<?php
// Group-1 adjudicator v2: correct field names, deprecation-prefix-stripped JSON bodies.
$targets = [
    'evidence-20261018-120620.jsonl',
    'evidence-20261018-120926.jsonl',
    'evidence-20261018-121232.jsonl',
];
$interest = [
    'E2-B1-BADCRED-404', 'E1-B1-CREATE-OK', 'E2-C1-LOGIN-OK', 'E2-C3-MIXED-OR-PROBE',
    'E3-A1-NOUSER', 'E5-A2-UNKNOWN', 'E6-A2-BADPROVIDER', 'E4-A3-ALIAS-BADPROV',
    'E7-A1-CALLBACK-FAIL-MOBILE',
];
function stripPrefix(string $snip) {
    // remove every HTML fragment line of the PDO deprecation storm
    $clean = preg_replace('/<br\s*\/?>\s*|\n<b>Deprecated.*?<\/b>(?: in .*? on line .*?)?<br\s*\/?>\s*|<br\s*\/?>\s*\n/is', '', $snip);
    // also strip any stray leading html noise before the first '{'
    $pos = strpos($clean, '{');
    if ($pos === 0) {
        // find LAST opening brace of a top-level JSON in case stray text appears
        return json_decode($clean, true) ?? $clean;
    }
    if ($pos !== false) {
        // find the LAST '{' that starts a JSON object with closing '}' at the end
        $rpos = strrpos($clean, '}');
        if ($rpos !== false) {
            $candidate = substr($clean, $pos, $rpos - $pos + 1);
            $dec = json_decode($candidate, true);
            return $dec ?? $clean;
        }
    }
    return $clean;
}
function hl(array $h): array {
    $out = [];
    foreach ($h as $k => $v) {
        $lk = strtolower((string) $k);
        if (str_starts_with($lk, 'x-ratelimit') || $lk === 'retry-after' || $lk === 'content-type') {
            $out[$lk] = $v;
        }
    }
    return $out;
}
$report = [];
foreach ($targets as $t) {
    $path = __DIR__ . '/' . $t;
    if (!is_file($path)) {
        continue;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $j = json_decode($line, true);
        if (!is_array($j) || empty($j['id'])) {
            continue;
        }
        $id = $j['id'];
        $base = preg_replace('/-\d+$/', '', $id);
        $is500 = (int) ($j['status'] ?? 0) === 500;
        if (!in_array($base, $interest, true) && !$is500 && !str_contains($base, 'R15') && !str_contains($base, '429')) {
            continue;
        }
        $decoded = stripPrefix((string) ($j['body_snip'] ?? ''));
        if (is_array($decoded)) {
            $decoded = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        $report[] = [
            'f' => substr($t, 9, 6),
            'id' => $id,
            'st' => $j['status'] ?? null,
            'ms' => $j['ms'] ?? null,
            'fails' => $j['fails'] ?? [],
            'hl' => hl($j['resp_headers'] ?? []),
            'body' => $decoded,
        ];
    }
}
echo json_encode(['n' => count($report), 'cases' => $report], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
