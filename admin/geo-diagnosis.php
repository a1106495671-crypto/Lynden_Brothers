<?php
/**
 * GEO 雷达诊断
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
$currentCustomerIndustry = (string) ($current_customer_context['industry'] ?? '');

$message = '';
$error = '';
$selectedId = trim((string) ($_GET['id'] ?? ''));
$generatedBaselineRows = [];

try {
    geo_diagnosis_ensure_schema($db);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
            throw new RuntimeException('CSRF验证失败');
        }

        $action = (string) ($_POST['action'] ?? 'create_diagnosis');
        if ($action === 'update_weights') {
            $diagnosisId = trim((string) ($_POST['diagnosis_id'] ?? ''));
            $weights = is_array($_POST['weights'] ?? null) ? $_POST['weights'] : [];
            $scores = is_array($_POST['scores'] ?? null) ? $_POST['scores'] : [];
            geo_diagnosis_update_weights($db, $diagnosisId, $weights, $scores);
            admin_redirect('geo-diagnosis.php?id=' . urlencode($diagnosisId));
        } elseif ($action === 'save_baseline_qa') {
            $diagnosisId = trim((string) ($_POST['diagnosis_id'] ?? ''));
            $baselineBrand = clean_input($_POST['brand_name'] ?? '');
            $baselineCustomer = clean_input($_POST['customer_id'] ?? $currentCustomerId);
            $savedCount = geo_baseline_qa_save_for_diagnosis($db, $diagnosisId, $baselineCustomer, $baselineBrand, $_POST);
            $message = '问答基准线已保存 ' . $savedCount . ' 条，并同步为监测关键词';
            $selectedId = $diagnosisId;
        } elseif ($action === 'generate_baseline_qa') {
            $diagnosisId = trim((string) ($_POST['diagnosis_id'] ?? ''));
            $baselineBrand = clean_input($_POST['brand_name'] ?? '');
            $baselineCustomer = clean_input($_POST['customer_id'] ?? $currentCustomerId);
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
                $generatedBaselineRows = [];
            } else {
                $message = (string) ($generated['message'] ?? '未生成首问样本。');
            }
            $selectedId = $diagnosisId;
        } else {
            $diagnosisId = geo_diagnosis_create($db, [
                'brand_name'  => clean_input($_POST['brand_name'] ?? ''),
                'domain'      => clean_input($_POST['domain'] ?? ''),
                'industry'    => clean_input($_POST['industry'] ?? ''),
                'email'       => clean_input($_POST['email'] ?? ''),
                'evidence'    => trim((string) ($_POST['evidence'] ?? '')),
                'customer_id' => clean_input($_POST['customer_id'] ?? $currentCustomerId),
            ]);
            admin_redirect('geo-diagnosis.php?id=' . urlencode($diagnosisId));
        }
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$summary = geo_diagnosis_summary($db);
$dataSourceConfig = geo_diagnosis_data_source_config();
$currentReport = geo_diagnosis_latest($db, $selectedId);
if ($selectedId === '' && ($currentCustomerName !== '' || $currentCustomerDomain !== '')) {
    $customerReport = geo_diagnosis_latest_for_brand($db, $currentCustomerName, $currentCustomerDomain);
    if ($customerReport) {
        $currentReport = $customerReport;
    }
}
$recentReports = geo_diagnosis_recent($db);
$industries = geo_diagnosis_industries();
$baselinePlatforms = geo_baseline_qa_platforms();
$showCreatePanel = !$currentReport || isset($_GET['new']) || ($error !== '' && ($_POST['action'] ?? '') === 'create_diagnosis');
if ($currentReport && $currentCustomerId !== '') {
    geo_baseline_qa_attach_customer($db, (string) $currentReport['id'], $currentCustomerId, (string) ($currentReport['brand_name'] ?? $currentCustomerName));
}
$currentBaselineRows = $currentReport ? geo_baseline_qa_for_diagnosis($db, (string) $currentReport['id']) : [];
$defaultBaselineQuestions = array_fill(0, 10, '');
$page_title = '雷达诊断';

$scoresForChart = [];
$benchmarkForChart = [];
$actionsBySignal = [];
$diagnosisSource = ['key' => 'unknown', 'label' => '未知来源', 'class' => 'bg-gray-100 text-gray-700', 'note' => '暂无诊断来源信息'];
$trendData = ['labels' => [], 'overall' => [], 'hit_rate' => [], 'signals' => [], 'delta' => null];
if ($currentReport) {
    $trendData = geo_diagnosis_history($db, (string) ($currentReport['brand_id'] ?? ''));
    foreach ($currentReport['actions'] as $action) {
        $actionsBySignal[(string) $action['signal_key']][] = $action;
    }
    foreach ($currentReport['scores'] as $score) {
        $rawMetric = json_decode((string) ($score['raw_metric'] ?? '{}'), true);
        $rawMetric = is_array($rawMetric) ? $rawMetric : [];
        $dataSource = (string) ($rawMetric['data_source'] ?? '');
        if ($dataSource === 'real_monitor') {
            $diagnosisSource = [
                'key' => 'real_monitor',
                'label' => '真实 AI 监测数据',
                'class' => 'bg-blue-100 text-blue-700',
                'note' => '引用率和 UGC 覆盖分数来自实际 AI 平台查询记录，非估算。',
            ];
        } elseif (!empty($rawMetric['search_provider']) || $dataSource === 'real_search') {
            $diagnosisSource = [
                'key' => 'real_search',
                'label' => '实时搜索诊断',
                'class' => 'bg-green-100 text-green-700',
                'note' => '已调用搜索 API 扫描第三方提及、UGC 覆盖、权威来源和官网信号。',
            ];
        } elseif ($dataSource === 'site_crawl_estimate' && $diagnosisSource['key'] !== 'real_search') {
            $diagnosisSource = [
                'key' => 'site_crawl_estimate',
                'label' => '官网抓取估算',
                'class' => 'bg-amber-100 text-amber-800',
                'note' => '已抓取官网估算结构、事实密度和站点身份，但第三方声量仍不可验证。',
            ];
        } elseif ($dataSource === 'estimated' && $diagnosisSource['key'] === 'unknown') {
            $diagnosisSource = [
                'key' => 'estimated',
                'label' => '本地估算',
                'class' => 'bg-red-100 text-red-700',
                'note' => '当前分数只基于输入资料和规则估算，不能代表真实全网声量。',
            ];
        }
        $scoresForChart[] = [
            'key' => $score['signal_key'],
            'name' => $score['name'] ?: $score['signal_key'],
            'score' => round((float) $score['score'], 1),
        ];
        $benchmarkForChart[] = round((float) ($score['benchmark_score'] ?? ($currentReport['industry_benchmark'] ?? 70)), 1);
    }
}

$searchProviderLabels = [
    'disabled' => '未启用',
    'bing' => 'Bing Search API',
    'serpapi' => 'SerpAPI',
    'google_cse' => 'Google Custom Search',
    'bocha' => '博查 AI',
];
$searchProviderEnabled = ($dataSourceConfig['provider'] ?? 'disabled') !== 'disabled'
    && !empty($dataSourceConfig['api_key_configured'])
    && (($dataSourceConfig['provider'] ?? '') !== 'google_cse' || trim((string) ($dataSourceConfig['google_cse_id'] ?? '')) !== '');
$searchProviderName = $searchProviderLabels[(string) ($dataSourceConfig['provider'] ?? 'disabled')] ?? (string) ($dataSourceConfig['provider'] ?? 'disabled');
$diagnosisWarning = '';
if ($currentReport && in_array($diagnosisSource['key'], ['estimated', 'site_crawl_estimate'], true)) {
    if ($searchProviderEnabled) {
        $diagnosisWarning = '当前报告仍是旧的估算结果；搜索数据源已启用（' . $searchProviderName . '），点击“重新诊断”后会用搜索 API 重新扫描第三方提及、UGC 覆盖和权威来源。';
    } else {
        $diagnosisWarning = '当前报告基于本地资料和官网抓取估算，第三方声量不可验证。要做真实全网雷达，请先到首页工作台的“雷达数据源配置”启用搜索服务商并保存 API Key。';
    }
}
$rerunBrandName = $currentCustomerName !== '' ? $currentCustomerName : (string) ($currentReport['brand_name'] ?? '');
$rerunDomain = $currentCustomerDomain !== '' ? $currentCustomerDomain : (string) ($currentReport['domain'] ?? '');
$rerunIndustry = $currentCustomerIndustry !== '' ? $currentCustomerIndustry : (string) ($currentReport['industry'] ?? '');

require_once __DIR__ . '/includes/header.php';
?>
            <div class="mb-8">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <h1 class="text-3xl font-bold text-gray-900">雷达诊断</h1>
                        <p class="mt-1 text-sm text-gray-600">品牌 GEO 权威性六维评分与短板诊断</p>
                    </div>
                    <div class="flex items-center gap-3">
                        <?php if ($rerunBrandName !== ''): ?>
                            <form method="POST" class="m-0">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
                                <input type="hidden" name="action" value="create_diagnosis">
                                <input type="hidden" name="customer_id" value="<?php echo htmlspecialchars($currentCustomerId); ?>">
                                <input type="hidden" name="brand_name" value="<?php echo htmlspecialchars($rerunBrandName); ?>">
                                <input type="hidden" name="domain" value="<?php echo htmlspecialchars($rerunDomain); ?>">
                                <input type="hidden" name="industry" value="<?php echo htmlspecialchars($rerunIndustry); ?>">
                                <input type="hidden" name="email" value="">
                                <input type="hidden" name="evidence" value="">
                                <button type="submit" class="inline-flex items-center justify-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50">
                                    <i data-lucide="refresh-cw" class="mr-2 h-4 w-4"></i>
                                    重新诊断
                                </button>
                            </form>
                        <?php else: ?>
                            <a href="<?php echo htmlspecialchars(admin_url('geo-diagnosis.php?new=1')); ?>" class="inline-flex items-center justify-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50">
                                <i data-lucide="refresh-cw" class="mr-2 h-4 w-4"></i>
                                重新诊断
                            </a>
                        <?php endif; ?>
                        <a href="<?php echo htmlspecialchars(admin_url('geo-monitor.php')); ?>" class="inline-flex items-center justify-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50">
                            <i data-lucide="activity" class="mr-2 h-4 w-4"></i>
                            GEO监测
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

            <div class="mb-6 grid grid-cols-1 gap-4 md:grid-cols-4">
                <div class="rounded-lg border border-gray-200 bg-white px-5 py-4 shadow-sm">
                    <div class="text-sm font-medium text-gray-500">总诊断数</div>
                    <div class="mt-2 text-3xl font-bold text-gray-900"><?php echo (int) $summary['total_runs']; ?></div>
                </div>
                <div class="rounded-lg border border-gray-200 bg-white px-5 py-4 shadow-sm">
                    <div class="text-sm font-medium text-gray-500">平均得分</div>
                    <div class="mt-2 text-3xl font-bold text-gray-900"><?php echo htmlspecialchars((string) $summary['avg_score']); ?></div>
                </div>
                <div class="rounded-lg border border-gray-200 bg-white px-5 py-4 shadow-sm">
                    <div class="text-sm font-medium text-gray-500">今日诊断</div>
                    <div class="mt-2 text-3xl font-bold text-gray-900"><?php echo (int) $summary['today_runs']; ?></div>
                </div>
                <div class="rounded-lg border border-gray-200 bg-white px-5 py-4 shadow-sm">
                    <div class="text-sm font-medium text-gray-500">优化动作</div>
                    <div class="mt-2 text-3xl font-bold text-gray-900"><?php echo (int) $summary['total_actions']; ?></div>
                </div>
            </div>

            <div class="grid grid-cols-1 gap-6 <?php echo $showCreatePanel ? 'xl:grid-cols-5' : 'xl:grid-cols-1'; ?>">
                <?php if ($showCreatePanel): ?>
                <section class="rounded-lg border border-gray-200 bg-white shadow-sm xl:col-span-2">
                    <div class="border-b border-gray-200 px-6 py-4">
                        <h2 class="text-lg font-semibold text-gray-900"><?php echo $currentReport ? '重新生成诊断' : '生成第一份诊断'; ?></h2>
                        <?php if ($currentReport): ?>
                            <p class="mt-1 text-sm text-gray-500">使用当前客户资料重新跑一版报告，旧报告会保留在历史记录里。</p>
                        <?php endif; ?>
                    </div>
                    <form method="POST" class="space-y-5 px-6 py-6" id="diagnosis-create-form">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
                        <input type="hidden" name="action" value="create_diagnosis">
                        <input type="hidden" name="customer_id" value="<?php echo htmlspecialchars($currentCustomerId); ?>">
                        <div>
                            <label class="mb-2 block text-sm font-medium text-gray-700" for="brand-name">品牌名称</label>
                            <input id="brand-name" name="brand_name" type="text" value="<?php echo htmlspecialchars($currentCustomerName !== '' ? $currentCustomerName : (string) ($currentReport['brand_name'] ?? '')); ?>" class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500" placeholder="董逻辑">
                        </div>
                        <div>
                            <label class="mb-2 block text-sm font-medium text-gray-700" for="brand-domain">官网域名</label>
                            <input id="brand-domain" name="domain" type="text" value="<?php echo htmlspecialchars($currentCustomerDomain !== '' ? $currentCustomerDomain : (string) ($currentReport['domain'] ?? '')); ?>" class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500" placeholder="example.com">
                        </div>
                        <div>
                            <label class="mb-2 block text-sm font-medium text-gray-700" for="brand-industry">行业</label>
                            <select id="brand-industry" name="industry" class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <?php
                                $selectedIndustryForForm = $currentCustomerIndustry !== '' ? $currentCustomerIndustry : (string) ($currentReport['industry'] ?? '');
                                if (!in_array($selectedIndustryForForm, $industries, true)) {
                                    foreach ($industries as $candidateIndustry) {
                                        if (mb_stripos($selectedIndustryForForm, $candidateIndustry) !== false || mb_stripos($candidateIndustry, $selectedIndustryForForm) !== false) {
                                            $selectedIndustryForForm = $candidateIndustry;
                                            break;
                                        }
                                    }
                                }
                                ?>
                                <?php foreach ($industries as $industry): ?>
                                    <option value="<?php echo htmlspecialchars($industry); ?>" <?php echo $industry === $selectedIndustryForForm ? 'selected' : ''; ?>><?php echo htmlspecialchars($industry); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="mb-2 block text-sm font-medium text-gray-700" for="lead-email">留资邮箱</label>
                            <input id="lead-email" name="email" type="email" class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500" placeholder="name@example.com">
                        </div>
                        <div>
                            <label class="mb-2 block text-sm font-medium text-gray-700" for="evidence">已知资料</label>
                            <textarea id="evidence" name="evidence" rows="5" class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500" placeholder="可粘贴官网简介、媒体报道、平台账号、客户案例、数据点、备案/联系方式等。资料越完整，诊断越接近真实状态。"></textarea>
                        </div>
                        <button type="submit" class="inline-flex w-full items-center justify-center rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-blue-700">
                            <i data-lucide="radar" class="mr-2 h-4 w-4"></i>
                            <?php echo $currentReport ? '重新生成雷达诊断' : '生成雷达诊断'; ?>
                        </button>
                    </form>
                </section>
                <?php endif; ?>

                <section class="rounded-lg border border-gray-200 bg-white shadow-sm <?php echo $showCreatePanel ? 'xl:col-span-3' : ''; ?>">
                    <div class="flex flex-col gap-2 border-b border-gray-200 px-6 py-4 md:flex-row md:items-center md:justify-between">
                        <div>
                            <h2 class="text-lg font-semibold text-gray-900">诊断报告</h2>
                            <?php if ($currentReport): ?>
                                <p class="mt-1 text-sm text-gray-500">
                                    <?php echo htmlspecialchars($currentReport['brand_name']); ?>
                                    <?php if (!empty($currentReport['domain'])): ?>
                                        · <?php echo htmlspecialchars($currentReport['domain']); ?>
                                    <?php endif; ?>
                                </p>
                            <?php endif; ?>
                        </div>
                        <?php if ($currentReport): ?>
                            <div class="flex flex-col items-start gap-2 md:items-end">
                                <span class="rounded-full px-3 py-1 text-xs font-semibold <?php echo htmlspecialchars($diagnosisSource['class']); ?>">
                                    <?php echo htmlspecialchars($diagnosisSource['label']); ?>
                                </span>
                                <div class="text-sm text-gray-500">完成时间：<?php echo htmlspecialchars((string) $currentReport['completed_at']); ?></div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php if (!$currentReport): ?>
                        <div class="flex h-96 items-center justify-center px-6 py-10">
                            <div class="text-center">
                                <i data-lucide="radar" class="mx-auto h-12 w-12 text-gray-400"></i>
                                <p class="mt-3 text-sm font-medium text-gray-600">暂无诊断数据</p>
                                <p class="mt-1 text-xs text-gray-500">填写左侧表单后生成第一份雷达报告。</p>
                            </div>
                        </div>
                    <?php else: ?>
                        <?php if ($diagnosisWarning !== ''): ?>
                            <div class="border-b border-amber-200 bg-amber-50 px-6 py-3 text-sm text-amber-800">
                                <?php echo htmlspecialchars($diagnosisWarning); ?>
                            </div>
                        <?php endif; ?>
	                        <div class="grid grid-cols-1 gap-8 px-6 py-6 xl:grid-cols-2">
	                            <div class="flex min-h-[440px] min-w-0 flex-col">
                                <div class="mb-6 flex items-start justify-between gap-4">
                                    <div>
                                        <div class="text-sm font-medium text-gray-500">
                                            <?php echo $diagnosisSource['key'] === 'real_search' ? '客户内容 AI 权威分' : '本地诊断估算分'; ?>
                                        </div>
                                        <div class="mt-3 flex items-end gap-2">
                                            <span id="overall-score-preview" class="text-6xl font-bold leading-none text-gray-900"><?php echo htmlspecialchars((string) round((float) $currentReport['overall_score'], 1)); ?></span>
                                            <span class="pb-2 text-sm text-gray-500">/ 100</span>
                                        </div>
                                    </div>
                                    <div class="text-right">
                                        <div class="text-sm font-medium text-gray-500">行业 Top 25% 基准</div>
                                        <div class="mt-3 text-3xl font-semibold text-gray-900"><?php echo htmlspecialchars((string) round((float) $currentReport['industry_benchmark'], 1)); ?></div>
                                    </div>
                                </div>
                                <div class="flex flex-1 items-center justify-center">
                                    <div class="h-80 w-full max-w-xl">
                                        <canvas id="geoRadarChart"></canvas>
                                    </div>
                                </div>
                            </div>

	                            <form method="POST" class="flex min-h-[440px] min-w-0 flex-col overflow-hidden" id="weight-form">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
                                <input type="hidden" name="action" value="update_weights">
                                <input type="hidden" name="diagnosis_id" value="<?php echo htmlspecialchars((string) $currentReport['id']); ?>">
                                <div class="mb-6 flex items-start justify-between gap-4">
                                    <div>
                                        <div class="text-sm font-medium text-gray-500">引用命中预测</div>
                                        <div id="hit-rate-preview" class="mt-3 text-3xl font-bold text-gray-900"><?php echo htmlspecialchars(geo_diagnosis_hit_rate_label((string) $currentReport['predicted_hit_rate'])); ?></div>
                                    </div>
                                    <div class="rounded-full bg-gray-100 px-3 py-1 text-xs font-medium text-gray-600">
                                        权重合计 <span id="weight-total">100</span>%
                                    </div>
                                </div>

                                <div class="space-y-5">
                                    <?php foreach ($currentReport['scores'] as $score): ?>
                                        <div class="grid grid-cols-1 gap-2 sm:grid-cols-[90px_minmax(96px,150px)_34px_72px] sm:items-center sm:gap-2 xl:grid-cols-[96px_minmax(120px,170px)_36px_72px]">
                                            <label class="truncate text-sm font-medium text-gray-700" for="score-<?php echo htmlspecialchars($score['signal_key']); ?>"><?php echo htmlspecialchars($score['name'] ?: $score['signal_key']); ?></label>
                                            <input
                                                id="score-<?php echo htmlspecialchars($score['signal_key']); ?>"
                                                type="range"
                                                min="0"
                                                max="100"
                                                step="1"
                                                name="scores[<?php echo htmlspecialchars($score['signal_key']); ?>]"
                                                value="<?php echo htmlspecialchars((string) round((float) $score['score'], 0)); ?>"
                                                data-key="<?php echo htmlspecialchars($score['signal_key']); ?>"
                                                class="score-slider w-full accent-blue-600"
                                            >
                                            <span class="text-right text-sm font-semibold text-gray-900"><span class="score-value" data-key="<?php echo htmlspecialchars($score['signal_key']); ?>"><?php echo htmlspecialchars((string) round((float) $score['score'], 1)); ?></span></span>
                                            <label class="flex shrink-0 items-center justify-end gap-1 text-sm" title="权重占比">
                                                <input
                                                    type="number"
                                                    min="0"
                                                    max="100"
                                                    step="0.1"
                                                    name="weights[<?php echo htmlspecialchars($score['signal_key']); ?>]"
                                                    value="<?php echo htmlspecialchars((string) round(((float) $score['weight']) * 100, 1)); ?>"
                                                    data-default-weight="<?php echo htmlspecialchars((string) round(((float) ($score['default_weight'] ?? $score['weight'])) * 100, 1)); ?>"
                                                    class="weight-input w-14 shrink-0 rounded-md border border-gray-300 px-2 py-1 text-right text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                                                >
                                                <span class="text-xs text-gray-500">%</span>
                                            </label>
                                        </div>
                                    <?php endforeach; ?>
                                </div>

                                <div class="mt-auto pt-6">
                                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                        <button type="button" id="reset-default-weights" class="inline-flex w-full items-center justify-center rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                                            重置为默认比例
                                        </button>
                                        <button type="submit" class="inline-flex w-full items-center justify-center rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700">
                                            保存模拟并重算
                                        </button>
                                    </div>
                                    <p class="mt-2 text-xs text-gray-500">滑杆调整维度当前分，输入框调整权重；六项权重合计需等于 100%。</p>
                                </div>
                            </form>
                        </div>

	                        <div class="border-t border-gray-200 px-6 py-6">
	                            <h3 class="mb-4 text-base font-semibold text-gray-900">六维评分与维度展开</h3>
	                            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
	                                <?php foreach ($currentReport['scores'] as $score): ?>
	                                    <?php $scoreValue = max(0, min(100, (float) $score['score'])); ?>
	                                    <?php $benchmarkValue = max(0, min(100, (float) ($score['benchmark_score'] ?? 70))); ?>
	                                    <?php $gapValue = (float) ($score['benchmark_gap'] ?? ($benchmarkValue - $scoreValue)); ?>
                                        <?php $scoreDetails = json_decode((string) ($score['details_json'] ?? '{}'), true); ?>
                                        <?php $scoreDetails = is_array($scoreDetails) ? $scoreDetails : []; ?>
                                        <?php $dimensionActions = $actionsBySignal[(string) $score['signal_key']] ?? []; ?>
	                                    <details class="rounded-lg border border-gray-200 bg-white px-4 py-4 open:border-blue-200 open:bg-blue-50/30">
	                                        <summary class="cursor-pointer list-none">
                                                <div class="mb-2 flex items-center justify-between gap-3">
                                                    <span class="text-sm font-medium text-gray-800"><?php echo htmlspecialchars($score['name'] ?: $score['signal_key']); ?></span>
                                                    <span class="shrink-0 text-sm font-semibold text-gray-900"><?php echo htmlspecialchars((string) round($scoreValue, 1)); ?> / 基准 <?php echo htmlspecialchars((string) round($benchmarkValue, 1)); ?></span>
                                                </div>
                                                <div class="relative h-2 rounded-full bg-gray-200">
                                                    <div class="h-2 rounded-full bg-blue-600" style="width: <?php echo $scoreValue; ?>%"></div>
                                                    <div class="absolute top-[-3px] h-4 w-px bg-gray-500" style="left: <?php echo $benchmarkValue; ?>%"></div>
                                                </div>
                                                <div class="mt-1 text-xs <?php echo $gapValue > 0 ? 'text-amber-600' : 'text-green-600'; ?>">
                                                    <?php echo $gapValue > 0 ? '低于行业基准 ' . htmlspecialchars((string) round($gapValue, 1)) . ' 分' : '达到或超过行业基准'; ?>
                                                </div>
                                            </summary>
                                            <div class="mt-4 space-y-3 border-t border-gray-200 pt-4 text-sm">
                                                <div>
                                                    <div class="text-xs font-medium text-gray-500">找到的证据来源</div>
                                                    <p class="mt-1 text-gray-700"><?php echo htmlspecialchars((string) ($scoreDetails['hint'] ?? '当前基于品牌资料、官网域名、行业基准和已知平台线索估算；接入搜索 API 后会展示真实 URL 证据。')); ?></p>
                                                    <?php if (!empty($scoreDetails['crawled'])): ?>
                                                        <p class="mt-1 text-xs text-green-700">已抓取官网首页参与估算。</p>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="grid grid-cols-1 gap-2 text-xs text-gray-600 sm:grid-cols-3">
                                                    <div class="rounded-md bg-gray-50 px-3 py-2">当前 <?php echo htmlspecialchars((string) round($scoreValue, 1)); ?> 分</div>
                                                    <div class="rounded-md bg-gray-50 px-3 py-2">行业基准 <?php echo htmlspecialchars((string) round($benchmarkValue, 1)); ?> 分</div>
                                                    <div class="rounded-md <?php echo $gapValue > 0 ? 'bg-amber-50 text-amber-700' : 'bg-green-50 text-green-700'; ?> px-3 py-2"><?php echo $gapValue > 0 ? '差距 ' . htmlspecialchars((string) round($gapValue, 1)) . ' 分' : '已达基准'; ?></div>
                                                </div>
                                                <div>
                                                    <div class="text-xs font-medium text-gray-500">当前分为什么低</div>
                                                    <p class="mt-1 text-gray-700"><?php echo $gapValue > 0 ? '该维度低于行业 Top 25% 基准，说明公开可信语料、结构化表达或平台覆盖仍有补齐空间。' : '该维度已达到或超过行业基准，后续重点是保持更新频率和证据质量。'; ?></p>
                                                </div>
                                                <div>
                                                    <div class="text-xs font-medium text-gray-500">具体行动清单</div>
                                                    <?php if (empty($dimensionActions)): ?>
                                                        <p class="mt-1 text-gray-700">当前不是前三短板，建议维持现有节奏并定期复诊。</p>
                                                    <?php else: ?>
                                                        <div class="mt-2 space-y-2">
                                                            <?php foreach ($dimensionActions as $dimensionAction): ?>
                                                                <div class="rounded-md border border-gray-200 bg-white px-3 py-2">
                                                                    <p class="text-gray-700"><?php echo htmlspecialchars($dimensionAction['action_text']); ?></p>
                                                                    <div class="mt-2 flex flex-wrap gap-2 text-xs">
                                                                        <span class="rounded-full bg-blue-50 px-2 py-1 text-blue-700">服务包：<?php echo htmlspecialchars($dimensionAction['sku_label'] ?? $dimensionAction['sku_id']); ?></span>
                                                                        <span class="rounded-full bg-green-50 px-2 py-1 text-green-700">预计 +<?php echo htmlspecialchars((string) round((float) $dimensionAction['estimated_impact'], 1)); ?> 分</span>
                                                                    </div>
                                                                </div>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
	                                    </details>
	                                <?php endforeach; ?>
	                            </div>
	                        </div>

                        <div class="border-t border-gray-200 px-6 py-6">
                            <h3 class="mb-4 text-base font-semibold text-gray-900">优先优化动作</h3>
                            <div class="space-y-3">
                                <?php foreach ($currentReport['actions'] as $action): ?>
                                    <div class="rounded-lg border border-gray-200 px-4 py-4">
                                        <div class="mb-2 flex items-center justify-between">
                                            <span class="text-sm font-semibold text-gray-900">优先级 <?php echo (int) $action['priority']; ?> · <?php echo htmlspecialchars($action['signal_name'] ?: $action['signal_key']); ?></span>
                                            <span class="text-xs text-gray-500">预计 +<?php echo htmlspecialchars((string) round((float) $action['estimated_impact'], 1)); ?> 分</span>
                                        </div>
                                        <div class="mb-3 grid grid-cols-1 gap-2 text-xs text-gray-600 md:grid-cols-3">
                                            <div class="rounded-md bg-gray-50 px-3 py-2">当前 <?php echo htmlspecialchars((string) round((float) ($action['score'] ?? 0), 1)); ?> 分</div>
                                            <div class="rounded-md bg-gray-50 px-3 py-2">行业基准 <?php echo htmlspecialchars((string) round((float) ($action['benchmark_score'] ?? 0), 1)); ?> 分</div>
                                            <div class="rounded-md <?php echo ((float) ($action['benchmark_gap'] ?? 0)) > 0 ? 'bg-amber-50 text-amber-700' : 'bg-green-50 text-green-700'; ?> px-3 py-2">
                                                <?php echo ((float) ($action['benchmark_gap'] ?? 0)) > 0 ? '差距 ' . htmlspecialchars((string) round((float) $action['benchmark_gap'], 1)) . ' 分' : '已达基准'; ?>
                                            </div>
                                        </div>
                                        <p class="text-sm text-gray-700"><?php echo htmlspecialchars($action['action_text']); ?></p>
                                        <div class="mt-3 inline-flex rounded-full bg-blue-50 px-3 py-1 text-xs font-medium text-blue-700">
                                            对应服务包：<?php echo htmlspecialchars($action['sku_label'] ?? $action['sku_id']); ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
	                </section>
	            </div>

            <?php if ($currentReport): ?>
                <?php
                $baselineEditRows = !empty($generatedBaselineRows) ? $generatedBaselineRows : $currentBaselineRows;
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
                ?>
                <section class="mt-6 rounded-lg border border-blue-200 bg-white shadow-sm">
                    <div class="border-b border-blue-100 bg-blue-50 px-6 py-4">
                        <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                            <div>
                                <h2 class="text-lg font-semibold text-gray-900">首次 AI 问答基准线</h2>
                                <p class="mt-1 text-sm text-gray-600">这组问题会同步到 GEO 监测，用来观察 AI 在品类推荐、排名和竞品比较里是否自然提到我们。</p>
                            </div>
                            <div class="flex flex-wrap gap-2 text-xs font-semibold">
                                <span class="rounded-full bg-white px-3 py-1 text-blue-700">已保存 <?php echo count($currentBaselineRows); ?> 条</span>
                                <span class="rounded-full bg-white px-3 py-1 text-gray-700">首次提及 <?php echo $baselineMentionCount; ?> 条</span>
                                <a href="<?php echo htmlspecialchars(admin_url('geo-monitor.php')); ?>" class="rounded-full bg-slate-900 px-3 py-1 text-white">去监测追踪</a>
                            </div>
                        </div>
                        <form method="POST" class="mt-4 flex flex-col gap-3 rounded-lg border border-blue-100 bg-white px-4 py-3 md:flex-row md:items-center md:justify-between">
                            <div>
                                <div class="text-sm font-semibold text-gray-900">从知识切片生成雷达首问</div>
                                <p class="mt-1 text-xs text-gray-500">读取已切割的知识库片段，整理出客户做品类推荐、排名和竞品比较时最可能拿去问AI的问题；答案由你去真实AI平台提问后手动记录。</p>
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
                            <p class="text-sm text-gray-500">保存后，这些问题会作为客户监测关键词，监测页的“基准线追踪”会展示最新答案变化。</p>
                            <button type="submit" class="inline-flex items-center justify-center rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-blue-700">
                                <i data-lucide="save" class="mr-2 h-4 w-4"></i>
                                保存基准线
                            </button>
                        </div>
                    </form>
                </section>
            <?php endif; ?>

            <?php if ($currentReport): ?>
                <section class="mt-6 rounded-lg border border-gray-200 bg-white shadow-sm">
                    <div class="border-b border-gray-200 px-6 py-4">
                        <div class="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
                            <div>
                                <h2 class="text-lg font-semibold text-gray-900">历史趋势 / 月度对比</h2>
                            </div>
                            <?php if (!empty($trendData['delta'])): ?>
                                <div class="rounded-full bg-gray-100 px-3 py-1 text-xs font-medium text-gray-600">
                                    本次 vs 上次：综合分 <?php echo ((float) $trendData['delta']['overall']) >= 0 ? '+' : ''; ?><?php echo htmlspecialchars((string) $trendData['delta']['overall']); ?>
                                    · 命中率 <?php echo ((int) $trendData['delta']['hit_rate']) >= 0 ? '+' : ''; ?><?php echo htmlspecialchars((string) $trendData['delta']['hit_rate']); ?>%
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="grid grid-cols-1 gap-6 px-6 py-6 lg:grid-cols-2">
                        <div class="rounded-lg border border-gray-200 px-4 py-4">
                            <div class="mb-3 text-sm font-semibold text-gray-900">综合分与 AI 引用命中率趋势</div>
                            <div class="h-72">
                                <canvas id="geoHistoryTrendChart"></canvas>
                            </div>
                        </div>
                        <div class="rounded-lg border border-gray-200 px-4 py-4">
                            <div class="mb-3 text-sm font-semibold text-gray-900">六维分数趋势</div>
                            <div class="h-72">
                                <canvas id="geoSignalTrendChart"></canvas>
                            </div>
                        </div>
                    </div>
                </section>
            <?php endif; ?>

            <section class="mt-6 rounded-lg border border-gray-200 bg-white shadow-sm">
                <div class="border-b border-gray-200 px-6 py-4">
                    <h2 class="text-lg font-semibold text-gray-900">最近诊断</h2>
                </div>
                <?php if (empty($recentReports)): ?>
                    <div class="px-6 py-10 text-center text-sm text-gray-500">暂无记录</div>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">品牌</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">行业</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">得分</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">命中率</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">时间</th>
                                    <th class="px-6 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500">操作</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 bg-white">
                                <?php foreach ($recentReports as $report): ?>
                                    <tr>
                                        <td class="whitespace-nowrap px-6 py-4 text-sm font-medium text-gray-900"><?php echo htmlspecialchars($report['brand_name']); ?></td>
                                        <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-500"><?php echo htmlspecialchars($report['industry']); ?></td>
                                        <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-900"><?php echo htmlspecialchars((string) round((float) $report['overall_score'], 1)); ?></td>
                                        <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-500"><?php echo htmlspecialchars(geo_diagnosis_hit_rate_label((string) $report['predicted_hit_rate'])); ?></td>
                                        <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-500"><?php echo htmlspecialchars((string) $report['created_at']); ?></td>
                                        <td class="whitespace-nowrap px-6 py-4 text-right text-sm">
                                            <a class="text-blue-600 hover:text-blue-800" href="<?php echo htmlspecialchars(admin_url('geo-diagnosis.php?id=' . urlencode($report['id']))); ?>">查看</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>

            <script src="/admin/assets/js/chart.umd.min.js"></script>
            <?php if ($currentReport): ?>
                <script>
                    (function () {
                        const chartEl = document.getElementById('geoRadarChart');
                        if (!chartEl || typeof Chart === 'undefined') return;
	                        const signals = <?php echo json_encode($scoresForChart, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
	                        const benchmark = <?php echo json_encode($benchmarkForChart, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
                            const trendData = <?php echo json_encode($trendData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
	                        const radarChart = new Chart(chartEl, {
                            type: 'radar',
                            data: {
                                labels: signals.map(item => item.name),
                                datasets: [
                                    {
                                        label: '当前得分',
                                        data: signals.map(item => item.score),
                                        backgroundColor: 'rgba(37, 99, 235, 0.16)',
                                        borderColor: '#2563eb',
                                        pointBackgroundColor: '#2563eb',
                                        borderWidth: 2
                                    },
                                    {
                                        label: '行业基准',
                                        data: benchmark,
                                        backgroundColor: 'rgba(107, 114, 128, 0.05)',
                                        borderColor: '#9ca3af',
                                        borderDash: [4, 4],
                                        pointBackgroundColor: '#9ca3af',
                                        borderWidth: 1
                                    }
                                ]
                            },
                            options: {
                                maintainAspectRatio: false,
                                scales: {
                                    r: {
                                        min: 0,
                                        max: 100,
                                        ticks: { stepSize: 25 },
                                        grid: { color: '#e5e7eb' },
                                        angleLines: { color: '#e5e7eb' },
                                        pointLabels: { color: '#374151', font: { size: 12 } }
                                    }
                                },
                                plugins: {
                                    legend: { position: 'bottom' }
                                }
	                            }
		                        });

                            const historyTrendEl = document.getElementById('geoHistoryTrendChart');
                            if (historyTrendEl) {
                                new Chart(historyTrendEl, {
                                    type: 'line',
                                    data: {
                                        labels: trendData.labels || [],
                                        datasets: [
                                            {
                                                label: '综合分',
                                                data: trendData.overall || [],
                                                borderColor: '#2563eb',
                                                backgroundColor: 'rgba(37, 99, 235, 0.12)',
                                                tension: 0.35,
                                                fill: true
                                            },
                                            {
                                                label: 'AI 引用命中率预测',
                                                data: trendData.hit_rate || [],
                                                borderColor: '#16a34a',
                                                backgroundColor: 'rgba(22, 163, 74, 0.08)',
                                                tension: 0.35,
                                                borderDash: [4, 4]
                                            }
                                        ]
                                    },
                                    options: {
                                        maintainAspectRatio: false,
                                        scales: { y: { min: 0, max: 100 } },
                                        plugins: { legend: { position: 'bottom' } }
                                    }
                                });
                            }

                            const signalTrendEl = document.getElementById('geoSignalTrendChart');
                            if (signalTrendEl) {
                                const palette = ['#2563eb', '#7c3aed', '#db2777', '#ea580c', '#0891b2', '#16a34a'];
                                const signalDatasets = Object.entries(trendData.signals || {}).map(([key, item], index) => ({
                                    label: item.name || key,
                                    data: item.data || [],
                                    borderColor: palette[index % palette.length],
                                    backgroundColor: 'transparent',
                                    tension: 0.35
                                }));
                                new Chart(signalTrendEl, {
                                    type: 'line',
                                    data: {
                                        labels: trendData.labels || [],
                                        datasets: signalDatasets
                                    },
                                    options: {
                                        maintainAspectRatio: false,
                                        scales: { y: { min: 0, max: 100 } },
                                        plugins: { legend: { position: 'bottom' } }
                                    }
                                });
                            }

	                            const weightForm = document.getElementById('weight-form');
                            const totalEl = document.getElementById('weight-total');
	                            const weightInputs = Array.from(document.querySelectorAll('.weight-input'));
	                            const scoreSliders = Array.from(document.querySelectorAll('.score-slider'));
	                            const saveButton = weightForm ? weightForm.querySelector('button[type="submit"]') : null;
                                const resetWeightsButton = document.getElementById('reset-default-weights');
                                const overallPreview = document.getElementById('overall-score-preview');
                                const hitRatePreview = document.getElementById('hit-rate-preview');

                                function hitRateLabel(score) {
                                    if (score >= 80) return '高';
                                    if (score >= 60) return '中等';
                                    if (score >= 40) return '低';
                                    return '很低';
                                }

                                function updateOverallPreview() {
                                    let total = 0;
                                    scoreSliders.forEach((slider, index) => {
                                        const score = parseFloat(slider.value) || 0;
                                        const weight = (parseFloat(weightInputs[index]?.value) || 0) / 100;
                                        total += score * weight;
                                    });
                                    const rounded = Math.round(total * 10) / 10;
                                    if (overallPreview) overallPreview.textContent = String(rounded);
                                    if (hitRatePreview) hitRatePreview.textContent = hitRateLabel(rounded);
                                }

	                            function updateWeightTotal() {
	                                const total = weightInputs.reduce((sum, input) => sum + (parseFloat(input.value) || 0), 0);
                                const rounded = Math.round(total * 10) / 10;
                                if (totalEl) {
                                    totalEl.textContent = String(rounded);
                                    totalEl.className = Math.abs(total - 100) <= 0.01 ? 'text-green-600' : 'text-red-600';
                                }
                                if (saveButton) {
                                    saveButton.disabled = Math.abs(total - 100) > 0.01;
                                    saveButton.classList.toggle('opacity-50', saveButton.disabled);
	                                    saveButton.classList.toggle('cursor-not-allowed', saveButton.disabled);
	                                }
                                    updateOverallPreview();
	                            }

	                            weightInputs.forEach(input => input.addEventListener('input', updateWeightTotal));
	                                if (resetWeightsButton) {
	                                    resetWeightsButton.addEventListener('click', () => {
	                                        weightInputs.forEach(input => {
	                                            input.value = input.dataset.defaultWeight || input.defaultValue || input.value;
                                                input.dispatchEvent(new Event('input', { bubbles: true }));
	                                        });
	                                        updateWeightTotal();
                                            resetWeightsButton.textContent = '已重置为默认比例';
                                            window.setTimeout(() => {
                                                resetWeightsButton.textContent = '重置为默认比例';
                                            }, 1200);
	                                    });
	                                }
	                            scoreSliders.forEach((input, index) => {
                                input.addEventListener('input', () => {
                                    const value = parseFloat(input.value) || 0;
                                    const label = document.querySelector(`.score-value[data-key="${input.dataset.key}"]`);
                                    if (label) {
                                        label.textContent = String(Math.round(value * 10) / 10);
                                    }
                                    if (radarChart && radarChart.data.datasets[0]) {
                                        radarChart.data.datasets[0].data[index] = value;
	                                        radarChart.update('none');
	                                    }
                                        updateOverallPreview();
	                                });
	                            });
	                            updateWeightTotal();
	                    })();
	                </script>
            <?php endif; ?>
<?php
require_once __DIR__ . '/includes/footer.php';
?>
