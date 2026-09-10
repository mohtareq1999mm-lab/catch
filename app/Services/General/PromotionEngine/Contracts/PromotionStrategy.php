<?php

declare(strict_types=1);

namespace App\Services\General\PromotionEngine\Contracts;

use Marvel\Database\Models\Cart;
use Marvel\Database\Models\Promotion;
use App\Services\General\PromotionEngine\PromotionEvaluation;
use App\Services\General\PromotionEngine\Outcome\PromotionOutcome;

interface PromotionStrategy
{
    // Determine eligibility using the evaluation (read-only)
    // `subtotal` provided in integer cents
    public function eligible(Promotion $promotion, Cart $cart, int $subtotalCents, PromotionEvaluation $evaluation): bool;

    // Compute read-only outcome (DiscountOutcome or GiftOutcome). Amounts in outcomes must be integer cents.
    public function computeOutcome(Promotion $promotion, Cart $cart, int $subtotalCents, PromotionEvaluation $evaluation): PromotionOutcome;
}
