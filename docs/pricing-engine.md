# Pricing Engine — Complete Architecture Documentation

> Version: 1.0 | Classification: INTERNAL | Last Updated: 2026-07-27

---

## Executive Summary

The pricing engine is the computational core of the commerce platform. It resolves a product's final selling price through a cascading chain (flash sale → sale discount → base price), then aggregates line totals into a checkout total, applies promotions (opt-in, per-item proportional allocation) and coupons (code-based, aggregate discount), and finally adds shipping costs to produce the grand total. The engine operates in two phases: a **preview phase** (`calcInvoicePrice`) that shows the user what they will pay, and a **creation phase** (`addItemsInOrder`) that re-reads prices, re-validates discounts, snapshots the final amounts, and persists the order. Tax calculation is **not implemented** in the current checkout flow.

---

## 1. Price Resolution Chain

Products and their variants have a cascading price hierarchy. Each step overrides the previous.

### 1.1 Resolution Order

```
FLASH SALE PRICE    (highest priority, if active)
        ↓
SALE DISCOUNT       (if has_discount = true & discount active)
        ↓
BASE PRICE          (products.price or product_variants.price)
```

### 1.2 Implementation

**Class**: `packages/marvel/src/Services/Pricing/ProductPricingService.php`

#### `calculateProductPricing(Product $product, ?int $quantity = 1): array`
Returns:
```php
[
    'base_price'         => float,   // products.price
    'final_price'        => float,   // the resolved selling price
    'has_discount'       => bool,    // sale discount is active
    'discount_type'      ?string,    // 'percentage' | 'fixed_rate' | null
    'discount_amount'    ?float,     // sale discount value
    'price_after_discount' => float, // base - sale discount
    'has_flash_sale'     => bool,
    'flash_sale_info'    => ?array,  // flash sale details
    'price_after_flash_sale' => float,
    'price_after_variant' => float,  // variant price if applicable
]
```

#### `calculateProductCurrentPrice(Product $product, ?int $quantity = 1): float`
Returns the single final price value — the **authoritative source of truth** for what a customer pays per unit.

#### Flash Sale Discount
- **File**: `ProductPricingService::resolveFlashSaleDiscountCents()`
- **Percentage**: `baseCents × (discountValue / 100)`, capped at `max_discount_amount` cents
- **Fixed Rate**: `toCents(discountValue)` subtracted from base cents
- **Final Price**: `baseCents - discountCents`

#### Sale Discount
- **File**: `ProductPricingService::calculateDiscountedPrice()`
- **Percentage**: `priceCents × (amount / 100)`, amount capped at 100%
- **Fixed Rate**: `priceCents - discountCents`, floored at 0

#### Variant Price Override
- If `$product->product_variants` exist with a non-null `price`, that price overrides the base product price entirely.

---

## 2. Tax Calculation

### Current Status: NOT IMPLEMENTED

The new checkout flow (`App\General\OrderController`) does **not** include tax calculation. The `tax_classes` table migration exists but is commented out:

```php
// database/migrations/xxxx_xx_xx_create_tax_classes_table.php
Schema::create('tax_classes', function (Blueprint $table) {
    $table->id();
    $table->string('country')->nullable();
    $table->string('state')->nullable();
    $table->string('zip')->nullable();
    $table->string('city')->nullable();
    $table->double('rate');           // e.g. 14 = 14%
    $table->string('name')->nullable();
    $table->integer('is_global')->nullable();
    $table->integer('priority')->nullable();
    $table->boolean('on_shipping')->default(1);
    $table->timestamps();
});
```

A legacy `CheckoutRepository::calculateTax()` exists in the deprecated Marvel REST path but is not wired into the current checkout pipeline. Any future implementation should add tax as a separate line item after subtotal but before the grand total, respecting the `on_shipping` flag.

---

## 3. Shipping Cost Calculation

### 3.1 Governorate-Based Shipping

**File**: `app/Services/General/OrderService.php`

```php
OrderService::resolveShippingPrice(?int $governorateId): array
```

**Resolution**:
1. Lookup `Governorate::find($governorateId)`
2. Query `governorate->shippingPrice()->where('status', true)->first()`
3. Returns: `['price' => float, 'free_shipping_over' => ?float, 'governorate_id' => ?int]`

### 3.2 Free Shipping Priority

Evaluated in order:

| Priority | Condition | Effect |
|---|---|---|
| 1 | `subtotal > free_shipping_over` (from `shipping_prices.free_shipping_over`) | shipping = 0 |
| 2 | `coupon.discount_type === 'free_shipping'` | shipping = 0 |

Both checks are independent — if either triggers, shipping is zeroed.

### 3.3 Fast Shipping

**File**: `app/Services/General/FastShippingService.php`
- Separate checkout path (`/fast-shipping/checkout`)
- Filters cart items by `shipping_method = 'FAST'`
- Adds `fastShippingFee` from `FastShippingRepository::getFee()`
- **BUG**: Does NOT check for `FREE_SHIPPING` coupons (unlike `OrderService`)

### 3.4 Database Schema

```sql
shipping_prices:
  price              decimal(10,2)     -- shipping cost for governorate
  free_shipping_over decimal(10,2)     -- subtotal threshold for free shipping

orders:
  shipping_price     decimal(8,3)      -- resolved shipping cost at order time
  fast_shipping_fee  decimal(12,2)     -- fast shipping surcharge
```

---

## 4. Promotion Discount Engine

### 4.1 Architecture

The promotion engine uses the **Strategy Pattern**. Each promotion type has its own strategy class that determines eligibility and computes discounts.

```
PromotionService::applySelectedPromotion()
    ↓
PromotionEligibilityResolver::resolve(cart, promotion)
    ↓ (for each eligible item)
    PromotionStrategy::eligible(item, promotion)  ← checks minimum_order_amount, required_quantity
    PromotionStrategy::compute(item, promotion)   ← computes discount in integer cents
    ↓
PromotionApplicator::applyOutcome(cart, outcome)
    ↓ (persists to cart_items)
    discount_amount, total_price, promotion_id, is_gift
```

### 4.2 Strategy Classes

| Strategy | Type | Computation |
|---|---|---|
| `PercentagePromotionStrategy` | percentage | `lineCents × (value / 100)`, capped at `max_discount_amount` cents |
| `FixedPromotionStrategy` | fixed_rate | Fixed `toCents(value)` per eligible item |
| `GiftPromotionStrategy` | gift | Gift product lookup, price set to 0 |

### 4.3 Proportional Allocation (Largest-Remainder)

To distribute a promotion discount across multiple cart items without rounding loss:

```php
$exactShare   = ($lineCents * $amountCents) / $sumLineCents;
$floorShare   = floor($exactShare);
$remainder    = $exactShare - $floorShare;  // fractional part
```

Remaining cents are distributed one-at-a-time to items with the largest fractional remainder, ensuring:
```
sum(allocated_cents) === intended_discount_cents
```

### 4.4 Gift Items

- Created by `GiftPromotionStrategy::compute()`
- Stored with `price = 0`, `total_price = 0`, `is_gift = true`
- Excluded from all subtotal/final total calculations
- Removed from cart when promotion selection changes
- Stock is reserved and finalized like regular items

### 4.5 Side Effect Warning

**`PromotionService::applySelectedPromotion()` mutates the database** (writes to `cart_items.discount_amount`, `cart_items.total_price`, `cart_items.promotion_id`, `cart_items.is_gift`) during what is logically a calculation/preview operation. This means:
- Calling `calcInvoicePrice()` irreversibly changes cart state
- If the user navigates away without checking out, the cart retains promotion-applied prices
- The "preview" is not idempotent

---

## 5. Coupon Discount Engine

### 5.1 Architecture

```
CouponOrchestrator::validateCoupon(couponCode, cart, user)
    ├── CouponAssignmentValidator::validate()  ← user-assigned quotas
    └── CouponValidator::validate()            ← status, dates, limiter, per-user, product scope

OrderService::calculatePriceByCoupon(cart, coupon)
    └── CouponCalculator::calculate(coupon, subtotal)
```

### 5.2 Discount Types

| Type | Computation | Cap |
|---|---|---|
| `percentage` | `subtotal × (discount / 100)` | `max_discount_amount` |
| `fixed_rate` | Fixed `discount` value | N/A (capped at subtotal) |
| `free_shipping` | Does not affect subtotal; zeroes shipping | N/A |

### 5.3 CouponCalculator

**File**: `app/Services/Coupon/CouponCalculator.php`

```php
CouponCalculator::calculate(Coupon $coupon, float $subtotal): array
```

Returns:
```php
[
    'discount'       => float,   // calculated discount amount (capped)
    'discount_type'  => string,  // 'percentage' | 'fixed_rate' | 'free_shipping'
    'max_amount'     => ?float,  // max_discount_amount (if percentage)
]
```

**Important**: The coupon discount applies to the **subtotal after promotions**. The order of operations is:
1. Subtotal = sum of non-gift cart item totals (already promotion-adjusted)
2. Coupon discount = `CouponCalculator::calculate(coupon, subtotal)`
3. Final total (pre-shipping) = subtotal - promotion_discount - coupon_discount

### 5.4 Usage Tracking

- `coupons.used` incremented in `CouponService::recordCouponUsage()` — called after successful payment callback
- Per-user usage tracked via `coupon_user` pivot table
- Assignment quotas tracked via `coupon_assignments.used` — checked by `CouponAssignmentValidator`
- Uses `firstOrCreate` with `wasRecentlyCreated` guard under `lockForUpdate`

---

## 6. Checkout Totals Calculation Flow

### 6.1 Phase 1: Preview (`calcInvoicePrice`)

```
OrderService::calcInvoicePrice(cart, request)
    │
    ├── Validate coupon via CouponOrchestrator
    ├── Cart validation (stock, existence)
    │
    ├── calculateCheckoutTotals()
    │   ├── PromotionService::applySelectedPromotion()   ← DB SIDE EFFECT
    │   ├── OrderService::calculatePriceByCoupon()
    │   │   └── CouponCalculator::calculate()
    │   └── Returns CheckoutTotals DTO
    │
    ├── resolveShippingPrice(governorate_id)
    ├── resolveFreeShippingByThreshold(subtotal)
    ├── resolveFreeShippingByCoupon(coupon)
    │
    └── finalTotal = checkoutTotals->finalTotal + shippingPrice
        cart->update(['total_price' => $finalTotal])     ← DB SIDE EFFECT
```

**Returns** (`CheckoutTotals` DTO):

```php
class CheckoutTotals {
    public float $subtotal;                // sum of non-gift item totals
    public ?float $promotionDiscount;       // total promotion discount
    public ?float $couponDiscount;          // calculated coupon discount
    public float $finalTotal;               // subtotal - promotionDiscount - couponDiscount
    public ?object $promotion;              // applied promotion details
    public array $giftItems;                // gift items from promotion
    public ?string $coupon;                 // coupon code
    public ?string $couponDiscountType;     // 'percentage' | 'fixed_rate' | 'free_shipping'
    public ?float $couponDiscountMaxAmount; // cap for percentage coupons
    public string $currency;                // 'EGP'
}
```

### 6.2 Phase 2: Order Creation (`addItemsInOrder`)

```
OrderService::addItemsInOrder(cart, request)
    │
    ├── refreshCartItemPrices()            ← re-reads current prices from ProductPricingService
    ├── Re-validates coupon (lockForUpdate)
    ├── getCheckoutTotalsFromCart()         ← reads persisted cart_item values
    │
    ├── resolveShippingPrice()
    ├── resolveFreeShippingByThreshold()
    ├── resolveFreeShippingByCoupon()
    │
    └── OrderCreationService::createOrder()
        ├── totalPrice = finalTotal + shippingPrice + fastShippingFee
        ├── createOrderItems()              ← snapshots pricing to order_products
        └── finalizeOrder()
            ├── PromotionService::incrementUsage()
            └── event(new OrderCreated($order))
```

### 6.3 The Dual-Path Problem

**`calcInvoicePrice()`** and **`addItemsInOrder()`** compute totals using different data:

| Aspect | calcInvoicePrice | addItemsInOrder |
|---|---|---|
| Item prices | From `cart_items.price` (added at cart time) | **Refreshed** via `refreshCartItemPrices()` |
| Promotion discount | **Fresh computation** via `PromotionService::applySelectedPromotion()` | **Reads persisted** `cart_items.discount_amount` |
| Subtotal | Fresh sum of item totals after promotion | Reads persisted `cart_items.total_price` |
| Shipping | Fresh resolve + free shipping check | Same logic re-executed |

If a flash sale ends between preview and creation, or if prices change, the two phases can produce **different totals**.

---

## 7. Promotions vs Coupons: Comparison

| Property | Promotion | Coupon |
|---|---|---|
| **Trigger** | User selects at checkout (opt-in) | Code entered on cart |
| **Discount scope** | Per-item, proportional allocation | Aggregate on subtotal |
| **Calculation unit** | Integer cents (largest-remainder) | Float (native PHP arithmetic) |
| **Types** | percentage, fixed_rate, gift | percentage, fixed_rate, free_shipping |
| **Persistence during checkout** | Written to `cart_items` | Stored only on order record |
| **Minimum order amount** | Yes (`minimum_order_amount`) | **No** (field does not exist) |
| **Product scope** | All products or specific products | All products or specific products |
| **Max uses (limiter)** | `promotions.limiter` | `coupons.limiter` |
| **User assignment** | N/A | Via `coupon_assignments` table |
| **Usage increment** | At order finalization | After payment callback |

---

## 8. Cart-to-Order Data Flow

```
CART ITEMS at add-to-cart time:
  price        = ProductPricingService::calculateProductCurrentPrice()
  total_price  = price × quantity
  (no promotion data yet)

AFTER promotion is selected:
  cart_items.discount_amount  = allocated promotion discount
  cart_items.total_price      = price - discount_amount (per item)
  cart_items.promotion_id     = selected promotion ID
  cart_items.is_gift          = true/false

CART at preview:
  cart.total_price = finalTotal + shippingPrice

ORDER at creation:
  orders.price           = subtotal (sum of order_product.product_total_price)
  orders.total_price     = grand total (price + shipping + fast_fee)
  orders.coupon_discount = coupon discount amount
  orders.promotion_discount = sum of cart_items.discount_amount

ORDER PRODUCTS:
  product_price         = unit price (BUG: round(total / qty, 2))
  product_total_price   = line total
  promotion_discount_amount = per-item promotion discount
```

---

## 9. Discount Stacking Summary

```
LINE ITEM LEVEL:
  Base Price
    → Flash Sale (override)
    → Sale Discount (if no flash sale)
    → Promotion Discount (proportional allocation)
    → Final Line Total

ORDER LEVEL:
  Sum(Line Totals) = Subtotal
    → Coupon Discount (% or fixed on subtotal)
    → Final Total (pre-shipping)
    → Shipping Cost (governorate rate, possibly free)
    → Fast Shipping Fee (if applicable)
    → GRAND TOTAL
```

---

## 10. Known Issues & Technical Debt

### CRITICAL

| ID | Issue | Impact | File |
|---|---|---|---|
| P-1 | Dual checkout paths compute totals differently | Order total may differ from preview total | `OrderService` |
| P-2 | `FastShippingService` does not check FREE_SHIPPING coupons | Fast shipping customers cannot use free shipping coupons | `FastShippingService` |
| P-3 | `PromotionService::applySelectedPromotion()` has DB side effects during preview | Calculation is destructive; cart state is mutated on preview | `PromotionService` |

### HIGH

| ID | Issue | Impact | File |
|---|---|---|---|
| P-4 | `round(lineTotal / quantity, 2)` for unit price | Rounding errors for multi-quantity items (e.g., 10.00 ÷ 3 = 3.33 × 3 = 9.99) | `OrderCreationService` |
| P-5 | `cart.total_price` has 6 different writers | No single lifecycle; value is ambiguous depending on when it is read | Various |
| P-6 | Three independent discount math implementations | Percentage/fixed logic duplicated across `CouponCalculator`, `ProductPricingService`, `Promotion` models | Multiple |

### MEDIUM

| ID | Issue | Impact | File |
|---|---|---|---|
| P-7 | No tax implementation | Tax cannot be collected or reported | N/A |
| P-8 | No `minimum_order_amount` on coupons | Cannot enforce minimum spend for coupon use | `coupons` table |
| P-9 | Order model has no `$casts` for monetary fields | All monetary values are raw floats; no type safety | `Order` model |
| P-10 | Mixed precision domains: D8,3, D10,2, D12,2 | Inconsistent decimal precision across monetary fields | Multiple migrations |
| P-11 | Native PHP float arithmetic (no bcmath) | Floating-point accumulation errors possible at scale | All pricing code |

---

## 11. Database Schema — Pricing Fields

### Products

| Column | Type | Purpose |
|---|---|---|
| `price` | decimal(10,2) | Base price |
| `has_discount` | boolean | Discount enabled flag |
| `discount_type` | enum(percentage,fixed_rate) | Sale discount type |
| `discount_amount` | double(10,2) | Sale discount value |
| `discount_status` | boolean | Discount active flag |
| `start_date` / `end_date` | date | Discount validity period |
| `price_after_discount` | decimal(10,2) | Computed post-sale price |
| `price_after_flash_sale` | decimal(10,2) | Computed post-flash-sale price |

### Product Variants

| Column | Type | Purpose |
|---|---|---|
| `price` | double(10,2) | Override base price |
| `sale_price` | double(10,2) | Variant sale price |

### Coupons

| Column | Type | Purpose |
|---|---|---|
| `discount_type` | enum(percentage,fixed_rate,free_shipping) | Discount type |
| `discount` | decimal(8,3) | Discount value |
| `max_discount_amount` | decimal(10,2) | Percentage cap |
| `limiter` | integer | Max global uses |
| `used` | integer | Current usage |

### Promotions

| Column | Type | Purpose |
|---|---|---|
| `type_amount` | enum(percentage,fixed_rate,gift) | Type |
| `value` | decimal(10,2) | Discount value |
| `max_discount_amount` | decimal(10,2) | Percentage cap |
| `minimum_order_amount` | decimal(10,2) | Minimum subtotal |
| `required_quantity_type` | integer | Quantity condition |

### Cart Items

| Column | Type | Purpose |
|---|---|---|
| `price` | decimal(10,2) | Unit price at add-to-cart time |
| `total_price` | decimal(10,2) | Line total (mutated by promotion) |
| `discount_amount` | decimal(10,2) | Promotion discount on this item |
| `is_gift` | boolean | Gift flag |
| `promotion_id` | integer, nullable | Linked promotion |

### Orders

| Column | Type | Purpose |
|---|---|---|
| `price` | decimal(8,3) | Subtotal |
| `total_price` | decimal(8,3) | Grand total |
| `shipping_price` | decimal(8,3) | Shipping cost |
| `fast_shipping_fee` | decimal(12,2) | Fast shipping surcharge |
| `coupon_discount` | decimal(10,3) | Coupon discount amount |
| `promotion_discount` | decimal(10,3) | Promotion discount amount |
| `promotion_id` | integer | Applied promotion |
| `promotion_type` | string | Promotion type |

### Order Products

| Column | Type | Purpose |
|---|---|---|
| `product_price` | decimal(8,3) | Unit price (BUG: see P-4) |
| `product_total_price` | decimal(8,3) | Line total |
| `product_discount_price` | decimal(10,3) | Discount applied |
| `product_flash_sale_price` | decimal(10,3) | Flash sale price |
| `promotion_discount_amount` | decimal(10,2) | Promotion discount |
| `is_gift` | boolean | Gift flag |

---

## 12. Key Classes Reference

### Pricing Services

| Class | File | Responsibility |
|---|---|---|
| `ProductPricingService` | `packages/marvel/src/Services/Pricing/ProductPricingService.php` | Single authority for product/variant price resolution |
| `CouponCalculator` | `app/Services/Coupon/CouponCalculator.php` | Coupon discount math (percentage/fixed/free_shipping) |
| `CouponValidator` | `app/Services/Coupon/CouponValidator.php` | Coupon eligibility validation |
| `CouponOrchestrator` | `app/Services/Coupon/CouponOrchestrator.php` | Coupon validation orchestration |
| `CouponAssignmentValidator` | `app/Services/Coupon/CouponAssignmentValidator.php` | User-assigned coupon quota validation |

### Promotion Engine

| Class | File | Responsibility |
|---|---|---|
| `PromotionService` | `app/Services/General/PromotionService.php` | Promotion application orchestration |
| `PromotionEligibilityResolver` | `app/Services/General/PromotionEngine/PromotionEligibilityResolver.php` | Eligibility resolution |
| `PromotionApplicator` | `app/Services/General/PromotionEngine/PromotionApplicator.php` | Outcome persistence to cart_items |
| `PercentagePromotionStrategy` | `app/Services/General/PromotionEngine/Strategies/PercentagePromotionStrategy.php` | Percentage discount |
| `FixedPromotionStrategy` | `app/Services/General/PromotionEngine/Strategies/FixedPromotionStrategy.php` | Fixed discount |
| `GiftPromotionStrategy` | `app/Services/General/PromotionEngine/Strategies/GiftPromotionStrategy.php` | Gift product resolution |
| `DiscountOutcome` | `app/Services/General/PromotionEngine/Outcome/DiscountOutcome.php` | Discount outcome DTO (cents) |
| `GiftOutcome` | `app/Services/General/PromotionEngine/Outcome/GiftOutcome.php` | Gift outcome DTO |

### Checkout & Order

| Class | File | Responsibility |
|---|---|---|
| `OrderService` | `app/Services/General/OrderService.php` | Checkout orchestrator, calculation entry point |
| `OrderCreationService` | `app/Services/Checkout/OrderCreationService.php` | Order/order_item creation and snapshot |
| `FastShippingService` | `app/Services/General/FastShippingService.php` | Fast shipping checkout |
| `CartInventoryService` | `app/Services/General/CartInventoryService.php` | Inventory reservation/release |

### DTOs

| DTO | File | Fields |
|---|---|---|
| `CheckoutTotals` | `app/DTOs/CheckoutTotals.php` | subtotal, promotionDiscount, couponDiscount, finalTotal, promotion, giftItems, coupon, couponDiscountType, couponDiscountMaxAmount, currency |

---

## 13. Security & Concurrency

### Price Integrity
- **`refreshCartItemPrices()`** re-reads `ProductPricingService` at order creation, preventing stale flash sale or discount prices
- All critical reads use **`lockForUpdate()`**: cart items, product inventory, promotions, coupon assignments, orders, transactions

### Validation Points
| Point | What is validated |
|---|---|
| Cart → Checkout | Stock availability, cart existence |
| Coupon application | Coupon validity, dates, usage limits, assignment quotas |
| Promotion selection | Promotion validity, minimum order amount, quantity requirements |
| Order creation | Minimum order amount (global setting), stock re-validation |
| Payment callback | Gateway amount vs `order.total_price` mismatch check |

---

## 14. Performance Considerations

- **N+1 on price resolution**: Each cart item triggers a separate `ProductPricingService` call. For large carts (20+ items), this means 20+ queries plus variant lookups
- **No caching**: Product prices are computed fresh on every call. Flash sale status, discount status, and variant prices are all read from DB each time
- **Dual computation**: `calcInvoicePrice` + `addItemsInOrder` both run the full pricing pipeline, doubling work on every checkout
- **Integer cent conversion**: Every monetary value is converted via `toCents(float) → int → fromCents(int) → float`, adding unnecessary overhead for a pure-float system
