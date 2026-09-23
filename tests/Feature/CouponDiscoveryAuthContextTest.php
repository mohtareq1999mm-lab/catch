<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Str;
use Marvel\Database\Models\Coupon;
use Marvel\Database\Models\User;
use Tests\TestCase;

/**
 * Authentication context of GET /api/v1/general/coupons.
 *
 * Regression for the "authenticated customer sees code:null" incident:
 * the backend policy was proven correct — the failing requests carried no
 * resolvable user. A presented-but-dead Bearer token now yields 401 (so
 * the client re-authenticates) instead of silently degrading to guest.
 */
class CouponDiscoveryAuthContextTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        if (!Schema::hasTable('order_status_history')) {
            Schema::create('order_status_history', function (Blueprint $table) {
                $table->id();
                $table->foreignId('order_id')->constrained('orders')->onDelete('cascade');
                $table->string('old_status')->nullable();
                $table->string('new_status');
                $table->string('old_payment_status')->nullable();
                $table->string('new_payment_status')->nullable();
                $table->string('old_fulfillment_status')->nullable();
                $table->string('new_fulfillment_status')->nullable();
                $table->foreignId('changed_by')->nullable()->constrained('users')->onDelete('set null');
                $table->string('changed_by_type')->default('user');
                $table->text('notes')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamp('changed_at');
                $table->timestamps();
            });
        }
        try {
            \Illuminate\Support\Facades\DB::statement('DROP INDEX IF EXISTS idx_orders_user_pending_unique');
        } catch (\Throwable $e) {}
    }

    private function publicCoupon(string $prefix = 'CTX'): Coupon
    {
        $code = $prefix . '-' . strtoupper(Str::random(6));

        return Coupon::create([
            'code' => $code,
            'name' => json_encode(['en' => 'Context Coupon']),
            'slug' => 'ctx-' . Str::random(6),
            'discount_type' => 'percentage',
            'discount' => 10,
            'status' => true,
            'limiter' => 100,
            'used' => 0,
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addDay()->toDateString(),
        ]);
    }

    private function rows($response): array
    {
        $response->assertOk();

        return collect($response->json('data'))->keyBy('id')->all();
    }

    /** @test Valid Bearer token resolves the customer: code visible. */
    public function valid_bearer_token_exposes_public_code()
    {
        $user = User::factory()->create(['type' => 'user']);
        $token = $user->createToken('ctx', [], now()->addWeek())->plainTextToken;
        $coupon = $this->publicCoupon();

        $rows = $this->rows(
            $this->withHeader('Authorization', 'Bearer ' . $token)
                ->getJson('/api/v1/general/coupons')
        );

        $this->assertSame($coupon->code, $rows[$coupon->id]['code']);
        $this->assertSame('public', $rows[$coupon->id]['visibility']);
    }

    /** @test Expired token → 401 (re-login signal), never silent guest shape. */
    public function expired_bearer_token_is_rejected_with_unauthorized()
    {
        $user = User::factory()->create(['type' => 'user']);
        $token = $user->createToken('ctx-expired', [], now()->addWeek())->plainTextToken;
        $user->tokens()->update(['expires_at' => now()->subDay()]);
        $this->publicCoupon();

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/general/coupons')
            ->assertUnauthorized();
    }

    /** @test Garbage token → 401, never guest shape. */
    public function invalid_bearer_token_is_rejected_with_unauthorized()
    {
        $this->publicCoupon();

        $this->withHeader('Authorization', 'Bearer dead-token-value')
            ->getJson('/api/v1/general/coupons')
            ->assertUnauthorized();
    }

    /** @test No header → guest shape with 200 (unchanged public behavior). */
    public function guest_without_header_receives_public_shape_without_codes()
    {
        $coupon = $this->publicCoupon();

        $rows = $this->rows($this->getJson('/api/v1/general/coupons'));

        $this->assertSame('public', $rows[$coupon->id]['visibility']);
        $this->assertFalse($rows[$coupon->id]['requires_claim']);
        $this->assertNull($rows[$coupon->id]['code']);
    }

    /** @test Guest and authenticated cache entries never contaminate. */
    public function guest_and_authenticated_cache_entries_stay_segregated()
    {
        $user = User::factory()->create(['type' => 'user']);
        $token = $user->createToken('ctx-cache', [], now()->addWeek())->plainTextToken;
        $coupon = $this->publicCoupon('SEG');

        $guestRows = $this->rows($this->getJson('/api/v1/general/coupons'));
        $this->assertNull($guestRows[$coupon->id]['code']);

        $authRows = $this->rows(
            $this->withHeader('Authorization', 'Bearer ' . $token)
                ->getJson('/api/v1/general/coupons')
        );
        $this->assertSame($coupon->code, $authRows[$coupon->id]['code']);

        // Fresh guest context again: withHeader() persists per test and the
        // container memoizes the guard user, so reset both to simulate the
        // per-request isolation of production PHP-FPM.
        $this->defaultHeaders = [];
        auth()->forgetGuards();

        $guestRows2 = $this->rows($this->getJson('/api/v1/general/coupons'));
        $this->assertNull($guestRows2[$coupon->id]['code']);
    }
}
