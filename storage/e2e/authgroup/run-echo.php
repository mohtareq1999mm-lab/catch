<?php
// Drive the capture server with the SAME header/body construction as runner.php — ground truth for the wire.
declare(strict_types=1);

function http_request(string $method, string $url, ?array $body = null, array $headers = []): array {
    $ch = curl_init();
    $curlHeaders = ['Accept: application/json'];
    foreach ($headers as $k => $v) { $curlHeaders[] = "$k: $v"; }
    $opts = [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $curlHeaders,
    ];
    if ($body !== null) {
        $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $curlHeaders[] = 'Content-Type: application/json';
    }
    // NOTE: headers array for body is built BEFORE being attached — inserting Content-Type AFTER $opts build mirrors runner.php ordering bug potential
    $opts[CURLOPT_HTTPHEADER] = $curlHeaders;
    curl_setopt_array($ch, $opts);
    $t0 = microtime(true);
    $result = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $ms = round((microtime(true) - $t0) * 1000, 1);
    $err = curl_error($ch);
    // PHP 8.5: curl_close deprecated-noop — resource freed by GC

    return ['status' => $status, 'body' => (string) $result, 'curl_error' => $err, 'ms' => $ms];
}

echo json_encode(http_request('POST', 'http://127.0.0.1:8099/echo',
    ['email' => 'qa.wireprobe.1@gmail.com', 'password' => 'QaPass!2026x']), JSON_UNESCAPED_SLASHES), PHP_EOL;
echo json_encode(http_request('POST', 'http://127.0.0.1:8099/echo2',
    ['first_name' => 'Qa', 'last_name' => 'Tester']), JSON_UNESCAPED_SLASHES), PHP_EOL;
