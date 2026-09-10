# Promotion Engine — Zero-Trust Audit

## Source Files Read

- `app/Services/General/PromotionService.php` — applySelectedPromotion, clearPromotionFromCart, incrementUsage, decrementUsage
- `app/Services/General/PromotionEngine/PromotionEligibilityResolver.php` — eligible(), resolve(), matchedEligibility()
- `app/Services/General/PromotionEngine/PromotionApplicator.php` — applyOutcome()
- `app/Services/General/PromotionEngine/PromotionResult.php` — DTO
- `app/Services/General/PromotionEngine/PromotionEvaluation.php` — DTO
- `app/Services/General/PromotionEngine/Outcome/DiscountOutcome.php`, `GiftOutcome.php`, `PromotionOutcome.php`
- `app/Services/General/PromotionEngine/Strategies/AbstractPromotionStrategy.php`, `FixedPromotionStrategy.php`, `PercentagePromotionStrategy.php`, `GiftPromotionStrategy.php`
- `app/Services/General/PromotionEngine/Contracts/PromotionStrategy.php`
- `packages/marvel/src/Database/Models/Promotion.php`
- `packages/marvel/src/Enums/PromotionType.php`, `PromotionMountType.php`
- `app/Services/General/OrderService.php` — calculateCheckoutTotals
- `app/Services/Checkout/OrderCreationService.php` — createOrder (snapshot)

---

## 1. Architecture

### Layered Design

```
Controller / Service
    │
    ▼
PromotionService::applySelectedPromotion()
    │
    ├── PromotionEligibilityResolver
    │     ├── eligible() — filters promotions
    │     └── resolve() — evaluates each promotion via strategy
    │           └── Strategy::eligible() → Strategy::computeOutcome()
    │
    └── PromotionApplicator::applyOutcome()
          ├── Locks promotion + cart (lockForUpdate)
          ├── Re-evaluates matchedEligibility
          ├── DiscountOutcome → proportional allocation (largest remainder)
          └── GiftOutcome → inventoryService.reserveGiftItem()
```

### Supported Promotion Types

| Type | Strategy | Calculation | Scope |
|---|---|---|---|
| `percentage` | `PercentagePromotionStrategy` | `price * (value / 100)`, capped at `max_discount_amount` | Matched subtotal |
| `fixed_rate` | `FixedPromotionStrategy` | `min(price, value)` | Matched subtotal |
| `gift` | `GiftPromotionStrategy` | No discount (gift priced at 0) | Free product(s) |

---

## 2. Promotion Model — Key Fields

From `Promotion.php`:

| Field | Type | Purpose |
|---|---|---|
| `type_amount` | enum(percentage,fixed_rate,gift) | Which strategy to use |
| `value` / `discount` | float | Two fields that mirror each other (saving syncs both) |
| `max_discount_amount` | float|null | Cap for percentage promotions |
| `limiter` | int|null | Maximum total uses |
| `usage` | int | Current usage count |
| `required_quantity_type` | int|null | Minimum quantity required |
| `minimum_order_amount` | float|null | Minimum subtotal required |
| `apply_to` | string(all_products,specific_products) | Scope |
| `start_at` / `end_at` | date | Validity window |

---

## 3. Critical Bug: Usage Increments Before Payment

**Location:** `PromotionService::incrementUsage()` called from `PromotionService::applySelectedPromotion()` during `OrderService::calculateCheckoutTotals()`.

**Verified in source code:**
```php
// PromotionService::applySelectedPromotion() — called from calculateCheckoutTotals()
public function applySelectedPromotion(...)
{
    // ...
    $this->incrementUsage($promotion);  // ← HAPPENS HERE, BEFORE PAYMENT
}
```

**Impact:** Every checkout attempt (including failed ones) increments `promotions.usage` and `promotions.usage_per_user`. If 100 users attempt checkout and 90 fail payment, promotion capacity is depleted by 100 even though only 10 paid.

**The `decrementUsage()` method exists** but is NEVER called on any failure path:
- Payment failure callback does not call it
- Cancellation does not call it
- Cart expiration does not call it

---

## 4. Eligibility Pipeline

### Step 1: `PromotionService::applySelectedPromotion()`

```
Input: cart, selectedPromotionId, selectedGiftProductId
  │
  ├── Load promotion(s) from DB
  ├── Filter to active/valid promotions (scope: valid())
  ├── Calculate subtotal in cents (exclude gifts)
  └── Pass to EligibleResolver
```

### Step 2: `PromotionEligibilityResolver::eligible()`

```
For each promotion:
  1. Check strategy exists for promotion->type_amount
  2. If not all_products: check promotion has products assigned
  3. matchedEligibility():
     - Filter cart items to matched products
     - Exclude gift items (is_gift = true)
     - Compute matchedSubtotalCents
     - If all_products: use full subtotal
  4. strategy->eligible():
     - promotion->isValid() (status, dates, limiter)
     - matchedSubtotalCents >= minimum_order_amount (in cents)
     - promotion->isRequiredQuantityTrue(matchedQuantity)
```

### Step 3: `Strategy::computeOutcome()`

Each strategy calls `promotion->discountAmount()` which:
- For percentage: `price * (value / 100)`, capped at `max_discount_amount`
- For fixed_rate: `min(price, value)`
- For gift: returns 0

Returns a `DiscountOutcome` or `GiftOutcome` (both extend `PromotionOutcome`).

---

## 5. Allocation Algorithm (Largest Remainder)

In `PromotionApplicator::applyOutcome()` for `DiscountOutcome`:

```
Lines: matched cart items with their line_total_cents
AmountCents: total discount to distribute

For each line:
  exactShare = (lineTotalCents * amountCents) / sumLineCents
  floorShare = floor(exactShare)
  allocations[i] = min(floorShare, lineTotalCents)
  remainder[i] = exactShare - floorShare

remaining = amountCents - sum(allocations)
Sort remainders descending
For each remainder (largest first):
  give = min(available, 1 cent)
  allocations[i] += give
  remaining -= give

For each line:
  cartItem->forceFill([
    promotion_id => promotion->id,
    discount_amount => allocation / 100 (in decimal),
    total_price => (lineTotalCents - allocation) / 100
  ])->save();

Update cart.total_price = sum(discounted line totals)
```

**Verification:** The algorithm distributes discount proportionally across matched items. All values are in integer cents to avoid floating point. The `min()` cap prevents over-discounting on individual lines. Correct.

---

## 6. Gift Promotion Flow

```
GiftPromotionStrategy::computeOutcome()
  ├── Loads promotion->giftProducts (pivot table)
  ├── Checks available_stock for each gift product
  ├── If variant specified: checks variant stock
  └── Returns GiftOutcome with GiftItem[] array

PromotionApplicator::applyOutcome()
  ├── For each GiftItem:
  │     ├── Lock product row (lockForUpdate)
  │     └── inventoryService->reserveGiftItem(cart, product, promotion, qty, variantId)
  └── Recalculates cart total_price (excluding gifts)
```

**ReserveGiftItem** creates a cart_item with:
- `quantity = gift quantity`
- `price = 0`, `total_price = 0`
- `is_gift = true`
- `promotion_id = promotion->id`
- No stock deduction (gift is free, inventory is separately managed via the product's `reserved_quantity`)

**BUG:** `GiftPromotionStrategy::computeOutcome()` checks `available_stock` but does NOT lock the product row during eligibility check. Between eligibility and applyOutcome(), stock could change. The `applyOutcome()` does lock the product row during reservation, so the actual reservation is safe, but the eligibility check is a TOCTOU.

---

## 7. Usage Counting

| Counter | Location | Incremented When | Decremented When |
|---|---|---|---|
| `promotions.usage` | DB column | `incrementUsage()` during checkout | `decrementUsage()` exists but NEVER called |
| `promotions.usage_per_user` | DB column via PromotionUserUsage table | `incrementUsage()` | Never |

**BUG: `incrementUsage()` called in `PromotionService::applySelectedPromotion()`** which runs during `OrderService::calculateCheckoutTotals()` which runs in `addItemsInOrder()` during checkout — BEFORE any payment.

**Fix requirement:** Move to `finalizePromotionUsageAfterPayment()` which already exists and is called AFTER payment confirmation.

---

## 8. Promotion Data Model Issues

### 8a. `value` vs `discount` Duplication

From `Promotion::boot()`:
```php
static::saving(function (self $promotion) {
    if ($promotion->discount !== null && ($promotion->value === null || !$promotion->isDirty('value'))) {
        $promotion->value = $promotion->discount;
    }
    if ($promotion->value !== null && ($promotion->discount === null || !$promotion->isDirty('discount'))) {
        $promotion->discount = $promotion->value;
    }
});
```

Two columns (`value` and `discount`) mirror each other. This is dead logic — one should be removed.

### 8b. `usage` vs `limiter` Naming

`promotions.usage` stores current count. `promotions.limiter` stores max. The names are inconsistent (one describes action, one describes limit). Minor but confusing.

---

## 9. Missing Features

| Feature | Status | Impact |
|---|---|---|
| Promotion usage decrement on failed payment | NOT IMPLEMENTED | Usage capacity permanently consumed by failed checkouts |
| Promotion usage decrement on refund | NOT IMPLEMENTED | Usage capacity never returned after refund |
| Promotion retry idempotency check | NOT IMPLEMENTED | `finalizePromotionUsageAfterPayment()` uses `firstOrCreate` on PromotionUserUsage, so duplicate rows are prevented. But `$promotion->increment('usage')` is not guarded. |
| Promotion + coupon interaction rules | NOT IMPLEMENTED | No logic for whether coupon and promotion can stack. Both are applied in `calculateCheckoutTotals()`. |
