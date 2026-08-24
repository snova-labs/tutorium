FROM php:8.3-fpm-alpine

ARG UID=1000
ARG GID=1000

RUN apk add --no-cache \
        git curl unzip \
        icu-libs oniguruma libzip freetype libjpeg-turbo libpng \
    && apk add --no-cache --virtual .build-deps \
        icu-dev oniguruma-dev libzip-dev \
        freetype-dev libjpeg-turbo-dev libpng-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_mysql mbstring exif pcntl bcmath gd intl zip opcache \
    && apk del --no-network .build-deps

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/app/php.ini /usr/local/etc/php/conf.d/99-platform.ini
COPY docker/app/entrypoint.sh /usr/local/bin/entrypoint

RUN set -eux; \
    chmod +x /usr/local/bin/entrypoint; \
    if ! getent group "${GID}" >/dev/null 2>&1; then \
        addgroup -g "${GID}" app; \
    fi; \
    if ! getent passwd "${UID}" >/dev/null 2>&1; then \
        adduser -u "${UID}" -G "$(getent group "${GID}" | cut -d: -f1)" -s /bin/sh -D app; \
    fi; \
    mkdir -p /tmp/composer; \
    chown -R "${UID}:${GID}" /tmp/composer

ENV COMPOSER_HOME=/tmp/composer
ENV COMPOSER_MEMORY_LIMIT=-1

WORKDIR /var/www/html

USER ${UID}:${GID}

ENTRYPOINT ["/usr/local/bin/entrypoint"]
CMD ["php-fpm"]