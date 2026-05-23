<?php
/**
 * 分发队列
 */

define('FEISHU_TREASURE', true);
session_start();

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/database_admin.php';
require_once __DIR__ . '/../includes/distribution_service.php';
require_once __DIR__ . '/../includes/distribution_publisher_service.php';

require_admin_login();
ensure_distribution_schema($db);

$msg   = '';
$error = '';

// ── POST 动作 ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'CSRF验证失败';
    } else {
        $action = $_POST['action'] ?? '';
        $jobId  = (int) ($_POST['job_id'] ?? 0);

        try {
            if ($action === 'retry' && $jobId > 0) {
                distribution_job_retry($db, $jobId);
                $msg = '已重置为待发布，可重新执行';

            } elseif ($action === 'run_job' && $jobId > 0) {
                $result = distribution_execute_publish_job($db, $jobId);
                if (($result['status'] ?? '') === 'success') {
                    $msg = '执行成功' . (trim($result['remote_url'] ?? '') ? '：' . $result['remote_url'] : '');
                } elseif (($result['status'] ?? '') === 'skipped') {
                    $msg = $result['error_message'] ?? '已跳过';
                } else {
                    $error = $result['error_message'] ?? '执行失败';
                }

            } elseif ($action === 'run_queued') {
                $count = distribution_start_queued_jobs_async($db, 10);
                $msg = "已在后台启动 {$count} 条待发布任务";

            } elseif ($action === 'mark_success' && $jobId > 0) {
                $remoteUrl = trim($_POST['remote_url'] ?? '');
                distribution_job_update($db, $jobId, 'success', $remoteUrl, '');
                $msg = '已标记为发布成功';

            } elseif ($action === 'mark_queued' && $jobId > 0) {
                distribution_job_update($db, $jobId, 'queued', '', '');
                $msg = '已重置为待发布';

            } elseif ($action === 'delete_job' && $jobId > 0) {
                $db->prepare("DELETE FROM media_publish_jobs WHERE id = ?")->execute([$jobId]);
                $msg = '已删除';

            } elseif ($action === 'enqueue') {
                $articleId  = (int) ($_POST['article_id'] ?? 0);
                $accountIds = $_POST['account_ids'] ?? [];
                if ($articleId > 0 && !empty($accountIds)) {
                    $created = distribution_enqueue_article($db, $articleId, array_map('intval', $accountIds));
                    $msg = "已加入队列 {$created} 条";
                } else {
                    $error = '请选择文章和至少一个渠道';
                }
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }

    // PRG
    $qs = http_build_query(array_filter([
        'status' => $_GET['status'] ?? '',
        'msg'    => $msg,
        'err'    => $error,
    ]));
    header('Location: distribution-queue.php' . ($qs ? '?' . $qs : ''));
    exit;
}

// ── 读取 flash ────────────────────────────────────────────
$msg   = $msg   ?: ($_GET['msg']  ?? '');
$error = $error ?: ($_GET['err']  ?? '');

// ── 过滤 & 分页 ───────────────────────────────────────────
$filterStatus = $_GET['status'] ?? '';
if (!in_array($filterStatus, ['queued','running','success','failed',''], true)) $filterStatus = '';
$page    = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 50;
$offset  = ($page - 1) * $perPage;

// ── 统计 ──────────────────────────────────────────────────
$stats = distribution_get_stats($db);
$total = distribution_queue_count($db, $filterStatus);
$jobs  = distribution_queue_get_jobs($db, $filterStatus, $perPage, $offset);
$pages = $total > 0 ? (int) ceil($total / $perPage) : 1;

// ── 加入队列面板数据 ──────────────────────────────────────
$publishedArticles = $db->query("
    SELECT id, title FROM articles WHERE status = 'published' ORDER BY updated_at DESC LIMIT 100
")->fetchAll(PDO::FETCH_ASSOC);
$activeAccounts = distribution_get_accounts($db, ['status' => 'active']);

$page_title = '分发队列';

function dq_h(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function dq_status_badge(string $status): string {
    $map = [
        'queued'  => ['待发布', '#d97706', '#fef3c7'],
        'running' => ['执行中', '#2563eb', '#dbeafe'],
        'success' => ['已发布', '#059669', '#d1fae5'],
        'failed'  => ['失败',   '#dc2626', '#fee2e2'],
    ];
    [$label, $color, $bg] = $map[$status] ?? ['未知', '#6b7280', '#f3f4f6'];
    return "<span style=\"background:{$bg};color:{$color};padding:2px 10px;border-radius:20px;font-size:11px;font-weight:600\">{$label}</span>";
}

require_once __DIR__ . '/includes/header.php';
?>

<div style="margin-bottom:24px;display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:12px">
    <div>
        <h1 style="margin:0;font-size:22px;font-weight:700">分发队列</h1>
        <p style="margin:4px 0 0;color:#6b7280;font-size:14px">追踪每篇文章到每个渠道的发布状态、重试和结果</p>
    </div>
    <div style="display:flex;gap:8px;align-items:center">
        <button onclick="document.getElementById('enqueue-panel').classList.toggle('hidden')"
                style="padding:8px 16px;background:#f3f4f6;border:1px solid #d1d5db;border-radius:7px;
                       font-size:13px;cursor:pointer;font-weight:500">
            + 加入队列
        </button>
        <?php if ($stats['queued'] > 0): ?>
        <form method="post" style="display:inline">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="run_queued">
            <button type="submit"
                    style="padding:8px 16px;background:#2563eb;color:#fff;border:none;border-radius:7px;
                           font-size:13px;cursor:pointer;font-weight:500">
                批量执行（<?= $stats['queued'] ?>条待发）
            </button>
        </form>
        <?php endif ?>
    </div>
</div>

<?php if ($msg): ?>
<div style="background:#d1fae5;color:#065f46;padding:12px 16px;border-radius:8px;margin-bottom:16px;font-size:14px"><?= dq_h($msg) ?></div>
<?php endif ?>
<?php if ($error): ?>
<div style="background:#fee2e2;color:#991b1b;padding:12px 16px;border-radius:8px;margin-bottom:16px;font-size:14px"><?= dq_h($error) ?></div>
<?php endif ?>

<!-- 加入队列面板 -->
<div id="enqueue-panel" class="hidden"
     style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:24px;margin-bottom:24px">
    <h3 style="margin:0 0 16px;font-size:15px;font-weight:600">将文章加入分发队列</h3>
    <form method="post">
        <?= csrf_input() ?>
        <input type="hidden" name="action" value="enqueue">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px">
            <div>
                <label style="display:block;font-size:13px;font-weight:500;margin-bottom:6px">选择文章</label>
                <select name="article_id" style="width:100%;padding:8px;border:1px solid #d1d5db;border-radius:6px;font-size:13px">
                    <option value="">-- 选择文章 --</option>
                    <?php foreach ($publishedArticles as $art): ?>
                    <option value="<?= (int)$art['id'] ?>"><?= dq_h(mb_substr($art['title'],0,50)) ?></option>
                    <?php endforeach ?>
                </select>
            </div>
            <div>
                <label style="display:block;font-size:13px;font-weight:500;margin-bottom:6px">
                    选择渠道（可多选）
                </label>
                <div style="max-height:160px;overflow-y:auto;border:1px solid #d1d5db;border-radius:6px;padding:8px">
                    <?php foreach ($activeAccounts as $acc): ?>
                    <label style="display:flex;align-items:center;gap:8px;padding:4px 0;font-size:13px;cursor:pointer">
                        <input type="checkbox" name="account_ids[]" value="<?= (int)$acc['id'] ?>">
                        <?= dq_h($acc['account_name']) ?>
                        <span style="color:#9ca3af;font-size:11px"><?= dq_h($acc['platform']) ?></span>
                    </label>
                    <?php endforeach ?>
                    <?php if (empty($activeAccounts)): ?>
                    <p style="color:#9ca3af;font-size:13px;margin:0">暂无启用渠道，请先在<a href="distribution.php">媒体分发</a>中添加。</p>
                    <?php endif ?>
                </div>
            </div>
        </div>
        <div style="margin-top:16px">
            <button type="submit"
                    style="padding:8px 20px;background:#2563eb;color:#fff;border:none;border-radius:7px;
                           font-size:13px;cursor:pointer;font-weight:500">
                加入队列
            </button>
            <button type="button" onclick="document.getElementById('enqueue-panel').classList.add('hidden')"
                    style="margin-left:8px;padding:8px 16px;background:#f3f4f6;border:1px solid #d1d5db;
                           border-radius:7px;font-size:13px;cursor:pointer">
                取消
            </button>
        </div>
    </form>
</div>

<!-- 状态统计卡片 -->
<div style="display:grid;grid-template-columns:repeat(5,1fr);gap:12px;margin-bottom:24px">
    <?php
    $statusCards = [
        ['label'=>'全部',   'value'=>$stats['queued']+$stats['success']+$stats['failed']+((int)$db->query("SELECT COUNT(*) FROM media_publish_jobs WHERE status='running'")->fetchColumn()), 'status'=>'', 'color'=>'#374151'],
        ['label'=>'待发布', 'value'=>$stats['queued'],  'status'=>'queued',  'color'=>'#d97706'],
        ['label'=>'执行中', 'value'=>(int)$db->query("SELECT COUNT(*) FROM media_publish_jobs WHERE status='running'")->fetchColumn(), 'status'=>'running', 'color'=>'#2563eb'],
        ['label'=>'已发布', 'value'=>$stats['success'], 'status'=>'success', 'color'=>'#059669'],
        ['label'=>'失败',   'value'=>$stats['failed'],  'status'=>'failed',  'color'=>'#dc2626'],
    ];
    foreach ($statusCards as $card):
        $isActive = $filterStatus === $card['status'];
    ?>
    <a href="?status=<?= dq_h($card['status']) ?>"
       style="background:<?= $isActive?'#1e3a8a':'#fff' ?>;border:1px solid <?= $isActive?'#2563eb':'#e5e7eb' ?>;
              border-radius:10px;padding:16px;text-decoration:none;display:block">
        <div style="font-size:11px;color:<?= $isActive?'#93c5fd':'#6b7280' ?>;margin-bottom:4px"><?= $card['label'] ?></div>
        <div style="font-size:26px;font-weight:700;color:<?= $isActive?'#fff':$card['color'] ?>"><?= number_format($card['value']) ?></div>
    </a>
    <?php endforeach ?>
</div>

<!-- 队列表格 -->
<div style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;overflow:hidden">
    <?php if (empty($jobs)): ?>
    <div style="padding:60px;text-align:center;color:#9ca3af">
        <p style="font-size:15px;margin:0 0 8px">暂无<?= $filterStatus ? '此状态的' : '' ?>队列记录</p>
        <p style="font-size:13px;margin:0">点击右上角"加入队列"将文章分配到渠道。</p>
    </div>
    <?php else: ?>
    <div style="overflow-x:auto">
    <table style="width:100%;border-collapse:collapse;font-size:13px">
        <thead>
            <tr style="border-bottom:2px solid #f3f4f6;background:#f9fafb">
                <th style="text-align:left;padding:12px 14px;color:#6b7280;font-weight:500">文章</th>
                <th style="text-align:left;padding:12px 14px;color:#6b7280;font-weight:500">渠道</th>
                <th style="text-align:center;padding:12px 14px;color:#6b7280;font-weight:500">状态</th>
                <th style="text-align:left;padding:12px 14px;color:#6b7280;font-weight:500">远程链接</th>
                <th style="text-align:center;padding:12px 14px;color:#6b7280;font-weight:500">重试</th>
                <th style="text-align:left;padding:12px 14px;color:#6b7280;font-weight:500">更新时间</th>
                <th style="text-align:center;padding:12px 14px;color:#6b7280;font-weight:500">操作</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($jobs as $job): ?>
        <tr style="border-bottom:1px solid #f3f4f6" id="job-<?= (int)$job['id'] ?>">

            <!-- 文章 -->
            <td style="padding:12px 14px;max-width:260px">
                <div style="font-weight:500;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                    <?php if ($job['article_slug']): ?>
                    <a href="../article/<?= dq_h($job['article_slug']) ?>" target="_blank"
                       style="color:#2563eb;text-decoration:none">
                        <?= dq_h(mb_substr($job['article_title'] ?: $job['title'] ?: '(无标题)', 0, 40)) ?>
                    </a>
                    <?php else: ?>
                    <?= dq_h(mb_substr($job['article_title'] ?: $job['title'] ?: '(无标题)', 0, 40)) ?>
                    <?php endif ?>
                </div>
                <div style="font-size:11px;color:#9ca3af;margin-top:2px">
                    #<?= (int)$job['article_id'] ?>
                </div>
            </td>

            <!-- 渠道 -->
            <td style="padding:12px 14px">
                <div style="font-weight:500"><?= dq_h($job['account_name'] ?: $job['platform']) ?></div>
                <div style="font-size:11px;color:#9ca3af"><?= dq_h($job['platform']) ?></div>
            </td>

            <!-- 状态 -->
            <td style="padding:12px 14px;text-align:center">
                <?= dq_status_badge($job['status']) ?>
                <?php if ($job['status'] === 'failed' && $job['error_message']): ?>
                <div style="font-size:11px;color:#dc2626;margin-top:4px;max-width:140px;
                            overflow:hidden;text-overflow:ellipsis;white-space:nowrap"
                     title="<?= dq_h($job['error_message']) ?>">
                    <?= dq_h(mb_substr($job['error_message'], 0, 40)) ?>
                </div>
                <?php endif ?>
            </td>

            <!-- 远程链接 -->
            <td style="padding:12px 14px;max-width:200px">
                <?php if ($job['remote_url']): ?>
                <a href="<?= dq_h($job['remote_url']) ?>" target="_blank"
                   style="color:#2563eb;font-size:12px;text-decoration:none;
                          overflow:hidden;text-overflow:ellipsis;white-space:nowrap;display:block"
                   title="<?= dq_h($job['remote_url']) ?>">
                    <?= dq_h(parse_url($job['remote_url'], PHP_URL_HOST) ?: $job['remote_url']) ?>&hellip;
                </a>
                <?php elseif ($job['status'] === 'success'): ?>
                <button onclick="showMarkSuccessModal(<?= (int)$job['id'] ?>)"
                        style="font-size:11px;color:#6b7280;background:none;border:none;cursor:pointer;padding:0">
                    填写链接
                </button>
                <?php else: ?>
                <span style="color:#d1d5db">—</span>
                <?php endif ?>
            </td>

            <!-- 重试次数 -->
            <td style="padding:12px 14px;text-align:center;color:<?= (int)$job['retry_count']>0?'#d97706':'#9ca3af' ?>">
                <?= (int)$job['retry_count'] ?>
            </td>

            <!-- 更新时间 -->
            <td style="padding:12px 14px;color:#9ca3af;font-size:12px;white-space:nowrap">
                <?= dq_h(substr($job['updated_at'], 0, 16)) ?>
            </td>

            <!-- 操作 -->
            <td style="padding:12px 14px;text-align:center">
                <div style="display:flex;gap:4px;justify-content:center;flex-wrap:wrap">
                    <?php if (in_array($job['status'], ['queued', 'failed'], true)): ?>
                    <form method="post" style="display:inline">
                        <?= csrf_input() ?>
                        <input type="hidden" name="action" value="run_job">
                        <input type="hidden" name="job_id" value="<?= (int)$job['id'] ?>">
                        <button type="submit"
                                style="padding:4px 10px;background:#2563eb;color:#fff;border:none;
                                       border-radius:5px;font-size:11px;cursor:pointer">
                            执行
                        </button>
                    </form>
                    <?php endif ?>

                    <?php if ($job['status'] === 'failed'): ?>
                    <form method="post" style="display:inline">
                        <?= csrf_input() ?>
                        <input type="hidden" name="action" value="retry">
                        <input type="hidden" name="job_id" value="<?= (int)$job['id'] ?>">
                        <button type="submit"
                                style="padding:4px 10px;background:#f59e0b;color:#fff;border:none;
                                       border-radius:5px;font-size:11px;cursor:pointer">
                            重试
                        </button>
                    </form>
                    <?php endif ?>

                    <?php if ($job['status'] !== 'success'): ?>
                    <form method="post" style="display:inline"
                          onsubmit="return confirm('标记为已发布成功？')">
                        <?= csrf_input() ?>
                        <input type="hidden" name="action" value="mark_success">
                        <input type="hidden" name="job_id" value="<?= (int)$job['id'] ?>">
                        <button type="submit"
                                style="padding:4px 10px;background:#059669;color:#fff;border:none;
                                       border-radius:5px;font-size:11px;cursor:pointer">
                            标记成功
                        </button>
                    </form>
                    <?php endif ?>

                    <form method="post" style="display:inline"
                          onsubmit="return confirm('确认删除这条队列记录？')">
                        <?= csrf_input() ?>
                        <input type="hidden" name="action" value="delete_job">
                        <input type="hidden" name="job_id" value="<?= (int)$job['id'] ?>">
                        <button type="submit"
                                style="padding:4px 10px;background:#fee2e2;color:#dc2626;border:none;
                                       border-radius:5px;font-size:11px;cursor:pointer">
                            删除
                        </button>
                    </form>
                </div>
            </td>
        </tr>
        <?php endforeach ?>
        </tbody>
    </table>
    </div>

    <!-- 分页 -->
    <?php if ($pages > 1): ?>
    <div style="padding:16px 20px;border-top:1px solid #f3f4f6;display:flex;gap:6px;justify-content:center">
        <?php for ($p = 1; $p <= $pages; $p++): ?>
        <a href="?status=<?= dq_h($filterStatus) ?>&page=<?= $p ?>"
           style="padding:5px 12px;border-radius:5px;font-size:13px;text-decoration:none;
                  background:<?= $p===$page?'#2563eb':'#f3f4f6' ?>;
                  color:<?= $p===$page?'#fff':'#374151' ?>">
            <?= $p ?>
        </a>
        <?php endfor ?>
    </div>
    <?php endif ?>

    <?php endif ?>
</div>

<!-- 标记成功+填写链接 Modal -->
<div id="mark-success-modal"
     style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:999;
            align-items:center;justify-content:center">
    <div style="background:#fff;border-radius:12px;padding:28px;width:440px;max-width:90vw">
        <h3 style="margin:0 0 16px;font-size:16px;font-weight:600">标记为发布成功</h3>
        <form method="post">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="mark_success">
            <input type="hidden" name="job_id" id="modal-job-id">
            <label style="display:block;font-size:13px;margin-bottom:6px;font-weight:500">
                远程链接（可选）
            </label>
            <input type="url" name="remote_url" id="modal-remote-url"
                   placeholder="https://..."
                   style="width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:6px;
                          font-size:13px;box-sizing:border-box">
            <div style="margin-top:16px;display:flex;gap:8px;justify-content:flex-end">
                <button type="button" onclick="hideMarkSuccessModal()"
                        style="padding:8px 16px;background:#f3f4f6;border:1px solid #d1d5db;
                               border-radius:7px;font-size:13px;cursor:pointer">
                    取消
                </button>
                <button type="submit"
                        style="padding:8px 16px;background:#059669;color:#fff;border:none;
                               border-radius:7px;font-size:13px;cursor:pointer;font-weight:500">
                    确认标记
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function showMarkSuccessModal(jobId) {
    document.getElementById('modal-job-id').value = jobId;
    document.getElementById('modal-remote-url').value = '';
    var m = document.getElementById('mark-success-modal');
    m.style.display = 'flex';
}
function hideMarkSuccessModal() {
    document.getElementById('mark-success-modal').style.display = 'none';
}
document.getElementById('mark-success-modal').addEventListener('click', function(e) {
    if (e.target === this) hideMarkSuccessModal();
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
