"""Alternate authenticated cold reads on the isolated baseline and candidate."""

import argparse
import gzip
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import re
import statistics
import time

ROOT = Path(__file__).resolve().parents[2]
PRIVATE = ROOT / "storage/app/private/d1-staging"
os.environ["D1_UAT_BASE"] = "https://familyfunds-d1-capacity.familyfunds-lab.workers.dev"
os.environ["D1_UAT_COOKIE_SUFFIX"] = "capacity-20"
spec = importlib.util.spec_from_file_location("capacity_client", PRIVATE / "http-uat.py")
client_module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(client_module)
transport_spec = importlib.util.spec_from_file_location("capacity_transport", Path(__file__).with_name("capacity-transport.py"))
transport = importlib.util.module_from_spec(transport_spec)
transport_spec.loader.exec_module(transport)
transport.install(client_module)

parser = argparse.ArgumentParser()
parser.add_argument("phase", choices=["query-only", "lean-runtime"])
parser.add_argument("candidate_digest")
args = parser.parse_args()
assert re.fullmatch(r"[a-f0-9]{64}", args.candidate_digest)
baseline_digest = "395dadae04d7fcb6be0da6cac795d959d05f57601d8a020d98a3d0aaad4108c6"
expected = json.loads((PRIVATE / "capacity-20-client-state.json").read_text())["1"]
client = client_module.Client("load-1")
admin = {"X-Staging-Admin": client_module.S["container_admin_token"]}
output = ROOT / f"deployment/d1-proof/cold-{args.phase}-comparison-result.json"
assert not output.exists(), "Retain the earlier comparison instead of overwriting it"
rows = []

for round_number in range(1, 6):
    for side in ["baseline", "candidate"]:
        headers = {**admin, **({"X-D1-Staging-Candidate": "lean"} if side == "candidate" else {})}
        start = time.monotonic()
        row = {"round": round_number, "side": side, "retries": 0, "stage": "restart"}
        try:
            status, _, _ = client.request("POST", "/__staging_admin/restart", {}, extra=headers)
            assert status == 200, ("restart", side, status)
            row["stage"] = "cold_dashboard"
            start = time.monotonic()
            status, response_headers, body = client.request("GET", client_module.F + "/dashboard", extra={
                **headers, "X-Inertia": "true", "X-Inertia-Version": expected["version"], "Accept-Encoding": "gzip",
            })
            row.update(seconds=time.monotonic() - start, http=status, timing=response_headers.get("server-timing"))
            assert status == 200, ("dashboard", status)
            decoded = gzip.decompress(body) if response_headers.get("content-encoding") == "gzip" else body
            props = json.loads(decoded)["props"]
            business = {key: props[key] for key in ["summary", "member_statuses", "recent_payments", "overdue_members", "family_aggregate", "personal", "fund_balance"] if key in props}
            digest = hashlib.sha256(json.dumps(business, sort_keys=True).encode()).hexdigest()
            assert digest == expected["business_sha256"], "Business data changed"
            status, _, body = client.request("GET", "/__staging_admin/runtime", extra=headers)
            assert status == 200
            runtime = json.loads(body)
            assert runtime["image"].endswith(args.candidate_digest if side == "candidate" else baseline_digest)
            assert runtime["runtime"]["cpu_count"] == 2 and runtime["runtime"]["vm_memory_kib"] > 6_000_000
            assert runtime["runtime"]["startup_profile"] is None, "Disable profiling before adjudication"
            row.update(correct=True, business_sha256=digest, runtime=runtime)
        except Exception as error:
            row.update(correct=False, seconds=row.get("seconds", time.monotonic() - start), error_type=type(error).__name__, error=str(error)[:300])
        rows.append(row)
        result = {"phase": args.phase, "client": "system curl IPv4, no retries", "condition": "forced container destruction; first authenticated Inertia dashboard", "idle_timeout_ms": 120000,
                  "profiling": False, "rows": rows, "limit_seconds": 3, "summary": {}}
        for key in ["baseline", "candidate"]:
            samples = [row for row in rows if row["side"] == key]
            if samples:
                seconds = [row["seconds"] for row in samples if row["stage"] == "cold_dashboard"]
                result["summary"][key] = {"requests": len(samples), "errors": sum(not row["correct"] for row in samples),
                    "restart_errors": sum(row["stage"] == "restart" for row in samples),
                    "median_seconds": statistics.median(seconds) if seconds else None, "range_seconds": [min(seconds), max(seconds)] if seconds else None,
                    "under_three_seconds": all(row["correct"] and row["seconds"] < 3 for row in samples)}
        output.write_text(json.dumps(result, indent=2) + "\n")
        print(json.dumps({key: row[key] for key in ["round", "side", "seconds", "correct"]}), flush=True)
