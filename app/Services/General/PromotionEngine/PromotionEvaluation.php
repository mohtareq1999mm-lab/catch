<?php

declare(strict_types=1);

namespace App\Services\General\PromotionEngine;

use Illuminate\Support\Collection;

/**
 * PromotionEvaluation is an immutable DTO produced by the resolver.
 * It represents which cart items matched the promotion scope and provides
 * the matched subtotal and matched quantity used for eligibility and calculation.
 */
final class PromotionEvaluation
{
    public Collection $matchedItems;
    /** matched subtotal in cents */
    public int $matchedSubtotalCents;
    public int $matchedQuantity;

    public function __construct(Collection $matchedItems, int $matchedSubtotalCents, int $matchedQuantity)
    {
        $this->matchedItems = $matchedItems;
        $this->matchedSubtotalCents = $matchedSubtotalCents;
        $this->matchedQuantity = $matchedQuantity;
    }
}
