<?php
define('FEISHU_TREASURE', true);
session_start();

require_once 'includes/config.php';
require_once 'includes/database.php';
require_once 'includes/functions.php';
require_once 'includes/seo_functions.php';

$database = Database::getInstance();
$db = $database->getPDO();

$slug = clean_input($_GET['slug'] ?? '');
$article_id = intval($_GET['id'] ?? 0);

if ($article_id > 0) {
    $article = get_public_article_by_id($article_id);
} else {
    $article = get_article_by_slug($slug);
}

if (!$article) {
    header('HTTP/1.0 404 Not Found');
    exit('文章不存在');
}

try {
    $stmt = $db->prepare("UPDATE articles SET view_count = view_count + 1 WHERE id = ?");
    $stmt->execute([$article['id']]);
    $article['view_count'] = intval($article['view_count'] ?? 0) + 1;
} catch (Exception $e) {
}

$site_title = site_setting_value('site_name', SITE_NAME);
$site_description = site_setting_value('site_description', SITE_DESCRIPTION);
$categories = get_categories();
$article_detail_ad = get_active_article_detail_ad();
$article_tags = get_article_tags($article['id']);
$related_articles = get_related_articles($article['id'], $article['category_id'], 3);
$article_content = $article['content'] ?? '';
$article_excerpt = $article['excerpt'] ?? '';
$article_content_summary = $article_content;

if ($article_content !== '') {
    $title_pattern = preg_quote(trim($article['title']), '/');
    $article_content = preg_replace('/^\s*#\s*' . $title_pattern . '\s*(?:\r?\n)+/u', '', $article_content, 1);
    $article_content_summary = preg_replace('/^\s*#\s*' . $title_pattern . '\s*(?:\r?\n)+/u', '', $article_content_summary, 1);
    $article_excerpt = preg_replace('/^\s*#\s*' . $title_pattern . '\s*/u', '', $article_excerpt, 1);
}

$article_excerpt = preg_replace('/!\[[^\]]*\]\(([^)]+)\)/u', '', $article_excerpt);
$article_content_summary = preg_replace('/!\[[^\]]*\]\(([^)]+)\)/u', '', $article_content_summary);
$article_content_summary = trim(preg_replace('/\n{3,}/', "\n\n", $article_content_summary));
$article_excerpt = clean_markdown_for_summary($article_excerpt, 220);
$article_content_summary = clean_markdown_for_summary($article_content_summary, 320);

$page_title = generate_page_title($article['title'], $article['category_name'] ?? '', $site_title);
$page_description = generate_page_description(!empty($article_excerpt) ? $article_excerpt : mb_substr($article_content_summary, 0, 160, 'UTF-8'));
$page_keywords = generate_page_keywords(
    $article['category_name'] ?? '',
    !empty($article_tags) ? implode(',', array_column($article_tags, 'name')) : ''
);
$canonical_url = geo_absolute_url('article/' . $article['slug']);
$article_summary = build_article_geo_summary($article, $article_tags);
$article_rendered_html = markdown_to_html($article_content);
$copy_markdown_text = trim('# ' . ($article['title'] ?? '') . "\n\n" . trim($article_content));

$structured_data_blocks = [
    generate_website_structured_data(),
    generate_article_structured_data($article, $site_title),
    generate_breadcrumb_structured_data(array_values(array_filter([
        ['name' => '首页', 'url' => geo_absolute_url('/')],
        !empty($article['category_name']) ? ['name' => $article['category_name'], 'url' => geo_absolute_url('category/' . ($article['category_slug'] ?: $article['category_id']))] : null,
        ['name' => $article['title'], 'url' => $canonical_url]
    ])))
];
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?></title>
    <meta name="description" content="<?php echo htmlspecialchars($page_description); ?>">
    <meta name="keywords" content="<?php echo htmlspecialchars($page_keywords); ?>">
    <link rel="canonical" href="<?php echo htmlspecialchars($canonical_url); ?>">
    <meta property="og:title" content="<?php echo htmlspecialchars($article['title']); ?>">
    <meta property="og:description" content="<?php echo htmlspecialchars($page_description); ?>">
    <meta property="og:type" content="article">
    <meta property="og:url" content="<?php echo htmlspecialchars($canonical_url); ?>">
    <meta property="og:site_name" content="<?php echo htmlspecialchars($site_title); ?>">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="/assets/css/style.css">
    <link rel="stylesheet" href="/assets/css/custom.css">
    <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
    <?php output_site_head_extras(); ?>
    <?php output_structured_data_blocks($structured_data_blocks); ?>
</head>
<body class="bg-white">
    <?php include 'includes/header.php'; ?>

    <main class="site-container article-page px-4 sm:px-6 lg:px-8 py-8 lg:py-10">
        <nav class="article-rail article-breadcrumb flex items-center flex-wrap gap-2 text-sm text-gray-500 mb-8" aria-label="面包屑导航">
            <a href="/" class="hover:text-gray-700">首页</a>
            <span class="article-breadcrumb-separator" aria-hidden="true">/</span>
            <?php if (!empty($article['category_name'])): ?>
                <a href="/category/<?php echo htmlspecialchars($article['category_slug'] ?: $article['category_id']); ?>" class="hover:text-gray-700"><?php echo htmlspecialchars($article['category_name']); ?></a>
                <span class="article-breadcrumb-separator" aria-hidden="true">/</span>
            <?php endif; ?>
            <span class="text-gray-900 article-breadcrumb-current"><?php echo htmlspecialchars($article['title']); ?></span>
        </nav>

        <article class="article-shell article-detail-shell mb-8">
            <div class="article-detail-pad">
                <header class="article-rail mb-10">
                    <?php if (!empty($article['category_name'])): ?>
                        <div class="mb-4">
                            <a href="/category/<?php echo htmlspecialchars($article['category_slug'] ?: $article['category_id']); ?>" class="article-section-chip">
                                <i data-lucide="folder" class="w-3.5 h-3.5"></i>
                                <?php echo htmlspecialchars($article['category_name']); ?>
                            </a>
                        </div>
                    <?php endif; ?>

                    <h1 class="article-hero-title font-semibold text-gray-900 mb-4 leading-tight">
                        <?php echo htmlspecialchars($article['title']); ?>
                    </h1>

                    <div class="entry-meta article-meta-row flex flex-wrap items-center gap-3 mb-6">
                        <span class="article-meta-chip flex items-center">
                            <i data-lucide="calendar" class="w-4 h-4 mr-1"></i>
                            更新：<?php echo date('Y-m-d', strtotime($article['published_at'] ?: $article['created_at'])); ?>
                        </span>
                    </div>

                    <div class="article-copy-toolbar" aria-label="文章复制工具">
                        <button type="button" class="article-copy-button article-copy-button--primary" data-copy-rich>
                            <i data-lucide="copy-check" class="w-4 h-4"></i>
                            复制带格式
                        </button>
                        <button type="button" class="article-copy-button" data-copy-markdown>
                            <i data-lucide="file-text" class="w-4 h-4"></i>
                            复制 Markdown
                        </button>
                        <span class="article-copy-status" data-copy-status aria-live="polite"></span>
                    </div>

                </header>

                <div class="article-prose article-rail max-w-none">
                    <?php echo $article_rendered_html; ?>
                </div>

                <template id="articleRichCopyTemplate">
                    <article class="article-rich-copy">
                        <h1><?php echo htmlspecialchars($article['title']); ?></h1>
                        <?php echo $article_rendered_html; ?>
                    </article>
                </template>
                <script type="application/json" id="articleMarkdownCopyData"><?php echo json_encode($copy_markdown_text, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?></script>

                <?php if (!empty($article_tags)): ?>
                    <div class="article-rail mt-8 pt-6 border-t border-gray-100">
                        <div class="flex flex-wrap gap-2">
                            <?php foreach ($article_tags as $tag): ?>
                                <span class="pill-tag">
                                    <i data-lucide="tag" class="w-3 h-3 mr-1"></i>
                                    <?php echo htmlspecialchars($tag['name']); ?>
                                </span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

            </div>
        </article>

        <?php if (!empty($related_articles)): ?>
            <div class="article-shell article-detail-shell p-6">
                <div class="article-rail max-w-none">
                <div class="related-articles-header flex items-center mb-5">
                    <span class="related-articles-header__icon" aria-hidden="true">
                        <i data-lucide="bookmark" class="w-4 h-4 text-gray-500 flex-shrink-0"></i>
                    </span>
                    <h3 class="text-base font-medium text-gray-700 leading-none">相关文章推荐</h3>
                </div>
                <ul class="related-articles-list space-y-4">
                    <?php foreach ($related_articles as $index => $related): ?>
                        <li class="related-article-item flex items-start group">
                            <span class="related-article-rank inline-flex items-center justify-center w-6 h-6 rounded-full bg-gray-100 text-gray-600 text-xs font-medium mr-4 mt-0.5 flex-shrink-0">
                                <?php echo $index + 1; ?>
                            </span>
                            <div class="flex-1 min-w-0">
                                <a href="/article/<?php echo htmlspecialchars($related['slug']); ?>" class="related-article-link block text-gray-900 hover:text-blue-600 transition-colors duration-200 font-medium leading-relaxed text-base mb-1">
                                    <?php echo htmlspecialchars($related['title']); ?>
                                </a>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!empty($article_detail_ad)): ?>
            <aside id="articleStickyAd" class="article-sticky-ad" data-ad-id="<?php echo htmlspecialchars($article_detail_ad['id']); ?>">
                <div class="article-sticky-ad__inner">
                    <button type="button" class="article-sticky-ad__close" id="articleStickyAdClose" aria-label="关闭广告">
                        <i data-lucide="x" class="w-4 h-4"></i>
                    </button>
                    <div class="article-sticky-ad__content">
                        <?php if (!empty($article_detail_ad['badge'])): ?>
                            <div class="article-sticky-ad__badge"><?php echo htmlspecialchars($article_detail_ad['badge']); ?></div>
                        <?php endif; ?>
                        <?php if (!empty($article_detail_ad['title'])): ?>
                            <h3 class="article-sticky-ad__title"><?php echo htmlspecialchars($article_detail_ad['title']); ?></h3>
                        <?php endif; ?>
                        <p class="article-sticky-ad__copy"><?php echo htmlspecialchars($article_detail_ad['copy']); ?></p>
                    </div>
                    <a href="<?php echo htmlspecialchars($article_detail_ad['button_url']); ?>" class="article-sticky-ad__button">
                        <?php echo htmlspecialchars($article_detail_ad['button_text']); ?>
                        <i data-lucide="arrow-up-right" class="w-4 h-4 ml-2"></i>
                    </a>
                </div>
            </aside>
        <?php endif; ?>
    </main>

    <?php include 'includes/footer.php'; ?>

    <script>
    document.addEventListener('DOMContentLoaded', function () {
        const richButton = document.querySelector('[data-copy-rich]');
        const markdownButton = document.querySelector('[data-copy-markdown]');
        const status = document.querySelector('[data-copy-status]');
        const richTemplate = document.getElementById('articleRichCopyTemplate');
        const markdownData = document.getElementById('articleMarkdownCopyData');

        if (!richButton || !markdownButton || !status || !richTemplate || !markdownData) {
            return;
        }

        let markdownText = '';
        try {
            markdownText = JSON.parse(markdownData.textContent || '""');
        } catch (error) {
            markdownText = '';
        }

        function setCopyStatus(message, type) {
            status.textContent = message;
            status.dataset.state = type || 'success';
            window.clearTimeout(setCopyStatus.timer);
            setCopyStatus.timer = window.setTimeout(function () {
                status.textContent = '';
                status.removeAttribute('data-state');
            }, 2600);
        }

        function fallbackCopyText(text) {
            const textarea = document.createElement('textarea');
            textarea.value = text;
            textarea.setAttribute('readonly', '');
            textarea.style.position = 'fixed';
            textarea.style.top = '-9999px';
            document.body.appendChild(textarea);
            textarea.select();
            const copied = document.execCommand('copy');
            textarea.remove();
            if (!copied) {
                throw new Error('copy failed');
            }
        }

        async function copyPlainText(text) {
            if (navigator.clipboard && navigator.clipboard.writeText) {
                await navigator.clipboard.writeText(text);
                return;
            }
            fallbackCopyText(text);
        }

        async function copyRichArticle() {
            const wrapper = document.createElement('div');
            wrapper.appendChild(richTemplate.content.cloneNode(true));
            applyRichCopyStyles(wrapper);
            const html = wrapper.innerHTML;
            const plainText = wrapper.textContent.replace(/\n{3,}/g, '\n\n').trim();

            if (navigator.clipboard && window.ClipboardItem) {
                await navigator.clipboard.write([
                    new ClipboardItem({
                        'text/html': new Blob([html], { type: 'text/html' }),
                        'text/plain': new Blob([plainText], { type: 'text/plain' })
                    })
                ]);
                return;
            }

            fallbackCopyText(plainText);
        }

        function applyRichCopyStyles(root) {
            const baseText = 'color:#374151;font-size:16px;line-height:1.85;margin:0 0 16px;';
            root.querySelectorAll('h1').forEach(function (node) {
                node.setAttribute('style', 'color:#111827;font-size:28px;line-height:1.35;font-weight:700;margin:0 0 20px;');
            });
            root.querySelectorAll('h2').forEach(function (node) {
                node.setAttribute('style', 'color:#111827;font-size:24px;line-height:1.4;font-weight:700;margin:30px 0 16px;');
            });
            root.querySelectorAll('h3').forEach(function (node) {
                node.setAttribute('style', 'color:#111827;font-size:20px;line-height:1.45;font-weight:700;margin:24px 0 14px;');
            });
            root.querySelectorAll('h4').forEach(function (node) {
                node.setAttribute('style', 'color:#111827;font-size:18px;line-height:1.45;font-weight:700;margin:22px 0 12px;');
            });
            root.querySelectorAll('p').forEach(function (node) {
                node.setAttribute('style', baseText);
            });
            root.querySelectorAll('ul').forEach(function (node) {
                node.setAttribute('style', baseText + 'padding-left:24px;');
            });
            root.querySelectorAll('ol').forEach(function (node) {
                node.setAttribute('style', baseText + 'padding-left:24px;');
            });
            root.querySelectorAll('li').forEach(function (node) {
                node.setAttribute('style', 'margin:0 0 10px;color:#374151;line-height:1.85;');
            });
            root.querySelectorAll('blockquote').forEach(function (node) {
                node.setAttribute('style', 'border-left:3px solid #d1d5db;padding-left:16px;color:#6b7280;margin:0 0 16px;line-height:1.85;');
            });
            root.querySelectorAll('table').forEach(function (node) {
                node.setAttribute('style', 'border-collapse:collapse;width:100%;margin:0 0 18px;color:#374151;font-size:15px;line-height:1.7;');
            });
            root.querySelectorAll('th').forEach(function (node) {
                node.setAttribute('style', 'border:1px solid #e5e7eb;background:#f9fafb;color:#111827;font-weight:600;padding:10px;text-align:left;');
            });
            root.querySelectorAll('td').forEach(function (node) {
                node.setAttribute('style', 'border:1px solid #e5e7eb;padding:10px;text-align:left;');
            });
            root.querySelectorAll('a').forEach(function (node) {
                node.setAttribute('style', 'color:#2563eb;text-decoration:none;');
            });
            root.querySelectorAll('code').forEach(function (node) {
                node.setAttribute('style', 'background:#f3f4f6;border-radius:5px;padding:2px 6px;font-size:14px;');
            });
        }

        richButton.addEventListener('click', async function () {
            try {
                await copyRichArticle();
                setCopyStatus('已复制带格式内容', 'success');
            } catch (error) {
                try {
                    await copyPlainText(markdownText);
                    setCopyStatus('富文本不可用，已复制 Markdown', 'warning');
                } catch (fallbackError) {
                    setCopyStatus('复制失败，请手动选择文章内容', 'error');
                }
            }
        });

        markdownButton.addEventListener('click', async function () {
            try {
                await copyPlainText(markdownText);
                setCopyStatus('已复制 Markdown', 'success');
            } catch (error) {
                setCopyStatus('复制失败，请手动选择文章内容', 'error');
            }
        });
    });
    </script>

    <?php if (!empty($article_detail_ad)): ?>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        const stickyAd = document.getElementById('articleStickyAd');
        const closeButton = document.getElementById('articleStickyAdClose');
        if (!stickyAd || !closeButton) {
            return;
        }

        const storageKey = 'articleStickyAdDismissed:' + (stickyAd.dataset.adId || 'default');
        if (window.localStorage && localStorage.getItem(storageKey) === '1') {
            stickyAd.remove();
            return;
        }

        closeButton.addEventListener('click', function () {
            if (window.localStorage) {
                localStorage.setItem(storageKey, '1');
            }
            stickyAd.remove();
        });
    });
    </script>
    <?php endif; ?>

</body>
</html>
