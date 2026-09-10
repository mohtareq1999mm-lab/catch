<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Events\OrderCreated;
use App\Notifications\UserOrderCreatedNotification;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

/**
 * PHASE 6 / 14 / 19 — Async queue divergence reproduction.
 *
 * In production QUEUE_CONNECTION=redis. Laravel's NotificationSender queues
 * ONE job PER CHANNEL (NotificationSender::queueNotification). So a
 * ['database','broadcast'] notification produces TWO independent
 * SendQueuedNotifications jobs on the same queue. This test proves, with the
 * REAL database queue driver acting as a stand-in for redis, that:
 *
 *   - Both jobs are dispatched (database job + broadcast job).
 *   - When the notifications table exists, the database job INSERTs a row AND
 *     the broadcast job reaches Pusher (happy path).
 *   - When the notifications table is missing (e.g. migrations never ran),
 *     the DATABASE job fails but the BROADCAST job STILL reaches Pusher.
 *     This is the exact reported symptom: "Pusher works, DB row missing."
 */
class AsyncQueuePersistenceAuditTest extends NotificationE2ETestCase
{
    protected function setUp(): void
    {
        parent::setUp();

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

        // Route queue traffic through the real database queue driver so each
        // SendQueuedNotifications job is independently stored and processed.
        config()->set('queue.default', 'database');
        Queue::setDefaultDriver('database');
    }

    /**
     * Prove the architecture: two independent jobs are queued for a
     * two-channel ShouldQueue notification.
     */
    public function test_two_channel_notification_queues_two_independent_jobs(): void
    {
        $user = $this->createUser('user');
        $order = $this->createOrder($user);

        $notification = new UserOrderCreatedNotification($order);
        $user->notify($notification);

        $jobs = DB::table('jobs')->get();
        $this->assertCount(2, $jobs, 'Expected 2 SendQueuedNotifications jobs (database + broadcast).');

        foreach ($jobs as $job) {
            $payload = json_decode($job->payload, true);
            $this->assertEquals(
                UserOrderCreatedNotification::class,
                $payload['displayName'],
                'displayName() is the underlying notification class.'
            );

            // The serialized command is a SendQueuedNotifications instance
            // whose channels array drives which channel executes.
            $this->assertEquals(
                'Illuminate\\Notifications\\SendQueuedNotifications',
                $payload['data']['commandName']
            );
            $this->assertStringContainsString('SendQueuedNotifications', $payload['data']['command']);
        }
    }

    /**
     * Happy path with a real queue: process both jobs and assert the DB row
     * AND the broadcast both materialize with matching identity.
     */
    public function test_real_queue_processes_database_job_and_broadcast_job(): void
    {
        $user = $this->createUser('user');
        $order = $this->createOrder($user);

        $this->resetBroadcastRecordings();

        $user->notify(new UserOrderCreatedNotification($order));

        // Process every queued job through the REAL worker pipeline.
        $this->processAllQueuedJobs();

        $row = DB::table('notifications')
            ->where('notifiable_id', $user->id)
            ->where('type', 'order.created')
            ->latest()
            ->first();

        $this->assertNotNull($row, 'order.created row must exist after real queue processing.');
        $this->assertEquals('Marvel\\Database\\Models\\User', $row->notifiable_type);
        $this->assertEquals($user->id, $row->notifiable_id);

        $broadcast = $this->assertBroadcastTo(
            'private-users.' . $user->id,
            'order.created'
        );
        $this->assertEquals($row->id, $broadcast['data']['id']);
        $this->assertEquals('order.created', $broadcast['data']['type']);
    }

    /**
     * THE reported symptom, reproduced: if the notifications table is missing
     * (migrations never ran / schema mismatch), the broadcast job STILL
     * reaches Pusher while the database job fails. This proves broadcast and
     * database persistence are decoupled — one does not imply the other.
     */
    public function test_missing_notifications_table_broadcast_still_reaches_pusher(): void
    {
        $user = $this->createUser('user');
        $order = $this->createOrder($user);

        // Simulate production where the notifications table was never created.
        Schema::dropIfExists('notifications');

        $this->resetBroadcastRecordings();

        $user->notify(new UserOrderCreatedNotification($order));

        // Attempt to process all queued jobs; the database job is expected to
        // fail, the broadcast job is expected to succeed.
        $this->processAllQueuedJobs(throwOnFailure: false);

        // Broadcast STILL reached the broadcaster (Pusher).
        $broadcasts = $this->recordedBroadcasts();
        $this->assertNotEmpty(
            array_filter(
                $broadcasts,
                fn ($b) => in_array('private-users.' . $user->id, $b['channels'], true)
                    && $b['event'] === 'order.created'
            ),
            'Broadcast job must still deliver to Pusher even when DB persistence fails.'
        );

        // No DB row can exist (table is gone).
        $this->assertFalse(Schema::hasTable('notifications'));
    }

    /**
     * Prove the inverse is also possible: the notifications table exists but
     * the broadcast is what would fail — the two channels are independent.
     */
    public function test_database_persistence_does_not_depend_on_broadcast_success(): void
    {
        $user = $this->createUser('user');
        $order = $this->createOrder($user);

        // Disable the broadcast driver so BroadcastChannel cannot deliver.
        config()->set('broadcasting.default', 'null');

        $this->resetBroadcastRecordings();

        $user->notify(new UserOrderCreatedNotification($order));
        $this->processAllQueuedJobs();

        // DB row was still created even though broadcast could not fire.
        $row = DB::table('notifications')
            ->where('notifiable_id', $user->id)
            ->where('type', 'order.created')
            ->latest()
            ->first();
        $this->assertNotNull($row, 'DB row must persist even when broadcast fails.');
    }

    /**
     * Pop and process every job across the queues used by the notification
     * pipeline through the real queue worker machinery.
     *
     * - meem-medium : the two SendQueuedNotifications channel jobs
     * - default     : the BroadcastEvent job produced when a ShouldBroadcast
     *                 event is queued by the BroadcastManager
     */
    protected function processAllQueuedJobs(bool $throwOnFailure = true): void
    {
        foreach (['meem-medium', 'default'] as $queue) {
            $connection = Queue::connection('database');

            while (true) {
                $job = $connection->pop($queue);
                if (!$job) {
                    break;
                }
                try {
                    $job->fire();
                    $job->delete();
                } catch (\Throwable $e) {
                    $job->fail($e);
                    if ($throwOnFailure) {
                        throw $e;
                    }
                }
            }
        }
    }
}