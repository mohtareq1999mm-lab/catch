<?php

declare(strict_types=1);

namespace App\DTOs\Payment;

/**
 * Immutable COD money snapshot: integer minor units in the order's currency.
 *
 * Produced only by OrderPaidAmountService from server-side evidence; it never
 * accepts client amounts, converts currencies, or applies fee/tax rules.
 */
final class CollectibleCodAmount
{
    public function __construct(
        public readonly int $orderId,
        public readonly string $currencyCode,
        public readonly int $decimalPlaces,
        public readonly int $orderTotalMinor,
        public readonly int $capturedMinor,
        public readonly int $refundedMinor,
        public readonly int $collectibleMinor,
    ) {
    }
}
