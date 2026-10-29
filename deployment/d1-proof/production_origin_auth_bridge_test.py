import importlib.util
import io
import json
from pathlib import Path
import time
import unittest

spec = importlib.util.spec_from_file_location('origin_bridge', Path(__file__).with_name('production-origin-auth-bridge.py'))
bridge = importlib.util.module_from_spec(spec)
spec.loader.exec_module(bridge)


class FakeClient:
    def close(self):
        pass


class BridgeBoundaryTest(unittest.TestCase):
    def setUp(self):
        bridge.Bridge.sessions = {}
        self.handler = object.__new__(bridge.Bridge)
        self.handler.config = {'prefix': '/__familyfunds_auth_uat_0123456789abcdef/', 'basic_authorization': 'Basic protected-fixture-credential-only'}
        self.handler.deadline = time.monotonic() + 100
        self.handler.command = 'GET'
        self.handler.path = self.handler.config['prefix']
        self.handler.headers = {'Authorization': self.handler.config['basic_authorization']}
        self.handler.make_session = FakeClient
        self.responses = []
        self.calls = []
        self.handler.reply = lambda *args, **kwargs: self.responses.append((args, kwargs))
        self.handler.upstream = self.fake_upstream

    def fake_upstream(self, client, method, path, payload=None):
        self.calls.append((method, path, payload))
        if path.endswith('options'):
            return 200, json.dumps({'options': {'rpId': 'familyfunds.app', 'challenge': 'synthetic-challenge'}}).encode()
        if path == '/platform':
            return 200, b'Platform Overview Total families'
        return 200, b'{}'

    def test_authentication_expiry_and_fixed_paths_block_upstream_access(self):
        self.handler.headers = {}
        self.handler.handle_check()
        self.assertEqual(self.responses[-1][0][0], 401)
        self.handler.headers = {'Authorization': self.handler.config['basic_authorization']}
        self.handler.path += 'payments'
        self.handler.handle_check()
        self.assertEqual(self.responses[-1][0][0], 404)
        self.handler.deadline = 0
        self.handler.handle_check()
        self.assertEqual(self.responses[-1][0][0], 410)
        self.assertEqual(self.calls, [])

    def test_real_origin_passkey_flow_reaches_only_fixed_mirror_routes(self):
        self.handler.path += 'options'
        self.handler.handle_check()
        sid = self.responses[-1][1]['session']
        self.assertEqual(self.calls, [('GET', '/passkeys/login/options', None)])
        self.handler.path = self.handler.config['prefix'] + 'verify'
        self.handler.command = 'POST'
        data = json.dumps({'credential': {'id': 'synthetic'}, 'remember': False}).encode()
        self.handler.headers.update({'Cookie': bridge.COOKIE + '=' + sid, 'Origin': bridge.ORIGIN, 'X-FamilyFunds-UAT': '1', 'Content-Length': str(len(data))})
        self.handler.rfile = io.BytesIO(data)
        self.handler.handle_check()
        self.assertTrue(self.responses[-1][0][1]['authenticated'])
        self.assertEqual([call[1] for call in self.calls], ['/passkeys/login/options', '/passkeys/login', '/platform'])
        self.assertFalse('credential' in json.dumps(self.responses[-1]))
        self.assertEqual(bridge.UPSTREAM, 'https://familyfunds-d1-capacity.familyfunds-lab.workers.dev')

    def test_cross_origin_verification_and_oversized_payload_are_rejected(self):
        self.handler.path += 'verify'
        self.handler.command = 'POST'
        self.handler.headers.update({'Origin': 'https://other.example', 'X-FamilyFunds-UAT': '1'})
        self.handler.handle_check()
        self.assertEqual(self.responses[-1][0][0], 403)
        bridge.Bridge.sessions['test'] = (time.monotonic(), FakeClient())
        self.handler.headers.update({'Origin': bridge.ORIGIN, 'Cookie': bridge.COOKIE + '=test', 'Content-Length': '65537'})
        self.handler.handle_check()
        self.assertEqual(self.responses[-1][0][0], 413)
        self.assertEqual(self.calls, [])


if __name__ == '__main__':
    unittest.main()
