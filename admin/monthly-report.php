<?php
/**
 * GEO 月度服务报告 - 可打印 / 导出 PDF
 */

define('FEISHU_TREASURE', true);
session_start();

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/database_admin.php';

require_admin_login();

$currentCustomer = $_SESSION['current_customer'] ?? [];
$customerId = trim((string) ($_GET['customer'] ?? $_GET['customer_id'] ?? ($currentCustomer['id'] ?? $currentCustomer['customer_id'] ?? '')));
try {
    if ($customerId === '') {
        $customerId = (string) $db->query("SELECT customer_id FROM customers ORDER BY updated_at DESC, id DESC LIMIT 1")->fetchColumn();
    }
    if ($customerId !== '') {
        $stmtCustomer = $db->prepare("SELECT * FROM customers WHERE customer_id = ? LIMIT 1");
        $stmtCustomer->execute([$customerId]);
        $dbCustomer = $stmtCustomer->fetch(PDO::FETCH_ASSOC);
        if ($dbCustomer) {
            $currentCustomer = array_merge($currentCustomer, [
                'id' => $dbCustomer['customer_id'],
                'customer_id' => $dbCustomer['customer_id'],
                'name' => $dbCustomer['name'],
                'domain' => $dbCustomer['domain'],
                'industry' => $dbCustomer['industry'],
                'contract_start_at' => $dbCustomer['contract_start_date'],
                'contract_end_at' => $dbCustomer['contract_end_date'],
                'owner' => $dbCustomer['owner'],
            ]);
        }
    }
} catch (Throwable $_customerLoad) {}

$brandName              = $currentCustomer['name'] ?? ($customerId !== '' ? $customerId : '未选择客户');
$industry               = $currentCustomer['industry'] ?? '';
$competitorsFromCustomer = $currentCustomer['competitors'] ?? [];
$contractEndAt          = $currentCustomer['contract_end_at'] ?? '';
$serviceStartAt         = $currentCustomer['contract_start_at'] ?? '';
$ownerName              = $currentCustomer['owner'] ?? '客户成功';

$cityName     = trim(($currentCustomer['cities'][0] ?? '') ?: '本地');
$industryLabel = trim(explode('/', $industry ?: '行业')[0]);

// Load competitors from DB (authoritative), fall back to session data
$competitorsFromDb = [];
try {
    $stmtCmpLoad = $db->prepare("SELECT competitor FROM geo_customer_competitors WHERE customer_id = ? AND enabled = TRUE ORDER BY id ASC");
    $stmtCmpLoad->execute([$customerId]);
    $competitorsFromDb = $stmtCmpLoad->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $_cml) {}
$competitorsList = !empty($competitorsFromDb) ? $competitorsFromDb : $competitorsFromCustomer;
$comp0 = $competitorsList[0] ?? '竞品A';
$comp1 = $competitorsList[1] ?? '竞品B';

// ── 真实监测数据（最近 90 天）──────────────────────────────────────────────
$realByDate  = [];
$realTotal   = 0;
$realMentioned = 0;
try {
    $stmtR = $db->prepare("
        SELECT queried_at::text AS day,
               COUNT(*) AS total,
               COUNT(*) FILTER (WHERE brand_mentioned = TRUE) AS mentioned
        FROM geo_monitor_records
        WHERE customer_id = ?
          AND queried_at >= CURRENT_DATE - INTERVAL '89 days'
        GROUP BY queried_at ORDER BY queried_at ASC
    ");
    $stmtR->execute([$customerId]);
    foreach ($stmtR->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $realByDate[$row['day']] = ['total' => (int)$row['total'], 'mentioned' => (int)$row['mentioned']];
        $realTotal     += (int)$row['total'];
        $realMentioned += (int)$row['mentioned'];
    }
} catch (Throwable $_re) {}
$hasRealData = $realTotal > 0;

// ── 各平台真实统计（最近30天）─────────────────────────────────────────────
$reportPlatformStats = [];
try {
    $stmtPR = $db->prepare("
        SELECT provider,
               COUNT(*) AS total,
               COUNT(*) FILTER (WHERE brand_mentioned = TRUE) AS mentioned,
               COUNT(*) FILTER (WHERE mention_depth >= 2) AS deep_hits
        FROM geo_monitor_records
        WHERE customer_id = ? AND queried_at >= CURRENT_DATE - INTERVAL '29 days'
        GROUP BY provider
    ");
    $stmtPR->execute([$customerId]);
    foreach ($stmtPR->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $t = (int)$row['total'];
        $reportPlatformStats[$row['provider']] = [
            'total'        => $t,
            'mentioned'    => (int)$row['mentioned'],
            'mention_rate' => $t > 0 ? round((int)$row['mentioned'] / $t * 100) : 0,
            'deep_hits'    => (int)$row['deep_hits'],
        ];
    }
} catch (Throwable $_pre) {}

// ── 竞品真实对比数据（最近30天）──────────────────────────────────────────────
$competitorStats    = [];
$brandMentioned30   = 0;
$brandTotal30       = 0;
try {
    $stmtB30 = $db->prepare("
        SELECT COUNT(*) AS total,
               COUNT(*) FILTER (WHERE brand_mentioned = TRUE) AS mentioned
        FROM geo_monitor_records
        WHERE customer_id = ? AND queried_at >= CURRENT_DATE - INTERVAL '29 days'
    ");
    $stmtB30->execute([$customerId]);
    $b30 = $stmtB30->fetch(PDO::FETCH_ASSOC);
    $brandTotal30     = (int)($b30['total']     ?? 0);
    $brandMentioned30 = (int)($b30['mentioned'] ?? 0);

    if ($brandTotal30 > 0) {
        $stmtCS = $db->prepare("
            SELECT elem->>'name' AS comp_name,
                   COUNT(*)      AS records_appeared,
                   SUM((elem->>'count')::int) AS total_appearances
            FROM geo_monitor_records r,
                 jsonb_array_elements(r.competitors_found::jsonb) AS elem
            WHERE r.customer_id = ?
              AND r.queried_at >= CURRENT_DATE - INTERVAL '29 days'
              AND r.competitors_found IS NOT NULL
              AND r.competitors_found != '[]'
            GROUP BY elem->>'name'
            ORDER BY records_appeared DESC
        ");
        $stmtCS->execute([$customerId]);
        foreach ($stmtCS->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $competitorStats[$row['comp_name']] = [
                'records_appeared'  => (int)$row['records_appeared'],
                'total_appearances' => (int)$row['total_appearances'],
                'mention_rate'      => round((int)$row['records_appeared'] / $brandTotal30 * 100),
            ];
        }
    }
} catch (Throwable $_cse) {}

// ── 趋势计算：只使用真实监测数据；没有数据时保持空图和明确提示 ────────────────
$trendPoints = [];
$endDate = new DateTimeImmutable('today');
if ($hasRealData) {
    $lastRate = null;
    $filled = [];
    for ($i = 89; $i >= 0; $i--) {
        $d = $endDate->modify("-{$i} days")->format('Y-m-d');
        if (isset($realByDate[$d])) {
            $row = $realByDate[$d];
            $lastRate = $row['total'] > 0 ? round($row['mentioned'] / $row['total'] * 100) : $lastRate;
        }
        $filled[$d] = $lastRate;
    }
    $first = null;
    foreach ($filled as $v) { if ($v !== null) { $first = $v; break; } }
    foreach ($filled as $d => $v) { if ($v === null) $filled[$d] = $first ?? 0; }
    foreach ($filled as $d => $rate) {
        $trendPoints[] = ['date' => $d, 'brand' => (int)$rate];
    }
}

$latestBrandRate = $trendPoints ? (int) end($trendPoints)['brand'] : 0;
// 行业均值：从诊断行业基准表读，无数据时显示 null（不展示假数字）
$latestIndustryRate = null;
try {
    $stmtBench = $db->prepare("
        SELECT ROUND(AVG(mention_rate_p50)) AS bench
        FROM geo_diagnosis_industry_benchmarks
        WHERE industry_key = (
            SELECT COALESCE(fact_value, 'general')
            FROM geo_brand_facts WHERE customer_id=? AND fact_key='industry' LIMIT 1
        )
    ");
    $stmtBench->execute([$customerId]);
    $benchVal = $stmtBench->fetchColumn();
    if ($benchVal !== false && $benchVal !== null) {
        $latestIndustryRate = (int)$benchVal;
    }
} catch (Throwable $_b) {}

$brandRiseTotal = count($trendPoints) > 1 ? ($latestBrandRate - $trendPoints[0]['brand']) : 0;
reset($trendPoints);

// 引用来源数：从真实监测记录中统计已命中的平台数
$sourceCurrent = $hasRealData ? count($reportPlatformStats) : 0;

// 核心词跌幅：从真实告警读，无则不展示
$fallPp = null;
try {
    $stmtFall = $db->prepare("
        SELECT ABS(brand_rate - competitor_rate) AS pp
        FROM geo_monitor_alerts
        WHERE customer_id=? AND alert_type='core_rate_low'
          AND alerted_at >= CURRENT_DATE - INTERVAL '30 days'
        ORDER BY alerted_at DESC LIMIT 1
    ");
    $stmtFall->execute([$customerId]);
    $fallVal = $stmtFall->fetchColumn();
    if ($fallVal !== false) $fallPp = round((float)$fallVal, 1);
} catch (Throwable $_f) {}

// ── AI 对话记录：只展示真实监测回答，不再回退假对话 ────────────────────────
$convProviderMap = ['kimi'=>'Kimi','deepseek'=>'DeepSeek','tongyi'=>'通义','wenxin'=>'文心','doubao'=>'豆包','yuanbao'=>'元宝'];
$conversations = [];
if ($hasRealData) {
    try {
        $stmtConv = $db->prepare("
            SELECT provider, query_text, full_response, queried_at::text AS day
            FROM geo_monitor_records
            WHERE customer_id = ? AND brand_mentioned = TRUE
            ORDER BY queried_at DESC, id DESC LIMIT 3
        ");
        $stmtConv->execute([$customerId]);
        foreach ($stmtConv->fetchAll(PDO::FETCH_ASSOC) as $rec) {
            $conversations[] = [
                'date'      => substr($rec['day'], 0, 10),
                'question'  => $rec['query_text'],
                'platform'  => $convProviderMap[$rec['provider']] ?? ucfirst($rec['provider']),
                'answer'    => mb_substr($rec['full_response'] ?? '', 0, 400),
                'citations' => [],
            ];
        }
    } catch (Throwable $_ce) {}
}

$renewalItems = [
    ['label' => '可见率提升', 'value' => ($hasRealData ? $trendPoints[0]['brand'] . '% → ' . $latestBrandRate . '%' : '监测数据积累中'), 'delta' => $hasRealData ? (($brandRiseTotal >= 0 ? '+' : '') . $brandRiseTotal . 'pp') : '--', 'green' => $brandRiseTotal > 0],
    ['label' => '行业均值对比', 'value' => ($latestIndustryRate !== null ? $latestBrandRate . '% vs ' . $latestIndustryRate . '%（行业）' : '行业基准待建立'), 'delta' => $latestIndustryRate !== null ? ($latestBrandRate >= $latestIndustryRate ? '+' . ($latestBrandRate - $latestIndustryRate) . 'pp' : (string)($latestBrandRate - $latestIndustryRate) . 'pp') : '--', 'green' => $latestIndustryRate !== null && $latestBrandRate >= $latestIndustryRate],
    ['label' => 'AI对话证据', 'value' => count($conversations) . ' 条', 'delta' => '含原话与来源', 'green' => count($conversations) > 0],
    ['label' => '已覆盖AI平台', 'value' => $sourceCurrent . ' / 6 个', 'delta' => '目标覆盖全部6平台', 'green' => $sourceCurrent >= 5],
];

// 从真实告警读取 topAlerts
$topAlerts = [];
try {
    $stmtTA = $db->prepare("
        SELECT alert_type, level, detail, alerted_at
        FROM geo_monitor_alerts
        WHERE customer_id=? AND alerted_at >= CURRENT_DATE - INTERVAL '30 days'
        ORDER BY CASE level WHEN 'high' THEN 1 WHEN 'medium' THEN 2 ELSE 3 END, alerted_at DESC
        LIMIT 5
    ");
    $stmtTA->execute([$customerId]);
    foreach ($stmtTA->fetchAll(PDO::FETCH_ASSOC) as $ta) {
        $typeLabel = match($ta['alert_type']) {
            'competitor_surpass' => '竞品超越',
            'core_rate_low'      => '核心率下跌',
            'accuracy_low'       => '语义偏差',
            default              => $ta['alert_type'],
        };
        $topAlerts[] = [
            'level'  => strtoupper($ta['level']),
            'title'  => "[{$typeLabel}] " . mb_substr($ta['detail'], 0, 60),
            'action' => '已记录，请按SOP处理',
        ];
    }
} catch (Throwable $_ta) {}
if (empty($topAlerts)) {
    $topAlerts = [['level' => 'INFO', 'title' => '近30天无告警记录', 'action' => '持续监测中']];
}

// 下月行动项：从真实告警和平台覆盖推导
$nextActions = [];
if (!$hasRealData) {
    $nextActions[] = '先完成一次GEO监测：当前月报没有真实监测记录，无法计算趋势和AI对话证据';
}
if ($sourceCurrent < 6) {
    $nextActions[] = '补全AI平台覆盖：当前 ' . $sourceCurrent . '/6 个平台有效提及，目标覆盖全部6个';
}
if (!empty($competitorsList)) {
    $nextActions[] = '竞品压制专项：针对「' . implode('、', array_slice($competitorsList, 0, 2)) . '」补充对比内容和第三方背书';
}
if ($hasRealData && $latestBrandRate < 80) {
    $nextActions[] = '核心信息呈现率提升：当前 ' . $latestBrandRate . '%，目标突破80%达标线';
}
$nextActions[] = '按月度SOP节点完成监测数据核查与存档';
if (empty($nextActions)) {
    $nextActions[] = '维持现有内容更新节奏，持续监测6大AI平台提及率';
}

$reportDate = date('Y年m月d日');
$reportPeriod = date('Y年m月');
?>
<!DOCTYPE html>
<html lang="zh-Hans">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($brandName); ?> GEO 月度服务报告 · <?php echo $reportPeriod; ?></title>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(admin_url('../assets/css/tailwind.css')); ?>">
    <style>
        @media print {
            .no-print { display: none !important; }
            body { background: white !important; }
            .page-break { page-break-before: always; }
            .print-card { box-shadow: none !important; border: 1px solid #e5e7eb !important; }
        }
        @page { margin: 1.5cm 2cm; size: A4; }
    </style>
</head>
<body class="bg-gray-50 font-sans text-gray-900 antialiased">

<!-- Print controls -->
<div class="no-print sticky top-0 z-50 border-b border-gray-200 bg-white px-6 py-3 shadow-sm">
    <div class="mx-auto flex max-w-4xl items-center justify-between gap-4">
        <div class="flex items-center gap-3">
            <a href="<?php echo htmlspecialchars(admin_url('geo-monitor.php')); ?>" class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                ← 返回监测
            </a>
            <span class="text-sm text-gray-500"><?php echo htmlspecialchars($brandName); ?> · <?php echo $reportPeriod; ?> 月报</span>
        </div>
        <div class="flex items-center gap-3">
            <a href="<?php echo htmlspecialchars(admin_url('monthly-report.php?customer_id=' . urlencode($currentCustomer['id'] ?? ''))); ?>" class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                刷新数据
            </a>
            <button onclick="window.print()" class="inline-flex items-center rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-700">
                打印 / 导出 PDF
            </button>
        </div>
    </div>
</div>

<!-- Report content -->
<div class="mx-auto max-w-4xl px-6 py-10 print:py-0">

    <!-- Header -->
    <div class="mb-10 border-b border-gray-200 pb-8">
        <div class="flex items-start justify-between gap-6">
            <div>
                <p class="text-xs font-semibold uppercase tracking-widest text-gray-400">GEO 月度服务报告</p>
                <h1 class="mt-2 text-3xl font-bold text-gray-900"><?php echo htmlspecialchars($brandName); ?></h1>
                <p class="mt-1 text-base text-gray-500"><?php echo htmlspecialchars($industry); ?> · 服务期 <?php echo htmlspecialchars($serviceStartAt); ?> ~ <?php echo htmlspecialchars($contractEndAt); ?></p>
            </div>
            <div class="shrink-0 text-right">
                <p class="text-xs text-gray-400">报告周期</p>
                <p class="mt-1 text-lg font-bold text-gray-900"><?php echo $reportPeriod; ?></p>
                <p class="mt-1 text-xs text-gray-400">生成日期 <?php echo $reportDate; ?></p>
                <p class="mt-1 text-xs text-gray-400">负责人 <?php echo htmlspecialchars($ownerName); ?></p>
            </div>
        </div>
    </div>

    <!-- 一、核心成果 -->
    <section class="mb-10">
        <h2 class="mb-5 text-xl font-bold text-gray-900">一、核心成果</h2>
        <div class="grid grid-cols-2 gap-4 md:grid-cols-4">
            <?php foreach ($renewalItems as $item): ?>
            <div class="print-card rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                <p class="text-xs font-semibold text-gray-500"><?php echo htmlspecialchars($item['label']); ?></p>
                <p class="mt-2 text-2xl font-bold text-gray-900"><?php echo htmlspecialchars($item['value']); ?></p>
                <p class="mt-1 text-xs <?php echo $item['green'] ? 'font-semibold text-emerald-600' : 'text-orange-600'; ?>"><?php echo htmlspecialchars($item['delta']); ?></p>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="mt-4 rounded-xl border border-emerald-200 bg-emerald-50 p-5">
            <p class="text-sm leading-7 text-gray-700">
                <?php if ($hasRealData): ?>
                    本月监测显示，<strong><?php echo htmlspecialchars($brandName); ?></strong> AI 可见率从 <?php echo $trendPoints[0]['brand']; ?>% 变化至 <strong><?php echo $latestBrandRate; ?>%</strong>，变动 <strong><?php echo ($brandRiseTotal >= 0 ? '+' : '') . $brandRiseTotal; ?> 个百分点</strong><?php echo $latestIndustryRate !== null ? '，行业基准为 ' . $latestIndustryRate . '%' : '，行业基准待建立'; ?>。
                    当前覆盖 AI 平台 <?php echo $sourceCurrent; ?> 个，共采集到 <?php echo count($conversations); ?> 条真实 AI 原话证据。
                <?php else: ?>
                    当前客户还没有可用于月报的真实 GEO 监测记录。本报告仅展示已存在的客户、文章、告警与行动项，不生成虚假趋势或 AI 原话。
                <?php endif; ?>
            </p>
        </div>
    </section>

    <!-- 二、AI 引用原话证据 -->
    <section class="mb-10">
        <h2 class="mb-5 text-xl font-bold text-gray-900">二、AI 引用原话证据</h2>
        <div class="space-y-4">
            <?php if (empty($conversations)): ?>
            <div class="print-card rounded-xl border border-dashed border-gray-300 bg-white p-6 text-sm text-gray-500">
                暂无真实 AI 原话证据。请先在 GEO 监测中完成至少一次关键词监测，系统会把模型回答、品牌提及和引用线索写入月报。
            </div>
            <?php endif; ?>
            <?php foreach ($conversations as $idx => $conv): ?>
            <div class="print-card rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                <div class="flex flex-wrap items-center gap-3">
                    <span class="rounded-full bg-blue-100 px-3 py-1 text-xs font-bold text-blue-700"><?php echo htmlspecialchars($conv['platform']); ?></span>
                    <span class="rounded-full bg-gray-100 px-3 py-1 text-xs text-gray-600"><?php echo htmlspecialchars($conv['date']); ?></span>
                    <span class="text-sm font-semibold text-gray-900"><?php echo htmlspecialchars($conv['question']); ?></span>
                </div>
                <div class="mt-4 rounded-lg bg-gray-50 p-4">
                    <p class="text-xs font-semibold text-gray-400">AI 原话</p>
                    <p class="mt-2 text-sm leading-7 text-gray-800"><?php echo htmlspecialchars($conv['answer']); ?></p>
                </div>
                <div class="mt-3 flex flex-wrap gap-2">
                    <span class="text-xs text-gray-500">引用来源：</span>
                    <?php foreach ($conv['citations'] as $cite): ?>
                    <span class="rounded bg-gray-100 px-2 py-0.5 text-xs text-gray-600"><?php echo htmlspecialchars($cite); ?></span>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </section>

    <!-- 三、竞品对比 -->
    <section class="mb-10">
        <h2 class="mb-5 text-xl font-bold text-gray-900">三、竞品对比</h2>
        <div class="print-card overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
            <table class="w-full text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500">品牌 / 竞品</th>
                        <th class="px-5 py-3 text-right text-xs font-semibold text-gray-500">AI 可见率</th>
                        <th class="px-5 py-3 text-right text-xs font-semibold text-gray-500">月均出现次数</th>
                        <th class="px-5 py-3 text-right text-xs font-semibold text-gray-500">平均排名</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500">动态摘要</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php
                    $brandAppearances = $brandMentioned30;
                    $comp0Stats = $competitorStats[$comp0] ?? null;
                    $comp0Rate  = $comp0Stats ? $comp0Stats['mention_rate'] : null;
                    $comp0Apps  = $comp0Stats ? $comp0Stats['records_appeared'] : null;
                    $comp1Stats = $competitorStats[$comp1] ?? null;
                    $comp1Rate  = $comp1Stats ? $comp1Stats['mention_rate'] : null;
                    $comp1Apps  = $comp1Stats ? $comp1Stats['records_appeared'] : null;
                    $brandWinComp0 = $comp0Rate !== null && $latestBrandRate > $comp0Rate;
                    $brandWinComp1 = $comp1Rate !== null && $latestBrandRate > $comp1Rate;
                    ?>
                    <tr class="bg-emerald-50">
                        <td class="px-5 py-4 font-semibold text-emerald-700"><?php echo htmlspecialchars($brandName); ?> <span class="ml-1 rounded-full bg-emerald-200 px-2 py-0.5 text-xs">本品牌</span></td>
                        <td class="px-5 py-4 text-right font-bold text-emerald-700"><?php echo $latestBrandRate; ?>%</td>
                        <td class="px-5 py-4 text-right text-gray-900"><?php echo $brandAppearances; ?></td>
                        <td class="px-5 py-4 text-right text-gray-900">—</td>
                        <td class="px-5 py-4 text-gray-700"><?php echo $hasRealData ? '来自近30天真实监测记录' : '暂无真实监测数据'; ?></td>
                    </tr>
                    <tr>
                        <td class="px-5 py-4 text-gray-900"><?php echo htmlspecialchars($comp0); ?></td>
                        <td class="px-5 py-4 text-right <?php echo $comp0Rate !== null && !$brandWinComp0 ? 'text-red-600 font-semibold' : 'text-gray-500'; ?>"><?php echo $comp0Rate !== null ? $comp0Rate . '%' : '—'; ?></td>
                        <td class="px-5 py-4 text-right text-gray-600"><?php echo $comp0Apps !== null ? $comp0Apps : '—'; ?></td>
                        <td class="px-5 py-4 text-right text-gray-600">—</td>
                        <td class="px-5 py-4 text-gray-500"><?php echo $comp0Rate === null ? '暂无竞品出现记录' : ($brandWinComp0 ? '低于本品牌，继续观察' : '超过本品牌，需补充对比内容'); ?></td>
                    </tr>
                    <tr>
                        <td class="px-5 py-4 text-gray-900"><?php echo htmlspecialchars($comp1); ?></td>
                        <td class="px-5 py-4 text-right <?php echo $comp1Rate !== null && !$brandWinComp1 ? 'text-red-600 font-semibold' : 'text-gray-500'; ?>"><?php echo $comp1Rate !== null ? $comp1Rate . '%' : '—'; ?></td>
                        <td class="px-5 py-4 text-right text-gray-600"><?php echo $comp1Apps !== null ? $comp1Apps : '—'; ?></td>
                        <td class="px-5 py-4 text-right text-gray-600">—</td>
                        <td class="px-5 py-4 text-gray-500"><?php echo $comp1Rate === null ? '暂无竞品出现记录' : ($brandWinComp1 ? '低于本品牌，继续观察' : '超过本品牌，需补充对比内容'); ?></td>
                    </tr>
                    <tr class="bg-gray-50">
                        <td class="px-5 py-4 text-gray-500">行业均值</td>
                        <td class="px-5 py-4 text-right text-gray-500"><?php echo $latestIndustryRate !== null ? $latestIndustryRate . '%' : '—'; ?></td>
                        <td class="px-5 py-4 text-right text-gray-500">—</td>
                        <td class="px-5 py-4 text-right text-gray-500">—</td>
                        <td class="px-5 py-4 text-gray-400">基准线参考</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>

    <!-- 四、本月告警摘要 -->
    <section class="mb-10 page-break">
        <h2 class="mb-5 text-xl font-bold text-gray-900">四、本月告警摘要</h2>
        <div class="space-y-3">
            <?php foreach ($topAlerts as $alert): ?>
            <?php
            $levelColor = match($alert['level']) {
                'HIGH'   => 'border-red-200 bg-red-50',
                'MEDIUM' => 'border-orange-200 bg-orange-50',
                default  => 'border-gray-200 bg-gray-50',
            };
            $badgeColor = match($alert['level']) {
                'HIGH'   => 'bg-red-100 text-red-700',
                'MEDIUM' => 'bg-orange-100 text-orange-700',
                default  => 'bg-gray-100 text-gray-600',
            };
            ?>
            <div class="print-card flex items-start gap-4 rounded-xl border <?php echo $levelColor; ?> p-4">
                <span class="shrink-0 rounded-full <?php echo $badgeColor; ?> px-2.5 py-1 text-xs font-bold"><?php echo htmlspecialchars($alert['level']); ?></span>
                <div class="flex-1">
                    <p class="font-semibold text-gray-900"><?php echo htmlspecialchars($alert['title']); ?></p>
                    <p class="mt-1 text-sm text-gray-600">处理：<?php echo htmlspecialchars($alert['action']); ?></p>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </section>

    <!-- 五、下月行动清单 -->
    <section class="mb-10">
        <h2 class="mb-5 text-xl font-bold text-gray-900">五、下月行动清单</h2>
        <div class="print-card rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
            <ol class="space-y-3">
                <?php foreach ($nextActions as $idx => $action): ?>
                <li class="flex items-start gap-3">
                    <span class="shrink-0 flex h-6 w-6 items-center justify-center rounded-full bg-slate-900 text-xs font-bold text-white"><?php echo $idx + 1; ?></span>
                    <span class="text-sm leading-6 text-gray-800"><?php echo htmlspecialchars($action); ?></span>
                </li>
                <?php endforeach; ?>
            </ol>
        </div>
    </section>

    <!-- 六、续费建议 -->
    <section class="mb-10">
        <h2 class="mb-5 text-xl font-bold text-gray-900">六、续费建议</h2>
        <div class="print-card rounded-xl border border-blue-200 bg-blue-50 p-6">
            <div class="flex items-start gap-4">
                <div class="flex-1">
                    <p class="text-base font-semibold text-blue-900">合同到期日：<?php echo htmlspecialchars($contractEndAt); ?></p>
                    <p class="mt-3 text-sm leading-7 text-gray-700">
                        <?php if ($hasRealData): ?>
                        本月服务期间，<?php echo htmlspecialchars($brandName); ?> 已积累真实 AI 监测记录，可见率当前为 <?php echo $latestBrandRate; ?>%。
                        当前阶段的核心风险是平台覆盖（<?php echo $sourceCurrent; ?>/6）和低提及关键词，建议通过持续的信源补充和内容优化来巩固优势。
                        <?php else: ?>
                        当前还缺少真实 AI 监测记录，暂不输出续费效果结论。建议先完成监测、文章发布和引用证据采集，再生成正式月报。
                        <?php endif; ?>
                        续费后优先执行：扩充权威信源、完成竞品压制专项、建立季度复盘机制。
                    </p>
                    <div class="mt-4 grid grid-cols-2 gap-3 md:grid-cols-4">
                        <?php foreach ($renewalItems as $item): ?>
                        <div class="rounded-lg bg-white/80 p-3">
                            <p class="text-xs text-gray-500"><?php echo htmlspecialchars($item['label']); ?></p>
                            <p class="mt-1 text-sm font-bold text-gray-900"><?php echo htmlspecialchars($item['value']); ?></p>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <div class="border-t border-gray-200 pt-6 text-center">
        <p class="text-xs text-gray-400">本报告由 GEO 交付系统自动生成 · <?php echo $reportDate; ?> · 客户成功负责人：<?php echo htmlspecialchars($ownerName); ?></p>
        <p class="mt-1 text-xs text-gray-400">数据来源：AI 平台反查 + GEO 雷达诊断 · 如有疑问请联系服务团队</p>
    </div>

</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const printBtn = document.querySelector('[onclick="window.print()"]');
    if (printBtn) {
        printBtn.addEventListener('click', (e) => {
            e.preventDefault();
            window.print();
        });
    }
});
</script>
</body>
</html>
