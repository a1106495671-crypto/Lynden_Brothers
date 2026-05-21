<?php
/**
 * 发布URL回写接口
 * POST /admin/api/publish-callback.php
 * Body: { article_id, remote_url, status }
 * Called by Playwright publish scripts after successful publication
 */
define('FEISHU_TREASURE', true);
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/database_admin.php';

header('Content-Type: application/json');

// Auth: simple shared secret (same key used in Node scripts)
$secret = defined('PUBLISH_CALLBACK_SECRET') ? PUBLISH_CALLBACK_SECRET : 'geo-internal-callback-2024';
$auth = $_SERVER['HTTP_X_CALLBACK_SECRET'] ?? ($_POST['_secret'] ?? '');
if ($auth !== $secret) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'unauthorized']);
    exit;
}

$data = [];
$raw = file_get_contents('php://input');
if ($raw) {
    $data = json_decode($raw, true) ?? [];
}
// Also accept form-encoded
if (!$data) {
    $data = $_POST;
}

$articleId = isset($data['article_id']) ? (int)$data['article_id'] : 0;
$remoteUrl = trim($data['remote_url'] ?? '');
$status    = in_array($data['status'] ?? '', ['published','failed']) ? $data['status'] : 'published';

if (!$articleId || !$remoteUrl) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'article_id and remote_url required']);
    exit;
}

// Validate URL format
if (!filter_var($remoteUrl, FILTER_VALIDATE_URL)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid remote_url']);
    exit;
}

try {
    $stmt = $db->prepare("UPDATE articles SET remote_url=?, status=?, updated_at=NOW() WHERE id=?");
    $stmt->execute([$remoteUrl, $status, $articleId]);
    $affected = $stmt->rowCount();

    if ($affected === 0) {
        echo json_encode(['ok' => false, 'error' => 'article not found or no change']);
    } else {
        echo json_encode(['ok' => true, 'article_id' => $articleId, 'remote_url' => $remoteUrl, 'status' => $status]);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
