<?php
/**
 * 竞品动态时间线 - 品牌 vs 竞品提及率历史曲线
 */
define('FEISHU_TREASURE', true);
session_start();
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database_admin.php';
require_once __DIR__ . '/../includes/functions.php';
require_admin_login();

$customers = [];
try {
    $customers = $db->query("SELECT customer_id, name FROM customers ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

$selectedCid = $_GET['customer'] ?? ($customers[0]['customer_id'] ?? '');
$selectedPlatform = $_GET['platform'] ?? 'all';
$weeks = max(4, min(12, (int)($_GET['weeks'] ?? 8)));

$selectedName = '';
foreach ($customers as $c) {
    if ($c['customer_id'] === $selectedCid) { $selectedName = $c['name']; break; }
}

$platforms = ['kimi' => 'Kimi', 'deepseek' => 'DeepSeek', 'tongyi' => '通义千问', 'wenxin' => '文心一言', 'doubao' => '豆包', 'yuanbao' => '腾讯元宝'];

// Get competitors
$competitors = [];
try {
    $stmt = $db->prepare("SELECT competitor_name FROM geo_customer_competitors WHERE customer_id=? ORDER BY created_at LIMIT 5");
    $stmt->execute([$selectedCid]);
    $competitors = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {}

// Weekly brand mention rate
$brandWeekly = [];
try {
    $where = $selectedPlatform !== 'all' ? "AND provider_key=:plat" : "";
    $params = ['cid' => $selectedCid];
    if ($selectedPlatform !== 'all') $params['plat'] = $selectedPlatform;
    $stmt = $db->prepare("
        SELECT DATE_TRUNC('week', queried_at) as week,
               ROUND(100.0*SUM(CASE WHEN brand_mentioned THEN 1 ELSE 0 END)/NULLIF(COUNT(*),0),1) as rate,
               COUNT(*) as total
        FROM geo_monitor_records
        WHERE customer_id=:cid $where
          AND queried_at >= NOW() - INTERVAL '{$weeks} weeks'
        GROUP BY 1 ORDER BY 1
    ");
    $stmt->execute($params);
    $brandWeekly = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

// Weekly competitor mention rates (from geo_monitor_records where competitor_mentioned_name matches)
$competitorWeekly = [];
foreach ($competitors as $comp) {
    try {
        $where = $selectedPlatform !== 'all' ? "AND provider_key=?" : "";
        $params = [$selectedCid, $comp];
        if ($selectedPlatform !== 'all') $params[] = $selectedPlatform;
        $stmt = $db->prepare("
            SELECT DATE_TRUNC('week', queried_at) as week,
                   ROUND(100.0*SUM(CASE WHEN competitor_mentioned_names ILIKE ? THEN 1 ELSE 0 END)/NULLIF(COUNT(*),0),1) as rate
            FROM geo_monitor_records
            WHERE customer_id=? $where
              AND queried_at >= NOW() - INTERVAL '{$weeks} weeks'
            GROUP BY 1 ORDER BY 1
        ");
        // Reorder: competitor name first for ILIKE, then customer_id
        $paramsFinal = [$comp, $selectedCid];
        if ($selectedPlatform !== 'all') $paramsFinal[] = $selectedPlatform;
        $stmt->execute($paramsFinal);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($rows) $competitorWeekly[$comp] = $rows;
    } catch (Throwable $e) {}
}

// Build chart labels and datasets
$labels = [];
$brandData = [];
foreach ($brandWeekly as $r) {
    $labels[] = date('m/d', strtotime($r['week']));
    $brandData[] = (float)$r['rate'];
}

$compDatasets = [];
$colors = ['#ef4444','#f97316','#eab308','#22c55e','#3b82f6'];
$ci = 0;
foreach ($competitorWeekly as $comp => $rows) {
    $weekMap = [];
    foreach ($rows as $r) $weekMap[date('m/d', strtotime($r['week']))] = (float)$r['rate'];
    $compData = [];
    foreach ($labels as $lbl) $compData[] = $weekMap[$lbl] ?? null;
    $compDatasets[] = [
        'label' => $comp,
        'data' => $compData,
        'color' => $colors[$ci % count($colors)],
    ];
    $ci++;
}

// Platform-wise current snapshot (last 7 days)
$platformSnapshot = [];
try {
    $stmt = $db->prepare("
        SELECT provider_key,
               ROUND(100.0*SUM(CASE WHEN brand_mentioned THEN 1 ELSE 0 END)/NULLIF(COUNT(*),0),1) as brand_rate
        FROM geo_monitor_records
        WHERE customer_id=? AND queried_at >= NOW() - INTERVAL '7 days'
        GROUP BY provider_key
    ");
    $stmt->execute([$selectedCid]);
    $platformSnapshot = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

// Surpass events: weeks where competitor rate > brand rate
$surpassEvents = [];
foreach ($compDatasets as $cd) {
    for ($i = 0; $i < count($brandData); $i++) {
        if (isset($cd['data'][$i]) && $cd['data'][$i] !== null && $cd['data'][$i] > $brandData[$i]) {
            $surpassEvents[] = [
                'week' => $labels[$i] ?? '',
                'competitor' => $cd['label'],
                'comp_rate' => $cd['data'][$i],
                'brand_rate' => $brandData[$i],
            ];
        }
    }
}

$pageTitle = '竞品动态时间线';
require_once __DIR__ . '/includes/header.php';
?>
<div class="max-w-6xl mx-auto px-4 py-6">
  <div class="mb-6">
    <h1 class="text-2xl font-bold text-gray-900">竞品动态时间线</h1>
    <p class="text-sm text-gray-500 mt-1">品牌 vs 竞品 AI提及率历史对比</p>
  </div>

  <!-- Filters -->
  <form method="GET" class="flex flex-wrap items-center gap-3 mb-6 bg-white border border-gray-200 rounded-xl px-4 py-3">
    <select name="customer" onchange="this.form.submit()" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 outline-none">
      <?php foreach ($customers as $c): ?>
        <option value="<?= htmlspecialchars($c['customer_id']) ?>" <?= $c['customer_id']===$selectedCid?'selected':'' ?>><?= htmlspecialchars($c['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="platform" onchange="this.form.submit()" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 outline-none">
      <option value="all" <?= $selectedPlatform==='all'?'selected':'' ?>>全部平台</option>
      <?php foreach ($platforms as $k => $v): ?>
        <option value="<?= $k ?>" <?= $selectedPlatform===$k?'selected':'' ?>><?= $v ?></option>
      <?php endforeach; ?>
    </select>
    <select name="weeks" onchange="this.form.submit()" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 outline-none">
      <option value="4" <?= $weeks==4?'selected':'' ?>>近4周</option>
      <option value="8" <?= $weeks==8?'selected':'' ?>>近8周</option>
      <option value="12" <?= $weeks==12?'selected':'' ?>>近12周</option>
    </select>
    <?php if ($selectedCid): ?>
      <a href="geo-knowledge-graph.php?customer=<?= urlencode($selectedCid) ?>" class="text-xs text-indigo-600 hover:text-indigo-800 ml-auto">+ 管理竞品</a>
    <?php endif; ?>
  </form>

  <?php if (empty($brandWeekly)): ?>
    <div class="bg-white rounded-xl border border-gray-200 p-12 text-center text-gray-400">
      <div class="text-4xl mb-3">📊</div>
      <div class="font-medium mb-1">暂无监测数据</div>
      <div class="text-sm">请先在 GEO监测 中配置关键词并开始监测</div>
    </div>
  <?php else: ?>

  <!-- Surpass alerts -->
  <?php if (!empty($surpassEvents)): ?>
  <div class="mb-4 bg-red-50 border border-red-200 rounded-xl p-4">
    <h3 class="text-sm font-semibold text-red-800 mb-2">⚠ 竞品超越事件（近<?= $weeks ?>周）</h3>
    <div class="space-y-1">
      <?php foreach (array_slice($surpassEvents, 0, 5) as $ev): ?>
      <div class="text-sm text-red-700">
        <span class="font-medium"><?= $ev['week'] ?></span> 周：
        <span class="font-medium"><?= htmlspecialchars($ev['competitor']) ?></span>
        提及率 <span class="font-bold"><?= $ev['comp_rate'] ?>%</span>
        超过品牌 <span class="font-bold"><?= $ev['brand_rate'] ?>%</span>
        （差距 <?= round($ev['comp_rate'] - $ev['brand_rate'], 1) ?>%）
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- Main trend chart -->
  <div class="bg-white rounded-xl border border-gray-200 p-5 mb-4">
    <h3 class="font-semibold text-gray-800 text-sm mb-4">提及率趋势对比（周维度）</h3>
    <canvas id="trendChart" height="100"></canvas>
  </div>

  <!-- Platform snapshot -->
  <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
    <div class="bg-white rounded-xl border border-gray-200 p-5">
      <h3 class="font-semibold text-gray-800 text-sm mb-4">当前各平台提及率（近7天）</h3>
      <div class="space-y-3">
        <?php
        $platMap = [];
        foreach ($platformSnapshot as $p) $platMap[$p['provider_key']] = (float)$p['brand_rate'];
        foreach ($platforms as $key => $pname):
          $rate = $platMap[$key] ?? 0;
        ?>
        <div class="flex items-center gap-3">
          <span class="text-xs text-gray-600 w-20 flex-shrink-0"><?= $pname ?></span>
          <div class="flex-1 bg-gray-100 rounded-full h-2">
            <div class="<?= $rate >= 60 ? 'bg-green-500' : ($rate >= 30 ? 'bg-yellow-400' : 'bg-red-400') ?> h-2 rounded-full" style="width:<?= min(100,$rate) ?>%"></div>
          </div>
          <span class="text-xs font-medium w-10 text-right <?= $rate > 0 ? ($rate >= 60 ? 'text-green-700' : ($rate >= 30 ? 'text-yellow-700' : 'text-red-700')) : 'text-gray-300' ?>">
            <?= $rate > 0 ? $rate.'%' : '—' ?>
          </span>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Competitor list -->
    <div class="bg-white rounded-xl border border-gray-200 p-5">
      <h3 class="font-semibold text-gray-800 text-sm mb-4">追踪竞品（<?= count($competitors) ?>个）</h3>
      <?php if (empty($competitors)): ?>
        <p class="text-sm text-gray-400">暂未配置竞品</p>
        <a href="customers.php?id=<?= urlencode($selectedCid) ?>" class="mt-2 inline-block text-sm text-indigo-600">→ 去添加竞品</a>
      <?php else: ?>
        <div class="space-y-2">
          <?php foreach ($competitors as $i => $comp):
            $color = $colors[$i % count($colors)];
          ?>
          <div class="flex items-center gap-3 py-1.5">
            <span class="w-3 h-3 rounded-full flex-shrink-0" style="background:<?= htmlspecialchars($color) ?>"></span>
            <span class="text-sm text-gray-700"><?= htmlspecialchars($comp) ?></span>
            <?php
            // Show latest week rate vs brand
            $lastBrand = end($brandData);
            $lastComp = null;
            foreach ($compDatasets as $cd) {
                if ($cd['label'] === $comp) {
                    $lastComp = end($cd['data']);
                    break;
                }
            }
            if ($lastComp !== null && $lastBrand !== false):
                $delta = round($lastComp - $lastBrand, 1);
            ?>
            <span class="ml-auto text-xs <?= $delta > 0 ? 'text-red-600' : 'text-green-600' ?>">
              <?= $delta > 0 ? '超出 +'.$delta.'%' : '低于 '.$delta.'%' ?>
            </span>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
        <a href="customers.php?id=<?= urlencode($selectedCid) ?>" class="mt-3 inline-block text-xs text-indigo-600 hover:text-indigo-800">编辑竞品列表 →</a>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
</div>

<script src="/admin/assets/js/chart.umd.min.js"></script>
<script>
const labels = <?= json_encode($labels) ?>;
const brandData = <?= json_encode($brandData) ?>;
const compDatasets = <?= json_encode($compDatasets) ?>;

if (labels.length > 0 && document.getElementById('trendChart')) {
  const datasets = [
    {
      label: '<?= addslashes($selectedName) ?>（品牌）',
      data: brandData,
      borderColor: '#6366f1',
      backgroundColor: 'rgba(99,102,241,0.08)',
      borderWidth: 2.5,
      pointRadius: 4,
      tension: 0.3,
      fill: true,
    }
  ];
  compDatasets.forEach(cd => {
    datasets.push({
      label: cd.label,
      data: cd.data,
      borderColor: cd.color,
      backgroundColor: 'transparent',
      borderWidth: 2,
      borderDash: [5,4],
      pointRadius: 3,
      tension: 0.3,
      fill: false,
      spanGaps: true,
    });
  });

  new Chart(document.getElementById('trendChart'), {
    type: 'line',
    data: { labels, datasets },
    options: {
      responsive: true,
      plugins: {
        legend: { position: 'top' },
        tooltip: { callbacks: { label: ctx => ctx.dataset.label + ': ' + (ctx.raw !== null ? ctx.raw + '%' : '无数据') } }
      },
      scales: {
        y: { min: 0, max: 100, ticks: { callback: v => v + '%' }, grid: { color: '#f3f4f6' } },
        x: { grid: { display: false } }
      }
    }
  });
}
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
