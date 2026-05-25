<?php
/**
 * GEO 意图挖掘 - AJAX 接口
 * POST /admin/api/intent-mine.php
 */
define('FEISHU_TREASURE', true);
set_time_limit(180);
session_start();

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/database_admin.php';

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['admin_id']) && empty($_SESSION['admin_username'])) {
    http_response_code(401);
    echo json_encode(['error' => '请先登录']);
    exit;
}

session_write_close();

$cid = trim($_POST['customer_id'] ?? '');
if (!$cid) { echo json_encode(['error' => '请选择客户']); exit; }

// 1. Load brand facts
$stmt = $db->prepare("SELECT fact_key, fact_value FROM geo_brand_facts WHERE customer_id=?");
$stmt->execute([$cid]);
$facts = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $facts[$r['fact_key']] = $r['fact_value'];
$brandName      = $facts['brand_name'] ?? $cid;
$industry       = $facts['industry'] ?? '';
$coreServices   = $facts['core_service'] ?? $facts['core_services'] ?? '';
$masterSentence = $facts['master_sentence'] ?? '';
$differentiator = $facts['differentiator'] ?? '';
$targetClient   = $facts['target_client'] ?? '';

// 2. Load competitors
$stmtComp = $db->prepare("SELECT competitor FROM geo_customer_competitors WHERE customer_id=? AND enabled=TRUE ORDER BY id");
$stmtComp->execute([$cid]);
$competitors = $stmtComp->fetchAll(PDO::FETCH_COLUMN);
$compDisplay = !empty($competitors) ? implode('、', $competitors) : '未设置';

// 3. Load existing monitored keywords
$stmtKw = $db->prepare("
    SELECT query_text, COUNT(*) AS total,
           SUM(CASE WHEN brand_mentioned THEN 1 ELSE 0 END) AS hits
    FROM geo_monitor_records
    WHERE customer_id=? AND queried_at >= CURRENT_DATE - INTERVAL '29 days'
    GROUP BY query_text ORDER BY hits DESC
");
$stmtKw->execute([$cid]);
$kwData = $stmtKw->fetchAll(PDO::FETCH_ASSOC);
$existingKwText = '';
foreach ($kwData as $kw) {
    $rate = $kw['total'] > 0 ? round($kw['hits'] / $kw['total'] * 100, 1) : 0;
    $existingKwText .= "- 「{$kw['query_text']}」提及率 {$rate}%（{$kw['hits']}/{$kw['total']}）\n";
}
if (empty($existingKwText)) $existingKwText = "暂无监测数据\n";

// 4. Load existing published articles
$stmtArt = $db->prepare("
    SELECT a.title FROM articles a
    JOIN tasks t ON t.id = a.task_id
    WHERE t.geo_customer_id = ? AND a.deleted_at IS NULL
    ORDER BY a.created_at DESC LIMIT 30
");
$stmtArt->execute([$cid]);
$articles = $stmtArt->fetchAll(PDO::FETCH_COLUMN);
$articleText = !empty($articles) ? implode("\n", array_map(fn($a) => "- {$a}", $articles)) : "暂无已发布文章\n";

// 5. Build brand facts summary
$factSummary = '';
$keyFacts = ['brand_name', 'industry', 'core_service', 'core_services', 'master_sentence', 'differentiator', 'target_client', 'website'];
foreach ($keyFacts as $fk) {
    if (!empty($facts[$fk])) $factSummary .= "- {$fk}: {$facts[$fk]}\n";
}

// 6. Load prompt template
$templatePath = dirname(__DIR__, 2) . '/prompts/intent_mining.md';
if (!file_exists($templatePath)) {
    echo json_encode(['error' => '意图挖掘模板文件不存在']); exit;
}
$template = file_get_contents($templatePath);

// 7. Build prompt directly (more reliable than template)
$prompt = "你是GEO意图挖掘分析师。基于以下品牌数据，挖掘用户在AI对话中会问但品牌尚未覆盖的问题。

## 品牌档案
- 品牌名称：{$brandName}
- 品牌定位：{$masterSentence}
- 核心服务：{$coreServices}
- 差异化：{$differentiator}
- 目标客户：{$targetClient}
- 行业：{$industry}
- 竞品：{$compDisplay}

## 已监测关键词（近30天）
{$existingKwText}

## 已发布文章
{$articleText}

## 品牌事实
{$factSummary}

## 要求
从以下7个维度各挖掘3-5个问题（共21-35个），每个问题必须：
1. 与品牌实际业务相关
2. 用真实用户语气（像在Kimi/DeepSeek里打出来的）
3. 如果已监测关键词或已发布文章中有类似内容，标covered=true
4. 有明确的商业价值

维度：品牌认知、品类发现、购买决策、场景问题、竞品对比、风险质疑、行业趋势

严格输出JSON，不要有其他文字：
{\"themes\":[{\"name\":\"主题名\",\"icon\":\"emoji\",\"dimension\":\"维度\",\"questions\":[{\"q\":\"问题\",\"intent\":\"维度\",\"priority\":\"P0/P1/P2\",\"covered\":false,\"reason\":\"原因\",\"suggested_action\":\"建议\"}]}]}";

// 8. Call AI (longer timeout for complex prompt)
$cfg = get_active_ai_config();
if (empty($cfg['api_key'])) {
    echo json_encode(['error' => 'AI 模型未配置']); exit;
}
$apiUrl = rtrim($cfg['api_url'], '/');
if (!str_ends_with($apiUrl, '/chat/completions')) {
    $apiUrl .= '/chat/completions';
}
$payload = json_encode([
    'model'       => $cfg['model_id'],
    'messages'    => [['role' => 'user', 'content' => $prompt]],
    'max_tokens'  => 6000,
    'temperature' => 0.3,
], JSON_UNESCAPED_UNICODE);
$ch = curl_init($apiUrl);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 180,
    CURLOPT_CONNECTTIMEOUT => 15,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $cfg['api_key'],
    ],
]);
$raw  = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$cerr = curl_error($ch);
curl_close($ch);
if ($code === 200) {
    $data = json_decode($raw, true);
    $aiResult = ['content' => $data['choices'][0]['message']['content'] ?? '', 'model_used' => $cfg['model_id'], 'error' => null];
} else {
    $aiResult = ['content' => '', 'model_used' => 'none', 'error' => "HTTP {$code}: {$cerr}"];
}
if (!empty($aiResult['error'])) {
    echo json_encode(['error' => "AI 调用失败: {$aiResult['error']}"]); exit;
}
$content = $aiResult['content'] ?? '';

// 9. Parse JSON response (strip markdown code fences if present)
$content = trim($content);
$content = preg_replace('/^```(?:json)?\s*/i', '', $content);
$content = preg_replace('/\s*```$/', '', $content);
$content = trim($content);
if (preg_match('/\{[\s\S]*\}/', $content, $m)) {
    $result = json_decode($m[0], true);
    if ($result && isset($result['themes'])) {
        // 10. Save to database
        try {
            $db->prepare("DELETE FROM geo_intent_questions WHERE customer_id=?")->execute([$cid]);
            $ins = $db->prepare("INSERT INTO geo_intent_questions
                (customer_id, theme, question, intent_type, priority, covered, reason, suggested_action, dimension)
                VALUES (?,?,?,?,?,?,?,?,?)");
            foreach ($result['themes'] as $theme) {
                foreach (($theme['questions'] ?? []) as $q) {
                    $ins->execute([
                        $cid,
                        $theme['name'],
                        $q['q'],
                        $q['intent'] ?? $theme['dimension'] ?? '',
                        $q['priority'] ?? 'P1',
                        ($q['covered'] ?? false) ? 1 : 0,
                        $q['reason'] ?? '',
                        $q['suggested_action'] ?? '',
                        $theme['dimension'] ?? $q['intent'] ?? '',
                    ]);
                }
            }
        } catch (Throwable $e) {}

        echo json_encode([
            'ok'         => true,
            'data'       => $result,
            'brand'      => $brandName,
            'model_used' => $aiResult['model_used'] ?? 'unknown',
            'prompt_used'=> $prompt,
        ]);
    } else {
        echo json_encode(['error' => 'AI 返回的 JSON 结构不符合预期', 'raw' => substr($content, 0, 500)]);
    }
} else {
    echo json_encode(['error' => 'AI 未返回有效 JSON', 'raw' => substr($content, 0, 500)]);
}
