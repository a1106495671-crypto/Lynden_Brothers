<?php
/**
 * 智能GEO内容系统 - 管理后台首页
 */

define('FEISHU_TREASURE', true);
session_start();

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/database_admin.php';

// 检查管理员登录
require_admin_login();

// 立即释放session锁，允许其他页面并发访问
session_write_close();

// 设置页面标题
$page_title = '管理后台首页';
$additional_css = '<style>html,body{overflow-x:hidden}</style>';

$journey_stages = [
    [
        'no' => '01',
        'name' => '诊断',
        'tagline' => '让客户看见短板',
        'duration' => '1-3 天',
        'role' => '咨询顾问',
        'tool' => '雷达诊断',
        'link' => 'geo-diagnosis.php',
        'segment' => '获客转化',
        'color' => 'blue',
        'activities' => ['官网与全网提及基线扫描', '六维信号打分', '输出雷达图与短板报告', '对比同行业 TOP10 基线'],
        'output' => '品牌 GEO 诊断报告：雷达图、综合分、主要短板与优化方向',
        'pricing' => '可作为独立诊断项目，也可纳入后续 GEO 服务方案',
        'conversion' => '帮助客户先看清当前基础，再判断优先补强的方向',
    ],
    [
        'no' => '02',
        'name' => '模拟',
        'tagline' => '量化机会成本',
        'duration' => '1 天内',
        'role' => '咨询顾问',
        'tool' => '引用模拟器',
        'link' => 'citation-simulator.php',
        'segment' => '获客转化',
        'color' => 'blue',
        'activities' => ['导入 10-20 个目标关键词', '模拟 6 家中文 AI 引用结果', '展示当前引用集与客户排名', '勾选动作组合推演进入引用集路径'],
        'output' => '关键词引用现状报告：当前引用来源、品牌位置与优化路径推演',
        'pricing' => '可与诊断组合交付，也可作为关键词机会评估单独执行',
        'conversion' => '让客户明确目标关键词下的竞争格局和可提升空间',
    ],
    [
        'no' => '03',
        'name' => '策略',
        'tagline' => '定 AI 与平台组合',
        'duration' => '3-5 天',
        'role' => '策略师 + 行业顾问',
        'tool' => 'AI偏好对照表',
        'link' => 'ai-citation-preferences.php',
        'segment' => '策略成单',
        'color' => 'emerald',
        'activities' => ['按行业锁定优先 AI', '选择 5-8 个投放平台', '设计内容矩阵', '输出 90 天 GEO 路线图'],
        'output' => '90 天 GEO 执行路线图：优先 AI、重点平台、内容主题与节奏安排',
        'pricing' => '可作为策略规划服务交付，也可进入长期执行服务',
        'conversion' => '把诊断结果转化为可执行的内容与平台组合方案',
    ],
    [
        'no' => '04',
        'name' => '执行',
        'tagline' => '内容矩阵生产',
        'duration' => '3-12 个月',
        'role' => '内容团队 + 编辑',
        'tool' => '文章管理 / 媒体分发',
        'link' => 'articles.php',
        'secondary_link' => 'distribution.php',
        'segment' => '策略成单',
        'color' => 'emerald',
        'activities' => ['生产事实密度型内容', '多平台铺设内容资产', '争取主流媒体报道', '补齐出站链接、作者署名和编辑政策'],
        'output' => '结构化内容资产、平台分发记录、媒体报道与站点可信度补强清单',
        'pricing' => '按月度服务计划执行，内容数量和分发范围按方案确定',
        'conversion' => '持续补齐 AI 可引用的可信内容和第三方信号',
    ],
    [
        'no' => '05',
        'name' => '监测',
        'tagline' => '跟踪引用变化',
        'duration' => '每月持续',
        'role' => '数据分析师',
        'tool' => 'GEO监测',
        'link' => 'geo-monitor.php',
        'segment' => '续费增长',
        'color' => 'orange',
        'activities' => ['每周跑一次真实 AI 反查', '记录引用频率与引用位置', '监测竞品引用变化', '生成月度引用变化报告'],
        'output' => '月度引用监测报告：品牌引用频率、引用位置、竞品变化与风险提醒',
        'pricing' => '可作为月度监测服务，按监测范围和关键词数量配置',
        'conversion' => '持续跟踪 AI 引用变化，及时发现新的机会和风险',
    ],
    [
        'no' => '06',
        'name' => '续费',
        'tagline' => '基于数据再循环',
        'duration' => '到期前 2 周',
        'role' => '客户成功',
        'tool' => '雷达诊断对比',
        'link' => 'geo-diagnosis.php',
        'segment' => '续费增长',
        'color' => 'orange',
        'activities' => ['对比服务前后雷达图', '展示引用命中率提升曲线', '指出新短板与下季度机会', '推荐升级 SKU 或增项'],
        'output' => '季度复盘报告：前后对比、效果变化、新短板与下一阶段计划',
        'pricing' => '根据复盘结果调整下一阶段服务范围和优先级',
        'conversion' => '用数据复盘形成持续优化闭环，让 GEO 服务逐季迭代',
    ],
];

$journey_color_classes = [
    'blue' => [
        'card' => 'border-blue-200 bg-blue-50/60',
        'badge' => 'bg-blue-600 text-white',
        'text' => 'text-blue-700',
        'soft' => 'bg-blue-100 text-blue-700',
        'line' => 'bg-blue-600',
    ],
    'emerald' => [
        'card' => 'border-emerald-200 bg-emerald-50/60',
        'badge' => 'bg-emerald-600 text-white',
        'text' => 'text-emerald-700',
        'soft' => 'bg-emerald-100 text-emerald-700',
        'line' => 'bg-emerald-600',
    ],
    'orange' => [
        'card' => 'border-orange-200 bg-orange-50/60',
        'badge' => 'bg-orange-600 text-white',
        'text' => 'text-orange-700',
        'soft' => 'bg-orange-100 text-orange-700',
        'line' => 'bg-orange-600',
    ],
];

// GEO 交付指标 — 客户数据（与 customers.php 同步）
$customers = [
    [
        'id'              => 'wenyun-ai-reading',
        'name'            => '湖南文韵爱阅读',
        'industry'        => '教培 / 知识付费',
        'owner'           => '张顾问',
        'stage_key'       => 'execute',
        'stage_label'     => '第4阶段执行中',
        'overall_pct'     => 58,
        'service_status'  => 'active',
        'contract_end_at' => '2026-08-01',
        'alerts'  => ['本周需补齐百度文库与公众号长文入口', '竞品"心田花开"在 Kimi 长文问答中出现 2 次'],
        'pending' => ['完成 6 篇知识问答内容', '更新监测关键词组', '复盘本月引用来源变化'],
    ],
    [
        'id'              => 'dongluoji-mgeo',
        'name'            => '董逻辑 MGEO',
        'industry'        => 'B2B SaaS / 企业服务',
        'owner'           => '李策略',
        'stage_key'       => 'strategy',
        'stage_label'     => '第3阶段策略中',
        'overall_pct'     => 42,
        'service_status'  => 'active',
        'contract_end_at' => '2026-09-15',
        'alerts'  => ['AI 配置仍有 3 家平台缺少 API Key', 'B2B 权威内容包需要补 GitHub README 和案例页'],
        'pending' => ['完善客户中心上下文', '补齐监测 v2 页面', '梳理渠道版交付模板'],
    ],
    [
        'id'              => 'yimaitong-health',
        'name'            => '医脉通健康项目',
        'industry'        => '医疗 / 健康',
        'owner'           => '王运营',
        'stage_key'       => 'execute',
        'stage_label'     => '第4阶段执行中',
        'overall_pct'     => 51,
        'service_status'  => 'active',
        'contract_end_at' => '2026-07-20',
        'alerts'  => ['健康类内容需规避医疗承诺', '建议保留丁香医生、百度健康和知乎健康作为权威信源'],
        'pending' => ['补齐健康问答白名单', '确认内容合规边界', '更新媒体分发资源权重'],
    ],
    [
        'id'              => 'local-food-sample',
        'name'            => '本地生活餐饮样板',
        'industry'        => '本地生活 / 餐饮',
        'owner'           => '陈执行',
        'stage_key'       => 'diagnose',
        'stage_label'     => '第1阶段诊断中',
        'overall_pct'     => 18,
        'service_status'  => 'paused',
        'contract_end_at' => '2026-06-30',
        'alerts'  => ['客户暂缓执行，需确认是否恢复服务'],
        'pending' => ['完成品牌基础诊断', '整理本地生活信源', '确认预算'],
    ],
];

// ── 用 sop_node_status 覆盖 overall_pct ────────────────────────────────────
try {
    $_dashIds = array_column($customers, 'id');
    $_ph      = implode(',', array_fill(0, count($_dashIds), '?'));
    $_stmtD   = $db->prepare("
        SELECT customer_id,
               COUNT(*) FILTER (WHERE status = 'done') AS done_count
        FROM sop_node_status
        WHERE scenario = 'onboard' AND customer_id IN ($_ph)
        GROUP BY customer_id
    ");
    $_stmtD->execute($_dashIds);
    $_sopDone = [];
    foreach ($_stmtD->fetchAll(PDO::FETCH_ASSOC) as $_r) {
        $_sopDone[$_r['customer_id']] = (int) $_r['done_count'];
    }
    foreach ($customers as &$_dc) {
        if (!isset($_sopDone[$_dc['id']])) continue;
        $_dc['overall_pct'] = min(100, (int) round($_sopDone[$_dc['id']] / 14 * 100));
    }
    unset($_dc);
} catch (Throwable $_de) {}

// 聚合客户交付指标
$total_customers  = count($customers);
$active_customers = count(array_filter($customers, fn($c) => $c['service_status'] === 'active'));
$doing_customers  = count(array_filter($customers, fn($c) => in_array($c['stage_key'], ['strategy', 'execute', 'monitor'], true)));
$total_alerts     = array_sum(array_map(fn($c) => count($c['alerts']), $customers));
$expiring_soon    = count(array_filter($customers, fn($c) => strtotime($c['contract_end_at']) <= strtotime('+60 days')));

$stage_labels = [
    'diagnose' => '诊断', 'simulate' => '模拟', 'strategy' => '策略',
    'execute'  => '执行', 'monitor'  => '监测', 'renew'    => '续费',
];
$stage_colors = [
    'diagnose' => 'bg-blue-500',   'simulate' => 'bg-blue-400',
    'strategy' => 'bg-emerald-500','execute'  => 'bg-emerald-400',
    'monitor'  => 'bg-orange-500', 'renew'    => 'bg-orange-400',
];
$stage_counts = array_fill_keys(array_keys($stage_labels), 0);
foreach ($customers as $c) {
    if (isset($stage_counts[$c['stage_key']])) {
        $stage_counts[$c['stage_key']]++;
    }
}

// GEO 工具使用统计（从数据库，带安全回退）
$geo_tool_stats = ['total_diagnoses' => 0, 'total_sim_queries' => 0, 'total_monitor_alerts' => 0];
try {
    $geo_tool_stats['total_diagnoses'] = (int) $db->query("SELECT COUNT(*) FROM geo_diagnosis_runs")->fetchColumn();
} catch (Exception $e) {}
try {
    $geo_tool_stats['total_sim_queries'] = (int) $db->query("SELECT COUNT(*) FROM geo_simulator_queries")->fetchColumn();
} catch (Exception $e) {}
try {
    $geo_tool_stats['total_monitor_alerts'] = (int) $db->query("SELECT COUNT(*) FROM monitor_alerts WHERE resolved_at IS NULL")->fetchColumn();
} catch (Exception $e) {}

$quick_start_steps = [
    [
        'no' => '1',
        'title' => '接入客户与品牌资料',
        'desc' => '先建立客户上下文，把行业、竞品、目标关键词、知识库和素材库沉淀为可复用资产。',
        'icon' => 'building-2',
        'link' => 'customers.php',
        'button' => '进入客户中心',
        'tone' => 'blue',
    ],
    [
        'no' => '2',
        'title' => '跑诊断与策略路线',
        'desc' => '用雷达诊断、引用模拟和 AI 偏好对照表确认短板、机会词和平台优先级。',
        'icon' => 'radar',
        'link' => 'geo-diagnosis.php',
        'button' => '开始诊断',
        'tone' => 'emerald',
    ],
    [
        'no' => '3',
        'title' => '一键启动自动交付',
        'desc' => '从入驻自动化直接串起资料整理、策略生成、内容任务、分发与监测，不用每步手动切页面。',
        'icon' => 'play',
        'link' => 'automation-workflow.php',
        'button' => '一键运行',
        'tone' => 'slate',
    ],
];

$automation_nodes = [
    [
        'title' => '客户上下文',
        'desc' => '客户档案、行业边界、竞品与目标 AI 场景',
        'icon' => 'users',
        'link' => 'customers.php',
        'status' => $active_customers > 0 ? 'ready' : 'attention',
        'meta' => $active_customers . ' 个活跃客户',
        'tone' => 'blue',
    ],
    [
        'title' => '诊断 / 模拟',
        'desc' => '雷达分、引用现状、机会词和短板报告',
        'icon' => 'scan-search',
        'link' => 'geo-diagnosis.php',
        'status' => $geo_tool_stats['total_diagnoses'] > 0 ? 'ready' : 'attention',
        'meta' => ($geo_tool_stats['total_diagnoses'] ?: '待跑') . ' 次诊断',
        'tone' => 'violet',
    ],
    [
        'title' => '策略 SOP',
        'desc' => '90 天路线图、平台组合、主题矩阵和负责人',
        'icon' => 'book-open-check',
        'link' => 'sop-center.php',
        'status' => $stage_counts['strategy'] > 0 ? 'running' : 'ready',
        'meta' => $stage_counts['strategy'] . ' 个策略中',
        'tone' => 'emerald',
    ],
    [
        'title' => '一键执行',
        'desc' => '品牌入驻自动化统一拉起任务、内容、分发和状态回写',
        'icon' => 'workflow',
        'link' => 'automation-workflow.php',
        'status' => 'running',
        'meta' => $doing_customers . ' 个交付中',
        'tone' => 'blue',
        'primary' => true,
    ],
    [
        'title' => '内容生产',
        'desc' => '事实密度内容、问答稿、作者与知识库引用',
        'icon' => 'file-pen-line',
        'link' => 'articles.php',
        'status' => $stage_counts['execute'] > 0 ? 'running' : 'ready',
        'meta' => $stage_counts['execute'] . ' 个执行中',
        'tone' => 'emerald',
    ],
    [
        'title' => '媒体分发',
        'desc' => '站点包、渠道队列、远端同步与发布回调',
        'icon' => 'radio-tower',
        'link' => 'distribution.php',
        'status' => 'ready',
        'meta' => '可远程同步',
        'tone' => 'orange',
    ],
    [
        'title' => 'AI 监测',
        'desc' => '关键词引用率、竞品变化、告警与月度复盘',
        'icon' => 'activity',
        'link' => 'geo-monitor.php',
        'status' => $total_alerts > 0 ? 'attention' : 'ready',
        'meta' => $total_alerts . ' 个告警',
        'tone' => 'orange',
    ],
    [
        'title' => '续费复盘',
        'desc' => '前后雷达对比、引用提升曲线和下季度动作',
        'icon' => 'repeat-2',
        'link' => 'geo-roadmap.php',
        'status' => $expiring_soon > 0 ? 'attention' : 'ready',
        'meta' => $expiring_soon . ' 个临期',
        'tone' => 'slate',
    ],
];

$tone_classes = [
    'blue' => 'bg-blue-50 text-blue-700 border-blue-100',
    'emerald' => 'bg-emerald-50 text-emerald-700 border-emerald-100',
    'orange' => 'bg-orange-50 text-orange-700 border-orange-100',
    'violet' => 'bg-violet-50 text-violet-700 border-violet-100',
    'slate' => 'bg-slate-50 text-slate-700 border-slate-200',
];

$status_classes = [
    'ready' => 'bg-emerald-100 text-emerald-700',
    'running' => 'bg-blue-100 text-blue-700',
    'attention' => 'bg-orange-100 text-orange-700',
];

$status_labels = [
    'ready' => '可用',
    'running' => '运行中',
    'attention' => '需关注',
];

// 包含统一头部
require_once __DIR__ . '/includes/header.php';
?>
            <div class="px-4 sm:px-0">
            <div class="mb-8 flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <h1 class="text-3xl font-bold text-gray-900">GEO 服务工作台</h1>
                    <p class="mt-1 max-w-3xl text-sm leading-6 text-gray-600">
                        从客户诊断、策略 SOP、内容执行、媒体分发到 AI 监测，一页看清交付链路；需要开跑时，直接进入品牌入驻自动化一键执行。
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <button onclick="location.reload()" class="inline-flex h-10 items-center rounded-lg border border-gray-300 bg-white px-4 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50">
                        <i data-lucide="refresh-cw" class="mr-2 h-4 w-4"></i>
                        刷新
                    </button>
                    <a href="<?php echo htmlspecialchars(admin_url('automation-workflow.php')); ?>" class="inline-flex h-10 items-center rounded-lg bg-blue-600 px-4 text-sm font-semibold text-white shadow-sm hover:bg-blue-700">
                        <i data-lucide="play" class="mr-2 h-4 w-4"></i>
                        一键运行
                    </a>
                </div>
            </div>

            <section class="mb-8 overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                <div class="flex flex-col gap-4 border-b border-gray-100 px-6 py-5 lg:flex-row lg:items-start lg:justify-between">
                    <div>
                        <p class="text-xs font-semibold uppercase text-blue-600">快速启动</p>
                        <h2 class="mt-2 text-xl font-semibold text-gray-900">三步启动 GEO 客户自动交付</h2>
                        <p class="mt-2 max-w-3xl text-sm leading-6 text-gray-500">
                            借鉴 GEOFlow 的清晰入口，但这里突出我们的核心差异：不只是建任务，而是把客户入驻、诊断、策略、内容、分发和监测串成可点击执行的交付流。
                        </p>
                    </div>
                    <span class="inline-flex w-fit items-center rounded-full bg-emerald-100 px-3 py-1 text-xs font-semibold text-emerald-700">
                        <span class="mr-2 h-1.5 w-1.5 rounded-full bg-current"></span>
                        自动化能力可用
                    </span>
                </div>
                <div class="grid grid-cols-1 divide-y divide-gray-100 lg:grid-cols-3 lg:divide-x lg:divide-y-0">
                    <?php foreach ($quick_start_steps as $step): ?>
                        <?php $tone = $tone_classes[$step['tone']] ?? $tone_classes['slate']; ?>
                        <div class="p-6">
                            <div class="flex items-start gap-4">
                                <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full <?php echo $step['tone'] === 'slate' ? 'bg-slate-900' : 'bg-' . $step['tone'] . '-600'; ?> text-sm font-semibold text-white">
                                    <?php echo htmlspecialchars($step['no']); ?>
                                </div>
                                <div class="min-w-0">
                                    <div class="flex items-center gap-2">
                                        <i data-lucide="<?php echo htmlspecialchars($step['icon']); ?>" class="h-5 w-5 <?php echo explode(' ', $tone)[1] ?? 'text-gray-700'; ?>"></i>
                                        <h3 class="text-base font-semibold text-gray-900"><?php echo htmlspecialchars($step['title']); ?></h3>
                                    </div>
                                    <p class="mt-2 text-sm leading-6 text-gray-500"><?php echo htmlspecialchars($step['desc']); ?></p>
                                    <a href="<?php echo htmlspecialchars(admin_url($step['link'])); ?>" class="mt-4 inline-flex h-9 items-center rounded-lg <?php echo $step['tone'] === 'slate' ? 'border border-gray-300 bg-white text-gray-700 hover:bg-gray-50' : 'bg-blue-600 text-white hover:bg-blue-700'; ?> px-3 text-sm font-semibold">
                                        <?php echo htmlspecialchars($step['button']); ?>
                                        <i data-lucide="arrow-right" class="ml-1.5 h-4 w-4"></i>
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="mb-8 overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                <div class="flex flex-col gap-4 border-b border-gray-100 px-6 py-5 lg:flex-row lg:items-start lg:justify-between">
                    <div>
                        <h2 class="text-xl font-semibold text-gray-900">GEO+AI 自动化交付流</h2>
                        <p class="mt-2 max-w-4xl text-sm leading-6 text-gray-500">
                            系统按客户服务依赖关系串联后台能力：先做客户上下文和诊断，再落策略、内容、分发和监测。节点可点进对应模块，异常节点优先处理。
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <span class="inline-flex items-center rounded-full bg-blue-100 px-3 py-1 text-xs font-semibold text-blue-700">
                            <span class="mr-2 h-1.5 w-1.5 rounded-full bg-current"></span>
                            <?php echo $doing_customers; ?> 个流程运行中
                        </span>
                        <span class="inline-flex items-center rounded-full bg-orange-100 px-3 py-1 text-xs font-semibold text-orange-700">
                            <span class="mr-2 h-1.5 w-1.5 rounded-full bg-current"></span>
                            <?php echo $total_alerts + $expiring_soon; ?> 项需要关注
                        </span>
                    </div>
                </div>
                <div class="p-5">
                    <div class="mb-5 flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                        <div>
                            <h3 class="text-base font-semibold text-gray-900">自动化流水线</h3>
                            <p class="mt-1 text-sm leading-6 text-gray-500">从左到右是标准交付路径；“一键执行”是我们的主入口。</p>
                        </div>
                        <a href="<?php echo htmlspecialchars(admin_url('automation-workflow.php')); ?>" class="inline-flex h-9 w-fit items-center rounded-lg border border-gray-300 bg-white px-3 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                            <i data-lucide="settings-2" class="mr-2 h-4 w-4"></i>
                            自动化设置
                        </a>
                    </div>
                    <div class="relative grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
                        <div class="pointer-events-none absolute left-[8%] right-[8%] top-[42px] hidden h-0.5 bg-gradient-to-r from-blue-200 via-emerald-200 to-orange-200 xl:block"></div>
                        <?php foreach ($automation_nodes as $node): ?>
                            <?php
                            $tone = $tone_classes[$node['tone']] ?? $tone_classes['slate'];
                            $status = $status_classes[$node['status']] ?? $status_classes['ready'];
                            $is_primary = !empty($node['primary']);
                            ?>
                            <a href="<?php echo htmlspecialchars(admin_url($node['link'])); ?>" class="relative z-10 flex min-h-[178px] flex-col rounded-lg border <?php echo $is_primary ? 'border-blue-300 bg-blue-50/60 ring-1 ring-blue-200' : 'border-gray-200 bg-white'; ?> p-4 shadow-sm transition hover:-translate-y-0.5 hover:shadow-md">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg border <?php echo $tone; ?>">
                                        <i data-lucide="<?php echo htmlspecialchars($node['icon']); ?>" class="h-5 w-5"></i>
                                    </div>
                                    <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold <?php echo $status; ?>">
                                        <span class="mr-1.5 h-1.5 w-1.5 rounded-full bg-current"></span>
                                        <?php echo htmlspecialchars($status_labels[$node['status']] ?? '可用'); ?>
                                    </span>
                                </div>
                                <h3 class="mt-4 text-base font-semibold text-gray-900"><?php echo htmlspecialchars($node['title']); ?></h3>
                                <p class="mt-2 text-sm leading-6 text-gray-500"><?php echo htmlspecialchars($node['desc']); ?></p>
                                <div class="mt-auto flex items-center justify-between gap-3 pt-4">
                                    <span class="rounded-full border border-gray-200 bg-white px-2.5 py-1 text-xs font-semibold text-gray-600"><?php echo htmlspecialchars($node['meta']); ?></span>
                                    <i data-lucide="arrow-up-right" class="h-4 w-4 text-gray-400"></i>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>

            <section class="mb-8 grid grid-cols-1 gap-5 md:grid-cols-2 xl:grid-cols-4">
                <div class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
                    <div class="flex items-center justify-between gap-3">
                        <h3 class="text-base font-semibold text-gray-900">活跃客户</h3>
                        <i data-lucide="users" class="h-5 w-5 text-blue-600"></i>
                    </div>
                    <div class="mt-5 text-3xl font-bold text-gray-900"><?php echo $active_customers; ?></div>
                    <div class="mt-2 text-sm font-medium text-gray-500">共 <?php echo $total_customers; ?> 个客户档案</div>
                </div>
                <div class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
                    <div class="flex items-center justify-between gap-3">
                        <h3 class="text-base font-semibold text-gray-900">交付中</h3>
                        <i data-lucide="zap" class="h-5 w-5 text-emerald-600"></i>
                    </div>
                    <div class="mt-5 text-3xl font-bold text-gray-900"><?php echo $doing_customers; ?></div>
                    <div class="mt-2 text-sm font-medium text-gray-500">策略 / 执行 / 监测阶段</div>
                </div>
                <div class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
                    <div class="flex items-center justify-between gap-3">
                        <h3 class="text-base font-semibold text-gray-900">诊断资产</h3>
                        <i data-lucide="radar" class="h-5 w-5 text-violet-600"></i>
                    </div>
                    <div class="mt-5 text-3xl font-bold text-gray-900"><?php echo $geo_tool_stats['total_diagnoses'] ?: '—'; ?></div>
                    <div class="mt-2 text-sm font-medium text-gray-500">引用模拟 <?php echo $geo_tool_stats['total_sim_queries'] ?: '—'; ?> 次</div>
                </div>
                <div class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
                    <div class="flex items-center justify-between gap-3">
                        <h3 class="text-base font-semibold text-gray-900">待处理告警</h3>
                        <i data-lucide="bell-ring" class="h-5 w-5 text-orange-600"></i>
                    </div>
                    <div class="mt-5 text-3xl font-bold <?php echo $total_alerts > 0 ? 'text-orange-600' : 'text-gray-900'; ?>"><?php echo $total_alerts; ?></div>
                    <div class="mt-2 text-sm font-medium text-gray-500"><?php echo $expiring_soon; ?> 个客户 60 天内到期</div>
                </div>
            </section>

            <section class="grid grid-cols-1 gap-6 xl:grid-cols-3">
                <div class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
                    <h2 class="text-xl font-semibold text-gray-900">六阶段服务路径</h2>
                    <p class="mt-2 text-sm leading-6 text-gray-500">把客户从获客诊断带到执行、监测和续费复盘。</p>
                    <div class="mt-5 space-y-3">
                        <?php foreach ($journey_stages as $stage): ?>
                            <?php $colors = $journey_color_classes[$stage['color']]; ?>
                            <a href="<?php echo htmlspecialchars(admin_url($stage['link'])); ?>" class="flex items-center gap-3 rounded-lg border border-gray-100 bg-gray-50 p-3 transition hover:border-blue-100 hover:bg-blue-50">
                                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-xs font-bold <?php echo $colors['badge']; ?>"><?php echo htmlspecialchars($stage['no']); ?></span>
                                <span class="min-w-0 flex-1">
                                    <span class="block text-sm font-semibold text-gray-900"><?php echo htmlspecialchars($stage['name']); ?> · <?php echo htmlspecialchars($stage['tagline']); ?></span>
                                    <span class="mt-1 block truncate text-xs text-gray-500"><?php echo htmlspecialchars($stage['output']); ?></span>
                                </span>
                                <i data-lucide="chevron-right" class="h-4 w-4 text-gray-400"></i>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="xl:col-span-2 rounded-lg border border-gray-200 bg-white shadow-sm">
                    <div class="flex items-center justify-between border-b border-gray-200 px-5 py-4">
                        <div>
                            <h2 class="text-xl font-semibold text-gray-900">客户下一步动作</h2>
                            <p class="mt-1 text-sm text-gray-500">只保留首页需要看到的客户状态和关键行动项。</p>
                        </div>
                        <a href="<?php echo htmlspecialchars(admin_url('customers.php')); ?>" class="text-sm font-semibold text-blue-600 hover:text-blue-800">全部客户</a>
                    </div>
                    <div class="divide-y divide-gray-100">
                        <?php foreach ($customers as $c): ?>
                            <?php
                            $alert_cnt = count($c['alerts']);
                            $days_left = (int) round((strtotime($c['contract_end_at']) - time()) / 86400);
                            ?>
                            <a href="<?php echo htmlspecialchars(admin_url('customers.php?select=' . rawurlencode($c['id']))); ?>" class="grid gap-4 px-5 py-4 transition hover:bg-gray-50 lg:grid-cols-[minmax(0,1.2fr)_160px_minmax(0,1.4fr)_90px] lg:items-center">
                                <span class="min-w-0">
                                    <span class="block text-sm font-semibold text-gray-900"><?php echo htmlspecialchars($c['name']); ?></span>
                                    <span class="mt-1 block text-xs text-gray-500"><?php echo htmlspecialchars($c['industry']); ?> · <?php echo htmlspecialchars($c['owner']); ?></span>
                                </span>
                                <span>
                                    <span class="inline-flex items-center rounded-full bg-blue-50 px-2.5 py-1 text-xs font-semibold text-blue-700"><?php echo htmlspecialchars($c['stage_label']); ?></span>
                                    <span class="mt-2 flex items-center gap-2">
                                        <span class="h-2 flex-1 rounded-full bg-gray-100">
                                            <span class="block h-2 rounded-full bg-blue-500" style="width:<?php echo (int)$c['overall_pct']; ?>%"></span>
                                        </span>
                                        <span class="text-xs tabular-nums text-gray-500"><?php echo (int)$c['overall_pct']; ?>%</span>
                                    </span>
                                </span>
                                <span class="text-sm leading-6 text-gray-600">
                                    <?php echo htmlspecialchars($c['pending'][0] ?? ($c['alerts'][0] ?? '暂无待办')); ?>
                                </span>
                                <span class="flex items-center justify-start gap-2 lg:justify-end">
                                    <?php if ($alert_cnt > 0): ?>
                                        <span class="inline-flex h-7 min-w-7 items-center justify-center rounded-full bg-orange-100 px-2 text-xs font-bold text-orange-700"><?php echo $alert_cnt; ?></span>
                                    <?php endif; ?>
                                    <?php if ($days_left <= 60): ?>
                                        <span class="text-xs font-semibold text-orange-600">剩 <?php echo $days_left; ?> 天</span>
                                    <?php endif; ?>
                                    <i data-lucide="arrow-up-right" class="h-4 w-4 text-gray-400"></i>
                                </span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>
            </div>

<?php
// 包含统一底部
require_once __DIR__ . '/includes/footer.php';
?>
