<?php
/**
 * Export a sanitized demo seed from the current PostgreSQL database.
 *
 * Usage:
 *   php bin/export_demo_seed.php
 *
 * The output intentionally excludes admins, API tokens, logs, browser profiles,
 * queue runtime state, and raw credentials. It is safe to commit after review.
 */

define('FEISHU_TREASURE', true);

$projectRoot = dirname(__DIR__);
chdir($projectRoot);

require_once $projectRoot . '/includes/config.php';
require_once $projectRoot . '/includes/database_admin.php';

$outputFile = $projectRoot . '/docs/demo-data.sql';

$tables = [
    'categories',
    'tags',
    'authors',
    'prompts',
    'ai_models',
    'customers',
    'media_accounts',
    'keyword_libraries',
    'keywords',
    'title_libraries',
    'titles',
    'knowledge_bases',
    'articles',
    'tasks',
    'task_materials',
    'geo_diagnosis_brands',
    'geo_diagnosis_runs',
    'geo_diagnosis_signal_definitions',
    'geo_diagnosis_signal_scores',
    'geo_diagnosis_actions',
    'geo_diagnosis_industry_benchmarks',
    'geo_diagnosis_domain_authority',
    'geo_brand_facts',
    'geo_brand_knowledge',
    'geo_customer_competitors',
    'geo_intent_questions',
    'geo_monitor_keywords',
    'geo_monitor_records',
    'geo_monitor_alerts',
    'geo_panorama_reports',
    'sop_node_status',
    'automation_workflows',
    'automation_workflow_steps',
];

$truncateTables = array_reverse($tables);

function seed_table_exists(PDO $db, string $table): bool
{
    $stmt = $db->prepare("
        SELECT 1
        FROM information_schema.tables
        WHERE table_schema = 'public' AND table_name = ?
        LIMIT 1
    ");
    $stmt->execute([$table]);
    return (bool) $stmt->fetchColumn();
}

function seed_columns(PDO $db, string $table): array
{
    $stmt = $db->prepare("
        SELECT column_name
        FROM information_schema.columns
        WHERE table_schema = 'public' AND table_name = ?
        ORDER BY ordinal_position
    ");
    $stmt->execute([$table]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
}

function seed_column_types(PDO $db, string $table): array
{
    $stmt = $db->prepare("
        SELECT column_name, data_type
        FROM information_schema.columns
        WHERE table_schema = 'public' AND table_name = ?
    ");
    $stmt->execute([$table]);
    $types = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $types[(string) $row['column_name']] = (string) $row['data_type'];
    }
    return $types;
}

function seed_quote_ident(string $identifier): string
{
    return '"' . str_replace('"', '""', $identifier) . '"';
}

function seed_sql_value(PDO $db, $value): string
{
    if ($value === null) {
        return 'NULL';
    }
    if (is_bool($value)) {
        return $value ? 'TRUE' : 'FALSE';
    }
    return $db->quote((string) $value);
}

function seed_sanitize(string $table, array $row): array
{
    if ($table === 'ai_models') {
        $row['name'] = '示例聊天模型（请替换 API Key）';
        $row['api_key'] = '';
        $row['api_url'] = 'https://api.example.com/v1';
        $row['model_id'] = 'demo-chat-model';
        if (array_key_exists('version', $row)) {
            $row['version'] = 'demo';
        }
        $row['status'] = 'inactive';
        $row['used_today'] = 0;
        $row['total_used'] = 0;
    }

    if ($table === 'tasks') {
        foreach (['last_error_message', 'last_result'] as $field) {
            if (array_key_exists($field, $row)) {
                $row[$field] = '';
            }
        }
        if (array_key_exists('last_error_at', $row)) {
            $row['last_error_at'] = null;
        }
    }

    if ($table === 'media_accounts') {
        foreach (['credential', 'agent_secret'] as $field) {
            if (array_key_exists($field, $row)) {
                $row[$field] = '';
            }
        }
        if (array_key_exists('agent_base_url', $row)) {
            $row['agent_base_url'] = '';
        }
        if (array_key_exists('last_used_at', $row)) {
            $row['last_used_at'] = null;
        }
        if (array_key_exists('username', $row)) {
            $row['username'] = 'demo_' . ($row['platform'] ?? 'account');
        }
    }

    if ($table === 'customers') {
        if (array_key_exists('owner', $row)) {
            $row['owner'] = '演示顾问';
        }
        if (array_key_exists('contact_name', $row)) {
            $row['contact_name'] = '演示联系人';
        }
        if (array_key_exists('contact_phone', $row)) {
            $row['contact_phone'] = '';
        }
    }

    if ($table === 'geo_brand_facts') {
        $key = (string) ($row['fact_key'] ?? '');
        if (in_array($key, ['contact_name', 'contact_phone', 'contact_email', 'email', 'phone'], true)) {
            $row['fact_value'] = '';
        }
    }

    if ($table === 'geo_diagnosis_runs') {
        if (array_key_exists('requester_ip', $row)) {
            $row['requester_ip'] = null;
        }
        if (array_key_exists('requester_email', $row)) {
            $row['requester_email'] = '';
        }
    }

    if ($table === 'geo_monitor_records' && array_key_exists('provider', $row)) {
        $row['provider'] = 'demo-ai-provider';
    }

    if ($table === 'automation_workflows') {
        if (array_key_exists('media_account_ids', $row)) {
            $row['media_account_ids'] = '[]';
        }
        if (array_key_exists('status', $row)) {
            $row['status'] = 'completed';
        }
    }

    if ($table === 'automation_workflow_steps' && !empty($row['output_data'])) {
        $decoded = json_decode((string) $row['output_data'], true);
        if (is_array($decoded)) {
            $decoded = seed_sanitize_step_output($decoded);
            $row['output_data'] = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
    }

    return $row;
}

function seed_sanitize_step_output(array $value): array
{
    foreach ($value as $key => $item) {
        if (is_array($item)) {
            $value[$key] = seed_sanitize_step_output($item);
            continue;
        }

        $normalizedKey = strtolower((string) $key);
        if (in_array($normalizedKey, ['api_key', 'apikey', 'api_url', 'base_url', 'agent_secret', 'credential', 'token'], true)) {
            unset($value[$key]);
            continue;
        }
        if (in_array($normalizedKey, ['model', 'model_id', 'model_name', 'model_used', 'provider'], true)) {
            $value[$key] = 'demo-chat-model';
        }
    }

    return $value;
}

function seed_order_by(array $columns): string
{
    if (in_array('id', $columns, true)) {
        return ' ORDER BY id';
    }
    if (in_array('created_at', $columns, true)) {
        return ' ORDER BY created_at';
    }
    return '';
}

$lines = [];
$lines[] = '-- Sanitized demo data for GEO+AI content system.';
$lines[] = '-- Generated by bin/export_demo_seed.php on ' . date('Y-m-d H:i:s') . '.';
$lines[] = '-- This file does not include admin passwords, API tokens, API keys, browser profiles, or runtime logs.';
$lines[] = '';
$lines[] = 'BEGIN;';
$lines[] = 'SET session_replication_role = replica;';
$lines[] = '';

foreach ($truncateTables as $table) {
    if (seed_table_exists($db, $table)) {
        $lines[] = 'TRUNCATE TABLE ' . seed_quote_ident($table) . ' RESTART IDENTITY CASCADE;';
    }
}

$lines[] = '';
$sequenceSets = [];

foreach ($tables as $table) {
    if (!seed_table_exists($db, $table)) {
        continue;
    }

    $columns = seed_columns($db, $table);
    if (!$columns) {
        continue;
    }
    $columnTypes = seed_column_types($db, $table);

    $stmt = $db->query('SELECT * FROM ' . seed_quote_ident($table) . seed_order_by($columns));
    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    if (!$rows) {
        continue;
    }

    $quotedColumns = array_map('seed_quote_ident', $columns);
    $lines[] = '-- ' . $table . ' (' . count($rows) . ' rows)';

    foreach ($rows as $row) {
        $row = seed_sanitize($table, $row);
        $values = [];
        foreach ($columns as $column) {
            $values[] = seed_sql_value($db, $row[$column] ?? null);
        }
        $lines[] = 'INSERT INTO ' . seed_quote_ident($table)
            . ' (' . implode(', ', $quotedColumns) . ') VALUES ('
            . implode(', ', $values) . ');';
    }

    if (in_array('id', $columns, true) && in_array($columnTypes['id'] ?? '', ['bigint', 'integer', 'smallint'], true)) {
        $sequenceSets[] = "SELECT setval(pg_get_serial_sequence('{$table}', 'id'), COALESCE((SELECT MAX(id) FROM " . seed_quote_ident($table) . "), 1), true);";
    }

    $lines[] = '';
}

foreach ($sequenceSets as $sql) {
    $lines[] = $sql;
}

$lines[] = '';
$lines[] = 'SET session_replication_role = DEFAULT;';
$lines[] = 'COMMIT;';
$lines[] = '';

if (!is_dir(dirname($outputFile))) {
    mkdir(dirname($outputFile), 0755, true);
}

file_put_contents($outputFile, implode("\n", $lines));
echo "Demo seed exported to {$outputFile}\n";
