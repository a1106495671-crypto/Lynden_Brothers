<?php
define('FEISHU_TREASURE', true);
session_start();
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/database_admin.php';
require_once __DIR__ . '/../includes/citation_simulator_service.php';
require_admin_login();

// Ensure table
try {
    $db->exec("CREATE TABLE IF NOT EXISTS geo_content_queue (
        id SERIAL PRIMARY KEY,
        customer_id VARCHAR(100),
        week_num SMALLINT DEFAULT 1,
        platform VARCHAR(50),
        keyword VARCHAR(200),
        title TEXT DEFAULT '',
        angle TEXT,
        content_format VARCHAR(50),
        priority VARCHAR(5) DEFAULT 'P1',
        status VARCHAR(20) DEFAULT 'pending',
        article_title TEXT,
        article_content TEXT,
        source VARCHAR(50) DEFAULT '',
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
} catch (Throwable $e) {}

$customers = $db->query("SELECT customer_id, name FROM customers ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$selectedCid = $_GET['customer'] ?? ($customers[0]['customer_id'] ?? '');

// AJAX: generate one article
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'generate_one') {
    set_time_limit(120);
    header('Content-Type: application/json; charset=utf-8');
    $qid = (int)($_POST['queue_id'] ?? 0);
    if (!$qid) { echo json_encode(['error' => 'invalid id']); exit; }

    $stmt = $db->prepare("SELECT * FROM geo_content_queue WHERE id=?");
    $stmt->execute([$qid]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$item) { echo json_encode(['error' => 'not found']); exit; }

    // Mark as processing
    $db->prepare("UPDATE geo_content_queue SET status='processing' WHERE id=?")->execute([$qid]);

    // Get brand facts
    $sf = $db->prepare("SELECT fact_key, fact_value FROM geo_brand_facts WHERE customer_id=?");
    $sf->execute([$item['customer_id']]);
    $facts = [];
    foreach ($sf->fetchAll(PDO::FETCH_ASSOC) as $r) $facts[$r['fact_key']] = $r['fact_value'];
    $brandName      = $facts['brand_name']      ?? $item['customer_id'];
    $masterSentence = $facts['master_sentence']  ?? '';
    $coreServices   = $facts['core_service']     ?? $facts['core_services'] ?? '';
    $differentiator = $facts['differentiator']   ?? '';

    $platform = $item['platform'];
    $keyword  = $item['keyword'];
    $angle    = $item['angle'];
    $fmt      = $item['content_format'];
    $week     = $item['week_num'];

    $platformStyle = ['知乎'=>'专业深度、有数据支撑、适合偏理性读者','今日头条'=>'口语化、接地气、有故事感','搜狐号'=>'专业媒体风格、信息密度高','微信公众号'=>'温和亲切、适合分享转发','小红书'=>'轻松活泼、有个人体验感'][$platform] ?? '专业中文内容';

    $prompt = <<<PROMPT
你是专业的GEO内容撰写专家。请为品牌【{$brandName}】撰写一篇GEO优化文章。

## 品牌信息
- 品牌定位：{$masterSentence}
- 核心服务：{$coreServices}
- 差异化：{$differentiator}

## 本篇任务
- 目标关键词：「{$keyword}」
- 文章角度：{$angle}
- 发布平台：{$platform}（{$platformStyle}）
- 内容格式：{$fmt}
- 所在周次：第{$week}周

## GEO优化要求
1. 自然植入品牌名【{$brandName}】至少4次
2. 优先使用已给出的品牌事实；没有证据时写“待补充证据”，不要编造数字
3. 包含1个FAQ板块（至少3个Q&A）
4. 结尾有明确行动召唤
5. 使用结构化格式，字数1000-1500字

请直接输出完整文章（含标题），不要任何前言和说明。
PROMPT;

    require_once __DIR__ . '/../includes/geo_ai_fallback.php';
    $aiResult = geo_call_ai_with_fallback($prompt, 3000, 0.75);
    if (!empty($aiResult['error']) && empty($aiResult['content'])) {
        $db->prepare("UPDATE geo_content_queue SET status='failed' WHERE id=?")->execute([$qid]);
        echo json_encode(['error' => 'AI调用失败: ' . $aiResult['error']]); exit;
    }
    $articleContent = $aiResult['content'] ?? '';
    $lines = explode("\n", $articleContent);
    $articleTitle = ltrim(trim($lines[0] ?? $keyword), '# ');

    $db->prepare("UPDATE geo_content_queue SET status='done', article_title=?, article_content=?, processed_at=NOW() WHERE id=?")->execute([$articleTitle, $articleContent, $qid]);

    echo json_encode(['ok' => true, 'id' => $qid, 'title' => $articleTitle, 'chars' => mb_strlen($articleContent)]);
    exit;
}

// AJAX: delete item
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $qid = (int)($_POST['queue_id'] ?? 0);
    if ($qid) $db->prepare("DELETE FROM geo_content_queue WHERE id=? AND customer_id=?")->execute([$qid, $selectedCid]);
    header('Content-Type: application/json'); echo json_encode(['ok' => true]); exit;
}

// Load queue for customer
$queue = [];
if ($selectedCid) {
    $stmt = $db->prepare("SELECT * FROM geo_content_queue WHERE customer_id=? ORDER BY week_num, priority DESC, id");
    $stmt->execute([$selectedCid]);
    $queue = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$stats = ['pending' => 0, 'done' => 0, 'failed' => 0, 'processing' => 0];
foreach ($queue as $item) {
    $stats[$item['status']] = ($stats[$item['status']] ?? 0) + 1;
}

$pageTitle = '批量生成队列';
require_once __DIR__ . '/includes/header.php';
?>
<div class="max-w-6xl mx-auto px-4 py-6">
  <div class="flex items-center justify-between mb-6">
    <div>
      <h1 class="text-2xl font-bold text-gray-900">批量生成队列</h1>
      <p class="text-sm text-gray-500 mt-1">来自策略日历和引用模拟器的文章生成任务，逐篇调用当前默认 AI 模型生成</p>
    </div>
    <div class="flex items-center gap-3">
      <select id="cidSelect" class="rounded-lg border-gray-300 text-sm shadow-sm" onchange="location='?customer='+this.value">
        <?php foreach ($customers as $c): ?>
          <option value="<?= htmlspecialchars($c['customer_id']) ?>" <?= $c['customer_id'] === $selectedCid ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <?php if ($stats['pending'] > 0): ?>
      <button id="runAllBtn" onclick="runAll()" class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">
        <i data-lucide="zap" class="w-4 h-4"></i> 开始批量生成（<?= $stats['pending'] ?>篇待处理）
      </button>
      <?php endif; ?>
      <a href="geo-strategy.php?customer=<?= urlencode($selectedCid) ?>" class="text-sm text-gray-500 hover:text-gray-700">← 返回策略</a>
    </div>
  </div>

  <!-- Stats -->
  <div class="grid grid-cols-4 gap-3 mb-6">
    <div class="bg-white rounded-xl border border-gray-200 p-3 text-center">
      <div class="text-xl font-bold text-gray-500"><?= count($queue) ?></div>
      <div class="text-xs text-gray-400 mt-0.5">总计</div>
    </div>
    <div class="bg-white rounded-xl border border-orange-200 p-3 text-center">
      <div class="text-xl font-bold text-orange-500"><?= $stats['pending'] ?></div>
      <div class="text-xs text-gray-400 mt-0.5">待生成</div>
    </div>
    <div class="bg-white rounded-xl border border-green-200 p-3 text-center">
      <div class="text-xl font-bold text-green-600"><?= $stats['done'] ?></div>
      <div class="text-xs text-gray-400 mt-0.5">已完成</div>
    </div>
    <div class="bg-white rounded-xl border border-red-200 p-3 text-center">
      <div class="text-xl font-bold text-red-500"><?= $stats['failed'] ?></div>
      <div class="text-xs text-gray-400 mt-0.5">失败</div>
    </div>
  </div>

  <div id="progressBar" class="hidden mb-4 bg-white rounded-xl border border-indigo-200 p-4">
    <div class="flex items-center justify-between mb-2">
      <span class="text-sm font-medium text-indigo-700" id="progressLabel">准备生成...</span>
      <span class="text-xs text-gray-400" id="progressCount"></span>
    </div>
    <div class="w-full bg-gray-100 rounded-full h-2">
      <div id="progressFill" class="bg-indigo-500 h-2 rounded-full transition-all duration-500" style="width:0%"></div>
    </div>
  </div>

  <?php if (empty($queue)): ?>
  <div class="text-center py-16 bg-white rounded-xl border border-dashed border-gray-300">
    <i data-lucide="inbox" class="w-10 h-10 text-gray-300 mx-auto mb-3"></i>
    <p class="text-gray-500">队列为空，先在<a href="geo-strategy.php?customer=<?= urlencode($selectedCid) ?>" class="text-indigo-600 underline">策略页面</a>生成日历并点击"批量生成文章"</p>
  </div>
  <?php else: ?>
  <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
    <table class="w-full text-sm">
      <thead class="bg-gray-50 border-b border-gray-100">
        <tr>
          <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 w-8">周</th>
          <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 w-20">平台</th>
          <th class="px-4 py-3 text-left text-xs font-medium text-gray-500">关键词 / 角度</th>
          <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 w-16">优先级</th>
          <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 w-24">状态</th>
          <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 w-32">操作</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-gray-50" id="queueBody">
        <?php foreach ($queue as $item): ?>
        <tr id="row-<?= $item['id'] ?>" class="hover:bg-gray-50">
          <td class="px-4 py-3 text-gray-400 text-xs">第<?= $item['week_num'] ?>周</td>
          <td class="px-4 py-3">
            <span class="inline-block px-2 py-0.5 rounded-full text-xs bg-indigo-100 text-indigo-700"><?= htmlspecialchars($item['platform']) ?></span>
          </td>
          <td class="px-4 py-3">
            <div class="font-medium text-gray-800 text-xs"><?= htmlspecialchars($item['keyword']) ?></div>
            <?php if ($item['article_title']): ?>
              <div class="text-xs text-green-600 mt-0.5">📄 <?= htmlspecialchars(mb_substr($item['article_title'], 0, 40)) ?></div>
            <?php elseif ($item['angle']): ?>
              <div class="text-xs text-gray-400 mt-0.5"><?= htmlspecialchars(mb_substr($item['angle'], 0, 40)) ?></div>
            <?php endif; ?>
          </td>
          <td class="px-4 py-3">
            <span class="text-xs font-bold <?= $item['priority']==='P0' ? 'text-red-600' : ($item['priority']==='P1' ? 'text-orange-500' : 'text-gray-400') ?>"><?= $item['priority'] ?></span>
          </td>
          <td class="px-4 py-3">
            <span id="status-<?= $item['id'] ?>" class="inline-flex items-center gap-1 text-xs px-2 py-0.5 rounded-full <?= match($item['status']) {
              'done' => 'bg-green-100 text-green-700',
              'failed' => 'bg-red-100 text-red-700',
              'processing' => 'bg-blue-100 text-blue-700',
              default => 'bg-gray-100 text-gray-600'
            } ?>">
              <?= match($item['status']) { 'done' => '✅ 已完成', 'failed' => '❌ 失败', 'processing' => '⏳ 生成中', default => '⏸ 待生成' } ?>
            </span>
          </td>
          <td class="px-4 py-3 flex items-center gap-2">
            <?php if ($item['status'] === 'done' && $item['article_content']): ?>
              <button onclick="viewArticle(<?= $item['id'] ?>)" class="text-xs text-indigo-600 hover:text-indigo-800">查看</button>
            <?php endif; ?>
            <?php if (in_array($item['status'], ['pending', 'failed'])): ?>
              <button onclick="generateOne(<?= $item['id'] ?>)" class="text-xs text-green-600 hover:text-green-800">生成</button>
            <?php endif; ?>
            <button onclick="deleteItem(<?= $item['id'] ?>)" class="text-xs text-red-400 hover:text-red-700">删除</button>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<!-- Article View Modal -->
<div id="articleModal" class="hidden fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
  <div class="bg-white rounded-2xl shadow-xl w-full max-w-3xl max-h-[85vh] flex flex-col">
    <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100">
      <h3 id="modalTitle" class="font-semibold text-gray-900 text-sm"></h3>
      <div class="flex items-center gap-3">
        <button onclick="copyArticle()" class="text-xs text-indigo-600 border border-indigo-300 rounded px-3 py-1.5 hover:bg-indigo-50">复制全文</button>
        <button onclick="document.getElementById('articleModal').classList.add('hidden')" class="text-gray-400 hover:text-gray-700"><i data-lucide="x" class="w-5 h-5"></i></button>
      </div>
    </div>
    <div class="overflow-y-auto p-6 flex-1">
      <pre id="modalContent" class="whitespace-pre-wrap text-sm text-gray-700 font-sans leading-relaxed"></pre>
    </div>
  </div>
</div>

<script>
const pendingIds = <?= json_encode(array_values(array_map(fn($i) => $i['id'], array_filter($queue, fn($i) => $i['status'] === 'pending')))) ?>;
const allArticles = <?= json_encode(array_column($queue, null, 'id')) ?>;
let currentArticleContent = '';

async function generateOne(qid) {
  document.getElementById('status-' + qid).innerHTML = '⏳ 生成中';
  document.getElementById('status-' + qid).className = 'inline-flex items-center gap-1 text-xs px-2 py-0.5 rounded-full bg-blue-100 text-blue-700';
  try {
    const res = await fetch('', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:`action=generate_one&queue_id=${qid}`});
    const data = await res.json();
    if (data.ok) {
      document.getElementById('status-' + qid).innerHTML = '✅ 已完成';
      document.getElementById('status-' + qid).className = 'inline-flex items-center gap-1 text-xs px-2 py-0.5 rounded-full bg-green-100 text-green-700';
      const row = document.getElementById('row-' + qid);
      const titleDiv = row.querySelector('.font-medium + div') || row.querySelector('.text-xs.text-gray-400');
      if (titleDiv) { titleDiv.className = 'text-xs text-green-600 mt-0.5'; titleDiv.textContent = '📄 ' + data.title; }
      return true;
    } else {
      document.getElementById('status-' + qid).innerHTML = '❌ 失败';
      document.getElementById('status-' + qid).className = 'inline-flex items-center gap-1 text-xs px-2 py-0.5 rounded-full bg-red-100 text-red-700';
      return false;
    }
  } catch(e) {
    document.getElementById('status-' + qid).innerHTML = '❌ 失败';
    return false;
  }
}

async function runAll() {
  const btn = document.getElementById('runAllBtn');
  btn.disabled = true;
  const bar = document.getElementById('progressBar');
  bar.classList.remove('hidden');
  const ids = pendingIds;
  let done = 0;
  for (const qid of ids) {
    document.getElementById('progressLabel').textContent = `正在生成第 ${done+1}/${ids.length} 篇...`;
    document.getElementById('progressCount').textContent = `${done}/${ids.length}`;
    document.getElementById('progressFill').style.width = (done/ids.length*100) + '%';
    await generateOne(qid);
    done++;
    await new Promise(r => setTimeout(r, 1500)); // brief pause between requests
  }
  document.getElementById('progressFill').style.width = '100%';
  document.getElementById('progressLabel').textContent = `✅ 全部完成！共生成 ${done} 篇文章`;
  document.getElementById('progressCount').textContent = `${done}/${ids.length}`;
  btn.disabled = false;
}

async function deleteItem(qid) {
  if (!confirm('删除此条目？')) return;
  await fetch('', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:`action=delete&queue_id=${qid}&customer_id=<?= urlencode($selectedCid) ?>`});
  document.getElementById('row-' + qid).remove();
}

function viewArticle(qid) {
  // Reload to get content - use modal with server data
  fetch('?customer=<?= urlencode($selectedCid) ?>&get_article=' + qid)
    .then(r => r.text()).then(() => {});
  // Use cached data if available, else prompt reload
  location.href = '?customer=<?= urlencode($selectedCid) ?>&view=' + qid;
}

function copyArticle() {
  navigator.clipboard.writeText(document.getElementById('modalContent').textContent).then(() => alert('已复制到剪贴板'));
}
</script>
<?php
// Handle view article
if (isset($_GET['view']) && $selectedCid) {
    $vid = (int)$_GET['view'];
    $stmt = $db->prepare("SELECT article_title, article_content FROM geo_content_queue WHERE id=? AND customer_id=?");
    $stmt->execute([$vid, $selectedCid]);
    $va = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($va) {
        echo '<script>
document.addEventListener("DOMContentLoaded", function() {
  document.getElementById("modalTitle").textContent = ' . json_encode($va['article_title']) . ';
  document.getElementById("modalContent").textContent = ' . json_encode($va['article_content']) . ';
  document.getElementById("articleModal").classList.remove("hidden");
});
</script>';
    }
}
require_once __DIR__ . '/includes/footer.php';
?>
