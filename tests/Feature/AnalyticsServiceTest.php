<?php

namespace Tests\Feature;

use App\Services\Analytics\OrderAnalyticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\User;
use Tests\TestCase;

class AnalyticsServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['user_notification_preferences', 'order_notifications', 'user_device_tokens'] as $t) {
            if (!Schema::hasTable($t)) {
                Schema::create($t, function (Blueprint $table) use ($t) {
                    if ($t === 'user_notification_preferences') {
                        $table->id();
                        $table->unsignedBigInteger('user_id')->unique();
                        $table->boolean('email_enabled')->default(true);
                        $table->boolean('sms_enabled')->default(true);
                        $table->boolean('push_enabled')->default(true);
                        $table->boolean('websocket_enabled')->default(true);
                        $table->json('event_preferences')->nullable();
                        $table->boolean('email_verified')->default(false);
                        $table->boolean('phone_verified')->default(false);
                        $table->timestamps();
                        $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
                    } elseif ($t === 'order_notifications') {
                        $table->id();
                        $table->unsignedBigInteger('order_id');
                        $table->unsignedBigInteger('user_id');
                        $table->string('event_type', 50);
                        $table->string('channel', 20);
                        $table->enum('status', ['pending', 'sent', 'delivered', 'failed', 'skipped'])->default('pending');
                        $table->string('subject')->nullable();
                        $table->text('message')->nullable();
                        $table->timestamps();
                        $table->foreign('order_id')->references('id')->on('orders')->onDelete('cascade');
                        $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
                    } else {
                        $table->id();
                        $table->unsignedBigInteger('user_id');
                        $table->string('token', 500);
                        $table->enum('platform', ['ios', 'android', 'web'])->default('web');
                        $table->boolean('is_active')->default(true);
                        $table->timestamps();
                        $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
                    }
                });
            }
        }
        try {
            \Illuminate\Support\Facades\DB::statement('DROP INDEX IF EXISTS idx_orders_user_pending_unique');
        } catch (\Throwable $e) {}
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
        // Create views if not exists (for sqlite)
        try {
            \Illuminate\Support\Facades\DB::statement("SELECT 1 FROM order_analytics_hourly LIMIT 1");
        } catch (\Throwable $e) {
            // Run migrations manually for sqlite
            $this->artisan('migrate', ['--force' => true]);
        }
    }

    private function createOrder(User $user, array $overrides = []): Order
    {
        $order = Order::create(array_merge([
            'user_id' => $user->id,
            'name' => $user->name,
            'user_email' => $user->email,
            'user_phone' => '0123456789',
            'status' => 'delivered',
            'payment_status' => 'payment-success',
            'fulfillment_status' => 'delivered',
            'price' => 100,
            'total_price' => 100,
            'fulfillment_type' => 'delivery',
            'payment_method' => 'online',
            'address' => ['city' => 'Cairo'],
        ], $overrides));
        $expected = 'ORD-' . str_pad((string) $order->id, 8, '0', STR_PAD_LEFT);
        \Illuminate\Support\Facades\DB::table('orders')->where('id', $order->id)->update(['order_number' => $expected, 'created_at' => $overrides['created_at'] ?? now(), 'updated_at' => now()]);
        return $order->refresh();
    }

    /** @test */
    public function calculates_dashboard_overview_correctly()
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->createOrder($user, [
                'status' => 'delivered',
                'payment_status' => 'payment-success',
                'total_price' => 100,
                'created_at' => now()->subHours(12),
            ]);
        }
        for ($i = 0; $i < 2; $i++) {
            $this->createOrder($user, [
                'status' => 'cancelled',
                'payment_status' => 'payment-failed',
                'total_price' => 50,
                'created_at' => now()->subHours(6),
            ]);
        }

        $service = app(OrderAnalyticsService::class);
        $overview = $service->getDashboardOverview('24h');

        $this->assertEquals(7, $overview['orders']['total']);
        $this->assertEquals(5, $overview['orders']['delivered']);
        $this->assertEquals(2, $overview['orders']['cancelled']);
        $this->assertEquals(500, $overview['revenue']['total_revenue']);
    }

    /** @test */
    public function generates_time_series_data()
    {
        $user = User::factory()->create();
        for ($i = 0; $i < 3; $i++) {
            $this->createOrder($user, [
                'payment_status' => 'payment-success',
                'total_price' => 100,
                'created_at' => now()->subDays($i),
            ]);
        }

        $service = app(OrderAnalyticsService::class);
        $timeSeries = $service->getTimeSeries('orders', '7d', 'day');

        $this->assertNotEmpty($timeSeries);
        $this->assertArrayHasKey('date', $timeSeries[0]);
        $this->assertArrayHasKey('value', $timeSeries[0]);
    }

    /** @test */
    public function identifies_top_customers()
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->createOrder($user1, ['payment_status' => 'payment-success', 'total_price' => 200, 'status' => 'delivered']);
        }
        for ($i = 0; $i < 2; $i++) {
            $this->createOrder($user2, ['payment_status' => 'payment-success', 'total_price' => 50, 'status' => 'delivered']);
        }

        $service = app(OrderAnalyticsService::class);
        $topCustomers = $service->getTopCustomers(10);

        $this->assertNotEmpty($topCustomers);
        // First should be user1 with 1000
        $first = (array) $topCustomers[0];
        $this->assertEquals($user1->id, $first['user_id']);
        $this->assertEquals(1000, $first['lifetime_value']);
    }
}
