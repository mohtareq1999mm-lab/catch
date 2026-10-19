<?php
// Group-1 evidence adjudicator: prints compact evidence for selected failing/500 cases.
$dir = __DIR__;
$selectors = [
    'E2-B1-BADCRED-404', 'E1-B1-CREATE-OK', 'E2-C1-LOGIN-OK', 'E2-C3-MIXED-OR-PROBE',
    'E3-A1-NOUSER', 'E5-A2-UNKNOWN', 'E6-A2-BADPROVIDER', 'E4-A3-ALIAS-BADPROV',
    'E7-A1-CALLBACK-FAIL-MOBILE', 'E2-R15-2', 'E2-R15-3', 'E2-429-6', 'E2-429-8',
];
$out = [];
foreach (glob($dir . '/evidence-*.jsonl') as $file) {
    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $j = json_decode($line, true);
        if (!is_array($j) || empty($j['id'])) {
            continue;
        }
        $id = $j['id'];
        $base = preg_replace('/-(\d+)$/', '', $id); // strip repeat suffix
        $hit = in_array($id, $selectors, true) || in_array($base, $selectors, true);
        if (!$hit) {
            continue;
        }
        $body = $j['body'] ?? null;
        $bodyDecoded = null;
        if (is_string($body)) {
            $dec = json_decode($body, true);
            $bodyDecoded = $dec !== null ? $dec : null;
        } elseif (is_array($body)) {
            $bodyDecoded = $body;
        }
        $keys = is_array($bodyDecoded) ? array_keys($bodyDecoded) : [];
        $headers = [];
        $hRaw = $j['headers'] ?? ($j['response_headers'] ?? []);
        if (is_array($hRaw)) {
            foreach ($hRaw as $k => $v) {
                $lk = strtolower((string) $k);
                if (str_starts_with($lk, 'x-ratelimit') || str_starts_with($lk, 'retry-after')) {
                    $headers[$lk] = $v;
                }
            }
        }
        $out[] = [
            'file' => basename($file),
            'id' => $id,
            'http' => $j['status'] ?? ($j['http_status'] ?? null),
            'reason' => $j['reason'] ?? ($j['fail'] ?? null),
            'keys' => $keys,
            'body' => is_array($bodyDecoded) ? $bodyDecoded : substr((string) $body, 0, 300),
            'ratelimit_headers' => $headers,
        ];
    }
}
echo json_encode(['count' => count($out), 'cases' => $out], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
