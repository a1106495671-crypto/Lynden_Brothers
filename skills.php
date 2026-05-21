<?php
define('FEISHU_TREASURE', true);
session_start();

require_once 'includes/config.php';
require_once 'includes/database.php';
require_once 'includes/functions.php';
require_once 'includes/seo_functions.php';

$site_title = site_setting_value('site_name', SITE_NAME);
$page_title = 'Yao GEO Skills - ' . $site_title;
$page_description = 'Yao GEO Skills 是面向 GEO 运营、效果追踪和 GEOFlow 自动化的可复用 Skill 包合集。';
$canonical_url = geo_absolute_url('/skills');
$registry_path = __DIR__ . '/public-geo-skills/registry/skills.json';
$registry = is_file($registry_path) ? json_decode(file_get_contents($registry_path), true) : [];
$skills = $registry['skills'] ?? [];

$skill_labels = [
    'yao-geoflow-cli' => [
        'title' => 'GEOFlow CLI 运营',
        'accent' => 'from-slate-900 to-sky-800',
        'icon' => 'terminal',
        'cn' => '通过本地 geoflow CLI/API 管理任务、上传草稿、审核和发布文章。'
    ],
    'yao-geo-tracking' => [
        'title' => 'GEO 效果追踪与归因',
        'accent' => 'from-emerald-800 to-lime-700',
        'icon' => 'activity',
        'cn' => '围绕官网、转化动作和市场环境生成企业级 GEO 后端效果追踪方案。'
    ],
    'yao-geoflow-template' => [
        'title' => 'GEOFlow 模板映射',
        'accent' => 'from-orange-800 to-amber-600',
        'icon' => 'layout-template',
        'cn' => '把参考站视觉风格映射成 GEOFlow 兼容的 preview-first 主题包方案。'
    ],
    'yao-geoflow-design' => [
        'title' => 'GEOFlow 主题设计迭代',
        'accent' => 'from-cyan-900 to-teal-600',
        'icon' => 'palette',
        'cn' => '发现、预览、克隆和优化 GEOFlow 前台主题，同时守住渲染与 SEO 契约。'
    ]
];

function skill_doc_url(array $skill): string {
    return '/public-geo-skills/docs/skills/' . rawurlencode($skill['id']) . '.md';
}

function skill_package_url(array $skill): string {
    return '/public-geo-skills/' . ltrim($skill['path'], '/') . '/SKILL.md';
}

function skill_manifest_url(array $skill): string {
    return '/public-geo-skills/' . ltrim($skill['path'], '/') . '/manifest.json';
}

$structured_data_blocks = [
    generate_website_structured_data(),
    json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'CollectionPage',
        'name' => 'Yao GEO Skills',
        'description' => $page_description,
        'url' => $canonical_url,
        'hasPart' => array_map(function ($skill) {
            return [
                '@type' => 'SoftwareSourceCode',
                'name' => $skill['id'],
                'description' => $skill['summary'] ?? '',
                'url' => geo_absolute_url(skill_package_url($skill))
            ];
        }, $skills)
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
];
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?></title>
    <meta name="description" content="<?php echo htmlspecialchars($page_description); ?>">
    <link rel="canonical" href="<?php echo htmlspecialchars($canonical_url); ?>">
    <meta property="og:title" content="<?php echo htmlspecialchars($page_title); ?>">
    <meta property="og:description" content="<?php echo htmlspecialchars($page_description); ?>">
    <meta property="og:type" content="website">
    <meta property="og:url" content="<?php echo htmlspecialchars($canonical_url); ?>">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="/assets/css/style.css">
    <link rel="stylesheet" href="/assets/css/custom.css">
    <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
    <?php output_site_head_extras(); ?>
    <?php output_structured_data_blocks($structured_data_blocks); ?>
</head>
<body class="bg-[#f7f4ec] text-gray-900">
    <?php include 'includes/header.php'; ?>

    <main class="site-container px-4 sm:px-6 lg:px-8 py-10">
        <section class="relative overflow-hidden rounded-[2rem] bg-[#111827] text-white shadow-2xl">
            <div class="absolute inset-0 opacity-30" style="background: radial-gradient(circle at 20% 20%, #facc15 0, transparent 28%), radial-gradient(circle at 80% 10%, #22c55e 0, transparent 24%), radial-gradient(circle at 60% 90%, #38bdf8 0, transparent 30%);"></div>
            <div class="relative px-6 py-12 sm:px-10 lg:px-14 lg:py-16">
                <span class="inline-flex items-center rounded-full border border-white/20 bg-white/10 px-4 py-1 text-sm text-white/85 backdrop-blur">
                    <i data-lucide="sparkles" class="mr-2 h-4 w-4"></i>
                    Skill Repository Deployed
                </span>
                <h1 class="mt-6 max-w-4xl text-4xl font-black tracking-tight sm:text-5xl lg:text-6xl">Yao GEO Skills</h1>
                <p class="mt-5 max-w-3xl text-lg leading-8 text-white/78">
                    把 GEO 运营、GEOFlow 自动化、前台模板映射和效果归因方法沉淀成可复用、可验证、可交付的 Skill 包。
                </p>
                <div class="mt-8 flex flex-col gap-3 sm:flex-row">
                    <a href="/public-geo-skills/README.md" class="inline-flex items-center justify-center rounded-full bg-white px-5 py-3 text-sm font-semibold text-gray-950 hover:bg-amber-100">
                        查看仓库 README
                        <i data-lucide="arrow-right" class="ml-2 h-4 w-4"></i>
                    </a>
                    <a href="/yao-geo-skills-main.zip" class="inline-flex items-center justify-center rounded-full border border-white/25 px-5 py-3 text-sm font-semibold text-white hover:bg-white/10">
                        下载完整 Skill 包
                        <i data-lucide="download" class="ml-2 h-4 w-4"></i>
                    </a>
                </div>
            </div>
        </section>

        <section class="mt-10 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="rounded-3xl bg-white p-5 shadow-sm ring-1 ring-black/5">
                <div class="text-3xl font-black"><?php echo count($skills); ?></div>
                <div class="mt-1 text-sm text-gray-500">已发布 Skill</div>
            </div>
            <div class="rounded-3xl bg-white p-5 shadow-sm ring-1 ring-black/5">
                <div class="text-3xl font-black">HTML / DOCX</div>
                <div class="mt-1 text-sm text-gray-500">可交付报告样例</div>
            </div>
            <div class="rounded-3xl bg-white p-5 shadow-sm ring-1 ring-black/5">
                <div class="text-3xl font-black">CLI / API</div>
                <div class="mt-1 text-sm text-gray-500">支持 GEOFlow 自动化</div>
            </div>
            <div class="rounded-3xl bg-white p-5 shadow-sm ring-1 ring-black/5">
                <div class="text-3xl font-black">Preview-first</div>
                <div class="mt-1 text-sm text-gray-500">模板设计安全工作流</div>
            </div>
        </section>

        <section class="mt-12">
            <div class="mb-6 flex items-end justify-between gap-4">
                <div>
                    <p class="text-sm font-semibold uppercase tracking-[0.25em] text-amber-700">Catalog</p>
                    <h2 class="mt-2 text-3xl font-black tracking-tight">Skill 清单</h2>
                </div>
                <a href="/public-geo-skills/registry/skills.json" class="hidden rounded-full bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-700 sm:inline-flex">查看 registry</a>
            </div>

            <div class="grid gap-6 lg:grid-cols-2">
                <?php foreach ($skills as $skill): ?>
                    <?php $label = $skill_labels[$skill['id']] ?? ['title' => $skill['id'], 'accent' => 'from-gray-800 to-gray-600', 'icon' => 'box', 'cn' => $skill['summary'] ?? '']; ?>
                    <article class="overflow-hidden rounded-[1.75rem] bg-white shadow-sm ring-1 ring-black/5 transition hover:-translate-y-1 hover:shadow-xl">
                        <div class="h-2 bg-gradient-to-r <?php echo htmlspecialchars($label['accent']); ?>"></div>
                        <div class="p-6 sm:p-7">
                            <div class="flex items-start gap-4">
                                <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-gray-950 text-white">
                                    <i data-lucide="<?php echo htmlspecialchars($label['icon']); ?>" class="h-6 w-6"></i>
                                </div>
                                <div>
                                    <h3 class="text-xl font-black"><?php echo htmlspecialchars($label['title']); ?></h3>
                                    <p class="mt-1 font-mono text-xs text-gray-500"><?php echo htmlspecialchars($skill['id']); ?></p>
                                </div>
                            </div>
                            <p class="mt-5 text-gray-700 leading-7"><?php echo htmlspecialchars($label['cn']); ?></p>
                            <p class="mt-3 text-sm leading-6 text-gray-500"><?php echo htmlspecialchars($skill['summary'] ?? ''); ?></p>
                            <div class="mt-5 flex flex-wrap gap-2">
                                <span class="rounded-full bg-gray-100 px-3 py-1 text-xs font-semibold text-gray-700"><?php echo htmlspecialchars($skill['family'] ?? 'geo'); ?></span>
                                <span class="rounded-full bg-gray-100 px-3 py-1 text-xs font-semibold text-gray-700"><?php echo htmlspecialchars($skill['maturity'] ?? 'active'); ?></span>
                                <span class="rounded-full bg-gray-100 px-3 py-1 text-xs font-semibold text-gray-700">updated <?php echo htmlspecialchars($skill['last_updated'] ?? ''); ?></span>
                            </div>
                            <?php if (!empty($skill['tags'])): ?>
                                <div class="mt-4 flex flex-wrap gap-2">
                                    <?php foreach (array_slice($skill['tags'], 0, 6) as $tag): ?>
                                        <span class="rounded-full border border-gray-200 px-3 py-1 text-xs text-gray-500">#<?php echo htmlspecialchars($tag); ?></span>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            <div class="mt-6 flex flex-wrap gap-3">
                                <a href="<?php echo htmlspecialchars(skill_doc_url($skill)); ?>" class="inline-flex items-center rounded-full bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-700">说明文档</a>
                                <a href="<?php echo htmlspecialchars(skill_package_url($skill)); ?>" class="inline-flex items-center rounded-full bg-white px-4 py-2 text-sm font-semibold text-gray-900 ring-1 ring-gray-200 hover:bg-gray-50">Skill 包</a>
                                <a href="<?php echo htmlspecialchars(skill_manifest_url($skill)); ?>" class="inline-flex items-center rounded-full bg-white px-4 py-2 text-sm font-semibold text-gray-900 ring-1 ring-gray-200 hover:bg-gray-50">Manifest</a>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="mt-12 rounded-[2rem] bg-white p-6 shadow-sm ring-1 ring-black/5 sm:p-8">
            <div class="grid gap-8 lg:grid-cols-[0.8fr_1.2fr] lg:items-center">
                <div>
                    <p class="text-sm font-semibold uppercase tracking-[0.25em] text-emerald-700">Examples</p>
                    <h2 class="mt-2 text-3xl font-black tracking-tight">GEO Tracking 公开示例</h2>
                    <p class="mt-4 text-gray-600 leading-7">部署包保留了 `yao-geo-tracking` 的 HTML、DOCX 和截图样例，适合直接演示企业 GEO 效果追踪报告的交付形态。</p>
                </div>
                <div class="grid gap-3 sm:grid-cols-2">
                    <a href="/public-geo-skills/skills/yao-geo-tracking/examples/hubspot-demo/hubspot-yao-geo-tracking.html" class="rounded-2xl bg-gray-50 p-5 ring-1 ring-gray-200 hover:bg-amber-50">
                        <div class="font-bold">HubSpot HTML 示例</div>
                        <div class="mt-1 text-sm text-gray-500">海外公司 GEO 追踪报告</div>
                    </a>
                    <a href="/public-geo-skills/skills/yao-geo-tracking/examples/lingxu-demo/lingxu-cn-yao-geo-tracking.html" class="rounded-2xl bg-gray-50 p-5 ring-1 ring-gray-200 hover:bg-amber-50">
                        <div class="font-bold">岭序 HTML 示例</div>
                        <div class="mt-1 text-sm text-gray-500">国内场景 GEO 追踪报告</div>
                    </a>
                    <a href="/public-geo-skills/skills/yao-geo-tracking/assets/screenshots/hubspot-yao-geo-tracking.png" class="rounded-2xl bg-gray-50 p-5 ring-1 ring-gray-200 hover:bg-amber-50">
                        <div class="font-bold">HubSpot 截图</div>
                        <div class="mt-1 text-sm text-gray-500">可视化报告预览图</div>
                    </a>
                    <a href="/public-geo-skills/skills/yao-geo-tracking/examples/hubspot-demo/hubspot-yao-geo-tracking.docx" class="rounded-2xl bg-gray-50 p-5 ring-1 ring-gray-200 hover:bg-amber-50">
                        <div class="font-bold">DOCX 交付件</div>
                        <div class="mt-1 text-sm text-gray-500">Word 报告样例</div>
                    </a>
                </div>
            </div>
        </section>
    </main>

    <?php include 'includes/footer.php'; ?>
</body>
</html>
