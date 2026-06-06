<?php
/**
 * 客户自助门户 - 登录页
 * 访问路径：/client/login.php 或 /client/
 */
session_start();

if (isset($_SESSION['client_id'])) {
    header('Location: dashboard.php');
    exit;
}

define('FEISHU_TREASURE', true);
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database_admin.php';

// Ensure client credentials table
try {
    $db->exec("CREATE TABLE IF NOT EXISTS geo_client_credentials (
        id SERIAL PRIMARY KEY,
        customer_id VARCHAR(100) UNIQUE,
        password_hash VARCHAR(255),
        last_login TIMESTAMP,
        created_at TIMESTAMP DEFAULT NOW()
    )");
} catch (Throwable $e) {}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $custId  = trim($_POST['customer_id'] ?? '');
    $pass    = $_POST['password'] ?? '';

    if ($custId && $pass) {
        $stmt = $db->prepare("SELECT gc.password_hash, c.name FROM geo_client_credentials gc JOIN customers c ON c.customer_id=gc.customer_id WHERE gc.customer_id=?");
        $stmt->execute([$custId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row && password_verify($pass, $row['password_hash'])) {
            $_SESSION['client_id']   = $custId;
            $_SESSION['client_name'] = $row['name'];
            $db->prepare("UPDATE geo_client_credentials SET last_login=NOW() WHERE customer_id=?")->execute([$custId]);
            header('Location: dashboard.php');
            exit;
        } else {
            $error = '客户ID或密码错误，请联系您的GEO顾问获取登录凭证';
        }
    } else {
        $error = '请填写客户ID和密码';
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>客户中心 - GEO数据看板</title>
<script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-gradient-to-br from-indigo-50 to-blue-50 flex items-center justify-center p-4">
  <div class="w-full max-w-md">
    <div class="text-center mb-8">
      <img src="/assets/images/lynden-brothers-logo.jpg" alt="Lynden Brothers GEO" class="mx-auto mb-4 h-20 w-auto max-w-72 object-contain">
      <h1 class="text-2xl font-bold text-gray-900">GEO数据看板</h1>
      <p class="text-gray-500 text-sm mt-1">登录查看您的品牌AI可见度报告</p>
    </div>

    <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-8">
      <?php if ($error): ?>
        <div class="mb-5 rounded-lg bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-700"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <form method="POST" class="space-y-4">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1.5">客户ID</label>
          <input type="text" name="customer_id" required value="<?= htmlspecialchars($_POST['customer_id'] ?? '') ?>"
            class="w-full rounded-xl border border-gray-300 px-4 py-2.5 text-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 outline-none"
            placeholder="请输入您的客户ID">
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1.5">密码</label>
          <input type="password" name="password" required
            class="w-full rounded-xl border border-gray-300 px-4 py-2.5 text-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 outline-none"
            placeholder="请输入登录密码">
        </div>
        <button type="submit" class="w-full bg-indigo-600 text-white rounded-xl py-2.5 text-sm font-medium hover:bg-indigo-700 transition-colors mt-2">
          登录
        </button>
      </form>

      <p class="text-center text-xs text-gray-400 mt-6">没有账号？联系您的GEO顾问获取登录凭证</p>
    </div>
  </div>
</body>
</html>
