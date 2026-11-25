<?php

declare(strict_types=1);

namespace App\Services\Settlement;

use App\Audit\ActivityAuditService;
use App\Audit\ActivitySnapshot;
use App\Exceptions\AmbiguousSettlementMatchException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\User;

/**
 * #191: settlement order-number matching fails closed on ambiguity.
 *
 * Provider and mode are applied when available because they are stored on
 * shipments. Provider account and period remain audit scope only; orders do
 * not carry those columns, and inventing a join would create false matches.
 */
final class SettlementOrderMatcher
{
    public function matchByOrderNumber(string $orderNumber, array $scope = []): Order
    {
        $orderNumber = $this->stringField($orderNumber, 'order_number', 20);
        $orders = $this->candidates($orderNumber, $scope, 2);

        if ($orders->count() > 1) {
            throw AmbiguousSettlementMatchException::forOrderNumber(
                $orderNumber,
                $orders->modelKeys(),
            );
        }

        if ($orders->isEmpty()) {
            throw new InvalidArgumentException(__('cod_settlement.invalid_payload', ['field' => 'order_number']));
        }

        return $orders->first();
    }

    /**
     * @param array<string, string> $scope
     */
    public function matchManually(
        string $orderNumber,
        int $orderId,
        int $actorId,
        string $reason,
        array $scope = [],
    ): Order {
        $orderNumber = $this->stringField($orderNumber, 'order_number', 20);
        if ($orderId <= 0 || $actorId <= 0) {
            throw new InvalidArgumentException(__('cod_settlement.invalid_payload', ['field' => 'order_id']));
        }

        $reason = trim($reason);
        if ($reason === '' || strlen($reason) > 255) {
            throw new InvalidArgumentException(__('cod_settlement.invalid_payload', ['field' => 'reason']));
        }

        $candidateIds = $this->candidates($orderNumber, $scope)->modelKeys();
        if (! in_array($orderId, $candidateIds, true)) {
            throw new InvalidArgumentException(__('cod_settlement.invalid_payload', ['field' => 'order_id']));
        }

        $order = Order::query()->findOrFail($orderId);

        ActivityAuditService::record(new ActivitySnapshot(
            logName: 'finance',
            event: 'settlement.order_match_manual',
            description: 'Ambiguous settlement order number matched manually.',
            subjectType: Order::class,
            subjectId: $orderId,
            causerType: User::class,
            causerId: $actorId,
            old: ['order_number' => $orderNumber, 'candidate_ids' => $candidateIds],
            new: ['order_id' => $orderId],
            context: ['scope' => $scope],
            reason: $reason,
        ));

        return $order;
    }

    private function candidates(string $orderNumber, array $scope, ?int $limit = null): Collection
    {
        $query = Order::query()->where('order_number', $orderNumber);

        $currencyCode = $this->scopeValue($scope, 'currency_code', 3);
        if ($currencyCode !== null) {
            $query->where('currency_code', strtoupper($currencyCode));
        }

        $providerCode = $this->scopeValue($scope, 'provider_code', 32);
        $providerMode = $this->scopeValue($scope, 'mode', 16) ?? $this->scopeValue($scope, 'provider_mode', 16);

        if ($providerCode !== null || $providerMode !== null) {
            $query->whereExists(function (Builder $subQuery) use ($providerCode, $providerMode): void {
                $subQuery->select(DB::raw(1))
                    ->from('shipments')
                    ->whereColumn('shipments.order_id', 'orders.id');

                if ($providerCode !== null) {
                    $subQuery->where('shipments.provider_code', $providerCode);
                }

                if ($providerMode !== null) {
                    $subQuery->where('shipments.provider_mode', $providerMode);
                }
            });
        }

        if ($limit !== null) {
            $query->limit($limit);
        }

        return $query->get();
    }

    private function scopeValue(array $scope, string $key, int $maxLength): ?string
    {
        $value = $scope[$key] ?? null;
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' && strlen($value) <= $maxLength ? $value : null;
    }

    private function stringField(mixed $value, string $field, int $maxLength): string
    {
        if (! is_string($value) && ! is_int($value)) {
            throw new InvalidArgumentException(__('cod_settlement.invalid_payload', ['field' => $field]));
        }

        $value = trim((string) $value);
        if ($value === '' || strlen($value) > $maxLength) {
            throw new InvalidArgumentException(__('cod_settlement.invalid_payload', ['field' => $field]));
        }

        return $value;
    }
}
