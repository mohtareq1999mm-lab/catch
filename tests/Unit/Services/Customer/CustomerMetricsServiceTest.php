<?php

namespace Tests\Unit\Services\Customer;

use App\Services\Customer\CustomerMetricsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Marvel\Database\Models\CustomerMetrics;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\User;
use Tests\TestCase;
use Illuminate\Support\Str;

class CustomerMetricsServiceTest extends TestCase
{
    use RefreshDatabase;

    private CustomerMetricsService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(CustomerMetricsService::class);
    }

    private function createOrder(User $user, array $overrides = []): Order
    {
        static $counter = 0;
        $counter++;

        return Order::create(array_merge([
            'user_id' => $user->id,
            'order_number' => 'ORD-' . Str::random(10) . '-' . $counter,
            'name' => 'Test Customer',
            'user_phone' => '1234567890',
            'user_email' => 'test@example.com',
            'address' => 'Test Address',
            'status' => Order::ORDER_STATUS_COMPLETED,
            'payment_status' => Order::PAYMENT_STATUS_SUCCESS,
            'price' => 100.00,
            'total_price' => 100.00,
            'converted_total_price' => 100.00,
            'currency_code' => 'USD',
            'base_currency_code' => 'USD',
            'catalog_currency_code' => 'USD',
            'currency_rate' => 1.0,
        ], $overrides));
    }

    public function test_rebuild_for_user_creates_metrics_from_zero()
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        $this->createOrder($user1, ['converted_total_price' => 100.00]);
        $this->createOrder($user2, ['converted_total_price' => 50.00]);

        $metrics = $this->service->rebuildForUser($user1);

        $this->assertEquals(1, $metrics->completed_orders);
        $this->assertEquals(100.00, (float) $metrics->total_qualifying_order_value);
        $this->assertNotNull($metrics->first_order_at);
        $this->assertNotNull($metrics->last_order_at);
    }

    public function test_rebuild_ignores_non_qualifying_orders()
    {
        $user = User::factory()->create();

        // Qualifying
        $this->createOrder($user, ['converted_total_price' => 100.00]);

        // Non-qualifying: pending status (use different user to avoid unique constraint)
        $userPending = User::factory()->create();
        $this->createOrder($userPending, [
            'status' => Order::ORDER_STATUS_PENDING,
            'converted_total_price' => 200.00,
        ]);

        // Non-qualifying: payment failed
        $userFailed = User::factory()->create();
        $this->createOrder($userFailed, [
            'payment_status' => Order::PAYMENT_STATUS_FAILED,
            'converted_total_price' => 300.00,
        ]);

        $metrics = $this->service->rebuildForUser($user);

        $this->assertEquals(1, $metrics->completed_orders);
        $this->assertEquals(100.00, (float) $metrics->total_qualifying_order_value);
    }

    public function test_rebuild_is_idempotent()
    {
        $user = User::factory()->create();

        $this->createOrder($user, ['converted_total_price' => 100.00]);

        $first = $this->service->rebuildForUser($user);
        $second = $this->service->rebuildForUser($user);

        $this->assertEquals($first->completed_orders, $second->completed_orders);
        $this->assertEquals($first->total_qualifying_order_value, $second->total_qualifying_order_value);
    }

    public function test_get_metrics_returns_existing_metrics()
    {
        $user = User::factory()->create();

        \Marvel\Database\Models\CustomerMetrics::create([
            'user_id' => $user->id,
            'completed_orders' => 5,
            'total_qualifying_order_value' => 500.00,
            'computed_at' => now(),
        ]);

        $metrics = $this->service->getMetrics($user);

        $this->assertEquals(5, $metrics->completed_orders);
        $this->assertEquals(500.00, (float) $metrics->total_qualifying_order_value);
    }

    public function test_get_metrics_rebuilds_if_not_exists()
    {
        $user = User::factory()->create();

        $this->createOrder($user, ['converted_total_price' => 75.00]);

        $metrics = $this->service->getMetrics($user);

        $this->assertEquals(1, $metrics->completed_orders);
        $this->assertEquals(75.00, (float) $metrics->total_qualifying_order_value);
    }

    public function test_ensure_metrics_creates_zero_metrics_if_not_exists()
    {
        $user = User::factory()->create();

        $metrics = $this->service->ensureMetrics($user);

        $this->assertEquals(0, $metrics->completed_orders);
        $this->assertEquals(0.00, (float) $metrics->total_qualifying_order_value);
        $this->assertNull($metrics->first_order_at);
    }

    public function test_ensure_metrics_does_not_overwrite_existing()
    {
        $user = User::factory()->create();

        \Marvel\Database\Models\CustomerMetrics::create([
            'user_id' => $user->id,
            'completed_orders' => 3,
            'total_qualifying_order_value' => 300.00,
            'computed_at' => now(),
        ]);

        $metrics = $this->service->ensureMetrics($user);

        $this->assertEquals(3, $metrics->completed_orders);
        $this->assertEquals(300.00, (float) $metrics->total_qualifying_order_value);
    }
}
