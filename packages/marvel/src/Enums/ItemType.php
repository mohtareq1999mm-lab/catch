<?php

namespace Marvel\Enums;

use BenSampo\Enum\Enum;

/**
 * Class ItemType
 * Describes the nature of a product and its fulfillment behavior.
 * This is NOT the product structure type (simple/variable) stored in product_type.
 *
 * @package Marvel\Enums
 */
final class ItemType extends Enum
{
    public const PHYSICAL = 'PHYSICAL';
    public const DIGITAL = 'DIGITAL';
}
