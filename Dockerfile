FROM php:8.4-fpm-bookworm AS vendor
RUN apt-get update && apt-get install -y --no-install-recommends libpq-dev libzip-dev unzip \
    && docker-php-ext-install pdo_pgsql zip \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts

FROM php:8.4-fpm-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends nginx gettext-base libpq-dev libzip-dev unzip \
    && docker-php-ext-install pdo_pgsql zip \
    && rm -rf /var/lib/apt/lists/*
WORKDIR /var/www/html
COPY --from=vendor /app/vendor ./vendor
COPY . .
COPY docker/nginx/default.conf.template /etc/nginx/templates/default.conf.template
COPY docker/entrypoint.sh /usr/local/bin/nilebit-entrypoint
RUN rm -f /etc/nginx/sites-enabled/default \
    && chmod +x /usr/local/bin/nilebit-entrypoint \
    && chown -R www-data:www-data storage bootstrap/cache
EXPOSE 10000
ENTRYPOINT ["/usr/local/bin/nilebit-entrypoint"]
