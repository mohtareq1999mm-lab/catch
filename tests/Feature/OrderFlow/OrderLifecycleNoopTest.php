<?php

declare(strict_types=1);

namespace Tests\Feature\OrderFlow;

use App\Events\OrderCancelled;
use App\Events\OrderDelivered;
use App\Events\OrderStatusChanged;
use App\Events\PaymentSucceeded;
use App\Services\General\OrderService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Cart;
use Marvel\Database\Models\CartItem;
use Marvel\Database\Models\Country;
use Marvel\Database\Models\Governorate;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Settings;
use Marvel\Database\Models\ShippingPrice;
use Marvel\Database\Models\User;
use Marvel\Enums\ProductType;
use Marvel\Enums\ShippingMethod;
use Tests\Concerns\CreatesTestTables;
use Tests\TestCase;

/**
 * PHASE 05 — D2 self-transition true no-op + F-05 cancel-error mapping.
 *
 * Every self-transition (from === to) through the canonical writer must be a
 * successful no-op: no markers, no history, no invoice, no cascade, no
 * events. Terminal exits (cancelled/delivered → anything,
 * completed → cancelled) must stay rejected.
 */
class OrderLifecycleNoopTest extends TestCase
{
    use DatabaseTransactions, CreatesTestTables;

    private User $user;

    private User $admin;

    private Product $product;

    private Governorate $governorate;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        Queue::fake();

        $this->createAllTestTables();

        config(['payment.order_timeout_hours' => 24]);
        config(['payment.cod_order_timeout_hours' => 24 * 7]);

        if (!Settings::exists()) {
            Settings::create([
                'language' => 'en',
                'options' => ['catalog_currency_code' => 'EGP', 'base_currency_code' => 'EGP', 'currency' => 'EGP'],
                'minimum_order_amount' => 0]);
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

        $this->user = User::create([
            'name' => 'Buyer',
            'email' => 'noop-buyer@example.com',
            'password' => bcrypt('password'),
            'type' => 'user',
            'is_active' => true,
            'email_verified_at' => now()]);

        $this->admin = User::create([
            'name' => 'Admin',
            'email' => 'noop-admin@example.com',
            'password' => bcrypt('password'),
            'type' => 'admin',
            'is_active' => true,
            'email_verified_at' => now()]);
        if (Schema::hasTable('permissions')) {
            foreach (['update-order-status', 'payments.mark_paid'] as $name) {
                $perm = \Spatie\Permission\Models\Permission::firstOrCreate(['name' => $name, 'guard_name' => 'api']);
                $this->admin->givePermissionTo($perm);
            }
            (new \Database\Seeders\OrderStatusPermissionSeeder)->run();
        }
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
    }

    protected function tearDown(): void
    {
        \Mockery::close();
        parent::tearDown();
    }

    private function makeOrderFor(User $owner, string $status = 'pending'): Order
    {
        $flow = \App\Models\OrderFlow\OrderFlow::query()->where('shipping_type', 'local')->firstOrFail();
        $statusId = \App\Models\OrderFlow\OrderStatus::query()->where('code', $status)->value('id');

        return Order::create([
            'user_id' => $owner->id,
            'name' => 'Noop Buyer',
            'user_phone' => '01000000000',
            'user_email' => 'noop-buyer@example.com',
            'address' => json_encode(['city' => 'Cairo']),
            'price' => 100.00,
            'total_price' => 100.00,
            'status' => $status,
            'shipping_type' => 'local',
            'flow_id' => $flow->id,
            'current_status_id' => $statusId,
            'shipping_method' => 'SCHEDULED',
        ]);
    }

    private function historyCount(Order $order): int
    {
        if (!Schema::hasTable('order_status_history')) {
            return 0;
        }

        return (int) \Illuminate\Support\Facades\DB::table('order_status_history')
            ->where('order_id', $order->id)
            ->count();
    }

    private function fakeLifecycleEvents(): void
    {
        Event::fake([
            OrderStatusChanged::class,
            OrderCancelled::class,
            OrderDelivered::class,
            PaymentSucceeded::class,
        ]);
    }

    private function assertNoLifecycleEvents(): void
    {
        Event::assertNotDispatched(OrderStatusChanged::class);
        Event::assertNotDispatched(OrderCancelled::class);
        Event::assertNotDispatched(OrderDelivered::class);
        Event::assertNotDispatched(PaymentSucceeded::class);
    }

    // -----------------------------------------------------------------
    // D2 — self-transitions are successful true no-ops
    // -----------------------------------------------------------------

    public function test_pending_to_pending_is_successful_noop(): void
    {
        $order = $this->makeOrderFor($this->user, 'pending');
        $historyBefore = $this->historyCount($order);
        $updatedBefore = $order->fresh()->updated_at;

        Sanctum::actingAs($this->user);
        $this->fakeLifecycleEvents();

        $result = app(OrderService::class)->changeOrderStatus(null, 'pending', $order->id);

        $this->assertNotFalse($result);
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertSame($historyBefore, $this->historyCount($order));
        $this->assertTrue($updatedBefore->equalTo($order->fresh()->updated_at));
        $this->assertNoLifecycleEvents();
    }

    public function test_completed_to_completed_is_noop_without_repeated_side_effects(): void
    {
        $order = $this->makeOrderFor($this->user, 'pending');

        Sanctum::actingAs($this->admin);
        app(OrderService::class)->changeOrderStatus(null, 'completed', $order->id);
        $this->assertSame('completed', $order->fresh()->status);

        $historyBefore = $this->historyCount($order);
        $usageBefore = (int) \Marvel\Database\Models\Promotion::query()->sum('usage');

        $this->fakeLifecycleEvents();
        $result = app(OrderService::class)->changeOrderStatus(null, 'completed', $order->id);

        $this->assertNotFalse($result);
        $this->assertSame('completed', $order->fresh()->status);
        $this->assertSame($historyBefore, $this->historyCount($order));
        $this->assertSame($usageBefore, (int) \Marvel\Database\Models\Promotion::query()->sum('usage'));
        $this->assertNoLifecycleEvents();
    }

    public function test_cancelled_to_cancelled_is_noop_without_double_side_effects(): void
    {
        $order = $this->makeOrderFor($this->user, 'pending');

        Sanctum::actingAs($this->user);
        app(OrderService::class)->changeOrderStatus(null, 'cancelled', $order->id);
        $fresh = $order->fresh();
        $this->assertSame('cancelled', $fresh->status);

        $historyBefore = $this->historyCount($order);
        $inventoryBefore = $fresh->inventory_state;

        $this->fakeLifecycleEvents();
        $result = app(OrderService::class)->changeOrderStatus(null, 'cancelled', $order->id);

        $this->assertNotFalse($result);
        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame($inventoryBefore, $order->fresh()->inventory_state);
        $this->assertSame($historyBefore, $this->historyCount($order));
        $this->assertNoLifecycleEvents();
    }

    // -----------------------------------------------------------------
    // Terminal exits stay rejected (completed → cancelled pinned in
    // OrderStatusFlowTest; delivered exits pinned in OrderFlowMatrixTest)
    // -----------------------------------------------------------------

    public function test_cancelled_to_other_status_is_rejected(): void
    {
        $order = $this->makeOrderFor($this->user, 'pending');

        Sanctum::actingAs($this->user);
        app(OrderService::class)->changeOrderStatus(null, 'cancelled', $order->id);

        try {
            app(OrderService::class)->changeOrderStatus(null, 'processing', $order->id);
            $this->fail('cancelled → processing must be rejected.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('cancelled', $e->getMessage());
        }

        $this->assertSame('cancelled', $order->fresh()->status);
    }

    public function test_completed_to_cancelled_stays_forbidden(): void
    {
        $order = $this->makeOrderFor($this->user, 'pending');

        Sanctum::actingAs($this->admin);
        app(OrderService::class)->changeOrderStatus(null, 'completed', $order->id);

        try {
            app(OrderService::class)->changeOrderStatus(null, 'cancelled', $order->id);
            $this->fail('completed → cancelled must stay forbidden.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('completed', $e->getMessage());
        }

        $this->assertSame('completed', $order->fresh()->status);
    }

    // -----------------------------------------------------------------
    // F-05 — resolution-miss on customer cancel maps to the cancel error
    // -----------------------------------------------------------------

    public function test_customer_cancel_resolution_miss_returns_cancel_specific_error(): void
    {
        $order = $this->makeOrderFor($this->user, 'pending');
        $order->forceFill(['payment_status' => Order::PAYMENT_STATUS_PENDING])->save();

        $this->mock(OrderService::class, function ($mock) use ($order) {
            $mock->shouldReceive('getOrderForUser')->andReturn($order);
            $mock->shouldReceive('changeOrderStatus')->once()->andReturn(false);
        });

        Sanctum::actingAs($this->user);
        $response = $this->postJson("/api/v1/general/orders/{$order->id}/cancel");

        $response->assertStatus(500);
        $this->assertSame(
            __('message.ERROR.ERROR_CANCELLING_ORDER'),
            $response->json('message')
        );
        $this->assertNotSame(
            __('message.ERROR.ERROR_ADDING_ITEMS_TO_ORDER'),
            $response->json('message')
        );
    }
}
