<?php
/**
 * 效果归因 - 文章发布前后提及率对比
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
$selectedName = '';
foreach ($customers as $c) {
    if ($c['customer_id'] === $selectedCid) { $selectedName = $c['name']; break; }
}

// 取该客户所有已发布文章（有发布时间）
$articles = [];
try {
    $stmt = $db->prepare("
        SELECT id, title, platform, keyword, remote_url,
               COALESCE(published_at, updated_at, created_at) AS pub_date,
               created_at
        FROM articles
        WHERE customer_id=? AND status='published'
        ORDER BY COALESCE(published_at, updated_at, created_at) DESC
        LIMIT 30
    ");
    $stmt->execute([$selectedCid]);
    $articles = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    // fallback: try without published_at column
    try {
        $stmt = $db->prepare("
            SELECT id, title, platform, keyword, remote_url,
                   created_at AS pub_date, created_at
            FROM articles
            WHERE customer_id=? AND status='published'
            ORDER BY created_at DESC LIMIT 30
        ");
        $stmt->execute([$selectedCid]);
        $articles = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e2) {}
}

// 对每篇文章计算前后7天提及率
$impactData = [];
foreach ($articles as $a) {
    $pubDate = $a['pub_date'] ?? $a['created_at'];
    if (!$pubDate) continue;

    $kw = trim($a['keyword'] ?? '');
    $platform = trim($a['platform'] ?? '');

    // 关键词条件：有keyword字段则按keyword过滤，否则全量
    $kwWhere = $kw ? "AND query_text ILIKE ?" : "";
    $platWhere = $platform ? "AND provider_key = ?" : "";

    $buildParams = function(string $start, string $end) use ($selectedCid, $kw, $platform) {
        $p = [$selectedCid, $start, $end];
        if ($kw) $p[] = '%' . $kw . '%';
        if ($platform) $p[] = $platform;
        return $p;
    };

    $beforeStart = date('Y-m-d H:i:s', strtotime($pubDate) - 7 * 86400);
    $afterEnd    = date('Y-m-d H:i:s', strtotime($pubDate) + 7 * 86400);

    try {
        // 发布前7天
        $sql = "SELECT ROUND(100.0*SUM(CASE WHEN brand_mentioned THEN 1 ELSE 0 END)/NULLIF(COUNT(*),0),1) as rate, COUNT(*) as n
                FROM geo_monitor_records
                WHERE customer_id=? AND queried_at BETWEEN ? AND ? $kwWhere $platWhere";
        $stmt = $db->prepare($sql);
        $stmt->execute($buildParams($beforeStart, $pubDate));
        $before = $stmt->fetch(PDO::FETCH_ASSOC);

        // 发布后7天
        $stmt2 = $db->prepare($sql);
        $stmt2->execute($buildParams($pubDate, $afterEnd));
        $after = $stmt2->fetch(PDO::FETCH_ASSOC);

        $beforeRate = $before['n'] > 0 ? (float)$before['rate'] : null;
        $afterRate  = $after['n']  > 0 ? (float)$after['rate']  : null;
        $delta = ($beforeRate !== null && $afterRate !== null) ? round($afterRate - $beforeRate, 1) : null;

        $impactData[] = [
            'id'          => $a['id'],
            'title'       => $a['title'],
            'platform'    => $a['platform'],
            'keyword'     => $kw,
            'pub_date'    => $pubDate,
            'remote_url'  => $a['remote_url'] ?? '',
            'before_rate' => $beforeRate,
            'after_rate'  => $afterRate,
            'delta'       => $delta,
            'before_n'    => (int)($before['n'] ?? 0),
            'after_n'     => (int)($after['n'] ?? 0),
        ];
    } catch (Throwable $e) {
        $impactData[] = [
            'id' => $a['id'], 'title' => $a['title'], 'platform' => $a['platform'],
            'keyword' => $kw, 'pub_date' => $pubDate, 'remote_url' => $a['remote_url'] ?? '',
            'before_rate' => null, 'after_rate' => null, 'delta' => null,
            'before_n' => 0, 'after_n' => 0,
        ];
    }
}

// 统计汇总
$withData  = array_filter($impactData, fn($r) => $r['delta'] !== null);
$positive  = array_filter($withData, fn($r) => $r['delta'] > 0);
$negative  = array_filter($withData, fn($r) => $r['delta'] < 0);
$avgDelta  = count($withData) ? round(array_sum(array_column(array_values($withData), 'delta')) / count($withData), 1) : 0;

$pageTitle = '效果归因';
require_once __DIR__ . '/includes/header.php';
?>
<div class="max-w-6xl mx-auto px-4 py-6">
  <div class="mb-6 flex items-center justify-between">
    <div>
      <h1 class="text-2xl font-bold text-gray-900">效果归因</h1>
      <p class="text-sm text-gray-500 mt-1">文章发布前7天 vs 后7天关键词AI提及率对比</p>
    </div>
    <form method="GET" class="flex items-center gap-2">
      <select name="customer" onchange="this.form.submit()" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-indigo-500 outline-none">
        <?php foreach ($customers as $c): ?>
          <option value="<?= htmlspecialchars($c['customer_id']) ?>" <?= $c['customer_id']===$selectedCid?'selected':'' ?>><?= htmlspecialchars($c['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </form>
  </div>

  <?php if (!empty($withData)): ?>
  <!-- 汇总卡片 -->
  <div class="grid grid-cols-4 gap-4 mb-6">
    <div class="bg-white rounded-xl border border-gray-200 p-4 text-center">
      <div class="text-3xl font-bold text-gray-800"><?= count($impactData) ?></div>
      <div class="text-xs text-gray-500 mt-1">分析文章数</div>
    </div>
    <div class="bg-white rounded-xl border border-gray-200 p-4 text-center">
      <div class="text-3xl font-bold <?= $avgDelta >= 0 ? 'text-green-600' : 'text-red-600' ?>"><?= $avgDelta >= 0 ? '+' : '' ?><?= $avgDelta ?>%</div>
      <div class="text-xs text-gray-500 mt-1">平均提及率变化</div>
    </div>
    <div class="bg-white rounded-xl border border-gray-200 p-4 text-center">
      <div class="text-3xl font-bold text-green-600"><?= count($positive) ?></div>
      <div class="text-xs text-gray-500 mt-1">有正向效果</div>
    </div>
    <div class="bg-white rounded-xl border border-gray-200 p-4 text-center">
      <div class="text-3xl font-bold text-red-500"><?= count($negative) ?></div>
      <div class="text-xs text-gray-500 mt-1">无明显提升</div>
    </div>
  </div>
  <?php endif; ?>

  <!-- 文章效果表格 -->
  <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
    <table class="w-full text-sm">
      <thead class="bg-gray-50 border-b border-gray-200">
        <tr>
          <th class="px-4 py-3 text-left text-xs font-medium text-gray-500">文章标题</th>
          <th class="px-4 py-3 text-left text-xs font-medium text-gray-500">平台</th>
          <th class="px-4 py-3 text-left text-xs font-medium text-gray-500">关键词</th>
          <th class="px-4 py-3 text-left text-xs font-medium text-gray-500">发布时间</th>
          <th class="px-4 py-3 text-center text-xs font-medium text-gray-500">发布前7天</th>
          <th class="px-4 py-3 text-center text-xs font-medium text-gray-500">发布后7天</th>
          <th class="px-4 py-3 text-center text-xs font-medium text-gray-500">变化</th>
          <th class="px-4 py-3 text-left text-xs font-medium text-gray-500">原文</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-gray-100">
        <?php if (empty($impactData)): ?>
        <tr><td colspan="8" class="px-4 py-12 text-center text-gray-400">
          <div class="text-4xl mb-2">📄</div>
          <div>暂无已发布文章数据</div>
          <div class="text-xs mt-1">发布文章后，系统将自动计算效果归因</div>
        </td></tr>
        <?php else: ?>
        <?php foreach ($impactData as $r):
          $deltaClass = '';
          $deltaLabel = '—';
          if ($r['delta'] !== null) {
              if ($r['delta'] > 2)  { $deltaClass = 'text-green-700 bg-green-50'; $deltaLabel = '+' . $r['delta'] . '%'; }
              elseif ($r['delta'] > 0) { $deltaClass = 'text-green-600 bg-green-50'; $deltaLabel = '+' . $r['delta'] . '%'; }
              elseif ($r['delta'] < -2) { $deltaClass = 'text-red-600 bg-red-50';   $deltaLabel = $r['delta'] . '%'; }
              elseif ($r['delta'] < 0) { $deltaClass = 'text-orange-600 bg-orange-50'; $deltaLabel = $r['delta'] . '%'; }
              else { $deltaClass = 'text-gray-500 bg-gray-50'; $deltaLabel = '0%'; }
          }
        ?>
        <tr class="hover:bg-gray-50">
          <td class="px-4 py-3">
            <div class="text-sm font-medium text-gray-800 max-w-xs truncate" title="<?= htmlspecialchars($r['title']) ?>"><?= htmlspecialchars(mb_substr($r['title'], 0, 30)) ?></div>
          </td>
          <td class="px-4 py-3">
            <span class="text-xs bg-indigo-50 text-indigo-700 px-2 py-0.5 rounded"><?= htmlspecialchars($r['platform'] ?? '—') ?></span>
          </td>
          <td class="px-4 py-3 text-xs text-gray-500 max-w-24 truncate"><?= htmlspecialchars($r['keyword'] ?: '全量') ?></td>
          <td class="px-4 py-3 text-xs text-gray-400"><?= date('m/d', strtotime($r['pub_date'])) ?></td>
          <td class="px-4 py-3 text-center">
            <?php if ($r['before_rate'] !== null): ?>
              <span class="text-sm font-medium text-gray-600"><?= $r['before_rate'] ?>%</span>
              <span class="text-xs text-gray-300 ml-1">(<?= $r['before_n'] ?>次)</span>
            <?php else: ?>
              <span class="text-gray-300 text-xs">无数据</span>
            <?php endif; ?>
          </td>
          <td class="px-4 py-3 text-center">
            <?php if ($r['after_rate'] !== null): ?>
              <span class="text-sm font-medium text-gray-700"><?= $r['after_rate'] ?>%</span>
              <span class="text-xs text-gray-300 ml-1">(<?= $r['after_n'] ?>次)</span>
            <?php else: ?>
              <span class="text-gray-300 text-xs">无数据</span>
            <?php endif; ?>
          </td>
          <td class="px-4 py-3 text-center">
            <?php if ($r['delta'] !== null): ?>
              <span class="inline-block text-xs font-bold px-2 py-0.5 rounded-full <?= $deltaClass ?>"><?= $deltaLabel ?></span>
            <?php else: ?>
              <span class="text-gray-300 text-xs">—</span>
            <?php endif; ?>
          </td>
          <td class="px-4 py-3">
            <?php if ($r['remote_url']): ?>
              <a href="<?= htmlspecialchars($r['remote_url']) ?>" target="_blank" class="text-xs text-indigo-600 hover:text-indigo-800">查看 ↗</a>
            <?php else: ?>
              <span class="text-gray-300 text-xs">—</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <p class="text-xs text-gray-400 mt-3">* 对比逻辑：以文章发布时间为基准，取前后各7天的AI平台监测数据计算提及率变化。数据量不足时显示「无数据」。</p>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
