<?php
/**
 * 智能GEO内容系统 - 媒体分发账号管理
 */

define('FEISHU_TREASURE', true);
session_start();

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/database_admin.php';
require_once __DIR__ . '/../includes/distribution_service.php';

require_admin_login();
ensure_distribution_schema($db);

$message = '';
$error   = '';

$platforms   = distribution_platforms();
$mediaTypes  = distribution_media_types();
$filterOpts  = distribution_filter_options();

// ─── POST actions ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'CSRF验证失败';
    } else {
        $action = $_POST['action'] ?? '';
        try {
            if ($action === 'save_account') {
                distribution_save_account($db, $_POST);
                $message = '媒体账号已保存';
                header('Location: media-accounts.php?saved=1');
                exit;
            } elseif ($action === 'toggle_status') {
                $id  = (int) ($_POST['account_id'] ?? 0);
                $cur = trim((string) ($_POST['current_status'] ?? 'active'));
                $new = $cur === 'active' ? 'inactive' : 'active';
                $db->prepare("UPDATE media_accounts SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?")
                   ->execute([$new, $id]);
                $message = '账号状态已更新';
                header('Location: media-accounts.php?saved=1');
                exit;
            } elseif ($action === 'delete_account') {
                $id = (int) ($_POST['account_id'] ?? 0);
                $db->prepare("DELETE FROM media_accounts WHERE id = ?")->execute([$id]);
                $message = '账号已删除';
                header('Location: media-accounts.php?saved=1');
                exit;
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

if (isset($_GET['saved'])) {
    $message = '操作成功';
}

// ─── edit mode ───────────────────────────────────────────────────────────────
$editId      = (int) ($_GET['edit'] ?? 0);
$editAccount = $editId > 0 ? distribution_get_account($db, $editId) : null;
$showForm    = $editAccount !== null || isset($_GET['new']);

// ─── filters ─────────────────────────────────────────────────────────────────
$filters = [];
foreach (['media_type', 'portal_source', 'industry', 'region', 'status'] as $f) {
    if (!empty($_GET[$f])) $filters[$f] = $_GET[$f];
}
if (!empty($_GET['geo_rank'])) $filters['geo_rank'] = '1';
if (!empty($_GET['q']))        $filters['search'] = $_GET['q'];

// status filter applied in PHP for simplicity (distribution_get_accounts supports it via custom key)
$allAccounts = distribution_get_accounts($db, array_diff_key($filters, ['status' => '']));
if (!empty($filters['status'])) {
    $allAccounts = array_filter($allAccounts, fn($a) => $a['status'] === $filters['status']);
}
$accounts = array_values($allAccounts);

$total  = count($accounts);
$active = count(array_filter($accounts, fn($a) => $a['status'] === 'active'));

$_maCurrentCustomer = $_SESSION['current_customer'] ?? null;
$page_title = '媒体账号管理';
$page_header = '
<div class="flex items-center justify-between">
    <div>
        <h1 class="text-2xl font-bold text-gray-900">媒体账号管理</h1>
        <p class="mt-1 text-sm text-gray-600">录入、编辑和维护可执行的媒体分发账号资源。</p>
    </div>
    <div class="flex gap-3">
        <a href="media-accounts.php?new=1" class="inline-flex items-center rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-blue-700">
            <i data-lucide="plus" class="mr-2 h-4 w-4"></i>新增账号
        </a>
        <a href="distribution.php" class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50">
            <i data-lucide="arrow-left" class="mr-2 h-4 w-4"></i>分发工作台
        </a>
    </div>
</div>
';

require_once __DIR__ . '/includes/header.php';

function ma_option_label(array $opts, string $key): string {
    return $opts[$key] ?? ($key === '' ? '—' : $key);
}
?>

<?php if ($message): ?>
    <div class="mb-5 rounded-md border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="mb-5 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>
<?php if ($_maCurrentCustomer): ?>
<div class="mb-5 flex items-center justify-between rounded-lg border border-slate-200 bg-slate-50 px-4 py-2.5 text-sm">
    <div class="flex items-center gap-3">
        <i data-lucide="building-2" class="h-4 w-4 text-slate-400"></i>
        <span class="font-semibold text-slate-800"><?= htmlspecialchars($_maCurrentCustomer['name'] ?? '') ?></span>
        <?php if (!empty($_maCurrentCustomer['industry'])): ?>
            <span class="rounded-full bg-slate-200 px-2 py-0.5 text-xs text-slate-600"><?= htmlspecialchars($_maCurrentCustomer['industry']) ?></span>
        <?php endif; ?>
        <?php if (!empty($filters)): ?>
            <span class="rounded-full bg-amber-100 px-2 py-0.5 text-xs text-amber-700">已应用 <?= count($filters) ?> 项筛选</span>
        <?php endif; ?>
    </div>
    <div class="flex items-center gap-3">
        <?php if (!empty($filters)): ?>
            <a href="media-accounts.php" class="text-xs text-blue-600 hover:underline">清除全部筛选</a>
        <?php endif; ?>
        <a href="customers.php" class="text-xs text-slate-500 hover:text-slate-700">切换客户 →</a>
    </div>
</div>
<?php else: ?>
<?php endif; ?>

<?php if ($showForm): ?>
<!-- ── Add / Edit form ───────────────────────────────────────────────────── -->
<div class="mb-8 rounded-lg bg-white shadow">
    <div class="flex items-center justify-between border-b border-gray-200 px-6 py-4">
        <h2 class="text-base font-semibold text-gray-900"><?= $editAccount ? '编辑账号' : '新增账号' ?></h2>
        <a href="media-accounts.php" class="text-sm text-gray-500 hover:text-gray-700">取消</a>
    </div>
    <form method="POST" class="p-6">
        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
        <input type="hidden" name="action" value="save_account">
        <input type="hidden" name="account_id" value="<?= (int) ($editAccount['id'] ?? 0) ?>">

        <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3">
            <!-- 平台 -->
            <div>
                <label class="block text-sm font-medium text-gray-700">平台 <span class="text-red-500">*</span></label>
                <select id="ma-platform-select" name="platform" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
                    <?php foreach ($platforms as $key => $p): ?>
                        <option value="<?= $key ?>"
                            data-media-type="<?= htmlspecialchars($p['media_type'] ?? 'self_media') ?>"
                            data-portal-source="<?= htmlspecialchars($p['portal_source'] ?? '') ?>"
                            <?= (($editAccount['platform'] ?? '') === $key) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($p['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- 账号名称 -->
            <div>
                <label class="block text-sm font-medium text-gray-700">账号名称 <span class="text-red-500">*</span></label>
                <input type="text" name="account_name" required
                    value="<?= htmlspecialchars($editAccount['account_name'] ?? '') ?>"
                    placeholder="例如：董逻辑MGEO · 知乎专栏"
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
            </div>

            <!-- 发布方式 -->
            <div>
                <label class="block text-sm font-medium text-gray-700">发布方式</label>
                <select name="publish_mode" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
                    <option value="browser" <?= (($editAccount['publish_mode'] ?? '') === 'browser') ? 'selected' : '' ?>>浏览器自动化</option>
                    <option value="api"     <?= (($editAccount['publish_mode'] ?? '') === 'api')     ? 'selected' : '' ?>>开放接口</option>
                    <option value="manual"  <?= (($editAccount['publish_mode'] ?? 'manual') === 'manual')  ? 'selected' : '' ?>>人工辅助</option>
                </select>
            </div>

            <!-- 登录名 -->
            <div>
                <label class="block text-sm font-medium text-gray-700">登录名 / 手机 / 邮箱</label>
                <input type="text" name="username"
                    value="<?= htmlspecialchars($editAccount['username'] ?? '') ?>"
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
            </div>

            <!-- 密码 -->
            <div>
                <label class="block text-sm font-medium text-gray-700">密码 / Token</label>
                <input type="password" name="password"
                    placeholder="<?= $editAccount ? '留空则不修改' : '' ?>"
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
                <p class="mt-1 text-xs text-gray-500">保存时加密，页面仅显示脱敏信息。</p>
            </div>

            <!-- 发布入口 -->
            <div>
                <label class="block text-sm font-medium text-gray-700">发布入口 URL</label>
                <input type="url" name="login_url"
                    value="<?= htmlspecialchars($editAccount['login_url'] ?? '') ?>"
                    placeholder="留空则使用平台默认"
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
            </div>

            <!-- 行业 -->
            <div>
                <label class="block text-sm font-medium text-gray-700">行业</label>
                <select name="industry" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
                    <?php foreach ($filterOpts['industry'] as $k => $v): ?>
                        <option value="<?= $k ?>" <?= (($editAccount['industry'] ?? '') === $k) ? 'selected' : '' ?>><?= htmlspecialchars($v) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- 地区 -->
            <div>
                <label class="block text-sm font-medium text-gray-700">地区</label>
                <select name="region" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
                    <?php foreach ($filterOpts['region'] as $k => $v): ?>
                        <option value="<?= $k ?>" <?= (($editAccount['region'] ?? '') === $k) ? 'selected' : '' ?>><?= htmlspecialchars($v) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- 报价 -->
            <div>
                <label class="block text-sm font-medium text-gray-700">单次报价（元）</label>
                <input type="number" name="price_amount" min="0" step="0.01"
                    value="<?= htmlspecialchars((string) ($editAccount['price_amount'] ?? '0')) ?>"
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
            </div>

            <!-- 入口级别 -->
            <div>
                <label class="block text-sm font-medium text-gray-700">入口级别</label>
                <select name="entry_level" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
                    <?php foreach ($filterOpts['entry_level'] as $k => $v): ?>
                        <option value="<?= $k ?>" <?= (($editAccount['entry_level'] ?? '') === $k) ? 'selected' : '' ?>><?= htmlspecialchars($v) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- 收录情况 -->
            <div>
                <label class="block text-sm font-medium text-gray-700">收录情况</label>
                <select name="index_status" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
                    <?php foreach ($filterOpts['index_status'] as $k => $v): ?>
                        <option value="<?= $k ?>" <?= (($editAccount['index_status'] ?? '') === $k) ? 'selected' : '' ?>><?= htmlspecialchars($v) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- 发稿速度 -->
            <div>
                <label class="block text-sm font-medium text-gray-700">发稿速度</label>
                <select name="publish_speed" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
                    <?php foreach ($filterOpts['publish_speed'] as $k => $v): ?>
                        <option value="<?= $k ?>" <?= (($editAccount['publish_speed'] ?? '') === $k) ? 'selected' : '' ?>><?= htmlspecialchars($v) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <!-- GEO排名 + 状态 -->
        <div class="mt-5 flex flex-wrap items-center gap-6">
            <label class="flex cursor-pointer items-center gap-2 rounded-md border border-gray-200 px-4 py-2.5 text-sm text-gray-700 hover:bg-gray-50">
                <input type="checkbox" name="can_geo_rank" value="1"
                    <?= !empty($editAccount['can_geo_rank']) ? 'checked' : '' ?>
                    class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                <span>支持 GEO 排名 / AI搜索曝光类发布</span>
            </label>

            <div class="flex items-center gap-3">
                <span class="text-sm font-medium text-gray-700">状态</span>
                <select name="status" class="rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
                    <option value="active"   <?= (($editAccount['status'] ?? 'active') === 'active')   ? 'selected' : '' ?>>启用</option>
                    <option value="inactive" <?= (($editAccount['status'] ?? '') === 'inactive') ? 'selected' : '' ?>>停用</option>
                </select>
            </div>
        </div>

        <!-- 备注 -->
        <div class="mt-5">
            <label class="block text-sm font-medium text-gray-700">备注</label>
            <textarea name="notes" rows="2"
                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"><?= htmlspecialchars($editAccount['notes'] ?? '') ?></textarea>
        </div>

        <div class="mt-6 flex gap-3">
            <button type="submit" class="inline-flex items-center rounded-md bg-blue-600 px-5 py-2 text-sm font-semibold text-white shadow-sm hover:bg-blue-700">
                <i data-lucide="save" class="mr-2 h-4 w-4"></i>
                <?= $editAccount ? '保存修改' : '保存账号' ?>
            </button>
            <a href="media-accounts.php" class="inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">取消</a>
        </div>
    </form>
</div>
<?php endif; ?>

<!-- ── Stats bar ─────────────────────────────────────────────────────────── -->
<div class="mb-5 flex flex-wrap items-center gap-4">
    <div class="flex items-center gap-1.5 rounded-md border border-gray-200 bg-white px-3 py-2 text-sm text-gray-600">
        <i data-lucide="database" class="h-4 w-4 text-gray-400"></i>
        共 <strong class="text-gray-900"><?= $total ?></strong> 个账号，启用 <strong class="text-green-700"><?= $active ?></strong> 个
    </div>
    <?php if (!empty($filters)): ?>
        <a href="media-accounts.php" class="text-sm text-blue-600 hover:underline">清除筛选</a>
    <?php endif; ?>
</div>

<!-- ── Filter bar ─────────────────────────────────────────────────────────── -->
<form method="GET" class="mb-5 flex flex-wrap items-end gap-3">
    <div class="flex-1 min-w-[160px]">
        <input type="text" name="q" value="<?= htmlspecialchars($_GET['q'] ?? '') ?>"
            placeholder="搜索账号名称 / 登录名"
            class="block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
    </div>
    <div>
        <select name="status" class="rounded-md border-gray-300 shadow-sm sm:text-sm">
            <option value="">全部状态</option>
            <option value="active"   <?= ($_GET['status'] ?? '') === 'active'   ? 'selected' : '' ?>>启用</option>
            <option value="inactive" <?= ($_GET['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>停用</option>
        </select>
    </div>
    <div>
        <select name="media_type" class="rounded-md border-gray-300 shadow-sm sm:text-sm">
            <option value="">全部类型</option>
            <?php foreach ($mediaTypes as $k => $v): if ($k === '') continue; ?>
                <option value="<?= $k ?>" <?= ($_GET['media_type'] ?? '') === $k ? 'selected' : '' ?>><?= htmlspecialchars($v) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div>
        <label class="flex items-center gap-1.5 text-sm text-gray-600 cursor-pointer">
            <input type="checkbox" name="geo_rank" value="1" <?= !empty($_GET['geo_rank']) ? 'checked' : '' ?> class="rounded border-gray-300 text-blue-600">
            仅GEO账号
        </label>
    </div>
    <button type="submit" class="inline-flex items-center rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
        <i data-lucide="search" class="mr-1.5 h-4 w-4 text-gray-400"></i>筛选
    </button>
</form>

<!-- ── Account table ─────────────────────────────────────────────────────── -->
<div class="overflow-hidden rounded-lg bg-white shadow">
    <table class="min-w-full divide-y divide-gray-200">
        <thead class="bg-gray-50">
            <tr>
                <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500 w-40">平台</th>
                <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">账号名称</th>
                <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500 hidden sm:table-cell">发布方式</th>
                <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500 hidden md:table-cell">报价</th>
                <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">标签</th>
                <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">状态</th>
                <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wide text-gray-500">操作</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-200 bg-white">
        <?php if (empty($accounts)): ?>
            <tr>
                <td colspan="7" class="px-6 py-10 text-center text-sm text-gray-500">
                    <?php if (!empty($filters)): ?>
                        没有符合条件的账号。<a href="media-accounts.php" class="text-blue-600 hover:underline">清除筛选</a>
                    <?php else: ?>
                        还没有媒体账号。<a href="media-accounts.php?new=1" class="text-blue-600 hover:underline">立即新增</a>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endif; ?>
        <?php foreach ($accounts as $acc):
            $plat = $platforms[$acc['platform']] ?? ['name' => $acc['platform'], 'icon' => 'globe'];
            $modeLabel = ['browser' => '自动化', 'api' => 'API', 'manual' => '人工'][$acc['publish_mode'] ?? ''] ?? $acc['publish_mode'] ?? '—';
        ?>
            <tr class="hover:bg-gray-50 <?= $acc['status'] !== 'active' ? 'opacity-60' : '' ?>">
                <!-- 平台 -->
                <td class="px-4 py-3">
                    <div class="flex items-center gap-1.5 text-sm font-medium text-gray-800">
                        <i data-lucide="<?= $plat['icon'] ?>" class="h-4 w-4 shrink-0 text-gray-400"></i>
                        <?= htmlspecialchars($plat['name']) ?>
                    </div>
                </td>
                <!-- 账号名称 + 登录名 -->
                <td class="px-4 py-3">
                    <div class="text-sm font-medium text-gray-900"><?= htmlspecialchars($acc['account_name']) ?></div>
                    <?php if (!empty($acc['username'])): ?>
                        <div class="mt-0.5 text-xs text-gray-400"><?= htmlspecialchars(distribution_mask_secret($acc['username'])) ?></div>
                    <?php endif; ?>
                </td>
                <!-- 发布方式 -->
                <td class="px-4 py-3 hidden sm:table-cell">
                    <span class="rounded bg-gray-100 px-2 py-0.5 text-xs text-gray-600"><?= htmlspecialchars($modeLabel) ?></span>
                </td>
                <!-- 报价 -->
                <td class="px-4 py-3 hidden md:table-cell text-sm text-gray-700">
                    <?= (float) ($acc['price_amount'] ?? 0) > 0 ? '￥' . number_format((float) $acc['price_amount'], 0) : '—' ?>
                </td>
                <!-- 标签 -->
                <td class="px-4 py-3">
                    <div class="flex flex-wrap gap-1">
                        <?php if (!empty($acc['can_geo_rank'])): ?>
                            <span class="rounded bg-red-50 px-1.5 py-0.5 text-xs font-medium text-red-600">GEO</span>
                        <?php endif; ?>
                        <?php if (!empty($acc['media_type']) && isset($mediaTypes[$acc['media_type']])): ?>
                            <span class="rounded bg-blue-50 px-1.5 py-0.5 text-xs text-blue-700"><?= htmlspecialchars($mediaTypes[$acc['media_type']]) ?></span>
                        <?php endif; ?>
                        <?php if (!empty($acc['industry'])): ?>
                            <span class="rounded bg-gray-100 px-1.5 py-0.5 text-xs text-gray-500"><?= htmlspecialchars(ma_option_label($filterOpts['industry'], $acc['industry'])) ?></span>
                        <?php endif; ?>
                    </div>
                </td>
                <!-- 状态 -->
                <td class="px-4 py-3">
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                        <input type="hidden" name="action" value="toggle_status">
                        <input type="hidden" name="account_id" value="<?= $acc['id'] ?>">
                        <input type="hidden" name="current_status" value="<?= htmlspecialchars($acc['status']) ?>">
                        <button type="submit" class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold <?= $acc['status'] === 'active' ? 'bg-green-100 text-green-800 hover:bg-green-200' : 'bg-gray-100 text-gray-600 hover:bg-gray-200' ?>">
                            <?= $acc['status'] === 'active' ? '启用' : '停用' ?>
                        </button>
                    </form>
                </td>
                <!-- 操作 -->
                <td class="px-4 py-3 text-right">
                    <div class="flex items-center justify-end gap-3">
                        <a href="media-accounts.php?edit=<?= $acc['id'] ?>"
                           class="text-sm font-medium text-blue-600 hover:text-blue-800">编辑</a>
                        <form method="POST" onsubmit="return confirm('确定删除"<?= htmlspecialchars(addslashes($acc['account_name'])) ?>"？关联发布任务也会删除。')">
                            <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                            <input type="hidden" name="action" value="delete_account">
                            <input type="hidden" name="account_id" value="<?= $acc['id'] ?>">
                            <button type="submit" class="text-sm font-medium text-red-500 hover:text-red-700">删除</button>
                        </form>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php if (!$showForm && $total > 0): ?>
<div class="mt-4 text-right">
    <a href="media-accounts.php?new=1" class="inline-flex items-center text-sm text-blue-600 hover:underline">
        <i data-lucide="plus" class="mr-1 h-4 w-4"></i>再新增一个账号
    </a>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
