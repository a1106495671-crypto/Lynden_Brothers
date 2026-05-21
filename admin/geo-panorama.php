<?php
/**
 * GEO 全景诊断 - 基于监测数据生成完整竞品差距与机会地图
 */
define('FEISHU_TREASURE', true);
session_start();
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/database_admin.php';
require_once __DIR__ . '/../includes/citation_simulator_service.php';
require_admin_login();

function gp_h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

// 客户列表
$customers = [];
try {
    $stmt = $db->query("
        SELECT DISTINCT k.customer_id,
               COALESCE((SELECT fact_value FROM geo_brand_facts WHERE customer_id=k.customer_id AND fact_key='brand_name' LIMIT 1), k.customer_id) AS brand_name
        FROM geo_monitor_keywords k ORDER BY brand_name
    ");
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

$cid    = trim($_GET['customer'] ?? $_POST['customer'] ?? ($customers[0]['customer_id'] ?? ''));
$action = $_POST['action'] ?? '';
$report = null;
$genError = '';

// 读品牌信息
$facts = [];
if ($cid !== '') {
    $stmtF = $db->prepare("SELECT fact_key, fact_value FROM geo_brand_facts WHERE customer_id=?");
    $stmtF->execute([$cid]);
    foreach ($stmtF->fetchAll(PDO::FETCH_ASSOC) as $r) $facts[$r['fact_key']] = $r['fact_value'];
}
$brandName = $facts['brand_name'] ?? $cid;

// ── 生成全景诊断 ──────────────────────────────────────────────────────────
if ($action === 'generate' && $cid !== '') {

    // 1. 品牌事实
    $masterSentence = $facts['master_sentence'] ?? '';
    $coreServices   = $facts['core_service'] ?? $facts['core_services'] ?? '';
    $industry       = $facts['industry'] ?? '';
    $differentiator = $facts['differentiator'] ?? '';
    $targetClient   = $facts['target_client'] ?? '';

    // 2. 竞品列表
    $stmtC = $db->prepare("SELECT competitor FROM geo_customer_competitors WHERE customer_id=? AND enabled=TRUE ORDER BY id");
    $stmtC->execute([$cid]);
    $competitors = $stmtC->fetchAll(PDO::FETCH_COLUMN);

    // 3. 监测数据（近30天）
    $stmtM = $db->prepare("
        SELECT provider, query_text, brand_mentioned, mention_depth,
               competitors_found, accuracy_score, queried_at::text AS day
        FROM geo_monitor_records
        WHERE customer_id=? AND queried_at >= CURRENT_DATE - INTERVAL '29 days'
        ORDER BY queried_at DESC
    ");
    $stmtM->execute([$cid]);
    $records = $stmtM->fetchAll(PDO::FETCH_ASSOC);

    // 4. 聚合：各平台提及率
    $platformStats = [];
    $kwStats = [];
    foreach ($records as $r) {
        $p  = $r['provider'];
        $kw = $r['query_text'];
        if (!isset($platformStats[$p])) $platformStats[$p] = ['total'=>0,'hit'=>0];
        $platformStats[$p]['total']++;
        if ($r['brand_mentioned']) $platformStats[$p]['hit']++;
        if (!isset($kwStats[$kw])) $kwStats[$kw] = ['total'=>0,'hit'=>0,'comp'=>[]];
        $kwStats[$kw]['total']++;
        if ($r['brand_mentioned']) $kwStats[$kw]['hit']++;
        $found = json_decode($r['competitors_found'] ?? '[]', true);
        if (is_array($found)) {
            foreach ($found as $f) {
                $cn = $f['name'] ?? '';
                if ($cn) $kwStats[$kw]['comp'][$cn] = ($kwStats[$kw]['comp'][$cn] ?? 0) + 1;
            }
        }
    }

    // 5. 竞品在各关键词的出现率
    $compOverall = [];
    foreach ($records as $r) {
        $found = json_decode($r['competitors_found'] ?? '[]', true);
        if (!is_array($found)) continue;
        foreach ($found as $f) {
            $cn = $f['name'] ?? '';
            if ($cn) $compOverall[$cn] = ($compOverall[$cn] ?? 0) + 1;
        }
    }
    $totalRecords = count($records);

    // 6. 诊断信号分
    $diagId = $db->prepare("
        SELECT r.id FROM geo_diagnosis_runs r
        JOIN geo_diagnosis_brands b ON b.id=r.brand_id
        WHERE b.name=? ORDER BY r.created_at DESC LIMIT 1
    ");
    $diagId->execute([$brandName]);
    $diagIdVal = $diagId->fetchColumn();
    $signals = [];
    if ($diagIdVal) {
        $stmtS = $db->prepare("
            SELECT s.signal_key, d.name, s.score
            FROM geo_diagnosis_signal_scores s
            JOIN geo_diagnosis_signal_definitions d ON d.signal_key=s.signal_key
            WHERE s.diagnosis_id=? ORDER BY s.score ASC
        ");
        $stmtS->execute([$diagIdVal]);
        $signals = $stmtS->fetchAll(PDO::FETCH_ASSOC);
    }

    // 7. 近7天告警
    $stmtA = $db->prepare("
        SELECT alert_type, level, keyword, competitor_name, brand_rate, competitor_rate, detail
        FROM geo_monitor_alerts WHERE customer_id=? AND alerted_at >= CURRENT_DATE - INTERVAL '7 days'
        ORDER BY CASE level WHEN 'high' THEN 1 WHEN 'medium' THEN 2 ELSE 3 END
    ");
    $stmtA->execute([$cid]);
    $alerts = $stmtA->fetchAll(PDO::FETCH_ASSOC);

    // 8. 构建 AI 分析 Prompt
    $platformText = '';
    foreach ($platformStats as $p => $s) {
        $rate = $s['total'] > 0 ? round($s['hit']/$s['total']*100,1) : 0;
        $platformText .= "- {$p}：提及率{$rate}%（{$s['hit']}/{$s['total']}）\n";
    }

    $kwText = '';
    arsort($kwStats);
    foreach (array_slice($kwStats, 0, 12) as $kw => $s) {
        $rate = $s['total'] > 0 ? round($s['hit']/$s['total']*100,1) : 0;
        $topComp = '';
        if (!empty($s['comp'])) {
            arsort($s['comp']);
            $topComp = ' | 竞品出现：' . implode('、', array_slice(array_keys($s['comp']), 0, 3));
        }
        $kwText .= "- 「{$kw}」提及率{$rate}%{$topComp}\n";
    }

    $compText = '';
    if ($totalRecords > 0) {
        arsort($compOverall);
        foreach (array_slice($compOverall, 0, 6) as $cn => $cnt) {
            $rate = round($cnt/$totalRecords*100,1);
            $compText .= "- {$cn}：出现率{$rate}%（{$cnt}次）\n";
        }
    }

    $signalText = '';
    foreach ($signals as $s) {
        $flag = $s['score'] < 40 ? '🔴' : ($s['score'] < 70 ? '🟡' : '🟢');
        $signalText .= "- {$flag} {$s['name']}：{$s['score']}分\n";
    }

    $alertText = empty($alerts) ? '近7天无告警' :
        implode("\n", array_map(fn($a)=>"- [{$a['level']}] {$a['detail']}", array_slice($alerts,0,5)));

    $prompt = <<<PROMPT
你是专业的GEO全景诊断分析师。请基于以下真实监测数据，为品牌【{$brandName}】生成一份完整的GEO全景诊断报告。

## 品牌档案
- 品牌定位：{$masterSentence}
- 核心服务：{$coreServices}
- 差异化：{$differentiator}
- 目标客户：{$targetClient}
- 行业：{$industry}
- 主要竞品：{$compText}

## AI平台提及率（近30天）
{$platformText}

## 关键词表现（提及率从高到低）
{$kwText}

## 竞品在AI回答中的出现频率
{$compText}

## 诊断信号评分
{$signalText}

## 近7天告警
{$alertText}

## 请生成以下内容（Markdown格式）：

### 一、AI可见性总评
- 整体提及率评级（A/B/C/D）及说明
- 与行业标准对比（行业平均GEO提及率约30-50%）
- 最强平台和最弱平台

### 二、竞品差距分析
- 哪些竞品在AI回答中已超越{$brandName}
- 差距最大的3个关键词及原因分析

### 三、GEO机会地图（P0/P1/P2优先级）
严格按以下格式输出表格：
| 优先级 | 问题类型 | 具体问题 | 推荐行动 | 预期周期 |
|--------|---------|---------|---------|---------|

P0（本周必做）：3-4条
P1（本月完成）：4-5条
P2（季度规划）：3-4条

### 四、快赢清单（48小时可执行）
列出3-5条本周可立即执行的高性价比动作

### 五、监测建议
需要新增哪些关键词来弥补问题宇宙盲区

只输出报告正文，不要前言。
PROMPT;

    $apiKey = citation_simulator_get_provider_key('deepseek', 'api_key');
    $payload = json_encode([
        'model' => 'deepseek-chat',
        'messages' => [['role' => 'user', 'content' => $prompt]],
        'max_tokens' => 4000,
        'temperature' => 0.4,
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init('https://api.deepseek.com/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer '.$apiKey],
    ]);
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code === 200) {
        $data = json_decode($raw, true);
        $reportMd = $data['choices'][0]['message']['content'] ?? '';
        $report = compact('brandName','platformStats','kwStats','compOverall','totalRecords','signals','alerts','reportMd','competitors');
    } else {
        $genError = "DeepSeek API 失败 HTTP {$code}";
    }
}

// ── Markdown 转 HTML（简单版）────────────────────────────────────────────
function md2html(string $md): string {
    $md = htmlspecialchars($md, ENT_QUOTES, 'UTF-8');
    // 表格
    $md = preg_replace_callback('/(\|.+\|\n)+/', function($m) {
        $rows = array_filter(explode("\n", trim($m[0])));
        $html = '<table class="w-full text-sm border-collapse mb-4">';
        $isHead = true;
        foreach ($rows as $row) {
            if (preg_match('/^\|[-| :]+\|$/', $row)) { $isHead = false; continue; }
            $cells = array_map('trim', explode('|', trim($row, '|')));
            $tag = $isHead ? 'th' : 'td';
            $html .= '<tr>' . implode('', array_map(fn($c) => "<{$tag} class=\"border border-gray-200 px-3 py-1.5 text-left\">{$c}</{$tag}>", $cells)) . '</tr>';
            if ($isHead) $isHead = false;
        }
        return $html . '</table>';
    }, $md);
    $md = preg_replace('/^### (.+)$/m', '<h3 class="text-base font-semibold text-gray-800 mt-5 mb-2">$1</h3>', $md);
    $md = preg_replace('/^## (.+)$/m',  '<h2 class="text-lg font-bold text-gray-900 mt-6 mb-3 border-b pb-1">$1</h2>', $md);
    $md = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $md);
    $md = preg_replace('/^- (.+)$/m', '<li class="ml-4 list-disc text-gray-700">$1</li>', $md);
    $md = str_replace(['🔴','🟡','🟢'], ['<span class="text-red-500">🔴</span>','<span class="text-yellow-500">🟡</span>','<span class="text-green-500">🟢</span>'], $md);
    $md = nl2br($md);
    return $md;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<title>GEO全景诊断</title>
<script src="/admin/assets/js/tailwind.play-cdn.js"></script>
</head>
<body class="bg-gray-50 min-h-screen">
<?php if ($selectedCid): echo brand_completeness_check($db, $selectedCid); endif; ?>
<div class="max-w-6xl mx-auto py-10 px-4">

  <div class="mb-6 flex items-center justify-between">
    <div>
      <h1 class="text-2xl font-bold text-gray-900">GEO 全景诊断</h1>
      <p class="text-gray-500 mt-1">AI可见性 · 竞品差距 · 机会地图 · 快赢清单</p>
    </div>
    <a href="geo-monitor.php" class="text-sm text-gray-500 hover:text-gray-700">← 返回监测</a>
  </div>

  <!-- 客户选择 + 生成 -->
  <form method="POST" class="bg-white rounded-lg border border-gray-200 p-5 mb-6 flex items-end gap-4">
    <div class="flex-1">
      <label class="block text-sm font-medium text-gray-700 mb-1">选择客户</label>
      <select name="customer" class="w-full border border-gray-300 rounded px-3 py-2 text-sm">
        <?php foreach ($customers as $c): ?>
        <option value="<?= gp_h($c['customer_id']) ?>" <?= $c['customer_id']===$cid?'selected':'' ?>><?= gp_h($c['brand_name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <button type="submit" name="action" value="generate" id="genBtn"
      class="px-6 py-2 bg-indigo-600 text-white text-sm font-medium rounded hover:bg-indigo-700">
      🔍 生成全景诊断
    </button>
  </form>

  <div id="loadingOverlay" style="display:none" class="fixed inset-0 bg-white/80 backdrop-blur-sm z-50 flex flex-col items-center justify-center">
    <div class="bg-white rounded-2xl shadow-lg border border-gray-200 px-10 py-8 text-center max-w-sm">
      <svg class="animate-spin w-10 h-10 text-indigo-600 mx-auto mb-4" fill="none" viewBox="0 0 24 24">
        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
      </svg>
      <div class="text-base font-semibold text-gray-800 mb-1">AI正在生成全景诊断</div>
      <div class="text-sm text-gray-500">正在分析6大AI平台数据，约30-60秒...</div>
      <div id="loadingTimer" class="text-2xl font-mono text-indigo-600 mt-4">0s</div>
    </div>
  </div>
  <script>
  document.querySelector("form").addEventListener("submit", function() {
    document.getElementById("loadingOverlay").style.display = "flex";
    document.getElementById("genBtn").disabled = true;
    var s = 0;
    setInterval(function(){ s++; document.getElementById("loadingTimer").textContent = s + "s"; }, 1000);
  });
  </script>

  <?php if ($genError): ?>
  <div class="mb-4 p-3 bg-red-50 border border-red-200 rounded text-sm text-red-700"><?= gp_h($genError) ?></div>
  <?php endif; ?>

  <?php if ($report): ?>

  <!-- 数据快览 -->
  <?php
    $totalHit = 0; $totalAll = 0;
    foreach ($report['platformStats'] as $s) { $totalHit += $s['hit']; $totalAll += $s['total']; }
    $overallRate = $totalAll > 0 ? round($totalHit/$totalAll*100,1) : 0;
    $grade = $overallRate >= 60 ? 'A' : ($overallRate >= 40 ? 'B' : ($overallRate >= 20 ? 'C' : 'D'));
    $gradeColor = $overallRate >= 60 ? 'text-green-600' : ($overallRate >= 40 ? 'text-yellow-600' : ($overallRate >= 20 ? 'text-orange-600' : 'text-red-600'));
  ?>
  <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
    <div class="bg-white rounded-lg border border-gray-200 p-4 text-center">
      <div class="text-xs text-gray-500 mb-1">整体AI提及率</div>
      <div class="text-3xl font-bold <?= $gradeColor ?>"><?= $overallRate ?>%</div>
      <div class="text-xs text-gray-400 mt-1">评级 <?= $grade ?></div>
    </div>
    <div class="bg-white rounded-lg border border-gray-200 p-4 text-center">
      <div class="text-xs text-gray-500 mb-1">覆盖AI平台</div>
      <div class="text-3xl font-bold text-blue-600"><?= count($report['platformStats']) ?></div>
      <div class="text-xs text-gray-400 mt-1">/ 6 个平台</div>
    </div>
    <div class="bg-white rounded-lg border border-gray-200 p-4 text-center">
      <div class="text-xs text-gray-500 mb-1">监测关键词</div>
      <div class="text-3xl font-bold text-purple-600"><?= count($report['kwStats']) ?></div>
      <div class="text-xs text-gray-400 mt-1">个词近30天</div>
    </div>
    <div class="bg-white rounded-lg border border-gray-200 p-4 text-center">
      <div class="text-xs text-gray-500 mb-1">竞品出现次数</div>
      <div class="text-3xl font-bold text-orange-500"><?= array_sum($report['compOverall']) ?></div>
      <div class="text-xs text-gray-400 mt-1">次（近30天）</div>
    </div>
  </div>

  <div class="grid grid-cols-3 gap-6 mb-6">

    <!-- 各平台提及率 -->
    <div class="bg-white rounded-lg border border-gray-200 p-4">
      <h3 class="font-semibold text-gray-700 mb-3 text-sm">各平台提及率</h3>
      <?php
      $platformNames = ['kimi'=>'Kimi','deepseek'=>'DeepSeek','tongyi'=>'通义','wenxin'=>'文心','doubao'=>'豆包','yuanbao'=>'元宝'];
      foreach ($report['platformStats'] as $p => $s):
        $r = $s['total'] > 0 ? round($s['hit']/$s['total']*100) : 0;
        $barColor = $r >= 50 ? 'bg-green-500' : ($r >= 25 ? 'bg-yellow-500' : 'bg-red-400');
      ?>
      <div class="mb-2">
        <div class="flex justify-between text-xs text-gray-600 mb-0.5">
          <span><?= gp_h($platformNames[$p] ?? $p) ?></span>
          <span><?= $r ?>%</span>
        </div>
        <div class="h-2 bg-gray-100 rounded-full">
          <div class="h-2 <?= $barColor ?> rounded-full" style="width:<?= $r ?>%"></div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- 关键词表现 -->
    <div class="bg-white rounded-lg border border-gray-200 p-4">
      <h3 class="font-semibold text-gray-700 mb-3 text-sm">关键词提及率（低→高）</h3>
      <?php
      $kwSorted = $report['kwStats'];
      uasort($kwSorted, fn($a,$b) => ($a['total']>0?$a['hit']/$a['total']:0) <=> ($b['total']>0?$b['hit']/$b['total']:0));
      foreach (array_slice($kwSorted, 0, 8) as $kw => $s):
        $r = $s['total'] > 0 ? round($s['hit']/$s['total']*100) : 0;
        $color = $r >= 50 ? 'text-green-600 bg-green-50' : ($r >= 25 ? 'text-yellow-700 bg-yellow-50' : 'text-red-600 bg-red-50');
      ?>
      <div class="flex items-center justify-between mb-1.5">
        <span class="text-xs text-gray-600 truncate flex-1 mr-2"><?= gp_h(mb_substr($kw,0,12)) ?></span>
        <span class="text-xs font-medium px-1.5 py-0.5 rounded <?= $color ?>"><?= $r ?>%</span>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- 竞品出现频率 -->
    <div class="bg-white rounded-lg border border-gray-200 p-4">
      <h3 class="font-semibold text-gray-700 mb-3 text-sm">竞品AI出现频率</h3>
      <?php
      $compSorted = $report['compOverall'];
      arsort($compSorted);
      if (empty($compSorted)): ?>
        <p class="text-xs text-gray-400">暂无竞品出现记录</p>
      <?php else:
        foreach (array_slice($compSorted, 0, 6) as $cn => $cnt):
          $r = $report['totalRecords'] > 0 ? round($cnt/$report['totalRecords']*100) : 0;
      ?>
      <div class="flex items-center justify-between mb-1.5">
        <span class="text-xs text-gray-600 truncate flex-1 mr-2"><?= gp_h(mb_substr($cn,0,10)) ?></span>
        <span class="text-xs font-medium text-orange-600"><?= $r ?>%</span>
      </div>
      <?php endforeach; endif; ?>
    </div>

  </div>

  <!-- AI 分析报告 -->
  <div class="bg-white rounded-lg border border-gray-200 p-6">
    <div class="flex items-center justify-between mb-4">
      <h2 class="font-bold text-gray-900">📊 GEO全景诊断报告 · <?= gp_h($report['brandName']) ?></h2>
      <span class="text-xs text-gray-400"><?= date('Y-m-d') ?> · DeepSeek分析</span>
    </div>
    <div class="prose prose-sm max-w-none text-gray-700 leading-relaxed">
      <?= md2html($report['reportMd']) ?>
    </div>
  </div>

  <?php endif; ?>

  <?php if (!$report && !$genError): ?>
  <div class="bg-white rounded-lg border border-gray-200 p-12 text-center text-gray-400">
    <div class="text-5xl mb-4">🔍</div>
    <p class="text-sm">选择客户，点击「生成全景诊断」</p>
    <p class="text-xs mt-2">将分析：AI平台提及率 · 竞品差距 · 机会地图 · 快赢清单</p>
  </div>
  <?php endif; ?>

</div>
</body>
</html>
