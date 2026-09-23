<?php

namespace App\Services\Coupon\Distribution\Consumers;

use App\Enums\CouponDistributionRecipientStatus;
use App\Enums\CouponDistributionRunStatus;
use App\Enums\CouponDistributionUserState;
use App\Models\CouponDistributionRecipient;
use App\Models\CouponDistributionRun;
use App\Models\CouponDistributionUserState as UserStateRow;
use App\Notifications\UserCouponEligibleNotification;
use App\Services\Coupon\Distribution\DistributionRunService;
use App\Services\Coupon\Distribution\Events\CouponDistributionEvents;
use App\Services\Coupon\Distribution\Events\CouponEventEnvelope;
use App\Services\Coupon\Distribution\Observability\CouponEventLogService;
use App\Services\Coupon\Distribution\Outbox\CouponOutboxService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Marvel\Database\Models\Coupon;
use Marvel\Database\Models\User;

/**
 * Handles coupon.notification.requested: final cross-run dedupe guards,
 * owner-scoped delivery, durable notified transition. Retries never
 * duplicate: the notified state + notification-row check converge.
 */
class NotificationRequestHandler
{
    use AssertsCouponPayload;

    public function __construct(
        private readonly DistributionRunService $runs,
        private readonly CouponOutboxService $outbox,
        private readonly CouponEventLogService $eventLog,
    ) {}    public function handle(CouponEventEnvelope $envelope): void
    {
        $this->requirePayloadKeys($envelope, ['run_id', 'recipient_id', 'coupon_id']);
        $payload = $envelope->payload;
        $run = CouponDistributionRun::query()->findOrFail($payload['run_id']);
        $recipient = CouponDistributionRecipient::query()->findOrFail($payload['recipient_id']);

        if (in_array($run->status, [CouponDistributionRunStatus::CANCELLED, CouponDistributionRunStatus::COMPLETED, CouponDistributionRunStatus::FAILED], true)) {
            // Terminal drop: stay silent (a disabled coupon must not notify),
            // but release a wedged NOTIFY_PENDING for this tree version so a
            // future run (re-enable + re-run) re-evaluates instead of
            // inheriting a stale in-flight marker as a silent skip.
            $this->releaseNotifyPending(
                (int) ($payload['coupon_id'] ?? 0),
                (int) ($payload['user_id'] ?? $envelope->userId ?? 0),
                (string) ($payload['tree_hash'] ?? $run->tree_hash)
            );

            return;
        }

        $coupon = Coupon::query()->findOrFail($payload['coupon_id']);
        $userId = (int) ($payload['user_id'] ?? $envelope->userId ?? 0);
        $user = $userId > 0 ? User::query()->find($userId) : null;

        if ($user === null) {
            $recipient->update([
                'status' => CouponDistributionRecipientStatus::FAILED_PERMANENT,
                'error' => 'User no longer exists.',
            ]);
            $run->increment('failed_count');
            $this->runs->maybeFinishRun($run, $envelope);

            return;
        }

        $treeHash = (string) ($payload['tree_hash'] ?? $run->tree_hash);

        // Guard 1 (durable, indexed): already notified for this tree version.
        $state = UserStateRow::query()
            ->where('coupon_id', $coupon->getKey())
            ->where('user_id', $user->getKey())
            ->first();

        if ($state !== null && $state->tree_hash === $treeHash && $state->state === CouponDistributionUserState::NOTIFIED) {
            $this->markDuplicate($run, $recipient);
            $this->runs->maybeFinishRun($run, $envelope);

            return;
        }

        // Guard 2 (belt & braces): a coupon.eligible row for this version
        // already exists (e.g. state write lost, message redelivered).
        if ($this->notificationRowExists($user, (int) $coupon->getKey(), $treeHash)) {
            $this->markDuplicate($run, $recipient);
            $this->convergeState($coupon, $user, $run, $recipient, $treeHash);
            $this->runs->maybeFinishRun($run, $envelope);

            return;
        }

        try {
            $user->notify(new UserCouponEligibleNotification($coupon, $run->getKey(), $treeHash));
        } catch (\Throwable $e) {
            $recipient->increment('attempts');
            $recipient->update([
                'status' => CouponDistributionRecipientStatus::FAILED_RETRYABLE,
                'error' => substr($e->getMessage(), 0, 500),
            ]);

            throw $e;
        }

        DB::transaction(function () use ($run, $recipient, $coupon, $user, $treeHash, $envelope) {
            $recipient->update([
                'status' => CouponDistributionRecipientStatus::NOTIFIED,
                'notified_at' => now(),
                'error' => null,
            ]);

            UserStateRow::query()->updateOrCreate(
                ['coupon_id' => $coupon->getKey(), 'user_id' => $user->getKey()],
                [
                    'tree_hash' => $treeHash,
                    'state' => CouponDistributionUserState::NOTIFIED,
                    'last_run_id' => $run->getKey(),
                    'last_recipient_id' => $recipient->getKey(),
                    'last_evaluated_at' => now(),
                    'notified_at' => now(),
                ]
            );

            $run->increment('notified_count');

            $sent = $envelope->derive(
                CouponDistributionEvents::NOTIFICATION_SENT,
                ['run_id' => $run->getKey(), 'recipient_id' => $recipient->getKey()],
                $user->getKey()
            );
            $this->outbox->record($sent);
            $this->eventLog->recordPublished($sent);
        });

        $this->runs->maybeFinishRun($run->fresh(), $envelope);

        Log::info('coupon.notification.sent', [
            'run_id' => $run->getKey(),
            'coupon_id' => $coupon->getKey(),
            'user_id' => $user->getKey(),
        ]);
    }

    private function markDuplicate(CouponDistributionRun $run, CouponDistributionRecipient $recipient): void
    {
        if ($recipient->status !== CouponDistributionRecipientStatus::DUPLICATE_SKIPPED) {
            $recipient->update(['status' => CouponDistributionRecipientStatus::DUPLICATE_SKIPPED]);
            $run->increment('duplicate_skipped_count');
        }
    }

    /**
     * Release a wedged NOTIFY_PENDING marker back to ELIGIBLE without
     * notifying. Used when the run died terminally mid-flight and when DLQ
     * exhaustion drops the request: the next run re-evaluates the user
     * instead of silently skipping them.
     */
    private function releaseNotifyPending(int $couponId, int $userId, string $treeHash): void
    {
        if ($couponId <= 0 || $userId <= 0 || $treeHash === '') {
            return;
        }

        try {
            UserStateRow::query()
                ->where('coupon_id', $couponId)
                ->where('user_id', $userId)
                ->where('tree_hash', $treeHash)
                ->where('state', CouponDistributionUserState::NOTIFY_PENDING->value)
                ->update([
                    'state' => CouponDistributionUserState::ELIGIBLE->value,
                    'updated_at' => now(),
                ]);
        } catch (\Throwable) {
            // Release is convergence hygiene — it must never break the drop path.
        }
    }

    private function convergeState(Coupon $coupon, User $user, CouponDistributionRun $run, CouponDistributionRecipient $recipient, string $treeHash): void
    {
        CouponDistributionUserState::query()->updateOrCreate(
            ['coupon_id' => $coupon->getKey(), 'user_id' => $user->getKey()],
            [
                'tree_hash' => $treeHash,
                'state' => CouponDistributionUserState::NOTIFIED,
                'last_run_id' => $run->getKey(),
                'last_recipient_id' => $recipient->getKey(),
                'notified_at' => now(),
            ]
        );

        // The durable row proves delivery happened (this attempt or an
        // earlier one whose state write was lost) — count it once.
        if ($recipient->status !== CouponDistributionRecipientStatus::NOTIFIED) {
            $run->increment('notified_count');
        }
    }

    private function notificationRowExists(User $user, int $couponId, string $treeHash): bool
    {
        // data is TEXT: filter the small per-user coupon.eligible set in PHP
        // instead of relying on engine-specific JSON operators.
        $rows = $user->notifications()
            ->where('type', 'coupon.eligible')
            ->orderByDesc('created_at')
            ->limit(100)
            ->get(['data']);

        foreach ($rows as $row) {
            $data = is_string($row->data) ? json_decode($row->data, true) : $row->data;

            if (is_array($data)
                && (int) ($data['coupon_id'] ?? 0) === $couponId
                && ($data['tree_hash'] ?? null) === $treeHash
            ) {
                return true;
            }
        }

        return false;
    }
}
