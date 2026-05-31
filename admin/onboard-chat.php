<?php
/**
 * 兼容入口：对话式品牌入驻已下线，保留旧地址并重定向到品牌入驻自动化。
 */

define('FEISHU_TREASURE', true);
session_start();

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

admin_redirect('automation-workflow.php');
exit;
