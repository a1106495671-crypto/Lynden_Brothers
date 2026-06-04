<?php
/**
 * GEO 全景诊断 - 基于监测数据生成完整竞品差距与机会地图
 */
define('FEISHU_TREASURE', true);
session_start();
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/database_admin.php';
require_once __DIR__ . '/../includes/citation_simulator_service.php';
require_admin_login();

function gp_h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function gp_json_decode($v, $fallback) {
    if (is_array($v)) return $v;
    $decoded = json_decode((string)$v, true);
    return is_array($decoded) ? $decoded : $fallback;
}

// 客户列表
$customers = [];
try {
    $stmt = $db->query("
        SELECT DISTINCT k.customer_id,
               COALESCE((SELECT fact_value FROM geo_brand_facts WHERE customer_id=k.customer_id AND fact_key='brand_name' LIMIT 1), k.customer_id) AS brand_name
        FROM geo_monitor_keywords k ORDER BY brand_name
    ");
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

$historyByCustomer = [];
try {
    $stmtH = $db->query("
        SELECT id, customer_id, brand_name, report_md, prompt_used, model_used,
               overall_rate, total_records, platform_stats, kw_stats, comp_overall,
               signals, alerts, created_at::text AS created_at
        FROM geo_panorama_reports
        ORDER BY created_at DESC
        LIMIT 80
    ");
    foreach ($stmtH->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $row['id'] = (int)$row['id'];
        $row['overall_rate'] = (float)$row['overall_rate'];
        $row['total_records'] = (int)$row['total_records'];
        $row['platform_stats'] = gp_json_decode($row['platform_stats'] ?? '{}', []);
        $row['kw_stats'] = gp_json_decode($row['kw_stats'] ?? '{}', []);
        $row['comp_overall'] = gp_json_decode($row['comp_overall'] ?? '{}', []);
        $row['signals'] = gp_json_decode($row['signals'] ?? '[]', []);
        $row['alerts'] = gp_json_decode($row['alerts'] ?? '[]', []);
        $historyByCustomer[$row['customer_id']][] = $row;
    }
} catch (Throwable $e) {}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>GEO全景诊断</title>
<script src="/admin/assets/js/tailwind.play-cdn.js"></script>
<script src="/admin/assets/js/lucide.min.js"></script>
<style>
  @keyframes fadeIn { from { opacity:0; transform:translateY(12px); } to { opacity:1; transform:translateY(0); } }
  .fade-in { animation: fadeIn .4s ease-out both; }
  .fade-in-d1 { animation-delay:.1s; }
  .fade-in-d2 { animation-delay:.2s; }
  .fade-in-d3 { animation-delay:.3s; }
  .fade-in-d4 { animation-delay:.4s; }
  /* Report prose */
  .report-prose h2 { font-size:1.125rem; font-weight:700; color:#111827; margin:1.75rem 0 .75rem; padding-bottom:.5rem; border-bottom:2px solid #e5e7eb; }
  .report-prose h3 { font-size:1rem; font-weight:600; color:#1f2937; margin:1.25rem 0 .5rem; }
  .report-prose p { margin:.5rem 0; line-height:1.75; color:#374151; }
  .report-prose ul { margin:.5rem 0; padding-left:1.25rem; }
  .report-prose li { margin:.35rem 0; line-height:1.7; color:#4b5563; }
  .report-prose table { width:100%; border-collapse:collapse; margin:1rem 0; font-size:.875rem; }
  .report-prose th { background:#f8fafc; font-weight:600; color:#1e293b; text-align:left; padding:.625rem .75rem; border:1px solid #e2e8f0; }
  .report-prose td { padding:.625rem .75rem; border:1px solid #e2e8f0; color:#334155; vertical-align:top; }
  .report-prose tr:hover td { background:#f8fafc; }
  .report-prose strong { color:#111827; font-weight:600; }
  .report-prose code { background:#f1f5f9; padding:.125rem .375rem; border-radius:.25rem; font-size:.8125rem; color:#0f172a; }
  .report-prose blockquote { border-left:3px solid #6366f1; padding:.5rem 1rem; margin:.75rem 0; background:#f5f3ff; border-radius:0 .375rem .375rem 0; color:#4338ca; }
  .report-prose { background:#f8fafc; margin:-1.5rem; padding:1.5rem; }
  .diagnosis-hero { border:1px solid #e5e7eb; border-radius:1rem; background:linear-gradient(135deg,#ffffff 0%,#f8fafc 55%,#eef2ff 100%); padding:1.25rem; margin-bottom:1rem; }
  .diagnosis-hero__eyebrow { display:flex; align-items:center; gap:.5rem; font-size:.75rem; font-weight:700; color:#4f46e5; margin-bottom:.5rem; }
  .diagnosis-hero__title { font-size:1.25rem; line-height:1.4; font-weight:800; color:#0f172a; margin-bottom:.6rem; }
  .diagnosis-hero__copy { color:#475569; line-height:1.8; font-size:.95rem; max-width:68rem; }
  .insight-grid { display:grid; grid-template-columns:repeat(1,minmax(0,1fr)); gap:.75rem; margin:1rem 0; }
  @media (min-width:768px) { .insight-grid { grid-template-columns:repeat(3,minmax(0,1fr)); } }
  .insight-card { border:1px solid #e5e7eb; border-radius:.85rem; background:white; padding:1rem; }
  .insight-card__label { color:#64748b; font-size:.75rem; font-weight:600; margin-bottom:.35rem; }
  .insight-card__value { color:#0f172a; font-size:1.35rem; line-height:1; font-weight:800; }
  .insight-card__hint { color:#94a3b8; font-size:.75rem; margin-top:.45rem; }
  .section-card { border:1px solid #e5e7eb; border-radius:1rem; background:white; overflow:hidden; margin-top:1rem; box-shadow:0 1px 2px rgba(15,23,42,.04); }
  .section-card__head { display:flex; align-items:center; gap:.75rem; padding:1rem 1.1rem; border-bottom:1px solid #eef2f7; background:#fff; }
  .section-card__icon { width:2rem; height:2rem; border-radius:.65rem; display:inline-flex; align-items:center; justify-content:center; background:#eef2ff; color:#4f46e5; flex:none; }
  .section-card__title { font-weight:800; color:#111827; font-size:1rem; }
  .section-card__body { padding:1rem 1.1rem; }
  .section-card__body p { color:#334155; line-height:1.85; margin:.6rem 0; }
  .section-card__body ul { margin:.75rem 0; padding-left:0; list-style:none; display:grid; gap:.45rem; }
  .section-card__body li { position:relative; padding:.55rem .75rem .55rem 2rem; margin:0; border-radius:.7rem; background:#f8fafc; color:#334155; line-height:1.65; }
  .section-card__body li:before { content:""; position:absolute; left:.85rem; top:1.12rem; width:.35rem; height:.35rem; border-radius:999px; background:#6366f1; }
  .action-list { display:grid; gap:.6rem; margin-top:.75rem; }
  .action-item { display:flex; gap:.75rem; align-items:flex-start; border:1px solid #fde68a; background:#fffbeb; border-radius:.85rem; padding:.8rem .9rem; color:#78350f; line-height:1.65; }
  .action-item__num { width:1.35rem; height:1.35rem; border-radius:999px; display:inline-flex; align-items:center; justify-content:center; background:#f59e0b; color:white; font-size:.75rem; font-weight:800; flex:none; margin-top:.15rem; }
  .signal-list { display:grid; grid-template-columns:repeat(1,minmax(0,1fr)); gap:.55rem; margin-top:.75rem; }
  @media (min-width:768px) { .signal-list { grid-template-columns:repeat(2,minmax(0,1fr)); } }
  .signal-row { display:flex; justify-content:space-between; align-items:center; gap:.75rem; border:1px solid #e5e7eb; border-radius:.75rem; padding:.7rem .8rem; background:white; }
  .signal-score { font-weight:800; font-size:.9rem; }
  .alert-stack { display:grid; gap:.55rem; margin-top:.75rem; }
  .alert-card { border:1px solid #fecaca; background:#fef2f2; color:#7f1d1d; border-radius:.85rem; padding:.8rem .9rem; line-height:1.65; }
  /* Prompt toggle handled by JS */
  /* Stat ring */
  .stat-ring { position:relative; width:80px; height:80px; }
  .stat-ring svg { transform:rotate(-90deg); }
  .stat-ring .ring-bg { fill:none; stroke:#e5e7eb; stroke-width:6; }
  .stat-ring .ring-fg { fill:none; stroke-width:6; stroke-linecap:round; transition: stroke-dashoffset 1s ease; }
  .stat-ring .ring-label { position:absolute; inset:0; display:flex; flex-direction:column; align-items:center; justify-content:center; }
</style>
</head>
<body class="bg-gray-50 min-h-screen">
<div class="max-w-6xl mx-auto py-8 px-4 sm:px-6">

  <!-- Header -->
  <div class="mb-8 flex items-center justify-between">
    <div>
      <h1 class="text-2xl font-bold text-gray-900 flex items-center gap-2">
        <span class="inline-flex items-center justify-center w-9 h-9 rounded-lg bg-indigo-100 text-indigo-600">
          <i data-lucide="scan-search" class="w-5 h-5"></i>
        </span>
        GEO 全景诊断
      </h1>
      <p class="text-gray-500 mt-1.5 text-sm">基于真实监测数据，生成 AI 可见度、竞品差距与机会地图</p>
    </div>
    <a href="geo-monitor.php" class="inline-flex items-center gap-1 text-sm text-gray-500 hover:text-gray-700 transition">
      <i data-lucide="arrow-left" class="w-4 h-4"></i> 返回监测
    </a>
  </div>

  <!-- Customer Select + Generate -->
  <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5 mb-6">
    <div class="flex items-end gap-4">
      <div class="flex-1">
        <label class="block text-sm font-medium text-gray-700 mb-1.5">选择品牌客户</label>
        <select id="customerSelect" class="w-full border border-gray-300 rounded-lg px-3 py-2.5 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none transition">
          <?php if (empty($customers)): ?>
            <option value="">暂无监测数据，请先添加关键词并运行监测</option>
          <?php else: ?>
            <?php foreach ($customers as $c): ?>
            <option value="<?= gp_h($c['customer_id']) ?>"><?= gp_h($c['brand_name']) ?></option>
            <?php endforeach; ?>
          <?php endif; ?>
        </select>
      </div>
      <button onclick="startGeneration()" id="genBtn"
        class="inline-flex items-center gap-2 px-6 py-2.5 bg-indigo-600 text-white text-sm font-medium rounded-lg hover:bg-indigo-700 active:bg-indigo-800 disabled:opacity-50 disabled:cursor-not-allowed shadow-sm transition">
        <i data-lucide="sparkles" class="w-4 h-4"></i>
        生成全景诊断
      </button>
    </div>
  </div>

  <!-- Saved Reports -->
  <div id="archiveBox" style="display:none" class="bg-white rounded-xl border border-gray-200 shadow-sm p-5 mb-6">
    <div class="flex items-center justify-between mb-3">
      <div class="flex items-center gap-2">
        <span class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-slate-100 text-slate-600">
          <i data-lucide="archive" class="w-4 h-4"></i>
        </span>
        <div>
          <h2 class="text-sm font-semibold text-gray-900">历史诊断档案</h2>
          <p class="text-xs text-gray-400">自动保存每次生成结果，可随时回看和导出</p>
        </div>
      </div>
      <span id="archiveCount" class="text-xs text-gray-400"></span>
    </div>
    <div id="archiveList" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3"></div>
  </div>

  <!-- Loading -->
  <div id="loadingBox" style="display:none" class="bg-white rounded-xl border border-indigo-200 shadow-sm p-10 text-center mb-6 fade-in">
    <div class="relative w-16 h-16 mx-auto mb-5">
      <svg class="animate-spin w-16 h-16 text-indigo-200" fill="none" viewBox="0 0 24 24">
        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3"></circle>
      </svg>
      <svg class="animate-spin w-16 h-16 text-indigo-600 absolute inset-0" fill="none" viewBox="0 0 24 24" style="animation-direction:reverse;animation-duration:1.5s">
        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
      </svg>
    </div>
    <div class="text-lg font-semibold text-gray-800 mb-1">AI 正在生成全景诊断</div>
    <div class="text-sm text-gray-500 mb-4">正在分析监测数据，生成竞品差距与机会地图...</div>
    <div class="inline-flex items-center gap-2 bg-indigo-50 rounded-full px-4 py-1.5">
      <div class="w-2 h-2 rounded-full bg-indigo-500 animate-pulse"></div>
      <span class="text-sm font-mono text-indigo-700 font-medium" id="loadingTimer">0s</span>
    </div>
    <div class="text-xs text-gray-400 mt-3">通常需要 30-60 秒，请耐心等待</div>
  </div>

  <!-- Error -->
  <div id="errorBox" style="display:none" class="mb-6 p-4 bg-red-50 border border-red-200 rounded-xl fade-in">
    <div class="flex items-start gap-3">
      <span class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-red-100 text-red-600 shrink-0">
        <i data-lucide="alert-triangle" class="w-4 h-4"></i>
      </span>
      <div>
        <div class="text-sm font-semibold text-red-800">生成失败</div>
        <div class="text-sm text-red-600 mt-1" id="errorText"></div>
      </div>
    </div>
  </div>

  <!-- Result Area -->
  <div id="resultArea" style="display:none">

    <!-- Stats Row -->
    <div id="statsRow" class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6"></div>

    <!-- Three-Column Analysis -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-6">
      <div id="platformBox" class="bg-white rounded-xl border border-gray-200 shadow-sm p-5 fade-in fade-in-d1"></div>
      <div id="kwBox" class="bg-white rounded-xl border border-gray-200 shadow-sm p-5 fade-in fade-in-d2"></div>
      <div id="compBox" class="bg-white rounded-xl border border-gray-200 shadow-sm p-5 fade-in fade-in-d3"></div>
    </div>

    <!-- Report -->
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden mb-6 fade-in fade-in-d4">
      <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100 bg-gradient-to-r from-gray-50 to-white">
        <div class="flex items-center gap-3">
          <span class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-indigo-100 text-indigo-600">
            <i data-lucide="file-text" class="w-4 h-4"></i>
          </span>
          <div>
            <h2 class="font-bold text-gray-900 text-base">GEO 全景诊断报告</h2>
            <div class="flex items-center gap-2 mt-0.5">
              <span class="text-xs font-medium text-indigo-600 bg-indigo-50 px-2 py-0.5 rounded-full" id="reportBrand"></span>
              <span class="text-xs text-gray-400" id="reportMeta"></span>
            </div>
          </div>
        </div>
        <button onclick="exportReport()" class="inline-flex items-center gap-1.5 text-xs text-gray-500 hover:text-gray-700 border border-gray-200 rounded-lg px-3 py-1.5 hover:bg-gray-50 transition">
          <i data-lucide="download" class="w-3.5 h-3.5"></i> 导出
        </button>
      </div>
      <div class="px-6 py-6 report-prose" id="reportBody"></div>
    </div>

    <!-- Prompt Preview (collapsible) -->
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden mb-6">
      <button onclick="togglePrompt()" class="w-full flex items-center justify-between px-6 py-4 hover:bg-gray-50 transition text-left">
        <div class="flex items-center gap-3">
          <span class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-amber-100 text-amber-600">
            <i data-lucide="terminal" class="w-4 h-4"></i>
          </span>
          <div>
            <span class="font-semibold text-gray-800 text-sm">查看发送给 AI 的完整 Prompt</span>
            <span class="text-xs text-gray-400 ml-2">点击展开</span>
          </div>
        </div>
        <i data-lucide="chevron-down" id="promptChevron" class="w-4 h-4 text-gray-400 transition-transform duration-300"></i>
      </button>
      <div id="promptContent" style="max-height:0;opacity:0;overflow:hidden;transition:max-height .5s ease,opacity .3s ease">
        <div class="px-6 pb-5 border-t border-gray-100 pt-4">
          <pre id="promptPreview" class="bg-gray-900 text-gray-100 rounded-lg p-5 text-xs leading-relaxed overflow-x-auto whitespace-pre-wrap font-mono" style="max-height:500px;overflow-y:auto"></pre>
        </div>
      </div>
    </div>

  </div>

  <!-- Empty State -->
  <div id="emptyState" class="bg-white rounded-xl border border-gray-200 shadow-sm p-16 text-center">
    <div class="w-16 h-16 rounded-2xl bg-indigo-50 flex items-center justify-center mx-auto mb-5">
      <i data-lucide="scan-search" class="w-8 h-8 text-indigo-400"></i>
    </div>
    <p class="text-base font-medium text-gray-700 mb-1">选择品牌，生成全景诊断</p>
    <p class="text-sm text-gray-400 mb-6">基于真实监测数据，分析 AI 可见度、竞品差距与优化机会</p>
    <div class="flex items-center justify-center gap-6 text-xs text-gray-400">
      <span class="flex items-center gap-1.5"><i data-lucide="eye" class="w-3.5 h-3.5"></i> AI平台提及率</span>
      <span class="flex items-center gap-1.5"><i data-lucide="swords" class="w-3.5 h-3.5"></i> 竞品差距分析</span>
      <span class="flex items-center gap-1.5"><i data-lucide="target" class="w-3.5 h-3.5"></i> 机会地图</span>
      <span class="flex items-center gap-1.5"><i data-lucide="zap" class="w-3.5 h-3.5"></i> 快赢清单</span>
    </div>
  </div>

</div>

<script>
var _histories = <?= json_encode($historyByCustomer, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;

document.addEventListener('DOMContentLoaded', function() {
    if (typeof lucide !== 'undefined') lucide.createIcons();
    var select = $('customerSelect');
    if (select) {
        select.addEventListener('change', onCustomerChange);
        onCustomerChange();
    }
});

var _timer = null, _seconds = 0, _lastPrompt = '', _promptOpen = false;

function togglePrompt() {
    _promptOpen = !_promptOpen;
    var content = $('promptContent');
    var chevron = $('promptChevron');
    if (_promptOpen) {
        content.style.maxHeight = '8000px';
        content.style.opacity = '1';
        chevron.style.transform = 'rotate(180deg)';
    } else {
        content.style.maxHeight = '0';
        content.style.opacity = '0';
        chevron.style.transform = 'rotate(0deg)';
    }
}

function startGeneration() {
    var customerId = document.getElementById('customerSelect').value;
    if (!customerId) { alert('请选择客户'); return; }

    $('emptyState').style.display = 'none';
    $('resultArea').style.display = 'none';
    $('errorBox').style.display = 'none';
    $('loadingBox').style.display = 'block';
    $('genBtn').disabled = true;

    _seconds = 0;
    $('loadingTimer').textContent = '0s';
    _timer = setInterval(function() {
        _seconds++;
        $('loadingTimer').textContent = _seconds + 's';
    }, 1000);

    var fd = new FormData();
    fd.append('customer_id', customerId);

    fetch('/dl-console/api/panorama-generate.php', { method:'POST', body:fd })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        clearInterval(_timer);
        $('loadingBox').style.display = 'none';
        $('genBtn').disabled = false;
        if (!data.success) {
            $('errorText').textContent = data.error || '未知错误';
            $('errorBox').style.display = 'block';
            return;
        }
        _lastPrompt = data.prompt_used || '';
        addHistoryRecord(customerId, data);
        renderResult(data);
    })
    .catch(function(err) {
        clearInterval(_timer);
        $('loadingBox').style.display = 'none';
        $('genBtn').disabled = false;
        $('errorText').textContent = '网络请求失败: ' + err.message;
        $('errorBox').style.display = 'block';
    });
}

function $(id) { return document.getElementById(id); }

function onCustomerChange() {
    var customerId = $('customerSelect') ? $('customerSelect').value : '';
    renderArchive(customerId);
    var list = _histories[customerId] || [];
    if (list.length > 0) {
        $('emptyState').style.display = 'none';
        $('errorBox').style.display = 'none';
        $('loadingBox').style.display = 'none';
        loadArchivedReport(customerId, 0);
    } else {
        $('resultArea').style.display = 'none';
        $('emptyState').style.display = 'block';
    }
}

function addHistoryRecord(customerId, data) {
    var record = {
        id: data.report_id || null,
        customer_id: customerId,
        brand_name: data.brand_name || '',
        report_md: data.report_md || '',
        prompt_used: data.prompt_used || '',
        model_used: data.model_used || '',
        overall_rate: data.overall_rate || 0,
        total_records: data.total_records || 0,
        platform_stats: data.platform_stats || {},
        kw_stats: data.kw_stats || {},
        comp_overall: data.comp_overall || {},
        signals: data.signals || [],
        alerts: data.alerts || [],
        created_at: data.created_at || new Date().toISOString().slice(0, 19).replace('T', ' ')
    };
    if (!_histories[customerId]) _histories[customerId] = [];
    _histories[customerId].unshift(record);
    renderArchive(customerId);
}

function renderArchive(customerId) {
    var list = _histories[customerId] || [];
    var box = $('archiveBox');
    var archiveList = $('archiveList');
    if (!box || !archiveList) return;
    if (list.length === 0) {
        box.style.display = 'none';
        archiveList.innerHTML = '';
        return;
    }
    box.style.display = 'block';
    $('archiveCount').textContent = list.length + ' 份档案';
    archiveList.innerHTML = list.slice(0, 12).map(function(item, idx) {
        var created = formatDateTime(item.created_at);
        var rate = Number(item.overall_rate || 0).toFixed(1);
        var activeClass = idx === 0 ? 'border-indigo-200 bg-indigo-50/50' : 'border-gray-200 bg-white hover:bg-gray-50';
        return '<button type="button" data-report-index="' + idx + '" class="panorama-archive-item text-left rounded-lg border ' + activeClass + ' p-3 transition">' +
          '<div class="flex items-center justify-between gap-2 mb-1">' +
            '<span class="text-sm font-semibold text-gray-800 truncate">' + escapeHtml(item.brand_name || '历史报告') + '</span>' +
            '<span class="text-xs font-semibold text-indigo-600 bg-white rounded-full px-2 py-0.5">' + rate + '%</span>' +
          '</div>' +
          '<div class="text-xs text-gray-500 flex items-center gap-1"><i data-lucide="clock" class="w-3 h-3"></i>' + escapeHtml(created) + '</div>' +
          '<div class="text-xs text-gray-400 mt-1 truncate">' + escapeHtml(item.model_used || 'AI') + ' · ' + Number(item.total_records || 0) + ' 条监测数据</div>' +
        '</button>';
    }).join('');
    Array.prototype.forEach.call(archiveList.querySelectorAll('.panorama-archive-item'), function(btn) {
        btn.addEventListener('click', function() {
            loadArchivedReport(customerId, Number(btn.getAttribute('data-report-index') || 0));
        });
    });
    if (typeof lucide !== 'undefined') lucide.createIcons();
}

function loadArchivedReport(customerId, index) {
    var item = (_histories[customerId] || [])[index];
    if (!item) return;
    _lastPrompt = item.prompt_used || '';
    renderResult({
        report_id: item.id,
        report_md: item.report_md,
        brand_name: item.brand_name,
        overall_rate: item.overall_rate,
        total_records: item.total_records,
        platform_stats: item.platform_stats || {},
        kw_stats: item.kw_stats || {},
        comp_overall: item.comp_overall || {},
        signals: item.signals || [],
        alerts: item.alerts || [],
        model_used: item.model_used,
        prompt_used: item.prompt_used,
        created_at: item.created_at
    });
}

function formatDateTime(value) {
    if (!value) return '';
    return String(value).replace('T', ' ').replace(/\.\d+$/, '').slice(0, 19);
}

function escapeHtml(value) {
    return String(value || '').replace(/[&<>"']/g, function(ch) {
        return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[ch];
    });
}

/* ── Stat Ring SVG ── */
function makeRing(pct, color, label, sub) {
    var r = 34, c = 2 * Math.PI * r;
    var offset = c - (pct / 100) * c;
    return '<div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5 flex items-center gap-4">' +
      '<div class="stat-ring shrink-0">' +
        '<svg viewBox="0 0 80 80">' +
          '<circle class="ring-bg" cx="40" cy="40" r="' + r + '"/>' +
          '<circle class="ring-fg" cx="40" cy="40" r="' + r + '" stroke="' + color + '" ' +
            'stroke-dasharray="' + c + '" stroke-dashoffset="' + offset + '"/>' +
        '</svg>' +
        '<div class="ring-label"><span class="text-lg font-bold" style="color:' + color + '">' + pct + '%</span></div>' +
      '</div>' +
      '<div><div class="text-sm font-semibold text-gray-800">' + label + '</div>' +
      '<div class="text-xs text-gray-400 mt-0.5">' + sub + '</div></div>' +
    '</div>';
}

function renderResult(data) {
    var totalHit = 0, totalAll = 0;
    var ps = data.platform_stats || {};
    for (var p in ps) { totalHit += ps[p].hit; totalAll += ps[p].total; }
    var overallRate = totalAll > 0 ? Math.round(totalHit / totalAll * 1000) / 10 : 0;
    var grade = overallRate >= 60 ? 'A' : (overallRate >= 40 ? 'B' : (overallRate >= 20 ? 'C' : 'D'));
    var gradeColor = overallRate >= 60 ? '#059669' : (overallRate >= 40 ? '#d97706' : (overallRate >= 20 ? '#ea580c' : '#dc2626'));
    var compSum = 0, co = data.comp_overall || {};
    for (var c in co) compSum += co[c];

    // Stats
    $('statsRow').innerHTML =
      makeRing(overallRate, gradeColor, '整体 AI 提及率', '评级 ' + grade + ' · ' + totalHit + '/' + totalAll + ' 次命中') +
      '<div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5 flex items-center gap-4">' +
        '<div class="w-14 h-14 rounded-xl bg-blue-50 flex items-center justify-center shrink-0"><span class="text-2xl font-bold text-blue-600">' + Object.keys(ps).length + '</span></div>' +
        '<div><div class="text-sm font-semibold text-gray-800">覆盖 AI 平台</div><div class="text-xs text-gray-400 mt-0.5">/ 6 个平台</div></div>' +
      '</div>' +
      '<div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5 flex items-center gap-4">' +
        '<div class="w-14 h-14 rounded-xl bg-purple-50 flex items-center justify-center shrink-0"><span class="text-2xl font-bold text-purple-600">' + Object.keys(data.kw_stats || {}).length + '</span></div>' +
        '<div><div class="text-sm font-semibold text-gray-800">监测关键词</div><div class="text-xs text-gray-400 mt-0.5">近 30 天</div></div>' +
      '</div>' +
      '<div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5 flex items-center gap-4">' +
        '<div class="w-14 h-14 rounded-xl bg-orange-50 flex items-center justify-center shrink-0"><span class="text-2xl font-bold text-orange-500">' + compSum + '</span></div>' +
        '<div><div class="text-sm font-semibold text-gray-800">竞品出现次数</div><div class="text-xs text-gray-400 mt-0.5">近 30 天</div></div>' +
      '</div>';

    // Platform bars
    var platformNames = {kimi:'Kimi',deepseek:'DeepSeek',tongyi:'通义',wenxin:'文心',doubao:'豆包',yuanbao:'元宝',chatgpt:'ChatGPT',claude:'Claude'};
    var platHtml = '<div class="flex items-center gap-2 mb-4"><i data-lucide="bar-chart-3" class="w-4 h-4 text-blue-500"></i><h3 class="font-semibold text-gray-800 text-sm">各平台提及率</h3></div>';
    for (var pk in ps) {
        var rate = ps[pk].total > 0 ? Math.round(ps[pk].hit / ps[pk].total * 100) : 0;
        var barColor = rate >= 50 ? 'bg-emerald-500' : (rate >= 25 ? 'bg-amber-500' : 'bg-red-400');
        var sampleWarn = ps[pk].total < 5 ? ' <span class="text-[10px] text-amber-500">样本少</span>' : '';
        platHtml += '<div class="mb-2.5">' +
          '<div class="flex justify-between text-xs mb-1"><span class="text-gray-600 font-medium">' + (platformNames[pk] || pk) + '</span><span class="text-gray-500">' + rate + '% (' + ps[pk].hit + '/' + ps[pk].total + ')' + sampleWarn + '</span></div>' +
          '<div class="h-2 bg-gray-100 rounded-full overflow-hidden"><div class="h-2 ' + barColor + ' rounded-full transition-all duration-700" style="width:' + rate + '%"></div></div>' +
        '</div>';
    }
    $('platformBox').innerHTML = platHtml;

    // Keyword box
    var kwSorted = [];
    for (var kw in data.kw_stats) {
        var ks = data.kw_stats[kw];
        kwSorted.push({kw:kw, rate: ks.total>0 ? ks.hit/ks.total : 0, hit:ks.hit, total:ks.total});
    }
    kwSorted.sort(function(a,b) { return a.rate - b.rate; });
    var kwHtml = '<div class="flex items-center gap-2 mb-4"><i data-lucide="key" class="w-4 h-4 text-purple-500"></i><h3 class="font-semibold text-gray-800 text-sm">关键词表现（低 → 高）</h3></div>';
    for (var i = 0; i < Math.min(kwSorted.length, 10); i++) {
        var item = kwSorted[i];
        var kr = Math.round(item.rate * 100);
        var kBg = kr >= 50 ? 'bg-emerald-50 text-emerald-700' : (kr >= 25 ? 'bg-amber-50 text-amber-700' : 'bg-red-50 text-red-600');
        var dot = kr >= 50 ? 'bg-emerald-400' : (kr >= 25 ? 'bg-amber-400' : 'bg-red-400');
        var shortKw = item.kw.length > 14 ? item.kw.substring(0,14) + '...' : item.kw;
        kwHtml += '<div class="flex items-center justify-between py-1.5">' +
          '<div class="flex items-center gap-2 flex-1 min-w-0"><span class="w-1.5 h-1.5 rounded-full ' + dot + ' shrink-0"></span><span class="text-xs text-gray-600 truncate">' + shortKw + '</span></div>' +
          '<span class="text-[11px] font-semibold px-2 py-0.5 rounded-full ' + kBg + ' ml-2 shrink-0">' + kr + '%</span>' +
        '</div>';
    }
    $('kwBox').innerHTML = kwHtml;

    // Competitor box
    var compSorted = [];
    for (var cn in co) compSorted.push({name:cn, count:co[cn]});
    compSorted.sort(function(a,b) { return b.count - a.count; });
    var compHtml = '<div class="flex items-center gap-2 mb-4"><i data-lucide="swords" class="w-4 h-4 text-orange-500"></i><h3 class="font-semibold text-gray-800 text-sm">竞品 AI 出现频率</h3></div>';
    if (compSorted.length === 0) {
        compHtml += '<div class="text-center py-6"><div class="text-gray-300 text-2xl mb-1">-</div><p class="text-xs text-gray-400">暂无竞品出现记录</p></div>';
    } else {
        for (var j = 0; j < Math.min(compSorted.length, 8); j++) {
            var cs = compSorted[j];
            var cr = data.total_records > 0 ? Math.round(cs.count / data.total_records * 100) : 0;
            var shortName = cs.name.length > 12 ? cs.name.substring(0,12) + '...' : cs.name;
            compHtml += '<div class="flex items-center justify-between py-1.5">' +
              '<span class="text-xs text-gray-600 truncate flex-1">' + shortName + '</span>' +
              '<div class="flex items-center gap-2 ml-2 shrink-0"><span class="text-xs text-gray-400">' + cs.count + '次</span>' +
              '<span class="text-[11px] font-semibold text-orange-600 bg-orange-50 px-2 py-0.5 rounded-full">' + cr + '%</span></div>' +
            '</div>';
        }
    }
    $('compBox').innerHTML = compHtml;

    // Report
    $('reportBrand').textContent = data.brand_name;
    var reportTime = data.created_at ? formatDateTime(data.created_at) : new Date().toLocaleString('zh-CN');
    $('reportMeta').textContent = reportTime + ' · ' + (data.model_used || 'AI') + ' 分析 · ' + data.total_records + ' 条监测数据';
    $('reportBody').innerHTML = renderReadableReport(data, {
        overallRate: overallRate,
        grade: grade,
        gradeColor: gradeColor,
        totalHit: totalHit,
        totalAll: totalAll,
        compSum: compSum
    });

    // Prompt preview
    $('promptPreview').textContent = _lastPrompt;

    $('resultArea').style.display = 'block';
    if (typeof lucide !== 'undefined') lucide.createIcons();
}

function renderReadableReport(data, metrics) {
    var sections = splitReportSections(data.report_md || '');
    var firstSection = sections.length ? sections[0] : {title:'AI 可见性总评', body:data.report_md || ''};
    var summary = firstParagraph(firstSection.body) || '暂无摘要。';
    var weakestKeywords = lowKeywordList(data.kw_stats || {}, 8);
    var topCompetitors = topCompetitorList(data.comp_overall || {}, 6);
    var actions = extractActions(data.report_md || '', weakestKeywords);

    var html = '<div class="diagnosis-hero">' +
      '<div class="diagnosis-hero__eyebrow"><i data-lucide="radar" class="w-4 h-4"></i> 全景诊断摘要</div>' +
      '<div class="diagnosis-hero__title">' + escapeHtml(data.brand_name || '当前品牌') + '：AI 可见度评级 ' +
        '<span style="color:' + metrics.gradeColor + '">' + metrics.grade + '</span></div>' +
      '<div class="diagnosis-hero__copy">' + inlineMd(summary) + '</div>' +
    '</div>';

    html += '<div class="insight-grid">' +
      metricTile('整体提及率', Number(metrics.overallRate || 0).toFixed(1) + '%', metrics.totalHit + '/' + metrics.totalAll + ' 次命中') +
      metricTile('监测样本', Number(data.total_records || 0) + ' 条', '近 30 天 AI 回答') +
      metricTile('竞品出现', Number(metrics.compSum || 0) + ' 次', topCompetitors.length ? topCompetitors.slice(0, 3).map(function(x){ return x.name; }).join('、') : '暂无竞品记录') +
    '</div>';

    if ((data.alerts || []).length) {
        html += '<div class="section-card">' +
          sectionHead('alert-triangle', '高优先级风险') +
          '<div class="section-card__body"><div class="alert-stack">' +
          (data.alerts || []).slice(0, 5).map(function(alert) {
              return '<div class="alert-card"><strong>' + escapeHtml(alert.keyword || alert.alert_type || '风险项') + '</strong><br>' + escapeHtml(alert.detail || '') + '</div>';
          }).join('') +
          '</div></div></div>';
    }

    if ((data.signals || []).length) {
        html += '<div class="section-card">' +
          sectionHead('activity', '基础信号体检') +
          '<div class="section-card__body"><div class="signal-list">' +
          (data.signals || []).map(function(signal) {
              var score = Number(signal.score || 0);
              var color = score >= 70 ? '#059669' : (score >= 40 ? '#d97706' : '#dc2626');
              return '<div class="signal-row"><span class="text-sm font-semibold text-gray-700">' + escapeHtml(signal.name || signal.signal_key || '') + '</span>' +
                '<span class="signal-score" style="color:' + color + '">' + score.toFixed(1) + '</span></div>';
          }).join('') +
          '</div></div></div>';
    }

    if (weakestKeywords.length || topCompetitors.length) {
        html += '<div class="section-card">' +
          sectionHead('target', '关键词与竞品焦点') +
          '<div class="section-card__body">' +
          (weakestKeywords.length ? '<p><strong>低提及关键词：</strong>' + weakestKeywords.map(function(x){ return '「' + escapeHtml(x.kw) + '」'; }).join('、') + '</p>' : '') +
          (topCompetitors.length ? '<p><strong>高频竞品/品类：</strong>' + topCompetitors.map(function(x){ return escapeHtml(x.name) + '（' + x.count + '次）'; }).join('、') + '</p>' : '') +
          '</div></div>';
    }

    if (actions.length) {
        html += '<div class="section-card">' +
          sectionHead('list-checks', '建议先做这几件事') +
          '<div class="section-card__body"><div class="action-list">' +
          actions.slice(0, 6).map(function(action, index) {
              return '<div class="action-item"><span class="action-item__num">' + (index + 1) + '</span><div>' + inlineMd(action) + '</div></div>';
          }).join('') +
          '</div></div></div>';
    }

    sections.forEach(function(section, index) {
        var title = normalizeSectionTitle(section.title);
        var body = section.body || '';
        if (index === 0 && body.indexOf(summary) >= 0) {
            body = body.replace(summary, '').trim();
        }
        if (!body) return;
        html += '<div class="section-card">' +
          sectionHead(sectionIcon(title), title) +
          '<div class="section-card__body">' + md2html(body) + '</div>' +
        '</div>';
    });

    return html;
}

function metricTile(label, value, hint) {
    return '<div class="insight-card"><div class="insight-card__label">' + escapeHtml(label) + '</div>' +
      '<div class="insight-card__value">' + escapeHtml(value) + '</div>' +
      '<div class="insight-card__hint">' + escapeHtml(hint) + '</div></div>';
}

function sectionHead(icon, title) {
    return '<div class="section-card__head"><span class="section-card__icon"><i data-lucide="' + icon + '" class="w-4 h-4"></i></span>' +
      '<div class="section-card__title">' + escapeHtml(title) + '</div></div>';
}

function splitReportSections(md) {
    var text = String(md || '').replace(/\r\n/g, '\n').trim();
    if (!text) return [];
    var parts = [];
    var re = /^##\s+(.+)$/gm;
    var match, lastIndex = 0, lastTitle = '';
    while ((match = re.exec(text)) !== null) {
        if (lastTitle) {
            parts.push({ title:lastTitle, body:text.slice(lastIndex, match.index).trim() });
        }
        lastTitle = match[1].trim();
        lastIndex = re.lastIndex;
    }
    if (lastTitle) {
        parts.push({ title:lastTitle, body:text.slice(lastIndex).trim() });
    }
    if (!parts.length) {
        parts.push({ title:'诊断正文', body:text });
    }
    return parts;
}

function normalizeSectionTitle(title) {
    return String(title || '').replace(/^[一二三四五六七八九十]+[、.．]\s*/, '').trim() || '诊断正文';
}

function sectionIcon(title) {
    if (title.indexOf('竞品') >= 0) return 'swords';
    if (title.indexOf('关键词') >= 0) return 'key';
    if (title.indexOf('机会') >= 0 || title.indexOf('建议') >= 0) return 'lightbulb';
    if (title.indexOf('内容') >= 0) return 'file-text';
    if (title.indexOf('可见') >= 0 || title.indexOf('总评') >= 0) return 'eye';
    return 'panel-top';
}

function firstParagraph(text) {
    var lines = String(text || '').split(/\n+/).map(function(line) {
        return line.replace(/^[-*]\s+/, '').trim();
    }).filter(function(line) {
        return line && !/^#+\s/.test(line) && !/^\*\*.+\*\*:?\s*$/.test(line);
    });
    return lines[0] || '';
}

function cleanKeywordName(name) {
    return String(name || '').replace(/（chunk #[^)]+）/g, '').trim();
}

function lowKeywordList(kwStats, limit) {
    var list = [];
    for (var kw in kwStats) {
        var item = kwStats[kw] || {};
        var total = Number(item.total || 0);
        var hit = Number(item.hit || 0);
        var rate = total > 0 ? hit / total : 0;
        if (rate < 0.2) list.push({kw:cleanKeywordName(kw), rate:rate, total:total});
    }
    list.sort(function(a, b) { return a.rate - b.rate || b.total - a.total; });
    return list.slice(0, limit || 8);
}

function topCompetitorList(compOverall, limit) {
    var list = [];
    for (var name in compOverall) list.push({name:name, count:Number(compOverall[name] || 0)});
    list.sort(function(a, b) { return b.count - a.count; });
    return list.slice(0, limit || 6);
}

function extractActions(md, fallbackKeywords) {
    var lines = String(md || '').split(/\n+/).map(function(line) {
        return line.replace(/^[-*]\s+/, '').replace(/^\d+[.、]\s*/, '').trim();
    }).filter(Boolean);
    var actions = lines.filter(function(line) {
        return /(建议|优先|需要|应当|应该|补齐|创建|优化|发布|建立)/.test(line) && line.length >= 18;
    });
    var seen = {};
    actions = actions.filter(function(line) {
        var key = line.slice(0, 40);
        if (seen[key]) return false;
        seen[key] = true;
        return true;
    });
    if (!actions.length && fallbackKeywords.length) {
        actions.push('优先为 ' + fallbackKeywords.slice(0, 5).map(function(x){ return '「' + x.kw + '」'; }).join('、') + ' 建立问答、清单和对比型内容。');
        actions.push('把品牌事实、产地、检测、功效边界和选购标准整理成结构化页面，提升 AI 可引用性。');
    }
    return actions;
}

function inlineMd(value) {
    return escapeHtml(value)
      .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
      .replace(/`([^`]+)`/g, '<code>$1</code>');
}

/* ── Markdown → HTML ── */
function md2html(md) {
    if (!md) return '';
    // Escape HTML
    md = md.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

    // Tables
    md = md.replace(/(\|.+\|\n)+/g, function(match) {
        var rows = match.trim().split('\n').filter(function(r){return r.trim();});
        if (rows.length < 2) return match;
        var html = '<table>';
        var isHead = true;
        for (var i = 0; i < rows.length; i++) {
            if (/^\|[-| :]+\|$/.test(rows[i])) { isHead = false; continue; }
            var cells = rows[i].replace(/^\|/, '').replace(/\|$/, '').split('|').map(function(c){return c.trim();});
            var tag = isHead ? 'th' : 'td';
            html += '<tr>' + cells.map(function(c){return '<'+tag+'>'+c+'</'+tag+'>';}).join('') + '</tr>';
            if (isHead) isHead = false;
        }
        return html + '</table>';
    });

    // Headings
    md = md.replace(/^#### (.+)$/gm, '<h4>$1</h4>');
    md = md.replace(/^### (.+)$/gm, '<h3>$1</h3>');
    md = md.replace(/^## (.+)$/gm, '<h2>$1</h2>');

    // Bold & italic
    md = md.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
    md = md.replace(/\*(.+?)\*/g, '<em>$1</em>');

    // Inline code
    md = md.replace(/`([^`]+)`/g, '<code>$1</code>');

    // Unordered list items
    md = md.replace(/^[-*] (.+)$/gm, '<li>$1</li>');

    // Ordered list items
    md = md.replace(/^\d+\.\s+(.+)$/gm, '<li>$1</li>');

    // Wrap consecutive <li> in <ul>
    md = md.replace(/((?:<li>.*<\/li>\n?)+)/g, '<ul>$1</ul>');

    // Status indicators
    md = md.replace(/🔴/g, '<span style="color:#ef4444">🔴</span>');
    md = md.replace(/🟡/g, '<span style="color:#f59e0b">🟡</span>');
    md = md.replace(/🟢/g, '<span style="color:#10b981">🟢</span>');

    // Paragraphs (lines not already wrapped)
    md = md.replace(/^(?!<[a-z])((?!^\s*$).+)$/gm, '<p>$1</p>');

    // Clean up empty paragraphs
    md = md.replace(/<p>\s*<\/p>/g, '');

    return md;
}

function exportReport() {
    var brand = $('reportBrand').textContent;
    var content = '# GEO 全景诊断报告 - ' + brand + '\n\n' +
      '生成时间：' + new Date().toLocaleString('zh-CN') + '\n\n' +
      (_lastPrompt ? '---\n\n## AI Prompt\n\n```\n' + _lastPrompt + '\n```\n\n---\n\n' : '') +
      '## 报告正文\n\n' + $('reportBody').innerText;
    var blob = new Blob([content], {type:'text/markdown;charset=utf-8'});
    var a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'GEO全景诊断_' + brand + '_' + new Date().toISOString().slice(0,10) + '.md';
    a.click();
}
</script>
</body>
</html>
