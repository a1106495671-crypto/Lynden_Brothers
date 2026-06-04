<?php
/**
 * 品牌入驻自动化 - 启动接口
 * POST /admin/api/automation-start.php
 * Body: { brand_name, industry, website, services, competitors, positioning, article_count }
 */
define('FEISHU_TREASURE', true);
session_start();

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/database_admin.php';

header('Content-Type: application/json; charset=utf-8');

// 需要管理员登录
if (empty($_SESSION['admin_id']) && empty($_SESSION['admin_username'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => '请先登录']);
    exit;
}

session_write_close();

// 只接受 POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// 解析请求体
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    $input = $_POST;
}

function automation_clean_value($value): string {
    return trim((string) ($value ?? ''));
}

function automation_first_non_empty(...$values): string {
    foreach ($values as $value) {
        $clean = automation_clean_value($value);
        if ($clean !== '') {
            return $clean;
        }
    }
    return '';
}

function automation_load_customer_profile(PDO $db, string $customerId): array {
    if ($customerId === '') {
        return [];
    }

    $profile = [];

    $stmt = $db->prepare("
        SELECT fact_key, fact_value
        FROM geo_brand_facts
        WHERE customer_id = ?
          AND fact_key IN ('brand_name', 'industry', 'website', 'core_service', 'core_services', 'competitors', 'positioning', 'master_sentence')
        ORDER BY updated_at DESC
    ");
    $stmt->execute([$customerId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $key = (string) $row['fact_key'];
        if (!isset($profile[$key]) && automation_clean_value($row['fact_value'] ?? '') !== '') {
            $profile[$key] = automation_clean_value($row['fact_value']);
        }
    }

    $stmt = $db->prepare("
        SELECT services, competitors, positioning, website
        FROM automation_workflows
        WHERE customer_id = ?
        ORDER BY created_at DESC
        LIMIT 20
    ");
    $stmt->execute([$customerId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        foreach (['services', 'competitors', 'positioning', 'website'] as $field) {
            if (!isset($profile[$field]) && automation_clean_value($row[$field] ?? '') !== '') {
                $profile[$field] = automation_clean_value($row[$field]);
            }
        }
    }

    if (empty($profile['competitors'])) {
        $stmt = $db->prepare("
            SELECT string_agg(competitor, '，' ORDER BY competitor) AS competitors
            FROM geo_customer_competitors
            WHERE customer_id = ? AND enabled = TRUE
        ");
        $stmt->execute([$customerId]);
        $profile['competitors'] = automation_clean_value($stmt->fetchColumn());
    }

    return $profile;
}

function automation_parse_list(string $text): array {
    $parts = preg_split('/[,，、\n\r]+/u', $text) ?: [];
    $items = [];
    foreach ($parts as $part) {
        $item = mb_substr(trim((string) $part), 0, 100);
        if ($item !== '') {
            $items[] = $item;
        }
    }
    return array_values(array_unique($items));
}

function automation_save_customer_profile(PDO $db, string $customerId, array $profile): void {
    if ($customerId === '') {
        return;
    }

    $brandName = automation_clean_value($profile['brand_name'] ?? '');
    $industry = automation_clean_value($profile['industry'] ?? '');
    $services = automation_clean_value($profile['services'] ?? '');
    $positioning = automation_clean_value($profile['positioning'] ?? '');
    $website = automation_clean_value($profile['website'] ?? '');
    $competitors = automation_clean_value($profile['competitors'] ?? '');

    $masterSentence = $positioning;
    if ($masterSentence === '' && $brandName !== '') {
        $parts = array_filter([$industry, $services]);
        $masterSentence = $brandName . ($parts ? '是一家专注于' . implode('、', $parts) . '的品牌。' : '是一个正在进行GEO优化的品牌。');
    }

    $facts = [
        ['brand_name', '品牌名称', $brandName, true],
        ['industry', '所属行业', $industry, true],
        ['website', '官网', $website, false],
        ['core_services', '核心服务', $services, true],
        ['competitors', '主要竞品', $competitors, false],
        ['positioning', '品牌定位', $positioning, true],
        ['master_sentence', '定位母句', $masterSentence, true],
    ];

    $stmt = $db->prepare("
        INSERT INTO geo_brand_facts (customer_id, fact_key, fact_label, fact_value, is_core)
        VALUES (?, ?, ?, ?, ?)
        ON CONFLICT (customer_id, fact_key) DO UPDATE SET
            fact_label = EXCLUDED.fact_label,
            fact_value = EXCLUDED.fact_value,
            is_core = EXCLUDED.is_core,
            updated_at = CURRENT_TIMESTAMP
    ");

    foreach ($facts as [$key, $label, $value, $isCore]) {
        $value = automation_clean_value($value);
        if ($value === '') {
            continue;
        }
        $stmt->execute([$customerId, $key, $label, mb_substr($value, 0, 500), $isCore ? 'true' : 'false']);
    }

    $competitorStmt = $db->prepare("
        INSERT INTO geo_customer_competitors (customer_id, competitor, enabled)
        VALUES (?, ?, TRUE)
        ON CONFLICT (customer_id, competitor) DO UPDATE SET enabled = TRUE
    ");
    foreach (automation_parse_list($competitors) as $competitor) {
        $competitorStmt->execute([$customerId, $competitor]);
    }
}

$customerId = trim($input['customer_id'] ?? '');
$selectedCustomer = null;
if ($customerId !== '') {
    $stmt = $db->prepare("SELECT * FROM customers WHERE customer_id = ?");
    $stmt->execute([$customerId]);
    $selectedCustomer = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$selectedCustomer) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => '选择的客户不存在']);
        exit;
    }
}

$savedProfile = automation_load_customer_profile($db, $customerId);

$brandName = automation_first_non_empty($input['brand_name'] ?? '', $selectedCustomer['name'] ?? '', $savedProfile['brand_name'] ?? '');
$industry  = automation_first_non_empty($input['industry'] ?? '', $selectedCustomer['industry'] ?? '', $savedProfile['industry'] ?? '');

if (!$brandName || !$industry) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => '品牌名称和行业为必填项']);
    exit;
}

$website     = automation_first_non_empty($input['website'] ?? '', $selectedCustomer['domain'] ?? '', $savedProfile['website'] ?? '');
$services    = automation_first_non_empty($input['services'] ?? '', $savedProfile['core_services'] ?? '', $savedProfile['core_service'] ?? '', $savedProfile['services'] ?? '');
$competitors = automation_first_non_empty($input['competitors'] ?? '', $savedProfile['competitors'] ?? '');
$positioning = automation_first_non_empty($input['positioning'] ?? '', $savedProfile['positioning'] ?? '', $savedProfile['master_sentence'] ?? '');
$articleCount = max(1, min(50, intval($input['article_count'] ?? 10)));
$mediaAccountIds = [];
if (is_array($input['media_account_ids'] ?? null)) {
    $mediaAccountIds = array_values(array_unique(array_filter(array_map('intval', $input['media_account_ids']))));
}
$mediaAccountIdsJson = json_encode($mediaAccountIds, JSON_UNESCAPED_UNICODE);

try {
    $db->exec("ALTER TABLE automation_workflows ADD COLUMN IF NOT EXISTS media_account_ids TEXT DEFAULT '[]'");

    automation_save_customer_profile($db, $customerId, [
        'brand_name' => $brandName,
        'industry' => $industry,
        'website' => $website,
        'services' => $services,
        'competitors' => $competitors,
        'positioning' => $positioning,
    ]);

    // 生成唯一 workflow ID
    $workflowId = 'wf_' . date('YmdHis') . '_' . substr(md5(uniqid(mt_rand(), true)), 0, 8);

    // 创建工作流记录
    $stmt = $db->prepare("
        INSERT INTO automation_workflows
            (workflow_id, brand_name, industry, website, services, competitors, positioning, article_count, media_account_ids, customer_id, status, current_step)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'running', 'collect')
    ");
    $stmt->execute([
        $workflowId, $brandName, $industry, $website,
        $services, $competitors, $positioning, $articleCount, $mediaAccountIdsJson, $customerId
    ]);

    // 创建所有步骤记录
    $steps = [
        ['collect',          1],
        ['keywords',         2],
        ['titles',           3],
        ['knowledge',        4],
        ['customer',         5],
        ['knowledge_graph',  6],
        ['intent_mining',    7],
        ['task',             8],
        ['generate',         9],
        ['distribute',      10],
        ['monitor',         11],
        ['panorama',        12],
        ['diagnosis',       13],
    ];

    $stepStmt = $db->prepare("
        INSERT INTO automation_workflow_steps (workflow_id, step_id, step_order, status)
        VALUES (?, ?, ?, 'pending')
    ");

    foreach ($steps as [$stepId, $order]) {
        $stepStmt->execute([$workflowId, $stepId, $order]);
    }

    // 标记第一步为运行中
    $db->prepare("
        UPDATE automation_workflow_steps
        SET status = 'running', started_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
        WHERE workflow_id = ? AND step_id = 'collect'
    ")->execute([$workflowId]);

    // 启动后台 worker 执行工作流
    $workerScript = dirname(__DIR__, 2) . '/bin/automation_worker.php';
    $logFile = dirname(__DIR__, 2) . '/bin/logs/automation_' . date('Y-m-d') . '.log';
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }

    $cmd = 'php ' . escapeshellarg($workerScript) . ' ' . escapeshellarg($workflowId)
         . ' >> ' . escapeshellarg($logFile) . ' 2>&1 &';
    exec($cmd);

    echo json_encode([
        'success'     => true,
        'workflow_id' => $workflowId,
        'message'     => '自动化流程已启动',
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => '启动失败: ' . $e->getMessage()]);
}
