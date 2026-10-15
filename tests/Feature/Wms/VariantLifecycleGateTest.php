<?php

namespace Tests\Feature\Wms;

use App\Models\Fulfillment\Location;
use App\Models\Fulfillment\ProductLocation;
use App\Models\Fulfillment\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\ProductVariant;
use Tests\TestCase;

/**
 * Gate 2 — variant lifecycle reproduction (read-only behaviors first).
 *
 * Proves, on an isolated database, exactly what
 * ProductRepository::updateProduct does when `variants` are supplied:
 * identity preservation, placement cascade, history constraint, and
 * attribute preservation. No implementation change here.
 */
class VariantLifecycleGateTest extends TestCase
{
    use RefreshDatabase;

    private function variableProduct(int $stockA = 10, int $stockB = 20): array
    {
        $p = Product::create([
            'name' => 'VG', 'slug' => 'vg-' . uniqid(), 'sku' => 'SKU-VG',
            'price' => 0, 'product_type' => 'variable', 'status' => true,
            'in_stock' => true, 'stock_quantity' => 0, 'reserved_quantity' => 0,
        ]);
        $a = ProductVariant::create(['product_id' => $p->id, 'sku' => 'VA-' . uniqid(), 'price' => 100, 'stock_quantity' => $stockA, 'reserved_quantity' => 0, 'in_stock' => true]);
        $b = ProductVariant::create(['product_id' => $p->id, 'sku' => 'VB-' . uniqid(), 'price' => 200, 'stock_quantity' => $stockB, 'reserved_quantity' => 0, 'in_stock' => true]);

        return [$p, $a, $b];
    }

    public function test_variant_ids_are_recreated_not_preserved(): void
    {
        [$p, $a, $b] = $this->variableProduct();
        $repo = app(\Marvel\Database\Repositories\ProductRepository::class);
        $request = new \Illuminate\Http\Request([
            'variants' => [
                ['price' => 100, 'quantity' => 10, 'attribute_values' => []],
                ['price' => 200, 'quantity' => 20, 'attribute_values' => []],
            ],
        ]);
        $repo->updateProduct($request, $p->id);

        $idsAfter = ProductVariant::where('product_id', $p->id)->orderBy('id')->pluck('id')->all();
        $this->assertCount(2, $idsAfter);
        $this->assertEmpty(array_intersect([$a->id, $b->id], $idsAfter), 'old variant IDs must be gone if wipe-and-recreate runs');
    }

    public function test_placements_cascade_on_variant_wipe(): void
    {
        [$p, $a] = $this->variableProduct();
        $w = Warehouse::create(['code' => 'WH-VG', 'name' => 'VG', 'status' => 'active', 'is_default' => true]);
        $loc = Location::create(['warehouse_id' => $w->id, 'code' => 'VG-01', 'barcode' => 'VG-LOC-01', 'name' => 'Bin', 'type' => 'picking', 'status' => 'active', 'priority' => 1]);
        ProductLocation::create(['product_id' => $p->id, 'product_variant_id' => $a->id, 'location_id' => $loc->id, 'warehouse_id' => $w->id, 'quantity' => 10, 'allocated_hint' => 0]);
        $this->assertSame(1, ProductLocation::where('product_variant_id', $a->id)->count());

        $repo = app(\Marvel\Database\Repositories\ProductRepository::class);
        $request = new \Illuminate\Http\Request([
            'variants' => [['price' => 100, 'quantity' => 10, 'attribute_values' => []]],
        ]);
        $repo->updateProduct($request, $p->id);

        $this->assertSame(0, ProductLocation::where('product_variant_id', $a->id)->count());
    }

    public function test_fulfillment_history_blocks_variant_delete(): void
    {
        [$p, $a] = $this->variableProduct();
        // Minimal fulfillment history row referencing the variant: the
        // RESTRICT FK must refuse the wipe loudly instead of losing history.
        $user = \Marvel\Database\Models\User::factory()->create(['type' => 'customer']);
        $order = \Marvel\Database\Models\Order::create([
            'user_id' => $user->id, 'name' => 'VH', 'user_email' => $user->email,
            'user_phone' => '010', 'status' => 'processing',
            'payment_status' => \Marvel\Database\Models\Order::PAYMENT_STATUS_SUCCESS,
            'payment_method' => 'online', 'fulfillment_status' => 'pending',
            'inventory_state' => \Marvel\Database\Models\Order::INVENTORY_STATE_COMMITTED,
            'price' => 100, 'total_price' => 100, 'currency_code' => 'EGP',
            'base_currency_code' => 'EGP', 'fulfillment_type' => 'delivery', 'address' => ['city' => 'Cairo'],
        ]);
        $w = Warehouse::create(['code' => 'WH-VH', 'name' => 'VH', 'status' => 'active', 'is_default' => true]);
        $fulfillment = \App\Models\Fulfillment\Fulfillment::create([
            'order_id' => $order->id, 'warehouse_id' => $w->id,
            'fulfillment_number' => 'FUL-GATE-' . uniqid(), 'status' => 'pending', 'priority' => 'normal',
        ]);
        $orderItem = $order->orderItems()->create([
            'product_id' => $p->id, 'product_name' => 'VG', 'product_sku' => 'SKU-VG',
            'product_quantity' => 1, 'product_price' => 100, 'product_total_price' => 100,
        ]);
        \App\Models\Fulfillment\FulfillmentItem::create([
            'fulfillment_id' => $fulfillment->id, 'order_item_id' => $orderItem->id,
            'product_id' => $p->id, 'product_variant_id' => $a->id,
            'product_location_id' => null, 'quantity' => 1, 'quantity_picked' => 0, 'status' => 'pending',
        ]);

        $repo = app(\Marvel\Database\Repositories\ProductRepository::class);
        $request = new \Illuminate\Http\Request([
            'variants' => [['price' => 100, 'quantity' => 10, 'attribute_values' => []]],
        ]);
        try {
            $repo->updateProduct($request, $p->id);
            $blocked = false;
        } catch (\Throwable) {
            $blocked = true;
        }

        $this->assertTrue($blocked, 'RESTRICT history must refuse the variant wipe loudly');
        $this->assertNotNull(ProductVariant::find($a->id), 'original variant row must survive the refused wipe');
    }
}
