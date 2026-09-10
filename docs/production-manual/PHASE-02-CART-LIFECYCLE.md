# PHASE 2: Cart Lifecycle — Production Operations Manual

## Executive Summary

The Cart is the temporary holding area for items a customer intends to purchase. It has a well-defined lifecycle: created empty, items added with inventory reservation, promotion/coupon applied, checked out (converted to order), then finalized or expired. This document traces every stage from creation to archive.

**Source Files:** `CartInventoryService.php`, `Cart.php` (Marvel model), `CartItem.php` (Marvel model), `OrderService.php`

---

## 1. Cart Stages

```
CREATED → ACTIVE (with items) → CHECKOUT_STARTED → PENDING_ORDER → FINALIZED / EXPIRED
                                                                     ↓
                                                              CHECKED_OUT (archived)
```

### Stage 1: Cart Created
- **Trigger:** Customer's first add-to-cart (no existing cart)
- **How:** `CartItem` is created; if no cart exists, `Cart::create()` is called implicitly via the relationship
- **Status:** `active`
- **Can customer edit?** Yes
- **Can remove items?** Yes
- **Can add items?** Yes
- **Can retry payment?** N/A
- **Should reservation exist?** No (reservation created on item add)
- **Should coupon stay?** N/A
- **Should promotion stay?** N/A
- **Should prices refresh?** No (refreshed at checkout)
- **Should cart survive?** Yes
- **Should new cart be created?** No

### Stage 2: Items Added (Active Cart)
- **Trigger:** `reserveItem()` in CartInventoryService
- **How:** `lockForUpdate()` on cart → find or create CartItem → `lockInventoryRow` (product/variant) → `reserveStock()` → set `reserved_quantity = quantity`
- **Database writes:**
  - `cart_items`: new row or updated (price, total_price, reserved_quantity, quantity)
  - `cart`: `status=active`, `reserved_at=now()`, `expires_at=now()+3days`
  - `products` or `product_variants`: `reserved_quantity += delta`, `in_stock` recalculated
- **Can customer edit?** Yes (add/remove/change quantity)
- **Can remove items?** Yes (via `releaseItem()` — releases stock, optionally deletes item)
- **Can add items?** Yes
- **Can retry payment?** N/A
- **Coupon?** Can be applied/removed
- **Promotion?** Can be changed
- **Prices?** NOT refreshed until checkout (stale prices possible)

### Stage 3: Coupon Applied
- **Trigger:** `POST /coupons/apply`
- **How:** Coupon validation → `cart->update(['coupon' => $code])`
- **Database write:** `cart.coupon` set to coupon code
- **Coupon reservation?** No — coupon quota is NOT reserved at apply time
- **Can change?** Yes, new coupon replaces old one

### Stage 4: Promotion Considered
- **Trigger:** Frontend calls `eligiblePromotions`, user selects one
- **How:** `PromotionService::applySelectedPromotion()` — applied to cart items (discount_amount, promotion_id) at checkout time
- **Database writes:** `cart_items.promotion_id`, `cart_items.discount_amount`, `cart_items.total_price` recalculated
- **Promotion reservation?** No — usage incremented only after payment

### Stage 5: Checkout Started (ensureCartReservation)
- **Trigger:** Customer clicks Checkout button
- **How:** `ensureCartReservation()` — re-syncs all item quantities with inventory
- **Database writes:** `cart_items.reserved_quantity` updated if needed; `product.reserved_quantity` adjusted
- **Can customer edit?** No (checkout in progress)
- **Stock guaranteed?** Yes (throws if insufficient)

### Stage 6: Pending Order Created
- **Trigger:** `addItemsInOrder()` succeeds; order created in `pending` status
- **How:** Order created, cart items copied to order_products
- **Cart status:** Still `active` (not yet finalized)
- **Cart items:** Still exist (not deleted)
- **Can customer edit?** No (locked from UI)
- **Can retry payment?** Not yet; depends on payment method
- **Can cancel?** Not from cart — must go through order cancellation

### Stage 7: Payment Success — Cart Finalization
- **Trigger:** Callback or markPaid
- **How:** `finalizeItemsByShippingMethod()` or `deductStockForOrder()`
- **finalizeItemsByShippingMethod:**
  - `lockForUpdate()` on cart
  - For SCHEDULED items: `finalizeStock()` (deducts reserved from physical, increases sold)
  - For ALL OTHER items: `releaseStock()` instead
  - Delete all cart items
  - `cart.update(status='checked_out', expires_at=null, reserved_at=null, total_price=0)`
- **Cart status:** `checked_out` (terminal)
- **Can customer edit?** No
- **Cart items:** All deleted
- **Coupon?** Consumed (quota used)
- **Promotion?** Usage incremented
- **New cart?** Future add-to-cart creates a new cart

### Stage 8: Cart Expiration
- **Trigger:** `expireCarts()` via scheduled command (if implemented)
- **How:** Finds all active carts where `expires_at <= now()`:
  - `lockForUpdate()` on cart
  - Release all reserved stock
  - Delete all items
  - `cart.update(status='expired')`
- **Cart status:** `expired` (terminal)
- **Inventory:** Released back to available pool
- **Coupon:** NOT consumed (was never reserved)
- **Promotion:** NOT consumed

### Stage 9: Cart Cancelled (via releaseCart)
- **Trigger:** `CartInventoryService::releaseCart()`
- **How:** Releases all reserved stock, optionally deletes items, resets cart to active with no expiration
- **Cart status:** `active` (not terminal — cart can be reused)
- **Inventory:** Released
- **Coupon?** Removed from cart if items empty

---

## 2. Timeline Diagram

```
TIME →
├─[t=0]── Cart created (empty, active)
├─[t=1]── Item added → stock reserved for 3 days
├─[t=2]── Coupon applied (not consumed)
├─[t=3]── Promotion selected (not consumed)
├─[t=4]── Checkout clicked → ensureCartReservation (re-sync stock)
├─[t=5]── Order created (pending)
├─[t=6]── Payment processed (online redirect / COD / cashier)
│
├─ IF PAYMENT SUCCEEDS:
│   ├─[t=7]── Inventory finalized (stock deducted, cart checked_out)
│   └─[t=8]── New cart can be created on next add-to-cart
│
├─ IF PAYMENT FAILS:
│   ├─[t=7]── Order stays pending (no cart changes)
│   └─[t=8]── Cart can still be used (not finalized)
│
├─ IF CUSTOMER ABANDONS:
│   ├─[t=7]── Cart expires after 3 days
│   └─[t=8]── Stock released, cart status=expired
│
└─ IF CUSTOMER REMOVES ALL ITEMS:
    └─[t=7]── Coupon removed, cart empty, no expiration
```

## 3. State Machine

```
                    ┌─────────────────┐
                    │     CREATED     │
                    │ (status=active) │
                    │ items=0, no     │
                    │ reservation     │
                    └────────┬────────┘
                             │
                    add item / reserveItem()
                             │
                             ▼
                    ┌─────────────────┐
                    │  ITEMS ADDED    │
                    │ (status=active) │
                    │ items>0, stock  │
                    │ reserved 3 days │
                    └────────┬────────┘
                             │
                    checkout started
                             │
                             ▼
                    ┌─────────────────┐
                    │  IN CHECKOUT    │
                    │ (still active)  │
                    │ order= pending  │
                    └────────┬────────┘
                             │
                ┌────────────┼────────────┐
                │            │            │
        payment success  payment fail  expire/abandon
                │            │            │
                ▼            ▼            ▼
        ┌───────────┐  ┌───────────┐  ┌───────────┐
        │CHECKED_OUT│  │  ACTIVE   │  │ EXPIRED   │
        │ terminal  │  │ (retry)   │  │ terminal  │
        └───────────┘  └───────────┘  └───────────┘
```

## 4. Data Model

### Cart
```sql
carts: id, user_id, coupon (nullable), total_price, status (active|checked_out|expired), reserved_at, expires_at, timestamps
```

### CartItem
```sql
cart_items: id, cart_id, product_id, product_variant_id, quantity, reserved_quantity, price, total_price, 
            attributes (json), discount_amount, shipping_method (SCHEDULED|FAST), is_gift (bool), 
            promotion_id (nullable), timestamps
```

## 5. Key Design Decisions

1. **Cart is single per user** — one active cart at a time. No multi-cart support.
2. **Reservation has 3-day TTL** — `Carbon::now()->addDays(self::CART_TTL_DAYS)` at each touch
3. **Prices are NOT live** — cart item prices are set at add-to-cart time; refreshed only at checkout
4. **Coupon is NOT reserved** — applied to cart but quota consumed only at payment
5. **Promotion is NOT reserved** — usage incremented only at payment
6. **Inventory IS reserved** — stock deducted from available pool on add-to-cart; released on expiration or explicit release
7. **Cart checked_out is terminal** — items deleted, status = checked_out, new cart needed

## 6. Problems Found

| Problem | Severity | Description |
|---------|----------|-------------|
| No cart cleanup command | MEDIUM | `expireCarts()` exists but no scheduled command registered in Kernel |
| Prices can be stale | LOW | Cart price set at add-time, not refreshed until checkout |
| No cart locking on concurrent add | MEDIUM | Multiple simultaneous add-to-cart for same product could oversell (reserveStock checks available at operation time) |

## 7. Production Recommendations

1. Register `CartInventoryService::expireCarts()` as a scheduled task (every 5 minutes)
2. Add price staleness indicator on cart items (show "prices may have changed" banner)
3. Add max quantity per product validation
4. Consider reducing TTL from 3 days to 1 day for better inventory turnover
5. Add cart item count and total to cart model for quick display without joins
