<?php
define('FEISHU_TREASURE', true);
session_start();
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database_admin.php';
require_once __DIR__ . '/../includes/functions.php';
require_admin_login();

$customers = $db->query("SELECT customer_id, name FROM customers ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$selectedCid = $_GET['customer'] ?? ($customers[0]['customer_id'] ?? '');
$selectedName = '';
foreach ($customers as $c) { if ($c['customer_id'] === $selectedCid) { $selectedName = $c['name']; break; } }

// GEO score from latest diagnosis
$geoScore = null;
$diagId = null;
try {
    $stmt = $db->prepare("SELECT id, overall_score FROM geo_diagnoses WHERE customer_id=? ORDER BY created_at DESC LIMIT 1");
    $stmt->execute([$selectedCid]);
    $diag = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($diag) { $geoScore = (int)$diag['overall_score']; $diagId = $diag['id']; }
} catch (Throwable $e) {}

// Signal scores
$signals = [];
if ($diagId) {
    try {
        $stmt = $db->prepare("SELECT s.signal_key, d.name, s.score, d.weight FROM geo_diagnosis_signal_scores s JOIN geo_diagnosis_signal_definitions d ON d.signal_key=s.signal_key WHERE s.diagnosis_id=? ORDER BY s.score ASC");
        $stmt->execute([$diagId]);
        $signals = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}
}

// Platform monitoring coverage
$platformCoverage = [];
try {
    $stmt = $db->prepare("SELECT provider_key, COUNT(*) as cnt, ROUND(100.0*SUM(CASE WHEN brand_mentioned THEN 1 ELSE 0 END)/COUNT(*),1) as rate FROM geo_monitor_records WHERE customer_id=? AND queried_at >= CURRENT_DATE - INTERVAL '30 days' GROUP BY provider_key");
    $stmt->execute([$selectedCid]);
    $platformCoverage = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

// Keyword stats
$kwStats = [];
try {
    $stmt = $db->prepare("SELECT query_text, COUNT(*) as total, ROUND(100.0*SUM(CASE WHEN brand_mentioned THEN 1 ELSE 0 END)/COUNT(*),1) as rate FROM geo_monitor_records WHERE customer_id=? AND queried_at >= CURRENT_DATE - INTERVAL '7 days' GROUP BY query_text ORDER BY rate ASC LIMIT 10");
    $stmt->execute([$selectedCid]);
    $kwStats = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

// Content published this month
$contentCount = 0;
try {
    $stmt = $db->prepare("SELECT COUNT(*) FROM articles WHERE customer_id=? AND created_at >= DATE_TRUNC('month', CURRENT_DATE)");
    $stmt->execute([$selectedCid]);
    $contentCount = (int)$stmt->fetchColumn();
} catch (Throwable $e) {}

// Recent alerts
$alerts = [];
try {
    $stmt = $db->prepare("SELECT alert_type, level, detail, alerted_at FROM geo_monitor_alerts WHERE customer_id=? AND alerted_at >= CURRENT_DATE - INTERVAL '14 days' ORDER BY CASE level WHEN 'high' THEN 1 WHEN 'medium' THEN 2 ELSE 3 END, alerted_at DESC LIMIT 5");
    $stmt->execute([$selectedCid]);
    $alerts = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

// Knowledge graph completeness
$kgCount = 0;
try {
    $stmt = $db->prepare("SELECT COUNT(*) FROM geo_brand_knowledge WHERE customer_id=?");
    $stmt->execute([$selectedCid]);
    $kgCount = (int)$stmt->fetchColumn();
} catch (Throwable $e) {}

// Intent questions coverage
$intentGap = 0;
try {
    $stmt = $db->prepare("SELECT COUNT(*) FROM geo_intent_questions WHERE customer_id=? AND covered=FALSE AND priority='P0'");
    $stmt->execute([$selectedCid]);
    $intentGap = (int)$stmt->fetchColumn();
} catch (Throwable $e) {}

// Build roadmap stages
function geo_stage(int $score): array {
    if ($score >= 80) return ['stage' => 4, 'label' => '持续优化期', 'color' => 'green'];
    if ($score >= 60) return ['stage' => 3, 'label' => '全平台覆盖期', 'color' => 'blue'];
    if ($score >= 40) return ['stage' => 2, 'label' => '权威建立期', 'color' => 'orange'];
    return ['stage' => 1, 'label' => '信息植入期', 'color' => 'red'];
}
$stage = $geoScore !== null ? geo_stage($geoScore) : null;

// Build prioritized action items from data
$actions = [];
if (!empty($signals)) {
    foreach (array_slice($signals, 0, 3) as $sig) {
        if ($sig['score'] < 70) {
            $actions[] = ['pri' => 'P0', 'type' => '诊断修复', 'item' => "提升「{$sig['name']}」得分（当前{$sig['score']}分）", 'link' => 'geo-diagnosis.php'];
        }
    }
}
foreach ($kwStats as $kw) {
    if ($kw['rate'] < 30) {
        $actions[] = ['pri' => 'P0', 'type' => '内容创作', 'item' => "补充关键词「{$kw['query_text']}」覆盖（提及率{$kw['rate']}%）", 'link' => 'geo-content.php?customer=' . urlencode($selectedCid) . '&keyword=' . urlencode($kw['query_text'])];
    }
}
if ($kgCount < 10) {
    $actions[] = ['pri' => 'P1', 'type' => '知识图谱', 'item' => "补充品牌知识图谱（当前{$kgCount}条，建议≥10条）", 'link' => 'geo-knowledge-graph.php?customer=' . urlencode($selectedCid)];
}
if ($intentGap > 0) {
    $actions[] = ['pri' => 'P1', 'type' => '意图覆盖', 'item' => "覆盖{$intentGap}个P0空白意图问题", 'link' => 'geo-intent.php?customer=' . urlencode($selectedCid)];
}
if (count($platformCoverage) < 4) {
    $actions[] = ['pri' => 'P1', 'type' => '平台扩张', 'item' => '扩大AI平台监测覆盖（目标6个平台）', 'link' => 'geo-monitor.php'];
}
if ($contentCount < 4) {
    $actions[] = ['pri' => 'P2', 'type' => '内容发布', 'item' => "本月已发布{$contentCount}篇，目标≥4篇/月", 'link' => 'geo-content.php?customer=' . urlencode($selectedCid)];
}
$actions[] = ['pri' => 'P2', 'type' => '月度报告', 'item' => '生成本月GEO监测月报', 'link' => 'monthly-report.php?customer=' . urlencode($selectedCid)];

$platforms6 = ['kimi' => 'Kimi', 'deepseek' => 'DeepSeek', 'tongyi' => '通义千问', 'wenxin' => '文心一言', 'doubao' => '豆包', 'yuanbao' => '腾讯元宝'];

$pageTitle = '执行路线图';
require_once __DIR__ . '/includes/brand-completeness.php';
require_once __DIR__ . '/includes/header.php';
?>
<div class="max-w-6xl mx-auto px-4 py-6">
  <?php if ($selectedCid): echo brand_completeness_check($db, $selectedCid); endif; ?>

  <div class="flex items-center justify-between mb-6">
    <div>
      <h1 class="text-2xl font-bold text-gray-900">执行路线图</h1>
      <p class="text-sm text-gray-500 mt-1">当前GEO进度、优先行动项与关键指标一览</p>
    </div>
    <select class="rounded-lg border-gray-300 text-sm shadow-sm" onchange="location='?customer='+this.value">
      <?php foreach ($customers as $c): ?>
        <option value="<?= htmlspecialchars($c['customer_id']) ?>" <?= $c['customer_id'] === $selectedCid ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>

  <!-- GEO Score + Stage -->
  <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
    <div class="md:col-span-1 bg-white rounded-xl border border-gray-200 p-5 flex flex-col items-center justify-center">
      <?php if ($geoScore !== null): ?>
        <div class="text-5xl font-bold <?= $geoScore >= 80 ? 'text-green-600' : ($geoScore >= 60 ? 'text-blue-600' : ($geoScore >= 40 ? 'text-orange-500' : 'text-red-600')) ?>"><?= $geoScore ?></div>
        <div class="text-sm text-gray-500 mt-1">GEO综合得分</div>
        <?php if ($stage): ?>
          <span class="mt-2 inline-block px-3 py-1 rounded-full text-xs font-medium bg-<?= $stage['color'] ?>-100 text-<?= $stage['color'] ?>-700">阶段<?= $stage['stage'] ?>：<?= $stage['label'] ?></span>
        <?php endif; ?>
      <?php else: ?>
        <div class="text-gray-400 text-sm">暂无诊断数据</div>
        <a href="geo-diagnosis.php" class="mt-2 text-xs text-indigo-600">去做诊断→</a>
      <?php endif; ?>
    </div>

    <!-- Stage progress bar -->
    <div class="md:col-span-2 bg-white rounded-xl border border-gray-200 p-5">
      <div class="text-sm font-medium text-gray-700 mb-3">GEO成熟度路线</div>
      <div class="flex items-center gap-0">
        <?php
        $stages = [
            ['label' => '信息植入', 'range' => '0-40', 'score' => 40],
            ['label' => '权威建立', 'range' => '40-60', 'score' => 60],
            ['label' => '全平台覆盖', 'range' => '60-80', 'score' => 80],
            ['label' => '持续优化', 'range' => '80+', 'score' => 100],
        ];
        $stageIdx = $stage ? $stage['stage'] - 1 : -1;
        foreach ($stages as $i => $s):
            $done = $geoScore !== null && $geoScore >= $s['score'];
            $current = $i === $stageIdx;
        ?>
        <div class="flex-1 text-center">
          <div class="flex items-center">
            <?php if ($i > 0): ?><div class="h-0.5 flex-1 <?= $done ? 'bg-indigo-400' : 'bg-gray-200' ?>"></div><?php endif; ?>
            <div class="w-8 h-8 rounded-full flex items-center justify-center text-sm font-bold flex-shrink-0 <?= $done ? 'bg-indigo-600 text-white' : ($current ? 'bg-indigo-100 text-indigo-600 ring-2 ring-indigo-400' : 'bg-gray-100 text-gray-400') ?>"><?= $i+1 ?></div>
            <?php if ($i < 3): ?><div class="h-0.5 flex-1 <?= $geoScore !== null && $geoScore >= $stages[$i+1]['score'] ? 'bg-indigo-400' : 'bg-gray-200' ?>"></div><?php endif; ?>
          </div>
          <div class="text-xs mt-1.5 <?= $current ? 'text-indigo-600 font-medium' : 'text-gray-400' ?>"><?= $s['label'] ?></div>
          <div class="text-xs text-gray-300"><?= $s['range'] ?>分</div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-4">
    <!-- Platform coverage -->
    <div class="bg-white rounded-xl border border-gray-200 p-4">
      <h3 class="font-semibold text-gray-800 mb-3 text-sm">AI平台覆盖（近30天）</h3>
      <?php if (empty($platformCoverage)): ?>
        <p class="text-gray-400 text-sm">暂无监测数据</p>
      <?php else: ?>
        <div class="space-y-2">
          <?php
          $coveredMap = [];
          foreach ($platformCoverage as $p) $coveredMap[$p['provider_key']] = $p;
          foreach ($platforms6 as $key => $name):
            $p = $coveredMap[$key] ?? null;
          ?>
          <div class="flex items-center gap-3">
            <span class="text-xs text-gray-500 w-20 flex-shrink-0"><?= $name ?></span>
            <?php if ($p): ?>
              <div class="flex-1 bg-gray-100 rounded-full h-2">
                <div class="bg-indigo-500 h-2 rounded-full" style="width:<?= min(100, $p['rate']) ?>%"></div>
              </div>
              <span class="text-xs font-medium text-gray-700 w-10 text-right"><?= $p['rate'] ?>%</span>
            <?php else: ?>
              <div class="flex-1 bg-gray-100 rounded-full h-2"></div>
              <span class="text-xs text-gray-300 w-10 text-right">—</span>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <!-- Keyword status -->
    <div class="bg-white rounded-xl border border-gray-200 p-4">
      <h3 class="font-semibold text-gray-800 mb-3 text-sm">关键词提及率（近7天，从低到高）</h3>
      <?php if (empty($kwStats)): ?>
        <p class="text-gray-400 text-sm">暂无关键词数据</p>
      <?php else: ?>
        <div class="space-y-2">
          <?php foreach ($kwStats as $kw): ?>
          <div class="flex items-center gap-2">
            <span class="text-xs text-gray-600 flex-1 truncate"><?= htmlspecialchars($kw['query_text']) ?></span>
            <div class="w-20 bg-gray-100 rounded-full h-1.5">
              <div class="<?= $kw['rate'] < 30 ? 'bg-red-400' : ($kw['rate'] < 60 ? 'bg-yellow-400' : 'bg-green-400') ?> h-1.5 rounded-full" style="width:<?= min(100,$kw['rate']) ?>%"></div>
            </div>
            <span class="text-xs <?= $kw['rate'] < 30 ? 'text-red-600' : ($kw['rate'] < 60 ? 'text-yellow-600' : 'text-green-600') ?> font-medium w-10 text-right"><?= $kw['rate'] ?>%</span>
          </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Priority Actions -->
  <div class="bg-white rounded-xl border border-gray-200 p-4 mb-4">
    <h3 class="font-semibold text-gray-800 mb-4 text-sm">优先行动清单</h3>
    <?php if (empty($actions)): ?>
      <p class="text-gray-400 text-sm">暂无行动项，请先完成诊断和监测配置</p>
    <?php else: ?>
      <div class="space-y-2">
        <?php foreach ($actions as $action): ?>
        <div class="flex items-center gap-3 p-3 rounded-lg border <?= $action['pri']==='P0' ? 'border-red-200 bg-red-50' : ($action['pri']==='P1' ? 'border-orange-200 bg-orange-50' : 'border-gray-200 bg-gray-50') ?>">
          <span class="inline-block px-1.5 py-0.5 rounded text-xs font-bold flex-shrink-0 <?= $action['pri']==='P0' ? 'bg-red-100 text-red-700' : ($action['pri']==='P1' ? 'bg-orange-100 text-orange-700' : 'bg-gray-200 text-gray-600') ?>"><?= $action['pri'] ?></span>
          <span class="text-xs text-gray-500 flex-shrink-0 w-16"><?= $action['type'] ?></span>
          <span class="text-sm text-gray-700 flex-1"><?= htmlspecialchars($action['item']) ?></span>
          <a href="<?= htmlspecialchars($action['link']) ?>" class="text-xs text-indigo-600 hover:text-indigo-800 flex-shrink-0">去处理→</a>
        </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <!-- Recent Alerts -->
  <?php if (!empty($alerts)): ?>
  <div class="bg-white rounded-xl border border-gray-200 p-4">
    <h3 class="font-semibold text-gray-800 mb-3 text-sm">近14天告警记录</h3>
    <div class="space-y-2">
      <?php foreach ($alerts as $alert): ?>
      <div class="flex items-start gap-3 p-3 rounded-lg <?= $alert['level']==='high' ? 'bg-red-50 border border-red-200' : ($alert['level']==='medium' ? 'bg-yellow-50 border border-yellow-200' : 'bg-gray-50 border border-gray-200') ?>">
        <span class="text-xs font-bold px-1.5 py-0.5 rounded flex-shrink-0 <?= $alert['level']==='high' ? 'bg-red-100 text-red-700' : ($alert['level']==='medium' ? 'bg-yellow-100 text-yellow-700' : 'bg-gray-100 text-gray-600') ?>"><?= strtoupper($alert['level']) ?></span>
        <span class="text-sm text-gray-700 flex-1"><?= htmlspecialchars(mb_substr($alert['detail'], 0, 80)) ?></span>
        <span class="text-xs text-gray-400 flex-shrink-0"><?= date('m/d', strtotime($alert['alerted_at'])) ?></span>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
