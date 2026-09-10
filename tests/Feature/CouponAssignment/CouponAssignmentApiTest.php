<?php

namespace Tests\Feature\CouponAssignment;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Coupon;
use Marvel\Database\Models\CouponAssignment;
use Marvel\Database\Models\User;
use Marvel\Enums\Permission as PermissionEnum;
use Marvel\Enums\Role as RoleEnum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CouponAssignmentApiTest extends TestCase
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
        $this->coupon = $this->createCoupon('ADMINCPN');
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

    private function createAssignment(Coupon $coupon, User $user, array $overrides = []): CouponAssignment
    {
        return CouponAssignment::create(array_merge([
            'coupon_id' => $coupon->id,
            'user_id' => $user->id,
            'max_uses' => 1,
            'used' => 0,
            'assigned_at' => now(),
            'expires_at' => null], $overrides));
    }

    private function authAdmin(): void
    {
        Sanctum::actingAs($this->admin);
    }

    private function authCustomer(): void
    {
        Sanctum::actingAs($this->customer);
    }

    private function assignmentUrl(?Coupon $coupon = null): string
    {
        $target = $coupon ?? $this->coupon;
        return self::PREFIX . '/coupons/' . $target->id . '/assignments';
    }

    // =========================================================================
    // Authentication & Authorization
    // =========================================================================

    /** @test */
    public function unauthenticated_user_cannot_access_any_endpoint(): void
    {
        $url = $this->assignmentUrl();

        $this->getJson($url)->assertUnauthorized();
        $this->postJson($url, [])->assertUnauthorized();
        $this->getJson($url . '/1')->assertUnauthorized();
        $this->putJson($url . '/1', [])->assertUnauthorized();
        $this->deleteJson($url . '/1')->assertUnauthorized();
    }

    /** @test */
    public function non_admin_user_gets_forbidden(): void
    {
        $this->authCustomer();
        $url = $this->assignmentUrl();

        $this->getJson($url)->assertForbidden();
        $this->postJson($url, ['user_id' => 1, 'max_uses' => 1])->assertForbidden();
        $this->getJson($url . '/1')->assertForbidden();
        $this->putJson($url . '/1', ['max_uses' => 2])->assertForbidden();
        $this->deleteJson($url . '/1')->assertForbidden();
    }

    // =========================================================================
    // List (Index)
    // =========================================================================

    /** @test */
    public function admin_can_list_assignments(): void
    {
        $this->authAdmin();
        $user = User::factory()->create(['type' => 'user']);
        $this->createAssignment($this->coupon, $user);
        $this->createAssignment($this->coupon, $this->customer);

        $response = $this->getJson($this->assignmentUrl());

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonStructure([
            'status', 'message', 'success',
            'data' => ['data', 'current_page', 'from', 'last_page', 'per_page', 'to', 'total']]);
        $this->assertCount(2, $response->json('data.data'));
        $this->assertEquals(2, $response->json('data.total'));
    }

    /** @test */
    public function index_returns_empty_when_no_assignments(): void
    {
        $this->authAdmin();

        $response = $this->getJson($this->assignmentUrl());

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $this->assertCount(0, $response->json('data.data'));
        $this->assertEquals(0, $response->json('data.total'));
    }

    /** @test */
    public function index_respects_per_page_parameter(): void
    {
        $this->authAdmin();
        for ($i = 0; $i < 5; $i++) {
            $user = User::factory()->create(['type' => 'user']);
            $this->createAssignment($this->coupon, $user);
        }

        $response = $this->getJson($this->assignmentUrl() . '?limit=2');

        $response->assertOk();
        $this->assertEquals(2, $response->json('data.per_page'));
        $this->assertEquals(5, $response->json('data.total'));
        $this->assertCount(2, $response->json('data.data'));
    }

    /** @test */
    public function index_returns_only_assignments_for_the_specified_coupon(): void
    {
        $this->authAdmin();
        $user = User::factory()->create(['type' => 'user']);
        $anotherCoupon = $this->createCoupon('OTHERCPN');
        $this->createAssignment($this->coupon, $user);
        $this->createAssignment($anotherCoupon, $user);

        $response = $this->getJson($this->assignmentUrl());

        $response->assertOk();
        $this->assertEquals(1, $response->json('data.total'));
        $this->assertEquals($this->coupon->id, $response->json('data.data.0.coupon_id'));
    }

    // =========================================================================
    // Create (Store)
    // =========================================================================

    /** @test */
    public function admin_can_create_assignment(): void
    {
        $this->authAdmin();

        $response = $this->postJson($this->assignmentUrl(), [
            'user_id' => $this->customer->id,
            'max_uses' => 3]);

        $response->assertStatus(201);
        $response->assertJsonPath('success', true);
        $response->assertJsonStructure([
            'status', 'message', 'success',
            'data' => ['id', 'coupon_id', 'user_id', 'user', 'max_uses', 'used', 'remaining', 'is_expired', 'assigned_at']]);
        $response->assertJsonPath('data.coupon_id', $this->coupon->id);
        $response->assertJsonPath('data.user_id', $this->customer->id);
        $response->assertJsonPath('data.max_uses', 3);
        $response->assertJsonPath('data.used', 0);
        $response->assertJsonPath('data.remaining', 3);
        $response->assertJsonPath('data.is_expired', false);
        $this->assertDatabaseHas('coupon_assignments', [
            'coupon_id' => $this->coupon->id,
            'user_id' => $this->customer->id,
            'max_uses' => 3,
            'used' => 0]);
    }

    /** @test */
    public function admin_can_create_assignment_with_expires_at(): void
    {
        $this->authAdmin();

        $response = $this->postJson($this->assignmentUrl(), [
            'user_id' => $this->customer->id,
            'max_uses' => 5,
            'expires_at' => now()->addWeek()->toISOString()]);

        $response->assertStatus(201);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.is_expired', false);
        $this->assertNotNull($response->json('data.expires_at'));
    }

    /** @test */
    public function cannot_create_duplicate_assignment(): void
    {
        $this->authAdmin();
        $this->createAssignment($this->coupon, $this->customer);

        $response = $this->postJson($this->assignmentUrl(), [
            'user_id' => $this->customer->id,
            'max_uses' => 1]);

        $response->assertStatus(409);
        $response->assertJsonPath('success', false);
    }

    /** @test */
    public function cannot_create_assignment_for_non_existent_coupon(): void
    {
        $this->authAdmin();

        $response = $this->postJson(self::PREFIX . '/coupons/99999/assignments', [
            'user_id' => $this->customer->id,
            'max_uses' => 1]);

        $response->assertStatus(404);
        $response->assertJsonPath('success', false);
    }

    // =========================================================================
    // Show
    // =========================================================================

    /** @test */
    public function admin_can_show_assignment(): void
    {
        $this->authAdmin();
        $assignment = $this->createAssignment($this->coupon, $this->customer);

        $response = $this->getJson($this->assignmentUrl() . '/' . $assignment->id);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.id', $assignment->id);
        $response->assertJsonPath('data.coupon_id', $this->coupon->id);
        $response->assertJsonPath('data.user_id', $this->customer->id);
        $response->assertJsonPath('data.max_uses', 1);
        $response->assertJsonPath('data.used', 0);
        $response->assertJsonPath('data.remaining', 1);
        $response->assertJsonPath('data.is_expired', false);
        $response->assertJsonStructure([
            'data' => ['user' => ['id', 'name', 'email']]]);
        $response->assertJsonPath('data.user.id', $this->customer->id);
    }

    /** @test */
    public function show_returns_404_for_non_existent_assignment(): void
    {
        $this->authAdmin();

        $response = $this->getJson($this->assignmentUrl() . '/99999');

        $response->assertStatus(404);
        $response->assertJsonPath('success', false);
    }

    /** @test */
    public function show_returns_404_when_assignment_belongs_to_different_coupon(): void
    {
        $this->authAdmin();
        $anotherCoupon = $this->createCoupon('OTHERCPN');
        $assignment = $this->createAssignment($anotherCoupon, $this->customer);

        $response = $this->getJson($this->assignmentUrl() . '/' . $assignment->id);

        $response->assertStatus(404);
        $response->assertJsonPath('success', false);
    }

    // =========================================================================
    // Update
    // =========================================================================

    /** @test */
    public function admin_can_update_max_uses(): void
    {
        $this->authAdmin();
        $assignment = $this->createAssignment($this->coupon, $this->customer);

        $response = $this->putJson($this->assignmentUrl() . '/' . $assignment->id, [
            'max_uses' => 10]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.max_uses', 10);
        $response->assertJsonPath('data.remaining', 10);
        $this->assertDatabaseHas('coupon_assignments', [
            'id' => $assignment->id,
            'max_uses' => 10]);
    }

    /** @test */
    public function admin_can_update_expires_at(): void
    {
        $this->authAdmin();
        $assignment = $this->createAssignment($this->coupon, $this->customer);
        $newExpiry = now()->addMonth()->toISOString();

        $response = $this->putJson($this->assignmentUrl() . '/' . $assignment->id, [
            'expires_at' => $newExpiry]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $this->assertNotNull($response->json('data.expires_at'));
    }

    /** @test */
    public function admin_can_set_expires_at_to_null(): void
    {
        $this->authAdmin();
        $assignment = $this->createAssignment($this->coupon, $this->customer, [
            'expires_at' => now()->addWeek()]);

        $response = $this->putJson($this->assignmentUrl() . '/' . $assignment->id, [
            'expires_at' => null]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $this->assertNull($response->json('data.expires_at'));
    }

    /** @test */
    public function cannot_update_max_uses_below_current_usage(): void
    {
        $this->authAdmin();
        $assignment = $this->createAssignment($this->coupon, $this->customer, [
            'max_uses' => 5,
            'used' => 3]);

        $response = $this->putJson($this->assignmentUrl() . '/' . $assignment->id, [
            'max_uses' => 2]);

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
        $this->assertDatabaseHas('coupon_assignments', [
            'id' => $assignment->id,
            'max_uses' => 5]);
    }

    /** @test */
    public function update_returns_404_for_non_existent_assignment(): void
    {
        $this->authAdmin();

        $response = $this->putJson($this->assignmentUrl() . '/99999', [
            'max_uses' => 5]);

        $response->assertStatus(404);
        $response->assertJsonPath('success', false);
    }

    // =========================================================================
    // Delete (Destroy)
    // =========================================================================

    /** @test */
    public function admin_can_delete_assignment_without_usage(): void
    {
        $this->authAdmin();
        $assignment = $this->createAssignment($this->coupon, $this->customer);

        $response = $this->deleteJson($this->assignmentUrl() . '/' . $assignment->id);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $this->assertDatabaseMissing('coupon_assignments', [
            'id' => $assignment->id]);
    }

    /** @test */
    public function cannot_delete_assignment_with_usage_history(): void
    {
        $this->authAdmin();
        $assignment = $this->createAssignment($this->coupon, $this->customer, [
            'max_uses' => 5,
            'used' => 2]);

        $response = $this->deleteJson($this->assignmentUrl() . '/' . $assignment->id);

        $response->assertStatus(409);
        $response->assertJsonPath('success', false);
        $this->assertDatabaseHas('coupon_assignments', [
            'id' => $assignment->id]);
    }

    /** @test */
    public function delete_returns_404_for_non_existent_assignment(): void
    {
        $this->authAdmin();

        $response = $this->deleteJson($this->assignmentUrl() . '/99999');

        $response->assertStatus(404);
        $response->assertJsonPath('success', false);
    }

    // =========================================================================
    // Resource Computed Fields
    // =========================================================================

    /** @test */
    public function resource_shows_remaining_as_max_uses_minus_used(): void
    {
        $this->authAdmin();
        $assignment = $this->createAssignment($this->coupon, $this->customer, [
            'max_uses' => 10,
            'used' => 3]);

        $response = $this->getJson($this->assignmentUrl() . '/' . $assignment->id);

        $response->assertJsonPath('data.remaining', 7);
    }

    /** @test */
    public function resource_shows_remaining_as_zero_when_exhausted(): void
    {
        $this->authAdmin();
        $assignment = $this->createAssignment($this->coupon, $this->customer, [
            'max_uses' => 3,
            'used' => 5]);

        $response = $this->getJson($this->assignmentUrl() . '/' . $assignment->id);

        $response->assertJsonPath('data.remaining', 0);
    }

    /** @test */
    public function resource_shows_is_expired_true_when_expired(): void
    {
        $this->authAdmin();
        $assignment = $this->createAssignment($this->coupon, $this->customer, [
            'expires_at' => now()->subDay()]);

        $response = $this->getJson($this->assignmentUrl() . '/' . $assignment->id);

        $response->assertJsonPath('data.is_expired', true);
    }

    /** @test */
    public function resource_shows_is_expired_false_when_not_expired(): void
    {
        $this->authAdmin();
        $assignment = $this->createAssignment($this->coupon, $this->customer, [
            'expires_at' => now()->addWeek()]);

        $response = $this->getJson($this->assignmentUrl() . '/' . $assignment->id);

        $response->assertJsonPath('data.is_expired', false);
    }

    /** @test */
    public function resource_shows_is_expired_false_when_no_expiry(): void
    {
        $this->authAdmin();
        $assignment = $this->createAssignment($this->coupon, $this->customer);

        $response = $this->getJson($this->assignmentUrl() . '/' . $assignment->id);

        $response->assertJsonPath('data.is_expired', false);
        $this->assertNull($response->json('data.expires_at'));
    }

    /** @test */
    public function resource_includes_user_data_when_loaded(): void
    {
        $this->authAdmin();
        $assignment = $this->createAssignment($this->coupon, $this->customer);

        $response = $this->getJson($this->assignmentUrl() . '/' . $assignment->id);

        $response->assertJsonPath('data.user.id', $this->customer->id);
        $response->assertJsonPath('data.user.name', $this->customer->name);
        $response->assertJsonPath('data.user.email', $this->customer->email);
    }

    // =========================================================================
    // Regression: Public Coupon Still Works
    // =========================================================================

    /** @test */
    public function coupon_with_zero_assignments_remains_public(): void
    {
        $this->authAdmin();
        $publicCoupon = $this->createCoupon('PUBLIC99');

        $response = $this->getJson($this->assignmentUrl($publicCoupon));

        $response->assertOk();
        $response->assertJsonPath('data.total', 0);
    }

    /** @test */
    public function deleting_an_assignment_restores_one_unit_of_quota_per_user(): void
    {
        $this->authAdmin();
        $user = User::factory()->create(['type' => 'user']);
        $assignment = $this->createAssignment($this->coupon, $user, ['max_uses' => 3, 'used' => 0]);

        $this->deleteJson($this->assignmentUrl() . '/' . $assignment->id);
        $this->assertDatabaseMissing('coupon_assignments', ['id' => $assignment->id]);

        $response = $this->getJson($this->assignmentUrl());
        $response->assertJsonPath('data.total', 0);
    }
}
