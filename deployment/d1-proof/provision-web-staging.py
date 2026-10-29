"""Provision only the dedicated D1 staging PHP runtime and virtual host."""
import json
import os
import pwd
import shutil
import subprocess
import tarfile
from pathlib import Path

ROOT = Path('/var/www/familyfunds-d1-staging')
DOMAIN = 'd1-staging.familyfunds.app'
USER = 'familyfunds-d1'
if os.geteuid() != 0:
    raise RuntimeError('Provisioning requires root on the approved staging host')
if ROOT.exists() and not (ROOT/'.d1-staging-runtime').exists():
    raise RuntimeError('Refusing to modify an existing unmarked application directory')
try:
    pwd.getpwnam(USER)
except KeyError:
    subprocess.run(['useradd', '--system', '--create-home', '--home-dir', '/var/lib/'+USER, '--shell', '/usr/sbin/nologin', USER], check=True)
ROOT.mkdir(parents=True, exist_ok=True)
(ROOT/'.d1-staging-runtime').touch()
archive=Path('/tmp/familyfunds-d1-application.tar.gz')
with tarfile.open(archive) as bundle:
    for member in bundle.getmembers():
        if member.name.startswith('/') or '..' in Path(member.name).parts:
            raise RuntimeError('Unsafe archive path')
    bundle.extractall(ROOT, filter='data')
private = ROOT/'storage/app/private/d1-staging'
for relative in ['storage/app/private/d1-staging','storage/app/public','storage/framework/cache/data','storage/framework/sessions','storage/framework/views','storage/logs','bootstrap/cache']:
    (ROOT/relative).mkdir(parents=True,exist_ok=True)
secret_path=Path('/tmp/familyfunds-d1-staging-secrets.json')
secret_path.chmod(0o600)
secrets=json.loads(secret_path.read_text())
(private/'staging-secrets.json').write_text(json.dumps(secrets))
(private/'staging-secrets.json').chmod(0o600)
env={
 'APP_NAME':'FamilyFunds D1 Staging','APP_ENV':'d1-staging','APP_KEY':secrets['app_key'],'APP_DEBUG':'false','APP_URL':'https://'+DOMAIN,
 'D1_STAGING_ENABLED':'true','D1_STAGING_DATABASE':'familyfunds-d1-web-staging','D1_STAGING_URL':'https://familyfunds-d1-staging-sql.familyfunds-lab.workers.dev','D1_STAGING_TOKEN':secrets['sql_token'],
 'DB_CONNECTION':'d1-staging','SESSION_DRIVER':'file','SESSION_LIFETIME':'120','SESSION_ENCRYPT':'false','SESSION_SECURE_COOKIE':'true','SESSION_COOKIE':'familyfunds_d1_staging_session',
 'CACHE_STORE':'file','CACHE_PREFIX':'familyfunds_d1_staging','QUEUE_CONNECTION':'d1-staging','MAIL_MAILER':'array','LOG_CHANNEL':'single','LOG_LEVEL':'warning','NIGHTWATCH_ENABLED':'false','INERTIA_SSR_ENABLED':'false','BROADCAST_CONNECTION':'null',
 'PAYSTACK_SECRET_KEY':'','PAYSTACK_PUBLIC_KEY':'','RESEND_KEY':'','VAPID_PUBLIC_KEY':'','VAPID_PRIVATE_KEY':'','TELESCOPE_ENABLED':'false','PULSE_ENABLED':'false'
}
(ROOT/'.env').write_text('\n'.join(key+'='+json.dumps(value) for key,value in env.items())+'\n')
(ROOT/'.env').chmod(0o600)
subprocess.run(['chown','-R',USER+':'+USER,str(ROOT)],check=True)
subprocess.run(['chmod','-R','u+rwX,go+rX',str(ROOT/'public')],check=True)
subprocess.run(['runuser','-u',USER,'--','php',str(ROOT/'artisan'),'package:discover','--no-interaction'],check=True,cwd=ROOT,stdout=subprocess.DEVNULL)
password_hash=subprocess.check_output(['php','-r','echo password_hash(stream_get_contents(STDIN), PASSWORD_BCRYPT);'],input=secrets['basic_password'].encode()).decode()
auth=Path('/etc/nginx/familyfunds-d1-staging.htpasswd');auth.write_text(secrets['basic_user']+':'+password_hash+'\n');auth.chmod(0o640)
subprocess.run(['chown','root:www-data',str(auth)],check=True)
fpm=Path('/etc/php/8.4/fpm/d1-staging.conf')
fpm.write_text('''[global]
pid = /run/php/php8.4-d1-staging.pid
error_log = /var/log/php8.4-d1-staging.log
daemonize = no
[familyfunds-d1-staging]
user = familyfunds-d1
group = familyfunds-d1
listen = /run/php/php8.4-d1-staging.sock
listen.owner = www-data
listen.group = www-data
listen.mode = 0660
pm = ondemand
pm.max_children = 2
pm.process_idle_timeout = 20s
pm.max_requests = 50
clear_env = yes
catch_workers_output = yes
request_terminate_timeout = 45s
php_admin_value[memory_limit] = 256M
php_admin_value[max_execution_time] = 30
php_admin_flag[display_errors] = off
''')
unit=Path('/etc/systemd/system/familyfunds-d1-staging.service')
unit.write_text('''[Unit]
Description=Isolated FamilyFunds D1 staging PHP runtime
After=network.target
[Service]
Type=simple
ExecStart=/usr/sbin/php-fpm8.4 -d extension=/opt/familyfunds-d1-php/usr/lib/php/20240924/pdo_sqlite.so -d extension=/opt/familyfunds-d1-php/usr/lib/php/20240924/sqlite3.so --nodaemonize --fpm-config /etc/php/8.4/fpm/d1-staging.conf
Restart=on-failure
MemoryHigh=512M
MemoryMax=640M
CPUQuota=50%
TasksMax=32
Nice=10
[Install]
WantedBy=multi-user.target
''')
subprocess.run(['/usr/sbin/php-fpm8.4','--test','--fpm-config',str(fpm)],check=True)
subprocess.run(['systemctl','daemon-reload'],check=True)
subprocess.run(['systemctl','enable','--now','familyfunds-d1-staging'],check=True,stdout=subprocess.DEVNULL)
site=Path('/etc/nginx/sites-available/d1-staging.familyfunds.app.conf')
if not Path('/etc/letsencrypt/live/'+DOMAIN+'/fullchain.pem').exists():
 site.write_text('server { listen 80; listen [::]:80; server_name '+DOMAIN+'; root '+str(ROOT)+'/public; location ^~ /.well-known/acme-challenge/ { try_files $uri =404; } location / { return 404; } }\n')
link=Path('/etc/nginx/sites-enabled')/site.name
if not link.exists():link.symlink_to(site)
subprocess.run(['nginx','-t'],check=True)
subprocess.run(['systemctl','reload','nginx'],check=True)
print(json.dumps({'root':str(ROOT),'service':'familyfunds-d1-staging','production_fpm_reloaded':False,'http_challenge_ready':True}))
