<?php

namespace App\Exceptions;

use InvalidArgumentException;

/**
 * Business rejection from the guest cart lifecycle (S12).
 *
 * Carries a machine-readable reason alongside the human message so the
 * controller can add `data.reason` without changing status codes or
 * localized text. Allowed values are the closed set approved in S12.
 */
class GuestCartRejectionException extends InvalidArgumentException
{
    public const PRODUCT_UNAVAILABLE = 'product_unavailable';
    public const DIGITAL_NOT_SUPPORTED = 'digital_not_supported';
    public const FAST_SHIPPING_NOT_SUPPORTED = 'fast_shipping_not_supported';
    public const VARIANT_REQUIRED = 'variant_required';
    public const VARIANT_UNAVAILABLE = 'variant_unavailable';
    public const INSUFFICIENT_STOCK = 'insufficient_stock';
    public const INVALID_ITEM_DATA = 'invalid_item_data';
    public const INVALID_OPERATION = 'invalid_operation';

    public function __construct(private string $reason, string $message)
    {
        parent::__construct($message);
    }

    public function reason(): string
    {
        return $this->reason;
    }
}
