<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Console\Commands\RetryShipmentNotifications;
use App\Contracts\Shipping\ShipmentNotificationDispatcher;
use App\Enums\Shipping\NotificationDeliveryResult;
use App\Events\Shipment\ShipmentStatusChanged;
use App\Models\OrderTrackingEvent;
use App\Models\Shipment;
use App\Models\Shipping\ShipmentNotification;
use App\Models\Shipping\ShipmentNotificationHistory;
use App\Services\Shipment\ShipmentNotificationRecorder;
use App\Services\Shipment\ShipmentNotificationRetryService;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\User;
use Tests\TestCase;

/** #183: bounded retries, conservative ambiguity, and replayable history. */
class ShipmentNotificationRetryHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_recorder_stamps_source_and_writes_deduped_ordered_replayable_history(): void
    {
        [$event, $shipment, $timelineId] = $this->eventFixture('webhook');
        $recorder = app(ShipmentNotificationRecorder::class);

        $recorder->record($event);

        $notifications = ShipmentNotification::query()
            ->where('timeline_event_id', $timelineId)
            ->orderBy('id')
            ->get();
        $this->assertCount(3, $notifications);
        $this->assertSame(['webhook', 'webhook', 'webhook'], $notifications->pluck('source')->all());

        $history = ShipmentNotificationHistory::forTimeline($timelineId)->get();
        $this->assertCount(3, $history);
        $this->assertSame(['recorded', 'recorded', 'recorded'], $history->pluck('event')->all());
        $this->assertSame(['webhook', 'webhook', 'webhook'], $history->pluck('source')->all());
        $this->assertSame([0, 0, 0], $history->pluck('attempt')->all());
        $this->assertSame($history->pluck('id')->all(), $history->pluck('id')->sort()->values()->all());

        // Replay derives the full identity from the persisted timeline row.
        $replay = ShipmentStatusChanged::forTimelineEvent(
            OrderTrackingEvent::findOrFail($timelineId),
            $shipment,
        );
        $this->assertSame($event->timelineEventId, $replay->timelineEventId);
        $this->assertSame($event->source, $replay->source);
        $this->assertSame($event->actorId, $replay->actorId);
        $this->assertSame($event->actorType, $replay->actorType);

        $recorder->record($replay);

        $this->assertSame(3, ShipmentNotification::query()->where('timeline_event_id', $timelineId)->count());
        $this->assertSame(3, ShipmentNotificationHistory::forTimeline($timelineId)->count());
        $this->assertSame(3, ShipmentNotificationHistory::forTimeline($timelineId, 'webhook')->count());
        $this->assertSame(0, ShipmentNotificationHistory::forTimeline($timelineId, 'admin')->count());
    }

    public function test_history_is_append_only(): void
    {
        $notification = $this->notificationFixture('direct');
        ShipmentNotificationHistory::appendFor($notification, 'recorded');
        $row = ShipmentNotificationHistory::firstOrFail();

        $this->assertFalse($row->update(['detail' => 'rewritten']));
        $this->assertFalse($row->delete());

        $this->assertSame(1, ShipmentNotificationHistory::count());
        $this->assertNull($row->fresh()->detail);
    }

    public function test_retries_are_bounded_with_exponential_backoff_and_exhaustion_alerts(): void
    {
        Carbon::setTestNow('2026-11-25 10:00:00');
        $notification = $this->notificationFixture('webhook');
        Log::spy();

        $service = app(ShipmentNotificationRetryService::class);
        $failed = fn () => NotificationDeliveryResult::FAILED;

        $first = $service->attempt($notification, $failed);
        $this->assertSame(1, $first->attempts);
        $this->assertSame('pending', $first->status);
        $this->assertSame(
            Carbon::parse('2026-11-25 10:01:00')->timestamp,
            $first->next_retry_at->timestamp
        );

        // Not due yet: the same row cannot be dispatched a second time.
        $this->assertSame(1, $service->attempt($first, $failed)->attempts);

        Carbon::setTestNow(Carbon::parse('2026-11-25 10:01:01'));
        $second = $service->attempt($first->fresh(), $failed);
        $this->assertSame(2, $second->attempts);
        $this->assertSame(
            Carbon::parse('2026-11-25 10:03:01')->timestamp,
            $second->next_retry_at->timestamp
        );

        Carbon::setTestNow(Carbon::parse('2026-11-25 10:03:02'));
        $third = $service->attempt($second->fresh(), $failed);
        $this->assertSame(3, $third->attempts);
        $this->assertSame('exhausted', $third->status);
        $this->assertNull($third->next_retry_at);

        $this->assertSame(
            [1, 2, 3],
            ShipmentNotificationHistory::forTimeline($notification->timeline_event_id)
                ->where('event', 'attempted')
                ->orderBy('id')
                ->pluck('attempt')
                ->all()
        );
        $this->assertSame(
            ['failed', 'failed', 'exhausted'],
            ShipmentNotificationHistory::forTimeline($notification->timeline_event_id)
                ->whereIn('event', ['failed', 'exhausted'])
                ->orderBy('id')
                ->pluck('event')
                ->all()
        );

        Log::shouldHaveReceived('error')->once()->withArgs(
            function (string $message, array $context) use ($notification): bool {
                return $message === 'shipment_notification.retry_exhausted'
                    && $context['notification_id'] === $notification->id
                    && $context['source'] === 'webhook'
                    && $context['attempts'] === 3;
            }
        );
    }

    public function test_ambiguous_delivery_is_terminal_and_never_blindly_retried(): void
    {
        $notification = $this->notificationFixture('webhook');
        Log::spy();
        $service = app(ShipmentNotificationRetryService::class);

        $after = $service->attempt($notification, fn () => NotificationDeliveryResult::AMBIGUOUS);

        $this->assertSame('ambiguous', $after->status);
        $this->assertSame(1, $after->attempts);
        $this->assertNull($after->next_retry_at);
        $this->assertSame('ambiguous_delivery_result', $after->last_error);
        $this->assertSame(
            ['attempted', 'ambiguous'],
            ShipmentNotificationHistory::forTimeline($notification->timeline_event_id)
                ->orderBy('id')
                ->pluck('event')
                ->all()
        );

        $dispatches = 0;
        $processed = $service->processDue(function () use (&$dispatches) {
            $dispatches++;

            return NotificationDeliveryResult::DELIVERED;
        });

        $this->assertSame(0, $processed);
        $this->assertSame(0, $dispatches);
        $this->assertSame(1, $after->fresh()->attempts);

        Log::shouldHaveReceived('warning')->once()->withArgs(
            function (string $message, array $context) use ($notification): bool {
                return $message === 'shipment_notification.ambiguous_delivery'
                    && $context['notification_id'] === $notification->id
                    && $context['source'] === 'webhook';
            }
        );
    }

    public function test_dispatcher_exception_is_treated_as_ambiguous(): void
    {
        Log::spy();
        $notification = $this->notificationFixture('admin');

        $after = app(ShipmentNotificationRetryService::class)->attempt(
            $notification,
            function (): never {
                throw new \RuntimeException('provider accepted but response lost');
            }
        );

        $this->assertSame('ambiguous', $after->status);
        $this->assertNull($after->next_retry_at);
        $this->assertStringStartsWith('dispatcher_exception:', $after->last_error);
    }

    public function test_due_retries_are_processed_oldest_first_and_honor_backoff(): void
    {
        Carbon::setTestNow('2026-11-25 09:00:00');
        $first = $this->notificationFixture('webhook');
        $later = $this->notificationFixture('admin');
        ShipmentNotification::query()->whereKey($later->id)->update([
            'next_retry_at' => Carbon::parse('2026-11-25 12:00:00'),
        ]);

        $service = app(ShipmentNotificationRetryService::class);
        $seen = [];
        $processed = $service->processDue(function (ShipmentNotification $notification) use (&$seen) {
            $seen[] = $notification->id;

            return NotificationDeliveryResult::DELIVERED;
        }, 1);

        $this->assertSame(1, $processed);
        $this->assertSame([$first->id], $seen);
        $this->assertSame('sent', $first->fresh()->status);
        $this->assertNotNull($first->fresh()->sent_at);
        $this->assertSame(0, $later->fresh()->attempts);
    }

    /** @dataProvider invalidRetryLimits */
    public function test_retry_batch_limit_rejects_out_of_range_values(int $limit): void
    {
        $service = app(ShipmentNotificationRetryService::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Limit must be between 1 and 500.');
        $service->processDue(fn () => NotificationDeliveryResult::DELIVERED, $limit);
    }

    /** @return array<string, array{int}> */
    public static function invalidRetryLimits(): array
    {
        return ['zero' => [0], 'above_cap' => [501]];
    }

    public function test_non_enum_dispatch_result_is_ambiguous(): void
    {
        Log::spy();
        $notification = $this->notificationFixture('webhook');

        $after = app(ShipmentNotificationRetryService::class)->attempt(
            $notification,
            fn () => 'delivered'
        );

        $this->assertSame('ambiguous', $after->status);
        $this->assertSame('invalid_dispatch_result', $after->last_error);
        $this->assertNull($after->next_retry_at);
    }

    public function test_process_due_does_not_starve_valid_rows_on_max_attempts_above_cap(): void
    {
        Carbon::setTestNow('2026-11-25 10:00:00');
        $starved = $this->notificationFixture('webhook');
        $starved->forceFill([
            'attempts' => 10,
            'max_attempts' => 100,
            'next_retry_at' => null,
        ])->save();
        $valid = $this->notificationFixture('admin');

        $seen = [];
        $processed = app(ShipmentNotificationRetryService::class)->processDue(
            function (ShipmentNotification $notification) use (&$seen) {
                $seen[] = $notification->id;

                return NotificationDeliveryResult::DELIVERED;
            },
            1
        );

        // Without the clamped selector the capped-but-unclaimable row consumes
        // the batch limit on every sweep, so the valid row never runs.
        $this->assertSame(1, $processed);
        $this->assertSame([$valid->id], $seen);
        $this->assertSame('sent', $valid->fresh()->status);
        $this->assertSame(10, $starved->fresh()->attempts);
        $this->assertSame('pending', $starved->fresh()->status);
    }

    public function test_process_due_uses_clamped_cap_when_max_attempts_is_zero(): void
    {
        Log::spy();
        $notification = $this->notificationFixture('admin');
        $notification->forceFill(['max_attempts' => 0])->save();

        $processed = app(ShipmentNotificationRetryService::class)->processDue(
            fn () => NotificationDeliveryResult::FAILED
        );

        $this->assertSame(1, $processed);
        $after = $notification->fresh();
        $this->assertSame(1, $after->attempts);
        $this->assertSame('exhausted', $after->status);
    }

    public function test_retry_sweep_command_is_registered_and_scheduled(): void
    {
        $this->assertInstanceOf(RetryShipmentNotifications::class, app(RetryShipmentNotifications::class));

        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event) => str_contains((string) $event->command, 'shipping:retry-notifications'));

        $this->assertNotNull($event);
        $this->assertSame('*/5 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertTrue($event->onOneServer);
    }

    public function test_retry_sweep_command_processes_due_notifications_with_configured_limit(): void
    {
        $first = $this->notificationFixture('webhook');
        $second = $this->notificationFixture('admin');
        $this->app->bind(ShipmentNotificationDispatcher::class, static fn (): ShipmentNotificationDispatcher => new class implements ShipmentNotificationDispatcher {
            public function __invoke(ShipmentNotification $notification): NotificationDeliveryResult
            {
                return NotificationDeliveryResult::DELIVERED;
            }
        });

        $this->artisan('shipping:retry-notifications', ['--limit' => 1])
            ->expectsOutputToContain('Processed 1')
            ->assertExitCode(0);

        $this->assertSame('sent', $first->fresh()->status);
        $this->assertSame(1, $first->fresh()->attempts);
        $this->assertSame('pending', $second->fresh()->status);
        $this->assertSame(0, $second->fresh()->attempts);
    }

    public function test_status_change_event_payload_is_after_commit_and_replayable(): void
    {
        [$event, $shipment, $timelineId] = $this->eventFixture('admin');
        $this->assertInstanceOf(ShouldDispatchAfterCommit::class, $event);
        $this->assertSame($shipment->id, $event->shipmentId);
        $this->assertSame($timelineId, $event->timelineEventId);
        $this->assertSame($event->order->id, $event->orderId);
        $this->assertSame('pending', $event->oldStatus);
        $this->assertSame('in_transit', $event->newStatus);
        $this->assertSame('admin', $event->source);
        $this->assertNotNull($event->actorId);
        $this->assertSame('user', $event->actorType);

        $seen = [];
        Event::listen(ShipmentStatusChanged::class, function (ShipmentStatusChanged $received) use (&$seen): void {
            $seen[] = $received;
        });

        DB::beginTransaction();
        event($event);
        $this->assertCount(0, $seen);
        DB::rollBack();
        $this->assertCount(0, $seen);

        DB::beginTransaction();
        event($event);
        DB::commit();
        $this->assertCount(1, $seen);
        $this->assertSame($timelineId, $seen[0]->timelineEventId);

        // Replaying the committed identity cannot duplicate recorded intents.
        event(ShipmentStatusChanged::forTimelineEvent(
            OrderTrackingEvent::findOrFail($timelineId),
            $shipment,
        ));
        $this->assertSame(3, ShipmentNotification::query()->where('timeline_event_id', $timelineId)->count());
    }

    /** @return array{0: ShipmentStatusChanged, 1: Shipment, 2: int} */
    private function eventFixture(string $source): array
    {
        $user = User::create([
            'name' => 'Retry History User',
            'email' => 'retry-'.Str::lower(Str::random(10)).'@example.test',
            'password' => bcrypt('password'),
        ]);
        $order = Order::query()->forceCreate([
            'tracking_number' => 'TRK-'.Str::upper(Str::random(8)),
            'user_id' => $user->id,
        ]);
        $shipment = Shipment::query()->create(['order_id' => $order->id, 'status' => 'pending']);
        $timelineId = (int) DB::table('order_tracking_events')->insertGetId([
            'order_id' => $order->id,
            'event_type' => 'shipment.status_changed',
            'old_status' => 'pending',
            'new_status' => 'in_transit',
            'actor_type' => 'user',
            'actor_id' => $user->id,
            'source' => $source,
            'metadata' => json_encode(['tracking_number' => 'TRK-REPLAY']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $event = new ShipmentStatusChanged(
            order: $order,
            oldStatus: 'pending',
            newStatus: 'in_transit',
            timelineEventId: $timelineId,
            shipmentId: (int) $shipment->id,
            orderId: (int) $order->id,
            actorId: (int) $user->id,
            actorType: 'user',
            source: $source,
        );

        return [$event, $shipment, $timelineId];
    }

    private function notificationFixture(string $source): ShipmentNotification
    {
        [, $shipment, $timelineId] = $this->eventFixture($source);

        return ShipmentNotification::query()->create([
            'shipment_id' => $shipment->id,
            'timeline_event_id' => $timelineId,
            'channel' => 'email',
            'recipient_type' => 'user',
            'recipient_id' => (string) $shipment->order->user_id,
            'status' => 'pending',
            'source' => $source,
        ]);
    }
}
