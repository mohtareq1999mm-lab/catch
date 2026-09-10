<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Events\AssignedCouponConsumed;
use App\Events\CouponAssigned;
use App\Events\CouponCreated;
use App\Events\OrderCancelled;
use App\Events\OrderCreated;
use App\Events\PaymentFailed;
use App\Events\PaymentSucceeded;
use App\Events\RefundApproved;
use App\Listeners\SendUserCouponAssignedNotification;
use App\Listeners\SendUserCouponAvailableNotification;
use App\Listeners\SendUserCouponUsedNotification;
use App\Listeners\SendUserOrderCancelledNotification;
use App\Listeners\SendUserOrderCreatedNotification;
use App\Listeners\SendUserOrderDeliveredNotification;
use App\Listeners\SendUserOrderRefundedNotification;
use App\Listeners\SendUserPaymentFailedNotification;
use App\Listeners\SendUserPaymentSucceededNotification;
use App\Notifications\UserCouponAssignedNotification;
use App\Notifications\UserCouponAvailableNotification;
use App\Notifications\UserCouponUsedNotification;
use App\Notifications\UserOrderCancelledNotification;
use App\Notifications\UserOrderCreatedNotification;
use App\Notifications\UserOrderDeliveredNotification;
use App\Notifications\UserOrderRefundedNotification;
use App\Notifications\UserPaymentFailedNotification;
use App\Notifications\UserPaymentSucceededNotification;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Coupon;
use Marvel\Database\Models\CouponAssignment;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Refund;
use Marvel\Database\Models\User;
use App\Events\OrderDelivered;
use Tests\TestCase;

class UserNotificationTest extends TestCase
{

    use DatabaseTransactions;

    private const PREFIX = '/api/v1';

    protected function setUp(): void
    {
        parent::setUp();

        if (!Schema::hasTable('users')) {
            $this->createTables();
        }

        if (config('database.default') === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = ON;');
        }

        $this->beginDatabaseTransaction();
    }

    private function createTables(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->boolean('is_active')->default(true);
            $table->string('type')->default('user');
            $table->string('phone_number')->unique();
            $table->unsignedBigInteger('shop_id')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('order_number')->nullable();
            $table->string('name')->nullable();
            $table->string('status')->default('pending');
            $table->decimal('price', 10, 2)->nullable();
            $table->decimal('total_price', 10, 2)->nullable();
            $table->string('payment_status')->nullable();
            $table->softDeletes();
                                    $table->string('inventory_state', 16)->default('none');
            $table->timestamp('inventory_reserved_at')->nullable();
            $table->timestamp('reservation_expires_at')->nullable();
            $table->index(['status', 'reservation_expires_at']);
            $table->timestamps();
        });

        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->string('code')->nullable();
            $table->string('slug')->nullable();
            $table->string('name')->nullable();
            $table->string('discount_type')->nullable();
            $table->decimal('discount', 10, 2)->nullable();
            $table->decimal('max_discount_amount', 10, 2)->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('limiter')->nullable();
            $table->integer('used')->default(0);
            $table->boolean('status')->default(true);
            $table->boolean('borderless')->default(false);
            $table->string('border_color')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('coupon_assignments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('coupon_id');
            $table->unsignedBigInteger('user_id');
            $table->integer('max_uses')->default(1);
            $table->integer('used')->default(0);
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->decimal('amount', 10, 2)->nullable();
            $table->string('status')->nullable();
            $table->timestamps();
        });

        Schema::create('activity_log', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('log_name')->nullable();
            $table->text('description');
            $table->nullableMorphs('subject', 'subject');
            $table->nullableMorphs('causer', 'causer');
            $table->string('event')->nullable();
            $table->json('properties')->nullable();
            $table->uuid('batch_uuid')->nullable();
            $table->timestamps();
            $table->index('log_name');
        });
        // FCM channel resolves user device tokens during notification fanout.
        if (!Schema::hasTable('device_tokens')) {
            Schema::create('device_tokens', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('token')->unique();
                $table->string('device_type')->nullable();
                $table->timestamps();
            });
        }

    }

    private function createRegularUser(array $attributes = []): User
    {
        return User::create(array_merge([
            'name' => 'Regular User',
            'email' => 'user-' . Str::random(6) . '@example.com',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
            'is_active' => true,
            'type' => 'user',
            'phone_number' => '01' . rand(100000000, 999999999),
        ], $attributes));
    }

    private function createAdminUser(array $attributes = []): User
    {
        return User::create(array_merge([
            'name' => 'Admin User',
            'email' => 'admin-' . Str::random(6) . '@example.com',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
            'is_active' => true,
            'type' => 'admin',
            'phone_number' => '02' . rand(100000000, 999999999),
        ], $attributes));
    }

    private function createOrder(User $user): Order
    {
        return Order::withoutEvents(fn () => Order::create([
            'user_id' => $user->id,
            'order_number' => 'ORD-' . rand(10000000, 99999999),
            'status' => 'pending',
            'total_price' => 100.00,
            'payment_status' => 'pending',
        ]));
    }

    private function createCoupon(array $attributes = []): Coupon
    {
        return Coupon::withoutEvents(fn () => Coupon::create(array_merge([
            'code' => 'CPN' . strtoupper(Str::random(6)),
            'name' => 'Test Coupon',
            'discount_type' => 'fixed',
            'discount' => 10,
            'status' => true,
        ], $attributes)));
    }

    private function createRefund(User $user, Order $order): Refund
    {
        return Refund::withoutEvents(fn () => Refund::create([
            'customer_id' => $user->id,
            'order_id' => $order->id,
            'amount' => 50.00,
            'status' => 'approved',
        ]));
    }

    private function createUserNotification(User $user, array $data): DatabaseNotification
    {
        return DatabaseNotification::create([
            'id' => (string) Str::uuid(),
            'type' => UserOrderCreatedNotification::class,
            'notifiable_type' => User::class,
            'notifiable_id' => $user->id,
            'data' => $data,
            'read_at' => null,
        ]);
    }

    private function localeMapData(string $enTitle, string $arTitle, string $enBody, string $arBody): array
    {
        return [
            'title' => ['en' => $enTitle, 'ar' => $arTitle],
            'message' => ['en' => $enBody, 'ar' => $arBody],
            'icon' => 'shopping-cart',
            'resource_type' => 'order',
            'resource_id' => 1,
            'action_url' => '/orders/1',
        ];
    }

    /**
     * Assert the notification carries a locale map (en/ar) in title and message.
     */
    private function assertLocaleMap($notification): void
    {
        $data = $notification->toDatabase(new \stdClass());
        $this->assertIsArray($data['title']);
        $this->assertArrayHasKey('en', $data['title']);
        $this->assertArrayHasKey('ar', $data['title']);
        $this->assertIsArray($data['message']);
        $this->assertArrayHasKey('en', $data['message']);
        $this->assertArrayHasKey('ar', $data['message']);
    }

    // ==================== API AUTHENTICATION ====================

    public function test_unauthenticated_user_cannot_list_notifications(): void
    {
        $response = $this->getJson(self::PREFIX . '/notifications');
        $response->assertStatus(401);
    }

    public function test_unauthenticated_user_cannot_view_notification(): void
    {
        $response = $this->getJson(self::PREFIX . '/notifications/fake-id');
        $response->assertStatus(401);
    }

    public function test_unauthenticated_user_cannot_mark_as_read(): void
    {
        $response = $this->patchJson(self::PREFIX . '/notifications/fake-id/read');
        $response->assertStatus(401);
    }

    public function test_unauthenticated_user_cannot_mark_all_as_read(): void
    {
        $response = $this->postJson(self::PREFIX . '/notifications/read-all');
        $response->assertStatus(401);
    }

    public function test_unauthenticated_user_cannot_delete_notification(): void
    {
        $response = $this->deleteJson(self::PREFIX . '/notifications/fake-id');
        $response->assertStatus(401);
    }

    // ==================== API READ (OWNERSHIP) ====================

    public function test_user_can_list_own_notifications(): void
    {
        $user = $this->createRegularUser();
        Sanctum::actingAs($user);
        $this->createUserNotification($user, $this->localeMapData('EN', 'AR', 'EN body', 'AR body'));

        $response = $this->getJson(self::PREFIX . '/notifications');

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.meta.total', 1);
    }

    public function test_user_can_view_single_notification(): void
    {
        $user = $this->createRegularUser();
        Sanctum::actingAs($user);
        $notification = $this->createUserNotification($user, $this->localeMapData('EN', 'AR', 'EN body', 'AR body'));

        $response = $this->getJson(self::PREFIX . "/notifications/{$notification->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('data.id', $notification->id);
    }

    public function test_user_cannot_view_another_users_notification(): void
    {
        $owner = $this->createRegularUser();
        $other = $this->createRegularUser();
        $notification = $this->createUserNotification($owner, $this->localeMapData('EN', 'AR', 'EN body', 'AR body'));

        Sanctum::actingAs($other);
        $response = $this->getJson(self::PREFIX . "/notifications/{$notification->id}");

        $response->assertStatus(404);
    }

    public function test_user_can_mark_notification_as_read(): void
    {
        $user = $this->createRegularUser();
        Sanctum::actingAs($user);
        $notification = $this->createUserNotification($user, $this->localeMapData('EN', 'AR', 'EN body', 'AR body'));

        $response = $this->patchJson(self::PREFIX . "/notifications/{$notification->id}/read");

        $response->assertStatus(200);
        $this->assertNotNull($response->json('data.read_at'));
    }

    public function test_user_cannot_mark_another_users_notification_as_read(): void
    {
        $owner = $this->createRegularUser();
        $other = $this->createRegularUser();
        $notification = $this->createUserNotification($owner, $this->localeMapData('EN', 'AR', 'EN body', 'AR body'));

        Sanctum::actingAs($other);
        $response = $this->patchJson(self::PREFIX . "/notifications/{$notification->id}/read");

        $response->assertStatus(404);
    }

    public function test_user_can_mark_all_as_read(): void
    {
        $user = $this->createRegularUser();
        Sanctum::actingAs($user);
        $this->createUserNotification($user, $this->localeMapData('EN', 'AR', 'b', 'b'));
        $this->createUserNotification($user, $this->localeMapData('EN', 'AR', 'b', 'b'));

        $response = $this->postJson(self::PREFIX . '/notifications/read-all');

        $response->assertStatus(200);
        $response->assertJsonPath('data.marked_count', 2);
        $this->assertEquals(0, $user->unreadNotifications()->count());
    }

    public function test_user_can_delete_notification(): void
    {
        $user = $this->createRegularUser();
        Sanctum::actingAs($user);
        $notification = $this->createUserNotification($user, $this->localeMapData('EN', 'AR', 'b', 'b'));

        $response = $this->deleteJson(self::PREFIX . "/notifications/{$notification->id}");

        $response->assertStatus(200);
        $this->assertNull(DatabaseNotification::find($notification->id));
    }

    public function test_user_cannot_delete_another_users_notification(): void
    {
        $owner = $this->createRegularUser();
        $other = $this->createRegularUser();
        $notification = $this->createUserNotification($owner, $this->localeMapData('EN', 'AR', 'b', 'b'));

        Sanctum::actingAs($other);
        $response = $this->deleteJson(self::PREFIX . "/notifications/{$notification->id}");

        $response->assertStatus(404);
    }

    // ==================== API LOCALIZATION ====================

    public function test_notification_title_resolves_to_english_by_default(): void
    {
        $user = $this->createRegularUser();
        Sanctum::actingAs($user);
        $this->createUserNotification($user, $this->localeMapData('EN_TITLE', 'AR_TITLE', 'EN_BODY', 'AR_BODY'));

        $response = $this->getJson(self::PREFIX . '/notifications');

        $response->assertStatus(200);
        $response->assertJsonPath('data.data.0.title', 'EN_TITLE');
        $response->assertJsonPath('data.data.0.message', 'EN_BODY');
    }

    public function test_notification_title_resolves_to_arabic_via_lang_header(): void
    {
        $user = $this->createRegularUser();
        Sanctum::actingAs($user);
        $this->createUserNotification($user, $this->localeMapData('EN_TITLE', 'AR_TITLE', 'EN_BODY', 'AR_BODY'));

        $response = $this->withHeader('lang', 'ar')->getJson(self::PREFIX . '/notifications');

        $response->assertStatus(200);
        $response->assertJsonPath('data.data.0.title', 'AR_TITLE');
        $response->assertJsonPath('data.data.0.message', 'AR_BODY');
    }

    public function test_single_notification_resolves_locale_via_lang_header(): void
    {
        $user = $this->createRegularUser();
        Sanctum::actingAs($user);
        $notification = $this->createUserNotification($user, $this->localeMapData('EN_TITLE', 'AR_TITLE', 'EN_BODY', 'AR_BODY'));

        $response = $this->withHeader('lang', 'ar')->getJson(self::PREFIX . "/notifications/{$notification->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('data.title', 'AR_TITLE');
    }

    // ==================== EVENT-DRIVEN NOTIFICATIONS ====================

    public function test_order_created_notifies_the_user(): void
    {
        Notification::fake();
        $user = $this->createRegularUser();
        $order = $this->createOrder($user);

        (new SendUserOrderCreatedNotification())->handle(new OrderCreated($order));

        Notification::assertSentTo($user, UserOrderCreatedNotification::class, function ($n) {
            $this->assertLocaleMap($n);
            $this->assertEquals('order.created', $n->broadcastType());
            return true;
        });
    }

    public function test_order_created_does_not_notify_admin(): void
    {
        Notification::fake();
        $admin = $this->createAdminUser();
        $order = $this->createOrder($admin);

        (new SendUserOrderCreatedNotification())->handle(new OrderCreated($order));

        Notification::assertNotSentTo($admin, UserOrderCreatedNotification::class);
    }

    public function test_payment_succeeded_notifies_the_user(): void
    {
        Notification::fake();
        $user = $this->createRegularUser();
        $order = $this->createOrder($user);

        (new SendUserPaymentSucceededNotification())->handle(new PaymentSucceeded($order));

        Notification::assertSentTo($user, UserPaymentSucceededNotification::class, function ($n) {
            $this->assertLocaleMap($n);
            $this->assertEquals('payment.succeeded', $n->broadcastType());
            return true;
        });
    }

    public function test_payment_failed_notifies_the_user(): void
    {
        Notification::fake();
        $user = $this->createRegularUser();
        $order = $this->createOrder($user);

        (new SendUserPaymentFailedNotification())->handle(new PaymentFailed($order));

        Notification::assertSentTo($user, UserPaymentFailedNotification::class, function ($n) {
            $this->assertLocaleMap($n);
            $this->assertEquals('payment.failed', $n->broadcastType());
            return true;
        });
    }

    public function test_order_delivered_notifies_the_user(): void
    {
        Notification::fake();
        $user = $this->createRegularUser();
        $order = $this->createOrder($user);

        (new SendUserOrderDeliveredNotification())->handle(new OrderDelivered($order));

        Notification::assertSentTo($user, UserOrderDeliveredNotification::class, function ($n) {
            $this->assertLocaleMap($n);
            $this->assertEquals('order.delivered', $n->broadcastType());
            return true;
        });
    }

    public function test_order_cancelled_notifies_the_user(): void
    {
        Notification::fake();
        $user = $this->createRegularUser();
        $order = $this->createOrder($user);

        (new SendUserOrderCancelledNotification())->handle(new OrderCancelled($order));

        Notification::assertSentTo($user, UserOrderCancelledNotification::class, function ($n) {
            $this->assertLocaleMap($n);
            $this->assertEquals('order.cancelled', $n->broadcastType());
            return true;
        });
    }

    public function test_refund_approved_notifies_the_user(): void
    {
        Notification::fake();
        $user = $this->createRegularUser();
        $order = $this->createOrder($user);
        $refund = $this->createRefund($user, $order);

        (new SendUserOrderRefundedNotification())->handle(new RefundApproved($refund));

        Notification::assertSentTo($user, UserOrderRefundedNotification::class, function ($n) {
            $this->assertLocaleMap($n);
            $this->assertEquals('order.refunded', $n->broadcastType());
            return true;
        });
    }

    public function test_coupon_assigned_notifies_the_assigned_user(): void
    {
        Notification::fake();
        $user = $this->createRegularUser();
        $coupon = $this->createCoupon();
        $assignment = CouponAssignment::create([
            'coupon_id' => $coupon->id,
            'user_id' => $user->id,
            'max_uses' => 1,
        ]);

        (new SendUserCouponAssignedNotification())->handle(new CouponAssigned($assignment));

        Notification::assertSentTo($user, UserCouponAssignedNotification::class, function ($n) {
            $this->assertLocaleMap($n);
            $this->assertEquals('coupon.assigned', $n->broadcastType());
            return true;
        });
    }

    /**
     * SCENARIO 2: users = [$userA, $userB, $userC]
     * The assignment flow calls assignCoupon() once per selected user, so the
     * CouponAssigned event fires once per user. Each user must receive exactly
     * ONE coupon_assigned notification (no duplicates).
     */
    public function test_coupon_assigned_notifies_each_user_exactly_once_for_multiple_users(): void
    {
        Notification::fake();
        $userA = $this->createRegularUser();
        $userB = $this->createRegularUser();
        $userC = $this->createRegularUser();
        $coupon = $this->createCoupon();

        // Simulate the assignment flow iterating over the selected users[] array.
        foreach ([$userA, $userB, $userC] as $recipient) {
            $assignment = CouponAssignment::create([
                'coupon_id' => $coupon->id,
                'user_id' => $recipient->id,
                'max_uses' => 1,
            ]);
            (new SendUserCouponAssignedNotification())->handle(new CouponAssigned($assignment));
        }

        Notification::assertSentToTimes($userA, UserCouponAssignedNotification::class, 1);
        Notification::assertSentToTimes($userB, UserCouponAssignedNotification::class, 1);
        Notification::assertSentToTimes($userC, UserCouponAssignedNotification::class, 1);
    }

    /**
     * SCENARIO 1 + user-type guard: users[] may contain a non-user (admin)
     * account. Notifications must only go to valid users.type='user' accounts.
     */
    public function test_coupon_assigned_skips_non_user_accounts_in_selection(): void
    {
        Notification::fake();
        $regular = $this->createRegularUser();
        $admin = $this->createAdminUser();
        $coupon = $this->createCoupon();

        foreach ([$regular, $admin] as $recipient) {
            $assignment = CouponAssignment::create([
                'coupon_id' => $coupon->id,
                'user_id' => $recipient->id,
                'max_uses' => 1,
            ]);
            (new SendUserCouponAssignedNotification())->handle(new CouponAssigned($assignment));
        }

        Notification::assertSentToTimes($regular, UserCouponAssignedNotification::class, 1);
        Notification::assertNotSentTo($admin, UserCouponAssignedNotification::class);
    }

    /**
     * SCENARIO 3: users = [] -> no assignment calls -> no notifications.
     */
    public function test_no_coupon_assigned_notifications_when_selection_is_empty(): void
    {
        Notification::fake();

        // With an empty selection the assignment flow never calls assignCoupon(),
        // so the listener is never invoked. Verify the listener is a no-op
        // when the underlying assignment has no resolvable user.
        $coupon = $this->createCoupon();
        $assignment = CouponAssignment::create([
            'coupon_id' => $coupon->id,
            'user_id' => 999999,
            'max_uses' => 1,
        ]);

        (new SendUserCouponAssignedNotification())->handle(new CouponAssigned($assignment));

        Notification::assertNothingSent();
    }

    /**
     * Integration check: the real assignment boundary (CouponAssignmentRepository::assignCoupon)
     * must dispatch CouponAssigned exactly once per call, producing exactly one notification
     * for the assigned user. This proves the notification reuses the existing flow without a
     * second event dispatch or assignment loop.
     */
    public function test_assign_coupon_repository_dispatches_one_notification(): void
    {
        Notification::fake();
        $user = $this->createRegularUser();
        $coupon = $this->createCoupon();

        $repository = app(\Marvel\Database\Repositories\CouponAssignmentRepository::class);
        $repository->assignCoupon($coupon->id, [
            'user_id' => $user->id,
            'max_uses' => 1,
        ]);

        Notification::assertSentToTimes($user, UserCouponAssignedNotification::class, 1);
    }

    public function test_coupon_available_notifies_all_users_but_not_admins(): void
    {
        Notification::fake();
        $user = $this->createRegularUser();
        $admin = $this->createAdminUser();
        $coupon = $this->createCoupon();

        (new SendUserCouponAvailableNotification())->handle(new CouponCreated($coupon));

        Notification::assertSentTo($user, UserCouponAvailableNotification::class, function ($n) {
            $this->assertLocaleMap($n);
            $this->assertEquals('coupon.available', $n->broadcastType());
            return true;
        });
        Notification::assertNotSentTo($admin, UserCouponAvailableNotification::class);
    }

    public function test_coupon_available_skips_when_coupon_has_assignments(): void
    {
        Notification::fake();
        $user = $this->createRegularUser();
        $coupon = $this->createCoupon();
        CouponAssignment::create([
            'coupon_id' => $coupon->id,
            'user_id' => $user->id,
            'max_uses' => 1,
        ]);

        (new SendUserCouponAvailableNotification())->handle(new CouponCreated($coupon));

        Notification::assertNotSentTo($user, UserCouponAvailableNotification::class);
    }

    public function test_coupon_used_notifies_the_user(): void
    {
        Notification::fake();
        $user = $this->createRegularUser();
        $order = $this->createOrder($user);
        $coupon = $this->createCoupon();
        $assignment = CouponAssignment::create([
            'coupon_id' => $coupon->id,
            'user_id' => $user->id,
            'max_uses' => 1,
        ]);

        (new SendUserCouponUsedNotification())->handle(
            new AssignedCouponConsumed($coupon, $assignment, $user, $order, 0, now())
        );

        Notification::assertSentTo($user, UserCouponUsedNotification::class, function ($n) {
            $this->assertLocaleMap($n);
            $this->assertEquals('coupon.used', $n->broadcastType());
            return true;
        });
    }
}
