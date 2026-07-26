#!/usr/bin/env bash

set -euo pipefail

if [ ! -f .env ]; then
    cp .env.example .env
fi

mkdir -p database
touch database/database.sqlite

composer install --no-interaction --prefer-dist --optimize-autoloader
npm ci

if ! grep -Eq '^APP_KEY=base64:.+' .env; then
    php artisan key:generate --force --no-interaction
fi

php artisan optimize:clear --no-interaction
php artisan migrate --force --no-interaction
php artisan wayfinder:generate --no-interaction
npm run build
