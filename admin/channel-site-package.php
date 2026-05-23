<?php
/**
 * 目标渠道站点包
 * 为每个分发渠道生成独立 PHP 站点包（含 Agent 接口 + 前台页面 + SEO 文件）
 */

define('FEISHU_TREASURE', true);
session_start();

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/database_admin.php';
require_once __DIR__ . '/../includes/distribution_service.php';

require_admin_login();
ensure_distribution_schema($db);

$msg   = '';
$error = '';

// ── POST 动作 ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'CSRF验证失败';
    } else {
        $action    = $_POST['action'] ?? '';
        $accountId = (int) ($_POST['account_id'] ?? 0);

        if ($action === 'save_agent' && $accountId > 0) {
            $siteName    = trim($_POST['site_name'] ?? '');
            $siteTitle   = trim($_POST['site_title'] ?? '');
            $agentSecret = trim($_POST['agent_secret'] ?? '');
            $agentBase   = rtrim(trim($_POST['agent_base_url'] ?? ''), '/');

            if ($agentSecret === '') {
                $agentSecret = bin2hex(random_bytes(20));
            }

            $db->prepare("
                UPDATE media_accounts
                SET site_name=?, site_title=?, agent_secret=?, agent_base_url=?, updated_at=CURRENT_TIMESTAMP
                WHERE id=?
            ")->execute([$siteName, $siteTitle, $agentSecret, $agentBase, $accountId]);
            $msg = '渠道 Agent 配置已保存';

        } elseif ($action === 'reset_secret' && $accountId > 0) {
            $newSecret = bin2hex(random_bytes(20));
            $db->prepare("UPDATE media_accounts SET agent_secret=?, updated_at=CURRENT_TIMESTAMP WHERE id=?")
               ->execute([$newSecret, $accountId]);
            $msg = '密钥已重置';

        } elseif ($action === 'download_package' && $accountId > 0) {
            $acc = $db->prepare("SELECT * FROM media_accounts WHERE id=?");
            $acc->execute([$accountId]);
            $account = $acc->fetch(PDO::FETCH_ASSOC);

            if (!$account) {
                $error = '渠道不存在';
            } elseif (empty($account['agent_secret'])) {
                $error = '请先保存 Agent 配置并生成密钥';
            } else {
                channel_package_download($account);
                exit;
            }
        }
    }

    $qs = http_build_query(array_filter(['msg'=>$msg,'err'=>$error]));
    header('Location: channel-site-package.php' . ($qs ? '?' . $qs : ''));
    exit;
}

$msg   = $msg   ?: ($_GET['msg'] ?? '');
$error = $error ?: ($_GET['err'] ?? '');

$accounts = $db->query("SELECT * FROM media_accounts ORDER BY status DESC, account_name ASC")->fetchAll(PDO::FETCH_ASSOC);
$editId   = (int) ($_GET['edit'] ?? 0);
$editAcc  = null;
if ($editId > 0) {
    foreach ($accounts as $a) {
        if ((int)$a['id'] === $editId) { $editAcc = $a; break; }
    }
}

// ── 站点包生成 ────────────────────────────────────────────
function channel_package_download(array $account): void {
    $siteName  = $account['site_name']    ?: $account['account_name'];
    $siteTitle = $account['site_title']   ?: $account['account_name'];
    $secret    = $account['agent_secret'];
    $baseUrl   = $account['agent_base_url'] ?: 'https://your-domain.com';
    $platform  = $account['platform'];

    $zip = new ZipArchive();
    $tmpFile = tempnam(sys_get_temp_dir(), 'geo_pkg_') . '.zip';
    $zip->open($tmpFile, ZipArchive::CREATE | ZipArchive::OVERWRITE);

    // ── config.php ───────────────────────────────────────
    $zip->addFromString('config.php', channel_pkg_config($siteName, $siteTitle, $secret, $baseUrl));

    // ── index.php (Agent API + 前台) ──────────────────────
    $zip->addFromString('index.php', channel_pkg_index());

    // ── storage/articles/.gitkeep ─────────────────────────
    $zip->addFromString('storage/articles/.gitkeep', '');
    $zip->addFromString('storage/.htaccess', "Order deny,allow\nDeny from all\n");

    // ── .htaccess ─────────────────────────────────────────
    $zip->addFromString('.htaccess', channel_pkg_htaccess());

    // ── nginx.conf 参考 ───────────────────────────────────
    $zip->addFromString('nginx.conf.example', channel_pkg_nginx($baseUrl));

    // ── sitemap.xml 模板 ──────────────────────────────────
    $zip->addFromString('sitemap.xml', channel_pkg_sitemap($baseUrl));

    // ── llms.txt（AI 爬虫可读索引）────────────────────────
    $zip->addFromString('llms.txt', channel_pkg_llms($siteName, $baseUrl));

    // ── robots.txt ────────────────────────────────────────
    $zip->addFromString('robots.txt', "User-agent: *\nAllow: /\nSitemap: {$baseUrl}/sitemap.xml\n");

    // ── README.md ─────────────────────────────────────────
    $zip->addFromString('README.md', channel_pkg_readme($siteName, $baseUrl, $secret, $platform));

    $zip->close();

    $filename = 'geo-agent-' . preg_replace('/[^a-z0-9\-]/', '-', strtolower($platform)) . '.zip';
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($tmpFile));
    header('Cache-Control: no-cache');
    readfile($tmpFile);
    unlink($tmpFile);
}

// ── 包内文件模板 ──────────────────────────────────────────

function channel_pkg_config(string $siteName, string $siteTitle, string $secret, string $baseUrl): string {
    $now = date('Y-m-d');
    return <<<PHP
<?php
/**
 * GEO Agent 站点配置
 * 生成时间：{$now}
 * ⚠️  请勿将此文件提交到公开代码仓库
 */

// Agent 密钥（与中心后台一致）
define('AGENT_SECRET', '{$secret}');

// 本站基础地址（不含末尾斜杠）
define('SITE_BASE_URL', '{$baseUrl}');

// 站点名称
define('SITE_NAME',  '{$siteName}');
define('SITE_TITLE', '{$siteTitle}');

// 文章存储目录（相对于本文件所在目录）
define('ARTICLES_DIR', __DIR__ . '/storage/articles');

// 每页文章数
define('PAGE_SIZE', 20);
PHP;
}

function channel_pkg_index(): string {
    return <<<'PHP'
<?php
/**
 * GEO Agent 入口
 * 路由：
 *   GET  /                     首页文章列表
 *   GET  /article/{slug}       文章详情
 *   GET  /sitemap.xml          动态 sitemap（重定向）
 *   POST /geo-agent/v1/push    接收中心后台推送文章
 *   GET  /geo-agent/v1/health  健康检查
 */

require_once __DIR__ . '/config.php';

$path   = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path   = '/' . trim($path, '/');
$method = strtoupper($_SERVER['REQUEST_METHOD']);

// ── API 路由 ──────────────────────────────────────────────

if ($path === '/geo-agent/v1/health' && $method === 'GET') {
    agent_json(['ok' => true, 'site' => SITE_NAME, 'ts' => date('c')]);
}

if ($path === '/geo-agent/v1/push' && $method === 'POST') {
    agent_auth();
    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body) || empty($body['slug'])) {
        agent_json(['ok' => false, 'error' => 'missing slug'], 400);
    }
    $slug = preg_replace('/[^a-z0-9\-_]/', '', strtolower($body['slug']));
    if ($slug === '') {
        agent_json(['ok' => false, 'error' => 'invalid slug'], 400);
    }
    $file = ARTICLES_DIR . '/' . $slug . '.json';
    if (!is_dir(ARTICLES_DIR)) {
        mkdir(ARTICLES_DIR, 0755, true);
    }
    file_put_contents($file, json_encode($body, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    agent_json(['ok' => true, 'slug' => $slug]);
}

if ($path === '/geo-agent/v1/delete' && $method === 'DELETE') {
    agent_auth();
    $body = json_decode(file_get_contents('php://input'), true);
    $slug = preg_replace('/[^a-z0-9\-_]/', '', strtolower($body['slug'] ?? ''));
    $file = ARTICLES_DIR . '/' . $slug . '.json';
    if ($slug && file_exists($file)) {
        unlink($file);
    }
    agent_json(['ok' => true]);
}

// ── 前台路由 ──────────────────────────────────────────────

if (preg_match('#^/article/([a-z0-9\-_]+)$#', $path, $m)) {
    $article = agent_load_article($m[1]);
    if (!$article) {
        http_response_code(404);
        echo '<h1>404 Not Found</h1>';
        exit;
    }
    agent_render_article($article);
    exit;
}

// 首页 / 列表页
$pageNum  = max(1, (int) ($_GET['page'] ?? 1));
agent_render_home($pageNum);
exit;

// ── 工具函数 ──────────────────────────────────────────────

function agent_auth(): void {
    $key = $_SERVER['HTTP_X_AGENT_KEY'] ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
    $key = preg_replace('/^Bearer\s+/i', '', $key);
    if (!hash_equals(AGENT_SECRET, trim($key))) {
        agent_json(['ok' => false, 'error' => 'unauthorized'], 401);
    }
}

function agent_json(array $data, int $code = 200): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function agent_load_article(string $slug): ?array {
    $slug = preg_replace('/[^a-z0-9\-_]/', '', $slug);
    $file = ARTICLES_DIR . '/' . $slug . '.json';
    if (!file_exists($file)) return null;
    $data = json_decode(file_get_contents($file), true);
    return is_array($data) ? $data : null;
}

function agent_list_articles(int $page = 1): array {
    if (!is_dir(ARTICLES_DIR)) return ['articles' => [], 'total' => 0];
    $files = glob(ARTICLES_DIR . '/*.json') ?: [];
    usort($files, fn($a, $b) => filemtime($b) - filemtime($a));
    $total   = count($files);
    $offset  = ($page - 1) * PAGE_SIZE;
    $slice   = array_slice($files, $offset, PAGE_SIZE);
    $articles = [];
    foreach ($slice as $f) {
        $data = json_decode(file_get_contents($f), true);
        if (is_array($data)) $articles[] = $data;
    }
    return ['articles' => $articles, 'total' => $total];
}

function agent_render_home(int $page): void {
    ['articles' => $articles, 'total' => $total] = agent_list_articles($page);
    $pages = $total > 0 ? (int)ceil($total / PAGE_SIZE) : 1;
    $title = htmlspecialchars(SITE_TITLE, ENT_QUOTES, 'UTF-8');
    $base  = rtrim(SITE_BASE_URL, '/');
    echo <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{$title}</title>
<meta name="robots" content="index,follow">
<link rel="sitemap" href="{$base}/sitemap.xml" type="application/xml">
<style>
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;max-width:800px;margin:0 auto;padding:20px;color:#1a1a1a}
h1{font-size:1.8em;border-bottom:2px solid #eee;padding-bottom:.4em}
.article-item{padding:16px 0;border-bottom:1px solid #f0f0f0}
.article-item h2{margin:0 0 6px;font-size:1.1em}
.article-item h2 a{color:#1a1a1a;text-decoration:none}
.article-item h2 a:hover{color:#2563eb}
.meta{font-size:.8em;color:#888}
.pagination{margin:30px 0;display:flex;gap:8px;justify-content:center}
.pagination a{padding:6px 14px;border:1px solid #ddd;border-radius:5px;text-decoration:none;color:#333}
.pagination .current{background:#2563eb;color:#fff;border-color:#2563eb}
footer{margin-top:40px;font-size:.8em;color:#aaa;text-align:center}
</style>
</head>
<body>
<h1>{$title}</h1>
HTML;
    if (empty($articles)) {
        echo '<p style="color:#aaa">暂无文章</p>';
    }
    foreach ($articles as $a) {
        $slug    = htmlspecialchars($a['slug'] ?? '', ENT_QUOTES, 'UTF-8');
        $atitle  = htmlspecialchars($a['title'] ?? '（无标题）', ENT_QUOTES, 'UTF-8');
        $excerpt = htmlspecialchars(mb_substr(strip_tags($a['content'] ?? ''), 0, 120), ENT_QUOTES, 'UTF-8');
        $date    = htmlspecialchars($a['published_at'] ?? ($a['created_at'] ?? ''), ENT_QUOTES, 'UTF-8');
        echo <<<HTML
<div class="article-item" itemscope itemtype="https://schema.org/Article">
  <h2 itemprop="headline"><a href="{$base}/article/{$slug}">{$atitle}</a></h2>
  <p class="meta" itemprop="datePublished" content="{$date}">{$date}</p>
  <p itemprop="description">{$excerpt}…</p>
</div>
HTML;
    }
    echo '<div class="pagination">';
    for ($p = 1; $p <= $pages; $p++) {
        $cls = $p === $page ? ' class="current"' : '';
        echo "<a href=\"?page={$p}\"{$cls}>{$p}</a>";
    }
    echo '</div>';
    echo "<footer>Powered by GEO Agent &middot; <a href=\"/sitemap.xml\">Sitemap</a> &middot; <a href=\"/llms.txt\">llms.txt</a></footer></body></html>";
}

function agent_render_article(array $a): void {
    $base    = rtrim(SITE_BASE_URL, '/');
    $slug    = htmlspecialchars($a['slug'] ?? '', ENT_QUOTES, 'UTF-8');
    $title   = htmlspecialchars($a['title'] ?? '（无标题）', ENT_QUOTES, 'UTF-8');
    $content = $a['content'] ?? '';
    $date    = htmlspecialchars($a['published_at'] ?? ($a['created_at'] ?? ''), ENT_QUOTES, 'UTF-8');
    $author  = htmlspecialchars($a['author'] ?? '', ENT_QUOTES, 'UTF-8');
    $desc    = htmlspecialchars(mb_substr(strip_tags($content), 0, 160), ENT_QUOTES, 'UTF-8');
    $siteTitle = htmlspecialchars(SITE_TITLE, ENT_QUOTES, 'UTF-8');
    $schema  = json_encode([
        '@context'  => 'https://schema.org',
        '@type'     => 'Article',
        'headline'  => $a['title'] ?? '',
        'datePublished' => $a['published_at'] ?? ($a['created_at'] ?? ''),
        'author'    => ['@type' => 'Person', 'name' => $a['author'] ?? ''],
        'publisher' => ['@type' => 'Organization', 'name' => SITE_NAME],
        'url'       => "{$base}/article/{$slug}",
        'description' => mb_substr(strip_tags($content), 0, 160),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    echo <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{$title} - {$siteTitle}</title>
<meta name="description" content="{$desc}">
<meta name="robots" content="index,follow">
<link rel="canonical" href="{$base}/article/{$slug}">
<script type="application/ld+json">{$schema}</script>
<style>
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;max-width:780px;margin:0 auto;padding:20px 20px 60px;color:#1a1a1a;line-height:1.75}
nav a{color:#2563eb;text-decoration:none;font-size:.9em}
h1{font-size:1.9em;margin:20px 0 8px;line-height:1.3}
.meta{font-size:.85em;color:#888;margin-bottom:28px}
.content img{max-width:100%;height:auto}
footer{margin-top:50px;font-size:.8em;color:#aaa;border-top:1px solid #eee;padding-top:16px}
</style>
</head>
<body>
<nav><a href="/">← 返回首页</a></nav>
<h1 itemprop="headline">{$title}</h1>
<div class="meta">{$author}{$date}</div>
<div class="content" itemprop="articleBody">{$content}</div>
<footer>{$siteTitle} &middot; <a href="/sitemap.xml">Sitemap</a></footer>
</body></html>
HTML;
}
PHP;
}

function channel_pkg_htaccess(): string {
    return <<<'HTACCESS'
Options -Indexes
<FilesMatch "^(config\.php|.*\.json)$">
    Order allow,deny
    Deny from all
</FilesMatch>

<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteRule ^ index.php [L]
</IfModule>
HTACCESS;
}

function channel_pkg_nginx(string $baseUrl): string {
    $host = parse_url($baseUrl, PHP_URL_HOST) ?: 'your-domain.com';
    return <<<NGINX
server {
    listen 80;
    server_name {$host};
    root /path/to/geo-agent;
    index index.php;

    location ~* /(config\.php|storage/) {
        deny all;
    }

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        include fastcgi_params;
    }
}
NGINX;
}

function channel_pkg_sitemap(string $baseUrl): string {
    $base = rtrim($baseUrl, '/');
    $date = date('Y-m-d');
    return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
  <url>
    <loc>{$base}/</loc>
    <lastmod>{$date}</lastmod>
    <changefreq>daily</changefreq>
    <priority>1.0</priority>
  </url>
  <!-- 文章页由 Agent 动态生成，此为静态占位 -->
</urlset>
XML;
}

function channel_pkg_llms(string $siteName, string $baseUrl): string {
    $base = rtrim($baseUrl, '/');
    $date = date('Y-m-d');
    return <<<TXT
# {$siteName}

> 本站内容由 GEO Agent 同步发布，适合 AI 训练与检索引用。

- 首页：{$base}/
- 文章列表：{$base}/?page=1
- Sitemap：{$base}/sitemap.xml
- Agent Health：{$base}/geo-agent/v1/health

更新日期：{$date}
TXT;
}

function channel_pkg_readme(string $siteName, string $baseUrl, string $secret, string $platform): string {
    $base = rtrim($baseUrl, '/');
    $masked = substr($secret, 0, 6) . str_repeat('*', max(0, strlen($secret) - 6));
    return <<<MD
# GEO Agent 站点包 - {$siteName}

## 快速部署

1. 解压到服务器目录（PHP 8.0+）
2. 确保 `storage/articles/` 目录可写
3. 确认 `config.php` 中 `SITE_BASE_URL` 填写正确
4. 访问 `{$base}/geo-agent/v1/health` 验证部署

## 接口说明

| 方法 | 路径 | 说明 |
|------|------|------|
| GET  | `/geo-agent/v1/health` | 健康检查 |
| POST | `/geo-agent/v1/push`   | 接收文章（需密钥）|
| DELETE | `/geo-agent/v1/delete` | 删除文章（需密钥）|

**请求头**：`X-Agent-Key: {$masked}`

## 前台页面

| 路径 | 说明 |
|------|------|
| `/` | 首页文章列表 |
| `/article/{slug}` | 文章详情（含 Schema.org 结构化数据）|
| `/sitemap.xml` | Sitemap |
| `/llms.txt` | AI 爬虫可读索引 |
| `/robots.txt` | 爬虫声明 |

## 平台：{$platform}
MD;
}

// ─────────────────────────────────────────────────────────

function h(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$page_title = '目标站点包';
require_once __DIR__ . '/includes/header.php';
?>

<div style="margin-bottom:24px">
    <h1 style="margin:0;font-size:22px;font-weight:700">目标站点包</h1>
    <p style="margin:4px 0 0;color:#6b7280;font-size:14px">
        为每个分发渠道生成独立部署包——含 Agent 接口、前台页面、Schema 结构化数据、sitemap 和 llms.txt，解压上传即用。
    </p>
</div>

<?php if ($msg): ?>
<div style="background:#d1fae5;color:#065f46;padding:12px 16px;border-radius:8px;margin-bottom:16px;font-size:14px"><?= h($msg) ?></div>
<?php endif ?>
<?php if ($error): ?>
<div style="background:#fee2e2;color:#991b1b;padding:12px 16px;border-radius:8px;margin-bottom:16px;font-size:14px"><?= h($error) ?></div>
<?php endif ?>

<!-- 说明卡 -->
<div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:20px;margin-bottom:28px;font-size:13px;line-height:1.7">
    <strong style="color:#1e40af">包内容说明：</strong><br>
    <code>config.php</code> — Agent 密钥 + 站点名 + 基础地址 &nbsp;|&nbsp;
    <code>index.php</code> — Agent 接口 + 前台路由 &nbsp;|&nbsp;
    <code>storage/articles/</code> — 文章 JSON 存储 &nbsp;|&nbsp;
    <code>.htaccess</code> / <code>nginx.conf.example</code> — 服务器配置 &nbsp;|&nbsp;
    <code>sitemap.xml</code> / <code>llms.txt</code> / <code>robots.txt</code> — SEO + AI 爬虫可读索引
</div>

<div style="display:grid;grid-template-columns:<?= $editAcc?'1fr 420px':'1fr' ?>;gap:24px;align-items:start">

<!-- 渠道列表 -->
<div>
<?php if (empty($accounts)): ?>
<div style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:40px;text-align:center;color:#9ca3af">
    <p>暂无渠道。请先在<a href="distribution.php" style="color:#2563eb">媒体分发</a>中添加媒体账号。</p>
</div>
<?php else: ?>
<div style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;overflow:hidden">
<table style="width:100%;border-collapse:collapse;font-size:13px">
    <thead>
        <tr style="background:#f9fafb;border-bottom:2px solid #f3f4f6">
            <th style="text-align:left;padding:12px 16px;color:#6b7280;font-weight:500">渠道</th>
            <th style="text-align:left;padding:12px 16px;color:#6b7280;font-weight:500">站点地址</th>
            <th style="text-align:center;padding:12px 16px;color:#6b7280;font-weight:500">密钥</th>
            <th style="text-align:center;padding:12px 16px;color:#6b7280;font-weight:500">健康检查</th>
            <th style="text-align:center;padding:12px 16px;color:#6b7280;font-weight:500">操作</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($accounts as $acc): $hasKey = !empty($acc['agent_secret']); ?>
    <tr style="border-bottom:1px solid #f3f4f6;<?= $editId===(int)$acc['id']?'background:#eff6ff':'' ?>">
        <td style="padding:12px 16px">
            <div style="font-weight:600"><?= h($acc['account_name']) ?></div>
            <div style="font-size:11px;color:#9ca3af"><?= h($acc['platform']) ?></div>
        </td>
        <td style="padding:12px 16px">
            <?php if ($acc['agent_base_url']): ?>
            <a href="<?= h($acc['agent_base_url']) ?>" target="_blank"
               style="color:#2563eb;font-size:12px;text-decoration:none">
                <?= h($acc['agent_base_url']) ?>
            </a>
            <?php else: ?>
            <span style="color:#d1d5db;font-size:12px">未配置</span>
            <?php endif ?>
        </td>
        <td style="padding:12px 16px;text-align:center">
            <?php if ($hasKey): ?>
            <span style="background:#d1fae5;color:#065f46;padding:2px 8px;border-radius:4px;font-size:11px;font-family:monospace">
                <?= h(substr($acc['agent_secret'],0,6)) ?>…
            </span>
            <?php else: ?>
            <span style="color:#d1d5db;font-size:12px">未生成</span>
            <?php endif ?>
        </td>
        <td style="padding:12px 16px;text-align:center">
            <?php if ($acc['agent_base_url'] && $hasKey): ?>
            <a href="<?= h(rtrim($acc['agent_base_url'],'/')) ?>/geo-agent/v1/health"
               target="_blank"
               style="font-size:11px;color:#2563eb;text-decoration:none">
                测试连接 ↗
            </a>
            <?php else: ?>
            <span style="color:#d1d5db;font-size:11px">—</span>
            <?php endif ?>
        </td>
        <td style="padding:12px 16px;text-align:center">
            <div style="display:flex;gap:6px;justify-content:center">
                <a href="?edit=<?= (int)$acc['id'] ?>"
                   style="padding:4px 10px;background:#f3f4f6;border:1px solid #d1d5db;
                          border-radius:5px;font-size:11px;text-decoration:none;color:#374151">
                    配置
                </a>
                <?php if ($hasKey): ?>
                <form method="post" style="display:inline">
                    <?= csrf_input() ?>
                    <input type="hidden" name="action" value="download_package">
                    <input type="hidden" name="account_id" value="<?= (int)$acc['id'] ?>">
                    <button type="submit"
                            style="padding:4px 10px;background:#2563eb;color:#fff;border:none;
                                   border-radius:5px;font-size:11px;cursor:pointer;font-weight:600">
                        ↓ 下载包
                    </button>
                </form>
                <?php endif ?>
            </div>
        </td>
    </tr>
    <?php endforeach ?>
    </tbody>
</table>
</div>
<?php endif ?>
</div><!-- /渠道列表 -->

<?php if ($editAcc): ?>
<!-- 配置面板 -->
<div style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:24px;position:sticky;top:20px">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px">
        <h3 style="margin:0;font-size:15px;font-weight:600">配置 Agent</h3>
        <a href="channel-site-package.php" style="font-size:12px;color:#6b7280;text-decoration:none">关闭 ✕</a>
    </div>
    <p style="margin:0 0 16px;font-size:12px;color:#6b7280">
        <?= h($editAcc['account_name']) ?> &middot; <?= h($editAcc['platform']) ?>
    </p>

    <form method="post">
        <?= csrf_input() ?>
        <input type="hidden" name="action" value="save_agent">
        <input type="hidden" name="account_id" value="<?= (int)$editAcc['id'] ?>">

        <div style="margin-bottom:14px">
            <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:4px">
                站点基础地址 <span style="color:#dc2626">*</span>
            </label>
            <input type="url" name="agent_base_url" required
                   value="<?= h($editAcc['agent_base_url']) ?>"
                   placeholder="https://your-site.com"
                   style="width:100%;padding:8px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;box-sizing:border-box">
            <div style="font-size:11px;color:#9ca3af;margin-top:3px">健康检查：[地址]/geo-agent/v1/health</div>
        </div>

        <div style="margin-bottom:14px">
            <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:4px">站点名称</label>
            <input type="text" name="site_name"
                   value="<?= h($editAcc['site_name'] ?: $editAcc['account_name']) ?>"
                   style="width:100%;padding:8px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;box-sizing:border-box">
        </div>

        <div style="margin-bottom:14px">
            <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:4px">网页标题</label>
            <input type="text" name="site_title"
                   value="<?= h($editAcc['site_title'] ?: $editAcc['account_name']) ?>"
                   style="width:100%;padding:8px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;box-sizing:border-box">
        </div>

        <div style="margin-bottom:20px">
            <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:4px">
                Agent 密钥
            </label>
            <?php if ($editAcc['agent_secret']): ?>
            <div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;padding:8px;
                        font-family:monospace;font-size:12px;word-break:break-all;color:#374151;margin-bottom:6px">
                <?= h($editAcc['agent_secret']) ?>
            </div>
            <?php endif ?>
            <input type="text" name="agent_secret"
                   value="<?= h($editAcc['agent_secret']) ?>"
                   placeholder="留空则自动生成"
                   style="width:100%;padding:8px;border:1px solid #d1d5db;border-radius:6px;
                          font-size:12px;font-family:monospace;box-sizing:border-box">
        </div>

        <button type="submit"
                style="width:100%;padding:10px;background:#2563eb;color:#fff;border:none;
                       border-radius:7px;font-size:13px;cursor:pointer;font-weight:600">
            保存配置
        </button>
    </form>

    <?php if ($editAcc['agent_secret']): ?>
    <div style="margin-top:12px;padding-top:12px;border-top:1px solid #f3f4f6">
        <form method="post" onsubmit="return confirm('重置密钥后，已部署的站点包需要更新 config.php，确认？')">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="reset_secret">
            <input type="hidden" name="account_id" value="<?= (int)$editAcc['id'] ?>">
            <button type="submit"
                    style="width:100%;padding:8px;background:#fee2e2;color:#dc2626;border:none;
                           border-radius:7px;font-size:12px;cursor:pointer">
                重置密钥
            </button>
        </form>

        <form method="post" style="margin-top:8px">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="download_package">
            <input type="hidden" name="account_id" value="<?= (int)$editAcc['id'] ?>">
            <button type="submit"
                    style="width:100%;padding:10px;background:#059669;color:#fff;border:none;
                           border-radius:7px;font-size:13px;cursor:pointer;font-weight:600">
                ↓ 下载站点包 ZIP
            </button>
        </form>
    </div>
    <?php endif ?>
</div>
<?php endif ?>

</div><!-- /grid -->

<?php require_once __DIR__ . '/includes/footer.php'; ?>
