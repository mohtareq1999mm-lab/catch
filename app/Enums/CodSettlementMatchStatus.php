<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * #156: lifecycle state of a settlement line's order linkage.
 */
enum CodSettlementMatchStatus: string
{
    case MATCHED = 'matched';
    case UNMATCHED = 'unmatched';
}
