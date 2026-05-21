#!/bin/bash
# 交付版内容运营系统 - 启动脚本

clear
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "  🚀 交付版内容运营系统"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo ""

# 获取脚本所在目录
SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" &> /dev/null && pwd )"
cd "$SCRIPT_DIR"

# 读取 .env，让启动脚本与实例配置保持一致
if [ -f ".env" ]; then
    set -a
    . ".env"
    set +a
fi

# 颜色定义
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

HOST_VALUE="${HOST:-localhost}"
HOST_PORT_VALUE="${HOST_PORT:-8080}"
ADMIN_BASE_PATH_VALUE="${ADMIN_BASE_PATH:-dl-console}"
ADMIN_BASE_PATH_VALUE="${ADMIN_BASE_PATH_VALUE#/}"
SITE_NAME_VALUE="${SITE_NAME:-董逻辑交付后台}"
SITE_URL_VALUE="${SITE_URL:-http://${HOST_VALUE}:${HOST_PORT_VALUE}}"

# 1. 检查PHP是否安装
echo "📋 环境检查..."
echo ""

if ! command -v php &> /dev/null; then
    echo -e "${RED}❌ 错误：未找到PHP${NC}"
    echo "请先安装PHP 7.4或更高版本"
    echo ""
    echo "macOS安装方法："
    echo "  brew install php"
    echo ""
    exit 1
fi

# 显示PHP版本
PHP_VERSION=$(php --version | head -n 1)
echo -e "${GREEN}✅ PHP版本：${NC}$PHP_VERSION"

# 2. 检查PostgreSQL扩展
if ! php -m | grep -q "pdo_pgsql"; then
    echo -e "${RED}❌ 错误：未找到PostgreSQL扩展${NC}"
    echo "请安装pdo_pgsql扩展"
    exit 1
fi
echo -e "${GREEN}✅ PostgreSQL扩展：${NC}已安装"

# 3. 检查必要的PHP扩展
REQUIRED_EXTENSIONS=("json" "mbstring" "session")
for ext in "${REQUIRED_EXTENSIONS[@]}"; do
    if php -m | grep -q "$ext"; then
        echo -e "${GREEN}✅ $ext扩展：${NC}已安装"
    else
        echo -e "${YELLOW}⚠️  $ext扩展：${NC}未安装（可选）"
    fi
done

echo ""

# 4. 检查PostgreSQL环境变量
DB_HOST_VALUE="${DB_HOST:-127.0.0.1}"
DB_PORT_VALUE="${DB_PORT:-5432}"
DB_NAME_VALUE="${DB_NAME:-geo_system}"
DB_USER_VALUE="${DB_USER:-geo_user}"
echo -e "${GREEN}✅ 数据库配置：${NC}${DB_USER_VALUE}@${DB_HOST_VALUE}:${DB_PORT_VALUE}/${DB_NAME_VALUE}"

# 5. 检查关键目录
DIRS=("data/backups" "logs" "uploads/images" "uploads/knowledge")
for dir in "${DIRS[@]}"; do
    if [ ! -d "$dir" ]; then
        mkdir -p "$dir"
        echo -e "${GREEN}✅ 创建目录：${NC}$dir"
    fi
done

# 6. 检查目录权限
if [ ! -w "data" ]; then
    echo -e "${YELLOW}⚠️  警告：${NC}data 目录不可写"
    echo "   执行: chmod -R 755 data"
fi

if [ ! -w "logs" ]; then
    echo -e "${YELLOW}⚠️  警告：${NC}logs 目录不可写"
    echo "   执行: chmod -R 755 logs"
fi

echo ""
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "  🌐 服务器信息"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo ""
echo -e "${BLUE}🏠 前台首页：${NC}${SITE_URL_VALUE}"
echo -e "${BLUE}⚙️  管理后台：${NC}${SITE_URL_VALUE}/${ADMIN_BASE_PATH_VALUE}/"
echo -e "${BLUE}📊 系统诊断：${NC}${SITE_URL_VALUE}/${ADMIN_BASE_PATH_VALUE}/system_diagnostics.php"
echo ""
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo ""
echo -e "${GREEN}💡 提示：${NC}"
echo "   • 当前站点名: ${SITE_NAME_VALUE}"
echo "   • 首次管理员由 ADMIN_BOOTSTRAP_USERNAME / ADMIN_BOOTSTRAP_PASSWORD 控制"
echo "   • 交付客户前请修改 ADMIN_BOOTSTRAP_PASSWORD 和 APP_SECRET_KEY"
echo "   • 按 Ctrl+C 停止服务器"
echo "   • 日志文件位于 logs/ 目录"
echo ""
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo ""
echo "🚀 正在启动服务器..."
echo ""

# 启动服务器（使用路由器支持URL重写）
php -S "${HOST_VALUE}:${HOST_PORT_VALUE}" router.php
