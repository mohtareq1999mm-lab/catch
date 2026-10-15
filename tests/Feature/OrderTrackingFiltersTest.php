<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\User;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class OrderTrackingFiltersTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

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
            DB::statement('DROP INDEX IF EXISTS idx_orders_user_pending_unique');
        } catch (\Throwable $e) {}

        Permission::firstOrCreate(['name' => 'view-orders', 'guard_name' => 'api']);

        $this->admin = User::factory()->create([
            'email' => 'tracking-filter-admin@test.com',
            'type' => 'admin',
        ]);
        $this->admin->givePermissionTo('view-orders');
    }

    private function createTestOrder(array $attributes = []): Order
    {
        static $seq = 1;
        $customer = User::factory()->create();
        $order = Order::create(array_merge([
            'user_id' => $customer->id,
            'name' => 'Customer ' . $seq,
            'user_email' => 'customer' . $seq . '@test.com',
            'user_phone' => '050000000' . ($seq % 10),
            'status' => 'pending',
            'payment_status' => Order::PAYMENT_STATUS_PENDING,
            'fulfillment_status' => 'pending',
            'price' => 100,
            'total_price' => 100,
            'currency_code' => 'SAR',
            'base_currency_code' => 'SAR',
            'fulfillment_type' => 'delivery',
            'payment_method' => 'online',
            'payment_gateway' => 'myfatoorah',
            'address' => ['city' => 'Riyadh'],
        ], $attributes));

        DB::table('orders')->where('id', $order->id)->update([
            'order_number' => 'ORD-FLT-' . str_pad((string)$seq++, 6, '0', STR_PAD_LEFT),
        ]);

        return $order->fresh();
    }

    public function test_filter_by_payment_status_aliases(): void
    {
        Sanctum::actingAs($this->admin);

        $orderPending = $this->createTestOrder(['payment_status' => Order::PAYMENT_STATUS_PENDING]);
        $orderPaid = $this->createTestOrder(['payment_status' => Order::PAYMENT_STATUS_SUCCESS]);
        $orderFailed = $this->createTestOrder(['payment_status' => Order::PAYMENT_STATUS_FAILED]);

        // Filter using alias 'pending'
        $res = $this->getJson('/api/v1/admin/tracking/orders?payment_status=pending');
        $res->assertStatus(200);
        $ids = collect($res->json('data.data'))->pluck('id')->all();
        $this->assertContains($orderPending->id, $ids);
        $this->assertNotContains($orderPaid->id, $ids);
        $this->assertNotContains($orderFailed->id, $ids);

        // Filter using alias 'paid'
        $res = $this->getJson('/api/v1/admin/tracking/orders?payment_status=paid');
        $res->assertStatus(200);
        $ids = collect($res->json('data.data'))->pluck('id')->all();
        $this->assertContains($orderPaid->id, $ids);
        $this->assertNotContains($orderPending->id, $ids);

        // Comma-separated payment statuses
        $res = $this->getJson('/api/v1/admin/tracking/orders?payment_status=paid,failed');
        $res->assertStatus(200);
        $ids = collect($res->json('data.data'))->pluck('id')->all();
        $this->assertContains($orderPaid->id, $ids);
        $this->assertContains($orderFailed->id, $ids);
        $this->assertNotContains($orderPending->id, $ids);
    }

    public function test_filter_by_payment_method_and_gateway(): void
    {
        Sanctum::actingAs($this->admin);

        $orderOnline = $this->createTestOrder(['payment_method' => 'online', 'payment_gateway' => 'myfatoorah']);
        $orderCod = $this->createTestOrder(['payment_method' => 'cash_on_delivery', 'payment_gateway' => null]);

        $res = $this->getJson('/api/v1/admin/tracking/orders?payment_method=cash_on_delivery');
        $res->assertStatus(200);
        $ids = collect($res->json('data.data'))->pluck('id')->all();
        $this->assertContains($orderCod->id, $ids);
        $this->assertNotContains($orderOnline->id, $ids);

        $res = $this->getJson('/api/v1/admin/tracking/orders?payment_gateway=myfatoorah');
        $res->assertStatus(200);
        $ids = collect($res->json('data.data'))->pluck('id')->all();
        $this->assertContains($orderOnline->id, $ids);
        $this->assertNotContains($orderCod->id, $ids);
    }

    public function test_filter_by_older_than_relative_dates(): void
    {
        Sanctum::actingAs($this->admin);

        $orderToday = $this->createTestOrder();
        $orderToday->created_at = Carbon::now()->subHours(2);
        $orderToday->save();

        $order3DaysAgo = $this->createTestOrder();
        $order3DaysAgo->created_at = Carbon::now()->subDays(3);
        $order3DaysAgo->save();

        $order45DaysAgo = $this->createTestOrder();
        $order45DaysAgo->created_at = Carbon::now()->subDays(45);
        $order45DaysAgo->save();

        // 1. older_than=2_days (or 2d)
        $res = $this->getJson('/api/v1/admin/tracking/orders?older_than=2_days');
        $res->assertStatus(200);
        $ids = collect($res->json('data.data'))->pluck('id')->all();
        $this->assertNotContains($orderToday->id, $ids);
        $this->assertContains($order3DaysAgo->id, $ids);
        $this->assertContains($order45DaysAgo->id, $ids);

        // 2. older_than=1_month (or 1m / 30d)
        $res = $this->getJson('/api/v1/admin/tracking/orders?older_than=1_month');
        $res->assertStatus(200);
        $ids = collect($res->json('data.data'))->pluck('id')->all();
        $this->assertNotContains($orderToday->id, $ids);
        $this->assertNotContains($order3DaysAgo->id, $ids);
        $this->assertContains($order45DaysAgo->id, $ids);

        // 3. older_than_days=3
        $res = $this->getJson('/api/v1/admin/tracking/orders?older_than_days=3');
        $res->assertStatus(200);
        $ids = collect($res->json('data.data'))->pluck('id')->all();
        $this->assertNotContains($orderToday->id, $ids);
        $this->assertContains($order3DaysAgo->id, $ids);
        $this->assertContains($order45DaysAgo->id, $ids);
    }

    public function test_filter_by_newer_than_and_within_days(): void
    {
        Sanctum::actingAs($this->admin);

        $orderToday = $this->createTestOrder();
        $orderToday->created_at = Carbon::now()->subHours(5);
        $orderToday->save();

        $order10DaysAgo = $this->createTestOrder();
        $order10DaysAgo->created_at = Carbon::now()->subDays(10);
        $order10DaysAgo->save();

        // newer_than=2d
        $res = $this->getJson('/api/v1/admin/tracking/orders?newer_than=2d');
        $res->assertStatus(200);
        $ids = collect($res->json('data.data'))->pluck('id')->all();
        $this->assertContains($orderToday->id, $ids);
        $this->assertNotContains($order10DaysAgo->id, $ids);

        // within_days=3
        $res = $this->getJson('/api/v1/admin/tracking/orders?within_days=3');
        $res->assertStatus(200);
        $ids = collect($res->json('data.data'))->pluck('id')->all();
        $this->assertContains($orderToday->id, $ids);
        $this->assertNotContains($order10DaysAgo->id, $ids);
    }
}
