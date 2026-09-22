<?php

// P2 probe: attempts ONE coupon claim on its own MySQL connection.
// Usage: php claim_probe.php <couponId> <userId>
// Prints one JSON line: {"ok":true,"claim_id":N} or {"ok":false,"reason":"..."}.
// DB is taken from the environment (DB_HOST/DB_PORT/DB_DATABASE/DB_USERNAME/DB_PASSWORD)
// so the same script works against any MySQL-compatible test database.

[$script, $couponId, $userId] = $argv + [null, null, null];

// Force the test-database connection BEFORE Laravel boots (boot-time
// providers query the DB using env config, so config() overrides later
// would come too late).
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

$repoRoot = dirname(__DIR__, 2);

require $repoRoot . '/vendor/autoload.php';
$app = require $repoRoot . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

Illuminate\Support\Facades\DB::purge('mysql');

try {
    $coupon = Marvel\Database\Models\Coupon::findOrFail((int) $couponId);
    $user = Marvel\Database\Models\User::findOrFail((int) $userId);
    $claim = app(App\Services\Coupon\CouponClaimService::class)->claim($coupon, $user);
    echo json_encode(['ok' => true, 'claim_id' => $claim->id]) . PHP_EOL;
    exit(0);
} catch (\Throwable $e) {
    $reason = $e instanceof App\Exceptions\CouponClaimException ? $e->reason : get_class($e);
    echo json_encode(['ok' => false, 'reason' => $reason, 'message' => $e->getMessage()]) . PHP_EOL;
    exit(1);
}
