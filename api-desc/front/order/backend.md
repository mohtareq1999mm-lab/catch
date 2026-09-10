# Backend - Order Feature

## Overview

The Order feature is event-driven with a lifecycle spanning cart → checkout → payment → fulfillment → delivery/cancellation. All status changes are centralized in `App\Services\General\OrderService`, which enforces the transition matrices and dispatches lifecycle events consumed by queued listeners on `meem-medium`.

## Key Files

### 1. Model - `packages/marvel/src/Database/Models/Order.php`

**Table:** `orders` (SoftDeletes; global scope orders by `created_at DESC`; auto-generates `order_number`)

**Status constants (source of truth):**

```php
ORDER_STATUS_PENDING    = 'pending'
ORDER_STATUS_PROCESSING = 'processing'
ORDER_STATUS_COMPLETED  = 'completed'
ORDER_STATUS_CANCELLED  = 'cancelled'
ORDER_STATUS_DELIVERED  = 'delivered'

PAYMENT_STATUS_PENDING  = 'payment-pending'
PAYMENT_STATUS_SUCCESS  = 'payment-success'
PAYMENT_STATUS_FAILED   = 'payment-failed'
PAYMENT_STATUS_REFUNDED = 'payment-refunded'

FULFILLMENT_STATUS_PENDING          = 'pending'
FULFILLMENT_STATUS_PROCESSING       = 'processing'
FULFILLMENT_STATUS_READY_FOR_PICKUP = 'ready_for_pickup'
FULFILLMENT_STATUS_OUT_FOR_DELIVERY = 'out_for_delivery'
FULFILLMENT_STATUS_DELIVERED        = 'delivered'
FULFILLMENT_STATUS_CANCELLED        = 'cancelled'
```

**Key columns:** identity (`order_number`, `user_id`, contact fields), money (`price`, `shipping_price`, `total_price`, currency snapshot columns), discounts (`coupon*`, `promotion*`), lifecycle (`status`, `payment_status`, `fulfillment_status`, `paid_at`, `completed_at`, `cancelled_at`, `inventory_restored_at`, `coupon_consumed`, `promotion_consumed`).

**Relationships:** `user()`, `orderItems()` → OrderProduct, `transactions()`, `pickupLocation()`, `children()`, `invoices()` / `latestInvoice()`.

**Scopes:** `forUser()`, `scheduled()`, `fast()`, `delivery()`, `pickup()`.

### 2. Controller (Customer) - `app/Http/Controllers/Api/General/OrderController.php`

| Method | Route | Description |
|--------|-------|-------------|
| `index()` | `GET /general/orders` | Authenticated user's paginated orders (+ optional status filter) |
| `show()` | `GET /general/orders/{id}` | Owner-only detail; 404 otherwise |
| `invoice()` | `GET /general/orders/invoice/{uuid}` | Legacy owner-only invoice view (compat) |
| `invoiceByOrderId()` | `GET /general/orders/{orderId}/invoice` | Canonical invoice lookup by Order ID (owner-scoped query; pending → 404) |
| `checkout()` | `POST /general/checkout` | Creates pending order from cart; delegates to payment handlers |
| `eligiblePromotions()` | `GET /general/checkout/promotions` | Available promotions for cart |
| `markCodAsPaid()` | `POST /general/checkout/cod/{id}/mark-paid` | Admin marks COD paid (permission required) |
| `markCashierPaid()` | `POST /general/checkout/cashier/{id}/mark-paid` | Admin marks cashier paid (permission required) |
| `checkoutCallback()` | `ANY /general/checkout/callback` | Public gateway success/verification callback |
| `checkoutErrorCallback()` | `ANY /general/checkout/error-callback` | Public gateway error callback |

> There is **no** `getTransactionQr()` method or `transaction-qr` route in this controller — earlier documentation listed one erroneously.

### 3. Controller (Admin) - `packages/marvel/src/Http/Controllers/Order/OrderController.php`

| Method | Permission | Description |
|--------|-----------|-------------|
| `index()` | `view-orders` | Paginated list with filters |
| `show()` | `view-order` | Detail (ID or tracking number) |
| `updateStatus()` | `update-order-status` | `PATCH orders/{id}/status` — validates via `OrderStatusUpdateRequest` then calls `OrderService::changeOrderStatus(null, $status, $orderId)`; 404 unknown id; 422 forbidden transition |

### 4. Service - `app\Services\General\OrderService.php` (single source of truth for status)

| Member | Description |
|--------|-------------|
| `$allowedOrderTransitions` | Authoritative transition matrix (see below) |
| `$allowedFulfillmentTransitions` | Fulfillment matrix synced during transitions |
| `changeOrderStatus($invoiceId, $status, $orderId)` | Locks order in DB transaction, validates transition, applies column updates + coupon/promotion/inventory effects, fires `OrderStatusChanged` (always) and `OrderCancelled` (first-time cancel) |
| `markCodAsPaid($order)` / `markCashierPaid($order)` | Pending tx → paid; order → completed; PaymentSucceeded fired inside transaction |
| `paginateForUser()` / `getOrderForUser()` | Owner-scoped reads with pricing enrichment |

### 5. Service - `app/Services/Checkout/OrderCreationService.php`

| Method | Description |
|--------|-------------|
| `createOrder(...)` | Creates the order row (`pending`, payment/fulfillment `pending`, currency snapshot) |
| `createOrderItems(...)` | Snapshots prices incl. flash-sale/discount/promotion amounts per item |
| `finalizeOrder(...)` | Dispatches `App\Events\OrderCreated` |

### 6. Transition Matrices

```text
ORDER STATUS
pending    → pending, processing, completed, cancelled
processing → processing, completed, cancelled
completed  → completed, delivered
delivered  → delivered            (terminal)
cancelled  → cancelled            (terminal)

FULFILLMENT STATUS (synced automatically)
pending          → pending, processing, cancelled
processing       → processing, ready_for_pickup, out_for_delivery, cancelled
ready_for_pickup → ready_for_pickup, delivered, cancelled
out_for_delivery → out_for_delivery, delivered, cancelled
delivered        → delivered      (terminal)
cancelled        → cancelled      (terminal)
```

### 7. Events — actual wiring (verified registrations)

All app flows dispatch `App\Events\*` classes:

| Event | Fired by | Registered listeners | Queue |
|-------|----------|----------------------|-------|
| `OrderCreated` | `OrderCreationService::finalizeOrder` (after commit of checkout) | SendNewOrderNotification, SendUserOrderCreatedNotification | meem-medium |
| `OrderStatusChanged` | `OrderService::changeOrderStatus` (every change) | SendOrderStatusChangedNotification (activity log) | meem-medium |
| `OrderCancelled` | `OrderService::changeOrderStatus` (first-time cancel); CancelUnpaidOrders command | RestoreProductInventory, SendOrderCancelledNotification (activity log), SendUserOrderCancelledNotification | meem-medium |
| `PaymentSucceeded` | markCod/markCashierPaid (in tx), checkoutCallback (after commit) | SendPaymentSucceededNotification (activity log), GenerateInvoiceListener, SendUserPaymentSucceededNotification | queued |
| `PaymentFailed` | checkoutCallback/checkouErrorCallback failure paths; CancelUnpaidOrders | SendPaymentFailedNotification (activity log), SendUserPaymentFailedNotification | queued |

Legacy Marvel-package listeners exist (`Marvel\Listeners\SendOrder*Notification` incl. an SMS/email chain on `meem-high`) but are registered against `Marvel\Events\*` classes that no app code dispatches — see bug-report.

### 8. Permissions

| Permission | Value | Guards |
|------------|-------|--------|
| `VIEW_ORDERS` | `view-orders` | Admin list |
| `VIEW_ORDER` | `view-order` | Admin detail |
| `UPDATE_ORDER_STATUS` | `update-order-status` | PATCH status + both mark-paid endpoints |

## Known Issues

1. **Legacy enum trap:** `Marvel\Enums\OrderStatus` values (`order-pending`, …) are not valid API statuses.
2. **Orphaned Marvel listeners:** no dispatch sites exist for `Marvel\Events\OrderCancelled|OrderDelivered|OrderStatusChanged|PaymentSuccess|PaymentFailed`; delivery/cancel SMS-email chains are unreachable from app flows.
3. **Events fire pre-commit:** `changeOrderStatus` dispatches inside its transaction without `DB::afterCommit()` (unlike `recordCouponUsage`, which does use it).
4. `inventory_restored_at` guard prevents double-restoration on cancellation.
5. ~~Status filter ignored on `/api/v1/general/orders`~~ — fixed 2026-07-23.
