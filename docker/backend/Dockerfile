# docker/backend/Dockerfile
FROM php:8.3-fpm

# System deps
RUN apt-get update && apt-get install -y \
    git unzip libpq-dev libzip-dev libicu-dev libonig-dev libxml2-dev rsync curl \
    && pecl install redis \
    && docker-php-ext-install pdo pdo_pgsql intl zip pcntl sockets opcache \
    && docker-php-ext-enable redis opcache

# Composer
RUN curl -sS https://composer.github.io/installer.sig -o composer.sig \
    && php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');" \
    && php -r "if (hash_file('sha384', 'composer-setup.php') !== trim(file_get_contents('composer.sig'))) { unlink('composer-setup.php'); unlink('composer.sig'); throw new RuntimeException('Invalid composer installer checksum'); }" \
    && php composer-setup.php --install-dir=/usr/local/bin --filename=composer \
    && php -r "unlink('composer-setup.php');" \
    && rm composer.sig

# PHP ini
COPY docker/php/php.ini /usr/local/etc/php/php.ini

WORKDIR /var/www/html
