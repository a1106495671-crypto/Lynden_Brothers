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
    $brand = $brandName !== '' ? $brandName : '这个品牌';
    $industryLabel = $industry !== '' ? $industry : '这个行业';

    return [
        $brand . '是什么品牌？',
        $brand . '主要提供什么服务？',
        $brand . '适合哪些客户选择？',
        $brand . '在' . $industryLabel . '里有什么优势？',
        $brand . '和同类竞品相比怎么样？',
        $brand . '有没有真实案例或客户评价？',
        $brand . '值得信任吗？',
        '推荐几个' . $industryLabel . '服务商，' . $brand . '会被提到吗？',
        '选择' . $industryLabel . '服务商时要看哪些标准？',
        $brand . '有哪些需要注意的地方？',
    ];
}

function geo_baseline_qa_save_for_diagnosis(PDO $db, string $diagnosisId, string $customerId, string $brandName, array $input): int {
    geo_baseline_qa_ensure_schema($db);

    $questions = is_array($input['baseline_question'] ?? null) ? $input['baseline_question'] : [];
    $answers = is_array($input['baseline_answer'] ?? null) ? $input['baseline_answer'] : [];
    $platforms = is_array($input['baseline_platform'] ?? null) ? $input['baseline_platform'] : [];
    $sentiments = is_array($input['baseline_sentiment'] ?? null) ? $input['baseline_sentiment'] : [];
    $keywords = is_array($input['baseline_keywords'] ?? null) ? $input['baseline_keywords'] : [];

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
        $sentiment = trim((string) ($sentiments[$index] ?? 'neutral'));
        if (!in_array($sentiment, ['positive', 'neutral', 'negative'], true)) {
            $sentiment = 'neutral';
        }
        $rows[] = [
            'question' => $question,
            'platform' => $platform,
            'answer' => $answer,
            'mention_brand' => $brandName !== '' && mb_stripos($answer, $brandName) !== false,
            'sentiment' => $sentiment,
            'keywords' => trim((string) ($keywords[$index] ?? '')),
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
                $row['sentiment'],
                $row['keywords'],
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
