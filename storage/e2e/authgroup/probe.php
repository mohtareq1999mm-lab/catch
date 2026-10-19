<?php
// Server liveness probe (no rate-limited endpoint): plain GET /api/v1/me
$ch = curl_init('http://127.0.0.1:8000/api/v1/me');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 10,
    CURLOPT_HTTPHEADER => ['Accept: application/json'],
]);
$resp = curl_exec($ch);
$code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
$err = curl_error($ch);
echo json_encode(['probe_code' => $code, 'curl_err' => $err, 'body' => mb_substr((string) $resp, 0, 300)]), PHP_EOL;
