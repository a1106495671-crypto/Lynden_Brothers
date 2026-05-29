<?php
/**
 * 择时发布助手 - 分析历史数据，推荐最佳发布小时
 */

if (!defined('FEISHU_TREASURE')) {
    die('Access denied');
}

/**
 * 根据历史成功发布数据，返回该平台最佳发布小时 (0-23)
 * 有数据时取历史峰值小时，无数据时返回平台默认值
 */
function get_optimal_publish_hour(PDO $db, string $platform, string $customerId = ''): int {
    $defaults = [
        'zhihu'       => 10,
        'xiaohongshu' => 20,
        'wechat'      => 21,
        'csdn'        => 9,
        'juejin'      => 10,
    ];

    try {
        $sql = "
            SELECT EXTRACT(HOUR FROM finished_at)::INTEGER AS hour, COUNT(*) AS cnt
            FROM media_publish_jobs
            WHERE status = 'success'
              AND platform = ?
              AND finished_at >= CURRENT_TIMESTAMP - INTERVAL '90 days'
        ";
        $params = [$platform];

        if ($customerId !== '') {
            $sql .= " AND account_id IN (SELECT id FROM media_accounts WHERE customer_id = ? AND status = 'active')";
            $params[] = $customerId;
        }

        $sql .= " GROUP BY hour ORDER BY cnt DESC LIMIT 1";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row && isset($row['hour']) && (int)$row['cnt'] >= 3) {
            return (int)$row['hour'];
        }
    } catch (Throwable $e) {
        // 表可能没有 customer_id 列，fallback to platform default
    }

    return $defaults[$platform] ?? 10;
}

/**
 * 返回下一个指定小时的 timestamp（今天还没到就返回今天，否则返回明天）
 */
function next_occurrence_of_hour(int $hour): string {
    $now    = time();
    $target = mktime($hour, 0, 0);
    if ($target <= $now) {
        $target = mktime($hour, 0, 0, (int)date('n'), (int)date('j') + 1);
    }
    return date('Y-m-d H:i:s', $target);
}
