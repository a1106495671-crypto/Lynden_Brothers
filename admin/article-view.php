<?php
/**
 * 智能GEO内容系统 - 文章查看
 */

define('FEISHU_TREASURE', true);
session_start();

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database_admin.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/distribution_publisher_service.php';

require_admin_login();
session_write_close();

$article_id = (int) ($_GET['id'] ?? 0);
$message = '';
$error = '';

if ($article_id <= 0) {
    header('Location: articles.php');
    exit;
}

$stmt = $db->prepare("
    SELECT a.*,
           t.name as task_name,
           au.name as author_name,
           c.name as category_name
    FROM articles a
    LEFT JOIN tasks t ON a.task_id = t.id
    LEFT JOIN authors au ON a.author_id = au.id
    LEFT JOIN categories c ON a.category_id = c.id
    WHERE a.id = ? AND a.deleted_at IS NULL
");
$stmt->execute([$article_id]);
$article = $stmt->fetch();

if (!$article) {
    header('Location: articles.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'CSRF验证失败';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'update_status') {
            $workflowState = normalize_article_workflow_state(
                $_POST['status'] ?? 'draft',
                $article['review_status'] ?? 'pending',
                $article['published_at'] ?? null
            );
            $stmt = $db->prepare("
                UPDATE articles
                SET status = ?, review_status = ?, published_at = ?, updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            if ($stmt->execute([$workflowState['status'], $workflowState['review_status'], $workflowState['published_at'], $article_id])) {
                $message = '发布状态已更新，审核结果与发布时间已同步收敛';
                if ($workflowState['status'] === 'published') {
                    $autoDistribution = distribution_handle_article_published($db, $article_id);
                    if (($autoDistribution['created'] ?? 0) > 0) {
                        $message .= '，已自动加入媒体分发队列';
                    }
                }
                $article['status'] = $workflowState['status'];
                $article['review_status'] = $workflowState['review_status'];
                $article['published_at'] = $workflowState['published_at'];
            } else {
                $error = '发布状态更新失败';
            }
        }

        if ($action === 'update_review') {
            $review_status = $_POST['review_status'] ?? '';
            if ($review_status !== '') {
                $desiredStatus = $article['status'] ?? 'draft';
                if (in_array($review_status, ['approved', 'auto_approved'], true)) {
                    $task_stmt = $db->prepare("SELECT need_review FROM tasks WHERE id = ?");
                    $task_stmt->execute([$article['task_id']]);
                    $task = $task_stmt->fetch();
                    if ($review_status === 'auto_approved' || ($task && !$task['need_review'])) {
                        $desiredStatus = 'published';
                    }
                }

                $workflowState = normalize_article_workflow_state($desiredStatus, $review_status, $article['published_at'] ?? null);
                $stmt = $db->prepare("
                    UPDATE articles
                    SET status = ?, review_status = ?, published_at = ?, updated_at = CURRENT_TIMESTAMP
                    WHERE id = ?
                ");
                if ($stmt->execute([$workflowState['status'], $workflowState['review_status'], $workflowState['published_at'], $article_id])) {
                    $message = '审核结果已更新';
                    if ($workflowState['status'] === 'published' && in_array($workflowState['review_status'], ['approved', 'auto_approved'], true)) {
                        $message .= '，文章已进入发布状态';
                        $autoDistribution = distribution_handle_article_published($db, $article_id);
                        if (($autoDistribution['created'] ?? 0) > 0) {
                            $message .= '，已自动加入媒体分发队列';
                        }
                    } elseif ($workflowState['status'] === 'draft' && $workflowState['review_status'] === 'rejected') {
                        $message .= '，文章已退回草稿';
                    }
                    $article['status'] = $workflowState['status'];
                    $article['review_status'] = $workflowState['review_status'];
                    $article['published_at'] = $workflowState['published_at'];
                } else {
                    $error = '审核结果更新失败';
                }
            }
        }
    }
}

$stmt = $db->prepare("
    SELECT ai.*, i.file_path, i.original_name
    FROM article_images ai
    LEFT JOIN images i ON ai.image_id = i.id
    WHERE ai.article_id = ?
    ORDER BY ai.position
");
$stmt->execute([$article_id]);
$article_images = $stmt->fetchAll();

$article_content = (string) ($article['content'] ?? '');
$article_display_content = $article_content;
if ($article_display_content !== '') {
    $title_pattern = preg_quote(trim((string) $article['title']), '/');
    $article_display_content = preg_replace('/^\s*#\s*' . $title_pattern . '\s*(?:\r?\n)+/u', '', $article_display_content, 1);
}
$article_rendered_html = markdown_to_html($article_display_content);
$copy_markdown_text = trim($article_content !== '' ? $article_content : ('# ' . ($article['title'] ?? '') . "\n\n" . ($article['excerpt'] ?? '')));

$page_title = '查看文章';
$page_header = '
<div class="flex items-center space-x-4">
    <a href="articles.php" class="text-gray-400 hover:text-gray-600">
        <i data-lucide="arrow-left" class="w-5 h-5"></i>
    </a>
    <div>
        <h1 class="text-2xl font-bold text-gray-900">查看文章</h1>
        <p class="mt-1 text-sm text-gray-600">' . htmlspecialchars($article['title'], ENT_QUOTES, 'UTF-8') . '</p>
    </div>
</div>';
$additional_css = '
<style>
    .markdown-content { color: #374151; font-size: 16px; line-height: 1.85; }
    .markdown-content h1, .markdown-content h2, .markdown-content h3, .markdown-content h4 { color: #111827; font-weight: 700; line-height: 1.35; margin-top: 1.75rem; margin-bottom: 1rem; }
    .markdown-content h1 { font-size: 1.875rem; }
    .markdown-content h2 { font-size: 1.5rem; }
    .markdown-content h3 { font-size: 1.25rem; }
    .markdown-content h4 { font-size: 1.125rem; }
    .markdown-content p, .markdown-content ul, .markdown-content ol, .markdown-content blockquote { margin-bottom: 1rem; }
    .markdown-content ul, .markdown-content ol { padding-left: 1.5rem; }
    .markdown-content img { max-width: 100%; height: auto; margin: 1rem 0; border-radius: 0.5rem; }
    .markdown-content blockquote { border-left: 4px solid #e5e7eb; padding-left: 1rem; margin: 1rem 0; color: #6b7280; }
    .markdown-content table { width: 100%; border-collapse: collapse; margin: 1rem 0; }
    .markdown-content th, .markdown-content td { border: 1px solid #e5e7eb; padding: 0.625rem; text-align: left; }
    .markdown-content th { background: #f9fafb; color: #111827; font-weight: 600; }
    .article-copy-toolbar { display: flex; flex-wrap: wrap; align-items: center; gap: 0.625rem; margin: 1rem 0 1.5rem; }
    .article-copy-button { display: inline-flex; align-items: center; gap: 0.4rem; min-height: 2.25rem; padding: 0.5rem 0.85rem; border: 1px solid #d1d5db; border-radius: 0.375rem; background: #fff; color: #374151; font-size: 0.875rem; font-weight: 500; line-height: 1; }
    .article-copy-button:hover { background: #f9fafb; border-color: #9ca3af; color: #111827; }
    .article-copy-button--primary { background: #2563eb; border-color: #2563eb; color: #fff; }
    .article-copy-button--primary:hover { background: #1d4ed8; border-color: #1d4ed8; color: #fff; }
    .article-copy-status { min-height: 1.25rem; color: #059669; font-size: 0.875rem; }
    .article-copy-status[data-state="warning"] { color: #b45309; }
    .article-copy-status[data-state="error"] { color: #dc2626; }
</style>';

require_once __DIR__ . '/includes/header.php';
?>

<div class="grid grid-cols-1 lg:grid-cols-4 gap-6">
    <div class="lg:col-span-3">
        <div class="bg-white shadow rounded-lg">
            <div class="px-6 py-4 border-b border-gray-200">
                <div class="flex items-center justify-between">
                    <h3 class="text-lg font-medium text-gray-900">文章内容</h3>
                    <div class="flex space-x-2">
                        <a href="article-edit.php?id=<?php echo (int) $article['id']; ?>" class="inline-flex items-center px-3 py-1.5 border border-gray-300 text-xs font-medium rounded text-gray-700 bg-white hover:bg-gray-50">
                            <i data-lucide="edit" class="w-4 h-4 mr-1"></i>编辑
                        </a>
                        <?php if ($article['status'] === 'published'): ?>
                            <a href="../article/<?php echo urlencode((string) $article['slug']); ?>" target="_blank" class="inline-flex items-center px-3 py-1.5 border border-transparent text-xs font-medium rounded text-white bg-blue-600 hover:bg-blue-700">
                                <i data-lucide="external-link" class="w-4 h-4 mr-1"></i>前台预览
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="px-6 py-6">
                <div class="space-y-6">
	                    <div>
	                        <h1 class="text-2xl font-bold text-gray-900 mb-2"><?php echo htmlspecialchars($article['title']); ?></h1>
	                    </div>
	                    <div class="article-copy-toolbar" aria-label="文章复制工具">
	                        <button type="button" class="article-copy-button article-copy-button--primary" data-copy-rich>
	                            <i data-lucide="copy-check" class="w-4 h-4"></i>复制带格式
	                        </button>
	                        <button type="button" class="article-copy-button" data-copy-markdown>
	                            <i data-lucide="file-text" class="w-4 h-4"></i>复制 Markdown
	                        </button>
	                        <span class="article-copy-status" data-copy-status aria-live="polite"></span>
	                    </div>
	                    <div class="prose max-w-none markdown-content" id="article-content">
	                        <?php echo $article_rendered_html; ?>
	                    </div>
	                    <template id="articleRichCopyTemplate">
	                        <article>
	                            <h1><?php echo htmlspecialchars($article['title']); ?></h1>
	                            <?php echo $article_rendered_html; ?>
	                        </article>
	                    </template>
	                    <script type="application/json" id="articleMarkdownCopyData"><?php echo json_encode($copy_markdown_text, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?></script>
	                </div>
	            </div>
	        </div>

        <?php if (!empty($article_images)): ?>
            <div class="mt-6 bg-white shadow rounded-lg">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-lg font-medium text-gray-900">文章图片</h3>
                </div>
                <div class="px-6 py-4">
                    <div class="grid grid-cols-2 md:grid-cols-3 gap-4">
                        <?php foreach ($article_images as $img): ?>
                            <div class="relative">
                                <img src="<?php echo htmlspecialchars($img['file_path']); ?>" alt="<?php echo htmlspecialchars($img['original_name']); ?>" class="w-full h-32 object-cover rounded-lg">
                                <div class="absolute bottom-0 left-0 right-0 bg-black bg-opacity-50 text-white text-xs p-2 rounded-b-lg"><?php echo htmlspecialchars($img['original_name']); ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <div class="space-y-6">
        <div class="bg-white shadow rounded-lg">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900">文章信息</h3>
            </div>
            <div class="px-6 py-4 space-y-4 text-sm">
                <div><div class="text-gray-500">ID</div><div class="mt-1 text-gray-900"><?php echo (int) $article['id']; ?></div></div>
                <div><div class="text-gray-500">URL别名</div><div class="mt-1 font-mono text-gray-900"><?php echo htmlspecialchars($article['slug']); ?></div></div>
                <div><div class="text-gray-500">分类</div><div class="mt-1 text-gray-900"><?php echo htmlspecialchars($article['category_name'] ?: '未分类'); ?></div></div>
                <div><div class="text-gray-500">作者</div><div class="mt-1 text-gray-900"><?php echo htmlspecialchars($article['author_name']); ?></div></div>
                <div><div class="text-gray-500">来源任务</div><div class="mt-1 text-gray-900"><?php echo $article['task_name'] ? htmlspecialchars($article['task_name']) : '手动创建'; ?></div></div>
                <div><div class="text-gray-500">创建时间</div><div class="mt-1 text-gray-900"><?php echo date('Y-m-d H:i:s', strtotime($article['created_at'])); ?></div></div>
                <?php if ($article['published_at']): ?>
                    <div><div class="text-gray-500">发布时间</div><div class="mt-1 text-gray-900"><?php echo date('Y-m-d H:i:s', strtotime($article['published_at'])); ?></div></div>
                <?php endif; ?>
                <div><div class="text-gray-500">最后更新</div><div class="mt-1 text-gray-900"><?php echo date('Y-m-d H:i:s', strtotime($article['updated_at'])); ?></div></div>
            </div>
        </div>

        <div class="bg-white shadow rounded-lg">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900">状态管理</h3>
            </div>
            <div class="px-6 py-4 space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">发布状态</label>
                    <form method="POST" class="inline">
                        <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                        <input type="hidden" name="action" value="update_status">
                        <select name="status" onchange="this.form.submit()" class="block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                            <option value="draft" <?php echo $article['status'] === 'draft' ? 'selected' : ''; ?>>草稿</option>
                            <option value="published" <?php echo $article['status'] === 'published' ? 'selected' : ''; ?>>已发布</option>
                            <option value="private" <?php echo $article['status'] === 'private' ? 'selected' : ''; ?>>私有</option>
                        </select>
                    </form>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">审核结果</label>
                    <form method="POST" class="inline">
                        <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                        <input type="hidden" name="action" value="update_review">
                        <select name="review_status" onchange="this.form.submit()" class="block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                            <option value="pending" <?php echo $article['review_status'] === 'pending' ? 'selected' : ''; ?>>待人工审核</option>
                            <option value="approved" <?php echo $article['review_status'] === 'approved' ? 'selected' : ''; ?>>人工通过</option>
                            <option value="rejected" <?php echo $article['review_status'] === 'rejected' ? 'selected' : ''; ?>>已拒绝</option>
                            <option value="auto_approved" <?php echo $article['review_status'] === 'auto_approved' ? 'selected' : ''; ?>>自动通过</option>
                        </select>
                    </form>
                    <p class="mt-2 text-xs text-gray-500">调整发布状态或审核结果时，系统会自动同步发布时间，并避免写出冲突流程状态。</p>
                </div>
            </div>
        </div>

        <div class="bg-white shadow rounded-lg">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900">SEO信息</h3>
            </div>
            <div class="px-6 py-4 space-y-4 text-sm">
                <?php if ($article['keywords']): ?><div><div class="text-gray-500">关键词</div><div class="mt-1 text-gray-900"><?php echo htmlspecialchars($article['keywords']); ?></div></div><?php endif; ?>
                <?php if ($article['meta_description']): ?><div><div class="text-gray-500">描述</div><div class="mt-1 text-gray-900"><?php echo htmlspecialchars($article['meta_description']); ?></div></div><?php endif; ?>
                <?php if ($article['original_keyword']): ?><div><div class="text-gray-500">原始关键词</div><div class="mt-1 text-gray-900"><?php echo htmlspecialchars($article['original_keyword']); ?></div></div><?php endif; ?>
            </div>
        </div>

        <div class="bg-white shadow rounded-lg">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900">快速操作</h3>
            </div>
            <div class="px-6 py-4 space-y-3">
                <a href="article-edit.php?id=<?php echo (int) $article['id']; ?>" class="w-full inline-flex items-center justify-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                    <i data-lucide="edit" class="w-4 h-4 mr-2"></i>编辑文章
                </a>
                <?php if ($article['status'] === 'published'): ?>
                    <a href="../article/<?php echo urlencode((string) $article['slug']); ?>" target="_blank" class="w-full inline-flex items-center justify-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700">
                        <i data-lucide="external-link" class="w-4 h-4 mr-2"></i>前台查看
                    </a>
                <?php endif; ?>
                <button onclick="deleteArticle()" class="w-full inline-flex items-center justify-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-red-600 hover:bg-red-700">
                    <i data-lucide="trash-2" class="w-4 h-4 mr-2"></i>删除文章
                </button>
            </div>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const richButton = document.querySelector('[data-copy-rich]');
        const markdownButton = document.querySelector('[data-copy-markdown]');
        const status = document.querySelector('[data-copy-status]');
        const richTemplate = document.getElementById('articleRichCopyTemplate');
        const markdownData = document.getElementById('articleMarkdownCopyData');

        if (!richButton || !markdownButton || !status || !richTemplate || !markdownData) {
            return;
        }

        let markdownText = '';
        try {
            markdownText = JSON.parse(markdownData.textContent || '""');
        } catch (error) {
            markdownText = '';
        }

        function setCopyStatus(message, type) {
            status.textContent = message;
            status.dataset.state = type || 'success';
            window.clearTimeout(setCopyStatus.timer);
            setCopyStatus.timer = window.setTimeout(function () {
                status.textContent = '';
                status.removeAttribute('data-state');
            }, 2600);
        }

        function fallbackCopyText(text) {
            const textarea = document.createElement('textarea');
            textarea.value = text;
            textarea.setAttribute('readonly', '');
            textarea.style.position = 'fixed';
            textarea.style.top = '-9999px';
            document.body.appendChild(textarea);
            textarea.select();
            const copied = document.execCommand('copy');
            textarea.remove();
            if (!copied) {
                throw new Error('copy failed');
            }
        }

        async function copyPlainText(text) {
            if (navigator.clipboard && navigator.clipboard.writeText) {
                await navigator.clipboard.writeText(text);
                return;
            }
            fallbackCopyText(text);
        }

        function applyRichCopyStyles(root) {
            const baseText = 'color:#374151;font-size:16px;line-height:1.85;margin:0 0 16px;';
            root.querySelectorAll('h1').forEach(function (node) { node.setAttribute('style', 'color:#111827;font-size:28px;line-height:1.35;font-weight:700;margin:0 0 20px;'); });
            root.querySelectorAll('h2').forEach(function (node) { node.setAttribute('style', 'color:#111827;font-size:24px;line-height:1.4;font-weight:700;margin:30px 0 16px;'); });
            root.querySelectorAll('h3').forEach(function (node) { node.setAttribute('style', 'color:#111827;font-size:20px;line-height:1.45;font-weight:700;margin:24px 0 14px;'); });
            root.querySelectorAll('h4').forEach(function (node) { node.setAttribute('style', 'color:#111827;font-size:18px;line-height:1.45;font-weight:700;margin:22px 0 12px;'); });
            root.querySelectorAll('p').forEach(function (node) { node.setAttribute('style', baseText); });
            root.querySelectorAll('ul,ol').forEach(function (node) { node.setAttribute('style', baseText + 'padding-left:24px;'); });
            root.querySelectorAll('li').forEach(function (node) { node.setAttribute('style', 'margin:0 0 10px;color:#374151;line-height:1.85;'); });
            root.querySelectorAll('blockquote').forEach(function (node) { node.setAttribute('style', 'border-left:3px solid #d1d5db;padding-left:16px;color:#6b7280;margin:0 0 16px;line-height:1.85;'); });
            root.querySelectorAll('table').forEach(function (node) { node.setAttribute('style', 'border-collapse:collapse;width:100%;margin:0 0 18px;color:#374151;font-size:15px;line-height:1.7;'); });
            root.querySelectorAll('th').forEach(function (node) { node.setAttribute('style', 'border:1px solid #e5e7eb;background:#f9fafb;color:#111827;font-weight:600;padding:10px;text-align:left;'); });
            root.querySelectorAll('td').forEach(function (node) { node.setAttribute('style', 'border:1px solid #e5e7eb;padding:10px;text-align:left;'); });
            root.querySelectorAll('a').forEach(function (node) { node.setAttribute('style', 'color:#2563eb;text-decoration:none;'); });
            root.querySelectorAll('code').forEach(function (node) { node.setAttribute('style', 'background:#f3f4f6;border-radius:5px;padding:2px 6px;font-size:14px;'); });
        }

        async function copyRichArticle() {
            const wrapper = document.createElement('div');
            wrapper.appendChild(richTemplate.content.cloneNode(true));
            applyRichCopyStyles(wrapper);
            const html = wrapper.innerHTML;
            const plainText = wrapper.textContent.replace(/\n{3,}/g, '\n\n').trim();

            if (navigator.clipboard && window.ClipboardItem) {
                await navigator.clipboard.write([
                    new ClipboardItem({
                        'text/html': new Blob([html], { type: 'text/html' }),
                        'text/plain': new Blob([plainText], { type: 'text/plain' })
                    })
                ]);
                return;
            }

            fallbackCopyText(plainText);
        }

        richButton.addEventListener('click', async function () {
            try {
                await copyRichArticle();
                setCopyStatus('已复制带格式内容', 'success');
            } catch (error) {
                try {
                    await copyPlainText(markdownText);
                    setCopyStatus('富文本不可用，已复制 Markdown', 'warning');
                } catch (fallbackError) {
                    setCopyStatus('复制失败，请手动选择文章内容', 'error');
                }
            }
        });

        markdownButton.addEventListener('click', async function () {
            try {
                await copyPlainText(markdownText);
                setCopyStatus('已复制 Markdown', 'success');
            } catch (error) {
                setCopyStatus('复制失败，请手动选择文章内容', 'error');
            }
        });
    });

    function deleteArticle() {
        if (!confirm('确定要删除这篇文章吗？')) {
            return;
        }
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'articles.php';
        form.innerHTML = `
            <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
            <input type="hidden" name="action" value="delete_articles">
            <input type="hidden" name="article_ids[]" value="<?php echo (int) $article['id']; ?>">
        `;
        document.body.appendChild(form);
        form.submit();
    }
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
