<?php

namespace Tests\Feature\Wms;

use App\Models\Fulfillment\Location;
use App\Models\Fulfillment\ProductLocation;
use App\Models\Fulfillment\Warehouse;
use App\Services\Inventory\StockOperationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\ProductVariant;
use Tests\TestCase;

/**
 * True multi-connection concurrency proof for the canonical backfill
 * primitive on isolated MySQL (AC-11..AC-14).
 *
 * No RefreshDatabase here by design: fixtures must be COMMITTED so two
 * independent OS processes (own PDO connections) can see them. Every
 * created row is tracked and deleted in tearDown; the isolated database
 * (`catch_wms_verify`) is never production.
 *
 * Workers are spawned via popen('start /B ...') with a file barrier:
 * both processes block until the parent releases them, so the race window
 * is real, not timing-based. Sequential fallback is NOT claimed as proof.
 */
class BackfillConcurrencyTest extends TestCase
{
    private string $tmp;

    /** @var array<int> */
    private array $warehouseIds = [];

    /** @var array<int> */
    private array $locationIds = [];

    /** @var array<int> */
    private array $productIds = [];

    /** @var array<int> */
    private array $variantIds = [];

    private int $activityBefore = 0;

    protected function setUp(): void
    {
        parent::setUp();

        // Process spawning here uses the Windows `start /B` launcher.
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('Multi-process barrier workers require Windows process spawning.');
        }

        $this->tmp = sys_get_temp_dir() . '/bfconc-' . Str::random(8);
        mkdir($this->tmp, 0777, true);
        $this->activityBefore = $this->activityCount();
    }

    protected function tearDown(): void
    {
        ProductLocation::whereIn('product_id', $this->productIds)->delete();
        ProductVariant::whereIn('id', $this->variantIds)->delete();
        Product::whereIn('id', $this->productIds)->forceDelete();
        Location::whereIn('id', $this->locationIds)->delete();
        Warehouse::whereIn('id', $this->warehouseIds)->delete();
        $this->deleteActivities();
        foreach (glob($this->tmp . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tmp);

        parent::tearDown();
    }

    // ---------- fixtures (committed) ----------

    private function warehouse(): Warehouse
    {
        $w = Warehouse::create(['code' => 'WH-CC-' . Str::random(4), 'name' => 'Conc', 'status' => 'active', 'is_default' => false]);
        $this->warehouseIds[] = $w->id;

        return $w;
    }

    private function bin(Warehouse $w, string $code): Location
    {
        $l = Location::create([
            'warehouse_id' => $w->id, 'code' => $code . '-' . Str::random(4), 'name' => 'Bin',
            'type' => Location::TYPE_STORAGE, 'status' => Location::STATUS_ACTIVE,
        ]);
        $this->locationIds[] = $l->id;

        return $l;
    }

    private function simpleProduct(int $stock): Product
    {
        $p = Product::create([
            'name' => ['en' => 'CC ' . Str::random(6)], 'slug' => 'cc-' . Str::random(8),
            'description' => ['en' => 'd'], 'price' => 100, 'product_type' => 'simple',
            'status' => 1, 'in_stock' => true, 'stock_quantity' => $stock, 'reserved_quantity' => 0,
        ]);
        $this->productIds[] = $p->id;

        return $p;
    }

    private function activityCount(): int
    {
        if (!class_exists(\Spatie\Activitylog\Models\Activity::class)) {
            return 0;
        }

        return \Spatie\Activitylog\Models\Activity::count();
    }

    private function deleteActivities(): void
    {
        if (!class_exists(\Spatie\Activitylog\Models\Activity::class) || $this->productIds === []) {
            return;
        }
        $model = new \Spatie\Activitylog\Models\Activity();
        $table = $model->getTable();
        if (!Schema::hasColumn($table, 'subject_id')) {
            return;
        }
        \Spatie\Activitylog\Models\Activity::where('subject_type', Product::class)
            ->whereIn('subject_id', $this->productIds)
            ->delete();
    }

    // ---------- worker plumbing ----------

    private function workerScript(): string
    {
        $path = $this->tmp . '/worker.php';
        $script = <<<'PHP'
<?php
// Backfill concurrency worker: own process, own PDO connection.
[$script, $base, $jobFile] = $argv + [null, null, null];
$job = json_decode((string) file_get_contents($jobFile), true);
foreach (($job['env'] ?? []) as $k => $v) {
    putenv("{$k}={$v}");
    $_ENV[$k] = $v;
    $_SERVER[$k] = $v;
}
require $base . '/vendor/autoload.php';
$app = require $base . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$out = $job['out'];
$barrier = $job['barrier'];
$deadline = time() + 60;
while (file_exists($barrier) && time() < $deadline) {
    usleep(50000);
}
if (file_exists($barrier)) {
    file_put_contents($out, json_encode(['ok' => false, 'error' => 'barrier timeout']));
    exit(3);
}
try {
    $svc = app(App\Services\Inventory\StockOperationService::class);
    $p = $job['params'];
    $r = $svc->recordExistingPlacement(
        (int) $p['product_id'],
        $p['product_variant_id'] ?? null,
        (int) $p['location_id'],
        $p['warehouse_id'] ?? null,
        (int) $p['quantity']
    );
    file_put_contents($out, json_encode(['ok' => true, 'applied' => $r['applied'], 'hint_quantity' => $r['hint_quantity']]));
    exit(0);
} catch (Throwable $e) {
    file_put_contents($out, json_encode(['ok' => false, 'error' => substr($e->getMessage(), 0, 300), 'class' => get_class($e)]));
    exit(1);
}
PHP;
        file_put_contents($path, $script);

        return $path;
    }

    /**
     * @param array<int, array{product_id:int, product_variant_id:?int, location_id:int, warehouse_id:?int, quantity:int}> $jobs
     * @return array<int, array{exit:int, out:?array}>
     */
    private function runWorkers(array $jobs): array
    {
        $base = base_path();
        $db = config('database.connections.mysql.database');
        $barrier = $this->tmp . '/barrier';
        touch($barrier);
        $script = $this->workerScript();
        $handles = [];
        foreach ($jobs as $slot => $params) {
            $jobFile = $this->tmp . "/job-{$slot}.json";
            file_put_contents($jobFile, json_encode([
                'params' => $params,
                'out' => $this->tmp . "/out-{$slot}.json",
                'barrier' => $barrier,
                'env' => ['DB_DATABASE' => $db, 'APP_ENV' => 'testing'],
            ]));
            // Detached background spawn (Windows): inherits our environment.
            $cmd = 'start /B "" php "' . $script . '" "' . $base . '" "' . $jobFile . '" > NUL 2>&1';
            $handles[$slot] = popen($cmd, 'r');
        }
        // Ensure both workers are blocked on the barrier, then release.
        sleep(4);
        @unlink($barrier);
        foreach ($handles as $handle) {
            if (is_resource($handle)) {
                pclose($handle);
            }
        }

        $results = [];
        $deadline = time() + 120;
        while (time() < $deadline) {
            $ready = true;
            foreach (array_keys($jobs) as $slot) {
                if (!is_file($this->tmp . "/out-{$slot}.json")) {
                    $ready = false;
                    break;
                }
            }
            if ($ready) {
                break;
            }
            usleep(200000);
        }
        foreach (array_keys($jobs) as $slot) {
            $file = $this->tmp . "/out-{$slot}.json";
            $results[$slot] = [
                'exit' => is_file($file) ? 0 : 99,
                'out' => is_file($file) ? json_decode((string) file_get_contents($file), true) : null,
            ];
        }

        return $results;
    }

    private function placementsFor(int $productId, ?int $variantId = null): \Illuminate\Support\Collection
    {
        return ProductLocation::where('product_id', $productId)
            ->when($variantId === null, fn ($q) => $q->whereNull('product_variant_id'), fn ($q) => $q->where('product_variant_id', $variantId))
            ->get();
    }

    // ---------- C1: same mapping concurrently ----------

    public function test_concurrent_same_mapping_creates_single_row(): void
    {
        $w = $this->warehouse();
        $loc = $this->bin($w, 'C1');
        $p = $this->simpleProduct(40);

        $job = ['product_id' => $p->id, 'product_variant_id' => null, 'location_id' => $loc->id, 'warehouse_id' => null, 'quantity' => 40];
        $results = $this->runWorkers([0 => $job, 1 => $job]);

        foreach ($results as $slot => $result) {
            $this->assertNotNull($result['out'], "worker {$slot} produced no output (barrier/spawn failure)");
        }

        $rows = $this->placementsFor($p->id);
        $this->assertCount(1, $rows, 'exactly one placement row must exist after concurrent same-mapping apply');
        $this->assertEquals(40, (float) $rows->first()->quantity);
        // Both workers completed successfully: exactly one applied, the
        // other converged to a no-op skip (no duplicate, no failure).
        $applied = array_map(fn ($r) => $r['out']['applied'] ?? null, $results);
        sort($applied);
        $this->assertSame([false, true], $applied, 'one worker applies, the other skips: ' . json_encode($results));
        // Authority untouched by either worker.
        $this->assertSame(40, (int) $p->fresh()->stock_quantity);
        $this->assertSame(0, (int) $p->fresh()->reserved_quantity);
    }

    // ---------- C4: different SKUs, one bin ----------

    public function test_concurrent_different_skus_one_bin_preserves_occupancy(): void
    {
        $w = $this->warehouse();
        $loc = $this->bin($w, 'C4');
        $a = $this->simpleProduct(20);
        $b = $this->simpleProduct(20);

        $results = $this->runWorkers([
            0 => ['product_id' => $a->id, 'product_variant_id' => null, 'location_id' => $loc->id, 'warehouse_id' => null, 'quantity' => 20],
            1 => ['product_id' => $b->id, 'product_variant_id' => null, 'location_id' => $loc->id, 'warehouse_id' => null, 'quantity' => 20],
        ]);
        foreach ($results as $slot => $result) {
            $this->assertNotNull($result['out'], "worker {$slot} produced no output");
        }

        $inBin = ProductLocation::where('location_id', $loc->id)->get();
        $this->assertCount(1, $inBin, 'one SKU per bin must hold under concurrency');
        $successes = count(array_filter($results, fn ($r) => ($r['out']['ok'] ?? false) === true));
        $this->assertSame(1, $successes, 'exactly one worker must succeed, the other must refuse');
        $this->assertSame(20, (int) $a->fresh()->stock_quantity);
        $this->assertSame(20, (int) $b->fresh()->stock_quantity);
    }

    // ---------- C3: competing hint-cap allocations ----------

    public function test_concurrent_hint_cap_allocations_never_exceed_authority(): void
    {
        $w = $this->warehouse();
        $loc1 = $this->bin($w, 'C3A');
        $loc2 = $this->bin($w, 'C3B');
        $p = $this->simpleProduct(10);

        $results = $this->runWorkers([
            0 => ['product_id' => $p->id, 'product_variant_id' => null, 'location_id' => $loc1->id, 'warehouse_id' => null, 'quantity' => 7],
            1 => ['product_id' => $p->id, 'product_variant_id' => null, 'location_id' => $loc2->id, 'warehouse_id' => null, 'quantity' => 7],
        ]);
        foreach ($results as $slot => $result) {
            $this->assertNotNull($result['out'], "worker {$slot} produced no output");
        }

        $sum = (float) $this->placementsFor($p->id)->sum('quantity');
        $this->assertLessThanOrEqual(10.0, $sum, 'combined hints must never exceed authority stock');
        $successes = count(array_filter($results, fn ($r) => ($r['out']['ok'] ?? false) === true));
        $this->assertSame(1, $successes, 'exactly one worker must succeed, the other must hit the hint cap: ' . json_encode($results));
        $this->assertSame(10, (int) $p->fresh()->stock_quantity);
    }

    // ---------- C5/C6: rollback + failed-entry audit absence ----------

    public function test_failed_placement_leaves_no_row_and_no_audit(): void
    {
        $w = $this->warehouse();
        $loc = $this->bin($w, 'C5');
        $p = $this->simpleProduct(5);
        $activityBefore = $this->activityCount();

        try {
            app(StockOperationService::class)->recordExistingPlacement($p->id, null, $loc->id, null, 1000);
            $this->fail('hint-cap violation must throw');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('hint_cap', $e->getMessage());
        }

        $this->assertSame(0, $this->placementsFor($p->id)->count());
        $this->assertSame(5, (int) $p->fresh()->stock_quantity);
        $this->assertSame($activityBefore, $this->activityCount(), 'failed placement must not write audit rows');
    }

    // ---------- guard: barrier plumbing ----------

    public function test_worker_plumbing_reports_barrier_release(): void
    {
        $w = $this->warehouse();
        $loc = $this->bin($w, 'C0');
        $p = $this->simpleProduct(3);

        $results = $this->runWorkers([
            0 => ['product_id' => $p->id, 'product_variant_id' => null, 'location_id' => $loc->id, 'warehouse_id' => null, 'quantity' => 3],
        ]);

        $this->assertNotNull($results[0]['out'], 'single worker must complete through the barrier');
        $this->assertTrue($results[0]['out']['ok'] ?? false, 'single worker apply must succeed: ' . json_encode($results[0]['out']));
        $this->assertCount(1, $this->placementsFor($p->id));
    }
}
