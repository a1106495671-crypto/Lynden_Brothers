<?php
/**
 * 引用基线服务 - 客户入驻时快照当前AI引用率，用于后续效果对比
 */

if (!defined('FEISHU_TREASURE')) {
    die('Access denied');
}

class CitationBaselineService {

    public function __construct(private PDO $db) {}

    /**
     * 为客户记录当前引用率基线（入驻时调用）
     */
    public function recordBaseline(string $customerId): array {
        $this->ensureTable();

        // 读取近 7 天监测数据
        $stmt = $this->db->prepare("
            SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN brand_mentioned THEN 1 ELSE 0 END) AS mentioned,
                COUNT(DISTINCT query_text) AS keyword_count
            FROM geo_monitor_records
            WHERE customer_id = ?
              AND queried_at >= CURRENT_DATE - INTERVAL '7 days'
        ");
        $stmt->execute([$customerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $total        = (int)($row['total'] ?? 0);
        $mentioned    = (int)($row['mentioned'] ?? 0);
        $keywordCount = (int)($row['keyword_count'] ?? 0);
        $rate         = $total > 0 ? round($mentioned / $total * 100, 2) : 0.0;
        $note         = $total === 0 ? 'no_data_yet' : null;

        $ins = $this->db->prepare("
            INSERT INTO geo_citation_baselines (customer_id, baseline_rate, keyword_count, note, recorded_at)
            VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)
        ");
        $ins->execute([$customerId, $rate, $keywordCount, $note]);

        return [
            'customer_id'   => $customerId,
            'baseline_rate' => $rate,
            'keyword_count' => $keywordCount,
            'recorded_at'   => date('Y-m-d H:i:s'),
            'note'          => $note,
        ];
    }

    /**
     * 获取最早的基线记录（入驻基准）
     */
    public function getBaseline(string $customerId): ?array {
        $stmt = $this->db->prepare("
            SELECT * FROM geo_citation_baselines
            WHERE customer_id = ?
            ORDER BY recorded_at ASC
            LIMIT 1
        ");
        $stmt->execute([$customerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * 计算从基线到现在的提升幅度
     */
    public function getImprovement(string $customerId): array {
        $baseline = $this->getBaseline($customerId);
        if (!$baseline) {
            return ['baseline_rate' => null, 'current_rate' => null, 'improvement_pp' => null, 'days_elapsed' => 0];
        }

        $stmt = $this->db->prepare("
            SELECT
                ROUND(100.0 * SUM(CASE WHEN brand_mentioned THEN 1 ELSE 0 END) / NULLIF(COUNT(*), 0), 2) AS rate
            FROM geo_monitor_records
            WHERE customer_id = ?
              AND queried_at >= CURRENT_DATE - INTERVAL '7 days'
        ");
        $stmt->execute([$customerId]);
        $currentRate = (float)($stmt->fetchColumn() ?? 0);

        $baselineRate  = (float)$baseline['baseline_rate'];
        $daysElapsed   = (int)round((time() - strtotime($baseline['recorded_at'])) / 86400);
        $improvementPp = round($currentRate - $baselineRate, 2);

        return [
            'baseline_rate'  => $baselineRate,
            'current_rate'   => $currentRate,
            'improvement_pp' => $improvementPp,
            'days_elapsed'   => $daysElapsed,
        ];
    }

    private function ensureTable(): void {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS geo_citation_baselines (
                id BIGSERIAL PRIMARY KEY,
                customer_id VARCHAR(80) NOT NULL,
                baseline_rate DECIMAL(5,2) NOT NULL DEFAULT 0,
                keyword_count INTEGER DEFAULT 0,
                note VARCHAR(200),
                recorded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_geo_citation_baselines_customer ON geo_citation_baselines(customer_id, recorded_at)");
    }
}
