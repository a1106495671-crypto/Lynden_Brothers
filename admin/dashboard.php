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

$automationMediaAccounts = [];
try {
    $automationMediaAccounts = $db->query("
        SELECT id, platform, account_name, publish_mode, status
        FROM media_accounts
        WHERE status = 'active'
        ORDER BY platform, id
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $_mediaAccountError) {}

$quick_start_steps = [
    [
        'no' => '1',
        'title' => '配置 API',
        'desc' => '添加至少一个可用的聊天模型；如果需要知识库 RAG 召回，再配置一个 embedding 模型。',
        'icon' => 'plug',
        'link' => 'ai-configurator.php',
        'button' => '配置 AI 模型',
        'tone' => 'blue',
    ],
    [
        'no' => '2',
        'title' => '配置素材库',
        'desc' => '把真实、可靠的业务资料沉淀为素材库，任务生成时优先使用这些内容。',
        'icon' => 'database',
        'link' => 'materials.php',
        'chips' => [
            ['label' => '知识库', 'link' => 'knowledge-bases.php', 'class' => 'border-orange-100 bg-orange-50 text-orange-700 hover:bg-orange-100'],
            ['label' => '标题库', 'link' => 'chunk-library-generate.php?focus=titles', 'class' => 'border-green-100 bg-green-50 text-green-700 hover:bg-green-100'],
            ['label' => '关键词库', 'link' => 'chunk-library-generate.php?focus=keywords', 'class' => 'border-blue-100 bg-blue-50 text-blue-700 hover:bg-blue-100'],
            ['label' => '图片库', 'link' => 'image-libraries.php', 'class' => 'border-purple-100 bg-purple-50 text-purple-700 hover:bg-purple-100'],
            ['label' => '作者', 'link' => 'authors.php', 'class' => 'border-slate-200 bg-slate-50 text-slate-700 hover:bg-slate-100'],
        ],
        'tone' => 'emerald',
    ],
    [
        'no' => '3',
        'title' => '新建任务',
        'desc' => '选择标题库、素材和模型，设置生成数量与发布频率，系统自动生成并发布内容。',
        'icon' => 'plus',
        'link' => 'tasks.php',
        'button' => '新建任务',
        'tone' => 'slate',
    ],
];

$automation_nodes = [
    [
        'title' => 'S1 配置 API 与模型',
        'desc' => '先配置可用 Chat 模型和 Embedding 模型',
        'icon' => 'cpu',
        'link' => 'ai-configurator.php',
        'status' => 'attention',
        'meta' => '人工配置',
        'tone' => 'orange',
    ],
    [
        'title' => 'S2 上传内容资产',
        'desc' => '上传业务资料、案例、FAQ、产品文档和素材',
        'icon' => 'folder-up',
        'link' => 'materials.php',
        'status' => 'attention',
        'meta' => '素材入库',
        'tone' => 'orange',
    ],
    [
        'title' => 'S3 内容切割与向量化',
        'desc' => '按结构化规则切片，并写入 Embedding 向量',
        'icon' => 'scissors',
        'link' => 'materials.php',
        'status' => 'attention',
        'meta' => '知识库中枢',
        'tone' => 'orange',
    ],
    [
        'title' => 'S4 补齐关键词/标题库',
        'desc' => '准备关键词库、标题库、图片库和作者资料',
        'icon' => 'library',
        'link' => 'materials.php',
        'status' => 'attention',
        'meta' => '人工校准',
        'tone' => 'orange',
    ],
    [
        'title' => 'S5 填写基准问答',
        'desc' => '手动跑首次 AI 问答采样，补录问题和原始答案',
        'icon' => 'message-square-text',
        'link' => 'geo-diagnosis.php',
        'status' => 'attention',
        'meta' => '人工采样',
        'tone' => 'orange',
    ],
    [
        'title' => 'S6 确认雷达诊断',
        'desc' => '基于知识库和基准问答确认六维评分',
        'icon' => 'radar',
        'link' => 'geo-diagnosis.php',
        'status' => $geo_tool_stats['total_diagnoses'] > 0 ? 'ready' : 'attention',
        'meta' => ($geo_tool_stats['total_diagnoses'] ?: '待跑') . ' 次诊断',
        'tone' => 'violet',
    ],
    [
        'title' => 'S7 生成知识图谱',
        'desc' => '从已向量化知识库中抽取可引用事实条目',
        'icon' => 'network',
        'link' => 'materials.php',
        'status' => 'ready',
        'meta' => '事实条目',
        'tone' => 'emerald',
    ],
    [
        'title' => 'S8 意图挖掘',
        'desc' => '基于客户资料和知识库发现真实问题空白',
        'icon' => 'crosshair',
        'link' => 'geo-diagnosis.php',
        'status' => 'ready',
        'meta' => '问题空白',
        'tone' => 'violet',
    ],
    [
        'title' => 'S9 创建并启动任务',
        'desc' => '选择知识库、标题库、模型、数量和发布范围',
        'icon' => 'zap',
        'link' => 'tasks.php',
        'status' => $doing_customers > 0 ? 'running' : 'ready',
        'meta' => $doing_customers . ' 个交付中',
        'tone' => 'emerald',
    ],
    [
        'title' => 'S10 首篇文章生成',
        'desc' => '使用向量召回资料生成首篇内容，剩余后台继续',
        'icon' => 'file-text',
        'link' => 'articles.php',
        'status' => $stage_counts['execute'] > 0 ? 'running' : 'ready',
        'meta' => '内容生产',
        'tone' => 'emerald',
    ],
    [
        'title' => 'S11 启动媒体分发',
        'desc' => '按渠道配置发布文章，自动发布或生成待办',
        'icon' => 'send',
        'link' => 'distribution.php',
        'status' => 'ready',
        'meta' => '媒体分发',
        'tone' => 'orange',
    ],
    [
        'title' => 'S12 启动监测',
        'desc' => '发布后添加监测关键词，持续跟踪引用变化',
        'icon' => 'activity',
        'link' => 'geo-monitor.php',
        'status' => $total_alerts > 0 ? 'attention' : 'running',
        'meta' => $total_alerts . ' 个告警',
        'tone' => 'orange',
    ],
    [
        'title' => 'S13 生成全景诊断',
        'desc' => '汇总诊断、内容、分发和监测结果形成复盘',
        'icon' => 'scan-search',
        'link' => 'geo-diagnosis.php',
        'status' => 'ready',
        'meta' => '全景档案',
        'tone' => 'slate',
    ],
];

$automation_running_count = count(array_filter($automation_nodes, fn($node) => ($node['status'] ?? '') === 'running'));
$automation_attention_count = count(array_filter($automation_nodes, fn($node) => ($node['status'] ?? '') === 'attention'));

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
                    <a href="<?php echo htmlspecialchars(admin_url('tasks.php')); ?>" class="inline-flex h-10 items-center rounded-lg bg-blue-600 px-4 text-sm font-semibold text-white shadow-sm hover:bg-blue-700">
                        <i data-lucide="plus" class="mr-2 h-4 w-4"></i>
                        新建任务
                    </a>
                </div>
            </div>

            <section class="mb-8 overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                <div class="flex flex-col gap-4 border-b border-gray-100 px-6 py-5 lg:flex-row lg:items-start lg:justify-between">
                    <div>
                        <p class="text-xs font-semibold uppercase text-blue-600">快速启动</p>
                        <h2 class="mt-2 text-xl font-semibold text-gray-900">只需三步，启动 GEO+AI 自动内容生产</h2>
                        <p class="mt-2 max-w-3xl text-sm leading-6 text-gray-500">
                            先接入可用模型，再准备知识库、标题、关键词和图片素材，最后创建任务，即可自动生成内容并按发布节奏上线。
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
                                    <?php if (!empty($step['chips'])): ?>
                                        <div class="mt-4 flex flex-wrap gap-2">
                                            <?php foreach ($step['chips'] as $chip): ?>
                                                <a href="<?php echo htmlspecialchars(admin_url($chip['link'])); ?>" class="rounded-full border px-3 py-1 text-xs font-semibold <?php echo htmlspecialchars($chip['class']); ?>">
                                                    <?php echo htmlspecialchars($chip['label']); ?>
                                                </a>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <a href="<?php echo htmlspecialchars(admin_url($step['link'])); ?>" class="mt-4 inline-flex h-9 items-center rounded-lg <?php echo $step['tone'] === 'slate' ? 'border border-gray-300 bg-white text-gray-700 hover:bg-gray-50' : 'bg-blue-600 text-white hover:bg-blue-700'; ?> px-3 text-sm font-semibold">
                                            <?php echo htmlspecialchars($step['button']); ?>
                                            <i data-lucide="arrow-right" class="ml-1.5 h-4 w-4"></i>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <section id="automation-flow" class="mb-8 scroll-mt-24 overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                <div class="flex flex-col gap-4 border-b border-gray-100 px-6 py-5 lg:flex-row lg:items-start lg:justify-between">
                    <div>
                        <h2 class="text-xl font-semibold text-gray-900">品牌入驻自动化</h2>
                        <p class="mt-2 max-w-4xl text-sm leading-6 text-gray-500">
                            先完成 API、素材上传、知识切割向量化和基准诊断这些人工准备项，再进入任务、内容、分发、监测和复盘自动化。
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <span class="inline-flex items-center rounded-full bg-blue-100 px-3 py-1 text-xs font-semibold text-blue-700">
                            <span class="mr-2 h-1.5 w-1.5 rounded-full bg-current"></span>
                            <?php echo $automation_running_count; ?> 个节点运行中
                        </span>
                        <span class="inline-flex items-center rounded-full bg-orange-100 px-3 py-1 text-xs font-semibold text-orange-700">
                            <span class="mr-2 h-1.5 w-1.5 rounded-full bg-current"></span>
                            <?php echo $automation_attention_count; ?> 项需要关注
                        </span>
                    </div>
                </div>
                <div class="p-5">
                    <div class="relative grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
                        <div class="pointer-events-none absolute left-[8%] right-[8%] top-[42px] hidden h-0.5 bg-gradient-to-r from-blue-200 via-emerald-200 to-orange-200 xl:block"></div>
                        <?php foreach ($automation_nodes as $node): ?>
                            <?php
                            $tone = $tone_classes[$node['tone']] ?? $tone_classes['slate'];
                            $status = $status_classes[$node['status']] ?? $status_classes['ready'];
                            ?>
                            <a href="<?php echo htmlspecialchars(admin_url($node['link'])); ?>" class="relative z-10 flex min-h-[178px] flex-col rounded-lg border border-gray-200 bg-white p-4 shadow-sm transition hover:-translate-y-0.5 hover:shadow-md">
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

            </div>

<?php
// 包含统一底部
require_once __DIR__ . '/includes/footer.php';
?>
