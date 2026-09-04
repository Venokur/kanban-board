# syntax=docker/dockerfile:1

# ---------- Stage 1: build the Vue frontend ----------
FROM node:20-alpine AS fe

WORKDIR /build

COPY frontend/package*.json ./
RUN npm install

COPY frontend/ .
RUN npm run build

# ---------- Stage 2: PHP + Swoole (with coroutine postgres) ----------
FROM php:8.2-cli-bookworm AS php

ARG SWOOLE_VERSION=v5.1.4

RUN apt-get update && apt-get install -y --no-install-recommends \
        curl \
        libpq-dev \
        libssl-dev \
        zlib1g-dev \
        libbrotli-dev \
        pkg-config \
        autoconf \
        gcc \
        g++ \
        make \
        git \
    && rm -rf /var/lib/apt/lists/*

# Build Swoole from source with postgresql coroutine support (--enable-swoole-pgsql)
RUN git clone --depth 1 --branch ${SWOOLE_VERSION} https://github.com/swoole/swoole-src.git /tmp/swoole-src \
    && cd /tmp/swoole-src \
    && phpize \
    && ./configure --enable-swoole --enable-swoole-pgsql \
    && make -j"$(nproc)" \
    && make install \
    && rm -rf /tmp/swoole-src

RUN docker-php-ext-enable swoole \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql

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
COPY --from=fe /build/dist/ /var/www/html/

EXPOSE 9501

CMD ["php", "src/server.php"]
