"""Build and inspect an unstarted, credential-free image before CI gets deploy access."""

import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import tarfile

ROOT = Path(__file__).resolve().parents[2]


def run(*args, **kwargs):
    return subprocess.run(args, check=True, **kwargs)


def inspect_archive(path):
    with tarfile.open(path) as archive:
        for entry in archive:
            name = entry.name.removeprefix('./').lstrip('/')
            if name == 'var/www/html/.env' or name.startswith('var/www/html/.env.'):
                raise ValueError('Environment files must not enter the image')
            if name == 'var/www/html/bootstrap/cache/config.php':
                raise ValueError('Runtime configuration must not enter the image')
            if entry.isfile() and name.startswith('var/www/html/storage/app/private/'):
                raise ValueError('Private runtime files must not enter the image')


def package_config(config):
    lines = ['FROM scratch', 'ADD ["rootfs.tar", "/"]']
    for value in config.get('Env', []):
        name, content = value.split('=', 1)
        if not re.fullmatch(r'[A-Za-z_][A-Za-z0-9_]*', name):
            raise ValueError('Invalid image environment name')
        if re.search(r'(^APP_KEY$|API_KEY$|TOKEN$|SECRET|PASSWORD|PRIVATE_KEY|ACCESS_KEY_ID|FF_RCLONE_CONFIG)', name) and content:
            raise ValueError('Runtime credentials must not enter the image')
        lines.append('ENV ' + name + '=' + json.dumps(content).replace('$', '\\$'))
    lines.append('WORKDIR ' + json.dumps(config.get('WorkingDir') or '/var/www/html'))
    for field in ['Entrypoint', 'Cmd']:
        if config.get(field):
            lines.append(field.upper() + ' ' + json.dumps(config[field]))
    if config.get('User'):
        lines.append('USER ' + config['User'])
    for name, value in (config.get('Labels') or {}).items():
        lines.append('LABEL ' + json.dumps(name) + '=' + json.dumps(value).replace('$', '\\$'))
    lines.append('EXPOSE 80')
    return '\n'.join(lines) + '\n'


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--target', choices=['production', 'staging'], required=True)
    parser.add_argument('--revision', required=True)
    parser.add_argument('--output', type=Path, required=True)
    args = parser.parse_args()
    if not re.fullmatch(r'[a-f0-9]{40}', args.revision):
        raise ValueError('Builds require a full Git commit SHA')
    config = json.loads((ROOT / 'deployment/d1-proof/cloudflare-release.json').read_text())
    target = config[args.target]
    args.output.mkdir(parents=True, exist_ok=False)
    image = 'familyfunds-ci:' + args.revision
    run('docker', 'build', '--platform', 'linux/amd64', '--tag', image, '--file',
        str(ROOT / 'deployment/d1-proof' / target['dockerfile']), '--build-arg',
        'RUNTIME_SOURCE_SHA256=' + args.revision, str(ROOT))
    inspected = json.loads(subprocess.check_output(['docker', 'image', 'inspect', image]))[0]
    dockerfile = package_config(inspected['Config'])
    container = subprocess.check_output(['docker', 'create', '--platform', 'linux/amd64', image], text=True).strip()
    try:
        with (args.output / 'rootfs.tar').open('wb') as output:
            run('docker', 'export', container, stdout=output)
    finally:
        run('docker', 'rm', '-v', container, stdout=subprocess.DEVNULL)
    inspect_archive(args.output / 'rootfs.tar')
    (args.output / 'Dockerfile').write_text(dockerfile)
    with (args.output / 'rootfs.tar').open('rb') as source:
        digest = hashlib.file_digest(source, 'sha256').hexdigest()
    result = {'target': args.target, 'revision': args.revision, 'rootfs_sha256': digest,
              'unstarted_export': True, 'runtime_credentials_packaged': False}
    (args.output / 'release.json').write_text(json.dumps(result, indent=2) + '\n')
    print(json.dumps(result))


if __name__ == '__main__':
    main()
