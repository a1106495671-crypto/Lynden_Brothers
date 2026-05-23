<?php
/**
 * 访问日志
 */

define('FEISHU_TREASURE', true);
session_start();

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/database_admin.php';

require_admin_login();
session_write_close();

$page_title = '访问日志';

function al_val(string $sql, array $bind = [], mixed $def = 0): mixed {
    global $db;
    try {
        $s = $bind ? $db->prepare($sql) : null;
        if ($s) { $s->execute($bind); return $s->fetchColumn() ?? $def; }
        return $db->query($sql)->fetchColumn() ?? $def;
    } catch (Throwable $e) { return $def; }
}
function al_all(string $sql, array $bind = []): array {
    global $db;
    try {
        if ($bind) { $s=$db->prepare($sql); $s->execute($bind); return $s->fetchAll(PDO::FETCH_ASSOC)?:[]; }
        return $db->query($sql)->fetchAll(PDO::FETCH_ASSOC)?:[];
    } catch (Throwable $e) { return []; }
}
function h(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

// ── 筛选 ──────────────────────────────────────────────────
$filterType = $_GET['type'] ?? '';   // all | human | bot | article
if (!in_array($filterType, ['','human','bot','article'], true)) $filterType = '';
$range = $_GET['range'] ?? '30';
if (!in_array($range, ['1','7','30','90'], true)) $range = '30';
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 60;
$offset  = ($page-1)*$perPage;

$dateWhere    = "created_at >= NOW() - INTERVAL '{$range} days'";
$typeWhere    = match($filterType) {
    'human'   => "is_bot = FALSE",
    'bot'     => "is_bot = TRUE",
    'article' => "page_type = 'article'",
    default   => "1=1",
};
$where = "WHERE {$dateWhere} AND {$typeWhere}";

// ── 总览数据 ──────────────────────────────────────────────
$totalAll    = (int) al_val("SELECT COUNT(*) FROM access_logs WHERE {$dateWhere}");
$totalHuman  = (int) al_val("SELECT COUNT(*) FROM access_logs WHERE {$dateWhere} AND is_bot=FALSE");
$totalBot    = (int) al_val("SELECT COUNT(*) FROM access_logs WHERE {$dateWhere} AND is_bot=TRUE");
$totalToday  = (int) al_val("SELECT COUNT(*) FROM access_logs WHERE created_at >= CURRENT_DATE");
$uniqueIPs   = (int) al_val("SELECT COUNT(DISTINCT ip_address) FROM access_logs WHERE {$dateWhere}");
$botRatio    = $totalAll > 0 ? round($totalBot / $totalAll * 100) : 0;

// ── 30天趋势（人 vs 机器人）──────────────────────────────
$trend = al_all("
    SELECT DATE(created_at) AS day,
           COUNT(*) AS total,
           SUM(CASE WHEN is_bot THEN 1 ELSE 0 END) AS bot_cnt,
           SUM(CASE WHEN NOT is_bot THEN 1 ELSE 0 END) AS human_cnt
    FROM access_logs
    WHERE created_at >= NOW() - INTERVAL '30 days'
    GROUP BY DATE(created_at) ORDER BY day
");
$trendLabels = json_encode(array_column($trend,'day'));
$trendHuman  = json_encode(array_map('intval', array_column($trend,'human_cnt')));
$trendBot    = json_encode(array_map('intval', array_column($trend,'bot_cnt')));

// ── Top 页面 ─────────────────────────────────────────────
$topPages = al_all("
    SELECT request_path,
           COUNT(*) AS visits,
           SUM(CASE WHEN is_bot THEN 1 ELSE 0 END) AS bot_visits,
           SUM(CASE WHEN NOT is_bot THEN 1 ELSE 0 END) AS human_visits
    FROM access_logs WHERE {$dateWhere}
    GROUP BY request_path ORDER BY visits DESC LIMIT 12
");

// ── 页面类型分布 ─────────────────────────────────────────
$pageTypes = al_all("
    SELECT page_type,
           COUNT(*) AS cnt,
           SUM(CASE WHEN is_bot THEN 1 ELSE 0 END) AS bot_cnt
    FROM access_logs WHERE {$dateWhere}
    GROUP BY page_type ORDER BY cnt DESC
");

// ── Top IP ────────────────────────────────────────────────
$topIPs = al_all("
    SELECT ip_address,
           COUNT(*) AS visits,
           MAX(is_bot::int)::bool AS is_bot,
           MAX(bot_name) AS bot_name
    FROM access_logs WHERE {$dateWhere}
    GROUP BY ip_address ORDER BY visits DESC LIMIT 10
");

// ── 明细列表 ─────────────────────────────────────────────
$total = (int) al_val("SELECT COUNT(*) FROM access_logs {$where}");
$logs  = al_all("
    SELECT id, request_path, page_type, article_slug,
           ip_address, is_bot, bot_name, bot_company, referer, created_at
    FROM access_logs {$where}
    ORDER BY created_at DESC
    LIMIT {$perPage} OFFSET {$offset}
");
$pages = $total > 0 ? (int)ceil($total/$perPage) : 1;

$pageTypeMeta = [
    'home'     => ['首页',   '#2563eb'],
    'article'  => ['文章',   '#7c3aed'],
    'category' => ['分类',   '#059669'],
    'archive'  => ['归档',   '#0891b2'],
    'search'   => ['搜索',   '#d97706'],
    'other'    => ['其他',   '#9ca3af'],
];

require_once __DIR__ . '/includes/header.php';
?>

<!-- 页头 -->
<div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:24px">
    <div>
        <h1 style="margin:0;font-size:22px;font-weight:700">访问日志</h1>
        <p style="margin:4px 0 0;color:#6b7280;font-size:14px">前台所有页面请求，区分人类访问与 AI 爬虫</p>
    </div>
    <!-- 时间范围 -->
    <div style="display:flex;gap:6px;align-items:center">
        <?php foreach (['1'=>'今天','7'=>'7天','30'=>'30天','90'=>'90天'] as $v=>$label): ?>
        <a href="?range=<?=$v?>&type=<?=h($filterType)?>"
           style="padding:6px 14px;border-radius:6px;font-size:13px;text-decoration:none;
                  background:<?=$range===$v?'#2563eb':'#f3f4f6'?>;
                  color:<?=$range===$v?'#fff':'#374151'?>">
            <?=$label?>
        </a>
        <?php endforeach ?>
    </div>
</div>

<!-- 总览卡片 -->
<div style="display:grid;grid-template-columns:repeat(5,1fr);gap:12px;margin-bottom:24px">
    <?php
    $cards = [
        ['总访问',   $totalAll,   '#374151', ''],
        ['今日访问', $totalToday, '#2563eb', ''],
        ['人类访问', $totalHuman, '#059669', round($totalAll?$totalHuman/$totalAll*100:0).'%'],
        ['AI爬虫',   $totalBot,   '#d97706', $botRatio.'%'],
        ['独立 IP',  $uniqueIPs,  '#7c3aed', ''],
    ];
    foreach ($cards as [$lbl,$val,$color,$sub]):
    ?>
    <div style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:18px 20px">
        <div style="font-size:12px;color:#6b7280;margin-bottom:4px"><?=h($lbl)?></div>
        <div style="font-size:28px;font-weight:700;color:<?=$color?>"><?=number_format($val)?></div>
        <?php if ($sub): ?>
        <div style="font-size:11px;color:#9ca3af;margin-top:2px"><?=h($sub)?></div>
        <?php endif ?>
    </div>
    <?php endforeach ?>
</div>

<!-- 趋势图 -->
<div style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:24px;margin-bottom:24px">
    <h3 style="margin:0 0 16px;font-size:14px;font-weight:600">近 30 天访问趋势
        <span style="font-weight:400;font-size:12px;color:#9ca3af;margin-left:12px">
            <span style="display:inline-block;width:10px;height:10px;background:#059669;border-radius:2px;margin-right:4px"></span>人类
            <span style="display:inline-block;width:10px;height:10px;background:#d97706;border-radius:2px;margin-left:10px;margin-right:4px"></span>AI爬虫
        </span>
    </h3>
    <?php if (empty($trend)): ?>
    <div style="height:120px;display:flex;align-items:center;justify-content:center;color:#d1d5db;font-size:13px">暂无数据</div>
    <?php else: ?>
    <canvas id="trendChart" height="80"></canvas>
    <?php endif ?>
</div>

<!-- 中间三列 -->
<div style="display:grid;grid-template-columns:2fr 1fr 1fr;gap:20px;margin-bottom:24px">

    <!-- Top 页面 -->
    <div style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:22px">
        <h3 style="margin:0 0 14px;font-size:14px;font-weight:600">Top 页面</h3>
        <?php if (empty($topPages)): ?>
        <p style="color:#d1d5db;font-size:13px">暂无数据</p>
        <?php else: $maxV = max(array_column($topPages,'visits')); ?>
        <?php foreach ($topPages as $row): ?>
        <div style="margin-bottom:10px">
            <div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:3px">
                <span style="font-family:monospace;overflow:hidden;text-overflow:ellipsis;
                             white-space:nowrap;max-width:200px" title="<?=h($row['request_path'])?>">
                    <?=h($row['request_path'])?>
                </span>
                <span style="color:#6b7280;white-space:nowrap;margin-left:8px">
                    <span style="color:#059669"><?=(int)$row['human_visits']?></span>
                    +<span style="color:#d97706"><?=(int)$row['bot_visits']?></span>
                </span>
            </div>
            <div style="height:5px;background:#f3f4f6;border-radius:3px;overflow:hidden;position:relative">
                <div style="position:absolute;left:0;top:0;height:100%;border-radius:3px;
                            width:<?=round((int)$row['human_visits']/(int)$row['visits']*((int)$row['visits']/$maxV)*100)?>%;
                            background:#059669"></div>
                <div style="position:absolute;left:<?=round((int)$row['human_visits']/(int)$row['visits']*((int)$row['visits']/$maxV)*100)?>%;top:0;
                            height:100%;border-radius:3px;
                            width:<?=round((int)$row['bot_visits']/$maxV*100)?>%;
                            background:#d97706"></div>
            </div>
        </div>
        <?php endforeach ?>
        <?php endif ?>
    </div>

    <!-- 页面类型 -->
    <div style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:22px">
        <h3 style="margin:0 0 14px;font-size:14px;font-weight:600">页面类型</h3>
        <?php if (empty($pageTypes)): ?>
        <p style="color:#d1d5db;font-size:13px">暂无数据</p>
        <?php else: $maxPT = max(array_column($pageTypes,'cnt')); ?>
        <?php foreach ($pageTypes as $pt):
            [$ptLabel,$ptColor] = $pageTypeMeta[$pt['page_type']] ?? [$pt['page_type'],'#9ca3af'];
        ?>
        <div style="margin-bottom:12px">
            <div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:3px">
                <span style="font-weight:500;color:<?=$ptColor?>"><?=h($ptLabel)?></span>
                <span style="color:#6b7280"><?=number_format((int)$pt['cnt'])?></span>
            </div>
            <div style="height:5px;background:#f3f4f6;border-radius:3px;overflow:hidden">
                <div style="height:100%;width:<?=round((int)$pt['cnt']/$maxPT*100)?>%;
                            background:<?=$ptColor?>;border-radius:3px"></div>
            </div>
        </div>
        <?php endforeach ?>
        <?php endif ?>
    </div>

    <!-- Top IP -->
    <div style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:22px">
        <h3 style="margin:0 0 14px;font-size:14px;font-weight:600">Top IP</h3>
        <?php if (empty($topIPs)): ?>
        <p style="color:#d1d5db;font-size:13px">暂无数据</p>
        <?php else: ?>
        <?php foreach ($topIPs as $row): ?>
        <div style="display:flex;align-items:center;justify-content:space-between;
                    padding:6px 0;border-bottom:1px solid #f9fafb;font-size:12px">
            <div>
                <span style="font-family:monospace"><?=h($row['ip_address'])?></span>
                <?php if ($row['is_bot']): ?>
                <span style="background:#fef3c7;color:#92400e;padding:1px 5px;border-radius:3px;
                             font-size:10px;margin-left:4px"><?=h($row['bot_name'])?></span>
                <?php endif ?>
            </div>
            <span style="color:#6b7280;font-weight:600"><?=(int)$row['visits']?></span>
        </div>
        <?php endforeach ?>
        <?php endif ?>
    </div>
</div>

<!-- 明细日志 -->
<div style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;overflow:hidden">
    <!-- 筛选 tab -->
    <div style="display:flex;gap:0;border-bottom:1px solid #f3f4f6">
        <?php foreach ([''=>'全部','human'=>'人类访问','bot'=>'AI爬虫','article'=>'文章页'] as $v=>$label): ?>
        <a href="?range=<?=h($range)?>&type=<?=h($v)?>"
           style="padding:12px 20px;font-size:13px;text-decoration:none;border-bottom:2px solid <?=$filterType===$v?'#2563eb':'transparent'?>;
                  color:<?=$filterType===$v?'#2563eb':'#6b7280'?>;font-weight:<?=$filterType===$v?'600':'400'?>">
            <?=h($label)?>
        </a>
        <?php endforeach ?>
        <div style="margin-left:auto;padding:10px 16px;font-size:12px;color:#9ca3af;align-self:center">
            共 <?=number_format($total)?> 条
        </div>
    </div>

    <?php if (empty($logs)): ?>
    <div style="padding:60px;text-align:center;color:#9ca3af;font-size:14px">暂无访问记录</div>
    <?php else: ?>
    <div style="overflow-x:auto">
    <table style="width:100%;border-collapse:collapse;font-size:12px">
        <thead>
            <tr style="background:#f9fafb;color:#6b7280">
                <th style="text-align:left;padding:10px 14px;font-weight:500">时间</th>
                <th style="text-align:left;padding:10px 14px;font-weight:500">类型</th>
                <th style="text-align:left;padding:10px 14px;font-weight:500">访问路径</th>
                <th style="text-align:left;padding:10px 14px;font-weight:500">来源</th>
                <th style="text-align:left;padding:10px 14px;font-weight:500">IP</th>
                <th style="text-align:left;padding:10px 14px;font-weight:500">Referer</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($logs as $log):
            [$ptLabel,$ptColor] = $pageTypeMeta[$log['page_type']] ?? [$log['page_type'],'#9ca3af'];
        ?>
        <tr style="border-bottom:1px solid #f9fafb">
            <td style="padding:9px 14px;color:#9ca3af;white-space:nowrap">
                <?=h(substr($log['created_at'],5,11))?>
            </td>
            <td style="padding:9px 14px">
                <span style="background:<?=$ptColor?>18;color:<?=$ptColor?>;
                             padding:2px 7px;border-radius:4px;font-size:11px;font-weight:500">
                    <?=h($ptLabel)?>
                </span>
            </td>
            <td style="padding:9px 14px;font-family:monospace;max-width:260px;
                       overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                <?=h($log['request_path'])?>
            </td>
            <td style="padding:9px 14px">
                <?php if ($log['is_bot']): ?>
                <span style="background:#fef3c7;color:#92400e;padding:2px 8px;
                             border-radius:4px;font-size:11px;font-weight:600">
                    <?=h($log['bot_name']?:$log['bot_company'])?>
                </span>
                <?php else: ?>
                <span style="color:#9ca3af;font-size:11px">人类</span>
                <?php endif ?>
            </td>
            <td style="padding:9px 14px;font-family:monospace;color:#6b7280">
                <?=h($log['ip_address'])?>
            </td>
            <td style="padding:9px 14px;max-width:180px;overflow:hidden;
                       text-overflow:ellipsis;white-space:nowrap;color:#9ca3af">
                <?php if ($log['referer']): ?>
                <span title="<?=h($log['referer'])?>"><?=h(parse_url($log['referer'],PHP_URL_HOST)?:$log['referer'])?></span>
                <?php else: ?>—<?php endif ?>
            </td>
        </tr>
        <?php endforeach ?>
        </tbody>
    </table>
    </div>

    <!-- 分页 -->
    <?php if ($pages > 1): ?>
    <div style="padding:14px 20px;border-top:1px solid #f3f4f6;display:flex;gap:5px;justify-content:center;flex-wrap:wrap">
        <?php for ($p=1;$p<=$pages;$p++): ?>
        <a href="?range=<?=h($range)?>&type=<?=h($filterType)?>&page=<?=$p?>"
           style="padding:4px 11px;border-radius:5px;font-size:12px;text-decoration:none;
                  background:<?=$p===$page?'#2563eb':'#f3f4f6'?>;
                  color:<?=$p===$page?'#fff':'#374151'?>">
            <?=$p?>
        </a>
        <?php endfor ?>
    </div>
    <?php endif ?>
    <?php endif ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
<script>
(function(){
    var labels = <?=$trendLabels?>;
    var human  = <?=$trendHuman?>;
    var bot    = <?=$trendBot?>;
    if (!labels.length) return;
    new Chart(document.getElementById('trendChart').getContext('2d'), {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [
                {
                    label: '人类访问',
                    data: human,
                    backgroundColor: 'rgba(5,150,105,0.25)',
                    borderColor: '#059669',
                    borderWidth: 1.5,
                    borderRadius: 3,
                    stack: 'a',
                },
                {
                    label: 'AI爬虫',
                    data: bot,
                    backgroundColor: 'rgba(217,119,6,0.3)',
                    borderColor: '#d97706',
                    borderWidth: 1.5,
                    borderRadius: 3,
                    stack: 'a',
                }
            ]
        },
        options: {
            responsive: true,
            plugins: { legend: { display: false } },
            scales: {
                y: { beginAtZero: true, stacked: true, ticks: { precision: 0 } },
                x: { stacked: true, grid: { display: false }, ticks: { maxTicksLimit: 12 } }
            }
        }
    });
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
