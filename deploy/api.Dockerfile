# Production image of the Choronai API: PHP-FPM with the PostgreSQL driver
# and OPcache. Caddy (see Caddyfile) sends API requests to it on port 9000.
FROM php:8.4-fpm

RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev libzip-dev libicu-dev unzip \
    && docker-php-ext-install pdo_pgsql bcmath intl zip opcache \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY deploy/php.ini /usr/local/etc/php/conf.d/choronai.ini
COPY deploy/fpm.conf /usr/local/etc/php-fpm.d/zz-choronai.conf

WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist
COPY . .
RUN composer dump-autoload --optimize --no-dev \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

# Settings come from the environment (.env.production), so caches are built
# when the container starts, not into the image.
CMD ["sh", "-c", "php artisan config:cache && php artisan route:cache && exec php-fpm"]
