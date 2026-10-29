import contextlib
import http.server
import importlib.util
import json
from pathlib import Path
import shutil
import socket
import subprocess
import tempfile
import threading
import time
import unittest
import urllib.error
import urllib.request

spec = importlib.util.spec_from_file_location('forward', Path(__file__).with_name('production-cutover-origin-forward.py'))
forward = importlib.util.module_from_spec(spec)
spec.loader.exec_module(forward)


class Upstream(http.server.BaseHTTPRequestHandler):
    requests = []

    def do_GET(self):
        self.respond()

    def do_POST(self):
        self.respond()

    def respond(self):
        body = self.rfile.read(int(self.headers.get('Content-Length', '0')))
        received = {'authorization': self.headers.get('Authorization'), 'cookie': self.headers.get('Cookie'),
                    'body': body.decode(), 'method': self.command}
        self.requests.append(received)
        data = json.dumps(received).encode()
        self.send_response(200)
        self.send_header('Link', '<' + 'a' * 20000 + '>; rel=preload')
        self.send_header('Set-Cookie', 'first=fixture; Secure; HttpOnly')
        self.send_header('Set-Cookie', 'second=fixture; Secure')
        self.send_header('Content-Length', str(len(data)))
        self.end_headers()
        with contextlib.suppress(BrokenPipeError, ConnectionResetError):
            self.wfile.write(data)

    def log_message(self, *_args):
        pass


@unittest.skipUnless(shutil.which('nginx'), 'Requires the installed Hetzner Nginx binary')
class RelayHeaderTest(unittest.TestCase):
    def setUp(self):
        Upstream.requests = []
        self.upstream = http.server.ThreadingHTTPServer(('127.0.0.1', 0), Upstream)
        self.thread = threading.Thread(target=self.upstream.serve_forever, daemon=True)
        self.thread.start()
        self.directory = tempfile.TemporaryDirectory(prefix='familyfunds-relay-test-')
        self.root = Path(self.directory.name)
        self.process = None

    def tearDown(self):
        if self.process:
            self.process.terminate()
            self.process.wait(timeout=10)
        self.upstream.shutdown()
        self.upstream.server_close()
        self.directory.cleanup()

    def start(self, original=False):
        with socket.socket() as listener:
            listener.bind(('127.0.0.1', 0))
            port = listener.getsockname()[1]
        block = forward.proxy_configuration('104.21.69.185')
        block = block.replace('https://104.21.69.185', 'http://127.0.0.1:' + str(self.upstream.server_port))
        if original:
            block = block.replace('        proxy_buffer_size 64k;\n', '')
        config = self.root / 'nginx.conf'
        config.write_text('daemon off;\nmaster_process off;\npid ' + str(self.root / 'nginx.pid')
                          + ';\nerror_log ' + str(self.root / 'error.log') + ';\nevents {}\nhttp { access_log off; server { listen 127.0.0.1:'
                          + str(port) + ';\n' + block + '\n} }')
        subprocess.run(['nginx', '-t', '-p', str(self.root), '-c', str(config)], capture_output=True, check=True)
        self.process = subprocess.Popen(['nginx', '-p', str(self.root), '-c', str(config)], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        deadline = time.monotonic() + 5
        while True:
            try:
                with socket.create_connection(('127.0.0.1', port), timeout=0.2):
                    break
            except OSError:
                if time.monotonic() > deadline:
                    self.fail('Loopback Nginx did not become ready')
                time.sleep(0.02)
        return 'http://127.0.0.1:' + str(port) + '/probe'

    def test_original_header_limit_reproduces_the_logged_502(self):
        with self.assertRaises(urllib.error.HTTPError) as error:
            urllib.request.urlopen(self.start(original=True), timeout=5)
        self.assertEqual(error.exception.code, 502)
        self.assertIn('upstream sent too big header', (self.root / 'error.log').read_text())

    def test_fixed_relay_preserves_large_headers_cookies_authorization_and_one_post(self):
        url = self.start()
        request = urllib.request.Request(url, data=b'fixture-body', method='POST',
                                         headers={'Authorization': 'Bearer fixture-only', 'Cookie': 'session=fixture-only'})
        with urllib.request.urlopen(request, timeout=5) as response:
            self.assertEqual(response.status, 200)
            self.assertGreater(len(response.headers['Link']), 20000)
            self.assertEqual(len(response.headers.get_all('Set-Cookie')), 2)
            actual = json.load(response)
        self.assertEqual(actual, {'authorization': 'Bearer fixture-only', 'cookie': 'session=fixture-only',
                                  'body': 'fixture-body', 'method': 'POST'})
        self.assertEqual(len(Upstream.requests), 1)


if __name__ == '__main__':
    unittest.main()
