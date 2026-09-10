# Order State Machine — Production Audit & Design

## Zero-Trust Source Code Audit

Audited files:
- `app/Services/General/OrderService.php` — `changeOrderStatus()`, `markCodAsPaid()`, `markCashierPaid()`
- `packages/marvel/src/Traits/OrderStatusManagerWithPaymentTrait.php` — `orderStatusManagementOnPayment()`, `fireEventOnOrderStatus()`, `orderStatusManagementOnCOD()`, `orderStatusManagementOnCancelled()`
- `packages/marvel/src/Database/Models/Order.php` — `getPaymentStatusAttribute()`
- `app/Console/Commands/CancelUnpaidOrders.php` — cron-based cancellation
- `app/Console/Kernel.php` — scheduler (NO scheduled tasks)
- `app/Events/OrderCreated.php`, `OrderCancelled.php`, `OrderStatusChanged.php`
- `app/Events/PaymentSucceeded.php`, `PaymentFailed.php`
- `app/Providers/EventServiceProvider.php`

---

## 1. Current Implementation — All Status Change Points

### 1a. Where Status Changes

| Location | File:Line | Who | How | Status Set |
|---|---|---|---|---|
| `checkoutCallback()` | OrderController:~300 | System (webhook) | `$order->status = 'completed'` | **completed** |
| `changeOrderStatus()` | OrderService:~530 | Admin/System | `$order->update(['status' => $status])` | Any passed |
| `markCodAsPaid()` | OrderService:~570 | Admin | `changeOrderStatus($order, 'completed')` | **completed** |
| `markCashierPaid()` | OrderService:~600 | Admin | `changeOrderStatus($order, 'completed')` | **completed** |
| `checkout()` | OrderController:~80 | Customer | Creates order; status = '**pending**' | **pending** |
| `CancelUnpaidOrders` | Console/Commands:46 | Cron | `$lockedOrder->update(['status' => 'cancelled'])` | **cancelled** |
| `OrderRepository` (Marvel) | packages/marvel/... | Admin API | Admin updates via GraphQL | Any selected |
| `orderStatusManagementOnCancelled()` | OrderStatusManagerTrait:272 | System | `$parent_order->save()`, `$childOrder->save()` | Updates totals, not status |

### 1b. Status Values Used in Code

From grep of source:
- `'pending'` — initial state after checkout
- `'completed'` — payment success / admin marks as done
- `'cancelled'` — admin cancels / cron timeout
- `'failed'` — payment failed / processing failure
- `'processing'` — admin begins fulfillment
- `'delivered'` — delivery completed

### 1c. OrderStatus Enum (Marvel)

From `Marvel\Enums\OrderStatus`:
- PENDING, PROCESSING, COMPLETED, CANCELLED, REFUNDED, FAILED, DELIVERED, ON_HOLD, OUT_FOR_DELIVERY, AWAITING_PICKUP

Not all enum values are wired in the controller/service code.

---

## 2. Current Implementation — Critical Bugs

### BUG-OSM-1: `changeOrderStatus()` Writes Transaction Payment Status

**File:** `OrderService::changeOrderStatus()` (~line 541)
```php
if ($status === OrderStatus::COMPLETED) {
    $order->transactions()->where('status', 'pending')->update(['status' => 'paid', 'paid_at' => now()]);
}
```

**Problem:** This method has TWO responsibilities — order status AND payment status. It is called by:
- `checkoutCallback()` — controller already set the transaction to paid
- `markCodAsPaid()` — creates a new transaction already as 'paid'
- `markCashierPaid()` — creates a new transaction already as 'paid'

In all cases, `where('pending')` finds 0 rows and is a no-op. But semantically, changing order status should NEVER touch transactions. This is a **violation of Single Responsibility Principle** and creates a trap for future developers.

**Worse:** If someone calls `changeOrderStatus($order, 'completed', PaymentStatus::SUCCESS)` directly (e.g., from admin panel), `PaymentSuccess` event fires → `GenerateInvoiceListener` fires → invoice is generated for an order that may NOT actually be paid.

### BUG-OSM-2: `canTransitionOrderStatus()` Missing

The **only** guard currently in code:
```php
if ($this->orderService->canTransitionOrderStatus($order, 'completed')) {
    $this->orderService->changeOrderStatus($order, 'completed', PaymentStatus::SUCCESS);
}
```

But `changeOrderStatus()` itself does NOT re-verify the transition is allowed. There is no state machine validation inside the method. Any caller can pass any status.

### BUG-OSM-3: `getPaymentStatusAttribute()` Is Computed, Not Stored

```php
public function getPaymentStatusAttribute(): string
{
    if (in_array($this->payment_method, ['cod', 'pay_at_cashier'])) {
        $latestTransaction = $this->transactions()->latest()->first();
        if ($latestTransaction) { return match ($latestTransaction->status) { ... }; }
        if (in_array($this->status, ['completed', 'delivered'])) { return PaymentStatus::SUCCESS; }
        return PaymentStatus::PENDING;
    }
    return match ($this->status) {
        'completed', 'delivered' => PaymentStatus::SUCCESS,
        'cancelled' => PaymentStatus::FAILED,
        default => PaymentStatus::PENDING,
    };
}
```

**Problems:**
1. `payment_status` is NOT a database column. DB queries filtering by `payment_status` will fail.
2. For COD/cashier: `transactions()->latest()` returns 1 row. If there are multiple transactions (failed retries then success), `latest()` returns the *last created*, which might be a failed one.
3. The fallback `if (in_array($this->status, ['completed', 'delivered']))` means any completed/delivered order is assumed paid — even if it was never actually paid.
4. **No `paid_at` on order** — the order record has no timestamp for when it was paid. Only transactions have `paid_at`.

### BUG-OSM-4: `status` vs `order_status` Column Confusion

In `app/Console/Commands/CancelUnpaidOrders.php:33`:
```php
->where('status', 'pending')
```

In `Marvel\Traits\OrderStatusManagerWithPaymentTrait.php`:
```php
if ($order->order_status === OrderStatus::COMPLETED)
```

The Order model `$fillable` has `'status'` but NOT `'order_status'`. If the database has only a `status` column, `$order->order_status` returns null → all trait checks silently fail → vendor balances are never calculated, cancellation money math is never applied.

### BUG-OSM-5: CancelUnpaidOrders Is NEVER Scheduled

**File:** `app/Console/Kernel.php:27`
```php
protected function schedule(Schedule $schedule)
{
    // $schedule->command('inspire')->hourly();
}
```

The `CancelUnpaidOrders` command exists but is NEVER registered in the scheduler. Pending orders accumulate forever. Inventory stays reserved. Coupon usage is never released.

### BUG-OSM-6: No Distinction Between "Cancelled By Customer" vs "Cancelled By Admin" vs "Expired"

All three cases result in the same `'cancelled'` status. There's no way to distinguish:
- Customer cancelled before payment → should release everything
- Admin cancelled after payment → should trigger refund
- Cron expired unpaid → should release inventory but not notify payment gateway

---

## 3. Current Implementation — Allowed Transitions (Actual)

These are the transitions actually possible in current code, NOT using a formal state machine:

```
[pending] -----------> [completed]  (checkoutCallback / markCodAsPaid / markCashierPaid)
     |                      |
     |                      v
     |                  [cancelled]  (CancelUnpaidOrders cron)
     |                      |
     v                      v
  (no path)            [refunded]    (admin via repository)
     |
     v
  (no path to failed)
```

**There is NO explicit state machine.** Any status can be set to any other status. There are no guards beyond `canTransitionOrderStatus()` which is optional and caller-dependent.

---

## 4. Proposed Production Order State Machine

### 4a. Separate Three Domains

These MUST be tracked independently:

| Domain | States | Stored Where |
|---|---|---|
| **Order Status** | pending_payment, paid, preparing, packed, shipped, delivered, completed, archived | `orders.status` |
| **Payment Status** | pending, processing, authorized, paid, failed, expired, cancelled, refunded, chargeback | `orders.payment_status` (NEW column) |
| **Fulfillment Status** | pending, picking, packing, ready, with_courier, out_for_delivery, delivered, pickup_ready, picked_up | `orders.fulfillment_status` (NEW column) |

### 4b. Order Status — Complete State Machine

```
                         +---> [cancelled_before_payment]
                         |
[pending_payment] -------+---> [expired]
      |                  |
      |                  +---> [payment_failed] ----> [pending_payment] (retry)
      |
      v
   [paid] -----> [preparing] -----> [packed] -----> [shipped]
                                                         |
                                                         v
                                              [out_for_delivery]
                                                         |
                                                         v
                                                    [delivered]
                                                         |
                                                         v
                                                   [completed]
                                                         |
                                                         v
                                                    [archived]
```

#### Cancellation Paths:
```
[pending_payment] --> [cancelled_before_payment]  (customer/admin, before any payment)
[paid] --> [cancelled] --> [refund_pending] --> [refunded]  (after payment)
[shipped] --> [return_requested] --> [return_approved] --> [return_shipped] --> [return_received] --> [refund_pending] --> [refunded]
```

### 4c. Transition Table

| From | To | Performer | Automatic/Manual | Condition |
|---|---|---|---|---|
| pending_payment | paid | System (webhook) | Automatic | Payment gateway confirms success |
| pending_payment | payment_failed | System (webhook) | Automatic | Payment gateway confirms failure |
| pending_payment | expired | Cron | Automatic | `created_at` > timeout threshold |
| pending_payment | cancelled_before_payment | Customer or Admin | Manual | No payment received yet |
| payment_failed | pending_payment | Customer | Manual | Customer clicks "retry payment" |
| paid | preparing | Admin | Manual | Warehouse begins fulfillment |
| paid | cancelled | Admin | Manual | Order cancelled after payment (triggers refund) |
| preparing | packed | Admin | Manual | Items packed |
| packed | shipped | Admin/System | Manual/Auto | Handoff to courier |
| shipped | out_for_delivery | System (courier API) | Automatic | Courier scan |
| out_for_delivery | delivered | System (courier API) | Automatic | Delivery confirmation |
| delivered | completed | System | Automatic | After return window expires |
| completed | archived | System | Automatic | After retention period |
| cancelled | refund_pending | Admin | Manual | Refund initiated |
| refund_pending | refunded | System (payment gateway) | Automatic | Refund confirmed |
| return_requested | return_approved | Admin | Manual | Inspection passes |
| return_approved | return_shipped | Customer | Manual | Customer sends back |
| return_shipped | return_received | Admin | Manual | Warehouse receives |
| return_received | refund_pending | Admin | Manual | Refund initiated |

### 4d. Forbidden Transitions

| Transition | Why Forbidden |
|---|---|
| pending_payment → completed | Payment must be confirmed first |
| paid → pending_payment | Payment already received; use refund path |
| cancelled → paid | Order is dead; create new order |
| archived → any | Frozen for legal retention |
| preparing → paid | Payment already confirmed |
| refunded → any | Financially closed |

### 4e. Customer-Visible Statuses

| Status | Customer Label |
|---|---|
| pending_payment | Pending Payment |
| paid | Paid |
| preparing | Preparing |
| packed | Packed |
| shipped | Shipped |
| out_for_delivery | Out for Delivery |
| delivered | Delivered |
| completed | Completed |
| cancelled_before_payment | Cancelled |
| cancelled | Cancelled |
| payment_failed | Payment Failed |
| expired | Expired |
| refund_pending | Refund Pending |
| refunded | Refunded |
| return_requested | Return Requested |
| return_approved | Return Approved |
| return_shipped | Return Shipped |
| return_received | Return Received |

### 4f. Admin-Only Statuses (Hidden From Customer)

| Status | Purpose |
|---|---|
| picking | Warehouse team is collecting items |
| quality_check | Items pass inspection before packing |
| ready | Packed and labeled, awaiting courier pickup |
| awaiting_pickup | Customer selected pickup; waiting for them |
| payment_verification | Suspicious payment under review |

These map to customer-visible equivalents:
- `picking` → customer sees "Preparing"
- `quality_check` → customer sees "Preparing"
- `ready` → customer sees "Packed" or "Ready for Pickup"
- `payment_verification` → customer sees "Pending Payment"

---

## 5. Event Mapping Per Transition

| Transition | Event Dispatched | Listeners | Queue |
|---|---|---|---|
| pending_payment → paid | `PaymentSucceeded` | SendPaymentSucceededNotification, GenerateInvoiceListener | high |
| pending_payment → payment_failed | `PaymentFailed` | SendPaymentFailedNotification | high |
| pending_payment → expired | `OrderCancelled` + `PaymentFailed` | RestoreProductInventory, SendNotification | medium |
| paid → cancelled | `OrderCancelled` | RestoreProductInventory, SendNotification | medium |
| any status change | `OrderStatusChanged` | SendOrderStatusChangedNotification | low |

---

## 6. State Machine Consistency Rules

1. **`orders.status` = 'paid' REQUIRES `orders.payment_status` = 'paid'** — always
2. **`orders.payment_status` = 'paid' REQUIRES at least one transaction with status 'paid'** — always
3. **`orders.fulfillment_status` = 'delivered' REQUIRES `orders.status` IN ('delivered', 'completed')** — always
4. **`orders.status` = 'cancelled' AND `orders.payment_status` = 'paid' REQUIRES `orders.refund_status` = 'pending' or 'completed'** — partial refund tracking needed
5. **`orders.status` = 'refunded' REQUIRES `orders.payment_status` = 'refunded'** — always
6. **`orders.status` = 'completed' REQUIRES `orders.fulfillment_status` = 'delivered'** — never mark completed without delivery

---

## 7. Current Inconsistencies Found

| # | Issue | Severity |
|---|---|---|
| 1 | `changeOrderStatus()` writes transaction `paid_at` — violates SRP | HIGH |
| 2 | `payment_status` is computed (not a column) — DB queries cannot filter on it | HIGH |
| 3 | `CancelUnpaidOrders` is NEVER scheduled — pending orders never expire | CRITICAL |
| 4 | `status` vs `order_status` column ambiguity — trait may silently fail | HIGH |
| 5 | No `paid_at` on order record — can't query "orders paid today" | MEDIUM |
| 6 | No `payment_status` or `fulfillment_status` columns — all three domains collapsed into `status` | HIGH |
| 7 | Completed/delivered orders assumed paid — false positive on payment status | MEDIUM |
| 8 | No transition guards inside `changeOrderStatus()` — any status → any status | HIGH |
| 9 | `PaymentSuccess` event fires inside `orderStatusManagementOnPayment()` which is called from `changeOrderStatus()` — changing order status to completed generates invoice even if payment wasn't just made | MEDIUM |
| 10 | No distinction between customer-cancelled, admin-cancelled, and expired | MEDIUM |
