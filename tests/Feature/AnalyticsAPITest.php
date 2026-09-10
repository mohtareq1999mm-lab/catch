<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\User;
use Tests\TestCase;

class AnalyticsAPITest extends TestCase
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
        try {
            \Illuminate\Support\Facades\DB::statement("SELECT 1 FROM order_analytics_hourly LIMIT 1");
        } catch (\Throwable $e) {
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
            'created_at' => now()->subHours(12),
        ], $overrides));
        $expected = 'ORD-' . str_pad((string) $order->id, 8, '0', STR_PAD_LEFT);
        \Illuminate\Support\Facades\DB::table('orders')->where('id', $order->id)->update(['order_number' => $expected]);
        return $order->refresh();
    }

    /** @test */
    public function admin_can_access_dashboard_analytics()
    {
        $admin = User::factory()->create(['type' => 'admin']);
        $user = User::factory()->create();
        for ($i = 0; $i < 5; $i++) {
            $this->createOrder($user, ['created_at' => now()->subHours(12)]);
        }

        Sanctum::actingAs($admin);
        $response = $this->getJson('/api/v1/admin/analytics/dashboard?period=24h');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'period',
                    'orders' => ['total', 'unique_customers', 'delivered'],
                    'revenue' => ['total_revenue', 'by_currency'],
                    'performance',
                    'customers',
                    'notifications',
                ],
            ]);
    }

    /** @test */
    public function admin_can_fetch_time_series_data()
    {
        $admin = User::factory()->create(['type' => 'admin']);
        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/admin/analytics/time-series?metric=orders&period=7d&granularity=day');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'metric',
                    'period',
                    'granularity',
                    'series',
                ],
            ]);
    }

    /** @test */
    public function validates_time_series_parameters()
    {
        $admin = User::factory()->create(['type' => 'admin']);
        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/admin/analytics/time-series?metric=invalid');

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['metric']);
    }

    /** @test */
    public function admin_can_export_orders()
    {
        $admin = User::factory()->create(['type' => 'admin']);
        $user = User::factory()->create();
        for ($i = 0; $i < 3; $i++) {
            $this->createOrder($user, ['created_at' => now()->subDays(2)]);
        }

        Sanctum::actingAs($admin);
        $response = $this->postJson('/api/v1/admin/analytics/export/orders', [
            'date_from' => now()->subDays(7)->toDateString(),
            'date_to' => now()->toDateString(),
        ]);

        $response->assertStatus(200);
        $this->assertTrue(str_contains($response->headers->get('content-type'), 'text/csv') || str_contains($response->headers->get('content-disposition'), '.csv'));
    }

    /** @test */
    public function dashboard_requires_authentication()
    {
        $response = $this->getJson('/api/v1/admin/analytics/dashboard?period=24h');
        $response->assertStatus(401);
    }

    /** @test */
    public function performance_endpoint_returns_sla_data()
    {
        $admin = User::factory()->create(['type' => 'admin']);
        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/admin/analytics/performance?period=24h');
        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['period', 'sla_compliance', 'bottlenecks']]);
    }

    /** @test */
    public function clear_cache_endpoint_works()
    {
        $admin = User::factory()->create(['type' => 'admin']);
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/v1/admin/analytics/clear-cache');
        $response->assertStatus(200)->assertJsonPath('success', true);
    }
}
