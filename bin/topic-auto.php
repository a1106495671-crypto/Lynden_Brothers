<?php
/**
 * GEO 选题自动化 - 每日扫描覆盖空白，自动生成本周选题
 * Cron: 0 7 * * * php bin/topic-auto.php
 */
define('FEISHU_TREASURE', true);

$projectRoot = dirname(__DIR__);
chdir($projectRoot);

require_once $projectRoot . '/includes/config.php';
require_once $projectRoot . '/includes/database_admin.php';

set_time_limit(300);

function ta_log(string $msg): void {
    echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
}

ta_log('选题自动化开始');

// 确保 geo_topic_suggestions 表存在
try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS geo_topic_suggestions (
            id BIGSERIAL PRIMARY KEY,
            customer_id VARCHAR(80) NOT NULL,
            title TEXT NOT NULL,
            source_keyword VARCHAR(200),
            mention_rate DECIMAL(5,2),
            status VARCHAR(20) DEFAULT 'pending',
            task_id BIGINT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");
} catch (Throwable $e) {
    ta_log('建表失败: ' . $e->getMessage());
}

// 取所有有活跃任务且有监测数据的客户
$stmt = $db->query("
    SELECT DISTINCT t.geo_customer_id AS customer_id
    FROM tasks t
    WHERE t.status = 'active'
      AND t.geo_customer_id IS NOT NULL
      AND t.geo_customer_id != ''
      AND EXISTS (
          SELECT 1 FROM geo_monitor_keywords mk WHERE mk.customer_id = t.geo_customer_id
      )
");
$customers = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];

if (empty($customers)) {
    ta_log('没有符合条件的客户，退出');
    exit(0);
}

ta_log('找到 ' . count($customers) . ' 个客户');

foreach ($customers as $cid) {
    ta_log("处理客户: {$cid}");

    // 品牌事实
    $stmtFacts = $db->prepare("SELECT fact_key, fact_value FROM geo_brand_facts WHERE customer_id = ?");
    $stmtFacts->execute([$cid]);
    $facts = [];
    foreach ($stmtFacts->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $facts[$row['fact_key']] = $row['fact_value'];
    }
    $brandName    = $facts['brand_name']    ?? $cid;
    $industry     = $facts['industry']      ?? '';
    $coreServices = $facts['core_services'] ?? '';
    $competitors  = $facts['competitors']   ?? '';

    // 最近 30 篇已发布文章标题（避免重复）
    $stmtTitles = $db->prepare("
        SELECT a.title
        FROM articles a
        JOIN tasks t ON a.task_id = t.id
        WHERE t.geo_customer_id = ?
          AND a.status IN ('published', 'draft')
          AND a.deleted_at IS NULL
        ORDER BY a.created_at DESC
        LIMIT 30
    ");
    $stmtTitles->execute([$cid]);
    $recentTitles = $stmtTitles->fetchAll(PDO::FETCH_COLUMN);

    // 过去 14 天各关键词提及率，找弱势词（<40%）
    $stmtKw = $db->prepare("
        SELECT
            query_text,
            ROUND(100.0 * SUM(CASE WHEN brand_mentioned THEN 1 ELSE 0 END) / COUNT(*), 1) AS mention_rate,
            COUNT(*) AS total
        FROM geo_monitor_records
        WHERE customer_id = ?
          AND queried_at >= CURRENT_DATE - INTERVAL '14 days'
        GROUP BY query_text
        ORDER BY mention_rate ASC
    ");
    $stmtKw->execute([$cid]);
    $kwData = $stmtKw->fetchAll(PDO::FETCH_ASSOC);

    $weakKws = array_filter($kwData, fn($k) => (float)$k['mention_rate'] < 40);
    if (empty($weakKws)) {
        // 若全部较好，取最低的3个作为优化目标
        $weakKws = array_slice($kwData, 0, 3);
    }

    if (empty($weakKws)) {
        ta_log("  {$cid}: 无监测数据，跳过");
        continue;
    }

    $weakKwLines  = implode("\n", array_map(fn($k) => "- 「{$k['query_text']}」当前提及率 {$k['mention_rate']}%", $weakKws));
    $recentTitleBlock = empty($recentTitles) ? '（暂无）' : implode("\n", array_map(fn($t) => "- {$t}", array_slice($recentTitles, 0, 15)));

    $prompt = <<<PROMPT
你是GEO内容策略师。根据以下品牌信息和内容现状，生成5个本周优先创作的文章标题。

品牌：{$brandName}
行业：{$industry}
核心服务：{$coreServices}
竞品：{$competitors}

覆盖薄弱的关键词（AI引用率不足40%，需要重点补内容）：
{$weakKwLines}

已有文章（避免重复，禁止出现相同主题）：
{$recentTitleBlock}

要求：
1. 每个标题必须直接服务于一个薄弱关键词
2. 优先使用：「X的N个判断标准」「为什么选X而非Y」「X是什么：{品牌}的实操定义」「X的完整流程」等问答式结构
3. 标题包含关键词，适合被AI大模型直接引用为答案
4. 只输出5个标题，每行一个，不加序号、不加引号、不加说明
PROMPT;

    $result = geo_call_ai($prompt, 800, 0.6);
    if (!empty($result['error']) || empty(trim($result['content'] ?? ''))) {
        ta_log("  {$cid}: AI调用失败 - " . ($result['error'] ?? '空响应'));
        continue;
    }

    $titles = array_filter(array_map('trim', explode("\n", $result['content'])));
    $titles = array_values(array_slice($titles, 0, 5));

    if (empty($titles)) {
        ta_log("  {$cid}: AI未返回有效标题");
        continue;
    }

    // 找该客户的主要活跃任务 ID
    $stmtTask = $db->prepare("SELECT id FROM tasks WHERE geo_customer_id = ? AND status = 'active' ORDER BY updated_at DESC LIMIT 1");
    $stmtTask->execute([$cid]);
    $taskId = $stmtTask->fetchColumn() ?: null;

    // 存入 geo_topic_suggestions，关联到提及率最低的关键词
    $weakKwsList = array_values($weakKws);
    $insertStmt  = $db->prepare("
        INSERT INTO geo_topic_suggestions (customer_id, title, source_keyword, mention_rate, status, task_id, created_at)
        VALUES (?, ?, ?, ?, 'pending', ?, CURRENT_TIMESTAMP)
    ");

    $saved = 0;
    foreach ($titles as $i => $title) {
        $sourceKw    = $weakKwsList[$i]['query_text'] ?? ($weakKwsList[0]['query_text'] ?? null);
        $mentionRate = $weakKwsList[$i]['mention_rate'] ?? ($weakKwsList[0]['mention_rate'] ?? null);
        $insertStmt->execute([$cid, $title, $sourceKw, $mentionRate, $taskId]);
        $saved++;
    }

    ta_log("  {$cid} ({$brandName}): 生成并保存 {$saved} 条选题");
}

ta_log('选题自动化完成');
