<?php
/**
 * 媒体分发执行器
 */

if (!defined('FEISHU_TREASURE')) {
    die('Access denied');
}

require_once __DIR__ . '/distribution_service.php';
require_once __DIR__ . '/functions.php';

function distribution_execute_publish_job(PDO $db, int $jobId): array {
    if (function_exists('set_time_limit')) {
        @set_time_limit(900);
    }

    $job = distribution_get_publish_job($db, $jobId);
    if (!$job) {
        throw new InvalidArgumentException('发布任务不存在');
    }

    $rateGate = distribution_publish_job_rate_gate($db, $job);
    if (!$rateGate['allowed']) {
        distribution_reschedule_publish_job($db, $jobId, (int) $rateGate['next_at'], (string) $rateGate['message']);
        return [
            'status' => 'skipped',
            'error_message' => (string) $rateGate['message'],
            'scheduled_at' => date('Y-m-d H:i:s', (int) $rateGate['next_at']),
        ];
    }

    if ((string) ($job['status'] ?? '') !== 'running') {
        if (!distribution_claim_publish_job($db, $jobId)) {
            $job = distribution_get_publish_job($db, $jobId);
            return [
                'status' => 'skipped',
                'error_message' => '任务已在执行或已发布，不再重复执行。',
                'current_status' => $job['status'] ?? '',
            ];
        }

        $job = distribution_get_publish_job($db, $jobId);
    }

    try {
        $mode = (string) ($job['publish_mode'] ?? 'browser');
        if ($mode !== 'browser') {
            throw new RuntimeException('当前账号不是“浏览器自动化”模式，无法自动发布。请将账号发布方式改为“浏览器自动化”。');
        }

        $platform = (string) ($job['platform'] ?? '');
        $result = match ($platform) {
            'zhihu' => distribution_publish_zhihu_with_browser($job),
            'toutiao' => distribution_publish_toutiao_with_browser($job),
            'sohu' => distribution_publish_generic_with_browser($job, 'sohu', 'https://mp.sohu.com/mpfe/v3/main/news/addarticle'),
            'baijiahao' => distribution_publish_generic_with_browser($job, 'baijiahao', 'https://baijiahao.baidu.com/builder/rc/edit'),
            'wechat' => distribution_publish_generic_with_browser($job, 'wechat', 'https://mp.weixin.qq.com/'),
            default => throw new RuntimeException('暂未实现 ' . distribution_platform_label($platform) . ' 的自动发布脚本。'),
        };

        $remoteUrl = (string) ($result['remote_url'] ?? '');
        distribution_job_update($db, $jobId, 'success', $remoteUrl, '');
        distribution_inject_monitor_keywords_for_job($db, $job, $remoteUrl);
        return ['status' => 'success', 'remote_url' => $remoteUrl];
    } catch (Throwable $e) {
        distribution_job_update($db, $jobId, 'failed', '', $e->getMessage());
        return ['status' => 'failed', 'error_message' => $e->getMessage()];
    }
}

function distribution_start_publish_job_async(PDO $db, int $jobId): bool {
    $job = distribution_get_publish_job($db, $jobId);
    if (!$job) {
        throw new InvalidArgumentException('发布任务不存在');
    }

    if (!in_array((string) ($job['status'] ?? ''), ['queued', 'failed'], true)) {
        throw new RuntimeException('任务已在执行或已发布，不需要重复启动。');
    }

    $rateGate = distribution_publish_job_rate_gate($db, $job);
    if (!$rateGate['allowed']) {
        distribution_reschedule_publish_job($db, $jobId, (int) $rateGate['next_at'], (string) $rateGate['message']);
        return false;
    }

    if (!distribution_claim_publish_job($db, $jobId)) {
        return false;
    }

    $runner = env_value('DISTRIBUTION_PHP_RUNNER', '');
    if ($runner === '') {
        $localRunner = realpath(__DIR__ . '/../.local/bin/frankenphp') ?: '';
        $runner = $localRunner !== '' ? ($localRunner . ' php-cli') : 'php';
    }
    $script = realpath(__DIR__ . '/../bin/distribution_run_job.php') ?: '';
    if ($script === '') {
        throw new RuntimeException('找不到媒体分发后台执行器');
    }

    $command = distribution_escape_runner_command($runner)
        . escapeshellarg($script) . ' '
        . (int) $jobId
        . ' >/dev/null 2>&1 &';
    exec($command);
    return true;
}

function distribution_escape_runner_command(string $runner): string {
    $parts = preg_split('/\s+/', trim($runner)) ?: [];
    $parts = array_values(array_filter($parts, static fn ($part) => $part !== ''));
    if (empty($parts)) {
        return 'php ';
    }
    return implode(' ', array_map('escapeshellarg', $parts)) . ' ';
}

function distribution_start_queued_jobs_async(PDO $db, int $limit = 5): int {
    $limit = max(1, min(20, $limit));
    $stmt = $db->prepare("
        SELECT j.id
        FROM media_publish_jobs j
        LEFT JOIN media_accounts ma ON j.account_id = ma.id
        WHERE j.status = 'queued'
          AND (j.scheduled_at IS NULL OR j.scheduled_at <= CURRENT_TIMESTAMP)
          AND ma.status = 'active'
          AND ma.publish_mode = 'browser'
        ORDER BY j.created_at ASC, j.id ASC
        LIMIT ?
    ");
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();

    $count = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $jobId) {
        if (distribution_start_publish_job_async($db, (int) $jobId)) {
            $count++;
        }
    }

    return $count;
}

function distribution_start_article_queued_jobs_async(PDO $db, int $articleId, int $limit = 5): int {
    $limit = max(1, min(20, $limit));
    $stmt = $db->prepare("
        SELECT j.id
        FROM media_publish_jobs j
        LEFT JOIN media_accounts ma ON j.account_id = ma.id
        WHERE j.article_id = ?
          AND j.status = 'queued'
          AND (j.scheduled_at IS NULL OR j.scheduled_at <= CURRENT_TIMESTAMP)
          AND ma.status = 'active'
          AND ma.publish_mode = 'browser'
        ORDER BY j.created_at ASC, j.id ASC
        LIMIT ?
    ");
    $stmt->bindValue(1, $articleId, PDO::PARAM_INT);
    $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    $stmt->execute();

    $count = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $jobId) {
        if (distribution_start_publish_job_async($db, (int) $jobId)) {
            $count++;
        }
    }

    return $count;
}

function distribution_publish_interval_seconds(string $platform = ''): int {
    $platformKey = strtoupper(preg_replace('/[^A-Z0-9]+/', '_', $platform));
    $specific = $platformKey !== '' ? env_value('DISTRIBUTION_' . $platformKey . '_MIN_INTERVAL_HOURS', '') : '';
    $hours = (float) ($specific !== '' ? $specific : env_value('DISTRIBUTION_PUBLISH_MIN_INTERVAL_HOURS', 4));
    return max(0, (int) round($hours * 3600));
}

function distribution_timestamp(?string $value): int {
    $value = trim((string) $value);
    if ($value === '') {
        return 0;
    }

    $timestamp = strtotime($value);
    return $timestamp === false ? 0 : $timestamp;
}

function distribution_publish_job_rate_gate(PDO $db, array $job): array {
    $jobId = (int) ($job['id'] ?? 0);
    $accountId = (int) ($job['account_id'] ?? 0);
    $platform = (string) ($job['platform'] ?? '');
    $interval = distribution_publish_interval_seconds($platform);
    $now = time();

    $scheduledAt = distribution_timestamp($job['scheduled_at'] ?? '');
    if ($scheduledAt > $now) {
        return [
            'allowed' => false,
            'next_at' => $scheduledAt,
            'message' => '发布任务已排期，将在 ' . date('Y-m-d H:i:s', $scheduledAt) . ' 后执行。',
        ];
    }

    if ($interval <= 0 || $accountId <= 0) {
        return ['allowed' => true, 'next_at' => $now, 'message' => ''];
    }

    $stmt = $db->prepare("
        SELECT id, status, started_at, finished_at, updated_at, created_at
        FROM media_publish_jobs
        WHERE account_id = ?
          AND id <> ?
          AND status IN ('running', 'success')
        ORDER BY COALESCE(started_at, finished_at, updated_at, created_at) DESC, id DESC
        LIMIT 1
    ");
    $stmt->execute([$accountId, $jobId]);
    $latest = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$latest) {
        return ['allowed' => true, 'next_at' => $now, 'message' => ''];
    }

    $latestStatus = (string) ($latest['status'] ?? '');
    $latestAt = distribution_timestamp($latest['started_at'] ?? '')
        ?: distribution_timestamp($latest['finished_at'] ?? '')
        ?: distribution_timestamp($latest['updated_at'] ?? '')
        ?: distribution_timestamp($latest['created_at'] ?? '');

    if ($latestStatus === 'running') {
        $nextAt = max($now + 300, $latestAt + $interval);
        return [
            'allowed' => false,
            'next_at' => $nextAt,
            'message' => '同一媒体账号已有发布任务执行中，已延后到 ' . date('Y-m-d H:i:s', $nextAt) . '。',
        ];
    }

    $nextAt = $latestAt + $interval;
    if ($latestAt > 0 && $nextAt > $now) {
        return [
            'allowed' => false,
            'next_at' => $nextAt,
            'message' => '同一媒体账号发布过于频繁，已按 ' . round($interval / 3600, 2) . ' 小时间隔延后到 ' . date('Y-m-d H:i:s', $nextAt) . '。',
        ];
    }

    return ['allowed' => true, 'next_at' => $now, 'message' => ''];
}

function distribution_reschedule_publish_job(PDO $db, int $jobId, int $nextAt, string $message): void {
    $stmt = $db->prepare("
        UPDATE media_publish_jobs
        SET status = 'queued',
            scheduled_at = ?,
            error_message = ?,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = ?
          AND status IN ('queued', 'failed', 'running')
    ");
    $stmt->execute([date('Y-m-d H:i:s', $nextAt), $message, $jobId]);
}

function distribution_inject_monitor_keywords_for_job(PDO $db, array $job, string $remoteUrl = ''): int {
    $articleId = (int) ($job['article_id'] ?? 0);
    if ($articleId <= 0 || !function_exists('geo_monitor_inject_article_keywords')) {
        return 0;
    }

    $stmt = $db->prepare("
        SELECT COALESCE(t.geo_customer_id, '') AS customer_id
        FROM articles a
        LEFT JOIN tasks t ON t.id = a.task_id
        WHERE a.id = ?
        LIMIT 1
    ");
    $stmt->execute([$articleId]);
    $customerId = trim((string) ($stmt->fetchColumn() ?: ''));
    if ($customerId === '') {
        return 0;
    }

    return geo_monitor_inject_article_keywords($db, $articleId, $customerId, $remoteUrl);
}

function distribution_auto_publish_enabled(): bool {
    $value = strtolower(trim((string) env_value('DISTRIBUTION_AUTO_PUBLISH_ENABLED', 'true')));
    return !in_array($value, ['0', 'false', 'off', 'no'], true);
}

function distribution_auto_start_enabled(): bool {
    $value = strtolower(trim((string) env_value('DISTRIBUTION_AUTO_START_ENABLED', 'true')));
    return in_array($value, ['1', 'true', 'on', 'yes'], true);
}

function distribution_browser_headless_enabled(): bool {
    $value = strtolower(trim((string) env_value('DISTRIBUTION_BROWSER_HEADLESS', 'true')));
    return !in_array($value, ['0', 'false', 'off', 'no'], true);
}

function distribution_handle_article_published(PDO $db, int $articleId): array {
    if (!distribution_auto_publish_enabled()) {
        return ['created' => 0, 'started' => 0, 'skipped' => 'auto_publish_disabled'];
    }

    try {
        ensure_distribution_schema($db);

        $articleStmt = $db->prepare("SELECT id, status FROM articles WHERE id = ? AND deleted_at IS NULL LIMIT 1");
        $articleStmt->execute([$articleId]);
        $article = $articleStmt->fetch(PDO::FETCH_ASSOC);
        if (!$article || (string) ($article['status'] ?? '') !== 'published') {
            return ['created' => 0, 'started' => 0, 'skipped' => 'article_not_published'];
        }

        $jobIds = distribution_enqueue_article_jobs($db, $articleId, []);
        $started = 0;
        if (!empty($jobIds) && distribution_auto_start_enabled()) {
            $started = distribution_start_article_queued_jobs_async($db, $articleId, count($jobIds));
        }

        return ['created' => count($jobIds), 'started' => $started, 'skipped' => ''];
    } catch (Throwable $e) {
        if (function_exists('write_log')) {
            write_log('媒体自动分发触发失败：文章ID ' . $articleId . '，' . $e->getMessage(), 'WARNING');
        } else {
            error_log('媒体自动分发触发失败：文章ID ' . $articleId . '，' . $e->getMessage());
        }

        return ['created' => 0, 'started' => 0, 'skipped' => 'error', 'error' => $e->getMessage()];
    }
}

function distribution_execute_queued_jobs(PDO $db, int $limit = 5): array {
    $limit = max(1, min(20, $limit));
    $stmt = $db->prepare("
        SELECT id
        FROM media_publish_jobs
        WHERE status = 'queued'
          AND (scheduled_at IS NULL OR scheduled_at <= CURRENT_TIMESTAMP)
        ORDER BY created_at ASC, id ASC
        LIMIT ?
    ");
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();

    $summary = ['success' => 0, 'failed' => 0, 'skipped' => 0, 'total' => 0];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $jobId) {
        $summary['total']++;
        $result = distribution_execute_publish_job($db, (int) $jobId);
        if (($result['status'] ?? '') === 'success') {
            $summary['success']++;
        } elseif (($result['status'] ?? '') === 'skipped') {
            $summary['skipped']++;
        } else {
            $summary['failed']++;
        }
    }

    return $summary;
}

function distribution_platform_label(string $platform): string {
    $platforms = distribution_platforms();
    return (string) ($platforms[$platform]['name'] ?? $platform);
}

function distribution_publish_zhihu_with_browser(array $job): array {
    $script = realpath(__DIR__ . '/../scripts/publish-zhihu.js');
    if (!$script || !is_file($script)) {
        throw new RuntimeException('知乎自动发布脚本不存在');
    }

    $nodeBin = env_value('DISTRIBUTION_NODE_BIN', '');
    if ($nodeBin === '') {
        $nodeBin = 'node';
    }

    $nodeModules = env_value('DISTRIBUTION_NODE_PATH', '');

    $profileRoot = env_value('DISTRIBUTION_BROWSER_PROFILE_DIR', __DIR__ . '/../data/browser-profiles');
    $profileDir = rtrim($profileRoot, '/\\') . '/zhihu-account-' . (int) ($job['account_id'] ?? 0);
    if (!is_dir($profileDir) && !mkdir($profileDir, 0755, true) && !is_dir($profileDir)) {
        throw new RuntimeException('无法创建浏览器登录态目录：' . $profileDir);
    }

    $tempRoot = sys_get_temp_dir() . '/geoflow-distribution';
    if (!is_dir($tempRoot) && !mkdir($tempRoot, 0755, true) && !is_dir($tempRoot)) {
        throw new RuntimeException('无法创建发布临时目录');
    }

    $inputFile = tempnam($tempRoot, 'zhihu-input-');
    $outputFile = tempnam($tempRoot, 'zhihu-output-');
    if (!$inputFile || !$outputFile) {
        throw new RuntimeException('无法创建发布临时文件');
    }

    $preparedContent = distribution_prepare_zhihu_content(
        (string) ($job['article_title'] ?: $job['title'] ?: ''),
        (string) ($job['article_content'] ?? ''),
        (string) ($job['article_excerpt'] ?? '')
    );

    $payload = [
        'title' => (string) ($job['article_title'] ?: $job['title'] ?: '未命名文章'),
        'content' => $preparedContent['markdown'],
        'contentText' => $preparedContent['text'],
        'contentHtml' => $preparedContent['html'],
        'excerpt' => distribution_normalize_article_text((string) ($job['article_excerpt'] ?? '')),
        'publishUrl' => distribution_zhihu_write_url((string) ($job['login_url'] ?? '')),
        'username' => trim((string) ($job['username'] ?? '')),
        'password' => decrypt_sensitive_value((string) ($job['credential'] ?? '')),
        'profileDir' => $profileDir,
        'loginWaitMs' => max(60, (int) env_value('DISTRIBUTION_LOGIN_WAIT_SECONDS', 600)) * 1000,
        'accountName' => (string) ($job['account_name'] ?? ''),
        'headless' => distribution_browser_headless_enabled(),
    ];

    file_put_contents($inputFile, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    $envPrefix = $nodeModules !== '' ? 'NODE_PATH=' . escapeshellarg($nodeModules) . ' ' : '';
    $command = $envPrefix
        . escapeshellarg($nodeBin) . ' '
        . escapeshellarg($script) . ' '
        . escapeshellarg($inputFile) . ' '
        . escapeshellarg($outputFile) . ' 2>&1';

    $lines = [];
    $exitCode = 0;
    exec($command, $lines, $exitCode);

    $output = is_file($outputFile) ? json_decode((string) file_get_contents($outputFile), true) : null;
    @unlink($inputFile);
    @unlink($outputFile);

    if (!is_array($output)) {
        $message = trim(implode("\n", $lines));
        throw new RuntimeException($message !== '' ? $message : '知乎自动发布脚本没有返回结果');
    }

    if (($output['ok'] ?? false) !== true || $exitCode !== 0) {
        $message = trim((string) ($output['error'] ?? ''));
        throw new RuntimeException($message !== '' ? $message : '知乎自动发布失败');
    }

    return ['remote_url' => (string) ($output['remote_url'] ?? '')];
}

function distribution_zhihu_write_url(string $configuredUrl = ''): string {
    $configuredUrl = trim($configuredUrl);
    if ($configuredUrl === '' || preg_match('~/creator/?$~', $configuredUrl)) {
        return 'https://zhuanlan.zhihu.com/write';
    }

    return $configuredUrl;
}

function distribution_publish_toutiao_with_browser(array $job): array {
    $script = realpath(__DIR__ . '/../scripts/publish-toutiao.js');
    if (!$script || !is_file($script)) {
        throw new RuntimeException('头条号自动发布脚本不存在');
    }

    $nodeBin = env_value('DISTRIBUTION_NODE_BIN', '');
    if ($nodeBin === '') {
        $nodeBin = 'node';
    }

    $nodeModules = env_value('DISTRIBUTION_NODE_PATH', '');

    $profileRoot = env_value('DISTRIBUTION_BROWSER_PROFILE_DIR', __DIR__ . '/../data/browser-profiles');
    $profileDir = rtrim($profileRoot, '/\\') . '/toutiao-account-' . (int) ($job['account_id'] ?? 0);
    if (!is_dir($profileDir) && !mkdir($profileDir, 0755, true) && !is_dir($profileDir)) {
        throw new RuntimeException('无法创建浏览器登录态目录：' . $profileDir);
    }

    $tempRoot = sys_get_temp_dir() . '/geoflow-distribution';
    if (!is_dir($tempRoot) && !mkdir($tempRoot, 0755, true) && !is_dir($tempRoot)) {
        throw new RuntimeException('无法创建发布临时目录');
    }

    $inputFile = tempnam($tempRoot, 'toutiao-input-');
    $outputFile = tempnam($tempRoot, 'toutiao-output-');
    if (!$inputFile || !$outputFile) {
        throw new RuntimeException('无法创建发布临时文件');
    }

    $preparedContent = distribution_prepare_zhihu_content(
        (string) ($job['article_title'] ?: $job['title'] ?: ''),
        (string) ($job['article_content'] ?? ''),
        (string) ($job['article_excerpt'] ?? '')
    );

    $payload = [
        'title' => (string) ($job['article_title'] ?: $job['title'] ?: '未命名文章'),
        'content' => $preparedContent['markdown'],
        'contentText' => $preparedContent['text'],
        'contentHtml' => $preparedContent['html'],
        'excerpt' => distribution_normalize_article_text((string) ($job['article_excerpt'] ?? '')),
        'publishUrl' => trim((string) ($job['login_url'] ?? '')) ?: 'https://mp.toutiao.com/profile_v4/xigua/publish/article',
        'profileDir' => $profileDir,
        'loginWaitMs' => max(60, (int) env_value('DISTRIBUTION_LOGIN_WAIT_SECONDS', 600)) * 1000,
        'accountName' => (string) ($job['account_name'] ?? ''),
    ];

    file_put_contents($inputFile, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    $envPrefix = $nodeModules !== '' ? 'NODE_PATH=' . escapeshellarg($nodeModules) . ' ' : '';
    $command = $envPrefix
        . escapeshellarg($nodeBin) . ' '
        . escapeshellarg($script) . ' '
        . escapeshellarg($inputFile) . ' '
        . escapeshellarg($outputFile) . ' 2>&1';

    $lines = [];
    $exitCode = 0;
    exec($command, $lines, $exitCode);

    $output = is_file($outputFile) ? json_decode((string) file_get_contents($outputFile), true) : null;
    @unlink($inputFile);
    @unlink($outputFile);

    if (!is_array($output)) {
        $message = trim(implode("\n", $lines));
        throw new RuntimeException($message !== '' ? $message : '头条号自动发布脚本没有返回结果');
    }

    if (($output['ok'] ?? false) !== true || $exitCode !== 0) {
        $message = trim((string) ($output['error'] ?? ''));
        throw new RuntimeException($message !== '' ? $message : '头条号自动发布失败');
    }

    return ['remote_url' => (string) ($output['remote_url'] ?? '')];
}

function distribution_publish_generic_with_browser(array $job, string $platformKey, string $defaultPublishUrl): array {
    $script = realpath(__DIR__ . '/../scripts/publish-generic.js');
    if (!$script || !is_file($script)) {
        throw new RuntimeException('通用自动发布脚本不存在');
    }

    $nodeBin = env_value('DISTRIBUTION_NODE_BIN', '');
    if ($nodeBin === '') {
        $nodeBin = 'node';
    }

    $nodeModules = env_value('DISTRIBUTION_NODE_PATH', '');
    $profileRoot = env_value('DISTRIBUTION_BROWSER_PROFILE_DIR', __DIR__ . '/../data/browser-profiles');
    $profileDir = rtrim($profileRoot, '/\\') . '/' . $platformKey . '-account-' . (int) ($job['account_id'] ?? 0);
    if (!is_dir($profileDir) && !mkdir($profileDir, 0755, true) && !is_dir($profileDir)) {
        throw new RuntimeException('无法创建浏览器登录态目录：' . $profileDir);
    }

    $tempRoot = sys_get_temp_dir() . '/geoflow-distribution';
    if (!is_dir($tempRoot) && !mkdir($tempRoot, 0755, true) && !is_dir($tempRoot)) {
        throw new RuntimeException('无法创建发布临时目录');
    }

    $inputFile = tempnam($tempRoot, $platformKey . '-input-');
    $outputFile = tempnam($tempRoot, $platformKey . '-output-');
    if (!$inputFile || !$outputFile) {
        throw new RuntimeException('无法创建发布临时文件');
    }

    $preparedContent = distribution_prepare_zhihu_content(
        (string) ($job['article_title'] ?: $job['title'] ?: ''),
        (string) ($job['article_content'] ?? ''),
        (string) ($job['article_excerpt'] ?? '')
    );

    $payload = [
        'platform' => $platformKey,
        'title' => (string) ($job['article_title'] ?: $job['title'] ?: '未命名文章'),
        'content' => $preparedContent['markdown'],
        'contentText' => $preparedContent['text'],
        'publishUrl' => trim((string) ($job['login_url'] ?? '')) ?: $defaultPublishUrl,
        'profileDir' => $profileDir,
        'loginWaitMs' => max(60, (int) env_value('DISTRIBUTION_LOGIN_WAIT_SECONDS', 600)) * 1000,
    ];
    file_put_contents($inputFile, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    $envPrefix = $nodeModules !== '' ? 'NODE_PATH=' . escapeshellarg($nodeModules) . ' ' : '';
    $command = $envPrefix
        . escapeshellarg($nodeBin) . ' '
        . escapeshellarg($script) . ' '
        . escapeshellarg($inputFile) . ' '
        . escapeshellarg($outputFile) . ' 2>&1';

    $lines = [];
    $exitCode = 0;
    exec($command, $lines, $exitCode);

    $output = is_file($outputFile) ? json_decode((string) file_get_contents($outputFile), true) : null;
    @unlink($inputFile);
    @unlink($outputFile);

    if (!is_array($output)) {
        $message = trim(implode("\n", $lines));
        throw new RuntimeException($message !== '' ? $message : '通用自动发布脚本没有返回结果');
    }

    if (($output['ok'] ?? false) !== true || $exitCode !== 0) {
        $message = trim((string) ($output['error'] ?? ''));
        throw new RuntimeException($message !== '' ? $message : distribution_platform_label($platformKey) . ' 自动发布失败');
    }

    return ['remote_url' => (string) ($output['remote_url'] ?? '')];
}

function distribution_normalize_article_text(string $content): string {
    $content = html_entity_decode(strip_tags($content), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $content = preg_replace("/\r\n|\r/", "\n", $content) ?? $content;
    $content = preg_replace("/\n{3,}/", "\n\n", $content) ?? $content;
    return trim($content);
}

function distribution_prepare_zhihu_content(string $title, string $content, string $excerpt = ''): array {
    $normalizedTitle = trim($title);
    $normalizedContent = str_replace(["\r\n", "\r"], "\n", (string) $content);
    $normalizedContent = trim($normalizedContent);

    if ($normalizedTitle !== '' && $normalizedContent !== '') {
        $quotedTitle = preg_quote($normalizedTitle, '/');
        $patterns = [
            '/^\s*#\s*' . $quotedTitle . '\s*(?:\n+|$)/u',
            '/^\s*\*\*' . $quotedTitle . '\*\*\s*(?:\n+|$)/u',
            '/^\s*' . $quotedTitle . '\s*(?:\n+|$)/u',
        ];
        foreach ($patterns as $pattern) {
            $normalizedContent = preg_replace($pattern, '', $normalizedContent, 1) ?? $normalizedContent;
            $normalizedContent = ltrim($normalizedContent);
        }
    }

    if ($normalizedContent === '') {
        $normalizedContent = trim((string) $excerpt);
    }
    if ($normalizedContent === '') {
        $normalizedContent = $normalizedTitle;
    }

    $html = function_exists('markdown_to_html')
        ? markdown_to_html($normalizedContent)
        : '<p>' . htmlspecialchars($normalizedContent, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</p>';

    $htmlForPaste = trim((string) preg_replace('/\sclass="[^"]*"/u', '', $html));
    $textForPaste = distribution_extract_text_from_html($htmlForPaste);

    return [
        'markdown' => trim($normalizedContent),
        'html' => $htmlForPaste,
        'text' => $textForPaste !== '' ? $textForPaste : distribution_normalize_article_text($normalizedContent),
    ];
}

function distribution_extract_text_from_html(string $html): string {
    if ($html === '') {
        return '';
    }

    $withBreaks = preg_replace('/<(\/p|\/h[1-6]|\/li|\/blockquote|\/ul|\/ol|br)\b[^>]*>/iu', "\n", $html) ?? $html;
    $text = html_entity_decode(strip_tags($withBreaks), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace("/\r\n|\r/", "\n", $text) ?? $text;
    $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;
    return trim($text);
}
