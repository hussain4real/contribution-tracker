"""Root-only source freeze and pre-write rollback for the approved cutover."""
import hashlib
import json
import os
from pathlib import Path
import subprocess
import sys

ROOT = Path('/root/familyfunds-production-cutover-20261027')
SITE = Path('/etc/nginx/sites-available/familyfunds.app.conf')
APP = '/var/www/contribution-tracker'
BASELINE = '936df5a4bb7e9591b765823e17ddb877474ccec2dcc9071cd4f85ca60bef361e'
CRON = '* * * * * cd /var/www/contribution-tracker && php8.4 artisan schedule:run >> /dev/null 2>&1'


def run(args, data=None):
    result = subprocess.run(args, input=data, capture_output=True, timeout=90)
    if result.returncode != 0:
        raise RuntimeError('Source operation failed: ' + args[0])
    return result.stdout


def pg(sql):
    return run(['runuser', '-u', 'postgres', '--', 'psql', '-d', 'contribution_tracker', '-At', '-v', 'ON_ERROR_STOP=1', '-c', sql])


def read_scheduler():
    return run(['crontab', '-u', 'deployer', '-l'])


def rollback():
    if (ROOT / 'd1-writers-armed').exists():
        raise RuntimeError('D1 writers were armed; delta reconciliation is required')
    pg('ALTER ROLE app_user IN DATABASE contribution_tracker RESET default_transaction_read_only;')
    original = (ROOT / 'nginx.original').read_bytes()
    if hashlib.sha256(original).hexdigest() != BASELINE:
        raise RuntimeError('Saved Nginx baseline changed')
    SITE.write_bytes(original)
    run(['nginx', '-t'])
    run(['systemctl', 'reload', 'nginx'])
    run(['runuser', '-u', 'deployer', '--', 'php8.4', APP + '/artisan', 'up', '--no-interaction'])
    process = subprocess.run(['supervisorctl', 'status', 'queue-worker:*'], capture_output=True, timeout=30)
    if process.returncode not in [0, 3]:
        raise RuntimeError('Could not inspect source queue workers')
    status = process.stdout.decode()
    for line in status.splitlines():
        parts = line.split()
        if len(parts) > 1 and parts[1] != 'RUNNING':
            run(['supervisorctl', 'start', parts[0]])
    run(['crontab', '-u', 'deployer', '-'], (ROOT / 'crontab.original').read_bytes())
    (ROOT / 'rolled-back').write_text('PostgreSQL source restored before D1 writers were armed\n')
    return {'status': 'rolled-back', 'nginx_sha256': BASELINE, 'source': 'PostgreSQL'}


def freeze():
    if ROOT.exists() or hashlib.sha256(SITE.read_bytes()).hexdigest() != BASELINE:
        raise RuntimeError('Fresh root directory and unchanged Nginx are required')
    original = SITE.read_bytes()
    cron = read_scheduler()
    if cron.decode().splitlines().count(CRON) != 1:
        raise RuntimeError('Unexpected production scheduler definition')
    settings = pg("SELECT count(*) FROM pg_db_role_setting WHERE setrole=(SELECT oid FROM pg_roles WHERE rolname='app_user') AND setdatabase=(SELECT oid FROM pg_database WHERE datname='contribution_tracker');").strip()
    if settings != b'0':
        raise RuntimeError('Existing database-specific role settings require review')
    os.umask(0o077)
    ROOT.mkdir(mode=0o700)
    (ROOT / 'nginx.original').write_bytes(original)
    (ROOT / 'crontab.original').write_bytes(cron)
    try:
        paused = '\n'.join(line for line in cron.decode().splitlines() if line != CRON) + '\n'
        run(['crontab', '-u', 'deployer', '-'], paused.encode())
        run(['supervisorctl', 'stop', 'queue-worker:*'])
        run(['runuser', '-u', 'deployer', '--', 'php8.4', APP + '/artisan', 'down', '--retry=60', '--no-interaction'])
        marker = b'    root /var/www/contribution-tracker/public;'
        if original.count(marker) != 1:
            raise RuntimeError('Unexpected source root')
        SITE.write_bytes(original.replace(marker, marker + b'\n    return 503;\n    add_header Retry-After "60" always;'))
        run(['nginx', '-t'])
        run(['systemctl', 'reload', 'nginx'])
        pg('ALTER ROLE app_user IN DATABASE contribution_tracker SET default_transaction_read_only=on;')
        pg("SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname='contribution_tracker' AND usename='app_user' AND pid<>pg_backend_pid();")
        jobs = pg('SELECT (SELECT count(*) FROM jobs),(SELECT count(*) FROM failed_jobs),(SELECT count(*) FROM jobs WHERE reserved_at IS NOT NULL);').decode().strip()
        if jobs != '0|0|0':
            raise RuntimeError('Source queue is not empty at the freeze boundary')
        with (ROOT / 'source-frozen.pgcustom').open('xb') as output:
            result = subprocess.run(['runuser', '-u', 'postgres', '--', 'pg_dump', '-Fc', 'contribution_tracker'], stdout=output, stderr=subprocess.PIPE, timeout=90)
            if result.returncode != 0:
                raise RuntimeError('Frozen PostgreSQL backup failed')
        listing = run(['pg_restore', '--list', str(ROOT / 'source-frozen.pgcustom')])
        result = {'status': 'frozen', 'jobs': 0, 'failed_jobs': 0, 'reserved_jobs': 0,
                  'backup_bytes': (ROOT / 'source-frozen.pgcustom').stat().st_size,
                  'backup_sha256': hashlib.sha256((ROOT / 'source-frozen.pgcustom').read_bytes()).hexdigest(),
                  'backup_catalog_entries': len(listing.splitlines()), 'nginx_original_sha256': BASELINE,
                  'postgres_role_default_read_only': True, 'source_scheduler_paused': True, 'source_queue_workers_stopped': True}
        (ROOT / 'freeze-result.json').write_text(json.dumps(result, indent=2) + '\n')
        return result
    except Exception:
        rollback()
        raise


if __name__ == '__main__':
    if os.geteuid() != 0 or sys.argv[1:] not in [['freeze-approved'], ['rollback-before-d1-writes']]:
        raise SystemExit('Explicit approved operation and root are required')
    try:
        print(json.dumps(freeze() if sys.argv[1] == 'freeze-approved' else rollback()))
    except Exception as error:
        print(json.dumps({'status': 'failed', 'reason': str(error)}))
        raise SystemExit(1)
