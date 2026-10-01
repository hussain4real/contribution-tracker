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
import json, os, pathlib, subprocess, sys
root = pathlib.Path(os.environ["DEPLOY_TEST_ROOT"])
scenario = os.environ["DEPLOY_TEST_SCENARIO"]
name, args = pathlib.Path(sys.argv[0]).name, sys.argv[1:]
with (root / "commands.jsonl").open("a") as log:
    log.write(json.dumps([name, *args]) + "\n")
if name == "sudo":
    if args[0] == "-n": args = args[1:]
    sys.exit(subprocess.run(args).returncode)
if name == "timeout": sys.exit(subprocess.run(args[1:]).returncode)
if name == "git":
    if args[:2] == ["rev-parse", "origin/main"]:
        print("2" * 40 if scenario == "stale" else "1" * 40)
    elif args[:2] == ["rev-parse", "HEAD"]:
        print("1" * 40 if (root / "merged").exists() else "0" * 40)
    elif args[0] == "merge-base" and scenario == "ancestry": sys.exit(1)
    elif args[:2] == ["merge", "--ff-only"]: (root / "merged").touch()
elif name == "php":
    if args[1] == "down": (root / "maintenance").touch()
    if args[1] == "up": (root / "maintenance").unlink(missing_ok=True)
    if args[1] == "migrate" and scenario == "migration": sys.exit(1)
elif name == "composer" and scenario == "composer": sys.exit(1)
elif name == "pgrep":
    if scenario != "no-workers": print("111")
elif name == "systemctl":
    if args[0] == "show": print("0" if scenario == "no-master" else "100")
    elif args[0] == "reload" and scenario != "drain":
        (root / "proc/111/stat").unlink(missing_ok=True)
    elif args[0] == "is-active" and scenario == "inactive": sys.exit(1)
elif name == "supervisorctl":
    action, consumer = args
    if consumer in ["queue-worker:*", "ssr"]:
        marker = root / ("running-queue" if consumer == "queue-worker:*" else "running-ssr")
        if action == "stop":
            if scenario == "queue-stop" and consumer == "queue-worker:*": sys.exit(1)
            marker.unlink(missing_ok=True)
        elif action == "restart": marker.touch()
        elif action == "status":
            if scenario != "empty-resume":
                state = "STOPPED" if scenario == "resume" or not marker.exists() else "RUNNING"
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
        "merged": (root / "merged").exists(),
        "stderr": result.stderr,
    }))
