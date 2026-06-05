<?php
/**
 * GEO 雷达诊断服务
 */

if (!defined('FEISHU_TREASURE')) {
    die('Access denied');
}

function geo_diagnosis_ensure_schema(PDO $db): void {
    $db->exec("CREATE EXTENSION IF NOT EXISTS pgcrypto");
    $db->exec("
        CREATE TABLE IF NOT EXISTS geo_diagnosis_brands (
            id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
            name VARCHAR(200) NOT NULL,
            domain VARCHAR(255) DEFAULT '',
            industry VARCHAR(64) DEFAULT '',
            metadata_json JSONB NOT NULL DEFAULT '{}'::jsonb,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (name, domain)
        )
    ");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_geo_diag_brands_domain ON geo_diagnosis_brands(domain)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_geo_diag_brands_industry ON geo_diagnosis_brands(industry)");

    $db->exec("
        CREATE TABLE IF NOT EXISTS geo_diagnosis_runs (
            id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
            brand_id UUID NOT NULL REFERENCES geo_diagnosis_brands(id) ON DELETE RESTRICT,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            overall_score NUMERIC(5,2),
            predicted_hit_rate VARCHAR(32),
            industry_benchmark NUMERIC(5,2),
            raw_signals_json JSONB NOT NULL DEFAULT '{}'::jsonb,
            ai_analysis_json JSONB NOT NULL DEFAULT '{}'::jsonb,
            ai_analysis_model VARCHAR(200) DEFAULT '',
            ai_analyzed_at TIMESTAMP DEFAULT NULL,
            error_message TEXT,
            requester_ip INET,
            requester_email VARCHAR(200) DEFAULT '',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            completed_at TIMESTAMP DEFAULT NULL
        )
    ");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_geo_diag_runs_brand ON geo_diagnosis_runs(brand_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_geo_diag_runs_status ON geo_diagnosis_runs(status)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_geo_diag_runs_created ON geo_diagnosis_runs(created_at DESC)");
    if (!db_column_exists($db, 'geo_diagnosis_runs', 'ai_analysis_json')) {
        $db->exec("ALTER TABLE geo_diagnosis_runs ADD COLUMN ai_analysis_json JSONB NOT NULL DEFAULT '{}'::jsonb");
    }
    if (!db_column_exists($db, 'geo_diagnosis_runs', 'ai_analysis_model')) {
        $db->exec("ALTER TABLE geo_diagnosis_runs ADD COLUMN ai_analysis_model VARCHAR(200) DEFAULT ''");
    }
    if (!db_column_exists($db, 'geo_diagnosis_runs', 'ai_analyzed_at')) {
        $db->exec("ALTER TABLE geo_diagnosis_runs ADD COLUMN ai_analyzed_at TIMESTAMP DEFAULT NULL");
    }

    $db->exec("
        CREATE TABLE IF NOT EXISTS geo_diagnosis_signal_definitions (
            signal_key VARCHAR(64) PRIMARY KEY,
            name VARCHAR(64) NOT NULL,
            default_weight NUMERIC(4,2) NOT NULL,
            description TEXT NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS geo_diagnosis_signal_scores (
            id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
            diagnosis_id UUID NOT NULL REFERENCES geo_diagnosis_runs(id) ON DELETE CASCADE,
            signal_key VARCHAR(64) NOT NULL,
            score NUMERIC(5,2) NOT NULL,
            weight NUMERIC(4,2) NOT NULL,
            raw_metric JSONB,
            details_json JSONB,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (diagnosis_id, signal_key)
        )
    ");
    if (!db_column_exists($db, 'geo_diagnosis_signal_scores', 'updated_at')) {
        $db->exec("ALTER TABLE geo_diagnosis_signal_scores ADD COLUMN updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP");
    }
    $db->exec("CREATE INDEX IF NOT EXISTS idx_geo_diag_scores_run ON geo_diagnosis_signal_scores(diagnosis_id)");

    $db->exec("
        CREATE TABLE IF NOT EXISTS geo_diagnosis_actions (
            id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
            diagnosis_id UUID NOT NULL REFERENCES geo_diagnosis_runs(id) ON DELETE CASCADE,
            signal_key VARCHAR(64) NOT NULL,
            priority INTEGER NOT NULL,
            action_text TEXT NOT NULL,
            estimated_impact NUMERIC(5,2),
            sku_id VARCHAR(64) DEFAULT '',
            details_json JSONB NOT NULL DEFAULT '{}'::jsonb,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ");
    if (!db_column_exists($db, 'geo_diagnosis_actions', 'details_json')) {
        $db->exec("ALTER TABLE geo_diagnosis_actions ADD COLUMN details_json JSONB NOT NULL DEFAULT '{}'::jsonb");
    }
    $db->exec("CREATE INDEX IF NOT EXISTS idx_geo_diag_actions_run ON geo_diagnosis_actions(diagnosis_id)");

    $db->exec("
        CREATE TABLE IF NOT EXISTS geo_diagnosis_domain_authority (
            domain VARCHAR(255) PRIMARY KEY,
            tier VARCHAR(4) NOT NULL,
            weight NUMERIC(3,2) NOT NULL,
            note TEXT,
            is_suffix_match BOOLEAN NOT NULL DEFAULT FALSE,
            source VARCHAR(32) NOT NULL DEFAULT 'seed',
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_geo_diag_domain_tier ON geo_diagnosis_domain_authority(tier)");

    $db->exec("
        CREATE TABLE IF NOT EXISTS geo_diagnosis_industry_benchmarks (
            id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
            industry VARCHAR(64) NOT NULL,
            benchmark_type VARCHAR(16) NOT NULL,
            signal_key VARCHAR(64) NOT NULL,
            score NUMERIC(5,2) NOT NULL,
            sample_size INTEGER NOT NULL DEFAULT 0,
            effective_month DATE NOT NULL DEFAULT DATE_TRUNC('month', CURRENT_DATE),
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (industry, benchmark_type, signal_key, effective_month)
        )
    ");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_geo_diag_bench_lookup ON geo_diagnosis_industry_benchmarks(industry, benchmark_type, effective_month)");

    geo_diagnosis_seed_definitions($db);
}

function geo_diagnosis_seed_definitions(PDO $db): void {
    $signals = geo_diagnosis_signal_catalog();
    $stmt = $db->prepare("
        INSERT INTO geo_diagnosis_signal_definitions (signal_key, name, default_weight, description)
        VALUES (?, ?, ?, ?)
        ON CONFLICT (signal_key) DO UPDATE SET
            name = EXCLUDED.name,
            default_weight = EXCLUDED.default_weight,
            description = EXCLUDED.description
    ");
    foreach ($signals as $signal) {
        $stmt->execute([$signal['key'], $signal['name'], $signal['weight'], $signal['description']]);
    }

    $tiers = ['T1' => 1.0, 'T2' => 0.8, 'T3' => 0.6, 'T4' => 0.4, 'T5' => 0.2];
    $domains = [
        ['gov.cn', 'T1', '中国政府门户域名后缀', true],
        ['edu.cn', 'T1', '中国教育机构域名后缀', true],
        ['xinhuanet.com', 'T1', '新华网', false],
        ['people.com.cn', 'T1', '人民网', false],
        ['nature.com', 'T1', 'Nature', false],
        ['science.org', 'T1', 'Science', false],
        ['caixin.com', 'T2', '财新', false],
        ['thepaper.cn', 'T2', '澎湃新闻', false],
        ['36kr.com', 'T2', '36氪', false],
        ['huxiu.com', 'T2', '虎嗅', false],
        ['yicai.com', 'T2', '第一财经', false],
        ['zhihu.com', 'T3', '知乎', false],
        ['sspai.com', 'T3', '少数派', false],
        ['csdn.net', 'T3', 'CSDN', false],
        ['juejin.cn', 'T3', '掘金', false],
        ['infoq.cn', 'T3', 'InfoQ 中文站', false],
        ['bilibili.com', 'T3', 'B 站', false],
        ['xiaohongshu.com', 'T3', '小红书', false],
        ['github.com', 'T3', 'GitHub', false],
        ['stackoverflow.com', 'T3', 'Stack Overflow', false],
        ['jianshu.com', 'T4', '简书', false],
        ['cnblogs.com', 'T4', '博客园', false],
        ['segmentfault.com', 'T4', '思否', false],
        ['v2ex.com', 'T4', 'V2EX', false],
        ['douyin.com', 'T4', '抖音', false],
        ['toutiao.com', 'T4', '今日头条', false],
    ];
    $domainStmt = $db->prepare("
        INSERT INTO geo_diagnosis_domain_authority (domain, tier, weight, note, is_suffix_match, source)
        VALUES (?, ?, ?, ?, ?, 'seed')
        ON CONFLICT (domain) DO NOTHING
    ");
    foreach ($domains as $item) {
        [$domain, $tier, $note, $suffix] = $item;
        $domainStmt->execute([$domain, $tier, $tiers[$tier], $note, $suffix ? 1 : 0]);
    }

    $benchmarks = [
        'GEO服务商' => 74,
        'AI营销服务' => 72,
        'B2B专业服务' => 70,
        '企业服务' => 70,
        'B2B SaaS' => 72,
        '消费品' => 68,
        '餐饮 / 新茶饮' => 66,
        '教育' => 70,
        '医疗' => 76,
        '金融' => 78,
        '本地生活' => 62,
    ];
    $benchStmt = $db->prepare("
        INSERT INTO geo_diagnosis_industry_benchmarks (industry, benchmark_type, signal_key, score, sample_size)
        VALUES (?, 'top_25', ?, ?, 25)
        ON CONFLICT (industry, benchmark_type, signal_key, effective_month) DO NOTHING
    ");
    foreach ($benchmarks as $industry => $baseScore) {
        foreach ($signals as $index => $signal) {
            $benchStmt->execute([$industry, $signal['key'], max(45, min(92, $baseScore + (($index % 3) - 1) * 4))]);
        }
    }
}

function geo_diagnosis_signal_catalog(): array {
    return [
        ['key' => 'third_party_mention', 'name' => '第三方提及', 'weight' => 0.25, 'description' => '知乎、媒体、Reddit 等独立来源对品牌的提及量与质量'],
        ['key' => 'fact_density', 'name' => '事实密度', 'weight' => 0.20, 'description' => '每百词出现的数据点、引用、统计数字密度'],
        ['key' => 'structure', 'name' => '结构化程度', 'weight' => 0.15, 'description' => 'H 标签、列表、表格、FAQ 等结构化标记占比'],
        ['key' => 'authoritative_links', 'name' => '权威外链', 'weight' => 0.15, 'description' => '出站链接到 .gov / .edu / 主流媒体的比例'],
        ['key' => 'ugc_coverage', 'name' => 'UGC 平台覆盖', 'weight' => 0.15, 'description' => '在知乎、小红书、B 站、即刻、公众号等 UGC 平台的内容铺设'],
        ['key' => 'site_identity', 'name' => '站点身份', 'weight' => 0.10, 'description' => 'About 页、作者署名、编辑政策、联系方式完整度'],
    ];
}

function geo_diagnosis_industries(): array {
    return ['GEO服务商', 'AI营销服务', 'B2B专业服务', '企业服务', 'B2B SaaS', '消费品', '餐饮 / 新茶饮', '教育', '医疗', '金融', '本地生活'];
}

function geo_diagnosis_data_source_config(): array {
    $provider = (string) get_setting('geo_diagnosis_search_provider', 'disabled');
    $allowedProviders = ['disabled', 'bing', 'serpapi', 'google_cse', 'bocha'];
    if (!in_array($provider, $allowedProviders, true)) {
        $provider = 'disabled';
    }

    $apiKeyStored = (string) get_setting('geo_diagnosis_search_api_key', '');
    $apiKey = $apiKeyStored !== '' ? decrypt_ai_api_key($apiKeyStored) : '';

    return [
        'provider' => $provider,
        'api_key' => $apiKey,
        'api_key_configured' => $apiKey !== '',
        'google_cse_id' => (string) get_setting('geo_diagnosis_google_cse_id', ''),
        'result_limit' => max(5, min(50, (int) get_setting('geo_diagnosis_result_limit', '10'))),
        'timeout_seconds' => max(3, min(60, (int) get_setting('geo_diagnosis_timeout_seconds', '15'))),
        'enable_site_crawl' => get_setting('geo_diagnosis_enable_site_crawl', '1') === '1',
    ];
}

function geo_diagnosis_save_data_source_config(array $input): bool {
    $provider = trim((string) ($input['provider'] ?? 'disabled'));
    $allowedProviders = ['disabled', 'bing', 'serpapi', 'google_cse', 'bocha'];
    if (!in_array($provider, $allowedProviders, true)) {
        $provider = 'disabled';
    }

    $settings = [
        'geo_diagnosis_search_provider' => $provider,
        'geo_diagnosis_google_cse_id' => trim((string) ($input['google_cse_id'] ?? '')),
        'geo_diagnosis_result_limit' => (string) max(5, min(50, (int) ($input['result_limit'] ?? 10))),
        'geo_diagnosis_timeout_seconds' => (string) max(3, min(60, (int) ($input['timeout_seconds'] ?? 15))),
        'geo_diagnosis_enable_site_crawl' => !empty($input['enable_site_crawl']) ? '1' : '0',
    ];

    foreach ($settings as $key => $value) {
        if (!set_setting($key, $value)) {
            return false;
        }
    }

    if (!empty($input['clear_api_key'])) {
        return set_setting('geo_diagnosis_search_api_key', '');
    }

    $apiKey = trim((string) ($input['api_key'] ?? ''));
    if ($apiKey !== '') {
        return set_setting('geo_diagnosis_search_api_key', encrypt_ai_api_key($apiKey));
    }

    return true;
}

function geo_diagnosis_normalize_domain(string $domain): string {
    $domain = trim(strtolower($domain));
    $domain = preg_replace('#^https?://#', '', $domain);
    $domain = preg_replace('#/.*$#', '', (string) $domain);
    $domain = preg_replace('#^www\.#', '', (string) $domain);
    return trim((string) $domain);
}

function geo_diagnosis_domain_authority(PDO $db, string $domain): array {
    $domain = geo_diagnosis_normalize_domain($domain);
    if ($domain === '') {
        return ['tier' => 'T5', 'weight' => 0.2, 'note' => '未提供官网域名'];
    }

    $stmt = $db->prepare("
        SELECT domain, tier, weight, note, is_suffix_match
        FROM geo_diagnosis_domain_authority
        WHERE domain = ?
           OR (is_suffix_match = TRUE AND ? LIKE '%' || domain)
        ORDER BY is_suffix_match ASC, weight DESC
        LIMIT 1
    ");
    $stmt->execute([$domain, $domain]);
    $row = $stmt->fetch();
    return $row ?: ['tier' => 'T5', 'weight' => 0.2, 'note' => '未进入权威域名种子库'];
}

function geo_diagnosis_create(PDO $db, array $input): string {
    geo_diagnosis_ensure_schema($db);

    $brandName = trim((string) ($input['brand_name'] ?? ''));
    $domain = geo_diagnosis_normalize_domain((string) ($input['domain'] ?? ''));
    $industry = trim((string) ($input['industry'] ?? ''));
    $email = trim((string) ($input['email'] ?? ''));
    $evidence = trim((string) ($input['evidence'] ?? ''));

    if ($brandName === '' && $domain === '') {
        throw new InvalidArgumentException('品牌名称或官网域名至少填写一项');
    }
    if ($brandName === '') {
        $brandName = $domain;
    }
    if ($domain !== '' && !preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/i', $domain)) {
        throw new InvalidArgumentException('官网域名格式不正确');
    }
    if (!in_array($industry, geo_diagnosis_industries(), true)) {
        $industry = 'B2B SaaS';
    }

    $db->beginTransaction();
    try {
        $brandStmt = $db->prepare("
            INSERT INTO geo_diagnosis_brands (name, domain, industry, metadata_json)
            VALUES (?, ?, ?, ?::jsonb)
            ON CONFLICT (name, domain) DO UPDATE SET
                industry = EXCLUDED.industry,
                updated_at = CURRENT_TIMESTAMP
            RETURNING id
        ");
        $brandStmt->execute([
            $brandName,
            $domain,
            $industry,
            json_encode(['evidence' => $evidence], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        $brandId = (string) $brandStmt->fetchColumn();

        $scores = geo_diagnosis_calculate_scores($db, $brandName, $domain, $industry, $evidence);
        // 如果有关联客户，用真实监测数据覆盖估算分数
        $customerId = trim((string) ($input['customer_id'] ?? ''));
        if ($customerId !== '') {
            $monitorData = geo_diagnosis_monitor_data($db, $customerId, $brandName);
            if (!empty($monitorData)) {
                $scores = geo_diagnosis_apply_monitor_data($scores, $monitorData);
            }
        }
        $overall = geo_diagnosis_overall_score($scores);
        $benchmark = geo_diagnosis_industry_benchmark($db, $industry);
        $hitRate = geo_diagnosis_hit_rate($overall);

        $runStmt = $db->prepare("
            INSERT INTO geo_diagnosis_runs (
                brand_id, status, overall_score, predicted_hit_rate, industry_benchmark,
                raw_signals_json, requester_ip, requester_email, completed_at
            )
            VALUES (?, 'completed', ?, ?, ?, ?::jsonb, ?::inet, ?, CURRENT_TIMESTAMP)
            RETURNING id
        ");
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $runStmt->execute([
            $brandId,
            $overall,
            $hitRate,
            $benchmark,
            json_encode($scores, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $ip ?: null,
            $email,
        ]);
        $diagnosisId = (string) $runStmt->fetchColumn();

        $scoreStmt = $db->prepare("
            INSERT INTO geo_diagnosis_signal_scores (diagnosis_id, signal_key, score, weight, raw_metric, details_json)
            VALUES (?, ?, ?, ?, ?::jsonb, ?::jsonb)
            ON CONFLICT (diagnosis_id, signal_key) DO UPDATE SET
                score = EXCLUDED.score,
                weight = EXCLUDED.weight,
                raw_metric = EXCLUDED.raw_metric,
                details_json = EXCLUDED.details_json
        ");
        foreach ($scores as $score) {
            $scoreStmt->execute([
                $diagnosisId,
                $score['key'],
                $score['score'],
                $score['weight'],
                json_encode($score['raw_metric'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                json_encode($score['details'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        }

        $actionStmt = $db->prepare("
            INSERT INTO geo_diagnosis_actions (diagnosis_id, signal_key, priority, action_text, estimated_impact, sku_id)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        foreach (geo_diagnosis_actions_for_scores($scores) as $action) {
            $actionStmt->execute([
                $diagnosisId,
                $action['signal_key'],
                $action['priority'],
                $action['action_text'],
                $action['estimated_impact'],
                $action['sku_id'],
            ]);
        }

        $db->commit();
        return $diagnosisId;
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

function geo_diagnosis_calculate_scores(PDO $db, string $brand, string $domain, string $industry, string $evidence): array {
    // 启用搜索 API 后必须使用真实搜索结果；只有显式 disabled 才走本地估算。
    $cfg = geo_diagnosis_data_source_config();
    $sourceMode = 'estimated';
    if (($cfg['provider'] ?? 'disabled') !== 'disabled') {
        if (($cfg['api_key'] ?? '') === '') {
            throw new RuntimeException('已启用搜索数据源，但 API Key 为空；请先保存有效 Key 后再重新诊断。');
        }
        $realScores = geo_diagnosis_real_calculate_scores($brand, $domain, $industry, $evidence, $cfg);
        if ($realScores !== null) {
            return $realScores;
        }
        throw new RuntimeException('搜索 API 已启用，但没有完成真实搜索诊断；请检查服务商配置后重试。');
    }

    // ── 降级估算：本地资料 + 可选官网抓取。没有搜索 API 时不能判断真实全网声量。 ──
    $signals = geo_diagnosis_signal_catalog();
    $authority = geo_diagnosis_domain_authority($db, $domain);
    $authorityWeight = (float) ($authority['weight'] ?? 0.2);
    $seed = abs((int) crc32($brand . '|' . $domain . '|' . $industry));
    $brandLen = mb_strlen($brand, 'UTF-8');
    $hasChinese = preg_match('/\p{Han}/u', $brand) === 1;
    $hasDomain = $domain !== '';

    $homepageHtml = '';
    if (!empty($cfg['enable_site_crawl']) && $domain !== '') {
        $homepageHtml = (string) (geo_diagnosis_crawl_url('https://' . $domain, (int) ($cfg['timeout_seconds'] ?? 10)) ?? '');
        if ($homepageHtml !== '') {
            $sourceMode = 'site_crawl_estimate';
        }
    }

    $homepageText = $homepageHtml !== '' ? mb_substr(trim(preg_replace('/\s+/u', ' ', strip_tags($homepageHtml))), 0, 6000) : '';
    $combinedEvidence = trim($evidence . "\n" . $homepageText);
    $evidenceLen = mb_strlen($combinedEvidence, 'UTF-8');
    $numbers = preg_match_all('/\d+(\.\d+)?%?|\d{4}年|\d{4}-\d{1,2}/u', $combinedEvidence, $m);
    $links = preg_match_all('#https?://|www\.|\.com|\.cn|\.org|\.edu|\.gov#i', $combinedEvidence . "\n" . $homepageHtml, $m2);
    $structureHints = preg_match_all('/FAQ|问答|清单|步骤|表格|案例|数据|报告|白皮书|schema|JSON-LD|application\/ld\+json|itemtype|schema\.org|<h[1-6]|<ul|<ol|<table/i', $combinedEvidence . "\n" . $homepageHtml, $m3);
    $platformHints = preg_match_all('/知乎|小红书|B站|bilibili|公众号|即刻|豆瓣|抖音|头条|Reddit|微博/i', $combinedEvidence, $m4);
    $identityHints = preg_match_all('/关于|About|联系|Contact|作者|编辑|隐私|备案|ICP|公司|团队|门店|加盟|品牌|隐私政策|用户协议/i', $combinedEvidence . "\n" . $homepageHtml, $m5);
    $homepageBonus = $homepageHtml !== '' ? 1 : 0;

    $scoresByKey = [
        'third_party_mention' => min(100, 22 + $authorityWeight * 35 + min(24, $platformHints * 8) + (($seed % 13))),
        'fact_density' => min(100, 18 + min(46, $numbers * 7 + $links * 5) + min(24, $evidenceLen / 120) + $homepageBonus * 8 + (($seed >> 3) % 8)),
        'structure' => min(100, 28 + min(42, $structureHints * 4) + ($hasDomain ? 12 : 0) + $homepageBonus * 8 + (($seed >> 5) % 8)),
        'authoritative_links' => min(100, 12 + $authorityWeight * 62 + min(18, $links * 4) + (($seed >> 7) % 8)),
        'ugc_coverage' => min(100, 18 + min(44, $platformHints * 12) + ($hasChinese ? 12 : 4) + min(12, $brandLen * 1.2) + (($seed >> 9) % 10)),
        'site_identity' => min(100, 30 + ($hasDomain ? 18 : 0) + min(34, $identityHints * 5) + $homepageBonus * 10 + (str_ends_with($domain, '.cn') ? 8 : 0) + (($seed >> 11) % 8)),
    ];

    $details = [
        'third_party_mention' => ['hint' => '本地估算：未接搜索 API，无法验证真实第三方提及；仅参考输入资料里的平台线索和域名种子库', 'domain_tier' => $authority['tier'] ?? 'T5'],
        'fact_density' => ['hint' => $homepageHtml !== '' ? '官网抓取 + 输入资料估算事实密度' : '仅基于输入资料估算事实密度', 'fact_hints' => $numbers + $links, 'crawled' => $homepageHtml !== ''],
        'structure' => ['hint' => $homepageHtml !== '' ? '官网抓取估算结构化信号' : '仅基于输入资料里的结构化线索估算', 'structure_hints' => $structureHints, 'crawled' => $homepageHtml !== ''],
        'authoritative_links' => ['hint' => '本地估算：未接搜索 API，无法验证权威媒体/政府/高校来源', 'authority_weight' => $authorityWeight],
        'ugc_coverage' => ['hint' => '本地估算：未接搜索 API，无法验证知乎/小红书/B站/公众号真实覆盖', 'platform_hints' => $platformHints],
        'site_identity' => ['hint' => $homepageHtml !== '' ? '官网抓取估算站点身份信号' : '基于官网域名和输入资料估算站点身份', 'identity_hints' => $identityHints, 'crawled' => $homepageHtml !== ''],
    ];

    $out = [];
    foreach ($signals as $signal) {
        $score = round(max(0, min(100, $scoresByKey[$signal['key']] ?? 0)), 2);
        $out[] = [
            'key' => $signal['key'],
            'name' => $signal['name'],
            'weight' => $signal['weight'],
            'score' => $score,
            'description' => $signal['description'],
            'raw_metric' => [
                'brand_length' => $brandLen,
                'domain' => $domain,
                'industry' => $industry,
                'evidence_length' => $evidenceLen,
                'data_source' => $sourceMode,
                'search_provider' => '',
                'crawled_homepage' => $homepageHtml !== '',
            ],
            'details' => $details[$signal['key']] ?? [],
        ];
    }
    return $out;
}

/**
 * 从 geo_monitor_records 提取真实引用数据，区分品牌词和通用词。
 * 返回结构化分析，用于替代估算分数。
 */
function geo_diagnosis_monitor_data(PDO $db, string $customerId, string $brand): array {
    $stmt = $db->prepare("
        SELECT query_text, brand_mentioned, mention_count, competitors_found, provider, queried_at
        FROM geo_monitor_records
        WHERE customer_id = ?
        ORDER BY created_at DESC
    ");
    $stmt->execute([$customerId]);
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($records)) {
        return [];
    }

    // 品牌词特征：包含品牌名或常见变体
    $brandWords = [];
    foreach (preg_split('/[\s\-_\/]+/u', mb_strtolower($brand)) as $w) {
        if (mb_strlen($w) >= 2) $brandWords[] = $w;
    }

    $byQuery = [];
    foreach ($records as $r) {
        $q = $r['query_text'];
        if (!isset($byQuery[$q])) {
            $ql = mb_strtolower($q);
            $isBranded = false;
            foreach ($brandWords as $w) {
                if (mb_strpos($ql, $w) !== false) { $isBranded = true; break; }
            }
            $byQuery[$q] = ['total' => 0, 'mentioned' => 0, 'branded' => $isBranded, 'competitors' => []];
        }
        $byQuery[$q]['total']++;
        if ($r['brand_mentioned']) $byQuery[$q]['mentioned']++;
        $comps = json_decode($r['competitors_found'] ?? '[]', true) ?: [];
        if ($comps) $byQuery[$q]['competitors'] = array_unique(array_merge($byQuery[$q]['competitors'], $comps));
    }

    $nonBrandedTotal = 0; $nonBrandedMentioned = 0;
    $gaps = []; $wins = [];
    foreach ($byQuery as $q => $d) {
        $rate = $d['total'] > 0 ? round(100.0 * $d['mentioned'] / $d['total'], 0) : 0;
        $byQuery[$q]['rate'] = $rate;
        if (!$d['branded']) {
            $nonBrandedTotal += $d['total'];
            $nonBrandedMentioned += $d['mentioned'];
            if ($d['mentioned'] === 0) $gaps[] = $q;
            else $wins[] = $q;
        }
    }

    $nonBrandedRate = $nonBrandedTotal > 0
        ? round(100.0 * $nonBrandedMentioned / $nonBrandedTotal, 1)
        : 0.0;

    return [
        'total_records'       => count($records),
        'non_branded_total'   => $nonBrandedTotal,
        'non_branded_cited'   => $nonBrandedMentioned,
        'non_branded_rate'    => $nonBrandedRate,
        'by_query'            => $byQuery,
        'gap_keywords'        => $gaps,
        'win_keywords'        => $wins,
        'data_source'         => 'real_monitor',
    ];
}

/**
 * 当有真实监测数据时，用引用率替换估算分数中的 ugc_coverage 和 third_party_mention。
 */
function geo_diagnosis_apply_monitor_data(array $scores, array $monitorData): array {
    if (empty($monitorData)) return $scores;

    $rate    = (float) ($monitorData['non_branded_rate'] ?? 0);
    $total   = (int)   ($monitorData['non_branded_total'] ?? 0);
    $cited   = (int)   ($monitorData['non_branded_cited'] ?? 0);
    $gaps    = $monitorData['gap_keywords'] ?? [];
    $wins    = $monitorData['win_keywords'] ?? [];

    // ugc_coverage = 非品牌词实际引用率（0-100）
    $ugcScore = $rate;
    // third_party_mention：有赢得引用的关键词则加分，全部为 0% 则压低
    $mentionBonus = count($wins) > 0 ? min(30, count($wins) * 10) : 0;
    $mentionScore = min(100, $rate * 0.6 + $mentionBonus);

    foreach ($scores as &$s) {
        if ($s['key'] === 'ugc_coverage') {
            $s['score'] = round($ugcScore, 2);
            $s['raw_metric']['data_source'] = 'real_monitor';
            $s['raw_metric']['non_branded_total'] = $total;
            $s['raw_metric']['non_branded_cited'] = $cited;
            $s['details']['hint'] = "真实监测数据：{$total} 次非品牌词查询，{$cited} 次提及，引用率 {$rate}%";
            $s['details']['gap_keywords'] = $gaps;
            $s['details']['win_keywords'] = $wins;
        }
        if ($s['key'] === 'third_party_mention') {
            $s['score'] = round($mentionScore, 2);
            $s['raw_metric']['data_source'] = 'real_monitor';
            $s['details']['hint'] = "基于真实 AI 平台查询结果估算第三方提及度";
        }
    }
    unset($s);
    return $scores;
}

function geo_diagnosis_overall_score(array $scores): float {
    $weighted = 0.0;
    $totalWeight = 0.0;
    foreach ($scores as $score) {
        $weight = (float) ($score['weight'] ?? 0);
        $weighted += ((float) ($score['score'] ?? 0)) * $weight;
        $totalWeight += $weight;
    }
    return round($totalWeight > 0 ? $weighted / $totalWeight : 0, 2);
}

function geo_diagnosis_hit_rate(float $overall): string {
    if ($overall >= 80) {
        return 'high';
    }
    if ($overall >= 60) {
        return 'medium';
    }
    if ($overall >= 40) {
        return 'low';
    }
    return 'very_low';
}

function geo_diagnosis_hit_rate_label(string $rate): string {
    return [
        'high' => '高',
        'medium' => '中',
        'low' => '低',
        'very_low' => '很低',
    ][$rate] ?? '未知';
}

function geo_diagnosis_update_weights(PDO $db, string $diagnosisId, array $weights, array $scores = []): void {
    geo_diagnosis_ensure_schema($db);
    if ($diagnosisId === '') {
        throw new InvalidArgumentException('缺少诊断ID');
    }

    $current = geo_diagnosis_latest($db, $diagnosisId);
    if (!$current) {
        throw new InvalidArgumentException('诊断记录不存在');
    }

    $normalized = [];
    $total = 0.0;
    foreach ($current['scores'] as $score) {
        $key = (string) $score['signal_key'];
        $value = isset($weights[$key]) ? (float) $weights[$key] : ((float) $score['weight'] * 100);
        $value = max(0, min(100, $value));
        $normalized[$key] = round($value, 2);
        $total += $normalized[$key];
    }

    if (abs($total - 100.0) > 0.01) {
        throw new InvalidArgumentException('六个维度权重总和必须等于 100%，当前为 ' . round($total, 2) . '%');
    }

    $updatedScores = [];
    $weighted = 0.0;
    $db->beginTransaction();
    try {
        $stmt = $db->prepare("
            UPDATE geo_diagnosis_signal_scores
            SET score = ?, weight = ?
            WHERE diagnosis_id = ? AND signal_key = ?
        ");
        foreach ($current['scores'] as $score) {
            $key = (string) $score['signal_key'];
            $weight = $normalized[$key] / 100.0;
            $scoreValue = isset($scores[$key]) ? (float) $scores[$key] : (float) $score['score'];
            $scoreValue = round(max(0, min(100, $scoreValue)), 2);
            $stmt->execute([$scoreValue, $weight, $diagnosisId, $key]);
            $weighted += $scoreValue * $weight;
            $updatedScores[] = [
                'key' => $key,
                'name' => (string) ($score['name'] ?: $key),
                'weight' => $weight,
                'score' => $scoreValue,
            ];
        }

        $overall = round($weighted, 2);
        $rate = geo_diagnosis_hit_rate($overall);
        $runStmt = $db->prepare("
            UPDATE geo_diagnosis_runs
            SET overall_score = ?, predicted_hit_rate = ?, raw_signals_json = ?::jsonb
            WHERE id = ?
        ");
        $runStmt->execute([
            $overall,
            $rate,
            json_encode($updatedScores, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $diagnosisId
        ]);

        $db->prepare("DELETE FROM geo_diagnosis_actions WHERE diagnosis_id = ?")->execute([$diagnosisId]);
        $actionStmt = $db->prepare("
            INSERT INTO geo_diagnosis_actions (diagnosis_id, signal_key, priority, action_text, estimated_impact, sku_id)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        foreach (geo_diagnosis_actions_for_scores($updatedScores) as $action) {
            $actionStmt->execute([
                $diagnosisId,
                $action['signal_key'],
                $action['priority'],
                $action['action_text'],
                $action['estimated_impact'],
                $action['sku_id'],
            ]);
        }

        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

function geo_diagnosis_industry_benchmark(PDO $db, string $industry): float {
    $stmt = $db->prepare("
        SELECT AVG(score)
        FROM geo_diagnosis_industry_benchmarks
        WHERE industry = ? AND benchmark_type = 'top_25'
    ");
    $stmt->execute([$industry]);
    $value = $stmt->fetchColumn();
    return round($value !== false ? (float) $value : 70, 2);
}

function geo_diagnosis_industry_benchmarks_by_signal(PDO $db, string $industry): array {
    $stmt = $db->prepare("
        SELECT signal_key, score
        FROM geo_diagnosis_industry_benchmarks
        WHERE industry = ? AND benchmark_type = 'top_25'
    ");
    $stmt->execute([$industry]);
    $rows = $stmt->fetchAll();
    $benchmarks = [];
    foreach ($rows as $row) {
        $benchmarks[(string) $row['signal_key']] = round((float) $row['score'], 1);
    }

    if (!empty($benchmarks)) {
        return $benchmarks;
    }

    foreach (geo_diagnosis_signal_catalog() as $signal) {
        $benchmarks[$signal['key']] = 70.0;
    }
    return $benchmarks;
}

function geo_diagnosis_actions_for_scores(array $scores): array {
    usort($scores, static fn ($a, $b) => ((float) $a['score']) <=> ((float) $b['score']));
    $templates = [
        'third_party_mention' => ['在知乎、小红书、行业媒体补齐 5 篇第三方结构化提及，优先覆盖品牌名 + 核心品类关键词。', 10, 'ugc_pkg_basic'],
        'fact_density' => ['为官网核心页面补充数据点、客户案例、年份、百分比、引用来源，让 AI 更容易抽取可信事实。', 8, 'content_fact_pack'],
        'structure' => ['重构首页和服务页的信息层级，增加 FAQ、列表、对比表和 JSON-LD 结构化数据。', 9, 'schema_pack'],
        'authoritative_links' => ['补充指向权威媒体、政府/学术/行业报告的外链，并争取 1 篇高权威来源报道。', 12, 'authority_pr_basic'],
        'ugc_coverage' => ['铺设知乎、小红书、B站、公众号的品牌问答与案例内容，形成可被 AI 引用的 UGC 语料。', 10, 'ugc_distribution'],
        'site_identity' => ['完善 About、联系方式、作者署名、编辑政策、隐私政策和备案信息，增强站点身份可信度。', 7, 'trust_page_pack'],
    ];
    $actions = [];
    $priority = 1;
    foreach (array_slice($scores, 0, 3) as $score) {
        $key = (string) $score['key'];
        [$text, $impact, $sku] = $templates[$key] ?? ['补齐该维度的公开可信资料。', 6, 'geo_basic'];
        $actions[] = [
            'signal_key' => $key,
            'signal_name' => $score['name'],
            'priority' => $priority++,
            'action_text' => $text,
            'estimated_impact' => $impact,
            'sku_id' => $sku,
            'score' => (float) $score['score'],
        ];
    }
    return $actions;
}

function geo_diagnosis_extract_json_object(string $text): array {
    $text = trim($text);
    if ($text === '') {
        return [];
    }
    $text = preg_replace('/^```(?:json)?\s*/i', '', $text);
    $text = preg_replace('/\s*```$/', '', (string) $text);
    $start = strpos($text, '{');
    $end = strrpos($text, '}');
    if ($start === false || $end === false || $end <= $start) {
        return [];
    }
    $json = substr($text, $start, $end - $start + 1);
    $data = json_decode($json, true);
    return is_array($data) ? $data : [];
}

function geo_diagnosis_generate_ai_analysis(PDO $db, string $diagnosisId): array {
    geo_diagnosis_ensure_schema($db);
    $diagnosisId = trim($diagnosisId);
    if ($diagnosisId === '') {
        throw new InvalidArgumentException('缺少诊断ID');
    }
    $report = geo_diagnosis_latest($db, $diagnosisId);
    if (!$report) {
        throw new InvalidArgumentException('诊断记录不存在');
    }
    if (!function_exists('geo_call_ai')) {
        throw new RuntimeException('AI 调用函数不可用，请检查系统配置。');
    }

    $scoreContext = [];
    foreach ((array) ($report['scores'] ?? []) as $score) {
        $scoreContext[] = [
            'signal_key' => (string) ($score['signal_key'] ?? ''),
            'name' => (string) ($score['name'] ?? $score['signal_key'] ?? ''),
            'score' => round((float) ($score['score'] ?? 0), 1),
            'weight_percent' => round(((float) ($score['weight'] ?? 0)) * 100, 1),
            'benchmark_score' => round((float) ($score['benchmark_score'] ?? 0), 1),
            'benchmark_gap' => round((float) ($score['benchmark_gap'] ?? 0), 1),
            'details' => json_decode((string) ($score['details_json'] ?? '{}'), true) ?: [],
            'raw_metric' => json_decode((string) ($score['raw_metric'] ?? '{}'), true) ?: [],
        ];
    }

    $promptContext = [
        'brand' => (string) ($report['brand_name'] ?? ''),
        'domain' => (string) ($report['domain'] ?? ''),
        'industry' => (string) ($report['industry'] ?? ''),
        'overall_score' => round((float) ($report['overall_score'] ?? 0), 1),
        'hit_rate' => geo_diagnosis_hit_rate_label((string) ($report['predicted_hit_rate'] ?? '')),
        'industry_benchmark' => round((float) ($report['industry_benchmark'] ?? 0), 1),
        'scores' => $scoreContext,
    ];

    $prompt = "你是给中小企业老板看的GEO/AI搜索诊断顾问。请基于下面数据做真实经营分析，不要套模板，不要编造未给出的事实。\n"
        . "输出对象不是技术人员。必须使用人话，像咨询顾问在会议上解释：现在有什么问题、会影响什么、这周先做什么、要准备什么材料、做完怎么判断有效。\n"
        . "禁止在面向用户的文案里出现这些内部词：fact_density、structure、site_identity、third_party_mention、authoritative_links、ugc_coverage、JSON-LD、H标签、schema、爬取、signal_key、sku_id。\n"
        . "如果必须表达技术动作，请翻译成人能理解的话，例如“把品牌资料整理成搜索引擎和AI容易读取的页面/表格”。\n"
        . "你的任务：给出一句话结论、实际业务影响、本周行动计划、需要客户准备的资料、3条优先动作。每条动作要具体到可以安排人执行。\n"
        . "请严格只输出JSON，不要Markdown。JSON结构必须是：{\n"
        . "  \"plain_summary\":\"60字以内，一句话说清当前最大问题\",\n"
        . "  \"business_impact\":\"80字以内，说清它会怎样影响客户咨询、成交或AI推荐\",\n"
        . "  \"evidence_note\":\"80字以内，说清哪些判断有数据支持，哪些还需要补资料，不要讲技术字段\",\n"
        . "  \"first_week_plan\":\"80字以内，说清本周第一优先级\",\n"
        . "  \"materials_needed\":[\"客户需要提供的资料1\",\"资料2\",\"资料3\"],\n"
        . "  \"actions\":[{\"signal_key\":\"六维signal_key之一，仅供系统内部使用\",\"priority\":1,\"title\":\"行动标题，不超过18字\",\"action_text\":\"要做什么，不超过90字\",\"why_it_matters\":\"为什么这件事有用，不超过80字\",\"what_to_prepare\":[\"需要准备1\",\"需要准备2\"],\"deliverables\":[\"交付物1\",\"交付物2\"],\"acceptance_criteria\":[\"验收标准1\",\"验收标准2\"],\"owner_role\":\"建议负责人，如品牌/内容/技术\",\"estimated_impact\":8}],\n"
        . "  \"risks\":[\"用人话写的风险1\",\"风险2\"],\n"
        . "  \"next_steps\":[\"下一步1\",\"下一步2\"]\n"
        . "}\n"
        . "允许的signal_key：third_party_mention, fact_density, structure, authoritative_links, ugc_coverage, site_identity。\n"
        . "诊断数据JSON：\n" . json_encode($promptContext, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $ai = geo_call_ai($prompt, 2400, 0.25);
    if (!empty($ai['error'])) {
        throw new RuntimeException('小米 MiMo 分析调用失败：' . (string) $ai['error']);
    }
    $analysis = geo_diagnosis_extract_json_object((string) ($ai['content'] ?? ''));
    if (empty($analysis['actions']) || !is_array($analysis['actions'])) {
        throw new RuntimeException('小米 MiMo 已返回内容，但没有给出可解析的行动项。');
    }

    $validSignals = array_column(geo_diagnosis_signal_catalog(), 'key');
    $scoresByKey = [];
    foreach ($scoreContext as $score) {
        $scoresByKey[(string) $score['signal_key']] = $score;
    }

    $normalizedActions = [];
    $priority = 1;
    foreach ((array) $analysis['actions'] as $item) {
        if ($priority > 3) {
            break;
        }
        if (!is_array($item)) {
            continue;
        }
        $signalKey = (string) ($item['signal_key'] ?? '');
        if (!in_array($signalKey, $validSignals, true)) {
            $signalKey = (string) ($scoreContext[$priority - 1]['signal_key'] ?? 'fact_density');
        }
        $actionText = trim((string) ($item['action_text'] ?? ''));
        if ($actionText === '') {
            continue;
        }
        $title = trim((string) ($item['title'] ?? ''));
        $normalizedActions[] = [
            'signal_key' => $signalKey,
            'priority' => $priority++,
            'action_text' => mb_substr($actionText, 0, 180),
            'estimated_impact' => round(max(1, min(20, (float) ($item['estimated_impact'] ?? 8))), 1),
            'sku_id' => 'geo_ai_analysis',
            'details' => [
                'title' => mb_substr($title !== '' ? $title : $actionText, 0, 36),
                'why_it_matters' => mb_substr(trim((string) ($item['why_it_matters'] ?? $item['rationale'] ?? '')), 0, 400),
                'what_to_prepare' => array_values(array_slice(array_map('strval', (array) ($item['what_to_prepare'] ?? [])), 0, 5)),
                'deliverables' => array_values(array_slice(array_map('strval', (array) ($item['deliverables'] ?? [])), 0, 5)),
                'acceptance_criteria' => array_values(array_slice(array_map('strval', (array) ($item['acceptance_criteria'] ?? [])), 0, 5)),
                'owner_role' => mb_substr(trim((string) ($item['owner_role'] ?? '项目负责人')), 0, 40),
                'source' => 'xiaomi_mimo',
                'score' => $scoresByKey[$signalKey]['score'] ?? null,
            ],
        ];
    }

    if (empty($normalizedActions)) {
        throw new RuntimeException('小米 MiMo 返回的行动项为空。');
    }

    $analysis['actions'] = $normalizedActions;
    $analysis['model_used'] = (string) ($ai['model_used'] ?? '');
    $analysis['generated_at'] = date('Y-m-d H:i:s');

    $db->beginTransaction();
    try {
        $runStmt = $db->prepare("
            UPDATE geo_diagnosis_runs
            SET ai_analysis_json = ?::jsonb,
                ai_analysis_model = ?,
                ai_analyzed_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        $runStmt->execute([
            json_encode($analysis, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            (string) ($ai['model_used'] ?? ''),
            $diagnosisId,
        ]);

        $db->prepare("DELETE FROM geo_diagnosis_actions WHERE diagnosis_id = ?")->execute([$diagnosisId]);
        $actionStmt = $db->prepare("
            INSERT INTO geo_diagnosis_actions (diagnosis_id, signal_key, priority, action_text, estimated_impact, sku_id, details_json)
            VALUES (?, ?, ?, ?, ?, ?, ?::jsonb)
        ");
        foreach ($normalizedActions as $action) {
            $actionStmt->execute([
                $diagnosisId,
                $action['signal_key'],
                $action['priority'],
                $action['action_text'],
                $action['estimated_impact'],
                $action['sku_id'],
                json_encode($action['details'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    return $analysis;
}

function geo_diagnosis_sku_label(string $sku): string {
    return [
        'ugc_pkg_basic' => 'UGC内容铺设基础包',
        'content_fact_pack' => '事实密度优化包',
        'schema_pack' => '结构化数据优化包',
        'authority_pr_basic' => '权威提及与PR基础包',
        'ugc_distribution' => 'UGC渠道分发包',
        'trust_page_pack' => '站点可信度页面包',
        'geo_ai_analysis' => 'AI深度诊断行动包',
        'geo_basic' => 'GEO基础优化包',
    ][$sku] ?? $sku;
}

function geo_diagnosis_latest(PDO $db, ?string $id = null): ?array {
    geo_diagnosis_ensure_schema($db);
    if ($id !== null && $id !== '') {
        $stmt = $db->prepare("
            SELECT r.*, b.name AS brand_name, b.domain, b.industry
            FROM geo_diagnosis_runs r
            JOIN geo_diagnosis_brands b ON b.id = r.brand_id
            WHERE r.id = ?
            LIMIT 1
        ");
        $stmt->execute([$id]);
    } else {
        $stmt = $db->query("
            SELECT r.*, b.name AS brand_name, b.domain, b.industry
            FROM geo_diagnosis_runs r
            JOIN geo_diagnosis_brands b ON b.id = r.brand_id
            ORDER BY r.created_at DESC
            LIMIT 1
        ");
    }
    $run = $stmt->fetch();
    if (!$run) {
        return null;
    }

    $benchmarks = geo_diagnosis_industry_benchmarks_by_signal($db, (string) ($run['industry'] ?? ''));

    $scoreStmt = $db->prepare("
        SELECT s.*, d.name, d.description, d.default_weight
        FROM geo_diagnosis_signal_scores s
        LEFT JOIN geo_diagnosis_signal_definitions d ON d.signal_key = s.signal_key
        WHERE s.diagnosis_id = ?
        ORDER BY d.default_weight DESC, s.signal_key ASC
    ");
    $scoreStmt->execute([$run['id']]);
    $scores = $scoreStmt->fetchAll();
    foreach ($scores as &$score) {
        $benchmark = $benchmarks[(string) $score['signal_key']] ?? (float) ($run['industry_benchmark'] ?? 70);
        $score['benchmark_score'] = round((float) $benchmark, 1);
        $score['benchmark_gap'] = round(((float) $benchmark) - ((float) $score['score']), 1);
    }
    unset($score);

    $actionStmt = $db->prepare("
        SELECT a.*, s.score, d.name AS signal_name
        FROM geo_diagnosis_actions a
        LEFT JOIN geo_diagnosis_signal_scores s
          ON s.diagnosis_id = a.diagnosis_id AND s.signal_key = a.signal_key
        LEFT JOIN geo_diagnosis_signal_definitions d
          ON d.signal_key = a.signal_key
        WHERE a.diagnosis_id = ?
        ORDER BY a.priority ASC
    ");
    $actionStmt->execute([$run['id']]);
    $actions = $actionStmt->fetchAll();
    foreach ($actions as &$action) {
        $benchmark = $benchmarks[(string) $action['signal_key']] ?? (float) ($run['industry_benchmark'] ?? 70);
        $action['benchmark_score'] = round((float) $benchmark, 1);
        $action['benchmark_gap'] = round(((float) $benchmark) - ((float) ($action['score'] ?? 0)), 1);
        $action['sku_label'] = geo_diagnosis_sku_label((string) ($action['sku_id'] ?? ''));
        $action['details'] = json_decode((string) ($action['details_json'] ?? '{}'), true) ?: [];
    }
    unset($action);

    $run['ai_analysis'] = json_decode((string) ($run['ai_analysis_json'] ?? '{}'), true) ?: [];
    $run['scores'] = $scores;
    $run['actions'] = $actions;
    return $run;
}

function geo_diagnosis_latest_for_brand(PDO $db, string $brandName, string $domain = ''): ?array {
    geo_diagnosis_ensure_schema($db);
    $brandName = trim($brandName);
    $domain = geo_diagnosis_normalize_domain($domain);
    if ($brandName === '' && $domain === '') {
        return null;
    }

    $where = [];
    $params = [];
    if ($brandName !== '') {
        $where[] = 'b.name = ?';
        $params[] = $brandName;
    }
    if ($domain !== '') {
        $where[] = 'b.domain = ?';
        $params[] = $domain;
    }

    $stmt = $db->prepare("
        SELECT r.id
        FROM geo_diagnosis_runs r
        JOIN geo_diagnosis_brands b ON b.id = r.brand_id
        WHERE " . implode(' OR ', $where) . "
        ORDER BY r.created_at DESC
        LIMIT 1
    ");
    $stmt->execute($params);
    $id = $stmt->fetchColumn();

    return $id ? geo_diagnosis_latest($db, (string) $id) : null;
}

function geo_diagnosis_summary(PDO $db): array {
    geo_diagnosis_ensure_schema($db);
    $row = $db->query("
        SELECT
            COUNT(*) AS total_runs,
            COALESCE(AVG(overall_score), 0) AS avg_score,
            COALESCE(SUM(CASE WHEN created_at::date = CURRENT_DATE THEN 1 ELSE 0 END), 0) AS today_runs
        FROM geo_diagnosis_runs
    ")->fetch();
    $actions = $db->query("SELECT COUNT(*) FROM geo_diagnosis_actions")->fetchColumn();
    return [
        'total_runs' => (int) ($row['total_runs'] ?? 0),
        'avg_score' => round((float) ($row['avg_score'] ?? 0), 1),
        'today_runs' => (int) ($row['today_runs'] ?? 0),
        'total_actions' => (int) ($actions ?: 0),
    ];
}

function geo_diagnosis_recent(PDO $db, int $limit = 8): array {
    geo_diagnosis_ensure_schema($db);
    $stmt = $db->prepare("
        SELECT r.id, r.overall_score, r.predicted_hit_rate, r.created_at, b.name AS brand_name, b.domain, b.industry
        FROM geo_diagnosis_runs r
        JOIN geo_diagnosis_brands b ON b.id = r.brand_id
        ORDER BY r.created_at DESC
        LIMIT ?
    ");
    $stmt->bindValue(1, max(1, $limit), PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function geo_diagnosis_hit_rate_percent(string $rate): int {
    return [
        'high' => 65,
        'medium' => 35,
        'low' => 15,
        'very_low' => 5,
    ][$rate] ?? 0;
}

function geo_diagnosis_history(PDO $db, string $brandId, int $limit = 12): array {
    geo_diagnosis_ensure_schema($db);
    if ($brandId === '') {
        return [
            'labels' => [],
            'overall' => [],
            'hit_rate' => [],
            'signals' => [],
            'delta' => null,
        ];
    }

    $stmt = $db->prepare("
        SELECT id, overall_score, predicted_hit_rate, created_at
        FROM geo_diagnosis_runs
        WHERE brand_id = ?
        ORDER BY created_at DESC
        LIMIT ?
    ");
    $stmt->bindValue(1, $brandId);
    $stmt->bindValue(2, max(1, $limit), PDO::PARAM_INT);
    $stmt->execute();
    $runs = array_reverse($stmt->fetchAll());

    $signals = [];
    foreach (geo_diagnosis_signal_catalog() as $signal) {
        $signals[$signal['key']] = [
            'name' => $signal['name'],
            'data' => [],
        ];
    }

    $labels = [];
    $overall = [];
    $hitRate = [];
    $scoreStmt = $db->prepare("
        SELECT signal_key, score
        FROM geo_diagnosis_signal_scores
        WHERE diagnosis_id = ?
    ");

    foreach ($runs as $run) {
        $labels[] = date('m-d H:i', strtotime((string) $run['created_at']));
        $overall[] = round((float) ($run['overall_score'] ?? 0), 1);
        $hitRate[] = geo_diagnosis_hit_rate_percent((string) ($run['predicted_hit_rate'] ?? ''));

        $scoreStmt->execute([$run['id']]);
        $scoreMap = [];
        foreach ($scoreStmt->fetchAll() as $row) {
            $scoreMap[(string) $row['signal_key']] = round((float) $row['score'], 1);
        }
        foreach ($signals as $key => &$signal) {
            $signal['data'][] = $scoreMap[$key] ?? null;
        }
        unset($signal);
    }

    $delta = null;
    $count = count($runs);
    if ($count >= 2) {
        $current = $runs[$count - 1];
        $previous = $runs[$count - 2];
        $delta = [
            'overall' => round(((float) $current['overall_score']) - ((float) $previous['overall_score']), 1),
            'hit_rate' => geo_diagnosis_hit_rate_percent((string) $current['predicted_hit_rate']) - geo_diagnosis_hit_rate_percent((string) $previous['predicted_hit_rate']),
            'current_time' => (string) $current['created_at'],
            'previous_time' => (string) $previous['created_at'],
        ];
    }

    return [
        'labels' => $labels,
        'overall' => $overall,
        'hit_rate' => $hitRate,
        'signals' => $signals,
        'delta' => $delta,
    ];
}

// ─── 真实搜索 API 接入 ────────────────────────────────────────────────────────

/**
 * 统一搜索入口：支持 serpapi / bing / google_cse
 * 返回 [{title, url, snippet}, ...] 或空数组
 */
function geo_diagnosis_search(string $provider, string $apiKey, string $query, int $limit, int $timeout, string $googleCseId = ''): array {
    if ($provider === 'disabled' || $apiKey === '') {
        return [];
    }

    $encodedQ = urlencode($query);
    $results = [];

    if ($provider === 'serpapi') {
        $url = 'https://serpapi.com/search.json?engine=google&q=' . $encodedQ
            . '&api_key=' . urlencode($apiKey)
            . '&hl=zh-cn&gl=cn&num=' . min($limit, 10);
        $raw = geo_diagnosis_http_get_or_fail($url, $timeout, [], 'SerpAPI');
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new RuntimeException('SerpAPI 返回不是有效 JSON。');
        }
        if (!empty($data['error'])) {
            throw new RuntimeException('SerpAPI 返回错误：' . (string) $data['error']);
        }
        foreach ((array) ($data['organic_results'] ?? []) as $item) {
            $results[] = [
                'title'   => (string) ($item['title'] ?? ''),
                'url'     => (string) ($item['link'] ?? ''),
                'snippet' => (string) ($item['snippet'] ?? ''),
            ];
        }
    } elseif ($provider === 'bing') {
        $url = 'https://api.bing.microsoft.com/v7.0/search?q=' . $encodedQ
            . '&count=' . min($limit, 50) . '&mkt=zh-CN&setLang=zh-hans';
        $raw = geo_diagnosis_http_get_or_fail($url, $timeout, ['Ocp-Apim-Subscription-Key: ' . $apiKey], 'Bing Search API');
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new RuntimeException('Bing Search API 返回不是有效 JSON。');
        }
        if (!empty($data['error']['message'])) {
            throw new RuntimeException('Bing Search API 返回错误：' . (string) $data['error']['message']);
        }
        foreach ((array) ($data['webPages']['value'] ?? []) as $item) {
            $results[] = [
                'title'   => (string) ($item['name'] ?? ''),
                'url'     => (string) ($item['url'] ?? ''),
                'snippet' => (string) ($item['snippet'] ?? ''),
            ];
        }
    } elseif ($provider === 'google_cse' && $googleCseId !== '') {
        $url = 'https://www.googleapis.com/customsearch/v1?q=' . $encodedQ
            . '&key=' . urlencode($apiKey)
            . '&cx=' . urlencode($googleCseId)
            . '&num=' . min($limit, 10) . '&hl=zh-CN';
        $raw = geo_diagnosis_http_get_or_fail($url, $timeout, [], 'Google Custom Search');
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new RuntimeException('Google Custom Search 返回不是有效 JSON。');
        }
        if (!empty($data['error']['message'])) {
            throw new RuntimeException('Google Custom Search 返回错误：' . (string) $data['error']['message']);
        }
        foreach ((array) ($data['items'] ?? []) as $item) {
            $results[] = [
                'title'   => (string) ($item['title'] ?? ''),
                'url'     => (string) ($item['link'] ?? ''),
                'snippet' => (string) ($item['snippet'] ?? ''),
            ];
        }
    } elseif ($provider === 'google_cse') {
        throw new RuntimeException('Google Custom Search 已启用，但缺少 Search Engine ID（CX）。');
    } elseif ($provider === 'bocha') {
        $payload = json_encode([
            'query'     => $query,
            'count'     => min($limit, 10),
            'freshness' => 'noLimit',
            'summary'   => false,
        ], JSON_UNESCAPED_UNICODE);
        $ch = curl_init('https://api.bochaai.com/v1/web-search');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(10, max(3, $timeout)),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json',
            ],
        ]);
        $raw = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        if ($raw === false || $raw === '' || $curlError !== '') {
            throw new RuntimeException('博查 AI 搜索调用失败：' . ($curlError !== '' ? $curlError : '空响应'));
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new RuntimeException('博查 AI 返回不是有效 JSON。');
        }
        if ($httpCode < 200 || $httpCode >= 400) {
            $message = (string) ($data['message'] ?? $data['error']['message'] ?? '未知错误');
            throw new RuntimeException('博查 AI 搜索调用失败：HTTP ' . $httpCode . '，' . $message);
        }
        if (isset($data['code']) && !in_array((string) $data['code'], ['0', '200'], true) && empty($data['data'])) {
            throw new RuntimeException('博查 AI 返回错误：' . (string) ($data['message'] ?? $data['code']));
        }
        foreach ((array) ($data['data']['webPages']['value'] ?? []) as $item) {
            $results[] = [
                'title'   => (string) ($item['name'] ?? ''),
                'url'     => (string) ($item['url'] ?? ''),
                'snippet' => (string) ($item['snippet'] ?? ''),
            ];
        }
    } else {
        throw new RuntimeException('不支持的搜索数据源：' . $provider);
    }

    return $results;
}

function geo_diagnosis_http_get_or_fail(string $url, int $timeout = 15, array $headers = [], string $label = '搜索 API'): string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => min(10, max(3, $timeout)),
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
    ]);
    if (!empty($headers)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }
    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false || $response === '' || $curlError !== '') {
        throw new RuntimeException($label . ' 调用失败：' . ($curlError !== '' ? $curlError : '空响应'));
    }
    if ($httpCode < 200 || $httpCode >= 400) {
        $message = '';
        $data = json_decode((string) $response, true);
        if (is_array($data)) {
            $message = (string) ($data['error']['message'] ?? $data['message'] ?? $data['error'] ?? '');
        }
        throw new RuntimeException($label . ' 调用失败：HTTP ' . $httpCode . ($message !== '' ? '，' . $message : ''));
    }

    return (string) $response;
}

/**
 * 抓取网页 HTML（用于站点爬取）
 */
function geo_diagnosis_crawl_url(string $url, int $timeout = 10): ?string {
    return geo_diagnosis_http_get($url, $timeout, [
        'User-Agent: Mozilla/5.0 (compatible; GEO-Diagnosis/1.0)',
        'Accept-Language: zh-CN,zh;q=0.9',
    ]);
}

/**
 * 通用 HTTP GET（curl）
 */
function geo_diagnosis_http_get(string $url, int $timeout = 15, array $headers = []): ?string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
    ]);
    if (!empty($headers)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }
    $response  = curl_exec($ch);
    $httpCode  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false || $curlError !== '' || $httpCode < 200 || $httpCode >= 400) {
        return null;
    }
    return (string) $response;
}

/**
 * 从搜索结果列表里统计命中特定域名模式的条目数
 */
function geo_diagnosis_count_domain_hits(array $results, array $domainPatterns): int {
    $count = 0;
    foreach ($results as $r) {
        $url = strtolower((string) ($r['url'] ?? ''));
        foreach ($domainPatterns as $pattern) {
            if (str_contains($url, strtolower($pattern))) {
                $count++;
                break;
            }
        }
    }
    return $count;
}

/**
 * 用真实搜索 API 打分（六维）
 * 成功时返回与 estimate 相同格式的 $scores 数组；失败返回 null（调用方降级到估算）
 */
function geo_diagnosis_real_calculate_scores(
    string $brand,
    string $domain,
    string $industry,
    string $evidence,
    array  $cfg   // geo_diagnosis_data_source_config() 的返回值
): ?array {
    $provider    = $cfg['provider'] ?? 'disabled';
    $apiKey      = $cfg['api_key'] ?? '';
    $cseId       = $cfg['google_cse_id'] ?? '';
    $limit       = (int) ($cfg['result_limit'] ?? 10);
    $timeout     = (int) ($cfg['timeout_seconds'] ?? 15);
    $enableCrawl = !empty($cfg['enable_site_crawl']);

    if ($provider === 'disabled' || $apiKey === '') {
        return null;
    }

    // 权威平台列表（用于第三方提及 / 权威外链）
    $authorityDomains = ['36kr.com','huxiu.com','xinhuanet.com','people.com.cn','caixin.com',
        'thepaper.cn','cyzone.cn','ifanr.com','pingwest.com','sohu.com','163.com',
        'sina.com','qq.com','tencent.com','baidu.com','alibaba.com'];
    $ugcDomains       = ['zhihu.com','xiaohongshu.com','bilibili.com','weixin.qq.com',
        'weibo.com','douyin.com','toutiao.com','jike.app','tieba.baidu.com'];
    $govEduDomains    = ['gov.cn','edu.cn','ac.cn','org.cn'];

    // ── 搜索请求（并行用 curl_multi 更快，此处顺序执行保持简单） ──

    // 1. 第三方提及：brand + 媒体/评测，排除自有域名
    $excludeSelf  = $domain !== '' ? ' -site:' . $domain : '';
    $thirdResults = geo_diagnosis_search($provider, $apiKey,
        '"' . $brand . '"' . $excludeSelf, $limit, $timeout, $cseId);

    // 2. UGC 平台覆盖
    $ugcQuery   = '"' . $brand . '" site:zhihu.com OR site:xiaohongshu.com OR site:bilibili.com OR site:weixin.qq.com';
    $ugcResults = geo_diagnosis_search($provider, $apiKey, $ugcQuery, $limit, $timeout, $cseId);

    // 3. 权威外链：来自政府/高校/媒体的提及
    $authQuery    = '"' . $brand . '" site:gov.cn OR site:edu.cn OR site:xinhuanet.com OR site:36kr.com OR site:huxiu.com';
    $authResults  = geo_diagnosis_search($provider, $apiKey, $authQuery, $limit, $timeout, $cseId);

    // 4. 官网收录量（结构化/身份信号）
    $siteResults  = $domain !== '' ? geo_diagnosis_search($provider, $apiKey, 'site:' . $domain, $limit, $timeout, $cseId) : [];

    // 5. 可选：爬取首页
    $homepageHtml = '';
    if ($enableCrawl && $domain !== '') {
        $homepageHtml = (string) (geo_diagnosis_crawl_url('https://' . $domain, $timeout) ?? '');
    }

    // ── 评分 ──

    // 第三方提及 (0-100)
    $totalThird   = count($thirdResults);
    $mediaHits    = geo_diagnosis_count_domain_hits($thirdResults, $authorityDomains);
    $ugcHitsInThird = geo_diagnosis_count_domain_hits($thirdResults, $ugcDomains);
    $thirdScore   = min(100, 20 + $totalThird * 4 + $mediaHits * 5 + $ugcHitsInThird * 3);

    // UGC 平台覆盖 (0-100)
    $ugcHits      = count($ugcResults);
    $ugcScore     = min(100, 20 + $ugcHits * 8 + geo_diagnosis_count_domain_hits($ugcResults, $ugcDomains) * 3);

    // 权威外链 (0-100)
    $authHits     = count($authResults);
    $govHits      = geo_diagnosis_count_domain_hits($authResults, $govEduDomains);
    $authScore    = min(100, 15 + $authHits * 7 + $govHits * 8);

    // 结构化程度：搜索 + 爬取双重信号 (0-100)
    $structureScore = 28;
    if ($homepageHtml !== '') {
        $lower = mb_strtolower($homepageHtml);
        $structureScore += substr_count($lower, 'faq') * 6;
        $structureScore += substr_count($lower, 'json-ld') * 8;
        $structureScore += (str_contains($lower, 'itemtype') || str_contains($lower, 'schema.org')) ? 8 : 0;
        $structureScore += (substr_count($lower, '<table') + substr_count($lower, '<ul') + substr_count($lower, '<ol')) * 1;
    }
    // site: 搜索结果数量也反映结构化覆盖
    $structureScore += min(20, count($siteResults) * 2);
    $structureScore  = min(100, $structureScore);

    // 事实密度：爬取首页 + evidence 输入 (0-100)
    $factScore = 18;
    $allText   = $homepageHtml . ' ' . $evidence;
    $numbers   = preg_match_all('/\d+(\.\d+)?%?|\d{4}年|\d{4}-\d{1,2}/u', $allText, $m1);
    $links     = preg_match_all('#https?://|www\.|\.com|\.cn#i', $allText, $m2);
    $factScore += min(50, $numbers * 5 + $links * 3);
    $factScore  = min(100, $factScore);

    // 站点身份：爬取 + site: 搜索 (0-100)
    $identityScore = 30;
    if ($homepageHtml !== '') {
        $lower = mb_strtolower($homepageHtml);
        $identityScore += (str_contains($lower, '关于') || str_contains($lower, 'about')) ? 10 : 0;
        $identityScore += (str_contains($lower, '联系') || str_contains($lower, 'contact')) ? 8 : 0;
        $identityScore += (str_contains($lower, '隐私') || str_contains($lower, 'privacy')) ? 6 : 0;
        $identityScore += (str_contains($lower, 'icp') || str_contains($lower, '备案')) ? 8 : 0;
        $identityScore += (str_contains($lower, '作者') || str_contains($lower, 'author') || str_contains($lower, '编辑')) ? 6 : 0;
    }
    $identityScore += min(12, count($siteResults) * 1);
    $identityScore  = min(100, $identityScore);

    // ── 组装输出（格式与 estimate 完全一致） ──
    $signals = geo_diagnosis_signal_catalog();
    $scoresByKey = [
        'third_party_mention' => round($thirdScore, 2),
        'fact_density'        => round($factScore, 2),
        'authoritative_links' => round($authScore, 2),
        'structure'           => round($structureScore, 2),
        'ugc_coverage'        => round($ugcScore, 2),
        'site_identity'       => round($identityScore, 2),
    ];
    $detailsByKey = [
        'third_party_mention' => [
            'hint'           => '搜索 API 实时扫描第三方提及',
            'total_results'  => $totalThird,
            'media_hits'     => $mediaHits,
            'ugc_hits'       => $ugcHitsInThird,
        ],
        'fact_density'        => [
            'hint'          => '首页爬取 + 输入资料的事实密度分析',
            'number_hits'   => $numbers,
            'link_hints'    => $links,
            'crawled'       => $homepageHtml !== '',
        ],
        'authoritative_links' => [
            'hint'      => '搜索 API 扫描政府/高校/媒体域名提及',
            'auth_hits' => $authHits,
            'gov_hits'  => $govHits,
        ],
        'structure'           => [
            'hint'          => '首页爬取结构化信号 + site: 收录量',
            'site_indexed'  => count($siteResults),
            'crawled'       => $homepageHtml !== '',
        ],
        'ugc_coverage'        => [
            'hint'     => '搜索 API 扫描知乎/小红书/B站/公众号',
            'ugc_hits' => $ugcHits,
        ],
        'site_identity'       => [
            'hint'           => '首页爬取身份信号 + site: 收录量',
            'site_indexed'   => count($siteResults),
            'crawled'        => $homepageHtml !== '',
        ],
    ];

    $out = [];
    foreach ($signals as $signal) {
        $score = max(0, min(100, $scoresByKey[$signal['key']] ?? 0));
        $out[] = [
            'key'         => $signal['key'],
            'name'        => $signal['name'],
            'weight'      => $signal['weight'],
            'score'       => $score,
            'description' => $signal['description'],
            'raw_metric'  => [
                'brand_length'    => mb_strlen($brand, 'UTF-8'),
                'domain'          => $domain,
                'industry'        => $industry,
                'evidence_length' => mb_strlen($evidence, 'UTF-8'),
                'data_source'     => 'real_search',
                'search_provider' => $provider,
            ],
            'details'     => $detailsByKey[$signal['key']] ?? [],
        ];
    }
    return $out;
}
?>
