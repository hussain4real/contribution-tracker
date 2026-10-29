"""Forward clients with cached origin DNS to the verified Cloudflare edge."""
import hashlib
import ipaddress
import json
from pathlib import Path
import subprocess
import sys

ROOT = Path('/root/familyfunds-production-cutover-20261027')
SITE = Path('/etc/nginx/sites-available/familyfunds.app.conf')


def run(args):
    result = subprocess.run(args, capture_output=True, timeout=30)
    if result.returncode != 0:
        raise RuntimeError('Origin forwarder operation failed: ' + args[0])


def proxy_configuration(address, hostname='familyfunds.app'):
    address = str(ipaddress.IPv4Address(address))
    if hostname not in ['familyfunds.app', 'familyfunds-d1-capacity.familyfunds-lab.workers.dev']:
        raise ValueError('Unexpected relay hostname')
    return f'''    location / {{
        proxy_pass https://{address};
        proxy_ssl_server_name on;
        proxy_ssl_name {hostname};
        proxy_ssl_verify on;
        proxy_ssl_verify_depth 3;
        proxy_ssl_trusted_certificate /etc/ssl/certs/ca-certificates.crt;
        proxy_set_header Host {hostname};
        proxy_set_header X-Forwarded-Proto https;
        proxy_set_header X-Forwarded-For $remote_addr;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header Connection "";
        proxy_http_version 1.1;
        proxy_buffer_size 64k;
        proxy_buffers 8 64k;
        proxy_busy_buffers_size 128k;
        proxy_buffering off;
        proxy_next_upstream off;
        proxy_read_timeout 300s;
        proxy_send_timeout 60s;
        proxy_redirect off;
    }}'''


def install(data):
    address = ipaddress.IPv4Address(data['address'])
    networks = [ipaddress.IPv4Network(network) for network in data['cloudflare_networks']]
    if not networks or not any(address in network for network in networks):
        raise RuntimeError('A verified Cloudflare edge address is required')
    if not (ROOT / 'freeze-result.json').exists() or (ROOT / 'd1-writers-armed').exists():
        raise RuntimeError('Pre-write frozen source is required')
    original = (ROOT / 'nginx.original').read_bytes()
    if hashlib.sha256(original).hexdigest() != '936df5a4bb7e9591b765823e17ddb877474ccec2dcc9071cd4f85ca60bef361e':
        raise RuntimeError('Original Nginx baseline changed')
    expected = original.replace(b'    root /var/www/contribution-tracker/public;', b'    root /var/www/contribution-tracker/public;\n    return 503;\n    add_header Retry-After "60" always;')
    if SITE.read_bytes() != expected:
        raise RuntimeError('Source must still have its frozen Nginx configuration')
    config = '''server {
    listen 80;
    listen [::]:80;
    server_name familyfunds.app;
    return 301 https://familyfunds.app$request_uri;
}
server {
    listen 443 ssl http2;
    listen [::]:443 ssl http2;
    server_name familyfunds.app;
    ssl_certificate /etc/letsencrypt/live/familyfunds.app/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/familyfunds.app/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;
    client_max_body_size 10M;
    access_log /var/log/nginx/familyfunds.app-access.log;
    error_log /var/log/nginx/familyfunds.app-error.log;
    add_header X-FamilyFunds-Origin-Forwarder "cloudflare" always;
PROXY_CONFIGURATION
}
'''.replace('PROXY_CONFIGURATION', proxy_configuration(address)).encode()
    try:
        SITE.write_bytes(config)
        run(['nginx', '-t'])
        run(['systemctl', 'reload', 'nginx'])
    except Exception:
        SITE.write_bytes(expected)
        run(['nginx', '-t'])
        run(['systemctl', 'reload', 'nginx'])
        raise
    result = {'status': 'forwarding', 'edge_address': str(address), 'source_postgres_still_frozen': True,
              'nginx_sha256': hashlib.sha256(config).hexdigest(), 'upstream_tls_verification_enabled': True}
    (ROOT / 'origin-forwarder.json').write_text(json.dumps(result, indent=2) + '\n')
    return result


if __name__ == '__main__':
    if sys.argv[1:] != ['--approved-cutover']:
        raise SystemExit('Approved cutover operation required')
    print(json.dumps(install(json.load(sys.stdin))))
