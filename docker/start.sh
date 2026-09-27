#!/bin/sh
# Container entrypoint: migrate, bootstrap the first admin once, cache config, serve.
set -e
cd /var/www/html

if [ -z "$APP_KEY" ]; then
  echo "APP_KEY is not set. Generate one with: php artisan key:generate --show" >&2
  exit 1
fi

php artisan migrate --force

# First deploy only: creates the system admin if no user exists yet.
if [ -n "$INITIAL_ADMIN_EMAIL" ] && [ -n "$INITIAL_ADMIN_PASSWORD" ]; then
  php artisan rroka:create-admin "$INITIAL_ADMIN_EMAIL" "${INITIAL_ADMIN_NAME:-مدير النظام}" --if-none
fi

php artisan config:cache
php artisan route:cache
php artisan view:cache

exec frankenphp php-server --root public --listen ":${PORT:-8080}"
