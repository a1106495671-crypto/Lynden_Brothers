<?php
/**
 * GEO 内容补充建议
 * 读取监测数据中品牌提及率低的关键词，一键创建写作任务
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
$days = max(7, min(30, (int)($_GET['days'] ?? 14)));
$rateThreshold = max(10, min(60, (int)($_GET['threshold'] ?? 30)));

// 创建写作任务（加入 geo_content_queue）
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_queue') {
    $keyword = trim($_POST['keyword'] ?? '');
    $cid = trim($_POST['customer_id'] ?? '');
    if ($keyword && $cid) {
        try {
            $db->exec("CREATE TABLE IF NOT EXISTS geo_content_queue (
                id SERIAL PRIMARY KEY,
                customer_id VARCHAR(100),
                keyword TEXT,
                title TEXT DEFAULT '',
                priority VARCHAR(5) DEFAULT 'P1',
                week_num INT DEFAULT 0,
                platform VARCHAR(50) DEFAULT '',
                angle TEXT DEFAULT '',
                content_format VARCHAR(50) DEFAULT '',
                status VARCHAR(20) DEFAULT 'pending',
                article_title TEXT DEFAULT '',
                article_content TEXT DEFAULT '',
                source VARCHAR(50) DEFAULT 'monitor_suggest',
                created_at TIMESTAMP DEFAULT NOW(),
                processed_at TIMESTAMP
            )");
            $db->exec("ALTER TABLE geo_content_queue ADD COLUMN IF NOT EXISTS title TEXT DEFAULT ''");
            $db->exec("ALTER TABLE geo_content_queue ADD COLUMN IF NOT EXISTS source VARCHAR(50) DEFAULT ''");
            $db->exec("ALTER TABLE geo_content_queue ADD COLUMN IF NOT EXISTS platform VARCHAR(50) DEFAULT ''");
            $db->exec("ALTER TABLE geo_content_queue ADD COLUMN IF NOT EXISTS angle TEXT DEFAULT ''");
            $db->exec("ALTER TABLE geo_content_queue ADD COLUMN IF NOT EXISTS content_format VARCHAR(50) DEFAULT ''");
            $db->exec("ALTER TABLE geo_content_queue ALTER COLUMN status SET DEFAULT 'pending'");
            $db->exec("ALTER TABLE geo_content_queue ALTER COLUMN priority SET DEFAULT 'P1'");
            $db->exec("ALTER TABLE geo_content_queue ALTER COLUMN created_at SET DEFAULT NOW()");
            $stmt = $db->prepare("INSERT INTO geo_content_queue (customer_id, keyword, title, source, priority, status, created_at) VALUES (?,?,?,?,?,'pending',NOW()) ON CONFLICT DO NOTHING");
            $stmt->execute([$cid, $keyword, '针对关键词「'.$keyword.'」补充GEO优化文章', 'monitor_suggest', 'P0']);
            $message = "success:{$keyword}";
        } catch (Throwable $e) {
            $message = "error:" . $e->getMessage();
        }
    }
    header('Content-Type: application/json');
    echo json_encode(['ok' => str_starts_with($message, 'success'), 'msg' => $message]);
    exit;
}

// 批量加入队列
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_all') {
    $cid = trim($_POST['customer_id'] ?? '');
    $keywords = json_decode($_POST['keywords'] ?? '[]', true);
    $added = 0;
    if ($cid && is_array($keywords)) {
        try {
            $db->exec("CREATE TABLE IF NOT EXISTS geo_content_queue (
                id SERIAL PRIMARY KEY, customer_id VARCHAR(100), keyword TEXT, title TEXT DEFAULT '',
                priority VARCHAR(5) DEFAULT 'P1', week_num INT DEFAULT 0, platform VARCHAR(50) DEFAULT '',
                angle TEXT DEFAULT '', content_format VARCHAR(50) DEFAULT '', status VARCHAR(20) DEFAULT 'pending',
                article_title TEXT DEFAULT '', article_content TEXT DEFAULT '',
                source VARCHAR(50) DEFAULT 'monitor_suggest', created_at TIMESTAMP DEFAULT NOW(), processed_at TIMESTAMP
            )");
            $db->exec("ALTER TABLE geo_content_queue ADD COLUMN IF NOT EXISTS title TEXT DEFAULT ''");
            $db->exec("ALTER TABLE geo_content_queue ADD COLUMN IF NOT EXISTS source VARCHAR(50) DEFAULT ''");
            $db->exec("ALTER TABLE geo_content_queue ADD COLUMN IF NOT EXISTS platform VARCHAR(50) DEFAULT ''");
            $db->exec("ALTER TABLE geo_content_queue ADD COLUMN IF NOT EXISTS angle TEXT DEFAULT ''");
            $db->exec("ALTER TABLE geo_content_queue ADD COLUMN IF NOT EXISTS content_format VARCHAR(50) DEFAULT ''");
            $db->exec("ALTER TABLE geo_content_queue ALTER COLUMN status SET DEFAULT 'pending'");
            $db->exec("ALTER TABLE geo_content_queue ALTER COLUMN priority SET DEFAULT 'P1'");
            $db->exec("ALTER TABLE geo_content_queue ALTER COLUMN created_at SET DEFAULT NOW()");
            $stmt = $db->prepare("INSERT INTO geo_content_queue (customer_id, keyword, title, source, priority, status, created_at) VALUES (?,?,?,?,?,'pending',NOW()) ON CONFLICT DO NOTHING");
            foreach ($keywords as $kw) {
                $kw = trim($kw);
                if ($kw) { $stmt->execute([$cid, $kw, '针对关键词「'.$kw.'」补充GEO优化文章', 'monitor_suggest', 'P0']); $added++; }
            }
        } catch (Throwable $e) {}
    }
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'added' => $added]);
    exit;
}

// 低提及率关键词（有监测数据）
$weakKeywords = [];
try {
    $stmt = $db->prepare("
        SELECT
            k.keyword,
            COUNT(r.id) as total_queries,
            COUNT(r.id) FILTER (WHERE r.brand_mentioned = TRUE) as brand_hits,
            ROUND(100.0 * COUNT(r.id) FILTER (WHERE r.brand_mentioned = TRUE) / NULLIF(COUNT(r.id), 0), 1) as brand_rate,
            MAX(r.queried_at) as last_queried
        FROM geo_monitor_keywords k
        LEFT JOIN geo_monitor_records r
            ON r.customer_id = k.customer_id
            AND r.query_text = k.keyword
            AND r.queried_at >= CURRENT_DATE - INTERVAL '{$days} days'
        WHERE k.customer_id = ? AND k.enabled = TRUE
        GROUP BY k.keyword
        HAVING COUNT(r.id) >= 2
           AND ROUND(100.0 * COUNT(r.id) FILTER (WHERE r.brand_mentioned = TRUE) / NULLIF(COUNT(r.id), 0), 1) < ?
        ORDER BY brand_rate ASC
        LIMIT 50
    ");
    $stmt->execute([$selectedCid, $rateThreshold]);
    $weakKeywords = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

// 无监测数据的关键词（从未被查过）
$unmonitoredKeywords = [];
try {
    $stmt = $db->prepare("
        SELECT k.keyword, k.created_at
        FROM geo_monitor_keywords k
        WHERE k.customer_id = ? AND k.enabled = TRUE
          AND NOT EXISTS (
              SELECT 1 FROM geo_monitor_records r
              WHERE r.customer_id = k.customer_id AND r.query_text = k.keyword
          )
        ORDER BY k.created_at DESC
        LIMIT 20
    ");
    $stmt->execute([$selectedCid]);
    $unmonitoredKeywords = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

// 已在写作队列中的关键词
$inQueueKeywords = [];
try {
    $stmt = $db->prepare("SELECT DISTINCT keyword FROM geo_content_queue WHERE customer_id = ? AND status IN ('pending','processing')");
    $stmt->execute([$selectedCid]);
    $inQueueKeywords = array_flip($stmt->fetchAll(PDO::FETCH_COLUMN));
} catch (Throwable $e) {}

$pageTitle = 'GEO内容建议';
require_once __DIR__ . '/includes/header.php';
?>
<div class="max-w-6xl mx-auto px-4 py-6">
  <div class="mb-6 flex items-center justify-between flex-wrap gap-3">
    <div>
      <h1 class="text-2xl font-bold text-gray-900">GEO内容补充建议</h1>
      <p class="text-sm text-gray-500 mt-1">监测数据反哺 · 低提及率关键词 → 自动加入写作队列</p>
    </div>
    <a href="geo-content-queue.php?customer=<?= urlencode($selectedCid) ?>" class="text-sm text-indigo-600 hover:text-indigo-800">→ 查看写作队列</a>
  </div>

  <!-- Filters -->
  <form method="GET" class="flex flex-wrap items-center gap-3 mb-6 bg-white border border-gray-200 rounded-xl px-4 py-3">
    <select name="customer" onchange="this.form.submit()" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-indigo-500 outline-none">
      <?php foreach ($customers as $c): ?>
        <option value="<?= htmlspecialchars($c['customer_id']) ?>" <?= $c['customer_id']===$selectedCid?'selected':'' ?>><?= htmlspecialchars($c['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="days" onchange="this.form.submit()" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-indigo-500 outline-none">
      <option value="7"  <?= $days===7?'selected':'' ?>>近7天</option>
      <option value="14" <?= $days===14?'selected':'' ?>>近14天</option>
      <option value="30" <?= $days===30?'selected':'' ?>>近30天</option>
    </select>
    <select name="threshold" onchange="this.form.submit()" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-indigo-500 outline-none">
      <option value="20" <?= $rateThreshold===20?'selected':'' ?>>提及率 &lt;20%</option>
      <option value="30" <?= $rateThreshold===30?'selected':'' ?>>提及率 &lt;30%</option>
      <option value="50" <?= $rateThreshold===50?'selected':'' ?>>提及率 &lt;50%</option>
    </select>
  </form>

  <?php if (empty($weakKeywords) && empty($unmonitoredKeywords)): ?>
  <div class="bg-white rounded-xl border border-gray-200 p-12 text-center text-gray-400">
    <div class="text-4xl mb-3">🎉</div>
    <div class="font-medium mb-1">当前筛选条件下无低提及率关键词</div>
    <div class="text-sm">说明该客户的所有被监测关键词品牌提及率均高于 <?= $rateThreshold ?>%</div>
  </div>
  <?php else: ?>

  <!-- 低提及率关键词 -->
  <?php if (!empty($weakKeywords)): ?>
  <div class="bg-white rounded-xl border border-gray-200 overflow-hidden mb-4">
    <div class="px-5 py-3 border-b border-gray-100 flex items-center justify-between">
      <div>
        <h3 class="font-semibold text-sm text-gray-800">⚠ 低提及率关键词（近<?= $days ?>天，品牌提及率 &lt;<?= $rateThreshold ?>%）</h3>
        <p class="text-xs text-gray-400 mt-0.5">这些关键词上AI大模型很少推荐你的品牌，需要补充内容</p>
      </div>
      <button onclick="addAll()" class="text-xs bg-indigo-600 text-white px-3 py-1.5 rounded-lg hover:bg-indigo-700">
        全部加入队列 (<?= count($weakKeywords) ?>)
      </button>
    </div>
    <table class="w-full text-sm">
      <thead class="bg-gray-50 text-xs text-gray-500 uppercase">
        <tr>
          <th class="px-4 py-2.5 text-left">关键词</th>
          <th class="px-4 py-2.5 text-center">查询次数</th>
          <th class="px-4 py-2.5 text-center">品牌提及率</th>
          <th class="px-4 py-2.5 text-center">最近监测</th>
          <th class="px-4 py-2.5 text-center">操作</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-gray-50" id="weakTable">
        <?php foreach ($weakKeywords as $kw):
          $rate = (float)$kw['brand_rate'];
          $inQueue = isset($inQueueKeywords[$kw['keyword']]);
        ?>
        <tr class="hover:bg-gray-50" data-kw="<?= htmlspecialchars($kw['keyword'], ENT_QUOTES) ?>">
          <td class="px-4 py-3 font-medium text-gray-800"><?= htmlspecialchars($kw['keyword']) ?></td>
          <td class="px-4 py-3 text-center text-gray-500"><?= (int)$kw['total_queries'] ?></td>
          <td class="px-4 py-3 text-center">
            <span class="text-xs font-bold <?= $rate < 10 ? 'text-red-600' : ($rate < 20 ? 'text-orange-500' : 'text-yellow-600') ?>">
              <?= $rate ?>%
            </span>
            <div class="w-20 mx-auto mt-1 bg-gray-100 rounded-full h-1.5">
              <div class="bg-red-400 h-1.5 rounded-full" style="width:<?= min(100,$rate) ?>%"></div>
            </div>
          </td>
          <td class="px-4 py-3 text-center text-xs text-gray-400">
            <?= $kw['last_queried'] ? date('m/d', strtotime($kw['last_queried'])) : '—' ?>
          </td>
          <td class="px-4 py-3 text-center">
            <?php if ($inQueue): ?>
              <span class="text-xs text-green-600 bg-green-50 px-2 py-1 rounded-full">已在队列</span>
            <?php else: ?>
              <button onclick="addOne(this, '<?= htmlspecialchars($kw['keyword'], ENT_QUOTES) ?>')"
                      class="text-xs bg-indigo-50 text-indigo-700 hover:bg-indigo-100 px-3 py-1 rounded-lg border border-indigo-200">
                加入写作队列
              </button>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <!-- 未监测关键词 -->
  <?php if (!empty($unmonitoredKeywords)): ?>
  <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
    <div class="px-5 py-3 border-b border-gray-100">
      <h3 class="font-semibold text-sm text-gray-800">📋 配置但未开始监测的关键词</h3>
      <p class="text-xs text-gray-400 mt-0.5">这些关键词已配置但尚无监测记录，建议补充内容后开启监测</p>
    </div>
    <div class="px-5 py-4 flex flex-wrap gap-2">
      <?php foreach ($unmonitoredKeywords as $kw): ?>
      <span class="inline-flex items-center gap-1.5 text-sm bg-gray-50 border border-gray-200 text-gray-600 px-3 py-1.5 rounded-lg">
        <?= htmlspecialchars($kw['keyword']) ?>
        <?php if (!isset($inQueueKeywords[$kw['keyword']])): ?>
        <button onclick="addOne(this, '<?= htmlspecialchars($kw['keyword'], ENT_QUOTES) ?>')"
                class="ml-1 text-indigo-500 hover:text-indigo-700 text-xs font-medium">+加入队列</button>
        <?php else: ?>
        <span class="text-xs text-green-500">✓</span>
        <?php endif; ?>
      </span>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <?php endif; ?>
</div>

<script>
const CID = '<?= htmlspecialchars($selectedCid, ENT_QUOTES) ?>';

async function addOne(btn, keyword) {
    btn.disabled = true;
    btn.textContent = '添加中…';
    const fd = new FormData();
    fd.append('action', 'add_queue');
    fd.append('customer_id', CID);
    fd.append('keyword', keyword);
    const res = await fetch('', {method:'POST', body: fd});
    const data = await res.json();
    if (data.ok) {
        btn.closest('td, span').innerHTML = '<span class="text-xs text-green-600 bg-green-50 px-2 py-1 rounded-full">已加入队列</span>';
    } else {
        btn.disabled = false;
        btn.textContent = '重试';
        alert('添加失败: ' + data.msg);
    }
}

async function addAll() {
    const rows = document.querySelectorAll('#weakTable tr[data-kw]');
    const keywords = [];
    rows.forEach(r => {
        const btn = r.querySelector('button');
        if (btn && !btn.disabled) keywords.push(r.dataset.kw);
    });
    if (!keywords.length) { alert('没有待加入的关键词'); return; }
    if (!confirm(`将 ${keywords.length} 个关键词加入写作队列？`)) return;
    const fd = new FormData();
    fd.append('action', 'add_all');
    fd.append('customer_id', CID);
    fd.append('keywords', JSON.stringify(keywords));
    const res = await fetch('', {method:'POST', body: fd});
    const data = await res.json();
    if (data.ok) {
        document.querySelectorAll('#weakTable button').forEach(btn => {
            btn.closest('td').innerHTML = '<span class="text-xs text-green-600 bg-green-50 px-2 py-1 rounded-full">已在队列</span>';
        });
        alert(`已添加 ${data.added} 个关键词到写作队列`);
    }
}
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
