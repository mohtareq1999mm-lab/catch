<?php

namespace App\Enums;

enum CouponOutboxStatus: string
{
    case PENDING = 'pending';
    case PUBLISHING = 'publishing';
    case PUBLISHED = 'published';
    case FAILED = 'failed';
}
