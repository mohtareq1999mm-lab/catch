# Invoice Flow Audit

> Generated: 2026-07-27 | Version: 1.0 | Classification: INTERNAL

---

## 1. Invoice Creation Flow

### 1.1 Order Creation → Invoice Snapshot

```
OrderService::addItemsInOrder()
  ├── createOrder() / updateOrder() — persists order row
  ├── createOrderItems() / syncOrderItems() — persists order_product rows
  ├── finalizeOrder() — dispatches OrderCreated event
  └── return order with items loaded
```

### 1.2 CheckoutTotals DTO Structure
```
CheckoutTotals {
    subtotal: float          — sum of non-gift item (price × quantity)
    promotionDiscount: float — sum of item-level discount_amounts
    couponDiscount: float    — calculated as (finalTotal - subtotal + promotion)
    finalTotal: float        — sum of non-gift item total_prices (after discounts)
    promotion: ?array        — {id, type, code}
    giftItems: array         — reserved gift item IDs
    coupon: ?string          — applied coupon code
    couponDiscountType: ?string — percentage / fixed_rate / free_shipping
    couponDiscountMaxAmount: ?float
    currency: string         — 'EGP' default
}
```

### 1.3 Order Table Fields
| Field | Source | Notes |
|-------|--------|-------|
| `price` | `$checkoutTotals->subtotal` | Pre-discount subtotal |
| `total_price` | `subtotal + shipping + fast_fee` (after discounts) | Final payable amount |
| `shipping_price` | `resolveShippingPrice()` | Per-governorate |
| `coupon_discount` | `$checkoutTotals->couponDiscount` | Coupon discount amount |
| `promotion_discount` | `$checkoutTotals->promotionDiscount` | Promotion discount amount |
| `coupon` | `$checkoutTotals->coupon ?? $cart->coupon` | Coupon code |
| `promotion_id` | `$checkoutTotals->promotionId()` | From CheckoutTotals |

### 1.4 Order-Item Fields
| Field | Source |
|-------|--------|
| `product_price` | `total_price / quantity` (effective unit price) |
| `product_total_price` | `$item->total_price` |
| `promotion_discount_amount` | `(price × quantity) - total_price` |
| `product_flash_sale_price` | From `ProductPricingService` at order time |
| `product_discount_price` | From `ProductPricingService` at order time |

---

## 2. InvoiceSnapshotService

**File**: `app/Services/Invoice/InvoiceSnapshotService.php`

- Schema version: `2.0.0`
- Builds complete snapshot from Order model after payment
- Captures: customer, address, fulfillment, pickup, items, pricing breakdown, payment info, metadata
- Items include: `original_price`, `discount_price`, `flash_sale_price`, `promotion_discount_amount`
- Pricing breakdown validates: `subtotal - promotion - coupon + shipping + fast_fee = total`

### 2.1 Snapshot Sections
1. **customer** — id, name, email, phone
2. **billing_address** / **shipping_address** — street, city, state, governorate, zip, country
3. **fulfillment** — type, shipping_method, shipping_price, expected_delivery_at
4. **pickup_location** — id, name, address, phone, coordinates (null if delivery)
5. **items** — full product snapshot with pricing lineage
6. **pricing_breakdown** — subtotal, discounts, shipping, total, coupon/promotion details
7. **payment** — method, gateway, transaction IDs, paid_at
8. **metadata** — system version, locale, generated_at

---

## 3. FinancialInvariantValidator

**File**: `app/Services/Invoice/Validators/FinancialInvariantValidator.php`

```
computedTotal = subtotal - promotionDiscount - couponDiscount + shippingPrice + fastShippingFee
assert |computedTotal - declaredTotal| <= 0.01
```

- Tolerance: 0.01 (1 cent)
- Throws `FinancialInvariantException` on violation
- Interface: `SnapshotValidatorInterface`

---

## 4. Critical Findings

### 4.1 MEDIUM — Pricing Snapshot Is Built Post-Creation, Not Atomic
The invoice snapshot in `InvoiceSnapshotService::buildFullSnapshot()` reads from the Order model after it's created. If the order's prices are modified between creation and snapshot generation, the snapshot would reflect the modified values. However, the snapshot is typically called immediately after order finalization, so this window is narrow.

### 4.2 LOW — `number_format` Used for Decimal Storage
**File**: `PromotionApplicator.php:118-119`
```php
'discount_amount' => number_format($alloc / 100.0, 2, '.', ''),
'total_price' => number_format($newTotalPrice, 2, '.', ''),
```
`number_format` returns a string, stored in float/decimal columns. Works due to MySQL casting but is fragile. Should use `round()` or explicit cast.

### 4.3 INFO — `price_after_flash_sale` vs `price_after_discount` Priority
The pricing priority is: `flash_sale_price ?? discount_price ?? base_price`. Flash sales always win over regular discounts. This is documented in `calculateProductPricing()`.

### 4.4 INFO — `isFlashSaleActive` Uses `today()` While `isDiscountActive` Uses `now()`
- Flash sales: day-granularity (`Carbon::today()`)
- Product discounts: second-granularity (`Carbon::now()`)
- This means flash sales activate at midnight, while discounts activate at the exact start_date timestamp. Inconsistency may cause confusion for time-sensitive promotions.

---

## 5. Price Calculation Chain

```
CartInventoryService::reserveItem()
  └── ProductPricingService::calculateVariantCurrentPrice() / calculateProductCurrentPrice()
        └── calculateProductPricing()
              ├── normalizeMoney() — round to 2 decimals
              ├── resolveActiveFlashSale() — query flash_sales with valid() scope
              ├── calculateFlashSalePrice() — percentage/fixed_rate/final_price
              └── calculateDiscountedPrice() — percentage/fixed_rate (capped at 100%)
                    └── toCents() → integer arithmetic → fromCents()
```

### 5.1 Flash Sale Discount Types
| Type | Calculation |
|------|------------|
| `PERCENTAGE` | `baseCents × discount% / 100` (capped by `max_discount_amount`) |
| `FIXED_RATE` | Fixed cent amount subtracted |
| `FINAL_PRICE` | `max(0, baseCents - finalPriceCents)` |

### 5.2 Product Discount
| Type | Calculation |
|------|------------|
| `percentage` | `baseCents × amount% / 100` (capped at 100%) |
| `fixed_rate` / `fixed` | `max(0, baseCents - discountCents)` |

---

## 6. Data Flow Diagram

```
Cart items (price, quantity, total_price, discount_amount, promotion_id)
  │
  ├── Promotion Applicator
  │     ├── proportional allocation (largest remainder)
  │     └── writes: promotion_id, discount_amount, total_price
  │
  ├── Coupon Calculator
  │     └── percentage / fixed_rate on post-promotion subtotal
  │
  ├── Order Creation
  │     ├── snapshot cart items → order_product rows
  │     ├── recalculate flash sale / discount prices at order time
  │     └── persists CheckoutTotals → order columns
  │
  └── Invoice Snapshot
        └── buildFullSnapshot() → versioned audit record
```

---

## 7. Security & Consistency

| Check | Location | Status |
|-------|----------|--------|
| Financial invariant validation | `FinancialInvariantValidator` | ✅ |
| Currency check | `OrderController` callback | ✅ (online only) |
| Amount mismatch check | `OrderController` callback | ✅ |
| Minimum order amount | `OrderService::addItemsInOrder` | ✅ |
| Stock reservation before order | `CartInventoryService::ensureCartReservation` | ✅ |
| Inventory finalization after payment | `OrderService::finalizeInventoryAfterPayment` | ⚠️ Exceptions swallowed |
