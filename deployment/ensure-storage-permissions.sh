#!/bin/bash
set -euo pipefail

# Keep Laravel's writable paths usable by both PHP-FPM (www-data) and the
# deployer-owned scheduler, including backup commands that traverse storage.
APP_DIR="${1:-/var/www/contribution-tracker}"
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

sudo /usr/bin/chown -R deployer:www-data "${WRITABLE_PATHS[@]}"
sudo /usr/bin/find "${WRITABLE_PATHS[@]}" -type d -exec /usr/bin/chmod 2775 {} +
sudo /usr/bin/find "${WRITABLE_PATHS[@]}" -type f -exec /usr/bin/chmod 0664 {} +

# Passport's private key must remain owner-writable and group-readable. The
# broad storage normalization above would otherwise make Passport reject the
# key during authorization requests.
if [[ -f "$APP_DIR/storage/oauth-private.key" ]]; then
    /usr/bin/chmod 0640 "$APP_DIR/storage/oauth-private.key"
fi

# Report generation creates this directory on demand. Creating it here also
# makes its ownership explicit before the backup scheduler traverses storage.
sudo /usr/bin/install -d -o deployer -g www-data -m 2775 "$APP_DIR/storage/app/private/reports"
