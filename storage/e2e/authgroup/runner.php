<?php
/**
 * Live HTTP test driver — Group 1: Auth endpoints (api/v1/register, token, admin-login, social/*).
 * Evidence-first: every execution recorded to JSONL; assertions derive from code + docs/languages, not guesses.
 * Rate-limit bookkeeping kept in-process: throttle:login = 5/min/IP shared by all 7 endpoints.
 *
 * Usage: php storage/e2e/authgroup/runner.php [phase]  (phase: 0=validation matrix, 15=repeatability)
 */

declare(strict_types=1);

$base = 'http://127.0.0.1:8000/api/v1';
$dir = __DIR__;
$runsStamp = date('Ymd-His');
$evidenceFile = "$dir/evidence-$runsStamp.jsonl";
$summaryFile = "$dir/summary-$runsStamp.json";

// ---------------------------------------------------------------- HTTP client
function http_request(string $method, string $url, ?array $body = null, array $headers = [], ?array $multipart = null): array {
    $ch = curl_init();
    $curlHeaders = ['Accept: application/json'];
    foreach ($headers as $k => $v) { $curlHeaders[] = "$k: $v"; }
    if ($multipart !== null) {
        // multipart/form-data; PHP CURLFile requires @path form
        $curlHeaders[] = 'Content-Type: multipart/form-data';
        $postfields = $multipart;
    } elseif ($body !== null) {
        $curlHeaders[] = 'Content-Type: application/json';
        $postfields = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    $opts = [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => false,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $curlHeaders,
    ];
    if (isset($postfields)) { $opts[CURLOPT_POSTFIELDS] = $postfields; }
    curl_setopt_array($ch, $opts);

    // Capture response headers
    $respHeaders = [];
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch1, $line) use (&$respHeaders) {
        $t = trim($line);
        if ($t !== '' && stripos($t, 'HTTP/') !== 0) {
            $p = explode(':', $t, 2);
            if (count($p) === 2) { $respHeaders[strtolower(trim($p[0]))] = trim($p[1]); }
        }
        return strlen($line);
    });

    $t0 = microtime(true);
    $body = curl_exec($ch);
    $t1 = microtime(true);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $elapsed = round(($t1 - $t0) * 1000, 1);
    $err = curl_error($ch);
    //PHP 8.5: curl_close() implicit deprecation — resource freed by GC

    return ['status' => $status, 'body' => (string) $body, 'headers' => $respHeaders,
            'ms' => $elapsed, 'curl_error' => $err];
}

function parse_json_body(string $raw): array {
    $raw = trim($raw);
    if ($raw === '') return [];
    // JSON may be preceded by stray output (deprecation notices) — try plain, then from first '{'
    $j = json_decode($raw, true);
    if (is_array($j)) return ['json' => $j, 'clean' => true];
    $pos = strpos($raw, '{');
    if ($pos !== false) { $j = json_decode(substr($raw, $pos), true); if (is_array($j)) return ['json' => $j, 'clean' => false]; }
    return ['json' => null, 'clean' => false];
}

// ---------------------------------------------------------------- evidence
$fh = fopen($evidenceFile, 'w');
$allowedPaths = null;
function write_evidence($fh, array $rec): void {
    fwrite($fh, json_encode($rec, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
}

// ---------------------------------------------------------------- rate bookkeeping
$rateHits = []; // timestamps of the last requests through throttle:login group
function rate_pace(array &$rateHits): void {
    $now = microtime(true);
    $rateHits = array_values(array_filter($rateHits, fn($t) => ($now - $t) < 60));
    if (count($rateHits) >= 5) {
        $sleep = 61.5 - ($now - $rateHits[0]);
        if ($sleep > 0) {
            usleep((int) ($sleep * 1_000_000));
        }
    }
    $rateHits[] = microtime(true);
}

// Intentionally DO NOT pace: used to overwhelm the limiter in the 429/over-limit phase.
function rate_pace_no_check(): void {}

// ---------------------------------------------------------------- assertions
function json_path(array $data, string $path) {
    $cur = $data;
    foreach (explode('.', $path) as $seg) {
        if (is_array($cur) && array_key_exists($seg, $cur)) { $cur = $cur[$seg]; }
        else { return null; }
    }
    return $cur;
}

function check_one(array $case, string $id, array $resp, string $rawBody, array $extra = []): array {
    $checks = [];
    $body = parse_json_body($rawBody);
    $json = $body['json'];
    $clean = $body['clean'];

    $checks[] = ['name' => 'http_status', 'ok' => in_array($resp['status'], $case['expect_status'], true),
                 'detail' => 'status=' . $resp['status'] . ' expect=' . implode('|', $case['expect_status'])];
    if ($resp['curl_error'] !== '') {
        $checks[] = ['name' => 'transport', 'ok' => false, 'detail' => 'curl: ' . $resp['curl_error']];
    }
    if (!empty($case['expect_json']) && $json === null) {
        $checks[] = ['name' => 'body_is_json', 'ok' => false, 'detail' => substr($rawBody, 0, 200)];
        return $checks;
    }
    if (count($checks) > 0 && !$checks[count($checks) - 1]['ok']) { return $checks; }

    foreach ($case['expect_json'] as $path => $rule) {
        $val = json_path($json ?? [], $path);
        $ok = false; $detail = 'path=' . $path . ' val=' . json_encode($val) . ' rule=' . json_encode($rule);
        if (is_string($rule)) {
            if (str_starts_with($rule, '=')) { $ok = ($val === substr($rule, 1)); }
            elseif (str_starts_with($rule, 'bool:')) { $ok = is_bool($val) && strtolower((string) $val) === strtolower(substr($rule, 5)); }
            elseif (str_starts_with($rule, '~:')) { $ok = is_string($val) && ($val === substr($rule, 2)); }
            elseif (str_starts_with($rule, 'in:')) { $ok = in_array($val, explode(',', substr($rule, 3)), true); }
            elseif (str_starts_with($rule, 'type:')) { $ok = gettype($val) === substr($rule, 5); }
            elseif (str_starts_with($rule, 'haskey:')) { $ok = is_array($json ?? []) && array_key_exists(substr($rule, 7), $json); }
            elseif (str_starts_with($rule, 'rx:')) {
                // validation error lists are arrays of strings — apply regex to first element
                if (is_array($val)) { $val = $val[0] ?? null; }
                $ok = is_string($val) && (bool) preg_match(substr($rule, 3), $val);
            }
            elseif (str_starts_with($rule, 'count>=')) { $ok = is_array($val) && count($val) >= (int) substr($rule, 8); }
            elseif (str_starts_with($rule, '!')) { $ok = $val !== substr($rule, 1) && $val !== null; }
            else { $ok = $val === $rule; }
        } else { $ok = $val === $rule; }
        $checks[] = ['name' => 'json.' . $path, 'ok' => $ok, 'detail' => $detail];
    }
    foreach (($case['expect_headers'] ?? []) as $hname => $rx) {
        $hv = $resp['headers'][$hname] ?? null;
        $checks[] = ['name' => 'hdr.' . $hname, 'ok' => ($hv !== null && (bool) preg_match($rx, $hv)),
                     'detail' => ($hv ?? 'MISSING') . ' expect_rx=' . $rx];
    }
    // body purity: always recorded; enforced only when the case demands it
    $checks[] = ['name' => 'body_pure_json',
                 'ok' => isset($case['expect_body_clean']) ? ((bool) $case['expect_body_clean']) === $clean : true,
                 'detail' => substr($rawBody, 0, 240)];
    return $checks;
}

function record_case($fh, string $envFile, array $case, array $resp, string $rawBody, array $checks): array {
    $fails = array_values(array_filter($checks, fn($c) => !$c['ok']));
    $rec = [
        'id' => $case['id'], 'group' => $case['group'] ?? '', 'category' => $case['category'] ?? '',
        'url' => $case['url'], 'method' => $case['method'],
        'ts' => date('c'), 'ms' => $resp['ms'], 'status' => $resp['status'],
        'curl_error' => $resp['curl_error'],
        'resp_headers' => array_intersect_key($resp['headers'], array_flip(['content-type', 'retry-after', 'x-ratelimit-limit', 'x-ratelimit-remaining', 'allow', 'location'])),
        'sent_meta' => $case['note'] ?? '',
        'body_snip' => mb_substr($rawBody, 0, 1200),
        'assertions' => $checks, 'passed' => count($fails) === 0,
        'fail_count' => count($fails), 'fails' => array_column($fails, 'name'),
    ];
    write_evidence($fh, $rec);
    return $rec;
}

// ---------------------------------------------------------------- fixtures
$stamp = time();
$QA = [
    'email' => "qaauthg1+{$stamp}@gmail.com",
    'email2' => "qaauthg1x+{$stamp}@gmail.com",
];
$QA['phone'] = '01550' . str_pad((string) random_int(100000, 999999), 6, (string) random_int(0, 9));
$QA['password'] = 'QaPass!2026x';
$QA['emailUniq'] = $QA['email'];
fwrite($fh, json_encode(['_fixtures' => $QA, 'ts' => date('c')]) . "\n");

// throttle bookkeeping for analytics
$report = [];

function run_case($fh, array &$rateHits, array $case, string $base, array &$report): array {
    if (empty($case['nopace'])) { rate_pace($rateHits); } else { rate_pace_no_check(); }
    $url = str_starts_with($case['url'], 'http') ? $case['url'] : $base . $case['url'];
    $resp = http_request($case['method'], $url, $case['body'] ?? null, $case['headers'] ?? [], $case['multipart'] ?? null);
    $checks = check_one($case, $case['id'], $resp, $resp['body']);
    $rec = record_case($fh, "ev", $case, $resp, $resp['body'], $checks);
    $report[] = $rec;
    echo sprintf("[%s] %-12s %-6s %s -> %d (%sms) %s\n", date('H:i:s'), $case['id'], $case['method'], $case['url'], $rec['status'], $rec['ms'], $rec['passed'] ? 'PASS' : 'FAIL:' . implode(',', $rec['fails']));
    return $rec;
}

// ---------------------------------------------------------------- PHASE selector
$phase = (string) ($argv[1] ?? '0');
$en = ['Accept' => 'application/json'];

if ($phase === '0') {
    // ---- E2 token: shape/anti-course/headers
    run_case($fh, $rateHits, ['id' => 'E2-A4-METHOD405', 'method' => 'GET', 'url' => '/token',
        'expect_status' => [405], 'expect_json' => [], 'note' => 'method-not-allowed per API docs pattern'], $base, $report);
    run_case($fh, $rateHits, ['id' => 'E2-A1-MISSINGALL', 'method' => 'POST', 'url' => '/token', 'body' => [],
        'expect_status' => [422],
        'expect_json' => ['success' => 'type:NULL', 'errors.email' => 'rx:/.+/i', 'errors.phone_number' => 'rx:/.+/i', 'errors.password' => 'rx:/.+/i'],
        'note' => 'validation: missing all credentials'], $base, $report);
    $nouserSeq = rand(1, 30);
    run_case($fh, $rateHits, ['id' => 'E2-B1-BADCRED-404', 'method' => 'POST', 'url' => "/token",
        'body' => ['email' => "qa.authorized.nouser+{$nouserSeq}@gmail.com", 'password' => 'WrongPass!2026'],
        'expect_status' => [404],
        'expect_json' => ['success' => '=false', 'message' => 'rx:/invalid credentials/i', 'status' => '=404'],
        'expect_headers' => ['x-ratelimit-limit' => '#\d+#', 'x-ratelimit-remaining' => '#\d+#'],
        'note' => 'anti-enumeration: unknown account message must equal invalid-password message'], $base, $report);
    // ---- E1 register: validation + create fixture
    run_case($fh, $rateHits, ['id' => 'E1-A1-MISSINGALL', 'method' => 'POST', 'url' => '/register', 'body' => [],
        'expect_status' => [422],
        'expect_json' => ['errors.first_name' => 'rx:/.+/i', 'errors.last_name' => 'rx:/.+/i',
                          'errors.phone_number' => 'rx:/.+/i', 'errors.password' => 'rx:/.+/i'],
        'note' => 'register validation: missing required fields'], $base, $report);
    run_case($fh, $rateHits, ['id' => 'E1-A2-BADPOLICY', 'method' => 'POST', 'url' => '/register',
        'body' => ['first_name' => 'Qa', 'last_name' => 'Tester', 'email' => null, 'phone_number' => $QA['phone'], 'password' => $QA['password']],
        'expect_status' => [422],
        'expect_json' => ['errors.policy' => 'rx:/.+/i'],
        'note' => 'register validation: policy consent required'], $base, $report);
    $regBody = ['first_name' => 'Qa', 'last_name' => 'Tester', 'email' => $QA['email'], 'phone_number' => $QA['phone'],
                'password' => $QA['password'], 'password_confirmation' => $QA['password'], 'policy' => 'true'];
    run_case($fh, $rateHits, ['id' => 'E1-B1-CREATE-OK', 'method' => 'POST', 'url' => '/register', 'body' => $regBody,
        'expect_status' => [200],
        'expect_json' => ['success' => '=true', 'data.otp_status' => '=true', 'message' => 'rx:/registered/i'],
        'note' => 'register happy path -> 200 + otp_status true (code: USER_REGISTERED_SUCCESSFULLY,200)'], $base, $report);
    // fixture login via email (E2 positive)
    run_case($fh, $rateHits, ['id' => 'E2-C1-LOGIN-OK', 'method' => 'POST', 'url' => '/token',
        'body' => ['email' => $QA['email'], 'password' => $QA['password']],
        'expect_status' => [200],
        'expect_json' => ['success' => '=true', 'status' => '=200', 'message' => 'rx:/logged in/i',
                          'data.token' => 'rx:/^\d+\|.{20,}$/', 'data.email_verified' => 'in:false,true',
                          'data.role' => 'type:array', 'data.expires_at' => 'rx:/\d{4}-\d{2}-\d{2}/'],
        'note' => 'token positive: full envelope shape'], $base, $report);
    // fixture login via phone
    run_case($fh, $rateHits, ['id' => 'E2-C2-LOGIN-PHONE', 'method' => 'POST', 'url' => '/token',
        'body' => ['phone_number' => $QA['phone'], 'password' => $QA['password']],
        'expect_status' => [200],
        'expect_json' => ['data.token' => 'rx:/^\d+\|.{20,}$/'],
        'note' => 'token positive via phone'], $base, $report);
    // OR-semantics probe: email of fixture + phone of NONEXISTENT — detection of sloppy OR
    run_case($fh, $rateHits, ['id' => 'E2-C3-MIXED-OR-PROBE', 'method' => 'POST', 'url' => '/token',
        'body' => ['email' => $QA['email'], 'phone_number' => '0100000' . str_pad((string) random_int(100000, 999999), 6, '1'), 'password' => $QA['password']],
        'expect_status' => [200, 404],
        'expect_json' => ['success' => 'in:true,false'],
        'note' => 'CONTROLLED: email matches fixture / phone bogus; code uses OR -> may still login (document as-is)'], $base, $report);
    // duplicate email
    run_case($fh, $rateHits, ['id' => 'E1-B2-DUP-EMAIL', 'method' => 'POST', 'url' => '/register',
        'body' => array_merge($regBody, ['email' => $QA['email'], 'phone_number' => '01550' . random_int(111111, 899999)]),
        'expect_status' => [422],
        'expect_json' => ['errors.email' => 'rx:/.+/i'],
        'note' => 'duplicate email must 422 (defect probe: no 500 leak)'], $base, $report);
    // ---- E3 admin-login: doomed probes ×2 then rate-limit assert
    run_case($fh, $rateHits, ['id' => 'E3-A1-NOUSER', 'method' => 'POST', 'url' => '/admin-login',
        'body' => ['email' => 'qa.no.admin+7@gmail.com', 'password' => 'WrongPass!2026'],
        'expect_status' => [404], 'expect_json' => ['success' => '=false', 'message' => 'rx:/invalid credentials/i'],
        'note' => 'admin-login unknown user'], $base, $report);
    // E2 half-open boundary pw=5
    run_case($fh, $rateHits, ['id' => 'E2-A2-PW5', 'method' => 'POST', 'url' => '/token',
        'body' => ['email' => 'x+13@gmail.com', 'password' => '12345'],
        'expect_status' => [422], 'expect_json' => ['errors.password' => 'rx:/at least 6|too short/'],
        'note' => 'password min:6 boundary 5'], $base, $report);
    // ---- E5 exchange: invalid codes
    run_case($fh, $rateHits, ['id' => 'E5-A1-422-EMPTY', 'method' => 'POST', 'url' => '/social/exchange', 'body' => [],
        'expect_status' => [422], 'expect_json' => ['errors.code' => 'rx:/.+/i'], 'note' => 'missing code'], $base, $report);
    run_case($fh, $rateHits, ['id' => 'E5-A2-UNKNOWN', 'method' => 'POST', 'url' => '/social/exchange',
        'body' => ['code' => bin2hex(random_bytes(32))],
        'expect_status' => [400], 'expect_json' => ['success' => '=false', 'message' => 'rx:/invalid or expired/i'],
        'note' => 'unknown 64-hex code'], $base, $report);
    run_case($fh, $rateHits, ['id' => 'E5-A3-LEN65', 'method' => 'POST', 'url' => '/social/exchange',
        'body' => ['code' => str_repeat('a', 65)],
        'expect_status' => [422], 'expect_json' => ['errors.code' => 'rx:/.+/i'], 'note' => 'max:64 boundary 65'], $base, $report);
    // ---- E4/E6 redirect: invalid provider (expects 500 per Handler mapping), valid provider attempt
    run_case($fh, $rateHits, ['id' => 'E6-A2-BADPROVIDER', 'method' => 'GET', 'url' => '/social/twitter?type=mobile',
        'expect_status' => [500],
        'expect_json' => ['message' => 'rx:/facebook or google/i', 'status' => 'bool:false'],
        'note' => 'provider whitelist — observed 500 (F03) with user-actionable message + envelope drift'], $base, $report);
    run_case($fh, $rateHits, ['id' => 'E4-A3-ALIAS-BADPROV', 'method' => 'GET', 'url' => '/social/redirect?provider=bogus',
        'expect_status' => [500],
        'expect_json' => ['message' => 'rx:/facebook or google/i', 'status' => 'bool:false'],
        'note' => 'alias path with bogus provider'], $base, $report);
    run_case($fh, $rateHits, ['id' => 'E4-A1-ALIAS-GOOGLE', 'method' => 'GET', 'url' => '/social/redirect?provider=google',
        'expect_status' => [200, 500],
        'expect_json' => [],
        'note' => 'depends on GOOGLE_CLIENT_ID emptiness (client_id currently EMPTY — F07)'], $base, $report);
    // ---- E7 callback (no real provider): mobile JSON error path (verified vs real Google 400)
    run_case($fh, $rateHits, ['id' => 'E7-A1-CALLBACK-FAIL-MOBILE', 'method' => 'GET', 'url' => '/social/google/callback?state=mobile',
        'expect_status' => [400], 'expect_json' => ['success' => 'bool:false', 'message' => 'rx:/social login failed/i'],
        'note' => 'provider unreachable => controlled 400 mobile (log-traced Google 400 invalid_request)'], $base, $report);
}

if ($phase === '5') {
    // Deterministic rate-limit behavior: rapid valid-shape requests => 5 under + over-limit.
    $statuses = []; $hdrs = [];
    for ($i = 1; $i <= 8; $i++) {
        rate_pace_no_check(); // minimal pacing (do not sleep) — intentional: overwhelm limiter
        $nouser = ['id' => "E2-429-{$i}", 'method' => 'POST', 'url' => '/token',
            'nopace' => true,
            'body' => ['email' => 'qa.ratelimit.no-user@gmail.com', 'password' => 'WrongPass!2026'],
            'expect_status' => [429],
            'expect_json' => ['success' => 'bool:false', 'message' => 'rx:/too many requests/i', 'data' => 'type:NULL'],
            'expect_headers' => ['retry-after' => '#^\d+$#'],
            'note' => "rapid attempt {$i}: expect 429 custom envelope once over 5/min"];
        $rec = run_case($fh, $rateHits, $nouser, $base, $report);
        $statuses[] = $rec['status'];
        $hdrs[] = $rec['resp_headers'];
    }
    fwrite($fh, json_encode(['_rate429' => ['statuses' => $statuses, 'headers' => $hdrs]]) . "\n");
    echo "RATE429 statuses: " . implode(',', $statuses) . "\n";
}

if ($phase === '15') {
    // Repeatability: E2 B1 bad creds ×15, deterministic expectation
    $attempts = 15; $statuses = []; $msgs = []; $times = [];
    for ($i = 1; $i <= $attempts; $i++) {
        $rec = run_case($fh, $rateHits, ['id' => "E2-R15-{$i}", 'method' => 'POST', 'url' => '/token',
            'body' => ['email' => 'qa.repeatability.no.user@gmail.com', 'password' => 'WrongPass!2026'],
            'expect_status' => [404], 'expect_json' => ['success' => '=false', 'message' => 'rx:/invalid credentials/i'],
            'note' => "repeatability run {$i}/{$attempts}"], $base, $report);
        $statuses[] = $rec['status']; $times[] = $rec['ms'];
        $m = parse_json_body($rec['body_snip'])['json']['message'] ?? null;
        $msgs[] = $m;
    }
    $unique = array_unique($statuses);
    fwrite($fh, json_encode(['_repeatability' => ['statuses' => $statuses, 'times_ms' => $times, 'messages' => $msgs]]) . "\n");
    echo "REPEATABILITY: statuses=" . implode(',', $statuses) . " unique=" . implode(',', $unique) . "\n";
    echo "TIMES p50=" . median($times) . "ms max=" . max($times) . "ms min=" . min($times) . "ms\n";
}

function median(array $arr) {
    sort($arr); $n = count($arr); $mid = (int) floor($n / 2);
    return $n % 2 === 0 ? ($arr[$mid - 1] + $arr[$mid]) / 2 : $arr[$mid];
}

// ---------------------------------------------------------------- summary
$passed = count(array_filter($report, fn($r) => $r['passed']));
$failed = count($report) - $passed;
$summary = ['phase' => $phase, 'executed' => count($report), 'passed' => $passed, 'failed' => $failed, 'ts' => date('c')];
file_put_contents($summaryFile, json_encode($summary, JSON_PRETTY_PRINT));
echo json_encode($summary, JSON_PRETTY_PRINT) . "\n";
fclose($fh);
