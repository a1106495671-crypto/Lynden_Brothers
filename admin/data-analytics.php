<?php
/**
 * 数据分析总览
 */

define('FEISHU_TREASURE', true);
session_start();

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/database_admin.php';
require_once __DIR__ . '/../includes/distribution_service.php';

require_admin_login();
session_write_close();

ensure_distribution_schema($db);

$page_title = '数据分析总览';

function da_q(string $sql, array $bind = []): mixed {
    global $db;
    try {
        if ($bind) {
            $s = $db->prepare($sql);
            $s->execute($bind);
            return $s;
        }
        return $db->query($sql);
    } catch (Throwable $e) {
        return null;
    }
}
function da_val(string $sql, array $bind = [], mixed $default = 0): mixed {
    $s = da_q($sql, $bind);
    return $s ? ($s->fetchColumn() ?? $default) : $default;
}
function da_all(string $sql, array $bind = []): array {
    $s = da_q($sql, $bind);
    return $s ? ($s->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
}
function h(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

// ── 系统总览 ─────────────────────────────────────────────
$overview = [
    'articles_total'   => (int) da_val("SELECT COUNT(*) FROM articles WHERE deleted_at IS NULL"),
    'articles_7d'      => (int) da_val("SELECT COUNT(*) FROM articles WHERE created_at >= NOW()-INTERVAL '7 days' AND deleted_at IS NULL"),
    'articles_30d'     => (int) da_val("SELECT COUNT(*) FROM articles WHERE created_at >= NOW()-INTERVAL '30 days' AND deleted_at IS NULL"),
    'articles_ai'      => (int) da_val("SELECT COUNT(*) FROM articles WHERE is_ai_generated=1 AND deleted_at IS NULL"),
    'articles_pub'     => (int) da_val("SELECT COUNT(*) FROM articles WHERE status='published' AND deleted_at IS NULL"),
    'tasks_total'      => (int) da_val("SELECT COUNT(*) FROM tasks"),
    'tasks_running'    => (int) da_val("SELECT COUNT(*) FROM tasks WHERE status='running'"),
    'tasks_done'       => (int) da_val("SELECT COUNT(*) FROM tasks WHERE status='completed'"),
    'customers_total'  => (int) da_val("SELECT COUNT(*) FROM customers"),
    'customers_active' => (int) da_val("SELECT COUNT(*) FROM customers WHERE service_status='active'"),
    'dist_total'       => (int) da_val("SELECT COUNT(*) FROM media_publish_jobs"),
    'dist_success'     => (int) da_val("SELECT COUNT(*) FROM media_publish_jobs WHERE status='success'"),
    'dist_queued'      => (int) da_val("SELECT COUNT(*) FROM media_publish_jobs WHERE status='queued'"),
    'dist_failed'      => (int) da_val("SELECT COUNT(*) FROM media_publish_jobs WHERE status='failed'"),
    'channels_active'  => (int) da_val("SELECT COUNT(*) FROM media_accounts WHERE status='active'"),
    'ai_crawls_total'  => (int) da_val("SELECT COUNT(*) FROM ai_crawler_logs"),
    'ai_crawls_7d'     => (int) da_val("SELECT COUNT(*) FROM ai_crawler_logs WHERE created_at >= NOW()-INTERVAL '7 days'"),
    'monitor_kws'      => (int) da_val("SELECT COUNT(*) FROM geo_monitor_keywords"),
];

// ── 文章生产趋势（近 30 天）──────────────────────────────
$articleTrend = da_all("
    SELECT DATE(created_at) AS day, COUNT(*) AS cnt
    FROM articles
    WHERE created_at >= NOW()-INTERVAL '30 days' AND deleted_at IS NULL
    GROUP BY DATE(created_at) ORDER BY day
");
$trendLabels   = json_encode(array_column($articleTrend, 'day'));
$trendArticles = json_encode(array_map('intval', array_column($articleTrend, 'cnt')));

// ── AI 爬虫趋势（近 30 天）──────────────────────────────
$crawlerTrend = da_all("
    SELECT DATE(created_at) AS day, COUNT(*) AS cnt
    FROM ai_crawler_logs
    WHERE created_at >= NOW()-INTERVAL '30 days'
    GROUP BY DATE(created_at) ORDER BY day
");
$crawlerLabels = json_encode(array_column($crawlerTrend, 'day'));
$crawlerCounts = json_encode(array_map('intval', array_column($crawlerTrend, 'cnt')));

// ── 爬虫来源分布 ─────────────────────────────────────────
$crawlerBots = da_all("
    SELECT bot_name, bot_company, COUNT(*) AS cnt
    FROM ai_crawler_logs GROUP BY bot_name, bot_company ORDER BY cnt DESC LIMIT 8
");

// ── 分发渠道分布 ─────────────────────────────────────────
$distByPlatform = da_all("
    SELECT platform, COUNT(*) AS total,
           SUM(CASE WHEN status='success' THEN 1 ELSE 0 END) AS success_cnt
    FROM media_publish_jobs
    GROUP BY platform ORDER BY total DESC LIMIT 10
");

// ── 各客户文章数 ─────────────────────────────────────────
$customerArticles = da_all("
    SELECT c.name, c.customer_id,
           COUNT(a.id) AS article_cnt,
           SUM(CASE WHEN a.status='published' THEN 1 ELSE 0 END) AS pub_cnt,
           MAX(a.created_at) AS last_article
    FROM customers c
    LEFT JOIN articles a ON a.task_id IN (
        SELECT id FROM tasks WHERE customer_id = c.customer_id
    ) AND a.deleted_at IS NULL
    GROUP BY c.name, c.customer_id
    ORDER BY article_cnt DESC
");

// ── 任务状态分布 ─────────────────────────────────────────
$taskStatus = da_all("
    SELECT status, COUNT(*) AS cnt FROM tasks GROUP BY status ORDER BY cnt DESC
");

// ── Top 文章（浏览量）────────────────────────────────────
$topArticles = da_all("
    SELECT title, slug, view_count, status, created_at
    FROM articles WHERE deleted_at IS NULL
    ORDER BY view_count DESC LIMIT 10
");

// ── 最近生成记录 ─────────────────────────────────────────
$recentArticles = da_all("
    SELECT a.title, a.status, a.is_ai_generated, a.created_at,
           c.name AS customer_name
    FROM articles a
    LEFT JOIN tasks t ON a.task_id = t.id
    LEFT JOIN customers c ON t.customer_id = c.customer_id
    WHERE a.deleted_at IS NULL
    ORDER BY a.created_at DESC LIMIT 8
");

// Chart colors
$chartColors = ['#2563eb','#7c3aed','#059669','#d97706','#dc2626','#0891b2','#65a30d','#9333ea'];

require_once __DIR__ . '/includes/header.php';
?>

<div style="margin-bottom:24px;display:flex;align-items:center;justify-content:space-between">
    <div>
        <h1 style="margin:0;font-size:22px;font-weight:700">数据分析总览</h1>
        <p style="margin:4px 0 0;color:#6b7280;font-size:14px">内容 · 任务 · 分发 · AI爬虫 · 客户活跃度</p>
    </div>
    <div style="font-size:12px;color:#9ca3af">更新于 <?= date('Y-m-d H:i') ?></div>
</div>

<!-- ── 系统总览卡片 ───────────────────────────────────── -->
<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:28px">
    <?php
    $cards = [
        ['文章总数',   $overview['articles_total'],   '本周 +'.$overview['articles_7d'],      '#2563eb'],
        ['已发布文章', $overview['articles_pub'],      'AI生成 '.$overview['articles_ai'].'篇', '#059669'],
        ['分发成功',   $overview['dist_success'],      '待发布 '.$overview['dist_queued'],      '#7c3aed'],
        ['AI爬虫访问', $overview['ai_crawls_total'],   '7天 '.$overview['ai_crawls_7d'].'次',   '#d97706'],
        ['活跃客户',   $overview['customers_active'],  '共 '.$overview['customers_total'].'个',  '#0891b2'],
        ['任务总数',   $overview['tasks_total'],       '运行中 '.$overview['tasks_running'],    '#65a30d'],
        ['监测关键词', $overview['monitor_kws'],       '',                                       '#9333ea'],
        ['启用渠道',   $overview['channels_active'],   '分发失败 '.$overview['dist_failed'],    '#dc2626'],
    ];
    foreach ($cards as [$label, $val, $sub, $color]):
    ?>
    <div style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:18px 20px">
        <div style="font-size:12px;color:#6b7280;margin-bottom:4px"><?= h($label) ?></div>
        <div style="font-size:30px;font-weight:700;color:<?= $color ?>;line-height:1.1"><?= number_format($val) ?></div>
        <?php if ($sub): ?>
        <div style="font-size:11px;color:#9ca3af;margin-top:4px"><?= h($sub) ?></div>
        <?php endif ?>
    </div>
    <?php endforeach ?>
</div>

<!-- ── 趋势图（双图）────────────────────────────────────── -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:24px">

    <!-- 文章生产趋势 -->
    <div style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:22px">
        <h3 style="margin:0 0 16px;font-size:14px;font-weight:600;color:#374151">文章生产趋势（近 30 天）</h3>
        <?php if (empty($articleTrend)): ?>
        <div style="height:160px;display:flex;align-items:center;justify-content:center;color:#d1d5db;font-size:13px">
            暂无数据
        </div>
        <?php else: ?>
        <canvas id="articleTrendChart" height="120"></canvas>
        <?php endif ?>
    </div>

    <!-- AI 爬虫趋势 -->
    <div style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:22px">
        <h3 style="margin:0 0 16px;font-size:14px;font-weight:600;color:#374151">AI 爬虫访问趋势（近 30 天）</h3>
        <?php if (empty($crawlerTrend)): ?>
        <div style="height:160px;display:flex;align-items:center;justify-content:center;color:#d1d5db;font-size:13px">
            暂无爬虫访问记录
        </div>
        <?php else: ?>
        <canvas id="crawlerTrendChart" height="120"></canvas>
        <?php endif ?>
    </div>
</div>

<!-- ── 三列中间层 ────────────────────────────────────────── -->
<div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:20px;margin-bottom:24px">

    <!-- 爬虫来源分布 -->
    <div style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:22px">
        <h3 style="margin:0 0 16px;font-size:14px;font-weight:600;color:#374151">AI 爬虫来源</h3>
        <?php if (empty($crawlerBots)): ?>
        <p style="color:#d1d5db;font-size:13px;text-align:center;margin-top:30px">暂无数据</p>
        <?php else: ?>
        <?php $maxBot = max(array_column($crawlerBots,'cnt')); ?>
        <?php foreach ($crawlerBots as $i => $bot): ?>
        <div style="margin-bottom:10px">
            <div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:3px">
                <span style="font-weight:500"><?= h($bot['bot_name']) ?></span>
                <span style="color:#6b7280"><?= number_format((int)$bot['cnt']) ?></span>
            </div>
            <div style="height:6px;background:#f3f4f6;border-radius:3px;overflow:hidden">
                <div style="height:100%;width:<?= round((int)$bot['cnt']/$maxBot*100) ?>%;
                            background:<?= $chartColors[$i % count($chartColors)] ?>;border-radius:3px"></div>
            </div>
        </div>
        <?php endforeach ?>
        <?php endif ?>
    </div>

    <!-- 任务状态 -->
    <div style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:22px">
        <h3 style="margin:0 0 16px;font-size:14px;font-weight:600;color:#374151">任务状态分布</h3>
        <?php
        $statusMeta = [
            'running'   => ['执行中', '#2563eb'],
            'completed' => ['已完成', '#059669'],
            'paused'    => ['已暂停', '#d97706'],
            'failed'    => ['失败',   '#dc2626'],
            'pending'   => ['待启动', '#9ca3af'],
            'created'   => ['已创建', '#7c3aed'],
        ];
        if (empty($taskStatus)):
        ?>
        <p style="color:#d1d5db;font-size:13px;text-align:center;margin-top:30px">暂无任务</p>
        <?php else: ?>
        <?php $maxTask = max(array_column($taskStatus,'cnt')); ?>
        <?php foreach ($taskStatus as $i => $row): [$slabel,$scolor] = $statusMeta[$row['status']] ?? [$row['status'],'#9ca3af']; ?>
        <div style="margin-bottom:10px">
            <div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:3px">
                <span style="font-weight:500"><?= h($slabel) ?></span>
                <span style="color:#6b7280"><?= (int)$row['cnt'] ?></span>
            </div>
            <div style="height:6px;background:#f3f4f6;border-radius:3px;overflow:hidden">
                <div style="height:100%;width:<?= round((int)$row['cnt']/$maxTask*100) ?>%;
                            background:<?= $scolor ?>;border-radius:3px"></div>
            </div>
        </div>
        <?php endforeach ?>
        <?php endif ?>
    </div>

    <!-- 分发渠道分布 -->
    <div style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:22px">
        <h3 style="margin:0 0 16px;font-size:14px;font-weight:600;color:#374151">分发渠道分布</h3>
        <?php if (empty($distByPlatform)): ?>
        <p style="color:#d1d5db;font-size:13px;text-align:center;margin-top:30px">暂无分发记录</p>
        <?php else: ?>
        <?php $maxDist = max(array_column($distByPlatform,'total')); ?>
        <?php foreach ($distByPlatform as $i => $row): ?>
        <div style="margin-bottom:10px">
            <div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:3px">
                <span style="font-weight:500"><?= h($row['platform']) ?></span>
                <span style="color:#059669"><?= (int)$row['success_cnt'] ?>/<span style="color:#6b7280"><?= (int)$row['total'] ?></span></span>
            </div>
            <div style="height:6px;background:#f3f4f6;border-radius:3px;overflow:hidden">
                <div style="height:100%;background:#e5e7eb;border-radius:3px;position:relative">
                    <div style="position:absolute;left:0;top:0;height:100%;
                                width:<?= round((int)$row['success_cnt']/(int)$row['total']*100) ?>%;
                                background:#059669;border-radius:3px"></div>
                    <div style="position:absolute;left:0;top:0;height:100%;
                                width:<?= round((int)$row['total']/$maxDist*100) ?>%;
                                background:<?= $chartColors[$i%count($chartColors)] ?>33;border-radius:3px"></div>
                </div>
            </div>
        </div>
        <?php endforeach ?>
        <?php endif ?>
    </div>
</div>

<!-- ── 客户活跃度 ────────────────────────────────────────── -->
<div style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:22px;margin-bottom:24px">
    <h3 style="margin:0 0 16px;font-size:14px;font-weight:600;color:#374151">客户活跃度</h3>
    <?php if (empty($customerArticles)): ?>
    <p style="color:#d1d5db;font-size:13px">暂无客户数据</p>
    <?php else: ?>
    <div style="overflow-x:auto">
    <table style="width:100%;border-collapse:collapse;font-size:13px">
        <thead>
            <tr style="border-bottom:2px solid #f3f4f6;color:#6b7280">
                <th style="text-align:left;padding:8px 12px;font-weight:500">客户</th>
                <th style="text-align:right;padding:8px 12px;font-weight:500">文章总数</th>
                <th style="text-align:right;padding:8px 12px;font-weight:500">已发布</th>
                <th style="text-align:left;padding:8px 12px;font-weight:500">内容进度</th>
                <th style="text-align:left;padding:8px 12px;font-weight:500">最近生产</th>
                <th style="text-align:center;padding:8px 12px;font-weight:500">操作</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($customerArticles as $row):
            $pct = $row['article_cnt'] > 0 ? round($row['pub_cnt']/$row['article_cnt']*100) : 0;
        ?>
        <tr style="border-bottom:1px solid #f9fafb">
            <td style="padding:10px 12px;font-weight:600"><?= h($row['name']) ?></td>
            <td style="padding:10px 12px;text-align:right;font-size:16px;font-weight:700;color:#2563eb">
                <?= (int)$row['article_cnt'] ?>
            </td>
            <td style="padding:10px 12px;text-align:right;color:#059669;font-weight:600">
                <?= (int)$row['pub_cnt'] ?>
            </td>
            <td style="padding:10px 12px;min-width:140px">
                <div style="display:flex;align-items:center;gap:8px">
                    <div style="flex:1;height:6px;background:#f3f4f6;border-radius:3px;overflow:hidden">
                        <div style="height:100%;width:<?= $pct ?>%;background:#059669;border-radius:3px"></div>
                    </div>
                    <span style="font-size:11px;color:#6b7280;white-space:nowrap"><?= $pct ?>%</span>
                </div>
            </td>
            <td style="padding:10px 12px;color:#9ca3af;font-size:12px">
                <?= $row['last_article'] ? h(substr($row['last_article'],0,10)) : '—' ?>
            </td>
            <td style="padding:10px 12px;text-align:center">
                <a href="geo-content.php?customer=<?= h($row['customer_id']) ?>"
                   style="font-size:11px;color:#2563eb;text-decoration:none;
                          padding:3px 10px;border:1px solid #bfdbfe;border-radius:5px">
                    生成内容
                </a>
            </td>
        </tr>
        <?php endforeach ?>
        </tbody>
    </table>
    </div>
    <?php endif ?>
</div>

<!-- ── 底部两列：Top文章 + 最近生产 ─────────────────────── -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:24px">

    <!-- Top 文章 -->
    <div style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:22px">
        <h3 style="margin:0 0 16px;font-size:14px;font-weight:600;color:#374151">Top 文章（浏览量）</h3>
        <?php if (empty($topArticles)): ?>
        <p style="color:#d1d5db;font-size:13px">暂无数据</p>
        <?php else: ?>
        <?php foreach ($topArticles as $i => $art): ?>
        <div style="display:flex;align-items:flex-start;gap:10px;padding:8px 0;
                    border-bottom:1px solid #f9fafb">
            <span style="font-size:12px;color:#d1d5db;font-weight:700;min-width:18px"><?= $i+1 ?></span>
            <div style="flex:1;min-width:0">
                <div style="font-size:13px;font-weight:500;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                    <?php if ($art['slug']): ?>
                    <a href="../article/<?= h($art['slug']) ?>" target="_blank"
                       style="color:#374151;text-decoration:none"><?= h(mb_substr($art['title'],0,40)) ?></a>
                    <?php else: ?>
                    <?= h(mb_substr($art['title'],0,40)) ?>
                    <?php endif ?>
                </div>
                <div style="font-size:11px;color:#9ca3af;margin-top:2px">
                    <?= (int)$art['view_count'] ?> 次浏览
                </div>
            </div>
        </div>
        <?php endforeach ?>
        <?php endif ?>
    </div>

    <!-- 最近生产 -->
    <div style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:22px">
        <h3 style="margin:0 0 16px;font-size:14px;font-weight:600;color:#374151">最近生产记录</h3>
        <?php if (empty($recentArticles)): ?>
        <p style="color:#d1d5db;font-size:13px">暂无数据</p>
        <?php else: ?>
        <?php foreach ($recentArticles as $art): ?>
        <div style="padding:8px 0;border-bottom:1px solid #f9fafb">
            <div style="display:flex;align-items:center;justify-content:space-between;gap:8px">
                <span style="font-size:13px;font-weight:500;overflow:hidden;text-overflow:ellipsis;
                             white-space:nowrap;flex:1">
                    <?= h(mb_substr($art['title'],0,36)) ?>
                </span>
                <?php if ($art['is_ai_generated']): ?>
                <span style="background:#ede9fe;color:#7c3aed;padding:1px 7px;border-radius:3px;
                             font-size:10px;white-space:nowrap;flex-shrink:0">AI</span>
                <?php endif ?>
            </div>
            <div style="font-size:11px;color:#9ca3af;margin-top:2px">
                <?= h($art['customer_name'] ?? '—') ?> &middot; <?= h(substr($art['created_at'],0,10)) ?>
            </div>
        </div>
        <?php endforeach ?>
        <?php endif ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
<script>
(function() {
    var articleLabels = <?= $trendLabels ?>;
    var articleData   = <?= $trendArticles ?>;
    if (articleLabels.length && document.getElementById('articleTrendChart')) {
        new Chart(document.getElementById('articleTrendChart').getContext('2d'), {
            type: 'bar',
            data: {
                labels: articleLabels,
                datasets: [{
                    label: '文章数',
                    data: articleData,
                    backgroundColor: 'rgba(37,99,235,0.15)',
                    borderColor: '#2563eb',
                    borderWidth: 1.5,
                    borderRadius: 3,
                }]
            },
            options: {
                responsive: true,
                plugins: { legend: { display: false } },
                scales: {
                    y: { beginAtZero: true, ticks: { precision: 0 } },
                    x: { grid: { display: false }, ticks: { maxTicksLimit: 10 } }
                }
            }
        });
    }

    var crawlerLabels = <?= $crawlerLabels ?>;
    var crawlerData   = <?= $crawlerCounts ?>;
    if (crawlerLabels.length && document.getElementById('crawlerTrendChart')) {
        new Chart(document.getElementById('crawlerTrendChart').getContext('2d'), {
            type: 'line',
            data: {
                labels: crawlerLabels,
                datasets: [{
                    label: 'AI爬虫',
                    data: crawlerData,
                    borderColor: '#d97706',
                    backgroundColor: 'rgba(217,119,6,0.08)',
                    borderWidth: 2,
                    fill: true,
                    tension: 0.3,
                    pointRadius: 3,
                }]
            },
            options: {
                responsive: true,
                plugins: { legend: { display: false } },
                scales: {
                    y: { beginAtZero: true, ticks: { precision: 0 } },
                    x: { grid: { display: false }, ticks: { maxTicksLimit: 10 } }
                }
            }
        });
    }
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
