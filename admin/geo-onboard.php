<?php
/**
 * Step1 品牌入驻 - 自动化入库
 */
define('FEISHU_TREASURE', true);
session_start();
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/database_admin.php';
require_once __DIR__ . '/../includes/geo_diagnosis_service.php';
require_once __DIR__ . '/../includes/citation_simulator_service.php';
require_admin_login();

function ob_h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$result  = null;
$errors  = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $brandName      = trim($_POST['brand_name']      ?? '');
    $customerId     = trim($_POST['customer_id']     ?? '');
    $masterSentence = trim($_POST['master_sentence'] ?? '');
    $industry       = trim($_POST['industry']        ?? '');
    $domain         = trim($_POST['domain']          ?? '');
    $coreServices   = trim($_POST['core_services']   ?? '');
    $contactName    = trim($_POST['contact_name']    ?? '');
    $contactPhone   = trim($_POST['contact_phone']   ?? '');
    $rawCompetitors = trim($_POST['competitors']     ?? '');
    $rawKeywords    = trim($_POST['keywords']        ?? '');

    // 验证
    if ($brandName === '')    $errors[] = '品牌名称不能为空';
    if ($customerId === '')   $errors[] = '客户ID不能为空';
    if ($masterSentence==='') $errors[] = '母句不能为空';

    // 清洗
    $customerId = preg_replace('/[^a-z0-9\-]/', '', strtolower($customerId));
    $competitors = array_values(array_filter(array_map('trim', explode("\n", $rawCompetitors))));
    $keywords    = array_values(array_filter(array_map('trim', explode("\n", $rawKeywords))));

    if (empty($keywords)) $errors[] = '监测关键词至少填1条';

    if (empty($errors)) {
        $db->beginTransaction();
        try {
            // 1. 写 geo_brand_facts
            $stmtFact = $db->prepare("INSERT INTO geo_brand_facts (customer_id, fact_key, fact_value, fact_label) VALUES (?, ?, ?, ?) ON CONFLICT (customer_id, fact_key) DO UPDATE SET fact_value = EXCLUDED.fact_value");
            $facts = [
                ['brand_name',      $brandName,      '品牌名称'],
                ['master_sentence', $masterSentence, '母句定位'],
                ['industry',        $industry,       '所属行业'],
                ['domain',          $domain,         '官网域名'],
                ['core_services',   $coreServices,   '核心服务'],
                ['contact_name',    $contactName,    '联系人'],
                ['contact_phone',   $contactPhone,   '联系方式'],
            ];
            foreach ($facts as [$key, $val, $label]) {
                if ($val !== '') $stmtFact->execute([$customerId, $key, $val, $label]);
            }

            // 2. 写竞品
            $db->prepare("DELETE FROM geo_customer_competitors WHERE customer_id = ?")->execute([$customerId]);
            $stmtComp = $db->prepare("INSERT INTO geo_customer_competitors (customer_id, competitor, enabled) VALUES (?, ?, TRUE) ON CONFLICT DO NOTHING");
            foreach ($competitors as $comp) {
                $stmtComp->execute([$customerId, $comp]);
            }

            // 3. 写监测关键词
            $db->prepare("UPDATE geo_monitor_keywords SET enabled = FALSE WHERE customer_id = ?")->execute([$customerId]);
            $stmtKw = $db->prepare("INSERT INTO geo_monitor_keywords (customer_id, keyword, enabled) VALUES (?, ?, TRUE) ON CONFLICT (customer_id, keyword) DO UPDATE SET enabled = TRUE");
            foreach ($keywords as $kw) {
                $stmtKw->execute([$customerId, $kw]);
            }

            // 4. 自动创建首次诊断
            $diagId = null;
            try {
                $diagId = geo_diagnosis_create($db, [
                    'brand_name' => $brandName,
                    'domain'     => $domain,
                    'industry'   => $industry ?: 'B2B SaaS',
                    'email'      => '',
                    'evidence'   => $masterSentence . ($coreServices ? '。核心服务：' . $coreServices : ''),
                ]);
            } catch (Throwable $e) {
                // 诊断失败不影响入库
            }

            $db->commit();

            // 5. 生成 Obsidian Markdown
            $mdLines = ["# {$brandName} · 品牌简报", ""];
            $mdLines[] = "## 基本信息";
            $mdLines[] = "| 字段 | 内容 |";
            $mdLines[] = "|------|------|";
            $mdLines[] = "| 品牌名称 | {$brandName} |";
            $mdLines[] = "| 客户ID | `{$customerId}` |";
            $mdLines[] = "| 所属行业 | {$industry} |";
            $mdLines[] = "| 官网域名 | {$domain} |";
            $mdLines[] = "| 联系人 | {$contactName} {$contactPhone} |";
            $mdLines[] = "";
            $mdLines[] = "## 母句";
            $mdLines[] = "> {$masterSentence}";
            $mdLines[] = "";
            $mdLines[] = "## 核心服务";
            $mdLines[] = $coreServices;
            $mdLines[] = "";
            if (!empty($competitors)) {
                $mdLines[] = "## 竞品";
                foreach ($competitors as $c) $mdLines[] = "- {$c}";
                $mdLines[] = "";
            }
            $mdLines[] = "## 监测关键词（" . count($keywords) . "条）";
            foreach ($keywords as $i => $kw) $mdLines[] = ($i+1) . ". {$kw}";
            $mdLines[] = "";
            $mdLines[] = "## 系统信息";
            $mdLines[] = "- 监测后台：http://1.14.206.128/dl-console/geo-monitor.php?customer={$customerId}";
            if ($diagId) $mdLines[] = "- 诊断报告：http://1.14.206.128/dl-console/geo-diagnosis.php?id={$diagId}";
            $mdLines[] = "- 入库时间：" . date('Y-m-d H:i:s');

            $mdContent = implode("\n", $mdLines);

            $success = true;
            $result  = compact('customerId','brandName','diagId','mdContent','keywords','competitors');

        } catch (Throwable $e) {
            $db->rollBack();
            $errors[] = '数据库写入失败：' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>品牌入驻 · Step1</title>
<script src="/admin/assets/js/tailwind.play-cdn.js"></script>
</head>
<body class="bg-gray-50 min-h-screen">
<div class="max-w-3xl mx-auto py-10 px-4">

  <div class="mb-8">
    <h1 class="text-2xl font-bold text-gray-900">品牌入驻</h1>
    <p class="text-gray-500 mt-1">填写完成后自动写入数据库，创建监测关键词和首次诊断</p>
  </div>

<?php if ($success && $result): ?>
  <div class="bg-green-50 border border-green-200 rounded-lg p-6 mb-6">
    <h2 class="text-lg font-semibold text-green-800 mb-3">✅ 入驻完成：<?= ob_h($result['brandName']) ?></h2>
    <div class="text-sm text-green-700 space-y-1">
      <p>客户ID：<code class="bg-green-100 px-1 rounded"><?= ob_h($result['customerId']) ?></code></p>
      <p>监测关键词：<?= count($result['keywords']) ?> 条</p>
      <p>竞品：<?= count($result['competitors']) ?> 个</p>
      <?php if ($result['diagId']): ?>
      <p>诊断报告：<a href="geo-diagnosis.php?id=<?= ob_h($result['diagId']) ?>" class="underline" target="_blank">查看雷达图</a></p>
      <?php endif; ?>
    </div>
    <div class="mt-4">
      <p class="text-sm font-medium text-green-800 mb-2">Obsidian 品牌简报（复制到 .md 文件）：</p>
      <textarea rows="16" class="w-full text-xs font-mono bg-white border border-green-200 rounded p-3 text-gray-700"><?= ob_h($result['mdContent']) ?></textarea>
    </div>
    <div class="mt-4 flex gap-3">
      <a href="geo-monitor.php?customer=<?= ob_h($result['customerId']) ?>" class="inline-flex items-center px-4 py-2 bg-blue-600 text-white text-sm rounded hover:bg-blue-700">开始监测</a>
      <a href="geo-onboard.php" class="inline-flex items-center px-4 py-2 bg-gray-200 text-gray-700 text-sm rounded hover:bg-gray-300">新建入驻</a>
    </div>
  </div>

<?php else: ?>

  <?php if (!empty($errors)): ?>
  <div class="bg-red-50 border border-red-200 rounded-lg p-4 mb-6">
    <?php foreach ($errors as $e): ?>
    <p class="text-sm text-red-700">❌ <?= ob_h($e) ?></p>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <form method="POST" class="bg-white rounded-lg shadow-sm border border-gray-200 divide-y divide-gray-100">

    <div class="p-6 space-y-4">
      <h2 class="font-semibold text-gray-700">基本信息</h2>
      <div class="grid grid-cols-2 gap-4">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">品牌名称 <span class="text-red-500">*</span></label>
          <input type="text" name="brand_name" value="<?= ob_h($_POST['brand_name'] ?? '') ?>" placeholder="如：董逻辑MGEO" class="w-full border border-gray-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">客户ID <span class="text-red-500">*</span></label>
          <input type="text" name="customer_id" value="<?= ob_h($_POST['customer_id'] ?? '') ?>" placeholder="如：dongluoji-mgeo（英文+连字符）" class="w-full border border-gray-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">所属行业</label>
          <input type="text" name="industry" value="<?= ob_h($_POST['industry'] ?? '') ?>" placeholder="如：B2B SaaS / GEO服务" class="w-full border border-gray-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">官网域名</label>
          <input type="text" name="domain" value="<?= ob_h($_POST['domain'] ?? '') ?>" placeholder="如：mgeo.dongluoji.com" class="w-full border border-gray-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">联系人</label>
          <input type="text" name="contact_name" value="<?= ob_h($_POST['contact_name'] ?? '') ?>" placeholder="姓名" class="w-full border border-gray-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">联系方式</label>
          <input type="text" name="contact_phone" value="<?= ob_h($_POST['contact_phone'] ?? '') ?>" placeholder="手机/邮箱" class="w-full border border-gray-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
        </div>
      </div>
    </div>

    <div class="p-6 space-y-4">
      <h2 class="font-semibold text-gray-700">品牌定位</h2>
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">母句 <span class="text-red-500">*</span></label>
        <textarea name="master_sentence" rows="2" placeholder="一句话定义品牌，如：专注GEO生成式引擎优化的服务商，帮助品牌在各AI平台持续被引用和推荐" class="w-full border border-gray-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"><?= ob_h($_POST['master_sentence'] ?? '') ?></textarea>
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">核心服务</label>
        <textarea name="core_services" rows="3" placeholder="每行一项，如：&#10;AI品牌入口诊断&#10;GEO监测与问题宇宙梳理&#10;30天验证项目&#10;季度陪跑" class="w-full border border-gray-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"><?= ob_h($_POST['core_services'] ?? '') ?></textarea>
      </div>
    </div>

    <div class="p-6 space-y-4">
      <h2 class="font-semibold text-gray-700">竞品 & 关键词</h2>
      <div class="grid grid-cols-2 gap-4">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">竞品名称（每行一个，最多5个）</label>
          <textarea name="competitors" rows="5" placeholder="泛GEO服务商&#10;内容代发机构&#10;包效果黑盒型公司" class="w-full border border-gray-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"><?= ob_h($_POST['competitors'] ?? '') ?></textarea>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">监测关键词（每行一条）<span class="text-red-500">*</span></label>
          <textarea name="keywords" rows="5" placeholder="GEO是什么&#10;GEO和SEO有什么区别&#10;GEO服务商怎么选&#10;AI搜索结果可以优化吗" class="w-full border border-gray-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"><?= ob_h($_POST['keywords'] ?? '') ?></textarea>
        </div>
      </div>
    </div>

    <div class="p-6 bg-gray-50 flex items-center justify-between">
      <p class="text-sm text-gray-500">提交后自动：写入品牌数据库 · 创建监测关键词 · 生成首次诊断 · 输出Obsidian简报</p>
      <button type="submit" class="px-6 py-2 bg-blue-600 text-white text-sm font-medium rounded hover:bg-blue-700 focus:outline-none">立即入驻 →</button>
    </div>

  </form>
<?php endif; ?>
</div>
</body>
</html>
