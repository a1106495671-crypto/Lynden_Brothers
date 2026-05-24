<?php
/**
 * 品牌入驻自动化 - 后台 Worker
 * 顺序执行 automation_workflow_steps 中的 9 个步骤
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

// ─── AI 调用封装（带重试） ────────────────────────────────
function wf_call_ai(string $prompt, int $maxTokens = 3000): string {
    $result = geo_call_ai_with_fallback($prompt, $maxTokens, 0.7);
    if (!empty($result['error'])) {
        throw new RuntimeException("AI调用失败: " . $result['error']);
    }
    if (empty($result['content'])) {
        throw new RuntimeException("AI返回空内容");
    }
    return $result['content'];
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

    $collected = wf_call_ai($prompt, 1500);

    // 存入 workflow 以供后续步骤使用
    wf_update_workflow($db, $wf['workflow_id'], [
        'error_message' => '' // clear any previous error
    ]);

    return [
        'success' => true,
        'output'  => json_encode(['collected_info' => $collected], JSON_UNESCAPED_UNICODE),
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

    $aiOutput = wf_call_ai($prompt, 3000);
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
        ], JSON_UNESCAPED_UNICODE),
        'data' => ['keyword_library_id' => $libraryId]
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

    $aiOutput = wf_call_ai($prompt, 3000);
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

    $knowledgeContent = wf_call_ai($prompt, 4000);

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
        ], JSON_UNESCAPED_UNICODE),
        'data' => ['knowledge_base_id' => $kbId]
    ];
}

/**
 * Step 5: 创建客户
 */
function step_customer(PDO $db, array $wf): array {
    $customerService = new CustomerService($db);
    $customer = $customerService->createCustomer([
        'name'     => $wf['brand_name'],
        'industry' => $wf['industry'],
        'domain'   => $wf['website'],
    ]);

    $customerId = $customer['customer_id'];
    wf_update_workflow($db, $wf['workflow_id'], ['customer_id' => $customerId]);

    return [
        'success' => true,
        'output'  => json_encode([
            'customer_id' => $customerId,
        ], JSON_UNESCAPED_UNICODE),
        'data' => ['customer_id' => $customerId]
    ];
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

    // 查找活跃的 AI 模型
    $stmt = $db->query("SELECT id FROM ai_models WHERE status='active' ORDER BY priority ASC NULLS LAST, id ASC LIMIT 1");
    $aiModel = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$aiModel) {
        throw new RuntimeException("系统中没有活跃的AI模型，请先在后台配置");
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

        // 检查任务是否还有 pending/running 的 job，如果没有且文章不够，补一个
        $queueService = new JobQueueService($db);
        if (!$queueService->hasPendingOrRunningJob($taskId)) {
            $queueService->enqueueTaskJob($taskId);
        }

        sleep($interval);
        $elapsed += $interval;
        wf_log($wf['workflow_id'], "等待文章生成... {$count}/{$target} ({$elapsed}s)");
    }

    // 超时，返回已有数量（不报错，继续后续步骤）
    $stmt = $db->prepare("SELECT COUNT(*) FROM articles WHERE task_id = ? AND deleted_at IS NULL");
    $stmt->execute([$taskId]);
    $count = (int) $stmt->fetchColumn();

    return [
        'success' => true,
        'output'  => json_encode(['article_count' => $count, 'target' => $target, 'timeout' => true], JSON_UNESCAPED_UNICODE),
        'data'    => ['article_count' => $count]
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

    return [
        'success' => true,
        'output'  => json_encode([
            'customer_id' => $customerId,
            'keyword_count' => count($monitorKeywords),
            'keywords' => $monitorKeywords,
        ], JSON_UNESCAPED_UNICODE),
        'data' => ['monitor_keywords' => $monitorKeywords]
    ];
}

// ═══════════════════════════════════════════════════════════
//  步骤路由
// ═══════════════════════════════════════════════════════════

function wf_execute_step(PDO $db, array $wf, string $stepId): array {
    switch ($stepId) {
        case 'collect':    return step_collect($db, $wf);
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
//  主入口
// ═══════════════════════════════════════════════════════════

$targetWorkflowId = $argv[1] ?? null;

if ($targetWorkflowId) {
    // 执行指定工作流
    wf_run($db, $targetWorkflowId);
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
