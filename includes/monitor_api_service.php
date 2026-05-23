<?php
/**
 * GEO监测关键词API服务
 */

if (!defined('FEISHU_TREASURE')) {
    die('Access denied');
}

class MonitorApiService {
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    public function addKeywords(string $customerId, array $keywords): array {
        $added = 0;
        $stmt = $this->db->prepare("
            INSERT INTO geo_monitor_keywords (customer_id, keyword, enabled)
            VALUES (?, ?, true)
            ON CONFLICT (customer_id, keyword) DO UPDATE SET enabled = true
        ");
        foreach ($keywords as $kw) {
            $kw = trim((string) $kw);
            if ($kw !== '') {
                $stmt->execute([$customerId, $kw]);
                $added++;
            }
        }
        return ['added' => $added, 'customer_id' => $customerId];
    }

    public function listKeywords(string $customerId): array {
        $stmt = $this->db->prepare("SELECT * FROM geo_monitor_keywords WHERE customer_id = ? ORDER BY created_at DESC");
        $stmt->execute([$customerId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function removeKeyword(int $id): void {
        $stmt = $this->db->prepare("DELETE FROM geo_monitor_keywords WHERE id = ?");
        $stmt->execute([$id]);
    }

    public function toggleKeyword(int $id, bool $enabled): void {
        $stmt = $this->db->prepare("UPDATE geo_monitor_keywords SET enabled = ? WHERE id = ?");
        $stmt->execute([$enabled ? 1 : 0, $id]);
    }
}
