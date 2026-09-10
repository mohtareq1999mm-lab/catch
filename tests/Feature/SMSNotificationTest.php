<?php

namespace Tests\Feature;

use App\Events\OrderStatusChanged;
use App\Listeners\SendOrderStatusSMS;
use App\Models\OrderNotification;
use App\Models\UserNotificationPreference;
use App\Services\Notifications\SMSService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\User;
use Tests\TestCase;

class SMSNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['user_notification_preferences', 'order_notifications', 'user_device_tokens'] as $table) {
            if (!Schema::hasTable($table)) {
                if ($table === 'user_notification_preferences') {
                    Schema::create($table, function (Blueprint $t) {
                        $t->id();
                        $t->unsignedBigInteger('user_id')->unique();
                        $t->boolean('email_enabled')->default(true);
                        $t->boolean('sms_enabled')->default(true);
                        $t->boolean('push_enabled')->default(true);
                        $t->boolean('websocket_enabled')->default(true);
                        $t->json('event_preferences')->nullable();
                        $t->boolean('email_verified')->default(false);
                        $t->boolean('phone_verified')->default(false);
                        $t->timestamp('email_verified_at')->nullable();
                        $t->timestamp('phone_verified_at')->nullable();
                        $t->time('quiet_hours_start')->nullable();
                        $t->time('quiet_hours_end')->nullable();
                        $t->boolean('respect_quiet_hours')->default(false);
                        $t->string('notification_language', 10)->default('en');
                        $t->timestamps();
                        $t->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
                    });
                } elseif ($table === 'order_notifications') {
                    Schema::create($table, function (Blueprint $t) {
                        $t->id();
                        $t->unsignedBigInteger('order_id');
                        $t->unsignedBigInteger('user_id');
                        $t->string('event_type', 50);
                        $t->string('channel', 20);
                        $t->enum('status', ['pending', 'sent', 'delivered', 'failed', 'skipped'])->default('pending');
                        $t->string('subject')->nullable();
                        $t->text('message')->nullable();
                        $t->timestamp('sent_at')->nullable();
                        $t->timestamp('delivered_at')->nullable();
                        $t->timestamp('failed_at')->nullable();
                        $t->text('failure_reason')->nullable();
                        $t->string('provider', 50)->nullable();
                        $t->string('provider_message_id')->nullable();
                        $t->json('provider_response')->nullable();
                        $t->json('metadata')->nullable();
                        $t->timestamps();
                        $t->foreign('order_id')->references('id')->on('orders')->onDelete('cascade');
                        $t->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
                    });
                } elseif ($table === 'user_device_tokens') {
                    Schema::create($table, function (Blueprint $t) {
                        $t->id();
                        $t->unsignedBigInteger('user_id');
                        $t->string('token', 500);
                        $t->enum('platform', ['ios', 'android', 'web'])->default('web');
                        $t->string('device_name')->nullable();
                        $t->string('device_id')->nullable();
                        $t->boolean('is_active')->default(true);
                        $t->timestamp('last_used_at')->nullable();
                        $t->timestamp('expires_at')->nullable();
                        $t->string('app_version')->nullable();
                        $t->string('os_version')->nullable();
                        $t->timestamps();
                        $t->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
                    });
                }
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
    }

    private function createOrder(User $user, string $status = 'pending'): Order
    {
        $order = Order::create([
            'user_id' => $user->id,
            'name' => $user->name,
            'user_email' => $user->email,
            'user_phone' => '+1234567890',
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
    public function sends_sms_on_order_processing()
    {
        $user = User::factory()->create();
        $order = $this->createOrder($user, 'pending');

        $prefs = UserNotificationPreference::forUser($user->id);
        $prefs->update(['phone_verified' => true, 'sms_enabled' => true, 'phone_verified_at' => now()]);

        $event = new OrderStatusChanged($order, 'pending', 'processing', $user->id, 'admin');
        $listener = app(SendOrderStatusSMS::class);
        $listener->handle($event);

        $this->assertDatabaseHas('order_notifications', [
            'order_id' => $order->id,
            'user_id' => $user->id,
            'channel' => 'sms',
        ]);
        $notif = OrderNotification::where('order_id', $order->id)->where('channel', 'sms')->latest()->first();
        $this->assertNotEquals('skipped', $notif->status);
    }

    /** @test */
    public function skips_sms_if_user_disabled()
    {
        $user = User::factory()->create();
        $order = $this->createOrder($user, 'pending');

        $prefs = UserNotificationPreference::forUser($user->id);
        $prefs->update(['sms_enabled' => false]);

        $event = new OrderStatusChanged($order, 'pending', 'processing', $user->id, 'admin');
        $listener = app(SendOrderStatusSMS::class);
        $listener->handle($event);

        $this->assertDatabaseHas('order_notifications', [
            'order_id' => $order->id,
            'channel' => 'sms',
            'status' => 'skipped',
        ]);
    }

    /** @test */
    public function skips_sms_during_quiet_hours_for_non_urgent()
    {
        $user = User::factory()->create();
        $order = $this->createOrder($user, 'pending');

        $prefs = UserNotificationPreference::forUser($user->id);
        $prefs->update([
            'phone_verified' => true,
            'sms_enabled' => true,
            'quiet_hours_start' => '22:00',
            'quiet_hours_end' => '08:00',
            'respect_quiet_hours' => true,
        ]);

        $this->travelTo(now()->setTime(23, 0));

        $event = new OrderStatusChanged($order, 'pending', 'processing', $user->id, 'admin'); // not urgent
        $listener = app(SendOrderStatusSMS::class);
        $listener->handle($event);

        $notification = OrderNotification::where('order_id', $order->id)->where('channel', 'sms')->latest()->first();
        $this->assertEquals('skipped', $notification->status);
        $this->assertStringContainsString('Quiet hours', $notification->failure_reason);

        $this->travelBack();
    }

    /** @test */
    public function allows_urgent_sms_during_quiet_hours()
    {
        $user = User::factory()->create();
        $order = $this->createOrder($user, 'pending');

        $prefs = UserNotificationPreference::forUser($user->id);
        $prefs->update([
            'phone_verified' => true,
            'sms_enabled' => true,
            'quiet_hours_start' => '22:00',
            'quiet_hours_end' => '08:00',
            'respect_quiet_hours' => true,
        ]);

        $this->travelTo(now()->setTime(23, 0));

        $event = new OrderStatusChanged($order, 'shipped', 'delivered', $user->id, 'admin'); // urgent
        $listener = app(SendOrderStatusSMS::class);
        $listener->handle($event);

        $notification = OrderNotification::where('order_id', $order->id)->where('channel', 'sms')->latest()->first();
        $this->assertNotEquals('skipped', $notification->status);

        $this->travelBack();
    }
}
