<?php

declare(strict_types=1);

namespace App\Services\Shipment;

use App\Enums\Shipping\NotificationDeliveryResult;
use App\Models\Shipping\ShipmentNotification;
use App\Models\Shipping\ShipmentNotificationHistory;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Bounded shipment-notification attempts with conservative outcome handling.
 *
 * This service owns attempt accounting, backoff and history only. The actual
 * channel adapter is supplied by the caller as a callback returning a
 * NotificationDeliveryResult; the queue/Laravel retry mechanism remains the
 * caller's concern. No provider-specific dispatch is invented here.
 */
class ShipmentNotificationRetryService
{
    public const MAX_ATTEMPTS = 3;

    public const BASE_BACKOFF_SECONDS = 60;

    private const ABSOLUTE_MAX_ATTEMPTS = 10;

    /**
     * @param  callable(ShipmentNotification): NotificationDeliveryResult  $dispatch
     */
    public function attempt(ShipmentNotification $notification, callable $dispatch): ShipmentNotification
    {
        $maxAttempts = $this->maxAttemptsFor($notification);
        if (!$this->claimAttempt($notification, $maxAttempts, now())) {
            return $notification->fresh() ?? $notification;
        }

        $current = $notification->fresh() ?? $notification;
        ShipmentNotificationHistory::appendFor($current, 'attempted', (int) $current->attempts);
        $this->resolveResult($current, $dispatch, $maxAttempts);

        return $current->fresh() ?? $current;
    }

    /**
     * Process rows whose backoff has elapsed, oldest due row first.
     *
     * @param  callable(ShipmentNotification): NotificationDeliveryResult  $dispatch
     */
    public function processDue(callable $dispatch, int $limit = 100): int
    {
        if ($limit < 1 || $limit > 500) {
            throw new InvalidArgumentException('Limit must be between 1 and 500.');
        }

        $due = ShipmentNotification::query()
            ->where('status', 'pending')
            // Mirrors maxAttemptsFor(): out-of-range values must not select a
            // row that claimAttempt() will always reject.
            ->whereRaw(
                'shipment_notifications.attempts < '.self::clampedMaxAttemptsSql(),
                self::clampedMaxAttemptsBindings()
            )
            ->where(function ($query) {
                $query->whereNull('next_retry_at')
                    ->orWhere('next_retry_at', '<=', now());
            })
            ->orderBy('next_retry_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $processed = 0;
        foreach ($due as $notification) {
            $after = $this->attempt($notification, $dispatch);
            if ((int) $after->attempts > (int) $notification->attempts) {
                $processed++;
            }
        }

        return $processed;
    }

    /** Atomically consume one attempt unless backoff or a terminal state blocks it. */
    private function claimAttempt(ShipmentNotification $notification, int $maxAttempts, Carbon $now): bool
    {
        $claimed = ShipmentNotification::query()
            ->whereKey($notification->id)
            ->where('status', 'pending')
            ->where('attempts', '<', $maxAttempts)
            ->where(function ($query) use ($now) {
                $query->whereNull('next_retry_at')
                    ->orWhere('next_retry_at', '<=', $now);
            })
            ->update([
                'attempts' => DB::raw('attempts + 1'),
                'next_retry_at' => $now->copy()->addSeconds($this->backoffSeconds((int) $notification->attempts + 1)),
                'updated_at' => $now,
            ]);

        return $claimed === 1;
    }

    /**
     * @param  callable(ShipmentNotification): NotificationDeliveryResult  $dispatch
     */
    private function resolveResult(
        ShipmentNotification $notification,
        callable $dispatch,
        int $maxAttempts,
    ): void {
        $detail = null;
        try {
            $result = $dispatch($notification);
        } catch (\Throwable $e) {
            // An adapter exception does not prove the message was not sent.
            $result = NotificationDeliveryResult::AMBIGUOUS;
            $detail = 'dispatcher_exception:' . get_class($e);
        }

        if (!$result instanceof NotificationDeliveryResult) {
            // Unknown outcomes are ambiguous, never "safe to retry".
            $result = NotificationDeliveryResult::AMBIGUOUS;
            $detail = 'invalid_dispatch_result';
        }

        match ($result) {
            NotificationDeliveryResult::DELIVERED => $this->markDelivered($notification),
            NotificationDeliveryResult::FAILED => $this->markFailed($notification, $maxAttempts, $detail ?? 'delivery_failed'),
            NotificationDeliveryResult::AMBIGUOUS => $this->markAmbiguous($notification, $detail ?? 'ambiguous_delivery_result'),
        };
    }

    /** Exponential backoff: 60s, 120s, 240s ... measured from the attempt. */
    public function backoffSeconds(int $attempt): int
    {
        return self::BASE_BACKOFF_SECONDS * (2 ** max(0, $attempt - 1));
    }

    private function maxAttemptsFor(ShipmentNotification $notification): int
    {
        return min(
            self::ABSOLUTE_MAX_ATTEMPTS,
            max(1, (int) ($notification->max_attempts ?? self::MAX_ATTEMPTS))
        );
    }

    /** Portable SQL equivalent of maxAttemptsFor() for due-row selection. */
    private static function clampedMaxAttemptsSql(): string
    {
        return '(CASE'
            .' WHEN max_attempts IS NULL THEN ?'
            .' WHEN max_attempts < 1 THEN ?'
            .' WHEN max_attempts > ? THEN ?'
            .' ELSE max_attempts END)';
    }

    /** @return list<int> */
    private static function clampedMaxAttemptsBindings(): array
    {
        return [self::MAX_ATTEMPTS, 1, self::ABSOLUTE_MAX_ATTEMPTS, self::ABSOLUTE_MAX_ATTEMPTS];
    }

    private function markDelivered(ShipmentNotification $notification): void
    {
        $notification->forceFill([
            'status' => 'sent',
            'sent_at' => now(),
            'next_retry_at' => null,
            'last_error' => null,
        ])->save();

        ShipmentNotificationHistory::appendFor($notification, 'sent', (int) $notification->attempts);
    }

    private function markFailed(ShipmentNotification $notification, int $maxAttempts, string $reason): void
    {
        $exhausted = (int) $notification->attempts >= $maxAttempts;
        $notification->forceFill([
            'status' => $exhausted ? 'exhausted' : 'pending',
            'next_retry_at' => $exhausted ? null : $notification->next_retry_at,
            'failed_at' => $exhausted ? now() : null,
            'last_error' => $reason,
        ])->save();

        ShipmentNotificationHistory::appendFor(
            $notification,
            $exhausted ? 'exhausted' : 'failed',
            (int) $notification->attempts,
            $reason
        );

        if ($exhausted) {
            Log::error('shipment_notification.retry_exhausted', $this->alertContext($notification, $reason));
        }
    }

    private function markAmbiguous(ShipmentNotification $notification, string $reason): void
    {
        $notification->forceFill([
            'status' => 'ambiguous',
            'next_retry_at' => null,
            'failed_at' => now(),
            'last_error' => $reason,
        ])->save();

        ShipmentNotificationHistory::appendFor(
            $notification,
            'ambiguous',
            (int) $notification->attempts,
            $reason
        );

        Log::warning('shipment_notification.ambiguous_delivery', $this->alertContext($notification, $reason));
    }

    /** @return array<string,mixed> Alert context without recipient PII. */
    private function alertContext(ShipmentNotification $notification, string $reason): array
    {
        return [
            'notification_id' => (int) $notification->id,
            'timeline_event_id' => (int) $notification->timeline_event_id,
            'channel' => (string) $notification->channel,
            'source' => (string) ($notification->source ?: 'direct'),
            'attempts' => (int) $notification->attempts,
            'reason' => $reason,
        ];
    }
}
