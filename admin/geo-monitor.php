<?php
/**
 * GEO 监测 V3
 */

define('FEISHU_TREASURE', true);
session_start();

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/database_admin.php';
require_once __DIR__ . '/../includes/monitor_api_service.php';
require_once __DIR__ . '/../includes/citation_simulator_service.php';
require_once __DIR__ . '/../includes/geo_monitor_alert_service.php';
require_once __DIR__ . '/../includes/geo_baseline_qa_service.php';

require_admin_login();

function geo_monitor_h($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function geo_monitor_load_customer(PDO $db, string $customerId): ?array {
    $customerId = trim($customerId);
    if ($customerId === '') {
        return null;
    }
    try {
        $stmt = $db->prepare("
            SELECT customer_id, name, domain, industry, package_tier, owner, service_status,
                   contract_start_date, contract_end_date, contract_amount, contact_name, contact_phone
            FROM customers
            WHERE customer_id = ?
            LIMIT 1
        ");
        $stmt->execute([$customerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        return [
            'id' => (string) $row['customer_id'],
            'customer_id' => (string) $row['customer_id'],
            'name' => (string) $row['name'],
            'domain' => (string) ($row['domain'] ?? ''),
            'industry' => (string) ($row['industry'] ?? ''),
            'package_tier' => (string) ($row['package_tier'] ?? ''),
            'owner' => (string) ($row['owner'] ?? ''),
            'service_status' => (string) ($row['service_status'] ?? ''),
            'contract_start_at' => (string) ($row['contract_start_date'] ?? ''),
            'contract_end_at' => (string) ($row['contract_end_date'] ?? ''),
            'contract_amount' => $row['contract_amount'] ?? 0,
            'contact_name' => (string) ($row['contact_name'] ?? ''),
            'contact_phone' => (string) ($row['contact_phone'] ?? ''),
            'competitors' => [],
            'cities' => [],
        ];
    } catch (Throwable $_) {
        return null;
    }
}

function geo_monitor_default_questions(string $brandName, string $industry, array $competitors): array {
    $brandName = trim($brandName);
    $industryMain = trim(preg_split('/[\/／,，|]/u', $industry)[0] ?? $industry);
    $industryMain = $industryMain !== '' ? $industryMain : '同类服务';
    $questions = [];
    if ($brandName !== '') {
        $questions[] = "{$brandName} 是做什么的？";
        $questions[] = "{$brandName} 怎么样，值得选吗？";
        $questions[] = "{$brandName} 的核心优势是什么？";
    }
    $questions[] = "{$industryMain}服务商推荐";
    $questions[] = "{$industryMain}哪家更值得选？";
    foreach (array_slice($competitors, 0, 3) as $competitor) {
        $competitor = trim((string) $competitor);
        if ($brandName !== '' && $competitor !== '') {
            $questions[] = "{$brandName} 和 {$competitor} 对比哪个好？";
        }
    }
    return array_values(array_unique(array_filter($questions)));
}

$requestedCustomerId = trim((string) ($_GET['customer'] ?? $_GET['customer_id'] ?? ''));
if ($requestedCustomerId !== '') {
    $loadedCustomer = geo_monitor_load_customer($db, $requestedCustomerId);
    if ($loadedCustomer) {
        $_SESSION['current_customer'] = $loadedCustomer;
    }
}

$currentCustomer = $_SESSION['current_customer'] ?? [];
$customerId = (string) ($currentCustomer['customer_id'] ?? $currentCustomer['id'] ?? '');
if ($customerId === '') {
    try {
        $firstCustomer = $db->query("SELECT customer_id FROM customers ORDER BY created_at DESC LIMIT 1")->fetchColumn();
        if ($firstCustomer) {
            $loadedCustomer = geo_monitor_load_customer($db, (string) $firstCustomer);
            if ($loadedCustomer) {
                $_SESSION['current_customer'] = $loadedCustomer;
                $currentCustomer = $loadedCustomer;
                $customerId = (string) $loadedCustomer['customer_id'];
            }
        }
    } catch (Throwable $_) {}
}

$brandName = $currentCustomer['name'] ?? '未选择客户';
$industry = $currentCustomer['industry'] ?? '';
$competitorsFromCustomer = $currentCustomer['competitors'] ?? [];
$contractEndAt = $currentCustomer['contract_end_at'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['add_monitor_keyword', 'add_monitor_competitor', 'seed_monitor_questions'], true)) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        admin_redirect('geo-monitor.php?customer=' . rawurlencode($customerId) . '&monitor_error=csrf');
    }
    $postCustomerId = trim((string) ($_POST['customer_id'] ?? $customerId));
    if ($postCustomerId !== '') {
        $customerId = $postCustomerId;
    }

    try {
        $action = (string) $_POST['action'];
        if ($action === 'add_monitor_keyword') {
            $keyword = trim((string) ($_POST['keyword'] ?? ''));
            if ($keyword !== '') {
                $monitorService = new MonitorApiService($db);
                $monitorService->addKeywords($customerId, [$keyword]);
            }
        } elseif ($action === 'add_monitor_competitor') {
            $competitor = trim((string) ($_POST['competitor'] ?? ''));
            if ($competitor !== '') {
                $stmt = $db->prepare("
                    INSERT INTO geo_customer_competitors (customer_id, competitor, enabled)
                    VALUES (?, ?, TRUE)
                    ON CONFLICT (customer_id, competitor) DO UPDATE SET enabled = TRUE
                ");
                $stmt->execute([$customerId, $competitor]);
            }
        } elseif ($action === 'seed_monitor_questions') {
            $stmtCmpSeed = $db->prepare("SELECT competitor FROM geo_customer_competitors WHERE customer_id = ? AND enabled = TRUE ORDER BY id ASC");
            $stmtCmpSeed->execute([$customerId]);
            $seedCompetitors = $stmtCmpSeed->fetchAll(PDO::FETCH_COLUMN) ?: [];
            $questions = geo_monitor_default_questions($brandName, $industry, $seedCompetitors);
            if (!empty($questions)) {
                $monitorService = new MonitorApiService($db);
                $monitorService->addKeywords($customerId, $questions);
            }
        }
        admin_redirect('geo-monitor.php?customer=' . rawurlencode($customerId) . '&monitor_saved=1');
    } catch (Throwable $e) {
        admin_redirect('geo-monitor.php?customer=' . rawurlencode($customerId) . '&monitor_error=save');
    }
}

// 从数据库读取真实合同到期日，计算续费倒计时
$renewalLabel = 'T-?';
try {
    $stmtContract = $db->prepare("SELECT contract_end_date FROM customers WHERE customer_id = ?");
    $stmtContract->execute([$customerId]);
    $dbContractEnd = $stmtContract->fetchColumn();
    if ($dbContractEnd) {
        $daysLeft = (int) ceil((strtotime($dbContractEnd) - time()) / 86400);
        $renewalLabel = 'T-' . $daysLeft;
        $contractEndAt = $dbContractEnd;
    }
} catch (Throwable $e) {}

$page_title = 'GEO监测';
$page_header = '
<div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
    <div>
        <h1 class="text-3xl font-bold text-gray-900">GEO监测</h1>
    </div>
    <div class="flex flex-wrap gap-3">
        <button type="button" data-open-monitor-tab="renewal" class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50">
            <i data-lucide="file-down" class="mr-2 h-4 w-4"></i>续费证据包
        </button>
        <a href="' . admin_url('sop-center.php') . '" class="inline-flex items-center rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-slate-700">
            <i data-lucide="siren" class="mr-2 h-4 w-4"></i>应急 SOP
        </a>
    </div>
</div>';

// --- 从 geo_monitor_records 读真实数据 ---
$baselineTracking = geo_baseline_qa_tracking($db, $customerId);
$baselineRows = $baselineTracking['rows'];
$baselineSummary = $baselineTracking['summary'];
$baselinePlatforms = geo_baseline_qa_platforms();

// 真实监测数据：最近 90 天
$realMonitorData = ['total' => 0, 'mentioned' => 0, 'records' => [], 'by_date' => []];
try {
    $stmtReal = $db->prepare("
        SELECT queried_at::text AS day,
               COUNT(*)                                        AS total,
               COUNT(*) FILTER (WHERE brand_mentioned = TRUE) AS mentioned
        FROM geo_monitor_records
        WHERE customer_id = ?
          AND queried_at >= CURRENT_DATE - INTERVAL '90 days'
        GROUP BY queried_at
        ORDER BY queried_at ASC
    ");
    $stmtReal->execute([$customerId]);
    $rows = $stmtReal->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        $realMonitorData['total']     += (int) $row['total'];
        $realMonitorData['mentioned'] += (int) $row['mentioned'];
        $realMonitorData['by_date'][$row['day']] = [
            'total'     => (int) $row['total'],
            'mentioned' => (int) $row['mentioned'],
            'rate'      => $row['total'] > 0 ? round((int)$row['mentioned'] / (int)$row['total'] * 100) : 0,
        ];
    }
    // 最近记录（用于AI对话证据展示）
    $stmtRec = $db->prepare("
        SELECT * FROM geo_monitor_records
        WHERE customer_id = ?
        ORDER BY queried_at DESC, id DESC
        LIMIT 20
    ");
    $stmtRec->execute([$customerId]);
    $realMonitorData['records'] = $stmtRec->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $_me) {}

$hasRealData = $realMonitorData['total'] > 0;
$cityName    = trim(($currentCustomer['cities'][0] ?? '') ?: '本地');
$industryLabel = trim(explode('/', $industry)[0]);

// Load competitors from DB (single source of truth); fall back to session
$competitorsFromDb = [];
try {
    $stmtCmpLoad = $db->prepare("SELECT competitor FROM geo_customer_competitors WHERE customer_id = ? AND enabled = TRUE ORDER BY id ASC");
    $stmtCmpLoad->execute([$customerId]);
    $competitorsFromDb = $stmtCmpLoad->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $_gcml) {}
if (!empty($competitorsFromDb)) {
    $competitorsFromCustomer = $competitorsFromDb;
}
$platformDisplay = [
    'kimi'     => ['label' => 'Kimi',     'combo' => ''],
    'deepseek' => ['label' => 'DeepSeek', 'combo' => ''],
    'tongyi'   => ['label' => '通义',      'combo' => '千问'],
    'wenxin'   => ['label' => '文心',      'combo' => '百度千帆'],
    'doubao'   => ['label' => '豆包',      'combo' => '火山方舟'],
    'yuanbao'  => ['label' => '元宝',      'combo' => '腾讯混元'],
];

$monitorKeywords = [];
try {
    $stmtMonitorKeywords = $db->prepare("
        SELECT id, keyword, enabled, created_at
        FROM geo_monitor_keywords
        WHERE customer_id = ?
        ORDER BY enabled DESC, id ASC
    ");
    $stmtMonitorKeywords->execute([$customerId]);
    $monitorKeywords = $stmtMonitorKeywords->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $_mkw) {}

$monitorFacts = [];
try {
    $stmtFacts = $db->prepare("SELECT fact_key, fact_label, fact_value, is_core FROM geo_brand_facts WHERE customer_id = ? ORDER BY is_core DESC, sort_order ASC, id ASC");
    $stmtFacts->execute([$customerId]);
    $monitorFacts = $stmtFacts->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $_mf) {}

$apiConfig = [];
$configuredProviders = [];
try {
    $apiConfig = citation_simulator_api_config();
    foreach (['kimi', 'deepseek', 'tongyi', 'wenxin', 'doubao', 'yuanbao'] as $pkey) {
        $pcfg = $apiConfig['providers'][$pkey] ?? null;
        if ($pcfg && !empty($pcfg['configured']) && (($pcfg['op_status'] ?? 'normal') !== 'disabled')) {
            $configuredProviders[$pkey] = $pcfg['label'] ?? $pkey;
        }
    }
} catch (Throwable $_cfg) {}
try {
    $stmtModels = $db->query("
        SELECT name, model_id, api_url
        FROM ai_models
        WHERE status = 'active'
          AND (model_type = 'chat' OR model_type IS NULL OR model_type = '')
          AND COALESCE(api_key, '') <> ''
          AND COALESCE(model_id, '') <> ''
        ORDER BY priority ASC NULLS LAST, id ASC
    ");
    foreach ($stmtModels->fetchAll(PDO::FETCH_ASSOC) as $model) {
        $modelText = mb_strtolower((string) ($model['name'] ?? '') . ' ' . (string) ($model['model_id'] ?? '') . ' ' . (string) ($model['api_url'] ?? ''));
        $pkey = preg_replace('/[^a-z0-9_\-]+/', '-', trim((string) ($model['model_id'] ?? 'custom-model')));
        if (str_contains($modelText, 'deepseek')) $pkey = 'deepseek';
        elseif (str_contains($modelText, 'doubao') || str_contains($modelText, 'volces') || str_contains($modelText, 'ark.cn')) $pkey = 'doubao';
        elseif (str_contains($modelText, 'qwen') || str_contains($modelText, 'tongyi') || str_contains($modelText, 'dashscope') || str_contains($modelText, '千问')) $pkey = 'tongyi';
        elseif (str_contains($modelText, 'moonshot') || str_contains($modelText, 'kimi')) $pkey = 'kimi';
        elseif (str_contains($modelText, 'hunyuan') || str_contains($modelText, 'yuanbao') || str_contains($modelText, '腾讯') || str_contains($modelText, '混元')) $pkey = 'yuanbao';
        elseif (str_contains($modelText, 'mimo') || str_contains($modelText, 'xiaomi')) $pkey = 'mimo-v2.5-pro';
        $configuredProviders[$pkey] = $model['name'] ?: ($model['model_id'] ?? $pkey);
        if (!isset($platformDisplay[$pkey])) {
            $platformDisplay[$pkey] = ['label' => (string) ($model['name'] ?: $model['model_id'] ?: $pkey), 'combo' => 'AI模型'];
        }
    }
} catch (Throwable $_models) {}

$enabledKeywordCount = count(array_filter($monitorKeywords, static fn($kw) => !empty($kw['enabled'])));
$providerCount = count($configuredProviders);
$readinessIssues = [];
if ($customerId === '') $readinessIssues[] = '未选择客户';
if (trim($brandName) === '' || $brandName === '未选择客户') $readinessIssues[] = '缺少品牌名称';
if ($enabledKeywordCount <= 0) $readinessIssues[] = '没有启用的监测关键词';
if (empty($competitorsFromCustomer)) $readinessIssues[] = '没有竞品名单';
if ($providerCount <= 0) $readinessIssues[] = '没有可用 AI 模型或平台 Key';
$monitorReady = empty($readinessIssues);

try {
    geo_monitor_refresh_alerts($db, $customerId, $brandName, $competitorsFromCustomer);
} catch (Throwable $_refreshAlerts) {}

// ── 内容收录：关键词生命周期数据（真实） ──────────────────────────────────
$kwLifecycle = [];
try {
    $stmtKwLife = $db->prepare("
        SELECT
            k.id          AS kw_id,
            k.keyword,
            k.article_id,
            k.source_url,
            k.created_at  AS added_at,
            MIN(r.queried_at)::text                                    AS first_seen,
            MAX(r.queried_at)::text                                    AS last_seen,
            COUNT(r.id)                                                AS total_queries,
            COUNT(r.id) FILTER (WHERE r.brand_mentioned = TRUE)        AS brand_hit_count,
            COUNT(DISTINCT r.queried_at) FILTER (WHERE r.brand_mentioned = TRUE) AS hit_days,
            COALESCE((SELECT a.title FROM articles a WHERE a.id = k.article_id), '') AS article_title
        FROM geo_monitor_keywords k
        LEFT JOIN geo_monitor_records r
            ON r.customer_id = k.customer_id AND r.query_text = k.keyword
        WHERE k.customer_id = ?
        GROUP BY k.id, k.keyword, k.article_id, k.source_url, k.created_at
        ORDER BY k.created_at DESC
    ");
    $stmtKwLife->execute([$customerId]);
    $kwLifecycle = $stmtKwLife->fetchAll(PDO::FETCH_ASSOC);

    // 每个关键词追加竞品超越信息（从最近 30 天 competitors_found 聚合）
    foreach ($kwLifecycle as &$kwRow) {
        $kwRow['competitor_hits'] = [];
        if (empty($competitorsFromCustomer)) continue;
        $stmtCf = $db->prepare("
            SELECT competitors_found
            FROM geo_monitor_records
            WHERE customer_id = ? AND query_text = ?
              AND queried_at >= CURRENT_DATE - INTERVAL '29 days'
              AND competitors_found IS NOT NULL AND competitors_found != '[]'
        ");
        $stmtCf->execute([$customerId, $kwRow['keyword']]);
        $cfRows = $stmtCf->fetchAll(PDO::FETCH_COLUMN);
        $compCount = [];
        foreach ($cfRows as $cfJson) {
            $cfArr = json_decode((string) $cfJson, true);
            if (!is_array($cfArr)) continue;
            foreach ($cfArr as $cf) {
                $cn = $cf['name'] ?? '';
                if ($cn !== '') $compCount[$cn] = ($compCount[$cn] ?? 0) + 1;
            }
        }
        $kwRow['competitor_hits'] = $compCount;  // ['竞品A' => 3, ...]
    }
    unset($kwRow);
} catch (Throwable $_kwE) {}

// ── 各平台真实覆盖率 ──────────────────────────────────────────────────────
$platformStats = [];
try {
    $stmtPlatform = $db->prepare("
        SELECT
            provider,
            COUNT(*)                                                        AS total,
            COUNT(*) FILTER (WHERE brand_mentioned = TRUE)                  AS mentioned,
            COUNT(*) FILTER (WHERE mention_depth >= 2)                      AS deep_hits,
            ROUND(AVG(CASE WHEN accuracy_score IS NOT NULL THEN accuracy_score END), 1) AS avg_accuracy
        FROM geo_monitor_records
        WHERE customer_id = ?
          AND queried_at >= CURRENT_DATE - INTERVAL '29 days'
        GROUP BY provider
    ");
    $stmtPlatform->execute([$customerId]);
    foreach ($stmtPlatform->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $total = (int) $row['total'];
        $platformStats[$row['provider']] = [
            'total'        => $total,
            'mentioned'    => (int) $row['mentioned'],
            'deep_hits'    => (int) $row['deep_hits'],
            'mention_rate' => $total > 0 ? round((int)$row['mentioned'] / $total * 100) : 0,
            'core_rate'    => $total > 0 ? round((int)$row['deep_hits']  / $total * 100) : 0,
            'avg_accuracy' => $row['avg_accuracy'] !== null ? (float)$row['avg_accuracy'] : null,
        ];
    }
} catch (Throwable $_pe) {}

// ── 真实告警 ─────────────────────────────────────────────────────────────
$realAlerts = [];
try {
    $stmtAlerts = $db->prepare("
        SELECT * FROM geo_monitor_alerts
        WHERE customer_id = ?
        ORDER BY CASE level WHEN 'high' THEN 0 WHEN 'medium' THEN 1 ELSE 2 END,
                 alerted_at DESC
        LIMIT 20
    ");
    $stmtAlerts->execute([$customerId]);
    $realAlerts = $stmtAlerts->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $_ae) {}
$hasRealAlerts = !empty($realAlerts);
$realAlertCount = count($realAlerts);

// ── 趋势图：全部使用真实监测数据 ──────────────────────────────────────
$trendPoints = [];

// 查询跨客户行业均值（所有客户的平均每日提及率）
$industryByDate = [];
try {
    $stmtIndustryTrend = $db->prepare("
        SELECT day, ROUND(AVG(rate), 0) AS avg_rate
        FROM (
            SELECT customer_id, queried_at AS day,
                   CASE WHEN COUNT(*) > 0 THEN ROUND(COUNT(*) FILTER (WHERE brand_mentioned = TRUE)::numeric / COUNT(*) * 100, 0) ELSE 0 END AS rate
            FROM geo_monitor_records
            WHERE queried_at >= CURRENT_DATE - INTERVAL '90 days'
            GROUP BY customer_id, queried_at
        ) sub
        GROUP BY day
        ORDER BY day ASC
    ");
    $stmtIndustryTrend->execute();
    foreach ($stmtIndustryTrend->fetchAll(PDO::FETCH_ASSOC) as $iRow) {
        $industryByDate[$iRow['day']] = (int) $iRow['avg_rate'];
    }
} catch (Throwable $_it) {}

if ($hasRealData && !empty($realMonitorData['by_date'])) {
    // 只保留有真实监测数据的日期，不做前向填充
    foreach ($realMonitorData['by_date'] as $td => $dData) {
        $trendPoints[] = [
            'date'      => (new DateTimeImmutable($td))->format('m-d'),
            'brand'     => (int) $dData['rate'],
            'industry'  => $industryByDate[$td] ?? null,
            'has_industry' => isset($industryByDate[$td]),
            'origin'    => 'live',
        ];
    }
}

// 获取最新行业均值（取最近一次跨客户平均，供对标使用）
$latestIndustryRate = null;
if (!empty($industryByDate)) {
    $latestIndustryRate = (int) end($industryByDate);
}

if (!empty($trendPoints)) {
    $latestBrandRate = end($trendPoints)['brand'];
    $brandRiseTotal  = $latestBrandRate - $trendPoints[0]['brand'];
} else {
    $latestBrandRate = 0;
    $brandRiseTotal  = 0;
}
reset($trendPoints);

// ── 竞品对标：只基于真实监测记录 ──────────────────────────────────────
$selfRadar       = [0, 0, 0, 0, 0];
$selfMentionRate = '0%';
$selfAppearances = 0;
$selfAvgRank     = '—';
$rTotal30d       = 0;

// ── 真实自身雷达（从 platformStats 聚合，最近 30 天）────────────────
$rTotal30d = array_sum(array_column($platformStats, 'total'));
if ($rTotal30d > 0) {
    $rMentioned30d  = array_sum(array_column($platformStats, 'mentioned'));
    $rDeepHits30d   = array_sum(array_column($platformStats, 'deep_hits'));
    $rCovPlatforms  = 0;
    foreach ($platformDisplay as $rpk => $_) {
        if (isset($platformStats[$rpk]) && $platformStats[$rpk]['mention_rate'] > 0) $rCovPlatforms++;
    }
    $rSelfMentionNum = round($rMentioned30d / $rTotal30d * 100);
    $providerDenominator = max(1, $providerCount, count($platformStats));
    $rPlatCov        = round($rCovPlatforms / $providerDenominator * 100);
    $rCoreRate       = round($rDeepHits30d  / $rTotal30d * 100);
    $accVals         = array_filter(array_column($platformStats, 'avg_accuracy'), static fn($v) => $v !== null);
    $rAccScore       = !empty($accVals) ? (int) round(array_sum($accVals) / count($accVals)) : 0;

    $keywordCoverageRate = 0;
    try {
        $stmtKwCov = $db->prepare("
            SELECT
                COUNT(DISTINCT query_text) AS total_keywords,
                COUNT(DISTINCT query_text) FILTER (WHERE brand_mentioned = TRUE) AS hit_keywords
            FROM geo_monitor_records
            WHERE customer_id = ?
              AND queried_at >= CURRENT_DATE - INTERVAL '29 days'
        ");
        $stmtKwCov->execute([$customerId]);
        $kwCov = $stmtKwCov->fetch(PDO::FETCH_ASSOC) ?: [];
        $kwTotal = max(1, (int) ($kwCov['total_keywords'] ?? 0), $enabledKeywordCount);
        $keywordCoverageRate = round(((int) ($kwCov['hit_keywords'] ?? 0)) / $kwTotal * 100);
    } catch (Throwable $_kwcov) {}

    $selfRadar       = [$rPlatCov, $rSelfMentionNum, $rCoreRate, $keywordCoverageRate, $rAccScore];
    $selfMentionRate = $rSelfMentionNum . '%';
    $selfAppearances = $rMentioned30d;
}

// ── 竞品雷达：从 competitors_found 聚合最近 30 天 ─────────────────
$compAggData = [];
$brandKeywordStats = [];
try {
    $stmtCfAgg = $db->prepare("
        SELECT provider, query_text, brand_mentioned, competitors_found
        FROM geo_monitor_records
        WHERE customer_id = ?
          AND queried_at >= CURRENT_DATE - INTERVAL '29 days'
    ");
    $stmtCfAgg->execute([$customerId]);
    foreach ($stmtCfAgg->fetchAll(PDO::FETCH_ASSOC) as $cfRow) {
        $kw = trim((string) ($cfRow['query_text'] ?? ''));
        if ($kw === '') $kw = '(未命名关键词)';
        $brandKeywordStats[$kw] ??= ['total' => 0, 'hits' => 0];
        $brandKeywordStats[$kw]['total']++;
        if (!empty($cfRow['brand_mentioned'])) $brandKeywordStats[$kw]['hits']++;

        $cfArr = json_decode((string) $cfRow['competitors_found'], true);
        if (!is_array($cfArr)) continue;
        foreach ($cfArr as $cf) {
            $cn = $cf['name'] ?? '';
            if ($cn === '') continue;
            if (!isset($compAggData[$cn])) $compAggData[$cn] = ['appears' => 0, 'providers' => [], 'keywords' => []];
            $compAggData[$cn]['appears']++;
            $compAggData[$cn]['providers'][$cfRow['provider']] = true;
            $compAggData[$cn]['keywords'][$kw] = ($compAggData[$cn]['keywords'][$kw] ?? 0) + 1;
        }
    }
} catch (Throwable $_cfq) {}

$radarDimensions = ['平台覆盖率', '整体出现率', '深度命中率', '关键词覆盖', '语义准确'];

// 构建 radarSubjects：自身和竞品都只使用真实监测聚合。
$radarSubjects = [[
    'type'        => 'self',
    'name'        => $brandName,
    'radar'       => $selfRadar,
    'mentionRate' => $selfMentionRate,
    'appearances' => $selfAppearances,
    'avgRank'     => $selfAvgRank,
    'summary'     => $rTotal30d > 0
        ? '基于近30天 ' . $rTotal30d . ' 次监测查询真实计算，平台覆盖率 ' . $selfRadar[0] . '%，核心信息呈现率 ' . $selfRadar[2] . '%。'
        : '暂无近30天真实监测记录，启动监测后自动计算。',
]];

foreach ($competitorsFromCustomer as $ci => $cname) {
    $cdata = $compAggData[$cname] ?? null;
    if ($cdata !== null && $rTotal30d > 0) {
        $cRate  = round($cdata['appears'] / $rTotal30d * 100);
        $cCov   = round(count($cdata['providers']) / max(1, $providerCount, count($platformStats)) * 100);
        $keywordDenominator = max(1, count($brandKeywordStats), $enabledKeywordCount);
        $cKeywordCoverage = round(count($cdata['keywords']) / $keywordDenominator * 100);
        $surpassKeywords = 0;
        foreach ($cdata['keywords'] as $kw => $hits) {
            $brandStat = $brandKeywordStats[$kw] ?? ['hits' => 0, 'total' => 0];
            if ((int) ($brandStat['total'] ?? 0) > 0 && (int) $hits > (int) ($brandStat['hits'] ?? 0)) {
                $surpassKeywords++;
            }
        }
        $cRisk = round($surpassKeywords / $keywordDenominator * 100);
        $cRadar = [$cCov, $cRate, 0, $cKeywordCoverage, $cRisk];
        $cMRate = $cRate . '%';
        $cApps  = $cdata['appears'];
        $cSummary = '近30天真实出现 ' . $cdata['appears'] . ' 次，覆盖 ' . count($cdata['providers']) . ' 个平台，涉及 ' . count($cdata['keywords']) . ' 个关键词。';
    } else {
        $cRadar   = [0, 0, 0, 0, 0];
        $cMRate   = '0%';
        $cApps    = 0;
        $cSummary = $rTotal30d > 0 ? '近30天真实监测中暂未出现。' : '等待监测运行后计算。';
    }
    $radarSubjects[] = [
        'type'        => 'competitor',
        'name'        => $cname,
        'radar'       => $cRadar,
        'mentionRate' => $cMRate,
        'appearances' => $cApps,
        'avgRank'     => '—',
        'summary'     => $cSummary,
    ];
}
if ($latestIndustryRate !== null) {
    $radarSubjects[] = [
        'type'        => 'industry',
        'name'        => '行业均值',
        'radar'       => [0, $latestIndustryRate, 0, 0, 0],
        'mentionRate' => $latestIndustryRate . '%',
        'appearances' => '—',
        'avgRank'     => '—',
        'summary'     => '跨客户真实监测记录计算出的最近行业平均提及率。',
    ];
}

// 告警只使用 geo_monitor_alerts 的真实记录。
$alerts = [];

// ── AI对话记录：只展示 geo_monitor_records 的真实回答 ───────────────
$convProviderMap = [
    'kimi' => 'Kimi', 'deepseek' => 'DeepSeek', 'tongyi' => '通义',
    'wenxin' => '文心', 'doubao' => '豆包', 'yuanbao' => '元宝',
];
$conversations = [];
if ($hasRealData && !empty($realMonitorData['records'])) {
    foreach ($realMonitorData['records'] as $rIdx => $rec) {
        $prov      = (string)($rec['provider'] ?? '');
        $platLabel = $convProviderMap[$prov] ?? ucfirst($prov);
        $cfArr     = json_decode((string)($rec['competitors_found'] ?? '[]'), true);
        $compNames = [];
        if (is_array($cfArr)) {
            foreach ($cfArr as $cf) {
                if (($cf['name'] ?? '') !== '') $compNames[] = $cf['name'];
            }
        }
        $recDate  = substr((string)($rec['queried_at'] ?? ''), 0, 10);
        $depth    = (int)($rec['mention_depth'] ?? 0);
        $mentionCount = (int) ($rec['mention_count'] ?? 0);
        $fingerprintHits = json_decode((string) ($rec['fingerprint_matched'] ?? '[]'), true);
        $fingerprintCount = is_array($fingerprintHits) ? count($fingerprintHits) : 0;
        $citationCount = (!empty($rec['source_url_cited']) ? 1 : 0) + $fingerprintCount;
        $heat = min(100, $depth * 22 + min(20, $mentionCount * 4) + min(14, $citationCount * 7));
        $fullResp = mb_substr((string)($rec['full_response'] ?? '（完整响应未存储）'), 0, 600);
        $conversations[] = [
            'id'           => 'conv-real-' . $rec['id'],
            'date'         => $recDate,
            'question'     => (string)($rec['query_text'] ?? ''),
            'type'         => !empty($rec['brand_mentioned']) ? '品牌提及' : '未提及品牌',
            'heat'         => $heat,
            'platform'     => $platLabel,
            'brandTerms'   => [$brandName],
            'brandMentions'=> $mentionCount,
            'competitors'  => $compNames,
            'citations'    => array_values(array_filter(array_merge(!empty($rec['source_url_cited']) ? ['来源URL命中'] : [], is_array($fingerprintHits) ? $fingerprintHits : []))),
            'citationCount'=> $citationCount,
            'answer'       => $fullResp,
        ];
    }
}

// ── 文章采信率：关联了关键词的文章，统计被AI深度引用的次数 ──────────────
$articleAdoption = [];
try {
    $stmtArt = $db->prepare("
        SELECT
            a.id                                                                AS article_id,
            a.title,
            a.created_at,
            k.keyword,
            k.source_fingerprints,
            COUNT(r.id)                                                         AS total_queries,
            COUNT(r.id) FILTER (WHERE r.brand_mentioned = TRUE)                 AS brand_hits,
            COUNT(r.id) FILTER (WHERE r.mention_depth >= 2)                     AS deep_hits,
            COUNT(r.id) FILTER (WHERE r.content_cited = TRUE)                   AS fingerprint_hits,
            COUNT(DISTINCT r.provider) FILTER (WHERE r.brand_mentioned = TRUE)  AS platform_count,
            MAX(r.queried_at::text)                                             AS last_queried
        FROM articles a
        JOIN geo_monitor_keywords k ON k.article_id = a.id AND k.customer_id = ?
        LEFT JOIN geo_monitor_records r
            ON r.customer_id = ? AND r.query_text = k.keyword
            AND r.queried_at >= CURRENT_DATE - INTERVAL '29 days'
        WHERE a.deleted_at IS NULL
        GROUP BY a.id, a.title, a.created_at, k.keyword, k.source_fingerprints
        ORDER BY deep_hits DESC, total_queries DESC
        LIMIT 50
    ");
    $stmtArt->execute([$customerId, $customerId]);
    $articleAdoption = $stmtArt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $_artE) {}

$competitorRiskCount = count(array_filter($realAlerts, static fn($alert) => ($alert['alert_type'] ?? '') === 'competitor_surpass'));
$renewalItems = [
    [
        'label' => '可见率变化',
        'value' => !empty($trendPoints) ? (($trendPoints[0]['brand'] ?? 0) . '% → ' . $latestBrandRate . '%') : '待监测',
        'desc' => count($trendPoints) >= 2 ? '基于真实监测日期计算，变化 ' . $brandRiseTotal . ' 个百分点。' : '至少积累 2 天监测数据后自动计算。',
    ],
    [
        'label' => '竞品风险',
        'value' => $competitorRiskCount . ' 项',
        'desc' => $competitorRiskCount > 0 ? '来自竞品超越类真实告警。' : '近期待复测或暂未发现竞品超越。',
    ],
    [
        'label' => '原话证据',
        'value' => count($conversations) . ' 条',
        'desc' => '来自 AI 平台真实回答原文，可直接展开核对。',
    ],
    [
        'label' => '续费触发',
        'value' => $renewalLabel,
        'desc' => $contractEndAt ? '合同到期日 ' . $contractEndAt . '。' : '客户合同到期日未录入。',
    ],
];


$thresholdRows = [
    [
        'name'  => '品牌消失',
        'level' => 'HIGH',
        'icon'  => '🚨',
        'rule'  => '品牌之前在 AI 回答中被提及，现在突然不再被提及了',
        'cause' => 'AI 模型更新或竞品内容覆盖了品牌信源',
        'action' => '立即排查并补充品牌内容',
    ],
    [
        'name'  => '提及率骤降',
        'level' => 'HIGH',
        'icon'  => '📉',
        'rule'  => '品牌在 AI 回答中的出现比例，一周内下降超过 15 个百分点',
        'cause' => '竞品发布了大量新内容，挤压了品牌曝光',
        'action' => '启动应急内容补充，压制竞品',
    ],
    [
        'name'  => '提及率下滑',
        'level' => 'MEDIUM',
        'icon'  => '⚠️',
        'rule'  => '品牌在 AI 回答中的出现比例，一周内下降 5-15 个百分点',
        'cause' => '品牌内容更新频率不足，被竞品逐步追赶',
        'action' => '纳入本周内容优化计划，补充高质量文章',
    ],
    [
        'name'  => '信源不足',
        'level' => 'MEDIUM',
        'icon'  => '📄',
        'rule'  => '品牌被 AI 引用的独立来源少于 20 个，可信度受限',
        'cause' => '品牌内容传播渠道单一，缺乏多平台背书',
        'action' => '拓展内容分发渠道，增加权威媒体引用',
    ],
    [
        'name'  => '引用正常',
        'level' => 'LOW',
        'icon'  => '✅',
        'rule'  => '品牌引用来源在 40-60% 范围内自然轮换，属于健康状态',
        'cause' => 'AI 平台正常的内容更新机制',
        'action' => '无需干预，仅记录到月度复盘',
    ],
];

$quotaRows = [];
foreach ($platformDisplay as $pkey => $pdisp) {
    $quotaRows[] = [
        'provider' => $pdisp['label'],
        'method' => isset($configuredProviders[$pkey]) ? '已接入真实模型/API' : '未接入',
        'quota' => isset($platformStats[$pkey]) ? ((int) $platformStats[$pkey]['total'] . ' 次查询') : '暂无查询',
        'status' => isset($configuredProviders[$pkey]) ? (isset($platformStats[$pkey]) ? '已运行' : '待运行') : '未配置',
    ];
}

$jsonOptions = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
$monitorCsrfToken = generate_csrf_token();

require_once __DIR__ . '/includes/header.php';
?>

<div class="space-y-6">
    <?php
    // 计算真实可见率；无数据时保持空状态
    if ($hasRealData) {
        $realRate = $realMonitorData['total'] > 0
            ? round($realMonitorData['mentioned'] / $realMonitorData['total'] * 100)
            : 0;
        $realConvCount = count($realMonitorData['records']);
    }
    ?>
    <section class="grid grid-cols-1 gap-4 md:grid-cols-3">
        <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
            <p class="text-sm font-semibold text-gray-500">竞品对标</p>
            <div class="mt-2 text-4xl font-bold text-gray-900"><?php echo count($competitorsFromCustomer); ?> 个</div>
            <p class="mt-1 text-xs text-gray-400">当前追踪竞品数量</p>
        </div>
        <div class="rounded-xl border border-red-200 <?php echo $realAlertCount > 0 ? 'bg-red-50' : 'bg-white'; ?> p-5 shadow-sm">
            <p class="text-sm font-semibold <?php echo $realAlertCount > 0 ? 'text-red-700' : 'text-gray-500'; ?>">异常告警</p>
            <div class="mt-2 text-4xl font-bold text-gray-900"><?php echo $realAlertCount; ?> 项</div>
            <p class="mt-1 text-xs <?php echo $realAlertCount > 0 ? 'text-red-500' : 'text-gray-400'; ?>"><?php echo $realAlertCount > 0 ? '点击异常告警 tab 查看详情' : '暂无真实告警'; ?></p>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
            <p class="text-sm font-semibold text-gray-500">续费证据包</p>
            <div class="mt-2 text-4xl font-bold text-gray-900"><?= htmlspecialchars($renewalLabel) ?></div>
            <p class="mt-1 text-xs text-gray-400">合同到期倒计时</p>
        </div>
    </section>

    <section class="rounded-xl border border-gray-200 bg-white shadow-sm">
        <div class="border-b border-gray-200 px-6 py-5">
            <div class="flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
                <div>
                    <div class="flex flex-wrap items-center gap-2">
                        <h2 class="text-xl font-bold text-gray-900">真实监测控制台</h2>
                        <span class="rounded-full px-2.5 py-1 text-xs font-bold <?php echo $monitorReady ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-800'; ?>">
                            <?php echo $monitorReady ? '可运行' : '待补配置'; ?>
                        </span>
                    </div>
                    <p class="mt-2 text-sm text-gray-500">
                        当前客户：<span class="font-semibold text-gray-900"><?php echo geo_monitor_h($brandName); ?></span>
                        <span class="text-gray-300">/</span>
                        <code class="rounded bg-gray-100 px-1.5 py-0.5 text-xs text-gray-700"><?php echo geo_monitor_h($customerId); ?></code>
                    </p>
                    <?php if (!$monitorReady): ?>
                        <div class="mt-3 flex flex-wrap gap-2">
                            <?php foreach ($readinessIssues as $issue): ?>
                                <span class="rounded-full bg-amber-50 px-2.5 py-1 text-xs font-semibold text-amber-800"><?php echo geo_monitor_h($issue); ?></span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="flex flex-wrap gap-2">
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?php echo geo_monitor_h($monitorCsrfToken); ?>">
                        <input type="hidden" name="customer_id" value="<?php echo geo_monitor_h($customerId); ?>">
                        <input type="hidden" name="action" value="seed_monitor_questions">
                        <button type="submit" class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                            <i data-lucide="list-plus" class="mr-2 h-4 w-4"></i>生成监测问题
                        </button>
                    </form>
                    <button type="button" id="start-real-monitor" data-customer-id="<?php echo geo_monitor_h($customerId); ?>" data-csrf="<?php echo geo_monitor_h($monitorCsrfToken); ?>" class="inline-flex items-center rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-blue-700 disabled:cursor-not-allowed disabled:bg-gray-300" <?php echo $enabledKeywordCount <= 0 || $providerCount <= 0 ? 'disabled' : ''; ?>>
                        <i data-lucide="play" class="mr-2 h-4 w-4"></i>立即跑真实监测
                    </button>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-4 p-6 lg:grid-cols-5">
            <div class="rounded-lg border border-gray-200 bg-gray-50 p-4">
                <p class="text-xs font-semibold text-gray-500">品牌事实</p>
                <p class="mt-2 text-2xl font-bold text-gray-900"><?php echo count($monitorFacts); ?></p>
                <p class="mt-1 text-xs text-gray-400">用于准确度校验</p>
            </div>
            <div class="rounded-lg border border-gray-200 bg-gray-50 p-4">
                <p class="text-xs font-semibold text-gray-500">监测关键词</p>
                <p class="mt-2 text-2xl font-bold text-gray-900"><?php echo $enabledKeywordCount; ?></p>
                <p class="mt-1 text-xs text-gray-400">启用 / 共 <?php echo count($monitorKeywords); ?> 条</p>
            </div>
            <div class="rounded-lg border border-gray-200 bg-gray-50 p-4">
                <p class="text-xs font-semibold text-gray-500">竞品名单</p>
                <p class="mt-2 text-2xl font-bold text-gray-900"><?php echo count($competitorsFromCustomer); ?></p>
                <p class="mt-1 text-xs text-gray-400">回答中同步识别</p>
            </div>
            <div class="rounded-lg border border-gray-200 bg-gray-50 p-4">
                <p class="text-xs font-semibold text-gray-500">真实模型/平台</p>
                <p class="mt-2 text-2xl font-bold text-gray-900"><?php echo $providerCount; ?></p>
                <p class="mt-1 text-xs text-gray-400">API 或后台模型</p>
            </div>
            <div class="rounded-lg border border-gray-200 bg-gray-50 p-4">
                <p class="text-xs font-semibold text-gray-500">今日记录</p>
                <p class="mt-2 text-2xl font-bold text-gray-900"><?php echo (int) ($realMonitorData['by_date'][date('Y-m-d')]['total'] ?? 0); ?></p>
                <p class="mt-1 text-xs text-gray-400">写入 records</p>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-4 border-t border-gray-200 p-6 xl:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
            <div class="space-y-4">
                <form method="post" class="flex flex-col gap-2 sm:flex-row">
                    <input type="hidden" name="csrf_token" value="<?php echo geo_monitor_h($monitorCsrfToken); ?>">
                    <input type="hidden" name="customer_id" value="<?php echo geo_monitor_h($customerId); ?>">
                    <input type="hidden" name="action" value="add_monitor_keyword">
                    <input type="text" name="keyword" placeholder="添加一个真实监测问题，如：<?php echo geo_monitor_h($brandName); ?> 怎么样？" class="min-w-0 flex-1 rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100">
                    <button type="submit" class="inline-flex items-center justify-center rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-700">
                        <i data-lucide="plus" class="mr-2 h-4 w-4"></i>添加关键词
                    </button>
                </form>
                <form method="post" class="flex flex-col gap-2 sm:flex-row">
                    <input type="hidden" name="csrf_token" value="<?php echo geo_monitor_h($monitorCsrfToken); ?>">
                    <input type="hidden" name="customer_id" value="<?php echo geo_monitor_h($customerId); ?>">
                    <input type="hidden" name="action" value="add_monitor_competitor">
                    <input type="text" name="competitor" placeholder="添加竞品名称" class="min-w-0 flex-1 rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100">
                    <button type="submit" class="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                        <i data-lucide="crosshair" class="mr-2 h-4 w-4"></i>添加竞品
                    </button>
                </form>
                <div class="rounded-lg border border-gray-200 bg-gray-50 p-4">
                    <div class="mb-2 flex items-center justify-between">
                        <p class="text-sm font-bold text-gray-900">当前关键词</p>
                        <a href="<?php echo geo_monitor_h(admin_url('customers.php?customer=' . rawurlencode($customerId) . '#kw-section')); ?>" class="text-xs font-semibold text-blue-600 hover:underline">客户中心管理</a>
                    </div>
                    <div class="flex max-h-32 flex-wrap gap-2 overflow-auto">
                        <?php if (empty($monitorKeywords)): ?>
                            <span class="text-sm text-gray-400">暂无关键词</span>
                        <?php else: ?>
                            <?php foreach ($monitorKeywords as $kw): ?>
                                <span class="rounded-full <?php echo !empty($kw['enabled']) ? 'bg-blue-50 text-blue-700' : 'bg-gray-100 text-gray-400'; ?> px-2.5 py-1 text-xs font-semibold"><?php echo geo_monitor_h($kw['keyword']); ?></span>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="rounded-lg border border-slate-200 bg-slate-950 p-4 text-slate-100">
                <div class="mb-3 flex items-center justify-between gap-3">
                    <div>
                        <p class="text-sm font-bold">运行日志</p>
                        <p id="monitor-run-status" class="mt-1 text-xs text-slate-400">点击立即跑真实监测后显示后台执行输出</p>
                    </div>
                    <button type="button" id="refresh-monitor-status" data-customer-id="<?php echo geo_monitor_h($customerId); ?>" class="rounded-md border border-slate-700 px-3 py-1.5 text-xs font-semibold text-slate-200 hover:bg-slate-800">刷新状态</button>
                </div>
                <pre id="monitor-run-log" class="h-56 overflow-auto whitespace-pre-wrap rounded-md bg-black/30 p-3 text-xs leading-5 text-slate-200">等待启动。</pre>
            </div>
        </div>
    </section>

    <section class="grid grid-cols-1 gap-4 md:grid-cols-4">
        <div class="rounded-xl border border-blue-200 bg-blue-50 p-5 shadow-sm">
            <p class="text-sm font-semibold text-blue-700">问答基准线</p>
            <div class="mt-2 text-3xl font-bold text-gray-900"><?php echo (int) $baselineSummary['total']; ?> 条</div>
            <p class="mt-1 text-xs text-blue-700">来自雷达诊断首次人工问答</p>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
            <p class="text-sm font-semibold text-gray-500">已复测</p>
            <div class="mt-2 text-3xl font-bold text-gray-900"><?php echo (int) $baselineSummary['retested']; ?> 条</div>
            <p class="mt-1 text-xs text-gray-400">同问题同平台已有新答案</p>
        </div>
        <div class="rounded-xl border <?php echo (int) $baselineSummary['changed'] > 0 ? 'border-amber-200 bg-amber-50' : 'border-gray-200 bg-white'; ?> p-5 shadow-sm">
            <p class="text-sm font-semibold <?php echo (int) $baselineSummary['changed'] > 0 ? 'text-amber-700' : 'text-gray-500'; ?>">答案变化</p>
            <div class="mt-2 text-3xl font-bold text-gray-900"><?php echo (int) $baselineSummary['changed']; ?> 条</div>
            <p class="mt-1 text-xs <?php echo (int) $baselineSummary['changed'] > 0 ? 'text-amber-700' : 'text-gray-400'; ?>">语义相似度低或品牌提及状态变化</p>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
            <p class="text-sm font-semibold text-gray-500">待复测</p>
            <div class="mt-2 text-3xl font-bold text-gray-900"><?php echo (int) $baselineSummary['missing']; ?> 条</div>
            <p class="mt-1 text-xs text-gray-400">等待监测脚本写入结果</p>
        </div>
    </section>

    <section class="rounded-xl border border-gray-200 bg-white shadow-sm">
        <div class="flex flex-col gap-4 border-b border-gray-200 px-6 py-5 xl:flex-row xl:items-center xl:justify-between">
            <div>
                <h2 class="text-xl font-bold text-gray-900">监测视图</h2>
            </div>
            <div class="flex flex-wrap gap-2" role="tablist" aria-label="GEO监测视图">
                <?php
                $monitorTabs = [
                    'baseline'     => ['label' => '基准线追踪' . ((int) $baselineSummary['changed'] > 0 ? ' <span class="ml-1 inline-flex h-4 w-4 items-center justify-center rounded-full bg-amber-500 text-[10px] font-bold text-white">' . (int) $baselineSummary['changed'] . '</span>' : ''), 'desc' => '首次答案 vs 最新答案'],
                    'coverage'     => ['label' => '内容收录',     'desc' => '关键词监测列表与生命周期'],
                    'trend'        => ['label' => '引用趋势',     'desc' => '提及率随时间变化曲线'],
                    'competitors'  => ['label' => '竞品对标',     'desc' => '品牌 vs 竞品被引用对比'],
                    'alerts'       => ['label' => '异常告警' . ($hasRealAlerts ? ' <span class="ml-1 inline-flex h-4 w-4 items-center justify-center rounded-full bg-red-500 text-[10px] font-bold text-white">' . count($realAlerts) . '</span>' : ''), 'desc' => '异常波动与风险提醒'],
                    'conversations'=> ['label' => 'AI对话记录',   'desc' => 'AI平台真实回答原文'],
                    'platforms'    => ['label' => '六大平台覆盖率','desc' => '各平台覆盖情况一览'],
                    'articles'     => ['label' => '文章采信率' . (!empty($articleAdoption) ? ' <span class="ml-1 inline-flex h-4 w-4 items-center justify-center rounded-full bg-indigo-500 text-[10px] font-bold text-white">' . count($articleAdoption) . '</span>' : ''), 'desc' => '已发布文章被AI引用次数'],
                ];
                foreach ($monitorTabs as $key => $tab):
                    $isActive = $key === 'baseline';
                ?>
                    <button type="button" data-monitor-tab="<?php echo htmlspecialchars($key, ENT_QUOTES, 'UTF-8'); ?>" aria-selected="<?php echo $isActive ? 'true' : 'false'; ?>"
                        class="monitor-tab cursor-pointer rounded-lg px-4 py-2.5 text-left <?php echo $isActive ? 'bg-slate-900 text-white' : 'text-gray-600 hover:bg-gray-100'; ?>">
                        <div class="text-sm font-semibold"><?php echo $tab['label']; ?></div>
                        <div data-monitor-tab-desc class="mt-0.5 text-xs <?php echo $isActive ? 'text-slate-300' : 'text-gray-400'; ?>"><?php echo $tab['desc']; ?></div>
                    </button>
                <?php endforeach; ?>
            </div>
        </div>

        <div data-monitor-panel="baseline" class="monitor-panel p-6">
            <?php if (empty($baselineRows)): ?>
                <div class="rounded-xl border border-blue-200 bg-blue-50 p-8 text-center">
                    <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-white text-blue-600">
                        <i data-lucide="radar" class="h-6 w-6"></i>
                    </div>
                    <h3 class="mt-4 text-lg font-bold text-gray-900">还没有可追踪的问答基准线</h3>
                    <p class="mx-auto mt-2 max-w-xl text-sm leading-6 text-gray-600">到雷达诊断的“首次 AI 问答基准线”里维护首问样本，保存后会同步到这里追踪。</p>
                    <a href="<?php echo htmlspecialchars(admin_url('geo-diagnosis.php')); ?>" class="mt-5 inline-flex items-center rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-700">
                        <i data-lucide="edit-3" class="mr-2 h-4 w-4"></i>维护基准线
                    </a>
                </div>
            <?php else: ?>
                <div class="mb-5 flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <h3 class="text-lg font-bold text-gray-900">首次答案变化检测</h3>
                        <p class="mt-1 text-sm text-gray-500">按“同问题 + 同平台”匹配最新监测记录，用来判断品牌认知是否偏离首次人工标准答案。</p>
                    </div>
                    <a href="<?php echo htmlspecialchars(admin_url('geo-diagnosis.php')); ?>" class="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                        <i data-lucide="edit-3" class="mr-2 h-4 w-4"></i>维护基准线
                    </a>
                </div>
                <div class="space-y-4">
                    <?php foreach ($baselineRows as $row): ?>
                        <?php
                        $platformLabel = $baselinePlatforms[(string) ($row['platform'] ?? '')] ?? (string) ($row['platform'] ?? '');
                        $statusClass = 'border-gray-200 bg-white';
                        $statusText = '待复测';
                        if (!empty($row['has_current'])) {
                            if (!empty($row['mention_changed']) || !empty($row['semantic_changed'])) {
                                $statusClass = 'border-amber-200 bg-amber-50';
                                $statusText = '有变化';
                            } else {
                                $statusClass = 'border-emerald-200 bg-emerald-50';
                                $statusText = '稳定';
                            }
                        }
                        ?>
                        <article class="rounded-xl border <?php echo $statusClass; ?> p-5">
                            <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                                <div>
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="rounded-full bg-white px-2.5 py-1 text-xs font-bold text-gray-700"><?php echo htmlspecialchars($platformLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                                        <span class="rounded-full bg-white px-2.5 py-1 text-xs font-bold <?php echo $statusText === '有变化' ? 'text-amber-700' : ($statusText === '稳定' ? 'text-emerald-700' : 'text-gray-500'); ?>"><?php echo $statusText; ?></span>
                                        <?php if ($row['similarity'] !== null): ?>
                                            <span class="rounded-full bg-white px-2.5 py-1 text-xs font-bold text-gray-700">相似度 <?php echo htmlspecialchars((string) $row['similarity'], ENT_QUOTES, 'UTF-8'); ?>%</span>
                                        <?php endif; ?>
                                    </div>
                                    <h4 class="mt-3 text-base font-bold text-gray-900"><?php echo htmlspecialchars((string) $row['question'], ENT_QUOTES, 'UTF-8'); ?></h4>
                                </div>
                                <div class="text-sm text-gray-500">
                                    <?php echo !empty($row['queried_at']) ? '最新复测：' . htmlspecialchars((string) $row['queried_at'], ENT_QUOTES, 'UTF-8') : '尚未复测'; ?>
                                </div>
                            </div>
                            <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-2">
                                <div class="rounded-lg bg-white/80 p-4">
                                    <div class="mb-2 flex items-center justify-between gap-2">
                                        <span class="text-sm font-semibold text-gray-700">首次人工答案</span>
                                        <span class="text-xs <?php echo !empty($row['mention_brand']) ? 'text-blue-700' : 'text-gray-400'; ?>"><?php echo !empty($row['mention_brand']) ? '提及品牌' : '未提及品牌'; ?></span>
                                    </div>
                                    <p class="max-h-40 overflow-auto whitespace-pre-wrap text-sm leading-6 text-gray-700"><?php echo htmlspecialchars((string) ($row['baseline_answer'] ?: '未填写首次答案'), ENT_QUOTES, 'UTF-8'); ?></p>
                                </div>
                                <div class="rounded-lg bg-white/80 p-4">
                                    <div class="mb-2 flex items-center justify-between gap-2">
                                        <span class="text-sm font-semibold text-gray-700">最新监测答案</span>
                                        <?php if (!empty($row['has_current'])): ?>
                                            <span class="text-xs <?php echo !empty($row['current_mention']) ? 'text-blue-700' : 'text-gray-400'; ?>"><?php echo !empty($row['current_mention']) ? '提及品牌' : '未提及品牌'; ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <p class="max-h-40 overflow-auto whitespace-pre-wrap text-sm leading-6 text-gray-700"><?php echo htmlspecialchars((string) ($row['current_answer'] ?: '等待 GEO 监测运行后生成最新答案'), ENT_QUOTES, 'UTF-8'); ?></p>
                                </div>
                            </div>
                            <?php if (!empty($row['mention_changed']) || !empty($row['semantic_changed'])): ?>
                                <div class="mt-4 rounded-lg bg-white/80 p-3 text-sm text-amber-800">
                                    <span class="font-semibold">建议处理：</span>
                                    <?php echo !empty($row['mention_changed']) ? '品牌提及状态发生变化，优先检查该问题对应的内容和信源。' : '答案语义偏离首次标准答案，建议补充可引用的品牌事实页、案例和第三方内容。'; ?>
                                </div>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div data-monitor-panel="trend" class="monitor-panel hidden p-6">
            <div class="rounded-xl border border-gray-200 p-5">
                <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h3 class="flex items-center gap-2 text-lg font-bold text-gray-900">
                            <i data-lucide="line-chart" class="h-5 w-5 text-blue-600"></i>
                            数据趋势分析
                        </h3>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <?php foreach (['7' => '近7天', '30' => '近30天', '90' => '近90天'] as $range => $label): ?>
                            <button type="button" data-trend-range="<?php echo htmlspecialchars($range, ENT_QUOTES, 'UTF-8'); ?>" class="trend-range rounded-full px-4 py-2 text-sm font-semibold <?php echo $range === '30' ? 'bg-blue-600 text-white shadow-md shadow-blue-200' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'; ?>">
                                <?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div id="trend-empty" class="hidden h-[360px] w-full flex flex-col items-center justify-center rounded-lg border-2 border-dashed border-gray-200 bg-gray-50 text-center">
                    <svg class="mb-3 h-12 w-12 text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" /></svg>
                    <p class="text-sm font-medium text-gray-500">监测数据不足</p>
                    <p class="mt-1 text-xs text-gray-400">请持续运行监测任务，至少积累 2 天数据后显示趋势图</p>
                </div>
                <svg id="trend-chart" class="h-[360px] w-full" viewBox="0 0 860 360" role="img" aria-label="数据趋势分析图"></svg>
                <div class="mt-3 flex flex-wrap justify-center gap-5 text-sm text-gray-500">
                    <span class="inline-flex items-center gap-2"><span class="h-4 w-4 rounded-full bg-blue-500"></span>品牌提及率</span>
                    <span class="inline-flex items-center gap-2"><span class="h-4 w-4 rounded-full bg-gray-500"></span>行业均值（跨客户）</span>
                </div>
            </div>
        </div>

        <div data-monitor-panel="coverage" class="monitor-panel hidden p-6">
            <?php if (empty($kwLifecycle)): ?>
                <div class="rounded-xl border border-amber-200 bg-amber-50 p-6 text-center">
                    <p class="font-semibold text-amber-800">暂未监测到任何关键词</p>
                    <p class="mt-1 text-sm text-amber-700">在客户中心添加监测关键词，或发布文章后系统会自动注入文章关键词。</p>
                </div>
            <?php else: ?>
                <div class="mb-4 flex items-center justify-between">
                    <p class="text-sm text-gray-500">共 <?php echo count($kwLifecycle); ?> 个关键词 · 数据来自每日监测记录</p>
                    <a href="<?php echo htmlspecialchars(admin_url('customers.php?customer=' . rawurlencode($customerId) . '#kw-section'), ENT_QUOTES, 'UTF-8'); ?>" class="text-sm font-semibold text-blue-600 hover:underline">管理关键词 →</a>
                </div>
                <div class="overflow-x-auto rounded-xl border border-gray-200">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50 text-xs font-bold uppercase text-gray-500">
                            <tr>
                                <th class="px-4 py-3 text-left">关键词</th>
                                <th class="px-4 py-3 text-left">来源文章</th>
                                <th class="px-4 py-3 text-center">首次收录</th>
                                <th class="px-4 py-3 text-center">最近命中</th>
                                <th class="px-4 py-3 text-center">保留天数</th>
                                <th class="px-4 py-3 text-center">命中率</th>
                                <th class="px-4 py-3 text-left">竞品出现（近30天）</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 bg-white">
                            <?php foreach ($kwLifecycle as $kw): ?>
                                <?php
                                $hitDays    = (int) ($kw['hit_days'] ?? 0);
                                $totalQ     = (int) ($kw['total_queries'] ?? 0);
                                $brandHits  = (int) ($kw['brand_hit_count'] ?? 0);
                                $hitRate    = $totalQ > 0 ? round($brandHits / $totalQ * 100) : 0;
                                $firstSeen  = $kw['first_seen'] ?? null;
                                $lastSeen   = $kw['last_seen'] ?? null;

                                // 计算保留天数：首次 → 最后
                                $retentionDays = 0;
                                if ($firstSeen && $lastSeen) {
                                    $retentionDays = (int) round(
                                        (strtotime($lastSeen) - strtotime($firstSeen)) / 86400
                                    ) + 1;
                                }

                                // 状态颜色
                                if ($hitRate >= 60) { $rateClass = 'text-emerald-700 bg-emerald-50'; }
                                elseif ($hitRate >= 30) { $rateClass = 'text-blue-700 bg-blue-50'; }
                                elseif ($hitRate > 0) { $rateClass = 'text-orange-700 bg-orange-50'; }
                                else { $rateClass = 'text-gray-500 bg-gray-50'; }
                                ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-4 py-3">
                                        <span class="font-semibold text-gray-900"><?php echo htmlspecialchars($kw['keyword'], ENT_QUOTES, 'UTF-8'); ?></span>
                                        <?php if (!empty($kw['source_url'])): ?>
                                            <a href="<?php echo htmlspecialchars($kw['source_url'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank" class="ml-1 text-xs text-blue-500 hover:underline">↗</a>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-4 py-3 text-gray-600 max-w-[160px] truncate" title="<?php echo htmlspecialchars($kw['article_title'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                        <?php echo $kw['article_title'] !== '' ? htmlspecialchars($kw['article_title'], ENT_QUOTES, 'UTF-8') : '<span class="text-gray-400">手动添加</span>'; ?>
                                    </td>
                                    <td class="px-4 py-3 text-center text-gray-600">
                                        <?php echo $firstSeen ?? '<span class="text-gray-400">—</span>'; ?>
                                    </td>
                                    <td class="px-4 py-3 text-center text-gray-600">
                                        <?php echo $lastSeen ?? '<span class="text-gray-400">—</span>'; ?>
                                    </td>
                                    <td class="px-4 py-3 text-center">
                                        <?php if ($retentionDays > 0): ?>
                                            <span class="font-semibold text-gray-900"><?php echo $retentionDays; ?></span>
                                            <span class="text-gray-400">天</span>
                                            <span class="ml-1 text-xs text-gray-400">(<?php echo $hitDays; ?>天命中)</span>
                                        <?php else: ?>
                                            <span class="text-gray-400">未监测到</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-4 py-3 text-center">
                                        <?php if ($totalQ > 0): ?>
                                            <span class="rounded-full px-2.5 py-1 text-xs font-bold <?php echo $rateClass; ?>"><?php echo $hitRate; ?>%</span>
                                            <div class="mt-1 text-xs text-gray-400"><?php echo $brandHits; ?>/<?php echo $totalQ; ?></div>
                                        <?php else: ?>
                                            <span class="text-gray-400">暂无数据</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-4 py-3">
                                        <?php if (!empty($kw['competitor_hits'])): ?>
                                            <div class="flex flex-wrap gap-1">
                                                <?php foreach ($kw['competitor_hits'] as $cn => $cnt): ?>
                                                    <?php $over = $totalQ > 0 && round($cnt / $totalQ * 100) > $hitRate; ?>
                                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold <?php echo $over ? 'bg-red-100 text-red-700' : 'bg-gray-100 text-gray-600'; ?>">
                                                        <?php echo htmlspecialchars($cn, ENT_QUOTES, 'UTF-8'); ?>
                                                        <span class="ml-1 opacity-70"><?php echo $cnt; ?>次</span>
                                                        <?php if ($over): ?><span class="ml-0.5 text-red-500">↑</span><?php endif; ?>
                                                    </span>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-gray-400 text-xs">暂无竞品出现</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <div data-monitor-panel="competitors" class="monitor-panel hidden p-6">
            <div class="grid grid-cols-1 gap-6 xl:grid-cols-[minmax(0,1fr)_380px]">
                <div class="rounded-xl border border-gray-200 p-5">
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <h3 class="text-lg font-bold text-gray-900">竞品五维对标</h3>
                        </div>
                        <div class="text-xs text-gray-500">蓝色：客户 / 红色：竞品 / 灰色：行业</div>
                    </div>
                    <svg id="radar-chart" class="mt-4 h-[390px] w-full" viewBox="0 0 420 390" role="img" aria-label="竞品五维雷达"></svg>
                </div>
                <div class="space-y-3" id="radar-subject-list">
                    <?php foreach ($radarSubjects as $index => $subject): ?>
                        <button type="button" data-radar-index="<?php echo $index; ?>" class="radar-subject w-full rounded-xl border p-4 text-left shadow-sm transition <?php echo $index === 0 ? 'border-blue-300 bg-blue-50' : 'border-gray-200 bg-white hover:border-blue-200'; ?>">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <p class="text-base font-bold text-gray-900"><?php echo htmlspecialchars($subject['name'], ENT_QUOTES, 'UTF-8'); ?></p>
                                    <p class="mt-1 text-xs text-gray-500"><?php echo htmlspecialchars($subject['summary'], ENT_QUOTES, 'UTF-8'); ?></p>
                                </div>
                                <span class="rounded-full bg-gray-100 px-2.5 py-1 text-xs font-semibold text-gray-600"><?php echo htmlspecialchars($subject['type'] === 'self' ? '客户' : ($subject['type'] === 'industry' ? '基线' : '竞品'), ENT_QUOTES, 'UTF-8'); ?></span>
                            </div>
                            <div class="mt-4 grid grid-cols-3 gap-2 text-center text-xs">
                                <div class="rounded-lg bg-white p-2"><strong class="block text-base text-gray-900"><?php echo htmlspecialchars($subject['mentionRate'], ENT_QUOTES, 'UTF-8'); ?></strong>提及率</div>
                                <div class="rounded-lg bg-white p-2"><strong class="block text-base text-gray-900"><?php echo htmlspecialchars((string) $subject['appearances'], ENT_QUOTES, 'UTF-8'); ?></strong>呈现次数</div>
                                <div class="rounded-lg bg-white p-2"><strong class="block text-base text-gray-900"><?php echo htmlspecialchars($subject['avgRank'], ENT_QUOTES, 'UTF-8'); ?></strong>平均排名</div>
                            </div>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div data-monitor-panel="alerts" class="monitor-panel hidden p-6">
            <div class="grid grid-cols-1 gap-4 lg:grid-cols-4">
                <div class="lg:col-span-3 grid grid-cols-1 gap-4 xl:grid-cols-2">
                    <?php
                    // 只展示真实告警；无数据时展示空状态，避免样例混淆业务判断。
                    $displayAlerts = $realAlerts;
                    $isRealAlertData = $hasRealAlerts;
                    ?>
                    <?php if (!$isRealAlertData): ?>
                        <div class="xl:col-span-2 rounded-xl border border-gray-200 bg-white p-8 text-center">
                            <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-emerald-50 text-emerald-600">
                                <i data-lucide="check-circle-2" class="h-6 w-6"></i>
                            </div>
                            <h3 class="mt-4 text-lg font-bold text-gray-900">暂无真实异常告警</h3>
                            <p class="mx-auto mt-2 max-w-xl text-sm leading-6 text-gray-600">
                                当前客户有 <?php echo (int) $realMonitorData['total']; ?> 条监测记录、<?php echo count($platformStats); ?> 个平台统计，但还没有触发竞品超越或命中率异常。告警只会在每日监测脚本写入 <code class="rounded bg-gray-100 px-1">geo_monitor_alerts</code> 后出现。
                            </p>
                            <div class="mt-5 flex flex-wrap justify-center gap-3 text-sm">
                                <a href="<?php echo htmlspecialchars(admin_url('customers.php')); ?>" class="rounded-lg border border-gray-300 bg-white px-4 py-2 font-semibold text-gray-700 hover:bg-gray-50">检查客户关键词</a>
                                <a href="<?php echo htmlspecialchars(admin_url('monitor-cookies.php')); ?>" class="rounded-lg border border-indigo-300 bg-indigo-50 px-4 py-2 font-semibold text-indigo-700 hover:bg-indigo-100">🤖 配置 AI 平台 Cookie</a>
                                <a href="<?php echo htmlspecialchars(admin_url('geo-monitor.php')); ?>" class="rounded-lg bg-slate-900 px-4 py-2 font-semibold text-white hover:bg-slate-700">刷新监测页</a>
                            </div>
                        </div>
                    <?php endif; ?>
                    <?php foreach ($displayAlerts as $alert): ?>
                        <?php
                        $alertLevel = $isRealAlertData ? ($alert['level'] ?? 'low') : ($alert['level'] ?? 'low');
                        $alertClass = [
                            'high'   => 'border-red-200 bg-red-50',
                            'medium' => 'border-orange-200 bg-orange-50',
                            'low'    => 'border-gray-200 bg-gray-50',
                        ][$alertLevel] ?? 'border-gray-200 bg-gray-50';
                        $levelText = ['high' => 'HIGH', 'medium' => 'MEDIUM', 'low' => 'LOW'][$alertLevel] ?? 'LOW';

                        if ($isRealAlertData) {
                            $alertTitle = match($alert['alert_type'] ?? '') {
                                'competitor_surpass' => '竞品「' . htmlspecialchars($alert['competitor_name'], ENT_QUOTES, 'UTF-8') . '」超越品牌',
                                'keyword_zero_visibility' => '关键词「' . htmlspecialchars($alert['keyword'], ENT_QUOTES, 'UTF-8') . '」零可见',
                                'core_rate_low' => '核心关键词平均提及率过低',
                                'source_diversity_low' => '品牌提及来源覆盖不足',
                                'visibility_drop' => '品牌提及率周环比下跌',
                                'accuracy_low' => 'AI 回答语义准确度不足',
                                default              => htmlspecialchars($alert['alert_type'] ?? '', ENT_QUOTES, 'UTF-8'),
                            };
                            $alertDesc = htmlspecialchars($alert['detail'] ?? '', ENT_QUOTES, 'UTF-8');
                            $alertDate = $alert['alerted_at'] ?? '';
                        } else {
                            $alertTitle = htmlspecialchars($alert['title'] ?? '', ENT_QUOTES, 'UTF-8');
                            $alertDesc  = htmlspecialchars($alert['desc']  ?? '', ENT_QUOTES, 'UTF-8');
                            $alertDate  = '';
                        }
                        ?>
                        <article class="rounded-xl border <?php echo $alertClass; ?> p-5">
                            <div class="flex items-center justify-between gap-3">
                                <span class="rounded-full bg-white/80 px-3 py-1 text-xs font-bold <?php echo $alertLevel === 'high' ? 'text-red-700' : 'text-gray-600'; ?>"><?php echo $levelText; ?></span>
                                <?php if ($alertDate): ?>
                                    <span class="text-xs text-gray-400"><?php echo htmlspecialchars($alertDate, ENT_QUOTES, 'UTF-8'); ?></span>
                                <?php endif; ?>
                            </div>
                            <h3 class="mt-4 text-lg font-bold text-gray-900"><?php echo $alertTitle; ?></h3>
                            <p class="mt-2 text-sm leading-6 text-gray-700"><?php echo $alertDesc; ?></p>
                            <?php if ($isRealAlertData && ($alert['alert_type'] ?? '') === 'competitor_surpass'): ?>
                                <div class="mt-4 grid grid-cols-2 gap-2 rounded-lg bg-white/80 p-3 text-sm">
                                    <div class="text-center">
                                        <div class="text-xl font-bold text-blue-700"><?php echo $alert['brand_rate']; ?>%</div>
                                        <div class="text-xs text-gray-500">品牌提及率</div>
                                    </div>
                                    <div class="text-center">
                                        <div class="text-xl font-bold text-red-600"><?php echo $alert['competitor_rate']; ?>%</div>
                                        <div class="text-xs text-gray-500">竞品提及率</div>
                                    </div>
                                </div>
                                <div class="mt-3 rounded-lg bg-white/80 p-3 text-sm text-gray-700">
                                    <span class="font-semibold">建议处置：</span>针对关键词「<?php echo htmlspecialchars($alert['keyword'] ?? '', ENT_QUOTES, 'UTF-8'); ?>」补充信源文章，压制竞品曝光。
                                </div>
                            <?php elseif ($isRealAlertData): ?>
                                <?php
                                $actionText = match($alert['alert_type'] ?? '') {
                                    'keyword_zero_visibility' => '优先补充该关键词的问答型内容、品牌事实页和第三方信源，发布后重新跑监测。',
                                    'core_rate_low' => '检查低提及关键词，补齐品牌母句、服务边界、案例证据和可引用摘要。',
                                    'source_diversity_low' => '增加不同平台的可索引信源，至少覆盖官网、问答平台和第三方内容平台。',
                                    'visibility_drop' => '对比下跌前后的关键词和平台，优先修复跌幅最大的内容入口。',
                                    'accuracy_low' => '修正品牌知识库中的事实口径，并补充可核验来源，降低 AI 误答。',
                                    default => '进入本周优化清单，补充内容、信源和复测关键词。',
                                };
                                ?>
                                <div class="mt-4 grid grid-cols-2 gap-2 rounded-lg bg-white/80 p-3 text-sm">
                                    <div class="text-center">
                                        <div class="text-xl font-bold text-blue-700"><?php echo htmlspecialchars((string) $alert['brand_rate'], ENT_QUOTES, 'UTF-8'); ?></div>
                                        <div class="text-xs text-gray-500">当前指标</div>
                                    </div>
                                    <div class="text-center">
                                        <div class="text-xl font-bold text-slate-700"><?php echo htmlspecialchars((string) $alert['competitor_rate'], ENT_QUOTES, 'UTF-8'); ?></div>
                                        <div class="text-xs text-gray-500">阈值/对照</div>
                                    </div>
                                </div>
                                <div class="mt-3 rounded-lg bg-white/80 p-3 text-sm text-gray-700">
                                    <span class="font-semibold">建议处置：</span><?php echo htmlspecialchars($actionText, ENT_QUOTES, 'UTF-8'); ?>
                                </div>
                            <?php elseif (!$isRealAlertData): ?>
                                <div class="mt-4 rounded-lg bg-white/80 p-3 text-sm text-gray-700">
                                    <p><span class="font-semibold">阈值：</span><?php echo htmlspecialchars($alert['threshold'] ?? '', ENT_QUOTES, 'UTF-8'); ?></p>
                                    <p class="mt-1"><span class="font-semibold">处置：</span><?php echo htmlspecialchars($alert['action'] ?? '', ENT_QUOTES, 'UTF-8'); ?></p>
                                </div>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
                <div class="rounded-xl border border-gray-200 bg-white p-5">
                    <h3 class="text-lg font-bold text-gray-900">告警规则说明</h3>
                    <p class="mt-1 text-xs text-gray-500">系统根据以下规则自动生成异常告警</p>
                    <div class="mt-4 space-y-3">
                        <?php foreach ($thresholdRows as $row): ?>
                            <div class="rounded-lg border-l-4 <?php echo $row['level'] === 'HIGH' ? 'border-red-400 bg-red-50' : ($row['level'] === 'MEDIUM' ? 'border-orange-400 bg-orange-50' : 'border-green-400 bg-green-50'); ?> p-3">
                                <div class="flex items-center gap-2">
                                    <span class="text-base"><?php echo $row['icon']; ?></span>
                                    <span class="text-sm font-bold text-gray-900"><?php echo htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8'); ?></span>
                                    <span class="rounded-full px-2 py-0.5 text-xs font-bold <?php echo $row['level'] === 'HIGH' ? 'bg-red-200 text-red-800' : ($row['level'] === 'MEDIUM' ? 'bg-orange-200 text-orange-800' : 'bg-green-200 text-green-800'); ?>"><?php echo htmlspecialchars($row['level'], ENT_QUOTES, 'UTF-8'); ?></span>
                                </div>
                                <p class="mt-2 text-xs leading-5 text-gray-700"><?php echo htmlspecialchars($row['rule'], ENT_QUOTES, 'UTF-8'); ?></p>
                                <div class="mt-2 flex items-start gap-1 text-xs text-gray-600">
                                    <span class="mt-0.5 shrink-0 font-semibold text-gray-500">常见原因：</span>
                                    <span><?php echo htmlspecialchars($row['cause'], ENT_QUOTES, 'UTF-8'); ?></span>
                                </div>
                                <div class="mt-1 flex items-start gap-1 text-xs text-gray-600">
                                    <span class="mt-0.5 shrink-0 font-semibold text-gray-500">建议处置：</span>
                                    <span class="font-medium text-gray-800"><?php echo htmlspecialchars($row['action'], ENT_QUOTES, 'UTF-8'); ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <div data-monitor-panel="conversations" class="monitor-panel hidden p-6">
            <div class="mb-5 grid grid-cols-1 gap-3 md:grid-cols-4">
                <label class="block">
                    <span class="text-sm font-semibold text-gray-600">对话平台</span>
                    <select id="conversation-platform-filter" class="mt-1 w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm">
                        <option value="">全部平台</option>
                        <option value="豆包">豆包</option>
                        <option value="Kimi">Kimi</option>
                        <option value="DeepSeek">DeepSeek</option>
                        <option value="通义">通义</option>
                    </select>
                </label>
                <label class="block">
                    <span class="text-sm font-semibold text-gray-600">竞品词</span>
                    <select id="conversation-competitor-filter" class="mt-1 w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm">
                        <option value="">全部竞品</option>
                        <?php foreach ($competitorsFromCustomer as $competitor): ?>
                            <option value="<?php echo htmlspecialchars($competitor, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($competitor, ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="block">
                    <span class="text-sm font-semibold text-gray-600">日期</span>
                    <select id="conversation-date-filter" class="mt-1 w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm">
                        <option value="">全部日期</option>
                        <?php
                        $convMonths = [];
                        foreach ($conversations as $cv) {
                            $m = substr($cv['date'], 0, 7);
                            if ($m) $convMonths[$m] = true;
                        }
                        krsort($convMonths);
                        foreach (array_keys($convMonths) as $m): ?>
                            <option value="<?php echo htmlspecialchars($m, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($m, ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <div class="flex items-end">
                    <button type="button" id="export-conversations" class="w-full rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">导出 CSV</button>
                </div>
            </div>

            <?php if (empty($conversations)): ?>
                <div class="rounded-xl border border-gray-200 bg-gray-50 p-8 text-center">
                    <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-white text-gray-500">
                        <i data-lucide="message-square" class="h-6 w-6"></i>
                    </div>
                    <h3 class="mt-4 text-lg font-bold text-gray-900">暂无真实 AI 回答原文</h3>
                    <p class="mx-auto mt-2 max-w-xl text-sm leading-6 text-gray-600">启动真实监测后，模型返回内容会写入 <code class="rounded bg-gray-100 px-1">geo_monitor_records.full_response</code>，这里才会展示。</p>
                </div>
            <?php else: ?>
            <div class="overflow-x-auto rounded-xl border border-gray-200">
                <table class="min-w-[1120px] w-full divide-y divide-gray-200 text-left text-sm">
                    <thead class="bg-gray-50 text-xs font-bold uppercase text-gray-500">
                        <tr>
                            <th class="px-4 py-3">对话时间</th>
                            <th class="px-4 py-3">AI问题</th>
                            <th class="px-4 py-3">问题类型</th>
                            <th class="px-4 py-3">热度</th>
                            <th class="px-4 py-3">平台</th>
                            <th class="px-4 py-3">品牌词</th>
                            <th class="px-4 py-3">竞品词</th>
                            <th class="px-4 py-3">引用来源</th>
                            <th class="px-4 py-3">操作</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 bg-white" id="conversation-table-body">
                        <?php foreach ($conversations as $item): ?>
                            <tr data-platform="<?php echo htmlspecialchars($item['platform'], ENT_QUOTES, 'UTF-8'); ?>" data-date="<?php echo htmlspecialchars(substr($item['date'], 0, 7), ENT_QUOTES, 'UTF-8'); ?>" data-competitors="<?php echo htmlspecialchars(implode(',', $item['competitors']), ENT_QUOTES, 'UTF-8'); ?>">
                                <td class="px-4 py-4 font-medium text-gray-900"><?php echo htmlspecialchars($item['date'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="px-4 py-4 font-semibold text-gray-900"><?php echo htmlspecialchars($item['question'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="px-4 py-4 text-gray-600"><?php echo htmlspecialchars($item['type'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="px-4 py-4"><span class="rounded-full bg-orange-100 px-2 py-1 text-xs font-bold text-orange-700"><?php echo htmlspecialchars((string) $item['heat'], ENT_QUOTES, 'UTF-8'); ?></span></td>
                                <td class="px-4 py-4 text-gray-600"><?php echo htmlspecialchars($item['platform'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="px-4 py-4 font-bold text-gray-900"><?php echo htmlspecialchars((string) $item['brandMentions'], ENT_QUOTES, 'UTF-8'); ?> 次</td>
                                <td class="px-4 py-4">
                                    <?php foreach ($item['competitors'] as $term): ?>
                                        <button type="button" data-competitor-tag="<?php echo htmlspecialchars($term, ENT_QUOTES, 'UTF-8'); ?>" class="mr-1 inline-flex rounded-full bg-orange-100 px-2 py-1 text-xs font-semibold text-orange-700 hover:bg-orange-200"><?php echo htmlspecialchars($term, ENT_QUOTES, 'UTF-8'); ?></button>
                                    <?php endforeach; ?>
                                </td>
                                <td class="px-4 py-4 text-gray-600"><?php echo htmlspecialchars((string) $item['citationCount'], ENT_QUOTES, 'UTF-8'); ?> 个来源</td>
                                <td class="px-4 py-4">
                                    <button type="button" data-open-conversation="<?php echo htmlspecialchars($item['id'], ENT_QUOTES, 'UTF-8'); ?>" class="rounded-lg bg-slate-900 px-3 py-2 text-xs font-semibold text-white hover:bg-slate-700">查看原话</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <div data-monitor-panel="renewal" class="monitor-panel hidden p-6">
            <div class="grid grid-cols-1 gap-4 lg:grid-cols-4">
                <?php foreach ($renewalItems as $item): ?>
                    <div class="rounded-xl border border-gray-200 bg-white p-5">
                        <p class="text-sm font-semibold text-gray-500"><?php echo htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8'); ?></p>
                        <div class="mt-2 text-2xl font-bold text-gray-900"><?php echo htmlspecialchars($item['value'], ENT_QUOTES, 'UTF-8'); ?></div>
                        <p class="mt-3 text-sm leading-6 text-gray-600"><?php echo htmlspecialchars($item['desc'], ENT_QUOTES, 'UTF-8'); ?></p>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="mt-6 grid grid-cols-1 gap-6 xl:grid-cols-[minmax(0,1fr)_360px]">
                <div class="rounded-xl border border-blue-200 bg-blue-50 p-5">
                    <h3 class="text-lg font-bold text-gray-900">续费证据包摘要</h3>
                    <p class="mt-2 text-sm leading-6 text-gray-700">
                        <?php if ($hasRealData): ?>
                            本月已沉淀 <?php echo (int) $realMonitorData['total']; ?> 条真实监测记录，其中 <?php echo (int) $realMonitorData['mentioned']; ?> 条提及品牌。当前证据包只汇总真实回答、真实告警和真实趋势。
                        <?php else: ?>
                            当前还没有可用于续费复盘的真实监测记录。先启动监测，证据包会自动引用 AI 原话和告警数据。
                        <?php endif; ?>
                    </p>
                    <div class="mt-4 flex flex-wrap gap-2">
                        <?php foreach ($conversations as $item): ?>
                            <button type="button" data-open-conversation="<?php echo htmlspecialchars($item['id'], ENT_QUOTES, 'UTF-8'); ?>" class="rounded-full bg-white px-3 py-1.5 text-xs font-semibold text-blue-700 shadow-sm"><?php echo htmlspecialchars($item['question'], ENT_QUOTES, 'UTF-8'); ?></button>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="rounded-xl border border-gray-200 bg-white p-5">
                    <h3 class="text-lg font-bold text-gray-900">反查配额状态</h3>
                    <div class="mt-4 space-y-3">
                        <?php foreach ($quotaRows as $row): ?>
                            <div class="flex items-center justify-between gap-3 rounded-lg bg-gray-50 p-3 text-sm">
                                <div>
                                    <div class="font-bold text-gray-900"><?php echo htmlspecialchars($row['provider'], ENT_QUOTES, 'UTF-8'); ?></div>
                                    <div class="text-xs text-gray-500"><?php echo htmlspecialchars($row['method'], ENT_QUOTES, 'UTF-8'); ?> · <?php echo htmlspecialchars($row['quota'], ENT_QUOTES, 'UTF-8'); ?></div>
                                </div>
                                <span class="rounded-full px-2.5 py-1 text-xs font-bold <?php echo $row['status'] === '正常' ? 'bg-emerald-100 text-emerald-700' : 'bg-orange-100 text-orange-700'; ?>"><?php echo htmlspecialchars($row['status'], ENT_QUOTES, 'UTF-8'); ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <div data-monitor-panel="platforms" class="monitor-panel hidden p-6">
            <div class="mb-5 flex items-center justify-between">
                <div>
                    <h3 class="text-lg font-bold text-gray-900">六大平台覆盖率
                        <span class="ml-2 text-sm font-normal text-gray-400">近30天</span>
                    </h3>
                    <p class="mt-1 text-sm text-gray-500">
                        <?php if (empty($platformStats)): ?>
                            尚未采集到监测数据，每日脚本运行后自动填充。
                        <?php else: ?>
                            基于 <?php echo array_sum(array_column($platformStats, 'total')); ?> 次查询计算，覆盖品牌提及率、核心信息呈现率和语义准确度。
                        <?php endif; ?>
                    </p>
                </div>
            </div>
            <div style="display:flex; flex-wrap:wrap; gap:16px;">
                <?php foreach ($platformDisplay as $pkey => $pdisp):
                    $stat = $platformStats[$pkey] ?? null;
                    $mentionRate = $stat ? $stat['mention_rate'] : null;
                    $coreRate    = $stat ? $stat['core_rate']    : null;
                    $avgAcc      = $stat ? $stat['avg_accuracy'] : null;

                    if ($mentionRate === null) {
                        $cardStyle = 'border:1px solid #e5e7eb; background:#f9fafb;';
                        $rateColor = '#d1d5db';
                        $barBg     = '#e5e7eb';
                        $barFill   = '#d1d5db';
                        $barWidth  = 0;
                    } elseif ($mentionRate >= 70) {
                        $cardStyle = 'border:1px solid #a7f3d0; background:#ecfdf5;';
                        $rateColor = '#059669';
                        $barBg     = '#d1fae5';
                        $barFill   = '#10b981';
                        $barWidth  = $mentionRate;
                    } elseif ($mentionRate >= 40) {
                        $cardStyle = 'border:1px solid #bfdbfe; background:#eff6ff;';
                        $rateColor = '#2563eb';
                        $barBg     = '#dbeafe';
                        $barFill   = '#3b82f6';
                        $barWidth  = $mentionRate;
                    } else {
                        $cardStyle = 'border:1px solid #fecaca; background:#fef2f2;';
                        $rateColor = '#dc2626';
                        $barBg     = '#fee2e2';
                        $barFill   = '#f87171';
                        $barWidth  = $mentionRate ?? 0;
                    }

                    if ($coreRate !== null) {
                        $coreColor = $coreRate >= 80 ? '#059669' : ($coreRate >= 50 ? '#2563eb' : '#f97316');
                    }
                    if ($avgAcc !== null) {
                        $accColor = $avgAcc >= 90 ? '#059669' : ($avgAcc >= 70 ? '#f97316' : '#dc2626');
                    }
                ?>
                    <div style="flex:1 1 140px; min-width:140px; max-width:200px; border-radius:12px; padding:16px; <?php echo $cardStyle; ?>">
                        <div>
                            <span style="font-size:15px; font-weight:700; color:#1f2937;"><?php echo htmlspecialchars($pdisp['label'], ENT_QUOTES, 'UTF-8'); ?></span>
                        </div>
                        <p style="font-size:10px; color:#9ca3af; margin-top:2px; min-height:14px;">
                            <?php echo $pdisp['combo'] ? htmlspecialchars($pdisp['combo'], ENT_QUOTES, 'UTF-8') : ''; ?>
                        </p>
                        <div style="margin-top:12px; font-size:28px; font-weight:800; color:<?php echo $rateColor; ?>;">
                            <?php echo $mentionRate !== null ? $mentionRate . '%' : '—'; ?>
                        </div>
                        <p style="font-size:11px; color:#9ca3af;">提及率</p>
                        <div style="margin-top:10px; height:4px; border-radius:4px; background:<?php echo $barBg; ?>;">
                            <div style="height:4px; border-radius:4px; background:<?php echo $barFill; ?>; width:<?php echo $barWidth; ?>%;"></div>
                        </div>
                        <?php if ($stat): ?>
                            <div style="margin-top:12px; display:flex; flex-direction:column; gap:6px; font-size:11px;">
                                <div style="display:flex; justify-content:space-between;">
                                    <span style="color:#6b7280;">核心呈现</span>
                                    <span style="font-weight:700; color:<?php echo $coreColor; ?>;"><?php echo $coreRate; ?>%</span>
                                </div>
                                <?php if ($avgAcc !== null): ?>
                                <div style="display:flex; justify-content:space-between;">
                                    <span style="color:#6b7280;">语义准确</span>
                                    <span style="font-weight:700; color:<?php echo $accColor; ?>;"><?php echo $avgAcc; ?></span>
                                </div>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <p style="margin-top:12px; font-size:11px; color:#9ca3af;">待监测</p>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- 文章采信率面板 -->
        <div data-monitor-panel="articles" class="monitor-panel hidden p-6">
            <div class="mb-5">
                <h3 class="text-lg font-bold text-gray-900">文章采信率
                    <span class="ml-2 text-sm font-normal text-gray-400">近30天</span>
                </h3>
                <p class="mt-1 text-sm text-gray-500">
                    统计已关联监测关键词的文章，在AI回答中被引用的情况。<br>
                    <span style="display:inline-flex;gap:16px;margin-top:4px;">
                        <span style="font-size:12px;"><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#059669;margin-right:4px;"></span>内容+品牌双命中（最佳）</span>
                        <span style="font-size:12px;"><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#f59e0b;margin-right:4px;"></span>内容被引用但品牌未出现（内容泄漏）</span>
                        <span style="font-size:12px;"><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#6366f1;margin-right:4px;"></span>品牌出现但内容未被引用</span>
                        <span style="font-size:12px;"><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#d1d5db;margin-right:4px;"></span>尚未命中</span>
                    </span>
                </p>
            </div>

            <?php if (empty($articleAdoption)): ?>
                <div style="text-align:center; padding:60px 20px; color:#9ca3af;">
                    <p style="font-size:15px;">暂无数据</p>
                    <p style="font-size:13px; margin-top:8px;">需先在关键词管理中将关键词关联到文章，监测脚本运行后自动统计。</p>
                </div>
            <?php else: ?>
                <div style="overflow-x:auto;">
                    <table style="width:100%; border-collapse:collapse; font-size:13px;">
                        <thead>
                            <tr style="border-bottom:2px solid #e5e7eb;">
                                <th style="text-align:left; padding:8px 12px; color:#6b7280; font-weight:600;">文章标题</th>
                                <th style="text-align:left; padding:8px 12px; color:#6b7280; font-weight:600;">监测关键词</th>
                                <th style="text-align:center; padding:8px 12px; color:#6b7280; font-weight:600;">查询次数</th>
                                <th style="text-align:center; padding:8px 12px; color:#6b7280; font-weight:600;">品牌提及</th>
                                <th style="text-align:center; padding:8px 12px; color:#6b7280; font-weight:600;">深度引用<br><span style="font-weight:400;font-size:11px;">depth≥2</span></th>
                                <th style="text-align:center; padding:8px 12px; color:#6b7280; font-weight:600;">指纹命中<br><span style="font-weight:400;font-size:11px;">内容确认</span></th>
                                <th style="text-align:center; padding:8px 12px; color:#6b7280; font-weight:600;">覆盖平台</th>
                                <th style="text-align:center; padding:8px 12px; color:#6b7280; font-weight:600;">状态</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($articleAdoption as $artRow):
                            $totalQ   = (int)$artRow['total_queries'];
                            $brandH   = (int)$artRow['brand_hits'];
                            $deepH    = (int)$artRow['deep_hits'];
                            $fpH      = (int)$artRow['fingerprint_hits'];
                            $platC    = (int)$artRow['platform_count'];
                            $hasFp    = !empty($artRow['source_fingerprints']) && $artRow['source_fingerprints'] !== '[]';

                            // 四象限状态判断
                            $contentCited = $fpH > 0 || $deepH > 0;
                            $brandCited   = $brandH > 0;

                            if ($contentCited && $brandCited) {
                                $statusDot   = '#059669'; $statusText = '双命中'; $statusBg = '#ecfdf5'; $statusColor = '#059669';
                            } elseif ($contentCited && !$brandCited) {
                                $statusDot   = '#f59e0b'; $statusText = '内容泄漏'; $statusBg = '#fffbeb'; $statusColor = '#b45309';
                            } elseif (!$contentCited && $brandCited) {
                                $statusDot   = '#6366f1'; $statusText = '品牌可见'; $statusBg = '#eef2ff'; $statusColor = '#4338ca';
                            } elseif ($totalQ > 0) {
                                $statusDot   = '#d1d5db'; $statusText = '未命中'; $statusBg = '#f9fafb'; $statusColor = '#6b7280';
                            } else {
                                $statusDot   = '#d1d5db'; $statusText = '待监测'; $statusBg = '#f9fafb'; $statusColor = '#9ca3af';
                            }

                            $adoptRate = $totalQ > 0 ? round($deepH / $totalQ * 100) : 0;
                        ?>
                            <tr style="border-bottom:1px solid #f3f4f6; <?php echo $brandCited || $contentCited ? '' : 'opacity:0.7;'; ?>">
                                <td style="padding:10px 12px; max-width:260px;">
                                    <a href="<?php echo admin_url('article-view.php?id=' . $artRow['article_id']); ?>"
                                       target="_blank"
                                       style="color:#1d4ed8; font-weight:600; text-decoration:none; display:block; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;"
                                       title="<?php echo htmlspecialchars($artRow['title'], ENT_QUOTES); ?>">
                                        <?php echo htmlspecialchars(mb_substr($artRow['title'], 0, 30, 'UTF-8'), ENT_QUOTES); ?>
                                        <?php echo mb_strlen($artRow['title'], 'UTF-8') > 30 ? '…' : ''; ?>
                                    </a>
                                    <span style="font-size:11px; color:#9ca3af;">
                                        <?php echo date('Y-m-d', strtotime($artRow['created_at'])); ?>
                                        <?php if (!$hasFp): ?>
                                            · <span style="color:#f59e0b;" title="关联文章后下次监测自动提取">指纹待提取</span>
                                        <?php endif; ?>
                                    </span>
                                </td>
                                <td style="padding:10px 12px; color:#374151;">
                                    <span style="background:#f3f4f6; border-radius:4px; padding:2px 6px; font-size:12px;">
                                        <?php echo htmlspecialchars($artRow['keyword'], ENT_QUOTES); ?>
                                    </span>
                                </td>
                                <td style="padding:10px 12px; text-align:center; color:#374151;">
                                    <?php echo $totalQ > 0 ? $totalQ : '<span style="color:#d1d5db;">—</span>'; ?>
                                </td>
                                <td style="padding:10px 12px; text-align:center;">
                                    <?php if ($brandH > 0): ?>
                                        <span style="color:#059669; font-weight:700;"><?php echo $brandH; ?></span>
                                    <?php else: ?>
                                        <span style="color:#d1d5db;">0</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding:10px 12px; text-align:center;">
                                    <?php if ($deepH > 0): ?>
                                        <span style="font-weight:700; color:#059669;"><?php echo $deepH; ?></span>
                                        <span style="font-size:11px; color:#9ca3af;"> (<?php echo $adoptRate; ?>%)</span>
                                    <?php else: ?>
                                        <span style="color:#d1d5db;">0</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding:10px 12px; text-align:center;">
                                    <?php if ($fpH > 0): ?>
                                        <span style="font-weight:700; color:#6366f1;"><?php echo $fpH; ?></span>
                                    <?php elseif ($hasFp): ?>
                                        <span style="color:#d1d5db;">0</span>
                                    <?php else: ?>
                                        <span style="font-size:11px; color:#f59e0b;">待提取</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding:10px 12px; text-align:center; color:#374151;">
                                    <?php echo $platC > 0 ? $platC . '/6' : '<span style="color:#d1d5db;">—</span>'; ?>
                                </td>
                                <td style="padding:10px 12px; text-align:center;">
                                    <span style="display:inline-flex; align-items:center; gap:4px; padding:2px 8px; border-radius:9999px; font-size:11px; font-weight:600; background:<?php echo $statusBg; ?>; color:<?php echo $statusColor; ?>;">
                                        <span style="width:6px;height:6px;border-radius:50%;background:<?php echo $statusDot; ?>;"></span>
                                        <?php echo $statusText; ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- 汇总说明 -->
                <?php
                $totalArts   = count($articleAdoption);
                $doubleHit   = count(array_filter($articleAdoption, fn($r) => (int)$r['fingerprint_hits'] > 0 || (int)$r['deep_hits'] > 0 && (int)$r['brand_hits'] > 0));
                $leakCount   = count(array_filter($articleAdoption, fn($r) => ((int)$r['fingerprint_hits'] > 0 || (int)$r['deep_hits'] > 0) && (int)$r['brand_hits'] === 0));
                ?>
                <?php if ($leakCount > 0): ?>
                <div style="margin-top:16px; padding:12px 16px; background:#fffbeb; border:1px solid #fde68a; border-radius:8px; font-size:13px; color:#92400e;">
                    <strong>内容泄漏预警：</strong>
                    有 <?php echo $leakCount; ?> 篇文章内容被AI引用但品牌未出现。建议在这些文章中加强品牌绑定——每段落加入品牌名或母句，让AI在复述内容时同步提及品牌。
                </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </section>
</div>

<div id="conversation-modal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/60 p-4">
    <div class="max-h-[90vh] w-full max-w-3xl overflow-y-auto rounded-2xl bg-white shadow-xl">
        <div class="flex items-start justify-between gap-4 border-b border-gray-200 p-6">
            <div>
                <p id="modal-meta" class="text-sm font-semibold text-blue-600"></p>
                <h3 id="modal-question" class="mt-2 text-2xl font-bold text-gray-900"></h3>
            </div>
            <button type="button" id="close-conversation-modal" class="rounded-lg p-2 text-gray-500 hover:bg-gray-100" aria-label="关闭">
                <i data-lucide="x" class="h-5 w-5"></i>
            </button>
        </div>
        <div class="space-y-5 p-6">
            <div class="rounded-xl bg-gray-50 p-5">
                <p class="text-sm font-semibold text-gray-500">AI 原话</p>
                <p id="modal-answer" class="mt-3 text-base leading-8 text-gray-800"></p>
            </div>
            <div>
                <p class="text-sm font-semibold text-gray-500">引用来源</p>
                <div id="modal-citations" class="mt-3 flex flex-wrap gap-2"></div>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    function switchMonitorTab(target) {
        if (!target) return false;
        document.querySelectorAll('[data-monitor-tab]').forEach(function (tab) {
            var active = tab.getAttribute('data-monitor-tab') === target;
            tab.classList.toggle('bg-slate-900', active);
            tab.classList.toggle('text-white', active);
            tab.classList.toggle('text-gray-600', !active);
            tab.classList.toggle('hover:bg-gray-100', !active);
            tab.setAttribute('aria-selected', active ? 'true' : 'false');
            var desc = tab.querySelector('[data-monitor-tab-desc]');
            if (desc) {
                desc.classList.toggle('text-slate-300', active);
                desc.classList.toggle('text-gray-400', !active);
            }
        });
        document.querySelectorAll('[data-monitor-panel]').forEach(function (panel) {
            panel.classList.toggle('hidden', panel.getAttribute('data-monitor-panel') !== target);
        });
        document.dispatchEvent(new CustomEvent('geo-monitor-tab-change', { detail: { target: target } }));
        return false;
    }

    window.geoMonitorSwitchTab = switchMonitorTab;
    document.addEventListener('click', function (event) {
        var tab = event.target.closest('[data-monitor-tab]');
        if (tab) {
            event.preventDefault();
            switchMonitorTab(tab.getAttribute('data-monitor-tab'));
            return;
        }
        var opener = event.target.closest('[data-open-monitor-tab]');
        if (opener) {
            event.preventDefault();
            switchMonitorTab(opener.getAttribute('data-open-monitor-tab'));
        }
    }, true);
})();
</script>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const trendPoints = <?php echo json_encode($trendPoints, $jsonOptions); ?>;
    const radarDimensions = <?php echo json_encode($radarDimensions, $jsonOptions); ?>;
    const radarSubjects = <?php echo json_encode($radarSubjects, $jsonOptions); ?>;
    const conversations = <?php echo json_encode($conversations, $jsonOptions); ?>;
    const monitorApiUrl = <?php echo json_encode(admin_url('api/geo-monitor-run.php'), $jsonOptions); ?>;
    const monitorCustomerId = <?php echo json_encode($customerId, $jsonOptions); ?>;
    const monitorCsrfToken = <?php echo json_encode($monitorCsrfToken, $jsonOptions); ?>;
    const monitorCanStart = <?php echo json_encode($enabledKeywordCount > 0 && $providerCount > 0); ?>;

    const tabs = document.querySelectorAll('[data-monitor-tab]');
    const panels = document.querySelectorAll('[data-monitor-panel]');

    function activateTab(target) {
        if (window.geoMonitorSwitchTab) window.geoMonitorSwitchTab(target);
        if (target === 'trend') renderTrendChart();
        if (target === 'competitors') renderRadarChart(0);
    }

    document.addEventListener('click', (event) => {
        const tabButton = event.target.closest('[data-monitor-tab]');
        if (tabButton) {
            event.preventDefault();
            activateTab(tabButton.dataset.monitorTab);
            return;
        }

        const opener = event.target.closest('[data-open-monitor-tab]');
        if (opener) {
            event.preventDefault();
            activateTab(opener.dataset.openMonitorTab);
        }
    }, true);

    function renderTrendChart() {
        const svg = document.getElementById('trend-chart');
        const emptyEl = document.getElementById('trend-empty');
        if (!svg) return;
        const activeRange = document.querySelector('[data-trend-range].bg-blue-600')?.dataset.trendRange || '30';
        const count = activeRange === '7' ? 7 : (activeRange === '30' ? 30 : trendPoints.length);
        const points = trendPoints.slice(-count);

        // 数据不足时显示空状态
        if (points.length < 2) {
            svg.classList.add('hidden');
            if (emptyEl) emptyEl.classList.remove('hidden');
            return;
        }
        svg.classList.remove('hidden');
        if (emptyEl) emptyEl.classList.add('hidden');

        const width = 860;
        const height = 360;
        const padX = 56;
        const padTop = 36;
        const padBottom = 58;
        const chartHeight = height - padTop - padBottom;
        const fullChartWidth = width - padX * 2;
        const plotWidth = activeRange === '7' ? Math.min(fullChartWidth, 580) : fullChartWidth;
        const plotStartX = padX + (fullChartWidth - plotWidth) / 2;
        const plotEndX = plotStartX + plotWidth;
        const slot = plotWidth / points.length;
        const barWidth = activeRange === '7'
            ? Math.max(10, Math.min(18, slot * 0.22))
            : (activeRange === '30'
                ? Math.max(6, Math.min(13, slot * 0.5))
                : Math.max(3, Math.min(5, slot * 0.5)));
        const yFor = (value) => padTop + (100 - Number(value)) / 100 * chartHeight;
        const xFor = (index) => plotStartX + slot * index + slot / 2;
        const grid = [0, 20, 40, 60, 80, 100].map((value) => {
            const y = yFor(value);
            return `<line x1="${plotStartX}" y1="${y}" x2="${plotEndX}" y2="${y}" stroke="#E5E7EB" />
                <text x="${padX - 12}" y="${y + 4}" text-anchor="end" font-size="12" fill="#9CA3AF">${value}%</text>`;
        }).join('');
        // 品牌柱子：只画有真实数据的点
        const bars = points.map((point, index) => {
            const center = xFor(index);
            const y = yFor(point.brand);
            const h = height - padBottom - y;
            return `<rect x="${center - barWidth / 2}" y="${y}" width="${barWidth}" height="${h}" rx="7" fill="#4F83E8" opacity="0.84" />`;
        }).join('');
        // 行业均值折线：只连接有行业数据的点
        const industryPoints = points.filter(p => p.has_industry);
        let industryLine = '';
        let dots = '';
        if (industryPoints.length >= 2) {
            const lineCoords = industryPoints.map((point) => {
                const idx = points.indexOf(point);
                return `${xFor(idx).toFixed(1)},${yFor(point.industry).toFixed(1)}`;
            }).join(' ');
            industryLine = `<polyline points="${lineCoords}" fill="none" stroke="#64748B" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" />`;
            dots = industryPoints.map((point) => {
                const idx = points.indexOf(point);
                return `<circle cx="${xFor(idx)}" cy="${yFor(point.industry)}" r="${activeRange === '90' ? 3.2 : 5}" fill="#64748B" />`;
            }).join('');
        }
        const labelEvery = activeRange === '7' ? 1 : (activeRange === '30' ? 7 : 15);
        const labels = points.map((point, index) => {
            const isLast = index === points.length - 1;
            const isPrevious = index === points.length - 2;
            if (activeRange !== '7' && isPrevious && points[index + 1]?.date) return '';
            if (index !== 0 && !isLast && index % labelEvery !== 0) return '';
            const x = xFor(index);
            return `<text x="${x}" y="${height - 20}" text-anchor="middle" font-size="12" fill="#6B7280">${point.date}</text>`;
        }).join('');
        svg.innerHTML = `${grid}${bars}${industryLine}${dots}${labels}`;
    }

    document.querySelectorAll('[data-trend-range]').forEach((button) => {
        button.addEventListener('click', () => {
            document.querySelectorAll('[data-trend-range]').forEach((item) => {
                const active = item === button;
                item.classList.toggle('bg-blue-600', active);
                item.classList.toggle('text-white', active);
                item.classList.toggle('shadow-md', active);
                item.classList.toggle('shadow-blue-200', active);
                item.classList.toggle('bg-gray-100', !active);
                item.classList.toggle('text-gray-600', !active);
                item.classList.toggle('hover:bg-gray-200', !active);
            });
            renderTrendChart();
        });
    });

    const startMonitorButton = document.getElementById('start-real-monitor');
    const refreshMonitorButton = document.getElementById('refresh-monitor-status');
    const monitorStatusEl = document.getElementById('monitor-run-status');
    const monitorLogEl = document.getElementById('monitor-run-log');
    const monitorStartButtonIdleHtml = startMonitorButton?.innerHTML || '';
    let monitorPollTimer = null;

    function setMonitorStatus(message) {
        if (monitorStatusEl) monitorStatusEl.textContent = message;
    }

    function setMonitorLog(text) {
        if (!monitorLogEl) return;
        monitorLogEl.textContent = text || '暂无日志。';
        monitorLogEl.scrollTop = monitorLogEl.scrollHeight;
    }

    function setMonitorStartButton(state) {
        if (!startMonitorButton) return;
        if (state === 'starting') {
            startMonitorButton.disabled = true;
            startMonitorButton.innerHTML = '<i data-lucide="loader-2" class="mr-2 h-4 w-4 animate-spin"></i>正在启动';
        } else if (state === 'running') {
            startMonitorButton.disabled = true;
            startMonitorButton.innerHTML = '<i data-lucide="loader-2" class="mr-2 h-4 w-4 animate-spin"></i>监测运行中';
        } else {
            startMonitorButton.disabled = !monitorCanStart;
            startMonitorButton.innerHTML = monitorStartButtonIdleHtml;
        }
        if (window.lucide?.createIcons) window.lucide.createIcons();
    }

    async function fetchMonitorStatus(keepPolling = false) {
        if (!monitorCustomerId || !monitorApiUrl) return;
        const url = `${monitorApiUrl}?action=status&customer_id=${encodeURIComponent(monitorCustomerId)}&t=${Date.now()}`;
        try {
            const response = await fetch(url, { credentials: 'same-origin' });
            const data = await response.json().catch(() => ({}));
            if (!response.ok) {
                setMonitorStatus(data.error || `读取状态失败：HTTP ${response.status}`);
                return;
            }
            if (!data.success) {
                setMonitorStatus(data.error || '读取状态失败');
                return;
            }
            setMonitorLog(data.log || '暂无日志。');
            if (data.status === 'running') {
                setMonitorStatus(data.pid ? `后台监测运行中，PID ${data.pid}，正在调用真实模型并写入数据库` : '后台监测运行中，正在调用真实模型并写入数据库');
                setMonitorStartButton('running');
                if (keepPolling && !monitorPollTimer) {
                    monitorPollTimer = window.setInterval(() => fetchMonitorStatus(true), 3000);
                }
            } else {
                if (monitorPollTimer) {
                    window.clearInterval(monitorPollTimer);
                    monitorPollTimer = null;
                }
                const total = data.stats?.today_records ?? 0;
                setMonitorStatus(total > 0 ? `今日已有 ${total} 条监测记录，刷新页面可查看最新分析` : '当前没有正在运行的监测任务');
                setMonitorStartButton('idle');
            }
        } catch (error) {
            setMonitorStatus('读取状态失败：' + error.message);
        }
    }

    async function startMonitorRun() {
        if (!startMonitorButton || startMonitorButton.disabled || !monitorCanStart) return;
        setMonitorStartButton('starting');
        setMonitorStatus('正在启动后台监测任务');
        setMonitorLog('正在启动...');

        const body = new FormData();
        body.append('action', 'start');
        body.append('customer_id', monitorCustomerId);
        body.append('csrf_token', monitorCsrfToken);
        body.append('force', '1');

        try {
            const response = await fetch(monitorApiUrl, {
                method: 'POST',
                body,
                credentials: 'same-origin'
            });
            const data = await response.json().catch(() => ({}));
            if (!response.ok) {
                setMonitorStatus(data.error || `启动失败：HTTP ${response.status}`);
                setMonitorLog(data.log || '');
                setMonitorStartButton('idle');
                return;
            }
            if (!data.success) {
                setMonitorStatus(data.error || '启动失败');
                setMonitorLog(data.log || '');
                setMonitorStartButton('idle');
                return;
            }
            if (data.already_running) {
                setMonitorStatus(data.pid ? `已有监测任务运行中，PID ${data.pid}` : '已有监测任务运行中');
            } else {
                setMonitorStatus(data.pid ? `已启动后台任务 PID ${data.pid}` : '已启动后台任务');
            }
            setMonitorStartButton('running');
            setMonitorLog(data.log || '任务已启动，等待日志写入。');
            if (monitorPollTimer) window.clearInterval(monitorPollTimer);
            monitorPollTimer = window.setInterval(() => fetchMonitorStatus(true), 3000);
            fetchMonitorStatus(true);
        } catch (error) {
            setMonitorStatus('启动失败：' + error.message);
            setMonitorStartButton('idle');
        }
    }

    startMonitorButton?.addEventListener('click', startMonitorRun);
    refreshMonitorButton?.addEventListener('click', () => fetchMonitorStatus(false));
    fetchMonitorStatus(false);

    function polygonPoints(values, radius, centerX, centerY) {
        return values.map((value, index) => {
            const angle = (-90 + index * (360 / values.length)) * Math.PI / 180;
            const r = radius * (Number(value) / 100);
            return `${(centerX + Math.cos(angle) * r).toFixed(1)},${(centerY + Math.sin(angle) * r).toFixed(1)}`;
        }).join(' ');
    }

    function renderRadarChart(index) {
        const svg = document.getElementById('radar-chart');
        if (!svg) return;
        const centerX = 210;
        const centerY = 190;
        const radius = 128;
        const selected = radarSubjects[index] || radarSubjects[0];
        const self = radarSubjects.find((item) => item.type === 'self') || selected;
        const industry = radarSubjects.find((item) => item.type === 'industry') || radarSubjects[radarSubjects.length - 1];
        const rings = [25, 50, 75, 100].map((value) => {
            const points = radarDimensions.map((_, dimensionIndex) => {
                const angle = (-90 + dimensionIndex * (360 / radarDimensions.length)) * Math.PI / 180;
                const r = radius * (value / 100);
                return `${(centerX + Math.cos(angle) * r).toFixed(1)},${(centerY + Math.sin(angle) * r).toFixed(1)}`;
            }).join(' ');
            return `<polygon points="${points}" fill="none" stroke="#E5E7EB" stroke-width="1" /><text x="${centerX + 4}" y="${centerY - radius * (value / 100) + 4}" font-size="11" fill="#6B7280">${value}</text>`;
        }).join('');
        const axes = radarDimensions.map((dimension, dimensionIndex) => {
            const angle = (-90 + dimensionIndex * (360 / radarDimensions.length)) * Math.PI / 180;
            const x = centerX + Math.cos(angle) * radius;
            const y = centerY + Math.sin(angle) * radius;
            const lx = centerX + Math.cos(angle) * (radius + 40);
            const ly = centerY + Math.sin(angle) * (radius + 40);
            return `<line x1="${centerX}" y1="${centerY}" x2="${x}" y2="${y}" stroke="#E5E7EB" /><text x="${lx}" y="${ly}" text-anchor="middle" font-size="12" font-weight="700" fill="#374151">${dimension}</text>`;
        }).join('');
        const selfPoly = polygonPoints(self.radar, radius, centerX, centerY);
        const selectedPoly = polygonPoints(selected.radar, radius, centerX, centerY);
        const industryPoly = polygonPoints(industry.radar, radius, centerX, centerY);
        svg.innerHTML = `${rings}${axes}
            <polygon points="${industryPoly}" fill="none" stroke="#9CA3AF" stroke-width="3" stroke-dasharray="6 5" />
            <polygon points="${selectedPoly}" fill="#DC262622" stroke="#DC2626" stroke-width="4" />
            <polygon points="${selfPoly}" fill="#2563EB22" stroke="#2563EB" stroke-width="4" />`;
        document.querySelectorAll('.radar-subject').forEach((button) => {
            const active = Number(button.dataset.radarIndex) === index;
            button.classList.toggle('border-blue-300', active);
            button.classList.toggle('bg-blue-50', active);
            button.classList.toggle('border-gray-200', !active);
            button.classList.toggle('bg-white', !active);
        });
    }

    document.querySelectorAll('[data-radar-index]').forEach((button) => {
        button.addEventListener('click', () => renderRadarChart(Number(button.dataset.radarIndex)));
    });

    document.addEventListener('geo-monitor-tab-change', (event) => {
        const target = event.detail?.target;
        if (target === 'trend') renderTrendChart();
        if (target === 'competitors') renderRadarChart(0);
    });

    const platformFilter = document.getElementById('conversation-platform-filter');
    const competitorFilter = document.getElementById('conversation-competitor-filter');
    const dateFilter = document.getElementById('conversation-date-filter');
    const rows = document.querySelectorAll('#conversation-table-body tr');

    function filterConversations() {
        if (!platformFilter || !competitorFilter || !dateFilter) return;
        const platform = platformFilter.value;
        const competitor = competitorFilter.value;
        const date = dateFilter.value;
        rows.forEach((row) => {
            const matchPlatform = !platform || row.dataset.platform === platform;
            const matchCompetitor = !competitor || (row.dataset.competitors || '').includes(competitor);
            const matchDate = !date || row.dataset.date === date;
            row.classList.toggle('hidden', !(matchPlatform && matchCompetitor && matchDate));
        });
    }

    [platformFilter, competitorFilter, dateFilter].forEach((input) => {
        if (input) input.addEventListener('change', filterConversations);
    });
    document.querySelectorAll('[data-competitor-tag]').forEach((tag) => {
        tag.addEventListener('click', () => {
            if (!competitorFilter) return;
            competitorFilter.value = tag.dataset.competitorTag;
            activateTab('conversations');
            filterConversations();
        });
    });

    const modal = document.getElementById('conversation-modal');
    const modalMeta = document.getElementById('modal-meta');
    const modalQuestion = document.getElementById('modal-question');
    const modalAnswer = document.getElementById('modal-answer');
    const modalCitations = document.getElementById('modal-citations');

    function escapeHtml(value) {
        return String(value).replace(/[&<>"']/g, (char) => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        }[char]));
    }

    function highlightTerms(item) {
        let text = escapeHtml(item.answer);
        [...item.brandTerms, ...item.competitors].forEach((term) => {
            const escaped = escapeHtml(term);
            const cls = item.brandTerms.includes(term) ? 'bg-blue-100 text-blue-700' : 'bg-orange-100 text-orange-700';
            text = text.split(escaped).join(`<span class="rounded px-1 font-semibold ${cls}">${escaped}</span>`);
        });
        return text;
    }

    function openConversation(id) {
        const item = conversations.find((conversation) => conversation.id === id);
        if (!item || !modal || !modalMeta || !modalQuestion || !modalAnswer || !modalCitations) return;
        modalMeta.textContent = `${item.date} · ${item.platform} · ${item.type} · 热度 ${item.heat}`;
        modalQuestion.textContent = item.question;
        modalAnswer.innerHTML = highlightTerms(item);
        modalCitations.innerHTML = item.citations.map((citation) => `<span class="rounded-full bg-gray-100 px-3 py-1 text-sm font-semibold text-gray-700">${escapeHtml(citation)}</span>`).join('');
        modal.classList.remove('hidden');
        modal.classList.add('flex');
    }

    document.querySelectorAll('[data-open-conversation]').forEach((button) => {
        button.addEventListener('click', () => openConversation(button.dataset.openConversation));
    });
    document.getElementById('close-conversation-modal')?.addEventListener('click', () => {
        if (modal) {
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }
    });
    modal?.addEventListener('click', (event) => {
        if (event.target === modal) {
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }
    });

    document.getElementById('export-conversations')?.addEventListener('click', () => {
        const header = ['对话时间', 'AI问题', '问题类型', '热度值', '对话平台', '覆盖品牌词', '提及次数', '覆盖竞品词', '引用来源数'];
        const lines = conversations.map((item) => [
            item.date,
            item.question,
            item.type,
            item.heat,
            item.platform,
            item.brandTerms.join('/'),
            item.brandMentions,
            item.competitors.join('/'),
            item.citationCount
        ]);
        const csv = [header, ...lines].map((row) => row.map((cell) => `"${String(cell).replace(/"/g, '""')}"`).join(',')).join('\n');
        const blob = new Blob([`\uFEFF${csv}`], { type: 'text/csv;charset=utf-8;' });
        const link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = 'geo-monitor-conversations-v3.csv';
        link.click();
        URL.revokeObjectURL(link.href);
    });

    renderTrendChart();
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
