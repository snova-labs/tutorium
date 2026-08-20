FROM php:8.3-fpm-alpine

RUN apk add --no-cache \
        git curl unzip icu-dev oniguruma-dev libzip-dev \
        freetype-dev libjpeg-turbo-dev libpng-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_mysql mbstring exif pcntl bcmath gd intl zip opcache \
    && rm -rf /var/cache/apk/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/app/php.ini /usr/local/etc/php/conf.d/99-platform.ini
COPY docker/app/entrypoint.sh /usr/local/bin/entrypoint

RUN chmod +x /usr/local/bin/entrypoint \
    && addgroup -g 1000 app \
    && adduser -u 1000 -G app -s /bin/sh -D app

WORKDIR /var/www/html
USER app

ENTRYPOINT ["entrypoint"]
CMD ["php-fpm"]
