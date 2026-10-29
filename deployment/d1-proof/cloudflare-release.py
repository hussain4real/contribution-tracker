"""Publish reviewed code and prepared images without exporting application secrets."""

import argparse
import email.parser
import email.policy
import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import time
import urllib.error
import urllib.request
import uuid

ROOT = Path(__file__).resolve().parents[2]
SOURCES = ROOT / 'deployment/d1-proof'
CONFIG = json.loads((SOURCES / 'cloudflare-release.json').read_text())
ACCOUNT = CONFIG['account_id']
API = 'https://api.cloudflare.com/client/v4'


def api(method, path, data=None, raw=False, content_type='application/json'):
    token = os.environ.get('CLOUDFLARE_API_TOKEN', '')
    if not token:
        raise RuntimeError('CLOUDFLARE_API_TOKEN is not configured')
    body = data if raw or data is None else json.dumps(data).encode()
    request = urllib.request.Request(API + '/accounts/' + ACCOUNT + path, data=body, method=method,
                                    headers={'Authorization': 'Bearer ' + token, 'Content-Type': content_type})
    try:
        with urllib.request.urlopen(request, timeout=60) as response:
            content, mime = response.read(8 * 1024 * 1024 + 1), response.headers.get('Content-Type', '')
    except urllib.error.HTTPError as error:
        raise RuntimeError(f'Cloudflare {method} {path} returned HTTP {error.code}; response suppressed') from None
    if len(content) > 8 * 1024 * 1024:
        raise RuntimeError('Cloudflare response exceeded the release budget')
    if raw and method == 'GET':
        return content, mime
    result = json.loads(content)
    if result.get('success') is not True:
        raise RuntimeError(f'Cloudflare {method} {path} failed; response suppressed')
    return result['result']


def migration_fingerprint():
    digest = hashlib.sha256()
    for file in sorted((ROOT / 'database/migrations').glob('*.php')):
        digest.update(file.relative_to(ROOT).as_posix().encode() + b'\0' + file.read_bytes() + b'\0')
    return digest.hexdigest()


def refresh_bootstrap_assets():
    file = SOURCES / 'container-bootstrap-assets.mjs'
    assets = json.loads(file.read_text().split('=', 1)[1].strip().removesuffix(';'))
    for asset in assets:
        relative = asset['path'].removeprefix('/var/www/html/')
        path = (ROOT / relative).resolve()
        if not path.is_relative_to(ROOT) or not path.is_file() or path.is_symlink():
            raise ValueError('Invalid bootstrap source path')
        asset['content'] = path.read_text()
        asset['sha256'] = hashlib.sha256(asset['content'].encode()).hexdigest()
    file.write_text('export const STAGING_BOOTSTRAP_ASSETS = ' + json.dumps(assets, ensure_ascii=False, separators=(',', ':')) + ';\n')


def modules(entry, image=None, revision=None):
    result = {}

    def visit(name):
        if name in result:
            return
        if not re.fullmatch(r'[A-Za-z0-9_.-]+\.(?:mjs|php)', name):
            raise ValueError('Release module must be a direct runtime source file')
        source = (SOURCES / name).read_text()
        if name == 'cloudflare-production-entry.mjs' and image is not None:
            source, image_count = re.subn(r"const MANAGED_IMAGE = '[^']+';", 'const MANAGED_IMAGE = ' + json.dumps(image) + ';', source)
            source, revision_count = re.subn(r"const MANAGED_RELEASE = '[^']+';", 'const MANAGED_RELEASE = ' + json.dumps(revision) + ';', source)
            if image_count != 1 or revision_count != 1:
                raise ValueError('Missing code-pinned production release')
        result[name] = source.encode()
        for dependency in re.findall(r'(?:from\s+|import\s*)[\'\"]\./([^\'\"]+)[\'\"]', source):
            visit(dependency)

    visit(entry)
    return result


def multipart(entry, sources, bindings, container=None):
    metadata = {'main_module': entry, 'compatibility_date': CONFIG['compatibility_date'],
                'compatibility_flags': ['nodejs_compat'], 'bindings': bindings,
                'keep_bindings': ['secret_text'], 'observability': {'enabled': True, 'head_sampling_rate': 1}}
    if container:
        metadata['containers'] = [container]
    boundary = 'familyfunds-' + uuid.uuid4().hex
    chunks = []
    for name, content, mime in [('metadata', json.dumps(metadata).encode(), 'application/json')] + [
            (name, source, 'text/plain' if name.endswith('.php') else 'application/javascript+module')
            for name, source in sources.items()]:
        filename = '; filename="' + name + '"' if name != 'metadata' else ''
        chunks.append(('--' + boundary + '\r\nContent-Disposition: form-data; name="' + name + '"' + filename + '\r\nContent-Type: ' + mime + '\r\n\r\n').encode() + content + b'\r\n')
    return b''.join(chunks) + ('--' + boundary + '--\r\n').encode(), 'multipart/form-data; boundary=' + boundary


def script_sources(raw, mime):
    message = email.parser.BytesParser(policy=email.policy.default).parsebytes(('Content-Type: ' + mime + '\r\nMIME-Version: 1.0\r\n\r\n').encode() + raw)
    if not message.is_multipart():
        raise RuntimeError('Rollback requires the complete multi-module Worker source')
    return {part.get_param('name', header='content-disposition'): part.get_payload(decode=True)
            for part in message.iter_parts() if part.get_param('name', header='content-disposition') != 'metadata'}


def prepared(image):
    deadline = time.monotonic() + 900
    while time.monotonic() < deadline:
        result = api('POST', '/containers/image-preparations', {'image': image})
        if result['status'] == 'ready':
            return
        if result['status'] != 'pending':
            raise RuntimeError('Container runtime image preparation failed')
        time.sleep(10)
    raise RuntimeError('Container runtime image preparation timed out before any Worker update')


def public_checks(target, revision=None):
    authorization = os.environ.get('STAGING_BASIC_AUTHORIZATION')
    if target == 'staging' and not authorization:
        raise RuntimeError('Authenticated staging smoke check requires STAGING_BASIC_AUTHORIZATION')
    for path in ['/up', '/login']:
        headers = {'User-Agent': 'FamilyFunds-GitHub-release', 'Cache-Control': 'no-cache'}
        if target == 'staging':
            headers['Authorization'] = authorization
        request = urllib.request.Request(CONFIG[target]['url'] + path, headers=headers)
        with urllib.request.urlopen(request, timeout=90) as response:
            body = response.read(1024 * 1024)
            if response.status != 200:
                raise RuntimeError('Release health check failed')
            if target == 'production' and revision and response.headers.get('X-FamilyFunds-Runtime-Revision') != revision:
                raise RuntimeError('The public edge did not serve the published revision')
            if target == 'production' and response.headers.get('X-FamilyFunds-Activation-Phase') != 'active':
                raise RuntimeError('Production activation is not the accepted live phase')
            if path == '/login' and (b'<html' not in body.lower() or b'password' not in body.lower()):
                raise RuntimeError('Laravel login did not render successfully')


def instance_image(target, image):
    result = api('GET', '/containers/applications/' + CONFIG[target]['application_id'] + '/instances-v2?per_page=100')
    rows = result if isinstance(result, list) else result.get('instances', [])
    for row in rows:
        actual = row.get('image') or row.get('configuration', {}).get('image')
        if row.get('status', {}).get('state') == 'running' and actual == image and row.get('name') == CONFIG[target]['instance_name']:
            return True
    return False


def rollout_image(path, image):
    return api('POST', path + '/rollouts', {
        'description': 'FamilyFunds verified GitHub Actions image release',
        'strategy': 'rolling', 'kind': 'full_auto', 'step_percentage': 100,
        'target_configuration': {'image': image},
    })


def validate():
    if migration_fingerprint() != CONFIG['accepted_migrations_sha256']:
        raise RuntimeError('Database migrations differ from the accepted D1 schema. Review and verify the D1 migration before updating its accepted fingerprint; CI never migrates or restores production data.')
    for target in ['production', 'staging']:
        for worker in [CONFIG[target]] + CONFIG[target]['companions']:
            modules(worker['entry'])
    return {'accepted_schema': True, 'runtime_modules_readable': True}


def checked_bindings(worker, settings):
    bindings = settings['bindings']
    names = {binding['name']: binding for binding in bindings}
    if worker == CONFIG['production']['worker']:
        expected = {'RUNTIME_PROFILE': 'production', 'PRODUCTION_CUTOVER_STAGE': 'live',
                    'TRAFFIC_ENABLED': 'true', 'BUSINESS_WRITES_ENABLED': 'true',
                    'WEBHOOKS_ENABLED': 'true', 'CANDIDATE_DIAGNOSTICS': '0'}
        if any(names.get(key, {}).get('text') != value for key, value in expected.items()):
            raise RuntimeError('Existing production activation differs from the accepted configuration')
        if names.get('DB', {}).get('id', names.get('DB', {}).get('database_id')) != '8f0d0867-281a-48c3-8b2e-0d867529622c':
            raise RuntimeError('Production D1 binding mismatch')
        if names.get('PRODUCTION_CONTAINER', {}).get('namespace_id') != CONFIG['production']['application_id']:
            raise RuntimeError('Production container namespace mismatch')
    if worker == 'familyfunds-production-offsite' and any(key in names for key in ['DB', 'CONTAINER_ENV', 'CONTAINER_ENV_0', 'APP_KEY', 'BACKUP_KEY']):
        raise RuntimeError('Offsite credential isolation no longer matches the reviewed configuration')
    return [binding for binding in bindings if binding['type'] != 'secret_text']


def publish(target, directory):
    refresh_bootstrap_assets()
    validate()
    release = json.loads((directory / 'release.json').read_text())
    revision = os.environ.get('GITHUB_SHA', '')
    if not re.fullmatch(r'[a-f0-9]{40}', revision) or release['revision'] != revision or release['target'] != target:
        raise RuntimeError('The artifact must match the exact tested Git commit and target')
    if os.environ.get('GITHUB_REF') != 'refs/heads/' + CONFIG[target]['branch']:
        raise RuntimeError('Deployments are limited to their configured branch')
    if not release['unstarted_export'] or release['runtime_credentials_packaged']:
        raise RuntimeError('Image credential inspection did not pass')
    with (directory / 'rootfs.tar').open('rb') as source:
        if hashlib.file_digest(source, 'sha256').hexdigest() != release['rootfs_sha256']:
            raise RuntimeError('Image artifact checksum mismatch')
    public_checks(target)
    name = 'github-' + revision[:12]
    tag = 'registry.cloudflare.com/' + ACCOUNT + '/' + CONFIG[target]['image_repository'] + ':' + revision
    if os.environ.get('GITHUB_ACTIONS') != 'true' or not os.environ.get('DOCKER_CONFIG'):
        raise RuntimeError('Publishing requires the isolated GitHub Actions Docker configuration')
    credentials = api('POST', '/containers/registries/registry.cloudflare.com/credentials', {'expiration_minutes': 60, 'permissions': ['pull', 'push']})
    try:
        if os.environ.get('GITHUB_ACTIONS') == 'true':
            print('::add-mask::' + credentials['password'], flush=True)
        subprocess.run(['docker', 'login', credentials['registry_host'], '--username', credentials['username'], '--password-stdin'],
                       input=credentials['password'].encode(), check=True, stdout=subprocess.DEVNULL)
        metadata = directory / 'pushed-image.json'
        subprocess.run(['docker', 'buildx', 'build', '--platform', 'linux/amd64', '--provenance=false', '--output',
                        'type=image,name=' + tag + ',push=true,oci-mediatypes=false,compression=gzip', '--metadata-file', str(metadata), str(directory)],
                       check=True)
    finally:
        subprocess.run(['docker', 'logout', 'registry.cloudflare.com'], check=False, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    digest = json.loads(metadata.read_text())['containerimage.digest']
    if not re.fullmatch(r'sha256:[a-f0-9]{64}', digest):
        raise RuntimeError('Registry returned an invalid immutable image digest')
    image = tag.rsplit(':', 1)[0] + '@' + digest
    prepared(image)
    plans = []
    for worker in CONFIG[target]['companions'] + [CONFIG[target]]:
        path = '/workers/scripts/' + worker['worker']
        settings = api('GET', path + '/settings')
        bindings = checked_bindings(worker['worker'], settings)
        previous_image = next((binding['text'] for binding in bindings if binding['name'] == 'CONTAINER_IMAGE'), worker.get('image'))
        raw, mime = api('GET', path, raw=True)
        previous_sources = script_sources(raw, mime)
        previous_entry = next((entry for entry in previous_sources if entry == worker['entry']), None)
        if previous_entry is None and worker['worker'] == 'familyfunds-production-managed':
            previous_entry = 'production-managed-worker.mjs'
        if previous_entry not in previous_sources:
            raise RuntimeError('Cannot identify the existing entry module for safe code rollback')
        container = {'class_name': worker['class_name'], 'images': {'base': worker.get('image', image)}} if worker.get('class_name') else None
        if worker.get('image'):
            prepared(worker['image'])
        rollback_container = {**container, 'images': {'base': previous_image}} if container and target == 'production' else container
        old_body, old_mime = multipart(previous_entry, previous_sources, bindings, rollback_container)
        updated = [dict(binding) for binding in bindings]
        for binding in updated:
            if worker['worker'] == CONFIG[target]['worker'] and binding['name'] in ['RUNTIME_REVISION', 'CONTAINER_IMAGE']:
                binding['text'] = name if binding['name'] == 'RUNTIME_REVISION' else image
        sources = modules(worker['entry'], image if target == 'production' else None, name)
        body, mime = multipart(worker['entry'], sources, updated, container if target == 'production' else None)
        plans.append({'path': path, 'body': body, 'mime': mime, 'old_body': old_body, 'old_mime': old_mime})
    previous_staging = None
    applied = []
    try:
        if target == 'staging':
            app_path = '/containers/applications/' + CONFIG[target]['application_id']
            previous_staging = api('GET', app_path)['configuration']['image']
            rollout_image(app_path, image)
        for plan in plans:
            applied.append(plan)
            api('PUT', plan['path'], plan['body'], raw=True, content_type=plan['mime'])
        deadline = time.monotonic() + 600
        while True:
            try:
                public_checks(target, name)
                if instance_image(target, image):
                    break
            except (RuntimeError, urllib.error.URLError):
                pass
            if time.monotonic() >= deadline:
                raise RuntimeError('Release verification failed: edge, Laravel readiness or native running image mismatch')
            time.sleep(10)
    except Exception:
        failures = []
        for plan in reversed(applied):
            try:
                api('PUT', plan['path'], plan['old_body'], raw=True, content_type=plan['old_mime'])
            except Exception:
                failures.append(plan['path'])
        if previous_staging:
            try:
                rollout_image(app_path, previous_staging)
            except Exception:
                failures.append('staging container image')
        if failures:
            raise RuntimeError('Deployment failed and code/image rollback needs operator attention: ' + ', '.join(failures)) from None
        raise RuntimeError('Deployment failed; previous code/image restored. No database rollback or request replay was attempted.') from None
    result = {'target': target, 'revision': revision, 'runtime_revision': name, 'image': image,
              'prepared': True, 'native_image_verified': True, 'laravel_login_verified': True,
              'application_secrets_exported': False, 'database_migrations_run': False}
    (directory / 'deployment.json').write_text(json.dumps(result, indent=2) + '\n')
    print(json.dumps(result))
    if os.environ.get('GITHUB_STEP_SUMMARY'):
        with open(os.environ['GITHUB_STEP_SUMMARY'], 'a') as summary:
            summary.write(f'FamilyFunds {target}: `{revision}`\n\nImage: `{image}`\n\nImage preparation, native running image and Laravel login checks passed. Application secrets stayed in Cloudflare.\n')


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('operation', choices=['validate', 'prepare', 'publish'])
    parser.add_argument('--target', choices=['production', 'staging'])
    parser.add_argument('--artifact', type=Path)
    args = parser.parse_args()
    if args.operation in ['validate', 'prepare']:
        if args.operation == 'prepare':
            refresh_bootstrap_assets()
        print(json.dumps(validate()))
    elif args.target and args.artifact:
        publish(args.target, args.artifact)
    else:
        parser.error('publish requires --target and --artifact')


if __name__ == '__main__':
    main()
