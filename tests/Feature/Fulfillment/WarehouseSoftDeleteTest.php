<?php

namespace Tests\Feature\Fulfillment;

use App\Models\Fulfillment\Fulfillment;
use App\Models\Fulfillment\FulfillmentBatch;
use App\Models\Fulfillment\Location;
use App\Models\Fulfillment\ProductLocation;
use App\Models\Fulfillment\Warehouse;
use App\Services\Fulfillment\FulfillmentService;
use App\Services\Warehouse\WarehouseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\User;
use Tests\TestCase;

/**
 * Phase 2 (P2-4) — D-WH-DEL = Soft Delete.
 *
 * - Deleted warehouse cannot back a NEW fulfillment (explicit or default).
 * - Historical records remain understandable (snapshots + FK ids, no withTrashed).
 * - Exactly one ACTIVE default is preserved; deleting the default is blocked.
 */
class WarehouseSoftDeleteTest extends TestCase
{
    use RefreshDatabase;

    private function makeWarehouse(string $code, bool $default = false): Warehouse
    {
        return Warehouse::create([
            'code' => $code, 'name' => $code, 'status' => 'active', 'is_default' => $default,
        ]);
    }

    private function makeProduct(int $stock = 10): Product
    {
        return Product::create([
            'name' => 'P', 'slug' => 'p-' . uniqid(), 'sku' => 'SKU-' . strtoupper(uniqid()),
            'price' => 100, 'product_type' => 'simple', 'status' => true,
            'in_stock' => $stock > 0, 'stock_quantity' => $stock, 'reserved_quantity' => 0,
        ]);
    }

    private function place(Product $product, Warehouse $warehouse): void
    {
        $location = Location::create([
            'warehouse_id' => $warehouse->id, 'code' => 'A-01-' . $warehouse->id,
            'name' => 'Bin', 'type' => 'picking', 'status' => 'active', 'priority' => 10,
        ]);
        ProductLocation::create([
            'product_id' => $product->id, 'location_id' => $location->id,
            'warehouse_id' => $warehouse->id, 'quantity' => 10, 'allocated_hint' => 0,
        ]);
    }

    private function makeOrder(User $user, Product $product): Order
    {
        $order = Order::create([
            'user_id' => $user->id, 'name' => $user->name, 'user_email' => $user->email,
            'user_phone' => '01000000001', 'status' => 'pending',
            'payment_status' => Order::PAYMENT_STATUS_SUCCESS,
            'fulfillment_status' => 'pending',
            'inventory_state' => Order::INVENTORY_STATE_COMMITTED,
            'price' => 200, 'total_price' => 200,
            'currency_code' => 'EGP', 'base_currency_code' => 'EGP',
            'fulfillment_type' => 'delivery', 'payment_method' => 'online',
            'address' => ['city' => 'Cairo'],
        ]);
        $order->orderItems()->create([
            'product_id' => $product->id, 'product_name' => $product->name,
            'product_sku' => $product->sku, 'product_quantity' => 2,
            'product_price' => 100, 'product_total_price' => 200,
        ]);

        return $order->refresh();
    }

    public function test_deleted_warehouse_rejected_for_explicit_new_fulfillment(): void
    {
        $a = $this->makeWarehouse('WH-A', true);
        $b = $this->makeWarehouse('WH-B');
        $b->delete();

        try {
            app(WarehouseService::class)->resolveForNewFulfillment($b->id);
            $this->fail('deleted warehouse must not resolve');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('deleted', strtolower($e->getMessage()));
        }

        // Write-path proof: release also rejects the deleted warehouse.
        $user = User::factory()->create(['type' => 'customer']);
        $order = $this->makeOrder($user, $this->makeProduct());
        try {
            app(FulfillmentService::class)->releaseForOrder($order, $b->id, 'del-key-1');
            $this->fail('release into deleted warehouse must reject');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('deleted', strtolower($e->getMessage()));
        }
        $this->assertEquals(0, Fulfillment::count());
        $this->assertTrue($a->refresh()->is_default);
    }

    public function test_deleted_default_not_effective_and_replacement_promotable(): void
    {
        $a = $this->makeWarehouse('WH-A', true);
        $b = $this->makeWarehouse('WH-B');
        app(WarehouseService::class)->setDefault($b->id);

        // A is no longer default → deletion allowed, history row retained.
        $a->delete();
        $this->assertTrue($a->refresh()->trashed());
        $this->assertNotNull(Warehouse::withTrashed()->find($a->id));

        // Effective default is B; exactly one ACTIVE default.
        $this->assertEquals($b->id, app(WarehouseService::class)->resolveForNewFulfillment(null)->id);
        $this->assertEquals(1, Warehouse::where('is_default', true)->count());

        // Unique backstop survives a trashed ex-default: promoting C works.
        $c = $this->makeWarehouse('WH-C');
        app(WarehouseService::class)->setDefault($c->id);
        $this->assertEquals(1, Warehouse::where('is_default', true)->count());
        $this->assertTrue($c->refresh()->is_default);
    }

    public function test_delete_default_blocked(): void
    {
        $a = $this->makeWarehouse('WH-A', true);
        $this->makeWarehouse('WH-B');

        try {
            $a->delete();
            $this->fail('deleting the default must throw');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('default', strtolower($e->getMessage()));
        }

        $this->assertFalse($a->refresh()->trashed());
        $this->assertTrue($a->refresh()->is_default);
    }

    public function test_historical_records_remain_understandable_after_delete(): void
    {
        $a = $this->makeWarehouse('WH-A', true);
        $b = $this->makeWarehouse('WH-B');
        $product = $this->makeProduct();
        $this->place($product, $b);
        $user = User::factory()->create(['type' => 'customer']);
        $order = $this->makeOrder($user, $product);

        $fulfillment = app(FulfillmentService::class)->releaseForOrder($order, $b->id, 'hist-key-1');
        $batch = FulfillmentBatch::create([
            'warehouse_id' => $b->id, 'batch_number' => 'B-HIST-1',
            'status' => 'pending', 'type' => 'order',
        ]);
        // Release B's fulfillment from the single-warehouse guard's reach:
        // this order now lives in B; delete B (non-default → allowed).
        $b->delete();

        $fulfillment = $fulfillment->refresh();
        // Snapshot history survives; FK identity survives; relation is
        // intentionally null (no global withTrashed) — history reads use the
        // snapshot + warehouse_id, never the live relation.
        $this->assertEquals('WH-B', $fulfillment->warehouse_code);
        $this->assertEquals('WH-B', $fulfillment->warehouse_name);
        $this->assertEquals($b->id, (int) $fulfillment->warehouse_id);
        $this->assertNull($fulfillment->warehouse);

        $batch = $batch->refresh();
        $this->assertEquals($b->id, (int) $batch->warehouse_id);

        // Locations/placements of the deleted warehouse are retained.
        $this->assertEquals(1, Location::where('warehouse_id', $b->id)->count());
        $this->assertEquals(1, ProductLocation::where('warehouse_id', $b->id)->count());
        $this->assertTrue($a->refresh()->is_default);
    }
}
