<?php
/**
 * 品牌入驻自动化 - 状态查询接口
 * GET /admin/api/automation-status.php?id=wf_xxx
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

$workflowId = trim($_GET['id'] ?? '');
if (!$workflowId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => '缺少 workflow id']);
    exit;
}

try {
    // 查询工作流主记录
    $stmt = $db->prepare("SELECT * FROM automation_workflows WHERE workflow_id = ?");
    $stmt->execute([$workflowId]);
    $workflow = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$workflow) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => '工作流不存在']);
        exit;
    }

    // 查询所有步骤
    $stepStmt = $db->prepare("
        SELECT step_id, step_order, status, started_at, finished_at, elapsed_seconds, output_data, error_message
        FROM automation_workflow_steps
        WHERE workflow_id = ?
        ORDER BY step_order ASC
    ");
    $stepStmt->execute([$workflowId]);
    $steps = $stepStmt->fetchAll(PDO::FETCH_ASSOC);

    // 查询统计数据
    $stats = ['keywords' => 0, 'titles' => 0, 'articles' => 0, 'published' => 0];

    if ($workflow['keyword_library_id']) {
        try {
            $ks = $db->prepare("SELECT COUNT(*) FROM keywords WHERE library_id = ?");
            $ks->execute([$workflow['keyword_library_id']]);
            $stats['keywords'] = (int) $ks->fetchColumn();
        } catch (Exception $e) {}
    }

    if ($workflow['title_library_id']) {
        try {
            $ts = $db->prepare("SELECT COUNT(*) FROM titles WHERE library_id = ?");
            $ts->execute([$workflow['title_library_id']]);
            $stats['titles'] = (int) $ts->fetchColumn();
        } catch (Exception $e) {}
    }

    if ($workflow['task_id']) {
        try {
            $as = $db->prepare("SELECT COUNT(*) FROM articles WHERE task_id = ?");
            $as->execute([$workflow['task_id']]);
            $stats['articles'] = (int) $as->fetchColumn();

            $ps = $db->prepare("SELECT COUNT(*) FROM articles WHERE task_id = ? AND status = 'published'");
            $ps->execute([$workflow['task_id']]);
            $stats['published'] = (int) $ps->fetchColumn();
        } catch (Exception $e) {}
    }

    echo json_encode([
        'success'  => true,
        'workflow' => [
            'id'           => $workflow['workflow_id'],
            'status'       => $workflow['status'],
            'current_step' => $workflow['current_step'],
            'brand_name'   => $workflow['brand_name'],
            'industry'     => $workflow['industry'],
            'created_at'   => $workflow['created_at'],
            'completed_at' => $workflow['completed_at'],
            'error_message'=> $workflow['error_message'],
            'steps'        => $steps,
        ],
        'stats' => $stats,
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => '查询失败: ' . $e->getMessage()]);
}
