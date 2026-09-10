# Payment Lifecycle — Production Audit & Design

## Zero-Trust Source Code Audit

Audited files:
- `app/Http/Controllers/Api/General/OrderController.php` — `checkout()`, `checkoutCallback()`, `checkoutErrorCallback()`, `markCodAsPaid()`, `markCashierPaid()`
- `app/Services/General/OrderService.php` — `addItemsInOrder()`, `changeOrderStatus()`, `recordCouponUsage()`, `finalizePromotionUsageAfterPayment()`
- `app/Services/Payment/PaymentCheckoutHandler.php` — `handleOnlinePayment()`, `handleCodPayment()`, `handleCashierQrPayment()`
- `app/Services/Checkout/OrderCreationService.php` — `createOrder()`, `findPendingOrderForUser()`, `updateTransactionAmount()`
- `app/Services/General/PromotionService.php` — `incrementUsage()`, `decrementUsage()`
- `app/Services/General/CartInventoryService.php` — `finalizeCart()`, `reserveItem()`, `releaseItem()`
- `packages/marvel/src/Database/Models/Transaction.php`

---

## 1. Current Implementation — Three Payment Methods

### 1a. Online Payment (checkoutCallback)

```
POST /checkout { payment_method: 'online' }
    ↓
OrderController::checkout()
    ↓  (1) Validate request, check COD+pickup conflict
    ↓  (2) orderService->addItemsInOrder()
    ↓       ├── findPendingOrderForUser() → reuse or create order
    ↓       ├── createOrder() / updateOrder()
    ↓       ├── createOrderItems() / syncOrderItems()
    ↓       ├── finalizeOrder() → dispatches OrderCreated event
    ↓       └── SendNewOrderNotification listener
    ↓  (3) PaymentCheckoutHandler::handleOnlinePayment()
    ↓       └── Returns payment URL / redirect
    ↓
Customer redirected to payment gateway
    ↓
Payment gateway calls checkoutCallback
    ↓
OrderController::checkoutCallback()
    ↓  (1) Validate signature/hash
    ↓  (2) Update transaction: status='paid', paid_at=now()
    ↓  (3) orderService->changeOrderStatus('completed', PaymentStatus::SUCCESS)
    ↓       ├── Tries to update pending transactions to paid (no-op, already done)
    ↓       └── orderStatusManagementOnPayment()
    │             ├── event(PaymentSuccess) → GenerateInvoiceListener (queue: high)
    │             ├── event(PaymentSuccess) → SendPaymentSucceededNotification (queue: high)
    │             └── fireEventOnOrderStatus(completed)
    │                   └── event(OrderStatusChanged)
    ↓  (4) orderService->recordCouponUsage()  ← AT THIS POINT
    ↓  (5) orderService->finalizePromotionUsageAfterPayment()  ← AT THIS POINT
    ↓  (6) cartInventoryService->finalizeCart()  ← AT THIS POINT
    ↓  (7) Return success response
```

### 1b. COD Payment

```
POST /checkout { payment_method: 'cod' }
    ↓
Same checkout() flow as above (step 1-2)
    ↓
PaymentCheckoutHandler::handleCodPayment()
    ↓  (1) Create transaction: status='pending'
    ↓  (2) Return "order created, pay on delivery"
    ↓
(Admin marks as paid later)
    ↓
Admin calls markCodAsPaid()
    ↓
OrderService::markCodAsPaid()
    ↓  (1) Create transaction: status='paid', paid_at=now()
    ↓  (2) changeOrderStatus('completed', PaymentStatus::SUCCESS)
    ↓       └── PaymentSuccess event → invoice generated
    ↓  (3) recordCouponUsage()
    ↓  (4) finalizePromotionUsageAfterPayment()
    ↓  (5) finalizeCart()
```

### 1c. Cashier QR Payment

```
POST /checkout { payment_method: 'pay_at_cashier' }
    ↓
Same checkout() flow
    ↓
PaymentCheckoutHandler::handleCashierQrPayment()
    ↓  (1) Create transaction: status='pending'
    ↓  (2) Generate QR code URL for payment
    ↓  (3) Return QR URL to customer
    ↓
(Customer pays at cashier)
    ↓
Admin calls markCashierPaid()
    ↓  Identical to markCodAsPaid()
```

---

## 2. Critical Bugs Found

### BUG-PAY-1: Inventory Finalization Happens in Controller, Not in finalizeOrder()

**File:** `OrderController::checkoutCallback()` calls `cartInventoryService->finalizeCart()` AFTER payment confirmation. But `OrderCreationService::finalizeOrder()` (called in `checkout()`) only dispatches `OrderCreated` — it does NOT finalize inventory.

**Problem:** If the callback is never received (network failure, gateway timeout, server crash before callback), the order is in 'pending' status with inventory RESERVED but never DECREMENTED. `CancelUnpaidOrders` would eventually cancel and release, but:
1. CancelUnpaidOrders is NEVER scheduled (BUG-OSM-5)
2. Even if it ran, it releases reservation, not decrement stock
3. Stock was never decremented in the first place for online payments

**Result:** For successful payments where callback triggers: stock is decremented in `finalizeCart()`. For successful payments where callback never arrives: stock is NOT decremented, and the order sits in 'pending' forever (since cron doesn't run).

### BUG-PAY-2: Coupon Usage Recorded in Controller, Not in a Transaction with Payment

```php
// OrderController::checkoutCallback()
$this->orderService->recordCouponUsage($order, $user);
$this->orderService->finalizePromotionUsageAfterPayment($order, $user);
$this->cartInventoryService->finalizeCart($order, $user);
```

These three calls happen AFTER `changeOrderStatus()` and AFTER `PaymentSuccess` event fires. If any of these FAILS (e.g., database deadlock on coupon increment), the order is already marked paid, the invoice is already generated, but coupon is not consumed and inventory is not finalized.

**Result:** Partial state — order says Paid, invoice exists, but inventory and coupons are inconsistent.

### BUG-PAY-3: Promotion Usage Increments in OrderService::calculateCheckoutTotals()

**File:** `OrderService::calculateCheckoutTotals()` (called during `addItemsInOrder()`):
```php
$this->promotionService->applySelectedPromotion($cart, $selectedPromotionId, $selectedGiftProductId);
```

And `PromotionService::applySelectedPromotion()` calls `PromotionService::incrementUsage()`:

```php
public function incrementUsage($promotion)
{
    $promotion->increment('usage_per_user');
    // ...
}
```

**Problem:** Promotion usage increments BEFORE payment. If payment fails, usage is NEVER decremented (only `decrementUsage()` exists but is never called on payment failure). Result: promotion "usage_per_user" and "usage_per_promotion" are inflated by failed payment attempts.

### BUG-PAY-4: Multiple Pending Transactions Created on Retry

If a customer:
1. POST /checkout → transaction A created (pending)
2. checkouts again (same pending order reused by findPendingOrderForUser) → transaction B created (pending)
3. Payment succeeds → BOTH transactions updated to 'paid' by `changeOrderStatus()` (uses `where('pending')->update()`)

**Result:** Two 'paid' transactions for the same order. Invoice service uses `latest('paid')` → picks the wrong one.

### BUG-PAY-5: `checkoutCallback()` Has No Idempotency Check

If the payment gateway calls the callback twice (double webhook):
1. First call: transaction → paid, order → completed, inventory finalized, coupon consumed
2. Second call: `where('pending')` finds 0, `changeOrderStatus()` is protected by `canTransitionOrderStatus()` but `finalizeCart()` is NOT idempotent—it will try to finalize an already-finalized cart

**File:** `OrderController::checkoutCallback()`, no guard against double-processing.

### BUG-PAY-6: COD + Pickup Check Returns 422 with No Cleanup

```php
// OrderController::checkout()
if ($request->payment_method === 'cod' && $request->fulfillment_type === 'pickup') {
    return response()->json(['message' => 'COD is not available for pickup'], 422);
}
```

This check happens BEFORE `addItemsInOrder()`. Cart is still active with reserved inventory. Customer gets 422 error but their cart is locked with reservations. They must manually clear items and re-add.

---

## 3. Required Behavior Design

### 3a. When Each Action Should Happen

| Action | Timing | Why |
|---|---|---|
| **Transaction → paid** | When payment gateway confirms success (online) or admin confirms receipt (COD/cashier) | Atomic: one transaction = one payment attempt |
| **Order → paid** | When at least one transaction is 'paid' | Logical: order is paid when payment is received |
| **Order → completed** | When fulfillment is done + return window expired | Never auto-complete on payment (BUG-PAY-2) |
| **Inventory decrease** | When order status → 'paid' AND payment confirmed | Never reserve → decrease; decrease only on confirmation |
| **Coupon consumed** | AFTER inventory decrease succeeds, in same transaction | If inventory decrease fails, coupon should NOT be consumed |
| **Promotion usage increment** | AFTER inventory decrease succeeds AND coupon consumed, in same transaction | If anything before fails, promotion usage should NOT increment |
| **Cart disappear** | After order → 'paid' AND all post-payment actions succeed | Never delete cart before successful completion |
| **Reservation disappear** | When cart is finalized/expired | Reservation is a cart concept, not an order concept |

### 3b. Correct Order of Operations (Online Payment Callback)

```
1. Validate webhook signature
2. Load order + lock FOR UPDATE
3. Verify order.status === 'pending_payment'
4. Verify no existing paid transaction for this gateway_txn_id (idempotency)
5. BEGIN TRANSACTION
6.   Update transaction: status='paid', paid_at=now()
7.   Update order: payment_status='paid', paid_at=now()
8.   Decrement inventory: products.stock_quantity -= qty
9.   Decrement variant stock
10.  Increment products.sold_quantity
11.  Increment promotion usage (if promotion exists)
12.  Mark coupon as consumed (if coupon exists)
13.  Update order: status='paid'
14. COMMIT
15. Dispatch PaymentSucceeded event
      ├── GenerateInvoiceListener (queue: high)
      ├── SendPaymentSucceededNotification (queue: high)
      └── CartFinalizationListener (queue: medium)
16. Return success
```

### 3c. Correct Order for COD/Cashier

```
1. Admin clicks "Mark as Paid"
2. Load order + lock FOR UPDATE
3. Verify order.status === 'pending_payment'
4. BEGIN TRANSACTION
5.   Create transaction: status='paid', paid_at=now()
6.   Update order: payment_status='paid', paid_at=now()
7.   Decrement inventory
8.   Increment promotion usage
9.   Mark coupon as consumed
10.  Update order: status='paid'
11. COMMIT
12. Dispatch PaymentSucceeded event
13. Return success
```

### 3d. Correct Order for Payment Failure

```
1. Webhook/callback confirms failure
2. BEGIN TRANSACTION
3.   Update transaction: status='failed', error=reason
4.   Update order: payment_status='failed'
5.   DO NOT change order.status (customer can retry)
6.   DO NOT release inventory (reservation stays for retry)
7.   DO NOT touch coupon usage
8.   DO NOT touch promotion usage
9. COMMIT
10. Dispatch PaymentFailed event (notification only)
```

### 3e. Payment Timeout / Expiry

```
1. Cron runs (must be scheduled!)
2. Find orders: status='pending_payment', created_at < now() - N hours
3. FOR EACH order:
4.   BEGIN TRANSACTION
5.     Lock order FOR UPDATE
6.     Verify still 'pending_payment'
7.     Release cart reservation: expireCartItems()
8.     Mark cart as expired (not deleted — customer can recover)
9.     Update order: status='expired'
10.    Update order: payment_status='expired'
11.  COMMIT
12.  Dispatch OrderCancelled event + PaymentFailed event
```

Payment timeout does NOT decrement coupon usage or promotion usage because they were never consumed (per the correct design — they should only be consumed on payment success, not during checkout).
