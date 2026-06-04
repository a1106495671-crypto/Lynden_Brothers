<?php
/**
 * GEO 问答基准线服务
 *
 * 记录首次人工询问大模型得到的标准问题与答案，供后续监测复测对比。
 */

if (!defined('FEISHU_TREASURE')) {
    die('Access denied');
}

function geo_baseline_qa_ensure_schema(PDO $db): void {
    $db->exec("
        CREATE TABLE IF NOT EXISTS geo_baseline_qa (
            id BIGSERIAL PRIMARY KEY,
            diagnosis_id UUID DEFAULT NULL,
            customer_id VARCHAR(80) NOT NULL DEFAULT '',
            brand_name VARCHAR(200) NOT NULL DEFAULT '',
            question TEXT NOT NULL DEFAULT '',
            platform VARCHAR(30) NOT NULL DEFAULT 'deepseek',
            baseline_answer TEXT NOT NULL DEFAULT '',
            mention_brand BOOLEAN NOT NULL DEFAULT FALSE,
            sentiment VARCHAR(20) NOT NULL DEFAULT 'neutral',
            keywords TEXT NOT NULL DEFAULT '',
            sort_order SMALLINT NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_geo_baseline_qa_diagnosis ON geo_baseline_qa(diagnosis_id, sort_order)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_geo_baseline_qa_customer ON geo_baseline_qa(customer_id, status)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_geo_baseline_qa_lookup ON geo_baseline_qa(customer_id, platform, status)");
}

function geo_baseline_qa_platforms(): array {
    return [
        'deepseek' => 'DeepSeek',
        'kimi' => 'Kimi',
        'doubao' => '豆包',
        'tongyi' => '通义',
        'wenxin' => '文心',
        'yuanbao' => '元宝',
        'chatgpt' => 'ChatGPT',
    ];
}

function geo_baseline_qa_default_questions(string $brandName, string $industry): array {
    $category = geo_baseline_qa_infer_category_label($brandName, $industry);
    return geo_baseline_qa_radar_question_blueprint($category, []);
}

function geo_baseline_qa_is_tea_context(string $text): bool {
    return preg_match('/茶|普洱|白茶|绿茶|红茶|乌龙|岩茶|龙井|铁观音|茶叶|茶饼|茶汤|冲泡|投茶|醒茶|茶香|回甘/u', $text) === 1;
}

function geo_baseline_qa_infer_category_label(string $brandName, string $industry, string $contextText = ''): string {
    $industry = trim($industry);
    $source = trim($industry . ' ' . $brandName . ' ' . $contextText);
    if ($industry !== '' && mb_stripos($brandName, $industry) === false) {
        return $industry;
    }

    if (preg_match('/来凤[^，。；、\\s]{0,8}藤茶/u', $source)) {
        return '来凤藤茶';
    }
    if (preg_match('/藤茶/u', $source)) {
        return '藤茶';
    }

    $teaTypes = ['普洱茶', '白茶', '绿茶', '红茶', '乌龙茶', '岩茶', '龙井茶', '铁观音', '茶叶'];
    foreach ($teaTypes as $teaType) {
        if (mb_stripos($source, $teaType) !== false) {
            return $teaType;
        }
    }

    return $industry !== '' ? $industry : '这个品类';
}

function geo_baseline_qa_generate_from_chunks(PDO $db, string $customerId, string $brandName, string $industry, int $limit = 10): array {
    geo_baseline_qa_ensure_schema($db);

    $limit = max(5, min(20, $limit));
    $chunks = geo_baseline_qa_load_chunk_context($db, $customerId, $brandName, 18);
    $competitors = geo_baseline_qa_load_competitors($db, $customerId);
    if (empty($chunks)) {
        return [
            'rows' => geo_baseline_qa_fallback_rows($brandName, $industry, [], $limit, $competitors),
            'source' => 'fallback',
            'message' => '未找到知识切片，已用品牌默认问题生成占位。',
        ];
    }

    $contextLines = [];
    foreach ($chunks as $idx => $chunk) {
        $title = trim((string) ($chunk['chunk_title'] ?: $chunk['section_path'] ?: $chunk['kb_name'] ?? ''));
        $content = trim(preg_replace('/\s+/u', ' ', (string) ($chunk['content'] ?? '')));
        $contextLines[] = '【片段' . ($idx + 1) . ($title !== '' ? '：' . $title : '') . '】' . mb_substr($content, 0, 900);
    }

    $contextText = implode("\n\n", $contextLines);
    $isTeaContext = geo_baseline_qa_is_tea_context($brandName . ' ' . $industry . ' ' . $contextText);
    $categoryLabel = geo_baseline_qa_infer_category_label($brandName, $industry, $contextText);
    $competitorText = empty($competitors) ? '暂无已录入竞品' : implode('、', $competitors);
    $domainGuide = $isTeaContext
        ? "当前知识更像茶/茶叶/茶品内容。问题必须围绕品类和消费决策：是什么茶、口感香气、产区产地、品牌推荐、品牌排名、适合人群、冲泡方法、饮用场景、送礼/自饮、品质判断、性价比、注意事项。不要写成服务商、项目交付、GEO、营销或企业采购问题。\n"
        : "请先从知识片段识别核心品类/对象。问题要围绕真实用户做品类发现、推荐、比较、排名和购买判断，不要默认写成服务商招商、项目交付或企业咨询问题。\n";

    $prompt = "你是GEO雷达诊断的首问样本设计师。请基于下面已经切片的知识库内容，生成{$limit}个真实用户会拿去问AI的监测问题。\n\n"
        . "被诊断品牌：{$brandName}\n"
        . "优先使用的品类/对象词：{$categoryLabel}\n"
        . "行业/场景：{$industry}\n\n"
        . "已录入竞品：{$competitorText}\n\n"
        . "雷达诊断目的：\n"
        . "- 用这些问题去真实AI平台提问，记录AI是否自然提到被诊断品牌、提到几次、推荐深度、是否和竞品一起出现。\n"
        . "- 后续监测会按这些问题统计品牌提及率、平台覆盖率、竞品超越告警和答案变化。\n"
        . "- 所以问题必须能诱发AI给出推荐名单、排名、比较、选择标准或购买判断，而不是只问百科知识。\n\n"
        . "要求：\n"
        . "1. " . $domainGuide
        . "2. 这些问题用于GEO雷达诊断：观察AI在无品牌或弱品牌搜索里会不会提到被诊断品牌、排第几、和哪些竞品一起出现。因此问题里不要主动写入被诊断品牌“{$brandName}”，除非知识片段证明它本身就是不可拆分的品类名。\n"
        . "3. 每一条问题都要当作用户第一次、单独发给AI的问题来写，必须自带完整品类/对象词（例如“{$categoryLabel}”），不能依赖上一条上下文。\n"
        . "4. 禁止用“它”“这款茶”“这款产品”“该茶”“产自哪里”这类需要上下文才能理解的问法；如果要问口感，就写成“{$categoryLabel}喝起来是什么味道？”这种完整问法。\n"
        . "5. 问题要像普通客户自然会问AI的话，少用内部术语，不要写“请基于知识库/片段/品牌资料”。\n"
        . "6. 每个问题只问一件具体事情，覆盖认知、推荐、排名、对比、适合谁、怎么选、怎么用、价格/性价比、注意事项等不同角度。\n"
        . "7. 请按下面问题篮子生成，尽量各1条：品类认知、品牌推荐、品牌/产区排名、购买决策、品质/性价比、场景适配、竞品对比、避坑风险、使用/冲泡方法、趋势/口碑。\n"
        . "8. 如有已录入竞品，最多生成2条直接点名竞品的问题，用来检测AI如何比较竞品；其余问题不要点名任何品牌。\n"
        . "9. 不要生成答案。返回严格JSON数组，不要Markdown，不要解释。每项字段只保留：question。\n\n"
        . "知识片段：\n" . $contextText;

    $aiRows = [];
    $aiError = '';
    if (function_exists('geo_call_ai')) {
        $result = geo_call_ai($prompt, 3500, 0.35);
        $content = trim((string) ($result['content'] ?? ''));
        if ($content !== '') {
            $aiRows = geo_baseline_qa_parse_generated_rows($content, $limit, $brandName, $categoryLabel);
        } else {
            $aiError = (string) ($result['error'] ?? 'AI未返回内容');
        }
    } else {
        $aiError = '未找到统一AI调用函数';
    }

    if (empty($aiRows)) {
        return [
            'rows' => geo_baseline_qa_fallback_rows($brandName, $industry, $chunks, $limit, $competitors),
            'source' => 'fallback',
            'message' => 'AI生成失败' . ($aiError !== '' ? '：' . $aiError : '') . '，已用知识切片规则生成占位。',
        ];
    }

    return [
            'rows' => $aiRows,
            'source' => 'ai',
        'message' => '已从 ' . count($chunks) . ' 个知识切片生成 ' . count($aiRows) . ' 条首问样本。',
    ];
}

function geo_baseline_qa_load_chunk_context(PDO $db, string $customerId, string $brandName, int $limit = 18): array {
    $limit = max(5, min(30, $limit));
    $hasCustomerId = function_exists('db_column_exists') && db_column_exists($db, 'knowledge_bases', 'customer_id');
    $brandNeedle = trim($brandName);
    $where = [];
    $params = [];

    if ($hasCustomerId && $customerId !== '') {
        $where[] = 'kb.customer_id = ?';
        $params[] = $customerId;
    }
    if ($brandNeedle !== '') {
        $where[] = "(LOWER(kb.name) LIKE LOWER(?) OR LOWER(kb.description) LIKE LOWER(?) OR LOWER(c.content) LIKE LOWER(?))";
        $like = '%' . $brandNeedle . '%';
        array_push($params, $like, $like, $like);
    }

    $whereSql = $where ? 'WHERE ' . implode(' OR ', $where) : '';
    $sql = "
        SELECT c.content, c.chunk_title, c.section_path, c.token_count, kb.name AS kb_name, kb.created_at
        FROM knowledge_chunks c
        JOIN knowledge_bases kb ON kb.id = c.knowledge_base_id
        {$whereSql}
        ORDER BY kb.created_at DESC, c.chunk_index ASC
        LIMIT {$limit}
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($rows)) {
        return $rows;
    }

    $stmt = $db->query("
        SELECT c.content, c.chunk_title, c.section_path, c.token_count, kb.name AS kb_name, kb.created_at
        FROM knowledge_chunks c
        JOIN knowledge_bases kb ON kb.id = c.knowledge_base_id
        ORDER BY kb.created_at DESC, c.chunk_index ASC
        LIMIT {$limit}
    ");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function geo_baseline_qa_load_competitors(PDO $db, string $customerId): array {
    if ($customerId === '') {
        return [];
    }
    try {
        $stmt = $db->prepare("SELECT competitor FROM geo_customer_competitors WHERE customer_id = ? AND enabled = TRUE ORDER BY id ASC LIMIT 6");
        $stmt->execute([$customerId]);
        return array_values(array_filter(array_map('trim', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [])));
    } catch (Throwable $e) {
        return [];
    }
}

function geo_baseline_qa_parse_generated_rows(string $content, int $limit, string $brandName = '', string $categoryLabel = ''): array {
    $json = trim($content);
    if (preg_match('/```(?:json)?\s*(.*?)```/is', $json, $m)) {
        $json = trim($m[1]);
    }
    if (!str_starts_with($json, '[')) {
        $start = strpos($json, '[');
        $end = strrpos($json, ']');
        if ($start !== false && $end !== false && $end > $start) {
            $json = substr($json, $start, $end - $start + 1);
        }
    }

    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        return [];
    }

    $rows = [];
    foreach ($decoded as $item) {
        if (!is_array($item)) continue;
        $question = geo_baseline_qa_normalize_radar_question(trim((string) ($item['question'] ?? '')), $brandName, $categoryLabel);
        if ($question === '') continue;
        $rows[] = [
            'question' => $question,
            'platform' => 'deepseek',
            'baseline_answer' => '',
            'sort_order' => count($rows) + 1,
        ];
        if (count($rows) >= $limit) break;
    }

    return $rows;
}

function geo_baseline_qa_normalize_radar_question(string $question, string $brandName, string $categoryLabel = ''): string {
    $question = trim($question);
    $brandName = trim($brandName);
    $categoryLabel = trim($categoryLabel);
    if ($question === '') {
        return $question;
    }

    if ($brandName !== '' && mb_stripos($question, $brandName) !== false) {
        $question = trim(str_replace($brandName, '', $question));
    }
    if ($categoryLabel === '') {
        $categoryLabel = '这个品类';
    }

    $question = preg_replace_callback(
        '/^(这款茶叶|这款产品|这个产品|这款茶|这个茶|该款|该茶|这款|这个|它|其)/u',
        static function (array $matches) use ($categoryLabel): string {
            return $categoryLabel;
        },
        $question,
        1
    ) ?? $question;

    if (mb_stripos($question, $categoryLabel) !== false) {
        return $question;
    }
    $coreCategory = geo_baseline_qa_core_category_token($categoryLabel);
    if ($coreCategory !== '' && mb_stripos($question, $coreCategory) !== false) {
        return $question;
    }

    return $categoryLabel . $question;
}

function geo_baseline_qa_core_category_token(string $categoryLabel): string {
    $tokens = ['藤茶', '普洱茶', '白茶', '绿茶', '红茶', '乌龙茶', '岩茶', '龙井茶', '铁观音', '茶叶'];
    foreach ($tokens as $token) {
        if (mb_stripos($categoryLabel, $token) !== false) {
            return $token;
        }
    }
    return '';
}

function geo_baseline_qa_fallback_rows(string $brandName, string $industry, array $chunks, int $limit, array $competitors = []): array {
    $contextText = implode(' ', array_map(static fn (array $chunk): string => (string) ($chunk['content'] ?? ''), $chunks));
    $category = geo_baseline_qa_infer_category_label($brandName, $industry, $contextText);
    $questions = geo_baseline_qa_radar_question_blueprint($category, $competitors);
    $rows = [];
    for ($i = 0; $i < $limit; $i++) {
        $rows[] = [
            'question' => $questions[$i] ?? (($brandName !== '' ? $brandName : '这个品牌') . '相关问题' . ($i + 1)),
            'platform' => 'deepseek',
            'baseline_answer' => '',
            'sort_order' => $i + 1,
        ];
    }

    return $rows;
}

function geo_baseline_qa_radar_question_blueprint(string $category, array $competitors = []): array {
    $category = trim($category) !== '' ? trim($category) : '这个品类';
    $competitors = array_values(array_filter(array_map('trim', $competitors)));
    $compA = $competitors[0] ?? '';
    $compB = $competitors[1] ?? '';

    $questions = [
        $category . '是什么？适合什么人了解？',
        $category . '有哪些品牌比较值得推荐？',
        $category . '有哪些品牌或产区排名比较靠前？',
        '买' . $category . '时应该怎么选？',
        $category . '怎么判断品质和性价比？',
        $category . '适合送礼、自用还是日常饮用？',
        $category . '和同类产品相比有什么区别？',
        '买' . $category . '有哪些常见坑需要避开？',
        $category . '应该怎么使用或冲泡效果更好？',
        $category . '最近口碑比较好的品牌有哪些？',
    ];

    if ($compA !== '' && $compB !== '') {
        $questions[6] = $category . '里' . $compA . '和' . $compB . '怎么选？';
        $questions[9] = $category . '品牌推荐里' . $compA . '、' . $compB . '这类品牌排名怎么样？';
    } elseif ($compA !== '') {
        $questions[6] = $category . '里' . $compA . '和其他品牌相比怎么样？';
    }

    return $questions;
}

function geo_baseline_qa_save_for_diagnosis(PDO $db, string $diagnosisId, string $customerId, string $brandName, array $input): int {
    geo_baseline_qa_ensure_schema($db);

    $questions = is_array($input['baseline_question'] ?? null) ? $input['baseline_question'] : [];
    $answers = is_array($input['baseline_answer'] ?? null) ? $input['baseline_answer'] : [];
    $platforms = is_array($input['baseline_platform'] ?? null) ? $input['baseline_platform'] : [];
    $rows = [];
    foreach ($questions as $index => $rawQuestion) {
        $question = trim((string) $rawQuestion);
        $answer = trim((string) ($answers[$index] ?? ''));
        if ($question === '' && $answer === '') {
            continue;
        }
        if ($question === '') {
            continue;
        }
        $platform = trim((string) ($platforms[$index] ?? 'deepseek'));
        if (!array_key_exists($platform, geo_baseline_qa_platforms())) {
            $platform = 'deepseek';
        }
        $rows[] = [
            'question' => $question,
            'platform' => $platform,
            'answer' => $answer,
            'mention_brand' => $brandName !== '' && mb_stripos($answer, $brandName) !== false,
            'sort_order' => count($rows) + 1,
        ];
    }

    $db->beginTransaction();
    try {
        $delete = $db->prepare("DELETE FROM geo_baseline_qa WHERE diagnosis_id = ?");
        $delete->execute([$diagnosisId]);

        if (empty($rows)) {
            $db->commit();
            return 0;
        }

        $insert = $db->prepare("
            INSERT INTO geo_baseline_qa (
                diagnosis_id, customer_id, brand_name, question, platform, baseline_answer,
                mention_brand, sentiment, keywords, sort_order, updated_at
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
        ");
        $kwInsert = $db->prepare("
            INSERT INTO geo_monitor_keywords (customer_id, keyword, enabled)
            VALUES (?, ?, TRUE)
            ON CONFLICT (customer_id, keyword) DO UPDATE SET enabled = TRUE
        ");

        foreach ($rows as $row) {
            $insert->execute([
                $diagnosisId,
                $customerId,
                $brandName,
                $row['question'],
                $row['platform'],
                $row['answer'],
                $row['mention_brand'] ? 'true' : 'false',
                'neutral',
                '',
                $row['sort_order'],
            ]);
            if ($customerId !== '') {
                $kwInsert->execute([$customerId, $row['question']]);
            }
        }

        $db->commit();
        return count($rows);
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

function geo_baseline_qa_for_diagnosis(PDO $db, string $diagnosisId): array {
    geo_baseline_qa_ensure_schema($db);
    $stmt = $db->prepare("
        SELECT *
        FROM geo_baseline_qa
        WHERE diagnosis_id = ? AND status = 'active'
        ORDER BY sort_order ASC, id ASC
    ");
    $stmt->execute([$diagnosisId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function geo_baseline_qa_tracking(PDO $db, string $customerId): array {
    geo_baseline_qa_ensure_schema($db);
    if ($customerId === '') {
        return ['rows' => [], 'summary' => ['total' => 0, 'retested' => 0, 'changed' => 0, 'missing' => 0]];
    }

    $stmt = $db->prepare("
        SELECT
            b.*,
            r.id AS record_id,
            r.brand_mentioned AS current_mention,
            r.mention_count AS current_mention_count,
            r.response_snippet,
            r.full_response,
            r.queried_at,
            r.competitors_found
        FROM geo_baseline_qa b
        LEFT JOIN LATERAL (
            SELECT *
            FROM geo_monitor_records r
            WHERE r.customer_id = b.customer_id
              AND r.query_text = b.question
              AND r.provider = b.platform
            ORDER BY r.queried_at DESC, r.id DESC
            LIMIT 1
        ) r ON TRUE
        WHERE b.customer_id = ? AND b.status = 'active'
        ORDER BY b.created_at ASC, b.sort_order ASC, b.id ASC
    ");
    $stmt->execute([$customerId]);

    $rows = [];
    $summary = ['total' => 0, 'retested' => 0, 'changed' => 0, 'missing' => 0];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $summary['total']++;
        $currentAnswer = trim((string) ($row['full_response'] ?: $row['response_snippet']));
        $hasCurrent = $currentAnswer !== '';
        $similarity = null;
        if ($hasCurrent) {
            $summary['retested']++;
            similar_text(
                mb_substr((string) $row['baseline_answer'], 0, 800),
                mb_substr($currentAnswer, 0, 800),
                $similarity
            );
            $similarity = round((float) $similarity, 1);
        } else {
            $summary['missing']++;
        }
        $mentionChanged = $hasCurrent && ((bool) $row['mention_brand'] !== (bool) $row['current_mention']);
        $semanticChanged = $hasCurrent && $similarity !== null && $similarity < 65;
        if ($mentionChanged || $semanticChanged) {
            $summary['changed']++;
        }

        $row['current_answer'] = $currentAnswer;
        $row['has_current'] = $hasCurrent;
        $row['similarity'] = $similarity;
        $row['mention_changed'] = $mentionChanged;
        $row['semantic_changed'] = $semanticChanged;
        $rows[] = $row;
    }

    return ['rows' => $rows, 'summary' => $summary];
}
