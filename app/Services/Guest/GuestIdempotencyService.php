<?php

namespace App\Services\Guest;

use App\Exceptions\GuestCheckoutConflictException;
use App\Models\GuestIdempotencyKey;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Durable idempotency for guest checkout / payment initiation.
 *
 * Claim-then-complete pattern:
 *  1. INSERT the claim — UNIQUE(guest,scope,key) makes exactly one request
 *     the owner; everyone else reads the winner's row.
 *  2. completed  -> replay the stored response (no second order/session).
 *     processing -> 409 conflict (protects double-click / concurrent tabs).
 *     failed     -> a new attempt may re-take the claim.
 * A stalled processing claim is re-takeable after 10 minutes (crash safety)
 * only when the stored request hash matches.
 */
class GuestIdempotencyService
{
    /**
     * @return array{replay: bool, response: array|null, claim: GuestIdempotencyKey|null}
     *
     * @throws GuestCheckoutConflictException when the operation is in flight
     *         or the same key is reused for a different payload.
     */
    public function claim(int $guestId, string $scope, string $key, array $payload): array
    {
        $key = substr(trim($key), 0, 191);
        $requestHash = hash('sha256', json_encode($this->canonical($payload)));

        try {
            $claim = GuestIdempotencyKey::create([
                'guest_id' => $guestId,
                'scope' => $scope,
                'idempotency_key' => $key,
                'request_hash' => $requestHash,
                'status' => GuestIdempotencyKey::STATUS_PROCESSING,
                'claimed_at' => now(),
            ]);

            return ['replay' => false, 'response' => null, 'claim' => $claim];
        } catch (QueryException $e) {
            if (!$this->isDuplicateKeyError($e)) {
                throw $e;
            }
        }

        /** @var GuestIdempotencyKey|null $existing */
        $existing = GuestIdempotencyKey::query()
            ->where('guest_id', $guestId)
            ->where('scope', $scope)
            ->where('idempotency_key', $key)
            ->first();

        if (!$existing) {
            throw new GuestCheckoutConflictException(__('guest_checkout.conflict_retry'));
        }

        if (!hash_equals($existing->request_hash, $requestHash)) {
            throw new GuestCheckoutConflictException(__('guest_checkout.idempotency_mismatch'), 422);
        }

        if ($existing->isCompleted()) {
            return ['replay' => true, 'response' => $existing->response ?? [], 'claim' => $existing];
        }

        if ($existing->status === GuestIdempotencyKey::STATUS_PROCESSING && !$existing->isStale()) {
            throw new GuestCheckoutConflictException(__('guest_checkout.checkout_in_progress'), 409);
        }

        // A handled failure is explicitly retryable with the SAME key, and a
        // stalled processing claim is re-takeable after the stale window.
        // The conditional update is the concurrency arbiter.
        $reclaim = GuestIdempotencyKey::query()
            ->whereKey($existing->id)
            ->where('request_hash', $requestHash);

        if ($existing->status === GuestIdempotencyKey::STATUS_FAILED) {
            $reclaim->where('status', GuestIdempotencyKey::STATUS_FAILED);
        } else {
            $reclaim->where('status', GuestIdempotencyKey::STATUS_PROCESSING)
                ->where('claimed_at', $existing->claimed_at);
        }

        $reclaimed = $reclaim->update([
            'claimed_at' => now(),
            'status' => GuestIdempotencyKey::STATUS_PROCESSING,
            'completed_at' => null,
            'order_id' => null,
            'response' => null,
        ]);

        if ($reclaimed !== 1) {
            throw new GuestCheckoutConflictException(__('guest_checkout.checkout_in_progress'), 409);
        }

        return [
            'replay' => false,
            'response' => null,
            'claim' => $existing->refresh(),
        ];
    }

    public function complete(GuestIdempotencyKey $claim, array $response, ?int $orderId = null): void
    {
        // Conditional on the exact claim epoch: a stale owner must not
        // overwrite a claim that a retry has re-taken.
        GuestIdempotencyKey::query()
            ->whereKey($claim->id)
            ->where('status', GuestIdempotencyKey::STATUS_PROCESSING)
            ->where('claimed_at', $claim->claimed_at)
            ->update([
                'status' => GuestIdempotencyKey::STATUS_COMPLETED,
                'response' => json_encode($response),
                'order_id' => $orderId ?? $claim->order_id,
                'completed_at' => now(),
                'updated_at' => now(),
            ]);
    }

    public function fail(GuestIdempotencyKey $claim, string $reason): void
    {
        // Failure is explicit so a client retry with the same key can proceed;
        // never leave a permanent processing tombstone after a handled error.
        DB::table($claim->getTable())
            ->where('id', $claim->id)
            ->where('status', GuestIdempotencyKey::STATUS_PROCESSING)
            ->where('claimed_at', $claim->claimed_at)
            ->update([
                'status' => GuestIdempotencyKey::STATUS_FAILED,
                'response' => json_encode(['error' => mb_substr($reason, 0, 500)]),
                'completed_at' => now(),
                'updated_at' => now(),
            ]);
    }

    /** Stable ordering so equivalent payloads hash identically. */
    private function canonical(array $payload): array
    {
        $normalize = function ($value) use (&$normalize) {
            if (is_array($value)) {
                $isList = array_is_list($value);
                $value = array_map($normalize, $value);
                if (!$isList) {
                    ksort($value);
                }
                return $value;
            }
            if (is_float($value)) {
                return number_format($value, 6, '.', '');
            }
            return $value;
        };

        $value = $normalize($payload);

        return is_array($value) ? $value : [];
    }

    private function isDuplicateKeyError(QueryException $e): bool
    {
        $errorInfo = $e->errorInfo ?? [];

        return ($errorInfo[1] ?? null) === 1062
            || $e->getCode() === '23000'
            || $e->getCode() === '23505'
            || (string) $e->getCode() === '1062';
    }
}
