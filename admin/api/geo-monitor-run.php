<?php
/**
 * GEO monitor manual runner API.
 *
 * Starts the real CLI runner in the background and exposes a lightweight log/status view.
 */
define('FEISHU_TREASURE', true);
session_start();

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/database_admin.php';
require_once __DIR__ . '/../../includes/citation_simulator_service.php';

header('Content-Type: application/json; charset=utf-8');

if (!is_admin_logged_in()) {
    json_response(['success' => false, 'error' => '请先登录'], 401);
}

session_write_close();

function geo_monitor_api_safe_customer(string $customerId): string {
    $safe = preg_replace('/[^a-zA-Z0-9_\-]+/', '-', trim($customerId));
    return trim((string) $safe, '-') ?: 'default';
}

function geo_monitor_api_log_dir(): string {
    $logDir = dirname(__DIR__, 2) . '/bin/logs';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    return $logDir;
}

function geo_monitor_api_legacy_log_file(string $customerId): string {
    return geo_monitor_api_log_dir() . '/geo_monitor_' . date('Y-m-d') . '_' . geo_monitor_api_safe_customer($customerId) . '.log';
}

function geo_monitor_api_state_file(string $customerId): string {
    return geo_monitor_api_log_dir() . '/geo_monitor_' . geo_monitor_api_safe_customer($customerId) . '.state.json';
}

function geo_monitor_api_run_log_file(string $customerId, string $runId): string {
    return geo_monitor_api_log_dir() . '/geo_monitor_' . date('Y-m-d') . '_' . geo_monitor_api_safe_customer($customerId) . '_' . $runId . '.log';
}

function geo_monitor_api_tail(string $file, int $maxLines = 220): string {
    if (!is_file($file)) {
        return '';
    }
    $lines = file($file, FILE_IGNORE_NEW_LINES);
    if (!is_array($lines)) {
        return '';
    }
    return implode("\n", array_slice($lines, -$maxLines));
}

function geo_monitor_api_latest_segment(string $log): string {
    $pos = strrpos("\n" . $log, "\n==== GEO monitor manual run ");
    if ($pos === false) {
        return $log;
    }
    return ltrim(substr("\n" . $log, $pos), "\n");
}

function geo_monitor_api_clean_legacy_log(string $log): string {
    $cleaned = preg_replace(
        '/\n?==== GEO monitor manual run [^\n]* dry_run=1 ====\n.*?\n\[geo-monitor-finished[^\n]*\]\n?/s',
        "\n",
        $log
    );
    $cleaned = is_string($cleaned) ? trim($cleaned) : '';
    return $cleaned !== '' ? $cleaned : $log;
}

function geo_monitor_api_read_state(string $customerId): array {
    $file = geo_monitor_api_state_file($customerId);
    if (!is_file($file)) {
        return [];
    }
    $data = json_decode((string) file_get_contents($file), true);
    return is_array($data) ? $data : [];
}

function geo_monitor_api_write_state(string $customerId, array $state): void {
    file_put_contents(
        geo_monitor_api_state_file($customerId),
        json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
    );
}

function geo_monitor_api_active_process(string $customerId): ?array {
    $needle = '--customer=' . $customerId;
    foreach (glob('/proc/[0-9]*/cmdline') ?: [] as $cmdlineFile) {
        $raw = @file_get_contents($cmdlineFile);
        if ($raw === false || $raw === '') {
            continue;
        }
        $cmd = str_replace("\0", ' ', $raw);
        if (!str_contains($cmd, 'geo-monitor-run.php') || !str_contains($cmd, $needle)) {
            continue;
        }
        if (str_contains($cmd, 'admin/api/geo-monitor-run.php')) {
            continue;
        }
        if (preg_match('#/proc/(\d+)/cmdline#', $cmdlineFile, $m)) {
            return [
                'pid' => $m[1],
                'cmd' => trim($cmd),
            ];
        }
    }
    return null;
}

function geo_monitor_api_run_status(string $customerId): array {
    $state = geo_monitor_api_read_state($customerId);
    $logFile = (string) ($state['log_file'] ?? '');
    $log = $logFile !== '' ? geo_monitor_api_tail($logFile) : '';
    $activeProcess = geo_monitor_api_active_process($customerId);

    if ($log === '') {
        $logFile = geo_monitor_api_legacy_log_file($customerId);
        $legacyTail = geo_monitor_api_clean_legacy_log(geo_monitor_api_tail($logFile));
        $log = geo_monitor_api_latest_segment($legacyTail);
    }

    $finished = $log !== '' && str_contains($log, '[geo-monitor-finished');
    $recent = is_file($logFile) && (time() - filemtime($logFile) < 900);

    return [
        'status' => ($activeProcess || (!$finished && $recent)) ? 'running' : 'idle',
        'log' => $log,
        'log_file' => $logFile,
        'run_id' => $state['run_id'] ?? null,
        'pid' => $activeProcess['pid'] ?? ($state['pid'] ?? null),
        'started_at' => $state['started_at'] ?? null,
    ];
}

function geo_monitor_api_runner_command(): string {
    $runner = env_value('GEO_MONITOR_PHP_RUNNER', '');
    if ($runner === '') {
        $runner = PHP_BINARY ?: 'php';
    }
    $parts = preg_split('/\s+/', trim($runner)) ?: [];
    $parts = array_values(array_filter($parts, static fn($part) => $part !== ''));
    return empty($parts) ? 'php' : implode(' ', array_map('escapeshellarg', $parts));
}

function geo_monitor_api_provider_count(PDO $db): int {
    $count = 0;
    try {
        $apiConfig = citation_simulator_api_config();
        foreach (['kimi', 'deepseek', 'tongyi', 'wenxin', 'doubao', 'yuanbao'] as $pkey) {
            $pcfg = $apiConfig['providers'][$pkey] ?? null;
            if ($pcfg && !empty($pcfg['configured']) && (($pcfg['op_status'] ?? 'normal') !== 'disabled')) {
                $count++;
            }
        }
    } catch (Throwable $_) {}

    try {
        $stmt = $db->query("
            SELECT COUNT(*)
            FROM ai_models
            WHERE status = 'active'
              AND (model_type = 'chat' OR model_type IS NULL OR model_type = '')
              AND COALESCE(api_key, '') <> ''
              AND COALESCE(api_url, '') <> ''
              AND COALESCE(model_id, '') <> ''
        ");
        $count += (int) $stmt->fetchColumn();
    } catch (Throwable $_) {}

    return $count;
}

function geo_monitor_api_stats(PDO $db, string $customerId): array {
    $stats = [
        'keyword_count' => 0,
        'competitor_count' => 0,
        'today_records' => 0,
        'total_records' => 0,
        'mentioned_records' => 0,
        'latest_record_at' => null,
    ];

    try {
        $stmt = $db->prepare("SELECT COUNT(*) FROM geo_monitor_keywords WHERE customer_id = ? AND enabled = TRUE");
        $stmt->execute([$customerId]);
        $stats['keyword_count'] = (int) $stmt->fetchColumn();
    } catch (Throwable $_) {}

    try {
        $stmt = $db->prepare("SELECT COUNT(*) FROM geo_customer_competitors WHERE customer_id = ? AND enabled = TRUE");
        $stmt->execute([$customerId]);
        $stats['competitor_count'] = (int) $stmt->fetchColumn();
    } catch (Throwable $_) {}

    try {
        $stmt = $db->prepare("
            SELECT
                COUNT(*) AS total,
                COUNT(*) FILTER (WHERE queried_at = CURRENT_DATE) AS today,
                COUNT(*) FILTER (WHERE brand_mentioned = TRUE) AS mentioned,
                MAX(created_at)::text AS latest_at
            FROM geo_monitor_records
            WHERE customer_id = ?
        ");
        $stmt->execute([$customerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $stats['total_records'] = (int) ($row['total'] ?? 0);
        $stats['today_records'] = (int) ($row['today'] ?? 0);
        $stats['mentioned_records'] = (int) ($row['mentioned'] ?? 0);
        $stats['latest_record_at'] = $row['latest_at'] ?? null;
    } catch (Throwable $_) {}

    return $stats;
}

$action = $_GET['action'] ?? $_POST['action'] ?? 'status';
$customerId = trim((string) ($_GET['customer_id'] ?? $_POST['customer_id'] ?? ''));
if ($customerId === '') {
    json_response(['success' => false, 'error' => '缺少 customer_id'], 400);
}

if ($action === 'status') {
    $runStatus = geo_monitor_api_run_status($customerId);
    json_response([
        'success' => true,
        'status' => $runStatus['status'],
        'log' => $runStatus['log'],
        'log_file' => $runStatus['log_file'],
        'run_id' => $runStatus['run_id'],
        'pid' => $runStatus['pid'],
        'started_at' => $runStatus['started_at'],
        'stats' => geo_monitor_api_stats($db, $customerId),
    ]);
}

if ($action !== 'start') {
    json_response(['success' => false, 'error' => '未知操作'], 400);
}

if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    json_response(['success' => false, 'error' => 'CSRF验证失败'], 403);
}

$currentRun = geo_monitor_api_run_status($customerId);
if (($currentRun['status'] ?? 'idle') === 'running') {
    json_response([
        'success' => true,
        'status' => 'running',
        'already_running' => true,
        'pid' => $currentRun['pid'],
        'run_id' => $currentRun['run_id'],
        'log_file' => $currentRun['log_file'],
        'log' => $currentRun['log'],
        'stats' => geo_monitor_api_stats($db, $customerId),
    ]);
}

$stats = geo_monitor_api_stats($db, $customerId);
if ($stats['keyword_count'] <= 0) {
    json_response(['success' => false, 'error' => '当前客户没有启用的监测关键词，请先添加或生成监测问题'], 422);
}

if (geo_monitor_api_provider_count($db) <= 0) {
    json_response(['success' => false, 'error' => '没有可用的 AI 提供商，请先在 AI 配置或引用模拟器中配置至少一个真实模型'], 422);
}

$script = realpath(dirname(__DIR__, 2) . '/bin/geo-monitor-run.php') ?: '';
if ($script === '') {
    json_response(['success' => false, 'error' => '监测脚本不存在'], 500);
}

$force = ($_POST['force'] ?? '1') === '1';
$dryRun = ($_POST['dry_run'] ?? '0') === '1';
$runId = date('His') . '_' . substr(bin2hex(random_bytes(3)), 0, 6);
$logFile = geo_monitor_api_run_log_file($customerId, $runId);

$header = "\n==== GEO monitor manual run " . date('Y-m-d H:i:s') . " customer={$customerId} force=" . ($force ? '1' : '0') . " dry_run=" . ($dryRun ? '1' : '0') . " ====\n";
file_put_contents($logFile, $header, FILE_APPEND);

$args = [
    escapeshellarg($script),
    escapeshellarg('--customer=' . $customerId),
];
if ($force) {
    $args[] = escapeshellarg('--force');
}
if ($dryRun) {
    $args[] = escapeshellarg('--dry-run');
}

$runnerCommand = geo_monitor_api_runner_command();
$inner = $runnerCommand . ' ' . implode(' ', $args)
    . ' >> ' . escapeshellarg($logFile)
    . ' 2>&1; code=$?; printf "\\n[geo-monitor-finished exit_code=%s at=%s]\\n" "$code" "$(date +%F\\ %T)" >> '
    . escapeshellarg($logFile);
$command = 'nohup sh -c ' . escapeshellarg($inner) . ' >/dev/null 2>&1 & echo $!';

$output = [];
exec($command, $output);
$pid = trim((string) ($output[0] ?? ''));

geo_monitor_api_write_state($customerId, [
    'customer_id' => $customerId,
    'run_id' => $runId,
    'pid' => $pid,
    'log_file' => $logFile,
    'started_at' => date('c'),
    'dry_run' => $dryRun,
    'force' => $force,
]);

json_response([
    'success' => true,
    'status' => 'running',
    'pid' => $pid,
    'run_id' => $runId,
    'log_file' => $logFile,
    'log' => geo_monitor_api_tail($logFile),
    'stats' => $stats,
]);
