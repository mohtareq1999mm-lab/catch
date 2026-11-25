<?php

declare(strict_types=1);

namespace App\Contracts\Settlement;

use App\DTOs\Settlement\SettlementParseResult;

/**
 * #185: provider-specific statement adapters share this read-only contract.
 *
 * Implementations only shape evidence rows. They never accept, reconcile,
 * close, or otherwise mutate financial state.
 */
interface SettlementStatementParser
{
    /** Whether the content has the expected statement shape before parsing. */
    public function supports(string $contents): bool;

    public function parse(string $contents): SettlementParseResult;

    /** Stable adapter/version identity persisted with the original file. */
    public function version(): string;
}
