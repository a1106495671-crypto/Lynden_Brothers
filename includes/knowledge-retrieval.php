<?php
/**
 * 知识切块与检索
 */

if (!defined('FEISHU_TREASURE')) {
    die('Access denied');
}

require_once __DIR__ . '/embedding-service.php';

function knowledge_retrieval_vector_dimensions(): int {
    return 256;
}

function knowledge_retrieval_normalize_text(string $text): string {
    $text = str_replace(["\xEF\xBB\xBF", "\xC2\xA0", "\xE3\x80\x80"], ['', ' ', ' '], $text);
    $text = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $text);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace("/\r\n|\r/u", "\n", $text);
    $text = preg_replace("/[ \t]+\n/u", "\n", $text);
    $text = preg_replace("/\n[ \t]+/u", "\n", $text);
    $text = preg_replace("/[ \t]{2,}/u", ' ', $text);
    $text = preg_replace("/\n{3,}/u", "\n\n", $text);
    return trim((string) $text);
}

function knowledge_retrieval_extract_tokens(string $text): array {
    $normalized = knowledge_retrieval_normalize_text(mb_strtolower($text, 'UTF-8'));
    if ($normalized === '') {
        return [];
    }

    $tokens = [];

    if (preg_match_all('/[a-z0-9][a-z0-9._+#-]{1,}/u', $normalized, $latinMatches)) {
        foreach ($latinMatches[0] as $token) {
            $token = trim((string) $token);
            if ($token !== '') {
                $tokens[] = $token;
            }
        }
    }

    if (preg_match_all('/[\p{Han}]{2,32}/u', $normalized, $hanMatches)) {
        foreach ($hanMatches[0] as $sequence) {
            $sequence = trim((string) $sequence);
            if ($sequence === '') {
                continue;
            }

            $length = mb_strlen($sequence, 'UTF-8');
            if ($length <= 4) {
                $tokens[] = $sequence;
            }

            $maxGram = $length >= 3 ? 3 : 2;
            for ($gram = 2; $gram <= $maxGram; $gram++) {
                if ($length < $gram) {
                    continue;
                }
                for ($offset = 0; $offset <= $length - $gram; $offset++) {
                    $tokens[] = mb_substr($sequence, $offset, $gram, 'UTF-8');
                }
            }
        }
    }

    return array_values(array_filter($tokens, static fn ($token) => $token !== ''));
}

function knowledge_retrieval_term_frequencies(string $text): array {
    $frequencies = [];
    foreach (knowledge_retrieval_extract_tokens($text) as $token) {
        if (!isset($frequencies[$token])) {
            $frequencies[$token] = 0;
        }
        $frequencies[$token]++;
    }

    return $frequencies;
}

function knowledge_retrieval_build_vector(string $text, ?int $dimensions = null): array {
    $dimensions = $dimensions ?: knowledge_retrieval_vector_dimensions();
    $vector = array_fill(0, $dimensions, 0.0);
    $frequencies = knowledge_retrieval_term_frequencies($text);

    if (empty($frequencies)) {
        return $vector;
    }

    foreach ($frequencies as $token => $count) {
        $indexSeed = abs((int) crc32('i:' . $token));
        $signSeed = abs((int) crc32('s:' . $token));
        $index = $indexSeed % $dimensions;
        $sign = ($signSeed % 2 === 0) ? 1.0 : -1.0;
        $tokenLength = max(1, mb_strlen($token, 'UTF-8'));
        $weight = (1.0 + log(1 + $count)) * min(2.0, 0.8 + ($tokenLength / 4));
        $vector[$index] += $sign * $weight;
    }

    $norm = 0.0;
    foreach ($vector as $value) {
        $norm += $value * $value;
    }

    if ($norm <= 0.0) {
        return $vector;
    }

    $norm = sqrt($norm);
    foreach ($vector as $index => $value) {
        $vector[$index] = $value / $norm;
    }

    return $vector;
}

function knowledge_retrieval_split_long_text(string $text, int $maxChars): array {
    $text = knowledge_retrieval_normalize_text($text);
    if ($text === '') {
        return [];
    }

    $segments = preg_split('/(?<=[。！？!?；;])/u', $text, -1, PREG_SPLIT_NO_EMPTY);
    if ($segments === false || empty($segments)) {
        $segments = [$text];
    }

    $chunks = [];
    $buffer = '';

    foreach ($segments as $segment) {
        $segment = knowledge_retrieval_normalize_text($segment);
        if ($segment === '') {
            continue;
        }

        if (mb_strlen($segment, 'UTF-8') > $maxChars) {
            if ($buffer !== '') {
                $chunks[] = $buffer;
                $buffer = '';
            }

            $length = mb_strlen($segment, 'UTF-8');
            for ($offset = 0; $offset < $length; $offset += $maxChars) {
                $piece = knowledge_retrieval_normalize_text(mb_substr($segment, $offset, $maxChars, 'UTF-8'));
                if ($piece !== '') {
                    $chunks[] = $piece;
                }
            }
            continue;
        }

        $candidate = $buffer === '' ? $segment : $buffer . ' ' . $segment;
        if (mb_strlen($candidate, 'UTF-8') <= $maxChars) {
            $buffer = $candidate;
            continue;
        }

        if ($buffer !== '') {
            $chunks[] = $buffer;
        }
        $buffer = $segment;
    }

    if ($buffer !== '') {
        $chunks[] = $buffer;
    }

    return $chunks;
}

function knowledge_retrieval_chunk_text(string $content, int $maxChars = 900): array {
    $content = knowledge_retrieval_normalize_text($content);
    if ($content === '') {
        return [];
    }

    $paragraphs = preg_split("/\n{2,}/u", $content, -1, PREG_SPLIT_NO_EMPTY);
    if ($paragraphs === false || empty($paragraphs)) {
        $paragraphs = [$content];
    }

    $chunks = [];
    $buffer = '';

    foreach ($paragraphs as $paragraph) {
        $paragraph = knowledge_retrieval_normalize_text($paragraph);
        if ($paragraph === '') {
            continue;
        }

        if (mb_strlen($paragraph, 'UTF-8') > $maxChars) {
            if ($buffer !== '') {
                $chunks[] = $buffer;
                $buffer = '';
            }

            foreach (knowledge_retrieval_split_long_text($paragraph, $maxChars) as $piece) {
                $chunks[] = $piece;
            }
            continue;
        }

        $candidate = $buffer === '' ? $paragraph : $buffer . "\n\n" . $paragraph;
        if (mb_strlen($candidate, 'UTF-8') <= $maxChars) {
            $buffer = $candidate;
            continue;
        }

        if ($buffer !== '') {
            $chunks[] = $buffer;
        }
        $buffer = $paragraph;
    }

    if ($buffer !== '') {
        $chunks[] = $buffer;
    }

    return array_values(array_filter(array_map('knowledge_retrieval_normalize_text', $chunks)));
}

function knowledge_retrieval_chunk_max_chars(): int {
    $configured = (int) (function_exists('get_setting') ? get_setting('knowledge_chunk_max_chars', '900') : 900);
    return max(300, min(3000, $configured));
}

function knowledge_retrieval_chunk_strategy(): string {
    $strategy = trim((string) (function_exists('get_setting') ? get_setting('knowledge_chunk_strategy', 'rule') : 'rule'));
    return in_array($strategy, ['rule', 'semantic_llm', 'auto'], true) ? $strategy : 'rule';
}

function knowledge_retrieval_ensure_chunk_schema(PDO $db): void {
    $columns = [
        'chunk_title' => "ALTER TABLE knowledge_chunks ADD COLUMN IF NOT EXISTS chunk_title VARCHAR(255) DEFAULT ''",
        'section_path' => "ALTER TABLE knowledge_chunks ADD COLUMN IF NOT EXISTS section_path VARCHAR(500) DEFAULT ''",
        'chunk_strategy' => "ALTER TABLE knowledge_chunks ADD COLUMN IF NOT EXISTS chunk_strategy VARCHAR(50) DEFAULT 'structured_rule'",
        'metadata_json' => "ALTER TABLE knowledge_chunks ADD COLUMN IF NOT EXISTS metadata_json TEXT DEFAULT ''",
        'source_hash' => "ALTER TABLE knowledge_chunks ADD COLUMN IF NOT EXISTS source_hash VARCHAR(64) DEFAULT ''",
        'embedding_model_id' => "ALTER TABLE knowledge_chunks ADD COLUMN IF NOT EXISTS embedding_model_id INTEGER DEFAULT NULL",
        'embedding_dimensions' => "ALTER TABLE knowledge_chunks ADD COLUMN IF NOT EXISTS embedding_dimensions INTEGER DEFAULT 0",
        'embedding_provider' => "ALTER TABLE knowledge_chunks ADD COLUMN IF NOT EXISTS embedding_provider VARCHAR(255) DEFAULT ''",
    ];

    foreach ($columns as $column => $sql) {
        if (function_exists('db_column_exists') && !db_column_exists($db, 'knowledge_chunks', $column)) {
            $db->exec($sql);
        }
    }

    try {
        if (embedding_service_pgvector_available($db)) {
            if (function_exists('db_column_exists') && !db_column_exists($db, 'knowledge_chunks', 'embedding_vector')) {
                $db->exec("ALTER TABLE knowledge_chunks ADD COLUMN embedding_vector vector(3072)");
            } elseif (function_exists('db_column_exists') && db_column_exists($db, 'knowledge_chunks', 'embedding_vector')) {
                $stmt = $db->query("
                    SELECT COALESCE(format_type(a.atttypid, a.atttypmod), '') AS column_type
                    FROM pg_attribute a
                    INNER JOIN pg_class c ON c.oid = a.attrelid
                    WHERE c.relname = 'knowledge_chunks'
                      AND a.attname = 'embedding_vector'
                      AND a.attnum > 0
                    LIMIT 1
                ");
                $columnType = $stmt ? (string) $stmt->fetchColumn() : '';
                if ($columnType !== '' && $columnType !== 'vector(3072)') {
                    $db->exec("ALTER TABLE knowledge_chunks ALTER COLUMN embedding_vector TYPE vector(3072)");
                }
            }
        }
    } catch (Throwable $e) {
        error_log('知识库向量列初始化失败: ' . $e->getMessage());
    }
}

function knowledge_retrieval_detect_structured_line_type(string $line): string {
    if (preg_match('/^(\-|\*|\+|\d+\.)\s+/u', $line) === 1) {
        return 'list';
    }
    if (str_starts_with($line, '|')) {
        return 'table';
    }
    if (str_starts_with($line, '>')) {
        return 'quote';
    }

    return 'paragraph';
}

function knowledge_retrieval_split_structured_blocks(string $content): array {
    $normalized = knowledge_retrieval_normalize_text($content);
    if ($normalized === '') {
        return [];
    }

    $lines = preg_split('/\R/u', $normalized) ?: [];
    $rawBlocks = [];
    $buffer = [];
    $bufferType = 'paragraph';
    $inFence = false;
    $fenceMarker = '';

    $flushBuffer = static function () use (&$rawBlocks, &$buffer, &$bufferType): void {
        $text = trim(implode("\n", $buffer));
        if ($text !== '') {
            $rawBlocks[] = ['type' => $bufferType, 'text' => $text];
        }
        $buffer = [];
        $bufferType = 'paragraph';
    };

    foreach ($lines as $line) {
        $trimmed = trim((string) $line);

        if ($inFence) {
            $buffer[] = (string) $line;
            if ($fenceMarker !== '' && preg_match('/^' . preg_quote($fenceMarker, '/') . '/u', $trimmed) === 1) {
                $flushBuffer();
                $inFence = false;
                $fenceMarker = '';
            }
            continue;
        }

        if (preg_match('/^(```+|~~~+)/u', $trimmed, $fenceMatch) === 1) {
            $flushBuffer();
            $inFence = true;
            $fenceMarker = (string) $fenceMatch[1];
            $bufferType = 'code';
            $buffer[] = (string) $line;
            continue;
        }

        if ($trimmed === '') {
            $flushBuffer();
            continue;
        }

        if (preg_match('/^(#{1,6})\s+(.+)$/u', $trimmed, $headingMatch) === 1) {
            $flushBuffer();
            $rawBlocks[] = [
                'type' => 'heading',
                'text' => $trimmed,
                'heading_level' => strlen((string) $headingMatch[1]),
                'heading_text' => trim((string) $headingMatch[2]),
            ];
            continue;
        }

        $lineType = knowledge_retrieval_detect_structured_line_type($trimmed);
        if ($buffer !== [] && $lineType !== $bufferType) {
            $flushBuffer();
        }
        $bufferType = $lineType;
        $buffer[] = (string) $line;
    }

    $flushBuffer();

    $blocks = [];
    $sectionPath = [];
    foreach ($rawBlocks as $rawBlock) {
        if (($rawBlock['type'] ?? '') === 'heading') {
            $level = max(1, min(6, (int) ($rawBlock['heading_level'] ?? 1)));
            foreach (array_keys($sectionPath) as $existingLevel) {
                if ((int) $existingLevel >= $level) {
                    unset($sectionPath[$existingLevel]);
                }
            }
            $sectionPath[$level] = (string) ($rawBlock['heading_text'] ?? '');
            ksort($sectionPath);
        }

        $blocks[] = [
            'index' => count($blocks),
            'type' => (string) ($rawBlock['type'] ?? 'paragraph'),
            'text' => (string) ($rawBlock['text'] ?? ''),
            'section_path' => trim(implode(' > ', array_filter($sectionPath))),
            'heading_level' => isset($rawBlock['heading_level']) ? (int) $rawBlock['heading_level'] : null,
            'heading_text' => isset($rawBlock['heading_text']) ? (string) $rawBlock['heading_text'] : null,
        ];
    }

    return $blocks;
}

function knowledge_retrieval_split_text_by_characters(string $text, int $maxChars): array {
    $parts = [];
    $length = mb_strlen($text, 'UTF-8');
    for ($offset = 0; $offset < $length; $offset += $maxChars) {
        $part = trim(mb_substr($text, $offset, $maxChars, 'UTF-8'));
        if ($part !== '') {
            $parts[] = $part;
        }
    }

    return $parts;
}

function knowledge_retrieval_split_oversized_block_text(string $text, int $maxChars): array {
    $text = trim($text);
    if ($text === '') {
        return [];
    }
    if (mb_strlen($text, 'UTF-8') <= $maxChars) {
        return [$text];
    }

    $lines = preg_split('/\n/u', $text) ?: [];
    if (count($lines) <= 1) {
        return knowledge_retrieval_split_text_by_characters($text, $maxChars);
    }

    $parts = [];
    $buffer = '';
    foreach ($lines as $line) {
        $line = (string) $line;
        $candidate = $buffer === '' ? $line : $buffer . "\n" . $line;
        if (mb_strlen($candidate, 'UTF-8') <= $maxChars) {
            $buffer = $candidate;
            continue;
        }
        if (trim($buffer) !== '') {
            $parts[] = trim($buffer);
            $buffer = '';
        }
        if (mb_strlen($line, 'UTF-8') > $maxChars) {
            array_push($parts, ...knowledge_retrieval_split_text_by_characters($line, $maxChars));
        } else {
            $buffer = $line;
        }
    }
    if (trim($buffer) !== '') {
        $parts[] = trim($buffer);
    }

    return array_values(array_filter($parts, static fn (string $part): bool => $part !== ''));
}

function knowledge_retrieval_expand_oversized_blocks(array $blocks): array {
    $maxChars = knowledge_retrieval_chunk_max_chars();
    $expanded = [];

    foreach ($blocks as $block) {
        $parts = knowledge_retrieval_split_oversized_block_text((string) ($block['text'] ?? ''), $maxChars);
        foreach ($parts as $partIndex => $partText) {
            $part = $block;
            $part['index'] = count($expanded);
            $part['text'] = $partText;
            $part['source_block_index'] = (int) ($block['index'] ?? count($expanded));
            $part['source_part_index'] = $partIndex;

            if ($partIndex > 0 && ($part['type'] ?? '') === 'heading') {
                $part['type'] = 'paragraph';
                $part['heading_level'] = null;
                $part['heading_text'] = null;
            }

            $expanded[] = $part;
        }
    }

    return $expanded;
}

function knowledge_retrieval_join_block_texts(array $blocks): string {
    return trim(implode("\n\n", array_values(array_filter(
        array_map(static fn (array $block): string => trim((string) ($block['text'] ?? '')), $blocks),
        static fn (string $text): bool => $text !== ''
    ))));
}

function knowledge_retrieval_infer_chunk_title(array $blocks): string {
    foreach ($blocks as $block) {
        if (($block['type'] ?? '') === 'heading' && trim((string) ($block['heading_text'] ?? '')) !== '') {
            return trim((string) $block['heading_text']);
        }
    }

    return trim((string) ($blocks[0]['section_path'] ?? ''));
}

function knowledge_retrieval_chunk_from_blocks(array $blocks, string $strategy, string $title = ''): array {
    $content = knowledge_retrieval_join_block_texts($blocks);
    $first = $blocks[0] ?? [];
    $title = trim($title) !== '' ? trim($title) : knowledge_retrieval_infer_chunk_title($blocks);

    return [
        'content' => $content,
        'title' => $title,
        'section_path' => (string) ($first['section_path'] ?? ''),
        'strategy' => $strategy,
        'metadata' => [
            'block_indexes' => array_values(array_map(static fn (array $block): int => (int) ($block['index'] ?? 0), $blocks)),
            'source_block_indexes' => array_values(array_unique(array_map(
                static fn (array $block): int => (int) ($block['source_block_index'] ?? ($block['index'] ?? 0)),
                $blocks
            ))),
        ],
    ];
}

function knowledge_retrieval_build_structured_rule_chunks(array $blocks, string $strategy): array {
    $chunks = [];
    $buffer = [];
    $maxChars = knowledge_retrieval_chunk_max_chars();

    foreach ($blocks as $block) {
        $blockText = (string) ($block['text'] ?? '');
        if ($blockText === '') {
            continue;
        }

        if (($block['type'] ?? '') === 'heading' && $buffer !== []) {
            $chunks[] = knowledge_retrieval_chunk_from_blocks($buffer, $strategy);
            $buffer = [];
        }

        $candidate = $buffer === [] ? $blockText : knowledge_retrieval_join_block_texts([...$buffer, $block]);
        if ($buffer !== [] && mb_strlen($candidate, 'UTF-8') > $maxChars) {
            $chunks[] = knowledge_retrieval_chunk_from_blocks($buffer, $strategy);
            $buffer = [];
        }

        $buffer[] = $block;
    }

    if ($buffer !== []) {
        $chunks[] = knowledge_retrieval_chunk_from_blocks($buffer, $strategy);
    }

    return array_values(array_filter($chunks, static fn (array $chunk): bool => trim((string) ($chunk['content'] ?? '')) !== ''));
}

function knowledge_retrieval_semantic_prompt_chars(array $blocks): int {
    $total = 600;
    foreach ($blocks as $block) {
        $total += mb_strlen((string) ($block['type'] ?? ''), 'UTF-8')
            + mb_strlen((string) ($block['section_path'] ?? ''), 'UTF-8')
            + min(260, mb_strlen(knowledge_retrieval_normalize_text((string) ($block['text'] ?? '')), 'UTF-8'))
            + 80;
    }

    return $total;
}

function knowledge_retrieval_can_attempt_semantic_chunking(array $blocks): bool {
    $maxPromptChars = (int) (defined('GEOFLOW_SEMANTIC_CHUNKING_MAX_CHARS') ? GEOFLOW_SEMANTIC_CHUNKING_MAX_CHARS : 20000);
    return count($blocks) <= 120 && knowledge_retrieval_semantic_prompt_chars($blocks) <= $maxPromptChars;
}

function knowledge_retrieval_resolve_chat_base_url(string $apiUrl): string {
    $normalized = rtrim(trim($apiUrl), '/');
    if ($normalized === '') {
        return '';
    }
    if (embedding_service_is_gemini_provider($normalized)) {
        return embedding_service_resolve_base_url($normalized);
    }
    if (preg_match('#/v1/chat/completions$#', $normalized) === 1) {
        return substr($normalized, 0, -strlen('/chat/completions'));
    }
    if (preg_match('#/chat/completions$#', $normalized) === 1) {
        return substr($normalized, 0, -strlen('/chat/completions'));
    }
    $path = (string) (parse_url($normalized, PHP_URL_PATH) ?: '');
    if ($path === '' || $path === '/') {
        return $normalized . '/v1';
    }
    return $normalized;
}

function knowledge_retrieval_semantic_models(PDO $db): array {
    $modelId = (int) (function_exists('get_setting') ? get_setting('knowledge_chunking_model_id', '0') : 0);
    if ($modelId <= 0) {
        return [];
    }

    $orderColumn = function_exists('db_column_exists') && db_column_exists($db, 'ai_models', 'failover_priority')
        ? 'failover_priority'
        : 'priority';
    $models = [];

    $baseSql = "
        SELECT *
        FROM ai_models
        WHERE status = 'active'
          AND (model_type IS NULL OR model_type = '' OR model_type = 'chat')
          AND COALESCE(api_key, '') <> ''
          AND COALESCE(model_id, '') <> ''
          AND (daily_limit IS NULL OR daily_limit <= 0 OR COALESCE(used_today, 0) < daily_limit)
    ";

    $stmt = $db->prepare($baseSql . " AND id = ? LIMIT 1");
    $stmt->execute([$modelId]);
    $primary = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($primary) {
        $models[(int) $primary['id']] = $primary;
    }

    $fallbackSql = $baseSql;
    if (!empty($models)) {
        $fallbackSql .= " AND id <> " . (int) array_key_first($models);
    }
    $fallbackSql .= " ORDER BY {$orderColumn} ASC NULLS LAST, id ASC";
    $fallbackStmt = $db->query($fallbackSql);
    foreach ($fallbackStmt ? $fallbackStmt->fetchAll(PDO::FETCH_ASSOC) : [] as $model) {
        $models[(int) $model['id']] = $model;
    }

    return array_values($models);
}

function knowledge_retrieval_call_semantic_model(PDO $db, array $model, string $prompt): string {
    $apiKey = trim(function_exists('decrypt_ai_api_key') ? decrypt_ai_api_key((string) ($model['api_key'] ?? '')) : (string) ($model['api_key'] ?? ''));
    $modelId = trim((string) ($model['model_id'] ?? ''));
    $baseUrl = knowledge_retrieval_resolve_chat_base_url((string) ($model['api_url'] ?? ''));
    if ($apiKey === '' || $modelId === '' || $baseUrl === '') {
        return '';
    }

    if (embedding_service_is_gemini_provider($baseUrl)) {
        $url = $baseUrl . '/models/' . rawurlencode(preg_replace('#^models/#', '', $modelId)) . ':generateContent?key=' . rawurlencode($apiKey);
        $payload = [
            'contents' => [[
                'role' => 'user',
                'parts' => [['text' => "You are GEOFlow's knowledge-base semantic chunk planner. Output strict JSON only.\n\n" . $prompt]],
            ]],
            'generationConfig' => [
                'temperature' => 0.1,
                'maxOutputTokens' => 1600,
            ],
        ];
        $headers = ['Content-Type: application/json'];
    } else {
        $url = $baseUrl . '/chat/completions';
        $payload = [
            'model' => $modelId,
            'messages' => [
                ['role' => 'system', 'content' => "You are GEOFlow's knowledge-base semantic chunk planner. You only group original block indexes into chunks. Do not rewrite, summarize, translate, add facts, or return source text. Output strict JSON only."],
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature' => 0.1,
            'max_tokens' => 1600,
        ];
        $headers = ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey];
    }

    $ch = curl_init($url);
    apply_curl_network_defaults($ch);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 90,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError !== '' || $code < 200 || $code >= 300) {
        error_log('知识库语义切片模型调用失败: ' . ($curlError !== '' ? $curlError : ('HTTP ' . $code . ' ' . mb_substr((string) $raw, 0, 300))));
        return '';
    }

    $data = json_decode((string) $raw, true);
    if (!is_array($data)) {
        return '';
    }

    if (embedding_service_is_gemini_provider($baseUrl)) {
        $parts = $data['candidates'][0]['content']['parts'] ?? [];
        $text = '';
        foreach (is_array($parts) ? $parts : [] as $part) {
            $text .= (string) ($part['text'] ?? '');
        }
    } else {
        $text = (string) ($data['choices'][0]['message']['content'] ?? $data['choices'][0]['text'] ?? '');
    }

    if ($text !== '') {
        try {
            $db->prepare("UPDATE ai_models SET used_today=COALESCE(used_today,0)+1, total_used=COALESCE(total_used,0)+1, updated_at=CURRENT_TIMESTAMP WHERE id=?")->execute([(int) $model['id']]);
        } catch (Throwable $e) {}
    }

    return trim($text);
}

function knowledge_retrieval_semantic_user_prompt(int $knowledgeBaseId, array $blocks): string {
    $blockPayload = array_map(static function (array $block): array {
        return [
            'index' => (int) ($block['index'] ?? 0),
            'type' => (string) ($block['type'] ?? 'paragraph'),
            'section_path' => (string) ($block['section_path'] ?? ''),
            'text' => mb_substr(knowledge_retrieval_normalize_text((string) ($block['text'] ?? '')), 0, 260, 'UTF-8'),
        ];
    }, $blocks);

    return "Plan semantic chunks for knowledge base {$knowledgeBaseId}.\n"
        . "Requirements:\n"
        . "1. Every block index must appear exactly once.\n"
        . "2. Keep block indexes in original ascending order; never reorder, skip, or duplicate blocks.\n"
        . "3. Merge adjacent blocks when they are semantically continuous; split at heading, topic, list, or table boundaries when useful.\n"
        . "4. Return only a concise chunk title and block_indexes. Do not include source text, summaries, explanations, Markdown fences, or comments.\n"
        . "5. Output strict JSON only with this schema: {\"chunks\":[{\"title\":\"...\",\"block_indexes\":[0,1]}]}.\n\n"
        . "blocks:\n" . json_encode($blockPayload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}

function knowledge_retrieval_decode_semantic_chunk_plan(string $content): array {
    $content = trim($content);
    if ($content === '') {
        return [];
    }
    if (preg_match('/```(?:json)?\s*(.*?)```/su', $content, $matches) === 1) {
        $content = trim((string) $matches[1]);
    } else {
        $start = strpos($content, '{');
        $end = strrpos($content, '}');
        if ($start !== false && $end !== false && $end >= $start) {
            $content = substr($content, $start, $end - $start + 1);
        }
    }

    $decoded = json_decode($content, true);
    if (!is_array($decoded) || !isset($decoded['chunks']) || !is_array($decoded['chunks'])) {
        return [];
    }

    $plan = [];
    foreach ($decoded['chunks'] as $item) {
        if (!is_array($item) || !isset($item['block_indexes']) || !is_array($item['block_indexes'])) {
            return [];
        }
        $indexes = [];
        foreach ($item['block_indexes'] as $index) {
            if (is_int($index)) {
                $normalizedIndex = $index >= 0 ? $index : null;
            } elseif (is_string($index) && preg_match('/^\d+$/u', $index) === 1) {
                $normalizedIndex = (int) $index;
            } else {
                $normalizedIndex = null;
            }
            if ($normalizedIndex === null) {
                return [];
            }
            $indexes[] = $normalizedIndex;
        }
        if ($indexes === []) {
            return [];
        }
        $plan[] = [
            'title' => trim((string) ($item['title'] ?? '')),
            'block_indexes' => $indexes,
        ];
    }

    return $plan;
}

function knowledge_retrieval_chunks_from_semantic_plan(array $blocks, array $plan): array {
    if ($plan === []) {
        return [];
    }

    $blocksByIndex = [];
    foreach ($blocks as $block) {
        $blocksByIndex[(int) ($block['index'] ?? 0)] = $block;
    }

    $seen = [];
    $chunks = [];
    $lastIndex = -1;
    foreach ($plan as $plannedChunk) {
        $chunkBlocks = [];
        foreach ($plannedChunk['block_indexes'] as $index) {
            if ($index <= $lastIndex || !isset($blocksByIndex[$index]) || isset($seen[$index])) {
                return [];
            }
            $seen[$index] = true;
            $lastIndex = $index;
            $chunkBlocks[] = $blocksByIndex[$index];
        }
        $chunks[] = knowledge_retrieval_chunk_from_blocks($chunkBlocks, 'semantic_llm', (string) ($plannedChunk['title'] ?? ''));
    }

    return count($seen) === count($blocks) ? $chunks : [];
}

function knowledge_retrieval_build_semantic_chunks(PDO $db, int $knowledgeBaseId, array $blocks): array {
    $models = knowledge_retrieval_semantic_models($db);
    if ($models === []) {
        return [];
    }

    $prompt = knowledge_retrieval_semantic_user_prompt($knowledgeBaseId, $blocks);
    foreach ($models as $model) {
        $content = knowledge_retrieval_call_semantic_model($db, $model, $prompt);
        $plan = knowledge_retrieval_decode_semantic_chunk_plan($content);
        $chunks = knowledge_retrieval_chunks_from_semantic_plan($blocks, $plan);
        if ($chunks !== []) {
            return $chunks;
        }
    }

    return [];
}

function knowledge_retrieval_plan_chunks(PDO $db, int $knowledgeBaseId, string $content): array {
    $blocks = knowledge_retrieval_expand_oversized_blocks(knowledge_retrieval_split_structured_blocks($content));
    if ($blocks === []) {
        return [];
    }

    $ruleChunks = knowledge_retrieval_build_structured_rule_chunks($blocks, 'structured_rule');
    $strategy = knowledge_retrieval_chunk_strategy();
    if ($strategy === 'rule') {
        return $ruleChunks;
    }

    if (!knowledge_retrieval_can_attempt_semantic_chunking($blocks)) {
        return $strategy === 'auto'
            ? $ruleChunks
            : knowledge_retrieval_build_structured_rule_chunks($blocks, 'semantic_fallback');
    }

    $semanticChunks = knowledge_retrieval_build_semantic_chunks($db, $knowledgeBaseId, $blocks);
    if ($semanticChunks !== []) {
        return $semanticChunks;
    }

    return $strategy === 'auto'
        ? $ruleChunks
        : knowledge_retrieval_build_structured_rule_chunks($blocks, 'semantic_fallback');
}

function knowledge_retrieval_extract_fallback_tokens(string $text): array {
    $normalized = mb_strtolower(knowledge_retrieval_normalize_text($text), 'UTF-8');
    if ($normalized === '') {
        return [];
    }

    $tokens = [];
    if (preg_match_all('/[a-z0-9][a-z0-9._+#-]{1,}/u', $normalized, $latinMatches)) {
        foreach ($latinMatches[0] as $token) {
            $token = trim((string) $token);
            if ($token !== '') {
                $tokens[] = $token;
            }
        }
    }
    if (preg_match_all('/[\p{Han}]{2,32}/u', $normalized, $hanMatches)) {
        foreach ($hanMatches[0] as $sequence) {
            $sequence = trim((string) $sequence);
            if ($sequence !== '') {
                $tokens[] = $sequence;
            }
        }
    }

    return $tokens;
}

function knowledge_retrieval_build_fallback_vector(string $text, int $dimensions = 256): array {
    $vector = array_fill(0, $dimensions, 0.0);
    $tokens = knowledge_retrieval_extract_fallback_tokens($text);
    if (empty($tokens)) {
        return $vector;
    }

    foreach ($tokens as $token) {
        $indexSeed = abs((int) crc32('i:' . $token));
        $signSeed = abs((int) crc32('s:' . $token));
        $index = $indexSeed % $dimensions;
        $sign = ($signSeed % 2 === 0) ? 1.0 : -1.0;
        $weight = 1.0 + log(1 + mb_strlen($token, 'UTF-8'));
        $vector[$index] += $sign * $weight;
    }

    $norm = 0.0;
    foreach ($vector as $value) {
        $norm += $value * $value;
    }
    if ($norm <= 0.0) {
        return $vector;
    }
    $norm = sqrt($norm);
    foreach ($vector as $index => $value) {
        $vector[$index] = $value / $norm;
    }

    return $vector;
}

function knowledge_retrieval_pgvector_storage_available(PDO $db): bool {
    static $cache = [];

    $cacheKey = spl_object_id($db);
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    $available = embedding_service_pgvector_available($db)
        && db_column_exists($db, 'knowledge_chunks', 'embedding_vector');

    if ($available) {
        try {
            $stmt = $db->query("
                SELECT COALESCE(format_type(a.atttypid, a.atttypmod), '') AS column_type
                FROM pg_attribute a
                INNER JOIN pg_class c ON c.oid = a.attrelid
                WHERE c.relname = 'knowledge_chunks'
                  AND a.attname = 'embedding_vector'
                  AND a.attnum > 0
                LIMIT 1
            ");
            $available = (string) ($stmt ? $stmt->fetchColumn() : '') === 'vector(3072)';
        } catch (Throwable) {
            $available = false;
        }
    }

    $cache[$cacheKey] = $available;

    return $cache[$cacheKey];
}

function knowledge_retrieval_generate_chunk_embeddings(PDO $db, array $chunks, ?string $documentTitle = null, bool $requireRealEmbedding = false): array {
    if (empty($chunks)) {
        return [];
    }

    try {
        $model = embedding_service_get_default_model($db);
        if (!$model) {
            if ($requireRealEmbedding) {
                throw new RuntimeException('未配置可用的 embedding 模型');
            }
            return [];
        }

        $results = [];
        $batchSize = 12;
        for ($offset = 0; $offset < count($chunks); $offset += $batchSize) {
            $batch = array_slice($chunks, $offset, $batchSize);
            foreach (embedding_service_generate_embeddings($db, $batch, $model, 'document', $documentTitle) as $item) {
                $results[] = $item;
            }
        }

        return count($results) === count($chunks) ? $results : [];
    } catch (Throwable $e) {
        if ($requireRealEmbedding) {
            throw $e;
        }
        error_log('知识库 embedding 生成失败，将回退到轻量检索: ' . $e->getMessage());
        return [];
    }
}

function knowledge_retrieval_sync_chunks(PDO $db, int $knowledgeBaseId, string $content, bool $requireRealEmbedding = false): int {
    $knowledgeBaseId = (int) $knowledgeBaseId;
    if ($knowledgeBaseId <= 0) {
        return 0;
    }

    knowledge_retrieval_ensure_chunk_schema($db);

    $plannedChunks = knowledge_retrieval_plan_chunks($db, $knowledgeBaseId, $content);
    $chunks = [];
    foreach ($plannedChunks as $index => $plannedChunk) {
        $chunkText = (string) ($plannedChunk['content'] ?? '');
        if ($chunkText === '') {
            continue;
        }

        $chunks[] = [
            'chunk_index' => $index,
            'content' => $chunkText,
            'title' => (string) ($plannedChunk['title'] ?? ''),
            'section_path' => (string) ($plannedChunk['section_path'] ?? ''),
            'strategy' => (string) ($plannedChunk['strategy'] ?? 'structured_rule'),
            'metadata' => $plannedChunk['metadata'] ?? [],
        ];
    }

    $titleStmt = $db->prepare("SELECT name FROM knowledge_bases WHERE id = ?");
    $titleStmt->execute([$knowledgeBaseId]);
    $documentTitle = trim((string) ($titleStmt->fetchColumn() ?: ''));

    $realEmbeddings = knowledge_retrieval_generate_chunk_embeddings(
        $db,
        array_map(static fn (array $chunk): string => $chunk['content'], $chunks),
        $documentTitle !== '' ? $documentTitle : null,
        $requireRealEmbedding
    );
    $useRealEmbeddings = count($realEmbeddings) === count($chunks);
    $usePgvector = knowledge_retrieval_pgvector_storage_available($db) && $useRealEmbeddings;

    if ($requireRealEmbedding && !$useRealEmbeddings && count($chunks) > 0) {
        throw new RuntimeException('真实向量生成不完整，已保留原切片');
    }

    $deleteStmt = $db->prepare("DELETE FROM knowledge_chunks WHERE knowledge_base_id = ?");
    if ($usePgvector) {
        $insertStmt = $db->prepare("
            INSERT INTO knowledge_chunks (
                knowledge_base_id, chunk_index, content, content_hash, chunk_title, section_path,
                chunk_strategy, metadata_json, source_hash, token_count, embedding_json,
                embedding_model_id, embedding_dimensions, embedding_provider, embedding_vector,
                created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CAST(? AS vector), CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ");
    } else {
        $insertStmt = $db->prepare("
            INSERT INTO knowledge_chunks (
                knowledge_base_id, chunk_index, content, content_hash, chunk_title, section_path,
                chunk_strategy, metadata_json, source_hash, token_count, embedding_json,
                embedding_model_id, embedding_dimensions, embedding_provider, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ");
    }

    $startedTransaction = !$db->inTransaction();
    if ($startedTransaction) {
        $db->beginTransaction();
    }

    try {
        $deleteStmt->execute([$knowledgeBaseId]);

        $inserted = 0;
        foreach ($chunks as $position => $chunk) {
            $chunkContent = (string) $chunk['content'];
            $fallbackVector = knowledge_retrieval_build_fallback_vector($chunkContent, 256);

            $params = [
                $knowledgeBaseId,
                (int) $chunk['chunk_index'],
                $chunkContent,
                hash('sha256', $chunkContent),
                mb_substr((string) ($chunk['title'] ?? ''), 0, 255, 'UTF-8'),
                mb_substr((string) ($chunk['section_path'] ?? ''), 0, 500, 'UTF-8'),
                mb_substr((string) ($chunk['strategy'] ?? 'structured_rule'), 0, 50, 'UTF-8'),
                json_encode($chunk['metadata'] ?? [], JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION),
                hash('sha256', (string) ($chunk['section_path'] ?? '') . '|' . $chunkContent),
                count(knowledge_retrieval_extract_fallback_tokens($chunkContent)),
                json_encode($useRealEmbeddings ? (($realEmbeddings[$position]['raw_vector'] ?? $realEmbeddings[$position]['vector'] ?? []) ?: $fallbackVector) : $fallbackVector, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION),
            ];

            if ($useRealEmbeddings) {
                $embedding = $realEmbeddings[$position];
                $params[] = (int) ($embedding['model_id'] ?? 0);
                $params[] = (int) ($embedding['dimensions'] ?? 0);
                $params[] = (string) ($embedding['provider'] ?? '');
            } else {
                $params[] = null;
                $params[] = 0;
                $params[] = '';
            }

            if ($usePgvector) {
                $embedding = $realEmbeddings[$position];
                $params[] = (string) ($embedding['vector_literal'] ?? '[]');
            }

            $insertStmt->execute($params);
            $inserted++;
        }

        if ($startedTransaction) {
            $db->commit();
        }

        return $inserted;
    } catch (Throwable $e) {
        if ($startedTransaction && $db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

function knowledge_retrieval_ensure_chunks(PDO $db, int $knowledgeBaseId, string $content): int {
    $stmt = $db->prepare("SELECT COUNT(*) FROM knowledge_chunks WHERE knowledge_base_id = ?");
    $stmt->execute([$knowledgeBaseId]);
    $count = (int) $stmt->fetchColumn();

    if ($count > 0 || trim($content) === '') {
        return $count;
    }

    return knowledge_retrieval_sync_chunks($db, $knowledgeBaseId, $content);
}

function knowledge_retrieval_dot_product(array $left, array $right): float {
    $sum = 0.0;
    $limit = min(count($left), count($right));
    for ($index = 0; $index < $limit; $index++) {
        $sum += (float) $left[$index] * (float) $right[$index];
    }
    return $sum;
}

function knowledge_retrieval_lexical_score(array $queryTerms, array $chunkTerms): float {
    if (empty($queryTerms) || empty($chunkTerms)) {
        return 0.0;
    }

    $matched = 0;
    $total = 0;
    foreach ($queryTerms as $token => $count) {
        $total += $count;
        if (isset($chunkTerms[$token])) {
            $matched += min($count, $chunkTerms[$token]);
        }
    }

    if ($total <= 0) {
        return 0.0;
    }

    return $matched / $total;
}

function knowledge_retrieval_fetch_context(PDO $db, int $knowledgeBaseId, string $query, int $limit = 4, int $maxChars = 2400): array {
    $query = knowledge_retrieval_normalize_text($query);
    $chunks = [];

    if ($query !== '' && knowledge_retrieval_pgvector_storage_available($db)) {
        try {
            $queryEmbedding = embedding_service_generate_embeddings($db, [$query], null, 'query');
            if (!empty($queryEmbedding[0]['vector_literal'])) {
                $candidateLimit = max($limit * 3, 8);
                $stmt = $db->prepare("
                    SELECT id, chunk_index, content, embedding_json, token_count,
                           (embedding_vector <=> CAST(? AS vector)) AS vector_distance
                    FROM knowledge_chunks
                    WHERE knowledge_base_id = ?
                      AND embedding_vector IS NOT NULL
                    ORDER BY embedding_vector <=> CAST(? AS vector), chunk_index ASC
                    LIMIT CAST(? AS INTEGER)
                ");
                $stmt->execute([
                    (string) $queryEmbedding[0]['vector_literal'],
                    $knowledgeBaseId,
                    (string) $queryEmbedding[0]['vector_literal'],
                    $candidateLimit,
                ]);
                $chunks = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            }
        } catch (Throwable $e) {
            error_log('知识库 pgvector 检索失败，将回退到轻量检索: ' . $e->getMessage());
        }
    }

    if (empty($chunks)) {
        $stmt = $db->prepare("
            SELECT id, chunk_index, content, embedding_json, token_count
            FROM knowledge_chunks
            WHERE knowledge_base_id = ?
            ORDER BY chunk_index ASC
        ");
        $stmt->execute([$knowledgeBaseId]);
        $chunks = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    if (empty($chunks)) {
        return [
            'context' => '',
            'chunks' => [],
        ];
    }

    $queryVector = knowledge_retrieval_build_vector($query);
    $queryTerms = knowledge_retrieval_term_frequencies($query);
    $scored = [];

    foreach ($chunks as $chunk) {
        $chunkVector = json_decode((string) ($chunk['embedding_json'] ?? '[]'), true);
        if (!is_array($chunkVector) || empty($chunkVector)) {
            $chunkVector = knowledge_retrieval_build_vector((string) ($chunk['content'] ?? ''));
        }

        $chunkTerms = knowledge_retrieval_term_frequencies((string) ($chunk['content'] ?? ''));
        $vectorScore = $query === '' ? 0.0 : knowledge_retrieval_dot_product($queryVector, $chunkVector);
        if (isset($chunk['vector_distance']) && $chunk['vector_distance'] !== null) {
            $vectorScore = max($vectorScore, 1.0 - (float) $chunk['vector_distance']);
        }
        $lexicalScore = $query === '' ? 0.0 : knowledge_retrieval_lexical_score($queryTerms, $chunkTerms);
        $positionBonus = max(0.0, 0.05 - ((int) ($chunk['chunk_index'] ?? 0) * 0.004));
        $score = ($vectorScore * 0.75) + ($lexicalScore * 0.25) + $positionBonus;

        $chunk['score'] = $score;
        $scored[] = $chunk;
    }

    usort($scored, static function (array $left, array $right): int {
        $scoreDiff = ($right['score'] <=> $left['score']);
        if ($scoreDiff !== 0) {
            return $scoreDiff;
        }

        return ((int) $left['chunk_index']) <=> ((int) $right['chunk_index']);
    });

    $selected = [];
    $charCount = 0;
    $fallbackMode = $query === '' || (($scored[0]['score'] ?? 0.0) <= 0.02);
    $source = $fallbackMode ? $chunks : $scored;

    foreach ($source as $chunk) {
        if (count($selected) >= $limit) {
            break;
        }

        $content = knowledge_retrieval_normalize_text((string) ($chunk['content'] ?? ''));
        if ($content === '') {
            continue;
        }

        $nextLength = $charCount + mb_strlen($content, 'UTF-8');
        if (!empty($selected) && $nextLength > $maxChars) {
            continue;
        }

        $selected[] = $chunk;
        $charCount = $nextLength;
    }

    usort($selected, static function (array $left, array $right): int {
        return ((int) $left['chunk_index']) <=> ((int) $right['chunk_index']);
    });

    $parts = [];
    foreach (array_values($selected) as $index => $chunk) {
        $parts[] = "【知识片段" . ($index + 1) . "】\n" . knowledge_retrieval_normalize_text((string) ($chunk['content'] ?? ''));
    }

    return [
        'context' => trim(implode("\n\n", $parts)),
        'chunks' => $selected,
    ];
}
