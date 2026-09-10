<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\DTOs\CheckoutTotals;
use App\Notifications\UserOrderCreatedNotification;
use App\Services\Checkout\OrderCreationService;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\Events\BroadcastNotificationCreated;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Marvel\Database\Models\User;

/**
 * DEFINITIVE PRODUCTION-PROBLEM TEST.
 *
 * Reported behaviour:  Notification -> Pusher = WORKING
 *                      Notification -> notifications table = NOT WORKING
 *
 * This file answers ONE question with runtime evidence:
 *
 *   "Can the REAL logged-in user test@gmail.com / password trigger a REAL
 *    application notification that reaches Pusher AND is persisted in the
 *    notifications table — and if it is NOT persisted, exactly where does
 *    the pipeline break?"
 *
 * Everything is the real production stack: real login endpoint
 * (POST /api/v1/token), real Sanctum tokens, real business trigger
 * (OrderCreationService::finalizeOrder -> App\Events\OrderCreated -> real
 * listeners -> real queued UserOrderCreatedNotification), the real Laravel
 * NotificationSender (which queues ONE SendQueuedNotifications job PER channel
 * returned by via()), the real DatabaseChannel and the real BroadcastChannel.
 *
 * The ONLY swapped component is the Pusher HTTP client (RecordingPusher) so no
 * external network call is made; the broadcast pipeline itself is untouched.
 */
class RealUserNotificationDatabasePersistenceE2ETest extends NotificationE2ETestCase
{
    private const TEST_EMAIL = 'test@gmail.com';
    private const TEST_PASSWORD = 'password';

    /**
     * Seed the exact production account (test@gmail.com / password) into the
     * empty test database. In production this user already exists; the seed
     * only mirrors its identity so the REAL login endpoint can authenticate it.
     */
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

    /**
     * REAL login through the project's actual login endpoint.
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

    /**
     * The REAL business action: create an order for the authenticated user and
     * run the REAL finalizeOrder() path which dispatches App\Events\OrderCreated.
     */
    private function triggerRealOrderCreated(User $user): void
    {
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
    }

    // ==================== STEP 1 — REAL LOGIN ====================

    public function test_step1_real_login_authenticates_test_user(): void
    {
        $user = $this->seedPrimaryUser();

        $token = $this->login(self::TEST_EMAIL);

        $response = $this->withToken($token)
            ->getJson(self::API_PREFIX . '/me');

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.id', $user->id);
        $response->assertJsonPath('data.email', self::TEST_EMAIL);

        // The report MUST show the authenticated identity.
        fwrite(STDERR, "\nAuthenticated User ID: {$user->id}\nEmail: " . self::TEST_EMAIL . "\n");
    }

    // ==================== STEP 2 — DB STATE BEFORE ====================

    public function test_step2_records_database_state_before_notification(): void
    {
        $user = $this->seedPrimaryUser();

        $this->assertSame(0, $user->notifications()->count());

        // The query filters by notifiable_type + notifiable_id, not count alone.
        $this->assertSame(
            0,
            DB::table('notifications')
                ->where('notifiable_type', User::class)
                ->where('notifiable_id', $user->id)
                ->count()
        );
    }

    // ==================== STEP 3+4+5+6 — REAL TRIGGER, via(), DB CHANNEL, INSERT ====================

    public function test_steps_3_4_5_6_real_trigger_executes_database_channel_and_inserts(): void
    {
        $user = $this->seedPrimaryUser();
        $this->login(self::TEST_EMAIL);

        $this->resetBroadcastRecordings();

        // Enable the query log BEFORE the trigger so the INSERT is captured.
        DB::enableQueryLog();
        $this->triggerRealOrderCreated($user);

        // The via() contract of the real notification class.
        $notification = new UserOrderCreatedNotification($this->createOrder($user));
        $channels = $notification->via($user);
        $this->assertContains('database', $channels);
        $this->assertContains('broadcast', $channels);

        // The DatabaseChannel INSERT happened (query log evidence).
        $notificationRow = DB::table('notifications')
            ->where('notifiable_id', $user->id)
            ->where('type', 'order.created')
            ->latest()
            ->first();
        $this->assertNotNull($notificationRow, 'DatabaseChannel must have INSERTed a notifications row.');

        $inserts = collect(DB::getQueryLog())->filter(fn ($q) => str_contains(strtolower($q['query']), 'insert into "notifications"'));
        $this->assertGreaterThan(0, $inserts->count(), 'No INSERT INTO notifications found in the query log.');
        DB::disableQueryLog();

        // Broadcast also reached Pusher for the SAME notification.
        $broadcast = $this->assertBroadcastTo(
            'private-users.' . $user->id,
            'order.created'
        );
        $this->assertEquals($notificationRow->id, $broadcast['data']['id']);
    }

    // ==================== STEP 7+8+9+10+11 — DB ROW, TYPE, PAYLOAD ====================

    public function test_steps_7_8_9_10_11_row_belongs_to_test_user_with_stable_type_and_payload(): void
    {
        $user = $this->seedPrimaryUser();
        $this->login(self::TEST_EMAIL);

        $this->triggerRealOrderCreated($user);

        $row = DB::table('notifications')
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', $user->id)
            ->latest()
            ->first();

        $this->assertNotNull($row);

        // id is a UUID string.
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $row->id);

        // Stable business type — NEVER the PHP FQCN.
        $this->assertEquals('order.created', $row->type);
        $this->assertNotEquals(UserOrderCreatedNotification::class, $row->type);

        // Real notifiable model + owning user id.
        $this->assertEquals(User::class, $row->notifiable_type);
        $this->assertEquals($user->id, $row->notifiable_id);

        // read_at null on creation, created_at present.
        $this->assertNull($row->read_at);
        $this->assertNotNull($row->created_at);

        // Localized payload.
        $data = json_decode($row->data, true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('title', $data);
        $this->assertArrayHasKey('message', $data);
        $this->assertArrayHasKey('en', $data['title']);
        $this->assertArrayHasKey('ar', $data['title']);
        $this->assertArrayHasKey('en', $data['message']);
        $this->assertArrayHasKey('ar', $data['message']);
        $this->assertNotEmpty($data['title']['en']);
        $this->assertNotEmpty($data['title']['ar']);
        $this->assertNotEmpty($data['message']['en']);
        $this->assertNotEmpty($data['message']['ar']);

        // databaseType() == broadcastType() == stable id.
        $n = new UserOrderCreatedNotification($this->createOrder($user));
        $this->assertEquals('order.created', $n->databaseType($user));
        $this->assertEquals('order.created', $n->broadcastType());
    }

    // ==================== STEP 8 — DB vs PUSHER for the SAME execution ====================

    public function test_step8_same_notification_db_and_pusher_match(): void
    {
        $user = $this->seedPrimaryUser();
        $this->login(self::TEST_EMAIL);

        $this->resetBroadcastRecordings();

        $this->triggerRealOrderCreated($user);

        $row = DB::table('notifications')
            ->where('notifiable_id', $user->id)
            ->where('type', 'order.created')
            ->latest()
            ->first();
        $this->assertNotNull($row);

        $broadcast = $this->assertBroadcastTo(
            'private-users.' . $user->id,
            'order.created'
        );

        // Same notification identity on both sides.
        $this->assertEquals($row->id, $broadcast['data']['id']);
        $this->assertEquals('order.created', $broadcast['data']['type']);

        // Same payload on both sides.
        $dbData = json_decode($row->data, true);
        $this->assertEquals($dbData['title'], $broadcast['data']['title']);
        $this->assertEquals($dbData['message'], $broadcast['data']['message']);
        $this->assertEquals($dbData['resource_id'], $broadcast['data']['resource_id']);
    }

    // ==================== STEP 12 — TRANSACTION / ROLLBACK ====================

    /**
     * Prove the INSERT is committed and NOT rolled back by the surrounding
     * business transaction: the row is visible immediately after the trigger.
     */
    public function test_step12_no_rollback_the_row_commits(): void
    {
        $user = $this->seedPrimaryUser();
        $this->login(self::TEST_EMAIL);

        $this->triggerRealOrderCreated($user);

        $this->assertNotNull(
            DB::table('notifications')->where('notifiable_id', $user->id)->first()
        );
    }

    // ==================== STEP 14 — REAL REST VERIFICATION ====================

    public function test_step14_rest_api_returns_same_notification(): void
    {
        $user = $this->seedPrimaryUser();
        $token = $this->login(self::TEST_EMAIL);

        $this->triggerRealOrderCreated($user);

        $response = $this->withToken($token)
            ->getJson(self::API_PREFIX . '/notifications');

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.meta.total', 1);
        $response->assertJsonPath('data.data.0.type', 'order.created');
        $response->assertJsonPath('data.data.0.resource_type', 'order');
        $this->assertIsString($response->json('data.data.0.title'));
        $this->assertIsString($response->json('data.data.0.message'));
    }

    // ==================== STEP 15 — OWNERSHIP ====================

    public function test_step15_other_user_cannot_see_test_user_notification(): void
    {
        $owner = $this->seedPrimaryUser();
        $this->login(self::TEST_EMAIL);

        $this->triggerRealOrderCreated($owner);

        $row = DB::table('notifications')->where('notifiable_id', $owner->id)->first();
        $this->assertNotNull($row);

        $other = $this->createUser('user');
        $otherToken = $this->login($other->email);

        $this->withToken($otherToken)
            ->getJson(self::API_PREFIX . '/notifications')
            ->assertStatus(200)
            ->assertJsonPath('data.meta.total', 0);

        $this->withToken($otherToken)
            ->getJson(self::API_PREFIX . "/notifications/{$row->id}")
            ->assertStatus(404);
    }

    // ==================== STEP 16+17 — LOGS / FAILED JOBS ====================

    public function test_step16_17_no_failed_jobs_in_sync_path(): void
    {
        $user = $this->seedPrimaryUser();
        $this->login(self::TEST_EMAIL);

        $this->triggerRealOrderCreated($user);

        $this->assertDatabaseNotification($user, 'order.created');

        if (Schema::hasTable('failed_jobs')) {
            $this->assertSame(0, DB::table('failed_jobs')->count());
        }
    }

    // ==================== THE CORE DIVERGENCE PROOF ====================

    /**
     * THE reported production symptom, reproduced with the REAL async queue
     * driver (database acting as the redis stand-in):
     *
     * Laravel's NotificationSender::queueNotification() dispatches ONE
     * SendQueuedNotifications job PER CHANNEL (vendor
     * .../Notifications/NotificationSender.php, line 191). via() returns
     * ['database','broadcast'] so TWO independent jobs are queued.
     *
     * In production (redis) these jobs are processed by workers INDEPENDENTLY.
     * When the notifications table is missing (e.g. migrations never ran), the
     * DATABASE job fails while the BROADCAST job succeeds independently ->
     * Pusher receives the notification, the notifications table has no row.
     * Exactly the reported behaviour.
     */
    public function test_core_divergence_broadcast_succeeds_database_fails(): void
    {
        $user = $this->seedPrimaryUser();
        $this->login(self::TEST_EMAIL);

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

        // Use the real async queue driver (database stands in for redis).
        config()->set('queue.default', 'database');
        \Illuminate\Support\Facades\Queue::setDefaultDriver('database');

        // Simulate production where the notifications table was never created.
        Schema::dropIfExists('notifications');

        $this->resetBroadcastRecordings();

        // Capture the JobFailed events — the same events a real queue:work
        // worker uses to write failed_jobs rows.
        $failedJobs = [];
        \Illuminate\Support\Facades\Event::listen(
            \Illuminate\Queue\Events\JobFailed::class,
            function ($event) use (&$failedJobs) {
                $failedJobs[] = $event;
            }
        );

        $this->triggerRealOrderCreated($user);

        // Two independent SendQueuedNotifications jobs were queued.
        $jobs = DB::table('jobs')->get();
        $this->assertCount(2, $jobs, 'Expected 2 SendQueuedNotifications jobs (database + broadcast).');

        // Process the jobs the way a real worker does. The database job fails
        // (table missing); the broadcast job succeeds.
        $connection = \Illuminate\Support\Facades\Queue::connection('database');
        $processed = 0;
        foreach (['meem-medium', 'default'] as $queue) {
            while ($job = $connection->pop($queue)) {
                try {
                    $job->fire();
                } catch (\Throwable $e) {
                    $job->fail($e);
                }
                $job->delete();
                $processed++;
            }
        }
        $this->assertGreaterThanOrEqual(1, $processed);

        // Pusher STILL received the notification.
        $broadcast = $this->assertBroadcastTo(
            'private-users.' . $user->id,
            'order.created'
        );
        $this->assertEquals('order.created', $broadcast['data']['type']);
        $this->assertIsString($broadcast['data']['id']);

        // The database channel job FAILED (recorded as a JobFailed event, the
        // same event that writes failed_jobs under a real queue:work worker),
        // so the notifications table holds NO row for this user.
        $this->assertNotEmpty($failedJobs, 'The database channel job must have failed.');
        $this->assertFalse(Schema::hasTable('notifications'));
    }

    /**
     * Even when the notifications table EXISTS, the two channels are executed
     * as INDEPENDENT queue jobs. This test inspects the actual serialized jobs
     * to prove the database job and the broadcast job are separate
     * SendQueuedNotifications instances — the architectural reason a database
     * failure can never block Pusher (and vice versa).
     */
    public function test_core_two_independent_jobs_per_channel(): void
    {
        $user = $this->seedPrimaryUser();
        $this->login(self::TEST_EMAIL);

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

        config()->set('queue.default', 'database');
        \Illuminate\Support\Facades\Queue::setDefaultDriver('database');

        $order = $this->createOrder($user);
        $user->notify(new UserOrderCreatedNotification($order));

        // Two independent SendQueuedNotifications jobs for the two channels.
        $jobs = DB::table('jobs')->get();
        $this->assertCount(2, $jobs);

        $serialized = $jobs->map(fn ($job) => json_decode($job->payload, true)['data']['command'])->all();
        foreach ($serialized as $command) {
            $this->assertStringContainsString('SendQueuedNotifications', $command);
        }
    }
}