<?php
/**
 * 管理端：客户门户账号管理
 * 路径：/admin/client-manage.php
 */
define('FEISHU_TREASURE', true);
session_start();
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database_admin.php';
require_once __DIR__ . '/../includes/functions.php';
require_admin_login();

// Ensure table
try {
    $db->exec("CREATE TABLE IF NOT EXISTS geo_client_credentials (
        id SERIAL PRIMARY KEY,
        customer_id VARCHAR(100) UNIQUE,
        password_hash VARCHAR(255),
        last_login TIMESTAMP,
        created_at TIMESTAMP DEFAULT NOW()
    )");
} catch (Throwable $e) {}

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $cid = trim($_POST['customer_id'] ?? '');

    if ($action === 'set_password' && $cid) {
        $pass = trim($_POST['password'] ?? '');
        if (strlen($pass) < 6) {
            $error = '密码至少6位';
        } else {
            $hash = password_hash($pass, PASSWORD_DEFAULT);
            $db->prepare("INSERT INTO geo_client_credentials (customer_id, password_hash) VALUES (?,?) ON CONFLICT (customer_id) DO UPDATE SET password_hash=EXCLUDED.password_hash")->execute([$cid, $hash]);
            $message = "✅ 已为「{$cid}」设置门户密码";
        }
    } elseif ($action === 'revoke' && $cid) {
        $db->prepare("DELETE FROM geo_client_credentials WHERE customer_id=?")->execute([$cid]);
        $message = "✅ 已撤销「{$cid}」的门户访问权限";
    }
}

$customers = $db->query("SELECT c.customer_id, c.name, gc.password_hash, gc.last_login FROM customers c LEFT JOIN geo_client_credentials gc ON gc.customer_id=c.customer_id ORDER BY c.name")->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = '客户门户管理';
require_once __DIR__ . '/includes/header.php';
?>
<div class="max-w-4xl mx-auto px-4 py-6">
  <div class="mb-6">
    <h1 class="text-2xl font-bold text-gray-900">客户门户管理</h1>
    <p class="text-sm text-gray-500 mt-1">为客户开通或撤销自助数据看板的登录权限</p>
    <div class="mt-2 text-sm text-indigo-700 bg-indigo-50 rounded-lg px-3 py-2 inline-block">
      客户门户地址：<code class="font-mono">http://<?= $_SERVER['HTTP_HOST'] ?>/client/</code>
    </div>
  </div>

  <?php if ($message): ?>
    <div class="mb-4 rounded-lg bg-green-50 border border-green-200 px-4 py-3 text-sm text-green-800"><?= htmlspecialchars($message) ?></div>
  <?php endif; ?>
  <?php if ($error): ?>
    <div class="mb-4 rounded-lg bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-700"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
    <table class="w-full text-sm">
      <thead class="bg-gray-50 border-b border-gray-200">
        <tr>
          <th class="px-4 py-3 text-left text-xs font-medium text-gray-500">客户</th>
          <th class="px-4 py-3 text-left text-xs font-medium text-gray-500">客户ID</th>
          <th class="px-4 py-3 text-left text-xs font-medium text-gray-500">门户状态</th>
          <th class="px-4 py-3 text-left text-xs font-medium text-gray-500">最后登录</th>
          <th class="px-4 py-3 text-left text-xs font-medium text-gray-500">操作</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-gray-100">
        <?php foreach ($customers as $c): ?>
        <tr class="hover:bg-gray-50">
          <td class="px-4 py-3 font-medium text-gray-800"><?= htmlspecialchars($c['name']) ?></td>
          <td class="px-4 py-3 text-gray-500 font-mono text-xs"><?= htmlspecialchars($c['customer_id']) ?></td>
          <td class="px-4 py-3">
            <?php if ($c['password_hash']): ?>
              <span class="inline-flex items-center gap-1 text-xs text-green-700 bg-green-100 px-2 py-0.5 rounded-full">
                <span class="w-1.5 h-1.5 bg-green-500 rounded-full"></span> 已开通
              </span>
            <?php else: ?>
              <span class="inline-flex items-center gap-1 text-xs text-gray-500 bg-gray-100 px-2 py-0.5 rounded-full">
                <span class="w-1.5 h-1.5 bg-gray-400 rounded-full"></span> 未开通
              </span>
            <?php endif; ?>
          </td>
          <td class="px-4 py-3 text-xs text-gray-400">
            <?= $c['last_login'] ? date('Y-m-d H:i', strtotime($c['last_login'])) : '—' ?>
          </td>
          <td class="px-4 py-3">
            <button onclick="openPasswordModal('<?= htmlspecialchars($c['customer_id']) ?>', '<?= htmlspecialchars($c['name']) ?>')" class="text-xs text-indigo-600 hover:text-indigo-800 mr-3">
              <?= $c['password_hash'] ? '修改密码' : '开通权限' ?>
            </button>
            <?php if ($c['password_hash']): ?>
              <form method="POST" class="inline" onsubmit="return confirm('确认撤销该客户的门户访问权限？')">
                <input type="hidden" name="action" value="revoke">
                <input type="hidden" name="customer_id" value="<?= htmlspecialchars($c['customer_id']) ?>">
                <button type="submit" class="text-xs text-red-500 hover:text-red-700">撤销</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Password Modal -->
<div id="passwordModal" class="hidden fixed inset-0 bg-black/40 flex items-center justify-center z-50">
  <div class="bg-white rounded-2xl shadow-xl p-6 w-96">
    <h3 class="font-semibold text-gray-900 mb-4">设置门户密码</h3>
    <form method="POST">
      <input type="hidden" name="action" value="set_password">
      <input type="hidden" name="customer_id" id="modalCid">
      <div class="mb-4">
        <label class="block text-sm font-medium text-gray-700 mb-1">客户：<span id="modalName" class="text-indigo-600"></span></label>
      </div>
      <div class="mb-4">
        <label class="block text-sm text-gray-600 mb-1.5">登录密码（至少6位）</label>
        <input type="text" name="password" id="modalPassword" required minlength="6"
          class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 outline-none"
          placeholder="设置客户的登录密码">
      </div>
      <div class="flex gap-2 justify-end">
        <button type="button" onclick="closeModal()" class="px-4 py-2 text-sm border border-gray-300 rounded-lg text-gray-600 hover:bg-gray-50">取消</button>
        <button type="submit" class="px-4 py-2 text-sm bg-indigo-600 text-white rounded-lg hover:bg-indigo-700">保存</button>
      </div>
    </form>
  </div>
</div>

<script>
function openPasswordModal(cid, name) {
  document.getElementById('modalCid').value = cid;
  document.getElementById('modalName').textContent = name;
  document.getElementById('modalPassword').value = '';
  document.getElementById('passwordModal').classList.remove('hidden');
}
function closeModal() {
  document.getElementById('passwordModal').classList.add('hidden');
}
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
