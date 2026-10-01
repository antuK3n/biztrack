#!/bin/sh
# Start-up for the app, queue and scheduler containers.
#
# Config, routes and views are cached here rather than at build time because
# the settings (api/.env.production, passed as env_file) only exist once the
# container runs. Each container caches its own copy. Migrations are NOT run
# here: on a live register they are a person's decision (AGENTS.md §2.2), run
# by hand with `docker compose run --rm app php artisan migrate --force`.
set -e
cd /var/www/api

# Storage is a volume that starts empty on a new server; the framework needs
# these folders.
mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views \
         storage/logs storage/app/private storage/app/public

if [ "${APP_ENV:-production}" = "production" ]; then
    php artisan optimize --quiet
fi

# Everything the app writes must belong to www-data, the user php-fpm serves
# as. A log file first created by root (the queue, say) would otherwise be
# unwritable by the website, and every request would 500.
chown -R www-data:www-data storage bootstrap/cache

# php-fpm's master process starts as root and drops its workers to www-data
# itself. Anything else (queue, scheduler, a one-off artisan command) runs as
# www-data from the start.
if [ "$1" = "php-fpm" ]; then
    exec "$@"
fi
exec su-exec www-data "$@"
