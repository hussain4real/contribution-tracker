"""Flatten an unstarted, credential-free staging image for Cloudflare's runtime packer."""

import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import tarfile
import tempfile

parser = argparse.ArgumentParser()
parser.add_argument("source")
parser.add_argument("tag")
parser.add_argument("--result", default="deployment/d1-proof/flat-image-package-result.json")
args = parser.parse_args()
prefixes = ["registry.cloudflare.com/40344948b73fa9da3008958fdc3faf12/" + name + ":" for name in ["familyfunds-d1-staging", "familyfunds-production"]]
assert any(args.source.startswith(prefix) and args.tag.startswith(prefix) for prefix in prefixes)
if "/familyfunds-production:" in args.source:
    assert args.result == "deployment/d1-proof/production-flat-image-package-result.json"
os.umask(0o077)
docker = ["docker", "--host", "unix:///Users/amisha/.colima/familyfunds-d1/docker.sock"]
image = json.loads(subprocess.check_output(docker + ["image", "inspect", args.source]))[0]
config = image["Config"]
for value in config.get("Env", []):
    name, content = value.split("=", 1)
    if re.search(r"(^APP_KEY$|TOKEN$|SECRET|PASSWORD|PRIVATE_KEY|ACCESS_KEY_ID|FF_RCLONE_CONFIG)", name):
        assert not content, "Runtime credentials must never be packaged"

with tempfile.TemporaryDirectory(prefix="familyfunds-image-package-", dir="/private/tmp") as directory:
    root = Path(directory)
    archive = root / "rootfs.tar"
    container = subprocess.check_output(docker + ["create", "--platform", "linux/amd64", args.source], text=True).strip()
    try:
        with archive.open("wb") as output:
            subprocess.run(docker + ["export", container], stdout=output, check=True)
    finally:
        subprocess.run(docker + ["rm", "-v", container], check=True, stdout=subprocess.DEVNULL)
    with tarfile.open(archive) as filesystem:
        for entry in filesystem:
            name = entry.name.lstrip("./")
            assert name != "var/www/html/.env", "Environment file found in image"
            assert name != "var/www/html/bootstrap/cache/config.php", "Runtime configuration found in image"
            assert not (entry.isfile() and name.startswith("var/www/html/storage/app/private/")), "Private runtime file found in image"
    lines = ["FROM scratch", 'ADD ["rootfs.tar", "/"]']
    for value in config.get("Env", []):
        name, content = value.split("=", 1)
        assert re.fullmatch(r"[A-Za-z_][A-Za-z0-9_]*", name)
        lines.append("ENV " + name + "=" + json.dumps(content).replace("$", "\\$"))
    lines.append("WORKDIR " + config.get("WorkingDir", "/var/www/html"))
    lines.append("ENTRYPOINT " + json.dumps(config["Entrypoint"]))
    if config.get("Cmd"):
        lines.append("CMD " + json.dumps(config["Cmd"]))
    if config.get("User"):
        lines.append("USER " + config["User"])
    for name, value in (config.get("Labels") or {}).items():
        lines.append("LABEL " + json.dumps(name) + "=" + json.dumps(value).replace("$", "\\$"))
    lines.append("EXPOSE 80")
    (root / "Dockerfile").write_text("\n".join(lines) + "\n")
    subprocess.run([
        "/private/tmp/familyfunds-d1-cli-plugins/docker-buildx", "build", "--platform", "linux/amd64", "--provenance=false",
        "--output", "type=image,name=" + args.tag + ",push=true,oci-mediatypes=false,compression=gzip",
        "--metadata-file", str(root / "metadata.json"), str(root),
    ], check=True)
    metadata = json.loads((root / "metadata.json").read_text())
    result = {"source": args.source, "source_image_id": image["Id"], "source_bytes": image["Size"], "unstarted_export": True,
              "runtime_credentials_packaged": False, "rootfs_sha256": hashlib.file_digest(archive.open("rb"), "sha256").hexdigest(),
              "tag": args.tag, "digest": metadata["containerimage.digest"]}
    Path(args.result).write_text(json.dumps(result, indent=2) + "\n")
    print(json.dumps(result))
