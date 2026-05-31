<?php
/**
 * 智能GEO内容系统 - 后台公共头部
 *
 * @version 1.0
 * @date 2025-10-06
 */

// 确保已经包含必要的文件
if (!defined('FEISHU_TREASURE')) {
    die('Direct access not allowed');
}

$admin_site_name = function_exists('get_setting') ? get_setting('site_title', SITE_NAME) : SITE_NAME;
$current_admin = function_exists('get_current_admin') ? get_current_admin() : null;
$is_super_admin = function_exists('is_super_admin') ? is_super_admin() : false;
$admin_role_label = $is_super_admin ? '超级管理员' : '管理员';
$current_customer_context = $_SESSION['current_customer'] ?? null;

// 获取当前页面名称，用于高亮菜单
$current_page = basename($_SERVER['PHP_SELF']);

// 定义菜单项和子页面映射
$menu_items = [
    'customers.php' => ['name' => '客户中心', 'icon' => 'building-2'],
    'dashboard.php' => ['name' => '首页', 'icon' => 'home'],
    'geo-diagnosis.php' => ['name' => '雷达诊断', 'icon' => 'radar'],
    'ops-dashboard.php'   => ['name' => '运营大盘',   'icon' => 'chart-bar'],
    'data-analytics.php'  => ['name' => '数据分析',   'icon' => 'bar-chart-2'],
    'geo-panorama.php' => ['name' => '全景诊断', 'icon' => 'scan-search'],
    'geo-intent.php' => ['name' => '意图挖掘', 'icon' => 'search'],
    'geo-knowledge-graph.php' => ['name' => '知识图谱', 'icon' => 'git-branch'],
    'rag-test.php'            => ['name' => 'RAG 检索测试', 'icon' => 'flask-conical'],
    'geo-roadmap.php' => ['name' => '执行路线图', 'icon' => 'map'],
    'geo-monitor-dashboard.php' => ['name' => '监测大盘', 'icon' => 'activity'],
    'geo-content-queue.php' => ['name' => '生成队列', 'icon' => 'list-checks'],
    'client-manage.php' => ['name' => '客户门户', 'icon' => 'users'],
    'citation-simulator.php' => ['name' => '引用模拟器', 'icon' => 'quote'],
    'ai-citation-preferences.php' => ['name' => 'AI偏好对照表', 'icon' => 'table-2'],
    'tasks.php' => ['name' => '任务管理', 'icon' => 'zap'],
    'geo-content.php' => ['name' => '内容生成', 'icon' => 'pen-tool'],
    'articles.php' => ['name' => '文章管理', 'icon' => 'file-text'],
    'distribution.php' => ['name' => '分发管理', 'icon' => 'radio-tower'],
    'distribution-queue.php'    => ['name' => '分发队列',   'icon' => 'list-ordered'],
    'channel-site-package.php' => ['name' => '站点包',     'icon' => 'package'],
    'agent-keys.php'           => ['name' => 'Agent 密钥',  'icon' => 'key-round'],
    'sop-center.php' => ['name' => '策略中心', 'icon' => 'book-open-check'],
    'geo-monitor.php' => ['name' => 'GEO监测', 'icon' => 'radar'],
    'competitor-timeline.php' => ['name' => '竞品时间线', 'icon' => 'trending-up'],
    'materials.php' => ['name' => '素材管理', 'icon' => 'folder'],
    'ai-configurator.php' => ['name' => 'AI配置', 'icon' => 'cpu'],
    'site-settings.php' => ['name' => '网站设置', 'icon' => 'settings'],
    'security-settings.php' => ['name' => '安全管理', 'icon' => 'shield'],
    'automation-workflow.php' => ['name' => '品牌入驻自动化', 'icon' => 'workflow'],
    'dynamic-workflow.php'   => ['name' => '动态工作流',     'icon' => 'cpu'],
    'reference-finder.php'   => ['name' => '参考文章发现器', 'icon' => 'book-marked'],
    'ai-crawler-stats.php'   => ['name' => 'AI爬虫识别',    'icon' => 'bot'],
    'access-logs.php'        => ['name' => '访问日志',      'icon' => 'activity'],
    'theme-settings.php'     => ['name' => '前台主题',      'icon' => 'layout-template'],
    'geo-scorecard.php'      => ['name' => 'GEO质量看板',   'icon' => 'shield-check'],
    'geo-content-suggest.php'=> ['name' => 'GEO内容建议',   'icon' => 'lightbulb'],
];

if ($is_super_admin) {
    $menu_items['admin-users.php'] = ['name' => '管理员', 'icon' => 'users'];
}

$primary_nav_items = [
    ['type' => 'link', 'page' => 'dashboard.php', 'name' => '首页', 'icon' => 'home'],
    [
        'type' => 'dropdown',
        'name' => '客户运营',
        'icon' => 'building-2',
        'children' => [
            ['page' => 'customers.php',       'name' => '客户工作台', 'desc' => '客户档案、阶段进度与当前客户上下文'],
            ['page' => 'ops-dashboard.php',   'name' => '运营总览',   'desc' => '跨客户健康度、告警与内容产能'],
            ['page' => 'geo-roadmap.php',     'name' => '执行路线图', 'desc' => '把诊断和监测结果转为下一步动作'],
            ['page' => 'monthly-report.php',  'name' => '月度复盘',   'desc' => '沉淀客户阶段成果与续费材料'],
            ['page' => 'client-manage.php',   'name' => '客户门户',   'desc' => '开通客户自助数据看板账号'],
        ],
    ],
    [
        'type' => 'dropdown',
        'name' => '诊断',
        'icon' => 'radar',
        'children' => [
            ['page' => 'geo-diagnosis.php',         'name' => '雷达诊断',       'desc' => '品牌 GEO 权威性诊断'],
            ['page' => 'geo-panorama.php',           'name' => '全景诊断',       'desc' => 'AI可见度全景分析'],
            ['page' => 'geo-intent.php',             'name' => '意图挖掘',       'desc' => '发现AI问答意图空白'],
            ['page' => 'citation-simulator.php',     'name' => '引用模拟器',     'desc' => '关键词引用机会推演'],
            ['page' => 'reference-finder.php',       'name' => '参考文章发现器', 'desc' => '提炼高引用写作风格Meta-Prompt'],
        ],
    ],
    ['type' => 'link', 'page' => 'sop-center.php', 'name' => '策略', 'icon' => 'book-open-check'],
    [
        'type' => 'dropdown',
        'name' => '交付',
        'icon' => 'send',
        'children' => [
            ['page' => 'automation-workflow.php','name' => '品牌入驻自动化','desc' => '12步全链路自动化主入口'],
            ['page' => 'tasks.php',             'name' => '任务管理',   'desc' => '任务派发、内容生成与进度管理'],
            ['page' => 'materials.php',         'name' => '素材管理',   'desc' => '关键词、标题、图片和知识库'],
            ['page' => 'articles.php',          'name' => '文章管理',   'desc' => '内容生产与审核'],
            ['page' => 'distribution.php',      'name' => '分发管理',   'desc' => '目标站 Agent、文章分发队列与同步日志'],
        ],
    ],
    ['type' => 'link', 'page' => 'distribution.php', 'name' => '分发管理', 'icon' => 'radio-tower'],
    [
        'type' => 'dropdown',
        'name' => '监测',
        'icon' => 'activity',
        'children' => [
            ['page' => 'geo-monitor.php',          'name' => 'GEO监测',    'desc' => '关键词引用率实时监测'],
            ['page' => 'geo-monitor-dashboard.php','name' => '监测大盘',   'desc' => '多客户数据概览大屏'],
            ['page' => 'geo-scorecard.php',        'name' => 'GEO质量看板','desc' => '三维评分分布与低分预警'],
            ['page' => 'competitor-timeline.php',  'name' => '竞品对比',   'desc' => '品牌vs竞品提及率历史曲线'],
            ['page' => 'geo-article-impact.php',   'name' => '效果归因',   'desc' => '文章发布前后AI提及率对比'],
        ],
    ],
    ['type' => 'link', 'page' => 'ai-configurator.php', 'name' => 'AI配置', 'icon' => 'cpu'],
];

$settings_menu_items = [
    ['page' => 'site-settings.php', 'name' => '网站设置', 'icon' => 'settings', 'desc' => '站点与账号设置'],
    ['page' => 'client-manage.php', 'name' => '客户门户', 'icon' => 'users', 'desc' => '客户自助看板账号管理'],
    ['page' => 'security-settings.php', 'name' => '安全管理', 'icon' => 'shield', 'desc' => '权限与安全策略'],
];

if ($is_super_admin) {
    $settings_menu_items[] = ['page' => 'admin-users.php', 'name' => '管理员', 'icon' => 'users', 'desc' => '账号与操作日志'];
}

// 定义子页面与主菜单的映射关系
$sub_page_mapping = [
    // 任务管理相关页面
    'task-create.php' => 'tasks.php',
    'task-edit.php' => 'tasks.php',
    'task-execute.php' => 'tasks.php',

    // 文章管理相关页面
    'article-create.php' => 'articles.php',
    'article-edit.php' => 'articles.php',
    'article-view.php' => 'articles.php',
    'articles-review.php' => 'articles.php',
    'articles-trash.php' => 'articles.php',

    // 素材管理相关页面
    'authors.php' => 'materials.php',
    'keyword-libraries.php' => 'materials.php',
    'keyword-library-detail.php' => 'materials.php',
    'title-libraries.php' => 'materials.php',
    'title-library-ai-generate.php' => 'materials.php',
    'image-libraries.php' => 'materials.php',
    'image-library-detail.php' => 'materials.php',
    'knowledge-bases.php' => 'materials.php',
    'url-import.php' => 'materials.php',
    'url-import-preview.php' => 'materials.php',
    'url-import-history.php' => 'materials.php',

    // AI配置器相关页面
    'ai-models.php' => 'ai-configurator.php',
    'ai-prompts.php' => 'ai-configurator.php',
    'ai-special-prompts.php' => 'ai-configurator.php',
    'ai-config-backup.php' => 'ai-configurator.php',
    'ai-config-simple.php' => 'ai-configurator.php',

    // 管理员相关页面
    'admin-activity-logs.php' => 'admin-users.php',
    'api-tokens.php' => 'admin-users.php'
];

// 定义历史/兼容页面，避免和正式入口混淆
$legacy_pages = [
    'ai-config-backup.php' => 'AI 配置历史备份页，请优先使用“AI配置器”。',
    'ai-config-simple.php' => 'AI 配置简化页，请优先使用“AI配置器”。',
    'dashboard-backup.php' => '仪表盘历史备份页，请优先使用“首页”。',
    'dashboard-simple.php' => '仪表盘简化页，请优先使用“首页”。',
    'tasks-safe.php' => '任务管理兼容页，请优先使用“任务管理”。',
    'materials-new.php' => '素材管理过渡入口，请优先使用正式菜单入口。',
    'tasks-new.php' => '任务管理过渡入口，请优先使用正式菜单入口。',
    'articles-new.php' => '文章管理过渡入口，请优先使用正式菜单入口。',
    'authors-new.php' => '作者管理过渡入口，请优先使用正式菜单入口。',
    'ai-config-new.php' => 'AI 配置过渡入口，请优先使用正式菜单入口。',
    'login-new.php' => '登录过渡入口，请优先使用正式后台登录入口。',
    'minimal-test.php' => '测试页，仅用于排查，不属于正式后台功能。',
    'simple-test.php' => '测试页，仅用于排查，不属于正式后台功能。',
    'test-admin.php' => '测试页，仅用于排查，不属于正式后台功能。',
    'test-dashboard.php' => '测试页，仅用于排查，不属于正式后台功能。',
    'test-fixes.php' => '测试页，仅用于排查，不属于正式后台功能。',
    'test-navigation.php' => '测试页，仅用于排查，不属于正式后台功能。',
    'verify-fixes.php' => '验证页，仅用于排查，不属于正式后台功能。'
];

// 判断当前激活的菜单
function isActiveMenu($page, $current_page, $sub_page_mapping) {
    // 直接匹配
    if ($page === $current_page) {
        return true;
    }

    // 检查是否为子页面
    if (isset($sub_page_mapping[$current_page]) && $sub_page_mapping[$current_page] === $page) {
        return true;
    }

    return false;
}

function isActiveNavItem($item, $current_page, $sub_page_mapping) {
    if (($item['type'] ?? 'link') === 'dropdown') {
        foreach (($item['children'] ?? []) as $child) {
            if (isActiveMenu($child['page'], $current_page, $sub_page_mapping)) {
                return true;
            }
        }
        return false;
    }

    return isActiveMenu($item['page'], $current_page, $sub_page_mapping);
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($page_title) ? $page_title : '管理后台'; ?> - <?php echo htmlspecialchars($admin_site_name); ?></title>
    <script src="/admin/assets/js/tailwind.play-cdn.js"></script>
    
    <script src="/admin/assets/js/lucide.min.js"></script>
    <script src="/admin/assets/js/htmx.min.js"></script>
    <script src="/admin/assets/js/htmx-preload.min.js"></script>
    <style>
        #nav-progress{position:fixed;top:0;left:0;width:0;height:3px;background:#3b82f6;z-index:9999;transition:width .2s ease,opacity .3s ease;pointer-events:none}
        #nav-progress.done{width:100%;opacity:0}
    </style>
    <?php if (isset($additional_css)): ?>
        <?php echo $additional_css; ?>
    <?php endif; ?>
</head>
<body class="bg-gray-50" hx-boost="true" hx-ext="preload">
    <!-- 导航栏 -->
    <nav class="relative bg-white shadow-sm border-b z-50">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="relative flex h-16 items-center justify-between">
                <div class="flex shrink-0 items-center">
                    <!-- Logo -->
                    <a href="<?php echo htmlspecialchars(admin_url('customers.php')); ?>" class="shrink-0 text-xl font-semibold leading-tight text-gray-900"><?php echo htmlspecialchars($admin_site_name); ?></a>
                </div>
                    
                <!-- 主导航菜单 -->
                <nav class="absolute left-1/2 top-1/2 hidden -translate-x-1/2 -translate-y-1/2 items-center justify-center gap-1 whitespace-nowrap overflow-visible md:flex">
                        <?php foreach ($primary_nav_items as $item): ?>
                            <?php
                            $is_active = isActiveNavItem($item, $current_page, $sub_page_mapping);
                            $base_class = $is_active ? 'bg-blue-50 text-blue-600 font-medium' : 'text-gray-500 hover:bg-gray-50 hover:text-gray-700';
                            ?>
                            <?php if (($item['type'] ?? 'link') === 'dropdown'): ?>
                                <div class="group relative shrink-0">
                                    <button type="button"
                                            class="<?php echo $base_class; ?> inline-flex items-center rounded-md px-2.5 py-2 text-sm transition-colors duration-200">
                                        <?php echo htmlspecialchars($item['name']); ?>
                                        <i data-lucide="chevron-down" class="ml-1 h-3.5 w-3.5"></i>
                                    </button>
                                    <div class="invisible absolute left-0 top-full z-50 w-64 translate-y-0 rounded-lg border border-gray-200 bg-white py-2 opacity-0 shadow-lg transition group-hover:visible group-hover:opacity-100">
                                        <?php foreach (($item['children'] ?? []) as $child): ?>
                                            <a href="<?php echo htmlspecialchars(admin_url($child['page'])); ?>"
                                               preload
                                               class="<?php echo isActiveMenu($child['page'], $current_page, $sub_page_mapping) ? 'bg-blue-50 text-blue-700' : 'text-gray-700 hover:bg-gray-50'; ?> block px-4 py-3 transition-colors">
                                                <span class="block text-sm font-semibold"><?php echo htmlspecialchars($child['name']); ?></span>
                                                <span class="mt-0.5 block text-xs text-gray-500"><?php echo htmlspecialchars($child['desc']); ?></span>
                                            </a>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php else: ?>
                                <a href="<?php echo htmlspecialchars(admin_url($item['page'])); ?>"
                                   preload
                                   class="<?php echo $base_class; ?> shrink-0 rounded-md px-2.5 py-2 text-sm transition-colors duration-200">
                                    <?php echo htmlspecialchars($item['name']); ?>
                                </a>
                            <?php endif; ?>
                        <?php endforeach; ?>
                </nav>
                
                <!-- 右侧用户信息 -->
                <div class="flex shrink-0 items-center gap-1.5">
                    <!-- 图标组 -->
                    <div class="flex items-center gap-0.5">
                        <!-- 搜索 -->
                        <button onclick="openSearchModal()" class="rounded-md p-2 text-gray-400 hover:text-gray-600 hover:bg-gray-50 transition-colors" title="搜索">
                            <i data-lucide="search" class="w-5 h-5"></i>
                        </button>
                        <!-- 通知 -->
                        <button class="rounded-md p-2 text-gray-400 hover:text-gray-600 hover:bg-gray-50 transition-colors" title="通知">
                            <i data-lucide="bell" class="w-5 h-5"></i>
                        </button>
                        <!-- 自动化快捷入口 -->
                        <a href="<?php echo htmlspecialchars(admin_url('automation-workflow.php')); ?>"
                           class="relative rounded-md p-2 text-violet-500 hover:text-violet-700 hover:bg-violet-50 transition-colors"
                           title="品牌入驻自动化">
                            <i data-lucide="workflow" class="w-5 h-5"></i>
                        </a>
                    </div>

                    <!-- 分隔线 -->
                    <div class="h-6 w-px bg-gray-200 mx-1"></div>

                    <!-- 用户信息 -->
                    <div class="flex items-center gap-2">
                        <div class="text-right hidden md:block">
                            <div class="text-sm text-gray-600"><?php echo htmlspecialchars($current_admin['username'] ?? ($_SESSION['admin_username'] ?? 'Admin')); ?></div>
                            <div class="text-[10px] text-gray-400"><?php echo htmlspecialchars($admin_role_label); ?></div>
                        </div>
                        <div class="relative">
                            <button onclick="toggleUserMenu()" class="flex items-center gap-1 rounded-md p-1 text-gray-600 hover:text-gray-900 hover:bg-gray-50 transition-colors">
                                <div class="w-7 h-7 bg-blue-100 rounded-full flex items-center justify-center">
                                    <i data-lucide="user" class="w-3.5 h-3.5 text-blue-600"></i>
                                </div>
                                <i data-lucide="chevron-down" class="w-3.5 h-3.5"></i>
                            </button>
                            
                            <!-- 用户下拉菜单 -->
                            <div id="user-menu" class="hidden absolute right-0 mt-2 w-48 bg-white rounded-md shadow-lg py-1 z-50">
                                <a href="<?php echo htmlspecialchars(admin_url('customers.php')); ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                                    <i data-lucide="home" class="w-4 h-4 inline mr-2"></i>
                                    返回客户中心
                                </a>
                                <div class="border-t border-gray-100 my-1"></div>
                                <div class="px-4 py-1 text-xs font-semibold text-gray-400">设置</div>
                                <?php foreach ($settings_menu_items as $settings_item): ?>
                                    <a href="<?php echo htmlspecialchars(admin_url($settings_item['page'])); ?>"
                                       class="<?php echo isActiveMenu($settings_item['page'], $current_page, $sub_page_mapping) ? 'bg-blue-50 text-blue-700' : 'text-gray-700 hover:bg-gray-100'; ?> block px-4 py-2 text-sm">
                                        <i data-lucide="<?php echo htmlspecialchars($settings_item['icon']); ?>" class="w-4 h-4 inline mr-2"></i>
                                        <?php echo htmlspecialchars($settings_item['name']); ?>
                                    </a>
                                <?php endforeach; ?>
                                <?php if ($is_super_admin): ?>
                                    <a href="<?php echo htmlspecialchars(admin_url('admin-activity-logs.php')); ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                                        <i data-lucide="clipboard-list" class="w-4 h-4 inline mr-2"></i>
                                        操作日志
                                    </a>
                                    <a href="<?php echo htmlspecialchars(admin_url('api-tokens.php')); ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                                        <i data-lucide="key-round" class="w-4 h-4 inline mr-2"></i>
                                        API Tokens
                                    </a>
                                <?php endif; ?>
                                <div class="border-t border-gray-100"></div>
                                <a href="<?php echo htmlspecialchars(admin_url('logout.php')); ?>" class="block px-4 py-2 text-sm text-red-600 hover:bg-gray-100">
                                    <i data-lucide="log-out" class="w-4 h-4 inline mr-2"></i>
                                    退出登录
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- 移动端菜单 -->
        <div id="mobile-menu" class="hidden md:hidden">
            <div class="px-2 pt-2 pb-3 space-y-1 sm:px-3 bg-gray-50 border-t">
                <!-- 自动化入口 (移动端) -->
                <a href="<?php echo htmlspecialchars(admin_url('automation-workflow.php')); ?>"
                   class="flex items-center gap-2 rounded-md bg-gradient-to-r from-violet-600 to-indigo-600 px-3 py-2.5 text-sm font-semibold text-white transition">
                    <i data-lucide="workflow" class="w-4 h-4"></i>
                    品牌入驻自动化
                </a>
                <div class="border-t border-gray-200 my-1"></div>
                <?php foreach ($primary_nav_items as $item): ?>
                    <?php if (($item['type'] ?? 'link') === 'dropdown'): ?>
                        <div class="px-3 pt-3 pb-1 text-xs font-semibold text-gray-400"><?php echo htmlspecialchars($item['name']); ?></div>
                        <?php foreach (($item['children'] ?? []) as $child): ?>
                            <a href="<?php echo htmlspecialchars(admin_url($child['page'])); ?>"
                               class="<?php echo isActiveMenu($child['page'], $current_page, $sub_page_mapping) ? 'bg-blue-100 text-blue-600' : 'text-gray-600 hover:bg-gray-100'; ?> block rounded-md px-6 py-2 text-sm font-medium transition-colors duration-200">
                                <?php echo htmlspecialchars($child['name']); ?>
                            </a>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <a href="<?php echo htmlspecialchars(admin_url($item['page'])); ?>"
                           class="<?php echo isActiveMenu($item['page'], $current_page, $sub_page_mapping) ? 'bg-blue-100 text-blue-600' : 'text-gray-600 hover:bg-gray-100'; ?> block px-3 py-2 rounded-md text-base font-medium transition-colors duration-200">
                            <i data-lucide="<?php echo htmlspecialchars($item['icon']); ?>" class="w-4 h-4 inline mr-2"></i>
                            <?php echo htmlspecialchars($item['name']); ?>
                        </a>
                    <?php endif; ?>
                <?php endforeach; ?>
                <div class="border-t border-gray-200 pt-2 mt-2">
                    <div class="px-3 py-1 text-xs font-semibold text-gray-400">设置</div>
                    <?php foreach ($settings_menu_items as $settings_item): ?>
                        <a href="<?php echo htmlspecialchars(admin_url($settings_item['page'])); ?>"
                           class="<?php echo isActiveMenu($settings_item['page'], $current_page, $sub_page_mapping) ? 'bg-blue-100 text-blue-600' : 'text-gray-600 hover:bg-gray-100'; ?> block px-3 py-2 rounded-md text-sm font-medium transition-colors duration-200">
                            <i data-lucide="<?php echo htmlspecialchars($settings_item['icon']); ?>" class="w-4 h-4 inline mr-2"></i>
                            <?php echo htmlspecialchars($settings_item['name']); ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </nav>

    <!-- 搜索弹框 -->
    <div id="search-modal" class="fixed inset-0 z-[60] hidden">
        <div class="absolute inset-0 bg-gray-900/40 backdrop-blur-sm" onclick="closeSearchModal()"></div>
        <div class="absolute left-1/2 top-[20%] -translate-x-1/2 w-full max-w-xl px-4">
            <div class="rounded-xl bg-white shadow-2xl border border-gray-200 overflow-hidden">
                <form action="<?php echo htmlspecialchars(admin_url('search.php')); ?>" method="GET">
                    <div class="flex items-center gap-3 px-4 py-3">
                        <i data-lucide="search" class="h-5 w-5 text-gray-400 shrink-0"></i>
                        <input type="text" name="q" id="search-modal-input"
                            placeholder="搜索客户、文章、任务..."
                            class="flex-1 text-base text-gray-900 placeholder-gray-400 outline-none bg-transparent"
                            autocomplete="off"
                            onkeydown="if(event.key==='Escape')closeSearchModal()">
                        <kbd class="hidden sm:inline-flex items-center rounded border border-gray-200 bg-gray-50 px-1.5 py-0.5 text-[10px] font-medium text-gray-400">ESC</kbd>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php if (!empty($current_customer_context) && is_array($current_customer_context)): ?>
        <div class="border-b border-slate-200 bg-slate-900 text-white">
            <div class="max-w-7xl mx-auto flex flex-col gap-2 px-4 py-3 text-sm sm:px-6 lg:px-8 md:flex-row md:items-center md:justify-between">
                <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                    <span class="font-semibold">当前客户：<?php echo htmlspecialchars($current_customer_context['name'] ?? '未命名客户'); ?></span>
                    <span class="rounded-full bg-white/10 px-3 py-1 text-xs text-slate-200"><?php echo htmlspecialchars($current_customer_context['industry'] ?? '行业未设置'); ?></span>
                    <span class="rounded-full bg-white/10 px-3 py-1 text-xs text-slate-200"><?php echo htmlspecialchars($current_customer_context['package_label'] ?? ($current_customer_context['package_tier'] ?? '套餐未设置')); ?></span>
                    <span class="rounded-full bg-blue-500/25 px-3 py-1 text-xs text-blue-100">整体 <?php echo (int)($current_customer_context['overall_pct'] ?? 0); ?>%</span>
                    <span class="text-xs text-slate-300"><?php echo htmlspecialchars($current_customer_context['stage_label'] ?? '阶段待确认'); ?></span>
                </div>
                <div class="flex items-center gap-2">
                    <a href="<?php echo htmlspecialchars(admin_url('customers.php?switch_customer=1#customer-switcher')); ?>" class="rounded-md border border-white/20 px-3 py-1.5 text-xs font-medium text-white hover:bg-white/10">切换客户</a>
                    <a href="<?php echo htmlspecialchars(admin_url('customers.php?clear_customer=1')); ?>" class="rounded-md bg-white px-3 py-1.5 text-xs font-medium text-slate-900 hover:bg-slate-100">退出客户</a>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- 移动端菜单按钮 -->
    <div class="md:hidden fixed top-4 right-4 z-50">
        <button onclick="toggleMobileMenu()" class="bg-white p-2 rounded-md shadow-md">
            <i data-lucide="menu" class="w-5 h-5 text-gray-600"></i>
        </button>
    </div>

    <!-- 主要内容区域开始 -->
    <div class="max-w-7xl mx-auto py-6 sm:px-6 lg:px-8">
        
        <!-- 消息提示区域 -->
        <?php if (isset($message) && !empty($message)): ?>
            <div class="admin-flash-alert mb-4 bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative">
                <span class="block sm:inline"><?php echo htmlspecialchars($message); ?></span>
                <button onclick="this.parentElement.style.display='none'" class="absolute top-0 bottom-0 right-0 px-4 py-3">
                    <i data-lucide="x" class="w-4 h-4"></i>
                </button>
            </div>
        <?php endif; ?>
        
        <?php if (isset($error) && !empty($error)): ?>
            <div class="admin-flash-alert mb-4 bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative">
                <span class="block sm:inline"><?php echo htmlspecialchars($error); ?></span>
                <button onclick="this.parentElement.style.display='none'" class="absolute top-0 bottom-0 right-0 px-4 py-3">
                    <i data-lucide="x" class="w-4 h-4"></i>
                </button>
            </div>
        <?php endif; ?>

        <?php if (isset($legacy_pages[$current_page])): ?>
            <div class="mb-4 bg-amber-50 border border-amber-300 text-amber-900 px-4 py-3 rounded-lg">
                <div class="flex items-start gap-3">
                    <i data-lucide="triangle-alert" class="w-5 h-5 mt-0.5 text-amber-600"></i>
                    <div>
                        <div class="font-semibold">当前页面属于历史/兼容页面</div>
                        <div class="text-sm mt-1"><?php echo htmlspecialchars($legacy_pages[$current_page]); ?></div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- 页面标题区域 -->
        <?php if (isset($page_header) && $page_header): ?>
            <div class="mb-8">
                <?php echo $page_header; ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($_GET['from_sop']) && !empty($_GET['sop_code'])): ?>
        <?php
            $sopCode       = htmlspecialchars($_GET['sop_code']       ?? '', ENT_QUOTES);
            $sopName       = htmlspecialchars($_GET['sop_name']        ?? '', ENT_QUOTES);
            $sopScenario   = htmlspecialchars($_GET['sop_scenario']    ?? '', ENT_QUOTES);
            $sopKpi        = htmlspecialchars($_GET['sop_kpi']         ?? '', ENT_QUOTES);
            $sopDeliverable= htmlspecialchars($_GET['sop_deliverable'] ?? '', ENT_QUOTES);
        ?>
        <div class="mb-6 rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 text-sm">
            <div class="flex items-start gap-3">
                <i data-lucide="send" class="mt-0.5 h-4 w-4 shrink-0 text-blue-500"></i>
                <div class="flex-1">
                    <div class="font-semibold text-blue-800">来自 SOP 派发<?php if ($sopScenario): ?> · <?php echo $sopScenario; ?><?php endif; ?></div>
                    <div class="mt-1 font-bold text-blue-900"><?php echo $sopCode; ?> <?php echo $sopName; ?></div>
                    <?php if ($sopKpi): ?><div class="mt-1 text-blue-700">KPI：<?php echo $sopKpi; ?></div><?php endif; ?>
                    <?php if ($sopDeliverable): ?><div class="mt-1 text-blue-700">产出物：<?php echo $sopDeliverable; ?></div><?php endif; ?>
                </div>
                <a href="sop-center.php" class="shrink-0 text-xs font-medium text-blue-600 hover:underline">← 返回 SOP</a>
            </div>
        </div>
        <?php endif; ?>

    <script>
        // 初始化Lucide图标
        document.addEventListener('DOMContentLoaded', function() {
            if (typeof lucide !== 'undefined') {
                lucide.createIcons();
            }
        });

        // 搜索弹框
        function openSearchModal() {
            document.getElementById('search-modal').classList.remove('hidden');
            setTimeout(function() {
                document.getElementById('search-modal-input').focus();
            }, 50);
        }
        function closeSearchModal() {
            document.getElementById('search-modal').classList.add('hidden');
        }
        // Ctrl/Cmd + K 打开搜索
        document.addEventListener('keydown', function(e) {
            if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
                e.preventDefault();
                openSearchModal();
            }
        });

        // 切换用户菜单
        function toggleUserMenu() {
            const menu = document.getElementById('user-menu');
            menu.classList.toggle('hidden');
        }

        // 切换移动端菜单
        function toggleMobileMenu() {
            const menu = document.getElementById('mobile-menu');
            menu.classList.toggle('hidden');
        }

        // 点击外部关闭菜单
        document.addEventListener('click', function(event) {
            const userMenu = document.getElementById('user-menu');
            const mobileMenu = document.getElementById('mobile-menu');
            
            if (!event.target.closest('[onclick="toggleUserMenu()"]') && !userMenu.contains(event.target)) {
                userMenu.classList.add('hidden');
            }
            
            if (!event.target.closest('[onclick="toggleMobileMenu()"]') && !mobileMenu.contains(event.target)) {
                mobileMenu.classList.add('hidden');
            }
        });

        // 自动隐藏消息提示
        setTimeout(function() {
            const alerts = document.querySelectorAll('.admin-flash-alert');
            alerts.forEach(function(alert) {
                if (alert.style.display !== 'none') {
                    alert.style.opacity = '0';
                    setTimeout(function() {
                        alert.style.display = 'none';
                    }, 300);
                }
            });
        }, 5000);
    </script>
