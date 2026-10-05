# syntax=docker/dockerfile:1.7
#
# The Laravel API, admin panel and background workers, as one image.
# Built for linux/arm64 (Oracle Ampere) in CI; see .github/workflows/images.yml.
#
# Front-end assets are compiled on the build machine's own architecture (they are plain CSS and
# JavaScript), so only the PHP layers run under emulation when cross-building.

FROM --platform=$BUILDPLATFORM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json vite.config.js ./
RUN npm ci --no-audit --no-fund
COPY resources ./resources
COPY public ./public
RUN npm run build

# serversideup/php: PHP-FPM and Nginx in one unprivileged container, listening on 8080.
FROM serversideup/php:8.3-fpm-nginx AS app

USER root
# Beyond the image's defaults: intl (number and date formatting), gd (spreadsheets, PDFs),
# bcmath (money arithmetic), exif.
RUN install-php-extensions intl gd bcmath exif
USER www-data

ENV PHP_OPCACHE_ENABLE=1 \
    # Migrations run in their own one-off container (see the stack file), never as a side effect
    # of a web container starting.
    AUTORUN_ENABLED=false \
    # Docker's bridge networks are IPv4; listening on IPv6 too fails on hosts without it.
    NGINX_LISTEN_IP_PROTOCOL=ipv4 \
    LOG_CHANNEL=stderr

WORKDIR /var/www/html

COPY --chown=www-data:www-data composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --no-scripts --no-autoloader

COPY --chown=www-data:www-data . .
COPY --from=assets --chown=www-data:www-data /app/public/build ./public/build

# PHP's JIT crashes (segfault) under the QEMU emulation used to cross-build for ARM, as soon as
# Composer scans the classmap with its large regular expressions. These build-time commands run
# with it off; the running containers on real ARM hardware are unaffected.
ARG BUILD_PHP="php -d pcre.jit=0 -d opcache.enable_cli=0 -d opcache.jit=off"

RUN ${BUILD_PHP} "$(command -v composer)" dump-autoload --optimize --classmap-authoritative --no-dev \
    && ${BUILD_PHP} artisan package:discover --ansi \
    && ${BUILD_PHP} artisan storage:link \
    && mkdir -p storage/app/private storage/app/public storage/framework/cache storage/framework/sessions storage/framework/views
