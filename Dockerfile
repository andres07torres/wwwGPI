FROM dunglas/frankenphp:1-php8.4-alpine AS app_php

# Dependencias del sistema y herramientas de desarrollo
RUN apk add --no-cache \
    acl \
    file \
    gettext \
    git \
    nodejs \
    npm \
    bash \
    curl \
    zip \
    unzip

# Extensiones de PHP requeridas por Symfony y MySQL
RUN install-php-extensions \
    @composer \
    apcu \
    intl \
    opcache \
    pdo_mysql \
    gd \
    zip

# Configuración personalizada de PHP
COPY docker/php/conf.d/app.ini $PHP_INI_DIR/conf.d/app.ini

WORKDIR /app

# Variables de entorno por defecto
ENV COMPOSER_ALLOW_SUPERUSER=1
ENV SERVER_NAME=":80"

# Puertos para HTTP, HTTPS y HTTP/3
EXPOSE 80 443 443/udp 2019

CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
