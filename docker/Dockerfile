# ==========================================
# Stage 1: Build Go Daemon Engine
# ==========================================
FROM golang:alpine AS builder-go
WORKDIR /build

RUN apk add --no-cache git ca-certificates

COPY go-engine/go.mod go-engine/go.sum* ./
RUN go mod download || true

COPY go-engine/ ./
RUN CGO_ENABLED=0 GOOS=linux go build -ldflags="-s -w" -o /build/harness-engine ./cmd/server

# ==========================================
# Stage 2: Build Svelte 5 Frontend
# ==========================================
FROM node:22-alpine AS builder-frontend
WORKDIR /build

COPY frontend/package.json frontend/package-lock.json* ./
RUN npm install

COPY frontend/ ./
RUN npm run build

# ==========================================
# Stage 3: Runtime Environment (PHP 8.3 CLI)
# ==========================================
FROM php:8.3-cli-alpine AS runtime

# Install system dependencies, SQLite, PCNTL, POSIX, Docker CLI client
RUN apk add --no-cache \
    bash \
    curl \
    git \
    sqlite \
    sqlite-dev \
    docker-cli \
    ca-certificates \
    shadow \
    && docker-php-ext-install pdo pdo_sqlite pcntl posix

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Copy Go binary
COPY --from=builder-go /build/harness-engine /app/go-engine/bin/harness-engine
RUN chmod +x /app/go-engine/bin/harness-engine

# Copy Frontend static dist
COPY --from=builder-frontend /build/dist /app/frontend/dist

# Copy PHP Core application
COPY php-core /app/php-core
WORKDIR /app/php-core
RUN composer install --no-dev --optimize-autoloader --no-interaction || true

WORKDIR /app

# Copy entrypoint script
COPY docker/entrypoint.sh /app/entrypoint.sh
RUN chmod +x /app/entrypoint.sh

# Volumes
VOLUME ["/workspace", "/data", "/var/run"]

EXPOSE 8080

ENTRYPOINT ["/app/entrypoint.sh"]
