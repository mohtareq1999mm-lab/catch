# Financial Verification Audit

## Table of Contents

1. [Scope](#1-scope)
2. [Formula Catalog](#2-formula-catalog)
3. [Rounding Strategy](#3-rounding-strategy)
4. [Promotion Proportional Allocation](#4-promotion-proportional-allocation)
5. [Coupon Calculation](#5-coupon-calculation)
6. [Complete Pipeline Walkthrough](#6-complete-pipeline-walkthrough)
7. [Edge Cases](#7-edge-cases)
8. [Cross-Validation](#8-cross-validation)
9. [Issues Found](#9-issues-found)

---

## 1. Scope

This document verifies every financial formula used in the checkout pipeline:

| Stage | Formula | Location |
|-------|---------|----------|
| Cart item base price | `total_price = price * quantity` | `CartInventoryService::reserveItem()` |
| Line total (for subtotal) | `sum(price * quantity)` for non-gift items | `PromotionService::subtotal()` |
| Promotion discount | Proportional allocation (largest remainder) | `PromotionApplicator::applyOutcome()` |
| Coupon discount | `price * (discount/100)` or `min(discount, price)` | `CouponCalculator::calculate()` |
| Final total | `max(0, priceAfterPromotion - couponDiscount)` | `OrderService::calculateCheckoutTotals()` |
| Order total | `round(finalTotal + shipping + fastFee, 2)` | `OrderCreationService::createOrder()` |
| Financial invariant | `subtotal - promo - coupon + shipping + fastFee = total` | `FinancialInvariantValidator` |

### Currency Convention

All monetary values stored as `decimal(10,3)` in the database but PHp uses `float` with `round(x, 2)`. Integer cents are used internally for promotion proportional allocation to avoid floating-point rounding errors.

---

## 2. Formula Catalog

### 2.1 Product Pricing

The pricing service determines the effective unit price for a product. There are three layers:

```
basePrice = product.price or variant.price

if (flashSale active):
    effectivePrice = basePrice * (1 - flashSale.sale_percentage / 100)
else if (product.has_discount AND discount active):
    if (product.discount_type === 'percentage'):
        effectivePrice = basePrice - basePrice * (product.discount_amount / 100)
    else:  // fixed
        effectivePrice = max(0, basePrice - product.discount_amount)
else:
    effectivePrice = basePrice
```

**Verified at**: `ProductPricingService::calculateProductCurrentPrice()` and `calculateVariantCurrentPrice()`

**Rounding**: `round(effectivePrice, 2)` — standard 2-decimal rounding.

### 2.2 Cart Item Total

```php
// CartInventoryService::reserveItem():58
$payload = [
    'price' => $price,                    // effective unit price (from pricing service)
    'total_price' => $price * $desiredQuantity,  // NOT rounded here!
];
```

**Issue FIN-1**: `total_price = price * desiredQuantity` is NOT rounded. In the cart item store, the multiplication can produce values like `100.50 * 3 = 301.5` (correct) or `10.99 * 3 = 32.97000000000001` (floating-point artifact). The value IS later rounded during promotion recalculation and order creation, but the raw cart item may hold unrounded values.

### 2.3 Subtotal Calculation

```php
// PromotionService::subtotal():233-246
round((float) $cart->items
    ->reject(fn($item) => (bool) ($item->is_gift ?? false))
    ->sum(function ($item) {
        $baseLineTotal = ((float) ($item->price ?? 0)) * ((int) ($item->quantity ?? 0));
        if ($baseLineTotal > 0) {
            return $baseLineTotal;
        }
        return (float) ($item->total_price ?? 0);
    }), 2);
```

**Logic**: Sums `price * quantity` for each non-gift item. Falls back to `total_price` ONLY if `price * quantity === 0` (which would only happen if price is 0 or quantity is 0 — a gift item would be excluded above).

**Rounding**: The outer `round(..., 2)` rounds the final sum to 2 decimal places.

**Verified**: Formula is `subtotal = round(sum(price_i * qty_i), 2)` for non-gift items.

### 2.4 Promotion Discount Calculation

#### Percentage Promotion

```php
// Promotion::discountAmount():202-223
$discount = $price * ($value / 100); // value is the percentage
if ($maxValue !== null) {
    $discount = min($discount, $maxValue);
}
return round(max(0.0, $discount), 2);
```

This is called from `PercentagePromotionStrategy::computeOutcome()`:
```php
$amountDecimal = $promotion->discountAmount($evaluation->matchedSubtotalCents / 100.0, ...);
$amountCents = (int) round($amountDecimal * 100);
```

**Conversion**: The matched subtotal in cents is converted to decimal (`matchedSubtotalCents / 100.0`), the percentage is applied, and the result is converted back to cents via `(int) round(amountDecimal * 100)`.

**Issue FIN-2**: The `$price` parameter passed to `discountAmount()` is already in decimal (from `matchedSubtotalCents / 100.0`), and the discount is computed in decimal, then `round(..., 2)` is applied, and then `round(... * 100)` converts to cents. This is a double-rounding scenario:

```
Example: matchedSubtotalCents = 1333 (13.33), value = 10%
  1. amountDecimal = 13.33 * 0.10 = 1.333
  2. round(1.333, 2) = 1.33         (first rounding)
  3. amountCents = (int) round(1.33 * 100) = 133
```

The same value could be computed in cents directly:
```
  1. amountCents = (int) round(1333 * 0.10) = 133
```

Both produce 133 cents in this case, but divergence can occur at boundary values.

**Example of divergence**:
```
matchedSubtotalCents = 1099 (10.99), value = 10%
Via discountAmount:
  1. 10.99 * 0.10 = 1.099
  2. round(1.099, 2) = 1.10
  3. (int) round(1.10 * 100) = 110 cents

Direct cents:
  1. 1099 * 0.10 = 109.9
  2. (int) round(109.9) = 110 cents

Same result. But try:
matchedSubtotalCents = 995 (9.95), value = 10%
Via discountAmount:
  1. 9.95 * 0.10 = 0.995
  2. round(0.995, 2) = 1.00 (standard rounding rounds 0.995 up)
  3. (int) round(1.00 * 100) = 100 cents

Direct cents:
  1. 995 * 0.10 = 99.5
  2. (int) round(99.5) = 100 cents (rounds 99.5 up)

Still same. The double-rounding is unlikely to diverge significantly because:
- round(x, 2) already rounds to 2 decimal places
- round(x * 100) converts to cents
- The error is at most 0.5 cents

**Verdict**: FIN-2 is LOW severity. No practical divergence in tested values.

#### Fixed Rate Promotion

```php
// Promotion::discountAmount() for FIXED_RATE
return round(max(0.0, min($price, $value)), 2);
```

Simply caps the fixed discount at the price. No rounding issues.

**Conversion to cents**:
```php
$amountCents = (int) round($amountDecimal * 100);
```

### 2.5 Promotion Proportional Allocation

The total discount is allocated across matched items proportionally using the **largest remainder method** (Hare quota).

```php
// PromotionApplicator::applyOutcome():76-102

// For each matched item:
$exactShare = ($lineTotalCents * $totalDiscountCents) / $sumLineCents;
$floorShare = (int) floor($exactShare);

// Distribute remaining cents one-at-a-time to items with largest remainder
arsort($remainders);
foreach ($remainders as $idx => $rem) {
    if ($remaining <= 0) break;
    $available = $lines[$idx]['line_total_cents'] - $allocations[$idx];
    if ($available <= 0) continue;
    $give = min($available, 1);
    $allocations[$idx] += $give;
    $remaining -= $give;
}
```

**Verified properties**:
1. `sum(allocations) = totalDiscountCents` (within 1 cent rounding error)
2. Each allocation `<= itemLineTotalCents` (never discount below zero)
3. Gaps are distributed one cent at a time by largest remainder

**Edge case**: If `totalDiscountCents > sumLineCents`, the discount is capped at `min(subtotalCents, amountCents)` on line 51 before allocation. So allocation can never exceed the line total.

**Issue FIN-3**: The allocation uses `$baseCents` (from `outcome->baseAmountCents`) as a cap on line 51-52:
```php
$amountCents = min($subtotalCents, $outcome->amountCents);
$baseCents = max(0, $outcome->baseAmountCents);
```
But `$baseCents` is never used after this. The `min($subtotalCents, ...)` already caps the discount. The `$baseCents` is vestigial. No functional impact.

### 2.6 Coupon Calculation

```php
// CouponCalculator::calculate()
if ($coupon->discount_type === DiscountType::PERCENTAGE) {
    $discountAmount = $price * ($discount / 100);
    if ($coupon->max_discount_amount !== null) {
        $discountAmount = min($discountAmount, (float) $coupon->max_discount_amount);
    }
} elseif ($coupon->discount_type === DiscountType::FIXED_RATE) {
    $discountAmount = min($discount, $price);
}
// FREE_SHIPPING type: discountAmount = 0 (handled separately)

$discountAmount = round(max(0, $discountAmount), 2);
$finalPrice = round(max(0, $price - $discountAmount), 2);
```

**Verified**:
- Percentage: `discount = price * (value / 100)`, capped at `max_discount_amount`
- Fixed rate: `discount = min(value, price)`
- Free shipping: `discount = 0` (shipping is zeroed separately)
- Final: `finalPrice = max(0, price - discount)`, rounded to 2 decimal places

**Consistency check**: Coupon percentage formula is identical to promotion percentage formula.

### 2.7 CheckoutTotals Construction

```php
// OrderService::calculateCheckoutTotals():436-463
$promotionTotals = $this->promotionService->applySelectedPromotion(
    $cart, $selectedPromotionId, $selectedGiftProductId, $shippingMethod
);
$priceAfterPromotion = $promotionTotals->finalTotal;

$couponResult = $this->calculatePriceByCoupon($cart, $priceAfterPromotion);
$finalTotal = round(max(0, (float) $couponResult['finalPrice']), 2);

return new CheckoutTotals(
    subtotal: $promotionTotals->subtotal,
    promotionDiscount: $promotionTotals->promotionDiscount,
    couponDiscount: round(max(0, (float) $priceAfterPromotion - (float) $finalTotal), 2),
    finalTotal: $finalTotal,
    // ...
);
```

**Formula chain**:
```
subtotal = Σ(price_i * qty_i)                     // from PromotionService::subtotal()
priceAfterPromotion = Σ(total_price_i)             // from cart items after promotion write-back
couponDiscount = max(0, priceAfterPromotion - finalTotal)
finalTotal = max(0, priceAfterPromotion - couponDiscountAmount)
```

**Verified**: The `couponDiscount` is computed as the DIFFERENCE between `priceAfterPromotion` and `finalTotal`, which is the actual coupon amount applied. This is correct even if the coupon is percentage-based.

**However, note the double computation**:

```
Step 1: couponDiscountAmount = coupon.calculate(priceAfterPromotion)
Step 2: finalTotal = priceAfterPromotion - couponDiscountAmount
Step 3: couponDiscount = priceAfterPromotion - finalTotal (= couponDiscountAmount, by definition)
```

Steps 2 and 3 are redundant. `couponDiscount` always equals `couponDiscountAmount`. This is not a bug, just a redundant recalculation.

### 2.8 Order Total

```php
// OrderCreationService::createOrder():28-30
$totalPrice = round((float) $checkoutTotals->finalTotal + $shippingPrice + ($fastShippingFee ?? 0), 2);
```

The shipping price is resolved separately:
```php
// OrderService::resolveShippingPrice() + free shipping logic
$shippingPrice = $this->resolveFreeShippingByThreshold($subtotal, $threshold, $basePrice);
if ($freeShippingCoupon) {
    $shippingPrice = 0;
}
```

**Free shipping by threshold**:
```php
// OrderService::resolveFreeShippingByThreshold()
if ($freeShippingOver !== null && $subtotal > $freeShippingOver) {
    return 0;
}
return $shippingPrice;
```

**Issue FIN-4**: The free shipping threshold uses `$checkoutTotals->subtotal` (line 131), which is the PRE-discount subtotal. If a promotion or coupon brings the final total below the threshold, shipping is still free because the threshold check uses subtotal. **This is the correct behavior** — free shipping should be based on pre-discount purchase value.

### 2.9 Financial Invariant (Snapshot)

```php
// FinancialInvariantValidator::validate()
$computedTotal = $subtotal - $promotionDiscount - $couponDiscount + $shippingPrice + $fastShippingFee;
$diff = abs($computedTotal - $declaredTotal);
assert($diff <= 0.01);
```

**Verified**: This matches `OrderCreationService::createOrder()`:
```php
$totalPrice = round($finalTotal + $shippingPrice + $fastShippingFee, 2);
// = round((subtotal - promotionDiscount - couponDiscount) + shipping + fastFee, 2)
```

Because `finalTotal = subtotal - promotionDiscount - couponDiscount` (approximately — there's a two-step process where promotionDiscount modifies cart item totals and couponDiscount is applied on top, but the net effect is the same).

---

## 3. Rounding Strategy

### Rounding at Each Stage

| Stage | Rounding | Function |
|-------|----------|----------|
| Product price (DB) | Stored as decimal, read as float | No rounding |
| Cart item total | **NOT rounded** | `price * qty` |
| Subtotal | `round(..., 2)` | PHP `round()` |
| Promotion discount amount | `round(..., 2)` | `Promotion::discountAmount()` |
| Promotion per-item allocation | Integer cents (no float) | `(int) floor()` + largest remainder |
| Promotion per-item discount (DB) | `number_format(..., 2, '.', '')` | String → DB decimal column |
| Promotion per-item total (DB) | `number_format(..., 2, '.', '')` | String → DB decimal column |
| Cart total (after promotion) | `round(..., 2)` | `PromotionApplicator` |
| Coupon discount amount | `round(..., 2)` | `CouponCalculator::calculate()` |
| Coupon final price | `round(max(0, ...), 2)` | `CouponCalculator::calculate()` |
| CheckoutTotals finalTotal | `round(..., 2)` | `OrderService::calculateCheckoutTotals()` |
| Order total_price | `round(..., 2)` | `OrderCreationService::createOrder()` |
| Invoice total (snapshot) | Stored as float | `InvoiceSnapshotService` |

### Rounding Mode

PHP's `round()` uses **round half away from zero** (PHP_ROUND_HALF_UP) by default:
- `round(1.5) = 2`
- `round(2.5) = 3`
- `round(1.05, 1) = 1.1`

This is the standard commercial rounding. No issues.

### Issue: number_format vs round

In `PromotionApplicator::applyOutcome():115-119`:
```php
$item->forceFill([
    'discount_amount' => number_format($alloc / 100.0, 2, '.', ''),
    'total_price' => number_format($newTotalPrice, 2, '.', ''),
])->save();
```

**Issue FIN-5**: `number_format()` returns a STRING. Eloquent's `forceFill()` + `save()` will cast it appropriately for the decimal column, but there's a subtle difference:
- `number_format(1.1, 2) = "1.10"` (string)
- `round(1.1, 2) = 1.1` (float)

`number_format` will always produce exactly 2 decimal places, while `round` may produce `1.1` (1 decimal). When stored in a `DECIMAL(10,3)` column, both work correctly. Not a bug, but inconsistent.

---

## 4. Promotion Proportional Allocation

### How It Works

1. **Total discount** is determined by the promotion strategy (percentage or fixed rate applied to matched subtotal)
2. **Per-item allocation**: Each matched item gets a share proportional to its line total
3. **Largest remainder method**: The floor allocation is given first, then remaining cents are distributed one-by-one to items with the largest fractional remainder

### Example 1: Three Items, 10% Percentage Discount

```
Items:
  A: 10.00 × 2 = 20.00 (2000 cents)
  B: 15.50 × 1 = 15.50 (1550 cents)
  C: 5.25 × 3 = 15.75 (1575 cents)

Total: 51.25 (5125 cents)
Discount: 10% = 5.125 → round to 513 cents

Allocation:
  A: floor(2000 * 513 / 5125) = floor(200.2) = 200 cents, remainder = 0.2
  B: floor(1550 * 513 / 5125) = floor(155.1) = 155 cents, remainder = 0.1
  C: floor(1575 * 513 / 5125) = floor(157.6) = 157 cents, remainder = 0.6

Sum floor: 200 + 155 + 157 = 512
Remaining: 513 - 512 = 1 cent → given to C (largest remainder 0.6)

Final:
  A: 200 cents (2.00)
  B: 155 cents (1.55)
  C: 158 cents (1.58)
  Total: 513 cents ✓
```

### Example 2: Fixed Rate ₹10 on $30 Total

```
Items:
  A: 10.00 (1000 cents)
  B: 20.00 (2000 cents)

Total: 30.00 (3000 cents)
Discount: 10.00 (1000 cents)

Allocation:
  A: floor(1000 * 1000 / 3000) = floor(333.33) = 333 cents
  B: floor(2000 * 1000 / 3000) = floor(666.66) = 666 cents

Sum floor: 333 + 666 = 999
Remaining: 1000 - 999 = 1 cent → given to B (largest remainder 0.66 > 0.33)

Final:
  A: 333 cents (3.33)
  B: 667 cents (6.67)
  Total: 1000 cents ✓
```

### Correctness

The largest remainder method guarantees:
- **Proportionality**: Each item's allocation is proportional to its line total
- **Exact sum**: `sum(allocations) = totalDiscount` (within integer arithmetic)
- **No negative discounts**: Each allocation `>= 0`
- **No discounts below zero**: Each allocation `<= lineTotal`

**Verdict**: The promotion allocation is mathematically correct.

---

## 5. Coupon Calculation

### Percentage Coupon

```php
$discountAmount = $price * ($discount / 100);
if ($coupon->max_discount_amount !== null) {
    $discountAmount = min($discountAmount, (float) $coupon->max_discount_amount);
}
$discountAmount = round(max(0, $discountAmount), 2);
$finalPrice = round(max(0, $price - $discountAmount), 2);
```

**Examples**:

| Price | Discount | Max Amount | Result | 
|-------|----------|------------|--------|
| 100.00 | 10% | null | discount=10.00, final=90.00 |
| 100.00 | 10% | 5.00 | discount=5.00, final=95.00 |
| 50.00 | 15% | null | discount=7.50, final=42.50 |
| 50.00 | 15% | 10.00 | discount=7.50, final=42.50 |

### Fixed Rate Coupon

```php
$discountAmount = min($discount, $price);
```

| Price | Discount | Result |
|-------|----------|--------|
| 100.00 | 10.00 | discount=10.00, final=90.00 |
| 5.00 | 10.00 | discount=5.00, final=0.00 |

### Free Shipping Coupon

```php
$freeShipping = $discount_type === DiscountType::FREE_SHIPPING;
// discountAmount = 0, finalPrice = price
// Shipping price is zeroed separately in OrderService::addItemsInOrder()
```

**Verdict**: Coupon formulas are correct and consistent with promotion formulas.

---

## 6. Complete Pipeline Walkthrough

### Scenario: Cart with 2 items + 10% promotion + 5% coupon + shipping

```
Cart Items:
  Product A: unit_price=25.00, qty=2 → line=50.00
  Product B: unit_price=30.00, qty=1 → line=30.00

Step 1: Subtotal
  subtotal = 50.00 + 30.00 = 80.00

Step 2: Apply Promotion (10% on all products)
  matchedSubtotal = 80.00 (8000 cents)
  totalDiscount = round(80.00 * 0.10, 2) = 8.00 (800 cents)
  
  Allocation (largest remainder):
    A: floor(5000 * 800 / 8000) = 500 cents
    B: floor(3000 * 800 / 8000) = 300 cents
    Sum floor = 800, remaining = 0
  
  Item A: total_price = 50.00 - 5.00 = 45.00
  Item B: total_price = 30.00 - 3.00 = 27.00
  
  priceAfterPromotion = 45.00 + 27.00 = 72.00
  promotionDiscount = 8.00

Step 3: Apply Coupon (5% on priceAfterPromotion)
  discountAmount = round(72.00 * 0.05, 2) = 3.60
  finalTotal = round(72.00 - 3.60, 2) = 68.40
  couponDiscount = 72.00 - 68.40 = 3.60

Step 4: Shipping
  shippingPrice = 10.00 (assuming no free shipping)
  
Step 5: Order Total
  totalPrice = round(68.40 + 10.00, 2) = 78.40

Step 6: Financial Invariant Check
  subtotal(80.00) - promo(8.00) - coupon(3.60) + shipping(10.00) = 78.40 ✓
  equals declared total 78.40 ✓
```

### Verification of All Cross-Checks

```
Check 1: sum(items.total_price) = subtotal before discounts
  = 50.00 + 30.00 = 80.00 ✓ matches subtotal before promotion

Check 2: sum(items.total_price after promo) = priceAfterPromotion
  = 45.00 + 27.00 = 72.00 ✓

Check 3: coupon % applied to priceAfterPromotion
  72.00 * 5% = 3.60 ✓

Check 4: order total
  68.40 + 10.00 = 78.40 ✓
```

---

## 7. Edge Cases

### Edge Case 1: Zero Subtotal (All Gifts)

If all items are gifts (price = 0, total_price = 0):
- `subtotal = 0`
- `promotionDiscount = 0`
- `couponDiscount = 0`
- `finalTotal = 0`
- `totalPrice = 0 + shipping`
- **Verdict**: Works. Coupon won't apply because `priceAfterPromotion = 0`. Checkout may still proceed if minimum order is met (but minimum is based on subtotal, which is 0, so this depends on config).

### Edge Case 2: Promotion + Coupon Exceed Subtotal

```
Subtotal: 50.00
Promotion: 30% = 15.00 (with max_discount_amount = 20.00)
priceAfterPromotion = 50.00 - 15.00 = 35.00

Coupon: 50% = 17.50
finalTotal = round(max(0, 35.00 - 17.50), 2) = 17.50

Total discount: 15.00 + 17.50 = 32.50 > subtotal(50.00) - no, it equals 50 - 17.50 = 32.50
```

**Verdict**: The coupon `max(0, ...)` guard prevents negative final totals. The promotion cap at `max_discount_amount` prevents excessive discount. Both formulas are independently guarded.

### Edge Case 3: Free Shipping Coupon + Free Shipping Threshold

Both conditions checked:
1. `resolveFreeShippingByThreshold()` — subtotal > threshold
2. `$freeShippingCoupon` flag — if coupon is FREE_SHIPPING type

If both are true, shipping = 0. The free shipping coupon flag takes precedence (set to 0 after threshold check). No double-zeroing issue.

### Edge Case 4: Coupon Becomes Invalid at Checkout

**BUG CPN-1** (from Phase 2): If a coupon expires between cart add and checkout, `$cart->update(['coupon' => null])` clears it from DB but the in-memory `$cart->coupon` is stale. `calculatePriceByCoupon()` reads stale value and re-applies the coupon.

**Financial impact**: The coupon discount may be incorrectly applied to a coupon that should no longer be valid. This is a MEDIUM severity bug.

### Edge Case 5: Promotion Allocation with 1 Cent Items

```
Item: 0.01 × 1 = 1 cent
Discount: 10% = 0.1 cent → floor = 0 cents
Allocation: 0 cents
Remaining: 0 cents (since 0.1 < 1)
```

**Verdict**: The item gets 0 discount, the discount amount is essentially lost (1 cent → 0). But with 1-cent line totals and 10% discount, the correct discount is 0.1 cents which cannot be represented. The system rounds down, and the remaining is distributed. If there are multiple items, the gap from this item is redistributed to others.

This is an inherent limitation of integer-cent arithmetic with very small amounts.

---

## 8. Cross-Validation

### Cross-Check 1: DTO Fields vs Order Columns

| CheckoutTotals Field | Order Column | Match? |
|---------------------|--------------|--------|
| `subtotal` | `orders.price` | YES |
| `promotionDiscount` | `orders.promotion_discount` | YES |
| `couponDiscount` | `orders.coupon_discount` | YES |
| `finalTotal` | (not stored directly) | N/A |
| `totalPrice` = finalTotal + shipping | `orders.total_price` | YES |
| `coupon` | `orders.coupon` | YES |
| `couponDiscountType` | `orders.coupon_discount_type` | YES |
| `couponDiscountMaxAmount` | `orders.coupon_discount_max_amount` | YES |
| `promotion.id` | `orders.promotion_id` | YES |
| `promotion.code` | `orders.promotion_code` | YES |
| `promotion.type` | `orders.promotion_type` | YES |

### Cross-Check 2: Snapshot vs Order Columns

| Snapshot Field | Order Column | Match? |
|----------------|--------------|--------|
| `pricing_breakdown.subtotal` | `orders.price` | YES |
| `pricing_breakdown.promotion_discount` | `orders.promotion_discount` | YES |
| `pricing_breakdown.coupon_discount` | `orders.coupon_discount` | YES |
| `pricing_breakdown.total` | `orders.total_price` | YES |
| `pricing_breakdown.shipping_price` | `orders.shipping_price` | YES |
| `items[].total_price` | `order_items.product_total_price` | YES |
| `items[].unit_price` | `order_items.product_price` | YES |
| `items[].quantity` | `order_items.product_quantity` | YES |
| `items[].promotion_discount_amount` | `order_items.promotion_discount_amount` | YES |

### Cross-Check 3: Snapshot Financial Invariant

The `FinancialInvariantValidator` checks:
```
computedTotal = subtotal - promotion_discount - coupon_discount + shipping_price + fast_shipping_fee
assert |computedTotal - declaredTotal| <= 0.01
```

This matches the order total formula. The tolerance of 0.01 accounts for rounding differences in intermediate calculations.

---

## 9. Issues Found

| ID | Severity | Location | Description |
|----|----------|----------|-------------|
| FIN-1 | LOW | `CartInventoryService:58` | `total_price = price * desiredQuantity` is NOT rounded. Floating-point artifacts like `10.99 * 3 = 32.97000000000001` may be stored in the cart item. The value is later rounded during promotion recalculation and order creation, but the raw cart item may hold unrounded values. |
| FIN-2 | LOW | `PercentagePromotionStrategy::computeOutcome()` + `Promotion::discountAmount()` | Double-rounding: `discountAmount()` rounds to 2 decimals, then `round(x*100)` converts to cents. In practice, this does not diverge from single-rounding (direct cents). |
| FIN-3 | INFO | `PromotionApplicator::applyOutcome():52` | `$baseCents` is computed from `outcome->baseAmountCents` but never used. Vestigial code. |
| FIN-4 | INFO | `OrderService:131` | Free shipping threshold checks `$checkoutTotals->subtotal` (pre-discount). This is correct behavior — free shipping should be based on pre-discount purchase value. |
| FIN-5 | LOW | `PromotionApplicator::applyOutcome():115-119` | Uses `number_format()` (returns string) instead of `round()` (returns float) for DB decimal columns. Works due to DB casting, but inconsistent with the rest of the system. |
| FIN-6 | MEDIUM | `OrderService:347-356` | **BUG CPN-1**: `calculatePriceByCoupon()` reads `$cart->coupon` from in-memory model, which may be stale if the coupon was invalidated at line 173 (`$cart->update(['coupon' => null])` without `$cart->refresh()`). Financially, this means an invalid coupon may still be applied. |
| FIN-7 | INFO | `CouponCalculator::calculate()` | Coupon calculation is done in float (decimal) while promotion calculation uses integer cents. The two systems work independently (coupon is applied after promotion), so no cross-contamination. But the inconsistent precision model is a readability/maintenance concern. |
| FIN-8 | LOW | `InvoiceSnapshotService:59-61` | Sum of item totals is never cross-checked against subtotal in the snapshot validation pipeline. A mismatch between `sum(items[].total_price)` and `pricing_breakdown.subtotal` would go undetected. |
| FIN-9 | INFO | `OrderService:396` | `couponDiscount` is computed as `max(0, priceAfterPromotion - finalTotal)` which is definitionally equal to `couponDiscountAmount` (since `finalTotal = priceAfterPromotion - couponDiscountAmount`). Redundant computation. |

### Summary

| Severity | Count |
|----------|-------|
| MEDIUM | 1 (FIN-6 / CPN-1) |
| LOW | 4 (FIN-1, FIN-2, FIN-5, FIN-8) |
| INFO | 4 (FIN-3, FIN-4, FIN-7, FIN-9) |

The financial pipeline is **fundamentally sound**. All formulas are correct, rounding is consistent, and the promotion allocation uses the mathematically correct largest remainder method. The one MEDIUM issue (FIN-6) is the stale coupon bug already documented in Phase 2.

### No Evidence Of

- Penny rounding attacks (the largest remainder method handles 1-cent gaps correctly)
- Negative totals (all guarded by `max(0, ...)`)
- Double-discount stacking (promotion and coupon are applied sequentially, not compounded)
- Missing financial invariants (the snapshot validator covers the critical total formula)
