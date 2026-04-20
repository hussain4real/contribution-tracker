#!/bin/bash
set -euo pipefail

# =============================================================================
# Manual deployment script for familyfunds.app
# Run as deployer user from /var/www/contribution-tracker
# Use this for manual deployments or debugging. CI/CD uses GitHub Actions.
# =============================================================================

APP_DIR="/var/www/contribution-tracker"

cd "$APP_DIR"

echo "Pulling latest changes..."
git pull origin main

echo "Installing PHP dependencies..."
composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

echo "Installing Node dependencies and building assets..."
npm ci
npm run build

echo "Running migrations..."
php artisan migrate --force

echo "Caching configuration..."
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

echo "Restarting queue workers, SSR, and Nightwatch..."
sudo supervisorctl restart queue-worker:*
sudo supervisorctl restart ssr
sudo supervisorctl restart nightwatch

echo "Reloading PHP-FPM..."
sudo systemctl reload php8.4-fpm

echo "Deployment complete!"
