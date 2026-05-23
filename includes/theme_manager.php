<?php
/**
 * 前台主题管理器
 */

function get_active_theme(): string {
    static $cached = null;
    if ($cached !== null) return $cached;
    try {
        if (function_exists('site_setting_value')) {
            $cached = (string) site_setting_value('active_theme', 'default');
        } elseif (function_exists('get_setting')) {
            $cached = (string) get_setting('active_theme', 'default');
        } else {
            $cached = 'default';
        }
    } catch (Throwable $e) {
        $cached = 'default';
    }
    return $cached ?: 'default';
}

function theme_file(string $page): string {
    $theme = get_active_theme();
    $base  = dirname(__DIR__) . '/themes';
    $path  = "{$base}/{$theme}/{$page}.php";
    if (!file_exists($path)) {
        $path = "{$base}/default/{$page}.php";
    }
    return $path;
}

function theme_render(string $page): void {
    require theme_file($page);
}

function get_available_themes(): array {
    $base   = dirname(__DIR__) . '/themes';
    $dirs   = glob($base . '/*/manifest.json') ?: [];
    $themes = [];
    foreach ($dirs as $mf) {
        $meta = json_decode(file_get_contents($mf), true);
        if (!is_array($meta)) continue;
        $themes[$meta['id'] ?? basename(dirname($mf))] = $meta;
    }
    return $themes;
}
