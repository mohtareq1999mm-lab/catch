<?php

namespace Tests\Feature\CouponAssignment;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Coupon;
use Marvel\Database\Models\User;
use Marvel\Enums\Permission as PermissionEnum;
use Marvel\Enums\Role as RoleEnum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CouponAssignmentValidationTest extends TestCase
{
    use RefreshDatabase;

    private const PREFIX = '/api/v1';
    private const GUARD = 'api';

    private User $admin;
    private User $customer;
    private Coupon $coupon;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');

        $this->customer = User::factory()->create(['type' => 'user']);
        $this->admin = $this->createAdminUser();
        $this->coupon = $this->createCoupon('VALIDCPN');
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function createAdminUser(): User
    {
        $permissions = [
            PermissionEnum::VIEW_COUPON_ASSIGNMENTS,
            PermissionEnum::CREATE_COUPON_ASSIGNMENT,
            PermissionEnum::UPDATE_COUPON_ASSIGNMENT,
            PermissionEnum::DELETE_COUPON_ASSIGNMENT];

        foreach ($permissions as $perm) {
            Permission::findOrCreate($perm, self::GUARD);
        }

        $role = Role::create([
            'name' => RoleEnum::SUPER_ADMIN,
            'display_name' => json_encode(['en' => 'Super Admin', 'ar' => 'مدير النظام']),
            'guard_name' => self::GUARD]);

        foreach ($permissions as $perm) {
            $role->givePermissionTo($perm);
        }

        $user = User::factory()->create(['type' => 'admin']);

        $user->assignRole($role);

        foreach ($permissions as $perm) {
            $user->givePermissionTo($perm);
        }

        return $user;
    }

    private function createCoupon(string $code, array $overrides = []): Coupon
    {
        $coupon = Coupon::create(array_merge([
            'name' => 'Test Coupon',
            'slug' => 'coupon-' . Str::random(6),
            'discount_type' => 'percentage',
            'discount' => 10,
            'status' => true,
            'start_date' => now()->subDay(),
            'end_date' => now()->addMonth()], $overrides));

        $coupon->update(['code' => $code]);

        return $coupon->fresh();
    }

    private function authAdmin(): void
    {
        Sanctum::actingAs($this->admin);
    }

    private function storeUrl(): string
    {
        return self::PREFIX . '/coupons/' . $this->coupon->id . '/assignments';
    }

    private function updateUrl(int $assignmentId): string
    {
        return self::PREFIX . '/coupons/' . $this->coupon->id . '/assignments/' . $assignmentId;
    }

    // =========================================================================
    // Store Validation - user_id
    // =========================================================================

    /** @test */
    public function store_requires_user_id(): void
    {
        $this->authAdmin();

        $response = $this->postJson($this->storeUrl(), [
            'max_uses' => 1]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrorFor('user_id');
    }

    /** @test */
    public function store_user_id_must_exist(): void
    {
        $this->authAdmin();

        $response = $this->postJson($this->storeUrl(), [
            'user_id' => 99999,
            'max_uses' => 1]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrorFor('user_id');
    }

    // =========================================================================
    // Store Validation - max_uses
    // =========================================================================

    /** @test */
    public function store_requires_max_uses(): void
    {
        $this->authAdmin();

        $response = $this->postJson($this->storeUrl(), [
            'user_id' => $this->customer->id]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrorFor('max_uses');
    }

    /** @test */
    public function store_max_uses_must_be_integer(): void
    {
        $this->authAdmin();

        $response = $this->postJson($this->storeUrl(), [
            'user_id' => $this->customer->id,
            'max_uses' => 'abc']);

        $response->assertStatus(422);
        $response->assertJsonValidationErrorFor('max_uses');
    }

    /** @test */
    public function store_max_uses_must_be_at_least_one(): void
    {
        $this->authAdmin();

        $response = $this->postJson($this->storeUrl(), [
            'user_id' => $this->customer->id,
            'max_uses' => 0]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrorFor('max_uses');
    }

    // =========================================================================
    // Store Validation - expires_at
    // =========================================================================

    /** @test */
    public function store_expires_at_must_be_valid_date(): void
    {
        $this->authAdmin();

        $response = $this->postJson($this->storeUrl(), [
            'user_id' => $this->customer->id,
            'max_uses' => 1,
            'expires_at' => 'not-a-date']);

        $response->assertStatus(422);
        $response->assertJsonValidationErrorFor('expires_at');
    }

    /** @test */
    public function store_expires_at_must_be_in_the_future(): void
    {
        $this->authAdmin();

        $response = $this->postJson($this->storeUrl(), [
            'user_id' => $this->customer->id,
            'max_uses' => 1,
            'expires_at' => now()->subDay()->toISOString()]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrorFor('expires_at');
    }

    // =========================================================================
    // Update Validation - max_uses
    // =========================================================================

    /** @test */
    public function update_max_uses_must_be_integer(): void
    {
        $this->authAdmin();
        $assignment = \Marvel\Database\Models\CouponAssignment::create([
            'coupon_id' => $this->coupon->id,
            'user_id' => $this->customer->id,
            'max_uses' => 5,
            'used' => 0,
            'assigned_at' => now()]);

        $response = $this->putJson($this->updateUrl($assignment->id), [
            'max_uses' => 'abc']);

        $response->assertStatus(422);
        $response->assertJsonValidationErrorFor('max_uses');
    }

    /** @test */
    public function update_max_uses_must_be_at_least_one(): void
    {
        $this->authAdmin();
        $assignment = \Marvel\Database\Models\CouponAssignment::create([
            'coupon_id' => $this->coupon->id,
            'user_id' => $this->customer->id,
            'max_uses' => 5,
            'used' => 0,
            'assigned_at' => now()]);

        $response = $this->putJson($this->updateUrl($assignment->id), [
            'max_uses' => 0]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrorFor('max_uses');
    }

    /** @test */
    public function update_max_uses_is_optional(): void
    {
        $this->authAdmin();
        $assignment = \Marvel\Database\Models\CouponAssignment::create([
            'coupon_id' => $this->coupon->id,
            'user_id' => $this->customer->id,
            'max_uses' => 5,
            'used' => 0,
            'assigned_at' => now()]);

        $response = $this->putJson($this->updateUrl($assignment->id), []);

        $response->assertOk();
        $response->assertJsonPath('data.max_uses', 5);
    }

    // =========================================================================
    // Update Validation - expires_at
    // =========================================================================

    /** @test */
    public function update_expires_at_must_be_valid_date(): void
    {
        $this->authAdmin();
        $assignment = \Marvel\Database\Models\CouponAssignment::create([
            'coupon_id' => $this->coupon->id,
            'user_id' => $this->customer->id,
            'max_uses' => 5,
            'used' => 0,
            'assigned_at' => now()]);

        $response = $this->putJson($this->updateUrl($assignment->id), [
            'expires_at' => 'not-a-date']);

        $response->assertStatus(422);
        $response->assertJsonValidationErrorFor('expires_at');
    }

    /** @test */
    public function update_expires_at_must_be_in_the_future(): void
    {
        $this->authAdmin();
        $assignment = \Marvel\Database\Models\CouponAssignment::create([
            'coupon_id' => $this->coupon->id,
            'user_id' => $this->customer->id,
            'max_uses' => 5,
            'used' => 0,
            'assigned_at' => now()]);

        $response = $this->putJson($this->updateUrl($assignment->id), [
            'expires_at' => now()->subDay()->toISOString()]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrorFor('expires_at');
    }

    /** @test */
    public function update_expires_at_null_clears_expiry(): void
    {
        $this->authAdmin();
        $assignment = \Marvel\Database\Models\CouponAssignment::create([
            'coupon_id' => $this->coupon->id,
            'user_id' => $this->customer->id,
            'max_uses' => 5,
            'used' => 0,
            'assigned_at' => now(),
            'expires_at' => now()->addWeek()]);

        $response = $this->putJson($this->updateUrl($assignment->id), [
            'expires_at' => null]);

        $response->assertOk();
        $this->assertNull($response->json('data.expires_at'));
    }

    // =========================================================================
    // Edge Cases
    // =========================================================================

    /** @test */
    public function store_with_all_fields_valid_succeeds(): void
    {
        $this->authAdmin();

        $response = $this->postJson($this->storeUrl(), [
            'user_id' => $this->customer->id,
            'max_uses' => 10,
            'expires_at' => now()->addMonth()->toISOString()]);

        $response->assertStatus(201);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.max_uses', 10);
    }
}
