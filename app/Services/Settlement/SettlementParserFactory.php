<?php

declare(strict_types=1);

namespace App\Services\Settlement;

use App\Contracts\Settlement\SettlementStatementParser;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/** #185: resolves provider adapters from deployment-backed configuration. */
final class SettlementParserFactory
{
    public function __construct(private Container $container) {}

    public function forProvider(string $providerCode): SettlementStatementParser
    {
        $parsers = config('settlement.imports.parsers', []);
        $class = is_array($parsers) ? ($parsers[$providerCode] ?? $parsers['default'] ?? null) : null;

        if (!is_string($class) || !is_a($class, SettlementStatementParser::class, true)) {
            throw new InvalidArgumentException(__('cod_settlement.parser_unavailable'));
        }

        $parser = $this->container->make($class);
        if (!$parser instanceof SettlementStatementParser) {
            throw new InvalidArgumentException(__('cod_settlement.parser_unavailable'));
        }

        return $parser;
    }
}
