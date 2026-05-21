<?php
if (!defined('FEISHU_TREASURE')) exit('Access denied');

function brand_completeness_check(PDO $db, string $cid): string {
    if (!$cid) return '';

    $required = [
        'brand_name'      => '品牌名称',
        'master_sentence' => '定位母句',
        'core_service'    => '核心服务',
        'industry'        => '所属行业',
        'differentiator'  => '差异化主张',
    ];

    try {
        $stmt = $db->prepare("SELECT fact_key, fact_value FROM geo_brand_facts WHERE customer_id=?");
        $stmt->execute([$cid]);
        $filled = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (trim($r['fact_value']) !== '') $filled[] = $r['fact_key'];
        }
    } catch (Throwable $e) { return ''; }

    $kwCount = 0;
    try {
        $s = $db->prepare("SELECT COUNT(*) FROM geo_monitor_keywords WHERE customer_id=? AND enabled=TRUE");
        $s->execute([$cid]); $kwCount = (int)$s->fetchColumn();
    } catch (Throwable $e) {}

    $compCount = 0;
    try {
        $s = $db->prepare("SELECT COUNT(*) FROM geo_customer_competitors WHERE customer_id=? AND enabled=TRUE");
        $s->execute([$cid]); $compCount = (int)$s->fetchColumn();
    } catch (Throwable $e) {}

    $missing = [];
    foreach ($required as $key => $label) {
        if (!in_array($key, $filled)) $missing[] = $label;
    }
    if ($kwCount === 0) $missing[] = '监测关键词';
    if ($compCount === 0) $missing[] = '竞品列表';

    if (empty($missing)) return '';

    $total   = count($required) + 2;
    $done    = $total - count($missing);
    $pct     = round($done / $total * 100);
    $missStr = implode('、', $missing);
    $url     = 'customers.php?' . http_build_query(['edit_customer_id' => $cid]);

    return <<<HTML
<div class="mb-5 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 flex items-center justify-between gap-4">
  <div class="flex items-center gap-3 min-w-0">
    <svg class="w-5 h-5 text-amber-500 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
    <div class="min-w-0">
      <span class="text-sm font-semibold text-amber-800">品牌档案 {$done}/{$total}（{$pct}%）</span>
      <span class="text-sm text-amber-700 ml-2 truncate">缺少：{$missStr}</span>
    </div>
  </div>
  <a href="{$url}" class="flex-shrink-0 text-xs font-medium text-amber-700 border border-amber-300 rounded-lg px-3 py-1.5 hover:bg-amber-100 whitespace-nowrap">去完善 →</a>
</div>
HTML;
}
