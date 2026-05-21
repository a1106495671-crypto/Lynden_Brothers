<?php
/**
 * Execute one media distribution job from a background process.
 */

define('FEISHU_TREASURE', true);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/database_admin.php';
require_once __DIR__ . '/../includes/distribution_publisher_service.php';

ensure_distribution_schema($db);

$jobId = isset($argv[1]) ? (int) $argv[1] : 0;
if ($jobId <= 0) {
    fwrite(STDERR, "Missing job id\n");
    exit(1);
}

$result = distribution_execute_publish_job($db, $jobId);
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
