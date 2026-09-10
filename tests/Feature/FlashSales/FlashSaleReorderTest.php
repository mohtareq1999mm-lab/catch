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

class FlashSaleReorderTest extends TestCase
{
    use RefreshDatabase;

    private const GUARD = 'api';
    private const PREFIX = '/api/v1';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');

        $this->user = $this->createSuperAdmin();
        Sanctum::actingAs($this->user);
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
            'email' => 'admin@example.com',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
            'is_active' => true]);

        $user->assignRole($role);

        return $user;
    }

    /** @test */
    public function can_reorder_flash_sales(): void
    {
        $sale1 = FlashSale::create(['title' => ['en' => 'First'], 'slug' => 'first', 'type' => 'percentage', 'discount' => 10, 'end_date' => now()->addDay(), 'status' => true]);
        $sale2 = FlashSale::create(['title' => ['en' => 'Second'], 'slug' => 'second', 'type' => 'percentage', 'discount' => 20, 'end_date' => now()->addDay(), 'status' => true]);
        $sale3 = FlashSale::create(['title' => ['en' => 'Third'], 'slug' => 'third', 'type' => 'percentage', 'discount' => 30, 'end_date' => now()->addDay(), 'status' => true]);

        $response = $this->putJson(self::PREFIX . '/flash-sale/reorder', [
            'flash_sales' => [$sale3->id, $sale1->id, $sale2->id]]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
    }

    /** @test */
    public function reorder_updates_order_column(): void
    {
        $sale1 = FlashSale::create(['title' => ['en' => 'A'], 'slug' => 'a', 'type' => 'percentage', 'discount' => 10, 'end_date' => now()->addDay(), 'status' => true]);
        $sale2 = FlashSale::create(['title' => ['en' => 'B'], 'slug' => 'b', 'type' => 'percentage', 'discount' => 20, 'end_date' => now()->addDay(), 'status' => true]);

        $this->putJson(self::PREFIX . '/flash-sale/reorder', [
            'flash_sales' => [$sale2->id, $sale1->id]]);

        $sale1->refresh();
        $sale2->refresh();

        $this->assertLessThan($sale1->order, $sale2->order);
    }

    /** @test */
    public function reorder_validates_flash_sales_required(): void
    {
        $response = $this->putJson(self::PREFIX . '/flash-sale/reorder', [
            'flash_sales' => []]);

        $response->assertStatus(422);
    }
}
