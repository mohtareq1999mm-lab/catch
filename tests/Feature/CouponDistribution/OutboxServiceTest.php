<?php

namespace Tests\Feature\CouponDistribution;

use App\Models\CouponOutbox;
use App\Services\Coupon\Distribution\Events\CouponDistributionEvents;
use App\Services\Coupon\Distribution\Events\CouponEventEnvelope;
use App\Services\Coupon\Distribution\Messaging\CouponEventTransport;
use App\Services\Coupon\Distribution\Messaging\FakeCouponEventTransport;
use App\Services\Coupon\Distribution\Outbox\CouponOutboxService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OutboxServiceTest extends TestCase
{
    use RefreshDatabase;

    private FakeCouponEventTransport $fake;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fake = new FakeCouponEventTransport();
        $this->app->singleton(FakeCouponEventTransport::class, fn () => $this->fake);
    }

    private function envelope(): CouponEventEnvelope
    {
        return CouponEventEnvelope::create(
            CouponDistributionEvents::DISTRIBUTION_START,
            aggregateId: 1,
            payload: ['run_id' => 1],
        );
    }

    public function test_record_and_rollback_keeps_no_row()
    {
        $service = app(CouponOutboxService::class);

        try {
            DB::transaction(function () use ($service) {
                $service->record($this->envelope());

                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
        }

        // Atomicity: business rollback takes the outbox row with it.
        $this->assertSame(0, CouponOutbox::query()->count());
    }

    public function test_publish_one_delivers_and_marks_published()
    {
        $service = app(CouponOutboxService::class);
        $row = $service->record($this->envelope());

        $this->assertTrue($service->publishOne($row->event_id));
        $this->assertCount(1, $this->fake->published);

        $row->refresh();
        $this->assertSame('published', $row->status->value);
        $this->assertNotNull($row->published_at);

        // Event audit observes the publication.
        $this->assertDatabaseHas('coupon_event_logs', [
            'event_id' => $row->event_id,
            'status' => 'published',
        ]);
    }

    public function test_publish_one_is_idempotent_for_published_rows()
    {
        $service = app(CouponOutboxService::class);
        $row = $service->record($this->envelope());

        $service->publishOne($row->event_id);
        $service->publishOne($row->event_id);

        $this->assertCount(1, $this->fake->published);
    }

    public function test_broker_down_keeps_row_pending_with_backoff()
    {
        $service = app(CouponOutboxService::class);
        $row = $service->record($this->envelope());

        $this->fake->unreachable = true;

        $this->assertFalse($service->publishOne($row->event_id));

        $row->refresh();
        $this->assertSame('pending', $row->status->value);
        $this->assertSame(1, $row->attempts);
        $this->assertTrue($row->available_at->isFuture());
        $this->assertNotNull($row->last_error);

        // Business state survived; recovery publishes later.
        $this->fake->unreachable = false;
        $this->assertTrue($service->publishDue(100) >= 1 || $service->publishOne($row->event_id));
        $this->assertCount(1, $this->fake->published);
    }

    public function test_poison_payload_marks_failed_after_budget()
    {
        config()->set('coupon-distribution.outbox_max_attempts', 2);

        $service = app(CouponOutboxService::class);
        $row = $service->record($this->envelope());

        // Corrupt the stored payload so envelope parsing throws.
        $row->update(['payload' => ['broken' => true]]);

        $this->assertFalse($service->publishOne($row->event_id));
        $this->assertFalse($service->publishOne($row->event_id));

        $row->refresh();
        $this->assertSame('failed', $row->status->value);
        $this->assertCount(0, $this->fake->published);
    }

    public function test_publish_due_only_picks_due_rows()
    {
        $service = app(CouponOutboxService::class);

        $due = $service->record($this->envelope());
        $later = $service->record($this->envelope());
        $later->update(['available_at' => now()->addHour()]);

        $this->assertSame(1, $service->publishDue(100));
        $this->assertCount(1, $this->fake->published);
        $this->assertSame($due->event_id, $this->fake->published[0]->eventId);
    }
}
