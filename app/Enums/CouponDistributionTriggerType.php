<?php

namespace App\Enums;

enum CouponDistributionTriggerType: string
{
    // NOTE: no COUPON_CREATED case by design. Creation-time activation is
    // covered by COUPON_ACTIVATED (observer fires it for created-active
    // coupons); the migration enum keeps a reserved 'coupon_created'
    // value for forward compatibility only.
    case COUPON_ACTIVATED = 'coupon_activated';
    case TARGETING_CHANGED = 'targeting_changed';
    case USER_REGISTERED = 'user_registered';
    case ADDRESS_CHANGED = 'address_changed';
    case ORDER_COMPLETED = 'order_completed';
    case MANUAL = 'manual';
}
