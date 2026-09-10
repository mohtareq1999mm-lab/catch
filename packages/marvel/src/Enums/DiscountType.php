<?php


namespace Marvel\Enums;

use BenSampo\Enum\Enum;

/**
 * Class RoleType
 * @package App\Enums
 */
final class DiscountType extends Enum
{
    public const PERCENTAGE = 'percentage';
    public const FIXED_RATE = 'fixed_rate';
    public const FREE_SHIPPING = 'free_shipping';
}
