<?php
/**
 * AI知识库详情页面
 */

define('FEISHU_TREASURE', true);
session_start();

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database_admin.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/knowledge-retrieval.php';

// 检查管理员登录
require_admin_login();
session_write_close();

$message = '';
$error = '';

// 获取知识库ID
$knowledge_id = intval($_GET['id'] ?? 0);

if ($knowledge_id <= 0) {
    header('Location: knowledge-bases.php');
    exit;
}

// 获取知识库详情
try {
    $stmt = $db->prepare("SELECT * FROM knowledge_bases WHERE id = ?");
    $stmt->execute([$knowledge_id]);
    $knowledge = $stmt->fetch();
    
    if (!$knowledge) {
        header('Location: knowledge-bases.php');
        exit;
    }
} catch (Exception $e) {
    $error = '获取知识库详情失败: ' . $e->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'CSRF验证失败';
    } else {
        switch ($_POST['action']) {
            case 'refresh_chunks':
                try {
                    $content = trim((string) ($knowledge['content'] ?? ''));
                    if ($content === '') {
                        throw new RuntimeException('知识库内容不能为空');
                    }
                    $chunk_count = knowledge_retrieval_sync_chunks($db, $knowledge_id, $content, true);
                    $message = '知识切片已刷新，真实向量写入完成：' . $chunk_count . ' 个片段';
                } catch (Throwable $e) {
                    $error = '刷新失败: ' . $e->getMessage();
                }
                break;

            case 'update_knowledge':
                $name = trim($_POST['name'] ?? '');
                $description = trim($_POST['description'] ?? '');
                $content = trim($_POST['content'] ?? '');
                
                if (empty($name)) {
                    $error = '知识库名称不能为空';
                } elseif (empty($content)) {
                    $error = '知识库内容不能为空';
                } else {
                    try {
                        $word_count = mb_strlen(strip_tags($content));
                        $stmt = $db->prepare("
                            UPDATE knowledge_bases 
                            SET name = ?, description = ?, content = ?, word_count = ?, updated_at = CURRENT_TIMESTAMP 
                            WHERE id = ?
                        ");
                        
                        if ($stmt->execute([$name, $description, $content, $word_count, $knowledge_id])) {
                            $chunk_count = knowledge_retrieval_sync_chunks($db, $knowledge_id, $content);
                            $message = '知识库更新成功，已刷新 ' . $chunk_count . ' 个知识片段';
                            // 重新获取更新后的数据
                            $stmt = $db->prepare("SELECT * FROM knowledge_bases WHERE id = ?");
                            $stmt->execute([$knowledge_id]);
                            $knowledge = $stmt->fetch();
                        } else {
                            $error = '知识库更新失败';
                        }
                    } catch (Exception $e) {
                        $error = '更新失败: ' . $e->getMessage();
                    }
                }
                break;
        }
    }
}

// 获取使用此知识库的任务
$related_tasks = [];
$knowledge_chunk_count = 0;
$knowledge_chunks_preview = [];
$knowledge_vector_count = 0;
try {
    knowledge_retrieval_ensure_chunk_schema($db);

    $stmt = $db->prepare("
        SELECT id, name, status, created_at
        FROM tasks
        WHERE knowledge_base_id = ?
        ORDER BY created_at DESC
    ");
    $stmt->execute([$knowledge_id]);
    $related_tasks = $stmt->fetchAll();

    $chunkStmt = $db->prepare("SELECT COUNT(*) FROM knowledge_chunks WHERE knowledge_base_id = ?");
    $chunkStmt->execute([$knowledge_id]);
    $knowledge_chunk_count = (int) $chunkStmt->fetchColumn();

    $vecStmt = $db->prepare("SELECT COUNT(*) FROM knowledge_chunks WHERE knowledge_base_id = ? AND embedding_model_id IS NOT NULL AND embedding_model_id > 0");
    $vecStmt->execute([$knowledge_id]);
    $knowledge_vector_count = (int) $vecStmt->fetchColumn();

    $previewStmt = $db->prepare("SELECT chunk_index, content, chunk_title, section_path, chunk_strategy, token_count, embedding_model_id, embedding_dimensions, embedding_provider FROM knowledge_chunks WHERE knowledge_base_id = ? ORDER BY chunk_index ASC LIMIT 50");
    $previewStmt->execute([$knowledge_id]);
    $knowledge_chunks_preview = $previewStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // 如果knowledge_base_id字段不存在，忽略错误
}

$page_title = '知识库详情';
$page_header = '
<div class="flex items-center justify-between">
    <div class="flex items-center space-x-4">
        <a href="knowledge-bases.php" class="text-gray-400 hover:text-gray-600">
            <i data-lucide="arrow-left" class="w-5 h-5"></i>
        </a>
        <div>
            <h1 class="text-2xl font-bold text-gray-900">知识库详情</h1>
            <p class="mt-1 text-sm text-gray-600">查看和编辑知识库内容</p>
        </div>
    </div>
    <a href="knowledge-bases.php" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
        <i data-lucide="list" class="w-4 h-4 mr-2"></i>
        返回列表
    </a>
</div>
';

require_once __DIR__ . '/includes/header.php';
?>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <div class="lg:col-span-2">
        <?php if ($message): ?>
            <div class="mb-6 bg-green-50 border border-green-200 rounded-md p-4">
                <div class="flex items-start">
                    <i data-lucide="check-circle" class="w-5 h-5 text-green-400 mt-0.5"></i>
                    <div class="ml-3">
                        <p class="text-sm font-medium text-green-800"><?php echo htmlspecialchars($message); ?></p>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="mb-6 bg-red-50 border border-red-200 rounded-md p-4">
                <div class="flex items-start">
                    <i data-lucide="alert-circle" class="w-5 h-5 text-red-400 mt-0.5"></i>
                    <div class="ml-3">
                        <p class="text-sm font-medium text-red-800"><?php echo htmlspecialchars($error); ?></p>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <div class="bg-white shadow rounded-lg overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900">知识库内容</h3>
            </div>

            <form method="POST" class="p-6" id="knowledge-detail-form">
                <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                <input type="hidden" name="action" value="update_knowledge">

                <div class="space-y-6">
                    <div>
                        <label for="name" class="block text-sm font-medium text-gray-700">知识库名称</label>
                        <input
                            type="text"
                            name="name"
                            id="name"
                            value="<?php echo htmlspecialchars($knowledge['name']); ?>"
                            class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-orange-500 focus:border-orange-500"
                            required
                        >
                    </div>

                    <div>
                        <label for="description" class="block text-sm font-medium text-gray-700">描述</label>
                        <textarea
                            name="description"
                            id="description"
                            rows="3"
                            class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-orange-500 focus:border-orange-500"
                            placeholder="可选：添加知识库的描述信息"
                        ><?php echo htmlspecialchars($knowledge['description'] ?? ''); ?></textarea>
                    </div>

                    <div>
                        <label for="content" class="block text-sm font-medium text-gray-700">内容</label>
                        <textarea
                            name="content"
                            id="content"
                            rows="20"
                            class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-orange-500 focus:border-orange-500 font-mono text-sm"
                            required
                        ><?php echo htmlspecialchars($knowledge['content']); ?></textarea>
                        <p class="mt-2 text-sm text-gray-500">当前字数：<span id="word-count"><?php echo (int) ($knowledge['word_count'] ?? 0); ?></span> 字</p>
                    </div>

                    <div class="flex justify-end">
                        <button type="submit" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-orange-600 hover:bg-orange-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-orange-500" data-detail-submit>
                            <i data-lucide="save" class="w-4 h-4 mr-2"></i>
                            <span data-detail-submit-label>保存更改</span>
                        </button>
                    </div>
                    <div class="hidden" data-detail-progress>
                        <div class="flex items-center justify-between text-xs font-medium text-orange-700">
                            <span data-detail-progress-label>正在保存内容</span>
                            <span data-detail-progress-value>0%</span>
                        </div>
                        <div class="mt-2 h-2 overflow-hidden rounded-full bg-orange-100">
                            <div class="h-full rounded-full bg-orange-500 transition-all duration-500 ease-out" style="width: 8%;" data-detail-progress-bar></div>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="space-y-6">
        <div class="bg-white shadow rounded-lg overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900">基本信息</h3>
            </div>
            <div class="p-6">
                <dl class="space-y-4">
                    <div>
                        <dt class="text-sm font-medium text-gray-500">文件类型</dt>
                        <dd class="mt-1">
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium <?php
                                echo $knowledge['file_type'] === 'markdown' ? 'bg-green-100 text-green-800' :
                                    ($knowledge['file_type'] === 'word' ? 'bg-blue-100 text-blue-800' : 'bg-gray-100 text-gray-800');
                            ?>">
                                <?php
                                switch ($knowledge['file_type']) {
                                    case 'markdown':
                                        echo 'Markdown';
                                        break;
                                    case 'word':
                                        echo 'Word文档';
                                        break;
                                    case 'text':
                                        echo '纯文本';
                                        break;
                                    default:
                                        echo '未知';
                                        break;
                                }
                                ?>
                            </span>
                        </dd>
                    </div>

                    <div>
                        <dt class="text-sm font-medium text-gray-500">字数统计</dt>
                        <dd class="mt-1 text-sm text-gray-900"><?php echo number_format((int) ($knowledge['word_count'] ?? 0)); ?> 字</dd>
                    </div>

                    <div>
                        <dt class="text-sm font-medium text-gray-500">知识片段</dt>
                        <dd class="mt-1 text-sm text-gray-900"><?php echo number_format($knowledge_chunk_count); ?> 个</dd>
                    </div>

                    <div>
                        <dt class="text-sm font-medium text-gray-500">创建时间</dt>
                        <dd class="mt-1 text-sm text-gray-900"><?php echo date('Y-m-d H:i:s', strtotime($knowledge['created_at'])); ?></dd>
                    </div>

                    <div>
                        <dt class="text-sm font-medium text-gray-500">更新时间</dt>
                        <dd class="mt-1 text-sm text-gray-900"><?php echo date('Y-m-d H:i:s', strtotime($knowledge['updated_at'])); ?></dd>
                    </div>

                    <?php if (!empty($knowledge['file_path'])): ?>
                        <div>
                            <dt class="text-sm font-medium text-gray-500">文件路径</dt>
                            <dd class="mt-1 text-sm text-gray-900 break-all"><?php echo htmlspecialchars($knowledge['file_path']); ?></dd>
                        </div>
                    <?php endif; ?>
                </dl>
            </div>
        </div>

        <?php if (!empty($related_tasks)): ?>
            <div class="bg-white shadow rounded-lg overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-lg font-medium text-gray-900">相关任务</h3>
                </div>
                <div class="p-6">
                    <div class="space-y-3">
                        <?php foreach ($related_tasks as $task): ?>
                            <div class="flex items-center justify-between">
                                <div>
                                    <a href="task-edit.php?id=<?php echo $task['id']; ?>" class="text-sm font-medium text-gray-900 hover:text-orange-600">
                                        <?php echo htmlspecialchars($task['name']); ?>
                                    </a>
                                    <p class="text-xs text-gray-500"><?php echo date('Y-m-d', strtotime($task['created_at'])); ?></p>
                                </div>
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium <?php
                                    echo $task['status'] === 'active' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800';
                                ?>">
                                    <?php echo $task['status'] === 'active' ? '运行中' : '已暂停'; ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Chunk preview section -->
<?php if ($knowledge_chunk_count > 0): ?>
<div id="chunk-preview" class="mt-6 bg-white shadow rounded-lg overflow-hidden">
    <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
        <h3 class="text-lg font-medium text-gray-900">
            知识切片预览
            <span class="ml-2 text-sm font-normal text-gray-400"><?php echo $knowledge_chunk_count; ?> 个切片，<?php echo $knowledge_vector_count; ?> 个已向量化</span>
        </h3>
        <div class="flex items-center gap-3">
            <form method="POST" onsubmit="return confirm('将重新切片并强制写入真实向量，确定继续吗？');" data-detail-refresh-form>
                <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                <input type="hidden" name="action" value="refresh_chunks">
                <button type="submit" class="text-sm text-orange-600 hover:text-orange-700 flex items-center gap-1" data-detail-refresh-button>
                    <i data-lucide="refresh-cw" class="w-4 h-4" data-detail-refresh-icon></i> <span data-detail-refresh-label>更新切片</span>
                </button>
            </form>
            <a href="rag-test.php" class="text-sm text-blue-600 hover:text-blue-700 flex items-center gap-1">
                <i data-lucide="flask-conical" class="w-4 h-4"></i> 去检索测试
            </a>
        </div>
    </div>
    <div class="p-4">
        <!-- vector coverage bar -->
        <?php $vec_pct = $knowledge_chunk_count > 0 ? round($knowledge_vector_count / $knowledge_chunk_count * 100) : 0; ?>
        <div class="flex items-center gap-3 mb-4 text-sm">
            <span class="text-gray-500 w-16 text-right shrink-0">向量覆盖</span>
            <div class="flex-1 h-2 bg-gray-100 rounded-full overflow-hidden">
                <div class="h-full <?php echo $vec_pct >= 90 ? 'bg-green-500' : ($vec_pct > 0 ? 'bg-yellow-400' : 'bg-gray-300'); ?> rounded-full" style="width:<?php echo $vec_pct; ?>%"></div>
            </div>
            <span class="text-gray-600 w-10 shrink-0"><?php echo $vec_pct; ?>%</span>
            <?php if ($vec_pct === 0): ?>
                <span class="text-xs text-yellow-600 bg-yellow-50 px-2 py-0.5 rounded">词法 fallback 模式</span>
            <?php elseif ($vec_pct < 100): ?>
                <span class="text-xs text-yellow-600 bg-yellow-50 px-2 py-0.5 rounded">混合模式</span>
            <?php else: ?>
                <span class="text-xs text-green-600 bg-green-50 px-2 py-0.5 rounded">全向量检索</span>
            <?php endif; ?>
        </div>
        <!-- chunk list -->
        <div class="space-y-2 max-h-96 overflow-y-auto pr-1">
            <?php foreach ($knowledge_chunks_preview as $chunk): ?>
                <?php $has_vec = !empty($chunk['embedding_model_id']) && (int) $chunk['embedding_model_id'] > 0; ?>
                <div class="border border-gray-100 rounded-lg p-3 hover:border-gray-200">
                    <div class="flex items-center gap-2 mb-1.5">
                        <span class="text-xs font-mono text-gray-400">#<?php echo (int) $chunk['chunk_index']; ?></span>
                        <?php if ($has_vec): ?>
                            <span class="text-xs bg-purple-100 text-purple-700 px-1.5 py-0.5 rounded"><?php echo (int) ($chunk['embedding_dimensions'] ?? 0); ?> 维</span>
                        <?php else: ?>
                            <span class="text-xs bg-gray-100 text-gray-500 px-1.5 py-0.5 rounded">词法</span>
                        <?php endif; ?>
                        <span class="text-xs bg-orange-50 text-orange-700 px-1.5 py-0.5 rounded"><?php echo htmlspecialchars((string) ($chunk['chunk_strategy'] ?? 'structured_rule')); ?></span>
                        <span class="text-xs text-gray-300"><?php echo (int) $chunk['token_count']; ?> tokens</span>
                    </div>
                    <?php if (!empty($chunk['chunk_title']) || !empty($chunk['section_path'])): ?>
                        <div class="mb-1 text-xs font-medium text-gray-500">
                            <?php echo htmlspecialchars((string) ($chunk['chunk_title'] ?: $chunk['section_path'])); ?>
                        </div>
                    <?php endif; ?>
                    <p class="text-sm text-gray-700 leading-relaxed line-clamp-3"><?php echo htmlspecialchars((string) ($chunk['content'] ?? '')); ?></p>
                </div>
            <?php endforeach; ?>
            <?php if ($knowledge_chunk_count > 50): ?>
                <p class="text-center text-xs text-gray-400 py-2">仅显示前 50 个切片，共 <?php echo $knowledge_chunk_count; ?> 个</p>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
// 实时字数统计
document.getElementById('content').addEventListener('input', function() {
    const content = this.value;
    const wordCount = content.length;
    document.getElementById('word-count').textContent = wordCount.toLocaleString();
});

// 初始化图标
if (typeof lucide !== 'undefined') {
    lucide.createIcons();
}

document.getElementById('knowledge-detail-form')?.addEventListener('submit', function () {
    const form = this;
    const button = form.querySelector('[data-detail-submit]');
    const label = form.querySelector('[data-detail-submit-label]');
    const progress = form.querySelector('[data-detail-progress]');
    const progressLabel = form.querySelector('[data-detail-progress-label]');
    const progressValue = form.querySelector('[data-detail-progress-value]');
    const progressBar = form.querySelector('[data-detail-progress-bar]');
    let percent = 10;

    if (button) {
        button.disabled = true;
        button.classList.add('cursor-wait', 'opacity-80');
    }
    if (label) {
        label.textContent = '保存并刷新切片中';
    }
    if (progress) {
        progress.classList.remove('hidden');
    }

    const render = () => {
        if (progressValue) progressValue.textContent = `${percent}%`;
        if (progressBar) progressBar.style.width = `${percent}%`;
        if (progressLabel) {
            progressLabel.textContent = percent >= 70
                ? '正在写入知识片段与向量'
                : (percent >= 36 ? '正在重新生成切片' : '正在保存内容');
        }
    };

    render();
    const timer = window.setInterval(function () {
        percent = Math.min(92, percent + (percent < 50 ? 12 : 6));
        render();
        if (percent >= 92) {
            window.clearInterval(timer);
        }
    }, 450);
});

document.querySelector('[data-detail-refresh-form]')?.addEventListener('submit', function (event) {
    if (event.defaultPrevented) {
        return;
    }

    const button = this.querySelector('[data-detail-refresh-button]');
    const icon = this.querySelector('[data-detail-refresh-icon]');
    const label = this.querySelector('[data-detail-refresh-label]');

    if (button) {
        button.disabled = true;
        button.classList.add('cursor-wait', 'opacity-80');
    }
    if (icon) {
        icon.classList.add('animate-spin');
    }
    if (label) {
        label.textContent = '更新中';
    }
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
