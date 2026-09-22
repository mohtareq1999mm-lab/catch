<?php

// P2 orchestrator: max_claims=1 coupon + 10 users, then 10 simultaneous
// OS processes (separate MySQL connections) race to claim.
// Expected: exactly 1 success, 9 rejections, exactly 1 claim row.
// Usage: php parallel_claim_proof.php
// Exit 0 + "PROOF_OK" on success, exit 1 + "PROOF_FAIL: ..." otherwise.
// Schema is assumed migrated (phpunit wrapper runs after RefreshDatabase).

$repoRoot = dirname(__DIR__, 2);

// Force the test-database connection BEFORE Laravel boots (see claim_probe.php).
foreach ([
    'DB_CONNECTION' => 'mysql',
    'DB_HOST' => getenv('DB_HOST') ?: '127.0.0.1',
    'DB_PORT' => getenv('DB_PORT') ?: '3307',
    'DB_DATABASE' => getenv('DB_DATABASE') ?: 'meem_test',
    'DB_USERNAME' => getenv('DB_USERNAME') ?: 'root',
    'DB_PASSWORD' => getenv('DB_PASSWORD') !== false ? getenv('DB_PASSWORD') : '',
] as $key => $value) {
    putenv("{$key}={$value}");
    $_ENV[$key] = $_SERVER[$key] = (string) $value;
}

require $repoRoot . '/vendor/autoload.php';
$app = require $repoRoot . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

Illuminate\Support\Facades\DB::purge('mysql');

$fail = function (string $msg) {
    echo 'PROOF_FAIL: ' . $msg . PHP_EOL;
    exit(1);
};

$tag = 'PPROOF' . strtoupper(bin2hex(random_bytes(3)));

try {
    $coupon = Marvel\Database\Models\Coupon::create([
        'name' => 'Parallel Proof',
        'slug' => 'parallel-proof-' . strtolower($tag),
        'discount_type' => 'percentage',
        'discount' => 10,
        'status' => true,
        'start_date' => date('Y-m-d', strtotime('-1 day')),
        'end_date' => date('Y-m-d', strtotime('+1 month')),
    ]);
    $coupon->update(['code' => $tag]);
    $coupon->refresh();

    Marvel\Database\Models\CouponTargeting::create([
        'coupon_id' => $coupon->id,
        'mode' => 'dynamic',
        'require_claim' => true,
        'max_claims' => 1,
        'claim_ttl_hours' => 24,
    ]);

    $userIds = [];
    foreach (range(1, 10) as $i) {
        $u = Marvel\Database\Models\User::forceCreate([
            'name' => "Proof User $tag $i",
            'email' => "proof-$tag-$i@example.com",
            'password' => bcrypt('secret'),
            'type' => 'user',
        ]);
        $userIds[] = $u->id;
    }
} catch (\Throwable $e) {
    $fail('fixture setup: ' . $e->getMessage());
}

// Fire all 10 probes as simultaneously as possible.
$probe = $repoRoot . '/tests/Concurrency/claim_probe.php';
$descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$procs = [];
foreach ($userIds as $uid) {
    $p = proc_open([PHP_BINARY, $probe, (string) $coupon->id, (string) $uid], $descriptors, $pipes);
    if (!is_resource($p)) {
        $fail('could not spawn probe process');
    }
    $procs[] = ['proc' => $p, 'pipes' => $pipes];
}

$results = [];
foreach ($procs as $p) {
    $out = stream_get_contents($p['pipes'][1]);
    $err = stream_get_contents($p['pipes'][2]);
    fclose($p['pipes'][1]);
    fclose($p['pipes'][2]);
    proc_close($p['proc']);
    $results[] = trim($out) !== '' ? trim($out) : ('STDERR: ' . trim($err));
}

$successes = 0;
foreach ($results as $line) {
    $row = json_decode($line, true);
    if (is_array($row) && ($row['ok'] ?? false) === true) {
        $successes++;
    } else {
        echo 'probe: ' . $line . PHP_EOL;
    }
}

$rowCount = Marvel\Database\Models\CouponClaim::where('coupon_id', $coupon->id)->count();

// Cleanup (best effort).
Marvel\Database\Models\CouponClaim::where('coupon_id', $coupon->id)->delete();
Marvel\Database\Models\CouponTargeting::where('coupon_id', $coupon->id)->delete();
$coupon->delete();
Marvel\Database\Models\User::whereIn('id', $userIds)->delete();

echo "successes={$successes} rows={$rowCount}" . PHP_EOL;

if ($successes !== 1) {
    $fail("expected exactly 1 success, got {$successes}");
}
if ($rowCount !== 1) {
    $fail("expected exactly 1 claim row, got {$rowCount}");
}

echo 'PROOF_OK' . PHP_EOL;
exit(0);
