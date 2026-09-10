<?php

declare(strict_types=1);

namespace App\Services\General\PromotionEngine;

use App\Services\General\PromotionEngine\Strategies\FixedPromotionStrategy;
use App\Services\General\PromotionEngine\Strategies\GiftPromotionStrategy;
use App\Services\General\PromotionEngine\Strategies\PercentagePromotionStrategy;
use App\Services\General\PromotionEngine\Outcome\GiftOutcome;
use Illuminate\Support\Collection;
use Marvel\Database\Models\Cart;
use Marvel\Database\Models\Promotion;
use Marvel\Enums\PromotionMountType;
use App\Services\General\PromotionEngine\PromotionEvaluation;

class PromotionEligibilityResolver
{
    /** @var array<string, mixed> */
    private array $strategies;

    public function __construct()
    {
        $this->strategies = [
            PromotionMountType::PERCENTAGE => app(PercentagePromotionStrategy::class),
            PromotionMountType::FIXED_RATE => app(FixedPromotionStrategy::class),
            PromotionMountType::GIFT => app(GiftPromotionStrategy::class),
        ];
    }

    public function eligible(Cart $cart, Collection $promotions, int $subtotalCents): Collection
    {
        return $promotions
            ->map(fn(Promotion $promotion) => $this->resolve($cart, $promotion, $subtotalCents))
            ->filter()
            ->values();
    }

    public function resolve(Cart $cart, Promotion $promotion, int $subtotalCents): ?PromotionResult
    {
        $strategy = $this->strategies[$promotion->type_amount] ?? null;

        if (!$strategy) {
            return null;
        }

        if (!$promotion->appliesToAllProducts() && $promotion->products->isEmpty()) {
            return null;
        }

        $evaluation = $this->matchedEligibility($cart, $promotion, $subtotalCents);

        if (!$strategy->eligible($promotion, $cart, $subtotalCents, $evaluation)) {
            return null;
        }

        // computeOutcome is read-only and does not mutate DB
        $outcome = $strategy->computeOutcome($promotion, $cart, $subtotalCents, $evaluation);

        $giftItems = [];
        if ($outcome instanceof GiftOutcome) {
            $giftItems = $outcome->giftItems;
        } elseif ($promotion->giftProducts->isNotEmpty()) {
            $giftOutcome = app(GiftPromotionStrategy::class)
                ->computeOutcome($promotion, $cart, $subtotalCents, $evaluation);

            if ($giftOutcome instanceof GiftOutcome) {
                $giftItems = $giftOutcome->giftItems;
            }
        }

        // Convert outcome into PromotionResult for backward compatibility consumers
        if ($outcome instanceof \App\Services\General\PromotionEngine\Outcome\DiscountOutcome) {
            return new PromotionResult($promotion, $outcome->amountCents / 100.0, $giftItems, $evaluation->matchedSubtotalCents);
        }

        if ($outcome instanceof \App\Services\General\PromotionEngine\Outcome\GiftOutcome) {
            return new PromotionResult($promotion, 0.0, $giftItems, $evaluation->matchedSubtotalCents);
        }

        return null;
    }

    public function matchedEligibility(Cart $cart, Promotion $promotion, int $subtotalCents): PromotionEvaluation
    {
        $requiredProductIds = $promotion->products->pluck('id')->map(fn($id) => (int) $id)->all();

        $matchedItems = $cart->items
            ->filter(function ($item) use ($promotion, $requiredProductIds) {
                if ((bool) ($item->is_gift ?? false)) {
                    return false;
                }

                if ($promotion->appliesToAllProducts()) {
                    return true;
                }

                return in_array((int) $item->product_id, $requiredProductIds, true);
            });

        $matchedQuantity = $matchedItems->sum(fn($item) => (int) $item->quantity);

        // Compute the matched subtotal from the original line value so a promotion
        // is not re-applied on an already discounted cart item total.
        $matchedSubtotalCents = $matchedItems->sum(function ($item) {
            $unitPrice = (float) ($item->price ?? 0);
            $quantity = (int) ($item->quantity ?? 0);
            $baseLineTotal = $unitPrice * $quantity;

            if ($baseLineTotal > 0) {
                return (int) round($baseLineTotal * 100);
            }

            return (int) round((float) ($item->total_price ?? 0) * 100);
        });

        // If promotion applies to all products, matchedSubtotal should reflect full subtotal
        if ($promotion->appliesToAllProducts()) {
            $matchedSubtotalCents = $subtotalCents;
        }

        return new PromotionEvaluation($matchedItems->values(), $matchedSubtotalCents, $matchedQuantity);
    }
}
