<?php
/**
 * 对话式品牌入驻 - AI 对话收集品牌信息，自动触发12步入驻流程
 */
define('FEISHU_TREASURE', true);
session_start();
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/database_admin.php';
require_admin_login();

// ── AJAX: 重置会话 ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reset') {
    unset($_SESSION['onboard_chat']);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => true]);
    exit;
}

// ── AJAX: 发送消息 ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'chat') {
    header('Content-Type: application/json; charset=utf-8');

    $userMsg = trim($_POST['message'] ?? '');
    if ($userMsg === '') {
        echo json_encode(['success' => false, 'error' => '消息不能为空']);
        exit;
    }

    // 初始化会话
    if (!isset($_SESSION['onboard_chat'])) {
        $_SESSION['onboard_chat'] = ['messages' => [], 'fields' => [], 'done' => false];
    }
    $chat = &$_SESSION['onboard_chat'];

    if ($chat['done']) {
        echo json_encode(['success' => false, 'error' => '信息已收集完毕，请开始入驻']);
        exit;
    }

    // 加入用户消息
    $chat['messages'][] = ['role' => 'user', 'content' => $userMsg];

    // 系统 Prompt
    $systemPrompt = <<<SYS
你是一名 GEO（生成式引擎优化）品牌入驻顾问。GEO 的目标是让 AI 搜索引擎（DeepSeek、豆包、Kimi、ChatGPT 等）在回答用户问题时主动提及并推荐该品牌。你现在要通过对话收集品牌信息，为后续 AI 内容生产和引用率提升做准备。

## 你要收集的6个字段及其 GEO 意义

1. **brand_name（品牌名称）**
   - GEO 用途：作为品牌实体词，所有文章、问答都会围绕它建立引用
   - 引导方式：直接问品牌名，如果用户说了中英文两个版本都要记录

2. **industry（所属行业）**
   - GEO 用途：决定监测哪些行业问题词、AI 在哪类对话中应提及该品牌
   - 引导方式：让用户说具体细分行业，比如不只说"科技"，而是"企业 SaaS / 算力租赁 / 医美"
   - 如果用户说的太宽泛，追问一次："能说得更具体些吗？比如主要服务哪类客户？"

3. **website（官方网站）**
   - GEO 用途：AI 会抓取官网内容补充品牌知识库，增强事实准确性
   - 引导方式：要完整域名，如 "example.com"，没有官网填"无"

4. **core_services（核心服务/产品）**
   - GEO 用途：这是 AI 内容生产的主题词池，每篇文章都会围绕某项服务展开
   - 引导方式：让用户列出 3-5 项最想被 AI 搜索引擎提及的服务/产品，并简单说明各自解决什么问题
   - 示例引导："比如：'算力调度（帮企业降低 GPU 成本）、弹性扩容（峰值流量无需提前采购）'"

5. **competitors（主要竞争对手）**
   - GEO 用途：生成竞品对比类内容，在用户问"A 和 B 哪个好"时让该品牌出现
   - 引导方式：问"用户在选你们和哪些品牌之间纠结？"，要真实品牌名，不知道可填"暂无"

6. **positioning（品牌一句话定位）**
   - GEO 用途：这句话会成为 AI 描述该品牌时最常引用的表述，要准确、有区分度
   - 引导方式："用一句话告诉 AI，你们是做什么的、服务谁、有什么不同？比如：'专为中小企业提供按需付费算力服务，比自建 GPU 集群节省 60% 成本'"
   - 如果用户的回答太模糊（如"提供高质量服务"），温和地追问一次要具体数据或差异点

## 对话规则

- 每次只问一个问题，收到回答后才问下一个
- 语气专业但亲切，像一个懂 GEO 的顾问在引导，不像机器人
- 每问一个字段时，可以用一句话说明这个信息有什么用（GEO 价值），让用户明白为什么要填
- 用户回答模糊时追问**最多一次**，之后接受并继续
- 不要一次性列出所有问题

## 完成时的输出

收集到全部6项后，只输出以下 JSON（不要有任何其他文字）：
{"brand_name":"...","industry":"...","website":"...","core_services":"...","competitors":"...","positioning":"...","__complete__":true}
SYS;

    // 调用 AI
    $cfg = get_active_ai_config();
    if (empty($cfg['api_key'])) {
        echo json_encode(['success' => false, 'error' => '未配置 AI 模型，请先在AI模型页面添加']);
        exit;
    }

    $apiUrl = rtrim($cfg['api_url'], '/');
    if (!str_ends_with($apiUrl, '/chat/completions')) {
        $apiUrl .= '/chat/completions';
    }

    $messages = array_merge(
        [['role' => 'system', 'content' => $systemPrompt]],
        $chat['messages']
    );

    $payload = json_encode([
        'model'       => $cfg['model_id'],
        'messages'    => $messages,
        'max_tokens'  => 500,
        'temperature' => 0.5,
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init($apiUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $cfg['api_key']],
    ]);
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200) {
        echo json_encode(['success' => false, 'error' => "AI 调用失败 HTTP {$code}"]);
        exit;
    }

    $data      = json_decode($raw, true);
    $aiContent = trim($data['choices'][0]['message']['content'] ?? '');

    if ($aiContent === '') {
        echo json_encode(['success' => false, 'error' => 'AI 返回空内容']);
        exit;
    }

    // 加入 AI 消息
    $chat['messages'][] = ['role' => 'assistant', 'content' => $aiContent];

    // 检测是否完成（AI 返回 JSON 含 __complete__）
    $completed = false;
    $fields    = [];
    if (preg_match('/\{[\s\S]*"__complete__"\s*:\s*true[\s\S]*\}/u', $aiContent, $m)) {
        $parsed = json_decode($m[0], true);
        if ($parsed && !empty($parsed['brand_name'])) {
            $completed = true;
            $fields    = array_filter([
                'brand_name'   => trim($parsed['brand_name']   ?? ''),
                'industry'     => trim($parsed['industry']     ?? ''),
                'website'      => trim($parsed['website']      ?? ''),
                'core_services'=> trim($parsed['core_services']?? ''),
                'competitors'  => trim($parsed['competitors']  ?? ''),
                'positioning'  => trim($parsed['positioning']  ?? ''),
            ]);
            $chat['done']   = true;
            $chat['fields'] = $fields;
        }
    }

    echo json_encode([
        'success'   => true,
        'message'   => $aiContent,
        'completed' => $completed,
        'fields'    => $completed ? $fields : null,
    ]);
    exit;
}

// ── 页面渲染 ──
$page_title = '对话式品牌入驻';
require_once __DIR__ . '/includes/header.php';
?>

<style>
.chat-bubble-user { background:#2563eb; color:#fff; border-radius:18px 18px 4px 18px; }
.chat-bubble-ai   { background:#f3f4f6; color:#1f2937; border-radius:18px 18px 18px 4px; }
#chatMessages     { scrollbar-width:thin; }
</style>

<div class="max-w-2xl mx-auto px-4 py-6">
  <div class="flex items-center justify-between mb-6">
    <div>
      <h1 class="text-2xl font-bold text-gray-900 flex items-center gap-2">
        <span class="inline-flex items-center justify-center w-9 h-9 rounded-lg bg-blue-100 text-blue-600">
          <i data-lucide="message-square" class="w-5 h-5"></i>
        </span>
        对话式品牌入驻
      </h1>
      <p class="text-sm text-gray-500 mt-1">和 AI 对话，自动完成品牌信息收集并触发入驻流程</p>
    </div>
    <a href="automation-workflow.php" class="text-sm text-gray-500 hover:text-gray-700 flex items-center gap-1">
      <i data-lucide="arrow-left" class="w-4 h-4"></i> 标准工作流
    </a>
  </div>

  <!-- Chat window -->
  <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden" style="height:520px;display:flex;flex-direction:column;">
    <!-- Messages -->
    <div id="chatMessages" class="flex-1 overflow-y-auto p-5 space-y-4">
      <!-- 欢迎消息 -->
      <div class="flex justify-start">
        <div class="chat-bubble-ai px-4 py-3 text-sm leading-relaxed" style="max-width:85%">
          你好！我是 GEO 品牌入驻顾问。<br><br>
          接下来我会问你 <strong>6 个问题</strong>，收集完后系统会自动生成关键词库、标题库、知识库，并启动内容生产和 AI 引用监测。<br><br>
          整个过程大约 <strong>3 分钟</strong>，你只需要回答问题。<br><br>
          先来第一个：<strong>这次要入驻的品牌名称是什么？</strong>（中英文都可以）
        </div>
      </div>
    </div>

    <!-- 完成卡片（隐藏） -->
    <div id="completeCard" style="display:none" class="mx-4 mb-3 p-4 bg-emerald-50 border border-emerald-200 rounded-xl">
      <div class="flex items-center gap-2 mb-3">
        <i data-lucide="check-circle" class="w-5 h-5 text-emerald-600"></i>
        <span class="font-semibold text-emerald-800">信息收集完毕！</span>
      </div>
      <div id="fieldsSummary" class="text-sm text-emerald-700 space-y-1 mb-4"></div>
      <button onclick="startOnboarding()" id="startBtn"
        class="w-full inline-flex items-center justify-center gap-2 px-4 py-2.5 bg-emerald-600 text-white text-sm font-medium rounded-lg hover:bg-emerald-700 shadow-sm transition">
        <i data-lucide="rocket" class="w-4 h-4"></i>
        开始自动入驻
      </button>
    </div>

    <!-- Input bar -->
    <div id="inputBar" class="border-t border-gray-100 p-3 flex gap-2">
      <input type="text" id="chatInput"
        class="flex-1 border border-gray-300 rounded-xl px-4 py-2.5 text-sm focus:ring-2 focus:ring-blue-500 outline-none"
        placeholder="在这里回复…"
        onkeydown="if(event.key==='Enter'&&!event.shiftKey){event.preventDefault();sendMessage();}">
      <button onclick="sendMessage()" id="sendBtn"
        class="inline-flex items-center justify-center w-10 h-10 bg-blue-600 text-white rounded-xl hover:bg-blue-700 disabled:opacity-50 transition shrink-0">
        <i data-lucide="send" class="w-4 h-4"></i>
      </button>
    </div>
  </div>

  <!-- Reset -->
  <div class="mt-3 flex justify-end">
    <button onclick="resetChat()" class="text-xs text-gray-400 hover:text-gray-600 transition flex items-center gap-1">
      <i data-lucide="rotate-ccw" class="w-3 h-3"></i> 重新开始
    </button>
  </div>
</div>

<!-- 隐藏的入驻表单 -->
<form id="onboardForm" method="POST" action="api/automation-start.php" style="display:none">
  <input type="hidden" name="brand_name"    id="f_brand_name">
  <input type="hidden" name="industry"      id="f_industry">
  <input type="hidden" name="website"       id="f_website">
  <input type="hidden" name="core_services" id="f_core_services">
  <input type="hidden" name="competitors"   id="f_competitors">
  <input type="hidden" name="positioning"   id="f_positioning">
  <input type="hidden" name="source"        value="chat">
</form>

<script>
var _sending=false,_collectedFields=null;

function ge(id){return document.getElementById(id);}

function appendMessage(role,text){
  var msgs=ge('chatMessages');
  var div=document.createElement('div');
  div.className='flex '+(role==='user'?'justify-end':'justify-start');
  var bubble=document.createElement('div');
  bubble.className=(role==='user'?'chat-bubble-user':'chat-bubble-ai')+' px-4 py-3 text-sm max-w-xs leading-relaxed whitespace-pre-wrap';
  bubble.textContent=text;
  div.appendChild(bubble);
  msgs.appendChild(div);
  msgs.scrollTop=msgs.scrollHeight;
}

function appendTyping(){
  var msgs=ge('chatMessages');
  var div=document.createElement('div');
  div.id='typingIndicator';div.className='flex justify-start';
  div.innerHTML='<div class="chat-bubble-ai px-4 py-3 text-sm"><span class="animate-pulse">…</span></div>';
  msgs.appendChild(div);
  msgs.scrollTop=msgs.scrollHeight;
}

function removeTyping(){
  var t=ge('typingIndicator');if(t)t.remove();
}

function sendMessage(){
  if(_sending)return;
  var input=ge('chatInput'),msg=input.value.trim();
  if(!msg)return;
  input.value='';
  appendMessage('user',msg);
  _sending=true;ge('sendBtn').disabled=true;
  appendTyping();

  var fd=new FormData();fd.append('action','chat');fd.append('message',msg);
  fetch(window.location.pathname,{method:'POST',body:fd})
  .then(function(r){return r.json();})
  .then(function(d){
    removeTyping();_sending=false;ge('sendBtn').disabled=false;
    if(!d.success){appendMessage('ai','出错了：'+(d.error||'未知错误'));return;}
    // 如果完成，不显示 JSON，显示友好消息
    if(d.completed){
      appendMessage('ai','信息收集完毕！请确认下方信息后点击「开始自动入驻」。');
      showCompleteCard(d.fields);
      ge('inputBar').style.display='none';
    } else {
      appendMessage('ai',d.message);
    }
  })
  .catch(function(err){
    removeTyping();_sending=false;ge('sendBtn').disabled=false;
    appendMessage('ai','网络错误：'+err.message);
  });
}

function showCompleteCard(fields){
  _collectedFields=fields;
  var labels={'brand_name':'品牌名','industry':'行业','website':'官网','core_services':'核心服务','competitors':'竞品','positioning':'定位'};
  var lines=Object.entries(fields).map(function(e){
    return '<div><span class="font-medium text-emerald-900">'+labels[e[0]]+'：</span>'+e[1]+'</div>';
  });
  ge('fieldsSummary').innerHTML=lines.join('');
  ge('completeCard').style.display='block';
  lucide.createIcons();
  ge('chatMessages').scrollTop=ge('chatMessages').scrollHeight;
}

function startOnboarding(){
  if(!_collectedFields)return;
  Object.entries(_collectedFields).forEach(function(e){
    var el=ge('f_'+e[0]);if(el)el.value=e[1];
  });
  ge('startBtn').disabled=true;
  ge('startBtn').innerHTML='<i data-lucide="loader" class="w-4 h-4 animate-spin"></i> 启动中…';
  ge('onboardForm').submit();
}

function resetChat(){
  if(!confirm('重置会清空当前对话，确定吗？'))return;
  var fd=new FormData();fd.append('action','reset');
  fetch(window.location.pathname,{method:'POST',body:fd}).then(function(){location.reload();});
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
