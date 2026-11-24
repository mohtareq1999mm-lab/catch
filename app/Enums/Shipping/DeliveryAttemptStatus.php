<?php

declare(strict_types=1);

namespace App\Enums\Shipping;

enum DeliveryAttemptStatus: string
{
    case DELIVERED = 'delivered';
    case CUSTOMER_UNAVAILABLE = 'customer_unavailable';
    case WRONG_ADDRESS = 'wrong_address';
    case REFUSED = 'refused';
    case CASH_NOT_READY = 'cash_not_ready';
    case OTHER = 'other';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case) => $case->value, self::cases());
    }

    public function requiresReason(): bool
    {
        return $this === self::OTHER;
    }
}
