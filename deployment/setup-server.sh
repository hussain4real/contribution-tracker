#!/bin/bash
set -euo pipefail

# =============================================================================
# Hetzner CX23 Server Setup for familyfunds.app
# Run as root on a fresh Ubuntu 24.04 LTS server
# =============================================================================

echo "=== Step 1: System Update ==="
apt update && apt upgrade -y

echo "=== Step 2: Create deployer user ==="
adduser --disabled-password --gecos "" deployer
usermod -aG sudo deployer

# Copy root SSH keys to deployer
mkdir -p /home/deployer/.ssh
cp /root/.ssh/authorized_keys /home/deployer/.ssh/authorized_keys
chown -R deployer:deployer /home/deployer/.ssh
chmod 700 /home/deployer/.ssh
chmod 600 /home/deployer/.ssh/authorized_keys

# Allow deployer to run specific commands without password (for CI/CD)
cat > /etc/sudoers.d/deployer << 'EOF'
deployer ALL=(ALL) NOPASSWD: /usr/bin/supervisorctl restart queue-worker
deployer ALL=(ALL) NOPASSWD: /usr/bin/systemctl reload php8.4-fpm
EOF

echo "=== Step 3: Configure Firewall ==="
ufw default deny incoming
ufw default allow outgoing
ufw allow 22/tcp
ufw allow 80/tcp
ufw allow 443/tcp
ufw --force enable

echo "=== Step 4: Install fail2ban ==="
apt install -y fail2ban
systemctl enable fail2ban
systemctl start fail2ban

echo "=== Step 5: Disable password SSH login ==="
sed -i 's/#PasswordAuthentication yes/PasswordAuthentication no/' /etc/ssh/sshd_config
sed -i 's/PasswordAuthentication yes/PasswordAuthentication no/' /etc/ssh/sshd_config
systemctl reload sshd

echo "=== Step 6: Install Nginx ==="
apt install -y nginx
systemctl enable nginx

echo "=== Step 7: Install PHP 8.4 ==="
apt install -y software-properties-common
add-apt-repository -y ppa:ondrej/php
apt update
apt install -y \
    php8.4-fpm \
    php8.4-cli \
    php8.4-pgsql \
    php8.4-mbstring \
    php8.4-xml \
    php8.4-curl \
    php8.4-zip \
    php8.4-bcmath \
    php8.4-gd \
    php8.4-intl \
    php8.4-readline \
    php8.4-tokenizer

# Tune PHP-FPM for 4GB RAM server
sed -i 's/pm.max_children = .*/pm.max_children = 20/' /etc/php/8.4/fpm/pool.d/www.conf
sed -i 's/pm.start_servers = .*/pm.start_servers = 4/' /etc/php/8.4/fpm/pool.d/www.conf
sed -i 's/pm.min_spare_servers = .*/pm.min_spare_servers = 2/' /etc/php/8.4/fpm/pool.d/www.conf
sed -i 's/pm.max_spare_servers = .*/pm.max_spare_servers = 6/' /etc/php/8.4/fpm/pool.d/www.conf

# Set upload limits
sed -i 's/upload_max_filesize = .*/upload_max_filesize = 10M/' /etc/php/8.4/fpm/php.ini
sed -i 's/post_max_size = .*/post_max_size = 12M/' /etc/php/8.4/fpm/php.ini

systemctl enable php8.4-fpm
systemctl restart php8.4-fpm

echo "=== Step 8: Install PostgreSQL 16 ==="
apt install -y postgresql postgresql-contrib
systemctl enable postgresql

# Create database and user
sudo -u postgres psql -c "CREATE USER app_user WITH PASSWORD 'jBc1Tijza2a2d9jb4HYAi1K1J5vblCH';"
sudo -u postgres psql -c "CREATE DATABASE contribution_tracker OWNER app_user;"
sudo -u postgres psql -c "GRANT ALL PRIVILEGES ON DATABASE contribution_tracker TO app_user;"

echo "=== Step 9: Install Node.js 22 ==="
curl -fsSL https://deb.nodesource.com/setup_22.x | bash -
apt install -y nodejs

echo "=== Step 10: Install Composer ==="
curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

echo "=== Step 11: Install Supervisor ==="
apt install -y supervisor
systemctl enable supervisor

echo "=== Step 12: Install Certbot ==="
apt install -y certbot python3-certbot-nginx

echo "=== Step 13: Install Git ==="
apt install -y git

echo "=== Step 14: Prepare application directory ==="
mkdir -p /var/www/contribution-tracker
chown deployer:www-data /var/www/contribution-tracker
chmod 775 /var/www/contribution-tracker

echo "============================================="
echo "Server setup complete!"
echo ""
echo "NEXT STEPS (as deployer user):"
echo "1. Change the PostgreSQL password in this script before running!"
echo "2. SSH as deployer: ssh deployer@<server-ip>"
echo "3. Clone repo: cd /var/www && git clone git@github.com:hussain4real/contribution-tracker.git"
echo "4. Copy Nginx config: sudo cp deployment/nginx/familyfunds.app.conf /etc/nginx/sites-available/"
echo "5. Enable site: sudo ln -s /etc/nginx/sites-available/familyfunds.app.conf /etc/nginx/sites-enabled/"
echo "6. Remove default: sudo rm /etc/nginx/sites-enabled/default"
echo "7. Copy Supervisor config: sudo cp deployment/supervisor/queue-worker.conf /etc/supervisor/conf.d/"
echo "8. Get SSL cert: sudo certbot --nginx -d familyfunds.app"
echo "9. Set up .env file with production values"
echo "10. Run: composer install --no-dev --optimize-autoloader"
echo "11. Run: npm ci && npm run build"
echo "12. Run: php artisan migrate --force"
echo "13. Run: php artisan config:cache && php artisan route:cache && php artisan view:cache"
echo "14. Run: php artisan storage:link"
echo "15. Start supervisor: sudo supervisorctl reread && sudo supervisorctl update"
echo "16. Add cron: crontab -e -> * * * * * cd /var/www/contribution-tracker && php artisan schedule:run >> /dev/null 2>&1"
echo "17. Test Nginx config: sudo nginx -t && sudo systemctl reload nginx"
echo "============================================="
