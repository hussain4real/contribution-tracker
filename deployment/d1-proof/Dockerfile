FROM webdevops/php-apache:8.4@sha256:d140d812f5685a1b708632d8b39ebe8a9137acf67dd56dd0e08839abf6b3e21e
WORKDIR /var/www/html
COPY app ./app
COPY bootstrap ./bootstrap
COPY config ./config
COPY database ./database
COPY deployment/d1-proof ./deployment/d1-proof
COPY public ./public
COPY resources ./resources
COPY routes ./routes
COPY vendor ./vendor
COPY artisan composer.json composer.lock ./
RUN printf '\nMutex file:/run/lock default\n' >> /etc/apache2/apache2.conf && printf '\npm.max_spare_servers = 2\npm.start_servers = 1\n' >> /opt/docker/etc/php/fpm/pool.d/application.conf && rm -f /opt/docker/etc/supervisor.d/cron.conf /opt/docker/etc/supervisor.d/dnsmasq.conf /opt/docker/etc/supervisor.d/postfix.conf /opt/docker/etc/supervisor.d/ssh.conf /opt/docker/etc/supervisor.d/syslog.conf && printf ready > public/__container_ready && rm -rf bootstrap/cache/*.php public/hot public/storage deployment/d1-proof/__pycache__ && mkdir -p storage/app/private storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache && chown -R application:application storage bootstrap/cache
ENV APP_ENV=d1-staging APP_DEBUG=false APP_NAME="FamilyFunds D1 Staging" APP_URL=https://d1-staging.familyfunds.app D1_STAGING_ENABLED=true D1_STAGING_DATABASE=familyfunds-d1-web-staging D1_STAGING_URL=https://familyfunds-d1-staging-sql.familyfunds-lab.workers.dev DB_CONNECTION=d1-staging SESSION_DRIVER=file SESSION_SECURE_COOKIE=true CACHE_STORE=file MAIL_MAILER=array QUEUE_CONNECTION=d1-staging NIGHTWATCH_ENABLED=false INERTIA_SSR_ENABLED=false GITHUB_RELEASES_OWNER="" GITHUB_RELEASES_REPO="" GITHUB_RELEASES_TOKEN=""
ENV WEB_DOCUMENT_ROOT=/var/www/html/public WEB_DOCUMENT_INDEX=index.php PHP_MEMORY_LIMIT=256M FPM_PM_MAX_CHILDREN=2 SERVICE_POSTFIX_OPTS=""
RUN chmod 755 deployment/d1-proof/container-entrypoint.sh
ENV LOG_CHANNEL=stderr
ENTRYPOINT ["/var/www/html/deployment/d1-proof/container-entrypoint.sh"]
RUN cp deployment/d1-proof/container-queue.conf /opt/docker/etc/supervisor.d/familyfunds-queue.conf
RUN cp deployment/d1-proof/container-scheduler.conf /opt/docker/etc/supervisor.d/familyfunds-synthetic-scheduler.conf
CMD ["supervisord"]
EXPOSE 80
