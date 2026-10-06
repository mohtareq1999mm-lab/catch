# Phase 4: Promotion Lifecycle

## Executive Summary

Promotions are resolved at checkout time via a strategy pattern (Percentage, Fixed, Gift). Eligibility is evaluated read-only; discount outcomes are applied to cart items by the PromotionApplicator in a transaction with row locks, while gift outcomes resolve to order-line descriptors that are materialized and inventory-reserved atomically with the Order. Consumption (increment of the `usage` counter) happens only after payment succeeds, guarded by the `promotion_consumed` flag on the order (NOT NULL, DEFAULT false). Promotion reversal on cancellation is CONDITIONAL (Rule 17 / ORD-1): unpaid cancellations decrement usage, paid cancellations keep it, and never-paid expiry cancellations skip the decrement entirely.

---

## Current Implementation

### Eligibility Resolution

```
Cart (with items)
  └─ PromotionService::eligiblePromotions($cart)
       └─ PromotionEligibilityResolver::eligible($cart, $promotions, $subtotalCents)
            └─ Per promotion: resolve($cart, $promotion, $subtotalCents)
                 ├─ matchedEligibility($cart, $promotion, $subtotalCents)
                 │    Returns PromotionEvaluation(matchedItems, matchedSubtotalCents, matchedQuantity)
                 │    - Filters out gift items
                 │    - If appliesToAllProducts() → matchedSubtotalCents = $subtotalCents
                 │    - Else → matchedItems only those in promotion->products
                 ├─ strategy->eligible($promotion, $cart, $subtotalCents, $evaluation)
                 │    Checks:
                 │      promotion->isValid() (status, dates, limiter)
                 │      matchedSubtotalCents >= minimum_order_amount
                 │      isRequiredQuantityTrue(matchedQuantity)
                 └─ strategy->computeOutcome(...)  ← read-only, no DB mutation
```

### Three Strategies

#### 1. PercentagePromotionStrategy

```php
class PercentagePromotionStrategy extends AbstractPromotionStrategy
{
    public function computeOutcome(...): PromotionOutcome
    {
        $amountDecimal = $promotion->discountAmount($matchedSubtotalCents / 100.0, $matchedQuantity);
        $amountCents = (int) round($amountDecimal * 100);
        return new DiscountOutcome($amountCents, $evaluation->matchedSubtotalCents);
    }
}
```

Uses `Promotion::discountAmount()` which computes `$price * ($discount / 100)` capped by `max_discount_amount`.

#### 2. FixedPromotionStrategy

```php
class FixedPromotionStrategy extends AbstractPromotionStrategy
{
    public function computeOutcome(...): PromotionOutcome
    {
        $amountDecimal = $promotion->discountAmount($matchedSubtotalCents / 100.0, $matchedQuantity);
        $amountCents = (int) round($amountDecimal * 100);
        return new DiscountOutcome($amountCents, $evaluation->matchedSubtotalCents);
    }
}
```

Same pattern, but `Promotion::discountAmount()` for fixed rate computes `min($price, $value)`.

#### 3. GiftPromotionStrategy

```php
class GiftPromotionStrategy extends AbstractPromotionStrategy
{
    public function eligible(...): bool
    {
        return parent::eligible(...) && $promotion->giftProducts->isNotEmpty();
    }

    public function computeOutcome(...): PromotionOutcome
    {
        // Maps giftProducts to GiftItem[] with price_cents=0
        // Checks available stock (simple product or variant)
        // Excludes out-of-stock gifts
        return new GiftOutcome($giftItems);
    }
}
```

### Application (PromotionService::applySelectedPromotion)

```
applySelectedPromotion($cart, $promotionId, $selectedGiftProductId, $shippingMethod)
  ├─ removeLegacyGiftRows($cart)  ← purges pre order-owned-reservation gift cart rows (no inventory release: carts never own reservations)
  ├─ Promotion::valid()->whereKey($promotionId)->lockForUpdate()
  ├─ resolver->resolve($cart, $promotion, $subtotalCents)  ← re-evaluate
  ├─ if DiscountOutcome (amountCents > 0):
  │    └─ applicator->applyOutcome($cart, $promotion, $discountOutcome)
  │         └─ DB::transaction
  │              ├─ lock promotion + cart + items
  │              ├─ re-evaluate matchedEligibility inside lock
  │              ├─ proportional allocation (largest remainder) across matched items
  │              ├─ sets cart_item.discount_amount, cart_item.promotion_id, cart_item.total_price
  │              └─ updates cart.total_price
  ├─ if GiftOutcome (giftItems non-empty):
  │    └─ resolveSelectedGiftItem(...) → ORDER-LINE DESCRIPTOR ONLY
  │         [{product_id, product_variant_id, quantity, promotion_id}]
  │         No cart write. No inventory reservation here — the gift line is
  │         created and reserved atomically with the Order during checkout
  │         (OrderCreationService + OrderReservationService).
  ├─ elseif $selectedGiftProductId given but no gift offered:
  │    └─ throw InvalidArgumentException('Selected gift product is not available for this promotion.') → 422, fail closed
  ├─ subtotal derived post-promotion (financial invariant: subtotal = finalTotal + promotionDiscount)
  └─ no promotion selected: clearPromotionFromCart (strip marks, restore line totals)
```

Invalid, expired, or ineligible selection throws `InvalidArgumentException` → 422. Fail closed, no silent fallback, no silent gift drop (loud gift failure).

### Discount Allocation Algorithm

Proportional allocation using **largest remainder** method:

1. For each matched item, compute exact fractional share: `(line_total_cents * amountCents) / sumLineCents`
2. Floor each share → initial allocation
3. Distribute remaining cents one-at-a-time to items with largest fractional remainder
4. Cap each allocation to the item's line total (no negative prices)
5. Persist: `item->discount_amount`, `item->total_price`, `item->promotion_id`

### Discount Stacking Precedence (F-03 explicit contract)

Discounts compose deterministically in exactly this order:

```text
Flash Sale
    ↓
Promotion
    ↓
Coupon
```

- Flash-sale pricing is embedded in the cart line prices BEFORE totals run (`OrderService::refreshCartItemPrices`); the post-flash price is the base. Promotion operates on that base.
- Promotion is applied next via `PromotionService::applySelectedPromotion`.
- Coupon is applied LAST on the remainder (`OrderService::calculateCheckoutTotals`: `calculatePriceByCoupon($cart, $priceAfterPromotion)`).
- Gift promotions carry discount 0 plus an order-line descriptor, so they never shrink the coupon base; the free gift rides alongside the monetary chain.
- Promotion/coupon stacking is deterministic, not configurable. Future discount types must not silently reorder this chain — change the contract explicitly (code + manual + pinning test) or not at all.

Pinned by `PromotionResidualHardeningTest::triple_stack_flash_sale_then_promotion_then_coupon_pins_final_amount` (flash-adjusted base 160 → fixed promotion 10 → remainder 150 → 10% coupon 15 → final 135) and `gift_promotion_does_not_shrink_the_coupon_base`.

### Consumption: incrementUsage()

Called from `OrderService::finalizePromotionUsageAfterPayment()`:

```php
public function finalizePromotionUsageAfterPayment(Order $order): void
{
    if ($order->promotion_consumed) { return; }

    $promotionId = $order->promotion_id ? (int) $order->promotion_id : null;
    if ($promotionId) {
        $counted = $this->promotionService->incrementUsage($promotionId);

        if (!$counted) {
            // F-06: limiter filled between apply and completion — the order
            // keeps its approved discount, but the uncounted grant is logged.
            Log::warning('promotion.usage.limiter_blocked', [
                'promotion_id' => $promotionId,
                'order_id' => $order->getKey(),
                'usage' => ..., 'limiter' => ...,
            ]);
        }
    }

    $order->update(['promotion_consumed' => true]);
}
```

`incrementUsage` (returns bool — true when the counter actually moved):

```php
public function incrementUsage(?int $promotionId): bool
{
    Promotion::query()
        ->whereKey($promotionId)
        ->where(function ($query) {
            $query->whereNull('limiter')
                ->orWhereColumn('usage', '<', 'limiter');
        })
        ->lockForUpdate()
        ->first()
        ?->increment('usage');

    return (bool) $moved;
}
```

Completion funnel (all routes converge on `finalizePromotionUsageAfterPayment`, guarded once by `promotion_consumed`):

- Online payment callback → `PaymentCompletionService::commitLocked` → finalize + `changeOrderStatus(..., 'completed')` (idempotent; `promotion_consumed` early-returns on retry/duplicate callbacks).
- COD / cashier mark-paid → `changeOrderStatus(..., 'completed')` → finalize.
- Zero-value online orders → canonical completed transition → finalize.

### Conditional Reversal on Cancellation: decrementUsage() (Rule 17 / ORD-1)

Called from `OrderService::changeOrderStatus()`:

```php
if ($status === 'cancelled' && $previousStatus !== 'cancelled') {
    // ... inventory restore/release keyed on inventory_state ...

    // Only decrement promotion usage for unpaid cancellations (Rule 17).
    // Paid orders that are cancelled must NOT decrement promotion usage
    // as the promotion benefit was already delivered and consumed.
    // D3: the system expiry command (orders:cancel-unpaid) passes
    // $skipPromotionDecrement for never-paid expiry cancels (ORD-1).
    if (!$skipPromotionDecrement && $order->payment_status !== Order::PAYMENT_STATUS_SUCCESS) {
        $this->promotionService->decrementUsage($order->promotion_id ? (int) $order->promotion_id : null);
    }
}
```

| Cancel case | Promotion usage |
|---|---|
| Unpaid cancellation | Decremented where applicable (floored at 0) |
| Paid cancellation | Kept — benefit was delivered and consumed |
| Never-paid expiry cancellation (`orders:cancel-unpaid`, `skipPromotionDecrement: true`) | Untouched |

**Promotion is the only discount type reversed on cancel, and only for unpaid orders.** Coupons are NOT reversed.

`decrementUsage` (floored — can never go negative, pinned by `PromotionResidualHardeningTest::decrement_usage_at_zero_never_goes_negative`):

```php
public function decrementUsage(?int $promotionId): void
{
    if (!$promotionId) { return; }

    Promotion::query()
        ->whereKey($promotionId)
        ->where('usage', '>', 0)
        ->lockForUpdate()
        ->first()
        ?->decrement('usage');
}
```

### Expiration Check at Checkout

The `Promotion::valid()` scope is used wherever promotions are fetched:

```php
public function scopeValid($query)
{
    return $query
        ->where('status', true)
        ->where(function ($q) {
            $q->whereNull('limiter')->orWhereColumn('usage', '<', 'limiter');
        })
        ->where(function ($q) {
            $q->whereNull('start_at')->orWhereDate('start_at', '<=', today());
        })
        ->where(function ($q) {
            $q->whereNull('end_at')->orWhereDate('end_at', '>=', today());
        });
}
```

### Gift Items: Descriptor → Order Line → Atomic Reservation

Gift promotions resolve to ORDER-LINE DESCRIPTORS only (`PromotionService::applySelectedPromotion`):

```php
$giftDetails = [
    'discount' => 0.0,
    'gift_items' => [[
        'product_id' => $selectedGiftItem->productId,
        'product_variant_id' => $selectedGiftItem->productVariantId,
        'quantity' => max(1, (int) $selectedGiftItem->quantity),
        'promotion_id' => $promotion->id,
    ]],
];
```

The descriptor travels in `CheckoutTotals->giftItems` → `OrderCreationService::createOrderItems` materializes the gift order line (`price=0`, `total_price=0`, `is_gift=true`, legacy gift cart rows are never snapshotted) → `OrderReservationService::reserveForOrder` reserves its stock together with the order's other physical lines, in the same checkout transaction.

There are no cart gift rows, no `reserveGiftItem`, no `reserved_quantity` on gifts outside the shared pool, and no finalize-at-payment step for gifts. Gift inventory and normal sellable inventory share the same stock pool (`stock_quantity` / `reserved_quantity` on products and variants) — by design.

Gift availability is therefore a TWO-STAGE check by design:

1. Apply-time snapshot (`GiftPromotionStrategy::hasAvailableStock`) — the gift must look available to be offered.
2. Order-time atomic reservation (`OrderReservationService`) — the authority; oversell is impossible.

Stock lost between the two stages aborts the whole checkout transaction (no order, no reservation, cart intact) and surfaces as the gift-specific 422 via `PromotionService::throwIfGiftUnavailable` (F-04): the failed gift is detected by re-reading the gift rows under lock, converted to `Selected gift product is not available for this promotion.`, and the transaction rolls back normally. Shortages on non-gift lines keep the generic stock error. Pinned by `PromotionResidualHardeningTest::gift_stock_lost_before_reservation_fails_with_gift_error_and_nothing_commits`.

### Limiter-Fill Accounting (F-06 observable trade-off)

If the global limiter fills between apply and completion, the guarded `incrementUsage` matches no row while `promotion_consumed` still sets: the customer keeps an approved discount that is never counted. This customer-favoring behavior is preserved deliberately (no revalidation at completion — a completed order never loses its promotion). Since hardening, the event is observable: `Log::warning('promotion.usage.limiter_blocked', [promotion_id, order_id, usage, limiter])`. No payment data is logged; nothing is exposed to the customer. Pinned by `PromotionResidualHardeningTest::limiter_filled_before_completion_keeps_discount_and_logs` (and the no-warning control `normal_completion_increments_without_limiter_warning`).

### Ending-Soon Notifier

`promotions:notify-ending-soon` (daily): finds active promotions expiring within 24h that lack the `ending_soon_notified_at` stamp, fans out to wishlist users of the promotion's products chunked (500), and stamps `ending_soon_notified_at` for idempotency. Informational only — never affects eligibility or usage.

### PromotionObserver + PromotionActivated

`PromotionObserver` records audit entries (created / statusChanged / updated / deleted on tracked fields) and fires `PromotionActivated` when a promotion is created active or transitions false→true. Downstream user notifications (availability / price-drop / ending-soon) listen on this event. No `PromotionConsumed` event exists by design — completion observability is the F-06 log above.

---

## Database Tables

### `promotions`

| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| name | json (translatable) | |
| code | varchar | Auto-generated prefix: ALL_ or PRO_ |
| type_amount | varchar | `percentage`, `fixed_rate`, `gift` (`PromotionMountType`) |
| discount | decimal | Syncs with `value` column |
| value | decimal | Syncs with `discount` column |
| max_discount_amount | decimal nullable | Cap for percentage type |
| type | varchar nullable | Legacy type field |
| apply_to | varchar | `all_products` or `specific_products` |
| start_at | date nullable | |
| end_at | date nullable | |
| limiter | int nullable | Max total uses |
| usage | int | Usage counter (sole writers: `incrementUsage` / `decrementUsage`) |
| minimum_order_amount | decimal nullable | |
| required_quantity_type | int nullable | Min quantity to qualify |
| status | boolean | |
| ending_soon_notified_at | timestamp nullable | Idempotency stamp for the ending-soon notifier |

### `promotion_product`

Pivot: `promotion_id`, `product_id`

### `promotion_gift_products`

Pivot: `promotion_id`, `product_id`, `quantity`, `product_variant_id`

### `orders` (relevant columns)

| Column | Type | Notes |
|---|---|---|
| promotion_id | bigint nullable | FK to promotions |
| promotion_code | varchar nullable | Snapshot |
| promotion_type | varchar nullable | Snapshot |
| promotion_discount | decimal nullable | Snapshot |
| promotion_consumed | tinyint(1) NOT NULL DEFAULT false | Idempotency guard for post-payment consumption (unconditional write; the old nullable/`Schema::hasColumn` concern is retired) |

---

## Problems

### P4-C1: promotion_consumed flag — RETIRED

The column is NOT NULL DEFAULT false (migration `2026_07_27_081603`) and is now written unconditionally. The old nullable/`Schema::hasColumn` concern is retired (a rolling-deploy guard remains in code and is harmless).

### P4-C2: Gift inventory not tracked independently — BY DESIGN

Gift items consume real product inventory through the shared pool. There is no separate gift-inventory pool and none is planned: a product that is both sellable and a gift draws from the same `stock_quantity` / `reserved_quantity`. Atomic order-time reservation makes oversell impossible; availability drift between snapshot and claim is handled by the F-04 gift-specific failure.

### P4-C3: decrementUsage floor — VERIFIED + PINNED

The `where('usage', '>', 0)` guard makes zero-usage decrement a no-op. Pinned by `PromotionResidualHardeningTest::decrement_usage_at_zero_never_goes_negative` — usage can never go negative through the supported service path.

### P4-C4: Gift availability two-moment check — SAFE, PRECISE ERROR SINCE HARDENING

The snapshot (apply) + atomic claim (order reservation) structure remains, as designed. The former generic stock error on a lost gift is now the gift-specific 422 (`PromotionService::throwIfGiftUnavailable`, called from all three checkout reservation sites). No second reservation path, no weakened locks, general stock errors unchanged.

---

## Production Recommendations

### R4-1: Make promotion_consumed a required column — DONE

Column is NOT NULL DEFAULT false; written unconditionally.

### R4-2: Add gift inventory allocation pool — DECLINED

Shared pool is the approved design (see P4-C2). No separate gift stock system.

### R4-3: Add regression test for decrementUsage floor — DONE

`PromotionResidualHardeningTest::decrement_usage_at_zero_never_goes_negative`.

### R4-4: Promote afterCommit for promotion_consumed — EVALUATED, NOT ADOPTED

The flag is intentionally set in the same unit as the increment: completion and consumption roll back together, which is correct. No afterCommit split.

### R4-5: Add a `PromotionConsumed` event — DECLINED in favor of F-06 observability

Completion observability is the structured `promotion.usage.limiter_blocked` log (only emitted when the increment is actually blocked), not a per-completion event. No new event bus.
