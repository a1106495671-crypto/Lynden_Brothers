<?php
/**
 * GEO System - 品牌入驻自动化工作流
 * 可视化展示自动化流程的每一步执行状态
 */

define('FEISHU_TREASURE', true);
session_start();

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/database_admin.php';
require_admin_login();

session_write_close();

$page_title = '品牌入驻自动化';
?>
<?php require_once __DIR__ . '/includes/header.php'; ?>

<style>
    /* 工作流连接线动画 */
    @keyframes flowPulse {
        0%, 100% { opacity: 0.3; }
        50% { opacity: 1; }
    }
    .flow-line-active {
        animation: flowPulse 1.5s ease-in-out infinite;
    }
    .flow-line-active .flow-dot {
        animation: flowPulse 1s ease-in-out infinite;
    }
    /* 步骤卡片进入动画 */
    @keyframes slideUp {
        from { opacity: 0; transform: translateY(12px); }
        to { opacity: 1; transform: translateY(0); }
    }
    .step-card { animation: slideUp 0.4s ease-out forwards; }
    .step-card:nth-child(2) { animation-delay: 0.05s; }
    .step-card:nth-child(3) { animation-delay: 0.1s; }
    .step-card:nth-child(4) { animation-delay: 0.15s; }
    .step-card:nth-child(5) { animation-delay: 0.2s; }
    .step-card:nth-child(6) { animation-delay: 0.25s; }
    .step-card:nth-child(7) { animation-delay: 0.3s; }
    .step-card:nth-child(8) { animation-delay: 0.35s; }
    .step-card:nth-child(9) { animation-delay: 0.4s; }
    /* 日志滚动 */
    .log-scroll::-webkit-scrollbar { width: 6px; }
    .log-scroll::-webkit-scrollbar-track { background: transparent; }
    .log-scroll::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 3px; }
    .log-scroll::-webkit-scrollbar-thumb:hover { background: #9ca3af; }
    /* 运行中脉冲 */
    @keyframes spin-slow {
        from { transform: rotate(0deg); }
        to { transform: rotate(360deg); }
    }
    .animate-spin-slow { animation: spin-slow 2s linear infinite; }
</style>

<div class="mb-8">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 flex items-center gap-3">
                <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-gradient-to-br from-violet-500 to-indigo-600 shadow-lg shadow-violet-500/25">
                    <i data-lucide="workflow" class="h-5 w-5 text-white"></i>
                </div>
                品牌入驻自动化
            </h1>
            <p class="mt-1 text-sm text-gray-500">一键完成品牌资料搜集、素材库生成、内容创建与分发的全流程自动化</p>
        </div>
        <div class="flex items-center gap-3">
            <span id="global-status" class="inline-flex items-center gap-1.5 rounded-full bg-gray-100 px-3 py-1.5 text-xs font-medium text-gray-600">
                <span class="h-2 w-2 rounded-full bg-gray-400"></span>
                就绪
            </span>
            <button id="btn-demo" onclick="runDemo()" class="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-3 py-2.5 text-sm font-medium text-gray-700 shadow-sm transition hover:bg-gray-50" title="无需后端，纯前端演示">
                <i data-lucide="test-tubes" class="h-4 w-4"></i>
                演示
            </button>
            <button id="btn-start" onclick="openStartModal()" class="inline-flex items-center gap-2 rounded-lg bg-gradient-to-r from-violet-600 to-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-lg shadow-violet-500/25 transition hover:shadow-xl hover:shadow-violet-500/30 active:scale-[0.98]">
                <i data-lucide="play" class="h-4 w-4"></i>
                启动自动化
            </button>
        </div>
    </div>
</div>

<!-- 主面板 -->
<div class="grid grid-cols-1 xl:grid-cols-3 gap-6">

    <!-- 左侧：工作流步骤 (占2列) -->
    <div class="xl:col-span-2 space-y-4">

        <!-- 流水线总览条 -->
        <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-sm font-semibold text-gray-700">自动化流水线</h2>
                <div class="flex items-center gap-4 text-xs text-gray-500">
                    <span class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full bg-gray-300"></span>等待</span>
                    <span class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full bg-blue-500 animate-pulse"></span>运行中</span>
                    <span class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full bg-emerald-500"></span>完成</span>
                    <span class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full bg-red-500"></span>失败</span>
                </div>
            </div>
            <!-- 进度条 -->
            <div class="relative">
                <div class="flex items-center justify-between">
                    <div id="pipeline-bar" class="h-2 flex-1 rounded-full bg-gray-100 overflow-hidden">
                        <div id="pipeline-progress" class="h-full rounded-full bg-gradient-to-r from-violet-500 to-indigo-500 transition-all duration-700 ease-out" style="width: 0%"></div>
                    </div>
                    <span id="pipeline-pct" class="ml-3 text-sm font-bold text-gray-900 tabular-nums w-10 text-right">0%</span>
                </div>
            </div>
        </div>

        <!-- 步骤卡片网格 -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4" id="steps-container">
            <?php
            $steps = [
                ['id' => 'collect',     'no' => '01', 'name' => '搜集品牌资料',  'desc' => '搜索官网与媒体报道，整理品牌基本信息', 'icon' => 'search',           'color' => 'blue'],
                ['id' => 'keywords',    'no' => '02', 'name' => '生成关键词库',  'desc' => '生成25-40个五类关键词',               'icon' => 'tags',             'color' => 'violet'],
                ['id' => 'titles',      'no' => '03', 'name' => '生成标题库',    'desc' => '生成20个六类标题模板',               'icon' => 'heading',          'color' => 'indigo'],
                ['id' => 'knowledge',   'no' => '04', 'name' => '生成知识库',    'desc' => '生成1200-1500字品牌知识文档',         'icon' => 'book-open',        'color' => 'purple'],
                ['id' => 'customer',    'no' => '05', 'name' => '创建客户',      'desc' => '在系统中创建客户记录',               'icon' => 'building-2',       'color' => 'sky'],
                ['id' => 'task',        'no' => '06', 'name' => '创建并启动任务','desc' => '关联标题库、AI模型与GEO语义优化',     'icon' => 'zap',              'color' => 'cyan'],
                ['id' => 'generate',    'no' => '07', 'name' => '首篇文章生成',  'desc' => '生成首篇后进入发布，剩余文章后台继续生成', 'icon' => 'file-text',        'color' => 'teal'],
                ['id' => 'distribute',  'no' => '08', 'name' => '启动媒体分发',  'desc' => '发布文章并加入媒体队列，后台持续分发',   'icon' => 'send',             'color' => 'emerald'],
                ['id' => 'monitor',     'no' => '09', 'name' => '启动监测',      'desc' => '首篇发布后添加监测关键词，持续跟踪变化', 'icon' => 'activity',         'color' => 'green'],
            ];

            $color_map = [
                'blue'    => ['bg' => 'bg-blue-50',    'border' => 'border-blue-200',    'icon' => 'bg-blue-100 text-blue-600',    'badge' => 'bg-blue-600',    'ring' => 'ring-blue-500/20'],
                'violet'  => ['bg' => 'bg-violet-50',  'border' => 'border-violet-200',  'icon' => 'bg-violet-100 text-violet-600','badge' => 'bg-violet-600',  'ring' => 'ring-violet-500/20'],
                'indigo'  => ['bg' => 'bg-indigo-50',  'border' => 'border-indigo-200',  'icon' => 'bg-indigo-100 text-indigo-600','badge' => 'bg-indigo-600',  'ring' => 'ring-indigo-500/20'],
                'purple'  => ['bg' => 'bg-purple-50',  'border' => 'border-purple-200',  'icon' => 'bg-purple-100 text-purple-600','badge' => 'bg-purple-600',  'ring' => 'ring-purple-500/20'],
                'sky'     => ['bg' => 'bg-sky-50',     'border' => 'border-sky-200',     'icon' => 'bg-sky-100 text-sky-600',     'badge' => 'bg-sky-600',     'ring' => 'ring-sky-500/20'],
                'cyan'    => ['bg' => 'bg-cyan-50',    'border' => 'border-cyan-200',    'icon' => 'bg-cyan-100 text-cyan-600',    'badge' => 'bg-cyan-600',    'ring' => 'ring-cyan-500/20'],
                'teal'    => ['bg' => 'bg-teal-50',    'border' => 'border-teal-200',    'icon' => 'bg-teal-100 text-teal-600',    'badge' => 'bg-teal-600',    'ring' => 'ring-teal-500/20'],
                'emerald' => ['bg' => 'bg-emerald-50', 'border' => 'border-emerald-200', 'icon' => 'bg-emerald-100 text-emerald-600','badge' => 'bg-emerald-600','ring' => 'ring-emerald-500/20'],
                'green'   => ['bg' => 'bg-green-50',   'border' => 'border-green-200',   'icon' => 'bg-green-100 text-green-600',  'badge' => 'bg-green-600',  'ring' => 'ring-green-500/20'],
            ];

            foreach ($steps as $i => $step):
                $c = $color_map[$step['color']];
            ?>
            <div class="step-card group relative rounded-xl border border-gray-200 bg-white p-4 shadow-sm transition hover:shadow-md hover:-translate-y-0.5"
                 id="step-<?php echo $step['id']; ?>"
                 data-step="<?php echo $step['id']; ?>"
                 data-no="<?php echo $step['no']; ?>">
                <!-- 步骤编号 + 图标 -->
                <div class="flex items-start justify-between mb-3">
                    <div class="flex items-center gap-2.5">
                        <div class="relative">
                            <div class="flex h-9 w-9 items-center justify-center rounded-lg <?php echo $c['icon']; ?> transition group-hover:shadow-sm" id="step-icon-<?php echo $step['id']; ?>">
                                <i data-lucide="<?php echo $step['icon']; ?>" class="h-4.5 w-4.5"></i>
                            </div>
                            <!-- 状态覆盖层 -->
                            <div class="absolute -top-1 -right-1 hidden" id="step-status-<?php echo $step['id']; ?>">
                            </div>
                        </div>
                        <div>
                            <span class="text-[10px] font-bold tracking-wider text-gray-400 uppercase">Step <?php echo $step['no']; ?></span>
                            <h3 class="text-sm font-semibold text-gray-900 leading-tight"><?php echo $step['name']; ?></h3>
                        </div>
                    </div>
                    <span class="inline-flex h-5 items-center rounded-full bg-gray-100 px-2 text-[10px] font-medium text-gray-500 transition"
                          id="step-badge-<?php echo $step['id']; ?>">
                        等待
                    </span>
                </div>
                <!-- 描述 -->
                <p class="text-xs text-gray-500 leading-relaxed mb-3"><?php echo $step['desc']; ?></p>
                <!-- 耗时 / 输出信息 -->
                <div class="flex items-center justify-between text-[10px] text-gray-400" id="step-meta-<?php echo $step['id']; ?>">
                    <span class="flex items-center gap-1"><i data-lucide="clock" class="h-3 w-3"></i> --</span>
                    <span class="flex items-center gap-1" id="step-output-<?php echo $step['id']; ?>"></span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- 品牌信息卡片 (启动后显示) -->
        <div id="brand-info-card" class="hidden rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
            <div class="flex items-center gap-3 mb-4">
                <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-violet-100 text-violet-600">
                    <i data-lucide="building-2" class="h-4 w-4"></i>
                </div>
                <div>
                    <h3 class="text-sm font-semibold text-gray-900" id="brand-name">--</h3>
                    <p class="text-xs text-gray-500" id="brand-industry">--</p>
                </div>
            </div>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3 text-xs">
                <div class="rounded-lg bg-gray-50 p-3">
                    <span class="text-gray-400 block mb-1">关键词</span>
                    <span class="font-semibold text-gray-900" id="stat-keywords">--</span>
                </div>
                <div class="rounded-lg bg-gray-50 p-3">
                    <span class="text-gray-400 block mb-1">标题</span>
                    <span class="font-semibold text-gray-900" id="stat-titles">--</span>
                </div>
                <div class="rounded-lg bg-gray-50 p-3">
                    <span class="text-gray-400 block mb-1">文章</span>
                    <span class="font-semibold text-gray-900" id="stat-articles">--</span>
                </div>
                <div class="rounded-lg bg-gray-50 p-3">
                    <span class="text-gray-400 block mb-1">已发布</span>
                    <span class="font-semibold text-gray-900" id="stat-published">--</span>
                </div>
            </div>
            <div class="mt-3 grid grid-cols-1 md:grid-cols-3 gap-3 text-xs">
                <div class="rounded-lg border border-gray-100 bg-white p-3">
                    <span class="text-gray-400 block mb-1">文章生成队列</span>
                    <span class="font-semibold text-gray-900" id="runtime-generation">--</span>
                </div>
                <div class="rounded-lg border border-gray-100 bg-white p-3">
                    <span class="text-gray-400 block mb-1">媒体发布队列</span>
                    <span class="font-semibold text-gray-900" id="runtime-distribution">--</span>
                    <span class="mt-1 block text-[10px] text-gray-400" id="runtime-next-distribution">--</span>
                </div>
                <div class="rounded-lg border border-gray-100 bg-white p-3">
                    <span class="text-gray-400 block mb-1">GEO监测</span>
                    <span class="font-semibold text-gray-900" id="runtime-monitor">--</span>
                </div>
            </div>
        </div>
    </div>

    <!-- 右侧：实时日志面板 -->
    <div class="xl:col-span-1">
        <div class="sticky top-6 rounded-xl border border-gray-200 bg-white shadow-sm overflow-hidden flex flex-col" style="height: calc(100vh - 160px);">
            <!-- 日志头部 -->
            <div class="flex items-center justify-between border-b border-gray-200 px-5 py-3.5">
                <div class="flex items-center gap-2">
                    <div class="flex h-7 w-7 items-center justify-center rounded-lg bg-gray-900">
                        <i data-lucide="terminal" class="h-3.5 w-3.5 text-emerald-400"></i>
                    </div>
                    <div>
                        <h3 class="text-sm font-semibold text-gray-900">执行日志</h3>
                        <p class="text-[10px] text-gray-400">实时显示自动化进度</p>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <button onclick="clearLogs()" class="rounded-md p-1.5 text-gray-400 hover:bg-gray-100 hover:text-gray-600 transition" title="清空日志">
                        <i data-lucide="trash-2" class="h-3.5 w-3.5"></i>
                    </button>
                    <label class="flex items-center gap-1.5 cursor-pointer">
                        <input type="checkbox" id="auto-scroll" checked class="h-3.5 w-3.5 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                        <span class="text-[10px] text-gray-500">自动滚动</span>
                    </label>
                </div>
            </div>
            <!-- 日志内容 -->
            <div id="log-container" class="log-scroll flex-1 overflow-y-auto p-4 font-mono text-xs space-y-1.5">
                <div class="flex items-start gap-2 text-gray-400">
                    <span class="text-[10px] text-gray-300 shrink-0 tabular-nums">--:--:--</span>
                    <span>等待自动化启动...</span>
                </div>
            </div>
            <!-- 日志底部状态栏 -->
            <div class="border-t border-gray-200 px-5 py-2.5 flex items-center justify-between text-[10px] text-gray-500 bg-gray-50">
                <span id="log-count">0 条日志</span>
                <span id="elapsed-time" class="tabular-nums">00:00</span>
            </div>
        </div>
    </div>
</div>

<!-- 启动配置弹窗 -->
<div id="start-modal" class="fixed inset-0 z-50 hidden">
    <div class="absolute inset-0 bg-gray-900/50 backdrop-blur-sm" onclick="closeStartModal()"></div>
    <div class="absolute left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 w-full max-w-lg">
        <div class="rounded-2xl bg-white shadow-2xl border border-gray-200 overflow-hidden">
            <!-- 弹窗头部 -->
            <div class="bg-gradient-to-r from-violet-600 to-indigo-600 px-6 py-5">
                <div class="flex items-center gap-3">
                    <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-white/20">
                        <i data-lucide="rocket" class="h-5 w-5 text-white"></i>
                    </div>
                    <div>
                        <h2 class="text-lg font-bold text-white">启动品牌入驻自动化</h2>
                        <p class="text-sm text-violet-100">填写品牌信息，系统将自动完成全部流程</p>
                    </div>
                </div>
            </div>
            <!-- 弹窗内容 -->
            <div class="p-6 space-y-4 max-h-[60vh] overflow-y-auto">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">品牌名称 <span class="text-red-500">*</span></label>
                    <input type="text" id="input-brand" placeholder="如：文韵爱阅读" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-violet-500 focus:ring-2 focus:ring-violet-500/20 focus:outline-none">
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">行业 <span class="text-red-500">*</span></label>
                        <input type="text" id="input-industry" placeholder="如：教培 / 知识付费" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-violet-500 focus:ring-2 focus:ring-violet-500/20 focus:outline-none">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">官网</label>
                        <input type="text" id="input-website" placeholder="如：example.com" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-violet-500 focus:ring-2 focus:ring-violet-500/20 focus:outline-none">
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">核心服务</label>
                    <input type="text" id="input-services" placeholder="用逗号分隔，如：AI阅读, 知识付费, 在线课程" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-violet-500 focus:ring-2 focus:ring-violet-500/20 focus:outline-none">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">竞品</label>
                    <input type="text" id="input-competitors" placeholder="用逗号分隔，如：竞品A, 竞品B, 竞品C" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-violet-500 focus:ring-2 focus:ring-violet-500/20 focus:outline-none">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">品牌定位</label>
                    <textarea id="input-positioning" rows="2" placeholder="一句话描述品牌定位" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-violet-500 focus:ring-2 focus:ring-violet-500/20 focus:outline-none resize-none"></textarea>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">生成文章数量</label>
                    <select id="input-article-count" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-violet-500 focus:ring-2 focus:ring-violet-500/20 focus:outline-none">
                        <option value="5">5 篇（快速预览）</option>
                        <option value="10" selected>10 篇（标准）</option>
                        <option value="20">20 篇（完整）</option>
                    </select>
                </div>
            </div>
            <!-- 弹窗底部 -->
            <div class="border-t border-gray-200 px-6 py-4 flex items-center justify-between bg-gray-50">
                <button onclick="closeStartModal()" class="rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100 transition">
                    取消
                </button>
                <button onclick="startAutomation()" id="btn-confirm-start" class="inline-flex items-center gap-2 rounded-lg bg-gradient-to-r from-violet-600 to-indigo-600 px-6 py-2.5 text-sm font-semibold text-white shadow-lg shadow-violet-500/25 hover:shadow-xl transition active:scale-[0.98]">
                    <i data-lucide="play" class="h-4 w-4"></i>
                    开始执行
                </button>
            </div>
        </div>
    </div>
</div>

<?php
$additional_js = <<<'JS'
<script>
// ── 状态管理 ──────────────────────────────────────────────────────────────────
const STEP_IDS = ['collect','keywords','titles','knowledge','customer','task','generate','distribute','monitor'];
const STEP_META = {
    collect:    { name: '搜集品牌资料',  icon: 'search' },
    keywords:   { name: '生成关键词库',  icon: 'tags' },
    titles:     { name: '生成标题库',    icon: 'heading' },
    knowledge:  { name: '生成知识库',    icon: 'book-open' },
    customer:   { name: '创建客户',      icon: 'building-2' },
    task:       { name: '创建并启动任务',icon: 'zap' },
    generate:   { name: '首篇文章生成',  icon: 'file-text' },
    distribute: { name: '启动媒体分发',  icon: 'send' },
    monitor:    { name: '启动监测',      icon: 'activity' },
};

let workflowState = {
    status: 'idle',      // idle | running | completed | error
    currentStep: null,
    steps: {},           // { stepId: { status, startedAt, finishedAt, elapsed, output } }
    brandInfo: null,
    runtime: null,
    startTime: null,
    logs: [],
};

let logCount = 0;
let elapsedTimer = null;

function parsePgTimestamp(value) {
    if (!value) return null;
    const normalized = String(value).replace(' ', 'T').replace(/\.\d+$/, '');
    const time = new Date(normalized).getTime();
    return Number.isNaN(time) ? null : time;
}

function parseStepOutput(raw) {
    if (!raw) return null;
    try {
        return JSON.parse(raw);
    } catch (err) {
        return { raw };
    }
}

// ── 弹窗控制 ──────────────────────────────────────────────────────────────────
function openStartModal() {
    document.getElementById('start-modal').classList.remove('hidden');
    document.getElementById('input-brand').focus();
    if (typeof lucide !== 'undefined') lucide.createIcons();
}

function closeStartModal() {
    document.getElementById('start-modal').classList.add('hidden');
}

// ESC 关闭弹窗
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeStartModal();
});

// ── 启动自动化 ────────────────────────────────────────────────────────────────
async function startAutomation() {
    const brand = document.getElementById('input-brand').value.trim();
    const industry = document.getElementById('input-industry').value.trim();

    if (!brand || !industry) {
        showToast('请填写品牌名称和行业', 'error');
        return;
    }

    const btnConfirm = document.getElementById('btn-confirm-start');
    btnConfirm.disabled = true;
    btnConfirm.innerHTML = '<i data-lucide="loader-2" class="h-4 w-4 animate-spin"></i> 正在启动...';
    if (typeof lucide !== 'undefined') lucide.createIcons();

    const payload = {
        brand_name: brand,
        industry: industry,
        website: document.getElementById('input-website').value.trim(),
        services: document.getElementById('input-services').value.trim(),
        competitors: document.getElementById('input-competitors').value.trim(),
        positioning: document.getElementById('input-positioning').value.trim(),
        article_count: parseInt(document.getElementById('input-article-count').value) || 10,
    };

    try {
        const resp = await fetch(window.adminUrl('api/automation-start.php'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        });
        const data = await resp.json();

        if (data.success) {
            closeStartModal();
            workflowState.brandInfo = payload;
            workflowState.status = 'running';
            workflowState.startTime = Date.now();
            workflowState.workflowId = data.workflow_id;

            // 初始化步骤状态
            STEP_IDS.forEach(id => {
                workflowState.steps[id] = { status: 'pending' };
            });

            updateGlobalStatus();
            showBrandInfoCard();
            startElapsedTimer();
            addLog('info', '自动化流程已启动，品牌：' + brand);
            addLog('info', '工作流 ID：' + data.workflow_id);

            // 开始轮询状态
            pollWorkflowStatus();
        } else {
            showToast(data.error || '启动失败', 'error');
        }
    } catch (err) {
        showToast('请求失败：' + err.message, 'error');
    } finally {
        btnConfirm.disabled = false;
        btnConfirm.innerHTML = '<i data-lucide="play" class="h-4 w-4"></i> 开始执行';
        if (typeof lucide !== 'undefined') lucide.createIcons();
    }
}

// ── 轮询工作流状态 ────────────────────────────────────────────────────────────
let pollTimer = null;

async function pollWorkflowStatus() {
    if (!workflowState.workflowId) return;

    try {
        const resp = await fetch(window.adminUrl('api/automation-status.php?id=' + workflowState.workflowId));
        const data = await resp.json();

        if (data.success && data.workflow) {
            applyWorkflowPayload(data, true);
            updatePipelineProgress();
            updateGlobalStatus();

            // 继续轮询
            if (workflowState.status === 'running' || hasBackgroundRuntime()) {
                pollTimer = setTimeout(pollWorkflowStatus, 2000);
            } else if (workflowState.status === 'completed') {
                stopElapsedTimer();
                addLog('success', '🎉 全部流程执行完成！');
                showToast('自动化流程已完成！', 'success');
            } else if (workflowState.status === 'error') {
                stopElapsedTimer();
                addLog('error', '流程执行出错，请检查日志');
                pollTimer = setTimeout(pollWorkflowStatus, 10000);
            }
        } else {
            // API 返回错误，继续轮询
            pollTimer = setTimeout(pollWorkflowStatus, 3000);
        }
    } catch (err) {
        // 网络错误，继续轮询
        pollTimer = setTimeout(pollWorkflowStatus, 5000);
    }
}

function applyWorkflowPayload(data, logChanges) {
    const wf = data.workflow;
    workflowState.workflowId = wf.id;
    workflowState.status = wf.status || 'running';
    workflowState.currentStep = wf.current_step || null;
    workflowState.brandInfo = {
        brand_name: wf.brand_name,
        industry: wf.industry,
        website: wf.website || '',
        services: wf.services || '',
        competitors: wf.competitors || '',
        positioning: wf.positioning || '',
        article_count: wf.article_count || 0,
    };
    workflowState.startTime = parsePgTimestamp(wf.created_at) || workflowState.startTime || Date.now();

    STEP_IDS.forEach(id => {
        if (!workflowState.steps[id]) {
            workflowState.steps[id] = { status: 'pending' };
        }
    });

    if (wf.steps) {
        wf.steps.forEach(step => {
            const prev = workflowState.steps[step.step_id];
            workflowState.steps[step.step_id] = {
                status: step.status,
                startedAt: step.started_at,
                finishedAt: step.finished_at,
                elapsed: step.elapsed_seconds,
                output: parseStepOutput(step.output_data),
                error: step.error_message,
            };

            if (logChanges && prev && prev.status !== step.status) {
                if (step.status === 'running') {
                    addLog('info', `▶ ${STEP_META[step.step_id]?.name || step.step_id} 开始执行`);
                } else if (step.status === 'completed') {
                    addLog('success', `✓ ${STEP_META[step.step_id]?.name || step.step_id} 完成` + (step.elapsed_seconds ? ` (${step.elapsed_seconds}s)` : ''));
                } else if (step.status === 'error') {
                    addLog('error', `✗ ${STEP_META[step.step_id]?.name || step.step_id} 失败: ${step.error_message || '未知错误'}`);
                }
            }

            updateStepUI(step.step_id, workflowState.steps[step.step_id]);
        });
    }

    showBrandInfoCard();
    if (data.stats) {
        updateBrandStats(data.stats);
    }
    if (data.runtime) {
        updateRuntimeStats(data.runtime, logChanges);
    }
}

async function resumeLatestWorkflow() {
    if (workflowState.workflowId) return;

    try {
        const resp = await fetch(window.adminUrl('api/automation-status.php?latest=1'));
        const data = await resp.json();
        if (!data.success || !data.workflow) return;

        applyWorkflowPayload(data, false);
        updatePipelineProgress();
        updateGlobalStatus();
        addLog('info', '已恢复最近一次工作流：' + data.workflow.id);

        if (workflowState.status === 'running' || hasBackgroundRuntime()) {
            startElapsedTimer();
            pollWorkflowStatus();
        } else if (workflowState.status === 'error' && data.workflow.error_message) {
            addLog('error', data.workflow.error_message);
        }
    } catch (err) {
        // 恢复失败不影响用户手动启动。
    }
}

// ── UI 更新 ───────────────────────────────────────────────────────────────────

function updateStepUI(stepId, stepData) {
    const card = document.getElementById('step-' + stepId);
    const badge = document.getElementById('step-badge-' + stepId);
    const iconWrap = document.getElementById('step-icon-' + stepId);
    const statusEl = document.getElementById('step-status-' + stepId);
    const metaEl = document.getElementById('step-meta-' + stepId);

    if (!card) return;

    // 重置类
    card.classList.remove('ring-2', 'ring-blue-500/30', 'ring-emerald-500/30', 'ring-red-500/30', 'bg-blue-50/50', 'bg-emerald-50/50', 'bg-red-50/50');

    switch (stepData.status) {
        case 'running':
            card.classList.add('ring-2', 'ring-blue-500/30', 'bg-blue-50/50');
            badge.className = 'inline-flex h-5 items-center rounded-full bg-blue-100 px-2 text-[10px] font-medium text-blue-700';
            badge.textContent = '运行中';
            iconWrap.innerHTML = '<i data-lucide="' + (STEP_META[stepId]?.icon || 'loader-2') + '" class="h-4.5 w-4.5 animate-pulse"></i>';
            statusEl.innerHTML = '<span class="flex h-4 w-4 items-center justify-center rounded-full bg-blue-500"><i data-lucide="loader-2" class="h-2.5 w-2.5 text-white animate-spin"></i></span>';
            statusEl.classList.remove('hidden');
            break;
        case 'completed':
            card.classList.add('ring-2', 'ring-emerald-500/30', 'bg-emerald-50/50');
            badge.className = 'inline-flex h-5 items-center rounded-full bg-emerald-100 px-2 text-[10px] font-medium text-emerald-700';
            badge.textContent = '完成';
            iconWrap.innerHTML = '<i data-lucide="' + (STEP_META[stepId]?.icon || 'check') + '" class="h-4.5 w-4.5"></i>';
            statusEl.innerHTML = '<span class="flex h-4 w-4 items-center justify-center rounded-full bg-emerald-500"><i data-lucide="check" class="h-2.5 w-2.5 text-white"></i></span>';
            statusEl.classList.remove('hidden');
            break;
        case 'error':
            card.classList.add('ring-2', 'ring-red-500/30', 'bg-red-50/50');
            badge.className = 'inline-flex h-5 items-center rounded-full bg-red-100 px-2 text-[10px] font-medium text-red-700';
            badge.textContent = '失败';
            iconWrap.innerHTML = '<i data-lucide="' + (STEP_META[stepId]?.icon || 'x') + '" class="h-4.5 w-4.5"></i>';
            statusEl.innerHTML = '<span class="flex h-4 w-4 items-center justify-center rounded-full bg-red-500"><i data-lucide="x" class="h-2.5 w-2.5 text-white"></i></span>';
            statusEl.classList.remove('hidden');
            break;
        default:
            badge.className = 'inline-flex h-5 items-center rounded-full bg-gray-100 px-2 text-[10px] font-medium text-gray-500';
            badge.textContent = '等待';
            iconWrap.innerHTML = '<i data-lucide="' + (STEP_META[stepId]?.icon || 'circle') + '" class="h-4.5 w-4.5"></i>';
            statusEl.classList.add('hidden');
    }

    // 更新耗时
    if (stepData.elapsed) {
        metaEl.querySelector('span:first-child').innerHTML = '<i data-lucide="clock" class="h-3 w-3"></i> ' + stepData.elapsed + 's';
    } else if (stepData.status === 'running' && stepData.startedAt) {
        const sec = Math.floor((Date.now() - new Date(stepData.startedAt).getTime()) / 1000);
        metaEl.querySelector('span:first-child').innerHTML = '<i data-lucide="clock" class="h-3 w-3"></i> ' + sec + 's';
    }

    if (typeof lucide !== 'undefined') lucide.createIcons();
}

function updatePipelineProgress() {
    const done = STEP_IDS.filter(id => workflowState.steps[id]?.status === 'completed').length;
    const total = STEP_IDS.length;
    const pct = Math.round((done / total) * 100);

    document.getElementById('pipeline-progress').style.width = pct + '%';
    document.getElementById('pipeline-pct').textContent = pct + '%';
}

function updateGlobalStatus() {
    const el = document.getElementById('global-status');
    const backgroundActive = hasBackgroundRuntime();
    const statusMap = {
        idle:      { text: '就绪',   dot: 'bg-gray-400',   bg: 'bg-gray-100',   textColor: 'text-gray-600' },
        running:   { text: '运行中', dot: 'bg-blue-500 animate-pulse', bg: 'bg-blue-50', textColor: 'text-blue-700' },
        background:{ text: '后台运行', dot: 'bg-blue-500 animate-pulse', bg: 'bg-blue-50', textColor: 'text-blue-700' },
        completed: { text: '已完成', dot: 'bg-emerald-500', bg: 'bg-emerald-50', textColor: 'text-emerald-700' },
        error:     { text: '出错',   dot: 'bg-red-500',    bg: 'bg-red-50',     textColor: 'text-red-700' },
    };
    const s = backgroundActive ? statusMap.background : (statusMap[workflowState.status] || statusMap.idle);
    el.className = `inline-flex items-center gap-1.5 rounded-full ${s.bg} px-3 py-1.5 text-xs font-medium ${s.textColor}`;
    el.innerHTML = `<span class="h-2 w-2 rounded-full ${s.dot}"></span>${s.text}`;
}

function hasBackgroundRuntime() {
    return workflowState.status === 'completed' && workflowState.runtime && (
        (workflowState.runtime.generation_pending || 0) > 0 ||
        (workflowState.runtime.generation_running || 0) > 0 ||
        (workflowState.runtime.distribution_queued || 0) > 0 ||
        (workflowState.runtime.distribution_running || 0) > 0
    );
}

function showBrandInfoCard() {
    const card = document.getElementById('brand-info-card');
    card.classList.remove('hidden');
    document.getElementById('brand-name').textContent = workflowState.brandInfo.brand_name;
    document.getElementById('brand-industry').textContent = workflowState.brandInfo.industry + (workflowState.brandInfo.website ? ' · ' + workflowState.brandInfo.website : '');
}

function updateBrandStats(stats) {
    if (stats.keywords !== undefined) document.getElementById('stat-keywords').textContent = stats.keywords;
    if (stats.titles !== undefined) document.getElementById('stat-titles').textContent = stats.titles;
    if (stats.articles !== undefined) document.getElementById('stat-articles').textContent = stats.articles;
    if (stats.published !== undefined) document.getElementById('stat-published').textContent = stats.published;
}

let lastRuntimeSignature = '';

function updateRuntimeStats(runtime, logChanges = false) {
    workflowState.runtime = runtime;

    const generationText = [
        `待生成 ${runtime.generation_pending || 0}`,
        `生成中 ${runtime.generation_running || 0}`,
        `失败 ${runtime.generation_failed || 0}`,
    ].join(' / ');

    const distributionText = [
        `待发 ${runtime.distribution_queued || 0}`,
        `发布中 ${runtime.distribution_running || 0}`,
        `手动 ${runtime.distribution_manual_queued || 0}`,
        `成功 ${runtime.distribution_success || 0}`,
        `失败 ${runtime.distribution_failed || 0}`,
    ].join(' / ');

    const monitorText = [
        `关键词 ${runtime.monitor_keywords || 0}`,
        `记录 ${runtime.monitor_records || 0}`,
        `提及 ${runtime.monitor_mentions || 0}`,
    ].join(' / ');

    document.getElementById('runtime-generation').textContent = generationText;
    document.getElementById('runtime-distribution').textContent = distributionText;
    document.getElementById('runtime-monitor').textContent = monitorText;

    const nextEl = document.getElementById('runtime-next-distribution');
    if (runtime.next_distribution_at) {
        nextEl.textContent = '下次发布：' + String(runtime.next_distribution_at).replace('T', ' ').slice(0, 16);
    } else {
        nextEl.textContent = '暂无排队发布时间';
    }

    const signature = JSON.stringify({
        gp: runtime.generation_pending || 0,
        gr: runtime.generation_running || 0,
        dq: runtime.distribution_queued || 0,
        dr: runtime.distribution_running || 0,
        ds: runtime.distribution_success || 0,
        mr: runtime.monitor_records || 0,
    });
    if (logChanges && lastRuntimeSignature && signature !== lastRuntimeSignature) {
        addLog('info', `后台状态：${generationText}；${distributionText}；${monitorText}`);
    }
    lastRuntimeSignature = signature;
}

// ── 日志系统 ──────────────────────────────────────────────────────────────────

function addLog(type, message) {
    const container = document.getElementById('log-container');
    const time = new Date().toLocaleTimeString('zh-CN', { hour12: false });

    // 如果是第一条（等待消息），清空
    if (logCount === 0) {
        container.innerHTML = '';
    }

    const typeColors = {
        info: 'text-blue-400',
        success: 'text-emerald-400',
        error: 'text-red-400',
        warn: 'text-yellow-400',
    };
    const typeIcons = {
        info: '●',
        success: '✓',
        error: '✗',
        warn: '▲',
    };

    const line = document.createElement('div');
    line.className = 'flex items-start gap-2 animate-[slideUp_0.2s_ease-out]';
    line.innerHTML = `
        <span class="text-[10px] text-gray-400 shrink-0 tabular-nums">${time}</span>
        <span class="${typeColors[type] || 'text-gray-600'} shrink-0">${typeIcons[type] || '●'}</span>
        <span class="text-gray-700 break-all">${escapeHtml(message)}</span>
    `;
    container.appendChild(line);

    logCount++;
    document.getElementById('log-count').textContent = logCount + ' 条日志';

    // 自动滚动
    if (document.getElementById('auto-scroll').checked) {
        container.scrollTop = container.scrollHeight;
    }

    // 保存到状态
    workflowState.logs.push({ time, type, message });
}

function clearLogs() {
    const container = document.getElementById('log-container');
    container.innerHTML = '<div class="flex items-start gap-2 text-gray-400"><span class="text-[10px] text-gray-300 shrink-0 tabular-nums">--:--:--</span><span>日志已清空</span></div>';
    logCount = 0;
    document.getElementById('log-count').textContent = '0 条日志';
}

// ── 计时器 ────────────────────────────────────────────────────────────────────

function startElapsedTimer() {
    stopElapsedTimer();
    elapsedTimer = setInterval(() => {
        if (!workflowState.startTime) return;
        const sec = Math.floor((Date.now() - workflowState.startTime) / 1000);
        const min = Math.floor(sec / 60);
        const s = sec % 60;
        document.getElementById('elapsed-time').textContent =
            String(min).padStart(2, '0') + ':' + String(s).padStart(2, '0');

        // 更新当前运行步骤的耗时
        STEP_IDS.forEach(id => {
            const step = workflowState.steps[id];
            if (step?.status === 'running' && step.startedAt) {
                const el = document.getElementById('step-meta-' + id);
                if (el) {
                    const elapsed = Math.floor((Date.now() - new Date(step.startedAt).getTime()) / 1000);
                    el.querySelector('span:first-child').innerHTML = '<i data-lucide="clock" class="h-3 w-3"></i> ' + elapsed + 's';
                    if (typeof lucide !== 'undefined') lucide.createIcons();
                }
            }
        });
    }, 1000);
}

function stopElapsedTimer() {
    if (elapsedTimer) {
        clearInterval(elapsedTimer);
        elapsedTimer = null;
    }
}

// ── 工具函数 ──────────────────────────────────────────────────────────────────

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function showToast(message, type = 'info') {
    if (typeof AdminUtils !== 'undefined' && AdminUtils.showToast) {
        AdminUtils.showToast(message, type);
    } else {
        alert(message);
    }
}

// ── 演示模式（无需后端） ──────────────────────────────────────────────────────
// 如果后端 API 未就绪，可以用演示模式查看效果
function runDemo() {
    closeStartModal();
    workflowState.brandInfo = {
        brand_name: '演示品牌',
        industry: '互联网 / 科技',
        website: 'demo.com',
    };
    workflowState.status = 'running';
    workflowState.startTime = Date.now();
    workflowState.workflowId = 'demo-' + Date.now();

    STEP_IDS.forEach(id => {
        workflowState.steps[id] = { status: 'pending' };
    });

    updateGlobalStatus();
    showBrandInfoCard();
    startElapsedTimer();
    addLog('info', '🧪 演示模式启动');
    addLog('info', '品牌：演示品牌 · 互联网 / 科技');

    // 模拟步骤执行
    let delay = 1000;
    STEP_IDS.forEach((id, i) => {
        setTimeout(() => {
            workflowState.steps[id] = { status: 'running', startedAt: new Date().toISOString() };
            updateStepUI(id, workflowState.steps[id]);
            updatePipelineProgress();
            addLog('info', `▶ ${STEP_META[id].name} 开始执行`);
        }, delay);
        delay += 2000 + Math.random() * 2000;

        setTimeout(() => {
            workflowState.steps[id].status = 'completed';
            workflowState.steps[id].elapsed = Math.floor((Date.now() - new Date(workflowState.steps[id].startedAt).getTime()) / 1000);
            updateStepUI(id, workflowState.steps[id]);
            updatePipelineProgress();
            addLog('success', `✓ ${STEP_META[id].name} 完成 (${workflowState.steps[id].elapsed}s)`);

            // 更新统计
            if (id === 'keywords') updateBrandStats({ keywords: 32 });
            if (id === 'titles') updateBrandStats({ titles: 20 });
            if (id === 'generate') updateBrandStats({ articles: 10 });
            if (id === 'distribute') updateBrandStats({ published: 8 });

            // 最后一步完成
            if (i === STEP_IDS.length - 1) {
                workflowState.status = 'completed';
                updateGlobalStatus();
                stopElapsedTimer();
                addLog('success', '🎉 全部流程执行完成！');
            }
        }, delay);
        delay += 1000 + Math.random() * 1000;
    });
}

document.addEventListener('DOMContentLoaded', resumeLatestWorkflow);
</script>
JS;

echo $additional_js;
?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
