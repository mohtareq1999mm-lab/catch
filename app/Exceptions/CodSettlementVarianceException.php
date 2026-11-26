<?php

declare(strict_types=1);

namespace App\Exceptions;

use DomainException;

/**
 * #191: closure is blocked by any variance beyond the approved per-currency
 * threshold. No tolerance is invented by the service itself.
 */
class CodSettlementVarianceException extends DomainException
{
    public static function exceedsThreshold(int $varianceMinor, int $toleranceMinor, string $currencyCode): self
    {
        return new self(__('cod_settlement.closure_variance_blocked', [
            'variance' => $varianceMinor,
            'tolerance' => $toleranceMinor,
            'currency' => $currencyCode,
        ]));
    }

    public static function approvalReferenceRequired(): self
    {
        return new self(__('cod_settlement.closure_approval_reference_required'));
    }
}
