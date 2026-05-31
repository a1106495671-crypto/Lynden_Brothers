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
    $endpoint = rtrim((string)$channel['endpoint_url'], '/');
    $healthUrl = $endpoint . '/health';
    $context = stream_context_create(['http' => ['timeout' => 8, 'ignore_errors' => true]]);
    $body = @file_get_contents($healthUrl, false, $context);
    $ok = $body !== false;
    $error = $ok ? null : '无法访问 ' . $healthUrl;
    $db->prepare("UPDATE distribution_channels SET last_health_status = ?, last_health_checked_at = CURRENT_TIMESTAMP, last_error_message = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?")
        ->execute([$ok ? 'ok' : 'failed', $error, $channelId]);
    geoflow_distribution_log($db, $ok ? 'info' : 'error', $ok ? '目标站点健康检查通过' : '目标站点健康检查失败', $channelId, null, null, ['event' => 'channel.health_checked', 'url' => $healthUrl]);
    if (!$ok) {
        throw new RuntimeException($error);
    }
    return ['status' => 'ok', 'url' => $healthUrl, 'body' => mb_substr((string)$body, 0, 500)];
}
