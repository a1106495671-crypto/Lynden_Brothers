<?php
/**
 * GEO 引用模拟器服务
 */

if (!defined('FEISHU_TREASURE')) {
    die('Access denied');
}

function citation_simulator_ensure_schema(PDO $db): void {
    $db->exec("CREATE EXTENSION IF NOT EXISTS pgcrypto");
    $db->exec("
        CREATE TABLE IF NOT EXISTS geo_simulator_queries (
            id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
            brand_id UUID NOT NULL REFERENCES geo_diagnosis_brands(id) ON DELETE RESTRICT,
            query_text VARCHAR(500) NOT NULL,
            industry_context VARCHAR(64) DEFAULT '',
            query_type VARCHAR(32) DEFAULT '',
            status VARCHAR(20) NOT NULL DEFAULT 'completed',
            ai_providers_json JSONB NOT NULL DEFAULT '[]'::jsonb,
            result_cache_json JSONB NOT NULL DEFAULT '{}'::jsonb,
            error_message TEXT,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            completed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_geo_sim_queries_brand ON geo_simulator_queries(brand_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_geo_sim_queries_created ON geo_simulator_queries(created_at DESC)");

    $db->exec("
        CREATE TABLE IF NOT EXISTS geo_simulator_runs (
            id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
            query_id UUID NOT NULL REFERENCES geo_simulator_queries(id) ON DELETE CASCADE,
            ai_provider VARCHAR(32) NOT NULL,
            response_text TEXT,
            citation_count INTEGER NOT NULL DEFAULT 0,
            duration_ms INTEGER,
            error_message TEXT,
            completed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (query_id, ai_provider)
        )
    ");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_geo_sim_runs_query ON geo_simulator_runs(query_id)");

    $db->exec("
        CREATE TABLE IF NOT EXISTS geo_simulator_citations (
            id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
            run_id UUID NOT NULL REFERENCES geo_simulator_runs(id) ON DELETE CASCADE,
            source_url VARCHAR(1024),
            source_domain VARCHAR(255) NOT NULL,
            source_title VARCHAR(500),
            source_type VARCHAR(32),
            citation_position VARCHAR(32) NOT NULL,
            citation_type VARCHAR(32),
            is_client_match BOOLEAN NOT NULL DEFAULT FALSE,
            raw_excerpt TEXT,
            relevance_score NUMERIC(5,2),
            order_in_answer INTEGER
        )
    ");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_geo_sim_citations_run ON geo_simulator_citations(run_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_geo_sim_citations_domain ON geo_simulator_citations(source_domain)");

    $db->exec("
        CREATE TABLE IF NOT EXISTS geo_simulator_ranked_sources (
            id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
            query_id UUID NOT NULL REFERENCES geo_simulator_queries(id) ON DELETE CASCADE,
            source_key VARCHAR(512) NOT NULL,
            source_display_name VARCHAR(500) NOT NULL,
            source_domain VARCHAR(255) NOT NULL,
            source_type VARCHAR(32),
            rank INTEGER NOT NULL,
            aggregated_score NUMERIC(5,2) NOT NULL,
            citation_count INTEGER NOT NULL,
            best_position VARCHAR(32),
            is_client BOOLEAN NOT NULL DEFAULT FALSE,
            ai_providers JSONB NOT NULL DEFAULT '[]'::jsonb,
            why TEXT,
            UNIQUE (query_id, source_key)
        )
    ");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_geo_sim_ranked_query_rank ON geo_simulator_ranked_sources(query_id, rank)");

    $db->exec("
        CREATE TABLE IF NOT EXISTS geo_simulator_action_defs (
            id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
            key VARCHAR(64) UNIQUE NOT NULL,
            label VARCHAR(200) NOT NULL,
            description TEXT,
            category VARCHAR(32) NOT NULL,
            base_impact NUMERIC(5,2) NOT NULL,
            sku_id VARCHAR(64) DEFAULT '',
            estimated_cost NUMERIC(10,2) DEFAULT 0,
            estimated_days INTEGER DEFAULT 0,
            active BOOLEAN NOT NULL DEFAULT TRUE,
            display_order INTEGER NOT NULL DEFAULT 0,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_geo_sim_action_defs_active ON geo_simulator_action_defs(active, display_order)");

    $db->exec("
        CREATE TABLE IF NOT EXISTS geo_simulator_simulations (
            id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
            query_id UUID NOT NULL REFERENCES geo_simulator_queries(id) ON DELETE CASCADE,
            selected_actions JSONB NOT NULL DEFAULT '[]'::jsonb,
            boost_total NUMERIC(5,2) NOT NULL,
            starting_rank INTEGER NOT NULL,
            resulting_rank INTEGER NOT NULL,
            starting_score NUMERIC(5,2) NOT NULL,
            resulting_score NUMERIC(5,2) NOT NULL,
            enters_top_5 BOOLEAN NOT NULL,
            estimated_cost NUMERIC(10,2) DEFAULT 0,
            estimated_days INTEGER DEFAULT 0,
            dispatched BOOLEAN NOT NULL DEFAULT FALSE,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_geo_sim_simulations_query ON geo_simulator_simulations(query_id)");

    citation_simulator_seed_actions($db);
}

function citation_simulator_seed_actions(PDO $db): void {
    $stmt = $db->prepare("
        INSERT INTO geo_simulator_action_defs
            (key, label, description, category, base_impact, sku_id, estimated_cost, estimated_days, display_order)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON CONFLICT (key) DO UPDATE SET
            label = EXCLUDED.label,
            description = EXCLUDED.description,
            category = EXCLUDED.category,
            base_impact = EXCLUDED.base_impact,
            sku_id = EXCLUDED.sku_id,
            estimated_cost = EXCLUDED.estimated_cost,
            estimated_days = EXCLUDED.estimated_days,
            display_order = EXCLUDED.display_order,
            active = TRUE,
            updated_at = CURRENT_TIMESTAMP
    ");
    foreach (citation_simulator_seed_action_rows() as $action) {
        $stmt->execute([
            $action['key'],
            $action['label'],
            $action['description'],
            $action['category'],
            $action['base_impact'],
            $action['sku_id'],
            $action['estimated_cost'],
            $action['estimated_days'],
            $action['display_order'],
        ]);
    }
}

function citation_simulator_seed_action_rows(): array {
    return [
        ['key' => 'ugc_zhihu_xhs_5', 'label' => '在知乎/小红书发布 5 篇结构化测评', 'description' => '由内容团队撰写并发布 5 篇满足 AI 友好结构的测评文章', 'category' => 'ugc', 'base_impact' => 8, 'sku_id' => 'ugc_pkg_basic', 'estimated_cost' => 8000, 'estimated_days' => 21, 'display_order' => 10],
        ['key' => 'pr_36kr_huxiu_1', 'label' => '争取 1 篇 36氪/虎嗅报道', 'description' => '通过媒介关系发起 1 篇主流科技/商业媒体报道', 'category' => 'pr_media', 'base_impact' => 12, 'sku_id' => 'pr_pkg_basic', 'estimated_cost' => 25000, 'estimated_days' => 45, 'display_order' => 20],
        ['key' => 'site_fact_density', 'label' => '产品页重写:增加事实密度', 'description' => '重写官网核心产品页，提升数据点、引用和结构化表达密度', 'category' => 'site', 'base_impact' => 6, 'sku_id' => 'site_rewrite', 'estimated_cost' => 6000, 'estimated_days' => 14, 'display_order' => 30],
        ['key' => 'site_authority_link', 'label' => '出站链接到权威报告', 'description' => '为关键内容页添加出站链接到权威报告、主流媒体和公开数据源', 'category' => 'site', 'base_impact' => 4, 'sku_id' => 'site_links', 'estimated_cost' => 2000, 'estimated_days' => 7, 'display_order' => 40],
        ['key' => 'site_identity', 'label' => '补全 About / 作者署名 / 编辑政策', 'description' => '完善站点身份信号、作者署名、编辑政策和联系方式', 'category' => 'site', 'base_impact' => 3, 'sku_id' => 'site_identity', 'estimated_cost' => 3000, 'estimated_days' => 10, 'display_order' => 50],
        ['key' => 'community_reddit', 'label' => '在 Reddit / 即刻发起讨论并被回复', 'description' => '在社区发起品牌相关讨论并获得有效回复', 'category' => 'community', 'base_impact' => 7, 'sku_id' => 'community_seed', 'estimated_cost' => 5000, 'estimated_days' => 21, 'display_order' => 60],
    ];
}

function citation_simulator_action_defs(PDO $db): array {
    $stmt = $db->query("
        SELECT key, label, description, category, base_impact, sku_id, estimated_cost, estimated_days
        FROM geo_simulator_action_defs
        WHERE active = TRUE
        ORDER BY display_order ASC, label ASC
    ");
    return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : citation_simulator_seed_action_rows();
}

function citation_simulator_industries(): array {
    return ['GEO服务商', 'AI营销服务', 'B2B SaaS', '企业服务', 'B2B专业服务', '电商', '教育', '医疗', '金融', '本地生活'];
}

function citation_simulator_query_types(): array {
    return ['精准查询', '对比查询', '问题类查询', '采购评估'];
}

function citation_simulator_ai_providers(): array {
    return [
        'doubao' => '豆包',
        'kimi' => 'Kimi',
        'deepseek' => 'DeepSeek',
        'tongyi' => '通义',
        'wenxin' => '文心',
        'yuanbao' => '元宝',
        'bocha' => '博查',
    ];
}

function citation_simulator_api_provider_fields(): array {
    return [
        'kimi' => [
            'label' => 'Kimi',
            'description' => 'Moonshot API，用于中文联网反查与引用抽取。',
            'fields' => [
                'api_key' => ['label' => 'KIMI_API_KEY', 'secret' => true],
            ],
        ],
        'deepseek' => [
            'label' => 'DeepSeek',
            'description' => 'DeepSeek API，可作为回答生成与隐式引用抽取模型。',
            'fields' => [
                'api_key' => ['label' => 'DEEPSEEK_API_KEY', 'secret' => true],
            ],
        ],
        'tongyi' => [
            'label' => '通义千问',
            'description' => '阿里 DashScope API，后续用于通义联网搜索反查。',
            'fields' => [
                'api_key' => ['label' => 'DASHSCOPE_API_KEY', 'secret' => true],
            ],
        ],
        'wenxin' => [
            'label' => '文心一言',
            'description' => '百度千帆 API（新版单 Key，OpenAI 兼容格式），填入 API Key 即可调用。',
            'fields' => [
                'api_key' => ['label' => 'QIANFAN_API_KEY', 'secret' => true],
            ],
        ],
        'doubao' => [
            'label' => '豆包',
            'description' => 'Volcengine Ark API（豆包），OpenAI 兼容格式，填入 API Key 即可调用。',
            'fields' => [
                'api_key' => ['label' => 'ARK_API_KEY', 'secret' => true],
            ],
        ],
        'yuanbao' => [
            'label' => '腾讯元宝',
            'description' => '腾讯混元 API（OpenAI 兼容格式），填入 API Key 即可调用。',
            'fields' => [
                'api_key' => ['label' => 'HUNYUAN_API_KEY', 'secret' => true],
            ],
        ],
        'bocha' => [
            'label' => '博查 AI',
            'description' => '博查 Web Search API，直接返回联网搜索结果，用于引用信源反查。',
            'fields' => [
                'api_key' => ['label' => 'BOCHA_API_KEY', 'secret' => true],
            ],
        ],
    ];
}

function citation_simulator_api_setting_key(string $provider, string $field): string {
    return 'geo_simulator_' . $provider . '_' . $field;
}

function citation_simulator_mask_secret(string $value): string {
    $length = strlen($value);
    if ($length <= 8) {
        return str_repeat('*', max($length, 4));
    }
    return substr($value, 0, 4) . str_repeat('*', max($length - 8, 8)) . substr($value, -4);
}

function citation_simulator_api_config(): array {
    $mode = (string) get_setting('geo_simulator_data_mode', 'local');
    if (!in_array($mode, ['local', 'real'], true)) {
        $mode = 'local';
    }

    $providers = [];
    foreach (citation_simulator_api_provider_fields() as $provider => $config) {
        $fields = [];
        $configuredCount = 0;
        foreach ($config['fields'] as $field => $meta) {
            $stored = (string) get_setting(citation_simulator_api_setting_key($provider, $field), '');
            $isSecret = !empty($meta['secret']);
            $type = (string) ($meta['type'] ?? ($isSecret ? 'password' : 'text'));
            $plain = $stored !== '' && $isSecret ? decrypt_ai_api_key($stored) : $stored;
            $configured = $plain !== '';
            if ($configured) {
                $configuredCount++;
            }
            $fields[$field] = [
                'label' => $meta['label'],
                'type' => $type,
                'secret' => $isSecret,
                'configured' => $configured,
                'value' => $isSecret ? '' : $plain,
                'masked' => $configured ? ($isSecret ? citation_simulator_mask_secret($plain) : $plain) : '',
            ];
        }
        $opStatus = (string) get_setting('geo_simulator_' . $provider . '_op_status', 'normal');
        if (!in_array($opStatus, ['normal', 'needs_attention', 'disabled'], true)) {
            $opStatus = 'normal';
        }
        $isConfigured = $configuredCount === count($config['fields']);
        $providers[$provider] = [
            'label' => $config['label'],
            'description' => $config['description'],
            'fields' => $fields,
            'configured' => $isConfigured,
            'op_status' => $isConfigured ? $opStatus : 'unconfigured',
        ];
    }

    return [
        'mode' => $mode,
        'providers' => $providers,
    ];
}

function citation_simulator_save_api_config(array $input): bool {
    $mode = (string) ($input['data_mode'] ?? 'local');
    if (!in_array($mode, ['local', 'real'], true)) {
        $mode = 'local';
    }
    if (!set_setting('geo_simulator_data_mode', $mode)) {
        return false;
    }

    $postedKeys = is_array($input['provider_keys'] ?? null) ? $input['provider_keys'] : [];
    $clearKeys = is_array($input['clear_provider_key'] ?? null) ? $input['clear_provider_key'] : [];
    $postedOpStatus = is_array($input['provider_op_status'] ?? null) ? $input['provider_op_status'] : [];

    foreach (citation_simulator_api_provider_fields() as $provider => $config) {
        // Save operational status if posted
        $opStatus = trim((string) ($postedOpStatus[$provider] ?? ''));
        if (in_array($opStatus, ['normal', 'needs_attention', 'disabled'], true)) {
            if (!set_setting('geo_simulator_' . $provider . '_op_status', $opStatus)) {
                return false;
            }
        }

        foreach ($config['fields'] as $field => $_meta) {
            $settingKey = citation_simulator_api_setting_key($provider, $field);
            $isSecret = !empty($_meta['secret']);
            $type = (string) ($_meta['type'] ?? ($isSecret ? 'password' : 'text'));
            $clearRequested = !empty($clearKeys[$provider][$field]);
            if ($type === 'boolean') {
                $newValue = !empty($postedKeys[$provider][$field]) ? '1' : '';
            } else {
                $newValue = trim((string) ($postedKeys[$provider][$field] ?? ''));
            }
            if ($clearRequested) {
                if (!set_setting($settingKey, '')) {
                    return false;
                }
                continue;
            }
            if ($newValue !== '') {
                $storedValue = $isSecret ? encrypt_ai_api_key($newValue) : $newValue;
                if (!set_setting($settingKey, $storedValue)) {
                    return false;
                }
            }
        }
    }

    return true;
}

function citation_simulator_default_form(): array {
    return [
        'brand_name' => '董逻辑',
        'domain' => 'dongluoji.com',
        'industry_context' => 'GEO服务商',
        'query_type' => '精准查询',
        'query_text' => '哪些 GEO 服务商适合帮助品牌提升 AI 搜索引用率？',
        'competitors' => '',
        'evidence' => '',
        'ai_providers' => array_keys(citation_simulator_ai_providers()),
        'selected_actions' => [],
    ];
}

function citation_simulator_sanitize_form(array $source): array {
    $defaults = citation_simulator_default_form();
    $providers = array_keys(citation_simulator_ai_providers());
    $form = $defaults;
    foreach (['brand_name', 'domain', 'industry_context', 'query_type', 'query_text', 'competitors', 'evidence'] as $key) {
        if (isset($source[$key])) {
            $form[$key] = trim((string) $source[$key]);
        }
    }
    $form['ai_providers'] = is_array($source['ai_providers'] ?? null)
        ? array_values(array_intersect($providers, $source['ai_providers']))
        : $defaults['ai_providers'];
    if (!$form['ai_providers']) {
        $form['ai_providers'] = $defaults['ai_providers'];
    }
    $form['selected_actions'] = is_array($source['selected_actions'] ?? null)
        ? array_values(array_filter(array_map('strval', $source['selected_actions'])))
        : [];
    return $form;
}

function citation_simulator_html($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function citation_simulator_clamp(float $score): float {
    return max(8, min(98, $score));
}

function citation_simulator_text_bonus(string $text, array $needles, float $weight): float {
    $score = 0;
    foreach ($needles as $needle) {
        if ($needle !== '' && mb_stripos($text, $needle) !== false) {
            $score += $weight;
        }
    }
    return $score;
}

function citation_simulator_position(float $score): array {
    if ($score >= 86) return ['opening_block', '高频整段'];
    if ($score >= 72) return ['paragraph', '高频段落'];
    if ($score >= 58) return ['opinion', '观点引用'];
    if ($score >= 42) return ['mention', '轻度提及'];
    return ['reference_only', '未稳定引用'];
}

function citation_simulator_position_weight(string $position): float {
    $weights = [
        'opening_block' => 1.00,
        'paragraph' => 0.80,
        'opinion' => 0.60,
        'mention' => 0.30,
        'reference_only' => 0.10,
    ];
    return $weights[$position] ?? 0.30;
}

function citation_simulator_type_label(string $type): string {
    $map = [
        'brand' => '客户内容',
        'competitor' => '竞品',
        'ugc' => 'UGC',
        'media' => '媒体',
        'review_site' => '评测站',
        'academic' => '权威资料',
        'community' => '社区讨论',
    ];
    return $map[$type] ?? $type;
}

function citation_simulator_category_label(string $category): string {
    $map = [
        'ugc' => 'UGC',
        'pr_media' => '媒体PR',
        'site' => '官网',
        'community' => '社区',
        'academic' => '权威资料',
        'other' => '其他',
    ];
    return $map[$category] ?? $category;
}

function citation_simulator_split_competitors(string $competitors): array {
    $parts = preg_split('/[,，、\n\r]+/u', $competitors) ?: [];
    $parts = array_values(array_filter(array_map('trim', $parts)));
    return $parts ?: ['行业头部服务商', '内容营销服务商', 'AI 搜索优化机构'];
}

function citation_simulator_provider_slice(array $selectedProviders, int $count): array {
    if ($count <= 0) {
        return [];
    }
    return array_slice(array_values($selectedProviders), 0, min($count, count($selectedProviders)));
}

function citation_simulator_source_key(array $citation): string {
    $domain = strtolower(trim((string) ($citation['source_domain'] ?? '')));
    $title = strtolower(trim((string) ($citation['source_title'] ?? '')));
    if ($domain !== '') {
        return $domain . '|' . mb_substr($title, 0, 60);
    }
    return 'title|' . mb_substr($title, 0, 80);
}

function citation_simulator_is_client_source(array $citation, array $brand): bool {
    $brandName = mb_strtolower(trim((string) ($brand['name'] ?? '')));
    $domain = strtolower(trim((string) ($brand['domain'] ?? '')));
    $sourceDomain = strtolower(trim((string) ($citation['source_domain'] ?? '')));
    $sourceUrl = strtolower(trim((string) ($citation['source_url'] ?? '')));
    $title = mb_strtolower((string) ($citation['source_title'] ?? ''));
    $excerpt = mb_strtolower((string) ($citation['raw_excerpt'] ?? ''));

    if ($domain !== '' && ($sourceDomain === $domain || str_ends_with($sourceDomain, '.' . $domain) || str_contains($sourceUrl, $domain))) {
        return true;
    }
    if ($brandName !== '' && (str_contains($title, $brandName) || str_contains($excerpt, $brandName . ' 官网') || str_contains($excerpt, $brandName . ' 官方') || str_contains($excerpt, $brandName . ' 的产品'))) {
        return true;
    }
    return false;
}

function citation_simulator_generate_stub_citations(array $input, array $providers): array {
    $brand = trim((string) ($input['brand_name'] ?? '当前品牌')) ?: '当前品牌';
    $domain = trim((string) ($input['domain'] ?? ''));
    $queryType = trim((string) ($input['query_type'] ?? '精准查询'));
    $evidence = trim((string) ($input['evidence'] ?? ''));
    $competitors = trim((string) ($input['competitors'] ?? ''));
    $combined = mb_strtolower($brand . "\n" . $domain . "\n" . ($input['query_text'] ?? '') . "\n" . $queryType . "\n" . $evidence . "\n" . $competitors);
    $providerKeys = array_values($providers);
    $citations = [];

    $competitorNames = citation_simulator_split_competitors($competitors);
    foreach ($competitorNames as $index => $name) {
        $baseScore = citation_simulator_clamp(82 - ($index * 6) + (($queryType === '对比查询') ? 4 : 0));
        [$position] = citation_simulator_position($baseScore);
        foreach (citation_simulator_provider_slice($providerKeys, max(2, min(6, (int) floor($baseScore / 16)))) as $order => $provider) {
            $citations[] = [
                'ai_provider' => $provider,
                'source_url' => 'https://competitor-' . ($index + 1) . '.com/',
                'source_domain' => 'competitor-' . ($index + 1) . '.com',
                'source_title' => $name,
                'source_type' => 'competitor',
                'citation_position' => $position,
                'raw_excerpt' => $name . ' 经常出现在同类方案推荐中。',
                'order_in_answer' => $order + 1,
                'seed_score' => $baseScore,
                'why' => '同类服务在多个 AI 回答里更容易被放进推荐或对比段落。',
            ];
        }
    }

    $templateSources = [
        ['知乎专栏:GEO 服务商选型指南', 'zhihu.com', 'ugc', 78, '问答和测评内容更贴近 AI 的推荐语料。'],
        ['36氪:AI 搜索优化行业观察', '36kr.com', 'media', 74, '媒体内容具备第三方背书，常被用于解释行业趋势。'],
        ['少数派 SSPAI:AI 营销工具横评', 'sspai.com', 'review_site', 70, '榜单型页面天然适合被 AI 抽取为候选源。'],
        ['艾瑞咨询:AI 搜索优化白皮书', 'report.iresearch.cn', 'academic', 66, '结构化数据和报告语气提升了事实可信度。'],
        ['即刻:AI 营销实操讨论', 'web.okjike.com', 'community', 57, '社区讨论提供真实使用反馈，但稳定性弱于媒体和榜单。'],
    ];
    foreach ($templateSources as $index => $source) {
        $baseScore = citation_simulator_clamp($source[3] + citation_simulator_text_bonus($combined, [$source[0], $source[1]], 2));
        [$position] = citation_simulator_position($baseScore);
        foreach (citation_simulator_provider_slice($providerKeys, max(1, min(6, (int) floor($baseScore / 17)))) as $order => $provider) {
            $citations[] = [
                'ai_provider' => $provider,
                'source_url' => 'https://' . $source[1] . '/',
                'source_domain' => $source[1],
                'source_title' => $source[0],
                'source_type' => $source[2],
                'citation_position' => $position,
                'raw_excerpt' => $source[4],
                'order_in_answer' => $order + 2,
                'seed_score' => $baseScore,
                'why' => $source[4],
            ];
        }
    }

    $clientScore = 42;
    $clientScore += $domain !== '' ? 6 : 0;
    $clientScore += mb_strlen($evidence) >= 80 ? 8 : 0;
    $clientScore += mb_strlen($evidence) >= 180 ? 5 : 0;
    $clientScore += citation_simulator_text_bonus($combined, ['客户案例', '案例', '数据', '白皮书', '报告'], 2.5);
    $clientScore += citation_simulator_text_bonus($combined, ['知乎', '小红书', 'b站', '即刻', '媒体', '36氪', '虎嗅'], 2);
    $clientScore += citation_simulator_text_bonus($combined, ['对比', '推荐', '采购', '榜单', '评测'], 1.8);
    if ($queryType === '对比查询' || $queryType === '采购评估') {
        $clientScore += $competitors !== '' ? 3 : -5;
    }
    $clientScore = round(citation_simulator_clamp($clientScore), 1);
    [$clientPosition] = citation_simulator_position($clientScore);
    foreach (citation_simulator_provider_slice($providerKeys, max(1, min(6, (int) floor($clientScore / 18)))) as $order => $provider) {
        $citations[] = [
            'ai_provider' => $provider,
            'source_url' => $domain !== '' ? 'https://' . $domain . '/' : '',
            'source_domain' => $domain !== '' ? $domain : 'client-domain.example',
            'source_title' => $brand . '官网内容',
            'source_type' => 'brand',
            'citation_position' => $clientPosition,
            'raw_excerpt' => $brand . ' 官网内容可作为该问题的候选来源。',
            'order_in_answer' => $order + 3,
            'seed_score' => $clientScore,
            'why' => '这是根据品牌名、官网域名和已知资料匹配出的客户内容。',
        ];
    }

    return $citations;
}

function citation_simulator_aggregate_ranked_sources(array $citations, array $brand): array {
    $groups = [];
    foreach ($citations as $citation) {
        $key = citation_simulator_source_key($citation);
        if (!isset($groups[$key])) {
            $groups[$key] = [
                'source_key' => $key,
                'source_display_name' => $citation['source_title'] ?? '',
                'source_domain' => $citation['source_domain'] ?? '',
                'source_type' => $citation['source_type'] ?? '',
                'score_accumulator' => 0.0,
                'seed_scores' => [],
                'citation_count' => 0,
                'best_position' => $citation['citation_position'] ?? 'mention',
                'best_position_weight' => 0.0,
                'ai_providers' => [],
                'is_client' => false,
                'why' => $citation['why'] ?? '',
            ];
        }
        $position = (string) ($citation['citation_position'] ?? 'mention');
        $positionWeight = citation_simulator_position_weight($position);
        $orderWeight = max(0.3, 1.0 - (((int) ($citation['order_in_answer'] ?? 1) - 1) * 0.1));
        $groups[$key]['score_accumulator'] += $positionWeight * $orderWeight * 18;
        $groups[$key]['seed_scores'][] = (float) ($citation['seed_score'] ?? 0);
        $groups[$key]['citation_count']++;
        $groups[$key]['ai_providers'][] = (string) ($citation['ai_provider'] ?? '');
        if ($positionWeight > $groups[$key]['best_position_weight']) {
            $groups[$key]['best_position'] = $position;
            $groups[$key]['best_position_weight'] = $positionWeight;
        }
        if (citation_simulator_is_client_source($citation, $brand)) {
            $groups[$key]['is_client'] = true;
            $groups[$key]['source_type'] = 'brand';
        }
    }

    $ranked = [];
    foreach ($groups as $group) {
        $seedAverage = $group['seed_scores'] ? array_sum($group['seed_scores']) / count($group['seed_scores']) : 40;
        $typeBonus = [
            'media' => 4,
            'review_site' => 4,
            'academic' => 3,
            'ugc' => 2,
            'community' => 0,
            'brand' => 0,
            'competitor' => 2,
        ][$group['source_type']] ?? 0;
        $aggregatedScore = citation_simulator_clamp(($seedAverage * 0.72) + ($group['score_accumulator'] * 0.22) + ($group['citation_count'] * 2.4) + $typeBonus);
        [$position, $positionLabel] = citation_simulator_position($aggregatedScore);
        $ranked[] = [
            'source_key' => $group['source_key'],
            'source_display_name' => $group['source_display_name'],
            'source_domain' => $group['source_domain'],
            'source_type' => $group['source_type'],
            'aggregated_score' => round($aggregatedScore, 1),
            'citation_count' => $group['citation_count'],
            'best_position' => $group['best_position'] ?: $position,
            'best_position_label' => $positionLabel,
            'ai_providers' => array_values(array_filter(array_unique($group['ai_providers']))),
            'is_client' => $group['is_client'],
            'why' => $group['why'],
        ];
    }

    usort($ranked, static fn($a, $b) => $b['aggregated_score'] <=> $a['aggregated_score']);
    foreach ($ranked as $index => &$source) {
        $source['rank'] = $index + 1;
    }
    unset($source);
    return $ranked;
}

function citation_simulator_simulate_actions(array $sources, array $selectedActions, array $actionDefs): array {
    $clientSource = null;
    foreach ($sources as $source) {
        if (!empty($source['is_client'])) {
            $clientSource = $source;
            break;
        }
    }
    $startingRank = $clientSource ? (int) $clientSource['rank'] : count($sources);
    $startingScore = $clientSource ? (float) $clientSource['aggregated_score'] : 0;
    $boostTotal = 0.0;
    $estimatedCost = 0.0;
    $estimatedDays = 0;
    foreach ($actionDefs as $action) {
        if (in_array($action['key'], $selectedActions, true)) {
            $boostTotal += (float) $action['base_impact'];
            $estimatedCost += (float) $action['estimated_cost'];
            $estimatedDays = max($estimatedDays, (int) $action['estimated_days']);
        }
    }
    $resultingScore = round(citation_simulator_clamp($startingScore + $boostTotal), 1);
    $scores = [];
    foreach ($sources as $source) {
        $scores[] = !empty($source['is_client']) ? $resultingScore : (float) $source['aggregated_score'];
    }
    rsort($scores, SORT_NUMERIC);
    $resultingRank = array_search($resultingScore, $scores, true);
    $resultingRank = $resultingRank === false ? count($scores) : $resultingRank + 1;
    $topFiveScore = isset($sources[4]) ? (float) $sources[4]['aggregated_score'] : 0;
    $gapToTop5 = $resultingRank <= 5 ? 0 : max(0, round($topFiveScore - $resultingScore + 0.1, 1));

    return [
        'starting_rank' => $startingRank,
        'starting_score' => round($startingScore, 1),
        'boost_total' => round($boostTotal, 1),
        'resulting_score' => $resultingScore,
        'resulting_rank' => $resultingRank,
        'enters_top_5' => $resultingRank <= 5,
        'gap_to_top_5' => $gapToTop5,
        'estimated_cost' => $estimatedCost,
        'estimated_days' => $estimatedDays,
    ];
}

function citation_simulator_build_result(array $input, array $aiProviders, array $actionDefs, ?array $apiConfig = null): array {
    $selectedProviders = is_array($input['ai_providers'] ?? null) ? array_values(array_intersect(array_keys($aiProviders), $input['ai_providers'])) : array_keys($aiProviders);
    if (!$selectedProviders) {
        $selectedProviders = array_keys($aiProviders);
    }
    $selectedActions = is_array($input['selected_actions'] ?? null)
        ? array_values(array_intersect(array_column($actionDefs, 'key'), $input['selected_actions']))
        : [];

    $brand = [
        'name' => trim((string) ($input['brand_name'] ?? '')) ?: '当前品牌',
        'domain' => trim((string) ($input['domain'] ?? '')),
    ];

    $mode = $apiConfig['mode'] ?? 'local';
    if ($apiConfig !== null && $mode === 'real') {
        $configuredSelected = array_values(array_filter(
            $selectedProviders,
            static fn($p) => !empty(($apiConfig['providers'][$p]['configured'] ?? false))
                && (($apiConfig['providers'][$p]['op_status'] ?? 'normal') !== 'disabled')
        ));
        if (!$configuredSelected) {
            throw new RuntimeException('真实反查模式下没有可用的已配置 provider，请先配置并启用至少一个 AI / 搜索源');
        }
        $citations = citation_simulator_real_citations($input, $configuredSelected, $apiConfig);
        $selectedProviders = $configuredSelected;
    } else {
        $citations = citation_simulator_generate_stub_citations($input, $selectedProviders);
    }

    $sources = citation_simulator_aggregate_ranked_sources($citations, $brand);
    $simulation = citation_simulator_simulate_actions($sources, $selectedActions, $actionDefs);

    return [
        'brand' => $brand['name'],
        'domain' => $brand['domain'],
        'query_text' => trim((string) ($input['query_text'] ?? '')),
        'industry' => trim((string) ($input['industry_context'] ?? '')),
        'query_type' => trim((string) ($input['query_type'] ?? '')),
        'ai_providers' => $selectedProviders,
        'selected_actions' => $selectedActions,
        'sources' => $sources,
        'citations' => $citations,
        'client_current_rank' => $simulation['starting_rank'],
        'client_current_score' => $simulation['starting_score'],
        'citation_set_size' => 5,
    ] + $simulation;
}

function citation_simulator_find_or_create_brand(PDO $db, array $formData): string {
    $name = trim((string) ($formData['brand_name'] ?? '')) ?: '当前品牌';
    $domain = trim((string) ($formData['domain'] ?? ''));
    $industry = trim((string) ($formData['industry_context'] ?? ''));
    $stmt = $db->prepare("
        INSERT INTO geo_diagnosis_brands (name, domain, industry, metadata_json)
        VALUES (?, ?, ?, '{}'::jsonb)
        ON CONFLICT (name, domain) DO UPDATE SET
            industry = EXCLUDED.industry,
            updated_at = CURRENT_TIMESTAMP
        RETURNING id
    ");
    $stmt->execute([$name, $domain, $industry]);
    return (string) $stmt->fetchColumn();
}

function citation_simulator_save_result(PDO $db, array $formData, array $result): string {
    $brandId = citation_simulator_find_or_create_brand($db, $formData);
    $stmt = $db->prepare("
        INSERT INTO geo_simulator_queries
            (brand_id, query_text, industry_context, query_type, status, ai_providers_json, result_cache_json, completed_at)
        VALUES (?, ?, ?, ?, 'completed', ?::jsonb, ?::jsonb, CURRENT_TIMESTAMP)
        RETURNING id
    ");
    $stmt->execute([
        $brandId,
        $result['query_text'],
        $result['industry'],
        $result['query_type'],
        json_encode($result['ai_providers'], JSON_UNESCAPED_UNICODE),
        json_encode($result, JSON_UNESCAPED_UNICODE),
    ]);
    $queryId = (string) $stmt->fetchColumn();

    $runStmt = $db->prepare("
        INSERT INTO geo_simulator_runs (query_id, ai_provider, response_text, citation_count, duration_ms)
        VALUES (?, ?, ?, ?, ?)
        ON CONFLICT (query_id, ai_provider) DO UPDATE SET
            response_text = EXCLUDED.response_text,
            citation_count = EXCLUDED.citation_count,
            duration_ms = EXCLUDED.duration_ms,
            completed_at = CURRENT_TIMESTAMP
        RETURNING id
    ");
    $citationStmt = $db->prepare("
        INSERT INTO geo_simulator_citations
            (run_id, source_url, source_domain, source_title, source_type, citation_position, citation_type, is_client_match, raw_excerpt, relevance_score, order_in_answer)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    foreach ($result['ai_providers'] as $provider) {
        $providerCitations = array_values(array_filter($result['citations'], static fn($citation) => ($citation['ai_provider'] ?? '') === $provider));
        $runStmt->execute([$queryId, $provider, 'local MVP simulated response', count($providerCitations), 0]);
        $runId = (string) $runStmt->fetchColumn();
        foreach ($providerCitations as $citation) {
            $citationStmt->execute([
                $runId,
                $citation['source_url'] ?? '',
                $citation['source_domain'] ?? '',
                $citation['source_title'] ?? '',
                $citation['source_type'] ?? '',
                $citation['citation_position'] ?? 'mention',
                $citation['citation_position'] ?? 'mention',
                citation_simulator_is_client_source($citation, ['name' => $result['brand'], 'domain' => $result['domain']]) ? 1 : 0,
                $citation['raw_excerpt'] ?? '',
                $citation['seed_score'] ?? null,
                $citation['order_in_answer'] ?? 1,
            ]);
        }
    }

    $rankStmt = $db->prepare("
        INSERT INTO geo_simulator_ranked_sources
            (query_id, source_key, source_display_name, source_domain, source_type, rank, aggregated_score, citation_count, best_position, is_client, ai_providers, why)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?::jsonb, ?)
    ");
    foreach ($result['sources'] as $source) {
        $rankStmt->execute([
            $queryId,
            $source['source_key'],
            $source['source_display_name'],
            $source['source_domain'],
            $source['source_type'],
            $source['rank'],
            $source['aggregated_score'],
            $source['citation_count'],
            $source['best_position'],
            !empty($source['is_client']) ? 1 : 0,
            json_encode($source['ai_providers'], JSON_UNESCAPED_UNICODE),
            $source['why'] ?? '',
        ]);
    }

    if (!empty($result['selected_actions'])) {
        $simStmt = $db->prepare("
            INSERT INTO geo_simulator_simulations
                (query_id, selected_actions, boost_total, starting_rank, resulting_rank, starting_score, resulting_score, enters_top_5, estimated_cost, estimated_days)
            VALUES (?, ?::jsonb, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $simStmt->execute([
            $queryId,
            json_encode($result['selected_actions'], JSON_UNESCAPED_UNICODE),
            $result['boost_total'],
            $result['client_current_rank'],
            $result['resulting_rank'],
            $result['client_current_score'],
            $result['resulting_score'],
            !empty($result['enters_top_5']) ? 1 : 0,
            $result['estimated_cost'],
            $result['estimated_days'],
        ]);
    }

    return $queryId;
}

// ─── 真实 AI 反查：Kimi + DeepSeek ───────────────────────────────────────────

/**
 * 从数据库取解密后的 provider key（不经过 apiConfig 的 value='' 遮蔽）
 */
function citation_simulator_get_provider_key(string $provider, string $field = 'api_key'): string {
    $settingKey = citation_simulator_api_setting_key($provider, $field);
    $stored = (string) get_setting($settingKey, '');
    if ($stored === '') {
        return '';
    }
    return (string) decrypt_ai_api_key($stored);
}

/**
 * 通用 OpenAI 兼容接口调用（Kimi / DeepSeek 均使用此格式）
 * 返回 AI 回答文本，失败返回 null
 */
function citation_simulator_call_openai_compat(
    string $endpoint,
    string $apiKey,
    string $model,
    string $queryText,
    string $industry,
    int $timeoutSec = 30
): ?string {
    $systemPrompt = '你是一个帮助用户发现优质品牌和服务的专业AI助手。当用户询问某个领域的推荐时，请给出真实、详细的回答，引用具体的品牌名称、网站或内容来源，并说明推荐理由。回答要结构清晰，优先列出你认为最值得推荐的选项。';
    $userPrompt = "请回答以下问题，给出具体的推荐来源：\n\n{$queryText}\n\n行业背景：{$industry}\n\n请列出你认为值得推荐的具体品牌、服务商或内容来源，并说明理由。";

    $payload = json_encode([
        'model' => $model,
        'messages' => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userPrompt],
        ],
        'temperature' => 0.3,
        'max_tokens' => 1200,
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeoutSec,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false || $curlError !== '' || $httpCode !== 200) {
        return null;
    }

    $data = json_decode((string) $response, true);
    return isset($data['choices'][0]['message']['content'])
        ? (string) $data['choices'][0]['message']['content']
        : null;
}

/**
 * 调用 Kimi（月之暗面 moonshot-v1-8k）+ 博查联网上下文
 */
function citation_simulator_call_kimi(string $apiKey, string $queryText, string $industry): ?string {
    return citation_simulator_call_with_search(
        'https://api.moonshot.cn/v1/chat/completions',
        $apiKey,
        'moonshot-v1-8k',
        $queryText,
        $industry,
        'Kimi'
    );
}

/**
 * 用博查获取实时搜索上下文，注入到 prompt 里
 * 返回格式化后的上下文字符串（空字符串表示博查未配置或调用失败）
 */
function citation_simulator_search_context(string $queryText): string {
    $bochaKey = citation_simulator_get_provider_key('bocha');
    if ($bochaKey === '') {
        return '';
    }
    $searchText = citation_simulator_call_bocha($bochaKey, $queryText);
    return $searchText !== null
        ? "\n\n以下是联网搜索结果，请基于这些真实信息回答：\n" . $searchText
        : '';
}

/**
 * 带联网上下文的 OpenAI 兼容调用（博查搜索 → 注入 prompt → LLM 作答）
 */
function citation_simulator_call_with_search(
    string $endpoint,
    string $apiKey,
    string $model,
    string $queryText,
    string $industry,
    string $persona = 'AI助手',
    int $timeoutSec = 40
): ?string {
    $searchContext = citation_simulator_search_context($queryText);
    $systemPrompt = "你是{$persona}。你已联网搜索并获取了实时信息。请基于搜索结果，给出结构清晰的回答，明确引用具体来源网站和品牌名称。";
    $userPrompt = $queryText . "\n\n行业背景：{$industry}" . $searchContext;

    $payload = json_encode([
        'model'       => $model,
        'messages'    => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user',   'content' => $userPrompt],
        ],
        'temperature' => 0.3,
        'max_tokens'  => 1200,
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeoutSec,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response = curl_exec($ch);
    $httpCode  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $httpCode !== 200) {
        return null;
    }

    $data = json_decode((string) $response, true);
    return isset($data['choices'][0]['message']['content'])
        ? (string) $data['choices'][0]['message']['content']
        : null;
}

/**
 * 调用 DeepSeek（deepseek-chat）+ 博查联网上下文
 */
function citation_simulator_call_deepseek(string $apiKey, string $queryText, string $industry): ?string {
    return citation_simulator_call_with_search(
        'https://api.deepseek.com/chat/completions',
        $apiKey,
        'deepseek-chat',
        $queryText,
        $industry,
        'DeepSeek AI'
    );
}

/**
 * 调用腾讯元宝（混元 hunyuan-turbos）+ 博查联网上下文
 */
function citation_simulator_call_yuanbao(string $apiKey, string $queryText, string $industry): ?string {
    return citation_simulator_call_with_search(
        'https://api.hunyuan.cloud.tencent.com/v1/chat/completions',
        $apiKey,
        'hunyuan-turbos',
        $queryText,
        $industry,
        '腾讯元宝'
    );
}

/**
 * 调用文心一言（百度千帆，ERNIE-Speed-128K）+ 博查联网上下文
 */
function citation_simulator_call_wenxin(string $apiKey, string $queryText, string $industry): ?string {
    return citation_simulator_call_with_search(
        'https://qianfan.baidubce.com/v2/chat/completions',
        $apiKey,
        'ernie-speed-128k',
        $queryText,
        $industry,
        '文心一言'
    );
}

/**
 * 调用通义千问（阿里 DashScope，qwen-plus）+ 博查联网上下文
 */
function citation_simulator_call_tongyi(string $apiKey, string $queryText, string $industry): ?string {
    return citation_simulator_call_with_search(
        'https://dashscope.aliyuncs.com/compatible-mode/v1/chat/completions',
        $apiKey,
        'qwen-plus',
        $queryText,
        $industry,
        '通义千问'
    );
}

/**
 * 调用豆包（Volcengine Ark）+ 博查联网上下文，模拟手机端联网模式
 */
function citation_simulator_call_doubao(string $apiKey, string $queryText, string $industry): ?string {
    return citation_simulator_call_with_search(
        'https://ark.cn-beijing.volces.com/api/v3/chat/completions',
        $apiKey,
        'doubao-pro-32k',
        $queryText,
        $industry,
        '豆包'
    );
}

/**
 * 调用博查 Web Search API，将搜索结果拼装为可解析的文本
 */
function citation_simulator_call_bocha(string $apiKey, string $queryText, int $timeoutSec = 20): ?string {
    $payload = json_encode([
        'query'     => $queryText,
        'count'     => 10,
        'freshness' => 'noLimit',
        'summary'   => false,
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init('https://api.bochaai.com/v1/web-search');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_TIMEOUT        => $timeoutSec,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ],
    ]);
    $raw = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false || $raw === '' || $httpCode !== 200) {
        return null;
    }

    $data = json_decode((string) $raw, true);
    $items = (array) ($data['data']['webPages']['value'] ?? []);
    if (empty($items)) {
        return null;
    }

    // 把搜索结果拼成自然语言，让 parse_real_response 能提取域名/品牌
    $lines = [];
    foreach ($items as $item) {
        $title   = trim((string) ($item['name'] ?? ''));
        $url     = trim((string) ($item['url'] ?? ''));
        $snippet = trim((string) ($item['snippet'] ?? ''));
        if ($title === '' && $url === '') {
            continue;
        }
        $lines[] = "- {$title}（{$url}）：{$snippet}";
    }

    return implode("\n", $lines);
}

/**
 * 解析 AI 回答文本 → 提取引用对象（与 stub citation 格式完全一致）
 */
function citation_simulator_parse_real_response(string $responseText, string $provider, array $input): array {
    $brand = trim((string) ($input['brand_name'] ?? '')) ?: '当前品牌';
    $domain = trim((string) ($input['domain'] ?? ''));
    $competitors = citation_simulator_split_competitors(trim((string) ($input['competitors'] ?? '')));
    $lowerResponse = mb_strtolower($responseText);
    $totalLen = max(mb_strlen($responseText), 1);
    $citations = [];
    $orderCounter = 1;

    // 根据在回答中的位置比例换算分数（越靠前越高）
    $pos_to_score = static function (int $pos) use ($totalLen): float {
        $ratio = $pos / $totalLen;
        if ($ratio < 0.15) return 80.0;
        if ($ratio < 0.35) return 68.0;
        if ($ratio < 0.60) return 54.0;
        return 42.0;
    };

    // 客户品牌：无论是否被提及，都输出一条 client 行
    $lowerBrand = mb_strtolower($brand);
    $brandPos = mb_strpos($lowerResponse, $lowerBrand);
    $domainPos = $domain !== '' ? mb_strpos($lowerResponse, mb_strtolower($domain)) : false;
    $clientMentioned = $brandPos !== false || $domainPos !== false;
    if ($clientMentioned) {
        $bestPos = $brandPos !== false ? (int) $brandPos : (int) $domainPos;
        $score = round($pos_to_score($bestPos), 1);
        [$position] = citation_simulator_position($score);
        $excerptStart = max(0, $bestPos - 40);
        $citations[] = [
            'ai_provider' => $provider,
            'source_url' => $domain !== '' ? 'https://' . $domain . '/' : '',
            'source_domain' => $domain !== '' ? $domain : 'client-domain.example',
            'source_title' => $brand . '（真实引用）',
            'source_type' => 'brand',
            'citation_position' => $position,
            'raw_excerpt' => mb_substr($responseText, $excerptStart, 180),
            'order_in_answer' => $orderCounter++,
            'seed_score' => $score,
            'why' => '真实 AI 回答中明确提到了该品牌。',
            'real_query' => true,
        ];
    } else {
        // 品牌未被提及：分数极低，如实反映
        $citations[] = [
            'ai_provider' => $provider,
            'source_url' => $domain !== '' ? 'https://' . $domain . '/' : '',
            'source_domain' => $domain !== '' ? $domain : 'client-domain.example',
            'source_title' => $brand . '（未被引用）',
            'source_type' => 'brand',
            'citation_position' => 'reference_only',
            'raw_excerpt' => '此次真实 AI 回答中未提及该品牌。这是真实反查结果，不是模拟估算。',
            'order_in_answer' => 99,
            'seed_score' => 18.0,
            'why' => '真实 AI 回答中未出现品牌名或域名，说明当前品牌尚未进入该 AI 的引用集合。',
            'real_query' => true,
        ];
    }

    // 竞品
    foreach ($competitors as $idx => $competitor) {
        if (trim($competitor) === '') continue;
        $lowerComp = mb_strtolower($competitor);
        $compPos = mb_strpos($lowerResponse, $lowerComp);
        if ($compPos === false) continue;
        $score = round($pos_to_score((int) $compPos) + 4, 1); // 竞品在 AI 推荐中通常分略高
        [$position] = citation_simulator_position($score);
        $excerptStart = max(0, (int) $compPos - 40);
        $citations[] = [
            'ai_provider' => $provider,
            'source_url' => '',
            'source_domain' => 'competitor-' . ($idx + 1) . '.com',
            'source_title' => $competitor,
            'source_type' => 'competitor',
            'citation_position' => $position,
            'raw_excerpt' => mb_substr($responseText, $excerptStart, 180),
            'order_in_answer' => $orderCounter++,
            'seed_score' => $score,
            'why' => '真实 AI 回答中提到了该竞品。',
            'real_query' => true,
        ];
    }

    // 已知高价值平台/来源
    $knownSources = [
        ['知乎', 'zhihu.com', 'ugc', '问答和测评内容更贴近 AI 的推荐语料。'],
        ['36氪', '36kr.com', 'media', '媒体内容具备第三方背书，常被用于解释行业趋势。'],
        ['虎嗅', 'huxiu.com', 'media', '媒体内容具备第三方背书，常被用于解释行业趋势。'],
        ['CSDN', 'csdn.net', 'tech_blog', '技术内容对 DeepSeek/Kimi 有较强引用偏好。'],
        ['掘金', 'juejin.cn', 'tech_blog', '技术内容对 DeepSeek/Kimi 有较强引用偏好。'],
        ['小红书', 'xiaohongshu.com', 'ugc', '消费决策类内容对消费品牌有价值。'],
        ['GitHub', 'github.com', 'tech_code', '工程类内容对 DeepSeek 极其友好。'],
        ['微信公众号', 'weixin.qq.com', 'wechat', '深度内容和品牌自有阵地。'],
        ['百度百科', 'baike.baidu.com', 'encyclopedia', '权威知识来源，文心一言优先引用。'],
        ['维基百科', 'wikipedia.org', 'encyclopedia', '权威百科来源，提升可信度。'],
    ];
    foreach ($knownSources as $src) {
        $srcPos = mb_strpos($lowerResponse, mb_strtolower($src[0]));
        if ($srcPos === false) continue;
        $score = round($pos_to_score((int) $srcPos) + 2, 1);
        [$position] = citation_simulator_position($score);
        $excerptStart = max(0, (int) $srcPos - 40);
        $citations[] = [
            'ai_provider' => $provider,
            'source_url' => 'https://' . $src[1] . '/',
            'source_domain' => $src[1],
            'source_title' => $src[0] . ' 相关内容',
            'source_type' => $src[2],
            'citation_position' => $position,
            'raw_excerpt' => mb_substr($responseText, $excerptStart, 180),
            'order_in_answer' => $orderCounter++,
            'seed_score' => $score,
            'why' => $src[3],
            'real_query' => true,
        ];
    }

    return $citations;
}

/**
 * 真实反查入口：只对已配置的 provider 发起 API 调用；真实模式不再静默降级为本地估算
 * 返回格式与 citation_simulator_generate_stub_citations() 完全一致
 */
function citation_simulator_real_citations(array $input, array $selectedProviders, array $apiConfig): array {
    $queryText = trim((string) ($input['query_text'] ?? ''));
    $industry = trim((string) ($input['industry_context'] ?? ''));
    $citations = [];

    foreach ($selectedProviders as $provider) {
        $providerCfg = $apiConfig['providers'][$provider] ?? [];
        $configured = !empty($providerCfg['configured']);

        if (!$configured) {
            throw new RuntimeException("真实反查 provider 未配置：{$provider}");
        }

        $apiKey = citation_simulator_get_provider_key($provider);
        if ($apiKey === '') {
            throw new RuntimeException("真实反查 provider 缺少 API Key：{$provider}");
        }

        $responseText = null;
        if ($provider === 'kimi') {
            $responseText = citation_simulator_call_kimi($apiKey, $queryText, $industry);
        } elseif ($provider === 'deepseek') {
            $responseText = citation_simulator_call_deepseek($apiKey, $queryText, $industry);
        } elseif ($provider === 'yuanbao') {
            $responseText = citation_simulator_call_yuanbao($apiKey, $queryText, $industry);
        } elseif ($provider === 'wenxin') {
            $responseText = citation_simulator_call_wenxin($apiKey, $queryText, $industry);
        } elseif ($provider === 'tongyi') {
            $responseText = citation_simulator_call_tongyi($apiKey, $queryText, $industry);
        } elseif ($provider === 'doubao') {
            $responseText = citation_simulator_call_doubao($apiKey, $queryText, $industry);
        } elseif ($provider === 'bocha') {
            $responseText = citation_simulator_call_bocha($apiKey, $queryText);
        }

        if ($responseText === null) {
            throw new RuntimeException("真实反查调用失败：{$provider}");
        }

        $parsed = citation_simulator_parse_real_response($responseText, $provider, $input);
        foreach ($parsed as $c) {
            $citations[] = $c;
        }

    }

    if (empty($citations)) {
        throw new RuntimeException('真实反查完成，但没有解析到可用引用源；请检查模型回答或搜索源返回内容');
    }

    return $citations;
}
