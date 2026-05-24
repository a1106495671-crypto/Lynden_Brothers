#!/bin/bash
set -euo pipefail

PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LOCAL_DIR="${PROJECT_DIR}/.local"
POSTGRES_DIR="${LOCAL_DIR}/postgres"
POSTGRES_BIN_DIR="${POSTGRES_DIR}/pg17/pgsql/bin"
POSTGRES_DATA_DIR="${POSTGRES_DIR}/data"
POSTGRES_RUN_DIR="${POSTGRES_DIR}/run"
POSTGRES_LOG_FILE="${POSTGRES_DIR}/postgres.log"
FRANKENPHP_BIN="${LOCAL_DIR}/bin/frankenphp"
CADDYFILE_PATH="${LOCAL_DIR}/Caddyfile"

if [ -f "${PROJECT_DIR}/.env" ]; then
    set -a
    . "${PROJECT_DIR}/.env"
    set +a
fi

DB_PORT_VALUE="${DB_PORT:-15432}"
DB_HOST_VALUE="${DB_HOST:-127.0.0.1}"
DB_NAME_VALUE="${DB_NAME:-geo_system}"
DB_USER_VALUE="${DB_USER:-geo_user}"
DB_PASSWORD_VALUE="${DB_PASSWORD:-geo_password}"

mkdir -p "${POSTGRES_RUN_DIR}"

if [ ! -x "${FRANKENPHP_BIN}" ]; then
    echo "未找到 FrankenPHP: ${FRANKENPHP_BIN}"
    exit 1
fi

if [ ! -x "${POSTGRES_BIN_DIR}/pg_ctl" ]; then
    echo "未找到 PostgreSQL 二进制: ${POSTGRES_BIN_DIR}"
    exit 1
fi

if [ ! -f "${POSTGRES_DATA_DIR}/PG_VERSION" ]; then
    echo "未初始化 PostgreSQL 数据目录: ${POSTGRES_DATA_DIR}"
    exit 1
fi

if ! PGPASSWORD="${DB_PASSWORD_VALUE}" "${POSTGRES_BIN_DIR}/psql" -h "${DB_HOST_VALUE}" -p "${DB_PORT_VALUE}" -U "${DB_USER_VALUE}" -d postgres -tAc "SELECT 1" >/dev/null 2>&1; then
    "${POSTGRES_BIN_DIR}/pg_ctl" -D "${POSTGRES_DATA_DIR}" -l "${POSTGRES_LOG_FILE}" -o "-p ${DB_PORT_VALUE} -h ${DB_HOST_VALUE} -k ${POSTGRES_RUN_DIR}" start
    sleep 2
fi

if [ -z "$(PGPASSWORD="${DB_PASSWORD_VALUE}" "${POSTGRES_BIN_DIR}/psql" -h "${DB_HOST_VALUE}" -p "${DB_PORT_VALUE}" -U "${DB_USER_VALUE}" -d postgres -tAc "SELECT 1 FROM pg_database WHERE datname='${DB_NAME_VALUE}'")" ]; then
    PGPASSWORD="${DB_PASSWORD_VALUE}" "${POSTGRES_BIN_DIR}/createdb" -h "${DB_HOST_VALUE}" -p "${DB_PORT_VALUE}" -U "${DB_USER_VALUE}" "${DB_NAME_VALUE}"
fi

# 启动后台文章生成 worker
mkdir -p "${PROJECT_DIR}/bin/logs"
WORKER_PID_FILE="${PROJECT_DIR}/.local/worker.pid"
if [ -f "${WORKER_PID_FILE}" ] && kill -0 "$(cat "${WORKER_PID_FILE}")" 2>/dev/null; then
    echo "worker.php 已在运行 (PID: $(cat "${WORKER_PID_FILE}"))"
else
    nohup php "${PROJECT_DIR}/bin/worker.php" >> "${PROJECT_DIR}/bin/logs/worker.log" 2>&1 &
    echo $! > "${WORKER_PID_FILE}"
    echo "worker.php 已启动 (PID: $!)"
fi

exec "${FRANKENPHP_BIN}" run --config "${CADDYFILE_PATH}"
