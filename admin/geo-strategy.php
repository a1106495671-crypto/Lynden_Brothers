<?php
/**
 * Step3 策略方案设计 - AI内容日历生成
 */
define('FEISHU_TREASURE', true);
session_start();
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/database_admin.php';
require_once __DIR__ . '/../includes/citation_simulator_service.php';
require_admin_login();

function gs_h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

// 获取所有有关键词的客户
$customers = [];
try {
    $stmt = $db->query("
        SELECT DISTINCT k.customer_id,
               COALESCE((SELECT fact_value FROM geo_brand_facts WHERE customer_id=k.customer_id AND fact_key='brand_name' LIMIT 1), k.customer_id) AS brand_name
        FROM geo_monitor_keywords k
        ORDER BY brand_name
    ");
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

$selectedCid = trim($_GET['customer'] ?? $_POST['customer'] ?? ($customers[0]['customer_id'] ?? ''));
$action      = $_POST['action'] ?? '';
$strategy    = null;
$strategyMd  = '';
$pushResult  = null;

if ($selectedCid !== '' && $action === 'generate') {
    // 1. 读品牌信息
    $stmtFacts = $db->prepare("SELECT fact_key, fact_value FROM geo_brand_facts WHERE customer_id = ?");
    $stmtFacts->execute([$selectedCid]);
    $facts = [];
    foreach ($stmtFacts->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $facts[$row['fact_key']] = $row['fact_value'];
    }
    $brandName      = $facts['brand_name']      ?? $selectedCid;
    $masterSentence = $facts['master_sentence']  ?? '';
    $coreServices   = $facts['core_services']    ?? '';
    $industry       = $facts['industry']         ?? '';

    // 2. 读竞品
    $stmtComp = $db->prepare("SELECT competitor FROM geo_customer_competitors WHERE customer_id = ? AND enabled = TRUE");
    $stmtComp->execute([$selectedCid]);
    $competitors = $stmtComp->fetchAll(PDO::FETCH_COLUMN);

    // 3. 诊断短板信号（分数 < 60）
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

    // 4. 失守关键词（近7天未提及 或 提及率低）
    $stmtKw = $db->prepare("
        SELECT query_text,
               ROUND(100.0 * SUM(CASE WHEN brand_mentioned THEN 1 ELSE 0 END) / COUNT(*), 1) AS mention_rate,
               COUNT(*) AS total
        FROM geo_monitor_records
        WHERE customer_id = ? AND queried_at >= CURRENT_DATE - INTERVAL '7 days'
        GROUP BY query_text
        HAVING ROUND(100.0 * SUM(CASE WHEN brand_mentioned THEN 1 ELSE 0 END) / COUNT(*), 1) < 30
        ORDER BY mention_rate ASC
        LIMIT 8
    ");
    $stmtKw->execute([$selectedCid]);
    $weakKeywords = $stmtKw->fetchAll(PDO::FETCH_ASSOC);

    // 5. 竞品超越情况
    $stmtAlert = $db->prepare("
        SELECT keyword, competitor_name, brand_rate, competitor_rate
        FROM geo_monitor_alerts
        WHERE customer_id = ? AND alerted_at >= CURRENT_DATE - INTERVAL '7 days'
        ORDER BY (competitor_rate - brand_rate) DESC
        LIMIT 5
    ");
    $stmtAlert->execute([$selectedCid]);
    $alerts = $stmtAlert->fetchAll(PDO::FETCH_ASSOC);

    // 6. 构建 AI Prompt
    $weakSignalText = empty($weakSignals) ? '暂无诊断数据' :
        implode('、', array_map(fn($s) => "{$s['name']}({$s['score']}分)", $weakSignals));

    $weakKwText = empty($weakKeywords) ? '暂无' :
        implode("\n", array_map(fn($k) => "- 「{$k['query_text']}」提及率{$k['mention_rate']}%", $weakKeywords));

    $alertText = empty($alerts) ? '暂无' :
        implode("\n", array_map(fn($a) => "- 「{$a['keyword']}」竞品{$a['competitor_name']}超出".round($a['competitor_rate']-$a['brand_rate'],1)."pp", $alerts));

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

    // 7. 调用 DeepSeek
    $apiKey = citation_simulator_get_provider_key('deepseek', 'api_key');
    if ($apiKey !== '') {
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

        if ($code === 200) {
            $data       = json_decode($raw, true);
            $strategyMd = $data['choices'][0]['message']['content'] ?? '';
        }
    }

    $strategy = compact('brandName', 'weakSignals', 'weakKeywords', 'alerts', 'strategyMd');
}

// 推送飞书
if ($selectedCid !== '' && $action === 'push_feishu' && !empty($_POST['strategy_md'])) {
    $mdContent = $_POST['strategy_md'];
    $brandNamePush = $_POST['brand_name_push'] ?? $selectedCid;

    $stmtFsUrl = $db->prepare("SELECT setting_value FROM site_settings WHERE setting_key = 'feishu_monitor_webhook' LIMIT 1");
    $stmtFsUrl->execute();
    $webhookUrl = trim((string)($stmtFsUrl->fetchColumn() ?: ''));

    if ($webhookUrl !== '') {
        $payload = json_encode([
            'msg_type' => 'post',
            'content'  => [
                'post' => [
                    'zh_cn' => [
                        'title'   => "📋 内容策略日历 · {$brandNamePush} · " . date('Y-m-d'),
                        'content' => [[['tag' => 'text', 'text' => $mdContent]]],
                    ],
                ],
            ],
        ], JSON_UNESCAPED_UNICODE);

        $ch = curl_init($webhookUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        ]);
        $r    = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $pushResult = $code === 200 ? 'success' : 'fail';
    } else {
        $pushResult = 'no_webhook';
    }
    $strategyMd = $mdContent;
    $strategy   = ['brandName' => $brandNamePush, 'strategyMd' => $strategyMd];
}

// ── 批量入队 ────────────────────────────────────────────────────────────────
$batchResult = null;
if ($selectedCid !== '' && $action === 'batch_queue' && !empty($_POST['strategy_md'])) {
    $mdContent = $_POST['strategy_md'];
    // Ensure table
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS geo_content_queue (
            id SERIAL PRIMARY KEY,
            customer_id VARCHAR(100),
            week_num SMALLINT DEFAULT 1,
            platform VARCHAR(50),
            keyword VARCHAR(200),
            angle TEXT,
            content_format VARCHAR(50),
            priority VARCHAR(5) DEFAULT 'P1',
            status VARCHAR(20) DEFAULT 'pending',
            article_title TEXT,
            article_content TEXT,
            created_at TIMESTAMP DEFAULT NOW(),
            processed_at TIMESTAMP
        )");
    } catch (Throwable $e) {}

    // Parse markdown table rows (skip header and separator lines)
    $lines = explode("\n", $mdContent);
    $inserted = 0;
    $ins = $db->prepare("INSERT INTO geo_content_queue (customer_id, week_num, platform, keyword, angle, content_format, priority) VALUES (?,?,?,?,?,?,?)");
    foreach ($lines as $line) {
        $line = trim($line);
        if (!str_starts_with($line, '|') || str_contains($line, '---') || str_contains($line, '周次') || str_contains($line, '发布平台')) continue;
        $cols = array_map('trim', explode('|', trim($line, '|')));
        if (count($cols) < 5) continue;
        $weekRaw  = $cols[0] ?? '';
        $platform = $cols[1] ?? '';
        $keyword  = $cols[2] ?? '';
        $angle    = $cols[3] ?? '';
        $fmt      = $cols[4] ?? '';
        $pri      = $cols[5] ?? 'P1';
        if (!$platform || !$keyword) continue;
        preg_match('/\d+/', $weekRaw, $wm);
        $weekNum = (int)($wm[0] ?? 1);
        try {
            $ins->execute([$selectedCid, $weekNum, $platform, $keyword, $angle, $fmt, $pri]);
            $inserted++;
        } catch (Throwable $e) {}
    }
    $batchResult = $inserted;
    $strategyMd = $mdContent;
    $brandNamePush = $_POST['brand_name_push'] ?? $selectedCid;
    $strategy = ['brandName' => $brandNamePush, 'strategyMd' => $strategyMd];
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<title>策略方案设计 · Step3</title>
<script src="/admin/assets/js/tailwind.play-cdn.js"></script>
</head>
<body class="bg-gray-50 min-h-screen">
<div class="max-w-5xl mx-auto py-10 px-4">

  <div class="mb-6 flex items-center justify-between">
    <div>
      <h1 class="text-2xl font-bold text-gray-900">策略方案设计</h1>
      <p class="text-gray-500 mt-1">基于诊断短板 + 失守关键词，AI生成4周内容日历</p>
    </div>
    <a href="geo-monitor.php" class="text-sm text-gray-500 hover:text-gray-700">← 返回监测</a>
  </div>

  <!-- 客户选择 + 生成 -->
  <form method="POST" class="bg-white rounded-lg border border-gray-200 p-5 mb-6 flex items-end gap-4">
    <div class="flex-1">
      <label class="block text-sm font-medium text-gray-700 mb-1">选择客户</label>
      <select name="customer" class="w-full border border-gray-300 rounded px-3 py-2 text-sm">
        <?php foreach ($customers as $c): ?>
        <option value="<?= gs_h($c['customer_id']) ?>" <?= $c['customer_id']===$selectedCid?'selected':'' ?>><?= gs_h($c['brand_name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <button type="submit" name="action" value="generate" class="px-6 py-2 bg-blue-600 text-white text-sm font-medium rounded hover:bg-blue-700">
      🤖 生成内容策略
    </button>
  </form>

  <?php if ($pushResult !== null): ?>
  <div class="mb-4 p-3 rounded text-sm <?= $pushResult==='success' ? 'bg-green-50 text-green-700 border border-green-200' : 'bg-red-50 text-red-700 border border-red-200' ?>">
    <?= $pushResult==='success' ? '✅ 已推送到飞书' : ($pushResult==='no_webhook' ? '⚠️ 未配置飞书webhook' : '❌ 飞书推送失败') ?>
  </div>
  <?php endif; ?>

  <?php if ($strategy !== null): ?>

  <!-- 数据摘要 -->
  <div class="grid grid-cols-3 gap-4 mb-6">
    <div class="bg-white rounded-lg border border-gray-200 p-4">
      <div class="text-xs text-gray-500 mb-1">诊断短板</div>
      <div class="text-2xl font-bold text-orange-500"><?= count($strategy['weakSignals'] ?? []) ?></div>
      <div class="text-xs text-gray-400 mt-1">个信号得分低于基准</div>
    </div>
    <div class="bg-white rounded-lg border border-gray-200 p-4">
      <div class="text-xs text-gray-500 mb-1">失守关键词</div>
      <div class="text-2xl font-bold text-red-500"><?= count($strategy['weakKeywords'] ?? []) ?></div>
      <div class="text-xs text-gray-400 mt-1">个关键词提及率低于30%</div>
    </div>
    <div class="bg-white rounded-lg border border-gray-200 p-4">
      <div class="text-xs text-gray-500 mb-1">竞品超越</div>
      <div class="text-2xl font-bold text-red-600"><?= count($strategy['alerts'] ?? []) ?></div>
      <div class="text-xs text-gray-400 mt-1">条近7天竞品超越告警</div>
    </div>
  </div>

  <!-- 内容策略 -->
  <?php if (isset($batchResult)): ?>
  <div class="mb-4 rounded-xl <?= $batchResult > 0 ? 'bg-indigo-50 border-indigo-200 text-indigo-800' : 'bg-red-50 border-red-200 text-red-700' ?> border px-4 py-3 text-sm font-medium">
    <?php if ($batchResult > 0): ?>
      ✅ 已将 <strong><?= $batchResult ?></strong> 篇文章加入生成队列 →
      <a href="geo-content-queue.php?customer=<?= urlencode($selectedCid) ?>" class="underline">查看队列</a>
    <?php else: ?>
      ❌ 未能解析到有效行，请确认策略表格格式正确
    <?php endif; ?>
  </div>
  <?php endif; ?>
  <?php if (!empty($strategy['strategyMd'])): ?>
  <div class="bg-white rounded-lg border border-gray-200 p-6 mb-4">
    <div class="flex items-center justify-between mb-4">
      <h2 class="font-semibold text-gray-800">📋 4周内容日历 · <?= gs_h($strategy['brandName']) ?></h2>
      <span class="text-xs text-gray-400"><?= date('Y-m-d') ?></span>
    </div>
    <div class="prose prose-sm max-w-none">
      <pre class="whitespace-pre-wrap text-sm text-gray-700 font-mono bg-gray-50 rounded p-4 overflow-x-auto"><?= gs_h($strategy['strategyMd']) ?></pre>
    </div>
  </div>

  <!-- 操作按钮 -->
  <div class="flex gap-3">
    <form method="POST" class="inline">
      <input type="hidden" name="customer" value="<?= gs_h($selectedCid) ?>">
      <input type="hidden" name="action" value="push_feishu">
      <input type="hidden" name="strategy_md" value="<?= gs_h($strategy['strategyMd']) ?>">
      <input type="hidden" name="brand_name_push" value="<?= gs_h($strategy['brandName']) ?>">
      <button type="submit" class="px-4 py-2 bg-green-600 text-white text-sm rounded hover:bg-green-700">推送到飞书</button>
    </form>

    <a href="geo-content.php?customer=<?= gs_h($selectedCid) ?>" class="px-4 py-2 bg-blue-600 text-white text-sm rounded hover:bg-blue-700">进入Step4：生成文章 →</a>
    <form method="POST" class="inline">
      <input type="hidden" name="action" value="batch_queue">
      <input type="hidden" name="strategy_md" value="<?= gs_h($strategy['strategyMd']) ?>">
      <input type="hidden" name="brand_name_push" value="<?= gs_h($strategy['brandName'] ?? '') ?>">
      <input type="hidden" name="customer" value="<?= gs_h($selectedCid) ?>">
      <button type="submit" class="px-4 py-2 bg-indigo-600 text-white text-sm rounded hover:bg-indigo-700">⚡ 批量生成文章</button>
    </form>

    <form method="POST" class="inline">
      <input type="hidden" name="customer" value="<?= gs_h($selectedCid) ?>">
      <button type="submit" name="action" value="generate" class="px-4 py-2 bg-gray-200 text-gray-700 text-sm rounded hover:bg-gray-300">重新生成</button>
    </form>
  </div>
  <?php else: ?>
  <div class="bg-yellow-50 border border-yellow-200 rounded p-4 text-sm text-yellow-700">AI生成失败，请检查 DeepSeek API Key 配置</div>
  <?php endif; ?>

  <?php endif; ?>

</div>
</body>
</html>
