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

if command -v pg_ctl >/dev/null 2>&1 && [ -f "${POSTGRES_DATA_DIR}/PG_VERSION" ]; then
    pg_ctl -D "${POSTGRES_DATA_DIR}" stop || true
fi
