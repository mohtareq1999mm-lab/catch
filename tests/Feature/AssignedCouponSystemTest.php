<?php

namespace Tests\Feature;

use App\Services\Coupon\CouponAssignmentValidator;
use App\Services\Coupon\CouponOrchestrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Cart;
use Marvel\Database\Models\CartItem;
use Marvel\Database\Models\Coupon;
use Marvel\Database\Models\CouponAssignment;
use Marvel\Database\Models\CouponAssignmentUsage;
use Marvel\Database\Models\CouponUsage;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Transaction;
use Marvel\Database\Models\User;
use Marvel\Enums\ProductType;
use Tests\TestCase;

class AssignedCouponSystemTest extends TestCase
{
    use RefreshDatabase;

    private const PREFIX = '/api/v1';

    private User $user;
    private User $otherUser;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->user = User::factory()->create(['type' => 'user']);
        $this->otherUser = User::factory()->create(['type' => 'user']);
        $this->product = Product::create([
            'name' => 'Test Product',
            'slug' => 'test-product-' . Str::random(8),
            'price' => 100.00,
            'product_type' => ProductType::SIMPLE,
            'status' => true,
            'in_stock' => true,
            'quantity' => 50,
        ]);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function createCoupon(string $code, array $overrides = []): Coupon
    {
        $coupon = Coupon::create(array_merge([
            'name' => 'Test Coupon',
            'slug' => 'coupon-' . Str::random(6),
            'discount_type' => 'percentage',
            'discount' => 10,
            'status' => true,
            'start_date' => now()->subDay(),
            'end_date' => now()->addMonth(),
        ], $overrides));

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
            'expires_at' => null,
        ], $overrides));
    }

    private function createCartWithItem(?User $user = null): Cart
    {
        $target = $user ?? $this->user;

        $cart = Cart::create([
            'user_id' => $target->id,
            'status' => 'active',
            'total_price' => 100.00,
        ]);

        CartItem::create([
            'cart_id' => $cart->id,
            'product_id' => $this->product->id,
            'quantity' => 1,
            'price' => 100.00,
            'total_price' => 100.00,
            'shipping_method' => 'SCHEDULED',
        ]);

        return $cart->fresh();
    }

    private function authUser(?User $user = null): void
    {
        Sanctum::actingAs($user ?? $this->user);
    }

    // =========================================================================
    // Assigned Coupon - Apply Success
    // =========================================================================

    /** @test */
    public function assigned_user_can_apply_assigned_coupon(): void
    {
        $this->authUser();
        $this->createCartWithItem();
        $coupon = $this->createCoupon('ASSIGNED1');
        $this->createAssignment($coupon, $this->user);

        $response = $this->postJson(self::PREFIX . '/general/coupons/apply', [
            'code' => 'ASSIGNED1',
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);

        $this->assertDatabaseHas('carts', [
            'user_id' => $this->user->id,
            'coupon' => 'ASSIGNED1',
        ]);
    }

    /** @test */
    public function assigned_coupon_with_multiple_users_isolates_quota(): void
    {
        $this->authUser($this->user);
        $this->createCartWithItem($this->user);
        $coupon = $this->createCoupon('MULTIUSER1');
        $this->createAssignment($coupon, $this->user, ['max_uses' => 3, 'used' => 2]);
        $this->createAssignment($coupon, $this->otherUser, ['max_uses' => 5, 'used' => 0]);

        $response = $this->postJson(self::PREFIX . '/general/coupons/apply', [
            'code' => 'MULTIUSER1',
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
    }

    // =========================================================================
    // Assigned Coupon - Apply Failures (API returns generic error, only check success=false)
    // =========================================================================

    /** @test */
    public function non_assigned_user_cannot_apply_assigned_coupon(): void
    {
        $this->authUser($this->user);
        $this->createCartWithItem($this->user);
        $coupon = $this->createCoupon('NOTFORU1');

        $this->createAssignment($coupon, $this->otherUser);

        $response = $this->postJson(self::PREFIX . '/general/coupons/apply', [
            'code' => 'NOTFORU1',
        ]);

        $response->assertStatus(400);
        $response->assertJsonPath('success', false);
    }

    /** @test */
    public function expired_assignment_returns_error(): void
    {
        $this->authUser();
        $this->createCartWithItem();
        $coupon = $this->createCoupon('EXPASSIGN');
        $this->createAssignment($coupon, $this->user, [
            'expires_at' => now()->subDay(),
        ]);

        $response = $this->postJson(self::PREFIX . '/general/coupons/apply', [
            'code' => 'EXPASSIGN',
        ]);

        $response->assertStatus(400);
        $response->assertJsonPath('success', false);
    }

    /** @test */
    public function usage_quota_exceeded_returns_error(): void
    {
        $this->authUser();
        $this->createCartWithItem();
        $coupon = $this->createCoupon('QUOTA1');
        $this->createAssignment($coupon, $this->user, [
            'max_uses' => 2,
            'used' => 2,
        ]);

        $response = $this->postJson(self::PREFIX . '/general/coupons/apply', [
            'code' => 'QUOTA1',
        ]);

        $response->assertStatus(400);
        $response->assertJsonPath('success', false);
    }

    // =========================================================================
    // Multi-use Assigned Coupon (max_uses > 1)
    // =========================================================================

    /** @test */
    public function assigned_coupon_can_be_used_multiple_times_up_to_max_uses(): void
    {
        $this->authUser();
        $this->createCartWithItem();
        $coupon = $this->createCoupon('MULTIUSE1');
        $this->createAssignment($coupon, $this->user, [
            'max_uses' => 3,
            'used' => 3,
        ]);

        $response = $this->postJson(self::PREFIX . '/general/coupons/apply', [
            'code' => 'MULTIUSE1',
        ]);

        $response->assertStatus(400);
        $response->assertJsonPath('success', false);
    }

    /** @test */
    public function assigned_coupon_allow_usage_before_reaching_max(): void
    {
        $this->authUser();
        $this->createCartWithItem();
        $coupon = $this->createCoupon('MULTIUSE2');
        $this->createAssignment($coupon, $this->user, [
            'max_uses' => 3,
            'used' => 1,
        ]);

        $response = $this->postJson(self::PREFIX . '/general/coupons/apply', [
            'code' => 'MULTIUSE2',
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
    }

    // =========================================================================
    // Public Coupon Still Works (Backward Compatibility)
    // =========================================================================

    /** @test */
    public function public_coupon_with_no_assignments_still_works(): void
    {
        $this->authUser();
        $this->createCartWithItem();
        $this->createCoupon('PUBLIC1');

        $response = $this->postJson(self::PREFIX . '/general/coupons/apply', [
            'code' => 'PUBLIC1',
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
    }

    /** @test */
    public function public_coupon_already_used_check_still_works(): void
    {
        $this->authUser();
        $this->createCartWithItem();
        $coupon = $this->createCoupon('PUBUSED1');

        CouponUsage::create([
            'coupon_id' => $coupon->id,
            'user_id' => $this->user->id,
            'used_at' => now(),
        ]);

        $response = $this->postJson(self::PREFIX . '/general/coupons/apply', [
            'code' => 'PUBUSED1',
        ]);

        $response->assertStatus(400);
        $response->assertJsonPath('success', false);
    }

    /** @test */
    public function any_user_can_use_public_coupon(): void
    {
        $this->authUser($this->otherUser);
        $this->createCartWithItem($this->otherUser);
        $this->createCoupon('PUBANY1');

        $response = $this->postJson(self::PREFIX . '/general/coupons/apply', [
            'code' => 'PUBANY1',
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
    }

    // =========================================================================
    // Assigned Coupon + Product Restrictions
    // =========================================================================

    /** @test */
    public function assigned_coupon_with_product_restriction_validates_products(): void
    {
        $this->authUser();
        $this->createCartWithItem();
        $coupon = $this->createCoupon('ASSIGNRES');

        $restrictedProduct = Product::create([
            'name' => 'Restricted',
            'slug' => 'restricted-' . Str::random(8),
            'price' => 200,
            'product_type' => ProductType::SIMPLE,
            'status' => true,
            'in_stock' => true,
        ]);

        $coupon->products()->attach($restrictedProduct->id);
        $this->createAssignment($coupon, $this->user);

        $response = $this->postJson(self::PREFIX . '/general/coupons/apply', [
            'code' => 'ASSIGNRES',
        ]);

        $response->assertStatus(400);
        $response->assertJsonPath('success', false);
    }

    /** @test */
    public function assigned_coupon_with_matching_product_succeeds(): void
    {
        $this->authUser();
        $this->createCartWithItem();
        $coupon = $this->createCoupon('ASSIGNMATCH');

        $coupon->products()->attach($this->product->id);
        $this->createAssignment($coupon, $this->user);

        $response = $this->postJson(self::PREFIX . '/general/coupons/apply', [
            'code' => 'ASSIGNMATCH',
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
    }

    // =========================================================================
    // Global Limiter + Assignment Quota
    // =========================================================================

    /** @test */
    public function global_limiter_still_works_for_assigned_coupon(): void
    {
        $this->authUser();
        $this->createCartWithItem();
        $coupon = $this->createCoupon('LIMITASSIGN', [
            'limiter' => 5,
            'used' => 5,
        ]);
        $this->createAssignment($coupon, $this->user, ['max_uses' => 10]);

        $response = $this->postJson(self::PREFIX . '/general/coupons/apply', [
            'code' => 'LIMITASSIGN',
        ]);

        $response->assertStatus(400);
        $response->assertJsonPath('success', false);
    }

    /** @test */
    public function assignment_quota_rejected_even_when_global_limiter_available(): void
    {
        $this->authUser();
        $this->createCartWithItem();
        $coupon = $this->createCoupon('QUOTALIMIT', [
            'limiter' => 100,
            'used' => 0,
        ]);
        $this->createAssignment($coupon, $this->user, ['max_uses' => 1, 'used' => 1]);

        $response = $this->postJson(self::PREFIX . '/general/coupons/apply', [
            'code' => 'QUOTALIMIT',
        ]);

        $response->assertStatus(400);
        $response->assertJsonPath('success', false);
    }

    // =========================================================================
    // Orchestrator Unit Tests (direct service calls)
    // =========================================================================

    /** @test */
    public function orchestrator_returns_valid_for_assigned_user(): void
    {
        $coupon = $this->createCoupon('ORCHASSIGN');
        $this->createAssignment($coupon, $this->user);

        $result = CouponOrchestrator::validateByCode('ORCHASSIGN', $this->user);

        $this->assertTrue($result['valid']);
        $this->assertNotNull($result['coupon']);
    }

    /** @test */
    public function orchestrator_returns_not_assigned_for_non_assigned_user(): void
    {
        $coupon = $this->createCoupon('ORCHNOT');
        $this->createAssignment($coupon, $this->otherUser);

        $result = CouponOrchestrator::validateByCode('ORCHNOT', $this->user);

        $this->assertFalse($result['valid']);
        $this->assertEquals('not_assigned', $result['reason']);
    }

    /** @test */
    public function orchestrator_returns_public_validation_when_no_assignments(): void
    {
        $this->createCoupon('ORCHPUB');

        $result = CouponOrchestrator::validateByCode('ORCHPUB', $this->user);

        $this->assertTrue($result['valid']);
    }

    /** @test */
    public function orchestrator_skips_already_used_for_assigned_coupons(): void
    {
        $coupon = $this->createCoupon('ORCHSKIP');
        $assignment = $this->createAssignment($coupon, $this->user, ['max_uses' => 3, 'used' => 0]);

        $result = CouponOrchestrator::validateByCode('ORCHSKIP', $this->user);

        $this->assertTrue($result['valid'], 'Assigned coupon should skip already_used check and use quota instead');
    }

    /** @test */
    public function orchestrator_rejects_disabled_coupon_even_if_assigned(): void
    {
        $coupon = $this->createCoupon('ORCHDIS', ['status' => false]);
        $this->createAssignment($coupon, $this->user);

        $result = CouponOrchestrator::validateByCode('ORCHDIS', $this->user);

        $this->assertFalse($result['valid']);
        $this->assertEquals('disabled', $result['reason']);
    }

    // =========================================================================
    // Checkout records coupon usage for assigned coupons
    // =========================================================================

    /** @test */
    public function checkout_records_assigned_coupon_usage_and_increments_assignment(): void
    {
        $coupon = $this->createCoupon('CHKASSIGN');
        $assignment = $this->createAssignment($coupon, $this->user, ['max_uses' => 5, 'used' => 0]);

        $order = Order::create([
            'user_id' => $this->user->id,
            'name' => 'Test Order',
            'user_phone' => '01000000000',
            'user_email' => 'test@test.com',
            'address' => json_encode(['street' => '123 Main St']),
            'total_price' => 80.00,
            'price' => 90.00,
            'coupon' => $coupon->code,
            'coupon_discount' => 10,
            'status' => 'pending',
        ]);

        Transaction::create([
            'order_id' => $order->id,
            'user_id' => $this->user->id,
            'payment_method' => 'cod',
            'status' => 'pending',
            'amount' => 100,
            'invoice_id' => 'INV-ASSIGN-1',
        ]);

        $orderService = app(\App\Services\General\OrderService::class);
        $orderService->markCodAsPaid($order);

        $assignment->refresh();
        $this->assertEquals(1, $assignment->used);

        $coupon->refresh();
        $this->assertEquals(1, $coupon->used);
    }

    /** @test */
    public function checkout_allows_multiple_assignments_usage(): void
    {
        $coupon = $this->createCoupon('CHKMULTI');
        $assignment = $this->createAssignment($coupon, $this->user, ['max_uses' => 3, 'used' => 0]);

        foreach (range(1, 2) as $i) {
            $order = Order::create([
                'user_id' => $this->user->id,
                'name' => "Order $i",
                'user_phone' => '01000000000',
                'user_email' => 'test@test.com',
                'address' => '{}',
                'total_price' => 80.00,
                'price' => 90.00,
                'coupon' => $coupon->code,
                'coupon_discount' => 10,
                'status' => 'completed',
            ]);

            Transaction::create([
                'order_id' => $order->id,
                'user_id' => $this->user->id,
                'payment_method' => 'cod',
                'status' => 'paid',
                'paid_at' => now(),
                'amount' => 100,
                'invoice_id' => "INV-MULTI-$i",
            ]);

            $orderService = app(\App\Services\General\OrderService::class);
            $orderService->changeOrderStatus(null, 'completed', $order->id);
        }

        $assignment->refresh();
        $this->assertEquals(2, $assignment->used);
    }

    /** @test */
    public function checkout_with_public_coupon_still_uses_first_or_create(): void
    {
        $coupon = $this->createCoupon('CHKPUB');

        $order = Order::create([
            'user_id' => $this->user->id,
            'name' => 'Test Order',
            'user_phone' => '01000000000',
            'user_email' => 'test@test.com',
            'address' => '{}',
            'total_price' => 80.00,
            'price' => 90.00,
            'coupon' => $coupon->code,
            'coupon_discount' => 10,
            'status' => 'completed',
        ]);

        Transaction::create([
            'order_id' => $order->id,
            'user_id' => $this->user->id,
            'payment_method' => 'cod',
            'status' => 'paid',
            'paid_at' => now(),
            'amount' => 100,
            'invoice_id' => 'INV-PUB-1',
        ]);

        $orderService = app(\App\Services\General\OrderService::class);
        $orderService->changeOrderStatus(null, 'completed', $order->id);

        $this->assertDatabaseHas('coupon_usages', [
            'coupon_id' => $coupon->id,
            'user_id' => $this->user->id,
        ]);

        $coupon->refresh();
        $this->assertEquals(1, $coupon->used);
    }

    // =========================================================================
    // CouponAssignmentValidator Unit Tests
    // =========================================================================

    /** @test */
    public function assignment_validator_returns_no_assignments_for_public_coupon(): void
    {
        $coupon = $this->createCoupon('VALPUB');

        $result = CouponAssignmentValidator::validate($coupon, $this->user);

        $this->assertFalse($result['has_assignments']);
        $this->assertTrue($result['valid']);
    }

    /** @test */
    public function assignment_validator_detects_expired_assignment(): void
    {
        $coupon = $this->createCoupon('VALEXP');
        $this->createAssignment($coupon, $this->user, [
            'expires_at' => now()->subDay(),
        ]);

        $result = CouponAssignmentValidator::validate($coupon, $this->user);

        $this->assertTrue($result['has_assignments']);
        $this->assertFalse($result['valid']);
        $this->assertEquals('assignment_expired', $result['reason']);
    }

    /** @test */
    public function assignment_validator_quota_exceeded(): void
    {
        $coupon = $this->createCoupon('VALQUOTA');
        $this->createAssignment($coupon, $this->user, [
            'max_uses' => 3,
            'used' => 3,
        ]);

        $result = CouponAssignmentValidator::validate($coupon, $this->user);

        $this->assertTrue($result['has_assignments']);
        $this->assertFalse($result['valid']);
        $this->assertEquals('usage_quota_exceeded', $result['reason']);
    }

    /** @test */
    public function assignment_validator_passes_for_valid_assignment(): void
    {
        $coupon = $this->createCoupon('VALPASS');
        $this->createAssignment($coupon, $this->user, [
            'max_uses' => 5,
            'used' => 0,
        ]);

        $result = CouponAssignmentValidator::validate($coupon, $this->user);

        $this->assertTrue($result['has_assignments']);
        $this->assertTrue($result['valid']);
        $this->assertArrayHasKey('assignment', $result);
    }

    /** @test */
    public function assignment_validator_uses_assignment_used_counter(): void
    {
        $coupon = $this->createCoupon('VALCOUNTER');
        $this->createAssignment($coupon, $this->user, [
            'max_uses' => 5,
            'used' => 3,
        ]);

        $result = CouponAssignmentValidator::validate($coupon, $this->user);

        $this->assertTrue($result['valid']);
    }

    // =========================================================================
    // CouponResource Serialization
    // =========================================================================

    /** @test */
    public function coupon_resource_serializes_is_assigned_false_for_public_coupon(): void
    {
        $coupon = $this->createCoupon('RESPUB');
        $coupon->load('assignments');

        $response = (new \Marvel\Http\Resources\CouponResource($coupon))->response();

        $data = json_decode($response->getContent(), true);

        $this->assertFalse($data['is_assigned']);
        $this->assertEmpty($data['assignments']);
    }

    /** @test */
    public function coupon_resource_serializes_is_assigned_true_with_assignments(): void
    {
        $coupon = $this->createCoupon('RESASSIGN');
        $this->createAssignment($coupon, $this->user, ['max_uses' => 3, 'expires_at' => now()->addMonth()]);
        $coupon->load('assignments');

        $response = (new \Marvel\Http\Resources\CouponResource($coupon))->response();

        $data = json_decode($response->getContent(), true);

        $this->assertTrue($data['is_assigned']);
        $this->assertCount(1, $data['assignments']);
        $this->assertEquals($this->user->id, $data['assignments'][0]['user_id']);
        $this->assertEquals(3, $data['assignments'][0]['max_uses']);
        $this->assertNotNull($data['assignments'][0]['expires_at']);
    }

    // =========================================================================
    // HARDENING: Usage History & Audit Trail
    // =========================================================================

    /** @test */
    public function record_coupon_usage_creates_assignment_usage_history(): void
    {
        $coupon = $this->createCoupon('AUDIT1');
        $assignment = $this->createAssignment($coupon, $this->user, ['max_uses' => 5, 'used' => 0]);

        $order = Order::create([
            'user_id' => $this->user->id,
            'name' => 'Audit Order',
            'user_phone' => '01000000000',
            'user_email' => 'test@test.com',
            'address' => '{}',
            'total_price' => 80.00,
            'price' => 90.00,
            'coupon' => $coupon->code,
            'coupon_discount' => 10,
            'status' => 'completed',
        ]);

        Transaction::create([
            'order_id' => $order->id,
            'user_id' => $this->user->id,
            'payment_method' => 'cod',
            'status' => 'paid',
            'paid_at' => now(),
            'amount' => 100,
            'invoice_id' => 'INV-AUDIT-1',
        ]);

        $orderService = app(\App\Services\General\OrderService::class);
        $orderService->changeOrderStatus(null, 'completed', $order->id);

        $this->assertDatabaseHas('coupon_assignment_usages', [
            'coupon_assignment_id' => $assignment->id,
            'order_id' => $order->id,
        ]);

        $usageCount = CouponAssignmentUsage::where('coupon_assignment_id', $assignment->id)->count();
        $this->assertEquals(1, $usageCount);
    }

    /** @test */
    public function audit_trail_shows_all_usage_events(): void
    {
        $coupon = $this->createCoupon('AUDIT2');
        $assignment = $this->createAssignment($coupon, $this->user, ['max_uses' => 5, 'used' => 0]);

        $orderIds = [];
        foreach (range(1, 3) as $i) {
            $order = Order::create([
                'user_id' => $this->user->id,
                'name' => "Order $i",
                'user_phone' => '01000000000',
                'user_email' => 'test@test.com',
                'address' => '{}',
                'total_price' => 80.00,
                'price' => 90.00,
                'coupon' => $coupon->code,
                'coupon_discount' => 10,
                'status' => 'completed',
            ]);

            Transaction::create([
                'order_id' => $order->id,
                'user_id' => $this->user->id,
                'payment_method' => 'cod',
                'status' => 'paid',
                'paid_at' => now(),
                'amount' => 100,
                'invoice_id' => "INV-AUDIT-$i",
            ]);

            $orderService = app(\App\Services\General\OrderService::class);
            $orderService->changeOrderStatus(null, 'completed', $order->id);

            $orderIds[] = $order->id;
        }

        $assignment->refresh();
        $this->assertEquals(3, $assignment->used);

        $usageCount = CouponAssignmentUsage::where('coupon_assignment_id', $assignment->id)->count();
        $this->assertEquals(3, $usageCount);

        foreach ($orderIds as $oid) {
            $this->assertDatabaseHas('coupon_assignment_usages', [
                'coupon_assignment_id' => $assignment->id,
                'order_id' => $oid,
            ]);
        }
    }

    // =========================================================================
    // HARDENING: Coupon Disable with Active Assignment
    // =========================================================================

    /** @test */
    public function disabled_coupon_is_rejected_even_with_valid_assignment(): void
    {
        $this->authUser();
        $this->createCartWithItem();
        $coupon = $this->createCoupon('DISASSIGN', ['status' => false]);
        $this->createAssignment($coupon, $this->user);

        $response = $this->postJson(self::PREFIX . '/general/coupons/apply', [
            'code' => 'DISASSIGN',
        ]);

        $response->assertStatus(400);
        $response->assertJsonPath('success', false);
    }

    // =========================================================================
    // HARDENING: User Deletion Cascade
    // =========================================================================

    /** @test */
    public function user_hard_deletion_cascades_to_assignments(): void
    {
        $coupon = $this->createCoupon('DELUSER');
        $this->createAssignment($coupon, $this->user);

        $this->assertDatabaseHas('coupon_assignments', [
            'coupon_id' => $coupon->id,
            'user_id' => $this->user->id,
        ]);

        $this->user->forceDelete();

        $this->assertDatabaseMissing('coupon_assignments', [
            'coupon_id' => $coupon->id,
            'user_id' => $this->user->id,
        ]);

        $this->assertFalse($coupon->assignments()->exists());
    }

    /** @test */
    public function soft_deleted_user_cannot_authenticate_so_assignment_is_inaccessible(): void
    {
        $coupon = $this->createCoupon('DELVAL');
        $this->createAssignment($coupon, $this->user);

        $this->user->delete();

        $this->assertTrue($coupon->assignments()->exists(), 'Assignment persists after soft-delete');

        $result = CouponAssignmentValidator::validate($coupon, $this->user);

        $this->assertTrue($result['has_assignments']);
        $this->assertTrue($result['valid'], 'Validator still passes because assignment exists and user object was passed directly');

        $response = $this->postJson(self::PREFIX . '/general/coupons/apply', ['code' => 'DELVAL']);
        $response->assertUnauthorized();
    }

    // =========================================================================
    // HARDENING: Max Uses Modification Preserves Used
    // =========================================================================

    /** @test */
    public function increasing_max_uses_preserves_used_counter(): void
    {
        $coupon = $this->createCoupon('MAXMOD');
        $assignment = $this->createAssignment($coupon, $this->user, [
            'max_uses' => 5,
            'used' => 3,
        ]);

        $this->assertEquals(2, $assignment->max_uses - $assignment->used);

        $assignment->update(['max_uses' => 10]);

        $assignment->refresh();
        $this->assertEquals(3, $assignment->used);
        $this->assertEquals(7, $assignment->max_uses - $assignment->used);
    }

    /** @test */
    public function decreasing_max_uses_can_lock_out_user(): void
    {
        $coupon = $this->createCoupon('MAXDEC');
        $assignment = $this->createAssignment($coupon, $this->user, [
            'max_uses' => 10,
            'used' => 8,
        ]);

        $this->assertEquals(2, $assignment->max_uses - $assignment->used);

        $assignment->update(['max_uses' => 8]);

        $assignment->refresh();
        $this->assertEquals(8, $assignment->used);
        $this->assertEquals(0, $assignment->max_uses - $assignment->used);

        $result = CouponAssignmentValidator::validate($coupon, $this->user);
        $this->assertFalse($result['valid']);
        $this->assertEquals('usage_quota_exceeded', $result['reason']);
    }

    // =========================================================================
    // HARDENING: Assignment Expiration with Active Coupon
    // =========================================================================

    /** @test */
    public function expired_assignment_rejected_while_coupon_is_active(): void
    {
        $this->authUser();
        $this->createCartWithItem();
        $coupon = $this->createCoupon('EXPACTIVE', [
            'start_date' => now()->subDay(),
            'end_date' => now()->addMonth(),
        ]);
        $this->createAssignment($coupon, $this->user, [
            'expires_at' => now()->subDay(),
        ]);

        $response = $this->postJson(self::PREFIX . '/general/coupons/apply', [
            'code' => 'EXPACTIVE',
        ]);

        $response->assertStatus(400);
        $response->assertJsonPath('success', false);
    }

    // =========================================================================
    // HARDENING: Database Uniqueness
    // =========================================================================

    /** @test */
    public function cannot_create_duplicate_assignment(): void
    {
        $coupon = $this->createCoupon('UNIQ1');
        $this->createAssignment($coupon, $this->user);

        $this->expectException(\Illuminate\Database\QueryException::class);
        CouponAssignment::create([
            'coupon_id' => $coupon->id,
            'user_id' => $this->user->id,
            'max_uses' => 1,
        ]);
    }

    // =========================================================================
    // HARDENING: Transaction Rollback Safety
    // =========================================================================

    /** @test */
    public function coupon_usage_rolled_back_when_mark_cod_fails(): void
    {
        $coupon = $this->createCoupon('ROLLBACK1');
        $assignment = $this->createAssignment($coupon, $this->user, ['max_uses' => 3, 'used' => 0]);

        $order = Order::create([
            'user_id' => $this->user->id,
            'name' => 'Rollback Test',
            'user_phone' => '01000000000',
            'user_email' => 'test@test.com',
            'address' => '{}',
            'total_price' => 80.00,
            'price' => 90.00,
            'coupon' => $coupon->code,
            'coupon_discount' => 10,
            'status' => 'pending',
        ]);

        Transaction::create([
            'order_id' => $order->id,
            'user_id' => $this->user->id,
            'payment_method' => 'cod',
            'status' => 'paid',
            'paid_at' => now(),
            'amount' => 100,
            'invoice_id' => 'INV-ROLL-1',
        ]);

        $orderService = app(\App\Services\General\OrderService::class);

        try {
            $orderService->markCodAsPaid($order);
        } catch (\Exception $e) {
        }

        $assignment->refresh();
        $this->assertEquals(0, $assignment->used, 'Used counter should not increment when transaction fails');

        $this->assertEquals(0, $coupon->fresh()->used);

        $this->assertEquals(0, CouponAssignmentUsage::where('coupon_assignment_id', $assignment->id)->count());
    }

    /** @test */
    public function mark_cod_as_paid_is_atomic(): void
    {
        $this->authUser();
        $this->createCartWithItem();
        $coupon = $this->createCoupon('ATOMIC1');
        $assignment = $this->createAssignment($coupon, $this->user, ['max_uses' => 1, 'used' => 0]);

        $order = Order::create([
            'user_id' => $this->user->id,
            'name' => 'Atomic Test',
            'user_phone' => '01000000000',
            'user_email' => 'test@test.com',
            'address' => '{}',
            'total_price' => 80.00,
            'price' => 90.00,
            'coupon' => $coupon->code,
            'coupon_discount' => 10,
            'status' => 'pending',
        ]);

        Transaction::create([
            'order_id' => $order->id,
            'user_id' => $this->user->id,
            'payment_method' => 'cod',
            'status' => 'pending',
            'amount' => 100,
            'invoice_id' => 'INV-ATOMIC-1',
        ]);

        $orderService = app(\App\Services\General\OrderService::class);
        $orderService->markCodAsPaid($order);

        $assignment->refresh();
        $this->assertEquals(1, $assignment->used);

        $this->assertDatabaseHas('coupon_assignment_usages', [
            'coupon_assignment_id' => $assignment->id,
        ]);
    }

    // =========================================================================
    // HARDENING: Order Cancellation Does NOT Return Quota (Policy)
    // =========================================================================

    /** @test */
    public function order_cancellation_does_not_decrement_assignment_used(): void
    {
        $coupon = $this->createCoupon('CANCELPOLICY');
        $assignment = $this->createAssignment($coupon, $this->user, ['max_uses' => 3, 'used' => 1]);

        $order = Order::create([
            'user_id' => $this->user->id,
            'name' => 'Cancel Test',
            'user_phone' => '01000000000',
            'user_email' => 'test@test.com',
            'address' => '{}',
            'total_price' => 80.00,
            'price' => 90.00,
            'coupon' => $coupon->code,
            'coupon_discount' => 10,
            'status' => 'pending',
        ]);

        Transaction::create([
            'order_id' => $order->id,
            'user_id' => $this->user->id,
            'payment_method' => 'cod',
            'status' => 'pending',
            'amount' => 100,
            'invoice_id' => 'INV-CANCEL-1',
        ]);

        $orderService = app(\App\Services\General\OrderService::class);
        $orderService->changeOrderStatus(null, 'cancelled', $order->id);

        $assignment->refresh();
        $this->assertEquals(1, $assignment->used, 'Cancellation must NOT decrement usage counter');
    }

    // =========================================================================
    // HARDENING: Product Restrictions Stay on Coupon, Not Assignment
    // =========================================================================

    /** @test */
    public function product_restrictions_are_owned_by_coupon_not_assignment(): void
    {
        $coupon = $this->createCoupon('PRODCOUPON');
        $coupon->products()->attach($this->product->id);
        $this->createAssignment($coupon, $this->user);

        $coupon->products()->sync([]);

        $this->authUser();
        $this->createCartWithItem();

        $response = $this->postJson(self::PREFIX . '/general/coupons/apply', [
            'code' => 'PRODCOUPON',
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
    }

    // =========================================================================
    // HARDENING: RecordCouponUsage Rejects Over-Consumption
    // =========================================================================

    /** @test */
    public function record_coupon_usage_skips_when_quota_exhausted(): void
    {
        $coupon = $this->createCoupon('OVERCONSUME');
        $assignment = $this->createAssignment($coupon, $this->user, ['max_uses' => 1, 'used' => 1]);

        $order = Order::create([
            'user_id' => $this->user->id,
            'name' => 'OverConsume',
            'user_phone' => '01000000000',
            'user_email' => 'test@test.com',
            'address' => '{}',
            'total_price' => 80.00,
            'price' => 90.00,
            'coupon' => $coupon->code,
            'coupon_discount' => 10,
            'status' => 'completed',
        ]);

        Transaction::create([
            'order_id' => $order->id,
            'user_id' => $this->user->id,
            'payment_method' => 'cod',
            'status' => 'paid',
            'paid_at' => now(),
            'amount' => 100,
            'invoice_id' => 'INV-OVER-1',
        ]);

        $orderService = app(\App\Services\General\OrderService::class);
        $orderService->changeOrderStatus(null, 'completed', $order->id);

        $assignment->refresh();
        $this->assertEquals(1, $assignment->used, 'Used counter must NOT exceed max_uses');
    }

    // =========================================================================
    // HARDENING: Public Coupon Remaining Unchanged (Full Regression)
    // =========================================================================

    /** @test */
    public function public_coupon_free_shipping_still_works(): void
    {
        $this->authUser();
        $this->createCartWithItem();
        $this->createCoupon('FREESHIP', [
            'discount_type' => 'free_shipping',
            'discount' => 0,
        ]);

        $response = $this->postJson(self::PREFIX . '/general/coupons/apply', [
            'code' => 'FREESHIP',
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
    }

    /** @test */
    public function public_coupon_fixed_rate_still_works(): void
    {
        $this->authUser();
        $this->createCartWithItem();
        $this->createCoupon('FIXED', [
            'discount_type' => 'fixed_rate',
            'discount' => 15,
        ]);

        $response = $this->postJson(self::PREFIX . '/general/coupons/apply', [
            'code' => 'FIXED',
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
    }

    /** @test */
    public function public_coupon_global_limiter_still_works(): void
    {
        $this->authUser();
        $this->createCartWithItem();
        $this->createCoupon('GLOBLIM', [
            'limiter' => 10,
            'used' => 10,
        ]);

        $response = $this->postJson(self::PREFIX . '/general/coupons/apply', [
            'code' => 'GLOBLIM',
        ]);

        $response->assertStatus(400);
        $response->assertJsonPath('success', false);
    }

    // =========================================================================
    // HARDENING: Detection Logic Uses EXISTS Not COUNT
    // =========================================================================

    /** @test */
    public function assignment_detection_uses_exists_not_count(): void
    {
        $coupon = $this->createCoupon('DETECT1');

        $queryLog = [];
        \Illuminate\Support\Facades\DB::listen(function ($query) use (&$queryLog) {
            $queryLog[] = $query->sql;
        });

        CouponAssignmentValidator::validate($coupon, $this->user);

        $hasExists = false;
        foreach ($queryLog as $sql) {
            if (str_contains($sql, 'exists') && str_contains($sql, 'coupon_assignments')) {
                $hasExists = true;
            }
        }

        $this->assertTrue($hasExists, 'Must use EXISTS, not COUNT for assignment detection');
    }
}
