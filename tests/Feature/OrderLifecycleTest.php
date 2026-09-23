<?php

namespace Tests\Feature;

use App\Services\General\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\User;
use Tests\TestCase;

/**
 * Phase 3 — Order lifecycle: single canonical writer (OrderService::changeOrderStatus),
 * legal transitions apply, illegal transitions throw WITHOUT mutation.
 */
class OrderLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrder(string $status = 'pending'): Order
    {
        $user = User::factory()->create(['type' => 'customer']);
        $order = Order::create([
            'user_id' => $user->id,
            'name' => $user->name,
            'user_email' => $user->email,
            'user_phone' => '01000000001',
            'status' => $status,
            'payment_status' => 'payment-pending',
            'fulfillment_status' => 'pending',
            'price' => 100,
            'total_price' => 100,
            'currency_code' => 'KWD',
            'base_currency_code' => $status === 'pending' ? 'KWD' : 'KWD',
            'fulfillment_type' => 'delivery',
            'payment_method' => 'online',
            'address' => ['city' => 'Cairo'],
        ]);
        \Illuminate\Support\Facades\DB::table('orders')->where('id', $order->id)
            ->update(['order_number' => 'ORD-' . str_pad((string) $order->id, 8, '0', STR_PAD_LEFT)]);

        return $order->refresh();
    }

    private function service(): OrderService
    {
        return app(OrderService::class);
    }

    public function test_legal_pending_to_processing_applies(): void
    {
        $order = $this->makeOrder('pending');
        $result = $this->service()->changeOrderStatus(null, 'processing', $order->id);
        $this->assertNotFalse($result);
        $this->assertEquals('processing', $order->refresh()->status);
    }

    public function test_legal_pending_to_cancelled_applies_and_releases(): void
    {
        $order = $this->makeOrder('pending');
        $result = $this->service()->changeOrderStatus(null, 'cancelled', $order->id);
        $this->assertNotFalse($result);
        $fresh = $order->refresh();
        $this->assertEquals('cancelled', $fresh->status);
        $this->assertNotEquals('active', $fresh->inventory_state);
    }

    public function test_illegal_completed_to_pending_throws_without_mutation(): void
    {
        $order = $this->makeOrder('completed');
        $before = $order->refresh()->getAttributes();
        try {
            $this->service()->changeOrderStatus(null, 'pending', $order->id);
            $this->fail('completed→pending must throw');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('pending', $e->getMessage());
        }
        $this->assertEquals($before['status'], $order->refresh()->status);
        $this->assertEquals($before['updated_at'], $order->refresh()->updated_at);
    }

    public function test_illegal_cancelled_to_completed_throws_without_mutation(): void
    {
        $order = $this->makeOrder('cancelled');
        try {
            $this->service()->changeOrderStatus(null, 'completed', $order->id);
            $this->fail('cancelled→completed must throw');
        } catch (\RuntimeException $e) {
            $this->assertNotEmpty($e->getMessage());
        }
        $this->assertEquals('cancelled', $order->refresh()->status);
    }

    public function test_illegal_delivered_to_cancelled_throws_without_mutation(): void
    {
        $order = $this->makeOrder('delivered');
        try {
            $this->service()->changeOrderStatus(null, 'cancelled', $order->id);
            $this->fail('delivered→cancelled must throw');
        } catch (\RuntimeException $e) {
            $this->assertNotEmpty($e->getMessage());
        }
        $this->assertEquals('delivered', $order->refresh()->status);
    }

    public function test_terminal_states_accept_same_state_resets(): void
    {
        $order = $this->makeOrder('completed');
        $result = $this->service()->changeOrderStatus(null, 'completed', $order->id);
        $this->assertNotFalse($result);
        $this->assertEquals('completed', $order->refresh()->status);
    }
}
