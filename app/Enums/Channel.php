<?php

namespace App\Enums;

enum Channel: string
{
    case HOME = 'home';
    case FAST_SHIPPING = 'fast-shipping';

    public static function values(): array
    {
        return array_map(fn(self $case) => $case->value, self::cases());
    }

    public static function isValid(?string $value): bool
    {
        if ($value === null) {
            return true;
        }

        return in_array($value, self::values(), true);
    }
}
