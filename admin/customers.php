<?php
/**
 * 客户中心 - 以客户为中心的 GEO 交付工作台
 */

define('FEISHU_TREASURE', true);
session_start();

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/database_admin.php';

require_admin_login();

function customer_h($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function customer_split_terms(string $raw): array {
    $parts = preg_split('/[,，、\n\r]+/u', $raw) ?: [];
    $terms = [];
    foreach ($parts as $part) {
        $term = mb_substr(trim($part), 0, 100);
        if ($term !== '') {
            $terms[] = $term;
        }
    }
    return array_values(array_unique($terms));
}

function customer_add_monitor_keywords(PDO $db, string $customerId, array $keywords): int {
    if ($customerId === '' || empty($keywords)) {
        return 0;
    }

    $stmt = $db->prepare("
        INSERT INTO geo_monitor_keywords (customer_id, keyword, enabled)
        VALUES (?, ?, TRUE)
        ON CONFLICT (customer_id, keyword) DO UPDATE SET enabled = TRUE
    ");

    $added = 0;
    foreach ($keywords as $keyword) {
        $keyword = mb_substr(trim((string) $keyword), 0, 100);
        if ($keyword === '') {
            continue;
        }
        $stmt->execute([$customerId, $keyword]);
        $added++;
    }

    return $added;
}

$customers = [
    [
        'id' => 'wenyun-ai-reading',
        'name' => '湖南文韵爱阅读',
        'domain' => 'wenyunedu.cn',
        'industry' => '教培 / 知识付费',
        'package_tier' => 'growth',
        'package_label' => '增长版',
        'owner' => '张顾问',
        'stage_key' => 'execute',
        'stage_label' => '第4阶段执行中',
        'overall_pct' => 58,
        'service_status' => 'active',
        'status_label' => '服务中',
        'contract_start_at' => '2026-01-01',
        'contract_end_at' => '2026-08-01',
        'contract_amount' => 58000,
        'contact_name' => '李敏',
        'contact_phone' => 'liMin@wenyunedu.cn',
        'competitors' => ['心田花开', '楚才教育', '麦田格'],
        'cities' => ['长沙'],
        'alerts' => ['本周需补齐百度文库与公众号长文入口', '竞品“心田花开”在 Kimi 长文问答中出现 2 次'],
        'pending' => ['完成 6 篇知识问答内容', '更新监测关键词组', '复盘本月引用来源变化'],
        'stages' => [
            ['key' => 'diagnose', 'name' => '诊断', 'tool' => '雷达诊断', 'link' => 'geo-diagnosis.php', 'pct' => 100, 'status' => 'done', 'note' => '完成六维基线和行业短板确认'],
            ['key' => 'simulate', 'name' => '模拟', 'tool' => '引用模拟器', 'link' => 'citation-simulator.php', 'pct' => 100, 'status' => 'done', 'note' => '完成核心关键词引用机会推演'],
            ['key' => 'strategy', 'name' => '策略', 'tool' => 'AI偏好对照表', 'link' => 'ai-citation-preferences.php', 'pct' => 75, 'status' => 'done', 'note' => '已锁定 Kimi/文心/元宝优先组合'],
            ['key' => 'execute', 'name' => '执行', 'tool' => '策略 SOP', 'link' => 'sop-center.php', 'pct' => 45, 'status' => 'doing', 'note' => '内容矩阵和外部信源正在补齐'],
            ['key' => 'monitor', 'name' => '监测', 'tool' => 'GEO监测', 'link' => 'geo-monitor.php', 'pct' => 20, 'status' => 'doing', 'note' => '开始沉淀月度引用变化证据'],
            ['key' => 'renew', 'name' => '续费', 'tool' => '复盘对比', 'link' => 'dashboard.php?tab=journey', 'pct' => 0, 'status' => 'pending', 'note' => '待形成下一轮复盘包'],
        ],
    ],
    [
        'id' => 'dongluoji-mgeo',
        'name' => '董逻辑 MGEO',
        'domain' => 'dongluoji.com',
        'industry' => 'B2B SaaS / 企业服务',
        'package_tier' => 'dominate',
        'package_label' => '主导版',
        'owner' => '李策略',
        'stage_key' => 'strategy',
        'stage_label' => '第3阶段策略中',
        'overall_pct' => 42,
        'service_status' => 'active',
        'status_label' => '服务中',
        'contract_start_at' => '2025-12-01',
        'contract_end_at' => '2026-09-15',
        'contract_amount' => 128000,
        'contact_name' => '董策',
        'contact_phone' => 'dong@dongluoji.com',
        'competitors' => ['GEO服务商', 'AI搜索优化', '内容增长工具'],
        'cities' => ['全国'],
        'alerts' => ['AI 配置仍有 3 家平台缺少 API Key', 'B2B 权威内容包需要补 GitHub README 和案例页'],
        'pending' => ['完善客户中心上下文', '补齐监测 v2 页面', '梳理渠道版交付模板'],
        'stages' => [
            ['key' => 'diagnose', 'name' => '诊断', 'tool' => '雷达诊断', 'link' => 'geo-diagnosis.php', 'pct' => 100, 'status' => 'done', 'note' => '已形成品牌 GEO 诊断模型'],
            ['key' => 'simulate', 'name' => '模拟', 'tool' => '引用模拟器', 'link' => 'citation-simulator.php', 'pct' => 100, 'status' => 'done', 'note' => '已完成中文 AI 引用机会推演'],
            ['key' => 'strategy', 'name' => '策略', 'tool' => 'AI偏好对照表', 'link' => 'ai-citation-preferences.php', 'pct' => 70, 'status' => 'doing', 'note' => '正在把行业偏好转成投放组合'],
            ['key' => 'execute', 'name' => '执行', 'tool' => '策略 SOP', 'link' => 'sop-center.php', 'pct' => 35, 'status' => 'doing', 'note' => '交付、素材和任务模块已接入'],
            ['key' => 'monitor', 'name' => '监测', 'tool' => 'GEO监测', 'link' => 'geo-monitor.php', 'pct' => 10, 'status' => 'pending', 'note' => '待接入 API Key 后跑真实监测'],
            ['key' => 'renew', 'name' => '续费', 'tool' => '复盘对比', 'link' => 'dashboard.php?tab=journey', 'pct' => 0, 'status' => 'pending', 'note' => '尚未进入续费周期'],
        ],
    ],
    [
        'id' => 'yimaitong-health',
        'name' => '医脉通健康项目',
        'domain' => 'news.growume.com',
        'industry' => '医疗 / 健康',
        'package_tier' => 'growth',
        'package_label' => '增长版',
        'owner' => '王运营',
        'stage_key' => 'execute',
        'stage_label' => '第4阶段执行中',
        'overall_pct' => 51,
        'service_status' => 'active',
        'status_label' => '服务中',
        'contract_start_at' => '2026-01-20',
        'contract_end_at' => '2026-07-20',
        'contract_amount' => 45000,
        'contact_name' => '陈医达',
        'contact_phone' => 'chen@yimaitong.net',
        'competitors' => ['丁香医生', '丁香园', '百度健康', '知乎健康'],
        'cities' => ['全国'],
        'alerts' => ['健康类内容需规避医疗承诺', '建议保留丁香医生、百度健康和知乎健康作为行业权威信源'],
        'pending' => ['补齐健康问答白名单', '确认内容合规边界', '更新媒体分发资源权重'],
        'stages' => [
            ['key' => 'diagnose', 'name' => '诊断', 'tool' => '雷达诊断', 'link' => 'geo-diagnosis.php', 'pct' => 100, 'status' => 'done', 'note' => '完成健康行业权威信源扫描'],
            ['key' => 'simulate', 'name' => '模拟', 'tool' => '引用模拟器', 'link' => 'citation-simulator.php', 'pct' => 80, 'status' => 'done', 'note' => '完成核心健康关键词推演'],
            ['key' => 'strategy', 'name' => '策略', 'tool' => 'AI偏好对照表', 'link' => 'ai-citation-preferences.php', 'pct' => 65, 'status' => 'done', 'note' => '医疗权威信源优先级已确定'],
            ['key' => 'execute', 'name' => '执行', 'tool' => '媒体分发', 'link' => 'distribution.php', 'pct' => 40, 'status' => 'doing', 'note' => '正在补充健康行业可执行资源'],
            ['key' => 'monitor', 'name' => '监测', 'tool' => 'GEO监测', 'link' => 'geo-monitor.php', 'pct' => 15, 'status' => 'pending', 'note' => '等待真实监测批次'],
            ['key' => 'renew', 'name' => '续费', 'tool' => '复盘对比', 'link' => 'dashboard.php?tab=journey', 'pct' => 0, 'status' => 'pending', 'note' => '未到续费节点'],
        ],
    ],
    [
        'id' => 'local-food-sample',
        'name' => '本地生活餐饮样板',
        'domain' => 'local-demo.cn',
        'industry' => '本地生活 / 餐饮',
        'package_tier' => 'lite',
        'package_label' => '轻量版',
        'owner' => '陈执行',
        'stage_key' => 'diagnose',
        'stage_label' => '第1阶段诊断中',
        'overall_pct' => 18,
        'service_status' => 'paused',
        'status_label' => '暂停',
        'contract_start_at' => '2026-03-01',
        'contract_end_at' => '2026-06-30',
        'contract_amount' => 18000,
        'contact_name' => '吴老板',
        'contact_phone' => '13800138000',
        'competitors' => ['大众点评', '美团', '小红书本地号'],
        'cities' => ['上海'],
        'alerts' => ['客户暂缓执行，需确认是否恢复服务'],
        'pending' => ['完成品牌基础诊断', '整理本地生活信源', '确认预算'],
        'stages' => [
            ['key' => 'diagnose', 'name' => '诊断', 'tool' => '雷达诊断', 'link' => 'geo-diagnosis.php', 'pct' => 45, 'status' => 'doing', 'note' => '本地生活信源扫描进行中'],
            ['key' => 'simulate', 'name' => '模拟', 'tool' => '引用模拟器', 'link' => 'citation-simulator.php', 'pct' => 0, 'status' => 'pending', 'note' => '待补关键词'],
            ['key' => 'strategy', 'name' => '策略', 'tool' => 'AI偏好对照表', 'link' => 'ai-citation-preferences.php', 'pct' => 0, 'status' => 'pending', 'note' => '未开始'],
            ['key' => 'execute', 'name' => '执行', 'tool' => '媒体分发', 'link' => 'distribution.php', 'pct' => 0, 'status' => 'pending', 'note' => '未开始'],
            ['key' => 'monitor', 'name' => '监测', 'tool' => 'GEO监测', 'link' => 'geo-monitor.php', 'pct' => 0, 'status' => 'pending', 'note' => '未开始'],
            ['key' => 'renew', 'name' => '续费', 'tool' => '复盘对比', 'link' => 'dashboard.php?tab=journey', 'pct' => 0, 'status' => 'pending', 'note' => '未开始'],
        ],
    ],
];

// ── 从 sop_node_status 读取执行进度，覆盖 execute 阶段的 pct ─────────────────
// onboard 场景共 14 个节点（S1-S14，含 S8/S13）
const SOP_ONBOARD_TOTAL = 14;

try {
    $allCustomerIds = array_column($customers, 'id');
    $placeholders   = implode(',', array_fill(0, count($allCustomerIds), '?'));
    $stmtSopPct = $db->prepare("
        SELECT customer_id,
               COUNT(*) FILTER (WHERE status = 'done') AS done_count,
               COUNT(*)                                 AS total_count
        FROM sop_node_status
        WHERE scenario = 'onboard' AND customer_id IN ($placeholders)
        GROUP BY customer_id
    ");
    $stmtSopPct->execute($allCustomerIds);
    $sopProgress = [];
    foreach ($stmtSopPct->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $sopProgress[$row['customer_id']] = (int) $row['done_count'];
    }
} catch (Throwable $_e) {
    $sopProgress = [];
}

foreach ($customers as &$_cust) {
    $doneCount   = $sopProgress[$_cust['id']] ?? null;
    if ($doneCount === null) continue; // 没有任何节点被点过，保留原始 mock 值

    $executePct = min(100, (int) round($doneCount / SOP_ONBOARD_TOTAL * 100));

    foreach ($_cust['stages'] as &$_stage) {
        if ($_stage['key'] === 'execute') {
            $_stage['pct']    = $executePct;
            $_stage['status'] = $executePct >= 100 ? 'done' : ($executePct > 0 ? 'doing' : 'pending');
            break;
        }
    }
    unset($_stage);

    // 重算 overall_pct = 所有阶段 pct 的简单平均
    $stagePcts = array_column($_cust['stages'], 'pct');
    $_cust['overall_pct'] = (int) round(array_sum($stagePcts) / count($stagePcts));
}
unset($_cust);

$customer_map = [];
foreach ($customers as $customer) {
    $customer_map[$customer['id']] = $customer;
}

// 补充从数据库加载的客户（新建客户不在 hardcoded 列表中）
try {
    $dbStmt = $db->prepare("SELECT customer_id, name, domain, industry, package_tier, owner, service_status, contract_start_date, contract_end_date, contract_amount, contact_name, contact_phone FROM customers ORDER BY created_at DESC");
    $dbStmt->execute();
    while ($dbRow = $dbStmt->fetch(PDO::FETCH_ASSOC)) {
        $cid = $dbRow['customer_id'];
        if (!isset($customer_map[$cid])) {
            $stageKey = 'diagnose';
            $stageLabel = '第1阶段诊断中';
            $packageMap = ['growth' => ['growth', '增长版'], 'dominate' => ['dominate', '主导版'], 'lite' => ['lite', '轻量版']];
            $pkg = $packageMap[$dbRow['package_tier']] ?? ['growth', '增长版'];
            $customer_map[$cid] = [
                'id' => $cid,
                'name' => $dbRow['name'],
                'domain' => $dbRow['domain'],
                'industry' => $dbRow['industry'],
                'package_tier' => $pkg[0],
                'package_label' => $pkg[1],
                'owner' => $dbRow['owner'],
                'stage_key' => $stageKey,
                'stage_label' => $stageLabel,
                'overall_pct' => 0,
                'service_status' => $dbRow['service_status'],
                'status_label' => $dbRow['service_status'] === 'active' ? '服务中' : '暂停',
                'contract_start_at' => $dbRow['contract_start_date'] ?? date('Y-m-d'),
                'contract_end_at' => $dbRow['contract_end_date'] ?? date('Y-m-d', strtotime('+90 days')),
                'contract_amount' => $dbRow['contract_amount'],
                'contact_name' => $dbRow['contact_name'],
                'contact_phone' => $dbRow['contact_phone'],
                'competitors' => [],
                'cities' => [],
                'alerts' => [],
                'pending' => [],
                'stages' => [
                    ['key' => 'diagnose', 'name' => '诊断', 'tool' => '雷达诊断', 'link' => 'geo-diagnosis.php', 'pct' => 0, 'status' => 'pending', 'note' => '待开始'],
                    ['key' => 'simulate', 'name' => '模拟', 'tool' => '引用模拟器', 'link' => 'citation-simulator.php', 'pct' => 0, 'status' => 'pending', 'note' => '待开始'],
                    ['key' => 'strategy', 'name' => '策略', 'tool' => 'AI偏好对照表', 'link' => 'ai-citation-preferences.php', 'pct' => 0, 'status' => 'pending', 'note' => '待开始'],
                    ['key' => 'execute', 'name' => '执行', 'tool' => '策略 SOP', 'link' => 'sop-center.php', 'pct' => 0, 'status' => 'pending', 'note' => '待开始'],
                    ['key' => 'monitor', 'name' => '监测', 'tool' => 'GEO监测', 'link' => 'geo-monitor.php', 'pct' => 0, 'status' => 'pending', 'note' => '待开始'],
                    ['key' => 'renew', 'name' => '续费', 'tool' => '复盘对比', 'link' => 'dashboard.php?tab=journey', 'pct' => 0, 'status' => 'pending', 'note' => '待开始'],
                ],
            ];
            $customers[] = $customer_map[$cid];
        } else {
            // 已存在的 hardcoded 客户，用 DB 真实数据补全
            $existing = &$customer_map[$cid];
            if (!empty($dbRow['contract_start_date'])) $existing['contract_start_at'] = $dbRow['contract_start_date'];
            if (!empty($dbRow['contract_end_date']))   $existing['contract_end_at'] = $dbRow['contract_end_date'];
            if (!empty($dbRow['contact_name']))        $existing['contact_name'] = $dbRow['contact_name'];
            if (!empty($dbRow['contact_phone']))       $existing['contact_phone'] = $dbRow['contact_phone'];
        }
    }
} catch (Throwable $_dbe) {}


// ── 竞品名称 AJAX 处理 ───────────────────────────────────────────────────────
// ── 合同到期日 AJAX 更新 ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_contract_date') {
    header('Content-Type: application/json');
    if (!verify_csrf_token($_POST['_csrf'] ?? '')) { echo json_encode(['ok' => false, 'error' => 'CSRF']); exit; }
    $cid  = trim($_POST['customer_id'] ?? '');
    $date = trim($_POST['contract_end_date'] ?? '');
    if ($cid === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        echo json_encode(['ok' => false, 'error' => '参数无效']); exit;
    }
    try {
        $db->prepare("UPDATE customers SET contract_end_date = ? WHERE customer_id = ?")
           ->execute([$date, $cid]);
        echo json_encode(['ok' => true, 'date' => $date]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ── 新建客户 AJAX 处理 ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_customer') {
    header('Content-Type: application/json');
    if (!verify_csrf_token($_POST['_csrf'] ?? '')) { echo json_encode(['ok' => false, 'error' => 'CSRF']); exit; }
    require_once __DIR__ . '/../includes/api_response.php';
    require_once __DIR__ . '/../includes/customer_service.php';
    try {
        $svc = new CustomerService($db);
        $newCust = $svc->createCustomer([
            'name'               => trim($_POST['name'] ?? ''),
            'domain'             => trim($_POST['domain'] ?? ''),
            'industry'           => trim($_POST['industry'] ?? ''),
            'package_tier'       => trim($_POST['package_tier'] ?? ''),
            'owner'              => trim($_POST['owner'] ?? ''),
            'contact_name'       => trim($_POST['contact_name'] ?? ''),
            'contact_phone'      => trim($_POST['contact_phone'] ?? ''),
            'contract_start_date'=> !empty($_POST['contract_start_date']) ? $_POST['contract_start_date'] : null,
            'contract_end_date'  => !empty($_POST['contract_end_date']) ? $_POST['contract_end_date'] : null,
            'contract_amount'    => (float) ($_POST['contract_amount'] ?? 0),
        ]);
        $cid = $newCust['customer_id'];

        // 写入竞品
        $competitors = customer_split_terms($_POST['competitors'] ?? '');
        if ($competitors) {
            $insCmp = $db->prepare("INSERT INTO geo_customer_competitors (customer_id, competitor) VALUES (?,?) ON CONFLICT (customer_id, competitor) DO UPDATE SET enabled=TRUE");
            foreach ($competitors as $cmp) {
                if ($cmp !== '') $insCmp->execute([$cid, $cmp]);
            }
            customer_add_monitor_keywords($db, $cid, $competitors);
        }

        // 写入基础品牌事实
        $factsToInsert = [];
        if (!empty($newCust['name']))       $factsToInsert[] = ['brand_name', '品牌名称', $newCust['name']];
        if (!empty($newCust['domain']))     $factsToInsert[] = ['domain', '官网域名', $newCust['domain']];
        if (!empty($newCust['industry']))   $factsToInsert[] = ['industry', '所属行业', $newCust['industry']];
        if (!empty($newCust['contact_name'])) $factsToInsert[] = ['contact_name', '联系人', $newCust['contact_name']];
        $insFact = $db->prepare("INSERT INTO geo_brand_facts (customer_id, fact_key, fact_label, fact_value, is_core) VALUES (?,?,?,?,true) ON CONFLICT (customer_id, fact_key) DO UPDATE SET fact_value=EXCLUDED.fact_value, updated_at=CURRENT_TIMESTAMP");
        foreach ($factsToInsert as $f) {
            $insFact->execute([$cid, $f[0], $f[1], $f[2]]);
        }

        // 初始化 SOP 节点（onboard 场景，所有节点 pending）
        $sopNodes = ['S1','S2','S3','S4','S5','S6','S7','S8','S9','S10','S11','S12','S14'];
        $insSop = $db->prepare("INSERT INTO sop_node_status (customer_id, scenario, node_code, status) VALUES (?,?,?,?) ON CONFLICT (customer_id, scenario, node_code) DO NOTHING");
        foreach ($sopNodes as $node) {
            $insSop->execute([$cid, 'onboard', $node, 'pending']);
        }

        echo json_encode(['ok' => true, 'customer_id' => $cid, 'name' => $newCust['name']]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['save_competitor', 'delete_competitor'], true)) {
    header('Content-Type: application/json');
    if (!verify_csrf_token($_POST['_csrf'] ?? '')) { echo json_encode(['ok' => false, 'error' => 'CSRF']); exit; }
    $cmpCid = trim($_POST['customer_id'] ?? '');
    if (!isset($customer_map[$cmpCid])) { echo json_encode(['ok' => false, 'error' => 'Invalid customer']); exit; }
    function cmpReload(PDO $db, string $cid): array {
        $s = $db->prepare("SELECT id, competitor FROM geo_customer_competitors WHERE customer_id = ? AND enabled = TRUE ORDER BY id");
        $s->execute([$cid]);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }
    try {
        if ($_POST['action'] === 'save_competitor') {
            $name = mb_substr(trim($_POST['competitor'] ?? ''), 0, 100);
            if ($name === '') { echo json_encode(['ok' => false, 'error' => 'empty']); exit; }
            $db->prepare("INSERT INTO geo_customer_competitors (customer_id, competitor) VALUES (?,?) ON CONFLICT (customer_id, competitor) DO UPDATE SET enabled=TRUE")->execute([$cmpCid, $name]);
            customer_add_monitor_keywords($db, $cmpCid, [$name]);
        } else {
            $db->prepare("UPDATE geo_customer_competitors SET enabled=FALSE WHERE id=? AND customer_id=?")->execute([(int)($_POST['competitor_id'] ?? 0), $cmpCid]);
        }
        echo json_encode(['ok' => true, 'competitors' => cmpReload($db, $cmpCid)]);
    } catch (Throwable $e) { echo json_encode(['ok' => false, 'error' => $e->getMessage()]); }
    exit;
}

// ── 品牌知识库 AJAX 处理 ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['save_brand_fact', 'delete_brand_fact'], true)) {
    header('Content-Type: application/json');
    if (!verify_csrf_token($_POST['_csrf'] ?? '')) { echo json_encode(['ok' => false, 'error' => 'CSRF']); exit; }
    $bfCid = trim($_POST['customer_id'] ?? '');
    if (!isset($customer_map[$bfCid])) { echo json_encode(['ok' => false, 'error' => 'Invalid customer']); exit; }
    $bfAction = $_POST['action'];
    function bfReload(PDO $db, string $cid): array {
        $s = $db->prepare("SELECT id, fact_key, fact_label, fact_value, is_core FROM geo_brand_facts WHERE customer_id = ? ORDER BY sort_order, id");
        $s->execute([$cid]);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }
    try {
        if ($bfAction === 'save_brand_fact') {
            $factKey   = mb_substr(preg_replace('/\s+/', '_', trim($_POST['fact_key'] ?? '')), 0, 80);
            $factLabel = mb_substr(trim($_POST['fact_label'] ?? ''), 0, 100);
            $factValue = mb_substr(trim($_POST['fact_value'] ?? ''), 0, 500);
            $isCore    = !empty($_POST['is_core']);
            if ($factKey === '' || $factLabel === '' || $factValue === '') {
                echo json_encode(['ok' => false, 'error' => 'empty']); exit;
            }
            $db->prepare("INSERT INTO geo_brand_facts (customer_id, fact_key, fact_label, fact_value, is_core) VALUES (?,?,?,?,?) ON CONFLICT (customer_id, fact_key) DO UPDATE SET fact_label=EXCLUDED.fact_label, fact_value=EXCLUDED.fact_value, is_core=EXCLUDED.is_core, updated_at=CURRENT_TIMESTAMP")->execute([$bfCid, $factKey, $factLabel, $factValue, $isCore ? 'true' : 'false']);
            echo json_encode(['ok' => true, 'facts' => bfReload($db, $bfCid)]);
        } else {
            $db->prepare("DELETE FROM geo_brand_facts WHERE id=? AND customer_id=?")->execute([(int)($_POST['fact_id'] ?? 0), $bfCid]);
            echo json_encode(['ok' => true, 'facts' => bfReload($db, $bfCid)]);
        }
    } catch (Throwable $e) { echo json_encode(['ok' => false, 'error' => $e->getMessage()]); }
    exit;
}

// ── 监测关键词 AJAX 处理 ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['save_monitor_keyword', 'delete_monitor_keyword'], true)) {
    header('Content-Type: application/json');
    if (!verify_csrf_token($_POST['_csrf'] ?? '')) {
        echo json_encode(['ok' => false, 'error' => 'CSRF error']);
        exit;
    }
    $kwCid = trim($_POST['customer_id'] ?? '');
    if (!isset($customer_map[$kwCid])) {
        echo json_encode(['ok' => false, 'error' => 'Invalid customer']);
        exit;
    }
    $action = $_POST['action'];
    try {
        if ($action === 'save_monitor_keyword') {
            $kw = mb_substr(trim($_POST['keyword'] ?? ''), 0, 60);
            if ($kw === '') { echo json_encode(['ok' => false, 'error' => 'empty']); exit; }
            $db->prepare("INSERT INTO geo_monitor_keywords (customer_id, keyword) VALUES (?, ?) ON CONFLICT (customer_id, keyword) DO NOTHING")->execute([$kwCid, $kw]);
            $stmt = $db->prepare("SELECT id, keyword, enabled FROM geo_monitor_keywords WHERE customer_id = ? ORDER BY id");
            $stmt->execute([$kwCid]);
            echo json_encode(['ok' => true, 'keywords' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        } else {
            $kwId = (int) ($_POST['keyword_id'] ?? 0);
            $db->prepare("DELETE FROM geo_monitor_keywords WHERE id = ? AND customer_id = ?")->execute([$kwId, $kwCid]);
            $stmt = $db->prepare("SELECT id, keyword, enabled FROM geo_monitor_keywords WHERE customer_id = ? ORDER BY id");
            $stmt->execute([$kwCid]);
            echo json_encode(['ok' => true, 'keywords' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        }
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

if (isset($_GET['clear_customer'])) {
    unset($_SESSION['current_customer']);
    admin_redirect('customers.php');
}

if (isset($_GET['select']) && isset($customer_map[$_GET['select']])) {
    $_SESSION['current_customer'] = $customer_map[$_GET['select']];
    admin_redirect('customers.php?customer=' . rawurlencode($_GET['select']));
}

$is_switching_customer = isset($_GET['switch_customer']);
$selected_id = $is_switching_customer ? null : ($_GET['customer'] ?? ($_SESSION['current_customer']['id'] ?? null));
$selected_customer = $selected_id && isset($customer_map[$selected_id]) ? $customer_map[$selected_id] : null;

if ($selected_customer) {
    // 用 DB 真实合同到期日覆盖 mock 值
    try {
        $stmtCed = $db->prepare("SELECT contract_end_date FROM customers WHERE customer_id = ?");
        $stmtCed->execute([$selected_customer['id']]);
        $dbCed = $stmtCed->fetchColumn();
        if ($dbCed) {
            $selected_customer['contract_end_at'] = $dbCed;
        }
    } catch (Throwable $_ce) {}
    $_SESSION['current_customer'] = $selected_customer;
}

// ── 读取选中客户的监测关键词 ──────────────────────────────────────────────────
$monitorKeywords = [];
if ($selected_customer) {
    try {
        $stmtKw = $db->prepare("SELECT id, keyword, enabled FROM geo_monitor_keywords WHERE customer_id = ? ORDER BY id");
        $stmtKw->execute([$selected_customer['id']]);
        $monitorKeywords = $stmtKw->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $_kwe) {}
}

// ── 读取选中客户的品牌知识库 ──────────────────────────────────────────────────
$brandFacts = [];
if ($selected_customer) {
    try {
        $stmtBf = $db->prepare("SELECT id, fact_key, fact_label, fact_value, is_core FROM geo_brand_facts WHERE customer_id = ? ORDER BY sort_order, id");
        $stmtBf->execute([$selected_customer['id']]);
        $brandFacts = $stmtBf->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $_bfe) {}
}

// ── 读取选中客户的竞品名称（来自 DB，权威来源）──────────────────────────────
$dbCompetitors = [];
if ($selected_customer) {
    try {
        $stmtCmp = $db->prepare("SELECT id, competitor FROM geo_customer_competitors WHERE customer_id = ? AND enabled = TRUE ORDER BY id");
        $stmtCmp->execute([$selected_customer['id']]);
        $dbCompetitors = $stmtCmp->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $_cmpe) {}
    // 用 DB 竞品覆盖 hardcoded 竞品
    if (!empty($dbCompetitors)) {
        $selected_customer['competitors'] = array_column($dbCompetitors, 'competitor');
    }
}

$page_title = '客户运营中心';

include __DIR__ . '/includes/header.php';

$scoped_customers = $selected_customer ? [$selected_customer] : $customers;
$active_count = count(array_filter($scoped_customers, fn($customer) => $customer['service_status'] === 'active'));
$doing_count = count(array_filter($scoped_customers, fn($customer) => in_array($customer['stage_key'], ['strategy', 'execute', 'monitor'], true)));
$renew_count = count(array_filter($scoped_customers, fn($customer) => strtotime($customer['contract_end_at']) <= strtotime('+60 days')));
$alert_count = array_sum(array_map(fn($customer) => count($customer['alerts']), $scoped_customers));
$ops_snapshot = [
    'avg_score' => null,
    'alerts_7d' => null,
    'articles_7d' => null,
    'queue_pending' => null,
];

if ($selected_customer) {
    $scopeCid = $selected_customer['id'];
    try {
        $stmt = $db->prepare("
            SELECT COALESCE(ROUND(overall_score), 0)
            FROM geo_diagnoses
            WHERE customer_id = ?
            ORDER BY created_at DESC
            LIMIT 1
        ");
        $stmt->execute([$scopeCid]);
        $score = $stmt->fetchColumn();
        $ops_snapshot['avg_score'] = $score === false ? null : (int) $score;
    } catch (Throwable $_e) {}

    try {
        $stmt = $db->prepare("SELECT COUNT(*) FROM geo_monitor_alerts WHERE customer_id = ? AND alerted_at >= NOW() - INTERVAL '7 days'");
        $stmt->execute([$scopeCid]);
        $ops_snapshot['alerts_7d'] = (int) $stmt->fetchColumn();
    } catch (Throwable $_e) {}

    try {
        $stmt = $db->prepare("
            SELECT COUNT(*)
            FROM articles a
            JOIN tasks t ON a.task_id = t.id
            WHERE t.geo_customer_id = ? AND a.created_at >= NOW() - INTERVAL '7 days'
        ");
        $stmt->execute([$scopeCid]);
        $ops_snapshot['articles_7d'] = (int) $stmt->fetchColumn();
    } catch (Throwable $_e) {}

    try {
        $stmt = $db->prepare("SELECT COUNT(*) FROM geo_content_queue WHERE customer_id = ? AND status = 'pending'");
        $stmt->execute([$scopeCid]);
        $ops_snapshot['queue_pending'] = (int) $stmt->fetchColumn();
    } catch (Throwable $_e) {}
} else {
    try {
        $ops_snapshot['avg_score'] = (int) $db->query("
            SELECT COALESCE(ROUND(AVG(d.overall_score)), 0)
            FROM geo_diagnoses d
            JOIN (
                SELECT customer_id, MAX(created_at) AS latest_at
                FROM geo_diagnoses
                GROUP BY customer_id
            ) latest ON latest.customer_id = d.customer_id AND latest.latest_at = d.created_at
        ")->fetchColumn();
    } catch (Throwable $_e) {}

    try {
        $ops_snapshot['alerts_7d'] = (int) $db->query("SELECT COUNT(*) FROM geo_monitor_alerts WHERE alerted_at >= NOW() - INTERVAL '7 days'")->fetchColumn();
    } catch (Throwable $_e) {}

    try {
        $ops_snapshot['articles_7d'] = (int) $db->query("SELECT COUNT(*) FROM articles WHERE created_at >= NOW() - INTERVAL '7 days'")->fetchColumn();
    } catch (Throwable $_e) {}

    try {
        $ops_snapshot['queue_pending'] = (int) $db->query("SELECT COUNT(*) FROM geo_content_queue WHERE status = 'pending'")->fetchColumn();
    } catch (Throwable $_e) {}
}

$status_classes = [
    'done' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
    'doing' => 'bg-blue-50 text-blue-700 border-blue-200',
    'pending' => 'bg-gray-50 text-gray-600 border-gray-200',
];
$status_names = ['done' => '已完成', 'doing' => '进行中', 'pending' => '待开始'];
$segment_classes = [
    'diagnose' => 'border-blue-200 bg-blue-50',
    'simulate' => 'border-blue-200 bg-blue-50',
    'strategy' => 'border-emerald-200 bg-emerald-50',
    'execute' => 'border-emerald-200 bg-emerald-50',
    'monitor' => 'border-orange-200 bg-orange-50',
    'renew' => 'border-orange-200 bg-orange-50',
];
$stage_badge_classes = [
    'diagnose' => 'bg-blue-600',
    'simulate' => 'bg-blue-600',
    'strategy' => 'bg-emerald-600',
    'execute' => 'bg-emerald-600',
    'monitor' => 'bg-orange-600',
    'renew' => 'bg-orange-600',
];
?>

<div class="space-y-8">
    <div class="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
        <div>
            <h1 class="text-3xl font-bold text-gray-900">客户运营中心</h1>
            <p class="mt-2 text-gray-600">把客户档案、交付进度、告警处理和运营复盘收进同一个工作台。</p>
        </div>
        <div class="flex flex-wrap gap-3">
            <button type="button" data-open-customer-modal class="inline-flex items-center rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-blue-700">
                <i data-lucide="plus" class="mr-2 h-4 w-4"></i>
                添加客户
            </button>
        </div>
    </div>

    <div class="grid grid-cols-1 gap-4 md:grid-cols-4">
        <div class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
            <div class="text-sm font-semibold text-gray-500"><?php echo $selected_customer ? '当前客户' : '客户总数'; ?></div>
            <div class="mt-3 text-4xl font-bold text-gray-900"><?php echo $selected_customer ? '1' : count($customers); ?></div>
            <div class="mt-2 text-sm text-gray-500"><?php echo $selected_customer ? customer_h($selected_customer['name']) : '统一 brands 客户实体'; ?></div>
        </div>
        <div class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
            <div class="text-sm font-semibold text-gray-500">服务中</div>
            <div class="mt-3 text-4xl font-bold text-gray-900"><?php echo $active_count; ?></div>
            <div class="mt-2 text-sm text-gray-500">可进入模块交付</div>
        </div>
        <div class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
            <div class="text-sm font-semibold text-gray-500">交付中</div>
            <div class="mt-3 text-4xl font-bold text-gray-900"><?php echo $doing_count; ?></div>
            <div class="mt-2 text-sm text-gray-500">策略、执行或监测阶段</div>
        </div>
        <a href="#alert-summary" onclick="document.getElementById('alert-summary').open=true"
           class="block rounded-lg border <?php echo $alert_count > 0 ? 'border-orange-200 bg-orange-50' : 'border-gray-200 bg-white'; ?> p-5 shadow-sm transition hover:shadow-md">
            <div class="text-sm font-semibold <?php echo $alert_count > 0 ? 'text-orange-600' : 'text-gray-500'; ?>">待关注</div>
            <div class="mt-3 text-4xl font-bold <?php echo $alert_count > 0 ? 'text-orange-700' : 'text-gray-900'; ?>"><?php echo $alert_count; ?></div>
            <div class="mt-2 text-sm <?php echo $alert_count > 0 ? 'text-orange-600' : 'text-gray-500'; ?>">
                <?php echo $selected_customer ? ($renew_count > 0 ? '当前客户 60 天内到期' : '当前客户合同未临期') : ($renew_count . ' 家 60 天内到期'); ?>
                <?php if ($alert_count > 0): ?>· 点击查看明细<?php endif; ?>
            </div>
        </a>
    </div>

    <section class="rounded-lg border border-gray-200 bg-white shadow-sm">
        <div class="flex flex-col gap-3 border-b border-gray-200 p-5 md:flex-row md:items-center md:justify-between">
            <div>
                <h2 class="text-xl font-bold text-gray-900">运营快照</h2>
                <p class="mt-1 text-sm text-gray-500"><?php echo $selected_customer ? '只展示当前客户的健康信号。' : '先选择客户，再进入单客户运营工作台。'; ?></p>
            </div>
        </div>
        <div class="grid grid-cols-1 gap-0 divide-y divide-gray-100 md:grid-cols-4 md:divide-x md:divide-y-0">
            <div class="p-5">
                <div class="text-sm font-semibold text-gray-500">平均 GEO 分</div>
                <div class="mt-3 text-3xl font-bold <?php echo ($ops_snapshot['avg_score'] ?? 0) >= 65 ? 'text-emerald-600' : 'text-gray-900'; ?>"><?php echo $ops_snapshot['avg_score'] === null ? '—' : (int) $ops_snapshot['avg_score']; ?></div>
                <div class="mt-2 text-sm text-gray-500"><?php echo $selected_customer ? '当前客户最新诊断' : '最新诊断均值'; ?></div>
            </div>
            <a href="<?php echo customer_h(admin_url('geo-monitor.php#alerts')); ?>" class="p-5 transition hover:bg-gray-50">
                <div class="text-sm font-semibold text-gray-500">7 日告警</div>
                <div class="mt-3 text-3xl font-bold <?php echo ($ops_snapshot['alerts_7d'] ?? 0) > 0 ? 'text-orange-600' : 'text-gray-900'; ?>"><?php echo $ops_snapshot['alerts_7d'] === null ? '—' : (int) $ops_snapshot['alerts_7d']; ?></div>
                <div class="mt-2 text-sm text-gray-500">需要运营跟进</div>
            </a>
            <a href="<?php echo customer_h(admin_url('articles.php')); ?>" class="p-5 transition hover:bg-gray-50">
                <div class="text-sm font-semibold text-gray-500">本周发文</div>
                <div class="mt-3 text-3xl font-bold text-blue-600"><?php echo $ops_snapshot['articles_7d'] === null ? '—' : (int) $ops_snapshot['articles_7d']; ?></div>
                <div class="mt-2 text-sm text-gray-500">内容产能</div>
            </a>
            <a href="<?php echo customer_h(admin_url('geo-content-queue.php')); ?>" class="p-5 transition hover:bg-gray-50">
                <div class="text-sm font-semibold text-gray-500">待生成</div>
                <div class="mt-3 text-3xl font-bold <?php echo ($ops_snapshot['queue_pending'] ?? 0) > 0 ? 'text-orange-600' : 'text-gray-900'; ?>"><?php echo $ops_snapshot['queue_pending'] === null ? '—' : (int) $ops_snapshot['queue_pending']; ?></div>
                <div class="mt-2 text-sm text-gray-500">进入内容队列</div>
            </a>
        </div>
    </section>

    <?php if ($alert_count > 0):
        // 为每条告警推断跳转模块
        function customer_alert_link(string $alert, string $custId): string {
            $base = admin_url('customers.php?customer=' . rawurlencode($custId));
            if (mb_stripos($alert, 'API') !== false || mb_stripos($alert, '配置') !== false) {
                return admin_url('citation-simulator.php');
            }
            if (mb_stripos($alert, '监测') !== false || mb_stripos($alert, '引用') !== false || mb_stripos($alert, 'Kimi') !== false || mb_stripos($alert, '竞品') !== false) {
                return admin_url('geo-monitor.php');
            }
            if (mb_stripos($alert, '内容') !== false || mb_stripos($alert, '信源') !== false || mb_stripos($alert, '分发') !== false || mb_stripos($alert, '补') !== false) {
                return admin_url('distribution.php');
            }
            if (mb_stripos($alert, '暂缓') !== false || mb_stripos($alert, '合同') !== false || mb_stripos($alert, '续费') !== false) {
                return $base;
            }
            return admin_url('geo-monitor.php');
        }
    ?>
    <details id="alert-summary" <?php echo $alert_count > 0 ? 'open' : ''; ?> class="rounded-lg border border-orange-200 bg-orange-50 shadow-sm">
        <summary class="flex cursor-pointer list-none items-center justify-between px-5 py-4">
            <div class="flex items-center gap-3">
                <i data-lucide="bell-ring" class="h-5 w-5 text-orange-600"></i>
                <span class="text-base font-semibold text-orange-900">待关注告警汇总</span>
                <span class="rounded-full bg-orange-600 px-2.5 py-0.5 text-xs font-bold text-white"><?php echo $alert_count; ?></span>
            </div>
            <span class="text-xs text-orange-500">点击展开 / 收起</span>
        </summary>
        <div class="divide-y divide-orange-100 border-t border-orange-200">
            <?php foreach ($scoped_customers as $customer):
                if (empty($customer['alerts'])) continue;
            ?>
            <div class="px-5 py-4">
                <div class="mb-3 flex items-center gap-3">
                    <span class="font-semibold text-gray-900"><?php echo customer_h($customer['name']); ?></span>
                    <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-500"><?php echo customer_h($customer['industry']); ?></span>
                    <?php if (!$selected_customer): ?>
                    <a href="<?php echo customer_h(admin_url('customers.php?customer=' . rawurlencode($customer['id']))); ?>"
                       class="ml-auto text-xs text-blue-600 hover:underline">进入客户工作台 →</a>
                    <?php endif; ?>
                </div>
                <div class="space-y-2">
                    <?php foreach ($customer['alerts'] as $alert):
                        $link = customer_alert_link($alert, $customer['id']);
                    ?>
                    <div class="flex items-start justify-between gap-4 rounded-lg bg-white px-4 py-2.5 shadow-sm">
                        <div class="flex items-start gap-2.5">
                            <i data-lucide="alert-triangle" class="mt-0.5 h-4 w-4 shrink-0 text-orange-500"></i>
                            <span class="text-sm text-gray-800"><?php echo customer_h($alert); ?></span>
                        </div>
                        <a href="<?php echo customer_h($link); ?>"
                           class="shrink-0 rounded-md border border-orange-200 bg-orange-50 px-2.5 py-1 text-xs font-medium text-orange-700 hover:bg-orange-100">
                            前往处理 →
                        </a>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </details>
    <?php endif; ?>

    <?php if ($selected_customer): ?>
        <section class="rounded-lg border border-gray-200 bg-white shadow-sm">
            <div class="flex flex-col gap-4 border-b border-gray-200 p-6 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <div class="text-sm font-semibold text-blue-600">当前客户运营工作台</div>
                    <h2 class="mt-2 text-3xl font-bold text-gray-900"><?php echo customer_h($selected_customer['name']); ?></h2>
                    <div class="mt-3 flex flex-wrap gap-2 text-sm text-gray-600">
                        <span class="rounded-full bg-gray-100 px-3 py-1"><?php echo customer_h($selected_customer['industry']); ?></span>
                        <span class="rounded-full bg-gray-100 px-3 py-1"><?php echo customer_h($selected_customer['package_label']); ?></span>
                        <span class="rounded-full bg-gray-100 px-3 py-1">负责人 <?php echo customer_h($selected_customer['owner']); ?></span>
                        <span class="rounded-full bg-gray-100 px-3 py-1">到期 <?php echo customer_h($selected_customer['contract_end_at']); ?></span>
                    </div>
                </div>
                <div class="w-full max-w-md rounded-lg bg-slate-50 p-4">
                    <div class="flex items-center justify-between text-sm font-semibold text-gray-600">
                        <span><?php echo customer_h($selected_customer['stage_label']); ?></span>
                        <span class="text-gray-900"><?php echo (int) $selected_customer['overall_pct']; ?>%</span>
                    </div>
                    <div class="mt-3 h-2 rounded-full bg-gray-200">
                        <div class="h-2 rounded-full bg-blue-600" style="width: <?php echo (int) $selected_customer['overall_pct']; ?>%;"></div>
                    </div>
                    <div class="mt-4 flex gap-2">
                        <a href="<?php echo customer_h(admin_url('customers.php?switch_customer=1#customer-switcher')); ?>" class="flex-1 rounded-md border border-gray-300 bg-white px-3 py-2 text-center text-sm font-semibold text-gray-700 hover:bg-gray-50">切换客户</a>
                        <a href="<?php echo customer_h(admin_url('customers.php?clear_customer=1')); ?>" class="flex-1 rounded-md bg-slate-900 px-3 py-2 text-center text-sm font-semibold text-white hover:bg-slate-800">退出客户</a>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 gap-4 p-6 md:grid-cols-2 xl:grid-cols-6">
                <?php foreach ($selected_customer['stages'] as $index => $stage): ?>
                    <?php
                    $link = $stage['link'] . (str_contains($stage['link'], '?') ? '&' : '?') . 'brand=' . rawurlencode($selected_customer['id']);
                    ?>
                    <a href="<?php echo customer_h(admin_url($link)); ?>" class="<?php echo $segment_classes[$stage['key']] ?? 'border-gray-200 bg-white'; ?> block rounded-lg border p-4 transition hover:-translate-y-0.5 hover:shadow-md">
                        <div class="flex items-center justify-between">
                            <span class="<?php echo $stage_badge_classes[$stage['key']] ?? 'bg-gray-600'; ?> rounded-full px-3 py-1 text-sm font-bold text-white"><?php echo sprintf('%02d', $index + 1); ?></span>
                            <span class="<?php echo $status_classes[$stage['status']] ?? 'bg-gray-50 text-gray-600 border-gray-200'; ?> rounded-full border px-2.5 py-1 text-xs font-semibold"><?php echo customer_h($status_names[$stage['status']] ?? $stage['status']); ?></span>
                        </div>
                        <div class="mt-5 text-xl font-bold text-gray-900"><?php echo customer_h($stage['name']); ?></div>
                        <div class="mt-1 text-sm font-semibold text-gray-600"><?php echo customer_h($stage['tool']); ?></div>
                        <p class="mt-3 min-h-[42px] text-sm text-gray-600"><?php echo customer_h($stage['note']); ?></p>
                        <div class="mt-4 h-2 rounded-full bg-white/80">
                            <div class="h-2 rounded-full <?php echo $stage['key'] === 'monitor' || $stage['key'] === 'renew' ? 'bg-orange-600' : ($stage['key'] === 'strategy' || $stage['key'] === 'execute' ? 'bg-emerald-600' : 'bg-blue-600'); ?>" style="width: <?php echo (int) $stage['pct']; ?>%;"></div>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <section class="rounded-lg border border-gray-200 bg-white shadow-sm lg:col-span-2">
                <div class="border-b border-gray-200 p-5">
                    <h3 class="text-xl font-bold text-gray-900">客户运营看板</h3>
                    <p class="mt-1 text-sm text-gray-500">这些信息后续由 SOP、监测、任务和复盘模块自动聚合。</p>
                </div>
                <div class="grid grid-cols-1 gap-4 p-5 md:grid-cols-3">
                    <div class="rounded-lg bg-blue-50 p-4">
                        <div class="text-sm font-semibold text-blue-700">竞品名单</div>
                        <div class="mt-3 flex flex-wrap gap-2">
                            <?php foreach ($selected_customer['competitors'] as $competitor): ?>
                                <span class="rounded-full bg-white px-3 py-1 text-sm font-semibold text-gray-700"><?php echo customer_h($competitor); ?></span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="rounded-lg bg-emerald-50 p-4">
                        <div class="text-sm font-semibold text-emerald-700">重点城市 · 官网</div>
                        <div class="mt-3 flex flex-wrap gap-2">
                            <?php foreach ($selected_customer['cities'] as $city): ?>
                                <span class="rounded-full bg-white px-3 py-1 text-sm font-semibold text-gray-700"><?php echo customer_h($city); ?></span>
                            <?php endforeach; ?>
                        </div>
                        <?php if (!empty($selected_customer['domain'])): ?>
                        <div class="mt-2 text-xs text-emerald-600"><?php echo customer_h($selected_customer['domain']); ?></div>
                        <?php endif; ?>
                    </div>
                    <?php
                    $daysLeft = (int) ceil((strtotime($selected_customer['contract_end_at']) - time()) / 86400);
                    $renewClass = $daysLeft <= 30 ? 'bg-red-50' : ($daysLeft <= 60 ? 'bg-orange-50' : 'bg-gray-50');
                    $renewTextClass = $daysLeft <= 30 ? 'text-red-700' : ($daysLeft <= 60 ? 'text-orange-700' : 'text-gray-700');
                    ?>
                    <div class="rounded-lg <?php echo $renewClass; ?> p-4">
                        <div class="text-sm font-semibold <?php echo $renewTextClass; ?>">合同信息</div>
                        <div class="mt-2 space-y-1.5">
                            <div class="flex items-center justify-between text-sm">
                                <span class="text-gray-500">签署</span>
                                <span class="font-medium text-gray-900"><?php echo customer_h($selected_customer['contract_start_at'] ?? '—'); ?></span>
                            </div>
                            <div class="flex items-center justify-between text-sm">
                                <span class="text-gray-500">到期</span>
                                <span class="font-semibold <?php echo $renewTextClass; ?>" id="contract-end-display">
                                    <?php echo customer_h($selected_customer['contract_end_at']); ?><?php echo $daysLeft <= 60 ? ' (T-' . $daysLeft . ')' : ''; ?>
                                </span>
                                <button type="button" onclick="showContractEdit()" class="ml-2 text-xs text-indigo-600 hover:text-indigo-800 underline" id="contract-edit-btn">修改</button>
                            </div>
                            <div id="contract-edit-row" class="hidden mt-1 flex items-center gap-2">
                                <input type="date" id="contract-end-input" value="<?php echo htmlspecialchars($selected_customer['contract_end_at'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                       class="rounded border border-gray-300 px-2 py-1 text-xs">
                                <button type="button" onclick="saveContractDate()" class="rounded bg-indigo-600 px-2 py-1 text-xs font-semibold text-white hover:bg-indigo-700">保存</button>
                                <button type="button" onclick="hideContractEdit()" class="rounded bg-gray-100 px-2 py-1 text-xs text-gray-600 hover:bg-gray-200">取消</button>
                                <span id="contract-save-msg" class="text-xs text-gray-500"></span>
                            </div>
                            <?php if (!empty($selected_customer['contract_amount'])): ?>
                            <div class="flex items-center justify-between text-sm">
                                <span class="text-gray-500">合同额</span>
                                <span class="font-medium text-gray-900">¥<?php echo number_format((int)$selected_customer['contract_amount']); ?></span>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($selected_customer['contact_name'])): ?>
                            <div class="mt-2 border-t border-white/60 pt-2 text-xs text-gray-500">
                                联系人：<span class="font-medium text-gray-700"><?php echo customer_h($selected_customer['contact_name']); ?></span>
                                <?php if (!empty($selected_customer['contact_phone'])): ?>
                                · <?php echo customer_h($selected_customer['contact_phone']); ?>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </section>

            <section class="rounded-lg border border-gray-200 bg-white shadow-sm">
                <div class="border-b border-gray-200 p-5">
                    <h3 class="text-xl font-bold text-gray-900">最新告警</h3>
                </div>
                <div class="space-y-3 p-5">
                    <?php foreach ($selected_customer['alerts'] as $alert): ?>
                        <div class="rounded-lg bg-amber-50 p-3 text-sm font-semibold text-amber-900"><?php echo customer_h($alert); ?></div>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="rounded-lg border border-gray-200 bg-white shadow-sm lg:col-span-3">
                <div class="border-b border-gray-200 p-5">
                    <h3 class="text-xl font-bold text-gray-900">待推进事项</h3>
                </div>
                <div class="grid grid-cols-1 gap-3 p-5 md:grid-cols-3">
                    <?php foreach ($selected_customer['pending'] as $item): ?>
                        <div class="flex items-start gap-3 rounded-lg border border-gray-200 p-4">
                            <i data-lucide="circle-check" class="mt-0.5 h-5 w-5 text-blue-600"></i>
                            <span class="text-sm font-semibold text-gray-700"><?php echo customer_h($item); ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="rounded-lg border border-gray-200 bg-white shadow-sm lg:col-span-3" id="kw-section">
                <div class="border-b border-gray-200 p-5">
                    <h3 class="text-xl font-bold text-gray-900">监测关键词</h3>
                </div>
                <div class="p-5 space-y-4">
                    <div id="kw-list" class="flex flex-wrap gap-2">
                        <?php if (empty($monitorKeywords)): ?>
                            <span id="kw-empty" class="text-sm text-gray-400">暂未添加关键词，将默认使用品牌名监测</span>
                        <?php else: ?>
                            <?php foreach ($monitorKeywords as $kw): ?>
                                <span class="kw-tag inline-flex items-center gap-1 rounded-full bg-blue-50 border border-blue-200 px-3 py-1 text-sm font-semibold text-blue-800" data-kw-id="<?php echo (int)$kw['id']; ?>">
                                    <?php echo customer_h($kw['keyword']); ?>
                                    <button type="button" class="ml-1 text-blue-400 hover:text-red-500" onclick="deleteKeyword(<?php echo (int)$kw['id']; ?>)" title="删除">
                                        <i data-lucide="x" class="h-3 w-3"></i>
                                    </button>
                                </span>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    <form id="kw-form" class="flex gap-2" onsubmit="saveKeyword(event)">
                        <input id="kw-input" type="text" maxlength="60" placeholder="输入关键词，例如：AI阅读辅导" class="flex-1 rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none">
                        <button type="submit" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700">添加</button>
                    </form>
                    <p class="text-xs text-gray-400">每天监测脚本会对这里的关键词向所有已配置的 AI 平台发起查询，检查品牌是否被提及。</p>
                </div>
            </section>

            <section class="rounded-lg border border-gray-200 bg-white shadow-sm lg:col-span-3" id="brand-facts-section">
                <div class="border-b border-gray-200 p-5 flex items-center justify-between">
                    <div>
                        <h3 class="text-xl font-bold text-gray-900">品牌知识库</h3>
                        <p class="mt-1 text-xs text-gray-400">AI 回答时对照此表检查信息准确度，建议填写核心参数和标准说法。</p>
                    </div>
                </div>
                <div class="p-5 space-y-4">
                    <div id="bf-list" class="space-y-2">
                        <?php if (empty($brandFacts)): ?>
                            <p id="bf-empty" class="text-sm text-gray-400">暂未录入品牌事实，添加后每日监测会自动验证 AI 回答的准确度。</p>
                        <?php else: ?>
                            <?php foreach ($brandFacts as $bf): ?>
                                <div class="bf-row flex items-start gap-3 rounded-lg border border-gray-200 bg-gray-50 px-4 py-3" data-fact-id="<?php echo (int)$bf['id']; ?>">
                                    <div class="flex-1 min-w-0">
                                        <span class="text-xs font-bold text-gray-500 uppercase"><?php echo customer_h($bf['fact_label']); ?></span>
                                        <?php if ($bf['is_core']): ?><span class="ml-1 rounded-full bg-blue-100 px-1.5 py-0.5 text-[10px] font-bold text-blue-700">核心</span><?php endif; ?>
                                        <p class="mt-0.5 text-sm text-gray-800"><?php echo customer_h($bf['fact_value']); ?></p>
                                    </div>
                                    <button type="button" class="shrink-0 text-gray-400 hover:text-red-500" onclick="deleteBrandFact(<?php echo (int)$bf['id']; ?>)" title="删除">
                                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                    </button>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    <form id="bf-form" class="grid grid-cols-1 gap-2 sm:grid-cols-[1fr_1fr_2fr_auto_auto]" onsubmit="saveBrandFact(event)">
                        <input id="bf-key"   type="text" maxlength="80"  placeholder="字段key (英文)" class="rounded-md border border-gray-300 px-3 py-2 text-sm">
                        <input id="bf-label" type="text" maxlength="100" placeholder="字段名（如：价格区间）" class="rounded-md border border-gray-300 px-3 py-2 text-sm">
                        <input id="bf-value" type="text" maxlength="500" placeholder="标准说法（如：￥3980/年）" class="rounded-md border border-gray-300 px-3 py-2 text-sm">
                        <label class="flex items-center gap-1 text-sm text-gray-600 whitespace-nowrap">
                            <input id="bf-core" type="checkbox" checked class="h-4 w-4"> 核心
                        </label>
                        <button type="submit" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700 whitespace-nowrap">添加</button>
                    </form>
                    <div class="grid grid-cols-2 gap-2 md:grid-cols-4">
                        <?php
                        $defaultFacts = [
                            ['key'=>'service_name','label'=>'服务/产品名称','value'=>''],
                            ['key'=>'price_range','label'=>'价格区间','value'=>''],
                            ['key'=>'target_audience','label'=>'目标人群','value'=>''],
                            ['key'=>'core_advantage','label'=>'核心优势','value'=>''],
                        ];
                        foreach ($defaultFacts as $df):
                            $alreadyExists = array_filter($brandFacts, fn($f) => $f['fact_key'] === $df['key']);
                            if (!empty($alreadyExists)) continue;
                        ?>
                            <button type="button" onclick="prefillFact('<?php echo customer_h($df['key']); ?>','<?php echo customer_h($df['label']); ?>')" class="rounded-lg border border-dashed border-gray-300 px-3 py-2 text-xs text-gray-500 hover:border-blue-300 hover:text-blue-600 text-left">
                                + <?php echo customer_h($df['label']); ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>
        </div>
    <?php endif; ?>

    <?php if (!$selected_customer): ?>
    <div id="customer-switcher">
        <section class="<?php echo $is_switching_customer ? 'ring-2 ring-blue-500 ring-offset-2' : ''; ?> rounded-lg border border-gray-200 bg-white shadow-sm">
            <div class="flex flex-col gap-4 border-b border-gray-200 p-5 lg:flex-row lg:items-center lg:justify-between">
                <div>
                    <h2 class="text-xl font-bold text-gray-900"><?php echo $is_switching_customer ? '选择要切换的客户' : '客户列表'; ?></h2>
                    <p class="mt-1 text-sm text-gray-500">
                        <?php echo $is_switching_customer ? '点击“设为当前”后，会切换顶部客户上下文，并进入该客户工作台。' : '登录后默认进入这里，先选客户，再进入各模块。'; ?>
                    </p>
                </div>
                <div class="grid grid-cols-1 gap-3 md:grid-cols-4">
                    <input id="customer-search" type="search" placeholder="搜索客户 / 行业 / 负责人" class="rounded-md border border-gray-300 px-3 py-2 text-sm md:col-span-2">
                    <select id="stage-filter" class="rounded-md border border-gray-300 px-3 py-2 text-sm">
                        <option value="">全部阶段</option>
                        <option value="diagnose">诊断</option>
                        <option value="strategy">策略</option>
                        <option value="execute">执行</option>
                        <option value="monitor">监测</option>
                    </select>
                    <select id="status-filter" class="rounded-md border border-gray-300 px-3 py-2 text-sm">
                        <option value="">全部状态</option>
                        <option value="active">服务中</option>
                        <option value="paused">暂停</option>
                    </select>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-5 py-3 text-left text-xs font-bold uppercase tracking-wide text-gray-500">客户</th>
                            <th class="px-5 py-3 text-left text-xs font-bold uppercase tracking-wide text-gray-500">行业 / 套餐</th>
                            <th class="px-5 py-3 text-left text-xs font-bold uppercase tracking-wide text-gray-500">当前阶段</th>
                            <th class="px-5 py-3 text-left text-xs font-bold uppercase tracking-wide text-gray-500">整体进度</th>
                            <th class="px-5 py-3 text-left text-xs font-bold uppercase tracking-wide text-gray-500">负责人</th>
                            <th class="px-5 py-3 text-right text-xs font-bold uppercase tracking-wide text-gray-500">操作</th>
                        </tr>
                    </thead>
                    <tbody id="customer-table-body" class="divide-y divide-gray-200 bg-white">
                        <?php foreach ($customers as $customer): ?>
                            <?php $isCurrentCustomer = isset($_SESSION['current_customer']['id']) && $_SESSION['current_customer']['id'] === $customer['id']; ?>
                            <tr class="customer-row <?php echo $isCurrentCustomer ? 'bg-blue-50/60' : 'hover:bg-gray-50'; ?>" data-search="<?php echo customer_h($customer['name'] . ' ' . $customer['industry'] . ' ' . $customer['owner']); ?>" data-stage="<?php echo customer_h($customer['stage_key']); ?>" data-status="<?php echo customer_h($customer['service_status']); ?>">
                                <td class="px-5 py-4">
                                    <div class="flex items-center gap-2">
                                        <span class="font-bold text-gray-900"><?php echo customer_h($customer['name']); ?></span>
                                        <?php if ($isCurrentCustomer): ?>
                                            <span class="rounded-full bg-blue-600 px-2 py-0.5 text-xs font-bold text-white">当前</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="mt-1 text-sm text-gray-500"><?php echo customer_h($customer['domain']); ?></div>
                                </td>
                                <td class="px-5 py-4">
                                    <div class="font-semibold text-gray-700"><?php echo customer_h($customer['industry']); ?></div>
                                    <div class="mt-1 inline-flex rounded-full bg-gray-100 px-2.5 py-1 text-xs font-semibold text-gray-600"><?php echo customer_h($customer['package_label']); ?></div>
                                </td>
                                <td class="px-5 py-4">
                                    <div class="font-semibold text-gray-800"><?php echo customer_h($customer['stage_label']); ?></div>
                                    <div class="mt-1 text-sm text-gray-500"><?php echo customer_h($customer['status_label']); ?></div>
                                </td>
                                <td class="px-5 py-4">
                                    <div class="flex items-center gap-3">
                                        <div class="h-2 w-28 rounded-full bg-gray-200">
                                            <div class="h-2 rounded-full bg-blue-600" style="width: <?php echo (int) $customer['overall_pct']; ?>%;"></div>
                                        </div>
                                        <span class="text-sm font-bold text-gray-900"><?php echo (int) $customer['overall_pct']; ?>%</span>
                                    </div>
                                </td>
                                <td class="px-5 py-4 font-semibold text-gray-700"><?php echo customer_h($customer['owner']); ?></td>
                                <td class="px-5 py-4 text-right">
                                    <div class="flex justify-end gap-2">
                                        <a href="<?php echo customer_h(admin_url('customers.php?customer=' . rawurlencode($customer['id']))); ?>" class="rounded-md border border-gray-300 px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">工作台</a>
                                        <a href="<?php echo customer_h(admin_url('customers.php?select=' . rawurlencode($customer['id']))); ?>" class="rounded-md bg-blue-600 px-3 py-2 text-sm font-semibold text-white hover:bg-blue-700">设为当前</a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
    <?php endif; ?>
</div>

<div id="customer-modal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-950/50 p-4" aria-hidden="true">
    <div class="w-full max-w-2xl overflow-hidden rounded-xl bg-white shadow-2xl">
        <div class="flex items-start justify-between border-b border-gray-200 p-6">
            <div>
                <h2 class="text-2xl font-bold text-gray-900">新建客户</h2>
                <p class="mt-1 text-sm text-gray-500">保存后会初始化首月 SOP 与监测批次。</p>
            </div>
            <button type="button" data-close-customer-modal class="rounded-md p-2 text-gray-500 hover:bg-gray-100 hover:text-gray-900" aria-label="关闭">
                <i data-lucide="x" class="h-5 w-5"></i>
            </button>
        </div>
        <form id="new-customer-form" class="max-h-[78vh] space-y-4 overflow-y-auto p-6" onsubmit="submitNewCustomer(event)">
            <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
                <label class="block md:col-span-2">
                    <span class="text-sm font-semibold text-gray-700">客户名称 <span class="text-red-500">*</span></span>
                    <input name="name" type="text" class="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm" placeholder="例如：湖南文韵爱阅读" required>
                </label>
                <label class="block">
                    <span class="text-sm font-semibold text-gray-700">行业</span>
                    <select name="industry" class="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm">
                        <option>教培 / 知识付费</option>
                        <option>B2B SaaS / 企业服务</option>
                        <option>医疗 / 健康</option>
                        <option>消费品 / 美妆 / 食品</option>
                        <option>本地生活 / 餐饮</option>
                    </select>
                </label>
                <label class="block">
                    <span class="text-sm font-semibold text-gray-700">套餐</span>
                    <select name="package_tier" class="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm">
                        <option value="growth">增长版</option>
                        <option value="dominate">主导版</option>
                        <option value="lite">轻量版</option>
                    </select>
                </label>
                <label class="block">
                    <span class="text-sm font-semibold text-gray-700">官网域名</span>
                    <input name="domain" type="text" class="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm" placeholder="例如：example.com（不含 https://）">
                </label>
                <label class="block">
                    <span class="text-sm font-semibold text-gray-700">负责人</span>
                    <input name="owner" type="text" class="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm" placeholder="例如：张顾问">
                </label>
            </div>
            <div class="rounded-lg border border-gray-200 p-4">
                <p class="mb-3 text-xs font-bold uppercase tracking-wide text-gray-500">合同信息</p>
                <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
                    <label class="block">
                        <span class="text-sm font-semibold text-gray-700">签署日期</span>
                        <input name="contract_start_date" type="date" class="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm">
                    </label>
                    <label class="block">
                        <span class="text-sm font-semibold text-gray-700">到期日期</span>
                        <input name="contract_end_date" type="date" class="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm">
                    </label>
                    <label class="block">
                        <span class="text-sm font-semibold text-gray-700">合同金额（元）</span>
                        <input name="contract_amount" type="number" min="0" step="100" class="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm" placeholder="例如：58000">
                    </label>
                </div>
            </div>
            <div class="rounded-lg border border-gray-200 p-4">
                <p class="mb-3 text-xs font-bold uppercase tracking-wide text-gray-500">客户联系人</p>
                <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
                    <label class="block">
                        <span class="text-sm font-semibold text-gray-700">联系人姓名</span>
                        <input name="contact_name" type="text" class="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm" placeholder="例如：李总">
                    </label>
                    <label class="block">
                        <span class="text-sm font-semibold text-gray-700">联系方式（电话 / 邮箱）</span>
                        <input name="contact_phone" type="text" class="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm" placeholder="例如：138xxxx0000 或 li@example.com">
                    </label>
                </div>
            </div>
            <!-- 竞品名单（新建时纯前端暂存，创建客户后再写库） -->
            <div class="rounded-lg border border-gray-200 p-4">
                <p class="mb-3 text-xs font-bold uppercase tracking-wide text-gray-500">竞品名单
                    <span class="ml-1 font-normal text-gray-400 normal-case">（保存后监测脚本会自动追踪）</span>
                </p>
                <div id="new-cmp-list" class="flex flex-wrap gap-2 mb-3 min-h-[28px]">
                    <span id="new-cmp-empty" class="text-sm text-gray-400">暂无竞品，添加后监测脚本会自动追踪</span>
                </div>
                <div class="flex gap-2">
                    <input type="text" id="new-cmp-input" placeholder="输入竞品名，回车添加"
                           class="flex-1 rounded-md border border-gray-300 px-3 py-1.5 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                    <button type="button" onclick="addNewCmp()" class="rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-indigo-700">添加</button>
                </div>
            </div>
            <label class="block">
                <span class="text-sm font-semibold text-gray-700">重点城市</span>
                <input name="cities" type="text" class="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm" placeholder="例如：长沙、上海、全国">
            </label>
            <div class="flex flex-col gap-3 pt-2 sm:flex-row sm:items-center sm:justify-between">
                <p class="text-xs leading-5 text-gray-500">保存后初始化首月 SOP，并把 industry、domain、competitor_terms、contact_name 作为所有模块的数据上下文。</p>
                <button type="submit" class="shrink-0 rounded-md bg-slate-900 px-5 py-3 text-sm font-semibold text-white hover:bg-slate-800">
                    创建客户并初始化
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    // ── 品牌知识库管理 ──────────────────────────────────────────────────────────
    const _bfCustomerId = <?php echo json_encode($selected_customer['id'] ?? ''); ?>;
    const _bfCsrf = <?php echo json_encode(generate_csrf_token()); ?>;

    function renderBrandFacts(facts) {
        const list = document.getElementById('bf-list');
        if (!list) return;
        if (!facts || facts.length === 0) {
            list.innerHTML = '<p id="bf-empty" class="text-sm text-gray-400">暂未录入品牌事实，添加后每日监测会自动验证 AI 回答的准确度。</p>';
            return;
        }
        list.innerHTML = facts.map(f => `
            <div class="bf-row flex items-start gap-3 rounded-lg border border-gray-200 bg-gray-50 px-4 py-3" data-fact-id="${f.id}">
                <div class="flex-1 min-w-0">
                    <span class="text-xs font-bold text-gray-500 uppercase">${f.fact_label.replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]))}</span>
                    ${f.is_core ? '<span class="ml-1 rounded-full bg-blue-100 px-1.5 py-0.5 text-[10px] font-bold text-blue-700">核心</span>' : ''}
                    <p class="mt-0.5 text-sm text-gray-800">${f.fact_value.replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]))}</p>
                </div>
                <button type="button" class="shrink-0 text-gray-400 hover:text-red-500" onclick="deleteBrandFact(${f.id})" title="删除">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </div>`).join('');
    }

    async function saveBrandFact(event) {
        event.preventDefault();
        const key   = (document.getElementById('bf-key')?.value || '').trim();
        const label = (document.getElementById('bf-label')?.value || '').trim();
        const value = (document.getElementById('bf-value')?.value || '').trim();
        const core  = document.getElementById('bf-core')?.checked ? '1' : '';
        if (!key || !label || !value || !_bfCustomerId) return;
        const fd = new FormData();
        fd.append('action','save_brand_fact'); fd.append('_csrf',_bfCsrf);
        fd.append('customer_id',_bfCustomerId); fd.append('fact_key',key);
        fd.append('fact_label',label); fd.append('fact_value',value); fd.append('is_core',core);
        try {
            const res = await fetch(window.location.pathname,{method:'POST',body:fd});
            const data = await res.json();
            if (data.ok) {
                renderBrandFacts(data.facts);
                document.getElementById('bf-key').value='';
                document.getElementById('bf-label').value='';
                document.getElementById('bf-value').value='';
            } else { alert(data.error||'保存失败'); }
        } catch(e){ alert('网络错误'); }
    }

    async function deleteBrandFact(factId) {
        if (!_bfCustomerId) return;
        const fd = new FormData();
        fd.append('action','delete_brand_fact'); fd.append('_csrf',_bfCsrf);
        fd.append('customer_id',_bfCustomerId); fd.append('fact_id',factId);
        try {
            const res = await fetch(window.location.pathname,{method:'POST',body:fd});
            const data = await res.json();
            if (data.ok) { renderBrandFacts(data.facts); }
            else { alert(data.error||'删除失败'); }
        } catch(e){ alert('网络错误'); }
    }

    function prefillFact(key, label) {
        const ki = document.getElementById('bf-key');
        const li = document.getElementById('bf-label');
        if (ki) ki.value = key;
        if (li) li.value = label;
        document.getElementById('bf-value')?.focus();
    }

    // ── 监测关键词管理 ──────────────────────────────────────────────────────────
    const _kwCustomerId = <?php echo json_encode($selected_customer['id'] ?? ''); ?>;
    const _kwCsrf = <?php echo json_encode(generate_csrf_token()); ?>;

    function renderKeywords(keywords) {
        const list = document.getElementById('kw-list');
        if (!list) return;
        if (!keywords || keywords.length === 0) {
            list.innerHTML = '<span id="kw-empty" class="text-sm text-gray-400">暂未添加关键词，将默认使用品牌名监测</span>';
            return;
        }
        list.innerHTML = keywords.map(kw => `
            <span class="kw-tag inline-flex items-center gap-1 rounded-full bg-blue-50 border border-blue-200 px-3 py-1 text-sm font-semibold text-blue-800" data-kw-id="${kw.id}">
                ${kw.keyword.replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]))}
                <button type="button" class="ml-1 text-blue-400 hover:text-red-500" onclick="deleteKeyword(${kw.id})" title="删除">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </span>`).join('');
    }

    async function saveKeyword(event) {
        event.preventDefault();
        const input = document.getElementById('kw-input');
        const kw = (input.value || '').trim();
        if (!kw || !_kwCustomerId) return;
        const fd = new FormData();
        fd.append('action', 'save_monitor_keyword');
        fd.append('_csrf', _kwCsrf);
        fd.append('customer_id', _kwCustomerId);
        fd.append('keyword', kw);
        try {
            const res = await fetch(window.location.pathname, {method: 'POST', body: fd});
            const data = await res.json();
            if (data.ok) { renderKeywords(data.keywords); input.value = ''; }
            else { alert(data.error || '保存失败'); }
        } catch(e) { alert('网络错误'); }
    }

    async function deleteKeyword(kwId) {
        if (!_kwCustomerId) return;
        const fd = new FormData();
        fd.append('action', 'delete_monitor_keyword');
        fd.append('_csrf', _kwCsrf);
        fd.append('customer_id', _kwCustomerId);
        fd.append('keyword_id', kwId);
        try {
            const res = await fetch(window.location.pathname, {method: 'POST', body: fd});
            const data = await res.json();
            if (data.ok) { renderKeywords(data.keywords); }
            else { alert(data.error || '删除失败'); }
        } catch(e) { alert('网络错误'); }
    }

    // ── 竞品名称管理 ─────────────────────────────────────────────────────────
    const _cmpCsrf       = <?php echo json_encode(generate_csrf_token()); ?>;
    const _cmpCustomerId = <?php echo json_encode($selected_customer['id'] ?? ''); ?>;

    function renderCmp(list) {
        const el = document.getElementById('cmp-list');
        if (!el) return;
        if (!list || list.length === 0) {
            el.innerHTML = '<span id="cmp-empty" class="text-sm text-gray-400">暂无竞品，添加后监测脚本会自动追踪</span>';
            return;
        }
        el.innerHTML = list.map(c => `
            <span class="inline-flex items-center gap-1 rounded-full bg-orange-50 border border-orange-200 px-3 py-1 text-sm text-orange-800" data-cmp-id="${c.id}">
                ${c.competitor}
                <button type="button" onclick="deleteCmp(${c.id}, this)" class="text-orange-400 hover:text-red-600 ml-1 font-bold">×</button>
            </span>`).join('');
    }

    async function addCmp() {
        const input = document.getElementById('cmp-input');
        const name = input.value.trim();
        if (!name) return;
        const fd = new FormData();
        fd.append('action', 'save_competitor');
        fd.append('_csrf', _cmpCsrf);
        fd.append('customer_id', _cmpCustomerId);
        fd.append('competitor', name);
        try {
            const res = await fetch(window.location.pathname, {method: 'POST', body: fd});
            const data = await res.json();
            if (data.ok) { renderCmp(data.competitors); input.value = ''; }
            else { alert(data.error || '添加失败'); }
        } catch(e) { alert('网络错误'); }
    }

    async function deleteCmp(cmpId, btn) {
        if (!confirm('确认删除该竞品？')) return;
        const fd = new FormData();
        fd.append('action', 'delete_competitor');
        fd.append('_csrf', _cmpCsrf);
        fd.append('customer_id', _cmpCustomerId);
        fd.append('competitor_id', cmpId);
        try {
            const res = await fetch(window.location.pathname, {method: 'POST', body: fd});
            const data = await res.json();
            if (data.ok) { renderCmp(data.competitors); }
            else { alert(data.error || '删除失败'); }
        } catch(e) { alert('网络错误'); }
    }

    document.getElementById('cmp-input')?.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); addCmp(); } });

    // ── 新建客户弹窗：竞品纯前端暂存（不调服务端，客户创建后再写库）──────────
    const _newCmpList = [];
    function renderNewCmp() {
        const el = document.getElementById('new-cmp-list');
        if (!el) return;
        if (_newCmpList.length === 0) {
            el.innerHTML = '<span id="new-cmp-empty" class="text-sm text-gray-400">暂无竞品，添加后监测脚本会自动追踪</span>';
            return;
        }
        el.innerHTML = _newCmpList.map((name, idx) => `
            <span class="inline-flex items-center gap-1 rounded-full bg-orange-50 border border-orange-200 px-3 py-1 text-sm text-orange-800">
                ${name.replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]))}
                <button type="button" onclick="removeNewCmp(${idx})" class="text-orange-400 hover:text-red-600 ml-1 font-bold">×</button>
            </span>`).join('');
    }
    function addNewCmp() {
        const input = document.getElementById('new-cmp-input');
        if (!input) return;
        const name = input.value.trim();
        if (!name) return;
        if (_newCmpList.includes(name)) { input.value = ''; return; }
        _newCmpList.push(name);
        renderNewCmp();
        input.value = '';
    }
    function removeNewCmp(idx) {
        _newCmpList.splice(idx, 1);
        renderNewCmp();
    }
    document.getElementById('new-cmp-input')?.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); addNewCmp(); } });

    async function submitNewCustomer(event) {
        event.preventDefault();
        const form = document.getElementById('new-customer-form');
        const fd = new FormData(form);
        fd.append('action', 'create_customer');
        fd.append('_csrf', _bfCsrf);
        fd.append('competitors', _newCmpList.join('\n'));
        const submitBtn = form.querySelector('button[type="submit"]');
        submitBtn.disabled = true;
        submitBtn.textContent = '创建中…';
        try {
            const res = await fetch(window.location.pathname, { method: 'POST', body: fd });
            const data = await res.json();
            if (data.ok) {
                window.location.href = window.location.pathname + '?customer=' + encodeURIComponent(data.customer_id);
            } else {
                alert('创建失败：' + (data.error || '未知错误'));
            }
        } catch (e) {
            alert('网络错误，请重试');
        } finally {
            submitBtn.disabled = false;
            submitBtn.textContent = '创建客户并初始化';
        }
    }

    (function() {
        const searchInput = document.getElementById('customer-search');
        const stageFilter = document.getElementById('stage-filter');
        const statusFilter = document.getElementById('status-filter');
        const rows = Array.from(document.querySelectorAll('.customer-row'));
        const modal = document.getElementById('customer-modal');
        const openButtons = Array.from(document.querySelectorAll('[data-open-customer-modal]'));
        const closeButtons = Array.from(document.querySelectorAll('[data-close-customer-modal]'));

        function openModal() {
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            modal.setAttribute('aria-hidden', 'false');
            document.body.classList.add('overflow-hidden');
        }

        function closeModal() {
            modal.classList.add('hidden');
            modal.classList.remove('flex');
            modal.setAttribute('aria-hidden', 'true');
            document.body.classList.remove('overflow-hidden');
        }

        function applyFilters() {
            const query = (searchInput.value || '').trim().toLowerCase();
            const stage = stageFilter.value;
            const status = statusFilter.value;

            rows.forEach((row) => {
                const matchesQuery = !query || (row.dataset.search || '').toLowerCase().includes(query);
                const matchesStage = !stage || row.dataset.stage === stage;
                const matchesStatus = !status || row.dataset.status === status;
                row.style.display = matchesQuery && matchesStage && matchesStatus ? '' : 'none';
            });
        }

        [searchInput, stageFilter, statusFilter].forEach((el) => el && el.addEventListener('input', applyFilters));
        openButtons.forEach((button) => button.addEventListener('click', openModal));
        closeButtons.forEach((button) => button.addEventListener('click', closeModal));
        modal && modal.addEventListener('click', (event) => {
            if (event.target === modal) {
                closeModal();
            }
        });
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && modal && !modal.classList.contains('hidden')) {
                closeModal();
            }
        });
    })();
</script>

<script>
const _contractCid  = <?php echo json_encode($selected_customer['id'] ?? ''); ?>;
const _contractCsrf = <?php echo json_encode(generate_csrf_token()); ?>;
function showContractEdit() {
    document.getElementById('contract-edit-row').classList.remove('hidden');
    document.getElementById('contract-edit-btn').classList.add('hidden');
}
function hideContractEdit() {
    document.getElementById('contract-edit-row').classList.add('hidden');
    document.getElementById('contract-edit-btn').classList.remove('hidden');
    document.getElementById('contract-save-msg').textContent = '';
}
async function saveContractDate() {
    const date = document.getElementById('contract-end-input').value;
    if (!date) return;
    const msg = document.getElementById('contract-save-msg');
    msg.textContent = '保存中…';
    const fd = new FormData();
    fd.append('action', 'save_contract_date');
    fd.append('_csrf', _contractCsrf);
    fd.append('customer_id', _contractCid);
    fd.append('contract_end_date', date);
    const res = await fetch(window.location.pathname, {method:'POST', body: fd});
    const data = await res.json();
    if (data.ok) {
        msg.textContent = '已保存';
        document.getElementById('contract-end-display').textContent = date;
        setTimeout(hideContractEdit, 1200);
    } else {
        msg.textContent = '失败: ' + (data.error || '');
    }
}
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
