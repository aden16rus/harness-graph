#!/bin/sh
set -e

# Setup directories
mkdir -p /data/logs /var/run /workspace

# Set permissions if running as root
if [ "$(id -u)" = "0" ]; then
    chown -R ${PUID:-1000}:${PGID:-1000} /data /workspace /var/run || true
    # Allow socket access
    chmod 777 /var/run || true
fi

# Locate PHP binary
PHP_BIN_PATH=$(which php || echo "/usr/local/bin/php")
if [ ! -f /usr/bin/php ] && [ -f "$PHP_BIN_PATH" ]; then
    ln -sf "$PHP_BIN_PATH" /usr/bin/php || true
fi

# Run database migrations if database doesn't exist
if [ ! -f /data/harness.db ]; then
    echo "[Entrypoint] Initializing SQLite database schema..."
    "$PHP_BIN_PATH" /app/php-core/bin/harness migrate --db=/data/harness.db || true
fi

echo "[Entrypoint] Starting Harness Go Engine..."
# Exec Go Engine (which serves UI static files, WebSocket hub, IPC socket, and spawns PHP tasks)
exec /app/go-engine/bin/harness-engine \
    --ipc-socket=${HARNESS_IPC_SOCKET:-/var/run/harness.sock} \
    --http-port=${HTTP_PORT:-8080} \
    --web-dir=/app/frontend/dist \
    --workspace-dir=${WORKSPACE_DIR:-/workspace} \
    --data-dir=${DATA_DIR:-/data} \
    --php-bin="$PHP_BIN_PATH" \
    --php-harness=/app/php-core/bin/harness
