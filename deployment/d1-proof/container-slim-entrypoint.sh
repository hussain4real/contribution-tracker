#!/bin/sh
set -eu

QUEUE_WORKER_ENABLED="${QUEUE_WORKER_ENABLED:-false}"
case "$QUEUE_WORKER_ENABLED" in
    true|false) ;;
    *) echo 'Invalid queue worker activation flag' >&2; exit 64 ;;
esac
export QUEUE_WORKER_ENABLED

case "${FPM_PM_MAX_CHILDREN:-16}" in
    1|2|3|4|5|6|7|8|9|10|11|12|13|14|15|16|17|18|19|20) ;;
    *) echo 'Invalid staging PHP concurrency' >&2; exit 64 ;;
esac
mkdir -p /run/nginx /run/php
if [ "${D1_PRODUCTION_ENABLED:-false}" = "true" ]; then
    test -f /etc/cloudflare/certs/cloudflare-containers-ca.crt
    cp /etc/cloudflare/certs/cloudflare-containers-ca.crt /usr/local/share/ca-certificates/familyfunds-container-outbound.crt
    update-ca-certificates >/dev/null 2>&1
fi
if [ -n "${FF_RCLONE_CONFIG:-}${R2_BACKUP_ACCESS_KEY_ID:-}${R2_BACKUP_SECRET_ACCESS_KEY:-}${BACKUP_R2_ACCESS_KEY_ID:-}${BACKUP_R2_SECRET_ACCESS_KEY:-}" ]; then
    echo 'Offsite credentials are forbidden in the application container' >&2
    exit 64
fi
mark_startup() {
    if [ "${FF_STARTUP_PROFILE:-}" = "1" ]; then
        FF_PROFILE_PHASE="$1" php -r '$p="/run/familyfunds-startup.json";$d=is_file($p)?json_decode(file_get_contents($p),true):[];$d[getenv("FF_PROFILE_PHASE")]=microtime(true)*1000;file_put_contents($p,json_encode($d));chmod($p,0600);'
    fi
}
mark_startup config_start_ms
php artisan config:cache --no-interaction >/dev/null
php artisan route:cache --no-interaction >/dev/null
mark_startup config_ready_ms
exec /usr/bin/supervisord -c /etc/supervisord.conf
