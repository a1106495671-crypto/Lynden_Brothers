<?php
/**
 * Step3 策略方案设计 - AI内容日历生成（重构版）
 */
define('FEISHU_TREASURE', true);
session_start();
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/database_admin.php';
require_once __DIR__ . '/../includes/citation_simulator_service.php';
require_admin_login();

function gs_h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

// ── 所有客户（含新客户）──────────────────────────────────────────────────────
$allCustomers = [];
try {
    $stmt = $db->query("SELECT customer_id, name AS brand_name FROM customers ORDER BY name");
    $allCustomers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

// ── 客户切换（GET 跳转避免表单重提）────────────────────────────────────────
$action = $_POST['action'] ?? '';
if ($action === 'switch_customer') {
    $cid = trim($_POST['customer'] ?? '');
    header('Location: geo-strategy.php?customer=' . urlencode($cid));
    exit;
}

// ── 确定当前客户 ─────────────────────────────────────────────────────────────
$current_customer_context = $_SESSION['current_customer'] ?? null;
$defaultCid = $current_customer_context['customer_id'] ?? ($allCustomers[0]['customer_id'] ?? '');
$selectedCid = trim($_GET['customer'] ?? $_POST['customer'] ?? $defaultCid);

// ── 检查客户是否有数据 ───────────────────────────────────────────────────────
$hasIntentData      = false;
$hasMonitorData     = false;
$hasDiagnosisData   = false;
$p0Questions        = [];
$weakKeywords       = [];
$weakSignals        = [];
$brandName          = $selectedCid;

if ($selectedCid !== '') {
    // 品牌名
    try {
        $r = $db->prepare("SELECT name FROM customers WHERE customer_id = ? LIMIT 1");
        $r->execute([$selectedCid]);
        $brandName = $r->fetchColumn() ?: $selectedCid;
    } catch (Throwable $e) {}

    // P0 意图问题（未覆盖）
    try {
        $stmt = $db->prepare("SELECT id, question, dimension, suggested_action FROM geo_intent_questions WHERE customer_id = ? AND priority = 'P0' AND covered = false ORDER BY created_at ASC LIMIT 20");
        $stmt->execute([$selectedCid]);
        $p0Questions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $hasIntentData = !empty($p0Questions);
    } catch (Throwable $e) {}

    // 失守关键词（7天内提及率 < 30%）
    try {
        $stmt = $db->prepare("
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
        $stmt->execute([$selectedCid]);
        $weakKeywords = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $hasMonitorData = !empty($weakKeywords);
    } catch (Throwable $e) {}

    // 诊断短板（分数 < 60）
    try {
        $stmt = $db->prepare("
            SELECT s.signal_key, d.name, s.score
            FROM geo_diagnosis_signal_scores s
            JOIN geo_diagnosis_signal_definitions d ON d.signal_key = s.signal_key
            WHERE s.diagnosis_id = (
                SELECT id FROM geo_diagnosis_runs
                WHERE brand_id = (SELECT id FROM geo_diagnosis_brands WHERE name = ? LIMIT 1)
                ORDER BY created_at DESC LIMIT 1
            ) AND s.score < 60
            ORDER BY s.score ASC
            LIMIT 5
        ");
        $stmt->execute([$brandName]);
        $weakSignals = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $hasDiagnosisData = !empty($weakSignals);
    } catch (Throwable $e) {}
}

$isNewCustomer = !$hasIntentData && !$hasMonitorData && !$hasDiagnosisData;

// ── AI 生成日历 ──────────────────────────────────────────────────────────────
$strategy     = null;
$calendarRows = [];
$aiNotes      = '';
$pushResult   = null;
$batchResult  = null;

if ($selectedCid !== '' && $action === 'generate') {
    // 品牌信息
    $facts = [];
    try {
        $stmt = $db->prepare("SELECT fact_key, fact_value FROM geo_brand_facts WHERE customer_id = ?");
        $stmt->execute([$selectedCid]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $facts[$row['fact_key']] = $row['fact_value'];
    } catch (Throwable $e) {}

    $masterSentence = $facts['master_sentence'] ?? '';
    $coreServices   = $facts['core_services']   ?? '';
    $industry       = $facts['industry']        ?? '';

    // P0 问题文本
    $p0Text = empty($p0Questions) ? '暂无P0意图问题' :
        implode("\n", array_map(fn($q) => "- {$q['question']}" . ($q['dimension'] ? "（{$q['dimension']}）" : ''), $p0Questions));

    // 失守关键词文本
    $weakKwText = empty($weakKeywords) ? '暂无' :
        implode("\n", array_map(fn($k) => "- 「{$k['query_text']}」提及率{$k['mention_rate']}%", $weakKeywords));

    // 诊断短板文本
    $weakSignalText = empty($weakSignals) ? '暂无诊断数据' :
        implode('、', array_map(fn($s) => "{$s['name']}({$s['score']}分)", $weakSignals));

    $prompt = <<<PROMPT
你是专业的GEO内容策略师。请为以下品牌生成一份4周内容日历策略。

## 品牌信息
- 品牌名：{$brandName}
- 定位：{$masterSentence}
- 核心服务：{$coreServices}
- 行业：{$industry}

## P0 优先意图问题（用户在AI搜索中问到但品牌未覆盖，必须优先处理）
{$p0Text}

## 失守关键词（品牌提及率低于30%）
{$weakKwText}

## 诊断短板（需补强）
{$weakSignalText}

## 任务
请生成一份4周内容日历，格式为严格的Markdown表格，每行一篇文章：

| 周次 | 发布平台 | 目标关键词/问题 | 文章角度 | 内容格式 | 优先级 |
|------|---------|--------------|---------|---------|-------|

要求：
1. 共12-16篇文章，覆盖4周
2. P0意图问题必须全部覆盖，每个问题至少一篇
3. 平台选择：知乎、今日头条、搜狐号、微信公众号、小红书（选最适合的）
4. 文章角度要有差异化，能让AI优先引用{$brandName}
5. 表格后附3条执行建议（每条一行，以"建议："开头）

只输出表格和建议，不要其他前言。
PROMPT;

    // 7. 调用AI生成策略
    $aiResult = geo_call_ai($prompt, 3000, 0.6);
    if (empty($aiResult['error'])) {
        $strategyMd = $aiResult['content'];
    }
    $aiNotes = implode("\n", $notes);
    $strategy = ['brandName' => $brandName, 'rawMd' => $rawMd];
}

// ── 批量入队 ─────────────────────────────────────────────────────────────────
if ($selectedCid !== '' && $action === 'batch_queue' && !empty($_POST['strategy_md'])) {
    $rawMd = $_POST['strategy_md'];
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

    $lines = explode("\n", $rawMd);
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
        try { $ins->execute([$selectedCid, $weekNum, $platform, $keyword, $angle, $fmt, $pri]); $inserted++; } catch (Throwable $e) {}
    }
    $batchResult = $inserted;
    $strategy = ['brandName' => $brandName, 'rawMd' => $rawMd];
}

// ── 推送飞书 ─────────────────────────────────────────────────────────────────
if ($selectedCid !== '' && $action === 'push_feishu' && !empty($_POST['strategy_md'])) {
    $mdContent = $_POST['strategy_md'];
    try {
        $stmt = $db->prepare("SELECT setting_value FROM site_settings WHERE setting_key = 'feishu_monitor_webhook' LIMIT 1");
        $stmt->execute();
        $webhookUrl = trim((string)($stmt->fetchColumn() ?: ''));
    } catch (Throwable $e) { $webhookUrl = ''; }

    if ($webhookUrl !== '') {
        $payload = json_encode([
            'msg_type' => 'post',
            'content'  => ['post' => ['zh_cn' => [
                'title'   => "📋 内容策略日历 · {$brandName} · " . date('Y-m-d'),
                'content' => [[['tag' => 'text', 'text' => $mdContent]]],
            ]]],
        ], JSON_UNESCAPED_UNICODE);
        $ch = curl_init($webhookUrl);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_HTTPHEADER => ['Content-Type: application/json']]);
        $r = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $pushResult = $code === 200 ? 'success' : 'fail';
    } else {
        $pushResult = 'no_webhook';
    }
    $strategy = ['brandName' => $brandName, 'rawMd' => $mdContent];
}

// 按周分组
$weekGroups = [];
foreach ($calendarRows as $row) {
    $weekGroups[$row['week']][] = $row;
}
ksort($weekGroups);

require_once __DIR__ . '/includes/header.php';
?>

<div class="max-w-5xl mx-auto py-8 px-4">

  <!-- 页头 -->
  <div class="mb-6 flex items-center justify-between">
    <div>
      <h1 class="text-xl font-bold text-gray-900">策略方案设计</h1>
      <p class="text-sm text-gray-500 mt-0.5">基于P0意图问题 + 失守关键词，AI生成4周内容日历</p>
    </div>
    <div class="flex gap-2 text-sm">
      <a href="geo-intent.php<?= $selectedCid ? '?customer='.urlencode($selectedCid) : '' ?>" class="px-3 py-1.5 rounded border border-gray-300 text-gray-600 hover:bg-gray-50">← 意图挖掘</a>
      <a href="geo-content.php<?= $selectedCid ? '?customer='.urlencode($selectedCid) : '' ?>" class="px-3 py-1.5 rounded border border-blue-300 text-blue-600 hover:bg-blue-50">内容生成 →</a>
    </div>
  </div>

  <!-- 客户切换 -->
  <form method="POST" class="bg-white rounded-xl border border-gray-200 p-4 mb-5 flex items-end gap-3">
    <input type="hidden" name="action" value="switch_customer">
    <div class="flex-1">
      <label class="block text-xs font-medium text-gray-500 mb-1">当前客户</label>
      <select name="customer" onchange="this.form.submit()" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400">
        <?php foreach ($allCustomers as $c): ?>
        <option value="<?= gs_h($c['customer_id']) ?>" <?= $c['customer_id']===$selectedCid?'selected':'' ?>><?= gs_h($c['brand_name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <noscript><button type="submit" class="px-4 py-2 bg-gray-100 text-gray-700 text-sm rounded-lg">切换</button></noscript>
  </form>

  <?php if ($selectedCid !== ''): ?>

  <?php if ($isNewCustomer): ?>
  <!-- 新客户引导框 -->
  <div class="bg-amber-50 border border-amber-200 rounded-xl p-5 mb-6">
    <div class="flex items-start gap-3">
      <span class="text-2xl">🆕</span>
      <div>
        <h3 class="font-semibold text-amber-800 mb-1">该客户尚未完成前置步骤</h3>
        <p class="text-sm text-amber-700 mb-3">策略生成需要诊断数据和意图问题，请按以下步骤操作：</p>
        <div class="flex flex-wrap gap-2">
          <a href="geo-diagnosis.php?customer=<?= urlencode($selectedCid) ?>" class="inline-flex items-center gap-1 px-3 py-1.5 bg-amber-100 border border-amber-300 text-amber-800 text-sm rounded-lg hover:bg-amber-200">
            <span class="font-bold">①</span> 雷达诊断
          </a>
          <span class="text-amber-400 self-center">→</span>
          <a href="geo-intent.php?customer=<?= urlencode($selectedCid) ?>" class="inline-flex items-center gap-1 px-3 py-1.5 bg-amber-100 border border-amber-300 text-amber-800 text-sm rounded-lg hover:bg-amber-200">
            <span class="font-bold">②</span> 意图挖掘
          </a>
          <span class="text-amber-400 self-center">→</span>
          <a href="geo-monitor.php?customer=<?= urlencode($selectedCid) ?>" class="inline-flex items-center gap-1 px-3 py-1.5 bg-amber-100 border border-amber-300 text-amber-800 text-sm rounded-lg hover:bg-amber-200">
            <span class="font-bold">③</span> 配置监测关键词
          </a>
          <span class="text-amber-400 self-center">→</span>
          <span class="inline-flex items-center gap-1 px-3 py-1.5 bg-amber-200 border border-amber-400 text-amber-900 text-sm rounded-lg font-medium">
            <span class="font-bold">④</span> 回来生成策略
          </span>
        </div>
      </div>
    </div>
  </div>
  <?php else: ?>

  <!-- 数据快照 -->
  <div class="grid grid-cols-3 gap-4 mb-5">
    <div class="bg-white rounded-xl border border-gray-200 p-4">
      <div class="text-xs text-gray-500 mb-1">P0 未覆盖意图</div>
      <div class="text-3xl font-bold <?= count($p0Questions)>0 ? 'text-red-500' : 'text-gray-300' ?>"><?= count($p0Questions) ?></div>
      <div class="text-xs text-gray-400 mt-1">个问题需要立即布局</div>
    </div>
    <div class="bg-white rounded-xl border border-gray-200 p-4">
      <div class="text-xs text-gray-500 mb-1">失守关键词</div>
      <div class="text-3xl font-bold <?= count($weakKeywords)>0 ? 'text-orange-500' : 'text-gray-300' ?>"><?= count($weakKeywords) ?></div>
      <div class="text-xs text-gray-400 mt-1">提及率低于30%</div>
    </div>
    <div class="bg-white rounded-xl border border-gray-200 p-4">
      <div class="text-xs text-gray-500 mb-1">诊断短板信号</div>
      <div class="text-3xl font-bold <?= count($weakSignals)>0 ? 'text-yellow-500' : 'text-gray-300' ?>"><?= count($weakSignals) ?></div>
      <div class="text-xs text-gray-400 mt-1">个维度得分低于60</div>
    </div>
  </div>

  <!-- P0 意图问题 -->
  <?php if (!empty($p0Questions)): ?>
  <div class="bg-white rounded-xl border border-red-100 p-5 mb-5">
    <div class="flex items-center justify-between mb-3">
      <h2 class="font-semibold text-gray-800 flex items-center gap-2">
        <span class="px-1.5 py-0.5 bg-red-500 text-white text-xs rounded font-bold">P0</span>
        未覆盖意图问题 <span class="text-red-500 font-bold">(<?= count($p0Questions) ?>)</span>
      </h2>
      <span class="text-xs text-gray-400">这些问题用户在AI中问到但品牌未出现</span>
    </div>
    <div class="space-y-2">
      <?php foreach ($p0Questions as $q): ?>
      <div class="flex items-center justify-between bg-red-50 rounded-lg px-4 py-2.5">
        <div class="flex-1 min-w-0">
          <span class="text-sm text-gray-800"><?= gs_h($q['question']) ?></span>
          <?php if ($q['dimension']): ?>
          <span class="ml-2 text-xs text-gray-400"><?= gs_h($q['dimension']) ?></span>
          <?php endif; ?>
        </div>
        <a href="geo-content.php?customer=<?= urlencode($selectedCid) ?>&keyword=<?= urlencode($q['question']) ?>"
           class="ml-3 flex-shrink-0 px-3 py-1 bg-blue-600 text-white text-xs rounded hover:bg-blue-700">
          生成文章
        </a>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <?php endif; /* end isNewCustomer else */ ?>

  <!-- 推送/批量结果提示 -->
  <?php if ($pushResult !== null): ?>
  <div class="mb-4 p-3 rounded-lg text-sm <?= $pushResult==='success' ? 'bg-green-50 text-green-700 border border-green-200' : 'bg-red-50 text-red-700 border border-red-200' ?>">
    <?= $pushResult==='success' ? '✅ 已推送到飞书' : ($pushResult==='no_webhook' ? '⚠️ 未配置飞书 Webhook（在系统设置中添加）' : '❌ 飞书推送失败') ?>
  </div>
  <?php endif; ?>
  <?php if ($batchResult !== null): ?>
  <div class="mb-4 p-3 rounded-lg text-sm <?= $batchResult>0 ? 'bg-indigo-50 text-indigo-700 border border-indigo-200' : 'bg-red-50 text-red-700 border border-red-200' ?>">
    <?php if ($batchResult > 0): ?>
      ✅ 已将 <strong><?= $batchResult ?></strong> 篇文章加入生成队列 →
      <a href="geo-content-queue.php?customer=<?= urlencode($selectedCid) ?>" class="underline font-medium">查看队列</a>
    <?php else: ?>
      ❌ 未能解析到有效行，请确认策略表格格式正确
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <!-- 生成按钮区 -->
  <?php if (!$isNewCustomer): ?>
  <form method="POST" class="mb-5">
    <input type="hidden" name="customer" value="<?= gs_h($selectedCid) ?>">
    <button type="submit" name="action" value="generate"
            class="w-full py-3 bg-blue-600 text-white font-medium rounded-xl hover:bg-blue-700 flex items-center justify-center gap-2">
      <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
      AI 生成4周内容日历
    </button>
  </form>
  <?php endif; ?>

  <!-- AI 日历结果 -->
  <?php if ($strategy !== null && !empty($weekGroups)): ?>
  <div class="mb-5">
    <div class="flex items-center justify-between mb-3">
      <h2 class="font-semibold text-gray-800">📋 4周内容日历 · <?= gs_h($strategy['brandName']) ?></h2>
      <span class="text-xs text-gray-400"><?= date('Y-m-d') ?></span>
    </div>

    <?php if ($aiNotes): ?>
    <div class="bg-blue-50 border border-blue-100 rounded-xl p-4 mb-4 text-sm text-blue-800">
      <div class="font-medium mb-1">💡 AI 执行建议</div>
      <?php foreach (explode("\n", $aiNotes) as $note): ?>
        <?php if (trim($note)): ?><div class="mt-1">• <?= gs_h(trim($note)) ?></div><?php endif; ?>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- 按周分组的卡片 -->
    <?php foreach ($weekGroups as $week => $rows): ?>
    <div class="mb-4">
      <div class="text-xs font-bold text-gray-500 uppercase tracking-wide mb-2">第 <?= $week ?> 周</div>
      <div class="space-y-2">
        <?php foreach ($rows as $row): ?>
        <div class="bg-white rounded-xl border border-gray-200 px-4 py-3 flex items-center gap-3">
          <span class="px-2 py-0.5 rounded text-xs font-bold <?= $row['priority']==='P0' ? 'bg-red-100 text-red-600' : 'bg-gray-100 text-gray-500' ?>"><?= gs_h($row['priority']) ?></span>
          <span class="text-xs px-2 py-0.5 bg-blue-50 text-blue-600 rounded"><?= gs_h($row['platform']) ?></span>
          <div class="flex-1 min-w-0">
            <div class="text-sm font-medium text-gray-800 truncate"><?= gs_h($row['keyword']) ?></div>
            <div class="text-xs text-gray-400 mt-0.5 truncate"><?= gs_h($row['angle']) ?> · <?= gs_h($row['format']) ?></div>
          </div>
          <a href="geo-content.php?customer=<?= urlencode($selectedCid) ?>&keyword=<?= urlencode($row['keyword']) ?>&platform=<?= urlencode($row['platform']) ?>"
             class="flex-shrink-0 px-3 py-1 bg-blue-600 text-white text-xs rounded hover:bg-blue-700">
            生成
          </a>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endforeach; ?>

    <!-- 操作按钮 -->
    <div class="flex gap-3 mt-4">
      <form method="POST" class="inline">
        <input type="hidden" name="customer" value="<?= gs_h($selectedCid) ?>">
        <input type="hidden" name="action" value="batch_queue">
        <input type="hidden" name="strategy_md" value="<?= gs_h($strategy['rawMd']) ?>">
        <button type="submit" class="px-4 py-2 bg-indigo-600 text-white text-sm rounded-lg hover:bg-indigo-700">⚡ 全部加入生成队列</button>
      </form>
      <form method="POST" class="inline">
        <input type="hidden" name="customer" value="<?= gs_h($selectedCid) ?>">
        <input type="hidden" name="action" value="push_feishu">
        <input type="hidden" name="strategy_md" value="<?= gs_h($strategy['rawMd']) ?>">
        <button type="submit" class="px-4 py-2 bg-green-600 text-white text-sm rounded-lg hover:bg-green-700">推送到飞书</button>
      </form>
      <form method="POST" class="inline">
        <input type="hidden" name="customer" value="<?= gs_h($selectedCid) ?>">
        <button type="submit" name="action" value="generate" class="px-4 py-2 bg-gray-100 text-gray-700 text-sm rounded-lg hover:bg-gray-200">重新生成</button>
      </form>
    </div>
  </div>

  <?php elseif ($strategy !== null && !empty($strategy['rawMd'])): ?>
  <!-- AI输出但解析失败时，回退显示原始文本 -->
  <div class="bg-white rounded-xl border border-gray-200 p-5 mb-4">
    <div class="text-sm font-medium text-gray-600 mb-2">AI 输出（原始）</div>
    <pre class="whitespace-pre-wrap text-sm text-gray-700 font-mono bg-gray-50 rounded-lg p-4 overflow-x-auto"><?= gs_h($strategy['rawMd']) ?></pre>
    <div class="flex gap-3 mt-4">
      <form method="POST" class="inline">
        <input type="hidden" name="customer" value="<?= gs_h($selectedCid) ?>">
        <input type="hidden" name="action" value="batch_queue">
        <input type="hidden" name="strategy_md" value="<?= gs_h($strategy['rawMd']) ?>">
        <button type="submit" class="px-4 py-2 bg-indigo-600 text-white text-sm rounded-lg hover:bg-indigo-700">⚡ 全部加入生成队列</button>
      </form>
    </div>
  </div>
  <?php elseif ($action === 'generate'): ?>
  <div class="bg-yellow-50 border border-yellow-200 rounded-xl p-4 text-sm text-yellow-700">
    ⚠️ AI生成失败，请检查 API Key 配置或稍后重试
  </div>
  <?php endif; ?>

  <?php endif; /* end selectedCid */ ?>

</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
