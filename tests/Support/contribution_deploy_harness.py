"""Execute the production shell script against disposable fake host commands."""

import json
import os
from pathlib import Path
import subprocess
import shutil
import sys
import tempfile

scenario = sys.argv[1]
script = sys.stdin.read()
sha = "1" * 40

dispatcher = r'''#!/usr/bin/env python3
import json, os, pathlib, signal, subprocess, sys
root = pathlib.Path(os.environ["DEPLOY_TEST_ROOT"])
scenario = os.environ["DEPLOY_TEST_SCENARIO"]
name, args = pathlib.Path(sys.argv[0]).name, sys.argv[1:]
with (root / "commands.jsonl").open("a") as log:
    log.write(json.dumps([name, *args]) + "\n")
if name == "sudo":
    if args[0] == "-n": args = args[1:]
    if scenario == "preflight-denied" and args[:2] == ["supervisorctl", "status"]: sys.exit(1)
    if scenario == "supervisor-control-denied" and args[:2] in [["supervisorctl", "stop"], ["supervisorctl", "status"]]: sys.exit(1)
    if scenario == "supervisor-stop-denied" and args[:2] == ["supervisorctl", "stop"]: sys.exit(1)
    sys.exit(subprocess.run(args).returncode)
if name == "timeout":
    if scenario == "probe-timeout" and args[1:3] == ["php", "-r"] and "isDownForMaintenance" in args[3]: sys.exit(124)
    if scenario == "health-reentry-probe-timeout" and (root / "up-attempted").exists() and args[1:3] == ["php", "-r"]: sys.exit(124)
    sys.exit(subprocess.run(args[1:]).returncode)
if name == "git":
    if args[:2] == ["rev-parse", "origin/main"]:
        print("2" * 40 if scenario == "stale" else "1" * 40)
    elif args[:2] == ["rev-parse", "HEAD"]:
        print("1" * 40 if (root / "merged").exists() else "0" * 40)
    elif args[0] == "merge-base" and scenario == "ancestry": sys.exit(1)
    elif args[:2] == ["merge", "--ff-only"]:
        (root / "merged").touch()
        if scenario == "fpm-retired-pid":
            (root / "proc/111/stat").write_text("111 (php-fpm8.4) S 100 " + "0 " * 17 + "43 " + "0 " * 29 + "0\n")
elif name == "php":
    if args[0] == "-r" and "file_get_contents" in args[1] and "posix_kill" in args[1]:
        phase = "final" if (root / "merged").exists() else "initial"
        counter = root / ("probe-count-" + phase)
        count = int(counter.read_text()) + 1 if counter.exists() else 1
        counter.write_text(str(count))
        moment = ("final-" if phase == "final" else "") + ("census" if count == 1 else "poll")
        fault = scenario.removeprefix("fpm-" + moment + "-") if scenario.startswith("fpm-" + moment + "-") else ""
        if fault == "timeout": sys.exit(124)
        if fault == "failure": sys.exit(2)
        if fault == "noise": print("unexpected"); sys.exit(0)
        model = {"unreadable": fault in ["exit", "permission", "live", "kernel-error"], "kernel_ok": fault == "live", "errno": {"exit": 3, "permission": 1, "live": 3, "kernel-error": 22}.get(fault, 0)}
        path = root / "proc" / args[2] / "stat"
        record = path.read_text() if path.exists() else ""
        if fault == "empty": model["record"] = ""
        if fault == "truncated": model["record"] = record[:record.rfind("42") + 1]
        if fault == "short": model["record"] = "111 (php-fpm8.4) S 100 " + "0 " * 17 + "42\n"
        if fault == "nonnumeric": model["record"] = record.replace(" 42 ", " invalid ").replace(" 43 ", " invalid ").replace(" 44 ", " invalid ")
        if fault == "wrong-pid": model["record"] = record.replace("111 (", "112 (", 1)
        if fault == "wrong-parent": model["record"] = record.replace(") S 100 ", ") S 101 ")
        if fault == "bad-state": model["record"] = record.replace(") S ", ") ? ")
        if fault == "missing": model["unreadable"] = True; model["kernel_ok"] = True
        fixture = "namespace DeploymentProbeFixture; $GLOBALS[\"probe_model\"] = json_decode(" + json.dumps(json.dumps(model)) + ", true);"
        fixture += r"""
function file_get_contents($path) { $model = $GLOBALS["probe_model"]; return $model["unreadable"] ? false : ($model["record"] ?? \file_get_contents($path)); }
function posix_kill($pid, $signal) { if ($signal !== 0 || $pid < 1) { throw new \RuntimeException("Unexpected signal"); } return $GLOBALS["probe_model"]["kernel_ok"]; }
function posix_get_last_error() { return $GLOBALS["probe_model"]["errno"]; }
"""
        sys.exit(subprocess.run([os.environ["DEPLOY_TEST_REAL_PHP"], "-r", fixture + args[1], *args[2:]]).returncode)
    if args[0] == "-r" and "function_exists" in args[1]:
        if scenario == "fpm-no-posix": sys.exit(1)
        sys.exit(subprocess.run([os.environ["DEPLOY_TEST_REAL_PHP"], *args]).returncode)
    if args[0] == "-r" and "isDownForMaintenance" in args[1]:
        if (root / "merged").exists() and scenario in ["final-inactive-reentry-before-state", "final-inactive-reentry-probe-error"]:
            was_inactive = (root / "final-inactive").exists()
            (root / "final-inactive").touch()
            (root / "maintenance").unlink(missing_ok=True)
            if was_inactive and scenario == "final-inactive-reentry-probe-error": sys.exit(2)
        if scenario == "final-unknown-reentry-before-state" and (root / "merged").exists(): sys.exit(2)
        if (root / "up-attempted").exists():
            if scenario in ["health-reentry-probe-error", "health-reentry-down-and-probe-error"]: sys.exit(2)
            if scenario == "health-reentry-probe-empty": sys.exit(0)
            if scenario == "health-reentry-probe-noise": print("unexpected"); sys.exit(0)
        if scenario in ["probe-error", "down-before-state-probe-error"] or (scenario == "postmerge-probe-error" and (root / "merged").exists()): sys.exit(2)
        if scenario == "probe-early-exit": sys.exit(0)
        if scenario == "probe-unexpected-output": print("unexpected"); sys.exit(0)
        print("active" if (root / "maintenance").exists() else "inactive")
    elif args[1] == "down":
        if (root / "final-inactive").exists() or (scenario == "final-unknown-reentry-before-state" and (root / "merged").exists()): sys.exit(19)
        if (root / "up-attempted").exists():
            if scenario in ["health-reentry-before-state", "health-reentry-down-and-probe-error", "up-before-state-failure", "up-after-state-failure", "term-up-before-state", "int-up-before-state", "term-up-after-state", "int-up-after-state"]: sys.exit(1)
            if scenario == "health-reentry-no-state": sys.exit(0)
        if scenario == "postmerge-broken-bootstrap" and (root / "merged").exists(): sys.exit(1)
        first_down = not (root / "down-attempted").exists()
        (root / "down-attempted").touch()
        if scenario in ["down-before-state", "down-before-state-probe-error", "down-success-without-state", "term-before-state", "int-before-state"]:
            if first_down and scenario in ["term-before-state", "int-before-state"]:
                os.kill(os.getppid(), signal.SIGTERM if scenario.startswith("term") else signal.SIGINT)
            sys.exit(0 if scenario == "down-success-without-state" else 1)
        if scenario == "down-retry-recovers" and first_down: sys.exit(1)
        (root / "maintenance").touch()
        if scenario == "health-reentry-after-state" and (root / "up-attempted").exists(): sys.exit(1)
        if first_down and scenario in ["term-after-state", "int-after-state"]:
            os.kill(os.getppid(), signal.SIGTERM if scenario.startswith("term") else signal.SIGINT)
            sys.exit(1)
        if scenario == "partial-down": sys.exit(1)
    if args[1] == "up":
        (root / "up-attempted").touch()
        if scenario in ["up-before-state-failure", "term-up-before-state", "int-up-before-state"]:
            if scenario != "up-before-state-failure": os.kill(os.getppid(), signal.SIGTERM if scenario.startswith("term") else signal.SIGINT)
            sys.exit(17)
        (root / "maintenance").unlink(missing_ok=True)
        if scenario in ["up-after-state-failure", "term-up-after-state", "int-up-after-state"]:
            if scenario != "up-after-state-failure": os.kill(os.getppid(), signal.SIGTERM if scenario.startswith("term") else signal.SIGINT)
            sys.exit(17)
    if args[1] == "migrate" and scenario == "migration": sys.exit(1)
elif name == "composer":
    if scenario in ["composer", "postmerge-broken-bootstrap"]: sys.exit(1)
    if scenario == "term-postmerge": os.kill(os.getppid(), signal.SIGTERM); sys.exit(1)
elif name == "awk":
    if args[-1].endswith("/stat"):
        phase = "final" if (root / "merged").exists() else "initial"
        counter = root / ("awk-count-" + phase)
        count = int(counter.read_text()) + 1 if counter.exists() else 1
        counter.write_text(str(count))
        moment = ("final-" if phase == "final" else "") + ("census" if count == 1 else "poll")
        if scenario == "fpm-" + moment + "-exit": pathlib.Path(args[-1]).unlink(missing_ok=True)
    sys.exit(subprocess.run([os.environ["DEPLOY_TEST_REAL_AWK"], *args]).returncode)
elif name == "pgrep":
    if scenario.startswith("fpm-child-"):
        print({"zero": "0", "negative": "-1", "overflow": "99999999999999999999", "nonnumeric": "111x"}[scenario.removeprefix("fpm-child-")])
    elif scenario != "no-workers": print("111 222" if scenario == "fpm-retired-pid" and not (root / "merged").exists() else "111")
elif name == "systemctl":
    if args[0] == "show": print("0" if scenario == "no-master" else "100")
    elif args[0] == "reload":
        if scenario == "cleanup-inactive-after-resume" and (root / "merged").exists():
            (root / "maintenance").unlink(missing_ok=True)
            (root / "final-inactive").touch()
            sys.exit(17)
        if scenario == "reload-failure" or (scenario == "final-reload-failure" and (root / "merged").exists()): sys.exit(1)
        if scenario != "drain" and not (scenario == "final-drain" and (root / "merged").exists()):
            start = "43" if not (root / "merged").exists() else "44"
            comm = "php-fpm8.4"
            if scenario == "fpm-comm-name" or (scenario == "fpm-final-comm-name" and (root / "merged").exists()):
                start = "42" if not (root / "merged").exists() else "43"
                comm = "php worker ) pool"
            (root / "proc/111/stat").write_text("111 (" + comm + ") S 100 " + "0 " * 17 + start + " " + "0 " * 29 + "0\n")
            if scenario == "fpm-retired-pid" and not (root / "merged").exists():
                (root / "proc/222/stat").write_text("222 (php-fpm8.4) S 100 " + "0 " * 17 + "42 " + "0 " * 29 + "0\n")
    elif args[0] == "is-active" and scenario == "inactive": sys.exit(1)
elif name == "sleep" and scenario == "fpm-retired-pid":
    (root / "proc/111/stat").write_text("malformed reused PID\n")
    (root / "proc/222/stat").write_text("222 (php-fpm8.4) S 100 " + "0 " * 17 + "45 " + "0 " * 29 + "0\n")
elif name == "supervisorctl":
    action, consumer = args
    if consumer in ["queue-worker:*", "ssr", "nightwatch"]:
        marker = root / {"queue-worker:*": "running-queue", "ssr": "running-ssr", "nightwatch": "running-nightwatch"}[consumer]
        if action == "stop":
            if scenario == "queue-stop" and consumer == "queue-worker:*": sys.exit(1)
            if scenario == "nightwatch-stop" and consumer == "nightwatch": sys.exit(1)
            marker.unlink(missing_ok=True)
        elif action == "restart":
            if scenario == "nightwatch-restart" and consumer == "nightwatch": sys.exit(1)
            marker.touch()
        elif action == "status":
            merged = (root / "merged").exists()
            if not (scenario == "empty-resume" and merged) and not (scenario == "nightwatch-empty-status" and consumer == "nightwatch" and merged) and scenario != "preflight-empty":
                state = "STOPPED" if (scenario == "resume" and merged) or not marker.exists() or (scenario == "nightwatch-status" and consumer == "nightwatch" and merged) or scenario == "preflight-stopped" else "RUNNING"
                print(consumer + " " + state)
elif name == "curl" and scenario == "health": sys.exit(1)
elif name == "curl" and scenario.startswith("health-reentry-"): sys.exit(17)
'''

with tempfile.TemporaryDirectory(prefix="contribution-deploy-") as tmp:
    root = Path(tmp)
    bin_dir = root / "bin"
    bin_dir.mkdir()
    (root / "proc/111").mkdir(parents=True)
    (root / "proc/111/stat").write_text("111 (php-fpm8.4) S 100 " + "0 " * 17 + "42 " + "0 " * 29 + "0\n")
    if scenario == "fpm-retired-pid":
        (root / "proc/222").mkdir()
        (root / "proc/222/stat").write_text("222 (php-fpm8.4) S 100 " + "0 " * 17 + "42 " + "0 " * 29 + "0\n")
    (root / "running-queue").touch()
    (root / "running-ssr").touch()
    (root / "running-nightwatch").touch()
    if scenario.startswith("initially-stopped-"):
        (root / ("running-" + scenario.removeprefix("initially-stopped-"))).unlink()
    for command in ["git", "php", "sudo", "timeout", "composer", "npm", "pgrep", "systemctl", "supervisorctl", "bash", "curl", "sleep", "awk"]:
        path = bin_dir / command
        path.write_text(dispatcher)
        path.chmod(0o755)
    script = script.replace("/var/www/contribution-tracker", str(root))
    script = script.replace("${{ github.sha }}", sha)
    script = script.replace("/proc/", str(root / "proc") + "/")
    if scenario != "fpm-retired-pid":
        script = script.replace("drain_deadline=$((SECONDS + 300))", "drain_deadline=$SECONDS")
    environment = {
        **os.environ,
        "PATH": str(bin_dir) + ":" + os.environ["PATH"],
        "DEPLOY_TEST_REAL_PHP": shutil.which("php"),
        "DEPLOY_TEST_REAL_AWK": shutil.which("awk"),
        "DEPLOY_TEST_ROOT": str(root),
        "DEPLOY_TEST_SCENARIO": scenario,
    }
    result = subprocess.run(["/bin/bash"], input=script, text=True, capture_output=True, env=environment, timeout=15)
    commands = [json.loads(line) for line in (root / "commands.jsonl").read_text().splitlines()]
    print(json.dumps({
        "exit": result.returncode,
        "commands": commands,
        "maintenance": (root / "maintenance").exists(),
        "queue_running": (root / "running-queue").exists(),
        "ssr_running": (root / "running-ssr").exists(),
        "nightwatch_running": (root / "running-nightwatch").exists(),
        "merged": (root / "merged").exists(),
        "stderr": result.stderr,
    }))
