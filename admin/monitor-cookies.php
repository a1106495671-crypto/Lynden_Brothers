<?php
define('FEISHU_TREASURE', true);
session_start();
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database_admin.php';
require_once __DIR__ . '/../includes/playwright_monitor_client.php';

if (empty($_SESSION['admin_id']) && empty($_SESSION['admin_username'])) {
    header('Location: /dl-console/login.php'); exit;
}

$platforms = [
    'kimi'     => ['name' => 'Kimi', 'url' => 'https://kimi.ai', 'domain' => 'kimi.ai', 'icon' => '🌙'],
    'deepseek' => ['name' => 'DeepSeek', 'url' => 'https://chat.deepseek.com', 'domain' => 'deepseek.com', 'icon' => '🔍'],
    'doubao'   => ['name' => '豆包', 'url' => 'https://www.doubao.com/chat/', 'domain' => 'doubao.com', 'icon' => '🫘'],
    'tongyi'   => ['name' => '通义千问', 'url' => 'https://tongyi.aliyun.com/qianwen/', 'domain' => 'tongyi.aliyun.com', 'icon' => '🧠'],
];

$msg = '';
$msgType = 'success';

// Save cookies
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action'])) {
    if ($_POST['action'] === 'save_cookies') {
        $platform = $_POST['platform'] ?? '';
        $rawJson  = trim($_POST['cookies_json'] ?? '');
        if (isset($platforms[$platform]) && $rawJson) {
            $decoded = json_decode($rawJson, true);
            if (!is_array($decoded)) {
                $msg = 'Cookie JSON 格式不正确，请用 Cookie-Editor 导出后粘贴';
                $msgType = 'error';
            } else {
                playwright_save_cookies($db, $platform, $decoded);
                $msg = $platforms[$platform]['name'] . ' Cookie 已保存（' . count($decoded) . ' 条）';
            }
        }
    } elseif ($_POST['action'] === 'test') {
        $platform  = $_POST['platform'] ?? '';
        $keyword   = trim($_POST['keyword'] ?? 'GEO优化');
        $brandName = trim($_POST['brand_name'] ?? 'dongluoji');
        if (isset($platforms[$platform])) {
            if (!playwright_is_available()) {
                $msg = 'Playwright 服务未启动，请先部署 geo-playwright 容器';
                $msgType = 'error';
            } else {
                $cookies = playwright_get_cookies($db, $platform);
                $result  = playwright_monitor($platform, $keyword, $brandName, $cookies);
                if ($result['success']) {
                    $mentioned = $result['brand_mentioned'] ? '✅ 有提及' : '❌ 未提及';
                    $msg = "{$platforms[$platform]['name']} 测试完成：{$mentioned}（提及 {$result['mention_count']} 次）";
                } else {
                    $msg = '测试失败：' . ($result['error'] ?? '未知错误');
                    $msgType = 'error';
                }
            }
        }
    }
}

// Load current cookie status
$cookieStatus = [];
foreach (array_keys($platforms) as $p) {
    $cookies = playwright_get_cookies($db, $p);
    $cookieStatus[$p] = count($cookies);
}

$playwrightOnline = playwright_is_available();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>AI 平台 Cookie 管理 - GEO监测</title>
<link rel="stylesheet" href="/dl-console/assets/admin.css">
<style>
body { background: #0f1117; color: #e0e0e0; font-family: system-ui, sans-serif; margin: 0; }
.header { background: #1a1d26; border-bottom: 1px solid #2a2d3a; padding: 16px 24px; display: flex; align-items: center; gap: 12px; }
.header h1 { margin: 0; font-size: 18px; color: #fff; }
.status-dot { width: 8px; height: 8px; border-radius: 50%; display: inline-block; }
.status-dot.online { background: #22c55e; box-shadow: 0 0 6px #22c55e; }
.status-dot.offline { background: #ef4444; }
.container { max-width: 900px; margin: 0 auto; padding: 24px; }
.alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-size: 14px; }
.alert.success { background: #052e16; border: 1px solid #166534; color: #86efac; }
.alert.error { background: #1c0a0a; border: 1px solid #7f1d1d; color: #fca5a5; }
.card { background: #1a1d26; border: 1px solid #2a2d3a; border-radius: 12px; padding: 20px; margin-bottom: 16px; }
.card-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; }
.platform-name { font-size: 16px; font-weight: 600; color: #fff; display: flex; align-items: center; gap: 8px; }
.badge { padding: 3px 10px; border-radius: 20px; font-size: 12px; font-weight: 600; }
.badge.has-cookies { background: #052e16; color: #86efac; border: 1px solid #166534; }
.badge.no-cookies { background: #1c1a0a; color: #fbbf24; border: 1px solid #78350f; }
.step { background: #0f1117; border-radius: 8px; padding: 12px 16px; margin-bottom: 12px; font-size: 13px; color: #9ca3af; }
.step strong { color: #e0e0e0; }
.step a { color: #60a5fa; }
textarea { width: 100%; box-sizing: border-box; background: #0f1117; border: 1px solid #2a2d3a; color: #e0e0e0; border-radius: 8px; padding: 10px 12px; font-family: monospace; font-size: 12px; resize: vertical; }
textarea:focus { outline: none; border-color: #4f46e5; }
.btn-row { display: flex; gap: 10px; margin-top: 10px; }
.btn { padding: 8px 16px; border-radius: 8px; border: none; cursor: pointer; font-size: 13px; font-weight: 600; transition: opacity .15s; }
.btn:hover { opacity: .85; }
.btn-primary { background: #4f46e5; color: #fff; }
.btn-secondary { background: #374151; color: #e0e0e0; }
.service-status { display: flex; align-items: center; gap: 8px; font-size: 13px; color: #9ca3af; }
.test-row { display: flex; gap: 8px; align-items: flex-end; margin-top: 8px; }
.test-row input { background: #0f1117; border: 1px solid #2a2d3a; color: #e0e0e0; border-radius: 8px; padding: 7px 12px; font-size: 13px; flex: 1; }
.test-row input:focus { outline: none; border-color: #4f46e5; }
</style>
</head>
<body>
<div class="header">
  <h1>🤖 AI 平台 Cookie 管理</h1>
  <div class="service-status">
    <span class="status-dot <?= $playwrightOnline ? 'online' : 'offline' ?>"></span>
    Playwright 服务：<?= $playwrightOnline ? '在线' : '未启动' ?>
  </div>
</div>
<div class="container">

<?php if ($msg): ?>
<div class="alert <?= $msgType ?>"><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>

<div class="card" style="margin-bottom:20px;background:#0f1117;border-color:#1a1d26;">
  <p style="margin:0;font-size:13px;color:#9ca3af;">
    <strong style="color:#e0e0e0;">使用说明：</strong>
    用 Cookie-Editor 浏览器插件导出各平台 Cookie，粘贴到下方对应平台。
    Cookie 有效期通常为 7-30 天，过期后重新导出即可。
  </p>
</div>

<?php foreach ($platforms as $pkey => $pinfo): ?>
<?php $count = $cookieStatus[$pkey] ?? 0; ?>
<div class="card">
  <div class="card-header">
    <div class="platform-name">
      <?= $pinfo['icon'] ?> <?= $pinfo['name'] ?>
      <span style="font-size:12px;color:#6b7280;"><?= $pinfo['domain'] ?></span>
    </div>
    <span class="badge <?= $count > 0 ? 'has-cookies' : 'no-cookies' ?>">
      <?= $count > 0 ? "已配置 {$count} 条 Cookie" : '未配置' ?>
    </span>
  </div>

  <div class="step">
    <strong>步骤：</strong>
    1. 用浏览器打开 <a href="<?= $pinfo['url'] ?>" target="_blank"><?= $pinfo['url'] ?></a> 并登录 &nbsp;
    2. 点击 Cookie-Editor 插件 → Export → Export as JSON &nbsp;
    3. 粘贴到下方文本框 → 保存
  </div>

  <form method="post">
    <input type="hidden" name="action" value="save_cookies">
    <input type="hidden" name="platform" value="<?= $pkey ?>">
    <textarea name="cookies_json" rows="4" placeholder='粘贴 Cookie-Editor 导出的 JSON 数组，格式：[{"name":"...","value":"...","domain":"..."}]'></textarea>
    <div class="btn-row">
      <button type="submit" class="btn btn-primary">保存 Cookie</button>
    </div>
  </form>

  <?php if ($count > 0 && $playwrightOnline): ?>
  <form method="post" style="margin-top:12px;padding-top:12px;border-top:1px solid #2a2d3a;">
    <input type="hidden" name="action" value="test">
    <input type="hidden" name="platform" value="<?= $pkey ?>">
    <div style="font-size:12px;color:#9ca3af;margin-bottom:6px;">测试（真实浏览器搜索，约 30-60 秒）</div>
    <div class="test-row">
      <input type="text" name="keyword" value="GEO优化" placeholder="搜索词">
      <input type="text" name="brand_name" value="董逻辑MGEO" placeholder="品牌名">
      <button type="submit" class="btn btn-secondary">运行测试</button>
    </div>
  </form>
  <?php endif; ?>
</div>
<?php endforeach; ?>

</div>
</body>
</html>
