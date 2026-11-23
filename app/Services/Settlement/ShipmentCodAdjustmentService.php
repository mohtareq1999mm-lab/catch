<?php

declare(strict_types=1);

namespace App\Services\Settlement;

use App\Exceptions\CodSettlementPrecisionException;
use App\Models\Currency;
use App\Models\Settlement\CodSettlementStatement;
use App\Models\Settlement\ShipmentCodAdjustment;
use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Marvel\Database\Models\Order;

class ShipmentCodAdjustmentService
{
    public function record(array $payload): ShipmentCodAdjustment
    {
        $idempotencyKey = $this->string($payload['idempotency_key'] ?? null, 'idempotency_key', 191);
        $existing = ShipmentCodAdjustment::query()->where('idempotency_key', $idempotencyKey)->first();
        if ($existing !== null) {
            return $existing;
        }

        $orderId = $this->positiveInt($payload['order_id'] ?? null, 'order_id');
        $actorId = $this->positiveInt($payload['actor_id'] ?? null, 'actor_id');
        $reason = $this->string($payload['reason'] ?? null, 'reason', 255);
        $currencyCode = strtoupper($this->string($payload['currency_code'] ?? null, 'currency_code', 3));
        $statementId = isset($payload['statement_id']) ? $this->positiveInt($payload['statement_id'], 'statement_id') : null;

        $order = Order::query()->find($orderId);
        if ($order === null) {
            throw new InvalidArgumentException('Invalid order_id.');
        }

        $orderCurrency = strtoupper(trim((string) $order->currency_code));
        if ($orderCurrency === '' || $orderCurrency !== $currencyCode) {
            throw CodSettlementPrecisionException::forCurrency();
        }

        $decimalPlaces = $this->decimalPlacesFor($currencyCode);

        if ($statementId !== null) {
            $this->assertStatementOwnership($statementId, $order, $currencyCode, $decimalPlaces);
        }

        $original = $this->minor($payload['original_collectible_minor'] ?? null, 'original_collectible_minor');
        $old = $this->minor($payload['old_collectible_minor'] ?? null, 'old_collectible_minor');
        $new = $this->minor($payload['new_collectible_minor'] ?? null, 'new_collectible_minor');

        $metadata = $payload['metadata'] ?? null;
        if ($metadata !== null && !is_array($metadata)) {
            throw new InvalidArgumentException('Invalid metadata.');
        }

        return DB::transaction(function () use (
            $statementId,
            $orderId,
            $currencyCode,
            $decimalPlaces,
            $original,
            $old,
            $new,
            $reason,
            $actorId,
            $idempotencyKey,
            $metadata
        ): ShipmentCodAdjustment {
            $existing = ShipmentCodAdjustment::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($existing !== null) {
                return $existing;
            }

            return ShipmentCodAdjustment::create([
                'statement_id' => $statementId,
                'order_id' => $orderId,
                'currency_code' => $currencyCode,
                'decimal_places' => $decimalPlaces,
                'original_collectible_minor' => $original,
                'old_collectible_minor' => $old,
                'new_collectible_minor' => $new,
                'reason' => $reason,
                'actor_id' => $actorId,
                'status' => 'pending_approval',
                'idempotency_key' => $idempotencyKey,
                'metadata' => $metadata,
            ]);
        });
    }

    public function approve(ShipmentCodAdjustment $adjustment, int $approverId): ShipmentCodAdjustment
    {
        if ($approverId <= 0) {
            throw new DomainException('Approver is required.');
        }

        return DB::transaction(function () use ($adjustment, $approverId): ShipmentCodAdjustment {
            $locked = ShipmentCodAdjustment::query()->lockForUpdate()->findOrFail($adjustment->id);

            if ($locked->status === 'approved') {
                return $locked;
            }

            if ($locked->status !== 'pending_approval') {
                throw new DomainException('Only pending COD adjustments can be approved.');
            }

            if ((int) $locked->actor_id === $approverId) {
                throw new DomainException('COD adjustment approval requires a different user.');
            }

            $locked->approved_by = $approverId;
            $locked->approved_at = now();
            $locked->status = 'approved';
            $locked->save();

            return $locked->fresh();
        });
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

    private function assertStatementOwnership(int $statementId, Order $order, string $currencyCode, int $decimalPlaces): void
    {
        $statement = CodSettlementStatement::query()->with('lines')->find($statementId);
        if ($statement === null) {
            throw new InvalidArgumentException('Invalid statement_id.');
        }

        $statementCurrency = strtoupper(trim((string) $statement->currency_code));
        if ($statementCurrency !== $currencyCode || (int) $statement->decimal_places !== $decimalPlaces) {
            throw new InvalidArgumentException('Invalid statement_id.');
        }

        $matchesOrder = $statement->lines->contains(
            fn ($line): bool => (int) $line->order_id === (int) $order->id
                && strtoupper(trim((string) $line->currency_code)) === $currencyCode
                && (int) $line->decimal_places === $decimalPlaces
        );

        if (!$matchesOrder) {
            throw new InvalidArgumentException('Invalid statement_id.');
        }
    }

    private function positiveInt(mixed $value, string $field): int
    {
        if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
            throw new InvalidArgumentException("Invalid {$field}.");
        }
        $int = (int) $value;
        if ($int <= 0) {
            throw new InvalidArgumentException("Invalid {$field}.");
        }
        return $int;
    }

    private function minor(mixed $value, string $field): int
    {
        if (!is_int($value) && !(is_string($value) && preg_match('/^-?\d+$/', $value))) {
            throw new InvalidArgumentException("Invalid {$field}.");
        }
        return (int) $value;
    }

    private function string(mixed $value, string $field, int $max): string
    {
        if (!is_string($value) && !is_int($value)) {
            throw new InvalidArgumentException("Invalid {$field}.");
        }
        $string = trim((string) $value);
        if ($string === '' || strlen($string) > $max) {
            throw new InvalidArgumentException("Invalid {$field}.");
        }
        return $string;
    }
}
