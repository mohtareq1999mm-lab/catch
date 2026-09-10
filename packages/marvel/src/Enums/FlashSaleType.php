<?php


namespace Marvel\Enums;

use BenSampo\Enum\Enum;

/**
 * Class RoleType
 * @package App\Enums
 */
final class FlashSaleType extends Enum
{
    public const PERCENTAGE = 'percentage';
    public const FIXED_RATE = 'fixed_rate';

    public const FINAL_PRICE = "final_price";
    // public const FREE_SHIPPING = 'free_shipping';
    // public const WALLET_POINT_GIFT = 'wallet_point_gift';
}
