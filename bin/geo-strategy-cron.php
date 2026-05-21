<?php
/**
 * 每周自动生成内容策略并推送飞书
 * Cron: 0 8 * * 1  每周一早8点运行
 */
define('FEISHU_TREASURE', true);
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/database_admin.php';
require_once __DIR__ . '/../includes/citation_simulator_service.php';

function sc_log(string $msg): void {
    echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
}

// 获取所有客户
try {
    $stmt = $db->query("
        SELECT DISTINCT k.customer_id,
               COALESCE((SELECT fact_value FROM geo_brand_facts WHERE customer_id=k.customer_id AND fact_key='brand_name' LIMIT 1), k.customer_id) AS brand_name
        FROM geo_monitor_keywords k ORDER BY brand_name
    ");
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    sc_log('读取客户列表失败: ' . $e->getMessage());
    exit(1);
}

if (empty($customers)) {
    sc_log('没有客户，退出');
    exit(0);
}

// 获取飞书 webhook
$stmtFs = $db->prepare("SELECT setting_value FROM site_settings WHERE setting_key = 'feishu_monitor_webhook' LIMIT 1");
$stmtFs->execute();
$webhookUrl = trim((string)($stmtFs->fetchColumn() ?: ''));

$apiKey = citation_simulator_get_provider_key('deepseek', 'api_key');
if ($apiKey === '') {
    sc_log('DeepSeek API Key 未配置，退出');
    exit(1);
}

sc_log('开始为 ' . count($customers) . ' 个客户生成本周策略');

foreach ($customers as $customer) {
    $cid   = $customer['customer_id'];
    $cname = $customer['brand_name'];
    sc_log("处理客户: {$cname}");

    // 读品牌信息
    $stmtFacts = $db->prepare("SELECT fact_key, fact_value FROM geo_brand_facts WHERE customer_id = ?");
    $stmtFacts->execute([$cid]);
    $facts = [];
    foreach ($stmtFacts->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $facts[$row['fact_key']] = $row['fact_value'];
    }
    $brandName      = $facts['brand_name']     ?? $cid;
    $masterSentence = $facts['master_sentence'] ?? '';
    $coreServices   = $facts['core_services']   ?? '';
    $industry       = $facts['industry']        ?? '';

    // 读竞品
    $stmtComp = $db->prepare("SELECT competitor FROM geo_customer_competitors WHERE customer_id = ? AND enabled = TRUE");
    $stmtComp->execute([$cid]);
    $competitors = $stmtComp->fetchAll(PDO::FETCH_COLUMN);

    // 诊断短板信号
    $stmtDiag = $db->prepare("
        SELECT s.signal_key, d.name, s.score
        FROM geo_diagnosis_signal_scores s
        JOIN geo_diagnosis_signal_definitions d ON d.signal_key = s.signal_key
        WHERE s.diagnosis_id = (
            SELECT r.id FROM geo_diagnosis_runs r
            JOIN geo_diagnosis_brands b ON b.id = r.brand_id
            WHERE b.name = ?
            ORDER BY r.created_at DESC LIMIT 1
        )
        ORDER BY s.score ASC
    ");
    $stmtDiag->execute([$brandName]);
    $weakSignals = $stmtDiag->fetchAll(PDO::FETCH_ASSOC);

    // 失守关键词（近7天提及率<30%）
    $stmtKw = $db->prepare("
        SELECT query_text,
               ROUND(100.0 * SUM(CASE WHEN brand_mentioned THEN 1 ELSE 0 END) / COUNT(*), 1) AS mention_rate
        FROM geo_monitor_records
        WHERE customer_id = ? AND queried_at >= CURRENT_DATE - INTERVAL '7 days'
        GROUP BY query_text
        HAVING ROUND(100.0 * SUM(CASE WHEN brand_mentioned THEN 1 ELSE 0 END) / COUNT(*), 1) < 30
        ORDER BY mention_rate ASC LIMIT 8
    ");
    $stmtKw->execute([$cid]);
    $weakKeywords = $stmtKw->fetchAll(PDO::FETCH_ASSOC);

    // 竞品超越告警（近7天）
    $stmtAlert = $db->prepare("
        SELECT keyword, competitor_name, brand_rate, competitor_rate
        FROM geo_monitor_alerts
        WHERE customer_id = ? AND alerted_at >= CURRENT_DATE - INTERVAL '7 days'
        ORDER BY (competitor_rate - brand_rate) DESC LIMIT 5
    ");
    $stmtAlert->execute([$cid]);
    $alerts = $stmtAlert->fetchAll(PDO::FETCH_ASSOC);

    $weakSignalText = empty($weakSignals) ? '暂无诊断数据' :
        implode('、', array_map(fn($s) => "{$s['name']}({$s['score']}分)", $weakSignals));
    $weakKwText = empty($weakKeywords) ? '暂无' :
        implode("\n", array_map(fn($k) => "- 「{$k['query_text']}」提及率{$k['mention_rate']}%", $weakKeywords));
    $alertText = empty($alerts) ? '暂无' :
        implode("\n", array_map(fn($a) => "- 「{$a['keyword']}」竞品{$a['competitor_name']}超出" . round($a['competitor_rate'] - $a['brand_rate'], 1) . "pp", $alerts));
    $compText = empty($competitors) ? '暂无' : implode('、', $competitors);

    $prompt = <<<PROMPT
你是专业的GEO内容策略师。请为以下品牌生成一份4周内容日历策略。

## 品牌信息
- 品牌名：{$brandName}
- 定位：{$masterSentence}
- 核心服务：{$coreServices}
- 行业：{$industry}

## 诊断短板（需重点补强）
{$weakSignalText}

## 失守关键词（品牌提及率低于30%）
{$weakKwText}

## 竞品超越情况
{$alertText}

## 主要竞品
{$compText}

## 任务
请生成一份4周内容日历，格式为严格的Markdown表格，每行一篇文章：

| 周次 | 发布平台 | 目标关键词 | 文章角度 | 内容格式 | 优先级 |
|------|---------|-----------|---------|---------|-------|

要求：
1. 共12-16篇文章，覆盖4周
2. 平台选择：知乎、今日头条、搜狐号、微信公众号、小红书（选最适合的）
3. 每篇文章针对一个失守关键词或短板信号
4. 文章角度要有差异化，能让AI优先引用{$brandName}
5. 表格后附3条执行建议

只输出表格和执行建议，不要其他前言。
PROMPT;

    $payload = json_encode([
        'model'       => 'deepseek-chat',
        'messages'    => [['role' => 'user', 'content' => $prompt]],
        'max_tokens'  => 3000,
        'temperature' => 0.6,
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init('https://api.deepseek.com/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
    ]);
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200) {
        sc_log("{$cname} DeepSeek生成失败，HTTP {$code}");
        continue;
    }

    $data       = json_decode($raw, true);
    $strategyMd = $data['choices'][0]['message']['content'] ?? '';
    if ($strategyMd === '') {
        sc_log("{$cname} 生成内容为空，跳过");
        continue;
    }

    sc_log("{$cname} 策略生成完成，准备推飞书");

    if ($webhookUrl === '') {
        sc_log('未配置飞书webhook，跳过推送');
        continue;
    }

    $fsPayload = json_encode([
        'msg_type' => 'post',
        'content'  => [
            'post' => [
                'zh_cn' => [
                    'title'   => "📋 本周内容日历 · {$brandName} · " . date('Y-m-d'),
                    'content' => [
                        [['tag' => 'text', 'text' => "📌 基于近7天监测数据自动生成\n\n"]],
                        [['tag' => 'text', 'text' => $strategyMd]],
                        [['tag' => 'text', 'text' => "\n\n---\n✅ 请按日历逐篇生成文章：http://1.14.206.128/dl-console/geo-content.php?customer={$cid}"]],
                    ],
                ],
            ],
        ],
    ], JSON_UNESCAPED_UNICODE);

    $ch2 = curl_init($webhookUrl);
    curl_setopt_array($ch2, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $fsPayload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    ]);
    $r2   = curl_exec($ch2);
    $code2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
    curl_close($ch2);

    if ($code2 === 200) {
        sc_log("{$cname} 策略已推送到飞书 ✅");
    } else {
        sc_log("{$cname} 飞书推送失败，HTTP {$code2}");
    }

    // 多客户时稍等，避免API限流
    sleep(3);
}

sc_log('本周策略生成完成');
