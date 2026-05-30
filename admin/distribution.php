<?php
/**
 * 智能GEO内容系统 - 媒体分发
 */

define('FEISHU_TREASURE', true);
session_start();

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/database_admin.php';
require_once __DIR__ . '/../includes/distribution_service.php';
require_once __DIR__ . '/../includes/distribution_publisher_service.php';

require_admin_login();
ensure_distribution_schema($db);

$message = '';
$error = '';
$platforms = distribution_platforms();
$mediaTypes = distribution_media_types();
$filterOptions = distribution_filter_options();
$selectedArticleId = (int) ($_GET['article_id'] ?? 0);
$editAccountId = (int) ($_GET['edit_account_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'CSRF验证失败';
    } else {
        $action = $_POST['action'] ?? '';

        try {
            if ($action === 'save_account') {
                distribution_save_account($db, $_POST);
                $message = '媒体账号已保存';
            } elseif ($action === 'delete_account') {
                $accountId = (int) ($_POST['account_id'] ?? 0);
                $stmt = $db->prepare("DELETE FROM media_accounts WHERE id = ?");
                $stmt->execute([$accountId]);
                $message = '媒体账号已删除';
            } elseif ($action === 'enqueue_article') {
                $articleId = (int) ($_POST['article_id'] ?? 0);
                $accountIds = $_POST['account_ids'] ?? [];
                $created = distribution_enqueue_article($db, $articleId, $accountIds);
                $message = "已创建 {$created} 条媒体发布任务";
                $selectedArticleId = $articleId;
            } elseif ($action === 'run_job') {
                $jobId = (int) ($_POST['job_id'] ?? 0);
                $result = distribution_execute_publish_job($db, $jobId);
                if (($result['status'] ?? '') === 'success') {
                    $remoteUrl = trim((string) ($result['remote_url'] ?? ''));
                    $message = $remoteUrl !== ''
                        ? '发布成功：已自动执行并返回链接'
                        : '发布成功：已自动执行';
                    // 发布成功：自动把文章关键词注入监测队列
                    $jobInfoStmt = $db->prepare("SELECT article_id FROM media_publish_jobs WHERE id = ?");
                    $jobInfoStmt->execute([$jobId]);
                    $jobArticleId = (int) ($jobInfoStmt->fetchColumn() ?: 0);
                    $sessionCid   = (string) ($_SESSION['current_customer']['id'] ?? '');
                    if ($jobArticleId > 0 && $sessionCid !== '') {
                        $injected = geo_monitor_inject_article_keywords($db, $jobArticleId, $sessionCid, $remoteUrl);
                        if ($injected > 0) {
                            $message .= "，已自动添加 {$injected} 个关键词到监测队列";
                        }
                        // 标记对应 P0 意图问题为已覆盖
                        try {
                            $kwStmt = $db->prepare("SELECT original_keyword FROM articles WHERE id = ?");
                            $kwStmt->execute([$jobArticleId]);
                            $artKw = (string)($kwStmt->fetchColumn() ?: '');
                            if ($artKw !== '') {
                                $db->prepare("UPDATE geo_intent_questions SET covered = true WHERE customer_id = ? AND question = ? AND priority = 'P0' AND covered = false")
                                   ->execute([$sessionCid, $artKw]);
                            }
                        } catch (Throwable $e) {}
                        $message .= ' · <a href="geo-article-impact.php?article_id=' . $jobArticleId . '" class="underline font-medium">查看引用效果</a>';
                    }
                } elseif (($result['status'] ?? '') === 'skipped') {
                    $message = trim((string) ($result['error_message'] ?? '任务已跳过'));
                } else {
                    $error = trim((string) ($result['error_message'] ?? '自动发布失败'));
                }
            } elseif ($action === 'run_queued_jobs') {
                $count = distribution_start_queued_jobs_async($db, 5);
                $message = "已开始后台执行 {$count} 条待发布任务，稍后刷新查看结果";
            } elseif ($action === 'mark_job') {
                $jobId = (int) ($_POST['job_id'] ?? 0);
                $status = $_POST['status'] ?? 'queued';
                if (!in_array($status, ['queued', 'running', 'success', 'failed'], true)) {
                    $status = 'queued';
                }
                distribution_job_update($db, $jobId, $status);
                $message = '发布任务状态已手动更新';
                // 手动标记成功时同样注入关键词 + 标记意图覆盖
                if ($status === 'success') {
                    $jobInfoStmt = $db->prepare("SELECT article_id, remote_url FROM media_publish_jobs WHERE id = ?");
                    $jobInfoStmt->execute([$jobId]);
                    $jobInfo      = $jobInfoStmt->fetch(PDO::FETCH_ASSOC) ?: [];
                    $jobArticleId = (int) ($jobInfo['article_id'] ?? 0);
                    $sessionCid   = (string) ($_SESSION['current_customer']['id'] ?? '');
                    if ($jobArticleId > 0 && $sessionCid !== '') {
                        $injected = geo_monitor_inject_article_keywords($db, $jobArticleId, $sessionCid, $jobInfo['remote_url'] ?? '');
                        if ($injected > 0) {
                            $message .= "，已自动添加 {$injected} 个关键词到监测队列";
                        }
                        // 标记对应 P0 意图问题为已覆盖
                        try {
                            $kwStmt = $db->prepare("SELECT original_keyword FROM articles WHERE id = ?");
                            $kwStmt->execute([$jobArticleId]);
                            $artKw = (string)($kwStmt->fetchColumn() ?: '');
                            if ($artKw !== '') {
                                $db->prepare("UPDATE geo_intent_questions SET covered = true WHERE customer_id = ? AND question = ? AND priority = 'P0' AND covered = false")
                                   ->execute([$sessionCid, $artKw]);
                            }
                        } catch (Throwable $e) {}
                        $message .= ' · <a href="geo-article-impact.php?article_id=' . $jobArticleId . '" class="underline font-medium">查看引用效果</a>';
                    }
                }
            } elseif ($action === 'save_geo_material') {
                distribution_save_geo_material($db, $_POST);
                $message = 'GEO 素材已保存';
            } elseif ($action === 'delete_geo_material') {
                distribution_delete_geo_material($db, (int) ($_POST['material_id'] ?? 0));
                $message = 'GEO 素材已删除';
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$accounts = distribution_get_accounts($db);
$jobs = distribution_get_recent_jobs($db, 80);
$stats = distribution_get_stats($db);
$editAccount = $editAccountId > 0 ? distribution_get_account($db, $editAccountId) : null;
$hasRunningDistributionJob = false;
foreach ($jobs as $job) {
    if (($job['status'] ?? '') === 'running') {
        $hasRunningDistributionJob = true;
        break;
    }
}

$selectedArticle = null;
if ($selectedArticleId > 0) {
    $stmt = $db->prepare("SELECT id, title, excerpt, status, review_status, published_at FROM articles WHERE id = ? AND deleted_at IS NULL");
    $stmt->execute([$selectedArticleId]);
    $selectedArticle = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

$geoMaterialTypes = distribution_geo_material_types();
$currentCustomerId = (string) ($_SESSION['current_customer']['id'] ?? '');

// 找出当前客户的 P0 关键词，用于文章列表打标
$_p0Keywords = [];
if ($currentCustomerId !== '') {
    try {
        $p0Stmt = $db->prepare("SELECT question FROM geo_intent_questions WHERE customer_id = ? AND priority = 'P0' AND covered = false");
        $p0Stmt->execute([$currentCustomerId]);
        $_p0Keywords = array_flip($p0Stmt->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $e) {}
}

$recentArticles = $db->query("
    SELECT id, title, status, review_status, created_at, original_keyword
    FROM articles
    WHERE deleted_at IS NULL
    ORDER BY created_at DESC
    LIMIT 30
")->fetchAll(PDO::FETCH_ASSOC);

// 读取行业偏好配置（由 ai-citation-preferences.php 一键应用写入）
$_industryPref = [];
if ($currentCustomerId !== '') {
    $raw = get_setting('geo_pref_industry_' . $currentCustomerId, '');
    if ($raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) $_industryPref = $decoded;
    }
}

$geoMaterials = distribution_get_geo_materials($db, '', $currentCustomerId);
$geoMaterialsByType = [];
foreach ($geoMaterials as $m) {
    $geoMaterialsByType[$m['material_type']][] = $m;
}

$activeGeoTab = $_GET['geo_tab'] ?? 'brand_entity_card';
if (!isset($geoMaterialTypes[$activeGeoTab])) {
    $activeGeoTab = 'brand_entity_card';
}

$page_title = '媒体分发';
$page_header = '
<div class="flex items-center justify-between">
    <div>
        <h1 class="text-2xl font-bold text-gray-900">媒体分发</h1>
        <p class="mt-1 text-sm text-gray-600">按客户行业和 AI 偏好确定分发信源，再把可执行资源加入发布队列并追踪结果。</p>
    </div>
    <div class="flex space-x-3">
        <a href="media-accounts.php" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
            <i data-lucide="settings" class="w-4 h-4 mr-2"></i>
            账号管理
        </a>
        <a href="articles.php" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
            <i data-lucide="file-text" class="w-4 h-4 mr-2"></i>
            返回文章
        </a>
    </div>
</div>
';

require_once __DIR__ . '/includes/header.php';

function distribution_option_label(array $options, string $key): string {
    return $options[$key] ?? ($key === '' ? '不限' : $key);
}
?>
<?php if (!empty($_industryPref['industry'])): ?>
<div class="mb-5 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-blue-200 bg-blue-50 px-5 py-3 text-sm">
    <div class="flex flex-wrap items-center gap-3">
        <i data-lucide="zap" class="h-4 w-4 text-blue-600"></i>
        <span class="font-semibold text-blue-900">行业偏好已配置：<?= htmlspecialchars($_industryPref['industry']) ?></span>
        <?php if (!empty($_industryPref['ais'])): ?>
        <span class="text-blue-700">优先 AI：<?= htmlspecialchars(implode(' / ', array_map(fn($a) => ['doubao'=>'豆包','kimi'=>'Kimi','deepseek'=>'DeepSeek','tongyi'=>'通义','wenxin'=>'文心','yuanbao'=>'元宝'][$a] ?? $a, $_industryPref['ais']))) ?></span>
        <?php endif; ?>
        <?php if (!empty($_industryPref['platforms'])): ?>
        <span class="text-blue-600">优选平台：<?= htmlspecialchars(implode('、', $_industryPref['platforms'])) ?></span>
        <?php endif; ?>
    </div>
    <a href="<?= htmlspecialchars(admin_url('ai-citation-preferences.php')) ?>" class="text-xs text-blue-600 hover:underline">修改偏好</a>
</div>
<?php endif; ?>

<?php if ($message): ?>
    <div class="mb-6 rounded-md border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800"><?php echo $message; ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="mb-6 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<div class="grid grid-cols-1 gap-6 md:grid-cols-5 mb-8">
    <?php
    $cards = [
        ['label' => '账号总数', 'value' => $stats['accounts'], 'icon' => 'key-round', 'color' => 'text-blue-600'],
        ['label' => '启用账号', 'value' => $stats['active_accounts'], 'icon' => 'check-circle', 'color' => 'text-green-600'],
        ['label' => '待发布', 'value' => $stats['queued'], 'icon' => 'clock', 'color' => 'text-amber-600'],
        ['label' => '已发布', 'value' => $stats['success'], 'icon' => 'send', 'color' => 'text-emerald-600'],
        ['label' => '失败任务', 'value' => $stats['failed'], 'icon' => 'alert-circle', 'color' => 'text-red-600'],
    ];
    ?>
    <?php foreach ($cards as $card): ?>
        <div class="bg-white overflow-hidden shadow rounded-lg">
            <div class="p-5 flex items-center">
                <i data-lucide="<?php echo $card['icon']; ?>" class="h-6 w-6 <?php echo $card['color']; ?>"></i>
                <dl class="ml-5">
                    <dt class="text-sm font-medium text-gray-500"><?php echo $card['label']; ?></dt>
                    <dd class="text-lg font-medium text-gray-900"><?php echo $card['value']; ?></dd>
                </dl>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
    <div class="bg-white shadow rounded-lg lg:col-span-2">
        <div class="px-6 py-4 border-b border-gray-200">
            <div class="flex items-center justify-between">
                <h3 class="text-lg font-medium text-gray-900">选择媒体资源</h3>
                <?php if ($selectedArticle): ?>
                    <a href="article-edit.php?id=<?php echo $selectedArticle['id']; ?>" class="inline-flex items-center px-3 py-1.5 rounded-md border border-gray-300 bg-white text-xs font-medium text-gray-700 hover:bg-gray-50">
                        <i data-lucide="edit" class="w-4 h-4 mr-1"></i>
                        编辑稿件
                    </a>
                <?php endif; ?>
            </div>
        </div>
        <form method="POST" class="p-6 space-y-5">
            <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
            <input type="hidden" name="action" value="enqueue_article">

            <div>
                <label class="block text-sm font-medium text-gray-700">选择文章</label>
                <select name="article_id" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
                    <?php if ($selectedArticle): ?>
                        <option value="<?php echo $selectedArticle['id']; ?>" selected>#<?php echo $selectedArticle['id']; ?> <?php echo htmlspecialchars($selectedArticle['title']); ?></option>
                    <?php endif; ?>
                    <?php foreach ($recentArticles as $article): ?>
                        <?php if ($selectedArticle && (int) $selectedArticle['id'] === (int) $article['id']) continue; ?>
                        <?php $isP0Art = isset($_p0Keywords[$article['original_keyword'] ?? '']) && ($article['original_keyword'] ?? '') !== ''; ?>
                        <option value="<?php echo $article['id']; ?>"><?php echo $isP0Art ? '[P0] ' : ''; ?>#<?php echo $article['id']; ?> <?php echo htmlspecialchars($article['title']); ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if ($selectedArticle): ?>
                    <p class="mt-2 text-sm text-gray-500"><?php echo htmlspecialchars(mb_substr($selectedArticle['excerpt'] ?? '', 0, 120)); ?></p>
                <?php endif; ?>
            </div>

            <div class="rounded-lg border border-blue-100 bg-blue-50 p-5">
                <div class="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
                    <div>
                        <label class="block text-sm font-semibold text-blue-900">2. 选择客户行业并生成分发权重</label>
                        <p class="mt-2 text-sm text-blue-800">先按客户行业确定 AI 偏好和信源占比，再保留通用重媒体，最后从价格表里补充可执行资源。</p>
                    </div>
                    <select class="distribution-industry-select rounded-md border border-blue-200 bg-white px-3 py-2 text-sm font-semibold text-blue-900 shadow-sm">
                        <option>B2B SaaS / 企业服务</option>
                        <option>医疗 / 健康</option>
                        <option>消费品 / 美妆 / 食品</option>
                        <option>教育 / 知识付费</option>
                        <option>金融 / 投资</option>
                        <option>本地生活 / 餐饮</option>
                    </select>
                </div>
                <div class="mt-5 grid gap-4 md:grid-cols-3">
                    <div class="rounded-lg bg-white p-4">
                        <div class="text-xs font-semibold text-gray-500">AI 优先级</div>
                        <div id="distribution-ai-priority" class="mt-3 flex flex-wrap gap-2"></div>
                        <p id="distribution-ai-note" class="mt-3 text-sm text-gray-600"></p>
                    </div>
                    <div class="rounded-lg bg-white p-4">
                        <div class="text-xs font-semibold text-gray-500">资源占比</div>
                        <div id="distribution-mix-rows" class="mt-3 space-y-2 text-sm"></div>
                    </div>
                    <div class="rounded-lg bg-white p-4">
                        <div class="text-xs font-semibold text-gray-500">保留原则</div>
                        <p id="distribution-principle-text" class="mt-3 text-sm text-gray-600"></p>
                    </div>
                </div>
            </div>

            <div>
                <div class="flex items-center justify-between mb-3">
                    <label class="block text-sm font-medium text-gray-700">3. 按行业权重推荐分发信源</label>
                    <div class="flex flex-wrap gap-2 text-xs">
                        <span class="rounded-full bg-blue-50 px-3 py-1 font-medium text-blue-700">通用重媒体</span>
                        <span class="rounded-full bg-emerald-50 px-3 py-1 font-medium text-emerald-700">行业权威</span>
                        <span class="rounded-full bg-gray-100 px-3 py-1 font-medium text-gray-600">价格表补充</span>
                    </div>
                </div>
                <div class="mb-4 rounded-lg border border-gray-200 bg-white p-4 text-sm text-gray-600">
                    <span class="font-semibold text-gray-900">资源来源分三层：</span>
                    通用重媒体必须保留；行业权威信源按行业白名单保留；news.growume.com 价格表只负责补充可下单媒体，不代表全部分发策略。
                </div>
                <div class="overflow-hidden rounded-lg border border-gray-200">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="w-[24%] px-4 py-3 text-left text-xs font-medium text-gray-500">信源</th>
                                <th class="w-[13%] px-4 py-3 text-left text-xs font-medium text-gray-500">资源类型</th>
                                <th class="w-[23%] px-4 py-3 text-left text-xs font-medium text-gray-500">行业权重</th>
                                <th class="w-[40%] px-4 py-3 text-left text-xs font-medium text-gray-500">执行方式</th>
                            </tr>
                        </thead>
                        <tbody id="distribution-strategy-rows" class="divide-y divide-gray-200 bg-white text-sm"></tbody>
                    </table>
                </div>
                <p class="mt-3 text-xs text-gray-500">上方是策略推荐，不直接创建发布任务；真正入队的账号在下方“可执行媒体账号”里选择。</p>
            </div>

            <div>
                <div class="flex items-center justify-between mb-3">
                    <label class="block text-sm font-medium text-gray-700">4. 选择可执行媒体账号</label>
                    <span class="text-xs text-gray-500">这里只放可以下单、登录或自动发布的资源；不勾选时默认发送到全部启用账号</span>
                </div>
                <div class="overflow-hidden rounded-lg border border-gray-200">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500">选择</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500">媒体资源</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500">属性</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500">价格/速度</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 bg-white">
                    <?php foreach ($accounts as $account): ?>
                        <?php $platform = $platforms[$account['platform']] ?? ['name' => $account['platform'], 'icon' => 'globe']; ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-4">
                                <input type="checkbox" name="account_ids[]" value="<?php echo $account['id']; ?>" data-price="<?php echo (float) ($account['price_amount'] ?? 0); ?>" <?php echo $account['status'] === 'active' ? '' : 'disabled'; ?> class="distribution-account-checkbox rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                            </td>
                            <td class="px-4 py-4">
                                <div class="flex items-center text-sm font-medium text-gray-900">
                                    <i data-lucide="<?php echo $platform['icon']; ?>" class="w-4 h-4 mr-1 text-gray-500"></i>
                                    <?php echo htmlspecialchars($platform['name'] . ' - ' . $account['account_name']); ?>
                                </div>
                                <div class="mt-1 text-xs text-gray-500"><?php echo htmlspecialchars($account['username'] ?: '未填写登录名'); ?> · <?php echo $account['status'] === 'active' ? '启用' : '停用'; ?></div>
                            </td>
                            <td class="px-4 py-4 text-xs text-gray-600">
                                <div><?php echo htmlspecialchars(distribution_option_label($filterOptions['portal_source'], (string) ($account['portal_source'] ?? ''))); ?> / <?php echo htmlspecialchars(distribution_option_label($filterOptions['industry'], (string) ($account['industry'] ?? ''))); ?></div>
                                <div class="mt-1"><?php echo htmlspecialchars(distribution_option_label($filterOptions['region'], (string) ($account['region'] ?? ''))); ?> · <?php echo htmlspecialchars(distribution_option_label($filterOptions['entry_level'], (string) ($account['entry_level'] ?? ''))); ?> · <?php echo htmlspecialchars(distribution_option_label($filterOptions['index_status'], (string) ($account['index_status'] ?? ''))); ?></div>
                                <div class="mt-1"><?php echo htmlspecialchars(distribution_option_label($filterOptions['link_type'], (string) ($account['link_type'] ?? ''))); ?></div>
                                <div class="mt-1">
                                    <?php if (!empty($account['can_geo_rank'])): ?>
                                        <span class="inline-flex items-center rounded bg-red-50 px-2 py-0.5 text-red-600">可发GEO排名</span>
                                    <?php endif; ?>
                                    <span class="inline-flex items-center rounded bg-gray-100 px-2 py-0.5 text-gray-600"><?php echo htmlspecialchars($mediaTypes[$account['media_type'] ?? ''] ?? '媒体'); ?></span>
                                </div>
                            </td>
                            <td class="px-4 py-4 text-sm text-gray-700">
                                <div class="font-medium text-gray-900">￥<?php echo number_format((float) ($account['price_amount'] ?? 0), 2); ?></div>
                                <div class="mt-1 text-xs text-gray-500"><?php echo htmlspecialchars(distribution_option_label($filterOptions['publish_speed'], (string) ($account['publish_speed'] ?? ''))); ?></div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($accounts)): ?>
                        <tr>
                            <td colspan="4" class="px-6 py-8 text-center text-sm text-gray-500">还没有可执行媒体账号。可以在右侧录入价格表资源或外部渠道账号。</td>
                        </tr>
                    <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="sticky bottom-0 -mx-6 -mb-6 flex flex-col gap-3 border-t border-gray-200 bg-white px-6 py-4 shadow-lg md:flex-row md:items-center md:justify-between">
                <div class="text-sm text-gray-700">
                    已选择资源 <span id="selected-resource-count" class="font-semibold text-blue-600">0</span> 个，
                    预计费用 <span id="selected-resource-price" class="font-semibold text-blue-600">￥0.00</span>
                </div>
                <button type="submit" class="inline-flex items-center justify-center px-4 py-2 rounded-md border border-transparent bg-blue-600 text-sm font-medium text-white hover:bg-blue-700">
                    <i data-lucide="send" class="w-4 h-4 mr-2"></i>
                    加入发布队列
                </button>
            </div>
        </form>
    </div>

    <aside class="bg-white shadow rounded-lg">
        <div class="px-6 py-4 border-b border-gray-200">
            <h3 class="text-lg font-medium text-gray-900">行业策略配置</h3>
            <p class="mt-1 text-sm text-gray-500">行业决定信源池，AI偏好决定优先级，价格表只做可下单资源补充。</p>
        </div>
        <div class="space-y-4 p-6">
            <label class="block text-sm font-medium text-gray-700">客户行业
                <select class="distribution-industry-select mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
                    <option>B2B SaaS / 企业服务</option>
                    <option>医疗 / 健康</option>
                    <option>消费品 / 美妆 / 食品</option>
                    <option>教育 / 知识付费</option>
                    <option>金融 / 投资</option>
                    <option>本地生活 / 餐饮</option>
                </select>
            </label>

            <div class="rounded-lg border border-gray-200 p-4">
                <div class="text-sm font-semibold text-gray-700">通用重媒体</div>
                <div class="mt-3 grid grid-cols-2 gap-2 text-sm text-gray-700">
                    <label class="flex items-center gap-2"><input type="checkbox" checked class="rounded border-gray-300 text-blue-600">知乎</label>
                    <label class="flex items-center gap-2"><input type="checkbox" checked class="rounded border-gray-300 text-blue-600">小红书</label>
                    <label class="flex items-center gap-2"><input type="checkbox" checked class="rounded border-gray-300 text-blue-600">微信公众号</label>
                    <label class="flex items-center gap-2"><input type="checkbox" checked class="rounded border-gray-300 text-blue-600">今日头条</label>
                </div>
            </div>

            <div class="rounded-lg border border-emerald-100 bg-emerald-50 p-4">
                <div id="distribution-authority-title" class="text-sm font-semibold text-emerald-900">B2B 行业权威信源</div>
                <div id="distribution-authority-chips" class="mt-3 flex flex-wrap gap-2 text-xs font-semibold text-emerald-700"></div>
                <p id="distribution-authority-note" class="mt-3 text-xs text-emerald-800">这些资源不一定来自价格表，但要进入当前行业的分发白名单。</p>
            </div>

            <details class="rounded-lg border border-gray-200 bg-gray-50 p-4">
                <summary class="cursor-pointer text-sm font-semibold text-gray-700">价格表补充资源</summary>
                <div class="mt-4 space-y-3">
                    <label class="block text-sm font-medium text-gray-700">价格表域名
                        <input class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm" value="https://news.growume.com">
                    </label>
                    <label class="block text-sm font-medium text-gray-700">资源类型
                        <select class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
                            <option>媒体价格 /index/price/website</option>
                            <option>自媒体价格 /index/price/wemedia</option>
                            <option>短视频价格 /index/price/shortvideo</option>
                            <option>问答价格 /index/price/question</option>
                        </select>
                    </label>
                    <label class="block text-sm font-medium text-gray-700">搜索媒体名称
                        <input id="distribution-price-search" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm" placeholder="例如：CSDN、36氪、博客园">
                    </label>
                    <label class="block text-sm font-medium text-gray-700">筛选条件
                        <select id="distribution-price-filter" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm sm:text-sm">
                            <option>B2B / 企业服务</option>
                            <option>包网页收录</option>
                            <option>可带网址</option>
                            <option>当日发布</option>
                        </select>
                    </label>
                </div>
            </details>
        </div>
    </aside>
</div>

<details class="mb-8 bg-white shadow rounded-lg" <?php echo $editAccount ? 'open' : ''; ?>>
        <summary class="flex cursor-pointer list-none items-center justify-between px-6 py-4 border-b border-gray-200">
            <div>
                <h3 class="text-lg font-medium text-gray-900"><?php echo $editAccount ? '编辑可执行资源' : '维护可执行资源'; ?></h3>
                <p class="mt-1 text-sm text-gray-500">用于录入价格表资源、外部渠道账号或可自动发布的平台账号。</p>
            </div>
            <span class="inline-flex items-center rounded-md border border-gray-300 bg-white px-3 py-1.5 text-xs font-medium text-gray-700">
                展开维护
                <i data-lucide="chevron-down" class="ml-1 h-4 w-4"></i>
            </span>
        </summary>
        <form method="POST" class="p-6 space-y-4">
            <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
            <input type="hidden" name="action" value="save_account">
            <input type="hidden" name="account_id" value="<?php echo (int) ($editAccount['id'] ?? 0); ?>">

            <div>
                <label class="block text-sm font-medium text-gray-700">平台</label>
                <select id="distribution-platform-select" name="platform" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
                    <?php foreach ($platforms as $key => $platform): ?>
                        <option value="<?php echo $key; ?>" data-media-type="<?php echo htmlspecialchars($platform['media_type'] ?? 'self_media'); ?>" data-portal-source="<?php echo htmlspecialchars($platform['portal_source'] ?? ''); ?>" <?php echo (($editAccount['platform'] ?? '') === $key) ? 'selected' : ''; ?>><?php echo htmlspecialchars($platform['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm font-medium text-gray-700">资源类型</label>
                    <select id="distribution-media-type-select" name="media_type" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
                        <?php foreach ($mediaTypes as $key => $label): ?>
                            <?php if ($key === '') continue; ?>
                            <option value="<?php echo $key; ?>" <?php echo (($editAccount['media_type'] ?? '') === $key) ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">报价</label>
                    <input type="number" name="price_amount" min="0" step="0.01" value="<?php echo htmlspecialchars((string) ($editAccount['price_amount'] ?? '0')); ?>" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
                </div>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">账号名称</label>
                <input type="text" name="account_name" value="<?php echo htmlspecialchars($editAccount['account_name'] ?? ''); ?>" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm" placeholder="例如：知乎账号、医脉通(39健康)、博客园">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">登录名 / 手机 / 邮箱</label>
                <input type="text" name="username" value="<?php echo htmlspecialchars($editAccount['username'] ?? ''); ?>" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">密码 / Token</label>
                <input type="password" name="password" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm" placeholder="<?php echo $editAccount ? '留空则不修改' : ''; ?>">
                <p class="mt-1 text-xs text-gray-500">保存时会加密，页面只展示脱敏状态。</p>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">发布入口</label>
                <input type="url" name="login_url" value="<?php echo htmlspecialchars($editAccount['login_url'] ?? ''); ?>" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm" placeholder="留空时使用平台默认入口">
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm font-medium text-gray-700">行业</label>
                    <select name="industry" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
                        <?php foreach ($filterOptions['industry'] as $key => $label): ?>
                            <option value="<?php echo $key; ?>" <?php echo (($editAccount['industry'] ?? '') === $key) ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">地区</label>
                    <select name="region" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
                        <?php foreach ($filterOptions['region'] as $key => $label): ?>
                            <option value="<?php echo $key; ?>" <?php echo (($editAccount['region'] ?? '') === $key) ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">媒体来源</label>
                <select id="distribution-portal-source-select" name="portal_source" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
                    <?php foreach ($filterOptions['portal_source'] as $key => $label): ?>
                        <option value="<?php echo $key; ?>" <?php echo (($editAccount['portal_source'] ?? '') === $key) ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="mt-1 text-xs text-gray-500">例如同一个搜狐号平台，也可以归到搜狐网、地方门户或其他来源。</p>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm font-medium text-gray-700">入口级别</label>
                    <select name="entry_level" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
                        <?php foreach ($filterOptions['entry_level'] as $key => $label): ?>
                            <option value="<?php echo $key; ?>" <?php echo (($editAccount['entry_level'] ?? '') === $key) ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">收录情况</label>
                    <select name="index_status" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
                        <?php foreach ($filterOptions['index_status'] as $key => $label): ?>
                            <option value="<?php echo $key; ?>" <?php echo (($editAccount['index_status'] ?? '') === $key) ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm font-medium text-gray-700">发稿速度</label>
                    <select name="publish_speed" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
                        <?php foreach ($filterOptions['publish_speed'] as $key => $label): ?>
                            <option value="<?php echo $key; ?>" <?php echo (($editAccount['publish_speed'] ?? '') === $key) ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">链接类型</label>
                    <select name="link_type" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
                        <?php foreach ($filterOptions['link_type'] as $key => $label): ?>
                            <option value="<?php echo $key; ?>" <?php echo (($editAccount['link_type'] ?? '') === $key) ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <label class="flex items-center rounded-md border border-gray-200 p-3 text-sm text-gray-700">
                <input type="checkbox" name="can_geo_rank" value="1" <?php echo !empty($editAccount['can_geo_rank']) ? 'checked' : ''; ?> class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                <span class="ml-2">支持 GEO 排名 / AI搜索曝光类发布</span>
            </label>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm font-medium text-gray-700">发布方式</label>
                    <select name="publish_mode" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
                        <option value="browser" <?php echo (($editAccount['publish_mode'] ?? '') === 'browser') ? 'selected' : ''; ?>>浏览器自动化</option>
                        <option value="api" <?php echo (($editAccount['publish_mode'] ?? '') === 'api') ? 'selected' : ''; ?>>开放接口</option>
                        <option value="manual" <?php echo (($editAccount['publish_mode'] ?? '') === 'manual') ? 'selected' : ''; ?>>人工辅助</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">状态</label>
                    <select name="status" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
                        <option value="active" <?php echo (($editAccount['status'] ?? 'active') === 'active') ? 'selected' : ''; ?>>启用</option>
                        <option value="inactive" <?php echo (($editAccount['status'] ?? '') === 'inactive') ? 'selected' : ''; ?>>停用</option>
                    </select>
                </div>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">备注</label>
                <textarea name="notes" rows="2" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"><?php echo htmlspecialchars($editAccount['notes'] ?? ''); ?></textarea>
            </div>
            <div class="flex gap-2">
                <button type="submit" class="inline-flex items-center px-4 py-2 rounded-md border border-transparent bg-blue-600 text-sm font-medium text-white hover:bg-blue-700">
                    <i data-lucide="save" class="w-4 h-4 mr-2"></i>
                    保存账号
                </button>
                <?php if ($editAccount): ?>
                    <a href="distribution.php" class="inline-flex items-center px-4 py-2 rounded-md border border-gray-300 bg-white text-sm font-medium text-gray-700 hover:bg-gray-50">取消</a>
                <?php endif; ?>
            </div>
        </form>
    </details>

<!-- GEO 专属素材库 -->
<div class="mb-8 rounded-lg border border-gray-200 bg-white shadow-sm" id="geo-lib-section">
    <button type="button" id="geo-lib-toggle" onclick="toggleGeoLib()"
        aria-expanded="false"
        class="flex w-full items-center justify-between px-6 py-4 text-left">
        <div class="flex items-center gap-3">
            <h3 class="text-lg font-medium text-gray-900">GEO 专属素材库</h3>
            <span class="rounded-full bg-blue-50 px-3 py-1 text-xs font-semibold text-blue-700"><?php echo count($geoMaterials); ?> 条素材</span>
        </div>
        <i data-lucide="chevron-down" id="geo-lib-chevron" class="h-5 w-5 text-gray-400 transition-transform duration-200"></i>
    </button>
    <div id="geo-lib-body" class="hidden">
    <div class="border-t border-gray-200 px-6 pb-2 pt-3">
        <!-- 素材类型 tab -->
        <div class="flex gap-1 rounded-lg bg-gray-100 p-1">
            <?php foreach ($geoMaterialTypes as $typeKey => $typeLabel): ?>
                <a href="distribution.php?geo_tab=<?php echo rawurlencode($typeKey); ?>#geo-materials"
                   class="flex-1 rounded-md px-3 py-2 text-center text-sm font-medium transition <?php echo $activeGeoTab === $typeKey ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-700'; ?>">
                    <?php echo htmlspecialchars($typeLabel); ?>
                    <span class="ml-1 text-xs <?php echo $activeGeoTab === $typeKey ? 'text-blue-600' : 'text-gray-400'; ?>">
                        (<?php echo count($geoMaterialsByType[$typeKey] ?? []); ?>)
                    </span>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
    <div id="geo-materials" class="p-6">
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">

            <!-- 素材列表 -->
            <div class="lg:col-span-2">
                <?php $currentTypeMaterials = $geoMaterialsByType[$activeGeoTab] ?? []; ?>
                <?php if (empty($currentTypeMaterials)): ?>
                    <div class="flex h-40 flex-col items-center justify-center rounded-lg border border-dashed border-gray-300 text-center">
                        <i data-lucide="package-open" class="mb-2 h-8 w-8 text-gray-300"></i>
                        <p class="text-sm text-gray-500">还没有<?php echo htmlspecialchars($geoMaterialTypes[$activeGeoTab]); ?>，在右侧添加第一条。</p>
                    </div>
                <?php else: ?>
                    <div class="space-y-3">
                        <?php foreach ($currentTypeMaterials as $mat):
                            $c = json_decode((string) ($mat['content_json'] ?? '{}'), true) ?: [];
                        ?>
                        <div class="rounded-lg border border-gray-200 p-4">
                            <div class="flex items-start justify-between gap-3">
                                <div class="flex-1 min-w-0">
                                    <div class="font-semibold text-gray-900"><?php echo htmlspecialchars($mat['title']); ?></div>
                                    <?php if ($activeGeoTab === 'brand_entity_card'): ?>
                                        <div class="mt-1 text-sm text-gray-600"><?php echo htmlspecialchars($c['tagline'] ?? ''); ?> <?php echo $c['domain'] ? '· ' . htmlspecialchars($c['domain']) : ''; ?></div>
                                        <?php if (!empty($c['description'])): ?>
                                            <p class="mt-2 text-xs text-gray-500 line-clamp-2"><?php echo htmlspecialchars($c['description']); ?></p>
                                        <?php endif; ?>
                                        <?php if (!empty($c['key_facts'])): ?>
                                            <div class="mt-2 flex flex-wrap gap-1">
                                                <?php foreach ((array) $c['key_facts'] as $fact): ?>
                                                    <span class="rounded-full bg-blue-50 px-2 py-0.5 text-xs text-blue-700"><?php echo htmlspecialchars($fact); ?></span>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    <?php elseif ($activeGeoTab === 'citation_evidence'): ?>
                                        <div class="mt-1 flex flex-wrap items-center gap-2 text-xs">
                                            <?php
                                            $evidenceLabels = ['media_report' => '媒体报道', 'case_study' => '客户案例', 'whitepaper' => '白皮书', 'award' => '获奖认可', 'data' => '数据研究', 'testimonial' => '客户证言'];
                                            $evType = $c['evidence_type'] ?? '';
                                            ?>
                                            <span class="rounded-full bg-emerald-50 px-2 py-0.5 font-medium text-emerald-700"><?php echo htmlspecialchars($evidenceLabels[$evType] ?? $evType); ?></span>
                                            <?php if (!empty($c['source_name'])): ?><span class="text-gray-600"><?php echo htmlspecialchars($c['source_name']); ?></span><?php endif; ?>
                                            <?php if (!empty($c['publish_date'])): ?><span class="text-gray-400"><?php echo htmlspecialchars($c['publish_date']); ?></span><?php endif; ?>
                                        </div>
                                        <?php if (!empty($c['source_url'])): ?>
                                            <a href="<?php echo htmlspecialchars($c['source_url']); ?>" target="_blank" class="mt-1 block truncate text-xs text-blue-600 hover:underline"><?php echo htmlspecialchars($c['source_url']); ?></a>
                                        <?php endif; ?>
                                    <?php elseif ($activeGeoTab === 'competitor_file'): ?>
                                        <?php if (!empty($c['domain'])): ?><div class="mt-1 text-xs text-gray-500"><?php echo htmlspecialchars($c['domain']); ?></div><?php endif; ?>
                                        <?php if (!empty($c['strengths'])): ?><div class="mt-2 text-xs text-gray-600"><span class="font-medium text-green-700">优势：</span><?php echo htmlspecialchars(mb_substr($c['strengths'], 0, 80)); ?></div><?php endif; ?>
                                        <?php if (!empty($c['weaknesses'])): ?><div class="mt-1 text-xs text-gray-600"><span class="font-medium text-red-700">劣势：</span><?php echo htmlspecialchars(mb_substr($c['weaknesses'], 0, 80)); ?></div><?php endif; ?>
                                    <?php endif; ?>
                                    <?php if (!empty($mat['notes'])): ?>
                                        <div class="mt-2 text-xs text-gray-400 italic"><?php echo htmlspecialchars($mat['notes']); ?></div>
                                    <?php endif; ?>
                                </div>
                                <form method="POST" onsubmit="return confirm('确定删除这条素材吗？');" class="shrink-0">
                                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                                    <input type="hidden" name="action" value="delete_geo_material">
                                    <input type="hidden" name="material_id" value="<?php echo (int) $mat['id']; ?>">
                                    <button type="submit" class="rounded p-1.5 text-gray-400 hover:bg-red-50 hover:text-red-600">
                                        <i data-lucide="trash-2" class="h-4 w-4"></i>
                                    </button>
                                </form>
                            </div>
                            <div class="mt-3 text-xs text-gray-400"><?php echo date('Y-m-d H:i', strtotime($mat['created_at'])); ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- 添加表单 -->
            <div class="lg:col-span-1">
                <div class="rounded-lg border border-gray-200 p-5">
                    <h4 class="mb-4 text-sm font-semibold text-gray-900">添加<?php echo htmlspecialchars($geoMaterialTypes[$activeGeoTab]); ?></h4>
                    <form method="POST" class="space-y-4">
                        <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                        <input type="hidden" name="action" value="save_geo_material">
                        <input type="hidden" name="material_type" value="<?php echo htmlspecialchars($activeGeoTab); ?>">
                        <input type="hidden" name="customer_id" value="<?php echo htmlspecialchars($currentCustomerId); ?>">

                        <div>
                            <label class="mb-1 block text-xs font-medium text-gray-700">
                                <?php echo $activeGeoTab === 'brand_entity_card' ? '品牌名称' : ($activeGeoTab === 'citation_evidence' ? '证据标题' : '竞品名称'); ?>
                            </label>
                            <input type="text" name="title" required class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500" placeholder="必填">
                        </div>

                        <?php if ($activeGeoTab === 'brand_entity_card'): ?>
                            <div>
                                <label class="mb-1 block text-xs font-medium text-gray-700">官网域名</label>
                                <input type="text" name="brand_domain" class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none">
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-medium text-gray-700">品牌定位（一句话）</label>
                                <input type="text" name="brand_tagline" class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none">
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-medium text-gray-700">品牌简介</label>
                                <textarea name="brand_description" rows="3" class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none" placeholder="供 AI 引用的品牌描述"></textarea>
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-medium text-gray-700">核心数据 <span class="text-gray-400 font-normal">（每行一条）</span></label>
                                <textarea name="brand_key_facts" rows="4" class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm font-mono shadow-sm focus:border-blue-500 focus:outline-none" placeholder="服务客户 50+&#10;NPS 92&#10;月均引用命中率提升 38%"></textarea>
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-medium text-gray-700">成立时间</label>
                                <input type="text" name="brand_founded" class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none" placeholder="例如：2023年">
                            </div>

                        <?php elseif ($activeGeoTab === 'citation_evidence'): ?>
                            <div>
                                <label class="mb-1 block text-xs font-medium text-gray-700">证据类型</label>
                                <select name="evidence_type" class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none">
                                    <option value="media_report">媒体报道</option>
                                    <option value="case_study">客户案例</option>
                                    <option value="whitepaper">白皮书 / 研究</option>
                                    <option value="award">获奖 / 认可</option>
                                    <option value="data">数据证明</option>
                                    <option value="testimonial">客户证言</option>
                                </select>
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-medium text-gray-700">来源媒体 / 机构</label>
                                <input type="text" name="evidence_source" class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none" placeholder="例如：36氪、Gartner、客户名">
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-medium text-gray-700">原文 URL</label>
                                <input type="url" name="evidence_url" class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none" placeholder="https://...">
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-medium text-gray-700">发布日期</label>
                                <input type="date" name="evidence_date" class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none">
                            </div>

                        <?php elseif ($activeGeoTab === 'competitor_file'): ?>
                            <div>
                                <label class="mb-1 block text-xs font-medium text-gray-700">竞品域名</label>
                                <input type="text" name="competitor_domain" class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none" placeholder="competitor.com">
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-medium text-gray-700">GEO 优势</label>
                                <textarea name="competitor_strengths" rows="2" class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none" placeholder="哪些维度做得好"></textarea>
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-medium text-gray-700">GEO 劣势</label>
                                <textarea name="competitor_weaknesses" rows="2" class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none" placeholder="哪些维度存在短板"></textarea>
                            </div>
                        <?php endif; ?>

                        <div>
                            <label class="mb-1 block text-xs font-medium text-gray-700">备注</label>
                            <textarea name="notes" rows="2" class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none"></textarea>
                        </div>
                        <button type="submit" class="inline-flex w-full items-center justify-center rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-blue-700">
                            <i data-lucide="plus" class="mr-2 h-4 w-4"></i>
                            保存素材
                        </button>
                    </form>
                </div>
            </div>

        </div>
    </div>
    </div><!-- /geo-lib-body -->
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <div class="bg-white shadow rounded-lg">
        <div class="px-6 py-4 border-b border-gray-200">
            <h3 class="text-lg font-medium text-gray-900">账号列表</h3>
        </div>
        <div class="divide-y divide-gray-200">
            <?php foreach ($accounts as $account): ?>
                <?php $platform = $platforms[$account['platform']] ?? ['name' => $account['platform'], 'icon' => 'globe']; ?>
                <div class="p-5">
                    <div class="flex items-start justify-between">
                        <div>
                            <div class="flex items-center text-sm font-semibold text-gray-900">
                                <i data-lucide="<?php echo $platform['icon']; ?>" class="w-4 h-4 mr-2 text-gray-500"></i>
                                <?php echo htmlspecialchars($platform['name']); ?>
                            </div>
                            <div class="mt-1 text-sm text-gray-700"><?php echo htmlspecialchars($account['account_name']); ?></div>
                            <div class="mt-1 text-xs text-gray-500"><?php echo htmlspecialchars(distribution_mask_secret($account['username'] ?? '')); ?></div>
                            <div class="mt-2 flex flex-wrap gap-1 text-xs">
                                <span class="rounded bg-gray-100 px-2 py-0.5 text-gray-600"><?php echo htmlspecialchars($mediaTypes[$account['media_type'] ?? ''] ?? '媒体'); ?></span>
                                <span class="rounded bg-blue-50 px-2 py-0.5 text-blue-700"><?php echo htmlspecialchars(distribution_option_label($filterOptions['portal_source'], (string) ($account['portal_source'] ?? ''))); ?></span>
                                <span class="rounded bg-gray-100 px-2 py-0.5 text-gray-600">￥<?php echo number_format((float) ($account['price_amount'] ?? 0), 2); ?></span>
                                <?php if (!empty($account['can_geo_rank'])): ?>
                                    <span class="rounded bg-red-50 px-2 py-0.5 text-red-600">GEO</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <span class="inline-flex items-center rounded px-2 py-0.5 text-xs font-medium <?php echo $account['status'] === 'active' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-700'; ?>">
                            <?php echo $account['status'] === 'active' ? '启用' : '停用'; ?>
                        </span>
                    </div>
                    <div class="mt-4 flex gap-2">
                        <a href="distribution.php?edit_account_id=<?php echo $account['id']; ?>" class="text-sm text-blue-600 hover:text-blue-800">编辑</a>
                        <form method="POST" onsubmit="return confirm('确定删除这个媒体账号吗？对应发布任务也会删除。')">
                            <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                            <input type="hidden" name="action" value="delete_account">
                            <input type="hidden" name="account_id" value="<?php echo $account['id']; ?>">
                            <button type="submit" class="text-sm text-red-600 hover:text-red-800">删除</button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
            <?php if (empty($accounts)): ?>
                <div class="p-6 text-sm text-gray-500">还没有媒体账号。</div>
            <?php endif; ?>
        </div>
    </div>

    <div class="bg-white shadow rounded-lg lg:col-span-2">
        <div class="px-6 py-4 border-b border-gray-200">
            <div class="flex items-center justify-between gap-3">
                <h3 class="text-lg font-medium text-gray-900">发布任务</h3>
                <form method="POST" onsubmit="return confirm('将自动执行最多 5 条待发布任务，确认继续吗？')">
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    <input type="hidden" name="action" value="run_queued_jobs">
                    <button type="submit" class="inline-flex items-center rounded-md border border-blue-200 bg-blue-50 px-3 py-1.5 text-xs font-medium text-blue-700 hover:bg-blue-100">
                        <i data-lucide="play-circle" class="mr-1 h-3.5 w-3.5"></i>
                        执行待发布任务
                    </button>
                </form>
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">文章</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">平台账号</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">状态</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">创建时间</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">操作</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 bg-white">
                    <?php foreach ($jobs as $job): ?>
                        <?php $platform = $platforms[$job['platform']] ?? ['name' => $job['platform'], 'icon' => 'globe']; ?>
                        <?php $jobMeta = distribution_job_meta((string) $job['status']); ?>
                        <tr>
                            <td class="px-6 py-4 text-sm text-gray-900">
                                <a href="article-view.php?id=<?php echo $job['article_id']; ?>" class="hover:text-blue-600"><?php echo htmlspecialchars($job['article_title'] ?: $job['title']); ?></a>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-600">
                                <div class="flex items-center">
                                    <i data-lucide="<?php echo $platform['icon']; ?>" class="w-4 h-4 mr-1 text-gray-500"></i>
                                    <?php echo htmlspecialchars($platform['name'] . ' / ' . ($job['account_name'] ?? '')); ?>
                                </div>
                            </td>
                            <td class="px-6 py-4">
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium <?php echo $jobMeta['class']; ?>"><?php echo $jobMeta['label']; ?></span>
                                <?php if (!empty($job['remote_url'])): ?>
                                    <a href="<?php echo htmlspecialchars($job['remote_url']); ?>" target="_blank" class="ml-2 text-xs text-blue-600">查看</a>
                                <?php endif; ?>
                                <?php if (!empty($job['error_message'])): ?>
                                    <div class="mt-2 max-w-xs rounded bg-red-50 px-2 py-1 text-xs leading-5 text-red-700"><?php echo htmlspecialchars($job['error_message']); ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-500"><?php echo date('m-d H:i', strtotime($job['created_at'])); ?></td>
                            <td class="px-6 py-4 text-sm">
                                <div class="flex flex-wrap items-center gap-2">
                                    <?php $canRunJob = in_array((string) $job['status'], ['queued', 'failed'], true); ?>
                                    <form method="POST" onsubmit="return confirm('将打开自动化浏览器并尝试发布到目标平台，确认执行吗？')">
                                        <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                                        <input type="hidden" name="action" value="run_job">
                                        <input type="hidden" name="job_id" value="<?php echo $job['id']; ?>">
                                        <button type="submit" <?php echo $canRunJob ? '' : 'disabled'; ?> class="inline-flex items-center rounded-md px-3 py-1.5 text-xs font-medium <?php echo $canRunJob ? 'bg-blue-600 text-white hover:bg-blue-700' : 'cursor-not-allowed bg-gray-100 text-gray-400'; ?>">
                                            <i data-lucide="play" class="mr-1 h-3.5 w-3.5"></i>
                                            <?php echo $job['status'] === 'failed' ? '重新执行' : ($job['status'] === 'running' ? '执行中' : ($job['status'] === 'success' ? '已发布' : '执行发布')); ?>
                                        </button>
                                    </form>
                                </div>
                                <form method="POST" class="mt-2 flex items-center gap-2">
                                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                                    <input type="hidden" name="action" value="mark_job">
                                    <input type="hidden" name="job_id" value="<?php echo $job['id']; ?>">
                                    <select name="status" class="rounded-md border-gray-300 text-xs">
                                        <option value="queued" <?php echo $job['status'] === 'queued' ? 'selected' : ''; ?>>待发布</option>
                                        <option value="running" <?php echo $job['status'] === 'running' ? 'selected' : ''; ?>>执行中</option>
                                        <option value="success" <?php echo $job['status'] === 'success' ? 'selected' : ''; ?>>已发布</option>
                                        <option value="failed" <?php echo $job['status'] === 'failed' ? 'selected' : ''; ?>>失败</option>
                                    </select>
                                    <button type="submit" class="text-xs text-gray-500 hover:text-gray-700">手动改状态</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($jobs)): ?>
                        <tr><td colspan="5" class="px-6 py-8 text-center text-sm text-gray-500">还没有发布任务。</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
    <?php if ($hasRunningDistributionJob): ?>
    window.setTimeout(() => {
        window.location.reload();
    }, 8000);
    <?php endif; ?>

    const distributionIndustryConfigs = {
        'B2B SaaS / 企业服务': {
            ai: ['DeepSeek', '通义', 'Kimi'],
            note: 'B2B 决策者更常用技术问答、媒体背书和企业资料做判断。',
            mix: [['通用重媒体', 35], ['行业权威信源', 45], ['价格表补充资源', 20]],
            principle: '知乎、公众号、头条作为基础保留；B2B 额外优先 CSDN、36氪、掘金、钉钉文档和 GitHub README。',
            authorityTitle: 'B2B 行业权威信源',
            authority: ['CSDN', '36氪', '掘金', '钉钉文档', 'GitHub README'],
            search: '例如：CSDN、36氪、博客园',
            filter: 'B2B / 企业服务',
            rows: [
                ['知乎', '全平台通吃 · 问答和长文沉淀', '通用重媒体', '15%', 'Kimi 5 / DeepSeek 4 / 通义 4', '必选；适合做问答长文、行业解释和服务商对比。', 'blue'],
                ['微信公众号', '深度内容和品牌自有阵地', '通用重媒体', '10%', '元宝 5 / Kimi 3 / DeepSeek 3', '必选；适合沉淀案例、白皮书和客户证据。', 'blue'],
                ['今日头条', '字节生态 · 豆包强绑定', '通用重媒体', '8%', '豆包偏好 5', '保留；适合做基础曝光和品牌实体补充。', 'blue'],
                ['CSDN / 掘金 / 技术博客', '技术问答和工程内容', '行业权威', '18%', 'DeepSeek 5 / Kimi 4 / 通义 4', 'B2B 技术类客户优先保留，用于参数表、教程、方案页引用。', 'green'],
                ['36氪 / 虎嗅 / 媒体', '主流媒体背书', '行业权威', '15%', 'Kimi 4 / DeepSeek 4 / 通义 4', '用于第三方提及、行业观察和品牌可信度建设。', 'green'],
                ['博客园', 'news.growume.com 可匹配', '价格表补充', '9%', '低成本技术媒体', '适合作为技术内容的价格表补充投放。', 'slate'],
            ],
        },
        '医疗 / 健康': {
            ai: ['文心', 'Kimi', 'DeepSeek'],
            note: '医疗内容更依赖权威机构、健康问答和百度系生态。',
            mix: [['通用重媒体', 40], ['行业权威信源', 40], ['价格表补充资源', 20]],
            principle: '知乎、小红书、公众号、头条属于跨行业重媒体；健康行业额外保留丁香医生、丁香园、百度健康等权威信源。',
            authorityTitle: '医疗行业权威信源',
            authority: ['丁香医生', '丁香园', '百度健康', '知乎健康', '微信医疗公众号', '卫健委站'],
            search: '例如：医脉通、39健康、搜狐健康',
            filter: '健康医疗',
            rows: [
                ['知乎', '全平台通吃 · 问答和长文沉淀', '通用重媒体', '15%', 'Kimi 5 / DeepSeek 4 / 通义 4', '必选；可做健康问答、科普解释和服务对比。', 'blue'],
                ['小红书', 'UGC 讨论和体验笔记', '通用重媒体', '8%', '消费强，医疗需合规筛选', '保留入口；健康类只发科普和经验型内容，避开医疗承诺。', 'blue'],
                ['微信公众号', '深度内容和品牌自有阵地', '通用重媒体', '10%', '元宝 5 / Kimi 3 / DeepSeek 3', '必选；适合沉淀专家观点、患者教育和案例资料。', 'blue'],
                ['今日头条 / 抖音百科', '字节生态 · 豆包强绑定', '通用重媒体', '7%', '豆包偏好 5', '保留；适合做基础曝光、科普解释和品牌实体补充。', 'blue'],
                ['丁香医生 / 丁香园', '医疗健康权威信源', '行业权威', '15%', '医疗行业专属保留', '非价格表资源；需人工对接或作为内容引用、专家背书目标。', 'green'],
                ['百度健康 / 知乎健康', '健康问答和百度系可信入口', '行业权威', '15%', '文心优先', '用于健康类问答、百科词条、科普内容和权威引用建设。', 'green'],
                ['医脉通(39健康)', 'news.growume.com 可匹配', '价格表补充', '10%', '健康医疗', '可作为医疗健康类 GEO 稿件的执行补充。', 'slate'],
            ],
        },
        '消费品 / 美妆 / 食品': {
            ai: ['豆包', '元宝', '通义'],
            note: '消费决策更容易被短视频、种草内容、评论和微信生态影响。',
            mix: [['通用重媒体', 55], ['行业种草信源', 30], ['价格表补充资源', 15]],
            principle: '小红书、抖音、公众号、头条必须保留；再结合淘宝评价、百度百科和消费测评类资源。',
            authorityTitle: '消费行业重点信源',
            authority: ['抖音', '小红书', '微信公众号', '淘宝评价', '今日头条', '百度百科'],
            search: '例如：今日头条、百家号、中国食品网',
            filter: '消费 / 美妆 / 食品',
            rows: [
                ['小红书', '消费决策类 UGC', '通用重媒体', '18%', '豆包 3 / Kimi 3 / 通义 3', '必选；承接种草、体验笔记和品牌口碑。', 'blue'],
                ['抖音 / 今日头条', '字节生态 · 短内容曝光', '通用重媒体', '17%', '豆包偏好 5', '必选；适合短视频脚本、测评和品牌百科补充。', 'blue'],
                ['微信公众号', '深度内容和私域承接', '通用重媒体', '10%', '元宝偏好 5', '必选；用于产品故事、成分解释和客户案例。', 'blue'],
                ['淘宝评价 / 电商内容', '购买决策证据', '行业权威', '12%', '通义偏好 5', '用于强化真实使用反馈和价格对比语境。', 'green'],
                ['中国食品网 / 美食媒体', 'news.growume.com 可匹配', '价格表补充', '8%', '自媒体价格', '适合食品、美妆、消费品的发稿补充。', 'slate'],
            ],
        },
        '教育 / 知识付费': {
            ai: ['Kimi', '文心', '元宝'],
            note: '教育内容更看重长文解释、知识库、课程案例和可信资料沉淀。',
            mix: [['通用重媒体', 35], ['知识权威信源', 45], ['价格表补充资源', 20]],
            principle: '知乎、公众号保留；教育行业额外优先 B站科普、百度文库、arXiv、课程案例和研究引用。',
            authorityTitle: '教育行业权威信源',
            authority: ['知乎', '微信公众号', 'B站科普', '百度文库', 'arXiv', '课程案例'],
            search: '例如：百度文库、B站科普、教育媒体',
            filter: '教育 / 知识付费',
            rows: [
                ['知乎', '知识问答和长文沉淀', '通用重媒体', '15%', 'Kimi 5 / DeepSeek 4', '必选；适合课程问答、行业解释和学习路径。', 'blue'],
                ['微信公众号', '课程案例和长文', '通用重媒体', '10%', '元宝 5 / Kimi 3', '必选；沉淀课程案例、方法论和转化文章。', 'blue'],
                ['B站科普', '视频科普和测评', '行业权威', '12%', '豆包 4 / Kimi 3', '适合知识讲解、课程体验和品牌人格化。', 'green'],
                ['百度文库 / arXiv', '资料库和研究引用', '行业权威', '18%', '文心 5 / Kimi 5', '用于提升知识可信度和长文引用基础。', 'green'],
                ['教育媒体 / 百家号', 'news.growume.com 可匹配', '价格表补充', '8%', '按价格表匹配', '作为发稿和搜索收录补充。', 'slate'],
            ],
        },
        '金融 / 投资': {
            ai: ['DeepSeek', 'Kimi', '元宝'],
            note: '金融内容更依赖数据推理、财经媒体背书和合规口径。',
            mix: [['通用重媒体', 30], ['财经权威信源', 50], ['价格表补充资源', 20]],
            principle: '知乎、公众号、头条保留；金融行业额外优先雪球、36氪、虎嗅、财新和券商研报类资料。',
            authorityTitle: '金融行业权威信源',
            authority: ['雪球', '36氪', '虎嗅', '微信公众号', '券商研报', '财新'],
            search: '例如：财经商业、证券、投资媒体',
            filter: '金融 / 投资',
            rows: [
                ['知乎', '问答和长文解释', '通用重媒体', '12%', 'Kimi 5 / DeepSeek 4', '必选；适合概念解释、风险提示和服务对比。', 'blue'],
                ['微信公众号', '深度内容和私域', '通用重媒体', '10%', '元宝偏好 5', '必选；适合研报解读、案例和数据型 FAQ。', 'blue'],
                ['雪球 / 财经媒体', '投资讨论和财经背书', '行业权威', '20%', 'DeepSeek 5 / Kimi 4', '保留；用于财经语境、观点提及和可信信号。', 'green'],
                ['36氪 / 虎嗅', '主流商业媒体', '行业权威', '15%', 'Kimi 4 / 通义 4', '用于商业模式、融资和企业可信背书。', 'green'],
                ['财经商业媒体', 'news.growume.com 可匹配', '价格表补充', '8%', '财经商业', '价格表补充资源，适合基础发稿和搜索露出。', 'slate'],
            ],
        },
        '本地生活 / 餐饮': {
            ai: ['豆包', '通义', '元宝'],
            note: '本地生活更依赖附近服务、点评、短视频和本地公众号。',
            mix: [['通用重媒体', 50], ['本地生活信源', 35], ['价格表补充资源', 15]],
            principle: '小红书、抖音、公众号、头条保留；本地生活额外优先大众点评、美团、本地号和地图类资料。',
            authorityTitle: '本地生活重点信源',
            authority: ['抖音同城', '大众点评', '小红书', '美团', '微信本地公众号', '地图资料'],
            search: '例如：地方门户、本地生活、餐饮媒体',
            filter: '本地生活 / 餐饮',
            rows: [
                ['小红书', '探店和体验笔记', '通用重媒体', '15%', '豆包 3 / Kimi 3', '必选；适合体验种草和真实口碑。', 'blue'],
                ['抖音同城 / 今日头条', '短视频和本地曝光', '通用重媒体', '18%', '豆包偏好 5', '必选；适合门店曝光、套餐和场景内容。', 'blue'],
                ['微信公众号', '本地私域内容', '通用重媒体', '10%', '元宝偏好 5', '必选；适合活动、菜单和品牌故事。', 'blue'],
                ['大众点评 / 美团', '消费决策入口', '行业权威', '18%', '本地生活强相关', '保留；用于评论、门店资料和消费决策信号。', 'green'],
                ['地方门户网站', 'news.growume.com 可匹配', '价格表补充', '8%', '按价格表匹配', '用于本地新闻、活动和门店资料补充。', 'slate'],
            ],
        },
    };

    function distributionBadgeClass(index) {
        return index === 0
            ? 'rounded-full bg-blue-600 px-3 py-1 text-xs font-semibold text-white'
            : 'rounded-full bg-blue-100 px-3 py-1 text-xs font-semibold text-blue-700';
    }

    function distributionTypeClass(tone) {
        if (tone === 'green') return 'inline-flex whitespace-nowrap rounded-full bg-emerald-50 px-3 py-1 text-xs font-semibold text-emerald-700';
        if (tone === 'blue') return 'inline-flex whitespace-nowrap rounded-full bg-blue-50 px-3 py-1 text-xs font-semibold text-blue-700';
        return 'inline-flex whitespace-nowrap rounded-full bg-gray-100 px-3 py-1 text-xs font-semibold text-gray-600';
    }

    function renderDistributionIndustry(industry) {
        const config = distributionIndustryConfigs[industry] || distributionIndustryConfigs['B2B SaaS / 企业服务'];
        const aiPriority = document.getElementById('distribution-ai-priority');
        const aiNote = document.getElementById('distribution-ai-note');
        const mixRows = document.getElementById('distribution-mix-rows');
        const principleText = document.getElementById('distribution-principle-text');
        const strategyRows = document.getElementById('distribution-strategy-rows');
        const authorityTitle = document.getElementById('distribution-authority-title');
        const authorityChips = document.getElementById('distribution-authority-chips');
        const authorityNote = document.getElementById('distribution-authority-note');
        const priceSearch = document.getElementById('distribution-price-search');
        const priceFilter = document.getElementById('distribution-price-filter');

        if (!aiPriority || !aiNote || !mixRows || !principleText || !strategyRows) {
            return;
        }

        document.querySelectorAll('.distribution-industry-select').forEach((select) => {
            if (select.value !== industry) {
                select.value = industry;
            }
        });

        aiPriority.innerHTML = config.ai.map((name, index) => (
            `<span class="${distributionBadgeClass(index)}">#${index + 1} ${name}</span>`
        )).join('');
        aiNote.textContent = config.note;
        mixRows.innerHTML = config.mix.map(([label, value]) => (
            `<div class="flex items-center justify-between"><span>${label}</span><strong>${value}%</strong></div>`
        )).join('');
        principleText.textContent = config.principle;
        if (authorityTitle) {
            authorityTitle.textContent = config.authorityTitle || '行业权威信源';
        }
        if (authorityChips) {
            authorityChips.innerHTML = (config.authority || []).map((item) => (
                `<span class="rounded-full bg-white px-3 py-1">${item}</span>`
            )).join('');
        }
        if (authorityNote) {
            authorityNote.textContent = `这些资源不一定来自价格表，但要进入${industry}的分发白名单。`;
        }
        if (priceSearch) {
            priceSearch.placeholder = config.search || '例如：媒体名称、平台资源';
        }
        if (priceFilter) {
            priceFilter.innerHTML = `<option>${config.filter || industry}</option><option>包网页收录</option><option>可带网址</option><option>当日发布</option>`;
        }
        strategyRows.innerHTML = config.rows.map(([name, meta, type, weight, score, execution, tone]) => (
            `<tr class="${tone === 'green' ? 'bg-emerald-50/40 hover:bg-emerald-50' : 'hover:bg-gray-50'}">
                <td class="px-4 py-4">
                    <div class="font-semibold text-gray-900">${name}</div>
                    <div class="mt-1 text-xs text-gray-500">${meta}</div>
                </td>
                <td class="px-4 py-4 whitespace-nowrap"><span class="${distributionTypeClass(tone)}">${type}</span></td>
                <td class="px-4 py-4"><div class="font-semibold">${weight}</div><div class="text-xs text-gray-500">${score}</div></td>
                <td class="px-4 py-4 text-xs text-gray-500">${execution}</td>
            </tr>`
        )).join('');
    }

    document.querySelectorAll('.distribution-industry-select').forEach((select) => {
        select.addEventListener('change', (event) => renderDistributionIndustry(event.target.value));
    });
    const _defaultIndustry = <?php echo json_encode(!empty($_industryPref['industry']) ? $_industryPref['industry'] : 'B2B SaaS / 企业服务'); ?>;
    renderDistributionIndustry(_defaultIndustry);
    // Sync all industry selects to the default
    document.querySelectorAll('.distribution-industry-select').forEach((select) => {
        if (select.value !== _defaultIndustry) select.value = _defaultIndustry;
    });

    function updateDistributionSelection() {
        const checked = Array.from(document.querySelectorAll('.distribution-account-checkbox:checked'));
        const total = checked.reduce((sum, item) => sum + Number(item.dataset.price || 0), 0);
        const countNode = document.getElementById('selected-resource-count');
        const priceNode = document.getElementById('selected-resource-price');
        if (countNode) {
            countNode.textContent = checked.length;
        }
        if (priceNode) {
            priceNode.textContent = `￥${total.toFixed(2)}`;
        }
    }

    document.querySelectorAll('.distribution-account-checkbox').forEach((checkbox) => {
        checkbox.addEventListener('change', updateDistributionSelection);
    });
    updateDistributionSelection();

    const platformSelect = document.getElementById('distribution-platform-select');
    const mediaTypeSelect = document.getElementById('distribution-media-type-select');
    const portalSourceSelect = document.getElementById('distribution-portal-source-select');
    const accountNameInput = document.querySelector('input[name="account_name"]');

    if (platformSelect && mediaTypeSelect) {
        platformSelect.addEventListener('change', () => {
            const selected = platformSelect.options[platformSelect.selectedIndex];
            const mediaType = selected ? selected.dataset.mediaType : '';
            const portalSource = selected ? selected.dataset.portalSource : '';
            if (mediaType) {
                mediaTypeSelect.value = mediaType;
            }
            if (portalSourceSelect && portalSource) {
                portalSourceSelect.value = portalSource;
            }
            if (accountNameInput && accountNameInput.value.trim() === '' && mediaType === 'website' && selected) {
                accountNameInput.value = `${selected.textContent.trim()}发稿资源`;
            }
        });
    }

    function toggleGeoLib() {
        const body    = document.getElementById('geo-lib-body');
        const chevron = document.getElementById('geo-lib-chevron');
        const toggle  = document.getElementById('geo-lib-toggle');
        const open    = toggle.getAttribute('aria-expanded') === 'true';
        if (open) {
            body.classList.add('hidden');
            chevron.classList.remove('rotate-180');
            toggle.setAttribute('aria-expanded', 'false');
        } else {
            body.classList.remove('hidden');
            chevron.classList.add('rotate-180');
            toggle.setAttribute('aria-expanded', 'true');
        }
    }
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
