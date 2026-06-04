<?php
/**
 * Background knowledge-base chunking and vectorization worker.
 */

define('FEISHU_TREASURE', true);

$projectRoot = dirname(__DIR__);
chdir($projectRoot);

require_once $projectRoot . '/includes/config.php';
require_once $projectRoot . '/includes/database_admin.php';
require_once $projectRoot . '/includes/functions.php';
require_once $projectRoot . '/includes/knowledge-retrieval.php';

set_time_limit(0);
ini_set('memory_limit', '768M');

function kb_worker_log(string $message): void {
    echo '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
}

function kb_worker_ensure_schema(PDO $db): void {
    $db->exec("ALTER TABLE knowledge_bases ADD COLUMN IF NOT EXISTS chunk_job_status VARCHAR(20) DEFAULT ''");
    $db->exec("ALTER TABLE knowledge_bases ADD COLUMN IF NOT EXISTS chunk_job_error TEXT DEFAULT ''");
    $db->exec("ALTER TABLE knowledge_bases ADD COLUMN IF NOT EXISTS chunk_job_started_at TIMESTAMP DEFAULT NULL");
    $db->exec("ALTER TABLE knowledge_bases ADD COLUMN IF NOT EXISTS chunk_job_finished_at TIMESTAMP DEFAULT NULL");
}

$knowledgeBaseId = (int) ($argv[1] ?? 0);
$requireRealEmbedding = ((int) ($argv[2] ?? 1)) === 1;

if ($knowledgeBaseId <= 0) {
    kb_worker_log('Missing knowledge base id.');
    exit(1);
}

$lockDir = $projectRoot . '/data/logs';
if (!is_dir($lockDir)) {
    mkdir($lockDir, 0775, true);
}
$lockHandle = fopen($lockDir . '/knowledge-chunks-' . $knowledgeBaseId . '.lock', 'c');
if (!$lockHandle || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    kb_worker_log("Knowledge base #{$knowledgeBaseId} is already being processed.");
    exit(0);
}

try {
    kb_worker_ensure_schema($db);

    $stmt = $db->prepare("SELECT id, name, content FROM knowledge_bases WHERE id = ?");
    $stmt->execute([$knowledgeBaseId]);
    $knowledge = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$knowledge) {
        throw new RuntimeException('知识库不存在');
    }

    $content = trim((string) ($knowledge['content'] ?? ''));
    if ($content === '') {
        throw new RuntimeException('知识库内容不能为空');
    }

    $db->prepare("
        UPDATE knowledge_bases
        SET chunk_job_status = 'running',
            chunk_job_error = '',
            chunk_job_started_at = CURRENT_TIMESTAMP,
            chunk_job_finished_at = NULL,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = ?
    ")->execute([$knowledgeBaseId]);

    kb_worker_log("Start chunking knowledge base #{$knowledgeBaseId}: " . (string) ($knowledge['name'] ?? ''));
    $chunkCount = knowledge_retrieval_sync_chunks($db, $knowledgeBaseId, $content, $requireRealEmbedding);

    $vectorStmt = $db->prepare("
        SELECT COUNT(*)
        FROM knowledge_chunks
        WHERE knowledge_base_id = ?
          AND embedding_model_id IS NOT NULL
          AND embedding_model_id > 0
          AND embedding_dimensions > 0
    ");
    $vectorStmt->execute([$knowledgeBaseId]);
    $vectorizedCount = (int) $vectorStmt->fetchColumn();

    $db->prepare("
        UPDATE knowledge_bases
        SET chunk_job_status = 'completed',
            chunk_job_error = '',
            chunk_job_finished_at = CURRENT_TIMESTAMP,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = ?
    ")->execute([$knowledgeBaseId]);

    kb_worker_log("Completed knowledge base #{$knowledgeBaseId}: vectorized {$vectorizedCount} / {$chunkCount}.");
    exit(0);
} catch (Throwable $e) {
    try {
        kb_worker_ensure_schema($db);
        $db->prepare("
            UPDATE knowledge_bases
            SET chunk_job_status = 'failed',
                chunk_job_error = ?,
                chunk_job_finished_at = CURRENT_TIMESTAMP,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ")->execute([mb_substr($e->getMessage(), 0, 2000, 'UTF-8'), $knowledgeBaseId]);
    } catch (Throwable $ignored) {
    }

    kb_worker_log("Failed knowledge base #{$knowledgeBaseId}: " . $e->getMessage());
    exit(1);
} finally {
    if ($lockHandle) {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }
}
