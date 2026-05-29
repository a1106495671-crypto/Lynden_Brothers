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

require_admin_login();

$message = '';
$error = '';
$selectedId = trim((string) ($_GET['id'] ?? ''));

try {
    geo_diagnosis_ensure_schema($db);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
            throw new RuntimeException('CSRF验证失败');
        }

        $action = (string) ($_POST['action'] ?? 'create_diagnosis');
        if ($action === 'save_data_source') {
            if (!geo_diagnosis_save_data_source_config($_POST)) {
                throw new RuntimeException('雷达数据源配置保存失败');
            }
            $message = '雷达数据源配置已保存';
        } elseif ($action === 'update_weights') {
            $diagnosisId = trim((string) ($_POST['diagnosis_id'] ?? ''));
            $weights = is_array($_POST['weights'] ?? null) ? $_POST['weights'] : [];
            $scores = is_array($_POST['scores'] ?? null) ? $_POST['scores'] : [];
            geo_diagnosis_update_weights($db, $diagnosisId, $weights, $scores);
            admin_redirect('geo-diagnosis.php?id=' . urlencode($diagnosisId));
        } else {
            $diagnosisId = geo_diagnosis_create($db, [
                'brand_name'  => clean_input($_POST['brand_name'] ?? ''),
                'domain'      => clean_input($_POST['domain'] ?? ''),
                'industry'    => clean_input($_POST['industry'] ?? ''),
                'email'       => clean_input($_POST['email'] ?? ''),
                'evidence'    => trim((string) ($_POST['evidence'] ?? '')),
                'customer_id' => clean_input($_POST['customer_id'] ?? ($current_customer_context['customer_id'] ?? '')),
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
$recentReports = geo_diagnosis_recent($db);
$industries = geo_diagnosis_industries();
$page_title = '雷达诊断';

// 拉取当前客户的真实监测缺口数据
$monitorGapData = [];
$currentCustomerId = $current_customer_context['customer_id'] ?? '';
if ($currentCustomerId !== '') {
    $brandForGap = $current_customer_context['name'] ?? ($currentReport['brand_name'] ?? '');
    $monitorGapData = geo_diagnosis_monitor_data($db, $currentCustomerId, $brandForGap);
}

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
                'note' => '未配置搜索 API；已抓取官网估算结构、事实密度和站点身份，但第三方声量仍不可验证。',
            ];
        } elseif ($dataSource === 'estimated' && $diagnosisSource['key'] === 'unknown') {
            $diagnosisSource = [
                'key' => 'estimated',
                'label' => '本地估算',
                'class' => 'bg-red-100 text-red-700',
                'note' => '未配置搜索 API，当前分数只基于输入资料和规则估算，不能代表真实全网声量。',
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

require_once __DIR__ . '/includes/header.php';
?>
            <div class="mb-8">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <h1 class="text-3xl font-bold text-gray-900">雷达诊断</h1>
                        <p class="mt-1 text-sm text-gray-600">品牌 GEO 权威性六维评分与短板诊断</p>
                    </div>
                    <div class="flex items-center gap-3">
                        <a href="<?php echo htmlspecialchars(admin_url('geo-diagnosis.php')); ?>" class="inline-flex items-center justify-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50">
                            <i data-lucide="plus" class="mr-2 h-4 w-4"></i>
                            新建诊断
                        </a>
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

            <div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
                <section class="rounded-lg border border-gray-200 bg-white shadow-sm xl:col-span-1">
                    <div class="border-b border-gray-200 px-6 py-4">
                        <h2 class="text-lg font-semibold text-gray-900">新建诊断</h2>
                    </div>
                    <form method="POST" class="space-y-5 px-6 py-6">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
                        <input type="hidden" name="action" value="create_diagnosis">
                        <div>
                            <label class="mb-2 block text-sm font-medium text-gray-700" for="brand-name">品牌名称</label>
                            <input id="brand-name" name="brand_name" type="text" class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500" placeholder="董逻辑">
                        </div>
                        <div>
                            <label class="mb-2 block text-sm font-medium text-gray-700" for="brand-domain">官网域名</label>
                            <input id="brand-domain" name="domain" type="text" class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500" placeholder="example.com">
                        </div>
                        <div>
                            <label class="mb-2 block text-sm font-medium text-gray-700" for="brand-industry">行业</label>
                            <select id="brand-industry" name="industry" class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <?php foreach ($industries as $industry): ?>
                                    <option value="<?php echo htmlspecialchars($industry); ?>"><?php echo htmlspecialchars($industry); ?></option>
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
                            生成雷达诊断
                        </button>
                    </form>
                </section>

                <section class="rounded-lg border border-gray-200 bg-white shadow-sm xl:col-span-2">
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
                        <?php if ($diagnosisSource['key'] !== 'real_search'): ?>
                            <div class="border-b border-amber-200 bg-amber-50 px-6 py-3 text-sm text-amber-800">
                                <?php echo htmlspecialchars($diagnosisSource['note']); ?> 要判断喜茶这类知名品牌的真实 AI 权威度，请在下方“雷达数据源配置”接入 Bing、SerpAPI、Google CSE 或博查搜索 API 后重新生成诊断。
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

            <?php if (!empty($monitorGapData)): ?>
                <section class="mt-6 rounded-lg border border-blue-200 bg-white shadow-sm">
                    <div class="border-b border-blue-100 bg-blue-50 px-6 py-4">
                        <div class="flex items-center justify-between">
                            <div>
                                <h2 class="text-lg font-semibold text-gray-900">真实 AI 引用缺口分析</h2>
                                <p class="mt-0.5 text-sm text-gray-500">基于 <?php echo (int) $monitorGapData['total_records']; ?> 条真实 AI 平台查询记录 · 非品牌词查询 <?php echo (int) $monitorGapData['non_branded_total']; ?> 次</p>
                            </div>
                            <div class="text-right">
                                <div class="text-3xl font-bold <?php echo (float) $monitorGapData['non_branded_rate'] >= 30 ? 'text-green-600' : ((float) $monitorGapData['non_branded_rate'] >= 10 ? 'text-amber-500' : 'text-red-500'); ?>">
                                    <?php echo $monitorGapData['non_branded_rate']; ?>%
                                </div>
                                <div class="text-xs text-gray-500">非品牌词实际引用率</div>
                            </div>
                        </div>
                    </div>
                    <div class="grid grid-cols-1 gap-6 px-6 py-6 md:grid-cols-2">
                        <?php if (!empty($monitorGapData['gap_keywords'])): ?>
                        <div>
                            <h3 class="mb-3 flex items-center gap-2 text-sm font-semibold text-red-700">
                                <span class="inline-block h-2 w-2 rounded-full bg-red-500"></span>
                                待突破关键词（引用率 0%）
                            </h3>
                            <div class="space-y-2">
                                <?php foreach ($monitorGapData['gap_keywords'] as $gapKw): ?>
                                    <div class="flex items-center justify-between rounded-md border border-red-100 bg-red-50 px-3 py-2">
                                        <span class="text-sm text-gray-800"><?php echo htmlspecialchars($gapKw); ?></span>
                                        <span class="rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-600">0%</span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <p class="mt-3 text-xs text-gray-500">这些关键词是你最需要写内容突破的方向，针对每个问题写一篇直接回答的知乎文章。</p>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($monitorGapData['win_keywords'])): ?>
                        <div>
                            <h3 class="mb-3 flex items-center gap-2 text-sm font-semibold text-green-700">
                                <span class="inline-block h-2 w-2 rounded-full bg-green-500"></span>
                                已赢得引用的关键词
                            </h3>
                            <div class="space-y-2">
                                <?php foreach ($monitorGapData['win_keywords'] as $winKw): ?>
                                    <?php
                                    $qd = $monitorGapData['by_query'][$winKw] ?? [];
                                    $winRate = $qd['rate'] ?? 0;
                                    ?>
                                    <div class="flex items-center justify-between rounded-md border border-green-100 bg-green-50 px-3 py-2">
                                        <span class="text-sm text-gray-800"><?php echo htmlspecialchars($winKw); ?></span>
                                        <span class="rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-700"><?php echo (int) $winRate; ?>%</span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <p class="mt-3 text-xs text-gray-500">分析这些内容的格式和发布平台，复制到待突破关键词上。</p>
                        </div>
                        <?php endif; ?>
                        <?php if (empty($monitorGapData['gap_keywords']) && empty($monitorGapData['win_keywords'])): ?>
                        <div class="col-span-2 rounded-md bg-gray-50 px-4 py-6 text-center text-sm text-gray-500">
                            当前监测关键词均为品牌词，建议添加通用 GEO 关键词（如"GEO公司推荐"、"AI搜索引擎优化"）后重新监测。
                        </div>
                        <?php endif; ?>
                    </div>
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
	                    <div class="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
	                        <div>
	                            <h2 class="text-lg font-semibold text-gray-900">雷达数据源配置</h2>
	                        </div>
	                    </div>
                </div>
                <form method="POST" class="px-6 py-6">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
                    <input type="hidden" name="action" value="save_data_source">
                    <div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
                        <div>
                            <label class="mb-2 block text-sm font-medium text-gray-700" for="search-provider">搜索服务商</label>
                            <select id="search-provider" name="provider" class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <?php foreach (['disabled' => '未启用', 'bing' => 'Bing Search API', 'serpapi' => 'SerpAPI', 'google_cse' => 'Google Custom Search', 'bocha' => '博查AI (Bocha)'] as $value => $label): ?>
                                    <option value="<?php echo htmlspecialchars($value); ?>" <?php echo $dataSourceConfig['provider'] === $value ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="mb-2 block text-sm font-medium text-gray-700" for="search-api-key">API Key</label>
                            <input id="search-api-key" name="api_key" type="password" autocomplete="off" class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500" placeholder="<?php echo $dataSourceConfig['api_key_configured'] ? '已保存，留空则不修改' : '粘贴搜索 API Key'; ?>">
                            <?php if ($dataSourceConfig['api_key_configured']): ?>
                                <label class="mt-2 inline-flex items-center text-xs text-gray-500">
                                    <input type="checkbox" name="clear_api_key" value="1" class="mr-2 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                    清除已保存的 API Key
                                </label>
                            <?php endif; ?>
                        </div>
                        <div>
                            <label class="mb-2 block text-sm font-medium text-gray-700" for="google-cse-id">Google CSE ID</label>
                            <input id="google-cse-id" name="google_cse_id" type="text" class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500" value="<?php echo htmlspecialchars($dataSourceConfig['google_cse_id']); ?>" placeholder="仅 Google Custom Search 需要">
                        </div>
                        <div>
                            <label class="mb-2 block text-sm font-medium text-gray-700" for="result-limit">每次搜索结果数</label>
                            <input id="result-limit" name="result_limit" type="number" min="5" max="50" class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500" value="<?php echo (int) $dataSourceConfig['result_limit']; ?>">
                        </div>
                        <div>
                            <label class="mb-2 block text-sm font-medium text-gray-700" for="timeout-seconds">请求超时秒数</label>
                            <input id="timeout-seconds" name="timeout_seconds" type="number" min="3" max="60" class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500" value="<?php echo (int) $dataSourceConfig['timeout_seconds']; ?>">
                        </div>
                        <div class="flex items-end">
                            <label class="inline-flex h-10 items-center rounded-md border border-gray-300 px-3 text-sm text-gray-700">
                                <input type="checkbox" name="enable_site_crawl" value="1" class="mr-2 rounded border-gray-300 text-blue-600 focus:ring-blue-500" <?php echo $dataSourceConfig['enable_site_crawl'] ? 'checked' : ''; ?>>
                                启用官网抓取
                            </label>
                        </div>
                    </div>
                    <div class="mt-5 flex justify-end">
                        <button type="submit" class="inline-flex items-center justify-center rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-blue-700">
                            <i data-lucide="save" class="mr-2 h-4 w-4"></i>
                            保存数据源配置
                        </button>
                    </div>
                </form>
            </section>

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
