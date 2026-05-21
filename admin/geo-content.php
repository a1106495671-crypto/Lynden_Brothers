<?php
/**
 * Step4 内容生成 - 根据策略生成文章并输出
 */
define('FEISHU_TREASURE', true);
session_start();
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/database_admin.php';
require_once __DIR__ . '/../includes/citation_simulator_service.php';
require_admin_login();

function gc_h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

// 获取客户列表
$customers = [];
try {
    $stmt = $db->query("
        SELECT DISTINCT k.customer_id,
               COALESCE((SELECT fact_value FROM geo_brand_facts WHERE customer_id=k.customer_id AND fact_key='brand_name' LIMIT 1), k.customer_id) AS brand_name
        FROM geo_monitor_keywords k ORDER BY brand_name
    ");
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

$selectedCid = trim($_GET['customer'] ?? $_POST['customer'] ?? ($customers[0]['customer_id'] ?? ''));
$action      = $_POST['action'] ?? '';
$article     = '';
$articleTitle = '';
$generateError = '';
$modelUsed   = '';
$pushResult  = null;

// 读取内容提示词列表
$contentPrompts = [];
try {
    $stmtPr = $db->query("SELECT id, name FROM prompts WHERE type='content' ORDER BY id");
    $contentPrompts = $stmtPr->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

// 读取客户品牌信息
$facts = [];
$weakKeywords = [];
if ($selectedCid !== '') {
    $stmtF = $db->prepare("SELECT fact_key, fact_value FROM geo_brand_facts WHERE customer_id = ?");
    $stmtF->execute([$selectedCid]);
    foreach ($stmtF->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $facts[$row['fact_key']] = $row['fact_value'];
    }

    // 失守关键词供快速选择
    $stmtK = $db->prepare("
        SELECT query_text,
               ROUND(100.0 * SUM(CASE WHEN brand_mentioned THEN 1 ELSE 0 END) / COUNT(*), 1) AS mention_rate
        FROM geo_monitor_records
        WHERE customer_id = ? AND queried_at >= CURRENT_DATE - INTERVAL '7 days'
        GROUP BY query_text
        ORDER BY mention_rate ASC LIMIT 15
    ");
    $stmtK->execute([$selectedCid]);
    $weakKeywords = $stmtK->fetchAll(PDO::FETCH_ASSOC);
}

$brandName      = $facts['brand_name']      ?? $selectedCid;
$masterSentence = $facts['master_sentence']  ?? '';
$coreServices   = $facts['core_services']    ?? '';

// 生成文章
if ($action === 'generate' && $selectedCid !== '') {
    $keyword   = trim($_POST['keyword']   ?? '');
    $platform  = trim($_POST['platform']  ?? '知乎');
    $angle     = trim($_POST['angle']     ?? '');
    $wordCount = (int)($_POST['word_count'] ?? 1000);

    if ($keyword !== '') {

        $platformStyle = [
            '知乎'     => '专业深度、有数据支撑、引用来源清晰，适合偏理性读者',
            '今日头条' => '标题抓眼球、开篇直接切重点、段落简短、口语化',
            '搜狐号'   => '专业观点、图文并茂风格、结构清晰',
            '微信公众号' => '有温度、场景化叙述、结尾有行动召唤',
            '小红书'   => '轻松活泼、多用数字和emoji、列表形式、种草感',
        ][$platform] ?? '专业中文内容';

        // 从提示词库读取模板，未配置时使用内置版本
        $promptId = intval($_POST['prompt_id'] ?? 0);
        $promptTemplate = '';
        if ($promptId > 0) {
            try {
                $stmtPT = $db->prepare("SELECT content FROM prompts WHERE id=? AND type='content'");
                $stmtPT->execute([$promptId]);
                $promptTemplate = $stmtPT->fetchColumn() ?: '';
            } catch (Throwable $e) {}
        }
        if ($promptTemplate === '') {
            try {
                $stmtPT = $db->query("SELECT content FROM prompts WHERE type='content' ORDER BY id LIMIT 1");
                $promptTemplate = $stmtPT->fetchColumn() ?: '';
            } catch (Throwable $e) {}
        }
        if ($promptTemplate === '') {
            $promptTemplate = <<<PROMPT
你是专业的GEO内容撰写专家，深度理解AI引用机制。请为品牌【{{BRAND_NAME}}】撰写一篇高AI引用率的GEO优化文章。

## 品牌信息
- 品牌定位：{{MASTER_SENTENCE}}
- 核心服务：{{CORE_SERVICES}}

## 写作要求
- 目标关键词：「{{KEYWORD}}」
- 发布平台：{{PLATFORM}}（{{PLATFORM_STYLE}}）
- 写作角度：{{ANGLE}}
- 字数要求：{{WORD_COUNT}}字左右

## GEO优化核心规则（Princeton KDD 2024研究实证）
1. **引用来源**（AI引用率+40%）：每个核心论点须附具体来源，格式如"据[机构]数据显示"
2. **精确数据**（+37%）：使用精确数字如"转化率提升37%"而非"显著提升"，数据须有出处
3. **权威引语**（+30%）：引用行业专家或机构观点时注明姓名/机构名
4. **答案密度**：每个H2段落首句用40-60字完整回答该段核心问题（AI直接提取引用的最优长度）
5. **结构化格式**：必须包含H2/H3标题、有序/无序列表、至少1个数据对比表格
6. **FAQ结尾**：文末必须附3-5个"常见问题与解答"，每个答案在50字以内
7. **品牌植入**：自然植入品牌名【{{BRAND_NAME}}】3-5次，在核心优势处突出差异化
8. **禁止关键词堆砌**：关键词密度控制在1-2%，堆砌会使AI引用率下降10%

请直接输出完整文章（含标题），不要任何前言和说明。
PROMPT;
        }
        $prompt = str_replace(
            ['{{BRAND_NAME}}', '{{MASTER_SENTENCE}}', '{{CORE_SERVICES}}',
             '{{KEYWORD}}', '{{PLATFORM}}', '{{PLATFORM_STYLE}}', '{{ANGLE}}', '{{WORD_COUNT}}'],
            [$brandName, $masterSentence, $coreServices,
             $keyword, $platform, $platformStyle, $angle, (string)$wordCount],
            $promptTemplate
        );

        require_once __DIR__ . '/../includes/geo_ai_fallback.php';
        $aiResult  = geo_call_ai_with_fallback($prompt, 3000, 0.75);
        $article   = $aiResult['content'] ?? '';
        $modelUsed = $aiResult['model_used'] ?? '';
        if ($article !== '') {
            $lines = explode("\n", $article);
            $articleTitle = ltrim($lines[0] ?? '', '# ');
        }
        if ($article === '') {
            $generateError = $aiResult['error'] ?? '未知错误';
        }
    }
}

// 推送飞书
if ($action === 'push_feishu' && !empty($_POST['article'])) {
    $articleContent = $_POST['article'];
    $pushTitle      = $_POST['push_title'] ?? '内容草稿';
    $pushPlatform   = $_POST['push_platform'] ?? '';

    $stmtFsUrl = $db->prepare("SELECT setting_value FROM site_settings WHERE setting_key = 'feishu_monitor_webhook' LIMIT 1");
    $stmtFsUrl->execute();
    $webhookUrl = trim((string)($stmtFsUrl->fetchColumn() ?: ''));

    if ($webhookUrl !== '') {
        $payload = json_encode([
            'msg_type' => 'post',
            'content'  => [
                'post' => [
                    'zh_cn' => [
                        'title'   => "✍️ 待发布文章｜{$pushPlatform}｜" . date('Y-m-d'),
                        'content' => [
                            [['tag' => 'text', 'text' => "📌 标题：{$pushTitle}\n📤 目标平台：{$pushPlatform}\n\n"]],
                            [['tag' => 'text', 'text' => $articleContent]],
                            [['tag' => 'text', 'text' => "\n\n---\n✅ 审阅后请发布到 {$pushPlatform}"]],
                        ],
                    ],
                ],
            ],
        ], JSON_UNESCAPED_UNICODE);

        $ch = curl_init($webhookUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        ]);
        $r    = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $pushResult = $code === 200 ? 'success' : 'fail';
    } else {
        $pushResult = 'no_webhook';
    }
    $article      = $articleContent;
    $articleTitle = $pushTitle;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<title>内容生成 · Step4</title>
<script src="/admin/assets/js/tailwind.play-cdn.js"></script>
</head>
<body class="bg-gray-50 min-h-screen">
<div class="max-w-
  <?php if ($selectedCid): echo brand_completeness_check($db, $selectedCid); endif; ?>
5xl mx-auto py-10 px-4">

  <div class="mb-6 flex items-center justify-between">
    <div>
      <h1 class="text-2xl font-bold text-gray-900">内容生成</h1>
      <p class="text-gray-500 mt-1">根据策略生成GEO优化文章，推送飞书后手动发布</p>
    </div>
    <a href="geo-strategy.php?customer=<?= gc_h($selectedCid) ?>" class="text-sm text-blue-600 hover:underline">← 返回策略</a>
  </div>

  <?php if ($pushResult !== null): ?>
  <div class="mb-4 p-3 rounded text-sm <?= $pushResult==='success'?'bg-green-50 text-green-700 border border-green-200':'bg-red-50 text-red-700 border border-red-200' ?>">
    <?= $pushResult==='success' ? '✅ 文章草稿已推送到飞书，请在飞书中审阅后发布' : ($pushResult==='no_webhook'?'⚠️ 未配置飞书webhook':'❌ 飞书推送失败') ?>
  </div>
  <?php endif; ?>

  <div class="grid grid-cols-5 gap-6">

    <!-- 左侧：生成表单 -->
    <div class="col-span-2">
      <form method="POST" class="bg-white rounded-lg border border-gray-200 p-5 space-y-4">
        <h2 class="font-semibold text-gray-700">生成参数</h2>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">客户</label>
          <select name="customer" class="w-full border border-gray-300 rounded px-3 py-2 text-sm" onchange="this.form.submit()">
            <?php foreach ($customers as $c): ?>
            <option value="<?= gc_h($c['customer_id']) ?>" <?= $c['customer_id']===$selectedCid?'selected':'' ?>><?= gc_h($c['brand_name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">提示词模板</label>
          <select name="prompt_id" class="w-full border border-gray-300 rounded px-3 py-2 text-sm">
            <?php foreach ($contentPrompts as $cp): ?>
            <option value="<?= $cp['id'] ?>" <?= (intval($_POST['prompt_id'] ?? 0)===$cp['id'])?'selected':'' ?>>
              <?= htmlspecialchars($cp['name']) ?>
            </option>
            <?php endforeach; ?>
            <?php if (empty($contentPrompts)): ?>
            <option value="0">内置 GEO_PROMPT_ENHANCED_V2</option>
            <?php endif; ?>
          </select>
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">目标关键词 <span class="text-red-500">*</span></label>
          <input type="text" name="keyword" value="<?= gc_h($_POST['keyword'] ?? '') ?>" placeholder="如：GEO服务商怎么选" class="w-full border border-gray-300 rounded px-3 py-2 text-sm">
          <?php if (!empty($weakKeywords)): ?>
          <div class="mt-2 space-y-1">
            <p class="text-xs text-gray-400">快速填入失守关键词：</p>
            <?php foreach (array_slice($weakKeywords, 0, 6) as $kw): ?>
            <button type="button" onclick="document.querySelector('[name=keyword]').value='<?= addslashes($kw['query_text']) ?>'"
              class="inline-block mr-1 mb-1 px-2 py-0.5 bg-red-50 text-red-600 text-xs rounded border border-red-100 hover:bg-red-100">
              <?= gc_h($kw['query_text']) ?> (<?= $kw['mention_rate'] ?>%)
            </button>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">发布平台</label>
          <select name="platform" class="w-full border border-gray-300 rounded px-3 py-2 text-sm">
            <?php foreach (['知乎','今日头条','搜狐号','微信公众号','小红书'] as $p): ?>
            <option <?= ($_POST['platform']??'')===$p?'selected':'' ?>><?= $p ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">写作角度</label>
          <input type="text" name="angle" value="<?= gc_h($_POST['angle'] ?? '') ?>" placeholder="如：从甲方视角，选GEO服务商的5个坑" class="w-full border border-gray-300 rounded px-3 py-2 text-sm">
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">字数要求</label>
          <select name="word_count" class="w-full border border-gray-300 rounded px-3 py-2 text-sm">
            <option value="800" <?= ($_POST['word_count']??'')==='800'?'selected':'' ?>>800字（短文）</option>
            <option value="1000" <?= ($_POST['word_count']??'1000')==='1000'?'selected':'' ?>>1000字（标准）</option>
            <option value="1500" <?= ($_POST['word_count']??'')==='1500'?'selected':'' ?>>1500字（深度）</option>
            <option value="2000" <?= ($_POST['word_count']??'')==='2000'?'selected':'' ?>>2000字（长文）</option>
          </select>
        </div>

        <button type="submit" name="action" value="generate" class="w-full py-2 bg-blue-600 text-white text-sm font-medium rounded hover:bg-blue-700">
          🤖 生成文章
        </button>
      </form>
    </div>

    <!-- 右侧：文章内容 -->
    <div class="col-span-3">
      <?php if ($article !== ''): ?>
      <div class="bg-white rounded-lg border border-gray-200 p-5">
        <div class="flex items-center justify-between mb-3">
          <h2 class="font-semibold text-gray-700">生成结果</h2>
          <span class="text-xs text-gray-400"><?= mb_strlen($article) ?> 字</span>
          <?php if ($modelUsed): ?>
          <span class="text-xs bg-blue-50 text-blue-600 px-2 py-0.5 rounded-full border border-blue-100">🤖 <?= htmlspecialchars($modelUsed) ?></span>
          <?php endif; ?>
        </div>
        <textarea id="articleContent" class="w-full text-sm font-mono text-gray-700 border border-gray-200 rounded p-3 bg-gray-50" rows="22"><?= gc_h($article) ?></textarea>

        <div class="mt-4 flex gap-3">
          <form method="POST" class="flex-1">
            <input type="hidden" name="customer" value="<?= gc_h($selectedCid) ?>">
            <input type="hidden" name="action" value="push_feishu">
            <input type="hidden" name="article" id="pushArticle" value="<?= gc_h($article) ?>">
            <input type="hidden" name="push_title" value="<?= gc_h($articleTitle) ?>">
            <input type="hidden" name="push_platform" value="<?= gc_h($_POST['platform'] ?? '知乎') ?>">
            <button type="submit" class="w-full py-2 bg-green-600 text-white text-sm rounded hover:bg-green-700">
              推送到飞书审阅
            </button>
          </form>
          <button onclick="navigator.clipboard.writeText(document.getElementById('articleContent').value)" class="px-4 py-2 bg-gray-200 text-gray-700 text-sm rounded hover:bg-gray-300">
            复制全文
          </button>
        </div>
      </div>
      <?php else: ?>
      <div class="bg-white rounded-lg border border-gray-200 p-10 text-center text-gray-400">
        <div class="text-4xl mb-3">✍️</div>
        <p class="text-sm">填写左侧参数，点击「生成文章」</p>
        <p class="text-xs mt-1">失守关键词直接点击快速填入</p>
      </div>
      <?php endif; ?>
    </div>

  </div>
</div>
</body>
</html>
