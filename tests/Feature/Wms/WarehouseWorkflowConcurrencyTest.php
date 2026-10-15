<?php

namespace Tests\Feature\Wms;

use App\Models\Fulfillment\Fulfillment;
use App\Models\Fulfillment\Warehouse;
use App\Models\Shipment;
use App\Models\WarehouseWorkflowVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\User;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Warehouse Workflow — genuine parallel MySQL verification (Gap A).
 *
 * Separate OS processes, independent Laravel boots + MySQL connections,
 * file-barrier synchronized for overlapping execution on catch_verify.
 * MySQL-only. Sequential execution is NOT claimed as concurrency proof.
 */
class WarehouseWorkflowConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    private string $tmp;

    private string $worker;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->assertSame('mysql', DB::getDriverName(), 'Concurrency proof requires MySQL');

        $this->tmp = 'C:/Users/mohta/AppData/Local/Temp/opencode/ww-conc-'.uniqid();
        mkdir($this->tmp, 0777, true);
        $this->worker = $this->tmp.'/worker.php';
        file_put_contents($this->worker, $this->workerScript());
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmp.'/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->tmp);
        parent::tearDown();
    }

    private function workerScript(): string
    {
        return <<<'PHP'
<?php
// argv[1] = base64(json {barrier, results, worker, op, iterations, payload})
$cfg = json_decode(base64_decode($argv[1]), true);
putenv('DB_DATABASE=catch_verify');
$_ENV['DB_DATABASE'] = 'catch_verify';
$_SERVER['DB_DATABASE'] = 'catch_verify';
require 'D:/work/meem/vendor/autoload.php';
$app = require 'D:/work/meem/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['database.connections.mysql.database' => 'catch_verify']);
Illuminate\Support\Facades\DB::purge('mysql');
app()->setLocale('en'); // deterministic English gate messages for assertions

$deadline = microtime(true) + 60;
while (!file_exists($cfg['barrier'].'/GO')) {
    if (microtime(true) > $deadline) { file_put_contents($cfg['results'].'-w'.$cfg['worker'].'.jsonl', json_encode(['worker' => $cfg['worker'], 'fatal' => 'barrier-timeout']).PHP_EOL, FILE_APPEND); exit(3); }
    usleep(3000);
}
// Per-worker file: concurrent appends to one file tear on Windows.
$out = fopen($cfg['results'].'-w'.$cfg['worker'].'.jsonl', 'a');
for ($i = 0; $i < $cfg['iterations']; $i++) {
    try {
        if ($cfg['op'] === 'publish') {
            $v = app(App\Services\Warehouse\WarehouseWorkflowService::class)->publish([
                'shipping_enabled' => ($i % 2 === 0),
                'notes' => "conc-w{$cfg['worker']}-i{$i}",
            ], $cfg['payload']['actor'] ?? null);
            fwrite($out, json_encode(['worker' => $cfg['worker'], 'iter' => $i, 'ok' => true, 'version_id' => $v->id]).PHP_EOL);
        } elseif ($cfg['op'] === 'release') {
            $oid = $cfg['payload']['order_ids'][$i % count($cfg['payload']['order_ids'])];
            $order = Marvel\Database\Models\Order::findOrFail($oid);
            $f = app(App\Services\Fulfillment\FulfillmentService::class)->releaseForOrder(
                $order, $cfg['payload']['warehouse_id'], "conc-{$cfg['run']}-w{$cfg['worker']}-i{$i}-".uniqid());
            fwrite($out, json_encode(['worker' => $cfg['worker'], 'iter' => $i, 'ok' => true,
                'fulfillment_id' => $f->id, 'pinned' => $f->warehouse_workflow_version_id]).PHP_EOL);
        } elseif ($cfg['op'] === 'ship') {
            $fid = $cfg['payload']['fulfillment_ids'][$i % count($cfg['payload']['fulfillment_ids'])];
            $f = App\Models\Fulfillment\Fulfillment::findOrFail($fid);
            $s = app(App\Services\Shipment\ShipmentService::class)->createForFulfillment(
                $f, [], $cfg['payload']['key_prefix']."-w{$cfg['worker']}-i{$i}");
            fwrite($out, json_encode(['worker' => $cfg['worker'], 'iter' => $i, 'ok' => true, 'shipment_id' => $s->id]).PHP_EOL);
        }
    } catch (Throwable $e) {
        fwrite($out, json_encode(['worker' => $cfg['worker'], 'iter' => $i, 'ok' => false,
            'error' => substr(get_class($e).': '.$e->getMessage(), 0, 300)]).PHP_EOL);
    }
}
fclose($out);
exit(0);
PHP;
    }

    /**
     * Pre-existing project behavior: the test connection sits at TRXLEVEL 1
     * after RefreshDatabase (verified via debug probe). In-process tests share
     * the connection so they never notice; cross-process workers cannot read
     * uncommitted fixtures. Drain ambient levels (commit) before spawning
     * workers so fixtures are genuinely committed and visible. Test-harness
     * synchronization only — no product code involved.
     */
    private function commitAmbientTransactions(): void
    {
        $drained = 0;
        while (DB::transactionLevel() > 0 && $drained < 10) {
            DB::commit();
            $drained++;
        }
        $this->assertSame(0, DB::transactionLevel(), 'Fixtures committed for cross-process visibility');
    }

    /**
     * @param array<int,array{op:string,iterations:int,payload:array}> $groups one entry per worker
     * @return array<int,array> result lines
     */
    private function runParallel(array $groups): array
    {
        $this->commitAmbientTransactions();
        $run = uniqid();
        $resultsBase = $this->tmp.'/results';
        $procs = [];
        foreach ($groups as $w => $g) {
            $cfg = base64_encode(json_encode([
                'barrier' => $this->tmp, 'results' => $resultsBase, 'worker' => $w,
                'run' => $run, 'op' => $g['op'], 'iterations' => $g['iterations'],
                'payload' => $g['payload'] ?? [],
            ]));
            // NOTE: $_ENV is empty under this PHP CLI (variables_order); use
            // getenv() so children inherit Winsock/system environment.
            $env = array_merge(getenv(), [
                'DB_DATABASE' => 'catch_verify', 'APP_ENV' => 'testing',
                'CACHE_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
            ]);
            $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $proc = proc_open([PHP_BINARY, $this->worker, $cfg], $descriptors, $pipes, 'D:/work/meem', $env);
            $this->assertIsResource($proc, "worker {$w} failed to spawn");
            fclose($pipes[0]);
            $procs[$w] = [$proc, $pipes[1], $pipes[2]];
        }
        sleep(4); // let every worker boot Laravel and block on the barrier
        file_put_contents($this->tmp.'/GO', 'go');

        $deadline = microtime(true) + 240;
        foreach ($procs as $w => [$proc, $stdout, $stderr]) {
            stream_set_blocking($stdout, false);
            stream_set_blocking($stderr, false);
            while (true) {
                $status = proc_get_status($proc);
                if (!$status['running']) {
                    break;
                }
                if (microtime(true) > $deadline) {
                    proc_terminate($proc);
                    $this->fail("worker {$w} timed out (genuine parallel run did not finish)");
                }
                usleep(50000);
            }
            fclose($stdout);
            fclose($stderr);
            $code = proc_close($proc);
            $this->assertSame(0, $code, "worker {$w} exit code");
        }

        $lines = [];
        foreach (glob($resultsBase.'-w*.jsonl') ?: [] as $f) {
            foreach (file($f) ?: [] as $l) {
                $l = trim($l);
                if ($l !== '') {
                    $lines[] = $l;
                }
            }
        }

        return array_map(fn ($l) => json_decode($l, true), $lines);
    }

    private function mysqlVersion(): string
    {
        return (string) (DB::selectOne('SELECT VERSION() AS v')->v ?? 'unknown');
    }

    private function makeDefaultWarehouse(string $code, string $name): Warehouse
    {
        // Suite shares one process DB (RefreshDatabase migrates fresh once per
        // process, then wraps each test in a transaction we commit for worker
        // visibility): clear any committed default from a sibling test first.
        Warehouse::where('is_default', true)->update(['is_default' => false]);

        return Warehouse::create(['code' => $code, 'name' => $name, 'status' => 'active', 'is_default' => true]);
    }

    // ---------- A.1 concurrent publication ----------

    public function test_concurrent_publication_append_only_no_lost_updates(): void
    {
        $mysql = $this->mysqlVersion();
        $admin = User::factory()->create(['type' => 'staff']);
        $workers = 6;
        $iters = 4;
        $expected = $workers * $iters;
        $baseline = WarehouseWorkflowVersion::count();

        $groups = [];
        for ($w = 0; $w < $workers; $w++) {
            $groups[] = ['op' => 'publish', 'iterations' => $iters, 'payload' => ['actor' => $admin->id]];
        }
        $results = $this->runParallel($groups);

        $ok = array_values(array_filter($results, fn ($r) => ($r['ok'] ?? false) === true));
        $failed = array_values(array_filter($results, fn ($r) => ($r['ok'] ?? false) === false));
        $this->assertCount($expected, $results, "MySQL {$mysql}: every worker iteration reported. Failures: ".json_encode($failed));
        $this->assertCount(0, $failed, 'No publication lost or partially written: '.json_encode(array_slice($failed, 0, 3)));

        $ids = array_column($ok, 'version_id');
        $this->assertCount($expected, array_unique($ids), 'Every committed publication has a unique version identity');
        $this->assertSame($expected, WarehouseWorkflowVersion::count() - $baseline, 'Delta equals parallel publications (suite shares one process DB)');

        // Every row complete and valid (no partial publication).
        $this->assertSame(0, WarehouseWorkflowVersion::whereNull('published_at')->count());
        $current = app(\App\Services\Warehouse\WarehouseWorkflowService::class)->current();
        $this->assertSame(max($ids), $current->id, 'Current = latest id ordering contract holds under parallel writes');

        // History readable and consistent.
        $this->assertSame($baseline + $expected, WarehouseWorkflowVersion::orderByDesc('id')->count());
    }

    // ---------- A.2 pinning race ----------

    public function test_fulfillment_pinning_race_pins_exactly_one_valid_version(): void
    {
        $mysql = $this->mysqlVersion();
        $warehouse = $this->makeDefaultWarehouse('WH-CONC-'.uniqid(), 'Conc');
        $product = Product::create([
            'name' => 'CONC', 'slug' => 'conc-'.uniqid(), 'sku' => 'SKU-CONC-'.uniqid(),
            'price' => 50, 'product_type' => 'simple', 'status' => true,
            'in_stock' => true, 'stock_quantity' => 1000, 'reserved_quantity' => 0, 'sold_quantity' => 0,
        ]);
        // (One customer per order below: idx_orders_user_pending_unique.)

        // v0 exists before the race: legacy NULL pins are impossible here.
        $v0 = app(\App\Services\Warehouse\WarehouseWorkflowService::class)->publish(['shipping_enabled' => true], null);

        $orderIds = [];
        for ($k = 0; $k < 6; $k++) {
            // One customer per order: idx_orders_user_pending_unique allows a
            // single pending order per user (verified business rule).
            $cust = User::factory()->create(['type' => 'customer']);
            $order = Order::create([
                'user_id' => $cust->id, 'name' => 'CC', 'user_email' => $cust->email,
                'user_phone' => '010', 'status' => 'pending',
                'payment_status' => Order::PAYMENT_STATUS_SUCCESS, 'payment_method' => 'online',
                'fulfillment_status' => 'pending', 'inventory_state' => Order::INVENTORY_STATE_COMMITTED,
                'price' => 50, 'total_price' => 50, 'currency_code' => 'EGP', 'base_currency_code' => 'EGP',
                'fulfillment_type' => 'delivery', 'address' => ['city' => 'Cairo'],
            ]);
            $order->orderItems()->create([
                'product_id' => $product->id, 'product_name' => 'CONC',
                'product_sku' => $product->sku, 'product_quantity' => 1,
                'product_price' => 50, 'product_total_price' => 50,
            ]);
            $orderIds[] = $order->id;
        }

        // 3 publishers + 3 releasers fire simultaneously on one barrier.
        $admin = User::factory()->create(['type' => 'staff']);
        $groups = [];
        for ($w = 0; $w < 3; $w++) {
            $groups[] = ['op' => 'publish', 'iterations' => 3, 'payload' => ['actor' => $admin->id]];
        }
        for ($w = 3; $w < 6; $w++) {
            $groups[] = ['op' => 'release', 'iterations' => 2, 'payload' => ['order_ids' => $orderIds, 'warehouse_id' => $warehouse->id]];
        }
        $results = $this->runParallel($groups);

        $relOk = array_values(array_filter($results, fn ($r) => ($r['ok'] ?? false) && isset($r['fulfillment_id'])));
        $this->assertNotEmpty($relOk, "MySQL {$mysql}: releases completed under parallel publishing");

        $validIds = WarehouseWorkflowVersion::pluck('id')->map(fn ($v) => (int) $v)->all();
        $this->assertContains($v0->id, $validIds);
        foreach ($relOk as $r) {
            $this->assertNotNull($r['pinned'], 'Fulfillment pinned exactly one version (no legacy NULL after v0)');
            $this->assertContains((int) $r['pinned'], $validIds, 'Pinned version is a real published version');
        }

        // Pinned versions immutable afterwards (write-once).
        $sample = Fulfillment::find($relOk[0]['fulfillment_id']);
        $original = $sample->warehouse_workflow_version_id;
        $other = end($validIds) === $original ? $validIds[0] : end($validIds);
        $sample->update(['warehouse_workflow_version_id' => $other]);
        $this->assertSame((int) $original, (int) $sample->fresh()->warehouse_workflow_version_id, 'Post-creation pin rewrite reverted');

        // No partial state: every created fulfillment has items.
        foreach ($relOk as $r) {
            $this->assertGreaterThan(0, Fulfillment::find($r['fulfillment_id'])->items()->count(), 'No inconsistent partial fulfillment');
        }
    }

    // ---------- A.3 shipment gate under concurrency ----------

    public function test_concurrent_shipment_gate_no_bypass_no_duplicates(): void
    {
        $mysql = $this->mysqlVersion();
        $t = app(\App\Services\Fulfillment\FulfillmentTransition::class);

        // Disabled-pinned fulfillments: 4 ready_to_ship rows.
        app(\App\Services\Warehouse\WarehouseWorkflowService::class)->publish(['shipping_enabled' => false], null);
        $warehouse = $this->makeDefaultWarehouse('WH-SG-'.uniqid(), 'SG');
        $product = Product::create([
            'name' => 'SG', 'slug' => 'sg-'.uniqid(), 'sku' => 'SKU-SG-'.uniqid(),
            'price' => 20, 'product_type' => 'simple', 'status' => true,
            'in_stock' => true, 'stock_quantity' => 1000, 'reserved_quantity' => 0, 'sold_quantity' => 0,
        ]);
        $customer = User::factory()->create(['type' => 'customer']);
        $disabledIds = [];
        for ($k = 0; $k < 4; $k++) {
            // One customer per pending order (idx_orders_user_pending_unique).
            $cust = User::factory()->create(['type' => 'customer']);
            $order = Order::create([
                'user_id' => $cust->id, 'name' => 'SG', 'user_email' => $cust->email,
                'user_phone' => '010', 'status' => 'pending',
                'payment_status' => Order::PAYMENT_STATUS_SUCCESS, 'payment_method' => 'online',
                'fulfillment_status' => 'pending', 'inventory_state' => Order::INVENTORY_STATE_COMMITTED,
                'price' => 20, 'total_price' => 20, 'currency_code' => 'EGP', 'base_currency_code' => 'EGP',
                'fulfillment_type' => 'delivery', 'address' => ['city' => 'Cairo'],
            ]);
            $order->orderItems()->create([
                'product_id' => $product->id, 'product_name' => 'SG',
                'product_sku' => $product->sku, 'product_quantity' => 1,
                'product_price' => 20, 'product_total_price' => 20,
            ]);
            $f = app(\App\Services\Fulfillment\FulfillmentService::class)->releaseForOrder($order, $warehouse->id, 'sg-dis-'.uniqid()."-{$k}");
            $t->transition($f, 'picking');
            $t->transition($f->fresh(), 'packing');
            $t->transition($f->fresh(), 'ready_to_ship');
            $disabledIds[] = $f->fresh()->id;
        }

        // 8 workers hammer disabled fulfillments while versions keep publishing.
        $admin = User::factory()->create(['type' => 'staff']);
        $groups = [];
        for ($w = 0; $w < 8; $w++) {
            $groups[] = $w < 6
                ? ['op' => 'ship', 'iterations' => 2, 'payload' => ['fulfillment_ids' => $disabledIds, 'key_prefix' => 'sgk-'.uniqid()]]
                : ['op' => 'publish', 'iterations' => 2, 'payload' => ['actor' => $admin->id]];
        }
        $results = $this->runParallel($groups);

        $shipAttempts = array_values(array_filter($results, fn ($r) => !isset($r['version_id'])));
        $this->assertCount(12, $shipAttempts, "MySQL {$mysql}: all 12 gate attempts reported (non-vacuous)");
        $shipOk = array_values(array_filter($shipAttempts, fn ($r) => ($r['ok'] ?? false) === true));
        $this->assertCount(0, $shipOk, "MySQL {$mysql}: gate held under parallel publishing — no bypass. Results: ".json_encode(array_slice($shipAttempts, 0, 4)));
        foreach ($shipAttempts as $a) {
            $this->assertStringContainsStringIgnoringCase(
                'disabled', (string) ($a['error'] ?? ''),
                'Every denial must be the shipping gate (not infra noise): '.json_encode($a)
            );
        }
        foreach ($disabledIds as $fid) {
            $this->assertSame(0, Shipment::where('fulfillment_id', $fid)->count(), "No accidental shipment for fulfillment {$fid}");
            $this->assertSame('ready_to_ship', Fulfillment::find($fid)->status);
        }

        // Duplicate-key race on an enabled fulfillment: exactly one row.
        app(\App\Services\Warehouse\WarehouseWorkflowService::class)->publish(['shipping_enabled' => true], null);
        $order2 = Order::create([
            'user_id' => $customer->id, 'name' => 'SG2', 'user_email' => $customer->email,
            'user_phone' => '010', 'status' => 'pending',
            'payment_status' => Order::PAYMENT_STATUS_SUCCESS, 'payment_method' => 'online',
            'fulfillment_status' => 'pending', 'inventory_state' => Order::INVENTORY_STATE_COMMITTED,
            'price' => 20, 'total_price' => 20, 'currency_code' => 'EGP', 'base_currency_code' => 'EGP',
            'fulfillment_type' => 'delivery', 'address' => ['city' => 'Cairo'],
        ]);
        $order2->orderItems()->create([
            'product_id' => $product->id, 'product_name' => 'SG',
            'product_sku' => $product->sku, 'product_quantity' => 1,
            'product_price' => 20, 'product_total_price' => 20,
        ]);
        $f2 = app(\App\Services\Fulfillment\FulfillmentService::class)->releaseForOrder($order2, $warehouse->id, 'sg-en-'.uniqid());
        $t->transition($f2, 'picking');
        $t->transition($f2->fresh(), 'packing');
        $t->transition($f2->fresh(), 'ready_to_ship');
        $f2id = $f2->fresh()->id;

        // True same-key race: all racers share one idempotency key string.
        // Service pre-check + UNIQUE backstop → single row.
        $sharedKey = 'sgdup-'.uniqid();
        $inlineResults = $this->runSameKeyRace($f2id, $sharedKey, 6);
        $this->assertSame(1, Shipment::where('fulfillment_id', $f2id)->count(), 'Exactly one shipment row under same-key parallel race');
        $this->assertGreaterThanOrEqual(1, count(array_filter($inlineResults, fn ($r) => ($r['ok'] ?? false) === true)), 'At least one racer won');
    }

    /**
     * True same-key race: all workers use literally the same idempotency key.
     */
    private function runSameKeyRace(int $fulfillmentId, string $key, int $workers): array
    {
        $script = <<<'PHP'
<?php
$cfg = json_decode(base64_decode($argv[1]), true);
putenv('DB_DATABASE=catch_verify');
$_ENV['DB_DATABASE'] = 'catch_verify';
$_SERVER['DB_DATABASE'] = 'catch_verify';
require 'D:/work/meem/vendor/autoload.php';
$app = require 'D:/work/meem/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['database.connections.mysql.database' => 'catch_verify']);
Illuminate\Support\Facades\DB::purge('mysql');
app()->setLocale('en'); // deterministic English gate messages for assertions
$deadline = microtime(true) + 60;
while (!file_exists($cfg['barrier'].'/GO')) {
    if (microtime(true) > $deadline) exit(3);
    usleep(3000);
}
try {
    $f = App\Models\Fulfillment\Fulfillment::findOrFail($cfg['fid']);
    $s = app(App\Services\Shipment\ShipmentService::class)->createForFulfillment($f, [], $cfg['key']);
    file_put_contents($cfg['results'].'-w'.$cfg['w'].'.jsonl', json_encode(['ok' => true, 'shipment_id' => $s->id]).PHP_EOL, FILE_APPEND);
} catch (Throwable $e) {
    file_put_contents($cfg['results'].'-w'.$cfg['w'].'.jsonl', json_encode(['ok' => false, 'error' => substr(get_class($e).': '.$e->getMessage(), 0, 200)]).PHP_EOL, FILE_APPEND);
}
exit(0);
PHP;
        $scriptPath = $this->tmp.'/samekey.php';
        file_put_contents($scriptPath, $script);
        $resultsBase = $this->tmp.'/samekey';
        @unlink($this->tmp.'/GO'); // force a fresh barrier so racers overlap
        $this->commitAmbientTransactions();
        $procs = [];
        foreach (range(0, $workers - 1) as $w) {
            $cfg = base64_encode(json_encode(['barrier' => $this->tmp, 'results' => $resultsBase, 'w' => $w, 'fid' => $fulfillmentId, 'key' => $key]));
            $env = array_merge(getenv(), ['DB_DATABASE' => 'catch_verify', 'APP_ENV' => 'testing']);
            $proc = proc_open([PHP_BINARY, $scriptPath, $cfg], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, 'D:/work/meem', $env);
            $this->assertIsResource($proc);
            fclose($pipes[0]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $procs[] = $proc;
        }
        sleep(4);
        file_put_contents($this->tmp.'/GO2', 'go');
        rename($this->tmp.'/GO2', $this->tmp.'/GO');
        $deadline = microtime(true) + 180;
        foreach ($procs as $proc) {
            while (true) {
                $st = proc_get_status($proc);
                if (!$st['running']) {
                    break;
                }
                if (microtime(true) > $deadline) {
                    proc_terminate($proc);
                    $this->fail('same-key worker timed out');
                }
                usleep(50000);
            }
            $this->assertSame(0, proc_close($proc));
        }
        // GO cleanup for potential reuse within the test process barrier file.
        $lines = [];
        foreach (glob($resultsBase.'-w*.jsonl') ?: [] as $f) {
            foreach (file($f) ?: [] as $l) {
                $l = trim($l);
                if ($l !== '') {
                    $lines[] = $l;
                }
            }
        }

        return array_map(fn ($l) => json_decode($l, true), $lines);
    }
}
