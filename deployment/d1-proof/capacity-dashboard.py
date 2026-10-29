"""Measure 20 independent authenticated users on the synthetic capacity target."""

import concurrent.futures
import gzip
import hashlib
import importlib.util
import json
import math
import os
from pathlib import Path
import re
import statistics
import sys
import threading
import time

ROOT = Path(__file__).resolve().parents[2]
PRIVATE = ROOT / "storage/app/private/d1-staging"
os.environ["D1_UAT_BASE"] = "https://familyfunds-d1-capacity.familyfunds-lab.workers.dev"
os.environ["D1_UAT_COOKIE_SUFFIX"] = "capacity-20"
spec = importlib.util.spec_from_file_location("capacity_client", PRIVATE / "http-uat.py")
client_module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(client_module)
if os.environ.get("D1_UAT_TRANSPORT") in ["curl", "persistent"]:
    transport_spec = importlib.util.spec_from_file_location("capacity_transport", Path(__file__).with_name("capacity-transport.py"))
    transport = importlib.util.module_from_spec(transport_spec)
    transport_spec.loader.exec_module(transport)
    if os.environ.get("D1_UAT_TRANSPORT") == "persistent":
        transport.install_persistent(client_module)
    else:
        transport.install(client_module)
if os.environ.get("D1_CAPACITY_CANDIDATE") == "lean":
    original_request = client_module.Client.request

    def candidate_request(client, method, path, data=None, extra=None):
        return original_request(client, method, path, data, {
            **(extra or {}), "X-D1-Staging-Candidate": "lean",
            "X-Staging-Admin": client_module.S["container_admin_token"],
        })

    client_module.Client.request = candidate_request
USERS = json.loads((PRIVATE / "capacity-user-records.json").read_text())
assert len(USERS) == 20 and len({user["id"] for user in USERS}) == 20
assert {user["id"] for user in USERS} == {1, 2, *range(101, 119)}
assert all(user["email"].endswith("@example.invalid") for user in USERS)
STATE = PRIVATE / "capacity-20-client-state.json"


def business_props(props):
    return {key: props[key] for key in [
        "summary", "member_statuses", "recent_payments", "overdue_members",
        "family_aggregate", "personal", "fund_balance",
    ] if key in props}


def prepare():
    state = {}
    for user in USERS:
        client = client_module.Client("load-" + str(user["id"]))
        status, _, _ = client.request("GET", client_module.F + "/dashboard")
        if status != 200:
            assert client.request("GET", "/login")[0] == 200
            password_key = "secretary_password" if user["id"] == 1 else "member_password"
            status, _, _ = client.request("POST", "/login", {
                "email": user["email"], "password": client_module.S[password_key],
            })
            assert status in [200, 302], ("login", user["id"], status)
        status, headers, body = client.request("GET", client_module.F + "/dashboard")
        assert status == 200 and headers.get("x-familyfunds-runtime") == "cloudflare-container"
        page = json.loads(re.search(
            r'<script data-page="app" type="application/json">(.*?)</script>',
            body.decode(), re.S,
        ).group(1))
        assert page["props"]["auth"]["user"]["id"] == user["id"]
        digest = hashlib.sha256(json.dumps(business_props(page["props"]), sort_keys=True).encode()).hexdigest()
        complete_props = business_props(page["props"])
        optional_details = "summary" in complete_props and "overdue_members" not in complete_props
        if optional_details:
            status, _, body = client.request("GET", client_module.F + "/dashboard", extra={
                "X-Inertia": "true", "X-Inertia-Version": page["version"],
                "X-Inertia-Partial-Component": page["component"],
                "X-Inertia-Partial-Data": "overdue_members",
            })
            assert status == 200
            partial = json.loads(body)
            assert "overdue_members" in partial["props"]
            complete_props["overdue_members"] = partial["props"]["overdue_members"]
        full_digest = hashlib.sha256(json.dumps(complete_props, sort_keys=True).encode()).hexdigest()
        reference_path = PRIVATE / "capacity-20-reference-business-state.json"
        if reference_path.exists():
            reference = json.loads(reference_path.read_text())
            assert full_digest == reference[str(user["id"])]["business_sha256"], ("financial props changed", user["id"])
        state[str(user["id"])] = {"version": page["version"], "business_sha256": digest,
                                  "full_business_sha256": full_digest, "optional_officer_details_verified": optional_details}
        print(json.dumps({"prepared_user": user["id"], "http": status}), flush=True)
    STATE.write_text(json.dumps(state))
    STATE.chmod(0o600)


def run(phase, users=None, rounds=5, on_ready=None):
    started_at_unix_ms = time.time() * 1000
    actors = USERS if users is None else users
    assert len(actors) in [19, 20]
    state = json.loads(STATE.read_text())
    clients = {user["id"]: client_module.Client("load-" + str(user["id"])) for user in actors}
    preflight_requests = 0
    if os.environ.get("D1_UAT_TRANSPORT") == "persistent":
        for user in actors:
            status, headers, body = clients[user["id"]].request("GET", client_module.F + "/dashboard", extra={
                "X-Inertia": "true", "X-Inertia-Version": state[str(user["id"])]["version"], "Accept-Encoding": "gzip",
            })
            assert status == 200, ("persistent connection preflight", user["id"], status)
            body = gzip.decompress(body) if headers.get("content-encoding") == "gzip" else body
            digest = hashlib.sha256(json.dumps(business_props(json.loads(body)["props"]), sort_keys=True).encode()).hexdigest()
            assert digest == state[str(user["id"])]["business_sha256"]
            preflight_requests += 1
        started_at_unix_ms = time.time() * 1000
    if on_ready is not None:
        on_ready()
    samples = []
    for round_number in range(1, rounds + 1):
        round_wall_start = time.time()
        round_clock_start = time.monotonic()
        barrier = threading.Barrier(len(actors))

        def fetch(user):
            client = clients[user["id"]]
            barrier.wait(timeout=20)
            started = time.monotonic()
            try:
                status, headers, body = client.request("GET", client_module.F + "/dashboard", extra={
                    "X-Inertia": "true", "X-Inertia-Version": state[str(user["id"])]["version"],
                    "Accept-Encoding": "gzip",
                })
                elapsed = time.monotonic() - started
                wire_bytes = len(body)
                if headers.get("content-encoding") == "gzip":
                    body = gzip.decompress(body)
                correct = False
                if status == 200:
                    page = json.loads(body)
                    digest = hashlib.sha256(json.dumps(business_props(page["props"]), sort_keys=True).encode()).hexdigest()
                    correct = (page["props"]["auth"]["user"]["id"] == user["id"]
                               and digest == state[str(user["id"])]["business_sha256"]
                               and headers.get("x-familyfunds-runtime") == "cloudflare-container")
                return {"round": round_number, "user_id": user["id"], "http": status,
                        "seconds": round(elapsed, 4), "wire_bytes": wire_bytes, "correct": correct,
                        "staging_mode": headers.get("x-d1-staging-mode"),
                        "client_connection_timing": headers.get("x-proof-client-timing"),
                        "server_timing": headers.get("server-timing")}
            except Exception as error:
                return {"round": round_number, "user_id": user["id"], "http": None,
                        "seconds": round(time.monotonic() - started, 4),
                        "correct": False, "error_type": type(error).__name__, "error": str(error)[:300]}

        with concurrent.futures.ThreadPoolExecutor(max_workers=len(actors)) as pool:
            rows = list(pool.map(fetch, actors))
        samples.extend(rows)
        clock_gap = max(0, time.time() - round_wall_start - (time.monotonic() - round_clock_start))
        for row in rows:
            row["client_clock_gap_seconds"] = round(clock_gap, 4)
        print(json.dumps({"round": round_number,
                          "errors": sum(row["http"] != 200 or not row["correct"] for row in rows),
                          "median_seconds": statistics.median(row["seconds"] for row in rows),
                          "max_seconds": max(row["seconds"] for row in rows)}), flush=True)
    times = sorted(row["seconds"] for row in samples)
    failures = sum(row["http"] != 200 or not row["correct"] for row in samples)
    continuous = all(row["client_clock_gap_seconds"] < 1 for row in samples)
    result = {
        "status": "passed" if failures == 0 and max(times) < 3 and continuous else "failed",
        "started_at_unix_ms": started_at_unix_ms, "finished_at_unix_ms": time.time() * 1000,
        "phase": phase, "simultaneous_users": len(actors), "rounds": rounds, "requests": len(samples),
        "target_seconds": 3, "criterion": "Every response under 3 seconds; zero errors; exact per-user business props",
        "measurement": "Warm authenticated Inertia HTTP response including body transfer; browser rendering not included",
        "dataset": "Synthetic family 1: 1002 active memberships and about 12040 contributions",
        "roles": "1 financial secretary, 19 members" if len(actors) == 20 else "19 members; secretary measured separately in browser", "errors": failures,
        "optional_officer_details_verified_separately": sum(item.get("optional_officer_details_verified", False) for item in state.values()),
        "median_seconds": statistics.median(times), "p95_seconds": times[math.ceil(len(times) * .95) - 1],
        "tls_trust_context": "System curl IPv4 per request, no retries" if os.environ.get("D1_UAT_TRANSPORT") == "curl" else "Initialized once before measurement; shared by immutable HTTPS handlers",
        "candidate": os.environ.get("D1_CAPACITY_CANDIDATE") == "lean",
        "transport": os.environ.get("D1_UAT_TRANSPORT", "urllib"),
        "connection_preflight_requests_excluded": preflight_requests,
        "connections_created": sum(getattr(client, "proof_connection_count", 0) for client in clients.values()),
        "maximum_client_clock_gap_seconds": max(row["client_clock_gap_seconds"] for row in samples),
        "continuous_warm_load_valid": continuous,
        "range_seconds": [min(times), max(times)], "automatic_retries": 0, "samples": samples,
    }
    path = ROOT / ("deployment/d1-proof/capacity-20-" + phase + "-result.json")
    path.write_text(json.dumps(result, indent=2) + "\n")
    print(json.dumps({key: value for key, value in result.items() if key != "samples"}))


if __name__ == "__main__":
    if sys.argv[1] == "prepare":
        prepare()
    elif sys.argv[1] == "browser-background":
        flag = PRIVATE / "capacity-browser-ready.json"
        acknowledgment = PRIVATE / "capacity-browser-load-started.json"
        flag.unlink(missing_ok=True)
        acknowledgment.unlink(missing_ok=True)
        deadline = time.monotonic() + 90
        while not flag.exists():
            assert time.monotonic() < deadline, "Browser did not reach the authenticated dashboard"
            time.sleep(0.05)
        def acknowledge_ready():
            acknowledgment.write_text(json.dumps({"at": time.time()}))
            acknowledgment.chmod(0o600)

        run("nineteen-member-browser-background", users=[user for user in USERS if user["id"] != 1], rounds=10,
            on_ready=acknowledge_ready)
    else:
        assert re.fullmatch(r"[a-z0-9-]+", sys.argv[1])
        run(sys.argv[1])
