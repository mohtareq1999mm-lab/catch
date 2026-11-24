<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Models\Shipping\NotificationRecipient;
use App\Models\Shipping\NotificationRecipientFingerprint;
use App\Models\Shipping\ShipmentNotification;
use App\Services\Shipment\NotificationRecipientResolver;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StableRecipientIdentityTest extends TestCase
{
    use RefreshDatabase;

    public function test_notification_uniqueness_references_stable_recipient_not_key_version(): void
    {
        $resolver = app(NotificationRecipientResolver::class);
        $recipient = $resolver->resolve([
            'recipient_type' => 'user',
            'recipient_key' => '42',
            'fingerprint' => hash('sha256', 'user:42'),
            'fingerprint_version' => 'v1',
        ]);

        $timelineEventId = $this->createTimelineEvent();
        ShipmentNotification::query()->create([
            'shipment_id' => null,
            'timeline_event_id' => $timelineEventId,
            'channel' => 'sms',
            'recipient_type' => 'user',
            'recipient_id' => (string) $recipient->id,
            'notification_recipient_id' => $recipient->id,
            'status' => 'pending',
        ]);

        NotificationRecipientFingerprint::query()->create([
            'notification_recipient_id' => $recipient->id,
            'version' => 'v2',
            'fingerprint' => hash('sha256', 'user:42:v2'),
        ]);

        $this->expectException(QueryException::class);
        ShipmentNotification::query()->create([
            'shipment_id' => null,
            'timeline_event_id' => $timelineEventId,
            'channel' => 'sms',
            'recipient_type' => 'user',
            'recipient_id' => '42-v2-raw',
            'notification_recipient_id' => $recipient->id,
            'status' => 'pending',
        ]);
    }

    public function test_rotation_adds_fingerprint_without_changing_recipient_identity(): void
    {
        $resolver = app(NotificationRecipientResolver::class);
        $first = $resolver->resolve([
            'recipient_type' => 'guest_contact',
            'recipient_key' => 'email:guest@example.test',
            'fingerprint' => hash_hmac('sha256', 'email:guest@example.test', 'secret-v1'),
            'fingerprint_version' => 'v1',
            'contact_encrypted' => 'enc:v1',
        ]);

        $rotated = $resolver->rotateFingerprint($first, 'v2', hash_hmac('sha256', 'email:guest@example.test', 'secret-v2'));

        $this->assertSame($first->id, $rotated->id);
        $this->assertDatabaseHas('notification_recipient_fingerprints', [
            'notification_recipient_id' => $first->id,
            'version' => 'v1',
        ]);
        $this->assertDatabaseHas('notification_recipient_fingerprints', [
            'notification_recipient_id' => $first->id,
            'version' => 'v2',
        ]);
        $this->assertSame(1, NotificationRecipient::query()->count());
    }

    public function test_ambiguous_matches_are_not_auto_merged(): void
    {
        $a = NotificationRecipient::query()->create(['recipient_type' => 'guest_contact', 'recipient_key' => 'a']);
        $b = NotificationRecipient::query()->create(['recipient_type' => 'guest_contact', 'recipient_key' => 'b']);
        NotificationRecipientFingerprint::query()->create([
            'notification_recipient_id' => $a->id,
            'version' => 'v1',
            'fingerprint' => 'same-fingerprint-a',
        ]);
        NotificationRecipientFingerprint::query()->create([
            'notification_recipient_id' => $b->id,
            'version' => 'v1',
            'fingerprint' => 'same-fingerprint-b',
        ]);

        $resolver = app(NotificationRecipientResolver::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Ambiguous recipient candidates');
        $resolver->resolve([
            'recipient_type' => 'guest_contact',
            'recipient_key' => 'ambiguous',
            'fingerprint' => 'same-fingerprint-a',
            'fingerprint_version' => 'v1',
            'candidate_recipient_ids' => [$a->id, $b->id],
        ]);
    }

    public function test_resolution_is_concurrency_safe_for_same_recipient_key(): void
    {
        $resolver = app(NotificationRecipientResolver::class);
        $attrs = [
            'recipient_type' => 'user',
            'recipient_key' => '77',
            'fingerprint' => hash('sha256', 'user:77'),
            'fingerprint_version' => 'v1',
        ];

        $first = $resolver->resolve($attrs);
        $second = $resolver->resolve($attrs);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, NotificationRecipient::query()->where('recipient_type', 'user')->where('recipient_key', '77')->count());
    }

    public function test_resolve_recovers_from_recipient_key_unique_violation_race(): void
    {
        $resolver = new class extends NotificationRecipientResolver {
            public int $injections = 0;

            protected function createRecipient(string $type, ?string $recipientKey, array $attributes): NotificationRecipient
            {
                if ($this->injections === 0 && $recipientKey !== null) {
                    $this->injections++;
                    $connection = DB::connection();
                    NotificationRecipient::query()->getQuery()->insert([
                        'recipient_type' => $type,
                        'recipient_key' => $recipientKey,
                        'contact_encrypted' => $attributes['contact_encrypted'] ?? null,
                        'metadata' => json_encode($attributes['metadata'] ?? null),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    $sqlState = $connection->getDriverName() === 'pgsql' ? '23505' : '23000';
                    $driverCode = $connection->getDriverName() === 'pgsql' ? 0 : 1062;
                    $exception = new QueryException(
                        $connection->getName(),
                        'insert into notification_recipients',
                        [],
                        new \PDOException('simulated unique violation', $driverCode)
                    );
                    $exception->errorInfo = [$sqlState, $driverCode, 'Duplicate entry'];
                    throw $exception;
                }

                return parent::createRecipient($type, $recipientKey, $attributes);
            }
        };

        $resolved = $resolver->resolve([
            'recipient_type' => 'user',
            'recipient_key' => 'race-77',
            'fingerprint' => hash('sha256', 'user:race-77'),
            'fingerprint_version' => 'v1',
        ]);

        $this->assertSame(1, NotificationRecipient::query()->where('recipient_type', 'user')->where('recipient_key', 'race-77')->count());
        $this->assertSame('race-77', $resolved->recipient_key);
    }

    public function test_recorder_populates_stable_recipient_for_registered_user_notifications(): void
    {
        $user = \Marvel\Database\Models\User::create([
            'name' => 'Stable Recipient User',
            'email' => 'stable-recipient@example.test',
            'password' => bcrypt('password'),
        ]);
        $order = \Marvel\Database\Models\Order::query()->forceCreate([
            'tracking_number' => 'TRK-STABLE-1',
            'user_id' => $user->id,
        ]);
        $shipment = \App\Models\Shipment::query()->create(['order_id' => $order->id, 'status' => 'pending']);
        $timelineEventId = DB::table('order_tracking_events')->insertGetId([
            'order_id' => $order->id,
            'event_type' => 'shipment.status_changed',
            'old_status' => 'pending',
            'new_status' => 'in_transit',
            'source' => 'direct',
            'actor_type' => 'system',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $event = new \App\Events\Shipment\ShipmentStatusChanged(
            order: $order,
            oldStatus: 'pending',
            newStatus: 'in_transit',
            timelineEventId: $timelineEventId,
            shipmentId: $shipment->id,
            orderId: $order->id,
            source: 'direct',
        );

        app(\App\Services\Shipment\ShipmentNotificationRecorder::class)->record($event);

        $notifications = ShipmentNotification::query()->where('timeline_event_id', $timelineEventId)->get();
        $this->assertCount(3, $notifications);
        $this->assertTrue($notifications->every(fn (ShipmentNotification $n) => $n->notification_recipient_id !== null));
        $this->assertSame(1, $notifications->pluck('notification_recipient_id')->unique()->count());
    }

    public function test_recorder_fails_closed_when_recipient_resolution_fails(): void
    {
        $user = \Marvel\Database\Models\User::create([
            'name' => 'Fail Closed',
            'email' => 'fail-closed@example.test',
            'password' => bcrypt('password'),
        ]);
        $order = \Marvel\Database\Models\Order::query()->forceCreate([
            'tracking_number' => 'TRK-STABLE-FAIL',
            'user_id' => $user->id,
        ]);
        $timelineEventId = DB::table('order_tracking_events')->insertGetId([
            'order_id' => $order->id,
            'event_type' => 'shipment.status_changed',
            'old_status' => 'pending',
            'new_status' => 'in_transit',
            'source' => 'direct',
            'actor_type' => 'system',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $event = new \App\Events\Shipment\ShipmentStatusChanged(
            order: $order,
            oldStatus: 'pending',
            newStatus: 'in_transit',
            timelineEventId: $timelineEventId,
            orderId: $order->id,
            source: 'direct',
        );

        $resolver = new class extends NotificationRecipientResolver {
            public function resolve(array $attributes): NotificationRecipient
            {
                throw new \RuntimeException('resolver down');
            }
        };

        $recorder = new \App\Services\Shipment\ShipmentNotificationRecorder($resolver);

        try {
            $recorder->record($event);
            $this->fail('Recorder must fail closed when stable recipient cannot be resolved.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('resolver down', $e->getMessage());
        }

        $this->assertSame(0, ShipmentNotification::query()->where('timeline_event_id', $timelineEventId)->count());
    }

    public function test_key_and_fingerprint_conflict_is_not_auto_merged(): void
    {
        $resolver = app(NotificationRecipientResolver::class);
        $recipientA = $resolver->resolve([
            'recipient_type' => 'guest_contact',
            'recipient_key' => 'email:a@example.test',
            'fingerprint' => 'fp-a',
            'fingerprint_version' => 'v1',
        ]);
        $recipientB = $resolver->resolve([
            'recipient_type' => 'guest_contact',
            'recipient_key' => 'email:b@example.test',
            'fingerprint' => 'fp-b',
            'fingerprint_version' => 'v1',
        ]);

        try {
            $resolver->resolve([
                'recipient_type' => 'guest_contact',
                'recipient_key' => 'email:a@example.test',
                'fingerprint' => 'fp-b',
                'fingerprint_version' => 'v1',
            ]);
            $this->fail('Conflicting key/fingerprint identity must not be auto-merged.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Ambiguous recipient key/fingerprint match', $e->getMessage());
        }

        $this->assertDatabaseHas('notification_recipients', ['id' => $recipientA->id, 'recipient_key' => 'email:a@example.test']);
        $this->assertDatabaseHas('notification_recipients', ['id' => $recipientB->id, 'recipient_key' => 'email:b@example.test']);
    }

    public function test_fingerprint_version_cannot_be_reassigned_to_a_different_fingerprint(): void
    {
        $resolver = app(NotificationRecipientResolver::class);
        $recipient = $resolver->resolve([
            'recipient_type' => 'guest_contact',
            'recipient_key' => 'email:version@example.test',
            'fingerprint' => 'fp-v1',
            'fingerprint_version' => 'v1',
        ]);

        try {
            $resolver->resolve([
                'recipient_type' => 'guest_contact',
                'recipient_key' => 'email:version@example.test',
                'fingerprint' => 'fp-v1-changed',
                'fingerprint_version' => 'v1',
            ]);
            $this->fail('Fingerprint version must not be reassigned.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Fingerprint version already assigned', $e->getMessage());
        }

        $this->assertDatabaseHas('notification_recipient_fingerprints', [
            'notification_recipient_id' => $recipient->id,
            'version' => 'v1',
            'fingerprint' => 'fp-v1',
        ]);
    }

    public function test_fingerprint_unique_violation_for_same_owner_is_resolved(): void
    {
        $resolver = new class extends NotificationRecipientResolver {
            public bool $injected = false;

            protected function attachFingerprint(NotificationRecipient $recipient, string $version, ?string $fingerprint, ?array $metadata): void
            {
                if (!$this->injected && $fingerprint !== null) {
                    $this->injected = true;
                    NotificationRecipientFingerprint::query()->create([
                        'notification_recipient_id' => $recipient->id,
                        'version' => $version,
                        'fingerprint' => $fingerprint,
                        'metadata' => $metadata,
                    ]);

                    $exception = new QueryException(
                        DB::connection()->getName(),
                        'insert into notification_recipient_fingerprints',
                        [],
                        new \PDOException('simulated unique violation', 1062)
                    );
                    $exception->errorInfo = ['23000', 1062, 'Duplicate entry'];
                    throw $exception;
                }

                parent::attachFingerprint($recipient, $version, $fingerprint, $metadata);
            }
        };

        $resolved = $resolver->resolve([
            'recipient_type' => 'guest_contact',
            'recipient_key' => 'email:fp-race@example.test',
            'fingerprint' => 'fp-race',
            'fingerprint_version' => 'v1',
        ]);

        $this->assertSame(1, NotificationRecipientFingerprint::query()
            ->where('notification_recipient_id', $resolved->id)
            ->where('version', 'v1')
            ->where('fingerprint', 'fp-race')
            ->count());
    }

    public function test_mysql_null_stable_recipient_unique_allows_legacy_duplicates_but_recorder_never_writes_null(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('MySQL-specific NULL-unique behavior.');
        }

        $timelineEventId = $this->createTimelineEvent();
        foreach (['legacy-a', 'legacy-b'] as $rawRecipient) {
            ShipmentNotification::query()->create([
                'shipment_id' => null,
                'timeline_event_id' => $timelineEventId,
                'channel' => 'email',
                'recipient_type' => 'user',
                'recipient_id' => $rawRecipient,
                'notification_recipient_id' => null,
                'status' => 'pending',
            ]);
        }

        $this->assertSame(2, ShipmentNotification::query()
            ->where('timeline_event_id', $timelineEventId)
            ->where('channel', 'email')
            ->whereNull('notification_recipient_id')
            ->count());

        $resolver = new class extends NotificationRecipientResolver {
            public function resolve(array $attributes): NotificationRecipient
            {
                throw new \RuntimeException('resolver down');
            }
        };

        $user = \Marvel\Database\Models\User::create([
            'name' => 'Null Bypass User',
            'email' => 'null-bypass@example.test',
            'password' => bcrypt('password'),
        ]);
        $order = \Marvel\Database\Models\Order::query()->forceCreate([
            'tracking_number' => 'TRK-STABLE-NULL',
            'user_id' => $user->id,
        ]);
        $event = new \App\Events\Shipment\ShipmentStatusChanged(
            order: $order,
            oldStatus: 'pending',
            newStatus: 'in_transit',
            timelineEventId: $timelineEventId,
            orderId: $order->id,
            source: 'direct',
        );

        try {
            (new \App\Services\Shipment\ShipmentNotificationRecorder($resolver))->record($event);
            $this->fail('Supported recorder path must not fall back to unresolved recipient identity.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('resolver down', $e->getMessage());
        }

        $this->assertSame(2, ShipmentNotification::query()
            ->where('timeline_event_id', $timelineEventId)
            ->where('channel', 'email')
            ->whereNull('notification_recipient_id')
            ->count());
    }

    private function createTimelineEvent(): int
    {
        $flowId = DB::table('order_flows')->value('id');
        if (!$flowId) {
            $statusId = DB::table('order_statuses')->insertGetId([
                'code' => 'pending',
                'name' => json_encode(['en' => 'Pending', 'ar' => 'Pending']),
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $flowId = DB::table('order_flows')->insertGetId([
                'code' => 'local',
                'name' => json_encode(['en' => 'Local', 'ar' => 'Local']),
                'shipping_type' => 'local',
                'is_default' => true,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('order_flow_statuses')->insert([
                'flow_id' => $flowId,
                'status_id' => $statusId,
                'sort_order' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $currentStatusId = DB::table('order_flow_statuses')->where('flow_id', $flowId)->orderBy('sort_order')->value('status_id');
        $orderId = DB::table('orders')->insertGetId([
            'tracking_number' => 'TRK-TEST-1',
            'flow_id' => $flowId,
            'current_status_id' => $currentStatusId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('order_tracking_events')->insertGetId([
            'order_id' => $orderId,
            'event_type' => 'shipment.status_changed',
            'old_status' => 'pending',
            'new_status' => 'in_transit',
            'source' => 'direct',
            'actor_type' => 'system',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
