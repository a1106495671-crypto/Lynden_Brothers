FROM php:8.2-cli

# 安装系统依赖
RUN apt-get update && apt-get install -y \
    libpq-dev \
    libcurl4-openssl-dev \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    unzip \
    curl \
    && docker-php-ext-install pdo_pgsql mbstring curl zip \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# 安装 Node.js (用于知乎发布脚本)
RUN curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
    && apt-get install -y nodejs \
    && npm install -g playwright \
    && npx playwright install --with-deps chromium

# 安装 PHP 内置服务器需要的 router
WORKDIR /app

# 复制项目文件
COPY . .

# 设置权限
RUN chmod +x bin/*.php scripts/*.sh 2>/dev/null || true
RUN mkdir -p data/browser-profiles data/logs

EXPOSE 18081

CMD ["php", "-S", "0.0.0.0:18081", "router.php"]
