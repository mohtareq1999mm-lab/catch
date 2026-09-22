<?php

namespace Tests\Feature;

use App\Enums\CouponClaimStatus;
use App\Enums\RateSource;
use App\Events\PaymentSucceeded;
use App\Listeners\Coupon\MarkCouponClaimRedeemed;
use App\Models\Currency;
use App\Models\CurrencyRate;
use App\Services\Coupon\CouponClaimService;
use App\Services\Customer\CustomerMetricsService;
use App\Services\Gateway\MyFatoorahGateway;
use App\Services\General\MyfatoraService;
use App\Services\General\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Marvel\Database\Models\Coupon;
use Marvel\Database\Models\CouponTargeting;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Transaction;
use Marvel\Database\Models\User;
use Tests\TestCase;

class CouponCurrencyClosureTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
    }

    private function seedRates(): void
    {
        // Base USD; historical rates Jan 2026 differ from later rates to prove
        // historical (not current) conversion is used.
        $usd = Currency::create(['code' => 'USD', 'name' => ['en' => 'US Dollar'], 'is_active' => true]);
        $kwd = Currency::create(['code' => 'KWD', 'name' => ['en' => 'Kuwaiti Dinar'], 'is_active' => true]);

        CurrencyRate::create(['currency_id' => $usd->id, 'exchange_rate' => '1.0000000000', 'effective_date' => '2026-01-10', 'source' => RateSource::MANUAL]);
        CurrencyRate::create(['currency_id' => $kwd->id, 'exchange_rate' => '0.3100000000', 'effective_date' => '2026-01-10', 'source' => RateSource::MANUAL]);
        CurrencyRate::create(['currency_id' => $kwd->id, 'exchange_rate' => '0.3500000000', 'effective_date' => '2026-06-01', 'source' => RateSource::MANUAL]);
    }

    private function legacyOrder(User $user, array $overrides = []): Order
    {
        $this->seq++;

        $order = Order::create(array_merge([
            'user_id' => $user->id,
            'order_number' => 'ORD-LEG-' . Str::random(8) . '-' . $this->seq,
            'name' => 'Legacy Customer',
            'user_phone' => '1234567890',
            'user_email' => 'legacy@example.com',
            'address' => 'Legacy Address',
            'status' => Order::ORDER_STATUS_COMPLETED,
            'payment_status' => Order::PAYMENT_STATUS_SUCCESS,
            'price' => 100.00,
            'total_price' => 100.00,
            // Pre-currency backfill shape: converted == total, no metadata.
            'converted_total_price' => 100.00,
            'currency_code' => null,
            'base_currency_code' => null,
            'currency_rate' => null,
            'currency_rate_date' => null,
        ], $overrides));

        // created_at is not fillable: force historical date so the command
        // must use the historical (not current) rate.
        $order->forceFill([
            'created_at' => $overrides['created_at'] ?? '2026-01-15 10:00:00',
            'updated_at' => $overrides['created_at'] ?? '2026-01-15 10:00:00',
        ])->save();

        return $order->fresh();
    }

    private function createCoupon(string $code): Coupon
    {
        $coupon = Coupon::create([
            'name' => 'Closure Coupon',
            'slug' => 'closure-' . Str::random(6),
            'discount_type' => 'percentage',
            'discount' => 10,
            'status' => true,
            'start_date' => now()->subDay(),
            'end_date' => now()->addMonth(),
        ]);
        $coupon->update(['code' => $code]);

        return $coupon->fresh();
    }

    public function test_case_a_recovers_with_historical_rate_not_current()
    {
        $this->seedRates();
        $user = User::factory()->create();
        $order = $this->legacyOrder($user);
        Transaction::create([
            'order_id' => $order->id,
            'user_id' => $user->id,
            'payment_method' => 'myfatoorah',
            'status' => 'paid',
            'amount' => 100.00,
            'currency' => 'KWD',
        ]);

        $this->artisan('coupons:remediate-legacy-currency', ['--apply' => true])->assertOk();

        $order = $order->fresh();
        // Historical Jan rate 0.31 → 100/0.31 = 322.58 (June 0.35 → 285.71).
        // rate_date stamps the CONVERSION (order) date like the live snapshot
        // flow; the rate VALUE is the historical LKG row.
        $this->assertEquals('KWD', $order->currency_code);
        $this->assertEquals('USD', $order->base_currency_code);
        $this->assertEquals(322.58, (float) $order->converted_total_price);
        $this->assertEquals('2026-01-15', $order->currency_rate_date->toDateString());

        $metrics = app(CustomerMetricsService::class)->rebuildForUser($user);
        $this->assertEquals(322.58, (float) $metrics->total_qualifying_order_value);
    }

    public function test_case_b_marks_unresolved_and_excludes_from_spend()
    {
        $user = User::factory()->create();
        // Legacy row with no reconstructable currency.
        $this->legacyOrder($user, ['total_price' => 200.00, 'converted_total_price' => 200.00]);
        // Healthy modern row.
        Order::create([
            'user_id' => $user->id,
            'order_number' => 'ORD-MOD-' . Str::random(8),
            'name' => 'Modern',
            'user_phone' => '1',
            'user_email' => 'm@example.com',
            'address' => 'A',
            'status' => Order::ORDER_STATUS_COMPLETED,
            'payment_status' => Order::PAYMENT_STATUS_SUCCESS,
            'price' => 50.00,
            'total_price' => 50.00,
            'converted_total_price' => 50.00,
            'currency_code' => 'USD',
            'base_currency_code' => 'USD',
            'currency_rate' => 1.0,
        ]);

        $this->artisan('coupons:remediate-legacy-currency', ['--apply' => true])->assertOk();

        $metrics = app(CustomerMetricsService::class)->rebuildForUser($user);
        // Unresolved legacy spend excluded; counts (currency-agnostic) kept.
        $this->assertEquals(50.00, (float) $metrics->total_qualifying_order_value);
        $this->assertEquals(2, $metrics->completed_orders);
        $this->assertEquals(1, Order::where('legacy_currency_status', 'unresolved')->count());
    }

    public function test_dry_run_writes_nothing()
    {
        $user = User::factory()->create();
        $this->legacyOrder($user);

        $this->artisan('coupons:remediate-legacy-currency')->assertOk();

        $this->assertEquals(1, Order::whereNull('currency_code')->count());
        $this->assertEquals(0, Order::where('legacy_currency_status', 'unresolved')->count());
    }

    public function test_rerun_is_idempotent_noop()
    {
        $this->seedRates();
        $user = User::factory()->create();
        $order = $this->legacyOrder($user);
        Transaction::create([
            'order_id' => $order->id,
            'user_id' => $user->id,
            'payment_method' => 'myfatoorah',
            'status' => 'paid',
            'amount' => 100.00,
            'currency' => 'KWD',
        ]);

        $this->artisan('coupons:remediate-legacy-currency', ['--apply' => true])->assertOk();
        $first = [$order->fresh()->converted_total_price, app(CustomerMetricsService::class)->rebuildForUser($user)->total_qualifying_order_value];

        $this->artisan('coupons:remediate-legacy-currency', ['--apply' => true])->assertOk();

        $this->assertEquals($first[0], $order->fresh()->converted_total_price);
        $this->assertEquals((float) $first[1], (float) app(CustomerMetricsService::class)->rebuildForUser($user)->total_qualifying_order_value);
        $this->assertEquals(0, Order::where('legacy_currency_status', 'unresolved')->count());
    }

    public function test_txn_amount_mismatch_is_unresolved_not_guessed()
    {
        $this->seedRates();
        $user = User::factory()->create();
        // Transaction in KWD but for a different amount: currency unattributable.
        $order = $this->legacyOrder($user);
        Transaction::create([
            'order_id' => $order->id,
            'user_id' => $user->id,
            'payment_method' => 'myfatoorah',
            'status' => 'paid',
            'amount' => 10.00,
            'currency' => 'KWD',
        ]);

        $this->artisan('coupons:remediate-legacy-currency', ['--apply' => true])->assertOk();

        $this->assertEquals('unresolved', $order->fresh()->legacy_currency_status);
        $this->assertEquals(100.00, (float) $order->fresh()->converted_total_price);
    }

    public function test_missing_historical_rate_is_unresolved()
    {
        $user = User::factory()->create();
        // No currency_rates seeded at all: nothing historical to convert with.
        $order = $this->legacyOrder($user);
        Transaction::create([
            'order_id' => $order->id,
            'user_id' => $user->id,
            'payment_method' => 'myfatoorah',
            'status' => 'paid',
            'amount' => 100.00,
            'currency' => 'KWD',
        ]);

        $this->artisan('coupons:remediate-legacy-currency', ['--apply' => true])->assertOk();

        $this->assertEquals('unresolved', $order->fresh()->legacy_currency_status);
    }

    public function test_sec24_cancelled_order_keeps_coupon_consumed()
    {
        // APPROVED POLICY Sec 24: coupon does NOT return after cancel/refund.
        // Structural proof: completed orders cannot transition to cancelled
        // (state machine forbids it), and usage/counters/REDEEMED stand.
        $user = User::factory()->create(['type' => 'user']);
        $coupon = $this->createCoupon('NORETURN1');
        CouponTargeting::create([
            'coupon_id' => $coupon->id,
            'mode' => 'dynamic',
            'require_claim' => true,
            'max_claims' => 5,
            'claim_ttl_hours' => 24,
        ]);
        $claim = app(CouponClaimService::class)->claim($coupon, $user);

        $order = Order::create([
            'user_id' => $user->id,
            'name' => 'NoReturn',
            'user_phone' => '01000000000',
            'user_email' => 'nr@test.com',
            'address' => '{}',
            'total_price' => 90.00,
            'price' => 100.00,
            'coupon' => $coupon->code,
            'coupon_discount' => 10,
            'status' => 'pending',
        ]);

        app(OrderService::class)->changeOrderStatus(null, 'completed', $order->id);
        app(MarkCouponClaimRedeemed::class)->handle(new PaymentSucceeded($order->fresh()));

        $usedAfterComplete = $coupon->fresh()->used;
        $this->assertDatabaseHas('coupon_usages', ['coupon_id' => $coupon->id, 'user_id' => $user->id]);
        $this->assertEquals(CouponClaimStatus::REDEEMED, $claim->fresh()->status);

        // completed → cancelled is rejected by the state machine ...
        try {
            app(OrderService::class)->changeOrderStatus(null, 'cancelled', $order->id);
            $this->fail('completed → cancelled must be rejected');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('completed', $e->getMessage());
            $this->assertEquals('completed', $order->fresh()->status);
        }

        // ... and nothing is restored: usage row, global counter, REDEEMED stand.
        $this->assertDatabaseHas('coupon_usages', ['coupon_id' => $coupon->id, 'user_id' => $user->id]);
        $this->assertEquals($usedAfterComplete, $coupon->fresh()->used);
        $this->assertEquals(CouponClaimStatus::REDEEMED, $claim->fresh()->status);
        $this->assertDatabaseMissing('coupon_reservations', ['order_id' => $order->id]);
    }

    public function test_sec24_unpaid_cancel_releases_reservation_without_usage()
    {
        // Pre-payment cancel (pending → cancelled): reservation released,
        // no usage ever created.
        $user = User::factory()->create(['type' => 'user']);
        $coupon = $this->createCoupon('NORETURN2');

        $order = Order::create([
            'user_id' => $user->id,
            'name' => 'NoReturn2',
            'user_phone' => '01000000000',
            'user_email' => 'nr2@test.com',
            'address' => '{}',
            'total_price' => 90.00,
            'price' => 100.00,
            'coupon' => $coupon->code,
            'coupon_discount' => 10,
            'status' => 'pending',
        ]);

        app(\App\Services\Coupon\CouponReservationService::class)->reserve($order, $coupon);
        app(OrderService::class)->changeOrderStatus(null, 'cancelled', $order->id);

        $this->assertDatabaseMissing('coupon_reservations', ['order_id' => $order->id]);
        $this->assertDatabaseMissing('coupon_usages', ['coupon_id' => $coupon->id, 'user_id' => $user->id]);
        $this->assertEquals(0, $coupon->fresh()->used);
    }

    public function test_sec1_gateway_charges_order_currency()
    {
        $user = User::factory()->create();
        $order = Order::create([
            'user_id' => $user->id,
            'name' => 'GW',
            'user_phone' => '01000000000',
            'user_email' => 'gw@test.com',
            'address' => '{}',
            'total_price' => 100.00,
            'price' => 100.00,
            'currency_code' => 'KWD',
            'base_currency_code' => 'USD',
            'status' => 'pending',
        ]);

        $captured = null;
        $mock = $this->mock(MyfatoraService::class, function ($m) use (&$captured) {
            $m->shouldReceive('createInvoice')->once()->withArgs(function ($data) use (&$captured) {
                $captured = $data;
                return true;
            })->andReturn(['Data' => ['InvoiceURL' => 'https://x.test/i', 'InvoiceId' => 7]]);
        });

        $result = app(MyFatoorahGateway::class)->createInvoice($order, 100.00, 'https://cb.test', 'https://err.test');

        $this->assertTrue($result->success);
        $this->assertEquals('KWD', $captured['DisplayCurrencyIso']);
        $this->assertEquals(100.00, $captured['InvoiceValue']);
    }
}
