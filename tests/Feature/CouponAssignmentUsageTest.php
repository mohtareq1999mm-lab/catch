<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Str;
use Marvel\Database\Models\Coupon;
use Marvel\Database\Models\CouponAssignment;
use Marvel\Database\Models\CouponAssignmentUsage;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\User;
use Tests\TestCase;

class CouponAssignmentUsageTest extends TestCase
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

    private function createOrderForUser(User $user): Order
    {
        return Order::create([
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
        ]);
    }

    /** @test */
    public function cannot_create_usage_without_order_id()
    {
        $user = User::factory()->create();
        $coupon = Coupon::create([
            'code' => 'TEST_' . strtoupper(Str::random(6)),
            'name' => json_encode(['en' => 'Test']),
            'slug' => 'test-' . Str::random(6),
            'discount_type' => 'percentage',
            'discount' => 10,
            'status' => true,
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addDay()->toDateString(),
        ]);
        $assignment = CouponAssignment::create([
            'coupon_id' => $coupon->id,
            'user_id' => $user->id,
            'max_uses' => 3,
            'used' => 0,
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('order_id is required');

        CouponAssignmentUsage::create([
            'coupon_assignment_id' => $assignment->id,
            'order_id' => null,
            'used_at' => now(),
        ]);
    }

    /** @test */
    public function creates_usage_with_valid_order_id()
    {
        $user = User::factory()->create();
        $coupon = Coupon::create([
            'code' => 'TEST_' . strtoupper(Str::random(6)),
            'name' => json_encode(['en' => 'Test']),
            'slug' => 'test-' . Str::random(6),
            'discount_type' => 'percentage',
            'discount' => 10,
            'status' => true,
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addDay()->toDateString(),
        ]);
        $assignment = CouponAssignment::create([
            'coupon_id' => $coupon->id,
            'user_id' => $user->id,
            'max_uses' => 3,
            'used' => 0,
        ]);
        $order = $this->createOrderForUser($user);

        $usage = CouponAssignmentUsage::create([
            'coupon_assignment_id' => $assignment->id,
            'order_id' => $order->id,
            'used_at' => now(),
        ]);

        $this->assertDatabaseHas('coupon_assignment_usages', [
            'id' => $usage->id,
            'order_id' => $order->id,
        ]);
    }

    /** @test */
    public function unique_constraint_prevents_duplicate_usage_for_same_order()
    {
        $user = User::factory()->create();
        $coupon = Coupon::create([
            'code' => 'TEST_' . strtoupper(Str::random(6)),
            'name' => json_encode(['en' => 'Test']),
            'slug' => 'test-' . Str::random(6),
            'discount_type' => 'percentage',
            'discount' => 10,
            'status' => true,
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addDay()->toDateString(),
        ]);
        $assignment = CouponAssignment::create([
            'coupon_id' => $coupon->id,
            'user_id' => $user->id,
            'max_uses' => 3,
            'used' => 0,
        ]);
        $order = $this->createOrderForUser($user);

        CouponAssignmentUsage::create([
            'coupon_assignment_id' => $assignment->id,
            'order_id' => $order->id,
            'used_at' => now(),
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        CouponAssignmentUsage::create([
            'coupon_assignment_id' => $assignment->id,
            'order_id' => $order->id,
            'used_at' => now()->addMinute(),
        ]);
    }
}
