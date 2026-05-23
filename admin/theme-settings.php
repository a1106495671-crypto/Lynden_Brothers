<?php
define('FEISHU_TREASURE', true);
session_start();
require_once '../includes/config.php';
require_once '../includes/database.php';
require_once '../includes/functions.php';
require_once '../includes/theme_manager.php';

$database = Database::getInstance();
$db = $database->getPDO();

$msg = '';
$msg_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $msg = 'CSRF 验证失败';
        $msg_type = 'error';
    } else {
        $new_theme = trim($_POST['theme'] ?? 'default');
        $available = get_available_themes();
        if (!isset($available[$new_theme])) {
            $msg = '主题不存在';
            $msg_type = 'error';
        } else {
            $stmt = $db->prepare("INSERT INTO site_settings (key, value) VALUES ('active_theme', ?)
                ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, updated_at = NOW()");
            $stmt->execute([$new_theme]);
            $msg = '主题已切换为：' . htmlspecialchars($available[$new_theme]['name'] ?? $new_theme);
            $msg_type = 'success';
            header('Location: theme-settings.php?saved=1&theme=' . urlencode($new_theme));
            exit;
        }
    }
}

if (!empty($_GET['saved'])) {
    $msg = '主题切换成功';
    $msg_type = 'success';
}

$themes = get_available_themes();
$active_theme = get_active_theme();
$csrf_token = generate_csrf_token();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>前台主题设置</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="/assets/css/admin.css">
    <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
    <style>
        .theme-card { cursor: pointer; transition: all .15s; }
        .theme-card:hover { transform: translateY(-2px); }
        .theme-card.active { ring: 2px solid #2563eb; }
        .preview-swatch { width: 100%; height: 80px; border-radius: 6px 6px 0 0; }
    </style>
</head>
<body class="bg-gray-50">
<?php include __DIR__ . '/includes/header.php'; ?>

<div class="max-w-5xl mx-auto px-4 py-8">
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">前台主题设置</h1>
            <p class="text-sm text-gray-500 mt-1">选择一个主题，点击"应用主题"立即切换前台样式</p>
        </div>
        <a href="/" target="_blank" class="inline-flex items-center gap-2 px-4 py-2 bg-gray-100 text-gray-700 rounded-lg text-sm hover:bg-gray-200">
            <i data-lucide="external-link" class="w-4 h-4"></i> 预览前台
        </a>
    </div>

    <?php if ($msg): ?>
        <div class="mb-6 px-4 py-3 rounded-lg text-sm <?php echo $msg_type === 'success' ? 'bg-green-50 text-green-700 border border-green-200' : 'bg-red-50 text-red-700 border border-red-200'; ?>">
            <?php echo htmlspecialchars($msg); ?>
        </div>
    <?php endif; ?>

    <form method="POST" action="theme-settings.php">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
        <input type="hidden" name="theme" id="selected-theme" value="<?php echo htmlspecialchars($active_theme); ?>">

        <?php if (empty($themes)): ?>
            <div class="bg-white rounded-xl border border-gray-200 p-12 text-center text-gray-400">
                <i data-lucide="layout-template" class="w-10 h-10 mx-auto mb-3"></i>
                <p>未找到任何主题，请确认 themes/ 目录下有 manifest.json</p>
            </div>
        <?php else: ?>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5 mb-8">
                <?php foreach ($themes as $id => $meta): ?>
                    <?php $is_active = ($id === $active_theme); ?>
                    <div class="theme-card bg-white rounded-xl border-2 <?php echo $is_active ? 'border-blue-500 shadow-md' : 'border-gray-200'; ?> overflow-hidden"
                         data-theme="<?php echo htmlspecialchars($id); ?>"
                         onclick="selectTheme(this)">
                        <div class="preview-swatch" style="background: <?php echo htmlspecialchars($meta['preview_color'] ?? '#888'); ?>;"></div>
                        <div class="p-4">
                            <div class="flex items-center justify-between mb-1">
                                <h3 class="font-semibold text-gray-900 text-base"><?php echo htmlspecialchars($meta['name'] ?? $id); ?></h3>
                                <?php if ($is_active): ?>
                                    <span class="inline-flex items-center gap-1 text-xs bg-blue-100 text-blue-700 px-2 py-0.5 rounded-full font-medium">
                                        <i data-lucide="check-circle" class="w-3 h-3"></i> 当前
                                    </span>
                                <?php endif; ?>
                            </div>
                            <p class="text-sm text-gray-500"><?php echo htmlspecialchars($meta['description'] ?? ''); ?></p>
                            <div class="mt-3 flex items-center gap-3 text-xs text-gray-400">
                                <?php if (!empty($meta['version'])): ?>
                                    <span>v<?php echo htmlspecialchars($meta['version']); ?></span>
                                <?php endif; ?>
                                <?php if (!empty($meta['author'])): ?>
                                    <span>by <?php echo htmlspecialchars($meta['author']); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="flex items-center gap-4">
                <button type="submit"
                        class="inline-flex items-center gap-2 px-6 py-2.5 bg-blue-600 text-white rounded-lg font-medium hover:bg-blue-700 transition-colors">
                    <i data-lucide="check" class="w-4 h-4"></i> 应用主题
                </button>
                <span class="text-sm text-gray-400" id="selected-label">
                    当前选中：<?php echo htmlspecialchars($themes[$active_theme]['name'] ?? $active_theme); ?>
                </span>
            </div>
        <?php endif; ?>
    </form>

    <!-- Theme file status -->
    <div class="mt-10">
        <h2 class="text-base font-semibold text-gray-700 mb-3">主题目录状态</h2>
        <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
            <table class="w-full text-sm">
                <thead><tr class="bg-gray-50 text-gray-500 text-xs uppercase">
                    <th class="px-4 py-2 text-left">主题 ID</th>
                    <th class="px-4 py-2 text-left">名称</th>
                    <th class="px-4 py-2 text-center">home.php</th>
                    <th class="px-4 py-2 text-center">article.php</th>
                    <th class="px-4 py-2 text-center">header.php</th>
                    <th class="px-4 py-2 text-center">footer.php</th>
                </tr></thead>
                <tbody class="divide-y divide-gray-100">
                <?php
                $base = dirname(__DIR__) . '/themes';
                $check_files = ['home', 'article', 'header', 'footer'];
                foreach ($themes as $id => $meta):
                    $row_class = ($id === $active_theme) ? 'bg-blue-50' : '';
                ?>
                    <tr class="<?php echo $row_class; ?>">
                        <td class="px-4 py-2 font-mono text-gray-700"><?php echo htmlspecialchars($id); ?></td>
                        <td class="px-4 py-2 text-gray-600"><?php echo htmlspecialchars($meta['name'] ?? ''); ?></td>
                        <?php foreach ($check_files as $f): ?>
                            <?php $exists = file_exists("{$base}/{$id}/{$f}.php"); ?>
                            <td class="px-4 py-2 text-center">
                                <?php if ($exists): ?>
                                    <i data-lucide="check-circle" class="w-4 h-4 text-green-500 inline"></i>
                                <?php else: ?>
                                    <i data-lucide="x-circle" class="w-4 h-4 text-gray-300 inline"></i>
                                <?php endif; ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="text-xs text-gray-400 mt-2">缺失的模板文件会自动 fallback 到 default 主题对应文件。</p>
    </div>
</div>

<script>
const names = <?php echo json_encode(array_map(fn($m) => $m['name'] ?? '', $themes)); ?>;
function selectTheme(card) {
    document.querySelectorAll('.theme-card').forEach(c => {
        c.classList.remove('border-blue-500', 'shadow-md');
        c.classList.add('border-gray-200');
    });
    card.classList.remove('border-gray-200');
    card.classList.add('border-blue-500', 'shadow-md');
    const id = card.dataset.theme;
    document.getElementById('selected-theme').value = id;
    document.getElementById('selected-label').textContent = '当前选中：' + (names[id] || id);
}
document.addEventListener('DOMContentLoaded', function() {
    if (typeof lucide !== 'undefined') lucide.createIcons();
});
</script>
</body>
</html>
