# Promotion Lifecycle Audit

## Table of Contents

1. [Promotion Domain Model](#1-promotion-domain-model)
2. [Promotion Types](#2-promotion-types)
3. [Promotion Creation](#3-promotion-creation)
4. [Eligibility & Validation](#4-eligibility--validation)
5. [Promotion Resolution](#5-promotion-resolution)
6. [Application to Cart](#6-application-to-cart)
7. [Checkout Flow](#7-checkout-flow)
8. [Usage Recording](#8-usage-recording)
9. [Promotion Deactivation](#9-promotion-deactivation)
10. [Expiration](#10-expiration)
11. [Key State Transitions](#11-key-state-transitions)
12. [Bugs Found](#12-bugs-found)

---

## 1. Promotion Domain Model

### Promotion (packages/marvel/src/Database/Models/Promotion.php)

| Field | Type | Description |
|-------|------|-------------|
| `id` | bigint PK | |
| `name` | json (translatable) | |
| `slug` | string | Auto-generated from name |
| `type` | string | Legacy? Seems unused |
| `type_amount` | enum: percentage, fixed_rate, gift | **The primary type discriminator** |
| `value` | float | Discount value (synced with `discount`) |
| `discount` | float | Discount value (synced with `value`) |
| `max_discount_amount` | float, nullable | Cap for percentage promotions |
| `code` | string | Auto-generated: `ALL_RANDOM10` or `PRO_RANDOM10` |
| `required_quantity_type` | integer, nullable | Minimum quantity required |
| `minimum_order_amount` | float, nullable | Minimum order amount required |
| `apply_to` | string: `all_products` or `specific_products` | Scope |
| `limiter` | integer, nullable | Max total uses |
| `usage` | integer | Current usage count |
| `start_at` | date, nullable | Active from |
| `end_at` | date, nullable | Active until |
| `status` | boolean | Enabled/disabled |

### Relations

```php
public function products()      // BelongsToMany(Product::class, 'promotion_product')
public function giftProducts()  // BelongsToMany(Product::class, 'promotion_gift_products')->withPivot('quantity', 'product_variant_id')
```

### Scopes

```php
public function scopeValid($query) {
    // status = true
    // limiter is null or usage < limiter
    // start_at is null or start_at <= today
    // end_at is null or end_at >= today
}
```

### Auto-generated Code

```php
static::creating(function ($promotion) {
    if (empty($promotion->code)) {
        $promotion->code = self::generateUniqueCode($promotion);
    }
});
// Prefix: 'ALL_' for all_products, 'PRO_' for specific_products
```

### Value/Discount Sync

```php
static::saving(function ($promotion) {
    // Keeps discount and value in sync
    if ($promotion->discount !== null && ($promotion->value === null || !$promotion->isDirty('value'))) {
        $promotion->value = $promotion->discount;
    }
    if ($promotion->value !== null && ($promotion->discount === null || !$promotion->isDirty('discount'))) {
        $promotion->discount = $promotion->value;
    }
});
```

---

## 2. Promotion Types

| Type | Enum Value | Discount Calculation | Strategy Class |
|------|-----------|---------------------|----------------|
| **Percentage** | `percentage` | `price * (discount / 100)`, capped at `max_discount_amount` | `PercentagePromotionStrategy` |
| **Fixed Rate** | `fixed_rate` | `min(price, discount)` | `FixedPromotionStrategy` |
| **Gift** | `gift` | No monetary discount. Adds free product(s) to cart. | `GiftPromotionStrategy` |

### Built-in Method: `discountAmount()`

```php
public function discountAmount(float $price, int $qty = 1): float {
    if (!$this->isRequiredQuantityTrue($qty)) return 0.0;
    
    $value = (float) ($this->discount ?? $this->value);
    $maxValue = $this->max_discount_amount;
    
    if ($this->isPercentagePromotion()) {
        $discount = $price * ($value / 100);
        if ($maxValue !== null) $discount = min($discount, $maxValue);
        return round(max(0.0, $discount), 2);
    }
    if ($this->isFixedRatePromotion()) {
        return round(max(0.0, min($price, $value)), 2);
    }
    // Gift promotion: returns 0.0
    return 0.0;
}
```

---

## 3. Promotion Creation

### Admin Endpoint

Promotions are created via admin endpoints (Marvel's PromotionController or GraphQL).

### Code Generation

Auto-generated based on scope:
- `all_products` → prefix `ALL_` + 10 random uppercase chars
- `specific_products` → prefix `PRO_` + 10 random uppercase chars

### Key Finding

1. **`value` and `discount` are duplicated fields** — The boot listener keeps them in sync. This is a legacy artifact. Any code that writes to one should be consistent, but the `saving` hook ensures they match.

---

## 4. Eligibility & Validation

### Validation Chain

```
PromotionService::applySelectedPromotion()
  → PromotionEligibilityResolver::resolve()
    → Promotion::valid() scope check (status, dates, limiter)
    → hasProducts() check (if specific_products and no products attached → reject)
    → PromotionEligibilityResolver::matchedEligibility()
      → Match cart items against promotion scope
      → If all_products: all non-gift items match
      → If specific_products: only items with product_id in promotion.products match
    → Strategy::eligible()
      → AbstractPromotionStrategy::eligible()
        → promotion.isValid() (status, dates, limiter)
        → matchedSubtotalCents >= minimum_order_amount (in cents)
        → required_quantity_type check
    → Strategy::computeOutcome()
      → Returns read-only outcome (DiscountOutcome or GiftOutcome)
```

### Eligibility Checks Summary

| Check | Where | Description |
|-------|-------|-------------|
| Status = true | `Promotion::valid()` scope | Global scope |
| Date range | `Promotion::valid()` scope | start_at <= today <= end_at |
| Global usage limit | `Promotion::valid()` scope | usage < limiter (or limiter is null) |
| Has products (if specific) | `resolve()` | promotion.products is not empty |
| Minimum order amount | `AbstractPromotionStrategy::eligible()` | matchedSubtotal >= minimum_order_amount (in cents) |
| Minimum quantity | `AbstractPromotionStrategy::eligible()` | matchedQuantity >= required_quantity_type |
| Matched items exist | Implicit | matchedItems must not be empty |

### Key Finding

2. **Minimum order amount is compared in cents** — The promotion's `minimum_order_amount` (stored as decimal) is converted to cents via `(int) round($amount * 100)` and compared against `matchedSubtotalCents` which is also in cents. This is internally consistent.
3. **`isValid()` is called twice** — Once via the `Promotion::valid()` SQL scope (in `applySelectedPromotion`) and again by `AbstractPromotionStrategy::eligible()`. Slight race condition window between SQL check and PHP re-check, but since the promotion row is locked later in `PromotionApplicator::applyOutcome()`, this is acceptable.

---

## 5. Promotion Resolution

### Strategy Resolution

`PromotionEligibilityResolver` maps `type_amount` to strategy:

```php
$this->strategies = [
    PromotionMountType::PERCENTAGE => PercentagePromotionStrategy,
    PromotionMountType::FIXED_RATE => FixedPromotionStrategy,
    PromotionMountType::GIFT => GiftPromotionStrategy,
];
```

### PromotionEvaluation

Created by `matchedEligibility()`:

```php
class PromotionEvaluation {
    public Collection $matchedItems;     // Items eligible for this promotion
    public int $matchedSubtotalCents;    // Sum of matched item line totals in cents
    public int $matchedQuantity;         // Sum of matched item quantities
}
```

### PromotionResult

Created by `resolve()`:

```php
class PromotionResult {
    public Promotion $promotion;
    public float $discount;              // Discount amount in decimal
    public array $giftItems;             // Gift items from GiftOutcome
    public int $matchedSubtotalCents;    // For proportional allocation
}
```

### Key Finding

4. **For `appliesToAllProducts()`, matchedSubtotalCents = full subtotal** — The `matchedEligibility()` method overrides the computed matched subtotal with the full cart subtotal when the promotion applies to all products. This means all-Products promotions calculate discounts against the entire cart.
5. **Gift items are also resolved for non-gift promotions** — If a percentage/fixed promotion has `giftProducts` attached, the resolver also computes a `GiftOutcome` for them and includes gift items in the result. This allows a promotion to be "buy X, get Y free" where X is discounted and Y is a gift.

---

## 6. Application to Cart

### `PromotionService::applySelectedPromotion()`

1. Remove existing gift items from cart (`removeGiftItems()`)
2. Load cart items with product/variant relations
3. Get promotion with `lockForUpdate` (row lock)
4. Resolve promotion eligibility (read-only)
5. If discount > 0: create `DiscountOutcome`, call `appplicator::applyOutcome()`
6. If gift items: resolve selected gift, create `GiftOutcome`, call `appplicator::applyOutcome()`
7. Return `CheckoutTotals` with promotion data

### `PromotionApplicator::applyOutcome()`

1. Lock promotion row
2. Lock cart + items
3. Re-evaluate matched eligibility (at apply-time)
4. **For discount outcomes**: Proportional allocation using largest remainder method
5. **For gift outcomes**: Reserve gift item via `CartInventoryService::reserveGiftItem()`
6. Update cart items with `promotion_id`, `discount_amount`, adjusted `total_price`
7. Update cart `total_price`
8. Return discount amount and gift item IDs

### Proportional Allocation (Largest Remainder)

```php
foreach matched items:
    exactShare = (lineTotalCents * totalDiscountCents) / sumLineCents
    floorShare = floor(exactShare)
    allocation = min(floorShare, lineTotalCents)
    remainder = exactShare - floorShare

// Distribute remaining cents by largest remainder
remaining = totalDiscountCents - sum(allocations)
arsort(remainders)
foreach remainders:
    if remaining <= 0 break
    give = min(available, 1)  // 1 cent at a time
    allocation += give
    remaining -= give
```

### Key Finding

6. **Cents conversion ONLY in promotion engine** — The promotion engine internally converts to integer cents for the proportional allocation algorithm. This is mathematically necessary (integer division) and safe because:
   - Input: decimal amounts (from DB) → cents: `(int) round($amount * 100)`
   - Output: cents → decimal: `$alloc / 100.0`
   - `number_format(..., 2, '.', '')` ensures no floating point artifacts
7. **Cents conversion uses `round()` NOT `intval()`** — This is correct. `intval()` truncates, `round()` rounds to nearest integer cent.
8. **Gift items are removed and re-reserved each time `applySelectedPromotion()` is called** — `removeGiftItems()` at the top of the method releases inventory and deletes existing gift items. Then `applyOutcome()` re-reserves them. This is safe but creates a brief window where gift inventory is temporarily released.

---

## 7. Checkout Flow

### Promotion in Checkout

`OrderService::addItemsInOrder()`:

1. Finds selected promotion from cart items (first item with non-null `promotion_id`)
2. Calls `calculateCheckoutTotals()` with selected promotion
3. `calculateCheckoutTotals()` calls `applySelectedPromotion()` which re-applies the promotion
4. `CheckoutTotals` is returned with recalculated values
5. Order is created with promotion snapshot (promotion_id, code, type, discount)

### Promotion Data on Order (Snapshot)

`OrderCreationService::createOrder()`:

```php
'promotion_id' => $checkoutTotals->promotionId(),
'promotion_code' => $checkoutTotals->promotionCode(),
'promotion_type' => $checkoutTotals->promotionType(),
'promotion_discount' => $checkoutTotals->promotionDiscount,
```

### Usage After Payment

`OrderService::finalizePromotionUsageAfterPayment()`:

```php
$promotionId = $order->promotion_id ? (int) $order->promotion_id : null;
if ($promotionId) {
    $this->promotionService->incrementUsage($promotionId);
}
```

`PromotionService::incrementUsage()`:

```php
Promotion::whereKey($promotionId)
    ->where(function ($q) { $q->whereNull('limiter')->orWhereColumn('usage', '<', 'limiter'); })
    ->lockForUpdate()
    ->first()
    ?->increment('usage');
```

### Key Finding

9. **Promotion usage is NOT consumed at checkout** — It's consumed when payment succeeds (online callback, COD mark-paid, cashier mark-paid). The `incrementUsage()` is called from `finalizePromotionUsageAfterPayment()`.
10. **Promotion `usage` increment happens WITHOUT checking for 100% concurrency** — The `lockForUpdate` + `where('usage', '<', 'limiter')` guard should prevent over-consumption. However, `incrementUsage()` uses `->first()?->increment()` which is atomic. The race condition protection depends on whether the transaction isolation level is REPEATABLE READ or SERIALIZABLE.
11. **Decrement happens on cancellation** — `PromotionService::decrementUsage()` is called when order status changes to 'cancelled' (from a non-cancelled status). This is the only decrement path.

---

## 8. Usage Recording

### When Usage is Incremented

| Path | Timing | Called From |
|------|--------|-------------|
| Online payment success | After gateway verification | `finalizePromotionUsageAfterPayment()` in `checkoutCallback()` |
| COD marked as paid | When admin marks paid | `markCodAsPaid()` |
| Cashier marked as paid | When admin marks paid | `markCashierPaid()` |

### When Usage is Decremented

| Path | Timing | Called From |
|------|--------|-------------|
| Order cancelled | When order → cancelled | `changeOrderStatus('cancelled')` |

### Key Finding

12. **Decrement on cancellation is a rollback mechanism** — When an order is cancelled, the promotion usage is decremented. This is different from coupon behavior (which NEVER decrements). This inconsistency may be intentional (promotions are admin-managed, coupons are customer-facing), but it creates a potential abuse vector: a customer could repeatedly checkout and cancel, consuming and releasing promotion quota.

---

## 9. Promotion Deactivation

### Via Status

- Setting `status = false` makes all `valid()` scope queries exclude the promotion
- Existing orders with this promotion are **not affected** (snapshot data on order)

### Via Date

- If `end_at` passes, the `valid()` scope excludes the promotion
- Same as status — existing orders are not affected

### Via Limiter

- When `usage >= limiter`, the `valid()` scope excludes the promotion
- `PromotionService::incrementUsage()` has a guard `whereColumn('usage', '<', 'limiter')` which prevents incrementing past the limit

### Key Finding

13. **No observer** — Unlike coupons, promotions do NOT have an observer. No activity logging on promotion CRUD.
14. **No cleanup of cart items when promotion is deactivated** — If a promotion is disabled or reaches its limit while a user has it applied to their cart, the stale `promotion_id` stays on cart items until the next cart mutation triggers `revalidatePromotion()`. The cart resource would try to load a deleted/inactive promotion via `CartItem::promotion()` relationship.

---

## 10. Expiration

### Same as Coupon Expiration

- `end_at` date field
- Checked at query time via `valid()` scope
- No scheduled cleanup of expired promotions on carts

---

## 11. Key State Transitions

```
Promotion Created (status=true, usage=0)
    │
    ├── User carts → User selects promotion (GET /checkout/promotions)
    │       │
    │       ├── Promotion applied to cart items (promotion_id, discount_amount)
    │       │       │
    │       │       ├── Cart updated → revalidatePromotion() clears promotion data
    │       │       │       → User must re-select promotion
    │       │       │
    │       │       └── Checkout initiated → applySelectedPromotion() re-applies
    │       │               │
    │       │               ├── Payment succeeds → incrementUsage() → promotion.usage++
    │       │               │
    │       │               ├── Payment fails → cart unchanged → promotion can be retried
    │       │               │
    │       │               └── Payment expires → cart expired → promotion data lost
    │       │
    │       └── Promotion deactivated (status=false, end_at passed, or limiter reached)
    │               → valid() scope excludes it → no longer selectable
    │               → Existing cart items may still reference it
    │
    ├── Promotion reaches usage limit
    │       → valid() scope excludes it
    │       → incrementUsage() guard prevents further increment
    │
    └── Order cancelled → decrementUsage() → promotion.usage-- (only if it was incremented)
```

---

## 12. Bugs Found

| ID | Severity | File:Line | Description |
|----|----------|-----------|-------------|
| PROMO-1 | LOW | `PromotionApplicator:48` | `matchedEligibility()` is called again at apply-time using fresh `$subtotalCents` computed from `$cart->items`. This could differ from the resolution-time subtotal if items changed between resolution and application. Since the cart is locked within the same transaction, this should be safe, but the double evaluation is redundant with the resolver's own evaluation. |
| PROMO-2 | INFO | `Promotion:89-95` | `discount` and `value` fields are kept in sync via `saving` hook. The condition `$promotion->discount !== null && ($promotion->value === null || !$promotion->isDirty('value'))` means if `discount` is set and `value` hasn't been explicitly changed, `value` is overwritten. This could mask bugs if one field is intentionally different from the other. |
| PROMO-3 | LOW | `PromotionService:195-197` | `incrementUsage()` has a guard `where('usage', '<', 'limiter')` but this is evaluated BEFORE the atomic `increment()`. In high-concurrency scenarios, two transactions could both pass this check and both increment, temporarily exceeding the limiter. The `lockForUpdate` on the promotion row should prevent this if isolation level is SERIALIZABLE or if the query reads the latest committed data. |
| PROMO-4 | INFO | No observer | Coupons have an observer for audit logging; promotions do not. |
| PROMO-5 | LOW | `PromotionEligibilityResolver:118-120` | When `appliesToAllProducts()`, `matchedSubtotalCents` is overwritten with `$subtotalCents`. But `$subtotalCents` is the FULL cart subtotal including items that may not be in `$matchedItems`. If some items were filtered out (gift items excluded), the matched subtotal should be based on all non-gift items anyway since all_products matches all items. So this is actually correct — it just recalculates from scratch. |
