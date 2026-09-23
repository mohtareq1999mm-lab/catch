<?php

namespace App\Enums;

enum CouponEventStatus: string
{
    case PUBLISHED = 'published';
    case PROCESSING = 'processing';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
    case RETRYING = 'retrying';
    case DEAD_LETTERED = 'dead_lettered';
}
