# BizTrack API image — php-fpm with the extensions Laravel + Postgres need.
FROM php:8.4-fpm-alpine

RUN apk add --no-cache postgresql-dev libzip-dev icu-dev oniguruma-dev \
    && docker-php-ext-install pdo_pgsql zip intl bcmath opcache

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/api
COPY api/ ./
# The zoning data the API reads at run time from beside itself —
# base_path('../docs/zoning-ordinance') and base_path('../web/public/zoning') —
# at those paths. Copying api/ alone left them out, and on biztrack.page every
# trade came back "not on the list" (zoning-check row 42, 5 October 2026).
# AppImageDataTest fails when the code reads a file outside api/ that no
# COPY here puts in the image.
COPY docs/zoning-ordinance/ /var/www/docs/zoning-ordinance/
COPY web/public/zoning/ /var/www/web/public/zoning/
RUN composer install --no-dev --optimize-autoloader --no-interaction \
    && php artisan config:clear || true

# storage is a volume; ensure the runtime user can write
RUN chown -R www-data:www-data storage bootstrap/cache

EXPOSE 9000
CMD ["php-fpm"]
