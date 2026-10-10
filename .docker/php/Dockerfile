FROM php:8.5-fpm

ADD --chmod=0755 https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/

RUN install-php-extensions apcu intl opcache pdo pdo_pgsql xsl zip

COPY custom-config.ini /usr/local/etc/php/conf.d/custom-config.ini

COPY --chmod=0755 entrypoint.sh /usr/local/bin/dev-entrypoint
ENTRYPOINT ["dev-entrypoint"]
# Redeclared: setting ENTRYPOINT resets the base image's CMD.
CMD ["php-fpm"]

WORKDIR /var/www

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
