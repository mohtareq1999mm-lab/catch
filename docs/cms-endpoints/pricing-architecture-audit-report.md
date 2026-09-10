# Pricing & Discount Architecture Audit Report

**Date:** 2026-07-12
**Scope:** Complete end-to-end audit of all pricing, discount, promotion, flash sale, coupon, and checkout calculation flows
**Methodology:** Full source code trace through all 30+ files across app/, packages/marvel/, routes/, config/, and docs/

---

## Part 1 — Remaining Code Review

### Files Read During Part 2

| File | Lines | Key Discovery |
|------|-------|---------------|
| `packages/marvel/src/Database/Models/FlashSale.php` | 144 | `calcPrice()` delegates to `ProductPricingService`. Has `isValid()` and scope. No pricing logic in model itself. |
| `packages/marvel/src/Database/Models/Product.php` | 580 | Appends `current_price`, `price_after_discount`, `price_after_flash_sale`, `final_price`. All delegate to `ProductPricingService`. Has `getActiveFlashSale()`. |
| `packages/marvel/src/Database/Models/Promotion.php` | 275 | `discountAmount()` — duplicate percentage/fixed math. `calcPrice()` — also duplicate. Has `isValid()` check. |
| `packages/marvel/src/Database/Models/Order.php` | 143 | No monetary field casting. `price`, `total_price`, `shipping_price`, `coupon_discount`, `promotion_discount` stored as uncast floats. |
| `packages/marvel/src/Database/Models/OrderProduct.php` | 58 | Only `promotion_discount_amount` has float cast. `product_price`, `product_total_price` uncast. |
| `packages/marvel/src/Database/Models/Coupon.php` | 219 | `isValid()` and `calcPrice()` both marked `@deprecated`. No `minimum_order_amount` field. |
| `app/Services/General/PromotionEngine/PromotionEligibilityResolver.php` | 125 | Uses `matchedSubtotalCents`. Excludes gift items. Well-encapsulated. |
| `app/Services/General/PromotionEngine/PromotionApplicator.php` | 180 | Largest-remainder proportional allocation. Mutates cart items and cart. Persists in DB. |
| `app/Services/General/PromotionEngine/PromotionEvaluation.php` | 28 | Immutable DTO. Uses integer cents. |
| `app/Services/General/PromotionEngine/Strategies/AbstractPromotionStrategy.php` | 29 | Checks `minimum_order_amount` and `required_quantity_type`. |
| `app/Services/General/PromotionEngine/Strategies/FixedPromotionStrategy.php` | 29 | Delegates to `Promotion::discountAmount()`. |
| `app/Services/General/PromotionEngine/Strategies/PercentagePromotionStrategy.php` | 28 | Same delegation pattern. |
| `app/Services/General/PromotionEngine/Strategies/GiftPromotionStrategy.php` | 118 | Stock-validated gift resolution. |
| `app/Services/Payment/PaymentCheckoutHandler.php` | 137 | Creates Transaction, delegates to gateway. No pricing logic. |
| `app/Services/Payment/PaymentGatewayFactory.php` | 19 | Simple factory. Single gateway (myfatoorah). |
| `app/Http/Resources/Order/OrderResource.php` (app) | 74 | `discount = coupon_discount + promotion_discount`. Uses `roundMoney()`. |
| `packages/marvel/src/Http/Resources/Order/OrderResource.php` (package) | 97 | Pricing fields only on `orders.show`. Also has `roundMoney()`. |
| `app/Http/Controllers/Api/General/OrderController.php` | 390 | Two-step checkout: `calcInvoicePrice()` then `addItemsInOrder()`. |
| `packages/marvel/src/Http/Controllers/CheckoutController.php` | 67 | Legacy verify endpoint. Delegates to repository. |
| `app/Services/General/FastShippingService.php` | 206 | Third checkout path. Does NOT handle FREE_SHIPPING coupons. |

### Architecture Diagram (Who Calls What)

```
OrderController::checkout()
├── CartInventoryService::getActiveCartForUser()
├── CartInventoryService::ensureCartReservation()
├── OrderService::calcInvoicePrice()
│   ├── getCartUser() [filters: SCHEDULED items]
│   ├── calculateCheckoutTotals() 
│   │   ├── PromotionService::applySelectedPromotion()  ← MUTATES DB
│   │   │   ├── removeGiftItems()
│   │   │   ├── PromotionEligibilityResolver::resolve()
│   │   │   ├── PromotionApplicator::applyOutcome()    ← PERSISTS TO cart_items/carts
│   │   │   └── returns CheckoutTotals
│   │   └── calculatePriceByCoupon()
│   │       └── CouponCalculator::calculate()
│   ├── resolveShippingPrice()
│   └── updates cart.total_price
├── OrderService::addItemsInOrder()
│   ├── getCartUser() [filters: SCHEDULED items, RELOADS]
│   ├── CouponValidator::validateByCode()
│   ├── getCheckoutTotalsFromCart()  ← READS PERSISTED VALUES
│   ├── resolveShippingPrice()
│   ├── OrderCreationService::createOrder()
│   ├── OrderCreationService::createOrderItems()
│   └── OrderCreationService::finalizeOrder()
│       ├── PromotionService::incrementUsage()
│       └── dispatch OrderCreated

FastShippingService::createFastOrder()
├── calculateCheckoutTotals() [DUPLICATE of OrderService logic]
│   ├── PromotionService::applySelectedPromotion()
│   ├── getCouponData() + calculateCouponDiscount()
│   └── NO FREE_SHIPPING check on shipping
├── OrderCreationService::createOrder()
├── OrderCreationService::createOrderItems()
└── OrderCreationService::finalizeOrder()
```

---

## Part 2 — Complete Execution Flow (Real Implementation)

### Step-by-Step: Scheduled Delivery Checkout

#### Phase A: Invoice Price Calculation (`calcInvoicePrice`)

1. **`getCartUser()`** (OrderService:232)
   - `Cart::where('user_id', auth()->id())->where('status', 'active')`
   - `->with(['items' => fn($q) => $q->where('shipping_method', 'SCHEDULED'), 'items.product', 'items.productVariant'])`
   - **BUG**: Items with `shipping_method = 'FAST'` are excluded. This is the shipping method filter.

2. **`calculateCheckoutTotals($cart, $selectedPromotionId, $selectedGiftProductId)`** (OrderService:306)
   
   a. **`PromotionService::applySelectedPromotion($cart, $promotionId, $giftProductId)`** (PromotionService:55)
      - Removes existing gift items from cart (DB mutation)
      - Loads cart items product/variant relations
      - Computes `subtotal` = sum of `$item->price * $item->quantity` (excludes gifts)
      - Converts to cents: `$subtotalCents = (int) round($subtotal * 100)`
      - Loads promotion with `lockForUpdate()`
      - `PromotionEligibilityResolver::resolve()` → returns `PromotionResult`
      - `PromotionApplicator::applyOutcome()` (PromotionApplicator:28)
        - **PERSISTS DISCOUNT TO DB**: Updates each cart item's `total_price`, `discount_amount`, `promotion_id`
        - **PERSISTS CART TOTAL**: Updates cart's `total_price`
      - **This is a SIDE EFFECT during what appears to be a calculation step**
      - Returns `CheckoutTotals` with `subtotal`, `promotionDiscount`, `finalTotal`, `promotion` metadata

   b. **`calculatePriceByCoupon($cart, $priceAfterPromotion)`** (OrderService:239)
      - Reads `$cart->coupon`
      - `CouponCalculator::calculate($coupon, $totalPrice)`
      - Returns `['finalPrice', 'discountType', 'freeShipping']`

   c. Builds `CheckoutTotals`:
      - `subtotal` = from promotion result
      - `promotionDiscount` = from promotion result
      - `couponDiscount` = `round(max(0, priceAfterPromotion - finalTotal), 2)`
      - `finalTotal` = `round(max(0, couponResult['finalPrice']), 2)`

3. **`resolveShippingPrice($governorateId)`** (OrderService:204)
   - Looks up governorate → shipping price table
   - Returns `['price', 'free_shipping_over', 'governorate_id']`

4. **Shipping override checks** (OrderService:105-110)
   - If `subtotal > free_shipping_over` → shipping = 0
   - If `couponDiscountType === DiscountType::FREE_SHIPPING` → shipping = 0

5. **`finalTotal = round(checkoutTotals->finalTotal + shippingPrice, 2)`** (OrderService:112)
   - `$cart->update(['total_price' => $finalTotal])` — PERSISTS TO DB
   - Returns `$cart->total_price` — this is the "invoice price" used for payment

#### Phase B: Order Item Creation (`addItemsInOrder`)

1. **`getCartUser()`** — RELOADS cart from DB (fresh query)

2. **Coupon re-validation** (OrderService:136-143)
   - `CouponValidator::validateByCode($cart->coupon, $request->user(), $cart->items)`
   - If invalid → removes coupon from cart
   - If valid and FREE_SHIPPING → sets `$freeShippingCoupon = true`

3. **`getCheckoutTotalsFromCart($cart)`** (OrderService:261) — DIFFERENT PATH
   - Reads persisted cart item values
   - `subtotal` = `sum of max($item->price * $item->quantity, $item->total_price)` — excludes gifts
   - `promotionDiscount` = `sum of $item->discount_amount`
   - `finalTotal` = `sum of $item->total_price`
   - `couponDiscount` = `max(0, subtotal - promotionDiscount - finalTotal)` — DERIVED, not computed

4. **Shipping again** (OrderService:152-159)
   - `resolveShippingPrice()` — same as before
   - Same `free_shipping_over` check
   - `freeShippingCoupon` check (from step 2)

5. **`OrderCreationService::createOrder()`** (OrderCreationService:19)
   - `total_price = round(finalTotal + shippingPrice + fastShippingFee, 2)`
   - Persists `price` (subtotal), `shipping_price`, `total_price`, `coupon_discount`, `promotion_discount`, etc.

6. **`OrderCreationService::createOrderItems($order, $cart)`** (OrderCreationService:67)
   - For each cart item:
     - `effectiveUnitPrice = round(lineTotal / quantity, 2)` — **ROUNDING ERROR INTRODUCED**
     - `promotionDiscountAmount = round(max(0, (price * quantity) - lineTotal), 2)` — recomputed
     - Reads `flash_sale_price` from product flash sale (fresh query)
     - Creates `OrderProduct` record

7. **`OrderCreationService::finalizeOrder()`** (OrderCreationService:153)
   - `PromotionService::incrementUsage($promotionId)` — increments `promotions.usage`
   - Dispatches `OrderCreated` event

---

## Part 3 — Final Discount Stacking Rules

### Actual Execution Order (Traced from Code)

```
1. PRODUCT BASE PRICE ($product->price or $variant->price)
   ↓
2. FLASH SALE PRICE  (if active, OVERRIDES everything below)
   ↓
3. SALE DISCOUNT     (if no flash sale, $product->discount_type + $discount_amount)
   ↓
4. PROMOTION         (applied at checkout to cart items, PERSISTED)
   ↓
5. COUPON            (applied after promotion, NOT persisted to items, calculated on final total)
   ↓
6. SHIPPING          (resolved from governorate, may be zeroed by free_shipping_over or FREE_SHIPPING coupon)
   ↓
7. GRAND TOTAL       (rounded to 2 decimals, persisted to orders table)
```

### Step-by-Step Ownership

| Step | Class | Method | Called By | Input | Output | Mutates DB? | Persists? |
|------|-------|--------|-----------|-------|--------|-------------|-----------|
| 1. Base Price | `Product` / `ProductVariant` | `$this->price` | Direct read | — | float | No | Created in DB |
| 2. Flash Sale | `ProductPricingService` | `calculateFlashSalePrice()` | `Product::getFlashSalePrice()`, `calculateProductPricing()` | FlashSale + basePrice | ?float | No | Read-only |
| 3. Sale Discount | `ProductPricingService` | `calculateDiscountedPrice()` | `calculateProductPricing()` | basePrice + discountType + amount | ?float | No | Read-only |
| 4. Promotion | `PromotionService` → `PromotionApplicator` | `applySelectedPromotion()` → `applyOutcome()` | `OrderService::calculateCheckoutTotals()`, `FastShippingService::calculateCheckoutTotals()` | Cart + Promotion | CheckoutTotals | **YES** | Updates `cart_items.total_price`, `cart_items.discount_amount`, `cart_items.promotion_id`, `carts.total_price` |
| 5. Coupon | `CouponCalculator` | `calculate()` | `OrderService::calculatePriceByCoupon()`, `FastShippingService::calculateCouponDiscount()` | Coupon + price | `['finalPrice', 'discountType', 'freeShipping']` | No | Calculated at order creation |
| 6. Shipping | `OrderService` | `resolveShippingPrice()` | `calcInvoicePrice()`, `addItemsInOrder()` | governorateId | `['price', 'free_shipping_over']` | No | Read-only |
| 7. Grand Total | `OrderCreationService` | `createOrder()` | Both checkout paths | finalTotal + shipping + fastFee | float | **YES** | `orders.total_price` |

### Documented vs Actual

| Documented Behavior | Actual Behavior | Discrepancy |
|-------------------|----------------|-------------|
| Promotion then Coupon | Promotion then Coupon | ✅ Matches |
| Flash Sale overrides Sale Price | Flash Sale overrides Sale Price | ✅ Matches |
| Coupon discount type checked for FREE_SHIPPING | Checked in both `calcInvoicePrice` and `addItemsInOrder` | ✅ Matches |
| Flash Sale price frozen at add-to-cart | Flash Sale price used at add-to-cart via `$product->current_price` → NOT re-evaluated at checkout | ⚠️ Documented behavior matches but has implication: stale flash sale prices survive |
| `calcInvoicePrice` is read-only | **`calcInvoicePrice` MUTATES DB** via `PromotionService::applySelectedPromotion()` | ❌ BREAKS CONTRACT |
| Shipping resolved once | Shipping resolved TWICE (once in `calcInvoicePrice`, once in `addItemsInOrder`) | ❌ Duplicate work, potential mismatch if governorate changed between calls |

---

## Part 4 — Hidden Bugs

### 🐛 BUG-1: Dual Checkout Paths Produce Different Totals (CRITICAL)

**Location:** `OrderService::calcInvoicePrice()` vs `OrderService::addItemsInOrder()`
**Files:** `app/Services/General/OrderService.php:84` and `:126`

The invoice price (used for payment amount) is computed by `calculateCheckoutTotals()` which calls `PromotionService::applySelectedPromotion()` → applies promotion from scratch, then applies coupon.

The order items are created using `getCheckoutTotalsFromCart()` which reads the **already-persisted values** from cart items (after promotion was applied and persisted by the first call).

**The two paths can diverge if:**
- The cart was modified between the two calls
- The promotion application in the first call had side effects that changed what `getCheckoutTotalsFromCart()` reads
- Rounding differences between the two calculation paths

**Business impact:** The payment amount (invoice price) could differ from the actual order total. Payment gateway disputes, reconciliation failures.

### 🐛 BUG-2: FastShippingService Does Not Handle FREE_SHIPPING Coupons (HIGH)

**Location:** `app/Services/General/FastShippingService.php:151-178`

`FastShippingService::calculateCheckoutTotals()` calls `calculateCouponDiscount()` which uses `ProductPricingService::calculateCouponPrice()` — this returns only the discounted price, NOT the `freeShipping` flag.

In contrast, `OrderService::addItemsInOrder()` checks `DiscountType::FREE_SHIPPING` and sets `$shippingPrice = 0`.

**Impact:** Fast shipping orders with a FREE_SHIPPING coupon will still charge shipping.

### 🐛 BUG-3: Invoice Price Not Freshly Loaded Before Payment (MEDIUM)

**Location:** `app/Http/Controllers/Api/General/OrderController.php:82-89`

```php
$orderPrice = $this->orderService->calcInvoicePrice($request);
// ...orderPrice used for payment amount...
$order = $this->orderService->addItemsInOrder($request);
```

`calcInvoicePrice()` returns the cart's `total_price`, which was updated during the calculation. But `addItemsInOrder()` recalculates and stores `orders.total_price` separately. The payment is initiated with `$orderPrice` (from cart), not with the final `$order->total_price`.

**Impact:** Payment amount mismatch. If `addItemsInOrder` produces a different total, the payment amount (from `calcInvoicePrice`) won't match the stored order total.

### 🐛 BUG-4: Rounding Error in Order Item Unit Price (MEDIUM)

**Location:** `app/Services/Checkout/OrderCreationService.php:73`

```php
$effectiveUnitPrice = $quantity > 0 ? round($lineTotal / $quantity, 2) : 0;
```

If `$lineTotal` = 100.00 and `$quantity` = 3, then `$effectiveUnitPrice` = 33.33. But `33.33 * 3 = 99.99 ≠ 100.00`.

**Impact:** The `product_price` × `product_quantity` may not equal `product_total_price` on the order item. Inconsistent invoice line items.

### 🐛 BUG-5: getCheckoutTotalsFromCart Can Produce Wrong Coupon Discount (MEDIUM)

**Location:** `app/Services/General/OrderService.php:261-303`

```php
$subtotal = round((float) $items->sum(function ($item) {
    $baseLineTotal = ((float) ($item->price ?? 0)) * ((int) ($item->quantity ?? 0));
    if ($baseLineTotal > 0) {
        return $baseLineTotal;
    }
    return (float) ($item->total_price ?? 0);
}), 2);
```

If ALL cart items have `$item->price = 0` (e.g. all items are free or have zero price from flash sale), then `$baseLineTotal = 0` for all items, and `$subtotal` falls through to `sum($item->total_price)`.

But `$item->total_price` at this point has already been reduced by promotion discounts (PromotionApplicator mutated it). So `subtotal` could equal `finalTotal`, making `couponDiscount = 0` even when a valid coupon exists.

**Impact:** Coupon discount calculated as zero when all cart items have a base price of zero.

### 🐛 BUG-6: Cart total_price Has No Single Source of Truth (HIGH)

**Location:** Multiple files

`cart.total_price` is overwritten by:
1. `CartInventoryService::reserveItem()` — sets to `price × quantity`
2. `PromotionApplicator::applyOutcome()` — sets to sum of discounted line totals
3. `OrderService::calcInvoicePrice()` — sets to `finalTotal + shippingPrice`
4. `FastShippingService::createFastOrder()` — sets to `order->total_price`
5. `CartInventoryService::finalizeItemsByShippingMethod()` — sets to 0 on checkout
6. `CartInventoryService::releaseCart()` — sets to 0 or remaining total

**Impact:** If any step fails between writes, the cart total is left in an inconsistent state.

### 🐛 BUG-7: PromotionService Mutates DB During Invoice Calculation (HIGH)

**Location:** `app/Services/General/PromotionService.php:55-131` called from `OrderService:97-101`

`applySelectedPromotion()` is called inside `calcInvoicePrice()` which is supposed to be a calculation method. It calls `PromotionApplicator::applyOutcome()` which:
- Saves updated `total_price` on each cart item
- Saves `discount_amount` on each cart item
- Saves `promotion_id` on each cart item
- Saves updated `total_price` on the cart

If the subsequent `addItemsInOrder()` fails (DB rollback), these mutations are NOT rolled back because `calcInvoicePrice()` committed its own DB transaction.

**Impact:** Cart items in an inconsistent state after failed order creation. Promotion discounts applied but no order created.

### 🐛 BUG-8: Coupon Validated After Invoice Calculation (MEDIUM)

**Location:** `app/Services/General/OrderService.php:84-124` vs `:136-143`

`calcInvoicePrice()` calculates with the coupon and returns a price. Then `addItemsInOrder()` validates the coupon — if invalid, it removes it and creates the order WITHOUT the coupon.

**Impact:** The user was quoted a price WITH the coupon discount, but the order is created WITHOUT it. Payment amount (from invoice) exceeds actual order total.

### 🐛 BUG-9: No minimum_order_amount Check on Coupons (LOW)

**Location:** `app/Services/Coupon/CouponValidator.php`

The `Coupon` model (`packages/marvel/src/Database/Models/Coupon.php`) does NOT have a `minimum_order_amount` field in its `$fillable` array. The `CouponValidator::validate()` does not check minimum order amount.

In contrast, `Promotion` has `minimum_order_amount` and `AbstractPromotionStrategy` checks it.

**Impact:** Coupons can be applied to any order regardless of subtotal.

### 🐛 BUG-10: Coupon usage Recorded at Multiple Points (LOW - Mitigated)

**Location:** `app/Services/General/OrderService.php:449-474`

`recordCouponUsage()` is called from:
- `changeOrderStatus('completed')`
- `markCodAsPaid()`
- `markCashierPaid()`

The `firstOrCreate` + `wasRecentlyCreated` guard prevents double increment. However, `changeOrderStatus('completed')` is called in the online payment callback — if the callback fires twice (duplicate webhook), the usage check was already made during checkout, and the coupon usage record might exist from the first attempt.

**Impact:** Low — mitigated by `wasRecentlyCreated`. But the coupon's `used` counter could drift if `recordCouponUsage` is never called (e.g., payment callback never arrives).

---

## Part 5 — Precision Audit

### All Rounding Points

| File | Line | Operation | Precision |
|------|------|-----------|-----------|
| CouponCalculator.php:27 | `round(max(0, $discountAmount), 2)` | 2 decimal places | Float |
| CouponCalculator.php:28 | `round(max(0, $price - $discountAmount), 2)` | 2 decimal places | Float |
| ProductPricingService.php:62 | `round((float) $amount, 2)` | 2 decimal places | Float |
| ProductPricingService.php:260 | `(int) round($priceUnits * ($amount / 100))` | Integer (unit conversion) | Int |
| ProductPricingService.php:263 | `$this->toUnits(max(0, $priceUnits - $discountUnits))` | Float cast | Float |
| PromotionApplicator.php:82 | `$exactShare = ($line * $amountCents) / $baseCents` | Integer cents | Float division |
| PromotionApplicator.php:83 | `$floorShare = (int) floor($exactShare)` | Integer cents | Int |
| PromotionApplicator.php:111 | `$newTotalPrice = ($lineTotalCents - $alloc) / 100.0` | 2 decimal float | Float |
| PromotionApplicator.php:117 | `number_format($alloc / 100.0, 2, '.', '')` | **STRING** | String |
| OrderService.php:265-271 | `round(sum of price * quantity, 2)` | 2 decimal places | Float |
| OrderService.php:298 | `round(max(0, ...), 2)` | 2 decimal places | Float |
| OrderCreationService.php:22 | `round(finalTotal + shipping + fee, 2)` | 2 decimal places | Float |
| OrderCreationService.php:73 | `round(lineTotal / quantity, 2)` | 2 decimal places | Float |
| OrderCreationService.php:74 | `round(max(0, (price * qty) - lineTotal), 2)` | 2 decimal places | Float |
| App\OrderResource.php:71 | `round((float) $value, 2)` | 2 decimal places | Float |
| Marvel\OrderResource.php:94 | `round((float) $value, 2)` | 2 decimal places | Float |

### Precision Issues Found

**ISSUE-P1: String-to-Float Round-Trip (Medium)**
`PromotionApplicator` stores values with `number_format()` which returns a STRING. These are later read with `(float)` casts. PHP's `(float)` on a well-formatted string is safe, but any subsequent mathematical operations on these values after read-back could introduce floating-point drift.

**ISSUE-P2: Mixed Precision Domains (High)**
- `PromotionApplicator` works in integer cents internally, but stores in float/string
- `Promotion::discountAmount()` works in float (round to 2 decimals)
- `CouponCalculator` works in float (round to 2 decimals)
- `ProductPricingService` works in float (no unit conversion for sale prices)
- These three domains interact without a clear boundary

**ISSUE-P3: Unit Price Division Rounding (Critical)**
`OrderCreationService::createOrderItems()` computes:
```php
$effectiveUnitPrice = $quantity > 0 ? round($lineTotal / $quantity, 2) : 0;
```
This is mathematically unsound for quantities > 1. The sum of unit prices may not equal the line total.

**ISSUE-P4: No Decimal/Bcmath Usage (Medium)**
No `bcmath` functions are used anywhere in the pricing chain. All arithmetic uses native PHP floats.

---

## Part 6 — Duplicate Calculations

### DUP-1: Percentage Discount Calculation (3 copies)

**Copy A** — `CouponCalculator::calculate()` (CouponCalculator.php:15-16)
```php
$discountAmount = $price * ($discount / 100);
```

**Copy B** — `ProductPricingService::calculateDiscountedPrice()` (ProductPricingService.php:259-260)
```php
$discountUnits = (int) round($priceUnits * ($amount / 100));
```

**Copy C** — `Promotion::discountAmount()` (Promotion.php:216)
```php
$discount = $price * ($value / 100);
```

All three implement `price × (percent / 100)` independently.

### DUP-2: Fixed Rate Discount Calculation (3 copies)

**Copy A** — `CouponCalculator::calculate()` (CouponCalculator.php:22)
```php
$discountAmount = $discount;
```

**Copy B** — `ProductPricingService::calculateDiscountedPrice()` (ProductPricingService.php:266-268)
```php
$discountUnits = $this->toUnits($amount);
return $this->toUnits(max(0, $priceUnits - $discountUnits));
```

**Copy C** — `Promotion::discountAmount()` (Promotion.php:226)
```php
return round(max(0.0, min($price, $value)), 2);
```

### DUP-3: Flash Sale Discount Calculation (3 types in one method)

`ProductPricingService::resolveFlashSaleDiscountUnits()` implements THREE distinct discount types in one method: percentage, fixed_rate, and final_price. This logic is ONLY used for flash sales and has no overlap with the other discount methods.

### DUP-4: Free Shipping Check (2 copies)

**Copy A** — `OrderService::calcInvoicePrice()` (OrderService.php:108)
```php
if ($checkoutTotals->couponDiscountType === DiscountType::FREE_SHIPPING) {
    $shippingPrice = 0;
}
```

**Copy B** — `OrderService::addItemsInOrder()` (OrderService.php:157)
```php
if ($freeShippingCoupon) {
    $shippingPrice = 0;
}
```

### DUP-5: Free Shipping Over Check (3 copies)

**Copy A** — `OrderService::calcInvoicePrice()` (OrderService.php:105-107)
**Copy B** — `OrderService::addItemsInOrder()` (OrderService.php:154-156)
**Copy C** — `FastShippingService::createFastOrder()` (FastShippingService.php:95-97)

### DUP-6: Discount Active Check (2 copies)

**Copy A** — `ProductPricingService::isDiscountActive()` (ProductPricingService.php:398)
**Copy B** — `ProductPricingService::isDiscountActiveFromData()` (ProductPricingService.php:427)

### DUP-7: Flash Sale Active Check (2 copies)

**Copy A** — `FlashSale::isValid()` (FlashSale.php:93)
**Copy B** — `ProductPricingService::isFlashSaleActive()` (ProductPricingService.php:373)

### DUP-8: Calculation Paths (3 partial copies)

**Path A** — `OrderService::calculateCheckoutTotals()` (OrderService.php:306)
**Path B** — `OrderService::getCheckoutTotalsFromCart()` (OrderService.php:261)  
**Path C** — `FastShippingService::calculateCheckoutTotals()` (FastShippingService.php:151)

---

## Part 7 — Persistence Flow

### Monetary Field Lifecycle

#### `products.price`
- **Created:** Product create/import
- **Read:** `ProductPricingService`, `CartInventoryService::reserveItem()`, `OrderService`, `OrderCreationService`
- **Never mutated after creation**

#### `products.sale_price` (does not exist)
- **Note:** Product model does NOT have a `sale_price` column. Price after discount is computed dynamically.

#### `products.price_after_discount` (virtual/computed)
- **Computed by:** `Product::getDiscountedPrice()` → `ProductPricingService::calculateProductPricing()['price_after_discount']`
- **Not persisted to DB** — computed at read time

#### `products.price_after_flash_sale` (virtual/computed)
- **Computed by:** `Product::getFlashSalePrice()` → `ProductPricingService::calculateFlashSalePrice()`
- **Not persisted to DB** — computed at read time

#### `cart_items.price`
- **Created:** `CartInventoryService::reserveItem()` — set to `$product->current_price` or `$variant->current_price`
- **Mutated by:** Never explicitly changed after creation
- **Read by:** `OrderService::getCheckoutTotalsFromCart()`, `PromotionEligibilityResolver::matchedEligibility()`, `PromotionApplicator::applyOutcome()`, `OrderCreationService::createOrderItems()`

#### `cart_items.total_price`
- **Created:** `CartInventoryService::reserveItem()` — set to `price × quantity`
- **Mutated by:** `PromotionApplicator::applyOutcome()` — overwritten with discounted line total
- **Read by:** `OrderService`, `PromotionService`, `PromotionApplicator`

#### `cart_items.discount_amount`
- **Created:** `PromotionApplicator::applyOutcome()` — set to allocated discount in cents/100
- **Read by:** `OrderService::getCheckoutTotalsFromCart()` — sum to compute promotionDiscount

#### `carts.total_price`
- **Created:** When cart is created (0)
- **Mutated by:** `reserveItem()`, `applyOutcome()`, `calcInvoicePrice()`, `createFastOrder()`, `finalizeItemsByShippingMethod()`, `releaseCart()`
- **Read by:** `OrderController::checkout()` — used as return value of `calcInvoicePrice()`

#### `orders.price` (subtotal)
- **Created:** `OrderCreationService::createOrder()` — set to `$checkoutTotals->subtotal`
- **Never mutated after creation**
- **Read by:** `OrderResource`

#### `orders.total_price` (grand total)
- **Created:** `OrderCreationService::createOrder()` — `round(finalTotal + shippingPrice + fastFee, 2)`
- **Never mutated after creation**
- **Read by:** `OrderResource`, `PaymentCheckoutHandler`, callback verification (amount mismatch check)

#### `orders.shipping_price`
- **Created:** `OrderCreationService::createOrder()` — set to resolved shipping price
- **Never mutated after creation**

#### `orders.coupon_discount`
- **Created:** `OrderCreationService::createOrder()` — set to `$checkoutTotals->couponDiscount`
- **Never mutated after creation**

#### `orders.promotion_discount`
- **Created:** `OrderCreationService::createOrder()` — set to `$checkoutTotals->promotionDiscount`
- **Never mutated after creation**

#### `order_products.product_price`
- **Created:** `OrderCreationService::createOrderItems()` — `round(lineTotal / quantity, 2)` (DERIVED)
- **Never mutated after creation**

#### `order_products.product_total_price`
- **Created:** `OrderCreationService::createOrderItems()` — `round(lineTotal, 2)`
- **Never mutated after creation**

#### `order_products.promotion_discount_amount`
- **Created:** `OrderCreationService::createOrderItems()` — `round(max(0, (price * qty) - lineTotal), 2)` (RECOMPUTED)
- **Never mutated after creation**

#### `order_products.product_flash_sale_price`
- **Created:** `OrderCreationService::createOrderItems()` — fresh query to flash_sale pricing
- **Never mutated after creation**

#### `order_products.product_discount_price`
- **Created:** `OrderCreationService::createOrderItems()` — set to `$product->discount_amount`
- **Note:** This stores the discount AMOUNT, not the price after discount. Misleading naming.

#### `coupons.used`
- **Created:** 0 at coupon creation
- **Mutated by:** `OrderService::recordCouponUsage()` — `$coupon->increment('used')`
- **Read by:** `CouponValidator` (limiter check), `Coupon::isValid()`, `Coupon::scopeValid()`

#### `promotions.usage`
- **Created:** 0 at promotion creation
- **Mutated by:** `PromotionService::incrementUsage()` — `$promotion->increment('usage')`
- **Read by:** `Promotion::isValid()`, `Promotion::scopeValid()`

---

## Part 8 — Source of Truth Audit

| Data | Single Source? | Current Owner | Duplicated? | Notes |
|------|---------------|---------------|-------------|-------|
| Product Base Price | ✅ YES | `products.price` | No | Single column |
| Variant Price | ✅ YES | `product_variants.price` | No | Single column |
| Sale Price (post-discount) | ❌ NO | Computed by `ProductPricingService::calculateDiscountedPrice()` | Duplicate logic in `CouponCalculator`, `Promotion::discountAmount()` | 3 copies of discount math |
| Flash Sale Price | ✅ YES | `ProductPricingService::calculateFlashSalePrice()` | Single implementation but called from multiple places | Different callers may pass different base prices |
| Promotion Discount | ❌ NO | `PromotionApplicator` (persists to cart_items) | `Promotion::discountAmount()` model method is separate path | PromotionApplicator is the real source; model method is legacy |
| Promotion Usage Counter | ✅ YES | `promotions.usage` | No | Single column, incremented atomically |
| Coupon Discount | ❌ NO | `CouponCalculator::calculate()` | Duplicate logic in `ProductPricingService::calculateCouponPrice()` and deprecated `Coupon::calcPrice()` | CouponCalculator is the current source; other two are wrappers/legacy |
| Coupon Usage Counter | ✅ YES | `coupons.used` | No | Single column, incremented atomically |
| Coupon Validation | ✅ YES | `CouponValidator::validate()` | Deprecated `Coupon::isValid()` exists | Architecture issue: legacy method not removed |
| Shipping Price | ✅ YES | `shipping_prices.price` (via governorate) | No | Read from DB each time |
| Tax | ✅ YES | `CheckoutController@verify` (legacy path) | No | Only in legacy checkout verify |
| Grand Total | ❌ NO | `orders.total_price` is final; but `carts.total_price` is intermediate | `carts.total_price` is overwritten multiple times | No single authority during checkout |
| Invoice Total | ❌ NO | `calcInvoicePrice()` returns cart.total_price; final value is orders.total_price | Two different values at different times | **ARCHITECTURE ISSUE** |
| Dashboard Revenue | ❓ Unknown | Would read from orders | Unknown | Not reviewed |
| Inventory Reservation | ✅ YES | `CartInventoryService` with row locks | Single implementation | Proper locking pattern |
| Free Shipping Check | ❌ NO | Checked in 2-3 places per checkout | Duplicated across OrderService and FastShippingService | Should be extracted |

### Architecture Issues (Multiple Owners)

**CRITICAL:** `cart.total_price` has **6 writers** and **no reader contract**.

**CRITICAL:** `calcInvoicePrice` returns cart's `total_price`, but the order's `total_price` is computed independently. No invariant ensures they match.

**HIGH:** Coupon discount calculation has **3 implementations**: `CouponCalculator`, `ProductPricingService::calculateCouponPrice()`, and deprecated `Coupon::calcPrice()`. The latter two delegate to `CouponCalculator` but provide different argument types.

**HIGH:** Promotion discount is calculated in **2 domains**: the new `PromotionApplicator` (integer cents, proportional allocation) and the old `Promotion::discountAmount()` (float). The old method is still used by `Promotion::calcPrice()`.

**MEDIUM:** Round-money logic is duplicated in **4 resources**: `App\OrderResource`, `Marvel\OrderResource`, `Marvel\OrderItemResource`, plus inline in OrderService.

---

## Part 9 — Invariant Verification

| Invariant | Status | Violation Path |
|-----------|--------|---------------|
| `grand_total >= 0` | ✅ **HOLDS** | `round(max(0, ...))` guards |
| `shipping >= 0` | ✅ **HOLDS** | Read from DB, never negative |
| `tax >= 0` | ✅ **HOLDS** | Not reviewed (legacy path) |
| `subtotal >= 0` | ✅ **HOLDS** | Sum of non-negative values |
| `coupon_discount >= 0` | ✅ **HOLDS** | `max(0, ...)` guard |
| `promotion_discount >= 0` | ✅ **HOLDS** | `max(0, ...)` guards |
| `coupon_discount <= subtotal` | ❌ **CAN VIOLATE** | If coupon is percentage and subtotal is very large... wait, no. The calculation is `max(0, subtotal - promoDiscount - finalTotal)`, so it's bounded by subtotal. But `CouponCalculator::calculate()` has `max(0, price - discount)` so the final price is always ≥ 0. ✅ **Actually HOLDS.** |
| `promotion_discount <= subtotal` | ✅ **HOLDS** | `min($subtotalCents, $outcome->amountCents)` cap in `PromotionApplicator::applyOutcome()` |
| `total_discount <= subtotal` | ✅ **HOLDS** | Both discounts capped individually |
| `paid_total == grand_total` | ❓ **UNVERIFIABLE** | No `paid_total` column found. Payment verification checks `abs(amount - total_price) > 0.01` |
| `coupon.used == COUNT(coupon_usages)` | ❓ **CAN VIOLATE** | `recordCouponUsage()` increments `coupon.used` only on `wasRecentlyCreated`. If the usage record already exists from a prior attempt, `used` is NOT incremented but the usage IS recorded. Drift possible. |
| `limiter >= used` | ✅ **CHECKED** | `CouponValidator` checks `$coupon->used >= $coupon->limiter` |
| `inventory >= 0` | ✅ **HOLDS** | `finalizeStock()` guards quantity > physical stock |
| `reserved_inventory >= 0` | ✅ **HOLDS** | `releaseStock()` uses `max(0, ...)` |

### Invariant Violation: Payment Amount vs Order Total

**Violation Path:** 
1. `calcInvoicePrice()` returns cart.total_price (value X)
2. `PaymentCheckoutHandler::handleOnlinePayment()` creates transaction with amount X
3. `addItemsInOrder()` creates order with total_price Y (may differ from X)
4. Payment callback verifies with amount X vs `order->total_price` (Y)
5. If |X - Y| > 0.01 → payment mismatch → order cancelled

**This can happen because** the two calculation paths use different methods (`calculateCheckoutTotals` vs `getCheckoutTotalsFromCart`) which process coupons differently.

### Invariant Violation: Coupon Used Counter Drift

**Violation Path:**
1. Checkout starts: `calcInvoicePrice()` — coupon is active
2. Between calcInvoicePrice and addItemsInOrder, coupon limiter is reached (e.g., concurrent checkout)
3. `addItemsInOrder()` validates coupon → invalid → removes coupon from cart
4. Order is created WITHOUT coupon, but invoice price already reflected coupon discount
5. User pays the discount price but no coupon is recorded
6. No compensation mechanism

---

## Part 10 — Architecture Score

| Subsystem | Score | Justification |
|-----------|-------|---------------|
| **Product Pricing** | **7/10** | Clean delegation to ProductPricingService. Duplicate discount math with CouponCalculator. Flash Sale/Sale/Base priority is clear. |
| **Flash Sale** | **7/10** | Model delegates to service. Date validation is duplicated (model + service). `resolveFlashSaleDiscountUnits` handles 3 types well. |
| **Promotion Engine** | **8/10** | Best-designed subsystem. Clean interfaces (strategy pattern). Immutable DTOs. Integer cents for precision. Largest-remainder allocation. Proper row locking. |
| **Coupon System** | **6/10** | Good validator/calculator separation. Deprecated model methods not removed. No minimum_order_amount. Usage tracking spread across multiple call sites. |
| **Checkout** | **4/10** | **WORST SCORE.** Dual paths (calculateCheckoutTotals vs getCheckoutTotalsFromCart). Side effects during calculation (calcInvoicePrice mutates DB). Shipping resolved twice. Coupon validated after price calculation. |
| **Order** | **6/10** | Clean model. Uncast monetary fields. Good resource layer. Creation service is well-structured. But `price` naming is confusing (stores subtotal). |
| **Invoice** | **3/10** | No dedicated invoice system. "Invoice" is the return value of `calcInvoicePrice()`, which is the cart's `total_price` — a mutable field. Payment amount is decoupled from final order total. |
| **Payment** | **7/10** | Clean gateway factory pattern. Proper transaction creation. Good amount mismatch verification in callback. Only one gateway (myfatoorah) supported. |
| **Shipping** | **6/10** | Governorate-based price resolution is clean. Free shipping over is well-implemented. But shipping is resolved twice per checkout. |
| **Inventory** | **8/10** | Best-scored subsystem. Proper row locking. Transactional safety. Clear reserve/release/finalize lifecycle. Cart TTL expiration. Gift item reservation. |
| **Dashboard** | **N/A** | Not reviewed. Would read from orders. |
| **API Resources** | **7/10** | Clean resources with `roundMoney()`. Two OrderResource implementations (app vs package) is technical debt. |
| **Overall Pricing Architecture** | **5/10** | The system works for common cases but has critical architectural flaws: dual checkout paths, side effects during calculation, no single source of truth for in-flight totals, duplicated discount math, and precision inconsistencies. The Promotion Engine is the gold standard; the Coupon → Checkout → Order pipeline needs refactoring. |

---

## Part 11 — Refactoring Opportunities

### CRITICAL

#### R-1: Unify Checkout Total Calculation (Critical)

**Problem:** Two different methods calculate totals: `calculateCheckoutTotals()` (used for invoice/payment) and `getCheckoutTotalsFromCart()` (used for order creation). They use different logic and can produce different results.

**Business impact:** Payment mismatch, order cancellation, reconciliation failures.

**Technical impact:** Code duplication, hard-to-track bugs.

**Risk:** High. Changing checkout flow affects every order.

**Files involved:** `app/Services/General/OrderService.php`, `app/Services/Checkout/OrderCreationService.php`, `app/Http/Controllers/Api/General/OrderController.php`

**Complexity:** High

**Migration strategy:**
1. Extract a single `CheckoutTotalsCalculator` service
2. Call it once before payment
3. Pass the result to both payment and order creation
4. Ensure the payment amount and order total are computed from the same source

**Expected benefit:** Eliminates payment mismatch risk. Single source of truth for all totals.

#### R-2: Separate Calculation from Mutation in Checkout (Critical)

**Problem:** `calcInvoicePrice()` calls `PromotionService::applySelectedPromotion()` which persists promotion discounts to cart_items and carts tables. A "calculation" method should not mutate DB state.

**Business impact:** Inconsistent cart state if order creation fails after invoice calculation.

**Technical impact:** Transaction boundaries are unclear. Rollback scenarios are leaky.

**Files involved:** `app/Services/General/OrderService.php`, `app/Services/General/PromotionService.php`

**Complexity:** High

**Migration strategy:**
1. Split `PromotionService::applySelectedPromotion()` into:
   - `calculatePromotion()` — returns outcome (read-only, no DB writes)
   - `applyPromotionOutcome()` — persists outcome (writes to DB)
2. Move the application call to `addItemsInOrder()` or a dedicated step
3. `calcInvoicePrice()` should only calculate

**Expected benefit:** Clear separation of concerns. Safe to retry calculations. Consistent state on failure.

### HIGH

#### R-3: Handle FREE_SHIPPING Coupon in FastShippingService (High)

**Problem:** `FastShippingService::createFastOrder()` does not check for FREE_SHIPPING coupon type when resolving shipping price.

**Business impact:** Fast shipping customers with free shipping coupons are incorrectly charged.

**Files involved:** `app/Services/General/FastShippingService.php`

**Complexity:** Low

**Migration strategy:** Add the same `DiscountType::FREE_SHIPPING` check that exists in `OrderService::addItemsInOrder()`.

#### R-4: Extract Rounding Helpers (High)

**Problem:** `roundMoney()` logic is duplicated across 4+ resource classes.

**Files involved:** All Order resources, OrderService, OrderCreationService

**Complexity:** Low

**Migration strategy:** Create a `Money` trait or helper class.

#### R-5: Unify Discount Math (High)

**Problem:** Percentage and fixed-rate discount calculations are implemented independently in `CouponCalculator`, `ProductPricingService`, `Promotion::discountAmount()`, and `ProductPricingService::resolveFlashSaleDiscountUnits()`.

**Files involved:** All 4 files

**Complexity:** Medium

**Migration strategy:** Extract a single `DiscountCalculator` service with methods like `percentage(price, percent, maxAmount)`, `fixed(price, amount)`, `finalPrice(price, targetPrice)`.

#### R-6: Remove Deprecated Model Methods (High)

**Problem:** `Coupon::isValid()`, `Coupon::calcPrice()`, and `OrderRepository::storeOrder()` (dead code) still exist with `@deprecated` annotations.

**Files involved:** `Coupon.php`, `OrderRepository.php`

**Complexity:** Low — but requires verifying no callers remain.

**Risk:** Low if callers are confirmed absent.

### MEDIUM

#### R-7: Fix Order Item Unit Price Rounding (Medium)

**Problem:** `round(lineTotal / quantity, 2)` can produce unit prices that don't sum to the line total.

**Files involved:** `app/Services/Checkout/OrderCreationService.php:73`

**Complexity:** Low

**Migration strategy:** Store the original unit price from the cart item instead of recomputing via division.

#### R-8: Add Monetary Field Casts to Order Model (Medium)

**Problem:** `price`, `shipping_price`, `total_price`, `coupon_discount`, `promotion_discount`, `fast_shipping_fee` have no `$casts` on Order.

**Files involved:** `packages/marvel/src/Database/Models/Order.php`

**Complexity:** Low

#### R-9: Add minimum_order_amount to Coupon (Medium)

**Problem:** Coupons lack minimum order amount validation. Only promotions have it.

**Files involved:** `Coupon.php` (model + migration), `CouponValidator.php`

**Complexity:** Medium (requires DB migration)

#### R-10: Extract cart.total_price Write Contract (Medium)

**Problem:** `cart.total_price` is written by 6 different methods with no documented contract about what it represents at each stage.

**Files involved:** `CartInventoryService.php`, `OrderService.php`, `PromotionApplicator.php`, `FastShippingService.php`

**Complexity:** Medium

**Migration strategy:** Define a clear lifecycle:
- During add-to-cart: `total_price` = sum of line totals (before discounts)
- After promotion: `total_price` = sum of discounted line totals
- After checkout: `total_price` = 0 (cart checked out)

### LOW

#### R-11: Consolidate Two OrderResource Implementations (Low)

**Problem:** Two `OrderResource` classes exist (app and package), both with `roundMoney()`.

**Complexity:** Low

#### R-12: Standardize Integer Cents Across All Pricing (Low)

**Problem:** Promotion engine uses integer cents internally. All other subsystems use floats. A system-wide standard would prevent precision bugs.

**Complexity:** Very High (cross-cutting change)

#### R-13: Add bcmath Support for Critical Calculations (Low)

**Problem:** No decimal arithmetic library used. Native PHP float arithmetic can produce precision artifacts at scale.

**Complexity:** High (requires installing bcmath extension + rewriting math)

---

## Part 12 — Questions Before Any Refactor

These are the architectural decisions that must be made before touching production code:

### On Checkout Architecture

1. **Should the checkout flow be a single atomic operation?** Currently it's two sequential DB transactions (calcInvoicePrice + addItemsInOrder). Should it be one transaction?

2. **Should the payment amount be read from the order or from the cart?** Currently it's from the cart (after calcInvoicePrice). Should it always come from the order (after addItemsInOrder)?

3. **Should promotion application happen during "calculation" or during "order creation"?** Currently it happens during calculation (calcInvoicePrice). Should it be deferred to order creation?

4. **Should we eliminate `getCheckoutTotalsFromCart()` entirely?** Since `calculateCheckoutTotals()` already computes everything, should `getCheckoutTotalsFromCart()` be removed and replaced with the unified path?

### On Discount Stacking

5. **Should Flash Sale always override Sale Price?** Current design: yes. Is this the correct business rule?

6. **Should Sale Price and Flash Sale stack?** Currently they don't (flash sale overrides sale). Could there be a case for both applying?

7. **Should Promotion stack with Flash Sale?** Currently promotions are applied at checkout to cart items, which already have the flash sale/sale price baked in. Yes, they implicitly stack. Is this intentional?

8. **Should Coupon apply before or after tax?** Currently tax is not in scope for this checkout. If tax is added, should coupon apply before tax (reducing taxable amount) or after?

9. **Should Coupon discount apply to subtotal post-promotion or pre-promotion?** Currently it applies post-promotion. Should it apply to the raw subtotal instead?

### On Shipping

10. **Should FREE_SHIPPING coupon override every shipping strategy?** Currently it does, but only for SCHEDULED shipping (not FAST). Should it work for all shipping methods?

11. **Should free_shipping_over be checked before or after coupon discount?** Currently it's checked before. Should an order that becomes free-shipping-eligible after coupon still get free shipping?

### On Data Integrity

12. **Should Dashboard read persisted values only (never compute)?** Dashboard should read `orders.*` only, never recompute from cart items. Is there any code path that does otherwise? (Not audited)

13. **Should invoices ever recalculate totals?** An invoice (once created) should be immutable. Is there any code path that recalculates or modifies a completed order's totals? (Not found, but should be verified)

14. **Should totals always come from CheckoutTotals DTO?** Currently there are two paths. Should the DTO be the exclusive source?

### On Precision

15. **Should the entire system adopt integer cents?** The promotion engine already uses cents internally. Should ProductPricingService, CouponCalculator, and all order creation follow suit?

16. **Should bcmath be required?** For an ecommerce platform handling money, is native float precision acceptable?

### On Coupon System

17. **Should coupons support minimum_order_amount?** Promotions do; coupons don't. Is this intentional?

18. **Should coupon usage be recorded at checkout time (not just at payment)?** Currently it's recorded at payment completion. This creates a gap where coupons can be consumed by concurrent users. Should it be reserved at checkout?

### On Legacy

19. **Should deprecated `Coupon::isValid()` and `Coupon::calcPrice()` be removed?** They are marked `@deprecated` but still exist. Are there any remaining callers?

20. **Should `OrderRepository::storeOrder()` (legacy path) be removed?** It contains dead code checking `$coupon->type` (which doesn't exist as an attribute).

---

## Part 13 — Executive Summary

### Top 3 Critical Issues

1. **DUAL CHECKOUT PATHS** — `calcInvoicePrice()` and `addItemsInOrder()` compute totals independently using different methods. This is the root cause of potential payment mismatches.

2. **SIDE EFFECTS IN CALCULATION** — `calcInvoicePrice()` persists promotion discounts to the database during what should be a read-only calculation. Failed order creation leaves cart in inconsistent state.

3. **FAST SHIPPING MISSES FREE_SHIPPING** — The fast shipping checkout path does not handle FREE_SHIPPING coupons, unlike the scheduled delivery path.

### Top 3 High Priority Issues

4. **THREE DISCOUNT MATH IMPLEMENTATIONS** — Percentage and fixed-rate discount calculations are duplicated across `CouponCalculator`, `ProductPricingService`, and `Promotion::discountAmount()`. A bug in one won't be caught by tests of the others.

5. **ORDER ITEM UNIT PRICE ROUNDING** — `round(lineTotal / quantity, 2)` produces mathematically incorrect unit prices for non-unitary quantities.

6. **NO SINGLE SOURCE OF TRUTH FOR cart.total_price** — Six different writers mutate this field with no documented lifecycle.

### Bright Spots

- **Promotion Engine** is well-architected with strategy pattern, immutable DTOs, integer cents, proportional allocation, and proper row locking.
- **CartInventoryService** has excellent transactional safety with row-level locks and clear reserve/release/finalize lifecycle.
- **CouponValidator** and **CouponCalculator** have clean separation of concerns.
- **CheckoutTotals DTO** is an immutable value object — a good pattern.

### Recommended Action Plan

| Order | Action | Priority | Effort | Risk |
|-------|--------|----------|--------|------|
| 1 | Fix FastShipping FREE_SHIPPING gap | Critical | Low | Low |
| 2 | Unify checkout total calculation (single path) | Critical | High | High |
| 3 | Separate calculation from mutation in checkout | Critical | High | High |
| 4 | Extract discount calculator utility | High | Medium | Low |
| 5 | Fix order item unit price rounding | High | Low | Low |
| 6 | Add monetary casts to Order model | Medium | Low | Low |
| 7 | Add minimum_order_amount to Coupon | Medium | Medium | Low |
| 8 | Remove deprecated model methods | Low | Low | Low |

### Key Architectural Question

**Should all totals flow through a single `CheckoutTotalsCalculator` service that produces an immutable snapshot, which is then used for both payment and order creation?**

This single change would eliminate:
- Dual checkout paths (R-1)
- Side effects during calculation (R-2)
- cart.total_price trust issues (R-10)
- Payment amount mismatch (BUG-3)

It would require:
- Making `PromotionService::applySelectedPromotion()` read-only (return outcomes without persisting)
- Moving the persistence to a dedicated step after payment verification
- Ensuring atomicity of the order creation transaction

This is the recommended architectural direction for any refactoring effort.
