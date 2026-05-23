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
        body { font-family: -apple-system, "PingFang SC", "Microsoft YaHei", sans-serif; background: #f5f5f5; }
        .tt-header { background: #e8321a; color: #fff; }
        .tt-header a { color: #fff; }
        .tt-nav-item { padding: 0 12px; height: 44px; display: inline-flex; align-items: center; font-size: 15px; }
        .tt-nav-item:hover, .tt-nav-item.active { background: rgba(0,0,0,.15); }
        .tt-container { max-width: 960px; margin: 0 auto; padding: 0 12px; }
        .tt-main { background: #fff; border-radius: 2px; }
        .tt-list-item { display: flex; align-items: flex-start; padding: 12px 0; border-bottom: 1px solid #f0f0f0; gap: 12px; }
        .tt-list-item:last-child { border-bottom: none; }
        .tt-item-title { font-size: 16px; line-height: 1.5; color: #1a1a1a; font-weight: 500; text-decoration: none; display: block; margin-bottom: 6px; }
        .tt-item-title:hover { color: #e8321a; }
        .tt-item-meta { font-size: 12px; color: #999; display: flex; gap: 8px; align-items: center; }
        .tt-item-excerpt { font-size: 13px; color: #666; line-height: 1.6; margin-bottom: 6px; }
        .tt-tag { background: #f5f5f5; color: #666; padding: 1px 6px; border-radius: 2px; font-size: 12px; text-decoration: none; }
        .tt-tag:hover { color: #e8321a; }
        .tt-featured-badge { background: #e8321a; color: #fff; padding: 1px 5px; border-radius: 2px; font-size: 11px; margin-right: 4px; }
        .tt-section-head { font-size: 14px; font-weight: 600; color: #333; padding: 12px 0 8px; border-bottom: 2px solid #e8321a; margin-bottom: 4px; display: flex; align-items: center; gap: 6px; }
        .tt-section-head span { color: #e8321a; }
        .tt-pagination { display: flex; justify-content: center; gap: 6px; padding: 16px 0; }
        .tt-pagination a, .tt-pagination span { padding: 5px 12px; border: 1px solid #e0e0e0; border-radius: 2px; font-size: 13px; color: #333; text-decoration: none; }
        .tt-pagination a:hover { border-color: #e8321a; color: #e8321a; }
        .tt-pagination .current { background: #e8321a; color: #fff; border-color: #e8321a; }
        .tt-search-bar { background: #fff; padding: 10px 0; border-bottom: 1px solid #e8e8e8; }
        .tt-search-input { border: 1px solid #e8321a; border-radius: 2px; padding: 6px 12px; font-size: 14px; width: 280px; outline: none; }
        .tt-search-btn { background: #e8321a; color: #fff; border: none; padding: 7px 20px; border-radius: 0 2px 2px 0; font-size: 14px; cursor: pointer; }
    </style>
    <?php if (function_exists('output_site_head_extras')) output_site_head_extras(); ?>
    <?php if (function_exists('output_structured_data_blocks')) output_structured_data_blocks($structured_data_blocks); ?>
</head>
<body>

<!-- Header -->
<header class="tt-header">
    <div class="tt-container">
        <div class="flex items-center justify-between" style="height:50px;">
            <a href="/" class="text-xl font-bold text-white" style="letter-spacing:1px;"><?php echo htmlspecialchars($site_title); ?></a>
            <nav class="flex items-center">
                <a href="/" class="tt-nav-item <?php echo (empty($_GET['category']) && empty($_GET['search'])) ? 'active' : ''; ?>">首页</a>
                <?php foreach (array_slice($categories, 0, 6) as $cat): ?>
                    <a href="/category/<?php echo $cat['id']; ?>" class="tt-nav-item <?php echo (isset($_GET['category']) && $_GET['category'] == $cat['id']) ? 'active' : ''; ?>"><?php echo htmlspecialchars($cat['name']); ?></a>
                <?php endforeach; ?>
            </nav>
        </div>
    </div>
</header>

<!-- Search bar -->
<div class="tt-search-bar">
    <div class="tt-container">
        <form action="/" method="GET" class="flex items-center">
            <input type="text" name="search" class="tt-search-input" placeholder="搜索文章..." value="<?php echo htmlspecialchars($search ?? ''); ?>">
            <button type="submit" class="tt-search-btn">搜索</button>
        </form>
    </div>
</div>

<!-- Main content -->
<div class="tt-container" style="padding-top:16px; padding-bottom:32px;">

    <?php if (!empty($search)): ?>
        <div style="margin-bottom:12px; font-size:14px; color:#666;">
            搜索"<strong><?php echo htmlspecialchars($search); ?></strong>"的结果，共 <?php echo $total_count ?? 0; ?> 篇
        </div>
    <?php elseif (!empty($category)): ?>
        <div style="margin-bottom:12px;">
            <h1 style="font-size:20px; font-weight:700; color:#1a1a1a;"><?php echo htmlspecialchars($category['name']); ?></h1>
            <?php if (!empty($category['description'])): ?>
                <p style="font-size:13px; color:#999; margin-top:4px;"><?php echo htmlspecialchars($category['description']); ?></p>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- Featured articles -->
    <?php if (empty($search) && ($category_id ?? 0) === 0 && ($page ?? 1) === 1 && !empty($featured_articles)): ?>
        <div class="tt-main" style="padding:0 16px; margin-bottom:12px;">
            <div class="tt-section-head"><span>★</span> 推荐</div>
            <div>
                <?php foreach ($featured_articles as $article): ?>
                    <div class="tt-list-item">
                        <div style="flex:1; min-width:0;">
                            <a href="/article/<?php echo htmlspecialchars($article['slug']); ?>" class="tt-item-title">
                                <span class="tt-featured-badge">推荐</span><?php echo htmlspecialchars($article['title']); ?>
                            </a>
                            <p class="tt-item-excerpt"><?php echo htmlspecialchars(!empty($article['excerpt']) ? $article['excerpt'] : mb_substr(strip_tags($article['content']), 0, 80, 'UTF-8') . '...'); ?></p>
                            <div class="tt-item-meta">
                                <?php if (!empty($article['category_name'])): ?>
                                    <a href="/category/<?php echo $article['category_id']; ?>" class="tt-tag"><?php echo htmlspecialchars($article['category_name']); ?></a>
                                <?php endif; ?>
                                <span><?php echo date('m-d', strtotime($article['published_at'] ?: $article['created_at'])); ?></span>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- Article list -->
    <div class="tt-main" style="padding:0 16px;">
        <?php if (empty($search) && ($category_id ?? 0) === 0 && ($page ?? 1) === 1): ?>
            <div class="tt-section-head"><span>▶</span> 最新</div>
        <?php endif; ?>

        <?php if (empty($articles)): ?>
            <div style="text-align:center; padding:40px 0; color:#999; font-size:14px;">
                <?php echo !empty($search) ? '没有找到相关内容' : '暂无文章'; ?>
                <br><a href="/" style="color:#e8321a; font-size:13px; margin-top:8px; display:inline-block;">返回首页</a>
            </div>
        <?php else: ?>
            <?php foreach ($articles as $article): ?>
                <div class="tt-list-item">
                    <div style="flex:1; min-width:0;">
                        <a href="/article/<?php echo htmlspecialchars($article['slug']); ?>" class="tt-item-title">
                            <?php echo htmlspecialchars($article['title']); ?>
                        </a>
                        <p class="tt-item-excerpt"><?php echo htmlspecialchars(!empty($article['excerpt']) ? $article['excerpt'] : mb_substr(strip_tags($article['content']), 0, 80, 'UTF-8') . '...'); ?></p>
                        <div class="tt-item-meta">
                            <?php if (!empty($article['category_name'])): ?>
                                <a href="/category/<?php echo $article['category_id']; ?>" class="tt-tag"><?php echo htmlspecialchars($article['category_name']); ?></a>
                            <?php endif; ?>
                            <span><?php echo date('Y-m-d', strtotime($article['published_at'] ?: $article['created_at'])); ?></span>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>

            <?php if (($total_pages ?? 1) > 1): ?>
                <div class="tt-pagination">
                    <?php
                    $params = [];
                    if (!empty($search)) $params[] = 'search=' . urlencode($search);
                    if (($category_id ?? 0) > 0) $params[] = 'category=' . $category_id;
                    $p_base = '/' . (!empty($params) ? '?' . implode('&', $params) . '&page=' : '?page=');
                    $cur = $page ?? 1;
                    if ($cur > 1): ?><a href="<?php echo $p_base . ($cur - 1); ?>">上一页</a><?php endif; ?>
                    <?php for ($i = max(1, $cur - 2); $i <= min($total_pages, $cur + 2); $i++): ?>
                        <?php if ($i === $cur): ?>
                            <span class="current"><?php echo $i; ?></span>
                        <?php else: ?>
                            <a href="<?php echo $p_base . $i; ?>"><?php echo $i; ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>
                    <?php if ($cur < $total_pages): ?><a href="<?php echo $p_base . ($cur + 1); ?>">下一页</a><?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

</div>

<!-- Footer -->
<footer style="background:#1a1a1a; color:#999; padding:20px 0; margin-top:24px;">
    <div class="tt-container" style="text-align:center; font-size:13px;">
        <p><?php echo htmlspecialchars($site_title); ?> &copy; <?php echo date('Y'); ?></p>
        <?php if (!empty($site_description)): ?>
            <p style="margin-top:4px; font-size:12px;"><?php echo htmlspecialchars($site_description); ?></p>
        <?php endif; ?>
    </div>
</footer>

</body>
</html>
