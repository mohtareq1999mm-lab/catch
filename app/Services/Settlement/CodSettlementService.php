<?php

namespace App\Services\Settlement;

use App\Enums\CodSettlementMatchMethod;
use App\Enums\CodSettlementMatchStatus;
use App\Enums\CodSettlementStatementStatus;
use App\Enums\SettlementMode;
use App\Exceptions\CodSettlementPrecisionException;
use App\Models\Currency;
use App\Models\Settlement\CodSettlementLine;
use App\Models\Settlement\CodSettlementMatchAudit;
use App\Models\Settlement\CodSettlementStatement;
use App\Models\Shipment;
use App\Models\Shipping\ShippingProviderEvent;
use App\Support\UniqueViolation;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\User;

/**
 * #135/#156: COD settlement evidence and audited matching.
 *
 * This service never mutates payment, order, shipment, fulfillment,
 * inventory, lifecycle event or queue state. It reads orders/shipments only
 * to validate a settlement's provider/mode/account/period/currency identity,
 * then writes evidence rows. No rate lookup, fee rule, tax rule or currency
 * conversion occurs here.
 */
class CodSettlementService
{
    public function createStatement(array $payload): CodSettlementStatement
    {
        // #162: a statement carrying a source-file hash is an import. Route it
        // through the idempotent path so no caller can bypass deduplication by
        // using the older service entry point.
        if (array_key_exists('file_hash', $payload) && $payload['file_hash'] !== null) {
            return $this->importStatement($payload);
        }

        $identity = $this->validateIdentity($payload);
        $overlapRule = $this->validateOverlapRule($payload['overlap_rule'] ?? null);
        $lines = $this->resolveLines(
            $this->normalizeLines($payload['lines'] ?? null),
            $identity,
            $identity['currency_code']
        );
        $identity['currency_code'] = $lines[0]['currency_code'];

        $lockName = $this->statementScopeLockName($identity);
        $lockAcquired = $this->acquireStatementScopeLock($lockName);

        try {
            return DB::transaction(function () use ($identity, $lines, $overlapRule): CodSettlementStatement {
                $this->assertNoDuplicateStatement($identity);
                $overlap = $this->findOverlappingStatement($identity);
                if ($overlap !== null) {
                    if ($overlapRule === 'reject') {
                        throw new InvalidArgumentException(__('cod_settlement.overlapping_statement'));
                    }

                    if ($overlapRule === 'flag') {
                        $metadata = $identity['metadata'] ?? [];
                        $metadata['overlap'] = [
                            'rule' => 'flag',
                            'statement_ids' => [$overlap->id],
                        ];
                        $identity['metadata'] = $metadata;
                    }
                }

                return $this->persistStatement($identity, $lines, $this->statementTotals($lines));
            });
        } catch (QueryException $e) {
            if ($this->isDuplicateStatementViolation($e, $identity)) {
                throw $this->duplicateStatementException();
            }

            if ($this->isStatementKeyViolation($e, $identity)) {
                throw $this->duplicateStatementKeyException();
            }

            throw $e;
        } finally {
            if ($lockAcquired) {
                $this->releaseStatementScopeLock($lockName);
            }
        }
    }

    /**
     * #162: idempotent import by provider + mode + file hash.
     * Re-importing the same file returns the existing statement without
     * duplicating statement/line rows.
     */
    public function importStatement(array $payload): CodSettlementStatement
    {
        $identity = $this->validateIdentity($payload, true);
        $fileHash = $identity['file_hash'];

        $existing = $this->findImportByFileHash($identity['provider_code'], $identity['mode'], $fileHash);
        if ($existing !== null) {
            return $existing;
        }

        $lines = $this->resolveLines(
            $this->normalizeLines($payload['lines'] ?? null),
            $identity,
            $identity['currency_code'],
        );
        $identity['currency_code'] = $lines[0]['currency_code'];

        return DB::transaction(function () use ($identity, $lines, $fileHash): CodSettlementStatement {
            $locked = $this->findImportByFileHash(
                $identity['provider_code'],
                $identity['mode'],
                $fileHash,
                true
            );
            if ($locked !== null) {
                return $locked;
            }

            try {
                return $this->persistStatement($identity, $lines, $this->statementTotals($lines));
            } catch (UniqueConstraintViolationException $exception) {
                // The DB unique index is the final concurrency authority. A
                // racing import may win after our pre-check/gap lock; return
                // its statement with lines instead of surfacing a duplicate.
                $winner = $this->findImportByFileHash($identity['provider_code'], $identity['mode'], $fileHash);
                if ($winner !== null) {
                    return $winner;
                }

                throw $exception;
            }
        });
    }

    private function findImportByFileHash(string $providerCode, string $mode, string $fileHash, bool $lock = false): ?CodSettlementStatement
    {
        $query = CodSettlementStatement::query()
            ->with('lines')
            ->where('provider_code', $providerCode)
            ->where('mode', $mode)
            ->where('file_hash', $fileHash)
            ->orderBy('id');

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
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

    public function manualMatch(int $statementId, int $lineId, int $orderId, int $actorId, string $reason): CodSettlementLine
    {
        $reason = $this->stringField($reason, 'reason', 1000);

        return DB::transaction(function () use ($statementId, $lineId, $orderId, $actorId, $reason): CodSettlementLine {
            $statement = CodSettlementStatement::query()->lockForUpdate()->findOrFail($statementId);
            if (($statement->status ?? CodSettlementStatementStatus::OPEN->value) === CodSettlementStatementStatus::CLOSED->value) {
                throw $this->invalidPayload('statement_closed');
            }

            $line = CodSettlementLine::query()->where('statement_id', $statement->id)->lockForUpdate()->findOrFail($lineId);
            $identity = $this->statementIdentity($statement);
            $order = Order::query()->find($orderId);
            if ($order === null) {
                throw $this->invalidPayload('order_id');
            }

            $this->assertOrderCurrency($order, $identity);
            if ($this->decimalPlacesFor($identity['currency_code']) !== (int) $statement->decimal_places) {
                throw CodSettlementPrecisionException::forMixedCurrency();
            }

            $allShipments = Shipment::query()->where('order_id', $order->id)->get();
            if ($allShipments->isNotEmpty()) {
                $this->assertOrderInScope($order, $identity, $this->scopedShipmentsForOrderIds([$order->id], $identity));
            }
            $this->assertLineReferenceUnique($statement, $line, $order);

            $previousStatus = (string) ($line->match_status ?? CodSettlementMatchStatus::UNMATCHED->value);
            $previousOrderId = $line->order_id !== null ? (int) $line->order_id : null;
            $previousMethod = $line->match_method !== null ? (string) $line->match_method : null;

            $line->forceFill([
                'order_id' => $order->id,
                'match_status' => CodSettlementMatchStatus::MATCHED->value,
                'match_method' => CodSettlementMatchMethod::MANUAL->value,
                'matched_by' => $actorId,
                'matched_at' => now(),
                'match_reason' => $reason,
            ])->save();

            CodSettlementMatchAudit::create($this->auditAttributes($statement, $line, $order, [
                'actor_id' => $actorId,
                'reason' => $reason,
                'match_method' => CodSettlementMatchMethod::MANUAL->value,
                'new_match_status' => CodSettlementMatchStatus::MATCHED->value,
                'previous_match_status' => $previousStatus,
                'previous_order_id' => $previousOrderId,
                'previous_match_method' => $previousMethod,
            ]));

            return $line->fresh();
        });
    }

    public function closeStatement(int $statementId, int $actorId, ?string $reason = null): CodSettlementStatement
    {
        $reason = $reason !== null ? $this->stringField($reason, 'reason', 1000) : null;

        return DB::transaction(function () use ($statementId, $actorId, $reason): CodSettlementStatement {
            $statement = CodSettlementStatement::query()->with('lines')->lockForUpdate()->findOrFail($statementId);
            if (($statement->status ?? CodSettlementStatementStatus::OPEN->value) === CodSettlementStatementStatus::CLOSED->value) {
                return $statement;
            }

            $unmatched = $statement->lines->first(fn ($line) =>
                ($line->match_status ?? CodSettlementMatchStatus::UNMATCHED->value) !== CodSettlementMatchStatus::MATCHED->value
                || $line->order_id === null
            );
            if ($unmatched !== null) {
                throw $this->invalidPayload('unmatched_lines');
            }

            if ((int) $statement->variance_minor !== 0) {
                throw $this->invalidPayload('unresolved_variances');
            }
            $lineVariance = $statement->lines->first(fn ($line) => (int) $line->variance_minor !== 0);
            if ($lineVariance !== null) {
                throw $this->invalidPayload('unresolved_variances');
            }

            $identity = $this->statementIdentity($statement);
            $matchedIds = $statement->lines->pluck('order_id')->filter()->map(fn ($id) => (int) $id)->all();
            $orders = Order::query()->whereIn('id', $matchedIds)->get()->keyBy('id');
            $allShipments = Shipment::query()
                ->whereIn('order_id', $matchedIds)
                ->get()
                ->groupBy(fn (Shipment $shipment) => (string) $shipment->order_id);
            $scopedShipments = $this->scopedShipmentsForOrderIds($matchedIds, $identity)->groupBy('order_id');

            foreach ($statement->lines as $line) {
                $order = $orders->get((int) $line->order_id);
                if ($order === null) {
                    // Orphan matched orders are never allowed to close.
                    throw $this->invalidPayload('orphan_order');
                }
                $this->assertOrderCurrency($order, $identity);
                $allForOrder = $allShipments->get((string) $line->order_id, new Collection());
                if ($allForOrder->isNotEmpty()) {
                    $this->assertOrderInScope(
                        $order,
                        $identity,
                        $scopedShipments->get((string) $line->order_id, new Collection()),
                    );
                }
            }

            $statement->forceFill([
                'status' => CodSettlementStatementStatus::CLOSED->value,
                'closed_by' => $actorId,
                'closed_at' => now(),
                'close_reason' => $reason,
            ])->save();

            return $statement->fresh('lines');
        });
    }

    private function persistStatement(array $identity, array $lines, array $totals): CodSettlementStatement
    {
        unset($identity['currency_code']);
        $statement = CodSettlementStatement::create($identity + $totals + ['status' => CodSettlementStatementStatus::OPEN->value]);

        foreach ($lines as $line) {
            $statement->lines()->create($line);
        }

        return $statement->load('lines');
    }

    private function resolveLines(array $normalizedLines, array $identity, ?string $headerCurrencyCode): array
    {
        $this->assertDistinctLineIdentifiers($normalizedLines);
        $candidates = $this->loadCandidates($normalizedLines, $identity);

        $matches = [];
        $matchedOrders = [];
        foreach ($normalizedLines as $line) {
            [$matchedOrder, $matchMethod, $matchStatus] = $this->matchLine($line, $identity, $candidates);
            if ($matchedOrder !== null) {
                if (isset($matchedOrders[$matchedOrder->id])) {
                    throw $this->invalidPayload('order_ambiguous');
                }
                $matchedOrders[$matchedOrder->id] = true;
            }
            $matches[] = [$line, $matchedOrder, $matchMethod, $matchStatus];
        }

        $currencyCode = $identity['currency_code'] ?? null;
        $decimalPlaces = $currencyCode !== null ? $this->decimalPlacesFor($currencyCode) : null;
        foreach ($matches as [, $matchedOrder]) {
            if ($matchedOrder === null || $currencyCode !== null) {
                continue;
            }
            $currencyCode = strtoupper(trim((string) $matchedOrder->currency_code));
            if ($currencyCode === '') {
                throw CodSettlementPrecisionException::forCurrency();
            }
            $decimalPlaces = $this->decimalPlacesFor($currencyCode);
        }
        if ($currencyCode === null) {
            throw CodSettlementPrecisionException::forCurrency();
        }

        $resolved = [];
        foreach ($matches as [$line, $matchedOrder, $matchMethod, $matchStatus]) {
            [$currencyCode, $decimalPlaces] = $this->linePrecision($line, $matchedOrder, $currencyCode, $decimalPlaces);
            $resolved[] = $this->resolveLine(
                $line,
                $this->lineIdentity($identity),
                ['currency_code' => $currencyCode, 'decimal_places' => $decimalPlaces],
                ['order' => $matchedOrder, 'method' => $matchMethod, 'status' => $matchStatus],
            );
        }

        return $resolved;
    }

    /**
     * @return array{0: ?Order, 1: ?string, 2: string}
     */
    private function matchLine(array $line, array $identity, array $candidates): array
    {
        $matched = fn (Order $order, CodSettlementMatchMethod $method) => [
            $order,
            $method->value,
            CodSettlementMatchStatus::MATCHED->value,
        ];
        $unmatched = [null, null, CodSettlementMatchStatus::UNMATCHED->value];

        $awb = $line['awb'] ?? null;
        if ($awb !== null) {
            return $this->matchFromShipments(
                $candidates['shipments_by_awb']->get($awb, new Collection()),
                $matched,
                CodSettlementMatchMethod::AWB,
                'awb_ambiguous',
            );
        }

        $providerShipmentId = $line['provider_shipment_id'] ?? null;
        if ($providerShipmentId !== null) {
            return $this->matchFromShipments(
                $candidates['shipments_by_provider_id']->get($providerShipmentId, new Collection()),
                $matched,
                CodSettlementMatchMethod::PROVIDER_SHIPMENT_ID,
                'provider_shipment_id_ambiguous',
            );
        }

        $orderNumber = $line['order_number'] ?? null;
        if ($orderNumber !== null) {
            $orders = $candidates['orders_by_number']->get($orderNumber, new Collection());
            if ($orders->count() > 1) {
                throw $this->invalidPayload('order_number_ambiguous');
            }
            $order = $orders->first();
            if (!$order instanceof Order) {
                // No confident link: keep the line as evidence for manual review.
                return $unmatched;
            }

            return $this->matchFromShipments(
                $candidates['shipments_by_order']->get((string) $order->id, new Collection()),
                $matched,
                CodSettlementMatchMethod::ORDER_NUMBER,
                'order_number_ambiguous',
            );
        }

        $orderId = $line['order_id'] ?? null;
        if ($orderId !== null) {
            $order = $candidates['orders_by_id']->get((int) $orderId);
            if (!$order instanceof Order) {
                throw $this->invalidPayload('order_id');
            }
            $this->assertOrderCurrency($order, $identity);

            $allShipments = $candidates['all_shipments_by_order']->get((string) $order->id, new Collection());
            $scoped = $candidates['shipments_by_order']->get((string) $order->id, new Collection());
            if ($allShipments->isNotEmpty()) {
                $this->assertOrderInScope($order, $identity, $scoped);
            }

            // Explicit order_id is #135 evidence, not a lower-priority
            // fallback: it is only consulted when no other identifier was
            // supplied, and any existing shipment must still be in scope.
            return $matched($order, CodSettlementMatchMethod::ORDER_ID);
        }

        throw $this->invalidPayload('order_id');
    }

    /**
     * @param  Collection<int, Shipment>  $shipments
     * @param  callable(Order, CodSettlementMatchMethod): array{0: ?Order, 1: ?string, 2: string}  $matched
     * @return array{0: ?Order, 1: ?string, 2: string}
     */
    private function matchFromShipments(
        Collection $shipments,
        callable $matched,
        CodSettlementMatchMethod $method,
        string $ambiguousField,
    ): array {
        if ($shipments->count() > 1) {
            throw $this->invalidPayload($ambiguousField);
        }

        $shipment = $shipments->first();
        if (!$shipment instanceof Shipment || !$shipment->order instanceof Order) {
            return [null, null, CodSettlementMatchStatus::UNMATCHED->value];
        }

        return $matched($shipment->order, $method);
    }

    private function loadCandidates(array $lines, array $identity): array
    {
        $unique = fn (string $field): array => array_values(array_unique(array_filter(
            array_column($lines, $field),
            static fn ($value) => $value !== null && $value !== '',
        )));

        $awbs = $unique('awb');
        $providerIds = $unique('provider_shipment_id');
        $orderNumbers = $unique('order_number');
        $explicitOrderIds = array_map('intval', $unique('order_id'));

        $ordersByNumber = Order::query()->whereIn('order_number', $orderNumbers)->get()->groupBy('order_number');
        $ordersById = Order::query()->whereIn('id', $explicitOrderIds)->get()->keyBy('id');

        $orderIds = array_values(array_unique(array_merge(
            $explicitOrderIds,
            $ordersByNumber->flatten()->map(fn (Order $order) => (int) $order->id)->all(),
        )));

        $allShipmentsByOrder = Shipment::query()
            ->whereIn('order_id', $orderIds)
            ->get()
            ->groupBy(fn (Shipment $shipment) => (string) $shipment->order_id);

        $shipments = Shipment::query()->with('order')
            ->where(function ($query) use ($awbs, $providerIds, $orderIds) {
                $query->whereIn('tracking_number', $awbs)
                    ->orWhereIn('provider_shipment_id', $providerIds)
                    ->orWhereIn('order_id', $orderIds);
            })
            ->where('provider_code', $identity['provider_code'])
            ->where('provider_mode', $identity['mode'])
            ->whereBetween('created_at', [
                $identity['period_start'] . ' 00:00:00',
                $identity['period_end'] . ' 23:59:59',
            ])
            ->get();

        $events = $this->providerEventsFor($shipments->pluck('id')->all());
        $shipments = $shipments->filter(fn (Shipment $shipment) => $this->shipmentInIdentity(
            $shipment,
            $identity,
            $events->get((int) $shipment->id, new Collection()),
        ))->values();

        return [
            'orders_by_number' => $ordersByNumber,
            'orders_by_id' => $ordersById,
            'all_shipments_by_order' => $allShipmentsByOrder,
            'shipments_by_order' => $shipments->groupBy(fn (Shipment $shipment) => (string) $shipment->order_id),
            'shipments_by_awb' => $shipments->groupBy('tracking_number'),
            'shipments_by_provider_id' => $shipments->groupBy('provider_shipment_id'),
        ];
    }

    private function scopedShipmentsForOrderIds(array $orderIds, array $identity): Collection
    {
        if ($orderIds === []) {
            return new Collection();
        }

        $shipments = Shipment::query()->with('order')
            ->whereIn('order_id', $orderIds)
            ->where('provider_code', $identity['provider_code'])
            ->where('provider_mode', $identity['mode'])
            ->whereBetween('created_at', [
                $identity['period_start'] . ' 00:00:00',
                $identity['period_end'] . ' 23:59:59',
            ])
            ->get();

        $events = $this->providerEventsFor($shipments->pluck('id')->all());

        return $shipments->filter(fn (Shipment $shipment) => $this->shipmentInIdentity(
            $shipment,
            $identity,
            $events->get((int) $shipment->id, new Collection()),
        ))->values();
    }

    private function providerEventsFor(array $shipmentIds): Collection
    {
        if ($shipmentIds === [] || !Schema::hasTable('shipping_provider_events')) {
            return new Collection();
        }

        return ShippingProviderEvent::query()
            ->whereIn('shipment_id', $shipmentIds)
            ->get()
            ->groupBy('shipment_id');
    }

    private function shipmentInIdentity(Shipment $shipment, array $identity, Collection $events): bool
    {
        $order = $shipment->order;
        if (!$order instanceof Order) {
            return false;
        }

        $currency = strtoupper(trim((string) $order->currency_code));
        if ($currency === '') {
            return false;
        }
        if (isset($identity['currency_code']) && $currency !== $identity['currency_code']) {
            return false;
        }

        // The shipment table has no account column. When carrier event
        // evidence exists it must agree with the statement account; absence
        // of event evidence cannot contradict the statement identity.
        if ($events->isNotEmpty() && !$events->every(fn (ShippingProviderEvent $event) =>
            $event->provider_code === $identity['provider_code']
            && $event->mode === $identity['mode']
            && $event->account_code === $identity['account_code']
        )) {
            return false;
        }

        return true;
    }

    private function assertOrderInScope(Order $order, array $identity, Collection $scopedShipments): void
    {
        $this->assertOrderCurrency($order, $identity);

        if ($scopedShipments->count() === 0) {
            throw $this->invalidPayload('order_id_scope');
        }
        if ($scopedShipments->count() > 1) {
            throw $this->invalidPayload('order_id_ambiguous');
        }
    }

    private function assertOrderCurrency(Order $order, array $identity): void
    {
        $currency = strtoupper(trim((string) $order->currency_code));
        if ($currency === '') {
            throw $this->invalidPayload('order_id');
        }
        if (isset($identity['currency_code']) && $currency !== $identity['currency_code']) {
            throw $this->invalidPayload('order_id');
        }
    }

    private function assertDistinctLineIdentifiers(array $lines): void
    {
        foreach (['awb', 'provider_shipment_id', 'order_number', 'order_id'] as $field) {
            $values = array_filter(array_column($lines, $field), static fn ($value) => $value !== null);
            if (count($values) !== count(array_unique(array_map('strval', $values)))) {
                throw $this->invalidPayload($field . '_ambiguous');
            }
        }
    }

    private function assertLineReferenceUnique(CodSettlementStatement $statement, CodSettlementLine $line, Order $order): void
    {
        $duplicate = CodSettlementLine::query()
            ->where('statement_id', $statement->id)
            ->whereKeyNot($line->id)
            ->where(function ($query) use ($order, $line) {
                $query->where('order_id', $order->id);
                foreach (['order_number', 'awb', 'provider_shipment_id'] as $field) {
                    if ($line->{$field} !== null) {
                        $query->orWhere($field, $line->{$field});
                    }
                }
            })
            ->exists();

        if ($duplicate) {
            throw $this->invalidPayload('order_ambiguous');
        }
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function linePrecision(array $line, ?Order $order, string $currencyCode, ?int $decimalPlaces): array
    {
        $lineCurrency = strtoupper(trim((string) $order?->currency_code));
        if ($lineCurrency === '') {
            if ($order === null) {
                return [$currencyCode, $this->decimalPlacesFor($currencyCode)];
            }
            throw CodSettlementPrecisionException::forCurrency();
        }

        if ($lineCurrency !== $currencyCode) {
            throw $this->invalidPayload('order_id');
        }

        $lineDecimalPlaces = $this->decimalPlacesFor($lineCurrency);
        if ($decimalPlaces !== null && $lineDecimalPlaces !== $decimalPlaces) {
            throw CodSettlementPrecisionException::forMixedCurrency();
        }

        return [$lineCurrency, $lineDecimalPlaces];
    }

    private function resolveLine(array $line, array $lineIdentity, array $precision, array $match): array
    {
        $currencyCode = $precision['currency_code'];
        $decimalPlaces = $precision['decimal_places'];
        $order = $match['order'];
        $expectedMinor = $this->toMinorUnits($line['expected'], $decimalPlaces);
        $receivedMinor = $this->toMinorUnits($line['received'], $decimalPlaces);

        return $lineIdentity + [
            'line_key' => $line['line_key'],
            'order_id' => $order?->id,
            'awb' => $line['awb'] ?? null,
            'provider_shipment_id' => $line['provider_shipment_id'] ?? null,
            'order_number' => $line['order_number'] ?? null,
            'match_status' => $match['status'],
            'match_method' => $match['method'],
            'matched_at' => $match['status'] === CodSettlementMatchStatus::MATCHED->value ? now() : null,
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

    private function statementIdentity(CodSettlementStatement $statement): array
    {
        $currency = strtoupper(trim((string) $statement->currency_code));
        if ($currency === '') {
            throw CodSettlementPrecisionException::forCurrency();
        }

        return [
            'provider_code' => (string) $statement->provider_code,
            'mode' => (string) $statement->mode,
            'account_code' => (string) $statement->account_code,
            'period_start' => $statement->period_start?->toDateString() ?? (string) $statement->period_start,
            'period_end' => $statement->period_end?->toDateString() ?? (string) $statement->period_end,
            'currency_code' => $currency,
        ];
    }

    private function lineIdentity(array $identity): array
    {
        return array_intersect_key($identity, array_flip([
            'provider_code', 'mode', 'account_code', 'period_start', 'period_end',
        ]));
    }

    private function auditAttributes(
        CodSettlementStatement $statement,
        CodSettlementLine $line,
        Order $order,
        array $auditContext,
    ): array {
        $identity = $this->statementIdentity($statement);
        $actorId = (int) $auditContext['actor_id'];

        return [
            'statement_id' => $statement->id,
            'line_id' => $line->id,
            'actor_id' => $actorId,
            'actor_name' => User::query()->whereKey($actorId)->value('name'),
            'statement_key' => $statement->statement_key,
            'line_key' => $line->line_key,
            'order_id' => $order->id,
            'previous_order_id' => $auditContext['previous_order_id'],
            'previous_match_status' => $auditContext['previous_match_status'],
            'previous_match_method' => $auditContext['previous_match_method'],
            'new_match_status' => $auditContext['new_match_status'],
            'match_method' => $auditContext['match_method'],
            'reason' => $auditContext['reason'],
            'metadata' => [
                'awb' => $line->awb,
                'provider_shipment_id' => $line->provider_shipment_id,
                'order_number' => $line->order_number,
            ],
            'awb' => $line->awb,
            'provider_shipment_id' => $line->provider_shipment_id,
            'order_number' => $line->order_number,
            'provider_code' => $identity['provider_code'],
            'mode' => $identity['mode'],
            'account_code' => $identity['account_code'],
            'period_start' => $identity['period_start'],
            'period_end' => $identity['period_end'],
        ];
    }

    private function validateIdentity(array $payload, bool $requireFileHash = false): array
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

    $identity = [
            'statement_key' => $this->stringField($payload['statement_key'] ?? null, 'statement_key', 191),
            'provider_code' => $this->stringField($payload['provider_code'] ?? null, 'provider_code', 32),
            'mode' => $mode->value,
            'account_code' => $this->stringField($payload['account_code'] ?? null, 'account_code', 64),
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'currency_code' => $this->optionalCurrencyCode($payload['currency_code'] ?? null),
            'metadata' => $metadata,
        ];

        $fileHash = $payload['file_hash'] ?? null;
        if ($fileHash !== null || $requireFileHash) {
            $fileHash = $this->stringField($fileHash, 'file_hash', 64);
            if (!preg_match('/^[A-Fa-f0-9]{64}$/', $fileHash)) {
                throw $this->invalidPayload('file_hash');
            }
            $identity['file_hash'] = strtolower($fileHash);
        }

        return $identity;
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
            if ($orderId !== null) {
                if (!is_int($orderId) && !(is_string($orderId) && ctype_digit($orderId))) {
                    throw $this->invalidPayload('order_id');
                }
                $orderId = (int) $orderId;
                if ($orderId <= 0) {
                    throw $this->invalidPayload('order_id');
                }
            }

            $awb = $this->optionalString($line['awb'] ?? ($line['tracking_number'] ?? null), 'awb', 191);
            $providerShipmentId = $this->optionalString($line['provider_shipment_id'] ?? null, 'provider_shipment_id', 191);
            $orderNumber = $this->optionalString($line['order_number'] ?? null, 'order_number', 191);

            if ($orderId === null && $awb === null && $providerShipmentId === null && $orderNumber === null) {
                throw $this->invalidPayload('order_id');
            }

            $lineKey = $line['line_key'] ?? ($awb ?? $providerShipmentId ?? $orderNumber ?? (string) $orderId);
            if (!is_int($lineKey) && !is_string($lineKey)) {
                throw $this->invalidPayload('line_key');
            }

            $normalized[] = [
                'line_key' => $this->stringField((string) $lineKey, 'line_key', 191),
                'order_id' => $orderId,
                'awb' => $awb,
                'provider_shipment_id' => $providerShipmentId,
                'order_number' => $orderNumber,
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

    private function optionalString(mixed $value, string $field, int $maxLength): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        return $this->stringField($value, $field, $maxLength);
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

    private function optionalCurrencyCode(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $code = strtoupper($this->stringField($value, 'currency_code', 3));
        if (!preg_match('/^[A-Z]{3}$/', $code)) {
            throw $this->invalidPayload('currency_code');
        }

        return $code;
    }

    private function validateOverlapRule(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $rule = $this->stringField($value, 'overlap_rule', 16);
        if (!in_array($rule, ['flag', 'reject'], true)) {
            throw $this->invalidPayload('overlap_rule');
        }

        return $rule;
    }

    private function assertNoDuplicateStatement(array $identity): void
    {
        $duplicate = $this->duplicateStatementQuery($identity)
            ->lockForUpdate()
            ->exists();

        if ($duplicate) {
            throw $this->duplicateStatementException();
        }
    }

    private function duplicateStatementQuery(array $identity)
    {
        return CodSettlementStatement::query()
            ->where('provider_code', $identity['provider_code'])
            ->where('mode', $identity['mode'])
            ->where('account_code', $identity['account_code'])
            ->whereDate('period_start', $identity['period_start'])
            ->whereDate('period_end', $identity['period_end'])
            ->where('currency_code', $identity['currency_code']);
    }

    private function duplicateStatementException(): ValidationException
    {
        return ValidationException::withMessages([
            'statement_key' => [__('cod_settlement.duplicate_statement')],
        ]);
    }

    private function duplicateStatementKeyException(): ValidationException
    {
        return ValidationException::withMessages([
            'statement_key' => [__('cod_settlement.duplicate_statement_key')],
        ]);
    }

    private function isDuplicateStatementViolation(QueryException $e, array $identity): bool
    {
        if (! UniqueViolation::detect($e)) {
            return false;
        }

        $message = $e->getMessage();
        if (str_contains($message, 'uq_cod_settlement_statement_identity')) {
            return true;
        }

        // SQLite and some drivers omit index names; confirm against the
        // deterministic statement identity before mapping to domain error.
        if ($this->isStatementKeyViolation($e, $identity)) {
            return false;
        }

        return $this->duplicateStatementQuery($identity)->exists();
    }

    private function isStatementKeyViolation(QueryException $e, array $identity): bool
    {
        if (! UniqueViolation::detect($e)) {
            return false;
        }

        $message = $e->getMessage();
        if (
            str_contains($message, 'uq_cod_settlement_statement_key')
            || str_contains($message, 'cod_settlement_statements.statement_key')
            || str_contains($message, 'cod_settlement_statements_statement_key_unique')
        ) {
            return true;
        }

        // SQLite and some drivers omit index names; confirm against the
        // statement key before mapping to a deterministic domain error.
        $statementKey = trim((string) ($identity['statement_key'] ?? ''));
        if ($statementKey === '') {
            return false;
        }

        return CodSettlementStatement::query()
            ->where('statement_key', $statementKey)
            ->exists();
    }

    private function statementScopeLockName(array $identity): string
    {
        $payload = implode('|', [
            'cod_settlement',
            (string) $identity['provider_code'],
            (string) $identity['mode'],
            (string) $identity['account_code'],
            (string) $identity['currency_code'],
        ]);

        return 'cod_settlement:' . sha1($payload);
    }

    private function acquireStatementScopeLock(string $lockName): bool
    {
        $connection = DB::connection();

        if ($connection->getDriverName() !== 'mysql') {
            return false;
        }

        $timeout = max(0, (int) config('cod_settlement.scope_lock_timeout', 5));
        $acquired = $connection->selectOne('SELECT GET_LOCK(?, ?) AS acquired', [$lockName, $timeout]);
        $result = is_object($acquired) ? ($acquired->acquired ?? null) : ($acquired['acquired'] ?? null);

        if ((int) $result === 1) {
            return true;
        }

        throw ValidationException::withMessages([
            'statement_key' => [__('cod_settlement.lock_timeout')],
        ]);
    }

    private function releaseStatementScopeLock(string $lockName): void
    {
        $connection = DB::connection();

        if ($connection->getDriverName() !== 'mysql') {
            return;
        }

        $connection->selectOne('SELECT RELEASE_LOCK(?) AS released', [$lockName]);
    }

    private function findOverlappingStatement(array $identity): ?CodSettlementStatement
    {
        return CodSettlementStatement::query()
            ->where('provider_code', $identity['provider_code'])
            ->where('mode', $identity['mode'])
            ->where('account_code', $identity['account_code'])
            ->where('currency_code', $identity['currency_code'])
            ->whereDate('period_start', '<=', $identity['period_end'])
            ->whereDate('period_end', '>=', $identity['period_start'])
            ->lockForUpdate()
            ->orderBy('id')
            ->first();
    }

    private function invalidPayload(string $field): InvalidArgumentException
    {
        return new InvalidArgumentException(__('cod_settlement.invalid_payload', ['field' => $field]));
    }
}
