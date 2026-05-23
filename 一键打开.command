#!/bin/bash
set -euo pipefail

PROJECT_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "${PROJECT_DIR}"

LOCAL_DIR="${PROJECT_DIR}/.local"
POSTGRES_DATA_DIR="${LOCAL_DIR}/postgres/data"
POSTGRES_RUN_DIR="${LOCAL_DIR}/postgres/run"
POSTGRES_LOG_FILE="${LOCAL_DIR}/postgres/postgres.log"
PHP_LOG_FILE="${LOCAL_DIR}/php-server.log"
PHP_PID_FILE="${LOCAL_DIR}/php-server.pid"
SCREEN_NAME="geo-system-local"

mkdir -p "${LOCAL_DIR}/postgres" "${POSTGRES_RUN_DIR}" data/backups logs uploads/images uploads/knowledge

need_command() {
    if ! command -v "$1" >/dev/null 2>&1; then
        echo "缺少命令：$1"
        echo "请先安装依赖：brew install php postgresql@17"
        read -r -p "按回车退出..."
        exit 1
    fi
}

port_is_free() {
    local port="$1"
    ! lsof -nP -iTCP:"${port}" -sTCP:LISTEN >/dev/null 2>&1
}

next_free_port() {
    local port="$1"
    while ! port_is_free "${port}"; do
        port=$((port + 1))
    done
    echo "${port}"
}

env_set() {
    local key="$1"
    local value="$2"
    touch .env
    if grep -q "^${key}=" .env; then
        sed -i.bak "s|^${key}=.*|${key}=${value}|" .env
        rm -f .env.bak
    else
        printf '%s=%s\n' "${key}" "${value}" >> .env
    fi
    export "${key}=${value}"
}

need_command php
need_command pg_ctl
need_command initdb
need_command psql
need_command createdb
need_command lsof
need_command screen

PHP_MODULES="$(php -m)"
if ! printf '%s\n' "${PHP_MODULES}" | grep -Fxq 'pdo_pgsql'; then
    echo "PHP 缺少 pdo_pgsql 扩展，请确认使用 Homebrew PHP。"
    read -r -p "按回车退出..."
    exit 1
fi

if [ ! -f .env ] && [ -f .env.example ]; then
    cp .env.example .env
fi

if [ -f .env ]; then
    set -a
    # shellcheck disable=SC1091
    . .env
    set +a
fi

HOST_VALUE="${HOST:-127.0.0.1}"
WEB_PORT="$(next_free_port "${HOST_PORT:-18080}")"
DB_HOST_VALUE="${DB_HOST:-127.0.0.1}"
if [ "${DB_HOST_VALUE}" = "postgres" ]; then
    DB_HOST_VALUE="127.0.0.1"
fi
DB_PORT_CANDIDATE="${DB_PORT:-15432}"
if [ "${DB_PORT_CANDIDATE}" = "5432" ]; then
    DB_PORT_CANDIDATE="${DB_EXPOSE_PORT:-15432}"
fi
DB_PORT_VALUE="${DB_PORT_CANDIDATE}"
if [ -f "${POSTGRES_DATA_DIR}/postmaster.pid" ]; then
    EXISTING_PID="$(head -n 1 "${POSTGRES_DATA_DIR}/postmaster.pid" || true)"
    if [ -n "${EXISTING_PID}" ] && kill -0 "${EXISTING_PID}" >/dev/null 2>&1; then
        DB_PORT_VALUE="$(sed -n '4p' "${POSTGRES_DATA_DIR}/postmaster.pid" || echo "${DB_PORT_VALUE}")"
    else
        rm -f "${POSTGRES_DATA_DIR}/postmaster.pid"
    fi
elif ! port_is_free "${DB_PORT_VALUE}"; then
    DB_PORT_VALUE="$(next_free_port "${DB_PORT_VALUE}")"
fi

DB_NAME_VALUE="${DB_NAME:-geo_system}"
DB_USER_VALUE="${DB_USER:-geo_user}"
DB_PASSWORD_VALUE="${DB_PASSWORD:-geo_password}"
ADMIN_BASE_PATH_VALUE="${ADMIN_BASE_PATH:-dl-console}"
ADMIN_BASE_PATH_VALUE="${ADMIN_BASE_PATH_VALUE#/}"
SITE_URL_VALUE="http://${HOST_VALUE}:${WEB_PORT}"

env_set HOST "${HOST_VALUE}"
env_set HOST_PORT "${WEB_PORT}"
env_set DB_DRIVER "pgsql"
env_set DB_HOST "${DB_HOST_VALUE}"
env_set DB_PORT "${DB_PORT_VALUE}"
env_set DB_NAME "${DB_NAME_VALUE}"
env_set DB_USER "${DB_USER_VALUE}"
env_set DB_PASSWORD "${DB_PASSWORD_VALUE}"
env_set SITE_URL "${SITE_URL_VALUE}"

if [ ! -f "${POSTGRES_DATA_DIR}/PG_VERSION" ]; then
    echo "初始化本地 PostgreSQL..."
    initdb -D "${POSTGRES_DATA_DIR}" --encoding=UTF8 --locale=C >/dev/null
fi

if ! pg_ctl -D "${POSTGRES_DATA_DIR}" status >/dev/null 2>&1; then
    echo "启动 PostgreSQL：${DB_HOST_VALUE}:${DB_PORT_VALUE}"
    pg_ctl -D "${POSTGRES_DATA_DIR}" -l "${POSTGRES_LOG_FILE}" -o "-p ${DB_PORT_VALUE} -h ${DB_HOST_VALUE} -k ${POSTGRES_RUN_DIR}" start >/dev/null
fi

for _ in {1..30}; do
    if psql -h "${DB_HOST_VALUE}" -p "${DB_PORT_VALUE}" -U "$(whoami)" -d postgres -tAc "SELECT 1" >/dev/null 2>&1; then
        break
    fi
    sleep 1
done

ROLE_EXISTS="$(psql -h "${DB_HOST_VALUE}" -p "${DB_PORT_VALUE}" -U "$(whoami)" -d postgres -tAc "SELECT 1 FROM pg_roles WHERE rolname='${DB_USER_VALUE}'" || true)"
if [ -z "${ROLE_EXISTS}" ]; then
    psql -h "${DB_HOST_VALUE}" -p "${DB_PORT_VALUE}" -U "$(whoami)" -d postgres -c "CREATE ROLE \"${DB_USER_VALUE}\" LOGIN PASSWORD '${DB_PASSWORD_VALUE}';" >/dev/null
else
    psql -h "${DB_HOST_VALUE}" -p "${DB_PORT_VALUE}" -U "$(whoami)" -d postgres -c "ALTER ROLE \"${DB_USER_VALUE}\" LOGIN PASSWORD '${DB_PASSWORD_VALUE}';" >/dev/null
fi

DB_EXISTS="$(PGPASSWORD="${DB_PASSWORD_VALUE}" psql -h "${DB_HOST_VALUE}" -p "${DB_PORT_VALUE}" -U "${DB_USER_VALUE}" -d postgres -tAc "SELECT 1 FROM pg_database WHERE datname='${DB_NAME_VALUE}'" || true)"
if [ -z "${DB_EXISTS}" ]; then
    createdb -h "${DB_HOST_VALUE}" -p "${DB_PORT_VALUE}" -U "$(whoami)" -O "${DB_USER_VALUE}" "${DB_NAME_VALUE}"
fi

echo "初始化应用数据表..."
php -r "define('FEISHU_TREASURE', true); require '${PROJECT_DIR}/includes/config.php'; require '${PROJECT_DIR}/includes/database.php'; Database::getInstance();" >/dev/null

screen -S "${SCREEN_NAME}" -X quit >/dev/null 2>&1 || true
PHP_BIN="$(command -v php)"
screen -dmS "${SCREEN_NAME}" bash -lc 'cd "$1" && exec "$2" -S "$3" router.php >> "$4" 2>&1' bash "${PROJECT_DIR}" "${PHP_BIN}" "${HOST_VALUE}:${WEB_PORT}" "${PHP_LOG_FILE}"
sleep 1

PHP_PID="$(lsof -nP -iTCP:"${WEB_PORT}" -sTCP:LISTEN -t | head -n 1 || true)"
if [ -z "${PHP_PID}" ]; then
    echo "PHP 服务启动失败，请查看：${PHP_LOG_FILE}"
    read -r -p "按回车退出..."
    exit 1
fi
echo "${PHP_PID}" > "${PHP_PID_FILE}"

FRONT_URL="${SITE_URL_VALUE}/"
ADMIN_URL="${SITE_URL_VALUE}/${ADMIN_BASE_PATH_VALUE}/"

open "${ADMIN_URL}" >/dev/null 2>&1 || true

echo ""
echo "本地系统已启动"
echo "发布前台：${FRONT_URL}"
echo "后台系统：${ADMIN_URL}"
echo "数据库：${DB_USER_VALUE}@${DB_HOST_VALUE}:${DB_PORT_VALUE}/${DB_NAME_VALUE}"
echo "默认账号：admin"
echo "默认密码：change-this-before-delivery"
echo ""
echo "这个窗口可以关闭，服务会在后台继续运行。"
read -r -p "按回车关闭窗口..."
