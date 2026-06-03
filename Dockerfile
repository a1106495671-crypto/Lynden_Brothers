FROM php:8.2-cli

# 用 mlocati/php-extension-installer 下载预编译扩展，无需现场编译
ADD https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/
RUN chmod +x /usr/local/bin/install-php-extensions \
    && install-php-extensions pdo_pgsql mbstring curl zip

# 安装 Node.js + Playwright（知乎发布脚本用）
RUN apt-get update && apt-get install -y nodejs npm \
    && npm install -g playwright \
    && npx playwright install --with-deps chromium \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# 安装 PHP 内置服务器需要的 router
WORKDIR /app

# Align PHP upload limits with the knowledge-base import UI.
COPY docker/php-upload.ini /usr/local/etc/php/conf.d/zz-geo-upload.ini

# 复制项目文件
COPY . .

# 设置权限
RUN chmod +x bin/*.php scripts/*.sh 2>/dev/null || true
RUN mkdir -p data/browser-profiles data/logs

EXPOSE 18081 18082

RUN chmod +x start-servers.sh
CMD ["bash", "start-servers.sh"]
