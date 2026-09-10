<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Promotion;
use Marvel\Database\Models\User;
use Marvel\Enums\PromotionMountType;
use Marvel\Enums\PromotionType;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PromotionCrudTest extends TestCase
{
    use RefreshDatabase;

    private const PREFIX = '/api/v1';

    private function makeAdmin(): User
    {
        foreach (['view-promotion', 'create-promotion', 'update-promotion', 'delete-promotion'] as $perm) {
            \Spatie\Permission\Models\Permission::create(['name' => $perm, 'guard_name' => 'api']);
        }

        $role = Role::create([
            'name' => 'super_admin',
            'guard_name' => 'api',
            'display_name' => json_encode(['en' => 'Super Admin'])]);
        $role->givePermissionTo([
            'view-promotion',
            'create-promotion',
            'update-promotion',
            'delete-promotion']);

        $user = User::factory()->create();
        $user->assignRole('super_admin');

        return $user;
    }

    private function createPromotion(array $overrides = []): Promotion
    {
        return Promotion::create(array_merge([
            'name' => 'Test Promo',
            'code' => 'TST-' . Str::upper(Str::random(6)),
            'type' => PromotionType::PRICE,
            'type_amount' => PromotionMountType::FIXED_RATE,
            'value' => 10,
            'discount' => 10,
            'minimum_order_amount' => 0,
            'apply_to' => 'all_products',
            'status' => true], $overrides));
    }

    public function test_admin_can_list_promotions(): void
    {
        $admin = $this->makeAdmin();
        Sanctum::actingAs($admin);

        $this->createPromotion(['name' => 'Promo A', 'code' => 'A-' . Str::upper(Str::random(6))]);
        $this->createPromotion(['name' => 'Promo B', 'code' => 'B-' . Str::upper(Str::random(6))]);

        $response = $this->getJson(self::PREFIX . '/promotions');

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => [
                'data' => [
                    '*' => ['id', 'name', 'code', 'type', 'discount_type', 'status']]]]);
    }

    public function test_admin_can_show_promotion(): void
    {
        $admin = $this->makeAdmin();
        Sanctum::actingAs($admin);

        $promotion = $this->createPromotion();

        $response = $this->getJson(self::PREFIX . '/promotions/' . $promotion->id);

        $response->assertOk();
        $response->assertJsonPath('data.id', $promotion->id);
    }

    public function test_admin_can_update_promotion(): void
    {
        $admin = $this->makeAdmin();
        Sanctum::actingAs($admin);

        $promotion = $this->createPromotion(['name' => 'Original Name']);

        $payload = [
            'name' => ['en' => 'Updated Name'],
            'value' => 25,
            'discount' => 25];

        $response = $this->putJson(self::PREFIX . '/promotions/' . $promotion->id, $payload);

        $response->assertOk();
        $promotion->refresh();
        $this->assertEquals(25, $promotion->value);
    }

    public function test_admin_can_delete_promotion(): void
    {
        $admin = $this->makeAdmin();
        Sanctum::actingAs($admin);

        $promotion = $this->createPromotion();

        $response = $this->deleteJson(self::PREFIX . '/promotions/' . $promotion->id);

        $response->assertOk();
        $this->assertDatabaseMissing('promotions', ['id' => $promotion->id]);
    }

    public function test_create_validation_fails_without_required_fields(): void
    {
        $admin = $this->makeAdmin();
        Sanctum::actingAs($admin);

        $response = $this->postJson(self::PREFIX . '/promotions', []);

        $response->assertStatus(422);
    }

    public function test_update_validation_fails_with_invalid_type(): void
    {
        $admin = $this->makeAdmin();
        Sanctum::actingAs($admin);

        $promotion = $this->createPromotion();

        $response = $this->putJson(self::PREFIX . '/promotions/' . $promotion->id, [
            'type' => 'invalid_type',
            'type_amount' => 'invalid_amount']);

        $response->assertStatus(422);
    }

    public function test_unauthenticated_user_cannot_create_promotion(): void
    {
        $response = $this->postJson(self::PREFIX . '/promotions', [
            'name' => ['en' => 'Hacked Promo'],
            'code' => 'HACKED']);

        $response->assertStatus(401);
    }
}
