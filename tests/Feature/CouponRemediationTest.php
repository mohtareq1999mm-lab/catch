<?php

namespace Tests\Feature;

use App\Enums\CouponClaimStatus;
use App\Events\PaymentSucceeded;
use App\Listeners\Coupon\MarkCouponClaimRedeemed;
use App\Services\Coupon\CouponClaimService;
use App\Services\Coupon\CouponOrchestrator;
use App\Services\Coupon\CouponReservationService;
use App\Services\General\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Cart;
use Marvel\Database\Models\CartItem;
use Marvel\Database\Models\Coupon;
use Marvel\Database\Models\CouponAssignment;
use Marvel\Database\Models\CouponTargeting;
use Marvel\Database\Models\CouponUsage;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Promotion;
use Marvel\Database\Models\Transaction;
use Marvel\Database\Models\User;
use Marvel\Database\Repositories\CouponRepository;
use Marvel\Enums\DiscountType;
use Marvel\Enums\ProductType;
use Marvel\Enums\PromotionType;
use Tests\TestCase;

/**
 * P1 invariant proofs for the coupon remediation.
 *
 * Philosophy: each test proves a state transition + exactly-once effects,
 * never just an HTTP status.
 */
class CouponRemediationTest extends TestCase
{
    use RefreshDatabase;

    private const PREFIX = '/api/v1';

    private User $user;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->user = User::factory()->create(['type' => 'user']);
        $this->product = Product::create([
            'name' => 'Remediation Product',
            'slug' => 'remediation-product-' . Str::random(8),
            'price' => 100.00,
            'product_type' => ProductType::SIMPLE,
            'status' => true,
            'in_stock' => true,
            'quantity' => 50,
        ]);
    }

    private function createCoupon(string $code, array $overrides = []): Coupon
    {
        $coupon = Coupon::create(array_merge([
            'name' => 'Remediation Coupon',
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

    private function createPendingOrderWithCoupon(User $user, Coupon $coupon, string $status = 'pending'): Order
    {
        return Order::create([
            'user_id' => $user->id,
            'name' => 'Remediation Order',
            'user_phone' => '01000000000',
            'user_email' => 'test@test.com',
            'address' => '{}',
            'total_price' => 90.00,
            'price' => 100.00,
            'coupon' => $coupon->code,
            'coupon_discount' => 10,
            'status' => $status,
        ]);
    }

    /** @test */
    public function public_index_does_not_expose_coupon_codes(): void
    {
        $this->createCoupon('SECRET99');

        $response = $this->getJson(self::PREFIX . '/general/coupons');

        $response->assertOk();
        $payload = $response->json('data');
        $this->assertNotEmpty($payload, 'Expected at least one public coupon row.');

        foreach ((array) $payload as $row) {
            $this->assertArrayNotHasKey('code', (array) $row, 'INV-08: public listing must not leak redeemable codes.');
        }
    }

    /** @test */
    public function apply_accepts_case_and_whitespace_variants(): void
    {
        Sanctum::actingAs($this->user);
        $this->createCartWithItem();
        $coupon = $this->createCoupon('MIXED10');

        // Stored canonically normalized (CP-09).
        $this->assertEquals('MIXED10', $coupon->code);

        $response = $this->postJson(self::PREFIX . '/general/coupons/apply', [
            'code' => '  mixed10 ',
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $this->assertDatabaseHas('carts', [
            'user_id' => $this->user->id,
            'coupon' => 'MIXED10',
        ]);
    }

    /** @test */
    public function invalid_coupon_configuration_throws_on_save(): void
    {
        // CP-05: fail-closed enforcement matrix (was warn-only).
        foreach ([
            'negative discount' => ['discount' => -5],
            'percentage over 100' => ['discount_type' => 'percentage', 'discount' => 150],
            'negative limiter' => ['limiter' => -1],
            'negative max discount' => ['max_discount_amount' => -2],
            'end before start' => ['start_date' => now()->addMonth(), 'end_date' => now()->subDay()],
            'unknown type' => ['discount_type' => 'bogus'],
        ] as $case => $overrides) {
            try {
                Coupon::create(array_merge([
                    'name' => 'Invalid',
                    'slug' => 'invalid-' . Str::random(6),
                    'code' => 'INV-' . Str::upper(Str::random(6)),
                    'discount_type' => 'percentage',
                    'discount' => 10,
                    'status' => true,
                ], $overrides));
                $this->fail("Expected InvalidArgumentException for case: {$case}");
            } catch (\InvalidArgumentException $e) {
                $this->assertNotEmpty($e->getMessage());
            }
        }
    }

    /** @test */
    public function store_coupon_strips_system_managed_fields(): void
    {
        // CP-11: generic admin input must not mutate `used` or other internals.
        $repository = app(CouponRepository::class);

        $request = Request::create('/coupons', 'POST', [
            'name' => ['en' => 'Strip Test'],
            'discount' => 10,
            'discount_type' => 'percentage',
            'max_discount_amount' => 50,
            'start_date' => now()->subDay()->format('Y-m-d'),
            'end_date' => now()->addMonth()->format('Y-m-d'),
            'limiter' => 100,
            'status' => 1,
            'used' => 9999,
            'code' => 'HACKED',
            'slug' => 'hacked',
        ]);

        $coupon = $repository->storeCoupon($request);

        $this->assertNotEquals(9999, (int) $coupon->fresh()->used, 'INV-13: used counter must not be admin-writable.');
        $this->assertNotEquals('HACKED', $coupon->fresh()->code, 'Redeemable code must stay server-generated.');
    }

    /** @test */
    public function public_usage_blocks_assigned_path_reuse(): void
    {
        // POLICY 1: prior public consumption is not reset by an assigned grant.
        Sanctum::actingAs($this->user);
        $this->createCartWithItem();
        $coupon = $this->createCoupon('POLICY1');
        CouponAssignment::create([
            'coupon_id' => $coupon->id,
            'user_id' => $this->user->id,
            'max_uses' => 3,
            'used' => 0,
            'assigned_at' => now(),
        ]);
        CouponUsage::create([
            'coupon_id' => $coupon->id,
            'user_id' => $this->user->id,
            'order_id' => null,
            'used_at' => now(),
        ]);

        $response = $this->postJson(self::PREFIX . '/general/coupons/apply', [
            'code' => 'POLICY1',
        ]);

        $response->assertStatus(400);
        $response->assertJsonPath('success', false);
        $this->assertDatabaseMissing('carts', [
            'user_id' => $this->user->id,
            'coupon' => 'POLICY1',
        ]);
    }

    /** @test */
    public function claim_redeemed_exactly_once_on_completion(): void
    {
        // CP-01 (INV-01): ACTIVE → REDEEMED exactly once; repeat runs are no-ops.
        $coupon = $this->createCoupon('CLAIM1');
        CouponTargeting::create([
            'coupon_id' => $coupon->id,
            'mode' => 'dynamic',
            'require_claim' => true,
            'max_claims' => 5,
            'claim_ttl_hours' => 24,
        ]);

        $claim = app(CouponClaimService::class)->claim($coupon, $this->user);
        $this->assertEquals(CouponClaimStatus::ACTIVE, $claim->status);

        $order = $this->createPendingOrderWithCoupon($this->user, $coupon);

        app(OrderService::class)->changeOrderStatus(null, 'completed', $order->id);

        // INV-03: completed ⇒ usage committed.
        $this->assertDatabaseHas('coupon_usages', [
            'coupon_id' => $coupon->id,
            'user_id' => $this->user->id,
        ]);

        $listener = app(MarkCouponClaimRedeemed::class);
        $listener->handle(new PaymentSucceeded($order->fresh()));

        $this->assertEquals(CouponClaimStatus::REDEEMED, $claim->fresh()->status);
        $this->assertNotNull($claim->fresh()->redeemed_at);

        // Duplicate listener execution must not mutate further.
        $listener->handle(new PaymentSucceeded($order->fresh()));
        $this->assertEquals(CouponClaimStatus::REDEEMED, $claim->fresh()->status);
        $this->assertEquals(
            1,
            \Marvel\Database\Models\CouponClaim::where('coupon_id', $coupon->id)
                ->where('user_id', $this->user->id)
                ->where('status', CouponClaimStatus::REDEEMED)
                ->count()
        );
    }

    /** @test */
    public function wrong_user_claim_is_never_redeemed(): void
    {
        // CP-01 ownership: another user's ACTIVE claim must survive my order.
        $coupon = $this->createCoupon('CLAIM2');
        CouponTargeting::create([
            'coupon_id' => $coupon->id,
            'mode' => 'dynamic',
            'require_claim' => true,
            'max_claims' => 5,
            'claim_ttl_hours' => 24,
        ]);

        $other = User::factory()->create(['type' => 'user']);
        $otherClaim = app(CouponClaimService::class)->claim($coupon, $other);

        $order = $this->createPendingOrderWithCoupon($this->user, $coupon);

        app(MarkCouponClaimRedeemed::class)->handle(new PaymentSucceeded($order));

        $this->assertEquals(CouponClaimStatus::ACTIVE, $otherClaim->fresh()->status);
    }

    /** @test */
    public function reservation_release_is_idempotent(): void
    {
        // CP-08 (INV-04): release×3 ⇒ exactly one logical release.
        $coupon = $this->createCoupon('RELIDEM', ['limiter' => 5]);
        $order = $this->createPendingOrderWithCoupon($this->user, $coupon);
        $service = app(CouponReservationService::class);

        $service->reserve($order, $coupon);
        $service->release($order);
        $service->release($order);
        $service->release($order);

        $this->assertDatabaseMissing('coupon_reservations', ['order_id' => $order->id]);
        $this->assertEquals(0, (int) $coupon->fresh()->used, 'Release must never touch the consumed counter.');
    }

    /** @test */
    public function cancel_releases_coupon_reservation_without_restoring_quota(): void
    {
        // CP-08 + POLICY 5: pre-payment cancel releases the hold, never the quota.
        $coupon = $this->createCoupon('CANREL', ['limiter' => 5]);
        $order = $this->createPendingOrderWithCoupon($this->user, $coupon);
        app(CouponReservationService::class)->reserve($order, $coupon);

        app(OrderService::class)->changeOrderStatus(null, 'cancelled', $order->id);

        $this->assertDatabaseMissing('coupon_reservations', ['order_id' => $order->id]);
        $this->assertEquals(0, (int) $coupon->fresh()->used);
        $this->assertEquals('cancelled', $order->fresh()->status);
    }

    /** @test */
    public function promotion_is_evaluated_before_coupon(): void
    {
        // CP-06/07 + POLICY 7: 100 −10% promo = 90; 10% coupon on 90 = 9.
        Sanctum::actingAs($this->user);
        $cart = $this->createCartWithItem();
        $coupon = $this->createCoupon('PIPE10');

        $promotion = Promotion::create([
            'name' => 'Pipe Promotion',
            'slug' => 'pipe-promotion-' . Str::random(6),
            'code' => 'PIPE-' . Str::random(6),
            'type' => PromotionType::PRICE,
            'type_amount' => 'percentage',
            'value' => 10,
            'discount' => 10,
            'minimum_order_amount' => 0,
            'apply_to' => 'all_products',
            'status' => true,
            'start_at' => now()->subDay()->format('Y-m-d'),
            'end_at' => now()->addMonth()->format('Y-m-d'),
        ]);

        $cart->update(['coupon' => $coupon->code]);

        $totals = app(OrderService::class)->calculateCheckoutTotals(
            $cart->fresh(), $promotion->id, null, \Marvel\Enums\ShippingMethod::SCHEDULED
        );

        $this->assertTrue($totals->hasPromotion(), 'Promotion must be present in the pipeline.');
        $this->assertTrue($totals->hasCoupon(), 'Coupon must be present in the pipeline.');
        $this->assertEquals(10.0, round((float) $totals->promotionDiscount, 2));
        $this->assertEquals(9.0, round((float) $totals->couponDiscount, 2));
        $this->assertEquals(81.0, round((float) $totals->finalTotal, 2));
    }

    /** @test */
    public function claim_gated_coupon_rejected_without_active_claim(): void
    {
        // CP-07 parity core: the shared Orchestrator (used by FAST and
        // SCHEDULED) enforces the claim gate the old FAST path skipped.
        $coupon = $this->createCoupon('GATED1');
        CouponTargeting::create([
            'coupon_id' => $coupon->id,
            'mode' => 'dynamic',
            'require_claim' => true,
            'max_claims' => 5,
            'claim_ttl_hours' => 24,
        ]);

        $result = CouponOrchestrator::validateByCode('GATED1', $this->user, null);

        $this->assertFalse($result['valid']);
        $this->assertEquals('claim_required', $result['reason']);
    }

    /** @test */
    public function auto_generated_code_is_canonical_uppercase(): void
    {
        // B1: generated codes must already be normalized (saving fires
        // before creating on insert, so generation-time normalization
        // is the only correct hook).
        $coupon = Coupon::create([
            'name' => 'Auto Code',
            'slug' => 'auto-code-' . Str::random(6),
            'discount_type' => 'percentage',
            'discount' => 10,
            'status' => true,
            'start_date' => now()->subDay(),
            'end_date' => now()->addMonth(),
        ]);

        $this->assertMatchesRegularExpression('/^[A-Z0-9_]+$/', $coupon->fresh()->code);
    }

    /** @test */
    public function duplicate_code_in_different_case_is_rejected(): void
    {
        // M3: the DB unique is collation-dependent; the canonical guard
        // must reject SAVE10/save10 coexistence on every engine.
        $this->createCoupon('CASEDUP');

        try {
            Coupon::create([
                'name' => 'Case Dup',
                'slug' => 'case-dup-' . Str::random(6),
                'code' => 'casedup',
                'discount_type' => 'percentage',
                'discount' => 10,
                'status' => true,
                'start_date' => now()->subDay(),
                'end_date' => now()->addMonth(),
            ]);
            $this->fail('Expected InvalidArgumentException for case-variant duplicate code.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('already taken', $e->getMessage());
        }
    }

    /** @test */
    public function reapply_variant_returns_already_applied(): void
    {
        // S1: case/whitespace variants hit the canonical early return.
        Sanctum::actingAs($this->user);
        $this->createCartWithItem();
        $this->createCoupon('REAPPLY');

        $this->postJson(self::PREFIX . '/general/coupons/apply', ['code' => 'REAPPLY'])->assertOk();

        $response = $this->postJson(self::PREFIX . '/general/coupons/apply', ['code' => '  reapply ']);

        $response->assertOk();
        $response->assertJsonPath('data.already_applied', true);
    }

    /** @test */
    public function callback_with_exhausted_coupon_fails_visibly_without_completing(): void
    {
        // M1 (INV-03): gateway-verified payment + dead coupon ⇒ failure
        // response (not 500), order stays pending, transaction failed,
        // no usage committed, counter untouched.
        $coupon = $this->createCoupon('CALLBACK1', ['limiter' => 1, 'used' => 1]);

        $order = Order::create([
            'user_id' => $this->user->id,
            'name' => 'Callback Order',
            'user_phone' => '01000000000',
            'user_email' => 'test@test.com',
            'address' => '{}',
            'total_price' => 90.00,
            'price' => 100.00,
            'coupon' => $coupon->code,
            'coupon_discount' => 10,
            'status' => 'pending',
            'payment_status' => 'payment-pending',
            'fulfillment_status' => 'pending',
            'payment_method' => 'online',
            'currency_code' => 'EGP',
            'base_currency_code' => 'EGP',
        ]);

        $tx = Transaction::create([
            'order_id' => $order->id,
            'user_id' => $this->user->id,
            'payment_method' => 'myfatoorah',
            'gateway_transaction_id' => 'PAY-CB-' . Str::random(8),
            'invoice_id' => 'INV-CB-' . Str::random(8),
            'status' => 'pending',
            'amount' => 90.00,
            'currency' => 'EGP',
            'gateway_response' => ['_callback_type' => 'web'],
        ]);

        $mockGateway = \Mockery::mock(\App\Services\Gateway\MyFatoorahGateway::class);
        $mockGateway->shouldReceive('verifyPayment')->with($tx->gateway_transaction_id)->andReturn(new \App\DTOs\GatewayResult(
            success: true, status: 'paid', amount: 90.00, currency: 'EGP',
            gatewayTransactionId: $tx->gateway_transaction_id, rawResponse: [],
        ));
        $mockFactory = \Mockery::mock(\App\Services\Payment\PaymentGatewayFactory::class);
        $mockFactory->shouldReceive('make')->andReturn($mockGateway);
        $this->app->instance(\App\Services\Payment\PaymentGatewayFactory::class, $mockFactory);

        try {
            $response = $this->getJson('/api/v1/general/checkout/callback?paymentId=' . $tx->gateway_transaction_id);
        } finally {
            try {
                $this->app->forgetInstance(\App\Services\Payment\PaymentGatewayFactory::class);
            } catch (\Throwable $e) {
            }
        }

        $response->assertStatus(302);
        $this->assertStringContainsString('/payment/failed', $response->headers->get('Location') ?? '');

        $this->assertEquals('pending', $order->fresh()->status);
        $this->assertEquals('failed', $tx->fresh()->status);
        $this->assertEquals(1, (int) $coupon->fresh()->used);
        $this->assertDatabaseMissing('coupon_usages', [
            'coupon_id' => $coupon->id,
            'user_id' => $this->user->id,
        ]);
    }
}
