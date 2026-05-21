<?php
/**
 * 月报自动推送 - 每月1日自动运行
 * cron: 0 9 1 * * /usr/bin/php8.3 /www/wwwroot/geo-system/bin/geo-monthly-push.php
 */
define('FEISHU_TREASURE', true);
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database_admin.php';

// 上个月的年月
$year  = (int)date('Y', strtotime('first day of last month'));
$month = (int)date('n', strtotime('first day of last month'));
$monthStart = sprintf('%04d-%02d-01', $year, $month);
$monthEnd   = date('Y-m-t', strtotime($monthStart));
$monthLabel = $year . '年' . $month . '月';

// 建表：报告token
try {
    $db->exec("CREATE TABLE IF NOT EXISTS geo_report_tokens (
        id SERIAL PRIMARY KEY,
        customer_id VARCHAR(100),
        token VARCHAR(64) UNIQUE,
        report_year INT,
        report_month INT,
        created_at TIMESTAMP DEFAULT NOW(),
        expires_at TIMESTAMP,
        accessed_at TIMESTAMP
    )");
} catch (Throwable $e) {}

// 取有客户门户账号 + 有飞书webhook 的客户
$customers = [];
try {
    $customers = $db->query("
        SELECT c.customer_id, c.name, c.feishu_webhook
        FROM customers c
        JOIN geo_client_credentials gc ON gc.customer_id = c.customer_id
        WHERE c.feishu_webhook IS NOT NULL AND c.feishu_webhook != ''
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    // fallback：无feishu_webhook列时推送到全局webhook
    try {
        $customers = $db->query("
            SELECT c.customer_id, c.name, NULL as feishu_webhook
            FROM customers c
            JOIN geo_client_credentials gc ON gc.customer_id = c.customer_id
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e2) {}
}

$globalWebhook = defined('FEISHU_WEBHOOK') ? FEISHU_WEBHOOK : '';
$siteUrl = defined('SITE_URL') ? rtrim(SITE_URL, '/') : 'http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');

$pushed = 0;
$errors = 0;

foreach ($customers as $c) {
    $cid     = $c['customer_id'];
    $name    = $c['name'];
    $webhook = $c['feishu_webhook'] ?: $globalWebhook;

    if (!$webhook) continue;

    // 生成 token（有效期30天）
    $token = bin2hex(random_bytes(24));
    $expires = date('Y-m-d H:i:s', strtotime('+30 days'));
    try {
        $db->prepare("INSERT INTO geo_report_tokens (customer_id, token, report_year, report_month, expires_at)
                      VALUES (?,?,?,?,?)
                      ON CONFLICT (token) DO NOTHING")
           ->execute([$cid, $token, $year, $month, $expires]);
    } catch (Throwable $e) { continue; }

    $reportUrl = $siteUrl . '/client/report-view.php?token=' . $token;

    // 取核心指标
    $geoScore = null; $avgRate = 0; $articleCount = 0; $alertHigh = 0;
    try {
        $stmt = $db->prepare("SELECT overall_score FROM geo_diagnoses WHERE customer_id=? AND created_at BETWEEN ? AND ? ORDER BY created_at DESC LIMIT 1");
        $stmt->execute([$cid, $monthStart, $monthEnd . ' 23:59:59']);
        $row = $stmt->fetchColumn();
        $geoScore = $row !== false ? (int)$row : null;
    } catch (Throwable $e) {}

    try {
        $stmt = $db->prepare("SELECT ROUND(100.0*SUM(CASE WHEN brand_mentioned THEN 1 ELSE 0 END)/NULLIF(COUNT(*),0),1) FROM geo_monitor_records WHERE customer_id=? AND queried_at BETWEEN ? AND ?");
        $stmt->execute([$cid, $monthStart, $monthEnd . ' 23:59:59']);
        $avgRate = (float)($stmt->fetchColumn() ?: 0);
    } catch (Throwable $e) {}

    try {
        $stmt = $db->prepare("SELECT COUNT(*) FROM articles WHERE customer_id=? AND status='published' AND created_at BETWEEN ? AND ?");
        $stmt->execute([$cid, $monthStart, $monthEnd . ' 23:59:59']);
        $articleCount = (int)$stmt->fetchColumn();
    } catch (Throwable $e) {}

    try {
        $stmt = $db->prepare("SELECT COUNT(*) FROM geo_monitor_alerts WHERE customer_id=? AND level='high' AND alerted_at BETWEEN ? AND ?");
        $stmt->execute([$cid, $monthStart, $monthEnd . ' 23:59:59']);
        $alertHigh = (int)$stmt->fetchColumn();
    } catch (Throwable $e) {}

    // 评分等级
    $scoreText = $geoScore !== null ? $geoScore . '分' : '本月未诊断';
    $scoreMark = '';
    if ($geoScore !== null) {
        if ($geoScore >= 80) $scoreMark = '🟢 优秀';
        elseif ($geoScore >= 65) $scoreMark = '🔵 良好';
        elseif ($geoScore >= 50) $scoreMark = '🟡 待提升';
        else $scoreMark = '🔴 需优化';
    }

    // 构建飞书消息
    $content = "📊 **{$name} · {$monthLabel}GEO月报**\n\n";
    $content .= "**GEO综合评分：** {$scoreText}" . ($scoreMark ? "  {$scoreMark}" : '') . "\n";
    $content .= "**AI平均提及率：** {$avgRate}%\n";
    $content .= "**本月发布文章：** {$articleCount} 篇\n";
    if ($alertHigh > 0) {
        $content .= "**高级告警：** ⚠️ {$alertHigh} 条（需关注）\n";
    }
    $content .= "\n📎 **查看完整月报：**\n{$reportUrl}\n";
    $content .= "\n_链接有效期30天，无需登录直接查看_";

    $payload = json_encode([
        'msg_type' => 'text',
        'content'  => ['text' => $content],
    ]);

    $ch = curl_init($webhook);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code === 200) {
        $pushed++;
        echo "[OK] {$name} 月报推送成功\n";
    } else {
        $errors++;
        echo "[ERR] {$name} 推送失败 HTTP {$code}: {$resp}\n";
    }
}

echo "完成：推送 {$pushed} 条，失败 {$errors} 条\n";
