<?php

namespace Marvel\Enums;

use BenSampo\Enum\Enum;

final class PromotionMountType extends Enum
{
    public const FIXED_RATE = 'fixed_rate';
    public const PERCENTAGE = 'percentage';
    public const GIFT = 'gift';
}
