#!/bin/bash
set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/contribution-tracker-staging}"
STAGING_BRANCH="${STAGING_BRANCH:-codex/staging}"

cd "$APP_DIR"

git config --global --add safe.directory "$APP_DIR"

if [ -n "$(git status --porcelain --untracked-files=no)" ]; then
    echo "Staging checkout has local tracked changes. Refusing to deploy over them."
    git status --short
    exit 1
fi

git fetch origin "$STAGING_BRANCH"
git merge --ff-only "origin/$STAGING_BRANCH"
echo "Deploying $(git rev-parse --short HEAD) to staging"

composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
npm ci

php artisan migrate --force
php artisan optimize:clear
rm -rf resources/js/actions resources/js/routes resources/js/wayfinder
php artisan wayfinder:generate
npm run build

php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

sudo -n cp "$APP_DIR/deployment/nginx/staging.familyfunds.app.conf" /etc/nginx/sites-available/staging.familyfunds.app.conf
sudo -n nginx -t
sudo -n systemctl reload nginx

sudo -n supervisorctl restart queue-worker-staging
sudo -n supervisorctl restart ssr-staging
sudo -n systemctl reload php8.4-fpm

echo "Staging deployment complete."
