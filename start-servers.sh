#!/bin/bash
# 启动两个 PHP 服务器：前台 + 后台

# 前台文章页 - 端口 18082
php -S 0.0.0.0:18082 router-front.php &

# 后台管理 - 端口 18081
php -S 0.0.0.0:18081 router.php &

# 保持容器运行，等待所有后台进程
wait
