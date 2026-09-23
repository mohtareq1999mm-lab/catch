<?php

namespace App\Services\Coupon\Distribution\Messaging;

/**
 * Handler outcomes for CouponEventTransport::consume().
 */
final class ConsumeResult
{
    public const ACK = 1;
    public const RETRY = 2;
    public const DEAD_LETTER = 3;
    public const STOP = 4;
}
