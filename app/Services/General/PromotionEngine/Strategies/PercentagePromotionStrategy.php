<?php

declare(strict_types=1);

namespace App\Services\General\PromotionEngine\Strategies;

use App\Services\General\PromotionEngine\Contracts\PromotionStrategy;
use Marvel\Database\Models\Cart;
use Marvel\Database\Models\Promotion;
use App\Services\General\PromotionEngine\PromotionEvaluation;
use App\Services\General\PromotionEngine\Outcome\DiscountOutcome;
use App\Services\General\PromotionEngine\Outcome\PromotionOutcome;

class PercentagePromotionStrategy extends AbstractPromotionStrategy implements PromotionStrategy
{
    public function eligible(Promotion $promotion, Cart $cart, int $subtotal, PromotionEvaluation $evaluation): bool
    {
        return parent::eligible($promotion, $cart, $subtotal, $evaluation);
    }

    public function computeOutcome(Promotion $promotion, Cart $cart, int $subtotal, PromotionEvaluation $evaluation): PromotionOutcome
    {
        $amountDecimal = $promotion->discountAmount($evaluation->matchedSubtotalCents / 100.0, $evaluation->matchedQuantity);
        $amountCents = (int) round($amountDecimal * 100);
        return new DiscountOutcome($amountCents, $evaluation->matchedSubtotalCents);
    }
}
