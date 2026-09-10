<?php

namespace App\Enums;

enum UserType: string
{
    case ADMIN = 'admin';
    case USER = 'user';

    public static function values(): array
    {
        return array_map(fn(self $case) => $case->value, self::cases());
    }
}
