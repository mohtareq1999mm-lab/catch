<?php

namespace App\Services\Settlement;

use App\Enums\SettlementMode;
use App\Exceptions\CodSettlementPrecisionException;
use App\Models\Currency;
use App\Models\Settlement\CodSettlementStatement;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Marvel\Database\Models\Order;

/**
 * #135: COD settlement bookkeeping.
 *
 * Reads orders only to establish currency identity. It writes settlement
 * evidence rows and nothing else: no payment, order, shipment, fulfillment,
 * inventory mutation, lifecycle event, queued job, rate lookup, fee rule,
 * tax rule, or currency conversion.
 */
class CodSettlementService
{
    public function createStatement(array $payload): CodSettlementStatement
    {
        $identity = $this->validateIdentity($payload);
        $lines = $this->resolveLines($this->normalizeLines($payload['lines'] ?? null), $identity);

        return DB::transaction(fn (): CodSettlementStatement => $this->persistStatement(
            $identity,
            $lines,
            $this->statementTotals($lines)
        ));
    }

    public function findStatement(int $id): CodSettlementStatement
    {
        return CodSettlementStatement::query()->with('lines')->findOrFail($id);
    }

    public function paginateStatements(array $filters = []): LengthAwarePaginator
    {
        $query = CodSettlementStatement::query()->with('lines')->orderByDesc('id');

        foreach (['provider_code', 'account_code', 'currency_code'] as $field) {
            $value = $filters[$field] ?? null;
            if ($value !== null && $value !== '') {
                $value = $this->stringField($value, $field, 64);
                $query->where($field, $field === 'currency_code' ? strtoupper($value) : $value);
            }
        }

        $mode = $filters['mode'] ?? null;
        if ($mode !== null && $mode !== '') {
            $mode = $mode instanceof SettlementMode ? $mode : SettlementMode::tryFrom((string) $mode);
            if ($mode === null) {
                throw $this->invalidPayload('mode');
            }
            $query->where('mode', $mode->value);
        }

        $periodStart = $filters['period_start'] ?? null;
        if ($periodStart !== null && $periodStart !== '') {
            $query->where('period_start', '>=', $this->dateField($periodStart, 'period_start'));
        }

        $periodEnd = $filters['period_end'] ?? null;
        if ($periodEnd !== null && $periodEnd !== '') {
            $query->where('period_end', '<=', $this->dateField($periodEnd, 'period_end'));
        }

        return $query->paginate(max(1, min((int) ($filters['limit'] ?? 15), 100)));
    }

    private function persistStatement(array $identity, array $lines, array $totals): CodSettlementStatement
    {
        $statement = CodSettlementStatement::create($identity + $totals);

        foreach ($lines as $line) {
            $statement->lines()->create($line);
        }

        return $statement->load('lines');
    }

    private function resolveLines(array $normalizedLines, array $identity): array
    {
        $orders = Order::query()
            ->whereIn('id', array_values(array_unique(array_column($normalizedLines, 'order_id'))))
            ->get()
            ->keyBy('id');

        $currencyCode = null;
        $decimalPlaces = null;
        $lineIdentity = array_intersect_key($identity, array_flip([
            'provider_code', 'mode', 'account_code', 'period_start', 'period_end',
        ]));
        $resolved = [];

        foreach ($normalizedLines as $line) {
            [$currencyCode, $decimalPlaces] = $this->linePrecision($line, $orders, $currencyCode, $decimalPlaces);
            $resolved[] = $this->resolveLine($line, $lineIdentity, $currencyCode, $decimalPlaces);
        }

        return $resolved;
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function linePrecision(array $line, Collection $orders, ?string $currencyCode, ?int $decimalPlaces): array
    {
        $order = $orders->get($line['order_id']);
        if ($order === null) {
            throw $this->invalidPayload('order_id');
        }

        $lineCurrency = strtoupper(trim((string) $order->currency_code));
        if ($lineCurrency === '') {
            throw CodSettlementPrecisionException::forCurrency();
        }

        if ($currencyCode !== null && $lineCurrency !== $currencyCode) {
            throw CodSettlementPrecisionException::forMixedCurrency();
        }

        $lineDecimalPlaces = $this->decimalPlacesFor($lineCurrency);
        if ($decimalPlaces !== null && $lineDecimalPlaces !== $decimalPlaces) {
            throw CodSettlementPrecisionException::forMixedCurrency();
        }

        return [$lineCurrency, $lineDecimalPlaces];
    }

    private function resolveLine(
        array $line,
        array $lineIdentity,
        string $currencyCode,
        int $decimalPlaces
    ): array {
        $expectedMinor = $this->toMinorUnits($line['expected'], $decimalPlaces);
        $receivedMinor = $this->toMinorUnits($line['received'], $decimalPlaces);

        return $lineIdentity + [
            'line_key' => $line['line_key'],
            'order_id' => $line['order_id'],
            'currency_code' => $currencyCode,
            'decimal_places' => $decimalPlaces,
            'expected_minor' => $expectedMinor,
            'received_minor' => $receivedMinor,
            'variance_minor' => $receivedMinor - $expectedMinor,
        ];
    }

    private function statementTotals(array $lines): array
    {
        $expectedMinor = 0;
        $receivedMinor = 0;

        foreach ($lines as $line) {
            $expectedMinor += $line['expected_minor'];
            $receivedMinor += $line['received_minor'];
        }

        return [
            'currency_code' => $lines[0]['currency_code'],
            'decimal_places' => $lines[0]['decimal_places'],
            'expected_minor' => $expectedMinor,
            'received_minor' => $receivedMinor,
            'variance_minor' => $receivedMinor - $expectedMinor,
        ];
    }

    private function validateIdentity(array $payload): array
    {
        $mode = $payload['mode'] ?? null;
        $mode = $mode instanceof SettlementMode ? $mode : SettlementMode::tryFrom((string) $mode);
        if ($mode === null) {
            throw $this->invalidPayload('mode');
        }

        $periodStart = $this->dateField($payload['period_start'] ?? null, 'period_start');
        $periodEnd = $this->dateField($payload['period_end'] ?? null, 'period_end');
        if ($periodStart > $periodEnd) {
            throw $this->invalidPayload('period_end');
        }

        $metadata = $payload['metadata'] ?? null;
        if ($metadata !== null && !is_array($metadata)) {
            throw $this->invalidPayload('metadata');
        }

        return [
            'statement_key' => $this->stringField($payload['statement_key'] ?? null, 'statement_key', 191),
            'provider_code' => $this->stringField($payload['provider_code'] ?? null, 'provider_code', 32),
            'mode' => $mode->value,
            'account_code' => $this->stringField($payload['account_code'] ?? null, 'account_code', 64),
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'metadata' => $metadata,
        ];
    }

    private function normalizeLines(mixed $lines): array
    {
        if (!is_array($lines) || $lines === []) {
            throw $this->invalidPayload('lines');
        }

        $normalized = [];
        foreach ($lines as $line) {
            if (!is_array($line)) {
                throw $this->invalidPayload('lines');
            }

            $orderId = $line['order_id'] ?? null;
            if (!is_int($orderId) && !(is_string($orderId) && ctype_digit($orderId))) {
                throw $this->invalidPayload('order_id');
            }
            $orderId = (int) $orderId;
            if ($orderId <= 0) {
                throw $this->invalidPayload('order_id');
            }

            $lineKey = $line['line_key'] ?? (string) $orderId;
            if (!is_int($lineKey) && !is_string($lineKey)) {
                throw $this->invalidPayload('line_key');
            }

            $normalized[] = [
                'line_key' => $this->stringField((string) $lineKey, 'line_key', 191),
                'order_id' => $orderId,
                'expected' => $line['expected'] ?? null,
                'received' => $line['received'] ?? null,
            ];
        }

        return $normalized;
    }

    private function decimalPlacesFor(string $currencyCode): int
    {
        $currency = Currency::query()->where('code', $currencyCode)->first();
        if ($currency === null || $currency->decimal_places === null) {
            throw CodSettlementPrecisionException::forCurrency();
        }

        $decimalPlaces = (int) $currency->decimal_places;
        if ($decimalPlaces < 0 || $decimalPlaces > 4) {
            throw CodSettlementPrecisionException::forCurrency();
        }

        return $decimalPlaces;
    }

    /**
     * Convert a major-unit decimal into integer minor units without floating
     * point arithmetic. More fraction digits than the currency supports is a
     * precision failure, never a silent round.
     */
    private function toMinorUnits(mixed $amount, int $decimalPlaces): int
    {
        if (!is_int($amount) && !is_string($amount)) {
            throw CodSettlementPrecisionException::forAmount();
        }

        $raw = trim((string) $amount);
        if (!preg_match('/^\d+(?:\.\d+)?$/', $raw)) {
            throw CodSettlementPrecisionException::forAmount();
        }

        [$whole, $fraction] = array_pad(explode('.', $raw, 2), 2, '');
        if (strlen($fraction) > $decimalPlaces) {
            throw CodSettlementPrecisionException::forAmount();
        }

        $fraction = str_pad($fraction, $decimalPlaces, '0');
        $digits = ltrim($decimalPlaces === 0 ? $whole : $whole . $fraction, '0') ?: '0';
        $max = (string) PHP_INT_MAX;

        if (strlen($digits) > strlen($max) || (strlen($digits) === strlen($max) && strcmp($digits, $max) > 0)) {
            throw CodSettlementPrecisionException::forAmount();
        }

        return (int) $digits;
    }

    private function stringField(mixed $value, string $field, int $maxLength): string
    {
        if (!is_string($value) && !is_int($value)) {
            throw $this->invalidPayload($field);
        }

        $value = trim((string) $value);
        if ($value === '' || strlen($value) > $maxLength) {
            throw $this->invalidPayload($field);
        }

        return $value;
    }

    private function dateField(mixed $value, string $field): string
    {
        if (!is_string($value) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $matches)) {
            throw $this->invalidPayload($field);
        }

        if (!checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1])) {
            throw $this->invalidPayload($field);
        }

        return $value;
    }

    private function invalidPayload(string $field): InvalidArgumentException
    {
        return new InvalidArgumentException(__('cod_settlement.invalid_payload', ['field' => $field]));
    }
}
