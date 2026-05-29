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

function automation_diagnosis_data_source(string $rawSignalsJson): string {
    $signals = json_decode($rawSignalsJson, true);
    if (!is_array($signals)) {
        return 'unknown';
    }

    foreach ($signals as $signal) {
        $metric = $signal['raw_metric'] ?? [];
        if (!is_array($metric)) {
            continue;
        }
        if (($metric['data_source'] ?? '') === 'real_search' || !empty($metric['search_provider'])) {
            return 'real_search';
        }
        if (($metric['data_source'] ?? '') === 'estimated') {
            return 'estimated';
        }
    }

    return 'estimated';
}

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
    $stats = ['diagnoses' => 0, 'keywords' => 0, 'titles' => 0, 'knowledge_graph' => 0, 'intent_questions' => 0, 'articles' => 0, 'published' => 0];
    $diagnosis = null;
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
        'panorama_reports' => 0,
        'latest_panorama_report_id' => null,
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

    try {
        if (!empty($workflow['diagnosis_id'])) {
            $ds = $db->prepare("SELECT COUNT(*) FROM geo_diagnosis_runs WHERE id::text = ?");
            $ds->execute([(string) $workflow['diagnosis_id']]);
            $stats['diagnoses'] = (int) $ds->fetchColumn();

            $detailStmt = $db->prepare("
                SELECT r.id::text AS id,
                       ROUND(r.overall_score::numeric, 1) AS overall_score,
                       r.predicted_hit_rate,
                       r.status,
                       r.completed_at,
                       r.raw_signals_json::text AS raw_signals_json,
                       b.name AS brand_name,
                       b.domain,
                       b.industry,
                       (
                           SELECT COUNT(*)
                           FROM geo_diagnosis_actions a
                           WHERE a.diagnosis_id = r.id
                       ) AS action_count,
                       (
                           SELECT d.name
                           FROM geo_diagnosis_signal_scores s
                           LEFT JOIN geo_diagnosis_signal_definitions d ON d.signal_key = s.signal_key
                           WHERE s.diagnosis_id = r.id
                           ORDER BY s.score ASC
                           LIMIT 1
                       ) AS weakest_signal
                FROM geo_diagnosis_runs r
                JOIN geo_diagnosis_brands b ON b.id = r.brand_id
                WHERE r.id::text = ?
                LIMIT 1
            ");
            $detailStmt->execute([(string) $workflow['diagnosis_id']]);
            $diagnosis = $detailStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($diagnosis) {
                $diagnosis['data_source'] = automation_diagnosis_data_source((string) ($diagnosis['raw_signals_json'] ?? ''));
                unset($diagnosis['raw_signals_json']);
            }
        } elseif (!empty($workflow['brand_name'])) {
            $ds = $db->prepare("
                SELECT COUNT(*)
                FROM geo_diagnosis_runs r
                JOIN geo_diagnosis_brands b ON b.id = r.brand_id
                WHERE b.name = ?
            ");
            $ds->execute([(string) $workflow['brand_name']]);
            $stats['diagnoses'] = (int) $ds->fetchColumn();

            $detailStmt = $db->prepare("
                SELECT r.id::text AS id,
                       ROUND(r.overall_score::numeric, 1) AS overall_score,
                       r.predicted_hit_rate,
                       r.status,
                       r.completed_at,
                       r.raw_signals_json::text AS raw_signals_json,
                       b.name AS brand_name,
                       b.domain,
                       b.industry,
                       (
                           SELECT COUNT(*)
                           FROM geo_diagnosis_actions a
                           WHERE a.diagnosis_id = r.id
                       ) AS action_count,
                       (
                           SELECT d.name
                           FROM geo_diagnosis_signal_scores s
                           LEFT JOIN geo_diagnosis_signal_definitions d ON d.signal_key = s.signal_key
                           WHERE s.diagnosis_id = r.id
                           ORDER BY s.score ASC
                           LIMIT 1
                       ) AS weakest_signal
                FROM geo_diagnosis_runs r
                JOIN geo_diagnosis_brands b ON b.id = r.brand_id
                WHERE b.name = ?
                ORDER BY r.created_at DESC
                LIMIT 1
            ");
            $detailStmt->execute([(string) $workflow['brand_name']]);
            $diagnosis = $detailStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($diagnosis) {
                $diagnosis['data_source'] = automation_diagnosis_data_source((string) ($diagnosis['raw_signals_json'] ?? ''));
                unset($diagnosis['raw_signals_json']);
            }
        }
    } catch (Exception $e) {}

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
            $kg = $db->prepare("SELECT COUNT(*) FROM geo_brand_knowledge WHERE customer_id = ?");
            $kg->execute([$customerId]);
            $stats['knowledge_graph'] = (int) $kg->fetchColumn();
        } catch (Exception $e) {}

        try {
            $iq = $db->prepare("SELECT COUNT(*) FROM geo_intent_questions WHERE customer_id = ?");
            $iq->execute([$customerId]);
            $stats['intent_questions'] = (int) $iq->fetchColumn();
        } catch (Exception $e) {}

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

        try {
            $pr = $db->prepare("
                SELECT COUNT(*) AS total, MAX(id) AS latest_id
                FROM geo_panorama_reports
                WHERE customer_id = ?
            ");
            $pr->execute([$customerId]);
            $panoramaRow = $pr->fetch(PDO::FETCH_ASSOC) ?: [];
            $runtime['panorama_reports'] = (int) ($panoramaRow['total'] ?? 0);
            $runtime['latest_panorama_report_id'] = $panoramaRow['latest_id'] ? (int) $panoramaRow['latest_id'] : null;
        } catch (Exception $e) {}
    }

    $completionIssues = [];
    $targetArticles = (int) ($workflow['article_count'] ?? 0);
    if (!empty($workflow['task_id']) && $targetArticles > 0 && (int) ($stats['articles'] ?? 0) < $targetArticles) {
        $completionIssues[] = '文章生成未达标：已生成 ' . (int) ($stats['articles'] ?? 0) . '/' . $targetArticles . ' 篇';
    }
    if (($runtime['generation_pending'] ?? 0) > 0 || ($runtime['generation_running'] ?? 0) > 0) {
        $completionIssues[] = '文章生成队列仍有待处理任务';
    }
    $distributionTotal = (int) ($runtime['distribution_queued'] ?? 0)
        + (int) ($runtime['distribution_running'] ?? 0)
        + (int) ($runtime['distribution_success'] ?? 0)
        + (int) ($runtime['distribution_failed'] ?? 0)
        + (int) ($runtime['distribution_manual_queued'] ?? 0);
    if ($distributionTotal > 0 && (int) ($runtime['distribution_success'] ?? 0) === 0) {
        $completionIssues[] = '媒体分发未成功：成功 0 / 总任务 ' . $distributionTotal;
    }
    if (($runtime['distribution_failed'] ?? 0) > 0) {
        $completionIssues[] = '媒体分发存在失败任务：' . (int) $runtime['distribution_failed'] . ' 个';
    }
    if (($runtime['distribution_queued'] ?? 0) > 0 || ($runtime['distribution_manual_queued'] ?? 0) > 0) {
        $completionIssues[] = '媒体分发仍有排队/手动任务';
    }
    $selectedMediaAccounts = json_decode((string) ($workflow['media_account_ids'] ?? '[]'), true);
    if (!is_array($selectedMediaAccounts) || empty($selectedMediaAccounts)) {
        $completionIssues[] = '未选择发布平台/账号，自动化不会真正发到外部平台';
    }
    if (($runtime['monitor_keywords'] ?? 0) > 0 && (int) ($runtime['monitor_records'] ?? 0) === 0) {
        $completionIssues[] = 'GEO 监测未产出记录：关键词 ' . (int) $runtime['monitor_keywords'] . ' 个，记录 0 条';
    }
    if (($runtime['monitor_records'] ?? 0) > 0 && (int) ($runtime['panorama_reports'] ?? 0) === 0) {
        $completionIssues[] = '全景诊断未生成档案';
    }
    if ($diagnosis && ($diagnosis['data_source'] ?? '') !== 'real_search') {
        $completionIssues[] = '雷达诊断不是实时搜索诊断：' . (($diagnosis['data_source'] ?? '') === 'site_crawl_estimate' ? '官网抓取估算' : '本地估算');
    }
    foreach ($steps as $step) {
        if (($step['step_id'] ?? '') !== 'intent_mining') {
            continue;
        }
        $stepOutput = json_decode((string) ($step['output_data'] ?? ''), true);
        if (is_array($stepOutput) && !empty($stepOutput['gap_analysis']['fallback'])) {
            $completionIssues[] = '意图挖掘使用规则兜底，不是 AI JSON 正常解析结果';
        }
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
            'media_account_ids' => json_decode((string) ($workflow['media_account_ids'] ?? '[]'), true) ?: [],
            'article_count'=> (int) $workflow['article_count'],
            'task_id'      => $workflow['task_id'] ? (int) $workflow['task_id'] : null,
            'diagnosis_id' => $workflow['diagnosis_id'] ?? '',
            'created_at'   => $workflow['created_at'],
            'completed_at' => $workflow['completed_at'],
            'error_message'=> $workflow['error_message'],
            'completion_issues' => $completionIssues,
            'is_really_complete' => empty($completionIssues),
            'steps'        => $steps,
        ],
        'stats' => $stats,
        'diagnosis' => $diagnosis,
        'runtime' => $runtime,
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => '查询失败: ' . $e->getMessage()]);
}
