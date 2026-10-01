"""Execute the production shell script against disposable fake host commands."""

import json
import os
from pathlib import Path
import subprocess
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
    if scenario == "probe-timeout" and args[1:3] == ["php", "-r"]: sys.exit(124)
    sys.exit(subprocess.run(args[1:]).returncode)
if name == "git":
    if args[:2] == ["rev-parse", "origin/main"]:
        print("2" * 40 if scenario == "stale" else "1" * 40)
    elif args[:2] == ["rev-parse", "HEAD"]:
        print("1" * 40 if (root / "merged").exists() else "0" * 40)
    elif args[0] == "merge-base" and scenario == "ancestry": sys.exit(1)
    elif args[:2] == ["merge", "--ff-only"]: (root / "merged").touch()
elif name == "php":
    if args[0] == "-r" and "isDownForMaintenance" in args[1]:
        if scenario in ["probe-error", "down-before-state-probe-error"] or (scenario == "postmerge-probe-error" and (root / "merged").exists()): sys.exit(2)
        if scenario == "probe-early-exit": sys.exit(0)
        if scenario == "probe-unexpected-output": print("unexpected"); sys.exit(0)
        print("active" if (root / "maintenance").exists() else "inactive")
    elif args[1] == "down":
        if scenario == "postmerge-broken-bootstrap" and (root / "merged").exists(): sys.exit(1)
        first_down = not (root / "down-attempted").exists()
        (root / "down-attempted").touch()
        if scenario in ["down-before-state", "down-before-state-probe-error", "down-success-without-state", "term-before-state", "int-before-state"]:
            if first_down and scenario in ["term-before-state", "int-before-state"]:
                os.kill(os.getppid(), signal.SIGTERM if scenario.startswith("term") else signal.SIGINT)
            sys.exit(0 if scenario == "down-success-without-state" else 1)
        if scenario == "down-retry-recovers" and first_down: sys.exit(1)
        (root / "maintenance").touch()
        if first_down and scenario in ["term-after-state", "int-after-state"]:
            os.kill(os.getppid(), signal.SIGTERM if scenario.startswith("term") else signal.SIGINT)
            sys.exit(1)
        if scenario == "partial-down": sys.exit(1)
    if args[1] == "up": (root / "maintenance").unlink(missing_ok=True)
    if args[1] == "migrate" and scenario == "migration": sys.exit(1)
elif name == "composer":
    if scenario in ["composer", "postmerge-broken-bootstrap"]: sys.exit(1)
    if scenario == "term-postmerge": os.kill(os.getppid(), signal.SIGTERM); sys.exit(1)
elif name == "pgrep":
    if scenario != "no-workers": print("111")
elif name == "systemctl":
    if args[0] == "show": print("0" if scenario == "no-master" else "100")
    elif args[0] == "reload":
        if scenario == "reload-failure" or (scenario == "final-reload-failure" and (root / "merged").exists()): sys.exit(1)
        if scenario != "drain" and not (scenario == "final-drain" and (root / "merged").exists()):
            (root / "proc/111/stat").write_text("111 (php-fpm8.4) S " + "0 " * 18 + ("43\n" if not (root / "merged").exists() else "44\n"))
    elif args[0] == "is-active" and scenario == "inactive": sys.exit(1)
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
'''

with tempfile.TemporaryDirectory(prefix="contribution-deploy-") as tmp:
    root = Path(tmp)
    bin_dir = root / "bin"
    bin_dir.mkdir()
    (root / "proc/111").mkdir(parents=True)
    (root / "proc/111/stat").write_text("111 (php-fpm8.4) S " + "0 " * 18 + "42\n")
    (root / "running-queue").touch()
    (root / "running-ssr").touch()
    (root / "running-nightwatch").touch()
    if scenario.startswith("initially-stopped-"):
        (root / ("running-" + scenario.removeprefix("initially-stopped-"))).unlink()
    for command in ["git", "php", "sudo", "timeout", "composer", "npm", "pgrep", "systemctl", "supervisorctl", "bash", "curl"]:
        path = bin_dir / command
        path.write_text(dispatcher)
        path.chmod(0o755)
    script = script.replace("/var/www/contribution-tracker", str(root))
    script = script.replace("${{ github.sha }}", sha)
    script = script.replace("/proc/", str(root / "proc") + "/")
    script = script.replace("drain_deadline=$((SECONDS + 300))", "drain_deadline=$SECONDS")
    environment = {
        **os.environ,
        "PATH": str(bin_dir) + ":" + os.environ["PATH"],
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
