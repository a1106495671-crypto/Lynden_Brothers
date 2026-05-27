<?php
/**
 * GEO 参考文章发现器 + 元写作风格提取器
 * 输入关键词 → AI 推演高引用文章结构 → 提炼 Meta Writing Style → 保存为 Prompt 模板
 */
define('FEISHU_TREASURE', true);
session_start();
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/database_admin.php';
require_admin_login();

// ── AJAX: 保存为 Prompt 模板 ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_prompt') {
    header('Content-Type: application/json; charset=utf-8');
    session_write_close();
    $name    = trim($_POST['prompt_name'] ?? '');
    $content = trim($_POST['prompt_content'] ?? '');
    if ($name === '' || $content === '') {
        echo json_encode(['success' => false, 'error' => '名称和内容不能为空']);
        exit;
    }
    try {
        $stmt = $db->prepare("INSERT INTO prompts (name, type, content, created_at, updated_at) VALUES (?, 'content', ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
        $stmt->execute([$name, $content]);
        echo json_encode(['success' => true, 'id' => $db->lastInsertId()]);
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ── AJAX: AI 两步生成 ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'generate') {
    header('Content-Type: application/json; charset=utf-8');
    session_write_close();

    $keywords  = trim($_POST['keywords'] ?? '');
    $brandName = trim($_POST['brand_name'] ?? '');
    $platform  = trim($_POST['platform'] ?? 'general');

    if ($keywords === '') {
        echo json_encode(['success' => false, 'error' => '请输入关键词']);
        exit;
    }

    // Step 1: 推演高引用文章结构
    $step1 = geo_call_ai(<<<PROMPT
你是GEO内容研究员。基于以下关键词，推演20-30个「最可能被AI大模型引用」的文章结构原型。
不需要是真实文章，而是你根据行业经验推演的高引用文章模板。

关键词：
{$keywords}

品牌（如有）：{$brandName}
目标平台：{$platform}

每个结构输出：
- 类型标签（定义解释型/判断标准型/操作流程型/对比分析型/案例证据型/风险避坑型/FAQ聚合型）
- 标题示例（1条）
- 内容骨架（3-5个段落主题，每个一句话）
- 为何容易被AI引用（1-2句）

用 ### 分隔每个结构，只输出结构不输出正文。
PROMPT, 4000, 0.5);

    if (!empty($step1['error']) || empty(trim($step1['content'] ?? ''))) {
        echo json_encode(['success' => false, 'error' => 'Step1 AI失败: ' . ($step1['error'] ?? '空响应')]);
        exit;
    }

    $platformLabels = ['zhihu' => '知乎（专业深度）', 'xiaohongshu' => '小红书（干货种草）', 'wechat' => '公众号（深度阅读）', 'general' => '通用多平台'];
    $platformLabel  = $platformLabels[$platform] ?? '通用多平台';

    // Step 2: 提炼 meta writing style
    $step2 = geo_call_ai(<<<PROMPT
你是GEO内容策略师。我分析了一批AI大模型高频引用的文章结构（见下方），
请从中提炼可复用的元写作风格指南，整理成可直接注入AI写作Prompt的风格指令。

目标平台：{$platformLabel}
品牌名：{$brandName}
关键词范围：{$keywords}

===== 高引用文章结构参考 =====
{$step1['content']}
===== END =====

输出格式（严格按此）：

## 核心写作模式
（3-5条，每条20-40字）

## 平台写作风格指令（可直接注入Prompt）
（纯文本段落150-250字：开头策略、段落结构与长度、语气人称、结尾处理、平台特性）

## 避坑提示
（2-3条，每条15-30字）
PROMPT, 3000, 0.4);

    if (!empty($step2['error']) || empty(trim($step2['content'] ?? ''))) {
        echo json_encode(['success' => false, 'error' => 'Step2 AI失败: ' . ($step2['error'] ?? '空响应'), 'article_structures' => $step1['content']]);
        exit;
    }

    echo json_encode([
        'success'            => true,
        'article_structures' => $step1['content'],
        'meta_prompt'        => $step2['content'],
        'model_used'         => $step2['model_used'] ?? 'AI',
    ]);
    exit;
}

session_write_close();
$page_title = '参考文章发现器';
require_once __DIR__ . '/includes/header.php';
?>
<style>
.section-toggle{max-height:0;opacity:0;overflow:hidden;transition:max-height .5s ease,opacity .3s ease}
.section-toggle.open{max-height:9999px;opacity:1}
@keyframes fadeIn{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}
.fade-in{animation:fadeIn .35s ease-out both}
</style>

<div class="max-w-5xl mx-auto px-4 py-6">
  <div class="flex items-center justify-between mb-6">
    <div>
      <h1 class="text-2xl font-bold text-gray-900 flex items-center gap-2">
        <span class="inline-flex items-center justify-center w-9 h-9 rounded-lg bg-emerald-100 text-emerald-600">
          <i data-lucide="book-marked" class="w-5 h-5"></i>
        </span>
        参考文章发现器
      </h1>
      <p class="text-sm text-gray-500 mt-1">输入关键词 → AI推演高引用文章结构 → 提炼Meta写作风格 → 保存为Prompt模板</p>
    </div>
    <a href="ai-prompts.php" class="text-sm text-gray-500 hover:text-gray-700 flex items-center gap-1">
      <i data-lucide="arrow-left" class="w-4 h-4"></i> 提示词管理
    </a>
  </div>

  <!-- Form -->
  <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6 mb-6">
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">
      <div class="lg:col-span-2">
        <label class="block text-sm font-medium text-gray-700 mb-1.5">
          关键词 <span class="text-red-500">*</span>
          <span class="text-gray-400 font-normal ml-1">（5-10个，每行一个或逗号分隔）</span>
        </label>
        <textarea id="keywords" rows="7"
          class="w-full border border-gray-300 rounded-lg px-3 py-2.5 text-sm focus:ring-2 focus:ring-emerald-500 outline-none resize-y"
          placeholder="GEO优化&#10;AI引用率&#10;品牌知识图谱&#10;生成式搜索&#10;结构化内容"></textarea>
      </div>
      <div class="flex flex-col gap-4">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1.5">品牌名（可选）</label>
          <input type="text" id="brandName"
            class="w-full border border-gray-300 rounded-lg px-3 py-2.5 text-sm focus:ring-2 focus:ring-emerald-500 outline-none"
            placeholder="例如：董逻辑MGEO">
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1.5">目标平台</label>
          <select id="platform" class="w-full border border-gray-300 rounded-lg px-3 py-2.5 text-sm focus:ring-2 focus:ring-emerald-500 outline-none">
            <option value="general">通用多平台</option>
            <option value="zhihu">知乎</option>
            <option value="xiaohongshu">小红书</option>
            <option value="wechat">公众号</option>
          </select>
        </div>
        <button onclick="startGenerate()" id="genBtn"
          class="mt-auto w-full inline-flex items-center justify-center gap-2 px-5 py-2.5 bg-emerald-600 text-white text-sm font-medium rounded-lg hover:bg-emerald-700 disabled:opacity-50 shadow-sm transition">
          <i data-lucide="sparkles" class="w-4 h-4"></i>
          分析 + 提炼 Meta-Prompt
        </button>
      </div>
    </div>
  </div>

  <!-- Loading -->
  <div id="loadingBox" style="display:none" class="bg-white rounded-xl border border-emerald-200 shadow-sm p-10 text-center mb-6 fade-in">
    <div class="text-base font-semibold text-gray-800 mb-2" id="loadingLabel">Step 1：AI推演高引用文章结构…</div>
    <div class="text-xs text-gray-400">两步AI调用，通常需要 30-60 秒</div>
    <div class="inline-flex items-center gap-2 bg-emerald-50 rounded-full px-4 py-1.5 mt-3">
      <div class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></div>
      <span class="text-sm font-mono text-emerald-700" id="loadingTimer">0s</span>
    </div>
  </div>

  <!-- Error -->
  <div id="errorBox" style="display:none" class="mb-6 p-4 bg-red-50 border border-red-200 rounded-xl fade-in">
    <span class="text-sm font-semibold text-red-800">生成失败：</span>
    <span class="text-sm text-red-600" id="errorText"></span>
  </div>

  <!-- Results -->
  <div id="resultArea" style="display:none" class="fade-in">
    <!-- Meta-Prompt -->
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden mb-5">
      <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100 bg-gradient-to-r from-emerald-50 to-white">
        <div class="flex items-center gap-3">
          <span class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-emerald-100 text-emerald-600">
            <i data-lucide="wand-2" class="w-4 h-4"></i>
          </span>
          <div>
            <h2 class="font-bold text-gray-900 text-base">提炼的 Meta Writing Style</h2>
            <span class="text-xs text-gray-400" id="modelUsedLabel"></span>
          </div>
        </div>
        <button onclick="copyMeta()" class="text-xs text-gray-500 hover:text-gray-700 border border-gray-200 rounded-lg px-3 py-1.5 hover:bg-gray-50 transition flex items-center gap-1.5">
          <i data-lucide="copy" class="w-3.5 h-3.5"></i> 复制
        </button>
      </div>
      <div class="px-6 py-5">
        <textarea id="metaPromptOutput" rows="14"
          class="w-full border border-gray-200 rounded-lg px-4 py-3 text-sm font-mono bg-gray-50 focus:ring-2 focus:ring-emerald-500 outline-none resize-y"></textarea>
      </div>
      <div class="px-6 pb-5 flex items-center gap-3">
        <input type="text" id="saveName"
          class="flex-1 border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-emerald-500 outline-none"
          placeholder="模板名称">
        <button onclick="savePrompt()" id="saveBtn"
          class="inline-flex items-center gap-2 px-5 py-2 bg-indigo-600 text-white text-sm font-medium rounded-lg hover:bg-indigo-700 disabled:opacity-50 shadow-sm transition">
          <i data-lucide="save" class="w-4 h-4"></i>保存为 Prompt 模板
        </button>
        <span id="saveStatus" class="text-xs text-gray-500"></span>
      </div>
    </div>

    <!-- Article structures (collapsible) -->
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden mb-5">
      <button onclick="toggleStructures()" class="w-full flex items-center justify-between px-6 py-4 hover:bg-gray-50 transition text-left">
        <div class="flex items-center gap-3">
          <span class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-blue-100 text-blue-600">
            <i data-lucide="layers" class="w-4 h-4"></i>
          </span>
          <span class="font-semibold text-gray-800 text-sm">Step 1：AI推演的高引用文章结构 <span class="text-xs text-gray-400 ml-1">点击展开</span></span>
        </div>
        <i data-lucide="chevron-down" id="structChevron" class="w-4 h-4 text-gray-400 transition-transform duration-300"></i>
      </button>
      <div id="structContent" class="section-toggle">
        <div class="px-6 pb-5 border-t border-gray-100 pt-4">
          <pre id="structOutput" class="bg-gray-900 text-gray-100 rounded-lg p-5 text-xs leading-relaxed overflow-x-auto whitespace-pre-wrap font-mono" style="max-height:500px;overflow-y:auto"></pre>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
var _timer=null,_secs=0,_structOpen=false;
function ge(id){return document.getElementById(id);}

function startGenerate(){
  var kw=ge('keywords').value.trim();
  if(!kw){alert('请输入关键词');return;}
  ge('resultArea').style.display='none';
  ge('errorBox').style.display='none';
  ge('loadingBox').style.display='block';
  ge('genBtn').disabled=true;
  ge('loadingLabel').textContent='Step 1：AI推演高引用文章结构…';
  _secs=0;ge('loadingTimer').textContent='0s';
  _timer=setInterval(function(){_secs++;ge('loadingTimer').textContent=_secs+'s';if(_secs===25)ge('loadingLabel').textContent='Step 2：提炼Meta Writing Style…';},1000);

  var fd=new FormData();
  fd.append('action','generate');
  fd.append('keywords',kw);
  fd.append('brand_name',ge('brandName').value.trim());
  fd.append('platform',ge('platform').value);

  fetch(window.location.pathname,{method:'POST',body:fd})
  .then(function(r){return r.json();})
  .then(function(d){
    clearInterval(_timer);ge('loadingBox').style.display='none';ge('genBtn').disabled=false;
    if(!d.success){ge('errorText').textContent=d.error||'未知错误';ge('errorBox').style.display='block';return;}
    ge('metaPromptOutput').value=d.meta_prompt||'';
    ge('structOutput').textContent=d.article_structures||'';
    ge('modelUsedLabel').textContent='模型：'+(d.model_used||'AI')+' · '+new Date().toLocaleTimeString('zh-CN');
    var kw0=ge('keywords').value.trim().split(/[\n,，]/)[0].trim();
    ge('saveName').value='Meta写作风格_'+ge('platform').options[ge('platform').selectedIndex].text+'_'+kw0;
    ge('resultArea').style.display='block';lucide.createIcons();
  })
  .catch(function(err){
    clearInterval(_timer);ge('loadingBox').style.display='none';ge('genBtn').disabled=false;
    ge('errorText').textContent='网络错误: '+err.message;ge('errorBox').style.display='block';
  });
}

function copyMeta(){
  var t=ge('metaPromptOutput').value;
  if(!t){return;}
  navigator.clipboard.writeText(t).then(function(){AdminUtils.showToast('已复制','success');});
}

function savePrompt(){
  var name=ge('saveName').value.trim(),content=ge('metaPromptOutput').value.trim();
  if(!name){AdminUtils.showToast('请填写模板名称','warning');return;}
  if(!content){AdminUtils.showToast('内容为空','warning');return;}
  ge('saveBtn').disabled=true;ge('saveStatus').textContent='保存中…';
  var fd=new FormData();
  fd.append('action','save_prompt');fd.append('prompt_name',name);fd.append('prompt_content',content);
  fetch(window.location.pathname,{method:'POST',body:fd})
  .then(function(r){return r.json();})
  .then(function(d){
    ge('saveBtn').disabled=false;
    if(d.success){ge('saveStatus').textContent='已保存 (ID:'+d.id+')';AdminUtils.showToast('已保存','success');}
    else{ge('saveStatus').textContent='失败';AdminUtils.showToast('保存失败: '+(d.error||''),'error');}
  });
}

function toggleStructures(){
  _structOpen=!_structOpen;
  ge('structContent').classList.toggle('open',_structOpen);
  ge('structChevron').style.transform=_structOpen?'rotate(180deg)':'rotate(0deg)';
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
