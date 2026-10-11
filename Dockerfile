# syntax=docker/dockerfile:1.7

FROM node:22-bookworm-slim AS frontend
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --legacy-peer-deps
COPY resources ./resources
COPY public ./public
COPY tsconfig.json vite.config.* tailwind.config.* postcss.config.* ./
RUN npm run build

FROM dunglas/frankenphp:php8.4-bookworm AS vendor

RUN install-php-extensions exif intl zip
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
# Production default: no dev packages. Local images pass INSTALL_DEV=true (Faker for demo seeders).
ARG INSTALL_DEV=false
COPY composer.json composer.lock ./
COPY Modules ./Modules
RUN composer install \
    $([ "$INSTALL_DEV" = "true" ] || echo --no-dev) \
    --no-interaction \
    --no-progress \
    --prefer-dist \
    --optimize-autoloader \
    --no-scripts

FROM dunglas/frankenphp:php8.4-bookworm AS application

RUN install-php-extensions \
    bcmath \
    exif \
    gd \
    intl \
    opcache \
    pcntl \
    pdo_mysql \
    redis \
    zip

WORKDIR /app

COPY --chown=www-data:www-data . .
COPY --from=vendor --chown=www-data:www-data /app/vendor ./vendor
COPY --from=frontend --chown=www-data:www-data /app/public/build ./public/build
COPY docker/Caddyfile /etc/caddy/Caddyfile
COPY docker/app/entrypoint.sh /usr/local/bin/inbils-entrypoint

RUN chmod +x /usr/local/bin/inbils-entrypoint \
    && mkdir -p \
        storage/app/public \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/testing \
        storage/framework/views \
        storage/logs \
        bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

ENV SERVER_NAME=:8080
EXPOSE 8080

ENTRYPOINT ["inbils-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
