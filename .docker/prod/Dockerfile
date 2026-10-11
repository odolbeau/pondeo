# Production images, built from the repository root:
#
#   docker build --file .docker/prod/Dockerfile --target php .
#   docker build --file .docker/prod/Dockerfile --target web .
#
# `php` runs the Symfony app (PHP-FPM) and holds the data to import
# (resources/geo, tracked with Git LFS: check it out before building), `web`
# serves the static files and forwards the rest to `php` (see nginx.conf).
# Both must see the application at the same path (/var/www).

FROM php:8.5-fpm AS php-base

ADD --chmod=0755 https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/

# Same extensions as the dev image (.docker/php/Dockerfile).
RUN install-php-extensions apcu intl opcache pdo pdo_pgsql xsl zip

COPY .docker/php/custom-config.ini /usr/local/etc/php/conf.d/custom-config.ini
COPY .docker/prod/php.ini /usr/local/etc/php/conf.d/zz-prod.ini

WORKDIR /var/www


FROM php-base AS vendors

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

ENV APP_ENV=prod

# Dependencies first, so that this layer is only rebuilt when they change.
COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-progress

COPY . ./
RUN composer dump-autoload --no-dev --classmap-authoritative \
    && composer dump-env prod \
    && composer run-script --no-dev post-install-cmd


FROM node:22-slim AS front-build

WORKDIR /var/www

COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund

# The build reads the Stimulus controllers shipped by vendors, and Tailwind
# scans the templates.
COPY --from=vendors /var/www ./
RUN npm run build


FROM php-base AS php

LABEL org.opencontainers.image.source=https://github.com/odolbeau/pondeo
LABEL org.opencontainers.image.description="Pondeo (PHP-FPM)"

COPY --from=vendors /var/www /var/www
COPY --from=front-build /var/www/public/build /var/www/public/build
RUN mkdir -p var && chown -R www-data:www-data var

USER www-data


FROM nginx:stable-alpine AS web

LABEL org.opencontainers.image.source=https://github.com/odolbeau/pondeo
LABEL org.opencontainers.image.description="Pondeo (nginx)"

COPY --from=php /var/www/public /var/www/public
COPY .docker/prod/nginx.conf /etc/nginx/conf.d/default.conf
