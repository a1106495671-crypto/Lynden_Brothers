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
$latest = !empty($_GET['latest']);
if (!$workflowId && !$latest) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => '缺少 workflow id']);
    exit;
}

try {
    // 查询工作流主记录
    if ($latest) {
        $stmt = $db->prepare("SELECT * FROM automation_workflows ORDER BY created_at DESC LIMIT 1");
        $stmt->execute();
    } else {
        $stmt = $db->prepare("SELECT * FROM automation_workflows WHERE workflow_id = ?");
        $stmt->execute([$workflowId]);
    }
    $workflow = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$workflow) {
        echo json_encode(['success' => false, 'error' => $latest ? '暂无工作流记录' : '工作流不存在']);
        exit;
    }

    $workflowId = $workflow['workflow_id'];

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
    $runtime = [
        'generation_pending' => 0,
        'generation_running' => 0,
        'generation_failed' => 0,
        'distribution_queued' => 0,
        'distribution_running' => 0,
        'distribution_success' => 0,
        'distribution_failed' => 0,
        'distribution_manual_queued' => 0,
        'next_distribution_at' => null,
        'monitor_keywords' => 0,
        'monitor_records' => 0,
        'monitor_mentions' => 0,
    ];

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

            $jobStmt = $db->prepare("
                SELECT status, COUNT(*) AS c
                FROM job_queue
                WHERE task_id = ?
                GROUP BY status
            ");
            $jobStmt->execute([$workflow['task_id']]);
            foreach ($jobStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $status = (string) ($row['status'] ?? '');
                if ($status === 'pending') $runtime['generation_pending'] = (int) $row['c'];
                if ($status === 'running') $runtime['generation_running'] = (int) $row['c'];
                if ($status === 'failed') $runtime['generation_failed'] = (int) $row['c'];
            }

            $distStmt = $db->prepare("
                SELECT j.status, COALESCE(ma.publish_mode, 'browser') AS publish_mode, COUNT(*) AS c
                FROM media_publish_jobs j
                LEFT JOIN media_accounts ma ON ma.id = j.account_id
                WHERE j.article_id IN (
                    SELECT id FROM articles WHERE task_id = ? AND deleted_at IS NULL
                )
                GROUP BY j.status, COALESCE(ma.publish_mode, 'browser')
            ");
            $distStmt->execute([$workflow['task_id']]);
            foreach ($distStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $status = (string) ($row['status'] ?? '');
                $publishMode = (string) ($row['publish_mode'] ?? 'browser');
                if ($publishMode === 'manual' && $status === 'queued') {
                    $runtime['distribution_manual_queued'] += (int) $row['c'];
                } elseif (array_key_exists('distribution_' . $status, $runtime)) {
                    $runtime['distribution_' . $status] = (int) $row['c'];
                }
            }

            $nextStmt = $db->prepare("
                SELECT MIN(j.scheduled_at)
                FROM media_publish_jobs j
                LEFT JOIN media_accounts ma ON ma.id = j.account_id
                WHERE j.status = 'queued'
                  AND COALESCE(ma.publish_mode, 'browser') <> 'manual'
                  AND j.article_id IN (
                      SELECT id FROM articles WHERE task_id = ? AND deleted_at IS NULL
                  )
            ");
            $nextStmt->execute([$workflow['task_id']]);
            $runtime['next_distribution_at'] = $nextStmt->fetchColumn() ?: null;
        } catch (Exception $e) {}
    }

    $customerId = (string) ($workflow['customer_id'] ?? '');
    if ($customerId !== '') {
        try {
            $mk = $db->prepare("SELECT COUNT(*) FROM geo_monitor_keywords WHERE customer_id = ? AND enabled = TRUE");
            $mk->execute([$customerId]);
            $runtime['monitor_keywords'] = (int) $mk->fetchColumn();

            $mr = $db->prepare("
                SELECT COUNT(*) AS total,
                       COUNT(*) FILTER (WHERE brand_mentioned = TRUE) AS mentioned
                FROM geo_monitor_records
                WHERE customer_id = ?
            ");
            $mr->execute([$customerId]);
            $monitorRow = $mr->fetch(PDO::FETCH_ASSOC) ?: [];
            $runtime['monitor_records'] = (int) ($monitorRow['total'] ?? 0);
            $runtime['monitor_mentions'] = (int) ($monitorRow['mentioned'] ?? 0);
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
            'website'      => $workflow['website'],
            'services'     => $workflow['services'],
            'competitors'  => $workflow['competitors'],
            'positioning'  => $workflow['positioning'],
            'article_count'=> (int) $workflow['article_count'],
            'task_id'      => $workflow['task_id'] ? (int) $workflow['task_id'] : null,
            'created_at'   => $workflow['created_at'],
            'completed_at' => $workflow['completed_at'],
            'error_message'=> $workflow['error_message'],
            'steps'        => $steps,
        ],
        'stats' => $stats,
        'runtime' => $runtime,
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => '查询失败: ' . $e->getMessage()]);
}
