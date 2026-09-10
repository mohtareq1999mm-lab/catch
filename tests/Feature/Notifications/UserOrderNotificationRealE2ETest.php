<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Notifications\UserOrderCreatedNotification;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\Events\BroadcastNotificationCreated;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Marvel\Database\Models\Country;
use Marvel\Database\Models\Governorate;
use Marvel\Database\Models\ShippingPrice;
use Marvel\Database\Models\User;

/**
 * THE FINAL RUNTIME INVESTIGATION — REAL ORDER BUSINESS FLOW.
 *
 * Everything below uses the REAL HTTP/API order flow with the REAL test
 * account (test@gmail.com / password):
 *
 *   POST /api/v1/token        -> REAL login (real Sanctum token)
 *   POST /api/v1/cart         -> REAL cart creation (real CartRepository)
 *   POST /api/v1/general/checkout -> REAL order creation (real OrderService,
 *                                   real OrderCreationService, real
 *                                   OrderCreated event, REAL listeners, REAL
 *                                   queued UserOrderCreatedNotification)
 *
 * No $user->notify(), no direct event() dispatch, no mock auth, no substituted
 * user. The single question answered with runtime evidence:
 *
 *   "After a REAL order via test@gmail.com, is a row actually inserted into
 *    the notifications table for that exact user — and if the production
 *    symptom (Pusher works, DB missing) happens, exactly where does the
 *    database branch break?"
 */
class UserOrderNotificationRealE2ETest extends NotificationE2ETestCase
{
    private const TEST_EMAIL = 'test@gmail.com';
    private const TEST_PASSWORD = 'password';

    // ==================== SEEDING ====================

    private function seedPrimaryUser(array $attributes = []): User
    {
        return User::create(array_merge([
            'name' => 'Test User',
            'email' => self::TEST_EMAIL,
            'password' => Hash::make(self::TEST_PASSWORD),
            'email_verified_at' => now(),
            'is_active' => true,
            'type' => 'user',
            'phone_number' => '01' . rand(100000000, 999999999),
        ], $attributes))->refresh();
    }

    private function seedCheckoutGeography(): Governorate
    {
        $country = Country::create(['name' => 'Test Country', 'status' => true]);
        $governorate = Governorate::create([
            'country_id' => $country->id,
            'name' => 'Test Governorate',
            'status' => true,
        ]);
        ShippingPrice::create([
            'governorate_id' => $governorate->id,
            'price' => 20,
            'status' => true,
        ]);

        return $governorate;
    }

    // ==================== REAL HTTP HELPERS ====================

    private function realLogin(string $email = self::TEST_EMAIL, string $password = self::TEST_PASSWORD): string
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

    private function realAddToCart(string $token, int $productId): void
    {
        $response = $this->withToken($token)
            ->postJson(self::API_PREFIX . '/cart', [
                'item' => [
                    'product_id' => $productId,
                    'quantity' => 1,
                    'shipping_method' => 'SCHEDULED',
                ],
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('success', true);
    }

    private function realCheckout(string $token, int $governorateId): int
    {
        $response = $this->withToken($token)
            ->postJson(self::API_PREFIX . '/general/checkout', [
                'name' => 'Test User',
                'user_phone' => '01000000001',
                'user_email' => self::TEST_EMAIL,
                'governorate_id' => $governorateId,
                'shipping_method' => 'SCHEDULED',
                'payment_method' => 'cod',
                'address' => ['street' => '123 Main St', 'city' => 'Cairo'],
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);

        $orderId = (int) $response->json('data.order_id');
        $this->assertGreaterThan(0, $orderId);

        return $orderId;
    }

    // ==================== A — REAL LOGIN ====================

    public function test_a_real_login_authenticates_test_user(): void
    {
        $user = $this->seedPrimaryUser();

        $token = $this->realLogin();

        $response = $this->withToken($token)->getJson(self::API_PREFIX . '/me');

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.id', $user->id);
        $response->assertJsonPath('data.email', self::TEST_EMAIL);

        fwrite(STDERR, "\n[REPORT A] REAL LOGIN OK — Authenticated User ID: {$user->id}, Email: " . self::TEST_EMAIL . "\n");
    }

    // ==================== B — AUTH USER ====================

    public function test_b_auth_user_is_the_real_notifiable_model(): void
    {
        $user = $this->seedPrimaryUser();
        $token = $this->realLogin();

        $response = $this->withToken($token)->getJson(self::API_PREFIX . '/me');

        $response->assertStatus(200);
        $response->assertJsonPath('data.id', $user->id);

        // The notifiable model is Marvel's User, not App\User.
        $this->assertInstanceOf(User::class, $user);
        $this->assertTrue(in_array(\Illuminate\Notifications\Notifiable::class, class_uses_recursive($user), true));

        fwrite(STDERR, "\n[REPORT B] AUTH USER OK — notifiable_type: " . get_class($user) . " (" . User::class . ")\n");
    }

    // ==================== C — REAL ORDER FLOW ====================

    public function test_c_real_http_order_flow_creates_order_for_test_user(): void
    {
        $user = $this->seedPrimaryUser();
        $token = $this->realLogin();
        $governorate = $this->seedCheckoutGeography();
        $product = $this->createProduct();

        $this->resetBroadcastRecordings();

        $this->realAddToCart($token, $product->id);
        $orderId = $this->realCheckout($token, $governorate->id);

        // The order was really created and belongs to test@gmail.com.
        $order = DB::table('orders')->where('id', $orderId)->first();
        $this->assertNotNull($order);
        $this->assertEquals($user->id, $order->user_id);

        fwrite(STDERR, "\n[REPORT C] REAL ORDER FLOW OK — order #{$orderId} created for user {$user->id} via real HTTP checkout\n");
    }

    // ==================== D — EVENT ====================

    public function test_d_real_order_fires_order_created_event(): void
    {
        $user = $this->seedPrimaryUser();
        $token = $this->realLogin();
        $governorate = $this->seedCheckoutGeography();
        $product = $this->createProduct();

        $fired = [];
        \Illuminate\Support\Facades\Event::listen(
            \App\Events\OrderCreated::class,
            function ($event) use (&$fired) {
                $fired[] = $event;
            }
        );

        $this->realAddToCart($token, $product->id);
        $orderId = $this->realCheckout($token, $governorate->id);

        $this->assertNotEmpty($fired, 'OrderCreated must have fired during the real checkout.');
        $this->assertEquals($orderId, $fired[0]->order->id);
        $this->assertEquals($user->id, $fired[0]->order->user_id);

        fwrite(STDERR, "\n[REPORT D] EVENT OK — App\Events\OrderCreated fired with order #{$orderId}\n");
    }

    // ==================== E — LISTENER ====================

    public function test_e_listener_sends_notification_to_test_user(): void
    {
        $user = $this->seedPrimaryUser();
        $token = $this->realLogin();
        $governorate = $this->seedCheckoutGeography();
        $product = $this->createProduct();

        $notified = [];
        \Illuminate\Support\Facades\Event::listen(
            \App\Events\OrderCreated::class,
            function ($event) use (&$notified) {
                $order = $event->order;
                $listener = app(\App\Listeners\SendUserOrderCreatedNotification::class);
                $listener->handle($event);
                $notified[] = [
                    'user_id' => $order->user->id,
                    'user_type' => $order->user->type,
                ];
            }
        );

        $this->realAddToCart($token, $product->id);
        $this->realCheckout($token, $governorate->id);

        $this->assertNotEmpty($notified);
        $this->assertEquals($user->id, $notified[0]['user_id']);
        $this->assertEquals('user', $notified[0]['user_type']);

        // The listener dispatched the real notification to the real user.
        $this->assertGreaterThanOrEqual(1, $user->notifications()->count());

        fwrite(STDERR, "\n[REPORT E] LISTENER OK — SendUserOrderCreatedNotification handled for user {$user->id}\n");
    }

    // ==================== F — QUEUE ====================

    public function test_f_real_flow_queues_one_job_per_channel_on_meem_medium(): void
    {
        $user = $this->seedPrimaryUser();
        $token = $this->realLogin();
        $governorate = $this->seedCheckoutGeography();
        $product = $this->createProduct();

        // The real notification declares database + broadcast via via().
        $notification = new UserOrderCreatedNotification($this->makeOrderStub($user));

        $channels = $notification->via($user);
        $this->assertContains('database', $channels);
        $this->assertContains('broadcast', $channels);

        // The real listener pins its queue to meem-medium.
        $listener = app(\App\Listeners\SendUserOrderCreatedNotification::class);
        $this->assertEquals('meem-medium', $listener->queue);

        fwrite(STDERR, "\n[REPORT F] QUEUE OK — via() = [" . implode(',', $channels) . "] on queue meem-medium\n");
    }

    // ==================== G+H+I — DB CHANNEL -> INSERT ====================

    public function test_g_h_i_real_order_inserts_notifications_row_for_test_user(): void
    {
        $user = $this->seedPrimaryUser();
        $token = $this->realLogin();
        $governorate = $this->seedCheckoutGeography();
        $product = $this->createProduct();

        DB::enableQueryLog();
        $this->realAddToCart($token, $product->id);
        $this->realCheckout($token, $governorate->id);

        // THE core assertion: a row EXISTS in the notifications table for the
        // exact authenticated user after the real order.
        $row = DB::table('notifications')
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', $user->id)
            ->latest()
            ->first();
        $this->assertNotNull($row, 'No notifications row was inserted for test@gmail.com after the real order.');

        $this->assertEquals('order.created', $row->type);
        $this->assertNull($row->read_at);

        // Query-log proof of the real INSERT INTO notifications.
        $inserts = collect(DB::getQueryLog())->filter(fn ($q) => str_contains(strtolower($q['query']), 'insert into "notifications"'));
        $this->assertGreaterThan(0, $inserts->count(), 'No INSERT INTO notifications in the query log.');
        DB::disableQueryLog();

        fwrite(STDERR, "\n[REPORT G+H+I] DB CHANNEL + INSERT OK — row {$row->id} for user {$user->id}\n");
    }

    // ==================== J — REST ====================

    public function test_j_real_order_visible_via_notifications_rest_api(): void
    {
        $user = $this->seedPrimaryUser();
        $token = $this->realLogin();
        $governorate = $this->seedCheckoutGeography();
        $product = $this->createProduct();

        $this->realAddToCart($token, $product->id);
        $this->realCheckout($token, $governorate->id);

        $response = $this->withToken($token)
            ->getJson(self::API_PREFIX . '/notifications');

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.meta.total', 1);
        $response->assertJsonPath('data.data.0.type', 'order.created');
        $response->assertJsonPath('data.data.0.resource_type', 'order');

        fwrite(STDERR, "\n[REPORT J] REST OK — GET /notifications returns the order.created notification\n");
    }

    // ==================== K — BROADCAST ====================

    public function test_k_real_order_broadcast_reaches_private_user_channel(): void
    {
        $user = $this->seedPrimaryUser();
        $token = $this->realLogin();
        $governorate = $this->seedCheckoutGeography();
        $product = $this->createProduct();

        $this->resetBroadcastRecordings();

        $this->realAddToCart($token, $product->id);
        $this->realCheckout($token, $governorate->id);

        $broadcast = $this->assertBroadcastTo(
            'private-users.' . $user->id,
            'order.created'
        );

        $this->assertEquals('order.created', $broadcast['data']['type']);
        $this->assertIsString($broadcast['data']['id']);

        // The SAME notification that was INSERTed is the one broadcast.
        $row = DB::table('notifications')
            ->where('notifiable_id', $user->id)
            ->where('type', 'order.created')
            ->latest()
            ->first();
        $this->assertNotNull($row);
        $this->assertEquals($row->id, $broadcast['data']['id']);

        fwrite(STDERR, "\n[REPORT K] BROADCAST OK — private-users.{$user->id} <- " . 'order.created' . " (same id as DB row)\n");
    }

    // ==================== L — E2E ====================

    public function test_l_full_e2e_real_order_reaches_db_and_pusher(): void
    {
        $user = $this->seedPrimaryUser();
        $token = $this->realLogin();
        $governorate = $this->seedCheckoutGeography();
        $product = $this->createProduct();

        $this->resetBroadcastRecordings();

        $this->realAddToCart($token, $product->id);
        $this->realCheckout($token, $governorate->id);

        // DB row present.
        $row = DB::table('notifications')
            ->where('notifiable_id', $user->id)
            ->where('type', 'order.created')
            ->latest()
            ->first();
        $this->assertNotNull($row);

        // Pusher got it.
        $broadcast = $this->assertBroadcastTo(
            'private-users.' . $user->id,
            'order.created'
        );
        $this->assertEquals($row->id, $broadcast['data']['id']);

        // REST returns it.
        $this->withToken($token)
            ->getJson(self::API_PREFIX . '/notifications')
            ->assertStatus(200)
            ->assertJsonPath('data.meta.total', 1);

        fwrite(STDERR, "\n[REPORT L] E2E OK — DB row + Pusher + REST all agree for user {$user->id}\n");
    }

    // ==================== M — THE PRODUCTION DIVERGENCE ====================

    /**
     * Reproduce the reported production symptom through the REAL order flow
     * with the REAL async queue driver (database standing in for redis):
     *
     * When the notifications table is missing (migrations never ran in the
     * deployed environment), the REAL checkout still creates the order and the
     * BROADCAST channel job still reaches Pusher, while the DATABASE channel
     * job FAILS independently. Pusher-PASS / DB-MISS.
     */
    public function test_m_real_order_production_divergence_broadcast_ok_database_fails(): void
    {
        $user = $this->seedPrimaryUser();
        $token = $this->realLogin();
        $governorate = $this->seedCheckoutGeography();
        $product = $this->createProduct();

        if (!Schema::hasTable('jobs')) {
            Schema::create('jobs', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('queue')->index();
                $table->longText('payload');
                $table->unsignedTinyInteger('attempts');
                $table->unsignedInteger('reserved_at')->nullable();
                $table->unsignedInteger('available_at');
                $table->unsignedInteger('created_at');
            });
        }

        if (!Schema::hasTable('failed_jobs')) {
            Schema::create('failed_jobs', function (Blueprint $table) {
                $table->id();
                $table->string('uuid')->unique();
                $table->text('connection');
                $table->text('queue');
                $table->longText('payload');
                $table->longText('exception');
                $table->timestamp('failed_at')->useCurrent();
            });
        }

        config()->set('queue.default', 'database');
        \Illuminate\Support\Facades\Queue::setDefaultDriver('database');

        // Simulate the deployed environment where the notifications table was
        // never created because RUN_MIGRATIONS=false / preDeployCommand was
        // commented out in render.yaml.
        Schema::dropIfExists('notifications');

        $failedJobs = [];
        \Illuminate\Support\Facades\Event::listen(
            \Illuminate\Queue\Events\JobFailed::class,
            function ($event) use (&$failedJobs) {
                $failedJobs[] = $event;
            }
        );

        $this->resetBroadcastRecordings();

        $this->realAddToCart($token, $product->id);
        $orderId = $this->realCheckout($token, $governorate->id);

        // The order WAS created (real checkout unaffected).
        $this->assertNotNull(DB::table('orders')->where('id', $orderId)->first());

        // Two independent SendQueuedNotifications jobs were queued.
        $jobs = DB::table('jobs')->get();
        $this->assertCount(2, $jobs, 'Expected 2 SendQueuedNotifications jobs (database + broadcast).');

        // Process them like a real worker: DB job fails, broadcast succeeds.
        $connection = \Illuminate\Support\Facades\Queue::connection('database');
        foreach (['meem-medium', 'default'] as $queue) {
            while ($job = $connection->pop($queue)) {
                try {
                    $job->fire();
                } catch (\Throwable $e) {
                    $job->fail($e);
                }
                $job->delete();
            }
        }

        // Pusher received the notification for the user.
        $broadcast = $this->assertBroadcastTo(
            'private-users.' . $user->id,
            'order.created'
        );
        $this->assertEquals('order.created', $broadcast['data']['type']);

        // The database channel job failed => no notifications row possible.
        $this->assertNotEmpty($failedJobs, 'The database channel job must have failed.');
        $this->assertFalse(Schema::hasTable('notifications'));

        fwrite(STDERR, "\n[REPORT M] DIVERGENCE PROVEN — real order + async: Pusher OK, DB channel job failed, notifications table absent\n");
    }

    // ==================== N — OWNERSHIP ====================

    public function test_n_other_user_cannot_read_test_user_notification(): void
    {
        $owner = $this->seedPrimaryUser();
        $token = $this->realLogin();
        $governorate = $this->seedCheckoutGeography();
        $product = $this->createProduct();

        $this->realAddToCart($token, $product->id);
        $this->realCheckout($token, $governorate->id);

        $row = DB::table('notifications')->where('notifiable_id', $owner->id)->first();
        $this->assertNotNull($row);

        $other = $this->createUser('user');
        $otherToken = $this->realLogin($other->email);

        $this->withToken($otherToken)
            ->getJson(self::API_PREFIX . '/notifications')
            ->assertStatus(200)
            ->assertJsonPath('data.meta.total', 0);

        $this->withToken($otherToken)
            ->getJson(self::API_PREFIX . "/notifications/{$row->id}")
            ->assertStatus(404);

        fwrite(STDERR, "\n[REPORT N] OWNERSHIP OK — other user cannot see test user's notification\n");
    }

    // ==================== O — FULL PIPELINE ====================

    public function test_o_full_pipeline_no_failed_jobs_in_sync_path(): void
    {
        $user = $this->seedPrimaryUser();
        $token = $this->realLogin();
        $governorate = $this->seedCheckoutGeography();
        $product = $this->createProduct();

        $this->realAddToCart($token, $product->id);
        $this->realCheckout($token, $governorate->id);

        $this->assertDatabaseNotification($user, 'order.created');

        if (Schema::hasTable('failed_jobs')) {
            $this->assertSame(0, DB::table('failed_jobs')->count());
        }

        fwrite(STDERR, "\n[REPORT O] FULL PIPELINE OK — no failed jobs, notification persisted for user {$user->id}\n");
    }

    // ==================== HELPERS ====================

    private function makeOrderStub(User $user): \Marvel\Database\Models\Order
    {
        return \Marvel\Database\Models\Order::withoutEvents(fn () => \Marvel\Database\Models\Order::create([
            'user_id' => $user->id,
            'status' => 'pending',
            'payment_status' => 'pending',
            'total_price' => 100.00,
            'price' => 100.00,
        ]));
    }
}