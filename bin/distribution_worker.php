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

echo sprintf(
    "Distribution jobs executed: total=%d success=%d failed=%d\n",
    $summary['total'],
    $summary['success'],
    $summary['failed']
);
