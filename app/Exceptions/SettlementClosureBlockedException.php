<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * #182: closure is blocked until finance resolves the accepted set.
 *
 * This is an operational block, never a settlement mutation: the caller must
 * not close, adjust, or mark anything paid when this exception is raised.
 */
final class SettlementClosureBlockedException extends RuntimeException
{
    /** @param list<int> $statementIds */
    public static function ambiguous(array $statementIds): self
    {
        return new self(__('cod_settlement.closure_ambiguous', [
            'ids' => implode(', ', array_map('strval', $statementIds)),
        ]));
    }

    public static function noAcceptedStatement(): self
    {
        return new self(__('cod_settlement.closure_no_accepted'));
    }
}
