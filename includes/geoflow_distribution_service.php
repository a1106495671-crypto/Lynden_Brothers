<?php
/**
 * GEOFlow 对齐版分发管理：目标站渠道、文章分发队列与同步日志。
 */

if (!defined('FEISHU_TREASURE')) {
    die('Access denied');
}

function geoflow_distribution_ensure_schema(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS distribution_channels (
        id BIGSERIAL PRIMARY KEY,
        name VARCHAR(120) NOT NULL,
        domain VARCHAR(255) NOT NULL,
        endpoint_url VARCHAR(500) NOT NULL,
        channel_type VARCHAR(60) DEFAULT 'geoflow_agent',
        front_mode VARCHAR(30) DEFAULT 'static',
        template_key VARCHAR(120) DEFAULT NULL,
        site_settings TEXT DEFAULT NULL,
        channel_config TEXT DEFAULT NULL,
        status VARCHAR(30) DEFAULT 'active',
        description TEXT DEFAULT NULL,
        last_health_status VARCHAR(30) DEFAULT NULL,
        last_health_checked_at TIMESTAMP DEFAULT NULL,
        last_error_message TEXT DEFAULT NULL,
        created_by_admin_id BIGINT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS distribution_channel_secrets (
        id BIGSERIAL PRIMARY KEY,
        distribution_channel_id BIGINT NOT NULL,
        key_id VARCHAR(80) NOT NULL UNIQUE,
        secret_ciphertext TEXT NOT NULL,
        status VARCHAR(30) DEFAULT 'active',
        scopes TEXT DEFAULT NULL,
        last_used_at TIMESTAMP DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS task_distribution_channels (
        id BIGSERIAL PRIMARY KEY,
        task_id BIGINT NOT NULL,
        distribution_channel_id BIGINT NOT NULL,
        trigger VARCHAR(60) DEFAULT 'after_local_publish',
        remote_status VARCHAR(40) DEFAULT 'follow_local',
        failure_policy VARCHAR(60) DEFAULT 'ignore_distribution_failure',
        max_attempts INTEGER DEFAULT 3,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS article_distributions (
        id BIGSERIAL PRIMARY KEY,
        article_id BIGINT NOT NULL,
        distribution_channel_id BIGINT NOT NULL,
        action VARCHAR(30) DEFAULT 'publish',
        status VARCHAR(30) DEFAULT 'queued',
        remote_id VARCHAR(120) DEFAULT NULL,
        remote_url VARCHAR(500) DEFAULT NULL,
        remote_meta TEXT DEFAULT NULL,
        idempotency_key VARCHAR(120) NOT NULL UNIQUE,
        attempt_count INTEGER DEFAULT 0,
        next_retry_at TIMESTAMP DEFAULT NULL,
        last_attempt_at TIMESTAMP DEFAULT NULL,
        last_error_message TEXT DEFAULT NULL,
        payload_hash VARCHAR(64) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS distribution_logs (
        id BIGSERIAL PRIMARY KEY,
        distribution_channel_id BIGINT DEFAULT NULL,
        article_distribution_id BIGINT DEFAULT NULL,
        article_id BIGINT DEFAULT NULL,
        level VARCHAR(20) DEFAULT 'info',
        event VARCHAR(120) DEFAULT NULL,
        message TEXT NOT NULL,
        context TEXT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    $db->exec("CREATE INDEX IF NOT EXISTS idx_distribution_channels_status ON distribution_channels(status)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_article_distributions_status ON article_distributions(status)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_article_distributions_article ON article_distributions(article_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_article_distributions_channel ON article_distributions(distribution_channel_id)");
    $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_article_distribution_unique ON article_distributions(article_id, distribution_channel_id, action)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_distribution_logs_channel ON distribution_logs(distribution_channel_id)");

    $columns = [
        'distribution_channels' => [
            'channel_type' => "ALTER TABLE distribution_channels ADD COLUMN channel_type VARCHAR(60) DEFAULT 'geoflow_agent'",
            'front_mode' => "ALTER TABLE distribution_channels ADD COLUMN front_mode VARCHAR(30) DEFAULT 'static'",
            'template_key' => "ALTER TABLE distribution_channels ADD COLUMN template_key VARCHAR(120) DEFAULT NULL",
            'site_settings' => "ALTER TABLE distribution_channels ADD COLUMN site_settings TEXT DEFAULT NULL",
            'channel_config' => "ALTER TABLE distribution_channels ADD COLUMN channel_config TEXT DEFAULT NULL",
            'last_health_status' => "ALTER TABLE distribution_channels ADD COLUMN last_health_status VARCHAR(30) DEFAULT NULL",
            'last_health_checked_at' => "ALTER TABLE distribution_channels ADD COLUMN last_health_checked_at TIMESTAMP DEFAULT NULL",
            'last_error_message' => "ALTER TABLE distribution_channels ADD COLUMN last_error_message TEXT DEFAULT NULL",
        ],
        'article_distributions' => [
            'remote_meta' => "ALTER TABLE article_distributions ADD COLUMN remote_meta TEXT DEFAULT NULL",
            'idempotency_key' => "ALTER TABLE article_distributions ADD COLUMN idempotency_key VARCHAR(120)",
            'payload_hash' => "ALTER TABLE article_distributions ADD COLUMN payload_hash VARCHAR(64) DEFAULT NULL",
            'next_retry_at' => "ALTER TABLE article_distributions ADD COLUMN next_retry_at TIMESTAMP DEFAULT NULL",
            'last_attempt_at' => "ALTER TABLE article_distributions ADD COLUMN last_attempt_at TIMESTAMP DEFAULT NULL",
        ],
        'distribution_logs' => [
            'event' => "ALTER TABLE distribution_logs ADD COLUMN event VARCHAR(120) DEFAULT NULL",
        ],
    ];
    foreach ($columns as $table => $tableColumns) {
        foreach ($tableColumns as $column => $sql) {
            if (function_exists('db_column_exists') && !db_column_exists($db, $table, $column)) {
                $db->exec($sql);
            }
        }
    }
}

function geoflow_distribution_status_label(string $status): string {
    return [
        'active' => '启用',
        'paused' => '暂停',
        'queued' => '待处理',
        'sending' => '发送中',
        'synced' => '已同步',
        'failed' => '失败',
        'deleted' => '已删除',
    ][$status] ?? $status;
}

function geoflow_distribution_channel_type_label(string $type): string {
    return $type === 'wordpress_rest' ? 'WordPress REST 渠道' : 'GEOFlow Agent';
}

function geoflow_distribution_json(array $data): string {
    return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
}

function geoflow_distribution_decode(?string $json): array {
    $decoded = json_decode((string)$json, true);
    return is_array($decoded) ? $decoded : [];
}

function geoflow_distribution_normalize_domain(string $domain): string {
    $domain = trim($domain);
    $domain = preg_replace('#^https?://#i', '', $domain) ?? $domain;
    return rtrim($domain, '/');
}

function geoflow_distribution_site_settings(array $input): array {
    $siteName = trim((string)($input['site_name'] ?? $input['name'] ?? 'GEOFlow Target Site'));
    $siteName = $siteName !== '' ? $siteName : 'GEOFlow Target Site';
    return [
        'site_name' => $siteName,
        'site_subtitle' => trim((string)($input['site_subtitle'] ?? '')),
        'site_description' => trim((string)($input['site_description'] ?? '由 GEOFlow 自动分发和管理的目标站点。')),
        'site_keywords' => trim((string)($input['site_keywords'] ?? '')),
        'copyright_info' => trim((string)($input['copyright_info'] ?? ('© ' . date('Y') . ' ' . $siteName))),
        'site_logo' => trim((string)($input['site_logo'] ?? '')),
        'site_favicon' => trim((string)($input['site_favicon'] ?? '')),
        'seo_title_template' => trim((string)($input['seo_title_template'] ?? '{title} - {site_name}')),
        'seo_description_template' => trim((string)($input['seo_description_template'] ?? '{description}')),
        'featured_limit' => min(100, max(1, (int)($input['featured_limit'] ?? 6))),
        'per_page' => min(200, max(1, (int)($input['per_page'] ?? 12))),
    ];
}

function geoflow_distribution_channel_config(array $input): array {
    $postStatus = (string)($input['wordpress_post_status'] ?? 'publish');
    $categoryStrategy = (string)($input['wordpress_category_strategy'] ?? 'match_or_create');
    $tagStrategy = (string)($input['wordpress_tag_strategy'] ?? 'keywords_to_tags');
    $imageStrategy = (string)($input['wordpress_image_strategy'] ?? 'upload_to_media');
    return [
        'wordpress_username' => trim((string)($input['wordpress_username'] ?? '')),
        'wordpress_post_status' => in_array($postStatus, ['publish', 'draft', 'pending', 'private'], true) ? $postStatus : 'publish',
        'wordpress_category_strategy' => in_array($categoryStrategy, ['match_or_create', 'match_only', 'fixed'], true) ? $categoryStrategy : 'match_or_create',
        'wordpress_fixed_category' => trim((string)($input['wordpress_fixed_category'] ?? '')),
        'wordpress_tag_strategy' => in_array($tagStrategy, ['keywords_to_tags', 'disabled'], true) ? $tagStrategy : 'keywords_to_tags',
        'wordpress_image_strategy' => in_array($imageStrategy, ['upload_to_media', 'keep_original'], true) ? $imageStrategy : 'upload_to_media',
        'wordpress_content_format' => 'html',
    ];
}

function geoflow_distribution_log(PDO $db, string $level, string $message, ?int $channelId = null, ?int $distributionId = null, ?int $articleId = null, array $context = []): void {
    $stmt = $db->prepare("INSERT INTO distribution_logs (distribution_channel_id, article_distribution_id, article_id, level, event, message, context, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)");
    $stmt->execute([$channelId, $distributionId, $articleId, $level, (string)($context['event'] ?? ''), $message, $context ? geoflow_distribution_json($context) : null]);
}

function geoflow_distribution_create_secret(PDO $db, int $channelId, string $plainSecret = ''): array {
    $keyId = 'gfa_' . bin2hex(random_bytes(8));
    $secret = $plainSecret !== '' ? $plainSecret : 'gfs_' . bin2hex(random_bytes(24));
    $db->prepare("UPDATE distribution_channel_secrets SET status = 'revoked', updated_at = CURRENT_TIMESTAMP WHERE distribution_channel_id = ? AND status = 'active'")->execute([$channelId]);
    $stmt = $db->prepare("INSERT INTO distribution_channel_secrets (distribution_channel_id, key_id, secret_ciphertext, status, scopes, created_at, updated_at)
        VALUES (?, ?, ?, 'active', ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
    $stmt->execute([$channelId, $keyId, base64_encode($secret), geoflow_distribution_json(['articles:write', 'site:write'])]);
    return ['key_id' => $keyId, 'secret' => $secret];
}

function geoflow_distribution_save_channel(PDO $db, array $input): array {
    geoflow_distribution_ensure_schema($db);
    $name = trim((string)($input['name'] ?? ''));
    $domain = geoflow_distribution_normalize_domain((string)($input['domain'] ?? ''));
    $endpoint = trim((string)($input['endpoint_url'] ?? ''));
    if ($name === '' || $domain === '' || $endpoint === '') {
        throw new InvalidArgumentException('请填写渠道名称、域名和 Agent/REST 地址');
    }

    $type = in_array(($input['channel_type'] ?? 'geoflow_agent'), ['geoflow_agent', 'wordpress_rest'], true) ? (string)$input['channel_type'] : 'geoflow_agent';
    $frontMode = in_array(($input['front_mode'] ?? 'static'), ['static', 'rewrite'], true) ? (string)$input['front_mode'] : 'static';
    $status = in_array(($input['status'] ?? 'active'), ['active', 'paused'], true) ? (string)$input['status'] : 'active';
    $channelId = (int)($input['channel_id'] ?? 0);
    $siteSettings = geoflow_distribution_site_settings($input);
    $channelConfig = geoflow_distribution_channel_config($input);

    if ($channelId > 0) {
        $stmt = $db->prepare("UPDATE distribution_channels
            SET name = ?, domain = ?, endpoint_url = ?, channel_type = ?, front_mode = ?, template_key = ?,
                site_settings = ?, channel_config = ?, status = ?, description = ?, updated_at = CURRENT_TIMESTAMP
            WHERE id = ?");
        $stmt->execute([$name, $domain, $endpoint, $type, $frontMode, trim((string)($input['template_key'] ?? '')) ?: null,
            geoflow_distribution_json($siteSettings), geoflow_distribution_json($channelConfig), $status,
            trim((string)($input['description'] ?? '')) ?: null, $channelId]);
        $secret = null;
        if ($type === 'wordpress_rest' && trim((string)($input['wordpress_application_password'] ?? '')) !== '') {
            $secret = geoflow_distribution_create_secret($db, $channelId, trim((string)$input['wordpress_application_password']));
        }
        geoflow_distribution_log($db, 'info', '分发渠道已更新', $channelId, null, null, ['event' => 'channel.updated']);
        return ['channel_id' => $channelId, 'secret' => $secret];
    }

    $stmt = $db->prepare("INSERT INTO distribution_channels
        (name, domain, endpoint_url, channel_type, front_mode, template_key, site_settings, channel_config, status, description, created_by_admin_id, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
    $stmt->execute([$name, $domain, $endpoint, $type, $frontMode, trim((string)($input['template_key'] ?? '')) ?: null,
        geoflow_distribution_json($siteSettings), geoflow_distribution_json($channelConfig), $status,
        trim((string)($input['description'] ?? '')) ?: null, (int)($_SESSION['admin_id'] ?? 0) ?: null]);
    $channelId = function_exists('db_last_insert_id') ? db_last_insert_id($db, 'distribution_channels') : (int)$db->lastInsertId();
    $secret = geoflow_distribution_create_secret($db, $channelId, $type === 'wordpress_rest' ? trim((string)($input['wordpress_application_password'] ?? '')) : '');
    geoflow_distribution_log($db, 'info', '分发渠道已创建', $channelId, null, null, ['event' => 'channel.created']);
    return ['channel_id' => $channelId, 'secret' => $secret];
}

function geoflow_distribution_get_channels(PDO $db): array {
    geoflow_distribution_ensure_schema($db);
    $stmt = $db->query("SELECT c.*,
        COALESCE(SUM(CASE WHEN ad.status IN ('queued','sending') THEN 1 ELSE 0 END), 0) AS pending_count,
        COALESCE(SUM(CASE WHEN ad.status = 'failed' THEN 1 ELSE 0 END), 0) AS failed_count
        FROM distribution_channels c
        LEFT JOIN article_distributions ad ON ad.distribution_channel_id = c.id
        GROUP BY c.id
        ORDER BY c.id DESC");
    return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
}

function geoflow_distribution_get_channel(PDO $db, int $channelId): ?array {
    geoflow_distribution_ensure_schema($db);
    $stmt = $db->prepare("SELECT * FROM distribution_channels WHERE id = ?");
    $stmt->execute([$channelId]);
    $channel = $stmt->fetch(PDO::FETCH_ASSOC);
    return $channel ?: null;
}

function geoflow_distribution_stats(PDO $db): array {
    geoflow_distribution_ensure_schema($db);
    return [
        'total' => (int)$db->query("SELECT COUNT(*) FROM distribution_channels")->fetchColumn(),
        'active' => (int)$db->query("SELECT COUNT(*) FROM distribution_channels WHERE status = 'active'")->fetchColumn(),
        'pending' => (int)$db->query("SELECT COUNT(*) FROM article_distributions WHERE status IN ('queued','sending')")->fetchColumn(),
        'failed' => (int)$db->query("SELECT COUNT(*) FROM article_distributions WHERE status = 'failed'")->fetchColumn(),
    ];
}

function geoflow_distribution_recent_logs(PDO $db, int $limit = 10): array {
    geoflow_distribution_ensure_schema($db);
    $stmt = $db->prepare("SELECT l.*, c.name AS channel_name, a.title AS article_title
        FROM distribution_logs l
        LEFT JOIN distribution_channels c ON c.id = l.distribution_channel_id
        LEFT JOIN articles a ON a.id = l.article_id
        ORDER BY l.id DESC LIMIT ?");
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function geoflow_distribution_enqueue_article(PDO $db, int $articleId, ?array $channelIds = null, string $action = 'publish'): int {
    geoflow_distribution_ensure_schema($db);
    $articleStmt = $db->prepare("SELECT id, status, title, content FROM articles WHERE id = ? AND deleted_at IS NULL");
    $articleStmt->execute([$articleId]);
    $article = $articleStmt->fetch(PDO::FETCH_ASSOC);
    if (!$article || (string)($article['status'] ?? '') !== 'published') {
        return 0;
    }

    if ($channelIds === null || empty($channelIds)) {
        $channelIds = array_map('intval', $db->query("SELECT id FROM distribution_channels WHERE status = 'active'")->fetchAll(PDO::FETCH_COLUMN) ?: []);
    } else {
        $channelIds = array_values(array_unique(array_map('intval', $channelIds)));
    }
    if (empty($channelIds)) {
        return 0;
    }

    $channelStmt = $db->prepare("SELECT id FROM distribution_channels WHERE id = ? AND status = 'active'");
    $existsStmt = $db->prepare("SELECT id FROM article_distributions WHERE article_id = ? AND distribution_channel_id = ? AND action = ? LIMIT 1");
    $insert = $db->prepare("INSERT INTO article_distributions
        (article_id, distribution_channel_id, action, status, idempotency_key, payload_hash, next_retry_at, created_at, updated_at)
        VALUES (?, ?, ?, 'queued', ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");

    $payloadHash = hash('sha256', (string)$article['title'] . "\n" . (string)$article['content']);
    $created = 0;
    foreach ($channelIds as $channelId) {
        $channelStmt->execute([$channelId]);
        if (!$channelStmt->fetchColumn()) {
            continue;
        }
        $existsStmt->execute([$articleId, $channelId, $action]);
        if ($existsStmt->fetchColumn()) {
            continue;
        }
        $idempotencyKey = sprintf('article-%d-channel-%d-%s-%s', $articleId, $channelId, $action, substr($payloadHash, 0, 12));
        $insert->execute([$articleId, $channelId, $action, $idempotencyKey, $payloadHash]);
        $distributionId = function_exists('db_last_insert_id') ? db_last_insert_id($db, 'article_distributions') : (int)$db->lastInsertId();
        geoflow_distribution_log($db, 'info', '文章已加入分发队列', $channelId, $distributionId, $articleId, ['event' => 'article_distribution.queued']);
        $created++;
    }
    return $created;
}

function geoflow_distribution_jobs(PDO $db, string $status = '', int $channelId = 0, int $limit = 50, int $offset = 0): array {
    geoflow_distribution_ensure_schema($db);
    $where = [];
    $params = [];
    if (in_array($status, ['queued', 'sending', 'synced', 'failed', 'deleted'], true)) {
        $where[] = 'ad.status = ?';
        $params[] = $status;
    }
    if ($channelId > 0) {
        $where[] = 'ad.distribution_channel_id = ?';
        $params[] = $channelId;
    }
    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $stmt = $db->prepare("SELECT ad.*, a.title AS article_title, a.slug AS article_slug, a.status AS article_status,
            c.name AS channel_name, c.domain AS channel_domain
        FROM article_distributions ad
        LEFT JOIN articles a ON a.id = ad.article_id
        LEFT JOIN distribution_channels c ON c.id = ad.distribution_channel_id
        {$whereSql}
        ORDER BY ad.id DESC LIMIT {$limit} OFFSET {$offset}");
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function geoflow_distribution_jobs_count(PDO $db, string $status = '', int $channelId = 0): int {
    geoflow_distribution_ensure_schema($db);
    $where = [];
    $params = [];
    if (in_array($status, ['queued', 'sending', 'synced', 'failed', 'deleted'], true)) {
        $where[] = 'status = ?';
        $params[] = $status;
    }
    if ($channelId > 0) {
        $where[] = 'distribution_channel_id = ?';
        $params[] = $channelId;
    }
    $stmt = $db->prepare("SELECT COUNT(*) FROM article_distributions" . ($where ? ' WHERE ' . implode(' AND ', $where) : ''));
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

function geoflow_distribution_retry(PDO $db, int $distributionId): void {
    geoflow_distribution_ensure_schema($db);
    $db->prepare("UPDATE article_distributions SET status = 'queued', last_error_message = NULL, next_retry_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$distributionId]);
    geoflow_distribution_log($db, 'info', '分发任务已手动重新入队', null, $distributionId, null, ['event' => 'distribution.retry_queued']);
}

function geoflow_distribution_set_channel_status(PDO $db, int $channelId, string $status): void {
    if (!in_array($status, ['active', 'paused'], true)) {
        throw new InvalidArgumentException('渠道状态无效');
    }
    geoflow_distribution_ensure_schema($db);
    $db->prepare("UPDATE distribution_channels SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$status, $channelId]);
    geoflow_distribution_log($db, 'info', $status === 'active' ? '分发渠道已启用' : '分发渠道已暂停', $channelId, null, null, ['event' => 'channel.status_changed']);
}

function geoflow_distribution_health(PDO $db, int $channelId): array {
    $channel = geoflow_distribution_get_channel($db, $channelId);
    if (!$channel) {
        throw new InvalidArgumentException('渠道不存在');
    }

    try {
        if ((string)($channel['channel_type'] ?? 'geoflow_agent') === 'wordpress_rest') {
            $result = geoflow_distribution_wordpress_request($db, $channel, 'GET', geoflow_distribution_wordpress_base_url($channel) . '/wp/v2/users/me', null, 10);
        } else {
            $result = geoflow_distribution_agent_health($db, $channel);
            if (isset($result['agent_base_url']) && is_string($result['agent_base_url']) && trim($result['agent_base_url']) !== '') {
                $db->prepare("UPDATE distribution_channels SET endpoint_url = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?")
                    ->execute([rtrim($result['agent_base_url'], '/'), $channelId]);
            }
        }
        $db->prepare("UPDATE distribution_channels SET last_health_status = 'ok', last_health_checked_at = CURRENT_TIMESTAMP, last_error_message = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = ?")
            ->execute([$channelId]);
        geoflow_distribution_log($db, 'info', '目标站点健康检查通过', $channelId, null, null, ['event' => 'channel.health_checked', 'result' => $result]);
        return ['status' => 'ok'] + $result;
    } catch (Throwable $e) {
        $db->prepare("UPDATE distribution_channels SET last_health_status = 'failed', last_health_checked_at = CURRENT_TIMESTAMP, last_error_message = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?")
            ->execute([$e->getMessage(), $channelId]);
        geoflow_distribution_log($db, 'error', '目标站点健康检查失败', $channelId, null, null, ['event' => 'channel.health_checked', 'error' => $e->getMessage()]);
        throw $e;
    }
}

function geoflow_distribution_active_secret(PDO $db, int $channelId): ?array {
    $stmt = $db->prepare("SELECT * FROM distribution_channel_secrets WHERE distribution_channel_id = ? AND status = 'active' ORDER BY id DESC LIMIT 1");
    $stmt->execute([$channelId]);
    $secret = $stmt->fetch(PDO::FETCH_ASSOC);
    return $secret ?: null;
}

function geoflow_distribution_plain_secret(?array $secret): string {
    if (!$secret) {
        return '';
    }
    $decoded = base64_decode((string)($secret['secret_ciphertext'] ?? ''), true);
    return is_string($decoded) ? $decoded : '';
}

function geoflow_distribution_uuid(): string {
    $hex = bin2hex(random_bytes(16));
    return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
}

function geoflow_distribution_signed_headers(array $secret, string $method, string $path, string $body, string $event, string $idempotencyKey): array {
    $plainSecret = geoflow_distribution_plain_secret($secret);
    if ($plainSecret === '') {
        throw new RuntimeException('分发渠道密钥无法解密');
    }
    $timestamp = gmdate('c');
    $nonce = geoflow_distribution_uuid();
    $bodyHash = hash('sha256', $body);
    $signature = hash_hmac('sha256', strtoupper($method) . "\n" . $path . "\n" . $timestamp . "\n" . $nonce . "\n" . $bodyHash, $plainSecret);
    return [
        'Content-Type: application/json',
        'Accept: application/json',
        'X-GEOFlow-Key-Id: ' . (string)$secret['key_id'],
        'X-GEOFlow-Timestamp: ' . $timestamp,
        'X-GEOFlow-Nonce: ' . $nonce,
        'X-GEOFlow-Idempotency-Key: ' . $idempotencyKey,
        'X-GEOFlow-Body-SHA256: ' . $bodyHash,
        'X-GEOFlow-Signature: ' . $signature,
        'X-GEOFlow-Event: ' . $event,
    ];
}

function geoflow_distribution_http_request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 30): array {
    $status = 0;
    $responseHeaders = [];
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $header) use (&$responseHeaders): int {
                $responseHeaders[] = trim($header);
                return strlen($header);
            },
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            throw new RuntimeException($error !== '' ? $error : 'HTTP 请求失败');
        }
    } else {
        $context = stream_context_create([
            'http' => [
                'method' => strtoupper($method),
                'header' => implode("\r\n", $headers),
                'content' => $body ?? '',
                'timeout' => $timeout,
                'ignore_errors' => true,
            ],
        ]);
        $raw = @file_get_contents($url, false, $context);
        $responseHeaders = function_exists('http_get_last_response_headers')
            ? (http_get_last_response_headers() ?: [])
            : [];
        if (!empty($responseHeaders[0]) && preg_match('/\s(\d{3})\s/', (string)$responseHeaders[0], $m)) {
            $status = (int)$m[1];
        }
        if ($raw === false) {
            throw new RuntimeException('HTTP 请求失败：' . $url);
        }
    }

    $decoded = json_decode((string)$raw, true);
    return [
        'status' => $status,
        'body' => (string)$raw,
        'json' => is_array($decoded) ? $decoded : null,
        'headers' => $responseHeaders,
    ];
}

function geoflow_distribution_failure_message(string $operation, array $response, string $endpoint): string {
    $status = (int)($response['status'] ?? 0);
    if ($status === 404) {
        return '目标站 Agent 接口未找到（请求地址：' . $endpoint . '）。请先下载并部署目标站点包，确认入口指向 public/index.php；如部署在二级目录，请把 Agent 基础地址填写为包含该目录的入口地址。';
    }
    if (in_array($status, [401, 403], true)) {
        return $operation . '失败：HTTP ' . $status . '，目标站 Agent 鉴权未通过。请确认密钥 ID 和密钥明文一致。';
    }
    $plain = html_entity_decode(strip_tags((string)($response['body'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $plain = preg_replace('/\s+/', ' ', trim($plain)) ?? '';
    if (mb_strlen($plain) > 300) {
        $plain = mb_substr($plain, 0, 300) . '...';
    }
    return $operation . '失败：HTTP ' . $status . ($plain !== '' ? ' ' . $plain : '');
}

function geoflow_distribution_send_agent_json(PDO $db, array $channel, string $path, string $event, string $idempotencyKey, array $payload, string $operation, string $method = 'POST'): array {
    $secret = geoflow_distribution_active_secret($db, (int)$channel['id']);
    if (!$secret) {
        throw new RuntimeException('分发渠道有效密钥不存在');
    }
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($body)) {
        throw new RuntimeException($operation . '载荷 JSON 编码失败');
    }
    $headers = geoflow_distribution_signed_headers($secret, $method, $path, $body, $event, $idempotencyKey);
    $endpoint = rtrim((string)$channel['endpoint_url'], '/') . $path;
    $response = geoflow_distribution_http_request($method, $endpoint, $headers, $body, 30);
    $failedEndpoint = $endpoint;
    $failedResponse = $response;

    if ((int)$response['status'] === 404 && !str_ends_with(rtrim((string)$channel['endpoint_url'], '/'), '/index.php')) {
        $fallbackBase = rtrim((string)$channel['endpoint_url'], '/') . '/index.php';
        $fallbackEndpoint = $fallbackBase . $path;
        $fallbackResponse = geoflow_distribution_http_request($method, $fallbackEndpoint, $headers, $body, 30);
        $failedEndpoint = $fallbackEndpoint;
        $failedResponse = $fallbackResponse;
        if ((int)$fallbackResponse['status'] < 400) {
            $db->prepare("UPDATE distribution_channels SET endpoint_url = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$fallbackBase, (int)$channel['id']]);
            $db->prepare("UPDATE distribution_channel_secrets SET last_used_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([(int)$secret['id']]);
            return $fallbackResponse['json'] ?? ['ok' => true];
        }
    }

    $db->prepare("UPDATE distribution_channel_secrets SET last_used_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([(int)$secret['id']]);
    if ((int)$failedResponse['status'] >= 400 || (int)$failedResponse['status'] === 0) {
        throw new RuntimeException(geoflow_distribution_failure_message($operation, $failedResponse, $failedEndpoint));
    }
    return $response['json'] ?? ['ok' => true];
}

function geoflow_distribution_agent_health(PDO $db, array $channel): array {
    $secret = geoflow_distribution_active_secret($db, (int)$channel['id']);
    $path = '/geoflow-agent/v1/health';
    $body = '{}';
    $headers = ['Accept: application/json'];
    if ($secret) {
        $headers = geoflow_distribution_signed_headers($secret, 'GET', $path, $body, 'health.check', 'health-channel-' . (int)$channel['id'] . '-' . time());
    }
    $endpoint = rtrim((string)$channel['endpoint_url'], '/') . $path;
    $response = geoflow_distribution_http_request('GET', $endpoint, $headers, null, 10);
    if ((int)$response['status'] === 404 && !str_ends_with(rtrim((string)$channel['endpoint_url'], '/'), '/index.php')) {
        $fallbackBase = rtrim((string)$channel['endpoint_url'], '/') . '/index.php';
        $fallback = geoflow_distribution_http_request('GET', $fallbackBase . $path, $headers, null, 10);
        if ((int)$fallback['status'] < 400) {
            $result = $fallback['json'] ?? ['ok' => true];
            $result['agent_base_url'] = $fallbackBase;
            return $result;
        }
        $response = $fallback;
        $endpoint = $fallbackBase . $path;
    }
    if ((int)$response['status'] >= 400 || (int)$response['status'] === 0) {
        throw new RuntimeException(geoflow_distribution_failure_message('目标站健康检查', $response, $endpoint));
    }
    return $response['json'] ?? ['ok' => true];
}

function geoflow_distribution_article_payload(PDO $db, int $articleId): array {
    $stmt = $db->prepare("
        SELECT a.*, c.name AS category_name, c.slug AS category_slug, au.name AS author_name, t.name AS task_name
        FROM articles a
        LEFT JOIN categories c ON c.id = a.category_id
        LEFT JOIN authors au ON au.id = a.author_id
        LEFT JOIN tasks t ON t.id = a.task_id
        WHERE a.id = ? AND a.deleted_at IS NULL
        LIMIT 1
    ");
    $stmt->execute([$articleId]);
    $article = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$article) {
        throw new RuntimeException('文章不存在或已删除');
    }
    $title = (string)($article['title'] ?? '');
    $content = (string)($article['content'] ?? '');
    $body = $content;
    if ($title !== '') {
        $quoted = preg_quote($title, '/');
        $body = preg_replace('/^\s*#\s*' . $quoted . '\s*(?:\n+|$)/u', '', $body, 1) ?? $body;
    }
    $contentHtml = function_exists('markdown_to_html')
        ? markdown_to_html($body)
        : '<p>' . htmlspecialchars($body, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</p>';

    return [
        'version' => '1.0',
        'source' => 'geoflow',
        'event' => 'article.publish',
        'article' => [
            'id' => (int)$article['id'],
            'title' => $title,
            'slug' => (string)($article['slug'] ?? ''),
            'excerpt' => (string)($article['excerpt'] ?? ''),
            'content' => $content,
            'content_format' => 'markdown',
            'content_html' => $contentHtml,
            'hero_image_url' => '',
            'keywords' => (string)($article['keywords'] ?? ''),
            'meta_description' => (string)($article['meta_description'] ?? ''),
            'status' => (string)($article['status'] ?? ''),
            'published_at' => (string)($article['published_at'] ?? ''),
            'updated_at' => (string)($article['updated_at'] ?? ''),
            'category' => !empty($article['category_id']) ? [
                'id' => (int)$article['category_id'],
                'name' => (string)($article['category_name'] ?? ''),
                'slug' => (string)($article['category_slug'] ?? ''),
            ] : null,
            'author' => !empty($article['author_id']) ? [
                'id' => (int)$article['author_id'],
                'name' => (string)($article['author_name'] ?? ''),
            ] : null,
            'task' => !empty($article['task_id']) ? [
                'id' => (int)$article['task_id'],
                'name' => (string)($article['task_name'] ?? ''),
            ] : null,
        ],
        'assets' => ['images' => []],
    ];
}

function geoflow_distribution_process_job(PDO $db, int $distributionId): array {
    geoflow_distribution_ensure_schema($db);
    $stmt = $db->prepare("SELECT ad.*, c.channel_type, c.endpoint_url, c.channel_config, c.site_settings, c.name AS channel_name, c.id AS channel_id
        FROM article_distributions ad
        LEFT JOIN distribution_channels c ON c.id = ad.distribution_channel_id
        WHERE ad.id = ? LIMIT 1");
    $stmt->execute([$distributionId]);
    $job = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$job) {
        return ['status' => 'skipped', 'error_message' => '分发任务不存在'];
    }
    if (!in_array((string)$job['status'], ['queued', 'failed'], true)) {
        return ['status' => 'skipped', 'error_message' => '任务状态不是待处理'];
    }
    $channel = geoflow_distribution_get_channel($db, (int)$job['distribution_channel_id']);
    if (!$channel || (string)($channel['status'] ?? '') !== 'active') {
        return ['status' => 'skipped', 'error_message' => '渠道未启用'];
    }

    $db->prepare("UPDATE article_distributions SET status = 'sending', attempt_count = attempt_count + 1, last_attempt_at = CURRENT_TIMESTAMP, last_error_message = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = ?")
        ->execute([$distributionId]);

    try {
        $action = (string)($job['action'] ?? 'publish');
        $payload = geoflow_distribution_article_payload($db, (int)$job['article_id']);
        if ($action === 'update') {
            $payload['event'] = 'article.update';
        }

        if ((string)($channel['channel_type'] ?? 'geoflow_agent') === 'wordpress_rest') {
            $result = geoflow_distribution_wordpress_publish_or_update($db, $channel, $job, $payload, $action);
        } else {
            $path = '/geoflow-agent/v1/articles';
            $event = 'article.publish';
            if ($action === 'update') {
                $path = '/geoflow-agent/v1/articles/' . rawurlencode((string)($payload['article']['slug'] ?? '')) . '/update';
                $event = 'article.update';
            } elseif ($action === 'delete') {
                $path = '/geoflow-agent/v1/articles/' . rawurlencode((string)($payload['article']['slug'] ?? '')) . '/delete';
                $event = 'article.delete';
                $payload = [
                    'version' => '1.0',
                    'source' => 'geoflow',
                    'event' => 'article.delete',
                    'article' => [
                        'id' => (int)$job['article_id'],
                        'slug' => (string)($payload['article']['slug'] ?? ''),
                        'title' => (string)($payload['article']['title'] ?? ''),
                    ],
                ];
            }
            if (str_contains($path, '//update') || str_contains($path, '//delete')) {
                throw new RuntimeException('分发文章缺少 slug，无法同步目标站。');
            }
            $result = geoflow_distribution_send_agent_json($db, $channel, $path, $event, (string)$job['idempotency_key'], $payload, '目标站分发');
        }

        $remoteId = is_scalar($result['remote_id'] ?? null) ? (string)$result['remote_id'] : (string)($job['remote_id'] ?? '');
        $remoteUrl = $action === 'delete' ? null : (is_scalar($result['remote_url'] ?? null) ? (string)$result['remote_url'] : (string)($job['remote_url'] ?? ''));
        $remoteMeta = is_array($result['remote_meta'] ?? null) ? geoflow_distribution_json($result['remote_meta']) : ($job['remote_meta'] ?? null);
        $db->prepare("UPDATE article_distributions SET status = 'synced', remote_id = ?, remote_url = ?, remote_meta = ?, last_error_message = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = ?")
            ->execute([$remoteId, $remoteUrl, $remoteMeta, $distributionId]);
        geoflow_distribution_log($db, 'info', '文章分发成功', (int)$channel['id'], $distributionId, (int)$job['article_id'], ['event' => 'distribution.synced', 'remote_result' => $result]);
        return ['status' => 'success', 'remote_url' => $remoteUrl, 'remote_id' => $remoteId];
    } catch (Throwable $e) {
        $attemptCount = (int)$db->query("SELECT attempt_count FROM article_distributions WHERE id = " . (int)$distributionId)->fetchColumn();
        $shouldRetry = geoflow_distribution_should_retry($e, $attemptCount, 3);
        $nextRetrySql = $shouldRetry ? db_now_plus_seconds_sql(min(3600, 60 * (2 ** max(0, $attemptCount - 1)))) : 'NULL';
        $status = $shouldRetry ? 'queued' : 'failed';
        $db->prepare("UPDATE article_distributions SET status = ?, last_error_message = ?, last_attempt_at = CURRENT_TIMESTAMP, next_retry_at = {$nextRetrySql}, updated_at = CURRENT_TIMESTAMP WHERE id = ?")
            ->execute([$status, mb_substr($e->getMessage(), 0, 1000), $distributionId]);
        geoflow_distribution_log($db, $shouldRetry ? 'warning' : 'error', '文章分发失败：' . $e->getMessage(), (int)$job['distribution_channel_id'], $distributionId, (int)$job['article_id'], ['event' => $shouldRetry ? 'distribution.retry_scheduled' : 'distribution.failed']);
        return ['status' => 'failed', 'error_message' => $e->getMessage(), 'retry_scheduled' => $shouldRetry];
    }
}

function geoflow_distribution_execute_queued_jobs(PDO $db, int $limit = 5): array {
    geoflow_distribution_ensure_schema($db);
    $limit = max(1, min(50, $limit));
    $stmt = $db->prepare("SELECT id FROM article_distributions WHERE status = 'queued' AND (next_retry_at IS NULL OR next_retry_at <= CURRENT_TIMESTAMP) ORDER BY id ASC LIMIT ?");
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();
    $summary = ['success' => 0, 'failed' => 0, 'skipped' => 0, 'total' => 0];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $jobId) {
        $summary['total']++;
        $result = geoflow_distribution_process_job($db, (int)$jobId);
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

function geoflow_distribution_should_retry(Throwable $exception, int $attemptCount, int $maxAttempts): bool {
    if ($attemptCount >= $maxAttempts) {
        return false;
    }
    $message = mb_strtolower($exception->getMessage(), 'UTF-8');
    if (str_contains($message, '401') || str_contains($message, '403') || str_contains($message, 'signature') || str_contains($message, '签名') || str_contains($message, '422')) {
        return false;
    }
    return str_contains($message, 'timeout')
        || str_contains($message, 'connection')
        || str_contains($message, '429')
        || str_contains($message, '500')
        || str_contains($message, '502')
        || str_contains($message, '503')
        || str_contains($message, '504');
}

function geoflow_distribution_sync_site_settings(PDO $db, int $channelId): array {
    $channel = geoflow_distribution_get_channel($db, $channelId);
    if (!$channel) {
        throw new InvalidArgumentException('渠道不存在');
    }
    $settings = geoflow_distribution_decode($channel['site_settings'] ?? null) + [
        'active_theme' => (string)($channel['template_key'] ?? ''),
        'front_mode' => (string)($channel['front_mode'] ?? 'static'),
    ];
    if ((string)($channel['channel_type'] ?? 'geoflow_agent') === 'wordpress_rest') {
        $payload = [
            'title' => (string)($settings['site_name'] ?? $channel['name']),
            'description' => (string)($settings['site_description'] ?? ''),
            'posts_per_page' => (int)($settings['per_page'] ?? 12),
        ];
        $result = geoflow_distribution_wordpress_request($db, $channel, 'POST', geoflow_distribution_wordpress_base_url($channel) . '/wp/v2/settings', $payload);
    } else {
        $result = geoflow_distribution_send_agent_json($db, $channel, '/geoflow-agent/v1/site-settings', 'site.settings.update', 'site-settings-channel-' . $channelId . '-' . time(), ['settings' => $settings], '目标站点设置同步');
    }
    geoflow_distribution_log($db, 'info', '目标站点设置已同步', $channelId, null, null, ['event' => 'site.settings.synced', 'remote_result' => $result]);
    return $result;
}

function geoflow_distribution_wordpress_base_url(array $channel): string {
    $base = rtrim((string)$channel['endpoint_url'], '/');
    return str_ends_with($base, '/wp-json') ? $base : $base . '/wp-json';
}

function geoflow_distribution_wordpress_request(PDO $db, array $channel, string $method, string $url, ?array $payload = null, int $timeout = 30): array {
    $config = geoflow_distribution_decode($channel['channel_config'] ?? null);
    $secret = geoflow_distribution_active_secret($db, (int)$channel['id']);
    $username = trim((string)($config['wordpress_username'] ?? ''));
    $password = geoflow_distribution_plain_secret($secret);
    if ($username === '' || $password === '') {
        throw new RuntimeException('WordPress REST 渠道缺少用户名或 Application Password');
    }
    $body = $payload === null ? null : json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $headers = [
        'Accept: application/json',
        'Authorization: Basic ' . base64_encode($username . ':' . $password),
    ];
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
    }
    $response = geoflow_distribution_http_request($method, $url, $headers, $body, $timeout);
    if ((int)$response['status'] >= 400 || (int)$response['status'] === 0) {
        throw new RuntimeException(geoflow_distribution_failure_message('WordPress REST 请求', $response, $url));
    }
    return $response['json'] ?? ['ok' => true];
}

function geoflow_distribution_wordpress_publish_or_update(PDO $db, array $channel, array $job, array $payload, string $action): array {
    if ($action === 'delete') {
        $postId = geoflow_distribution_wordpress_post_id($job);
        if ($postId <= 0) {
            return ['deleted' => true, 'remote_id' => null, 'remote_url' => null, 'message' => 'missing_remote_post_id'];
        }
        geoflow_distribution_wordpress_request($db, $channel, 'DELETE', geoflow_distribution_wordpress_base_url($channel) . '/wp/v2/posts/' . $postId, ['force' => false]);
        return ['deleted' => true, 'remote_id' => (string)$postId, 'remote_url' => null];
    }
    $article = is_array($payload['article'] ?? null) ? $payload['article'] : [];
    $config = geoflow_distribution_channel_config(geoflow_distribution_decode($channel['channel_config'] ?? null));
    $postPayload = [
        'title' => (string)($article['title'] ?? ''),
        'slug' => (string)($article['slug'] ?? ''),
        'status' => (string)$config['wordpress_post_status'],
        'content' => (string)($article['content_html'] ?? ''),
        'excerpt' => (string)($article['excerpt'] ?? ''),
    ];
    $postId = $action === 'update' ? geoflow_distribution_wordpress_post_id($job) : 0;
    $url = geoflow_distribution_wordpress_base_url($channel) . '/wp/v2/posts' . ($postId > 0 ? '/' . $postId : '');
    $result = geoflow_distribution_wordpress_request($db, $channel, 'POST', $url, $postPayload);
    $remoteId = (int)($result['id'] ?? 0);
    return [
        'remote_id' => $remoteId > 0 ? (string)$remoteId : '',
        'remote_url' => (string)($result['link'] ?? ''),
        'remote_meta' => ['wordpress_post_id' => $remoteId],
    ];
}

function geoflow_distribution_wordpress_post_id(array $job): int {
    if (isset($job['remote_id']) && ctype_digit((string)$job['remote_id'])) {
        return (int)$job['remote_id'];
    }
    $meta = geoflow_distribution_decode($job['remote_meta'] ?? null);
    return is_numeric($meta['wordpress_post_id'] ?? null) ? (int)$meta['wordpress_post_id'] : 0;
}

function geoflow_distribution_update_remote_article(PDO $db, int $distributionId, array $articleInput): void {
    $stmt = $db->prepare("SELECT article_id FROM article_distributions WHERE id = ?");
    $stmt->execute([$distributionId]);
    $articleId = (int)$stmt->fetchColumn();
    if ($articleId <= 0) {
        throw new InvalidArgumentException('分发任务不存在');
    }
    $db->prepare("UPDATE articles SET title = ?, excerpt = ?, content = ?, keywords = ?, meta_description = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?")
        ->execute([
            (string)($articleInput['title'] ?? ''),
            (string)($articleInput['excerpt'] ?? ''),
            (string)($articleInput['content'] ?? ''),
            (string)($articleInput['keywords'] ?? ''),
            (string)($articleInput['meta_description'] ?? ''),
            $articleId,
        ]);
    $db->prepare("UPDATE article_distributions SET action = 'update', status = 'queued', idempotency_key = ?, payload_hash = NULL, next_retry_at = CURRENT_TIMESTAMP, last_error_message = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = ?")
        ->execute(['article-' . $articleId . '-distribution-' . $distributionId . '-update-v1', $distributionId]);
    $result = geoflow_distribution_process_job($db, $distributionId);
    if (($result['status'] ?? '') !== 'success') {
        throw new RuntimeException((string)($result['error_message'] ?? '远程文章更新失败'));
    }
}

function geoflow_distribution_delete_remote_article(PDO $db, int $distributionId): void {
    $stmt = $db->prepare("SELECT article_id FROM article_distributions WHERE id = ?");
    $stmt->execute([$distributionId]);
    $articleId = (int)$stmt->fetchColumn();
    if ($articleId <= 0) {
        throw new InvalidArgumentException('分发任务不存在');
    }
    $db->prepare("UPDATE article_distributions SET action = 'delete', status = 'queued', idempotency_key = ?, next_retry_at = CURRENT_TIMESTAMP, last_error_message = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = ?")
        ->execute(['article-' . $articleId . '-distribution-' . $distributionId . '-delete-v1', $distributionId]);
    $result = geoflow_distribution_process_job($db, $distributionId);
    if (($result['status'] ?? '') !== 'success') {
        throw new RuntimeException((string)($result['error_message'] ?? '远程文章删除失败'));
    }
}
