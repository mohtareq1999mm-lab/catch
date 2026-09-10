<?php

declare(strict_types=1);

namespace App\Services\General\PromotionEngine\Strategies;

use Marvel\Database\Models\Cart;
use Marvel\Database\Models\Promotion;
use App\Services\General\PromotionEngine\PromotionEvaluation;

abstract class AbstractPromotionStrategy
{
    // subtotal cents
    public function eligible(Promotion $promotion, Cart $cart, int $subtotal, PromotionEvaluation $evaluation): bool
    {
        if (!$promotion->isValid()) {
            return false;
        }

        // Eligibility should be based on matched subtotal (scope of the promotion)
        $minimumCents = (int) round(((float) ($promotion->minimum_order_amount ?? 0)) * 100);
        if ($evaluation->matchedSubtotalCents < $minimumCents) {
            return false;
        }

        return $promotion->isRequiredQuantityTrue($evaluation->matchedQuantity);
    }
}
