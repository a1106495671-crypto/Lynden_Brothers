<?php
/**
 * Playwright 监测服务客户端
 * 调用 geo-playwright 容器的 HTTP API，用真实浏览器检测品牌在各 AI 平台的引用情况
 */

define('PLAYWRIGHT_SERVICE_URL', getenv('PLAYWRIGHT_SERVICE_URL') ?: 'http://playwright:3456');
define('PLAYWRIGHT_TIMEOUT_SEC', 120);

/**
 * 检测品牌在指定 AI 平台上的引用情况
 *
 * @param string $platform  kimi | deepseek | doubao | tongyi
 * @param string $keyword   要搜索的关键词
 * @param string $brandName 品牌名称
 * @param array  $cookies   平台 Cookie 数组（Cookie-Editor 导出的 JSON）
 * @return array {success, brand_mentioned, mention_count, response_text, error?}
 */
function playwright_monitor(string $platform, string $keyword, string $brandName, array $cookies = []): array {
    $url = rtrim(PLAYWRIGHT_SERVICE_URL, '/') . '/monitor';

    $payload = json_encode([
        'platform'   => $platform,
        'keyword'    => $keyword,
        'brand_name' => $brandName,
        'cookies'    => $cookies,
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => PLAYWRIGHT_TIMEOUT_SEC,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    ]);

    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) {
        return ['success' => false, 'error' => 'curl: ' . $err, 'brand_mentioned' => false, 'mention_count' => 0];
    }

    if ($code !== 200) {
        return ['success' => false, 'error' => "HTTP {$code}", 'brand_mentioned' => false, 'mention_count' => 0];
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return ['success' => false, 'error' => 'Invalid JSON response', 'brand_mentioned' => false, 'mention_count' => 0];
    }

    return $data;
}

/**
 * 检查 Playwright 服务是否在线
 */
function playwright_is_available(): bool {
    static $cache = null;
    if ($cache !== null) return $cache;

    $ch = curl_init(rtrim(PLAYWRIGHT_SERVICE_URL, '/') . '/health');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_CONNECTTIMEOUT => 3,
    ]);
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $cache = ($code === 200 && !empty($raw));
    return $cache;
}

/**
 * 从数据库读取某平台的 Cookie
 */
function playwright_get_cookies(PDO $db, string $platform): array {
    try {
        $stmt = $db->prepare("SELECT cookies_json FROM ai_monitor_cookies WHERE platform = ? LIMIT 1");
        $stmt->execute([$platform]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || empty($row['cookies_json'])) return [];
        $cookies = json_decode($row['cookies_json'], true);
        return is_array($cookies) ? $cookies : [];
    } catch (Throwable $_) {
        return [];
    }
}

/**
 * 保存平台 Cookie 到数据库
 */
function playwright_save_cookies(PDO $db, string $platform, array $cookies): bool {
    try {
        $db->exec("
            CREATE TABLE IF NOT EXISTS ai_monitor_cookies (
                id SERIAL PRIMARY KEY,
                platform VARCHAR(50) NOT NULL UNIQUE,
                cookies_json TEXT NOT NULL,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");
        $stmt = $db->prepare("
            INSERT INTO ai_monitor_cookies (platform, cookies_json, updated_at)
            VALUES (?, ?, CURRENT_TIMESTAMP)
            ON CONFLICT (platform) DO UPDATE
                SET cookies_json = EXCLUDED.cookies_json,
                    updated_at   = CURRENT_TIMESTAMP
        ");
        $stmt->execute([$platform, json_encode($cookies, JSON_UNESCAPED_UNICODE)]);
        return true;
    } catch (Throwable $e) {
        error_log('playwright_save_cookies error: ' . $e->getMessage());
        return false;
    }
}
