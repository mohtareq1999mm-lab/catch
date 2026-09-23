<?php

namespace Tests\Feature\Fulfillment;

use App\Models\Fulfillment\Fulfillment;
use App\Models\Fulfillment\Location;
use App\Models\Fulfillment\Package;
use App\Models\Fulfillment\ProductLocation;
use App\Models\Fulfillment\Warehouse;
use App\Services\Fulfillment\FulfillmentService;
use App\Services\Fulfillment\FulfillmentTransition;
use App\Services\Fulfillment\PackingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\User;
use Tests\TestCase;

/**
 * Phase 10 — packing: package invariant, multi-package split, seal rules.
 */
class PackingTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;
    private Product $product;
    private Fulfillment $fulfillment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->warehouse = Warehouse::create([
            'code' => 'WH-1', 'name' => 'Main', 'status' => 'active', 'is_default' => true,
        ]);
        $location = Location::create([
            'warehouse_id' => $this->warehouse->id, 'code' => 'A-01',
            'barcode' => 'LOC-A-01', 'name' => 'Bin', 'type' => 'picking',
            'status' => 'active', 'priority' => 1,
        ]);
        $this->product = Product::create([
            'name' => 'P', 'slug' => 'p-' . uniqid(), 'sku' => 'SKU-PACK-1',
            'price' => 100, 'product_type' => 'simple', 'status' => true,
            'in_stock' => true, 'stock_quantity' => 50, 'reserved_quantity' => 0,
        ]);
        ProductLocation::create([
            'product_id' => $this->product->id, 'location_id' => $location->id,
            'warehouse_id' => $this->warehouse->id, 'quantity' => 50, 'allocated_hint' => 0,
        ]);

        $user = User::factory()->create(['type' => 'customer']);
        $order = Order::create([
            'user_id' => $user->id, 'name' => 'O', 'user_email' => $user->email,
            'user_phone' => '010', 'status' => 'pending',
            'payment_status' => Order::PAYMENT_STATUS_SUCCESS,
            'fulfillment_status' => 'pending',
            'inventory_state' => Order::INVENTORY_STATE_COMMITTED,
            'price' => 1000, 'total_price' => 1000,
            'currency_code' => 'EGP', 'base_currency_code' => 'EGP',
            'fulfillment_type' => 'delivery', 'payment_method' => 'online',
            'address' => ['city' => 'Cairo'],
        ]);
        $order->orderItems()->create([
            'product_id' => $this->product->id, 'product_name' => 'P',
            'product_sku' => 'SKU-PACK-1', 'product_quantity' => 10,
            'product_price' => 100, 'product_total_price' => 1000,
        ]);
        $this->fulfillment = app(FulfillmentService::class)
            ->releaseForOrder($order->refresh(), $this->warehouse->id, 'pack-key');

        // Simulate fully picked item (picking covered in P8/P9).
        $item = $this->fulfillment->items()->firstOrFail();
        $item->update(['quantity_picked' => 10, 'status' => 'picked']);
        app(FulfillmentTransition::class)->transition($this->fulfillment, 'picking');
        app(FulfillmentTransition::class)->transition($this->fulfillment, 'picked');
        app(FulfillmentTransition::class)->transition($this->fulfillment, 'packing');
        $this->fulfillment->refresh();
    }

    private function service(): PackingService
    {
        return app(PackingService::class);
    }

    public function test_multi_package_split_respects_picked_invariant(): void
    {
        $item = $this->fulfillment->items()->firstOrFail();

        $packageA = $this->service()->createPackage($this->fulfillment);
        $this->service()->addItemToPackage($packageA, $item->id, 4);
        $packageB = $this->service()->createPackage($this->fulfillment);
        $this->service()->addItemToPackage($packageB, $item->id, 6);

        $this->assertEquals(4, (float) $packageA->items()->first()->quantity);
        $this->assertEquals(6, (float) $packageB->items()->first()->quantity);
        // Traceability: fulfillment + order links.
        $this->assertEquals($this->fulfillment->id, (int) $packageA->fulfillment_id);
        $this->assertEquals($this->fulfillment->order_id, (int) $packageA->order_id);

        // 4 + 6 = 10 picked → one more unit over-packs.
        try {
            $this->service()->addItemToPackage($packageB, $item->id, 1);
            $this->fail('over-pack must reject');
        } catch (\Exception $e) {
            $this->assertStringContainsString('Over-pack', $e->getMessage());
        }
    }

    public function test_rejects_unpicked_foreign_and_sealed_modification(): void
    {
        $item = $this->fulfillment->items()->firstOrFail();
        $item->update(['quantity_picked' => 0]);

        $package = $this->service()->createPackage($this->fulfillment);
        try {
            $this->service()->addItemToPackage($package, $item->id, 1);
            $this->fail('unpicked pack must reject');
        } catch (\Exception $e) {
            $this->assertStringContainsString('unpicked', $e->getMessage());
        }

        // Foreign fulfillment item.
        $other = Fulfillment::create([
            'order_id' => $this->fulfillment->order_id, 'warehouse_id' => $this->warehouse->id,
            'fulfillment_number' => 'FUL-FOREIGN', 'status' => 'packing',
        ]);
        $foreignItem = $other->items()->create([
            'order_item_id' => 1, 'product_id' => $this->product->id,
            'quantity' => 1, 'quantity_picked' => 1, 'status' => 'picked',
        ]);
        try {
            $this->service()->addItemToPackage($package, $foreignItem->id, 1);
            $this->fail('foreign item must reject');
        } catch (\Exception $e) {
            $this->assertStringContainsString('different fulfillment', $e->getMessage());
        }

        // Empty seal rejected; sealed package immutable.
        try {
            $this->service()->sealPackage($package);
            $this->fail('empty seal must reject');
        } catch (\Exception $e) {
            $this->assertStringContainsString('empty', $e->getMessage());
        }

        $item->update(['quantity_picked' => 5]);
        $this->service()->addItemToPackage($package, $item->id, 5);
        $sealed = $this->service()->sealPackage($package, 2.5, ['length' => 30]);
        $this->assertEquals(Package::STATUS_SEALED, $sealed->status);
        $this->assertNotNull($sealed->barcode);
        $this->assertNotNull($sealed->sealed_at);

        try {
            $this->service()->addItemToPackage($sealed, $item->id, 1);
            $this->fail('sealed modification must reject');
        } catch (\Exception $e) {
            $this->assertStringContainsString('sealed', $e->getMessage());
        }
    }
}
