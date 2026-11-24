<?php

declare(strict_types=1);

namespace App\Services\Shipping;

use App\Enums\Shipping\DeliveryAttemptStatus;
use DomainException;

/**
 * #161: validates the canonical #124/#164 delivery-attempt taxonomy.
 * Naming is deliberately validator-specific so it cannot collide with the
 * canonical DeliveryAttemptStatus enum added by #164.
 */
final class DeliveryAttemptOutcomeValidator
{
    /**
     * @param array<string,mixed> $input
     * @return array{status:string,failure_reason:?string}
     */
    public function normalize(array $input): array
    {
        $status = is_string($input['status'] ?? null) ? trim($input['status']) : '';
        $outcome = DeliveryAttemptStatus::tryFrom($status);
        if ($outcome === null) {
            throw new DomainException('Invalid delivery attempt outcome.');
        }

        $reason = is_string($input['failure_reason'] ?? null) ? trim($input['failure_reason']) : null;
        if ($reason === '') {
            $reason = null;
        }

        if ($outcome->requiresReason() && $reason === null) {
            throw new DomainException('Delivery attempt outcome "other" requires a reason.');
        }

        return [
            'status' => $outcome->value,
            'failure_reason' => $outcome === DeliveryAttemptStatus::DELIVERED ? null : $reason,
        ];
    }
}
