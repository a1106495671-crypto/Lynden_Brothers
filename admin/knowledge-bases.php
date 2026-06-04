<?php
/**
 * 智能GEO内容系统 - AI知识库管理
 *
 * @version 1.0
 * @date 2025-10-06
 */

define('FEISHU_TREASURE', true);
session_start();

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database_admin.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/includes/knowledge-base-helpers.php';
require_once __DIR__ . '/includes/material-library-helpers.php';

// 检查管理员登录
require_admin_login();

// 立即释放session锁，允许其他页面并发访问
session_write_close();

$message = '';
$error = '';

function knowledge_base_has_default_embedding_model(PDO $db): bool {
    try {
        $stmt = $db->query("
            SELECT COUNT(*)
            FROM ai_models
            WHERE status = 'active'
              AND COALESCE(NULLIF(model_type, ''), 'chat') = 'embedding'
        ");
        return (int) ($stmt ? $stmt->fetchColumn() : 0) > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function knowledge_base_ensure_chunk_job_schema(PDO $db): void {
    $db->exec("ALTER TABLE knowledge_bases ADD COLUMN IF NOT EXISTS chunk_job_status VARCHAR(20) DEFAULT ''");
    $db->exec("ALTER TABLE knowledge_bases ADD COLUMN IF NOT EXISTS chunk_job_error TEXT DEFAULT ''");
    $db->exec("ALTER TABLE knowledge_bases ADD COLUMN IF NOT EXISTS chunk_job_started_at TIMESTAMP DEFAULT NULL");
    $db->exec("ALTER TABLE knowledge_bases ADD COLUMN IF NOT EXISTS chunk_job_finished_at TIMESTAMP DEFAULT NULL");
}

function knowledge_base_enqueue_chunk_job(PDO $db, int $knowledgeBaseId, bool $requireRealEmbedding = true): void {
    if ($knowledgeBaseId <= 0) {
        return;
    }

    knowledge_base_ensure_chunk_job_schema($db);
    $stmt = $db->prepare("
        UPDATE knowledge_bases
        SET chunk_job_status = 'queued',
            chunk_job_error = '',
            chunk_job_started_at = NULL,
            chunk_job_finished_at = NULL,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = ?
    ");
    $stmt->execute([$knowledgeBaseId]);

    $script = dirname(__DIR__) . '/bin/knowledge_chunk_worker.php';
    $log = dirname(__DIR__) . '/data/logs/knowledge-chunks.log';
    $php = PHP_BINARY ?: 'php';
    $command = sprintf(
        'nohup %s %s %d %d >> %s 2>&1 &',
        escapeshellarg($php),
        escapeshellarg($script),
        $knowledgeBaseId,
        $requireRealEmbedding ? 1 : 0,
        escapeshellarg($log)
    );
    exec($command);
}

try {
    knowledge_base_ensure_chunk_job_schema($db);
} catch (Throwable $e) {
    // 状态字段仅用于后台进度提示，不影响知识库基础功能。
}

// 处理POST请求
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'CSRF验证失败';
    } else {
        $action = $_POST['action'] ?? '';
        
        switch ($action) {
            case 'refresh_chunks':
                $knowledge_id = intval($_POST['knowledge_id'] ?? 0);
                if ($knowledge_id <= 0) {
                    $error = '请选择要更新切片的知识库';
                    break;
                }

                try {
                    $stmt = $db->prepare("SELECT id, content FROM knowledge_bases WHERE id = ?");
                    $stmt->execute([$knowledge_id]);
                    $knowledge = $stmt->fetch(PDO::FETCH_ASSOC);
                    if (!$knowledge) {
                        throw new RuntimeException('知识库不存在');
                    }

                    $content = trim((string) ($knowledge['content'] ?? ''));
                    if ($content === '') {
                        throw new RuntimeException('知识库内容不能为空');
                    }

                    knowledge_base_enqueue_chunk_job($db, $knowledge_id, true);
                    $message = '已提交后台切片/向量化任务，页面可继续操作，稍后刷新查看进度。';
                } catch (Throwable $e) {
                    $error = '提交切片任务失败: ' . $e->getMessage();
                }
                break;

            case 'create_knowledge':
                $name = trim($_POST['name'] ?? '');
                $description = trim($_POST['description'] ?? '');
                $content = trim($_POST['content'] ?? '');
                $file_type = $_POST['file_type'] ?? 'markdown';
                $stored_paths = [];
                $created = false;

                try {
                    $uploaded_files = knowledge_base_uploaded_files_from_request('knowledge_files');
                    if (count($uploaded_files) > 200) {
                        throw new RuntimeException('最多只能一次导入 200 个文件');
                    }
                    $parsed_files = knowledge_base_parse_uploaded_files($uploaded_files, $stored_paths);
                    $content = knowledge_base_merge_sources($content, $parsed_files);

                    if ($content === '') {
                        throw new RuntimeException('请粘贴文本或上传至少一个知识文档');
                    }
                    if ($name === '') {
                        $name = knowledge_base_infer_name($uploaded_files, $content);
                    }
                    if ($name === '') {
                        throw new RuntimeException('知识库名称不能为空');
                    }

                    $file_type = knowledge_base_file_type_from_sources($file_type, trim($_POST['content'] ?? ''), $parsed_files);
                    $file_path = knowledge_base_encode_file_paths($stored_paths);
                    $word_count = mb_strlen(strip_tags($content));
                    $columns = ['name', 'description', 'content', 'file_type', 'file_path', 'word_count'];
                    $values = [$name, $description, $content, $file_type, $file_path, $word_count];
                    if (db_column_exists($db, 'knowledge_bases', 'character_count')) {
                        $columns[] = 'character_count';
                        $values[] = mb_strlen($content, 'UTF-8');
                    }
                    $stmt = $db->prepare("
                        INSERT INTO knowledge_bases (" . implode(', ', $columns) . ", created_at, updated_at)
                        VALUES (" . implode(', ', array_fill(0, count($columns), '?')) . ", CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                    ");

                    if ($stmt->execute($values)) {
                        $created = true;
                        $knowledge_id = db_last_insert_id($db, 'knowledge_bases');
                        if (($_POST['import_action'] ?? 'save_and_chunk') === 'save') {
                            $message = '知识库已保存，尚未生成知识片段';
                        } else {
                            knowledge_base_enqueue_chunk_job($db, $knowledge_id, true);
                            $message = '知识库创建成功，已提交后台切片/向量化任务。';
                        }
                    } else {
                        foreach ($stored_paths as $path) {
                            cleanup_knowledge_file($path);
                        }
                        $error = '知识库创建失败';
                    }
                } catch (Throwable $e) {
                    if (!$created) {
                        foreach ($stored_paths as $path) {
                            cleanup_knowledge_file($path);
                        }
                    }
                    $error = '创建失败: ' . $e->getMessage();
                }
                break;

            case 'delete_knowledge':
                $knowledge_id = intval($_POST['knowledge_id'] ?? 0);
                
                if ($knowledge_id > 0) {
                    try {
                        $references = get_knowledge_base_task_references($db, $knowledge_id);
                        if ($references['count'] > 0) {
                            $taskLabels = array_map(
                                static fn(array $task): string => '#' . (int) $task['id'] . ' ' . (string) $task['name'],
                                $references['tasks']
                            );
                            $error = '该知识库正在被 ' . $references['count'] . ' 个任务引用，请先解除引用后再删除';
                            if (!empty($taskLabels)) {
                                $error .= '：' . implode('、', $taskLabels);
                            }
                            break;
                        }

                        $lookupStmt = $db->prepare("SELECT file_path FROM knowledge_bases WHERE id = ?");
                        $lookupStmt->execute([$knowledge_id]);
                        $knowledge = $lookupStmt->fetch();
                        if (!$knowledge) {
                            throw new Exception('知识库不存在');
                        }

                        $db->beginTransaction();
                        $stmt = $db->prepare("DELETE FROM knowledge_bases WHERE id = ?");
                        
                        if ($stmt->execute([$knowledge_id])) {
                            $db->commit();
                            cleanup_knowledge_file($knowledge['file_path'] ?? '');
                            $message = '知识库删除成功';
                        } else {
                            $db->rollBack();
                            $error = '删除失败';
                        }
                    } catch (Throwable $e) {
                        if ($db->inTransaction()) {
                            $db->rollBack();
                        }
                        $error = '删除失败: ' . $e->getMessage();
                    }
                }
                break;
                
            case 'upload_file':
                $_POST['action'] = 'create_knowledge';
                $stored_paths = [];
                $created = false;
                try {
                    $name = trim($_POST['name'] ?? '');
                    $description = trim($_POST['description'] ?? '');
                    $uploaded_files = knowledge_base_uploaded_files_from_request('knowledge_files');
                    if (empty($uploaded_files)) {
                        $uploaded_files = knowledge_base_uploaded_files_from_request('knowledge_file');
                    }
                    if (empty($uploaded_files)) {
                        throw new RuntimeException('请选择要上传的文件');
                    }
                    if (count($uploaded_files) > 200) {
                        throw new RuntimeException('最多只能一次导入 200 个文件');
                    }
                    $parsed_files = knowledge_base_parse_uploaded_files($uploaded_files, $stored_paths);
                    $content = knowledge_base_merge_sources('', $parsed_files);
                    if ($name === '') {
                        $name = knowledge_base_infer_name($uploaded_files, $content);
                    }
                    if ($name === '') {
                        throw new RuntimeException('知识库名称不能为空');
                    }
                    $file_type = knowledge_base_file_type_from_sources('markdown', '', $parsed_files);
                    $file_path = knowledge_base_encode_file_paths($stored_paths);
                    $word_count = mb_strlen(strip_tags($content));
                    $columns = ['name', 'description', 'content', 'file_type', 'file_path', 'word_count'];
                    $values = [$name, $description, $content, $file_type, $file_path, $word_count];
                    if (db_column_exists($db, 'knowledge_bases', 'character_count')) {
                        $columns[] = 'character_count';
                        $values[] = mb_strlen($content, 'UTF-8');
                    }

                    $stmt = $db->prepare("
                        INSERT INTO knowledge_bases (" . implode(', ', $columns) . ", created_at, updated_at)
                        VALUES (" . implode(', ', array_fill(0, count($columns), '?')) . ", CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                    ");

                    if ($stmt->execute($values)) {
                        $created = true;
                        $knowledge_id = db_last_insert_id($db, 'knowledge_bases');
                        knowledge_base_enqueue_chunk_job($db, $knowledge_id, true);
                        $message = '知识库文件上传成功，已提交后台切片/向量化任务。';
                    } else {
                        foreach ($stored_paths as $path) {
                            cleanup_knowledge_file($path);
                        }
                        $error = '保存到数据库失败';
                    }
                } catch (Throwable $e) {
                    if (!$created) {
                        foreach ($stored_paths as $path) {
                            cleanup_knowledge_file($path);
                        }
                    }
                    $error = '上传失败: ' . $e->getMessage();
                }
                break;
        }
    }
}

try {
    knowledge_retrieval_ensure_chunk_schema($db);
} catch (Throwable $e) {
    // 列表页仍可展示知识库；切片统计会在后续查询失败时回退为 0。
}

// 获取知识库列表
try {
    $knowledge_bases = $db->query("
        SELECT
            kb.*,
            COUNT(kc.id) AS chunk_count,
            SUM(CASE WHEN kc.embedding_model_id IS NOT NULL AND kc.embedding_model_id > 0 AND kc.embedding_dimensions > 0 THEN 1 ELSE 0 END) AS vectorized_chunk_count
        FROM knowledge_bases kb
        LEFT JOIN knowledge_chunks kc ON kc.knowledge_base_id = kb.id
        GROUP BY kb.id
        ORDER BY kb.created_at DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $knowledge_bases = $db->query("
        SELECT *, 0 AS chunk_count, 0 AS vectorized_chunk_count
        FROM knowledge_bases
        ORDER BY created_at DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
}

// 获取统计数据
$stats = [
    'total_knowledge' => count($knowledge_bases),
    'total_words' => $db->query("SELECT SUM(word_count) as total FROM knowledge_bases")->fetch()['total'] ?? 0,
    'markdown_count' => $db->query("SELECT COUNT(*) as count FROM knowledge_bases WHERE file_type = 'markdown'")->fetch()['count'],
    'word_count' => $db->query("SELECT COUNT(*) as count FROM knowledge_bases WHERE file_type = 'word'")->fetch()['count']
];
$has_default_embedding_model = knowledge_base_has_default_embedding_model($db);

// 设置页面信息
$page_title = 'AI知识库管理';
$page_header = '
<div class="flex items-center justify-between">
    <div class="flex items-center space-x-4">
        <a href="materials.php" class="text-gray-400 hover:text-gray-600">
            <i data-lucide="arrow-left" class="w-5 h-5"></i>
        </a>
        <div>
            <h1 class="text-2xl font-bold text-gray-900">AI知识库管理</h1>
            <p class="mt-1 text-sm text-gray-600">管理AI训练和参考的知识库文档</p>
        </div>
    </div>
    <div class="flex items-center gap-3">
        <button onclick="showCreateModal()" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
            <i data-lucide="plus" class="w-4 h-4 mr-2"></i>
            新建知识库
        </button>
        <button onclick="showCreateModal()" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-orange-600 hover:bg-orange-700">
            <i data-lucide="upload" class="w-4 h-4 mr-2"></i>
            导入知识库
        </button>
    </div>
</div>
';

// 包含头部模块
require_once __DIR__ . '/includes/header.php';
?>

        <?php if ($message): ?>
            <div class="mb-4 bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="mb-4 bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>


        <!-- 统计卡片 -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i data-lucide="brain" class="h-6 w-6 text-orange-600"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate">知识库总数</dt>
                                <dd class="text-lg font-medium text-gray-900"><?php echo $stats['total_knowledge']; ?></dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i data-lucide="file-text" class="h-6 w-6 text-blue-600"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate">总字数</dt>
                                <dd class="text-lg font-medium text-gray-900"><?php echo number_format($stats['total_words']); ?></dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i data-lucide="hash" class="h-6 w-6 text-green-600"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate">Markdown</dt>
                                <dd class="text-lg font-medium text-gray-900"><?php echo $stats['markdown_count']; ?></dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i data-lucide="file" class="h-6 w-6 text-purple-600"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 truncate">Word文档</dt>
                                <dd class="text-lg font-medium text-gray-900"><?php echo $stats['word_count']; ?></dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 知识库列表 -->
        <div class="bg-white shadow rounded-lg">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900">知识库列表</h3>
            </div>

            <?php if (empty($knowledge_bases)): ?>
                <div class="px-6 py-8 text-center">
                    <i data-lucide="brain" class="w-12 h-12 mx-auto text-gray-400 mb-4"></i>
                    <h3 class="text-lg font-medium text-gray-900 mb-2">暂无知识库</h3>
                    <p class="text-gray-500 mb-4">创建您的第一个知识库来为AI提供专业知识</p>
                    <div class="flex justify-center space-x-2">
                        <button onclick="showCreateModal()" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-orange-600 hover:bg-orange-700">
                            <i data-lucide="plus" class="w-4 h-4 mr-2"></i>
                            新建知识库
                        </button>
                        <button onclick="showUploadModal()" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                            <i data-lucide="upload" class="w-4 h-4 mr-2"></i>
                            上传文档
                        </button>
                    </div>
                </div>
            <?php else: ?>
                <div class="flex items-center justify-between gap-6 px-6 py-3 border-b border-gray-200 bg-gray-50 text-xs font-semibold uppercase tracking-wide text-gray-500">
                    <div>知识库</div>
                    <div class="text-right" style="width: 440px;">操作</div>
                </div>
                <div class="divide-y divide-gray-200">
                    <?php foreach ($knowledge_bases as $knowledge): ?>
                        <div class="px-6 py-6">
                            <div class="flex flex-col gap-5 lg:flex-row lg:items-center">
                                <div class="min-w-0 lg:flex-1">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <h4 class="text-lg font-medium text-gray-900">
                                            <a href="knowledge-base-detail.php?id=<?php echo $knowledge['id']; ?>" class="hover:text-orange-600">
                                                <?php echo htmlspecialchars($knowledge['name']); ?>
                                            </a>
                                        </h4>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium <?php 
                                            echo $knowledge['file_type'] === 'markdown' ? 'bg-green-100 text-green-800' : 
                                                ($knowledge['file_type'] === 'word' ? 'bg-purple-100 text-purple-800' : 'bg-blue-100 text-blue-800'); 
                                        ?>">
                                            <?php 
                                            echo $knowledge['file_type'] === 'markdown' ? 'Markdown' : 
                                                ($knowledge['file_type'] === 'word' ? 'Word文档' : '文本'); 
                                            ?>
                                        </span>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-orange-100 text-orange-800">
                                            <?php echo number_format($knowledge['word_count']); ?> 字
                                        </span>
                                        <?php if ((int) ($knowledge['chunk_count'] ?? 0) > 0): ?>
                                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-50 text-blue-700">
                                                已向量化 <?php echo (int) ($knowledge['vectorized_chunk_count'] ?? 0); ?> / <?php echo (int) ($knowledge['chunk_count'] ?? 0); ?>
                                            </span>
                                        <?php endif; ?>
                                        <?php
                                            $chunkJobStatus = (string) ($knowledge['chunk_job_status'] ?? '');
                                            $chunkJobClasses = [
                                                'queued' => 'bg-amber-50 text-amber-700',
                                                'running' => 'bg-indigo-50 text-indigo-700',
                                                'completed' => 'bg-emerald-50 text-emerald-700',
                                                'failed' => 'bg-red-50 text-red-700',
                                            ];
                                            $chunkJobLabels = [
                                                'queued' => '切片排队中',
                                                'running' => '切片处理中',
                                                'completed' => '切片完成',
                                                'failed' => '切片失败',
                                            ];
                                        ?>
                                        <?php if (isset($chunkJobLabels[$chunkJobStatus])): ?>
                                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium <?php echo $chunkJobClasses[$chunkJobStatus]; ?>"
                                                  title="<?php echo htmlspecialchars((string) ($knowledge['chunk_job_error'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                                <?php echo htmlspecialchars($chunkJobLabels[$chunkJobStatus], ENT_QUOTES, 'UTF-8'); ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($knowledge['description']): ?>
                                        <p class="mt-1 text-sm text-gray-600"><?php echo htmlspecialchars($knowledge['description']); ?></p>
                                    <?php endif; ?>
                                    <div class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-gray-500">
                                        <span>创建时间: <?php echo date('Y-m-d H:i', strtotime($knowledge['created_at'])); ?></span>
                                        <span>更新时间: <?php echo date('Y-m-d H:i', strtotime($knowledge['updated_at'])); ?></span>
                                        <?php if ($knowledge['usage_count'] > 0): ?>
                                            <span>使用次数: <?php echo $knowledge['usage_count']; ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <div class="flex flex-wrap items-start justify-start gap-2 lg:shrink-0 lg:justify-end lg:pl-8" style="width: 440px;">
                                    <?php if ($has_default_embedding_model): ?>
                                        <div style="width: 148px;" data-refresh-chunks-action>
                                            <form method="POST" class="inline-block w-full" data-refresh-chunks-form data-knowledge-name="<?php echo htmlspecialchars($knowledge['name']); ?>" data-knowledge-summary="已向量化 <?php echo (int) ($knowledge['vectorized_chunk_count'] ?? 0); ?> / <?php echo (int) ($knowledge['chunk_count'] ?? 0); ?>" data-word-count="<?php echo number_format((int) ($knowledge['word_count'] ?? 0)); ?> 字">
                                                <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                                                <input type="hidden" name="action" value="refresh_chunks">
                                                <input type="hidden" name="knowledge_id" value="<?php echo (int) $knowledge['id']; ?>">
                                                <button type="submit" class="inline-flex w-full items-center justify-center px-3 py-1.5 border border-emerald-200 text-xs font-medium rounded text-emerald-700 bg-emerald-50 hover:bg-emerald-100" data-refresh-submit-button>
                                                    <i data-lucide="refresh-cw" class="w-4 h-4 mr-1" data-refresh-submit-icon></i>
                                                    <span data-refresh-submit-label>更新切片</span>
                                                </button>
                                            </form>
                                            <div class="mt-2 hidden" data-refresh-progress>
                                                <div class="flex items-center justify-between text-[11px] font-medium text-emerald-700">
                                                    <span data-refresh-progress-label>正在重建切片</span>
                                                    <span data-refresh-progress-value>0%</span>
                                                </div>
                                                <div class="mt-1 h-1.5 overflow-hidden rounded-full bg-emerald-100">
                                                    <div class="h-full rounded-full bg-emerald-500 transition-all duration-500 ease-out" style="width: 8%;" data-refresh-progress-bar></div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <button type="button" onclick="showEmbeddingConfigModal()" class="inline-flex items-center px-3 py-1.5 border border-amber-200 text-xs font-medium rounded text-amber-800 bg-amber-50 hover:bg-amber-100">
                                            <i data-lucide="refresh-cw" class="w-4 h-4 mr-1"></i>
                                            更新切片
                                        </button>
                                    <?php endif; ?>
                                    <a href="knowledge-base-detail.php?id=<?php echo $knowledge['id']; ?>#chunk-preview" class="inline-flex items-center px-3 py-1.5 border border-blue-200 text-xs font-medium rounded text-blue-700 bg-blue-50 hover:bg-blue-100">
                                        <i data-lucide="rows-3" class="w-4 h-4 mr-1"></i>
                                        切片
                                    </a>
                                    <a href="knowledge-base-detail.php?id=<?php echo $knowledge['id']; ?>" class="inline-flex items-center px-3 py-1.5 border border-gray-300 text-xs font-medium rounded text-gray-700 bg-white hover:bg-gray-50">
                                        <i data-lucide="eye" class="w-4 h-4 mr-1"></i>
                                        查看
                                    </a>
                                    <button onclick="deleteKnowledge(<?php echo $knowledge['id']; ?>, '<?php echo htmlspecialchars($knowledge['name']); ?>')" class="inline-flex items-center px-3 py-1.5 border border-transparent text-xs font-medium rounded text-white bg-red-600 hover:bg-red-700">
                                        <i data-lucide="trash-2" class="w-4 h-4 mr-1"></i>
                                        删除
                                    </button>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- 创建知识库模态框 -->
    <div id="create-modal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
        <div class="relative top-10 mx-auto p-5 border w-2/3 max-w-4xl shadow-lg rounded-md bg-white">
            <div class="mt-3">
                <h3 class="text-lg font-medium text-gray-900 mb-4">新建知识库</h3>
                <form method="POST" enctype="multipart/form-data" id="create-knowledge-form">
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    <input type="hidden" name="action" value="create_knowledge">
                    <input type="hidden" name="import_action" value="save_and_chunk" data-import-action-input>
                    
                    <div class="space-y-4">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700">知识库名称 *</label>
                                <input type="text" name="name"
                                       class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-orange-500 focus:border-orange-500 sm:text-sm"
                                       placeholder="可留空，系统会根据文件或正文自动命名">
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700">文档类型</label>
                                <select name="file_type" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-orange-500 focus:border-orange-500 sm:text-sm">
                                    <option value="markdown">Markdown</option>
                                    <option value="text">纯文本</option>
                                    <option value="pdf">PDF</option>
                                    <option value="powerpoint">PowerPoint</option>
                                </select>
                            </div>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700">描述</label>
                            <textarea name="description" rows="2"
                                      class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-orange-500 focus:border-orange-500 sm:text-sm"
                                      placeholder="知识库的用途描述（可选）"></textarea>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700">上传文档</label>
                            <div id="knowledge-dropzone" class="mt-1 flex flex-col items-center justify-center rounded-xl border-2 border-dashed border-orange-200 bg-orange-50/30 px-6 py-8 text-center transition hover:border-orange-300 hover:bg-orange-50">
                                <input type="file" id="knowledge-files-input" name="knowledge_files[]" accept=".txt,.md,.docx,.pdf,.pptx,.ppt" multiple class="sr-only">
                                <input type="file" id="knowledge-folder-input" name="knowledge_files[]" accept=".txt,.md,.docx,.pdf,.pptx,.ppt" multiple webkitdirectory directory class="sr-only">
                                <div>
                                    <span class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-white text-orange-600 shadow-sm ring-1 ring-orange-100">
                                        <i data-lucide="upload-cloud" class="h-6 w-6"></i>
                                    </span>
                                    <span class="mt-4 block text-sm font-semibold text-gray-900">点击选择文件或文件夹，或直接拖拽文件/文件夹到这里</span>
                                    <span class="mt-1 block text-sm text-gray-500">会读取 TXT、MD、DOCX、PDF、PPTX；文件夹会自动递归读取，最多 200 个文件，单文件 50MB，单次最多 512MB</span>
                                    <div class="mt-4 flex flex-wrap items-center justify-center gap-3">
                                        <label for="knowledge-files-input" class="inline-flex cursor-pointer items-center rounded-md border border-orange-200 bg-white px-3 py-2 text-sm font-medium text-orange-700 shadow-sm hover:bg-orange-50">
                                            选择文件
                                        </label>
                                        <label for="knowledge-folder-input" class="inline-flex cursor-pointer items-center rounded-md border border-orange-200 bg-white px-3 py-2 text-sm font-medium text-orange-700 shadow-sm hover:bg-orange-50">
                                            选择文件夹
                                        </label>
                                    </div>
                                </div>
                            </div>
                            <div id="knowledge-file-list" class="mt-3 hidden rounded-lg border border-gray-200 divide-y divide-gray-100"></div>
                        </div>

                        <div>
                            <div class="flex items-center justify-between">
                                <label class="block text-sm font-medium text-gray-700">粘贴文本</label>
                                <span id="knowledge-content-count" class="text-xs text-orange-700 bg-orange-50 px-2 py-1 rounded-full">0 字</span>
                            </div>
                            <textarea name="content" rows="12"
                                      class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-orange-500 focus:border-orange-500 sm:text-sm font-mono"
                                      placeholder="可粘贴 Markdown、网页正文、业务资料或 FAQ；也可以只上传文件。若同时上传文件，文本会放在最前面合并入库。"></textarea>
                        </div>
                    </div>
                    
                    <div class="mt-6 flex justify-end space-x-3">
                        <button type="button" onclick="hideCreateModal()" class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50">
                            取消
                        </button>
                        <button type="submit" class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50" data-import-submit data-import-action="save" data-import-label="正在保存">
                            只保存
                        </button>
                        <button type="submit" class="px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-orange-600 hover:bg-orange-700" data-import-submit data-import-action="save_and_chunk" data-import-label="正在切片向量化">
                            保存并切片向量化
                        </button>
                    </div>
                    <div class="mt-4 hidden" data-import-progress>
                        <div class="flex items-center justify-between text-xs font-medium text-orange-700">
                            <span data-import-progress-label>正在保存知识库</span>
                            <span data-import-progress-value>0%</span>
                        </div>
                        <div class="mt-2 h-2 overflow-hidden rounded-full bg-orange-100">
                            <div class="h-full rounded-full bg-orange-500 transition-all duration-500 ease-out" style="width: 8%;" data-import-progress-bar></div>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- 上传文档模态框 -->
    <div id="upload-modal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
        <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
            <div class="mt-3">
                <h3 class="text-lg font-medium text-gray-900 mb-4">上传知识文档</h3>
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    <input type="hidden" name="action" value="upload_file">
                    
                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">知识库名称</label>
                            <input type="text" name="name" 
                                   class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-orange-500 focus:border-orange-500 sm:text-sm"
                                   placeholder="留空将使用文件名">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700">描述</label>
                            <textarea name="description" rows="2"
                                      class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-orange-500 focus:border-orange-500 sm:text-sm"
                                      placeholder="知识库描述（可选）"></textarea>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700">选择文件或文件夹 *</label>
                            <input type="file" name="knowledge_files[]" accept=".txt,.md,.docx,.pdf,.pptx,.ppt" multiple
                                   class="mt-1 block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-orange-50 file:text-orange-700 hover:file:bg-orange-100">
                            <input type="file" name="knowledge_files[]" accept=".txt,.md,.docx,.pdf,.pptx,.ppt" multiple webkitdirectory directory
                                   class="mt-2 block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-orange-50 file:text-orange-700 hover:file:bg-orange-100">
                        </div>
                        
                        <div class="text-sm text-gray-500">
                            <p class="mb-2">支持的文件格式：</p>
                            <ul class="list-disc list-inside space-y-1">
                                <li>TXT - 纯文本文件</li>
                                <li>MD - Markdown文件</li>
                                <li>DOCX - Word文档，支持自动提取正文</li>
                                <li>PDF - 支持提取可复制文字，扫描件请先 OCR</li>
                                <li>PPTX - PowerPoint 演示文稿，支持提取幻灯片文字</li>
                                <li>DOC - 旧版 Word 文档，请先另存为 DOCX 后上传</li>
                                <li>PPT - 旧版 PowerPoint 文档，请先另存为 PPTX 后上传</li>
                            </ul>
                        </div>
                    </div>
                    
                    <div class="mt-6 flex justify-end space-x-3">
                        <button type="button" onclick="hideUploadModal()" class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50">
                            取消
                        </button>
                        <button type="submit" class="px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-orange-600 hover:bg-orange-700">
                            <i data-lucide="upload" class="w-4 h-4 mr-2 inline"></i>
                            上传文档
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div id="embedding-config-modal" class="hidden fixed inset-0 z-50">
        <div class="absolute inset-0 bg-slate-900/45"></div>
        <div class="relative flex min-h-screen items-center justify-center p-4">
            <div class="w-full max-w-lg rounded-2xl bg-white shadow-2xl ring-1 ring-slate-200">
                <div class="border-b border-slate-100 px-6 py-5">
                    <h3 class="text-lg font-semibold text-slate-900">需要先配置 Embedding 模型</h3>
                </div>
                <div class="px-6 py-5">
                    <div class="text-sm leading-7 text-slate-600">更新切片会重新生成知识片段并写入真实向量。当前没有可用的 embedding 模型，请先到 AI 模型配置里添加或启用 embedding 模型。</div>
                </div>
                <div class="flex items-center justify-end gap-3 border-t border-slate-100 px-6 py-4">
                    <button type="button" onclick="hideEmbeddingConfigModal()" class="inline-flex items-center rounded-xl border border-slate-200 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">
                        取消
                    </button>
                    <a href="ai-models.php" class="inline-flex items-center rounded-xl bg-amber-500 px-4 py-2 text-sm font-medium text-white hover:bg-amber-600">
                        去配置
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div id="refresh-chunks-modal" class="hidden fixed inset-0 z-50" data-knowledge-refresh-modal>
        <div class="absolute inset-0 bg-slate-900/45" data-refresh-chunks-cancel></div>
        <div class="relative flex min-h-screen items-center justify-center p-4">
            <div class="w-full max-w-xl overflow-hidden rounded-2xl bg-white shadow-2xl ring-1 ring-slate-200">
                <div class="border-b border-slate-100 px-6 py-5">
                    <div class="flex items-start gap-4">
                        <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-emerald-50 text-emerald-600">
                            <i data-lucide="refresh-cw" class="h-5 w-5"></i>
                        </div>
                        <div class="min-w-0">
                            <h3 class="text-lg font-semibold text-slate-900">确认更新切片</h3>
                            <p class="mt-1 text-sm leading-6 text-slate-600">系统会重新拆分知识库内容，并重新写入 embedding 向量。</p>
                        </div>
                    </div>
                </div>
                <div class="space-y-5 px-6 py-5">
                    <div class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3">
                        <div class="text-xs font-medium uppercase tracking-wide text-slate-500">目标知识库</div>
                        <div class="mt-1 text-sm font-semibold text-slate-900" data-refresh-modal-name>-</div>
                        <div class="mt-2 flex flex-wrap gap-2 text-xs text-slate-600">
                            <span class="rounded-full bg-white px-2.5 py-1 ring-1 ring-slate-200" data-refresh-modal-summary>-</span>
                            <span class="rounded-full bg-white px-2.5 py-1 ring-1 ring-slate-200" data-refresh-modal-words>-</span>
                        </div>
                    </div>
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                        <div class="rounded-xl border border-emerald-100 bg-emerald-50 px-3 py-3">
                            <div class="text-sm font-semibold text-emerald-800">重建切片</div>
                            <p class="mt-1 text-xs leading-5 text-emerald-700">按当前内容重新生成知识片段。</p>
                        </div>
                        <div class="rounded-xl border border-blue-100 bg-blue-50 px-3 py-3">
                            <div class="text-sm font-semibold text-blue-800">生成向量</div>
                            <p class="mt-1 text-xs leading-5 text-blue-700">调用默认 embedding 模型写入向量。</p>
                        </div>
                        <div class="rounded-xl border border-purple-100 bg-purple-50 px-3 py-3">
                            <div class="text-sm font-semibold text-purple-800">覆盖写入</div>
                            <p class="mt-1 text-xs leading-5 text-purple-700">新的切片会替换旧切片。</p>
                        </div>
                    </div>
                </div>
                <div class="flex items-center justify-end gap-3 border-t border-slate-100 px-6 py-4">
                    <button type="button" class="inline-flex items-center rounded-xl border border-slate-200 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50" data-refresh-chunks-cancel>
                        取消
                    </button>
                    <button type="button" class="inline-flex items-center rounded-xl bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700" data-refresh-chunks-confirm>
                        <i data-lucide="play" class="mr-2 h-4 w-4"></i>
                        继续更新
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        let pendingRefreshChunksForm = null;
        let refreshChunksTimer = null;
        let importProgressTimer = null;

        // 初始化Lucide图标
        document.addEventListener('DOMContentLoaded', function() {
            if (typeof lucide !== 'undefined') {
                lucide.createIcons();
            }

            const fileInput = document.getElementById('knowledge-files-input');
            const folderInput = document.getElementById('knowledge-folder-input');
            const dropzone = document.getElementById('knowledge-dropzone');
            const fileList = document.getElementById('knowledge-file-list');
            const contentInput = document.querySelector('#create-knowledge-form textarea[name="content"]');
            const contentCount = document.getElementById('knowledge-content-count');
            const escapeHtml = (value) => String(value).replace(/[&<>"']/g, (char) => ({
                '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
            }[char]));
            const supportedKnowledgeFile = (file) => /\.(txt|md|docx|pdf|pptx|ppt)$/i.test(file.name || '');

            function renderFileList() {
                if (!fileInput || !fileList) return;
                const files = [
                    ...Array.from(fileInput.files || []),
                    ...Array.from(folderInput ? folderInput.files || [] : [])
                ];
                if (files.length === 0) {
                    fileList.classList.add('hidden');
                    fileList.innerHTML = '';
                    return;
                }
                fileList.classList.remove('hidden');
                fileList.innerHTML = files.map((file) => {
                    const sizeMb = (file.size / 1024 / 1024).toFixed(2);
                    const displayName = file.webkitRelativePath || file.relativePath || file.name;
                    return `<div class="flex items-center justify-between gap-3 px-3 py-2 text-sm">
                        <span class="truncate text-gray-700">${escapeHtml(displayName)}</span>
                        <span class="shrink-0 text-xs text-gray-400">${sizeMb} MB</span>
                    </div>`;
                }).join('');
            }

            if (fileInput) {
                fileInput.addEventListener('change', renderFileList);
            }
            if (folderInput) {
                folderInput.addEventListener('change', renderFileList);
            }

            if (dropzone && fileInput) {
                ['dragenter', 'dragover'].forEach((eventName) => {
                    dropzone.addEventListener(eventName, (event) => {
                        event.preventDefault();
                        dropzone.classList.add('border-orange-400', 'bg-orange-50');
                    });
                });
                ['dragleave', 'drop'].forEach((eventName) => {
                    dropzone.addEventListener(eventName, (event) => {
                        event.preventDefault();
                        dropzone.classList.remove('border-orange-400', 'bg-orange-50');
                    });
                });
                const readEntryFiles = async (entry, path = '') => {
                    if (!entry) return [];
                    if (entry.isFile) {
                        return await new Promise((resolve) => {
                            entry.file((file) => {
                                if (!supportedKnowledgeFile(file)) {
                                    resolve([]);
                                    return;
                                }
                                Object.defineProperty(file, 'relativePath', {
                                    value: path + file.name,
                                    configurable: true
                                });
                                resolve([file]);
                            }, () => resolve([]));
                        });
                    }
                    if (!entry.isDirectory) return [];
                    const reader = entry.createReader();
                    const entries = [];
                    while (true) {
                        const batch = await new Promise((resolve) => reader.readEntries(resolve, () => resolve([])));
                        if (!batch.length) break;
                        entries.push(...batch);
                    }
                    const nested = await Promise.all(entries.map((child) => readEntryFiles(child, path + entry.name + '/')));
                    return nested.flat();
                };

                dropzone.addEventListener('drop', async (event) => {
                    if (!event.dataTransfer) return;
                    const items = Array.from(event.dataTransfer.items || []);
                    let files = [];
                    if (items.length && items.some((item) => typeof item.webkitGetAsEntry === 'function')) {
                        const collected = await Promise.all(items.map((item) => readEntryFiles(item.webkitGetAsEntry())));
                        files = collected.flat();
                    } else {
                        files = Array.from(event.dataTransfer.files || []).filter(supportedKnowledgeFile);
                    }

                    const dt = new DataTransfer();
                    files.slice(0, 200).forEach((file) => dt.items.add(file));
                    fileInput.files = dt.files;
                    renderFileList();
                });
            }

            if (contentInput && contentCount) {
                const updateCount = () => {
                    contentCount.textContent = `${contentInput.value.length.toLocaleString()} 字`;
                };
                contentInput.addEventListener('input', updateCount);
                updateCount();
            }

            if (new URLSearchParams(window.location.search).get('create') === '1') {
                showCreateModal();
            }

            document.querySelectorAll('[data-refresh-chunks-form]').forEach(function (form) {
                form.addEventListener('submit', function (event) {
                    event.preventDefault();
                    showRefreshChunksModal(form);
                });
            });

            document.querySelectorAll('[data-refresh-chunks-cancel]').forEach(function (button) {
                button.addEventListener('click', function () {
                    pendingRefreshChunksForm = null;
                    hideRefreshChunksModal();
                });
            });

            const refreshConfirmButton = document.querySelector('[data-refresh-chunks-confirm]');
            if (refreshConfirmButton) {
                refreshConfirmButton.addEventListener('click', function () {
                    if (!pendingRefreshChunksForm) {
                        hideRefreshChunksModal();
                        return;
                    }

                    const form = pendingRefreshChunksForm;
                    pendingRefreshChunksForm = null;
                    hideRefreshChunksModal();
                    startRefreshChunksProgress(form);
                });
            }

            document.querySelectorAll('#create-knowledge-form, #upload-modal form').forEach(function (form) {
                form.addEventListener('submit', function (event) {
                    startImportProgress(form, event.submitter || form.querySelector('[type="submit"]'));
                });
            });

            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape' && pendingRefreshChunksForm) {
                    pendingRefreshChunksForm = null;
                    hideRefreshChunksModal();
                }
            });
        });

        function startImportProgress(form, submitter) {
            const progress = form.querySelector('[data-import-progress]');
            const progressLabel = form.querySelector('[data-import-progress-label]');
            const progressValue = form.querySelector('[data-import-progress-value]');
            const progressBar = form.querySelector('[data-import-progress-bar]');
            const buttons = form.querySelectorAll('button[type="submit"]');
            const actionInput = form.querySelector('[data-import-action-input]');
            let percent = 10;

            if (actionInput && submitter && submitter.dataset && submitter.dataset.importAction) {
                actionInput.value = submitter.dataset.importAction;
            }

            buttons.forEach((button) => {
                button.disabled = true;
                button.classList.add('cursor-wait', 'opacity-80');
            });

            if (submitter) {
                const label = submitter.dataset && submitter.dataset.importLabel ? submitter.dataset.importLabel : '处理中';
                submitter.innerHTML = `<span class="inline-flex items-center"><span class="mr-2 inline-block h-3 w-3 animate-spin rounded-full border-2 border-current border-t-transparent"></span>${label}</span>`;
            }

            if (progress) {
                progress.classList.remove('hidden');
            }

            const renderProgress = () => {
                if (progressValue) progressValue.textContent = `${percent}%`;
                if (progressBar) progressBar.style.width = `${percent}%`;
                if (progressLabel) {
                    progressLabel.textContent = percent >= 70
                        ? '正在写入知识片段与向量'
                        : (percent >= 36 ? '正在解析文件并生成切片' : '正在保存知识库');
                }
            };

            renderProgress();
            if (importProgressTimer) window.clearInterval(importProgressTimer);
            importProgressTimer = window.setInterval(function () {
                percent = Math.min(92, percent + (percent < 50 ? 12 : 6));
                renderProgress();
                if (percent >= 92 && importProgressTimer) {
                    window.clearInterval(importProgressTimer);
                    importProgressTimer = null;
                }
            }, 450);
        }

        function showRefreshChunksModal(form) {
            const modal = document.querySelector('[data-knowledge-refresh-modal]');
            if (!modal) {
                return true;
            }

            pendingRefreshChunksForm = form;
            const nameNode = modal.querySelector('[data-refresh-modal-name]');
            const summaryNode = modal.querySelector('[data-refresh-modal-summary]');
            const wordsNode = modal.querySelector('[data-refresh-modal-words]');

            if (nameNode) nameNode.textContent = form.dataset.knowledgeName || '-';
            if (summaryNode) summaryNode.textContent = form.dataset.knowledgeSummary || '-';
            if (wordsNode) wordsNode.textContent = form.dataset.wordCount || '-';

            modal.classList.remove('hidden');
            const confirmButton = modal.querySelector('[data-refresh-chunks-confirm]');
            if (confirmButton) {
                setTimeout(function () {
                    confirmButton.focus();
                }, 0);
            }

            return false;
        }

        function hideRefreshChunksModal() {
            const modal = document.querySelector('[data-knowledge-refresh-modal]');
            if (modal) {
                modal.classList.add('hidden');
            }
        }

        function startRefreshChunksProgress(form) {
            const wrapper = form.closest('[data-refresh-chunks-action]');
            const button = form.querySelector('[data-refresh-submit-button]');
            const icon = form.querySelector('[data-refresh-submit-icon]');
            const buttonLabel = form.querySelector('[data-refresh-submit-label]');
            const progress = wrapper ? wrapper.querySelector('[data-refresh-progress]') : null;
            const progressLabel = wrapper ? wrapper.querySelector('[data-refresh-progress-label]') : null;
            const progressValue = wrapper ? wrapper.querySelector('[data-refresh-progress-value]') : null;
            const progressBar = wrapper ? wrapper.querySelector('[data-refresh-progress-bar]') : null;
            let percent = 12;

            if (button) {
                button.disabled = true;
                button.classList.add('cursor-wait', 'opacity-80');
            }
            if (icon) icon.classList.add('animate-spin');
            if (buttonLabel) buttonLabel.textContent = '更新中';
            if (progress) progress.classList.remove('hidden');

            const renderProgress = function () {
                if (progressValue) progressValue.textContent = percent + '%';
                if (progressBar) progressBar.style.width = percent + '%';
                if (progressLabel) {
                    progressLabel.textContent = percent >= 70
                        ? '正在写入向量'
                        : (percent >= 38 ? '正在生成 embedding' : '正在重建切片');
                }
            };

            renderProgress();
            refreshChunksTimer = window.setInterval(function () {
                percent = Math.min(92, percent + (percent < 50 ? 11 : 6));
                renderProgress();
                if (percent >= 92 && refreshChunksTimer) {
                    window.clearInterval(refreshChunksTimer);
                    refreshChunksTimer = null;
                }
            }, 420);

            setTimeout(function () {
                form.submit();
            }, 180);
        }

        // 显示创建模态框
        function showCreateModal() {
            document.getElementById('create-modal').classList.remove('hidden');
        }

        // 隐藏创建模态框
        function hideCreateModal() {
            document.getElementById('create-modal').classList.add('hidden');
        }

        // 显示上传模态框
        function showUploadModal() {
            document.getElementById('upload-modal').classList.remove('hidden');
        }

        // 隐藏上传模态框
        function hideUploadModal() {
            document.getElementById('upload-modal').classList.add('hidden');
        }

        function showEmbeddingConfigModal() {
            const modal = document.getElementById('embedding-config-modal');
            if (modal) {
                modal.classList.remove('hidden');
            }
        }

        function hideEmbeddingConfigModal() {
            const modal = document.getElementById('embedding-config-modal');
            if (modal) {
                modal.classList.add('hidden');
            }
        }

        // 删除知识库
        function deleteKnowledge(knowledgeId, knowledgeName) {
            if (confirm(`确定要删除知识库"${knowledgeName}"吗？此操作不可恢复！`)) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    <input type="hidden" name="action" value="delete_knowledge">
                    <input type="hidden" name="knowledge_id" value="${knowledgeId}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }

        // 点击模态框外部关闭
        window.onclick = function(event) {
            const createModal = document.getElementById('create-modal');
            const uploadModal = document.getElementById('upload-modal');
            const embeddingConfigModal = document.getElementById('embedding-config-modal');
            
            if (event.target === createModal) {
                hideCreateModal();
            }
            if (event.target === uploadModal) {
                hideUploadModal();
            }
            if (event.target === embeddingConfigModal) {
                hideEmbeddingConfigModal();
            }
        }
    </script>
</body>
</html>
