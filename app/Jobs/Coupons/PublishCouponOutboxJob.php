<?php

namespace App\Jobs\Coupons;

use App\Enums\QueueName;
use App\Services\Coupon\Distribution\Outbox\CouponOutboxService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Immediate outbox delivery on the existing database queue (high).
 * Dispatched afterCommit from trigger sites; the scheduler sweep
 * (coupons:publish-outbox) covers crashes between commit and this job.
 */
class PublishCouponOutboxJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $timeout = 120;

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120, 300, 600];
    }

    public function __construct(
        public readonly string $eventId,
    ) {
        $this->onQueue(QueueName::high());
    }

    public function handle(CouponOutboxService $outbox): void
    {
        if (! $outbox->publishOne($this->eventId)) {
            // publishOne is false only when another publisher owns the row
            // (fresh claim), the broker is down (sweep recovers with backoff),
            // or the row is poison-FAILED. In all three cases requeueing is
            // wrong — it would burn job attempts and fill failed_jobs during
            // an outage — so drop the job and let the minutely sweep own
            // recovery. Hard exceptions (e.g. DB down) still throw above and
            // use tries/backoff correctly.
            $this->delete();
        }
    }
}
