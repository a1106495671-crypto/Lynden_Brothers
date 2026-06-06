<?php
/**
 * 董逻辑 GEO 交付后台 - 策略 / SOP 引擎
 */

define('FEISHU_TREASURE', true);
session_start();

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/database_admin.php';

require_admin_login();

function sop_h($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function sop_current_customer_id(array $customer): string {
    return (string) ($customer['id'] ?? $customer['customer_id'] ?? '');
}

function sop_compact_text(string $text, int $limit = 260): string {
    $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    if (mb_strlen($text, 'UTF-8') <= $limit) {
        return $text;
    }
    return mb_substr($text, 0, $limit, 'UTF-8') . '...';
}

function sop_markdown_inline(string $text): string {
    $text = sop_h($text);
    $text = preg_replace('/`([^`]+)`/u', '<code class="rounded bg-slate-100 px-1.5 py-0.5 text-[0.9em] text-slate-700">$1</code>', $text) ?? $text;
    $text = preg_replace('/\*\*([^*]+)\*\*/u', '<strong class="font-semibold text-slate-950">$1</strong>', $text) ?? $text;
    return $text;
}

function sop_markdown_cells(string $line): array {
    $line = trim($line);
    $line = trim($line, '|');
    return array_map(static fn($cell) => trim($cell), explode('|', $line));
}

function sop_render_markdown(string $markdown): string {
    $lines = preg_split('/\R/u', trim($markdown)) ?: [];
    $html = [];
    $inUl = false;
    $inOl = false;
    $count = count($lines);

    $closeLists = static function () use (&$html, &$inUl, &$inOl): void {
        if ($inUl) {
            $html[] = '</ul>';
            $inUl = false;
        }
        if ($inOl) {
            $html[] = '</ol>';
            $inOl = false;
        }
    };

    for ($i = 0; $i < $count; $i++) {
        $line = rtrim((string) $lines[$i]);
        $trim = trim($line);
        if ($trim === '') {
            $closeLists();
            continue;
        }

        if (str_contains($trim, '|') && isset($lines[$i + 1]) && preg_match('/^\s*\|?\s*:?-{3,}:?\s*(\|\s*:?-{3,}:?\s*)+\|?\s*$/', (string) $lines[$i + 1])) {
            $closeLists();
            $headers = sop_markdown_cells($trim);
            $i += 2;
            $rows = [];
            while ($i < $count && str_contains((string) $lines[$i], '|') && trim((string) $lines[$i]) !== '') {
                $rows[] = sop_markdown_cells((string) $lines[$i]);
                $i++;
            }
            $i--;
            $html[] = '<div class="my-5 overflow-x-auto rounded-lg border border-slate-200 bg-white"><table class="min-w-full divide-y divide-slate-200 text-sm">';
            $html[] = '<thead class="bg-slate-50"><tr>';
            foreach ($headers as $cell) {
                $html[] = '<th class="px-4 py-3 text-left font-semibold text-slate-700">' . sop_markdown_inline($cell) . '</th>';
            }
            $html[] = '</tr></thead><tbody class="divide-y divide-slate-100">';
            foreach ($rows as $row) {
                $html[] = '<tr class="align-top">';
                foreach ($headers as $idx => $_) {
                    $html[] = '<td class="px-4 py-3 leading-6 text-slate-700">' . sop_markdown_inline($row[$idx] ?? '') . '</td>';
                }
                $html[] = '</tr>';
            }
            $html[] = '</tbody></table></div>';
            continue;
        }

        if (preg_match('/^(#{1,4})\s+(.+)$/u', $trim, $m)) {
            $closeLists();
            $level = strlen($m[1]);
            $classes = [
                1 => 'mt-1 text-2xl font-bold text-slate-950',
                2 => 'mt-8 border-t border-slate-200 pt-6 text-xl font-bold text-slate-950 first:mt-0 first:border-t-0 first:pt-0',
                3 => 'mt-6 text-base font-bold text-slate-900',
                4 => 'mt-5 text-sm font-bold text-slate-800',
            ];
            $html[] = '<h' . $level . ' class="' . $classes[$level] . '">' . sop_markdown_inline($m[2]) . '</h' . $level . '>';
            continue;
        }

        if (preg_match('/^\d+\.\s+(.+)$/u', $trim, $m)) {
            if ($inUl) {
                $html[] = '</ul>';
                $inUl = false;
            }
            if (!$inOl) {
                $html[] = '<ol class="my-4 list-decimal space-y-2 pl-6 text-sm leading-7 text-slate-700">';
                $inOl = true;
            }
            $html[] = '<li>' . sop_markdown_inline($m[1]) . '</li>';
            continue;
        }

        if (preg_match('/^[-*]\s+(.+)$/u', $trim, $m)) {
            if ($inOl) {
                $html[] = '</ol>';
                $inOl = false;
            }
            if (!$inUl) {
                $html[] = '<ul class="my-4 list-disc space-y-2 pl-6 text-sm leading-7 text-slate-700">';
                $inUl = true;
            }
            $html[] = '<li>' . sop_markdown_inline($m[1]) . '</li>';
            continue;
        }

        $closeLists();
        $html[] = '<p class="my-3 text-sm leading-7 text-slate-700">' . sop_markdown_inline($trim) . '</p>';
    }

    $closeLists();
    return implode("\n", $html);
}

function sop_ensure_strategy_analysis_table(PDO $db): void {
    $db->exec("
        CREATE TABLE IF NOT EXISTS sop_strategy_analyses (
            id BIGSERIAL PRIMARY KEY,
            customer_id VARCHAR(80) NOT NULL DEFAULT '',
            brand_name VARCHAR(200) NOT NULL DEFAULT '',
            recommended_scenario VARCHAR(40) NOT NULL DEFAULT '',
            analysis_md TEXT NOT NULL DEFAULT '',
            input_snapshot JSONB NOT NULL DEFAULT '{}'::jsonb,
            model_used VARCHAR(200) NOT NULL DEFAULT '',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_sop_strategy_analyses_customer ON sop_strategy_analyses(customer_id, created_at DESC)");
}

function sop_strategy_prompt_template(): string {
    $file = dirname(__DIR__) . '/prompts/sop_strategy_analysis.md';
    if (is_file($file)) {
        $text = file_get_contents($file);
        if (is_string($text) && trim($text) !== '') {
            return $text;
        }
    }
    return "你是 GEO 品牌策略负责人。请基于当前品牌真实数据包输出 Markdown 策略分析，不要编造数据。\n\n{{REALITY_JSON}}";
}

function sop_fetch_reality_snapshot(PDO $db, array $customer): array {
    $customerId = sop_current_customer_id($customer);
    $brandName  = (string) ($customer['name'] ?? '');
    $industry   = (string) ($customer['industry'] ?? '');
    $facts      = [];
    $p0Questions = [];
    $weakKeywords = [];
    $weakSignals = [];
    $brandKnowledge = [];
    $geoMaterials = [];
    $contentQueue = [];
    $monitorSamples = [];
    $diagnosisActions = [];
    $monitor = ['total' => 0, 'mentioned' => 0, 'mention_rate' => null, 'platform_count' => 0];
    $humanTasks = ['pending' => 0, 'in_progress' => 0, 'done' => 0];
    $contentQueuePending = 0;

    if ($customerId !== '') {
        try {
            $stmt = $db->prepare("SELECT fact_key, fact_value FROM geo_brand_facts WHERE customer_id = ?");
            $stmt->execute([$customerId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $facts[$row['fact_key']] = $row['fact_value'];
            }
            $brandName = $facts['brand_name'] ?? $brandName;
            $industry = $facts['industry'] ?? $industry;
        } catch (Throwable $e) {}

        try {
            $stmt = $db->prepare("
                SELECT question, dimension, suggested_action
                FROM geo_intent_questions
                WHERE customer_id = ? AND priority = 'P0' AND covered = false
                ORDER BY created_at ASC
                LIMIT 8
            ");
            $stmt->execute([$customerId]);
            $p0Questions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {}

        try {
            $stmt = $db->prepare("
                SELECT query_text,
                       ROUND(100.0 * SUM(CASE WHEN brand_mentioned THEN 1 ELSE 0 END) / COUNT(*), 1) AS mention_rate,
                       COUNT(*) AS total
                FROM geo_monitor_records
                WHERE customer_id = ? AND queried_at >= CURRENT_DATE - INTERVAL '30 days'
                GROUP BY query_text
                HAVING ROUND(100.0 * SUM(CASE WHEN brand_mentioned THEN 1 ELSE 0 END) / COUNT(*), 1) < 30
                ORDER BY mention_rate ASC, total DESC
                LIMIT 8
            ");
            $stmt->execute([$customerId]);
            $weakKeywords = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {}

        try {
            $stmt = $db->prepare("
                SELECT COUNT(*) AS total,
                       COUNT(*) FILTER (WHERE brand_mentioned = TRUE) AS mentioned,
                       COUNT(DISTINCT provider) AS platform_count
                FROM geo_monitor_records
                WHERE customer_id = ? AND queried_at >= CURRENT_DATE - INTERVAL '30 days'
            ");
            $stmt->execute([$customerId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $monitor['total'] = (int) ($row['total'] ?? 0);
            $monitor['mentioned'] = (int) ($row['mentioned'] ?? 0);
            $monitor['platform_count'] = (int) ($row['platform_count'] ?? 0);
            $monitor['mention_rate'] = $monitor['total'] > 0 ? round($monitor['mentioned'] * 100 / $monitor['total'], 1) : null;
        } catch (Throwable $e) {}

        try {
            $stmt = $db->prepare("
                SELECT s.signal_key, d.name, s.score
                FROM geo_diagnosis_signal_scores s
                JOIN geo_diagnosis_signal_definitions d ON d.signal_key = s.signal_key
                WHERE s.diagnosis_id = (
                    SELECT r.id
                    FROM geo_diagnosis_runs r
                    JOIN geo_diagnosis_brands b ON b.id = r.brand_id
                    WHERE b.name = ?
                    ORDER BY r.created_at DESC
                    LIMIT 1
                ) AND s.score < 60
                ORDER BY s.score ASC
                LIMIT 6
            ");
            $stmt->execute([$brandName]);
            $weakSignals = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {}

        try {
            $stmt = $db->prepare("
                SELECT category, title, content, source, citability_score
                FROM geo_brand_knowledge
                WHERE customer_id = ?
                ORDER BY citability_score DESC, updated_at DESC, id DESC
                LIMIT 12
            ");
            $stmt->execute([$customerId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $brandKnowledge[] = [
                    'category' => $row['category'],
                    'title' => $row['title'],
                    'content' => sop_compact_text((string) $row['content'], 240),
                    'source' => $row['source'],
                    'citability_score' => (int) $row['citability_score'],
                ];
            }
        } catch (Throwable $e) {}

        try {
            $stmt = $db->prepare("
                SELECT material_type, title, notes, content_json, status
                FROM geo_materials
                WHERE customer_id = ? AND status = 'active'
                ORDER BY updated_at DESC, id DESC
                LIMIT 8
            ");
            $stmt->execute([$customerId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $geoMaterials[] = [
                    'material_type' => $row['material_type'],
                    'title' => $row['title'],
                    'notes' => sop_compact_text((string) $row['notes'], 160),
                    'content' => sop_compact_text((string) $row['content_json'], 240),
                ];
            }
        } catch (Throwable $e) {}

        try {
            $stmt = $db->prepare("
                SELECT week_num, platform, keyword, title, angle, content_format, priority, status, article_title
                FROM geo_content_queue
                WHERE customer_id = ?
                ORDER BY created_at DESC, id DESC
                LIMIT 12
            ");
            $stmt->execute([$customerId]);
            $contentQueue = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {}

        try {
            $stmt = $db->prepare("
                SELECT provider, query_text, brand_mentioned, mention_position, mention_depth, accuracy_score, response_snippet, queried_at::text AS queried_at
                FROM geo_monitor_records
                WHERE customer_id = ?
                ORDER BY queried_at DESC, id DESC
                LIMIT 8
            ");
            $stmt->execute([$customerId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $monitorSamples[] = [
                    'provider' => $row['provider'],
                    'query_text' => sop_compact_text((string) $row['query_text'], 120),
                    'brand_mentioned' => (bool) $row['brand_mentioned'],
                    'mention_position' => $row['mention_position'],
                    'mention_depth' => $row['mention_depth'],
                    'accuracy_score' => $row['accuracy_score'],
                    'response_snippet' => sop_compact_text((string) $row['response_snippet'], 220),
                    'queried_at' => $row['queried_at'],
                ];
            }
        } catch (Throwable $e) {}

        try {
            $stmt = $db->prepare("
                SELECT a.signal_key, a.priority, a.action_text, a.estimated_impact, a.sku_id
                FROM geo_diagnosis_actions a
                WHERE a.diagnosis_id = (
                    SELECT r.id
                    FROM geo_diagnosis_runs r
                    JOIN geo_diagnosis_brands b ON b.id = r.brand_id
                    WHERE b.name = ?
                    ORDER BY r.created_at DESC
                    LIMIT 1
                )
                ORDER BY a.priority ASC, a.estimated_impact DESC NULLS LAST
                LIMIT 8
            ");
            $stmt->execute([$brandName]);
            $diagnosisActions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {}

        try {
            $stmt = $db->prepare("
                SELECT status, COUNT(*) AS c
                FROM sop_dispatched_tasks
                WHERE customer_id = ?
                GROUP BY status
            ");
            $stmt->execute([$customerId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $humanTasks[$row['status']] = (int) $row['c'];
            }
        } catch (Throwable $e) {}

        try {
            $stmt = $db->prepare("SELECT COUNT(*) FROM geo_content_queue WHERE customer_id = ? AND status IN ('pending','processing')");
            $stmt->execute([$customerId]);
            $contentQueuePending = (int) $stmt->fetchColumn();
        } catch (Throwable $e) {}
    }

    return [
        'customer_id' => $customerId,
        'brand_name' => $brandName,
        'industry' => $industry,
        'facts' => $facts,
        'p0_questions' => $p0Questions,
        'weak_keywords' => $weakKeywords,
        'weak_signals' => $weakSignals,
        'brand_knowledge' => $brandKnowledge,
        'geo_materials' => $geoMaterials,
        'content_queue' => $contentQueue,
        'monitor_samples' => $monitorSamples,
        'diagnosis_actions' => $diagnosisActions,
        'monitor' => $monitor,
        'human_tasks' => $humanTasks,
        'content_queue_pending' => $contentQueuePending,
    ];
}

// ── SOP 派发任务 POST 处理 ──────────────────────────────────────────────────
$_dispatchMessage = '';
$_dispatchError   = '';
$_analysisMessage = '';
$_analysisError   = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'sop_dispatch_task') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $_dispatchError = 'CSRF验证失败';
    } else {
        $currentCustomer = $_SESSION['current_customer'] ?? [];
        $customerId      = (string) ($currentCustomer['id'] ?? '');
        $sopScenario     = trim((string) ($_POST['sop_scenario']   ?? ''));
        $sopCode         = trim((string) ($_POST['sop_code']       ?? ''));
        $sopName         = trim((string) ($_POST['sop_name']       ?? ''));
        $sopKpi          = trim((string) ($_POST['sop_kpi']        ?? ''));
        $sopDeliverable  = trim((string) ($_POST['sop_deliverable'] ?? ''));
        $sopOwner        = trim((string) ($currentCustomer['owner'] ?? '客户成功'));
        $dueOffset       = (int) ($_POST['due_days'] ?? 7);
        $dueDate         = date('Y-m-d', strtotime("+{$dueOffset} days"));

        if ($sopCode === '' || $sopName === '') {
            $_dispatchError = '派发参数不完整';
        } else {
            // 防止重复派发：同客户同 SOP 节点且状态不为 done 时不再新增
            $exists = $db->prepare("SELECT id FROM sop_dispatched_tasks WHERE customer_id=? AND sop_code=? AND status != 'done' LIMIT 1");
            $exists->execute([$customerId, $sopCode]);
            if ($exists->fetch()) {
                $_dispatchError = "节点 {$sopCode} 已有进行中的任务，无需重复派发";
            } else {
                $stmt = $db->prepare("
                    INSERT INTO sop_dispatched_tasks
                        (customer_id, sop_scenario, sop_code, name, kpi, deliverable, owner, due_date)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$customerId, $sopScenario, $sopCode, $sopName, $sopKpi, $sopDeliverable, $sopOwner, $dueDate]);
                session_write_close();
                header('Location: ' . admin_url('tasks.php?task_type=human&dispatched=' . urlencode($sopName)));
                exit;
            }
        }
    }
}

// ── SOP 节点状态更新（AJAX）──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_node_status') {
    header('Content-Type: application/json');
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        echo json_encode(['ok' => false, 'error' => 'CSRF']);
        exit;
    }
    $customerId = sop_current_customer_id($_SESSION['current_customer'] ?? []);
    $scenario   = trim((string) ($_POST['scenario']  ?? ''));
    $nodeCode   = trim((string) ($_POST['node_code'] ?? ''));
    $newStatus  = trim((string) ($_POST['status']    ?? ''));
    $allowed    = ['pending', 'in_progress', 'done'];
    if ($customerId === '' || $scenario === '' || $nodeCode === '' || !in_array($newStatus, $allowed, true)) {
        echo json_encode(['ok' => false, 'error' => '参数错误']);
        exit;
    }
    $stmt = $db->prepare("
        INSERT INTO sop_node_status (customer_id, scenario, node_code, status, updated_at)
        VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)
        ON CONFLICT (customer_id, scenario, node_code) DO UPDATE
            SET status = EXCLUDED.status, updated_at = CURRENT_TIMESTAMP
    ");
    $stmt->execute([$customerId, $scenario, $nodeCode, $newStatus]);
    echo json_encode(['ok' => true, 'status' => $newStatus]);
    exit;
}

// ── 读取当前客户的节点状态覆盖 ───────────────────────────────────────────────
$_currentCustomer = $_SESSION['current_customer'] ?? [];
$_currentCustomerId = sop_current_customer_id($_currentCustomer);
$_nodeStatusOverrides = [];
if ($_currentCustomerId !== '') {
    $stmtNs = $db->prepare("SELECT scenario, node_code, status FROM sop_node_status WHERE customer_id = ?");
    $stmtNs->execute([$_currentCustomerId]);
    foreach ($stmtNs->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $_nodeStatusOverrides[$row['scenario']][$row['node_code']] = $row['status'];
    }
}

$page_title = '策略';

$sop_scenarios = [
    'onboard' => [
        'key' => 'onboard',
        'name' => '品牌启动·首月',
        'subtitle' => '签约后 30 天启动 SOP',
        'description' => '从市场调研、问题宇宙、品牌基线到权威信源矩阵和首月复盘，形成可复制的首月交付施工图。',
        'time_grain' => '周粒度',
        'trigger' => '签约触发',
        'node_count' => 14,
        'presale_count' => 6,
        'span_labels' => ['第1周', '第2周', '第3周', '第4周'],
        'color' => 'blue',
        'nodes' => [
            ['code' => 'S1', 'name' => '市场调研：竞品与平台映射', 'action' => '覆盖 DeepSeek、豆包、元宝、Kimi、通义等中文 AI，抽样 TOP5 竞品与 TOP20 高频问法。', 'kpi' => '平台覆盖率 100%', 'deliverable' => '竞品与平台映射表', 'owner' => '运营/产品', 'cycle' => '1-2 天', 'presale' => true, 'span' => [0, 0], 'target' => null, 'status' => 'done', 'note' => '先看市场再定打法'],
            ['code' => 'S2', 'name' => '问题宇宙梳理', 'action' => '按购买决策、品牌认知、竞品对比、价格服务和行业知识拆出问题树。', 'kpi' => '问题覆盖 80+', 'deliverable' => '问题宇宙表', 'owner' => '策略经理', 'cycle' => '1 天', 'presale' => true, 'span' => [0, 0], 'target' => null, 'status' => 'done', 'note' => '用于后续内容矩阵和监测关键词'],
            ['code' => 'S3', 'name' => '品牌现状数据基线', 'action' => '完成雷达诊断、引用模拟和 AI 偏好对照，建立客户当前可见性基线。', 'kpi' => '六维评分完整', 'deliverable' => '诊断报告与基线表', 'owner' => '数据分析师', 'cycle' => '1 天', 'presale' => true, 'span' => [0, 0], 'target' => null, 'status' => 'done', 'note' => '首页进度的诊断段来源'],
            ['code' => 'S4', 'name' => '单城 30 天策略制定', 'action' => '确定优先 AI、信源组合、内容主题、发布节奏和监测口径。', 'kpi' => '路线图确认', 'deliverable' => '30 天 GEO 路线图', 'owner' => '策略经理', 'cycle' => '1-2 天', 'presale' => true, 'span' => [0, 1], 'target' => null, 'status' => 'done', 'note' => '销售演示可提前完成'],
            ['code' => 'S5', 'name' => '竞品 GEO 监测与攻防', 'action' => '建立竞品引用监控、排名波动和高频提及来源追踪。', 'kpi' => '竞品监测项 ≥ 3', 'deliverable' => '竞品攻防清单', 'owner' => '数据分析师', 'cycle' => '持续', 'presale' => false, 'span' => [1, 3], 'target' => 'monitor', 'status' => 'in_progress', 'note' => '与监测中心联动'],
            ['code' => 'S6', 'name' => '品牌 AI 知识图谱构建', 'action' => '整理品牌实体、服务包、客户案例、FAQ、资质和第三方证据。', 'kpi' => '核心实体完整', 'deliverable' => 'AI 知识图谱与素材包', 'owner' => '内容策略', 'cycle' => '3-5 天', 'presale' => false, 'span' => [1, 2], 'target' => 'content', 'status' => 'in_progress', 'note' => '进入素材管理'],
            ['code' => 'S7', 'name' => '方案对齐与 KPI 确认', 'action' => '与客户确认目标 AI、目标问题、信源优先级、交付周期和验收标准。', 'kpi' => 'KPI 口径一致', 'deliverable' => 'KPI 确认表', 'owner' => '客户成功', 'cycle' => '0.5 天', 'presale' => true, 'span' => [1, 1], 'target' => null, 'status' => 'in_progress', 'note' => '签约前后均可使用'],
            ['code' => 'S8', 'name' => 'AI内容可引用性优化', 'action' => '按Princeton GEO研究清单审查内容：添加数据来源引用（+40%引用率）、精确数字（+37%）、权威引语（+30%）；检查robots.txt开放GPTBot/ClaudeBot/PerplexityBot；每页添加40-60字答案块和FAQ结构。', 'kpi' => '内容GEO评分 ≥ 70', 'deliverable' => 'AI可引用性检查表 + 优化后文章', 'owner' => '内容团队', 'cycle' => '3-5 天', 'presale' => false, 'span' => [1, 2], 'target' => 'content', 'status' => 'pending', 'note' => '基于Princeton KDD 2024研究，直接提升AI引用率'],
            ['code' => 'S9', 'name' => '权威信源矩阵建设', 'action' => '按行业白名单补齐知乎、公众号、媒体、技术博客、行业权威站等信源。', 'kpi' => '核心信源 ≥ 8', 'deliverable' => '信源矩阵与资源清单', 'owner' => '分发运营', 'cycle' => '3-7 天', 'presale' => false, 'span' => [2, 3], 'target' => 'media', 'status' => 'pending', 'note' => '连接媒体分发'],
            ['code' => 'S10', 'name' => '核心问题内容优化与投放', 'action' => '围绕高频问题生产事实密度型内容，并投放到优先信源。', 'kpi' => '首批内容 ≥ 20 篇', 'deliverable' => '内容矩阵与发布记录', 'owner' => '内容团队', 'cycle' => '2 周', 'presale' => false, 'span' => [2, 3], 'target' => 'content', 'status' => 'pending', 'note' => '首月核心执行动作'],
            ['code' => 'S11', 'name' => '数据巡检与实时监测', 'action' => '追踪引用命中率、来源变化、竞品变化和内容收录状态。', 'kpi' => '每周巡检 1 次', 'deliverable' => '周度巡检记录', 'owner' => '数据分析师', 'cycle' => '持续', 'presale' => false, 'span' => [2, 3], 'target' => 'monitor', 'status' => 'pending', 'note' => '异常进入应急 SOP'],
            ['code' => 'S12', 'name' => '紧急机制', 'action' => '为引用暴跌、错误提及、竞品压制等情况准备应急流程。', 'kpi' => '4 小时内确认', 'deliverable' => '应急预案卡', 'owner' => '项目经理', 'cycle' => '持续', 'presale' => false, 'span' => [3, 3], 'target' => 'task', 'status' => 'pending', 'note' => '可由监测触发'],
            ['code' => 'S14', 'name' => '月度复盘', 'action' => '汇总任务完成、引用变化、竞品波动、续费机会和下月动作。', 'kpi' => '复盘报告 1 份', 'deliverable' => '月度复盘报告', 'owner' => '客户成功', 'cycle' => '1-2 天', 'presale' => false, 'span' => [3, 3], 'target' => 'task', 'status' => 'pending', 'note' => '完成后生成月报骨架'],
        ],
    ],
    'emergency' => [
        'key' => 'emergency',
        'name' => '应急预案',
        'subtitle' => '引用异常自动触发',
        'description' => '当 GEO 监测发现引用暴跌、错误提及或竞品异常增长时，自动拉起 E1-E7 应急节点。',
        'time_grain' => '小时/天粒度',
        'trigger' => '监测告警触发',
        'node_count' => 7,
        'presale_count' => 0,
        'span_labels' => ['0-4h', '4-12h', '12-24h', '24-72h', 'D4-D7'],
        'color' => 'orange',
        'nodes' => [
            ['code' => 'E1', 'name' => '异常确认与分级', 'action' => '确认引用暴跌、错误提及或竞品异常的真实程度，标记高/中/低优先级。', 'kpi' => '4 小时内确认', 'deliverable' => '异常确认单', 'owner' => '数据分析师', 'cycle' => '0-4h', 'presale' => false, 'span' => [0, 0], 'target' => 'task', 'status' => 'in_progress', 'note' => '监测触发后自动派发'],
            ['code' => 'E2', 'name' => '根因排查', 'action' => '排查模型口径、内容收录、信源变化和竞品动作。', 'kpi' => '根因假设 ≥ 2', 'deliverable' => '根因排查表', 'owner' => '策略经理', 'cycle' => '4-12h', 'presale' => false, 'span' => [1, 1], 'target' => 'task', 'status' => 'pending', 'note' => '形成修复路径'],
            ['code' => 'E3', 'name' => '提示词与问题宇宙重构', 'action' => '重构触发错误引用的问法，补充对抗式查询和长尾问题。', 'kpi' => '新增问法 ≥ 20', 'deliverable' => '问题宇宙更新表', 'owner' => '策略经理', 'cycle' => '12-24h', 'presale' => false, 'span' => [2, 2], 'target' => 'content', 'status' => 'pending', 'note' => '同步引用模拟器'],
            ['code' => 'E4', 'name' => 'FAQ/案例/验证补强', 'action' => '补充事实密度、资质证明、客户案例和反误解 FAQ。', 'kpi' => '补强内容 ≥ 5 条', 'deliverable' => '修复内容包', 'owner' => '内容团队', 'cycle' => '24-48h', 'presale' => false, 'span' => [3, 3], 'target' => 'content', 'status' => 'pending', 'note' => '进入文章管理'],
            ['code' => 'E5', 'name' => '重点信源补发与分发', 'action' => '将修复内容补发到知乎、公众号、行业权威源和价格表补充资源。', 'kpi' => '重点信源 ≥ 5', 'deliverable' => '补发记录', 'owner' => '分发运营', 'cycle' => '24-72h', 'presale' => false, 'span' => [3, 3], 'target' => 'media', 'status' => 'pending', 'note' => '连接媒体分发'],
            ['code' => 'E6', 'name' => '专项监测与复测', 'action' => '连续复测异常关键词，记录引用恢复、竞品变化和错误提及下降。', 'kpi' => 'D4-D7 每日复测', 'deliverable' => '专项监测表', 'owner' => '数据分析师', 'cycle' => 'D4-D7', 'presale' => false, 'span' => [4, 4], 'target' => 'monitor', 'status' => 'pending', 'note' => '验证修复效果'],
            ['code' => 'E7', 'name' => '专项复盘与防再发', 'action' => '沉淀应急原因、处理动作、恢复曲线和长期预防机制。', 'kpi' => '7 天内复盘', 'deliverable' => '专项复盘报告', 'owner' => '客户成功', 'cycle' => '7 天内', 'presale' => false, 'span' => [4, 4], 'target' => 'task', 'status' => 'pending', 'note' => '回流月报'],
        ],
    ],
    'annual' => [
        'key' => 'annual',
        'name' => '全年路线',
        'subtitle' => '年单客户服务清单',
        'description' => '面向年单客户，把策略制定、资产建设、内容投放、监测复盘和应急响应拉成全年服务节奏。',
        'time_grain' => '季度/月粒度',
        'trigger' => '年单触发',
        'node_count' => 9,
        'presale_count' => 1,
        'span_labels' => ['Q1', 'Q2', 'Q3', 'Q4'],
        'color' => 'green',
        'nodes' => [
            ['code' => 'A1', 'name' => 'AI 全域 GEO 营销策略制定', 'action' => '制定全年 GEO 目标、AI 平台优先级和行业信源策略。', 'kpi' => '年度策略确认', 'deliverable' => '年度 GEO 策略书', 'owner' => '策略负责人', 'cycle' => '1 周', 'presale' => true, 'span' => [0, 0], 'target' => null, 'status' => 'done', 'note' => '年单签约关键材料'],
            ['code' => 'A2', 'name' => '品牌 AI 知识图谱与问题宇宙', 'action' => '建设品牌实体库、问题池、FAQ 和证据材料。', 'kpi' => '知识资产完整', 'deliverable' => '知识图谱与问题池', 'owner' => '内容策略', 'cycle' => 'Q1', 'presale' => false, 'span' => [0, 0], 'target' => 'content', 'status' => 'in_progress', 'note' => '基础资产'],
            ['code' => 'A3', 'name' => '竞品 GEO 动态监测与攻防', 'action' => '全年监测竞品引用、来源变化和攻击机会。', 'kpi' => '月度监测持续', 'deliverable' => '竞品攻防台账', 'owner' => '数据分析师', 'cycle' => '全年', 'presale' => false, 'span' => [0, 3], 'target' => 'monitor', 'status' => 'in_progress', 'note' => '监测中心联动'],
            ['code' => 'A4', 'name' => '季度策略迭代咨询', 'action' => '按季度复盘模型变化、内容效果和行业信源偏移。', 'kpi' => '季度迭代 4 次', 'deliverable' => '季度策略迭代报告', 'owner' => '策略负责人', 'cycle' => '每季度', 'presale' => false, 'span' => [0, 3], 'target' => 'task', 'status' => 'pending', 'note' => '续费关键触点'],
            ['code' => 'A5', 'name' => '品牌 GEO 资产构建（首期）', 'action' => '搭建官网、知识库、权威源和重点平台内容资产。', 'kpi' => '首期资产上线', 'deliverable' => 'GEO 资产清单', 'owner' => '项目经理', 'cycle' => 'Q1-Q2', 'presale' => false, 'span' => [0, 1], 'target' => 'media', 'status' => 'pending', 'note' => '资产化交付'],
            ['code' => 'A6', 'name' => '核心问题内容优化与投放', 'action' => '持续生产并分发结构化内容、案例、问答和对比材料。', 'kpi' => '月度内容达标', 'deliverable' => '内容投放记录', 'owner' => '内容团队', 'cycle' => '全年', 'presale' => false, 'span' => [0, 3], 'target' => 'content', 'status' => 'pending', 'note' => '执行主线'],
            ['code' => 'A7', 'name' => 'GEO 优化结果实时监测', 'action' => '按月监测引用命中、自然推荐、来源占比和排名波动。', 'kpi' => '月报不断档', 'deliverable' => '监测结果库', 'owner' => '数据分析师', 'cycle' => '全年', 'presale' => false, 'span' => [0, 3], 'target' => 'monitor', 'status' => 'pending', 'note' => '数据再循环'],
            ['code' => 'A8', 'name' => '周报/月报/季度复盘', 'action' => '按服务周期输出周报、月报和季度复盘。', 'kpi' => '报告准时率 100%', 'deliverable' => '周期复盘报告', 'owner' => '客户成功', 'cycle' => '全年', 'presale' => false, 'span' => [0, 3], 'target' => 'task', 'status' => 'pending', 'note' => '续费材料'],
            ['code' => 'A9', 'name' => '应急 SOP 响应与专项补强', 'action' => '全年保留应急响应能力，异常触发专项补强。', 'kpi' => 'SLA 达标', 'deliverable' => '应急记录与补强清单', 'owner' => '项目经理', 'cycle' => '全年', 'presale' => false, 'span' => [0, 3], 'target' => 'task', 'status' => 'pending', 'note' => '护城河能力'],
        ],
    ],
    'overview' => [
        'key' => 'overview',
        'name' => '单城推进总表',
        'subtitle' => '渠道交付对齐版',
        'description' => '把首月 SOP 压缩成渠道和城市团队能看懂的 9 个推进节点，适合交付对齐和代理复制。',
        'time_grain' => '周粒度',
        'trigger' => '签约/渠道触发',
        'node_count' => 9,
        'presale_count' => 2,
        'span_labels' => ['第1周', '第2周', '第3周', '第4周'],
        'color' => 'blue',
        'nodes' => [
            ['code' => 'T1', 'name' => '市场调研+问题宇宙+基线', 'action' => '合并完成市场、问题和现状基线。', 'kpi' => '基线完整', 'deliverable' => '启动基线包', 'owner' => '策略经理', 'cycle' => '1 周', 'presale' => true, 'span' => [0, 0], 'target' => null, 'status' => 'done', 'note' => '渠道前置材料'],
            ['code' => 'T2', 'name' => '策略制定+攻防规则', 'action' => '确定优先平台、内容方向、竞品攻防规则和验收口径。', 'kpi' => '规则确认', 'deliverable' => '策略推进表', 'owner' => '策略经理', 'cycle' => '1 周', 'presale' => true, 'span' => [0, 0], 'target' => null, 'status' => 'done', 'note' => '售前可展示'],
            ['code' => 'T3', 'name' => '知识库/资产构建', 'action' => '搭建品牌知识库、FAQ、案例和资质素材。', 'kpi' => '资产完整', 'deliverable' => '知识资产包', 'owner' => '内容策略', 'cycle' => '第2周', 'presale' => false, 'span' => [1, 1], 'target' => 'content', 'status' => 'in_progress', 'note' => '进入素材管理'],
            ['code' => 'T4', 'name' => '方案对齐+KPI确认', 'action' => '与客户确认 30 天目标和验收标准。', 'kpi' => 'KPI 签字确认', 'deliverable' => 'KPI 对齐单', 'owner' => '客户成功', 'cycle' => '第2周', 'presale' => false, 'span' => [1, 1], 'target' => null, 'status' => 'pending', 'note' => '进入执行前置'],
            ['code' => 'T5', 'name' => '权威信源矩阵建设', 'action' => '按行业选出通用重媒体、行业权威和价格表补充资源。', 'kpi' => '信源 ≥ 8', 'deliverable' => '信源矩阵', 'owner' => '分发运营', 'cycle' => '第2-3周', 'presale' => false, 'span' => [1, 2], 'target' => 'media', 'status' => 'pending', 'note' => '连接媒体分发'],
            ['code' => 'T6', 'name' => '内容优化与 8 信源投放', 'action' => '生产内容并按信源矩阵完成投放。', 'kpi' => '8 信源铺设', 'deliverable' => '投放记录', 'owner' => '内容团队', 'cycle' => '第3周', 'presale' => false, 'span' => [2, 2], 'target' => 'content', 'status' => 'pending', 'note' => '核心交付动作'],
            ['code' => 'T7', 'name' => '实时监测+紧急机制', 'action' => '启动监测和异常响应机制。', 'kpi' => '监测启用', 'deliverable' => '监测配置', 'owner' => '数据分析师', 'cycle' => '第3-4周', 'presale' => false, 'span' => [2, 3], 'target' => 'monitor', 'status' => 'pending', 'note' => '异常转应急'],
            ['code' => 'T8', 'name' => '周报输出', 'action' => '每周同步任务完成、引用变化和下周计划。', 'kpi' => '周报准时', 'deliverable' => '周报', 'owner' => '客户成功', 'cycle' => '每周', 'presale' => false, 'span' => [1, 3], 'target' => 'task', 'status' => 'pending', 'note' => '客户沟通材料'],
            ['code' => 'T9', 'name' => '月度复盘', 'action' => '汇总 30 天成果、短板、续费机会和下月计划。', 'kpi' => '复盘完成', 'deliverable' => '月度复盘', 'owner' => '客户成功', 'cycle' => '第4周', 'presale' => false, 'span' => [3, 3], 'target' => 'task', 'status' => 'pending', 'note' => '续费节点'],
        ],
    ],
    'maintain' => [
        'key' => 'maintain',
        'name' => '第二月维护',
        'subtitle' => '首月结束后的续接 SOP',
        'description' => '把首月结果转化成第二月的问题池滚动、持续投放、竞品攻防和复盘续费。',
        'time_grain' => '周粒度',
        'trigger' => '首月结束续接',
        'node_count' => 6,
        'presale_count' => 0,
        'span_labels' => ['第1周', '第2周', '第3周', '第4周'],
        'color' => 'green',
        'nodes' => [
            ['code' => 'M1', 'name' => '上月复盘结论落地', 'action' => '把首月复盘中的短板、机会和客户反馈转为本月任务。', 'kpi' => '结论全部入池', 'deliverable' => '本月优化清单', 'owner' => '客户成功', 'cycle' => '第1周', 'presale' => false, 'span' => [0, 0], 'target' => 'task', 'status' => 'done', 'note' => '承接首月复盘'],
            ['code' => 'M2', 'name' => '问题池滚动更新', 'action' => '补充新问法、长尾词、竞品问法和客户新业务。', 'kpi' => '新增问题 ≥ 30', 'deliverable' => '滚动问题池', 'owner' => '策略经理', 'cycle' => '第1周', 'presale' => false, 'span' => [0, 0], 'target' => null, 'status' => 'in_progress', 'note' => '持续诊断基础'],
            ['code' => 'M3', 'name' => '持续内容投放', 'action' => '围绕问题池持续生产内容并投放重点信源。', 'kpi' => '月度内容达标', 'deliverable' => '投放记录', 'owner' => '内容团队', 'cycle' => '第1-4周', 'presale' => false, 'span' => [0, 3], 'target' => 'content', 'status' => 'in_progress', 'note' => '维护期主动作'],
            ['code' => 'M4', 'name' => '竞品攻防执行', 'action' => '根据竞品变化补发内容、调整信源和强化差异点。', 'kpi' => '攻防动作完成', 'deliverable' => '竞品攻防记录', 'owner' => '策略经理', 'cycle' => '第2-4周', 'presale' => false, 'span' => [1, 3], 'target' => 'media', 'status' => 'pending', 'note' => '连接监测结果'],
            ['code' => 'M5', 'name' => '周度巡检与异常响应', 'action' => '每周巡检引用结果，异常进入应急 SOP。', 'kpi' => '每周 1 次', 'deliverable' => '巡检记录', 'owner' => '数据分析师', 'cycle' => '第1-4周', 'presale' => false, 'span' => [0, 3], 'target' => 'monitor', 'status' => 'pending', 'note' => '监测联动'],
            ['code' => 'M6', 'name' => '第二月度复盘', 'action' => '形成第二月结果、趋势、续费和增购建议。', 'kpi' => '复盘报告 1 份', 'deliverable' => '第二月复盘', 'owner' => '客户成功', 'cycle' => '第4周', 'presale' => false, 'span' => [3, 3], 'target' => 'task', 'status' => 'pending', 'note' => '完成后生成月报骨架'],
        ],
    ],
];

$onboard_extra = [
    'S1' => ['week' => '第1周', 'unmet_action' => '补齐遗漏平台与竞品，24 小时内重新采样。', 'presale_text' => '可售前完成'],
    'S2' => ['week' => '第1周', 'unmet_action' => '补充缺失意图与长尾问法，当天修订。', 'presale_text' => '可售前完成'],
    'S3' => ['week' => '第1周', 'unmet_action' => '追加采样并补录异常样本。', 'presale_text' => '可售前完成'],
    'S4' => ['week' => '第1周', 'unmet_action' => '补充城市优先级、发布节奏和监测口径。', 'presale_text' => '可售前完成'],
    'S5' => ['week' => '第1周', 'unmet_action' => '补竞品词、补预警阈值和防守动作。', 'presale_text' => '签约后执行'],
    'S6' => ['week' => '第1周', 'unmet_action' => '优先补 FAQ、城市页、案例和品牌验证页。', 'presale_text' => '签约后执行'],
    'S7' => ['week' => '第1周', 'unmet_action' => '未确认项 24 小时内补会并锁定版本。', 'presale_text' => '可售前/签约当周'],
    'S9' => ['week' => '第2-3周', 'unmet_action' => '补充缺失信源并替换低权重位。', 'presale_text' => '签约后执行'],
    'S10' => ['week' => '第2-3周', 'unmet_action' => '48 小时内补发，补改标题结构和问法。', 'presale_text' => '签约后执行'],
    'S11' => ['week' => '第2-3周', 'unmet_action' => '发现异常当天推送，次日给补救动作。', 'presale_text' => '签约后执行'],
    'S12' => ['week' => '第2-3周', 'unmet_action' => '升级专项应急，增加监测频次。', 'presale_text' => '签约后执行'],
    'S14' => ['week' => '第4周', 'unmet_action' => '下月补强并进入维护清单。', 'presale_text' => '签约后执行'],
];

$onboard_nodes =& $sop_scenarios['onboard']['nodes'];
$onboard_codes = array_column($onboard_nodes, 'code');

if (!in_array('S8', $onboard_codes, true)) {
    $insert_after_s7 = array_search('S7', $onboard_codes, true);
    $s8_node = ['code' => 'S8', 'name' => '外部品牌资产对接', 'action' => '对接官网、百科、白皮书、机构背书、IP 内容等外部资产，至少补强 2 类高权重素材。', 'kpi' => '外部资产对接完成率 ≥ 80%', 'deliverable' => '外部资产接入清单', 'owner' => '项目经理/内容', 'cycle' => '1-3 天', 'presale' => true, 'span' => [1, 1], 'target' => 'media', 'status' => 'pending', 'note' => '可选项，但对品牌验证很有用', 'week' => '第1周', 'unmet_action' => '未完成则列入次周补强清单。', 'presale_text' => '部分可售前完成'];
    array_splice($onboard_nodes, ($insert_after_s7 === false ? 7 : $insert_after_s7 + 1), 0, [$s8_node]);
}

$onboard_codes = array_column($onboard_nodes, 'code');
if (!in_array('S13', $onboard_codes, true)) {
    $insert_before_s14 = array_search('S14', $onboard_codes, true);
    $s13_node = ['code' => 'S13', 'name' => '执行效果验收与定期输出', 'action' => '每周输出阶段结果，说明做了什么、哪些词起色、哪些词未达标、下周怎么补。', 'kpi' => '周报提交率 100%', 'deliverable' => '周报 2 份', 'owner' => '项目经理', 'cycle' => '每周 1 次', 'presale' => false, 'span' => [2, 3], 'target' => 'task', 'status' => 'pending', 'note' => '周报是客户安全感来源', 'week' => '第2-3周', 'unmet_action' => '延期即补发并补充动作结论。', 'presale_text' => '签约后执行'];
    array_splice($onboard_nodes, ($insert_before_s14 === false ? count($onboard_nodes) : $insert_before_s14), 0, [$s13_node]);
}

foreach ($onboard_nodes as &$node) {
    if (isset($onboard_extra[$node['code']])) {
        $node = array_merge($node, $onboard_extra[$node['code']]);
    }
}
unset($node, $onboard_nodes);

$annual_service_groups = [
    'A1' => '策略服务',
    'A2' => '策略服务',
    'A3' => '策略服务',
    'A4' => '策略服务',
    'A5' => '品牌 GEO 资产构建',
    'A6' => '执行&日常执行服务',
    'A7' => '执行&日常执行服务',
    'A8' => '执行&日常执行服务',
    'A9' => '执行&日常执行服务',
];

foreach ($sop_scenarios['annual']['nodes'] as &$node) {
    $node['service_group'] = $annual_service_groups[$node['code']] ?? '执行&日常执行服务';
}
unset($node);

$sop_scenarios['onboard']['node_count'] = count($sop_scenarios['onboard']['nodes']);
$sop_scenarios['onboard']['presale_count'] = count(array_filter($sop_scenarios['onboard']['nodes'], static fn($node) => $node['presale']));

$total_nodes = array_sum(array_map(static fn($scenario) => count($scenario['nodes']), $sop_scenarios));
$total_presale = array_sum(array_map(static fn($scenario) => count(array_filter($scenario['nodes'], static fn($node) => $node['presale'])), $sop_scenarios));

// 合并数据库中的状态覆盖
foreach ($sop_scenarios as $scenarioKey => &$scenarioData) {
    foreach ($scenarioData['nodes'] as &$node) {
        if (isset($_nodeStatusOverrides[$scenarioKey][$node['code']])) {
            $node['status'] = $_nodeStatusOverrides[$scenarioKey][$node['code']];
        }
    }
    unset($node);
}
unset($scenarioData);

$sopReality = sop_fetch_reality_snapshot($db, $_currentCustomer);
sop_ensure_strategy_analysis_table($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'sop_generate_analysis') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $_analysisError = 'CSRF验证失败';
    } elseif ($sopReality['customer_id'] === '') {
        $_analysisError = '请先选择客户';
    } else {
        $snapshotForPrompt = [
            'brand_name' => $sopReality['brand_name'],
            'industry' => $sopReality['industry'],
            'core_services' => $sopReality['facts']['core_services'] ?? '',
            'positioning' => $sopReality['facts']['master_sentence'] ?? ($sopReality['facts']['positioning'] ?? ''),
            'monitor_30d' => $sopReality['monitor'],
            'p0_questions' => $sopReality['p0_questions'],
            'weak_keywords' => $sopReality['weak_keywords'],
            'weak_signals' => $sopReality['weak_signals'],
            'brand_knowledge' => $sopReality['brand_knowledge'],
            'geo_materials' => $sopReality['geo_materials'],
            'content_queue' => $sopReality['content_queue'],
            'monitor_samples' => $sopReality['monitor_samples'],
            'diagnosis_actions' => $sopReality['diagnosis_actions'],
            'human_tasks' => $sopReality['human_tasks'],
            'content_queue_pending' => $sopReality['content_queue_pending'],
        ];
        $snapshotJson = json_encode($snapshotForPrompt, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        $prompt = str_replace('{{REALITY_JSON}}', (string) $snapshotJson, sop_strategy_prompt_template());

        session_write_close();
        $aiResult = geo_call_ai($prompt, 2600, 0.35);
        if (!empty($aiResult['error'])) {
            $_analysisError = 'AI 分析失败：' . $aiResult['error'];
        } else {
            $analysisMd = trim((string) ($aiResult['content'] ?? ''));
            $stmt = $db->prepare("
                INSERT INTO sop_strategy_analyses
                    (customer_id, brand_name, recommended_scenario, analysis_md, input_snapshot, model_used)
                VALUES (?, ?, ?, ?, ?::jsonb, ?)
            ");
            $stmt->execute([
                $sopReality['customer_id'],
                $sopReality['brand_name'],
                'brand_strategy',
                $analysisMd,
                json_encode($snapshotForPrompt, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                (string) ($aiResult['model_used'] ?? ''),
            ]);
            $_analysisMessage = '已基于当前品牌真实数据生成策略分析';
        }
    }
}

$latestSopAnalysis = null;
if ($sopReality['customer_id'] !== '') {
    try {
        $stmt = $db->prepare("SELECT * FROM sop_strategy_analyses WHERE customer_id = ? ORDER BY created_at DESC LIMIT 1");
        $stmt->execute([$sopReality['customer_id']]);
        $latestSopAnalysis = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {}
}

$sopCsrfToken = generate_csrf_token();
session_write_close();

require_once __DIR__ . '/includes/header.php';
?>
<?php if ($_dispatchMessage): ?>
<div class="mb-5 rounded-md border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800"><?= htmlspecialchars($_dispatchMessage) ?></div>
<?php endif; ?>
<?php if ($_dispatchError): ?>
<div class="mb-5 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800"><?= htmlspecialchars($_dispatchError) ?></div>
<?php endif; ?>
<?php if ($_analysisMessage): ?>
<div class="mb-5 rounded-md border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800"><?= sop_h($_analysisMessage) ?></div>
<?php endif; ?>
<?php if ($_analysisError): ?>
<div class="mb-5 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800"><?= sop_h($_analysisError) ?></div>
<?php endif; ?>
<script>const _sopCsrfToken = <?= json_encode($sopCsrfToken) ?>;</script>
            <div class="mb-6">
                <div class="flex flex-col gap-4 xl:flex-row xl:items-center xl:justify-between">
                    <div>
                        <h1 class="text-3xl font-bold text-gray-900">品牌策略分析</h1>
                        <p class="mt-1 text-sm text-gray-600">基于当前品牌的真实监测、诊断、知识库和内容资产生成策略建议。</p>
                    </div>
                    <div class="flex flex-wrap gap-3">
                        <a href="<?php echo htmlspecialchars(admin_url('tasks.php')); ?>" class="inline-flex items-center justify-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50">
                            <i data-lucide="list-checks" class="mr-2 h-4 w-4"></i>
                            任务管理
                        </a>
                        <a href="<?php echo htmlspecialchars(admin_url('geo-monitor.php')); ?>" class="inline-flex items-center justify-center rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-gray-800">
                            <i data-lucide="activity" class="mr-2 h-4 w-4"></i>
                            监测触发应急
                        </a>
                    </div>
                </div>
            </div>

            <div class="mb-6 hidden grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
                <section class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
                    <div class="text-sm font-semibold text-gray-500">场景</div>
                    <div class="mt-2 text-3xl font-bold text-gray-900">5</div>
                    <div class="mt-2 text-sm leading-6 text-gray-500">覆盖启动、应急、年单、推进和维护。</div>
                </section>
                <section class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
                    <div class="text-sm font-semibold text-gray-500">节点</div>
                    <div class="mt-2 text-3xl font-bold text-gray-900"><?php echo (int) $total_nodes; ?></div>
                    <div class="mt-2 text-sm leading-6 text-gray-500">把 GEO 服务拆成可执行动作。</div>
                </section>
                <section class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
                    <div class="text-sm font-semibold text-gray-500">可售前</div>
                    <div class="mt-2 text-3xl font-bold text-gray-900"><?php echo (int) $total_presale; ?></div>
                    <div class="mt-2 text-sm leading-6 text-gray-500">可在签约前完成，用于方案演示。</div>
                </section>
                <section class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
                    <div class="text-sm font-semibold text-gray-500">联动</div>
                    <div class="mt-2 text-3xl font-bold text-gray-900">4</div>
                    <div class="mt-2 text-sm leading-6 text-gray-500">连接任务、交付、监测和复盘。</div>
                </section>
            </div>

            <section class="mb-6 rounded-lg border border-gray-200 bg-white shadow-sm">
                <div class="border-b border-gray-200 p-5">
                    <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                        <div>
                            <h2 class="text-xl font-semibold text-gray-900">当前品牌真实信号</h2>
                            <p class="mt-1 text-sm text-gray-500">来自当前客户的意图、监测、诊断、知识图谱、素材、队列和人工任务。</p>
                        </div>
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?= sop_h($sopCsrfToken) ?>">
                            <input type="hidden" name="action" value="sop_generate_analysis">
                            <button type="submit" class="inline-flex items-center justify-center rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-blue-700">
                                <i data-lucide="sparkles" class="mr-2 h-4 w-4"></i>
                                生成品牌策略分析
                            </button>
                        </form>
                    </div>
                </div>
                <div class="grid grid-cols-1 gap-4 p-5 lg:grid-cols-[520px_minmax(0,1fr)]">
                    <div class="space-y-4">
                    <div class="grid grid-cols-2 gap-3 xl:grid-cols-3">
                        <div class="rounded-lg bg-red-50 p-4">
                            <div class="text-xs font-semibold text-red-700">P0 未覆盖</div>
                            <div class="mt-2 text-2xl font-bold text-red-700"><?= count($sopReality['p0_questions']) ?></div>
                        </div>
                        <div class="rounded-lg bg-orange-50 p-4">
                            <div class="text-xs font-semibold text-orange-700">失守关键词</div>
                            <div class="mt-2 text-2xl font-bold text-orange-700"><?= count($sopReality['weak_keywords']) ?></div>
                        </div>
                        <div class="rounded-lg bg-yellow-50 p-4">
                            <div class="text-xs font-semibold text-yellow-700">诊断短板</div>
                            <div class="mt-2 text-2xl font-bold text-yellow-700"><?= count($sopReality['weak_signals']) ?></div>
                        </div>
                        <div class="rounded-lg bg-blue-50 p-4">
                            <div class="text-xs font-semibold text-blue-700">30天提及率</div>
                            <div class="mt-2 text-2xl font-bold text-blue-700">
                                <?= $sopReality['monitor']['mention_rate'] === null ? '-' : sop_h($sopReality['monitor']['mention_rate'] . '%') ?>
                            </div>
                        </div>
                        <div class="rounded-lg bg-emerald-50 p-4">
                            <div class="text-xs font-semibold text-emerald-700">知识图谱</div>
                            <div class="mt-2 text-2xl font-bold text-emerald-700"><?= count($sopReality['brand_knowledge']) ?></div>
                        </div>
                        <div class="rounded-lg bg-purple-50 p-4">
                            <div class="text-xs font-semibold text-purple-700">可用素材</div>
                            <div class="mt-2 text-2xl font-bold text-purple-700"><?= count($sopReality['geo_materials']) ?></div>
                        </div>
                    </div>
                    <div class="rounded-lg border border-gray-200 bg-gray-50 p-4">
                        <div class="mb-3 text-sm font-semibold text-gray-900">本次分析输入</div>
                        <div class="space-y-3 text-sm">
                            <div>
                                <div class="text-xs font-semibold text-gray-500">低提及关键词</div>
                                <div class="mt-1 flex flex-wrap gap-2">
                                    <?php foreach (array_slice($sopReality['weak_keywords'], 0, 5) as $kw): ?>
                                        <span class="rounded-full bg-white px-2.5 py-1 text-xs font-semibold text-orange-700">
                                            <?= sop_h(($kw['query_text'] ?? '') . ' · ' . ($kw['mention_rate'] ?? '-') . '%') ?>
                                        </span>
                                    <?php endforeach; ?>
                                    <?php if (empty($sopReality['weak_keywords'])): ?><span class="text-xs text-gray-400">暂无低提及关键词</span><?php endif; ?>
                                </div>
                            </div>
                            <div>
                                <div class="text-xs font-semibold text-gray-500">P0 未覆盖问题</div>
                                <div class="mt-1 space-y-1">
                                    <?php foreach (array_slice($sopReality['p0_questions'], 0, 3) as $q): ?>
                                        <div class="truncate rounded bg-white px-2.5 py-1 text-xs text-gray-700"><?= sop_h($q['question'] ?? '') ?></div>
                                    <?php endforeach; ?>
                                    <?php if (empty($sopReality['p0_questions'])): ?><div class="text-xs text-gray-400">暂无 P0 未覆盖问题</div><?php endif; ?>
                                </div>
                            </div>
                            <div>
                                <div class="text-xs font-semibold text-gray-500">最近监测样本</div>
                                <div class="mt-1 space-y-1">
                                    <?php foreach (array_slice($sopReality['monitor_samples'], 0, 3) as $sample): ?>
                                        <div class="truncate rounded bg-white px-2.5 py-1 text-xs text-gray-700">
                                            <?= sop_h(($sample['provider'] ?? '') . ' · ' . ($sample['query_text'] ?? '') . ' · ' . (!empty($sample['brand_mentioned']) ? '提及' : '未提及')) ?>
                                        </div>
                                    <?php endforeach; ?>
                                    <?php if (empty($sopReality['monitor_samples'])): ?><div class="text-xs text-gray-400">暂无监测样本</div><?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    </div>
                    <div class="rounded-lg bg-gray-50 p-4">
                        <?php if ($latestSopAnalysis): ?>
                            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                                <div class="text-sm font-semibold text-gray-900">
                                    最新品牌策略分析
                                </div>
                                <div class="text-xs text-gray-500"><?= sop_h($latestSopAnalysis['created_at']) ?> · <?= sop_h($latestSopAnalysis['model_used']) ?></div>
                            </div>
                            <div class="max-h-[620px] overflow-auto rounded-lg bg-white px-6 py-5 shadow-sm ring-1 ring-slate-100">
                                <article class="sop-markdown max-w-none">
                                    <?= sop_render_markdown((string) $latestSopAnalysis['analysis_md']) ?>
                                </article>
                            </div>
                        <?php else: ?>
                            <div class="flex h-full min-h-40 items-center justify-center rounded-md border border-dashed border-gray-300 bg-white p-6 text-center">
                                <div>
                                    <div class="text-sm font-semibold text-gray-900">还没有生成过品牌策略分析</div>
                                    <div class="mt-1 text-sm text-gray-500">点击右上角按钮，会把当前品牌真实数据送进 AI，生成本周策略、内容方向和风险缺口。</div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </section>

            <section class="mb-6 hidden rounded-lg border border-gray-200 bg-white shadow-sm">
                <div class="border-b border-gray-200 p-5">
                    <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                        <div>
                            <h2 class="text-xl font-semibold text-gray-900">场景</h2>
                        </div>
                        <label class="inline-flex items-center gap-2 rounded-full bg-gray-50 px-4 py-2 text-sm font-medium text-gray-700">
                            <input id="sop-presale-only" type="checkbox" class="rounded border-gray-300 text-blue-600">
                            只看可售前完成
                        </label>
                    </div>
                    <div id="sop-scenario-buttons" class="mt-5 grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-5"></div>
                </div>
                <div class="grid grid-cols-1 gap-5 p-5 lg:grid-cols-[minmax(0,1fr)_280px]">
                    <div>
                        <div class="flex flex-wrap items-center gap-2">
                            <span id="sop-current-name" class="text-2xl font-bold text-gray-900"></span>
                            <span id="sop-current-trigger" class="rounded-full bg-gray-100 px-3 py-1 text-xs font-semibold text-gray-600"></span>
                            <span id="sop-current-grain" class="rounded-full bg-gray-100 px-3 py-1 text-xs font-semibold text-gray-600"></span>
                        </div>
                    </div>
                    <div class="rounded-lg bg-gray-50 p-4">
                        <div class="flex items-center justify-between text-sm">
                            <span class="text-gray-500">当前进度</span>
                            <strong id="sop-overall-progress" class="text-gray-900">0%</strong>
                        </div>
                        <div class="mt-3 h-2 overflow-hidden rounded-full bg-gray-200">
                            <div id="sop-overall-bar" class="h-full rounded-full bg-blue-600" style="width: 0%"></div>
                        </div>
                        <div id="sop-status-summary" class="mt-4 grid grid-cols-3 gap-2 text-center text-xs"></div>
                    </div>
                </div>
            </section>

            <div class="hidden grid grid-cols-1 gap-5 xl:grid-cols-[minmax(0,1fr)_320px] 2xl:grid-cols-[minmax(0,1fr)_340px] xl:items-start">
                <section class="rounded-lg border border-gray-200 bg-white shadow-sm">
                    <div class="border-b border-gray-200 p-5">
                        <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                            <div>
                                <h2 class="text-xl font-semibold text-gray-900">节点</h2>
                            </div>
                            <div id="sop-view-buttons" class="inline-flex rounded-lg bg-gray-100 p-1 text-sm font-semibold text-gray-600">
                                <button type="button" data-sop-view="table" class="sop-view-btn rounded-md px-4 py-2">明细表</button>
                                <button type="button" data-sop-view="gantt" class="sop-view-btn rounded-md px-4 py-2">甘特图</button>
                                <button type="button" data-sop-view="progress" class="sop-view-btn rounded-md px-4 py-2">客户进度</button>
                            </div>
                        </div>
                    </div>

                    <div id="sop-table-view" class="overflow-x-auto"></div>
                    <div id="sop-gantt-view" class="hidden p-5"></div>
                    <div id="sop-progress-view" class="hidden p-5"></div>
                </section>

                <aside class="xl:sticky xl:top-24">
                    <section class="rounded-lg border border-gray-200 bg-white shadow-sm">
                        <div class="border-b border-gray-200 p-5">
                            <h2 class="text-lg font-semibold text-gray-900">节点详情</h2>
                        </div>
                        <div id="sop-node-detail" class="p-4"></div>
                    </section>
                </aside>
            </div>

            <script>
                const SOP_SCENARIOS = <?php echo json_encode($sop_scenarios, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
                const REQUESTED_SCENARIO = <?php echo json_encode((string) ($_GET['scenario'] ?? '')); ?>;
                let activeScenario = SOP_SCENARIOS[REQUESTED_SCENARIO] ? REQUESTED_SCENARIO : 'onboard';
                let activeView = 'table';
                let selectedNodeCode = SOP_SCENARIOS[activeScenario].nodes[0].code;

                const statusMap = {
                    done: { label: '已完成', className: 'bg-emerald-50 text-emerald-700 border-emerald-200' },
                    in_progress: { label: '执行中', className: 'bg-blue-50 text-blue-700 border-blue-200' },
                    pending: { label: '待执行', className: 'bg-gray-50 text-gray-600 border-gray-200' },
                    skipped: { label: '已跳过', className: 'bg-gray-100 text-gray-500 border-gray-200' },
                };

                const targetMap = {
                    task: '任务管理',
                    content: '文章/素材',
                    media: '媒体分发',
                    monitor: 'GEO监测',
                };

                function escapeHtml(value) {
                    return String(value ?? '').replace(/[&<>"']/g, (char) => ({
                        '&': '&amp;',
                        '<': '&lt;',
                        '>': '&gt;',
                        '"': '&quot;',
                        "'": '&#039;',
                    }[char]));
                }

                function getScenario() {
                    return SOP_SCENARIOS[activeScenario] || SOP_SCENARIOS.onboard;
                }

                function getVisibleNodes() {
                    const scenario = getScenario();
                    const presaleOnly = document.getElementById('sop-presale-only')?.checked;
                    return presaleOnly ? scenario.nodes.filter((node) => node.presale) : scenario.nodes;
                }

                function getNode(code = selectedNodeCode) {
                    return getScenario().nodes.find((node) => node.code === code) || getVisibleNodes()[0] || getScenario().nodes[0];
                }

                function scenarioColorClass(color, active = false) {
                    if (active) {
                        if (color === 'green') return 'border-emerald-500 bg-emerald-50 text-emerald-900';
                        if (color === 'orange') return 'border-orange-500 bg-orange-50 text-orange-900';
                        return 'border-blue-500 bg-blue-50 text-blue-900';
                    }
                    return 'border-gray-200 bg-white text-gray-700 hover:border-blue-200 hover:bg-blue-50';
                }

                function renderScenarioButtons() {
                    const wrap = document.getElementById('sop-scenario-buttons');
                    wrap.innerHTML = Object.values(SOP_SCENARIOS).map((scenario) => {
                        const active = scenario.key === activeScenario;
                        return `<button type="button" data-sop-scenario="${escapeHtml(scenario.key)}" class="rounded-lg border p-4 text-left transition ${scenarioColorClass(scenario.color, active)}">
                            <div class="font-semibold">${escapeHtml(scenario.name)}</div>
                            <div class="mt-3 flex items-center justify-between text-xs">
                                <span>${scenario.node_count} 节点</span>
                                <span>${escapeHtml(scenario.trigger)}</span>
                            </div>
                        </button>`;
                    }).join('');
                    document.querySelectorAll('[data-sop-scenario]').forEach((button) => {
                        button.addEventListener('click', () => {
                            activeScenario = button.dataset.sopScenario;
                            selectedNodeCode = getScenario().nodes[0].code;
                            renderAll();
                        });
                    });
                }

                function renderOverview() {
                    const scenario = getScenario();
                    document.getElementById('sop-current-name').textContent = scenario.name;
                    document.getElementById('sop-current-trigger').textContent = scenario.trigger;
                    document.getElementById('sop-current-grain').textContent = scenario.time_grain;
                    const nodes = scenario.nodes;
                    const done = nodes.filter((node) => node.status === 'done' || node.status === 'skipped').length;
                    const inProgress = nodes.filter((node) => node.status === 'in_progress').length;
                    const pending = nodes.length - done - inProgress;
                    const progress = nodes.length ? Math.round(done / nodes.length * 100) : 0;
                    document.getElementById('sop-overall-progress').textContent = `${progress}%`;
                    document.getElementById('sop-overall-bar').style.width = `${progress}%`;
                    document.getElementById('sop-status-summary').innerHTML = [
                        ['已完成', done, 'text-emerald-700'],
                        ['执行中', inProgress, 'text-blue-700'],
                        ['待执行', pending, 'text-gray-600'],
                    ].map(([label, value, color]) => (
                        `<div class="rounded-lg bg-white p-2"><div class="font-bold ${color}">${value}</div><div class="text-gray-500">${label}</div></div>`
                    )).join('');
                }

                function renderViewButtons() {
                    document.querySelectorAll('.sop-view-btn').forEach((button) => {
                        const active = button.dataset.sopView === activeView;
                        button.className = `sop-view-btn rounded-md px-4 py-2 ${active ? 'bg-white text-blue-700 shadow-sm' : 'text-gray-600 hover:text-gray-900'}`;
                        button.onclick = () => {
                            activeView = button.dataset.sopView;
                            renderAll();
                        };
                    });
                    document.getElementById('sop-table-view').classList.toggle('hidden', activeView !== 'table');
                    document.getElementById('sop-gantt-view').classList.toggle('hidden', activeView !== 'gantt');
                    document.getElementById('sop-progress-view').classList.toggle('hidden', activeView !== 'progress');
                }

                function statusBadge(status, nodeCode, scenarioKey) {
                    const info = statusMap[status] || statusMap.pending;
                    return `<button type="button"
                        onclick="cycleNodeStatus(event,'${escapeHtml(nodeCode)}','${escapeHtml(scenarioKey)}')"
                        title="点击切换状态"
                        class="inline-flex whitespace-nowrap rounded-full border px-2.5 py-1 text-xs font-semibold cursor-pointer hover:opacity-80 transition-opacity ${info.className}"
                        data-node-status="${escapeHtml(status)}"
                        data-node-code-status="${escapeHtml(nodeCode)}"
                        data-scenario-status="${escapeHtml(scenarioKey)}">
                        ${info.label}
                    </button>`;
                }

                async function cycleNodeStatus(e, nodeCode, scenarioKey) {
                    e.stopPropagation();
                    const cycle = ['pending', 'in_progress', 'done'];
                    const scenario = SOP_SCENARIOS[scenarioKey];
                    if (!scenario) return;
                    const node = scenario.nodes.find((n) => n.code === nodeCode);
                    if (!node) return;
                    const cur = node.status || 'pending';
                    const next = cycle[(cycle.indexOf(cur) + 1) % cycle.length];
                    const btn = e.currentTarget;
                    btn.disabled = true;
                    try {
                        const body = new URLSearchParams({
                            action: 'update_node_status',
                            csrf_token: _sopCsrfToken,
                            scenario: scenarioKey,
                            node_code: nodeCode,
                            status: next,
                        });
                        const res = await fetch(window.location.pathname, { method: 'POST', body });
                        const json = await res.json();
                        if (json.ok) {
                            node.status = next;
                            renderAll(false);
                        }
                    } catch (_) {
                        btn.disabled = false;
                    }
                }

                function targetBadge(target) {
                    if (!target) {
                        return '<span class="inline-flex whitespace-nowrap rounded-full bg-gray-100 px-2.5 py-1 text-xs font-semibold text-gray-500">不可派发</span>';
                    }
                    return `<span class="inline-flex whitespace-nowrap rounded-full bg-blue-50 px-2.5 py-1 text-xs font-semibold text-blue-700">${escapeHtml(targetMap[target] || target)}</span>`;
                }

                function presaleText(node) {
                    return node.presale_text || (node.presale ? '可售前完成' : '签约后执行');
                }

                function weekText(node) {
                    return node.week || node.cycle || '-';
                }

                function weekClass(node) {
                    if (node.week === '第1周') return 'bg-sky-50/60';
                    if (node.week === '第2-3周') return 'bg-rose-50/50';
                    if (node.week === '第4周') return 'bg-emerald-50/50';
                    return '';
                }

                function serviceGroupClass(group) {
                    if (group === '策略服务') return 'bg-blue-50 text-blue-800';
                    if (group === '品牌 GEO 资产构建') return 'bg-emerald-50 text-emerald-800';
                    if (group === '执行&日常执行服务') return 'bg-orange-50 text-orange-800';
                    return 'bg-gray-50 text-gray-700';
                }

                function rowPhaseClass(node) {
                    if (activeScenario !== 'annual') return weekClass(node);
                    if (node.service_group === '策略服务') return 'bg-blue-50/35';
                    if (node.service_group === '品牌 GEO 资产构建') return 'bg-emerald-50/40';
                    if (node.service_group === '执行&日常执行服务') return 'bg-orange-50/35';
                    return '';
                }

                function timelineText(node) {
                    return activeScenario === 'annual' ? (node.cycle || '-') : weekText(node);
                }

                function renderTable() {
                    const nodes = getVisibleNodes();
                    const timelineLabel = activeScenario === 'annual' ? '服务周期' : '周次';
                    let lastServiceGroup = '';
                    document.getElementById('sop-table-view').innerHTML = `<table class="min-w-full table-fixed divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="w-[88px] px-4 py-3 text-left text-xs font-medium text-gray-500">${timelineLabel}</th>
                                <th class="w-[220px] px-4 py-3 text-left text-xs font-medium text-gray-500">节点</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500">KPI</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500">产出物</th>
                                <th class="w-[130px] px-4 py-3 text-left text-xs font-medium text-gray-500">负责人/周期</th>
                                <th class="w-[110px] px-4 py-3 text-left text-xs font-medium text-gray-500">可售前</th>
                                <th class="w-[90px] px-4 py-3 text-left text-xs font-medium text-gray-500">状态</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 bg-white">
                            ${nodes.map((node) => {
                                const serviceGroup = activeScenario === 'annual' ? (node.service_group || '其他服务') : '';
                                const groupRow = serviceGroup && serviceGroup !== lastServiceGroup
                                    ? `<tr><td colspan="7" class="px-4 py-3 text-xs font-bold ${serviceGroupClass(serviceGroup)}">${escapeHtml(serviceGroup)}</td></tr>`
                                    : '';
                                if (serviceGroup) lastServiceGroup = serviceGroup;
                                return `${groupRow}<tr data-node-code="${escapeHtml(node.code)}" class="cursor-pointer hover:bg-blue-50/70 ${rowPhaseClass(node)} ${node.code === selectedNodeCode ? '!bg-blue-50' : ''}">
                                <td class="px-4 py-4 align-top text-sm font-semibold text-gray-600">${escapeHtml(timelineText(node))}</td>
                                <td class="px-4 py-4 align-top">
                                    <div class="font-semibold text-gray-900">${escapeHtml(node.code)} ${escapeHtml(node.name)}</div>
                                    <div class="mt-2 text-xs leading-5 text-gray-500">${escapeHtml(node.action)}</div>
                                    <div class="mt-2 flex flex-wrap gap-2">${targetBadge(node.target)}</div>
                                </td>
                                <td class="px-4 py-4 align-top text-sm font-semibold leading-6 text-gray-900">${escapeHtml(node.kpi)}</td>
                                <td class="px-4 py-4 align-top text-sm leading-6 text-gray-600">${escapeHtml(node.deliverable)}</td>
                                <td class="px-4 py-4 align-top text-sm text-gray-600">
                                    <div class="font-semibold text-gray-800">${escapeHtml(node.owner)}</div>
                                    <div class="mt-1">${escapeHtml(node.cycle)}</div>
                                </td>
                                <td class="px-4 py-4 align-top text-sm font-semibold ${node.presale ? 'text-emerald-700' : 'text-gray-500'}">${escapeHtml(presaleText(node))}</td>
                                <td class="px-4 py-4 align-top">${statusBadge(node.status, node.code, activeScenario)}</td>
                            </tr>`;
                            }).join('')}
                        </tbody>
                    </table>`;
                    document.querySelectorAll('[data-node-code]').forEach((row) => {
                        row.addEventListener('click', () => {
                            selectedNodeCode = row.dataset.nodeCode;
                            renderAll(false);
                        });
                    });
                }

                function renderEmergencyGantt(scenario, nodes) {
                    const columns = scenario.span_labels.length;
                    const colPct = (100 / columns).toFixed(4);
                    document.getElementById('sop-gantt-view').innerHTML = `
                        <div class="overflow-x-auto">
                            <div class="min-w-[860px] space-y-1 pb-2">
                                <!-- Header -->
                                <div class="grid gap-2 rounded-lg bg-slate-800 px-3 py-2" style="grid-template-columns: 200px 1fr 120px 140px 120px;">
                                    <div class="text-xs font-semibold text-white">任务项</div>
                                    <div class="text-xs font-semibold text-white">时间轴</div>
                                    <div class="text-xs font-semibold text-amber-300">阶段目标</div>
                                    <div class="text-xs font-semibold text-amber-300">产出物</div>
                                    <div class="text-xs font-semibold text-amber-300">备注</div>
                                </div>
                                <!-- Timeline ticks -->
                                <div class="grid gap-2 px-3" style="grid-template-columns: 200px 1fr 120px 140px 120px;">
                                    <div></div>
                                    <div class="relative">
                                        ${scenario.span_labels.map((label, i) => `<span class="absolute text-center text-[10px] font-semibold text-gray-400 select-none" style="left:${(i * 100 / columns).toFixed(2)}%;width:${colPct}%">${escapeHtml(label)}</span>`).join('')}
                                        <div class="invisible text-xs">&nbsp;</div>
                                    </div>
                                    <div></div><div></div><div></div>
                                </div>
                                ${nodes.map((node) => {
                                    const start = Math.max(0, node.span[0]);
                                    const end = Math.min(columns - 1, node.span[1]);
                                    const barLeft = (start / columns * 100).toFixed(2);
                                    const barWidth = ((end - start + 1) / columns * 100).toFixed(2);
                                    const isCurrent = node.code === selectedNodeCode;
                                    const barBg = isCurrent ? 'bg-blue-600' : 'bg-red-200';
                                    const barText = isCurrent ? 'text-white' : 'text-red-900';
                                    const nodeBtn = isCurrent ? 'border-blue-500 bg-blue-50 text-blue-900' : 'border-gray-200 bg-white text-gray-900 hover:border-blue-300';
                                    return `<div class="grid items-center gap-2 px-3 py-1" style="grid-template-columns: 200px 1fr 120px 140px 120px;">
                                        <button type="button" data-node-code="${escapeHtml(node.code)}"
                                            class="rounded-lg border px-3 py-2 text-left text-sm font-semibold transition ${nodeBtn}">
                                            <span class="block leading-tight">${escapeHtml(node.code)} ${escapeHtml(node.name)}</span>
                                            <span class="mt-0.5 block text-[11px] font-normal text-gray-500">${escapeHtml(node.owner)} · ${escapeHtml(node.cycle)}</span>
                                        </button>
                                        <div class="relative rounded-full bg-gray-100" style="height:32px;">
                                            ${Array.from({ length: columns }).map((_, i) => `<div class="absolute inset-y-0 border-l border-gray-200/70" style="left:${(i / columns * 100).toFixed(2)}%"></div>`).join('')}
                                            <div class="${barBg} ${barText} absolute flex items-center overflow-hidden rounded-full px-3 text-[11px] font-semibold whitespace-nowrap"
                                                 style="left:${barLeft}%;width:${barWidth}%;top:0;bottom:0;"
                                                 title="${escapeHtml(node.cycle)}">
                                                ${escapeHtml(node.cycle)}
                                            </div>
                                        </div>
                                        <div class="rounded-lg bg-amber-50 px-2 py-2 text-xs leading-5 text-gray-800">${escapeHtml(node.kpi)}</div>
                                        <div class="rounded-lg bg-white px-2 py-2 text-xs leading-5 text-gray-700">${escapeHtml(node.deliverable)}</div>
                                        <div class="rounded-lg bg-white px-2 py-2 text-xs leading-5 text-gray-600">${escapeHtml(node.note || '-')}</div>
                                    </div>`;
                                }).join('')}
                            </div>
                        </div>`;
                    document.querySelectorAll('[data-node-code]').forEach((el) => {
                        el.addEventListener('click', () => {
                            selectedNodeCode = el.dataset.nodeCode;
                            renderAll(false);
                        });
                    });
                }

                function renderGantt() {
                    const scenario = getScenario();
                    const nodes = getVisibleNodes();
                    if (activeScenario === 'emergency') {
                        renderEmergencyGantt(scenario, nodes);
                        return;
                    }
                    const columns = scenario.span_labels.length;
                    const colPct = (100 / columns).toFixed(4);
                    document.getElementById('sop-gantt-view').innerHTML = `
                        <div class="overflow-x-auto">
                            <div class="min-w-[640px] space-y-2 pb-2">
                                <!-- Timeline header -->
                                <div class="flex items-center">
                                    <div class="w-52 shrink-0"></div>
                                    <div class="relative ml-3 flex-1">
                                        ${scenario.span_labels.map((label, i) => `<span class="absolute text-center text-[11px] font-semibold text-gray-400 select-none" style="left:${(i * 100 / columns).toFixed(2)}%;width:${colPct}%">${escapeHtml(label)}</span>`).join('')}
                                        <div class="invisible text-xs">&nbsp;</div>
                                    </div>
                                </div>
                                <!-- Grid lines overlay container -->
                                ${nodes.map((node) => {
                                    const start = Math.max(0, node.span[0]);
                                    const end = Math.min(columns - 1, node.span[1]);
                                    const barLeft = (start / columns * 100).toFixed(2);
                                    const barWidth = ((end - start + 1) / columns * 100).toFixed(2);
                                    const isCurrent = node.code === selectedNodeCode;
                                    const barBg = isCurrent ? 'bg-blue-600' : 'bg-blue-200';
                                    const barText = isCurrent ? 'text-white' : 'text-blue-900';
                                    const nodeBtn = isCurrent ? 'border-blue-500 bg-blue-50 text-blue-900' : 'border-gray-200 bg-white text-gray-900 hover:border-blue-300 hover:bg-blue-50/40';
                                    return `<div class="flex items-center">
                                        <button type="button" data-node-code="${escapeHtml(node.code)}"
                                            class="w-52 shrink-0 rounded-lg border px-3 py-2 text-left text-sm transition ${nodeBtn}">
                                            <span class="block font-semibold leading-tight">${escapeHtml(node.code)} ${escapeHtml(node.name)}</span>
                                            <span class="mt-0.5 block text-[11px] text-gray-500">${escapeHtml(node.owner)}</span>
                                        </button>
                                        <div class="relative ml-3 flex-1 rounded-full bg-gray-100" style="height:32px;">
                                            ${Array.from({ length: columns }).map((_, i) => `<div class="absolute inset-y-0 border-l border-gray-200/70" style="left:${(i / columns * 100).toFixed(2)}%"></div>`).join('')}
                                            <div class="${barBg} ${barText} absolute flex items-center overflow-hidden rounded-full px-3 text-[11px] font-semibold whitespace-nowrap"
                                                 style="left:${barLeft}%;width:${barWidth}%;top:0;bottom:0;"
                                                 title="${escapeHtml(node.cycle)}">
                                                ${escapeHtml(node.cycle)}
                                            </div>
                                        </div>
                                    </div>`;
                                }).join('')}
                            </div>
                        </div>`;
                    document.querySelectorAll('[data-node-code]').forEach((el) => {
                        el.addEventListener('click', () => {
                            selectedNodeCode = el.dataset.nodeCode;
                            renderAll(false);
                        });
                    });
                }

                function renderProgress() {
                    const nodes = getVisibleNodes();
                    document.getElementById('sop-progress-view').innerHTML = `<div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
                        ${nodes.map((node, index) => {
                            const info = statusMap[node.status] || statusMap.pending;
                            return `<button type="button" data-node-code="${escapeHtml(node.code)}" class="rounded-lg border p-4 text-left transition hover:border-blue-300 ${node.code === selectedNodeCode ? 'border-blue-500 bg-blue-50' : 'border-gray-200 bg-white'}">
                                <div class="flex items-start justify-between gap-3">
                                    <div>
                                        <div class="text-xs font-bold text-gray-400">${String(index + 1).padStart(2, '0')}</div>
                                        <div class="mt-2 font-semibold text-gray-900">${escapeHtml(node.code)} ${escapeHtml(node.name)}</div>
                                    </div>
                                    <span class="rounded-full border px-2 py-1 text-xs font-semibold ${info.className}">${info.label}</span>
                                </div>
                                <div class="mt-4 text-sm text-gray-600">${escapeHtml(node.deliverable)}</div>
                                <div class="mt-4 flex flex-wrap gap-2">${node.presale ? '<span class="rounded-full bg-emerald-50 px-2 py-1 text-xs text-emerald-700">可售前</span>' : ''}${targetBadge(node.target)}</div>
                            </button>`;
                        }).join('')}
                    </div>`;
                    document.querySelectorAll('[data-node-code]').forEach((row) => {
                        row.addEventListener('click', () => {
                            selectedNodeCode = row.dataset.nodeCode;
                            renderAll(false);
                        });
                    });
                }

                const targetPageMap = {
                    task: 'tasks.php',
                    content: 'articles.php',
                    media: 'distribution.php',
                    monitor: 'geo-monitor.php',
                };

                function buildDispatchUrl(node) {
                    const params = new URLSearchParams({
                        from_sop: '1',
                        sop_scenario: activeScenario,
                        sop_code: node.code,
                        sop_name: node.name,
                        sop_kpi: node.kpi || '',
                        sop_deliverable: node.deliverable || '',
                    });
                    return `${targetPageMap[node.target] || 'tasks.php'}?${params.toString()}`;
                }

                function buildTaskDispatchForm(node) {
                    // target=task 节点用 POST 表单写入 sop_dispatched_tasks 表
                    const adminBase = '<?= htmlspecialchars(rtrim(admin_url(''), '/')) ?>';
                    return `<form method="POST" action="${adminBase}/sop-center.php" class="mt-5">
                        <input type="hidden" name="csrf_token" value="${escapeHtml(_sopCsrfToken)}">
                        <input type="hidden" name="action" value="sop_dispatch_task">
                        <input type="hidden" name="sop_scenario" value="${escapeHtml(activeScenario)}">
                        <input type="hidden" name="sop_code" value="${escapeHtml(node.code)}">
                        <input type="hidden" name="sop_name" value="${escapeHtml(node.name)}">
                        <input type="hidden" name="sop_kpi" value="${escapeHtml(node.kpi || '')}">
                        <input type="hidden" name="sop_deliverable" value="${escapeHtml(node.deliverable || '')}">
                        <input type="hidden" name="due_days" value="7">
                        <button type="submit" class="inline-flex w-full items-center justify-center rounded-md bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800">
                            <svg class="mr-2 h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/></svg>
                            派发到任务管理
                        </button>
                    </form>`;
                }

                function renderNodeDetail() {
                    const node = getNode();
                    if (!node) return;
                    let dispatchButton;
                    if (!node.target) {
                        dispatchButton = '<button type="button" disabled class="mt-5 inline-flex w-full items-center justify-center rounded-md bg-gray-100 px-4 py-2 text-sm font-semibold text-gray-400">该节点暂不派发</button>';
                    } else if (node.target === 'task') {
                        dispatchButton = buildTaskDispatchForm(node);
                    } else {
                        dispatchButton = `<a href="${escapeHtml(buildDispatchUrl(node))}" class="mt-5 inline-flex w-full items-center justify-center rounded-md bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800"><i data-lucide="send" class="mr-2 h-4 w-4"></i>前往${escapeHtml(targetMap[node.target] || '任务管理')}</a>`;
                    }
                    document.getElementById('sop-node-detail').innerHTML = `
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <div class="text-sm font-semibold text-blue-700">${escapeHtml(node.code)}</div>
                                <h3 class="mt-1 text-xl font-bold text-gray-900">${escapeHtml(node.name)}</h3>
                            </div>
                            ${statusBadge(node.status, node.code, activeScenario)}
                        </div>
                        <dl class="mt-5 space-y-3 text-sm">
                            <div class="rounded-lg bg-gray-50 p-4">
                                <dt class="font-semibold text-gray-500">具体动作/服务描述</dt>
                                <dd class="mt-1 leading-6 text-gray-900">${escapeHtml(node.action)}</dd>
                            </div>
                            <div class="rounded-lg bg-orange-50 p-4">
                                <dt class="font-semibold text-orange-700">未达标动作</dt>
                                <dd class="mt-1 leading-6 text-orange-950">${escapeHtml(node.unmet_action || '按项目复盘结果补充处理动作。')}</dd>
                            </div>
                            ${node.note ? `<div class="rounded-lg bg-gray-50 p-4">
                                <dt class="font-semibold text-gray-500">备注</dt>
                                <dd class="mt-1 leading-6 text-gray-900">${escapeHtml(node.note)}</dd>
                            </div>` : ''}
                        </dl>
                        ${dispatchButton}`;
                    if (typeof lucide !== 'undefined') lucide.createIcons();
                }

                function renderAll(updateScenarioButtons = true) {
                    if (!getVisibleNodes().some((node) => node.code === selectedNodeCode)) {
                        selectedNodeCode = getVisibleNodes()[0]?.code || getScenario().nodes[0].code;
                    }
                    if (updateScenarioButtons) renderScenarioButtons();
                    renderOverview();
                    renderViewButtons();
                    renderTable();
                    renderGantt();
                    renderProgress();
                    renderNodeDetail();
                    if (typeof lucide !== 'undefined') lucide.createIcons();
                }

                function initSopCenter() {
                    var el = document.getElementById('sop-presale-only');
                    if (!el) return;
                    el.addEventListener('change', () => renderAll());
                    renderAll();
                }
                initSopCenter();
                document.addEventListener('htmx:afterSettle', function() {
                    if (document.getElementById('sop-presale-only')) initSopCenter();
                });
            </script>
<?php
require_once __DIR__ . '/includes/footer.php';
?>
