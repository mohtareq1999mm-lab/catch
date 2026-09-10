# Phase 4: Promotion Lifecycle

## Executive Summary

Promotions are resolved at checkout time via a strategy pattern (Percentage, Fixed, Gift). Eligibility is evaluated read-only; outcomes are applied to cart items by the PromotionApplicator in a transaction with row locks. Consumption (increment of the `usage` counter) happens only after payment succeeds, guarded by a `promotion_consumed` flag on the order. Promotions are the **only** discount type that is reversed on cancellation — `decrementUsage()` is called when an order transitions to `cancelled`.

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
  ├─ removeGiftItems($cart)  ← releases previously reserved gifts
  ├─ Promotion::valid()->whereKey($promotionId)->lockForUpdate()
  ├─ resolver->resolve($cart, $promotion, $subtotalCents)  ← re-evaluate
  ├─ if DiscountOutcome:
  │    └─ applicator->applyOutcome($cart, $promotion, $discountOutcome)
  │         └─ DB::transaction
  │              ├─ lock promotion + cart + items
  │              ├─ re-evaluate matchedEligibility inside lock
  │              ├─ proportional allocation (largest remainder) across matched items
  │              ├─ sets cart_item.discount_amount, cart_item.promotion_id, cart_item.total_price
  │              └─ updates cart.total_price
  └─ if GiftOutcome:
       └─ applicator->applyOutcome($cart, $promotion, $giftOutcome)
            ├─ Product::lockForUpdate()
            └─ inventoryService->reserveGiftItem(...)
                 └─ creates cart_item with price=0, total_price=0, is_gift=true
```

### Discount Allocation Algorithm

Proportional allocation using **largest remainder** method:

1. For each matched item, compute exact fractional share: `(line_total_cents * amountCents) / sumLineCents`
2. Floor each share → initial allocation
3. Distribute remaining cents one-at-a-time to items with largest fractional remainder
4. Cap each allocation to the item's line total (no negative prices)
5. Persist: `item->discount_amount`, `item->total_price`, `item->promotion_id`

### Consumption: incrementUsage()

Called from `OrderService::finalizePromotionUsageAfterPayment()`:

```php
public function finalizePromotionUsageAfterPayment(Order $order): void
{
    if ($order->promotion_consumed) { return; }

    $promotionId = $order->promotion_id ? (int) $order->promotion_id : null;
    if ($promotionId) {
        $this->promotionService->incrementUsage($promotionId);
    }

    if (Schema::hasColumn('orders', 'promotion_consumed')) {
        $order->update(['promotion_consumed' => true]);
    }
}
```

`incrementUsage`:

```php
public function incrementUsage(?int $promotionId): void
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
}
```

Called from:
- `checkoutCallback` (online payment) — `OrderController:343`
- `markCodAsPaid` — `OrderService:640`
- `markCashierPaid` — `OrderService:684`

### Rollback on Cancellation: decrementUsage()

Called from `OrderService::changeOrderStatus()`:

```php
if ($status === 'cancelled' && $previousStatus !== 'cancelled') {
    $this->promotionService->decrementUsage($order->promotion_id ? (int) $order->promotion_id : null);
}
```

`decrementUsage`:

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

**This is the only discount that is reversed on cancel.** Coupons are NOT reversed.

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

### Gift Items: Pricing and Reservation

Gift items are created with `price=0` and `total_price=0`:

```php
$payload = [
    'product_id' => $product->id,
    'product_variant_id' => $variant?->id,
    'quantity' => $desiredQuantity,
    'reserved_quantity' => $desiredQuantity,
    'price' => 0,
    'total_price' => 0,
    'is_gift' => true,
    'promotion_id' => $promotion->id,
    ...
];
```

Reserved via `CartInventoryService::reserveGiftItem()` which uses `lockForUpdate` on the product/variant row. Stock is deducted from `reserved_quantity` at reservation time and finalized at payment.

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
| usage | int | Usage counter |
| minimum_order_amount | decimal nullable | |
| required_quantity_type | int nullable | Min quantity to qualify |
| status | boolean | |

### `promotion_product`

Pivot: `promotion_id`, `product_id`

### `promotion_gift_products`

Pivot: `promotion_id`, `product_id`, `quantity`, `product_variant_id`

### `orders` (relevant columns)

| Column | Type | Notes |
|---|---|---|
| promotion_id | bigint nullable | FK to promotions |
| promotion_code | varchar nullable | |
| promotion_type | varchar nullable | |
| promotion_discount | decimal nullable | |
| promotion_consumed | tinyint(1) | Guard flag (Schema::hasColumn-checked) |

---

## Problems

### P4-C1: promotion_consumed flag is schema-checked

The `promotion_consumed` column is read/written conditionally:

```php
if (Schema::hasColumn('orders', 'promotion_consumed')) {
    $order->update(['promotion_consumed' => true]);
}
```

If the column does not exist (e.g., migration not run, test database), the guard never gets set and `incrementUsage` would be called on every payment-related status change. The `$order->promotion_consumed` guard on line 247 reads `null`, which passes the `if` check.

**Location:** `OrderService:247-258`

### P4-C2: Gift inventory not tracked independently

Gift items consume real product inventory through `reserveGiftItem()`. There is no separate gift-inventory pool. If a product is both a sellable item and a gift product, selling it as a gift depletes sellable stock and vice versa. There is no dedicated "gift allocation" or separate stock tracking.

**Location:** `CartInventoryService::reserveGiftItem()` lines 80-164

### P4-C3: decrementUsage can go negative

The `decrementUsage` method has a guard (`where('usage', '>', 0)`) but does not use `max(0, ...)`. If `decrementUsage` is called when `usage` is 0, the `where('usage', '>', 0)` clause means no row is matched, so it is a no-op. This is correct for the current implementation but fragile — an edge case where the query builder's `decrement` bypasses the `where` clause would cause negative values.

**Location:** `PromotionService:189-201`

### P4-C4: Gift strategy relies on `product->available_stock` across multiple code paths

`GiftPromotionStrategy::hasAvailableStock()` checks `available_stock` which is an accessor computed as `stock_quantity - reserved_quantity`. If this accessor is inconsistent (e.g., cache or stale relation), an out-of-stock gift could be offered. This is a read-model concern: the check is a snapshot, not a lock.

---

## Production Recommendations

### R4-1: Make promotion_consumed a required column

Add a migration to ensure `promotion_consumed` on `orders` is always present and NOT NULL default false. Remove the `Schema::hasColumn` conditional. This makes the guard reliable.

### R4-2: Add gift inventory allocation pool

Consider tracking gift-specific allocation on promotions. A `promotion_gift_allocations` table could track how many gift units have been "spent" vs "reserved". Move inventory deduction for gifts to this table so sellable stock is not depleted by promotions.

### R4-3: Add regression test for decrementUsage floor

Write a test that calls `decrementUsage` when `usage` is 0 and verifies it remains 0 (not -1).

### R4-4: Promote afterCommit for promotion_consumed

Wrap the `promotion_consumed` flag update in `DB::afterCommit()` to ensure it only persists after the transaction commits, preventing a race window where the flag is visible before the increment is durable.

### R4-5: Add a `PromotionConsumed` event

Consider firing an event (similar to `AssignedCouponConsumed`) when a promotion's usage is incremented, carrying `remainingUses` for downstream monitoring.
