<?php
/**
 * GEO 监测定时脚本 v2
 * 用法：php bin/geo-monitor-run.php [--customer=<id>] [--dry-run]
 *
 * 每天跑一次：
 *  1. 对每个活跃客户的监测关键词调用 AI，检查品牌是否被提及
 *  2. 同时检测竞品名称是否出现在同一条 AI 回答中，写入 competitors_found
 *  3. 跑完后计算告警：竞品提及率超过品牌时写入 geo_monitor_alerts 并推飞书
 */

define('FEISHU_TREASURE', true);

$projectRoot = dirname(__DIR__);
chdir($projectRoot);

require_once $projectRoot . '/includes/config.php';
require_once $projectRoot . '/includes/database_admin.php';
require_once $projectRoot . '/includes/citation_simulator_service.php';
require_once $projectRoot . '/includes/geo_monitor_alert_service.php';

set_time_limit(600);

// ── 参数解析 ───────────────────────────────────────────────────────────────
$opts      = getopt('', ['customer:', 'dry-run']);
$filterCid = $opts['customer'] ?? null;
$dryRun    = isset($opts['dry-run']);

function gm_log(string $msg): void {
    echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
}

// ── 客户列表：从数据库读取，硬编码仅作兜底 ────────────────────────────────
// 读取有监测关键词的活跃客户（customer_id 来自 geo_monitor_keywords）
// 品牌名从 geo_brand_facts.fact_key='master_sentence' 或 brand_name 推导，兜底用 customer_id
$customers = [];
try {
    // 取所有有关键词的 customer_id
    $stmtCids = $db->query("SELECT DISTINCT customer_id FROM geo_monitor_keywords WHERE enabled = TRUE");
    $dbCids   = $stmtCids->fetchAll(PDO::FETCH_COLUMN) ?: [];

    // 取品牌名（优先 fact_key='brand_name'，次选 fact_label 含"名称"）
    $stmtNames = $db->prepare("
        SELECT customer_id, fact_value AS brand_name
        FROM geo_brand_facts
        WHERE fact_key = 'brand_name'
    ");
    $stmtNames->execute();
    $brandNameMap = [];
    foreach ($stmtNames->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $brandNameMap[$row['customer_id']] = $row['brand_name'];
    }

    // 取竞品列表（来自 geo_customer_competitors，这是权威来源）
    $stmtComps = $db->query("SELECT customer_id, competitor FROM geo_customer_competitors WHERE enabled = TRUE ORDER BY customer_id, id");
    $compMap   = [];
    foreach ($stmtComps->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $compMap[$row['customer_id']][] = $row['competitor'];
    }

    foreach ($dbCids as $cid) {
        if ($filterCid !== null && $cid !== $filterCid) continue;
        $customers[] = [
            'id'          => $cid,
            'name'        => $brandNameMap[$cid] ?? $cid,
            'competitors' => $compMap[$cid] ?? [],
        ];
    }
} catch (Throwable $e) {
    gm_log('从数据库读取客户列表失败，使用兜底数据：' . $e->getMessage());
}

// 兜底：若数据库无数据，使用静态列表（第一次部署时可能触发）
if (empty($customers)) {
    $fallbackCustomers = [
        ['id' => 'wenyun-ai-reading', 'name' => '湖南文韵爱阅读', 'competitors' => ['心田花开', '楚才教育', '麦田格']],
        ['id' => 'dongluoji-mgeo',    'name' => '董逻辑 MGEO',    'competitors' => ['GEO服务商', 'AI搜索优化', '内容增长工具']],
        ['id' => 'yimaitong-health',  'name' => '医脉通健康项目', 'competitors' => ['丁香医生', '医学界', '健康时报']],
    ];
    foreach ($fallbackCustomers as $fc) {
        if ($filterCid !== null && $fc['id'] !== $filterCid) continue;
        $customers[] = $fc;
    }
    gm_log('使用兜底客户列表（共 ' . count($customers) . ' 个），建议在客户中心录入竞品信息');
}

if (empty($customers)) {
    gm_log('没有需要监测的客户，退出。');
    exit(0);
}

// ── 读取 API 配置 ─────────────────────────────────────────────────────────
$apiConfig = citation_simulator_api_config();
$providers = [];
foreach (['kimi', 'deepseek', 'tongyi', 'wenxin', 'doubao', 'yuanbao'] as $pkey) {
    $pcfg = $apiConfig['providers'][$pkey] ?? null;
    if (!$pcfg || !$pcfg['configured']) continue;
    $opStatus = $pcfg['op_status'] ?? 'unconfigured';
    if ($opStatus === 'disabled') continue;
    $providers[$pkey] = $pcfg;
}

if (empty($providers)) {
    $fallbackAi = function_exists('get_active_ai_config') ? get_active_ai_config() : [];
    if (!empty($fallbackAi['api_key']) && !empty($fallbackAi['api_url']) && !empty($fallbackAi['model_id'])) {
        $providerKey = $fallbackAi['model_id'] ?? 'default_ai_model';
        $providers[$providerKey] = [
            'name' => $fallbackAi['name'] ?? $fallbackAi['model_id'] ?? 'AI模型',
            'api_key' => $fallbackAi['api_key'],
            'api_url' => $fallbackAi['api_url'],
            'model_id' => $fallbackAi['model_id'],
            'configured' => true,
        ];
        gm_log('未配置专用监测提供商，使用模型：' . ($providers[$providerKey]['name'] ?? 'default'));
    } else {
        gm_log('没有可用的 AI 提供商（未配置或已停用），退出。');
        exit(1);
    }
}

gm_log('可用提供商：' . implode(', ', array_keys($providers)));

// ── 今日日期 ──────────────────────────────────────────────────────────────
$today = date('Y-m-d');

// ── 主循环 ────────────────────────────────────────────────────────────────
$totalInserted = 0;

foreach ($customers as $customer) {
    $cid         = $customer['id'];
    $cname       = $customer['name'];
    $competitors = $customer['competitors'] ?? [];

    gm_log("── 客户：{$cname} ({$cid})  竞品：" . (empty($competitors) ? '无' : implode('、', $competitors)));

    // 读该客户的监测关键词（含文章关联信息）
    $stmtKw = $db->prepare("
        SELECT id, keyword, article_id, source_url
        FROM geo_monitor_keywords
        WHERE customer_id = ? AND enabled = TRUE
        ORDER BY id
    ");
    $stmtKw->execute([$cid]);
    $kwRows = $stmtKw->fetchAll(PDO::FETCH_ASSOC);

    // 若没有配置关键词，用品牌名兜底
    if (empty($kwRows)) {
        $kwRows = [['id' => null, 'keyword' => $cname, 'article_id' => null, 'source_url' => '']];
        gm_log("  未配置关键词，使用品牌名兜底：{$cname}");
    }

    foreach ($kwRows as $kwRow) {
        $kw        = $kwRow['keyword'];
        $articleId = $kwRow['article_id'];
        $sourceUrl = $kwRow['source_url'] ?? '';

        // ── 自动提取内容指纹（每30天刷新一次，仅关联了文章时执行）────
        $fingerprints = json_decode((string)($kwRow['source_fingerprints'] ?? '[]'), true);
        if (!is_array($fingerprints)) $fingerprints = [];

        if (!empty($articleId) && !$dryRun) {
            $needExtract = empty($fingerprints);
            if (!$needExtract && !empty($kwRow['fingerprints_extracted_at'])) {
                $extractedAt = strtotime((string)$kwRow['fingerprints_extracted_at']);
                $needExtract = (time() - $extractedAt) > 86400 * 30;
            }
            if ($needExtract) {
                gm_log("  [指纹] 开始提取文章 #{$articleId} 的内容指纹");
                $newFingerprints = gm_extract_fingerprints($db, (int)$articleId, $providers);
                if (!empty($newFingerprints)) {
                    $fingerprints = $newFingerprints;
                    $db->prepare("
                        UPDATE geo_monitor_keywords
                        SET source_fingerprints = ?, fingerprints_extracted_at = CURRENT_TIMESTAMP
                        WHERE id = ?
                    ")->execute([json_encode($newFingerprints, JSON_UNESCAPED_UNICODE), $kwRow['id']]);
                    gm_log("  [指纹] 提取完成：" . implode(' / ', $newFingerprints));
                } else {
                    gm_log("  [指纹] 提取失败或文章内容不足，跳过");
                }
            }
        }

        foreach (array_keys($providers) as $pkey) {
            // 今天已有记录则跳过
            $stmtChk = $db->prepare("
                SELECT id FROM geo_monitor_records
                WHERE customer_id=? AND provider=? AND query_text=? AND queried_at=?
                LIMIT 1
            ");
            $stmtChk->execute([$cid, $pkey, $kw, $today]);
            if ($stmtChk->fetch()) {
                gm_log("  [{$pkey}] \"{$kw}\" 今日已查，跳过");
                continue;
            }

            gm_log("  [{$pkey}] 查询：{$kw}");

            if ($dryRun) {
                gm_log("  [dry-run] 跳过实际 API 调用");
                continue;
            }

            // 调用 AI
            $response = null;
            try {
                $response = geo_monitor_call_provider($pkey, $providers[$pkey], $kw);
            } catch (Throwable $e) {
                gm_log("  [{$pkey}] 调用失败：" . $e->getMessage());
                continue;
            }

            if ($response === null) {
                gm_log("  [{$pkey}] 无响应，跳过");
                continue;
            }

            // ── 品牌分析 ──────────────────────────────────────────────
            $mentioned = mb_stripos($response, $cname) !== false;
            $count     = $mentioned ? substr_count(mb_strtolower($response), mb_strtolower($cname)) : 0;
            $position  = null;
            $snippet   = '';
            if ($mentioned) {
                $pos      = mb_stripos($response, $cname);
                $len      = mb_strlen($response);
                $position = $len > 0 ? (int) ceil($pos / $len * 3) : 1;
                $start    = max(0, $pos - 50);
                $snippet  = mb_substr($response, $start, 200);
            }

            // ── 推荐深度评分 ───────────────────────────────────────────
            [$mentionDepth, $depthDetail] = gm_score_depth($response, $cname);

            // ── 竞品检测（每个竞品名是否出现在同一条回答里）──────────
            $competitorsFound = [];
            foreach ($competitors as $comp) {
                if ($comp !== '' && mb_stripos($response, $comp) !== false) {
                    $compCount = substr_count(mb_strtolower($response), mb_strtolower($comp));
                    $competitorsFound[] = ['name' => $comp, 'count' => $compCount];
                }
            }
            $competitorsJson = json_encode($competitorsFound, JSON_UNESCAPED_UNICODE);

            // ── 信源 URL 检测（仅对支持联网搜索的模型）────────────────
            $sourceUrlCited = false;
            if ($sourceUrl !== '' && mb_stripos($response, $sourceUrl) !== false) {
                $sourceUrlCited = true;
            }

            // ── 内容指纹匹配（不依赖品牌名，独立判断文章内容是否被AI引用）──
            $contentCited      = false;
            $fingerprintHits   = [];
            if (!empty($fingerprints)) {
                foreach ($fingerprints as $fp) {
                    if (mb_strlen((string)$fp, 'UTF-8') >= 4 && mb_stripos($response, $fp) !== false) {
                        $contentCited    = true;
                        $fingerprintHits[] = $fp;
                    }
                }
            }
            $fingerprintHitsJson = json_encode($fingerprintHits, JSON_UNESCAPED_UNICODE);

            // ── 写入数据库 ────────────────────────────────────────────
            $stmt = $db->prepare("
                INSERT INTO geo_monitor_records
                    (customer_id, provider, query_text, brand_mentioned, mention_count,
                     mention_position, response_snippet, full_response, queried_at,
                     article_id, competitors_found, source_url_cited,
                     mention_depth, depth_detail,
                     content_cited, fingerprint_matched)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $cid, $pkey, $kw,
                $mentioned ? 'true' : 'false',
                $count,
                $position,
                $snippet,
                mb_substr($response, 0, 3000),
                $today,
                $articleId,
                $competitorsJson,
                $sourceUrlCited ? 'true' : 'false',
                $mentionDepth,
                json_encode($depthDetail, JSON_UNESCAPED_UNICODE),
                $contentCited ? 'true' : 'false',
                $fingerprintHitsJson,
            ]);

            // 记录刚插入的 ID，供后续准确度校验
            $newRecordId = (int) $db->lastInsertId('geo_monitor_records_id_seq');

            $totalInserted++;
            $depthLabel = ['0:未提及', '1:仅名称', '2:有描述', '3:有参数', '4:有案例'][$mentionDepth] ?? $mentionDepth;
            $tag  = $mentioned ? "✓ 品牌({$count}次) 深度:{$depthLabel}" : "✗ 未提及";
            $ctag = empty($competitorsFound)
                ? ''
                : ' | 竞品：' . implode('、', array_column($competitorsFound, 'name'));
            $ftag = $contentCited
                ? ' | 内容指纹命中：' . implode('、', $fingerprintHits)
                : (!empty($fingerprints) ? ' | 指纹未命中' : '');
            gm_log("  [{$pkey}] {$tag}{$ctag}{$ftag}");

            // ── 语义准确度校验（仅深度≥2时，且品牌有知识库，且7天内未校验过）
            if ($mentionDepth >= 2 && $newRecordId > 0 && !$dryRun) {
                gm_verify_accuracy($db, $cid, $cname, $kw, $pkey, $response, $newRecordId, $providers);
            }

            // 避免 API 限速
            usleep(800000);
        }
    }

    // ── 跑完该客户后，计算竞品超越告警 ──────────────────────────────
    gm_log("  计算告警：{$cname}");
    gm_compute_alerts($db, $cid, $cname, $competitors, $today);
}

gm_log("完成，共写入 {$totalInserted} 条记录。");

// ── 监测数据回填诊断雷达图 ────────────────────────────────────────────────
foreach ($customers as $customer) {
    gm_sync_diagnosis($db, (string) ($customer['id'] ?? ''), (string) ($customer['name'] ?? ''), $today);
}

// ── 飞书推送（汇总所有今日 HIGH 级别告警）────────────────────────────────
// 直接查 DB，不依赖 functions.php（bin 脚本不加载 web 层）
$feishuUrl = '';
try {
    $stmtFsUrl = $db->prepare("SELECT setting_value FROM site_settings WHERE setting_key = 'feishu_monitor_webhook' LIMIT 1");
    $stmtFsUrl->execute();
    $feishuUrl = trim((string)($stmtFsUrl->fetchColumn() ?: ''));
} catch (Throwable $_fsu) {}
if ($feishuUrl !== '') {
    gm_send_feishu_summary($db, $today, $feishuUrl);
} else {
    gm_log('[飞书] 未配置 webhook，跳过推送（在「系统设置 → GEO监测通知」中填写 URL）');
}

// ═══════════════════════════════════════════════════════════════════════════
// 函数区
// ═══════════════════════════════════════════════════════════════════════════

/**
 * 将今日监测结果回填到诊断雷达图的信号分数
 * - ugc_coverage  ← 有几个AI平台提及了品牌（平台覆盖率）
 * - site_identity ← 整体提及率（提及查询数/总查询数）
 */
function gm_sync_diagnosis(PDO $db, string $cid, string $cname, string $today): void {
    // 1. 统计今日监测数据
    try {
        $stmt = $db->prepare("
            SELECT
                COUNT(*)                                             AS total,
                SUM(CASE WHEN brand_mentioned THEN 1 ELSE 0 END)    AS mentioned,
                COUNT(DISTINCT CASE WHEN brand_mentioned THEN provider END) AS platforms_with_mention,
                COUNT(DISTINCT provider)                             AS platforms_total
            FROM geo_monitor_records
            WHERE customer_id = ? AND queried_at = ?
        ");
        $stmt->execute([$cid, $today]);
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        gm_log("[诊断回填] 读取监测数据失败: " . $e->getMessage());
        return;
    }

    $total    = (int)($stats['total'] ?? 0);
    $mentioned = (int)($stats['mentioned'] ?? 0);
    $platformsWithMention = (int)($stats['platforms_with_mention'] ?? 0);
    $platformsTotal = (int)($stats['platforms_total'] ?? 1);

    if ($total === 0) {
        gm_log("[诊断回填] {$cname} 今日无数据，跳过");
        return;
    }

    // 计算两个信号分数（0-100）
    $mentionRate     = round($mentioned / $total * 100, 1);
    $platformCoverage = round($platformsWithMention / $platformsTotal * 100, 1);

    // 2. 找该客户最新的诊断 run_id（通过品牌名匹配）
    try {
        $stmt = $db->prepare("
            SELECT r.id
            FROM geo_diagnosis_runs r
            JOIN geo_diagnosis_brands b ON b.id = r.brand_id
            WHERE b.name = ?
            ORDER BY r.created_at DESC
            LIMIT 1
        ");
        $stmt->execute([$cname]);
        $diagId = $stmt->fetchColumn();
    } catch (Throwable $e) {
        gm_log("[诊断回填] 查询诊断ID失败: " . $e->getMessage());
        return;
    }

    if (!$diagId) {
        gm_log("[诊断回填] {$cname} 没有诊断报告，跳过（请先在诊断页面创建）");
        return;
    }

    // 3. 回填两个信号分数
    $signals = [
        'site_identity' => [
            'score'      => $mentionRate,
            'raw_metric' => json_encode(['mention_rate' => $mentionRate, 'mentioned' => $mentioned, 'total' => $total, 'date' => $today], JSON_UNESCAPED_UNICODE),
            'details'    => json_encode(['source' => 'geo_monitor', 'desc' => "今日{$total}次查询，{$mentioned}次提及品牌"], JSON_UNESCAPED_UNICODE),
        ],
        'ugc_coverage' => [
            'score'      => $platformCoverage,
            'raw_metric' => json_encode(['platform_coverage' => $platformCoverage, 'platforms_with_mention' => $platformsWithMention, 'platforms_total' => $platformsTotal, 'date' => $today], JSON_UNESCAPED_UNICODE),
            'details'    => json_encode(['source' => 'geo_monitor', 'desc' => "{$platformsTotal}个平台中{$platformsWithMention}个提及品牌"], JSON_UNESCAPED_UNICODE),
        ],
    ];

    try {
        $upsert = $db->prepare("
            INSERT INTO geo_diagnosis_signal_scores (diagnosis_id, signal_key, score, weight, raw_metric, details_json)
            VALUES (?, ?, ?, (SELECT default_weight FROM geo_diagnosis_signal_definitions WHERE signal_key = ?), ?::jsonb, ?::jsonb)
            ON CONFLICT (diagnosis_id, signal_key) DO UPDATE SET
                score      = EXCLUDED.score,
                raw_metric = EXCLUDED.raw_metric,
                details_json = EXCLUDED.details_json,
                updated_at = CURRENT_TIMESTAMP
        ");
        foreach ($signals as $key => $data) {
            $upsert->execute([$diagId, $key, $data['score'], $key, $data['raw_metric'], $data['details']]);
        }
        gm_log("[诊断回填] {$cname} 更新完成 — 提及率:{$mentionRate}% 平台覆盖:{$platformCoverage}%");
    } catch (Throwable $e) {
        gm_log("[诊断回填] 写入失败: " . $e->getMessage());
    }
}

/**
 * 从文章内容中自动提取3-5个内容指纹短语
 *
 * 指纹 = 文章独有的表达，不是通用说法，不含品牌名本身。
 * AI在回答中出现这些短语 → 说明它引用了该文章的内容（不论是否提及品牌）。
 *
 * @return string[]  提取到的短语数组，失败时返回空数组
 */
function gm_extract_fingerprints(PDO $db, int $articleId, array $providers): array {
    // 读取文章内容
    try {
        $stmt = $db->prepare("SELECT title, content FROM articles WHERE id = ? AND deleted_at IS NULL LIMIT 1");
        $stmt->execute([$articleId]);
        $article = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        gm_log("  [指纹] 读取文章失败：" . $e->getMessage());
        return [];
    }
    if (empty($article)) {
        gm_log("  [指纹] 文章 #{$articleId} 不存在或已删除");
        return [];
    }

    // 去除 Markdown 标记，取前 2000 字
    $rawContent = strip_tags(preg_replace('/[#*`_\[\]>|]+/', '', $article['content'] ?? ''));
    $rawContent = preg_replace('/\s+/', ' ', $rawContent);
    $excerpt    = mb_substr(trim($rawContent), 0, 2000, 'UTF-8');

    if (mb_strlen($excerpt, 'UTF-8') < 100) {
        gm_log("  [指纹] 文章内容太短（不足100字），跳过提取");
        return [];
    }

    // 选一个可用 Provider 调 AI
    $providerKey = array_key_first($providers);
    if ($providerKey === null) return [];
    $pcfg = $providers[$providerKey];

    $prompt = <<<PROMPT
你是一个内容指纹提取工具。请从以下文章中提取3到5个"内容指纹短语"。

要求：
1. 每个短语长度在6到25个字之间
2. 必须是该文章独有的具体表达，不是通用说法（如"学习""提升""服务"等词不算）
3. 优先选含具体数字、专有名词、独特搭配的句子片段
4. 不要包含品牌名称本身
5. 如果AI在回答用户问题时复述了这些短语，能证明它引用了这篇文章的内容

只返回JSON数组，不要任何其他内容，格式：["短语1","短语2","短语3"]

文章标题：{$article['title']}

文章内容：
{$excerpt}
PROMPT;

    // 调用 AI
    $apiUrl = ai_build_chat_completions_url($pcfg['api_url'] ?? '');
    $payload = json_encode([
        'model'    => $pcfg['model_id'] ?? '',
        'messages' => [['role' => 'user', 'content' => $prompt]],
        'max_tokens' => 300,
        'temperature' => 0.3,
    ]);

    $ch = curl_init($apiUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . ($pcfg['api_key'] ?? ''),
        ],
    ]);
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200 || !$raw) {
        gm_log("  [指纹] AI调用失败 HTTP {$code}");
        return [];
    }

    // 解析响应
    $decoded = json_decode($raw, true);
    $text    = trim((string)($decoded['choices'][0]['message']['content'] ?? ''));

    // 从响应中提取 JSON 数组（容忍AI在数组前后加文字）
    if (!preg_match('/\[.*?\]/s', $text, $m)) {
        gm_log("  [指纹] AI返回格式异常：" . mb_substr($text, 0, 100));
        return [];
    }

    $phrases = json_decode($m[0], true);
    if (!is_array($phrases)) {
        gm_log("  [指纹] JSON解析失败");
        return [];
    }

    // 过滤：只保留6-25字、非空的短语
    $valid = [];
    foreach ($phrases as $p) {
        $p = trim((string)$p);
        $len = mb_strlen($p, 'UTF-8');
        if ($len >= 6 && $len <= 30) {
            $valid[] = $p;
        }
    }

    return array_slice($valid, 0, 5);
}

/**
 * 计算并写入告警：竞品在某关键词上的提及率超过品牌时产生告警
 */
function gm_compute_alerts(PDO $db, string $cid, string $cname, array $competitors, string $today): void {
    $summary = geo_monitor_refresh_alerts($db, $cid, $cname, $competitors, $today);
    gm_log("  [告警汇总] 检查 {$summary['checked_records']} 条记录，写入/更新 {$summary['created_or_updated']} 条告警");
    return;

    if (empty($competitors)) return;

    // 取最近 7 天该客户所有记录
    $stmtR = $db->prepare("
        SELECT query_text, brand_mentioned, competitors_found
        FROM geo_monitor_records
        WHERE customer_id = ?
          AND queried_at >= (CURRENT_DATE - INTERVAL '6 days')
    ");
    $stmtR->execute([$cid]);
    $rows = $stmtR->fetchAll(PDO::FETCH_ASSOC);

    if (empty($rows)) return;

    // 按关键词聚合：品牌命中次数 + 总查询次数
    $kwBrand = [];  // keyword => ['hits' => 0, 'total' => 0]
    $kwComp  = [];  // keyword => [compName => ['hits' => 0, 'total' => 0]]

    foreach ($rows as $row) {
        $kw    = $row['query_text'];
        $brand = (bool) $row['brand_mentioned'];

        if (!isset($kwBrand[$kw])) {
            $kwBrand[$kw] = ['hits' => 0, 'total' => 0];
        }
        $kwBrand[$kw]['total']++;
        if ($brand) $kwBrand[$kw]['hits']++;

        $found = json_decode((string) ($row['competitors_found'] ?? '[]'), true);
        if (!is_array($found)) continue;
        foreach ($found as $cf) {
            $cn = $cf['name'] ?? '';
            if ($cn === '') continue;
            if (!isset($kwComp[$kw][$cn])) {
                $kwComp[$kw][$cn] = ['hits' => 0, 'total' => 0];
            }
            // competitors_found 中出现即算"命中"
            $kwComp[$kw][$cn]['hits']++;
            $kwComp[$kw][$cn]['total'] = $kwBrand[$kw]['total'];
        }
    }

    $stmtAlert = $db->prepare("
        INSERT INTO geo_monitor_alerts
            (customer_id, alert_type, level, keyword, competitor_name,
             brand_rate, competitor_rate, detail, alerted_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON CONFLICT (customer_id, alert_type, keyword, competitor_name, alerted_at)
        DO UPDATE SET
            brand_rate      = EXCLUDED.brand_rate,
            competitor_rate = EXCLUDED.competitor_rate,
            detail          = EXCLUDED.detail
    ");

    // 先收集所有关键词的 brandRate，用于整体 core_rate 计算
    $allBrandRates = [];

    foreach ($kwBrand as $kw => $brandStat) {
        $total     = $brandStat['total'];
        if ($total === 0) continue;
        $brandRate = round($brandStat['hits'] / $total * 100, 1);
        $allBrandRates[] = $brandRate;

        foreach ($competitors as $comp) {
            $compStat = $kwComp[$kw][$comp] ?? null;
            if ($compStat === null) continue;

            $compRate = round($compStat['hits'] / $total * 100, 1);

            if ($compRate > $brandRate) {
                $diff   = round($compRate - $brandRate, 1);
                $level  = $diff >= 20 ? 'high' : ($diff >= 10 ? 'medium' : 'low');
                $detail = "「{$kw}」近7天品牌提及率 {$brandRate}%，竞品「{$comp}」{$compRate}%，超出 {$diff}pp";
                gm_log("  [告警] {$detail}");
                try {
                    $stmtAlert->execute([
                        $cid, 'competitor_surpass', $level,
                        $kw, $comp,
                        $brandRate, $compRate,
                        $detail, $today,
                    ]);
                } catch (Throwable $e) {
                    gm_log("  [告警写入失败] " . $e->getMessage());
                }
            }
        }
    }

    // ── 新增：核心信息呈现率 <80% 告警 ──────────────────────────────────────
    if (!empty($allBrandRates)) {
        $coreRate = round(array_sum($allBrandRates) / count($allBrandRates), 1);
        if ($coreRate < 80) {
            $level  = $coreRate < 50 ? 'high' : ($coreRate < 65 ? 'medium' : 'low');
            $detail = "{$cname} 近7天核心信息呈现率 {$coreRate}%，低于80%达标线（共" . count($allBrandRates) . "个关键词均值）";
            gm_log("  [核心率告警] {$detail}");
            try {
                $stmtAlert->execute([
                    $cid, 'core_rate_low', $level,
                    '', '',
                    $coreRate, 80.0,
                    $detail, $today,
                ]);
            } catch (Throwable $e) {
                gm_log("  [核心率告警写入失败] " . $e->getMessage());
            }
        }
    }
}

/**
 * 飞书 Webhook 推送今日 HIGH 级别告警汇总
 */
function gm_send_feishu_summary(PDO $db, string $today, string $webhookUrl): void {
    $stmt = $db->prepare("
        SELECT a.customer_id, a.keyword, a.competitor_name, a.brand_rate, a.competitor_rate, a.detail
        FROM geo_monitor_alerts a
        WHERE a.alerted_at = ? AND a.level = 'high' AND a.is_read = FALSE
        ORDER BY a.competitor_rate - a.brand_rate DESC
        LIMIT 10
    ");
    $stmt->execute([$today]);
    $alerts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($alerts)) {
        gm_log('[飞书] 今日无 HIGH 级别告警，跳过推送');
        return;
    }

    $lines = ["**GEO监测告警 · {$today}**\n共 " . count($alerts) . " 条竞品超越告警：\n"];
    foreach ($alerts as $a) {
        $lines[] = "▸ 关键词「{$a['keyword']}」竞品「{$a['competitor_name']}」超出 " .
                   round((float)$a['competitor_rate'] - (float)$a['brand_rate'], 1) . "pp（品牌{$a['brand_rate']}% vs 竞品{$a['competitor_rate']}%）";
    }

    $payload = json_encode([
        'msg_type' => 'text',
        'content'  => ['text' => implode("\n", $lines)],
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init($webhookUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    ]);
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code === 200) {
        gm_log('[飞书] 告警推送成功，' . count($alerts) . ' 条');
        $db->prepare("UPDATE geo_monitor_alerts SET is_read = TRUE WHERE alerted_at = ? AND level = 'high'")->execute([$today]);
        // Step6：为每条 HIGH 告警生成修复文章草稿并推送到飞书
        foreach ($alerts as $a) {
            gm_generate_and_push_draft($db, $a, $webhookUrl);
        }
    } else {
        gm_log("[飞书] 推送失败，HTTP {$code}");
    }
}

/**
 * Step6：根据告警信息生成修复文章草稿，推送到飞书
 */
function gm_generate_and_push_draft(PDO $db, array $alert, string $webhookUrl): void {
    $cid     = $alert['customer_id'];
    $keyword = $alert['keyword'];
    $comp    = $alert['competitor_name'];

    // 读取品牌事实（母句 + 核心服务）
    $stmtFacts = $db->prepare("SELECT fact_key, fact_value FROM geo_brand_facts WHERE customer_id = ? AND fact_key IN ('master_sentence','brand_name','core_services') LIMIT 10");
    $stmtFacts->execute([$cid]);
    $facts = [];
    foreach ($stmtFacts->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $facts[$row['fact_key']] = $row['fact_value'];
    }
    $brandName    = $facts['brand_name']    ?? $cid;
    $masterSentence = $facts['master_sentence'] ?? '';
    $coreServices = $facts['core_services'] ?? '';

    // 用激活模型生成文章草稿
    $aiCfg = get_active_ai_config();
    if (empty($aiCfg['api_key'])) {
        gm_log("[Step6] AI API Key 未配置，跳过草稿生成");
        return;
    }

    $prompt = <<<PROMPT
你是一位专业的GEO内容策略师。请为以下品牌撰写一篇GEO优化文章草稿。

品牌信息：
- 品牌名：{$brandName}
- 品牌定位：{$masterSentence}
- 核心服务：{$coreServices}

写作任务：
- 目标关键词：「{$keyword}」
- 背景：竞品「{$comp}」在该关键词上的AI提及率高于我们，需要创作内容让AI优先推荐{$brandName}
- 要求：文章要包含大量可被AI引用的事实、数据、场景描述，用结构化格式（标题、列表）写作
- 字数：800-1200字
- 格式：直接输出文章，包含标题，不要前言和说明

请开始写作：
PROMPT;

    $payload = json_encode([
        'model'    => $aiCfg['model_id'],
        'messages' => [['role' => 'user', 'content' => $prompt]],
        'max_tokens'  => 2000,
        'temperature' => 0.7,
    ], JSON_UNESCAPED_UNICODE);

    $aiApiUrl = rtrim($aiCfg['api_url'], '/');
    if (!str_ends_with($aiApiUrl, '/chat/completions')) {
        $aiApiUrl .= '/chat/completions';
    }
    $ch = curl_init($aiApiUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $aiCfg['api_key'],
        ],
    ]);
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200) {
        gm_log("[Step6] AI 生成失败，HTTP {$code}");
        return;
    }

    $data    = json_decode($raw, true);
    $article = $data['choices'][0]['message']['content'] ?? '';
    if ($article === '') {
        gm_log("[Step6] 文章内容为空，跳过");
        return;
    }

    // 推送草稿到飞书（富文本格式）
    $title   = "📝 修复草稿｜「{$keyword}」竞品超越告警";
    $summary = "竞品「{$comp}」在「{$keyword}」上超越{$brandName}，以下为AI生成的修复文章草稿，请审阅后发布到高权重平台。";

    $cardPayload = json_encode([
        'msg_type' => 'post',
        'content'  => [
            'post' => [
                'zh_cn' => [
                    'title'   => $title,
                    'content' => [
                        [['tag' => 'text', 'text' => $summary]],
                        [['tag' => 'text', 'text' => "
---
" . $article]],
                        [['tag' => 'text', 'text' => "
---
✅ 确认后请发布到：今日头条 / 搜狐号 / 知乎"]],
                    ],
                ],
            ],
        ],
    ], JSON_UNESCAPED_UNICODE);

    $ch2 = curl_init($webhookUrl);
    curl_setopt_array($ch2, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $cardPayload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    ]);
    $r2 = curl_exec($ch2);
    $c2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
    curl_close($ch2);

    if ($c2 === 200) {
        gm_log("[Step6] 草稿已推送到飞书：关键词「{$keyword}」");
    } else {
        gm_log("[Step6] 草稿推送失败，HTTP {$c2}");
    }
}

/**
 * 推荐深度评分
 * 返回 [depth(0-4), detail_array]
 *
 * 0 = 未提及
 * 1 = 仅出现名称（上下文无实质描述）
 * 2 = 有描述（提到品牌做什么、是什么）
 * 3 = 有参数/场景（价格/功能/适用人群/城市）
 * 4 = 有案例/推荐依据（客户案例/口碑/评价/荣誉）
 */
function gm_score_depth(string $response, string $brandName): array {
    if (mb_stripos($response, $brandName) === false) {
        return [0, []];
    }

    $resp = mb_strtolower($response);
    $bn   = mb_strtolower($brandName);

    // 提取品牌名附近 200 字的上下文
    $pos     = mb_stripos($resp, $bn);
    $context = mb_substr($resp, max(0, $pos - 30), 300);

    $detail = [
        'has_description' => false,
        'has_price'       => false,
        'has_feature'     => false,
        'has_scenario'    => false,
        'has_case'        => false,
    ];

    // 描述信号：是/为/属于/提供/专注/专业/擅长/主营
    $descPatterns = ['是', '为', '属于', '提供', '专注', '专业', '擅长', '主营', '主要', '核心'];
    foreach ($descPatterns as $p) {
        if (mb_strpos($context, $p) !== false) { $detail['has_description'] = true; break; }
    }

    // 价格信号
    $pricePatterns = ['元', '万', '价格', '收费', '费用', '价位', '套餐', '报价', '¥', 'rmb'];
    foreach ($pricePatterns as $p) {
        if (mb_strpos($resp, $p) !== false) { $detail['has_price'] = true; break; }
    }

    // 功能/特点信号
    $featurePatterns = ['功能', '特点', '优势', '特色', '优点', '服务', '支持', '包含', '覆盖'];
    foreach ($featurePatterns as $p) {
        if (mb_strpos($context, $p) !== false) { $detail['has_feature'] = true; break; }
    }

    // 场景/人群信号
    $scenarioPatterns = ['适合', '适用', '人群', '场景', '用途', '针对', '面向', '目标', '解决'];
    foreach ($scenarioPatterns as $p) {
        if (mb_strpos($context, $p) !== false) { $detail['has_scenario'] = true; break; }
    }

    // 案例/推荐依据信号
    $casePatterns = ['案例', '客户', '口碑', '评价', '荣誉', '奖项', '合作', '已有', '效果', '推荐'];
    foreach ($casePatterns as $p) {
        if (mb_strpos($context, $p) !== false) { $detail['has_case'] = true; break; }
    }

    // 计算深度
    if ($detail['has_case']) {
        $depth = 4;
    } elseif ($detail['has_price'] || ($detail['has_feature'] && $detail['has_scenario'])) {
        $depth = 3;
    } elseif ($detail['has_description'] || $detail['has_feature'] || $detail['has_scenario']) {
        $depth = 2;
    } else {
        $depth = 1;
    }

    return [$depth, $detail];
}

/**
 * 语义准确度校验：用 AI 对照品牌知识库检查本次回答有无事实错误
 * 仅在该客户有品牌知识库且近 7 天同关键词同平台未校验过时执行
 */
function gm_verify_accuracy(PDO $db, string $cid, string $cname, string $kw,
                             string $pkey, string $response, int $recordId, array $providers): void
{
    // 读取品牌知识库
    try {
        $stmtFacts = $db->prepare("SELECT fact_label, fact_value FROM geo_brand_facts WHERE customer_id = ? ORDER BY sort_order, id");
        $stmtFacts->execute([$cid]);
        $facts = $stmtFacts->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return;
    }
    if (empty($facts)) return;

    // 近 7 天同关键词同平台已校验过，跳过（节省 API）
    try {
        $stmtChk = $db->prepare("
            SELECT id FROM geo_monitor_records
            WHERE customer_id=? AND provider=? AND query_text=?
              AND accuracy_score IS NOT NULL
              AND queried_at >= CURRENT_DATE - INTERVAL '6 days'
            LIMIT 1
        ");
        $stmtChk->execute([$cid, $pkey, $kw]);
        if ($stmtChk->fetch()) {
            gm_log("  [准确度] {$pkey}/{$kw} 近7天已校验，跳过");
            return;
        }
    } catch (Throwable $e) {
        return;
    }

    // 构建校验 prompt
    $factsText = implode("\n", array_map(fn($f) => "- {$f['fact_label']}：{$f['fact_value']}", $facts));
    $verifyPrompt = "你是一个信息核查助手。以下是关于「{$cname}」的品牌标准事实：\n{$factsText}\n\n"
        . "请分析以下 AI 回答中关于「{$cname}」的描述，列出事实错误（如有）。每行一条，格式：错误点|正确内容。如无错误，回答\"无\"。\n\n"
        . "AI 回答：\n" . mb_substr($response, 0, 1500);

    // 用第一个可用的 provider 做校验（优先 deepseek，成本低）
    $verifyPkey = null;
    foreach (['deepseek', 'tongyi', 'kimi', 'wenxin', 'doubao', 'yuanbao'] as $pk) {
        if (isset($providers[$pk])) { $verifyPkey = $pk; break; }
    }
    if ($verifyPkey === null) return;

    try {
        // 直接用简单 chat 不注入搜索上下文
        $verifyResp = gm_call_provider_raw($verifyPkey, $providers[$verifyPkey], $verifyPrompt);
    } catch (Throwable $e) {
        gm_log("  [准确度] 校验 API 调用失败：" . $e->getMessage());
        return;
    }
    if ($verifyResp === null) return;

    $verifyText = trim($verifyResp);
    $issues = [];
    if ($verifyText !== '无' && $verifyText !== '') {
        foreach (explode("\n", $verifyText) as $line) {
            $line = trim($line);
            if ($line !== '' && $line !== '无') $issues[] = $line;
        }
    }

    $issueCount   = count($issues);
    $accuracyScore = max(0, 100 - $issueCount * 15);  // 每个错误扣 15 分

    try {
        $db->prepare("UPDATE geo_monitor_records SET accuracy_score=?, accuracy_issues=? WHERE id=?")
           ->execute([$accuracyScore, json_encode($issues, JSON_UNESCAPED_UNICODE), $recordId]);
    } catch (Throwable $e) {
        return;
    }

    $tag = $issueCount === 0 ? "✓ 准确(100分)" : "⚠ 发现{$issueCount}处偏差({$accuracyScore}分)";
    gm_log("  [准确度] {$tag}");

    // ── 新增：语义准确度 <98% 告警 ──────────────────────────────────────────
    if ($accuracyScore < 98 && $issueCount > 0) {
        $level  = $accuracyScore < 70 ? 'high' : ($accuracyScore < 85 ? 'medium' : 'low');
        $detail = "{$cname} 在「{$kw}」/{$pkey} 上语义准确度 {$accuracyScore}分，发现{$issueCount}处偏差：" . implode('；', array_slice($issues, 0, 3));
        try {
            $stmtAccAlert = $db->prepare("
                INSERT INTO geo_monitor_alerts
                    (customer_id, alert_type, level, keyword, competitor_name,
                     brand_rate, competitor_rate, detail, alerted_at)
                VALUES (?, 'accuracy_low', ?, ?, ?, ?, 98.0, ?, ?)
                ON CONFLICT (customer_id, alert_type, keyword, competitor_name, alerted_at)
                DO UPDATE SET brand_rate=EXCLUDED.brand_rate, detail=EXCLUDED.detail
            ");
            $stmtAccAlert->execute([$cid, $level, $kw, $pkey, $accuracyScore, $detail, $today]);
            gm_log("  [准确度告警] 已写入 level={$level}");
        } catch (Throwable $e) {
            gm_log("  [准确度告警写入失败] " . $e->getMessage());
        }
    }

    usleep(500000);
}

/**
 * 不注入搜索上下文的裸 AI 调用（用于内部校验）
 */
function gm_call_provider_raw(string $pkey, array $pcfg, string $prompt): ?string {
    if (!empty($pcfg['api_key']) && !empty($pcfg['api_url'])) {
        return gm_call_openai_compatible($pcfg, [
            ['role' => 'user', 'content' => $prompt],
        ], 400, 0.1);
    }

    $fields = $pcfg['fields'] ?? [];
    $apiKey = '';
    foreach ($fields as $fkey => $fcfg) {
        if (str_contains($fkey, 'key') || str_contains($fkey, 'Key')) {
            $apiKey = decrypt_ai_api_key($fcfg['raw'] ?? '');
            if ($apiKey !== '') break;
        }
    }
    if ($apiKey === '') return null;

    $endpointMap = [
        'kimi'     => ['url' => 'https://api.moonshot.cn/v1/chat/completions',                   'model' => 'moonshot-v1-8k'],
        'deepseek' => ['url' => 'https://api.deepseek.com/chat/completions',                      'model' => 'deepseek-chat'],
        'tongyi'   => ['url' => 'https://dashscope.aliyuncs.com/compatible-mode/v1/chat/completions', 'model' => 'qwen-plus'],
        'wenxin'   => ['url' => 'https://qianfan.baidubce.com/v2/chat/completions',               'model' => 'ernie-speed-128k'],
        'doubao'   => ['url' => 'https://ark.cn-beijing.volces.com/api/v3/chat/completions',      'model' => 'ep-m-20260406200950-6jq5k'],
        'yuanbao'  => ['url' => 'https://api.hunyuan.cloud.tencent.com/v1/chat/completions',      'model' => 'hunyuan-turbo'],
    ];
    $ep = $endpointMap[$pkey] ?? null;
    if (!$ep) return null;

    $payload = json_encode([
        'model'       => $ep['model'],
        'messages'    => [['role' => 'user', 'content' => $prompt]],
        'max_tokens'  => 400,
        'temperature' => 0.1,
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init($ep['url']);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey],
    ]);
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($raw === false || $code !== 200) return null;
    $data = json_decode($raw, true);
    return $data['choices'][0]['message']['content'] ?? null;
}

/**
 * 调用 AI 提供商（含 Bocha 搜索上下文注入）
 */
function geo_monitor_call_provider(string $pkey, array $pcfg, string $query): ?string {
    $searchCtx    = citation_simulator_search_context($query);
    $systemPrompt = "你是一个中文AI助手。请基于以下实时网络搜索结果回答用户问题。\n\n" . $searchCtx;

    if (!empty($pcfg['api_key']) && !empty($pcfg['api_url'])) {
        return gm_call_openai_compatible($pcfg, [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user',   'content' => $query],
        ], 800, 0.3);
    }

    $rawKey = citation_simulator_get_provider_key($pkey, 'api_key');
    if ($rawKey === '') return null;

    // 文心用 BCE IAM 签名认证
    $wenxinBceHeaders = null;
    if ($pkey === 'wenxin' && str_contains($rawKey, ':')) {
        [$bceAk, $bceSk] = explode(':', $rawKey, 2);
        $bceHost      = 'qianfan.baidubce.com';
        $bcePath      = '/v2/chat/completions';
        $bceTimestamp = gmdate('Y-m-d\TH:i:s\Z');
        $bceExpiry    = 1800;
        $bcePrefix    = "bce-auth-v1/{$bceAk}/{$bceTimestamp}/{$bceExpiry}";
        $bceSignKey   = hash_hmac('sha256', $bcePrefix, $bceSk);
        $bceCanonHdr  = "content-type:application/json\nhost:{$bceHost}\nx-bce-date:{$bceTimestamp}";
        $bceSignedHdr = 'content-type;host;x-bce-date';
        $bceCanonReq  = "POST\n{$bcePath}\n\n{$bceCanonHdr}";
        $bceSig       = hash_hmac('sha256', $bceCanonReq, $bceSignKey);
        $bceAuth      = "{$bcePrefix}/{$bceSignedHdr}/{$bceSig}";
        $wenxinBceHeaders = [
            'Content-Type: application/json',
            "Authorization: {$bceAuth}",
            "x-bce-date: {$bceTimestamp}",
            "Host: {$bceHost}",
        ];
        $apiKey = '';
    } else {
        $apiKey = $rawKey;
    }

    $endpointMap = [
        'kimi'     => ['url' => 'https://api.moonshot.cn/v1/chat/completions',                   'model' => 'moonshot-v1-8k'],
        'deepseek' => ['url' => 'https://api.deepseek.com/chat/completions',                      'model' => 'deepseek-chat'],
        'tongyi'   => ['url' => 'https://dashscope.aliyuncs.com/compatible-mode/v1/chat/completions', 'model' => 'qwen-plus'],
        'wenxin'   => ['url' => 'https://qianfan.baidubce.com/v2/chat/completions',               'model' => 'ernie-speed-128k'],
        'doubao'   => ['url' => 'https://ark.cn-beijing.volces.com/api/v3/chat/completions',      'model' => 'ep-m-20260406200950-6jq5k'],
        'yuanbao'  => ['url' => 'https://api.hunyuan.cloud.tencent.com/v1/chat/completions',      'model' => 'hunyuan-turbo'],
    ];

    $ep = $endpointMap[$pkey] ?? null;
    if (!$ep) return null;

    $payload = json_encode([
        'model'    => $ep['model'],
        'messages' => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user',   'content' => $query],
        ],
        'max_tokens'  => 800,
        'temperature' => 0.3,
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init($ep['url']);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => $wenxinBceHeaders ?? [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
    ]);
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false || $code !== 200) return null;

    $data = json_decode($raw, true);
    return $data['choices'][0]['message']['content'] ?? null;
}

function gm_call_openai_compatible(array $pcfg, array $messages, int $maxTokens = 800, float $temperature = 0.3): ?string {
    $apiKey = trim((string) ($pcfg['api_key'] ?? ''));
    $apiUrl = ai_build_chat_completions_url((string) ($pcfg['api_url'] ?? ''));
    $modelId = trim((string) ($pcfg['model_id'] ?? ''));
    if ($apiKey === '' || $apiUrl === '' || $modelId === '') {
        return null;
    }

    $payload = json_encode([
        'model' => $modelId,
        'messages' => $messages,
        'max_tokens' => $maxTokens,
        'temperature' => $temperature,
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init($apiUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
    ]);
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false || $code !== 200) {
        gm_log("  [{$pkey}] 调用失败 HTTP {$code}");
        return null;
    }

    $data = json_decode($raw, true);
    return $data['choices'][0]['message']['content'] ?? null;
}
