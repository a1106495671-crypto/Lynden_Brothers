<?php
/**
 * 客户月度报告 - 可打印/导出PDF
 * 路径：/client/report-export.php
 */
session_start();
if (!isset($_SESSION['client_id'])) {
    header('Location: login.php');
    exit;
}

define('FEISHU_TREASURE', true);
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database_admin.php';

$cid  = $_SESSION['client_id'];
$name = $_SESSION['client_name'] ?? $cid;

// Month selector (default: last full month)
$year  = (int)($_GET['year']  ?? date('Y', strtotime('first day of last month')));
$month = (int)($_GET['month'] ?? date('n', strtotime('first day of last month')));
if ($month < 1) { $month = 12; $year--; }
if ($month > 12) { $month = 1; $year++; }
$monthStart = sprintf('%04d-%02d-01', $year, $month);
$monthEnd   = date('Y-m-t', strtotime($monthStart));
$monthLabel = $year . '年' . $month . '月';

// GEO score (latest in month)
$geoScore = null; $diagDate = null;
try {
    $stmt = $db->prepare("SELECT overall_score, created_at FROM geo_diagnoses WHERE customer_id=? AND created_at BETWEEN ? AND ? ORDER BY created_at DESC LIMIT 1");
    $stmt->execute([$cid, $monthStart, $monthEnd . ' 23:59:59']);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) { $geoScore = (int)$row['overall_score']; $diagDate = $row['created_at']; }
} catch (Throwable $e) {}

// Platform mention rates
$platforms = [];
try {
    $stmt = $db->prepare("SELECT provider_key, ROUND(100.0*SUM(CASE WHEN brand_mentioned THEN 1 ELSE 0 END)/NULLIF(COUNT(*),0),1) as rate, COUNT(*) as total FROM geo_monitor_records WHERE customer_id=? AND queried_at BETWEEN ? AND ? GROUP BY provider_key ORDER BY rate DESC");
    $stmt->execute([$cid, $monthStart, $monthEnd . ' 23:59:59']);
    $platforms = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

$platformNames = ['kimi'=>'Kimi','deepseek'=>'DeepSeek','tongyi'=>'通义千问','wenxin'=>'文心一言','doubao'=>'豆包','yuanbao'=>'腾讯元宝'];

$avgRate = 0;
$platMap = [];
if ($platforms) {
    $avgRate = round(array_sum(array_column($platforms, 'rate')) / count($platforms), 1);
    foreach ($platforms as $p) $platMap[$p['provider_key']] = $p;
}

// Top keywords
$topKeywords = [];
try {
    $stmt = $db->prepare("SELECT query_text, ROUND(100.0*SUM(CASE WHEN brand_mentioned THEN 1 ELSE 0 END)/NULLIF(COUNT(*),0),1) as rate, COUNT(*) as total FROM geo_monitor_records WHERE customer_id=? AND queried_at BETWEEN ? AND ? GROUP BY query_text ORDER BY rate DESC LIMIT 10");
    $stmt->execute([$cid, $monthStart, $monthEnd . ' 23:59:59']);
    $topKeywords = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

// Published articles
$articles = [];
try {
    $stmt = $db->prepare("SELECT title, platform, status, remote_url, created_at FROM articles WHERE customer_id=? AND created_at BETWEEN ? AND ? ORDER BY created_at DESC");
    $stmt->execute([$cid, $monthStart, $monthEnd . ' 23:59:59']);
    $articles = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

$publishedCount = count(array_filter($articles, fn($a) => ($a['status'] ?? '') === 'published'));

// Signal scores
$signals = [];
try {
    $stmt = $db->prepare("SELECT d.name, s.score FROM geo_diagnosis_signal_scores s JOIN geo_diagnosis_signal_definitions d ON d.signal_key=s.signal_key WHERE s.diagnosis_id=(SELECT id FROM geo_diagnoses WHERE customer_id=? AND created_at BETWEEN ? AND ? ORDER BY created_at DESC LIMIT 1) ORDER BY d.sort_order");
    $stmt->execute([$cid, $monthStart, $monthEnd . ' 23:59:59']);
    $signals = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

// Alert summary
$alertSummary = ['high'=>0,'medium'=>0,'low'=>0];
try {
    $stmt = $db->prepare("SELECT level, COUNT(*) as cnt FROM geo_monitor_alerts WHERE customer_id=? AND alerted_at BETWEEN ? AND ? GROUP BY level");
    $stmt->execute([$cid, $monthStart, $monthEnd . ' 23:59:59']);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $alertSummary[$r['level']] = (int)$r['cnt'];
} catch (Throwable $e) {}

function score_grade(int $s): array {
    if ($s >= 80) return ['A', '#16a34a', '优秀'];
    if ($s >= 65) return ['B', '#2563eb', '良好'];
    if ($s >= 50) return ['C', '#d97706', '待提升'];
    return ['D', '#dc2626', '需重点优化'];
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($name) ?> - <?= $monthLabel ?>GEO月度报告</title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: -apple-system, "PingFang SC", "Microsoft YaHei", sans-serif; background: #f8fafc; color: #1e293b; }
  .page { max-width: 800px; margin: 0 auto; background: white; }

  /* Print / PDF styles */
  @media print {
    body { background: white; }
    .no-print { display: none !important; }
    .page { box-shadow: none; margin: 0; max-width: 100%; }
    .section { break-inside: avoid; }
  }

  .cover { background: linear-gradient(135deg, #4f46e5 0%, #2563eb 100%); color: white; padding: 60px 48px 48px; }
  .cover-logo { margin-bottom: 48px; }
  .cover-logo img { height: 52px; width: auto; max-width: 280px; object-fit: contain; background: white; border-radius: 6px; padding: 6px 10px; }
  .cover-title { font-size: 32px; font-weight: 700; margin-bottom: 8px; }
  .cover-sub { font-size: 15px; opacity: 0.8; margin-bottom: 48px; }
  .cover-meta { font-size: 13px; opacity: 0.65; }

  .section { padding: 32px 48px; border-bottom: 1px solid #f1f5f9; }
  .section-title { font-size: 16px; font-weight: 700; color: #1e293b; margin-bottom: 20px; padding-bottom: 10px; border-bottom: 2px solid #4f46e5; display: inline-block; }

  .metric-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; }
  .metric-card { background: #f8fafc; border-radius: 12px; padding: 16px; text-align: center; }
  .metric-value { font-size: 28px; font-weight: 800; }
  .metric-label { font-size: 11px; color: #64748b; margin-top: 4px; }

  .bar-row { display: flex; align-items: center; gap: 12px; margin-bottom: 10px; }
  .bar-label { font-size: 12px; color: #475569; width: 80px; flex-shrink: 0; }
  .bar-track { flex: 1; height: 8px; background: #e2e8f0; border-radius: 4px; overflow: hidden; }
  .bar-fill { height: 100%; border-radius: 4px; }
  .bar-value { font-size: 12px; font-weight: 600; width: 40px; text-align: right; flex-shrink: 0; }

  .kw-table { width: 100%; border-collapse: collapse; font-size: 13px; }
  .kw-table th { text-align: left; padding: 8px 12px; background: #f8fafc; color: #64748b; font-weight: 500; border-bottom: 1px solid #e2e8f0; }
  .kw-table td { padding: 8px 12px; border-bottom: 1px solid #f1f5f9; }

  .article-row { display: flex; align-items: start; gap: 10px; padding: 10px 0; border-bottom: 1px solid #f1f5f9; }
  .article-row:last-child { border-bottom: none; }
  .tag { font-size: 11px; background: #e0e7ff; color: #4338ca; padding: 2px 8px; border-radius: 4px; flex-shrink: 0; }
  .tag-pub { background: #dcfce7; color: #15803d; }

  .signal-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; }
  .signal-card { border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px; }
  .signal-name { font-size: 11px; color: #64748b; margin-bottom: 6px; }
  .signal-score-row { display: flex; align-items: center; gap: 8px; }
  .signal-bar { flex: 1; height: 4px; background: #e2e8f0; border-radius: 2px; overflow: hidden; }

  .alert-row { display: flex; align-items: center; gap: 10px; padding: 8px 12px; border-radius: 8px; margin-bottom: 8px; }
  .dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }

  .footer-note { padding: 24px 48px; background: #f8fafc; font-size: 12px; color: #94a3b8; text-align: center; }

  .print-btn { position: fixed; bottom: 32px; right: 32px; background: #4f46e5; color: white; border: none; padding: 12px 24px; border-radius: 10px; font-size: 14px; font-weight: 600; cursor: pointer; box-shadow: 0 4px 12px rgba(79,70,229,0.4); z-index: 100; }
  .nav-bar { background: white; border-bottom: 1px solid #e2e8f0; padding: 12px 48px; display: flex; align-items: center; justify-between; }
  .nav-bar a { font-size: 13px; color: #4f46e5; text-decoration: none; }
  .month-nav { display: flex; align-items: center; gap: 12px; }
  .month-nav a { font-size: 13px; color: #4f46e5; text-decoration: none; padding: 4px 10px; border: 1px solid #e2e8f0; border-radius: 6px; }
</style>
</head>
<body>

<!-- Controls (no-print) -->
<div class="no-print nav-bar">
  <a href="dashboard.php">← 返回看板</a>
  <div class="month-nav">
    <?php
    $prevY = $month === 1 ? $year-1 : $year;
    $prevM = $month === 1 ? 12 : $month-1;
    $nextY = $month === 12 ? $year+1 : $year;
    $nextM = $month === 12 ? 1 : $month+1;
    ?>
    <a href="?year=<?= $prevY ?>&month=<?= $prevM ?>">‹ 上月</a>
    <span style="font-size:14px;font-weight:600"><?= $monthLabel ?></span>
    <a href="?year=<?= $nextY ?>&month=<?= $nextM ?>">下月 ›</a>
  </div>
  <button onclick="window.print()" style="background:#4f46e5;color:white;border:none;padding:6px 16px;border-radius:6px;font-size:13px;cursor:pointer">打印 / 导出PDF</button>
</div>

<div class="page">

  <!-- Cover -->
  <div class="cover">
    <div class="cover-logo"><img src="/assets/images/lynden-brothers-logo.jpg" alt="Lynden Brothers GEO"></div>
    <div class="cover-title"><?= htmlspecialchars($name) ?></div>
    <div class="cover-sub"><?= $monthLabel ?> · 品牌AI可见度月度报告</div>
    <div class="cover-meta">生成日期：<?= date('Y年m月d日') ?></div>
  </div>

  <!-- Summary metrics -->
  <div class="section">
    <div class="section-title">核心指标概览</div>
    <div class="metric-grid">
      <div class="metric-card">
        <?php if ($geoScore !== null):
          [$g, $c, $l] = score_grade($geoScore); ?>
          <div class="metric-value" style="color:<?= $c ?>"><?= $geoScore ?></div>
          <div class="metric-label">GEO综合评分</div>
          <div style="font-size:11px;margin-top:4px;color:#64748b"><?= $g ?> · <?= $l ?></div>
        <?php else: ?>
          <div class="metric-value" style="color:#cbd5e1">--</div>
          <div class="metric-label">GEO综合评分</div>
          <div style="font-size:11px;margin-top:4px;color:#94a3b8">本月未诊断</div>
        <?php endif; ?>
      </div>
      <div class="metric-card">
        <div class="metric-value" style="color:#4f46e5"><?= $avgRate ?>%</div>
        <div class="metric-label">平均AI提及率</div>
        <div style="font-size:11px;margin-top:4px;color:#64748b"><?= count($platforms) ?>个平台数据</div>
      </div>
      <div class="metric-card">
        <div class="metric-value" style="color:#0ea5e9"><?= count($articles) ?></div>
        <div class="metric-label">GEO内容发布</div>
        <div style="font-size:11px;margin-top:4px;color:#64748b"><?= $publishedCount ?>篇已发布</div>
      </div>
      <div class="metric-card">
        <div class="metric-value" style="color:<?= $alertSummary['high'] > 0 ? '#dc2626' : '#16a34a' ?>"><?= array_sum($alertSummary) ?></div>
        <div class="metric-label">监测告警</div>
        <div style="font-size:11px;margin-top:4px;color:#64748b">高<?= $alertSummary['high'] ?> 中<?= $alertSummary['medium'] ?> 低<?= $alertSummary['low'] ?></div>
      </div>
    </div>
  </div>

  <!-- Platform breakdown -->
  <div class="section">
    <div class="section-title">AI平台提及率</div>
    <?php foreach ($platformNames as $key => $pname):
      $p = $platMap[$key] ?? null;
      $rate = $p ? (float)$p['rate'] : 0;
      $color = $rate >= 60 ? '#22c55e' : ($rate >= 30 ? '#f59e0b' : '#ef4444');
    ?>
    <div class="bar-row">
      <span class="bar-label"><?= $pname ?></span>
      <div class="bar-track"><div class="bar-fill" style="width:<?= min(100,$rate) ?>%;background:<?= $color ?>"></div></div>
      <span class="bar-value" style="color:<?= $p ? $color : '#cbd5e1' ?>"><?= $p ? $rate.'%' : '—' ?></span>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- Top keywords -->
  <?php if (!empty($topKeywords)): ?>
  <div class="section">
    <div class="section-title">关键词AI可见度 TOP<?= count($topKeywords) ?></div>
    <table class="kw-table">
      <thead><tr><th>#</th><th>关键词</th><th>提及率</th><th>查询次数</th></tr></thead>
      <tbody>
        <?php foreach ($topKeywords as $i => $kw):
          $color = $kw['rate'] >= 60 ? '#16a34a' : ($kw['rate'] >= 30 ? '#d97706' : '#dc2626');
        ?>
        <tr>
          <td style="color:#94a3b8"><?= $i+1 ?></td>
          <td><?= htmlspecialchars($kw['query_text']) ?></td>
          <td><span style="font-weight:700;color:<?= $color ?>"><?= $kw['rate'] ?>%</span></td>
          <td style="color:#94a3b8"><?= $kw['total'] ?> 次</td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <!-- Signal scores -->
  <?php if (!empty($signals)): ?>
  <div class="section">
    <div class="section-title">GEO维度得分</div>
    <div class="signal-grid">
      <?php foreach ($signals as $sig):
        [$g, $c, $l] = score_grade((int)$sig['score']);
        $barColor = (int)$sig['score'] >= 70 ? '#22c55e' : ((int)$sig['score'] >= 50 ? '#f59e0b' : '#ef4444');
      ?>
      <div class="signal-card">
        <div class="signal-name"><?= htmlspecialchars($sig['name']) ?></div>
        <div class="signal-score-row">
          <div class="signal-bar"><div style="width:<?= min(100,$sig['score']) ?>%;height:4px;background:<?= $barColor ?>;border-radius:2px"></div></div>
          <span style="font-size:13px;font-weight:700;color:<?= $c ?>"><?= $sig['score'] ?></span>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- Articles -->
  <?php if (!empty($articles)): ?>
  <div class="section">
    <div class="section-title">本月GEO内容（<?= count($articles) ?>篇）</div>
    <?php foreach ($articles as $a): ?>
    <div class="article-row">
      <span style="font-size:12px;color:#94a3b8;flex-shrink:0;margin-top:2px"><?= date('m/d', strtotime($a['created_at'])) ?></span>
      <span class="tag"><?= htmlspecialchars($a['platform'] ?? '待定') ?></span>
      <?php if (($a['status'] ?? '') === 'published'): ?>
        <span class="tag tag-pub">已发布</span>
      <?php endif; ?>
      <span style="font-size:13px;color:#1e293b;flex:1"><?= htmlspecialchars($a['title'] ?? '') ?></span>
      <?php if (!empty($a['remote_url'])): ?>
        <a href="<?= htmlspecialchars($a['remote_url']) ?>" target="_blank" style="font-size:11px;color:#4f46e5;flex-shrink:0;text-decoration:none">查看原文 ↗</a>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- Footer -->
  <div class="footer-note">
    本报告由GEO顾问团队生成 · <?= $monthLabel ?> · <?= htmlspecialchars($name) ?><br>
    数据来源：AI平台实时监测 + GEO诊断系统
  </div>

</div>

<script>
// Auto-adjust for printing: hide month nav
window.addEventListener('beforeprint', () => {
  document.querySelector('.no-print')?.remove();
});
</script>
</body>
</html>
