<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\User;
use Tests\TestCase;

class PaymentReconciliationTest extends TestCase
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

    private function createOrder(User $user, array $overrides = []): Order
    {
        $defaults = [
            'user_id' => $user->id,
            'name' => $user->name,
            'user_email' => $user->email,
            'user_phone' => '0123456789',
            'status' => Order::ORDER_STATUS_PENDING,
            'payment_status' => Order::PAYMENT_STATUS_PENDING,
            'fulfillment_status' => Order::FULFILLMENT_STATUS_PENDING,
            'price' => 100,
            'total_price' => 100,
            'fulfillment_type' => 'delivery',
            'payment_method' => 'online',
            'address' => ['city' => 'Cairo'],
        ];
        $order = Order::create(array_merge($defaults, $overrides));
        $expected = 'ORD-' . str_pad((string) $order->id, 8, '0', STR_PAD_LEFT);
        $raw = \Illuminate\Support\Facades\DB::table('orders')->where('id', $order->id)->value('order_number');
        if ($raw !== $expected) {
            \Illuminate\Support\Facades\DB::table('orders')->where('id', $order->id)->update(['order_number' => $expected]);
            $order->refresh();
        }
        return $order->refresh();
    }

    /** @test */
    public function customer_sees_verification_message_for_pending_payment()
    {
        $user = User::factory()->create();
        $order = $this->createOrder($user, [
            'status' => 'pending',
            'payment_status' => 'payment-pending',
            'payment_method' => 'online',
            'created_at' => now()->subMinutes(10),
            'updated_at' => now()->subMinutes(10),
        ]);
        // Force created_at to 10 mins ago (override mass assignment guard)
        \Illuminate\Support\Facades\DB::table('orders')->where('id', $order->id)->update(['created_at' => now()->subMinutes(10)]);
        $order->refresh();

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/v1/general/orders/{$order->id}/track");

        $response->assertStatus(200)
            ->assertJsonPath('data.current_status.verification_status', 'in_progress')
            ->assertJsonPath('data.current_status.verification_message', 'Payment verification in progress. This usually completes within a few minutes.');
    }

    /** @test */
    public function customer_sees_delayed_verification_after_30_minutes()
    {
        $user = User::factory()->create();
        $order = $this->createOrder($user, [
            'status' => 'pending',
            'payment_status' => 'payment-pending',
            'payment_method' => 'online',
        ]);
        \Illuminate\Support\Facades\DB::table('orders')->where('id', $order->id)->update(['created_at' => now()->subMinutes(45)]);
        $order->refresh();

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/v1/general/orders/{$order->id}/track");

        $response->assertStatus(200)
            ->assertJsonPath('data.current_status.verification_status', 'delayed');
    }

    /** @test */
    public function customer_sees_requires_attention_after_2_hours()
    {
        $user = User::factory()->create();
        $order = $this->createOrder($user, [
            'status' => 'pending',
            'payment_status' => 'payment-pending',
            'payment_method' => 'online',
        ]);
        \Illuminate\Support\Facades\DB::table('orders')->where('id', $order->id)->update(['created_at' => now()->subMinutes(130)]);
        $order->refresh();

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/v1/general/orders/{$order->id}/track");

        $response->assertStatus(200)
            ->assertJsonPath('data.current_status.verification_status', 'requires_attention')
            ->assertJsonPath('data.current_status.support_action', 'contact_support');
    }

    /** @test */
    public function admin_alerted_for_stuck_payment_verification()
    {
        // Create 3 stuck orders
        for ($i = 0; $i < 3; $i++) {
            $user = User::factory()->create();
            $order = $this->createOrder($user, [
                'status' => 'pending',
                'payment_status' => 'payment-pending',
                'payment_method' => 'online',
            ]);
            \Illuminate\Support\Facades\DB::table('orders')->where('id', $order->id)->update(['created_at' => now()->subMinutes(45)]);
        }

        $admin = User::factory()->create(['type' => 'admin']);
        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/admin/tracking/requires-attention');

        $response->assertStatus(200)
            ->assertJsonPath('data.payment_verification_stuck', 3);
        $response->assertJsonStructure(['data' => ['alerts']]);
        $alerts = $response->json('data.alerts');
        $this->assertNotEmpty($alerts);
    }

    /** @test */
    public function reconciliation_command_runs_every_15_minutes()
    {
        $schedule = app()->make(\Illuminate\Console\Scheduling\Schedule::class);

        $events = collect($schedule->events())->filter(function ($event) {
            return str_contains($event->command ?? '', 'payments:reconcile');
        });

        $this->assertCount(1, $events);

        $event = $events->first();
        $this->assertEquals('*/15 * * * *', $event->expression);
    }

    /** @test */
    public function non_online_pending_does_not_show_verification_message()
    {
        $user = User::factory()->create();
        $order = $this->createOrder($user, [
            'status' => 'pending',
            'payment_status' => 'payment-pending',
            'payment_method' => 'cod',
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/v1/general/orders/{$order->id}/track");

        $response->assertStatus(200);
        $this->assertArrayNotHasKey('verification_status', $response->json('data.current_status'));
    }
}
