FROM dunglas/frankenphp:1.12.7-php8.5-bookworm AS php-base

RUN install-php-extensions bcmath intl mbstring opcache pcntl pdo_pgsql pgsql zip
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY docker_settings/mrst/php/dev.ini /usr/local/etc/php/conf.d/99-mrst-dev.ini
WORKDIR /app

FROM php-base AS web-dev
COPY docker_settings/mrst/frankenphp/Caddyfile /etc/frankenphp/Caddyfile
ENV XDG_CONFIG_HOME=/tmp/mrst-caddy-config \
    XDG_DATA_HOME=/tmp/mrst-caddy-data
EXPOSE 8080

FROM php-base AS cli-dev
ENTRYPOINT []
HEALTHCHECK NONE

FROM cli-dev AS worker-dev
RUN apt-get update \
    && apt-get install -y --no-install-recommends python3 python3-venv \
    && rm -rf /var/lib/apt/lists/*
COPY workers/msnc-python/requirements.txt /tmp/msnc-requirements.txt
RUN python3 -m venv /opt/msnc-venv \
    && /opt/msnc-venv/bin/pip install --no-cache-dir -r /tmp/msnc-requirements.txt
ENV PATH=/opt/msnc-venv/bin:${PATH}
