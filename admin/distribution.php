<?php
/**
 * 分发管理 - GEOFlow 对齐版
 */

define('FEISHU_TREASURE', true);
session_start();

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/database_admin.php';
require_once __DIR__ . '/../includes/distribution_service.php';
require_once __DIR__ . '/../includes/geoflow_distribution_service.php';

require_admin_login();
geoflow_distribution_ensure_schema($db);

$message = '';
$error = '';
$secretNotice = null;
$view = $_GET['view'] ?? 'index';
$editChannelId = (int)($_GET['edit'] ?? 0);

function gd_h(mixed $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function gd_csrf_input(): string {
    return '<input type="hidden" name="csrf_token" value="' . gd_h(generate_csrf_token()) . '">';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'CSRF验证失败';
    } else {
        $action = $_POST['action'] ?? '';
        try {
            if ($action === 'save_channel') {
                $result = geoflow_distribution_save_channel($db, $_POST);
                $message = ((int)($_POST['channel_id'] ?? 0) > 0) ? '分发渠道已更新' : '分发渠道已创建';
                if (!empty($result['secret'])) {
                    $secretNotice = $result['secret'] + ['endpoint_url' => trim((string)($_POST['endpoint_url'] ?? ''))];
                }
            } elseif ($action === 'pause_channel') {
                geoflow_distribution_set_channel_status($db, (int)$_POST['channel_id'], 'paused');
                $message = '分发渠道已暂停';
            } elseif ($action === 'activate_channel') {
                geoflow_distribution_set_channel_status($db, (int)$_POST['channel_id'], 'active');
                $message = '分发渠道已启用';
            } elseif ($action === 'rotate_secret') {
                $secretNotice = geoflow_distribution_create_secret($db, (int)$_POST['channel_id']);
                $channel = geoflow_distribution_get_channel($db, (int)$_POST['channel_id']);
                $secretNotice['endpoint_url'] = (string)($channel['endpoint_url'] ?? '');
                $message = '密钥已轮换，请立即保存新密钥';
            } elseif ($action === 'health_check') {
                $result = geoflow_distribution_health($db, (int)$_POST['channel_id']);
                $message = '健康检查通过';
            } elseif ($action === 'sync_settings') {
                geoflow_distribution_sync_site_settings($db, (int)$_POST['channel_id']);
                $message = '目标站点设置已同步';
            } elseif ($action === 'retry_job') {
                geoflow_distribution_retry($db, (int)$_POST['distribution_id']);
                $message = '分发任务已重新入队';
            } elseif ($action === 'run_job') {
                $result = geoflow_distribution_process_job($db, (int)$_POST['distribution_id']);
                if (($result['status'] ?? '') === 'success') {
                    $message = '分发任务执行成功' . (trim((string)($result['remote_url'] ?? '')) !== '' ? '：' . gd_h((string)$result['remote_url']) : '');
                } elseif (($result['status'] ?? '') === 'skipped') {
                    $message = (string)($result['error_message'] ?? '任务已跳过');
                } else {
                    $error = (string)($result['error_message'] ?? '分发任务执行失败');
                }
            } elseif ($action === 'run_queued') {
                $summary = geoflow_distribution_execute_queued_jobs($db, 10);
                $message = "已执行 {$summary['total']} 条分发任务，成功 {$summary['success']}，失败 {$summary['failed']}，跳过 {$summary['skipped']}";
            } elseif ($action === 'enqueue_article') {
                $created = geoflow_distribution_enqueue_article($db, (int)$_POST['article_id'], array_map('intval', $_POST['channel_ids'] ?? []));
                $message = "已加入分发队列 {$created} 条";
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$stats = geoflow_distribution_stats($db);
$channels = geoflow_distribution_get_channels($db);
$logs = geoflow_distribution_recent_logs($db, 10);
$editChannel = $editChannelId > 0 ? geoflow_distribution_get_channel($db, $editChannelId) : null;

$jobStatus = $_GET['status'] ?? '';
if (!in_array($jobStatus, ['queued', 'sending', 'synced', 'failed', 'deleted', ''], true)) {
    $jobStatus = '';
}
$jobChannelId = max(0, (int)($_GET['channel_id'] ?? 0));
$jobPage = max(1, (int)($_GET['page'] ?? 1));
$jobPerPage = 20;
$jobOffset = ($jobPage - 1) * $jobPerPage;
$jobsTotal = geoflow_distribution_jobs_count($db, $jobStatus, $jobChannelId);
$jobsPages = max(1, (int)ceil($jobsTotal / $jobPerPage));
$jobs = geoflow_distribution_jobs($db, $jobStatus, $jobChannelId, $jobPerPage, $jobOffset);
$publishedArticles = $db->query("SELECT id, title FROM articles WHERE status = 'published' AND deleted_at IS NULL ORDER BY updated_at DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);

$page_title = '分发管理';
$page_header = '
<div class="flex items-center justify-between">
    <div>
        <h1 class="text-2xl font-bold text-gray-900">分发管理</h1>
        <p class="mt-1 text-sm text-gray-600">集中管理目标站 Agent、文章分发队列和远程同步日志</p>
    </div>
    <div class="flex items-center gap-3">
        <a href="distribution.php?view=jobs" class="inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
            <i data-lucide="list-checks" class="mr-2 h-4 w-4"></i>查看队列
        </a>
        <form method="POST" class="inline-flex">
            ' . gd_csrf_input() . '
            <button name="action" value="run_queued" class="inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                <i data-lucide="play" class="mr-2 h-4 w-4"></i>执行队列
            </button>
        </form>
        <a href="distribution.php?view=create" class="inline-flex items-center rounded-md border border-transparent bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">
            <i data-lucide="plus" class="mr-2 h-4 w-4"></i>新建渠道
        </a>
    </div>
</div>';

require_once __DIR__ . '/includes/header.php';
?>

<?php if ($message): ?>
<div class="mb-6 rounded-md border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800"><?= $message ?></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="mb-6 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800"><?= gd_h($error) ?></div>
<?php endif; ?>
<?php if ($secretNotice): ?>
<div class="mb-6 rounded-lg border border-amber-300 bg-amber-50 px-4 py-4">
    <div class="text-sm font-semibold text-amber-900">请立即保存渠道密钥</div>
    <p class="mt-1 text-sm text-amber-800">密钥只在本次操作后展示一次，目标站 Agent 需要用它校验请求。</p>
    <div class="mt-4 grid grid-cols-1 gap-3 md:grid-cols-3">
        <div><div class="text-xs font-medium uppercase text-amber-700">Key ID</div><code class="mt-1 block break-all rounded border border-amber-200 bg-white px-3 py-2 text-sm"><?= gd_h($secretNotice['key_id'] ?? '') ?></code></div>
        <div><div class="text-xs font-medium uppercase text-amber-700">Secret</div><code class="mt-1 block break-all rounded border border-amber-200 bg-white px-3 py-2 text-sm"><?= gd_h($secretNotice['secret'] ?? '') ?></code></div>
        <div><div class="text-xs font-medium uppercase text-amber-700">Endpoint</div><code class="mt-1 block break-all rounded border border-amber-200 bg-white px-3 py-2 text-sm"><?= gd_h($secretNotice['endpoint_url'] ?? '') ?></code></div>
    </div>
</div>
<?php endif; ?>

<?php if ($view === 'create' || $editChannel): ?>
<?php
    $form = $editChannel ?: ['channel_type' => 'geoflow_agent', 'front_mode' => 'static', 'status' => 'active'];
    $siteSettings = geoflow_distribution_decode($form['site_settings'] ?? null);
    $channelConfig = geoflow_distribution_decode($form['channel_config'] ?? null);
?>
<div class="mb-8 flex items-center space-x-4">
    <a href="distribution.php" class="text-gray-400 hover:text-gray-600"><i data-lucide="arrow-left" class="h-5 w-5"></i></a>
    <div>
        <h2 class="text-xl font-bold text-gray-900"><?= $editChannel ? '编辑渠道' : '新建渠道' ?></h2>
        <p class="mt-1 text-sm text-gray-600">创建目标站 Agent 或 WordPress REST 渠道，发布文章后自动进入分发队列。</p>
    </div>
</div>
<div class="rounded-lg bg-white shadow">
    <form method="POST" class="space-y-6 px-6 py-6">
        <?= gd_csrf_input() ?>
        <input type="hidden" name="action" value="save_channel">
        <input type="hidden" name="channel_id" value="<?= (int)($form['id'] ?? 0) ?>">
        <div>
            <label class="block text-sm font-medium text-gray-700">渠道名称 *</label>
            <input name="name" required value="<?= gd_h($form['name'] ?? '') ?>" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="客户官网 Agent">
        </div>
        <fieldset class="rounded-lg border border-gray-200 bg-gray-50 p-4">
            <legend class="text-sm font-medium text-gray-900">渠道类型</legend>
            <div class="mt-4 grid grid-cols-1 gap-3 md:grid-cols-2">
                <?php foreach (['geoflow_agent' => 'GEOFlow Agent', 'wordpress_rest' => 'WordPress REST 渠道'] as $type => $label): ?>
                <label class="flex cursor-pointer gap-3 rounded-md border border-gray-200 bg-white p-4 hover:border-blue-300">
                    <input type="radio" name="channel_type" value="<?= $type ?>" class="mt-1 text-blue-600" <?= (($form['channel_type'] ?? 'geoflow_agent') === $type) ? 'checked' : '' ?>>
                    <span><span class="block text-sm font-semibold text-gray-900"><?= $label ?></span><span class="mt-1 block text-sm text-gray-600"><?= $type === 'wordpress_rest' ? '通过 WordPress REST API 发布文章' : '目标站安装 Agent 后接收文章、设置与删除同步' ?></span></span>
                </label>
                <?php endforeach; ?>
            </div>
        </fieldset>
        <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
            <div><label class="block text-sm font-medium text-gray-700">域名 *</label><input name="domain" required value="<?= gd_h($form['domain'] ?? '') ?>" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm" placeholder="example.com"></div>
            <div><label class="block text-sm font-medium text-gray-700">Agent/REST 地址 *</label><input name="endpoint_url" required value="<?= gd_h($form['endpoint_url'] ?? '') ?>" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm" placeholder="https://example.com/geoflow-agent.php"></div>
        </div>
        <div class="grid grid-cols-1 gap-6 md:grid-cols-3">
            <div><label class="block text-sm font-medium text-gray-700">前台模式</label><select name="front_mode" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm"><option value="static" <?= (($form['front_mode'] ?? 'static') === 'static') ? 'selected' : '' ?>>静态页面</option><option value="rewrite" <?= (($form['front_mode'] ?? '') === 'rewrite') ? 'selected' : '' ?>>伪静态重写</option></select></div>
            <div><label class="block text-sm font-medium text-gray-700">主题模板</label><input name="template_key" value="<?= gd_h($form['template_key'] ?? '') ?>" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm" placeholder="default"></div>
            <div><label class="block text-sm font-medium text-gray-700">状态</label><select name="status" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm"><option value="active" <?= (($form['status'] ?? 'active') === 'active') ? 'selected' : '' ?>>启用</option><option value="paused" <?= (($form['status'] ?? '') === 'paused') ? 'selected' : '' ?>>暂停</option></select></div>
        </div>
        <div class="rounded-lg border border-blue-100 bg-blue-50 p-5">
            <h3 class="text-base font-medium text-gray-900">WordPress REST 设置</h3>
            <div class="mt-4 grid grid-cols-1 gap-6 md:grid-cols-2">
                <div><label class="block text-sm font-medium text-gray-700">用户名</label><input name="wordpress_username" value="<?= gd_h($channelConfig['wordpress_username'] ?? '') ?>" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm"></div>
                <div><label class="block text-sm font-medium text-gray-700">Application Password</label><input type="password" name="wordpress_application_password" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm" autocomplete="new-password"></div>
            </div>
        </div>
        <div class="rounded-lg border border-gray-200 bg-gray-50 p-5">
            <h3 class="text-base font-medium text-gray-900">目标站设置</h3>
            <div class="mt-4 grid grid-cols-1 gap-6 md:grid-cols-2">
                <div><label class="block text-sm font-medium text-gray-700">站点名称</label><input name="site_name" value="<?= gd_h($siteSettings['site_name'] ?? ($form['name'] ?? '')) ?>" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm"></div>
                <div><label class="block text-sm font-medium text-gray-700">关键词</label><input name="site_keywords" value="<?= gd_h($siteSettings['site_keywords'] ?? '') ?>" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm"></div>
                <div class="md:col-span-2"><label class="block text-sm font-medium text-gray-700">站点描述</label><textarea name="site_description" rows="3" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm"><?= gd_h($siteSettings['site_description'] ?? '') ?></textarea></div>
            </div>
        </div>
        <div><label class="block text-sm font-medium text-gray-700">备注</label><textarea name="description" rows="3" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm"><?= gd_h($form['description'] ?? '') ?></textarea></div>
        <div class="flex justify-end gap-3"><a href="distribution.php" class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700">取消</a><button class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700"><i data-lucide="key-round" class="mr-2 inline h-4 w-4"></i>保存渠道</button></div>
    </form>
</div>
<?php elseif ($view === 'jobs'): ?>
<div class="rounded-lg bg-white shadow">
    <form method="GET" class="grid grid-cols-1 gap-4 border-b border-gray-200 px-6 py-4 md:grid-cols-4">
        <input type="hidden" name="view" value="jobs">
        <div><label class="block text-sm font-medium text-gray-700">状态</label><select name="status" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm"><option value="">全部状态</option><?php foreach (['queued','sending','synced','failed','deleted'] as $s): ?><option value="<?= $s ?>" <?= $jobStatus === $s ? 'selected' : '' ?>><?= geoflow_distribution_status_label($s) ?></option><?php endforeach; ?></select></div>
        <div><label class="block text-sm font-medium text-gray-700">渠道</label><select name="channel_id" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm"><option value="0">全部渠道</option><?php foreach ($channels as $channel): ?><option value="<?= (int)$channel['id'] ?>" <?= $jobChannelId === (int)$channel['id'] ? 'selected' : '' ?>><?= gd_h($channel['name']) ?></option><?php endforeach; ?></select></div>
        <div class="flex items-end gap-3 md:col-span-2"><button class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white">筛选</button><a href="distribution.php?view=jobs" class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700">重置</a></div>
    </form>
    <div class="border-b border-gray-200 px-6 py-4"><h2 class="text-lg font-medium text-gray-900">分发队列</h2></div>
    <?php if (empty($jobs)): ?>
    <div class="px-6 py-10 text-center text-sm text-gray-500">暂无分发任务</div>
    <?php else: ?>
    <div class="overflow-x-auto"><table class="min-w-full divide-y divide-gray-200"><thead class="bg-gray-50"><tr><th class="px-6 py-3 text-left text-xs font-medium uppercase text-gray-500">文章</th><th class="px-6 py-3 text-left text-xs font-medium uppercase text-gray-500">渠道</th><th class="px-6 py-3 text-left text-xs font-medium uppercase text-gray-500">状态</th><th class="px-6 py-3 text-left text-xs font-medium uppercase text-gray-500">远程链接</th><th class="px-6 py-3 text-left text-xs font-medium uppercase text-gray-500">操作</th></tr></thead><tbody class="divide-y divide-gray-200 bg-white">
    <?php foreach ($jobs as $job): ?>
        <tr><td class="px-6 py-4 text-sm"><div class="font-medium text-gray-900"><?= gd_h($job['article_title'] ?? '') ?></div><div class="text-xs text-gray-500">#<?= (int)$job['article_id'] ?></div></td><td class="px-6 py-4 text-sm text-gray-600"><?= gd_h($job['channel_name'] ?? '') ?></td><td class="px-6 py-4 text-sm"><span class="rounded-full px-2 py-1 text-xs font-medium <?= $job['status'] === 'failed' ? 'bg-red-100 text-red-800' : ($job['status'] === 'synced' ? 'bg-green-100 text-green-800' : 'bg-blue-100 text-blue-800') ?>"><?= geoflow_distribution_status_label((string)$job['status']) ?></span><?php if (!empty($job['last_error_message'])): ?><div class="mt-1 max-w-xs text-xs text-red-600"><?= gd_h($job['last_error_message']) ?></div><?php endif; ?></td><td class="px-6 py-4 text-sm"><?php if ($job['remote_url']): ?><a href="<?= gd_h($job['remote_url']) ?>" target="_blank" class="text-blue-600 hover:underline">打开</a><?php else: ?><span class="text-gray-400">-</span><?php endif; ?></td><td class="px-6 py-4 text-sm"><div class="flex flex-wrap gap-3"><?php if (in_array($job['status'], ['queued','failed'], true)): ?><form method="POST"><?= gd_csrf_input() ?><input type="hidden" name="action" value="run_job"><input type="hidden" name="distribution_id" value="<?= (int)$job['id'] ?>"><button class="text-green-600 hover:text-green-800">执行</button></form><?php endif; ?><?php if ($job['status'] === 'failed'): ?><form method="POST"><?= gd_csrf_input() ?><input type="hidden" name="action" value="retry_job"><input type="hidden" name="distribution_id" value="<?= (int)$job['id'] ?>"><button class="text-blue-600 hover:text-blue-800">重试</button></form><?php endif; ?></div></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
    <?php endif; ?>
</div>
<?php else: ?>
<div class="grid grid-cols-1 gap-6 md:grid-cols-4 mb-8">
    <?php foreach ([['渠道总数',$stats['total'],'text-gray-900'],['活跃渠道',$stats['active'],'text-green-700'],['待处理分发',$stats['pending'],'text-blue-700'],['失败分发',$stats['failed'],'text-red-700']] as $card): ?>
    <div class="rounded-lg bg-white p-5 shadow"><div class="text-sm font-medium text-gray-500"><?= gd_h($card[0]) ?></div><div class="mt-2 text-2xl font-semibold <?= $card[2] ?>"><?= (int)$card[1] ?></div></div>
    <?php endforeach; ?>
</div>

<div class="mb-8 rounded-lg bg-white shadow">
    <div class="border-b border-gray-200 px-6 py-4"><h2 class="text-lg font-medium text-gray-900">分发渠道</h2></div>
    <?php if (empty($channels)): ?>
    <div class="px-6 py-10 text-center text-sm text-gray-500"><i data-lucide="radio-tower" class="mx-auto mb-3 h-10 w-10 text-gray-400"></i><div class="font-medium text-gray-900">还没有分发渠道</div><div class="mt-1">创建第一个目标站 Agent 渠道后，任务可以在本地发布后自动分发文章。</div></div>
    <?php else: ?>
    <div class="overflow-x-auto"><table class="min-w-full divide-y divide-gray-200"><thead class="bg-gray-50"><tr><th class="px-6 py-3 text-left text-xs font-medium uppercase text-gray-500">名称</th><th class="px-6 py-3 text-left text-xs font-medium uppercase text-gray-500">域名</th><th class="px-6 py-3 text-left text-xs font-medium uppercase text-gray-500">状态</th><th class="px-6 py-3 text-left text-xs font-medium uppercase text-gray-500">队列</th><th class="px-6 py-3 text-left text-xs font-medium uppercase text-gray-500">操作</th></tr></thead><tbody class="divide-y divide-gray-200 bg-white">
    <?php foreach ($channels as $channel): ?>
    <tr><td class="px-6 py-4 text-sm"><div class="font-medium text-gray-900"><?= gd_h($channel['name']) ?></div><div class="mt-1 inline-flex rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600"><?= geoflow_distribution_channel_type_label((string)$channel['channel_type']) ?></div></td><td class="px-6 py-4 text-sm text-gray-600"><?= gd_h($channel['domain']) ?></td><td class="px-6 py-4 text-sm"><span class="rounded-full px-2 py-1 text-xs font-medium <?= $channel['status'] === 'active' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-700' ?>"><?= geoflow_distribution_status_label((string)$channel['status']) ?></span><?php if (!empty($channel['last_health_status'])): ?><div class="mt-1 text-xs text-gray-500">健康：<?= gd_h($channel['last_health_status']) ?></div><?php endif; ?></td><td class="px-6 py-4 text-sm text-gray-600">待处理 <?= (int)$channel['pending_count'] ?> / 失败 <?= (int)$channel['failed_count'] ?></td><td class="px-6 py-4 text-sm"><div class="flex flex-wrap items-center gap-3"><a href="distribution.php?edit=<?= (int)$channel['id'] ?>" class="text-gray-600 hover:text-gray-800">编辑</a><form method="POST"><?= gd_csrf_input() ?><input type="hidden" name="channel_id" value="<?= (int)$channel['id'] ?>"><button name="action" value="<?= $channel['status'] === 'active' ? 'pause_channel' : 'activate_channel' ?>" class="text-blue-600 hover:text-blue-800"><?= $channel['status'] === 'active' ? '暂停' : '启用' ?></button></form><form method="POST"><?= gd_csrf_input() ?><input type="hidden" name="action" value="health_check"><input type="hidden" name="channel_id" value="<?= (int)$channel['id'] ?>"><button class="text-green-600 hover:text-green-800">健康检查</button></form><form method="POST"><?= gd_csrf_input() ?><input type="hidden" name="action" value="sync_settings"><input type="hidden" name="channel_id" value="<?= (int)$channel['id'] ?>"><button class="text-indigo-600 hover:text-indigo-800">同步设置</button></form><form method="POST"><?= gd_csrf_input() ?><input type="hidden" name="action" value="rotate_secret"><input type="hidden" name="channel_id" value="<?= (int)$channel['id'] ?>"><button class="text-amber-600 hover:text-amber-800">轮换密钥</button></form></div></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
    <?php endif; ?>
</div>

<div class="mb-8 rounded-lg bg-white shadow">
    <div class="border-b border-gray-200 px-6 py-4"><h2 class="text-lg font-medium text-gray-900">手动加入队列</h2></div>
    <form method="POST" class="grid grid-cols-1 gap-4 px-6 py-5 md:grid-cols-3">
        <?= gd_csrf_input() ?><input type="hidden" name="action" value="enqueue_article">
        <div><label class="block text-sm font-medium text-gray-700">文章</label><select name="article_id" class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm"><option value="">选择已发布文章</option><?php foreach ($publishedArticles as $article): ?><option value="<?= (int)$article['id'] ?>"><?= gd_h(mb_substr((string)$article['title'], 0, 80)) ?></option><?php endforeach; ?></select></div>
        <div><label class="block text-sm font-medium text-gray-700">渠道</label><select name="channel_ids[]" multiple class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm"><?php foreach ($channels as $channel): ?><option value="<?= (int)$channel['id'] ?>"><?= gd_h($channel['name']) ?></option><?php endforeach; ?></select></div>
        <div class="flex items-end"><button class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">加入队列</button></div>
    </form>
</div>

<div class="rounded-lg bg-white shadow">
    <div class="border-b border-gray-200 px-6 py-4"><h2 class="text-lg font-medium text-gray-900">最近分发日志</h2></div>
    <?php if (empty($logs)): ?><div class="px-6 py-8 text-sm text-gray-500">暂无分发日志</div><?php else: ?><div class="divide-y divide-gray-200"><?php foreach ($logs as $log): ?><div class="px-6 py-4 text-sm"><div class="flex items-center justify-between gap-4"><div class="font-medium text-gray-900"><?= gd_h($log['message']) ?></div><div class="shrink-0 text-xs text-gray-500"><?= gd_h($log['created_at']) ?></div></div><div class="mt-1 flex flex-wrap gap-x-3 gap-y-1 text-gray-500"><span><?= gd_h($log['channel_name'] ?? '无渠道') ?></span><span><?= gd_h($log['level']) ?></span><span>文章：<?= gd_h($log['article_title'] ?? '无') ?></span></div></div><?php endforeach; ?></div><?php endif; ?>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
