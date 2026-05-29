<?php
/**
 * 动态工作流 AI 推荐接口
 * POST {customer_id, workflow_id?} → AI 决策哪些步骤应该运行/跳过
 */
define('FEISHU_TREASURE', true);
session_start();
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/database_admin.php';
require_admin_login();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => '仅支持 POST']);
    exit;
}

$customerId  = trim($_POST['customer_id'] ?? '');
$workflowId  = trim($_POST['workflow_id'] ?? '');

if ($customerId === '') {
    echo json_encode(['success' => false, 'error' => '缺少 customer_id']);
    exit;
}

// 收集品牌当前状态
$brandFacts = [];
$stmtFacts  = $db->prepare("SELECT fact_key, fact_value FROM geo_brand_facts WHERE customer_id = ?");
$stmtFacts->execute([$customerId]);
foreach ($stmtFacts->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $brandFacts[$row['fact_key']] = $row['fact_value'];
}

$brandName = $brandFacts['brand_name'] ?? $customerId;

// 文章数
$stmtArt = $db->prepare("SELECT COUNT(*) FROM articles a JOIN tasks t ON a.task_id = t.id WHERE t.geo_customer_id = ? AND a.deleted_at IS NULL");
$stmtArt->execute([$customerId]);
$articleCount = (int)$stmtArt->fetchColumn();

// 监测记录数
$stmtMon = $db->prepare("SELECT COUNT(*) FROM geo_monitor_records WHERE customer_id = ? AND queried_at >= CURRENT_DATE - INTERVAL '7 days'");
$stmtMon->execute([$customerId]);
$monitorCount = (int)$stmtMon->fetchColumn();

// 当前引用率
$stmtRate = $db->prepare("SELECT ROUND(100.0 * SUM(CASE WHEN brand_mentioned THEN 1 ELSE 0 END) / NULLIF(COUNT(*), 0), 1) FROM geo_monitor_records WHERE customer_id = ? AND queried_at >= CURRENT_DATE - INTERVAL '7 days'");
$stmtRate->execute([$customerId]);
$citationRate = (float)($stmtRate->fetchColumn() ?? 0);

// 诊断分数
$stmtDiag = $db->prepare("SELECT overall_score FROM geo_diagnosis_runs WHERE customer_id = ? ORDER BY created_at DESC LIMIT 1");
try {
    $stmtDiag->execute([$customerId]);
    $diagScore = (float)($stmtDiag->fetchColumn() ?? 0);
} catch (Throwable $e) {
    $diagScore = 0;
}

// 关键词数量
$stmtKw = $db->prepare("SELECT COUNT(*) FROM geo_monitor_keywords WHERE customer_id = ?");
$stmtKw->execute([$customerId]);
$keywordCount = (int)$stmtKw->fetchColumn();

// 知识库
$stmtKb = $db->prepare("SELECT COUNT(*) FROM knowledge_bases WHERE customer_id = ?");
try {
    $stmtKb->execute([$customerId]);
    $kbCount = (int)$stmtKb->fetchColumn();
} catch (Throwable $e) {
    $kbCount = 0;
}

$statusSummary = <<<SUMMARY
品牌：{$brandName}
文章数量：{$articleCount} 篇
本周监测次数：{$monitorCount} 次
当前AI引用率：{$citationRate}%
诊断综合评分：{$diagScore}/100
监测关键词数：{$keywordCount} 个
知识库：{$kbCount} 个
SUMMARY;

$prompt = <<<PROMPT
你是GEO内容运营顾问。根据以下品牌的当前GEO运营状态，判断本轮自动化工作流中哪些步骤应该执行、哪些可以跳过。

品牌当前状态：
{$statusSummary}

可用的自动化步骤清单：
- keyword_library: 生成关键词库（首次必须，已有时可跳过）
- title_library: 生成标题库（首次必须，已有时可跳过）
- knowledge_base: 生成知识库（首次必须，内容少时重新生成）
- customer_create: 创建客户档案（已有时跳过）
- task_create: 创建生成任务（内容不足时必须）
- article_generate: 批量生成文章（文章不足或引用率低时必须）
- media_distribute: 分发到媒体平台（有新文章时执行）
- monitor_setup: 配置监测关键词（关键词不足时执行）
- monitor_run: 立即执行一次监测（数据陈旧时执行）
- diagnosis: GEO雷达诊断（分数低于60时重新诊断）
- strategy: 生成本周内容策略（每周一次）
- weekly_report: 发送周报（有足够数据时执行）

请以JSON格式输出推荐方案，格式：
{"run":["步骤1","步骤2"],"skip":["步骤3"],"reason":"一句话说明核心判断逻辑","priority_note":"最重要的1件事"}
PROMPT;

$result = geo_call_ai($prompt, 1000, 0.3);

if (!empty($result['error']) || empty(trim($result['content'] ?? ''))) {
    echo json_encode(['success' => false, 'error' => 'AI调用失败: ' . ($result['error'] ?? '空响应')]);
    exit;
}

// 解析 AI 返回的 JSON
$content = trim($result['content']);
// 提取 JSON（AI 可能包了一些说明文字）
if (preg_match('/\{[\s\S]*\}/u', $content, $m)) {
    $content = $m[0];
}
$parsed = json_decode($content, true);

if (!$parsed) {
    echo json_encode([
        'success'    => true,
        'parsed'     => false,
        'raw_output' => $result['content'],
        'status'     => ['article_count' => $articleCount, 'citation_rate' => $citationRate, 'diag_score' => $diagScore],
    ]);
    exit;
}

echo json_encode([
    'success'      => true,
    'parsed'       => true,
    'run'          => $parsed['run'] ?? [],
    'skip'         => $parsed['skip'] ?? [],
    'reason'       => $parsed['reason'] ?? '',
    'priority_note'=> $parsed['priority_note'] ?? '',
    'status'       => [
        'brand_name'    => $brandName,
        'article_count' => $articleCount,
        'citation_rate' => $citationRate,
        'diag_score'    => $diagScore,
        'keyword_count' => $keywordCount,
    ],
    'model_used'   => $result['model_used'] ?? 'AI',
]);
