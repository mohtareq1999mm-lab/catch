# Phase 5: Order Lifecycle

## Executive Summary

The Order lifecycle manages three state machines — order status, payment status, and fulfillment status — with synchronized transitions driven by `changeOrderStatus()`, `markCodAsPaid()`, and `markCashierPaid()`. Inventory, promotion usage, and coupon consumption are finalized on payment success and partially reversed on cancellation. The order lifecycle is the central coordination point for all downstream effects.

---

## State Machines

### 1. Order Status

**States:**

```
                    ┌─────────┐
                    │ PENDING │
                    └────┬────┘
                    ┌────┴────┐
               ┌────▼──┐  ┌──▼─────┐
               │PROCESS │  │CANCELLED│
               │ ING    │  │ (term)  │
               └────┬───┘  └────────┘
               ┌────▼───┐
               │COMPLETED│
               └────┬───┘
               ┌────▼───┐
               │DELIVERED│
               │ (term)  │
               └────────┘
```

**Allowed transitions** (from `OrderService::$allowedOrderTransitions`):

| From \ To | pending | processing | completed | delivered | cancelled |
|---|---|---|---|---|---|
| **pending** | ✓ | ✓ | ✓ | | ✓ |
| **processing** | | ✓ | ✓ | | ✓ |
| **completed** | | | ✓ | ✓ | |
| **delivered** | | | | ✓ (term) | |
| **cancelled** | | | | | ✓ (term) |

**Constants on Order model:**

| Constant | Value |
|---|---|
| `ORDER_STATUS_PENDING` | `pending` |
| `ORDER_STATUS_PROCESSING` | `processing` |
| `ORDER_STATUS_COMPLETED` | `completed` |
| `ORDER_STATUS_CANCELLED` | `cancelled` |
| `ORDER_STATUS_DELIVERED` | `delivered` |

### 2. Payment Status

**Dual system:** Payment status is both a stored column and a computed accessor.

**Stored column** (conditionally set via `Schema::hasColumn`):
- Set to `payment-pending` on order creation (`OrderCreationService:69`)
- Set to `payment-success` on completion (`changeOrderStatus`, `markCodAsPaid`, `markCashierPaid`)
- Not automatically set to `payment-failed` on cancel

**Accessor** (`Order::getPaymentStatusAttribute()`):

```php
public function getPaymentStatusAttribute(): ?string
{
    // 1. If column exists and is not null, return it
    if (array_key_exists('payment_status', $this->attributes) && $this->attributes['payment_status'] !== null) {
        return $this->attributes['payment_status'];
    }

    // 2. For COD/cashier: derive from latest transaction status
    if (in_array($this->payment_method, ['cod', 'pay_at_cashier'])) {
        $latestTransaction = $this->transactions()->latest()->first();
        if ($latestTransaction) {
            return match ($latestTransaction->status) {
                'paid' => PaymentStatus::SUCCESS,        // 'payment-success'
                'failed' => PaymentStatus::FAILED,       // 'payment-failed'
                default => PaymentStatus::PENDING,       // 'payment-pending'
            };
        }
        if (in_array($this->status, ['completed', 'delivered'])) {
            return PaymentStatus::SUCCESS;
        }
        return PaymentStatus::PENDING;
    }

    // 3. For online payment: derive from order status
    return match ($this->status) {
        'completed', 'delivered' => PaymentStatus::SUCCESS,
        'cancelled' => PaymentStatus::FAILED,
        default => PaymentStatus::PENDING,
    };
}
```

**Inconsistency:** The accessor returns values with the `payment-` prefix (e.g. `payment-pending`). The column, when set, also uses the same prefix. However, the Order model's constants like `PAYMENT_STATUS_PENDING = 'payment-pending'` use the same prefix. The raw order status `pending` (without prefix) is the order status, not the payment status. When the frontend checks `payment_status`, it receives `payment-pending`, `payment-success`, etc.

**Payment Status Enum** (separate from Order model constants):

```php
final class PaymentStatus extends Enum
{
    public const PENDING = 'payment-pending';
    public const SUCCESS = 'payment-success';
    public const FAILED  = 'payment-failed';
    public const REFUNDED = 'payment-refunded';
    // ... more values
}
```

### 3. Fulfillment Status

**States:**

```
                    ┌─────────┐
                    │ PENDING │
                    └────┬────┘
                    ┌────┴────┐
               ┌────▼──┐  ┌──▼─────┐
               │PROCESS │  │CANCELLED│
               │ ING    │  │ (term)  │
               └───┬─┬──┘  └────────┘
          ┌────────┘ └────────────┐
   ┌──────▼──────┐        ┌──────▼──────┐
   │ready_for_   │        │out_for_     │
   │pickup       │        │delivery     │
   └──────┬──────┘        └──────┬──────┘
          └──────────┬──────────┘
               ┌────▼───┐
               │DELIVERED│
               │ (term)  │
               └────────┘
```

**Allowed transitions** (from `OrderService::$allowedFulfillmentTransitions`):

| From \ To | pending | processing | ready_for_pickup | out_for_delivery | delivered | cancelled |
|---|---|---|---|---|---|---|
| **pending** | ✓ | ✓ | | | | ✓ |
| **processing** | | ✓ | ✓ | ✓ | | ✓ |
| **ready_for_pickup** | | | ✓ | | ✓ | ✓ |
| **out_for_delivery** | | | | ✓ | ✓ | ✓ |
| **delivered** | | | | | ✓ (term) | |
| **cancelled** | | | | | | ✓ (term) |

**Mapping from order status** (in `changeOrderStatus`):

| Order Status | Fulfillment Status |
|---|---|
| `processing` | `processing` |
| `completed` | `processing` (only if current is `pending`) |
| `cancelled` | `cancelled` |
| `delivered` | `delivered` |

---

## Order Status Change Flow (changeOrderStatus)

```
changeOrderStatus($invoiceId, $status, $orderId)
  └─ DB::transaction
       ├─ Transaction::where('invoice_id', $invoiceId)->first()
       │    └─ transaction->order()->lockForUpdate()
       ├─ OR Order::whereKey($orderId)->lockForUpdate()
       ├─ canTransitionOrderStatus($previousStatus, $status) → throws if invalid
       ├─ Prepare $updateData = ['status' => $status]
       │    ├─ If completed: payment_status=payment-success, completed_at=now()
       │    ├─ If cancelled: cancelled_at=now()
       │    └─ Fulfillment status mapping (see above)
       ├─ $order->update($updateData)
       ├─ IF status === 'completed':
       │    └─ recordCouponUsage($order)           ← coupon quota consumed
       ├─ IF transaction exists && completed:
       │    └─ transaction->update(status='paid', paid_at=now())
       ├─ IF transaction exists && cancelled:
       │    └─ transaction->update(status='failed')
       ├─ IF status === 'cancelled' && not already cancelled:
       │    └─ promotionService->decrementUsage(...)  ← promotion is REVERSED
       ├─ event(new OrderStatusChanged($order))
       └─ IF status === 'cancelled' && not already cancelled:
            └─ event(new OrderCancelled($order))
```

---

## Events & Listeners

### OrderStatusChanged (`App\Events\OrderStatusChanged`)

| Listener | Queue | Description |
|---|---|---|
| `SendOrderStatusChangedNotification` | `medium` | Logs activity via `LogActivityJob` |

Fired on **every** status change (including self-transitions like `pending → pending`).

### OrderCancelled (`App\Events\OrderCancelled`)

| Listener | Queue | Description |
|---|---|---|
| `RestoreProductInventory` | `medium` | Restores stock via `inventory_restored_at` guard |
| `SendOrderCancelledNotification` | `medium` | Logs activity via `LogActivityJob` |

### Marvel\Events\OrderCancelled

| Listener | Queue | Description |
|---|---|---|
| `RestoreProductInventory` | `medium` | **Same listener registered a second time** |
| `SendOrderCancelledNotification` (Marvel) | — | Sends notification (separate from App listener) |

**BUG:** `RestoreProductInventory` is registered for **both** `App\Events\OrderCancelled` AND `Marvel\Events\OrderCancelled`. The `changeOrderStatus` method only fires `App\Events\OrderCancelled`. However, the dual registration means inventory restoration happens once (correctly) but the event system is confusing. See BUG-10.

### OrderCreated (`App\Events\OrderCreated`)

| Listener | Queue | Description |
|---|---|---|
| `SendNewOrderNotification` | — | Sends new order notification |

Fired via `OrderCreationService::finalizeOrder()`.

### PaymentSucceeded (`App\Events\PaymentSucceeded`)

| Listener | Queue | Description |
|---|---|---|
| `SendPaymentSucceededNotification` | `medium` | Logs activity |
| `GenerateInvoiceListener` | `high` (afterCommit, 5 tries) | Generates invoice via `InvoiceService` |

### PaymentFailed (`App\Events\PaymentFailed`)

| Listener | Queue | Description |
|---|---|---|
| `SendPaymentFailedNotification` | `medium` | Logs activity |

---

## Inventory Effect on Cancel

`RestoreProductInventory` (for `App\Events\OrderCancelled`):

```php
public function handle($event)
{
    DB::transaction(function () use ($event) {
        $order = $event->order;
        $updated = Order::whereKey($order->id)
            ->whereNull('inventory_restored_at')
            ->lockForUpdate()
            ->update(['inventory_restored_at' => now()]);

        if ($updated === 0) { return; }  // already restored
        if (!$order->paid_at) { return; }  // only restore if paid

        foreach ($order->orderItems as $item) {
            if ($item->is_gift) { continue; }
            // Restore stock_quantity, reduce sold_quantity
            // Lock product/variant row
        }
    });
}
```

**Guard:** `inventory_restored_at` — once set, inventory is never restored again, even if the event fires multiple times.

**Constraint:** Only restores if `$order->paid_at` is set. If the order was cancelled before payment, inventory is not restored (it was never deducted from sellable stock — it was only reserved, and cart expiry releases the reservation).

---

## Coupon & Promotion Effect on Cancel

| Discount Type | Reversed on Cancel? | Mechanism |
|---|---|---|
| **Coupon** | **NEVER** | `recordCouponUsage()` is not called on cancel. The `coupon_consumed` flag stays true. No decrement logic exists. |
| **Promotion** | **YES** | `promotionService->decrementUsage()` is called. The `promotion_consumed` flag is NOT reset (but the usage counter is decremented). |

**Policy:** Coupon quota is intentionally not returned. This prevents a user from using the same coupon repeatedly by cancelling and re-ordering.

---

## Problems

### P5-C1: Dual event system for OrderCancelled and RestoreProductInventory

`RestoreProductInventory` is registered for both `App\Events\OrderCancelled` and `Marvel\Events\OrderCancelled`. Only `App\Events\OrderCancelled` is fired from `changeOrderStatus`. The Marvel registration is dead code that adds confusion. If another code path fires `Marvel\Events\OrderCancelled`, inventory restoration would fire from the same listener twice.

**Location:** `App\Providers\EventServiceProvider:72-78`

### P5-C2: Payment status dual system inconsistency

The `payment_status` accessor checks the column first, then falls back to computation. The column is conditionally set via `Schema::hasColumn`. If the column exists but is `null` (e.g., old orders created before the column migration), the accessor returns `null` rather than computing a value. The `array_key_exists` check at line 161 returns `true` because the key exists in `$this->attributes`, but the value is `null`.

**Location:** `Order::getPaymentStatusAttribute():159-162`

### P5-C3: Self-transition allowed (pending→pending, etc.)

The state machine allows self-transitions. `changeOrderStatus` fires `OrderStatusChanged` on self-transitions, which triggers `SendOrderStatusChangedNotification` even when nothing changed. This is an unnecessary queue job.

**Location:** `OrderService:474-479`

### P5-C4: Missing payment_status update on cancel

When an order is cancelled, `payment_status` is not updated to `payment-failed` in the column. The accessor derives `payment-failed` from the order status, but the column remains at whatever value it had before (e.g., `payment-pending`). If downstream code checks only the column (not the accessor), it will not see the failed status.

**Location:** `changeOrderStatus()` does not set `payment_status` on cancel.

### P5-C5: Fulfillment status column changes are schema-guarded but not atomic

All three manual `Schema::hasColumn` checks in `changeOrderStatus`, `markCodAsPaid`, and `markCashierPaid` are individually guarded. If a deployment adds one column but not another, partial updates occur silently.

---

## Production Recommendations

### R5-1: Consolidate to single OrderCancelled event

Remove `\Marvel\Events\OrderCancelled::class` from the listener registration. Only `App\Events\OrderCancelled` should be used. Keep the listener registered only once.

### R5-2: Standardize payment_status

Make the `payment_status` column required (NOT NULL, with a default). Remove the conditional `Schema::hasColumn` guards. Either fully use the column or fully use the accessor — not both. If using the accessor, drop the column.

### R5-3: Prevent self-transitions

Modify `canTransitionOrderStatus` to return `false` when `$from === $to`. This eliminates unnecessary event dispatches and job dispatches.

### R5-4: Set payment_status on cancel

In `changeOrderStatus`, when status transitions to `cancelled`, always update `payment_status` to `payment-failed` (if the column exists). This makes the column consistent with the accessor.

### R5-5: Add migration to make schema-guarded columns required

Add a migration that ensures `payment_status`, `fulfillment_status`, `completed_at`, `cancelled_at`, `coupon_consumed`, and `promotion_consumed` are all present on the `orders` table with appropriate defaults. Remove all `Schema::hasColumn` conditionals.

### R5-6: Add regression test for promotion decrement on cancel

Write a test that verifies `promotionService->decrementUsage()` is called exactly once when an order transitions from `pending` to `cancelled`.
