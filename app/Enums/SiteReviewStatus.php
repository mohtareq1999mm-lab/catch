<?php

namespace App\Enums;

enum SiteReviewStatus: string
{
    case PENDING = 'pending';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';

    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
