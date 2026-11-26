# Production image for Coolify (compose.production.yaml builds it once for web, worker and scheduler).
#
# Runtime: FrankenPHP (Caddy with PHP built in) on the official php:8.5 ZTS
# Debian image. One process serves static files and PHP, so there is no
# nginx + php-fpm pair to supervise. Listens on plain HTTP :8080 as a non-root
# user; Coolify's Traefik terminates TLS in front of it.
#
# The build needs network access: Composer and npm downloads, and the Vite
# font provider fetches the Google fonts it self-hosts into public/build.

ARG PHP_IMAGE=dunglas/frankenphp:1-php8.5-trixie

# ---------------------------------------------------------------------------
# base: PHP runtime with every extension the app needs.
# OPcache is built into PHP 8.5; it is configured in docker/php.ini.
# gd is built with JPEG, PNG, WebP and FreeType (WebP re-encoding strips EXIF).
# ---------------------------------------------------------------------------
FROM ${PHP_IMAGE} AS base

RUN install-php-extensions \
        bcmath \
        exif \
        gd \
        intl \
        pcntl \
        pdo_pgsql \
        zip \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY docker/php.ini "$PHP_INI_DIR/conf.d/zz-app.ini"

WORKDIR /app

# ---------------------------------------------------------------------------
# vendor: Composer dependencies (no dev packages) and Filament's published
# assets (public/css|js|fonts/filament, written by `filament:upgrade` in the
# post-autoload-dump script).
# ---------------------------------------------------------------------------
FROM base AS vendor

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV COMPOSER_ALLOW_SUPERUSER=1

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --no-progress --prefer-dist

COPY . .
RUN mkdir -p storage/app/public storage/app/private storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && composer dump-autoload --no-dev --optimize --no-interaction \
    && php artisan storage:link

# ---------------------------------------------------------------------------
# assets: Vite build. Filament's theme imports and @sources vendor/filament,
# so the vendor directory from the previous stage must be present.
# ---------------------------------------------------------------------------
FROM node:24-slim AS assets

WORKDIR /app

COPY package.json package-lock.json .npmrc ./
RUN npm ci --no-audit --no-fund

COPY --from=vendor /app /app
RUN npm run build

# ---------------------------------------------------------------------------
# runtime
# ---------------------------------------------------------------------------
FROM base AS runtime

ARG APP_UID=1000

RUN useradd --uid "${APP_UID}" --user-group --no-create-home --shell /usr/sbin/nologin app \
    && mkdir -p /data/caddy /config/caddy \
    && chown -R app:app /data/caddy /config

COPY docker/Caddyfile /etc/frankenphp/Caddyfile
COPY --chmod=755 docker/entrypoint.sh /usr/local/bin/entrypoint

COPY --from=vendor /app /app
COPY --from=assets /app/public/build /app/public/build

# Code stays root-owned and read-only to the app user; only the directories
# Laravel writes to are handed over. storage/app holds variant photos (public)
# and payment slips (private), so compose mounts a persistent volume on it.
RUN chown -R app:app storage bootstrap/cache

USER app

EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=5s --start-period=60s --retries=3 \
    CMD curl -fsS http://127.0.0.1:8080/up > /dev/null || exit 1

ENTRYPOINT ["/usr/local/bin/entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]
