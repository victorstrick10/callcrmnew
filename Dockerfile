# syntax=docker/dockerfile:1

FROM php:8.2-cli-alpine AS vendor
WORKDIR /app
RUN apk add --no-cache \
    icu-dev \
    libzip-dev \
    && docker-php-ext-configure intl \
    && docker-php-ext-install intl zip
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-ansi \
    --no-interaction \
    --no-progress \
    --prefer-dist \
    --no-scripts
COPY . .
RUN composer dump-autoload --optimize --classmap-authoritative --no-scripts

FROM node:20-alpine AS assets
WORKDIR /app
COPY package.json ./
RUN npm install --no-audit --no-fund
COPY vite.config.js ./
COPY resources ./resources
RUN npm run build

FROM php:8.2-cli-alpine AS runtime
RUN apk add --no-cache \
    icu-dev \
    libzip-dev \
    oniguruma-dev \
    postgresql-dev \
    && docker-php-ext-configure intl \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        intl \
        opcache \
        pdo_pgsql \
        pgsql \
        zip \
    && rm -rf /var/cache/apk/*
COPY docker/php-production.ini /usr/local/etc/php/conf.d/99-production.ini
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh \
    && sed -i 's/\r$//' /usr/local/bin/entrypoint.sh
WORKDIR /var/www/html
COPY --from=vendor /app /var/www/html
COPY --from=assets /app/public/build ./public/build
RUN mkdir -p \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache
EXPOSE 8080
ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    CONTAINER_ROLE=web
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
