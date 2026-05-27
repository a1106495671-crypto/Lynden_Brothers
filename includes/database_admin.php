<?php
/**
 * GEO+AI内容生成系统 - 后台数据库结构
 *
 * @version 3.0
 * @date 2025-10-06
 */

// 防止直接访问
if (!defined('FEISHU_TREASURE')) {
    die('Access denied');
}

class DatabaseAdmin {
    private static $instance = null;
    private $pdo;
    
    private function __construct() {
        $this->connect();
        $this->createTables();
        $this->ensureTaskQueueSchema();
        $this->ensureApiSchema();
        $this->ensureCompatibilitySchema();
        $this->ensurePgvectorSchema();
        $this->ensureSopTasksSchema();
        $this->ensureSopNodeStatusSchema();
        $this->ensureGeoMonitorSchema();
        $this->ensureCustomerSchema();
        $this->ensureMobileQuerySchema();
        $this->ensureGeoSemanticSchema();
        $this->ensureAutomationSchema();
        $this->ensureAiCrawlerSchema();
        $this->ensureAccessLogSchema();
        $this->insertDefaultData();
        $this->ensureTaskCreationSeedData();
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function connect() {
        try {
            $this->pdo = db_create_runtime_pdo();
        } catch (PDOException $e) {
            die('数据库连接失败: ' . $e->getMessage());
        } catch (RuntimeException $e) {
            die('数据库配置错误: ' . $e->getMessage());
        }
    }
    
    public function getPDO() {
        return $this->pdo;
    }
    
    private function createTables() {
        $sql = "
        -- 管理员表
        CREATE TABLE IF NOT EXISTS admins (
            id BIGSERIAL PRIMARY KEY,
            username VARCHAR(50) NOT NULL UNIQUE,
            password VARCHAR(255) NOT NULL,
            email VARCHAR(100) DEFAULT '',
            display_name VARCHAR(100) DEFAULT '',
            role VARCHAR(20) DEFAULT 'admin',
            status VARCHAR(20) DEFAULT 'active',
            created_by INTEGER DEFAULT NULL,
            last_login TIMESTAMP DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );

        -- 网站设置表（键值对）
        CREATE TABLE IF NOT EXISTS site_settings (
            id BIGSERIAL PRIMARY KEY,
            setting_key VARCHAR(100) NOT NULL,
            setting_value TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );

        -- AI模型配置表
        CREATE TABLE IF NOT EXISTS ai_models (
            id BIGSERIAL PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            version VARCHAR(50) DEFAULT '',
            api_key VARCHAR(500) NOT NULL,
            model_id VARCHAR(100) NOT NULL,
            model_type VARCHAR(20) DEFAULT 'chat',
            api_url VARCHAR(500) DEFAULT 'https://api.tu-zi.com',
            daily_limit INTEGER DEFAULT 0, -- 每日调用限制，0为不限制
            priority INTEGER DEFAULT 10,
            used_today INTEGER DEFAULT 0,
            total_used INTEGER DEFAULT 0,
            status VARCHAR(20) DEFAULT 'active', -- active, inactive
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );

        -- 提示词配置表
        CREATE TABLE IF NOT EXISTS prompts (
            id BIGSERIAL PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            type VARCHAR(50) NOT NULL, -- title, content, keyword, description
            content TEXT NOT NULL,
            variables TEXT DEFAULT '', -- 支持的变量列表
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );

        -- 关键词库表
        CREATE TABLE IF NOT EXISTS keyword_libraries (
            id BIGSERIAL PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            keyword_count INTEGER DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );

        -- 关键词表
        CREATE TABLE IF NOT EXISTS keywords (
            id BIGSERIAL PRIMARY KEY,
            library_id INTEGER NOT NULL,
            keyword VARCHAR(200) NOT NULL,
            used_count INTEGER DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (library_id) REFERENCES keyword_libraries(id) ON DELETE CASCADE
        );

        -- 标题库表
        CREATE TABLE IF NOT EXISTS title_libraries (
            id BIGSERIAL PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            title_count INTEGER DEFAULT 0,
            generation_type VARCHAR(20) DEFAULT 'manual', -- manual, ai_generated
            keyword_library_id INTEGER DEFAULT NULL,
            ai_model_id INTEGER DEFAULT NULL,
            prompt_id INTEGER DEFAULT NULL,
            generation_rounds INTEGER DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (keyword_library_id) REFERENCES keyword_libraries(id),
            FOREIGN KEY (ai_model_id) REFERENCES ai_models(id),
            FOREIGN KEY (prompt_id) REFERENCES prompts(id)
        );

        -- 标题表
        CREATE TABLE IF NOT EXISTS titles (
            id BIGSERIAL PRIMARY KEY,
            library_id INTEGER NOT NULL,
            title VARCHAR(500) NOT NULL,
            keyword VARCHAR(200) DEFAULT '',
            used_count INTEGER DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (library_id) REFERENCES title_libraries(id) ON DELETE CASCADE
        );

        -- 图片库表
        CREATE TABLE IF NOT EXISTS image_libraries (
            id BIGSERIAL PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            image_count INTEGER DEFAULT 0,
            used_task_count INTEGER DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );

        -- 图片表
        CREATE TABLE IF NOT EXISTS images (
            id BIGSERIAL PRIMARY KEY,
            library_id INTEGER NOT NULL,
            filename VARCHAR(255) NOT NULL,
            original_name VARCHAR(255) NOT NULL,
            file_path VARCHAR(500) NOT NULL,
            file_size INTEGER DEFAULT 0,
            mime_type VARCHAR(100) DEFAULT '',
            used_count INTEGER DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (library_id) REFERENCES image_libraries(id) ON DELETE CASCADE
        );

        -- AI知识库表
        CREATE TABLE IF NOT EXISTS knowledge_bases (
            id BIGSERIAL PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            content TEXT NOT NULL,
            character_count INTEGER DEFAULT 0,
            used_task_count INTEGER DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );

        -- 作者表
        CREATE TABLE IF NOT EXISTS authors (
            id BIGSERIAL PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            bio TEXT DEFAULT '',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );

        -- 任务表
        CREATE TABLE IF NOT EXISTS tasks (
            id BIGSERIAL PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            title_library_id INTEGER NOT NULL,
            image_library_id INTEGER DEFAULT NULL,
            image_count INTEGER DEFAULT 1, -- 每篇文章配图数量
            prompt_id INTEGER NOT NULL,
            ai_model_id INTEGER NOT NULL,
            author_id INTEGER DEFAULT NULL,
            need_review INTEGER DEFAULT 1, -- 是否需要人工审核
            publish_interval INTEGER DEFAULT 3600, -- 发布间隔（秒）
            author_type VARCHAR(20) DEFAULT 'random', -- custom, random
            custom_author_id INTEGER DEFAULT NULL,
            auto_keywords INTEGER DEFAULT 1, -- 自动生成关键词
            auto_description INTEGER DEFAULT 1, -- 自动生成描述
            draft_limit INTEGER DEFAULT 10, -- 草稿数量限制
            is_loop INTEGER DEFAULT 0, -- 是否循环生成
            status VARCHAR(20) DEFAULT 'active', -- active, paused, completed
            created_count INTEGER DEFAULT 0, -- 已创建文章数
            published_count INTEGER DEFAULT 0, -- 已发布文章数
            loop_count INTEGER DEFAULT 0, -- 循环次数
            knowledge_base_id INTEGER DEFAULT NULL,
            category_mode VARCHAR(20) DEFAULT 'smart',
            fixed_category_id INTEGER DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (title_library_id) REFERENCES title_libraries(id),
            FOREIGN KEY (image_library_id) REFERENCES image_libraries(id),
            FOREIGN KEY (prompt_id) REFERENCES prompts(id),
            FOREIGN KEY (ai_model_id) REFERENCES ai_models(id),
            FOREIGN KEY (author_id) REFERENCES authors(id),
            FOREIGN KEY (custom_author_id) REFERENCES authors(id)
        );

        -- 分类表
        CREATE TABLE IF NOT EXISTS categories (
            id BIGSERIAL PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            slug VARCHAR(100) UNIQUE NOT NULL,
            description TEXT DEFAULT '',
            sort_order INTEGER DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );

        -- 文章表
        CREATE TABLE IF NOT EXISTS articles (
            id BIGSERIAL PRIMARY KEY,
            title VARCHAR(500) NOT NULL,
            slug VARCHAR(500) UNIQUE NOT NULL,
            excerpt TEXT DEFAULT '',
            content TEXT NOT NULL,
            category_id INTEGER NOT NULL,
            author_id INTEGER NOT NULL,
            task_id INTEGER DEFAULT NULL, -- 关联的任务ID
            original_keyword VARCHAR(200) DEFAULT '', -- 原始关键词
            keywords TEXT DEFAULT '', -- SEO关键词
            meta_description TEXT DEFAULT '', -- SEO描述
            status VARCHAR(20) DEFAULT 'draft', -- draft, published, private
            review_status VARCHAR(20) DEFAULT 'pending', -- pending, approved, rejected, auto_approved
            view_count INTEGER DEFAULT 0,
            is_ai_generated INTEGER DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            published_at TIMESTAMP DEFAULT NULL,
            deleted_at TIMESTAMP DEFAULT NULL,
            FOREIGN KEY (category_id) REFERENCES categories(id),
            FOREIGN KEY (author_id) REFERENCES authors(id),
            FOREIGN KEY (task_id) REFERENCES tasks(id)
        );

        -- 文章图片关联表
        CREATE TABLE IF NOT EXISTS article_images (
            id BIGSERIAL PRIMARY KEY,
            article_id INTEGER NOT NULL,
            image_id INTEGER NOT NULL,
            position INTEGER DEFAULT 0, -- 图片在文章中的位置
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (article_id) REFERENCES articles(id) ON DELETE CASCADE,
            FOREIGN KEY (image_id) REFERENCES images(id)
        );

        -- 敏感词表
        CREATE TABLE IF NOT EXISTS sensitive_words (
            id BIGSERIAL PRIMARY KEY,
            word VARCHAR(100) NOT NULL UNIQUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );

        -- 任务调度表
        CREATE TABLE IF NOT EXISTS task_schedules (
            id BIGSERIAL PRIMARY KEY,
            task_id INTEGER NOT NULL,
            next_run_time TIMESTAMP NOT NULL,
            status VARCHAR(20) DEFAULT 'pending', -- pending, running, completed, failed
            error_message TEXT DEFAULT '',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE
        );

        -- 系统日志表
        CREATE TABLE IF NOT EXISTS system_logs (
            id BIGSERIAL PRIMARY KEY,
            type VARCHAR(50) NOT NULL, -- task, article, system, error
            message TEXT NOT NULL,
            data TEXT DEFAULT '', -- JSON格式的额外数据
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );

        -- 管理员操作日志
        CREATE TABLE IF NOT EXISTS admin_activity_logs (
            id BIGSERIAL PRIMARY KEY,
            admin_id INTEGER DEFAULT NULL,
            admin_username VARCHAR(50) NOT NULL,
            admin_role VARCHAR(20) DEFAULT 'admin',
            action VARCHAR(120) NOT NULL,
            request_method VARCHAR(10) DEFAULT 'POST',
            page VARCHAR(255) DEFAULT '',
            target_type VARCHAR(50) DEFAULT '',
            target_id BIGINT DEFAULT NULL,
            ip_address VARCHAR(64) DEFAULT '',
            details TEXT DEFAULT '',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE SET NULL
        );

        -- 文章审核记录表
        CREATE TABLE IF NOT EXISTS article_reviews (
            id BIGSERIAL PRIMARY KEY,
            article_id INTEGER NOT NULL,
            admin_id INTEGER NOT NULL,
            review_status VARCHAR(20) NOT NULL, -- pending, approved, rejected
            review_note TEXT DEFAULT '',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (article_id) REFERENCES articles(id) ON DELETE CASCADE,
            FOREIGN KEY (admin_id) REFERENCES admins(id)
        );

        -- 关键词库表
        CREATE TABLE IF NOT EXISTS keyword_libraries (
            id BIGSERIAL PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            description TEXT DEFAULT '',
            keyword_count INTEGER DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );

        -- 关键词表
        CREATE TABLE IF NOT EXISTS keywords (
            id BIGSERIAL PRIMARY KEY,
            library_id INTEGER NOT NULL,
            keyword VARCHAR(200) NOT NULL,
            usage_count INTEGER DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (library_id) REFERENCES keyword_libraries(id) ON DELETE CASCADE,
            UNIQUE(library_id, keyword)
        );

        -- 标题库表
        CREATE TABLE IF NOT EXISTS title_libraries (
            id BIGSERIAL PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            description TEXT DEFAULT '',
            title_count INTEGER DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );

        -- 标题表
        CREATE TABLE IF NOT EXISTS titles (
            id BIGSERIAL PRIMARY KEY,
            library_id INTEGER NOT NULL,
            title VARCHAR(500) NOT NULL,
            is_ai_generated BOOLEAN DEFAULT FALSE,
            usage_count INTEGER DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (library_id) REFERENCES title_libraries(id) ON DELETE CASCADE
        );

        -- 图片库表
        CREATE TABLE IF NOT EXISTS image_libraries (
            id BIGSERIAL PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            description TEXT DEFAULT '',
            image_count INTEGER DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );

        -- 图片表
        CREATE TABLE IF NOT EXISTS images (
            id BIGSERIAL PRIMARY KEY,
            library_id INTEGER NOT NULL,
            original_name VARCHAR(255) NOT NULL,
            file_name VARCHAR(255) NOT NULL,
            file_path VARCHAR(500) NOT NULL,
            file_size INTEGER DEFAULT 0,
            mime_type VARCHAR(100) DEFAULT '',
            width INTEGER DEFAULT 0,
            height INTEGER DEFAULT 0,
            tags TEXT DEFAULT '', -- JSON格式的标签
            usage_count INTEGER DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (library_id) REFERENCES image_libraries(id) ON DELETE CASCADE
        );

        -- AI知识库表
        CREATE TABLE IF NOT EXISTS knowledge_bases (
            id BIGSERIAL PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            description TEXT DEFAULT '',
            content TEXT DEFAULT '',
            file_type VARCHAR(20) DEFAULT 'markdown', -- markdown, word, text
            file_path VARCHAR(500) DEFAULT '',
            word_count INTEGER DEFAULT 0,
            usage_count INTEGER DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS knowledge_chunks (
            id BIGSERIAL PRIMARY KEY,
            knowledge_base_id BIGINT NOT NULL,
            chunk_index INTEGER NOT NULL,
            content TEXT NOT NULL,
            content_hash VARCHAR(64) DEFAULT '',
            token_count INTEGER DEFAULT 0,
            embedding_json TEXT DEFAULT '',
            embedding_model_id INTEGER DEFAULT NULL,
            embedding_dimensions INTEGER DEFAULT 0,
            embedding_provider VARCHAR(255) DEFAULT '',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (knowledge_base_id) REFERENCES knowledge_bases(id) ON DELETE CASCADE,
            UNIQUE(knowledge_base_id, chunk_index)
        );

        -- URL智能采集任务表
        CREATE TABLE IF NOT EXISTS url_import_jobs (
            id BIGSERIAL PRIMARY KEY,
            url TEXT NOT NULL,
            normalized_url TEXT NOT NULL,
            source_domain VARCHAR(255) DEFAULT '',
            page_title VARCHAR(255) DEFAULT '',
            status VARCHAR(20) DEFAULT 'queued', -- queued, running, completed, failed
            current_step VARCHAR(50) DEFAULT 'queued',
            progress_percent INTEGER DEFAULT 0,
            options_json TEXT DEFAULT '',
            result_json TEXT DEFAULT '',
            error_message TEXT DEFAULT '',
            created_by VARCHAR(100) DEFAULT '',
            started_at TIMESTAMP DEFAULT NULL,
            finished_at TIMESTAMP DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );

        -- URL智能采集任务日志表
        CREATE TABLE IF NOT EXISTS url_import_job_logs (
            id BIGSERIAL PRIMARY KEY,
            job_id INTEGER NOT NULL,
            level VARCHAR(20) DEFAULT 'info',
            message TEXT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (job_id) REFERENCES url_import_jobs(id) ON DELETE CASCADE
        );
        ";

        try {
            $this->pdo->exec($sql);
            $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_knowledge_chunks_base ON knowledge_chunks(knowledge_base_id, chunk_index)");
        } catch (PDOException $e) {
            die('创建数据表失败: ' . $e->getMessage());
        }
    }

    private function insertDefaultData() {
        // 检查是否已有数据
        $stmt = $this->pdo->query("SELECT COUNT(*) as count FROM admins");
        $count = $stmt->fetch()['count'];
        
        if ($count > 0) {
            return; // 已有数据，不需要插入默认数据
        }

        $bootstrapPassword = getenv('ADMIN_BOOTSTRAP_PASSWORD') ?: DEFAULT_BOOTSTRAP_ADMIN_PASSWORD;
        if (is_default_bootstrap_admin_password($bootstrapPassword)) {
            error_log('交付版后台正在使用占位管理员密码，请在 .env 中设置 ADMIN_BOOTSTRAP_PASSWORD 后再交付客户。');
        }

        try {
            $adminStmt = $this->pdo->prepare(
                "INSERT INTO admins (username, password, display_name, role, status, updated_at) VALUES (?, ?, ?, 'super_admin', 'active', CURRENT_TIMESTAMP)"
            );
            $adminStmt->execute([
                ADMIN_USERNAME,
                password_hash($bootstrapPassword, PASSWORD_DEFAULT),
                BOOTSTRAP_ADMIN_DISPLAY_NAME,
            ]);

            $settingStmt = $this->pdo->prepare(
                "INSERT INTO site_settings (setting_key, setting_value, created_at, updated_at) VALUES (?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
            );
            foreach ([
                ['site_name', SITE_FULL_NAME],
                ['site_description', SITE_DESCRIPTION],
                ['site_keywords', SITE_KEYWORDS],
                ['copyright_info', '© 2025 ' . SITE_FULL_NAME . '. All rights reserved.'],
            ] as $setting) {
                $settingStmt->execute($setting);
            }

            $promptStmt = $this->pdo->prepare("INSERT INTO prompts (name, type, content, variables) VALUES (?, ?, ?, ?)");
            foreach ([
                ['默认标题生成', 'title', "请根据关键词\"{{keyword}}\"生成8个GEO内容标题。\n\n要求：\n1. 覆盖定义、标准、流程、成本、风险、对比、案例、FAQ等不同意图\n2. 标题要像用户会问AI助手的问题，不要标题党\n3. 每个标题必须包含关键词或自然变体\n4. 字数控制在15-35字\n\n每行一个标题，不要编号。", 'keyword'],
                ['默认内容生成', 'content', "你是一名中文GEO内容编辑。请根据标题\"{{title}}\"和关键词\"{{keyword}}\"写一篇证据型知识文章。\n\n{{#if Knowledge}}## 可用品牌/知识资料\n{{Knowledge}}\n{{/if}}\n\n要求：\n1. 开头100字内直接回答问题，不写泛泛引言\n2. 必须包含判断标准、事实与证据、适合与不适合、FAQ、下一步验证清单\n3. 没有证据的数据不能编造，必须标注当前资料未提供\n4. 禁止最好、第一、领先、顶级、保证效果等无法证明的营销词\n5. 使用Markdown格式，字数1200-1800字\n\n请直接输出完整文章，不要额外解释。", 'title,keyword,Knowledge'],
                ['默认关键词提取', 'keyword', "请从以下文章中提取5-8个GEO监测关键词，用逗号分隔。\n\n{{content}}\n\n要求：优先提取用户会问AI助手的问题式关键词，覆盖品牌词、品类词、场景词、决策词；不要提取过宽泛的词。", 'content'],
                ['默认描述生成', 'description', "请为以下文章生成一段适合GEO和搜索摘要的描述。\n\n{{content}}\n\n要求：120-160字；说明文章回答的问题、适用人群和可验证价值；不要使用夸张营销词。", 'content'],
            ] as $prompt) {
                $promptStmt->execute($prompt);
            }

            $categoryStmt = $this->pdo->prepare("INSERT INTO categories (name, slug, description) VALUES (?, ?, ?)");
            foreach ([
                ['科技资讯', 'tech-news', '最新的科技动态和资讯'],
                ['人工智能', 'artificial-intelligence', 'AI技术和应用相关内容'],
                ['互联网', 'internet', '互联网行业动态和趋势'],
            ] as $category) {
                $categoryStmt->execute($category);
            }

            $authorStmt = $this->pdo->prepare("INSERT INTO authors (name, bio) VALUES (?, ?)");
            foreach ([
                ['AI编辑', 'AI智能编辑，专注于科技内容创作'],
                ['科技观察员', '资深科技行业观察者'],
                ['数码评测师', '专业数码产品评测专家'],
            ] as $author) {
                $authorStmt->execute($author);
            }
        } catch (PDOException $e) {
            // 忽略插入错误，可能是重复插入
        }
    }

    private function ensureTaskQueueSchema() {
        $columnsToAdd = [
            'last_run_at' => "ALTER TABLE tasks ADD COLUMN last_run_at TIMESTAMP DEFAULT NULL",
            'next_run_at' => "ALTER TABLE tasks ADD COLUMN next_run_at TIMESTAMP DEFAULT NULL",
            'last_success_at' => "ALTER TABLE tasks ADD COLUMN last_success_at TIMESTAMP DEFAULT NULL",
            'last_error_at' => "ALTER TABLE tasks ADD COLUMN last_error_at TIMESTAMP DEFAULT NULL",
            'last_error_message' => "ALTER TABLE tasks ADD COLUMN last_error_message TEXT DEFAULT ''",
            'schedule_enabled' => "ALTER TABLE tasks ADD COLUMN schedule_enabled INTEGER DEFAULT 1",
            'max_retry_count' => "ALTER TABLE tasks ADD COLUMN max_retry_count INTEGER DEFAULT 3",
        ];

        foreach ($columnsToAdd as $column => $sql) {
            if (!db_column_exists($this->pdo, 'tasks', $column)) {
                $this->pdo->exec($sql);
            }
        }

        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS job_queue (
                id BIGSERIAL PRIMARY KEY,
                task_id INTEGER NOT NULL,
                job_type VARCHAR(50) NOT NULL DEFAULT 'generate_article',
                status VARCHAR(20) NOT NULL DEFAULT 'pending',
                payload TEXT DEFAULT '',
                attempt_count INTEGER DEFAULT 0,
                max_attempts INTEGER DEFAULT 3,
                available_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                claimed_at TIMESTAMP DEFAULT NULL,
                finished_at TIMESTAMP DEFAULT NULL,
                worker_id VARCHAR(100) DEFAULT '',
                error_message TEXT DEFAULT '',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE
            );

            CREATE TABLE IF NOT EXISTS task_runs (
                id BIGSERIAL PRIMARY KEY,
                task_id INTEGER NOT NULL,
                job_id INTEGER DEFAULT NULL,
                status VARCHAR(20) NOT NULL,
                article_id INTEGER DEFAULT NULL,
                error_message TEXT DEFAULT '',
                duration_ms INTEGER DEFAULT 0,
                meta TEXT DEFAULT '',
                started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                finished_at TIMESTAMP DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
                FOREIGN KEY (job_id) REFERENCES job_queue(id) ON DELETE SET NULL
            );

            CREATE TABLE IF NOT EXISTS worker_heartbeats (
                worker_id VARCHAR(100) PRIMARY KEY,
                status VARCHAR(20) NOT NULL DEFAULT 'idle',
                current_job_id INTEGER DEFAULT NULL,
                last_seen_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                meta TEXT DEFAULT '',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (current_job_id) REFERENCES job_queue(id) ON DELETE SET NULL
            );

            CREATE INDEX IF NOT EXISTS idx_job_queue_status_available ON job_queue(status, available_at);
            CREATE INDEX IF NOT EXISTS idx_job_queue_task ON job_queue(task_id);
            CREATE INDEX IF NOT EXISTS idx_task_runs_task ON task_runs(task_id);
            CREATE INDEX IF NOT EXISTS idx_task_runs_status ON task_runs(status);
            CREATE INDEX IF NOT EXISTS idx_worker_heartbeats_last_seen ON worker_heartbeats(last_seen_at);
        ");
    }

    private function ensureApiSchema(): void {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS api_tokens (
                id BIGSERIAL PRIMARY KEY,
                name VARCHAR(120) NOT NULL,
                token_hash VARCHAR(255) NOT NULL UNIQUE,
                scopes JSONB NOT NULL DEFAULT '[]'::jsonb,
                status VARCHAR(20) NOT NULL DEFAULT 'active',
                created_by_admin_id BIGINT DEFAULT NULL,
                last_used_at TIMESTAMP DEFAULT NULL,
                expires_at TIMESTAMP DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (created_by_admin_id) REFERENCES admins(id) ON DELETE SET NULL
            );

            CREATE TABLE IF NOT EXISTS api_idempotency_keys (
                id BIGSERIAL PRIMARY KEY,
                idempotency_key VARCHAR(120) NOT NULL,
                route_key VARCHAR(120) NOT NULL,
                request_hash VARCHAR(64) NOT NULL,
                response_body TEXT NOT NULL,
                response_status INTEGER NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (idempotency_key, route_key)
            );

            CREATE INDEX IF NOT EXISTS idx_api_tokens_status ON api_tokens(status);
            CREATE INDEX IF NOT EXISTS idx_api_tokens_expires_at ON api_tokens(expires_at);
            CREATE INDEX IF NOT EXISTS idx_api_tokens_created_by ON api_tokens(created_by_admin_id, created_at DESC);
            CREATE INDEX IF NOT EXISTS idx_api_idempotency_created_at ON api_idempotency_keys(created_at DESC);
        ");
    }

    private function ensureCompatibilitySchema() {
        $columnsToAdd = [
            'site_settings' => [
                'setting_key' => "ALTER TABLE site_settings ADD COLUMN setting_key VARCHAR(100) DEFAULT ''",
                'setting_value' => "ALTER TABLE site_settings ADD COLUMN setting_value TEXT DEFAULT ''",
            ],
            'tasks' => [
                'author_id' => "ALTER TABLE tasks ADD COLUMN author_id INTEGER DEFAULT NULL",
                'prompt_id' => "ALTER TABLE tasks ADD COLUMN prompt_id INTEGER DEFAULT NULL",
                'knowledge_base_id' => "ALTER TABLE tasks ADD COLUMN knowledge_base_id INTEGER DEFAULT NULL",
                'category_mode' => "ALTER TABLE tasks ADD COLUMN category_mode VARCHAR(20) DEFAULT 'smart'",
                'fixed_category_id' => "ALTER TABLE tasks ADD COLUMN fixed_category_id INTEGER DEFAULT NULL",
            ],
            'admins' => [
                'display_name' => "ALTER TABLE admins ADD COLUMN display_name VARCHAR(100) DEFAULT ''",
                'role' => "ALTER TABLE admins ADD COLUMN role VARCHAR(20) DEFAULT 'admin'",
                'status' => "ALTER TABLE admins ADD COLUMN status VARCHAR(20) DEFAULT 'active'",
                'created_by' => "ALTER TABLE admins ADD COLUMN created_by INTEGER DEFAULT NULL",
                'last_login' => "ALTER TABLE admins ADD COLUMN last_login TIMESTAMP DEFAULT NULL",
                'updated_at' => "ALTER TABLE admins ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
            ],
            'image_libraries' => [
                'description' => "ALTER TABLE image_libraries ADD COLUMN description TEXT DEFAULT ''",
            ],
            'images' => [
                'file_name' => "ALTER TABLE images ADD COLUMN file_name VARCHAR(255) DEFAULT ''",
                'width' => "ALTER TABLE images ADD COLUMN width INTEGER DEFAULT 0",
                'height' => "ALTER TABLE images ADD COLUMN height INTEGER DEFAULT 0",
                'tags' => "ALTER TABLE images ADD COLUMN tags TEXT DEFAULT ''",
                'usage_count' => "ALTER TABLE images ADD COLUMN usage_count INTEGER DEFAULT 0",
            ],
            'keyword_libraries' => [
                'description' => "ALTER TABLE keyword_libraries ADD COLUMN description TEXT DEFAULT ''",
            ],
            'keywords' => [
                'usage_count' => "ALTER TABLE keywords ADD COLUMN usage_count INTEGER DEFAULT 0",
            ],
            'title_libraries' => [
                'description' => "ALTER TABLE title_libraries ADD COLUMN description TEXT DEFAULT ''",
                'is_ai_generated' => "ALTER TABLE title_libraries ADD COLUMN is_ai_generated INTEGER DEFAULT 0",
            ],
            'titles' => [
                'keyword' => "ALTER TABLE titles ADD COLUMN keyword VARCHAR(200) DEFAULT ''",
                'is_ai_generated' => "ALTER TABLE titles ADD COLUMN is_ai_generated BOOLEAN DEFAULT FALSE",
                'used_count' => "ALTER TABLE titles ADD COLUMN used_count INTEGER DEFAULT 0",
            ],
            'knowledge_bases' => [
                'description' => "ALTER TABLE knowledge_bases ADD COLUMN description TEXT DEFAULT ''",
                'file_type' => "ALTER TABLE knowledge_bases ADD COLUMN file_type VARCHAR(20) DEFAULT 'markdown'",
                'file_path' => "ALTER TABLE knowledge_bases ADD COLUMN file_path VARCHAR(500) DEFAULT ''",
                'word_count' => "ALTER TABLE knowledge_bases ADD COLUMN word_count INTEGER DEFAULT 0",
                'usage_count' => "ALTER TABLE knowledge_bases ADD COLUMN usage_count INTEGER DEFAULT 0",
            ],
            'ai_models' => [
                'model_type' => "ALTER TABLE ai_models ADD COLUMN model_type VARCHAR(20) DEFAULT 'chat'",
                'priority' => "ALTER TABLE ai_models ADD COLUMN priority INTEGER DEFAULT 10",
            ],
            'knowledge_chunks' => [
                'embedding_model_id' => "ALTER TABLE knowledge_chunks ADD COLUMN embedding_model_id INTEGER DEFAULT NULL",
                'embedding_dimensions' => "ALTER TABLE knowledge_chunks ADD COLUMN embedding_dimensions INTEGER DEFAULT 0",
                'embedding_provider' => "ALTER TABLE knowledge_chunks ADD COLUMN embedding_provider VARCHAR(255) DEFAULT ''",
            ],
        ];

        foreach ($columnsToAdd as $table => $definitions) {
            foreach ($definitions as $column => $sql) {
                if (!db_column_exists($this->pdo, $table, $column)) {
                    $this->pdo->exec($sql);
                }
            }
        }

        $this->pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_site_settings_key ON site_settings(setting_key)");
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS knowledge_chunks (
                id BIGSERIAL PRIMARY KEY,
                knowledge_base_id BIGINT NOT NULL,
                chunk_index INTEGER NOT NULL,
                content TEXT NOT NULL,
                content_hash VARCHAR(64) DEFAULT '',
                token_count INTEGER DEFAULT 0,
                embedding_json TEXT DEFAULT '',
                embedding_model_id INTEGER DEFAULT NULL,
                embedding_dimensions INTEGER DEFAULT 0,
                embedding_provider VARCHAR(255) DEFAULT '',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (knowledge_base_id) REFERENCES knowledge_bases(id) ON DELETE CASCADE,
                UNIQUE(knowledge_base_id, chunk_index)
            )
        ");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_knowledge_chunks_base ON knowledge_chunks(knowledge_base_id, chunk_index)");
        $this->pdo->exec("UPDATE ai_models SET model_type = COALESCE(NULLIF(model_type, ''), 'chat')");
        $this->pdo->exec("UPDATE ai_models SET priority = COALESCE(priority, 10)");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS admin_activity_logs (
            id BIGSERIAL PRIMARY KEY,
            admin_id INTEGER DEFAULT NULL,
            admin_username VARCHAR(50) NOT NULL,
            admin_role VARCHAR(20) DEFAULT 'admin',
            action VARCHAR(120) NOT NULL,
            request_method VARCHAR(10) DEFAULT 'POST',
            page VARCHAR(255) DEFAULT '',
            target_type VARCHAR(50) DEFAULT '',
            target_id BIGINT DEFAULT NULL,
            ip_address VARCHAR(64) DEFAULT '',
            details TEXT DEFAULT '',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE SET NULL
        )");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_admin_activity_logs_admin ON admin_activity_logs(admin_id, created_at DESC)");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_admin_activity_logs_created ON admin_activity_logs(created_at DESC)");
        $this->pdo->exec("UPDATE admins SET status = COALESCE(NULLIF(status, ''), 'active'), updated_at = COALESCE(updated_at, CURRENT_TIMESTAMP)");

        $superAdminCount = (int) $this->pdo->query("SELECT COUNT(*) FROM admins WHERE role = 'super_admin'")->fetchColumn();
        if ($superAdminCount === 0) {
            $firstAdminId = (int) $this->pdo->query("SELECT id FROM admins ORDER BY id ASC LIMIT 1")->fetchColumn();
            if ($firstAdminId > 0) {
                $stmt = $this->pdo->prepare("UPDATE admins SET role = 'super_admin', updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                $stmt->execute([$firstAdminId]);
            }
        }
    }

    private function ensureSopTasksSchema(): void {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS sop_dispatched_tasks (
                id BIGSERIAL PRIMARY KEY,
                customer_id VARCHAR(80) NOT NULL DEFAULT '',
                sop_scenario VARCHAR(50) NOT NULL DEFAULT '',
                sop_code VARCHAR(10) NOT NULL DEFAULT '',
                name VARCHAR(200) NOT NULL DEFAULT '',
                kpi TEXT DEFAULT '',
                deliverable VARCHAR(200) DEFAULT '',
                owner VARCHAR(100) DEFAULT '',
                status VARCHAR(20) DEFAULT 'pending',
                due_date DATE DEFAULT NULL,
                note TEXT DEFAULT '',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_sop_dispatched_customer ON sop_dispatched_tasks(customer_id, status)");
    }

    private function ensureGeoMonitorSchema(): void {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS geo_monitor_records (
                id            BIGSERIAL PRIMARY KEY,
                customer_id   VARCHAR(80)  NOT NULL DEFAULT '',
                provider      VARCHAR(30)  NOT NULL DEFAULT '',
                query_text    TEXT         NOT NULL DEFAULT '',
                brand_mentioned  BOOLEAN   DEFAULT FALSE,
                mention_count    SMALLINT  DEFAULT 0,
                mention_position SMALLINT  DEFAULT NULL,
                response_snippet TEXT      DEFAULT '',
                full_response    TEXT      DEFAULT '',
                queried_at    DATE         NOT NULL DEFAULT CURRENT_DATE,
                created_at    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
            )
        ");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_geo_monitor_customer_date ON geo_monitor_records(customer_id, queried_at DESC)");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_geo_monitor_provider ON geo_monitor_records(customer_id, provider, queried_at DESC)");

        // 扩展字段：竞品检测、信源引用追踪、文章关联
        $this->pdo->exec("ALTER TABLE geo_monitor_records ADD COLUMN IF NOT EXISTS article_id BIGINT DEFAULT NULL");
        $this->pdo->exec("ALTER TABLE geo_monitor_records ADD COLUMN IF NOT EXISTS competitors_found TEXT DEFAULT '[]'");
        $this->pdo->exec("ALTER TABLE geo_monitor_records ADD COLUMN IF NOT EXISTS source_url_cited BOOLEAN DEFAULT FALSE");

        // 推荐深度评分：0=未提及 1=仅名称 2=有描述 3=有参数+场景 4=有案例推荐
        $this->pdo->exec("ALTER TABLE geo_monitor_records ADD COLUMN IF NOT EXISTS mention_depth SMALLINT DEFAULT 0");
        $this->pdo->exec("ALTER TABLE geo_monitor_records ADD COLUMN IF NOT EXISTS depth_detail TEXT DEFAULT '{}'");

        // 语义准确度：0-100，NULL=未校验
        $this->pdo->exec("ALTER TABLE geo_monitor_records ADD COLUMN IF NOT EXISTS accuracy_score NUMERIC(4,1) DEFAULT NULL");
        $this->pdo->exec("ALTER TABLE geo_monitor_records ADD COLUMN IF NOT EXISTS accuracy_issues TEXT DEFAULT '[]'");
        // 内容指纹命中：不依赖品牌名，独立检测文章内容是否被AI引用
        $this->pdo->exec("ALTER TABLE geo_monitor_records ADD COLUMN IF NOT EXISTS content_cited BOOLEAN DEFAULT FALSE");
        $this->pdo->exec("ALTER TABLE geo_monitor_records ADD COLUMN IF NOT EXISTS fingerprint_matched TEXT DEFAULT '[]'");

        // 品牌知识库：每个客户的标准事实表，用于准确度校验
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS geo_brand_facts (
                id          BIGSERIAL PRIMARY KEY,
                customer_id VARCHAR(80)  NOT NULL DEFAULT '',
                fact_key    VARCHAR(80)  NOT NULL DEFAULT '',
                fact_label  VARCHAR(100) NOT NULL DEFAULT '',
                fact_value  TEXT         NOT NULL DEFAULT '',
                is_core     BOOLEAN      DEFAULT TRUE,
                sort_order  SMALLINT     DEFAULT 0,
                created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
                updated_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (customer_id, fact_key)
            )
        ");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_geo_brand_facts_customer ON geo_brand_facts(customer_id, is_core)");

        // 客户监测关键词配置表
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS geo_monitor_keywords (
                id          BIGSERIAL PRIMARY KEY,
                customer_id VARCHAR(80)  NOT NULL DEFAULT '',
                keyword     TEXT         NOT NULL DEFAULT '',
                enabled     BOOLEAN      DEFAULT TRUE,
                created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (customer_id, keyword)
            )
        ");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_geo_monitor_kw_customer ON geo_monitor_keywords(customer_id) WHERE enabled = TRUE");

        // 关键词关联文章信息
        $this->pdo->exec("ALTER TABLE geo_monitor_keywords ADD COLUMN IF NOT EXISTS article_id BIGINT DEFAULT NULL");
        $this->pdo->exec("ALTER TABLE geo_monitor_keywords ADD COLUMN IF NOT EXISTS source_url TEXT DEFAULT ''");
        // 内容指纹：从关联文章自动提取的独特短语，用于检测AI是否引用了该文章内容
        $this->pdo->exec("ALTER TABLE geo_monitor_keywords ADD COLUMN IF NOT EXISTS source_fingerprints TEXT DEFAULT '[]'");
        $this->pdo->exec("ALTER TABLE geo_monitor_keywords ADD COLUMN IF NOT EXISTS fingerprints_extracted_at TIMESTAMP DEFAULT NULL");

        // 竞品名称表：集中管理，监测脚本从此表读取，不再硬编码
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS geo_customer_competitors (
                id          BIGSERIAL PRIMARY KEY,
                customer_id VARCHAR(80)  NOT NULL DEFAULT '',
                competitor  VARCHAR(100) NOT NULL DEFAULT '',
                enabled     BOOLEAN      DEFAULT TRUE,
                created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (customer_id, competitor)
            )
        ");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_geo_competitors_customer ON geo_customer_competitors(customer_id) WHERE enabled = TRUE");

        // 告警记录表（真实数据驱动）
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS geo_monitor_alerts (
                id              BIGSERIAL PRIMARY KEY,
                customer_id     VARCHAR(80)  NOT NULL DEFAULT '',
                alert_type      VARCHAR(40)  NOT NULL DEFAULT '',
                level           VARCHAR(10)  NOT NULL DEFAULT 'medium',
                keyword         TEXT         DEFAULT '',
                competitor_name VARCHAR(100) DEFAULT '',
                brand_rate      NUMERIC(5,1) DEFAULT 0,
                competitor_rate NUMERIC(5,1) DEFAULT 0,
                detail          TEXT         DEFAULT '',
                is_read         BOOLEAN      DEFAULT FALSE,
                alerted_at      DATE         NOT NULL DEFAULT CURRENT_DATE,
                created_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (customer_id, alert_type, keyword, competitor_name, alerted_at)
            )
        ");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_geo_monitor_alerts_customer ON geo_monitor_alerts(customer_id, alerted_at DESC)");
    }

    private function ensureGeoSemanticSchema(): void {
        // GEO文章评分表
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS geo_article_scores (
                id                     BIGSERIAL PRIMARY KEY,
                article_id             BIGINT       NOT NULL,
                customer_id            VARCHAR(80)  NOT NULL DEFAULT '',
                structure_score        SMALLINT     NOT NULL DEFAULT 0,
                fact_density_score     SMALLINT     NOT NULL DEFAULT 0,
                brand_compliance_score SMALLINT     NOT NULL DEFAULT 0,
                geo_score              SMALLINT     NOT NULL DEFAULT 0,
                red_line_violations    TEXT         NOT NULL DEFAULT '[]',
                has_faq                BOOLEAN      NOT NULL DEFAULT FALSE,
                brand_mention_count    SMALLINT     NOT NULL DEFAULT 0,
                has_master_sentence    BOOLEAN      NOT NULL DEFAULT FALSE,
                issues                 TEXT         NOT NULL DEFAULT '[]',
                scored_at              TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (article_id)
            )
        ");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_geo_article_scores_customer ON geo_article_scores(customer_id, geo_score DESC)");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_geo_article_scores_article ON geo_article_scores(article_id)");

        // tasks 表新增 GEO 模式字段
        $this->pdo->exec("ALTER TABLE tasks ADD COLUMN IF NOT EXISTS geo_mode     BOOLEAN      DEFAULT FALSE");
        $this->pdo->exec("ALTER TABLE tasks ADD COLUMN IF NOT EXISTS geo_scenario VARCHAR(1)   DEFAULT 'B'");
        $this->pdo->exec("ALTER TABLE tasks ADD COLUMN IF NOT EXISTS geo_brand_name VARCHAR(200) DEFAULT ''");
        $this->pdo->exec("ALTER TABLE tasks ADD COLUMN IF NOT EXISTS geo_customer_id VARCHAR(80)  DEFAULT ''");
    }

    private function ensureAutomationSchema(): void {
        // 自动化工作流主表
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS automation_workflows (
                id              BIGSERIAL    PRIMARY KEY,
                workflow_id     VARCHAR(64)  NOT NULL UNIQUE,
                brand_name      VARCHAR(200) NOT NULL DEFAULT '',
                industry        VARCHAR(120) NOT NULL DEFAULT '',
                website         VARCHAR(200) DEFAULT '',
                services        TEXT         DEFAULT '',
                competitors     TEXT         DEFAULT '',
                positioning     TEXT         DEFAULT '',
                article_count   INT          NOT NULL DEFAULT 10,
                status          VARCHAR(20)  NOT NULL DEFAULT 'pending',
                current_step    VARCHAR(40)  DEFAULT '',
                customer_id     VARCHAR(80)  DEFAULT '',
                diagnosis_id    VARCHAR(64)  DEFAULT '',
                keyword_library_id BIGINT    DEFAULT NULL,
                title_library_id   BIGINT    DEFAULT NULL,
                knowledge_base_id  BIGINT    DEFAULT NULL,
                task_id         BIGINT       DEFAULT NULL,
                error_message   TEXT         DEFAULT '',
                created_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
                updated_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
                completed_at    TIMESTAMP    DEFAULT NULL
            )
        ");
        $this->pdo->exec("ALTER TABLE automation_workflows ADD COLUMN IF NOT EXISTS diagnosis_id VARCHAR(64) DEFAULT ''");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_automation_workflows_status ON automation_workflows(status)");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_automation_workflows_wf_id ON automation_workflows(workflow_id)");

        // 自动化工作流步骤表
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS automation_workflow_steps (
                id              BIGSERIAL    PRIMARY KEY,
                workflow_id     VARCHAR(64)  NOT NULL,
                step_id         VARCHAR(40)  NOT NULL,
                step_order      INT          NOT NULL DEFAULT 0,
                status          VARCHAR(20)  NOT NULL DEFAULT 'pending',
                started_at      TIMESTAMP    DEFAULT NULL,
                finished_at     TIMESTAMP    DEFAULT NULL,
                elapsed_seconds INT          DEFAULT NULL,
                output_data     TEXT         DEFAULT NULL,
                error_message   TEXT         DEFAULT '',
                created_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
                updated_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (workflow_id, step_id)
            )
        ");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_automation_steps_wf_id ON automation_workflow_steps(workflow_id)");
    }

    private function ensureCustomerSchema(): void {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS customers (
                id BIGSERIAL PRIMARY KEY,
                customer_id VARCHAR(80) NOT NULL UNIQUE,
                name VARCHAR(200) NOT NULL,
                domain VARCHAR(200) DEFAULT '',
                industry VARCHAR(120) DEFAULT '',
                package_tier VARCHAR(40) DEFAULT '',
                owner VARCHAR(80) DEFAULT '',
                service_status VARCHAR(30) DEFAULT 'active',
                contract_start_date DATE DEFAULT NULL,
                contract_end_date DATE DEFAULT NULL,
                contract_amount NUMERIC(12,2) DEFAULT 0,
                contact_name VARCHAR(100) DEFAULT '',
                contact_phone VARCHAR(120) DEFAULT '',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");

        $defaults = [
            ['wenyun-ai-reading', '湖南文韵爱阅读', 'wenyunedu.cn', '教培 / 知识付费', 'growth', '张顾问', 'active', '2026-01-01', '2026-08-01', 58000, '李敏', 'liMin@wenyunedu.cn'],
            ['dongluoji-mgeo', '董逻辑 MGEO', 'dongluoji.com', 'B2B SaaS / 企业服务', 'dominate', '李策略', 'active', '2025-12-01', '2026-09-15', 128000, '董策', 'dong@dongluoji.com'],
            ['yimaitong-health', '医脉通健康项目', 'news.growume.com', '医疗 / 健康', 'growth', '王运营', 'active', '2026-01-20', '2026-07-20', 45000, '陈医达', 'chen@yimaitong.net'],
            ['local-food-sample', '本地生活餐饮样板', 'local-demo.cn', '本地生活 / 餐饮', 'lite', '陈执行', 'paused', '2026-03-01', '2026-06-30', 18000, '吴老板', '13800138000'],
        ];

        $stmt = $this->pdo->prepare("
            INSERT INTO customers (
                customer_id, name, domain, industry, package_tier, owner, service_status,
                contract_start_date, contract_end_date, contract_amount, contact_name, contact_phone
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON CONFLICT (customer_id) DO UPDATE SET
                name = EXCLUDED.name,
                domain = EXCLUDED.domain,
                industry = EXCLUDED.industry,
                package_tier = EXCLUDED.package_tier,
                owner = EXCLUDED.owner,
                updated_at = CURRENT_TIMESTAMP
        ");

        foreach ($defaults as $customer) {
            $stmt->execute($customer);
        }
    }

    private function ensureTaskCreationSeedData(): void {
        $activeChatModels = (int) $this->pdo->query("
            SELECT COUNT(*)
            FROM ai_models
            WHERE COALESCE(NULLIF(model_type, ''), 'chat') = 'chat'
        ")->fetchColumn();

        if ($activeChatModels === 0) {
            $stmt = $this->pdo->prepare("
                INSERT INTO ai_models (
                    name, version, api_key, model_id, model_type, api_url,
                    daily_limit, status, created_at, updated_at
                ) VALUES (?, ?, ?, ?, 'chat', ?, 0, 'inactive', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
            ");
            $stmt->execute([
                '本地占位聊天模型',
                'local',
                '',
                'local-placeholder',
                'http://127.0.0.1/not-configured',
            ]);
        }

        $contentPrompts = (int) $this->pdo->query("SELECT COUNT(*) FROM prompts WHERE type = 'content'")->fetchColumn();
        if ($contentPrompts === 0) {
            $stmt = $this->pdo->prepare("INSERT INTO prompts (name, type, content, variables) VALUES (?, 'content', ?, ?)");
            $stmt->execute([
                '默认内容生成',
                "你是一名中文GEO内容编辑。请根据标题\"{{title}}\"和关键词\"{{keyword}}\"写一篇证据型知识文章。必须包含直接答案、判断标准、事实与证据、适合与不适合、FAQ、下一步验证清单；没有证据的数据不能编造。{{#if Knowledge}}\n\n参考知识：{{Knowledge}}{{/if}}\n\n请直接输出完整文章。",
                'title,keyword,Knowledge',
            ]);
        }

        $titleLibraries = (int) $this->pdo->query("SELECT COUNT(*) FROM title_libraries")->fetchColumn();
        if ($titleLibraries === 0) {
            $stmt = $this->pdo->prepare("INSERT INTO title_libraries (name, title_count, created_at, updated_at) VALUES (?, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
            $stmt->execute(['默认标题库']);
        }

        $authors = (int) $this->pdo->query("SELECT COUNT(*) FROM authors")->fetchColumn();
        if ($authors === 0) {
            $stmt = $this->pdo->prepare("INSERT INTO authors (name, bio, created_at, updated_at) VALUES (?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
            $stmt->execute(['AI编辑', '本地默认作者']);
        }
    }

    private function ensureSopNodeStatusSchema(): void {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS sop_node_status (
                id BIGSERIAL PRIMARY KEY,
                customer_id VARCHAR(80) NOT NULL DEFAULT '',
                scenario    VARCHAR(40) NOT NULL DEFAULT '',
                node_code   VARCHAR(20) NOT NULL DEFAULT '',
                status      VARCHAR(20) NOT NULL DEFAULT 'pending',
                updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (customer_id, scenario, node_code)
            )
        ");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_sop_node_status_customer ON sop_node_status(customer_id, scenario)");
    }

    private function ensureMobileQuerySchema(): void {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS mobile_query_records (
                id BIGSERIAL PRIMARY KEY,
                customer_id VARCHAR(80) NOT NULL DEFAULT '',
                platform VARCHAR(30) NOT NULL DEFAULT '',
                query_text TEXT NOT NULL DEFAULT '',
                brand_mentioned BOOLEAN DEFAULT FALSE,
                mention_position SMALLINT DEFAULT NULL,
                response_snippet TEXT DEFAULT '',
                queried_at DATE DEFAULT CURRENT_DATE,
                notes TEXT DEFAULT '',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_mobile_query_customer ON mobile_query_records(customer_id, platform, queried_at)");
    }

    private function ensureAccessLogSchema(): void {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS access_logs (
                id           BIGSERIAL PRIMARY KEY,
                request_path VARCHAR(500) DEFAULT '',
                page_type    VARCHAR(30)  DEFAULT '',
                article_slug VARCHAR(200) DEFAULT '',
                ip_address   VARCHAR(45)  DEFAULT '',
                user_agent   TEXT         DEFAULT '',
                referer      VARCHAR(500) DEFAULT '',
                is_bot       BOOLEAN      DEFAULT FALSE,
                bot_name     VARCHAR(80)  DEFAULT '',
                bot_company  VARCHAR(60)  DEFAULT '',
                created_at   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
            )
        ");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_access_logs_created ON access_logs(created_at)");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_access_logs_is_bot  ON access_logs(is_bot, created_at)");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_access_logs_path    ON access_logs(request_path)");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_access_logs_slug    ON access_logs(article_slug)");
    }

    private function ensureAiCrawlerSchema(): void {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS ai_crawler_logs (
                id          BIGSERIAL PRIMARY KEY,
                bot_name    VARCHAR(80)  NOT NULL,
                bot_company VARCHAR(60)  DEFAULT '',
                user_agent  TEXT         DEFAULT '',
                request_path VARCHAR(500) DEFAULT '',
                article_slug VARCHAR(200) DEFAULT '',
                ip_address  VARCHAR(45)  DEFAULT '',
                referer     VARCHAR(500) DEFAULT '',
                created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_ai_crawler_bot    ON ai_crawler_logs(bot_name)");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_ai_crawler_created ON ai_crawler_logs(created_at)");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_ai_crawler_slug    ON ai_crawler_logs(article_slug)");
    }

    private function ensurePgvectorSchema(): void {
        try {
            $this->pdo->exec("CREATE EXTENSION IF NOT EXISTS vector");
        } catch (Throwable $e) {
            error_log('pgvector 扩展初始化失败，将继续使用文本检索回退: ' . $e->getMessage());
            return;
        }

        try {
            $stmt = $this->pdo->query("
                SELECT EXISTS (
                    SELECT 1
                    FROM pg_type
                    WHERE typname = 'vector'
                )
            ");
            $vectorAvailable = (bool) ($stmt ? $stmt->fetchColumn() : false);
        } catch (Throwable $e) {
            error_log('pgvector 可用性检查失败: ' . $e->getMessage());
            return;
        }

        if (!$vectorAvailable) {
            return;
        }

        try {
            if (!db_column_exists($this->pdo, 'knowledge_chunks', 'embedding_vector')) {
                $this->pdo->exec("ALTER TABLE knowledge_chunks ADD COLUMN embedding_vector vector(1536)");
            }

            $this->pdo->exec("
                CREATE INDEX IF NOT EXISTS idx_knowledge_chunks_embedding_hnsw
                ON knowledge_chunks
                USING hnsw (embedding_vector vector_cosine_ops)
                WHERE embedding_vector IS NOT NULL
            ");
        } catch (Throwable $e) {
            error_log('pgvector 向量列或索引初始化失败: ' . $e->getMessage());
        }
    }
}

// 创建全局数据库连接
try {
    if (!class_exists('DatabaseNew', false)) {
        class_alias(DatabaseAdmin::class, 'DatabaseNew');
    }

    $db = DatabaseAdmin::getInstance()->getPDO();
} catch (Exception $e) {
    die('数据库初始化失败: ' . $e->getMessage());
}
?>
