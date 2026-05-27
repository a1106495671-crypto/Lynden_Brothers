<?php
/**
 * 引用率加权选题器 - 优先为覆盖薄弱的关键词生成内容
 */

if (!defined('FEISHU_TREASURE')) {
    die('Access denied');
}

/**
 * 从候选标题列表中，选出最能覆盖引用薄弱关键词的标题
 *
 * @param PDO    $db              数据库连接
 * @param string $customerId      客户ID
 * @param array  $availableTitles 候选标题数组 [['id'=>?, 'title'=>?, ...], ...]
 * @return array|null             最优标题，或 null（回退随机）
 */
function citation_weighted_title_select(PDO $db, string $customerId, array $availableTitles): ?array {
    if (empty($availableTitles) || $customerId === '') {
        return null;
    }

    try {
        // 读取近 14 天各关键词的提及率
        $stmt = $db->prepare("
            SELECT query_text, ROUND(100.0 * SUM(CASE WHEN brand_mentioned THEN 1 ELSE 0 END) / COUNT(*), 1) AS rate
            FROM geo_monitor_records
            WHERE customer_id = ?
              AND queried_at >= CURRENT_DATE - INTERVAL '14 days'
            GROUP BY query_text
        ");
        $stmt->execute([$customerId]);
        $kwRates = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $kwRates[mb_strtolower($row['query_text'])] = (float)$row['rate'];
        }

        if (empty($kwRates)) {
            return null; // 无监测数据，回退随机
        }

        // 为每个候选标题打分：标题中包含低引用率关键词的，分数越高
        $scored = [];
        foreach ($availableTitles as $t) {
            $titleLower = mb_strtolower((string)($t['title'] ?? ''));
            $score      = 0;
            foreach ($kwRates as $kw => $rate) {
                if (mb_strpos($titleLower, $kw) !== false) {
                    // 引用率越低，这个标题的优先级越高
                    $score += max(0, 100 - $rate);
                }
            }
            $scored[] = ['title' => $t, 'score' => $score];
        }

        // 按分数降序，相同分数随机排序
        usort($scored, function ($a, $b) {
            if ($a['score'] === $b['score']) return rand(-1, 1);
            return $b['score'] <=> $a['score'];
        });

        // 取最高分的标题（若最高分为0则回退随机）
        if ($scored[0]['score'] > 0) {
            return $scored[0]['title'];
        }
    } catch (Throwable $e) {
        // 静默失败，回退随机
    }

    return null;
}
