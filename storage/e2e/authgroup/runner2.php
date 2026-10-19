<?php
/**
 * Live HTTP test driver — Group 2: Password reset trio (api/v1/forget-password,
 * verify-forget-password-token, reset-password). throttle:sensitive = 5/min/IP.
 * Phases: 0=validation/anti-enumeration matrix, 1=recovery flow (register fixture user,
 * request email OTP, drain queue worker, parse token from mail log, verify token),
 * 2=reset happy path + password proof, 5=per-email lockout + IP throttle burst,
 * 15=repeatability 15x on /forget-password unknown-email.
 *
 * Usage: php storage/e2e/authgroup/runner2.php [phase]
 */

declare(strict_types=1);

$base = 'http://127.0.0.1:8000/api/v1';
$dir = __DIR__;
$runsStamp = date('Ymd-His');
$evidenceFile = "$dir/evidence2-$runsStamp.jsonl";
$summaryFile = "$dir/summary2-$runsStamp.json";
$logFile = __DIR__ . '/../../logs/laravel.log';
$stamp = (string) time();

// ---------------------------------------------------------------- HTTP client
function http_request(string $method, string $url, ?array $body = null, array $headers = []): array {
    $ch = curl_init();
    $curlHeaders = ['Accept: application/json'];
    foreach ($headers as $k => $v) { $curlHeaders[] = "$k: $v"; }
    $postfields = null;
    if ($body !== null) {
        $curlHeaders[] = 'Content-Type: application/json';
        $postfields = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    $opts = [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $curlHeaders,
    ];
    if (isset($postfields)) { $opts[CURLOPT_POSTFIELDS] = $postfields; }
    curl_setopt_array($ch, $opts);
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
    $out = curl_exec($ch);
    $t1 = microtime(true);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $elapsed = round(($t1 - $t0) * 1000, 1);
    $err = curl_error($ch);
    return ['status' => $status, 'body' => (string) $out, 'headers' => $respHeaders,
            'ms' => $elapsed, 'curl_error' => $err];
}

function parse_json_body(string $raw): array {
    $raw = trim($raw);
    if ($raw === '') return [];
    $j = json_decode($raw, true);
    if (is_array($j)) return ['json' => $j, 'clean' => true];
    $pos = strpos($raw, '{');
    if ($pos !== false) { $j = json_decode(substr($raw, $pos), true); if (is_array($j)) return ['json' => $j, 'clean' => false]; }
    return ['json' => null, 'clean' => false];
}

$fh = fopen($evidenceFile, 'w');
function write_evidence($fh, array $rec): void {
    fwrite($fh, json_encode($rec, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
}

// sensitive-group (throttle:sensitive) bookkeeping; login-bucket ops counted separately
$rateHits = [];
function rate_pace(array &$rateHits): void {
    $now = microtime(true);
    $rateHits = array_values(array_filter($rateHits, fn($t) => ($now - $t) < 60));
    if (count($rateHits) >= 4) {  // conservative: sensitive limiter consumes 5/min shared with other bursts
        $sleep = 61.5 - ($now - $rateHits[0]);
        if ($sleep > 0) usleep((int) ($sleep * 1_000_000));
    }
    $rateHits[] = microtime(true);
}
function rate_pace_no_check(): void {}

function json_path(array $data, string $path) {
    $cur = $data;
    foreach (explode('.', $path) as $seg) {
        if (is_array($cur) && array_key_exists($seg, $cur)) { $cur = $cur[$seg]; }
        else { return null; }
    }
    return $cur;
}

function check_one(array $case, array $resp, string $rawBody): array {
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
    $checks[] = ['name' => 'body_pure_json',
                 'ok' => isset($case['expect_body_clean']) ? ((bool) $case['expect_body_clean']) === $clean : true,
                 'detail' => substr($rawBody, 0, 240)];
    return $checks;
}

function record_case($fh, array $case, array $resp, string $rawBody, array $checks): array {
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
$QA = [
    'email' => "qag2+{$stamp}@gmail.com",
    'email2' => "qag2x+{$stamp}@gmail.com",
    'emailFmt' => "not-an-email-{$stamp}",
    'password' => 'QaPass!2026x',
    'password2' => 'QaNewPass!2026y',
    'phone' => '01550' . random_int(100000, 999999),
];
fwrite($fh, json_encode(['_fixtures' => $QA, 'ts' => date('c')]) . "\n");

$report = [];

function run_case($fh, array &$rateHits, array $case, string $base, array &$report): array {
    if (empty($case['nopace'])) { rate_pace($rateHits); } else { rate_pace_no_check(); }
    $url = str_starts_with($case['url'], 'http') ? $case['url'] : $base . $case['url'];
    $resp = http_request($case['method'], $url, $case['body'] ?? null, $case['headers'] ?? []);
    $checks = check_one($case, $resp, $resp['body']);
    $rec = record_case($fh, $case, $resp, $resp['body'], $checks);
    $report[] = $rec;
    echo sprintf("[%s] %-26s %-5s %s -> %d (%sms) %s\n", date('H:i:s'), $case['id'], $case['method'], $case['url'], $rec['status'], $rec['ms'], $rec['passed'] ? 'PASS' : 'FAIL:' . implode(',', $rec['fails']));
    return $rec;
}

$phase = (string) ($argv[1] ?? '0');
$sensi = 'bool:false';

// ================================================================ PHASE 0
if ($phase === '0') {
    $generic = [
        'success' => 'bool:true',
        'message' => 'rx:/inbox|password reset|كلمة المرور|البريد/u',
    ];
    // --- F1 validation shape: endpoint has NO request-level validation rule (static evidence)
    run_case($fh, $rateHits, ['id' => 'F1-A1-MISSING-ALL', 'method' => 'POST', 'url' => '/forget-password', 'body' => [],
        'expect_status' => [200, 422], 'expect_json' => [],
        'note' => 'missing email — code has no validation; evidence decides 200-generic vs 422'], $base, $report);
    run_case($fh, $rateHits, ['id' => 'F1-A2-BADFORMAT', 'method' => 'POST', 'url' => '/forget-password',
        'body' => ['email' => $QA['emailFmt']],
        'expect_status' => [200, 422], 'expect_json' => [],
        'note' => 'non-email string — no server-side format validation expected (defect probe)'], $base, $report);
    $oracleUnknown = "qa.unknown+{$stamp}@gmail.com";
    run_case($fh, $rateHits, ['id' => 'F1-A3-UNKNOWN-ENUM', 'method' => 'POST', 'url' => '/forget-password',
        'body' => ['email' => $oracleUnknown],
        'expect_status' => [200],
        'expect_json' => $generic,
        'note' => 'unknown valid email — must equal known-user message (anti-enumeration oracle stored for adjudication)'], $base, $report);
    // --- F2 verify token shape
    run_case($fh, $rateHits, ['id' => 'F2-A1-MISSING-ALL', 'method' => 'POST', 'url' => '/verify-forget-password-token', 'body' => [],
        'expect_status' => [422],
        'expect_json' => ['errors.email' => 'rx:/.+/i', 'errors.otp' => 'rx:/.+/i'],
        'note' => 'verify requires email+otp'], $base, $report);
    run_case($fh, $rateHits, ['id' => 'F2-A2-BADFORMAT', 'method' => 'POST', 'url' => '/verify-forget-password-token',
        'body' => ['email' => $QA['emailFmt'], 'otp' => '123'],
        'expect_status' => [422],
        'expect_json' => ['errors.email' => 'rx:/.+/i'],
        'note' => 'verify email format validation'], $base, $report);
    run_case($fh, $rateHits, ['id' => 'F2-A3-UNKNOWN-OTP', 'method' => 'POST', 'url' => '/verify-forget-password-token',
        'body' => ['email' => "qa.nouser+{$stamp}@gmail.com", 'otp' => '000000'],
        'expect_status' => [400],
        'expect_json' => ['status' => '=400', 'success' => 'bool:false', 'message' => 'rx:/invalid token|رمز غير صالح/u'],
        'note' => 'unknown email + wrong OTP -> controlled 400 (records per-email failure)'], $base, $report);
    // --- F3 reset shape
    run_case($fh, $rateHits, ['id' => 'F3-A1-MISSING-ALL', 'method' => 'POST', 'url' => '/reset-password', 'body' => [],
        'expect_status' => [422],
        'expect_json' => ['errors.password' => 'rx:/.+/i', 'errors.email' => 'rx:/.+/i', 'errors.otp' => 'rx:/.+/i'],
        'note' => 'reset requires password+email+otp'], $base, $report);
    run_case($fh, $rateHits, ['id' => 'F3-A2-CONFIRM-MISMATCH', 'method' => 'POST', 'url' => '/reset-password',
        'body' => ['password' => $QA['password'], 'password_confirmation' => 'DIFFERENT9', 'email' => "qa.nouser+{$stamp}@gmail.com", 'otp' => '000000'],
        'expect_status' => [422],
        'expect_json' => ['errors.password' => 'rx:/.+/i'],
        'note' => 'confirmed-policy boundary — password_confirmation differs'], $base, $report);
    run_case($fh, $rateHits, ['id' => 'F3-A3-UNKNOWN-PAIR', 'method' => 'POST', 'url' => '/reset-password',
        'body' => ['password' => $QA['password'], 'password_confirmation' => $QA['password'], 'email' => "qa.nouser2+{$stamp}@gmail.com", 'otp' => '999999'],
        'expect_status' => [400],
        'expect_json' => ['status' => '=400', 'success' => 'bool:false', 'message' => 'rx:/invalid token|رمز غير صالح/u'],
        'note' => 'unknown pair — must NOT 500, controlled error'], $base, $report);
}

// ================================================================ PHASE 1
if ($phase === '1') {
    // 1) persist fixture user
    $regBody = ['first_name' => 'Qa', 'last_name' => 'G2', 'email' => $QA['email'], 'phone_number' => $QA['phone'],
                'password' => $QA['password'], 'password_confirmation' => $QA['password'], 'policy' => 'true'];
    run_case($fh, $rateHits, ['id' => 'P1-REGISTER-FIXTURE', 'method' => 'POST', 'url' => '/register', 'body' => $regBody,
        'expect_status' => [200],
        'expect_json' => ['success' => 'bool:true', 'data.otp_status' => 'bool:true'],
        'note' => 'fixture user for recovery flow'], $base, $report);
    // 2) forget-password on known user (queues SendPasswordResetEmailJob on 'medium')
    run_case($fh, $rateHits, ['id' => 'F1-B1-KNOWN-USER', 'method' => 'POST', 'url' => '/forget-password',
        'body' => ['email' => $QA['email']],
        'expect_status' => [200],
        'expect_json' => ['status' => '=200', 'success' => 'bool:true', 'message' => 'rx:/inbox|password reset|البريد|كلمة المرور/u'],
        'note' => 'known user -> password_resets row + queued mail job'], $base, $report);
    // 3) drain medium queue once (no external provider traffic; MAIL_MAILER=log)
    $artisan = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'artisan';
    $drain = shell_exec("php " . escapeshellarg($artisan) . " queue:work database --queue=medium --once --tries=1 --sleep=1 2>&1") ?? '';
    $tail = is_file($logFile) ? substr(file_get_contents($logFile), -14000) : '';
    $pos = strripos($tail, 'Password Reset Token');
    $token = null; $snip = '';
    if ($pos !== false) {
        $proc = substr($tail, $pos, 1200);
        if (preg_match('/(?<!\d)(\d{6})(?!\d)/', $proc, $m)) { $token = $m[1]; }
        $snip = mb_substr($proc, 0, 420);
    }
    write_evidence($fh, ['_queue_drain' => ['worker_out' => mb_substr($drain, 0, 400), 'token_found' => $token !== null, 'mail_snip' => $snip]]);
    echo "TOKEN_EXTRACT: " . ($token !== null ? 'OK(len6)' : 'NOT-FOUND') . "\n";
    $QA['otp'] = $token;
    file_put_contents("$dir/g2otp.json", json_encode([
        'email' => $QA['email'], 'password' => $QA['password'], 'otp' => $token, 'saved' => date('c'),
    ]));
    // 4) verify correct token (per-email failure counter must be consumed/cleared)
    if ($token !== null) {
        run_case($fh, $rateHits, ['id' => 'F2-B1-TOKEN-IS-VALID', 'method' => 'POST', 'url' => '/verify-forget-password-token',
            'body' => ['email' => $QA['email'], 'otp' => $token],
            'expect_status' => [200],
            'expect_json' => ['status' => '=200', 'success' => 'bool:true', 'message' => 'rx:/token is valid|الرمز صالح/u'],
            'note' => 'real 6-digit token verified -> 200'], $base, $report);
    }
}

// ================================================================ PHASE 2
if ($phase === '2') {
    $otpFile = "$dir/g2otp.json";
    $state = is_file($otpFile) ? json_decode((string) file_get_contents($otpFile), true) : [];
    // phase 2 must be invoked in the same run as phase 1 OR read state file written by phase 1
    if (empty($state['otp'])) { echo "STATE-G2-OTP-MISSING (runner2 phase 2 needs phase 1 in same process)\n"; exit(2); }
    $token = $state['otp'];
    $qaEmail = $state['email'];
    // login BEFORE reset to mint a bearer token that must be revoked by reset
    $oldTok = null;
    $login = run_case($fh, $rateHits, ['id' => 'P2-LOGIN-BEFORE-RESET', 'method' => 'POST', 'url' => '/token',
        'body' => ['email' => $qaEmail, 'password' => $state['password'] ?? 'QaPass!2026x'],
        'expect_status' => [200],
        'expect_json' => ['data.token' => 'rx:/^\d+\|.{20,}$/'],
        'note' => 'mint pre-reset bearer token (login bucket)'], $base, $report);
    $oldTok = parse_json_body($login['body_snip'])['json']['data']['token'] ?? null;
    // reset password
    run_case($fh, $rateHits, ['id' => 'F3-B1-RESET-OK', 'method' => 'POST', 'url' => '/reset-password',
        'body' => ['password' => 'QaNewPass!2026y', 'password_confirmation' => 'QaNewPass!2026y', 'email' => $qaEmail, 'otp' => $token],
        'expect_status' => [200],
        'expect_json' => ['status' => '=200', 'success' => 'bool:true', 'message' => 'rx:/password reset|كلمة المرور/u'],
        'note' => 'real token -> password change + token revoke + row delete (transaction)'], $base, $report);
    // old bearer token must be dead
    if (is_string($oldTok)) {
        run_case($fh, $rateHits, ['id' => 'P2-ME-401-OLD-TOKEN', 'method' => 'GET', 'url' => '/me',
            'headers' => ['Authorization' => "Bearer $oldTok"],
            'expect_status' => [401, 404],
            'expect_json' => [],
            'note' => 'pre-reset bearer must be revoked (401) — 404 tolerated if /me route absent (route evidence pending)'], $base, $report);
    }
    // login with NEW password must succeed
    run_case($fh, $rateHits, ['id' => 'P2-LOGIN-NEW-OK', 'method' => 'POST', 'url' => '/token',
        'body' => ['email' => $qaEmail, 'password' => 'QaNewPass!2026y'],
        'expect_status' => [200],
        'expect_json' => ['data.token' => 'rx:/^\d+\|.{20,}$/'],
        'note' => 'reset actually changed the password'], $base, $report);
}

// ================================================================ PHASE 5
if ($phase === '5') {
    $state = json_decode((string) file_get_contents("$dir/g2otp.json"), true);
    $qaEmail = $state['email'] ?? $QA['email'];
    // A) per-email lockout: 5 bad OTP verify in one window then a 6th after cool-off
    for ($i = 1; $i <= 5; $i++) {
        run_case($fh, $rateHits, ['id' => "F2-LOCK-PREP-{$i}", 'method' => 'POST', 'url' => '/verify-forget-password-token', 'nopace' => false,
            'body' => ['email' => $qaEmail, 'otp' => '00000' . $i],
            'expect_status' => [400],
            'expect_json' => ['status' => '=400', 'success' => 'bool:false'],
            'note' => "lockout pre-charger {$i}/5 (hits success-sensitive IP window 5/5)"], $base, $report);
    }
    fwrite($fh, json_encode(['_lockout' => ['stage' => 'precharged', 'ts' => date('c')]]) . "\n");
    echo "LOCKOUT: precharged 5 failures — cool-off phase must sleep 62s externally\n";
}
if ($phase === '5b') {
    $state = json_decode((string) file_get_contents("$dir/g2otp.json"), true);
    $qaEmail = $state['email'] ?? $QA['email'];
    // 6th bad attempt after cool-off -> per-email lockout 429 (status key SERVER-enforced)
    run_case($fh, $rateHits, ['id' => 'F2-LOCKOUT-429', 'method' => 'POST', 'url' => '/verify-forget-password-token',
        'body' => ['email' => $qaEmail, 'otp' => '777777'],
        'expect_status' => [429],
        'expect_json' => ['status' => '=429', 'success' => 'bool:false', 'message' => 'rx:/too many requests|كثيرة جدًا/u'],
        'note' => '6th failure on same email — per-email lockout (5/15min) vs IP throttle disambiguation via status key'], $base, $report);
    // B) IP burst on reset-password wrong OTP x7 rapid — 5 pass, next 429 custom
    $statuses = [];
    for ($i = 1; $i <= 7; $i++) {
        $over = $i > 5;
        $rec = run_case($fh, $rateHits, ['id' => "F3-IPBURST-{$i}", 'method' => 'POST', 'url' => '/reset-password', 'nopace' => true,
            'body' => ['password' => 'QaPass!2026x', 'password_confirmation' => 'QaPass!2026x',
                       'email' => "qa.burst+{$stamp}@gmail.com", 'otp' => '555555'],
            'expect_status' => $over ? [429] : [400],
            'expect_json' => $over
                ? ['success' => 'bool:false', 'message' => 'rx:/too many requests|كثيرة جدًا/u', 'data' => 'type:NULL', 'status' => 'type:NULL']
                : ['status' => '=400', 'success' => 'bool:false', 'message' => 'rx:/invalid token|رمز غير صالح/u'],
            'expect_headers' => $over ? ['retry-after' => '#^\d+$#'] : [],
            'note' => $over ? "burst {$i}/7 expect IP-limit 429 custom envelope" : "burst {$i}/7 expect 400 invalid token"], $base, $report);
        $statuses[] = $rec['status'];
    }
    fwrite($fh, json_encode(['_ipburst' => ['statuses' => $statuses]]) . "\n");
    echo "IPBURST statuses: " . implode(',', $statuses) . "\n";
}

// ================================================================ PHASE 15
if ($phase === '15') {
    $attempts = 15; $statuses = []; $times = []; $msgs = [];
    for ($i = 1; $i <= $attempts; $i++) {
        $rec = run_case($fh, $rateHits, ['id' => "F1-A3-R15-{$i}", 'method' => 'POST', 'url' => '/forget-password',
            'body' => ['email' => "qa.g2repeat+{$stamp}+{$i}@gmail.com"],
            'expect_status' => [200],
            'expect_json' => ['status' => '=200', 'success' => 'bool:true',
                              'message' => 'rx:/inbox|password reset|البريد|كلمة المرور/u'],
            'note' => "repeatability run {$i}/{$attempts} (unknown user — no side effects)"], $base, $report);
        $statuses[] = $rec['status']; $times[] = $rec['ms'];
        $msgs[] = parse_json_body($rec['body_snip'])['json']['message'] ?? null;
    }
    fwrite($fh, json_encode(['_repeatability' => ['statuses' => $statuses, 'times_ms' => $times, 'messages' => $msgs]]) . "\n");
    echo "REPEATABILITY: statuses=" . implode(',', $statuses) . "\n";
    echo "TIMES max=" . max($times) . "ms min=" . min($times) . "ms\n";
}

function median(array $arr) {
    sort($arr); $n = count($arr); $mid = (int) floor($n / 2);
    return $n % 2 === 0 ? ($arr[$mid - 1] + $arr[$mid]) / 2 : $arr[$mid];
}

$passed = count(array_filter($report, fn($r) => $r['passed']));
$failed = count($report) - $passed;
$summary = ['phase' => $phase, 'executed' => count($report), 'passed' => $passed, 'failed' => $failed, 'ts' => date('c')];
file_put_contents($summaryFile, json_encode($summary, JSON_PRETTY_PRINT));
echo json_encode($summary, JSON_PRETTY_PRINT) . "\n";
fclose($fh);
