<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Import;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\User;
use Marvel\Enums\Permission as PermissionEnum;
use Marvel\Enums\Role as RoleEnum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProductExportTest extends TestCase
{
    use RefreshDatabase;

    private const GUARD = 'api';
    private const PREFIX = '/api/v1';

    private function createSuperAdminUser(): User
    {
        $permissions = [
            PermissionEnum::EXPORT_PRODUCT,
            PermissionEnum::VIEW_PRODUCTS];

        foreach ($permissions as $perm) {
            Permission::findOrCreate($perm, self::GUARD);
        }

        $role = Role::create([
            'name' => RoleEnum::SUPER_ADMIN,
            'guard_name' => self::GUARD,
            'display_name' => json_encode(['en' => 'Super Admin', 'ar' => 'مدير النظام'])]);

        foreach ($permissions as $perm) {
            $role->givePermissionTo($perm);
        }

        $user = User::create([
            'name' => 'Super Admin',
            'email' => 'superadmin@example.com',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
            'is_active' => true,
            'type' => 'admin',
            'phone_number' => '+1-555-0100']);

        $user->assignRole($role);

        foreach ($permissions as $perm) {
            $user->givePermissionTo($perm);
        }

        return $user;
    }

    public function test_unauthenticated_user_cannot_export(): void
    {
        $response = $this->getJson(self::PREFIX . '/products/export');

        $response->assertUnauthorized();
    }

    public function test_export_returns_excel_file(): void
    {
        Queue::fake();
        Storage::fake('imports');
        $user = $this->createSuperAdminUser();
        Sanctum::actingAs($user);

        Product::create([
            'name' => ['en' => 'Test Product'],
            'slug' => 'test-product',
            'price' => 99.99,
            'status' => 1,
            'in_stock' => 1,
            'product_type' => 'simple',
            'stock_quantity' => 10,
            'quantity' => 10,
            'sku' => 'TEST-001']);

        $response = $this->getJson(self::PREFIX . '/products/export');

        $response->assertStatus(202);
        $response->assertJsonPath('success', true);
        $response->assertJsonStructure(['data' => ['export_id', 'status']]);
        $exportId = $response->json('data.export_id');
        $this->assertDatabaseHas('imports', ['id' => $exportId]);
        Queue::assertPushed(\Marvel\Jobs\ExportProductsJob::class);
        // Process job manually (since queue faked)
        (new \Marvel\Jobs\ExportProductsJob($exportId))->handle();
        $import = Import::find($exportId);
        $this->assertEquals('completed', $import->status);
        $this->assertTrue(Storage::disk('imports')->exists($import->file_path));

        // Download
        $download = $this->getJson(self::PREFIX . "/products/export/{$exportId}/download");
        // Download returns binary, but via JSON we check status endpoint
        $status = $this->getJson(self::PREFIX . "/products/export/{$exportId}");
        $status->assertOk();
        $status->assertJsonPath('data.status', 'completed');
    }

    public function test_export_with_filters(): void
    {
        Storage::fake('imports');
        $user = $this->createSuperAdminUser();
        Sanctum::actingAs($user);

        Product::create([
            'name' => ['en' => 'Product A'],
            'slug' => 'product-a',
            'price' => 100.00,
            'status' => 1,
            'in_stock' => 1,
            'product_type' => 'simple',
            'stock_quantity' => 5,
            'quantity' => 5,
            'sku' => 'SKU-A']);

        Product::create([
            'name' => ['en' => 'Product B'],
            'slug' => 'product-b',
            'price' => 200.00,
            'status' => 0,
            'in_stock' => 0,
            'product_type' => 'simple',
            'stock_quantity' => 0,
            'quantity' => 0,
            'sku' => 'SKU-B']);

        $response = $this->getJson(self::PREFIX . '/products/export?status=1');

        $response->assertStatus(202);
        $exportId = $response->json('data.export_id');
        (new \Marvel\Jobs\ExportProductsJob($exportId, ['status' => 1]))->handle();
        $import = Import::find($exportId);
        $this->assertEquals('completed', $import->status);
    }

    public function test_export_validates_invalid_product_type(): void
    {
        $user = $this->createSuperAdminUser();
        Sanctum::actingAs($user);

        $response = $this->getJson(self::PREFIX . '/products/export?product_type=invalid');

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['product_type']);
    }

    public function test_export_post_also_works(): void
    {
        Storage::fake('imports');
        $user = $this->createSuperAdminUser();
        Sanctum::actingAs($user);

        $response = $this->postJson(self::PREFIX . '/products/export', ['status' => 1]);
        $response->assertStatus(202);
        $response->assertJsonPath('success', true);
    }

    public function test_export_status_forbidden_for_other_user(): void
    {
        Storage::fake('imports');
        $owner = $this->createSuperAdminUser();
        Sanctum::actingAs($owner);
        $resp = $this->getJson(self::PREFIX . '/products/export');
        $exportId = $resp->json('data.export_id');

        // Other user without super admin
        $other = User::create([
            'name' => 'Other',
            'email' => 'other-' . uniqid() . '@test.local',
            'password' => Hash::make('password'),
            'is_active' => true,
            'type' => 'admin']);
        $perm = Permission::findOrCreate(PermissionEnum::EXPORT_PRODUCT, self::GUARD);
        $role = Role::create(['name' => 'r' . uniqid(), 'guard_name' => self::GUARD, 'display_name' => 'r']);
        $role->givePermissionTo($perm);
        $other->assignRole($role);
        $other->givePermissionTo($perm);
        Sanctum::actingAs($other);
        $resp2 = $this->getJson(self::PREFIX . "/products/export/{$exportId}");
        $this->assertEquals(404, $resp2->getStatusCode(), 'Other user must get 404 for export status');
    }
}
