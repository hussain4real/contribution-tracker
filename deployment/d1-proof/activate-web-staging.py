"""Activate HTTPS only for the dedicated, protected D1 staging site."""
import subprocess
from pathlib import Path

root=Path('/var/www/familyfunds-d1-staging')
domain='d1-staging.familyfunds.app'
if not (root/'.d1-staging-runtime').exists():raise RuntimeError('Missing isolated runtime marker')
if not Path('/etc/letsencrypt/live/'+domain+'/fullchain.pem').exists():raise RuntimeError('Staging certificate is not ready')
site=Path('/etc/nginx/sites-available/d1-staging.familyfunds.app.conf')
previous=site.read_text()
content=r'''server {
    listen 80;
    listen [::]:80;
    server_name d1-staging.familyfunds.app;
    root /var/www/familyfunds-d1-staging/public;
    location ^~ /.well-known/acme-challenge/ { try_files $uri =404; }
    location / { return 301 https://$host$request_uri; }
}
server {
    listen 443 ssl http2;
    listen [::]:443 ssl http2;
    server_name d1-staging.familyfunds.app;
    root /var/www/familyfunds-d1-staging/public;
    index index.php;
    ssl_certificate /etc/letsencrypt/live/d1-staging.familyfunds.app/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/d1-staging.familyfunds.app/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;
    auth_basic "FamilyFunds isolated D1 staging";
    auth_basic_user_file /etc/nginx/familyfunds-d1-staging.htpasswd;
    add_header X-Robots-Tag "noindex, nofollow, noarchive" always;
    add_header Cache-Control "no-store" always;
    add_header X-Content-Type-Options "nosniff" always;
    access_log /var/log/nginx/familyfunds-d1-staging-access.log;
    error_log /var/log/nginx/familyfunds-d1-staging-error.log;
    client_max_body_size 2M;
    location = /robots.txt {
        auth_basic off;
        default_type text/plain;
        return 200 "User-agent: *\nDisallow: /\n";
    }
    location / { try_files $uri $uri/ /index.php?$query_string; }
    location = /index.php {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $realpath_root/index.php;
        fastcgi_param DOCUMENT_ROOT $realpath_root;
        fastcgi_param HTTPS on;
        fastcgi_param HTTP_PROXY "";
        fastcgi_param HTTP_AUTHORIZATION $http_authorization;
        fastcgi_pass unix:/run/php/php8.4-d1-staging.sock;
        fastcgi_read_timeout 45s;
    }
    location ~ \.php$ { return 404; }
    location ~ /\. { deny all; }
}
'''
try:
    site.write_text(content)
    subprocess.run(['nginx','-t'],check=True)
except Exception:
    site.write_text(previous)
    raise
subprocess.run(['systemctl','reload','nginx'],check=True)
print('Protected D1 staging HTTPS site activated')
