"""Measure the protected mirror with one explicitly isolated test account."""

import argparse
import base64
import hashlib
import hmac
import http.cookiejar
import importlib.util
import json
from pathlib import Path
import re
import socket
import struct
import subprocess
import tempfile
import time
import types
import urllib.parse

ROOT = Path(__file__).resolve().parents[2]
PRIVATE = ROOT / 'storage/app/private/production-candidate'
BASE = 'https://familyfunds-d1-capacity.familyfunds-lab.workers.dev'

parser = argparse.ArgumentParser()
parser.add_argument('--side', choices=['baseline', 'candidate'], required=True)
parser.add_argument('--round', type=int, required=True)
parser.add_argument('--method', choices=['mfa', 'passkey'], default='mfa')
parser.add_argument('--revision', required=True)
parser.add_argument('--phase', default='v27', choices=['v27', 'v28-weur', 'v28-weur-edge', 'v28-weur-hetzner'])
parser.add_argument('--edge-ip', choices=['104.21.5.150', '172.67.154.185'])
args = parser.parse_args()
if args.edge_ip and args.edge_ip not in {entry[4][0] for entry in socket.getaddrinfo(urllib.parse.urlsplit(BASE).hostname, 443, socket.AF_INET)}:
    raise RuntimeError('The benchmark edge must be a current DNS address')
access = json.loads((PRIVATE / 'cutover-access.json').read_text())
fixture_path = PRIVATE / 'login-benchmark-credentials.json'
fixture = json.loads(fixture_path.read_text())
username, password = base64.b64decode(access['basic_authorization'][6:]).decode().split(':', 1)
cookie_directory = tempfile.TemporaryDirectory(prefix='login-benchmark-', dir=PRIVATE)


class Client:
    def __init__(self):
        self.jar = http.cookiejar.MozillaCookieJar(str(Path(cookie_directory.name) / 'cookies'))
        self.jar.save(ignore_discard=True, ignore_expires=True)


client_module = types.SimpleNamespace(S={'basic_user': username, 'basic_password': password}, BASE=BASE, F='/login', P=PRIVATE, Client=Client, EDGE_IP=args.edge_ip)
transport_spec = importlib.util.spec_from_file_location('login_curl_transport', ROOT / 'deployment/d1-proof/capacity-transport.py')
transport = importlib.util.module_from_spec(transport_spec)
transport_spec.loader.exec_module(transport)
transport.install(client_module)
client = Client()
samples = []


def request(method, path, data=None, expected=200):
    start, wall = time.monotonic(), time.time()
    status, headers, body = client.request(method, path, data, extra={'X-Requested-With': 'XMLHttpRequest'})
    response = types.SimpleNamespace(status_code=status, headers=headers, text=body.decode(), json=lambda: json.loads(body))
    elapsed = time.monotonic() - start
    if abs(time.time() - wall - elapsed) > 0.5:
        raise RuntimeError('Client clock discontinuity invalidated this sample')
    public_path = '/{test-family}/dashboard' if path.endswith('/dashboard') else path
    row = {'method': method, 'path': public_path, 'http': response.status_code, 'seconds': round(elapsed, 4), 'server_timing': headers.get('server-timing'), 'network_timing': headers.get('x-proof-client-timing')}
    samples.append(row)
    if response.status_code != expected:
        raise RuntimeError('Unexpected HTTP status for ' + path + ': ' + str(response.status_code))
    if headers.get('x-familyfunds-runtime-revision') != args.revision:
        raise RuntimeError('Unexpected runtime generation')
    return response


def current_otp():
    secret = fixture['secret']
    key = base64.b32decode(secret + '=' * ((8 - len(secret) % 8) % 8))
    digest = hmac.new(key, struct.pack('>Q', int(time.time()) // 30), hashlib.sha1).digest()
    offset = digest[-1] & 15
    return str((struct.unpack('>I', digest[offset:offset + 4])[0] & 0x7fffffff) % 1000000).zfill(6)


result = {'phase': args.phase, 'load_generator': 'Hetzner' if args.phase.endswith('-hetzner') else 'local Mac', 'edge_ip': args.edge_ip, 'side': args.side, 'round': args.round, 'authentication': args.method, 'revision': args.revision, 'synthetic_only': True, 'transport': 'system curl IPv4, TLS verified, fresh connection, no retry', 'retries': 0, 'samples': samples}
try:
    request('GET', '/up')
    status, _, body = client.request('GET', '/__native/runtime', extra={'X-Native-Admin': access['native_admin']})
    runtime = json.loads(body)
    expected_image = json.loads((ROOT / 'deployment/d1-proof/production-v27-login-build-plan.json').read_text())['base'] if args.side == 'baseline' else 'registry.cloudflare.com/40344948b73fa9da3008958fdc3faf12/familyfunds-production@' + json.loads((ROOT / 'deployment/d1-proof/production-flat-image-package-result.json').read_text())['digest']
    if status != 200 or runtime.get('image') != expected_image or runtime.get('managed') != {'release': args.revision, 'stage': 'read-only', 'stored_generation': args.revision, 'configuration_matches': True}:
        raise RuntimeError('Native image or cached configuration attestation failed')
    result['native_image'] = runtime['image']
    result['native_configuration_verified'] = True
    if args.method == 'mfa':
        while current_otp() == fixture.get('last_otp'):
            time.sleep(min(1, 30 - time.time() % 30 + 0.1))
    for _ in range(3):
        request('GET', '/login')
    if args.method == 'mfa':
        response = request('POST', '/login', {'email': fixture['email'], 'password': fixture['password']})
        if response.json().get('two_factor') is not True:
            raise RuntimeError('The independent MFA challenge was not required')
        code = current_otp()
        request('POST', '/two-factor-challenge', {'code': code}, expected=204)
        fixture['last_otp'] = code
        fixture_path.write_text(json.dumps(fixture))
    else:
        options = request('GET', '/passkeys/login/options').json()['options']
        signing = subprocess.run(['node', '--input-type=module', '-e', '''
import {readFileSync,writeFileSync} from 'node:fs';
import {createHash,sign} from 'node:crypto';
const options=JSON.parse(readFileSync(0,'utf8'));
const path='storage/app/private/production-candidate/login-benchmark-passkey.json';
const key=JSON.parse(readFileSync(path,'utf8'));
const clientData=Buffer.from(JSON.stringify({type:'webauthn.get',challenge:options.challenge,origin:'https://familyfunds.app',crossOrigin:false}));
const counter=Buffer.alloc(4);counter.writeUInt32BE(++key.counter);
const authenticatorData=Buffer.concat([createHash('sha256').update(options.rpId).digest(),Buffer.from([5]),counter]);
const signature=sign('sha256',Buffer.concat([authenticatorData,createHash('sha256').update(clientData).digest()]),key.private_key_pem);
writeFileSync(path,JSON.stringify(key),{mode:0o600});
console.log(JSON.stringify({id:key.id,rawId:key.id,type:'public-key',response:{clientDataJSON:clientData.toString('base64url'),authenticatorData:authenticatorData.toString('base64url'),signature:signature.toString('base64url'),userHandle:key.user_handle},clientExtensionResults:{}}));
'''], input=json.dumps(options), cwd=ROOT, capture_output=True, text=True, timeout=30, check=True)
        request('POST', '/passkeys/login', {'credential': json.loads(signing.stdout), 'remember': False})
    authentication = samples[-1]
    if args.round >= 2 and 'd1_transport' in (authentication['server_timing'] or ''):
        raise RuntimeError('Application profiling must be disabled during adjudication')
    dashboard = fixture.get('dashboard', '/' + fixture['family_slug'] + '/dashboard')
    response = request('GET', dashboard)
    if dashboard == '/platform':
        tables = re.findall(r'<tbody[^>]*>(.*?)</tbody>', response.text, re.S)
        counts = [len(re.findall(r'<tr\b', table)) for table in tables]
        if not all(label in response.text for label in ['Platform Overview', 'Total families', 'Recent families', 'Recent payments']) or len(counts) < 2 or not all(n > 0 for n in counts[:2]) or 'Loading...' in response.text:
            raise RuntimeError('The complete admin dashboard did not render')
        result['table_rows'] = counts[:2]
    else:
        page = re.search(r'<script[^>]*data-page[^>]*>(.*?)</script>', response.text, re.S)
        data = json.loads(page.group(1)) if page else {}
        if data.get('component') != 'Dashboard/Index' or data.get('props', {}).get('auth', {}).get('user', {}).get('id') != fixture['user_id']:
            raise RuntimeError('Authenticated family dashboard acceptance failed')
    result.update(status='passed', authentication_response_seconds=authentication['seconds'], dashboard_response_seconds=samples[-1]['seconds'], completion_seconds=round(authentication['seconds'] + samples[-1]['seconds'], 4), dashboard='/platform' if dashboard == '/platform' else '/{test-family}/dashboard')
    request('POST', '/logout', {}, expected=204)
except Exception as error:
    result.update(status='failed', error_type=type(error).__name__, reason=str(error) if isinstance(error, RuntimeError) else 'Private details suppressed')
output = ROOT / f'deployment/d1-proof/production-{args.phase}-login-{args.side}-{args.method}-{args.round}-result.json'
if output.exists():
    raise RuntimeError('Preserve the previous measured result instead of overwriting it')
output.write_text(json.dumps(result, indent=2) + '\n')
cookie_directory.cleanup()
print(json.dumps(result))
raise SystemExit(0 if result['status'] == 'passed' else 1)
