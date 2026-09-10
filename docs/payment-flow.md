# Payment Flow Audit

## Table of Contents

1. [Payment Methods Overview](#1-payment-methods-overview)
2. [Transaction States](#2-transaction-states)
3. [Online Payment Flow](#3-online-payment-flow)
4. [COD Payment Flow](#4-cod-payment-flow)
5. [Pay at Cashier Payment Flow](#5-pay-at-cashier-payment-flow)
6. [Payment Callback (Online)](#6-payment-callback-online)
7. [Payment Error Callback](#7-payment-error-callback)
8. [Mark COD as Paid](#8-mark-cod-as-paid)
9. [Mark Cashier as Paid](#9-mark-cashier-as-paid)
10. [Pending Payment Timeout](#10-pending-payment-timeout)
11. [Retry Scenarios](#11-retry-scenarios)
12. [Critical Questions Answered](#12-critical-questions-answered)

---

## 1. Payment Methods Overview

| Method | Enum Value | Flow |
|--------|-----------|------|
| **Online** | `online` | MyFatoorah invoice → redirect → callback → verify → finalize |
| **COD** | `cod` | Order created immediately → transaction pending → admin marks paid |
| **Pay at Cashier** | `pay_at_cashier` | Order created → QR generated → customer pays at store → admin marks paid |

**Constraints:**
- COD + pickup → rejected (`COD_NOT_AVAILABLE_FOR_PICKUP`)
- Pay at Cashier + delivery → rejected (validation: `fulfillment_type.in` must be pickup)
- Online + any fulfillment type → accepted

---

## 2. Transaction States

| State | Description |
|-------|-------------|
| `pending` | Created, waiting for payment |
| `paid` | Payment confirmed (for online: gateway verified; for COD/cashier: admin marked) |
| `failed` | Payment failed or cancelled |

### Transaction Fields

```php
Transaction {
    id, order_id, invoice_id (MyFatoorah InvoiceId), payment_method, 
    user_id, uuid (auto-generated UUID), status, amount, currency,
    gateway_transaction_id (MyFatoorah PaymentId), 
    gateway_response (JSON), error_message, qr_code_url, paid_at
}
```

---

## 3. Online Payment Flow

### Entry: `OrderController::checkout()` → online path

```
File: app/Http/Controllers/Api/General/OrderController.php:106
```

1. **Order already created** in `addItemsInOrder()` with status='pending'
2. `$orderPrice = round((float) $order->total_price, 2)`
3. If `$orderPrice <= 0`: return error (FILED_TO_CREATE_ORDER_TRY_AGAIN)
4. Delegate to `PaymentCheckoutHandler::handleOnlinePayment()`

### handleOnlinePayment()

```
File: app/Services/Payment/PaymentCheckoutHandler.php:25
```

1. `PaymentGatewayFactory::make($gateway)` → MyFatoorahGateway instance
2. `$gateway->createInvoice($order, $amount, $callbackUrl, $errorUrl)`
   - Builds MyFatoorah request payload (InvoiceValue, CustomerName, CallBackUrl, ErrorUrl, etc.)
   - Calls `MyfatoraService::createInvoice($data)` (HTTP POST to MyFatoorah API)
   - On success: returns `GatewayResult` with `redirectUrl` + `gatewayTransactionId` (InvoiceId)
   - On failure: returns `GatewayResult` with `success=false` + `errorMessage`
3. If failed: return 500 error response
4. **Create Transaction record:**
   ```php
   Transaction::create([
       'order_id' => $order->id,
       'user_id' => $request->user()->id,
       'invoice_id' => $result->gatewayTransactionId,   // MyFatoorah InvoiceId
       'payment_method' => $gateway,
       'status' => 'pending',
       'amount' => $amount,
       'currency' => config('payment.default_currency', 'EGP'),
       'gateway_transaction_id' => $result->gatewayTransactionId,
       'gateway_response' => $result->rawResponse,
   ]);
   ```
5. Return success response with `{ url: $result->redirectUrl }` → frontend redirects user to MyFatoorah payment page

### Key Findings

1. **Two different IDs from MyFatoorah** — `invoice_id` and `gateway_transaction_id` are set to the same `InvoiceId` at creation time. But at callback time, `PaymentId` is used. The code handles both by searching both fields.
2. **Transaction created AFTER order commit** — The transaction creation is in a separate request from the order creation transaction. If the gateway call fails, the order still exists (pending). No rollback of the order.
3. **No cart modification during online payment** — Cart remains active with reserved inventory.

---

## 4. COD Payment Flow

### Entry: `OrderController::checkout()` → cod path

```
File: app/Http/Controllers/Api/General/OrderController.php:114
```

### handleCodPayment()

```
File: app/Services/Payment/PaymentCheckoutHandler.php:77
```

1. **Create Transaction record:**
   ```php
   Transaction::create([
       'order_id' => $order->id,
       'user_id' => $request->user()->id,
       'payment_method' => 'cod',
       'status' => 'pending',
       'amount' => $order->total_price,
       'currency' => config('payment.default_currency', 'EGP'),
   ]);
   ```
2. Return success response with `{ order_id: ... }`
3. **Cart remains active** — Inventory stays reserved

### Key Findings

4. **COD = two-phase flow** — Order created immediately. Inventory reserved in cart. Nothing finalized until admin manually marks as paid.
5. **No inventory deduction at order time** — The `ManageProductInventory` listener (Marvel event) decrements stock, but that's for the deprecated Marvel OrderCreated event. The App's OrderCreated event only sends notifications. Inventory decrement for COD happens only when `markCodAsPaid()` is called.

---

## 5. Pay at Cashier Payment Flow

### Entry: `OrderController::checkout()` → pay_at_cashier path

```
File: app/Http/Controllers/Api/General/OrderController.php:118
```

### handleCashierQrPayment()

```
File: app/Services/Payment/PaymentCheckoutHandler.php:97
```

1. **Create Transaction record:**
   ```php
   Transaction::create([
       'order_id' => $order->id,
       'user_id' => $request->user()->id,
       'payment_method' => 'pay_at_cashier',
       'status' => 'pending',
       'amount' => $order->total_price,
       'currency' => config('payment.default_currency', 'EGP'),
   ]);
   ```
2. **Generate QR Code** via `CashierQrService::generateBase64DataUri($transaction)`
3. Return success with `{ order_id, transaction_uuid, qr_code }`
4. **Cart remains active** — Inventory stays reserved

### Key Finding

6. **Same two-phase flow as COD** — Order + pending transaction created immediately. Inventory finalized only when admin marks paid.

---

## 6. Payment Callback (Online)

### Entry: User redirected back from MyFatoorah

```
GET /api/v1/checkout/callback?paymentId=12345
or
POST /api/v1/checkout/callback with body { paymentId: 12345 }
```

### Code Path

```
File: app/Http/Controllers/Api/General/OrderController.php:170
```

#### Step 1: Extract Payment ID

```php
$paymentId = $request->query('paymentId', $request->input('paymentId'));
if (!$paymentId) {
    return $this->apiResponse(MISSING_PAYMENT_ID, 400, false);
}
```

#### Step 2: Find Transaction (before verification)

```php
$transaction = Transaction::where('gateway_transaction_id', $paymentId)
    ->orWhere('invoice_id', $paymentId)
    ->first();
```

#### Step 3: Initialize Gateway

```php
$gatewayName = $transaction?->payment_method ?? 'myfatoorah';
$gateway = $this->paymentGatewayFactory->make($gatewayName);
```

#### Step 4: Verify Payment with Gateway

```php
$result = $gateway->verifyPayment($paymentId);
// Calls MyFatoorah API to check payment status
// Returns success=true + gatewayTransactionId if paid
```

#### Step 5: Find/Verify Transaction After Verification

```php
$verifiedInvoiceId = $result->gatewayTransactionId;

if (!$transaction) {
    $transaction = Transaction::where('gateway_transaction_id', $verifiedInvoiceId)
        ->orWhere('invoice_id', $verifiedInvoiceId)
        ->first();
}

$order = $transaction?->order;
```

#### Step 6: Handle Payment Failure

If `!$result->success`:
1. Update transaction: status='failed', gateway_response, error_message
2. Dispatch `PaymentFailed` event
3. Redirect user to frontend payment-failed page

#### Step 7: Handle Order Not Found

If `$result->success` but `!$order`:
- Mobile: return JSON success
- Web: redirect to payment-success page

#### Step 8: Amount & Currency Validation

```php
if ($result->amount !== null && abs($result->amount - $order->total_price) > 0.01) {
    $hasMismatch = true;
}
if ($result->currency !== null && $result->currency !== 'EGP') {
    $hasMismatch = true;
}
```

If mismatch: update transaction with error, dispatch `PaymentFailed`, redirect to failed page.

#### Step 9: Process Successful Payment (With Lock)

```php
DB::transaction(function () use ($order, $transaction, $paymentId, $verifiedInvoiceId, $result, &$processed) {
    // Lock transaction row
    $lockedTransaction = Transaction::where('gateway_transaction_id', $paymentId)
        ->orWhere('invoice_id', $paymentId)
        ->lockForUpdate()
        ->first();
    
    // Lock order row
    $lockedOrder = $lockedTransaction->order()->lockForUpdate()->first();
    
    // Idempotency check
    if ($lockedTransaction->status === 'paid' && $lockedOrder->status === 'completed') {
        return; // Already processed
    }
    
    // Mark transaction as paid
    $lockedTransaction->update([
        'status' => 'paid',
        'gateway_response' => $result->rawResponse,
        'paid_at' => now(),
    ]);
    
    // Finalize cart items for this shipping method
    $cart = $this->cartInventoryService->getActiveCartForUser($user);
    if ($cart) {
        $this->cartInventoryService->finalizeItemsByShippingMethod($cart, $shippingMethod);
    }
    
    // Record promotion usage
    $this->orderService->finalizePromotionUsageAfterPayment($lockedOrder);
    
    // Change order to completed + record coupon usage
    $this->orderService->changeOrderStatus($lockedTransaction->invoice_id, 'completed');
    
    $processed = true;
});
```

#### Step 10: Dispatch PaymentSucceeded

```php
if ($processed) {
    event(new PaymentSucceeded($order->fresh()));
}
```

#### Step 11: Return Response

- Mobile: JSON success with order_id
- Web: redirect to frontend payment-success page

### Key Findings

7. **Idempotency via lock** — The combo `lockForUpdate` on transaction + status check prevents double-processing.
8. **Dual lookup** — Transaction is searched by both `gateway_transaction_id` and `invoice_id`, twice (before and after verification). This handles the case where the initial lookup fails (e.g., callback called with PaymentId but we stored InvoiceId).
9. **`changeOrderStatus('completed')` records coupon usage** — Inside this method, when status → 'completed', `recordCouponUsage()` is called.
10. **Promotion usage recorded AFTER cart finalization** — `finalizePromotionUsageAfterPayment()` is called before `changeOrderStatus()`. The order matches the dependency order (promotion before coupon).
11. **Missing: `cart.total_price` update after finalization** — `finalizeItemsByShippingMethod()` updates cart status + deletes items but doesn't reset `coupon` on the cart (if items remain with different shipping method, coupon persists).

---

## 7. Payment Error Callback

### Entry: User redirected from MyFatoorah on error/cancellation

```
GET /api/v1/checkout/error-callback?paymentId=12345
```

### Code Path

```
File: app/Http/Controllers/Api/General/OrderController.php:362
```

1. Extract paymentId
2. Initialize gateway
3. Verify payment (may succeed despite being error callback)
4. Find transaction + order
5. **Lock transaction:**
   ```php
   DB::transaction(function () use ($transaction, ...) {
       $lockedTransaction = Transaction::where(...)->lockForUpdate()->first();
       if ($lockedTransaction->status === 'failed') return; // Already failed
       
       $lockedTransaction->update([
           'status' => 'failed',
           'gateway_response' => $result->rawResponse,
           'error_message' => $errorMessage,
       ]);
   });
   ```
6. Dispatch `PaymentFailed` event
7. Return redirect or JSON error

### Key Findings

12. **Gateway verification happens regardless** — Even in the error callback, the gateway is called to verify the actual payment status. This is important because MyFatoorah may redirect to the error URL even for successful payments in some configurations.
13. **Idempotent failure** — `if ($lockedTransaction->status === 'failed') return;` prevents double-processing.

---

## 8. Mark COD as Paid

### Entry: Admin action

```
POST /api/v1/checkout/cod/{orderId}/mark-paid
Middleware: auth:sanctum, permission:update-order-status
```

### Code Path

```
File: app/Http/Controllers/Api/General/OrderController.php:125
OrderController::markCodAsPaid($orderId)
  → OrderService::markCodAsPaid($order)
```

### markCodAsPaid()

```
File: app/Services/General/OrderService.php:567
```

```php
DB::transaction(function () use ($order) {
    // Find pending COD transaction
    $transaction = $order->transactions()
        ->where('payment_method', 'cod')
        ->where('status', 'pending')
        ->latest()
        ->lockForUpdate()
        ->first();
    
    if (!$transaction) {
        throw new RuntimeException(__('checkout.no_pending_cod_transaction'));
    }
    
    // Mark transaction as paid
    $transaction->update(['status' => 'paid', 'paid_at' => now()]);
    
    // Mark order as completed
    $order->update(['status' => 'completed']);
    
    // Record coupon usage
    $this->recordCouponUsage($order);
    
    // Record promotion usage
    $this->finalizePromotionUsageAfterPayment($order);
    
    // Finalize inventory
    $this->finalizeInventoryAfterPayment($order);
    
    // Dispatch event
    event(new PaymentSucceeded($order));
});
```

### finalizeInventoryAfterPayment()

```
File: app/Services/General/OrderService.php:629
```

```php
$cart = Cart::query()
    ->where('user_id', $order->user_id)
    ->where('status', 'active')
    ->first();

if ($cart) {
    $shippingMethod = $order->shipping_method ?? ShippingMethod::SCHEDULED;
    $this->cartInventoryService->finalizeItemsByShippingMethod($cart, $shippingMethod);
}
```

### Key Findings

14. **Single transaction** — Unlike the online callback (which has a complex multi-step flow), COD mark-paid does everything in one DB transaction.
15. **Find cart by query** — Does NOT use `$user->cart` relationship. Queries for `status='active'` cart directly.
16. **Inventory finalized** via `finalizeItemsByShippingMethod()` — same as online callback.
17. **No idempotency check on order status** — If the order is already 'completed' or 'cancelled', `markCodAsPaid` would:
    - Find the pending transaction → fail (transaction already 'paid')
    - OR: if transaction was already updated, find nothing → throw

---

## 9. Mark Cashier as Paid

### Entry: Admin action

```
POST /api/v1/checkout/cashier/{orderId}/mark-paid
Middleware: auth:sanctum, permission:update-order-status
```

### Code Path

Identical to `markCodAsPaid()` but searches for `payment_method = 'pay_at_cashier'`:

```php
$transaction = $order->transactions()
    ->where('payment_method', 'pay_at_cashier')
    ->where('status', 'pending')
    ->latest()
    ->lockForUpdate()
    ->first();
```

### Key Finding

18. **Identical logic to COD** — The only difference is the payment method filter. Consider unifying into a single method.

---

## 10. Pending Payment Timeout

### Command: `orders:cancel-unpaid`

```
File: app/Console/Commands/CancelUnpaidOrders.php
```

```php
$cutoff = now()->subHours(config('payment.order_timeout_hours', 72));

$orders = Order::query()
    ->where('status', 'pending')
    ->where('created_at', '<=', $cutoff)
    ->cursor();
```

For each pending order:
1. `$order->update(['status' => 'cancelled'])`
2. `$order->transactions()->where('status', 'pending')->update(['status' => 'failed'])`
3. Dispatch `OrderCancelled` event
4. Dispatch `PaymentFailed` event
5. **Expire cart** via `CartInventoryService::expireSingleCart($cart)`:
   - Release reserved stock
   - Delete cart items
   - Cart → status='expired'

### Key Findings

19. **Uses `cursor()` instead of `chunk()`** — For large datasets, `cursor()` holds the connection open for the entire duration. Could be problematic for millions of pending orders.
20. **No lock on orders** — The `Order::query()->where(...)->cursor()` doesn't use `lockForUpdate`. Between the cursor read and the update, another process (e.g., payment callback) could have already processed the order.
21. **Timeout = 72 hours** — Configurable via `config('payment.order_timeout_hours')`.

---

## 11. Retry Scenarios

### Online Payment Failed

1. Cart: active, items reserved, coupon on cart
2. User sees "payment failed" page
3. User can:
   - Click "retry" → frontend re-submits `POST /checkout` → new order created (or pending updated) → new payment URL
   - Or: abandon → cart eventually expires (3 days TTL)

### Online Payment Cancelled

1. Same as failed — cart untouched, user can retry

### Online Payment Expired

1. `orders:cancel-unpaid` fires → order cancelled, cart expired
2. User must re-add items to cart and start fresh

### COD: Never Paid

1. Order sits in 'pending' forever until `orders:cancel-unpaid` fires (72h)
2. After cancel: cart expired, inventory released

### Pay at Cashier: Never Paid

1. Same as COD — pending until timeout

---

## 12. Critical Questions Answered

### Exactly when should order become Pending?

**At order creation time** — `OrderService::addItemsInOrder()` → `OrderCreationService::createOrder()` sets `status = 'pending'`. This happens before any payment processing.

### Exactly when should order become Paid?

- **Online**: After gateway callback verifies payment AND amount/currency match → `changeOrderStatus('completed')`
- **COD**: When admin calls `markCodAsPaid()` → `$order->update(['status' => 'completed'])`
- **Pay at Cashier**: When admin calls `markCashierPaid()` → `$order->update(['status' => 'completed'])`

**CURRENT BEHAVIOR**: Order status goes directly to 'completed' (not 'paid'). There is no 'paid' order status. The `payment_status` attribute is computed from transaction status.

### Exactly when should inventory finalize?

**CURRENT BEHAVIOR**:
- **Online**: In callback, inside the lock transaction → `finalizeItemsByShippingMethod()` → `finalizeStock()`
- **COD**: When admin marks paid → `finalizeInventoryAfterPayment()` → same method
- **Pay at Cashier**: When admin marks paid → `finalizeInventoryAfterPayment()` → same method

**Correctness**: Inventory is finalized → stock_quantity decremented, reserved_quantity decremented, sold_quantity incremented. This is the point of no return.

### Exactly when should promotion usage finalize?

**CURRENT BEHAVIOR**:
- **Online**: After cart finalization, before order status change → `finalizePromotionUsageAfterPayment()` → `incrementUsage()`
- **COD/Cashier**: At mark-paid → `finalizePromotionUsageAfterPayment()`

### Exactly when should coupon usage finalize?

**CURRENT BEHAVIOR**:
- **Online**: Inside `changeOrderStatus('completed')` → `recordCouponUsage()`
- **COD/Cashier**: Direct call at mark-paid → `recordCouponUsage()`

**Policy**: Coupon quota consumed only on payment success. NEVER returned on cancellation/refund.

### Exactly when should cart delete?

**CURRENT BEHAVIOR**:
- **Online**: In callback → `finalizeItemsByShippingMethod()` → deletes items, sets status='checked_out' when all items gone
- **COD**: At mark-paid → same
- **Pay at Cashier**: At mark-paid → same

**Cart is NEVER hard-deleted**. It transitions to 'checked_out' status.

### Exactly when should invoice generate?

**CURRENT BEHAVIOR**: No automated invoice generation. The `invoice_id` on transactions is MyFatoorah's InvoiceId, not a local invoice number. PDF invoices exist but are downloaded via token, not auto-generated.

---

## Appendix: Payment Flow Decision Matrix

| Payment Method | Order Created | Transaction Created | Inventory Finalized | Coupon Consumed | Promotion Consumed | Cart Status |
|----------------|--------------|-------------------|--------------------|----------------|-------------------|-------------|
| Online (pending) | pending | pending | reserved (cart) | not consumed | not consumed | active |
| Online (success) | completed | paid | finalized | consumed | consumed | checked_out |
| Online (failed) | pending | failed | reserved (cart) | not consumed | not consumed | active |
| Online (cancelled) | pending | failed | reserved (cart) | not consumed | not consumed | active |
| Online (expired) | cancelled | failed | released | not consumed | decremented | expired |
| COD (pending) | pending | pending | reserved (cart) | not consumed | not consumed | active |
| COD (paid) | completed | paid | finalized | consumed | consumed | checked_out |
| COD (unpaid timeout) | cancelled | failed | released | not consumed | decremented | expired |
| Pay at Cashier (pending) | pending | pending | reserved (cart) | not consumed | not consumed | active |
| Pay at Cashier (paid) | completed | paid | finalized | consumed | consumed | checked_out |
| Pay at Cashier (unpaid) | cancelled | failed | released | not consumed | decremented | expired |
