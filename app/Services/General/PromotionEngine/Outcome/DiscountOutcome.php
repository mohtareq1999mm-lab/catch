<?php

declare(strict_types=1);

namespace App\Services\General\PromotionEngine\Outcome;

final class DiscountOutcome extends PromotionOutcome
{
    /** amount and baseAmount are expressed in integer cents */
    public function __construct(
        public readonly int $amountCents,
        public readonly int $baseAmountCents,
    ) {}
}
