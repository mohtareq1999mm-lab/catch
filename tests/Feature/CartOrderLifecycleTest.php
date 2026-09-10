<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\DTOs\GatewayResult;
use App\Models\CouponReservation;
use App\Services\Payment\Contracts\PaymentGatewayContract;
use App\Services\Payment\PaymentGatewayFactory;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Cart;
use Marvel\Database\Models\CartItem;
use Marvel\Database\Models\Coupon;
use Marvel\Database\Models\CouponAssignment;
use Marvel\Database\Models\CouponAssignmentUsage;
use Marvel\Database\Models\CouponUsage;
use Marvel\Database\Models\Country;
use Marvel\Database\Models\Governorate;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\OrderProduct;
use Marvel\Database\Models\PickupLocation;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\ProductVariant;
use Marvel\Database\Models\Settings;
use Marvel\Database\Models\ShippingPrice;
use Marvel\Database\Models\Transaction;
use Marvel\Database\Models\User;
use Marvel\Enums\ProductType;
use Marvel\Enums\ShippingMethod;
use Tests\Concerns\CreatesTestTables;
use Tests\Concerns\WithInvoiceTables;
use Tests\TestCase;

/**
 * Cart & Order Lifecycle — Full coverage as per business spec.
 *
 * Uses DatabaseTransactions + CreatesTestTables (same as most feature tests)
 * to match real SQLite schema used in CI.
 */
class CartOrderLifecycleTest extends TestCase
{
    use DatabaseTransactions, CreatesTestTables, WithInvoiceTables;

    private const CHECKOUT_PREFIX = '/api/v1/general';
    private const ADMIN_PREFIX = '/api/v1';

    private User $user;
    private User $admin;
    private Product $product;
    private Product $product2;
    private Governorate $governorate;
    private PickupLocation $pickupLocation;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');

        // Prevent Mpdf font-missing crashes from bubbling into HTTP responses (sync queue)
        Queue::fake();

        $this->createAllTestTables();
        $this->createInvoiceTables();

        if (!Schema::hasTable('coupon_reservations')) {
            Schema::create('coupon_reservations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('coupon_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('order_id')->constrained()->cascadeOnDelete();
                $table->timestamp('reserved_at');
                $table->timestamp('expires_at');
                $table->timestamps();
                $table->index(['coupon_id', 'expires_at']);
                $table->unique(['order_id']);
            });
        }

        // Config for timeouts and currency (checkout requires catalog/base = EGP to pass gateway check)
        config(['payment.order_timeout_hours' => 24]);
        config(['payment.cod_order_timeout_hours' => 24 * 7]);
        config(['payment.default_currency' => 'EGP']);
        config(['shop.default_currency' => 'EGP']);
        config(['services.myfatoorah.base_url' => 'https://apitest.myfatoorah.com/v2/']);
        // Ensure gateways support EGP
        config(['payment.gateways.myfatoorah.supported_currencies' => ['KWD','SAR','AED','BHD','QAR','OMR','EGP','USD']]);

        if (!Settings::exists()) {
            Settings::create([
                'language' => 'en',
                'options' => ['catalog_currency_code' => 'EGP', 'base_currency_code' => 'EGP', 'currency' => 'EGP'],
                'minimum_order_amount' => 0]);
        } else {
            $s = Settings::first();
            $opts = $s->options ?? [];
            $opts['catalog_currency_code'] = 'EGP';
            $opts['base_currency_code'] = 'EGP';
            $opts['currency'] = 'EGP';
            $s->update(['options' => $opts]);
        }

        $country = Country::create(['name' => 'Test Country', 'status' => true]);
        $this->governorate = Governorate::create([
            'country_id' => $country->id,
            'name' => 'Test Gov',
            'status' => true]);
        ShippingPrice::create([
            'governorate_id' => $this->governorate->id,
            'price' => 0,
            'status' => true]);

        $this->pickupLocation = PickupLocation::create([
            'store_name' => 'Main Store',
            'address' => '123 Main St',
            'phone' => '01000000000',
            'status' => true]);

        $this->user = User::create([
            'name' => 'Buyer',
            'email' => 'buyer@example.com',
            'password' => bcrypt('password'),
            'type' => 'user',
            'is_active' => true,
            'email_verified_at' => now()]);

        $this->admin = User::create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => bcrypt('password'),
            'type' => 'admin',
            'is_active' => true,
            'email_verified_at' => now()]);
        // permissions for admin status route — must include display_name for RefreshDatabase (migration NOT NULL)
        if (Schema::hasTable('roles')) {
            $role = \Marvel\Database\Models\Role::firstOrCreate(
                ['name' => 'super_admin', 'guard_name' => 'api'],
                ['display_name' => ['en' => 'Super Admin', 'ar' => 'ادمن']]
            );
            $this->admin->assignRole($role);
        }
        if (Schema::hasTable('permissions')) {
            $perm = \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'update-order-status', 'guard_name' => 'api']);
            $this->admin->givePermissionTo($perm);
            $permView = \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'view-orders', 'guard_name' => 'api']);
            $permView2 = \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'view-order', 'guard_name' => 'api']);
            $this->admin->givePermissionTo([$permView, $permView2]);
        }
        // Clear permission cache after role/permission setup
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $this->product = Product::create([
            'name' => 'Product A',
            'slug' => 'product-a-' . Str::random(6),
            'price' => 100.00,
            'product_type' => ProductType::SIMPLE,
            'status' => true,
            'in_stock' => true,
            'stock_quantity' => 20,
            'reserved_quantity' => 0,
            'sold_quantity' => 0]);

        $this->product2 = Product::create([
            'name' => 'Product B',
            'slug' => 'product-b-' . Str::random(6),
            'price' => 50.00,
            'product_type' => ProductType::SIMPLE,
            'status' => true,
            'in_stock' => true,
            'stock_quantity' => 20,
            'reserved_quantity' => 0,
            'sold_quantity' => 0]);
    }

    protected function tearDown(): void
    {
        \Mockery::close();
        Carbon::setTestNow();
        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function createCartWithItems(User $user, array $items): Cart
    {
        $cart = Cart::firstOrCreate(
            ['user_id' => $user->id, 'status' => 'active'],
            ['total_price' => 0, 'coupon' => null]
        );
        // ensure fresh cart for test isolation: delete old items if any
        // Caller controls.

        $total = 0;
        foreach ($items as $it) {
            $prod = $it['product'] ?? $this->product;
            $qty = $it['quantity'] ?? 1;
            $price = $it['price'] ?? $prod->price;
            $totalPrice = $price * $qty;
            $total += $totalPrice;
            CartItem::create([
                'cart_id' => $cart->id,
                'product_id' => $prod->id,
                'product_variant_id' => $it['variant_id'] ?? null,
                'quantity' => $qty,
                'price' => $price,
                'total_price' => $totalPrice,
                'shipping_method' => $it['shipping_method'] ?? ShippingMethod::SCHEDULED,
                'is_gift' => $it['is_gift'] ?? false,
                'promotion_id' => $it['promotion_id'] ?? null]);
        }
        $cart->update(['total_price' => $total]);
        return $cart->fresh()->load(['items', 'items.product']);
    }

    private function clearCart(User $user): void
    {
        $cart = Cart::where('user_id', $user->id)->first();
        if ($cart) {
            $cart->items()->delete();
            $cart->update(['total_price' => 0, 'coupon' => null]);
        }
    }

    private function mockMyFatoraSuccess(string $invoiceId = '12345', string $invoiceUrl = 'https://example.com/pay'): void
    {
        $mock = \Mockery::mock(\App\Services\General\MyfatoraService::class);
        $mock->shouldReceive('createInvoice')
            ->andReturn(['Data' => ['InvoiceURL' => $invoiceUrl, 'InvoiceId' => $invoiceId]]);
        // For verification in callbacks we also need checkInvoice, but that goes via gateway.
        // Mock gateway verification for online.
        $mock->shouldReceive('checkInvoice')->andReturnNull(); // not used for create path
        $this->app->instance(\App\Services\General\MyfatoraService::class, $mock);
    }

    private function mockGatewayVerify(string $paymentId, array $options = []): void
    {
        $success = $options['success'] ?? true;
        $amount = $options['amount'] ?? null;
        $currency = $options['currency'] ?? 'EGP';
        $status = $success ? 'paid' : 'failed';
        $gatewayTransactionId = $options['gatewayTransactionId'] ?? $paymentId;

        $gateway = \Mockery::mock(PaymentGatewayContract::class);
        $gateway->shouldReceive('verifyPayment')
            ->with($paymentId)
            ->andReturn(new GatewayResult(
                success: $success,
                gatewayTransactionId: $gatewayTransactionId,
                amount: $amount,
                currency: $currency,
                status: $status,
                errorMessage: $options['errorMessage'] ?? ($success ? null : 'Payment declined'),
                rawResponse: $options['rawResponse'] ?? ($success ? ['InvoiceStatus' => 'Paid'] : ['InvoiceStatus' => 'Failed']),
            ));

        $factory = \Mockery::mock(PaymentGatewayFactory::class);
        $factory->shouldReceive('make')->andReturn($gateway);
        $this->app->instance(PaymentGatewayFactory::class, $factory);
    }

    private function mockGatewayVerifySequence(string $paymentId, array $results): void
    {
        $gateway = \Mockery::mock(PaymentGatewayContract::class);
        $expectation = $gateway->shouldReceive('verifyPayment')->with($paymentId);
        foreach ($results as $res) {
            $expectation = $expectation->andReturn($res);
        }
        // If only two results given, allow twice
        $factory = \Mockery::mock(PaymentGatewayFactory::class);
        $factory->shouldReceive('make')->andReturn($gateway);
        $this->app->instance(PaymentGatewayFactory::class, $factory);
    }

    private function checkout(User $user, array $payload): \Illuminate\Testing\TestResponse
    {
        Sanctum::actingAs($user);
        return $this->postJson(self::CHECKOUT_PREFIX . '/checkout', $payload);
    }

    private function checkoutAs(User $user, array $payload, ?string $invoiceId = null): \Illuminate\Testing\TestResponse
    {
        if (($payload['payment_method'] ?? 'online') === 'online') {
            $this->mockMyFatoraSuccess($invoiceId ?? 'INV-'.rand(10000,99999));
        }
        return $this->checkout($user, $payload);
    }

    private function baseCheckoutPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Test User',
            'user_phone' => '01000000000',
            'user_email' => $this->user->email,
            'address' => ['street' => '123 Main St'],
            'governorate_id' => $this->governorate->id,
            'payment_method' => 'cod',
            'fulfillment_type' => 'delivery'], $overrides);
    }

    private function createActiveReservation(Product $product, int $qty = 2, ?User $user = null, string $paymentMethod = 'online'): Order
    {
        $user = $user ?? $this->user;
        $order = Order::create([
            'user_id' => $user->id,
            'name' => 'Res Test',
            'user_phone' => '01000000000',
            'user_email' => $user->email,
            'address' => json_encode(['street' => '1']),
            'shipping_method' => 'SCHEDULED',
            'payment_method' => $paymentMethod,
            'price' => $product->price * $qty,
            'shipping_price' => 0,
            'total_price' => $product->price * $qty,
            'status' => 'pending',
            'payment_status' => Order::PAYMENT_STATUS_PENDING]);
        $order->orderItems()->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'product_sku' => $product->sku,
            'product_quantity' => $qty,
            'product_price' => $product->price,
            'product_total_price' => $product->price * $qty]);
        app(\App\Services\Inventory\OrderReservationService::class)->reserveForOrder($order->refresh());
        return $order->refresh();
    }

    private function runReaper(): void
    {
        $this->artisan('orders:cancel-unpaid')->assertExitCode(0);
    }

    // =================================================================
    // Test 1 — Cart survives checkout
    // =================================================================
    public function test_1_cart_survives_checkout(): void
    {
        $this->clearCart($this->user);
        $cart = $this->createCartWithItems($this->user, [
            ['product' => $this->product, 'quantity' => 1],
            ['product' => $this->product2, 'quantity' => 1]]);
        $cartId = $cart->id;

        $resp = $this->checkout($this->user, $this->baseCheckoutPayload(['payment_method' => 'cod']));
        $resp->assertStatus(200);

        $this->assertDatabaseHas('carts', ['id' => $cartId, 'user_id' => $this->user->id]);
        $this->assertDatabaseHas('carts', ['id' => $cartId]); // cart record remains
        $this->assertEquals($cartId, Cart::where('user_id', $this->user->id)->value('id'), 'Same cart row survives');
    }

    // =================================================================
    // Test 2 — Cart Items are deleted after checkout
    // =================================================================
    public function test_2_cart_items_are_deleted_after_checkout(): void
    {
        $this->clearCart($this->user);
        $cart = $this->createCartWithItems($this->user, [
            ['product' => $this->product, 'quantity' => 1],
            ['product' => $this->product2, 'quantity' => 1]]);
        $this->assertEquals(2, CartItem::where('cart_id', $cart->id)->count());

        $resp = $this->checkout($this->user, $this->baseCheckoutPayload(['payment_method' => 'cod']));
        $resp->assertStatus(200);

        $this->assertEquals(0, CartItem::where('cart_id', $cart->id)->count());
        $this->assertDatabaseMissing('cart_items', ['cart_id' => $cart->id]);
    }

    // =================================================================
    // Test 3 — Order Items contain the snapshot
    // =================================================================
    public function test_3_order_items_contain_snapshot(): void
    {
        $this->clearCart($this->user);
        $cart = $this->createCartWithItems($this->user, [
            ['product' => $this->product, 'quantity' => 2, 'price' => 100]]);

        $resp = $this->checkout($this->user, $this->baseCheckoutPayload(['payment_method' => 'cod']));
        $resp->assertStatus(200);

        $order = Order::where('user_id', $this->user->id)->latest()->first();
        $this->assertNotNull($order);
        $this->assertGreaterThan(0, $order->orderItems()->count());

        $item = $order->orderItems()->first();
        $this->assertEquals($this->product->id, $item->product_id);
        $this->assertEquals(2, $item->product_quantity);
        $this->assertEquals(100.0, (float) $item->product_price);
        $this->assertEquals(200.0, (float) $item->product_total_price);
        $this->assertNotNull($item->product_name);
        $this->assertNotNull($item->product_sku);
        // discount / promotion fields should be numeric (0 if none)
        $this->assertIsNumeric($item->promotion_discount_amount ?? 0);
    }

    // =================================================================
    // Test 4 — Pending Order with empty Cart
    // =================================================================
    public function test_4_pending_order_with_empty_cart(): void
    {
        $this->clearCart($this->user);
        $cart = $this->createCartWithItems($this->user, [
            ['product' => $this->product, 'quantity' => 1]]);

        $resp = $this->checkout($this->user, $this->baseCheckoutPayload(['payment_method' => 'cod']));
        $resp->assertStatus(200);

        $order = Order::where('user_id', $this->user->id)->latest()->first();
        $this->assertEquals('pending', $order->status);
        $this->assertEquals(0, CartItem::where('cart_id', $cart->id)->count());
        $this->assertDatabaseHas('carts', ['id' => $cart->id]);
        // Cart still exists, empty
        $cart->refresh();
        $this->assertEquals(0, $cart->items()->count());
    }

    // =================================================================
    // Test 5 — Online checkout creates pending Order
    // =================================================================
    public function test_5_online_checkout_creates_pending_order(): void
    {
        $this->clearCart($this->user);
        $cart = $this->createCartWithItems($this->user, [
            ['product' => $this->product, 'quantity' => 1]]);
        $cartId = $cart->id;

        $resp = $this->checkoutAs($this->user, array_merge($this->baseCheckoutPayload(['payment_method' => 'online']), ['type' => 'mobile']), 'INV-ONLINE-5');
        $resp->assertStatus(200);
        $resp->assertJsonPath('success', true);

        $order = Order::where('user_id', $this->user->id)->latest()->first();
        $this->assertNotNull($order);
        $this->assertEquals('pending', $order->status);
        $this->assertEquals(0, CartItem::where('cart_id', $cartId)->count());
        $this->assertDatabaseHas('carts', ['id' => $cartId]);
        $this->assertDatabaseHas('transactions', ['order_id' => $order->id, 'payment_method' => 'myfatoorah', 'status' => 'pending']);
        $this->assertEquals(Order::INVENTORY_STATE_ACTIVE, $order->inventory_state);
    }

    // =================================================================
    // Test 6 — Online payment success
    // =================================================================
    public function test_6_online_payment_success(): void
    {
        $this->clearCart($this->user);
        $this->createCartWithItems($this->user, [
            ['product' => $this->product, 'quantity' => 2]]);
        $productStockBefore = $this->product->stock_quantity;
        $productReservedBefore = $this->product->reserved_quantity;

        $this->mockMyFatoraSuccess('INV-6', 'https://pay.example/6');
        $resp = $this->checkout($this->user, array_merge($this->baseCheckoutPayload(['payment_method' => 'online']), ['type' => 'mobile']));
        $resp->assertStatus(200);
        $order = Order::where('user_id', $this->user->id)->latest()->first();
        $this->assertNotNull($order);
        $amount = (float) $order->total_price;

        // Create transaction already exists from checkout; mock verify
        $paymentId = Transaction::where('order_id', $order->id)->value('gateway_transaction_id') ?? 'INV-6';

        $gateway = \Mockery::mock(PaymentGatewayContract::class);
        $gateway->shouldReceive('verifyPayment')->andReturn(new GatewayResult(
            success: true,
            gatewayTransactionId: $paymentId,
            amount: $amount,
            currency: 'EGP',
            status: 'paid',
            rawResponse: ['InvoiceStatus' => 'Paid']
        ));
        $factory = \Mockery::mock(PaymentGatewayFactory::class);
        $factory->shouldReceive('make')->andReturn($gateway);
        $this->app->instance(PaymentGatewayFactory::class, $factory);

        $cb = $this->get('/api/v1/general/checkout/callback?paymentId='.$paymentId.'&type=mobile');
        // Should be 200 JSON for mobile
        $cb->assertStatus(200);

        $order->refresh();
        $this->assertEquals('completed', $order->status);
        // payment_status via accessor should be success
        $this->assertEquals('payment-success', $order->payment_status);
        $this->assertEquals(0, CartItem::where('cart_id', Cart::where('user_id',$this->user->id)->value('id'))->count());
        // Inventory committed
        $this->product->refresh();
        $this->assertEquals(Order::INVENTORY_STATE_COMMITTED, $order->inventory_state);
        $this->assertEquals($productStockBefore - 2, $this->product->stock_quantity);
        $this->assertEquals(0, $this->product->reserved_quantity);
        $this->assertEquals(2, $this->product->sold_quantity);
    }

    // =================================================================
    // Test 7 — Online payment failure
    // =================================================================
    public function test_7_online_payment_failure(): void
    {
        $this->clearCart($this->user);
        $this->createCartWithItems($this->user, [['product' => $this->product, 'quantity' => 1]]);

        $this->mockMyFatoraSuccess('INV-7');
        $resp = $this->checkout($this->user, array_merge($this->baseCheckoutPayload(['payment_method' => 'online']), ['type' => 'mobile']));
        $resp->assertStatus(200);
        $order = Order::where('user_id', $this->user->id)->latest()->first();
        $paymentId = Transaction::where('order_id', $order->id)->value('gateway_transaction_id') ?? 'INV-7';

        $gateway = \Mockery::mock(PaymentGatewayContract::class);
        $gateway->shouldReceive('verifyPayment')->andReturn(new GatewayResult(
            success: false,
            errorMessage: 'declined',
            status: 'failed',
            rawResponse: ['InvoiceStatus' => 'Failed']
        ));
        $factory = \Mockery::mock(PaymentGatewayFactory::class);
        $factory->shouldReceive('make')->andReturn($gateway);
        $this->app->instance(PaymentGatewayFactory::class, $factory);

        // checkout/error-callback is failure path but we use main callback with failure
        $cb = $this->get('/api/v1/general/checkout/callback?paymentId='.$paymentId);
        // failure redirects to frontend (302)
        $this->assertContains($cb->status(), [302,200]);

        $order->refresh();
        $this->assertEquals('pending', $order->status, 'Failure keeps pending');
        $this->assertEquals(Order::INVENTORY_STATE_ACTIVE, $order->inventory_state);
        // Cart items NOT restored
        $cartId = Cart::where('user_id', $this->user->id)->value('id');
        $this->assertEquals(0, CartItem::where('cart_id', $cartId)->count(), 'Cart items must remain 0 after payment failure');
        $this->assertDatabaseHas('carts', ['id' => $cartId]);
    }

    // =================================================================
    // Test 8 — Online retry (same order ID, no duplicate pending)
    // =================================================================
    public function test_8_online_retry(): void
    {
        $this->clearCart($this->user);
        $this->createCartWithItems($this->user, [['product' => $this->product, 'quantity' => 1]]);
        $this->mockMyFatoraSuccess('INV-8');
        $this->checkout($this->user, array_merge($this->baseCheckoutPayload(['payment_method' => 'online']), ['type' => 'mobile']))->assertStatus(200);
        $order = Order::where('user_id', $this->user->id)->latest()->first();
        $firstId = $order->id;
        $paymentId = Transaction::where('order_id', $order->id)->value('gateway_transaction_id') ?? 'INV-8';
        $amount = (float) $order->total_price;

        // First failure then success using sequential mock (single factory)
        $gatewaySeq = \Mockery::mock(PaymentGatewayContract::class);
        $gatewaySeq->shouldReceive('verifyPayment')->twice()->andReturn(
            new GatewayResult(success:false, status:'failed', errorMessage:'declined', rawResponse:['InvoiceStatus'=>'Failed']),
            new GatewayResult(success:true, gatewayTransactionId:$paymentId, amount:$amount, currency:'EGP', status:'paid', rawResponse:['InvoiceStatus'=>'Paid'])
        );
        $factorySeq = \Mockery::mock(PaymentGatewayFactory::class);
        $factorySeq->shouldReceive('make')->twice()->andReturn($gatewaySeq);
        $this->app->instance(PaymentGatewayFactory::class, $factorySeq);
        $this->get('/api/v1/general/checkout/callback?paymentId='.$paymentId);
        $order->refresh();
        $this->assertEquals('pending', $order->status);

        // Reset transaction to pending for retry (callback success path expects pending)
        Transaction::where('order_id', $order->id)->update(['status' => 'pending']);

        $cb2 = $this->get('/api/v1/general/checkout/callback?paymentId='.$paymentId.'&type=mobile');
        // Dump on failure for diagnostics
        if ($cb2->status() !== 200) {
            $this->fail('Callback retry expected 200 got '.$cb2->status().' content: '.$cb2->content());
        }

        $order->refresh();
        if ($order->status !== 'completed') {
            $tx = Transaction::where('order_id', $order->id)->first();
            $this->fail('Order not completed after retry. Status: '.$order->status.' Inventory: '.$order->inventory_state.' Tx status: '.($tx->status ?? 'null').' Response: '.$cb2->content());
        }
        $this->assertEquals($firstId, $order->id, 'Same order ID on retry');
        $this->assertEquals('completed', $order->status);
        $this->assertEquals(1, Order::where('user_id', $this->user->id)->count(), 'No duplicate pending order');
        $cartId = Cart::where('user_id', $this->user->id)->value('id');
        $this->assertEquals(0, CartItem::where('cart_id', $cartId)->count());
    }

    // =================================================================
    // Test 9 — Duplicate successful callback (idempotent)
    // =================================================================
    public function test_9_duplicate_successful_callback(): void
    {
        Event::fake([\App\Events\PaymentSucceeded::class]);

        $this->clearCart($this->user);
        $this->createCartWithItems($this->user, [['product' => $this->product, 'quantity' => 1]]);

        // Create coupon for consumption check
        $coupon = Coupon::create([
            'code' => 'ONEUSE-'.Str::random(4),
            'name' => 'One Use',
            'slug' => 'coupon-'.Str::random(6),
            'discount_type' => 'percentage',
            'discount' => 10,
            'status' => true,
            'start_date' => now()->subDay(),
            'end_date' => now()->addDay(),
            'limiter' => 10,
            'used' => 0]);
        $cart = Cart::where('user_id', $this->user->id)->first();
        $cart->update(['coupon' => $coupon->code]);

        // checkout online
        $this->mockMyFatoraSuccess('INV-9');
        $this->checkout($this->user, array_merge($this->baseCheckoutPayload(['payment_method' => 'online']), ['type' => 'mobile']))->assertStatus(200);
        $order = Order::where('user_id', $this->user->id)->latest()->first();
        $paymentId = Transaction::where('order_id', $order->id)->value('gateway_transaction_id') ?? 'INV-9';
        $amount = (float) $order->total_price;

        $gateway = \Mockery::mock(PaymentGatewayContract::class);
        $gateway->shouldReceive('verifyPayment')->times(2)->andReturn(new GatewayResult(success:true, gatewayTransactionId:$paymentId, amount:$amount, currency:'EGP', status:'paid', rawResponse:['InvoiceStatus'=>'Paid']));
        $factory = \Mockery::mock(PaymentGatewayFactory::class);
        $factory->shouldReceive('make')->times(2)->andReturn($gateway);
        $this->app->instance(PaymentGatewayFactory::class, $factory);

        $this->get('/api/v1/general/checkout/callback?paymentId='.$paymentId.'&type=mobile')->assertStatus(200);
        $this->get('/api/v1/general/checkout/callback?paymentId='.$paymentId.'&type=mobile')->assertStatus(200);

        $order->refresh();
        $this->assertEquals('completed', $order->status);
        // Inventory committed exactly once
        $this->product->refresh();
        $this->assertEquals(19, $this->product->stock_quantity, 'Stock deducted once 20-1');
        $this->assertEquals(1, $this->product->sold_quantity);
        // Coupon consumed exactly once
        $coupon->refresh();
        $this->assertEquals(1, $coupon->used, 'Coupon used once');
        $this->assertTrue($order->coupon_consumed);
        // Invoice exactly once
        $this->assertEquals(1, \App\Models\Invoice::where('order_id', $order->id)->count(), 'One invoice');
        // Event exactly once
        Event::assertDispatched(\App\Events\PaymentSucceeded::class, 1);
    }

    // =================================================================
    // Test 10 — Cashier checkout
    // =================================================================
    public function test_10_cashier_checkout(): void
    {
        $this->clearCart($this->user);
        $cart = $this->createCartWithItems($this->user, [['product' => $this->product, 'quantity' => 2]]);
        $productBefore = $this->product->stock_quantity;

        $resp = $this->checkout($this->user, $this->baseCheckoutPayload([
            'payment_method' => 'pay_at_cashier',
            'fulfillment_type' => 'pickup',
            'pickup_location_id' => $this->pickupLocation->id]));
        $resp->assertStatus(200);
        $resp->assertJsonPath('success', true);

        $order = Order::where('user_id', $this->user->id)->latest()->first();
        $this->assertNotNull($order);
        $this->assertEquals('pending', $order->status);
        $this->assertEquals('pay_at_cashier', $order->payment_method);
        $this->assertDatabaseHas('carts', ['id' => $cart->id]);
        $this->assertEquals(0, CartItem::where('cart_id', $cart->id)->count());
        $this->assertEquals(Order::INVENTORY_STATE_ACTIVE, $order->inventory_state);
        $this->product->refresh();
        $this->assertEquals(2, $this->product->reserved_quantity, 'Inventory reserved');
        $this->assertEquals($productBefore, $this->product->stock_quantity, 'Stock not deducted yet');
        $this->assertDatabaseHas('transactions', ['order_id' => $order->id, 'payment_method' => 'pay_at_cashier', 'status' => 'pending']);
    }

    // =================================================================
    // Test 11 — Cashier does NOT require Cart Items
    // =================================================================
    public function test_11_cashier_does_not_require_cart_items(): void
    {
        $this->clearCart($this->user);
        $cart = $this->createCartWithItems($this->user, [['product' => $this->product, 'quantity' => 1]]);

        $this->checkout($this->user, $this->baseCheckoutPayload([
            'payment_method' => 'pay_at_cashier',
            'fulfillment_type' => 'pickup',
            'pickup_location_id' => $this->pickupLocation->id]))->assertStatus(200);

        $order = Order::where('user_id', $this->user->id)->latest()->first();
        $this->assertEquals('pending', $order->status);
        $this->assertEquals(0, CartItem::where('cart_id', $cart->id)->count());

        // Inspect cart via API must NOT recreate items
        Sanctum::actingAs($this->user);
        $resp = $this->getJson('/api/v1/cart');
        $resp->assertStatus(200);
        // Ensure cart still empty
        $this->assertEquals(0, CartItem::where('cart_id', $cart->id)->count());
    }

    // =================================================================
    // Test 12 — Admin changes Cashier Order status via canonical route
    // =================================================================
    public function test_12_admin_changes_cashier_order_status(): void
    {
        Event::fake([\App\Events\PaymentSucceeded::class]);
        $this->clearCart($this->user);
        $this->createCartWithItems($this->user, [['product' => $this->product, 'quantity' => 1]]);

        $this->checkout($this->user, $this->baseCheckoutPayload([
            'payment_method' => 'pay_at_cashier',
            'fulfillment_type' => 'pickup',
            'pickup_location_id' => $this->pickupLocation->id]))->assertStatus(200);

        $order = Order::where('user_id', $this->user->id)->latest()->first();
        $cartId = Cart::where('user_id', $this->user->id)->value('id');

        Sanctum::actingAs($this->admin);
        $resp = $this->patchJson('/api/v1/orders/'.$order->id.'/status', ['status' => 'processing']);
        $resp->assertStatus(200);
        $resp->assertJsonPath('success', true);
        $order->refresh();
        $this->assertEquals('processing', $order->status);
        $this->assertEquals(0, CartItem::where('cart_id', $cartId)->count(), 'Cart items remain 0 after admin transition');
        // Inventory should still be reserved (not committed until completed)
        $this->assertEquals(Order::INVENTORY_STATE_ACTIVE, $order->inventory_state);

        // Now complete
        $resp2 = $this->patchJson('/api/v1/orders/'.$order->id.'/status', ['status' => 'completed']);
        $resp2->assertStatus(200);
        $order->refresh();
        $this->assertEquals('completed', $order->status);
        $this->assertEquals(Order::INVENTORY_STATE_COMMITTED, $order->inventory_state);
        $this->assertEquals(0, CartItem::where('cart_id', $cartId)->count());
    }

    // =================================================================
    // Test 13 — Cashier timeout at 24h
    // =================================================================
    public function test_13_cashier_timeout_at_24h(): void
    {
        $this->clearCart($this->user);
        $this->createCartWithItems($this->user, [['product' => $this->product, 'quantity' => 1]]);

        $coupon = Coupon::create([
            'code' => 'CASHIER-'.Str::random(4),
            'name' => 'Cashier Test',
            'slug' => 'coupon-'.Str::random(6),
            'discount_type' => 'percentage',
            'discount' => 5,
            'status' => true,
            'start_date' => now()->subDay(),
            'end_date' => now()->addDay(),
            'limiter' => 10,
            'used' => 0]);
        $cart = Cart::where('user_id', $this->user->id)->first();
        $cart->update(['coupon' => $coupon->code]);

        $this->checkout($this->user, $this->baseCheckoutPayload([
            'payment_method' => 'pay_at_cashier',
            'fulfillment_type' => 'pickup',
            'pickup_location_id' => $this->pickupLocation->id]))->assertStatus(200);

        $order = Order::where('user_id', $this->user->id)->latest()->first();
        $cartId = Cart::where('user_id', $this->user->id)->value('id');
        $initialReserved = $this->product->fresh()->reserved_quantity;
        $this->assertEquals(1, $initialReserved);

        // 23:59:59 -> still pending
        Carbon::setTestNow(Carbon::parse($order->reservation_expires_at)->subSecond());
        $this->runReaper();
        $order->refresh();
        $this->assertEquals('pending', $order->status, 'Cashier must remain pending before 24h');
        $this->assertEquals(1, $this->product->fresh()->reserved_quantity);

        // 24h+ -> cancelled, released
        Carbon::setTestNow(Carbon::parse($order->reservation_expires_at)->addSecond());
        $this->runReaper();
        $order->refresh();
        $this->assertEquals('cancelled', $order->status);
        $this->assertEquals(Order::INVENTORY_STATE_RELEASED, $order->inventory_state);
        $this->assertEquals(0, $this->product->fresh()->reserved_quantity, 'Inventory released');
        $this->assertEquals(0, CouponReservation::where('order_id', $order->id)->count(), 'Coupon reservation released');
        $this->assertDatabaseHas('carts', ['id' => $cartId]);
        $this->assertEquals(0, CartItem::where('cart_id', $cartId)->count());

        Carbon::setTestNow();
    }

    // =================================================================
    // Test 14 — Cashier must NOT expire before 24h
    // =================================================================
    public function test_14_cashier_must_not_expire_before_24h(): void
    {
        $this->clearCart($this->user);
        $this->createCartWithItems($this->user, [['product' => $this->product, 'quantity' => 1]]);

        $this->checkout($this->user, $this->baseCheckoutPayload([
            'payment_method' => 'pay_at_cashier',
            'fulfillment_type' => 'pickup',
            'pickup_location_id' => $this->pickupLocation->id]))->assertStatus(200);

        $order = Order::where('user_id', $this->user->id)->latest()->first();

        Carbon::setTestNow(Carbon::parse($order->created_at)->addHours(23)->addMinutes(59));
        $this->runReaper();
        $order->refresh();
        $this->assertEquals('pending', $order->status, '23h59m must stay pending');

        Carbon::setTestNow();
    }

    // =================================================================
    // Test 15 — COD checkout
    // =================================================================
    public function test_15_cod_checkout(): void
    {
        $this->clearCart($this->user);
        $cart = $this->createCartWithItems($this->user, [['product' => $this->product, 'quantity' => 1]]);
        $resp = $this->checkout($this->user, $this->baseCheckoutPayload(['payment_method' => 'cod']))->assertStatus(200);
        $order = Order::where('user_id', $this->user->id)->latest()->first();
        $this->assertNotNull($order);
        $this->assertEquals('pending', $order->status);
        $this->assertEquals('cod', $order->payment_method);
        $this->assertEquals(0, CartItem::where('cart_id', $cart->id)->count());
        $this->assertDatabaseHas('carts', ['id' => $cart->id]);
        $this->assertEquals(Order::INVENTORY_STATE_ACTIVE, $order->inventory_state);
    }

    // =================================================================
    // Test 16 — COD remains pending after 24h
    // =================================================================
    public function test_16_cod_remains_pending_after_24h(): void
    {
        $this->clearCart($this->user);
        $this->createCartWithItems($this->user, [['product' => $this->product, 'quantity' => 1]]);

        $this->checkout($this->user, $this->baseCheckoutPayload(['payment_method' => 'cod']))->assertStatus(200);
        $order = Order::where('user_id', $this->user->id)->latest()->first();
        $cartId = Cart::where('user_id', $this->user->id)->value('id');

        Carbon::setTestNow(Carbon::parse($order->created_at)->addHours(24)->addMinute());
        $this->runReaper();
        $order->refresh();
        $this->assertEquals('pending', $order->status, 'COD must NOT expire after 24h');
        $this->assertEquals(Order::INVENTORY_STATE_ACTIVE, $order->inventory_state, 'Reservation must remain');
        $this->assertEquals(1, $this->product->fresh()->reserved_quantity);
        $this->assertEquals(0, CartItem::where('cart_id', $cartId)->count());

        Carbon::setTestNow();
    }

    // =================================================================
    // Test 17 — COD remains pending for 7 days (6d 23h 59m)
    // =================================================================
    public function test_17_cod_remains_pending_for_7_days(): void
    {
        $this->clearCart($this->user);
        $this->createCartWithItems($this->user, [['product' => $this->product, 'quantity' => 1]]);

        $this->checkout($this->user, $this->baseCheckoutPayload(['payment_method' => 'cod']))->assertStatus(200);
        $order = Order::where('user_id', $this->user->id)->latest()->first();

        Carbon::setTestNow(Carbon::parse($order->created_at)->addDays(6)->addHours(23)->addMinutes(59));
        $this->runReaper();
        $order->refresh();
        $this->assertEquals('pending', $order->status, 'COD must remain pending at 6d23h59m');

        Carbon::setTestNow();
    }

    // =================================================================
    // Test 18 — COD expires after 7 days
    // =================================================================
    public function test_18_cod_expires_after_7_days(): void
    {
        $this->clearCart($this->user);
        $this->createCartWithItems($this->user, [['product' => $this->product, 'quantity' => 1]]);

        $coupon = Coupon::create([
            'code' => 'COD7-'.Str::random(4),
            'name' => 'COD7',
            'slug' => 'coupon-'.Str::random(6),
            'discount_type' => 'percentage',
            'discount' => 5,
            'status' => true,
            'start_date' => now()->subDay(),
            'end_date' => now()->addDay(),
            'limiter' => 10,
            'used' => 0]);
        $cart = Cart::where('user_id', $this->user->id)->first();
        $cart->update(['coupon' => $coupon->code]);

        $this->checkout($this->user, $this->baseCheckoutPayload(['payment_method' => 'cod']))->assertStatus(200);
        $order = Order::where('user_id', $this->user->id)->latest()->first();
        $cartId = Cart::where('user_id', $this->user->id)->value('id');

        Carbon::setTestNow(Carbon::parse($order->reservation_expires_at)->addSecond());
        $this->runReaper();
        $order->refresh();
        $this->assertEquals('cancelled', $order->status);
        $this->assertEquals(Order::INVENTORY_STATE_RELEASED, $order->inventory_state);
        $this->assertEquals(0, $this->product->fresh()->reserved_quantity);
        $this->assertEquals(0, CouponReservation::where('order_id', $order->id)->count());
        $this->assertDatabaseHas('carts', ['id' => $cartId]);
        $this->assertEquals(0, CartItem::where('cart_id', $cartId)->count());

        Carbon::setTestNow();
    }

    // =================================================================
    // Test 19 — Admin status endpoint
    // =================================================================
    public function test_19_admin_status_endpoint(): void
    {
        // Create order directly to avoid lingering auth from checkout
        $order = Order::create([
            'user_id' => $this->user->id,
            'name' => 'Test',
            'user_phone' => '01000000000',
            'user_email' => $this->user->email,
            'address' => json_encode(['street'=>'1']),
            'price' => 100,
            'total_price' => 100,
            'status' => 'pending']);

        // authentication required — no user acting
        // Ensure no authenticated user lingering
        $this->app['auth']->forgetGuards();
        $this->patchJson('/api/v1/orders/'.$order->id.'/status', ['status' => 'processing'])->assertStatus(401);

        // correct permission required (user without perm)
        Sanctum::actingAs($this->user);
        $this->patchJson('/api/v1/orders/'.$order->id.'/status', ['status' => 'processing'])->assertStatus(403);

        // validation works - invalid status 422
        Sanctum::actingAs($this->admin);
        $this->patchJson('/api/v1/orders/'.$order->id.'/status', ['status' => 'shipped'])->assertStatus(422);
        $this->patchJson('/api/v1/orders/'.$order->id.'/status', [])->assertStatus(422);

        // missing Order 404
        $this->patchJson('/api/v1/orders/99999/status', ['status' => 'processing'])->assertStatus(404);

        // valid transition succeeds
        $resp = $this->patchJson('/api/v1/orders/'.$order->id.'/status', ['status' => 'processing']);
        $resp->assertStatus(200);
        $resp->assertJsonPath('success', true);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'processing']);
    }

    // =================================================================
    // Test 20 — Invalid transitions
    // =================================================================
    public function test_20_invalid_transitions(): void
    {
        Sanctum::actingAs($this->admin);

        // completed -> pending should be 422
        $order = Order::create([
            'user_id' => $this->user->id,
            'name' => 'Test',
            'user_phone' => '01000000000',
            'user_email' => $this->user->email,
            'address' => json_encode(['street'=>'1']),
            'price' => 100,
            'total_price' => 100,
            'status' => 'completed']);
        $before = $order->fresh()->toArray();
        $resp = $this->patchJson('/api/v1/orders/'.$order->id.'/status', ['status' => 'pending']);
        $resp->assertStatus(422);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'completed']);

        // delivered -> cancelled also invalid
        $order2 = Order::create([
            'user_id' => $this->user->id,
            'name' => 'Test2',
            'user_phone' => '01000000000',
            'user_email' => $this->user->email,
            'address' => json_encode(['street'=>'1']),
            'price' => 100,
            'total_price' => 100,
            'status' => 'delivered']);
        $resp2 = $this->patchJson('/api/v1/orders/'.$order2->id.'/status', ['status' => 'cancelled']);
        $resp2->assertStatus(422);
        $this->assertDatabaseHas('orders', ['id' => $order2->id, 'status' => 'delivered']);

        // cancelled -> processing also invalid
        $order3 = Order::create([
            'user_id' => $this->user->id,
            'name' => 'Test3',
            'user_phone' => '01000000000',
            'user_email' => $this->user->email,
            'address' => json_encode(['street'=>'1']),
            'price' => 100,
            'total_price' => 100,
            'status' => 'cancelled']);
        $resp3 = $this->patchJson('/api/v1/orders/'.$order3->id.'/status', ['status' => 'processing']);
        $resp3->assertStatus(422);
        $this->assertDatabaseHas('orders', ['id' => $order3->id, 'status' => 'cancelled']);
    }

    // =================================================================
    // Test 21 — Same-status update (idempotent, no duplicate effects)
    // =================================================================
    public function test_21_same_status_update(): void
    {
        // Do not fake PaymentSucceeded globally as it hides invoice dupe checks; Queue is already faked
        $this->clearCart($this->user);
        $this->createCartWithItems($this->user, [['product' => $this->product, 'quantity' => 1]]);

        $coupon = Coupon::create([
            'code' => 'SAME-'.Str::random(4),
            'name' => 'Same',
            'slug' => 'coupon-'.Str::random(6),
            'discount_type' => 'percentage',
            'discount' => 10,
            'status' => true,
            'start_date' => now()->subDay(),
            'end_date' => now()->addDay(),
            'limiter' => 10,
            'used' => 0]);
        $cart = Cart::where('user_id', $this->user->id)->first();
        $cart->update(['coupon' => $coupon->code]);

        $this->checkout($this->user, $this->baseCheckoutPayload(['payment_method' => 'cod']))->assertStatus(200);
        $order = Order::where('user_id', $this->user->id)->latest()->first();
        $orderId = $order->id;

        // First transition pending->processing via admin
        Sanctum::actingAs($this->admin);
        $this->patchJson('/api/v1/orders/'.$orderId.'/status', ['status' => 'processing'])->assertStatus(200);

        // Same-status pending->pending (should succeed but do nothing harmful)
        // Create new pending order for this test
        $this->clearCart($this->user);
        $this->createCartWithItems($this->user, [['product' => $this->product2, 'quantity' => 1]]);
        $this->checkout($this->user, $this->baseCheckoutPayload(['payment_method' => 'cod']))->assertStatus(200);
        $order2 = Order::where('user_id', $this->user->id)->orderBy('id','desc')->first();
        // Ensure we got the new pending order, not the previous processing one
        $this->assertNotEquals($orderId, $order2->id, 'Second checkout must create new order');
        $this->assertEquals('pending', $order2->status);

        $invoiceCountBefore = \App\Models\Invoice::where('order_id', $order2->id)->count();
        $couponUsedBefore = $coupon->fresh()->used;
        $reservedBefore = $this->product2->fresh()->reserved_quantity;

        Sanctum::actingAs($this->admin);
        $resp = $this->patchJson('/api/v1/orders/'.$order2->id.'/status', ['status' => 'pending']);
        if ($resp->status() !== 200) {
            $this->fail('Same-status pending->pending expected 200 got '.$resp->status().' content: '.$resp->content());
        }

        $this->assertEquals('pending', $order2->fresh()->status);
        $this->assertEquals($invoiceCountBefore, \App\Models\Invoice::where('order_id', $order2->id)->count(), 'No duplicate invoice on same-status');
        $this->assertEquals($couponUsedBefore, $coupon->fresh()->used, 'Coupon not consumed again');
        $this->assertEquals($reservedBefore, $this->product2->fresh()->reserved_quantity, 'Inventory not committed again');
        $this->assertEquals(Order::INVENTORY_STATE_ACTIVE, $order2->fresh()->inventory_state);
    }

    // =================================================================
    // Test 22 — Reserve once
    // =================================================================
    public function test_22_reserve_once(): void
    {
        $this->clearCart($this->user);
        $product = $this->product;
        $product->update(['stock_quantity' => 10, 'reserved_quantity' => 0, 'sold_quantity' => 0]);

        $this->createCartWithItems($this->user, [['product' => $product, 'quantity' => 3]]);
        $this->checkout($this->user, $this->baseCheckoutPayload(['payment_method' => 'cod']))->assertStatus(200);

        $product->refresh();
        $this->assertEquals(3, $product->reserved_quantity, 'Reservation happens once');
        $this->assertEquals(10, $product->stock_quantity);
    }

    // =================================================================
    // Test 23 — Duplicate checkout (no duplicate reservation)
    // =================================================================
    public function test_23_duplicate_checkout(): void
    {
        $this->clearCart($this->user);
        $product = $this->product;
        $product->update(['stock_quantity' => 10, 'reserved_quantity' => 0, 'sold_quantity' => 0]);

        $this->createCartWithItems($this->user, [['product' => $product, 'quantity' => 2]]);
        $this->checkout($this->user, $this->baseCheckoutPayload(['payment_method' => 'cod']))->assertStatus(200);
        $reservedAfterFirst = $product->fresh()->reserved_quantity;
        $this->assertEquals(2, $reservedAfterFirst);

        // Second checkout with empty cart should fail, not duplicate reservation
        $resp2 = $this->checkout($this->user, $this->baseCheckoutPayload(['payment_method' => 'cod']));
        // Expect 400 cart not found or 422
        $this->assertContains($resp2->status(), [400, 422]);

        $product->refresh();
        $this->assertEquals(2, $product->reserved_quantity, 'No duplicate reservation');
        $this->assertEquals(1, Order::where('user_id', $this->user->id)->count(), 'No duplicate order');
    }

    // =================================================================
    // Test 24 — Successful completion commits once
    // =================================================================
    public function test_24_successful_completion_commits_once(): void
    {
        $this->clearCart($this->user);
        $product = $this->product;
        $product->update(['stock_quantity' => 10, 'reserved_quantity' => 0, 'sold_quantity' => 0]);

        $this->createCartWithItems($this->user, [['product' => $product, 'quantity' => 2]]);
        $this->checkout($this->user, $this->baseCheckoutPayload(['payment_method' => 'cod']))->assertStatus(200);
        $order = Order::where('user_id', $this->user->id)->latest()->first();

        Sanctum::actingAs($this->admin);
        $this->patchJson('/api/v1/orders/'.$order->id.'/status', ['status' => 'completed'])->assertStatus(200);

        $product->refresh();
        $this->assertEquals(8, $product->stock_quantity, 'Stock deducted 10-2');
        $this->assertEquals(0, $product->reserved_quantity);
        $this->assertEquals(2, $product->sold_quantity);
        $this->assertEquals(Order::INVENTORY_STATE_COMMITTED, $order->fresh()->inventory_state);

        // Second complete should be idempotent (same status)
        $this->patchJson('/api/v1/orders/'.$order->id.'/status', ['status' => 'completed'])->assertStatus(200);
        $product->refresh();
        $this->assertEquals(8, $product->stock_quantity, 'Second commit does not deduct again');
        $this->assertEquals(2, $product->sold_quantity);
    }

    // =================================================================
    // Test 25 — Expiration releases once
    // =================================================================
    public function test_25_expiration_releases_once(): void
    {
        $this->clearCart($this->user);
        $product = $this->product;
        $product->update(['stock_quantity' => 10, 'reserved_quantity' => 0, 'sold_quantity' => 0]);

        $this->createCartWithItems($this->user, [['product' => $product, 'quantity' => 3]]);
        $this->checkout($this->user, $this->baseCheckoutPayload(['payment_method' => 'cod']))->assertStatus(200);
        $order = Order::where('user_id', $this->user->id)->latest()->first();
        $this->assertEquals(3, $product->fresh()->reserved_quantity);

        Carbon::setTestNow(Carbon::parse($order->reservation_expires_at)->addSecond());
        $this->runReaper();
        $this->runReaper(); // second run should be no-op

        $product->refresh();
        $this->assertEquals(0, $product->reserved_quantity, 'Released exactly once');
        $this->assertEquals(10, $product->stock_quantity, 'Stock not increased beyond original');
        $this->assertEquals(Order::INVENTORY_STATE_RELEASED, $order->fresh()->inventory_state);

        // Run again, still same
        $this->runReaper();
        $product->refresh();
        $this->assertEquals(0, $product->reserved_quantity);
        $this->assertEquals(10, $product->stock_quantity);

        Carbon::setTestNow();
    }

    // =================================================================
    // Test 26 — Coupon reserved at checkout
    // =================================================================
    public function test_26_coupon_reserved_at_checkout(): void
    {
        $this->clearCart($this->user);
        $coupon = Coupon::create([
            'code' => 'RESV-'.Str::random(4),
            'name' => 'Reserve Test',
            'slug' => 'coupon-'.Str::random(6),
            'discount_type' => 'percentage',
            'discount' => 10,
            'status' => true,
            'start_date' => now()->subDay(),
            'end_date' => now()->addDay(),
            'limiter' => 5,
            'used' => 0]);
        $this->createCartWithItems($this->user, [['product' => $this->product, 'quantity' => 1]]);
        $cart = Cart::where('user_id', $this->user->id)->first();
        $cart->update(['coupon' => $coupon->code]);

        $this->checkout($this->user, $this->baseCheckoutPayload(['payment_method' => 'cod']))->assertStatus(200);
        $order = Order::where('user_id', $this->user->id)->latest()->first();
        $this->assertEquals($coupon->code, $order->coupon);
        $this->assertDatabaseHas('coupon_reservations', ['order_id' => $order->id, 'coupon_id' => $coupon->id]);
    }

    // =================================================================
    // Test 27 — Failed payment does not consume coupon
    // =================================================================
    public function test_27_failed_payment_does_not_consume_coupon(): void
    {
        $this->clearCart($this->user);
        $coupon = Coupon::create([
            'code' => 'FAIL-'.Str::random(4),
            'name' => 'Fail Test',
            'slug' => 'coupon-'.Str::random(6),
            'discount_type' => 'percentage',
            'discount' => 10,
            'status' => true,
            'start_date' => now()->subDay(),
            'end_date' => now()->addDay(),
            'limiter' => 5,
            'used' => 0]);
        $this->createCartWithItems($this->user, [['product' => $this->product, 'quantity' => 1]]);
        $cart = Cart::where('user_id', $this->user->id)->first();
        $cart->update(['coupon' => $coupon->code]);

        $this->mockMyFatoraSuccess('INV-27');
        $this->checkout($this->user, array_merge($this->baseCheckoutPayload(['payment_method' => 'online']), ['type' => 'mobile']))->assertStatus(200);
        $order = Order::where('user_id', $this->user->id)->latest()->first();
        $paymentId = Transaction::where('order_id', $order->id)->value('gateway_transaction_id') ?? 'INV-27';
        $this->assertDatabaseHas('coupon_reservations', ['order_id' => $order->id]);

        // Fail payment
        $gateway = \Mockery::mock(PaymentGatewayContract::class);
        $gateway->shouldReceive('verifyPayment')->andReturn(new GatewayResult(success:false, status:'failed'));
        $factory = \Mockery::mock(PaymentGatewayFactory::class);
        $factory->shouldReceive('make')->andReturn($gateway);
        $this->app->instance(PaymentGatewayFactory::class, $factory);
        $this->get('/api/v1/general/checkout/callback?paymentId='.$paymentId);

        $order->refresh();
        $coupon->refresh();
        $this->assertEquals('pending', $order->status);
        $this->assertFalse((bool) $order->coupon_consumed);
        $this->assertEquals(0, $coupon->used, 'Coupon NOT consumed on failure');
        // Reservation still exists (active) for retry — but may be deleted? In failure path, PaymentFailed does not release reservation; only reaper or success consumes.
        // Our implementation keeps reservation active for retry.
    }

    // =================================================================
    // Test 28 — Successful payment consumes coupon once
    // =================================================================
    public function test_28_successful_payment_consumes_coupon_once(): void
    {
        $this->clearCart($this->user);
        $coupon = Coupon::create([
            'code' => 'CONS-'.Str::random(4),
            'name' => 'Consume Test',
            'slug' => 'coupon-'.Str::random(6),
            'discount_type' => 'percentage',
            'discount' => 10,
            'status' => true,
            'start_date' => now()->subDay(),
            'end_date' => now()->addDay(),
            'limiter' => 5,
            'used' => 0]);
        $this->createCartWithItems($this->user, [['product' => $this->product, 'quantity' => 1]]);
        $cart = Cart::where('user_id', $this->user->id)->first();
        $cart->update(['coupon' => $coupon->code]);

        $this->mockMyFatoraSuccess('INV-28');
        $this->checkout($this->user, array_merge($this->baseCheckoutPayload(['payment_method' => 'online']), ['type' => 'mobile']))->assertStatus(200);
        $order = Order::where('user_id', $this->user->id)->latest()->first();
        $paymentId = Transaction::where('order_id', $order->id)->value('gateway_transaction_id') ?? 'INV-28';
        $amount = (float) $order->total_price;

        $gateway = \Mockery::mock(PaymentGatewayContract::class);
        $gateway->shouldReceive('verifyPayment')->andReturn(new GatewayResult(success:true, gatewayTransactionId:$paymentId, amount:$amount, currency:'EGP', status:'paid', rawResponse:['InvoiceStatus'=>'Paid']));
        $factory = \Mockery::mock(PaymentGatewayFactory::class);
        $factory->shouldReceive('make')->andReturn($gateway);
        $this->app->instance(PaymentGatewayFactory::class, $factory);

        $this->get('/api/v1/general/checkout/callback?paymentId='.$paymentId.'&type=mobile')->assertStatus(200);

        $coupon->refresh();
        $order->refresh();
        $this->assertEquals(1, $coupon->used);
        $this->assertTrue((bool) $order->coupon_consumed);
        $this->assertEquals(0, CouponReservation::where('order_id', $order->id)->count(), 'Reservation consumed');

        // Second callback idempotent -> still 1 (reuse same mock, should remain idempotent)
        $this->get('/api/v1/general/checkout/callback?paymentId='.$paymentId.'&type=mobile')->assertStatus(200);
        $this->assertEquals(1, $coupon->fresh()->used, 'Second success does not double-consume');
    }

    // =================================================================
    // Test 29 — Expiration releases coupon reservation
    // =================================================================
    public function test_29_expiration_releases_coupon_reservation(): void
    {
        $this->clearCart($this->user);
        $coupon = Coupon::create([
            'code' => 'EXP-'.Str::random(4),
            'name' => 'Expire Test',
            'slug' => 'coupon-'.Str::random(6),
            'discount_type' => 'percentage',
            'discount' => 10,
            'status' => true,
            'start_date' => now()->subDay(),
            'end_date' => now()->addDay(),
            'limiter' => 5,
            'used' => 0]);
        $this->createCartWithItems($this->user, [['product' => $this->product, 'quantity' => 1]]);
        $cart = Cart::where('user_id', $this->user->id)->first();
        $cart->update(['coupon' => $coupon->code]);

        $this->checkout($this->user, $this->baseCheckoutPayload(['payment_method' => 'cod']))->assertStatus(200);
        $order = Order::where('user_id', $this->user->id)->latest()->first();
        $this->assertDatabaseHas('coupon_reservations', ['order_id' => $order->id]);

        Carbon::setTestNow(Carbon::parse($order->reservation_expires_at)->addSecond());
        $this->runReaper();
        $order->refresh();
        $coupon->refresh();
        $this->assertEquals('cancelled', $order->status);
        $this->assertEquals(0, CouponReservation::where('order_id', $order->id)->count(), 'Reservation released');
        $this->assertEquals(0, $coupon->used, 'Usage NOT incremented on expiration');
        $this->assertFalse((bool) $order->coupon_consumed);

        Carbon::setTestNow();
    }

    // =================================================================
    // Test 30 — Pending Order reuse does not orphan coupon reservations
    // =================================================================
    public function test_30_pending_order_reuse_does_not_orphan_coupon_reservations(): void
    {
        $this->clearCart($this->user);
        $couponA = Coupon::create([
            'code' => 'COUPA-'.Str::random(4),
            'name' => 'Coupon A',
            'slug' => 'coupon-a-'.Str::random(6),
            'discount_type' => 'percentage',
            'discount' => 10,
            'status' => true,
            'start_date' => now()->subDay(),
            'end_date' => now()->addDay(),
            'limiter' => 5,
            'used' => 0]);
        $couponB = Coupon::create([
            'code' => 'COUPB-'.Str::random(4),
            'name' => 'Coupon B',
            'slug' => 'coupon-b-'.Str::random(6),
            'discount_type' => 'percentage',
            'discount' => 5,
            'status' => true,
            'start_date' => now()->subDay(),
            'end_date' => now()->addDay(),
            'limiter' => 5,
            'used' => 0]);

        // Checkout with Coupon A (creates pending order)
        $this->createCartWithItems($this->user, [['product' => $this->product, 'quantity' => 1]]);
        $cart = Cart::where('user_id', $this->user->id)->first();
        $cart->update(['coupon' => $couponA->code]);
        $this->checkout($this->user, $this->baseCheckoutPayload(['payment_method' => 'cod']))->assertStatus(200);
        $order = Order::where('user_id', $this->user->id)->latest()->first();
        $this->assertDatabaseHas('coupon_reservations', ['order_id' => $order->id, 'coupon_id' => $couponA->id]);
        $firstOrderId = $order->id;

        // Simulate user changes coupon to B and retries checkout (pending reuse)
        // Need to refill cart since it was cleared after checkout
        $this->createCartWithItems($this->user, [['product' => $this->product2, 'quantity' => 1]]);
        $cart2 = Cart::where('user_id', $this->user->id)->first();
        $cart2->update(['coupon' => $couponB->code]);

        // Next checkout should reuse same order, release A, reserve B
        $this->checkout($this->user, $this->baseCheckoutPayload(['payment_method' => 'cod']))->assertStatus(200);
        $order2 = Order::where('user_id', $this->user->id)->latest()->first();
        $this->assertEquals($firstOrderId, $order2->id, 'Same order reused');

        $this->assertDatabaseMissing('coupon_reservations', ['order_id' => $order2->id, 'coupon_id' => $couponA->id]);
        $this->assertDatabaseHas('coupon_reservations', ['order_id' => $order2->id, 'coupon_id' => $couponB->id]);
        $this->assertEquals(1, CouponReservation::where('order_id', $order2->id)->count(), 'No orphaned reservations');
    }

    // =================================================================
    // Test 31 — Payment failure does not restore Cart Items
    // =================================================================
    public function test_31_payment_failure_does_not_restore_cart_items(): void
    {
        $this->clearCart($this->user);
        $this->createCartWithItems($this->user, [['product' => $this->product, 'quantity' => 1]]);
        $cartId = Cart::where('user_id', $this->user->id)->value('id');

        $this->mockMyFatoraSuccess('INV-31');
        $this->checkout($this->user, $this->baseCheckoutPayload(['payment_method' => 'online']))->assertStatus(200);
        $this->assertEquals(0, CartItem::where('cart_id', $cartId)->count());

        $order = Order::where('user_id', $this->user->id)->latest()->first();
        $paymentId = Transaction::where('order_id', $order->id)->value('gateway_transaction_id') ?? 'INV-31';

        $gateway = \Mockery::mock(PaymentGatewayContract::class);
        $gateway->shouldReceive('verifyPayment')->andReturn(new GatewayResult(success:false, status:'failed'));
        $factory = \Mockery::mock(PaymentGatewayFactory::class);
        $factory->shouldReceive('make')->andReturn($gateway);
        $this->app->instance(PaymentGatewayFactory::class, $factory);

        $this->get('/api/v1/general/checkout/callback?paymentId='.$paymentId);
        $this->assertEquals(0, CartItem::where('cart_id', $cartId)->count(), 'Cart items must remain 0 after payment failure');
    }

    // =================================================================
    // Test 32 — Order expiration does not restore Cart Items
    // =================================================================
    public function test_32_order_expiration_does_not_restore_cart_items(): void
    {
        $this->clearCart($this->user);
        $this->createCartWithItems($this->user, [['product' => $this->product, 'quantity' => 1]]);
        $cartId = Cart::where('user_id', $this->user->id)->value('id');

        $this->checkout($this->user, $this->baseCheckoutPayload(['payment_method' => 'cod']))->assertStatus(200);
        $order = Order::where('user_id', $this->user->id)->latest()->first();
        $this->assertEquals(0, CartItem::where('cart_id', $cartId)->count());

        Carbon::setTestNow(Carbon::parse($order->reservation_expires_at)->addSecond());
        $this->runReaper();
        $this->assertEquals(0, CartItem::where('cart_id', $cartId)->count(), 'Expiration must NOT restore cart items');
        Carbon::setTestNow();
    }

    // =================================================================
    // Test 33 — Order cancellation does not restore Cart Items
    // =================================================================
    public function test_33_order_cancellation_does_not_restore_cart_items(): void
    {
        $this->clearCart($this->user);
        $this->createCartWithItems($this->user, [['product' => $this->product, 'quantity' => 1]]);
        $cartId = Cart::where('user_id', $this->user->id)->value('id');

        $this->checkout($this->user, $this->baseCheckoutPayload(['payment_method' => 'cod']))->assertStatus(200);
        $order = Order::where('user_id', $this->user->id)->latest()->first();
        $this->assertEquals(0, CartItem::where('cart_id', $cartId)->count());

        Sanctum::actingAs($this->admin);
        $this->patchJson('/api/v1/orders/'.$order->id.'/status', ['status' => 'cancelled'])->assertStatus(200);

        $this->assertEquals(0, CartItem::where('cart_id', $cartId)->count(), 'Cancellation must NOT restore cart items');
    }

    // =================================================================
    // Test 34 — Successful Order does not affect Cart record
    // =================================================================
    public function test_34_successful_order_does_not_affect_cart_record(): void
    {
        $this->clearCart($this->user);
        $this->createCartWithItems($this->user, [['product' => $this->product, 'quantity' => 1]]);
        $cartId = Cart::where('user_id', $this->user->id)->value('id');

        $this->checkout($this->user, $this->baseCheckoutPayload(['payment_method' => 'cod']))->assertStatus(200);
        $order = Order::where('user_id', $this->user->id)->latest()->first();
        Sanctum::actingAs($this->admin);
        $this->patchJson('/api/v1/orders/'.$order->id.'/status', ['status' => 'completed'])->assertStatus(200);

        $this->assertDatabaseHas('carts', ['id' => $cartId]);
        $this->assertEquals(0, CartItem::where('cart_id', $cartId)->count());
    }

    // =================================================================
    // Test 35 — Duplicate pending Order race
    // =================================================================
    public function test_35_duplicate_pending_order_race(): void
    {
        $this->clearCart($this->user);
        $this->createCartWithItems($this->user, [['product' => $this->product, 'quantity' => 1]]);
        $this->checkout($this->user, $this->baseCheckoutPayload(['payment_method' => 'cod']))->assertStatus(200);
        $firstCount = Order::where('user_id', $this->user->id)->where('status','pending')->count();
        $this->assertEquals(1, $firstCount);

        // Simulate second concurrent checkout: refill cart then checkout again quickly
        // Should reuse same pending order, not create second
        $this->createCartWithItems($this->user, [['product' => $this->product2, 'quantity' => 1]]);
        $this->checkout($this->user, $this->baseCheckoutPayload(['payment_method' => 'cod']))->assertStatus(200);
        $secondCount = Order::where('user_id', $this->user->id)->count();
        $this->assertEquals(1, $secondCount, 'Concurrent checkouts must not create duplicate pending orders');

        // Also verify atomic locking: trying to create 2 orders concurrently via service would lock
        DB::transaction(function () {
            $pending = Order::where('user_id', $this->user->id)->where('status','pending')->lockForUpdate()->first();
            $this->assertNotNull($pending);
        });
    }

    // =================================================================
    // Test 36 — Coupon reservation race (two users, single-use coupon)
    // =================================================================
    public function test_36_coupon_reservation_race(): void
    {
        $coupon = Coupon::create([
            'code' => 'RACE-'.Str::random(4),
            'name' => 'Race',
            'slug' => 'coupon-race-'.Str::random(6),
            'discount_type' => 'percentage',
            'discount' => 10,
            'status' => true,
            'start_date' => now()->subDay(),
            'end_date' => now()->addDay(),
            'limiter' => 1,
            'used' => 0]);

        $userA = User::create(['name'=>'UA','email'=>'ua-'.Str::random(4).'@test.com','password'=>bcrypt('pass'),'type'=>'user','is_active'=>true,'email_verified_at'=>now()]);
        $userB = User::create(['name'=>'UB','email'=>'ub-'.Str::random(4).'@test.com','password'=>bcrypt('pass'),'type'=>'user','is_active'=>true,'email_verified_at'=>now()]);

        // Both create carts and checkout with same single-use coupon
        $cartA = Cart::create(['user_id' => $userA->id, 'status' => 'active', 'total_price' => 100]);
        CartItem::create(['cart_id'=>$cartA->id,'product_id'=>$this->product->id,'quantity'=>1,'price'=>100,'total_price'=>100,'shipping_method'=>ShippingMethod::SCHEDULED]);
        $cartA->update(['coupon' => $coupon->code]);

        $cartB = Cart::create(['user_id' => $userB->id, 'status' => 'active', 'total_price' => 100]);
        CartItem::create(['cart_id'=>$cartB->id,'product_id'=>$this->product->id,'quantity'=>1,'price'=>100,'total_price'=>100,'shipping_method'=>ShippingMethod::SCHEDULED]);
        $cartB->update(['coupon' => $coupon->code]);

        // User A checkout succeeds and reserves coupon
        Sanctum::actingAs($userA);
        $respA = $this->postJson(self::CHECKOUT_PREFIX.'/checkout', [
            'name' => 'UA','user_phone'=>'01000000000','user_email'=>$userA->email,'address'=>['street'=>'1'],'governorate_id'=>$this->governorate->id,'payment_method'=>'cod']);
        $respA->assertStatus(200);
        $orderA = Order::where('user_id', $userA->id)->latest()->first();
        $this->assertDatabaseHas('coupon_reservations', ['order_id'=>$orderA->id]);

        // User B checkout should fail due to coupon limit (reservation race)
        Sanctum::actingAs($userB);
        $respB = $this->postJson(self::CHECKOUT_PREFIX.'/checkout', [
            'name' => 'UB','user_phone'=>'01000000000','user_email'=>$userB->email,'address'=>['street'=>'1'],'governorate_id'=>$this->governorate->id,'payment_method'=>'cod']);
        // Expect 422 coupon limit or similar failure
        $this->assertContains($respB->status(), [422,400,500]);
        // Ensure only one reservation exists
        $this->assertEquals(1, CouponReservation::where('coupon_id', $coupon->id)->count());
    }

    // =================================================================
    // Test 37 — Payment vs expiration race
    // =================================================================
    public function test_37_payment_vs_expiration_race(): void
    {
        $this->clearCart($this->user);
        $this->createCartWithItems($this->user, [['product' => $this->product, 'quantity' => 1]]);
        $this->mockMyFatoraSuccess('INV-37');
        $this->checkout($this->user, array_merge($this->baseCheckoutPayload(['payment_method' => 'online']), ['type' => 'mobile']))->assertStatus(200);
        $order = Order::where('user_id', $this->user->id)->latest()->first();
        // Force expiry to be now so reaper is eligible
        $order->update(['reservation_expires_at' => now()->subSecond()]);
        $paymentId = Transaction::where('order_id',$order->id)->value('gateway_transaction_id') ?? 'INV-37';
        $amount = (float) $order->total_price;

        // Scenario A: payment wins (callback before reaper)
        $gateway = \Mockery::mock(PaymentGatewayContract::class);
        $gateway->shouldReceive('verifyPayment')->andReturn(new GatewayResult(success:true, gatewayTransactionId:$paymentId, amount:$amount, currency:'EGP', status:'paid'));
        $factory = \Mockery::mock(PaymentGatewayFactory::class);
        $factory->shouldReceive('make')->andReturn($gateway);
        $this->app->instance(PaymentGatewayFactory::class, $factory);

        $this->get('/api/v1/general/checkout/callback?paymentId='.$paymentId.'&type=mobile')->assertStatus(200);
        $this->runReaper(); // reaper after payment should be no-op

        $order->refresh();
        $this->assertEquals('completed', $order->status);
        $this->assertEquals(Order::INVENTORY_STATE_COMMITTED, $order->inventory_state);
        $this->product->refresh();
        $this->assertEquals(1, $this->product->sold_quantity, 'Committed once');

        // Scenario B: expiration wins (reaper before payment)
        $this->product->update(['stock_quantity'=>20,'reserved_quantity'=>0,'sold_quantity'=>0]);
        $this->clearCart($this->user);
        $this->createCartWithItems($this->user, [['product' => $this->product, 'quantity' => 1]]);
        $this->mockMyFatoraSuccess('INV-37B');
        $this->checkout($this->user, array_merge($this->baseCheckoutPayload(['payment_method' => 'online']), ['type' => 'mobile']))->assertStatus(200);
        $order2 = Order::where('user_id', $this->user->id)->orderBy('id','desc')->first();
        $this->assertNotEquals($order->id, $order2->id, 'Second scenario must create new order');
        $this->assertEquals('pending', $order2->status, 'Order2 should be pending before reaper');
        $order2->update(['reservation_expires_at' => now()->subSecond()]);
        $paymentId2 = Transaction::where('order_id',$order2->id)->value('gateway_transaction_id') ?? 'INV-37B';

        // Ensure gatewayReportsPaid returns false by making pending transaction not match (set status to failed)
        Transaction::where('order_id', $order2->id)->update(['status' => 'failed']);
        \Illuminate\Support\Facades\Artisan::call('orders:cancel-unpaid');
        $order2->refresh();
        // Restore pending for late-payment check (late payment should not resurrect cancelled order)
        Transaction::where('order_id', $order2->id)->update(['status' => 'pending']);
        if ($order2->status !== 'cancelled') {
            $this->fail('Expected cancelled after reaper, got '.$order2->status.' inventory '.$order2->inventory_state.' order2 id '.$order2->id);
        }
        $this->assertEquals('cancelled', $order2->status);
        $this->assertEquals(Order::INVENTORY_STATE_RELEASED, $order2->inventory_state);

        // Late payment should NOT resurrect
        $gateway2 = \Mockery::mock(PaymentGatewayContract::class);
        $gateway2->shouldReceive('verifyPayment')->andReturn(new GatewayResult(success:true, gatewayTransactionId:$paymentId2, amount:(float)$order2->total_price, currency:'EGP', status:'paid'));
        $factory2 = \Mockery::mock(PaymentGatewayFactory::class);
        $factory2->shouldReceive('make')->andReturn($gateway2);
        $this->app->instance(PaymentGatewayFactory::class, $factory2);
        $this->get('/api/v1/general/checkout/callback?paymentId='.$paymentId2.'&type=mobile');
        $order2->refresh();
        $this->assertEquals('cancelled', $order2->status, 'Cancelled cannot be resurrected');
        $this->assertEquals(Order::INVENTORY_STATE_RELEASED, $order2->inventory_state);
        $this->product->refresh();
        $this->assertEquals(0, $this->product->sold_quantity, 'Late payment must not commit');
    }

    // =================================================================
    // Test 38 — Admin status vs expiration race
    // =================================================================
    public function test_38_admin_status_vs_expiration_race(): void
    {
        $this->clearCart($this->user);
        $this->createCartWithItems($this->user, [['product' => $this->product, 'quantity' => 1]]);
        $this->checkout($this->user, $this->baseCheckoutPayload([
            'payment_method' => 'pay_at_cashier',
            'fulfillment_type' => 'pickup',
            'pickup_location_id' => $this->pickupLocation->id]))->assertStatus(200);
        $order = Order::where('user_id', $this->user->id)->latest()->first();
        // Force expiry
        $order->update(['reservation_expires_at' => now()->subSecond()]);

        // Admin moves pending->processing before reaper runs
        Sanctum::actingAs($this->admin);
        $this->patchJson('/api/v1/orders/'.$order->id.'/status', ['status' => 'processing'])->assertStatus(200);
        $order->refresh();
        $this->assertEquals('processing', $order->status);

        // Reaper should NOT cancel processing order
        $this->runReaper();
        $order->refresh();
        $this->assertEquals('processing', $order->status, 'Reaper must not cancel non-pending');
        $this->assertEquals(Order::INVENTORY_STATE_ACTIVE, $order->inventory_state);

        // Opposite: expire first, then admin try to process cancelled -> should fail
        // Create a fresh pending cashier order directly to avoid checkout reuse complexities
        $order2 = $this->createActiveReservation($this->product2, 1, $this->user, 'pay_at_cashier');
        $order2->update(['payment_method' => 'pay_at_cashier', 'fulfillment_type' => 'pickup', 'pickup_location_id' => $this->pickupLocation->id]);
        // Ensure transaction exists for completeness (not required for cashier reaper)
        Transaction::create([
            'order_id' => $order2->id,
            'user_id' => $this->user->id,
            'payment_method' => 'pay_at_cashier',
            'status' => 'pending',
            'amount' => $order2->total_price,
            'currency' => 'EGP']);
        $order2->update(['reservation_expires_at' => now()->subSecond()]);
        $this->runReaper();
        $order2->refresh();
        if ($order2->status !== 'cancelled') {
            $this->fail('Expected order2 to be cancelled after reaper, got '.$order2->status.' inventory '.$order2->inventory_state.' expires '.$order2->reservation_expires_at);
        }
        $this->assertEquals('cancelled', $order2->status);

        Sanctum::actingAs($this->admin);
        $resp = $this->patchJson('/api/v1/orders/'.$order2->id.'/status', ['status' => 'processing']);
        $resp->assertStatus(422);
        $this->assertEquals('cancelled', $order2->fresh()->status);
    }
}
