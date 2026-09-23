<?php

namespace App\Enums;

enum CouponDistributionUserState: string
{
    case ELIGIBLE = 'eligible';
    case NOT_ELIGIBLE = 'not_eligible';
    case NOTIFY_PENDING = 'notify_pending';
    case NOTIFIED = 'notified';
}
