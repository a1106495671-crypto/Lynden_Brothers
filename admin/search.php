<?php
/**
 * 智能GEO内容系统 - 全局搜索
 */

define('FEISHU_TREASURE', true);
session_start();

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/database_admin.php';
require_once __DIR__ . '/../includes/distribution_service.php';

require_admin_login();
session_write_close();

$q = trim((string) ($_GET['q'] ?? ''));

// ─── 搜索结果 ─────────────────────────────────────────────────────────────────
$results = ['customers' => [], 'tasks' => [], 'accounts' => []];

if ($q !== '') {
    $like = '%' . $q . '%';

    // 1. 客户（hardcoded mock，与 customers.php 保持一致）
    $all_customers = [
        ['id' => 'cust_001', 'name' => '灵犀互娱', 'industry' => 'B2B SaaS / 企业服务', 'service_status' => 'active', 'stage_label' => '执行期'],
        ['id' => 'cust_002', 'name' => '云帆科技', 'industry' => 'B2B SaaS / 企业服务', 'service_status' => 'active', 'stage_label' => '起量期'],
        ['id' => 'cust_003', 'name' => '元气森林', 'industry' => '健康消费', 'service_status' => 'active', 'stage_label' => '执行期'],
        ['id' => 'cust_004', 'name' => '春风教育', 'industry' => '教育 K12', 'service_status' => 'paused', 'stage_label' => '暂缓'],
    ];
    foreach ($all_customers as $c) {
        if (mb_stripos($c['name'], $q) !== false || mb_stripos($c['industry'], $q) !== false) {
            $results['customers'][] = $c;
        }
    }

    // 2. 任务（sop_dispatched_tasks）
    try {
        $stmt = $db->prepare("
            SELECT * FROM sop_dispatched_tasks
            WHERE name LIKE ? OR kpi LIKE ? OR deliverable LIKE ? OR sop_code LIKE ? OR customer_id LIKE ?
            ORDER BY created_at DESC
            LIMIT 30
        ");
        $stmt->execute([$like, $like, $like, $like, $like]);
        $results['tasks'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $results['tasks'] = [];
    }

    // 3. 媒体账号
    ensure_distribution_schema($db);
    $results['accounts'] = distribution_get_accounts($db, ['search' => $q]);
    $results['accounts'] = array_slice($results['accounts'], 0, 30);
}

$total = count($results['customers']) + count($results['tasks']) + count($results['accounts']);

$page_title = $q !== '' ? '搜索 "' . $q . '"' : '全局搜索';
$page_header = '
<div class="flex items-center justify-between">
    <div>
        <h1 class="text-2xl font-bold text-gray-900">全局搜索</h1>
        ' . ($q !== '' ? '<p class="mt-1 text-sm text-gray-500">关键词：<strong>' . htmlspecialchars($q) . '</strong>，共找到 ' . $total . ' 条结果</p>' : '') . '
    </div>
</div>
';

require_once __DIR__ . '/includes/header.php';

function sh(string $v): string { return htmlspecialchars($v); }

$statusLabels = ['pending' => '待处理', 'in_progress' => '执行中', 'done' => '已完成'];
$platforms = distribution_platforms();
?>

<!-- ── 搜索框 ──────────────────────────────────────────────────────────────── -->
<form method="GET" class="mb-8">
    <div class="flex gap-3">
        <div class="relative flex-1">
            <i data-lucide="search" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400"></i>
            <input type="text" name="q" value="<?= sh($q) ?>"
                placeholder="搜索客户名称、任务名称、媒体账号…"
                autofocus
                class="block w-full rounded-lg border border-gray-300 bg-white py-2.5 pl-10 pr-4 text-sm text-gray-900 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500">
        </div>
        <button type="submit"
            class="inline-flex items-center rounded-lg bg-blue-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-blue-700">
            搜索
        </button>
    </div>
</form>

<?php if ($q === ''): ?>
<div class="rounded-lg border border-gray-200 bg-white p-10 text-center text-gray-400 shadow-sm">
    <i data-lucide="search" class="mx-auto mb-3 h-10 w-10 text-gray-300"></i>
    <p class="text-sm">输入关键词搜索客户、派发任务或媒体账号</p>
</div>

<?php elseif ($total === 0): ?>
<div class="rounded-lg border border-gray-200 bg-white p-10 text-center text-gray-400 shadow-sm">
    <i data-lucide="search-x" class="mx-auto mb-3 h-10 w-10 text-gray-300"></i>
    <p class="text-sm font-semibold text-gray-500">未找到与"<?= sh($q) ?>"相关的结果</p>
    <p class="mt-1 text-xs text-gray-400">尝试使用不同关键词，或检查拼写</p>
</div>

<?php else: ?>
<div class="space-y-8">

    <?php if (!empty($results['customers'])): ?>
    <!-- ── 客户 ──────────────────────────────────────────────────────────── -->
    <section>
        <div class="mb-3 flex items-center gap-2">
            <i data-lucide="building-2" class="h-4 w-4 text-blue-600"></i>
            <h2 class="text-sm font-semibold text-gray-700">客户</h2>
            <span class="rounded-full bg-blue-50 px-2 py-0.5 text-xs font-semibold text-blue-700"><?= count($results['customers']) ?></span>
        </div>
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
            <?php foreach ($results['customers'] as $c): ?>
            <a href="<?= sh(admin_url('customers.php?select=' . rawurlencode($c['id']))) ?>"
               class="flex items-start gap-3 rounded-lg border border-gray-200 bg-white p-4 shadow-sm transition hover:border-blue-300 hover:shadow-md">
                <div class="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-blue-100">
                    <i data-lucide="building-2" class="h-4 w-4 text-blue-600"></i>
                </div>
                <div class="min-w-0">
                    <div class="truncate font-semibold text-gray-900"><?= sh($c['name']) ?></div>
                    <div class="mt-0.5 text-xs text-gray-500"><?= sh($c['industry']) ?></div>
                    <div class="mt-1 flex gap-1.5">
                        <span class="rounded-full <?= $c['service_status'] === 'active' ? 'bg-green-50 text-green-700' : 'bg-amber-50 text-amber-700' ?> px-2 py-0.5 text-[11px] font-semibold">
                            <?= $c['service_status'] === 'active' ? '服务中' : '暂缓' ?>
                        </span>
                        <span class="rounded-full bg-gray-100 px-2 py-0.5 text-[11px] text-gray-600"><?= sh($c['stage_label']) ?></span>
                    </div>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

    <?php if (!empty($results['tasks'])): ?>
    <!-- ── 派发任务 ───────────────────────────────────────────────────────── -->
    <section>
        <div class="mb-3 flex items-center gap-2">
            <i data-lucide="clipboard-check" class="h-4 w-4 text-purple-600"></i>
            <h2 class="text-sm font-semibold text-gray-700">派发任务</h2>
            <span class="rounded-full bg-purple-50 px-2 py-0.5 text-xs font-semibold text-purple-700"><?= count($results['tasks']) ?></span>
        </div>
        <div class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500">任务名称</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500">SOP</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500">客户</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500">状态</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500">截止</th>
                        <th class="w-16 px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php foreach ($results['tasks'] as $t):
                        $statusLabel = $statusLabels[$t['status']] ?? $t['status'];
                        $statusClass = match ($t['status']) {
                            'done'        => 'bg-green-50 text-green-700',
                            'in_progress' => 'bg-blue-50 text-blue-700',
                            default       => 'bg-gray-100 text-gray-600',
                        };
                        $overdue = !empty($t['due_date']) && $t['status'] !== 'done' && $t['due_date'] < date('Y-m-d');
                    ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3">
                            <div class="font-semibold text-gray-900"><?= sh($t['name']) ?></div>
                            <?php if (!empty($t['kpi'])): ?>
                                <div class="mt-0.5 text-xs text-gray-500 truncate max-w-[200px]"><?= sh($t['kpi']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3 text-xs text-gray-500"><?= sh($t['sop_code'] ?? '') ?></td>
                        <td class="px-4 py-3 text-xs text-gray-600"><?= sh($t['customer_id'] ?? '') ?></td>
                        <td class="px-4 py-3">
                            <span class="rounded-full px-2 py-0.5 text-xs font-semibold <?= $statusClass ?>"><?= sh($statusLabel) ?></span>
                        </td>
                        <td class="px-4 py-3 text-xs <?= $overdue ? 'font-semibold text-red-600' : 'text-gray-500' ?>">
                            <?= sh((string) ($t['due_date'] ?? '—')) ?>
                            <?php if ($overdue): ?><span class="ml-1">逾期</span><?php endif; ?>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <a href="<?= sh(admin_url('tasks.php?task_type=human')) ?>"
                               class="text-xs text-blue-600 hover:underline">查看</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
    <?php endif; ?>

    <?php if (!empty($results['accounts'])): ?>
    <!-- ── 媒体账号 ───────────────────────────────────────────────────────── -->
    <section>
        <div class="mb-3 flex items-center gap-2">
            <i data-lucide="radio" class="h-4 w-4 text-emerald-600"></i>
            <h2 class="text-sm font-semibold text-gray-700">媒体账号</h2>
            <span class="rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-semibold text-emerald-700"><?= count($results['accounts']) ?></span>
        </div>
        <div class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500">账号名称</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500">平台</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500">行业</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500">状态</th>
                        <th class="w-16 px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php foreach ($results['accounts'] as $a):
                        $platLabel = $platforms[$a['platform'] ?? ''] ?? ($a['platform'] ?? '—');
                        $isActive = ($a['status'] ?? '') === 'active';
                    ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3 font-semibold text-gray-900"><?= sh($a['account_name'] ?? '') ?></td>
                        <td class="px-4 py-3 text-xs text-gray-600"><?= sh($platLabel) ?></td>
                        <td class="px-4 py-3 text-xs text-gray-500"><?= sh($a['industry'] ?? '—') ?></td>
                        <td class="px-4 py-3">
                            <span class="rounded-full px-2 py-0.5 text-xs font-semibold <?= $isActive ? 'bg-green-50 text-green-700' : 'bg-gray-100 text-gray-500' ?>">
                                <?= $isActive ? '启用' : '停用' ?>
                            </span>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <a href="<?= sh(admin_url('media-accounts.php?edit=' . (int)($a['id'] ?? 0))) ?>"
                               class="text-xs text-blue-600 hover:underline">编辑</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
    <?php endif; ?>

</div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
