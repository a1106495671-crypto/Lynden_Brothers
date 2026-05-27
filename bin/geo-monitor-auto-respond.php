<?php
/**
 * GEO 监控自动决策 - 检测引用率下滑，自动补救内容
 * Cron: 0 8 * * * php bin/geo-monitor-auto-respond.php
 */
define('FEISHU_TREASURE', true);

$projectRoot = dirname(__DIR__);
chdir($projectRoot);

require_once $projectRoot . '/includes/config.php';
require_once $projectRoot . '/includes/database_admin.php';
require_once $projectRoot . '/includes/job_queue_service.php';

set_time_limit(120);

function mar_log(string $msg): void {
    echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
}

mar_log('监控自动决策开始');

// 取有近期监测数据的客户
$stmt = $db->query("
    SELECT DISTINCT customer_id
    FROM geo_monitor_records
    WHERE queried_at >= CURRENT_DATE - INTERVAL '14 days'
");
$customers = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];

if (empty($customers)) {
    mar_log('无近期监测数据，退出');
    exit(0);
}

mar_log('检查 ' . count($customers) . ' 个客户');

$queueService = new JobQueueService($db);

foreach ($customers as $cid) {
    // 本周 vs 上周，按关键词对比
    $stmt = $db->prepare("
        SELECT
            query_text,
            ROUND(100.0 * SUM(CASE WHEN brand_mentioned AND queried_at >= CURRENT_DATE - INTERVAL '7 days' THEN 1 ELSE 0 END)
                / NULLIF(SUM(CASE WHEN queried_at >= CURRENT_DATE - INTERVAL '7 days' THEN 1 ELSE 0 END), 0), 1) AS this_week,
            ROUND(100.0 * SUM(CASE WHEN brand_mentioned AND queried_at < CURRENT_DATE - INTERVAL '7 days' AND queried_at >= CURRENT_DATE - INTERVAL '14 days' THEN 1 ELSE 0 END)
                / NULLIF(SUM(CASE WHEN queried_at < CURRENT_DATE - INTERVAL '7 days' AND queried_at >= CURRENT_DATE - INTERVAL '14 days' THEN 1 ELSE 0 END), 0), 1) AS last_week
        FROM geo_monitor_records
        WHERE customer_id = ?
          AND queried_at >= CURRENT_DATE - INTERVAL '14 days'
        GROUP BY query_text
        HAVING SUM(CASE WHEN queried_at >= CURRENT_DATE - INTERVAL '7 days' THEN 1 ELSE 0 END) >= 2
           AND SUM(CASE WHEN queried_at < CURRENT_DATE - INTERVAL '7 days' AND queried_at >= CURRENT_DATE - INTERVAL '14 days' THEN 1 ELSE 0 END) >= 2
    ");
    $stmt->execute([$cid]);
    $kwRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 找下滑超过 10pp 的危机关键词
    $crisisKws = [];
    foreach ($kwRows as $row) {
        $thisWeek = (float)($row['this_week'] ?? 0);
        $lastWeek = (float)($row['last_week'] ?? 0);
        $drop     = $lastWeek - $thisWeek;
        if ($drop >= 10) {
            $crisisKws[] = ['keyword' => $row['query_text'], 'drop' => $drop, 'current' => $thisWeek];
        }
    }

    if (empty($crisisKws)) {
        continue;
    }

    // 品牌名
    $stmtBn = $db->prepare("SELECT fact_value FROM geo_brand_facts WHERE customer_id = ? AND fact_key = 'brand_name' LIMIT 1");
    $stmtBn->execute([$cid]);
    $brandName = (string)($stmtBn->fetchColumn() ?: $cid);

    // 找活跃任务
    $stmtTask = $db->prepare("SELECT id FROM tasks WHERE geo_customer_id = ? AND status = 'active' ORDER BY updated_at DESC LIMIT 1");
    $stmtTask->execute([$cid]);
    $taskId = $stmtTask->fetchColumn();

    if (!$taskId) {
        mar_log("  {$cid}: 无活跃任务，跳过补救");
        continue;
    }

    $year = date('Y');
    foreach ($crisisKws as $crisis) {
        $kw    = $crisis['keyword'];
        $title = "为什么{$brandName}在「{$kw}」方面值得信赖：{$year}年最新验证";

        // 避免重复入队
        $existing = $db->prepare("
            SELECT COUNT(*) FROM job_queue
            WHERE task_id = ? AND JSON_EXTRACT_PATH_TEXT(payload::text, 'title') = ?
              AND created_at >= CURRENT_DATE - INTERVAL '3 days'
        ");
        try {
            $existing->execute([$taskId, $title]);
            if ((int)$existing->fetchColumn() > 0) {
                continue;
            }
        } catch (Throwable $e) {
            // JSON 函数差异，直接写入
        }

        $jobId = $queueService->enqueueTaskJob($taskId, 'generate_article', [
            'title'           => $title,
            'keyword'         => $kw,
            'priority'        => 10,
            'auto_respond'    => true,
            'crisis_keyword'  => $kw,
            'crisis_drop_pp'  => $crisis['drop'],
        ]);

        if ($jobId) {
            mar_log("  {$cid} ({$brandName}): 补救 job #{$jobId} 关键词「{$kw}」下滑 {$crisis['drop']}pp");
        }
    }
}

mar_log('监控自动决策完成');
