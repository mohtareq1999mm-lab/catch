<?php

namespace App\Services\Coupon\Distribution;

use App\Enums\CouponDistributionRecipientStatus;
use App\Enums\CouponDistributionUserState;
use App\Models\CouponDistributionRecipient;
use App\Models\CouponDistributionRun;
use App\Models\CouponDistributionUserState as UserStateRow;
use App\Services\Coupon\Distribution\Events\CouponDistributionEvents;
use App\Services\Coupon\Distribution\Events\CouponEventEnvelope;
use App\Services\Coupon\Distribution\Outbox\CouponOutboxService;
use App\Services\Coupon\Eligibility\EligibilityEngine;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Coupon;
use Marvel\Database\Models\User;

/**
 * Single place where evaluation becomes durable state.
 *
 * Policy: notify ONLY on unseen/NOT_ELIGIBLE → ELIGIBLE for the CURRENT
 * tree_hash. Same-hash retries, duplicate chunks, re-runs, and
 * already-notified users converge to duplicate_skipped with zero new
 * notifications. A new tree_hash re-opens evaluation for everyone.
 */
class EligibilityTransitionService
{
    public const OUTCOME_NOTIFIED_PATH = 'notify';
    public const OUTCOME_DUPLICATE = 'duplicate';
    public const OUTCOME_NOT_ELIGIBLE = 'not_eligible';

    public function __construct(
        private readonly EligibilityEngine $engine,
        private readonly CouponOutboxService $outbox,
    ) {}

    /**
     * @return array{outcome: string, eligible: bool, recipient: CouponDistributionRecipient, state: CouponDistributionUserState}
     */
    public function evaluate(
        Coupon $coupon,
        User $user,
        CouponDistributionRun $run,
        CouponDistributionRecipient $recipient,
        CouponEventEnvelope $cause,
        string $treeHash,
    ): array {
        return DB::transaction(function () use ($coupon, $user, $run, $recipient, $cause, $treeHash) {
            $result = $this->engine->evaluate($coupon, $user);
            $eligible = $result->isEligible;

            try {
                $state = UserStateRow::query()->lockForUpdate()->firstOrCreate(
                    ['coupon_id' => $coupon->getKey(), 'user_id' => $user->getKey()],
                    ['tree_hash' => $treeHash, 'state' => CouponDistributionUserState::NOT_ELIGIBLE]
                );
            } catch (\Illuminate\Database\QueryException $e) {
                // firstOrCreate race (SELECT then INSERT under concurrency):
                // the unique arbiter decided — reload the winner's row.
                $state = UserStateRow::query()->lockForUpdate()
                    ->where('coupon_id', $coupon->getKey())
                    ->where('user_id', $user->getKey())
                    ->firstOrFail();
            }

            // New targeting version re-opens evaluation (prior notified does
            // not suppress the new version).
            if ($state->tree_hash !== $treeHash) {
                $state->update(['tree_hash' => $treeHash, 'state' => CouponDistributionUserState::NOT_ELIGIBLE]);
                $state->refresh();
            }

            if (! $eligible) {
                $recipient->update([
                    'status' => CouponDistributionRecipientStatus::NOT_ELIGIBLE,
                    'error' => null,
                ]);
                $state->update([
                    'state' => CouponDistributionUserState::NOT_ELIGIBLE,
                    'last_run_id' => $run->getKey(),
                    'last_recipient_id' => $recipient->getKey(),
                    'last_evaluated_at' => now(),
                ]);

                return [
                    'outcome' => self::OUTCOME_NOT_ELIGIBLE,
                    'eligible' => false,
                    'recipient' => $recipient->fresh(),
                    'state' => $state->fresh(),
                ];
            }

            // Eligible: suppress when this tree version already notified OR
            // a notification request is already in flight. The state row is
            // row-locked, so concurrent evaluations serialize here: the
            // second sees NOTIFY_PENDING and emits nothing (B3).
            if (in_array($state->state, [
                CouponDistributionUserState::NOTIFIED,
                CouponDistributionUserState::NOTIFY_PENDING,
            ], true)) {
                $recipient->update(['status' => CouponDistributionRecipientStatus::DUPLICATE_SKIPPED]);
                $run->increment('duplicate_skipped_count');

                return [
                    'outcome' => self::OUTCOME_DUPLICATE,
                    'eligible' => true,
                    'recipient' => $recipient->fresh(),
                    'state' => $state->fresh(),
                ];
            }

            $recipient->update(['status' => CouponDistributionRecipientStatus::ELIGIBLE]);
            $state->update([
                'state' => CouponDistributionUserState::NOTIFY_PENDING,
                'last_run_id' => $run->getKey(),
                'last_recipient_id' => $recipient->getKey(),
                'last_evaluated_at' => now(),
            ]);

            // became_eligible observation + notification work request, both
            // through the outbox so a crash here loses nothing.
            $eligibleEvent = $cause->derive(
                CouponDistributionEvents::BECAME_ELIGIBLE,
                ['run_id' => $run->getKey(), 'recipient_id' => $recipient->getKey(), 'coupon_id' => $coupon->getKey()],
                $user->getKey()
            );
            $this->outbox->record($eligibleEvent);

            $notificationEvent = $cause->derive(
                CouponDistributionEvents::NOTIFICATION_REQUESTED,
                [
                    'run_id' => $run->getKey(),
                    'recipient_id' => $recipient->getKey(),
                    'coupon_id' => $coupon->getKey(),
                    'user_id' => $user->getKey(),
                    'tree_hash' => $treeHash,
                ],
                $user->getKey()
            );
            $this->outbox->recordAndDispatch($notificationEvent);

            return [
                'outcome' => self::OUTCOME_NOTIFIED_PATH,
                'eligible' => true,
                'recipient' => $recipient->fresh(),
                'state' => $state->fresh(),
            ];
        });
    }
}
