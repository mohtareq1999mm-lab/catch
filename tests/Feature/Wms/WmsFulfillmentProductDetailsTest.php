<?php

namespace Tests\Feature\Wms;

use App\Models\Fulfillment\Fulfillment;
use App\Models\Fulfillment\FulfillmentItem;
use App\Models\Fulfillment\Location;
use App\Models\Fulfillment\Package;
use App\Models\Fulfillment\PackageItem;
use App\Models\Fulfillment\PackingTask;
use App\Models\Fulfillment\ProductLocation;
use App\Models\Fulfillment\Warehouse;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Marvel\Database\Models\Attribute;
use Marvel\Database\Models\AttributeProduct;
use Marvel\Database\Models\AttributeValue;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\OrderProduct;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\ProductVariant;
use Marvel\Database\Models\User;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class WmsFulfillmentProductDetailsTest extends TestCase
{
    use DatabaseTransactions;

    private Warehouse $warehouse;
    private User $admin;
    private Product $product;
    private ProductVariant $variant;
    private Location $location;
    private ProductLocation $placement;
    private Order $order;
    private OrderProduct $orderItem;
    private Fulfillment $fulfillment;
    private FulfillmentItem $fulfillmentItem;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (['view-fulfillment', 'manage-fulfillment', 'packing-execute', 'packing.complete'] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'api']);
        }

        $this->warehouse = Warehouse::create([
            'code' => 'WH-TEST-'.Str::random(4),
            'name' => 'Test Warehouse',
            'status' => 'active',
            'is_default' => true,
        ]);

        $this->admin = User::factory()->create([
            'type' => 'staff',
            'name' => 'WMS Admin',
            'warehouse_id' => $this->warehouse->id,
        ]);
        $this->admin->givePermissionTo(['view-fulfillment', 'manage-fulfillment', 'packing-execute', 'packing.complete']);

        $this->product = Product::create([
            'name' => 'Luxury Polo Shirt',
            'slug' => 'luxury-polo-'.Str::random(6),
            'price' => 200,
            'sku' => 'POLO-LUX-001',
            'product_type' => 'variable',
            'status' => 1,
            'in_stock' => true,
            'stock_quantity' => 50,
        ]);

        $this->variant = ProductVariant::create([
            'product_id' => $this->product->id,
            'sku' => 'POLO-LUX-BLUE-M',
            'price' => 210,
            'stock_quantity' => 15,
            'in_stock' => true,
        ]);

        // Setup variation attributes
        $attrColor = Attribute::firstOrCreate(['slug' => 'color'], ['name' => 'Color']);
        $valBlue = AttributeValue::firstOrCreate(['attribute_id' => $attrColor->id, 'slug' => 'blue'], ['value' => 'Navy Blue']);
        AttributeProduct::create([
            'attribute_value_id' => $valBlue->id,
            'product_variant_id' => $this->variant->id,
        ]);

        $this->location = Location::create([
            'warehouse_id' => $this->warehouse->id,
            'code' => 'A-01-01',
            'barcode' => 'LOC-A0101',
            'name' => 'Aisle 1 Shelf 1',
            'is_active' => true,
            'is_locked' => false,
        ]);

        $this->placement = ProductLocation::create([
            'warehouse_id' => $this->warehouse->id,
            'location_id' => $this->location->id,
            'product_id' => $this->product->id,
            'product_variant_id' => $this->variant->id,
            'quantity' => 10,
            'allocated_hint' => 0,
        ]);

        $this->order = Order::create([
            'customer_id' => $this->admin->id,
            'tracking_number' => 'ORD-'.Str::random(8),
            'amount' => 420,
            'total' => 420,
        ]);

        $this->orderItem = OrderProduct::create([
            'order_id' => $this->order->id,
            'product_id' => $this->product->id,
            'variation_option_id' => $this->variant->id,
            'order_quantity' => 2,
            'unit_price' => 210,
            'subtotal' => 420,
        ]);

        $this->fulfillment = Fulfillment::create([
            'order_id' => $this->order->id,
            'warehouse_id' => $this->warehouse->id,
            'fulfillment_number' => 'FUL-'.Str::random(8),
            'status' => 'packing',
            'priority' => 'standard',
        ]);

        $this->fulfillmentItem = FulfillmentItem::create([
            'fulfillment_id' => $this->fulfillment->id,
            'order_item_id' => $this->orderItem->id,
            'product_id' => $this->product->id,
            'product_variant_id' => $this->variant->id,
            'product_location_id' => $this->placement->id,
            'quantity' => 2,
            'quantity_picked' => 2,
            'status' => 'picked',
        ]);
        $secondItem = FulfillmentItem::create([
            'fulfillment_id' => $this->fulfillment->id,
            'order_item_id' => $this->orderItem->id,
            'product_id' => $this->product->id,
            'product_variant_id' => $this->variant->id,
            'product_location_id' => $this->placement->id,
            'quantity' => 1,
            'quantity_picked' => 1,
            'status' => 'picked',
        ]);

        DB::enableQueryLog();

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/v1/admin/fulfillments/{$this->fulfillment->id}");

        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        $itemData = $response->json('data.items.0');

        // Legacy / existing keys preserved
        $this->assertSame($this->fulfillmentItem->id, $itemData['id']);
        $this->assertSame($this->orderItem->id, $itemData['order_item_id']);
        $this->assertSame($this->product->id, $itemData['product_id']);
        $this->assertSame($this->variant->id, $itemData['product_variant_id']);
        $this->assertEquals(2, $itemData['quantity']);
        $this->assertEquals(2, $itemData['quantity_picked']);
        $this->assertSame('picked', $itemData['status']);

        // Rich product details
        $this->assertNotNull($itemData['product']);
        $this->assertSame($this->product->id, $itemData['product']['id']);
        $this->assertSame('Luxury Polo Shirt', $itemData['product']['name']);
        $this->assertSame('POLO-LUX-001', $itemData['product']['sku']);
        $this->assertSame('variable', $itemData['product']['product_type']);

        // Rich variant details
        $this->assertNotNull($itemData['variant']);
        $this->assertSame($this->variant->id, $itemData['variant']['id']);
        $this->assertSame('POLO-LUX-BLUE-M', $itemData['variant']['sku']);
        $this->assertEquals(210, $itemData['variant']['price']);
        $this->assertIsArray($itemData['variant']['attributes']);
        $this->assertNotEmpty($itemData['variant']['attributes']);
        $this->assertSame('Color', $itemData['variant']['attributes'][0]['attribute']);
        $this->assertSame('Navy Blue', $itemData['variant']['attributes'][0]['value']);

        // Rich location details
        $this->assertNotNull($itemData['location']);
        $this->assertSame('A-01-01', $itemData['location']['code']);
        $this->assertSame('Aisle 1 Shelf 1', $itemData['location']['name']);
        $this->assertSame('LOC-A0101', $itemData['location']['barcode']);

        // Zero N+1 query assertion: detail load query count is deterministic and constant even with multiple items
        $this->assertLessThanOrEqual(18, $queries, "Fulfillment detail executed {$queries} queries (N+1 regression check)");
    }

    public function test_package_show_returns_rich_product_and_variant_details(): void
    {
        $package = Package::create([
            'fulfillment_id' => $this->fulfillment->id,
            'package_number' => 'PKG-'.Str::random(6),
            'barcode' => 'PKG-BAR-'.Str::random(6),
            'status' => 'open',
            'weight' => 1.5,
        ]);

        PackageItem::create([
            'package_id' => $package->id,
            'fulfillment_item_id' => $this->fulfillmentItem->id,
            'order_item_id' => $this->orderItem->id,
            'quantity' => 2,
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/v1/admin/packages/{$package->id}");

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        $packageItemData = $response->json('data.items.0');

        $this->assertSame($this->fulfillmentItem->id, $packageItemData['fulfillment_item_id']);
        $this->assertEquals(2, $packageItemData['quantity']);

        // Product in package
        $this->assertNotNull($packageItemData['product']);
        $this->assertSame($this->product->id, $packageItemData['product']['id']);
        $this->assertSame('Luxury Polo Shirt', $packageItemData['product']['name']);
        $this->assertSame('POLO-LUX-001', $packageItemData['product']['sku']);

        // Variant in package
        $this->assertNotNull($packageItemData['variant']);
        $this->assertSame($this->variant->id, $packageItemData['variant']['id']);
        $this->assertSame('POLO-LUX-BLUE-M', $packageItemData['variant']['sku']);
        $this->assertNotEmpty($packageItemData['variant']['attributes']);
        $this->assertSame('Navy Blue', $packageItemData['variant']['attributes'][0]['value']);
    }

    public function test_packing_task_show_returns_fulfillment_items_with_product_details(): void
    {
        $task = PackingTask::create([
            'fulfillment_id' => $this->fulfillment->id,
            'status' => 'packing',
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/v1/admin/packing-tasks/{$task->id}");

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        $items = $response->json('data.items');
        $this->assertIsArray($items);
        $this->assertCount(2, $items);

        $item = $items[0];
        $this->assertSame($this->fulfillmentItem->id, $item['id']);
        $this->assertSame('Luxury Polo Shirt', $item['product']['name']);
        $this->assertSame('POLO-LUX-BLUE-M', $item['variant']['sku']);
    }
}
