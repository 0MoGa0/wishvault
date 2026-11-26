# syntax=docker/dockerfile:1
ARG PHP_VERSION=8.5

# --- web UI -------------------------------------------------------------------
FROM node:24-alpine AS web
WORKDIR /src
RUN npm install -g pnpm@11.25.0
COPY openapi.yaml ./
COPY web/package.json web/pnpm-lock.yaml web/pnpm-workspace.yaml ./web/
RUN cd web && pnpm install --frozen-lockfile
COPY web ./web
RUN mkdir public && cd web && pnpm build

# --- service ------------------------------------------------------------------
FROM serversideup/php:${PHP_VERSION}-fpm-nginx-alpine
ARG VERSION=dev
LABEL org.opencontainers.image.title="WishVault" \
      org.opencontainers.image.description="Game wishlist with voting for GameVault servers" \
      org.opencontainers.image.licenses="AGPL-3.0-only" \
      org.opencontainers.image.version="${VERSION}"

USER root
RUN install-php-extensions intl \
 && mkdir -p /var/www/data \
 && chown www-data:www-data /var/www/data
COPY --chmod=755 docker/s6-overlay/ /etc/s6-overlay/

USER www-data
WORKDIR /var/www/html
COPY --chown=www-data:www-data composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --optimize-autoloader --no-scripts
COPY --chown=www-data:www-data src ./src
COPY --chown=www-data:www-data bin ./bin
COPY --chown=www-data:www-data public/index.php ./public/index.php
COPY --from=web --chown=www-data:www-data /src/public/ ./public/

ENV DATA_DIR=/var/www/data \
    WEB_DIR=/var/www/html/public \
    PHP_OPCACHE_ENABLE=1 \
    SSL_MODE=off

VOLUME /var/www/data
EXPOSE 8080
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
  CMD curl -fsS http://127.0.0.1:8080/api/v1/info > /dev/null || exit 1
