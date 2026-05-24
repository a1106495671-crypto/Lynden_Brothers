<?php
/**
 * 品牌入驻自动化 - 后台 Worker
 * 顺序执行 automation_workflow_steps 中的 10 个步骤
 *
 * 用法:
 *   php bin/automation_worker.php <workflow_id>   # 执行指定工作流
 *   php bin/automation_worker.php                  # 轮询所有 running 状态的工作流
 */

define('FEISHU_TREASURE', true);

$projectRoot = dirname(__DIR__);
chdir($projectRoot);

require_once $projectRoot . '/includes/config.php';
require_once $projectRoot . '/includes/database_admin.php';
require_once $projectRoot . '/includes/functions.php';
require_once $projectRoot . '/includes/geo_ai_fallback.php';
require_once $projectRoot . '/includes/job_queue_service.php';
require_once $projectRoot . '/includes/material_service.php';
require_once $projectRoot . '/includes/customer_service.php';
require_once $projectRoot . '/includes/task_lifecycle_service.php';
require_once $projectRoot . '/includes/article_service.php';
require_once $projectRoot . '/includes/monitor_api_service.php';
require_once $projectRoot . '/includes/geo_diagnosis_service.php';

set_time_limit(0);
ini_set('memory_limit', '512M');

// ─── 日志 ────────────────────────────────────────────────
function wf_log(string $workflowId, string $message): void {
    $ts = date('Y-m-d H:i:s');
    echo "[{$ts}] [{$workflowId}] {$message}\n";
    $dir = __DIR__ . '/logs';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    file_put_contents($dir . '/automation_' . date('Y-m-d') . ".log",
        "[{$ts}] [{$workflowId}] {$message}\n", FILE_APPEND | LOCK_EX);
}

// ─── 数据库工具 ──────────────────────────────────────────
function wf_get_workflow(PDO $db, string $workflowId): ?array {
    $stmt = $db->prepare("SELECT * FROM automation_workflows WHERE workflow_id = ?");
    $stmt->execute([$workflowId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function wf_update_workflow(PDO $db, string $workflowId, array $fields): void {
    $sets = [];
    $vals = [];
    foreach ($fields as $k => $v) {
        $sets[] = "{$k} = ?";
        $vals[] = $v;
    }
    $sets[] = "updated_at = CURRENT_TIMESTAMP";
    $vals[] = $workflowId;
    $db->prepare("UPDATE automation_workflows SET " . implode(', ', $sets) . " WHERE workflow_id = ?")
       ->execute($vals);
}

function wf_update_step(PDO $db, string $workflowId, string $stepId, string $status, ?string $error = null, ?string $output = null): void {
    $sql = "UPDATE automation_workflow_steps SET status = ?, updated_at = CURRENT_TIMESTAMP";
    $params = [$status];

    if ($status === 'running') {
        $sql .= ", started_at = CURRENT_TIMESTAMP";
    }
    if ($status === 'completed' || $status === 'error') {
        $sql .= ", finished_at = CURRENT_TIMESTAMP";
        $sql .= ", elapsed_seconds = EXTRACT(EPOCH FROM (CURRENT_TIMESTAMP - COALESCE(started_at, CURRENT_TIMESTAMP)))::INT";
    }
    if ($error !== null) {
        $sql .= ", error_message = ?";
        $params[] = $error;
    }
    if ($output !== null) {
        $sql .= ", output_data = ?";
        $params[] = $output;
    }
    $sql .= " WHERE workflow_id = ? AND step_id = ?";
    $params[] = $workflowId;
    $params[] = $stepId;
    $db->prepare($sql)->execute($params);
}

// ─── AI 调用封装（仅允许真实 API 模型）──────────────────────
function wf_call_ai(string $prompt, int $maxTokens = 3000, string $workflowId = '', string $stage = 'AI生成'): string {
    $result = geo_call_ai_with_fallback($prompt, $maxTokens, 0.7);
    $GLOBALS['WF_LAST_AI_MODEL'] = $result['model_used'] ?? 'none';

    if (!empty($result['error'])) {
        throw new RuntimeException("AI调用失败: " . $result['error']);
    }
    if (empty($result['content'])) {
        throw new RuntimeException("AI返回空内容");
    }

    if ($workflowId !== '') {
        wf_log($workflowId, "{$stage} 使用模型: " . ($result['model_used'] ?? 'unknown'));
    }

    return $result['content'];
}

function wf_last_ai_model(): string {
    return (string) ($GLOBALS['WF_LAST_AI_MODEL'] ?? '');
}

// ─── 解析 prompt 模板 ────────────────────────────────────
function wf_fill_template(string $template, array $vars): string {
    $result = $template;
    foreach ($vars as $key => $value) {
        $result = str_replace('{' . $key . '}', $value, $result);
    }
    return $result;
}

// ─── 解析关键词输出 ──────────────────────────────────────
function wf_parse_keywords(string $text): array {
    $keywords = [];
    $lines = explode("\n", $text);
    foreach ($lines as $line) {
        $line = trim($line, " \t\r\n*-");
        if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, '```')) continue;
        // 去掉开头的编号 "1. " "- " "* "
        $line = preg_replace('/^\d+[\.\)、]\s*/', '', $line);
        $line = preg_replace('/^[-*]\s+/', '', $line);
        $line = trim($line);
        if ($line !== '' && mb_strlen($line) <= 100) {
            $keywords[] = $line;
        }
    }
    return array_values(array_unique($keywords));
}

// ─── 解析标题输出 ────────────────────────────────────────
function wf_parse_titles(string $text): array {
    $titles = [];
    $lines = explode("\n", $text);
    foreach ($lines as $line) {
        $line = trim($line, " \t\r\n*-");
        if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, '```')) continue;
        $line = preg_replace('/^\d+[\.\)、]\s*/', '', $line);
        $line = trim($line);
        if ($line !== '' && mb_strlen($line) >= 5 && mb_strlen($line) <= 100) {
            $titles[] = $line;
        }
    }
    return array_values(array_unique($titles));
}

function wf_get_usable_chat_model(PDO $db): ?array {
    $stmt = $db->query("
        SELECT id, name, api_key, model_id, api_url
        FROM ai_models
        WHERE status = 'active'
          AND COALESCE(NULLIF(model_type, ''), 'chat') = 'chat'
          AND COALESCE(api_key, '') <> ''
          AND COALESCE(model_id, '') <> ''
        ORDER BY priority ASC NULLS LAST, id ASC
    ");

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $model) {
        $apiKey = trim(decrypt_ai_api_key((string) ($model['api_key'] ?? '')));
        $apiUrl = trim((string) ($model['api_url'] ?? ''));
        if ($apiKey !== '' && $apiUrl !== '' && trim((string) $model['model_id']) !== '') {
            return $model;
        }
    }

    return null;
}

function wf_get_latest_job_error(PDO $db, int $taskId): string {
    $stmt = $db->prepare("
        SELECT status, error_message
        FROM job_queue
        WHERE task_id = ?
        ORDER BY updated_at DESC, id DESC
        LIMIT 1
    ");
    $stmt->execute([$taskId]);
    $job = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$job) {
        return '没有找到文章生成队列记录，请确认 worker.php 是否正在运行';
    }

    $status = (string) ($job['status'] ?? 'unknown');
    $message = trim((string) ($job['error_message'] ?? ''));
    return $message !== '' ? "{$status}: {$message}" : "最近队列状态：{$status}";
}

function wf_get_step_output(PDO $db, string $workflowId, string $stepId): array {
    $stmt = $db->prepare("
        SELECT output_data
        FROM automation_workflow_steps
        WHERE workflow_id = ? AND step_id = ?
        LIMIT 1
    ");
    $stmt->execute([$workflowId, $stepId]);
    $raw = (string) ($stmt->fetchColumn() ?: '');
    if ($raw === '') {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function wf_map_diagnosis_industry(string $industry): string {
    $industry = trim($industry);
    $available = geo_diagnosis_industries();
    if (in_array($industry, $available, true)) {
        return $industry;
    }

    $map = [
        'geo' => 'GEO服务商',
        'ai营销' => 'AI营销服务',
        '营销' => 'AI营销服务',
        'saas' => 'B2B SaaS',
        '企业服务' => '企业服务',
        'b2b' => 'B2B专业服务',
        '教育' => '教育',
        '教培' => '教育',
        '医疗' => '医疗',
        '健康' => '医疗',
        '金融' => '金融',
        '本地生活' => '本地生活',
        '消费' => '消费品',
    ];

    $lower = mb_strtolower($industry, 'UTF-8');
    foreach ($map as $needle => $mapped) {
        if (mb_strpos($lower, mb_strtolower($needle, 'UTF-8')) !== false) {
            return $mapped;
        }
    }

    return 'B2B SaaS';
}

function wf_seed_geo_brand_facts(PDO $db, string $customerId, array $wf): int {
    if ($customerId === '') {
        return 0;
    }

    $brandName = trim((string) ($wf['brand_name'] ?? ''));
    $industry = trim((string) ($wf['industry'] ?? ''));
    $services = trim((string) ($wf['services'] ?? ''));
    $positioning = trim((string) ($wf['positioning'] ?? ''));

    $masterSentence = $positioning;
    if ($masterSentence === '') {
        $parts = array_filter([$industry, $services]);
        $masterSentence = $brandName . ($parts ? '是一家专注于' . implode('、', $parts) . '的品牌。' : '是一个正在进行GEO优化的品牌。');
    }

    $facts = [
        ['brand_name', '品牌名称', $brandName, true],
        ['industry', '所属行业', $industry, true],
        ['website', '官网', trim((string) ($wf['website'] ?? '')), false],
        ['core_services', '核心服务', $services, true],
        ['competitors', '主要竞品', trim((string) ($wf['competitors'] ?? '')), false],
        ['positioning', '品牌定位', $positioning, true],
        ['master_sentence', '定位母句', $masterSentence, true],
    ];

    $stmt = $db->prepare("
        INSERT INTO geo_brand_facts (customer_id, fact_key, fact_label, fact_value, is_core)
        VALUES (?, ?, ?, ?, ?)
        ON CONFLICT (customer_id, fact_key) DO UPDATE SET
            fact_label = EXCLUDED.fact_label,
            fact_value = EXCLUDED.fact_value,
            is_core = EXCLUDED.is_core,
            updated_at = CURRENT_TIMESTAMP
    ");

    $count = 0;
    foreach ($facts as [$key, $label, $value, $isCore]) {
        $value = trim((string) $value);
        if ($value === '') {
            continue;
        }
        $stmt->execute([$customerId, $key, $label, mb_substr($value, 0, 500), $isCore ? 'true' : 'false']);
        $count++;
    }

    return $count;
}

// ═══════════════════════════════════════════════════════════
//  步骤执行器
// ═══════════════════════════════════════════════════════════

/**
 * Step 1: 搜集品牌资料
 */
function step_collect(PDO $db, array $wf): array {
    $brandName = $wf['brand_name'];
    $industry  = $wf['industry'];
    $website   = $wf['website'];

    // 用 AI 补充品牌背景信息
    $prompt = "请用200字客观介绍以下品牌，包括主营业务、目标客户、核心优势。如果不确定具体信息，请基于行业通用知识合理推断。\n\n"
            . "品牌名称：{$brandName}\n行业：{$industry}\n官网：{$website}\n核心服务：{$wf['services']}\n竞品：{$wf['competitors']}\n定位：{$wf['positioning']}\n\n"
            . "要求：客观中立，不要营销话术，像百科词条。";

    $collected = wf_call_ai($prompt, 1500, $wf['workflow_id'], '搜集品牌资料');

    // 存入 workflow 以供后续步骤使用
    wf_update_workflow($db, $wf['workflow_id'], [
        'error_message' => '' // clear any previous error
    ]);

    return [
        'success' => true,
        'output'  => json_encode(['collected_info' => $collected, 'model_used' => wf_last_ai_model()], JSON_UNESCAPED_UNICODE),
        'data'    => ['collected_info' => $collected]
    ];
}

/**
 * Step 2: 生成关键词库
 */
function step_keywords(PDO $db, array $wf): array {
    $templatePath = $projectRoot = dirname(__DIR__) . '/prompts/keyword_library.md';
    if (!file_exists($templatePath)) {
        throw new RuntimeException("关键词模板不存在: {$templatePath}");
    }
    $template = file_get_contents($templatePath);
    $prompt = wf_fill_template($template, [
        'brand_name'   => $wf['brand_name'],
        'industry'     => $wf['industry'],
        'website'      => $wf['website'],
        'core_services'=> $wf['services'],
        'competitors'  => $wf['competitors'],
        'positioning'  => $wf['positioning'],
    ]);

    $aiOutput = wf_call_ai($prompt, 3000, $wf['workflow_id'], '生成关键词库');
    $keywords = wf_parse_keywords($aiOutput);

    if (count($keywords) < 5) {
        throw new RuntimeException("AI生成的关键词过少（仅" . count($keywords) . "个），请重试");
    }

    // 创建关键词库
    $materialService = new MaterialService($db);
    $library = $materialService->createKeywordLibrary([
        'name'        => $wf['brand_name'] . ' GEO关键词库',
        'description' => '自动化生成于 ' . date('Y-m-d H:i:s'),
        'keywords'    => $keywords,
    ]);

    $libraryId = $library['id'];
    wf_update_workflow($db, $wf['workflow_id'], ['keyword_library_id' => $libraryId]);

    return [
        'success' => true,
        'output'  => json_encode([
            'library_id' => $libraryId,
            'keyword_count' => count($keywords),
            'keywords' => array_slice($keywords, 0, 10), // 只存前10个预览
            'model_used' => wf_last_ai_model(),
        ], JSON_UNESCAPED_UNICODE),
        'data' => ['keyword_library_id' => $libraryId]
    ];
}

/**
 * Step 2: 生成雷达诊断
 */
function step_diagnosis(PDO $db, array $wf): array {
    $collected = wf_get_step_output($db, (string) $wf['workflow_id'], 'collect');
    $collectedInfo = trim((string) ($collected['collected_info'] ?? ''));

    $evidenceParts = array_filter([
        '品牌资料：' . $collectedInfo,
        '核心服务：' . trim((string) ($wf['services'] ?? '')),
        '主要竞品：' . trim((string) ($wf['competitors'] ?? '')),
        '品牌定位：' . trim((string) ($wf['positioning'] ?? '')),
    ], static fn ($part) => trim(str_replace(['品牌资料：', '核心服务：', '主要竞品：', '品牌定位：'], '', $part)) !== '');

    $diagnosisId = geo_diagnosis_create($db, [
        'brand_name' => (string) ($wf['brand_name'] ?? ''),
        'domain' => (string) ($wf['website'] ?? ''),
        'industry' => wf_map_diagnosis_industry((string) ($wf['industry'] ?? '')),
        'email' => '',
        'evidence' => implode("\n", $evidenceParts),
    ]);

    wf_update_workflow($db, (string) $wf['workflow_id'], ['diagnosis_id' => $diagnosisId]);

    $report = geo_diagnosis_latest($db, $diagnosisId);
    return [
        'success' => true,
        'output' => json_encode([
            'diagnosis_id' => $diagnosisId,
            'overall_score' => $report ? round((float) ($report['overall_score'] ?? 0), 1) : null,
            'industry' => $report['industry'] ?? wf_map_diagnosis_industry((string) ($wf['industry'] ?? '')),
            'predicted_hit_rate' => $report['predicted_hit_rate'] ?? '',
        ], JSON_UNESCAPED_UNICODE),
        'data' => ['diagnosis_id' => $diagnosisId],
    ];
}

/**
 * Step 3: 生成标题库
 */
function step_titles(PDO $db, array $wf): array {
    $templatePath = dirname(__DIR__) . '/prompts/title_library.md';
    if (!file_exists($templatePath)) {
        throw new RuntimeException("标题模板不存在: {$templatePath}");
    }
    $template = file_get_contents($templatePath);
    $prompt = wf_fill_template($template, [
        'brand_name'   => $wf['brand_name'],
        'industry'     => $wf['industry'],
        'core_services'=> $wf['services'],
        'competitors'  => $wf['competitors'],
        'positioning'  => $wf['positioning'],
    ]);

    $aiOutput = wf_call_ai($prompt, 4096, $wf['workflow_id'], '生成标题库');
    $titles = wf_parse_titles($aiOutput);

    if (count($titles) < 5) {
        throw new RuntimeException("AI生成的标题过少（仅" . count($titles) . "个），请重试");
    }

    $keywordLibraryId = $wf['keyword_library_id'] ?: null;

    $materialService = new MaterialService($db);
    $library = $materialService->createTitleLibrary([
        'name'              => $wf['brand_name'] . ' 文章标题库',
        'description'       => '自动化生成于 ' . date('Y-m-d H:i:s'),
        'keyword_library_id'=> $keywordLibraryId,
        'titles'            => $titles,
    ]);

    $libraryId = $library['id'];
    wf_update_workflow($db, $wf['workflow_id'], ['title_library_id' => $libraryId]);

    return [
        'success' => true,
        'output'  => json_encode([
            'library_id' => $libraryId,
            'title_count' => count($titles),
            'model_used' => wf_last_ai_model(),
        ], JSON_UNESCAPED_UNICODE),
        'data' => ['title_library_id' => $libraryId]
    ];
}

/**
 * Step 4: 生成知识库
 */
function step_knowledge(PDO $db, array $wf): array {
    $templatePath = dirname(__DIR__) . '/prompts/knowledge_base.md';
    if (!file_exists($templatePath)) {
        throw new RuntimeException("知识库模板不存在: {$templatePath}");
    }
    $template = file_get_contents($templatePath);
    $prompt = wf_fill_template($template, [
        'brand_name'    => $wf['brand_name'],
        'industry'      => $wf['industry'],
        'website'       => $wf['website'],
        'core_services' => $wf['services'],
        'competitors'   => $wf['competitors'],
        'positioning'   => $wf['positioning'],
        'known_materials'=> '',
    ]);

    $knowledgeContent = wf_call_ai($prompt, 6144, $wf['workflow_id'], '生成知识库');

    $materialService = new MaterialService($db);
    $kb = $materialService->createKnowledgeBase([
        'name'        => $wf['brand_name'] . ' 品牌知识库',
        'description' => '自动化生成于 ' . date('Y-m-d H:i:s'),
        'content'     => $knowledgeContent,
    ]);

    $kbId = $kb['id'];
    wf_update_workflow($db, $wf['workflow_id'], ['knowledge_base_id' => $kbId]);

    return [
        'success' => true,
        'output'  => json_encode([
            'knowledge_base_id' => $kbId,
            'char_count' => mb_strlen($knowledgeContent, 'UTF-8'),
            'model_used' => wf_last_ai_model(),
        ], JSON_UNESCAPED_UNICODE),
        'data' => ['knowledge_base_id' => $kbId]
    ];
}

/**
 * Step 5: 创建客户
 */
function step_customer(PDO $db, array $wf): array {
    $customerService = new CustomerService($db);
    $customer = wf_find_existing_customer($db, $wf);
    $created = false;
    if (!$customer) {
        $customer = $customerService->createCustomer([
            'name'     => $wf['brand_name'],
            'industry' => $wf['industry'],
            'domain'   => $wf['website'],
        ]);
        $created = true;
    }

    $customerId = $customer['customer_id'];
    $factCount = wf_seed_geo_brand_facts($db, $customerId, $wf);
    wf_update_workflow($db, $wf['workflow_id'], ['customer_id' => $customerId]);

    return [
        'success' => true,
        'output'  => json_encode([
            'customer_id' => $customerId,
            'reused_existing_customer' => !$created,
            'geo_brand_fact_count' => $factCount,
        ], JSON_UNESCAPED_UNICODE),
        'data' => ['customer_id' => $customerId]
    ];
}

function wf_find_existing_customer(PDO $db, array $wf): ?array {
    $domain = trim((string) ($wf['website'] ?? ''));
    $brandName = trim((string) ($wf['brand_name'] ?? ''));

    if ($domain !== '') {
        $stmt = $db->prepare("
            SELECT *
            FROM customers
            WHERE lower(trim(domain)) = lower(trim(?))
            ORDER BY CASE WHEN customer_id LIKE 'cust_%' THEN 1 ELSE 0 END ASC, created_at ASC
            LIMIT 1
        ");
        $stmt->execute([$domain]);
        $customer = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($customer) {
            return $customer;
        }
    }

    if ($brandName !== '') {
        $stmt = $db->prepare("
            SELECT *
            FROM customers
            WHERE lower(trim(name)) = lower(trim(?))
            ORDER BY CASE WHEN customer_id LIKE 'cust_%' THEN 1 ELSE 0 END ASC, created_at ASC
            LIMIT 1
        ");
        $stmt->execute([$brandName]);
        $customer = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($customer) {
            return $customer;
        }
    }

    return null;
}

/**
 * Step 6: 创建并启动任务
 */
function step_task(PDO $db, array $wf): array {
    // 查找默认 content prompt
    $stmt = $db->prepare("SELECT id FROM prompts WHERE type = 'content' ORDER BY id ASC LIMIT 1");
    $stmt->execute();
    $prompt = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$prompt) {
        throw new RuntimeException("系统中没有 type='content' 的提示词，请先在后台创建");
    }

    // 查找可用的聊天模型，不能选择本地占位或没有 API Key 的模型。
    $aiModel = wf_get_usable_chat_model($db);
    if (!$aiModel) {
        throw new RuntimeException("系统中没有可用的聊天 AI 模型，请先配置有效 API Key 并保持模型为活跃状态");
    }

    $taskService = new TaskLifecycleService($db);
    $task = $taskService->createTask([
        'name'              => $wf['brand_name'] . ' GEO自动化任务',
        'title_library_id'  => (int) $wf['title_library_id'],
        'prompt_id'         => (int) $prompt['id'],
        'ai_model_id'       => (int) $aiModel['id'],
        'knowledge_base_id' => $wf['knowledge_base_id'] ? (int) $wf['knowledge_base_id'] : null,
        'draft_limit'       => (int) $wf['article_count'],
        'is_loop'           => 1,
        'need_review'       => 0,
        'auto_keywords'     => 1,
        'auto_description'  => 1,
        'geo_mode'          => 1,
        'geo_scenario'      => 'B',
        'geo_brand_name'    => $wf['brand_name'],
        'geo_customer_id'   => (string) ($wf['customer_id'] ?? ''),
        'status'            => 'active',
    ]);

    $taskId = (int) $task['id'];

    // 立即启动并入队第一个 job
    $taskService->startTask($taskId, true);

    wf_update_workflow($db, $wf['workflow_id'], ['task_id' => $taskId]);

    return [
        'success' => true,
        'output'  => json_encode([
            'task_id' => $taskId,
            'draft_limit' => (int) $wf['article_count'],
            'geo_mode' => true,
            'geo_scenario' => 'B',
            'geo_brand_name' => $wf['brand_name'],
            'geo_customer_id' => (string) ($wf['customer_id'] ?? ''),
            'model_name' => $aiModel['name'] ?? '',
            'model_id' => $aiModel['model_id'] ?? '',
            'api_url' => $aiModel['api_url'] ?? '',
        ], JSON_UNESCAPED_UNICODE),
        'data' => ['task_id' => $taskId]
    ];
}

/**
 * Step 7: 等待文章生成
 */
function step_generate(PDO $db, array $wf): array {
    $taskId = (int) $wf['task_id'];
    $target = (int) $wf['article_count'];
    $minToContinue = max(1, (int) env_value('AUTOMATION_MIN_ARTICLES_TO_CONTINUE', min(1, $target)));
    $maxWait = 600; // 最多等10分钟
    $elapsed = 0;
    $interval = 10;

    while ($elapsed < $maxWait) {
        // 检查工作流是否被取消
        $current = wf_get_workflow($db, $wf['workflow_id']);
        if (!$current || $current['status'] === 'cancelled') {
            throw new RuntimeException("工作流已被取消");
        }

        $stmt = $db->prepare("SELECT COUNT(*) FROM articles WHERE task_id = ? AND deleted_at IS NULL");
        $stmt->execute([$taskId]);
        $count = (int) $stmt->fetchColumn();

        if ($count >= $target) {
            return [
                'success' => true,
                'output'  => json_encode(['article_count' => $count, 'target' => $target], JSON_UNESCAPED_UNICODE),
                'data'    => ['article_count' => $count]
            ];
        }

        if ($count >= $minToContinue) {
            $queueService = new JobQueueService($db);
            $hasMoreJobs = $queueService->hasPendingOrRunningJob($taskId);
            if (!$hasMoreJobs && $count < $target) {
                $queueService->enqueueTaskJob($taskId);
                $hasMoreJobs = true;
            }
            wf_log($wf['workflow_id'], "文章已生成 {$count}/{$target}，首篇文章已就绪，先进入媒体分发和监测；剩余文章继续由任务队列生成，生成后会自动进入分发队列。");

            return [
                'success' => true,
                'output'  => json_encode([
                    'article_count' => $count,
                    'target' => $target,
                    'partial' => true,
                    'min_to_continue' => $minToContinue,
                    'has_more_jobs' => $hasMoreJobs,
                ], JSON_UNESCAPED_UNICODE),
                'data'    => ['article_count' => $count, 'partial' => true]
            ];
        }

        // 检查任务是否还有 pending/running 的 job，如果没有且文章不够，补一个
        $queueService = new JobQueueService($db);
        if (!$queueService->hasPendingOrRunningJob($taskId)) {
            $queueService->enqueueTaskJob($taskId);
        }

        sleep($interval);
        $elapsed += $interval;
        wf_log($wf['workflow_id'], "等待文章生成... {$count}/{$target} ({$elapsed}s)");
    }

    // 超时仍未达到目标时，允许已有文章先进入分发/监测；0 篇仍然失败，避免伪完成。
    $stmt = $db->prepare("SELECT COUNT(*) FROM articles WHERE task_id = ? AND deleted_at IS NULL");
    $stmt->execute([$taskId]);
    $count = (int) $stmt->fetchColumn();

    if ($count < $minToContinue) {
        $latestJobError = wf_get_latest_job_error($db, $taskId);
        throw new RuntimeException("文章生成超时：已生成 {$count}/{$target}，低于继续流程所需 {$minToContinue} 篇。{$latestJobError}");
    }

    $queueService = new JobQueueService($db);
    $hasMoreJobs = $queueService->hasPendingOrRunningJob($taskId);
    if (!$hasMoreJobs && $count < $target) {
        $queueService->enqueueTaskJob($taskId);
        $hasMoreJobs = true;
    }
    wf_log($wf['workflow_id'], "文章已生成 {$count}/{$target}，首篇文章已就绪，先进入媒体分发和监测；剩余文章继续由任务队列生成，生成后会自动进入分发队列。");

    return [
        'success' => true,
        'output'  => json_encode([
            'article_count' => $count,
            'target' => $target,
            'partial' => $count < $target,
            'min_to_continue' => $minToContinue,
            'has_more_jobs' => $hasMoreJobs,
        ], JSON_UNESCAPED_UNICODE),
        'data'    => ['article_count' => $count, 'partial' => $count < $target]
    ];
}

/**
 * Step 8: 分发到媒体平台
 */
function step_distribute(PDO $db, array $wf): array {
    $taskId = (int) $wf['task_id'];

    // 查找该任务的所有文章
    $stmt = $db->prepare("SELECT id, status FROM articles WHERE task_id = ? AND deleted_at IS NULL ORDER BY id ASC");
    $stmt->execute([$taskId]);
    $articles = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($articles)) {
        throw new RuntimeException("没有可分发的文章，不能标记分发完成");
    }

    $articleService = new ArticleService($db);
    $published = 0;
    $failed = 0;

    foreach ($articles as $article) {
        try {
            if ($article['status'] === 'published') {
                $published++;
                continue;
            }
            $articleService->publishArticle((int) $article['id']);
            $published++;
        } catch (Throwable $e) {
            $failed++;
            wf_log($wf['workflow_id'], "发布文章 #{$article['id']} 失败: " . $e->getMessage());
        }
    }

    return [
        'success' => true,
        'output'  => json_encode([
            'total' => count($articles),
            'published' => $published,
            'failed' => $failed,
        ], JSON_UNESCAPED_UNICODE),
        'data' => ['published' => $published]
    ];
}

/**
 * Step 9: 设置监测关键词
 */
function step_monitor(PDO $db, array $wf): array {
    $customerId = $wf['customer_id'];
    if (!$customerId) {
        return ['success' => true, 'output' => '{"skipped":"no_customer_id"}', 'data' => []];
    }

    // 从关键词库中取前10个作为监测词
    $monitorKeywords = [];
    if ($wf['keyword_library_id']) {
        $stmt = $db->prepare("SELECT keyword FROM keywords WHERE library_id = ? ORDER BY id ASC LIMIT 10");
        $stmt->execute([$wf['keyword_library_id']]);
        $monitorKeywords = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    // 加上品牌名
    $monitorKeywords[] = $wf['brand_name'];
    $monitorKeywords = array_values(array_unique($monitorKeywords));

    if (!empty($monitorKeywords)) {
        $monitorService = new MonitorApiService($db);
        $monitorService->addKeywords($customerId, $monitorKeywords);
    }

    $monitorRunStarted = wf_start_geo_monitor_run_async($customerId);
    if ($monitorRunStarted) {
        wf_log($wf['workflow_id'], "已启动 GEO 监测后台任务：customer={$customerId}");
    } else {
        wf_log($wf['workflow_id'], "GEO 监测关键词已添加；未能启动后台监测任务，请检查 bin/geo-monitor-run.php");
    }

    return [
        'success' => true,
        'output'  => json_encode([
            'customer_id' => $customerId,
            'keyword_count' => count($monitorKeywords),
            'keywords' => $monitorKeywords,
            'monitor_run_started' => $monitorRunStarted,
        ], JSON_UNESCAPED_UNICODE),
        'data' => ['monitor_keywords' => $monitorKeywords]
    ];
}

function wf_start_geo_monitor_run_async(string $customerId): bool {
    $customerId = trim($customerId);
    if ($customerId === '') {
        return false;
    }

    $script = realpath(dirname(__DIR__) . '/bin/geo-monitor-run.php') ?: '';
    if ($script === '') {
        return false;
    }

    $runner = env_value('GEO_MONITOR_PHP_RUNNER', '');
    if ($runner === '') {
        $runner = PHP_BINARY ?: 'php';
    }

    $parts = preg_split('/\s+/', trim($runner)) ?: [];
    $parts = array_values(array_filter($parts, static fn ($part) => $part !== ''));
    $runnerCommand = empty($parts) ? 'php ' : implode(' ', array_map('escapeshellarg', $parts)) . ' ';
    $logFile = dirname(__DIR__) . '/bin/logs/geo_monitor_' . date('Y-m-d') . '.log';
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }

    $command = $runnerCommand
        . escapeshellarg($script) . ' '
        . '--customer=' . escapeshellarg($customerId) . ' '
        . '>> ' . escapeshellarg($logFile) . ' 2>&1 &';
    exec($command);

    return true;
}

// ═══════════════════════════════════════════════════════════
//  步骤路由
// ═══════════════════════════════════════════════════════════

function wf_execute_step(PDO $db, array $wf, string $stepId): array {
    switch ($stepId) {
        case 'collect':    return step_collect($db, $wf);
        case 'diagnosis':  return step_diagnosis($db, $wf);
        case 'keywords':   return step_keywords($db, $wf);
        case 'titles':     return step_titles($db, $wf);
        case 'knowledge':  return step_knowledge($db, $wf);
        case 'customer':   return step_customer($db, $wf);
        case 'task':       return step_task($db, $wf);
        case 'generate':   return step_generate($db, $wf);
        case 'distribute': return step_distribute($db, $wf);
        case 'monitor':    return step_monitor($db, $wf);
        default:
            throw new RuntimeException("未知步骤: {$stepId}");
    }
}

// ═══════════════════════════════════════════════════════════
//  执行单个工作流
// ═══════════════════════════════════════════════════════════

function wf_run(PDO $db, string $workflowId): void {
    $wf = wf_get_workflow($db, $workflowId);
    if (!$wf) {
        wf_log($workflowId, "工作流不存在");
        return;
    }

    if ($wf['status'] !== 'running') {
        wf_log($workflowId, "工作流状态为 {$wf['status']}，跳过");
        return;
    }

    wf_log($workflowId, "开始执行工作流 - 品牌: {$wf['brand_name']}");

    // 获取所有步骤
    $stmt = $db->prepare("SELECT * FROM automation_workflow_steps WHERE workflow_id = ? ORDER BY step_order ASC");
    $stmt->execute([$workflowId]);
    $steps = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($steps as $step) {
        $stepId = $step['step_id'];
        $stepStatus = $step['status'];

        // 跳过已完成或出错的步骤
        if ($stepStatus === 'completed') {
            continue;
        }
        if ($stepStatus === 'error') {
            wf_log($workflowId, "步骤 {$stepId} 之前已失败，跳过");
            return;
        }

        // 更新当前步骤
        wf_update_workflow($db, $workflowId, ['current_step' => $stepId]);
        wf_update_step($db, $workflowId, $stepId, 'running');
        wf_log($workflowId, "▶ 执行步骤: {$stepId}");

        try {
            // 重新加载 workflow（因为前面的步骤可能更新了 ID 字段）
            $wf = wf_get_workflow($db, $workflowId);
            $result = wf_execute_step($db, $wf, $stepId);

            if ($result['success']) {
                wf_update_step($db, $workflowId, $stepId, 'completed', null, $result['output'] ?? null);
                wf_log($workflowId, "✓ 步骤 {$stepId} 完成");
            } else {
                $errorMsg = $result['error'] ?? '未知错误';
                wf_update_step($db, $workflowId, $stepId, 'error', $errorMsg);
                wf_update_workflow($db, $workflowId, ['status' => 'error', 'error_message' => "步骤 {$stepId} 失败: {$errorMsg}"]);
                wf_log($workflowId, "✗ 步骤 {$stepId} 失败: {$errorMsg}");
                return;
            }
        } catch (Throwable $e) {
            $errorMsg = $e->getMessage();
            wf_update_step($db, $workflowId, $stepId, 'error', $errorMsg);
            wf_update_workflow($db, $workflowId, ['status' => 'error', 'error_message' => "步骤 {$stepId} 异常: {$errorMsg}"]);
            wf_log($workflowId, "✗ 步骤 {$stepId} 异常: {$errorMsg}");
            return;
        }
    }

    // 所有步骤完成
    wf_update_workflow($db, $workflowId, [
        'status'       => 'completed',
        'current_step' => 'done',
        'completed_at' => date('Y-m-d H:i:s'),
    ]);
    wf_log($workflowId, "🎉 工作流全部完成！品牌 [{$wf['brand_name']}] 入驻成功");
}

// ═══════════════════════════════════════════════════════════
//  Resume: 从失败步骤继续执行
// ═══════════════════════════════════════════════════════════

function wf_resume(PDO $db, string $workflowId): void {
    $wf = wf_get_workflow($db, $workflowId);
    if (!$wf) {
        wf_log($workflowId, "工作流不存在");
        return;
    }
    if ($wf['status'] !== 'error') {
        wf_log($workflowId, "工作流状态为 {$wf['status']}，无需恢复");
        return;
    }

    // 将所有 error 步骤重置为 pending
    $stmt = $db->prepare(
        "UPDATE automation_workflow_steps SET status = 'pending', error_message = NULL, updated_at = CURRENT_TIMESTAMP WHERE workflow_id = ? AND status = 'error'"
    );
    $stmt->execute([$workflowId]);
    $resetCount = $stmt->rowCount();
    wf_log($workflowId, "已重置 {$resetCount} 个失败步骤为 pending");

    // 更新工作流状态为 running
    wf_update_workflow($db, $workflowId, ['status' => 'running', 'error_message' => null]);

    // 复用 wf_run 从头遍历（已完成的会自动跳过）
    wf_run($db, $workflowId);
}

// ═══════════════════════════════════════════════════════════
//  主入口
// ═══════════════════════════════════════════════════════════

$targetWorkflowId = $argv[1] ?? null;
$isResume = in_array('--resume', $argv);

if ($targetWorkflowId) {
    // 执行或恢复指定工作流
    if ($isResume) {
        wf_resume($db, $targetWorkflowId);
    } else {
        wf_run($db, $targetWorkflowId);
    }
} else {
    // 轮询所有 running 状态的工作流
    $stmt = $db->prepare("SELECT workflow_id FROM automation_workflows WHERE status = 'running' ORDER BY created_at ASC");
    $stmt->execute();
    $workflows = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($workflows)) {
        echo "[" . date('Y-m-d H:i:s') . "] 没有待执行的工作流\n";
    } else {
        foreach ($workflows as $wfId) {
            wf_run($db, $wfId);
        }
    }
}
