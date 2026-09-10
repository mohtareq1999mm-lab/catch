<?php

declare(strict_types=1);

namespace App\Services\General\PromotionEngine;

use App\Services\General\PromotionEngine\Outcome\DiscountOutcome;
use App\Services\General\PromotionEngine\Outcome\PromotionOutcome;
use Marvel\Database\Models\Promotion;
use App\Services\General\PromotionEngine\PromotionEligibilityResolver;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Cart;

/**
 * Applies DISCOUNT outcomes to cart line pricing.
 *
 * Gift promotions are intentionally NOT handled here anymore: they resolve to
 * order-line descriptors (see PromotionService::applySelectedPromotion) and are
 * materialized + inventory-reserved atomically with the Order at checkout.
 */
class PromotionApplicator
{
    public function __construct(private PromotionEligibilityResolver $resolver) {}

    /**
     * Apply a computed outcome to the cart in a safe transaction.
     * Returns array with applied discount and reserved gift item ids.
     *
     * @return array{discount:float, gift_items:array}
     */
    public function applyOutcome(
        Cart $cart,
        Promotion $promotion,
        PromotionOutcome $outcome,
        ?string $shippingMethod = null,
    ): array {
        return DB::transaction(function () use ($cart, $promotion, $outcome, $shippingMethod) {
            // lock promotion to protect limiter / usage counts
            $lockedPromotion = Promotion::whereKey($promotion->id)->lockForUpdate()->first();
            if (!$lockedPromotion) {
                throw new \InvalidArgumentException('Promotion no longer available.');
            }

            // Lock cart and items to avoid concurrent modifications
            $cart = Cart::whereKey($cart->id)->lockForUpdate()->with(['items'])->firstOrFail();

            // compute current subtotal in cents from original prices (exclude gift items)
            $subtotalCents = (int) round($cart->items->reject(fn($i) => (bool) ($i->is_gift ?? false))->sum(fn($i) => ((float) ($i->price ?? 0)) * ((int) ($i->quantity ?? 0))) * 100);

            // re-evaluate matched items at apply-time
            $evaluation = $this->resolver->matchedEligibility($cart, $promotion, $subtotalCents);

            if ($outcome instanceof DiscountOutcome) {
                $amountCents = min($subtotalCents, $outcome->amountCents);
                $baseCents = max(0, $outcome->baseAmountCents);

                if ($amountCents <= 0 || $baseCents <= 0) {
                    return ['discount' => 0.0, 'gift_items' => []];
                }

                $matchedItems = $evaluation->matchedItems; // collection of cart item models

                // prepare line totals in cents
                $lines = $matchedItems->map(function ($item) {
                    $baseLineTotal = ((float) ($item->price ?? 0)) * ((int) ($item->quantity ?? 0));
                    $lineTotalCents = (int) round((($baseLineTotal > 0 ? $baseLineTotal : (float) ($item->total_price ?? 0))) * 100);
                    return [
                        'item' => $item,
                        'line_total_cents' => $lineTotalCents,
                    ];
                })->values();

                $sumLineCents = $lines->sum(fn($l) => $l['line_total_cents']);
                if ($sumLineCents <= 0) {
                    return ['discount' => 0.0, 'gift_items' => []];
                }

                // proportional allocation using largest remainder
                $allocations = [];
                $allocatedSum = 0;
                $remainders = [];

                foreach ($lines as $index => $entry) {
                    $line = $entry['line_total_cents'];
                    // exact fractional share as float
                    $exactShare = ($line * $amountCents) / $sumLineCents;
                    $floorShare = (int) floor($exactShare);
                    $allocations[$index] = min($floorShare, $line); // cap to line total
                    $allocatedSum += $allocations[$index];
                    $remainders[$index] = $exactShare - $floorShare;
                }

                $remaining = $amountCents - $allocatedSum;
                if ($remaining > 0) {
                    // distribute by largest remainder, respecting caps
                    arsort($remainders);
                    foreach ($remainders as $idx => $rem) {
                        if ($remaining <= 0) break;
                        $available = $lines[$idx]['line_total_cents'] - $allocations[$idx];
                        if ($available <= 0) continue;
                        $give = min($available, 1); // distribute one cent at a time
                        $allocations[$idx] += $give;
                        $remaining -= $give;
                    }
                }

                // Final pass: ensure no negative totals and persist allocations
                foreach ($lines as $index => $entry) {
                    $item = $entry['item'];
                    $lineTotalCents = $entry['line_total_cents'];
                    $alloc = $allocations[$index] ?? 0;
                    $alloc = max(0, min($alloc, $lineTotalCents));

                    // compute new total price after discount
                    $newTotalPrice = ($lineTotalCents - $alloc) / 100.0;

                    // persist discount allocation and promotion id on the cart item
                    $item->forceFill([
                        'promotion_id' => $promotion->id,
                        // store discount_amount as decimal for compatibility
                        'discount_amount' => number_format($alloc / 100.0, 2, '.', ''),
                        'total_price' => number_format($newTotalPrice, 2, '.', ''),
                    ])->save();
                }

                // update cart total price after allocations using the freshly calculated line totals
                $discountedSubtotalCents = 0;
                foreach ($lines as $index => $entry) {
                    $lineTotalCents = $entry['line_total_cents'];
                    $alloc = $allocations[$index] ?? 0;
                    $discountedSubtotalCents += max(0, $lineTotalCents - $alloc);
                }

$cart->forceFill(['total_price' => round($discountedSubtotalCents / 100.0, 2)])->save();

                return ['discount' => $amountCents / 100.0, 'gift_items' => []];
            }

            return ['discount' => 0.0, 'gift_items' => []];
        });
    }
}
