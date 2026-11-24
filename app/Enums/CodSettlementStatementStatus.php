<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * #156: lifecycle state of a COD settlement evidence header.
 */
enum CodSettlementStatementStatus: string
{
    case OPEN = 'open';
    case CLOSED = 'closed';
}
