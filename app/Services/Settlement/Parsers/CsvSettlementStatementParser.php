<?php

declare(strict_types=1);

namespace App\Services\Settlement\Parsers;

use App\Contracts\Settlement\SettlementStatementParser;
use App\DTOs\Settlement\SettlementParseResult;

/**
 * #185: generic CSV evidence adapter. Provider-specific adapters implement the
 * same contract; none of them is a financial authority.
 */
final class CsvSettlementStatementParser implements SettlementStatementParser
{
    private const HEADER = ['order_id', 'expected', 'received'];
    private const REASON_COLUMN_COUNT = 'column_count';
    private const REASON_ORDER_ID = 'order_id';
    private const REASON_AMOUNT = 'amount';

    public function supports(string $contents): bool
    {
        return $this->header($this->lines($contents)) === self::HEADER;
    }

    public function parse(string $contents): SettlementParseResult
    {
        $lines = $this->lines($contents);
        if ($this->header($lines) !== self::HEADER) {
            throw new \InvalidArgumentException(__('cod_settlement.invalid_content'));
        }

        $rows = [];
        $malformed = [];

        foreach ($lines as $index => $raw) {
            if ($index === 0 || trim($raw) === '') {
                continue;
            }

            $cells = str_getcsv($raw);
            if (count($cells) !== count(self::HEADER)) {
                $malformed[] = ['row' => $index + 1, 'reason' => self::REASON_COLUMN_COUNT, 'raw' => $raw];

                continue;
            }

            $orderId = trim((string) $cells[0]);
            $expected = trim((string) $cells[1]);
            $received = trim((string) $cells[2]);

            if (!ctype_digit($orderId) || (int) $orderId <= 0) {
                $malformed[] = ['row' => $index + 1, 'reason' => self::REASON_ORDER_ID, 'raw' => $raw];

                continue;
            }

            if (!$this->isAmount($expected) || !$this->isAmount($received)) {
                $malformed[] = ['row' => $index + 1, 'reason' => self::REASON_AMOUNT, 'raw' => $raw];

                continue;
            }

            $rows[] = [
                'order_id' => (int) $orderId,
                'expected' => $expected,
                'received' => $received,
                'source_row' => $index + 1,
            ];
        }

        return new SettlementParseResult($rows, $malformed);
    }

    public function version(): string
    {
        return 'csv-v1';
    }

    /**
     * @return list<string>
     */
    private function lines(string $contents): array
    {
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;

        return preg_split('/\r\n|\n|\r/', $contents) ?: [];
    }

    /**
     * @param list<string> $lines
     * @return list<string>|null
     */
    private function header(array $lines): ?array
    {
        if ($lines === []) {
            return null;
        }

        $header = str_getcsv($lines[0]);

        return array_map(static fn ($cell): string => strtolower(trim((string) $cell)), $header);
    }

    private function isAmount(string $value): bool
    {
        return preg_match('/^\d+(?:\.\d+)?$/', $value) === 1;
    }
}
