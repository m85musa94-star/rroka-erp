# Production image: FrankenPHP (web server + PHP in one process) serving the Laravel app.
# Base image already ships pdo_pgsql, opcache, zip and composer — no apt/pecl downloads needed.
# Build from the repository root:  docker build -t rroka-erp .
FROM serversideup/php:8.4-frankenphp

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    PHP_OPCACHE_ENABLE=1 \
    PHP_DATE_TIMEZONE=Asia/Riyadh

USER root
COPY docker/start.sh /usr/local/bin/rroka-start
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-rroka.ini
RUN chmod 755 /usr/local/bin/rroka-start

USER www-data
WORKDIR /var/www/html
COPY --chown=www-data:www-data backend/composer.json backend/composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --no-progress

COPY --chown=www-data:www-data backend/ ./

RUN composer dump-autoload --optimize --no-dev \
    && php artisan package:discover \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache

EXPOSE 8080
ENTRYPOINT []
CMD ["rroka-start"]
