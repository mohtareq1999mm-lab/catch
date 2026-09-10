<?php

namespace App\Services\Invoice\Validators;

use App\Contracts\Services\Invoice\SnapshotValidatorInterface;
use App\Exceptions\SnapshotValidationException;

class MoneyValidator implements SnapshotValidatorInterface
{
    private const MAX_DECIMAL_PLACES = 3;

    public function validate(array $snapshot): void
    {
        $this->validateMoneyField($snapshot['pricing_breakdown']['subtotal'] ?? null, 'subtotal');
        $this->validateMoneyField($snapshot['pricing_breakdown']['promotion_discount'] ?? null, 'promotion_discount');
        $this->validateMoneyField($snapshot['pricing_breakdown']['coupon_discount'] ?? null, 'coupon_discount');
        $this->validateMoneyField($snapshot['pricing_breakdown']['shipping_price'] ?? null, 'shipping_price');
        $this->validateMoneyField($snapshot['pricing_breakdown']['total'] ?? null, 'total');

        foreach ($snapshot['items'] ?? [] as $i => $item) {
            $this->validateMoneyField($item['unit_price'] ?? null, "items[{$i}].unit_price");
            $this->validateMoneyField($item['total_price'] ?? null, "items[{$i}].total_price");
        }
    }

    private function validateMoneyField(mixed $value, string $field): void
    {
        if ($value === null) {
            return;
        }

        if (!is_numeric($value) && !is_float($value) && !is_int($value)) {
            throw new SnapshotValidationException(
                "{$field} must be numeric, got " . gettype($value)
            );
        }

        $parts = explode('.', (string) $value);
        if (isset($parts[1]) && strlen($parts[1]) > self::MAX_DECIMAL_PLACES) {
            throw new SnapshotValidationException(
                "{$field} has more than " . self::MAX_DECIMAL_PLACES . " decimal places"
            );
        }
    }
}
