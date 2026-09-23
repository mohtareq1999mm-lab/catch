<?php

namespace App\Services\Coupon\Distribution;

use App\Enums\CouponDistributionRunStatus;
use App\Enums\CouponDistributionTriggerType;
use App\Services\Coupon\Distribution\Events\CouponDistributionEvents;
use App\Services\Coupon\Distribution\Events\CouponEventEnvelope;
use App\Services\Coupon\Distribution\Outbox\CouponOutboxService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Marvel\Database\Models\Coupon;

/**
 * Distribution entry point. Validates the coupon, computes the targeting
 * version, dedupes the run, and emits coupon.distribution.start through
 * the outbox. Never fans out synchronously; never touches assignments,
 * claims, reservations, or capacity.
 */
class DistributionService
{
    public function __construct(
        private readonly DistributionRunService $runs,
        private readonly CouponOutboxService $outbox,
    ) {}

    /**
     * @return array{run: \App\Models\CouponDistributionRun, created: bool}
     */
    public function startDistribution(
        Coupon $coupon,
        CouponDistributionTriggerType $trigger,
        ?string $scope = null,
        ?string $triggerId = null,
        ?int $audienceCap = null,
        ?string $correlationId = null,
        int $delaySeconds = 0,
    ): array {
        $targeting = $coupon->targeting;

        if ($targeting === null || ! in_array($targeting->mode, ['dynamic', 'assignment_and_dynamic', 'assignment_or_dynamic'], true)) {
            throw new NonDistributableCouponException(
                "Coupon [{$coupon->getKey()}] has no dynamic targeting and is not distributable."
            );
        }

        $treeHash = TreeHash::forRuleTree($targeting->rule_tree, (string) $targeting->mode);

        [$run, $created] = DB::transaction(function () use ($coupon, $treeHash, $trigger, $scope, $triggerId, $audienceCap, $correlationId, $delaySeconds) {
            [$run, $created] = $this->runs->startOrJoin(
                $coupon->getKey(), $treeHash, $trigger, $scope, $triggerId
            );

            if (! $created && $this->isTerminal($run) && ($scope = $this->reopenScope($trigger, $scope, $triggerId, $run)) !== null) {
                // The joined run is terminal but the caller brings a NEW
                // event (explicit manual re-run, or a new per-user event):
                // open a follow-up run instead of silently dropping it.
                [$run, $created] = $this->runs->startOrJoin(
                    $coupon->getKey(), $treeHash, $trigger, $scope, $triggerId
                );
            }

            if (! $created) {
                return [$run, false];
            }

            $envelope = CouponEventEnvelope::create(
                eventType: CouponDistributionEvents::DISTRIBUTION_START,
                aggregateId: $coupon->getKey(),
                payload: [
                    'run_id' => $run->getKey(),
                    'coupon_id' => $coupon->getKey(),
                    'tree_hash' => $treeHash,
                    'trigger' => $trigger->value,
                    'trigger_scope' => $scope,
                    'audience_cap' => $audienceCap ?? (int) config('coupon-distribution.default_audience_cap', 10000),
                ],
                correlationId: $correlationId,
                distributionRunId: $run->getKey(),
                treeHash: $treeHash,
            );

            // Delayed fan-out (trigger-driven runs): the coupon is valid
            // now; the sweep publishes when the window lapses. Immediate
            // manual runs keep delay 0 (recordAndDispatch).
            if ($delaySeconds > 0) {
                $this->outbox->recordDelayed($envelope, $delaySeconds);
            } else {
                $this->outbox->recordAndDispatch($envelope);
            }

            return [$run, true];
        });

        if ($created) {
            Log::info('coupon.distribution.run_started', [
                'run_id' => $run->getKey(),
                'coupon_id' => $coupon->getKey(),
                'trigger' => $trigger->value,
            ]);
        }

        return ['run' => $run->fresh(), 'created' => $created];
    }

    public function cancelRun(int $runId): void
    {
        $run = \App\Models\CouponDistributionRun::query()->findOrFail($runId);

        if ($this->isTerminal($run)) {
            return;
        }

        $this->runs->finish($run, CouponDistributionRunStatus::CANCELLED);
    }

    private function isTerminal(\App\Models\CouponDistributionRun $run): bool
    {
        return in_array($run->status, [
            CouponDistributionRunStatus::COMPLETED,
            CouponDistributionRunStatus::FAILED,
            CouponDistributionRunStatus::CANCELLED,
        ], true);
    }

    /**
     * Follow-up scope for a terminal joined run, or null to keep joining.
     *
     * - manual: every explicit admin call after terminal state opens a new
     *   run (live runs still 409 via join). Cross-run NOTIFIED state keeps
     *   this safe against duplicate notifications.
     * - user:{id} + new triggerId (a NEW event for the same user): new run
     *   so late eligibility is re-evaluated. Same/unknown event joins.
     * - activation/targeting_changed: one run per tree version ever; joins.
     */
    private function reopenScope(
        CouponDistributionTriggerType $trigger,
        ?string $scope,
        ?string $triggerId,
        \App\Models\CouponDistributionRun $run,
    ): ?string {
        if ($trigger === CouponDistributionTriggerType::MANUAL) {
            return ($scope ?? 'manual').':'.(string) \Illuminate\Support\Str::uuid();
        }

        if ($scope !== null
            && str_starts_with($scope, 'user:')
            && $triggerId !== null
            && $triggerId !== $run->trigger_id
        ) {
            return $scope.':'.md5($triggerId);
        }

        return null;
    }
}
