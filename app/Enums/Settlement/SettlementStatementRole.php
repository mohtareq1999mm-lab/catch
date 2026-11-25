<?php

declare(strict_types=1);

namespace App\Enums\Settlement;

/**
 * #129: a correction replaces an accepted statement after finance accepts it;
 * a supplementary statement only adds evidence and never replaces the original.
 */
enum SettlementStatementRole: string
{
    case CORRECTION = 'correction';
    case SUPPLEMENTARY = 'supplementary';
}
