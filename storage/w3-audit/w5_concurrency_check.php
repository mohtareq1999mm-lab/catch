<?php

/**
 * W5 CONCURRENCY PROOF — real MySQL, real cross-process contention.
 *
 * Run with scratch-DB env vars (never the dev database):
 *   DB_CONNECTION=mysql DB_DATABASE=meem_w5_audit php w5_concurrency_check.php
 *
 * Spawns independent PHP worker PROCESSES (separate connections) that race
 * DigitalFulfillmentService::fulfillOrder — SQLite cannot prove row-lock
 * semantics, so this must run on MySQL per the W5 mandate.
 */

require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\DigitalLicenseKey;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\OrderProduct;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\User;

$failures = [];
$total = 0;
function check(string $name, bool $ok): void
{
    global $failures, $total;
    $total++;
    echo ($ok ? 'PASS' : 'FAIL') . ": {$name}\n";
    if (!$ok) {
        $failures[] = $name;
    }
}

function makeUser(string $tag): User
{
    return User::create([
        'name' => $tag,
        'email' => $tag . '-' . uniqid() . '@example.com',
        'password' => bcrypt('x'),
        'type' => 'customer',
    ]);
}

function digitalOrder(User $user, Product $product): Order
{
    $order = Order::create(['user_id' => $user->id]);
    OrderProduct::create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'item_type' => 'DIGITAL',
        'product_quantity' => 1,
    ]);

    return $order;
}

echo '== driver=' . DB::getDriverName() . " ==\n";
if (DB::getDriverName() !== 'mysql') {
    exit("MUST run against MySQL.\n");
}

Artisan::call('migrate:fresh', ['--force' => true]);

$svc = app(\App\Services\Digital\DigitalFulfillmentService::class);

/* ---------------- Scenario 1: N workers, SAME order ---------------- */
$user1 = makeUser('w5-race-owner');
$product1 = Product::create([
    'name' => ['en' => 'Race Product'],
    'slug' => 'race-' . uniqid(),
    'price' => 10,
    'item_type' => 'DIGITAL',
]);
$licenseAsset = app(\App\Services\Digital\DigitalAssetService::class)->createLicense($product1, []);
app(\App\Services\Digital\DigitalAssetService::class)->addLicenseKeys($licenseAsset, [
    'RACE-KEY-1', 'RACE-KEY-2', 'RACE-KEY-3', 'RACE-KEY-4',
]);
$order1 = digitalOrder($user1, $product1);

$workers = [];
for ($i = 0; $i < 8; $i++) {
    $cmd = 'php ' . escapeshellarg(__DIR__ . DIRECTORY_SEPARATOR . 'w5_concurrency_worker.php')
        . ' ' . escapeshellarg((string) $order1->id);
    // Force the same scratch connection in children via inherited env.
    $workers[] = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
}
foreach ($workers as $p) {
    proc_close($p);   // blocks until worker exits
}
echo "== scenario 1: 8 concurrent workers raced order {$order1->id} ==\n";

$entitlements = \App\Models\DigitalEntitlement::where('order_id', $order1->id)->get();
check('S1 exactly one entitlement', $entitlements->count() === 1);

$assigned = DigitalLicenseKey::where('asset_id', $licenseAsset->id)
    ->where('status', DigitalLicenseKey::STATUS_ASSIGNED)->get();
check('S1 exactly one key assigned', $assigned->count() === 1);
check('S1 assigned key bound to THE entitlement', (int) $assigned->first()?->allocated_entitlement_id === (int) $entitlements->first()?->id);
check('S1 three keys still available', DigitalLicenseKey::where('asset_id', $licenseAsset->id)
    ->where('status', DigitalLicenseKey::STATUS_AVAILABLE)->count() === 3);
check('S1 entitlement delivered', $entitlements->first()?->status === \App\Models\DigitalEntitlement::STATUS_DELIVERED);

// Sequential idempotency after the storm.
$svc->fulfillOrder($order1->fresh());
$svc->fulfillOrder($order1->fresh());
check('S1 replay allocates nothing new', DigitalLicenseKey::where('asset_id', $licenseAsset->id)
    ->where('status', DigitalLicenseKey::STATUS_ASSIGNED)->count() === 1);

/* -------- Scenario 2: 6 orders race a pool of 3 (double-hit each) -------- */
$product2 = Product::create([
    'name' => ['en' => 'Scarce Product'],
    'slug' => 'scarce-' . uniqid(),
    'price' => 10,
    'item_type' => 'DIGITAL',
]);
$scarce = app(\App\Services\Digital\DigitalAssetService::class)->createLicense($product2, []);
app(\App\Services\Digital\DigitalAssetService::class)->addLicenseKeys($scarce, ['SCARCE-1', 'SCARCE-2', 'SCARCE-3']);

$orders = [];
foreach (range(1, 6) as $n) {
    $orders[] = digitalOrder(makeUser("w5-buyer-$n"), $product2);
}
$workers = [];
foreach ($orders as $o) {
    foreach ([1, 2] as $hit) {   // two racing workers PER order (idempotency under load)
        $cmd = 'php ' . escapeshellarg(__DIR__ . DIRECTORY_SEPARATOR . 'w5_concurrency_worker.php')
            . ' ' . escapeshellarg((string) $o->id);
        $workers[] = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    }
}
foreach ($workers as $p) {
    proc_close($p);
}
echo "== scenario 2: 12 workers raced 6 orders over 3 scarce keys ==\n";

$assignedIds = DigitalLicenseKey::where('asset_id', $scarce->id)
    ->whereIn('status', [DigitalLicenseKey::STATUS_ASSIGNED])->pluck('allocated_entitlement_id');
check('S2 exactly 3 keys assigned (pool respected)', $assignedIds->count() === 3);
check('S2 no duplicate allocation (unique entitlements)', $assignedIds->unique()->count() === 3);
check('S2 zero available remain', DigitalLicenseKey::where('asset_id', $scarce->id)
    ->where('status', DigitalLicenseKey::STATUS_AVAILABLE)->count() === 0);

$perEntitlement = DB::table('digital_license_keys')
    ->select('allocated_entitlement_id', DB::raw('count(*) c'))
    ->where('asset_id', $scarce->id)
    ->groupBy('allocated_entitlement_id')
    ->pluck('c', 'allocated_entitlement_id');
check('S2 no entitlement holds >1 key', $perEntitlement->every(fn ($c) => $c <= 1));
check('S2 all six entitlements exist (fulfillment never blocked)',
    \App\Models\DigitalEntitlement::whereIn('order_id', collect($orders)->pluck('id'))->count() === 6);

echo "\n==== RESULT: " . ($total - count($failures)) . "/{$total} checks passed ====\n";
exit(empty($failures) ? 0 : 1);
