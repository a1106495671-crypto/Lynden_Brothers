<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?></title>
    <meta name="description" content="<?php echo htmlspecialchars($page_description); ?>">
    <meta name="keywords" content="<?php echo htmlspecialchars($page_keywords); ?>">
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
<body class="bg-white">
    <?php include __DIR__ . '/header.php'; ?>

    <main class="site-container px-4 sm:px-6 lg:px-8 py-8">
        <?php if (empty($search) && $category_id === 0 && $page === 1): ?>
            <section class="home-hero article-shell mb-10">
                <div class="px-6 py-7 sm:px-8">
                    <h1 class="home-hero-title text-gray-900 mb-3"><?php echo htmlspecialchars($site_title); ?></h1>
                    <p class="home-hero-copy text-gray-600">
                        <?php echo htmlspecialchars(!empty($site_subtitle) ? $site_subtitle : $site_description); ?>
                    </p>
                </div>
            </section>
        <?php endif; ?>

        <?php if (empty($search) && $category_id === 0 && $page === 1 && !empty($featured_articles)): ?>
            <div class="flex items-center mb-6">
                <div class="section-label mr-4">
                    <i data-lucide="star" class="w-4 h-4 text-amber-400"></i>
                    <span>推荐文章</span>
                </div>
            </div>
            <section class="mb-8">
                <div class="space-y-6">
                    <?php foreach ($featured_articles as $article): ?>
                        <article class="article-shell entry-card">
                            <div class="p-6">
                                <div class="flex items-center justify-between mb-4">
                                    <div class="flex items-center space-x-2">
                                        <span class="pill-tag"><i data-lucide="star" class="w-3 h-3 mr-1"></i>推荐</span>
                                        <?php if (!empty($article['category_name'])): ?>
                                            <a href="/category/<?php echo htmlspecialchars($article['category_id']); ?>" class="pill-tag"><?php echo htmlspecialchars($article['category_name']); ?></a>
                                        <?php endif; ?>
                                    </div>
                                    <time class="text-sm text-gray-500"><?php echo date('Y年m月d日', strtotime($article['published_at'] ?: $article['created_at'])); ?></time>
                                </div>
                                <h2 class="entry-title font-semibold text-gray-900 mb-3">
                                    <a href="/article/<?php echo htmlspecialchars($article['slug']); ?>" class="hover:text-blue-600"><?php echo htmlspecialchars($article['title']); ?></a>
                                </h2>
                                <p class="entry-summary mb-4"><?php echo htmlspecialchars(!empty($article['excerpt']) ? $article['excerpt'] : mb_substr(strip_tags($article['content']), 0, 120, 'UTF-8') . '...'); ?></p>
                                <div class="flex justify-end">
                                    <a href="/article/<?php echo htmlspecialchars($article['slug']); ?>" class="read-more-btn">阅读全文 <i data-lucide="arrow-right" class="w-4 h-4 ml-1"></i></a>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>
            <div class="flex items-center mt-10 mb-4">
                <div class="section-label mr-4"><i data-lucide="list" class="w-4 h-4 text-gray-400"></i><span>最新文章</span></div>
            </div>
        <?php endif; ?>

        <?php if (!empty($search)): ?>
            <nav class="flex items-center space-x-2 text-sm text-gray-500 mb-8">
                <a href="/" class="hover:text-gray-700">首页</a>
                <i data-lucide="chevron-right" class="w-4 h-4"></i>
                <span class="text-gray-900">搜索：<?php echo htmlspecialchars($search); ?></span>
            </nav>
        <?php elseif (!empty($category)): ?>
            <div class="mb-8">
                <h1 class="text-3xl font-bold text-gray-900 mb-2"><?php echo htmlspecialchars($category['name']); ?></h1>
                <?php if (!empty($category['description'])): ?><p class="text-gray-500"><?php echo htmlspecialchars($category['description']); ?></p><?php endif; ?>
            </div>
        <?php endif; ?>

        <section class="py-4">
            <?php if (empty($articles)): ?>
                <div class="article-shell p-12 text-center">
                    <h3 class="text-xl font-semibold text-gray-900 mb-2"><?php echo !empty($search) ? '没有找到相关内容' : '暂无文章'; ?></h3>
                    <a href="/" class="inline-flex items-center px-4 py-2 bg-gray-900 text-white rounded-lg mt-4">返回首页</a>
                </div>
            <?php else: ?>
                <div class="space-y-8">
                    <?php foreach ($articles as $article): ?>
                        <article class="article-shell entry-card">
                            <div class="p-6">
                                <div class="flex items-center justify-between mb-4">
                                    <div class="flex items-center space-x-2">
                                        <?php if (!empty($article['category_name'])): ?>
                                            <a href="/category/<?php echo htmlspecialchars($article['category_id']); ?>" class="pill-tag"><?php echo htmlspecialchars($article['category_name']); ?></a>
                                        <?php endif; ?>
                                    </div>
                                    <time class="text-sm text-gray-500"><?php echo date('Y年m月d日', strtotime($article['published_at'] ?: $article['created_at'])); ?></time>
                                </div>
                                <h2 class="entry-title font-semibold text-gray-900 mb-3">
                                    <a href="/article/<?php echo htmlspecialchars($article['slug']); ?>" class="hover:text-blue-600"><?php echo htmlspecialchars($article['title']); ?></a>
                                </h2>
                                <p class="entry-summary mb-4"><?php echo htmlspecialchars(!empty($article['excerpt']) ? $article['excerpt'] : mb_substr(strip_tags($article['content']), 0, 120, 'UTF-8') . '...'); ?></p>
                                <div class="flex justify-end">
                                    <a href="/article/<?php echo htmlspecialchars($article['slug']); ?>" class="read-more-btn">阅读全文 <i data-lucide="arrow-right" class="w-4 h-4 ml-1"></i></a>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
                <?php if ($total_pages > 1): ?>
                    <div class="mt-12">
                        <?php
                        $params = [];
                        if (!empty($search)) $params[] = 'search=' . urlencode($search);
                        if ($category_id > 0) $params[] = 'category=' . $category_id;
                        $pagination_url = '/' . (!empty($params) ? '?' . implode('&', $params) . '&page=' : '?page=');
                        echo generate_pagination($page, $total_pages, $pagination_url);
                        ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </section>
    </main>

    <?php include __DIR__ . '/footer.php'; ?>
    <script>document.addEventListener('DOMContentLoaded',function(){if(typeof lucide!=='undefined')lucide.createIcons();});</script>
</body>
</html>
