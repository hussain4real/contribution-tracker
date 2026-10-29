"""Use system curl consistently when the local Python TLS stack is unreliable."""

import base64
from email.parser import Parser
import http.client
import json
from pathlib import Path
import socket
import subprocess
import tempfile
import urllib.parse
import urllib.request


def parse_headers(items):
    headers = {}
    for key, value in items:
        key = key.lower()
        if key == "server-timing" and key in headers:
            headers[key] += ", " + value
        else:
            headers[key] = value
    return headers


def install(client_module):
    def request(client, method, path, data=None, extra=None):
        headers = {"User-Agent": "Mozilla/5.0", "Authorization": "Basic " + base64.b64encode(
            (client_module.S["basic_user"] + ":" + client_module.S["basic_password"]).encode()).decode(),
            "Accept": "application/json", "Origin": client_module.BASE,
            "Referer": client_module.BASE + client_module.F + "/dashboard"}
        if data is not None:
            headers["Content-Type"] = "application/json"
            token = next((cookie.value for cookie in client.jar if cookie.name == "XSRF-TOKEN"), None)
            if token:
                headers["X-XSRF-TOKEN"] = urllib.parse.unquote(token)
        headers.update(extra or {})
        with tempfile.TemporaryDirectory(prefix="capacity-http-", dir=client_module.P) as directory:
            root = Path(directory)
            configuration = ["silent", "show-error", "ipv4", "connect-timeout = 10", "max-time = 40",
                "url = " + json.dumps(client_module.BASE + path), "request = " + json.dumps(method),
                "cookie = " + json.dumps(client.jar.filename), "cookie-jar = " + json.dumps(client.jar.filename),
                "dump-header = " + json.dumps(str(root / "headers")), "output = " + json.dumps(str(root / "body")),
                'write-out = "%{http_code} %{remote_ip} %{time_namelookup} %{time_connect} %{time_appconnect} %{time_starttransfer} %{time_total}"']
            configuration.extend("header = " + json.dumps(key + ": " + value) for key, value in headers.items())
            if getattr(client_module, "EDGE_IP", None):
                configuration.append("resolve = " + json.dumps(urllib.parse.urlsplit(client_module.BASE).hostname + ":443:" + client_module.EDGE_IP))
            if data is not None:
                configuration.append("data = " + json.dumps(json.dumps(data)))
            result = subprocess.run(["/usr/bin/curl", "--config", "-"], input="\n".join(configuration).encode(),
                                    capture_output=True, timeout=45)
            if result.returncode != 0:
                raise RuntimeError("curl " + str(result.returncode) + ": " + result.stderr.decode()[:180] + " timings=" + result.stdout.decode()[:180])
            parsed = Parser().parsestr((root / "headers").read_text().split("\n", 1)[1])
            response_headers = parse_headers(parsed.items())
            timing = result.stdout.decode().split()
            response_headers["x-proof-client-timing"] = " ".join(timing[1:])
            body = (root / "body").read_bytes()
            client.jar.clear()
            client.jar.load(ignore_discard=True, ignore_expires=True)
            Path(client.jar.filename).chmod(0o600)
            return int(timing[0]), response_headers, body

    client_module.Client.request = request


def install_persistent(client_module):
    def request(client, method, path, data=None, extra=None):
        headers = {"User-Agent": "Mozilla/5.0", "Authorization": "Basic " + base64.b64encode(
            (client_module.S["basic_user"] + ":" + client_module.S["basic_password"]).encode()).decode(),
            "Accept": "application/json", "Origin": client_module.BASE,
            "Referer": client_module.BASE + client_module.F + "/dashboard"}
        if data is not None:
            headers["Content-Type"] = "application/json"
            token = next((cookie.value for cookie in client.jar if cookie.name == "XSRF-TOKEN"), None)
            if token:
                headers["X-XSRF-TOKEN"] = urllib.parse.unquote(token)
        headers.update(extra or {})
        cookie_request = urllib.request.Request(client_module.BASE + path, headers=headers)
        client.jar.add_cookie_header(cookie_request)
        if getattr(client, "proof_connection", None) is None:
            client.proof_connection = http.client.HTTPSConnection(urllib.parse.urlsplit(client_module.BASE).hostname,
                timeout=40, context=client_module.TLS_CONTEXT)
            client.proof_connection._create_connection = lambda address, *args, **kwargs: socket.create_connection(
                (socket.gethostbyname(address[0]), address[1]), *args, **kwargs)
            client.proof_connection_count = getattr(client, "proof_connection_count", 0) + 1
        try:
            client.proof_connection.request(method, path, body=json.dumps(data).encode() if data is not None else None,
                                            headers=dict(cookie_request.header_items()))
            response = client.proof_connection.getresponse()
            body = response.read()
            client.jar.extract_cookies(response, cookie_request)
            client.jar.save(ignore_discard=True, ignore_expires=True)
            Path(client.jar.filename).chmod(0o600)
            response_headers = parse_headers(response.getheaders())
            response_headers["x-proof-client-timing"] = "connections_created=" + str(client.proof_connection_count)
            if response.will_close:
                client.proof_connection.close()
                client.proof_connection = None
            return response.status, response_headers, body
        except Exception:
            client.proof_connection.close()
            client.proof_connection = None
            raise

    client_module.Client.request = request
