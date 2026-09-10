<?php

declare(strict_types=1);

namespace Tests\Feature\FlashSales;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\User;
use Marvel\Enums\Permission as PermissionEnum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class FlashSaleApproveRequestTest extends TestCase
{
    use RefreshDatabase;

    private const GUARD = 'api';
    private const PREFIX = '/api/v1';

    /** @test */
    public function unauthenticated_user_cannot_approve(): void
    {
        $response = $this->postJson(self::PREFIX . '/approve-flash-sale-requested-products', [
            'id' => 1]);

        $response->assertStatus(401);
    }

    /** @test */
    public function unauthenticated_user_cannot_disapprove(): void
    {
        $response = $this->postJson(self::PREFIX . '/disapprove-flash-sale-requested-products', [
            'id' => 1]);

        $response->assertStatus(401);
    }

    private function createSuperAdminPermission(): void
    {
        Permission::findOrCreate(self::GUARD);
    }

    /** @test */
    public function unauthorized_user_gets_403_on_approve(): void
    {
        $this->createSuperAdminPermission();

        $user = User::create([
            'name' => 'Customer',
            'email' => 'customer@example.com',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
            'is_active' => true]);

        Sanctum::actingAs($user);

        $response = $this->postJson(self::PREFIX . '/approve-flash-sale-requested-products', [
            'id' => 1]);

        $response->assertStatus(403);
    }

    /** @test */
    public function unauthorized_user_gets_403_on_disapprove(): void
    {
        $this->createSuperAdminPermission();

        $user = User::create([
            'name' => 'Customer',
            'email' => 'customer2@example.com',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
            'is_active' => true]);

        Sanctum::actingAs($user);

        $response = $this->postJson(self::PREFIX . '/disapprove-flash-sale-requested-products', [
            'id' => 1]);

        $response->assertStatus(403);
    }
}
