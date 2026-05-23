<?php
/**
 * AI 爬虫识别与记录
 * 在 router.php 前端页面路由后调用，不影响正常响应。
 */

// UA pattern => [bot_name, bot_company]
const AI_CRAWLER_MAP = [
    'GPTBot'               => ['GPTBot',              'OpenAI'],
    'ChatGPT-User'         => ['ChatGPT-User',         'OpenAI'],
    'OAI-SearchBot'        => ['OAI-SearchBot',        'OpenAI'],
    'ClaudeBot'            => ['ClaudeBot',            'Anthropic'],
    'Claude-Web'           => ['Claude-Web',           'Anthropic'],
    'anthropic-ai'         => ['anthropic-ai',         'Anthropic'],
    'PerplexityBot'        => ['PerplexityBot',        'Perplexity'],
    'Google-Extended'      => ['Google-Extended',      'Google'],
    'Googlebot'            => ['Googlebot',            'Google'],
    'Gemini'               => ['Gemini',               'Google'],
    'Bytespider'           => ['Bytespider',           'ByteDance'],
    'Applebot'             => ['Applebot',             'Apple'],
    'cohere-ai'            => ['cohere-ai',            'Cohere'],
    'YouBot'               => ['YouBot',               'You.com'],
    'Meta-ExternalAgent'   => ['Meta-ExternalAgent',   'Meta'],
    'facebookexternalhit'  => ['FacebookBot',          'Meta'],
    'Diffbot'              => ['Diffbot',              'Diffbot'],
    'CCBot'                => ['CCBot',                'Common Crawl'],
    'SemrushBot'           => ['SemrushBot',           'Semrush'],
    'DuckDuckBot'          => ['DuckDuckBot',          'DuckDuckGo'],
    'bingbot'              => ['BingBot',              'Microsoft'],
    'msnbot'               => ['MSNBot',               'Microsoft'],
];

function ai_crawler_detect(string $ua): ?array {
    foreach (AI_CRAWLER_MAP as $pattern => [$name, $company]) {
        if (stripos($ua, $pattern) !== false) {
            return ['bot_name' => $name, 'bot_company' => $company];
        }
    }
    return null;
}

function ai_crawler_log(): void {
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    if ($ua === '') return;

    $bot = ai_crawler_detect($ua);
    if ($bot === null) return;

    try {
        require_once __DIR__ . '/env_bootstrap.php';
        require_once __DIR__ . '/db_support.php';
        $pdo = db_create_runtime_pdo();

        $path = $_SERVER['REQUEST_URI'] ?? '';
        // 从路径提取 slug（/article/xxx）
        $slug = '';
        if (preg_match('#^/article/([a-zA-Z0-9\-_]+)#', $path, $m)) {
            $slug = $m[1];
        }

        $ip = $_SERVER['HTTP_X_FORWARDED_FOR']
            ?? $_SERVER['HTTP_X_REAL_IP']
            ?? $_SERVER['REMOTE_ADDR']
            ?? '';
        // 只取第一个 IP
        $ip = trim(explode(',', $ip)[0]);

        $stmt = $pdo->prepare("
            INSERT INTO ai_crawler_logs
                (bot_name, bot_company, user_agent, request_path, article_slug, ip_address, referer)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $bot['bot_name'],
            $bot['bot_company'],
            mb_substr($ua, 0, 500),
            mb_substr($path, 0, 500),
            $slug,
            mb_substr($ip, 0, 45),
            mb_substr($_SERVER['HTTP_REFERER'] ?? '', 0, 500),
        ]);
    } catch (Throwable $e) {
        // 静默失败，不影响前端响应
        error_log('[ai_crawler_log] ' . $e->getMessage());
    }
}
