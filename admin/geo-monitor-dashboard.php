<?php
/**
 * GEO 监测数据大屏
 */

define('FEISHU_TREASURE', true);
session_start();

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/database_admin.php';

require_admin_login();

function dashboard_h($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function dashboard_read_json(string $path): array {
    if (!is_file($path)) {
        return [];
    }

    $json = json_decode((string) file_get_contents($path), true);
    return is_array($json) ? $json : [];
}

function dashboard_read_jsonl(string $path): array {
    if (!is_file($path)) {
        return [];
    }

    $rows = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $row = json_decode((string) $line, true);
        if (is_array($row)) {
            $rows[] = $row;
        }
    }
    return $rows;
}

function dashboard_percent(int $num, int $den): int {
    return $den > 0 ? (int) round(($num / $den) * 100) : 0;
}

function dashboard_report_key_from_name(string $reportName): string {
    return preg_replace('/^\d{4}-\d{2}-\d{2}(?:-[0-9]+)?(?:-[a-z0-9]+)?-/u', '', $reportName) ?: $reportName;
}

function dashboard_make_bucket(array $extra = []): array {
    return array_merge([
        'total' => 0,
        'ok' => 0,
        'visible' => 0,
        'naturalOk' => 0,
        'naturalVisible' => 0,
        'competitor' => 0,
        'sourceRows' => 0,
        'firstRecommend' => 0,
        'firstRecommendBase' => 0,
        'semanticSum' => 0.0,
        'semanticCount' => 0,
        'rankSum' => 0.0,
        'rankCount' => 0,
        'bestClientRank' => null,
        'averageClientRank' => null,
        'firstRecommendRate' => 0,
        'semanticMatchRate' => 0,
        'technicalCapabilityRate' => 0,
        'proofCapabilityRate' => 0,
        'cognitionCapabilityRate' => 0,
        'visibilityRate' => 0,
        'naturalVisibilityRate' => 0,
        'competitorRate' => 0,
        'sourceCoverageRate' => 0,
    ], $extra);
}

function dashboard_semantic_match_score(array $row): ?float {
    if (empty($row['ok'])) {
        return null;
    }

    $keyword = mb_strtolower((string) ($row['keyword'] ?? ''), 'UTF-8');
    $answer = mb_strtolower((string) ($row['answer'] ?? $row['answer_summary'] ?? ''), 'UTF-8');
    if ($keyword === '' || $answer === '') {
        return null;
    }

    preg_match_all('/[\p{Han}]{2,}|[a-z0-9]{3,}/u', $keyword, $matches);
    $tokens = array_values(array_unique(array_filter(array_map(static function ($token): string {
        $token = trim((string) $token);
        if ($token === '') return '';
        $stop = ['哪些', '可以', '怎么', '如何', '公司', '服务商', '品牌', '推荐', '比较', '值得', '了解', '企业', '提升', '排名', '优化', '生成式', '引擎'];
        return in_array($token, $stop, true) ? '' : $token;
    }, (array) ($matches[0] ?? [])))));

    if (empty($tokens)) {
        return null;
    }

    $hit = 0;
    foreach ($tokens as $token) {
        if (mb_strpos($answer, $token, 0, 'UTF-8') !== false) {
            $hit++;
        }
    }

    return $hit / count($tokens);
}

function dashboard_row_rank(array $row): ?float {
    foreach ((array) ($row['mention_ranking'] ?? []) as $ranking) {
        if (preg_match('/^(\d+(?:\.\d+)?)\..*（客户）/u', (string) $ranking, $match)) {
            return (float) $match[1];
        }
    }

    if (isset($row['client_rank']) && is_numeric($row['client_rank']) && (float) $row['client_rank'] > 0) {
        return (float) $row['client_rank'];
    }
    return null;
}

function dashboard_add_metric(array &$bucket, array $row): void {
    $bucket['total']++;
    $ok = !empty($row['ok']);
    $brandQuery = !empty($row['keyword_contains_client']);
    $visible = !empty($row['client_mentioned']);
    $competitor = !empty($row['competitor_mentioned']);

    if ($ok) {
        $bucket['ok']++;
    }
    if ($ok && !$brandQuery) {
        $bucket['naturalOk']++;
    }
    if ($visible) {
        $bucket['visible']++;
    }
    if ($visible && !$brandQuery) {
        $bucket['naturalVisible']++;
    }
    if ($competitor) {
        $bucket['competitor']++;
    }
    foreach ((array) ($row['sources'] ?? []) as $source) {
        if ($ok && trim((string) $source) !== '') {
            $bucket['sourceRows']++;
            break;
        }
    }

    $rank = dashboard_row_rank($row);
    if ($ok && $visible && $rank !== null) {
        $bucket['rankSum'] += $rank;
        $bucket['rankCount']++;
        $bucket['bestClientRank'] = $bucket['bestClientRank'] === null ? $rank : min((float) $bucket['bestClientRank'], $rank);
        $bucket['firstRecommendBase']++;
        if ((float) $rank === 1.0) {
            $bucket['firstRecommend']++;
        }
    }

    $semantic = dashboard_semantic_match_score($row);
    if ($semantic !== null) {
        $bucket['semanticSum'] += $semantic;
        $bucket['semanticCount']++;
    }
}

function dashboard_finalize_metric(array $bucket): array {
    $bucket['visibilityRate'] = dashboard_percent((int) $bucket['visible'], (int) $bucket['ok']);
    $bucket['naturalVisibilityRate'] = dashboard_percent((int) $bucket['naturalVisible'], (int) $bucket['naturalOk']);
    $bucket['competitorRate'] = dashboard_percent((int) $bucket['competitor'], (int) $bucket['ok']);
    $bucket['sourceCoverageRate'] = dashboard_percent((int) $bucket['sourceRows'], (int) $bucket['ok']);
    $bucket['averageClientRank'] = $bucket['rankCount'] > 0 ? round(((float) $bucket['rankSum']) / (int) $bucket['rankCount'], 1) : null;
    $bucket['firstRecommendRate'] = dashboard_percent((int) $bucket['firstRecommend'], (int) $bucket['firstRecommendBase']);
    $bucket['semanticMatchRate'] = $bucket['semanticCount'] > 0 ? (int) round((((float) $bucket['semanticSum']) / (int) $bucket['semanticCount']) * 100) : 0;
    $bucket['technicalCapabilityRate'] = dashboard_weighted_score([
        [(int) $bucket['semanticMatchRate'], 55],
        [(int) $bucket['sourceCoverageRate'], 45],
    ]);
    $bucket['proofCapabilityRate'] = dashboard_weighted_score([
        [(int) $bucket['visibilityRate'], 45],
        [(int) $bucket['firstRecommendRate'], 25],
        [(int) $bucket['sourceCoverageRate'], 30],
    ]);
    unset($bucket['rankSum'], $bucket['rankCount'], $bucket['semanticSum'], $bucket['semanticCount']);
    return $bucket;
}

function dashboard_weighted_score(array $scores): int {
    $sum = 0;
    $weightSum = 0;
    foreach ($scores as $item) {
        $score = (int) ($item[0] ?? 0);
        $weight = (int) ($item[1] ?? 0);
        $sum += max(0, min(100, $score)) * $weight;
        $weightSum += $weight;
    }
    return $weightSum > 0 ? (int) round($sum / $weightSum) : 0;
}

function dashboard_compute_summary(array $rows, string $projectKey, array $project = []): array {
    $overall = dashboard_make_bucket();
    $byProvider = [];
    $byKeyword = [];
    $byProviderKeyword = [];
    $maxSampleCount = 1;

    foreach ($rows as $row) {
        dashboard_add_metric($overall, $row);
        $maxSampleCount = max($maxSampleCount, (int) ($row['sample_count'] ?? 1));

        $providerName = (string) ($row['provider_name'] ?? '未知AI');
        if (!isset($byProvider[$providerName])) {
            $byProvider[$providerName] = dashboard_make_bucket([
                'provider_id' => (string) ($row['provider_id'] ?? ''),
                'provider_name' => $providerName,
            ]);
        }
        dashboard_add_metric($byProvider[$providerName], $row);

        $keyword = (string) ($row['keyword'] ?? '未命名关键词');
        if (!isset($byKeyword[$keyword])) {
            $byKeyword[$keyword] = dashboard_make_bucket([
                'keyword' => $keyword,
                'intent' => (string) ($row['intent'] ?? ''),
                'keyword_contains_client' => !empty($row['keyword_contains_client']),
            ]);
        }
        dashboard_add_metric($byKeyword[$keyword], $row);

        $matrixKey = $providerName . '||' . $keyword;
        if (!isset($byProviderKeyword[$matrixKey])) {
            $byProviderKeyword[$matrixKey] = dashboard_make_bucket([
                'provider_id' => (string) ($row['provider_id'] ?? ''),
                'provider_name' => $providerName,
                'keyword' => $keyword,
                'intent' => (string) ($row['intent'] ?? ''),
                'keyword_contains_client' => !empty($row['keyword_contains_client']),
            ]);
        }
        dashboard_add_metric($byProviderKeyword[$matrixKey], $row);
    }

    $overall = dashboard_finalize_metric($overall);
    foreach ($byProvider as $key => $bucket) {
        $byProvider[$key] = dashboard_finalize_metric($bucket);
    }
    foreach ($byKeyword as $key => $bucket) {
        $byKeyword[$key] = dashboard_finalize_metric($bucket);
    }
    foreach ($byProviderKeyword as $key => $bucket) {
        $byProviderKeyword[$key] = dashboard_finalize_metric($bucket);
    }

    $keywordCount = count($byKeyword);
    $coveredKeywordCount = 0;
    foreach ($byKeyword as $bucket) {
        if ((int) ($bucket['visibilityRate'] ?? 0) > 0) {
            $coveredKeywordCount++;
        }
    }

    $providerSpreadByKeyword = [];
    foreach ($byProviderKeyword as $bucket) {
        $kw = (string) ($bucket['keyword'] ?? '');
        if ($kw === '') {
            continue;
        }
        $providerSpreadByKeyword[$kw][] = (int) ($bucket['visibilityRate'] ?? 0);
    }
    $spreadSum = 0;
    $spreadCount = 0;
    foreach ($providerSpreadByKeyword as $rates) {
        if (count($rates) < 2) {
            continue;
        }
        $spreadSum += max($rates) - min($rates);
        $spreadCount++;
    }
    $modelDifferenceRate = $spreadCount > 0 ? (int) round($spreadSum / $spreadCount) : 0;
    $cognitionCapabilityRate = dashboard_weighted_score([
        [dashboard_percent($coveredKeywordCount, $keywordCount), 45],
        [(int) $overall['semanticMatchRate'], 35],
        [max(0, 100 - $modelDifferenceRate), 20],
    ]);

    return [
        'projectKey' => $projectKey,
        'projectName' => (string) ($project['name'] ?? ''),
        'clientName' => (string) ($project['clientName'] ?? ''),
        'clientAliases' => array_values((array) ($project['clientAliases'] ?? [])),
        'competitors' => array_values((array) ($project['competitors'] ?? [])),
        'total' => count($rows),
        'ok' => (int) $overall['ok'],
        'failed' => max(0, count($rows) - (int) $overall['ok']),
        'visible' => (int) $overall['visible'],
        'naturalOk' => (int) $overall['naturalOk'],
        'naturalVisible' => (int) $overall['naturalVisible'],
        'competitor' => (int) $overall['competitor'],
        'sourceRows' => (int) $overall['sourceRows'],
        'visibilityRate' => (int) $overall['visibilityRate'],
        'naturalVisibilityRate' => (int) $overall['naturalVisibilityRate'],
        'competitorRate' => (int) $overall['competitorRate'],
        'sourceCoverageRate' => (int) $overall['sourceCoverageRate'],
        'firstRecommendRate' => (int) $overall['firstRecommendRate'],
        'semanticMatchRate' => (int) $overall['semanticMatchRate'],
        'scenarioCoverageRate' => dashboard_percent($coveredKeywordCount, $keywordCount),
        'modelDifferenceRate' => $modelDifferenceRate,
        'technicalCapabilityRate' => (int) $overall['technicalCapabilityRate'],
        'proofCapabilityRate' => (int) $overall['proofCapabilityRate'],
        'cognitionCapabilityRate' => $cognitionCapabilityRate,
        'averageClientRank' => $overall['averageClientRank'],
        'bestClientRank' => $overall['bestClientRank'],
        'sampleCount' => $maxSampleCount,
        'byProvider' => $byProvider,
        'byKeyword' => $byKeyword,
        'providerKeywordRows' => array_values($byProviderKeyword),
        'trend' => ['direction' => 'baseline', 'visibilityRateDelta' => null, 'naturalVisibilityRateDelta' => null, 'averageRankDelta' => null],
    ];
}

function dashboard_metric_trend(array $current, ?array $previous): array {
    if (empty($previous)) {
        return [
            'direction' => 'baseline',
            'visibilityRateDelta' => null,
            'naturalVisibilityRateDelta' => null,
            'averageRankDelta' => null,
        ];
    }

    $visibilityRateDelta = (int) ($current['visibilityRate'] ?? 0) - (int) ($previous['visibilityRate'] ?? 0);
    $naturalVisibilityRateDelta = (int) ($current['naturalVisibilityRate'] ?? 0) - (int) ($previous['naturalVisibilityRate'] ?? 0);
    $averageRankDelta = null;
    if (($current['averageClientRank'] ?? null) !== null && ($previous['averageClientRank'] ?? null) !== null) {
        $averageRankDelta = round((float) $current['averageClientRank'] - (float) $previous['averageClientRank'], 1);
    }

    $direction = 'flat';
    if ($visibilityRateDelta > 0 || ($visibilityRateDelta === 0 && $averageRankDelta !== null && $averageRankDelta < 0)) {
        $direction = 'up';
    } elseif ($visibilityRateDelta < 0 || ($visibilityRateDelta === 0 && $averageRankDelta !== null && $averageRankDelta > 0)) {
        $direction = 'down';
    }

    return [
        'direction' => $direction,
        'visibilityRateDelta' => $visibilityRateDelta,
        'naturalVisibilityRateDelta' => $naturalVisibilityRateDelta,
        'averageRankDelta' => $averageRankDelta,
    ];
}

function dashboard_apply_trends(array $summary, array $previousSummary): array {
    if (empty($previousSummary)) {
        return $summary;
    }

    $summary['trend'] = dashboard_metric_trend($summary, $previousSummary);

    foreach ((array) ($summary['byProvider'] ?? []) as $name => $row) {
        $summary['byProvider'][$name]['trend'] = dashboard_metric_trend((array) $row, (array) ($previousSummary['byProvider'][$name] ?? []));
    }

    foreach ((array) ($summary['byKeyword'] ?? []) as $keyword => $row) {
        $summary['byKeyword'][$keyword]['trend'] = dashboard_metric_trend((array) $row, (array) ($previousSummary['byKeyword'][$keyword] ?? []));
    }

    $previousProviderKeyword = [];
    foreach ((array) ($previousSummary['providerKeywordRows'] ?? []) as $row) {
        $key = (string) ($row['provider_name'] ?? '') . '||' . (string) ($row['keyword'] ?? '');
        $previousProviderKeyword[$key] = (array) $row;
    }
    foreach ((array) ($summary['providerKeywordRows'] ?? []) as $index => $row) {
        $key = (string) ($row['provider_name'] ?? '') . '||' . (string) ($row['keyword'] ?? '');
        $summary['providerKeywordRows'][$index]['trend'] = dashboard_metric_trend((array) $row, $previousProviderKeyword[$key] ?? null);
    }

    return $summary;
}

function dashboard_trend_badge(array $trend, string $field = 'visibilityRateDelta'): string {
    if (empty($trend) || ($trend['direction'] ?? 'baseline') === 'baseline') {
        return '<span class="dash-pill dash-muted">基准</span>';
    }

    if ($field === 'averageRankDelta') {
        if (!isset($trend['averageRankDelta']) || $trend['averageRankDelta'] === null) {
            return '<span class="dash-pill dash-muted">排名无基准</span>';
        }
        $delta = (float) $trend['averageRankDelta'];
        if ($delta < 0) {
            return '<span class="dash-pill dash-up">▲ 排名提升 ' . dashboard_h(number_format(abs($delta), 1)) . '</span>';
        }
        if ($delta > 0) {
            return '<span class="dash-pill dash-down">▼ 排名下降 ' . dashboard_h(number_format(abs($delta), 1)) . '</span>';
        }
        return '<span class="dash-pill dash-muted">→ 排名持平</span>';
    }

    $delta = isset($trend[$field]) ? (int) $trend[$field] : 0;
    if ($delta > 0) {
        return '<span class="dash-pill dash-up">▲ +' . $delta . 'pp</span>';
    }
    if ($delta < 0) {
        return '<span class="dash-pill dash-down">▼ ' . $delta . 'pp</span>';
    }
    return '<span class="dash-pill dash-muted">→ 0pp</span>';
}

function dashboard_rank($value): string {
    return $value === null || $value === '' ? '-' : dashboard_h(rtrim(rtrim(number_format((float) $value, 1), '0'), '.'));
}

function dashboard_sparkline(array $values, string $color = '#16c784'): string {
    $values = array_values(array_map(static fn ($value) => max(0, min(100, (float) $value)), $values));
    if (empty($values)) {
        $values = [0, 0];
    } elseif (count($values) === 1) {
        $values[] = $values[0];
    }

    $points = [];
    $count = count($values);
    foreach ($values as $index => $value) {
        $x = $count <= 1 ? 0 : ($index / ($count - 1)) * 300;
        $y = 74 - ($value / 100) * 58;
        $points[] = round($x, 1) . ',' . round($y, 1);
    }

    return '<svg viewBox="0 0 300 82" class="dash-spark" preserveAspectRatio="none">'
        . '<polyline points="' . dashboard_h(implode(' ', $points)) . '" fill="none" stroke="' . dashboard_h($color) . '" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"/>'
        . '<line x1="0" y1="74" x2="300" y2="74" stroke="rgba(148,163,184,.2)" stroke-width="1"/>'
        . '</svg>';
}

function dashboard_source_domains(array $rows): array {
    $domains = [];
    foreach ($rows as $row) {
        foreach (($row['sources'] ?? []) as $source) {
            $text = (string) $source;
            if (!preg_match('/https?:\/\/[^\s)）\]】"\'<>]+/u', $text, $match)) {
                continue;
            }
            $host = strtolower((string) parse_url($match[0], PHP_URL_HOST));
            $host = preg_replace('/^www\./', '', $host) ?: '';
            if ($host === '') {
                continue;
            }
            if (!isset($domains[$host])) {
                $domains[$host] = ['domain' => $host, 'count' => 0, 'visible' => 0];
            }
            $domains[$host]['count']++;
            if (!empty($row['client_mentioned'])) {
                $domains[$host]['visible']++;
            }
        }
    }

    usort($domains, static fn ($a, $b) => $b['count'] <=> $a['count']);
    return array_slice($domains, 0, 8);
}

function dashboard_mindshare(array $rows, string $brandName): array {
    $share = [];
    foreach ($rows as $row) {
        if (!empty($row['client_mentioned'])) {
            $label = $brandName !== '' ? $brandName : '本品牌';
            $share[$label] = ($share[$label] ?? 0) + 1;
        }
        foreach (array_merge((array) ($row['competitor_mentions'] ?? []), (array) ($row['discovered_competitors'] ?? [])) as $name) {
            $name = trim((string) $name);
            if ($name === '') {
                continue;
            }
            $share[$name] = ($share[$name] ?? 0) + 1;
        }
    }

    arsort($share);
    if (empty($share)) {
        return [['name' => '未形成有效提及', 'count' => 1, 'rate' => 100]];
    }

    $top = array_slice($share, 0, 5, true);
    $total = array_sum($top);
    $rowsOut = [];
    foreach ($top as $name => $count) {
        $rowsOut[] = ['name' => $name, 'count' => $count, 'rate' => dashboard_percent((int) $count, (int) $total)];
    }
    return $rowsOut;
}

function dashboard_summary_for_dir(string $dir, string $fallbackProjectKey = '', array $fallbackProject = []): array {
    $summary = dashboard_read_json($dir . '/summary.json');
    $meta = dashboard_read_json($dir . '/run-meta.json');
    $project = array_merge($fallbackProject, (array) ($meta['project'] ?? []));
    $reportKey = dashboard_report_key_from_name(basename($dir));
    $projectKey = (string) ($summary['projectKey'] ?? ($project['projectKey'] ?? ($fallbackProjectKey !== '' ? $fallbackProjectKey : $reportKey)));

    if (is_file($dir . '/results.jsonl') && (
        empty($summary)
        || !isset($summary['sourceCoverageRate'])
        || !isset($summary['firstRecommendRate'])
        || !isset($summary['semanticMatchRate'])
        || !isset($summary['scenarioCoverageRate'])
        || !isset($summary['modelDifferenceRate'])
        || !isset($summary['technicalCapabilityRate'])
        || !isset($summary['proofCapabilityRate'])
        || !isset($summary['cognitionCapabilityRate'])
        || empty($summary['byProvider'] ?? [])
        || empty($summary['providerKeywordRows'] ?? [])
    )) {
        $computed = dashboard_compute_summary(dashboard_read_jsonl($dir . '/results.jsonl'), $projectKey, $project);
        $summary = empty($summary) ? $computed : array_replace_recursive($computed, $summary);
    }
    if (empty($summary)) {
        return [];
    }

    $summary['projectKey'] = (string) ($summary['projectKey'] ?? $projectKey);
    $summary['projectName'] = (string) ($summary['projectName'] ?? ($project['name'] ?? ''));
    $summary['clientName'] = (string) ($summary['clientName'] ?? ($project['clientName'] ?? $reportKey));
    $summary['clientAliases'] = array_values((array) ($summary['clientAliases'] ?? ($project['clientAliases'] ?? [])));
    $summary['competitors'] = array_values((array) ($summary['competitors'] ?? ($project['competitors'] ?? [])));
    return $summary;
}

function dashboard_summary_matches_project(array $summary, string $projectKey, string $dir): bool {
    if ($projectKey === '') {
        return true;
    }

    $candidates = array_filter([
        (string) ($summary['projectKey'] ?? ''),
        (string) ($summary['clientName'] ?? ''),
        dashboard_report_key_from_name(basename($dir)),
    ], static fn ($value) => $value !== '');
    return in_array($projectKey, $candidates, true);
}

function dashboard_previous_summary(string $root, string $currentDir, string $projectKey): array {
    if (!is_dir($root)) {
        return [];
    }

    $currentMtime = filemtime($currentDir) ?: time();
    $dirs = glob($root . '/*', GLOB_ONLYDIR) ?: [];
    usort($dirs, static fn ($a, $b) => (filemtime($b) ?: 0) <=> (filemtime($a) ?: 0));
    foreach ($dirs as $dir) {
        if (realpath($dir) === realpath($currentDir) || (filemtime($dir) ?: 0) >= $currentMtime) {
            continue;
        }
        $summary = dashboard_summary_for_dir($dir, $projectKey);
        if (!empty($summary) && dashboard_summary_matches_project($summary, $projectKey, $dir)) {
            return $summary;
        }
    }

    return [];
}

function dashboard_history(string $root, string $currentDir, string $projectKey): array {
    if (!is_dir($root)) {
        return [];
    }

    $items = [];
    foreach (glob($root . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
        $summary = dashboard_summary_for_dir($dir, $projectKey);
        if (empty($summary) || !dashboard_summary_matches_project($summary, $projectKey, $dir)) {
            continue;
        }
        $items[] = [
            'dir' => $dir,
            'label' => date('m-d H:i', filemtime($dir) ?: time()),
            'visibilityRate' => (int) ($summary['visibilityRate'] ?? 0),
            'naturalVisibilityRate' => (int) ($summary['naturalVisibilityRate'] ?? 0),
            'competitorRate' => (int) ($summary['competitorRate'] ?? 0),
            'sourceCoverageRate' => (int) ($summary['sourceCoverageRate'] ?? 0),
            'firstRecommendRate' => (int) ($summary['firstRecommendRate'] ?? 0),
            'semanticMatchRate' => (int) ($summary['semanticMatchRate'] ?? 0),
            'scenarioCoverageRate' => (int) ($summary['scenarioCoverageRate'] ?? 0),
            'modelDifferenceRate' => (int) ($summary['modelDifferenceRate'] ?? 0),
            'technicalCapabilityRate' => (int) ($summary['technicalCapabilityRate'] ?? 0),
            'proofCapabilityRate' => (int) ($summary['proofCapabilityRate'] ?? 0),
            'cognitionCapabilityRate' => (int) ($summary['cognitionCapabilityRate'] ?? 0),
        ];
    }

    usort($items, static fn ($a, $b) => filemtime($a['dir']) <=> filemtime($b['dir']));
    return array_slice($items, -12);
}

function dashboard_recommendations(array $summary, array $sources): array {
    $recommendations = [];
    if ((int) ($summary['technicalCapabilityRate'] ?? 0) < 50) {
        $recommendations[] = '技术能力偏弱：品牌信息还没有稳定转成 AI 易理解、易引用的内容，需要补结构化问答、对比页和证据页。';
    }
    if ((int) ($summary['proofCapabilityRate'] ?? 0) < 50) {
        $recommendations[] = '效果证明不足：要把“被推荐了多少、引用了多少、采信了多少”做成可追踪的数据。';
    }
    if ((int) ($summary['cognitionCapabilityRate'] ?? 0) < 50) {
        $recommendations[] = '行业认知不足：需要围绕客户业务建立行业知识库，让 AI 更像在引用行业权威。';
    }
    if ((int) ($summary['competitorRate'] ?? 0) > (int) ($summary['visibilityRate'] ?? 0)) {
        $recommendations[] = '竞品排他压力偏高：同品类位置有限，需要按高频竞品补齐差异化对比内容。';
    }
    if ((int) ($summary['sourceCoverageRate'] ?? 0) > 0 && (int) ($summary['sourceCoverageRate'] ?? 0) < 30) {
        $recommendations[] = '引用采信偏低：官网、案例、媒体稿要做成 AI 能直接摘取和引用的基础设施。';
    }
    if ((int) ($summary['modelDifferenceRate'] ?? 0) > 35) {
        $recommendations[] = '模型认知差异较大：不同 AI 对品牌认知不一致，需要统一品牌叙事和证据分发。';
    }
    if (empty($sources)) {
        $recommendations[] = '当前引用来源不足，建议建立官网知识库、媒体稿、案例页和问答页作为 AI 可采信来源。';
    }
    if (empty($recommendations)) {
        $recommendations[] = '当前三项能力表现稳定，下一步可以扩大关键词池并持续观察趋势变化。';
    }
    return $recommendations;
}

$root = realpath(__DIR__ . '/../data/geo-monitor');
$reportName = basename((string) ($_GET['report'] ?? ''));

// 没有指定报告时，自动选最新的
if ($reportName === '' && $root && is_dir($root)) {
    $dirs = glob($root . '/*', GLOB_ONLYDIR);
    if ($dirs) {
        usort($dirs, fn($a,$b) => filemtime($b) - filemtime($a));
        $reportName = basename($dirs[0]);
    }
}

$reportDir = $root && $reportName !== '' ? realpath($root . '/' . $reportName) : false;

if (!$root || !$reportDir || !str_starts_with($reportDir, $root) || !is_dir($reportDir)) {
    http_response_code(404);
    // 显示友好的空状态页而不是裸文字
    echo '<!DOCTYPE html><html lang="zh"><head><meta charset="UTF-8"><title>监测大盘</title>';
    echo '<script src="https://cdn.tailwindcss.com"></script></head>';
    echo '<body class="bg-gray-50 min-h-screen flex items-center justify-center">';
    echo '<div class="text-center">';
    echo '<div class="text-5xl mb-4">📡</div>';
    echo '<h2 class="text-xl font-semibold text-gray-700 mb-2">暂无监测报告</h2>';
    echo '<p class="text-gray-500 text-sm mb-6">请先在 GEO 监测页运行一次检测，生成报告后自动显示。</p>';
    echo '<a href="geo-monitor.php" class="px-4 py-2 bg-indigo-600 text-white rounded hover:bg-indigo-700 text-sm">前往 GEO 监测</a>';
    echo '</div></body></html>';
    exit;
}

$resultsPath = $reportDir . '/results.jsonl';
$rows = dashboard_read_jsonl($resultsPath);
$summary = dashboard_summary_for_dir($reportDir, dashboard_report_key_from_name($reportName));
$projectKey = (string) ($summary['projectKey'] ?? dashboard_report_key_from_name($reportName));
$previousSummary = dashboard_previous_summary($root, $reportDir, $projectKey);
$summary = dashboard_apply_trends($summary, $previousSummary);

$brandName = trim((string) ($summary['clientName'] ?? ''));
if ($brandName === '') {
    $brandName = $projectKey !== '' ? $projectKey : '当前品牌';
}
$projectName = trim((string) ($summary['projectName'] ?? ''));
$history = dashboard_history($root, $reportDir, $projectKey);
$historyVisibility = array_column($history, 'visibilityRate');
$historyFirst = array_column($history, 'firstRecommendRate');
$historyScenario = array_column($history, 'scenarioCoverageRate');
$historyCompetitor = array_column($history, 'competitorRate');
$historySource = array_column($history, 'sourceCoverageRate');
$historySemantic = array_column($history, 'semanticMatchRate');
$historyModelDiff = array_column($history, 'modelDifferenceRate');
$providerRows = array_values((array) ($summary['byProvider'] ?? []));
$matrixRows = array_values((array) ($summary['providerKeywordRows'] ?? []));
usort($matrixRows, static function ($a, $b) {
    return ((int) ($b['visibilityRate'] ?? 0) <=> (int) ($a['visibilityRate'] ?? 0))
        ?: strcmp((string) ($a['provider_name'] ?? ''), (string) ($b['provider_name'] ?? ''));
});
$matrixRows = array_slice($matrixRows, 0, 18);
$sourceDomains = dashboard_source_domains($rows);
$mindshare = dashboard_mindshare($rows, $brandName);
$recommendations = dashboard_recommendations($summary, $sourceDomains);

$donutColors = ['#2563eb', '#22c55e', '#f59e0b', '#ef4444', '#8b5cf6'];
$donutStops = [];
$cursor = 0;
foreach ($mindshare as $index => $item) {
    $rate = max(0, min(100, (int) ($item['rate'] ?? 0)));
    $next = $cursor + $rate;
    $color = $donutColors[$index % count($donutColors)];
    $donutStops[] = $color . ' ' . $cursor . '% ' . $next . '%';
    $cursor = $next;
}
if ($cursor < 100) {
    $donutStops[] = '#475569 ' . $cursor . '% 100%';
}

$page_title = 'GEO 数据大屏';
$downloadReportButton = is_file($reportDir . '/report.html')
    ? '<a href="geo-monitor.php?download=' . urlencode($reportDir . '/report.html') . '" class="inline-flex items-center rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">下载HTML报告</a>'
    : '';
$page_header = '
<div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
    <div>
        <h1 class="text-2xl font-bold text-gray-900">GEO 数据大屏</h1>
        <p class="mt-1 text-sm text-gray-600">按客户/品牌独立生成的 AI 可见性监控视图。</p>
    </div>
    <div class="flex flex-wrap gap-3">
        <a href="geo-monitor.php" class="inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
            返回监测
        </a>
        ' . $downloadReportButton . '
    </div>
</div>';

$additional_css = '
<style>
    .geo-screen { background: #101827; color: #e5edf7; border-radius: 10px; padding: 22px; box-shadow: 0 18px 45px rgba(15, 23, 42, .18); }
    .geo-screen .dash-muted-text { color: #8fa1b8; }
    .dash-grid { display: grid; gap: 16px; }
    .dash-kpis { grid-template-columns: repeat(4, minmax(0, 1fr)); }
    .dash-two { grid-template-columns: minmax(0, 1.1fr) minmax(0, .9fr); }
    .dash-card { background: #1d2a3c; border: 1px solid rgba(148, 163, 184, .22); border-radius: 8px; padding: 18px; }
    .dash-card h2, .dash-card h3 { color: #f8fafc; font-weight: 700; }
    .dash-kpi-value { font-size: 30px; line-height: 1; font-weight: 800; color: #fff; }
    .dash-pill { display: inline-flex; align-items: center; border-radius: 999px; padding: 2px 8px; font-size: 12px; font-weight: 700; white-space: nowrap; }
    .dash-up { background: rgba(16, 185, 129, .16); color: #34d399; }
    .dash-down { background: rgba(239, 68, 68, .16); color: #f87171; }
    .dash-muted { background: rgba(148, 163, 184, .14); color: #cbd5e1; }
    .dash-spark { width: 100%; height: 58px; margin-top: 14px; }
    .dash-bar-track { height: 10px; background: rgba(15, 23, 42, .45); border-radius: 999px; overflow: hidden; }
    .dash-bar { height: 100%; border-radius: 999px; background: linear-gradient(90deg, #14b8a6, #22c55e); }
    .dash-bar-warn { background: linear-gradient(90deg, #f59e0b, #f97316); }
    .dash-table { width: 100%; border-collapse: collapse; font-size: 13px; }
    .dash-table th { color: #94a3b8; font-weight: 600; text-align: left; padding: 10px 8px; border-bottom: 1px solid rgba(148, 163, 184, .18); }
    .dash-table td { padding: 11px 8px; border-bottom: 1px solid rgba(148, 163, 184, .12); color: #dbeafe; vertical-align: top; }
    .dash-heat { min-width: 58px; border-radius: 7px; padding: 5px 8px; text-align: center; font-weight: 800; color: #06141f; background: #22c55e; }
    .dash-donut { width: 190px; height: 190px; border-radius: 50%; position: relative; margin: 0 auto; }
    .dash-donut::after { content: ""; position: absolute; inset: 46px; border-radius: 50%; background: #1d2a3c; border: 1px solid rgba(148, 163, 184, .2); }
    .dash-domain { display: grid; grid-template-columns: 150px 1fr 42px; gap: 10px; align-items: center; }
    @media (max-width: 1100px) { .dash-kpis { grid-template-columns: repeat(2, minmax(0, 1fr)); } .dash-two { grid-template-columns: 1fr; } }
    @media (max-width: 640px) { .geo-screen { padding: 14px; border-radius: 8px; } .dash-kpis { grid-template-columns: 1fr; } .dash-domain { grid-template-columns: 1fr; } }
</style>';

require_once __DIR__ . '/includes/header.php';
?>

<section class="geo-screen">
    <div class="mb-6 flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
        <div>
            <div class="text-sm font-semibold text-emerald-300">品牌监控对象</div>
            <div class="mt-2 text-3xl font-black text-white"><?php echo dashboard_h($brandName); ?></div>
            <div class="mt-2 text-sm dash-muted-text">
                <?php if ($projectName !== ''): ?>项目：<?php echo dashboard_h($projectName); ?> · <?php endif; ?>报告：<?php echo dashboard_h($reportName); ?> · 有效回答 <?php echo (int) ($summary['ok'] ?? 0); ?>/<?php echo (int) ($summary['total'] ?? 0); ?> · 抽样 <?php echo (int) ($summary['sampleCount'] ?? 1); ?> 次
            </div>
        </div>
        <div class="flex flex-wrap gap-2">
            <?php echo dashboard_trend_badge((array) ($summary['trend'] ?? [])); ?>
            <span class="dash-pill dash-muted">信源域名 <?php echo count($sourceDomains); ?> 个</span>
            <span class="dash-pill dash-muted">按本客户数据生成</span>
        </div>
    </div>

    <div class="dash-grid dash-kpis mb-6">
        <div class="dash-card">
            <div class="dash-muted-text text-sm">品牌推荐率</div>
            <div class="mt-4 dash-kpi-value"><?php echo (int) ($summary['visibilityRate'] ?? 0); ?>%</div>
            <?php echo dashboard_sparkline($historyVisibility, '#14b8a6'); ?>
        </div>
        <div class="dash-card">
            <div class="dash-muted-text text-sm">首位推荐率</div>
            <div class="mt-4 dash-kpi-value"><?php echo (int) ($summary['firstRecommendRate'] ?? 0); ?>%</div>
            <?php echo dashboard_sparkline($historyFirst, '#22c55e'); ?>
        </div>
        <div class="dash-card">
            <div class="dash-muted-text text-sm">引用采信率</div>
            <div class="mt-4 dash-kpi-value"><?php echo (int) ($summary['sourceCoverageRate'] ?? 0); ?>%</div>
            <?php echo dashboard_sparkline($historySource, '#38bdf8'); ?>
        </div>
        <div class="dash-card">
            <div class="dash-muted-text text-sm">行业场景覆盖</div>
            <div class="mt-4 dash-kpi-value"><?php echo (int) ($summary['scenarioCoverageRate'] ?? 0); ?>%</div>
            <?php echo dashboard_sparkline($historyScenario, '#34d399'); ?>
        </div>
        <div class="dash-card">
            <div class="dash-muted-text text-sm">竞品排他压力</div>
            <div class="mt-4 dash-kpi-value"><?php echo (int) ($summary['competitorRate'] ?? 0); ?>%</div>
            <?php echo dashboard_sparkline($historyCompetitor, '#f59e0b'); ?>
        </div>
        <div class="dash-card">
            <div class="dash-muted-text text-sm">AI理解度</div>
            <div class="mt-4 dash-kpi-value"><?php echo (int) ($summary['semanticMatchRate'] ?? 0); ?>%</div>
            <?php echo dashboard_sparkline($historySemantic, '#a78bfa'); ?>
        </div>
        <div class="dash-card">
            <div class="dash-muted-text text-sm">模型认知差异</div>
            <div class="mt-4 dash-kpi-value"><?php echo (int) ($summary['modelDifferenceRate'] ?? 0); ?>%</div>
            <?php echo dashboard_sparkline($historyModelDiff, '#f97316'); ?>
        </div>
    </div>

    <div class="dash-grid dash-two mb-6">
        <div class="dash-card">
            <h2 class="text-base">AI 平台表现</h2>
            <div class="mt-4 space-y-4">
                <?php foreach ($providerRows as $provider): ?>
                    <?php $rate = (int) ($provider['visibilityRate'] ?? 0); ?>
                    <div>
                        <div class="mb-2 flex items-center justify-between gap-4 text-sm">
                            <span class="font-semibold text-slate-100"><?php echo dashboard_h($provider['provider_name'] ?? '未知AI'); ?></span>
                            <span class="dash-muted-text">推荐率 <?php echo $rate; ?>% · 首位 <?php echo (int) ($provider['firstRecommendRate'] ?? 0); ?>%</span>
                        </div>
                        <div class="dash-bar-track"><div class="dash-bar" style="width: <?php echo max(0, min(100, $rate)); ?>%"></div></div>
                    </div>
                <?php endforeach; ?>
                <?php if (empty($providerRows)): ?>
                    <div class="dash-muted-text text-sm">暂无平台数据。</div>
                <?php endif; ?>
            </div>
        </div>

        <div class="dash-card">
            <h2 class="text-base">AI 认知份额</h2>
            <div class="mt-5 grid gap-5 md:grid-cols-[220px_1fr] md:items-center">
                <div class="dash-donut" style="background: conic-gradient(<?php echo dashboard_h(implode(', ', $donutStops)); ?>);"></div>
                <div class="space-y-3">
                    <?php foreach ($mindshare as $index => $item): ?>
                        <div class="flex items-center justify-between gap-3 text-sm">
                            <span class="flex min-w-0 items-center gap-2">
                                <span class="h-2.5 w-2.5 rounded-full" style="background: <?php echo dashboard_h($donutColors[$index % count($donutColors)]); ?>"></span>
                                <span class="truncate text-slate-100"><?php echo dashboard_h($item['name']); ?></span>
                            </span>
                            <span class="dash-muted-text"><?php echo (int) $item['rate']; ?>%</span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="dash-grid dash-two mb-6">
        <div class="dash-card">
            <h2 class="text-base">关键词 × AI 采信矩阵</h2>
            <div class="mt-4 overflow-x-auto">
                <table class="dash-table">
                    <thead>
                        <tr>
                            <th>AI</th>
                            <th>关键词</th>
                            <th>推荐</th>
                            <th>AI理解</th>
                            <th>采信</th>
                            <th>趋势变化</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($matrixRows as $row): ?>
                            <?php $rate = (int) ($row['visibilityRate'] ?? 0); ?>
                            <tr>
                                <td><?php echo dashboard_h($row['provider_name'] ?? '未知AI'); ?></td>
                                <td>
                                    <div class="font-medium text-slate-100"><?php echo dashboard_h($row['keyword'] ?? '-'); ?></div>
                                    <div class="mt-1 text-xs dash-muted-text"><?php echo !empty($row['keyword_contains_client']) ? '品牌词' : '泛推荐'; ?> · <?php echo dashboard_h($row['intent'] ?? ''); ?></div>
                                </td>
                                <td><div class="dash-heat" style="background: hsl(<?php echo 20 + (int) ($rate * 1.2); ?> 78% 58%);"><?php echo $rate; ?>%</div></td>
                                <td><?php echo (int) ($row['semanticMatchRate'] ?? 0); ?>%</td>
                                <td><?php echo (int) ($row['sourceCoverageRate'] ?? 0); ?>%</td>
                                <td><?php echo dashboard_trend_badge((array) ($row['trend'] ?? [])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($matrixRows)): ?>
                            <tr><td colspan="6" class="dash-muted-text">暂无关键词矩阵数据。</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="dash-card">
            <h2 class="text-base">引用采信来源</h2>
            <div class="mt-4 space-y-4">
                <?php $maxDomain = max(1, ...array_map(static fn ($item) => (int) $item['count'], $sourceDomains ?: [['count' => 1]])); ?>
                <?php foreach ($sourceDomains as $domain): ?>
                    <?php $width = dashboard_percent((int) $domain['count'], $maxDomain); ?>
                    <div class="dash-domain text-sm">
                        <div class="truncate text-slate-100"><?php echo dashboard_h($domain['domain']); ?></div>
                        <div class="dash-bar-track"><div class="dash-bar dash-bar-warn" style="width: <?php echo $width; ?>%"></div></div>
                        <div class="dash-muted-text"><?php echo (int) $domain['count']; ?></div>
                    </div>
                <?php endforeach; ?>
                <?php if (empty($sourceDomains)): ?>
                    <div class="dash-muted-text text-sm">当前平台没有返回可解析的真实链接。建议加强官网、媒体稿和案例页作为可采信来源，并优先使用支持联网检索/引用的模型做采信监测。</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="dash-card">
        <h2 class="text-base">本轮诊断建议</h2>
        <div class="mt-4 grid gap-3 md:grid-cols-2">
            <?php foreach ($recommendations as $item): ?>
                <div class="rounded-lg border border-slate-600/40 bg-slate-900/30 px-4 py-3 text-sm text-slate-200"><?php echo dashboard_h($item); ?></div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
