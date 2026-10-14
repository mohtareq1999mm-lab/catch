<?php

namespace Tests\Feature\Wms;

use App\Models\Fulfillment\Location;
use App\Models\Fulfillment\Warehouse;
use App\Models\Inventory\InventoryOperation;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\ProductVariant;
use Marvel\Database\Models\User;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class WmsInventoryOperationsListTest extends TestCase
{
    use DatabaseTransactions;

    private Warehouse $warehouse;

    private Warehouse $otherWarehouse;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (['view-location', 'manage-warehouse', 'inventory-adjust'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'api']);
        }

        $this->warehouse = Warehouse::create([
            'code' => 'WH-LIST-'.Str::random(4),
            'name' => 'Main Warehouse',
            'status' => 'active',
            'is_default' => true,
        ]);

        $this->otherWarehouse = Warehouse::create([
            'code' => 'WH-LIST-'.Str::random(4),
            'name' => 'Other Warehouse',
            'status' => 'active',
            'is_default' => false,
        ]);
    }

    private function manager(): User
    {
        $u = User::factory()->create(['type' => 'staff', 'warehouse_id' => $this->warehouse->id]);
        $u->givePermissionTo(['view-location', 'manage-warehouse', 'inventory-adjust']);

        return $u;
    }

    private function scopedUser(): User
    {
        $u = User::factory()->create(['type' => 'staff', 'warehouse_id' => $this->warehouse->id]);
        $u->givePermissionTo(['view-location']);

        return $u;
    }

    private function simpleProduct(): Product
    {
        return Product::create([
            'name' => 'Product '.Str::random(6),
            'slug' => 'prod-'.Str::random(8),
            'price' => 50,
            'product_type' => 'simple',
            'status' => 1,
            'in_stock' => true,
            'stock_quantity' => 10,
        ]);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/v1/admin/inventory/operations')
            ->assertStatus(401);
    }

    public function test_forbidden_without_view_location_permission(): void
    {
        $user = User::factory()->create(['type' => 'staff', 'warehouse_id' => $this->warehouse->id]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/admin/inventory/operations')
            ->assertStatus(403);
    }

    public function test_manager_can_list_and_paginate_operations(): void
    {
        $prod = $this->simpleProduct();

        for ($i = 0; $i < 3; $i++) {
            InventoryOperation::create([
                'idempotency_key' => 'op-test-'.Str::random(12),
                'operation' => InventoryOperation::OPERATION_RECEIPT,
                'product_id' => $prod->id,
                'warehouse_id' => $this->warehouse->id,
                'quantity' => 10 + $i,
                'status' => InventoryOperation::STATUS_COMPLETED,
            ]);
        }

        $res = $this->actingAs($this->manager(), 'sanctum')
            ->getJson('/api/v1/admin/inventory/operations?limit=2')
            ->assertStatus(200);

        $res->assertJsonStructure([
            'success',
            'message',
            'data' => [
                '*' => [
                    'id',
                    'key',
                    'operation',
                    'status',
                    'product_id',
                    'warehouse_id',
                    'quantity',
                    'created_at',
                ],
            ],
        ]);

        $this->assertCount(2, $res->json('data'));
    }

    public function test_filter_by_operation_and_status(): void
    {
        $prod = $this->simpleProduct();

        $receiptKey = 'op-rcpt-'.Str::random(10);
        InventoryOperation::create([
            'idempotency_key' => $receiptKey,
            'operation' => InventoryOperation::OPERATION_RECEIPT,
            'product_id' => $prod->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 5,
            'status' => InventoryOperation::STATUS_COMPLETED,
        ]);

        $transferKey = 'op-trf-'.Str::random(10);
        InventoryOperation::create([
            'idempotency_key' => $transferKey,
            'operation' => InventoryOperation::OPERATION_TRANSFER,
            'product_id' => $prod->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 3,
            'status' => InventoryOperation::STATUS_PROCESSING,
        ]);

        $res = $this->actingAs($this->manager(), 'sanctum')
            ->getJson("/api/v1/admin/inventory/operations?operation=receipt&status=completed")
            ->assertStatus(200);

        $keys = collect($res->json('data'))->pluck('key');
        $this->assertTrue($keys->contains($receiptKey));
        $this->assertFalse($keys->contains($transferKey));
    }

    public function test_scoped_user_cannot_view_foreign_warehouse_operations(): void
    {
        $prod = $this->simpleProduct();

        $mainKey = 'op-main-'.Str::random(10);
        InventoryOperation::create([
            'idempotency_key' => $mainKey,
            'operation' => InventoryOperation::OPERATION_RECEIPT,
            'product_id' => $prod->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 5,
            'status' => InventoryOperation::STATUS_COMPLETED,
        ]);

        $foreignKey = 'op-foreign-'.Str::random(10);
        InventoryOperation::create([
            'idempotency_key' => $foreignKey,
            'operation' => InventoryOperation::OPERATION_RECEIPT,
            'product_id' => $prod->id,
            'warehouse_id' => $this->otherWarehouse->id,
            'quantity' => 10,
            'status' => InventoryOperation::STATUS_COMPLETED,
        ]);

        // Scoped user only sees operations for their assigned warehouse
        $res = $this->actingAs($this->scopedUser(), 'sanctum')
            ->getJson('/api/v1/admin/inventory/operations')
            ->assertStatus(200);

        $keys = collect($res->json('data'))->pluck('key');
        $this->assertTrue($keys->contains($mainKey));
        $this->assertFalse($keys->contains($foreignKey));

        // Attempting to filter by another warehouse yields 404 anti-enumeration
        $this->actingAs($this->scopedUser(), 'sanctum')
            ->getJson('/api/v1/admin/inventory/operations?warehouse_id='.$this->otherWarehouse->id)
            ->assertStatus(404);
    }

    public function test_operation_status_returns_rich_product_and_variant_details(): void
    {
        $prod = Product::create([
            'name' => 'T-Shirt Master',
            'slug' => 't-shirt-master-'.Str::random(6),
            'price' => 120,
            'sku' => 'TSHIRT-MASTER-SKU',
            'product_type' => 'variable',
            'status' => 1,
            'in_stock' => true,
            'stock_quantity' => 50,
        ]);

        $variant = ProductVariant::create([
            'product_id' => $prod->id,
            'sku' => 'TSHIRT-RED-XL',
            'price' => 130,
            'stock_quantity' => 20,
            'in_stock' => true,
        ]);

        $key = 'op-rich-'.Str::random(10);
        InventoryOperation::create([
            'idempotency_key' => $key,
            'operation' => InventoryOperation::OPERATION_RECEIPT,
            'product_id' => $prod->id,
            'product_variant_id' => $variant->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 15,
            'status' => InventoryOperation::STATUS_COMPLETED,
            'result' => ['received_quantity' => 15],
        ]);

        $res = $this->actingAs($this->manager(), 'sanctum')
            ->getJson("/api/v1/admin/inventory/operations/{$key}")
            ->assertStatus(200);

        // Standard idempotency keys preserved
        $this->assertSame($key, $res->json('data.key'));
        $this->assertSame('receipt', $res->json('data.operation'));
        $this->assertSame('completed', $res->json('data.status'));
        $this->assertSame($prod->id, $res->json('data.product_id'));
        $this->assertSame($variant->id, $res->json('data.product_variant_id'));

        // Rich product details
        $this->assertSame($prod->id, $res->json('data.product.id'));
        $this->assertSame('T-Shirt Master', $res->json('data.product.name'));
        $this->assertSame('TSHIRT-MASTER-SKU', $res->json('data.product.sku'));
        $this->assertSame('variable', $res->json('data.product.product_type'));

        // Rich variant details
        $this->assertSame($variant->id, $res->json('data.variant.id'));
        $this->assertSame('TSHIRT-RED-XL', $res->json('data.variant.sku'));
        $this->assertEquals(130, (float) $res->json('data.variant.price'));
        $this->assertEquals(20, (int) $res->json('data.variant.stock_quantity'));
        $this->assertIsArray($res->json('data.variant.attributes'));
    }
}
