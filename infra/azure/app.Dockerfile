# BizTrack API image for the Azure server (biztrack.page). One image runs three
# containers: php-fpm (app), the queue worker and the scheduler.
#
# Differs from infra/php/Dockerfile in what production on Azure needs:
#   - gd, so dompdf can place the City seal and other PNGs in printed PDFs
#   - pcntl, so the queue worker stops cleanly between jobs on a redeploy
#   - postgresql16-client, so the nightly `backup:run` can call pg_dump
#     against Azure Database for PostgreSQL (same major version as the server)
#   - an entrypoint that caches config/routes/views at start, when the
#     environment (env_file) is finally present
FROM php:8.4-fpm-alpine

RUN apk add --no-cache su-exec postgresql16-client libpq libzip icu-libs libpng libjpeg-turbo freetype \
    && apk add --no-cache --virtual .build-deps postgresql-dev libzip-dev icu-dev oniguruma-dev \
        libpng-dev libjpeg-turbo-dev freetype-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql zip intl bcmath opcache gd pcntl \
    && apk del .build-deps

COPY infra/azure/php.ini /usr/local/etc/php/conf.d/biztrack.ini
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/api
COPY api/composer.json api/composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist
COPY api/ ./
# The zoning data the API reads at run time from beside itself —
# base_path('../docs/zoning-ordinance') and base_path('../web/public/zoning') —
# at those paths. Copying api/ alone left them out, and on biztrack.page every
# trade came back "not on the list" (zoning-check row 42, 5 October 2026).
# AppImageDataTest fails when the code reads a file outside api/ that no
# COPY here puts in the image.
COPY docs/zoning-ordinance/ /var/www/docs/zoning-ordinance/
COPY web/public/zoning/ /var/www/web/public/zoning/
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative \
    && php artisan package:discover --ansi \
    && chown -R www-data:www-data storage bootstrap/cache

COPY infra/azure/entrypoint.sh /usr/local/bin/biztrack-entrypoint
RUN chmod +x /usr/local/bin/biztrack-entrypoint

ENTRYPOINT ["biztrack-entrypoint"]
EXPOSE 9000
CMD ["php-fpm"]
