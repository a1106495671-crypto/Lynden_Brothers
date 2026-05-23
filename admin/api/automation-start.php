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

$brandName = trim($input['brand_name'] ?? '');
$industry  = trim($input['industry'] ?? '');

if (!$brandName || !$industry) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => '品牌名称和行业为必填项']);
    exit;
}

$website     = trim($input['website'] ?? '');
$services    = trim($input['services'] ?? '');
$competitors = trim($input['competitors'] ?? '');
$positioning = trim($input['positioning'] ?? '');
$articleCount = max(1, min(50, intval($input['article_count'] ?? 10)));

try {
    // 生成唯一 workflow ID
    $workflowId = 'wf_' . date('YmdHis') . '_' . substr(md5(uniqid(mt_rand(), true)), 0, 8);

    // 创建工作流记录
    $stmt = $db->prepare("
        INSERT INTO automation_workflows
            (workflow_id, brand_name, industry, website, services, competitors, positioning, article_count, status, current_step)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'running', 'collect')
    ");
    $stmt->execute([
        $workflowId, $brandName, $industry, $website,
        $services, $competitors, $positioning, $articleCount
    ]);

    // 创建所有步骤记录
    $steps = [
        ['collect',    1],
        ['keywords',   2],
        ['titles',     3],
        ['knowledge',  4],
        ['customer',   5],
        ['task',       6],
        ['generate',   7],
        ['distribute', 8],
        ['monitor',    9],
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

    echo json_encode([
        'success'     => true,
        'workflow_id' => $workflowId,
        'message'     => '自动化流程已启动',
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => '启动失败: ' . $e->getMessage()]);
}
