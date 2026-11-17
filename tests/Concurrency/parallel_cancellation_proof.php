<?php

use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\OrderProduct;
use Marvel\Database\Models\Product;
use Marvel\Enums\ItemType;

// #82 orchestrator: one live guest cancellation link, TWO simultaneous OS
// processes (separate MySQL connections) racing the cancel endpoint's
// service path with the same valid token.
//
// Expected: exactly one success, exactly one single-use marker, exactly one
// cancellation timestamp, exactly one status-history row, and inventory
// restored exactly once.
//
// Usage: php parallel_cancellation_proof.php
// Exit 0 + "PROOF_OK" on success, exit 1 + "PROOF_FAIL: ..." otherwise.
// Schema is assumed migrated (the PHPUnit wrapper runs migrate:fresh first).

foreach ([
    'DB_CONNECTION' => 'mysql',
    'DB_HOST' => getenv('DB_HOST') ?: '127.0.0.1',
    'DB_PORT' => getenv('DB_PORT') ?: '3306',
    'DB_DATABASE' => getenv('DB_DATABASE') ?: 'meem_test',
    'DB_USERNAME' => getenv('DB_USERNAME') !== false ? getenv('DB_USERNAME') : 'root',
    'DB_PASSWORD' => getenv('DB_PASSWORD') !== false ? getenv('DB_PASSWORD') : '',
    'CACHE_DRIVER' => 'array',
    'APP_ENV' => 'local',
] as $key => $value) {
    putenv("{$key}={$value}");
    $_ENV[$key] = $_SERVER[$key] = (string) $value;
}

$repoRoot = dirname(__DIR__, 2);

require $repoRoot . '/tests/bootstrap.php';
$app = require $repoRoot . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

Illuminate\Support\Facades\DB::purge('mysql');

// Flows are seeded DATA, not schema: a freshly migrated test database has
// none and Order::create is fail-closed without an active flow. The seeder is
// idempotent and never overwrites customization.
try {
    app(\App\Services\OrderFlow\OrderFlowService::class)->resolveFlowForShippingType('local');
} catch (\Throwable $e) {
    (new \Database\Seeders\OrderFlowSeeder())->run();
}

$fail = function (string $msg): void {
    echo 'PROOF_FAIL: ' . $msg . PHP_EOL;
    exit(1);
};

$quantity = 2;
$stockBefore = 10;
$soldBefore = $quantity;

$product = null;
$order = null;
$token = null;

try {
    $product = Product::create([
        'name' => 'Parallel Cancel Product',
        'slug' => 'parallel-cancel-' . strtolower(bin2hex(random_bytes(4))),
        'price' => 50.00,
        'product_type' => \Marvel\Enums\ProductType::SIMPLE,
        'item_type' => ItemType::PHYSICAL,
        'status' => true,
        'in_stock' => true,
        'stock_quantity' => $stockBefore,
        'reserved_quantity' => 0,
        'sold_quantity' => $soldBefore,
        'is_fast_shipping_available' => false,
        'has_discount' => false,
        'has_flash_sale' => false,
    ]);

    $token = app(App\Services\Guest\GuestOrderTokenService::class)->generateToken();

    $order = Order::create([
        'name' => 'Parallel Cancel Guest',
        'user_phone' => '+201000000009',
        'user_email' => 'parallel.cancel@example.test',
        'user_id' => null,
        'guest_id' => null,
        'address' => '{}',
        'shipping_type' => 'local',
        'price' => 100.00,
        'total_price' => 100.00,
        'status' => Order::ORDER_STATUS_PENDING,
        'payment_status' => Order::PAYMENT_STATUS_PENDING,
        'inventory_state' => Order::INVENTORY_STATE_COMMITTED,
    ]);

    // The credential columns are intentionally NOT mass-assignable (they are
    // never serialized either), so the fixture writes them explicitly.
    $hash = app(App\Services\Guest\GuestOrderTokenService::class)->hash($token);
    $order->forceFill([
        'guest_cancellation_token_hash' => $hash,
        'guest_cancellation_token_used_at' => null,
        'guest_cancellation_token_expires_at' => now()->addHour(),
    ])->save();

    if ($order->fresh()->guest_cancellation_token_hash !== $hash) {
        $fail('fixture did not persist the cancellation token hash');
    }

    OrderProduct::create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'product_name' => $product->name,
        'product_quantity' => $quantity,
        'product_price' => 50.00,
        'product_total_price' => 100.00,
        'item_type' => ItemType::PHYSICAL,
    ]);
} catch (\Throwable $e) {
    $fail('fixture setup: ' . $e->getMessage());
}

$code = (string) (config('auth.master_otp') ?: config('auth.local_otp_code') ?: '123456');
$probe = __DIR__ . '/guest_cancellation_probe.php';
$descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

// Fire both probes as simultaneously as possible.
$procs = [];
foreach ([1, 2] as $_) {
    $p = proc_open([PHP_BINARY, $probe, (string) $order->id, $token, $code], $descriptors, $pipes);
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
$losers = 0;
foreach ($results as $line) {
    $row = json_decode($line, true);
    if (is_array($row) && ($row['ok'] ?? false) === true) {
        $successes++;
    } else {
        $losers++;
        echo 'probe: ' . $line . PHP_EOL;
    }
}

// Observe the committed state from THIS process (the probes' transactions are done).
$fresh = Order::withoutGlobalScopes()->find($order->id);
$freshProduct = Product::find($product->id);
$cancelledHistory = DB::table('order_status_history')
    ->where('order_id', $order->id)
    ->where('new_status', Order::ORDER_STATUS_CANCELLED)
    ->count();

$observed = [
    'successes' => $successes,
    'losers' => $losers,
    'status' => $fresh?->status,
    'used_at' => $fresh?->guest_cancellation_token_used_at?->toIso8601String(),
    'cancelled_at' => $fresh?->cancelled_at?->toIso8601String(),
    'inventory_state' => $fresh?->inventory_state,
    'restored_at' => $fresh?->inventory_state_restored_at?->toIso8601String(),
    'stock' => $freshProduct ? (int) $freshProduct->stock_quantity : null,
    'sold' => $freshProduct ? (int) $freshProduct->sold_quantity : null,
    'cancelled_history_rows' => $cancelledHistory,
];

echo json_encode($observed) . PHP_EOL;

// Cleanup (best effort; history is model-immutable so use the query builder).
try {
    DB::table('order_status_history')->where('order_id', $order->id)->delete();
    OrderProduct::where('order_id', $order->id)->delete();
    Order::withoutGlobalScopes()->whereKey($order->id)->delete();
    Product::whereKey($product->id)->delete();
} catch (\Throwable $e) {
    echo 'cleanup warning: ' . $e->getMessage() . PHP_EOL;
}

if ($successes !== 1) {
    $fail("expected exactly 1 success, got {$successes}");
}
if ($losers !== 1) {
    $fail("expected exactly 1 loser, got {$losers}");
}
if ($fresh?->status !== Order::ORDER_STATUS_CANCELLED) {
    $fail('order is not cancelled');
}
if ($fresh?->guest_cancellation_token_used_at === null) {
    $fail('single-use marker was not persisted exactly once');
}
if ($fresh?->cancelled_at === null) {
    $fail('cancelled_at was not persisted');
}
if ($fresh?->inventory_state !== Order::INVENTORY_STATE_RESTORED || $fresh?->inventory_state_restored_at === null) {
    $fail('inventory was not restored exactly once');
}
if ($cancelledHistory !== 1) {
    $fail("expected exactly 1 cancellation history row, got {$cancelledHistory}");
}
if ($freshProduct && (int) $freshProduct->stock_quantity !== $stockBefore + $quantity) {
    $fail('stock was not incremented by the ordered quantity exactly once: got ' . $freshProduct->stock_quantity);
}
if ($freshProduct && (int) $freshProduct->sold_quantity !== max(0, $soldBefore - $quantity)) {
    $fail('sold_quantity was not decremented exactly once: got ' . $freshProduct->sold_quantity);
}

echo 'PROOF_OK' . PHP_EOL;
exit(0);
