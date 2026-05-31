<?php
/**
 * 媒体分发队列执行器
 */

define('FEISHU_TREASURE', true);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/database_admin.php';
require_once __DIR__ . '/../includes/distribution_publisher_service.php';

ensure_distribution_schema($db);

$limit = isset($argv[1]) ? (int) $argv[1] : 5;
$summary = distribution_execute_queued_jobs($db, $limit);
$geoflowSummary = function_exists('geoflow_distribution_execute_queued_jobs')
    ? geoflow_distribution_execute_queued_jobs($db, $limit)
    : ['total' => 0, 'success' => 0, 'failed' => 0, 'skipped' => 0];

echo sprintf(
    "Media distribution jobs executed: total=%d success=%d failed=%d skipped=%d\n",
    $summary['total'],
    $summary['success'],
    $summary['failed'],
    $summary['skipped'] ?? 0
);

echo sprintf(
    "GEOFlow distribution jobs executed: total=%d success=%d failed=%d skipped=%d\n",
    $geoflowSummary['total'],
    $geoflowSummary['success'],
    $geoflowSummary['failed'],
    $geoflowSummary['skipped'] ?? 0
);
