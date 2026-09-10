# Phase 6: Payment Lifecycle

## Executive Summary

Three payment methods are supported: Online (MyFatoorah), Cash on Delivery (COD), and Pay at Cashier. All three start with order creation and a pending transaction, then converge on the same finalization path after payment confirmation. A reconciliation job runs periodically on the `low` queue to detect mismatches between local state and the gateway. Refunds are handled through the gateway with listeners for inventory restoration and credit note generation.

---

## A) Online Payment (MyFatoorah)

### Flow Diagram

```
OrderController::checkout()
  │
  ├─ orderService->addItemsInOrder($request)
  │    └─ returns Order (status=pending)
  │
  └─ paymentCheckoutHandler->handleOnlinePayment($request, $order, $amount, $gateway)
       │
       ├─ PaymentGatewayFactory::make('myfatoorah')
       │    └─ MyFatoorahGateway
       │
       ├─ gateway->createInvoice($order, $amount, $callbackUrl, $errorUrl)
       │    └─ MyfatoraService::createInvoice($data)
       │         ├─ POST {baseUrl}/SendPayment
       │         └─ Returns Data.InvoiceURL, Data.InvoiceId
       │
       ├─ Transaction::create([
       │    'order_id' => $order->id,
       │    'invoice_id' => $invoiceId,
       │    'payment_method' => 'myfatoorah',
       │    'status' => 'pending',
       │    'amount' => $amount,
       │    'currency' => config('payment.default_currency', 'EGP'),
       │    'gateway_transaction_id' => $invoiceId,
       │ ])
       │
       └─ Returns { url: $invoiceURL } → frontend redirects to MyFatoorah
```

### Callback (checkoutCallback)

```
POST /checkout/callback?paymentId={paymentId}
  │
  ├─ Find Transaction by gateway_transaction_id or invoice_id
  ├─ gateway->verifyPayment($paymentId)
  │    └─ MyfatoraService::checkInvoice($data)
  │         ├─ POST {baseUrl}/GetPaymentStatus
  │         └─ Returns InvoiceStatus, InvoiceValue, DisplayCurrencyIso
  │
  ├─ [FAILURE PATH]
  │    ├─ Update transaction: status='failed', error_message, gateway_response
  │    ├─ event(new PaymentFailed($order))
  │    └─ Redirect to /payment/failed
  │
  ├─ [AMOUNT MISMATCH > 0.01]
  │    ├─ Log warning
  │    ├─ event(new PaymentFailed($order))
  │    └─ Redirect to /payment/failed
  │
  ├─ [CURRENCY MISMATCH]
  │    ├─ Check against config('payment.default_currency')
  │    ├─ event(new PaymentFailed($order))
  │    └─ Redirect to /payment/failed
  │
  └─ [SUCCESS PATH]
       └─ DB::transaction (with lockForUpdate)
            ├─ Re-find Transaction (lockForUpdate) ← idempotency check
            ├─ Re-find Order via transaction (lockForUpdate)
            ├─ IF order->status !== 'pending' → return (duplicate callback protection)
            ├─ Update transaction: status='paid', paid_at=now(), gateway_response
            ├─ Update order: paid_at=now()
            │   └─ Also sets payment_status=payment-success (if column exists)
            ├─ finalizeItemsByShippingMethod($cart, $shippingMethod)
            │   OR deductStockForOrder($order)
            ├─ finalizePromotionUsageAfterPayment($order)
            │   └─ promotionService->incrementUsage()
            ├─ orderService->changeOrderStatus($invoiceId, 'completed')
            │   └─ (recordCouponUsage, transaction->paid, OrderStatusChanged event)
            └─ event(new PaymentSucceeded($order))
                 ├─ SendPaymentSucceededNotification (queue:medium)
                 └─ GenerateInvoiceListener (queue:high, afterCommit, 5 retries)
```

### Callback Idempotency

```php
// In checkoutCallback:
$lockedOrder = $lockedTransaction->order()->lockForUpdate()->first();

if ($lockedOrder->status !== 'pending') {
    return;  // Already processed
}
```

The order status check (`!== 'pending'`) prevents the callback from processing the same payment twice. The `lockForUpdate` ensures serialized access.

### Amount Mismatch Tolerance

```php
if ($result->amount !== null && abs((float) $result->amount - (float) $order->total_price) > 0.01) {
    $hasMismatch = true;
}
```

Tolerance of 0.01 (1 cent/piastre) accounts for floating-point rounding.

### Currency Mismatch

```php
if (!$hasMismatch && $result->currency !== null && $result->currency !== config('payment.default_currency', 'EGP')) {
    $hasMismatch = true;
}
```

Checks against the configured default currency (EGP).

### Mobile Response

When `?type=mobile` is appended to the callback URL, the response is JSON instead of a redirect:

```php
if (request()->type === 'mobile') {
    return $this->apiResponse(CHECKOUT_SUCCESSFUL, 200, true, [
        'status' => 'success',
        'message' => __(PAYMENT_SUCCESSFUL),
        'payment_id' => $paymentId,
    ]);
}
```

### Error Callback

```
GET /checkout/error-callback?paymentId={paymentId}
  │
  ├─ gateway->verifyPayment($paymentId)
  ├─ DB::transaction
  │    └─ lockForUpdate → update transaction status='failed'
  ├─ event(new PaymentFailed($order))
  └─ Redirect to /payment/failed
```

**BUG:** The errorCallback always calls `verifyPayment` even though the user was already on the error page. If MyFatoorah returns a non-error response (e.g., `Paid` status because the payment actually succeeded), the errorCallback incorrectly marks the transaction as `failed`. See BUG-4.

---

## B) Cash on Delivery (COD)

### Flow Diagram

```
OrderController::checkout() with payment_method='cod'
  │
  ├─ orderService->addItemsInOrder($request)  ← order created, status=pending
  │
  └─ paymentCheckoutHandler->handleCodPayment($request, $order)
       └─ Transaction::create([
            'order_id' => $order->id,
            'payment_method' => 'cod',
            'status' => 'pending',
            'amount' => $order->total_price,
            'currency' => config('payment.default_currency', 'EGP'),
         ])
         └─ Returns { order_id } to frontend
```

### Mark as Paid (Admin)

```
POST /checkout/cod/{orderId}/mark-paid
  middleware: auth:sanctum, permission:update-order-status
  │
  └─ OrderController::markCodAsPaid($orderId)
       └─ orderService->markCodAsPaid($order)
            └─ DB::transaction
                 ├─ Transaction::where(payment_method='cod', status='pending')
                 │    ->latest()->lockForUpdate()->first()
                 ├─ Update transaction: status='paid', paid_at=now()
                 ├─ Update order:
                 │    status='completed'
                 │    payment_status='payment-success' (if column exists)
                 │    completed_at=now()
                 │    fulfillment_status='processing' (if column exists, only if current=pending)
                 ├─ recordCouponUsage($order)
                 ├─ finalizePromotionUsageAfterPayment($order)
                 │    └─ promotionService->incrementUsage()
                 ├─ finalizeInventoryAfterPayment($order)
                 └─ event(new PaymentSucceeded($order))
```

### Finalization

The same finalization path as online payment: inventory is finalized (either via cart or direct deduction), promotion usage is incremented, coupon usage is recorded, and invoice is generated (via `GenerateInvoiceListener` queued by `PaymentSucceeded`).

---

## C) Pay at Cashier

### Flow Diagram

```
OrderController::checkout() with payment_method='pay_at_cashier'
  │
  ├─ orderService->addItemsInOrder($request)  ← order created, status=pending
  │
  └─ paymentCheckoutHandler->handleCashierQrPayment($request, $order)
       ├─ Transaction::create([
       │    'order_id' => $order->id,
       │    'payment_method' => 'pay_at_cashier',
       │    'status' => 'pending',
       │    'amount' => $order->total_price,
       │    'currency' => config('payment.default_currency', 'EGP'),
       │    'uuid' => (auto-generated UUID),
       │  ])
       ├─ CashierQrService::generateBase64DataUri($transaction)
       │    └─ QR code encodes: { "transaction": $transaction->uuid }
       └─ Returns { transaction_uuid, qr_code (base64 SVG) }
```

### QR Code Details

- Generated as SVG
- Payload: `{"transaction": "<uuid>"}`
- Configurable size: `config('payment.pay_at_cashier.size')` (default 50)
- ECC Level: L (lowest — sufficient for small payload)
- Available via API: `GET /checkout/transaction-qr/{uuid}` returns raw SVG

### Mark as Paid (Cashier)

```
POST /checkout/cashier/{orderId}/mark-paid
  middleware: auth:sanctum, permission:update-order-status
  │
  └─ OrderController::markCashierPaid($orderId)
       └─ orderService->markCashierPaid($order)
            └─ Identical to markCodAsPaid but scoped to 'pay_at_cashier'
```

Uses the exact same finalization code as COD.

---

## D) Payment Reconciliation

### Job: `PaymentReconciliationJob`

| Property | Value |
|---|---|
| Queue | `low` |
| Trigger | Scheduled or manual dispatch |
| Transaction scope | All transactions with `gateway_transaction_id` where `status != 'failed'` |
| Iterator | `cursor()` (lazy collection, memory-safe) |
| Table | `payment_reconciliation_results` |

### Reconciliation Logic

For each candidate transaction:

1. **Resolve gateway** from `transaction->payment_method` via `PaymentGatewayFactory`
2. **Verify with gateway**: `gateway->verifyPayment($gatewayTransactionId)`
3. **Compare amount**: `abs(local - gateway) > 0.01` → mismatch
4. **Compare currency**: `local !== gateway` → mismatch
5. **Compare payment status**: gateway says paid but local says not (or vice versa) → mismatch
6. **Compare order status**: gateway says paid but order not `completed` → mismatch
7. **Compare refund status**: **stubbed** → always returns `false`

### Mismatch Recording

```php
PaymentReconciliationResult::create([
    'transaction_id' => $transaction->id,
    'order_id' => $order->id,
    'gateway' => $transaction->payment_method ?? 'unknown',
    'mismatch_type' => $type,  // 'amount', 'currency', 'payment_status', 'order_status'
    'expected_value' => $expected,
    'actual_value' => $actual,
]);
```

### REFUND Comparison is Stubbed

```php
private function compareRefundStatus(...): bool
{
    return false;  // Always returns no mismatch
}
```

---

## E) Refund (Payment Gateway)

### Flow

```
Admin initiates refund → calls MyFatoorahGateway::refund($order, $amount)
  │
  └─ myfatoraService->makeRefund($data)
       ├─ POST {baseUrl}/MakeRefund
       ├─ Key: gateway_transaction_id, KeyType: PaymentId
       └─ Returns RefundId, RefundStatus
```

### RefundApproved Event

```php
class RefundApproved
{
    public $refund;  // Marvel\Database\Models\Refund
}
```

### Listeners (all queue:medium, afterCommit)

| Listener | Action |
|---|---|
| `RatingRemoved` | Removes product rating |
| `RestoreInventoryOnRefund` | Restores stock (locked by `inventory_restored_at`) |
| `GenerateCreditNoteOnRefund` | Generates credit note, marks invoice as `corrected` |

**All three registered in `Marvel\Providers\EventServiceProvider`:**

```php
RefundApproved::class => [
    RatingRemoved::class,
    \App\Listeners\RestoreInventoryOnRefund::class,
    \App\Listeners\GenerateCreditNoteOnRefund::class,
],
```

### RestoreInventoryOnRefund Detail

- Only restores if `$order->status !== 'cancelled'` (if already cancelled, inventory was already restored by `RestoreProductInventory`)
- Uses `inventory_restored_at` guard (same as cancel restore)
- Skips gift items
- Locks product/variant rows with `lockForUpdate`

### GenerateCreditNoteOnRefund Detail

- Finds latest active invoice (`generated`, `ready`, `verified`, `downloaded`, `printed`)
- Generates credit note via `CreditNoteService::generateForRefund()`
- Marks invoice as `corrected`
- Records timeline entry

---

## Database Tables

### `transactions`

| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| order_id | bigint FK | |
| user_id | bigint FK | |
| invoice_id | varchar nullable | Gateway invoice ID (MyFatoorah) |
| uuid | uuid | Auto-generated, used for QR payload |
| payment_method | varchar | `cod`, `pay_at_cashier`, `myfatoorah` |
| status | varchar | `pending`, `paid`, `failed` |
| amount | decimal | |
| currency | varchar | Default: EGP |
| gateway_transaction_id | varchar nullable | |
| gateway_response | json nullable | Raw gateway response |
| error_message | text nullable | |
| qr_code_url | text nullable | |
| paid_at | datetime nullable | |

### `payment_reconciliation_results`

| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| transaction_id | bigint FK | |
| order_id | bigint FK | |
| gateway | varchar | e.g. `myfatoorah` |
| mismatch_type | varchar | `amount`, `currency`, `payment_status`, `order_status` |
| expected_value | text | |
| actual_value | text | |
| notes | text nullable | |
| resolved_at | datetime nullable | |

---

## Problems

### P6-C1: BUG-10 — Dual event registration for OrderCancelled

`RestoreProductInventory` is registered for both `App\Events\OrderCancelled` and `Marvel\Events\OrderCancelled` in `App\Providers\EventServiceProvider`. Only `App\Events\OrderCancelled` is fired from `changeOrderStatus`. The Marvel registration is dead code that may cause double-inventory restoration if any other code path fires the Marvel event.

**Location:** `App\Providers\EventServiceProvider:72-78`

### P6-C2: BUG-4 — errorCallback always calls verifyPayment

The `checkoutErrorCallback` method (route: `api.checkout.errorCallback`) calls `gateway->verifyPayment($paymentId)` unconditionally. If the user reaches the error page but the payment actually succeeded at the gateway (e.g., MyFatoorah returns `Paid`), the error callback will:
1. Verify and get a `success=true` result
2. Still mark the transaction as `failed` (because it's in the error path)
3. This creates a permanent mismatch: gateway says paid, local says failed

**Location:** `OrderController::checkoutErrorCallback():397`

### P6-C3: Reconciliation REFUND comparison is stubbed

`compareRefundStatus()` always returns `false`. Any refund status mismatch between the gateway and local database is silently ignored.

**Location:** `PaymentReconciliationJob:220-223`

### P6-C4: COD/Cashier create transaction in handle* but deduct stock in changeOrderStatus

For COD and cashier, the transaction is created with `status=pending` immediately during checkout, but inventory is not finalized until `markCodAsPaid`/`markCashierPaid` is called. If the admin never marks the order as paid, inventory remains reserved via the cart expiration mechanism (3-day TTL), but not deducted from sellable stock.

### P6-C5: Gateway transaction lookup uses OR conditions

```php
Transaction::where('gateway_transaction_id', $paymentId)
    ->orWhere('invoice_id', $paymentId)
    ->first();
```

If two different transactions happen to have one matching `gateway_transaction_id` and another matching `invoice_id` for the same query, the `OR` may return the wrong one. The `lockForUpdate` in the callback mitigates this, but the initial lookup before the lock could resolve to a stale transaction.

**Location:** `OrderController::checkoutCallback():180-183`

### P6-C6: Amount mismatch check is one-sided

The amount comparison in the callback only logs a warning and redirects to failure. It does not attempt to reconcile or retry. This is correct behavior (security), but from an operations perspective, every amount mismatch means a lost order that requires manual support intervention.

---

## Production Recommendations

### R6-1: Fix dual event registration

Remove `\Marvel\Events\OrderCancelled::class` from the `App\Providers\EventServiceProvider` listener map. The `App\Events\OrderCancelled` event is the only one that should trigger `RestoreProductInventory`.

### R6-2: Fix error callback to only verify if already failed

Before calling `verifyPayment` in `checkoutErrorCallback`, check the transaction's current status. If it's already `paid` (e.g., a concurrent callback already processed it), skip the `failed` update and redirect to success instead. Alternatively, skip the gateway verification entirely in the error callback and only update the transaction if it's still `pending`.

### R6-3: Implement refund reconciliation

Replace the stubbed `compareRefundStatus()` with actual logic that compares refund status between the gateway and local `refunds` table.

### R6-4: Add COD/cashier auto-cancel job

Add a scheduled job that finds COD and cashier orders with pending transactions older than a configurable threshold and automatically cancels them, releasing inventory reservations.

### R6-5: Add reconciliation metrics

Emit metrics from `PaymentReconciliationJob` (total checked, mismatches by type, gateway failures) so the operations team can monitor payment health via dashboards.

### R6-6: Log warning when promotion_consumed guard is missing

In `finalizePromotionUsageAfterPayment`, if `Schema::hasColumn('orders', 'promotion_consumed')` returns false, log a warning so the operations team knows the migration is missing.
