<?php
/**
 * 中文 AI 偏好对照表
 */

define('FEISHU_TREASURE', true);
session_start();

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/database_admin.php';

require_admin_login();

// ── 当前客户上下文 ────────────────────────────────────────────────────────
$currentCustomer  = $_SESSION['current_customer'] ?? [];
$currentCustId    = (string) ($currentCustomer['id']       ?? '');
$currentCustName  = (string) ($currentCustomer['name']     ?? '');
$currentIndustry  = (string) ($currentCustomer['industry'] ?? '');
$settingKey       = 'geo_pref_industry_' . $currentCustId;

// ── POST: 应用行业偏好到分发策略 ──────────────────────────────────────────
$_prefMessage = '';
$_prefError   = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'apply_industry_pref') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $_prefError = 'CSRF验证失败';
    } elseif ($currentCustId === '') {
        $_prefError = '请先在客户中心选择客户';
    } else {
        $payload = [
            'industry'    => trim((string) ($_POST['industry_name'] ?? '')),
            'ais'         => json_decode($_POST['ais_json']       ?? '[]', true) ?: [],
            'platforms'   => json_decode($_POST['platforms_json'] ?? '[]', true) ?: [],
            'applied_at'  => date('Y-m-d H:i:s'),
            'applied_by'  => $_SESSION['admin_username'] ?? 'admin',
        ];
        set_setting($settingKey, json_encode($payload, JSON_UNESCAPED_UNICODE));
        header('Location: ' . admin_url('ai-citation-preferences.php?applied=1'));
        exit;
    }
}
if (isset($_GET['applied'])) {
    $_prefMessage = '行业偏好已保存，分发页面将优先显示推荐平台';
}

// 已保存的配置
$savedPref = json_decode(get_setting($settingKey, '{}'), true) ?: [];

$page_title = 'AI偏好对照表';

function pref_h($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function pref_score_class(int $score): string {
    return [
        5 => 'bg-[#185FA5] text-white',
        4 => 'bg-[#378ADD] text-white',
        3 => 'bg-[#85B7EB] text-blue-950',
        2 => 'bg-[#E6F1FB] text-blue-900',
        1 => 'bg-gray-100 text-gray-700 ring-1 ring-gray-300',
    ][$score] ?? 'bg-gray-100 text-gray-700 ring-1 ring-gray-300';
}

function pref_provider_label(string $key): string {
    return [
        'doubao' => '豆包',
        'kimi' => 'Kimi',
        'deepseek' => 'DeepSeek',
        'tongyi' => '通义',
        'wenxin' => '文心',
        'yuanbao' => '元宝',
    ][$key] ?? $key;
}

$version = [
    'label' => '2026-05',
    'source_type' => 'manual_estimate',
    'source_label' => '公开案例反推估算',
    'sample_size' => null,
    'published_at' => '2026-05-16',
];

$aiProviders = ['doubao', 'kimi', 'deepseek', 'tongyi', 'wenxin', 'yuanbao'];

$matrixRows = [
    ['key' => 'bytedance', 'name' => '抖音 / 头条 / 抖音百科', 'sub' => '字节系独占', 'category' => '生态平台', 'scores' => ['doubao' => 5, 'kimi' => 1, 'deepseek' => 1, 'tongyi' => 1, 'wenxin' => 1, 'yuanbao' => 1], 'note' => '豆包强绑定字节生态，适合消费种草、本地生活和短视频脚本。'],
    ['key' => 'baidu', 'name' => '百度百科 / 百家号', 'sub' => '文心一言核心源', 'category' => '生态平台', 'scores' => ['doubao' => 1, 'kimi' => 2, 'deepseek' => 2, 'tongyi' => 2, 'wenxin' => 5, 'yuanbao' => 1], 'note' => '文心一言明显偏百度系，适合品牌实体、百科和知识科普。'],
    ['key' => 'wechat_public', 'name' => '微信公众号', 'sub' => '腾讯元宝独占 3.6 亿篇', 'category' => '生态平台', 'scores' => ['doubao' => 1, 'kimi' => 3, 'deepseek' => 3, 'tongyi' => 2, 'wenxin' => 1, 'yuanbao' => 5], 'note' => '元宝对微信生态更友好，适合深度长文、个人 IP 和客户案例。'],
    ['key' => 'alibaba', 'name' => '淘宝 / 天猫 / 阿里生态', 'sub' => '通义千问优势', 'category' => '生态平台', 'scores' => ['doubao' => 1, 'kimi' => 2, 'deepseek' => 2, 'tongyi' => 5, 'wenxin' => 1, 'yuanbao' => 1], 'note' => '通义在电商、企业服务和阿里云/钉钉生态里优势明显。'],
    ['key' => 'zhihu', 'name' => '知乎', 'sub' => '全平台通吃', 'category' => 'UGC', 'scores' => ['doubao' => 3, 'kimi' => 5, 'deepseek' => 4, 'tongyi' => 4, 'wenxin' => 3, 'yuanbao' => 3], 'note' => '知乎是最值得优先铺设的跨 AI 通用内容平台之一。'],
    ['key' => 'xiaohongshu', 'name' => '小红书', 'sub' => '消费决策类', 'category' => 'UGC', 'scores' => ['doubao' => 3, 'kimi' => 3, 'deepseek' => 2, 'tongyi' => 3, 'wenxin' => 2, 'yuanbao' => 2], 'note' => '适合消费品、美妆、餐饮、本地生活，不适合严肃 B2B 决策主战场。'],
    ['key' => 'bilibili', 'name' => 'B 站', 'sub' => '科普 / 测评', 'category' => 'UGC', 'scores' => ['doubao' => 4, 'kimi' => 3, 'deepseek' => 3, 'tongyi' => 3, 'wenxin' => 2, 'yuanbao' => 2], 'note' => '视频测评和科普内容对豆包、Kimi、DeepSeek 有一定外溢价值。'],
    ['key' => 'media', 'name' => '36氪 / 虎嗅 / 媒体', 'sub' => '主流媒体', 'category' => '媒体', 'scores' => ['doubao' => 3, 'kimi' => 4, 'deepseek' => 4, 'tongyi' => 4, 'wenxin' => 3, 'yuanbao' => 3], 'note' => '媒体报道是从“自证”变成“被推荐”的关键第三方信号。'],
    ['key' => 'tech_blog', 'name' => 'CSDN / 掘金 / 技术博客', 'sub' => 'DeepSeek 技术问答首选', 'category' => '技术内容', 'scores' => ['doubao' => 2, 'kimi' => 4, 'deepseek' => 5, 'tongyi' => 4, 'wenxin' => 2, 'yuanbao' => 2], 'note' => '技术文档、参数表、教程和对比页会显著影响 DeepSeek/Kimi。'],
    ['key' => 'academic', 'name' => 'arXiv / 学术论文', 'sub' => 'Kimi 长文档优势', 'category' => '权威资料', 'scores' => ['doubao' => 2, 'kimi' => 5, 'deepseek' => 5, 'tongyi' => 3, 'wenxin' => 2, 'yuanbao' => 2], 'note' => '适合教育、医疗、金融、AI/技术类品牌强化权威性。'],
    ['key' => 'github', 'name' => 'GitHub', 'sub' => '代码 / 工程类', 'category' => '技术内容', 'scores' => ['doubao' => 2, 'kimi' => 4, 'deepseek' => 5, 'tongyi' => 4, 'wenxin' => 2, 'yuanbao' => 2], 'note' => '工程类和开源类内容对 DeepSeek 极其友好。'],
    ['key' => 'authority', 'name' => '政府 / 高校 / 白皮书', 'sub' => '权威机构通吃', 'category' => '权威资料', 'scores' => ['doubao' => 3, 'kimi' => 4, 'deepseek' => 4, 'tongyi' => 4, 'wenxin' => 4, 'yuanbao' => 4], 'note' => '跨 AI 平均分最高，预算有限时优先补权威引用和白皮书。'],
    ['key' => 'brand', 'name' => '品牌官网', 'sub' => '全平台低权重', 'category' => '自有阵地', 'scores' => ['doubao' => 2, 'kimi' => 2, 'deepseek' => 2, 'tongyi' => 2, 'wenxin' => 2, 'yuanbao' => 2], 'note' => '官网是基础，但单独依赖官网通常不够，需要第三方与生态内容配合。'],
];

foreach ($matrixRows as &$row) {
    $row['avg'] = round(array_sum($row['scores']) / count($row['scores']), 1);
    arsort($row['scores']);
    $row['top_ais'] = array_slice($row['scores'], 0, 3, true);
    $row['scores'] = array_replace(array_fill_keys($aiProviders, 0), $row['scores']);
}
unset($row);
$leverageRows = $matrixRows;
usort($leverageRows, static fn($a, $b) => $b['avg'] <=> $a['avg']);

$ecosystems = [
    ['ai' => 'doubao', 'corp' => '字节跳动', 'sources' => ['抖音', '今日头条', '抖音百科', '番茄小说'], 'strength' => '短视频脚本、消费决策、年轻化内容', 'weakness' => '严肃技术、学术、政策类'],
    ['ai' => 'kimi', 'corp' => '月之暗面', 'sources' => ['arXiv 论文', '学术著作', '长文档', '权威媒体'], 'strength' => '长文档解析、学术研究、深度报告', 'weakness' => '电商、本地生活、热点八卦'],
    ['ai' => 'deepseek', 'corp' => '深度求索', 'sources' => ['GitHub', '技术博客', 'CSDN', 'arXiv', 'Stack Overflow'], 'strength' => '代码、数学、推理、技术问答', 'weakness' => '生活资讯、娱乐、本地服务'],
    ['ai' => 'tongyi', 'corp' => '阿里巴巴', 'sources' => ['淘宝', '天猫', '钉钉文档', '阿里云文档'], 'strength' => '电商、企业服务、商业类查询', 'weakness' => '微信生态内容、短视频内容'],
    ['ai' => 'wenxin', 'corp' => '百度', 'sources' => ['百度百科', '百家号', '知道', '文库', '学术'], 'strength' => '中文常识、知识科普、传统文化', 'weakness' => '微信生态、字节系内容'],
    ['ai' => 'yuanbao', 'corp' => '腾讯', 'sources' => ['微信公众号', '搜狗百科', '腾讯新闻'], 'strength' => '深度长文、个人 IP 内容、社交话题', 'weakness' => '电商、字节系、百度系内容'],
];

$industries = [
    ['name' => 'B2B SaaS / 企业服务', 'ais' => ['deepseek', 'tongyi', 'kimi'], 'platforms' => ['知乎专栏', 'CSDN', '36氪', '掘金', '钉钉文档', 'GitHub README'], 'reason' => '决策者多用 DeepSeek/Kimi 查技术，采购流程依赖钉钉/阿里云生态。', 'avoid' => '不必优先投入抖音、小红书，B2B 决策者通常不在那里完成采购判断。', 'service' => 'B2B 权威内容包：知乎/CSDN 技术问答、36氪媒体背书、官网方案页和 GitHub README 优化。'],
    ['name' => '消费品 / 美妆 / 食品', 'ais' => ['doubao', 'yuanbao', 'tongyi'], 'platforms' => ['抖音', '小红书', '微信公众号', '淘宝评价', '今日头条'], 'reason' => '抖音种草、微信深度和淘宝比价，正好对应豆包、元宝、通义生态。', 'avoid' => '不必把 DeepSeek、Kimi 当主战场，它们不是消费决策的首选入口。', 'service' => '消费种草内容包：抖音/小红书测评、公众号深度种草、淘宝评价信号和头条内容铺设。'],
    ['name' => '教育 / 知识付费', 'ais' => ['kimi', 'wenxin', 'yuanbao'], 'platforms' => ['知乎', '微信公众号', 'B站科普', '百度文库', 'arXiv'], 'reason' => 'Kimi 面向长文和研究，文心连接百度文库，公众号长文转化强。', 'avoid' => '抖音引用偏娱乐化，严肃教育内容权重偏低。', 'service' => '知识权威内容包：知乎长答、公众号课程案例、B站科普、百度文库资料和研究引用整理。'],
    ['name' => '医疗 / 健康', 'ais' => ['wenxin', 'kimi', 'deepseek'], 'platforms' => ['丁香园', '百度健康', '知乎健康', '微信医疗公众号', '卫健委站'], 'reason' => '医疗查询偏理性，需要权威信源、公开机构和学术证据支撑。', 'avoid' => '小红书医美内容容易去权重，抖音医疗合规风险高。', 'service' => '医疗可信内容包：权威健康问答、百度健康/丁香园信号、医疗公众号和公开机构引用建设。'],
    ['name' => '金融 / 投资', 'ais' => ['deepseek', 'kimi', 'yuanbao'], 'platforms' => ['雪球', '36氪', '虎嗅', '微信公众号', '券商研报', '财新'], 'reason' => 'DeepSeek 做数据推理，Kimi 读研报，元宝吃公众号深度内容。', 'avoid' => '抖音金融合规收紧，小红书理财内容权重低。', 'service' => '金融深度内容包：研报解读、雪球话题、财经媒体背书、公众号长文和数据型 FAQ。'],
    ['name' => '本地生活 / 餐饮', 'ais' => ['doubao', 'tongyi', 'yuanbao'], 'platforms' => ['抖音同城', '大众点评', '小红书', '美团', '微信本地公众号'], 'reason' => '抖音本地生活生态最强，大众点评/美团影响消费决策，微信本地号活跃。', 'avoid' => 'Kimi、DeepSeek 几乎不处理本地生活即时查询。', 'service' => '本地生活曝光包：抖音同城内容、大众点评/美团资料、小红书笔记和本地公众号铺设。'],
];

// 找到当前客户匹配的行业推荐
$matchedIndustry = null;
foreach ($industries as $ind) {
    if ($currentIndustry !== '' && stripos($ind['name'], explode('/', $currentIndustry)[0]) !== false) {
        $matchedIndustry = $ind;
        break;
    }
}

$versionLog = [
    ['date' => '2026-05-16', 'note' => '初版矩阵上线，13 平台 × 6 AI，数据来源：公开案例反推估算'],
    ['date' => '2026-05-17', 'note' => '新增行业偏好一键应用功能，支持绑定当前客户并写入分发策略'],
];

require_once __DIR__ . '/includes/header.php';
?>
<?php if ($_prefMessage): ?>
<div class="mb-4 rounded-md border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800"><?= pref_h($_prefMessage) ?></div>
<?php endif; ?>
<?php if ($_prefError): ?>
<div class="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800"><?= pref_h($_prefError) ?></div>
<?php endif; ?>

<?php if ($currentCustName): ?>
<!-- 当前客户上下文条 -->
<div class="mb-5 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-blue-200 bg-blue-50 px-5 py-3">
    <div class="flex flex-wrap items-center gap-3 text-sm">
        <i data-lucide="user-check" class="h-4 w-4 text-blue-600"></i>
        <span class="font-semibold text-blue-900"><?= pref_h($currentCustName) ?></span>
        <span class="text-blue-600"><?= pref_h($currentIndustry) ?></span>
        <?php if ($matchedIndustry): ?>
            <span class="rounded-full bg-blue-100 px-2.5 py-0.5 text-xs font-medium text-blue-700">已匹配行业推荐方案</span>
        <?php else: ?>
            <span class="rounded-full bg-gray-100 px-2.5 py-0.5 text-xs text-gray-500">行业未匹配，请手动查阅</span>
        <?php endif; ?>
        <?php if (!empty($savedPref['applied_at'])): ?>
            <span class="text-xs text-blue-500">偏好已应用 · <?= pref_h($savedPref['applied_at']) ?></span>
        <?php endif; ?>
    </div>
    <a href="<?= pref_h(admin_url('customers.php?switch_customer=1#customer-switcher')) ?>" class="text-xs text-blue-600 hover:underline">切换客户</a>
</div>
<?php endif; ?>

            <div class="mb-8">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <h1 class="text-3xl font-bold text-gray-900">AI偏好对照表</h1>
                        <p class="mt-1 text-sm text-gray-600">13 个内容平台 × 6 家中文 AI 的引用偏好、生态绑定与行业投放策略</p>
                    </div>
                    <div class="flex flex-wrap items-center gap-3">
                        <span class="inline-flex items-center rounded-full bg-blue-50 px-3 py-1 text-sm font-medium text-blue-700">
                            版本 <?php echo pref_h($version['label']); ?>
                        </span>
                        <a href="<?php echo pref_h(admin_url('citation-simulator.php')); ?>" class="inline-flex items-center justify-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50">
                            <i data-lucide="quote" class="mr-2 h-4 w-4"></i>
                            引用模拟器
                        </a>
                    </div>
                </div>
            </div>

            <div class="mb-6 grid grid-cols-1 gap-4 md:grid-cols-4">
                <div class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
                    <div class="text-sm font-medium text-gray-500">内容平台</div>
                    <div class="mt-2 text-3xl font-bold text-gray-900">13 个</div>
                    <p class="mt-2 text-sm text-gray-500">覆盖生态、UGC、媒体、权威、自有阵地。</p>
                </div>
                <div class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
                    <div class="text-sm font-medium text-gray-500">中文 AI</div>
                    <div class="mt-2 text-3xl font-bold text-gray-900">6 家</div>
                    <p class="mt-2 text-sm text-gray-500">豆包、Kimi、DeepSeek、通义、文心、元宝。</p>
                </div>
                <div class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
                    <div class="text-sm font-medium text-gray-500">数据来源</div>
                    <div class="mt-2 text-xl font-semibold text-gray-900"><?php echo pref_h($version['source_label']); ?></div>
                    <p class="mt-2 text-sm text-gray-500">基于公开案例、平台生态和中文 AI 引用特征整理。</p>
                </div>
                <div class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
                    <div class="text-sm font-medium text-gray-500">更新时间</div>
                    <div class="mt-2 text-xl font-semibold text-gray-900"><?php echo pref_h($version['published_at']); ?></div>
                    <p class="mt-2 text-sm text-gray-500">矩阵建议按月更新，避免引用偏好过期。</p>
                </div>
            </div>

            <section class="mb-6 rounded-lg border border-gray-200 bg-white shadow-sm">
                <div class="flex flex-col gap-3 border-b border-gray-200 px-6 py-4 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900">引用偏好矩阵</h2>
                        <p class="mt-1 text-sm text-gray-500">1 分代表弱引用偏好，5 分代表强引用偏好。颜色越深，越值得优先投入。</p>
                    </div>
                    <div class="flex items-center gap-2 text-xs text-gray-500">
                        <span class="inline-flex h-5 w-5 items-center justify-center rounded <?php echo pref_score_class(1); ?>">1</span>
                        <span>低</span>
                        <span class="inline-flex h-5 w-5 items-center justify-center rounded <?php echo pref_score_class(3); ?>">3</span>
                        <span>中</span>
                        <span class="inline-flex h-5 w-5 items-center justify-center rounded <?php echo pref_score_class(5); ?>">5</span>
                        <span>高</span>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                            <tr>
                                <th class="sticky left-0 z-10 bg-gray-50 px-6 py-3">内容来源</th>
                                <?php foreach ($aiProviders as $provider): ?>
                                    <th class="px-4 py-3 text-center"><?php echo pref_h(pref_provider_label($provider)); ?></th>
                                <?php endforeach; ?>
                                <th class="px-4 py-3 text-center">均值</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 bg-white">
                            <?php foreach ($matrixRows as $row): ?>
                                <tr class="align-middle hover:bg-gray-50">
                                    <td class="sticky left-0 z-10 min-w-[260px] bg-white px-6 py-4">
                                        <div class="font-semibold text-gray-900"><?php echo pref_h($row['name']); ?></div>
                                        <div class="mt-1 flex flex-wrap items-center gap-2">
                                            <span class="text-xs text-gray-500"><?php echo pref_h($row['sub']); ?></span>
                                            <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-600"><?php echo pref_h($row['category']); ?></span>
                                        </div>
                                        <div class="mt-2 text-xs leading-5 text-gray-500"><?php echo pref_h($row['note']); ?></div>
                                    </td>
                                    <?php foreach ($aiProviders as $provider): ?>
                                        <?php $score = (int) $row['scores'][$provider]; ?>
                                        <td class="px-4 py-4 text-center">
                                            <span class="inline-flex h-9 w-9 items-center justify-center rounded-md text-sm font-bold <?php echo pref_score_class($score); ?>" title="<?php echo pref_h($row['note']); ?>">
                                                <?php echo $score; ?>
                                            </span>
                                        </td>
                                    <?php endforeach; ?>
                                    <td class="px-4 py-4 text-center">
                                        <span class="font-semibold text-gray-900"><?php echo pref_h($row['avg']); ?></span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="mb-6 rounded-lg border border-gray-200 bg-white shadow-sm">
                <div class="border-b border-gray-200 px-6 py-4">
                    <h2 class="text-lg font-semibold text-gray-900">生态绑定关系</h2>
                </div>
                <div class="grid grid-cols-1 gap-4 p-6 lg:grid-cols-2 xl:grid-cols-3">
                    <?php foreach ($ecosystems as $item): ?>
                        <div class="rounded-lg border border-gray-200 p-4">
                            <div class="flex items-center justify-between gap-3">
                                <div class="font-semibold text-gray-900"><?php echo pref_h(pref_provider_label($item['ai'])); ?></div>
                                <span class="rounded-full bg-gray-100 px-2 py-1 text-xs font-medium text-gray-600"><?php echo pref_h($item['corp']); ?></span>
                            </div>
                            <div class="mt-3 flex flex-wrap gap-2">
                                <?php foreach ($item['sources'] as $source): ?>
                                    <span class="rounded-full bg-blue-50 px-2 py-1 text-xs font-medium text-blue-700"><?php echo pref_h($source); ?></span>
                                <?php endforeach; ?>
                            </div>
                            <div class="mt-4 grid grid-cols-1 gap-3 text-sm">
                                <div>
                                    <div class="font-medium text-gray-900">擅长</div>
                                    <p class="mt-1 leading-6 text-gray-600"><?php echo pref_h($item['strength']); ?></p>
                                </div>
                                <div>
                                    <div class="font-medium text-gray-900">短板</div>
                                    <p class="mt-1 leading-6 text-gray-600"><?php echo pref_h($item['weakness']); ?></p>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <div class="mb-6 grid grid-cols-1 gap-6 xl:grid-cols-3">
                <section class="rounded-lg border border-gray-200 bg-white shadow-sm xl:col-span-2">
                    <div class="flex items-center justify-between border-b border-gray-200 px-6 py-4">
                        <h2 class="text-lg font-semibold text-gray-900">按行业看推荐</h2>
                        <?php if ($matchedIndustry): ?>
                            <span class="rounded-full bg-blue-100 px-3 py-1 text-xs font-semibold text-blue-700">当前客户匹配：<?= pref_h($matchedIndustry['name']) ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="grid grid-cols-1 gap-4 p-6 lg:grid-cols-2">
                        <?php
                        // 把匹配行业排到最前面
                        $sortedIndustries = $industries;
                        if ($matchedIndustry) {
                            usort($sortedIndustries, fn($a) => $a['name'] === $matchedIndustry['name'] ? -1 : 1);
                        }
                        foreach ($sortedIndustries as $industry):
                            $isMatched = $matchedIndustry && $industry['name'] === $matchedIndustry['name'];
                        ?>
                            <div class="rounded-lg border <?= $isMatched ? 'border-blue-400 bg-blue-50 ring-2 ring-blue-200' : 'border-gray-200' ?> p-4">
                                <div class="flex items-center justify-between gap-2">
                                    <div class="font-semibold text-gray-900"><?php echo pref_h($industry['name']); ?></div>
                                    <?php if ($isMatched): ?>
                                        <span class="shrink-0 rounded-full bg-blue-600 px-2 py-0.5 text-xs font-bold text-white">当前客户</span>
                                    <?php endif; ?>
                                </div>
                                <div class="mt-3 flex flex-wrap gap-2">
                                    <?php foreach ($industry['ais'] as $index => $ai): ?>
                                        <span class="rounded-full bg-blue-50 px-2 py-1 text-xs font-medium text-blue-700">#<?php echo $index + 1; ?> <?php echo pref_h(pref_provider_label($ai)); ?></span>
                                    <?php endforeach; ?>
                                </div>
                                <div class="mt-3 flex flex-wrap gap-2">
                                    <?php foreach ($industry['platforms'] as $platform): ?>
                                        <span class="rounded-full bg-gray-100 px-2 py-1 text-xs text-gray-600"><?php echo pref_h($platform); ?></span>
                                    <?php endforeach; ?>
                                </div>
                                <p class="mt-3 text-sm leading-6 text-gray-600"><?php echo pref_h($industry['reason']); ?></p>
                                <div class="mt-3 rounded-md bg-amber-50 px-3 py-2 text-sm leading-6 text-amber-800"><?php echo pref_h($industry['avoid']); ?></div>
                                <div class="mt-3 rounded-md bg-blue-50 px-3 py-2 text-sm leading-6 text-blue-800">
                                    <span class="font-semibold">服务方案：</span><?php echo pref_h($industry['service']); ?>
                                </div>
                                <?php if ($isMatched && $currentCustId !== ''): ?>
                                <!-- 一键应用到分发优先级 -->
                                <form method="POST" class="mt-4">
                                    <input type="hidden" name="csrf_token"     value="<?= generate_csrf_token() ?>">
                                    <input type="hidden" name="action"         value="apply_industry_pref">
                                    <input type="hidden" name="industry_name"  value="<?= pref_h($industry['name']) ?>">
                                    <input type="hidden" name="ais_json"       value="<?= pref_h(json_encode($industry['ais'],       JSON_UNESCAPED_UNICODE)) ?>">
                                    <input type="hidden" name="platforms_json" value="<?= pref_h(json_encode($industry['platforms'], JSON_UNESCAPED_UNICODE)) ?>">
                                    <div class="flex gap-2">
                                        <button type="submit" class="inline-flex flex-1 items-center justify-center rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700">
                                            <i data-lucide="zap" class="mr-2 h-4 w-4"></i>
                                            一键应用到分发优先级
                                        </button>
                                        <a href="<?= pref_h(admin_url('distribution.php')) ?>" class="inline-flex items-center rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-600 hover:bg-gray-50">
                                            前往分发
                                        </a>
                                    </div>
                                    <?php if (!empty($savedPref['applied_at'])): ?>
                                    <p class="mt-2 text-xs text-gray-400">上次应用：<?= pref_h($savedPref['applied_at']) ?> · 推荐平台：<?= pref_h(implode('、', $savedPref['platforms'] ?? [])) ?></p>
                                    <?php endif; ?>
                                </form>
                                <?php elseif ($isMatched && $currentCustId === ''): ?>
                                <p class="mt-3 text-xs text-gray-400">请先<a href="<?= pref_h(admin_url('customers.php')) ?>" class="text-blue-500 hover:underline">选择客户</a>再应用行业偏好</p>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>

                <section class="rounded-lg border border-gray-200 bg-white shadow-sm">
                    <div class="border-b border-gray-200 px-6 py-4">
                        <h2 class="text-lg font-semibold text-gray-900">跨平台搬运效应</h2>
                        <p class="mt-1 text-sm text-gray-500">右侧大数字是跨 6 家 AI 的平均偏好分；标签里的数字是该平台在对应 AI 里的 1-5 偏好分。</p>
                    </div>
                    <div class="space-y-3 p-6">
                        <?php foreach (array_slice($leverageRows, 0, 8) as $index => $row): ?>
                            <div class="rounded-lg border <?php echo $index < 3 ? 'border-blue-200 bg-blue-50' : 'border-gray-200 bg-white'; ?> p-3">
                                <div class="flex items-center justify-between gap-3">
                                    <div class="min-w-0">
                                        <div class="font-semibold text-gray-900">#<?php echo $index + 1; ?> <?php echo pref_h($row['name']); ?></div>
                                        <div class="mt-1 text-xs text-gray-500"><?php echo pref_h($row['sub']); ?></div>
                                    </div>
                                    <div class="text-lg font-bold text-gray-900"><?php echo pref_h($row['avg']); ?></div>
                                </div>
                                <div class="mt-2 flex flex-wrap gap-2">
                                    <?php foreach ($row['top_ais'] as $ai => $score): ?>
                                        <span class="rounded-full bg-white px-2 py-1 text-xs text-gray-600"><?php echo pref_h(pref_provider_label($ai)); ?> 偏好分 <?php echo (int) $score; ?></span>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <div class="rounded-lg bg-gray-900 p-4 text-sm leading-6 text-white">
                            预算有限时，先攻克政府/高校/白皮书、知乎、媒体和技术博客这类高杠杆平台；它们更容易被多家 AI 同时引用。
                        </div>
                    </div>
                </section>
            </div>
            <!-- 版本变更日志 -->
            <section class="mb-6 rounded-lg border border-gray-200 bg-white shadow-sm">
                <div class="border-b border-gray-200 px-6 py-4">
                    <h2 class="text-base font-semibold text-gray-900">版本变更日志</h2>
                    <p class="mt-0.5 text-xs text-gray-500">矩阵数据来自公开案例反推估算，建议按月核对 AI 生态变化后更新。</p>
                </div>
                <div class="divide-y divide-gray-100">
                    <?php foreach (array_reverse($versionLog) as $log): ?>
                    <div class="flex items-start gap-4 px-6 py-3 text-sm">
                        <span class="shrink-0 font-mono text-xs text-gray-400"><?= pref_h($log['date']) ?></span>
                        <span class="text-gray-600"><?= pref_h($log['note']) ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </section>
<?php
require_once __DIR__ . '/includes/footer.php';
?>
