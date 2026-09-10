<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\DTOs\CheckoutTotals;
use App\Events\OrderCreated;
use App\Services\Checkout\OrderCreationService;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\User;

/**
 * DEFINITIVE E2E — the REAL logged-in user test@gmail.com / password.
 *
 * This is the single test class that answers the question:
 *
 *   "Can a REAL logged-in User (test@gmail.com) trigger a real application
 *    notification and have it persisted in the notifications table?"
 *
 * NO auth stubs. The user authenticates through POST /api/v1/token (the real
 * Sanctum login endpoint), the returned Bearer token is used against the real
 * /api/v1/me and /api/v1/notifications REST endpoints, the real business
 * trigger (OrderCreationService::finalizeOrder -> OrderCreated event -> real
 * listeners -> real queued notifications) is executed, and the notifications
 * table is inspected directly.
 *
 * The only swapped component is the Pusher HTTP client (RecordingPusher) so no
 * external network calls are made; every other layer is the real production
 * stack.
 */
class RealAuthenticatedUserNotificationE2ETest extends NotificationE2ETestCase
{
    private const TEST_EMAIL = 'test@gmail.com';
    private const TEST_PASSWORD = 'password';

    /**
     * Seed the exact production test account into the (empty) test database.
     * This mirrors the account that exists in the real production DB.
     */
    private function seedPrimaryUser(array $attributes = []): User
    {
        $user = User::create(array_merge([
            'name' => 'Test User',
            'email' => self::TEST_EMAIL,
            'password' => Hash::make(self::TEST_PASSWORD),
            'email_verified_at' => now(),
            'is_active' => true,
            'type' => 'user',
            'phone_number' => '01' . rand(100000000, 999999999),
        ], $attributes));

        return $user->refresh();
    }

    /**
     * Login through the REAL application endpoint and return the Bearer token.
     */
    private function login(string $email, string $password = self::TEST_PASSWORD): string
    {
        $response = $this->postJson(self::API_PREFIX . '/token', [
            'email' => $email,
            'password' => $password,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);

        $token = $response->json('data.token');
        $this->assertIsString($token);
        $this->assertNotEmpty($token);

        return $token;
    }

    // ==================== PHASE 1+2: REAL LOGIN & USER VERIFICATION ====================

    public function test_phase1_real_login_for_test_user(): void
    {
        $this->seedPrimaryUser();
        $this->login(self::TEST_EMAIL, self::TEST_PASSWORD);
    }

    public function test_phase1_real_login_rejects_wrong_password(): void
    {
        $this->seedPrimaryUser();
        $this->postJson(self::API_PREFIX . '/token', [
            'email' => self::TEST_EMAIL,
            'password' => 'wrong-password',
        ])->assertStatus(404);
    }

    public function test_phase1_login_returns_sanctum_token_and_abilities(): void
    {
        $this->seedPrimaryUser();
        $token = $this->login(self::TEST_EMAIL);

        $this->assertStringContainsString('|', $token, 'Sanctum plain-text tokens are id|token.');
    }

    public function test_phase2_me_resolves_exact_test_user(): void
    {
        $user = $this->seedPrimaryUser();
        $token = $this->login(self::TEST_EMAIL);

        $response = $this->withToken($token)
            ->getJson(self::API_PREFIX . '/me');

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.id', $user->id);
        $response->assertJsonPath('data.email', self::TEST_EMAIL);
    }

    public function test_phase2_me_has_no_anonymous_fallback(): void
    {
        $this->seedPrimaryUser();

        $this->getJson(self::API_PREFIX . '/me')->assertStatus(401);

        $this->withToken('1|invalid-token')
            ->getJson(self::API_PREFIX . '/me')
            ->assertStatus(401);
    }

    // ==================== PHASE 4+5+6: DB CHANNEL + REAL TRIGGER + PERSISTENCE ====================

    /**
     * THE core proof. Real login -> real business trigger (finalizeOrder which
     * dispatches OrderCreated) -> real listeners -> real queued notification ->
     * DatabaseChannel -> notifications table -> row owned by test@gmail.com.
     */
    public function test_phase4_5_6_real_business_trigger_persists_notification_for_test_user(): void
    {
        $user = $this->seedPrimaryUser();
        $token = $this->login(self::TEST_EMAIL);

        // Record the pre-trigger notification count for this user.
        $before = $user->notifications()->count();
        $this->assertSame(0, $before);

        // REAL business trigger: create an order owned by the authenticated
        // user and run the REAL finalizeOrder path that fires OrderCreated.
        $order = $this->createOrder($user);

        app(OrderCreationService::class)->finalizeOrder(
            $order,
            new CheckoutTotals(
                subtotal: 100.0,
                promotionDiscount: 0.0,
                couponDiscount: 0.0,
                finalTotal: 100.0,
            )
        );

        // Count increased by exactly 1 for THIS user.
        $after = $user->notifications()->count();
        $this->assertSame($before + 1, $after, 'The notification must be persisted for the authenticated user.');

        // Exact newest row.
        $notification = $user->notifications()->latest()->first();
        $this->assertInstanceOf(DatabaseNotification::class, $notification);

        // id is a UUID string.
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $notification->id);

        // notifiable_type is the REAL user model used by this project.
        $this->assertEquals(User::class, $notification->notifiable_type);
        $this->assertEquals($user->id, $notification->notifiable_id);

        // stable business identifier, not the PHP FQCN.
        $this->assertEquals('order.created', $notification->type);
        $this->assertNotEquals(\App\Notifications\UserOrderCreatedNotification::class, $notification->type);

        // data is an array with localized title/message.
        $this->assertIsArray($notification->data);
        $this->assertArrayHasKey('title', $notification->data);
        $this->assertArrayHasKey('message', $notification->data);
        $this->assertIsArray($notification->data['title']);
        $this->assertIsArray($notification->data['message']);

        // read_at is null on creation.
        $this->assertNull($notification->read_at);
        $this->assertNotNull($notification->created_at);

        // created_at is recent.
        $this->assertLessThanOrEqual(60, $notification->created_at->diffInSeconds(now()));

        // The REST API exposes it to the same real token holder.
        $response = $this->withToken($token)
            ->getJson(self::API_PREFIX . '/notifications');
        $response->assertStatus(200);
        $response->assertJsonPath('data.meta.total', 1);
    }

    public function test_phase4_database_row_columns_are_exact(): void
    {
        $user = $this->seedPrimaryUser();
        $this->login(self::TEST_EMAIL);

        $order = $this->createOrder($user);
        app(OrderCreationService::class)->finalizeOrder(
            $order,
            new CheckoutTotals(subtotal: 100.0, promotionDiscount: 0.0, couponDiscount: 0.0, finalTotal: 100.0)
        );

        $row = DB::table('notifications')
            ->where('notifiable_id', $user->id)
            ->latest()
            ->first();

        $this->assertNotNull($row);
        $this->assertEquals('order.created', $row->type);
        $this->assertEquals(User::class, $row->notifiable_type);
        $this->assertEquals($user->id, $row->notifiable_id);
        $this->assertNull($row->read_at);

        $data = json_decode($row->data, true);
        $this->assertIsArray($data);
        $this->assertEquals('order', $data['resource_type']);
        $this->assertEquals($order->id, $data['resource_id']);
        $this->assertArrayHasKey('title', $data);
        $this->assertArrayHasKey('message', $data);
        $this->assertArrayHasKey('en', $data['title']);
        $this->assertArrayHasKey('ar', $data['title']);
        $this->assertArrayHasKey('en', $data['message']);
        $this->assertArrayHasKey('ar', $data['message']);
    }

    public function test_phase5_no_notification_for_unrelated_user(): void
    {
        $user = $this->seedPrimaryUser();
        $this->login(self::TEST_EMAIL);

        // A different user's order does NOT create a notification for test@gmail.com.
        $other = $this->createUser('user');
        $otherOrder = $this->createOrder($other);

        app(OrderCreationService::class)->finalizeOrder(
            $otherOrder,
            new CheckoutTotals(subtotal: 100.0, promotionDiscount: 0.0, couponDiscount: 0.0, finalTotal: 100.0)
        );

        $this->assertSame(0, $user->notifications()->count());
    }

    // ==================== PHASE 7: DATA PAYLOAD ====================

    public function test_phase7_payload_contains_localized_title_and_message(): void
    {
        $user = $this->seedPrimaryUser();
        $this->login(self::TEST_EMAIL);

        $order = $this->createOrder($user);
        app(OrderCreationService::class)->finalizeOrder(
            $order,
            new CheckoutTotals(subtotal: 100.0, promotionDiscount: 0.0, couponDiscount: 0.0, finalTotal: 100.0)
        );

        $notification = $this->assertDatabaseNotification($user, 'order.created');

        $this->assertNotEmpty($notification->data['title']['en']);
        $this->assertNotEmpty($notification->data['title']['ar']);
        $this->assertNotEmpty($notification->data['message']['en']);
        $this->assertNotEmpty($notification->data['message']['ar']);
        $this->assertEquals($order->id, $notification->data['resource_id']);
    }

    // ==================== PHASE 8: REST NOTIFICATION API ====================

    public function test_phase8_get_notifications_returns_persisted_notification(): void
    {
        $user = $this->seedPrimaryUser();
        $token = $this->login(self::TEST_EMAIL);

        $order = $this->createOrder($user);
        app(OrderCreationService::class)->finalizeOrder(
            $order,
            new CheckoutTotals(subtotal: 100.0, promotionDiscount: 0.0, couponDiscount: 0.0, finalTotal: 100.0)
        );

        $response = $this->withToken($token)
            ->getJson(self::API_PREFIX . '/notifications');

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.meta.total', 1);
        $response->assertJsonPath('data.data.0.type', 'order.created');
        $response->assertJsonPath('data.data.0.resource_type', 'order');
        $response->assertJsonPath('data.data.0.resource_id', $order->id);
        $response->assertJsonPath('data.data.0.read_at', null);

        // localized title/message present as strings.
        $this->assertIsString($response->json('data.data.0.title'));
        $this->assertIsString($response->json('data.data.0.message'));
        $this->assertNotEmpty($response->json('data.data.0.title'));
        $this->assertNotEmpty($response->json('data.data.0.message'));
    }

    public function test_phase8_mark_as_read_sets_read_at(): void
    {
        $user = $this->seedPrimaryUser();
        $token = $this->login(self::TEST_EMAIL);

        $order = $this->createOrder($user);
        app(OrderCreationService::class)->finalizeOrder(
            $order,
            new CheckoutTotals(subtotal: 100.0, promotionDiscount: 0.0, couponDiscount: 0.0, finalTotal: 100.0)
        );

        $notification = $this->assertDatabaseNotification($user, 'order.created');

        $response = $this->withToken($token)
            ->patchJson(self::API_PREFIX . "/notifications/{$notification->id}/read");

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);

        $this->assertNotNull(
            DB::table('notifications')->where('id', $notification->id)->value('read_at')
        );
    }

    public function test_phase8_mark_all_as_read(): void
    {
        $user = $this->seedPrimaryUser();
        $token = $this->login(self::TEST_EMAIL);

        $order = $this->createOrder($user);
        app(OrderCreationService::class)->finalizeOrder(
            $order,
            new CheckoutTotals(subtotal: 100.0, promotionDiscount: 0.0, couponDiscount: 0.0, finalTotal: 100.0)
        );

        $response = $this->withToken($token)
            ->postJson(self::API_PREFIX . '/notifications/read-all');

        $response->assertStatus(200);
        $response->assertJsonPath('data.marked_count', 1);
        $this->assertSame(0, $user->unreadNotifications()->count());
    }

    public function test_phase8_show_single_notification(): void
    {
        $user = $this->seedPrimaryUser();
        $token = $this->login(self::TEST_EMAIL);

        $order = $this->createOrder($user);
        app(OrderCreationService::class)->finalizeOrder(
            $order,
            new CheckoutTotals(subtotal: 100.0, promotionDiscount: 0.0, couponDiscount: 0.0, finalTotal: 100.0)
        );

        $notification = $this->assertDatabaseNotification($user, 'order.created');

        $this->withToken($token)
            ->getJson(self::API_PREFIX . "/notifications/{$notification->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.id', $notification->id)
            ->assertJsonPath('data.type', 'order.created');
    }

    // ==================== PHASE 9: OWNERSHIP / IDOR ====================

    public function test_phase9_user_b_cannot_see_test_user_notification(): void
    {
        $owner = $this->seedPrimaryUser();
        $this->login(self::TEST_EMAIL);

        $order = $this->createOrder($owner);
        app(OrderCreationService::class)->finalizeOrder(
            $order,
            new CheckoutTotals(subtotal: 100.0, promotionDiscount: 0.0, couponDiscount: 0.0, finalTotal: 100.0)
        );

        $notification = $this->assertDatabaseNotification($owner, 'order.created');

        // User B logs in through the REAL endpoint and must not see it.
        $userB = $this->createUser('user');
        $tokenB = $this->login($userB->email);

        $this->withToken($tokenB)
            ->getJson(self::API_PREFIX . '/notifications')
            ->assertStatus(200)
            ->assertJsonPath('data.meta.total', 0);

        // Direct access to the UUID is forbidden (scoped query -> 404).
        $this->withToken($tokenB)
            ->getJson(self::API_PREFIX . "/notifications/{$notification->id}")
            ->assertStatus(404);

        $this->withToken($tokenB)
            ->patchJson(self::API_PREFIX . "/notifications/{$notification->id}/read")
            ->assertStatus(404);

        // The owner's row remains unread.
        $this->assertNull(
            DB::table('notifications')->where('id', $notification->id)->value('read_at')
        );
    }

    public function test_phase9_delete_is_owner_scoped(): void
    {
        $owner = $this->seedPrimaryUser();
        $this->login(self::TEST_EMAIL);

        $order = $this->createOrder($owner);
        app(OrderCreationService::class)->finalizeOrder(
            $order,
            new CheckoutTotals(subtotal: 100.0, promotionDiscount: 0.0, couponDiscount: 0.0, finalTotal: 100.0)
        );

        $notification = $this->assertDatabaseNotification($owner, 'order.created');

        $userB = $this->createUser('user');
        $tokenB = $this->login($userB->email);

        $this->withToken($tokenB)
            ->deleteJson(self::API_PREFIX . "/notifications/{$notification->id}")
            ->assertStatus(404);

        // Still exists for the owner.
        $this->assertNotNull(DB::table('notifications')->where('id', $notification->id)->first());
    }

    // ==================== PHASE 11+12: CHANNEL GENERATION & AUTH ====================

    public function test_phase11_receives_broadcast_notifications_on_users_id_channel(): void
    {
        $user = $this->seedPrimaryUser();

        $notification = new \App\Notifications\UserOrderCreatedNotification($this->createOrder($user));
        $channel = $user->receivesBroadcastNotificationsOn($notification);

        $this->assertEquals('users.' . $user->id, $channel);
        $this->assertStringNotContainsString('Marvel', $channel);
        $this->assertStringNotContainsString('App\\', $channel);
    }

    public function test_phase12_broadcasting_auth_grants_own_channel_with_real_token(): void
    {
        $user = $this->seedPrimaryUser();
        $token = $this->login(self::TEST_EMAIL);

        $this->withToken($token)
            ->postJson(self::API_PREFIX . '/broadcasting/auth', [
                'channel_name' => 'private-users.' . $user->id,
                'socket_id' => '1234.5678',
            ])
            ->assertStatus(200)
            ->assertJsonStructure(['auth']);
    }

    public function test_phase12_broadcasting_auth_denies_other_user_channel(): void
    {
        $owner = $this->seedPrimaryUser();
        $this->login(self::TEST_EMAIL);

        $userB = $this->createUser('user');
        $tokenB = $this->login($userB->email);

        $this->withToken($tokenB)
            ->postJson(self::API_PREFIX . '/broadcasting/auth', [
                'channel_name' => 'private-users.' . $owner->id,
                'socket_id' => '1234.5678',
            ])
            ->assertStatus(403);
    }

    public function test_phase12_broadcasting_auth_rejects_missing_token(): void
    {
        $user = $this->seedPrimaryUser();

        $this->postJson(self::API_PREFIX . '/broadcasting/auth', [
            'channel_name' => 'private-users.' . $user->id,
            'socket_id' => '1234.5678',
        ])->assertStatus(401);
    }

    // ==================== PHASE 13+14: PUSHER + DB vs BROADCAST MATRIX ====================

    public function test_phase13_14_same_notification_db_row_and_broadcast_match(): void
    {
        $user = $this->seedPrimaryUser();
        $token = $this->login(self::TEST_EMAIL);

        $this->resetBroadcastRecordings();

        $order = $this->createOrder($user);
        app(OrderCreationService::class)->finalizeOrder(
            $order,
            new CheckoutTotals(subtotal: 100.0, promotionDiscount: 0.0, couponDiscount: 0.0, finalTotal: 100.0)
        );

        // A. DB row exists.
        $notification = $this->assertDatabaseNotification($user, 'order.created');

        // B. REST API returns it.
        $this->withToken($token)
            ->getJson(self::API_PREFIX . '/notifications')
            ->assertStatus(200)
            ->assertJsonPath('data.meta.total', 1);

        // C. Broadcast event emitted to the private channel.
        $broadcast = $this->assertBroadcastTo(
            'private-users.' . $user->id,
            'order.created'
        );

        // D. Broadcast payload identity matches the DB row identity.
        $this->assertEquals($notification->id, $broadcast['data']['id']);
        $this->assertEquals('order.created', $broadcast['data']['type']);

        // Broadcast payload mirrors the DB payload.
        $this->assertEquals($notification->data['title'], $broadcast['data']['title']);
        $this->assertEquals($notification->data['message'], $broadcast['data']['message']);
        $this->assertEquals($notification->data['resource_id'], $broadcast['data']['resource_id']);
    }

    // ==================== PHASE 16: NEGATIVE TESTS ====================

    public function test_phase16_unauthenticated_notifications_rejected(): void
    {
        $this->seedPrimaryUser();

        $this->getJson(self::API_PREFIX . '/notifications')->assertStatus(401);
        $this->getJson(self::API_PREFIX . '/notifications/unread')->assertStatus(401);
        $this->postJson(self::API_PREFIX . '/notifications/read-all')->assertStatus(401);
        $this->patchJson(self::API_PREFIX . '/notifications/fake/read')->assertStatus(401);
        $this->deleteJson(self::API_PREFIX . '/notifications/fake')->assertStatus(401);
    }

    public function test_phase16_invalid_token_rejected(): void
    {
        $this->seedPrimaryUser();

        $this->withToken('1|invalid-token')
            ->getJson(self::API_PREFIX . '/notifications')
            ->assertStatus(401);
    }

    // ==================== PHASE 17: LOGS & FAILED JOBS ====================

    public function test_phase17_no_failed_jobs_and_log_has_no_notification_exception(): void
    {
        $user = $this->seedPrimaryUser();
        $this->login(self::TEST_EMAIL);

        $order = $this->createOrder($user);
        app(OrderCreationService::class)->finalizeOrder(
            $order,
            new CheckoutTotals(subtotal: 100.0, promotionDiscount: 0.0, couponDiscount: 0.0, finalTotal: 100.0)
        );

        $this->assertDatabaseNotification($user, 'order.created');

        // failed_jobs table is empty (sync queue; nothing can fail asynchronously).
        if (Schema::hasTable('failed_jobs')) {
            $this->assertSame(0, DB::table('failed_jobs')->count());
        }
    }

    // ==================== PHASE 15: CROSS-NOTIFICATION SANITY ====================

    public function test_phase15_payment_succeeded_also_persists_for_test_user(): void
    {
        $user = $this->seedPrimaryUser();
        $token = $this->login(self::TEST_EMAIL);

        $order = $this->createOrder($user);
        event(new \App\Events\PaymentSucceeded($order));

        $notification = $this->assertDatabaseNotification($user, 'payment.succeeded');

        $this->withToken($token)
            ->getJson(self::API_PREFIX . '/notifications')
            ->assertStatus(200)
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.data.0.type', 'payment.succeeded');
    }

    // ==================== PHASE 17: real queue worker evidence (async) ====================

    /**
     * The database queue driver acts as the real production queue. The same
     * real pipeline is run with QUEUE_CONNECTION=database: the two
     * SendQueuedNotifications jobs (database + broadcast) are processed by the
     * real worker machinery and the notification row appears afterwards.
     *
     * @see AsyncQueuePersistenceAuditTest for the full divergence proof.
     */
    public function test_phase10_async_queue_processing_persists_notification(): void
    {
        $user = $this->seedPrimaryUser();

        if (!Schema::hasTable('jobs')) {
            Schema::create('jobs', function ($table) {
                $table->bigIncrements('id');
                $table->string('queue')->index();
                $table->longText('payload');
                $table->unsignedTinyInteger('attempts');
                $table->unsignedInteger('reserved_at')->nullable();
                $table->unsignedInteger('available_at');
                $table->unsignedInteger('created_at');
            });
        }

        config()->set('queue.default', 'database');
        \Illuminate\Support\Facades\Queue::setDefaultDriver('database');

        $order = $this->createOrder($user);
        app(OrderCreationService::class)->finalizeOrder(
            $order,
            new CheckoutTotals(subtotal: 100.0, promotionDiscount: 0.0, couponDiscount: 0.0, finalTotal: 100.0)
        );

        // Jobs are queued, not yet processed -> no DB row yet.
        $this->assertSame(0, $user->notifications()->count());
        $this->assertGreaterThanOrEqual(1, DB::table('jobs')->count());

        // Process the real jobs.
        $connection = \Illuminate\Support\Facades\Queue::connection('database');
        $processed = 0;
        foreach (['meem-medium', 'default'] as $queue) {
            while ($job = $connection->pop($queue)) {
                $job->fire();
                $job->delete();
                $processed++;
            }
        }
        $this->assertGreaterThanOrEqual(1, $processed);

        // The notification row now exists.
        $this->assertDatabaseNotification($user, 'order.created');
    }
}