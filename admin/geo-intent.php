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
    $db->exec("CREATE TABLE IF NOT EXISTS geo_intent_questions (
        id SERIAL PRIMARY KEY,
        customer_id VARCHAR(100),
        theme VARCHAR(100),
        question TEXT,
        intent_type VARCHAR(20),
        priority VARCHAR(5),
        covered BOOLEAN DEFAULT FALSE,
        created_at TIMESTAMP DEFAULT NOW()
    )");
} catch (Throwable $e) {}

// AJAX: mine intents
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mine') {
    header('Content-Type: application/json; charset=utf-8');
    $cid = trim($_POST['customer_id'] ?? '');
    if (!$cid) { echo json_encode(['error' => '请选择客户']); exit; }

    $stmt = $db->prepare("SELECT fact_key, fact_value FROM geo_brand_facts WHERE customer_id=?");
    $stmt->execute([$cid]);
    $facts = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $facts[$r['fact_key']] = $r['fact_value'];
    $brandName    = $facts['brand_name']    ?? $cid;
    $industry     = $facts['industry']      ?? '';
    $coreServices = $facts['core_service']  ?? $facts['core_services'] ?? '';
    $masterSentence = $facts['master_sentence'] ?? '';

    $stmtKw = $db->prepare("SELECT DISTINCT query_text FROM geo_monitor_records WHERE customer_id=? LIMIT 20");
    $stmtKw->execute([$cid]);
    $existingKw = implode('、', $stmtKw->fetchAll(PDO::FETCH_COLUMN));

    $prompt = <<<PROMPT
你是GEO意图挖掘专家。请分析用户在AI（Kimi/豆包/DeepSeek/通义/文心/元宝）中可能问到的问题。

## 品牌信息
- 品牌：{$brandName}
- 行业：{$industry}
- 服务：{$coreServices}
- 定位：{$masterSentence}
- 已监测关键词：{$existingKw}

## 任务
枚举用户在AI对话中可能问的60个具体问题，覆盖6个维度：
1. 信息类（这个品牌是什么/做什么）
2. 比较类（和竞品相比如何）
3. 决策类（该不该选这个品牌）
4. 问题类（遇到XX问题该找谁）
5. 场景类（XX场景下用什么解决方案）
6. 趋势类（行业趋势/AI搜索趋势等）

## 输出格式（严格JSON，无其他文字）
{"themes":[{"name":"主题名","icon":"emoji","questions":[{"q":"问题文本","intent":"信息/比较/决策/问题/场景/趋势","priority":"P0/P1/P2","covered":true}]}],"summary":"3句话总结","gap_count":0,"p0_count":0}

covered=true表示已有关键词覆盖此问题，false表示空白机会。
P0=高频高优先，P1=中优先，P2=可选。
gap_count=covered=false的问题总数，p0_count=priority=P0的问题总数。
PROMPT;

    $aiResult = geo_call_ai($prompt, 4000, 0.7);
    if (!empty($aiResult['error'])) { echo json_encode(['error' => "API失败: {$aiResult['error']}"]); exit; }
    $content = $aiResult['content'];
    if (preg_match('/\{.*\}/s', $content, $m)) {
        $result = json_decode($m[0], true);
        if ($result) {
            try {
                $db->prepare("DELETE FROM geo_intent_questions WHERE customer_id=?")->execute([$cid]);
                $ins = $db->prepare("INSERT INTO geo_intent_questions (customer_id, theme, question, intent_type, priority, covered) VALUES (?,?,?,?,?,?)");
                foreach (($result['themes'] ?? []) as $theme) {
                    foreach (($theme['questions'] ?? []) as $q) {
                        $ins->execute([$cid, $theme['name'], $q['q'], $q['intent'], $q['priority'], ($q['covered'] ?? false) ? 1 : 0]);
                    }
                }
            } catch (Throwable $e) {}
            echo json_encode(['ok' => true, 'data' => $result, 'brand' => $brandName]);
        } else {
            echo json_encode(['error' => 'JSON解析失败', 'raw' => substr($content, 0, 300)]);
        }
    } else {
        echo json_encode(['error' => '响应格式错误', 'raw' => substr($content, 0, 300)]);
    }
    exit;
}

$customers = $db->query("SELECT customer_id, name FROM customers ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$selectedCid = $_GET['customer'] ?? ($customers[0]['customer_id'] ?? '');
$selectedName = '';
foreach ($customers as $c) { if ($c['customer_id'] === $selectedCid) { $selectedName = $c['name']; break; } }

// Load existing
$existing = [];
$existingStats = ['total' => 0, 'gap' => 0, 'p0' => 0];
if ($selectedCid) {
    $stmt = $db->prepare("SELECT theme, question, intent_type, priority, covered FROM geo_intent_questions WHERE customer_id=? ORDER BY priority, theme");
    $stmt->execute([$selectedCid]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $existing[$r['theme']][] = $r;
        $existingStats['total']++;
        if (!$r['covered']) $existingStats['gap']++;
        if ($r['priority'] === 'P0') $existingStats['p0']++;
    }
}

$pageTitle = '意图挖掘';
require_once __DIR__ . '/includes/brand-completeness.php';
require_once __DIR__ . '/includes/header.php';
?>
<div class="max-w-6xl mx-auto px-4 py-6">
  <?php if ($selectedCid): echo brand_completeness_check($db, $selectedCid); endif; ?>

  <div class="flex items-center justify-between mb-6">
    <div>
      <h1 class="text-2xl font-bold text-gray-900">意图挖掘</h1>
      <p class="text-sm text-gray-500 mt-1">发现用户在AI对话中真实问到的问题，找出品牌覆盖空白</p>
    </div>
    <div class="flex items-center gap-3">
      <select id="customerSelect" class="rounded-lg border-gray-300 text-sm shadow-sm" onchange="location='?customer='+this.value">
        <?php foreach ($customers as $c): ?>
          <option value="<?= htmlspecialchars($c['customer_id']) ?>" <?= $c['customer_id'] === $selectedCid ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <button id="mineBtn" onclick="mineIntents()" class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">
        <i data-lucide="search" class="w-4 h-4"></i> 开始挖掘
      </button>
    </div>
  </div>

  <?php if ($existingStats['total'] > 0): ?>
  <div class="grid grid-cols-3 gap-4 mb-6">
    <div class="bg-white rounded-xl border border-gray-200 p-4">
      <div class="text-2xl font-bold text-gray-900"><?= $existingStats['total'] ?></div>
      <div class="text-sm text-gray-500 mt-1">挖掘到的问题总数</div>
    </div>
    <div class="bg-white rounded-xl border border-red-200 p-4">
      <div class="text-2xl font-bold text-red-600"><?= $existingStats['gap'] ?></div>
      <div class="text-sm text-gray-500 mt-1">未覆盖空白机会</div>
    </div>
    <div class="bg-white rounded-xl border border-orange-200 p-4">
      <div class="text-2xl font-bold text-orange-600"><?= $existingStats['p0'] ?></div>
      <div class="text-sm text-gray-500 mt-1">P0最高优先问题</div>
    </div>
  </div>
  <?php endif; ?>

  <div id="loadingState" class="hidden text-center py-16">
    <div class="inline-flex items-center gap-3 text-indigo-600">
      <svg class="animate-spin w-6 h-6" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
      <span class="text-lg font-medium">AI正在分析意图空间，约30秒...</span>
    </div>
  </div>

  <div id="summaryBox" class="hidden mb-6 bg-indigo-50 border border-indigo-200 rounded-xl p-4">
    <div class="flex items-start gap-3">
      <i data-lucide="lightbulb" class="w-5 h-5 text-indigo-600 mt-0.5 flex-shrink-0"></i>
      <div id="summaryText" class="text-sm text-indigo-800"></div>
    </div>
  </div>

  <div id="resultsContainer">
    <?php if (!empty($existing)): ?>
      <?php foreach ($existing as $theme => $questions): ?>
      <div class="bg-white rounded-xl border border-gray-200 mb-4 overflow-hidden">
        <div class="px-4 py-3 border-b border-gray-100 flex items-center justify-between">
          <span class="font-semibold text-gray-800"><?= htmlspecialchars($theme) ?></span>
          <span class="text-xs text-gray-400"><?= count($questions) ?> 个问题</span>
        </div>
        <div class="divide-y divide-gray-50">
          <?php foreach ($questions as $q): ?>
          <div class="px-4 py-3 flex items-start justify-between hover:bg-gray-50">
            <div class="flex items-start gap-3 flex-1">
              <span class="mt-0.5 inline-block px-1.5 py-0.5 rounded text-xs font-bold <?= $q['priority']==='P0' ? 'bg-red-100 text-red-700' : ($q['priority']==='P1' ? 'bg-orange-100 text-orange-700' : 'bg-gray-100 text-gray-500') ?>"><?= $q['priority'] ?></span>
              <span class="text-sm text-gray-700 flex-1"><?= htmlspecialchars($q['question']) ?></span>
              <span class="text-xs text-gray-400 ml-2 flex-shrink-0"><?= htmlspecialchars($q['intent_type']) ?></span>
            </div>
            <div class="flex items-center gap-2 ml-4 flex-shrink-0">
              <?php if (!$q['covered']): ?>
                <span class="inline-flex items-center gap-1 text-xs text-red-600"><i data-lucide="alert-circle" class="w-3 h-3"></i>空白</span>
              <?php else: ?>
                <span class="inline-flex items-center gap-1 text-xs text-green-600"><i data-lucide="check-circle" class="w-3 h-3"></i>覆盖</span>
              <?php endif; ?>
              <a href="geo-content.php?customer=<?= urlencode($selectedCid) ?>&keyword=<?= urlencode($q['question']) ?>" class="text-xs text-indigo-600 hover:text-indigo-800">生成文章→</a>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endforeach; ?>
    <?php elseif ($selectedCid): ?>
    <div class="text-center py-16 bg-white rounded-xl border border-dashed border-gray-300">
      <i data-lucide="search" class="w-10 h-10 text-gray-300 mx-auto mb-3"></i>
      <p class="text-gray-500">点击"开始挖掘"，AI将分析 <strong><?= htmlspecialchars($selectedName) ?></strong> 的意图空间</p>
    </div>
    <?php endif; ?>
  </div>
</div>

<script>
async function mineIntents() {
  const cid = document.getElementById('customerSelect').value;
  document.getElementById('loadingState').classList.remove('hidden');
  document.getElementById('resultsContainer').classList.add('hidden');
  document.getElementById('summaryBox').classList.add('hidden');
  document.getElementById('mineBtn').disabled = true;

  try {
    const res = await fetch('', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: `action=mine&customer_id=${encodeURIComponent(cid)}`});
    const data = await res.json();
    if (data.error) { alert('错误：' + data.error); return; }

    // Summary
    const summaryBox = document.getElementById('summaryBox');
    document.getElementById('summaryText').textContent = data.data.summary || '';
    summaryBox.classList.remove('hidden');

    // Build results
    const container = document.getElementById('resultsContainer');
    container.innerHTML = '';

    // Stats bar
    const gapCount = data.data.gap_count || 0;
    const p0Count = data.data.p0_count || 0;
    const totalQ = (data.data.themes || []).reduce((s,t) => s + (t.questions||[]).length, 0);
    container.innerHTML += `<div class="grid grid-cols-3 gap-4 mb-6">
      <div class="bg-white rounded-xl border border-gray-200 p-4"><div class="text-2xl font-bold text-gray-900">${totalQ}</div><div class="text-sm text-gray-500 mt-1">挖掘到的问题总数</div></div>
      <div class="bg-white rounded-xl border border-red-200 p-4"><div class="text-2xl font-bold text-red-600">${gapCount}</div><div class="text-sm text-gray-500 mt-1">未覆盖空白机会</div></div>
      <div class="bg-white rounded-xl border border-orange-200 p-4"><div class="text-2xl font-bold text-orange-600">${p0Count}</div><div class="text-sm text-gray-500 mt-1">P0最高优先问题</div></div>
    </div>`;

    for (const theme of (data.data.themes || [])) {
      let rows = '';
      for (const q of (theme.questions || [])) {
        const pCls = q.priority==='P0' ? 'bg-red-100 text-red-700' : q.priority==='P1' ? 'bg-orange-100 text-orange-700' : 'bg-gray-100 text-gray-500';
        const covBadge = q.covered
          ? '<span class="inline-flex items-center gap-1 text-xs text-green-600">✓ 覆盖</span>'
          : '<span class="inline-flex items-center gap-1 text-xs text-red-600">⚠ 空白</span>';
        rows += `<div class="px-4 py-3 flex items-start justify-between hover:bg-gray-50">
          <div class="flex items-start gap-3 flex-1">
            <span class="mt-0.5 inline-block px-1.5 py-0.5 rounded text-xs font-bold ${pCls}">${q.priority}</span>
            <span class="text-sm text-gray-700 flex-1">${q.q}</span>
            <span class="text-xs text-gray-400 ml-2 flex-shrink-0">${q.intent}</span>
          </div>
          <div class="flex items-center gap-2 ml-4 flex-shrink-0">
            ${covBadge}
            <a href="geo-content.php?customer=${encodeURIComponent(cid)}&keyword=${encodeURIComponent(q.q)}" class="text-xs text-indigo-600 hover:text-indigo-800">生成文章→</a>
          </div>
        </div>`;
      }
      container.innerHTML += `<div class="bg-white rounded-xl border border-gray-200 mb-4 overflow-hidden">
        <div class="px-4 py-3 border-b border-gray-100 flex items-center justify-between">
          <span class="font-semibold text-gray-800">${theme.icon || ''} ${theme.name}</span>
          <span class="text-xs text-gray-400">${(theme.questions||[]).length} 个问题</span>
        </div>
        <div class="divide-y divide-gray-50">${rows}</div>
      </div>`;
    }
    container.classList.remove('hidden');
  } catch(e) {
    alert('请求失败：' + e.message);
  } finally {
    document.getElementById('loadingState').classList.add('hidden');
    document.getElementById('mineBtn').disabled = false;
    lucide.createIcons();
  }
}
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
