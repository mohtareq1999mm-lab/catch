<?php

namespace App\Services\Coupon\Distribution\Consumers;

use App\Enums\CouponDistributionRecipientStatus;
use App\Enums\CouponDistributionRunStatus;
use App\Models\CouponDistributionRecipient;
use App\Models\CouponDistributionRun;
use App\Services\Coupon\Distribution\DistributionRunService;
use App\Services\Coupon\Distribution\Events\CouponDistributionEvents;
use App\Services\Coupon\Distribution\Events\CouponEventEnvelope;
use App\Services\Coupon\Distribution\NonDistributableCouponException;
use App\Services\Coupon\Distribution\Observability\CouponEventLogService;
use App\Services\Coupon\Distribution\Outbox\CouponOutboxService;
use App\Services\Coupon\Distribution\Selection\CouponCandidateSelector;
use App\Services\Coupon\Distribution\TreeHash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Marvel\Database\Models\Coupon;

/**
 * Handles coupon.distribution.start: validates coupon/run, scans candidates
 * with chunkById, creates recipient rows (discovered), and emits one
 * coupon.distribution.chunk per chunk through the outbox. Runs inside the
 * consumer (never in a web request). Audience-capped.
 */
class DistributionStartHandler
{
    use AssertsCouponPayload;

    public function __construct(
        private readonly DistributionRunService $runs,
        private readonly CouponCandidateSelector $selector,
        private readonly CouponOutboxService $outbox,
        private readonly CouponEventLogService $eventLog,
    ) {}

    /**
     * @return array{run_id: int, chunks: int, candidates: int}
     *
     * @throws \Throwable
     */
    public function handle(CouponEventEnvelope $envelope): array
    {
        $this->requirePayloadKeys($envelope, ['run_id', 'coupon_id']);
        $payload = $envelope->payload;
        $run = CouponDistributionRun::query()->findOrFail($payload['run_id']);
        $coupon = Coupon::query()->with('targeting')->findOrFail($payload['coupon_id']);

        if (in_array($run->status, [CouponDistributionRunStatus::CANCELLED, CouponDistributionRunStatus::COMPLETED, CouponDistributionRunStatus::FAILED], true)) {
            // Terminal states are terminal: a stale/redelivered start event
            // must never resurrect a finished run back to RUNNING.
            return ['run_id' => $run->getKey(), 'chunks' => 0, 'candidates' => 0];
        }

        // Cheap fan-out validity: status + dates only (CouponLiveCheck).
        // Claim capacity is NEVER a fan-out gate.
        if (! \App\Services\Coupon\Distribution\CouponLiveCheck::isLive($coupon)) {
            $this->runs->finish($run, CouponDistributionRunStatus::CANCELLED);
            Log::info('coupon.distribution.run_cancelled_invalid_coupon', ['run_id' => $run->getKey()]);

            return ['run_id' => $run->getKey(), 'chunks' => 0, 'candidates' => 0];
        }

        $targeting = $coupon->targeting;

        if ($targeting === null) {
            throw new NonDistributableCouponException("Coupon [{$coupon->getKey()}] lost its targeting.");
        }

        $treeHash = TreeHash::forRuleTree($targeting->rule_tree, (string) $targeting->mode);

        if ($treeHash !== $run->tree_hash) {
            throw new NonDistributableCouponException("Run [{$run->getKey()}] tree drifted; refusing stale fan-out.");
        }

        $this->runs->markRunning($run);

        $chunkSize = (int) config('coupon-distribution.chunk_size', 500);
        $cap = max(1, (int) ($payload['audience_cap'] ?? config('coupon-distribution.default_audience_cap', 10000)));

        $candidateQuery = $this->selector->queryFor($coupon, $cap);

        // Single-user triggers (registration / address / order): evaluate
        // just this user instead of scanning the candidate audience.
        $scopeUserId = null;
        $scope = (string) ($payload['trigger_scope'] ?? '');

        if (str_starts_with($scope, 'user:') && ctype_digit(substr($scope, 5))) {
            $scopeUserId = (int) substr($scope, 5);
            $candidateQuery->where('users.id', $scopeUserId);
        }

        $candidates = 0;
        $chunks = 0;

        $candidateQuery->chunkById($chunkSize, function ($users) use ($run, $coupon, $treeHash, $envelope, $cap, &$candidates, &$chunks) {
            $ids = [];

            foreach ($users as $user) {
                if ($candidates >= $cap) {
                    break;
                }

                $ids[] = $user->getKey();
                $candidates++;
            }

            if ($ids === []) {
                return false;
            }

            DB::transaction(function () use ($run, $coupon, $treeHash, $ids, $envelope, &$chunks) {
                foreach ($ids as $userId) {
                    CouponDistributionRecipient::query()->firstOrCreate(
                        ['run_id' => $run->getKey(), 'user_id' => $userId],
                        [
                            'coupon_id' => $coupon->getKey(),
                            'tree_hash' => $treeHash,
                            'status' => CouponDistributionRecipientStatus::DISCOVERED,
                        ]
                    );
                }

                $chunkEvent = $envelope->derive(
                    CouponDistributionEvents::DISTRIBUTION_CHUNK,
                    [
                        'run_id' => $run->getKey(),
                        'coupon_id' => $coupon->getKey(),
                        'tree_hash' => $treeHash,
                        'user_ids' => $ids,
                        'chunk_index' => $chunks,
                    ]
                );
                // Chunk work dispatches immediately (few messages); the bulk
                // per-user evaluate fan-out rides the per-minute sweep.
                // NOTE: no markCompleted here — the consumer marks completion
                // after handling (event_id idempotency skips completed rows).
                $this->outbox->recordAndDispatch($chunkEvent);
                $this->eventLog->recordPublished($chunkEvent);

                $chunks++;
            });

            // Stop scanning once the audience cap is reached; otherwise the
            // next chunk continues.
            return $candidates < $cap;
        });

        CouponDistributionRun::query()->where('id', $run->getKey())->update(['candidate_count' => $candidates]);

        $completed = $envelope->derive(
            CouponDistributionEvents::CHUNK_CREATED,
            ['run_id' => $run->getKey(), 'chunks' => $chunks, 'candidates' => $candidates]
        );
        $this->outbox->record($completed);

        Log::info('coupon.distribution.chunks_created', [
            'run_id' => $run->getKey(),
            'chunks' => $chunks,
            'candidates' => $candidates,
        ]);

        if ($candidates === 0) {
            $this->runs->finish($run->fresh(), CouponDistributionRunStatus::COMPLETED);
        }

        return ['run_id' => $run->getKey(), 'chunks' => $chunks, 'candidates' => $candidates];
    }
}
