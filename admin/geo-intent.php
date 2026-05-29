<?php
/**
 * GEO 意图挖掘 - 发现用户在AI对话中真实问到的问题，找出品牌覆盖空白
 */
define('FEISHU_TREASURE', true);
session_start();
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database_admin.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/citation_simulator_service.php';
require_admin_login();

// Ensure table with enhanced schema
try {
    $db->exec("CREATE TABLE IF NOT EXISTS geo_intent_questions (
        id SERIAL PRIMARY KEY,
        customer_id VARCHAR(100),
        theme VARCHAR(100),
        question TEXT,
        intent_type VARCHAR(20),
        priority VARCHAR(5),
        covered BOOLEAN DEFAULT FALSE,
        reason TEXT DEFAULT '',
        suggested_action TEXT DEFAULT '',
        dimension VARCHAR(30) DEFAULT '',
        created_at TIMESTAMP DEFAULT NOW()
    )");
    // Add columns if missing (migration)
    $db->exec("ALTER TABLE geo_intent_questions ADD COLUMN IF NOT EXISTS reason TEXT DEFAULT ''");
    $db->exec("ALTER TABLE geo_intent_questions ADD COLUMN IF NOT EXISTS suggested_action TEXT DEFAULT ''");
    $db->exec("ALTER TABLE geo_intent_questions ADD COLUMN IF NOT EXISTS dimension VARCHAR(30) DEFAULT ''");
} catch (Throwable $e) {}

// ── AJAX: 从监测缺口导入 P0 意图问题 ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'import_from_monitor') {
    header('Content-Type: application/json; charset=utf-8');
    $cid = trim($_POST['customer_id'] ?? '');
    if ($cid === '') { echo json_encode(['error' => '未指定客户']); exit; }

    // 获取品牌名用于区分品牌词
    $brandRow = $db->prepare("SELECT fact_value FROM geo_brand_facts WHERE customer_id=? AND fact_key='brand_name' LIMIT 1");
    $brandRow->execute([$cid]);
    $brandName = strtolower((string)($brandRow->fetchColumn() ?: $cid));
    $brandWords = array_filter(preg_split('/[\s\-_\/]+/u', $brandName), fn($w) => mb_strlen($w) >= 2);

    // 找出非品牌词、引用率为0的关键词
    $stmt = $db->prepare("
        SELECT query_text,
               COUNT(*) AS total,
               SUM(CASE WHEN brand_mentioned THEN 1 ELSE 0 END) AS cited
        FROM geo_monitor_records
        WHERE customer_id = ?
        GROUP BY query_text
        HAVING SUM(CASE WHEN brand_mentioned THEN 1 ELSE 0 END) = 0
    ");
    $stmt->execute([$cid]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $imported = 0;
    $skipped  = 0;
    $ins = $db->prepare("
        INSERT INTO geo_intent_questions
            (customer_id, theme, question, intent_type, priority, covered, reason, suggested_action, dimension)
        VALUES (?, ?, ?, 'question', 'P0', FALSE, ?, ?, ?)
        ON CONFLICT DO NOTHING
    ");

    // 维度猜测规则
    function guess_dimension(string $q): string {
        if (preg_match('/推荐|选择|哪家|哪个|对比|区别|vs|VS/u', $q)) return '购买决策';
        if (preg_match('/竞品|PK|比较|和.*的区别/u', $q)) return '竞品对比';
        if (preg_match('/风险|靠谱|值不值|骗|假/u', $q)) return '风险质疑';
        if (preg_match('/怎么|如何|方法|步骤|教程/u', $q)) return '场景问题';
        if (preg_match('/什么是|定义|原理|概念/u', $q)) return '品类发现';
        if (preg_match('/行业|趋势|未来|市场/u', $q)) return '行业趋势';
        return '品类发现';
    }

    foreach ($rows as $r) {
        $q   = $r['query_text'];
        $ql  = mb_strtolower($q);
        // 跳过品牌词
        $isBranded = false;
        foreach ($brandWords as $w) { if (mb_strpos($ql, $w) !== false) { $isBranded = true; break; } }
        if ($isBranded) { $skipped++; continue; }

        $dim    = guess_dimension($q);
        $reason = "监测记录：共查询 {$r['total']} 次，引用率 0%，用户真实问过此问题但品牌未被 AI 提及";
        $action = "针对此问题写一篇知乎文章，第一段直接给出定义/判断/建议，后附数据证据";
        $ins->execute([$cid, $dim, $q, $reason, $action, $dim]);
        $imported++;
    }

    echo json_encode(['ok' => true, 'imported' => $imported, 'skipped' => $skipped]);
    exit;
}

// ── AJAX: Toggle covered status ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_covered') {
    header('Content-Type: application/json; charset=utf-8');
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        $db->prepare("UPDATE geo_intent_questions SET covered = NOT covered WHERE id=?")->execute([$id]);
        $st = $db->prepare("SELECT covered FROM geo_intent_questions WHERE id=?");
        $st->execute([$id]);
        echo json_encode(['ok' => true, 'covered' => (bool)$st->fetchColumn()]);
    } else {
        echo json_encode(['error' => '无效ID']);
    }
    exit;
}

// ── Page Load ──
$customers = $db->query("SELECT customer_id, name FROM customers ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$selectedCid = $_GET['customer'] ?? ($customers[0]['customer_id'] ?? '');
$selectedName = '';
foreach ($customers as $c) { if ($c['customer_id'] === $selectedCid) { $selectedName = $c['name']; break; } }

// Load existing questions
$existing = [];
$existingStats = ['total' => 0, 'gap' => 0, 'p0' => 0, 'covered' => 0, 'dimensions' => [], 'themes' => []];
if ($selectedCid) {
    $stmt = $db->prepare("SELECT id, theme, question, intent_type, priority, covered, reason, suggested_action, dimension
        FROM geo_intent_questions WHERE customer_id=? ORDER BY
        CASE priority WHEN 'P0' THEN 0 WHEN 'P1' THEN 1 ELSE 2 END, theme");
    $stmt->execute([$selectedCid]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $existing[] = $r;
        $existingStats['total']++;
        if (!$r['covered']) $existingStats['gap']++;
        else $existingStats['covered']++;
        if ($r['priority'] === 'P0') $existingStats['p0']++;
        $dim = $r['dimension'] ?: $r['intent_type'] ?: '未分类';
        $existingStats['dimensions'][$dim] = ($existingStats['dimensions'][$dim] ?? 0) + 1;
        $existingStats['themes'][$r['theme']] = ($existingStats['themes'][$r['theme']] ?? 0) + 1;
    }
}

// Group by theme for display
$grouped = [];
foreach ($existing as $r) {
    $grouped[$r['theme']][] = $r;
}

$pageTitle = '意图挖掘';
require_once __DIR__ . '/includes/brand-completeness.php';
require_once __DIR__ . '/includes/header.php';

$coverageRate = $existingStats['total'] > 0 ? round($existingStats['covered'] / $existingStats['total'] * 100) : 0;
$dimensions = ['品牌认知','品类发现','购买决策','场景问题','竞品对比','风险质疑','行业趋势'];
$dimIcons = ['品牌认知'=>'🏷️','品类发现'=>'🔍','购买决策'=>'💰','场景问题'=>'🎯','竞品对比'=>'⚔️','风险质疑'=>'🛡️','行业趋势'=>'📈'];
?>

<style>
  @keyframes fadeIn { from { opacity:0; transform:translateY(8px); } to { opacity:1; transform:translateY(0); } }
  .fade-in { animation: fadeIn .3s ease-out both; }
  .intent-badge { display:inline-flex; align-items:center; gap:4px; padding:2px 8px; border-radius:9999px; font-size:11px; font-weight:600; }
  .intent-badge.P0 { background:#fef2f2; color:#dc2626; }
  .intent-badge.P1 { background:#fff7ed; color:#ea580c; }
  .intent-badge.P2 { background:#f9fafb; color:#6b7280; }
  .q-row { transition: background .15s; }
  .q-row:hover { background:#f8fafc; }
  .filter-btn { padding:4px 12px; border-radius:9999px; font-size:12px; font-weight:500; border:1px solid #e5e7eb; color:#6b7280; cursor:pointer; transition: all .15s; }
  .filter-btn:hover { border-color:#c7d2fe; color:#4f46e5; }
  .filter-btn.active { background:#eef2ff; border-color:#a5b4fc; color:#4f46e5; }
  .coverage-bar { height:8px; border-radius:4px; background:#e5e7eb; overflow:hidden; }
  .coverage-fill { height:100%; border-radius:4px; transition: width .8s ease; }
  .prompt-content { max-height:0; opacity:0; overflow:hidden; transition: max-height .5s ease, opacity .3s ease; }
  .prompt-open .prompt-content { max-height:8000px; opacity:1; }
  .dim-matrix td, .dim-matrix th { text-align:center; padding:6px 10px; font-size:12px; }
  .dim-matrix .cell-count { font-weight:700; font-size:16px; }
  .dim-matrix .cell-gap { font-size:10px; color:#ef4444; }
</style>

<div class="max-w-6xl mx-auto px-4 py-6">
  <?php if ($selectedCid): echo brand_completeness_check($db, $selectedCid); endif; ?>

  <!-- Header -->
  <div class="flex items-center justify-between mb-6">
    <div>
      <h1 class="text-2xl font-bold text-gray-900 flex items-center gap-2">
        <span class="inline-flex items-center justify-center w-9 h-9 rounded-lg bg-purple-100 text-purple-600">
          <i data-lucide="search" class="w-5 h-5"></i>
        </span>
        意图挖掘
      </h1>
      <p class="text-sm text-gray-500 mt-1">发现用户在 AI 对话中真实问到的问题，找出品牌覆盖空白</p>
    </div>
    <div class="flex items-center gap-3">
      <select id="customerSelect" class="rounded-lg border-gray-300 text-sm shadow-sm" onchange="location='?customer='+this.value">
        <?php foreach ($customers as $c): ?>
          <option value="<?= htmlspecialchars($c['customer_id']) ?>" <?= $c['customer_id'] === $selectedCid ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <button id="importBtn" onclick="importFromMonitor()" class="inline-flex items-center gap-2 rounded-lg border border-blue-300 bg-blue-50 px-4 py-2 text-sm font-medium text-blue-700 hover:bg-blue-100 shadow-sm transition">
        <i data-lucide="download" class="w-4 h-4"></i> 从监测导入缺口
      </button>
      <button id="mineBtn" onclick="mineIntents()" class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 shadow-sm transition">
        <i data-lucide="sparkles" class="w-4 h-4"></i> 开始挖掘
      </button>
    </div>
  </div>

  <!-- Stats Cards -->
  <?php if ($existingStats['total'] > 0): ?>
  <div class="grid grid-cols-2 lg:grid-cols-5 gap-4 mb-6">
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-4">
      <div class="text-2xl font-bold text-gray-900"><?= $existingStats['total'] ?></div>
      <div class="text-xs text-gray-500 mt-1">挖掘问题总数</div>
    </div>
    <div class="bg-white rounded-xl border border-red-200 shadow-sm p-4">
      <div class="text-2xl font-bold text-red-600"><?= $existingStats['gap'] ?></div>
      <div class="text-xs text-gray-500 mt-1">未覆盖空白</div>
    </div>
    <div class="bg-white rounded-xl border border-orange-200 shadow-sm p-4">
      <div class="text-2xl font-bold text-orange-600"><?= $existingStats['p0'] ?></div>
      <div class="text-xs text-gray-500 mt-1">P0 高优问题</div>
    </div>
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-4">
      <div class="text-2xl font-bold text-indigo-600"><?= $coverageRate ?>%</div>
      <div class="text-xs text-gray-500 mt-1">意图覆盖率</div>
      <div class="coverage-bar mt-2">
        <div class="coverage-fill <?= $coverageRate >= 60 ? 'bg-emerald-500' : ($coverageRate >= 30 ? 'bg-amber-500' : 'bg-red-400') ?>" style="width:<?= $coverageRate ?>%"></div>
      </div>
    </div>
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-4">
      <div class="text-2xl font-bold text-gray-700"><?= count($existingStats['themes']) ?></div>
      <div class="text-xs text-gray-500 mt-1">主题分组</div>
    </div>
  </div>

  <!-- Intent Dimension Matrix -->
  <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5 mb-6">
    <h3 class="font-semibold text-gray-800 text-sm mb-3 flex items-center gap-2">
      <i data-lucide="grid-3x3" class="w-4 h-4 text-gray-400"></i> 意图维度分布
    </h3>
    <div class="overflow-x-auto">
      <table class="w-full dim-matrix">
        <thead>
          <tr class="text-gray-500">
            <th class="text-left font-medium"></th>
            <?php foreach ($dimensions as $dim): ?>
            <th class="font-medium"><span class="text-xs"><?= $dimIcons[$dim] ?? '' ?> <?= $dim ?></span></th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
          <?php
          // Count by dimension × priority
          $matrix = [];
          foreach ($existing as $r) {
              $d = $r['dimension'] ?: $r['intent_type'] ?: '未分类';
              $p = $r['priority'] ?? 'P1';
              $c = $r['covered'] ? 'covered' : 'gap';
              if (!isset($matrix[$d][$p])) $matrix[$d][$p] = ['total'=>0,'gap'=>0];
              $matrix[$d][$p]['total']++;
              if (!$r['covered']) $matrix[$d][$p]['gap']++;
          }
          foreach (['P0','P1','P2'] as $pri):
          ?>
          <tr>
            <td class="text-left font-medium text-xs"><span class="intent-badge <?= $pri ?>"><?= $pri ?></span></td>
            <?php foreach ($dimensions as $dim): ?>
            <td>
              <?php if (isset($matrix[$dim][$pri])): ?>
                <div class="cell-count <?= $matrix[$dim][$pri]['gap'] > 0 ? 'text-red-600' : 'text-emerald-600' ?>"><?= $matrix[$dim][$pri]['total'] ?></div>
                <?php if ($matrix[$dim][$pri]['gap'] > 0): ?>
                <div class="cell-gap"><?= $matrix[$dim][$pri]['gap'] ?> 个空白</div>
                <?php endif; ?>
              <?php else: ?>
                <div class="text-gray-200">-</div>
              <?php endif; ?>
            </td>
            <?php endforeach; ?>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

  <!-- Loading -->
  <div id="loadingState" class="hidden text-center py-16 bg-white rounded-xl border border-indigo-200 shadow-sm mb-6">
    <div class="relative w-16 h-16 mx-auto mb-5">
      <svg class="animate-spin w-16 h-16 text-indigo-200" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3"></circle></svg>
      <svg class="animate-spin w-16 h-16 text-indigo-600 absolute inset-0" fill="none" viewBox="0 0 24 24" style="animation-direction:reverse;animation-duration:1.5s"><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
    </div>
    <div class="text-lg font-semibold text-gray-800 mb-1">AI 正在挖掘意图空间</div>
    <div class="text-sm text-gray-500 mb-4">正在分析品牌数据、监测记录和已有内容...</div>
    <div class="inline-flex items-center gap-2 bg-indigo-50 rounded-full px-4 py-1.5">
      <div class="w-2 h-2 rounded-full bg-indigo-500 animate-pulse"></div>
      <span class="text-sm font-mono text-indigo-700 font-medium" id="loadingTimer">0s</span>
    </div>
  </div>

  <!-- Error -->
  <div id="errorBox" class="hidden mb-6 p-4 bg-red-50 border border-red-200 rounded-xl">
    <div class="flex items-start gap-3">
      <span class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-red-100 text-red-600 shrink-0"><i data-lucide="alert-triangle" class="w-4 h-4"></i></span>
      <div><div class="text-sm font-semibold text-red-800">挖掘失败</div><div class="text-sm text-red-600 mt-1" id="errorText"></div></div>
    </div>
  </div>

  <!-- Summary (shown after mining) -->
  <div id="summaryBox" class="hidden mb-6 bg-indigo-50 border border-indigo-200 rounded-xl p-4 fade-in">
    <div class="flex items-start gap-3">
      <i data-lucide="lightbulb" class="w-5 h-5 text-indigo-600 mt-0.5 shrink-0"></i>
      <div>
        <div class="text-sm font-semibold text-indigo-800 mb-1">AI 意图分析摘要</div>
        <div id="summaryText" class="text-sm text-indigo-700 leading-relaxed"></div>
      </div>
    </div>
  </div>

  <!-- Filter Bar -->
  <?php if ($existingStats['total'] > 0): ?>
  <div id="filterBar" class="flex flex-wrap items-center gap-2 mb-4">
    <span class="text-xs text-gray-500 font-medium mr-1">筛选：</span>
    <button onclick="setFilter('all')" class="filter-btn active" data-filter="all">全部</button>
    <button onclick="setFilter('gap')" class="filter-btn" data-filter="gap">仅空白</button>
    <button onclick="setFilter('covered')" class="filter-btn" data-filter="covered">已覆盖</button>
    <span class="text-gray-300 mx-1">|</span>
    <button onclick="setFilter('P0')" class="filter-btn" data-filter="P0">P0</button>
    <button onclick="setFilter('P1')" class="filter-btn" data-filter="P1">P1</button>
    <button onclick="setFilter('P2')" class="filter-btn" data-filter="P2">P2</button>
    <span class="text-gray-300 mx-1">|</span>
    <?php foreach ($dimensions as $dim): ?>
    <button onclick="setFilter('dim:<?= $dim ?>')" class="filter-btn" data-filter="dim:<?= $dim ?>"><?= $dimIcons[$dim] ?? '' ?> <?= $dim ?></button>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- Results Container -->
  <div id="resultsContainer">
    <?php if (!empty($grouped)): ?>
      <?php foreach ($grouped as $theme => $questions): ?>
      <div class="bg-white rounded-xl border border-gray-200 shadow-sm mb-4 overflow-hidden theme-card fade-in" data-theme="<?= htmlspecialchars($theme) ?>">
        <div class="px-5 py-3 border-b border-gray-100 flex items-center justify-between bg-gray-50/50">
          <div class="flex items-center gap-2">
            <span class="font-semibold text-gray-800 text-sm"><?= htmlspecialchars($theme) ?></span>
            <span class="text-xs text-gray-400"><?= count($questions) ?> 个问题</span>
          </div>
          <div class="flex items-center gap-2">
            <?php
            $gapCnt = 0; foreach ($questions as $q) { if (!$q['covered']) $gapCnt++; }
            if ($gapCnt > 0): ?>
            <span class="text-xs text-red-500 font-medium"><?= $gapCnt ?> 个空白</span>
            <?php endif; ?>
          </div>
        </div>
        <div class="divide-y divide-gray-50">
          <?php foreach ($questions as $q): ?>
          <div class="px-5 py-3 q-row flex items-start justify-between gap-3"
               data-id="<?= $q['id'] ?>"
               data-priority="<?= $q['priority'] ?>"
               data-covered="<?= $q['covered'] ? '1' : '0' ?>"
               data-dimension="<?= htmlspecialchars($q['dimension'] ?: $q['intent_type']) ?>">
            <div class="flex items-start gap-3 flex-1 min-w-0">
              <span class="intent-badge <?= $q['priority'] ?> mt-0.5 shrink-0"><?= $q['priority'] ?></span>
              <div class="flex-1 min-w-0">
                <div class="text-sm text-gray-800"><?= htmlspecialchars($q['question']) ?></div>
                <?php if ($q['reason']): ?>
                <div class="text-xs text-gray-400 mt-1">💡 <?= htmlspecialchars($q['reason']) ?></div>
                <?php endif; ?>
                <?php if ($q['suggested_action']): ?>
                <div class="text-xs text-indigo-500 mt-0.5">📋 建议：<?= htmlspecialchars($q['suggested_action']) ?></div>
                <?php endif; ?>
              </div>
              <span class="text-xs text-gray-400 shrink-0 mt-0.5"><?= $dimIcons[$q['dimension'] ?: $q['intent_type']] ?? '' ?> <?= htmlspecialchars($q['dimension'] ?: $q['intent_type']) ?></span>
            </div>
            <div class="flex items-center gap-2 shrink-0 mt-0.5">
              <button onclick="toggleCovered(<?= $q['id'] ?>, this)" class="inline-flex items-center gap-1 text-xs px-2 py-1 rounded-full transition <?= $q['covered'] ? 'bg-emerald-50 text-emerald-600 hover:bg-emerald-100' : 'bg-red-50 text-red-500 hover:bg-red-100' ?>">
                <?php if ($q['covered']): ?>
                  <i data-lucide="check-circle" class="w-3 h-3"></i> 已覆盖
                <?php else: ?>
                  <i data-lucide="alert-circle" class="w-3 h-3"></i> 空白
                <?php endif; ?>
              </button>
              <?php if (!$q['covered']): ?>
              <a href="geo-content.php?customer=<?= urlencode($selectedCid) ?>&keyword=<?= urlencode($q['question']) ?>"
                 class="text-xs text-indigo-600 hover:text-indigo-800 hover:underline whitespace-nowrap">生成文章→</a>
              <?php endif; ?>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endforeach; ?>
    <?php elseif ($selectedCid): ?>
    <!-- Empty state -->
    <div class="text-center py-16 bg-white rounded-xl border border-dashed border-gray-300">
      <div class="w-16 h-16 rounded-2xl bg-purple-50 flex items-center justify-center mx-auto mb-5">
        <i data-lucide="search" class="w-8 h-8 text-purple-400"></i>
      </div>
      <p class="text-base font-medium text-gray-700 mb-1">点击「开始挖掘」分析意图空间</p>
      <p class="text-sm text-gray-400 mb-4">AI 将基于品牌真实数据和监测记录，发现用户在 AI 对话中的未覆盖问题</p>
      <div class="flex items-center justify-center gap-6 text-xs text-gray-400">
        <span class="flex items-center gap-1.5"><i data-lucide="tag" class="w-3.5 h-3.5"></i> 品牌认知</span>
        <span class="flex items-center gap-1.5"><i data-lucide="compass" class="w-3.5 h-3.5"></i> 品类发现</span>
        <span class="flex items-center gap-1.5"><i data-lucide="credit-card" class="w-3.5 h-3.5"></i> 购买决策</span>
        <span class="flex items-center gap-1.5"><i data-lucide="shield" class="w-3.5 h-3.5"></i> 风险质疑</span>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <!-- Prompt Preview (collapsible) -->
  <div id="promptPreviewBox" class="hidden bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden mt-6">
    <button onclick="togglePrompt()" class="w-full flex items-center justify-between px-6 py-4 hover:bg-gray-50 transition text-left">
      <div class="flex items-center gap-3">
        <span class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-amber-100 text-amber-600"><i data-lucide="terminal" class="w-4 h-4"></i></span>
        <div>
          <span class="font-semibold text-gray-800 text-sm">查看发送给 AI 的完整 Prompt</span>
          <span class="text-xs text-gray-400 ml-2">点击展开</span>
        </div>
      </div>
      <i data-lucide="chevron-down" id="promptChevron" class="w-4 h-4 text-gray-400 transition-transform duration-300"></i>
    </button>
    <div class="prompt-content" id="promptContent">
      <div class="px-6 pb-5 border-t border-gray-100 pt-4">
        <pre id="promptPreview" class="bg-gray-900 text-gray-100 rounded-lg p-5 text-xs leading-relaxed overflow-x-auto whitespace-pre-wrap font-mono" style="max-height:500px;overflow-y:auto"></pre>
      </div>
    </div>
  </div>
</div>

<script>
var _timer = null, _seconds = 0, _lastPrompt = '', _promptOpen = false, _currentFilter = 'all';

function togglePrompt() {
    _promptOpen = !_promptOpen;
    document.getElementById('promptPreviewBox').classList.toggle('prompt-open', _promptOpen);
    document.getElementById('promptChevron').style.transform = _promptOpen ? 'rotate(180deg)' : 'rotate(0deg)';
}

async function mineIntents() {
    const cid = document.getElementById('customerSelect').value;
    document.getElementById('loadingState').classList.remove('hidden');
    document.getElementById('resultsContainer').classList.add('hidden');
    document.getElementById('summaryBox').classList.add('hidden');
    document.getElementById('errorBox').classList.add('hidden');
    document.getElementById('promptPreviewBox').classList.add('hidden');
    document.getElementById('mineBtn').disabled = true;

    _seconds = 0;
    document.getElementById('loadingTimer').textContent = '0s';
    _timer = setInterval(() => { _seconds++; document.getElementById('loadingTimer').textContent = _seconds + 's'; }, 1000);

    try {
        const res = await fetch('/dl-console/api/intent-mine.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `customer_id=${encodeURIComponent(cid)}`
        });
        const data = await res.json();
        clearInterval(_timer);

        if (data.error) {
            document.getElementById('errorText').textContent = data.error;
            document.getElementById('errorBox').classList.remove('hidden');
            return;
        }

        _lastPrompt = data.prompt_used || '';
        document.getElementById('promptPreview').textContent = _lastPrompt;
        document.getElementById('promptPreviewBox').classList.remove('hidden');

        const summaryBox = document.getElementById('summaryBox');
        const summary = data.data.gap_analysis?.summary || data.data.summary || '';
        document.getElementById('summaryText').textContent = summary;
        summaryBox.classList.remove('hidden');

        renderResults(data.data, cid);
    } catch(e) {
        clearInterval(_timer);
        document.getElementById('errorText').textContent = '请求失败：' + e.message;
        document.getElementById('errorBox').classList.remove('hidden');
    } finally {
        document.getElementById('loadingState').classList.add('hidden');
        document.getElementById('mineBtn').disabled = false;
        lucide.createIcons();
    }
}

function renderResults(data, cid) {
    const container = document.getElementById('resultsContainer');
    const ga = data.gap_analysis || {};
    const totalQ = ga.total_questions || (data.themes||[]).reduce((s,t) => s + (t.questions||[]).length, 0);
    const gapCount = ga.gap_count || 0;
    const p0Gaps = ga.p0_gaps || 0;
    const covRate = totalQ > 0 ? Math.round((totalQ - gapCount) / totalQ * 100) : 0;

    let html = `<div class="grid grid-cols-2 lg:grid-cols-5 gap-4 mb-6 fade-in">
      <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-4"><div class="text-2xl font-bold text-gray-900">${totalQ}</div><div class="text-xs text-gray-500 mt-1">挖掘问题总数</div></div>
      <div class="bg-white rounded-xl border border-red-200 shadow-sm p-4"><div class="text-2xl font-bold text-red-600">${gapCount}</div><div class="text-xs text-gray-500 mt-1">未覆盖空白</div></div>
      <div class="bg-white rounded-xl border border-orange-200 shadow-sm p-4"><div class="text-2xl font-bold text-orange-600">${p0Gaps}</div><div class="text-xs text-gray-500 mt-1">P0 空白问题</div></div>
      <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-4"><div class="text-2xl font-bold text-indigo-600">${covRate}%</div><div class="text-xs text-gray-500 mt-1">意图覆盖率</div>
        <div class="coverage-bar mt-2"><div class="coverage-fill ${covRate>=60?'bg-emerald-500':covRate>=30?'bg-amber-500':'bg-red-400'}" style="width:${covRate}%"></div></div></div>
      <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-4"><div class="text-2xl font-bold text-gray-700">${(data.themes||[]).length}</div><div class="text-xs text-gray-500 mt-1">主题分组</div></div>
    </div>`;

    // Filter bar
    html += `<div class="flex flex-wrap items-center gap-2 mb-4"><span class="text-xs text-gray-500 font-medium mr-1">筛选：</span>
      <button onclick="setFilter('all')" class="filter-btn active" data-filter="all">全部</button>
      <button onclick="setFilter('gap')" class="filter-btn" data-filter="gap">仅空白</button>
      <button onclick="setFilter('covered')" class="filter-btn" data-filter="covered">已覆盖</button>
      <span class="text-gray-300 mx-1">|</span>
      <button onclick="setFilter('P0')" class="filter-btn" data-filter="P0">P0</button>
      <button onclick="setFilter('P1')" class="filter-btn" data-filter="P1">P1</button>
      <button onclick="setFilter('P2')" class="filter-btn" data-filter="P2">P2</button></div>`;

    const dimIcons = {'品牌认知':'🏷️','品类发现':'🔍','购买决策':'💰','场景问题':'🎯','竞品对比':'⚔️','风险质疑':'🛡️','行业趋势':'📈'};

    for (const theme of (data.themes || [])) {
        let rows = '';
        for (const q of (theme.questions || [])) {
            const pCls = q.priority==='P0' ? 'P0' : q.priority==='P1' ? 'P1' : 'P2';
            const dim = q.intent || theme.dimension || '';
            const dimIcon = dimIcons[dim] || '';
            const covBadge = q.covered
              ? '<button onclick="toggleCoveredDyn(this)" class="inline-flex items-center gap-1 text-xs px-2 py-1 rounded-full bg-emerald-50 text-emerald-600 hover:bg-emerald-100 transition"><i data-lucide="check-circle" class="w-3 h-3"></i> 已覆盖</button>'
              : '<button onclick="toggleCoveredDyn(this)" class="inline-flex items-center gap-1 text-xs px-2 py-1 rounded-full bg-red-50 text-red-500 hover:bg-red-100 transition"><i data-lucide="alert-circle" class="w-3 h-3"></i> 空白</button>';
            const reasonHtml = q.reason ? `<div class="text-xs text-gray-400 mt-1">💡 ${q.reason}</div>` : '';
            const actionHtml = q.suggested_action ? `<div class="text-xs text-indigo-500 mt-0.5">📋 建议：${q.suggested_action}</div>` : '';
            const genLink = !q.covered ? `<a href="geo-content.php?customer=${encodeURIComponent(cid)}&keyword=${encodeURIComponent(q.q)}" class="text-xs text-indigo-600 hover:text-indigo-800 hover:underline whitespace-nowrap">生成文章→</a>` : '';
            rows += `<div class="px-5 py-3 q-row flex items-start justify-between gap-3" data-priority="${q.priority}" data-covered="${q.covered?1:0}" data-dimension="${dim}">
              <div class="flex items-start gap-3 flex-1 min-w-0">
                <span class="intent-badge ${pCls} mt-0.5 shrink-0">${q.priority}</span>
                <div class="flex-1 min-w-0"><div class="text-sm text-gray-800">${q.q}</div>${reasonHtml}${actionHtml}</div>
                <span class="text-xs text-gray-400 shrink-0 mt-0.5">${dimIcon} ${dim}</span>
              </div>
              <div class="flex items-center gap-2 shrink-0 mt-0.5">${covBadge}${genLink}</div>
            </div>`;
        }
        const gapCnt = (theme.questions||[]).filter(q=>!q.covered).length;
        html += `<div class="bg-white rounded-xl border border-gray-200 shadow-sm mb-4 overflow-hidden theme-card fade-in">
          <div class="px-5 py-3 border-b border-gray-100 flex items-center justify-between bg-gray-50/50">
            <div class="flex items-center gap-2"><span class="font-semibold text-gray-800 text-sm">${theme.icon||''} ${theme.name}</span><span class="text-xs text-gray-400">${(theme.questions||[]).length} 个问题</span></div>
            ${gapCnt > 0 ? `<span class="text-xs text-red-500 font-medium">${gapCnt} 个空白</span>` : ''}
          </div>
          <div class="divide-y divide-gray-50">${rows}</div>
        </div>`;
    }

    container.innerHTML = html;
    container.classList.remove('hidden');
    lucide.createIcons();
}

/* ── Filter ── */
function setFilter(filter) {
    _currentFilter = filter;
    document.querySelectorAll('.filter-btn').forEach(b => {
        b.classList.toggle('active', b.dataset.filter === filter);
    });
    document.querySelectorAll('.q-row').forEach(row => {
        let show = true;
        if (filter === 'gap') show = row.dataset.covered === '0';
        else if (filter === 'covered') show = row.dataset.covered === '1';
        else if (filter === 'P0' || filter === 'P1' || filter === 'P2') show = row.dataset.priority === filter;
        else if (filter.startsWith('dim:')) show = row.dataset.dimension === filter.substring(4);
        row.style.display = show ? '' : 'none';
    });
    // Hide empty theme cards
    document.querySelectorAll('.theme-card').forEach(card => {
        const visible = card.querySelectorAll('.q-row:not([style*="display: none"])');
        card.style.display = visible.length > 0 ? '' : 'none';
    });
}

/* ── Toggle Covered ── */
async function importFromMonitor() {
    const btn = document.getElementById('importBtn');
    const cid = document.querySelector('select[name="customer"]')?.value || '';
    if (!cid) { alert('请先选择客户'); return; }
    btn.disabled = true;
    btn.innerHTML = '<i data-lucide="loader-2" class="w-4 h-4 animate-spin"></i> 导入中...';
    lucide.createIcons();
    try {
        const res = await fetch('', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `action=import_from_monitor&customer_id=${encodeURIComponent(cid)}`
        });
        const data = await res.json();
        if (data.ok) {
            if (data.imported > 0) {
                alert(`成功导入 ${data.imported} 个监测缺口关键词为 P0 意图问题，页面即将刷新`);
                location.reload();
            } else {
                alert('没有发现新的非品牌词缺口（引用率为0%的关键词），或已全部导入');
                btn.disabled = false;
                btn.innerHTML = '<i data-lucide="download" class="w-4 h-4"></i> 从监测导入缺口';
                lucide.createIcons();
            }
        } else {
            alert(data.error || '导入失败');
            btn.disabled = false;
            btn.innerHTML = '<i data-lucide="download" class="w-4 h-4"></i> 从监测导入缺口';
            lucide.createIcons();
        }
    } catch(e) {
        alert('请求失败：' + e.message);
        btn.disabled = false;
        btn.innerHTML = '<i data-lucide="download" class="w-4 h-4"></i> 从监测导入缺口';
        lucide.createIcons();
    }
}

async function toggleCovered(id, btn) {
    try {
        const res = await fetch('', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `action=toggle_covered&id=${id}`
        });
        const data = await res.json();
        if (data.ok) {
            const row = btn.closest('.q-row');
            row.dataset.covered = data.covered ? '1' : '0';
            if (data.covered) {
                btn.className = 'inline-flex items-center gap-1 text-xs px-2 py-1 rounded-full bg-emerald-50 text-emerald-600 hover:bg-emerald-100 transition';
                btn.innerHTML = '<i data-lucide="check-circle" class="w-3 h-3"></i> 已覆盖';
            } else {
                btn.className = 'inline-flex items-center gap-1 text-xs px-2 py-1 rounded-full bg-red-50 text-red-500 hover:bg-red-100 transition';
                btn.innerHTML = '<i data-lucide="alert-circle" class="w-3 h-3"></i> 空白';
            }
            lucide.createIcons();
        }
    } catch(e) {}
}

function toggleCoveredDyn(btn) {
    // For dynamically rendered rows - just toggle visual (no DB call since it's new data)
    const row = btn.closest('.q-row');
    const isCovered = row.dataset.covered === '1';
    row.dataset.covered = isCovered ? '0' : '1';
    if (!isCovered) {
        btn.className = 'inline-flex items-center gap-1 text-xs px-2 py-1 rounded-full bg-emerald-50 text-emerald-600 hover:bg-emerald-100 transition';
        btn.innerHTML = '<i data-lucide="check-circle" class="w-3 h-3"></i> 已覆盖';
    } else {
        btn.className = 'inline-flex items-center gap-1 text-xs px-2 py-1 rounded-full bg-red-50 text-red-500 hover:bg-red-100 transition';
        btn.innerHTML = '<i data-lucide="alert-circle" class="w-3 h-3"></i> 空白';
    }
    lucide.createIcons();
}
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
