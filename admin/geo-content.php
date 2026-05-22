<?php
/**
 * GEO内容生成 - 按关键词一键生成 GEO 优化文章
 */

define('FEISHU_TREASURE', true);
session_start();

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database_admin.php';
require_once __DIR__ . '/../includes/functions.php';

require_admin_login();
session_write_close();

// ── AJAX: 生成文章 ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'generate') {
    header('Content-Type: application/json; charset=utf-8');

    $keyword  = trim($_POST['keyword']  ?? '');
    $customer = trim($_POST['customer'] ?? '');
    $angle    = trim($_POST['angle']    ?? '全面介绍');
    $platform = trim($_POST['platform'] ?? '通用');
    $fmt      = trim($_POST['fmt']      ?? '知识科普');

    if (!$keyword) { echo json_encode(['error' => '关键词不能为空']); exit; }

    // 读取品牌信息
    $brandName = $customer;
    $masterSentence = '';
    $coreServices = '';
    $differentiator = '';
    try {
        $sf = $db->prepare("SELECT fact_key, fact_value FROM geo_brand_facts WHERE customer_id = ?");
        $sf->execute([$customer]);
        $facts = [];
        foreach ($sf->fetchAll(PDO::FETCH_ASSOC) as $r) $facts[$r['fact_key']] = $r['fact_value'];
        $brandName      = $facts['brand_name']      ?? $customer;
        $masterSentence = $facts['master_sentence']  ?? '';
        $coreServices   = $facts['core_service']     ?? $facts['core_services'] ?? '';
        $differentiator = $facts['differentiator']   ?? '';
    } catch (Throwable $e) {}

    $platformStyle = [
        '知乎'     => '专业深度、有数据支撑、适合偏理性读者',
        '微信公众号' => '温和亲切、适合分享转发',
        '小红书'   => '轻松活泼、有个人体验感',
        '今日头条' => '口语化、接地气、有故事感',
        '通用'     => '专业中文内容，结构清晰',
    ][$platform] ?? '专业中文内容';

    $brandBlock = '';
    if ($brandName || $masterSentence || $coreServices) {
        $brandBlock = "## 品牌信息\n";
        if ($brandName)       $brandBlock .= "- 品牌名称：{$brandName}\n";
        if ($masterSentence)  $brandBlock .= "- 品牌定位：{$masterSentence}\n";
        if ($coreServices)    $brandBlock .= "- 核心服务：{$coreServices}\n";
        if ($differentiator)  $brandBlock .= "- 差异化优势：{$differentiator}\n";
        $brandBlock .= "\n";
    }

    $prompt = <<<PROMPT
你是专业的GEO（生成式引擎优化）内容撰写专家，专门撰写能被 AI 大模型引用的结构化文章。

{$brandBlock}## 本篇任务
- 目标关键词：「{$keyword}」
- 文章角度：{$angle}
- 内容格式：{$fmt}
- 发布平台：{$platform}（{$platformStyle}）

## GEO 优化要求
1. 标题直接包含目标关键词，以问答或"是什么/怎么做/为什么"句式呈现
2. 第一段直接给出核心答案，不铺垫
3. 包含至少 3 个可被 AI 引用的具体数据或事实（注明来源）
4. 包含 1 个 FAQ 板块（至少 3 个 Q&A）
5. 使用结构化格式（二级标题分节），字数 1000–1500 字
6. 结尾有明确行动召唤
PROMPT;

    if ($brandName && $brandName !== $customer) {
        $prompt .= "\n7. 自然植入品牌名【{$brandName}】至少 3 次";
    }

    $prompt .= "\n\n请直接输出完整文章（含标题），不要任何前言和说明。";

    require_once __DIR__ . '/../includes/geo_ai_fallback.php';
    $result = geo_call_ai_with_fallback($prompt, 3000, 0.72);

    if (!empty($result['error']) && empty($result['content'])) {
        echo json_encode(['error' => 'AI 调用失败：' . $result['error']]);
        exit;
    }

    $content = $result['content'] ?? '';
    $lines   = explode("\n", $content);
    $title   = ltrim(trim($lines[0] ?? $keyword), '# ');

    echo json_encode([
        'ok'         => true,
        'title'      => $title,
        'content'    => $content,
        'model_used' => $result['model_used'] ?? '',
    ]);
    exit;
}

// ── AJAX: 保存草稿 ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    header('Content-Type: application/json; charset=utf-8');

    $keyword  = trim($_POST['keyword']  ?? '');
    $title    = trim($_POST['title']    ?? '');
    $content  = trim($_POST['content']  ?? '');

    if (!$title || !$content) { echo json_encode(['error' => '标题和内容不能为空']); exit; }

    // 取第一个作者和分类兜底
    $author_id   = (int) ($db->query("SELECT id FROM authors ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
    $category_id = (int) ($db->query("SELECT id FROM categories ORDER BY id LIMIT 1")->fetchColumn() ?: 0);

    $slug    = generate_unique_article_slug($db, $title);
    $excerpt = mb_substr(strip_tags($content), 0, 200, 'UTF-8');

    $stmt = $db->prepare("
        INSERT INTO articles
            (title, slug, content, excerpt, original_keyword, status, review_status,
             is_ai_generated, author_id, category_id, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, 'draft', 'pending', 1, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
    ");
    $ok = $stmt->execute([$title, $slug, $content, $excerpt, $keyword, $author_id ?: null, $category_id ?: null]);

    if (!$ok) { echo json_encode(['error' => '保存失败']); exit; }
    $newId = db_last_insert_id($db, 'articles');
    echo json_encode(['ok' => true, 'id' => $newId, 'redirect' => "article-edit.php?id={$newId}"]);
    exit;
}

// ── GET: 渲染页面 ─────────────────────────────────────────────────────────────
$customer = trim($_GET['customer'] ?? '');
$keyword  = trim($_GET['keyword']  ?? '');

if (!$keyword) {
    header('Location: articles.php');
    exit;
}

// 已有文章
$stmt = $db->prepare("
    SELECT a.id, a.title, a.status, a.review_status, a.original_keyword, a.created_at
    FROM articles a
    WHERE a.deleted_at IS NULL
      AND (a.original_keyword = ? OR a.title LIKE ? OR a.keywords LIKE ?)
    ORDER BY a.created_at DESC
    LIMIT 20
");
$like = '%' . $keyword . '%';
$stmt->execute([$keyword, $like, $like]);
$existing = $stmt->fetchAll();

function gc_status_badge(string $s, string $r): string {
    if ($s === 'published')  return '<span class="px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-700">已发布</span>';
    if ($r === 'rejected')   return '<span class="px-2 py-0.5 rounded text-xs font-medium bg-red-100 text-red-700">已拒绝</span>';
    if ($r === 'pending')    return '<span class="px-2 py-0.5 rounded text-xs font-medium bg-yellow-100 text-yellow-700">待审核</span>';
    return '<span class="px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-600">草稿</span>';
}

$kwE         = htmlspecialchars($keyword,  ENT_QUOTES, 'UTF-8');
$customerE   = htmlspecialchars($customer, ENT_QUOTES, 'UTF-8');
$page_title  = 'AI生成 · ' . $kwE;
$page_header = '
<div class="flex items-center space-x-4">
    <a href="javascript:history.back()" class="text-gray-400 hover:text-gray-600">
        <i data-lucide="arrow-left" class="w-5 h-5"></i>
    </a>
    <div>
        <h1 class="text-2xl font-bold text-gray-900">内容生成</h1>
        <p class="mt-1 text-sm text-gray-600">' . $kwE . '</p>
    </div>
</div>';

require_once __DIR__ . '/includes/header.php';
?>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

  <!-- 左：生成表单 + 结果 -->
  <div class="lg:col-span-2 space-y-6">

    <!-- 生成配置卡 -->
    <div class="bg-white shadow rounded-lg">
      <div class="px-6 py-4 border-b border-gray-200">
        <h3 class="text-base font-medium text-gray-900 flex items-center gap-2">
          <i data-lucide="zap" class="w-4 h-4 text-blue-500"></i>AI 一键生成
        </h3>
      </div>
      <div class="px-6 py-5 space-y-4">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">目标关键词</label>
          <div class="flex items-center gap-2 px-3 py-2 bg-blue-50 border border-blue-200 rounded-md text-sm text-blue-800 font-medium">
            <i data-lucide="target" class="w-4 h-4 shrink-0"></i>
            <?php echo $kwE; ?>
          </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">文章角度</label>
            <select id="angle" class="block w-full border-gray-300 rounded-md shadow-sm text-sm focus:ring-blue-500 focus:border-blue-500">
              <option value="是什么 · 全面解析">是什么 · 全面解析</option>
              <option value="怎么做 · 实操指南">怎么做 · 实操指南</option>
              <option value="为什么 · 原因深度">为什么 · 原因深度</option>
              <option value="对比分析 · 竞品横评">对比分析 · 竞品横评</option>
              <option value="案例展示 · 成功故事">案例展示 · 成功故事</option>
              <option value="常见误区 · 纠偏指南">常见误区 · 纠偏指南</option>
            </select>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">内容格式</label>
            <select id="fmt" class="block w-full border-gray-300 rounded-md shadow-sm text-sm focus:ring-blue-500 focus:border-blue-500">
              <option value="知识科普">知识科普</option>
              <option value="问答式">问答式</option>
              <option value="案例展示">案例展示</option>
              <option value="对比分析">对比分析</option>
              <option value="列表总结">列表总结</option>
            </select>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">目标平台</label>
            <select id="platform" class="block w-full border-gray-300 rounded-md shadow-sm text-sm focus:ring-blue-500 focus:border-blue-500">
              <option value="通用">通用</option>
              <option value="知乎">知乎</option>
              <option value="微信公众号">微信公众号</option>
              <option value="小红书">小红书</option>
              <option value="今日头条">今日头条</option>
            </select>
          </div>
        </div>

        <div class="flex items-center gap-3 pt-1">
          <button id="genBtn" onclick="startGenerate()"
            class="inline-flex items-center px-5 py-2.5 border border-transparent text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed">
            <i data-lucide="zap" class="w-4 h-4 mr-2"></i>开始生成
          </button>
          <span id="genStatus" class="text-sm text-gray-500 hidden">
            <svg class="inline w-4 h-4 mr-1 animate-spin text-blue-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"></path>
            </svg>
            AI 生成中，请稍候…
          </span>
          <span id="genError" class="text-sm text-red-600 hidden"></span>
        </div>
      </div>
    </div>

    <!-- 生成结果卡（隐藏，生成后显示） -->
    <div id="resultCard" class="bg-white shadow rounded-lg hidden">
      <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
        <h3 class="text-base font-medium text-gray-900">生成结果</h3>
        <span id="modelUsed" class="text-xs text-gray-400"></span>
      </div>
      <div class="px-6 py-5 space-y-4">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">标题</label>
          <input id="resultTitle" type="text"
            class="block w-full border-gray-300 rounded-md shadow-sm text-sm focus:ring-blue-500 focus:border-blue-500">
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">正文（Markdown）</label>
          <textarea id="resultContent" rows="18"
            class="block w-full border-gray-300 rounded-md shadow-sm text-sm font-mono focus:ring-blue-500 focus:border-blue-500"></textarea>
        </div>
        <div class="flex items-center gap-3">
          <button onclick="saveDraft()"
            class="inline-flex items-center px-5 py-2.5 border border-transparent text-sm font-medium rounded-md text-white bg-green-600 hover:bg-green-700">
            <i data-lucide="save" class="w-4 h-4 mr-2"></i>保存为草稿并进入编辑
          </button>
          <button onclick="startGenerate()"
            class="inline-flex items-center px-4 py-2.5 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
            <i data-lucide="refresh-cw" class="w-4 h-4 mr-2"></i>重新生成
          </button>
          <span id="saveStatus" class="text-sm text-gray-500"></span>
        </div>
      </div>
    </div>

  </div>

  <!-- 右：已有文章 -->
  <div class="space-y-6">
    <div class="bg-white shadow rounded-lg">
      <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
        <h3 class="text-sm font-medium text-gray-900">已有相关文章</h3>
        <span class="text-xs text-gray-400"><?php echo count($existing); ?> 篇</span>
      </div>
      <?php if (empty($existing)): ?>
        <div class="px-6 py-8 text-center text-sm text-gray-400">
          <i data-lucide="file-x" class="w-8 h-8 mx-auto mb-2 text-gray-200"></i>
          暂无匹配文章
        </div>
      <?php else: ?>
        <div class="divide-y divide-gray-100">
          <?php foreach ($existing as $art): ?>
            <div class="px-4 py-3">
              <a href="article-view.php?id=<?php echo (int) $art['id']; ?>"
                 class="text-sm font-medium text-gray-800 hover:text-blue-600 line-clamp-2 block leading-snug">
                <?php echo htmlspecialchars($art['title'], ENT_QUOTES, 'UTF-8'); ?>
              </a>
              <div class="mt-1.5 flex items-center gap-2 text-xs text-gray-400 flex-wrap">
                <?php echo gc_status_badge($art['status'], $art['review_status']); ?>
                <span><?php echo date('m-d', strtotime($art['created_at'])); ?></span>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
        <div class="px-4 py-3 border-t border-gray-100">
          <a href="articles.php?search=<?php echo urlencode($keyword); ?>"
             class="text-xs text-blue-600 hover:underline">在文章管理中查看全部 →</a>
        </div>
      <?php endif; ?>
    </div>
  </div>

</div>

<script>
const KEYWORD  = <?php echo json_encode($keyword); ?>;
const CUSTOMER = <?php echo json_encode($customer); ?>;
const ADMIN_PATH = <?php echo json_encode(ADMIN_BASE_PATH); ?>;

function startGenerate() {
  const btn       = document.getElementById('genBtn');
  const status    = document.getElementById('genStatus');
  const errEl     = document.getElementById('genError');
  const resultCard = document.getElementById('resultCard');

  btn.disabled = true;
  status.classList.remove('hidden');
  errEl.classList.add('hidden');

  const body = new URLSearchParams({
    action:   'generate',
    keyword:  KEYWORD,
    customer: CUSTOMER,
    angle:    document.getElementById('angle').value,
    fmt:      document.getElementById('fmt').value,
    platform: document.getElementById('platform').value,
  });

  fetch(ADMIN_PATH + '/geo-content.php', { method: 'POST', body })
    .then(r => r.json())
    .then(data => {
      btn.disabled = false;
      status.classList.add('hidden');
      if (data.error) {
        errEl.textContent = data.error;
        errEl.classList.remove('hidden');
        return;
      }
      document.getElementById('resultTitle').value   = data.title   || '';
      document.getElementById('resultContent').value = data.content || '';
      document.getElementById('modelUsed').textContent = data.model_used ? '模型：' + data.model_used : '';
      resultCard.classList.remove('hidden');
      resultCard.scrollIntoView({ behavior: 'smooth', block: 'start' });
    })
    .catch(e => {
      btn.disabled = false;
      status.classList.add('hidden');
      errEl.textContent = '请求失败，请重试';
      errEl.classList.remove('hidden');
    });
}

function saveDraft() {
  const title   = document.getElementById('resultTitle').value.trim();
  const content = document.getElementById('resultContent').value.trim();
  const saveStatus = document.getElementById('saveStatus');

  if (!title || !content) { alert('标题和内容不能为空'); return; }

  saveStatus.textContent = '保存中…';

  const body = new URLSearchParams({
    action:  'save',
    keyword: KEYWORD,
    title,
    content,
  });

  fetch(ADMIN_PATH + '/geo-content.php', { method: 'POST', body })
    .then(r => r.json())
    .then(data => {
      if (data.error) { saveStatus.textContent = '保存失败：' + data.error; return; }
      window.location.href = ADMIN_PATH + '/' + data.redirect;
    })
    .catch(() => { saveStatus.textContent = '保存失败，请重试'; });
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
