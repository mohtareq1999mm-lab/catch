<?php
// Local echo-capture server (stdlib only). Dumps request headers + body to capture-out.jsonl, then replies 200 JSON.
declare(strict_types=1);
$out = __DIR__ . '/capture-out.jsonl';
$payload = [
    'ts' => date('c'),
    'method' => $_SERVER['REQUEST_METHOD'] ?? null,
    'uri' => $_SERVER['REQUEST_URI'] ?? null,
    'http_headers' => [],
];
foreach ($_SERVER as $k => $v) {
    if (str_starts_with($k, 'HTTP_')) { $payload['http_headers'][$k] = $v; }
}
$payload['content_type_raw'] = $_SERVER['CONTENT_TYPE'] ?? null;
$raw = file_get_contents('php://input');
$payload['body_len'] = strlen($raw);
$payload['body_head_400'] = substr($raw, 0, 400);
$payload['decoded_json_ok'] = null;
$payload['_post_fields'] = array_keys($_POST);
file_put_contents($out, json_encode($payload, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
header('Content-Type: application/json');
echo json_encode(['ok' => true, 'echo_len' => $payload['body_len']]);
