#!/bin/bash
# ============================================================
# GEO System - 阿里云一键部署脚本
# 在阿里云服务器上执行此脚本
# 用法: bash deploy-alicloud.sh
# ============================================================

set -euo pipefail

echo "========================================="
echo "  GEO System 阿里云部署脚本"
echo "========================================="

# ---- 1. 安装Docker ----
if ! command -v docker &> /dev/null; then
    echo "[1/6] 安装Docker..."
    curl -fsSL https://get.docker.com | sh
    systemctl enable docker
    systemctl start docker
    echo "[OK] Docker安装完成"
else
    echo "[1/6] Docker已安装，跳过"
fi

# ---- 2. 安装Docker Compose ----
if ! command -v docker-compose &> /dev/null && ! docker compose version &> /dev/null; then
    echo "[2/6] 安装Docker Compose..."
    curl -L "https://github.com/docker/compose/releases/latest/download/docker-compose-$(uname -s)-$(uname -m)" -o /usr/local/bin/docker-compose
    chmod +x /usr/local/bin/docker-compose
    echo "[OK] Docker Compose安装完成"
else
    echo "[2/6] Docker Compose已安装，跳过"
fi

# ---- 3. 创建部署目录 ----
DEPLOY_DIR="/opt/geo-system"
echo "[3/6] 创建部署目录: ${DEPLOY_DIR}"
mkdir -p ${DEPLOY_DIR}
mkdir -p ${DEPLOY_DIR}/data/browser-profiles
mkdir -p ${DEPLOY_DIR}/data/logs

# ---- 4. 生成.env文件 ----
echo "[4/6] 生成配置文件..."
if [ ! -f ${DEPLOY_DIR}/.env ]; then
    # 生成随机密钥
    APP_SECRET=$(openssl rand -hex 32)
    DB_PASS=$(openssl rand -hex 16)

    cat > ${DEPLOY_DIR}/.env << EOF
# GEO System 部署配置
HOST_PORT=18081
DB_DRIVER=pgsql
DB_HOST=postgres
DB_PORT=5432
DB_NAME=geo_system
DB_USER=geo_user
DB_PASSWORD=${DB_PASS}
SITE_URL=http://$(curl -s ifconfig.me):18081
ADMIN_BASE_PATH=dl-console
ADMIN_BOOTSTRAP_USERNAME=admin
ADMIN_BOOTSTRAP_PASSWORD=$(openssl rand -hex 8)
APP_SECRET_KEY=${APP_SECRET}
TZ=Asia/Shanghai
EOF
    echo "[OK] .env文件已生成"
    echo ""
    echo "========================================="
    echo "  重要：请保存以下信息"
    echo "========================================="
    cat ${DEPLOY_DIR}/.env
    echo "========================================="
    echo ""
else
    echo "[OK] .env文件已存在，跳过"
fi

# ---- 5. 复制项目文件 ----
echo "[5/6] 复制项目文件..."
SCRIPT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
rsync -av --exclude='node_modules' --exclude='.local' --exclude='data' \
    "${SCRIPT_DIR}/" "${DEPLOY_DIR}/"
echo "[OK] 项目文件已复制"

# ---- 6. 启动服务 ----
echo "[6/6] 启动Docker服务..."
cd ${DEPLOY_DIR}

# 使用docker compose或docker-compose
if docker compose version &> /dev/null; then
    docker compose up -d --build
else
    docker-compose up -d --build
fi

echo ""
echo "========================================="
echo "  部署完成！"
echo "========================================="
echo ""
echo "  公网访问地址: http://$(curl -s ifconfig.me):18081"
echo "  后台地址:     http://$(curl -s ifconfig.me):18081/dl-console/"
echo ""
echo "  查看日志: docker logs -f geo-app"
echo "  停止服务: cd ${DEPLOY_DIR} && docker compose down"
echo "  重启服务: cd ${DEPLOY_DIR} && docker compose restart"
echo ""
