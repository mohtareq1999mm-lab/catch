<?php

namespace Tests\Feature;

use App\DTOs\GatewayResult;
use App\Services\Payment\PaymentGatewayFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Transaction;
use Marvel\Database\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use Mockery;

class SecurityRemediationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Ensure order_status_history exists for tracking tests
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
        // Ensure invoices tables exist
        if (!Schema::hasTable('invoices')) {
            Schema::create('invoices', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->unsignedBigInteger('order_id')->nullable();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('invoice_number')->nullable();
                $table->string('status')->default('pending');
                $table->string('pdf_path')->nullable();
                $table->timestamps();
            });
        }
        try {
            DB::statement('DROP INDEX IF EXISTS idx_orders_user_pending_unique');
        } catch (\Throwable $e) {}
        // Ensure permissions exist
        $this->seedPermissions();
    }

    private function seedPermissions(): void
    {
        $perms = ['view-orders', 'view-analytics', 'export-analytics', 'view-coupons', 'update-coupon', 'create-coupon', 'update-order-status'];
        foreach ($perms as $p) {
            Permission::firstOrCreate(['name' => $p, 'guard_name' => 'api']);
        }
    }

    private function makeUser(string $type = 'customer', array $permissions = []): User
    {
        $user = User::factory()->create(['type' => $type === 'admin' ? 'admin' : 'customer']);
        if (!empty($permissions)) {
            foreach ($permissions as $perm) {
                Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'api']);
                $user->givePermissionTo($perm);
            }
        }
        return $user;
    }

    private function createOrderWithTransaction(User $user, float $total = 100, string $currency = 'KWD', string $status = 'pending', string $paymentStatus = 'payment-pending'): array
    {
        $order = Order::create([
            'user_id' => $user->id,
            'name' => $user->name,
            'user_email' => $user->email,
            'user_phone' => '01000000001',
            'status' => $status,
            'payment_status' => $paymentStatus,
            'fulfillment_status' => 'pending',
            'price' => $total,
            'total_price' => $total,
            'currency_code' => $currency,
            'base_currency_code' => $currency,
            'fulfillment_type' => 'delivery',
            'payment_method' => 'online',
            'address' => ['city' => 'Cairo'],
        ]);
        DB::table('orders')->where('id', $order->id)->update(['order_number' => 'ORD-' . str_pad((string)$order->id, 8, '0', STR_PAD_LEFT)]);

        $transaction = Transaction::create([
            'order_id' => $order->id,
            'user_id' => $user->id,
            'payment_method' => 'myfatoorah',
            'gateway_transaction_id' => 'PAY-'. $order->id . '-' . uniqid(),
            'invoice_id' => 'INV-'. $order->id . '-' . uniqid(),
            'status' => 'pending',
            'amount' => $total,
            'currency' => $currency,
            'gateway_response' => ['_callback_type' => 'web'],
        ]);

        return [$order->refresh(), $transaction->refresh()];
    }

    // ========== ADMIN TRACKING ==========

    public function test_admin_tracking_requires_authentication(): void
    {
        $this->getJson('/api/v1/admin/tracking/dashboard')->assertStatus(401);
        $this->getJson('/api/v1/admin/tracking/orders')->assertStatus(401);
        $this->getJson('/api/v1/admin/tracking/requires-attention')->assertStatus(401);
    }

    public function test_customer_without_permission_denied_on_tracking(): void
    {
        $customer = $this->makeUser('customer');
        Sanctum::actingAs($customer);
        $this->getJson('/api/v1/admin/tracking/dashboard')->assertStatus(403);
        $this->getJson('/api/v1/admin/tracking/orders')->assertStatus(403);
        $this->getJson('/api/v1/admin/tracking/requires-attention')->assertStatus(403);
        $this->getJson('/api/v1/admin/tracking/orders/1')->assertStatus(403);
    }

    public function test_admin_with_view_orders_can_access_tracking(): void
    {
        $admin = $this->makeUser('admin', ['view-orders']);
        Sanctum::actingAs($admin);
        $this->getJson('/api/v1/admin/tracking/dashboard')->assertStatus(200)->assertJsonPath('success', true);
        $this->getJson('/api/v1/admin/tracking/orders')->assertStatus(200);
        $this->getJson('/api/v1/admin/tracking/requires-attention')->assertStatus(200);
    }

    public function test_admin_with_view_analytics_cannot_access_tracking(): void
    {
        $admin = $this->makeUser('admin', ['view-analytics']);
        Sanctum::actingAs($admin);
        $this->getJson('/api/v1/admin/tracking/dashboard')->assertStatus(403);
    }

    public function test_admin_with_view_analytics_can_access_tracking_removed(): void
    {
        // view-analytics must NOT grant tracking (least privilege); view-orders required
        $admin = $this->makeUser('admin', ['view-orders']);
        Sanctum::actingAs($admin);
        $this->getJson('/api/v1/admin/tracking/dashboard')->assertStatus(200);
    }

    public function test_admin_type_without_permission_denied(): void
    {
        // Type admin but no permission should be denied (strict mode)
        $adminNoPerm = User::factory()->create(['type' => 'admin']);
        Sanctum::actingAs($adminNoPerm);
        $this->getJson('/api/v1/admin/tracking/dashboard')->assertStatus(403);
    }

    public function test_unrelated_permission_denied_on_tracking(): void
    {
        $user = $this->makeUser('customer', ['view-products']);
        Sanctum::actingAs($user);
        $this->getJson('/api/v1/admin/tracking/dashboard')->assertStatus(403);
    }

    // ========== ANALYTICS ==========

    public function test_analytics_requires_authentication(): void
    {
        $this->getJson('/api/v1/admin/analytics/dashboard')->assertStatus(401);
    }

    public function test_customer_denied_on_analytics(): void
    {
        $customer = $this->makeUser('customer');
        Sanctum::actingAs($customer);
        $this->getJson('/api/v1/admin/analytics/dashboard')->assertStatus(403);
        $this->getJson('/api/v1/admin/analytics/time-series?metric=orders&period=7d')->assertStatus(403);
        $this->getJson('/api/v1/admin/analytics/performance')->assertStatus(403);
        $this->postJson('/api/v1/admin/analytics/clear-cache')->assertStatus(403);
    }

    public function test_analytics_export_denied_for_view_only(): void
    {
        $user = $this->makeUser('admin', ['view-analytics']);
        Sanctum::actingAs($user);
        // view-analytics should NOT allow export
        $this->postJson('/api/v1/admin/analytics/export/orders', [
            'date_from' => now()->subDays(7)->toDateString(),
            'date_to' => now()->toDateString(),
        ])->assertStatus(403);
    }

    public function test_export_denied_for_unauthorized(): void
    {
        $customer = $this->makeUser('customer');
        Sanctum::actingAs($customer);
        $this->postJson('/api/v1/admin/analytics/export/orders', [
            'date_from' => now()->subDays(7)->toDateString(),
            'date_to' => now()->toDateString(),
        ])->assertStatus(403);
        $this->postJson('/api/v1/admin/analytics/export/customer-ltv')->assertStatus(403);
        $this->postJson('/api/v1/admin/analytics/export/performance', [
            'date_from' => now()->subDays(7)->toDateString(),
            'date_to' => now()->toDateString(),
        ])->assertStatus(403);
    }

    public function test_authorized_export_allowed(): void
    {
        $admin = $this->makeUser('admin', ['export-analytics']);
        Sanctum::actingAs($admin);
        $resp = $this->postJson('/api/v1/admin/analytics/export/orders', [
            'date_from' => now()->subDays(7)->toDateString(),
            'date_to' => now()->toDateString(),
        ]);
        $status = $resp->baseResponse->getStatusCode();
        $this->assertNotEquals(403, $status, 'Export should not be forbidden for authorized user');
        $this->assertTrue(in_array($status, [200, 302]), 'Export should return 200 or 302 file, got ' . $status);
    }

    // ========== COUPON CONFIGURATION ==========

    public function test_coupon_config_requires_authentication(): void
    {
        $this->postJson('/api/v1/admin/coupons/validate-configuration', ['coupon_type' => 'public'])->assertStatus(401);
        $this->getJson('/api/v1/admin/coupons/1/usage-info')->assertStatus(401);
        $this->postJson('/api/v1/admin/coupons/1/suggest-fix', ['desired_behavior' => 'multi_use_per_user'])->assertStatus(401);
    }

    public function test_coupon_config_denied_for_customer(): void
    {
        $customer = $this->makeUser('customer');
        Sanctum::actingAs($customer);
        $this->postJson('/api/v1/admin/coupons/validate-configuration', ['coupon_type' => 'public'])->assertStatus(403);
        $this->getJson('/api/v1/admin/coupons/1/usage-info')->assertStatus(403);
        $this->postJson('/api/v1/admin/coupons/1/suggest-fix', ['desired_behavior' => 'single_use_per_user'])->assertStatus(403);
    }

    public function test_coupon_config_allowed_for_authorized(): void
    {
        $admin = $this->makeUser('admin', ['view-coupons']);
        Sanctum::actingAs($admin);
        $this->postJson('/api/v1/admin/coupons/validate-configuration', ['coupon_type' => 'public', 'limiter' => 10])->assertStatus(200)->assertJsonPath('success', true);

        // Create a coupon for usage-info test
        $coupon = \Marvel\Database\Models\Coupon::create([
            'code' => 'TEST' . uniqid(),
            'name' => 'Test Coupon',
            'slug' => 'test-' . uniqid(),
            'discount_type' => 'percentage',
            'discount' => 10,
            'status' => true,
            'start_date' => now()->subDay(),
            'end_date' => now()->addMonth(),
        ]);
        $this->getJson('/api/v1/admin/coupons/' . $coupon->id . '/usage-info')->assertStatus(200);
        $this->postJson('/api/v1/admin/coupons/' . $coupon->id . '/suggest-fix', ['desired_behavior' => 'single_use_per_user'])->assertStatus(200);
    }

    // ========== PAYMENT CALLBACKS ==========

    public function test_payment_callback_rejects_malformed_payment_id(): void
    {
        $this->getJson('/api/v1/general/checkout/callback?paymentId=bad%20id%20with%20spaces')->assertStatus(400);
        $this->getJson('/api/v1/general/checkout/callback?paymentId=')->assertStatus(400);
        // Too long
        $this->getJson('/api/v1/general/checkout/callback?paymentId=' . str_repeat('a', 200))->assertStatus(400);
    }

    public function test_payment_callback_rejects_invalid_callback_type(): void
    {
        $user = $this->makeUser('customer');
        [$order, $tx] = $this->createOrderWithTransaction($user);
        $this->mockGatewaySuccess($tx->gateway_transaction_id, (float)$order->total_price, 'KWD');
        $this->getJson('/api/v1/general/checkout/callback?paymentId=' . $tx->gateway_transaction_id . '&type=evil')->assertStatus(400);
    }

    public function test_payment_callback_amount_mismatch_blocked(): void
    {
        $orig = config('services.myfatoorah.base_url');
        config(['services.myfatoorah.base_url' => 'https://api.myfatoorah.com/v2/']);
        try {
            $user = $this->makeUser('customer');
            [$order, $tx] = $this->createOrderWithTransaction($user, 100, 'KWD');
            $this->mockGatewaySuccess($tx->gateway_transaction_id, 1.00, 'KWD');
            $resp = $this->getJson('/api/v1/general/checkout/callback?paymentId=' . $tx->gateway_transaction_id);
            $resp->assertStatus(302);
            $this->assertStringContainsString('/payment/failed', $resp->headers->get('Location') ?? '');
            $order->refresh();
            $this->assertNotEquals('completed', $order->status, 'Order should not be completed on amount mismatch');
            $tx->refresh();
            $this->assertEquals('failed', $tx->status, 'Transaction should be failed on amount mismatch');
            $this->assertEquals('pending', $order->status);
        } finally {
            config(['services.myfatoorah.base_url' => $orig]);
        }
    }

    public function test_payment_callback_currency_mismatch_blocked(): void
    {
        $orig = config('services.myfatoorah.base_url');
        config(['services.myfatoorah.base_url' => 'https://api.myfatoorah.com/v2/']);
        try {
            $user = $this->makeUser('customer');
            [$order, $tx] = $this->createOrderWithTransaction($user, 100, 'KWD');
            $this->mockGatewaySuccess($tx->gateway_transaction_id, 100, 'USD');
            $resp = $this->getJson('/api/v1/general/checkout/callback?paymentId=' . $tx->gateway_transaction_id);
            $resp->assertStatus(302);
            $this->assertStringContainsString('/payment/failed', $resp->headers->get('Location') ?? '');
            $order->refresh();
            $this->assertNotEquals('completed', $order->status);
            $tx->refresh();
            $this->assertEquals('failed', $tx->status);
        } finally {
            config(['services.myfatoorah.base_url' => $orig]);
        }
    }

    public function test_payment_callback_test_gateway_bypass_blocked_in_production(): void
    {
        $originalEnv = app()->environment();
        $originalBaseUrl = config('services.myfatoorah.base_url');
        try {
            config(['services.myfatoorah.base_url' => 'https://apitest.myfatoorah.com/v2/']);
            // Simulate production
            app()['env'] = 'production';
            $user = $this->makeUser('customer');
            [$order, $tx] = $this->createOrderWithTransaction($user, 100, 'KWD');
            $this->mockGatewaySuccess($tx->gateway_transaction_id, 999, 'KWD');
            $resp = $this->getJson('/api/v1/general/checkout/callback?paymentId=' . $tx->gateway_transaction_id);
            $resp->assertStatus(302);
            $this->assertStringContainsString('/payment/failed', $resp->headers->get('Location') ?? '');
            $order->refresh();
            $this->assertNotEquals('completed', $order->status);
            $tx->refresh();
            $this->assertEquals('failed', $tx->status);
        } finally {
            app()['env'] = $originalEnv;
            config(['services.myfatoorah.base_url' => $originalBaseUrl]);
        }
    }

    public function test_payment_callback_test_gateway_allowed_in_non_production(): void
    {
        $originalBaseUrl = config('services.myfatoorah.base_url');
        try {
            config(['services.myfatoorah.base_url' => 'https://apitest.myfatoorah.com/v2/']);
            // Ensure not production
            app()['env'] = 'testing';
            $user = $this->makeUser('customer');
            [$order, $tx] = $this->createOrderWithTransaction($user, 100, 'KWD');
            $this->mockGatewaySuccess($tx->gateway_transaction_id, 999, 'KWD');
            $resp = $this->getJson('/api/v1/general/checkout/callback?paymentId=' . $tx->gateway_transaction_id);
            $resp->assertStatus(302);
            $this->assertStringContainsString('/payment/success', $resp->headers->get('Location') ?? '');
        } finally {
            config(['services.myfatoorah.base_url' => $originalBaseUrl]);
            app()['env'] = 'testing';
        }
    }

    public function test_payment_callback_duplicate_is_idempotent(): void
    {
        $user = $this->makeUser('customer');
        [$order, $tx] = $this->createOrderWithTransaction($user, 100, 'KWD');
        $this->mockGatewaySuccess($tx->gateway_transaction_id, 100, 'KWD');
        $first = $this->getJson('/api/v1/general/checkout/callback?paymentId=' . $tx->gateway_transaction_id);
        $first->assertStatus(302);
        $this->assertStringContainsString('/payment/success', $first->headers->get('Location') ?? '');
        $order->refresh();
        $firstStatus = $order->status;
        // Duplicate
        $this->mockGatewaySuccess($tx->gateway_transaction_id, 100, 'KWD');
        $second = $this->getJson('/api/v1/general/checkout/callback?paymentId=' . $tx->gateway_transaction_id);
        $second->assertStatus(302);
        $order->refresh();
        $this->assertEquals($firstStatus, $order->status, 'Duplicate callback should be idempotent');
        $this->assertEquals('completed', $order->status);
        // Ensure only one completed transition (check transaction still paid)
        $tx->refresh();
        $this->assertEquals('paid', $tx->status);
    }

    public function test_payment_callback_invalid_gateway_verification(): void
    {
        $user = $this->makeUser('customer');
        [$order, $tx] = $this->createOrderWithTransaction($user, 100, 'KWD');
        $this->mockGatewayFailure($tx->gateway_transaction_id);
        $resp = $this->getJson('/api/v1/general/checkout/callback?paymentId=' . $tx->gateway_transaction_id);
        $resp->assertStatus(302);
        $this->assertStringContainsString('/payment/failed', $resp->headers->get('Location') ?? '');
        $order->refresh();
        $this->assertNotEquals('completed', $order->status);
        $tx->refresh();
        $this->assertEquals('failed', $tx->status);
    }

    public function test_payment_callback_valid_payment_succeeds(): void
    {
        $user = $this->makeUser('customer');
        [$order, $tx] = $this->createOrderWithTransaction($user, 100, 'KWD');
        $this->mockGatewaySuccess($tx->gateway_transaction_id, 100, 'KWD');
        $resp = $this->getJson('/api/v1/general/checkout/callback?paymentId=' . $tx->gateway_transaction_id);
        $resp->assertStatus(302);
        $this->assertStringContainsString('/payment/success', $resp->headers->get('Location') ?? '');
        $order->refresh();
        $this->assertEquals('completed', $order->status);
        $tx->refresh();
        $this->assertEquals('paid', $tx->status);
    }

    public function test_payment_callback_null_amount_is_blocked(): void
    {
        $orig = config('services.myfatoorah.base_url');
        config(['services.myfatoorah.base_url' => 'https://api.myfatoorah.com/v2/']);
        try {
            $user = $this->makeUser('customer');
            [$order, $tx] = $this->createOrderWithTransaction($user, 100, 'KWD');
            $this->mockGatewaySuccessWithNull($tx->gateway_transaction_id, null, 'KWD');
            $resp = $this->getJson('/api/v1/general/checkout/callback?paymentId=' . $tx->gateway_transaction_id);
            $resp->assertStatus(302);
            $this->assertStringContainsString('/payment/failed', $resp->headers->get('Location') ?? '');
            $order->refresh();
            $this->assertNotEquals('completed', $order->status);
            $tx->refresh();
            $this->assertEquals('failed', $tx->status);
        } finally {
            config(['services.myfatoorah.base_url' => $orig]);
        }
    }

    public function test_payment_callback_null_currency_is_blocked(): void
    {
        $orig = config('services.myfatoorah.base_url');
        config(['services.myfatoorah.base_url' => 'https://api.myfatoorah.com/v2/']);
        try {
            $user = $this->makeUser('customer');
            [$order, $tx] = $this->createOrderWithTransaction($user, 100, 'KWD');
            $this->mockGatewaySuccessWithNull($tx->gateway_transaction_id, 100, null);
            $resp = $this->getJson('/api/v1/general/checkout/callback?paymentId=' . $tx->gateway_transaction_id);
            $resp->assertStatus(302);
            $this->assertStringContainsString('/payment/failed', $resp->headers->get('Location') ?? '');
            $order->refresh();
            $this->assertNotEquals('completed', $order->status);
            $tx->refresh();
            $this->assertEquals('failed', $tx->status);
        } finally {
            config(['services.myfatoorah.base_url' => $orig]);
        }
    }

    public function test_payment_callback_cents_precision_blocks_one_cent_underpayment(): void
    {
        $orig = config('services.myfatoorah.base_url');
        config(['services.myfatoorah.base_url' => 'https://api.myfatoorah.com/v2/']);
        try {
            $user = $this->makeUser('customer');
            [$order, $tx] = $this->createOrderWithTransaction($user, 100.00, 'KWD');
            // 1 cent underpayment
            $this->mockGatewaySuccess($tx->gateway_transaction_id, 99.99, 'KWD');
            $resp = $this->getJson('/api/v1/general/checkout/callback?paymentId=' . $tx->gateway_transaction_id);
            $resp->assertStatus(302);
            $this->assertStringContainsString('/payment/failed', $resp->headers->get('Location') ?? '');
            $order->refresh();
            $this->assertNotEquals('completed', $order->status);
        } finally {
            config(['services.myfatoorah.base_url' => $orig]);
        }
    }

    public function test_payment_callback_payment_id_boundaries(): void
    {
        // 191 chars valid, 192 invalid, invalid chars, hyphen/underscore allowed, injection blocked
        $valid191 = str_repeat('a', 191);
        $invalid192 = str_repeat('a', 192);
        $this->assertNotEquals(400, $this->getJson('/api/v1/general/checkout/callback?paymentId=' . $valid191)->status(), '191 chars should pass validation');
        $this->getJson('/api/v1/general/checkout/callback?paymentId=' . $invalid192)->assertStatus(400);
        $this->assertNotEquals(400, $this->getJson('/api/v1/general/checkout/callback?paymentId=valid-id_123')->status(), 'hyphen/underscore should be allowed');
        $this->getJson('/api/v1/general/checkout/callback?paymentId=../etc/passwd')->assertStatus(400);
        $this->getJson('/api/v1/general/checkout/callback?paymentId=bad;DROP')->assertStatus(400);
        $this->getJson('/api/v1/general/checkout/callback?paymentId=')->assertStatus(400);
    }

    // ========== RATE LIMITING (smoke) ==========

    public function test_rate_limiters_are_configured(): void
    {
        $limiters = ['public-api', 'authenticated', 'admin', 'payment-callback', 'public-tracking'];
        foreach ($limiters as $name) {
            $this->assertTrue(
                \Illuminate\Support\Facades\RateLimiter::limiter($name) !== null,
                "Limiter $name should be defined"
            );
        }
    }

    public function test_signed_invoice_requires_signature(): void
    {
        $this->getJson('/api/v1/general/invoices/view/' . \Illuminate\Support\Str::uuid())->assertStatus(403);
        $this->getJson('/api/v1/general/invoices/download/' . \Illuminate\Support\Str::uuid())->assertStatus(403);
    }

    // Helpers

    private function mockGatewaySuccess(string $paymentId, float $amount, ?string $currency): void
    {
        $mockGateway = Mockery::mock(\App\Services\Gateway\MyFatoorahGateway::class);
        $mockGateway->shouldReceive('verifyPayment')->with($paymentId)->andReturn(new GatewayResult(
            success: true,
            gatewayTransactionId: $paymentId,
            amount: $amount,
            currency: $currency,
            status: 'paid',
            rawResponse: ['Data' => ['InvoiceId' => $paymentId, 'InvoiceStatus' => 'Paid', 'InvoiceValue' => $amount, 'DisplayCurrencyIso' => $currency]],
        ));
        $mockFactory = Mockery::mock(PaymentGatewayFactory::class);
        $mockFactory->shouldReceive('make')->andReturn($mockGateway);
        $this->app->instance(PaymentGatewayFactory::class, $mockFactory);
    }

    private function mockGatewaySuccessWithNull(string $paymentId, ?float $amount, ?string $currency): void
    {
        $mockGateway = Mockery::mock(\App\Services\Gateway\MyFatoorahGateway::class);
        $mockGateway->shouldReceive('verifyPayment')->with($paymentId)->andReturn(new GatewayResult(
            success: true,
            gatewayTransactionId: $paymentId,
            amount: $amount,
            currency: $currency,
            status: 'paid',
            rawResponse: ['Data' => ['InvoiceId' => $paymentId, 'InvoiceStatus' => 'Paid', 'InvoiceValue' => $amount, 'DisplayCurrencyIso' => $currency]],
        ));
        $mockFactory = Mockery::mock(PaymentGatewayFactory::class);
        $mockFactory->shouldReceive('make')->andReturn($mockGateway);
        $this->app->instance(PaymentGatewayFactory::class, $mockFactory);
    }

    private function mockGatewayFailure(string $paymentId): void
    {
        $mockGateway = Mockery::mock(\App\Services\Gateway\MyFatoorahGateway::class);
        $mockGateway->shouldReceive('verifyPayment')->with($paymentId)->andReturn(new GatewayResult(
            success: false,
            gatewayTransactionId: $paymentId,
            status: 'failed',
            errorMessage: 'Payment not completed',
            rawResponse: ['Data' => ['InvoiceStatus' => 'Failed']],
        ));
        $mockFactory = Mockery::mock(PaymentGatewayFactory::class);
        $mockFactory->shouldReceive('make')->andReturn($mockGateway);
        $this->app->instance(PaymentGatewayFactory::class, $mockFactory);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        try { $this->app->forgetInstance(PaymentGatewayFactory::class); } catch (\Throwable $e) {}
        parent::tearDown();
    }
}
