<?php
/**
 * 国内媒体分发服务
 */

if (!defined('FEISHU_TREASURE')) {
    die('Access denied');
}

function distribution_platforms(): array {
    return [
        'website_media' => ['name' => '网站媒体资源', 'icon' => 'globe-2', 'url' => '', 'media_type' => 'website'],
        'portal_tencent' => ['name' => '腾讯网', 'icon' => 'globe-2', 'url' => '', 'media_type' => 'website', 'portal_source' => 'tencent'],
        'portal_sina' => ['name' => '新浪网', 'icon' => 'globe-2', 'url' => '', 'media_type' => 'website', 'portal_source' => 'sina'],
        'portal_netease' => ['name' => '网易网', 'icon' => 'globe-2', 'url' => '', 'media_type' => 'website', 'portal_source' => 'netease'],
        'portal_sohu' => ['name' => '搜狐网', 'icon' => 'globe-2', 'url' => '', 'media_type' => 'website', 'portal_source' => 'sohu'],
        'portal_ifeng' => ['name' => '凤凰网', 'icon' => 'globe-2', 'url' => '', 'media_type' => 'website', 'portal_source' => 'ifeng'],
        'portal_people' => ['name' => '人民网', 'icon' => 'globe-2', 'url' => '', 'media_type' => 'website', 'portal_source' => 'people'],
        'portal_cctv' => ['name' => '央视网', 'icon' => 'globe-2', 'url' => '', 'media_type' => 'website', 'portal_source' => 'cctv'],
        'portal_cnr' => ['name' => '中国广播网', 'icon' => 'globe-2', 'url' => '', 'media_type' => 'website', 'portal_source' => 'cnr'],
        'portal_chinanews' => ['name' => '中国新闻网', 'icon' => 'globe-2', 'url' => '', 'media_type' => 'website', 'portal_source' => 'chinanews'],
        'portal_xinhuanet' => ['name' => '新华网', 'icon' => 'globe-2', 'url' => '', 'media_type' => 'website', 'portal_source' => 'xinhuanet'],
        'portal_china_daily' => ['name' => '中国日报网', 'icon' => 'globe-2', 'url' => '', 'media_type' => 'website', 'portal_source' => 'china_daily'],
        'portal_gmw' => ['name' => '光明网', 'icon' => 'globe-2', 'url' => '', 'media_type' => 'website', 'portal_source' => 'gmw'],
        'portal_youth' => ['name' => '中国青年网', 'icon' => 'globe-2', 'url' => '', 'media_type' => 'website', 'portal_source' => 'youth'],
        'portal_huanqiu' => ['name' => '环球网', 'icon' => 'globe-2', 'url' => '', 'media_type' => 'website', 'portal_source' => 'huanqiu'],
        'portal_qianlong' => ['name' => '千龙网', 'icon' => 'globe-2', 'url' => '', 'media_type' => 'website', 'portal_source' => 'qianlong'],
        'portal_beiqing' => ['name' => '北青网', 'icon' => 'globe-2', 'url' => '', 'media_type' => 'website', 'portal_source' => 'beiqing'],
        'portal_ce' => ['name' => '中国经济网', 'icon' => 'globe-2', 'url' => '', 'media_type' => 'website', 'portal_source' => 'ce'],
        'portal_cri' => ['name' => '国际在线', 'icon' => 'globe-2', 'url' => '', 'media_type' => 'website', 'portal_source' => 'cri'],
        'portal_hexun' => ['name' => '和讯网', 'icon' => 'globe-2', 'url' => '', 'media_type' => 'website', 'portal_source' => 'hexun'],
        'portal_china' => ['name' => '中国网', 'icon' => 'globe-2', 'url' => '', 'media_type' => 'website', 'portal_source' => 'china'],
        'portal_zhonghua' => ['name' => '中华网', 'icon' => 'globe-2', 'url' => '', 'media_type' => 'website', 'portal_source' => 'zhonghua'],
        'portal_eastday' => ['name' => '东方网', 'icon' => 'globe-2', 'url' => '', 'media_type' => 'website', 'portal_source' => 'eastday'],
        'portal_dzwww' => ['name' => '大众网', 'icon' => 'globe-2', 'url' => '', 'media_type' => 'website', 'portal_source' => 'dzwww'],
        'portal_haiwai' => ['name' => '海外媒体', 'icon' => 'globe-2', 'url' => '', 'media_type' => 'website', 'portal_source' => 'haiwai'],
        'portal_client' => ['name' => '客户端', 'icon' => 'globe-2', 'url' => '', 'media_type' => 'website', 'portal_source' => 'client'],
        'portal_baijia' => ['name' => '官方百家号', 'icon' => 'globe-2', 'url' => '', 'media_type' => 'website', 'portal_source' => 'baijia'],
        'portal_other' => ['name' => '其他门户', 'icon' => 'globe-2', 'url' => '', 'media_type' => 'website', 'portal_source' => 'other_portal'],
        'zhihu' => ['name' => '知乎', 'icon' => 'message-circle', 'url' => 'https://www.zhihu.com/creator', 'media_type' => 'qa'],
        'csdn' => ['name' => 'CSDN', 'icon' => 'code-2', 'url' => 'https://mp.csdn.net/', 'media_type' => 'self_media'],
        'juejin' => ['name' => '掘金', 'icon' => 'pen-tool', 'url' => 'https://juejin.cn/', 'media_type' => 'self_media'],
        'feishu' => ['name' => '飞书', 'icon' => 'send', 'url' => 'https://www.feishu.cn/', 'media_type' => 'owned'],
        'sohu' => ['name' => '搜狐号', 'icon' => 'newspaper', 'url' => 'https://mp.sohu.com/', 'media_type' => 'self_media'],
        'toutiao' => ['name' => '头条号', 'icon' => 'radio', 'url' => 'https://mp.toutiao.com/', 'media_type' => 'self_media'],
        'baijiahao' => ['name' => '百家号', 'icon' => 'badge-check', 'url' => 'https://baijiahao.baidu.com/', 'media_type' => 'self_media'],
        'wechat' => ['name' => '微信公众号', 'icon' => 'messages-square', 'url' => 'https://mp.weixin.qq.com/', 'media_type' => 'self_media'],
        'short_video_resource' => ['name' => '短视频资源', 'icon' => 'video', 'url' => '', 'media_type' => 'short_video'],
        'ghostwriting_resource' => ['name' => '代写资源', 'icon' => 'pen-tool', 'url' => '', 'media_type' => 'ghostwriting'],
    ];
}

function distribution_media_types(): array {
    return [
        '' => '全部资源',
        'website' => '网站媒体',
        'self_media' => '自媒体',
        'short_video' => '短视频',
        'qa' => '问答',
        'ghostwriting' => '代写',
        'owned' => '自有阵地',
    ];
}

function distribution_filter_options(): array {
    return [
        'industry' => [
            '' => '不限',
            'it' => 'IT科技',
            'game' => '游戏网站',
            'finance' => '财经商业',
            'auto' => '汽车网站',
            'entertainment' => '娱乐休闲',
            'news' => '新闻资讯',
            'health' => '健康医疗',
            'home' => '房产家居',
            'parenting' => '亲子母婴',
            'education' => '教育培训',
            'food' => '食品餐饮',
            'travel' => '酒店旅游',
            'fashion' => '女性时尚',
            'lifestyle' => '生活消费',
            'public_welfare' => '公益',
            'sports' => '体育运动',
            'trade' => '工业贸易',
            'culture' => '文化艺术',
            'package' => '套餐系列',
            'flash_sale' => '最新秒杀',
            'crypto' => '区块链',
            'other' => '其他',
        ],
        'portal_source' => [
            '' => '不限',
            'tencent' => '腾讯网',
            'sina' => '新浪网',
            'netease' => '网易网',
            'sohu' => '搜狐网',
            'ifeng' => '凤凰网',
            'people' => '人民网',
            'cctv' => '央视网',
            'cnr' => '中国广播网',
            'chinanews' => '中国新闻网',
            'xinhuanet' => '新华网',
            'china_daily' => '中国日报网',
            'gmw' => '光明网',
            'youth' => '中国青年网',
            'huanqiu' => '环球网',
            'qianlong' => '千龙网',
            'beiqing' => '北青网',
            'ce' => '中国经济网',
            'cri' => '国际在线',
            'hexun' => '和讯网',
            'china' => '中国网',
            'zhonghua' => '中华网',
            'eastday' => '东方网',
            'dzwww' => '大众网',
            'haiwai' => '海外媒体',
            'client' => '客户端',
            'baijia' => '官方百家号',
            'other_portal' => '其他门户',
        ],
        'region' => [
            '' => '不限',
            'national' => '综合全国',
            'beijing' => '北京',
            'shanghai' => '上海',
            'guangdong' => '广东',
            'jiangsu' => '江苏',
            'zhejiang' => '浙江',
            'hunan' => '湖南',
            'hubei' => '湖北',
            'fujian' => '福建',
            'jiangxi' => '江西',
            'anhui' => '安徽',
            'henan' => '河南',
            'hebei' => '河北',
            'shandong' => '山东',
            'shanxi' => '山西',
            'guizhou' => '贵州',
            'sichuan' => '四川',
            'qinghai' => '青海',
            'xizang' => '西藏',
            'liaoning' => '辽宁',
            'jilin' => '吉林',
            'shaanxi' => '陕西',
            'gansu' => '甘肃',
            'ningxia' => '宁夏',
            'heilongjiang' => '黑龙江',
            'neimenggu' => '内蒙古',
            'yunnan' => '云南',
            'xinjiang' => '新疆',
            'hk_mo_tw' => '港澳台',
            'overseas' => '海外',
        ],
        'entry_level' => [
            '' => '不限',
            'none' => '没有入口',
            'home' => '首页入口',
            'channel' => '频道入口',
            'premium' => '上级入口',
        ],
        'index_status' => [
            '' => '不限',
            'page_excluded' => '不包网页收录',
            'page_included' => '包网页收录',
            'news_excluded' => '不包资讯收录',
            'news_included' => '包资讯收录',
        ],
        'publish_speed' => [
            '' => '不限',
            '1h' => '1小时',
            '2h' => '2小时',
            '12h' => '12小时',
            'today' => '当日',
            'next_day' => '次日',
            '48h' => '48小时以上',
        ],
        'link_type' => [
            '' => '不限',
            'none' => '不可带网址',
            'url' => '可带网址',
            'focus_image' => '焦点图',
        ],
        'geo_rank' => [
            '' => '不限',
            '1' => '可发GEO排名',
        ],
    ];
}

function ensure_distribution_schema(PDO $db): void {
    $db->exec("
        CREATE TABLE IF NOT EXISTS media_accounts (
            id BIGSERIAL PRIMARY KEY,
            platform VARCHAR(50) NOT NULL,
            media_type VARCHAR(50) DEFAULT 'self_media',
            portal_source VARCHAR(80) DEFAULT '',
            entry_level VARCHAR(40) DEFAULT '',
            index_status VARCHAR(40) DEFAULT '',
            account_name VARCHAR(120) NOT NULL,
            username VARCHAR(200) DEFAULT '',
            credential TEXT DEFAULT '',
            login_url VARCHAR(500) DEFAULT '',
            publish_mode VARCHAR(30) DEFAULT 'browser',
            industry VARCHAR(80) DEFAULT '',
            region VARCHAR(80) DEFAULT '',
            publish_speed VARCHAR(40) DEFAULT '',
            link_type VARCHAR(40) DEFAULT '',
            price_amount NUMERIC(10, 2) DEFAULT 0,
            can_geo_rank INTEGER DEFAULT 0,
            status VARCHAR(20) DEFAULT 'active',
            notes TEXT DEFAULT '',
            last_used_at TIMESTAMP DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS media_publish_jobs (
            id BIGSERIAL PRIMARY KEY,
            article_id BIGINT NOT NULL,
            account_id BIGINT NOT NULL,
            platform VARCHAR(50) NOT NULL,
            status VARCHAR(20) DEFAULT 'queued',
            title VARCHAR(300) DEFAULT '',
            payload TEXT DEFAULT '',
            remote_url VARCHAR(500) DEFAULT '',
            error_message TEXT DEFAULT '',
            scheduled_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            started_at TIMESTAMP DEFAULT NULL,
            finished_at TIMESTAMP DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (article_id) REFERENCES articles(id) ON DELETE CASCADE,
            FOREIGN KEY (account_id) REFERENCES media_accounts(id) ON DELETE CASCADE
        )
    ");

    $columnsToAdd = [
        'media_type' => "ALTER TABLE media_accounts ADD COLUMN media_type VARCHAR(50) DEFAULT 'self_media'",
        'portal_source' => "ALTER TABLE media_accounts ADD COLUMN portal_source VARCHAR(80) DEFAULT ''",
        'entry_level' => "ALTER TABLE media_accounts ADD COLUMN entry_level VARCHAR(40) DEFAULT ''",
        'index_status' => "ALTER TABLE media_accounts ADD COLUMN index_status VARCHAR(40) DEFAULT ''",
        'industry' => "ALTER TABLE media_accounts ADD COLUMN industry VARCHAR(80) DEFAULT ''",
        'region' => "ALTER TABLE media_accounts ADD COLUMN region VARCHAR(80) DEFAULT ''",
        'publish_speed' => "ALTER TABLE media_accounts ADD COLUMN publish_speed VARCHAR(40) DEFAULT ''",
        'link_type' => "ALTER TABLE media_accounts ADD COLUMN link_type VARCHAR(40) DEFAULT ''",
        'price_amount' => "ALTER TABLE media_accounts ADD COLUMN price_amount NUMERIC(10, 2) DEFAULT 0",
        'can_geo_rank' => "ALTER TABLE media_accounts ADD COLUMN can_geo_rank INTEGER DEFAULT 0",
    ];
    foreach ($columnsToAdd as $column => $sql) {
        if (!db_column_exists($db, 'media_accounts', $column)) {
            $db->exec($sql);
        }
    }

    $db->exec("CREATE INDEX IF NOT EXISTS idx_media_accounts_platform ON media_accounts(platform, status)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_media_accounts_filters ON media_accounts(media_type, portal_source, industry, region, status)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_media_publish_jobs_article ON media_publish_jobs(article_id, created_at DESC)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_media_publish_jobs_status ON media_publish_jobs(status, created_at DESC)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_media_publish_jobs_article_account ON media_publish_jobs(article_id, account_id)");

    $db->exec("
        CREATE TABLE IF NOT EXISTS geo_materials (
            id BIGSERIAL PRIMARY KEY,
            material_type VARCHAR(40) NOT NULL,
            customer_id VARCHAR(80) DEFAULT '',
            title VARCHAR(300) NOT NULL,
            content_json TEXT NOT NULL DEFAULT '{}',
            status VARCHAR(20) DEFAULT 'active',
            notes TEXT DEFAULT '',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_geo_materials_type ON geo_materials(material_type, status)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_geo_materials_customer ON geo_materials(customer_id)");

    distribution_seed_b2b_accounts($db);
}

function distribution_seed_b2b_accounts(PDO $db): void {
    $count = (int) $db->query("SELECT COUNT(*) FROM media_accounts")->fetchColumn();
    if ($count > 0) {
        return;
    }

    $seeds = [
        [
            'platform' => 'zhihu',
            'account_name' => '董逻辑MGEO · 知乎专栏',
            'username' => 'dongluoji_mgeo',
            'login_url' => 'https://www.zhihu.com/creator',
            'publish_mode' => 'browser',
            'media_type' => 'qa',
            'industry' => 'it',
            'region' => 'national',
            'publish_speed' => 'today',
            'link_type' => 'url',
            'can_geo_rank' => 1,
            'price_amount' => 0,
            'notes' => '知乎专栏，B2B SaaS核心信源。适合深度问答内容，AI引用权重高。',
        ],
        [
            'platform' => 'csdn',
            'account_name' => '董逻辑MGEO · CSDN博客',
            'username' => 'dongluoji_mgeo',
            'login_url' => 'https://mp.csdn.net/',
            'publish_mode' => 'browser',
            'media_type' => 'self_media',
            'industry' => 'it',
            'region' => 'national',
            'publish_speed' => 'today',
            'link_type' => 'url',
            'can_geo_rank' => 1,
            'price_amount' => 0,
            'notes' => 'CSDN技术博客，开发者和企业技术决策者覆盖广，适合技术选型类内容。',
        ],
        [
            'platform' => 'wechat',
            'account_name' => '董逻辑MGEO · 公众号',
            'username' => 'dongluoji_mgeo',
            'login_url' => 'https://mp.weixin.qq.com/',
            'publish_mode' => 'manual',
            'media_type' => 'self_media',
            'industry' => 'it',
            'region' => 'national',
            'publish_speed' => 'next_day',
            'link_type' => 'url',
            'can_geo_rank' => 0,
            'price_amount' => 0,
            'notes' => '微信公众号，品牌自有阵地。需人工排版发布，适合行业洞察和月报类内容。',
        ],
        [
            'platform' => 'juejin',
            'account_name' => '董逻辑MGEO · 掘金',
            'username' => 'dongluoji_mgeo',
            'login_url' => 'https://juejin.cn/',
            'publish_mode' => 'browser',
            'media_type' => 'self_media',
            'industry' => 'it',
            'region' => 'national',
            'publish_speed' => 'today',
            'link_type' => 'url',
            'can_geo_rank' => 1,
            'price_amount' => 0,
            'notes' => '掘金技术社区，开发者聚集，适合工程实践和方法论类文章。',
        ],
    ];

    $stmt = $db->prepare("
        INSERT INTO media_accounts (
            platform, media_type, portal_source, entry_level, index_status,
            account_name, username, credential, login_url, publish_mode,
            industry, region, publish_speed, link_type,
            price_amount, can_geo_rank, status, notes, updated_at
        ) VALUES (?, ?, '', '', '', ?, ?, '', ?, ?, ?, 'national', ?, ?, ?, ?, 'active', ?, CURRENT_TIMESTAMP)
    ");
    foreach ($seeds as $s) {
        $stmt->execute([
            $s['platform'],
            $s['media_type'],
            $s['account_name'],
            $s['username'],
            $s['login_url'],
            $s['publish_mode'],
            $s['industry'],
            $s['publish_speed'],
            $s['link_type'],
            $s['price_amount'],
            $s['can_geo_rank'],
            $s['notes'],
        ]);
    }
}

function distribution_geo_material_types(): array {
    return [
        'brand_entity_card' => '品牌实体卡',
        'citation_evidence' => '引用证据包',
        'competitor_file'   => '竞品档案',
    ];
}

function distribution_get_geo_materials(PDO $db, string $type = '', string $customerId = ''): array {
    $where  = ['1=1'];
    $params = [];
    if ($type !== '') {
        $where[]  = 'material_type = ?';
        $params[] = $type;
    }
    if ($customerId !== '') {
        $where[]  = 'customer_id = ?';
        $params[] = $customerId;
    }
    $stmt = $db->prepare('SELECT * FROM geo_materials WHERE ' . implode(' AND ', $where) . ' ORDER BY created_at DESC LIMIT 100');
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function distribution_save_geo_material(PDO $db, array $input): int {
    $id      = (int)    ($input['material_id']   ?? 0);
    $type    = (string) ($input['material_type'] ?? 'brand_entity_card');
    $title   = trim((string) ($input['title']   ?? ''));
    $notes   = trim((string) ($input['notes']   ?? ''));
    $custId  = trim((string) ($input['customer_id'] ?? ''));

    if ($title === '') {
        throw new InvalidArgumentException('素材标题不能为空');
    }

    // Build content JSON from type-specific fields
    $content = [];
    switch ($type) {
        case 'brand_entity_card':
            $content = [
                'domain'      => trim((string) ($input['brand_domain']      ?? '')),
                'tagline'     => trim((string) ($input['brand_tagline']     ?? '')),
                'description' => trim((string) ($input['brand_description'] ?? '')),
                'founded'     => trim((string) ($input['brand_founded']     ?? '')),
                'key_facts'   => array_values(array_filter(array_map('trim', explode("\n", (string) ($input['brand_key_facts'] ?? ''))))),
            ];
            break;
        case 'citation_evidence':
            $content = [
                'source_url'     => trim((string) ($input['evidence_url']     ?? '')),
                'source_name'    => trim((string) ($input['evidence_source']  ?? '')),
                'evidence_type'  => trim((string) ($input['evidence_type']    ?? 'media_report')),
                'publish_date'   => trim((string) ($input['evidence_date']    ?? '')),
            ];
            break;
        case 'competitor_file':
            $content = [
                'domain'     => trim((string) ($input['competitor_domain']     ?? '')),
                'strengths'  => trim((string) ($input['competitor_strengths']  ?? '')),
                'weaknesses' => trim((string) ($input['competitor_weaknesses'] ?? '')),
            ];
            break;
    }

    $contentJson = json_encode($content, JSON_UNESCAPED_UNICODE);

    if ($id > 0) {
        $stmt = $db->prepare('UPDATE geo_materials SET material_type=?, title=?, content_json=?, notes=?, customer_id=?, updated_at=CURRENT_TIMESTAMP WHERE id=?');
        $stmt->execute([$type, $title, $contentJson, $notes, $custId, $id]);
        return $id;
    }
    $stmt = $db->prepare('INSERT INTO geo_materials (material_type, title, content_json, notes, customer_id) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$type, $title, $contentJson, $notes, $custId]);
    return (int) $db->lastInsertId();
}

function distribution_delete_geo_material(PDO $db, int $id): void {
    $stmt = $db->prepare('DELETE FROM geo_materials WHERE id = ?');
    $stmt->execute([$id]);
}

function distribution_job_update(PDO $db, int $jobId, string $status, string $remoteUrl = '', string $errorMessage = ''): void {
    if (!in_array($status, ['queued', 'running', 'success', 'failed'], true)) {
        $status = 'queued';
    }

    $startedAtSql = $status === 'running' ? ', started_at = CURRENT_TIMESTAMP' : '';
    $finishedAtSql = in_array($status, ['success', 'failed'], true) ? ', finished_at = CURRENT_TIMESTAMP' : '';
    $stmt = $db->prepare("
        UPDATE media_publish_jobs
        SET status = ?,
            remote_url = ?,
            error_message = ?,
            updated_at = CURRENT_TIMESTAMP
            {$startedAtSql}
            {$finishedAtSql}
        WHERE id = ?
    ");
    $stmt->execute([$status, $remoteUrl, $errorMessage, $jobId]);
}

function distribution_claim_publish_job(PDO $db, int $jobId): bool {
    $stmt = $db->prepare("
        UPDATE media_publish_jobs
        SET status = 'running',
            error_message = '',
            updated_at = CURRENT_TIMESTAMP,
            started_at = CURRENT_TIMESTAMP
        WHERE id = ?
          AND status IN ('queued', 'failed')
    ");
    $stmt->execute([$jobId]);
    return $stmt->rowCount() > 0;
}

function distribution_get_publish_job(PDO $db, int $jobId): ?array {
    $stmt = $db->prepare("
        SELECT
            j.*,
            a.title AS article_title,
            a.slug AS article_slug,
            a.excerpt AS article_excerpt,
            a.content AS article_content,
            ma.account_name,
            ma.username,
            ma.credential,
            ma.login_url,
            ma.publish_mode
        FROM media_publish_jobs j
        LEFT JOIN articles a ON j.article_id = a.id
        LEFT JOIN media_accounts ma ON j.account_id = ma.id
        WHERE j.id = ?
        LIMIT 1
    ");
    $stmt->execute([$jobId]);
    $job = $stmt->fetch(PDO::FETCH_ASSOC);
    return $job ?: null;
}

function distribution_mask_secret(string $value): string {
    $value = trim($value);
    if ($value === '') {
        return '未填写';
    }

    $length = mb_strlen($value);
    if ($length <= 4) {
        return str_repeat('*', $length);
    }

    return mb_substr($value, 0, 2) . str_repeat('*', min(8, $length - 4)) . mb_substr($value, -2);
}

function distribution_infer_media_type(string $platform, string $accountName = ''): string {
    $platforms = distribution_platforms();
    if (isset($platforms[$platform]['media_type'])) {
        return (string) $platforms[$platform]['media_type'];
    }

    $name = mb_strtolower(trim($accountName));
    if ($name === '') {
        return 'self_media';
    }

    $rules = [
        'website' => ['网', '新闻网', '日报', '门户', '媒体'],
        'short_video' => ['视频', '抖音', '快手', 'b站'],
        'qa' => ['知乎', '问答', '知道'],
        'owned' => ['官网', '企业站', '自有', '小程序'],
        'ghostwriting' => ['代写', '写手', '撰稿'],
        'self_media' => ['公众号', '头条号', '百家号', '搜狐号', '自媒体'],
    ];

    foreach ($rules as $type => $keywords) {
        foreach ($keywords as $keyword) {
            if (mb_strpos($name, $keyword) !== false) {
                return $type;
            }
        }
    }

    return 'self_media';
}

function distribution_infer_portal_source(string $platform, string $portalSource = '', string $accountName = ''): string {
    if ($portalSource !== '') {
        return $portalSource;
    }

    $platforms = distribution_platforms();
    if (!empty($platforms[$platform]['portal_source'])) {
        return (string) $platforms[$platform]['portal_source'];
    }

    $name = mb_strtolower(trim($accountName));
    $portalMap = [
        'tencent' => ['腾讯'],
        'sina' => ['新浪'],
        'netease' => ['网易'],
        'sohu' => ['搜狐'],
        'ifeng' => ['凤凰'],
        'people' => ['人民'],
        'cctv' => ['央视'],
        'cnr' => ['广播'],
        'xinhuanet' => ['新华'],
        'gmw' => ['光明'],
    ];

    foreach ($portalMap as $key => $keywords) {
        foreach ($keywords as $keyword) {
            if (mb_strpos($name, $keyword) !== false) {
                return $key;
            }
        }
    }

    return '';
}

function distribution_save_account(PDO $db, array $input): int {
    $id = (int) ($input['account_id'] ?? 0);
    $platform = trim((string) ($input['platform'] ?? ''));
    $accountName = trim((string) ($input['account_name'] ?? ''));
    $username = trim((string) ($input['username'] ?? ''));
    $password = trim((string) ($input['password'] ?? ''));
    $loginUrl = trim((string) ($input['login_url'] ?? ''));
    $publishMode = trim((string) ($input['publish_mode'] ?? 'browser'));
    $mediaType = trim((string) ($input['media_type'] ?? ''));
    $portalSource = trim((string) ($input['portal_source'] ?? ''));
    $entryLevel = trim((string) ($input['entry_level'] ?? ''));
    $indexStatus = trim((string) ($input['index_status'] ?? ''));
    $industry = trim((string) ($input['industry'] ?? ''));
    $region = trim((string) ($input['region'] ?? ''));
    $publishSpeed = trim((string) ($input['publish_speed'] ?? ''));
    $linkType = trim((string) ($input['link_type'] ?? ''));
    $priceAmount = max(0, (float) ($input['price_amount'] ?? 0));
    $canGeoRank = !empty($input['can_geo_rank']) ? 1 : 0;
    $notes = trim((string) ($input['notes'] ?? ''));
    $status = trim((string) ($input['status'] ?? 'active'));

    if ($platform === '' || $accountName === '') {
        throw new InvalidArgumentException('请选择平台并填写账号名称');
    }

    if (!isset(distribution_platforms()[$platform])) {
        throw new InvalidArgumentException('暂不支持该媒体平台');
    }

    if (!in_array($publishMode, ['browser', 'api', 'manual'], true)) {
        $publishMode = 'browser';
    }

    if (!in_array($status, ['active', 'inactive'], true)) {
        $status = 'active';
    }

    $explicitType = isset(distribution_media_types()[$mediaType]) && $mediaType !== '' ? $mediaType : '';
    $platformDefaultType = distribution_platforms()[$platform]['media_type'] ?? 'self_media';
    $nameInferredType = distribution_infer_media_type('', $accountName);

    // 明确门户强制归到网站媒体；通用资源平台优先按名称关键词归类；其余按平台默认。
    if (str_starts_with($platform, 'portal_')) {
        $mediaType = 'website';
    } elseif (in_array($platform, ['website_media', 'short_video_resource', 'ghostwriting_resource'], true)) {
        $mediaType = $nameInferredType;
    } else {
        $mediaType = $platformDefaultType;
    }

    if ($explicitType !== '') {
        $mediaType = $explicitType;
    }
    $portalSource = distribution_infer_portal_source($platform, $portalSource, $accountName);

    if ($id > 0) {
        if ($password !== '') {
            $stmt = $db->prepare("
                UPDATE media_accounts
                SET platform = ?, media_type = ?, portal_source = ?, entry_level = ?, index_status = ?, account_name = ?, username = ?, credential = ?, login_url = ?,
                    publish_mode = ?, industry = ?, region = ?, publish_speed = ?, link_type = ?,
                    price_amount = ?, can_geo_rank = ?, status = ?, notes = ?, updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            $stmt->execute([$platform, $mediaType, $portalSource, $entryLevel, $indexStatus, $accountName, $username, encrypt_sensitive_value($password), $loginUrl, $publishMode, $industry, $region, $publishSpeed, $linkType, $priceAmount, $canGeoRank, $status, $notes, $id]);
        } else {
            $stmt = $db->prepare("
                UPDATE media_accounts
                SET platform = ?, media_type = ?, portal_source = ?, entry_level = ?, index_status = ?, account_name = ?, username = ?, login_url = ?,
                    publish_mode = ?, industry = ?, region = ?, publish_speed = ?, link_type = ?,
                    price_amount = ?, can_geo_rank = ?, status = ?, notes = ?, updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            $stmt->execute([$platform, $mediaType, $portalSource, $entryLevel, $indexStatus, $accountName, $username, $loginUrl, $publishMode, $industry, $region, $publishSpeed, $linkType, $priceAmount, $canGeoRank, $status, $notes, $id]);
        }

        return $id;
    }

    $stmt = $db->prepare("
        INSERT INTO media_accounts (
            platform, media_type, portal_source, entry_level, index_status, account_name, username, credential, login_url, publish_mode,
            industry, region, publish_speed, link_type, price_amount, can_geo_rank, status, notes, updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
    ");
    $stmt->execute([$platform, $mediaType, $portalSource, $entryLevel, $indexStatus, $accountName, $username, encrypt_sensitive_value($password), $loginUrl, $publishMode, $industry, $region, $publishSpeed, $linkType, $priceAmount, $canGeoRank, $status, $notes]);

    return function_exists('db_last_insert_id') ? db_last_insert_id($db, 'media_accounts') : (int) $db->lastInsertId();
}

function distribution_get_accounts(PDO $db, array $filters = []): array {
    $where = [];
    $params = [];

    foreach (['media_type', 'portal_source', 'industry', 'region', 'entry_level', 'index_status', 'publish_speed', 'link_type'] as $field) {
        if (!empty($filters[$field])) {
            $where[] = "{$field} = ?";
            $params[] = $filters[$field];
        }
    }

    if (($filters['geo_rank'] ?? '') === '1') {
        $where[] = 'can_geo_rank = 1';
    }

    if (!empty($filters['search'])) {
        $where[] = '(account_name LIKE ? OR username LIKE ? OR notes LIKE ?)';
        $params[] = '%' . $filters['search'] . '%';
        $params[] = '%' . $filters['search'] . '%';
        $params[] = '%' . $filters['search'] . '%';
    }

    $whereSql = empty($where) ? '' : 'WHERE ' . implode(' AND ', $where);
    $stmt = $db->prepare("
        SELECT *
        FROM media_accounts
        {$whereSql}
        ORDER BY status ASC, can_geo_rank DESC, price_amount ASC, platform ASC, id DESC
    ");
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function distribution_get_account(PDO $db, int $id): ?array {
    $stmt = $db->prepare("SELECT * FROM media_accounts WHERE id = ?");
    $stmt->execute([$id]);
    $account = $stmt->fetch(PDO::FETCH_ASSOC);
    return $account ?: null;
}

function distribution_enqueue_article_jobs(PDO $db, int $articleId, array $accountIds): array {
    $articleStmt = $db->prepare("
        SELECT id, title, slug, excerpt, content, keywords, meta_description, status
        FROM articles
        WHERE id = ? AND deleted_at IS NULL
    ");
    $articleStmt->execute([$articleId]);
    $article = $articleStmt->fetch(PDO::FETCH_ASSOC);
    if (!$article) {
        throw new InvalidArgumentException('文章不存在或已删除');
    }

    $accountIds = array_values(array_unique(array_map('intval', $accountIds)));
    if (empty($accountIds)) {
        $activeStmt = $db->query("SELECT id FROM media_accounts WHERE status = 'active'");
        $accountIds = array_map('intval', $activeStmt ? $activeStmt->fetchAll(PDO::FETCH_COLUMN) : []);
    }

    if (empty($accountIds)) {
        throw new InvalidArgumentException('请先配置至少一个启用中的媒体账号');
    }

    $accountStmt = $db->prepare("SELECT id, platform FROM media_accounts WHERE id = ? AND status = 'active'");
    $existingStmt = $db->prepare("SELECT id FROM media_publish_jobs WHERE article_id = ? AND account_id = ? LIMIT 1");
    $insert = $db->prepare("
        INSERT INTO media_publish_jobs (
            article_id, account_id, platform, status, title, payload, scheduled_at, updated_at
        ) VALUES (?, ?, ?, 'queued', ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
    ");

    $payload = json_encode([
        'title' => $article['title'],
        'slug' => $article['slug'],
        'excerpt' => $article['excerpt'],
        'content' => $article['content'],
        'keywords' => $article['keywords'],
        'meta_description' => $article['meta_description'],
    ], JSON_UNESCAPED_UNICODE);

    $createdJobIds = [];
    foreach ($accountIds as $accountId) {
        $accountStmt->execute([$accountId]);
        $account = $accountStmt->fetch(PDO::FETCH_ASSOC);
        if (!$account) {
            continue;
        }

        $existingStmt->execute([$articleId, $accountId]);
        if ($existingStmt->fetchColumn()) {
            continue;
        }

        $insert->execute([$articleId, $accountId, $account['platform'], $article['title'], $payload]);
        $createdJobIds[] = function_exists('db_last_insert_id') ? db_last_insert_id($db, 'media_publish_jobs') : (int) $db->lastInsertId();
    }

    return $createdJobIds;
}

function distribution_enqueue_article(PDO $db, int $articleId, array $accountIds): int {
    return count(distribution_enqueue_article_jobs($db, $articleId, $accountIds));
}

/**
 * 文章发布成功后，自动把文章关键词注入到 geo_monitor_keywords 监测队列。
 * 调用时机：distribution.php 中 run_job / mark_job 状态变为 success 时。
 *
 * @return int 新增的关键词条数（已存在的跳过）
 */
function geo_monitor_inject_article_keywords(PDO $db, int $articleId, string $customerId, string $publishedUrl = ''): int {
    if ($customerId === '' || $articleId <= 0) return 0;

    $stmt = $db->prepare("SELECT keywords, original_keyword FROM articles WHERE id = ? AND deleted_at IS NULL");
    $stmt->execute([$articleId]);
    $article = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$article) return 0;

    $keywords = [];
    // original_keyword 是生成时用的核心词
    $orig = trim((string) ($article['original_keyword'] ?? ''));
    if ($orig !== '') $keywords[] = $orig;
    // keywords 字段是逗号分隔的 SEO 关键词
    foreach (array_map('trim', explode(',', (string) ($article['keywords'] ?? ''))) as $kw) {
        if ($kw !== '') $keywords[] = $kw;
    }
    $keywords = array_unique(array_filter(array_map(fn($k) => mb_substr(trim($k), 0, 60), $keywords)));

    if (empty($keywords)) return 0;

    $upsert = $db->prepare("
        INSERT INTO geo_monitor_keywords (customer_id, keyword, article_id, source_url)
        VALUES (?, ?, ?, ?)
        ON CONFLICT (customer_id, keyword) DO UPDATE SET
            article_id = COALESCE(geo_monitor_keywords.article_id, EXCLUDED.article_id),
            source_url = CASE
                WHEN geo_monitor_keywords.source_url = '' THEN EXCLUDED.source_url
                ELSE geo_monitor_keywords.source_url
            END
    ");

    $inserted = 0;
    foreach ($keywords as $kw) {
        try {
            $upsert->execute([$customerId, $kw, $articleId, $publishedUrl]);
            $inserted++;
        } catch (Throwable $e) {
            // 忽略重复插入冲突以外的错误
        }
    }
    return $inserted;
}

function distribution_job_meta(string $status): array {
    return match ($status) {
        'running' => ['label' => '执行中', 'class' => 'bg-blue-100 text-blue-800 border border-blue-200'],
        'success' => ['label' => '已发布', 'class' => 'bg-green-100 text-green-800 border border-green-200'],
        'failed' => ['label' => '失败', 'class' => 'bg-red-100 text-red-800 border border-red-200'],
        default => ['label' => '待发布', 'class' => 'bg-amber-100 text-amber-800 border border-amber-200'],
    };
}

function distribution_get_recent_jobs(PDO $db, int $limit = 50): array {
    $stmt = $db->prepare("
        SELECT j.*, a.title AS article_title, ma.account_name
        FROM media_publish_jobs j
        LEFT JOIN articles a ON j.article_id = a.id
        LEFT JOIN media_accounts ma ON j.account_id = ma.id
        ORDER BY j.created_at DESC
        LIMIT ?
    ");
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function distribution_get_stats(PDO $db): array {
    return [
        'accounts' => (int) $db->query("SELECT COUNT(*) FROM media_accounts")->fetchColumn(),
        'active_accounts' => (int) $db->query("SELECT COUNT(*) FROM media_accounts WHERE status = 'active'")->fetchColumn(),
        'queued' => (int) $db->query("SELECT COUNT(*) FROM media_publish_jobs WHERE status = 'queued'")->fetchColumn(),
        'success' => (int) $db->query("SELECT COUNT(*) FROM media_publish_jobs WHERE status = 'success'")->fetchColumn(),
        'failed' => (int) $db->query("SELECT COUNT(*) FROM media_publish_jobs WHERE status = 'failed'")->fetchColumn(),
    ];
}
