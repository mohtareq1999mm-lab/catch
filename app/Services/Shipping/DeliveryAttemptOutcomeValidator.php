<?php

declare(strict_types=1);

namespace App\Services\Shipping;

use App\Enums\Shipping\DeliveryAttemptStatus;
use DomainException;

/**
 * #161: thin adapter over the canonical #164 taxonomy normalizer.
 *
 * `DeliveryAttemptStatus::normalizeAttempt()` is the single source of truth for
 * accepted outcomes, reason requirements, and `delivered` reason stripping.
 * This adapter keeps the validator's exception-based contract for the webhook
 * taxonomy path instead of duplicating the rules.
 */
final class DeliveryAttemptOutcomeValidator
{
    /**
     * @param array<string,mixed> $input
     * @return array{status:string,failure_reason:?string}
     */
    public function normalize(array $input): array
    {
        $result = DeliveryAttemptStatus::normalizeAttempt($input);

        if ($result['error'] === 'reason_required') {
            throw new DomainException('Delivery attempt outcome "other" requires a reason.');
        }

        if ($result['error'] !== null || $result['status'] === null) {
            throw new DomainException('Invalid delivery attempt outcome.');
        }

        return [
            'status' => $result['status'],
            'failure_reason' => $result['failure_reason'],
        ];
    }
}
