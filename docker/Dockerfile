# Laravel Octane (FrankenPHP) + Supervisor for Render (web + worker)
# Build: docker build -f docker/Dockerfile --build-arg DEPLOYMENT_TYPE=web -t app:latest .
# Worker: same with DEPLOYMENT_TYPE=worker

FROM dunglas/frankenphp AS php-base

ADD https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/
RUN chmod +x /usr/local/bin/install-php-extensions

RUN install-php-extensions \
    bcmath \
    intl \
    opcache \
    pcntl \
    pdo_pgsql \
    pdo_sqlite \
    zip

RUN apt-get update && apt-get install -y \
    bash \
    supervisor \
    git \
    curl \
    unzip \
    libpq-dev \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:latest /usr/bin/composer /usr/local/bin/composer

# --- BEGIN FRONTEND (Node/Vite) ---
# Remove this entire stage if your app has no frontend (API-only/backend). Also remove the
# COPY --from=frontend and RUN block below that copy the frontend build into /app/public/build.
# Set build arg SKIP_FRONTEND=1 to skip npm run build (e.g. if it fails on the platform).
FROM node:20 AS frontend

ARG SKIP_FRONTEND=0

WORKDIR /frontend

COPY package.json package-lock.json* yarn.lock* pnpm-lock.yaml* ./
RUN npm ci 2>/dev/null || npm install --legacy-peer-deps

COPY resources ./resources
COPY vite.config.* ./
COPY tailwind.config.* ./
COPY postcss.config.* ./

RUN if [ "$SKIP_FRONTEND" = "1" ]; then mkdir -p public/build; else npm run build; fi
# --- END FRONTEND ---

FROM php-base

ARG USER=appuser
ARG DEPLOYMENT_TYPE=web
ENV DEPLOYMENT_TYPE=${DEPLOYMENT_TYPE}

RUN useradd ${USER} && \
    setcap -r /usr/local/bin/frankenphp 2>/dev/null || true && \
    chown -R ${USER}:${USER} /data/caddy 2>/dev/null || true && \
    chown -R ${USER}:${USER} /config/caddy 2>/dev/null || true

RUN mkdir -p /var/log/supervisor /app/storage/logs /app/config /app/data

ENV XDG_CONFIG_HOME=/app/config
ENV XDG_DATA_HOME=/app/data
ENV SERVER_NAME=:8000
ENV OCTANE_SERVER=frankenphp

WORKDIR /app

COPY composer.json composer.lock* ./
# Optional: add "COPY auth.json ./" here if you use private Composer repos

RUN --mount=type=cache,target=/root/.composer \
    composer install --no-dev --no-interaction --optimize-autoloader --no-scripts --ignore-platform-req=php

COPY . /app

COPY docker/supervisord-web.conf /etc/supervisor/conf.d/supervisord-web.conf
COPY docker/supervisord-worker.conf /etc/supervisor/conf.d/supervisord-worker.conf
COPY docker/php.ini /usr/local/etc/php/php.ini
COPY docker/docker-entrypoint.sh /docker-entrypoint.sh

# --- BEGIN FRONTEND COPY ---
# Remove these two blocks if your app has no frontend (API-only/backend).
COPY --from=frontend /frontend/public/build /tmp/frontend-build

RUN if [ "$DEPLOYMENT_TYPE" != "worker" ]; then \
      mkdir -p /app/public/build && \
      cp -r /tmp/frontend-build/* /app/public/build/ 2>/dev/null || true; \
    fi && \
    rm -rf /tmp/frontend-build
# --- END FRONTEND COPY ---

RUN chown -R ${USER}:${USER} /app && \
    chmod +x /docker-entrypoint.sh /app/artisan

USER ${USER}
EXPOSE 8000 443 2019
ENTRYPOINT ["/docker-entrypoint.sh"]
