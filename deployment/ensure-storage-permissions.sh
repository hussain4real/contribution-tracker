#!/bin/bash
set -euo pipefail

# Keep Laravel's writable paths usable by PHP-FPM and the deployer scheduler.
# Report ownership and ACLs are managed separately and must survive deployment.
APP_DIR="${1:-/var/www/contribution-tracker}"
REPORTS_DIR="$APP_DIR/storage/app/private/reports"
WRITABLE_PATHS=(
    "$APP_DIR/storage"
    "$APP_DIR/bootstrap/cache"
)

for path in "${WRITABLE_PATHS[@]}"; do
    if [[ ! -d "$path" ]]; then
        echo "Writable path does not exist: $path" >&2
        exit 1
    fi
done

sudo /usr/bin/find "${WRITABLE_PATHS[@]}" -path "$REPORTS_DIR" -prune -o -exec /usr/bin/chown -h deployer:www-data {} +

# Lock down both Passport keys before traversing storage, then exclude them
# from file normalization so PHP-FPM remains read-only throughout.
if [[ -f "$APP_DIR/storage/oauth-private.key" ]]; then
    /usr/bin/chmod 0640 "$APP_DIR/storage/oauth-private.key"
fi

if [[ -f "$APP_DIR/storage/oauth-public.key" ]]; then
    /usr/bin/chmod 0640 "$APP_DIR/storage/oauth-public.key"
fi

sudo /usr/bin/find "${WRITABLE_PATHS[@]}" -path "$REPORTS_DIR" -prune -o -type d -exec /usr/bin/chmod 2775 {} +
sudo /usr/bin/find "${WRITABLE_PATHS[@]}" -path "$REPORTS_DIR" -prune -o -path "$APP_DIR/storage/oauth-private.key" -prune -o -path "$APP_DIR/storage/oauth-public.key" -prune -o -type f -exec /usr/bin/chmod 0664 {} +

# Bootstrap only a missing report parent; never normalize an existing ACL.
if [[ ! -e "$REPORTS_DIR" && ! -L "$REPORTS_DIR" ]]; then
    sudo /usr/bin/install -d -o deployer -g www-data -m 2775 "$REPORTS_DIR"
fi
