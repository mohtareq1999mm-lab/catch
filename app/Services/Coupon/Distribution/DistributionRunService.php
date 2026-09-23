<?php

namespace App\Services\Coupon\Distribution;

use App\Enums\CouponDistributionRecipientStatus;
use App\Enums\CouponDistributionRunStatus;
use App\Enums\CouponDistributionTriggerType;
use App\Models\CouponDistributionRecipient;
use App\Models\CouponDistributionRun;
use App\Services\Coupon\Distribution\Events\CouponDistributionEvents;
use App\Services\Coupon\Distribution\Events\CouponEventEnvelope;
use App\Services\Coupon\Distribution\Observability\CouponEventLogService;
use App\Services\Coupon\Distribution\Outbox\CouponOutboxService;
use Illuminate\Database\QueryException;

/**
 * Distribution runs with dedupe-key arbitration.
 *
 * dedupe_key = coupon_id : tree_hash : trigger_type : trigger_scope.
 * Concurrent duplicate starts converge: the unique constraint arbitrates,
 * losers receive the existing run (no double fan-out, no admin-double-click
 * mass re-run).
 */
class DistributionRunService
{
    public function __construct(
        private readonly CouponOutboxService $outbox,
        private readonly CouponEventLogService $eventLog,
    ) {}
    public function dedupeKey(int $couponId, string $treeHash, CouponDistributionTriggerType $trigger, ?string $scope = null): string
    {
        return implode(':', [$couponId, $treeHash, $trigger->value, $scope ?? '-']);
    }

    /**
     * Start (or rejoin) a run. Returns [run, created].
     */
    public function startOrJoin(
        int $couponId,
        string $treeHash,
        CouponDistributionTriggerType $trigger,
        ?string $scope = null,
        ?string $triggerId = null,
    ): array {
        $key = $this->dedupeKey($couponId, $treeHash, $trigger, $scope);

        try {
            $run = CouponDistributionRun::query()->create([
                'coupon_id' => $couponId,
                'trigger_type' => $trigger->value,
                'trigger_id' => $triggerId,
                'tree_hash' => $treeHash,
                'dedupe_key' => $key,
                'status' => CouponDistributionRunStatus::PENDING,
                'started_at' => now(),
            ]);

            return [$run, true];
        } catch (QueryException $e) {
            if (! $this->isUniqueViolation($e)) {
                throw $e;
            }

            $existing = CouponDistributionRun::query()->where('dedupe_key', $key)->firstOrFail();

            return [$existing, false];
        }
    }

    public function markRunning(CouponDistributionRun $run): void
    {
        $run->update(['status' => CouponDistributionRunStatus::RUNNING]);
    }

    public function incrementCounter(CouponDistributionRun $run, string $column, int $by = 1): void
    {
        CouponDistributionRun::query()->where('id', $run->getKey())->increment($column, $by);
    }

    public function finish(CouponDistributionRun $run, CouponDistributionRunStatus $status): void
    {
        $run->update([
            'status' => $status,
            'finished_at' => now(),
        ]);
    }

    /**
     * Converge a run to completed/failed once no recipient still owns
     * pending work. ELIGIBLE counts as open: its notification.requested
     * message may still be in flight — finishing early would make the
     * notification consumer (which skips terminal runs) drop it. The same
     * holds for FAILED_RETRYABLE: its redelivery is still expected.
     */
    public function maybeFinishRun(CouponDistributionRun $run, CouponEventEnvelope $envelope): void
    {
        $open = CouponDistributionRecipient::query()
            ->where('run_id', $run->getKey())
            ->whereIn('status', [
                CouponDistributionRecipientStatus::DISCOVERED->value,
                CouponDistributionRecipientStatus::ELIGIBLE->value,
                CouponDistributionRecipientStatus::FAILED_RETRYABLE->value,
            ])
            ->exists();

        if ($open) {
            return;
        }

        $fresh = $run->fresh();

        if (in_array($fresh->status, [
            CouponDistributionRunStatus::CANCELLED,
            CouponDistributionRunStatus::COMPLETED,
            CouponDistributionRunStatus::FAILED,
        ], true)) {
            return;
        }

        $this->reconcile($fresh);

        $failed = (int) $fresh->failed_count;
        $this->finish($fresh, $failed > 0 ? CouponDistributionRunStatus::FAILED : CouponDistributionRunStatus::COMPLETED);

        $event = $envelope->derive(
            $failed > 0 ? CouponDistributionEvents::DISTRIBUTION_FAILED : CouponDistributionEvents::DISTRIBUTION_COMPLETED,
            ['run_id' => $fresh->getKey(), 'failed' => $failed]
        );
        $this->outbox->record($event);
        $this->eventLog->recordPublished($event);
    }

    /**
     * Reconcile counters from recipient rows (observable truth for operators).
     *
     * @return array<string, int>
     */
    public function reconcile(CouponDistributionRun $run): array
    {
        $counts = $run->recipients()
            ->selectRaw('status, COUNT(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status');

        $by = static fn (string $status): int => (int) ($counts[$status] ?? 0);

        $result = [
            'candidate_count' => $run->recipients()->count(),
            'eligible_count' => $by('eligible') + $by('notified'),
            'not_eligible_count' => $by('not_eligible'),
            'notified_count' => $by('notified'),
            'failed_count' => $by('failed_retryable') + $by('failed_permanent'),
            'duplicate_skipped_count' => $by('duplicate_skipped'),
        ];

        $run->update($result);

        return $result;
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'duplicate')
            || str_contains($message, 'unique')
            || str_contains($message, '1062')
            || str_contains($message, '23000');
    }
}
