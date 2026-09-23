<?php

namespace App\Services\Coupon\Distribution\Consumers;

use App\Enums\CouponDistributionRecipientStatus;
use App\Enums\CouponDistributionRunStatus;
use App\Models\CouponDistributionRecipient;
use App\Models\CouponDistributionRun;
use App\Services\Coupon\Distribution\DistributionRunService;
use App\Services\Coupon\Distribution\EligibilityTransitionService;
use App\Services\Coupon\Distribution\Events\CouponDistributionEvents;
use App\Services\Coupon\Distribution\Events\CouponEventEnvelope;
use App\Services\Coupon\Distribution\Observability\CouponEventLogService;
use App\Services\Coupon\Distribution\Outbox\CouponOutboxService;
use Illuminate\Support\Facades\Log;
use Marvel\Database\Models\Coupon;
use Marvel\Database\Models\User;

/**
 * Handles coupon.user.evaluate: loads coupon + user, runs the
 * EligibilityEngine via the transition service, then advances run
 * completion when no recipient remains undiscovered.
 */
class UserEvaluateHandler
{
    use AssertsCouponPayload;

    public function __construct(
        private readonly EligibilityTransitionService $transitions,
        private readonly DistributionRunService $runs,
        private readonly CouponOutboxService $outbox,
        private readonly CouponEventLogService $eventLog,
    ) {}

    /**
     * @return array{outcome: string, eligible: bool}
     */
    public function handle(CouponEventEnvelope $envelope): array
    {
        $this->requirePayloadKeys($envelope, ['run_id', 'recipient_id', 'coupon_id']);
        $payload = $envelope->payload;
        $run = CouponDistributionRun::query()->findOrFail($payload['run_id']);
        $recipient = CouponDistributionRecipient::query()->findOrFail($payload['recipient_id']);

        if (in_array($run->status, [CouponDistributionRunStatus::CANCELLED, CouponDistributionRunStatus::COMPLETED, CouponDistributionRunStatus::FAILED], true)) {
            return ['outcome' => EligibilityTransitionService::OUTCOME_DUPLICATE, 'eligible' => false];
        }

        $coupon = Coupon::query()->with('targeting')->findOrFail($payload['coupon_id']);
        $user = User::query()->find($payload['user_id']);

        if ($user === null) {
            $recipient->update([
                'status' => CouponDistributionRecipientStatus::FAILED_PERMANENT,
                'error' => 'User no longer exists.',
            ]);
            $run->increment('failed_count');
            $this->runs->maybeFinishRun($run, $envelope);

            return ['outcome' => EligibilityTransitionService::OUTCOME_NOT_ELIGIBLE, 'eligible' => false];
        }

        $today = today();
        $live = \App\Services\Coupon\Distribution\CouponLiveCheck::isLive($coupon, $today);

        if (! $live) {
            // Disabled/expired mid-flight: stop harmlessly, no notification.
            $recipient->update(['status' => CouponDistributionRecipientStatus::NOT_ELIGIBLE]);

            return ['outcome' => EligibilityTransitionService::OUTCOME_NOT_ELIGIBLE, 'eligible' => false];
        }

        $treeHash = (string) ($payload['tree_hash'] ?? $run->tree_hash);

        $result = $this->transitions->evaluate($coupon, $user, $run, $recipient, $envelope, $treeHash);

        $completed = $envelope->derive(
            CouponDistributionEvents::EVALUATION_COMPLETED,
            [
                'run_id' => $run->getKey(),
                'recipient_id' => $recipient->getKey(),
                'outcome' => $result['outcome'],
            ],
            $user->getKey()
        );
        $this->outbox->record($completed);
        $this->eventLog->recordPublished($completed);

        $this->runs->maybeFinishRun($run, $envelope);

        Log::info('coupon.user.evaluated', [
            'run_id' => $run->getKey(),
            'coupon_id' => $coupon->getKey(),
            'user_id' => $user->getKey(),
            'outcome' => $result['outcome'],
        ]);

        return ['outcome' => $result['outcome'], 'eligible' => $result['eligible']];
    }
}
