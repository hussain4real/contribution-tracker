"""Loopback-only, one-client OAuth compatibility probe; requires separate installation approval."""
import argparse
import base64
import hashlib
from http.server import BaseHTTPRequestHandler, HTTPServer
import json
from pathlib import Path
import re
import stat
import time

import requests

UPSTREAM = 'https://familyfunds-d1-capacity.familyfunds-lab.workers.dev/__native/inspect-oauth'


def selected_token(authorization, client_id, user_id):
    if not isinstance(authorization, str) or not authorization[:7].lower() == 'bearer ':
        return None
    token = authorization[7:]
    if len(token) > 8192 or not re.fullmatch(r'[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+', token):
        return None
    try:
        part = token.split('.')[1]
        claims = json.loads(base64.urlsafe_b64decode(part + '=' * (-len(part) % 4)))
        audience = claims.get('aud', [])
        if isinstance(audience, str):
            audience = [audience]
        if client_id not in audience or str(claims.get('sub')) != str(user_id):
            return None
        return token
    except (ValueError, TypeError, AttributeError):
        return None


def inspect_token(token, config, send=requests.post):
    response = send(UPSTREAM, headers={'Authorization': config['basic_authorization'], 'X-Native-Admin': config['native_admin'], 'Accept-Encoding': 'identity'},
                    json={'token': token}, timeout=60, allow_redirects=False, stream=True)
    try:
        data = response.raw.read(4097, decode_content=True)
        if response.status_code != 200 or len(data) > 4096:
            return {'authenticated': False, 'error': 'Private mirror inspection unavailable'}
        result = json.loads(data)
        valid = result.get('authenticated') is True and str(result.get('user_id')) == str(config['user_id']) and result.get('client_id') == config['client_id']
        reference = result.get('token_reference_sha256')
        return {'authenticated': valid, 'user_id': config['user_id'] if valid else None, 'client_id': config['client_id'] if valid else None,
                'token_reference_sha256': reference if isinstance(reference, str) and re.fullmatch('[a-f0-9]{64}', reference) else None,
                'read_only': True, 'raw_token_saved_or_logged': False}
    finally:
        response.close()


class Probe(BaseHTTPRequestHandler):
    config = {}
    deadline = 0
    attempts = 0
    selected_fingerprint = None
    result_file = None

    def log_message(self, *_):
        pass

    def do_POST(self):
        if self.path == '/check' and self.client_address[0] == '127.0.0.1' and time.monotonic() < self.deadline and type(self).attempts < 5:
            token = selected_token(self.headers.get('Authorization'), self.config['client_id'], self.config['user_id'])
            fingerprint = hashlib.sha256(token.encode()).hexdigest() if token else None
            if token and type(self).selected_fingerprint is None:
                type(self).selected_fingerprint = fingerprint
            if token and fingerprint == type(self).selected_fingerprint and not (self.result_file.exists() and json.loads(self.result_file.read_text()).get('authenticated')):
                type(self).attempts += 1
                try:
                    result = inspect_token(token, self.config)
                except Exception:
                    result = {'authenticated': False, 'error': 'Private OAuth check failed; details suppressed', 'raw_token_saved_or_logged': False}
                result.update(client_name='ChatGPT', checked_at_utc=time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime()), original_request_body_forwarded=False)
                history = json.loads(self.result_file.read_text()).get('attempts', []) if self.result_file.exists() else []
                result['attempts'] = history + [dict(result)]
                self.result_file.write_text(json.dumps(result))
                self.result_file.chmod(0o600)
        self.send_response(204)
        self.send_header('Content-Length', '0')
        self.end_headers()

    do_GET = do_POST


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--config', required=True)
    parser.add_argument('--existing-client-probe-approved', action='store_true', required=True)
    args = parser.parse_args()
    path = Path(args.config)
    if path.is_symlink() or stat.S_IMODE(path.stat().st_mode) != 0o600:
        raise SystemExit('Private regular probe configuration required')
    config = json.loads(path.read_text())
    if set(config) != {'client_id', 'user_id', 'basic_authorization', 'native_admin'} or config['user_id'] != 1 or config['client_id'] != '019ded82-bc96-73fa-8c0f-5c568bd8ea7e':
        raise SystemExit('Only the reviewed existing ChatGPT client and owner may be checked')
    if not isinstance(config['basic_authorization'], str) or not config['basic_authorization'].startswith('Basic ') or not isinstance(config['native_admin'], str) or len(config['native_admin']) < 32:
        raise SystemExit('Invalid private gateway configuration')
    Probe.config = config
    Probe.result_file = path.with_name('probe-result.json')
    Probe.deadline = time.monotonic() + 900
    server = HTTPServer(('127.0.0.1', 18871), Probe)
    server.timeout = 1
    while time.monotonic() < Probe.deadline:
        server.handle_request()
    server.server_close()
