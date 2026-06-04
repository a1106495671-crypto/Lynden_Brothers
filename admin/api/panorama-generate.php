<?php
/**
 * GEO 全景诊断 - AJAX 生成接口
 * POST /admin/api/panorama-generate.php
 * Body: { "customer_id": "xxx" }
 */
define('FEISHU_TREASURE', true);
$isCli = PHP_SAPI === 'cli';
set_time_limit($isCli ? 900 : 300);
if (!$isCli) {
    session_start();
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/database_admin.php';

if (!$isCli) {
    header('Content-Type: application/json; charset=utf-8');
}

if (!$isCli && empty($_SESSION['admin_id']) && empty($_SESSION['admin_username'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => '请先登录']);
    exit;
}

if (!$isCli) {
    session_write_close();
}

$customerId = $isCli
    ? trim((string) ($argv[1] ?? ''))
    : trim($_POST['customer_id'] ?? $_GET['customer_id'] ?? '');
if ($customerId === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => '请选择客户']);
    exit;
}

// 1. 读品牌事实
$facts = [];
$stmtF = $db->prepare("SELECT fact_key, fact_value FROM geo_brand_facts WHERE customer_id=?");
$stmtF->execute([$customerId]);
foreach ($stmtF->fetchAll(PDO::FETCH_ASSOC) as $r) $facts[$r['fact_key']] = $r['fact_value'];
$brandName = $facts['brand_name'] ?? $customerId;

// 2. 读竞品
$stmtC = $db->prepare("SELECT competitor FROM geo_customer_competitors WHERE customer_id=? AND enabled=TRUE ORDER BY id");
$stmtC->execute([$customerId]);
$competitors = $stmtC->fetchAll(PDO::FETCH_COLUMN);

// 3. 读监测数据（近30天）
$stmtM = $db->prepare("
    SELECT provider, query_text, brand_mentioned, mention_depth,
           competitors_found, accuracy_score, queried_at::text AS day
    FROM geo_monitor_records
    WHERE customer_id=? AND queried_at >= CURRENT_DATE - INTERVAL '29 days'
    ORDER BY queried_at DESC
");
$stmtM->execute([$customerId]);
$records = $stmtM->fetchAll(PDO::FETCH_ASSOC);

if (empty($records)) {
    echo json_encode([
        'success' => false,
        'error' => "品牌「{$brandName}」暂无监测数据。请先在监测页面添加关键词并运行监测。"
    ]);
    exit;
}

// 4. 聚合：各平台提及率
$platformStats = [];
$kwStats = [];
foreach ($records as $r) {
    $p  = $r['provider'];
    $kw = $r['query_text'];
    if (!isset($platformStats[$p])) $platformStats[$p] = ['total' => 0, 'hit' => 0];
    $platformStats[$p]['total']++;
    if ($r['brand_mentioned']) $platformStats[$p]['hit']++;
    if (!isset($kwStats[$kw])) $kwStats[$kw] = ['total' => 0, 'hit' => 0, 'comp' => []];
    $kwStats[$kw]['total']++;
    if ($r['brand_mentioned']) $kwStats[$kw]['hit']++;
    $found = json_decode($r['competitors_found'] ?? '[]', true);
    if (is_array($found)) {
        foreach ($found as $f) {
            $cn = $f['name'] ?? '';
            if ($cn) $kwStats[$kw]['comp'][$cn] = ($kwStats[$kw]['comp'][$cn] ?? 0) + 1;
        }
    }
}

// 5. 竞品整体出现频率
$compOverall = [];
foreach ($records as $r) {
    $found = json_decode($r['competitors_found'] ?? '[]', true);
    if (!is_array($found)) continue;
    foreach ($found as $f) {
        $cn = $f['name'] ?? '';
        if ($cn) $compOverall[$cn] = ($compOverall[$cn] ?? 0) + 1;
    }
}
$totalRecords = count($records);

// 6. 诊断信号分
$diagId = $db->prepare("
    SELECT r.id FROM geo_diagnosis_runs r
    JOIN geo_diagnosis_brands b ON b.id=r.brand_id
    WHERE b.name=? ORDER BY r.created_at DESC LIMIT 1
");
$diagId->execute([$brandName]);
$diagIdVal = $diagId->fetchColumn();
$signals = [];
if ($diagIdVal) {
    $stmtS = $db->prepare("
        SELECT s.signal_key, d.name, s.score
        FROM geo_diagnosis_signal_scores s
        JOIN geo_diagnosis_signal_definitions d ON d.signal_key=s.signal_key
        WHERE s.diagnosis_id=? ORDER BY s.score ASC
    ");
    $stmtS->execute([$diagIdVal]);
    $signals = $stmtS->fetchAll(PDO::FETCH_ASSOC);
}

// 7. 近7天告警
$stmtA = $db->prepare("
    SELECT alert_type, level, keyword, competitor_name, brand_rate, competitor_rate, detail
    FROM geo_monitor_alerts WHERE customer_id=? AND alerted_at >= CURRENT_DATE - INTERVAL '7 days'
    ORDER BY CASE level WHEN 'high' THEN 1 WHEN 'medium' THEN 2 ELSE 3 END
");
$stmtA->execute([$customerId]);
$alerts = $stmtA->fetchAll(PDO::FETCH_ASSOC);

// ── 构建 AI Prompt ──

// 加载 prompt 模板
$templatePath = dirname(__DIR__, 2) . '/prompts/panorama_diagnosis.md';
if (!file_exists($templatePath)) {
    echo json_encode(['success' => false, 'error' => '诊断模板文件不存在']);
    exit;
}
$template = file_get_contents($templatePath);

// 品牌档案
$masterSentence = $facts['master_sentence'] ?? '未提供';
$coreServices   = $facts['core_service'] ?? $facts['core_services'] ?? '未提供';
$industry       = $facts['industry'] ?? '未提供';
$differentiator = $facts['differentiator'] ?? '未提供';
$targetClient   = $facts['target_client'] ?? '未提供';

// 平台提及率文本 — 带样本量标注
$platformText = '';
foreach ($platformStats as $p => $s) {
    $rate = $s['total'] > 0 ? round($s['hit'] / $s['total'] * 100, 1) : 0;
    $sampleNote = $s['total'] < 5 ? '（样本不足，仅供参考）' : '';
    $platformText .= "- {$p}：提及率 {$rate}%（{$s['hit']}/{$s['total']}）{$sampleNote}\n";
}

// 关键词表现文本 — 按提及率排序，带竞品压制分析
$kwSorted = $kwStats;
uasort($kwSorted, function($a, $b) {
    $ra = $a['total'] > 0 ? $a['hit'] / $a['total'] : 0;
    $rb = $b['total'] > 0 ? $b['hit'] / $b['total'] : 0;
    return $ra <=> $rb;
});
$kwText = '';
foreach (array_slice($kwSorted, 0, 15, true) as $kw => $s) {
    $rate = $s['total'] > 0 ? round($s['hit'] / $s['total'] * 100, 1) : 0;
    $compInfo = '';
    if (!empty($s['comp'])) {
        arsort($s['comp']);
        $compParts = [];
        foreach (array_slice($s['comp'], 0, 3, true) as $cn => $cnt) {
            $compParts[] = "{$cn}({$cnt}次)";
        }
        $compInfo = ' | 竞品出现：' . implode('、', $compParts);
    }
    $kwText .= "- 「{$kw}」提及率 {$rate}%（{$s['hit']}/{$s['total']}）{$compInfo}\n";
}

// 竞品出现频率文本
$compText = '';
if (!empty($compOverall)) {
    arsort($compOverall);
    foreach (array_slice($compOverall, 0, 8, true) as $cn => $cnt) {
        $rate = round($cnt / $totalRecords * 100, 1);
        $compText .= "- {$cn}：出现率 {$rate}%（{$cnt}/{$totalRecords}）\n";
    }
} else {
    $compText = "监测范围内暂无竞品被 AI 回答提及\n";
}

// 诊断信号文本
$signalText = '';
if (!empty($signals)) {
    foreach ($signals as $s) {
        $flag = $s['score'] < 40 ? '🔴' : ($s['score'] < 70 ? '🟡' : '🟢');
        $signalText .= "- {$flag} {$s['name']}：{$s['score']}分\n";
    }
} else {
    $signalText = "暂无雷达诊断数据，可前往雷达诊断页生成\n";
}

// 告警文本
$alertText = '';
if (!empty($alerts)) {
    foreach (array_slice($alerts, 0, 5) as $a) {
        $levelIcon = $a['level'] === 'high' ? '🔴' : ($a['level'] === 'medium' ? '🟡' : '🔵');
        $alertText .= "- {$levelIcon} [{$a['level']}] {$a['detail']}\n";
    }
} else {
    $alertText = "近 7 天无告警\n";
}

// 填充模板
$prompt = $template;
$replacements = [
    '{brand_name}'     => $brandName,
    '{master_sentence}' => $masterSentence,
    '{core_services}'  => $coreServices,
    '{differentiator}' => $differentiator,
    '{target_client}'  => $targetClient,
    '{industry}'       => $industry,
    '{total_records}'  => (string)$totalRecords,
    '{platform_count}' => (string)count($platformStats),
    '{keyword_count}'  => (string)count($kwStats),
    '{platform_text}'  => rtrim($platformText),
    '{kw_text}'        => rtrim($kwText),
    '{comp_text}'      => rtrim($compText),
    '{signal_text}'    => rtrim($signalText),
    '{alert_text}'     => rtrim($alertText),
];
$prompt = str_replace(array_keys($replacements), array_values($replacements), $prompt);

// 调用 AI
$aiResult = geo_call_ai($prompt, 4000, 0.3);

if (!empty($aiResult['error'])) {
    echo json_encode([
        'success' => false,
        'error' => "AI 分析失败: {$aiResult['error']}"
    ]);
    exit;
}

$reportMd = $aiResult['content'] ?? '';
if (empty(trim($reportMd))) {
    echo json_encode([
        'success' => false,
        'error' => 'AI 返回了空内容，请重试'
    ]);
    exit;
}

// 返回结果
$totalHit = 0;
$totalAll = 0;
foreach ($platformStats as $s) { $totalHit += $s['hit']; $totalAll += $s['total']; }
$overallRate = $totalAll > 0 ? round($totalHit / $totalAll * 100, 1) : 0;
$modelUsed = $aiResult['model_used'] ?? 'unknown';

$reportId = null;
try {
    $stmtSave = $db->prepare("
        INSERT INTO geo_panorama_reports (
            customer_id, brand_name, report_md, prompt_used, model_used,
            overall_rate, total_records, platform_stats, kw_stats,
            comp_overall, signals, alerts
        ) VALUES (
            :customer_id, :brand_name, :report_md, :prompt_used, :model_used,
            :overall_rate, :total_records, CAST(:platform_stats AS jsonb), CAST(:kw_stats AS jsonb),
            CAST(:comp_overall AS jsonb), CAST(:signals AS jsonb), CAST(:alerts AS jsonb)
        )
        RETURNING id
    ");
    $stmtSave->execute([
        ':customer_id' => $customerId,
        ':brand_name' => $brandName,
        ':report_md' => $reportMd,
        ':prompt_used' => $prompt,
        ':model_used' => $modelUsed,
        ':overall_rate' => $overallRate,
        ':total_records' => $totalRecords,
        ':platform_stats' => json_encode($platformStats, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ':kw_stats' => json_encode($kwStats, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ':comp_overall' => json_encode($compOverall, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ':signals' => json_encode($signals, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ':alerts' => json_encode($alerts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
    $reportId = $stmtSave->fetchColumn();
} catch (Throwable $e) {
    error_log('保存 GEO 全景诊断档案失败: ' . $e->getMessage());
}

echo json_encode([
    'success'       => true,
    'report_id'     => $reportId,
    'report_md'     => $reportMd,
    'brand_name'    => $brandName,
    'overall_rate'  => $overallRate,
    'total_records' => $totalRecords,
    'platform_stats'=> $platformStats,
    'kw_stats'      => $kwStats,
    'comp_overall'  => $compOverall,
    'signals'       => $signals,
    'alerts'        => $alerts,
    'model_used'    => $modelUsed,
    'prompt_used'   => $prompt,
    'created_at'    => date('Y-m-d H:i:s'),
]);
