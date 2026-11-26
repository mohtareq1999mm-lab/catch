<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * #179: lineage kind of a COD settlement evidence statement.
 */
enum CodStatementType: string
{
    case ORIGINAL = 'original';
    case CORRECTED = 'corrected';
    case SUPPLEMENTARY = 'supplementary';
}
