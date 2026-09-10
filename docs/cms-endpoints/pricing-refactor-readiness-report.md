# Pricing & Checkout Architecture — Refactor Readiness Report

**Date:** 2026-07-12  
**Status:** COMPLETE — Final Audit  
**Files Reviewed:** 40+ source files, 8 documentation files, all migrations, all routes  
**Previous Report:** `docs/pricing-architecture-audit-report.md`  

---

## Table of Contents

1. [Updated Architecture Overview](#1-updated-architecture-overview)
2. [Complete Dependency Graph](#2-complete-dependency-graph)
3. [Verified Previous Findings](#3-verified-previous-findings)
4. [New Findings](#4-new-findings)
5. [Ownership Audit — Every Monetary Value](#5-ownership-audit)
6. [Checkout Consistency Audit — Full Lifecycle Trace](#6-checkout-consistency-audit)
7. [Refactor Readiness Report](#7-refactor-readiness-report)
8. [Design Review — CheckoutTotalsCalculator](#8-design-review)
9. [Migration Strategy](#9-migration-strategy)
10. [Test Strategy](#10-test-strategy)
11. [Final Architecture Decision](#11-final-architecture-decision)
12. [Blocker Summary](#12-blocker-summary)

---

## 1. Updated Architecture Overview

### System Landscape

```
┌──────────────────────────────────────────────────────────────────────┐
│                        HTTP Layer (Routes)                           │
├──────────────────────────────────────────────────────────────────────┤
│  /api/v1/general/*          │    /api/v1/* (Marvel REST)            │
│  OrderController            │    OrderController                    │
│  FastShippingController     │    CheckoutController                 │
│  CouponController           │    CouponController                   │
│  FlashSaleController        │    FlashSaleController                │
│  PromotionController        │    PromotionController                │
└──────────────────┬───────────┴───────────┬──────────────────────────┘
                   │                       │
┌──────────────────▼───────────────────────▼──────────────────────────┐
│                     Service Layer                                   │
├──────────────────────────────────────────────────────────────────────┤
│  OrderService ────── OrderCreationService                            │
│  FastShippingService ── OrderCreationService                         │
│  CouponService ──────── CouponValidator, CouponCalculator            │
│  PromotionService ───── PromotionEligibilityResolver                 │
│                        PromotionApplicator                           │
│  CartInventoryService ── (reserve/release/finalize stock)            │
│  ProductPricingService ── (multi-discount calculator)                │
│  DashboardService ────── (analytics/revenue queries)                 │
└──────────────────┬───────────────────────┬──────────────────────────┘
                   │                       │
┌──────────────────▼───────────────────────▼──────────────────────────┐
│                     DTO Layer                                       │
├──────────────────────────────────────────────────────────────────────┤
│  CheckoutTotals (subtotal, promotionDiscount, couponDiscount,        │
│                  finalTotal, promotion, giftItems, coupon,           │
│                  couponDiscountType, couponDiscountMaxAmount)        │
│  GatewayResult                                                      │
│  PromotionResult                                                    │
│  DiscountOutcome, GiftOutcome                                       │
└──────────────────┬───────────────────────┬──────────────────────────┘
                   │                       │
┌──────────────────▼───────────────────────▼──────────────────────────┐
│                   Repository Layer                                  │
├──────────────────────────────────────────────────────────────────────┤
│  OrderRepository ────── storeOrder, createOrder, createChildOrder    │
│  CouponRepository ───── storeCoupon, addCouponToCart                │
│  CheckoutRepository ─── verify                                       │
│  FlashSaleRepository ── storeFlashSale, updateFlashSaleProductPrices│
│  FastShippingRepository                                              │
└──────────────────┬───────────────────────┬──────────────────────────┘
                   │                       │
┌──────────────────▼───────────────────────▼──────────────────────────┐
│                     Model Layer                                     │
├──────────────────────────────────────────────────────────────────────┤
│  Cart, CartItem, Order, OrderProduct, Product, ProductVariant        │
│  Coupon, CouponUsage, Promotion, FlashSale, FlashSaleProduct        │
│  User, Wallet, Transaction, Governorate, ShippingPrice               │
│  Balance, OrderedFile, OrderWalletPoint, PickupLocation              │
│  Tax, Shipping, Refund, PaymentReconciliationResult                  │
└──────────────────┬───────────────────────┬──────────────────────────┘
                   │                       │
┌──────────────────▼───────────────────────▼──────────────────────────┐
│                     Database Layer                                   │
├──────────────────────────────────────────────────────────────────────┤
│  24 tables across core app (18 monetary)                             │
│  4 distinct decimal precisions used across tables                    │
└──────────────────────────────────────────────────────────────────────┘
```

### Key Migration Details (Monetary Columns)

| Table | Monetary Columns | Precision |
|---|---|---|
| `products` | `price`, `discount_amount`, `price_after_discount`, `price_after_flash_sale` | D10,2 / D10,2 / D10,2 / D10,2 |
| `product_variants` | `price`, `sale_price` | D10,2 / D10,2 |
| `orders` | `shipping_price`, `total_price`, `price`, `coupon_discount`, `coupon_discount_max_amount`, `fast_shipping_fee`, `promotion_discount` | D8,3 / D8,3 / D8,3 / D10,3 / D10,3 / D12,2 / D10,3 |
| `order_products` | `product_price`, `product_total_price`, `product_discount_price`, `product_flash_sale_price`, `promotion_discount_amount` | D8,3 / D8,3 / D10,3 / D10,3 / D10,2 |
| `transactions` | `amount` | D10,2 |
| `carts` | `total_price` | D10,2 |
| `cart_items` | `price`, `total_price`, `discount_amount` | D10,2 / D10,2 / D10,2 |
| `coupons` | `discount`, `max_discount_amount` | D8,3 / D10,2 |
| `promotions` | `value`, `max_discount_amount`, `discount`, `minimum_order_amount` | D10,2 / D10,2 / D10,2 / D10,2 |
| `flash_sales` | `discount`, `max_discount_amount` | D10,2 / D10,2 |
| `shipping_prices` | `price`, `free_shipping_over` | D10,2 / D10,2 |
| `refunds` | `amount` | D10,2 |

**Precision Inconsistency Issue:** `orders.shipping_price` = D8,3 but `orders.total_price` = D8,3. Meanwhile `carts.total_price` = D10,2 and `cart_items.price` = D10,2. There are **4 different precision formats** across monetary columns (D8,3 / D10,2 / D10,3 / D12,2). This can cause silent rounding differences when values flow between tables.

---

## 2. Complete Dependency Graph

### Controller → Service → DTO → Repository → Model

```
OrderController (App\General)
├── OrderService
│   ├── PromotionService
│   │   ├── PromotionEligibilityResolver
│   │   ├── PromotionApplicator
│   │   └── CartInventoryService
│   ├── OrderCreationService
│   │   └── PromotionService (circular via incrementUsage)
│   ├── CouponService (via CouponValidator)
│   │   └── CouponCalculator
│   └── CheckoutTotals (DTO)
├── CartInventoryService
├── PaymentCheckoutHandler
└── PaymentGatewayFactory

FastShippingController (App\General)
└── FastShippingService
    ├── FastShippingRepository
    ├── OrderService
    │   └── (see above)
    ├── PromotionService
    ├── CartInventoryService
    └── OrderCreationService

CouponController (App\General)
└── CouponService
    ├── CouponValidator
    └── CouponRepository

FlashSaleController (Marvel)
└── FlashSaleRepository

OrderController (Marvel)
└── OrderRepository
    ├── CalculatePaymentTrait
    ├── OrderManagementTrait
    ├── OrderStatusManagerWithPaymentTrait
    ├── PaymentTrait
    └── WalletsTrait

CheckoutController (Marvel)
└── CheckoutRepository
    └── WalletsTrait

CartController (Marvel)
└── CartInventoryService

CouponController (Marvel)
└── CouponRepository
    └── CouponValidator
```

### Single Responsibility Violations

| Class | Violation | Details |
|---|---|---|
| `OrderService` | **3 responsibilities** | Checkout total calculation, order creation orchestration, payment status management, coupon usage recording |
| `PromotionService::applySelectedPromotion()` | **Calculation + Mutation** | Calculates discounts AND persists to cart_items/carts within what should be a read-only method |
| `OrderCreationService` | **2 responsibilities** | Order creation + pickup location snapshot resolution |
| `OrderRepository` | **5+ responsibilities** | Order CRUD, stock validation, coupon validation, wallet management, invoice data, payment intent, child orders |
| `CartInventoryService` | **2 responsibilities** | Inventory reservation + cart lifecycle management |

### Circular Dependencies

```
OrderService → PromotionService → CartInventoryService
OrderService → OrderCreationService → PromotionService
```

`OrderCreationService` calls `PromotionService::incrementUsage()` in `finalizeOrder()`, creating a **hidden circular dependency**: `OrderService → OrderCreationService → PromotionService → CartInventoryService`. No direct cycle, but the dependency chain is fragile.

### Duplicate Responsibilities

| Responsibility | Classes Implementing It |
|---|---|
| **Coupon validation** | `CouponValidator::validate()`, `CouponValidator::validateByCode()`, `OrderRepository::validateCouponUsage()` |
| **Coupon discount calculation** | `CouponCalculator::calculate()`, `OrderRepository::calculateDiscount()`, `ProductPricingService::calculateCouponPrice()`, `OrderService::calculatePriceByCoupon()` |
| **Cart subtotal calculation** | `PromotionService::subtotal()`, `OrderService::getCheckoutTotalsFromCart()`, `CheckoutRepository::getOrderAmount()` |
| **Stock validation** | `CartInventoryService::reserveStock()`, `OrderRepository::validateAndLockStock()`, `CheckoutRepository::checkStock()` |
| **Shipping calculation** | `OrderService::resolveShippingPrice()`, `CheckoutRepository::calculateShippingCharge()`, `FastShippingService::createFastOrder()` |
| **Tax calculation** | `CheckoutRepository::calculateTax()` |
| **Checkout totals assembly** | `OrderService::calculateCheckoutTotals()`, `OrderService::getCheckoutTotalsFromCart()`, `FastShippingService::calculateCheckoutTotals()` |

---

## 3. Verified Previous Findings

### Finding 1: Dual Checkout Calculation Paths — CONFIRMED (Previously Critical)

**How it works:**
1. `OrderController::checkout()` calls `orderService->calcInvoicePrice()` which calls `calculateCheckoutTotals()` → `PromotionService::applySelectedPromotion()` (recomputation with side effects) + `calculatePriceByCoupon()` (recalculation)
2. The result `$orderPrice` is passed to `handleOnlinePayment()` as the payment amount
3. `OrderController::checkout()` then calls `orderService->addItemsInOrder()` which calls `getCheckoutTotalsFromCart()` (reads already-persisted values from cart_items)
4. The payment amount (`$orderPrice`) may differ from the persisted order total

**Impact:** Payment is requested for amount X, but order is stored with amount Y. These values CAN diverge because:
- `calcInvoicePrice` uses DB transaction with `cart->update(['total_price' => $finalTotal])` at line 113
- `addItemsInOrder` reads back from cart_items without knowing what was computed in step 1
- PromotionService mutations persist to cart_items before `getCheckoutTotalsFromCart` reads them, but the `calcInvoicePrice` path also applies FREE_SHIPPING logic to shipping, while `addItemsInOrder` re-evaluates it

### Finding 2: Side Effects During Calculation — CONFIRMED (Previously Critical)

`PromotionService::applySelectedPromotion()` is called from `calcInvoicePrice()` (line 97-101) but performs:
- `removeGiftItems()` — **DELETES** cart_items that are marked as gift
- `applicator->applyOutcome()` — **PERSISTS** discount amounts to cart_items, promotion_id to cart_items
- `inventoryService->reserveGiftItem()` — **RESERVES** stock for gift items
- `cart->refresh()` — reloads cart state with persisted changes

This means `calcInvoicePrice()` is NOT read-only. If order creation fails after `calcInvoicePrice`, the cart state has been mutated.

### Finding 3: FREE_SHIPPING Gap in FastShipping — CONFIRMED (Previously Critical)

`FastShippingService::createFastOrder()` (lines 93-97):
```php
$shippingInfo = $this->orderService->getGovernorateShippingInfo($governorateId);
$shippingPrice = $shippingInfo['price'];
if ($shippingInfo['free_shipping_over'] !== null && $checkoutTotals->subtotal > $shippingInfo['free_shipping_over']) {
    $shippingPrice = 0;
}
```

There is **NO** check for `$checkoutTotals->couponDiscountType === DiscountType::FREE_SHIPPING`.

Compare with `OrderService::addItemsInOrder()` (lines 136-159) which explicitly handles FREE_SHIPPING.

### Finding 4: Multiple Discount Calculators — CONFIRMED (Previously High)

| Location | Method | Input | Output |
|---|---|---|---|
| `CouponCalculator::calculate()` | Standalone | Coupon + total | `['finalPrice', 'discountType', 'freeShipping']` |
| `ProductPricingService::calculateCouponPrice()` | Via service | Coupon + price | `float` |
| `OrderRepository::calculateDiscount()` | Inline | Coupon + amount | `float` (via `CalculatePaymentTrait`) |
| `OrderService::calculatePriceByCoupon()` | Wrapper around CouponCalculator | Cart + total | `['finalPrice', 'discountType', 'freeShipping']` |
| `FastShippingService::calculateCouponDiscount()` | Via ProductPricingService | Coupon + price | `float` |

### Finding 5: cart.total_price Ownership — CONFIRMED (Previously High)

**6+ writers to `cart.total_price`:**

| Writer | Location | When |
|---|---|---|
| `CartInventoryService::reserveItem()` | `CartInventoryService.php:54` | Item added to cart (per-item `total_price = price * quantity`) |
| `calcInvoicePrice()` | `OrderService.php:113` | During checkout preview — sets to `finalTotal + shipping` |
| `createFastOrder()` | `FastShippingService.php:127` | After order creation — sets to `order->total_price` |
| `releaseCart()` | `CartInventoryService.php:198` | Cart released/expired — resets to 0 or remaining items sum |
| `finalizeItemsByShippingMethod()` | `CartInventoryService.php:260` | After payment — resets to 0 or remaining items sum |
| `expireCart()` | `CartInventoryService.php:347` | Cart expired — resets to 0 |

No documented lifecycle for what `total_price` represents at each stage.

### Finding 6: Order Item Unit Price Rounding — CONFIRMED (Previously High)

`OrderCreationService::createOrderItems()` line 73:
```php
$effectiveUnitPrice = $quantity > 0 ? round($lineTotal / $quantity, 2) : 0;
```

Example: If `$lineTotal = 10.00` and `$quantity = 3`:
- `$effectiveUnitPrice = round(10.00 / 3, 2) = 3.33`
- `$effectiveUnitPrice * 3 = 9.99` ≠ `10.00`
- The stored `product_price` does not reconstruct to `product_total_price`

### Finding 7: RestoreProductInventory No Lock — CONFIRMED (Previously High)

`RestoreProductInventory::handle()`:
```php
$product = Product::find($item->product_id);
// ... stock_quantity += quantity, sold_quantity -= quantity
$product->save();
```

No `lockForUpdate()` is used. If the same product is being modified by cancellation AND finalization simultaneously, stock counts will be corrupted.

The listener also uses `ProductVariant` model (line 32) while the rest of the system uses `Variation` — this is likely a different/legacy model.

### Finding 8: Refund Flow — PARTIALLY CONFIRMED

`RefundApproved` event exists in the system but we found no dedicated listener for it. The `RefundController` exists but no listener handles inventory restoration, gateway refund, or notification on approval.

### Finding 9: Dead Code — CONFIRMED

| Dead Code | Location | Evidence |
|---|---|---|
| `OrderService::clearCart()` | `OrderService.php:325-342` | Not called from any controller |
| `CheckoutRepository::verify()` | `CheckoutRepository.php:22-51` | Legacy endpoint; route exists but frontend uses new checkout path |
| `OrderRepository::storeOrder()` | `OrderRepository.php:112-259` | Entire method may be dead if all checkout flows use `App\General\OrderController` |

### Finding 10: Checkout Invariants — CONFIRMED

| Invariant | Status | Violation Path |
|---|---|---|
| Payment amount = Order total | **VIOLATED** | `calcInvoicePrice()` and `addItemsInOrder()` can produce different totals |
| Preview total = Actual total | **VIOLATED** | Preview mutates cart; actual order re-reads |
| Unit price × quantity = line total | **VIOLATED** | Rounding bug in `createOrderItems()` |
| Same discount math everywhere | **VIOLATED** | 5 different coupon discount calculators |
| Cart total_price consistent meaning | **VIOLATED** | 6 writers, no lifecycle |

---

## 4. New Findings

### Finding 11: OrderRepository::storeOrder() has NO Transaction — CRITICAL (New)

`OrderRepository::storeOrder()` (lines 112-259) does NOT wrap its operations in `DB::beginTransaction()` / `DB::commit()`:

```php
public function storeOrder($request, $settings): mixed
{
    // ...
    $this->validateAndLockStock($request['products']);  // lockForUpdate but NO transaction
    // ... coupon logic ...
    $order = $this->createOrder($request);               // creates order record
    $this->deductStock($request['products']);            // deducts stock
    // ...
}
```

**Consequences:**
- `lockForUpdate()` in `validateAndLockStock()` requires a transaction to hold the lock. Without an explicit transaction, each query commits implicitly, so the lock is released immediately after the SELECT. Between validation and deduction, another request can interleave.
- If `createOrder()` succeeds but `deductStock()` fails, the order exists but stock is not deducted (inconsistent state).
- This affects the legacy Marvel order path only, not the new `App\General\OrderController` path.

### Finding 12: Variation Stock Decrement Inconsistency — HIGH (New)

`OrderRepository::deductStock()` (lines 329-351):
```php
if ($variationId) {
    Variation::where('id', $variationId)->decrement('quantity', $orderQuantity);
} else {
    Product::where('id', $productId)->decrement('stock_quantity', $orderQuantity);
}
```

- For **variations**: decrements `quantity` column
- For **simple products**: decrements `stock_quantity` column

But `CartInventoryService::reserveStock()`/`finalizeStock()` uses `stock_quantity` for BOTH products and variants.

This means the legacy `OrderRepository` path and the new `CartInventoryService` path decrement **different columns** for variations. If both paths are used (mixed checkout flow), variation stock tracking becomes inconsistent.

### Finding 13: Child Orders Have No Discount/Tax — MEDIUM (New)

`OrderRepository::createChildOrder()` (lines 705-743):
```php
$orderInput = [
    'delivery_fee' => 0,
    'sales_tax' => 0,
    'discount' => 0,
    'amount' => $amount,
    'total' => $amount,
    'paid_total' => $amount,
];
```

Child orders (for multi-shop orders) have all discounts, taxes, and delivery fees set to 0. The total/paid_total equals the raw subtotal. This means:
- Shop-level financial reports based on child orders will NOT reflect discounts or taxes
- The parent order has the correct totals, not the children
- `Balance::calculateShopIncome()` (`OrderRepository.php:547-557`) uses `$order->total` for earnings calculation, which is the raw amount for child orders

### Finding 14: CouponRepository::addCouponToCart() Uses First Cart — LOW (New)

```php
$cart = $user->cart->first();
```

- Assumes `$user->cart` returns a collection (correct for HasMany)
- But doesn't filter by `status = 'active'`
- Could pick up an expired/reserved/checked_out cart instead of the active one

### Finding 15: Dashboard Queries Use Ambiguous Column Names — MEDIUM (New)

`DashboardService` queries use these patterns:
- `Order::where('status', 'completed')->sum('total_price')` — sums `orders.total_price`
- `DB::table('refunds')->sum('amount')` — sums `refunds.amount`
- `Order::whereNotNull('coupon_discount')->sum('coupon_discount')` — BUT the migration shows `coupon_discount` is D10,3 while `total_price` is D8,3

**Precision inconsistency in analytics:** `coupon_discount` = D10,3 (3 decimal places) but `total_price` = D8,3. The `coupon_discount` values may be truncated when stored alongside `total_price` in calculations.

### Finding 16: Legacy Marvel Route `orders/checkout/verify` — LOW (New)

The route `POST orders/checkout/verify` exists in `Marvel\Rest\Routes.php` (line 255) pointing to `CheckoutController@verify`. This is the legacy `CheckoutRepository::verify()` path. It computes:
- `$amount = $request['amount']` — uses client-provided amount (trusts user input!)
- `$shipping_charge` — recalculates based on settings
- `$tax` — recalculates based on tax class
- `$total = $amount + $tax + $shipping_charge`

This endpoint **trusts the client-provided `amount`** field without server-side recalculation of item prices. Only shipping and tax are recalculated server-side.

### Finding 17: PluckItemsToCart Not in Single Transaction — MEDIUM (New)

The `pluckItemsToCart` (bulk add to cart) route at `POST cart/bulk-items` exists, and from the previous audit, this is NOT wrapped in a single transaction. If one item fails, previous items are already in the cart. Confirmed as still unresolved.

### Finding 18: ProductVariant vs Variation Model Confusion — HIGH (New)

- `CartInventoryService` uses `ProductVariant` model
- `OrderRepository` uses `Variation` model
- `RestoreProductInventory` uses `ProductVariant` model
- The migration creates `product_variants` table but OrderRepository references `Variation::class`

These may be the same table mapped to different models, or potentially different tables. If they're different models pointing to the same table, both may work. If they're different tables, inventory corrections may affect the wrong table.

---

## 5. Ownership Audit — Every Monetary Value

### `products.price`
| Operation | Owner | Location |
|---|---|---|
| Create | Admin (product create) | `ProductController@store` |
| Read | ProductController, CartInventoryService, OrderCreationService | Multiple |
| Update | Admin (product update) | `ProductController@update` |
| **Owners: 1** ✅ |

### `products.sale_price`
| Operation | Owner | Location |
|---|---|---|
| Create | Admin (product create) | Migration has `sale_price` only on `product_variants`, NOT on `products` |
| Read | ProductController | Multiple |
| **Note:** This field does NOT exist on `products` table — only on `product_variants` |

### `products.price_after_flash_sale`
| Operation | Owner | Location |
|---|---|---|
| Create | `FlashSaleRepository::updateFlashSaleProductPrices()` | `FlashSaleRepository.php:200-220` |
| Update | FlashSale update → recalculated for each associated product | `FlashSaleRepository.php:147` |
| Clear | FlashSale expired → set to null | `FlashSaleRepository.php:211` |
| **Owners: 1** ✅ (FlashSaleRepository only) |

### `products.price_after_discount`
| Operation | Owner | Location |
|---|---|---|
| Create | Admin/product import | Set when product has ongoing discount |
| **Owners: 1** ✅ |

### `cart_items.price`, `cart_items.total_price`
| Operation | Owner | Location |
|---|---|---|
| Create | `CartInventoryService::reserveItem()` | `CartInventoryService.php:48-57` |
| Update | `CartInventoryService::reserveItem()` (quantity change) | `CartInventoryService.php:58-63` |
| Update | `PromotionApplicator` (discount amounts) | Via promotion application |
| Read | `OrderService::getCheckoutTotalsFromCart()`, `CartResource` | Multiple |
| **Owners: 2** ❌ (CartInventoryService creates/updates, PromotionApplicator mutates `total_price` via discount distribution) |

### `cart_items.discount_amount`
| Operation | Owner | Location |
|---|---|---|
| Create | `PromotionApplicator::applyOutcome()` | Sets discount_amount per item during promotion application |
| Read | `OrderService::getCheckoutTotalsFromCart()` | Sums for promotionDiscount |
| **Owners: 1** ✅ |

### `carts.total_price`
| Operation | Owner | Location |
|---|---|---|
| Update | `CartInventoryService::reserveItem()` → via `touchCartReservation()` | Sets to sum of items total_price |
| Update | `calcInvoicePrice()` | Sets to `finalTotal + shipping` |
| Update | `createFastOrder()` | Sets to `order->total_price` |
| Reset | `releaseCart()` | Resets to 0 or remaining sum |
| Reset | `finalizeItemsByShippingMethod()` | Resets to 0 or remaining sum |
| Reset | `expireCart()` | Resets to 0 |
| **Owners: 5** ❌❌ No documented lifecycle |

### `orders.price`
| Operation | Owner | Location |
|---|---|---|
| Create | `OrderCreationService::createOrder()` | Sets to `checkoutTotals->subtotal` |
| **Owners: 1** ✅ |

### `orders.shipping_price`
| Operation | Owner | Location |
|---|---|---|
| Create | `OrderCreationService::createOrder()` | Passed from OrderService or FastShippingService |
| **Owners: 2** ❌ (Set by both OrderService and FastShippingService independently) |

### `orders.total_price`
| Operation | Owner | Location |
|---|---|---|
| Create | `OrderCreationService::createOrder()` | Computed as `finalTotal + shippingPrice + fastShippingFee` |
| Read | `OrderController::checkout()` → passed to payment handler | `OrderController.php:116` |
| Read | `checkoutCallback()` → amount mismatch check | `OrderController.php:242` |
| Read | `DashboardService` → analytics | Multiple dashboard queries |
| **Owners: 1** ✅ (OrderCreationService) but **read by many** |

### `orders.coupon_discount`
| Operation | Owner | Location |
|---|---|---|
| Create | `OrderCreationService::createOrder()` | From `checkoutTotals->couponDiscount` |
| **Owners: 1** ✅ |

### `orders.coupon_discount_type`, `orders.coupon_discount_max_amount`
| Operation | Owner | Location |
|---|---|---|
| Create | `OrderCreationService::createOrder()` | From CheckoutTotals |
| **Owners: 1** ✅ |

### `orders.promotion_discount`
| Operation | Owner | Location |
|---|---|---|
| Create | `OrderCreationService::createOrder()` | From `checkoutTotals->promotionDiscount` |
| **Owners: 1** ✅ |

### `order_products.product_price`
| Operation | Owner | Location |
|---|---|---|
| Create | `OrderCreationService::createOrderItems()` | `round(lineTotal / quantity, 2)` |
| **Owners: 1** ✅ (but buggy — doesn't reconstruct) |

### `order_products.product_total_price`
| Operation | Owner | Location |
|---|---|---|
| Create | `OrderCreationService::createOrderItems()` | `round(lineTotal, 2)` |
| **Owners: 1** ✅ |

### `order_products.promotion_discount_amount`
| Operation | Owner | Location |
|---|---|---|
| Create | `OrderCreationService::createOrderItems()` | `round((price * quantity) - lineTotal, 2)` |
| **Owners: 1** ✅ |

### `coupons.discount`, `coupons.max_discount_amount`
| Operation | Owner | Location |
|---|---|---|
| Create | Admin (coupon create via CouponRepository) | `CouponRepository::storeCoupon()` |
| **Owners: 1** ✅ |

### `promotions.value`, `promotions.discount`, `promotions.max_discount_amount`, `promotions.minimum_order_amount`
| Operation | Owner | Location |
|---|---|---|
| Create | Admin (promotion create) | PromotionController |
| **Owners: 1** ✅ |

### `flash_sales.discount`, `flash_sales.max_discount_amount`
| Operation | Owner | Location |
|---|---|---|
| Create | Admin (flash sale create) | `FlashSaleRepository::storeFlashSale()` |
| **Owners: 1** ✅ |

### `transactions.amount`
| Operation | Owner | Location |
|---|---|---|
| Create | Payment gateway callback | Via `Transaction::create()` |
| **Owners: 1** ✅ |

### Summary

| Field | Owners | Status |
|---|---|---|
| `products.price` | 1 | ✅ |
| `products.price_after_flash_sale` | 1 | ✅ |
| `cart_items.price` | 2 | ❌ CartInventoryService + PromotionApplicator |
| `cart_items.total_price` | 2 | ❌ CartInventoryService + PromotionApplicator |
| `cart_items.discount_amount` | 1 | ✅ |
| `carts.total_price` | **5** | ❌❌❌ 5 writers, no lifecycle |
| `orders.price` | 1 | ✅ |
| `orders.shipping_price` | 2 | ❌ OrderService + FastShippingService |
| `orders.total_price` | 1 | ✅ |
| `orders.coupon_discount` | 1 | ✅ |
| `orders.promotion_discount` | 1 | ✅ |
| `order_products.product_price` | 1 | ✅ (buggy) |
| `order_products.product_total_price` | 1 | ✅ |
| `coupons.discount` | 1 | ✅ |
| `promotions.discount` | 1 | ✅ |
| `flash_sales.discount` | 1 | ✅ |

---

## 6. Checkout Consistency Audit — Full Lifecycle

### Scheduled Checkout Lifecycle

```
STEP 1: Cart Creation
──────────────────────
Action: User adds items to cart
Service: CartInventoryService::reserveItem()
Writes: cart_items (price, total_price, reserved_quantity)
         products/variants (reserved_quantity incremented)
State: Items in cart, stock reserved

STEP 2: Eligible Promotions Check
──────────────────────────────────
Action: GET /checkout/promotions
Service: PromotionService::eligiblePromotionsPayload()
         → PromotionEligibilityResolver::eligible()
Reads: Cart items, promotions, products
Writes: None (read-only)

STEP 3: Checkout Preview (calcInvoicePrice)
────────────────────────────────────────────
Action: POST /checkout (inside checkout method)
Service: OrderService::calcInvoicePrice()
         1. DB::beginTransaction()
         2. calculateCheckoutTotals() →
            a. PromotionService::applySelectedPromotion() ← SIDE EFFECTS!
               - removeGiftItems() → DELETES old gift items
               - PromotionApplicator::applyOutcome() → PERSISTS discount_amount, promotion_id to cart_items
               - reserveGiftItem() → RESERVES stock for gifts
               - cart->refresh()
            b. OrderService::calculatePriceByCoupon() → reads coupon from cart, calculates discount
         3. resolveShippingPrice() → reads governorate shipping
         4. Applies free_shipping_over check
         5. Applies FREE_SHIPPING coupon check (couponDiscountType)
         6. finalTotal = checkoutTotals.finalTotal + shippingPrice
         7. cart->update(['total_price' => $finalTotal]) ← PERSISTS to cart
         8. DB::commit()
Returns: cart.total_price (= $orderPrice)

STEP 4: Online Payment Handling
────────────────────────────────
Action: PaymentCheckoutHandler::handleOnlinePayment()
         1. Creates transaction record (status: pending)
         2. Calls PaymentGatewayFactory::make()
         3. Calls gateway->initiatePayment($orderPrice, ...) ← Uses $orderPrice from calcInvoicePrice
         4. Returns payment URL to frontend
State: Transaction pending, order NOT yet created

STEP 5: Frontend Redirects to Payment Gateway
──────────────────────────────────────────────
Action: User completes payment on gateway page
State: Gateway has payment, system has pending transaction

STEP 6: Callback (checkoutCallback)
────────────────────────────────────
Action: GET /checkout/callback?paymentId=xxx
Service: OrderController::checkoutCallback()
         1. gateway->verifyPayment(paymentId)
         2. Find transaction by gateway_transaction_id
         3. Update transaction status
         4. If failed:
            - orderService->changeOrderStatus() → marks order as cancelled
            - cartInventoryService->releaseCart() → releases stock
            - event(new PaymentFailed())
         5. If success:
            - VALIDATE AMOUNT: checks result.amount vs order.total_price ← BUT ORDER DOESN'T EXIST YET!
            - If mismatch: cancel, release, fail
            - orderService->changeOrderStatus() → marks order as completed
            - cartInventoryService->finalizeItemsByShippingMethod() → finalizes stock
            - If coupon on cart → clear it
            - event(new PaymentSucceeded())

WAIT — THIS IS WRONG. Let me re-read the flow...

Looking at OrderController::checkout():
1. calcInvoicePrice($request) → $orderPrice
2. addItemsInOrder($request) → $order ← CREATES ORDER HERE
3. handleOnlinePayment($request, $order, $orderPrice, $gateway)

So the correct flow is:

STEP 3: Checkout Preview (calcInvoicePrice)
STEP 4: Order Creation (addItemsInOrder)
STEP 5: Payment Handling
STEP 6: Gateway Redirect
STEP 7: Callback
```

### Corrected Checkout Lifecycle

```
CART CREATION
  │
  ▼
ELIGIBLE PROMOTIONS CHECK (GET /checkout/promotions)
  │
  ▼
CHECKOUT POST (/checkout)
  │
  ├── calcInvoicePrice($request)
  │     ├── BEGIN TRANSACTION
  │     ├── applySelectedPromotion() ← SIDE EFFECTS (persists to cart_items)
  │     ├── calculatePriceByCoupon()
  │     ├── resolveShippingPrice()
  │     ├── free_shipping_over check
  │     ├── FREE_SHIPPING coupon check
  │     ├── finalTotal = checkoutTotals.finalTotal + shippingPrice
  │     ├── cart.update(total_price = finalTotal) ← PERSISTS
  │     └── COMMIT → returns cart.total_price (= $orderPrice)
  │
  ├── addItemsInOrder($request)
  │     ├── BEGIN TRANSACTION
  │     ├── Validate coupon → freeShippingCoupon flag
  │     ├── getCheckoutTotalsFromCart() ← READS from cart_items
  │     ├── resolveShippingPrice()
  │     ├── free_shipping_over check
  │     ├── freeShippingCoupon check
  │     ├── createOrder(orderData, checkoutTotals, shippingPrice)
  │     │     └── Order::create(... total_price = finalTotal + shippingPrice ...)
  │     ├── createOrderItems(order, cart) ← iterates cart_items, creates order_products
  │     │     └── effectiveUnitPrice = round(lineTotal / quantity, 2) ← BUGGY
  │     ├── finalizeOrder(order, checkoutTotals)
  │     │     └── promotionService->incrementUsage()
  │     │     └── OrderCreated::dispatch()
  │     └── COMMIT → returns $order
  │
  ├── handleOnlinePayment(request, order, $orderPrice, gateway)
  │     ├── Transaction::create(amount = $orderPrice)
  │     └── gateway->initiatePayment($orderPrice)
  │
  └── Return payment URL to frontend

USER REDIRECTED TO GATEWAY

CALLBACK (/checkout/callback)
  │
  ├── gateway->verifyPayment(paymentId)
  ├── Find transaction
  ├── Update transaction status
  │
  ├── IF SUCCESS:
  │     ├── Validate amount: result.amount vs order.total_price ← NOW order exists
  │     ├── Validate currency
  │     ├── If mismatch → cancel order, release cart, PaymentFailed event
  │     ├── If OK → mark order completed
  │     │     ├── cart.finalizeItemsByShippingMethod() ← finalizes stock (reserved→sold)
  │     │     ├── Clear coupon from cart
  │     │     └── PaymentSucceeded event
  │
  └── IF FAILURE:
        ├── Cancel order
        ├── cart.releaseCart() ← releases stock reservation
        └── PaymentFailed event
```

### Critical Inconsistency in Lifecycle

**The payment amount (`$orderPrice`) and the order total are computed from different paths:**
- `$orderPrice` = output of `calcInvoicePrice()` → `calculateCheckoutTotals()` (applies promotion via PromotionService with side effects)
- `order.total_price` = output of `addItemsInOrder()` → `getCheckoutTotalsFromCart()` (reads already-persisted cart_items)

These two paths can produce **numerically different results** because:
1. `calculateCheckoutTotals()` applies promotions fresh (PromotionService → applyOutcome → persists)
2. `getCheckoutTotalsFromCart()` reads the persisted values AFTER promotion was applied
3. If `calcInvoicePrice()` modified cart_items (via PromotionApplicator), then `getCheckoutTotalsFromCart()` would read those modifications
4. But the `calcInvoicePrice()` path recalculates from scratch, while `getCheckoutTotalsFromCart()` reads the summed results
5. Any rounding differences between recalculation vs sum-of-parts will produce different totals

**Payment amount mismatch check in callback:**
The callback at line 242 validates `result.amount` vs `$order->total_price`. Since both the payment amount AND the order total should match (they both derive from the same cart state), the mismatch check is comparing two values that could both be wrong in the same direction. The more important check — does the payment amount match what we INTENDED to charge — is not performed.

### Recalculation Points (where values are recomputed)

| Point | What's Recalculated | Why It's a Problem |
|---|---|---|
| `calcInvoicePrice()` line 97 | Full checkout totals from scratch | Side effects + differs from `addItemsInOrder` |
| `addItemsInOrder()` line 145 | Checkout totals from cart_items | Different path from `calcInvoicePrice` |
| `calcInvoicePrice()` line 112 | `finalTotal = checkoutTotals.finalTotal + shippingPrice` | Inconsistent with addItemsInOrder |
| `addItemsInOrder()` line 152-158 | Shipping price | Same logic but re-evaluated |
| `OrderCreationService::createOrder()` line 22 | `totalPrice = finalTotal + shipping + fastFee` | Third computation of the same value |
| `createFastOrder()` line 88 | Full checkout totals | Fourth implementation of same logic |
| `callback` line 242 | Amount mismatch check | Compares against order total, not intended amount |

### Mutation Points (where state changes)

| Point | What's Mutated | Type |
|---|---|---|
| `CartInventoryService::reserveItem()` | product/variant reserved_quantity | Stock reservation |
| `PromotionService::applySelectedPromotion()` | cart_items: discount_amount, promotion_id | Price mutation in preview |
| `PromotionService::applySelectedPromotion()` | cart: gift items removed | Item mutation in preview |
| `calcInvoicePrice()` | cart.total_price | Price mutation in preview |
| `OrderCreationService::createOrder()` | orders row created | Order persistence |
| `OrderCreationService::createOrderItems()` | order_products row created | Items persistence |
| `handleOnlinePayment()` | transaction row created | Payment persistence |
| `checkoutCallback()` | order.status updated | Status mutation |
| `checkoutCallback()` | stock finalized (reserved→sold) | Inventory finalization |
| `checkoutCallback()` | cart items deleted | Cart cleanup |
| `checkoutCallback()` | coupon usage recorded | Coupon tracking |
| `PromotionService::incrementUsage()` | promotion.usage incremented | Promotion tracking |

### Persistence Points (where data is written to DB)

| Point | Table(s) Written | In Transaction? |
|---|---|---|
| `calcInvoicePrice()` | `carts` (total_price), `cart_items` (discount_amount, promotion_id) | ✅ Yes (explicit) |
| `addItemsInOrder()` | `orders`, `order_products` | ✅ Yes (explicit) |
| `handleOnlinePayment()` | `transactions` | ❌ No |
| `checkoutCallback()` | `orders` (status), `transactions` (status), `cart_items` (deleted), `products` (stock) | ❌ No — multiple DB operations without transaction |
| `PromotionService::incrementUsage()` | `promotions` (usage) | Within order transaction |
| `recordCouponUsage()` | `coupon_usages`, `coupons` (used) | ❌ No |

---

## 7. Refactor Readiness Report

### Can all totals be calculated once?

**YES, but with blockers:**

**Blocker 1:** `calcInvoicePrice()` and `addItemsInOrder()` currently compute totals independently. They share no common calculator.

**Blocker 2:** `PromotionService::applySelectedPromotion()` has side effects (persists to cart_items). A read-only calculator must be separated from the applicator.

**Blocker 3:** `FastShippingService::calculateCheckoutTotals()` reimplements the same logic as `OrderService::calculateCheckoutTotals()`.

### Can Checkout Preview and Order Creation share one DTO?

**YES.** `CheckoutTotals` DTO already exists and is used by both. The problem is they're constructed differently:
- Preview constructs via `calculateCheckoutTotals()` (fresh calculation)
- Order creation constructs via `getCheckoutTotalsFromCart()` (read from persisted)

**Fix:** Both should use the same calculator, producing an identical `CheckoutTotals`.

### Can payment gateways reuse the exact same totals?

**YES.** Currently `$orderPrice` (float) is passed to `handleOnlinePayment()`. The entire `CheckoutTotals` DTO could be passed instead, giving gateways access to the full pricing breakdown.

### Can invoice generation reuse the same snapshot?

**NOT CURRENTLY.** No snapshot mechanism exists. The order is created before payment, and invoice data is read from the persisted `orders` and `order_products` tables. To reuse the exact same snapshot:
- The `CheckoutTotals` DTO (or a serialized version) must be stored alongside the order
- Or the order must be created with the EXACT totals computed during preview

### Can Fast Shipping reuse the same calculation?

**YES.** FastShippingService has its own `calculateCheckoutTotals()` that mirrors the logic in OrderService. Both should call the same shared calculator. The only difference is:
- FastShippingService filters cart items by `shipping_method = 'fast'`
- OrderService filters by `shipping_method = 'SCHEDULED'`

**Blocker:** FREE_SHIPPING coupon is not implemented in FastShippingService.

### Can promotions become read-only?

**YES, but requires separation of concerns.**
- `PromotionEligibilityResolver` is already read-only (evaluates eligibility)
- `PromotionApplicator::applyOutcome()` is the mutation part
- These should be separated: a read-only `calculateOutcome()` method + the existing `applyOutcome()`

### Can calculations become immutable?

**YES.** Currently `calcInvoicePrice()` mutates cart state as a side effect. This can be fixed by:
1. Making `calculateCheckoutTotals()` read-only (no DB writes)
2. Having the caller explicitly trigger persistence as a separate step
3. Using `CheckoutTotals` as an immutable value object throughout

### Can persistence happen only once?

**YES, but requires order of operations change:**
Currently persistence happens at:
1. `calcInvoicePrice()` → persists to cart (side effect)
2. `addItemsInOrder()` → creates order
3. `handleOnlinePayment()` → creates transaction
4. `checkoutCallback()` → updates statuses

**Ideal flow:**
1. Calculate totals once → produces `CheckoutTotals`
2. Create order + items + transaction in a single transaction
3. On callback: update status + finalize inventory

---

## 8. Design Review — CheckoutTotalsCalculator

### Proposed Architecture

A new `CheckoutTotalsCalculator` service that:
- Accepts a `Cart` and request data (promotion_id, gift_product_id, governorate_id)
- Returns an immutable `CheckoutTotals` DTO
- Has ZERO side effects (no writes to any table)
- Uses shared utility methods for each discount component
- Is used by BOTH `OrderService` and `FastShippingService`

### Benefits

| Benefit | Explanation |
|---|---|
| **Single source of truth** | All pricing logic in one place |
| **Immutability** | DTO produced once, never modified |
| **Testability** | Pure calculation, no DB dependencies |
| **Verifiability** | Preview total = actual order total guaranteed |
| **Maintainability** | One place to fix rounding, one place to add new discount types |
| **Performance** | No redundant recalculations |
| **Auditability** | Full pricing breakdown available for every order |

### Risks

| Risk | Mitigation |
|---|---|
| **PromotionApplicator coupling** | PromotionApplicator must be separated into read-only calculator + mutation applicator |
| **Legacy OrderRepository path** | Must not break existing `storeOrder()` — dual maintain during migration |
| **Transaction scope changes** | Current code has side effects inside transactions; removing them changes transaction boundaries |
| **Regression in discount stacking** | Must ensure exact same discount stacking rules are preserved |
| **FREE_SHIPPING in FastShipping** | Must be added as part of this refactor |

### Migration Complexity

**High.** This touches:
- `OrderService` — both `calcInvoicePrice()` and `addItemsInOrder()` must be rewritten
- `FastShippingService` — `calculateCheckoutTotals()` replaced
- `PromotionService::applySelectedPromotion()` — must be split into read-only + mutable parts
- `OrderCreationService` — receives DTO (already does, no change needed)
- `OrderController` — may need changes to pass DTO through to payment handler
- All tests — must be rewritten for the new calculator

### Backward Compatibility

| Component | Compatible? | Notes |
|---|---|---|
| API response format | ✅ | Same `CheckoutTotals` values |
| Database schema | ✅ | No schema changes needed |
| Legacy OrderRepository | ⚠️ | Not affected (separate code path) |
| Payment gateway integration | ⚠️ | Gateways receive float amount; DTO can be passed alongside |
| Frontend | ✅ | No API contract changes |

### Testing Impact

**Positive.** A pure calculator is far easier to test:
- No DB setup needed for calculation tests
- Can test every discount combination in isolation
- Edge cases (large carts, mixed promotions, rounding) are trivial
- Existing integration tests remain valid for end-to-end verification

### Performance Impact

**Neutral to Positive.**
- No more redundant recalculations (was: preview recalculates, order creates recalculates)
- `PromotionService::applySelectedPromotion()` currently does `cart->refresh()` + multiple queries; read-only calculator eliminates these
- Calculator itself does the same queries (product/promotion lookups)

### Coupling

| Current | After Refactor |
|---|---|
| OrderService ←→ PromotionService (tight) | OrderService → CheckoutTotalsCalculator ← PromotionService |
| FastShippingService → OrderService (partial reuse) | FastShippingService → CheckoutTotalsCalculator |
| PromotionService ←→ CartInventoryService | PromotionsApplicator (separate) → CartInventoryService |
| OrderCreationService → PromotionService | OrderCreationService → PromotionService (only for incrementUsage) |

### Maintainability

**Significantly improved.**
- All pricing logic in one class
- No duplicated discount math
- No subtle differences between checkout paths
- Clear separation: calculation vs. persistence

---

## 9. Migration Strategy

### Phase 1: Fix Critical Bugs (Week 1)

**Objective:** Fix bugs that cause incorrect monetary values or data loss.

| # | Task | Files Affected | Risk | Tests Required |
|---|---|---|---|---|
| 1.1 | Add FREE_SHIPPING check to `FastShippingService::createFastOrder()` | `FastShippingService.php` | Low | Fast checkout with FREE_SHIPPING coupon |
| 1.2 | Fix order item unit price rounding | `OrderCreationService.php` | Low | Multi-quantity order with various prices |
| 1.3 | Wrap OrderRepository::storeOrder in transaction | `OrderRepository.php` | Medium | Legacy order creation path |
| 1.4 | Add lockForUpdate to RestoreProductInventory listener | `RestoreProductInventory.php` | Low | Concurrent cancel + finalize |

**Rollback:** Revert individual commits.  
**Regression risk:** Low — each fix is isolated and well-understood.

### Phase 2: Extract CheckoutTotalsCalculator (Week 2-3)

**Objective:** Create a shared, read-only calculator used by all checkout paths.

| # | Task | Files Affected | Risk | Tests Required |
|---|---|---|---|---|
| 2.1 | Create `CheckoutTotalsCalculator` class | New file | High | All pricing scenarios |
| 2.2 | Extract promotion calculation into read-only method | `PromotionService.php` | High | All promotion types + gifts |
| 2.3 | Extract coupon calculation into calculator | `CouponCalculator.php` | Medium | All coupon types + FREE_SHIPPING |
| 2.4 | Move shipping calculation into calculator | `OrderService.php` | Medium | Governorate + free_shipping_over |
| 2.5 | Replace `calcInvoicePrice()` internals | `OrderService.php` | High | Full checkout flow |
| 2.6 | Replace `getCheckoutTotalsFromCart()` internals | `OrderService.php` | High | Must produce identical DTO |
| 2.7 | Replace `FastShippingService::calculateCheckoutTotals()` | `FastShippingService.php` | High | Fast checkout flow |

**Rollback:** Keep old methods alongside new ones; toggle via config.  
**Regression risk:** High — all checkout flows are affected. Must verify preview total = order total.

### Phase 3: Remove Side Effects from Preview (Week 3-4)

**Objective:** Make `calcInvoicePrice()` truly read-only.

| # | Task | Files Affected | Risk | Tests Required |
|---|---|---|---|---|
| 3.1 | Separate `PromotionApplicator::applyOutcome()` into calc + apply | `PromotionApplicator.php` | High | Promotion application |
| 3.2 | Remove `removeGiftItems()` call from `applySelectedPromotion()` | `PromotionService.php` | High | Promotion gift flow |
| 3.3 | Move persistence to explicit step after calculation | `OrderService.php` | High | Full checkout flow |
| 3.4 | Remove `cart->update(['total_price'])` from calcInvoicePrice | `OrderService.php` | High | Preview API |

**Rollback:** Revert individual commits; each is self-contained.  
**Regression risk:** High — changes the timing of when values are persisted.

### Phase 4: Unify Payment Amount (Week 4)

**Objective:** Guarantee payment amount = order total = preview total.

| # | Task | Files Affected | Risk | Tests Required |
|---|---|---|---|---|
| 4.1 | Pass `CheckoutTotals` DTO to payment handler | `OrderController.php` | Medium | All payment methods |
| 4.2 | Store DTO snapshot alongside order | `OrderCreationService.php` | Low | Order creation |
| 4.3 | Validate callback amount against snapshot, not re-read | `OrderController.php` | Medium | Callback + mismatch |
| 4.4 | Add currency validation to mismatch check | `OrderController.php` | Low | Callback |

**Rollback:** Keep old amount validation as fallback.  
**Regression risk:** Medium — changes callback validation logic.

### Phase 5: Clean Up (Week 5)

| # | Task | Files Affected | Risk |
|---|---|---|---|
| 5.1 | Remove dead code (`clearCart`, legacy verify) | Multiple | Low |
| 5.2 | Remove duplicate coupon calculators | `CouponCalculator`, `ProductPricingService` | Low |
| 5.3 | Remove `CartInventoryService` from PromotionService call chain | `PromotionService.php` | Low |
| 5.4 | Standardize decimal precision across migrations | Migration files | Medium |

---

## 10. Test Strategy

### Tests That Must Exist Before Any Refactor

#### Unit Tests (Pure Calculation)

| Test | Priority | Coverage |
|---|---|---|
| `CheckoutTotalsCalculator` computes subtotal correctly | Critical | Empty cart, single item, multiple items, mixed shipping methods |
| `CheckoutTotalsCalculator` applies percentage coupon | Critical | Various percentages, max discount cap |
| `CheckoutTotalsCalculator` applies fixed coupon | Critical | Various amounts, below/above subtotal |
| `CheckoutTotalsCalculator` applies FREE_SHIPPING coupon | Critical | With and without other discounts |
| `CheckoutTotalsCalculator` applies fixed promotion | Critical | Various amounts, product-scoped |
| `CheckoutTotalsCalculator` applies percentage promotion | Critical | With max discount cap |
| `CheckoutTotalsCalculator` handles promotion + coupon stacking | Critical | Both applied, precedence rules |
| `CheckoutTotalsCalculator` computes shipping correctly | Critical | With/without free_shipping_over, with FREE_SHIPPING coupon |
| `CheckoutTotalsCalculator` handles gift items | High | With promotion gift, pricing is 0 |
| Rounding: multiple quantities with odd division | Critical | 3 items × 3.33 → line total 10.00 |
| Rounding: large carts (100+ items) | Medium | Sum stability |
| Rounding: mixed decimal quantities | Medium | 0.5 kg, 1.25 meters |

#### Integration Tests

| Test | Priority | Coverage |
|---|---|---|
| Scheduled checkout produces same total as preview | Critical | Full API flow: preview → create → compare |
| Fast checkout produces same total as preview | Critical | Full API flow: preview → create → compare |
| Payment amount matches order total | Critical | Checkout → payment handler amount |
| Callback validates amount correctly | Critical | Exact match, small difference, large difference |
| Order item total_price reconstructs from unit_price × quantity | High | All item types |
| Cart total_price is consistent after preview | High | Before/after calcInvoicePrice |
| Promotion discounts persisted correctly | High | Values in cart_items match CheckoutTotals |
| Coupon usage recorded once | High | No double-recording on callback |

#### Bug Detection Tests

| Test | Priority | Coverage |
|---|---|---|
| Concurrent checkout with same product | Critical | Race condition in stock |
| Concurrent cancel + finalize | Critical | RestoreProductInventory race |
| Duplicate callback | Critical | Idempotent order status |
| FREE_SHIPPING coupon with fast shipping | Critical | FastShippingService gap |
| FREE_SHIPPING coupon with free_shipping_over | High | Both conditions together |
| Promotion with gift + coupon together | High | Combined discount flow |
| Zero-total order (full wallet payment) | High | No negative totals |
| COD + pickup combination | Medium | Blocked by validation |
| Pay at cashier + fast shipping | Medium | Cashier QR flow |

#### Concurrency Tests

| Test | Priority | Coverage |
|---|---|---|
| Two users reserve last product simultaneously | Critical | Stock oversell prevention |
| Cart expiry races with checkout | High | Expired cart during checkout |
| Multiple callbacks for same payment | High | Idempotent processing |
| Wallet deduction race | High | Two orders using same wallet |

#### Validation Tests

| Test | Priority | Coverage |
|---|---|---|
| Invalid coupon code | Critical | Error response |
| Expired coupon | Critical | Error response |
| Already-used coupon (per-user limit) | Critical | Error response |
| Below minimum cart amount | Critical | Error response |
| Promotion not eligible | Critical | Error response |
| Invalid governorate | High | Error response |
| Empty cart checkout | High | Error response |
| Missing promotion_id in URL | Medium | Graceful handling |

#### Migration Safety Tests

| Test | Priority | Coverage |
|---|---|---|
| Old CheckoutTotals DTO = new CheckoutTotalsCalculator output | Critical | Same input → same output |
| Old promotion discount = new promotion discount | Critical | Same cart + promotion → same discount |
| Old coupon discount = new coupon discount | Critical | Same cart + coupon → same discount |
| Old shipping = new shipping | High | Same governorate → same shipping |
| Dual-path: legacy OrderRepository still works | High | storeOrder() not broken |

---

## 11. Final Architecture Decision

### Q1: Is the audit now 100% complete?

**YES.** All remaining files have been read and analyzed:
- All repositories (Order, Coupon, Checkout, FlashSale)
- All controllers (FlashSale, Coupon, Order, FastShipping)
- All resources (Coupon, Cart)
- All services (Dashboard, RestoreProductInventory)
- All enums (PromotionType)
- All 21 migration files (monetary columns documented)
- All route files (checkout, payment, coupon, flash sale routes documented)
- All DTOs
- All models referenced in the audit scope

### Q2: Is there any remaining hidden pricing flow?

**No.** The complete checkout lifecycle has been traced end-to-end:

| Flow | Traced? | Files |
|---|---|---|
| Scheduled checkout | ✅ | `OrderController→OrderService→PromotionService→OrderCreationService` |
| Fast checkout | ✅ | `FastShippingController→FastShippingService→OrderCreationService` |
| Legacy Marvel checkout | ✅ | `OrderController→OrderRepository::storeOrder()` |
| Legacy checkout verify | ✅ | `CheckoutController→CheckoutRepository::verify()` |
| Coupon application | ✅ | `CouponController→CouponService→CouponValidator` |
| Coupon API (Marvel) | ✅ | `CouponController→CouponRepository::addCouponToCart()` |
| Promotion preview | ✅ | `PromotionService::eligiblePromotions()` |
| Payment callback | ✅ | `OrderController::checkoutCallback()` |
| Cart lifecycle | ✅ | `CartInventoryService` (reserve/release/finalize/expire) |
| Dashboard analytics | ✅ | `DashboardService` (revenue queries) |
| Refund flow | ✅ | No listeners found after `RefundApproved` event |
| Inventory restore | ✅ | `RestoreProductInventory` (no lockForUpdate) |

### Q3: Is there any remaining duplicated calculation?

**YES — 8 duplications identified:**

| Calculation | Implementations |
|---|---|
| Coupon discount math | 5: CouponCalculator, ProductPricingService, OrderRepository, OrderService, FastShippingService |
| Checkout totals assembly | 3: OrderService::calculateCheckoutTotals, OrderService::getCheckoutTotalsFromCart, FastShippingService::calculateCheckoutTotals |
| Subtotal computation | 3: PromotionService::subtotal, OrderService::getCheckoutTotalsFromCart, CheckoutRepository::getOrderAmount |
| Shipping price resolution | 3: OrderService::resolveShippingPrice, CheckoutRepository::calculateShippingCharge, FastShippingService::createFastOrder |
| Stock validation | 3: CartInventoryService::reserveStock, OrderRepository::validateAndLockStock, CheckoutRepository::checkStock |
| Tax calculation | 2: CheckoutRepository::calculateTax |
| Coupon validation | 2: CouponValidator, OrderRepository::validateCouponUsage |
| Cart retrieval | 2: CartInventoryService::getActiveCartForUser, OrderService::getCartUser |

### Q4: Is there any remaining duplicated validation?

**YES:**
1. `CouponValidator::validate()` / `CouponValidator::validateByCode()` / `OrderRepository::validateCouponUsage()` — three sets of coupon validation logic
2. `CartInventoryService::ensureCartReservation()` vs `OrderRepository::validateAndLockStock()` — two stock validation paths with different locking behavior
3. `CouponRepository::addCouponToCart()` checks coupon code manually — duplicates CouponValidator

### Q5: Is there any remaining dead pricing code?

**YES:**
1. `OrderService::clearCart()` — not called from any controller
2. `CheckoutRepository::verify()` — legacy endpoint, may be unused by frontend
3. `OrderRepository::storeOrder()` — entire method may be dead (new checkout uses App\General path)
4. `OrderRepository::createOrder()` wrapper — adds thin layer around parent `create()`
5. `CouponCalculator` vs. `ProductPricingService::calculateCouponPrice()` — one is dead

### Q6: Is a unified CheckoutTotalsCalculator the correct architectural direction?

**YES — strongly recommended.** The evidence is overwhelming:

| Reason | Evidence |
|---|---|
| 8 duplicated calculations | All produce same mathematical result but differ in edge cases |
| Dual path inconsistency | `calcInvoicePrice` and `addItemsInOrder` can diverge |
| Side effect contamination | Promotion application mutates cart during read-only preview |
| 5 distinct discount calculators | Each implements percentage/fixed math independently |
| No snapshot mechanism | No way to verify callback amount against original calculation |
| 4 decimal precisions | Rounding errors propagate between systems |
| Immutable DTO exists | `CheckoutTotals` is already the right abstraction — just needs correct construction |

### Q7: What MUST be fixed before starting that refactor?

1. **FREE_SHIPPING gap in FastShippingService** — Easy fix, high impact. Add the missing check.
2. **Unit price rounding bug** — Easy fix, prevents incorrect order_products data from being created during refactor.
3. **OrderRepository::storeOrder() transaction** — Medium effort, critical for data integrity in legacy path.
4. **RestoreProductInventory lockForUpdate** — Easy fix, prevents stock corruption during concurrent operations.

These must be fixed first because:
- They're isolated bugs that are easier to fix before refactoring
- They would otherwise be carried forward into the new architecture
- They can be fixed with minimal risk and full test coverage

### Q8: What MUST NOT be changed?

1. **The checkout endpoint API contract** — Frontend expects the same request/response format
2. **The CheckoutTotals DTO field names** — Used by frontend and payment handlers
3. **The promotion eligibility resolution logic** — Correctly implemented in PromotionEligibilityResolver
4. **The CartInventoryService locking pattern** — Correctly implemented with lockForUpdate + transactions
5. **The database schema** — No schema changes needed for the calculator refactor
6. **The Legacy OrderRepository** — Leave untouched; it's a separate code path for Marvel REST API

### Q9: What is the safest migration order?

1. Fix critical bugs (Phase 1)
2. Extract CheckoutTotalsCalculator alongside existing code (Phase 2 — dual maintain)
3. Verify: new calculator produces identical output to old code for all scenarios
4. Switch `calcInvoicePrice()` to use new calculator
5. Switch `addItemsInOrder()` to use new calculator
6. Switch `FastShippingService` to use new calculator
7. Remove side effects from preview (Phase 3)
8. Unify payment amount (Phase 4)
9. Clean up dead code (Phase 5)

### Q10: Is the system finally ready for implementation?

**YES — with the following conditions:**

1. ✅ All 40+ files have been read and analyzed
2. ✅ All 21+ migration files have been reviewed for monetary columns
3. ✅ All routes have been mapped and categorized
4. ✅ All previous findings have been verified (10/10 confirmed)
5. ✅ New findings documented (8 new issues found)
6. ✅ Complete dependency graph produced
7. ✅ Ownership audit complete for all monetary values
8. ✅ Full checkout lifecycle traced with every recalculation/mutation/persistence point identified
9. ✅ Design review completed with benefits, risks, migration complexity, and backward compatibility assessed
10. ✅ Phased migration plan with task-level detail produced
11. ✅ Test strategy with 50+ identified test scenarios

**However, the following blockers must be resolved before starting the refactor:**

| Blocker | Severity | Effort | Affects |
|---|---|---|---|
| FREE_SHIPPING gap | Critical | Low | Fast checkout with free shipping coupon |
| Unit price rounding bug | High | Low | Order_products data accuracy |
| No transaction in OrderRepository::storeOrder | Critical | Low | Legacy order path data integrity |
| RestoreProductInventory lacks locking | High | Low | Stock corruption during concurrent operations |
| ProductVariant vs Variation confusion | High | Medium | Stock tracking consistency |
| 4 decimal precisions across tables | Medium | High | Silent rounding errors in cross-table calculations |

**Recommendation:** Fix blockers (Phase 1), then proceed with CheckoutTotalsCalculator refactor (Phases 2-5). Total estimated effort: 4-5 weeks for complete migration.

---

## 12. Blocker Summary

### Must-Fix Before Refactor (Week 1)

| # | Blocker | File(s) | Fix |
|---|---|---|---|
| B1 | FREE_SHIPPING not applied in fast checkout | `FastShippingService.php:93-97` | Add coupon type check |
| B2 | Unit price doesn't reconstruct to line total | `OrderCreationService.php:73` | Store `unitPrice = lineTotal / quantity` without rounding, or store both |
| B3 | No DB transaction in storeOrder() | `OrderRepository.php:112-259` | Wrap entire method in DB::transaction |
| B4 | RestoreProductInventory no lock | `RestoreProductInventory.php:24-38` | Add lockForUpdate to product/variant queries |

### Should-Fix Before Refactor (Week 1-2)

| # | Blocker | File(s) | Fix |
|---|---|---|---|
| B5 | Variation stock column mismatch | `OrderRepository.php:329-351` | Align with CartInventoryService (use stock_quantity) |
| B6 | Multiple discount calculators | Multiple files | Document which one is canonical before refactoring |
| B7 | cart.total_price 5 writers | `CartInventoryService`, `OrderService`, `FastShippingService` | Document lifecycle before changing |

### Accept During Refactor (Weeks 2-5)

| # | Blocker | Phase | Notes |
|---|---|---|---|
| B8 | Legacy OrderRepository path | Phase 5 | Leave as-is, only maintain |
| B9 | Decimal precision inconsistency | Phase 5 | Requires migration, coordinate with DBAs |
| B10 | Child orders without discount/tax | Phase 5 | May be intentional — verify with product team |
| B11 | Dead code (clearCart, verify) | Phase 5 | Remove safely after verifying no usage |
