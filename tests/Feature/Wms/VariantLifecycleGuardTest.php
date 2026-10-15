<?php

namespace Tests\Feature\Wms;

use App\Models\Fulfillment\Fulfillment;
use App\Models\Fulfillment\FulfillmentItem;
use App\Models\Fulfillment\Location;
use App\Models\Fulfillment\ProductLocation;
use App\Models\Fulfillment\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\ProductVariant;
use Marvel\Database\Models\User;
use Marvel\Enums\Permission as MarvelPermission;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Option C variant-lifecycle guard (ProductRepository::assertVariantsReplaceable).
 *
 * Externally observable contract only: HTTP status, standard error envelope,
 * and database invariants. Isolated MySQL (RefreshDatabase), sequential.
 */
class VariantLifecycleGuardTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    private Location $bin;

    protected function setUp(): void
    {
        parent::setUp();
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ([MarvelPermission::VIEW_PRODUCTS, MarvelPermission::UPDATE_PRODUCT] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'api']);
        }

        $this->warehouse = Warehouse::create(['code' => 'WH-VG', 'name' => 'Guard', 'status' => 'active', 'is_default' => true]);
        $this->bin = Location::create([
            'warehouse_id' => $this->warehouse->id, 'code' => 'VG-01', 'barcode' => 'VG-LOC-01',
            'name' => 'Bin', 'type' => 'picking', 'status' => 'active', 'priority' => 1,
        ]);
    }

    private function admin(): User
    {
        $u = User::factory()->create(['type' => 'admin', 'warehouse_id' => null]);
        $u->givePermissionTo([MarvelPermission::VIEW_PRODUCTS, MarvelPermission::UPDATE_PRODUCT]);

        return $u;
    }

    private function variableProduct(): array
    {
        $p = Product::create([
            'name' => 'VG', 'slug' => 'vg-' . uniqid(), 'sku' => 'SKU-VG-' . uniqid(),
            'price' => 0, 'product_type' => 'variable', 'status' => true,
            'in_stock' => true, 'stock_quantity' => 0, 'reserved_quantity' => 0,
        ]);
        $a = ProductVariant::create(['product_id' => $p->id, 'sku' => 'VA-' . uniqid(), 'price' => 100, 'stock_quantity' => 10, 'reserved_quantity' => 0, 'in_stock' => true]);
        $b = ProductVariant::create(['product_id' => $p->id, 'sku' => 'VB-' . uniqid(), 'price' => 200, 'stock_quantity' => 20, 'reserved_quantity' => 0, 'in_stock' => true]);

        return [$p, $a, $b];
    }

    private function replaceVariants(int $productId, array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin(), 'sanctum')->putJson(
            "/api/v1/products/{$productId}",
            array_merge(['variants' => [['price' => 100, 'quantity' => 10]]], $extra)
        );
    }

    private function assertRefusedUnchanged(\Illuminate\Testing\TestResponse $response, Product $p, array $variantIds): void
    {
        $response->assertStatus(422);
        $body = $response->json();
        $this->assertFalse($body['success'] ?? $body['status'] ?? true);
        $this->assertIsString($body['message'] ?? null);
        foreach (['SQLSTATE', '1451', 'QueryException', 'product_variant_id_foreign'] as $leak) {
            $this->assertStringNotContainsString($leak, (string) $response->getContent());
        }
        // Nothing rewritten: same variant IDs in the same order.
        $expected = $variantIds;
        sort($expected);
        $this->assertSame(
            $expected,
            ProductVariant::where('product_id', $p->id)->orderBy('id')->pluck('id')->all()
        );
    }

    public function test_fulfillment_history_refuses_with_422_and_preserves_everything(): void
    {
        [$p, $a, $b] = $this->variableProduct();
        $customer = User::factory()->create(['type' => 'customer']);
        $order = Order::create([
            'user_id' => $customer->id, 'name' => 'VH', 'user_email' => $customer->email,
            'user_phone' => '010', 'status' => 'processing',
            'payment_status' => Order::PAYMENT_STATUS_SUCCESS, 'payment_method' => 'online',
            'fulfillment_status' => 'pending', 'inventory_state' => Order::INVENTORY_STATE_COMMITTED,
            'price' => 100, 'total_price' => 100, 'currency_code' => 'EGP', 'base_currency_code' => 'EGP',
            'fulfillment_type' => 'delivery', 'address' => ['city' => 'Cairo'],
        ]);
        $fulfillment = Fulfillment::create([
            'order_id' => $order->id, 'warehouse_id' => $this->warehouse->id,
            'fulfillment_number' => 'FUL-VG-' . uniqid(), 'status' => 'pending', 'priority' => 'normal',
        ]);
        $orderItem = $order->orderItems()->create([
            'product_id' => $p->id, 'product_name' => 'VG', 'product_sku' => 'SKU-VG',
            'product_quantity' => 1, 'product_price' => 100, 'product_total_price' => 100,
        ]);
        $item = FulfillmentItem::create([
            'fulfillment_id' => $fulfillment->id, 'order_item_id' => $orderItem->id,
            'product_id' => $p->id, 'product_variant_id' => $a->id,
            'product_location_id' => null, 'quantity' => 1, 'quantity_picked' => 0, 'status' => 'pending',
        ]);

        $response = $this->replaceVariants($p->id);

        $this->assertRefusedUnchanged($response, $p, [$a->id, $b->id]);
        $this->assertNotNull(ProductVariant::find($a->id));
        $this->assertNotNull(FulfillmentItem::find($item->id));
        $this->assertNotNull($order->orderItems()->find($orderItem->id));
        // Repeat is identically refused (no partial state from attempt one).
        $this->replaceVariants($p->id)->assertStatus(422);
        $this->assertNotNull(ProductVariant::find($a->id));
    }

    public function test_live_placement_refuses_and_survives(): void
    {
        [$p, $a, $b] = $this->variableProduct();
        $placement = ProductLocation::create([
            'product_id' => $p->id, 'product_variant_id' => $a->id,
            'location_id' => $this->bin->id, 'warehouse_id' => $this->warehouse->id,
            'quantity' => 10, 'allocated_hint' => 0,
        ]);

        $this->assertRefusedUnchanged($this->replaceVariants($p->id), $p, [$a->id, $b->id]);
        $this->assertNotNull(ProductLocation::find($placement->id));
        $this->assertEquals(10, (float) ProductLocation::find($placement->id)->quantity);
    }

    public function test_order_line_reference_refuses_without_unattribution(): void
    {
        [$p, $a, $b] = $this->variableProduct();
        $customer = User::factory()->create(['type' => 'customer']);
        $order = Order::create([
            'user_id' => $customer->id, 'name' => 'VO', 'user_email' => $customer->email,
            'user_phone' => '010', 'status' => 'processing',
            'payment_status' => Order::PAYMENT_STATUS_SUCCESS, 'payment_method' => 'online',
            'fulfillment_status' => 'pending', 'inventory_state' => Order::INVENTORY_STATE_COMMITTED,
            'price' => 100, 'total_price' => 100, 'currency_code' => 'EGP', 'base_currency_code' => 'EGP',
            'fulfillment_type' => 'delivery', 'address' => ['city' => 'Cairo'],
        ]);
        $orderItem = $order->orderItems()->create([
            'product_id' => $p->id, 'product_variant_id' => $a->id, 'product_name' => 'VG',
            'product_sku' => 'SKU-VG', 'product_quantity' => 1, 'product_price' => 100, 'product_total_price' => 100,
        ]);

        $this->assertRefusedUnchanged($this->replaceVariants($p->id), $p, [$a->id, $b->id]);
        $this->assertSame($a->id, (int) $order->orderItems()->find($orderItem->id)->product_variant_id);
    }

    public function test_reserved_variant_refuses(): void
    {
        [$p, $a, $b] = $this->variableProduct();
        $a->update(['reserved_quantity' => 4]);

        $this->assertRefusedUnchanged($this->replaceVariants($p->id), $p, [$a->id, $b->id]);
        $this->assertSame(4, (int) ProductVariant::find($a->id)->reserved_quantity);
    }

    public function test_unprotected_valid_update_keeps_existing_contract(): void
    {
        [$p] = $this->variableProduct();

        $response = $this->replaceVariants($p->id);

        $response->assertStatus(200)->assertJson(['success' => true]);
        $this->assertCount(1, ProductVariant::where('product_id', $p->id)->get());
    }

    public function test_simple_product_update_unaffected(): void
    {
        $p = Product::create([
            'name' => 'VS', 'slug' => 'vs-' . uniqid(), 'sku' => 'SKU-VS-' . uniqid(),
            'price' => 50, 'product_type' => 'simple', 'status' => true,
            'in_stock' => true, 'stock_quantity' => 9, 'reserved_quantity' => 0,
        ]);

        $this->actingAs($this->admin(), 'sanctum')->putJson("/api/v1/products/{$p->id}", [
            'price' => 60,
        ])->assertStatus(200);

        $this->assertSame(60.0, (float) $p->fresh()->price);
        $this->assertSame(9, (int) $p->fresh()->stock_quantity);
    }
}
