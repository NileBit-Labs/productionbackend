#!/bin/sh
set -eu

: "${PORT:=10000}"
envsubst '${PORT}' < /etc/nginx/templates/default.conf.template > /etc/nginx/conf.d/default.conf

if [ "${RUN_MIGRATIONS_ON_BOOT:-false}" = "true" ]; then
    php artisan migrate --force --no-interaction
fi

php-fpm -F &
exec nginx -g 'daemon off;'
