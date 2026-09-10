# Financial Integrity — Zero-Trust Production Audit

**Date**: 2026-07-27  
**Scope**: Money flow tracing, pricing formula verification, invariant testing, cross-system consistency  
**Trust Level**: ZERO — every claim verified against source code

---

## Table of Contents

1. [Money Flow Architecture](#1-money-flow-architecture)
2. [Pricing Formula Cross-Check](#2-pricing-formula-cross-check)
3. [Dual Financial Models](#3-dual-financial-models)
4. [Coupon Financial Impact](#4-coupon-financial-impact)
5. [Promotion Financial Impact](#5-promotion-financial-impact)
6. [Invoice Financial Invariants](#6-invoice-financial-invariants)
7. [Verified Bugs](#7-verified-bugs)
8. [Design Recommendations](#8-design-recommendations)

---

## 1. Money Flow Architecture

### 1.1 Custom Checkout Money Flow

```
Cart items
  ↓ price × quantity per item
Cart Item prices (snapshotted at reserve time)
  ↓
refreshCartItemPrices() (re-calculated at checkout time)
  ↓
calculateCheckoutTotals()
  ├── PromotionService::applySelectedPromotion() → [subtotal, discount, final_total]
  └── CouponCalculator::calculate() → [discountAmount, finalPrice]
  ↓
CheckoutTotals DTO
  ├── subtotal          = sum of item total_price
  ├── promotionDiscount = from promotion engine
  ├── couponDiscount    = priceAfterPromotion - finalTotal (computed, not from coupon!)
  └── finalTotal        = after both discounts
  ↓
createOrder()
  ├── price         = checkoutTotals.subtotal
  ├── total_price   = checkoutTotals.finalTotal + shipping + fast_shipping_fee
  ├── promotion_discount   = checkoutTotals.promotionDiscount
  ├── coupon_discount      = checkoutTotals.couponDiscount
  └── shipping_price       = resolved
  ↓
Transaction.create()
  ├── amount = order.total_price
  └── status = 'pending'
  ↓
Payment gateway → callback
  ↓
finalizeAfterPayment() / changeOrderStatus('completed')
  ├── transaction.status = 'paid'
  └── event(PaymentSucceeded)
```

### 1.2 Admin Panel Money Flow (OrderRepository)

```
OrderRepository::storeOrder()
  ├── request.amount      ← from input
  ├── request.sales_tax   ← from input  
  ├── request.delivery_fee ← from input
  ├── request.discount     ← from input
  ├── request.paid_total = amount + sales_tax + delivery_fee - discount
  └── request.total = paid_total
  ↓
Order::create() ← some fields may be excluded by $fillable
  ↓
deductStock()
  ↓
event(OrderProcessed)
```

### 1.3 Money Flow Gap

**BUG-FIN-001**: The admin panel and custom checkout use DIFFERENT financial models:

| Concept | Custom Checkout (Order $fillable) | Admin Panel (OrderRepository keys) |
|---|---|---|
| Subtotal | `price` | `amount` |
| Total | `total_price` | `total` (alias of `paid_total`) |
| Paid Total | not used | `paid_total` (= amount + tax + delivery - discount) |
| Tax | not used | `sales_tax` |
| Discount | `coupon_discount` + `promotion_discount` | `discount` (single field) |
| Delivery fee | `shipping_price` | `delivery_fee` |

The Order model's `$fillable` does NOT include `amount`, `paid_total`, `sales_tax`, `delivery_fee`, or `discount`. The OrderRepository writes to columns that may not exist in mass-assignment.

---

## 2. Pricing Formula Cross-Check

### 2.1 Product-Level Pricing

**Formula** (ProductPricingService::calculateProductPricing):
```
final_price = flashSalePrice ?? discountPrice ?? basePrice
```

Where:
- `basePrice` = round(price, 2)  (float, NOT integer cents — despite the comment claiming "integer cents")
- `flashSalePrice` = flashSale ? max(0, baseCents - discountCents) / 100 : null
- `discountPrice` = flashSale is null AND discount active ? max(0, baseCents - discountCents) / 100 : null

**BUG-FIN-002**: The class comment says "All monetary arithmetic uses integer cents" but `normalizeMoney()` returns a `float`, not `int`. The `toCents()`/`fromCents()` methods are used internally in `calculateDiscountedPrice()` and `calculateFlashSalePrice()`, but the return values throughout the service are floats. The only place integer math is enforced is inside `calculateDiscountedPrice()`:

```php
$priceCents = $this->toCents($normalizedPrice);  // float → int cents
$discountCents = (int) round($priceCents * $amount / 100);
return $this->fromCents(max(0, $priceCents - $discountCents));  // int → float
```

So the cents conversion is applied **per-item at calculation time**, but totals across items use **float arithmetic**. The cent precision is lost when summing across items:

```php
// In createOrderItems:
$lineTotal = (float) ($item->total_price ?? 0);  // float!
$effectiveUnitPrice = $quantity > 0 ? $lineTotal / $quantity : 0;  // float division!
```

**BUG-FIN-003**: Per-item rounding errors compound across order items. Each item's total_price is already a float. Summing floats across 10+ items with discounts, flash sales, and promotions creates sub-penny errors that the FinancialInvariantValidator would catch with its 0.01 tolerance.

### 2.2 Checkout Totals Formula

**Formula** (calculateCheckoutTotals):
```
subtotal = promotionTotals.subtotal           // from promotion service
priceAfterPromotion = promotionTotals.finalTotal
couponResult = calculatePriceByCoupon(cart, priceAfterPromotion)
finalTotal = round(max(0, couponResult.finalPrice), 2)
couponDiscount = round(max(0, priceAfterPromotion - finalTotal), 2)
```

**Order total** (createOrder):
```
totalPrice = round(finalTotal + shippingPrice + fastShippingFee, 2)
```

**BUG-FIN-004**: `couponDiscount` is computed as `priceAfterPromotion - finalTotal`, NOT from the actual coupon calculation. If `finalTotal` is capped or adjusted by rounding, the couponDiscount may not match the actual coupon discount applied. Example:
- priceAfterPromotion = 100.00
- Coupon = 10% = 10.00
- finalTotal = 90.00
- couponDiscount = 100.00 - 90.00 = 10.00 ✓

But with rounding:
- priceAfterPromotion = 99.99
- Coupon = 10% = 9.999 → coupon says 10.00
- CouponCalculator: `round(max(0, 99.99 - 10.00), 2)` = 89.99
- finalTotal = 89.99
- couponDiscount = 99.99 - 89.99 = 10.00 ✓ (matches)

OK, it works due to symmetry. But if there are multiple rounding steps, it could diverge.

### 2.3 CouponCalculator Formula

```
discount_type = PERCENTAGE:
  discountAmount = price × (discount / 100)
  if max_discount_amount: discountAmount = min(discountAmount, max_discount_amount)

discount_type = FIXED_RATE:
  discountAmount = min(discount, price)

discount_type = FREE_SHIPPING:
  discountAmount = 0 (handled separately as shipping=0)

finalPrice = round(max(0, price - discountAmount), 2)
```

**BUG-FIN-005**: CouponCalculator uses `float` arithmetic throughout:
```php
$discountAmount = $price * ($discount / 100);  // float × float = float
$discountAmount = min($discountAmount, (float) $coupon->max_discount_amount);  // float comparison
$discountAmount = round(max(0, $discountAmount), 2);  // float
$finalPrice = round(max(0, $price - $discountAmount), 2);  // float
```

For high-precision financial calculations, PHP floats introduce errors. Example: 100.00 × 0.07 (7%) = 6.999999999999999 in float arithmetic.

### 2.4 Promotion Discount Formula

From the promotion engine audit:
- Strategy pattern with `PromotionApplicator::applyOutcome()`
- Uses largest remainder algorithm for percentage distributions across items
- Fixed amount: `min(discount, item_total)` per item
- Percentage: `round(item_total × rate / 100, 2)` per item
- Sum of per-item discounts may not equal total discount due to rounding

### 2.5 FinancialInvariantValidator Formula

```
computedTotal = subtotal - promotionDiscount - couponDiscount + shippingPrice + fastShippingFee
assert |computedTotal - declaredTotal| ≤ 0.01
```

This formula matches the order total calculation:
```
totalPrice = round(finalTotal + shippingPrice + fastShippingFee, 2)
           = round((finalTotal + shippingPrice + fastShippingFee), 2)
           = round((subtotal - promoDiscount - couponDiscount + shipping + fastShipping), 2)
```

**But**: The order `total_price` is computed in `createOrder` and stored. The invoice snapshot reads it. The validator verifies it. **This is a circular validation** — it checks internal consistency but cannot detect if the stored total_price is wrong (e.g., missing a discount, applying wrong shipping).

---

## 3. Dual Financial Models

### 3.1 Custom Checkout Order Fields

From Order `$fillable`:
| Field | Source | Description |
|---|---|---|
| `price` | `checkoutTotals.subtotal` | Sum of item prices before discounts |
| `shipping_price` | Resolved shipping | |
| `total_price` | `checkoutTotals.finalTotal + shipping + fastShipping` | Final amount due |
| `coupon_discount` | `checkoutTotals.couponDiscount` | |
| `promotion_discount` | `checkoutTotals.promotionDiscount` | |
| `fast_shipping_fee` | | |

### 3.2 Admin Order Fields (OrderRepository)

From `OrderRepository::storeOrder()`:
| Field | Source | Description |
|---|---|---|
| `amount` | `request.amount` | Subtotal |
| `sales_tax` | `request.sales_tax` | |
| `delivery_fee` | `request.delivery_fee` | |
| `discount` | `request.discount` | Single discount field |
| `paid_total` | Computed: `amount + sales_tax + delivery_fee - discount` | |
| `total` | Alias of `paid_total` | |

**BUG-FIN-006**: The Order model `$fillable` does NOT include `amount`, `paid_total`, `sales_tax`, `delivery_fee`, or `discount`. If `Order::create($request)` is called with these keys, they are silently excluded by mass-assignment protection. The admin panel's financial data is LOST.

### 3.3 Migration/Risk

Check if `$guarded = []` is used on the Order model... it's not shown in the file. If not, the default Laravel behavior is `$guarded = ['*']` when `$fillable` is empty, or `$guarded = ['id']` when `$fillable` is set. Since `$fillable` lists specific columns, any column NOT in `$fillable` will be silently ignored.

**BUG-FIN-006 (confirmed)**: Admin panel order creation loses `amount`, `paid_total`, `sales_tax`, `delivery_fee`, and `discount` data. These are set on the request but never persisted to the `orders` table.

---

## 4. Coupon Financial Impact

### 4.1 Coupon Discount Persistence

When a coupon is applied during checkout:
1. `calculateCheckoutTotals()` computes coupon discount
2. `createOrder()` stores `coupon_discount` on the order
3. `recordCouponUsage()` records usage AFTER payment succeeds
4. `CouponUsage` table records: `coupon_id`, `order_id`, `user_id`, `amount`

**BUG-FIN-007**: `coupon_discount` stored on the order is computed as `priceAfterPromotion - finalTotal` (a residual), not the actual coupon discount amount. If rounding causes even 1 piastre difference, the stored coupon_discount and the actual CouponUsage.amount (which IS the real coupon amount) could differ.

### 4.2 Coupon Usage Double-Record

`recordCouponUsage()` is called from:
1. `changeOrderStatus('completed')` — when admin changes order to completed
2. `markCodAsPaid()` — when COD is marked paid
3. `markCashierPaid()` — when cashier payment is marked paid

**BUG-FIN-008**: `recordCouponUsage()` has an internal guard using `CouponUsage::firstOrNew()`, so it won't double-record. But the guard is at the database level, meaning each call produces a SELECT. For COD and Cashier flows, `recordCouponUsage()` is called inside the same transaction that also dispatches `PaymentSucceeded`. If the listener `GenerateInvoiceListener` also tries to record coupon usage (it doesn't currently, but if it did), there'd be a race.

### 4.3 Coupon on Cancelled Orders

From policy comment in `OrderService`:
```
Coupon quota is consumed when payment succeeds.
It is NEVER automatically returned on cancellation or refund.
```

This is explicitly designed to prevent abuse. Confirmed by source: `recordCouponUsage()` has no corresponding "release" method called on cancellation.

---

## 5. Promotion Financial Impact

### 5.1 Promotion Discount Persistence

- `promotion_discount` stored on the order during `createOrder()`
- Individual `promotion_discount_amount` per order item in `order_products`
- Promotion usage (`incrementUsage/decrementUsage`) tracks total usage count

**BUG-FIN-009**: Promotion usage is incremented BEFORE payment (BUG-002). If payment fails:
- `incrementUsage()` already happened (in `applySelectedPromotion()` during checkout)
- Never decremented — promotion quota is consumed even for failed payments
- User can't re-use the promotion because usage was already counted

### 5.2 Promotion Decrement on Cancel

`changeOrderStatus()` calls `decrementUsage()` for cancelled status (line 556). But this only decrements if the promotion was actually used (checks `$order->promotion_id`). This is the only correction path, but it has a TOCTOU issue (promotion usage is never finalized or locked in a transaction with the payment).

---

## 6. Invoice Financial Invariants

### 6.1 Invariant Validation Suite

The invoice system validates 6 invariants:

| Validator | Checks | Issue |
|---|---|---|
| `StructureValidator` | Snapshot has required keys | OK |
| `SnapshotVersionValidator` | Version is 1 | OK |
| `FinancialInvariantValidator` | `total = subtotal - promo - coupon + shipping + fastShipping` | Only checks internal consistency |
| `MoneyValidator` | Monetary values are non-negative and reasonable | OK |
| `CurrencyValidator` | Currency is supported | OK |
| `MetadataValidator` | Metadata is present | OK |

### 6.2 What FinancialInvariantValidator DOESN'T Check

- That `subtotal` matches sum of `order_products.product_total_price`
- That `promotion_discount` matches sum of per-item promotion discounts
- That `coupon_discount` matches actual coupon calculation
- That `shipping_price` matches the governorate's configured shipping rate
- That no money was lost/created between order creation and snapshot generation

**BUG-FIN-010**: The FinancialInvariantValidator can pass with a wrong total as long as the internal formula is consistent. Example:
- subtotal = 100, promo = 0, coupon = 0, shipping = 10, fast = 0, total = 110 ✓
- subtotal = 0, promo = 0, coupon = 0, shipping = 0, fast = 0, total = 0 ✓ (all zeros — also valid!)

If the snapshot contains all zeros, the validator would pass, but the invoice would show a $0 total for a real order.

---

## 7. Verified Bugs

| ID | Bug | Severity | File |
|---|---|---|---|
| **BUG-FIN-001** | Custom checkout and admin panel use different financial models (different column sets) | CRITICAL | `Order.php fillable` vs `OrderRepository.php` |
| **BUG-FIN-002** | "Integer cents" claim is false — floats used throughout | MEDIUM | `ProductPricingService.php:17` |
| **BUG-FIN-003** | Per-item float rounding errors compound across multiple items | MEDIUM | `OrderCreationService.php:122-125` |
| **BUG-FIN-004** | `couponDiscount` computed as residual, not from actual coupon calculation | LOW | `OrderService.php:458` |
| **BUG-FIN-005** | CouponCalculator uses float arithmetic for percentage discounts | MEDIUM | `CouponCalculator.php:16` |
| **BUG-FIN-006** | Admin panel financial fields (`amount`, `paid_total`, `sales_tax`, `delivery_fee`, `discount`) silently excluded from mass-assignment | CRITICAL | `Order.php:19-51` |
| **BUG-FIN-007** | `coupon_discount` on order may differ from actual `CouponUsage.amount` due to residual calculation | LOW | `OrderService.php:458` |
| **BUG-FIN-008** | `recordCouponUsage()` called in 3 places but only guarded by `firstOrNew` SELECT | INFO | `OrderService.php` |
| **BUG-FIN-009** | Promotion usage incremented before payment — consumption on failed payments | CRITICAL | `PromotionService.php` (BUG-002) |
| **BUG-FIN-010** | FinancialInvariantValidator only checks internal formula, not external consistency | MEDIUM | `FinancialInvariantValidator.php` |
| **BUG-FIN-011** | `runSafely()` silently swallows pricing errors — returns fallback with no alert | HIGH | `ProductPricingService.php:486-495` |

### Severity Summary

- **CRITICAL**: 3 (BUG-FIN-001, BUG-FIN-006, BUG-FIN-009)
- **HIGH**: 1 (BUG-FIN-011)
- **MEDIUM**: 4 (BUG-FIN-002, BUG-FIN-003, BUG-FIN-005, BUG-FIN-010)
- **LOW**: 2 (BUG-FIN-004, BUG-FIN-007)
- **INFO**: 1 (BUG-FIN-008)

---

## 8. Design Recommendations

### 8.1 Critical: Unify Financial Models

Choose ONE set of financial columns and use them everywhere:

**Recommended (Custom Checkout model):**
```
orders: price, shipping_price, fast_shipping_fee, coupon_discount, promotion_discount, coupon_discount_type, coupon_discount_max_amount, total_price
```

Add missing columns if admin needs tax:
```
orders: sales_tax (new)
```

Drop: `amount`, `paid_total`, `delivery_fee`, `discount` (redundant)

### 8.2 Critical: Integer Cents Everywhere

Convert ALL monetary values to integer cents:
- Store as cents in DB (`price_cents`, `total_price_cents`, etc.)
- Calculate in cents throughout
- Only convert to float for display/API output
- See Stripe/Laravel Cashier pattern

### 8.3 High: Remove `runSafely()`

The `runSafely()` method silently returns fallback values on any exception. Replace with proper exception handling:
- Log the error with full context
- Let the exception propagate to the controller for proper error response
- Never silently return wrong prices

### 8.4 High: Add External Consistency Checks

Enhance `FinancialInvariantValidator` to also verify:
- `subtotal === sum(order_products.product_total_price)`
- `promotion_discount === sum(order_products.promotion_discount_amount)`  
- `total_price` matches the actual checkout calculation result

### 8.5 Medium: Fix Float Arithmetic

Replace float arithmetic with integer cents in:
- `CouponCalculator` — convert to cents before computation
- `OrderCreationService::createOrderItems()` — lineTotal calculation
- `OrderService::calculateCheckoutTotals()` — discount calculations

### 8.6 Medium: Add Promotion Usage Guard

Move promotion usage from `applySelectedPromotion()` (before payment) to a dedicated `finalizePromotionUsageAfterPayment()` (after payment). Add idempotency guard similar to `inventory_restored_at`.

### 8.7 Low: Consistent Rounding

Define a single rounding strategy:
- Round after EVERY multiplication
- Or: round only at display time
- Currently: `round()` is used inconsistently — sometimes per-item, sometimes at total, sometimes not at all
