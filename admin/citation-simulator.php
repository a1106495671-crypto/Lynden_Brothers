<?php
/**
 * GEO 引用模拟器
 */

define('FEISHU_TREASURE', true);
session_start();

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/database_admin.php';
require_once __DIR__ . '/../includes/geo_diagnosis_service.php';
require_once __DIR__ . '/../includes/citation_simulator_service.php';

require_admin_login();

$page_title = '引用模拟器';
$message = '';
$error = '';

function sim_h($value): string {
    return citation_simulator_html($value);
}

function sim_type_label(string $type): string {
    return citation_simulator_type_label($type);
}

function sim_ensure_content_queue(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS geo_content_queue (
        id SERIAL PRIMARY KEY,
        customer_id VARCHAR(100),
        week_num SMALLINT DEFAULT 1,
        platform VARCHAR(50),
        keyword VARCHAR(200),
        title TEXT DEFAULT '',
        angle TEXT,
        content_format VARCHAR(50),
        priority VARCHAR(5) DEFAULT 'P1',
        status VARCHAR(20) DEFAULT 'pending',
        article_title TEXT,
        article_content TEXT,
        source VARCHAR(50) DEFAULT '',
        created_at TIMESTAMP DEFAULT NOW(),
        processed_at TIMESTAMP
    )");
    $db->exec("ALTER TABLE geo_content_queue ADD COLUMN IF NOT EXISTS title TEXT DEFAULT ''");
    $db->exec("ALTER TABLE geo_content_queue ADD COLUMN IF NOT EXISTS source VARCHAR(50) DEFAULT ''");
    $db->exec("ALTER TABLE geo_content_queue ADD COLUMN IF NOT EXISTS platform VARCHAR(50) DEFAULT ''");
    $db->exec("ALTER TABLE geo_content_queue ADD COLUMN IF NOT EXISTS angle TEXT DEFAULT ''");
    $db->exec("ALTER TABLE geo_content_queue ADD COLUMN IF NOT EXISTS content_format VARCHAR(50) DEFAULT ''");
    $db->exec("ALTER TABLE geo_content_queue ALTER COLUMN status SET DEFAULT 'pending'");
    $db->exec("ALTER TABLE geo_content_queue ALTER COLUMN priority SET DEFAULT 'P1'");
    $db->exec("ALTER TABLE geo_content_queue ALTER COLUMN created_at SET DEFAULT NOW()");
}

function sim_customer_id_for_brand(PDO $db, array $formData): string {
    $brandName = trim((string) ($formData['brand_name'] ?? ''));
    $domain = trim((string) ($formData['domain'] ?? ''));
    if ($domain !== '') {
        $stmt = $db->prepare("SELECT customer_id FROM customers WHERE lower(domain) = lower(?) ORDER BY id DESC LIMIT 1");
        $stmt->execute([$domain]);
        $found = (string) ($stmt->fetchColumn() ?: '');
        if ($found !== '') {
            return $found;
        }
    }
    if ($brandName !== '') {
        $stmt = $db->prepare("SELECT customer_id FROM customers WHERE name ILIKE ? ORDER BY id DESC LIMIT 1");
        $stmt->execute(['%' . $brandName . '%']);
        $found = (string) ($stmt->fetchColumn() ?: '');
        if ($found !== '') {
            return $found;
        }
    }
    return $domain !== '' ? preg_replace('/[^a-z0-9]+/i', '-', strtolower($domain)) : preg_replace('/[^a-z0-9\x{4e00}-\x{9fa5}]+/u', '-', strtolower($brandName ?: 'sim-brand'));
}

function sim_dispatch_actions_to_content_queue(PDO $db, array $formData, array $result, array $actionDefs): int {
    $selected = array_values(array_filter((array) ($result['selected_actions'] ?? [])));
    if (!$selected) {
        throw new RuntimeException('请至少选择一个优化动作再派发');
    }

    sim_ensure_content_queue($db);
    $defsByKey = [];
    foreach ($actionDefs as $def) {
        $defsByKey[(string) $def['key']] = $def;
    }

    $customerId = sim_customer_id_for_brand($db, $formData);
    $keyword = trim((string) ($result['query_text'] ?? $formData['query_text'] ?? ''));
    $brandName = trim((string) ($formData['brand_name'] ?? ''));
    $insert = $db->prepare("
        INSERT INTO geo_content_queue (customer_id, week_num, platform, keyword, title, angle, content_format, priority, status, source)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', 'citation_simulator')
    ");

    $platformByCategory = [
        'ugc' => '知乎',
        'pr_media' => '搜狐号',
        'site' => '官网',
        'community' => '知乎',
        'academic' => '微信公众号',
    ];
    $formatByCategory = [
        'ugc' => '测评/问答',
        'pr_media' => '媒体通稿',
        'site' => '事实页/指南',
        'community' => '讨论帖',
        'academic' => '证据清单',
    ];

    $count = 0;
    foreach ($selected as $index => $key) {
        $def = $defsByKey[$key] ?? null;
        if (!$def) {
            continue;
        }
        $category = (string) ($def['category'] ?? 'other');
        $platform = $platformByCategory[$category] ?? '知乎';
        $format = $formatByCategory[$category] ?? 'GEO内容';
        $angle = sprintf(
            '%s：围绕“%s”补充可被 AI 引用的证据。动作说明：%s。目标：提升 %s 在该问题下的引用稳定性。',
            (string) $def['label'],
            $keyword,
            (string) ($def['description'] ?? ''),
            $brandName !== '' ? $brandName : '本品牌'
        );
        $priority = $index === 0 ? 'P0' : 'P1';
        $week = min(4, $index + 1);
        $title = sprintf('针对「%s」执行：%s', $keyword, (string) $def['label']);
        $insert->execute([$customerId, $week, $platform, $keyword, $title, $angle, $format, $priority]);
        $count++;
    }

    return $count;
}

try {
    geo_diagnosis_ensure_schema($db);
    citation_simulator_ensure_schema($db);

    $industryOptions = citation_simulator_industries();
    $queryTypes = citation_simulator_query_types();
    $aiProviders = citation_simulator_ai_providers();
    $actionDefs = citation_simulator_action_defs($db);
    $apiConfig = citation_simulator_api_config();
    $postAction = (string) ($_POST['action'] ?? 'run_simulation');
    $formData = citation_simulator_sanitize_form($_SERVER['REQUEST_METHOD'] === 'POST' && $postAction !== 'save_api_config' ? $_POST : []);

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !verify_csrf_token($_POST['csrf_token'] ?? '')) {
        throw new RuntimeException('CSRF验证失败');
    }

    $apiConfigJustSaved = false;
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $postAction === 'save_api_config') {
        if (!citation_simulator_save_api_config($_POST)) {
            throw new RuntimeException('引用模拟器 API 配置保存失败');
        }
        $apiConfig = citation_simulator_api_config();
        $message = '引用模拟器 API 配置已保存';
        $apiConfigJustSaved = true;
    }

    // 真实 API 查询只在 POST run_simulation / run_batch 时触发；GET 页面加载始终用本地模拟避免超时
    $isRunSimulation = $_SERVER['REQUEST_METHOD'] === 'POST' && $postAction === 'run_simulation';
    $isRunBatch      = $_SERVER['REQUEST_METHOD'] === 'POST' && $postAction === 'run_batch';
    $isSaveSimulation = $_SERVER['REQUEST_METHOD'] === 'POST' && $postAction === 'save_simulation';
    $isDispatchActions = $_SERVER['REQUEST_METHOD'] === 'POST' && $postAction === 'dispatch_actions';
    $effectiveConfig = ($isRunSimulation || $isRunBatch) ? $apiConfig : null;

    // 批量关键词状态
    $batchFormData = citation_simulator_sanitize_form($isRunBatch ? $_POST : []);
    $batchResults  = [];
    $activeMode    = $isRunBatch ? 'batch' : 'single';

    $result = citation_simulator_build_result($formData, $aiProviders, $actionDefs, $isRunSimulation ? $effectiveConfig : null);
    if ($isRunSimulation) {
        citation_simulator_save_result($db, $formData, $result);
        $message = ($apiConfig['mode'] === 'real') ? '真实反查完成，结果已保存' : '引用模拟结果已保存';
    }

    if ($isSaveSimulation || $isDispatchActions) {
        $queryId = citation_simulator_save_result($db, $formData, $result);
        $message = '引用模拟结果已保存';
        if ($isDispatchActions) {
            $dispatched = sim_dispatch_actions_to_content_queue($db, $formData, $result, $actionDefs);
            $db->prepare("UPDATE geo_simulator_simulations SET dispatched = TRUE WHERE query_id = ?")->execute([$queryId]);
            $message = '已保存模拟结果，并派发 ' . $dispatched . ' 个优化任务到内容生成队列';
        }
    }

    if ($isRunBatch) {
        $rawKeywords   = (string) ($_POST['batch_keywords'] ?? '');
        $batchKeywords = array_values(array_filter(array_map('trim', explode("\n", $rawKeywords)), fn($k) => $k !== ''));
        $batchKeywords = array_slice($batchKeywords, 0, 20);
        foreach ($batchKeywords as $kw) {
            $kwData              = $batchFormData;
            $kwData['query_text'] = $kw;
            $kwResult            = citation_simulator_build_result($kwData, $aiProviders, $actionDefs, $effectiveConfig);
            citation_simulator_save_result($db, $kwData, $kwResult);
            $batchResults[] = [
                'keyword'           => $kw,
                'rank'              => (int)   $kwResult['client_current_rank'],
                'score'             => (float) $kwResult['client_current_score'],
                'citation_set_size' => (int)   $kwResult['citation_set_size'],
                'enters_top_5'      => (bool)  $kwResult['enters_top_5'],
                'gap_to_top_5'      => $kwResult['gap_to_top_5'],
                'resulting_rank'    => (int)   $kwResult['resulting_rank'],
            ];
        }
        $message = '批量模拟完成，共处理 ' . count($batchResults) . ' 个关键词';
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
    $industryOptions = $industryOptions ?? citation_simulator_industries();
    $queryTypes      = $queryTypes      ?? citation_simulator_query_types();
    $aiProviders     = $aiProviders     ?? citation_simulator_ai_providers();
    $actionDefs      = $actionDefs      ?? citation_simulator_seed_action_rows();
    $apiConfig          = $apiConfig          ?? citation_simulator_api_config();
    $apiConfigJustSaved = $apiConfigJustSaved ?? false;
    $formData           = $formData           ?? citation_simulator_sanitize_form($_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : []);
    $result             = $result             ?? citation_simulator_build_result($formData, $aiProviders, $actionDefs, null);
    $batchFormData      = $batchFormData      ?? citation_simulator_sanitize_form([]);
    $batchResults       = $batchResults       ?? [];
    $activeMode         = $activeMode         ?? 'single';
    // 默认折叠：刚保存过、或所有 provider 均已配置且状态正常
    $allProvidersOk = !empty($apiConfig['providers']) && array_reduce(
        $apiConfig['providers'],
        fn($carry, $p) => $carry && $p['configured'] && ($p['op_status'] ?? 'unconfigured') === 'normal',
        true
    );
    $apiPanelCollapsed = $apiConfigJustSaved || $allProvidersOk;
}

$otherScores = array_values(array_map(static fn($source) => (float) $source['aggregated_score'], array_filter($result['sources'], static fn($source) => !$source['is_client'])));

require_once __DIR__ . '/includes/header.php';
?>
            <div class="mb-8">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <h1 class="text-3xl font-bold text-gray-900">引用模拟器</h1>
                        <p class="mt-1 text-sm text-gray-600">关键词反查排名与优化动作推演</p>
                    </div>
                    <a href="<?php echo sim_h(admin_url('geo-diagnosis.php')); ?>" class="inline-flex items-center justify-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50">
                        <i data-lucide="radar" class="mr-2 h-4 w-4"></i>
                        雷达诊断
                    </a>
                </div>
            </div>

            <?php if ($error !== ''): ?>
                <div class="mb-6 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"><?php echo sim_h($error); ?></div>
            <?php endif; ?>

            <section class="mb-6 rounded-lg border border-gray-200 bg-white shadow-sm" id="api-config-section">
                <button type="button"
                    id="api-config-toggle"
                    onclick="toggleApiConfig()"
                    class="flex w-full flex-col gap-3 px-6 py-4 text-left lg:flex-row lg:items-center lg:justify-between <?php echo $apiPanelCollapsed ? '' : 'border-b border-gray-200'; ?>"
                    aria-expanded="<?php echo $apiPanelCollapsed ? 'false' : 'true'; ?>">
                    <div class="flex items-center gap-3">
                        <div>
                            <h2 class="text-lg font-semibold text-gray-900">真实反查 API 配置</h2>
                            <p class="mt-1 text-sm text-gray-500">先保存 API Key；真实反查链路接入后会用这里的配置调用各家 AI。</p>
                        </div>
                    </div>
                    <div class="flex shrink-0 items-center gap-3">
                        <div class="inline-flex rounded-full bg-gray-100 p-1 text-xs font-medium">
                            <span class="rounded-full px-3 py-1 <?php echo $apiConfig['mode'] === 'local' ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500'; ?>">本地模拟</span>
                            <span class="rounded-full px-3 py-1 <?php echo $apiConfig['mode'] === 'real' ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500'; ?>">真实反查</span>
                        </div>
                        <i data-lucide="chevron-down"
                            id="api-config-chevron"
                            class="h-5 w-5 text-gray-400 transition-transform duration-200 <?php echo $apiPanelCollapsed ? '' : 'rotate-180'; ?>"></i>
                    </div>
                </button>
                <div id="api-config-body" class="<?php echo $apiPanelCollapsed ? 'hidden' : ''; ?>">
                <form method="POST" class="px-6 py-5">
                    <input type="hidden" name="csrf_token" value="<?php echo sim_h(generate_csrf_token()); ?>">
                    <input type="hidden" name="action" value="save_api_config">
                    <div class="mb-5 grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <label class="flex items-center gap-3 rounded-lg border border-gray-200 px-4 py-3">
                            <input type="radio" name="data_mode" value="local" class="border-gray-300 text-blue-600 focus:ring-blue-500" <?php echo $apiConfig['mode'] === 'local' ? 'checked' : ''; ?>>
                            <span>
                                <span class="block text-sm font-medium text-gray-900">本地模拟</span>
                                <span class="text-xs text-gray-500">不用 API Key，继续使用当前 MVP 推演数据。</span>
                            </span>
                        </label>
                        <label class="flex items-center gap-3 rounded-lg border border-gray-200 px-4 py-3">
                            <input type="radio" name="data_mode" value="real" class="border-gray-300 text-blue-600 focus:ring-blue-500" <?php echo $apiConfig['mode'] === 'real' ? 'checked' : ''; ?>>
                            <span>
                                <span class="block text-sm font-medium text-gray-900">真实反查</span>
                                <span class="text-xs text-gray-500">调用已配置的 Kimi/DeepSeek API 发起真实查询；未配置的 provider 自动降级为本地模拟。</span>
                            </span>
                        </label>
                    </div>
                    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2 xl:grid-cols-3">
                        <?php
                        $opStatusLabels = [
                            'normal'          => ['label' => '正常', 'class' => 'bg-green-50 text-green-700'],
                            'needs_attention' => ['label' => '待补',  'class' => 'bg-amber-50 text-amber-700'],
                            'disabled'        => ['label' => '停用',  'class' => 'bg-red-50 text-red-600'],
                            'unconfigured'    => ['label' => '未配置','class' => 'bg-gray-100 text-gray-500'],
                        ];
                        ?>
                        <?php foreach ($apiConfig['providers'] as $providerKey => $providerConfig): ?>
                            <?php
                            $opStatus  = $providerConfig['op_status'] ?? 'unconfigured';
                            $opDisplay = $opStatusLabels[$opStatus] ?? $opStatusLabels['unconfigured'];
                            $badgeLabel = $providerConfig['configured'] ? '已配置 · ' . $opDisplay['label'] : '未配置';
                            $badgeClass = $providerConfig['configured'] ? $opDisplay['class'] : 'bg-gray-100 text-gray-500';
                            $isCollapsed = $providerConfig['configured'] && $opStatus === 'normal';
                            ?>
                            <div class="rounded-lg border <?php echo $opStatus === 'needs_attention' ? 'border-amber-200' : 'border-gray-200'; ?> p-4" data-provider-card>
                                <div class="flex items-start justify-between gap-3">
                                    <div>
                                        <div class="font-semibold text-gray-900"><?php echo sim_h($providerConfig['label']); ?></div>
                                        <p class="mt-1 text-xs leading-5 text-gray-500"><?php echo sim_h($providerConfig['description']); ?></p>
                                    </div>
                                    <div class="flex shrink-0 items-center gap-2">
                                        <span class="rounded-full px-2 py-1 text-xs font-medium <?php echo $badgeClass; ?>">
                                            <?php echo sim_h($badgeLabel); ?>
                                        </span>
                                        <button
                                            type="button"
                                            class="rounded-md border border-gray-200 px-2 py-1 text-xs font-medium text-gray-600 hover:border-blue-200 hover:bg-blue-50 hover:text-blue-700"
                                            data-provider-toggle
                                            aria-expanded="<?php echo $isCollapsed ? 'false' : 'true'; ?>">
                                            <?php echo $isCollapsed ? '展开配置' : '收起'; ?>
                                        </button>
                                    </div>
                                </div>
                                <div class="mt-4 space-y-3 <?php echo $isCollapsed ? 'hidden' : ''; ?>" data-provider-body>
                                    <?php foreach ($providerConfig['fields'] as $fieldKey => $fieldConfig): ?>
                                        <div>
                                            <?php if (($fieldConfig['type'] ?? '') === 'boolean'): ?>
                                                <label class="flex items-center justify-between rounded-md border border-gray-200 px-3 py-2 text-sm">
                                                    <span class="font-medium text-gray-700"><?php echo sim_h($fieldConfig['label']); ?></span>
                                                    <input type="checkbox" name="provider_keys[<?php echo sim_h($providerKey); ?>][<?php echo sim_h($fieldKey); ?>]" value="1" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500" <?php echo $fieldConfig['configured'] ? 'checked' : ''; ?>>
                                                </label>
                                            <?php else: ?>
                                                <label class="mb-1 block text-xs font-medium text-gray-600" for="api-<?php echo sim_h($providerKey . '-' . $fieldKey); ?>"><?php echo sim_h($fieldConfig['label']); ?></label>
                                                <?php if (!empty($fieldConfig['secret'])): ?>
                                                    <input id="api-<?php echo sim_h($providerKey . '-' . $fieldKey); ?>" type="password" autocomplete="off" name="provider_keys[<?php echo sim_h($providerKey); ?>][<?php echo sim_h($fieldKey); ?>]" class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500" placeholder="<?php echo $fieldConfig['configured'] ? '已保存：' . sim_h($fieldConfig['masked']) : '粘贴配置值'; ?>">
                                                <?php else: ?>
                                                    <input id="api-<?php echo sim_h($providerKey . '-' . $fieldKey); ?>" type="text" autocomplete="off" name="provider_keys[<?php echo sim_h($providerKey); ?>][<?php echo sim_h($fieldKey); ?>]" value="<?php echo sim_h($fieldConfig['value'] ?? ''); ?>" class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500" placeholder="例如 /var/data/<?php echo sim_h($providerKey); ?>_accounts">
                                                <?php endif; ?>
                                            <?php endif; ?>
                                            <?php if ($fieldConfig['configured'] && ($fieldConfig['type'] ?? '') !== 'boolean'): ?>
                                                <label class="mt-2 flex items-center text-xs text-gray-500">
                                                    <input type="checkbox" name="clear_provider_key[<?php echo sim_h($providerKey); ?>][<?php echo sim_h($fieldKey); ?>]" value="1" class="mr-2 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                                    清除这个配置
                                                </label>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                    <?php if ($providerConfig['configured']): ?>
                                        <div class="border-t border-gray-100 pt-3">
                                            <label class="mb-1 block text-xs font-medium text-gray-600">运行状态</label>
                                            <select name="provider_op_status[<?php echo sim_h($providerKey); ?>]" class="block w-full rounded-md border border-gray-300 px-3 py-1.5 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500">
                                                <option value="normal"          <?php echo $opStatus === 'normal'          ? 'selected' : ''; ?>>正常 — 配额充足，可正常调用</option>
                                                <option value="needs_attention" <?php echo $opStatus === 'needs_attention' ? 'selected' : ''; ?>>待补 — 配额不足或登录态失效，需补充</option>
                                                <option value="disabled"        <?php echo $opStatus === 'disabled'        ? 'selected' : ''; ?>>停用 — 暂时关闭此渠道</option>
                                            </select>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="mt-5 flex justify-end">
                        <button type="submit" class="inline-flex items-center justify-center rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-blue-700">
                            <i data-lucide="key-round" class="mr-2 h-4 w-4"></i>
                            保存 API 配置
                        </button>
                    </div>
                </form>
                </div><!-- /api-config-body -->
            </section>

            <!-- 模式切换 -->
            <div class="mb-6 rounded-xl border border-gray-200 bg-white p-1 shadow-sm">
                <div class="grid grid-cols-2 gap-1">
                    <button type="button" id="tab-single"
                        onclick="switchMode('single')"
                        class="inline-flex items-center justify-center rounded-lg px-4 py-3 text-sm font-semibold transition <?php echo $activeMode === 'single' ? 'bg-blue-600 text-white shadow-sm' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'; ?>">
                        <i data-lucide="search" class="mr-2 h-4 w-4"></i>
                        单关键词
                    </button>
                    <button type="button" id="tab-batch"
                        onclick="switchMode('batch')"
                        class="inline-flex items-center justify-center rounded-lg px-4 py-3 text-sm font-semibold transition <?php echo $activeMode === 'batch' ? 'bg-blue-600 text-white shadow-sm' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'; ?>">
                        <i data-lucide="list" class="mr-2 h-4 w-4"></i>
                        批量关键词
                    </button>
                </div>
            </div>

            <!-- 单关键词模式 -->
            <div id="mode-single" <?php echo $activeMode === 'batch' ? 'class="hidden"' : ''; ?>>
            <div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
                <section class="rounded-lg border border-gray-200 bg-white shadow-sm xl:col-span-1">
                    <div class="border-b border-gray-200 px-6 py-4">
                        <h2 class="text-lg font-semibold text-gray-900">单关键词模拟</h2>
                    </div>
                    <form method="POST" class="space-y-5 px-6 py-6">
                        <input type="hidden" name="csrf_token" value="<?php echo sim_h(generate_csrf_token()); ?>">
                        <input type="hidden" name="action" value="run_simulation">
                        <div>
                            <label class="mb-2 block text-sm font-medium text-gray-700" for="brand-name">品牌名称</label>
                            <input id="brand-name" name="brand_name" type="text" value="<?php echo sim_h($formData['brand_name']); ?>" class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div>
                            <label class="mb-2 block text-sm font-medium text-gray-700" for="brand-domain">官网域名</label>
                            <input id="brand-domain" name="domain" type="text" value="<?php echo sim_h($formData['domain']); ?>" class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div>
                            <label class="mb-2 block text-sm font-medium text-gray-700" for="query-text">目标查询关键词</label>
                            <textarea id="query-text" name="query_text" rows="3" class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500"><?php echo sim_h($formData['query_text']); ?></textarea>
                        </div>
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div>
                                <label class="mb-2 block text-sm font-medium text-gray-700" for="industry-context">行业</label>
                                <select id="industry-context" name="industry_context" class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500">
                                    <?php foreach ($industryOptions as $industry): ?>
                                        <option value="<?php echo sim_h($industry); ?>" <?php echo $formData['industry_context'] === $industry ? 'selected' : ''; ?>><?php echo sim_h($industry); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="mb-2 block text-sm font-medium text-gray-700" for="query-type">查询类型</label>
                                <select id="query-type" name="query_type" class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500">
                                    <?php foreach ($queryTypes as $type): ?>
                                        <option value="<?php echo sim_h($type); ?>" <?php echo $formData['query_type'] === $type ? 'selected' : ''; ?>><?php echo sim_h($type); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div>
                            <label class="mb-2 block text-sm font-medium text-gray-700" for="competitors">竞品/已知候选源</label>
                            <input id="competitors" name="competitors" type="text" value="<?php echo sim_h($formData['competitors']); ?>" class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500" placeholder="多个用逗号分隔">
                        </div>
                        <div>
                            <label class="mb-2 block text-sm font-medium text-gray-700">反查 AI</label>
                            <div class="grid grid-cols-2 gap-2">
                                <?php foreach ($aiProviders as $key => $label): ?>
                                    <label class="flex items-center gap-2 rounded-md border border-gray-200 px-3 py-2 text-sm text-gray-700">
                                        <input type="checkbox" name="ai_providers[]" value="<?php echo sim_h($key); ?>" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500" <?php echo in_array($key, (array) $formData['ai_providers'], true) ? 'checked' : ''; ?>>
                                        <span><?php echo sim_h($label); ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div>
                            <label class="mb-2 block text-sm font-medium text-gray-700" for="evidence">品牌资料</label>
                            <textarea id="evidence" name="evidence" rows="5" class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500" placeholder="官网简介、案例、媒体报道、平台账号、数据证明等"><?php echo sim_h($formData['evidence']); ?></textarea>
                        </div>
                        <button type="submit" class="inline-flex w-full items-center justify-center rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-gray-800">
                            <i data-lucide="search" class="mr-2 h-4 w-4"></i>
                            运行模拟
                        </button>
                    </form>
                </section>

                <section class="space-y-6 xl:col-span-2">
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-4">
                        <div class="rounded-lg border border-gray-200 bg-white px-5 py-4 shadow-sm">
                            <div class="text-sm font-medium text-gray-500">当前排名</div>
                            <div class="mt-2 text-4xl font-bold text-gray-900">#<?php echo (int) $result['client_current_rank']; ?></div>
                        </div>
                        <div class="rounded-lg border border-gray-200 bg-white px-5 py-4 shadow-sm">
                            <div class="text-sm font-medium text-gray-500">客户分数</div>
                            <div class="mt-2 text-4xl font-bold text-gray-900"><?php echo sim_h($result['client_current_score']); ?></div>
                        </div>
                        <div class="rounded-lg border border-gray-200 bg-white px-5 py-4 shadow-sm">
                            <div class="text-sm font-medium text-gray-500">引用集合容量</div>
                            <div class="mt-2 text-4xl font-bold text-gray-900">Top <?php echo (int) $result['citation_set_size']; ?></div>
                        </div>
                        <div class="rounded-lg border border-gray-200 bg-white px-5 py-4 shadow-sm">
                            <div class="text-sm font-medium text-gray-500">模拟后排名</div>
                            <div id="resulting-rank-card" class="mt-2 text-4xl font-bold text-blue-600">#<?php echo (int) $result['resulting_rank']; ?></div>
                        </div>
                    </div>

                    <section class="rounded-lg border border-gray-200 bg-white shadow-sm">
                        <div class="flex flex-col gap-2 border-b border-gray-200 px-6 py-4 md:flex-row md:items-center md:justify-between">
                            <div>
                                <h2 class="text-lg font-semibold text-gray-900">候选源排名</h2>
                                <p class="mt-1 text-sm text-gray-500"><?php echo sim_h($result['query_text']); ?></p>
                            </div>
                            <div class="text-sm text-gray-500">第 5 位后视为暂未进入稳定引用集合</div>
                        </div>
                        <div class="divide-y divide-gray-100 px-6 py-4">
                            <?php foreach ($result['sources'] as $source): ?>
                                <?php if ((int) $source['rank'] === 6): ?>
                                    <div class="flex items-center gap-3 py-3">
                                        <div class="h-px flex-1 border-t border-dashed border-gray-300"></div>
                                        <span class="rounded-full bg-gray-100 px-3 py-1 text-xs font-medium text-gray-500">引用集合分界线</span>
                                        <div class="h-px flex-1 border-t border-dashed border-gray-300"></div>
                                    </div>
                                <?php endif; ?>
                                <details class="group rounded-lg <?php echo $source['is_client'] ? 'border-2 border-blue-500 bg-blue-50/50' : 'border border-transparent bg-white'; ?> px-4 py-3">
                                    <summary class="flex cursor-pointer list-none items-center gap-4">
                                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full <?php echo $source['is_client'] ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-700'; ?> text-sm font-bold">#<?php echo (int) $source['rank']; ?></span>
                                        <div class="min-w-0 flex-1">
                                            <div class="flex flex-wrap items-center gap-2">
                                                <span class="font-semibold text-gray-900"><?php echo sim_h($source['source_display_name']); ?></span>
                                                <?php if ($source['is_client']): ?>
                                                    <span class="rounded-full bg-blue-600 px-2 py-0.5 text-xs font-medium text-white">你</span>
                                                <?php endif; ?>
                                                <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600"><?php echo sim_h(sim_type_label($source['source_type'])); ?></span>
                                                <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600"><?php echo sim_h($source['best_position_label']); ?></span>
                                            </div>
                                            <div class="mt-1 truncate text-sm text-gray-500"><?php echo sim_h($source['source_domain']); ?></div>
                                        </div>
                                        <div class="hidden text-right sm:block">
                                            <div class="text-xl font-bold text-gray-900"><?php echo sim_h($source['aggregated_score']); ?></div>
                                            <div class="text-xs text-gray-500"><?php echo (int) $source['citation_count']; ?> 家 AI 引用</div>
                                        </div>
                                        <i data-lucide="chevron-down" class="h-4 w-4 text-gray-400 transition group-open:rotate-180"></i>
                                    </summary>
                                    <div class="mt-4 rounded-md bg-white p-4 text-sm text-gray-600">
                                        <div class="font-medium text-gray-900">AI 为什么引用它</div>
                                        <p class="mt-2 leading-6"><?php echo sim_h($source['why']); ?></p>
                                        <div class="mt-3 flex flex-wrap gap-2">
                                            <?php foreach ($source['ai_providers'] as $provider): ?>
                                                <span class="rounded-full bg-gray-100 px-2 py-1 text-xs text-gray-600"><?php echo sim_h($aiProviders[$provider] ?? $provider); ?></span>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </details>
                            <?php endforeach; ?>
                        </div>
                    </section>

                    <section class="rounded-lg border border-gray-200 bg-white shadow-sm">
                        <div class="flex flex-col gap-3 border-b border-gray-200 px-6 py-4 md:flex-row md:items-center md:justify-between">
                            <div>
                                <h2 class="text-lg font-semibold text-gray-900">优化动作模拟</h2>
                                <p class="mt-1 text-sm text-gray-500">勾选动作后实时推演客户排名、分数、预算和周期。</p>
                            </div>
                            <div id="top5-badge" class="rounded-full px-3 py-1 text-sm font-medium <?php echo $result['enters_top_5'] ? 'bg-green-50 text-green-700' : 'bg-amber-50 text-amber-700'; ?>">
                                <?php echo $result['enters_top_5'] ? '可进入 Top 5' : '距离 Top 5 还差 ' . sim_h($result['gap_to_top_5']) . ' 分'; ?>
                            </div>
                        </div>
                        <form method="POST" class="px-6 py-6">
                            <input type="hidden" name="csrf_token" value="<?php echo sim_h(generate_csrf_token()); ?>">
                            <input type="hidden" name="brand_name" value="<?php echo sim_h($formData['brand_name']); ?>">
                            <input type="hidden" name="domain" value="<?php echo sim_h($formData['domain']); ?>">
                            <input type="hidden" name="industry_context" value="<?php echo sim_h($formData['industry_context']); ?>">
                            <input type="hidden" name="query_type" value="<?php echo sim_h($formData['query_type']); ?>">
                            <input type="hidden" name="query_text" value="<?php echo sim_h($formData['query_text']); ?>">
                            <input type="hidden" name="competitors" value="<?php echo sim_h($formData['competitors']); ?>">
                            <input type="hidden" name="evidence" value="<?php echo sim_h($formData['evidence']); ?>">
                            <?php foreach ($result['ai_providers'] as $provider): ?>
                                <input type="hidden" name="ai_providers[]" value="<?php echo sim_h($provider); ?>">
                            <?php endforeach; ?>
                            <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
                                <?php foreach ($actionDefs as $action): ?>
                                    <label class="sim-action flex cursor-pointer gap-3 rounded-lg border border-gray-200 p-4 hover:border-blue-300">
                                        <input type="checkbox" name="selected_actions[]" value="<?php echo sim_h($action['key']); ?>" class="sim-action-input mt-1 rounded border-gray-300 text-blue-600 focus:ring-blue-500" data-impact="<?php echo sim_h($action['base_impact']); ?>" data-cost="<?php echo sim_h($action['estimated_cost']); ?>" data-days="<?php echo sim_h($action['estimated_days']); ?>" <?php echo in_array($action['key'], $result['selected_actions'], true) ? 'checked' : ''; ?>>
                                        <span class="min-w-0 flex-1">
                                            <span class="block font-medium text-gray-900"><?php echo sim_h($action['label']); ?></span>
                                            <span class="mt-2 flex flex-wrap gap-2 text-xs text-gray-500">
                                                <span class="rounded-full bg-gray-100 px-2 py-1"><?php echo sim_h(citation_simulator_category_label($action['category'])); ?></span>
                                                <span class="rounded-full bg-blue-50 px-2 py-1 text-blue-700">+<?php echo sim_h($action['base_impact']); ?> 分</span>
                                                <span class="rounded-full bg-gray-100 px-2 py-1">¥<?php echo number_format((float) $action['estimated_cost']); ?></span>
                                                <span class="rounded-full bg-gray-100 px-2 py-1"><?php echo (int) $action['estimated_days']; ?> 天</span>
                                            </span>
                                        </span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <div class="mt-6 grid grid-cols-1 gap-4 rounded-lg bg-gray-50 p-4 md:grid-cols-4">
                                <div>
                                    <div class="text-sm text-gray-500">加分合计</div>
                                    <div id="boost-total" class="mt-1 text-2xl font-bold text-gray-900"><?php echo sim_h($result['boost_total']); ?></div>
                                </div>
                                <div>
                                    <div class="text-sm text-gray-500">模拟后分数</div>
                                    <div id="resulting-score" class="mt-1 text-2xl font-bold text-gray-900"><?php echo sim_h($result['resulting_score']); ?></div>
                                </div>
                                <div>
                                    <div class="text-sm text-gray-500">预估服务费</div>
                                    <div id="estimated-cost" class="mt-1 text-2xl font-bold text-gray-900">¥<?php echo number_format((float) $result['estimated_cost']); ?></div>
                                </div>
                                <div>
                                    <div class="text-sm text-gray-500">交付周期</div>
                                    <div id="estimated-days" class="mt-1 text-2xl font-bold text-gray-900"><?php echo (int) $result['estimated_days']; ?> 天</div>
                                </div>
                            </div>
                            <div class="mt-6 flex flex-col gap-3 sm:flex-row">
                                <button type="submit" name="action" value="save_simulation" class="inline-flex flex-1 items-center justify-center rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-blue-700">
                                    <i data-lucide="calculator" class="mr-2 h-4 w-4"></i>
                                    保存模拟结果
                                </button>
                                <button type="submit" name="action" value="dispatch_actions" class="inline-flex flex-1 items-center justify-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50">
                                    <i data-lucide="send" class="mr-2 h-4 w-4"></i>
                                    派发到任务管理
                                </button>
                            </div>
                        </form>
                    </section>
                </section>
            </div>
            </div><!-- /mode-single -->

            <!-- 批量关键词模式 -->
            <div id="mode-batch" <?php echo $activeMode === 'single' ? 'class="hidden"' : ''; ?>>
                <div class="grid grid-cols-1 gap-6 xl:grid-cols-3">

                    <!-- 批量设置表单 -->
                    <section class="rounded-lg border border-gray-200 bg-white shadow-sm xl:col-span-1">
                        <div class="border-b border-gray-200 px-6 py-4">
                            <h2 class="text-lg font-semibold text-gray-900">批量关键词设置</h2>
                            <p class="mt-1 text-sm text-gray-500">每行一个关键词，最多 20 个</p>
                        </div>
                        <form method="POST" class="space-y-5 px-6 py-6">
                            <input type="hidden" name="csrf_token" value="<?php echo sim_h(generate_csrf_token()); ?>">
                            <input type="hidden" name="action" value="run_batch">
                            <div>
                                <label class="mb-2 block text-sm font-medium text-gray-700" for="batch-brand-name">品牌名称</label>
                                <input id="batch-brand-name" name="brand_name" type="text" value="<?php echo sim_h($batchFormData['brand_name']); ?>" class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500">
                            </div>
                            <div>
                                <label class="mb-2 block text-sm font-medium text-gray-700" for="batch-domain">官网域名</label>
                                <input id="batch-domain" name="domain" type="text" value="<?php echo sim_h($batchFormData['domain']); ?>" class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500">
                            </div>
                            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <div>
                                    <label class="mb-2 block text-sm font-medium text-gray-700" for="batch-industry">行业</label>
                                    <select id="batch-industry" name="industry_context" class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500">
                                        <?php foreach ($industryOptions as $industry): ?>
                                            <option value="<?php echo sim_h($industry); ?>" <?php echo $batchFormData['industry_context'] === $industry ? 'selected' : ''; ?>><?php echo sim_h($industry); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div>
                                    <label class="mb-2 block text-sm font-medium text-gray-700" for="batch-query-type">查询类型</label>
                                    <select id="batch-query-type" name="query_type" class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500">
                                        <?php foreach ($queryTypes as $type): ?>
                                            <option value="<?php echo sim_h($type); ?>" <?php echo $batchFormData['query_type'] === $type ? 'selected' : ''; ?>><?php echo sim_h($type); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div>
                                <label class="mb-2 block text-sm font-medium text-gray-700" for="batch-competitors">竞品/已知候选源</label>
                                <input id="batch-competitors" name="competitors" type="text" value="<?php echo sim_h($batchFormData['competitors']); ?>" class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500" placeholder="多个用逗号分隔">
                            </div>
                            <div>
                                <label class="mb-2 block text-sm font-medium text-gray-700">反查 AI</label>
                                <div class="grid grid-cols-2 gap-2">
                                    <?php foreach ($aiProviders as $key => $label): ?>
                                        <label class="flex items-center gap-2 rounded-md border border-gray-200 px-3 py-2 text-sm text-gray-700">
                                            <input type="checkbox" name="ai_providers[]" value="<?php echo sim_h($key); ?>" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500" <?php echo in_array($key, (array) $batchFormData['ai_providers'], true) ? 'checked' : ''; ?>>
                                            <span><?php echo sim_h($label); ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <div>
                                <label class="mb-2 block text-sm font-medium text-gray-700" for="batch-keywords">
                                    目标关键词列表
                                    <span class="ml-1 text-xs font-normal text-gray-400">（每行一个，最多 20 个）</span>
                                </label>
                                <textarea id="batch-keywords" name="batch_keywords" rows="10"
                                    class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500 font-mono"
                                    placeholder="哪些 GEO 服务商适合帮助品牌提升 AI 引用率？&#10;长沙教培机构推荐&#10;知识付费平台比较&#10;..."><?php echo sim_h(implode("\n", array_column($batchResults, 'keyword'))); ?></textarea>
                                <p id="batch-kw-count" class="mt-1 text-xs text-gray-400">0 / 20 个关键词</p>
                            </div>
                            <div>
                                <label class="mb-2 block text-sm font-medium text-gray-700" for="batch-evidence">品牌资料</label>
                                <textarea id="batch-evidence" name="evidence" rows="3" class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500" placeholder="官网简介、案例、媒体报道等"><?php echo sim_h($batchFormData['evidence']); ?></textarea>
                            </div>
                            <button type="submit" class="inline-flex w-full items-center justify-center rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-gray-800">
                                <i data-lucide="play" class="mr-2 h-4 w-4"></i>
                                批量运行模拟
                            </button>
                        </form>
                    </section>

                    <!-- 批量结果 -->
                    <section class="space-y-6 xl:col-span-2">
                        <?php if (empty($batchResults)): ?>
                            <div class="flex h-64 flex-col items-center justify-center rounded-lg border border-dashed border-gray-300 bg-white text-center">
                                <i data-lucide="list" class="mb-3 h-10 w-10 text-gray-300"></i>
                                <p class="text-sm font-medium text-gray-500">在左侧填写关键词后运行批量模拟</p>
                                <p class="mt-1 text-xs text-gray-400">每个关键词独立跑一次反查，结果汇总在此</p>
                            </div>
                        <?php else: ?>
                            <!-- 汇总统计 -->
                            <div class="grid grid-cols-3 gap-4">
                                <?php
                                $inTop5 = count(array_filter($batchResults, fn($r) => $r['enters_top_5']));
                                $avgRank = round(array_sum(array_column($batchResults, 'rank')) / count($batchResults), 1);
                                $avgScore = round(array_sum(array_column($batchResults, 'score')) / count($batchResults), 1);
                                ?>
                                <div class="rounded-lg border border-gray-200 bg-white px-5 py-4 shadow-sm text-center">
                                    <div class="text-sm font-medium text-gray-500">已进入 Top 5</div>
                                    <div class="mt-2 text-3xl font-bold text-green-600"><?php echo $inTop5; ?> <span class="text-lg text-gray-400">/ <?php echo count($batchResults); ?></span></div>
                                </div>
                                <div class="rounded-lg border border-gray-200 bg-white px-5 py-4 shadow-sm text-center">
                                    <div class="text-sm font-medium text-gray-500">平均排名</div>
                                    <div class="mt-2 text-3xl font-bold text-gray-900">#<?php echo $avgRank; ?></div>
                                </div>
                                <div class="rounded-lg border border-gray-200 bg-white px-5 py-4 shadow-sm text-center">
                                    <div class="text-sm font-medium text-gray-500">平均分数</div>
                                    <div class="mt-2 text-3xl font-bold text-gray-900"><?php echo $avgScore; ?></div>
                                </div>
                            </div>

                            <!-- 关键词结果表 -->
                            <div class="rounded-lg border border-gray-200 bg-white shadow-sm">
                                <div class="border-b border-gray-200 px-6 py-4 flex items-center justify-between">
                                    <h2 class="text-lg font-semibold text-gray-900">各关键词模拟结果</h2>
                                    <span class="text-sm text-gray-500">共 <?php echo count($batchResults); ?> 个关键词</span>
                                </div>
                                <div class="overflow-x-auto">
                                    <table class="min-w-full divide-y divide-gray-200">
                                        <thead class="bg-gray-50">
                                            <tr>
                                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">关键词</th>
                                                <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">当前排名</th>
                                                <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">当前分数</th>
                                                <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">引用集容量</th>
                                                <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Top 5 状态</th>
                                                <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">操作</th>
                                            </tr>
                                        </thead>
                                        <tbody class="bg-white divide-y divide-gray-100">
                                            <?php foreach ($batchResults as $br): ?>
                                            <tr class="hover:bg-gray-50">
                                                <td class="px-4 py-3">
                                                    <div class="text-sm font-medium text-gray-900 max-w-xs truncate" title="<?php echo sim_h($br['keyword']); ?>"><?php echo sim_h($br['keyword']); ?></div>
                                                </td>
                                                <td class="px-4 py-3 text-center">
                                                    <span class="inline-flex h-8 w-8 items-center justify-center rounded-full text-sm font-bold <?php echo $br['rank'] <= 5 ? 'bg-blue-100 text-blue-700' : 'bg-gray-100 text-gray-600'; ?>">
                                                        #<?php echo $br['rank']; ?>
                                                    </span>
                                                </td>
                                                <td class="px-4 py-3 text-center">
                                                    <span class="text-sm font-semibold text-gray-900"><?php echo $br['score']; ?></span>
                                                </td>
                                                <td class="px-4 py-3 text-center">
                                                    <span class="text-sm text-gray-600">Top <?php echo $br['citation_set_size']; ?></span>
                                                </td>
                                                <td class="px-4 py-3 text-center">
                                                    <?php if ($br['enters_top_5']): ?>
                                                        <span class="inline-flex items-center gap-1 rounded-full bg-green-50 px-2.5 py-1 text-xs font-semibold text-green-700">
                                                            <i data-lucide="check" class="h-3 w-3"></i> 已进入
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2.5 py-1 text-xs font-semibold text-amber-700">
                                                            差 <?php echo sim_h($br['gap_to_top_5']); ?> 分
                                                        </span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="px-4 py-3 text-center">
                                                    <button type="button"
                                                        onclick="loadSingleKeyword(<?php echo htmlspecialchars(json_encode($br['keyword'], JSON_UNESCAPED_UNICODE), ENT_QUOTES); ?>)"
                                                        class="rounded-md border border-gray-300 px-3 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50">
                                                        单独分析
                                                    </button>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        <?php endif; ?>
                    </section>

                </div>
            </div><!-- /mode-batch -->

            <script>
                (function() {
                    const startingScore = <?php echo json_encode((float) $result['client_current_score']); ?>;
                    const otherScores = <?php echo json_encode($otherScores, JSON_UNESCAPED_UNICODE); ?>;
                    const topFiveScore = <?php echo json_encode(isset($result['sources'][4]) ? (float) $result['sources'][4]['aggregated_score'] : 0); ?>;
                    const inputs = Array.from(document.querySelectorAll('.sim-action-input'));
                    const boostEl = document.getElementById('boost-total');
                    const scoreEl = document.getElementById('resulting-score');
                    const rankEl = document.getElementById('resulting-rank-card');
                    const costEl = document.getElementById('estimated-cost');
                    const daysEl = document.getElementById('estimated-days');
                    const badgeEl = document.getElementById('top5-badge');

                    function rankFor(score) {
                        const scores = otherScores.concat([score]).sort((a, b) => b - a);
                        return scores.indexOf(score) + 1;
                    }

                    function updateSimulation() {
                        let boost = 0;
                        let cost = 0;
                        let days = 0;
                        inputs.forEach(input => {
                            input.closest('.sim-action')?.classList.toggle('border-blue-500', input.checked);
                            input.closest('.sim-action')?.classList.toggle('bg-blue-50', input.checked);
                            if (!input.checked) return;
                            boost += Number(input.dataset.impact || 0);
                            cost += Number(input.dataset.cost || 0);
                            days = Math.max(days, Number(input.dataset.days || 0));
                        });

                        const score = Math.min(98, Math.round((startingScore + boost) * 10) / 10);
                        const rank = rankFor(score);
                        if (boostEl) boostEl.textContent = String(boost);
                        if (scoreEl) scoreEl.textContent = String(score);
                        if (rankEl) rankEl.textContent = '#' + rank;
                        if (costEl) costEl.textContent = '¥' + new Intl.NumberFormat('zh-CN').format(cost);
                        if (daysEl) daysEl.textContent = days + ' 天';
                        if (badgeEl) {
                            const gap = Math.max(0, Math.round((topFiveScore - score + 0.1) * 10) / 10);
                            badgeEl.textContent = rank <= 5 ? '可进入 Top 5' : '距离 Top 5 还差 ' + gap + ' 分';
                            badgeEl.className = 'rounded-full px-3 py-1 text-sm font-medium ' + (rank <= 5 ? 'bg-green-50 text-green-700' : 'bg-amber-50 text-amber-700');
                        }
                    }

                    inputs.forEach(input => input.addEventListener('change', updateSimulation));
                    updateSimulation();
                })();

                // 模式切换
                function switchMode(mode) {
                    const single = document.getElementById('mode-single');
                    const batch  = document.getElementById('mode-batch');
                    const tabS   = document.getElementById('tab-single');
                    const tabB   = document.getElementById('tab-batch');
                    const activeClass   = ['bg-blue-600', 'text-white', 'shadow-sm'];
                    const inactiveClass = ['text-gray-600', 'hover:bg-gray-50', 'hover:text-gray-900'];
                    if (mode === 'single') {
                        single.classList.remove('hidden');
                        batch.classList.add('hidden');
                        tabS.classList.add(...activeClass);
                        tabS.classList.remove(...inactiveClass);
                        tabB.classList.remove(...activeClass);
                        tabB.classList.add(...inactiveClass);
                    } else {
                        single.classList.add('hidden');
                        batch.classList.remove('hidden');
                        tabB.classList.add(...activeClass);
                        tabB.classList.remove(...inactiveClass);
                        tabS.classList.remove(...activeClass);
                        tabS.classList.add(...inactiveClass);
                    }
                }

                // 已配置且正常的 API 模型默认收起，需要调整时再展开
                document.querySelectorAll('[data-provider-toggle]').forEach(button => {
                    button.addEventListener('click', () => {
                        const card = button.closest('[data-provider-card]');
                        const body = card?.querySelector('[data-provider-body]');
                        if (!body) return;

                        const isCollapsed = body.classList.toggle('hidden');
                        button.textContent = isCollapsed ? '展开配置' : '收起';
                        button.setAttribute('aria-expanded', isCollapsed ? 'false' : 'true');
                    });
                });

                // 批量关键词计数器
                (function() {
                    const ta = document.getElementById('batch-keywords');
                    const counter = document.getElementById('batch-kw-count');
                    if (!ta || !counter) return;
                    function updateCount() {
                        const lines = ta.value.split('\n').filter(l => l.trim() !== '');
                        const cnt = Math.min(lines.length, 20);
                        counter.textContent = cnt + ' / 20 个关键词';
                        counter.classList.toggle('text-red-500', lines.length > 20);
                        counter.classList.toggle('text-gray-400', lines.length <= 20);
                    }
                    ta.addEventListener('input', updateCount);
                    updateCount();
                })();

                // "单独分析"——把该关键词填入单关键词表单并切换模式
                function loadSingleKeyword(kw) {
                    const queryEl = document.getElementById('query-text');
                    if (queryEl) queryEl.value = kw;
                    switchMode('single');
                    queryEl?.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }

                function toggleApiConfig() {
                    const body     = document.getElementById('api-config-body');
                    const chevron  = document.getElementById('api-config-chevron');
                    const toggle   = document.getElementById('api-config-toggle');
                    const section  = document.getElementById('api-config-section');
                    const open     = toggle.getAttribute('aria-expanded') === 'true';

                    if (open) {
                        body.classList.add('hidden');
                        chevron.classList.remove('rotate-180');
                        toggle.classList.remove('border-b', 'border-gray-200');
                        toggle.setAttribute('aria-expanded', 'false');
                    } else {
                        body.classList.remove('hidden');
                        chevron.classList.add('rotate-180');
                        toggle.classList.add('border-b', 'border-gray-200');
                        toggle.setAttribute('aria-expanded', 'true');
                    }
                }
            </script>
<?php
require_once __DIR__ . '/includes/footer.php';
?>
