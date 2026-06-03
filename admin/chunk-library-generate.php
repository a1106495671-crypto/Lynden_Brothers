<?php
/**
 * 从知识库 Chunk 生成关键词库与标题库
 */

define('FEISHU_TREASURE', true);
session_start();

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database_admin.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/knowledge-retrieval.php';
require_once __DIR__ . '/includes/material-library-helpers.php';

require_admin_login();

$csrf_token = generate_csrf_token();
$page_title = 'Chunk 生成素材库';
$message = '';
$error = '';
$generated = null;

const CHUNK_ASSET_CONTEXT_LIMIT = 14;
const CHUNK_ASSET_CONTEXT_MAX_CHARS = 9000;
const CHUNK_ASSET_AI_TIMEOUT_SECONDS = 120;
const CHUNK_ASSET_AI_MAX_TOKENS = 5000;
const CHUNK_ASSET_BATCH_KEYWORDS = 5;
const CHUNK_ASSET_TITLE_BATCH_SIZE = 8;
const CHUNK_ASSET_BATCH_CONTEXT_CHUNKS = 5;
const CHUNK_ASSET_BATCH_CONTEXT_MAX_CHARS = 3600;

function chunk_asset_clean_json(string $text): string {
    $text = trim($text);
    $text = preg_replace('/^```(?:json)?\s*/iu', '', $text);
    $text = preg_replace('/\s*```$/u', '', $text);
    $start = strpos($text, '{');
    $end = strrpos($text, '}');
    if ($start !== false && $end !== false && $end > $start) {
        return substr($text, $start, $end - $start + 1);
    }
    return $text;
}

function chunk_asset_call_chat_model(PDO $db, array $model, string $systemPrompt, string $userPrompt, int $maxTokens = CHUNK_ASSET_AI_MAX_TOKENS): string {
    $apiKey = trim(decrypt_ai_api_key((string) ($model['api_key'] ?? '')));
    $modelId = trim((string) ($model['model_id'] ?? ''));
    $url = ai_build_chat_completions_url((string) ($model['api_url'] ?? ''));

    if ($apiKey === '' || $modelId === '' || $url === '') {
        throw new RuntimeException('AI 模型配置不完整，请先检查 API Key、模型 ID 和接口地址。');
    }

    $payload = [
        'model' => $modelId,
        'messages' => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userPrompt],
        ],
        'temperature' => 0.35,
        'max_tokens' => max(1200, min(CHUNK_ASSET_AI_MAX_TOKENS, $maxTokens)),
        'response_format' => ['type' => 'json_object'],
    ];

    $ch = curl_init($url);
    apply_curl_network_defaults($ch);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_TIMEOUT => CHUNK_ASSET_AI_TIMEOUT_SECONDS,
        CURLOPT_CONNECTTIMEOUT => 12,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);

    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError !== '') {
        throw new RuntimeException('AI 请求失败：' . $curlError);
    }
    if ($code < 200 || $code >= 300) {
        throw new RuntimeException('AI 请求失败，HTTP ' . $code . '：' . mb_substr((string) $raw, 0, 500));
    }

    $data = json_decode((string) $raw, true);
    $content = is_array($data) ? chunk_asset_extract_chat_content($data) : '';
    if ($content === '') {
        throw new RuntimeException('AI 响应里没有可用内容：' . chunk_asset_response_diagnostic($data, (string) $raw));
    }

    try {
        $db->prepare("UPDATE ai_models SET used_today=COALESCE(used_today,0)+1, total_used=COALESCE(total_used,0)+1, updated_at=CURRENT_TIMESTAMP WHERE id=?")->execute([(int) $model['id']]);
    } catch (Throwable $e) {}

    return trim($content);
}

function chunk_asset_extract_chat_content(array $data): string {
    $choice = $data['choices'][0] ?? [];
    $message = is_array($choice) ? ($choice['message'] ?? []) : [];

    if (is_array($message)) {
        $content = $message['content'] ?? '';
        if (is_string($content) && trim($content) !== '') {
            return trim($content);
        }
        if (is_array($content)) {
            $parts = [];
            foreach ($content as $part) {
                if (is_array($part)) {
                    $parts[] = (string) ($part['text'] ?? $part['content'] ?? '');
                } elseif (is_string($part)) {
                    $parts[] = $part;
                }
            }
            $joined = trim(implode("\n", array_filter($parts)));
            if ($joined !== '') {
                return $joined;
            }
        }
        if (!empty($message['reasoning_content']) && is_string($message['reasoning_content'])) {
            return trim((string) $message['reasoning_content']);
        }
    }

    foreach (['text', 'output_text', 'response', 'content'] as $field) {
        if (!empty($choice[$field]) && is_string($choice[$field])) {
            return trim((string) $choice[$field]);
        }
        if (!empty($data[$field]) && is_string($data[$field])) {
            return trim((string) $data[$field]);
        }
    }

    return '';
}

function chunk_asset_response_diagnostic($data, string $raw): string {
    if (!is_array($data)) {
        return '响应不是 JSON：' . mb_substr($raw, 0, 300, 'UTF-8');
    }

    $choice = $data['choices'][0] ?? [];
    $message = is_array($choice) ? ($choice['message'] ?? []) : [];
    $diagnostic = [
        'finish_reason' => is_array($choice) ? ($choice['finish_reason'] ?? null) : null,
        'message_keys' => is_array($message) ? array_keys($message) : [],
        'usage' => $data['usage'] ?? null,
        'error' => $data['error']['message'] ?? $data['message'] ?? null,
    ];

    return json_encode($diagnostic, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function chunk_asset_trim_context(string $context, int $maxChars = CHUNK_ASSET_CONTEXT_MAX_CHARS): string {
    $context = trim($context);
    if (mb_strlen($context, 'UTF-8') <= $maxChars) {
        return $context;
    }

    return rtrim(mb_substr($context, 0, $maxChars, 'UTF-8')) . "\n\n【系统提示】以上为本次生成采用的优先证据片段，已按相关性截断。";
}

function chunk_asset_normalize_list($items, string $key, int $limit): array {
    if ($limit <= 0) {
        return [];
    }

    $result = [];
    foreach (is_array($items) ? $items : [] as $item) {
        $value = is_array($item) ? (string) ($item[$key] ?? '') : (string) $item;
        $value = trim(preg_replace('/^\d+[\.\)、\)]\s*/u', '', $value));
        if ($value === '') {
            continue;
        }
        $dedupeKey = mb_strtolower($value, 'UTF-8');
        if (!isset($result[$dedupeKey])) {
            $result[$dedupeKey] = $value;
        }
        if (count($result) >= $limit) {
            break;
        }
    }
    return array_values($result);
}

function chunk_asset_fetch_context(PDO $db, int $knowledgeBaseId, string $intent, int $limit = CHUNK_ASSET_CONTEXT_LIMIT): array {
    $query = trim($intent);
    if ($query === '') {
        $query = '根据知识库内容自动识别客户最可能提问的问题 购买决策 痛点 对比 价格 成本 方案 适用场景 实施风险 案例 FAQ 行业信任';
    }

    $retrieved = knowledge_retrieval_fetch_context($db, $knowledgeBaseId, $query, $limit, CHUNK_ASSET_CONTEXT_MAX_CHARS);
    if (!empty($retrieved['chunks'])) {
        $retrieved['context'] = chunk_asset_trim_context((string) ($retrieved['context'] ?? ''));
        return $retrieved;
    }

    $stmt = $db->prepare("
        SELECT id, chunk_index, content, token_count
        FROM knowledge_chunks
        WHERE knowledge_base_id = ?
        ORDER BY
          CASE WHEN embedding_model_id IS NOT NULL AND embedding_model_id > 0 THEN 0 ELSE 1 END,
          chunk_index ASC
        LIMIT ?
    ");
    $stmt->bindValue(1, $knowledgeBaseId, PDO::PARAM_INT);
    $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    $stmt->execute();
    $chunks = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $parts = [];
    foreach ($chunks as $index => $chunk) {
        $parts[] = '【知识片段' . ($index + 1) . "】\n" . knowledge_retrieval_normalize_text((string) ($chunk['content'] ?? ''));
    }

    return ['context' => chunk_asset_trim_context(trim(implode("\n\n", $parts))), 'chunks' => $chunks];
}

function chunk_asset_context_from_chunks(array $chunks, int $batchIndex): string {
    $chunks = array_values($chunks);
    if (empty($chunks)) {
        return '';
    }

    $chunkCount = count($chunks);
    $start = ($batchIndex * CHUNK_ASSET_BATCH_CONTEXT_CHUNKS) % $chunkCount;
    $selected = [];
    for ($offset = 0; $offset < min(CHUNK_ASSET_BATCH_CONTEXT_CHUNKS, $chunkCount); $offset++) {
        $selected[] = $chunks[($start + $offset) % $chunkCount];
    }

    $parts = [];
    foreach ($selected as $index => $chunk) {
        $label = '【知识片段' . ($index + 1) . ' / chunk #' . (int) ($chunk['chunk_index'] ?? $index) . "】\n";
        $parts[] = $label . knowledge_retrieval_normalize_text((string) ($chunk['content'] ?? ''));
    }

    return chunk_asset_trim_context(trim(implode("\n\n", $parts)), CHUNK_ASSET_BATCH_CONTEXT_MAX_CHARS);
}

function chunk_asset_merge_unique(array $base, array $items, int $limit): array {
    $merged = [];
    foreach ([...$base, ...$items] as $item) {
        $value = trim((string) $item);
        if ($value === '') {
            continue;
        }
        $key = mb_strtolower($value, 'UTF-8');
        if (!isset($merged[$key])) {
            $merged[$key] = $value;
        }
        if (count($merged) >= $limit) {
            break;
        }
    }

    return array_values($merged);
}

function chunk_asset_keyword_batch_prompt(
    string $knowledgeBaseName,
    string $intentInstruction,
    string $context,
    int $batchNumber,
    int $batchTotal,
    int $keywordCount,
    array $existingKeywords
): string {
    $avoidKeywords = empty($existingKeywords) ? '无' : implode('、', array_slice($existingKeywords, -20));

    return <<<PROMPT
知识库名称：{$knowledgeBaseName}
批次：{$batchNumber} / {$batchTotal}
{$intentInstruction}

下面是本批次召回的 chunk。请只基于这些 chunk 生成本批次素材：
{$context}

已经生成过的关键词，必须避免重复：
{$avoidKeywords}

请输出紧凑严格 JSON，格式如下。两个字段都必须是字符串数组，不要输出对象：
{
  "question_directions": [
    "客户最可能问的问题方向（chunk #编号）"
  ],
  "keywords": [
    "短关键词或长尾问法（chunk #编号）"
  ]
}

生成要求：
1. 本批次 keywords 生成 {$keywordCount} 条。
2. 每条都必须能追溯到本批次 chunk，不要写知识片段里没有依据的事实。
3. 关键词要包含短词、长尾词和问句式关键词，优先模拟客户真实会问 AI 的表达。
4. 如果本批次是第 1 批，请归纳 3-5 个 question_directions；后续批次可以返回空数组。
5. 不要 reason、source、intent 等额外字段，不要 Markdown，不要解释，只返回 JSON。
PROMPT;
}

function chunk_asset_title_batch_prompt(
    string $knowledgeBaseName,
    array $keywords,
    int $titleCount,
    array $existingTitles
): string {
    $keywordList = implode("\n", array_map(fn($keyword) => '- ' . $keyword, $keywords));
    $avoidTitles = empty($existingTitles) ? '无' : implode("\n", array_map(fn($title) => '- ' . $title, array_slice($existingTitles, -20)));

    return <<<PROMPT
知识库名称：{$knowledgeBaseName}

下面是已经从知识库 chunk 中提炼出的关键词库。标题库只能从这些关键词派生，不要重新发散新的客户意图：
{$keywordList}

已经生成过的标题，必须避免重复：
{$avoidTitles}

请输出紧凑严格 JSON，格式如下：
{
  "titles": [
    "贴近客户真实问题的文章标题"
  ]
}

生成要求：
1. 本批次生成 {$titleCount} 条标题。
2. 每个标题必须覆盖或改写上方关键词库中的某个关键词，不要引入关键词库之外的新需求。
3. 标题要像客户真实问题、解决方案说明、购买决策或风险消除型内容资产，不要标题党。
4. 不要 question_directions、keywords、reason 等额外字段，不要 Markdown，不要解释，只返回 JSON。
PROMPT;
}

function chunk_asset_decode_json_or_null(string $raw): ?array {
    $decoded = json_decode(chunk_asset_clean_json($raw), true);
    return is_array($decoded) ? $decoded : null;
}

function chunk_asset_retry_keyword_json_prompt(string $raw, int $keywordCount): string {
    $raw = mb_substr($raw, 0, 3500, 'UTF-8');
    return <<<PROMPT
上一轮输出不是可解析 JSON。请只把下面内容改写为严格 JSON，不要补充新事实，不要解释。

必须使用这个格式，两个字段都必须是字符串数组：
{
  "question_directions": [],
  "keywords": []
}

数量要求：keywords {$keywordCount} 条；如果原文不足，请保留能从原文看出的条目，不要编造。

上一轮原文：
{$raw}
PROMPT;
}

function chunk_asset_retry_title_json_prompt(string $raw, int $titleCount): string {
    $raw = mb_substr($raw, 0, 3500, 'UTF-8');
    return <<<PROMPT
上一轮输出不是可解析 JSON。请只把下面内容改写为严格 JSON，不要补充新事实，不要解释。

必须使用这个格式，字段必须是字符串数组：
{
  "titles": []
}

数量要求：titles {$titleCount} 条；如果原文不足，请保留能从原文看出的条目，不要编造。

上一轮原文：
{$raw}
PROMPT;
}

function chunk_asset_save_libraries(PDO $db, string $baseName, array $keywords, array $titles): array {
    $keywordLibraryName = $baseName . ' - Chunk关键词库';
    $titleLibraryName = $baseName . ' - Chunk标题库';

    $db->beginTransaction();
    try {
        $keywordDescription = '根据知识库 chunk 组织关键词提问 prompt，问模型后沉淀，面向客户真实问题与 RAG 召回。';
        $titleDescription = '由知识库 chunk 证据和关键词意图生成，贴近客户会问的问题。';

        if (db_column_exists($db, 'keyword_libraries', 'description')) {
            $stmt = $db->prepare("INSERT INTO keyword_libraries (name, description, keyword_count, created_at, updated_at) VALUES (?, ?, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
            $stmt->execute([$keywordLibraryName, $keywordDescription]);
        } else {
            $stmt = $db->prepare("INSERT INTO keyword_libraries (name, keyword_count, created_at, updated_at) VALUES (?, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
            $stmt->execute([$keywordLibraryName]);
        }
        $keywordLibraryId = db_last_insert_id($db, 'keyword_libraries');

        $kwStmt = $db->prepare("
            INSERT INTO keywords (library_id, keyword, created_at)
            SELECT ?, ?, CURRENT_TIMESTAMP
            WHERE NOT EXISTS (SELECT 1 FROM keywords WHERE library_id = ? AND keyword = ?)
        ");
        $savedKeywords = 0;
        foreach ($keywords as $keyword) {
            $kwStmt->execute([$keywordLibraryId, $keyword, $keywordLibraryId, $keyword]);
            $savedKeywords += $kwStmt->rowCount() > 0 ? 1 : 0;
        }
        refresh_keyword_library_count($db, $keywordLibraryId);

        $titleColumns = ['name', 'title_count', 'generation_type', 'keyword_library_id', 'created_at', 'updated_at'];
        if (db_column_exists($db, 'title_libraries', 'description')) {
            $stmt = $db->prepare("INSERT INTO title_libraries (name, description, title_count, generation_type, keyword_library_id, created_at, updated_at) VALUES (?, ?, 0, 'ai_generated', ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
            $stmt->execute([$titleLibraryName, $titleDescription, $keywordLibraryId]);
        } else {
            $stmt = $db->prepare("INSERT INTO title_libraries (name, title_count, generation_type, keyword_library_id, created_at, updated_at) VALUES (?, 0, 'ai_generated', ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
            $stmt->execute([$titleLibraryName, $keywordLibraryId]);
        }
        $titleLibraryId = db_last_insert_id($db, 'title_libraries');

        $hasAiGenerated = db_column_exists($db, 'titles', 'is_ai_generated');
        $titleSql = $hasAiGenerated
            ? "INSERT INTO titles (library_id, title, keyword, is_ai_generated, created_at) VALUES (?, ?, ?, TRUE, CURRENT_TIMESTAMP)"
            : "INSERT INTO titles (library_id, title, keyword, created_at) VALUES (?, ?, ?, CURRENT_TIMESTAMP)";
        $titleStmt = $db->prepare($titleSql);
        $savedTitles = 0;
        foreach ($titles as $index => $title) {
            $keyword = $keywords[$index % max(1, count($keywords))] ?? '';
            $titleStmt->execute([$titleLibraryId, $title, $keyword]);
            $savedTitles++;
        }
        refresh_title_library_count($db, $titleLibraryId);

        $db->commit();
        return [
            'keyword_library_id' => $keywordLibraryId,
            'title_library_id' => $titleLibraryId,
            'saved_keywords' => $savedKeywords,
            'saved_titles' => $savedTitles,
        ];
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

$knowledgeBases = $db->query("
    SELECT kb.id, kb.name,
           COUNT(kc.id) AS chunk_count,
           COUNT(kc.id) FILTER (WHERE kc.embedding_model_id IS NOT NULL AND kc.embedding_model_id > 0) AS vectorized_count
    FROM knowledge_bases kb
    LEFT JOIN knowledge_chunks kc ON kc.knowledge_base_id = kb.id
    GROUP BY kb.id, kb.name
    ORDER BY kb.updated_at DESC NULLS LAST, kb.id DESC
")->fetchAll(PDO::FETCH_ASSOC);

$aiModels = $db->query("
    SELECT id, name, model_id
    FROM ai_models
    WHERE status = 'active'
      AND COALESCE(NULLIF(model_type, ''), 'chat') = 'chat'
    ORDER BY priority ASC NULLS LAST, id DESC
")->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        set_time_limit(240);
        if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
            throw new RuntimeException('CSRF 验证失败，请刷新后重试。');
        }

        $knowledgeBaseId = (int) ($_POST['knowledge_base_id'] ?? 0);
        $aiModelId = (int) ($_POST['ai_model_id'] ?? 0);
        $intent = trim((string) ($_POST['intent'] ?? ''));
        $keywordCount = max(10, min(80, (int) ($_POST['keyword_count'] ?? 40)));
        $titleCount = max(10, min(80, (int) ($_POST['title_count'] ?? 40)));

        $kbStmt = $db->prepare("SELECT id, name FROM knowledge_bases WHERE id = ?");
        $kbStmt->execute([$knowledgeBaseId]);
        $knowledgeBase = $kbStmt->fetch(PDO::FETCH_ASSOC);
        if (!$knowledgeBase) {
            throw new RuntimeException('请选择可用知识库。');
        }

        $modelStmt = $db->prepare("
            SELECT *
            FROM ai_models
            WHERE id = ?
              AND status = 'active'
              AND COALESCE(NULLIF(model_type, ''), 'chat') = 'chat'
        ");
        $modelStmt->execute([$aiModelId]);
        $aiModel = $modelStmt->fetch(PDO::FETCH_ASSOC);
        if (!$aiModel) {
            throw new RuntimeException('请选择可用 Chat 模型。');
        }

        $contextPack = chunk_asset_fetch_context($db, $knowledgeBaseId, $intent, CHUNK_ASSET_CONTEXT_LIMIT);
        $context = trim((string) ($contextPack['context'] ?? ''));
        if ($context === '') {
            throw new RuntimeException('这个知识库还没有可用 chunk，请先在知识库里更新切片/向量化。');
        }

        $intentInstruction = $intent !== ''
            ? "用户补充的问题方向：{$intent}\n请以这个方向为优先，但仍然必须受知识片段约束。"
            : "用户没有提供问题方向。请你先根据这些知识片段自动判断：目标客户最可能关心什么、会怎么问、购买前会比较什么、会担心什么。";

        $keywordSystemPrompt = '你是 GEO+AI 客户问题挖掘师。你只能基于给定知识片段生成客户真实会问的关键词，不得编造资料。输出必须是严格 JSON。';
        $titleSystemPrompt = '你是 GEO+AI 内容资产标题规划师。你只能基于给定关键词库生成标题，不得重新发散需求或编造资料。输出必须是严格 JSON。';
        $keywords = [];
        $titles = [];
        $questionDirections = [];
        $keywordBatchTotal = max(1, (int) ceil($keywordCount / CHUNK_ASSET_BATCH_KEYWORDS));
        $titleBatchTotal = max(1, (int) ceil($titleCount / CHUNK_ASSET_TITLE_BATCH_SIZE));
        set_time_limit(max(240, (($keywordBatchTotal + $titleBatchTotal) * (CHUNK_ASSET_AI_TIMEOUT_SECONDS + 30)) + 60));

        for ($batchIndex = 0; $batchIndex < $keywordBatchTotal; $batchIndex++) {
            $remainingKeywords = max(0, $keywordCount - count($keywords));
            if ($remainingKeywords <= 0) {
                break;
            }

            // 多请求 3 个来抵消去重损耗
            $batchKeywordCount = min(CHUNK_ASSET_BATCH_KEYWORDS + 3, $remainingKeywords + 3);
            $batchContext = chunk_asset_context_from_chunks($contextPack['chunks'] ?? [], $batchIndex);
            if ($batchContext === '') {
                throw new RuntimeException('没有可用于第 ' . ($batchIndex + 1) . ' 批生成的 chunk。');
            }

            $userPrompt = chunk_asset_keyword_batch_prompt(
                (string) $knowledgeBase['name'],
                $intentInstruction,
                $batchContext,
                $batchIndex + 1,
                $keywordBatchTotal,
                $batchKeywordCount,
                $keywords
            );

            try {
                $batchMaxTokens = 1000 + ($batchKeywordCount * 90);
                $raw = chunk_asset_call_chat_model($db, $aiModel, $keywordSystemPrompt, $userPrompt, $batchMaxTokens);
                $decoded = chunk_asset_decode_json_or_null($raw);
                if (!is_array($decoded)) {
                    $retryPrompt = chunk_asset_retry_keyword_json_prompt($raw, $batchKeywordCount);
                    $retryRaw = chunk_asset_call_chat_model($db, $aiModel, '你是 JSON 修复器。你只能输出严格 JSON，不要解释。', $retryPrompt, 1800);
                    $decoded = chunk_asset_decode_json_or_null($retryRaw);
                    if (!is_array($decoded)) {
                        throw new RuntimeException('AI 没有返回可解析 JSON：' . mb_substr($raw, 0, 500));
                    }
                }
            } catch (Throwable $batchError) {
                throw new RuntimeException('关键词第 ' . ($batchIndex + 1) . ' / ' . $keywordBatchTotal . ' 批 AI 生成失败：' . $batchError->getMessage());
            }

            $batchKeywords = chunk_asset_normalize_list($decoded['keywords'] ?? [], 'keyword', $batchKeywordCount);
            $batchDirections = chunk_asset_normalize_list($decoded['question_directions'] ?? [], 'question', 12);

            if ($batchKeywordCount > 0 && empty($batchKeywords)) {
                throw new RuntimeException('第 ' . ($batchIndex + 1) . ' 批 AI 返回的关键词为空。');
            }

            $keywords = chunk_asset_merge_unique($keywords, $batchKeywords, $keywordCount);
            $questionDirections = chunk_asset_merge_unique($questionDirections, $batchDirections, 12);
        }

        // 关键词补充批次：如果去重后数量不足，追加生成直到补齐（最多补 3 轮）
        for ($retryRound = 0; $retryRound < 3; $retryRound++) {
            $kwShort = max(0, $keywordCount - count($keywords));
            if ($kwShort <= 0) {
                break;
            }

            $retryBatch = chunk_asset_context_from_chunks($contextPack['chunks'] ?? [], $retryRound);
            if ($retryBatch === '') {
                break;
            }

            $retryPrompt = chunk_asset_keyword_batch_prompt(
                (string) $knowledgeBase['name'],
                $intentInstruction,
                $retryBatch,
                $keywordBatchTotal + $retryRound + 1,
                $keywordBatchTotal + 3,
                $kwShort + 3,
                $keywords
            );

            try {
                $retryMaxTokens = 1000 + (($kwShort + 3) * 90);
                $retryRaw = chunk_asset_call_chat_model($db, $aiModel, $keywordSystemPrompt, $retryPrompt, $retryMaxTokens);
                $retryDecoded = chunk_asset_decode_json_or_null($retryRaw);
                if (is_array($retryDecoded)) {
                    $retryKw = chunk_asset_normalize_list($retryDecoded['keywords'] ?? [], 'keyword', $kwShort + 3);
                    $keywords = chunk_asset_merge_unique($keywords, $retryKw, $keywordCount);
                }
            } catch (Throwable $retryErr) {
                // 补充失败不中断，后面统一检查
            }
        }

        $kwShort = max(0, $keywordCount - count($keywords));
        if ($kwShort > 0) {
            throw new RuntimeException('AI 分批生成完成但关键词数量不足：关键词 ' . count($keywords) . ' / ' . $keywordCount . '。请减少数量或补充问题方向后重试。');
        }

        for ($batchIndex = 0; $batchIndex < $titleBatchTotal; $batchIndex++) {
            $remainingTitles = max(0, $titleCount - count($titles));
            if ($remainingTitles <= 0) {
                break;
            }

            $batchTitleCount = min(CHUNK_ASSET_TITLE_BATCH_SIZE + 3, $remainingTitles + 3);
            $keywordSlice = array_slice($keywords, ($batchIndex * CHUNK_ASSET_TITLE_BATCH_SIZE) % max(1, count($keywords)), min(count($keywords), CHUNK_ASSET_TITLE_BATCH_SIZE + 6));
            if (empty($keywordSlice)) {
                $keywordSlice = $keywords;
            }

            $titlePrompt = chunk_asset_title_batch_prompt((string) $knowledgeBase['name'], $keywordSlice, $batchTitleCount, $titles);
            try {
                $titleMaxTokens = 1000 + ($batchTitleCount * 90);
                $raw = chunk_asset_call_chat_model($db, $aiModel, $titleSystemPrompt, $titlePrompt, $titleMaxTokens);
                $decoded = chunk_asset_decode_json_or_null($raw);
                if (!is_array($decoded)) {
                    $retryPrompt = chunk_asset_retry_title_json_prompt($raw, $batchTitleCount);
                    $retryRaw = chunk_asset_call_chat_model($db, $aiModel, '你是 JSON 修复器。你只能输出严格 JSON，不要解释。', $retryPrompt, 1800);
                    $decoded = chunk_asset_decode_json_or_null($retryRaw);
                    if (!is_array($decoded)) {
                        throw new RuntimeException('AI 没有返回可解析 JSON：' . mb_substr($raw, 0, 500));
                    }
                }
            } catch (Throwable $batchError) {
                throw new RuntimeException('标题第 ' . ($batchIndex + 1) . ' / ' . $titleBatchTotal . ' 批 AI 生成失败：' . $batchError->getMessage());
            }

            $batchTitles = chunk_asset_normalize_list($decoded['titles'] ?? [], 'title', $batchTitleCount);
            if (empty($batchTitles)) {
                throw new RuntimeException('标题第 ' . ($batchIndex + 1) . ' 批 AI 返回为空。');
            }
            $titles = chunk_asset_merge_unique($titles, $batchTitles, $titleCount);
        }

        for ($retryRound = 0; $retryRound < 3; $retryRound++) {
            $titleShort = max(0, $titleCount - count($titles));
            if ($titleShort <= 0) {
                break;
            }

            $keywordSlice = array_slice($keywords, $retryRound * CHUNK_ASSET_TITLE_BATCH_SIZE, CHUNK_ASSET_TITLE_BATCH_SIZE + 6);
            if (empty($keywordSlice)) {
                $keywordSlice = $keywords;
            }

            $retryPrompt = chunk_asset_title_batch_prompt((string) $knowledgeBase['name'], $keywordSlice, $titleShort + 3, $titles);
            try {
                $retryRaw = chunk_asset_call_chat_model($db, $aiModel, $titleSystemPrompt, $retryPrompt, 1000 + (($titleShort + 3) * 90));
                $retryDecoded = chunk_asset_decode_json_or_null($retryRaw);
                if (is_array($retryDecoded)) {
                    $retryTitles = chunk_asset_normalize_list($retryDecoded['titles'] ?? [], 'title', $titleShort + 3);
                    $titles = chunk_asset_merge_unique($titles, $retryTitles, $titleCount);
                }
            } catch (Throwable $retryErr) {
                // 补充失败不中断，后面统一检查
            }
        }

        $titleShort = max(0, $titleCount - count($titles));
        if ($titleShort > 0) {
            throw new RuntimeException('关键词库已生成，但标题数量不足：标题 ' . count($titles) . ' / ' . $titleCount . '。请减少标题数量后重试。');
        }

        $saveResult = chunk_asset_save_libraries($db, (string) $knowledgeBase['name'], $keywords, $titles);
        $generated = [
            'keywords' => $keywords,
            'titles' => $titles,
            'question_directions' => $questionDirections,
            'chunks' => $contextPack['chunks'] ?? [],
            'keyword_library_id' => $saveResult['keyword_library_id'],
            'title_library_id' => $saveResult['title_library_id'],
        ];
        $message = '已按“知识 chunk → 关键词库 → 标题库”生成素材：关键词 ' . $saveResult['saved_keywords'] . ' 条，标题 ' . $saveResult['saved_titles'] . ' 条。';
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="px-4 sm:px-0">
    <div class="mb-8 flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
        <div>
            <h1 class="text-3xl font-bold text-gray-900">从知识 Chunk 生成素材库</h1>
            <p class="mt-2 max-w-3xl text-sm leading-6 text-gray-600">
                先从已切割/向量化的知识库召回相关 chunk，组织一份高质量关键词提问 prompt，再用这份 prompt 问模型并沉淀关键词库。
            </p>
        </div>
        <a href="<?php echo htmlspecialchars(admin_url('materials.php')); ?>" class="inline-flex h-10 w-fit items-center rounded-lg border border-gray-300 bg-white px-4 text-sm font-semibold text-gray-700 hover:bg-gray-50">
            <i data-lucide="arrow-left" class="mr-2 h-4 w-4"></i>
            返回素材管理
        </a>
    </div>

    <?php if ($message): ?>
        <div class="mb-5 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-700"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="mb-5 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-700"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <section class="mb-8 overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
        <div class="border-b border-gray-100 px-6 py-5">
            <h2 class="text-xl font-semibold text-gray-900">知识 Chunk → 关键词 Prompt → 关键词库</h2>
            <p class="mt-2 text-sm leading-6 text-gray-500">这里不是让模型凭空造关键词，而是先把最相关的知识 chunk 整理成可追问的 prompt，再用 prompt 问模型，得到更贴近客户真实问题的关键词库。</p>
        </div>
        <form method="post" class="grid grid-cols-1 gap-6 p-6 lg:grid-cols-[minmax(0,1fr)_320px]" data-chunk-asset-form>
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <div class="space-y-5">
                <div>
                    <label class="mb-1 block text-sm font-semibold text-gray-700">选择知识库</label>
                    <select name="knowledge_base_id" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/20" required>
                        <option value="">请选择已切割的知识库</option>
                        <?php foreach ($knowledgeBases as $kb): ?>
                            <?php $selectedKnowledgeBaseId = (int) ($_POST['knowledge_base_id'] ?? (count($knowledgeBases) === 1 ? $knowledgeBases[0]['id'] : 0)); ?>
                            <option value="<?php echo (int) $kb['id']; ?>" <?php echo $selectedKnowledgeBaseId === (int) $kb['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($kb['name']); ?>（chunk <?php echo (int) $kb['chunk_count']; ?> / 向量 <?php echo (int) $kb['vectorized_count']; ?>）
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="mb-1 block text-sm font-semibold text-gray-700">选择 Chat 模型</label>
                    <select name="ai_model_id" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/20" required>
                        <option value="">请选择用于生成素材的模型</option>
                        <?php foreach ($aiModels as $model): ?>
                            <?php $selectedAiModelId = (int) ($_POST['ai_model_id'] ?? (count($aiModels) === 1 ? $aiModels[0]['id'] : 0)); ?>
                            <option value="<?php echo (int) $model['id']; ?>" <?php echo $selectedAiModelId === (int) $model['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($model['name'] . ' / ' . $model['model_id']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="mb-1 block text-sm font-semibold text-gray-700">问题方向补充（可选）</label>
                    <textarea name="intent" rows="4" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/20" placeholder="可以不填。留空时，系统会根据最相关 chunk 自动判断客户最可能问什么。"><?php echo htmlspecialchars((string) ($_POST['intent'] ?? '')); ?></textarea>
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-sm font-semibold text-gray-700">关键词数量</label>
                        <input name="keyword_count" type="number" min="10" max="80" value="<?php echo htmlspecialchars((string) ($_POST['keyword_count'] ?? '40')); ?>" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm">
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-semibold text-gray-700">标题数量</label>
                        <input name="title_count" type="number" min="10" max="80" value="<?php echo htmlspecialchars((string) ($_POST['title_count'] ?? '40')); ?>" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm">
                    </div>
                </div>
            </div>

            <aside class="rounded-lg border border-blue-100 bg-blue-50 p-5">
                <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-blue-600 text-white">
                    <i data-lucide="wand-sparkles" class="h-5 w-5"></i>
                </div>
                <h3 class="mt-4 text-base font-semibold text-gray-900">生成逻辑</h3>
                <p class="mt-2 text-sm leading-6 text-gray-600">系统会先召回最相关 chunk，再把证据片段组织成关键词提问 prompt；模型只回答这份 prompt，关键词补齐后再派生标题库。</p>
                <button type="submit" class="mt-5 inline-flex w-full items-center justify-center rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-700" data-chunk-asset-submit>
                    <i data-lucide="sparkles" class="mr-2 h-4 w-4"></i>
                    <span data-chunk-asset-submit-label>构建 Prompt 并沉淀关键词</span>
                </button>
                <div class="mt-4 hidden" data-chunk-asset-progress>
                    <div class="flex items-center justify-between text-xs font-semibold text-blue-700">
                        <span data-chunk-asset-progress-label>正在召回知识 chunk</span>
                        <span data-chunk-asset-progress-value>0%</span>
                    </div>
                    <div class="mt-2 h-2 overflow-hidden rounded-full bg-blue-100">
                        <div class="h-full rounded-full bg-blue-600 transition-all duration-500 ease-out" style="width: 8%;" data-chunk-asset-progress-bar></div>
                    </div>
                    <p class="mt-2 text-xs leading-5 text-blue-700">关键词阶段每批最多用 5 个 chunk 组织 prompt；标题阶段只读取已沉淀关键词。如果任一批失败，系统会保留失败信息，不会创建素材库。</p>
                </div>
            </aside>
        </form>
    </section>

    <?php if ($generated): ?>
        <?php if (!empty($generated['question_directions'])): ?>
            <section class="mb-5 rounded-lg bg-white p-5 shadow-sm ring-1 ring-gray-200">
                <h2 class="text-lg font-semibold text-gray-900">AI 从 Chunk 归纳出的客户问题方向</h2>
                <div class="mt-4 flex flex-wrap gap-2">
                    <?php foreach ($generated['question_directions'] as $question): ?>
                        <span class="rounded-full bg-indigo-50 px-3 py-1 text-xs font-semibold text-indigo-700"><?php echo htmlspecialchars($question); ?></span>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <section class="grid grid-cols-1 gap-5 lg:grid-cols-2">
            <div class="rounded-lg bg-white p-5 shadow-sm ring-1 ring-gray-200">
                <div class="flex items-center justify-between">
                    <h2 class="text-lg font-semibold text-gray-900">生成的关键词</h2>
                    <a class="text-sm font-semibold text-blue-600 hover:text-blue-700" href="<?php echo htmlspecialchars(admin_url('keyword-library-detail.php?id=' . (int) $generated['keyword_library_id'])); ?>">查看关键词库</a>
                </div>
                <div class="mt-4 flex flex-wrap gap-2">
                    <?php foreach (array_slice($generated['keywords'], 0, 40) as $kw): ?>
                        <span class="rounded-full bg-blue-50 px-3 py-1 text-xs font-semibold text-blue-700"><?php echo htmlspecialchars($kw); ?></span>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="rounded-lg bg-white p-5 shadow-sm ring-1 ring-gray-200">
                <div class="flex items-center justify-between">
                    <h2 class="text-lg font-semibold text-gray-900">生成的标题</h2>
                    <a class="text-sm font-semibold text-blue-600 hover:text-blue-700" href="<?php echo htmlspecialchars(admin_url('title-library-detail.php?id=' . (int) $generated['title_library_id'])); ?>">查看标题库</a>
                </div>
                <ul class="mt-4 space-y-2 text-sm text-gray-700">
                    <?php foreach (array_slice($generated['titles'], 0, 20) as $title): ?>
                        <li class="rounded-md bg-gray-50 px-3 py-2"><?php echo htmlspecialchars($title); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </section>
    <?php endif; ?>
</div>

<script>
document.querySelector('[data-chunk-asset-form]')?.addEventListener('submit', function () {
    const form = this;
    const button = form.querySelector('[data-chunk-asset-submit]');
    const label = form.querySelector('[data-chunk-asset-submit-label]');
    const progress = form.querySelector('[data-chunk-asset-progress]');
    const progressLabel = form.querySelector('[data-chunk-asset-progress-label]');
    const progressValue = form.querySelector('[data-chunk-asset-progress-value]');
    const progressBar = form.querySelector('[data-chunk-asset-progress-bar]');
    let percent = 8;

    if (button) {
        button.disabled = true;
        button.classList.add('cursor-wait', 'opacity-80');
    }
    if (label) {
        label.textContent = '生成中';
    }
    if (progress) {
        progress.classList.remove('hidden');
    }

    const render = () => {
        if (progressValue) progressValue.textContent = `${percent}%`;
        if (progressBar) progressBar.style.width = `${percent}%`;
        if (progressLabel) {
            progressLabel.textContent = percent >= 72
                ? '正在保存关键词库和标题库'
                : (percent >= 52 ? '正在基于关键词整理标题库' : (percent >= 30 ? '正在组织关键词提问 prompt' : '正在召回知识 chunk'));
        }
    };

    render();
    const timer = window.setInterval(function () {
        percent = Math.min(92, percent + (percent < 50 ? 11 : 5));
        render();
        if (percent >= 92) {
            window.clearInterval(timer);
        }
    }, 520);
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
