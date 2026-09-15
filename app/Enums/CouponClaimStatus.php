<?php

namespace App\Enums;

enum CouponClaimStatus: string
{
    case ACTIVE = 'active';
    case EXPIRED = 'expired';
    case REDEEMED = 'redeemed';
}
