<?php

namespace App\Enums;

enum CouponDistributionRecipientStatus: string
{
    case DISCOVERED = 'discovered';
    case ELIGIBLE = 'eligible';
    case NOT_ELIGIBLE = 'not_eligible';
    case NOTIFIED = 'notified';
    case FAILED_RETRYABLE = 'failed_retryable';
    case FAILED_PERMANENT = 'failed_permanent';
    case DUPLICATE_SKIPPED = 'duplicate_skipped';
}
