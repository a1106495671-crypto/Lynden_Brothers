<?php
/**
 * 动态工作流 - AI 分析品牌状态，推荐本轮应该执行哪些步骤
 */
define('FEISHU_TREASURE', true);
session_start();
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/database_admin.php';
require_admin_login();

// 客户列表
$stmtC = $db->query("SELECT DISTINCT k.customer_id, COALESCE((SELECT fact_value FROM geo_brand_facts WHERE customer_id=k.customer_id AND fact_key='brand_name' LIMIT 1), k.customer_id) AS brand_name FROM geo_monitor_keywords k ORDER BY brand_name");
$customers = $stmtC ? $stmtC->fetchAll(PDO::FETCH_ASSOC) : [];

$page_title = '动态工作流';
require_once __DIR__ . '/includes/header.php';

function dh($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
?>

<div class="max-w-4xl mx-auto px-4 py-6">
  <div class="flex items-center justify-between mb-6">
    <div>
      <h1 class="text-2xl font-bold text-gray-900 flex items-center gap-2">
        <span class="inline-flex items-center justify-center w-9 h-9 rounded-lg bg-violet-100 text-violet-600">
          <i data-lucide="cpu" class="w-5 h-5"></i>
        </span>
        动态工作流
      </h1>
      <p class="text-sm text-gray-500 mt-1">AI 分析品牌当前状态，推荐本轮应该执行哪些自动化步骤</p>
    </div>
    <a href="automation-workflow.php" class="text-sm text-gray-500 hover:text-gray-700 flex items-center gap-1">
      <i data-lucide="arrow-left" class="w-4 h-4"></i> 标准工作流
    </a>
  </div>

  <!-- 客户选择 -->
  <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6 mb-6">
    <div class="flex flex-col sm:flex-row gap-4 items-end">
      <div class="flex-1">
        <label class="block text-sm font-medium text-gray-700 mb-1.5">选择品牌客户</label>
        <select id="customerSelect" class="w-full border border-gray-300 rounded-lg px-3 py-2.5 text-sm focus:ring-2 focus:ring-violet-500 outline-none">
          <option value="">-- 请选择 --</option>
          <?php foreach ($customers as $c): ?>
          <option value="<?= dh($c['customer_id']) ?>"><?= dh($c['brand_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button onclick="getRecommendation()" id="analyzeBtn"
        class="inline-flex items-center gap-2 px-6 py-2.5 bg-violet-600 text-white text-sm font-medium rounded-lg hover:bg-violet-700 disabled:opacity-50 shadow-sm transition shrink-0">
        <i data-lucide="brain" class="w-4 h-4"></i>
        AI 分析
      </button>
    </div>
  </div>

  <!-- Loading -->
  <div id="loadingBox" style="display:none" class="bg-white rounded-xl border border-violet-200 shadow-sm p-10 text-center mb-6">
    <div class="text-base font-semibold text-gray-800 mb-2">AI 正在分析品牌状态…</div>
    <div class="inline-flex items-center gap-2 bg-violet-50 rounded-full px-4 py-1.5 mt-2">
      <div class="w-2 h-2 rounded-full bg-violet-500 animate-pulse"></div>
      <span class="text-sm font-mono text-violet-700" id="loadingTimer">0s</span>
    </div>
  </div>

  <!-- Error -->
  <div id="errorBox" style="display:none" class="mb-6 p-4 bg-red-50 border border-red-200 rounded-xl">
    <span class="text-sm font-semibold text-red-800">分析失败：</span>
    <span class="text-sm text-red-600" id="errorText"></span>
  </div>

  <!-- Result -->
  <div id="resultArea" style="display:none">
    <!-- Status card -->
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6 mb-5">
      <h2 class="text-sm font-semibold text-gray-700 mb-3 flex items-center gap-2">
        <i data-lucide="bar-chart-2" class="w-4 h-4 text-gray-400"></i> 当前状态快照
      </h2>
      <div class="grid grid-cols-2 sm:grid-cols-4 gap-4" id="statusCards"></div>
    </div>

    <!-- AI Recommendation -->
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden mb-5">
      <div class="px-6 py-4 border-b border-gray-100 bg-gradient-to-r from-violet-50 to-white">
        <h2 class="font-bold text-gray-900 flex items-center gap-2">
          <i data-lucide="lightbulb" class="w-4 h-4 text-violet-500"></i>
          AI 推荐方案
        </h2>
        <p class="text-sm text-gray-500 mt-0.5" id="reasonText"></p>
      </div>
      <div class="p-6 grid grid-cols-1 sm:grid-cols-2 gap-6">
        <div>
          <div class="flex items-center gap-2 mb-3">
            <span class="inline-flex items-center justify-center w-6 h-6 rounded-full bg-green-100 text-green-600 text-xs font-bold">✓</span>
            <span class="text-sm font-semibold text-gray-700">本轮执行</span>
          </div>
          <ul id="runList" class="space-y-2"></ul>
        </div>
        <div>
          <div class="flex items-center gap-2 mb-3">
            <span class="inline-flex items-center justify-center w-6 h-6 rounded-full bg-gray-100 text-gray-500 text-xs font-bold">—</span>
            <span class="text-sm font-semibold text-gray-700">本轮跳过</span>
          </div>
          <ul id="skipList" class="space-y-2"></ul>
        </div>
      </div>
      <div class="px-6 pb-5">
        <div class="p-3 bg-amber-50 border border-amber-200 rounded-lg text-sm text-amber-800 flex items-start gap-2">
          <i data-lucide="zap" class="w-4 h-4 shrink-0 mt-0.5 text-amber-500"></i>
          <span><strong>优先事项：</strong> <span id="priorityNote"></span></span>
        </div>
      </div>
    </div>

    <!-- Execute button -->
    <div class="flex justify-end">
      <a id="executeBtn" href="automation-workflow.php"
        class="inline-flex items-center gap-2 px-6 py-2.5 bg-green-600 text-white text-sm font-medium rounded-lg hover:bg-green-700 shadow-sm transition">
        <i data-lucide="play" class="w-4 h-4"></i>
        前往标准工作流执行
      </a>
    </div>
  </div>
</div>

<script>
var _timer=null,_secs=0;
function ge(id){return document.getElementById(id);}

var STEP_LABELS={
  keyword_library:'关键词库',title_library:'标题库',knowledge_base:'知识库',
  customer_create:'客户档案',task_create:'生成任务',article_generate:'批量文章生成',
  media_distribute:'媒体分发',monitor_setup:'监测配置',monitor_run:'立即监测',
  diagnosis:'GEO诊断',strategy:'内容策略',weekly_report:'周报推送'
};

function getRecommendation(){
  var cid=ge('customerSelect').value;
  if(!cid){alert('请选择客户');return;}
  ge('resultArea').style.display='none';
  ge('errorBox').style.display='none';
  ge('loadingBox').style.display='block';
  ge('analyzeBtn').disabled=true;
  _secs=0;ge('loadingTimer').textContent='0s';
  _timer=setInterval(function(){_secs++;ge('loadingTimer').textContent=_secs+'s';},1000);

  var fd=new FormData();fd.append('customer_id',cid);
  fetch('api/automation-dynamic.php',{method:'POST',body:fd})
  .then(function(r){return r.json();})
  .then(function(d){
    clearInterval(_timer);ge('loadingBox').style.display='none';ge('analyzeBtn').disabled=false;
    if(!d.success){ge('errorText').textContent=d.error||'未知错误';ge('errorBox').style.display='block';return;}
    renderResult(d);
  })
  .catch(function(err){
    clearInterval(_timer);ge('loadingBox').style.display='none';ge('analyzeBtn').disabled=false;
    ge('errorText').textContent='网络错误: '+err.message;ge('errorBox').style.display='block';
  });
}

function renderResult(d){
  var s=d.status||{};
  ge('statusCards').innerHTML=[
    stat('文章数量',s.article_count||0,'篇','blue'),
    stat('本周引用率',(s.citation_rate||0)+'%','','emerald'),
    stat('诊断评分',(s.diag_score||0)+'/100','','violet'),
    stat('监测关键词',s.keyword_count||0,'个','amber'),
  ].join('');

  ge('reasonText').textContent=d.reason||'';
  ge('priorityNote').textContent=d.priority_note||'—';

  var run=d.run||[],skip=d.skip||[];
  ge('runList').innerHTML=run.map(function(s){return '<li class="flex items-center gap-2 text-sm text-gray-700"><span class="inline-block w-2 h-2 rounded-full bg-green-500"></span>'+(STEP_LABELS[s]||s)+'</li>';}).join('')||'<li class="text-sm text-gray-400">无</li>';
  ge('skipList').innerHTML=skip.map(function(s){return '<li class="flex items-center gap-2 text-sm text-gray-400"><span class="inline-block w-2 h-2 rounded-full bg-gray-300"></span>'+(STEP_LABELS[s]||s)+'</li>';}).join('')||'<li class="text-sm text-gray-400">无</li>';

  ge('resultArea').style.display='block';
  lucide.createIcons();
}

function stat(label,value,unit,color){
  var colors={blue:'bg-blue-50 text-blue-600',emerald:'bg-emerald-50 text-emerald-600',violet:'bg-violet-50 text-violet-600',amber:'bg-amber-50 text-amber-600'};
  return '<div class="'+colors[color]+' rounded-lg p-4 text-center"><div class="text-xl font-bold">'+value+(unit?'<span class="text-sm font-normal ml-0.5">'+unit+'</span>':'')+'</div><div class="text-xs mt-1 opacity-75">'+label+'</div></div>';
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
