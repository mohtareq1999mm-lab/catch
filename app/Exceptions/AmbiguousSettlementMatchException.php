<?php

declare(strict_types=1);

namespace App\Exceptions;

use DomainException;

/**
 * #191: order-number matching is never guessed when more than one order fits
 * the settlement scope. Resolution requires an audited manual match.
 */
class AmbiguousSettlementMatchException extends DomainException
{
    /**
     * @param array<int, int> $candidateIds
     */
    public static function forOrderNumber(string $orderNumber, array $candidateIds): self
    {
        return new self(__('cod_settlement.ambiguous_order_match', [
            'order_number' => $orderNumber,
            'candidates' => implode(', ', $candidateIds),
        ]));
    }
}
