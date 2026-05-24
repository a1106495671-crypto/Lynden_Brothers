<?php
/**
 * geo_ai_fallback.php
 * Multi-model AI call with automatic fallback.
 * Usage: $r = geo_call_ai_with_fallback($prompt, 3000, 0.75);
 *        $r['content']    — generated text (empty string on failure)
 *        $r['model_used'] — model name that succeeded
 *        $r['error']      — null on success, error string on failure
 */
if (!function_exists('geo_call_ai_with_fallback')) {

function geo_call_ai_with_fallback(string $prompt, int $maxTokens = 3000, float $temperature = 0.75): array {
    global $db;
    $models = [];
    $errors = [];
    try {
        $stmt = $db->query(
            "SELECT * FROM ai_models
             WHERE status='active'
               AND (model_type='chat' OR model_type IS NULL OR model_type='')
               AND COALESCE(api_key, '') <> ''
               AND COALESCE(model_id, '') <> ''
             ORDER BY priority ASC NULLS LAST, id ASC"
        );
        $models = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}

    foreach ($models as $m) {
        $apiKey  = trim(function_exists('decrypt_ai_api_key') ? decrypt_ai_api_key((string)($m['api_key'] ?? '')) : ($m['api_key'] ?? ''));
        $modelId = trim($m['model_id'] ?? '');
        $apiUrl  = rtrim(trim($m['api_url'] ?? ''), '/');
        if (!$apiKey || !$modelId) continue;
        if ($apiUrl === '' || str_contains($modelId, 'placeholder') || str_contains($apiUrl, 'not-configured')) {
            continue;
        }
        if (!str_ends_with($apiUrl, '/chat/completions') && !str_ends_with($apiUrl, '/completions')) {
            $apiUrl .= '/chat/completions';
        }

        $payload = json_encode([
            'model'       => $modelId,
            'messages'    => [['role' => 'user', 'content' => $prompt]],
            'max_tokens'  => $maxTokens,
            'temperature' => $temperature,
        ], JSON_UNESCAPED_UNICODE);

        $ch = curl_init($apiUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 90,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ],
        ]);
        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError !== '') {
            if (function_exists('log_ai_api_call')) {
                log_ai_api_call([
                    'source' => 'automation_material',
                    'model_name' => (string) ($m['name'] ?? ''),
                    'model_id' => $modelId,
                    'api_url' => $apiUrl,
                    'http_code' => 0,
                    'ok' => false,
                    'error' => $curlError,
                ]);
            }
            $errors[] = ($m['name'] ?? $modelId) . " cURL: {$curlError}";
            continue;
        }

        if ($code === 200) {
            $data    = json_decode($raw, true);
            $content = $data['choices'][0]['message']['content'] ?? '';
            if (function_exists('log_ai_api_call')) {
                log_ai_api_call([
                    'source' => 'automation_material',
                    'model_name' => (string) ($m['name'] ?? ''),
                    'model_id' => $modelId,
                    'api_url' => $apiUrl,
                    'http_code' => $code,
                    'ok' => $content !== '',
                    'prompt_tokens' => $data['usage']['prompt_tokens'] ?? null,
                    'completion_tokens' => $data['usage']['completion_tokens'] ?? null,
                    'total_tokens' => $data['usage']['total_tokens'] ?? null,
                ]);
            }
            if ($content !== '') {
                try {
                    $db->prepare(
                        "UPDATE ai_models SET used_today=COALESCE(used_today,0)+1, total_used=COALESCE(total_used,0)+1 WHERE id=?"
                    )->execute([$m['id']]);
                } catch (Throwable $e) {}
                return ['content' => $content, 'model_used' => ($m['name'] ?? $modelId), 'error' => null];
            }
        }

        $data = json_decode((string) $raw, true);
        $errMsg = is_array($data) ? ($data['error']['message'] ?? $data['message'] ?? '') : '';
        if (function_exists('log_ai_api_call')) {
            log_ai_api_call([
                'source' => 'automation_material',
                'model_name' => (string) ($m['name'] ?? ''),
                'model_id' => $modelId,
                'api_url' => $apiUrl,
                'http_code' => $code,
                'ok' => false,
                'prompt_tokens' => is_array($data) ? ($data['usage']['prompt_tokens'] ?? null) : null,
                'completion_tokens' => is_array($data) ? ($data['usage']['completion_tokens'] ?? null) : null,
                'total_tokens' => is_array($data) ? ($data['usage']['total_tokens'] ?? null) : null,
                'error' => $errMsg,
            ]);
        }
        $errors[] = ($m['name'] ?? $modelId) . " HTTP {$code}" . ($errMsg !== '' ? ": {$errMsg}" : '');
    }

    return [
        'content' => '',
        'model_used' => 'none',
        'error' => empty($errors) ? 'no_usable_api_model' : implode('；', $errors),
    ];
}

function geo_ai_db_fallback(string $prompt, int $maxTokens, float $temperature): array {
    $cfg = get_active_ai_config();
    if (empty($cfg['api_key'])) {
        return ['content' => '', 'model_used' => 'none', 'error' => 'no_api_key'];
    }
    $apiUrl = $cfg['api_url'];
    if (!str_ends_with($apiUrl, '/chat/completions') && !str_ends_with($apiUrl, '/completions')) {
        $apiUrl .= '/chat/completions';
    }
    $payload = json_encode([
        'model'       => $cfg['model_id'],
        'messages'    => [['role' => 'user', 'content' => $prompt]],
        'max_tokens'  => $maxTokens,
        'temperature' => $temperature,
    ], JSON_UNESCAPED_UNICODE);
    $ch = curl_init($apiUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 90,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $cfg['api_key'],
        ],
    ]);
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code === 200) {
        $data    = json_decode($raw, true);
        $content = $data['choices'][0]['message']['content'] ?? '';
        return ['content' => $content, 'model_used' => $cfg['model_id'], 'error' => null];
    }
    return ['content' => '', 'model_used' => 'none', 'error' => "HTTP {$code}"];
}

} // end if !function_exists
