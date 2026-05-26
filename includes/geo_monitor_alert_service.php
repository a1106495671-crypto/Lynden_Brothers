<?php
/**
 * GEO 监测告警服务
 *
 * 只基于真实 geo_monitor_records / geo_monitor_keywords 数据生成告警。
 */

if (!defined('FEISHU_TREASURE')) {
    die('Access denied');
}

function geo_monitor_alert_rate(int $hits, int $total): float {
    return $total > 0 ? round($hits / $total * 100, 1) : 0.0;
}

function geo_monitor_alert_insert(PDO $db, array $alert): bool {
    $stmt = $db->prepare("
        INSERT INTO geo_monitor_alerts
            (customer_id, alert_type, level, keyword, competitor_name,
             brand_rate, competitor_rate, detail, alerted_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON CONFLICT (customer_id, alert_type, keyword, competitor_name, alerted_at)
        DO UPDATE SET
            level           = EXCLUDED.level,
            brand_rate      = EXCLUDED.brand_rate,
            competitor_rate = EXCLUDED.competitor_rate,
            detail          = EXCLUDED.detail
    ");

    return $stmt->execute([
        $alert['customer_id'],
        $alert['alert_type'],
        $alert['level'],
        $alert['keyword'] ?? '',
        $alert['competitor_name'] ?? '',
        $alert['brand_rate'] ?? 0,
        $alert['competitor_rate'] ?? 0,
        $alert['detail'] ?? '',
        $alert['alerted_at'] ?? date('Y-m-d'),
    ]);
}

function geo_monitor_refresh_alerts(PDO $db, string $customerId, string $brandName, array $competitors = [], ?string $today = null): array {
    $today = $today ?: date('Y-m-d');
    $summary = [
        'created_or_updated' => 0,
        'checked_records' => 0,
        'alert_types' => [],
    ];

    if ($customerId === '') {
        return $summary;
    }

    $stmt = $db->prepare("
        SELECT provider, query_text, brand_mentioned, competitors_found, accuracy_score, queried_at
        FROM geo_monitor_records
        WHERE customer_id = ?
          AND queried_at >= CURRENT_DATE - INTERVAL '29 days'
    ");
    $stmt->execute([$customerId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $summary['checked_records'] = count($rows);
    if (empty($rows)) {
        return $summary;
    }

    $recentRows = [];
    $prevRows = [];
    $kwStats = [];
    $kwCompStats = [];
    $providerStats = [];
    $accuracyScores = [];

    $todayDate = new DateTimeImmutable($today);
    foreach ($rows as $row) {
        $queriedAt = new DateTimeImmutable((string) $row['queried_at']);
        $daysAgo = (int) $queriedAt->diff($todayDate)->format('%r%a');
        $isRecent = $daysAgo >= 0 && $daysAgo <= 6;
        $isPrevious = $daysAgo >= 7 && $daysAgo <= 13;

        if ($isRecent) {
            $recentRows[] = $row;
        } elseif ($isPrevious) {
            $prevRows[] = $row;
        }

        if (!$isRecent) {
            continue;
        }

        $kw = trim((string) ($row['query_text'] ?? ''));
        if ($kw === '') {
            $kw = '(未命名关键词)';
        }
        $kwStats[$kw] ??= ['hits' => 0, 'total' => 0];
        $kwStats[$kw]['total']++;
        if (!empty($row['brand_mentioned'])) {
            $kwStats[$kw]['hits']++;
            $providerStats[(string) ($row['provider'] ?? 'unknown')] = true;
        }

        $found = json_decode((string) ($row['competitors_found'] ?? '[]'), true);
        if (is_array($found)) {
            foreach ($found as $item) {
                $comp = trim((string) ($item['name'] ?? ''));
                if ($comp === '') {
                    continue;
                }
                $kwCompStats[$kw][$comp] ??= ['hits' => 0, 'total' => 0];
                $kwCompStats[$kw][$comp]['hits']++;
                $kwCompStats[$kw][$comp]['total'] = $kwStats[$kw]['total'];
            }
        }

        if ($row['accuracy_score'] !== null && $row['accuracy_score'] !== '') {
            $accuracyScores[] = (float) $row['accuracy_score'];
        }
    }

    if (empty($recentRows)) {
        return $summary;
    }

    $insert = function(array $alert) use ($db, &$summary): void {
        if (geo_monitor_alert_insert($db, $alert)) {
            $summary['created_or_updated']++;
            $summary['alert_types'][$alert['alert_type']] = ($summary['alert_types'][$alert['alert_type']] ?? 0) + 1;
        }
    };

    $recentHits = count(array_filter($recentRows, static fn($r) => !empty($r['brand_mentioned'])));
    $recentRate = geo_monitor_alert_rate($recentHits, count($recentRows));
    $prevHits = count(array_filter($prevRows, static fn($r) => !empty($r['brand_mentioned'])));
    $prevRate = geo_monitor_alert_rate($prevHits, count($prevRows));

    if (count($recentRows) >= 5 && count($prevRows) >= 5) {
        $drop = round($prevRate - $recentRate, 1);
        if ($drop >= 15) {
            $insert([
                'customer_id' => $customerId,
                'alert_type' => 'visibility_drop',
                'level' => $drop >= 30 ? 'high' : 'medium',
                'brand_rate' => $recentRate,
                'competitor_rate' => $prevRate,
                'detail' => "{$brandName} 近7天品牌提及率 {$recentRate}%，较前7天 {$prevRate}% 下降 {$drop}pp",
                'alerted_at' => $today,
            ]);
        }
    }

    if (!empty($kwStats)) {
        $rates = [];
        foreach ($kwStats as $kw => $stat) {
            $rate = geo_monitor_alert_rate((int) $stat['hits'], (int) $stat['total']);
            $rates[] = $rate;
            if ((int) $stat['total'] >= 2 && $rate <= 0) {
                $insert([
                    'customer_id' => $customerId,
                    'alert_type' => 'keyword_zero_visibility',
                    'level' => 'high',
                    'keyword' => $kw,
                    'brand_rate' => $rate,
                    'competitor_rate' => 30,
                    'detail' => "关键词「{$kw}」近7天 {$stat['total']} 次监测均未提及 {$brandName}",
                    'alerted_at' => $today,
                ]);
            }
        }

        $coreRate = round(array_sum($rates) / max(1, count($rates)), 1);
        if ($coreRate < 80) {
            $insert([
                'customer_id' => $customerId,
                'alert_type' => 'core_rate_low',
                'level' => $coreRate < 50 ? 'high' : ($coreRate < 65 ? 'medium' : 'low'),
                'brand_rate' => $coreRate,
                'competitor_rate' => 80,
                'detail' => "{$brandName} 近7天核心关键词平均提及率 {$coreRate}%，低于80%达标线",
                'alerted_at' => $today,
            ]);
        }
    }

    foreach ($kwCompStats as $kw => $compStats) {
        $brandTotal = (int) ($kwStats[$kw]['total'] ?? 0);
        $brandHits = (int) ($kwStats[$kw]['hits'] ?? 0);
        $brandRate = geo_monitor_alert_rate($brandHits, $brandTotal);
        foreach ($compStats as $comp => $stat) {
            if (!empty($competitors) && !in_array($comp, $competitors, true)) {
                continue;
            }
            $compRate = geo_monitor_alert_rate((int) $stat['hits'], $brandTotal);
            if ($brandTotal > 0 && $compRate > $brandRate) {
                $diff = round($compRate - $brandRate, 1);
                $insert([
                    'customer_id' => $customerId,
                    'alert_type' => 'competitor_surpass',
                    'level' => $diff >= 20 ? 'high' : ($diff >= 10 ? 'medium' : 'low'),
                    'keyword' => $kw,
                    'competitor_name' => $comp,
                    'brand_rate' => $brandRate,
                    'competitor_rate' => $compRate,
                    'detail' => "关键词「{$kw}」近7天品牌提及率 {$brandRate}%，竞品「{$comp}」{$compRate}%，超出 {$diff}pp",
                    'alerted_at' => $today,
                ]);
            }
        }
    }

    $sourceCount = count($providerStats);
    if (count($recentRows) >= 5 && $sourceCount < 2) {
        $insert([
            'customer_id' => $customerId,
            'alert_type' => 'source_diversity_low',
            'level' => $sourceCount === 0 ? 'high' : 'medium',
            'brand_rate' => $sourceCount,
            'competitor_rate' => 2,
            'detail' => "{$brandName} 近7天只有 {$sourceCount} 个平台出现品牌提及，低于2个平台的最低覆盖线",
            'alerted_at' => $today,
        ]);
    }

    if (count($accuracyScores) >= 3) {
        $avgAccuracy = round(array_sum($accuracyScores) / count($accuracyScores), 1);
        if ($avgAccuracy < 85) {
            $insert([
                'customer_id' => $customerId,
                'alert_type' => 'accuracy_low',
                'level' => $avgAccuracy < 70 ? 'high' : 'medium',
                'brand_rate' => $avgAccuracy,
                'competitor_rate' => 85,
                'detail' => "{$brandName} 近7天语义准确度均值 {$avgAccuracy} 分，低于85分安全线",
                'alerted_at' => $today,
            ]);
        }
    }

    return $summary;
}
