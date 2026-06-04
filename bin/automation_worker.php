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
require_once $projectRoot . '/includes/distribution_service.php';
require_once $projectRoot . '/includes/distribution_publisher_service.php';

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
    try {
        $db->exec("ALTER TABLE automation_workflows ADD COLUMN IF NOT EXISTS media_account_ids TEXT DEFAULT '[]'");
    } catch (Throwable $e) {}
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

function wf_parse_competitors(string $text): array {
    $parts = preg_split('/[,，、\n\r]+/u', $text) ?: [];
    $competitors = [];
    foreach ($parts as $part) {
        $name = mb_substr(trim($part), 0, 100);
        if ($name !== '') {
            $competitors[] = $name;
        }
    }
    return array_values(array_unique($competitors));
}

function wf_truthy_bool($value): bool {
    if (is_bool($value)) {
        return $value;
    }
    $normalized = strtolower(trim((string) $value));
    return in_array($normalized, ['1', 't', 'true', 'yes', 'y'], true);
}

function wf_extract_json_object(string $text): ?array {
    $content = trim($text);
    $content = preg_replace('/^```(?:json)?\s*/i', '', $content);
    $content = preg_replace('/\s*```$/', '', $content);
    $content = trim($content);

    $decoded = json_decode($content, true);
    if (is_array($decoded)) {
        return $decoded;
    }

    $start = strpos($content, '{');
    $end = strrpos($content, '}');
    if ($start === false || $end === false || $end <= $start) {
        return null;
    }

    $json = substr($content, $start, $end - $start + 1);
    $decoded = json_decode($json, true);
    return is_array($decoded) ? $decoded : null;
}

function wf_build_intent_fallback(string $brandName, string $industry, string $coreServices, string $competitorsText, string $targetClient): array {
    $competitors = wf_parse_competitors($competitorsText);
    $mainCompetitor = $competitors[0] ?? '主要竞品';
    $service = $coreServices !== '' ? explode('、', str_replace([',', '，'], '、', $coreServices))[0] : $industry . '服务';
    $target = $targetClient !== '' && $targetClient !== '待补充' ? $targetClient : '目标客户';

    $templates = [
        '品牌认知' => [
            "{$brandName}是做什么的？",
            "{$brandName}适合哪些{$target}？",
            "{$brandName}靠谱吗？",
        ],
        '品类发现' => [
            "{$industry}有哪些值得关注的品牌？",
            "想做{$service}应该怎么选服务商？",
            "{$industry}用户怎么判断一个品牌是否专业？",
        ],
        '购买决策' => [
            "{$brandName}的服务适合什么预算阶段？",
            "选择{$brandName}前需要准备哪些资料？",
            "{$service}多久能看到效果？",
        ],
        '场景问题' => [
            "{$target}遇到{$industry}增长瓶颈怎么办？",
            "新品牌怎么在AI搜索里被推荐？",
            "怎么让AI回答更准确地提到{$brandName}？",
        ],
        '竞品对比' => [
            "{$brandName}和{$mainCompetitor}有什么区别？",
            "有没有比{$mainCompetitor}更适合{$target}的方案？",
            "{$brandName}相比同类品牌优势在哪里？",
        ],
        '风险质疑' => [
            "{$brandName}会不会没有效果？",
            "{$service}容易踩哪些坑？",
            "{$brandName}的数据和案例怎么验证？",
        ],
        '行业趋势' => [
            "{$industry}未来一年会怎么变化？",
            "AI搜索会怎样影响{$industry}品牌获客？",
            "{$industry}品牌为什么需要做GEO？",
        ],
    ];

    $themes = [];
    foreach ($templates as $dimension => $questions) {
        $themes[] = [
            'name' => $dimension . '意图',
            'icon' => '',
            'dimension' => $dimension,
            'questions' => array_map(static function (string $q) use ($dimension): array {
                return [
                    'q' => $q,
                    'intent' => $dimension,
                    'priority' => in_array($dimension, ['品牌认知', '品类发现', '购买决策', '竞品对比'], true) ? 'P0' : 'P1',
                    'covered' => false,
                    'reason' => '该问题会影响用户在AI回答中对品牌的认知、比较或选择。',
                    'suggested_action' => '补充FAQ、对比内容、案例证据和可引用品牌事实。',
                ];
            }, $questions),
        ];
    }

    return [
        'themes' => $themes,
        'gap_analysis' => [
            'total_questions' => 21,
            'covered_count' => 0,
            'gap_count' => 21,
            'p0_gaps' => 12,
            'top_gap_dimension' => '购买决策',
            'summary' => 'AI返回格式无效时已使用规则兜底生成基础意图池。建议后续用真实监测数据继续校准覆盖状态。优先补齐品牌认知、购买决策和竞品对比内容。',
            'fallback' => true,
        ],
    ];
}

function wf_sync_customer_competitors(PDO $db, string $customerId, array $competitors): int {
    if ($customerId === '' || empty($competitors)) {
        return 0;
    }

    $stmt = $db->prepare("
        INSERT INTO geo_customer_competitors (customer_id, competitor, enabled)
        VALUES (?, ?, TRUE)
        ON CONFLICT (customer_id, competitor) DO UPDATE SET enabled = TRUE
    ");

    $count = 0;
    foreach ($competitors as $competitor) {
        $competitor = mb_substr(trim((string) $competitor), 0, 100);
        if ($competitor === '') {
            continue;
        }
        $stmt->execute([$customerId, $competitor]);
        $count++;
    }

    return $count;
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

function wf_count_rows(PDO $db, string $sql, array $params = []): int {
    try {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function wf_find_latest_customer_task(PDO $db, string $customerId): ?array {
    if ($customerId === '') {
        return null;
    }

    $stmt = $db->prepare("
        SELECT *
        FROM tasks
        WHERE geo_customer_id = ?
        ORDER BY updated_at DESC, id DESC
        LIMIT 1
    ");
    $stmt->execute([$customerId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function wf_find_named_keyword_library(PDO $db, string $brandName): ?array {
    $stmt = $db->prepare("
        SELECT id, name, keyword_count
        FROM keyword_libraries
        WHERE name ILIKE ?
        ORDER BY updated_at DESC, id DESC
        LIMIT 1
    ");
    $stmt->execute(['%' . $brandName . '%']);
    $library = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$library) {
        return null;
    }
    $count = wf_count_rows($db, "SELECT COUNT(*) FROM keywords WHERE library_id = ?", [(int) $library['id']]);
    return $count > 0 ? array_merge($library, ['keyword_count' => $count]) : null;
}

function wf_find_named_title_library(PDO $db, string $brandName): ?array {
    $stmt = $db->prepare("
        SELECT id, name, title_count, keyword_library_id
        FROM title_libraries
        WHERE name ILIKE ?
        ORDER BY updated_at DESC, id DESC
        LIMIT 1
    ");
    $stmt->execute(['%' . $brandName . '%']);
    $library = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$library) {
        return null;
    }
    $count = wf_count_rows($db, "SELECT COUNT(*) FROM titles WHERE library_id = ?", [(int) $library['id']]);
    return $count > 0 ? array_merge($library, ['title_count' => $count]) : null;
}

function wf_find_named_chunked_knowledge_base(PDO $db, string $brandName): ?array {
    $stmt = $db->prepare("
        SELECT kb.id, kb.name,
               COUNT(kc.id) AS chunk_count,
               SUM(CASE WHEN COALESCE(kc.embedding_json, '') <> '' THEN 1 ELSE 0 END) AS embedded_count
        FROM knowledge_bases kb
        LEFT JOIN knowledge_chunks kc ON kc.knowledge_base_id = kb.id
        WHERE kb.name ILIKE ?
        GROUP BY kb.id, kb.name, kb.updated_at
        HAVING COUNT(kc.id) > 0
        ORDER BY kb.updated_at DESC, kb.id DESC
        LIMIT 1
    ");
    $stmt->execute(['%' . $brandName . '%']);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function wf_auto_skip_step(PDO $db, array $wf, string $stepId): ?array {
    $workflowId = (string) ($wf['workflow_id'] ?? '');
    $customerId = trim((string) ($wf['customer_id'] ?? ''));
    $brandName = trim((string) ($wf['brand_name'] ?? ''));
    $task = wf_find_latest_customer_task($db, $customerId);

    switch ($stepId) {
        case 'collect':
            if ($customerId !== '' && wf_count_rows($db, "SELECT COUNT(*) FROM geo_brand_facts WHERE customer_id = ?", [$customerId]) >= 3) {
                return [
                    'reason' => '客户品牌事实已存在，跳过品牌资料搜集',
                    'fields' => [],
                    'output' => ['skipped' => true, 'reason' => 'existing_brand_facts', 'customer_id' => $customerId],
                ];
            }
            break;

        case 'customer':
            if ($customerId !== '' && wf_count_rows($db, "SELECT COUNT(*) FROM customers WHERE customer_id = ?", [$customerId]) > 0) {
                $factCount = wf_seed_geo_brand_facts($db, $customerId, $wf);
                $competitorCount = wf_sync_customer_competitors($db, $customerId, wf_parse_competitors((string) ($wf['competitors'] ?? '')));
                return [
                    'reason' => "已选择客户 {$customerId}，跳过创建客户",
                    'fields' => ['customer_id' => $customerId],
                    'output' => [
                        'skipped' => true,
                        'reason' => 'existing_customer',
                        'customer_id' => $customerId,
                        'geo_brand_fact_count' => $factCount,
                        'competitor_count' => $competitorCount,
                    ],
                ];
            }
            break;

        case 'keywords':
            $library = null;
            if (!empty($wf['keyword_library_id'])) {
                $count = wf_count_rows($db, "SELECT COUNT(*) FROM keywords WHERE library_id = ?", [(int) $wf['keyword_library_id']]);
                if ($count > 0) {
                    $library = ['id' => (int) $wf['keyword_library_id'], 'keyword_count' => $count];
                }
            }
            if (!$library && $task && !empty($task['title_library_id'])) {
                $stmt = $db->prepare("
                    SELECT kl.id, COUNT(k.id) AS keyword_count
                    FROM title_libraries tl
                    JOIN keyword_libraries kl ON kl.id = tl.keyword_library_id
                    LEFT JOIN keywords k ON k.library_id = kl.id
                    WHERE tl.id = ?
                    GROUP BY kl.id
                    LIMIT 1
                ");
                $stmt->execute([(int) $task['title_library_id']]);
                $library = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            }
            if (!$library && $brandName !== '') {
                $library = wf_find_named_keyword_library($db, $brandName);
            }
            if ($library && (int) ($library['keyword_count'] ?? 0) > 0) {
                return [
                    'reason' => '关键词库已存在，跳过生成关键词库',
                    'fields' => ['keyword_library_id' => (int) $library['id']],
                    'output' => ['skipped' => true, 'reason' => 'existing_keyword_library', 'library_id' => (int) $library['id'], 'keyword_count' => (int) $library['keyword_count']],
                ];
            }
            break;

        case 'titles':
            $library = null;
            if (!empty($wf['title_library_id'])) {
                $count = wf_count_rows($db, "SELECT COUNT(*) FROM titles WHERE library_id = ?", [(int) $wf['title_library_id']]);
                if ($count > 0) {
                    $library = ['id' => (int) $wf['title_library_id'], 'title_count' => $count, 'keyword_library_id' => $wf['keyword_library_id'] ?? null];
                }
            }
            if (!$library && $task && !empty($task['title_library_id'])) {
                $stmt = $db->prepare("
                    SELECT tl.id, tl.keyword_library_id, COUNT(t.id) AS title_count
                    FROM title_libraries tl
                    LEFT JOIN titles t ON t.library_id = tl.id
                    WHERE tl.id = ?
                    GROUP BY tl.id, tl.keyword_library_id
                    LIMIT 1
                ");
                $stmt->execute([(int) $task['title_library_id']]);
                $library = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            }
            if (!$library && $brandName !== '') {
                $library = wf_find_named_title_library($db, $brandName);
            }
            if ($library && (int) ($library['title_count'] ?? 0) > 0) {
                $fields = ['title_library_id' => (int) $library['id']];
                if (!empty($library['keyword_library_id'])) {
                    $fields['keyword_library_id'] = (int) $library['keyword_library_id'];
                }
                return [
                    'reason' => '标题库已存在，跳过生成标题库',
                    'fields' => $fields,
                    'output' => ['skipped' => true, 'reason' => 'existing_title_library', 'library_id' => (int) $library['id'], 'title_count' => (int) $library['title_count']],
                ];
            }
            break;

        case 'knowledge':
            $kb = null;
            if (!empty($wf['knowledge_base_id'])) {
                $chunks = wf_count_rows($db, "SELECT COUNT(*) FROM knowledge_chunks WHERE knowledge_base_id = ?", [(int) $wf['knowledge_base_id']]);
                if ($chunks > 0) {
                    $kb = ['id' => (int) $wf['knowledge_base_id'], 'chunk_count' => $chunks];
                }
            }
            if (!$kb && $task && !empty($task['knowledge_base_id'])) {
                $chunks = wf_count_rows($db, "SELECT COUNT(*) FROM knowledge_chunks WHERE knowledge_base_id = ?", [(int) $task['knowledge_base_id']]);
                if ($chunks > 0) {
                    $kb = ['id' => (int) $task['knowledge_base_id'], 'chunk_count' => $chunks];
                }
            }
            if (!$kb && $brandName !== '') {
                $kb = wf_find_named_chunked_knowledge_base($db, $brandName);
            }
            if ($kb && (int) ($kb['chunk_count'] ?? 0) > 0) {
                return [
                    'reason' => '知识库已切割，跳过生成知识库',
                    'fields' => ['knowledge_base_id' => (int) $kb['id']],
                    'output' => [
                        'skipped' => true,
                        'reason' => 'existing_chunked_knowledge_base',
                        'knowledge_base_id' => (int) $kb['id'],
                        'chunk_count' => (int) $kb['chunk_count'],
                        'embedded_count' => (int) ($kb['embedded_count'] ?? 0),
                    ],
                ];
            }
            break;

        case 'knowledge_graph':
            if ($customerId !== '') {
                $count = wf_count_rows($db, "SELECT COUNT(*) FROM geo_brand_knowledge WHERE customer_id = ?", [$customerId]);
                if ($count >= 5) {
                    return [
                        'reason' => '知识图谱已有事实条目，跳过抽取',
                        'fields' => [],
                        'output' => ['skipped' => true, 'reason' => 'existing_knowledge_graph', 'total_inserted' => $count],
                    ];
                }
            }
            break;

        case 'intent_mining':
            if ($customerId !== '') {
                $count = wf_count_rows($db, "SELECT COUNT(*) FROM geo_intent_questions WHERE customer_id = ?", [$customerId]);
                if ($count >= 10) {
                    return [
                        'reason' => '意图问题池已存在，跳过意图挖掘',
                        'fields' => [],
                        'output' => ['skipped' => true, 'reason' => 'existing_intent_questions', 'total_questions' => $count],
                    ];
                }
            }
            break;

        case 'task':
            if ($task && !empty($task['id']) && !empty($task['title_library_id'])) {
                return [
                    'reason' => '客户已有 GEO 自动化任务，跳过创建任务',
                    'fields' => [
                        'task_id' => (int) $task['id'],
                        'title_library_id' => (int) $task['title_library_id'],
                        'knowledge_base_id' => !empty($task['knowledge_base_id']) ? (int) $task['knowledge_base_id'] : null,
                    ],
                    'output' => ['skipped' => true, 'reason' => 'existing_task', 'task_id' => (int) $task['id'], 'status' => $task['status'] ?? ''],
                ];
            }
            break;

        case 'generate':
            if (!empty($wf['task_id'])) {
                $count = wf_count_rows($db, "SELECT COUNT(*) FROM articles WHERE task_id = ? AND deleted_at IS NULL", [(int) $wf['task_id']]);
                if ($count >= 1) {
                    return [
                        'reason' => '客户任务已有文章，跳过首篇等待',
                        'fields' => [],
                        'output' => ['skipped' => true, 'reason' => 'existing_articles', 'article_count' => $count, 'target' => (int) ($wf['article_count'] ?? 0)],
                    ];
                }
            }
            break;
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

function wf_try_lock(PDO $db, string $workflowId): bool {
    try {
        $stmt = $db->prepare("SELECT pg_try_advisory_lock(hashtext(?))");
        $stmt->execute(['automation_workflow:' . $workflowId]);
        return filter_var($stmt->fetchColumn(), FILTER_VALIDATE_BOOLEAN);
    } catch (Throwable $e) {
        return true;
    }
}

function wf_unlock(PDO $db, string $workflowId): void {
    try {
        $stmt = $db->prepare("SELECT pg_advisory_unlock(hashtext(?))");
        $stmt->execute(['automation_workflow:' . $workflowId]);
    } catch (Throwable $e) {
        // Ignore unlock failures; unsupported databases will have skipped locking.
    }
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
        '餐饮' => '餐饮 / 新茶饮',
        '茶饮' => '餐饮 / 新茶饮',
        '饮品' => '餐饮 / 新茶饮',
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
 * Final display step: 生成雷达诊断
 *
 * 雷达诊断只作为客户展示报告，不参与关键词、标题、知识库、文章生成或全景诊断决策。
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
    $signals = is_array($report['signals'] ?? null) ? $report['signals'] : [];
    $firstMetric = is_array($signals[0]['raw_metric'] ?? null) ? $signals[0]['raw_metric'] : [];
    $dataSource = !empty($firstMetric['search_provider']) ? 'real_search' : 'estimated';

    return [
        'success' => true,
        'output' => json_encode([
            'diagnosis_id' => $diagnosisId,
            'overall_score' => $report ? round((float) ($report['overall_score'] ?? 0), 1) : null,
            'industry' => $report['industry'] ?? wf_map_diagnosis_industry((string) ($wf['industry'] ?? '')),
            'predicted_hit_rate' => $report['predicted_hit_rate'] ?? '',
            'data_source' => $dataSource,
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
    $competitors = wf_parse_competitors((string) ($wf['competitors'] ?? ''));
    $competitorCount = wf_sync_customer_competitors($db, $customerId, $competitors);
    wf_update_workflow($db, $wf['workflow_id'], ['customer_id' => $customerId]);

    return [
        'success' => true,
        'output'  => json_encode([
            'customer_id' => $customerId,
            'reused_existing_customer' => !$created,
            'geo_brand_fact_count' => $factCount,
            'competitor_count' => $competitorCount,
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
 * Step 6.5: 生成品牌知识图谱
 */
function step_knowledge_graph(PDO $db, array $wf): array {
    $customerId = trim((string) ($wf['customer_id'] ?? ''));
    if ($customerId === '') {
        throw new RuntimeException("客户尚未创建，无法生成知识图谱");
    }

    $templatePath = dirname(__DIR__) . '/prompts/knowledge_graph.md';
    if (!file_exists($templatePath)) {
        throw new RuntimeException("知识图谱模板不存在: {$templatePath}");
    }

    $collected = wf_get_step_output($db, (string) $wf['workflow_id'], 'collect');
    $collectedInfo = trim((string) ($collected['collected_info'] ?? ''));

    $template = file_get_contents($templatePath);
    $prompt = wf_fill_template($template, [
        'brand_name'    => $wf['brand_name'],
        'industry'      => $wf['industry'],
        'website'       => $wf['website'],
        'core_services' => $wf['services'],
        'competitors'   => $wf['competitors'],
        'positioning'   => $wf['positioning'],
        'collected_info'=> $collectedInfo ?: '暂无额外资料',
    ]);

    $aiOutput = wf_call_ai($prompt, 6144, $wf['workflow_id'], '生成品牌知识图谱');

    // 解析 JSON 输出
    $parsed = null;
    if (preg_match('/\{[\s\S]*"knowledge"[\s\S]*\}/', $aiOutput, $m)) {
        $parsed = json_decode($m[0], true);
    }
    if (!$parsed || empty($parsed['knowledge']) || !is_array($parsed['knowledge'])) {
        throw new RuntimeException("AI返回的知识图谱数据格式无效，请重试");
    }

    $categories = ['stat', 'case', 'credential', 'capability', 'claim'];
    $inserted = 0;
    $byCategory = [];

    $stmt = $db->prepare("
        INSERT INTO geo_brand_knowledge (customer_id, category, title, content, source, citability_score)
        VALUES (?, ?, ?, ?, ?, ?)
    ");

    foreach ($parsed['knowledge'] as $item) {
        $cat = trim((string) ($item['category'] ?? ''));
        $title = trim((string) ($item['title'] ?? ''));
        $content = trim((string) ($item['content'] ?? ''));
        $source = trim((string) ($item['source'] ?? ''));
        $score = max(1, min(5, (int) ($item['citability_score'] ?? 3)));

        if (!in_array($cat, $categories, true) || $title === '' || $content === '') {
            continue;
        }

        $stmt->execute([$customerId, $cat, mb_substr($title, 0, 200), $content, mb_substr($source, 0, 200), $score]);
        $inserted++;
        $byCategory[$cat] = ($byCategory[$cat] ?? 0) + 1;
    }

    if ($inserted < 5) {
        throw new RuntimeException("AI生成的有效知识条目过少（仅{$inserted}条），请重试");
    }

    wf_log($wf['workflow_id'], "知识图谱生成完成：{$inserted} 条，分布：" . json_encode($byCategory, JSON_UNESCAPED_UNICODE));

    return [
        'success' => true,
        'output'  => json_encode([
            'customer_id' => $customerId,
            'total_inserted' => $inserted,
            'by_category' => $byCategory,
            'model_used' => wf_last_ai_model(),
        ], JSON_UNESCAPED_UNICODE),
        'data' => ['knowledge_graph_count' => $inserted]
    ];
}

/**
 * Step 7.5: 生成意图挖掘
 */
function step_intent_mining(PDO $db, array $wf): array {
    $customerId = trim((string) ($wf['customer_id'] ?? ''));
    if ($customerId === '') {
        throw new RuntimeException("客户尚未创建，无法进行意图挖掘");
    }

    $templatePath = dirname(__DIR__) . '/prompts/intent_mining.md';
    if (!file_exists($templatePath)) {
        throw new RuntimeException("意图挖掘模板不存在: {$templatePath}");
    }

    // 加载品牌事实
    $stmt = $db->prepare("SELECT fact_key, fact_value FROM geo_brand_facts WHERE customer_id=?");
    $stmt->execute([$customerId]);
    $facts = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $facts[$r['fact_key']] = $r['fact_value'];

    $brandName      = $facts['brand_name'] ?? $wf['brand_name'];
    $industry       = $facts['industry'] ?? $wf['industry'];
    $coreServices   = $facts['core_services'] ?? $facts['core_service'] ?? $wf['services'];
    $masterSentence = $facts['master_sentence'] ?? $wf['positioning'];
    $differentiator = $facts['differentiator'] ?? '';
    $targetClient   = $facts['target_client'] ?? '';
    $competitorsText = trim((string) ($facts['competitors'] ?? $wf['competitors'] ?? ''));

    // 已监测关键词（新客户通常为空）
    $stmtKw = $db->prepare("
        SELECT query_text, COUNT(*) AS total,
               SUM(CASE WHEN brand_mentioned THEN 1 ELSE 0 END) AS hits
        FROM geo_monitor_records
        WHERE customer_id=? AND queried_at >= CURRENT_DATE - INTERVAL '29 days'
        GROUP BY query_text ORDER BY hits DESC
    ");
    $stmtKw->execute([$customerId]);
    $kwData = $stmtKw->fetchAll(PDO::FETCH_ASSOC);
    $existingKwText = '';
    foreach ($kwData as $kw) {
        $rate = $kw['total'] > 0 ? round($kw['hits'] / $kw['total'] * 100, 1) : 0;
        $existingKwText .= "- 「{$kw['query_text']}」提及率 {$rate}%（{$kw['hits']}/{$kw['total']}）\n";
    }
    if (empty($existingKwText)) $existingKwText = "暂无监测数据\n";

    // 已发布文章（新客户通常为空）
    $stmtArt = $db->prepare("
        SELECT a.title FROM articles a
        JOIN tasks t ON t.id = a.task_id
        WHERE t.geo_customer_id = ? AND a.deleted_at IS NULL
        ORDER BY a.created_at DESC LIMIT 30
    ");
    $stmtArt->execute([$customerId]);
    $articles = $stmtArt->fetchAll(PDO::FETCH_COLUMN);
    $articleText = !empty($articles) ? implode("\n", array_map(fn($a) => "- {$a}", $articles)) : "暂无已发布文章\n";

    // 品牌事实摘要
    $factSummary = '';
    foreach (['brand_name','industry','core_service','core_services','master_sentence','differentiator','target_client','website'] as $fk) {
        if (!empty($facts[$fk])) $factSummary .= "- {$fk}: {$facts[$fk]}\n";
    }

    // 加载 prompt 模板
    $template = file_get_contents($templatePath);
    $prompt = wf_fill_template($template, [
        'brand_name'       => $brandName,
        'master_sentence'  => $masterSentence,
        'core_services'    => $coreServices,
        'differentiator'   => $differentiator ?: '待补充',
        'target_client'    => $targetClient ?: '待补充',
        'industry'         => $industry,
        '行业'             => $industry,
        '服务'             => $coreServices,
        '竞品'             => $competitorsText !== '' ? $competitorsText : '主要竞品',
        'competitors'      => $competitorsText !== '' ? $competitorsText : '主要竞品',
        'existing_keywords'=> $existingKwText,
        'existing_articles'=> $articleText,
        'brand_facts_summary'=> $factSummary ?: '暂无',
    ]);

    $aiOutput = wf_call_ai($prompt, 6144, $wf['workflow_id'], '意图挖掘');

    // 解析 JSON；若模型输出格式不稳定，使用规则兜底，避免自动化卡死。
    $parsed = wf_extract_json_object($aiOutput);
    if (!$parsed || empty($parsed['themes']) || !is_array($parsed['themes'])) {
        wf_log($wf['workflow_id'], "意图挖掘 AI 输出格式无效，启用规则兜底。输出片段：" . mb_substr(trim($aiOutput), 0, 240));
        $parsed = wf_build_intent_fallback($brandName, $industry, $coreServices, $competitorsText, $targetClient);
    }

    // 清空旧数据并写入
    $db->prepare("DELETE FROM geo_intent_questions WHERE customer_id=?")->execute([$customerId]);
    $ins = $db->prepare("INSERT INTO geo_intent_questions
        (customer_id, theme, question, intent_type, priority, covered, reason, suggested_action, dimension)
        VALUES (?,?,?,?,?,?,?,?,?)");

    $totalQuestions = 0;
    $byDimension = [];

    foreach ($parsed['themes'] as $theme) {
        foreach (($theme['questions'] ?? []) as $q) {
            $dim = $theme['dimension'] ?? $q['intent'] ?? '';
            $ins->execute([
                $customerId,
                $theme['name'],
                $q['q'],
                $q['intent'] ?? $dim,
                $q['priority'] ?? 'P1',
                ($q['covered'] ?? false) ? 1 : 0,
                $q['reason'] ?? '',
                $q['suggested_action'] ?? '',
                $dim,
            ]);
            $totalQuestions++;
            $byDimension[$dim] = ($byDimension[$dim] ?? 0) + 1;
        }
    }

    if ($totalQuestions < 10) {
        throw new RuntimeException("AI生成的有效意图问题过少（仅{$totalQuestions}条），请重试");
    }

    $gapAnalysis = $parsed['gap_analysis'] ?? [];
    wf_log($wf['workflow_id'], "意图挖掘完成：{$totalQuestions} 个问题，维度分布：" . json_encode($byDimension, JSON_UNESCAPED_UNICODE));

    return [
        'success' => true,
        'output'  => json_encode([
            'customer_id'     => $customerId,
            'total_questions' => $totalQuestions,
            'by_dimension'    => $byDimension,
            'gap_analysis'    => $gapAnalysis,
            'model_used'      => wf_last_ai_model(),
        ], JSON_UNESCAPED_UNICODE),
        'data' => ['intent_question_count' => $totalQuestions]
    ];
}

/**
 * Step 8: 创建并启动任务
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
    $selectedAccountIds = json_decode((string) ($wf['media_account_ids'] ?? '[]'), true);
    $selectedAccountIds = is_array($selectedAccountIds)
        ? array_values(array_unique(array_filter(array_map('intval', $selectedAccountIds))))
        : [];

    if (empty($selectedAccountIds)) {
        return [
            'success' => false,
            'error' => '没有选择发布平台/账号，媒体分发不会真正发到外部平台。',
            'output' => json_encode(['skipped_reason' => 'no_media_accounts_selected'], JSON_UNESCAPED_UNICODE),
        ];
    }

    // 查找该任务的所有文章
    $stmt = $db->prepare("SELECT id, status FROM articles WHERE task_id = ? AND deleted_at IS NULL ORDER BY id ASC");
    $stmt->execute([$taskId]);
    $articles = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($articles)) {
        throw new RuntimeException("没有可分发的文章，不能标记分发完成");
    }

    $published = 0;
    $failed = 0;
    $createdJobs = 0;
    $startedJobs = 0;
    $manualJobs = 0;
    $autoJobs = 0;
    $successfulAutoJobs = 0;
    $failedAutoJobs = 0;
    $skippedAutoJobs = 0;
    $errors = [];
    $seenJobIds = [];

    foreach ($articles as $article) {
        try {
            if ($article['status'] === 'published') {
                $published++;
            } else {
                $db->prepare("
                    UPDATE articles
                    SET status = 'published',
                        review_status = CASE
                            WHEN review_status IN ('approved', 'auto_approved') THEN review_status
                            ELSE 'auto_approved'
                        END,
                        published_at = COALESCE(published_at, CURRENT_TIMESTAMP),
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id = ? AND deleted_at IS NULL
                ")->execute([(int) $article['id']]);
                $published++;
            }

            $createdIds = distribution_enqueue_article_jobs($db, (int) $article['id'], $selectedAccountIds);
            $createdJobs += count($createdIds);

            $placeholders = implode(',', array_fill(0, count($selectedAccountIds), '?'));
            $jobStmt = $db->prepare("
                SELECT j.id, COALESCE(ma.publish_mode, 'browser') AS publish_mode, j.status
                FROM media_publish_jobs j
                LEFT JOIN media_accounts ma ON ma.id = j.account_id
                WHERE j.article_id = ?
                  AND j.account_id IN ({$placeholders})
                ORDER BY j.id ASC
            ");
            $jobStmt->execute(array_merge([(int) $article['id']], $selectedAccountIds));

            foreach ($jobStmt->fetchAll(PDO::FETCH_ASSOC) as $jobRow) {
                $jobId = (int) ($jobRow['id'] ?? 0);
                if ($jobId <= 0 || isset($seenJobIds[$jobId])) {
                    continue;
                }
                $seenJobIds[$jobId] = true;

                if (($jobRow['publish_mode'] ?? '') === 'manual') {
                    $manualJobs++;
                    continue;
                }

                $autoJobs++;
                $currentStatus = (string) ($jobRow['status'] ?? '');
                if ($currentStatus === 'success') {
                    $successfulAutoJobs++;
                    continue;
                }

                $startedJobs++;
                wf_log($wf['workflow_id'], "媒体分发任务 #{$jobId} 将打开可见浏览器；如遇登录/扫码/验证码，请在弹出的浏览器里完成验证，系统会继续发布。");
                $result = distribution_execute_publish_job($db, $jobId, true);
                $resultStatus = (string) ($result['status'] ?? '');
                if ($resultStatus === 'success') {
                    $successfulAutoJobs++;
                } elseif ($resultStatus === 'skipped') {
                    $skippedAutoJobs++;
                    $errors[] = '任务 #' . $jobId . ' 暂未执行：' . (string) ($result['error_message'] ?? '排期或频控限制');
                } else {
                    $failedAutoJobs++;
                    $errors[] = '任务 #' . $jobId . ' 发布失败：' . (string) ($result['error_message'] ?? '未知错误');
                }
            }
        } catch (Throwable $e) {
            $failed++;
            wf_log($wf['workflow_id'], "发布文章 #{$article['id']} 失败: " . $e->getMessage());
            $errors[] = '文章 #' . (int) $article['id'] . ' 分发失败：' . $e->getMessage();
        }
    }

    $output = json_encode([
        'total' => count($articles),
        'published' => $published,
        'failed' => $failed,
        'selected_account_ids' => $selectedAccountIds,
        'media_jobs_created' => $createdJobs,
        'media_jobs_started' => $startedJobs,
        'auto_jobs' => $autoJobs,
        'manual_jobs' => $manualJobs,
        'auto_success' => $successfulAutoJobs,
        'auto_failed' => $failedAutoJobs,
        'auto_skipped' => $skippedAutoJobs,
        'errors' => array_slice($errors, 0, 5),
    ], JSON_UNESCAPED_UNICODE);

    if ($autoJobs <= 0) {
        return [
            'success' => false,
            'error' => "所选账号均为人工辅助账号，只创建了 {$manualJobs} 条待办，没有真实自动发布到外部平台。",
            'output' => $output,
        ];
    }

    if ($successfulAutoJobs <= 0) {
        $errorText = !empty($errors) ? implode('；', array_slice($errors, 0, 3)) : '自动发布未返回成功状态';
        return [
            'success' => false,
            'error' => "媒体分发没有成功发布到外部平台：{$errorText}",
            'output' => $output,
        ];
    }

    return [
        'success' => true,
        'output'  => $output,
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
    $monitorKeywords = array_merge($monitorKeywords, wf_parse_competitors((string) ($wf['competitors'] ?? '')));
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

function step_panorama(PDO $db, array $wf): array {
    $customerId = trim((string) ($wf['customer_id'] ?? ''));
    if ($customerId === '') {
        return ['success' => false, 'error' => '缺少客户 ID，无法生成全景诊断'];
    }

    $deadline = time() + 600;
    $recordCount = 0;
    do {
        $stmtCount = $db->prepare("
            SELECT COUNT(*)
            FROM geo_monitor_records
            WHERE customer_id = ?
              AND queried_at >= CURRENT_DATE - INTERVAL '29 days'
        ");
        $stmtCount->execute([$customerId]);
        $recordCount = (int) $stmtCount->fetchColumn();
        if ($recordCount > 0) {
            break;
        }
        wf_log($wf['workflow_id'], "等待 GEO 监测记录产出后生成全景诊断...");
        sleep(10);
    } while (time() < $deadline);

    if ($recordCount === 0) {
        return ['success' => false, 'error' => 'GEO 监测尚未产出记录，无法生成全景诊断档案'];
    }

    $facts = [];
    $stmtF = $db->prepare("SELECT fact_key, fact_value FROM geo_brand_facts WHERE customer_id = ?");
    $stmtF->execute([$customerId]);
    foreach ($stmtF->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $facts[(string) $row['fact_key']] = (string) $row['fact_value'];
    }
    $brandName = $facts['brand_name'] ?? (string) ($wf['brand_name'] ?? $customerId);

    $stmtM = $db->prepare("
        SELECT provider, query_text, brand_mentioned, mention_depth,
               competitors_found, accuracy_score, queried_at::text AS day
        FROM geo_monitor_records
        WHERE customer_id = ?
          AND queried_at >= CURRENT_DATE - INTERVAL '29 days'
        ORDER BY queried_at DESC
    ");
    $stmtM->execute([$customerId]);
    $records = $stmtM->fetchAll(PDO::FETCH_ASSOC);
    if (empty($records)) {
        return ['success' => false, 'error' => '未读取到可用于全景诊断的监测记录'];
    }

    $platformStats = [];
    $kwStats = [];
    $compOverall = [];
    foreach ($records as $row) {
        $provider = (string) ($row['provider'] ?? 'unknown');
        $keyword = (string) ($row['query_text'] ?? '');
        if (!isset($platformStats[$provider])) {
            $platformStats[$provider] = ['total' => 0, 'hit' => 0];
        }
        $platformStats[$provider]['total']++;
        if (wf_truthy_bool($row['brand_mentioned'] ?? false)) {
            $platformStats[$provider]['hit']++;
        }
        if (!isset($kwStats[$keyword])) {
            $kwStats[$keyword] = ['total' => 0, 'hit' => 0, 'comp' => []];
        }
        $kwStats[$keyword]['total']++;
        if (wf_truthy_bool($row['brand_mentioned'] ?? false)) {
            $kwStats[$keyword]['hit']++;
        }
        $found = json_decode((string) ($row['competitors_found'] ?? '[]'), true);
        if (!is_array($found)) {
            continue;
        }
        foreach ($found as $item) {
            $name = is_array($item) ? trim((string) ($item['name'] ?? '')) : '';
            if ($name === '') {
                continue;
            }
            $kwStats[$keyword]['comp'][$name] = ($kwStats[$keyword]['comp'][$name] ?? 0) + 1;
            $compOverall[$name] = ($compOverall[$name] ?? 0) + 1;
        }
    }

    $signals = [];

    $stmtAlerts = $db->prepare("
        SELECT alert_type, level, keyword, competitor_name, brand_rate, competitor_rate, detail
        FROM geo_monitor_alerts
        WHERE customer_id = ?
          AND alerted_at >= CURRENT_DATE - INTERVAL '7 days'
        ORDER BY CASE level WHEN 'high' THEN 1 WHEN 'medium' THEN 2 ELSE 3 END
        LIMIT 5
    ");
    $stmtAlerts->execute([$customerId]);
    $alerts = $stmtAlerts->fetchAll(PDO::FETCH_ASSOC);

    $templatePath = dirname(__DIR__) . '/prompts/panorama_diagnosis.md';
    if (!is_file($templatePath)) {
        return ['success' => false, 'error' => '全景诊断 prompt 模板不存在'];
    }
    $template = file_get_contents($templatePath);

    $platformText = '';
    foreach ($platformStats as $provider => $stats) {
        $rate = $stats['total'] > 0 ? round($stats['hit'] / $stats['total'] * 100, 1) : 0;
        $sampleNote = $stats['total'] < 5 ? '（样本不足，仅供参考）' : '';
        $platformText .= "- {$provider}：提及率 {$rate}%（{$stats['hit']}/{$stats['total']}）{$sampleNote}\n";
    }

    $kwSorted = $kwStats;
    uasort($kwSorted, static function ($a, $b): int {
        $ra = $a['total'] > 0 ? $a['hit'] / $a['total'] : 0;
        $rb = $b['total'] > 0 ? $b['hit'] / $b['total'] : 0;
        return $ra <=> $rb;
    });
    $kwText = '';
    foreach (array_slice($kwSorted, 0, 15, true) as $keyword => $stats) {
        $rate = $stats['total'] > 0 ? round($stats['hit'] / $stats['total'] * 100, 1) : 0;
        $compInfo = '';
        if (!empty($stats['comp'])) {
            arsort($stats['comp']);
            $parts = [];
            foreach (array_slice($stats['comp'], 0, 3, true) as $name => $count) {
                $parts[] = "{$name}({$count}次)";
            }
            $compInfo = ' | 竞品出现：' . implode('、', $parts);
        }
        $kwText .= "- 「{$keyword}」提及率 {$rate}%（{$stats['hit']}/{$stats['total']}）{$compInfo}\n";
    }

    $compText = '';
    if (!empty($compOverall)) {
        arsort($compOverall);
        foreach (array_slice($compOverall, 0, 8, true) as $name => $count) {
            $rate = round($count / count($records) * 100, 1);
            $compText .= "- {$name}：出现率 {$rate}%（{$count}/" . count($records) . "）\n";
        }
    } else {
        $compText = "监测范围内暂无竞品被 AI 回答提及\n";
    }

    $signalText = "雷达诊断已从自动化决策链剥离；本报告不使用雷达评分作为依据。\n";

    $alertText = '';
    if (!empty($alerts)) {
        foreach ($alerts as $alert) {
            $alertText .= "- [{$alert['level']}] {$alert['detail']}\n";
        }
    } else {
        $alertText = "近 7 天无告警\n";
    }

    $prompt = wf_fill_template($template, [
        'brand_name' => $brandName,
        'master_sentence' => $facts['master_sentence'] ?? '未提供',
        'core_services' => $facts['core_service'] ?? $facts['core_services'] ?? (string) ($wf['services'] ?? '未提供'),
        'differentiator' => $facts['differentiator'] ?? '未提供',
        'target_client' => $facts['target_client'] ?? '未提供',
        'industry' => $facts['industry'] ?? (string) ($wf['industry'] ?? '未提供'),
        'total_records' => (string) count($records),
        'platform_count' => (string) count($platformStats),
        'keyword_count' => (string) count($kwStats),
        'platform_text' => rtrim($platformText),
        'kw_text' => rtrim($kwText),
        'comp_text' => rtrim($compText),
        'signal_text' => rtrim($signalText),
        'alert_text' => rtrim($alertText),
    ]);

    $reportMd = wf_call_ai($prompt, 4096, $wf['workflow_id'], '生成全景诊断');
    $modelUsed = wf_last_ai_model() ?: 'unknown';
    $totalHit = array_sum(array_map(static fn ($stats) => (int) $stats['hit'], $platformStats));
    $totalAll = array_sum(array_map(static fn ($stats) => (int) $stats['total'], $platformStats));
    $overallRate = $totalAll > 0 ? round($totalHit / $totalAll * 100, 1) : 0;

    $stmtSave = $db->prepare("
        INSERT INTO geo_panorama_reports (
            customer_id, brand_name, report_md, prompt_used, model_used,
            overall_rate, total_records, platform_stats, kw_stats,
            comp_overall, signals, alerts
        ) VALUES (
            :customer_id, :brand_name, :report_md, :prompt_used, :model_used,
            :overall_rate, :total_records, CAST(:platform_stats AS jsonb), CAST(:kw_stats AS jsonb),
            CAST(:comp_overall AS jsonb), CAST(:signals AS jsonb), CAST(:alerts AS jsonb)
        )
        RETURNING id
    ");
    $stmtSave->execute([
        ':customer_id' => $customerId,
        ':brand_name' => $brandName,
        ':report_md' => $reportMd,
        ':prompt_used' => $prompt,
        ':model_used' => $modelUsed,
        ':overall_rate' => $overallRate,
        ':total_records' => count($records),
        ':platform_stats' => json_encode($platformStats, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ':kw_stats' => json_encode($kwStats, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ':comp_overall' => json_encode($compOverall, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ':signals' => json_encode($signals, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ':alerts' => json_encode($alerts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
    $reportId = (int) $stmtSave->fetchColumn();
    wf_log($wf['workflow_id'], "全景诊断档案已生成：report_id={$reportId}");

    return [
        'success' => true,
        'output' => json_encode([
            'customer_id' => $customerId,
            'report_id' => $reportId,
            'overall_rate' => $overallRate,
            'total_records' => count($records),
            'model_used' => $modelUsed,
        ], JSON_UNESCAPED_UNICODE),
        'data' => ['panorama_report_id' => $reportId],
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
        case 'collect':          return step_collect($db, $wf);
        case 'diagnosis':        return step_diagnosis($db, $wf);
        case 'keywords':         return step_keywords($db, $wf);
        case 'titles':           return step_titles($db, $wf);
        case 'knowledge':        return step_knowledge($db, $wf);
        case 'customer':         return step_customer($db, $wf);
        case 'knowledge_graph':  return step_knowledge_graph($db, $wf);
        case 'intent_mining':    return step_intent_mining($db, $wf);
        case 'task':             return step_task($db, $wf);
        case 'generate':         return step_generate($db, $wf);
        case 'distribute':       return step_distribute($db, $wf);
        case 'monitor':          return step_monitor($db, $wf);
        case 'panorama':         return step_panorama($db, $wf);
        default:
            throw new RuntimeException("未知步骤: {$stepId}");
    }
}

function wf_is_optional_step(string $stepId): bool {
    return $stepId === 'diagnosis';
}

function wf_complete_optional_step(PDO $db, string $workflowId, string $stepId, string $errorMsg): void {
    $output = json_encode([
        'optional' => true,
        'skipped' => true,
        'reason' => $errorMsg,
        'note' => '雷达诊断只作为最终客户展示，不影响文章生成、分发、监测或全景诊断。',
    ], JSON_UNESCAPED_UNICODE);

    wf_update_step($db, $workflowId, $stepId, 'completed', null, $output);
    wf_log($workflowId, "⚠ 可选展示步骤 {$stepId} 跳过，不影响主流程: {$errorMsg}");
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

    if (!wf_try_lock($db, $workflowId)) {
        wf_log($workflowId, "已有 worker 正在执行该工作流，跳过本次启动");
        return;
    }

    try {
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
            $skip = wf_auto_skip_step($db, $wf, $stepId);
            if ($skip !== null) {
                if (!empty($skip['fields']) && is_array($skip['fields'])) {
                    wf_update_workflow($db, $workflowId, $skip['fields']);
                }
                $output = json_encode($skip['output'] ?? ['skipped' => true], JSON_UNESCAPED_UNICODE);
                wf_update_step($db, $workflowId, $stepId, 'completed', null, $output);
                wf_log($workflowId, "↷ 步骤 {$stepId} 跳过: " . ($skip['reason'] ?? '已有可复用数据'));
                continue;
            }

            $result = wf_execute_step($db, $wf, $stepId);

            if ($result['success']) {
                wf_update_step($db, $workflowId, $stepId, 'completed', null, $result['output'] ?? null);
                wf_log($workflowId, "✓ 步骤 {$stepId} 完成");
            } else {
                $errorMsg = $result['error'] ?? '未知错误';
                if (wf_is_optional_step($stepId)) {
                    wf_complete_optional_step($db, $workflowId, $stepId, $errorMsg);
                    continue;
                }
                wf_update_step($db, $workflowId, $stepId, 'error', $errorMsg);
                wf_update_workflow($db, $workflowId, ['status' => 'error', 'error_message' => "步骤 {$stepId} 失败: {$errorMsg}"]);
                wf_log($workflowId, "✗ 步骤 {$stepId} 失败: {$errorMsg}");
                return;
            }
        } catch (Throwable $e) {
            $errorMsg = $e->getMessage();
            if (wf_is_optional_step($stepId)) {
                wf_complete_optional_step($db, $workflowId, $stepId, $errorMsg);
                continue;
            }
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
    } finally {
        wf_unlock($db, $workflowId);
    }
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
