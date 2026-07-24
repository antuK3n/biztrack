# BizTrack API image — php-fpm with the extensions Laravel + Postgres need.
FROM php:8.4-fpm-alpine

RUN apk add --no-cache postgresql-dev libzip-dev icu-dev oniguruma-dev \
    && docker-php-ext-install pdo_pgsql zip intl bcmath opcache

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/api
COPY api/ ./
RUN composer install --no-dev --optimize-autoloader --no-interaction \
    && php artisan config:clear || true

# storage is a volume; ensure the runtime user can write
RUN chown -R www-data:www-data storage bootstrap/cache

EXPOSE 9000
CMD ["php-fpm"]
