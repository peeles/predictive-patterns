# docker/backend/Dockerfile
FROM php:8.3-fpm

# System deps
RUN apt-get update && apt-get install -y \
    git unzip libpq-dev libzip-dev libicu-dev libonig-dev libxml2-dev rsync curl \
    && docker-php-ext-install pdo pdo_pgsql intl zip

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# PHP ini
COPY docker/php/php.ini /usr/local/etc/php/php.ini

WORKDIR /var/www/html
