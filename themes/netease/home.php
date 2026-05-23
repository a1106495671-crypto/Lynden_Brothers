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
    <style>
        body { font-family: -apple-system, "PingFang SC", "Microsoft YaHei", sans-serif; background: #f2f2f2; color: #1a1a1a; }
        .ne-header { background: #cc0000; }
        .ne-logo { font-size: 24px; font-weight: 900; color: #fff; letter-spacing: 2px; text-decoration: none; }
        .ne-nav { background: #222; }
        .ne-nav a { color: #ccc; font-size: 14px; padding: 0 14px; height: 36px; display: inline-flex; align-items: center; text-decoration: none; }
        .ne-nav a:hover, .ne-nav a.active { color: #fff; background: #444; }
        .ne-container { max-width: 1000px; margin: 0 auto; padding: 0 10px; }
        .ne-layout { display: flex; gap: 14px; align-items: flex-start; }
        .ne-main { flex: 1; min-width: 0; }
        .ne-sidebar { width: 240px; flex-shrink: 0; }
        .ne-card { background: #fff; border: 1px solid #e8e8e8; border-radius: 2px; margin-bottom: 10px; }
        .ne-card-head { font-size: 15px; font-weight: 700; color: #cc0000; padding: 10px 14px; border-bottom: 1px solid #e8e8e8; display: flex; align-items: center; gap: 6px; }
        .ne-card-head::before { content: ''; display: inline-block; width: 3px; height: 15px; background: #cc0000; border-radius: 1px; }
        .ne-article-row { display: flex; gap: 10px; padding: 10px 14px; border-bottom: 1px solid #f0f0f0; align-items: flex-start; }
        .ne-article-row:last-child { border-bottom: none; }
        .ne-article-title { font-size: 15px; font-weight: 500; color: #1a1a1a; text-decoration: none; line-height: 1.5; display: block; margin-bottom: 5px; }
        .ne-article-title:hover { color: #cc0000; text-decoration: underline; }
        .ne-article-excerpt { font-size: 13px; color: #666; line-height: 1.6; margin-bottom: 5px; }
        .ne-article-meta { font-size: 12px; color: #999; display: flex; gap: 8px; align-items: center; }
        .ne-cat-tag { color: #cc0000; font-size: 12px; text-decoration: none; border: 1px solid #cc0000; padding: 0 4px; border-radius: 2px; line-height: 18px; display: inline-block; }
        .ne-cat-tag:hover { background: #cc0000; color: #fff; }
        .ne-featured-tag { background: #cc0000; color: #fff; font-size: 11px; padding: 0 5px; border-radius: 2px; margin-right: 4px; vertical-align: middle; }
        .ne-sidebar-head { font-size: 14px; font-weight: 700; color: #cc0000; padding: 8px 12px; border-bottom: 1px solid #e8e8e8; border-left: 3px solid #cc0000; }
        .ne-sidebar-list { padding: 6px 0; }
        .ne-sidebar-item { padding: 6px 12px; font-size: 13px; border-bottom: 1px dotted #f0f0f0; }
        .ne-sidebar-item:last-child { border-bottom: none; }
        .ne-sidebar-link { color: #333; text-decoration: none; display: block; line-height: 1.5; }
        .ne-sidebar-link:hover { color: #cc0000; }
        .ne-sidebar-index { display: inline-block; width: 18px; height: 18px; line-height: 18px; text-align: center; font-size: 12px; background: #ccc; color: #fff; border-radius: 2px; margin-right: 6px; font-weight: 700; }
        .ne-sidebar-index.top1 { background: #cc0000; }
        .ne-sidebar-index.top2 { background: #e66; }
        .ne-sidebar-index.top3 { background: #f99; }
        .ne-pagination { display: flex; gap: 4px; padding: 12px 14px; align-items: center; }
        .ne-pagination a, .ne-pagination span { padding: 4px 10px; border: 1px solid #e0e0e0; font-size: 13px; color: #333; text-decoration: none; border-radius: 2px; }
        .ne-pagination a:hover { border-color: #cc0000; color: #cc0000; }
        .ne-pagination .cur { background: #cc0000; color: #fff; border-color: #cc0000; }
        .ne-search { background: #fff; border: 1px solid #cc0000; display: flex; border-radius: 2px; overflow: hidden; }
        .ne-search input { flex: 1; padding: 6px 10px; font-size: 13px; border: none; outline: none; }
        .ne-search button { background: #cc0000; color: #fff; border: none; padding: 0 16px; font-size: 13px; cursor: pointer; }
    </style>
    <?php if (function_exists('output_site_head_extras')) output_site_head_extras(); ?>
    <?php if (function_exists('output_structured_data_blocks')) output_structured_data_blocks($structured_data_blocks); ?>
</head>
<body>

<!-- Header -->
<header class="ne-header">
    <div class="ne-container" style="display:flex; align-items:center; justify-content:space-between; height:54px;">
        <a href="/" class="ne-logo"><?php echo htmlspecialchars($site_title); ?></a>
        <div style="flex:1; max-width:340px; margin-left:24px;">
            <form action="/" method="GET" class="ne-search">
                <input type="text" name="search" placeholder="搜索..." value="<?php echo htmlspecialchars($search ?? ''); ?>">
                <button type="submit">搜索</button>
            </form>
        </div>
    </div>
</header>

<!-- Nav -->
<nav class="ne-nav">
    <div class="ne-container" style="display:flex;">
        <a href="/" class="<?php echo (empty($_GET['category']) && empty($_GET['search'])) ? 'active' : ''; ?>">首页</a>
        <?php foreach (array_slice($categories, 0, 8) as $cat): ?>
            <a href="/category/<?php echo $cat['id']; ?>" class="<?php echo (isset($_GET['category']) && $_GET['category'] == $cat['id']) ? 'active' : ''; ?>"><?php echo htmlspecialchars($cat['name']); ?></a>
        <?php endforeach; ?>
    </div>
</nav>

<!-- Body -->
<div class="ne-container" style="padding-top:14px; padding-bottom:32px;">

    <?php if (!empty($search)): ?>
        <div style="font-size:14px; color:#666; margin-bottom:10px;">
            搜索"<strong><?php echo htmlspecialchars($search); ?></strong>"，共 <?php echo $total_count ?? 0; ?> 篇
        </div>
    <?php elseif (!empty($category)): ?>
        <div style="margin-bottom:10px;">
            <h1 style="font-size:20px; font-weight:700;"><?php echo htmlspecialchars($category['name']); ?></h1>
            <?php if (!empty($category['description'])): ?>
                <p style="font-size:13px; color:#999; margin-top:2px;"><?php echo htmlspecialchars($category['description']); ?></p>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="ne-layout">

        <!-- Main column -->
        <div class="ne-main">

            <?php if (empty($search) && ($category_id ?? 0) === 0 && ($page ?? 1) === 1 && !empty($featured_articles)): ?>
                <div class="ne-card">
                    <div class="ne-card-head">推荐阅读</div>
                    <?php foreach ($featured_articles as $article): ?>
                        <div class="ne-article-row">
                            <div style="flex:1; min-width:0;">
                                <a href="/article/<?php echo htmlspecialchars($article['slug']); ?>" class="ne-article-title">
                                    <span class="ne-featured-tag">推荐</span><?php echo htmlspecialchars($article['title']); ?>
                                </a>
                                <p class="ne-article-excerpt"><?php echo htmlspecialchars(!empty($article['excerpt']) ? $article['excerpt'] : mb_substr(strip_tags($article['content']), 0, 90, 'UTF-8') . '...'); ?></p>
                                <div class="ne-article-meta">
                                    <?php if (!empty($article['category_name'])): ?>
                                        <a href="/category/<?php echo $article['category_id']; ?>" class="ne-cat-tag"><?php echo htmlspecialchars($article['category_name']); ?></a>
                                    <?php endif; ?>
                                    <span><?php echo date('Y-m-d', strtotime($article['published_at'] ?: $article['created_at'])); ?></span>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="ne-card">
                <?php if (empty($search) && ($category_id ?? 0) === 0): ?>
                    <div class="ne-card-head">最新文章</div>
                <?php endif; ?>

                <?php if (empty($articles)): ?>
                    <div style="text-align:center; padding:40px 0; color:#999; font-size:14px;">
                        <?php echo !empty($search) ? '没有找到相关内容' : '暂无文章'; ?>
                        <br><a href="/" style="color:#cc0000; font-size:13px; margin-top:6px; display:inline-block;">返回首页</a>
                    </div>
                <?php else: ?>
                    <?php foreach ($articles as $article): ?>
                        <div class="ne-article-row">
                            <div style="flex:1; min-width:0;">
                                <a href="/article/<?php echo htmlspecialchars($article['slug']); ?>" class="ne-article-title">
                                    <?php echo htmlspecialchars($article['title']); ?>
                                </a>
                                <p class="ne-article-excerpt"><?php echo htmlspecialchars(!empty($article['excerpt']) ? $article['excerpt'] : mb_substr(strip_tags($article['content']), 0, 90, 'UTF-8') . '...'); ?></p>
                                <div class="ne-article-meta">
                                    <?php if (!empty($article['category_name'])): ?>
                                        <a href="/category/<?php echo $article['category_id']; ?>" class="ne-cat-tag"><?php echo htmlspecialchars($article['category_name']); ?></a>
                                    <?php endif; ?>
                                    <span><?php echo date('Y-m-d', strtotime($article['published_at'] ?: $article['created_at'])); ?></span>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <?php if (($total_pages ?? 1) > 1): ?>
                        <div class="ne-pagination">
                            <?php
                            $params = [];
                            if (!empty($search)) $params[] = 'search=' . urlencode($search);
                            if (($category_id ?? 0) > 0) $params[] = 'category=' . $category_id;
                            $p_base = '/' . (!empty($params) ? '?' . implode('&', $params) . '&page=' : '?page=');
                            $cur = $page ?? 1;
                            if ($cur > 1): ?><a href="<?php echo $p_base . ($cur - 1); ?>">上一页</a><?php endif; ?>
                            <?php for ($i = max(1, $cur - 2); $i <= min($total_pages, $cur + 2); $i++): ?>
                                <?php if ($i === $cur): ?>
                                    <span class="cur"><?php echo $i; ?></span>
                                <?php else: ?>
                                    <a href="<?php echo $p_base . $i; ?>"><?php echo $i; ?></a>
                                <?php endif; ?>
                            <?php endfor; ?>
                            <?php if ($cur < $total_pages): ?><a href="<?php echo $p_base . ($cur + 1); ?>">下一页</a><?php endif; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

        </div><!-- /ne-main -->

        <!-- Sidebar -->
        <div class="ne-sidebar">

            <!-- Categories -->
            <div class="ne-card" style="margin-bottom:10px;">
                <div class="ne-sidebar-head">内容分类</div>
                <div class="ne-sidebar-list">
                    <div class="ne-sidebar-item"><a href="/" class="ne-sidebar-link">全部文章</a></div>
                    <?php foreach ($categories as $cat): ?>
                        <div class="ne-sidebar-item">
                            <a href="/category/<?php echo $cat['id']; ?>" class="ne-sidebar-link">
                                <?php echo htmlspecialchars($cat['name']); ?>
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Top featured -->
            <?php if (!empty($featured_articles)): ?>
            <div class="ne-card">
                <div class="ne-sidebar-head">热门推荐</div>
                <div class="ne-sidebar-list">
                    <?php foreach (array_slice($featured_articles, 0, 8) as $i => $art): ?>
                        <div class="ne-sidebar-item">
                            <span class="ne-sidebar-index <?php echo $i < 3 ? 'top' . ($i + 1) : ''; ?>"><?php echo $i + 1; ?></span>
                            <a href="/article/<?php echo htmlspecialchars($art['slug']); ?>" class="ne-sidebar-link" style="display:inline;"><?php echo htmlspecialchars(mb_substr($art['title'], 0, 22, 'UTF-8')); ?></a>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

        </div><!-- /ne-sidebar -->

    </div><!-- /ne-layout -->

</div>

<!-- Footer -->
<footer style="background:#222; color:#888; padding:18px 0; margin-top:10px;">
    <div class="ne-container" style="text-align:center; font-size:13px;">
        <p><?php echo htmlspecialchars($site_title); ?> &copy; <?php echo date('Y'); ?> &nbsp;|&nbsp; <?php echo htmlspecialchars($site_description ?? ''); ?></p>
    </div>
</footer>

</body>
</html>
