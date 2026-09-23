<?php

namespace App\Services\Coupon\Distribution\Consumers;

use App\Enums\CouponDistributionRunStatus;
use App\Models\CouponDistributionRun;
use App\Services\Coupon\Distribution\Events\CouponDistributionEvents;
use App\Services\Coupon\Distribution\Events\CouponEventEnvelope;
use App\Services\Coupon\Distribution\Observability\CouponEventLogService;
use App\Services\Coupon\Distribution\Outbox\CouponOutboxService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Handles coupon.distribution.chunk: fans one chunk out to per-user
 * coupon.user.evaluate work messages (via outbox, batched). Cheap per
 * message so heartbeats keep flowing; the evaluate consumer does the
 * Engine work one user at a time.
 */
class DistributionChunkHandler
{
    use AssertsCouponPayload;

    public function __construct(
        private readonly CouponOutboxService $outbox,
        private readonly CouponEventLogService $eventLog,
    ) {}

    /**
     * @return array{evaluations: int}
     */
    public function handle(CouponEventEnvelope $envelope): array
    {
        $this->requirePayloadKeys($envelope, ['run_id', 'coupon_id', 'user_ids']);
        $payload = $envelope->payload;
        $run = CouponDistributionRun::query()->findOrFail($payload['run_id']);

        if (in_array($run->status, [CouponDistributionRunStatus::CANCELLED, CouponDistributionRunStatus::COMPLETED, CouponDistributionRunStatus::FAILED], true)) {
            return ['evaluations' => 0];
        }

        $userIds = array_values(array_filter(
            array_map(static fn ($id) => (int) $id, (array) ($payload['user_ids'] ?? [])),
            static fn ($id) => $id > 0
        ));

        if ($userIds === []) {
            throw new PoisonMessageException('Chunk carries no usable user ids.');
        }

        $count = 0;

        DB::transaction(function () use ($envelope, $payload, $run, $userIds, &$count) {
            foreach ($userIds as $userId) {
                $recipientId = DB::table('coupon_distribution_recipients')
                    ->where('run_id', $run->getKey())
                    ->where('user_id', $userId)
                    ->value('id');

                if ($recipientId === null) {
                    continue;
                }

                $evaluate = $envelope->derive(
                    CouponDistributionEvents::USER_EVALUATE,
                    [
                        'run_id' => $run->getKey(),
                        'recipient_id' => (int) $recipientId,
                        'coupon_id' => $payload['coupon_id'],
                        'user_id' => $userId,
                        'tree_hash' => $payload['tree_hash'] ?? null,
                    ],
                    $userId
                );
                $this->outbox->record($evaluate);
                $count++;
            }
        });

        $done = $envelope->derive(
            CouponDistributionEvents::CHUNK_COMPLETED,
            ['run_id' => $run->getKey(), 'evaluations' => $count]
        );
        $this->outbox->record($done);
        $this->eventLog->recordPublished($done);

        Log::info('coupon.distribution.chunk_fanned_out', [
            'run_id' => $run->getKey(),
            'evaluations' => $count,
        ]);

        return ['evaluations' => $count];
    }
}
