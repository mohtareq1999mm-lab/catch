<?php

declare(strict_types=1);

namespace App\DTOs\Shipping;

use InvalidArgumentException;

/**
 * Money crossing the carrier seam: integer minor units + ISO currency code.
 * No float arithmetic and no implicit currency conversion.
 */
final class CarrierMoney
{
    public function __construct(
        public readonly int $amountMinor,
        public readonly string $currency,
    ) {
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new InvalidArgumentException('Carrier money requires a three-letter uppercase currency code.');
        }
    }
}
