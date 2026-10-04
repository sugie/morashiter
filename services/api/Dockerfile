# RightsRelay JSON API. The production image is also suitable for Ubuntu IaaS.
FROM composer:2 AS vendor

WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install \
        --no-dev \
        --no-interaction \
        --no-progress \
        --prefer-dist \
        --optimize-autoloader \
        --no-scripts

FROM dunglas/frankenphp:php8.4-bookworm AS runtime

RUN install-php-extensions \
        intl \
        opcache \
        pdo_pgsql \
        pgsql \
        zip

COPY docker/Caddyfile /etc/caddy/Caddyfile
COPY docker/php.ini /usr/local/etc/php/conf.d/rightsrelay.ini

ENV PGCONNECT_TIMEOUT=5
WORKDIR /app

# Caddy still maintains internal state with automatic HTTPS disabled. Make its
# standard directories writable before dropping privileges.
RUN mkdir -p /data/caddy /config/caddy \
    && chown -R www-data:www-data /data /config

FROM runtime AS dev

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY composer.json composer.lock ./
RUN composer install \
        --no-interaction \
        --no-progress \
        --prefer-dist \
        --no-scripts \
    && chmod -R 777 /app/vendor

RUN mkdir -p \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        bootstrap/cache \
    && chmod -R 777 storage bootstrap/cache

ENV APP_ENV=local \
    APP_DEBUG=true \
    LOG_CHANNEL=stderr

USER www-data
EXPOSE 80
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]

FROM runtime AS app

COPY . .
COPY --from=vendor /app/vendor ./vendor
RUN mkdir -p \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        bootstrap/cache \
    && chmod -R 777 storage bootstrap/cache

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr

USER www-data
EXPOSE 80
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
