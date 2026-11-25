<?php

declare(strict_types=1);

namespace App\DTOs\Settlement;

use App\Models\Settlement\SettlementImportFile;

/**
 * #185: outcome of one hash-idempotent private settlement file import.
 *
 * A duplicate import returns the original file with zero parsed lines; no
 * statement line is ever emitted twice.
 */
final class SettlementImportResult
{
    public function __construct(
        public readonly SettlementImportFile $file,
        public readonly bool $duplicate,
        public readonly SettlementParseResult $parse,
    ) {}
}
