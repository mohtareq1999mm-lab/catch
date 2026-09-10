<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Events\OrderCreated;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\Events\BroadcastNotificationCreated;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * PHASE 8 / 15 / 18 — Definitive database-persistence audit.
 *
 * Proves with runtime evidence that a REAL notification, sent to a REAL
 * Marvel\Database\Models\User through the REAL event/listener/notification
 * pipeline, creates exactly ONE row in the `notifications` table AND is
 * broadcast to the private-users.{id} channel, with matching identifiers.
 *
 * The broadcast pipeline is executed against the real PusherBroadcaster; only
 * the external HTTP client is swapped for the RecordingPusher double.
 */
class DatabasePersistenceAuditTest extends NotificationE2ETestCase
{
    public function test_real_pipeline_creates_db_row_and_broadcast_with_matching_ids(): void
    {
        $user = $this->createUser('user');
        $other = $this->createUser('user');
        $order = $this->createOrder($user);

        $beforeA = DB::table('notifications')->where('notifiable_id', $user->id)->count();
        $beforeB = DB::table('notifications')->where('notifiable_id', $other->id)->count();

        $queries = [];
        DB::listen(function ($q) use (&$queries) {
            if (stripos($q->sql, 'notifications') !== false) {
                $queries[] = $q->sql;
            }
        });

        $this->resetBroadcastRecordings();

        event(new OrderCreated($order));

        $afterA = DB::table('notifications')->where('notifiable_id', $user->id)->count();
        $afterB = DB::table('notifications')->where('notifiable_id', $other->id)->count();

        // 1. Exactly one new row for User A, zero for User B.
        $this->assertEquals($beforeA + 1, $afterA, 'User A must gain exactly one notification row.');
        $this->assertEquals($beforeB, $afterB, 'User B must gain zero notification rows.');

        // 2. Query log must contain an INSERT INTO notifications.
        $this->assertNotEmpty(
            array_filter($queries, fn ($sql) => stripos($sql, 'insert into') !== false),
            'No INSERT INTO notifications query was captured in the query log.'
        );

        // 3. Row contents.
        $row = DB::table('notifications')
            ->where('notifiable_id', $user->id)
            ->where('type', 'order.created')
            ->latest()
            ->first();

        $this->assertNotNull($row, 'order.created row missing for the real user.');
        $this->assertEquals('order.created', $row->type);
        $this->assertEquals('Marvel\\Database\\Models\\User', $row->notifiable_type);
        $this->assertEquals($user->id, $row->notifiable_id);
        $this->assertTrue(Str::isUuid($row->id), 'notification id must be a UUID string.');
        $this->assertNull($row->read_at);

        $data = json_decode($row->data, true);
        $this->assertIsArray($data, 'data column must contain valid JSON.');
        $this->assertArrayHasKey('title', $data);
        $this->assertArrayHasKey('en', $data['title']);
        $this->assertArrayHasKey('ar', $data['title']);
        $this->assertArrayHasKey('message', $data);
        $this->assertArrayHasKey('en', $data['message']);
        $this->assertArrayHasKey('ar', $data['message']);

        // 4. Broadcast reached Pusher with the same logical identity.
        $broadcast = $this->assertBroadcastTo(
            'private-users.' . $user->id,
            'order.created'
        );
        $this->assertEquals('order.created', $broadcast['data']['type']);
        $this->assertEquals($row->id, $broadcast['data']['id']);
        $this->assertEquals($data['title'], $broadcast['data']['title']);
        $this->assertEquals($data['message'], $broadcast['data']['message']);

        // 5. Relationship read-back confirms the same row.
        $this->assertSame(
            $row->id,
            $user->notifications()->first()->id,
            'notifiable->notifications() must resolve the persisted row.'
        );
    }
}