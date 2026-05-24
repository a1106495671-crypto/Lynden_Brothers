<?php
define('FEISHU_TREASURE', true);
session_start();
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database_admin.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/citation_simulator_service.php';
require_admin_login();

// Ensure table
try {
    $db->exec("CREATE TABLE IF NOT EXISTS geo_brand_knowledge (
        id SERIAL PRIMARY KEY,
        customer_id VARCHAR(100),
        category VARCHAR(50),
        title VARCHAR(200),
        content TEXT,
        source VARCHAR(200),
        citability_score SMALLINT DEFAULT 3,
        created_at TIMESTAMP DEFAULT NOW(),
        updated_at TIMESTAMP DEFAULT NOW()
    )");
} catch (Throwable $e) {}

$categories = [
    'stat'        => ['label' => '核心数据', 'icon' => 'bar-chart-2', 'color' => 'blue',   'desc' => '可引用的量化数据和成果'],
    'case'        => ['label' => '客户案例', 'icon' => 'briefcase',   'color' => 'green',  'desc' => '真实项目案例和交付成果'],
    'credential'  => ['label' => '荣誉资质', 'icon' => 'award',       'color' => 'yellow', 'desc' => '认证、奖项、媒体报道'],
    'capability'  => ['label' => '服务能力', 'icon' => 'zap',         'color' => 'purple', 'desc' => '方法论、技术能力、团队'],
    'claim'       => ['label' => '核心主张', 'icon' => 'megaphone',   'color' => 'red',    'desc' => '品牌观点、独特主张、立场'],
];

$message = '';

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $cid = trim($_POST['customer_id'] ?? '');

    if ($action === 'save_fact') {
        $id = (int)($_POST['id'] ?? 0);
        $category = $_POST['category'] ?? 'stat';
        $title = trim($_POST['title'] ?? '');
        $content = trim($_POST['content'] ?? '');
        $source = trim($_POST['source'] ?? '');
        $score = max(1, min(5, (int)($_POST['citability_score'] ?? 3)));
        if ($title && $content && $cid) {
            if ($id > 0) {
                $db->prepare("UPDATE geo_brand_knowledge SET category=?,title=?,content=?,source=?,citability_score=?,updated_at=NOW() WHERE id=? AND customer_id=?")->execute([$category,$title,$content,$source,$score,$id,$cid]);
                $message = '✅ 已更新';
            } else {
                $db->prepare("INSERT INTO geo_brand_knowledge (customer_id,category,title,content,source,citability_score) VALUES (?,?,?,?,?,?)")->execute([$cid,$category,$title,$content,$source,$score]);
                $message = '✅ 已添加';
            }
        }
        header("Location: ?customer={$cid}&cat=" . urlencode($_POST['category'] ?? 'stat') . "&msg=" . urlencode($message));
        exit;
    }

    if ($action === 'delete_fact') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0 && $cid) {
            $db->prepare("DELETE FROM geo_brand_knowledge WHERE id=? AND customer_id=?")->execute([$id, $cid]);
        }
        header("Location: ?customer={$cid}&cat=" . urlencode($_POST['return_cat'] ?? 'stat'));
        exit;
    }

    // 品牌核心事实（geo_brand_facts）管理
    if ($action === 'toggle_core') {
        header('Content-Type: application/json');
        $factId = (int)($_POST['fact_id'] ?? 0);
        $isCore = ($_POST['is_core'] ?? '0') === '1';
        try {
            $db->prepare("UPDATE geo_brand_facts SET is_core=?, updated_at=NOW() WHERE id=? AND customer_id=?")->execute([$isCore, $factId, $cid]);
            echo json_encode(['ok' => true]);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'err' => $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'save_brand_fact') {
        $factId = (int)($_POST['fact_id'] ?? 0);
        $factKey   = preg_replace('/[^a-z0-9_]/', '_', strtolower(trim($_POST['fact_key'] ?? '')));
        $factLabel = trim($_POST['fact_label'] ?? '');
        $factValue = trim($_POST['fact_value'] ?? '');
        $isCore    = !empty($_POST['is_core']);
        if ($factKey && $factLabel && $factValue && $cid) {
            if ($factId > 0) {
                $db->prepare("UPDATE geo_brand_facts SET fact_key=?,fact_label=?,fact_value=?,is_core=?,updated_at=NOW() WHERE id=? AND customer_id=?")->execute([$factKey,$factLabel,$factValue,$isCore,$factId,$cid]);
            } else {
                $db->prepare("INSERT INTO geo_brand_facts (customer_id,fact_key,fact_label,fact_value,is_core) VALUES (?,?,?,?,?) ON CONFLICT (customer_id,fact_key) DO UPDATE SET fact_label=EXCLUDED.fact_label,fact_value=EXCLUDED.fact_value,is_core=EXCLUDED.is_core,updated_at=NOW()")->execute([$cid,$factKey,$factLabel,$factValue,$isCore]);
            }
        }
        header("Location: ?customer={$cid}&cat={$selectedCat}&tab=facts");
        exit;
    }

    if ($action === 'delete_brand_fact') {
        $factId = (int)($_POST['fact_id'] ?? 0);
        if ($factId > 0 && $cid) {
            $db->prepare("DELETE FROM geo_brand_facts WHERE id=? AND customer_id=?")->execute([$factId, $cid]);
        }
        header("Location: ?customer={$cid}&cat={$selectedCat}&tab=facts");
        exit;
    }

    if ($action === 'ai_suggest') {
        header('Content-Type: application/json; charset=utf-8');
        $category = $_POST['category'] ?? 'stat';
        $catLabel = $categories[$category]['label'] ?? $category;

        $stmt = $db->prepare("SELECT fact_key, fact_value FROM geo_brand_facts WHERE customer_id=?");
        $stmt->execute([$cid]);
        $facts = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $facts[$r['fact_key']] = $r['fact_value'];
        $brandName = $facts['brand_name'] ?? $cid;
        $industry = $facts['industry'] ?? '';
        $coreServices = $facts['core_service'] ?? $facts['core_services'] ?? '';

        $existing = $db->prepare("SELECT title, content FROM geo_brand_knowledge WHERE customer_id=? AND category=? LIMIT 10");
        $existing->execute([$cid, $category]);
        $existingItems = implode("\n", array_map(fn($r) => "- {$r['title']}: {$r['content']}", $existing->fetchAll(PDO::FETCH_ASSOC)));

        $prompt = <<<PROMPT
你是GEO内容专家。请为品牌【{$brandName}】（行业：{$industry}，服务：{$coreServices}）补充「{$catLabel}」类型的知识图谱条目。

已有条目：
{$existingItems}

请建议5个新的{$catLabel}条目，每条应该：
- 具体、可引用（AI能直接引用的事实或数据）
- 与已有条目不重复
- 对{$brandName}的GEO优化有价值

输出严格JSON（无其他文字）：
{"suggestions":[{"title":"标题","content":"具体内容（可引用的事实）","source":"来源建议","score":4}]}
score是可引用性评分1-5，5最高。
PROMPT;

        $aiResult = geo_call_ai($prompt, 1500, 0.7);
        $content2 = $aiResult['content'];
        if (preg_match('/\{.*\}/s', $content2, $m)) {
            $result = json_decode($m[0], true);
            echo json_encode(['ok' => true, 'suggestions' => $result['suggestions'] ?? []]);
        } else {
            echo json_encode(['error' => '解析失败']);
        }
        exit;
    }
}

$customers = $db->query("SELECT customer_id, name FROM customers ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$selectedCid = $_GET['customer'] ?? ($customers[0]['customer_id'] ?? '');
$selectedCat = $_GET['cat'] ?? 'stat';
$message = $message ?: urldecode($_GET['msg'] ?? '');

$facts = [];
if ($selectedCid) {
    $stmt = $db->prepare("SELECT * FROM geo_brand_knowledge WHERE customer_id=? AND category=? ORDER BY citability_score DESC, created_at DESC");
    $stmt->execute([$selectedCid, $selectedCat]);
    $facts = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// 品牌核心事实（geo_brand_facts）
$brandFacts = [];
$activeTab = $_GET['tab'] ?? 'knowledge';
if ($selectedCid) {
    try {
        $bfStmt = $db->prepare("SELECT * FROM geo_brand_facts WHERE customer_id=? ORDER BY is_core DESC, sort_order ASC, created_at ASC");
        $bfStmt->execute([$selectedCid]);
        $brandFacts = $bfStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}
}

// Counts per category
$catCounts = [];
if ($selectedCid) {
    $stmt2 = $db->prepare("SELECT category, COUNT(*) as cnt FROM geo_brand_knowledge WHERE customer_id=? GROUP BY category");
    $stmt2->execute([$selectedCid]);
    foreach ($stmt2->fetchAll(PDO::FETCH_ASSOC) as $r) $catCounts[$r['category']] = $r['cnt'];
}

$editId = (int)($_GET['edit'] ?? 0);
$editFact = null;
if ($editId > 0 && $selectedCid) {
    $stmt3 = $db->prepare("SELECT * FROM geo_brand_knowledge WHERE id=? AND customer_id=?");
    $stmt3->execute([$editId, $selectedCid]);
    $editFact = $stmt3->fetch(PDO::FETCH_ASSOC);
}

$pageTitle = '品牌知识图谱';
require_once __DIR__ . '/includes/brand-completeness.php';
require_once __DIR__ . '/includes/header.php';
?>
<div class="max-w-6xl mx-auto px-4 py-6">
  <?php if ($selectedCid): echo brand_completeness_check($db, $selectedCid); endif; ?>

  <div class="flex items-center justify-between mb-6">
    <div>
      <h1 class="text-2xl font-bold text-gray-900">品牌知识图谱</h1>
      <p class="text-sm text-gray-500 mt-1">管理可被AI引用的结构化品牌事实，提升内容生成质量</p>
    </div>
    <select class="rounded-lg border-gray-300 text-sm shadow-sm" onchange="location='?customer='+this.value+'&cat=<?= urlencode($selectedCat) ?>'">
      <?php foreach ($customers as $c): ?>
        <option value="<?= htmlspecialchars($c['customer_id']) ?>" <?= $c['customer_id'] === $selectedCid ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>

  <?php if ($message): ?>
    <div class="mb-4 rounded-lg bg-green-50 border border-green-200 px-4 py-3 text-sm text-green-800"><?= htmlspecialchars($message) ?></div>
  <?php endif; ?>

  <div class="flex gap-4">
    <!-- Category sidebar -->
    <div class="w-52 flex-shrink-0">
      <nav class="space-y-1">
        <?php foreach ($categories as $key => $cat): ?>
          <?php $colorMap = ['blue'=>'blue','green'=>'green','yellow'=>'yellow','purple'=>'purple','red'=>'red']; ?>
          <a href="?customer=<?= urlencode($selectedCid) ?>&cat=<?= $key ?>" class="flex items-center justify-between px-3 py-2.5 rounded-lg text-sm <?= $key === $selectedCat ? 'bg-indigo-50 text-indigo-700 font-medium' : 'text-gray-600 hover:bg-gray-100' ?>">
            <div class="flex items-center gap-2">
              <i data-lucide="<?= $cat['icon'] ?>" class="w-4 h-4"></i>
              <?= $cat['label'] ?>
            </div>
            <?php if (isset($catCounts[$key])): ?>
              <span class="text-xs bg-gray-200 text-gray-600 rounded-full px-1.5"><?= $catCounts[$key] ?></span>
            <?php endif; ?>
          </a>
        <?php endforeach; ?>
      </nav>
    </div>

    <!-- Main content -->
    <div class="flex-1 min-w-0">
      <div class="bg-white rounded-xl border border-gray-200 mb-4 p-4">
        <div class="flex items-center justify-between mb-3">
          <div>
            <h3 class="font-semibold text-gray-800"><?= $categories[$selectedCat]['label'] ?></h3>
            <p class="text-xs text-gray-400 mt-0.5"><?= $categories[$selectedCat]['desc'] ?></p>
          </div>
          <div class="flex gap-2">
            <button onclick="aiSuggest()" class="inline-flex items-center gap-1.5 rounded-lg border border-indigo-300 text-indigo-600 px-3 py-1.5 text-sm hover:bg-indigo-50">
              <i data-lucide="sparkles" class="w-3.5 h-3.5"></i> AI建议
            </button>
            <button onclick="toggleAddForm()" class="inline-flex items-center gap-1.5 rounded-lg bg-indigo-600 text-white px-3 py-1.5 text-sm hover:bg-indigo-700">
              <i data-lucide="plus" class="w-3.5 h-3.5"></i> 添加
            </button>
          </div>
        </div>

        <!-- Add/Edit form -->
        <div id="addForm" class="<?= $editFact ? '' : 'hidden' ?> border border-gray-200 rounded-lg p-4 mb-4 bg-gray-50">
          <h4 class="text-sm font-medium text-gray-700 mb-3"><?= $editFact ? '编辑条目' : '添加新条目' ?></h4>
          <form method="POST">
            <input type="hidden" name="action" value="save_fact">
            <input type="hidden" name="customer_id" value="<?= htmlspecialchars($selectedCid) ?>">
            <input type="hidden" name="category" value="<?= htmlspecialchars($selectedCat) ?>">
            <?php if ($editFact): ?><input type="hidden" name="id" value="<?= $editFact['id'] ?>"><?php endif; ?>
            <div class="grid grid-cols-2 gap-3 mb-3">
              <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">标题 *</label>
                <input type="text" name="title" required value="<?= htmlspecialchars($editFact['title'] ?? '') ?>" class="w-full rounded-lg border-gray-300 text-sm" placeholder="简短描述这条事实">
              </div>
              <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">来源</label>
                <input type="text" name="source" value="<?= htmlspecialchars($editFact['source'] ?? '') ?>" class="w-full rounded-lg border-gray-300 text-sm" placeholder="数据来源或链接">
              </div>
            </div>
            <div class="mb-3">
              <label class="block text-xs font-medium text-gray-600 mb-1">内容 * <span class="text-gray-400">（AI可直接引用的具体事实）</span></label>
              <textarea name="content" required rows="3" class="w-full rounded-lg border-gray-300 text-sm" placeholder="例：董逻辑MGEO为超过50个品牌完成GEO优化，平均提升AI提及率42%"><?= htmlspecialchars($editFact['content'] ?? '') ?></textarea>
            </div>
            <div class="flex items-center gap-4">
              <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">可引用性评分</label>
                <select name="citability_score" class="rounded-lg border-gray-300 text-sm">
                  <?php for ($i=5; $i>=1; $i--): ?>
                    <option value="<?= $i ?>" <?= ($editFact['citability_score'] ?? 3) == $i ? 'selected' : '' ?>><?= str_repeat('⭐', $i) ?> <?= ['','低','较低','中等','高','极高'][$i] ?></option>
                  <?php endfor; ?>
                </select>
              </div>
              <div class="flex gap-2 mt-4">
                <button type="submit" class="rounded-lg bg-indigo-600 text-white px-4 py-1.5 text-sm hover:bg-indigo-700">保存</button>
                <button type="button" onclick="toggleAddForm()" class="rounded-lg border border-gray-300 text-gray-600 px-4 py-1.5 text-sm hover:bg-gray-50">取消</button>
              </div>
            </div>
          </form>
        </div>

        <!-- AI suggestions panel -->
        <div id="aiPanel" class="hidden border border-indigo-200 rounded-lg p-4 mb-4 bg-indigo-50">
          <div class="flex items-center justify-between mb-3">
            <h4 class="text-sm font-medium text-indigo-800">AI建议补充的条目</h4>
            <button onclick="document.getElementById('aiPanel').classList.add('hidden')" class="text-indigo-400 hover:text-indigo-600"><i data-lucide="x" class="w-4 h-4"></i></button>
          </div>
          <div id="aiSuggestions" class="space-y-2"></div>
        </div>
      </div>

      <!-- Facts list -->
      <?php if (empty($facts)): ?>
        <div class="text-center py-12 bg-white rounded-xl border border-dashed border-gray-300">
          <i data-lucide="<?= $categories[$selectedCat]['icon'] ?>" class="w-10 h-10 text-gray-300 mx-auto mb-3"></i>
          <p class="text-gray-500">还没有「<?= $categories[$selectedCat]['label'] ?>」条目，点击"添加"或"AI建议"开始</p>
        </div>
      <?php else: ?>
        <div class="space-y-3">
          <?php foreach ($facts as $fact): ?>
          <div class="bg-white rounded-xl border border-gray-200 p-4">
            <div class="flex items-start justify-between gap-4">
              <div class="flex-1 min-w-0">
                <div class="flex items-center gap-2 mb-1.5">
                  <span class="font-medium text-gray-800 text-sm"><?= htmlspecialchars($fact['title']) ?></span>
                  <span class="text-yellow-500 text-xs"><?= str_repeat('⭐', (int)$fact['citability_score']) ?></span>
                </div>
                <p class="text-sm text-gray-600 leading-relaxed"><?= htmlspecialchars($fact['content']) ?></p>
                <?php if ($fact['source']): ?>
                  <p class="text-xs text-gray-400 mt-1.5">来源：<?= htmlspecialchars($fact['source']) ?></p>
                <?php endif; ?>
              </div>
              <div class="flex items-center gap-2 flex-shrink-0">
                <a href="?customer=<?= urlencode($selectedCid) ?>&cat=<?= $selectedCat ?>&edit=<?= $fact['id'] ?>" class="text-xs text-gray-400 hover:text-gray-700">编辑</a>
                <form method="POST" onsubmit="return confirm('确认删除？')">
                  <input type="hidden" name="action" value="delete_fact">
                  <input type="hidden" name="customer_id" value="<?= htmlspecialchars($selectedCid) ?>">
                  <input type="hidden" name="id" value="<?= $fact['id'] ?>">
                  <input type="hidden" name="return_cat" value="<?= $selectedCat ?>">
                  <button type="submit" class="text-xs text-red-400 hover:text-red-700">删除</button>
                </form>
              </div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<script>
function toggleAddForm() {
  const f = document.getElementById('addForm');
  f.classList.toggle('hidden');
}

async function aiSuggest() {
  const cid = '<?= htmlspecialchars($selectedCid) ?>';
  const cat = '<?= $selectedCat ?>';
  const panel = document.getElementById('aiPanel');
  const sugg = document.getElementById('aiSuggestions');
  panel.classList.remove('hidden');
  sugg.innerHTML = '<div class="text-sm text-indigo-600">AI正在分析...</div>';

  const res = await fetch('', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:`action=ai_suggest&customer_id=${encodeURIComponent(cid)}&category=${cat}`});
  const data = await res.json();
  if (data.error) { sugg.innerHTML = '<div class="text-red-600 text-sm">' + data.error + '</div>'; return; }

  sugg.innerHTML = '';
  for (const s of (data.suggestions || [])) {
    const div = document.createElement('div');
    div.className = 'bg-white rounded-lg border border-indigo-200 p-3';
    div.innerHTML = `<div class="flex items-start justify-between gap-3">
      <div class="flex-1"><div class="font-medium text-sm text-gray-800">${s.title}</div><div class="text-sm text-gray-600 mt-0.5">${s.content}</div>${s.source ? '<div class="text-xs text-gray-400 mt-1">来源建议：'+s.source+'</div>' : ''}</div>
      <button onclick="fillForm('${cat}','${s.title.replace(/'/g,"\\'")}','${s.content.replace(/'/g,"\\'")}','${(s.source||'').replace(/'/g,"\\'")}',${s.score||3})" class="text-xs text-indigo-600 border border-indigo-300 rounded px-2 py-1 hover:bg-indigo-100 flex-shrink-0">采用</button>
    </div>`;
    sugg.appendChild(div);
  }
  lucide.createIcons();
}

function fillForm(cat, title, content, source, score) {
  document.getElementById('addForm').classList.remove('hidden');
  document.querySelector('[name=title]').value = title;
  document.querySelector('[name=content]').value = content;
  document.querySelector('[name=source]').value = source;
  document.querySelector('[name=citability_score]').value = score;
  document.getElementById('addForm').scrollIntoView({behavior:'smooth'});
}

async function toggleCore(factId, checkbox) {
    const isCore = checkbox.checked ? '1' : '0';
    const fd = new FormData();
    fd.append('action', 'toggle_core');
    fd.append('customer_id', '<?= htmlspecialchars($selectedCid, ENT_QUOTES) ?>');
    fd.append('fact_id', factId);
    fd.append('is_core', isCore);
    const res = await fetch('', {method:'POST', body: fd});
    const data = await res.json();
    const row = checkbox.closest('tr');
    if (data.ok) {
        row.classList.toggle('bg-indigo-50', checkbox.checked);
    } else {
        checkbox.checked = !checkbox.checked;
        alert('更新失败');
    }
}
</script>

<!-- 品牌核心事实面板 -->
<div class="max-w-6xl mx-auto px-4 pb-10 mt-8" id="brandFactsPanel">
  <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
    <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between">
      <div>
        <h2 class="font-semibold text-gray-800">品牌核心事实锚点</h2>
        <p class="text-xs text-gray-400 mt-0.5">
          标记为「核心」的事实在文章评分时会检验是否逐字出现。母句、服务等关键信息建议标记为核心。
          <span class="text-indigo-500">当前 <?= count(array_filter($brandFacts, fn($f) => !empty($f['is_core']))) ?> 条核心事实</span>
        </p>
      </div>
      <button onclick="document.getElementById('addBrandFactForm').classList.toggle('hidden')"
              class="text-sm text-indigo-600 border border-indigo-200 rounded-lg px-3 py-1.5 hover:bg-indigo-50">+ 添加事实</button>
    </div>

    <!-- 添加事实表单 -->
    <div id="addBrandFactForm" class="hidden px-5 py-4 bg-indigo-50 border-b border-indigo-100">
      <form method="POST" class="flex flex-wrap items-end gap-3">
        <input type="hidden" name="action" value="save_brand_fact">
        <input type="hidden" name="customer_id" value="<?= htmlspecialchars($selectedCid) ?>">
        <input type="hidden" name="fact_id" value="0">
        <div>
          <label class="text-xs text-gray-600 block mb-1">事实标识（英文）</label>
          <input name="fact_key" placeholder="如 founding_year" class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm w-36" required>
        </div>
        <div>
          <label class="text-xs text-gray-600 block mb-1">显示名称</label>
          <input name="fact_label" placeholder="如 成立年份" class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm w-28" required>
        </div>
        <div class="flex-1 min-w-[200px]">
          <label class="text-xs text-gray-600 block mb-1">事实内容</label>
          <input name="fact_value" placeholder="如 2019年成立于上海" class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm w-full" required>
        </div>
        <div class="flex items-center gap-1.5 mb-1">
          <input type="checkbox" name="is_core" id="isCoreCbNew" value="1" checked class="rounded">
          <label for="isCoreCbNew" class="text-xs text-gray-600">标记为核心</label>
        </div>
        <button type="submit" class="bg-indigo-600 text-white px-4 py-1.5 rounded-lg text-sm hover:bg-indigo-700">保存</button>
        <button type="button" onclick="document.getElementById('addBrandFactForm').classList.add('hidden')" class="text-sm text-gray-500 hover:text-gray-700 px-2">取消</button>
      </form>
    </div>

    <?php if (empty($brandFacts)): ?>
    <div class="px-5 py-8 text-center text-sm text-gray-400">
      暂无品牌事实。<a href="geo-onboard.php" class="text-indigo-500 hover:text-indigo-700">→ 在品牌入驻时自动生成</a>，或在此手动添加。
    </div>
    <?php else: ?>
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="bg-gray-50 text-xs text-gray-500 uppercase">
          <tr>
            <th class="px-4 py-2.5 text-left w-8">核心</th>
            <th class="px-4 py-2.5 text-left">标识</th>
            <th class="px-4 py-2.5 text-left">名称</th>
            <th class="px-4 py-2.5 text-left">内容</th>
            <th class="px-4 py-2.5 text-center w-20">操作</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-50">
          <?php foreach ($brandFacts as $bf): $isCore = !empty($bf['is_core']); ?>
          <tr class="hover:bg-gray-50 <?= $isCore ? 'bg-indigo-50/40' : '' ?>">
            <td class="px-4 py-2.5 text-center">
              <input type="checkbox"
                     <?= $isCore ? 'checked' : '' ?>
                     onchange="toggleCore(<?= (int)$bf['id'] ?>, this)"
                     class="rounded accent-indigo-600 cursor-pointer"
                     title="<?= $isCore ? '取消核心标记' : '标记为核心事实' ?>">
            </td>
            <td class="px-4 py-2.5 text-xs text-gray-400 font-mono"><?= htmlspecialchars($bf['fact_key']) ?></td>
            <td class="px-4 py-2.5 text-xs font-medium text-gray-700">
              <?= htmlspecialchars($bf['fact_label']) ?>
              <?php if ($isCore): ?>
                <span class="ml-1 text-[10px] bg-indigo-100 text-indigo-600 px-1.5 py-0.5 rounded-full">核心</span>
              <?php endif; ?>
            </td>
            <td class="px-4 py-2.5 text-xs text-gray-600 max-w-sm">
              <span class="line-clamp-2"><?= htmlspecialchars($bf['fact_value']) ?></span>
            </td>
            <td class="px-4 py-2.5 text-center">
              <form method="POST" onsubmit="return confirm('确认删除？')" class="inline">
                <input type="hidden" name="action" value="delete_brand_fact">
                <input type="hidden" name="customer_id" value="<?= htmlspecialchars($selectedCid) ?>">
                <input type="hidden" name="fact_id" value="<?= (int)$bf['id'] ?>">
                <button type="submit" class="text-xs text-red-400 hover:text-red-600">删除</button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
