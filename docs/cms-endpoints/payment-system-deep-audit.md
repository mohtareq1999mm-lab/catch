# Payment System Deep Architecture Audit

**Date:** 2026-07-12  
**Method:** Every conclusion verified against actual implementation code.  
**Baseline:** docs/payment-system-architecture-review.md (Jul 11) — discrepancies noted below.

---

## Corrections vs Previous Architecture Review

The Jul 11 architecture review has been partially superseded. Key changes:

| Subject | Jul 11 Doc Said | Current Code (Jul 12) | Status |
|---------|----------------|----------------------|--------|
| BUG-1 (callback blocking) | ❌ Log warning only, not blocking | ✅ **BLOCKING** — order cancelled, PaymentFailed fired | FIXED |
| BUG-1 stock leak (OrderCancelled in failure) | ❌ Explicit `event(new OrderCancelled(...))` | ✅ **Removed** — only PaymentFailed is dispatched | FIXED |
| CheckoutTotals DTO | ⚠️ Recommendation only, not implemented | ✅ **Implemented** — `CheckoutTotals` readonly DTO in use | DONE |
| Payment Reconciliation | 📌 Future roadmap | ✅ **Implemented** — Job + Command + Model + Dashboard endpoint | DONE |
| `PaymentCheckoutHandler.php` path | `app/Services/Checkout/` | `app/Services/Payment/` | MOVED |
| `net_revenue` formula (BUG-3) | Subtracts `coupon_discount` only | ✅ Now includes `promotion_discount` too | FIXED |
| `getCouponAnalytics` total_discount | Called `total_discount` misleadingly | ✅ Renamed to `total_coupon_discount` | FIXED |
| `DashboardTest` failures (SQLite) | 26 pre-existing failures | ✅ All resolved — migration fixes applied | FIXED |
| `PaymentReconciliationTest` | Did not exist | ✅ 26 tests, all passing | ADDED |

**Total:** 7 discrepancies found in the Jul 11 document. 0 regressions introduced.

---

## Phase 1: Complete Cart Lifecycle

### 1.1 Cart States

The `carts` table has `status` column with 3 possible values:

```
active      → Cart is live, items reserving stock
checked_out → Cart has been finalized (payment success or COD/cashier)
expired     → Cart TTL expired (3 days), stock released
```

### 1.2 Add To Cart

**Entry point:** `POST /cart` → `CartController::store()` → `CartRepository::storeCart()` → `CartRepository::persistCart()`

```mermaid
sequenceDiagram
    Customer->>CartController: POST /cart {item: {product_id, quantity}}
    CartController->>CartRepository: storeCart(request)
    CartRepository->>Cart: lockForUpdate() find or create
    CartRepository->>CartInventoryService: reserveItem(cart, product, variant, qty, 'add')
    CartInventoryService->>DB: transaction start
    CartInventoryService->>Cart: lockForUpdate() reload
    CartInventoryService->>Product: lockInventoryRow() → lockForUpdate()
    CartInventoryService->>Product: reserveStock() → reserved_quantity += delta
    CartInventoryService->>CartItem: create/update with reserved_quantity
    CartInventoryService->>Cart: touchCartReservation() → expires_at = now + 3 days
    CartInventoryService->>DB: transaction commit
    CartRepository->>Cart: update total_price = SUM(items.total_price)
    CartRepository->>DB: commit
    CartController->>Customer: 201 CartResource
```

**Key details:**
- `reserveItem()` uses a `DB::transaction()` with `lockForUpdate()` on Cart, Product, and CartItem rows
- `availableStock = stock_quantity - reserved_quantity` (must be ≥ desired qty)
- `touchCartReservation()` sets `status='active'`, `reserved_at=now()`, `expires_at=now()+3days`
- CartItem stores `reserved_quantity = quantity` (always kept in sync when qty changes)
- If an item already exists (same product+variant), it updates qty and adjusts stock delta

### 1.3 Pluck Multiple Items

**Entry point:** `POST /cart/bulk-items` → `CartController::pluckItemsToCart()`

Calls `storeCart()` in a loop for each item in `request.items[]`. Each iteration:
- Clones the request
- Replaces with a single item
- Calls `CartRepository::storeCart()`

**Risk:** No wrapping transaction. If item 3 of 5 fails, items 1-2 are already committed.

### 1.4 Update Cart Item (Set Mode)

**Entry point:** `PUT /cart/update-item` → `CartController::update()` → `CartRepository::updateCart()` → `CartRepository::persistCart()` with `mode='set'`

Same flow as store but:
- `desiredQuantity = quantity` (instead of `existing + quantity`)
- If `delta < 0` → calls `releaseStock()` to decrement `reserved_quantity`
- If setting qty to 0 → `desiredQuantity = 0` → throws `Exception('Quantity must be at least 1.')`

### 1.5 Delete Single Cart Item

**Entry point:** `DELETE /cart/delete-item/{itemId}` → `CartController::deleteItemFromCart()`

```mermaid
flowchart LR
    A[CartController] --> B[find item by itemId]
    B --> C[CartInventoryService::releaseItem(item, deleteItem=true)]
    C --> D[lockForUpdate on CartItem]
    D --> E{reserved_quantity > 0?}
    E -->|Yes| F[lockInventoryRowByItem]
    F --> G[releaseStock → reserved_quantity -= qty]
    G --> H[delete CartItem]
    E -->|No| H
    H --> I[update cart.total_price]
```

### 1.6 Clear Entire Cart

**Entry point:** `DELETE /cart/delete-items` → `CartController::destroy()`

```php
$this->inventoryService->releaseCart($cart, true);
```

`releaseCart()`:
1. `lockForUpdate()` on Cart
2. For each CartItem: calls `releaseItem(item, deleteItems=true)` → releases stock + deletes item
3. Sets `cart.status = 'active'`, `cart.expires_at = null`, `cart.reserved_at = null`, `total_price = 0`

Note: Status stays `active`, not `expired` or `deleted`. This means the cart can be reused.

### 1.7 Cart Expiration (Cron)

**Command:** `cart:expire` → `CartInventoryService::expireCarts()`  
**Schedule:** `$schedule->command('cart:expire')->hourly()->withoutOverlapping()`

```mermaid
flowchart TD
    A[cron: cart:expire] --> B[Cart::where status=active AND expires_at <= now]
    B --> C[chunkById 100]
    C --> D[expireCart for each]
    D --> E[lockForUpdate Cart + items]
    E --> F[for each item: releaseStock → reserved_quantity -= qty]
    F --> G[items()->delete]
    G --> H[cart.status = expired, total_price = 0]
```

### 1.8 Ensure Cart Reservation (At Checkout)

**Called from:** `OrderController::checkout()` → `CartInventoryService::ensureCartReservation()`

```mermaid
flowchart TD
    A[checkout] --> B[ensureCartReservation]
    B --> C[lockForUpdate Cart + items + products + variants]
    C --> D[for each item: syncCartItemReservation]
    D --> E[delta = quantity - reserved_quantity]
    E --> F{delta > 0?}
    F -->|Yes| G[reserveStock → reserved_quantity += delta]
    F -->|No| H{delta < 0?}
    H -->|Yes| I[releaseStock → reserved_quantity -= abs(delta)]
    H -->|No| J[no change]
    G --> K[touchCartReservation]
    I --> K
    J --> K
```

### 1.9 Cart Finalization (Checkout Success)

**Called from:**
- `PaymentCheckoutHandler::finalizeInventory()` — for COD and Cashier payments
- `OrderController::checkoutCallback()` — for online payment success

Uses `finalizeItemsByShippingMethod(cart, shippingMethod)`:
1. `lockForUpdate()` Cart
2. Query CartItems by shipping method
3. For each item: `finalizeStock()` → `stock_quantity -= qty`, `reserved_quantity -= qty`, `sold_quantity += qty`
4. `$item->delete()`
5. If no remaining items → `cart.status = 'checked_out'`
6. If items remain → recalculate `cart.total_price`

### 1.10 Cart Release (Checkout Failure)

**Called from:**
- `OrderController::checkoutCallback()` failure path
- `OrderController::checkoutErrorCallback()` failure path
- `CancelUnpaidOrders` command

Uses `releaseCart(cart, deleteItems=false)`:
1. `lockForUpdate()` Cart + items
2. For each item: `releaseItem()` → `reserved_quantity -= qty` (no delete)
3. `cart.status = 'active'`, `cart.expires_at = null`, `cart.reserved_at = null`

Note: `deleteItems=false` means cart items stay in the database but with `reserved_quantity=0`.

---

## Phase 2: Complete Inventory Lifecycle

### 2.1 Physical Stock Equation

```
available_stock = stock_quantity - reserved_quantity

Add to Cart:      reserved_quantity += qty
Checkout (sync):  reserved_quantity = qty (delta adjustment)
Payment Success:  stock_quantity -= qty, reserved_quantity -= qty, sold_quantity += qty
Payment Failure:  reserved_quantity -= qty (via releaseCart)
Cancel (completed→cancelled): stock_quantity += qty, sold_quantity -= qty (via RestoreProductInventory)
Cart Expiry:      reserved_quantity -= qty (via expireCart)
```

### 2.2 All Inventory Operations

| Operation | Method | SQL Update | Lock | Event |
|-----------|--------|-----------|------|-------|
| Reserve | `reserveStock()` | `reserved_quantity += delta` | `lockForUpdate()` | None |
| Release | `releaseStock()` | `reserved_quantity -= delta` | `lockForUpdate()` | None |
| Finalize | `finalizeStock()` | `stock_quantity -= qty, reserved_quantity -= qty, sold_quantity += qty` | `lockForUpdate()` | None |
| Restore | `RestoreProductInventory::handle()` | `stock_quantity += qty, sold_quantity -= qty` | **NONE** | `OrderCancelled` (queued) |

### 2.3 Race Condition Analysis

All synchronous operations use `lockForUpdate()` (MySQL row-level pessimistic locks). The only operation without a lock is `RestoreProductInventory` (queued listener).

**Risk:** If admin cancels an order while the queue processes the `RestoreProductInventory` job, and simultaneously another process calls `finalizeStock` on the same product, the `stock_quantity` and `sold_quantity` could be incorrect.

**Mitigation:** Low likelihood — `OrderCancelled` only fires for `completed→cancelled` transitions, and no other process should be modifying that product's inventory simultaneously.

### 2.4 The Stock Leak Bug (Now Fixed)

**Previous behavior (pre-Jul 12):** The callback failure path explicitly dispatched `event(new OrderCancelled($order))`, which queued `RestoreProductInventory`. Since `finalizeStock` was never called (payment failed before stock was finalized), `RestoreProductInventory` would add `product_quantity` to `stock_quantity` incorrectly — creating a stock overcount.

**Current behavior (Jul 12+):** The explicit `OrderCancelled` dispatch was removed from both:
- `OrderController::checkoutCallback()` (failure path)
- `OrderController::checkoutErrorCallback()` (failure path)

Only `PaymentFailed` is dispatched. The `changeOrderStatus()` method correctly only dispatches `OrderCancelled` when transitioning from `completed→cancelled`. Since the order was `pending` during callback failure, `OrderCancelled` is NOT triggered.

**Also fixed:** The `releaseCart()` call was added to the callback failure path to release `reserved_quantity` back.

---

## Phase 3: Complete Event System

### 3.1 All Application Events

| Event | File | Dispatchers | Queue |
|-------|------|-------------|-------|
| `OrderCreated` | `app/Events/OrderCreated.php` | `OrderCreationService::finalizeOrder()` | Synchronous dispatch |
| `OrderCancelled` | `app/Events/OrderCancelled.php` | `OrderService::changeOrderStatus()` when `completed→cancelled` | Synchronous dispatch |
| `OrderStatusChanged` | `app/Events/OrderStatusChanged.php` | `OrderService::changeOrderStatus()` on every change | Synchronous dispatch |
| `PaymentSucceeded` | `app/Events/PaymentSucceeded.php` | `OrderService::markCodAsPaid()`, `OrderService::markCashierPaid()`, `OrderController::checkoutCallback()` | Synchronous dispatch |
| `PaymentFailed` | `app/Events/PaymentFailed.php` | `CancelUnpaidOrders`, `OrderController::checkoutCallback()` failure, `checkoutErrorCallback()` | Synchronous dispatch |
| `AdminLoggedIn` | `app/Events/AdminLoggedIn.php` | Admin login controller | Synchronous dispatch |
| `ContactMessageReceived` | `app/Events/ContactMessageReceived.php` | Contact form controller | Synchronous dispatch |
| `UserRolesUpdated` | `app/Events/UserRolesUpdated.php` | User role management | Synchronous dispatch |

### 3.2 All Application Listeners

| Listener | Event | Queue | Action |
|----------|-------|-------|--------|
| `SendNewOrderNotification` | `OrderCreated` | `medium` | Admin DB notification + `LogActivityJob` |
| `RestoreProductInventory` | `OrderCancelled` | `medium` | `stock_quantity += qty`, `sold_quantity -= qty` |
| `SendOrderCancelledNotification` | `OrderCancelled` | `medium` | `LogActivityJob` |
| `SendOrderStatusChangedNotification` | `OrderStatusChanged` | `medium` | `LogActivityJob` |
| `SendPaymentSucceededNotification` | `PaymentSucceeded` | `medium` | `LogActivityJob` |
| `SendPaymentFailedNotification` | `PaymentFailed` | `medium` | `LogActivityJob` |
| `SendAdminLoginNotification` | `AdminLoggedIn` | `medium` | DB notification to admins |
| `SendContactMessageNotification` | `ContactMessageReceived` | `medium` | DB notification to admins |
| `LogUserRolesUpdated` | `UserRolesUpdated` | `medium` | Activity log |

### 3.3 Event Flow Diagram

```mermaid
flowchart TD
    subgraph Dispatchers
        CO[changeOrderStatus]
        FO[finalizeOrder]
        MC[markCodAsPaid]
        MP[markCashierPaid]
        CB[checkoutCallback success]
        CF[checkoutCallback failure]
        CU[CancelUnpaidOrders]
    end

    subgraph Events
        OC[OrderCreated]
        OCC[OrderCancelled]
        OSC[OrderStatusChanged]
        PS[PaymentSucceeded]
        PF[PaymentFailed]
    end

    subgraph Listeners
        SN[SendNewOrderNotification]
        RI[RestoreProductInventory]
        SC[SendOrderCancelledNotification]
        SS[SendOrderStatusChangedNotification]
        SP[SendPaymentSucceededNotification]
        SF[SendPaymentFailedNotification]
    end

    FO --> OC
    OC --> SN

    CO -->|completed→cancelled| OCC
    OCC --> RI
    OCC --> SC

    CO --> OSC
    MC --> OSC
    MP --> OSC
    
    OSC --> SS

    MC --> PS
    MP --> PS
    CB -->|on success| PS
    PS --> SP

    CF -->|on failure| PF
    CU --> PF
    CB -->|amount mismatch| PF
    PF --> SF

    CO -->|any transition| OSC
```

### 3.4 Missing: `RefundApproved` Has No Listener

The `Marvel\Events\RefundApproved` event exists but is NOT registered in `app/Providers/EventServiceProvider.php`. When a refund is approved:
- No inventory restoration
- No gateway refund call
- No notification
- No activity log
- Order status stays `completed`

### 3.5 No Duplicate Event Registrations

Verified: `EventServiceProvider.php` — each event appears exactly once. No duplicate listeners.

---

## Phase 4: Order Lifecycle

### 4.1 Order Status State Machine

```mermaid
stateDiagram-v2
    [*] --> pending: Order created
    pending --> completed: Callback success / Mark COD paid / Mark cashier paid
    pending --> cancelled: Callback failure / CancelUnpaidOrders / amount mismatch
    
    completed --> cancelled: Admin cancellation
    
    completed --> [*]: Refund (NOT IMPLEMENTED)
    cancelled --> [*]: Terminal state
```

### 4.2 Status Transitions

| From | To | Trigger | Inventory | Events |
|------|----|---------|-----------|--------|
| `none`→`pending` | Order created | `OrderCreationService::createOrder()` | Reserved | `OrderCreated` |
| `pending`→`completed` | Payment success | `checkoutCallback()`, `markCodAsPaid()`, `markCashierPaid()` | Finalize stock | `OrderStatusChanged`, `PaymentSucceeded` |
| `pending`→`cancelled` | Payment failure | `checkoutCallback()` failure, `CancelUnpaidOrders` | Release cart | `OrderStatusChanged`, `PaymentFailed` |
| `pending`→`cancelled` | Amount mismatch | `checkoutCallback()` mismatch detection | Release cart | `OrderStatusChanged`, `PaymentFailed` |
| `completed`→`cancelled` | Admin cancel | `OrderService::changeOrderStatus()` | RestoreProductInventory (queued) | `OrderStatusChanged`, `OrderCancelled` |

### 4.3 Impossible Transitions (No Code Supports These)

- `cancelled → completed`: Not implemented. Callback manually checks `paymentId` uniqueness but doesn't guard against this. If a failure callback arrives after a success callback, order stays `completed` (changeOrderStatus would try `completed→completed` which is no-op).
- `cancelled → pending`: Not possible.
- `completed → pending`: Not possible.
- `expired → *`: `Order.status` has no `expired` status (that's a cart status).

---

## Phase 5: Payment Lifecycle

### 5.1 Payment Methods

```
online         → MyFatoorah gateway → callback success/failure
cod            → Cash on Delivery → markCodAsPaid (admin action)
pay_at_cashier → QR code → markCashierPaid (admin action)
```

### 5.2 Online Payment Flow

```mermaid
sequenceDiagram
    Customer->>OrderController: POST /checkout {payment_method: online}
    OrderController->>CartInventoryService: ensureCartReservation(cart)
    OrderController->>OrderService: calcInvoicePrice(request)
    OrderController->>OrderService: addItemsInOrder(request)
    OrderService->>OrderCreationService: createOrder() → Order (status=pending)
    OrderService->>OrderCreationService: createOrderItems()
    OrderService->>OrderCreationService: finalizeOrder() → dispatch OrderCreated
    OrderController->>PaymentCheckoutHandler: handleOnlinePayment(request, order, amount, gateway)
    PaymentCheckoutHandler->>PaymentGatewayFactory: make(gateway) → MyFatoorahGateway
    MyFatoorahGateway->>MyFatoorah API: createInvoice(order, amount, callbackUrl, errorUrl)
    MyFatoorah API-->>MyFatoorahGateway: {invoiceId, redirectUrl, success}
    MyFatoorahGateway-->>PaymentCheckoutHandler: GatewayResult
    PaymentCheckoutHandler->>Transaction: create(order_id, invoice_id, status=pending, amount)
    PaymentCheckoutHandler-->>Customer: {url: redirectUrl}

    Customer->>MyFatoorah: Redirect to payment page
    Customer->>MyFatoorah: Complete payment
    MyFatoorah->>OrderController: Redirect to callbackUrl?paymentId=xxx
    OrderController->>MyFatoorahGateway: verifyPayment(paymentId)
    MyFatoorahGateway->>MyFatoorah API: GET /v2/GetPaymentStatus
    MyFatoorah API-->>MyFatoorahGateway: {InvoiceStatus, InvoiceId, InvoiceValue, Currency}
    
    alt Payment Failed
        OrderController->>OrderService: changeOrderStatus(invoiceId, 'cancelled')
        OrderController->>CartInventoryService: releaseCart(cart)
        OrderController->>Event: PaymentFailed
        OrderController->>Customer: redirect /payment/failed
    else Amount/Currency Mismatch
        OrderController->>OrderService: changeOrderStatus(invoiceId, 'cancelled')
        OrderController->>CartInventoryService: releaseCart(cart)
        OrderController->>Event: PaymentFailed
        OrderController->>Customer: redirect /payment/failed
    else Payment Success
        OrderController->>OrderService: changeOrderStatus(invoiceId, 'completed')
        OrderController->>CartInventoryService: finalizeItemsByShippingMethod(cart, method)
        OrderController->>Event: PaymentSucceeded
        OrderController->>Customer: redirect /payment/success
    end
```

### 5.3 Callback Security (BUG-1 — Now Fixed)

The Jul 11 document reported this as a non-blocking warning. **Current code now blocks:**

```php
// OrderController.php:242-279
if ($result->amount !== null && abs((float) $result->amount - (float) $order->total_price) > 0.01) {
    $hasMismatch = true;
    \Log::warning('Payment amount mismatch - blocking order', [...]);
}

if (!$hasMismatch && $result->currency !== null && $result->currency !== config('payment.default_currency', 'EGP')) {
    $hasMismatch = true;
    \Log::warning('Payment currency mismatch - blocking order', [...]);
}

if ($hasMismatch) {
    // Cancel order, release cart, fire PaymentFailed
    // Redirect to /payment/failed
}
```

**Remaining gap:** Order ownership is NOT verified. The callback looks up by `gateway_transaction_id` or `invoice_id` which inherently scopes to the correct order, but a malicious actor with a valid `paymentId` could theoretically trigger the callback for another user's order. Risk is low since `paymentId` is per-invoice and scoped by MyFatoorah.

### 5.4 Duplicate Callback Protection

- `changeOrderStatus('completed')` when order is already `completed` → no-op (same status, `->update()` writes same value)
- `finalizeItemsByShippingMethod()` when cart items already deleted → no-op (query returns empty)
- `releaseCart()` when `reserved_quantity` already 0 → no-op (max(0, ...) guard)

---

## Phase 6: Shipping Lifecycle

### 6.1 Three Fulfillment Types

```
delivery   → Governorate shipping price + optional fast shipping fee
pickup     → No shipping price, no fast fee
```

### 6.2 Scheduled Delivery

```
Governorate → ShippingPrice (many-to-one)
   ├── price (float)             ← shipping_price on order
   ├── free_shipping_over (float?) ← if subtotal > this, shipping_price = 0
   └── status (bool)
```

Shipping price resolved by `OrderService::resolveShippingPrice()`.

### 6.3 Fast Shipping

```
FastShippingSettings
   ├── fee_per_km (float)
   ├── base_fee (float)
   └── status (bool)

FastShippingRepository
   ├── getFee()       → computes fee from settings
   ├── calculateEta() → computes delivery ETA
   └── validateCheckout(governorate, items) → validates eligibility
```

Fast shipping fee is **always charged** even when the governorate's free shipping threshold is met. This is correct — the business rule says "fast shipping fee is a premium, not a shipping replacement."

### 6.4 Shared Logic

Both scheduled and fast paths use `resolveShippingPrice()` (or its public wrapper `getGovernorateShippingInfo()`). The free shipping threshold check is duplicated in 3 places but justified — each path computes `subtotal` differently (scheduled: all items, fast: only FAULT items).

---

## Phase 7: Coupon + Promotion

### 7.1 Promotion Types

From `Marvel\Enums\PromotionTypeEnum`:
```
PRODUCT_SPECIFIC  → Discount applies to specific products
APPLY_TO_ALL      → Discount applies to entire subtotal
GIFT              → Free product added to cart
```

### 7.2 Coupon Discount Types

From `Marvel\Enums\CouponTypeEnum`:
```
FIXED            → Fixed amount off (e.g., 10 EGP)
PERCENTAGE       → Percentage off (e.g., 10%)
FREE_SHIPPING    → Free shipping
```

### 7.3 Calculation Order

```mermaid
flowchart LR
    A[Cart items] --> B[subtotal = SUM(price * qty)]
    B --> C[PromotionService::applySelectedPromotion]
    C --> D[promotion_discount on each item]
    D --> E[priceAfterPromotion = subtotal - promotion_discount]
    E --> F[calculateCouponDiscount]
    F --> G[coupon_discount = coupon% on priceAfterPromotion]
    G --> H[final_total = priceAfterPromotion - coupon_discount]
```

### 7.4 CheckoutTotals DTO

```php
// app/DTOs/CheckoutTotals.php
readonly class CheckoutTotals {
    public float $subtotal;
    public float $promotionDiscount;
    public float $couponDiscount;
    public float $finalTotal;
    public ?array $promotion;
    public array $giftItems;
    public ?string $coupon;
    public ?string $couponDiscountType;
    public ?float $couponDiscountMaxAmount;
}
```

Returned by 3 services:
- `OrderService::getCheckoutTotalsFromCart()` — reads pre-applied values from cart items
- `OrderService::calculateCheckoutTotals()` — recomputes via PromotionService
- `FastShippingService::calculateCheckoutTotals()` — fast shipping variant

### 7.5 Coupon Usage Recording

Coupon usage is recorded by `OrderService::recordCouponUsage()`:
- Called when `status = 'completed'` (in `changeOrderStatus()` or `markCodAsPaid()` or `markCashierPaid()`)
- Creates `CouponUsage` record (prevents re-use)
- Usage is **not rolled back** on cancellation (intentional — prevents coupon reuse attacks)

---

## Phase 8: Every Place That Clears The Cart

### 8.1 Complete Deletion Matrix

| Method | File:Line | Why | Deletes What | Trigger | Transaction | Event |
|--------|-----------|-----|--------------|---------|-------------|-------|
| `CartInventoryService::finalizeItemsByShippingMethod()` | CartInventoryService.php:222 | Payment success — move reserved→sold | CartItem records (by shipping_method) | `checkoutCallback()` success, `finalizeInventory()` for COD/cashier | ✅ `DB::transaction` | None |
| `CartInventoryService::finalizeCart()` | CartInventoryService.php:197 | Finalize ALL items regardless of method | ALL CartItem records | Never called from App code (utility only) | ✅ `DB::transaction` | None |
| `CartInventoryService::releaseItem()` with `deleteItem=true` | CartInventoryService.php:160 | Delete single item from cart | Single CartItem record | `CartController::deleteItemFromCart()` | ✅ `DB::transaction` | None |
| `CartInventoryService::releaseCart()` with `deleteItems=true` | CartInventoryService.php:177 | Clear entire cart | ALL CartItem records (via releaseItem loop) | `CartController::destroy()` | ✅ `DB::transaction` | None |
| `CartInventoryService::expireCart()` | CartInventoryService.php:322 | Cart TTL expired | ALL CartItem records (`$cart->items()->delete()`) | `cart:expire` cron (hourly) | ✅ `DB::transaction` | None |
| `CartItem::delete()` inside finalize loop | CartInventoryService.php:208,238 | Remove finalized items | Single CartItem record each iteration | Part of `finalizeCart()` or `finalizeItemsByShippingMethod()` | ✅ (wrapped) | None |
| `Cart::items()->delete()` in `OrderService::clearCart()` | OrderService.php:332 | Manual cart clear | ALL CartItem records | No caller found — dead code | ❌ No transaction | None |

### 8.2 Cart Deletion Flowchart

```mermaid
flowchart TD
    subgraph Cart Items Deleted
        A1[finalizeItemsByShippingMethod] --> B1[foreach item: finalizeStock + item->delete]
        A2[releaseItem with deleteItem=true] --> B2[releaseStock + item->delete]
        A3[releaseCart with deleteItems=true] --> B3[foreach item: releaseItem with delete]
        A4[expireCart] --> B4[releaseStock + items()->delete]
    end

    subgraph Cart Status Changed
        C1[finalizeItemsByShippingMethod: all items done] --> D1[cart.status = checked_out]
        C2[finalizeCart] --> D2[cart.status = checked_out]
        C3[releaseCart] --> D3[cart.status = active, expires_at = null]
        C4[expireCart] --> D4[cart.status = expired]
    end

    subgraph Triggers
        T1[checkoutCallback success] --> A1
        T2[COD/cashier payment] --> A1
        T3[Customer deletes single item] --> A2
        T4[Customer clears entire cart] --> A3
        T5[cart:expire cron] --> A4
    end
```

### 8.3 What Is NOT Deleted

- **Cart record itself** is NEVER deleted. Status transitions: `active → checked_out | expired`
- **Checked_out carts**: Status = `checked_out`, no items, no reservation. Record retained for analytics.
- **Expired carts**: Status = `expired`, no items. Record retained for abandonment analytics.
- **Active carts cleared by user**: Status = `active`, no items. Can be reused.

---

## Phase 9: Sequence Diagrams

### 9.1 Scheduled Checkout (Online Payment)

```mermaid
sequenceDiagram
    actor C as Customer
    participant CC as CartController
    participant OC as OrderController
    participant PH as PaymentCheckoutHandler
    participant CI as CartInventoryService
    participant OS as OrderService
    participant OCS as OrderCreationService
    participant GW as MyFatoorahGateway
    participant TX as Transaction
    participant EV as Events

    Note over C,EV: Customer already has items in cart
    C->>OC: POST /api/v1/checkout
    Note right of OC: with shipping_method=SCHEDULED, payment_method=online
    
    OC->>CI: getActiveCartForUser(user)
    CI-->>OC: Cart
    
    OC->>CI: ensureCartReservation(cart)
    CI->>CI: lockForUpdate cart, items, products
    CI->>CI: sync each item's reserved_quantity = quantity
    CI->>CI: touchCartReservation → extend TTL
    
    OC->>OS: calcInvoicePrice(request)
    OS->>OS: calculateCheckoutTotals(cart, promotion, gift)
    OS->>OS: resolveShippingPrice(governorate_id)
    OS->>OS: apply free_shipping_over check
    OS->>OS: cart.total_price = final_total + shipping
    OS-->>OC: cart.total_price
    
    OC->>OS: addItemsInOrder(request)
    OS->>OS: getCheckoutTotalsFromCart(cart)
    OS->>OS: resolveShippingPrice(governorate_id)
    OS->>OCS: createOrder(data, cart, totals, shipping)
    OCS->>OCS: resolvePickupLocationSnapshot(id)
    OCS->>DB: INSERT orders
    OCS-->>OS: Order
    OS->>OCS: createOrderItems(order, cart)
    OCS->>DB: INSERT order_items per cart item
    OS->>OCS: finalizeOrder(order, totals)
    OCS->>OCS: incrementUsage(promotion)
    OCS->>EV: OrderCreated::dispatch(order)
    EV-->>EV: SendNewOrderNotification (queued medium)
    OS-->>OC: Order (with items)
    
    OC->>PH: handleOnlinePayment(request, order, orderPrice, gateway)
    PH->>PH: paymentGatewayFactory.make(gateway)
    PH->>GW: createInvoice(order, amount, callback, error)
    GW->>GW: POST /v2/SendPayment (MyFatoorah API)
    GW-->>PH: GatewayResult {redirectUrl, invoiceId}
    PH->>DB: INSERT transactions (status=pending)
    PH-->>C: {url: redirectUrl}
    
    C->>C: Redirect to MyFatoorah payment page
    Note over C: Customer completes payment
    
    MyFatoorah->>OC: GET /callback?paymentId=xxx
    OC->>GW: verifyPayment(paymentId)
    GW-->>OC: GatewayResult {InvoiceStatus=Paid, amount, currency}
    
    OC->>OC: Amount/currency validation
    Note right of OC: If mismatch → cancel order, redirect to /payment/failed
    
    OC->>OS: changeOrderStatus(invoiceId, 'completed')
    OS->>OS: Transaction → status=paid, paid_at=now
    OS->>OS: recordCouponUsage(order)
    OS-->>OC: Order (status=completed)
    
    OC->>CI: getActiveCartForUser(user)
    OC->>CI: finalizeItemsByShippingMethod(cart, shippingMethod)
    CI->>CI: lockForUpdate items
    CI->>CI: finalizeStock for each item
    Note right of CI: stock_quantity -= qty, reserved_quantity -= qty, sold_quantity += qty
    CI->>CI: delete finalized CartItems
    CI->>CI: cart.status = checked_out
    
    OC->>EV: PaymentSucceeded::dispatch(order)
    EV-->>EV: SendPaymentSucceededNotification (queued medium)
    
    OC-->>C: Redirect /payment/success
```

### 9.2 COD Checkout

```mermaid
sequenceDiagram
    actor C as Customer
    participant OC as OrderController
    participant PH as PaymentCheckoutHandler
    participant CI as CartInventoryService
    participant OS as OrderService
    participant TX as Transaction
    participant EV as Events

    C->>OC: POST /api/v1/checkout {payment_method: cod}
    OC->>CI: ensureCartReservation(cart)
    OC->>OS: calcInvoicePrice(request)
    OC->>OS: addItemsInOrder(request) → Order (pending)
    OC->>PH: handleCodPayment(request, order)
    
    PH->>DB: INSERT transaction (status=pending, payment_method=cod)
    PH->>CI: finalizeInventory(request, scheduled)
    CI->>CI: getActiveCartForUser(user)
    CI->>CI: finalizeItemsByShippingMethod(cart, SCHEDULED)
    Note right of CI: stock finalized, items deleted, cart → checked_out
    
    PH-->>C: {order_id: xxx}
    
    Note over C: Later, admin marks as paid
    
    Admin->>OC: POST /api/v1/orders/{id}/mark-cod-paid
    OC->>OS: markCodAsPaid(order)
    OS->>DB: Transaction → status=paid, paid_at=now
    OS->>DB: Order → status=completed
    OS->>DB: recordCouponUsage(order)
    OS->>EV: PaymentSucceeded::dispatch(order)
    OC-->>Admin: 200 success
```

### 9.3 Cart Expiration

```mermaid
sequenceDiagram
    participant Cron as cron: cart:expire
    participant CI as CartInventoryService

    Note over Cron: Runs hourly
    
    Cron->>CI: expireCarts()
    CI->>DB: SELECT carts WHERE status=active AND expires_at <= now()
    
    loop per chunk of 100 carts
        CI->>CI: lockForUpdate cart + items
        CI->>CI: releaseStock for each item
        Note right of CI: reserved_quantity -= qty
        CI->>DB: DELETE cart_items
        CI->>DB: UPDATE carts SET status=expired, total_price=0
    end
```

### 9.4 Unpaid Order Timeout

```mermaid
sequenceDiagram
    participant Cron as cron: orders:cancel-unpaid
    participant OS as OrderService
    participant CI as CartInventoryService
    participant EV as Event

    Note over Cron: Runs hourly (via Kernel)
    
    Cron->>DB: SELECT orders WHERE status=pending AND created_at <= now()-72h
    
    loop per expired order
        Cron->>DB: UPDATE orders SET status=cancelled
        Cron->>DB: UPDATE transactions SET status=failed WHERE status=pending
        Cron->>EV: PaymentFailed::dispatch(order)
        
        Cron->>DB: SELECT carts WHERE user_id=order.user_id AND status=active
        alt Cart exists
            Cron->>CI: releaseCart(cart, false)
            CI->>CI: releaseStock for each item
            CI->>CI: cart.status=active, expires_at=null
        end
    end
```

---

## Phase 10: Architecture Review

### 10.1 Bugs Found (Updated vs Jul 11)

| # | Bug | Status | Severity | Fixed In |
|---|-----|--------|----------|----------|
| BUG-1 | Callback amount/currency mismatch not blocking | ✅ **FIXED** | HIGH | `OrderController.php:242-279` |
| BUG-1a | Stock leak via explicit OrderCancelled in failure path | ✅ **FIXED** | HIGH | Removed from both callback and errorCallback |
| BUG-2 | Duplicate CategoryObserver registration | ✅ **FIXED** | MEDIUM | AppServiceProvider |
| BUG-3 | Dashboard coupon analytics counting | ✅ **FIXED** | LOW | DashboardService.php |
| BUG-4 | SQLite ALTER TABLE MODIFY incompatibility | ✅ **FIXED** | MEDIUM | 2 migrations updated |

### 10.2 Architecture Weaknesses

| # | Weakness | Severity | Impact | File |
|---|----------|----------|--------|------|
| W1 | `RestoreProductInventory` has no `lockForUpdate()` | LOW | Race condition if same product is cancelled + finalized simultaneously | `app/Listeners/RestoreProductInventory.php` |
| W2 | `pluckItemsToCart` not wrapped in single transaction | LOW | Partial cart creation if one item fails | `CartController.php:151` |
| W3 | No customer-facing notifications (email/SMS/push) | MEDIUM | Customer never notified of order status changes | All listeners only log activity |
| W4 | `OrderService::clearCart()` is dead code | LOW | Not called from any controller | `OrderService.php:320` |
| W5 | App OrderResource pickup_location only returns `{id, store_name}` | LOW | Customer may want full address/phone | `app/Resources/Order/OrderResource.php` |
| W6 | Marvel OrderResource hides prices in list views | MEDIUM | Frontend may not receive price data in list endpoints | `packages/marvel/OrderResource.php` |
| W7 | RefundApproved has no listeners | HIGH | No inventory restoration, no gateway refund, no notification | EventServiceProvider |
| W8 | No retry logic for failed queue jobs | LOW | If queue fails, notification/logging is lost | All listeners use default retry (255) but no backoff |

### 10.3 Performance Analysis

| Area | Assessment | Concern Level |
|------|-----------|---------------|
| N+1 queries | None found. Eager loading used throughout | LOW |
| Duplicate queries | None found | LOW |
| Database locks | `lockForUpdate()` in all inventory operations | ✅ GOOD |
| Long transactions | `addItemsInOrder()` wraps entire flow in single transaction — includes API call for online payment? NO — API call is AFTER transaction commits | ✅ GOOD |
| Memory | `expireCarts()` uses `chunkById(100)` | ✅ GOOD |
| Queue pressure | All listeners on `medium` queue, no concurrency limit | LOW | 

### 10.4 Security Analysis

| Area | Assessment | Concern Level |
|------|-----------|---------------|
| Callback replay | Protected by `paymentId` → `verifyPayment()` → MyFatoorah API check | ✅ SAFE |
| Callback amount check | Now BLOCKING (not just warning) | ✅ FIXED |
| CSRF | API uses Sanctum tokens | ✅ SAFE |
| Mass assignment | `$fillable` on all models | ✅ SAFE |
| SQL injection | Eloquent ORM throughout | ✅ SAFE |
| Missing: Order ownership in callback | Callback doesn't verify order belongs to current user | LOW (paymentId is per-invoice) |
| Missing: HMAC/Signature on callback | MyFatoorah doesn't require it | LOW (paymentId is verified via API) |

### 10.5 Test Coverage

| Test Suite | Tests | Passing | Coverage |
|------------|-------|---------|----------|
| PaymentReconciliationTest | 26 | 26 | Reconciliation job, mismatches, dashboard endpoint |
| PaymentSystemTest | 29 | 29 | markCodAsPaid, markCashierPaid, CancelUnpaidOrders, handlers |
| PaymentCheckoutTest | 35 | 35 | Checkout flows, validation, QR, governorate shipping |
| FastShippingControllerTest | 42 | 42 | Fast shipping flows, channel filtering, products |
| EventSystemTest | 40 | 40 | All events, listeners, queued jobs, inventory restore |
| DashboardTest | 26 | 26 | All analytics endpoints, reconciliation |
| **Total** | **198** | **198** | |

**NOT TESTED (gaps):**
- Race conditions (concurrent cart modifications)
- MyFatoorah gateway HTTP integration
- Callback replay attack prevention
- Duplicate callback handling
- Governorate shipping free threshold edge cases

### 10.6 Improvement Roadmap (Updated)

| Priority | Improvement | Effort | Risk Reduction | Current Status |
|----------|-------------|--------|----------------|----------------|
| **P0** | Refund inventory restoration (RefundApproved listener) | 2 days | HIGH | ❌ Not implemented |
| **P0** | Refund gateway integration (MyFatoorah refund API) | 3-5 days | HIGH | ❌ Not implemented |
| **P1** | `RestoreProductInventory` race condition (add lockForUpdate) | 1 hour | MEDIUM | ❌ Not implemented |
| **P2** | Customer-facing notifications (email/SMS) | 3 days | MEDIUM | ❌ Not implemented |
| **P3** | Configurable cart TTL (in config/payment.php) | 30 min | LOW | ❌ Hardcoded 3 days |
| **P4** | Cart heartbeat / reservation extension API | 4 hours | MEDIUM | ❌ Not implemented |
| **P5** | `pluckItemsToCart` single transaction | 30 min | LOW | ❌ Not implemented |
| **P6** | Remove dead code `OrderService::clearCart()` | 15 min | LOW | ❌ Not implemented |

### 10.7 Production Risks (Updated)

| Risk | Likelihood | Impact | Mitigation |
|------|-----------|--------|------------|
| Payment gateway returns different amount than expected | Very Low | HIGH | ✅ Fixed — now blocking with order cancellation |
| Refund approved but stock not restored | Medium | HIGH | ❌ No listener for RefundApproved — needs implementation |
| Refund approved but gateway not called | Medium | HIGH | ❌ No gateway refund integration — needs implementation |
| Race condition in RestoreProductInventory | Low | MEDIUM | ❌ No lockForUpdate — needs fix |
| Customer never receives order status notifications | High | MEDIUM | ❌ Only admin DB notifications exist |
| Cart expires while customer is checking out | Low | MEDIUM | ❌ No heartbeat extension mechanism |
| Concurrent bulk cart add creates partial state | Low | LOW | ❌ pluckItemsToCart not wrapped in transaction |

### 10.8 Final Classification

## ✅ Production Ready with Known Gaps

The payment system is **well-architected** with:

- Clean separation of concerns (Controller → Service → Repository → Model)
- Pessimistic locking on all inventory operations
- Correct price formula (single source: `OrderCreationService::createOrder()`)
- Event-driven side effects with queued listeners
- Shared shipping logic between scheduled and fast flows
- Dynamic QR generation (no stored data)
- Callback amount/currency mismatch protection (now blocking)

### MUST Fix Before Refund Use In Production

| Issue | Severity | Effort |
|-------|----------|--------|
| RefundApproved → inventory restoration | HIGH | 2 days |
| RefundApproved → gateway refund API call | HIGH | 3-5 days |
| Order status change on refund (completed→refunded) | HIGH | 1 day |

### SHOULD Fix Before Scaling

| Issue | Severity | Effort |
|-------|----------|--------|
| Customer-facing order notifications | MEDIUM | 3 days |
| `RestoreProductInventory` lockForUpdate | LOW | 1 hour |
| Configurable cart TTL | LOW | 30 min |
| Cart heartbeat extension | LOW | 4 hours |

---

*End of Deep Audit*
