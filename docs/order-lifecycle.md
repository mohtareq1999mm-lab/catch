# Order Lifecycle Audit

## Table of Contents

1. [Order Statuses](#1-order-statuses)
2. [State Machine](#2-state-machine)
3. [Order Creation → Pending](#3-order-creation--pending)
4. [Pending → Processing](#4-pending--processing)
5. [Pending → Completed](#5-pending--completed)
6. [Pending → Cancelled](#6-pending--cancelled)
7. [Processing → Completed](#7-processing--completed)
8. [Processing → Cancelled](#8-processing--cancelled)
9. [Completed → Delivered](#9-completed--delivered)
10. [Cancelled → (no transitions)](#10-cancelled--no-transitions)
11. [Refund Flow](#11-refund-flow)
12. [Event & Listener Map](#12-event--listener-map)
13. [Critical Questions Answered](#13-critical-questions-answered)
14. [Bugs Found](#14-bugs-found)

---

## 1. Order Statuses

### Actual Statuses Used (app flow)

The app checkout uses simple string statuses stored directly in `orders.status`:

| Status | Description | Set By |
|--------|-------------|--------|
| `pending` | Order created, awaiting payment | `OrderCreationService::createOrder()` |
| `processing` | Admin started processing | `OrderService::changeOrderStatus()` |
| `completed` | Payment confirmed / order fulfilled | `OrderService::changeOrderStatus()` or `markCodAsPaid()` |
| `delivered` | Order delivered to customer | `OrderService::changeOrderStatus()` |
| `cancelled` | Order cancelled | `OrderService::changeOrderStatus()` or `CancelUnpaidOrders` |

### Marvel Enum (NOT used in app flow)

`Marvel\Enums\OrderStatus` defines prefixed values (`order-pending`, `order-processing`, etc.) that are used in the **deprecated Marvel admin flow**. The app checkout bypasses these entirely.

---

## 2. State Machine

```
┌──────────┐
│  pending │◄──── Created by checkout
└────┬─────┘
     │
     ├───────────────────────────►┌───────────┐
     │                            │ completed │◄── Payment confirmed (online callback / COD mark-paid)
     │                            └─────┬─────┘
     │                                   │
     │                                   ▼
     │                            ┌───────────┐
     │                            │ delivered │
     │                            └───────────┘
     │
     ├───────────────────────────►┌────────────┐
     │                            │ processing │◄── Admin starts processing
     │                            └─────┬──────┘
     │                                   │
     │                                   ├──► completed
     │                                   │
     │                                   └──► cancelled
     │
     └───────────────────────────►┌───────────┐
                                  │ cancelled │◄── Admin cancels / timeout
                                  └───────────┘
```

### Allowed Transitions (defined in `OrderService`)

```php
private static array $allowedOrderTransitions = [
    'pending'    => ['pending', 'processing', 'completed', 'cancelled'],
    'processing' => ['processing', 'completed', 'cancelled'],
    'completed'  => ['completed', 'delivered'],
    'delivered'  => ['delivered'],
    'cancelled'  => ['cancelled'],
];
```

### Forbidden Transitions

| From | To | Why |
|------|----|-----|
| pending | delivered | Must go through processing or completed |
| processing | delivered | Must go through completed first |
| completed | pending | Payment reversal not supported |
| completed | cancelled | Cancellation of completed orders not supported |
| delivered | anything | Terminal state |
| cancelled | anything | Terminal state |

---

## 3. Order Creation → Pending

### Entry Point

`OrderService::addItemsInOrder()` → `OrderCreationService::createOrder()`

### What Happens

1. Cart locked + SCHEDULED items loaded
2. Prices refreshed
3. Coupon validated
4. Promotion applied → cart items updated
5. Checkout totals calculated (subtotal, promotions, coupon, shipping)
6. Minimum order check
7. Shipping price resolved
8. **Order created with `status = 'pending'`**

### DB Writes

- `orders` row inserted with full snapshot data
- `order_items` rows inserted with per-item snapshots
- Cart items NOT modified (promotion data was written during promotion application)
- Cart itself NOT finalized

### Events Dispatched

- `App\Events\OrderCreated` → `SendNewOrderNotification` (queued, logs activity)

### Relations Loaded

```php
$order->load(['orderItems.product', 'orderItems.productVariant']);
```

---

## 4. Pending → Processing

### Trigger

Admin action via `changeOrderStatus()`.

### Allowed?

**YES** — `pending` → `processing` is in allowed transitions.

### Side Effects

- `$order->update(['status' => 'processing'])`
- Transaction: NOT updated (remains pending if COD/cashier, or paid if online)
- Promotion: NOT decremented
- Coupon: NOT recorded
- Event: `OrderStatusChanged` dispatched
- Inventory: Not affected

---

## 5. Pending → Completed

### Trigger

- **Online**: Callback verifies payment → `changeOrderStatus('completed')`
- **COD**: Admin marks paid → `markCodAsPaid()` → direct `$order->update(['status' => 'completed'])`
- **Cashier**: Admin marks paid → `markCashierPaid()` → direct `$order->update(['status' => 'completed'])`

### Allowed?

**YES** — `pending` → `completed` is in allowed transitions.

### Side Effects

When `changeOrderStatus('completed')` is used (online callback):

1. Order: `status = 'completed'`
2. **Coupon usage recorded**: `recordCouponUsage($order)` — coupons.used++, coupon_usages row or assignment incremented
3. Transaction: `status = 'paid'`, `paid_at = now()`
4. Promotion: NOT touched here (done separately before)
5. Event: `OrderStatusChanged` dispatched

When `markCodAsPaid()` or `markCashierPaid()` is used:

1. Transaction: `status = 'paid'`, `paid_at = now()`
2. Order: `status = 'completed'`
3. Coupon usage recorded
4. Promotion usage recorded (via `finalizePromotionUsageAfterPayment()`)
5. Inventory finalized (via `finalizeInventoryAfterPayment()`)
6. Event: `PaymentSucceeded` dispatched

**IMPORTANT**: In the online callback, inventory finalization and promotion usage happen BEFORE `changeOrderStatus()` is called (inside the same DB transaction). In the COD/cashier flow, they happen inside `markCodAsPaid()`/`markCashierPaid()` directly.

---

## 6. Pending → Cancelled

### Trigger

- **Manual**: Admin calls `changeOrderStatus('cancelled')`
- **Timeout**: `CancelUnpaidOrders` command

### Allowed?

**YES** — `pending` → `cancelled` is in allowed transitions.

### Side Effects

When `changeOrderStatus('cancelled')`:

1. Order: `status = 'cancelled'` (validation: transition allowed)
2. Transaction: `status = 'failed'`
3. **Promotion usage decremented**: `decrementUsage()` (only if previous status was NOT cancelled)
4. Events: `OrderStatusChanged` + `OrderCancelled` dispatched

When `CancelUnpaidOrders`:

1. Order: `status = 'cancelled'`
2. Transactions: `status = 'failed'` (batch update)
3. Events: `OrderCancelled` + `PaymentFailed` dispatched
4. **Cart expired**: `expireSingleCart()` → release stock, delete items, cart → 'expired'

**Note**: `CancelUnpaidOrders` does NOT call `changeOrderStatus()` directly. It bypasses the state machine and does not call `decrementUsage()`. This means promotion usage is NOT decremented for timed-out orders.

### Events Dispatched

- `App\Events\OrderCancelled` → listeners:
  - `RestoreProductInventory` (queued, guarded by `inventory_restored_at`)
  - `SendOrderCancelledNotification` (queued, logs activity)
- `Marvel\Events\OrderCancelled` → listener:
  - `RestoreProductInventory` (same App listener, also handles Marvel event)

---

## 7. Processing → Completed

### Trigger

Admin action via `changeOrderStatus()`.

### Allowed?

**YES** — `processing` → `completed` is in allowed transitions.

### Side Effects

Same as `pending` → `completed` via `changeOrderStatus()`:
1. Transaction: `status = 'paid'`, `paid_at = now()`
2. Coupon usage: recorded

---

## 8. Processing → Cancelled

### Trigger

Admin action via `changeOrderStatus()`.

### Allowed?

**YES** — `processing` → `cancelled` is in allowed transitions.

### Side Effects

Same as `pending` → `cancelled`:
1. Transaction: `status = 'failed'`
2. Promotion: decremented (if not already cancelled)
3. Events: `OrderStatusChanged` + `OrderCancelled`

---

## 9. Completed → Delivered

### Trigger

Admin action via `changeOrderStatus()`.

### Allowed?

**YES** — `completed` → `delivered` is in allowed transitions.

### Side Effects

- Order: `status = 'delivered'`
- Transaction: NOT modified (already 'paid')
- Events: `OrderStatusChanged` dispatched

**delivered is a terminal state** — no transitions out.

---

## 10. Cancelled → (no transitions)

Cancelled is a terminal state. No status can transition to cancelled (already at cancelled), and cancelled cannot transition to any other status.

---

## 11. Refund Flow

### No Built-in Refund Status

The `Marvel\Enums\OrderStatus::REFUNDED` exists but is NOT included in the app's `$allowedOrderTransitions`. There is no `pending → refunded` or `completed → refunded` transition.

### Refund Handling

1. Admin triggers refund via payment gateway (`MyFatoorahGateway::refund()`)
2. `RestoreInventoryOnRefund` listener handles `RefundApproved` event:
   - Restores stock_quantity (adds back)
   - Restores sold_quantity (subtracts)
   - Uses `inventory_restored_at` guard for idempotency
3. **Order status remains 'completed'** — no status change

### Key Finding

1. **Refund does NOT change order status** — The order stays as 'completed'. There's no 'refunded' status in the app flow.
2. **Coupon usage is NOT reversed on refund** — This is an explicit policy decision (documented in `recordCouponUsage()`).
3. **Promotion usage is NOT decremented on refund** — Only decremented on cancellation.
4. **`RestoreInventoryOnRefund` and `RestoreProductInventory` have identical logic** — Both restore stock and sold quantities. The only difference is their trigger (RefundApproved vs OrderCancelled).

---

## 12. Event & Listener Map

### App Events

| Event | Listeners | Queue |
|-------|-----------|-------|
| `App\Events\OrderCreated` | `SendNewOrderNotification` | medium |
| `App\Events\OrderStatusChanged` | `SendOrderStatusChangedNotification` | medium |
| `App\Events\PaymentSucceeded` | `SendPaymentSucceededNotification` | medium |
| `App\Events\PaymentFailed` | `SendPaymentFailedNotification` | medium |
| `App\Events\OrderCancelled` | `RestoreProductInventory`, `SendOrderCancelledNotification` | medium |
| `Marvel\Events\OrderCancelled` | `RestoreProductInventory` (same listener) | medium |

### Marvel Events (Legacy — dispatched from admin)

| Event | Listeners |
|-------|-----------|
| `Marvel\Events\OrderCreated` | `SendOrderCreationNotification`, `ManageProductInventory`, `StoredOrderNotifyLogsListener` |
| `Marvel\Events\PaymentSuccess` | `SendPaymentSuccessNotification` |
| `Marvel\Events\PaymentFailed` | `SendPaymentFailedNotification` |
| `Marvel\Events\OrderStatusChanged` | `SendOrderStatusChangedNotification` |
| `Marvel\Events\OrderCancelled` | `SendOrderCancelledNotification` |

---

## 13. Critical Questions Answered

### Allowed transitions

| From | To | Allowed? | Mechanism |
|------|----|----------|-----------|
| pending | pending | YES (no-op) | `canTransitionOrderStatus()` |
| pending | processing | YES | Admin action |
| pending | completed | YES | Payment success |
| pending | cancelled | YES | Admin action / timeout |
| processing | processing | YES (no-op) | |
| processing | completed | YES | Admin action |
| processing | cancelled | YES | Admin action |
| completed | completed | YES (no-op) | |
| completed | delivered | YES | Admin action |
| completed | cancelled | **NO** | Not in allowed transitions |
| delivered | anything | **NO** | Terminal state |
| cancelled | anything | **NO** | Terminal state |

### Forbidden transitions

| From | To | Consequences if attempted |
|------|----|--------------------------|
| completed | cancelled | `RuntimeException` thrown → 422 response |
| completed | pending | Not in the allowed array at all |
| cancelled | pending | Not in the allowed array |
| delivered | anything | Only 'delivered' → 'delivered' |

### Inventory changes

| Transition | Inventory Change |
|------------|-----------------|
| pending → completed (online) | `finalizeStock()` → stock_quantity--, reserved_quantity--, sold_quantity++ |
| pending → completed (COD/cashier) | `finalizeStock()` → same |
| pending → cancelled (timeout) | `expireSingleCart()` → release stock (reserved_quantity--, no change to stock/sold) |
| pending → cancelled (admin) | `OrderCancelled` → `RestoreProductInventory` restores stock + sold |
| completed → refund | `RestoreInventoryOnRefund` → stock_quantity++, sold_quantity-- |

### Financial changes

| Transition | Financial Impact |
|------------|-----------------|
| pending → completed | Coupon consumed, promotion usage++ |
| pending → cancelled | Promotion usage-- (if called via changeOrderStatus), coupon NOT consumed |
| completed → refund | Coupon NOT reversed, promotion NOT decremented |

### Notification changes

| Transition | Notifications |
|------------|---------------|
| Order created | Admin notified (NewOrderNotification) |
| pending → completed | `PaymentSucceeded` → activity log |
| pending → cancelled | Inventory restored, order cancelled notification |
| Any status change | `OrderStatusChanged` → activity log |

---

## 14. Bugs Found

| ID | Severity | Location | Description |
|----|----------|----------|-------------|
| ORD-1 | LOW | `CancelUnpaidOrders` | Bypasses `changeOrderStatus()` entirely. Does NOT call `decrementUsage()` for promotion. If a promotion was applied and the order times out, promotion usage is not decremented. |
| ORD-2 | INFO | `$allowedOrderTransitions` | The array allows `pending→pending` and `processing→processing` as valid transitions. These are no-ops. Not a bug, but confusing. |
| ORD-3 | INFO | `OrderStatus` enum (Marvel) | The enum defines `order-pending`, `order-processing`, etc. but the app flow uses plain `pending`, `processing`, etc. The enum is only relevant for the deprecated Marvel admin flow. This dual-status system is confusing. |
| ORD-4 | LOW | `changeOrderStatus:534` | When status → 'completed', `recordCouponUsage()` is called. But if the order was already completed (idempotency), `canTransitionOrderStatus` allows `completed→completed` (no-op), yet `recordCouponUsage` would still be called. However, the first guard in `recordCouponUsage()` checks `$order->coupon`, and the DB update does nothing. But `OrderStatusChanged` event would still fire for a no-op transition. |
| ORD-5 | LOW | `changeOrderStatus:538-550` | Transaction status is synchronized with order status: 'completed' → 'paid', 'cancelled' → 'failed'. But this only applies if a transaction exists. If the order was created via the API (COD/cashier) and already has a transaction, this sync is redundant. If the order was created via Marvel admin flow (which may not have a transaction), this handles it. |
