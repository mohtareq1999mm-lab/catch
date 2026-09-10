<?php

namespace Tests\Feature;

use App\Events\OrderStatusChanged;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\User;
use Tests\TestCase;

class OrderBroadcastingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
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
    }

    private function createOrder(User $user, string $status = 'pending'): Order
    {
        $order = Order::create([
            'user_id' => $user->id,
            'name' => $user->name,
            'user_email' => $user->email,
            'user_phone' => '0123456789',
            'status' => $status,
            'payment_status' => Order::PAYMENT_STATUS_PENDING,
            'fulfillment_status' => Order::FULFILLMENT_STATUS_PENDING,
            'price' => 100,
            'total_price' => 100,
            'fulfillment_type' => 'delivery',
            'payment_method' => 'online',
            'address' => ['city' => 'Cairo'],
        ]);
        $expected = 'ORD-' . str_pad((string) $order->id, 8, '0', STR_PAD_LEFT);
        \Illuminate\Support\Facades\DB::table('orders')->where('id', $order->id)->update(['order_number' => $expected]);
        return $order->refresh();
    }

    /** @test */
    public function order_status_change_event_broadcasts()
    {
        Event::fake([OrderStatusChanged::class]);

        $user = User::factory()->create();
        $order = $this->createOrder($user, 'pending');

        event(new OrderStatusChanged($order, 'pending', 'processing', $user->id, 'admin'));

        Event::assertDispatched(OrderStatusChanged::class, function ($event) use ($order) {
            return $event->order->id === $order->id
                && $event->oldStatus === 'pending'
                && $event->newStatus === 'processing';
        });
    }

    /** @test */
    public function event_broadcasts_on_correct_channels()
    {
        $user = User::factory()->create();
        $order = $this->createOrder($user, 'pending');

        $event = new OrderStatusChanged($order, 'pending', 'processing', $user->id, 'admin');
        $channels = $event->broadcastOn();

        $this->assertCount(2, $channels);
        $this->assertStringContainsString("user.{$user->id}.orders", $channels[0]->name);
        $this->assertStringContainsString("order.{$order->id}", $channels[1]->name);
    }

    /** @test */
    public function broadcast_payload_contains_expected_data()
    {
        $user = User::factory()->create();
        $order = $this->createOrder($user, 'pending');
        \Illuminate\Support\Facades\DB::table('orders')->where('id', $order->id)->update(['order_number' => 'ORD-12345678']);
        $order->refresh();

        $event = new OrderStatusChanged($order, 'pending', 'processing', 1, 'admin');
        $data = $event->broadcastWith();

        $this->assertArrayHasKey('order_id', $data);
        $this->assertArrayHasKey('order_number', $data);
        $this->assertEquals('ORD-12345678', $data['order_number']);
        $this->assertEquals('pending', $data['old_status']);
        $this->assertEquals('processing', $data['new_status']);
        $this->assertEquals('admin', $data['changed_by_type']);
        $this->assertArrayHasKey('changed_at', $data);
    }

    /** @test */
    public function broadcast_event_implements_should_broadcast()
    {
        $user = User::factory()->create();
        $order = $this->createOrder($user);

        $event = new OrderStatusChanged($order, 'pending', 'processing');

        $this->assertInstanceOf(\Illuminate\Contracts\Broadcasting\ShouldBroadcast::class, $event);
        $this->assertInstanceOf(\Illuminate\Contracts\Events\ShouldDispatchAfterCommit::class, $event);
        $this->assertEquals('order.status.changed', $event->broadcastAs());
    }
}
