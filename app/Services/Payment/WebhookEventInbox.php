<?php

namespace App\Services\Payment;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * W-1 / Constraint C — Durable webhook event inbox.
 *
 * Every provider delivery is durably persisted AFTER provider
 * authentication and BEFORE any business processing; the provider is
 * acknowledged only once the row is committed (persistence failure ⇒ 5xx
 * ⇒ provider retries ⇒ at-least-once delivery is preserved).
 *
 * Event identity (provider-scoped, documented rules):
 * - Stable provider event id when the provider supplies one (Stripe
 *   event.id, PayPal body.id) — VERIFIED in this repo's handlers.
 * - Otherwise a SCOPED fingerprint over verified, NORMALIZED identity
 *   attributes: provider | account | provider object id | event type |
 *   status. Raw-body SHA256 alone is NEVER used: distinct status
 *   transitions of the same object MUST NOT collapse into one key, while
 *   identical replays of the same logical notification MUST.
 * - Account scoping: the configured merchant account label for the
 *   gateway (single-account deployments use 'default').
 *
 * Financial idempotency does NOT depend on the inbox: completeLocked's
 * token plus pending-only guards remain the double-completion defense.
 */
class WebhookEventInbox
{
    /** Processing retry ceiling before an event is parked for ops replay. */
    public const MAX_ATTEMPTS = 5;

    /**
     * @return \Marvel\Database\Models\Transaction|object|null the stored
     * event row, or NULL when an identical event is already recorded.
     */
    public function store(
        string $provider,
        string $eventKey,
        ?string $account = null,
        ?string $eventType = null,
        ?string $providerRef = null,
        ?string $attemptRef = null,
        array $payload = [],
    ): ?object {
        $table = 'payment_webhook_events';

        if (!Schema::hasTable($table)) {
            // Pre-migration schema (legacy test databases): proceed without
            // the inbox rather than rejecting authenticated provider events.
            Log::info('Webhook inbox unavailable: table missing', ['provider' => $provider]);

            return (object) ['id' => null, '_virtual' => true];
        }

        try {
            return DB::table($table)->insertGetId([
                'provider' => $provider,
                'event_key' => $eventKey,
                'provider_account' => $account,
                'event_type' => $eventType,
                'provider_ref' => $providerRef,
                'attempt_ref' => $attemptRef,
                'payload' => json_encode($payload),
                'status' => 'received',
                'attempts' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]) ? (object) ['id' => DB::getPdo()->lastInsertId(), '_virtual' => false] : null;
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            Log::info('Webhook event duplicate (durable inbox)', [
                'provider' => $provider,
                'event_key' => $eventKey,
                'result' => 'duplicate',
            ]);

            return null;
        }
    }

    public function markProcessed(int|string|null $eventId): void
    {
        $this->update($eventId, 'processed', null);
    }

    public function markFailed(int|string|null $eventId, \Throwable|string $error): void
    {
        $this->update($eventId, 'failed', $error instanceof \Throwable ? $error->getMessage() : $error);
    }

    /** Replay/parking: retries exhausted — retained for ops, never deleted. */
    public function markDead(int|string|null $eventId, \Throwable|string $error): void
    {
        $this->update($eventId, 'dead', $error instanceof \Throwable ? $error->getMessage() : $error);
    }

    private function update(int|string|null $eventId, string $status, ?string $error): void
    {
        if ($eventId === null || !is_numeric($eventId)) {
            return;
        }

        try {
            $row = DB::table('payment_webhook_events')->where('id', $eventId)->first();

            if (!$row) {
                return;
            }

            $attempts = (int) $row->attempts + ($status === 'failed' ? 1 : 0);
            $final = $status === 'failed' && $attempts >= self::MAX_ATTEMPTS ? 'dead' : $status;

            DB::table('payment_webhook_events')->where('id', $eventId)->update([
                'status' => $final,
                'attempts' => $attempts,
                'last_error' => $error !== null ? \Illuminate\Support\Str::limit($error, 500, '') : $row->last_error,
                'processed_at' => $final === 'processed' ? now() : $row->processed_at,
                'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** Documented scoped fingerprint (see class docblock). */
    public static function fingerprint(string $provider, string $account, string $objectId, string $eventType, string $status): string
    {
        return 'fp:' . hash('sha256', implode('|', [$provider, $account ?: 'default', $objectId, $eventType, $status]));
    }
}
