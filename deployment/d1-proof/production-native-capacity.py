"""Opt-in native candidate dashboard load check with independent synthetic sessions."""
import concurrent.futures
import base64
import copy
import hashlib
import hmac
import http.cookiejar
import json
import math
import os
from pathlib import Path
import re
import statistics
import struct
import threading
import time
import urllib.error
import urllib.parse
import urllib.request
import requests

ROOT = Path(__file__).resolve().parents[2]
PRIVATE = ROOT / 'storage/app/private/production-candidate'
BASE = 'https://familyfunds-d1-capacity.familyfunds-lab.workers.dev'
OUTPUT_NAME = os.environ.get('D1_CAPACITY_OUTPUT', 'production-native-capacity-result.json')
assert re.fullmatch(r'production-native-capacity[\w-]*\.json', OUTPUT_NAME), 'Private proof result filename required'
OUTPUT = ROOT / 'deployment/d1-proof' / OUTPUT_NAME
assert os.environ.get('D1_PRODUCTION_NATIVE_CAPACITY') == '1', 'Explicit isolated native check required'
CREDENTIALS = json.loads((PRIVATE / 'credentials.json').read_text())
FIXTURE = json.loads((PRIVATE / 'fixture.json').read_text())
assert FIXTURE['synthetic_only'] and FIXTURE['database'] == 'familyfunds-production'
USERS = [user for user in FIXTURE['tables']['users'] if user['id'] in range(1, 21)]
assert len(USERS) == 20


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None


class Client:
    def __init__(self, user):
        self.user = user
        self.jar = http.cookiejar.MozillaCookieJar(str(PRIVATE / ('capacity-' + str(user['id']) + '.cookies')))
        if Path(self.jar.filename).exists():
            self.jar.load(ignore_discard=True, ignore_expires=True)
        elif user['id'] == 1:
            admin = http.cookiejar.MozillaCookieJar(str(PRIVATE / 'admin-cookies.txt'))
            admin.load(ignore_discard=True, ignore_expires=True)
            for cookie in admin:
                self.jar.set_cookie(copy.copy(cookie))
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar), NoRedirect())
        self.session = requests.Session()
        self.session.cookies = self.jar
        assert self.session.get_adapter('https://').max_retries.total == 0

    def request(self, method, path, data=None):
        headers = {'Authorization': CREDENTIALS['basic_authorization'], 'User-Agent': 'FamilyFunds-Isolated-Native-Check/1.0',
                   'Accept': 'application/json', 'Origin': BASE, 'Referer': BASE + '/login'}
        if data is not None:
            headers['Content-Type'] = 'application/json'
            headers['X-XSRF-TOKEN'] = urllib.parse.unquote(next(cookie.value for cookie in self.jar if cookie.name == 'XSRF-TOKEN'))
        if os.environ.get('D1_CAPACITY_TRANSPORT', 'fresh') == 'persistent':
            response = self.session.request(method, BASE + path, headers=headers,
                data=json.dumps(data).encode() if data is not None else None,
                timeout=45, allow_redirects=False)
            self.last_timing = response.headers.get('Server-Timing')
            return response.status_code, response.content
        request = urllib.request.Request(BASE + path, method=method, headers=headers,
                                         data=json.dumps(data).encode() if data is not None else None)
        try:
            response = self.opener.open(request, timeout=45)
        except urllib.error.HTTPError as error:
            response = error
        self.last_timing = response.headers.get('Server-Timing')
        return response.status, response.read()

    def dashboard(self):
        status, body = self.request('GET', '/candidate-first/dashboard')
        assert status == 200, ('dashboard status', self.user['id'], status)
        match = re.search(rb'<script[^>]*data-page[^>]*>(.*?)</script>', body, re.S)
        if not match:
            failure = PRIVATE / ('capacity-unexpected-' + str(self.user['id']) + '.html')
            failure.write_bytes(body)
            failure.chmod(0o600)
            raise AssertionError(('Missing Inertia page', len(body), hashlib.sha256(body).hexdigest()))
        page = json.loads(match[1])
        assert page['component'] == 'Dashboard/Index' and page['props']['auth']['user']['id'] == self.user['id']
        values = {key: value for key, value in page['props'].items() if key in ['summary', 'personal', 'fund_balance', 'family_aggregate']}
        return hashlib.sha256(json.dumps(values, sort_keys=True).encode()).hexdigest()


def prepare(user):
    client = Client(user)
    try:
        client.digest = client.dashboard()
    except AssertionError as error:
        detail = error.args[0] if error.args else None
        if not isinstance(detail, tuple) or detail[0] != 'dashboard status' or detail[2] not in (302, 401):
            raise
        assert client.request('GET', '/login')[0] == 200
        status, body = client.request('POST', '/login', {'email': user['email'],
            'password': CREDENTIALS['login_password'] if user['id'] in (1, 2) else 'password'})
        assert status in (200, 302), ('login status', user['id'], status)
        if status == 200 and json.loads(body).get('two_factor') is True:
            assert user['id'] == 1, 'Only the independent synthetic admin is enrolled'
            secret = CREDENTIALS['totp_secret']
            key = base64.b32decode(secret + '=' * ((8 - len(secret) % 8) % 8))
            digest = hmac.new(key, struct.pack('>Q', int(time.time()) // 30), hashlib.sha1).digest()
            offset = digest[-1] & 15
            code = str((struct.unpack('>I', digest[offset:offset + 4])[0] & 0x7fffffff) % 1000000).zfill(6)
            status, _ = client.request('POST', '/two-factor-challenge', {'code': code})
            assert status in (200, 204, 302), ('independent MFA status', status)
        client.digest = client.dashboard()
    assert client.dashboard() == client.digest
    client.jar.save(ignore_discard=True, ignore_expires=True)
    Path(client.jar.filename).chmod(0o600)
    print(json.dumps({'prepared_user': user['id']}), flush=True)
    return client


ROUND_COUNT = int(os.environ.get('D1_CAPACITY_ROUNDS', '5'))
assert 5 <= ROUND_COUNT <= 30, 'Capacity acceptance requires five to thirty complete 20-user bursts'
result = {'synthetic_only': True, 'users': 20, 'rounds': [], 'status': 'preparing', 'preparation_concurrency': 2,
          'vm_vcpu': 2, 'vm_memory_mib': 6144, 'placement': 'sin22', 'automatic_retries': 0,
          'client_transport': os.environ.get('D1_CAPACITY_TRANSPORT', 'fresh'),
          'measurement_scope': 'Complete authenticated dashboard responses with independent warmed user sessions; browser rendering measured separately'}
try:
    warm = Client(USERS[0])
    assert warm.request('GET', '/up')[0] == 200
    request = urllib.request.Request(BASE + '/__native/runtime', headers={
        'Authorization': CREDENTIALS['basic_authorization'], 'X-Native-Admin': CREDENTIALS['admin_token'],
        'User-Agent': 'FamilyFunds-Isolated-Native-Check/1.0'})
    runtime = json.load(urllib.request.urlopen(request, timeout=30))
    manifest = json.loads((ROOT / 'deployment/d1-proof/production-runtime.json').read_text())
    assert runtime['image'] == manifest['candidate_runtime']['image'], 'Unverified running candidate image'
    result['runtime'] = {'image': runtime['image'], 'revision': runtime['build']}
    with concurrent.futures.ThreadPoolExecutor(max_workers=2) as executor:
        clients = list(executor.map(prepare, USERS))
    for number in range(ROUND_COUNT):
        barrier = threading.Barrier(20)

        def hit(client):
            barrier.wait(timeout=15)
            wall, monotonic = time.time(), time.monotonic()
            try:
                correct, error = client.dashboard() == client.digest, None
            except Exception as exception:
                correct, error = False, type(exception).__name__ + ': ' + str(exception)[:80]
            elapsed, wall_elapsed = time.monotonic() - monotonic, time.time() - wall
            return {'user_id': client.user['id'], 'seconds': round(elapsed, 4), 'correct': correct,
                    'clock_consistent': abs(elapsed - wall_elapsed) < 0.25, 'error': error,
                    'server_timing': client.last_timing}

        with concurrent.futures.ThreadPoolExecutor(max_workers=20) as executor:
            rows = list(executor.map(hit, clients))
        result['rounds'].append(rows)
        OUTPUT.write_text(json.dumps(result, indent=2))
        print(json.dumps({'round': number + 1, 'max_seconds': max(row['seconds'] for row in rows),
                          'errors': sum(not row['correct'] for row in rows)}), flush=True)
    rows = sum(result['rounds'], [])
    times = sorted(row['seconds'] for row in rows)
    result['summary'] = {'requests': len(rows), 'correct': sum(row['correct'] for row in rows), 'median_seconds': statistics.median(times),
                         'p95_seconds': times[math.ceil(len(times) * 0.95) - 1], 'min_seconds': min(times), 'max_seconds': max(times),
                         'under_3_seconds': sum(value < 3 for value in times), 'clocks_consistent': all(row['clock_consistent'] for row in rows)}
    result['acceptance'] = {'metric': 'p95', 'threshold_seconds': 3, 'failed_responses_allowed': 0,
                            'owner_confirmed': True, 'maximum_remains_reported': True}
    result['status'] = 'passed' if all(row['correct'] and row['clock_consistent'] for row in rows) and result['summary']['p95_seconds'] < 3 else 'failed'
except Exception as exception:
    result.update(status='failed-preparation', error=type(exception).__name__ + ': ' + str(exception)[:120])
finally:
    OUTPUT.write_text(json.dumps(result, indent=2) + '\n')
    print(json.dumps({key: value for key, value in result.items() if key != 'rounds'}), flush=True)

if result['status'] != 'passed':
    raise SystemExit(1)
