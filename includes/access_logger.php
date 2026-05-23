<?php
/**
 * 前台访问日志
 * 记录所有前台页面请求，区分人类访问和 AI 爬虫。
 * 在 router.php 中调用，替代原 ai_crawler_log()。
 */

require_once __DIR__ . '/ai_crawler_logger.php'; // 复用 AI_CRAWLER_MAP 和 ai_crawler_detect()

function access_log_record(): void {
    $ua   = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $path = $_SERVER['REQUEST_URI'] ?? '/';

    // 识别页面类型
    $pageType = 'other';
    $slug     = '';
    if ($path === '/' || $path === '') {
        $pageType = 'home';
    } elseif (preg_match('#^/article/([a-zA-Z0-9\-_]+)#', $path, $m)) {
        $pageType = 'article';
        $slug     = $m[1];
    } elseif (preg_match('#^/category/#', $path)) {
        $pageType = 'category';
    } elseif (preg_match('#^/archive#', $path)) {
        $pageType = 'archive';
    } elseif (preg_match('#^/search/#', $path)) {
        $pageType = 'search';
    }

    // AI 爬虫检测
    $bot     = $ua !== '' ? ai_crawler_detect($ua) : null;
    $isBot   = $bot !== null;
    $botName = $bot['bot_name']    ?? '';
    $botCo   = $bot['bot_company'] ?? '';

    // IP（取真实 IP）
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR']
        ?? $_SERVER['HTTP_X_REAL_IP']
        ?? $_SERVER['REMOTE_ADDR']
        ?? '';
    $ip = mb_substr(trim(explode(',', $ip)[0]), 0, 45);

    try {
        require_once __DIR__ . '/env_bootstrap.php';
        require_once __DIR__ . '/db_support.php';
        $pdo = db_create_runtime_pdo();

        $pdo->prepare("
            INSERT INTO access_logs
                (request_path, page_type, article_slug, ip_address, user_agent, referer,
                 is_bot, bot_name, bot_company)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([
            mb_substr($path, 0, 500),
            $pageType,
            $slug,
            $ip,
            mb_substr($ua, 0, 500),
            mb_substr($_SERVER['HTTP_REFERER'] ?? '', 0, 500),
            $isBot ? 'true' : 'false',
            $botName,
            $botCo,
        ]);

        // 同步写 ai_crawler_logs（保持原有表数据完整）
        if ($isBot && $slug !== '') {
            $pdo->prepare("
                INSERT INTO ai_crawler_logs
                    (bot_name, bot_company, user_agent, request_path, article_slug, ip_address, referer)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ")->execute([
                $botName,
                $botCo,
                mb_substr($ua, 0, 500),
                mb_substr($path, 0, 500),
                $slug,
                $ip,
                mb_substr($_SERVER['HTTP_REFERER'] ?? '', 0, 500),
            ]);
        }
    } catch (Throwable $e) {
        error_log('[access_log] ' . $e->getMessage());
    }
}
