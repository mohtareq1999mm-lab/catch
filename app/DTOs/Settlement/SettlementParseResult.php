<?php

declare(strict_types=1);

namespace App\DTOs\Settlement;

/**
 * #185: parsed evidence plus retained malformed rows.
 *
 * Parsed lines are always pending finance review; they carry no acceptance,
 * payment, or lifecycle outcome.
 */
final class SettlementParseResult
{
    /**
     * @param list<array{order_id: int, expected: string, received: string, source_row: int}> $lines
     * @param list<array{row: int, reason: string, raw: string}> $malformedRows
     */
    public function __construct(
        public readonly array $lines,
        public readonly array $malformedRows,
    ) {}
}
