# Checkout Flow Audit

> Generated: 2026-07-27 | Version: 1.0 | Classification: INTERNAL

---

## 1. End-to-End Checkout Flow

### 1.1 Entry Point
`POST /api/v1/v1/general/checkout` → `OrderController@checkout`
- Middleware: `auth:sanctum`
- Input validated by `OrderCreateRequest`

### 1.2 Controller Flow
```
OrderController::checkout()
  ├── validate request
  ├── getActiveCartForUser() — single active cart (status=active)
  ├── ensureCartReservation() — lock cart, sync reserved_quantity with DB stock
  ├── determine payment_method, gateway, fulfillment_type
  ├── validate: COD + pickup → 422
  ├── OrderService::addItemsInOrder()
  │     ├── lock cart (lockForUpdate)
  │     ├── refreshCartItemPrices() — re-fetch prices from ProductPricingService
  │     ├── re-validate coupon via CouponOrchestrator
  │     ├── calculateCheckoutTotals() — promotion + coupon
  │     ├── minimum order amount check
  │     ├── create/update pending order + order items
  │     └── commit
  └── PaymentCheckoutHandler
        ├── handleOnlinePayment() — create gateway invoice, store transaction
        ├── handleCodPayment() — create pending transaction
        └── handleCashierQrPayment() — create pending transaction + generate QR
```

### 1.3 Callback Flow
`GET /checkout/callback?paymentId=X` → `OrderController@checkoutCallback`
```
OrderController::checkoutCallback()
  ├── lookup transaction (without lock, first pass)
  ├── if mobile + no order → return 200 success (POTENTIAL ISSUE)
  ├── verify payment via gateway
  ├── DB::transaction block:
  │     ├── lock transaction (lockForUpdate)
  │     ├── skip if already paid (idempotency)
  │     ├── currency mismatch check
  │     ├── amount mismatch check
  │     ├── mark order completed
  │     ├── recordCouponUsage()
  │     ├── finalizePromotionUsageAfterPayment()
  │     └── finalizeInventoryAfterPayment()
  └── return redirect to success URL
```

---

## 2. Files Audited

| File | Lines | Purpose |
|------|-------|---------|
| `app/Http/Controllers/Api/General/OrderController.php` | 447 | Checkout + callback controller |
| `app/Services/General/OrderService.php` | 741 | Order creation, coupons, status transitions |
| `app/Services/General/CartInventoryService.php` | 487 | Cart inventory reservation, release, finalize |
| `app/Services/Payment/PaymentCheckoutHandler.php` | 121 | Payment method routing |
| `app/Services/Checkout/OrderCreationService.php` | 246 | Order/order-item creation, pending order resumption |
| `packages/marvel/src/Http/Requests/OrderCreateRequest.php` | 79 | Checkout validation rules |

---

## 3. Critical Findings

### 3.1 HIGH — `finalizeInventoryAfterPayment` Swallows Exceptions
**File**: `OrderService.php:631-645`

```php
private function finalizeInventoryAfterPayment(Order $order): void
{
    try {
        $cart = Cart::query()->where('user_id', $order->user_id)->where('status', 'active')->first();
        if ($cart) {
            $this->cartInventoryService->finalizeItemsByShippingMethod($cart, $shippingMethod);
        }
    } catch (\Throwable $e) {
        report($e);  // <-- silently swallowed
    }
}
```

**Impact**: If inventory finalization fails (DB deadlock, constraint violation), the payment success is still reported but stock is never deducted. Leads to over-selling.

**Recommendation**: Remove the try/catch or propagate the exception. The transaction in `markCodAsPaid`/`markCashierPaid` should roll back on failure.

### 3.2 HIGH — `changeOrderStatus` Marks Transaction Paid on Status Change
**File**: `OrderService.php:540-546`

```php
if ($status === 'completed') {
    $transaction->update([
        'status' => 'paid',
        'paid_at' => now(),
    ]);
}
```

**Impact**: An admin changing order status to 'completed' (via admin panel) marks any pending transaction as 'paid', even for COD orders that were never actually paid. Circumvents the proper `markCodAsPaid` flow.

**Recommendation**: Only auto-mark transaction as paid if the payment method is online and gateway verification confirms it. COD/cashier should require explicit mark-paid action.

### 3.3 MEDIUM — Callback First Transaction Lookup Without Lock
**File**: `OrderController.php:179-181`

```php
$transaction = Transaction::where('invoice_id', $invoiceId)
    ->orWhere('gateway_transaction_id', $invoiceId)
    ->first();
```

**Impact**: Race window between this read and the locked read inside the transaction block (line 288). Two concurrent callbacks could both proceed.

**Recommendation**: Move the initial lookup inside the transaction or use `lockForUpdate` on the first read.

### 3.4 MEDIUM — Mobile Callback Returns 200 When Order Is Null
**File**: `OrderController.php:229-236`

```php
if (!$order) {
    if ($request->type === 'mobile') {
        return response()->json(['message' => __('Order placed successfully')]);
    }
    ...
}
```

**Impact**: User is told their order succeeded, but no order was found for their transaction. Potential confusion or data loss.

### 3.5 MEDIUM — Promotion Usage Counted Outside Main Transaction
**File**: `OrderService.php:263-268`

```php
public function finalizePromotionUsageAfterPayment(Order $order): void
{
    $promotionId = $order->promotion_id ? (int) $order->promotion_id : null;
    if ($promotionId) {
        $this->promotionService->incrementUsage($promotionId);
    }
}
```

Called from `finalizeAfterPayment` (line 257-260) in a separate `DB::transaction` from `finalizeItemsByShippingMethod`. If inventory finalization fails, promotion usage is still incremented.

### 3.6 MEDIUM — COD/Cashier Uses `latest()->lockForUpdate()` to Find Transaction
**File**: `OrderService.php:573-577, 602-607`

```php
$transaction = $order->transactions()
    ->where('payment_method', 'cod')
    ->where('status', 'pending')
    ->latest()
    ->lockForUpdate()
    ->first();
```

**Impact**: If multiple pending COD transactions exist (unlikely but possible), the latest one is used. Should use a specific transaction identifier.

---

## 4. Design Observations

### 4.1 Cart Lifecycle
- One active cart per user enforced via `where('user_id', $userId)->lockForUpdate()`
- Stock reservation uses `lockForUpdate()` on product/variant rows
- Cart TTL = 3 days, `expireCarts()` runs via scheduled task
- TOCTOU in `syncItems()` is protected by inner lock in `reserveStock()`

### 4.2 Coupon System
- Coupon code stored as string on cart (no FK)
- Re-validated at checkout via `CouponOrchestrator`
- Public coupons: one use per user via `coupon_usages` (unique constraint)
- Assigned coupons: per-user quota via `coupon_assignments.used`
- Usage recorded only after successful payment (not at apply time)

### 4.3 Promotion System
- Applied to cart items with proportional discount allocation (largest remainder method for cent-perfect distribution)
- Cleared on every cart mutation (`revalidatePromotion()`)
- Promotion usage incremented separately from inventory finalization

### 4.4 Pending Order Resumption
- `addItemsInOrder()` checks for existing pending order
- If found: `updateOrder()` + `syncOrderItems()` instead of creating new
- Prevents duplicate orders on page refresh or retry

---

## 5. Security Review

| Concern | Status | Notes |
|---------|--------|-------|
| Authorization | ✅ | Auth via `auth:sanctum` |
| Mass Assignment | ⚠️ | Cart `coupon` field is fillable; mitigated by controlled access |
| SQL Injection | ✅ | Eloquent ORM throughout |
| Race Conditions | ⚠️ | Callback first lookup without lock (Finding 3.3) |
| Input Validation | ✅ | FormRequest validation on all endpoints |

---

## 6. Performance Review

| Concern | Status | Notes |
|---------|--------|-------|
| N+1 Queries | ✅ | Eager loading used consistently |
| Lock Contention | ⚠️ | `lockForUpdate` on cart in multiple concurrent paths |
| Bulk Operations | ✅ | `expireCarts()` uses `chunkById()` |
| Query Count | ✅ | Minimal queries per request |
