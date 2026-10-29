import base64
import concurrent.futures
import copy
import http.cookiejar
import json
import pathlib
import re
import threading
import time
import urllib.error
import urllib.parse
import urllib.request
import uuid

ROOT = pathlib.Path(__file__).resolve().parents[2]
PRIVATE = ROOT / 'storage/app/private/d1-staging'
SECRETS = json.loads((PRIVATE / 'staging-secrets.json').read_text())
BASE = 'https://familyfunds-d1-capacity.familyfunds-lab.workers.dev'
SQL = 'https://familyfunds-d1-scale-sql.familyfunds-lab.workers.dev/query'
FAMILY = '/d1-uat-family'
PREFIX = 'CF-CONCURRENT-' + str(uuid.uuid4())
OUTPUT = ROOT / 'deployment/d1-proof/http-concurrency-read-cache-result.json'


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None


class Client:
    def __init__(self):
        self.cookies = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.cookies), NoRedirect())

    def clone(self):
        client = Client()
        for cookie in self.cookies:
            client.cookies.set_cookie(copy.copy(cookie))
        return client

    def request(self, method, path, data=None):
        authorization = base64.b64encode((SECRETS['basic_user'] + ':' + SECRETS['basic_password']).encode()).decode()
        headers = {'User-Agent': 'Mozilla/5.0', 'Authorization': 'Basic ' + authorization,
                   'Accept': 'application/json', 'Origin': BASE, 'Referer': BASE + FAMILY + '/dashboard'}
        if data is not None:
            headers['Content-Type'] = 'application/json'
            token = next((cookie.value for cookie in self.cookies if cookie.name == 'XSRF-TOKEN'), None)
            if token:
                headers['X-XSRF-TOKEN'] = urllib.parse.unquote(token)
        request = urllib.request.Request(BASE + path, headers=headers, method=method,
                                         data=json.dumps(data).encode() if data is not None else None)
        try:
            response = self.opener.open(request, timeout=40)
        except urllib.error.HTTPError as error:
            response = error
        body = response.read()
        return response.status, {key.lower(): value for key, value in response.headers.items()}, body

    def login(self):
        assert self.request('GET', '/login')[0] == 200
        status, _, _ = self.request('POST', '/login', {'email': 'd1-secretary@example.invalid',
                                                     'password': SECRETS['secretary_password']})
        assert status in (200, 302), ('login', status)
        status, headers, body = self.request('GET', FAMILY + '/dashboard')
        assert status == 200 and headers.get('x-familyfunds-runtime') == 'cloudflare-container'
        assert b'FamilyFunds D1 Scale Proof' in body


def sql(query, parameters=None):
    assert query.lower().startswith(('select ', 'pragma '))
    request = urllib.request.Request(SQL, data=json.dumps({'sql': query, 'params': parameters or []}).encode(),
                                     headers={'User-Agent': 'Mozilla/5.0', 'Content-Type': 'application/json',
                                              'Authorization': 'Bearer ' + SECRETS['scale_sql_token']}, method='POST')
    with urllib.request.urlopen(request, timeout=40) as response:
        result = json.load(response)
    assert result['database'] == 'familyfunds-d1-scale-lab'
    return result['result']['results']


def payment(amount, reference):
    return {'member_id': 102, 'amount': amount, 'paid_at': '2026-11-05', 'method': 'cash',
            'reference': reference, 'idempotency_key': str(uuid.uuid4())}


def dashboard_fund_balance(client):
    status, headers, body = client.request('GET', FAMILY + '/dashboard')
    assert status == 200 and headers.get('x-d1-staging-mode') == 'read-only'
    page = json.loads(re.search(r'<script data-page="app" type="application/json">(.*?)</script>', body.decode(), re.S).group(1))
    return page['props']['fund_balance']


def simultaneous(operations):
    barrier = threading.Barrier(len(operations))

    def send(operation):
        client, method, path, payload = operation
        barrier.wait(timeout=15)
        started = time.monotonic()
        try:
            status, headers, body = client.request(method, path, payload)
            correct = status == (302 if method == 'POST' else 200)
            if method == 'GET' and status == 200:
                correct = b'FamilyFunds D1 Scale Proof' in body
            return {'method': method, 'http': status, 'seconds': round(time.monotonic() - started, 4),
                    'correct': correct, 'runtime': headers.get('x-familyfunds-runtime'),
                    'reference': payload['reference'] if payload else None,
                    'idempotency_key': payload['idempotency_key'] if payload else None}
        except Exception as error:
            return {'method': method, 'http': None, 'seconds': round(time.monotonic() - started, 4),
                    'correct': False, 'error': type(error).__name__,
                    'reference': payload['reference'] if payload else None,
                    'idempotency_key': payload['idempotency_key'] if payload else None}

    with concurrent.futures.ThreadPoolExecutor(max_workers=len(operations)) as pool:
        return list(pool.map(send, operations))


result = {'status': 'running', 'target': BASE, 'database': 'familyfunds-d1-scale-lab', 'synthetic_only': True,
          'family_id': 1, 'member_id': 102, 'operation_prefix': PREFIX, 'client_retries': 0, 'cases': []}
expected_receipts = 0
expected_amount = 0


def save():
    OUTPUT.write_text(json.dumps(result, indent=2) + '\n')


try:
    assert sql('select count(*) n from family_members where family_id=1 and user_id=102 and archived_at is null')[0]['n'] == 1
    clients = [Client() for _ in range(4)]
    for client in clients:
        client.login()
    initial_fund_balance = dashboard_fund_balance(clients[0])
    save()
    for round_number in range(5):
        operations = [(client, 'POST', FAMILY + '/payments', payment(1, PREFIX + '-round-' + str(round_number) + '-' + str(index)))
                      for index, client in enumerate(clients)]
        rows = simultaneous(operations)
        result['cases'].append({'case': 'four simultaneous distinct payments', 'round': round_number + 1, 'requests': rows})
        expected_receipts += 4
        expected_amount += 4
        save()
        assert all(row['correct'] for row in rows), 'Distinct payment request failed; no automatic replay.'
    duplicate = payment(7, PREFIX + '-duplicate')
    rows = simultaneous([(client, 'POST', FAMILY + '/payments', duplicate) for client in clients])
    duplicate_rows = sql('select count(*) receipts,coalesce(sum(total_amount),0) amount from payment_batches where family_id=1 and reference=?', [duplicate['reference']])[0]
    result['cases'].append({'case': 'four simultaneous requests with one idempotency key', 'requests': rows, 'verification': duplicate_rows})
    expected_receipts += 1
    expected_amount += 7
    save()
    assert all(row['correct'] for row in rows) and duplicate_rows == {'receipts': 1, 'amount': 7}
    operations = [(client.clone(), 'POST', FAMILY + '/payments', payment(2, PREFIX + '-mixed-' + str(index)))
                  for index, client in enumerate(clients)]
    operations += [(client.clone(), 'GET', FAMILY + '/dashboard', None) for client in clients]
    rows = simultaneous(operations)
    result['cases'].append({'case': 'four dashboard reads overlap four financial writes', 'requests': rows})
    expected_receipts += 4
    expected_amount += 8
    save()
    assert all(row['correct'] for row in rows)
    rows = simultaneous([(clients[index % 4].clone(), 'POST', FAMILY + '/payments', payment(3, PREFIX + '-burst-' + str(index)))
                         for index in range(8)])
    result['cases'].append({'case': 'eight simultaneous distinct payments', 'requests': rows})
    expected_receipts += 8
    expected_amount += 24
    save()
    assert all(row['correct'] for row in rows)
    totals = sql('select count(*) receipts,count(distinct idempotency_key) unique_keys,coalesce(sum(total_amount),0) amount from payment_batches where family_id=1 and substr(reference,1,?)=?', [len(PREFIX), PREFIX])[0]
    allocations = sql('select count(*) allocations,coalesce(sum(amount),0) amount from payments where payment_batch_id in(select id from payment_batches where family_id=1 and substr(reference,1,?)=?)', [len(PREFIX), PREFIX])[0]
    result['verification'] = {'expected_receipts': expected_receipts, 'expected_amount': expected_amount,
                              'receipts': totals, 'allocations': allocations, 'foreign_key_violations': len(sql('pragma foreign_key_check'))}
    assert totals == {'receipts': expected_receipts, 'unique_keys': expected_receipts, 'amount': expected_amount}
    assert allocations == {'allocations': expected_receipts, 'amount': expected_amount}
    assert result['verification']['foreign_key_violations'] == 0
    final_fund_balance = dashboard_fund_balance(clients[0])
    result['verification']['read_cache_fund_balance'] = {'before': initial_fund_balance, 'after': final_fund_balance, 'expected_increase': expected_amount}
    assert final_fund_balance == initial_fund_balance + expected_amount
    result['status'] = 'passed'
except Exception as error:
    result['status'] = 'failed'
    result['error'] = type(error).__name__ + ': ' + str(error)
    raise
finally:
    save()
    print(json.dumps({'status': result['status'], 'cases': len(result['cases']), 'verification': result.get('verification')}))
