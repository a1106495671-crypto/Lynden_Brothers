<?php
/**
 * 多渠道 Agent 密钥管理
 * 每个分发渠道拥有独立密钥，支持查看/复制/重置/在线检测
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
$csrf_token = generate_csrf_token();

// ─── AJAX: ping health endpoint ───────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'ping') {
    header('Content-Type: application/json; charset=utf-8');
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) { echo json_encode(['ok' => false, 'msg' => '参数错误']); exit; }

    $stmt = $db->prepare("SELECT agent_base_url, agent_secret FROM media_accounts WHERE id=?");
    $stmt->execute([$id]);
    $acc = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$acc || empty($acc['agent_base_url']) || empty($acc['agent_secret'])) {
        echo json_encode(['ok' => false, 'msg' => '未配置']); exit;
    }

    $healthUrl = rtrim($acc['agent_base_url'], '/') . '/geo-agent/v1/health';
    $t0 = microtime(true);
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $healthUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 6,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $acc['agent_secret']],
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $resp = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    $ms = round((microtime(true) - $t0) * 1000);

    if ($err !== '') {
        echo json_encode(['ok' => false, 'msg' => '连接失败: ' . $err, 'ms' => $ms]); exit;
    }

    $data = json_decode((string) $resp, true);
    if ($code === 200 && is_array($data)) {
        echo json_encode(['ok' => true, 'msg' => $data['message'] ?? 'online', 'ms' => $ms]); exit;
    }
    echo json_encode(['ok' => false, 'msg' => "HTTP {$code}", 'ms' => $ms]);
    exit;
}

// ─── POST actions ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'CSRF 验证失败';
    } else {
        $action = $_POST['action'] ?? '';
        $id     = (int) ($_POST['account_id'] ?? 0);

        try {
            if ($action === 'generate' && $id > 0) {
                // 生成新密钥（首次）
                $stmt = $db->prepare("SELECT agent_secret FROM media_accounts WHERE id=?");
                $stmt->execute([$id]);
                $existing = $stmt->fetchColumn();
                if (empty($existing)) {
                    $secret = bin2hex(random_bytes(20));
                    $db->prepare("UPDATE media_accounts SET agent_secret=?, updated_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$secret, $id]);
                    $msg = '密钥已生成';
                } else {
                    $error = '该渠道已有密钥，请使用「重置」操作';
                }

            } elseif ($action === 'reset' && $id > 0) {
                $secret = bin2hex(random_bytes(20));
                $db->prepare("UPDATE media_accounts SET agent_secret=?, updated_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$secret, $id]);
                $msg = '密钥已重置，旧密钥立即失效';

            } elseif ($action === 'save_url' && $id > 0) {
                $base = rtrim(trim($_POST['agent_base_url'] ?? ''), '/');
                $db->prepare("UPDATE media_accounts SET agent_base_url=?, updated_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$base, $id]);
                $msg = 'Agent 地址已保存';

            } elseif ($action === 'generate_all') {
                // 为所有无密钥渠道批量生成
                $rows = $db->query("SELECT id FROM media_accounts WHERE COALESCE(agent_secret,'') = ''")->fetchAll(PDO::FETCH_COLUMN);
                foreach ($rows as $rid) {
                    $secret = bin2hex(random_bytes(20));
                    $db->prepare("UPDATE media_accounts SET agent_secret=?, updated_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$secret, $rid]);
                }
                $msg = '已为 ' . count($rows) . ' 个渠道生成密钥';
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }

    $qs = http_build_query(array_filter(['msg' => $msg, 'err' => $error]));
    header('Location: agent-keys.php' . ($qs ? '?' . $qs : ''));
    exit;
}

$msg   = $msg   ?: htmlspecialchars($_GET['msg'] ?? '');
$error = $error ?: htmlspecialchars($_GET['err'] ?? '');

// ─── load accounts ────────────────────────────────────────────────────────────
$accounts = $db->query("
    SELECT id, account_name, platform, status, agent_secret, agent_base_url, site_name, updated_at
    FROM media_accounts
    ORDER BY CASE WHEN COALESCE(agent_secret,'')='' THEN 1 ELSE 0 END ASC,
             status ASC, account_name ASC
")->fetchAll(PDO::FETCH_ASSOC);

$total     = count($accounts);
$hasKey    = count(array_filter($accounts, fn($a) => !empty($a['agent_secret'])));
$hasUrl    = count(array_filter($accounts, fn($a) => !empty($a['agent_base_url'])));
$ready     = count(array_filter($accounts, fn($a) => !empty($a['agent_secret']) && !empty($a['agent_base_url'])));

$page_title = 'Agent 密钥管理';
require_once __DIR__ . '/includes/header.php';

function h(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
?>

<div class="mb-6 flex items-center justify-between">
    <div>
        <h1 class="text-2xl font-bold text-gray-900">多渠道 Agent 密钥管理</h1>
        <p class="mt-1 text-sm text-gray-500">每个渠道持有独立密钥，用于目标站点 API 鉴权；密钥一旦重置旧值立即失效</p>
    </div>
    <div class="flex gap-3">
        <form method="POST" action="agent-keys.php">
            <input type="hidden" name="csrf_token" value="<?php echo h($csrf_token); ?>">
            <input type="hidden" name="action" value="generate_all">
            <button type="submit"
                    class="inline-flex items-center gap-2 px-4 py-2 bg-blue-600 text-white text-sm rounded-lg hover:bg-blue-700">
                <i data-lucide="zap" class="w-4 h-4"></i> 批量生成缺失密钥
            </button>
        </form>
        <a href="channel-site-package.php" class="inline-flex items-center gap-2 px-4 py-2 border border-gray-300 text-gray-700 text-sm rounded-lg hover:bg-gray-50">
            <i data-lucide="package" class="w-4 h-4"></i> 下载站点包
        </a>
    </div>
</div>

<?php if ($msg): ?>
    <div class="mb-5 px-4 py-3 bg-green-50 border border-green-200 rounded-lg text-sm text-green-700"><?php echo $msg; ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="mb-5 px-4 py-3 bg-red-50 border border-red-200 rounded-lg text-sm text-red-700"><?php echo $error; ?></div>
<?php endif; ?>

<!-- Stats row -->
<div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-8">
    <?php
    $stats = [
        ['label' => '渠道总数',    'value' => $total,  'color' => 'gray',   'icon' => 'layers'],
        ['label' => '已生成密钥',  'value' => $hasKey,  'color' => 'green',  'icon' => 'key'],
        ['label' => '已配置地址',  'value' => $hasUrl,  'color' => 'blue',   'icon' => 'globe'],
        ['label' => '完整可用',    'value' => $ready,   'color' => 'purple', 'icon' => 'shield-check'],
    ];
    $palette = ['gray'=>'bg-gray-100 text-gray-600','green'=>'bg-green-100 text-green-600','blue'=>'bg-blue-100 text-blue-600','purple'=>'bg-purple-100 text-purple-600'];
    foreach ($stats as $s):
    ?>
    <div class="bg-white rounded-xl border border-gray-200 p-4 flex items-center gap-4">
        <div class="w-10 h-10 rounded-full flex items-center justify-center <?php echo $palette[$s['color']]; ?>">
            <i data-lucide="<?php echo $s['icon']; ?>" class="w-5 h-5"></i>
        </div>
        <div>
            <p class="text-xs text-gray-400"><?php echo $s['label']; ?></p>
            <p class="text-xl font-bold text-gray-900"><?php echo $s['value']; ?></p>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Channel table -->
<div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
    <div class="px-5 py-3 border-b border-gray-100 flex items-center gap-2 text-sm font-semibold text-gray-700">
        <i data-lucide="key-round" class="w-4 h-4 text-gray-400"></i> 渠道密钥列表
    </div>
    <?php if (empty($accounts)): ?>
        <div class="p-12 text-center text-gray-400 text-sm">
            暂无渠道，请先在
            <a href="media-accounts.php" class="text-blue-600 hover:underline">媒体账号</a>
            中添加渠道
        </div>
    <?php else: ?>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="bg-gray-50 text-xs text-gray-400 uppercase border-b border-gray-100">
                    <th class="px-4 py-3 text-left">渠道</th>
                    <th class="px-4 py-3 text-left">Agent 地址</th>
                    <th class="px-4 py-3 text-center">密钥</th>
                    <th class="px-4 py-3 text-center">在线状态</th>
                    <th class="px-4 py-3 text-center">更新时间</th>
                    <th class="px-4 py-3 text-center">操作</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
            <?php foreach ($accounts as $acc):
                $id         = (int) $acc['id'];
                $secret     = (string) ($acc['agent_secret'] ?? '');
                $baseUrl    = (string) ($acc['agent_base_url'] ?? '');
                $hasSecret  = $secret !== '';
                $hasBaseUrl = $baseUrl !== '';
                $isReady    = $hasSecret && $hasBaseUrl;
                $masked     = $hasSecret ? substr($secret, 0, 6) . '••••••••••••••••••••••••••••' . substr($secret, -4) : '';
            ?>
                <tr class="hover:bg-gray-50 <?php echo !$hasSecret ? 'opacity-70' : ''; ?>">
                    <!-- channel -->
                    <td class="px-4 py-3">
                        <div class="font-medium text-gray-900"><?php echo h($acc['account_name']); ?></div>
                        <div class="text-xs text-gray-400 mt-0.5">
                            <?php echo h($acc['platform']); ?>
                            <?php if ($acc['status'] !== 'active'): ?>
                                <span class="ml-1 bg-gray-100 text-gray-500 px-1 rounded">停用</span>
                            <?php endif; ?>
                        </div>
                    </td>

                    <!-- agent url -->
                    <td class="px-4 py-3">
                        <div class="flex items-center gap-2" x-data>
                            <?php if ($hasBaseUrl): ?>
                                <a href="<?php echo h($baseUrl); ?>" target="_blank"
                                   class="text-xs text-blue-600 hover:underline max-w-xs truncate block"
                                   title="<?php echo h($baseUrl); ?>">
                                    <?php echo h($baseUrl); ?>
                                </a>
                            <?php else: ?>
                                <span class="text-xs text-gray-300">未配置</span>
                            <?php endif; ?>
                            <button onclick="showUrlEdit(<?php echo $id; ?>, '<?php echo h($baseUrl); ?>')"
                                    class="text-gray-300 hover:text-blue-500 flex-shrink-0" title="编辑地址">
                                <i data-lucide="pencil" class="w-3.5 h-3.5"></i>
                            </button>
                        </div>
                    </td>

                    <!-- key -->
                    <td class="px-4 py-3 text-center">
                        <?php if ($hasSecret): ?>
                            <div class="flex items-center justify-center gap-1.5">
                                <code class="font-mono text-xs bg-gray-100 text-gray-700 px-2 py-0.5 rounded key-display"
                                      id="key-<?php echo $id; ?>"
                                      data-full="<?php echo h($secret); ?>"
                                      data-masked="<?php echo h($masked); ?>">
                                    <?php echo h($masked); ?>
                                </code>
                                <button onclick="toggleKey(<?php echo $id; ?>)" title="显示/隐藏"
                                        class="text-gray-400 hover:text-gray-700">
                                    <i data-lucide="eye" class="w-3.5 h-3.5" id="eye-<?php echo $id; ?>"></i>
                                </button>
                                <button onclick="copyKey(<?php echo $id; ?>)" title="复制密钥"
                                        class="text-gray-400 hover:text-blue-600">
                                    <i data-lucide="copy" class="w-3.5 h-3.5"></i>
                                </button>
                            </div>
                        <?php else: ?>
                            <span class="text-xs text-gray-300">未生成</span>
                        <?php endif; ?>
                    </td>

                    <!-- ping status -->
                    <td class="px-4 py-3 text-center">
                        <?php if ($isReady): ?>
                            <button onclick="pingChannel(<?php echo $id; ?>, this)"
                                    class="inline-flex items-center gap-1 px-2.5 py-1 border border-gray-200 rounded-full text-xs text-gray-500 hover:border-blue-300 hover:text-blue-600 transition-colors"
                                    id="ping-btn-<?php echo $id; ?>">
                                <i data-lucide="wifi" class="w-3 h-3"></i>
                                <span id="ping-label-<?php echo $id; ?>">检测</span>
                            </button>
                        <?php else: ?>
                            <span class="text-xs text-gray-200">—</span>
                        <?php endif; ?>
                    </td>

                    <!-- updated_at -->
                    <td class="px-4 py-3 text-center text-xs text-gray-400">
                        <?php echo $acc['updated_at'] ? date('m-d H:i', strtotime($acc['updated_at'])) : '—'; ?>
                    </td>

                    <!-- actions -->
                    <td class="px-4 py-3 text-center">
                        <div class="flex items-center justify-center gap-1.5">
                            <?php if (!$hasSecret): ?>
                                <form method="POST" action="agent-keys.php">
                                    <input type="hidden" name="csrf_token" value="<?php echo h($csrf_token); ?>">
                                    <input type="hidden" name="action" value="generate">
                                    <input type="hidden" name="account_id" value="<?php echo $id; ?>">
                                    <button type="submit"
                                            class="inline-flex items-center gap-1 px-2.5 py-1.5 bg-blue-600 text-white text-xs rounded-lg hover:bg-blue-700">
                                        <i data-lucide="key" class="w-3 h-3"></i> 生成密钥
                                    </button>
                                </form>
                            <?php else: ?>
                                <button onclick="confirmReset(<?php echo $id; ?>, '<?php echo h($acc['account_name']); ?>')"
                                        class="inline-flex items-center gap-1 px-2.5 py-1.5 border border-red-200 text-red-600 text-xs rounded-lg hover:bg-red-50">
                                    <i data-lucide="refresh-cw" class="w-3 h-3"></i> 重置
                                </button>
                            <?php endif; ?>

                            <?php if ($isReady): ?>
                                <a href="channel-site-package.php?edit=<?php echo $id; ?>"
                                   class="inline-flex items-center gap-1 px-2.5 py-1.5 border border-gray-200 text-gray-600 text-xs rounded-lg hover:bg-gray-50">
                                    <i data-lucide="package" class="w-3 h-3"></i> 包
                                </a>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- Reset confirm modal -->
<div id="reset-modal" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 hidden">
    <div class="bg-white rounded-xl shadow-xl p-6 w-full max-w-sm">
        <div class="flex items-center gap-3 mb-4">
            <div class="w-10 h-10 rounded-full bg-red-100 flex items-center justify-center">
                <i data-lucide="alert-triangle" class="w-5 h-5 text-red-600"></i>
            </div>
            <div>
                <h3 class="font-semibold text-gray-900">确认重置密钥</h3>
                <p class="text-xs text-gray-500" id="reset-channel-name"></p>
            </div>
        </div>
        <p class="text-sm text-gray-600 mb-5">
            重置后，旧密钥<strong>立即失效</strong>，目标站点将无法接受来自本系统的推文，需要同步更新部署的 <code>config.php</code> 或重新下载站点包。
        </p>
        <form method="POST" action="agent-keys.php" id="reset-form">
            <input type="hidden" name="csrf_token" value="<?php echo h($csrf_token); ?>">
            <input type="hidden" name="action" value="reset">
            <input type="hidden" name="account_id" id="reset-account-id" value="">
            <div class="flex gap-3 justify-end">
                <button type="button" onclick="closeModal('reset-modal')"
                        class="px-4 py-2 border border-gray-300 text-gray-700 rounded-lg text-sm hover:bg-gray-50">
                    取消
                </button>
                <button type="submit"
                        class="px-4 py-2 bg-red-600 text-white rounded-lg text-sm hover:bg-red-700">
                    确认重置
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Edit URL modal -->
<div id="url-modal" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 hidden">
    <div class="bg-white rounded-xl shadow-xl p-6 w-full max-w-md">
        <h3 class="font-semibold text-gray-900 mb-4">编辑 Agent 地址</h3>
        <form method="POST" action="agent-keys.php">
            <input type="hidden" name="csrf_token" value="<?php echo h($csrf_token); ?>">
            <input type="hidden" name="action" value="save_url">
            <input type="hidden" name="account_id" id="url-account-id" value="">
            <div class="mb-4">
                <label class="text-xs text-gray-500 block mb-1">目标站点根地址（不含末尾斜杠）</label>
                <input type="url" name="agent_base_url" id="url-input"
                       placeholder="https://your-site.com"
                       class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-300">
                <p class="text-xs text-gray-400 mt-1">健康检查将访问：[地址]/geo-agent/v1/health</p>
            </div>
            <div class="flex gap-3 justify-end">
                <button type="button" onclick="closeModal('url-modal')"
                        class="px-4 py-2 border border-gray-300 text-gray-700 rounded-lg text-sm hover:bg-gray-50">
                    取消
                </button>
                <button type="submit"
                        class="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm hover:bg-blue-700">
                    保存
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// ── key reveal / copy ──────────────────────────────────────────────────────────
const keyShown = {};
function toggleKey(id) {
    const el = document.getElementById('key-' + id);
    const eye = document.getElementById('eye-' + id);
    keyShown[id] = !keyShown[id];
    el.textContent = keyShown[id] ? el.dataset.full : el.dataset.masked;
    eye.setAttribute('data-lucide', keyShown[id] ? 'eye-off' : 'eye');
    lucide.createIcons({ nodes: [eye] });
}

function copyKey(id) {
    const el = document.getElementById('key-' + id);
    const text = el.dataset.full;
    navigator.clipboard.writeText(text).then(() => {
        const orig = el.textContent;
        el.textContent = '✓ 已复制';
        el.classList.add('text-green-600');
        setTimeout(() => {
            el.textContent = keyShown[id] ? el.dataset.full : el.dataset.masked;
            el.classList.remove('text-green-600');
        }, 1500);
    }).catch(() => {
        prompt('复制此密钥：', text);
    });
}

// ── ping ───────────────────────────────────────────────────────────────────────
async function pingChannel(id, btn) {
    const label = document.getElementById('ping-label-' + id);
    label.textContent = '检测中…';
    btn.disabled = true;

    try {
        const resp = await fetch(`agent-keys.php?action=ping&id=${id}`);
        const data = await resp.json();
        if (data.ok) {
            label.textContent = `✓ ${data.ms}ms`;
            btn.className = btn.className.replace('text-gray-500', 'text-green-600').replace('border-gray-200', 'border-green-300');
        } else {
            label.textContent = `✗ ${data.msg}`;
            btn.className = btn.className.replace('text-gray-500', 'text-red-500').replace('border-gray-200', 'border-red-300');
        }
    } catch(e) {
        label.textContent = '✗ 超时';
    }

    btn.disabled = false;
    setTimeout(() => {
        label.textContent = '检测';
        btn.className = btn.className.replace('text-green-600', 'text-gray-500')
                                     .replace('text-red-500', 'text-gray-500')
                                     .replace('border-green-300', 'border-gray-200')
                                     .replace('border-red-300', 'border-gray-200');
    }, 8000);
}

// ── modals ─────────────────────────────────────────────────────────────────────
function confirmReset(id, name) {
    document.getElementById('reset-account-id').value = id;
    document.getElementById('reset-channel-name').textContent = name;
    document.getElementById('reset-modal').classList.remove('hidden');
}

function showUrlEdit(id, currentUrl) {
    document.getElementById('url-account-id').value = id;
    document.getElementById('url-input').value = currentUrl;
    document.getElementById('url-modal').classList.remove('hidden');
}

function closeModal(id) {
    document.getElementById(id).classList.add('hidden');
}

// close on backdrop click
['reset-modal','url-modal'].forEach(id => {
    document.getElementById(id).addEventListener('click', e => {
        if (e.target === e.currentTarget) closeModal(id);
    });
});

document.addEventListener('DOMContentLoaded', () => {
    if (typeof lucide !== 'undefined') lucide.createIcons();
});
</script>
