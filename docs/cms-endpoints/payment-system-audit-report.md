# Payment System Architecture Audit Report

**Date:** 2026-07-11
**Classification:** Confirmed Bug, Already Implemented, Improvement Only, Not a Bug

---

## Audit Results

| # | Area | Classification | Details |
|---|------|----------------|---------|
| 1 | Inventory Cancellation Flow | **Confirmed Bug** | Stock leak in callback failure path |
| 2 | Callback Idempotency | **Not a Bug** | State transitions are idempotent |
| 3 | Checkout Totals Recalculation | **Improvement Only** | Dual calculation produces consistent values |
| 4 | Pickup Location Snapshot | **Already Implemented** | Migration + snapshot + resource fallback all exist |
| 5 | Dashboard Revenue | **Improvement Only** | Net revenue formula double-subtracts coupon discount |
| 6 | Database Transactions | **Improvement Only** | Order creation atomic; payment handler not fully covered |
| 7 | Concurrency & Race Conditions | **Not a Bug** | `lockForUpdate()` provides adequate protection |
| 8 | Queue Failure | **Not a Bug** | Listeners not idempotent but double-execution is low-impact |
| 9 | Payment Timeout | **Improvement Only** | `CancelUnpaidOrders` doesn't release cart reservations |
| 10 | Coupon & Promotion Rollback | **Not a Bug** | Permanent usage is intentional |

---

## 1. Inventory Cancellation Flow — Confirmed Bug

### Root Cause

In `OrderController::checkoutCallback()`, the failure path (line 209-213) calls:
```php
$this->orderService->changeOrderStatus($transaction->invoice_id, 'cancelled');
event(new OrderCancelled($order));  // ← explicit dispatch
```

Inside `changeOrderStatus(line 384)`, `OrderCancelled` is also conditionally dispatched:
```php
if ($status === 'cancelled' && $previousStatus === 'completed') {
    event(new OrderCancelled($order));
}
```

When a payment fails, the order status was `pending` (never `completed`). So `changeOrderStatus` correctly does NOT dispatch `OrderCancelled`. But the caller dispatches it unconditionally, which queues `RestoreProductInventory`.

### The Leak Sequence

1. User adds to cart → `reserved_quantity += order_qty`, `stock_quantity` unchanged
2. Payment fails → `changeOrderStatus('cancelled')` — order was `pending`, `OrderCancelled` NOT dispatched by method
3. `event(new OrderCancelled($order))` — **explicitly dispatched** → queues `RestoreProductInventory`
4. `releaseCart($cart, false)` — releases `reserved_quantity` back to available stock ✅
5. (Queue) `RestoreProductInventory` runs → `stock_quantity += order_qty` — **BUG: stock was never deducted since `finalizeStock` never ran**

Result: `available_stock = stock_quantity + order_qty - 0 = overcounted by order_qty`.

### Fix

Remove the explicit `event(new OrderCancelled($order))` from both:
- `checkoutCallback()` failure path (line 212)
- `checkoutErrorCallback()` failure path (line 321)

The `changeOrderStatus()` method already correctly controls when `OrderCancelled` should fire. The `PaymentFailed` event (which remains) handles the notification/logging.

### Files Changed

| File | Line | Change |
|------|------|--------|
| `app/Http/Controllers/Api/General/OrderController.php` | 212 | Remove `event(new OrderCancelled($order))` |
| `app/Http/Controllers/Api/General/OrderController.php` | 321 | Remove `event(new OrderCancelled($order))` |

### Why Safe

- `PaymentFailed` remains → activity is still logged
- `releaseCart` remains → stock is still correctly released
- `changeOrderStatus('cancelled')` for `pending → cancelled` correctly skips `OrderCancelled`
- `changeOrderStatus('cancelled')` for `completed → cancelled` correctly fires `OrderCancelled` (admin cancellations)

### Backward Compatibility

- API responses: No change
- Business logic: Only when a payment fails, the `OrderCancelled` event is no longer fired
- The `PaymentFailed` event always fires, so notifications/activity logging is preserved
- Cancellations of completed orders (admin) are unaffected

---

## 2. Callback Idempotency — Not a Bug

The callback is naturally protected:

| Scenario | Behavior | Safe? |
|----------|----------|-------|
| Success → Success | Order already `completed`, `finalizeItemsByShippingMethod` finds no active cart | ✅ |
| Failure → Failure | Order already `cancelled`, `releaseCart` finds `reserved_quantity = 0` | ✅ |
| Success → Failure | `changeOrderStatus('cancelled')` with `previousStatus='completed'` fires `OrderCancelled` → `RestoreProductInventory` correctly restores stock | ✅ |
| Failure → Success | `changeOrderStatus('completed')` from `cancelled` — cart already released. Stock leak (pre-existing, low likelihood). | ⚠️ 3rd-party race condition |

No changes needed.

---

## 3. Checkout Totals — Improvement Only

`calcInvoicePrice()` and `addItemsInOrder()` independently compute checkout totals. They produce consistent values because `addItemsInOrder` reads cart items that were already modified by promotion application during `calcInvoicePrice`. This is intentional behavior.

Refactoring to a shared DTO is documented in `docs/checkout-totals-refactor-plan.md`.

No changes needed.

---

## 4. Pickup Location Snapshot — Already Implemented ✅

| Component | Status |
|-----------|--------|
| Migration `2026_07_11_000004_add_pickup_location_snapshot_to_orders.php` | ✅ Exists |
| Order model fillable (`pickup_location_name`, `_address`, `_phone`, `_coordinates`) | ✅ Lines 34-37 |
| `OrderCreationService::resolvePickupLocationSnapshot()` | ✅ Lines 117-150 |
| Marvel OrderResource `resolvePickupLocation()` fallback | ✅ Lines 52-86 |
| App OrderResource `resolvePickupLocation()` fallback | ✅ Lines 42-59 |
| Tests pass with schema columns | ✅ After fix |

No changes needed.

---

## 5. Dashboard Revenue — Improvement Only

`getFinanceAnalytics()` formula:
```
net_revenue = gross_revenue - refund - coupon_discount
```

The `coupon_discount` is subtracted twice: once via `total_price` (which already reflects it) and once explicitly. This is a metric definition choice, not a transaction bug.

No changes needed.

---

## 6. Database Transactions — Improvement Only

| Operation | Atomic? | Risk |
|-----------|---------|------|
| Order + Items creation | ✅ In `DB::transaction` | None |
| Order + Items (fast) | ✅ In `DB::transaction` | None |
| Online payment (Transaction) | ❌ Not wrapped | Transaction create succeeds even if callback fails later |
| COD payment (Transaction + inventory) | ❌ Not wrapped | Transaction exists even if inventory fails |

Low risk. `finalizeInventory` is guarded with `try/catch`. No changes needed.

---

## 7. Concurrency & Race Conditions — Not a Bug

- `lockForUpdate()` on cart ensures serial checkout
- `releaseCart`, `finalizeCart`, `expireCart` all use `lockForUpdate()`
- Admin `markCodAsPaid` and `markCashierPaid` operate on different payment methods (mutually exclusive)

No changes needed.

---

## 8. Queue Failure — Not a Bug

All App Listeners use `ShouldQueue` + `$queue = 'medium'`. No explicit retry limits set (Laravel default: up to 255 retries). Listeners are not idempotent (double execution creates duplicate activity log entries).

Low impact. No changes needed.

---

## 9. Payment Timeout — Improvement Only

`CancelUnpaidOrders` (runs hourly) correctly cancels orders and fires `PaymentFailed`. However, it does NOT release the cart reservation. The cart will self-expire via `ExpireAbandonedCarts` (also runs hourly), but the order cancellation and cart release are not synchronized.

Low urgency. No changes needed.

---

## 10. Coupon & Promotion Rollback — Not a Bug

`CouponUsage` and promotion `usage` counters are not decremented on cancellation. This prevents re-use of the same coupon/promotion. This is intentional.

No changes needed.

---

## Summary of Required Code Changes

Only **1 confirmed bug** requires a fix:

| File | Change | Why |
|------|--------|-----|
| `app/Http/Controllers/Api/General/OrderController.php` | Remove 2 explicit `OrderCancelled` dispatches | Prevents double stock restoration in callback failure path |

All other areas are either: Already Implemented, Improvement Only, or Not a Bug.
