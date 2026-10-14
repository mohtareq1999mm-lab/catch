<?php

namespace Tests\Feature\Wms;

use App\Exceptions\PickingValidationException;
use App\Models\Fulfillment\FulfillmentItem;
use App\Models\Fulfillment\Location;
use App\Models\Fulfillment\ProductLocation;
use App\Models\Fulfillment\Warehouse;
use App\Models\Inventory\InventoryOperation;
use App\Services\Fulfillment\FulfillmentService;
use App\Services\Fulfillment\OrderPickingService;
use App\Services\Fulfillment\PickingExecutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Marvel\Database\Models\Attribute;
use Marvel\Database\Models\AttributeValue;
use Marvel\Database\Models\Category;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\ProductVariant;
use Marvel\Database\Models\User;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * MASTER mission gap coverage (owned by the inventory-consistency audit).
 *
 * Covers three behaviors the 20-case variant contract does not exercise
 * over HTTP/service boundaries:
 * 1. Idempotency-key payload binding: reusing a completed key with a
 *    DIFFERENT payload is a 409 (never a silent replay, never a second
 *    execution) and leaves stock untouched.
 * 2. Variable-product creation with an initial location is refused (422)
 *    with no partial product (stock lives on variants; one-call initial
 *    placement is unrepresentable).
 * 3. Picking confirmation rechecks SKU identity: a placement row whose
 *    variant drifted out-of-band is rejected (stale_placement), picking
 *    nothing.
 */
class WmsMasterInventoryGapsTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (['view-location', 'manage-location', 'inventory-adjust', 'manage-warehouse', 'view-warehouse', 'view-products', 'create-product', 'picking-execute', 'view-fulfillment'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'api']);
        }

        $this->warehouse = Warehouse::create(['code' => 'WH-GAP', 'name' => 'Gap', 'status' => 'active', 'is_default' => true]);
    }

    private function manager(): User
    {
        $u = User::factory()->create(['type' => 'staff', 'warehouse_id' => $this->warehouse->id]);
        $u->givePermissionTo(['view-location', 'manage-location', 'inventory-adjust', 'manage-warehouse', 'view-warehouse', 'view-products', 'create-product', 'picking-execute', 'view-fulfillment']);

        return $u;
    }

    private function location(string $code): Location
    {
        return Location::create([
            'warehouse_id' => $this->warehouse->id, 'code' => $code,
            'barcode' => 'LOC-' . $code, 'name' => 'Bin ' . $code,
            'type' => Location::TYPE_STORAGE, 'status' => Location::STATUS_ACTIVE,
        ]);
    }

    private function simpleProduct(array $over = []): Product
    {
        return Product::create(array_merge([
            'name' => 'Simple ' . Str::random(6), 'slug' => 'simple-' . Str::random(8),
            'price' => 100, 'product_type' => 'simple', 'status' => 1,
            'in_stock' => true, 'quantity' => 0, 'stock_quantity' => 0,
            'reserved_quantity' => 0, 'sold_quantity' => 0,
        ], $over));
    }

    // 1. Conflicting idempotency-key reuse → 409, stock applied exactly once.
    public function test_conflicting_idempotency_key_reuse_rejected(): void
    {
        $product = $this->simpleProduct();
        $loc = $this->location('G-IDEM');
        $key = 'gap-' . Str::random(10);

        $this->actingAs($this->manager(), 'sanctum')->postJson("/api/v1/admin/locations/{$loc->id}/stock-receipts", [
            'product_id' => $product->id, 'quantity' => 5, 'idempotency_key' => $key,
        ])->assertStatus(201);

        // Same key, DIFFERENT payload (quantity) → 409, never a replay.
        $this->actingAs($this->manager(), 'sanctum')->postJson("/api/v1/admin/locations/{$loc->id}/stock-receipts", [
            'product_id' => $product->id, 'quantity' => 6, 'idempotency_key' => $key,
        ])->assertStatus(409);

        // Same key, DIFFERENT product → 409 as well.
        $other = $this->simpleProduct();
        $this->actingAs($this->manager(), 'sanctum')->postJson("/api/v1/admin/locations/{$loc->id}/stock-receipts", [
            'product_id' => $other->id, 'quantity' => 5, 'idempotency_key' => $key,
        ])->assertStatus(409);

        $this->assertSame(5, (int) $product->fresh()->stock_quantity);
        $this->assertSame(0, (int) $other->fresh()->stock_quantity);
        $this->assertSame(1, InventoryOperation::where('idempotency_key', $key)->count());
    }

    // 2. Variable product + initial location → 422 from the placement
    // guard (request validation passes), no partial product.
    public function test_variable_product_creation_with_location_refused_without_product(): void
    {
        $loc = $this->location('G-INIT');
        $before = Product::count();

        $category = Category::create(['name' => 'Gap Cat', 'slug' => 'gap-cat-' . Str::random(6)]);
        $attribute = Attribute::create(['name' => 'Size', 'slug' => 'size-gap-' . Str::random(4)]);
        $value = AttributeValue::create([
            'attribute_id' => $attribute->id, 'value' => 'Large', 'slug' => 'large-gap-' . Str::random(4),
        ]);

        $response = $this->actingAs($this->manager(), 'sanctum')->postJson('/api/v1/products', [
            'name' => ['en' => 'Variable ' . uniqid()],
            'description' => ['en' => 'd'],
            'product_type' => 'variable',
            'categories' => [$category->id],
            'images' => [UploadedFile::fake()->image('variable.jpg')],
            'quantity' => 5,
            'in_stock' => 1,
            'has_discount' => 0,
            'has_flash_sale' => 0,
            'variants' => [['price' => 200, 'quantity' => 5, 'attribute_values' => [$value->id]]],
            'location_id' => $loc->id,
        ]);

        $response->assertStatus(422);
        // Guard message (not a request-validation error): the payload is
        // validation-clean, so this 422 proves the initial-placement guard.
        $this->assertStringContainsString('variable products', (string) $response->getContent());

        $this->assertSame($before, Product::count());
        $this->assertSame(0, (int) ProductLocation::where('location_id', $loc->id)->count());
    }

    // 3. Confirm rechecks SKU identity: drifted placement → stale_placement.
    public function test_confirm_rejects_drifted_sibling_variant_placement(): void
    {
        $parent = Product::create([
            'name' => 'VP', 'slug' => 'vp-' . uniqid(), 'sku' => 'SKU-GAP-VP',
            'price' => 100, 'product_type' => 'variable', 'status' => true,
            'in_stock' => true, 'stock_quantity' => 0, 'reserved_quantity' => 0,
        ]);
        $variantA = ProductVariant::create([
            'product_id' => $parent->id, 'sku' => 'GAP-A-' . uniqid(), 'price' => 100,
            'stock_quantity' => 10, 'reserved_quantity' => 0, 'in_stock' => true,
        ]);
        $variantB = ProductVariant::create([
            'product_id' => $parent->id, 'sku' => 'GAP-B-' . uniqid(), 'price' => 100,
            'stock_quantity' => 10, 'reserved_quantity' => 0, 'in_stock' => true,
        ]);
        $binA = $this->location('GVA-01');
        $placementA = ProductLocation::create([
            'product_id' => $parent->id, 'product_variant_id' => $variantA->id,
            'location_id' => $binA->id, 'warehouse_id' => $this->warehouse->id,
            'quantity' => 10, 'allocated_hint' => 0,
        ]);

        $customer = User::factory()->create(['type' => 'customer']);
        $order = Order::create([
            'user_id' => $customer->id, 'name' => 'V', 'user_email' => $customer->email,
            'user_phone' => '010', 'status' => 'pending',
            'payment_status' => Order::PAYMENT_STATUS_SUCCESS,
            'fulfillment_status' => 'pending',
            'inventory_state' => Order::INVENTORY_STATE_COMMITTED,
            'price' => 200, 'total_price' => 200,
            'currency_code' => 'EGP', 'base_currency_code' => 'EGP',
            'fulfillment_type' => 'delivery', 'payment_method' => 'online',
            'address' => ['city' => 'Cairo'],
        ]);
        $orderItem = $order->orderItems()->create([
            'product_id' => $parent->id, 'product_variant_id' => $variantA->id,
            'product_name' => 'VP', 'product_sku' => $variantA->sku, 'product_quantity' => 2,
            'product_price' => 100, 'product_total_price' => 200,
        ]);
        $fulfillment = \App\Models\Fulfillment\Fulfillment::create([
            'order_id' => $order->id, 'warehouse_id' => $this->warehouse->id,
            'fulfillment_number' => 'FUL-GAP-' . uniqid(), 'status' => 'pending',
        ]);
        $item = FulfillmentItem::create([
            'fulfillment_id' => $fulfillment->id, 'order_item_id' => $orderItem->id,
            'product_id' => $parent->id, 'product_variant_id' => $variantA->id,
            'product_location_id' => $placementA->id, 'quantity' => 2, 'quantity_picked' => 0,
            'status' => 'pending',
        ]);

        [$task] = app(OrderPickingService::class)->createTasksForFulfillment($fulfillment);
        $picker = User::factory()->create(['type' => 'customer']);
        app(PickingExecutionService::class)->claim($task, $picker->id);

        // Out-of-band drift: the pinned row now claims the sibling variant.
        ProductLocation::whereKey($placementA->id)->update(['product_variant_id' => $variantB->id]);

        try {
            app(PickingExecutionService::class)->confirm($task, [
                'location' => 'LOC-GVA-01', 'product' => $variantA->sku,
                'quantity' => 2, 'op_seq' => 1,
            ], $picker->id);
            $this->fail('confirm must reject a drifted sibling-variant placement');
        } catch (PickingValidationException $e) {
            $this->assertSame('stale_placement', $e->context['reason'] ?? null);
        }

        // Nothing picked anywhere: item, task, and authority all untouched.
        $this->assertSame(0, (int) $item->fresh()->quantity_picked);
        $this->assertSame(0, (int) $task->fresh()->quantity_picked);
        $this->assertNotSame('picked', $task->fresh()->status);
        $this->assertSame(10, (int) $variantA->fresh()->stock_quantity);
        $this->assertSame(10, (int) $variantB->fresh()->stock_quantity);
    }
}
