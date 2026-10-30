# syntax=docker/dockerfile:1

# ---------- Stage 1: build the Vue frontend ----------
FROM node:20-alpine AS fe

WORKDIR /build

COPY frontend/package*.json ./
RUN npm install

COPY frontend/ .
RUN npm run build

# ---------- Stage 2: build the OpenSwoole extension ----------
FROM php:8.3-cli-bookworm AS openswoole

ARG OPENSWOOLE_VERSION=v26.2.0

# Some build environments block plain HTTP, so apt sources are switched to HTTPS.
RUN set -eux; \
    for f in /etc/apt/sources.list /etc/apt/sources.list.d/*.list /etc/apt/sources.list.d/*.sources; do \
        [ -f "$f" ] && sed -i 's|http://|https://|g' "$f" || true; \
    done; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        curl \
        libpq-dev \
        libssl-dev \
        zlib1g-dev \
        pkg-config \
        autoconf \
        gcc \
        g++ \
        make \
        git; \
    rm -rf /var/lib/apt/lists/*

# OpenSwoole 26.x requires PHP >= 8.3. The coroutine PostgreSQL client is
# compiled in with --with-postgres (Swoole used --enable-swoole-pgsql).
RUN git clone --depth 1 --branch ${OPENSWOOLE_VERSION} \
        https://github.com/openswoole/ext-openswoole.git /tmp/ext-openswoole \
    && cd /tmp/ext-openswoole \
    && phpize \
    && ./configure --enable-openssl --with-postgres \
    && make -j"$(nproc)" \
    && make install \
    && rm -rf /tmp/ext-openswoole

# ---------- Stage 3: runtime (no toolchain in the final image) ----------
FROM php:8.3-cli-bookworm

RUN set -eux; \
    for f in /etc/apt/sources.list /etc/apt/sources.list.d/*.list /etc/apt/sources.list.d/*.sources; do \
        [ -f "$f" ] && sed -i 's|http://|https://|g' "$f" || true; \
    done; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        curl \
        libpq5; \
    rm -rf /var/lib/apt/lists/*

COPY --from=openswoole /usr/local/lib/php/extensions/ /usr/local/lib/php/extensions/

# Fail the build early if the extension or the coroutine PostgreSQL client is missing.
RUN docker-php-ext-enable openswoole \
    && php -r "exit(class_exists('OpenSwoole\\\\Coroutine\\\\PostgreSQL') ? 0 : 1);"

ENV DB_HOST=db \
    DB_PORT=5432 \
    DB_NAME=kanban \
    DB_USER=kanban \
    DB_PASSWORD=kanban \
    APP_HOST=0.0.0.0 \
    APP_PORT=9501 \
    APP_DEBUG=0

WORKDIR /app

COPY backend/src/ ./src/
COPY migrations/ ./migrations/
COPY --from=fe /build/dist/ /var/www/html/

EXPOSE 9501

CMD ["php", "src/server.php"]
