<?php
/**
 * 客户月报 Token 访问页（无需登录）
 * 路径：/client/report-view.php?token=xxx
 */
define('FEISHU_TREASURE', true);
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database_admin.php';

$token = trim($_GET['token'] ?? '');

if (!$token) {
    http_response_code(403);
    die('<p style="font-family:sans-serif;text-align:center;margin-top:80px;color:#666">链接无效</p>');
}

// 验证 token
$row = null;
try {
    $stmt = $db->prepare("SELECT t.*, c.name as customer_name FROM geo_report_tokens t JOIN customers c ON c.customer_id=t.customer_id WHERE t.token=? AND t.expires_at > NOW() LIMIT 1");
    $stmt->execute([$token]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

if (!$row) {
    http_response_code(403);
    die('<p style="font-family:sans-serif;text-align:center;margin-top:80px;color:#666">链接已失效或不存在，请联系您的GEO顾问获取新链接</p>');
}

// 记录访问时间
try {
    $db->prepare("UPDATE geo_report_tokens SET accessed_at=NOW() WHERE token=?")->execute([$token]);
} catch (Throwable $e) {}

// 伪造 session 以复用 report-export.php 逻辑
session_start();
$_SESSION['client_id']   = $row['customer_id'];
$_SESSION['client_name'] = $row['customer_name'];

// 设置月份参数
$_GET['year']  = $row['report_year'];
$_GET['month'] = $row['report_month'];

// 直接 include report-export.php（已有完整输出逻辑）
require __DIR__ . '/report-export.php';
