<?php

namespace App\Enums;

enum EligibilityRuleType: string
{
    // Phase 1: Exactly 13 whitelisted rules (NO demographics, NO refund-based)

    // Order-based rules
    case MIN_COMPLETED_ORDERS = 'min_completed_orders';
    case MAX_COMPLETED_ORDERS = 'max_completed_orders';
    case MIN_TOTAL_SPEND = 'min_total_spend';
    case MAX_TOTAL_SPEND = 'max_total_spend';

    // Time-based rules
    case FIRST_ORDER_AFTER = 'first_order_after';
    case FIRST_ORDER_BEFORE = 'first_order_before';
    case LAST_ORDER_AFTER = 'last_order_after';
    case LAST_ORDER_BEFORE = 'last_order_before';

    // Coupon usage rules
    case MIN_COUPONS_USED = 'min_coupons_used';
    case MAX_COUPONS_USED = 'max_coupons_used';

    // Claim-based rules
    case NOT_CLAIMED = 'not_claimed';
    case CLAIMED = 'claimed';

    // Assignment-based rule
    case HAS_ASSIGNMENT = 'has_assignment';
}
