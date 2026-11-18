<?php

namespace App\Enums;

/**
 * Closed reason set for business 422 rejections on guest cart writes (S12).
 *
 * The value is the machine-readable `data.reason`; the localized message
 * travels separately so text and code never drift.
 */
enum GuestCartRejectionReason: string
{
    case PRODUCT_UNAVAILABLE = 'product_unavailable';
    case DIGITAL_NOT_SUPPORTED = 'digital_not_supported';
    case FAST_SHIPPING_NOT_ELIGIBLE = 'fast_shipping_not_eligible';
    case FAST_SHIPPING_NOT_SUPPORTED = 'fast_shipping_not_supported';
    case VARIANT_REQUIRED = 'variant_required';
    case VARIANT_UNAVAILABLE = 'variant_unavailable';
    case INSUFFICIENT_STOCK = 'insufficient_stock';
    case INVALID_ITEM_DATA = 'invalid_item_data';
    case INVALID_OPERATION = 'invalid_operation';
}
