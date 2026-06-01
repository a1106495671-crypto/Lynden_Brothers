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

function chunk_asset_call_chat_model(PDO $db, array $model, string $systemPrompt, string $userPrompt): string {
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
        'max_tokens' => 5000,
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
        CURLOPT_TIMEOUT => 120,
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
    $content = (string) ($data['choices'][0]['message']['content'] ?? $data['choices'][0]['text'] ?? '');
    if ($content === '') {
        throw new RuntimeException('AI 响应里没有可用内容。');
    }

    try {
        $db->prepare("UPDATE ai_models SET used_today=COALESCE(used_today,0)+1, total_used=COALESCE(total_used,0)+1, updated_at=CURRENT_TIMESTAMP WHERE id=?")->execute([(int) $model['id']]);
    } catch (Throwable $e) {}

    return trim($content);
}

function chunk_asset_normalize_list($items, string $key, int $limit): array {
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

function chunk_asset_fetch_context(PDO $db, int $knowledgeBaseId, string $intent, int $limit = 12): array {
    $query = trim($intent);
    if ($query === '') {
        $query = '客户真实问题 购买决策 价格 对比 方案 痛点 案例 FAQ 行业信任';
    }

    $retrieved = knowledge_retrieval_fetch_context($db, $knowledgeBaseId, $query, $limit, 9000);
    if (!empty($retrieved['chunks'])) {
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

    return ['context' => trim(implode("\n\n", $parts)), 'chunks' => $chunks];
}

function chunk_asset_save_libraries(PDO $db, string $baseName, array $keywords, array $titles): array {
    $keywordLibraryName = $baseName . ' - Chunk关键词库';
    $titleLibraryName = $baseName . ' - Chunk标题库';

    $db->beginTransaction();
    try {
        $keywordDescription = '由知识库 chunk 证据和 AI prompt 生成，面向客户真实问题与 RAG 召回。';
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

        $contextPack = chunk_asset_fetch_context($db, $knowledgeBaseId, $intent, 14);
        $context = trim((string) ($contextPack['context'] ?? ''));
        if ($context === '') {
            throw new RuntimeException('这个知识库还没有可用 chunk，请先在知识库里更新切片/向量化。');
        }

        $systemPrompt = '你是 GEO+AI 内容策略师。你只能基于给定知识片段生成关键词和标题，不得编造资料。目标是把向量化知识库里的 chunk 转成最贴近客户真实提问、购买决策和 AI 可引用语境的关键词库与标题库。只输出严格 JSON。';
        $userPrompt = <<<PROMPT
知识库名称：{$knowledgeBase['name']}
客户/业务意图补充：{$intent}

下面是从已切割/向量化知识库中召回的 chunk。请把它们转化成素材库：
{$context}

请输出严格 JSON，格式如下：
{
  "keywords": [
    {"keyword": "短关键词或长尾问法", "intent": "客户为什么会搜/问它", "source": "对应知识片段编号"}
  ],
  "titles": [
    {"title": "贴近客户真实问题的文章标题", "keyword": "对应关键词", "source": "对应知识片段编号"}
  ]
}

生成要求：
1. keywords 生成 {$keywordCount} 条，优先覆盖：购买决策、痛点、对比、价格/成本、方案选型、案例、FAQ、行业信任、风险疑虑。
2. titles 生成 {$titleCount} 条，要像客户真的会问的问题或会点击的解决方案标题，不要空泛营销标题。
3. 每条都必须能追溯到 chunk，不要写知识片段里没有依据的事实。
4. 关键词要包含短词、长尾词和问句式关键词；标题要适合后续内容生产。
5. 不要 Markdown，不要解释，只返回 JSON。
PROMPT;

        $raw = chunk_asset_call_chat_model($db, $aiModel, $systemPrompt, $userPrompt);
        $decoded = json_decode(chunk_asset_clean_json($raw), true);
        if (!is_array($decoded)) {
            throw new RuntimeException('AI 没有返回可解析 JSON：' . mb_substr($raw, 0, 500));
        }

        $keywords = chunk_asset_normalize_list($decoded['keywords'] ?? [], 'keyword', $keywordCount);
        $titles = chunk_asset_normalize_list($decoded['titles'] ?? [], 'title', $titleCount);
        if (empty($keywords) || empty($titles)) {
            throw new RuntimeException('AI 返回的关键词或标题为空，请换一个模型或补充业务意图后重试。');
        }

        $saveResult = chunk_asset_save_libraries($db, (string) $knowledgeBase['name'], $keywords, $titles);
        $generated = [
            'keywords' => $keywords,
            'titles' => $titles,
            'chunks' => $contextPack['chunks'] ?? [],
            'keyword_library_id' => $saveResult['keyword_library_id'],
            'title_library_id' => $saveResult['title_library_id'],
        ];
        $message = '已基于知识 chunk 生成素材：关键词 ' . $saveResult['saved_keywords'] . ' 条，标题 ' . $saveResult['saved_titles'] . ' 条。';
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
                先从已切割/向量化的知识库召回相关 chunk，再用专门 prompt 生成贴近客户真实提问的关键词库和标题库。
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
            <h2 class="text-xl font-semibold text-gray-900">Chunk → Prompt → 关键词库 / 标题库</h2>
            <p class="mt-2 text-sm leading-6 text-gray-500">这里不是凭空生成标题，而是把知识库中最相关的 chunk 当成证据，让 AI 归纳客户会问的问题、关键词和标题。</p>
        </div>
        <form method="post" class="grid grid-cols-1 gap-6 p-6 lg:grid-cols-[minmax(0,1fr)_320px]">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <div class="space-y-5">
                <div>
                    <label class="mb-1 block text-sm font-semibold text-gray-700">选择知识库</label>
                    <select name="knowledge_base_id" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/20" required>
                        <option value="">请选择已切割的知识库</option>
                        <?php foreach ($knowledgeBases as $kb): ?>
                            <option value="<?php echo (int) $kb['id']; ?>" <?php echo (int) ($_POST['knowledge_base_id'] ?? 0) === (int) $kb['id'] ? 'selected' : ''; ?>>
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
                            <option value="<?php echo (int) $model['id']; ?>" <?php echo (int) ($_POST['ai_model_id'] ?? 0) === (int) $model['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($model['name'] . ' / ' . $model['model_id']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="mb-1 block text-sm font-semibold text-gray-700">客户想问的问题方向</label>
                    <textarea name="intent" rows="4" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/20" placeholder="例如：客户怎么选这类服务、和竞品区别、价格成本、实施风险、适合什么场景"><?php echo htmlspecialchars((string) ($_POST['intent'] ?? '')); ?></textarea>
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
                <p class="mt-2 text-sm leading-6 text-gray-600">系统会先召回最相关 chunk，再要求模型输出可追溯到 chunk 的关键词和标题，生成后直接写入关键词库与标题库。</p>
                <button type="submit" class="mt-5 inline-flex w-full items-center justify-center rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-700">
                    <i data-lucide="sparkles" class="mr-2 h-4 w-4"></i>
                    从 Chunk 生成素材
                </button>
            </aside>
        </form>
    </section>

    <?php if ($generated): ?>
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

<?php require_once __DIR__ . '/includes/footer.php'; ?>
