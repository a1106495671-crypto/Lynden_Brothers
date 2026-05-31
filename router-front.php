<?php
/**
 * 前台路由器 - 直接展示文章列表，不跳转后台
 */
require_once __DIR__ . '/includes/env_bootstrap.php';
require_once __DIR__ . '/includes/access_logger.php';

$requestUri = $_SERVER['REQUEST_URI'];
$requestPath = parse_url($requestUri, PHP_URL_PATH);
$path = rtrim($requestPath, '/');

// 静态文件
if (preg_match('/\.(css|js|txt|png|jpg|jpeg|gif|ico|svg|woff|woff2|ttf|eot)$/', $path)) {
    return false;
}

// 文章详情页
if (preg_match('/^\/article\/([a-zA-Z0-9\-_]+)\/?$/', $path, $matches)) {
    $_GET['slug'] = $matches[1];
    require_once __DIR__ . '/article.php';
    return true;
}

// 分类页面
if (preg_match('/^\/category\/(\d+)\/?$/', $path, $matches)) {
    $_GET['category'] = $matches[1];
    // 前台直接展示，不跳转
    define('FEISHU_TREASURE', true);
    session_start();
    require_once 'includes/config.php';
    require_once 'includes/database.php';
    require_once 'includes/functions.php';
    require_once 'includes/seo_functions.php';
    require_once 'includes/theme_manager.php';
    $database = Database::getInstance();
    $db = $database->getPDO();
    theme_render('home');
    return true;
}

if (preg_match('/^\/category\/([a-zA-Z0-9\-_]+)\/?$/', $path, $matches)) {
    $_GET['slug'] = $matches[1];
    require_once __DIR__ . '/category.php';
    return true;
}

// 归档
if (preg_match('/^\/archive\/(\d{4})\/(\d{2})\/?$/', $path, $matches)) {
    $_GET['year'] = $matches[1];
    $_GET['month'] = $matches[2];
    require_once __DIR__ . '/archive.php';
    return true;
}

if ($path === '/archive') {
    require_once __DIR__ . '/archive.php';
    return true;
}

// 搜索
if (preg_match('/^\/search\/(.+?)\/?$/', $path, $matches)) {
    $_GET['search'] = urldecode($matches[1]);
    // 复用 index.php 逻辑但不跳转
    define('FEISHU_TREASURE', true);
    session_start();
    require_once 'includes/config.php';
    require_once 'includes/database.php';
    require_once 'includes/functions.php';
    require_once 'includes/seo_functions.php';
    require_once 'includes/theme_manager.php';
    $database = Database::getInstance();
    $db = $database->getPDO();
    theme_render('home');
    return true;
}

// RSS
if ($path === '/rss' || $path === '/feed') {
    require_once __DIR__ . '/rss.php';
    return true;
}

// 根目录 - 直接展示文章列表
if ($path === '' || $path === '/') {
    // 前台入口：不跳转，直接渲染文章列表
    define('FEISHU_TREASURE', true);
    session_start();
    require_once 'includes/config.php';
    require_once 'includes/database.php';
    require_once 'includes/functions.php';
    require_once 'includes/seo_functions.php';
    require_once 'includes/theme_manager.php';

    $database = Database::getInstance();
    $db = $database->getPDO();

    $category_id = intval($_GET['category'] ?? 0);
    $search = clean_input($_GET['search'] ?? '');
    $page = max(1, intval($_GET['page'] ?? 1));
    $per_page = max(1, intval(site_setting_value('per_page', 12)));

    $site_title = site_setting_value('site_name', SITE_NAME);
    $site_subtitle = site_setting_value('site_subtitle', '');
    $site_description = site_setting_value('site_description', SITE_DESCRIPTION);
    $site_keywords = site_setting_value('site_keywords', SITE_KEYWORDS);
    $featured_limit = max(1, intval(site_setting_value('featured_limit', 6)));
    $site_stats = get_site_stats();
    $category = null;

    $categories = get_categories();
    $featured_articles = get_featured_articles($featured_limit);

    if (!empty($search)) {
        $articles = search_articles($search, $page, $per_page);
        $total_count = get_search_count($search);
        $view_title = "搜索：{$search}";
    } elseif ($category_id > 0) {
        $category = get_category_by_id($category_id);
        if ($category) {
            $articles = get_articles_by_category($category_id, $page, $per_page);
            $total_count = get_category_article_count($category_id);
            $view_title = $category['name'];
        } else {
            $articles = [];
            $total_count = 0;
            $view_title = '分类不存在';
        }
    } else {
        $offset = ($page - 1) * $per_page;
        $stmt = $db->prepare("
            SELECT a.*, c.name as category_name, au.name as author_name
            FROM articles a
            LEFT JOIN categories c ON a.category_id = c.id
            LEFT JOIN authors au ON a.author_id = au.id
            WHERE a.status = 'published'
              AND a.deleted_at IS NULL
            ORDER BY a.is_featured DESC, a.published_at DESC, a.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$per_page, $offset]);
        $articles = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $total_count = intval($db->query("SELECT COUNT(*) FROM articles WHERE status = 'published' AND deleted_at IS NULL")->fetchColumn());
        $view_title = '最新文章';
    }

    $total_pages = max(1, (int) ceil($total_count / $per_page));

    if (!empty($search)) {
        $page_title = generate_page_title("搜索：{$search}", '', $site_title);
        $page_description = generate_page_description("搜索结果：{$search} - {$site_description}");
    } elseif (!empty($category)) {
        $page_title = generate_page_title($category['name'], $category['name'], $site_title);
        $page_description = generate_page_description((!empty($category['description']) ? $category['description'] : "{$category['name']}分类下的内容") . " - {$site_description}");
    } else {
        if (!empty($site_subtitle)) {
            $page_title = generate_page_title($site_subtitle, '', $site_title);
        } else {
            $page_title = $site_title;
        }
        $page_description = generate_page_description($site_description, '', $site_title);
    }

    $canonical_url = !empty($search)
        ? geo_absolute_url('/?search=' . urlencode($search))
        : (!empty($category)
            ? geo_absolute_url('category/' . ($category['slug'] ?: $category['id']))
            : geo_absolute_url('/'));

    $page_keywords = !empty($category)
        ? generate_page_keywords($site_keywords, $category['name'])
        : generate_page_keywords($site_keywords, !empty($search) ? $search : '');

    $list_summary = build_collection_geo_summary(
        $view_title,
        $page_description,
        $articles,
        [
            '站点名称' => $site_title,
            '内容类型' => !empty($search) ? '搜索结果页' : (!empty($category) ? '分类列表页' : '首页内容聚合页'),
            '当前页码' => (string) $page
        ]
    );

    $structured_items = [];
    foreach (array_slice($articles, 0, 10) as $item) {
        $structured_items[] = [
            "@type" => "Article",
            "headline" => $item['title'],
            "url" => geo_absolute_url('article/' . $item['slug'])
        ];
    }

    $breadcrumbs = [['name' => '首页', 'url' => geo_absolute_url('/')]];
    if (!empty($search)) {
        $breadcrumbs[] = ['name' => '搜索', 'url' => $canonical_url];
    } elseif (!empty($category)) {
        $breadcrumbs[] = ['name' => $category['name'], 'url' => $canonical_url];
    }

    $structured_data_blocks = [
        generate_website_structured_data(),
        generate_collection_structured_data($view_title, $page_description, $canonical_url, $structured_items),
        generate_breadcrumb_structured_data($breadcrumbs)
    ];
    theme_render('home');
    return true;
}

return false;
