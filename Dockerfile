FROM composer:2 AS vendor

WORKDIR /app

COPY fichaje-backend/composer.json fichaje-backend/composer.lock ./
RUN composer install \
    --no-dev \
    --prefer-dist \
    --no-interaction \
    --no-scripts

COPY fichaje-backend/ ./
RUN composer install \
    --no-dev \
    --prefer-dist \
    --no-interaction \
    --optimize-autoloader

FROM php:8.2-apache

WORKDIR /var/www/html

RUN apt-get update \
    && apt-get install -y --no-install-recommends libonig-dev libxml2-dev \
    && docker-php-ext-install pdo_mysql mbstring xml \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

COPY fichaje-backend/docker/apache-vhost.conf /etc/apache2/sites-available/000-default.conf
COPY fichaje-backend/docker/entrypoint.sh /usr/local/bin/backend-entrypoint

COPY --from=vendor /app /var/www/html

RUN chmod +x /usr/local/bin/backend-entrypoint \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

ENTRYPOINT ["backend-entrypoint"]
CMD ["apache2-foreground"]
