"""Temporary loopback-only passkey bridge; installation needs explicit origin-path approval."""
import argparse
import hashlib
import base64
import hmac
from http.cookies import SimpleCookie
from http.server import BaseHTTPRequestHandler, HTTPServer
import json
from pathlib import Path
import re
import secrets
import stat
import time
from urllib.parse import unquote

import requests

UPSTREAM = 'https://familyfunds-d1-capacity.familyfunds-lab.workers.dev'
ORIGIN = 'https://familyfunds.app'
COOKIE = 'familyfunds_origin_uat'
SCRIPT = '''document.querySelector('button').onclick=async()=>{
const output=document.querySelector('output');output.textContent='Requesting a passkey challenge from the isolated D1 mirror…';
try{const r=await fetch('options',{cache:'no-store'});if(!r.ok)throw Error('Challenge request failed');const d=await r.json();
if(d.options.rpId!=='familyfunds.app')throw Error('Unexpected relying party');
const options=PublicKeyCredential.parseRequestOptionsFromJSON(d.options);
output.textContent='Complete the browser passkey prompt.';const credential=await navigator.credentials.get({publicKey:options});
const verified=await fetch('verify',{method:'POST',headers:{'Content-Type':'application/json','X-FamilyFunds-UAT':'1'},body:JSON.stringify({credential:credential.toJSON(),remember:false})});
const result=await verified.json();if(!verified.ok||!result.authenticated)throw Error('Passkey verification did not succeed');
output.textContent='PASS: existing passkey authenticated at familyfunds.app against the isolated D1 mirror; authenticated platform page returned HTTP 200. Production remains on PostgreSQL.';
}catch(error){output.textContent='Check incomplete: '+error.message;}};'''
HTML = ('<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width">'
        '<title>FamilyFund production-origin authentication check</title><h1>FamilyFund authentication check</h1>'
        '<p>This protected check uses the isolated D1 mirror. Ordinary production traffic remains on PostgreSQL.</p>'
        '<button type="button">Test my existing passkey</button><p><output>No test has run.</output></p>'
        '<script>' + SCRIPT + '</script></html>').encode()


class Bridge(BaseHTTPRequestHandler):
    config = {}
    sessions = {}
    deadline = 0
    make_session = staticmethod(requests.Session)

    def log_message(self, *_):
        pass

    def reply(self, status, body, content_type='application/json', session=None):
        data = body if isinstance(body, bytes) else json.dumps(body).encode()
        self.send_response(status)
        self.send_header('Content-Type', content_type)
        self.send_header('Content-Length', str(len(data)))
        self.send_header('Cache-Control', 'no-store')
        self.send_header('X-Robots-Tag', 'noindex, nofollow, noarchive')
        self.send_header('X-Content-Type-Options', 'nosniff')
        self.send_header('Referrer-Policy', 'no-referrer')
        self.send_header('Content-Security-Policy', "default-src 'none'; connect-src 'self'; script-src 'sha256-" + base64.b64encode(hashlib.sha256(SCRIPT.encode()).digest()).decode() + "'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'")
        if status == 401:
            self.send_header('WWW-Authenticate', 'Basic realm="FamilyFund isolated origin authentication"')
        if session:
            self.send_header('Set-Cookie', COOKIE + '=' + session + '; Path=' + self.config['prefix'] + '; Max-Age=1800; Secure; HttpOnly; SameSite=Strict')
        self.end_headers()
        self.wfile.write(data)

    def upstream(self, client, method, path, payload=None):
        headers = {'Authorization': self.config['basic_authorization'], 'Accept': 'application/json', 'Accept-Encoding': 'identity',
                   'X-Forwarded-Host': 'familyfunds.app', 'X-Forwarded-Proto': 'https', 'Origin': ORIGIN}
        if method == 'POST':
            token = next((cookie.value for cookie in client.cookies if cookie.name == 'XSRF-TOKEN'), None)
            if not token:
                raise ValueError('Missing test CSRF state')
            headers['X-XSRF-TOKEN'] = unquote(token)
        response = client.request(method, UPSTREAM + path, headers=headers, json=payload, timeout=40, allow_redirects=False, stream=True)
        try:
            data = response.raw.read(1048577, decode_content=True)
            if len(data) > 1048576:
                raise ValueError('Oversized test response')
            return response.status_code, data
        finally:
            response.close()

    def handle_check(self):
        if time.monotonic() >= self.deadline:
            return self.reply(410, {'error': 'Test window ended'})
        supplied = self.headers.get('Authorization', '')
        if not hmac.compare_digest(supplied, self.config['basic_authorization']):
            return self.reply(401, {'error': 'Protected authentication check'})
        path = self.path.split('?', 1)[0]
        prefix = self.config['prefix']
        if self.command == 'GET' and path == prefix:
            return self.reply(200, HTML, 'text/html; charset=utf-8')
        if path not in [prefix + 'options', prefix + 'verify']:
            return self.reply(404, {'error': 'Unknown test operation'})
        if (path.endswith('options') and self.command != 'GET') or (path.endswith('verify') and self.command != 'POST'):
            return self.reply(405, {'error': 'Unsupported test method'})
        self.sessions = type(self).sessions
        for key, (created, client) in list(self.sessions.items()):
            if time.monotonic() - created >= 1800:
                client.close()
                del self.sessions[key]
        cookies = SimpleCookie(self.headers.get('Cookie', ''))
        sid = cookies[COOKIE].value if COOKIE in cookies else ''
        if path.endswith('options'):
            if len(self.sessions) >= 4:
                return self.reply(429, {'error': 'Test session limit'})
            sid = secrets.token_urlsafe(32)
            client = self.make_session()
            self.sessions[sid] = (time.monotonic(), client)
            status, data = self.upstream(client, 'GET', '/passkeys/login/options')
            result = json.loads(data)
            if status != 200 or result.get('options', {}).get('rpId') != 'familyfunds.app':
                return self.reply(502, {'error': 'Mirror origin challenge unavailable'})
            return self.reply(200, result, session=sid)
        if self.headers.get('Origin') != ORIGIN or self.headers.get('X-FamilyFunds-UAT') != '1':
            return self.reply(403, {'error': 'Unexpected test origin'})
        if sid not in self.sessions:
            return self.reply(419, {'error': 'Test session expired'})
        length = int(self.headers.get('Content-Length', '0'))
        if not 0 < length <= 65536:
            return self.reply(413, {'error': 'Invalid test payload size'})
        payload = json.loads(self.rfile.read(length))
        if set(payload) - {'credential', 'remember'} or not isinstance(payload.get('credential'), dict) or payload.get('remember', False) is not False:
            return self.reply(400, {'error': 'Invalid passkey payload'})
        client = self.sessions[sid][1]
        status, _ = self.upstream(client, 'POST', '/passkeys/login', payload)
        if status != 200:
            return self.reply(status, {'error': 'Mirror passkey verification failed'})
        authenticated, data = self.upstream(client, 'GET', '/platform')
        valid = authenticated == 200 and b'Platform Overview' in data and b'Total families' in data
        return self.reply(200 if valid else 502, {'authenticated': valid, 'platform_http': authenticated, 'backend': 'isolated D1 mirror'})

    def do_GET(self):
        try:
            self.handle_check()
        except Exception:
            self.reply(502, {'error': 'Isolated authentication check failed; private details suppressed'})

    do_POST = do_GET


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--config', required=True)
    parser.add_argument('--origin-path-installation-approved', action='store_true', required=True)
    args = parser.parse_args()
    path = Path(args.config)
    if path.is_symlink() or stat.S_IMODE(path.stat().st_mode) != 0o600:
        raise SystemExit('Private regular test configuration required')
    Bridge.config = json.loads(path.read_text())
    if set(Bridge.config) != {'prefix', 'basic_authorization'} or not re.fullmatch(r'/__familyfunds_auth_uat_[a-f0-9]{16}/', Bridge.config['prefix']):
        raise SystemExit('Invalid fixed test configuration')
    if not isinstance(Bridge.config['basic_authorization'], str) or not Bridge.config['basic_authorization'].startswith('Basic ') or len(Bridge.config['basic_authorization']) < 40:
        raise SystemExit('Invalid protected test credential')
    Bridge.deadline = time.monotonic() + 1800
    server = HTTPServer(('127.0.0.1', 18870), Bridge)
    server.timeout = 1
    while time.monotonic() < Bridge.deadline:
        server.handle_request()
    server.server_close()
