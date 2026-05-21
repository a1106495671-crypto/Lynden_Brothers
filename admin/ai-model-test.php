<?php
define('FEISHU_TREASURE', true);
session_start();
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database_admin.php';
require_admin_login();

header('Content-Type: application/json; charset=utf-8');

$id = intval($_POST['id'] ?? 0);
if (!$id) { echo json_encode(['ok'=>false,'msg'=>'缺少模型 ID']); exit; }

$stmt = $db->prepare("SELECT * FROM ai_models WHERE id=?");
$stmt->execute([$id]);
$m = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$m) { echo json_encode(['ok'=>false,'msg'=>'模型不存在']); exit; }

$apiKey = trim(function_exists('decrypt_ai_api_key') ? decrypt_ai_api_key((string)($m['api_key']??'')) : ($m['api_key']??''));
$modelId = trim($m['model_id'] ?? '');
$apiUrl  = rtrim(trim($m['api_url'] ?? 'https://api.deepseek.com'), '/');
$type    = $m['model_type'] ?? 'chat';

if (!$apiKey) { echo json_encode(['ok'=>false,'msg'=>'API Key 为空']); exit; }
if (!$modelId) { echo json_encode(['ok'=>false,'msg'=>'Model ID 为空']); exit; }

$start = microtime(true);

if ($type === 'embedding') {
    // Embedding test
    if (!str_ends_with($apiUrl, '/embeddings')) {
        $apiUrl .= '/embeddings';
    }
    $payload = json_encode(['model'=>$modelId,'input'=>'test connection','encoding_format'=>'float'], JSON_UNESCAPED_UNICODE);
    $ch = curl_init($apiUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>$payload,
        CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>30,
        CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer '.$apiKey],
    ]);
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) { echo json_encode(['ok'=>false,'msg'=>'cURL 错误: '.$err]); exit; }
    $elapsed = round((microtime(true)-$start)*1000).'ms';
    $data = json_decode($raw, true);
    if ($code===200 && !empty($data['data'][0]['embedding'])) {
        $dims = count($data['data'][0]['embedding']);
        echo json_encode(['ok'=>true,'msg'=>"连接成功 · {$dims}维向量 · {$elapsed}"]);
    } else {
        $errMsg = $data['error']['message'] ?? $data['message'] ?? "HTTP {$code}";
        echo json_encode(['ok'=>false,'msg'=>"HTTP {$code}: {$errMsg}"]);
    }
} else {
    // Chat test
    if (!str_ends_with($apiUrl,'/chat/completions')&&!str_ends_with($apiUrl,'/completions')) {
        $apiUrl .= '/chat/completions';
    }
    $payload = json_encode([
        'model'=>$modelId,
        'messages'=>[['role'=>'user','content'=>'回复"OK"两个字即可，不要多说。']],
        'max_tokens'=>10,'temperature'=>0,
    ], JSON_UNESCAPED_UNICODE);
    $ch = curl_init($apiUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>$payload,
        CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>30,
        CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer '.$apiKey],
    ]);
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) { echo json_encode(['ok'=>false,'msg'=>'cURL 错误: '.$err]); exit; }
    $elapsed = round((microtime(true)-$start)*1000).'ms';
    $data = json_decode($raw, true);
    if ($code===200) {
        $reply = trim($data['choices'][0]['message']['content'] ?? '');
        echo json_encode(['ok'=>true,'msg'=>"连接成功 · 回复: \"{$reply}\" · {$elapsed}"]);
    } else {
        $errMsg = $data['error']['message'] ?? $data['message'] ?? "HTTP {$code}";
        echo json_encode(['ok'=>false,'msg'=>"HTTP {$code}: {$errMsg}"]);
    }
}
