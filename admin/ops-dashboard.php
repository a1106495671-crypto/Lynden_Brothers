<?php
/**
 * 运营大盘 - 跨客户GEO健康总览
 */
define('FEISHU_TREASURE', true);
session_start();
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database_admin.php';
require_once __DIR__ . '/../includes/functions.php';
require_admin_login();

// Summary stats
$totalCustomers = 0;
$avgScore = 0;
$alertCount = 0;
$articlesThisWeek = 0;

try {
    $totalCustomers = (int)$db->query("SELECT COUNT(*) FROM customers")->fetchColumn();
    $avgScore = (int)$db->query("SELECT COALESCE(AVG(d.overall_score),0) FROM geo_diagnoses d JOIN (SELECT customer_id, MAX(created_at) ma FROM geo_diagnoses GROUP BY customer_id) latest ON d.customer_id=latest.customer_id AND d.created_at=latest.ma")->fetchColumn();
    $alertCount = (int)$db->query("SELECT COUNT(*) FROM geo_monitor_alerts WHERE alerted_at >= NOW() - INTERVAL '7 days'")->fetchColumn();
    $articlesThisWeek = (int)$db->query("SELECT COUNT(*) FROM articles WHERE created_at >= NOW() - INTERVAL '7 days'")->fetchColumn();
} catch (Throwable $e) {}

// Per-customer data
$customers = [];
try {
    $customers = $db->query("SELECT customer_id, name, industry FROM customers ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

// Build customer metrics
$customerMetrics = [];
foreach ($customers as $c) {
    $cid = $c['customer_id'];
    $m = ['cid' => $cid, 'name' => $c['name'], 'industry' => $c['industry'] ?? ''];

    // Latest GEO score
    try {
        $stmt = $db->prepare("SELECT overall_score, created_at FROM geo_diagnoses WHERE customer_id=? ORDER BY created_at DESC LIMIT 2");
        $stmt->execute([$cid]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $m['score'] = $rows ? (int)$rows[0]['overall_score'] : null;
        $m['score_prev'] = isset($rows[1]) ? (int)$rows[1]['overall_score'] : null;
        $m['score_date'] = $rows ? date('m/d', strtotime($rows[0]['created_at'])) : null;
    } catch (Throwable $e) { $m['score'] = null; $m['score_prev'] = null; $m['score_date'] = null; }

    // Avg mention rate last 7 days
    try {
        $stmt = $db->prepare("SELECT ROUND(100.0*SUM(CASE WHEN brand_mentioned THEN 1 ELSE 0 END)/NULLIF(COUNT(*),0),1) as rate FROM geo_monitor_records WHERE customer_id=? AND queried_at >= NOW() - INTERVAL '7 days'");
        $stmt->execute([$cid]);
        $m['mention_rate'] = $stmt->fetchColumn();
    } catch (Throwable $e) { $m['mention_rate'] = null; }

    // Platform count (active last 7 days)
    try {
        $stmt = $db->prepare("SELECT COUNT(DISTINCT provider_key) FROM geo_monitor_records WHERE customer_id=? AND queried_at >= NOW() - INTERVAL '7 days'");
        $stmt->execute([$cid]);
        $m['platform_count'] = (int)$stmt->fetchColumn();
    } catch (Throwable $e) { $m['platform_count'] = 0; }

    // Alert count last 7 days
    try {
        $stmt = $db->prepare("SELECT COUNT(*) FROM geo_monitor_alerts WHERE customer_id=? AND alerted_at >= NOW() - INTERVAL '7 days'");
        $stmt->execute([$cid]);
        $m['alert_count'] = (int)$stmt->fetchColumn();
    } catch (Throwable $e) { $m['alert_count'] = 0; }

    // Articles this week
    try {
        $stmt = $db->prepare("SELECT COUNT(*) FROM articles WHERE customer_id=? AND created_at >= NOW() - INTERVAL '7 days'");
        $stmt->execute([$cid]);
        $m['articles_week'] = (int)$stmt->fetchColumn();
    } catch (Throwable $e) { $m['articles_week'] = 0; }

    // Queue pending
    try {
        $stmt = $db->prepare("SELECT COUNT(*) FROM geo_content_queue WHERE customer_id=? AND status='pending'");
        $stmt->execute([$cid]);
        $m['queue_pending'] = (int)$stmt->fetchColumn();
    } catch (Throwable $e) { $m['queue_pending'] = 0; }

    // Status: red = alert≥1 or score<50, yellow = score<65 or rate<30, green otherwise
    if ($m['alert_count'] > 0 || ($m['score'] !== null && $m['score'] < 50)) {
        $m['status'] = 'red';
    } elseif ($m['score'] !== null && $m['score'] < 65 || ($m['mention_rate'] !== null && $m['mention_rate'] < 30)) {
        $m['status'] = 'yellow';
    } elseif ($m['score'] === null) {
        $m['status'] = 'gray';
    } else {
        $m['status'] = 'green';
    }

    $customerMetrics[] = $m;
}

// Sort: red first, then yellow, then green/gray
usort($customerMetrics, function($a, $b) {
    $order = ['red'=>0,'yellow'=>1,'green'=>2,'gray'=>3];
    return ($order[$a['status']] ?? 3) - ($order[$b['status']] ?? 3);
});

$pageTitle = '运营大盘';
require_once __DIR__ . '/includes/header.php';
?>
<div class="max-w-7xl mx-auto px-4 py-6">
  <div class="mb-6 flex items-center justify-between">
    <div>
      <h1 class="text-2xl font-bold text-gray-900">运营大盘</h1>
      <p class="text-sm text-gray-500 mt-1">所有客户GEO健康状态总览 · 数据更新：<?= date('Y-m-d H:i') ?></p>
    </div>
    <button onclick="location.reload()" class="text-sm text-indigo-600 hover:text-indigo-800">刷新数据</button>
  </div>

  <!-- Summary cards -->
  <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
    <div class="bg-white rounded-xl border border-gray-200 p-4 text-center">
      <div class="text-3xl font-bold text-gray-800"><?= $totalCustomers ?></div>
      <div class="text-xs text-gray-500 mt-1">服务客户数</div>
    </div>
    <div class="bg-white rounded-xl border border-gray-200 p-4 text-center">
      <div class="text-3xl font-bold <?= $avgScore >= 65 ? 'text-green-600' : ($avgScore >= 50 ? 'text-yellow-600' : 'text-red-600') ?>"><?= $avgScore ?></div>
      <div class="text-xs text-gray-500 mt-1">平均GEO分</div>
    </div>
    <div class="bg-white rounded-xl border border-gray-200 p-4 text-center">
      <div class="text-3xl font-bold <?= $alertCount > 0 ? 'text-red-600' : 'text-gray-400' ?>"><?= $alertCount ?></div>
      <div class="text-xs text-gray-500 mt-1">本周告警</div>
    </div>
    <div class="bg-white rounded-xl border border-gray-200 p-4 text-center">
      <div class="text-3xl font-bold text-indigo-600"><?= $articlesThisWeek ?></div>
      <div class="text-xs text-gray-500 mt-1">本周发文</div>
    </div>
  </div>

  <!-- Status legend -->
  <div class="flex items-center gap-4 mb-4 text-xs text-gray-500">
    <span class="flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-red-500"></span> 需关注（告警或分数&lt;50）</span>
    <span class="flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-yellow-400"></span> 待提升（分数&lt;65或提及率&lt;30%）</span>
    <span class="flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-green-500"></span> 健康</span>
    <span class="flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-gray-300"></span> 未诊断</span>
  </div>

  <!-- Customer table -->
  <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
    <table class="w-full text-sm">
      <thead class="bg-gray-50 border-b border-gray-200">
        <tr>
          <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 w-4"></th>
          <th class="px-4 py-3 text-left text-xs font-medium text-gray-500">客户</th>
          <th class="px-4 py-3 text-center text-xs font-medium text-gray-500">GEO评分</th>
          <th class="px-4 py-3 text-center text-xs font-medium text-gray-500">提及率(7天)</th>
          <th class="px-4 py-3 text-center text-xs font-medium text-gray-500">平台覆盖</th>
          <th class="px-4 py-3 text-center text-xs font-medium text-gray-500">本周告警</th>
          <th class="px-4 py-3 text-center text-xs font-medium text-gray-500">本周发文</th>
          <th class="px-4 py-3 text-center text-xs font-medium text-gray-500">待生成</th>
          <th class="px-4 py-3 text-left text-xs font-medium text-gray-500">快捷操作</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-gray-100">
        <?php foreach ($customerMetrics as $m):
          $statusColors = ['red'=>'bg-red-500','yellow'=>'bg-yellow-400','green'=>'bg-green-500','gray'=>'bg-gray-300'];
          $statusColor = $statusColors[$m['status']] ?? 'bg-gray-300';
          $scoreTrend = '';
          if ($m['score'] !== null && $m['score_prev'] !== null) {
              $delta = $m['score'] - $m['score_prev'];
              if ($delta > 0) $scoreTrend = '<span class="text-green-600 ml-1 text-xs">▲'.$delta.'</span>';
              elseif ($delta < 0) $scoreTrend = '<span class="text-red-600 ml-1 text-xs">▼'.abs($delta).'</span>';
          }
        ?>
        <tr class="hover:bg-gray-50">
          <td class="pl-4 py-3">
            <span class="w-2.5 h-2.5 rounded-full inline-block <?= $statusColor ?>"></span>
          </td>
          <td class="px-4 py-3">
            <div class="font-medium text-gray-800"><?= htmlspecialchars($m['name']) ?></div>
            <div class="text-xs text-gray-400"><?= htmlspecialchars($m['industry']) ?></div>
          </td>
          <td class="px-4 py-3 text-center">
            <?php if ($m['score'] !== null): ?>
              <span class="font-bold <?= $m['score'] >= 65 ? 'text-green-600' : ($m['score'] >= 50 ? 'text-yellow-600' : 'text-red-600') ?>"><?= $m['score'] ?></span>
              <?= $scoreTrend ?>
              <div class="text-xs text-gray-400"><?= $m['score_date'] ?></div>
            <?php else: ?>
              <span class="text-gray-300 text-xs">未诊断</span>
            <?php endif; ?>
          </td>
          <td class="px-4 py-3 text-center">
            <?php if ($m['mention_rate'] !== null): ?>
              <div class="flex items-center justify-center gap-1">
                <div class="w-16 bg-gray-100 rounded-full h-1.5">
                  <div class="<?= $m['mention_rate'] >= 60 ? 'bg-green-500' : ($m['mention_rate'] >= 30 ? 'bg-yellow-400' : 'bg-red-400') ?> h-1.5 rounded-full" style="width:<?= min(100,$m['mention_rate']) ?>%"></div>
                </div>
                <span class="text-xs font-medium"><?= $m['mention_rate'] ?>%</span>
              </div>
            <?php else: ?>
              <span class="text-gray-300 text-xs">—</span>
            <?php endif; ?>
          </td>
          <td class="px-4 py-3 text-center">
            <span class="<?= $m['platform_count'] >= 4 ? 'text-green-600' : ($m['platform_count'] >= 2 ? 'text-yellow-600' : 'text-red-500') ?> font-medium"><?= $m['platform_count'] ?>/6</span>
          </td>
          <td class="px-4 py-3 text-center">
            <?php if ($m['alert_count'] > 0): ?>
              <span class="inline-block bg-red-100 text-red-700 text-xs font-medium px-2 py-0.5 rounded-full"><?= $m['alert_count'] ?></span>
            <?php else: ?>
              <span class="text-gray-300 text-xs">—</span>
            <?php endif; ?>
          </td>
          <td class="px-4 py-3 text-center">
            <span class="<?= $m['articles_week'] > 0 ? 'text-indigo-600 font-medium' : 'text-gray-300' ?>"><?= $m['articles_week'] > 0 ? $m['articles_week'] : '—' ?></span>
          </td>
          <td class="px-4 py-3 text-center">
            <?php if ($m['queue_pending'] > 0): ?>
              <a href="geo-content-queue.php?customer=<?= urlencode($m['cid']) ?>" class="inline-block bg-orange-100 text-orange-700 text-xs font-medium px-2 py-0.5 rounded-full hover:bg-orange-200"><?= $m['queue_pending'] ?> 篇</a>
            <?php else: ?>
              <span class="text-gray-300 text-xs">—</span>
            <?php endif; ?>
          </td>
          <td class="px-4 py-3">
            <div class="flex items-center gap-2 text-xs">
              <a href="customers.php?id=<?= urlencode($m['cid']) ?>" class="text-indigo-600 hover:text-indigo-800">档案</a>
              <span class="text-gray-300">·</span>
              <a href="geo-monitor.php?customer=<?= urlencode($m['cid']) ?>" class="text-indigo-600 hover:text-indigo-800">监测</a>
              <span class="text-gray-300">·</span>
              <a href="geo-roadmap.php?customer=<?= urlencode($m['cid']) ?>" class="text-indigo-600 hover:text-indigo-800">路线图</a>
              <?php if ($m['alert_count'] > 0): ?>
                <span class="text-gray-300">·</span>
                <a href="geo-monitor.php?customer=<?= urlencode($m['cid']) ?>#alerts" class="text-red-600 hover:text-red-800 font-medium">⚠ 处理告警</a>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- Quick links -->
  <div class="mt-4 text-xs text-gray-400 text-right">
    按状态排序：红色（需关注）→ 黄色（待提升）→ 绿色（健康）
  </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
