#!/bin/bash
set -euo pipefail

PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
POSTGRES_DATA_DIR="${PROJECT_DIR}/.local/postgres/data"

if [ -f "${PROJECT_DIR}/.env" ]; then
    set -a
    . "${PROJECT_DIR}/.env"
    set +a
fi

HOST_VALUE="${HOST:-127.0.0.1}"
HOST_PORT_VALUE="${HOST_PORT:-18080}"

pkill -f "php -S ${HOST_VALUE}:${HOST_PORT_VALUE} router.php" || true
pkill -f "php -S localhost:${HOST_PORT_VALUE} router.php" || true

# 停止后台 worker
WORKER_PID_FILE="${PROJECT_DIR}/.local/worker.pid"
if [ -f "${WORKER_PID_FILE}" ]; then
    kill "$(cat "${WORKER_PID_FILE}")" 2>/dev/null || true
    rm -f "${WORKER_PID_FILE}"
    echo "worker.php 已停止"
fi

if command -v pg_ctl >/dev/null 2>&1 && [ -f "${POSTGRES_DATA_DIR}/PG_VERSION" ]; then
    pg_ctl -D "${POSTGRES_DATA_DIR}" stop || true
fi
