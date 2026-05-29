<?php
/**
 * 品牌入驻自动化 - 从失败步骤继续执行
 * POST /admin/api/automation-resume.php
 * Body: { workflow_id }
 */
define('FEISHU_TREASURE', true);
session_start();

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/database_admin.php';

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['admin_id']) && empty($_SESSION['admin_username'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => '请先登录']);
    exit;
}

session_write_close();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    $input = $_POST;
}

$workflowId = trim($input['workflow_id'] ?? '');
if (!$workflowId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => '缺少 workflow_id']);
    exit;
}

try {
    // 检查工作流存在且状态为 error
    $stmt = $db->prepare("SELECT status FROM automation_workflows WHERE workflow_id = ?");
    $stmt->execute([$workflowId]);
    $wf = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$wf) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => '工作流不存在']);
        exit;
    }

    if (!in_array($wf['status'], ['error', 'running'])) {
        echo json_encode(['success' => false, 'error' => '当前状态为 ' . $wf['status'] . '，不可恢复']);
        exit;
    }

    // 检查是否已有 worker 在处理（避免重复启动）
    $stepsStmt = $db->prepare("SELECT status FROM automation_workflow_steps WHERE workflow_id = ? AND status IN ('pending','running')");
    $stepsStmt->execute([$workflowId]);
    $pendingSteps = $stepsStmt->fetchAll(PDO::FETCH_COLUMN);
    if (empty($pendingSteps) && $wf['status'] === 'running') {
        echo json_encode(['success' => true, 'workflow_id' => $workflowId, 'message' => '流程正在执行中']);
        exit;
    }

    // 启动后台 worker（带 --resume 参数）
    $workerScript = dirname(__DIR__, 2) . '/bin/automation_worker.php';
    $logFile = dirname(__DIR__, 2) . '/bin/logs/automation_' . date('Y-m-d') . '.log';
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }

    $cmd = 'php ' . escapeshellarg($workerScript) . ' ' . escapeshellarg($workflowId)
         . ' --resume >> ' . escapeshellarg($logFile) . ' 2>&1 &';
    exec($cmd);

    echo json_encode([
        'success'     => true,
        'workflow_id' => $workflowId,
        'message'     => '已继续处理',
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => '恢复失败: ' . $e->getMessage()]);
}
