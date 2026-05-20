FROM composer:2 AS vendor-prod

WORKDIR /app

COPY . .
RUN mkdir -p bootstrap/cache storage/framework/cache storage/framework/sessions storage/framework/views storage/logs
RUN composer install \
    --no-dev \
    --prefer-dist \
    --no-interaction \
    --no-progress \
    --optimize-autoloader \
    --ignore-platform-req=ext-sockets \
    --ignore-platform-req=ext-bcmath

FROM composer:2 AS vendor-test

WORKDIR /app

COPY . .
RUN mkdir -p bootstrap/cache storage/framework/cache storage/framework/sessions storage/framework/views storage/logs
RUN composer install \
    --prefer-dist \
    --no-interaction \
    --no-progress \
    --optimize-autoloader \
    --ignore-platform-req=ext-sockets \
    --ignore-platform-req=ext-bcmath

FROM php:8.4-cli-alpine AS base

WORKDIR /app

RUN apk add --no-cache linux-headers postgresql-dev \
    && docker-php-ext-install bcmath pcntl pdo_pgsql sockets

COPY . .

RUN cp .env.example .env

RUN rm -f bootstrap/cache/*.php \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

EXPOSE 8000

FROM base AS app

COPY --from=vendor-prod --chown=www-data:www-data /app/vendor ./vendor
RUN rm -rf tests

USER www-data

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]

FROM base AS app-test

COPY --from=vendor-test --chown=www-data:www-data /app/vendor ./vendor

USER www-data

CMD ["php", "artisan", "test"]
