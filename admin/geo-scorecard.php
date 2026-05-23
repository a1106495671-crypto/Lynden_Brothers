<?php
/**
 * GEO 内容质量看板
 * 三维评分分布 + 低分预警 + 红线统计
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
$scoreFilter = $_GET['score'] ?? 'all'; // all | low | medium | good

// 统计汇总
$stats = ['total' => 0, 'avg_score' => 0, 'good' => 0, 'medium' => 0, 'low' => 0, 'red_line' => 0, 'no_master' => 0];
try {
    $stmt = $db->prepare("
        SELECT
            COUNT(*) as total,
            ROUND(AVG(geo_score)) as avg_score,
            COUNT(*) FILTER (WHERE geo_score >= 70) as good,
            COUNT(*) FILTER (WHERE geo_score >= 50 AND geo_score < 70) as medium,
            COUNT(*) FILTER (WHERE geo_score < 50) as low,
            COUNT(*) FILTER (WHERE red_line_violations <> '[]') as red_line,
            COUNT(*) FILTER (WHERE has_master_sentence = FALSE) as no_master
        FROM geo_article_scores
        WHERE customer_id = ?
    ");
    $stmt->execute([$selectedCid]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC) ?: $stats;
} catch (Throwable $e) {}

// 常见问题 TOP 汇总
$topIssues = [];
try {
    $stmt = $db->prepare("SELECT issues FROM geo_article_scores WHERE customer_id = ? AND issues <> '[]'");
    $stmt->execute([$selectedCid]);
    $issueCounts = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $rawJson) {
        $arr = json_decode($rawJson, true);
        if (is_array($arr)) {
            foreach ($arr as $issue) {
                $key = mb_substr($issue, 0, 20, 'UTF-8');
                $issueCounts[$key] = ($issueCounts[$key] ?? 0) + 1;
            }
        }
    }
    arsort($issueCounts);
    $topIssues = array_slice($issueCounts, 0, 6, true);
} catch (Throwable $e) {}

// 文章列表
$whereScore = match($scoreFilter) {
    'low'    => 'AND gs.geo_score < 50',
    'medium' => 'AND gs.geo_score >= 50 AND gs.geo_score < 70',
    'good'   => 'AND gs.geo_score >= 70',
    default  => '',
};

$articles = [];
try {
    $stmt = $db->prepare("
        SELECT a.id, a.title, a.created_at,
               gs.geo_score, gs.structure_score, gs.fact_density_score, gs.brand_compliance_score,
               gs.red_line_violations, gs.has_faq, gs.brand_mention_count, gs.has_master_sentence,
               gs.issues, gs.scored_at
        FROM geo_article_scores gs
        JOIN articles a ON a.id = gs.article_id
        WHERE gs.customer_id = ? $whereScore
        ORDER BY gs.geo_score ASC, gs.scored_at DESC
        LIMIT 100
    ");
    $stmt->execute([$selectedCid]);
    $articles = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

// 三维分项均值
$dimAvg = ['structure' => 0, 'fact' => 0, 'brand' => 0];
try {
    $stmt = $db->prepare("
        SELECT ROUND(AVG(structure_score)) as s, ROUND(AVG(fact_density_score)) as f, ROUND(AVG(brand_compliance_score)) as b
        FROM geo_article_scores WHERE customer_id = ?
    ");
    $stmt->execute([$selectedCid]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($r) $dimAvg = ['structure' => (int)$r['s'], 'fact' => (int)$r['f'], 'brand' => (int)$r['b']];
} catch (Throwable $e) {}

$pageTitle = 'GEO质量看板';
require_once __DIR__ . '/includes/header.php';
?>
<div class="max-w-7xl mx-auto px-4 py-6">
  <div class="mb-6 flex items-center justify-between flex-wrap gap-3">
    <div>
      <h1 class="text-2xl font-bold text-gray-900">GEO质量看板</h1>
      <p class="text-sm text-gray-500 mt-1">内容三维评分分布 · 低分预警 · 红线统计</p>
    </div>
    <form method="GET" class="flex items-center gap-2">
      <select name="customer" onchange="this.form.submit()" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-indigo-500 outline-none">
        <?php foreach ($customers as $c): ?>
          <option value="<?= htmlspecialchars($c['customer_id']) ?>" <?= $c['customer_id']===$selectedCid?'selected':'' ?>><?= htmlspecialchars($c['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <input type="hidden" name="score" value="<?= htmlspecialchars($scoreFilter) ?>">
    </form>
  </div>

  <?php if ($stats['total'] == 0): ?>
  <div class="bg-white rounded-xl border border-gray-200 p-12 text-center text-gray-400">
    <div class="text-4xl mb-3">📊</div>
    <div class="font-medium mb-1">暂无评分数据</div>
    <div class="text-sm">请先在任务中开启 GEO模式 生成文章，系统会自动评分</div>
  </div>
  <?php else: ?>

  <!-- 顶部统计卡片 -->
  <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
    <div class="bg-white rounded-xl border border-gray-200 p-4">
      <div class="text-xs text-gray-500 mb-1">已评分文章</div>
      <div class="text-2xl font-bold text-gray-800"><?= (int)$stats['total'] ?></div>
    </div>
    <div class="bg-white rounded-xl border border-gray-200 p-4">
      <div class="text-xs text-gray-500 mb-1">平均GEO分</div>
      <div class="text-2xl font-bold <?= (int)$stats['avg_score'] >= 70 ? 'text-green-600' : ((int)$stats['avg_score'] >= 50 ? 'text-yellow-600' : 'text-red-600') ?>"><?= (int)$stats['avg_score'] ?></div>
    </div>
    <div class="bg-white rounded-xl border border-gray-200 p-4">
      <div class="text-xs text-gray-500 mb-1">红线违规文章</div>
      <div class="text-2xl font-bold <?= (int)$stats['red_line'] > 0 ? 'text-red-600' : 'text-gray-800' ?>"><?= (int)$stats['red_line'] ?></div>
    </div>
    <div class="bg-white rounded-xl border border-gray-200 p-4">
      <div class="text-xs text-gray-500 mb-1">母句缺失</div>
      <div class="text-2xl font-bold <?= (int)$stats['no_master'] > 0 ? 'text-orange-500' : 'text-gray-800' ?>"><?= (int)$stats['no_master'] ?></div>
    </div>
  </div>

  <!-- 分布 + 分项均值 -->
  <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
    <!-- 评级分布 -->
    <div class="bg-white rounded-xl border border-gray-200 p-5">
      <h3 class="font-semibold text-sm text-gray-800 mb-4">评级分布</h3>
      <div class="space-y-3">
        <?php
        $total = max(1, (int)$stats['total']);
        $bands = [
            ['label' => '优（≥70分）', 'count' => (int)$stats['good'],   'color' => 'bg-green-500',  'text' => 'text-green-700',  'filter' => 'good'],
            ['label' => '中（50-69分）','count' => (int)$stats['medium'], 'color' => 'bg-yellow-400', 'text' => 'text-yellow-700', 'filter' => 'medium'],
            ['label' => '低（<50分）',  'count' => (int)$stats['low'],    'color' => 'bg-red-400',    'text' => 'text-red-700',    'filter' => 'low'],
        ];
        foreach ($bands as $b):
            $pct = round($b['count'] / $total * 100);
        ?>
        <div>
          <div class="flex justify-between text-xs mb-1">
            <a href="?customer=<?= urlencode($selectedCid) ?>&score=<?= $b['filter'] ?>" class="text-gray-600 hover:text-indigo-600"><?= $b['label'] ?></a>
            <span class="<?= $b['text'] ?> font-medium"><?= $b['count'] ?>篇 (<?= $pct ?>%)</span>
          </div>
          <div class="bg-gray-100 rounded-full h-2">
            <div class="<?= $b['color'] ?> h-2 rounded-full" style="width:<?= $pct ?>%"></div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- 三维分项均值 -->
    <div class="bg-white rounded-xl border border-gray-200 p-5">
      <h3 class="font-semibold text-sm text-gray-800 mb-4">三维分项均值</h3>
      <div class="space-y-4">
        <?php
        $dims = [
            ['label' => '结构分', 'max' => 40, 'val' => $dimAvg['structure'], 'color' => 'bg-indigo-500'],
            ['label' => '事实密度', 'max' => 30, 'val' => $dimAvg['fact'],     'color' => 'bg-blue-500'],
            ['label' => '品牌合规', 'max' => 30, 'val' => $dimAvg['brand'],    'color' => 'bg-violet-500'],
        ];
        foreach ($dims as $d):
            $pct = $d['max'] > 0 ? round($d['val'] / $d['max'] * 100) : 0;
        ?>
        <div>
          <div class="flex justify-between text-xs mb-1">
            <span class="text-gray-600"><?= $d['label'] ?></span>
            <span class="font-medium text-gray-700"><?= $d['val'] ?> / <?= $d['max'] ?></span>
          </div>
          <div class="bg-gray-100 rounded-full h-2.5">
            <div class="<?= $d['color'] ?> h-2.5 rounded-full" style="width:<?= $pct ?>%"></div>
          </div>
        </div>
        <?php endforeach; ?>

        <?php if (!empty($topIssues)): ?>
        <div class="mt-4 pt-4 border-t border-gray-100">
          <div class="text-xs font-medium text-gray-600 mb-2">高频问题 TOP</div>
          <?php foreach ($topIssues as $issue => $cnt): ?>
          <div class="flex justify-between text-xs text-gray-500 py-0.5">
            <span class="truncate max-w-[180px]"><?= htmlspecialchars($issue) ?>…</span>
            <span class="ml-2 text-orange-500 font-medium"><?= $cnt ?>篇</span>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- 文章列表 -->
  <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
    <div class="px-5 py-3 border-b border-gray-100 flex items-center justify-between">
      <h3 class="font-semibold text-sm text-gray-800">
        文章评分明细
        <?php if ($scoreFilter !== 'all'): ?>
          <span class="ml-2 text-xs px-2 py-0.5 bg-gray-100 text-gray-600 rounded-full">
            筛选：<?= $scoreFilter === 'low' ? '低分' : ($scoreFilter === 'medium' ? '中等' : '优质') ?>
          </span>
        <?php endif; ?>
      </h3>
      <div class="flex gap-2 text-xs">
        <?php foreach (['all' => '全部', 'low' => '低分', 'medium' => '中等', 'good' => '优质'] as $f => $l): ?>
        <a href="?customer=<?= urlencode($selectedCid) ?>&score=<?= $f ?>"
           class="px-2.5 py-1 rounded-full <?= $scoreFilter===$f ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200' ?>">
          <?= $l ?>
        </a>
        <?php endforeach; ?>
      </div>
    </div>

    <?php if (empty($articles)): ?>
    <div class="p-8 text-center text-gray-400 text-sm">该筛选条件下暂无文章</div>
    <?php else: ?>
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="bg-gray-50 text-xs text-gray-500 uppercase">
          <tr>
            <th class="px-4 py-3 text-left">文章标题</th>
            <th class="px-4 py-3 text-center">GEO总分</th>
            <th class="px-4 py-3 text-center">结构<span class="text-gray-400">/40</span></th>
            <th class="px-4 py-3 text-center">事实<span class="text-gray-400">/30</span></th>
            <th class="px-4 py-3 text-center">合规<span class="text-gray-400">/30</span></th>
            <th class="px-4 py-3 text-center">红线</th>
            <th class="px-4 py-3 text-left">主要问题</th>
            <th class="px-4 py-3 text-center">评分时间</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-50">
          <?php foreach ($articles as $art):
            $gs = (int)$art['geo_score'];
            $gsBg = $gs >= 70 ? '#dcfce7' : ($gs >= 50 ? '#fef9c3' : '#fee2e2');
            $gsColor = $gs >= 70 ? '#15803d' : ($gs >= 50 ? '#854d0e' : '#b91c1c');
            $rl = json_decode($art['red_line_violations'] ?? '[]', true);
            $issues = json_decode($art['issues'] ?? '[]', true);
          ?>
          <tr class="hover:bg-gray-50">
            <td class="px-4 py-3 max-w-xs">
              <a href="article-view.php?id=<?= $art['id'] ?>" target="_blank"
                 class="text-gray-800 hover:text-indigo-600 line-clamp-2 text-xs font-medium">
                <?= htmlspecialchars($art['title']) ?>
              </a>
            </td>
            <td class="px-4 py-3 text-center">
              <span style="display:inline-flex;align-items:center;padding:2px 10px;border-radius:9999px;font-size:13px;font-weight:700;background:<?= $gsBg ?>;color:<?= $gsColor ?>">
                <?= $gs ?>
              </span>
            </td>
            <td class="px-4 py-3 text-center text-xs <?= (int)$art['structure_score'] < 20 ? 'text-red-500 font-medium' : 'text-gray-600' ?>">
              <?= (int)$art['structure_score'] ?>
            </td>
            <td class="px-4 py-3 text-center text-xs <?= (int)$art['fact_density_score'] < 12 ? 'text-red-500 font-medium' : 'text-gray-600' ?>">
              <?= (int)$art['fact_density_score'] ?>
            </td>
            <td class="px-4 py-3 text-center text-xs <?= (int)$art['brand_compliance_score'] < 15 ? 'text-orange-500 font-medium' : 'text-gray-600' ?>">
              <?= (int)$art['brand_compliance_score'] ?>
            </td>
            <td class="px-4 py-3 text-center">
              <?php if (!empty($rl)): ?>
                <span class="text-xs text-red-600 font-medium">×<?= count($rl) ?></span>
              <?php else: ?>
                <span class="text-xs text-green-500">✓</span>
              <?php endif; ?>
            </td>
            <td class="px-4 py-3">
              <?php if (!empty($issues)): ?>
                <div class="text-xs text-gray-500 space-y-0.5">
                  <?php foreach (array_slice($issues, 0, 2) as $iss): ?>
                    <div class="text-orange-600">· <?= htmlspecialchars(mb_substr($iss, 0, 32, 'UTF-8')) ?><?= mb_strlen($iss,'UTF-8')>32?'…':'' ?></div>
                  <?php endforeach; ?>
                </div>
              <?php else: ?>
                <span class="text-xs text-green-500">无问题</span>
              <?php endif; ?>
            </td>
            <td class="px-4 py-3 text-center text-xs text-gray-400">
              <?= date('m/d H:i', strtotime($art['scored_at'])) ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
