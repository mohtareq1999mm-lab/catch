<?php

declare(strict_types=1);

namespace Tests\Feature\FlashSales;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\FlashSale;
use Marvel\Database\Models\User;
use Marvel\Enums\Permission as PermissionEnum;
use Marvel\Enums\Role as RoleEnum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FlashSaleRegressionTest extends TestCase
{
    use RefreshDatabase;

    private const GUARD = 'api';
    private const PREFIX = '/api/v1';

    private User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');

        $this->adminUser = $this->createSuperAdmin();
    }

    /** @test show returns 404 for non-existent id */
    public function test_show_returns_404_for_nonexistent_id(): void
    {
        Sanctum::actingAs($this->adminUser);

        $response = $this->getJson(self::PREFIX . '/flash-sale/99999');

        $response->assertNotFound();
    }

    /** @test show returns 404 for non-existent slug */
    public function test_show_returns_404_for_nonexistent_slug(): void
    {
        Sanctum::actingAs($this->adminUser);

        $response = $this->getJson(self::PREFIX . '/flash-sale/nonexistent-slug');

        $response->assertNotFound();
    }

    /** @test show finds flash sale by slug */
    public function test_show_finds_by_slug(): void
    {
        Sanctum::actingAs($this->adminUser);

        $flashSale = FlashSale::create([
            'title' => ['en' => 'Summer Sale'],
            'slug' => 'summer-sale',
            'type' => 'percentage',
            'discount' => 20,
            'end_date' => now()->addDays(10),
            'status' => true]);

        $response = $this->getJson(self::PREFIX . '/flash-sale/summer-sale');

        $response->assertOk();
        $response->assertJsonPath('data.id', $flashSale->id);
    }

    /** @test destroy returns 404 for non-existent flash sale */
    public function test_destroy_returns_404_for_nonexistent(): void
    {
        Sanctum::actingAs($this->adminUser);

        $response = $this->deleteJson(self::PREFIX . '/flash-sale/99999');

        $response->assertNotFound();
    }

    /** @test show returns flash sale with products loaded */
    public function test_show_returns_flash_sale_with_products(): void
    {
        Sanctum::actingAs($this->adminUser);

        $flashSale = FlashSale::create([
            'title' => ['en' => 'Flash Sale'],
            'slug' => 'flash-sale',
            'type' => 'percentage',
            'discount' => 15,
            'end_date' => now()->addDays(5),
            'status' => true]);

        $response = $this->getJson(self::PREFIX . '/flash-sale/' . $flashSale->id);

        $response->assertOk();
        $response->assertJsonStructure([
            'status', 'message', 'success', 'data' => [
                'id', 'title', 'slug', 'image', 'description',
                'start_date', 'end_date', 'status', 'is_valid',
                'type', 'discount', 'max_discount_amount', 'created_at']]);
    }

    private function createSuperAdmin(): User
    {
        Permission::findOrCreate(self::GUARD);
        Permission::findOrCreate(PermissionEnum::VIEW_FlASH_SALE, self::GUARD);
        Permission::findOrCreate(PermissionEnum::CREATE_FlASH_SALE, self::GUARD);
        Permission::findOrCreate(PermissionEnum::UPDATE_FlASH_SALE, self::GUARD);
        Permission::findOrCreate(PermissionEnum::DELETE_FlASH_SALE, self::GUARD);

        $role = Role::create([
            'name' => RoleEnum::SUPER_ADMIN,
            'guard_name' => self::GUARD,
            'display_name' => json_encode(['en' => 'Super Admin'])]);

        $role->givePermissionTo([
            PermissionEnum::VIEW_FlASH_SALE,
            PermissionEnum::CREATE_FlASH_SALE,
            PermissionEnum::UPDATE_FlASH_SALE,
            PermissionEnum::DELETE_FlASH_SALE]);

        $user = User::create([
            'name' => 'Super Admin',
            'email' => 'admin.flashsale@example.com',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
            'is_active' => true]);

        $user->assignRole($role);

        return $user;
    }
}
