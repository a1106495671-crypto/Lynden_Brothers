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
$active_tab = ($_GET['tab'] ?? 'board') === 'journey' ? 'journey' : 'board';

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

// 包含统一头部
require_once __DIR__ . '/includes/header.php';
?>
            <!-- 页面标题 -->
            <div class="mb-8">
                <div class="flex items-center justify-between">
                    <div>
                        <h1 class="text-3xl font-bold text-gray-900">仪表盘</h1>
                        <p class="mt-1 text-sm text-gray-600"><?php echo htmlspecialchars($admin_site_name); ?> 数据概览</p>
                    </div>
                    <div class="flex items-center space-x-3">
                        <span class="text-sm text-gray-500">最后更新: <?php echo date('Y-m-d H:i:s'); ?></span>
                        <button onclick="location.reload()" class="inline-flex items-center px-3 py-2 border border-gray-300 shadow-sm text-sm leading-4 font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                            <i data-lucide="refresh-cw" class="w-4 h-4 mr-1"></i>
                            刷新
                        </button>
                    </div>
                </div>
            </div>

            <!-- 首页标签页 -->
            <div class="mb-8 rounded-xl border border-gray-200 bg-white p-1 shadow-sm">
                <div class="grid grid-cols-2 gap-1">
                    <a href="<?php echo htmlspecialchars(admin_url('dashboard.php?tab=board')); ?>"
                       class="inline-flex items-center justify-center rounded-lg px-4 py-3 text-sm font-semibold transition <?php echo $active_tab === 'board' ? 'bg-blue-600 text-white shadow-sm' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'; ?>">
                        <i data-lucide="layout-dashboard" class="mr-2 h-4 w-4"></i>
                        数据看板
                    </a>
                    <a href="<?php echo htmlspecialchars(admin_url('dashboard.php?tab=journey')); ?>"
                       class="inline-flex items-center justify-center rounded-lg px-4 py-3 text-sm font-semibold transition <?php echo $active_tab === 'journey' ? 'bg-blue-600 text-white shadow-sm' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'; ?>">
                        <i data-lucide="route" class="mr-2 h-4 w-4"></i>
                        服务流程
                    </a>
                </div>
            </div>

            <?php if ($active_tab === 'board'): ?>

            <!-- GEO 交付 KPI 卡片 -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                <!-- 活跃客户 -->
                <div class="bg-white overflow-hidden shadow-lg rounded-lg">
                    <div class="p-5">
                        <div class="flex items-center">
                            <div class="flex-shrink-0">
                                <i data-lucide="users" class="h-8 w-8 text-blue-600"></i>
                            </div>
                            <div class="ml-5 w-0 flex-1">
                                <dl>
                                    <dt class="text-sm font-medium text-gray-500 truncate">活跃客户</dt>
                                    <dd class="text-2xl font-bold text-gray-900"><?php echo $active_customers; ?></dd>
                                    <dd class="text-xs text-gray-500">共 <?php echo $total_customers; ?> 个客户</dd>
                                </dl>
                            </div>
                        </div>
                    </div>
                    <div class="bg-blue-50 px-5 py-2">
                        <a href="customers.php" class="text-xs font-medium text-blue-600 hover:text-blue-800">进入客户中心 →</a>
                    </div>
                </div>

                <!-- 执行阶段客户 -->
                <div class="bg-white overflow-hidden shadow-lg rounded-lg">
                    <div class="p-5">
                        <div class="flex items-center">
                            <div class="flex-shrink-0">
                                <i data-lucide="zap" class="h-8 w-8 text-emerald-600"></i>
                            </div>
                            <div class="ml-5 w-0 flex-1">
                                <dl>
                                    <dt class="text-sm font-medium text-gray-500 truncate">策略/执行/监测中</dt>
                                    <dd class="text-2xl font-bold text-gray-900"><?php echo $doing_customers; ?></dd>
                                    <dd class="text-xs text-gray-500">正在交付 GEO 服务</dd>
                                </dl>
                            </div>
                        </div>
                    </div>
                    <div class="bg-emerald-50 px-5 py-2">
                        <a href="sop-center.php" class="text-xs font-medium text-emerald-600 hover:text-emerald-800">查看 SOP 进度 →</a>
                    </div>
                </div>

                <!-- 累计诊断次数 -->
                <div class="bg-white overflow-hidden shadow-lg rounded-lg">
                    <div class="p-5">
                        <div class="flex items-center">
                            <div class="flex-shrink-0">
                                <i data-lucide="radar" class="h-8 w-8 text-purple-600"></i>
                            </div>
                            <div class="ml-5 w-0 flex-1">
                                <dl>
                                    <dt class="text-sm font-medium text-gray-500 truncate">累计诊断次数</dt>
                                    <dd class="text-2xl font-bold text-gray-900"><?php echo $geo_tool_stats['total_diagnoses'] ?: '—'; ?></dd>
                                    <dd class="text-xs text-gray-500">引用模拟 <?php echo $geo_tool_stats['total_sim_queries'] ?: '—'; ?> 次</dd>
                                </dl>
                            </div>
                        </div>
                    </div>
                    <div class="bg-purple-50 px-5 py-2">
                        <a href="geo-diagnosis.php" class="text-xs font-medium text-purple-600 hover:text-purple-800">进入雷达诊断 →</a>
                    </div>
                </div>

                <!-- 待处理告警 -->
                <div class="bg-white overflow-hidden shadow-lg rounded-lg">
                    <div class="p-5">
                        <div class="flex items-center">
                            <div class="flex-shrink-0">
                                <i data-lucide="bell-ring" class="h-8 w-8 <?php echo $total_alerts > 0 ? 'text-orange-500' : 'text-gray-400'; ?>"></i>
                            </div>
                            <div class="ml-5 w-0 flex-1">
                                <dl>
                                    <dt class="text-sm font-medium text-gray-500 truncate">待处理告警</dt>
                                    <dd class="text-2xl font-bold <?php echo $total_alerts > 0 ? 'text-orange-600' : 'text-gray-900'; ?>"><?php echo $total_alerts; ?></dd>
                                    <dd class="text-xs text-gray-500"><?php echo $expiring_soon; ?> 个客户合同 60 天内到期</dd>
                                </dl>
                            </div>
                        </div>
                    </div>
                    <div class="<?php echo $total_alerts > 0 ? 'bg-orange-50' : 'bg-gray-50'; ?> px-5 py-2">
                        <a href="geo-monitor.php" class="text-xs font-medium <?php echo $total_alerts > 0 ? 'text-orange-600 hover:text-orange-800' : 'text-gray-500 hover:text-gray-700'; ?>">查看监测告警 →</a>
                    </div>
                </div>
            </div>

            <!-- 阶段分布 + 客户状态 -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">

                <!-- 客户阶段漏斗 -->
                <div class="bg-white shadow rounded-lg">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <h3 class="text-lg font-medium text-gray-900">客户阶段分布</h3>
                        <p class="text-xs text-gray-500 mt-0.5">当前所有客户所处交付阶段</p>
                    </div>
                    <div class="p-6 space-y-3">
                        <?php foreach ($stage_labels as $key => $label):
                            $cnt = $stage_counts[$key];
                            $pct = $total_customers > 0 ? round(($cnt / $total_customers) * 100) : 0;
                            $color = $stage_colors[$key] ?? 'bg-gray-400';
                        ?>
                        <div>
                            <div class="flex items-center justify-between mb-1">
                                <span class="text-sm font-medium text-gray-700"><?php echo $label; ?></span>
                                <span class="text-sm font-semibold text-gray-900"><?php echo $cnt; ?> 个</span>
                            </div>
                            <div class="w-full bg-gray-100 rounded-full h-2.5">
                                <div class="<?php echo $color; ?> h-2.5 rounded-full transition-all" style="width: <?php echo $pct; ?>%"></div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- 客户状态一览 -->
                <div class="lg:col-span-2 bg-white shadow rounded-lg">
                    <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
                        <h3 class="text-lg font-medium text-gray-900">客户状态一览</h3>
                        <a href="customers.php" class="text-sm text-blue-600 hover:text-blue-800">全部客户 →</a>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">客户</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">阶段</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">整体进度</th>
                                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">告警</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">负责人</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">合同到期</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php foreach ($customers as $c):
                                    $is_active = $c['service_status'] === 'active';
                                    $alert_cnt = count($c['alerts']);
                                    $days_left = (int) round((strtotime($c['contract_end_at']) - time()) / 86400);
                                ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-4 py-3">
                                        <div class="text-sm font-semibold text-gray-900"><?php echo htmlspecialchars($c['name']); ?></div>
                                        <div class="text-xs text-gray-500"><?php echo htmlspecialchars($c['industry']); ?></div>
                                    </td>
                                    <td class="px-4 py-3">
                                        <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold
                                            <?php echo $is_active ? 'bg-blue-50 text-blue-700' : 'bg-gray-100 text-gray-500'; ?>">
                                            <?php echo htmlspecialchars($c['stage_label']); ?>
                                        </span>
                                    </td>
                                    <td class="px-4 py-3" style="min-width:120px">
                                        <div class="flex items-center gap-2">
                                            <div class="flex-1 bg-gray-100 rounded-full h-2">
                                                <div class="bg-blue-500 h-2 rounded-full" style="width:<?php echo (int)$c['overall_pct']; ?>%"></div>
                                            </div>
                                            <span class="text-xs text-gray-600 tabular-nums"><?php echo $c['overall_pct']; ?>%</span>
                                        </div>
                                    </td>
                                    <td class="px-4 py-3 text-center">
                                        <?php if ($alert_cnt > 0): ?>
                                        <span class="inline-flex items-center justify-center w-6 h-6 rounded-full bg-orange-100 text-orange-700 text-xs font-bold"><?php echo $alert_cnt; ?></span>
                                        <?php else: ?>
                                        <span class="text-gray-300">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-4 py-3 text-sm text-gray-700"><?php echo htmlspecialchars($c['owner']); ?></td>
                                    <td class="px-4 py-3">
                                        <span class="text-sm <?php echo $days_left <= 60 ? 'font-semibold text-orange-600' : 'text-gray-600'; ?>">
                                            <?php echo htmlspecialchars($c['contract_end_at']); ?>
                                        </span>
                                        <?php if ($days_left <= 60): ?>
                                        <div class="text-xs text-orange-500">剩 <?php echo $days_left; ?> 天</div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- 待办汇总 -->
            <div class="bg-white shadow rounded-lg">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-lg font-medium text-gray-900">全客户待办汇总</h3>
                    <p class="text-xs text-gray-500 mt-0.5">汇集所有客户当前阶段的关键行动项</p>
                </div>
                <div class="p-6">
                    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4">
                        <?php foreach ($customers as $c):
                            $alert_cnt = count($c['alerts']);
                        ?>
                        <div class="rounded-lg border <?php echo $c['service_status'] === 'active' ? 'border-gray-200' : 'border-dashed border-gray-200 opacity-60'; ?> p-4">
                            <div class="flex items-start justify-between mb-3">
                                <div>
                                    <div class="text-sm font-semibold text-gray-900"><?php echo htmlspecialchars($c['name']); ?></div>
                                    <div class="text-xs text-gray-500 mt-0.5"><?php echo htmlspecialchars($c['stage_label']); ?></div>
                                </div>
                                <?php if ($alert_cnt > 0): ?>
                                <span class="flex items-center gap-1 rounded-full bg-orange-100 px-2 py-0.5 text-xs font-semibold text-orange-700">
                                    <i data-lucide="alert-circle" class="h-3 w-3"></i><?php echo $alert_cnt; ?>
                                </span>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($c['pending'])): ?>
                            <ul class="space-y-1.5">
                                <?php foreach ($c['pending'] as $item): ?>
                                <li class="flex items-start gap-2 text-xs text-gray-600">
                                    <i data-lucide="circle-dot" class="mt-0.5 h-3 w-3 shrink-0 text-blue-400"></i>
                                    <?php echo htmlspecialchars($item); ?>
                                </li>
                                <?php endforeach; ?>
                            </ul>
                            <?php else: ?>
                            <p class="text-xs text-gray-400 italic">暂无待办</p>
                            <?php endif; ?>
                            <div class="mt-3 pt-3 border-t border-gray-100">
                                <a href="customers.php?select=<?php echo rawurlencode($c['id']); ?>" class="text-xs font-medium text-blue-600 hover:text-blue-800">进入工作台 →</a>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <?php else: ?>
            <!-- 服务流程总览 -->
            <div class="space-y-6">
                <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
                    <div class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-500">服务交付路径</p>
                                <p class="mt-2 text-3xl font-bold text-gray-900">6 阶段</p>
                            </div>
                            <div class="rounded-lg bg-blue-50 p-3 text-blue-600">
                                <i data-lucide="route" class="h-7 w-7"></i>
                            </div>
                        </div>
                        <p class="mt-4 text-sm leading-6 text-gray-600">以诊断、模拟、策略、执行、监测和复盘串联 GEO 服务全流程，让每一步目标与交付物清晰可见。</p>
                    </div>
                    <div class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-500">核心工具</p>
                                <p class="mt-2 text-3xl font-bold text-gray-900">3 个</p>
                            </div>
                            <div class="rounded-lg bg-emerald-50 p-3 text-emerald-600">
                                <i data-lucide="wrench" class="h-7 w-7"></i>
                            </div>
                        </div>
                        <p class="mt-4 text-sm leading-6 text-gray-600">雷达诊断、引用模拟器、AI偏好对照表共同完成现状评估、机会测算和平台策略选择。</p>
                    </div>
                    <div class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-500">交付目标</p>
                                <p class="mt-2 text-3xl font-bold text-gray-900">闭环</p>
                            </div>
                            <div class="rounded-lg bg-orange-50 p-3 text-orange-600">
                                <i data-lucide="repeat-2" class="h-7 w-7"></i>
                            </div>
                        </div>
                        <p class="mt-4 text-sm leading-6 text-gray-600">通过持续监测与阶段复盘，形成可量化的优化闭环，帮助品牌稳定提升 AI 引用表现。</p>
                    </div>
                </div>

                <div class="rounded-lg border border-gray-200 bg-white shadow-sm">
                    <div class="border-b border-gray-200 px-6 py-5">
                        <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                            <div>
                                <h2 class="text-xl font-semibold text-gray-900">六阶段客户旅程</h2>
                                <p class="mt-1 text-sm text-gray-500">点击每个阶段的工具入口，可以直接进入对应后台模块。</p>
                            </div>
                            <div class="flex flex-wrap items-center gap-2 text-xs font-medium">
                                <span class="rounded-full bg-blue-100 px-3 py-1 text-blue-700">获客转化</span>
                                <span class="rounded-full bg-emerald-100 px-3 py-1 text-emerald-700">策略成单</span>
                                <span class="rounded-full bg-orange-100 px-3 py-1 text-orange-700">续费增长</span>
                            </div>
                        </div>
                    </div>

                    <div class="p-6">
                        <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-6">
                            <?php foreach ($journey_stages as $stage): ?>
                                <?php $colors = $journey_color_classes[$stage['color']]; ?>
                                <a href="#stage-<?php echo htmlspecialchars($stage['no']); ?>"
                                   class="group rounded-lg border <?php echo $colors['card']; ?> p-4 transition hover:-translate-y-0.5 hover:shadow-md">
                                    <div class="flex items-center justify-between">
                                        <span class="rounded-full px-2.5 py-1 text-xs font-semibold <?php echo $colors['badge']; ?>">
                                            <?php echo htmlspecialchars($stage['no']); ?>
                                        </span>
                                        <span class="text-xs font-medium <?php echo $colors['text']; ?>">
                                            <?php echo htmlspecialchars($stage['segment']); ?>
                                        </span>
                                    </div>
                                    <h3 class="mt-4 text-lg font-bold text-gray-900"><?php echo htmlspecialchars($stage['name']); ?></h3>
                                    <p class="mt-1 text-sm text-gray-600"><?php echo htmlspecialchars($stage['tagline']); ?></p>
                                    <div class="mt-4 h-1.5 rounded-full bg-white/80">
                                        <div class="h-1.5 rounded-full <?php echo $colors['line']; ?>" style="width: <?php echo ((int)$stage['no']) * 16; ?>%;"></div>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-6 xl:grid-cols-2">
                    <?php foreach ($journey_stages as $stage): ?>
                        <?php $colors = $journey_color_classes[$stage['color']]; ?>
                        <section id="stage-<?php echo htmlspecialchars($stage['no']); ?>" class="rounded-lg border border-gray-200 bg-white shadow-sm">
                            <div class="border-b border-gray-200 p-6">
                                <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                                    <div class="flex items-start gap-4">
                                        <span class="mt-1 rounded-lg px-3 py-2 text-sm font-bold <?php echo $colors['badge']; ?>">
                                            <?php echo htmlspecialchars($stage['no']); ?>
                                        </span>
                                        <div>
                                            <div class="flex flex-wrap items-center gap-2">
                                                <h3 class="text-xl font-bold text-gray-900"><?php echo htmlspecialchars($stage['name']); ?></h3>
                                                <span class="rounded-full px-2.5 py-1 text-xs font-semibold <?php echo $colors['soft']; ?>">
                                                    <?php echo htmlspecialchars($stage['tagline']); ?>
                                                </span>
                                            </div>
                                            <div class="mt-2 flex flex-wrap gap-2 text-xs text-gray-600">
                                                <span class="rounded-full bg-gray-100 px-2.5 py-1">周期：<?php echo htmlspecialchars($stage['duration']); ?></span>
                                                <span class="rounded-full bg-gray-100 px-2.5 py-1">主导：<?php echo htmlspecialchars($stage['role']); ?></span>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="flex flex-wrap gap-2">
                                        <a href="<?php echo htmlspecialchars(admin_url($stage['link'])); ?>"
                                           class="inline-flex items-center rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50">
                                            <i data-lucide="external-link" class="mr-1.5 h-4 w-4"></i>
                                            <?php echo htmlspecialchars($stage['tool']); ?>
                                        </a>
                                        <?php if (!empty($stage['secondary_link'])): ?>
                                            <a href="<?php echo htmlspecialchars(admin_url($stage['secondary_link'])); ?>"
                                               class="inline-flex items-center rounded-md bg-gray-900 px-3 py-2 text-sm font-medium text-white shadow-sm hover:bg-gray-800">
                                                媒体分发
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="grid grid-cols-1 gap-5 p-6 lg:grid-cols-2">
                                <div>
                                    <h4 class="text-sm font-semibold text-gray-900">关键动作</h4>
                                    <ul class="mt-3 space-y-2">
                                        <?php foreach ($stage['activities'] as $activity): ?>
                                            <li class="flex gap-2 text-sm leading-6 text-gray-600">
                                                <i data-lucide="check-circle-2" class="mt-1 h-4 w-4 flex-shrink-0 <?php echo $colors['text']; ?>"></i>
                                                <span><?php echo htmlspecialchars($activity); ?></span>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                                <div class="space-y-3">
                                    <div class="rounded-lg bg-gray-50 p-4">
                                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">交付成果</p>
                                        <p class="mt-1 text-sm leading-6 text-gray-800"><?php echo htmlspecialchars($stage['output']); ?></p>
                                    </div>
                                    <div class="rounded-lg bg-gray-50 p-4">
                                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">服务方式</p>
                                        <p class="mt-1 text-sm leading-6 text-gray-800"><?php echo htmlspecialchars($stage['pricing']); ?></p>
                                    </div>
                                    <div class="rounded-lg bg-gray-50 p-4">
                                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">阶段价值</p>
                                        <p class="mt-1 text-sm leading-6 text-gray-800"><?php echo htmlspecialchars($stage['conversion']); ?></p>
                                    </div>
                                </div>
                            </div>
                        </section>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

<?php
// 包含统一底部
require_once __DIR__ . '/includes/footer.php';
?>
