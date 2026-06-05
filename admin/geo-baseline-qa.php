<?php
/**
 * GEO 首次 AI 问答基准线
 */

define('FEISHU_TREASURE', true);
session_start();

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/database_admin.php';
require_once __DIR__ . '/../includes/geo_diagnosis_service.php';
require_once __DIR__ . '/../includes/geo_baseline_qa_service.php';

require_admin_login();

$current_customer_context = is_array($_SESSION['current_customer'] ?? null) ? $_SESSION['current_customer'] : [];
$currentCustomerId = (string) ($current_customer_context['customer_id'] ?? $current_customer_context['id'] ?? '');
$currentCustomerName = (string) ($current_customer_context['name'] ?? '');
$currentCustomerDomain = (string) ($current_customer_context['domain'] ?? '');

$message = '';
$error = '';
$selectedId = trim((string) ($_GET['id'] ?? $_POST['diagnosis_id'] ?? ''));

try {
    geo_diagnosis_ensure_schema($db);
    geo_baseline_qa_ensure_schema($db);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
            throw new RuntimeException('CSRF验证失败');
        }

        $action = (string) ($_POST['action'] ?? '');
        $diagnosisId = trim((string) ($_POST['diagnosis_id'] ?? ''));
        $baselineBrand = clean_input($_POST['brand_name'] ?? '');
        $baselineCustomer = clean_input($_POST['customer_id'] ?? $currentCustomerId);

        if ($action === 'save_baseline_qa') {
            $savedCount = geo_baseline_qa_save_for_diagnosis($db, $diagnosisId, $baselineCustomer, $baselineBrand, $_POST);
            $message = '问答基准线已保存 ' . $savedCount . ' 条，并同步为监测关键词';
            $selectedId = $diagnosisId;
        } elseif ($action === 'generate_baseline_qa') {
            $baselineIndustry = clean_input($_POST['industry'] ?? '');
            $generated = geo_baseline_qa_generate_from_chunks($db, $baselineCustomer, $baselineBrand, $baselineIndustry, 10);
            $generatedBaselineRows = $generated['rows'] ?? [];
            if (!empty($generatedBaselineRows)) {
                $saveInput = [
                    'baseline_question' => array_map(static fn (array $row): string => (string) ($row['question'] ?? ''), $generatedBaselineRows),
                    'baseline_answer' => array_fill(0, count($generatedBaselineRows), ''),
                    'baseline_platform' => array_map(static fn (array $row): string => (string) ($row['platform'] ?? 'deepseek'), $generatedBaselineRows),
                ];
                $savedCount = geo_baseline_qa_save_for_diagnosis($db, $diagnosisId, $baselineCustomer, $baselineBrand, $saveInput);
                $message = (string) ($generated['message'] ?? '已生成首问样本。') . ' 已写入问题栏 ' . $savedCount . ' 条，答案留空，请复制问题去真实AI平台提问后再补充保存。';
            } else {
                $message = (string) ($generated['message'] ?? '未生成首问样本。');
            }
            $selectedId = $diagnosisId;
        }
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$currentReport = geo_diagnosis_latest($db, $selectedId);
if ($selectedId === '' && ($currentCustomerName !== '' || $currentCustomerDomain !== '')) {
    $customerReport = geo_diagnosis_latest_for_brand($db, $currentCustomerName, $currentCustomerDomain);
    if ($customerReport) {
        $currentReport = $customerReport;
    }
}

if ($currentReport && $currentCustomerId !== '') {
    geo_baseline_qa_attach_customer($db, (string) $currentReport['id'], $currentCustomerId, (string) ($currentReport['brand_name'] ?? $currentCustomerName));
}

$baselinePlatforms = geo_baseline_qa_platforms();
$currentBaselineRows = $currentReport ? geo_baseline_qa_for_diagnosis($db, (string) $currentReport['id']) : [];
$baselineEditRows = $currentBaselineRows;
if (empty($baselineEditRows)) {
    $questionsForCurrentBrand = array_fill(0, 10, '');
    $baselineEditRows = array_map(static function ($question, $index) {
        return [
            'question' => $question,
            'platform' => 'deepseek',
            'baseline_answer' => '',
            'sort_order' => $index + 1,
        ];
    }, $questionsForCurrentBrand, array_keys($questionsForCurrentBrand));
}

$baselineMentionCount = 0;
foreach ($currentBaselineRows as $baselineRow) {
    if (!empty($baselineRow['mention_brand'])) {
        $baselineMentionCount++;
    }
}

$page_title = '首次 AI 问答基准线';

require_once __DIR__ . '/includes/header.php';
?>
            <div class="mb-8">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <h1 class="text-3xl font-bold text-gray-900">首次 AI 问答基准线</h1>
                        <p class="mt-1 text-sm text-gray-600">生成首问样本，记录第一次真实 AI 回答，并同步到 GEO 监测追踪。</p>
                    </div>
                    <div class="flex flex-wrap gap-3">
                        <?php if ($currentReport): ?>
                            <a href="<?php echo htmlspecialchars(admin_url('geo-diagnosis.php?id=' . urlencode((string) $currentReport['id']))); ?>" class="inline-flex items-center justify-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50">
                                <i data-lucide="arrow-left" class="mr-2 h-4 w-4"></i>
                                返回诊断
                            </a>
                        <?php endif; ?>
                        <a href="<?php echo htmlspecialchars(admin_url('geo-monitor.php')); ?>" class="inline-flex items-center justify-center rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-slate-800">
                            <i data-lucide="activity" class="mr-2 h-4 w-4"></i>
                            去监测追踪
                        </a>
                    </div>
                </div>
            </div>

            <?php if ($error !== ''): ?>
                <div class="mb-6 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <?php if ($message !== ''): ?>
                <div class="mb-6 rounded-md border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <?php if (!$currentReport): ?>
                <section class="rounded-lg border border-gray-200 bg-white px-6 py-12 text-center shadow-sm">
                    <h2 class="text-lg font-semibold text-gray-900">还没有可填写的诊断报告</h2>
                    <p class="mt-2 text-sm text-gray-600">先完成一次雷达诊断，再来维护首次 AI 问答基准线。</p>
                    <a href="<?php echo htmlspecialchars(admin_url('geo-diagnosis.php')); ?>" class="mt-5 inline-flex items-center justify-center rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-blue-700">
                        <i data-lucide="radar" class="mr-2 h-4 w-4"></i>
                        去雷达诊断
                    </a>
                </section>
            <?php else: ?>
                <section class="rounded-lg border border-blue-200 bg-white shadow-sm">
                    <div class="border-b border-blue-100 bg-blue-50 px-6 py-5">
                        <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                            <div>
                                <h2 class="text-lg font-semibold text-gray-900"><?php echo htmlspecialchars((string) $currentReport['brand_name']); ?></h2>
                                <p class="mt-1 text-sm text-gray-600">这些问题会作为客户监测关键词，监测页的“基准线追踪”会展示最新答案变化。</p>
                            </div>
                            <div class="flex flex-wrap gap-2 text-xs font-semibold">
                                <span class="rounded-full bg-white px-3 py-1 text-blue-700">已保存 <?php echo count($currentBaselineRows); ?> 条</span>
                                <span class="rounded-full bg-white px-3 py-1 text-gray-700">首次提及 <?php echo $baselineMentionCount; ?> 条</span>
                            </div>
                        </div>
                        <form method="POST" class="mt-4 flex flex-col gap-3 rounded-lg border border-blue-100 bg-white px-4 py-3 md:flex-row md:items-center md:justify-between">
                            <div>
                                <div class="text-sm font-semibold text-gray-900">从知识切片生成雷达首问</div>
                                <p class="mt-1 text-xs text-gray-500">读取已切割的知识库片段，整理出客户做品类推荐、排名和竞品比较时最可能拿去问 AI 的问题。</p>
                            </div>
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
                            <input type="hidden" name="action" value="generate_baseline_qa">
                            <input type="hidden" name="diagnosis_id" value="<?php echo htmlspecialchars((string) $currentReport['id']); ?>">
                            <input type="hidden" name="brand_name" value="<?php echo htmlspecialchars((string) $currentReport['brand_name']); ?>">
                            <input type="hidden" name="customer_id" value="<?php echo htmlspecialchars($currentCustomerId); ?>">
                            <input type="hidden" name="industry" value="<?php echo htmlspecialchars((string) ($currentReport['industry'] ?? '')); ?>">
                            <button type="submit" class="inline-flex shrink-0 items-center justify-center rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-slate-800">
                                <i data-lucide="wand-sparkles" class="mr-2 h-4 w-4"></i>
                                生成首问样本
                            </button>
                        </form>
                    </div>
                    <form method="POST" class="px-6 py-6">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
                        <input type="hidden" name="action" value="save_baseline_qa">
                        <input type="hidden" name="diagnosis_id" value="<?php echo htmlspecialchars((string) $currentReport['id']); ?>">
                        <input type="hidden" name="brand_name" value="<?php echo htmlspecialchars((string) $currentReport['brand_name']); ?>">
                        <input type="hidden" name="customer_id" value="<?php echo htmlspecialchars($currentCustomerId); ?>">
                        <div class="space-y-3">
                            <?php foreach ($baselineEditRows as $idx => $row): ?>
                                <div class="rounded-lg border border-gray-200 bg-white px-4 py-4 shadow-sm">
                                    <div class="mb-3 flex items-center justify-between gap-3">
                                        <div class="flex items-center gap-3">
                                            <span class="inline-flex h-8 w-8 items-center justify-center rounded-md border border-blue-100 bg-blue-50 text-sm font-bold text-blue-700"><?php echo (int) $idx + 1; ?></span>
                                            <span class="text-sm font-semibold text-gray-900">首问样本</span>
                                        </div>
                                        <select name="baseline_platform[]" class="h-9 w-36 rounded-md border border-gray-300 bg-white px-3 text-sm font-medium text-gray-800 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">
                                            <?php foreach ($baselinePlatforms as $platformKey => $platformLabel): ?>
                                                <option value="<?php echo htmlspecialchars($platformKey); ?>" <?php echo (string) ($row['platform'] ?? '') === $platformKey ? 'selected' : ''; ?>><?php echo htmlspecialchars($platformLabel); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="grid grid-cols-1 gap-4 xl:grid-cols-[minmax(320px,0.95fr)_minmax(560px,1.45fr)]">
                                        <label class="block">
                                            <span class="mb-1.5 block text-xs font-semibold text-gray-500">问题</span>
                                            <textarea name="baseline_question[]" rows="3" class="block min-h-[104px] w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm leading-6 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500" placeholder="粘贴你手动问 AI 的问题"><?php echo htmlspecialchars((string) ($row['question'] ?? '')); ?></textarea>
                                        </label>
                                        <label class="block">
                                            <span class="mb-1.5 block text-xs font-semibold text-gray-500">首次答案</span>
                                            <textarea name="baseline_answer[]" rows="3" class="block min-h-[104px] w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm leading-6 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500" placeholder="粘贴第一次手动问到的原始答案"><?php echo htmlspecialchars((string) ($row['baseline_answer'] ?? '')); ?></textarea>
                                        </label>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <p class="text-sm text-gray-500">保存后会同步到 GEO 监测，用于比较首次答案和后续复测答案。</p>
                            <button type="submit" class="inline-flex items-center justify-center rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-blue-700">
                                <i data-lucide="save" class="mr-2 h-4 w-4"></i>
                                保存基准线
                            </button>
                        </div>
                    </form>
                </section>
            <?php endif; ?>
<?php
require_once __DIR__ . '/includes/footer.php';
?>
