<?php

// AI_INSIGHTS_PATCHED
function geo_weekly_ai_insight(array $metricText, string $apiKey): string {
    if (!$apiKey) return '';
    $prompt = "你是GEO顾问，请根据以下客户本周数据，用中文写出：\n1. 本周最重要的1-2个发现（30字内每条）\n2. 下周优先行动建议（3条，每条20字内，以「・」开头）\n\n数据摘要：\n" . implode("\n", $metricText) . "\n\n直接输出，不要多余的说明。";
    $payload = json_encode(['model'=>'deepseek-chat','messages'=>[['role'=>'user','content'=>$prompt]],'max_tokens'=>300,'temperature'=>0.5]);
    $ch = curl_init('https://api.deepseek.com/v1/chat/completions');
    
    // Build metric summary for AI
    $metricLines = [];
    if (!empty($thisWeekRate)) $metricLines[] = '平均提及率：' . $thisWeekRate . '% (上周:' . ($lastWeekRate ?? '无') . '%)';
    if (!empty($worstKws)) {
        foreach ($worstKws as $kw) $metricLines[] = '低提及词「' . $kw['query_text'] . '」' . $kw['rate'] . '%';
    }
    if (!empty($highAlerts)) $metricLines[] = '高级告警：' . $highAlerts . '条';
    if (!empty($articlesThisWeek)) $metricLines[] = '本周发文：' . $articlesThisWeek . '篇';
    $aiKey = citation_simulator_get_provider_key('deepseek', 'api_key');
    $aiInsight = (!empty($metricLines) && $aiKey) ? geo_weekly_ai_insight($metricLines, $aiKey) : '';
    if ($aiInsight) {
        $reportText .= "\n\n🤖 AI洞察：\n" . $aiInsight;
    }

    curl_setopt_array($ch, [CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>$payload, CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>20, CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer '.$apiKey]]);
    $resp = curl_exec($ch);
    curl_close($ch);
    if (!$resp) return '';
    $json = json_decode($resp, true);
    return trim($json['choices'][0]['message']['content'] ?? '');
}

define('FEISHU_TREASURE', true);
require_once '/www/wwwroot/geo-system/includes/config.php';
require_once '/www/wwwroot/geo-system/includes/database_admin.php';

$today    = date('Y-m-d');
$weekAgo  = date('Y-m-d', strtotime('-7 days'));
$week2Ago = date('Y-m-d', strtotime('-14 days'));

// Get all active customers
$customers = $db->query("SELECT DISTINCT customer_id FROM geo_monitor_records WHERE queried_at >= CURRENT_DATE - INTERVAL '7 days'")->fetchAll(PDO::FETCH_COLUMN);

if (empty($customers)) {
    echo "无监测数据，跳过周报\n";
    exit(0);
}

$stmtWebhook = $db->prepare("SELECT setting_value FROM site_settings WHERE setting_key='feishu_monitor_webhook' LIMIT 1");
$stmtWebhook->execute();
$webhookUrl = trim((string)($stmtWebhook->fetchColumn() ?: ''));

foreach ($customers as $cid) {
    // Brand name
    $stmtBn = $db->prepare("SELECT fact_value FROM geo_brand_facts WHERE customer_id=? AND fact_key='brand_name' LIMIT 1");
    $stmtBn->execute([$cid]);
    $brandName = (string)($stmtBn->fetchColumn() ?: $cid);

    // This week avg mention rate
    $stmtThis = $db->prepare("SELECT ROUND(100.0*SUM(CASE WHEN brand_mentioned THEN 1 ELSE 0 END)/COUNT(*),1) as rate, COUNT(*) as total FROM geo_monitor_records WHERE customer_id=? AND queried_at >= CURRENT_DATE - INTERVAL '7 days'");
    $stmtThis->execute([$cid]);
    $thisWeek = $stmtThis->fetch(PDO::FETCH_ASSOC);
    $thisRate = (float)($thisWeek['rate'] ?? 0);
    $thisTotal = (int)($thisWeek['total'] ?? 0);
    if ($thisTotal === 0) continue;

    // Last week avg mention rate
    $stmtLast = $db->prepare("SELECT ROUND(100.0*SUM(CASE WHEN brand_mentioned THEN 1 ELSE 0 END)/COUNT(*),1) as rate FROM geo_monitor_records WHERE customer_id=? AND queried_at >= CURRENT_DATE - INTERVAL '14 days' AND queried_at < CURRENT_DATE - INTERVAL '7 days'");
    $stmtLast->execute([$cid]);
    $lastRate = (float)($stmtLast->fetchColumn() ?: 0);
    $delta    = round($thisRate - $lastRate, 1);
    $deltaStr = ($delta >= 0 ? '+' : '') . $delta . 'pp';
    $trend    = $delta > 2 ? '📈' : ($delta < -2 ? '📉' : '➡️');

    // Per-platform breakdown
    $stmtPlatform = $db->prepare("SELECT provider_key, ROUND(100.0*SUM(CASE WHEN brand_mentioned THEN 1 ELSE 0 END)/COUNT(*),1) as rate FROM geo_monitor_records WHERE customer_id=? AND queried_at >= CURRENT_DATE - INTERVAL '7 days' GROUP BY provider_key ORDER BY rate DESC");
    $stmtPlatform->execute([$cid]);
    $platforms = $stmtPlatform->fetchAll(PDO::FETCH_ASSOC);
    $platformNames = ['kimi'=>'Kimi','deepseek'=>'DeepSeek','tongyi'=>'通义','wenxin'=>'文心','doubao'=>'豆包','yuanbao'=>'元宝'];
    $platformLines = array_map(fn($p) => sprintf('%s %s%%', $platformNames[$p['provider_key']] ?? $p['provider_key'], $p['rate']), $platforms);

    // Keyword top3 worst
    $stmtKw = $db->prepare("SELECT query_text, ROUND(100.0*SUM(CASE WHEN brand_mentioned THEN 1 ELSE 0 END)/COUNT(*),1) as rate FROM geo_monitor_records WHERE customer_id=? AND queried_at >= CURRENT_DATE - INTERVAL '7 days' GROUP BY query_text ORDER BY rate ASC LIMIT 3");
    $stmtKw->execute([$cid]);
    $weakKws = $stmtKw->fetchAll(PDO::FETCH_ASSOC);
    $kwLines = array_map(fn($k) => "  · 「{$k['query_text']}」{$k['rate']}%", $weakKws);

    // Alert count this week
    $stmtAlert = $db->prepare("SELECT level, COUNT(*) as cnt FROM geo_monitor_alerts WHERE customer_id=? AND alerted_at >= CURRENT_DATE - INTERVAL '7 days' GROUP BY level");
    $stmtAlert->execute([$cid]);
    $alertCounts = [];
    foreach ($stmtAlert->fetchAll(PDO::FETCH_ASSOC) as $a) $alertCounts[$a['level']] = $a['cnt'];
    $alertLine = empty($alertCounts) ? '无告警 ✅' :
        implode('  ', array_map(fn($l,$c) => strtoupper($l)."×{$c}", array_keys($alertCounts), $alertCounts));

    // Content published this week
    $stmtArt = $db->prepare("SELECT COUNT(*) FROM articles WHERE customer_id=? AND created_at >= CURRENT_DATE - INTERVAL '7 days'");
    $stmtArt->execute([$cid]);
    $artCount = (int)$stmtArt->fetchColumn();

    $weekNum   = date('W');
    $dateRange = date('m/d', strtotime('-7 days')) . '–' . date('m/d');

    $reportText = <<<REPORT
📊 第{$weekNum}周 GEO 周报 · {$brandName}
🗓 {$dateRange}

━━━ 核心指标 ━━━
{$trend} 本周 AI 提及率：{$thisRate}%（{$deltaStr} vs 上周）
📡 监测查询：{$thisTotal} 次
✍️ 本周发布文章：{$artCount} 篇

━━━ 平台分布 ━━━
{implode('  |  ', $platformLines)}

━━━ 待提升关键词（提及率最低）━━━
{implode("\n", $kwLines ?: ['  暂无数据'])}

━━━ 本周告警 ━━━
{$alertLine}

━━━ 下周建议 ━━━
· 重点补强提及率低于30%的关键词内容
· 登录后台查看执行路线图获取具体行动项
REPORT;

    echo $reportText . "\n\n";

    // Push to Feishu
    if ($webhookUrl !== '') {
        $fsPayload = json_encode([
            'msg_type' => 'post',
            'content'  => [
                'post' => [
                    'zh_cn' => [
                        'title'   => "📊 GEO周报 · {$brandName} · 第{$weekNum}周",
                        'content' => [[['tag' => 'text', 'text' => $reportText]]],
                    ],
                ],
            ],
        ], JSON_UNESCAPED_UNICODE);

        $ch = curl_init($webhookUrl);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $fsPayload, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_HTTPHEADER => ['Content-Type: application/json']]);
        $r = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        echo "飞书推送[{$brandName}]：" . ($code === 200 ? '✅ 成功' : "❌ 失败 HTTP {$code}") . "\n";
    }
}
