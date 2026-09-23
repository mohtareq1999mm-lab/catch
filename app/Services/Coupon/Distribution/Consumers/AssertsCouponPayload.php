<?php

namespace App\Services\Coupon\Distribution\Consumers;

use App\Services\Coupon\Distribution\Events\CouponEventEnvelope;

/**
 * Shared consumer preconditions: required payload keys with strict types.
 * Violations are poison (permanent, never retryable) → dead-letter.
 */
trait AssertsCouponPayload
{
    /**
     * @param  list<string>  $keys
     *
     * @throws PoisonMessageException
     */
    protected function requirePayloadKeys(CouponEventEnvelope $envelope, array $keys): void
    {
        foreach ($keys as $key) {
            if (! array_key_exists($key, $envelope->payload)) {
                throw new PoisonMessageException(
                    "Event [{$envelope->eventId}] type [{$envelope->eventType}] missing required payload key [{$key}]."
                );
            }
        }
    }

    /**
     * @throws PoisonMessageException
     */
    protected function requirePositiveInt(CouponEventEnvelope $envelope, string $key): int
    {
        $value = $envelope->payload[$key] ?? null;

        if (! is_int($value) && !(is_string($value) && ctype_digit((string) $value))) {
            throw new PoisonMessageException(
                "Event [{$envelope->eventId}] payload key [{$key}] must be a positive integer."
            );
        }

        $int = (int) $value;

        if ($int <= 0) {
            throw new PoisonMessageException(
                "Event [{$envelope->eventId}] payload key [{$key}] must be a positive integer."
            );
        }

        return $int;
    }
}
