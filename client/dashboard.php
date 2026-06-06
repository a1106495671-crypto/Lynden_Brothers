<?php
/**
 * 客户自助门户 - 数据看板
 */
session_start();
if (!isset($_SESSION['client_id'])) {
    header('Location: login.php');
    exit;
}

define('FEISHU_TREASURE', true);
require_once '/www/wwwroot/geo-system/includes/config.php';
require_once '/www/wwwroot/geo-system/includes/database_admin.php';

$cid  = $_SESSION['client_id'];
$name = $_SESSION['client_name'] ?? $cid;

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: login.php');
    exit;
}

// GEO Score
$geoScore = null;
$scoreTrend = [];
try {
    $stmt = $db->prepare("SELECT overall_score, created_at FROM geo_diagnoses WHERE customer_id=? ORDER BY created_at DESC LIMIT 6");
    $stmt->execute([$cid]);
    $diagRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if ($diagRows) {
        $geoScore = (int)$diagRows[0]['overall_score'];
        $scoreTrend = array_reverse(array_map(fn($r) => ['score' => (int)$r['overall_score'], 'date' => date('m/d', strtotime($r['created_at']))], $diagRows));
    }
} catch (Throwable $e) {}

// Platform mention rates (last 30 days)
$platforms = [];
try {
    $stmt = $db->prepare("SELECT provider_key, ROUND(100.0*SUM(CASE WHEN brand_mentioned THEN 1 ELSE 0 END)/COUNT(*),1) as rate, COUNT(*) as total FROM geo_monitor_records WHERE customer_id=? AND queried_at >= CURRENT_DATE - INTERVAL '30 days' GROUP BY provider_key");
    $stmt->execute([$cid]);
    $platforms = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

$platformNames = ['kimi' => 'Kimi', 'deepseek' => 'DeepSeek', 'tongyi' => '通义千问', 'wenxin' => '文心一言', 'doubao' => '豆包', 'yuanbao' => '腾讯元宝'];

// Avg mention rate
$avgRate = 0;
if ($platforms) {
    $avgRate = round(array_sum(array_column($platforms, 'rate')) / count($platforms), 1);
}

// Keyword coverage (last 7 days)
$keywords = [];
try {
    $stmt = $db->prepare("SELECT query_text, ROUND(100.0*SUM(CASE WHEN brand_mentioned THEN 1 ELSE 0 END)/COUNT(*),1) as rate FROM geo_monitor_records WHERE customer_id=? AND queried_at >= CURRENT_DATE - INTERVAL '7 days' GROUP BY query_text ORDER BY rate DESC");
    $stmt->execute([$cid]);
    $keywords = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

// Published articles this month
$articles = [];
try {
    $stmt = $db->prepare("SELECT title, platform, status, created_at FROM articles WHERE customer_id=? ORDER BY created_at DESC LIMIT 8");
    $stmt->execute([$cid]);
    $articles = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

// Signal scores for radar
$signals = [];
try {
    $stmt = $db->prepare("SELECT d.name, s.score FROM geo_diagnosis_signal_scores s JOIN geo_diagnosis_signal_definitions d ON d.signal_key=s.signal_key WHERE s.diagnosis_id=(SELECT id FROM geo_diagnoses WHERE customer_id=? ORDER BY created_at DESC LIMIT 1) ORDER BY d.sort_order");
    $stmt->execute([$cid]);
    $signals = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

// Recent alerts (client-friendly)
$alerts = [];
try {
    $stmt = $db->prepare("SELECT alert_type, level, detail, alerted_at FROM geo_monitor_alerts WHERE customer_id=? AND alerted_at >= CURRENT_DATE - INTERVAL '30 days' ORDER BY alerted_at DESC LIMIT 5");
    $stmt->execute([$cid]);
    $alerts = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

function score_grade(int $s): array {
    if ($s >= 80) return ['A', 'text-green-600', '优秀'];
    if ($s >= 65) return ['B', 'text-blue-600', '良好'];
    if ($s >= 50) return ['C', 'text-yellow-600', '待提升'];
    return ['D', 'text-red-600', '需重点优化'];
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($name) ?> - GEO数据看板</title>
<script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-gray-50">
<nav class="bg-white border-b border-gray-200 px-6 py-3 flex items-center justify-between">
  <div class="flex items-center gap-3">
    <img src="/assets/images/lynden-brothers-logo.jpg" alt="Lynden Brothers GEO" class="h-9 w-auto max-w-44 object-contain">
    <span class="font-semibold text-gray-900">GEO数据看板</span>
    <span class="text-gray-300">|</span>
    <span class="text-sm text-gray-600"><?= htmlspecialchars($name) ?></span>
  </div>
  <div class="flex items-center gap-4">
    <span class="text-xs text-gray-400">数据更新：<?= date('Y-m-d') ?></span>
    <a href="report-export.php" class="text-xs text-indigo-600 hover:text-indigo-800">下载月报</a>
    <a href="?logout=1" class="text-xs text-gray-400 hover:text-gray-700">退出登录</a>
  </div>
</nav>

<div class="max-w-5xl mx-auto px-4 py-6">

  <!-- Top metrics -->
  <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
    <div class="bg-white rounded-xl border border-gray-200 p-4 text-center">
      <?php if ($geoScore !== null):
        [$grade, $gradeCls, $gradeLabel] = score_grade($geoScore);
      ?>
        <div class="text-3xl font-bold <?= $gradeCls ?>"><?= $geoScore ?></div>
        <div class="text-xs text-gray-500 mt-1">GEO综合评分</div>
        <span class="inline-block mt-1.5 text-xs px-2 py-0.5 rounded-full bg-gray-100 text-gray-600"><?= $grade ?> · <?= $gradeLabel ?></span>
      <?php else: ?>
        <div class="text-2xl text-gray-300">--</div>
        <div class="text-xs text-gray-400 mt-1">暂无诊断数据</div>
      <?php endif; ?>
    </div>

    <div class="bg-white rounded-xl border border-gray-200 p-4 text-center">
      <div class="text-3xl font-bold text-indigo-600"><?= $avgRate ?>%</div>
      <div class="text-xs text-gray-500 mt-1">平均AI提及率</div>
      <div class="text-xs text-gray-400 mt-1">近30天</div>
    </div>

    <div class="bg-white rounded-xl border border-gray-200 p-4 text-center">
      <div class="text-3xl font-bold text-blue-600"><?= count($platforms) ?>/6</div>
      <div class="text-xs text-gray-500 mt-1">AI平台覆盖</div>
      <div class="text-xs text-gray-400 mt-1">有效提及</div>
    </div>

    <div class="bg-white rounded-xl border border-gray-200 p-4 text-center">
      <div class="text-3xl font-bold text-green-600"><?= count($articles) ?></div>
      <div class="text-xs text-gray-500 mt-1">近期发布文章</div>
      <div class="text-xs text-gray-400 mt-1">GEO内容</div>
    </div>
  </div>

  <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
    <!-- Platform breakdown -->
    <div class="bg-white rounded-xl border border-gray-200 p-5">
      <h3 class="font-semibold text-gray-800 text-sm mb-4">AI平台提及率（近30天）</h3>
      <div class="space-y-3">
        <?php
        $platMap = [];
        foreach ($platforms as $p) $platMap[$p['provider_key']] = $p;
        foreach ($platformNames as $key => $pname):
          $p = $platMap[$key] ?? null;
          $rate = $p ? (float)$p['rate'] : 0;
        ?>
        <div class="flex items-center gap-3">
          <span class="text-xs text-gray-600 w-20 flex-shrink-0"><?= $pname ?></span>
          <div class="flex-1 bg-gray-100 rounded-full h-2">
            <div class="<?= $rate >= 60 ? 'bg-green-500' : ($rate >= 30 ? 'bg-yellow-400' : 'bg-red-400') ?> h-2 rounded-full transition-all" style="width:<?= min(100,$rate) ?>%"></div>
          </div>
          <span class="text-xs font-medium <?= $rate >= 60 ? 'text-green-700' : ($rate >= 30 ? 'text-yellow-700' : ($p ? 'text-red-700' : 'text-gray-300')) ?> w-10 text-right">
            <?= $p ? $rate.'%' : '—' ?>
          </span>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Keyword coverage -->
    <div class="bg-white rounded-xl border border-gray-200 p-5">
      <h3 class="font-semibold text-gray-800 text-sm mb-4">关键词AI可见度（近7天）</h3>
      <?php if (empty($keywords)): ?>
        <p class="text-sm text-gray-400">暂无关键词监测数据</p>
      <?php else: ?>
        <div class="space-y-2">
          <?php foreach (array_slice($keywords, 0, 8) as $kw): ?>
          <div class="flex items-center gap-2">
            <span class="text-xs text-gray-600 flex-1 truncate" title="<?= htmlspecialchars($kw['query_text']) ?>"><?= htmlspecialchars(mb_substr($kw['query_text'], 0, 20)) ?></span>
            <div class="w-16 bg-gray-100 rounded-full h-1.5">
              <div class="<?= $kw['rate'] >= 60 ? 'bg-green-400' : ($kw['rate'] >= 30 ? 'bg-yellow-400' : 'bg-red-400') ?> h-1.5 rounded-full" style="width:<?= min(100,$kw['rate']) ?>%"></div>
            </div>
            <span class="text-xs font-medium <?= $kw['rate'] >= 60 ? 'text-green-700' : ($kw['rate'] >= 30 ? 'text-yellow-700' : 'text-red-700') ?> w-8 text-right"><?= $kw['rate'] ?>%</span>
          </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Signal scores -->
  <?php if (!empty($signals)): ?>
  <div class="bg-white rounded-xl border border-gray-200 p-5 mb-4">
    <h3 class="font-semibold text-gray-800 text-sm mb-4">GEO维度得分</h3>
    <div class="grid grid-cols-2 md:grid-cols-3 gap-3">
      <?php foreach ($signals as $sig):
        [$g, $c, $l] = score_grade((int)$sig['score']);
      ?>
      <div class="border border-gray-100 rounded-lg p-3">
        <div class="text-xs text-gray-500 mb-1"><?= htmlspecialchars($sig['name']) ?></div>
        <div class="flex items-center justify-between">
          <div class="flex-1 bg-gray-100 rounded-full h-1.5 mr-2">
            <div class="<?= (int)$sig['score'] >= 70 ? 'bg-green-500' : ((int)$sig['score'] >= 50 ? 'bg-yellow-400' : 'bg-red-400') ?> h-1.5 rounded-full" style="width:<?= min(100,$sig['score']) ?>%"></div>
          </div>
          <span class="text-xs font-bold <?= $c ?>"><?= $sig['score'] ?></span>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- Recent Articles -->
  <?php if (!empty($articles)): ?>
  <div class="bg-white rounded-xl border border-gray-200 p-5 mb-4">
    <h3 class="font-semibold text-gray-800 text-sm mb-4">近期GEO内容</h3>
    <div class="divide-y divide-gray-50">
      <?php foreach ($articles as $a): ?>
      <div class="py-2.5 flex items-center gap-3">
        <span class="text-xs text-gray-400 flex-shrink-0"><?= date('m/d', strtotime($a['created_at'])) ?></span>
        <span class="text-xs bg-indigo-100 text-indigo-700 px-1.5 py-0.5 rounded flex-shrink-0"><?= htmlspecialchars($a['platform'] ?? '待定') ?></span>
        <span class="text-sm text-gray-700 flex-1 truncate"><?= htmlspecialchars($a['title'] ?? '') ?></span>
        <span class="text-xs <?= ($a['status'] ?? '') === 'published' ? 'text-green-600' : 'text-gray-400' ?> flex-shrink-0">
          <?= ($a['status'] ?? '') === 'published' ? '已发布' : '草稿' ?>
        </span>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- Alerts (client-friendly) -->
  <?php if (!empty($alerts)): ?>
  <div class="bg-white rounded-xl border border-gray-200 p-5">
    <h3 class="font-semibold text-gray-800 text-sm mb-3">近30天监测动态</h3>
    <div class="space-y-2">
      <?php foreach ($alerts as $alert):
        $typeMap = ['competitor_surpass'=>'竞品超越','core_rate_low'=>'提及率偏低','accuracy_low'=>'语义偏差'];
        $typeLabel = $typeMap[$alert['alert_type']] ?? $alert['alert_type'];
      ?>
      <div class="flex items-start gap-3 p-3 rounded-lg <?= $alert['level']==='high' ? 'bg-red-50 border border-red-100' : 'bg-gray-50 border border-gray-100' ?>">
        <span class="inline-block w-1.5 h-1.5 rounded-full mt-1.5 flex-shrink-0 <?= $alert['level']==='high' ? 'bg-red-500' : ($alert['level']==='medium' ? 'bg-yellow-400' : 'bg-gray-400') ?>"></span>
        <div class="flex-1">
          <span class="text-xs font-medium text-gray-600">[<?= $typeLabel ?>]</span>
          <span class="text-sm text-gray-700 ml-1"><?= htmlspecialchars(mb_substr($alert['detail'], 0, 80)) ?></span>
        </div>
        <span class="text-xs text-gray-400 flex-shrink-0"><?= date('m月d日', strtotime($alert['alerted_at'])) ?></span>
      </div>
      <?php endforeach; ?>
    </div>
    <p class="text-xs text-gray-400 mt-3">* 您的GEO顾问已收到告警通知并正在处理</p>
  </div>
  <?php endif; ?>

</div>
</body>
</html>
