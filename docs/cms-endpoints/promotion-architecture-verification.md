# Promotion System Architecture & Lifecycle Verification

> **Document Type**: Architecture Verification (Analysis Only)
> **Status**: No code was modified
> **Date**: 2026-07-13
> **Frozen Architecture**: ADR-001 respected throughout

---

## Table of Contents

1. [Business Requirement Verification](#1-business-requirement-verification)
2. [Promotion Lifecycle](#2-promotion-lifecycle)
3. [Promotion State Machine](#3-promotion-state-machine)
4. [Cart Consistency Verification](#4-cart-consistency-verification)
5. [Promotion Calculation Verification](#5-promotion-calculation-verification)
6. [Promotion Persistence](#6-promotion-persistence)
7. [Checkout Verification](#7-checkout-verification)
8. [Order Verification](#8-order-verification)
9. [Promotion Usage](#9-promotion-usage)
10. [Inventory Verification](#10-inventory-verification)
11. [Architecture Verification](#11-architecture-verification)
12. [API Verification](#12-api-verification)
13. [Critical Questions Answered](#13-critical-questions-answered)
14. [Confirmed Bugs](#14-confirmed-bugs)
15. [Potential Risks](#15-potential-risks)
16. [Architecture Violations](#16-architecture-violations)
17. [Open Questions](#17-open-questions)
18. [Evaluation of `has_eligible_promotion`](#18-evaluation-of-has_eligible_promotion)
19. [Prioritized Technical TODO](#19-prioritized-technical-todo)

---

## 1. Business Requirement Verification

### 1.1 Requirement 1: Cart & Promotion Synchronization

**Statement**: "Any modification to the cart MUST trigger promotion revalidation."

**Verification Result**: ❌ **FAILED — Not implemented.**

Every cart modification operation was traced. None of them trigger promotion revalidation.

| Operation | Endpoint | Does it revalidate promotion? | Evidence |
|-----------|----------|------------------------------|----------|
| Add product | `POST /api/v1/carts` | ❌ No | `CartRepository::persistCart()` → `syncItems()` → `CartInventoryService::reserveItem()`. No call to `PromotionService`, `PromotionEligibilityResolver`, or any promotion class. |
| Remove product | `DELETE /api/v1/carts/{itemId}` | ❌ No | `CartController::deleteItemFromCart()` → `CartInventoryService::releaseItem()`. Recalculates `cart.total_price` but never touches promotion. |
| Increase qty | `PUT /api/v1/carts` | ❌ No | `CartRepository::updateCart()` → `persistCart('set')` → `CartInventoryService::reserveItem()` with mode='set'. Same flow as add. |
| Decrease qty | `PUT /api/v1/carts` | ❌ No | Same as increase. |
| Change variant | `PUT /api/v1/carts` | ❌ No | Same flow. `reserveItem()` updates the item payload but does not clear promotion data. |
| Clear cart | `DELETE /api/v1/carts` | ❌ No | `CartController::destroy()` → `CartInventoryService::releaseCart()`. Deletes all items, no promotion involvement. |
| Pluck items | `POST /api/v1/carts/pluck-items` | ❌ No | Calls `storeCart()` in a loop. Same as add. |

**Conclusion**: The system has zero automatic promotion revalidation on cart modification.

### 1.2 Requirement 2: Promotion Validity at Checkout

**Statement**: "If an administrator changes the promotion before checkout, the promotion must not be applied."

**Verification Result**: ⚠️ **Partially implemented — conditional.**

| Path | Re-validates? | Evidence |
|------|--------------|----------|
| `calcInvoicePrice()` → `calculateCheckoutTotals()` → `applySelectedPromotion()` | ✅ **Yes** | `PromotionService::applySelectedPromotion()` loads with `Promotion::valid()` scope and `lockForUpdate()` (line 67-76). Then calls `PromotionEligibilityResolver::resolve()` which re-runs all eligibility rules. |
| `addItemsInOrder()` → `getCheckoutTotalsFromCart()` | ❌ **No** | Reads pre-persisted `promotion_id` and `discount_amount` from cart_items (line 291-297). Calls `Promotion::query()->find()` to get promotion metadata — does NOT use `Promotion::valid()` scope. A deactivated promotion would still be found if it exists in the database, and its metadata would be included in the order. |
| `createFastOrder()` → `calculateCheckoutTotals()` | ✅ **Yes** | Same as `calcInvoicePrice()` path. |

**Conclusion**: The price calculation step validates. The order creation step (for regular checkout only) does NOT. A deactivated promotion would have its metadata included in the order if it was previously applied and persisted.

### 1.3 Requirement 3: Order Snapshot

**Statement**: "Orders are immutable snapshots. Promotion information must remain permanently stored."

**Verification Result**: ✅ **Implemented.**

| Field | `orders` Table | `order_products` Table | Always Set? |
|-------|---------------|----------------------|-------------|
| `promotion_id` | ✅ Written by `OrderCreationService::createOrder()` (line 53) | ✅ Written by `createOrderItems()` (line 104) | Conditionally |
| `promotion_code` | ✅ Written (line 54) | ❌ Not on order_products | Conditionally |
| `promotion_type` | ✅ Written (line 55) | ❌ Not on order_products | Conditionally |
| `promotion_discount` | ✅ Written (line 56) | ❌ Not at order level | ✅ Always (0 if no promotion) |
| `promotion_discount_amount` | ❌ Not on orders | ✅ Written per item (line 101) | ✅ Always (0 if no discount) |
| `is_gift` | ❌ Not on orders | ✅ Written per item (line 103) | ✅ Always (false if not gift) |

**Evidence**: `OrderCreationService::createOrder()` writes `$checkoutTotals->promotionId()`, `promotionCode()`, `promotionType()`, `promotionDiscount` to the `orders` table. `createOrderItems()` writes `$item->promotion_id`, `$promotionDiscountAmount`, and `$item->is_gift` to each `order_products` row. These are all inside the order creation transaction and are never modified afterward.

**Risk**: If the promotion was deleted from the database before order creation, `$checkoutTotals->promotion` is null (because `Promotion::find()` returns null in `getCheckoutTotalsFromCart()`), and the order would have null promotion metadata. The per-item `promotion_id` is read from `$item->promotion_id` (the raw attribute), which could hold a deleted FK. The `promotion_discount_amount` is computed from `(price * qty) - total_price` and is always correct regardless of promotion existence.

### 1.4 Requirement 4: Customer Freedom

**Statement**: "Customers must be able to select, change, remove, or skip promotions freely."

**Verification Result**: ⚠️ **Partially implemented.**

| Scenario | Supported? | How? |
|----------|-----------|------|
| Select a promotion | ✅ Yes | Pass `selected_promotion_id` to `calcInvoicePrice()` |
| Change promotion | ✅ Yes | Pass a different `selected_promotion_id` — `applySelectedPromotion()` first clears gift items, then applies new promotion |
| Remove promotion (explicitly) | ❌ **Not directly** | There is no "remove promotion" endpoint or operation. Customer would need to call `calcInvoicePrice()` with `selected_promotion_id = null` or `0`. But `calcInvoicePrice()` is a price calculation step, not a "clear promotion" operation. |
| Continue without promotion | ✅ Yes | Pass `selected_promotion_id = null` to checkout |

**Cart consistency after operations:**

| Operation | Promotion State on Cart Items |
|-----------|------------------------------|
| Select promotion | Items get `promotion_id`, `discount_amount`, `total_price` updated |
| Change promotion | Previous gifts removed, new promotion applied, discount recalculated |
| Remove promotion via `selected_promotion_id = null` | `applySelectedPromotion()` is called with null → skips entire block. Gift items are NOT cleared (because `removeGiftItems()` is called at the start, but only if `$promotionId` is provided... wait) |

Let me re-check:

```php
public function applySelectedPromotion(Cart $cart, ?int $promotionId, ?int $selectedGiftProductId = null): CheckoutTotals
{
    $this->removeGiftItems($cart);  // ← called REGARDLESS of $promotionId
    // ...
    if ($promotionId) {
        // apply promotion
    }
    // return CheckoutTotals with empty promotion/gift data
}
```

Actually, `removeGiftItems()` is called at the start regardless of `$promotionId`. So if a customer previously had a gift promotion and calls `calcInvoicePrice()` with `selected_promotion_id = null`, the gift items ARE removed. But `discount_amount` and `promotion_id` on regular items are **NOT cleared** — they persist because `applyOutcome()` is only called inside the `if ($promotionId)` block.

**Verdict**: Calling with `selected_promotion_id = null` removes gifts but leaves discount_amount and promotion_id on items.

---

## 2. Promotion Lifecycle

### 2.1 Tier 1: Cart Manipulation (No Promotion Involvement)

```
Customer Actions                     System Response
─────────────────                    ────────────────
Add item to cart          ───────→   CartInventoryService::reserveItem()
                                      └── Sets price, total_price via ProductPricingService
                                      └── Promotions: NOT evaluated

Remove item from cart     ───────→   CartInventoryService::releaseItem()
                                      └── Releases inventory, deletes item
                                      └── Promotions: NOT evaluated
                                      └── remaining items keep promotion_id, discount_amount

Update item quantity      ───────→   CartInventoryService::reserveItem() (mode='set')
                                      └── Recalculates total_price = price × quantity (UNDISCOUNTED)
                                      └── promotion_id, discount_amount: PRESERVED (stale)
```

### 2.2 Tier 2: Promotion Eligibility (Read-Only)

```
Customer Actions                     System Response
─────────────────                    ────────────────
View eligible promotions  ───────→   GET /api/v1/general/checkout/promotions
                                      ├── PromotionService::eligiblePromotionsPayload()
                                      │   └── Promotion::valid() → get all valid promotions
                                      │   └── PromotionEligibilityResolver::eligible()
                                      │       └── For each promotion: resolve()
                                      │           ├── matchedEligibility()
                                      │           ├── Strategy::eligible()
                                      │           └── Strategy::computeOutcome()
                                      └── Returns PromotionResult[] → JSON
                                      └── Database: READ ONLY, no writes
```

### 2.3 Tier 3: Promotion Selection & Price Calculation

```
Customer Actions                     System Response
─────────────────                    ────────────────
Proceed to checkout       ───────→   POST /api/v1/general/checkout (Step 1: calcInvoicePrice)
                                      │
                                      ├── OrderService::calcInvoicePrice()
                                      │   └── DB::transaction
                                      │       ├── getCartUser() → cart + scheduled items only
                                      │       └── calculateCheckoutTotals($cart, $promotionId, $giftProductId)
                                      │           ├── PromotionService::applySelectedPromotion()
                                      │           │   ├── removeGiftItems() → clear previous gifts
                                      │           │   ├── Load promotion with lockForUpdate
                                      │           │   ├── PromoEligibilityResolver::resolve() → re-validate
                                      │           │   ├── PromoEligibilityResolver::matchedEligibility()
                                      │           │   ├── PromotionApplicator::applyOutcome()
                                      │           │   │   └── DB::transaction (nested)
                                      │           │   │       ├── Lock promotion row
                                      │           │   │       ├── Lock cart + items
                                      │           │   │       ├── Re-evaluate matched eligibility
                                      │           │   │       ├── Proportional allocation (discount)
                                      │           │   │       │   └── Update each item: promotion_id, discount_amount, total_price
                                      │           │   │       └── OR reserveGiftItem (gift)
                                      │           │   │           └── Create CartItem: price=0, is_gift=true
                                      │           │   └── Return CheckoutTotals DTO
                                      │           └── calculatePriceByCoupon()
                                      │               └── CouponCalculator::calculate()
                                      │                   └── Applied on price-after-promotion
                                      │
                                      └── cart.update(['total_price' => subtotal - promo + shipping])
```

### 2.4 Tier 4: Order Creation (Reads Persisted Data)

```
Customer Actions                     System Response
─────────────────                    ────────────────
Confirm order (payment)   ───────→   POST /api/v1/general/checkout (Step 2: addItemsInOrder)
                                      │
                                      ├── OrderService::addItemsInOrder()
                                      │   └── DB::transaction
                                      │       ├── getCartUser() → cart + SCHEDULED items only
                                      │       ├── Validate coupon (re-validate, may clear invalid coupons)
                                      │       ├── getCheckoutTotalsFromCart()
                                      │       │   └── ⚠️ Reads persisted promotion_id, discount_amount, total_price
                                      │       │   └── ⚠️ Does NOT re-calculate promotion
                                      │       │   └── ⚠️ Promo::find() — does NOT use valid() scope
                                      │       ├── OrderCreationService::createOrder()
                                      │       │   └── Writes promotion data to orders table
                                      │       ├── OrderCreationService::createOrderItems()
                                      │       │   └── Writes per-item promotion data to order_products
                                      │       └── OrderCreationService::finalizeOrder()
                                      │           └── PromotionService::incrementUsage()
                                      │           └── OrderCreated::dispatch()
```

### 2.5 Tier 5: Payment & Finalization

```
Customer Actions                     System Response
─────────────────                    ────────────────
Complete payment           ───────→   GET /api/v1/general/checkout/callback
                                      │
                                      ├── Gateway verification
                                      ├── CartInventoryService::finalizeItemsByShippingMethod()
                                      │   └── Finalizes items with matching shipping_method
                                      │   └── ⚠️ Gift items have no shipping_method set → default SCHEDULED
                                      ├── Order status → 'completed'
                                      └── recordCouponUsage() → CouponUsage + coupon increment
```

### 2.6 Tier 6: Fast Shipping (Atomic Path)

```
Customer Actions                     System Response
─────────────────                    ────────────────
Fast checkout             ───────→   POST /api/v1/general/checkout/fast
                                      │
                                      └── FastShippingService::createFastOrder()
                                          └── DB::transaction (single)
                                              ├── getActiveCartForUser()
                                              ├── ensureCartReservation()
                                              ├── calculateCheckoutTotals() ← FRESH calculation
                                              ├── createOrder()
                                              ├── createOrderItems()
                                              ├── finalizeOrder() → incrementUsage
                                              └── commit
```

---

## 3. Promotion State Machine

### 3.1 States

| State | Definition | Database Evidence | Code Evidence |
|-------|-----------|------------------|---------------|
| **Eligible** | Promotion passes all rules for current cart | `Promotion::valid()` scope returns it; `PromotionEligibilityResolver::resolve()` returns a `PromotionResult` | `PromotionService::eligiblePromotions()` → resolver → non-null result |
| **Selected** | Customer chose this promotion during checkout | Not persisted as a "selected" state; passed as request param `selected_promotion_id` | `OrderController::checkout()` reads `$request->input('selected_promotion_id')` |
| **Applied** | Discount written to cart_items | `cart_items.promotion_id` set to promotion ID; `cart_items.discount_amount` > 0; `cart_items.total_price` reduced | `PromotionApplicator::applyOutcome()` writes to DB |
| **Gifted** | Gift items added to cart | `cart_items` with `is_gift = true`, `promotion_id` set, `price = 0`, `total_price = 0` | `CartInventoryService::reserveGiftItem()` creates these |
| **Removed** | Customer removed promotion selection | Explicit removal: `applySelectedPromotion()` with null `promotionId` → gifts removed via `removeGiftItems()`, but discount_amount and promotion_id on items NOT cleared. Implicit removal (cart edit): promotion data preserved | `applySelectedPromotion()` line 57: `removeGiftItems()` always called. Lines 97-112: discount apply only if `$promotionId` provided. |
| **Invalid** | Promotion no longer meets criteria | `Promotion::valid()` would NOT return it; `PromotionEligibilityResolver::resolve()` returns null | Checked at apply time via `Promotion::valid()` scope + `resolve()` |
| **Expired** | `end_at` date passed | `start_at > today` or `end_at < today` | `Promotion::isValid()` checks dates |
| **Consumed** | Usage limit reached | `usage >= limiter` | `Promotion::isValid()` checks `usage < limiter` |
| **Cancelled** | Order cancelled after usage was incremented | No state change on promotion; usage counter remains incremented | No `decrementUsage()` method exists |

### 3.2 State Transitions

```
                        ┌─────────────────────────────────────────────┐
                        │                                             │
                        ▼                                             │
  ┌──────────┐    select    ┌──────────┐    applyPersist    ┌─────────┴──────┐
  │ Eligible ├─────────────►│ Selected ├──────────────────►│ Applied+Gifted │
  └──────────┘              └──────────┘                    └───────┬────────┘
                                                                     │
       ▲                        │                                    │
       │                        │ checkout                           │ checkout
       │                        ▼                                    ▼
       │                 ┌──────────────┐                    ┌──────────────┐
       │                 │ Removed      │                    │ OrderCreated │
       │                 │ (on next     │                    │  (usage incr) │
       │                 │  calcInvoice)│                    └──────┬───────┘
       │                 └──────────────┘                           │
       │                                                            ▼
       │                                                     ┌──────────────┐
       └─────────────────────────────────────────────────────┤ Cancelled    │
            (admin re-enables, or customer re-selects)       │ (usage NOT   │
                                                             │  decremented)│
                                                             └──────────────┘
```

### 3.3 Transition Details

| From | To | Trigger | Class | DB Changes | Can Fail? |
|------|----|---------|-------|------------|-----------|
| Eligible | Selected | Customer selects promotion | `OrderController::checkout()` → param | None (not persisted) | N/A |
| Selected | Applied+Gifted | `applySelectedPromotion()` | `PromotionApplicator::applyOutcome()` | `cart_items.promotion_id`, `discount_amount`, `total_price` updated; gift cart_items created | ✅ Yes — promotion invalid, gift no stock, race condition |
| Applied+Gifted | Removed | Next `calcInvoicePrice()` with different/null promotion | `PromotionService::applySelectedPromotion()` | Gift items deleted; discount_amount and promotion_id on items persist if no new promotion applied | No |
| Applied+Gifted | OrderCreated | `addItemsInOrder()` / `createFastOrder()` | `OrderCreationService::finalizeOrder()` | `orders.promotion_*` written; `order_products.promotion_*` written; `promotions.usage` incremented | ✅ Yes — order creation fails → rollback |
| Applied+Gifted | Cancelled (promotion) | Admin deactivates/deletes | N/A (admin action) | `promotions.status = false` or row deleted | No |
| OrderCreated | Cancelled (order) | Order cancelled by admin | `OrderService::changeOrderStatus()` | `orders.status = cancelled`; **promotion.usage NOT decremented** | No |

### 3.4 Inconsistency Risk: Items with promotion_id but no active promotion

```
After cart modification (qty increased):
  ┌─────────────┬──────────┬────────────────┬──────────────┐
  │ item_id     │promotion │ discount_amount │ total_price  │
  ├─────────────┼──────────┼────────────────┼──────────────┤
  │ 1           │ 5        │ 10.00          │ 200.00       │ ← STALE
  │             │          │                │ (price×qty,  │
  │             │          │                │  no discount) │
  └─────────────┴──────────┴────────────────┴──────────────┘
```

This item has `promotion_id = 5` and `discount_amount = 10`, but its `total_price = 200` (full price for qty=2). The discount_amount no longer reflects the actual discount applied.

---

## 4. Cart Consistency Verification

### 4.1 Add Product

| Question | Answer | Evidence |
|----------|--------|----------|
| Does promotion get revalidated? | ❌ No | `CartInventoryService::reserveItem()` — no promotion code path |
| Does PromotionEligibilityResolver execute? | ❌ No | Not called from any cart modification path |
| Does promotion remain on existing items? | ✅ Yes | `$item->update($payload)` does not include `promotion_id` → preserved |
| Should it remain? | ⚠️ Unclear | The existing items' discount was calculated for a different cart composition. With new items added, the proportional allocation is different. |
| Does discount recalculate? | ❌ No | No promotion code invoked |
| Does discount allocation recalculate? | ❌ No | No promotion code invoked |
| Does cart total recalculate? | ✅ Partially | `$cart->update(['total_price' => $cart->items()->sum('total_price')])` — sums all items' total_price, including discounted and undiscounted items |
| Does gift remain? | ✅ Yes | `removeGiftItems()` is only called inside `applySelectedPromotion()` |
| Should gift remain? | ⚠️ Unclear | If the promotion that granted the gift is still valid, yes. But the allocation was for a different cart composition. |
| Is gift removed? | ❌ No | Not called during cart modification |
| Is promotion removed? | ❌ No | Not called |
| Is `promotion_id` updated? | ✅ No change (correct for new items); ❌ Preserved on existing (stale risk) | `reserveItem()` payload does not include `promotion_id` |
| Is `discount_amount` updated? | ❌ Preserved (stale risk) | Not in payload |
| Is `total_price` updated? | ✅ Yes — recalculated as `price × quantity` | `reserveItem()` recalculates without discount |
| Which service performs this? | `CartInventoryService::reserveItem()` | Lines 51-60 of CartInventoryService |
| Is there any stale data left? | **✅ YES** — `discount_amount` on updated items is stale; `promotion_id` on updated items may refer to a promotion whose allocation is no longer valid | `discount_amount` was computed for previous quantity. After qty change, it's not updated. |

### 4.2 Remove Product

| Question | Answer | Evidence |
|----------|--------|----------|
| Does promotion get revalidated? | ❌ No | `CartController::deleteItemFromCart()` → `releaseItem()` → no promotion code |
| Does promotion remain on remaining items? | ✅ Yes | Only the deleted item is removed; others keep their values |
| Should it remain? | ⚠️ Partial allocation was based on all items. After removal, the remaining items' discount_amount still equals their share of the original allocation. |
| Does cart total recalculate? | ✅ Yes | `$cart->update(['total_price' => $cart->items()->sum('total_price')])` — sums remaining items |
| Does gift remain? | ✅ Yes | Only the specified item is deleted |
| Is gift removed? | ❌ No | Not unless the deleted item was a gift |
| Is promotion removed? | ❌ No | Not called |
| Is `promotion_id` updated? | ❌ No | Not touched |
| Is `discount_amount` updated? | ❌ No | Not touched |
| Is `total_price` updated? | ✅ Yes — `SUM(total_price)` of remaining items | Correct recalc |
| Is there any stale data? | ⚠️ The remaining items' `discount_amount` reflects an allocation calculated with the now-deleted item included. The total discount in the cart is no longer what was originally applied. But the individual discount_amount values are still mathematically correct for each item. |

### 4.3 Increase Quantity

| Question | Answer | Evidence |
|----------|--------|----------|
| Does promotion get revalidated? | ❌ No | Same as Add |
| Does `discount_amount` remain? | ✅ **YES — STALE** | `$item->update($payload)` does not clear it |
| Is `total_price` recalculated? | ✅ Yes — `price × new_quantity` | **WITHOUT discount** — the new total_price equals full price, not discounted price |
| Is there stale data? | **✅ YES — BUG** | `discount_amount` has old value (e.g., 10), `total_price` equals full price (e.g., 200). `getCheckoutTotalsFromCart()` reads `promotionDiscount = sum(discount_amount)`, `finalTotal = sum(total_price)`. The discount no longer matches the actual price reduction. |

### 4.4 Decrease Quantity

| Question | Answer | Evidence |
|----------|--------|----------|
| Same as Increase Quantity | Same | `reserveItem()` recalculates `total_price = price × new_quantity`. `discount_amount` preserved. Same staleness. |

### 4.5 Change Variant

| Question | Answer | Evidence |
|----------|--------|----------|
| If same product + new variant: | New CartItem created (different `product_variant_id`). `promotion_id` = null. Old item may still exist if both variants were in cart. | `findCartItemForLock()` matches on `product_id + product_variant_id`. Different variant = different row. |
| If same variant + different attributes: | Same item updated. `promotion_id` and `discount_amount` preserved. `total_price` recalculated without discount. **Same staleness as quantity change.** | `reserveItem()` payload does not include promotion fields. |

### 4.6 Clear Cart

| Question | Answer | Evidence |
|----------|--------|----------|
| All items deleted? | ✅ Yes | `CartInventoryService::releaseCart($cart, true)` → deletes all items |
| Cart reset? | ✅ Yes | `total_price = 0`, `coupon = null`, `status = 'active'` |
| Promotion data left? | ❌ No | All items deleted |
| Gift items removed? | ✅ Yes | All items deleted |

### 4.7 Summary Table

| Cart Operation | Promo Revalidated? | discount_amount Stale? | total_price Correct? | promo_id Stale? | Gift Properly Handled? |
|---------------|-------------------|----------------------|---------------------|----------------|----------------------|
| Add product | ❌ | Preserved on existing | ✅ (full price for new) | Preserved on existing | Preserved on existing |
| Remove product | ❌ | Preserved | ✅ (SUM of remaining) | Preserved | Preserved (unless deleted) |
| Increase qty | ❌ | **STALE** 🐛 | **STALE** (full price) 🐛 | Preserved | N/A |
| Decrease qty | ❌ | **STALE** 🐛 | **STALE** (full price) 🐛 | Preserved | N/A |
| Change variant | ❌ | **STALE**(if same row) 🐛 | **STALE** 🐛 | Preserved | N/A |
| Clear cart | ❌ (N/A) | N/A (deleted) | ✅ | N/A | ✅ Deleted |

---

## 5. Promotion Calculation Verification

### 5.1 Percentage Promotion

```php
// Promotion::discountAmount()
$discount = $price * ($value / 100);       // price = matched subtotal
if ($maxValue !== null) {
    $discount = min($discount, $maxValue);  // capped
}
return round(max(0.0, $discount), 2);
```

**Formula**: `discount = min(matchedSubtotal × value%, max_discount_amount)`

**Verification**: ✅ Mathematically correct.

**Edge case**: `matchedSubtotalCents / 100.0` converts back to decimal, then `discountAmount()` returns float, then `round(amountDecimal * 100)` converts back to cents. Double conversion is safe for realistic values (precision to 2 decimal places).

### 5.2 Fixed Rate Promotion

```php
// Promotion::discountAmount()
return round(max(0.0, min($price, $value)), 2);
```

**Formula**: `discount = min(matchedSubtotal, value)`

**Verification**: ✅ Mathematically correct. Discount cannot exceed matched subtotal.

### 5.3 Gift Promotion

```php
// GiftPromotionStrategy::computeOutcome()
$giftItems = $promotion->giftProducts
    ->map(function ($product) {
        // Filter out-of-stock gifts → null
        // Build GiftItem DTO with priceCents = 0
    })
    ->filter()
    ->values()
    ->all();
```

**Verification**: ✅ Correct. Gift items filtered for stock availability, priced at zero.

### 5.4 Matched Subtotal

```php
// PromotionEligibilityResolver::matchedEligibility()
$matchedSubtotalCents = $matchedItems->sum(function ($item) {
    $unitPrice = (float) ($item->price ?? 0);
    $quantity = (int) ($item->quantity ?? 0);
    $baseLineTotal = $unitPrice * $quantity;
    if ($baseLineTotal > 0) {
        return (int) round($baseLineTotal * 100);
    }
    return (int) round((float) ($item->total_price ?? 0) * 100);
});
```

**Key behaviour**: Uses `$item->price * quantity` (original price at add-to-cart time), NOT `$item->total_price`. Falls back to `total_price` if baseLineTotal is 0.

**Verification**: ✅ Correct. Using original `price * quantity` avoids double-counting any existing discount. However, `$item->price` is the price at add-to-cart time, not necessarily the current product price.

### 5.5 Quantity Rules

```php
// Promotion::isRequiredQuantityTrue()
return is_null($this->required_quantity_type) || $qty >= $this->required_quantity_type;
```

**Verification**: ✅ Correct. NULL means no minimum quantity. Otherwise, matched quantity >= required threshold.

### 5.6 Minimum Order

```php
// AbstractPromotionStrategy::eligible()
$minimumCents = (int) round(((float) ($promotion->minimum_order_amount ?? 0)) * 100);
if ($evaluation->matchedSubtotalCents < $minimumCents) {
    return false;
}
```

**Verification**: ✅ Correct. Compares matched subtotal (cents) to minimum order (converted to cents).

### 5.7 Allocation Algorithm

```php
// PromotionApplicator::applyOutcome()
// For each matched item:
//   exact_share = (line_total_cents × amountCents) / baseCents
//   floor_share = floor(exact_share)
//   allocation = min(floor_share, line_total_cents)
//   allocatedSum += allocation
//   remainders = exact_share - floor_share
//
// remaining = amountCents - allocatedSum
// Sort remainders descending
// Distribute remaining cents one-by-one to largest remainders (respecting line caps)
```

**Verification**: ✅ This is the standard **largest remainder method** for proportional allocation. It guarantees:
1. Total allocated == total discount amount
2. No item gets more discount than its line total
3. Fair proportional distribution

### 5.8 Duplicated Calculations Found

| Calculation | Location 1 | Location 2 | Location 3 | Duplicated? |
|------------|-----------|-----------|-----------|-------------|
| `price × qty` subtotal (exclude gifts) | `PromotionService::subtotal()` (line 180) | `PromotionEligibilityResolver::matchedEligibility()` (line 105) | `OrderService::getCheckoutTotalsFromCart()` (line 283) | ✅ Yes — same formula, three places |
| Discount formula | `Promotion::discountAmount()` (model) | Called from both `PercentagePromotionStrategy` and `FixedPromotionStrategy` | — | ✅ Centralized in model (arch. violation, but not duplicate) |
| `matchedEligibility()` call | `PromotionEligibilityResolver::resolve()` (line 51) | `PromotionService::applySelectedPromotion()` (line 91) | `PromotionApplicator::applyOutcome()` (line 47) | ⚠️ Three calls for same apply — first two are redundant |

---

## 6. Promotion Persistence

### 6.1 After Discount Apply

| Table | Column | Value | Always Set? |
|-------|--------|-------|-------------|
| `cart_items` | `promotion_id` | Promotion ID | ✅ Yes (for matched items) |
| `cart_items` | `discount_amount` | Allocated amount (2 decimals) | ✅ Yes (for matched items) |
| `cart_items` | `total_price` | Original total minus allocation | ✅ Yes (for matched items) |
| `carts` | `total_price` | Sum of non-gift items' total_price | ✅ Yes |

**Fields NOT changed**: `price` (original), `quantity`, `reserved_quantity`, `is_gift` (remains false).

### 6.2 After Gift Apply

| Table | Column | Value | Always Set? |
|-------|--------|-------|-------------|
| `cart_items` (new) | `is_gift` | `true` | ✅ Yes |
| `cart_items` (new) | `price` | `0` | ✅ Yes |
| `cart_items` (new) | `total_price` | `0` | ✅ Yes |
| `cart_items` (new) | `promotion_id` | Promotion ID | ✅ Yes |
| `cart_items` (new) | `shipping_method` | **NOT SET** — column default (`SCHEDULED`) | ❌ **Bug: implicit** |

### 6.3 Consistency After Persistence

| Check | Result | Evidence |
|-------|--------|----------|
| `total_price = Σ(item.total_price)` for non-gift items? | ✅ Yes | `applyOutcome()` computes `discountedSubtotalCents` as sum of non-gift items' new total_price |
| `discount_amount = original_price - total_price` per item? | ✅ Yes | `$newTotalPrice = (lineTotalCents - alloc) / 100.0`, `$alloc = discount_amount * 100` |
| Cart total after discount + gift? | ✅ Yes | Gift items have `total_price = 0`, excluded from cart total recomputation |
| Can inconsistent state occur? | ✅ Only through cart modification (see Phase 4) | Cart modifications do not clear promotion data |

### 6.4 The Stale Data Risk

The only way stale data can enter is through **cart modification after promotion apply**. The `reserveItem()` payload does not include promotion fields, so:

- Existing items: `discount_amount`, `promotion_id` preserved but `total_price` recalculated without discount
- New items: `promotion_id = null` (column default), `discount_amount = null`
- The cart ends up with a mix of items, some with promotion data and some without

---

## 7. Checkout Verification

### 7.1 Does checkout trust stored promotion data or validate everything again?

**Answer**: It depends on which checkout path.

| Path | Trusts Stored Data? | Re-validates? |
|------|--------------------|---------------|
| Regular: `calcInvoicePrice()` → `calculateCheckoutTotals()` | ❌ Does NOT trust — recalculates | ✅ Yes — `Promotion::valid()` + `PromotionEligibilityResolver::resolve()` |
| Regular: `addItemsInOrder()` → `getCheckoutTotalsFromCart()` | **✅ TRUSTS stored data** | ❌ No — reads `promotion_id`, `discount_amount`, `total_price` from DB as-is |
| Fast: `createFastOrder()` → `calculateCheckoutTotals()` | ❌ Does NOT trust — recalculates | ✅ Yes — same as calcInvoicePrice |

### 7.2 The Critical Gap

For regular checkout:
1. `calcInvoicePrice()` → applies promotion → writes to DB ✅
2. ... time passes (payment gateway redirect) ...
3. `addItemsInOrder()` → reads FROM DB → creates order ❌

**Between step 1 and 3, the cart can be modified.** If it is, step 3 reads stale/corrupted promotion data.

### 7.3 `getCheckoutTotalsFromCart()` — What It Reads

```php
$items = $cart->items->reject(fn($item) => (bool) ($item->is_gift ?? false));
$subtotal = price × qty (original)                                     ← CORRECT
$promotionDiscount = sum(discount_amount)                               ← ⚠️ STALE if cart was modified
$finalTotal = sum(total_price)                                          ← ⚠️ STALE if cart was modified
$promotionItem = first item with promotion_id                           ← ⚠️ Stale if cart modified
$promotion = Promotion::query()->find($promotionItem->promotion_id)     ← ⚠️ Does NOT use valid() scope
```

**WARNING**: `Promotion::query()->find()` (line 297) does NOT use the `valid()` scope. A promotion that was deactivated, expired, or reached its usage limit would still be found and its metadata included in the order.

### 7.4 Scenario: Modified Cart + Stale Checkout

1. User adds Product A ($100) and B ($50) to cart
2. `calcInvoicePrice(selected_promotion_id=1)` → 10% discount → A(90), B(45), total=135
3. User goes to payment gateway
4. While on payment page, user opens another tab and increases B's quantity to 2
5. `reserveItem()` recalculates B: `total_price = 50 × 2 = 100`, `discount_amount = 5` (STALE)
6. Payment succeeds → `addItemsInOrder()` runs
7. `getCheckoutTotalsFromCart()` reads:
   - subtotal = 100 + 100 = 200
   - promotionDiscount = 10 + 5 = 15 (STALE — should be 10% of 200 = 20)
   - finalTotal = 90 + 100 = 190 (STALE — B got no real discount)
   - couponDiscount = max(0, 200 - 15 - 190) = 0
8. Order created with `price=200`, `total=190`, `promotion_discount=15`

**Customer gets a $15 discount on a $200 order** when they should have gotten $20 (10% of 200). The customer underpays by $5.

**Or worse**: If the discount_amount was over-written somewhere, the customer could overpay.

---

## 8. Order Verification

### 8.1 Snapshot Completeness

| What | Stored Where | Complete? |
|------|-------------|-----------|
| Promotion ID | `orders.promotion_id` | ✅ Always (nullable) |
| Promotion Code | `orders.promotion_code` | ✅ Always (nullable) |
| Promotion Type | `orders.promotion_type` | ✅ Always (nullable) |
| Promotion Discount (total) | `orders.promotion_discount` | ✅ Always (0 if no promotion) |
| Per-Item Discount | `order_products.promotion_discount_amount` | ✅ Always (0 if no discount) |
| Per-Item Is Gift | `order_products.is_gift` | ✅ Always (false if not gift) |
| Per-Item Promotion ID | `order_products.promotion_id` | ✅ Always (nullable) |
| Gift Items as separate rows | `order_products` | ✅ Yes |
| Coupon Discount | `orders.coupon_discount` | ✅ Always |
| Subtotal | `orders.price` | ✅ Always |
| Total | `orders.total_price` | ✅ Always |

### 8.2 Can Promotion Information Be Lost?

| Scenario | Lost? | Evidence |
|----------|-------|----------|
| Promotion deleted between apply and order creation | ⚠️ Metadata lost, amounts preserved | `getCheckoutTotalsFromCart()` calls `Promotion::find()` → null. `$promotionData` becomes null. Order has null promotion_id/type/code. But `order_products.promotion_id` still has the raw FK value (no FK constraint would block it, and it's the attribute value, not the relation). |
| No promotion selected | ✅ Correct — all promotion fields null | All computed fields are null/0 |
| Gift promotion | ✅ Gift items stored with `is_gift=true` | Per-item in `order_products` |

### 8.3 Flash Sale Price at Order Creation

In `createOrderItems()` (line 81-89):
```php
$pricingService = app(ProductPricingService::class);
$flashSale = $pricingService->resolveActiveFlashSale($product);
$pricing = $pricingService->calculateProductPricing($product, $flashSale);
$isFlashSaleApplied = $pricing['price_after_flash_sale'] !== null;
$flashSalePrice = $isFlashSaleApplied ? $effectiveUnitPrice : null;
```

**Observation**: The flash sale/discount status is re-queried from the database at order creation time. This means the flash sale price in the order snapshot reflects the **current** flash sale status, not the status at the time the item was added to cart. If a flash sale ends between cart-add and checkout, the `product_flash_sale_price` field is set to null even though the customer paid the flash sale price (which was set at add-to-cart time via `ProductPricingService` in `CartInventoryService::reserveItem()`).

**Verdict**: This is a separate issue from promotions, but part of the same order snapshot inconsistency.

---

## 9. Promotion Usage

### 9.1 When Usage Increments

`PromotionService::incrementUsage()` is called from `OrderCreationService::finalizeOrder()`:

```php
public function finalizeOrder(Order $order, CheckoutTotals $checkoutTotals): void
{
    $this->promotionService->incrementUsage($checkoutTotals->promotionId());
    // Then dispatch OrderCreated event
}
```

This is called AFTER order and order_items are successfully created, inside the same transaction.

### 9.2 When It Should Increment

Only after a successful order is committed to the database. ✅ Correct.

### 9.3 Can Failed Checkout Increase Usage?

**Regular checkout**: `addItemsInOrder()` is in a DB::transaction. If `createOrder()` or `createOrderItems()` fails, the transaction rolls back. `finalizeOrder()` is only called after both succeed (line 175). If `finalizeOrder()` itself fails, `incrementUsage()` was already executed, but the outer catch block rolls back the entire transaction (line 182: `DB::rollBack()`). However, `incrementUsage()` runs `Promotion::query()->whereKey(...)->lockForUpdate()->first()?->increment('usage')` — this is a separate query that executes inside the transaction. When the transaction rolls back, the increment is also rolled back.

**Verdict**: ✅ Usage is never incremented on failed checkout. Transaction rollback ensures atomicity.

### 9.4 Race Conditions

The `lockForUpdate()` in `incrementUsage()` prevents concurrent increments:
```php
Promotion::query()
    ->whereKey($promotionId)
    ->where(function ($query) {
        $query->whereNull('limiter')
            ->orWhereColumn('usage', '<', 'limiter');
    })
    ->lockForUpdate()
    ->first()
    ?->increment('usage');
```

If two orders complete simultaneously:
1. Order A acquires lock → reads usage=5 → increments to 6 → commits
2. Order B acquires lock → reads usage=6 → increments to 7 → commits

### 9.5 Can Usage Exceed Limiter?

The `incrementUsage()` method has a guard:
```php
->where(function ($query) {
    $query->whereNull('limiter')
        ->orWhereColumn('usage', '<', 'limiter');
})
```

If `usage >= limiter`, `->first()` returns null, and `?->increment('usage')` is never called. So a promotion with usage=10 and limiter=10 would not be further incremented.

**But**: The eligibility check (`isValid()`) also checks this condition. An order that passes eligibility (usage=9, limiter=10) would increment to 10. The next order would fail eligibility (usage=10, limiter=10). ✅ Correct.

**TOCTOU window**: Between eligibility check (in `applySelectedPromotion()`) and `incrementUsage()` (in `finalizeOrder()`), another order could increment usage past the limit. But `lockForUpdate()` in `incrementUsage()` ensures atomic increment. The worst case is that an order succeeds where eligibility was evaluated with usage=9 but by the time it increments, usage is already 10 — the increment brings it to 11. But the guard `WHERE usage < limiter` prevents this: if usage is already 10 and limiter is 10, `first()` returns null and increment doesn't happen. The order still goes through (discount already applied to cart), but usage is not incremented.

**Verdict**: ⚠️ An order can go through without incrementing usage if the limiter was reached between eligibility check and increment. But the discount was already applied to the cart, so there's no financial loss — just a miscounted usage counter.

### 9.6 Cancelled Orders

When an order is cancelled:
- `changeOrderStatus()` sets order status to 'cancelled'
- Usage counter is **NOT decremented**
- No `decrementUsage()` method exists

**Verdict**: ⚠️ Cancelled orders still count toward promotion usage limit. Business decision, but should be documented.

---

## 10. Inventory Verification

### 10.1 Gift Item Reserve

`CartInventoryService::reserveGiftItem():`
- Creates `CartItem` with `price = 0`, `total_price = 0`, `is_gift = true`, `promotion_id` set
- Reserves stock via `reserveStock()` (decreases available stock by `reserved_quantity + quantity`)
- Operates inside `DB::transaction` with `lockForUpdate()`

### 10.2 Gift Item Release

**Scenario — re-apply**: `PromotionService::removeGiftItems()` in `applySelectedPromotion()`:
```php
$cart->items()
    ->where('is_gift', true)
    ->get()
    ->each(fn($item) => $this->inventoryService->releaseItem($item, true));
```
✅ Releases stock and deletes gift items.

**Scenario — cart expired**: `CartInventoryService::expireCarts()` → `expireCart()` → iterates all items (including gifts) → `releaseStock()` → deletes items. ✅

**Scenario — cart cleared**: `CartInventoryService::releaseCart()` → iterates all items → `releaseItem()` → deletes items. ✅

### 10.3 Gift Item Finalize

`CartInventoryService::finalizeItemsByShippingMethod($cart, $shippingMethod)`:
```php
$items = CartItem::where('cart_id', $cart->id)
    ->where('shipping_method', $shippingMethod)
    ->lockForUpdate()
    ->get();
```

**Bug**: Gift items are created without `shipping_method` in the payload. They get the column default (`SCHEDULED`). In fast checkout, `$shippingMethod = 'FAST'`. Gift items with `shipping_method = 'SCHEDULED'` are NOT included in the finalization query. Their inventory remains reserved.

**Impact**: Inventory leak for gift items in fast checkout.

### 10.4 Gift Item Stock Check

`GiftPromotionStrategy::hasAvailableStock()`:
```php
if ($product->relationLoaded('variations')) {
    return $product->variations->contains(fn($v) => (int) ($v->available_stock ?? 0) > 0);
}
return $product->variations()
    ->whereRaw('(COALESCE(stock_quantity, 0) - COALESCE(reserved_quantity, 0)) > 0')
    ->exists();
```

The fallback query (when variations are not eager loaded) uses a raw WHERE. This is an N+1 risk if variations are not eager loaded upstream. Currently, `PromotionService::eligiblePromotions()` eager loads `giftProducts.variations`, so the fallback is never triggered during eligibility listing. But if `hasAvailableStock()` is called from another code path without eager loading, it triggers an extra query.

---

## 11. Architecture Verification

### 11.1 Violations of Frozen Architecture (ADR-001)

| Rule | Requirement | Status | Evidence |
|------|-------------|--------|----------|
| #1 Single Pricing Authority | All pricing through `ProductPricingService` | ✅ Compliant (separate domain) | Promotion pricing is cart-level, not product-level. Separate by design. |
| #2 Pre-Serialization Enrichment | Pricing set before Resource | ✅ Compliant | CartInventoryService sets `price` and `total_price` before CartItemResource. |
| #3 Resource Purity | Resources only serialize | ❌ **Violation** | `CartItemResource::toArray()` calls `$this->product->getFirstMediaUrl('products')` — media library call inside resource. |
| #4 Model Purity | No pricing business logic | ❌ **Violation** | `Promotion::discountAmount()` — computes discount amounts with percentage/fixed rate logic. |
| #5 Controller Purity | Controllers only orchestrate | ⚠️ Mostly compliant | `CartController::deleteItemFromCart()` and `destroy()` contain direct business logic (inventory release, cart updates). |
| #6 Zero Duplication | No duplicate pricing formulas | ❌ **Violation** | Subtotal calculation `price × qty` duplicated in `PromotionService::subtotal()`, `PromotionEligibilityResolver::matchedEligibility()`, `OrderService::getCheckoutTotalsFromCart()`. |
| #7 Lightweight Accessors | No computing in accessors | ✅ Compliant | No promotion-related accessors compute values. |
| #8 No Hidden Work | No hidden SQL | ❌ **Violation** | `getFirstMediaUrl()` in CartItemResource. `Promotion::query()->find()` in `getCheckoutTotalsFromCart()`. |
| #9 Extensibility | Extend ProductPricingService | ✅ N/A | Promotion is separate domain. |

### 11.2 Additional Architecture Observations

**PromotionEligibilityResolver is the single source of truth for eligibility** ✅
All eligibility evaluation passes through `PromotionEligibilityResolver::resolve()` or `eligible()`. No parallel eligibility paths exist.

**Business logic in model is centralized but incorrect placement** ⚠️
`Promotion::discountAmount()` contains the discount formula, called by all strategies. It's not duplicated across strategies, but it's in the model layer.

**Strategies delegate to model instead of computing themselves** ⚠️
```php
// PercentagePromotionStrategy::computeOutcome()
$amountDecimal = $promotion->discountAmount($evaluation->matchedSubtotalCents / 100.0, $evaluation->matchedQuantity);
```
The strategy calls the model instead of computing. This means the strategy is not truly owning its calculation.

---

## 12. API Verification

### 12.1 Cart API

| Endpoint | Promotion Fields in Response | Consistent? |
|----------|------------------------------|-------------|
| `GET /api/v1/carts` | **NONE** | ❌ `total_price` may reflect discounts but no explanation |
| `GET /api/v1/carts/{id}` | **NONE** | ❌ Same |
| `POST /api/v1/carts` | **NONE** | ✅ Correct (no promotion at add time) |
| `PUT /api/v1/carts` | **NONE** | ❌ After promotion apply, total_price changes but no promotion data shown |
| `DELETE /api/v1/carts/{itemId}` | **NONE** | ✅ Correct (operation response only) |
| `DELETE /api/v1/carts` | **NONE** | ✅ Correct (operation response only) |
| `POST /api/v1/carts/pluck-items` | **NONE** | ✅ Correct (batch add) |

### 12.2 Promotion API

| Endpoint | Response Data | Consistent? |
|----------|--------------|-------------|
| `GET /api/v1/general/checkout/promotions` | `eligible_promotions[]` with id, type, title, code, discount, gift_items | ✅ Complete |
| `GET /api/v1/general/promotions` | Paginated promotion list with product data | ✅ Complete |
| `GET /api/v1/general/promotions/{slug}` | Single promotion with products, pricing enriched | ✅ Complete |

### 12.3 Checkout API

| Endpoint | Response Data | Consistent? |
|----------|--------------|-------------|
| `POST /api/v1/general/checkout` (price calc) | `total_price` (float) — single value | ❌ Returns only the total price, no breakdown |
| `POST /api/v1/general/checkout` (full) | Order data through `OrderResource` | ✅ Includes all promotion fields |

### 12.4 Order API

| Endpoint | Response Data | Consistent? |
|----------|--------------|-------------|
| `GET /api/v1/general/orders` | Orders with OrderResource/OrderCollection | ✅ Includes promotion, coupon, discount fields |
| `GET /api/v1/general/orders` (item) | Order items with OrderItemResource | ✅ Includes `promotion_discount_amount`, `is_gift`, `promotion_id` |

### 12.5 Stale Data in API Responses

| Scenario | Could API Return Stale Data? | Evidence |
|----------|------------------------------|----------|
| Cart after promotion applied, no cart modification | ❌ No | Cart items properly updated with discount |
| Cart after promotion applied, then quantity changed | ✅ **YES — STALE** | `total_price` recalculated without discount; `discount_amount` preserved from old allocation |
| Cart after promotion applied, then item removed | ✅ **YES — STALE** (partial) | Remaining items keep their share of the old allocation |
| Cart after promotion applied, then new item added | ✅ **YES — STALE** (partial) | New item has no discount, old items keep theirs |
| Cart after promotion applied, then cart cleared | ❌ No | All items deleted, no stale data |

---

## 13. Critical Questions Answered

### 13.1 Cart Modification & Promotion Revalidation

**Q1: Is every cart modification guaranteed to trigger promotion revalidation?**
**A**: No. Zero cart modification operations trigger promotion revalidation. The `CartInventoryService::reserveItem()` method, called by all add/update operations, has no reference to `PromotionService`, `PromotionEligibilityResolver`, or any promotion-related class.

**Q2: Is there any cart operation that does NOT revalidate promotion?**
**A**: All of them. Every cart operation — add, remove, update qty, change variant, clear, pluck items — does not revalidate promotions.

### 13.2 Stale Data

**Q3: Can promotion remain after eligibility is lost?**
**A**: Yes. If a promotion is deactivated/expired/deleted after being applied to cart items, the `promotion_id` and `discount_amount` on `cart_items` remain unchanged. During `addItemsInOrder()`, `getCheckoutTotalsFromCart()` reads these values and `Promotion::query()->find()` does NOT use a `valid()` scope. The order would include the stale promotion metadata.

**Q4: Can gift items remain after eligibility is lost?**
**A**: Yes. Gift items are only cleared by:
1. `PromotionService::removeGiftItems()` — called inside `applySelectedPromotion()` (which is only called during `calcInvoicePrice()`)
2. `CartInventoryService::releaseCart()` — cart cleared
3. `CartInventoryService::expireCart()` — cart expired

If a gift-promotion is deactivated, the gift items in the cart are NOT automatically removed. They persist until the next `calcInvoicePrice()` call.

**Q5: Can discount remain after eligibility is lost?**
**A**: Yes. Same as Q3. `discount_amount` on cart_items is never cleared by cart modification operations.

**Q6: Can stale promotion_id remain?**
**A**: Yes. Cart modifications preserve `promotion_id` on existing items (`reserveItem()` payload does not include it).

**Q7: Can stale discount_amount remain?**
**A**: Yes. Cart modifications preserve `discount_amount` on existing items (not in payload).

**Q8: Can stale total_price remain?**
**A**: No — but with a caveat. Cart modifications recalculate `total_price = price × quantity` (undiscounted). The new `total_price` is "correct" in that it equals the current unit price × quantity, but it NO LONGER reflects the promotion discount. This creates an inconsistency: `discount_amount` suggests a discount exists, but `total_price` doesn't reflect it.

**Q9: Can stale gift items remain?**
**A**: Yes. Gift items persist until the next promotion apply or cart clear/expiry.

**Q10: Can stale promotion metadata remain?**
**A**: Yes. On cart_items: `promotion_id`, `discount_amount`, `is_gift` all persist through cart modifications.

### 13.3 Checkout Validation

**Q11: Can checkout succeed using stale promotion data?**
**A**: **Yes**. `addItemsInOrder()` → `getCheckoutTotalsFromCart()` reads persisted data as-is. If the cart was modified after promotion apply, this data is stale. The checkout does NOT re-calculate or re-validate.

**Q12: Does checkout always validate promotion again?**
**A**: No. Only the price calculation step (`calcInvoicePrice()` → `calculateCheckoutTotals()`) validates. The order creation step (`addItemsInOrder()` → `getCheckoutTotalsFromCart()`) does NOT validate.

### 13.4 Admin Changes

**Q13: Can admin changes invalidate an already-selected promotion?**
**A**: Yes — for the next apply. Admin can deactivate, change dates, change products, or delete a promotion. The next `calcInvoicePrice()` will fail because `Promotion::valid()` won't find the promotion.

**Q14: Is this correctly handled?**
**A**: Partially. `calcInvoicePrice()` handles it correctly (throws exception). But if the promotion was already applied and the user proceeds directly to checkout (which calls `addItemsInOrder()` → `getCheckoutTotalsFromCart()`), the stale data is used without re-validation.

### 13.5 Order & Inventory Consistency

**Q15: Can order data differ from checkout?**
**A**: Yes, as shown in the scenario in section 7.4. Cart modifications between `calcInvoicePrice()` and `addItemsInOrder()` can cause order data to differ from the checkout calculation.

**Q16: Is the order snapshot complete?**
**A**: Yes. All promotion-relevant fields are stored in `orders` and `order_products` tables. The snapshot captures promotion_id, type, code, discount amount, gift status, and per-item discount allocation.

**Q17: Can inventory become inconsistent?**
**A**: Yes, for gift items in fast checkout. Gift items have `shipping_method = 'SCHEDULED'` (default), while fast checkout finalizes items with `shipping_method = 'FAST'`. Gift items are skipped during fast checkout finalization, leaving their inventory reserved.

**Q18: Can promotion usage become inconsistent?**
**A**: The TOCTOU window between eligibility check and increment could allow an order to complete without incrementing usage (if limiter was reached between the two). Usage is never decremented on order cancellation.

### 13.6 Architecture & Code Quality

**Q19: Is PromotionEligibilityResolver truly the single source of truth?**
**A**: Yes. All eligibility evaluation passes through `PromotionEligibilityResolver::resolve()` or `eligible()`. No parallel eligibility paths exist.

**Q20: Is there any duplicated promotion logic anywhere?**
**A**: Yes. Subtotal calculation is duplicated in three places. `matchedEligibility()` is called three times during a single promotion apply (two redundant).

**Q21: Is there any hidden business logic inside models?**
**A**: Yes. `Promotion::discountAmount()` contains the discount calculation formula. `Promotion::isValid()` contains validity logic. `Promotion::isRequiredQuantityTrue()` contains quantity threshold logic.

**Q22: Is there any hidden SQL or lazy loading?**
**A**: Yes.
- `CartItemResource::getFirstMediaUrl()` — media query in Resource
- `getCheckoutTotalsFromCart()` → `Promotion::query()->find()` — hidden query
- `GiftPromotionStrategy::hasAvailableStock()` fallback query

### 13.7 Assumptions

**Q23: What assumptions does the current implementation rely on?**

1. **Assumption**: Cart is not modified between `calcInvoicePrice()` and `checkout()`. This is the single most critical assumption. The code splits checkout into two separate transactions with no guard against intervening modifications.

2. **Assumption**: Gift items have `shipping_method = 'SCHEDULED'`. This works for regular checkout but fails for fast checkout.

3. **Assumption**: `$item->price` reflects the agreed price. The price at add-to-cart time is used for all calculations. This is a valid business assumption but means promotion eligibility is based on potentially stale prices.

4. **Assumption**: Promotion metadata is always available from the database. `getCheckoutTotalsFromCart()` calls `Promotion::find()` without a valid scope. If the promotion is deleted, metadata is lost.

5. **Assumption**: Only one promotion is applied per cart. `getCheckoutTotalsFromCart()` picks the first item with `promotion_id` — if multiple promotions somehow exist on different items, only the first is reported.

6. **Assumption**: The frontend calls `calcInvoicePrice()` before `checkout()`. The order creation reads persisted data that was written by `calcInvoicePrice()`. If the frontend calls `checkout()` directly without calling `calcInvoicePrice()` first, no promotion is applied.

7. **Assumption**: `$cart->items` in `addItemsInOrder()` includes gift items. `getCartUser()` filters to `shipping_method = 'SCHEDULED'`. Gift items have `shipping_method = 'SCHEDULED'` (default), so they ARE included. This assumption holds.

**Q24: Which assumptions are unsafe?**

| Assumption | Safety | Risk |
|-----------|--------|------|
| #1: No cart modification between price calc and checkout | ❌ **Unsafe** | Stale/corrupted promotion data in order |
| #2: Gift items always SCHEDULED | ❌ **Unsafe** | Inventory leak in fast checkout |
| #3: Price at add-to-cart time is current | ✅ Safe (by design) | Stale pricing is a business decision |
| #4: Promotion always exists in DB | ⚠️ Mostly safe | Metadata loss if deleted |
| #5: Single promotion per cart | ✅ Safe | Enforced by architecture |
| #6: calcInvoicePrice is called before checkout | ❌ **Unsafe** | No promotion applied if not called |
| #7: Gift items are SCHEDULED | ✅ Safe | Column default ensures this |

---

## 14. Confirmed Bugs

### Bug 1 — P1: `getCheckoutTotalsFromCart()` Does Not Re-Validate Promotion

**Location**: `OrderService::addItemsInOrder()` → `getCheckoutTotalsFromCart()` (line 150, 280-322)

**Description**: Regular checkout's order creation step reads persisted promotion data from `cart_items` without re-validating the promotion. It also calls `Promotion::find()` without using the `valid()` scope, so deactivated/expired promotions are still included in the order.

**Impact**: If cart is modified between price calc and checkout, order totals are wrong. If promotion is deactivated, it's still applied to the order.

**Evidence**:
```php
// OrderService.php line 280-303
$promotionItem = $items->first(fn($item) => !is_null($item->promotion_id));
if ($promotionItem) {
    $promotion = Promotion::query()->find((int) $promotionItem->promotion_id);  // No valid() scope
    $promotionData = $promotion ? [...] : null;
}
```

### Bug 2 — P1: Gift Item `shipping_method` Not Set

**Location**: `CartInventoryService::reserveGiftItem()` (line 137-147)

**Description**: Gift items are created without an explicit `shipping_method` in the payload. They receive the column default (`SCHEDULED`). Fast checkout finalizes items with `shipping_method = 'FAST'`, so gift items are never finalized.

**Impact**: Inventory leak for gift products in fast checkout. Gift inventory remains reserved indefinitely until cart expiry (3 days).

**Evidence**:
```php
// CartInventoryService.php line 137-147
$payload = [
    'product_id' => $product->id,
    'product_variant_id' => $variant?->id,
    'quantity' => $desiredQuantity,
    'reserved_quantity' => $desiredQuantity,
    'price' => 0,
    'total_price' => 0,
    'attributes' => null,
    'is_gift' => true,
    'promotion_id' => $promotion->id,
    // 'shipping_method' is MISSING
];
```

### Bug 3 — P2: `discount_amount` Becomes Stale After Cart Modification

**Location**: `CartInventoryService::reserveItem()` (line 51-60)

**Description**: When a cart item's quantity is updated after a promotion was applied, `reserveItem()` recalculates `total_price = price × quantity` (without discount) but does NOT clear or recalculate `discount_amount` or `promotion_id`. The item has a stale `discount_amount` that no longer reflects the actual price reduction.

**Impact**: `getCheckoutTotalsFromCart()` reads the stale `discount_amount` and produces incorrect order totals.

**Evidence**:
```php
// CartInventoryService.php line 51-60
$payload = [
    'product_id' => $product->id,
    'product_variant_id' => $variant?->id,
    'quantity' => $desiredQuantity,
    'reserved_quantity' => $desiredQuantity,
    'price' => $price,
    'total_price' => $price * $desiredQuantity,   // WITHOUT discount
    'attributes' => ...,
    'shipping_method' => $shippingMethod,
    // 'promotion_id' MISSING → preserved from previous value
    // 'discount_amount' MISSING → preserved from previous value
];
```

### Bug 4 — P3: Gift Items Not Cleared When Promotion Is Removed

**Location**: `PromotionService::applySelectedPromotion()` (line 55-113)

**Description**: When `applySelectedPromotion()` is called with `$promotionId = null`, gift items ARE cleared (by `removeGiftItems()` on line 57), but `promotion_id` and `discount_amount` on regular items are NOT cleared (because `applyOutcome()` is only called inside the `if ($promotionId)` block). If the customer explicitly removes their promotion selection, discount persists on items.

**Evidence**:
```php
public function applySelectedPromotion(Cart $cart, ?int $promotionId, ...): CheckoutTotals
{
    $this->removeGiftItems($cart);  // ← Always called
    // ...
    if ($promotionId) {             // ← discount only cleared INSIDE this block
        // applies promotion, calls applyOutcome()
    }
    // Return CheckoutTotals with empty promotion
    // But items still have previous promotion_id and discount_amount!
}
```

---

## 15. Potential Risks

### Risk 1 — Medium: `getCheckoutTotalsFromCart()` Uses `Promotion::query()->find()` Without `valid()` Scope

If an admin deactivates a promotion that a customer has already applied to their cart, and the customer completes checkout, the order will include promotion metadata from the deactivated promotion. The discount amounts on order items are correct (computed from `price × quantity - total_price`), but the order records that the promotion was applied even though it was deactivated at the time.

### Risk 2 — Medium: No Guard Against Cart Modification Between calcInvoicePrice and checkout

The regular checkout flow is two separate HTTP requests (or at least two separate transactions). There is no:
- Cart versioning
- Lock on the cart between steps
- Re-validation on the second step
- Any check that the cart hasn't changed

### Risk 3 — Low: Flash Sale Status Re-queried at Order Creation

In `OrderCreationService::createOrderItems()`, flash sale status is re-queried from the database. This may not match the price the customer agreed to at add-to-cart time.

### Risk 4 — Low: Promotion Metadata Loss When Promotion Is Deleted

`getCheckoutTotalsFromCart()` queries `Promotion::find($promotionItem->promotion_id)`. If the promotion was deleted, this returns null and the order loses `promotion_type`, `promotion_code`, and `promotion_id` (at the order level). Per-item `promotion_id` retains the raw attribute from `cart_items`.

### Risk 5 — Low: Usage Counter Not Decremented on Order Cancellation

A promotion's usage limit can be exhausted by orders that were later cancelled. This is a business decision but should be documented and potentially configurable.

---

## 16. Architecture Violations

| # | Violation | File | Severity | Details |
|---|-----------|------|----------|---------|
| 1 | Business logic in model | `Promotion.php:202` `discountAmount()` | Medium | Discount calculation formula in Model layer |
| 2 | Hidden SQL in Resource | `CartItemResource.php:27` `getFirstMediaUrl()` | Low | Media query during serialization |
| 3 | Hidden SQL in Service | `OrderService.php:297` `Promotion::query()->find()` | Low | Separate query during order creation |
| 4 | Duplicated subtotal | 3 files | Low | Same formula in 3 classes |
| 5 | Controller contains business logic | `CartController.php:90-112` | Low | Direct inventory/update calls |
| 6 | Redundant `matchedEligibility()` | `PromotionService.php:91` | Low | Called after `resolve()` already called it |

---

## 17. Open Questions

These questions cannot be definitively answered from the current codebase alone:

1. **Does the frontend always call `calcInvoicePrice()` before `checkout()`?** The system assumes this, but there is no server-side enforcement. If a client calls `checkout()` directly, `addItemsInOrder()` reads whatever is on cart_items at that moment. If no promotion was applied, `getCheckoutTotalsFromCart()` finds no items with `promotion_id` and returns zero promotion data.

2. **What is the default value of `cart_items.shipping_method`?** The migration file was not inspected. Gift items rely on this default being `'SCHEDULED'`. If the default changes, gift items break.

3. **Is there a foreign key constraint on `cart_items.promotion_id`?** The migration file was not inspected. If no FK constraint exists, deleted promotions leave orphaned IDs. If FK exists with ON DELETE SET NULL, deleted promotions would clear the promotion_id on cart items.

4. **What happens if `finalizeOrder()` fails after `incrementUsage()` succeeds but before `OrderCreated::dispatch()`?** The transaction rolls back (in both `addItemsInOrder()` and `createFastOrder()`). The `incrementUsage()` query is inside the transaction, so the increment is rolled back. But if `incrementUsage()` was called inside `finalizeOrder()`, which is before the dispatch, and the dispatch throws, the rollback would catch it. However, `finalizeOrder()` wraps the dispatch in try/catch:
   ```php
   try { OrderCreated::dispatch($order); } catch (\Throwable $e) { report($e); }
   ```
   So the dispatch failure is swallowed. The order is created, usage is incremented, but the event is not dispatched. This is acceptable (eventual consistency).

5. **Does `CartInventoryService::getActiveCartForUser()` return the same cart as `OrderService::getCartUser()`?** `getActiveCartForUser()` loads items without shipping method filter, while `getCartUser()` filters to SCHEDULED only. They return the same cart model but with different loaded relations. The checkout controller uses `getActiveCartForUser()` in `ensureCartReservation()`, while the order service uses `getCartUser()` for price calculation and order creation.

---

## 18. Evaluation of `has_eligible_promotion`

### 18.1 Is It Actually Needed?

**Arguments for NEEDED:**
- The cart response currently has zero promotion-related information. Frontend cannot show "eligible for promotion" badges.
- Requires 2 API calls to determine if cart qualifies for any promotion.
- Would enable proactive UX (badge, notification, banner).

**Arguments against:**
- The current architecture intentionally separates cart (read-only data) from checkout (promotions). This is a design choice, not a bug.
- The frontend can call `/checkout/promotions` when needed.
- Adding eligibility to cart adds DB load on every cart view.

**Verdict**: It is not strictly needed for correctness. It is a UX enhancement.

### 18.2 Can Existing `PromotionEligibilityResolver` Be Reused?

Yes, but with a significant performance concern:

```php
// Current eligiblePromotions() loads ALL promotions with gift details
$promotions = Promotion::valid()
    ->with([
        'products:id',
        'giftProducts:id,...',
        'giftProducts.variations...',
        'giftProducts.variations.attributeProducts.attributeValue.attribute',
    ])
    ->get();

return $this->resolver->eligible($cart, $promotions, $subtotalCents);
```

This is the existing method. It's heavy — it loads all valid promotions with gift product details. Using this directly for a boolean flag would be unnecessarily expensive.

A lightweight version would:
1. Load promotions with only `products:id` (no gift products)
2. Iterate until first eligible match
3. Early-exit

### 18.3 Would It Duplicate Business Logic?

If the `hasEligiblePromotions()` method calls `PromotionEligibilityResolver::resolve()` (the single source of truth), there is NO duplication. The resolver is reused directly.

If `hasEligiblePromotions()` re-implements eligibility logic (e.g., checking `$promotion->isValid()` + subtotal checks), that WOULD be duplication and must be avoided.

### 18.4 Would It Violate Architecture?

If implemented as **pre-serialization enrichment in the controller**:
```php
// Controller
$cart->has_eligible_promotion = $this->promotionService->hasEligiblePromotions($cart);

// Resource
'has_eligible_promotion' => $this->has_eligible_promotion ?? false,
```

This follows the same pattern as `ProductPricingService::enrichProductWithPricing()` and does not violate the frozen architecture (which governs product pricing, not promotion). Resource remains a serializer (reads a pre-set attribute). Controller remains an orchestrator (calls service, sets attribute).

**If implemented directly in `CartResource::toArray()`**: ❌ Violates Resource Purity (Rule #3).

### 18.5 Where Is the Correct Integration Point?

```
CartController::show()
    ├── CartRepository → load cart with items, products, variants
    ├── PromotionService::hasEligiblePromotions($cart)  ★ NEW lightweight method
    │       ├── Load valid promotions (lightweight: only products:id)
    │       ├── Compute subtotal in cents
    │       └── PromotionEligibilityResolver::eligible() → first match = early exit
    │
    ├── $cart->has_eligible_promotion = $result
    └── CartResource::make($cart) → includes the boolean field
```

The same integration point must be added to:
- `CartController::index()`
- `CartController::store()`
- `CartController::update()`
- `CartController::pluckItemsToCart()`

### 18.6 Would Another Solution Be Architecturally Better?

| Alternative | Architecture Impact | Complexity | Performance | Recommendation |
|-------------|-------------------|------------|-------------|---------------|
| Pre-serialization enrichment (as above) | ✅ Compliant | Low | Medium (1-2 extra queries) | ✅ **Best** |
| Composite endpoint `/cart-with-promotions` | ✅ Compliant | High | Same | ❌ YAGNI |
| Frontend always calls `/checkout/promotions` separately | ✅ No backend change | None | Same or better (cached) | ⚠️ Viable alternative |
| Cache eligibility result on cart model | ✅ Compliant | Medium | Better (no query on reads) | ⚠️ Cache invalidation complexity |
| Always compute in CartResource (inline) | ❌ Violates Resource Purity | Low | Same | ❌ Avoid |

---

## 19. Prioritized Technical TODO

### P1 — Critical (Data Integrity)

| # | Issue | Current Behavior | Expected | Risk | Files |
|---|-------|-----------------|----------|------|-------|
| 1 | `getCheckoutTotalsFromCart()` does not re-validate promotion | Order creation reads stale `discount_amount` | Must re-calculate promotion from fresh cart data, or at minimum re-validate and re-compute totals | Order totals wrong | `app/Services/General/OrderService.php:280-322` |
| 2 | Gift item `shipping_method` not set | Gift items default to `SCHEDULED`; fast checkout does not finalize them | Gift items should get explicit `shipping_method` matching the checkout context | Inventory leak for gift items in fast checkout | `app/Services/General/CartInventoryService.php:137-147` |

### P2 — High (Incorrect State)

| # | Issue | Current Behavior | Expected | Risk | Files |
|---|-------|-----------------|----------|------|-------|
| 3 | Cart quantity change does not clear `discount_amount` | `reserveItem()` recalculates `total_price` without discount but preserves `discount_amount` | `discount_amount` and `promotion_id` should be cleared when cart items are modified after promotion apply | Stale discount in order | `app/Services/General/CartInventoryService.php:51-60` |
| 4 | `getCheckoutTotalsFromCart()` uses `Promotion::find()` without `valid()` scope | Deactivated promotion still found and included in order metadata | Must use `Promotion::valid()->find()` or equivalent | Deactivated promotion applied to order | `app/Services/General/OrderService.php:297` |
| 5 | `applySelectedPromotion()` called with null does not clear discount on items | `removeGiftItems()` clears gifts but discount on regular items persists | All promotion data should be cleared when promotion is removed | Customer "removes" promotion but discount remains | `app/Services/General/PromotionService.php:55-113` |

### P3 — Medium (Architecture & Consistency)

| # | Issue | Current Behavior | Expected | Risk | Files |
|---|-------|-----------------|----------|------|-------|
| 6 | `Promotion::discountAmount()` business logic in model | Discount formula in model | Strategies should own calculation | Model purity violation | `packages/marvel/src/Database/Models/Promotion.php:202` |
| 7 | Duplicated subtotal formula in 3 places | Same `price × qty` logic in 3 classes | Extract to shared method/helper | Inconsistency if formula changes | `PromotionService::subtotal()`, `PromotionEligibilityResolver::matchedEligibility()`, `OrderService::getCheckoutTotalsFromCart()` |
| 8 | Redundant `matchedEligibility()` call in `applySelectedPromotion()` | Called after `resolve()` already called it | Remove redundant call | Double computation | `app/Services/General/PromotionService.php:91` |
| 9 | Cart response has no promotion fields | `promotion_id`, `discount_amount`, `is_gift` not serialized | Expose existing DB fields | Frontend cannot determine promotion state | `packages/marvel/src/Http/Resources/CartItemResource.php` |

### P4 — Low (Enhancement)

| # | Issue | Current Behavior | Expected | Risk | Files |
|---|-------|-----------------|----------|------|-------|
| 10 | `getCheckoutTotalsFromCart()` picks first item's `promotion_id` | If multiple items have different promotion_ids, only first is reported | Guard or aggregate | Incorrect promotion metadata | `app/Services/General/OrderService.php:294` |
| 11 | `getCheckoutTotalsFromCart()` and `calculateCheckoutTotals()` use different coupon discount formulas | One uses `subtotal - promotionDiscount - finalTotal`, the other uses `priceAfterPromotion - finalTotal` | Use consistent formula | 1-cent discrepancy | `app/Services/General/OrderService.php:316, 344` |
| 12 | No `decrementUsage()` for cancelled orders | Usage never decreased | Add method if business requires | Promotion slot wasted | `app/Services/General/PromotionService.php` |

---

## Document Metadata

- **Author**: AI Code Analysis
- **Date**: 2026-07-13
- **Purpose**: Complete Promotion System Architecture & Lifecycle Verification
- **Scope**: Analysis only — no code was modified
- **Architecture**: ADR-001 (Frozen) respected
