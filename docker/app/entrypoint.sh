#!/bin/sh
set -e

# Wait for the database before anything that touches it.
if [ -n "${DB_HOST}" ]; then
  echo "waiting for ${DB_HOST}:${DB_PORT:-3306} ..."
  until php -r "new PDO('mysql:host=${DB_HOST};port=${DB_PORT:-3306}', '${DB_USERNAME}', '${DB_PASSWORD}');" 2>/dev/null; do
    sleep 1
  done
fi

# Storage directories exist and are writable in a fresh checkout.
mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache

exec "$@"
