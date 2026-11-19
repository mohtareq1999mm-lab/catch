<?php

// #82 probe: attempts ONE guest cancellation on its own MySQL connection.
// Usage: php guest_cancellation_probe.php <orderId> <token> <code>
// Prints one JSON line: {"ok":true,...} or {"ok":false,"type":"...","message":"..."}.
// DB/APP config comes from the environment so the same script works against
// any MySQL-compatible test database (the orchestrator passes its own).

[$script, $orderId, $token, $code] = $argv + [null, null, null];

if (!$orderId || !$token) {
    echo json_encode(['ok' => false, 'type' => 'Usage', 'message' => 'orderId and token are required']) . PHP_EOL;
    exit(2);
}

// Force the test connection BEFORE Laravel boots (boot-time providers query
// the DB using env config, so config() overrides later would come too late).
foreach ([
    'DB_CONNECTION' => 'mysql',
    'DB_HOST' => getenv('DB_HOST') ?: '127.0.0.1',
    'DB_PORT' => getenv('DB_PORT') ?: '3306',
    'DB_DATABASE' => getenv('DB_DATABASE') ?: 'meem_test',
    'DB_USERNAME' => getenv('DB_USERNAME') !== false ? getenv('DB_USERNAME') : 'root',
    'DB_PASSWORD' => getenv('DB_PASSWORD') !== false ? getenv('DB_PASSWORD') : '',
    // The OTP state is not shared in this proof: the ADR 0003 static bypass
    // is the possession proof, and each probe's empty array store simply has
    // no failure counters. Never require Redis for a concurrency proof.
    'CACHE_DRIVER' => 'array',
    'APP_ENV' => 'local',
] as $key => $value) {
    putenv("{$key}={$value}");
    $_ENV[$key] = $_SERVER[$key] = (string) $value;
}

$repoRoot = dirname(__DIR__, 2);

// Worktree-rooted bootstrap: composer's shared vendor autoloader resolves
// repo-owned namespaces against the MAIN tree, so use the test bootstrap.
require $repoRoot . '/tests/bootstrap.php';
$app = require $repoRoot . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

Illuminate\Support\Facades\DB::purge('mysql');

try {
    $order = app(App\Services\Guest\GuestCancellationService::class)->cancel((string) $token, (string) $code);

    echo json_encode([
        'ok' => true,
        'order_id' => $order->id,
        'status' => $order->status,
    ]) . PHP_EOL;
    exit(0);
} catch (\Throwable $e) {
    echo json_encode([
        'ok' => false,
        'type' => get_class($e),
        'message' => $e->getMessage(),
    ]) . PHP_EOL;
    exit(1);
}
