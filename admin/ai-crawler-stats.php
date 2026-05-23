<?php
/**
 * AI 爬虫识别统计
 */

define('FEISHU_TREASURE', true);
session_start();

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/database_admin.php';

require_admin_login();
session_write_close();

$page_title = 'AI 爬虫识别';

// ── 时间范围 ──────────────────────────────────────────────
$range = $_GET['range'] ?? '30';
$range = in_array($range, ['7', '30', '90', 'all'], true) ? $range : '30';
$whereDate = $range === 'all' ? '' : "WHERE created_at >= NOW() - INTERVAL '{$range} days'";
$whereDateAnd = $range === 'all' ? '' : "AND created_at >= NOW() - INTERVAL '{$range} days'";

// ── 汇总数据 ──────────────────────────────────────────────
$totalVisits  = (int) $db->query("SELECT COUNT(*) FROM ai_crawler_logs $whereDate")->fetchColumn();
$uniqueBots   = (int) $db->query("SELECT COUNT(DISTINCT bot_name) FROM ai_crawler_logs $whereDate")->fetchColumn();
$uniquePages  = (int) $db->query("SELECT COUNT(DISTINCT request_path) FROM ai_crawler_logs $whereDate")->fetchColumn();
$todayVisits  = (int) $db->query("SELECT COUNT(*) FROM ai_crawler_logs WHERE created_at >= CURRENT_DATE")->fetchColumn();

// ── 各爬虫访问量 ──────────────────────────────────────────
$botStats = $db->query("
    SELECT bot_name, bot_company, COUNT(*) AS visit_count,
           COUNT(DISTINCT request_path) AS page_count,
           MAX(created_at) AS last_seen
    FROM ai_crawler_logs
    $whereDate
    GROUP BY bot_name, bot_company
    ORDER BY visit_count DESC
    LIMIT 20
")->fetchAll(PDO::FETCH_ASSOC);

// ── 最多被爬的文章 ────────────────────────────────────────
$topArticles = $db->query("
    SELECT article_slug, request_path, COUNT(*) AS visit_count,
           COUNT(DISTINCT bot_name) AS bot_count,
           MAX(created_at) AS last_seen
    FROM ai_crawler_logs
    WHERE article_slug != '' $whereDateAnd
    GROUP BY article_slug, request_path
    ORDER BY visit_count DESC
    LIMIT 15
")->fetchAll(PDO::FETCH_ASSOC);

// ── 最近爬取记录 ──────────────────────────────────────────
$recentLogs = $db->query("
    SELECT bot_name, bot_company, request_path, ip_address, created_at
    FROM ai_crawler_logs
    ORDER BY created_at DESC
    LIMIT 50
")->fetchAll(PDO::FETCH_ASSOC);

// ── 每日趋势（最近 30 天）────────────────────────────────
$dailyTrend = $db->query("
    SELECT DATE(created_at) AS day, COUNT(*) AS cnt
    FROM ai_crawler_logs
    WHERE created_at >= NOW() - INTERVAL '30 days'
    GROUP BY DATE(created_at)
    ORDER BY day
")->fetchAll(PDO::FETCH_ASSOC);

$trendLabels = json_encode(array_column($dailyTrend, 'day'));
$trendData   = json_encode(array_map('intval', array_column($dailyTrend, 'cnt')));

// 爬虫公司颜色映射
$companyColors = [
    'OpenAI'       => '#10a37f',
    'Anthropic'    => '#d97706',
    'Google'       => '#4285f4',
    'Perplexity'   => '#7c3aed',
    'ByteDance'    => '#e11d48',
    'Microsoft'    => '#0078d4',
    'Meta'         => '#1877f2',
    'Apple'        => '#555',
    'Cohere'       => '#39a',
    'You.com'      => '#ff6b00',
    'Diffbot'      => '#888',
    'Common Crawl' => '#aaa',
    'Semrush'      => '#f60',
    'DuckDuckGo'   => '#de5833',
];

function crawler_color(string $company, array $map): string {
    return $map[$company] ?? '#6b7280';
}

function h(mixed $v): string {
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="page-header" style="margin-bottom:24px">
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px">
        <div>
            <h1 style="margin:0;font-size:22px;font-weight:700">AI 爬虫识别</h1>
            <p style="margin:4px 0 0;color:#6b7280;font-size:14px">识别 GPT、Claude、Perplexity 等 AI 平台对本站的抓取行为</p>
        </div>
        <div style="display:flex;gap:8px">
            <?php foreach (['7'=>'7天','30'=>'30天','90'=>'90天','all'=>'全部'] as $v=>$label): ?>
                <a href="?range=<?= $v ?>"
                   style="padding:6px 14px;border-radius:6px;font-size:13px;text-decoration:none;
                          background:<?= $range===$v?'#2563eb':'#f3f4f6' ?>;
                          color:<?= $range===$v?'#fff':'#374151' ?>">
                    <?= $label ?>
                </a>
            <?php endforeach ?>
        </div>
    </div>
</div>

<!-- 汇总卡片 -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:16px;margin-bottom:28px">
    <?php
    $cards = [
        ['label'=>'AI 访问总次数', 'value'=>number_format($totalVisits), 'color'=>'#2563eb', 'icon'=>'bot'],
        ['label'=>'今日访问',      'value'=>number_format($todayVisits),  'color'=>'#059669', 'icon'=>'calendar'],
        ['label'=>'识别爬虫种类',  'value'=>$uniqueBots,                  'color'=>'#7c3aed', 'icon'=>'cpu'],
        ['label'=>'被爬页面数',    'value'=>number_format($uniquePages),  'color'=>'#d97706', 'icon'=>'file-text'],
    ];
    foreach ($cards as $card):
    ?>
    <div style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:20px">
        <div style="font-size:12px;color:#6b7280;margin-bottom:6px"><?= $card['label'] ?></div>
        <div style="font-size:28px;font-weight:700;color:<?= $card['color'] ?>"><?= $card['value'] ?></div>
    </div>
    <?php endforeach ?>
</div>

<!-- 趋势图 -->
<div style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:24px;margin-bottom:24px">
    <h3 style="margin:0 0 16px;font-size:15px;font-weight:600">近 30 天访问趋势</h3>
    <canvas id="trendChart" height="80"></canvas>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:24px">

    <!-- 各爬虫统计 -->
    <div style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:24px">
        <h3 style="margin:0 0 16px;font-size:15px;font-weight:600">爬虫访问排行</h3>
        <?php if (empty($botStats)): ?>
            <p style="color:#9ca3af;font-size:14px">暂无数据。等待 AI 爬虫访问前台页面后自动记录。</p>
        <?php else: ?>
        <table style="width:100%;border-collapse:collapse;font-size:13px">
            <thead>
                <tr style="border-bottom:2px solid #f3f4f6;color:#6b7280">
                    <th style="text-align:left;padding:6px 8px">爬虫</th>
                    <th style="text-align:left;padding:6px 8px">公司</th>
                    <th style="text-align:right;padding:6px 8px">次数</th>
                    <th style="text-align:right;padding:6px 8px">页面数</th>
                    <th style="text-align:left;padding:6px 8px">最近</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($botStats as $row): $color = crawler_color($row['bot_company'], $companyColors); ?>
                <tr style="border-bottom:1px solid #f9fafb">
                    <td style="padding:8px 8px">
                        <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:<?= $color ?>;margin-right:6px"></span>
                        <strong><?= h($row['bot_name']) ?></strong>
                    </td>
                    <td style="padding:8px 8px;color:#6b7280"><?= h($row['bot_company']) ?></td>
                    <td style="padding:8px 8px;text-align:right;font-weight:600;color:<?= $color ?>"><?= number_format((int)$row['visit_count']) ?></td>
                    <td style="padding:8px 8px;text-align:right;color:#6b7280"><?= number_format((int)$row['page_count']) ?></td>
                    <td style="padding:8px 8px;color:#9ca3af;font-size:12px"><?= h(substr($row['last_seen'],0,16)) ?></td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
        <?php endif ?>
    </div>

    <!-- 高热文章 -->
    <div style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:24px">
        <h3 style="margin:0 0 16px;font-size:15px;font-weight:600">被 AI 最多抓取的文章</h3>
        <?php if (empty($topArticles)): ?>
            <p style="color:#9ca3af;font-size:14px">暂无文章级数据（需要 AI 爬虫访问 /article/slug 路径）。</p>
        <?php else: ?>
        <table style="width:100%;border-collapse:collapse;font-size:13px">
            <thead>
                <tr style="border-bottom:2px solid #f3f4f6;color:#6b7280">
                    <th style="text-align:left;padding:6px 8px">文章</th>
                    <th style="text-align:right;padding:6px 8px">访问</th>
                    <th style="text-align:right;padding:6px 8px">爬虫数</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($topArticles as $row): ?>
                <tr style="border-bottom:1px solid #f9fafb">
                    <td style="padding:8px 8px">
                        <a href="<?= h($row['request_path']) ?>" target="_blank"
                           style="color:#2563eb;text-decoration:none;font-size:12px">
                            <?= h($row['article_slug']) ?>
                        </a>
                    </td>
                    <td style="padding:8px 8px;text-align:right;font-weight:600"><?= (int)$row['visit_count'] ?></td>
                    <td style="padding:8px 8px;text-align:right;color:#7c3aed"><?= (int)$row['bot_count'] ?></td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
        <?php endif ?>
    </div>
</div>

<!-- 最近记录 -->
<div style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:24px;margin-bottom:24px">
    <h3 style="margin:0 0 16px;font-size:15px;font-weight:600">最近爬取记录</h3>
    <?php if (empty($recentLogs)): ?>
        <div style="text-align:center;padding:40px;color:#9ca3af">
            <p style="font-size:15px;margin:0 0 8px">暂无爬虫访问记录</p>
            <p style="font-size:13px;margin:0">当 GPTBot、ClaudeBot 等 AI 爬虫访问前台页面时，记录将自动出现在这里。</p>
        </div>
    <?php else: ?>
    <div style="overflow-x:auto">
    <table style="width:100%;border-collapse:collapse;font-size:13px">
        <thead>
            <tr style="border-bottom:2px solid #f3f4f6;color:#6b7280">
                <th style="text-align:left;padding:8px 10px">爬虫</th>
                <th style="text-align:left;padding:8px 10px">公司</th>
                <th style="text-align:left;padding:8px 10px">访问路径</th>
                <th style="text-align:left;padding:8px 10px">IP</th>
                <th style="text-align:left;padding:8px 10px">时间</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($recentLogs as $row): $color = crawler_color($row['bot_company'], $companyColors); ?>
            <tr style="border-bottom:1px solid #f9fafb">
                <td style="padding:8px 10px">
                    <span style="background:<?= $color ?>20;color:<?= $color ?>;
                                 padding:2px 8px;border-radius:4px;font-size:11px;font-weight:600">
                        <?= h($row['bot_name']) ?>
                    </span>
                </td>
                <td style="padding:8px 10px;color:#6b7280"><?= h($row['bot_company']) ?></td>
                <td style="padding:8px 10px;font-family:monospace;font-size:12px;
                           max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                    <?= h($row['request_path']) ?>
                </td>
                <td style="padding:8px 10px;color:#9ca3af;font-size:12px"><?= h($row['ip_address']) ?></td>
                <td style="padding:8px 10px;color:#9ca3af;font-size:12px;white-space:nowrap">
                    <?= h(substr($row['created_at'],0,16)) ?>
                </td>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table>
    </div>
    <?php endif ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
<script>
(function() {
    var labels = <?= $trendLabels ?>;
    var data   = <?= $trendData ?>;
    if (!labels.length) return;
    var ctx = document.getElementById('trendChart').getContext('2d');
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: 'AI 爬虫访问次数',
                data: data,
                backgroundColor: 'rgba(37,99,235,0.15)',
                borderColor: '#2563eb',
                borderWidth: 1.5,
                borderRadius: 4,
            }]
        },
        options: {
            responsive: true,
            plugins: { legend: { display: false } },
            scales: {
                y: { beginAtZero: true, ticks: { precision: 0 } },
                x: { grid: { display: false } }
            }
        }
    });
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
