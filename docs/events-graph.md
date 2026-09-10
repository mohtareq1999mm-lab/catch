# Events Graph — Zero-Trust Production Audit

**Date**: 2026-07-27  
**Scope**: Complete event dispatch → listener map, dead/orphaned events, dual registrations, notification fragmentation  
**Trust Level**: ZERO — every claim verified against source code

---

## Table of Contents

1. [Event Architecture](#1-event-architecture)
2. [Complete Event → Listener Map](#2-complete-event--listener-map)
3. [Dual Namespace Fragmentation](#3-dual-namespace-fragmentation)
4. [Dead Listeners (Code That Never Runs)](#4-dead-listeners-code-that-never-runs)
5. [Dual Registration Bug](#5-dual-registration-bug)
6. [Notification Fragmentation](#6-notification-fragmentation)
7. [Verified Bugs](#7-verified-bugs)
8. [Design Recommendations](#8-design-recommendations)

---

## 1. Event Architecture

The codebase has TWO event systems operating in parallel:

### App\Events (Custom Layer)
- Uses `Dispatchable`, `InteractsWithSockets`, `SerializesModels`
- Events are plain PHP objects (no `ShouldQueue`)
- Listeners are in `App\Listeners\`
- Registered in `app/Providers/EventServiceProvider.php`

### Marvel\Events (Package Layer)
- Most implement `ShouldQueue` (marked as queued)
- Listeners are in `Marvel\Listeners\`
- Registered in `packages/marvel/src/Providers/EventServiceProvider.php`

### Key Issue

Both systems define SAME conceptual events (OrderCancelled, PaymentSucceeded, etc.) in different namespaces. They are dispatched independently, listened to independently, and have no coordination.

---

## 2. Complete Event → Listener Map

### 2.1 App\Events (Registered in App EventServiceProvider)

```
AdminLoggedIn
  └── SendAdminLoginNotification

ContactMessageReceived
  └── SendContactMessageNotification

Registered
  └── SendEmailVerificationNotification

UserRolesUpdated
  └── LogUserRolesUpdated

OrderCancelled                         ← App\Events\OrderCancelled
  ├── RestoreProductInventory          ← QUEUED (medium queue)
  └── SendOrderCancelledNotification

\Marvel\Events\OrderCancelled          ← DUPLICATE REGISTRATION
  └── RestoreProductInventory          ← QUEUED (medium queue)

OrderCreated                           ← App\Events\OrderCreated
  └── SendNewOrderNotification

OrderStatusChanged                     ← App\Events\OrderStatusChanged
  └── SendOrderStatusChangedNotification

PaymentFailed                          ← App\Events\PaymentFailed
  └── SendPaymentFailedNotification

PaymentSucceeded                       ← App\Events\PaymentSucceeded
  ├── SendPaymentSucceededNotification
  └── GenerateInvoiceListener
```

### 2.2 Marvel\Events (Registered in Marvel EventServiceProvider)

```
DigitalProductUpdateEvent
  └── DigitalProductNotifyLogsListener

FlashSaleProcessed
  └── FlashSaleProductProcess

Maintenance
  └── MaintenanceNotification

MessageSent
  ├── MessageParticipantNotification
  ├── SendMessageNotification
  └── StoredMessagedNotifyLogsListener

OrderCancelled                         ← Marvel\Events\OrderCancelled
  └── SendOrderCancelledNotification   ← NO inventory restore here!

OrderReceived
  └── SendOrderReceivedNotification

OrderProcessed                         ← NO listener (comment says removed!)
  └── [ProductInventoryDecrement intentionally removed]

OrderDelivered
  └── SendOrderDeliveredNotification

OwnershipTransferStatusControl
  └── OwnershipTransferStatusControlListener

PaymentMethods
  └── CheckAndSetDefaultCard

ProductReviewApproved
  └── ProductReviewApprovedListener

ProductReviewRejected
  └── ProductReviewRejectedListener

ProcessUserData
  └── AppDataListener

ProcessOwnershipTransition
  └── TransferredShopOwnershipNotification

QuestionAnswered
  └── SendQuestionAnsweredNotification

RefundApproved
  ├── RatingRemoved
  └── App\Listeners\RestoreInventoryOnRefund

ReviewCreated                          ← COMMENTED OUT in ReviewRepository
  └── [SendReviewNotification — dead code]

RefundRequested
  └── SendRefundRequestedNotification

RefundUpdate
  └── SendRefundUpdateNotification

StoreNoticeEvent
  ├── StoreNoticeListener
  └── StoredStoreNoticeNotifyLogsListener

CommissionRateUpdateEvent
  └── CommissionRateUpdateListener

ShopMaintenance
  └── ShopMaintenanceListener
```

### 2.3 Events Dispatched But NO Listeners Registered

These events are dispatched by some code but have ZERO listeners:

| Event | Dispatched By | Impact |
|---|---|---|
| `Marvel\Events\PaymentSuccess` | `OrderStatusManagerWithPaymentTrait::orderStatusManagementOnPayment()` | Payment success does nothing — no notification, no invoice, no side effects |
| `Marvel\Events\PaymentFailed` | `OrderStatusManagerWithPaymentTrait::orderStatusManagementOnPayment()` | Payment failure does nothing |
| `Marvel\Events\OrderCreated` | Not currently dispatched (OrderRepository uses `OrderProcessed`) | N/A |
| `Marvel\Events\OrderStatusChanged` | `OrderStatusManagerWithPaymentTrait` (COD path: default case) | Status change does nothing via this path |
| `App\Events\OrderCreated` | Not currently dispatched | N/A |

**BUG-EVT-001**: `Marvel\Events\PaymentSuccess` is dispatched by the admin order status management trait but has NO registered listeners. When admin marks an order as paid via the admin panel, the `PaymentSuccess` event fires but nothing happens — no notification, no inventory finalization, no invoice generation.

**BUG-EVT-002**: `Marvel\Events\PaymentFailed` similarly has no listeners.

---

## 3. Dual Namespace Fragmentation

### 3.1 OrderCancelled

| Namespace | Dispatched From | Listener 1 | Listener 2 |
|---|---|---|---|
| `App\Events\OrderCancelled` | `OrderService::changeOrderStatus()`, `CancelUnpaidOrders` | `RestoreProductInventory` | `SendOrderCancelledNotification` |
| `Marvel\Events\OrderCancelled` | `OrderStatusManagerWithPaymentTrait` (3 code paths), `Test file` | `RestoreProductInventory` (via App Provider) | `SendOrderCancelledNotification` (via Marvel Provider) |

### 3.2 PaymentSucceeded / PaymentSuccess

| Namespace | Dispatched From | Listeners |
|---|---|---|
| `App\Events\PaymentSucceeded` | `OrderController::checkoutCallback()`, `OrderService::markCodAsPaid()`, `OrderService::markCashierPaid()` | `SendPaymentSucceededNotification`, `GenerateInvoiceListener` |
| `Marvel\Events\PaymentSuccess` | `OrderStatusManagerWithPaymentTrait::orderStatusManagementOnPayment()` | NONE |

### 3.3 PaymentFailed

| Namespace | Dispatched From | Listeners |
|---|---|---|
| `App\Events\PaymentFailed` | `OrderController::checkoutCallback()`, `OrderController::checkoutErrorCallback()`, `CancelUnpaidOrders` | `SendPaymentFailedNotification` |
| `Marvel\Events\PaymentFailed` | `OrderStatusManagerWithPaymentTrait::orderStatusManagementOnPayment()` | NONE |

### 3.4 OrderStatusChanged

| Namespace | Dispatched From | Listeners |
|---|---|---|
| `App\Events\OrderStatusChanged` | `OrderService::changeOrderStatus()` | `SendOrderStatusChangedNotification` |
| `Marvel\Events\OrderStatusChanged` | `OrderStatusManagerWithPaymentTrait::orderStatusManagementOnCOD()` default case, `fireEventOnOrderStatus()` default case | NONE |

---

## 4. Dead Listeners (Code That Never Runs)

These listener classes exist in the codebase but are NOT registered in any EventServiceProvider:

| Class | Would Listen To | Intended Purpose |
|---|---|---|
| `Marvel\Listeners\ProductInventoryDecrement` | `Marvel\Events\OrderProcessed` | Decrement stock when order is processed |
| `Marvel\Listeners\ProductInventoryRestore` | (unknown, likely `OrderCancelled`) | Restore inventory without idempotency guard |
| `Marvel\Listeners\SendOrderCreationNotification` | `Marvel\Events\OrderCreated` | SMS + email for order creation |
| `Marvel\Listeners\SendPaymentSuccessNotification` | `Marvel\Events\PaymentSuccess` | SMS + email for payment success (vendor + customer) |
| `Marvel\Listeners\SendPaymentFailedNotification` | `Marvel\Events\PaymentFailed` | SMS + email for payment failure (vendor + customer) |
| `Marvel\Listeners\SendOrderStatusChangedNotification` | `Marvel\Events\OrderStatusChanged` | SMS + email for status change |

**BUG-EVT-003**: `Marvel\Listeners\ProductInventoryDecrement` was intentionally removed from registration (see Marvel EventServiceProvider comment on lines 83-86). But `ProductInventoryRestore` still exists with no registration. If `OrderCancelled` is dispatched and the only restoration is in `App\Listeners\RestoreProductInventory`, the old Marvel restore is dead code.

**BUG-EVT-004**: `Marvel\Listeners\SendPaymentSuccessNotification` and `SendPaymentFailedNotification` are dead code. They would send notifications to vendors and customers, but since `Marvel\Events\PaymentSuccess` and `PaymentFailed` have no registered listeners, these notifications never fire. The admin panel "mark as paid" action is silent.

**BUG-EVT-005**: `Marvel\Listeners\SendOrderStatusChangedNotification` is dead code. Admin panel status changes don't notify anyone.

### 4.1 The Orphaned Notification Chain

When admin marks order as paid via `OrderStatusManagerWithPaymentTrait::orderStatusManagementOnPayment()`:
1. Dispatches `Marvel\Events\PaymentSuccess` → **no listener**
2. Dispatches `Marvel\Events\PaymentFailed` (on failure/reversal) → **no listener**
3. Calls `fireEventOnOrderStatus()` which may dispatch `Marvel\Events\OrderStatusChanged` or `Marvel\Events\OrderCancelled` → only `OrderCancelled` has listeners

Result: The admin panel payment flow is almost completely silent. Only order cancellation triggers notifications.

---

## 5. Dual Registration Bug

In `app/Providers/EventServiceProvider.php`:

```php
OrderCancelled::class => [                    // App\Events\OrderCancelled
    RestoreProductInventory::class,
    SendOrderCancelledNotification::class,
],
\Marvel\Events\OrderCancelled::class => [     // Marvel\Events\OrderCancelled
    RestoreProductInventory::class,
],
```

**BUG-EVT-006**: `RestoreProductInventory` is registered for BOTH event classes. This means:
- If any code dispatches `App\Events\OrderCancelled` → restoration fires once
- If any code dispatches `Marvel\Events\OrderCancelled` → restoration fires again (via the same listener)
- If both fire → `inventory_restored_at` guard prevents double execution (only if they don't race)

The guard protects against double restoration, but the dual registration is technical debt that makes the system fragile. Anyone adding a new OrderCancelled dispatch must know which one to use, and using the wrong one may skip or duplicate restoration.

### 5.1 OrderCancelled Dispatch Paths

```
OrderService::changeOrderStatus()
  └── App\Events\OrderCancelled  (for FAILED payment_status)

CancelUnpaidOrders command
  └── App\Events\OrderCancelled  (for pending orders expired)

OrderStatusManagerWithPaymentTrait
  ├── orderStatusManagementOnCOD()
  │   └── Marvel\Events\OrderCancelled  (for CANCELLED/REFUNDED)
  ├── fireEventOnOrderStatus()
  │   └── Marvel\Events\OrderCancelled  (for CANCELLED/REFUNDED/FAILED)
  └── orderStatusManagementOnPayment()
      └── [does NOT dispatch OrderCancelled]
```

---

## 6. Notification Fragmentation

### 6.1 Parallel Notification Listeners

The codebase has multiple notification listeners for the same event types:

| Event | App Listener | Marvel Listener (Dead) |
|---|---|---|
| `OrderCreated` | `SendNewOrderNotification` | `SendOrderCreationNotification` |
| `PaymentSucceeded` | `SendPaymentSucceededNotification` | `SendPaymentSuccessNotification` |
| `PaymentFailed` | `SendPaymentFailedNotification` | `SendPaymentFailedNotification` |
| `OrderStatusChanged` | `SendOrderStatusChangedNotification` | `SendOrderStatusChangedNotification` |
| `OrderCancelled` | `SendOrderCancelledNotification` | `SendOrderCancelledNotification` |

### 6.2 App vs Marvel Notification Behavior

**App listeners** (`App\Listeners\`):
- Simpler implementation
- Only customer notifications (no vendor)

**Marvel listeners** (`Marvel\Listeners\`):
- Check `emailReceiver` config for customer/vendor/admin
- Use SMS trait for SMS notifications
- More comprehensive (customer + vendor + admin + SMS)

**BUG-EVT-007**: The App listeners (which ARE registered) only notify customers. The Marvel listeners (which are NOT registered for payment/success/failure) would notify vendors too. When admin marks an order as paid via admin panel, vendors never receive payment notifications.

---

## 7. Verified Bugs

| ID | Bug | Severity | Source |
|---|---|---|---|
| **BUG-EVT-001** | `Marvel\Events\PaymentSuccess` has NO listeners — admin "mark as paid" is silent | CRITICAL | `OrderStatusManagerWithPaymentTrait.php:160` |
| **BUG-EVT-002** | `Marvel\Events\PaymentFailed` has NO listeners — admin payment failure does nothing | CRITICAL | `OrderStatusManagerWithPaymentTrait.php:163` |
| **BUG-EVT-003** | `ProductInventoryDecrement` intentionally dead, but `ProductInventoryRestore` orphaned (unregistered) | MEDIUM | `Marvel\Listeners\ProductInventoryRestore.php` |
| **BUG-EVT-004** | `SendPaymentSuccessNotification` and `SendPaymentFailedNotification` are dead code | HIGH | `Marvel\Listeners\SendPayment*.php` |
| **BUG-EVT-005** | `SendOrderStatusChangedNotification` (Marvel) is dead code | MEDIUM | `Marvel\Listeners\SendOrderStatusChangedNotification.php` |
| **BUG-EVT-006** | `RestoreProductInventory` registered for BOTH `App\Events\OrderCancelled` AND `Marvel\Events\OrderCancelled` | MEDIUM | `app/Providers/EventServiceProvider.php:70-76` |
| **BUG-EVT-007** | App listeners only notify customers; dead Marvel listeners would notify vendors too | HIGH | Both EventServiceProviders |
| **BUG-EVT-008** | `App\Events\OrderCreated` has listener but is NEVER dispatched | LOW | `app/Events/OrderCreated.php` |
| **BUG-EVT-009** | `Marvel\Events\OrderCreated` has NO listeners but is also never dispatched | LOW | `Marvel\Events\OrderCreated.php` |

### Severity Summary

- **CRITICAL**: 2 (BUG-EVT-001, BUG-EVT-002)
- **HIGH**: 2 (BUG-EVT-004, BUG-EVT-007)
- **MEDIUM**: 3 (BUG-EVT-003, BUG-EVT-005, BUG-EVT-006)
- **LOW**: 2 (BUG-EVT-008, BUG-EVT-009)

---

## 8. Design Recommendations

### 8.1 Critical: Register Marvel Payment Listeners

Either:
- Register `Marvel\Listeners\SendPaymentSuccessNotification` for `Marvel\Events\PaymentSuccess`
- Register `Marvel\Listeners\SendPaymentFailedNotification` for `Marvel\Events\PaymentFailed`
- Or unify the event systems (better — see 8.2)

### 8.2 Critical: Unify Event Namespace

Choose ONE event per conceptual event:
- `App\Events\OrderCancelled` OR `Marvel\Events\OrderCancelled` — not both
- `App\Events\PaymentSucceeded` OR `Marvel\Events\PaymentSuccess` — not both
- Same for PaymentFailed, OrderStatusChanged, OrderCreated

**Recommended approach**: Use `App\Events` as the primary namespace (it's already in the app layer). Have Marvel dispatch `App\Events` when needed.

### 8.3 High: Fix Dual Registration

Remove one of the `OrderCancelled` registrations:
- Keep `App\Events\OrderCancelled` → `RestoreProductInventory`
- Remove `Marvel\Events\OrderCancelled` → `RestoreProductInventory`
- Ensure all code that dispatches `Marvel\Events\OrderCancelled` switches to `App\Events\OrderCancelled`

### 8.4 High: Clean Up Dead Listeners

Remove or register:
- `Marvel\Listeners\ProductInventoryDecrement` — remove (intentionally dead, commented in provider)
- `Marvel\Listeners\ProductInventoryRestore` — either register or remove
- `Marvel\Listeners\SendOrderCreationNotification` — either register for `App\Events\OrderCreated` or remove
- `Marvel\Listeners\SendPaymentSuccessNotification` — register for `App\Events\PaymentSucceeded` or remove
- `Marvel\Listeners\SendPaymentFailedNotification` — register for `App\Events\PaymentFailed` or remove
- `Marvel\Listeners\SendOrderStatusChangedNotification` — register for `App\Events\OrderStatusChanged` or remove

### 8.5 Medium: Consolidate Notification Listeners

The App and Marvel notification listeners do similar things with different scope (customer-only vs customer+vendor+admin). Consolidate into a single listener per event type that handles all recipients:

| Event | Unified Listener |
|---|---|
| `App\Events\PaymentSucceeded` | Merge `SendPaymentSucceededNotification` + `SendPaymentSuccessNotification` |
| `App\Events\PaymentFailed` | Merge `SendPaymentFailedNotification` + `SendPaymentFailedNotification` |
| `App\Events\OrderStatusChanged` | Merge App + Marvel versions |
| `App\Events\OrderCancelled` | Merge App + Marvel versions |
| `App\Events\OrderCreated` | Create unified listener (currently App only notifies, Marvel has dead listener) |

### 8.6 Medium: Event-Driven Architecture Diagram

```
┌──────────────────────┐
│  Checkout Callback   │
│  (OrderController)   │
└────────┬─────────────┘
         │
         ▼
┌──────────────────────┐
│  Atomic Transaction  │
│  ├─ finalizeItems    │
│  ├─ incrementPromo   │
│  ├─ updateTxn→paid   │
│  ├─ changeOrderSts   │
│  └─ commit           │
└────────┬─────────────┘
         │
         ▼  (after commit)
┌──────────────────────┐
│  PaymentSucceeded    │
│  (App\Events)        │
└────────┬─────────────┘
         │
    ┌────┴────┐
    ▼         ▼
┌─────────┐ ┌──────────────┐
│ Generate │ │ SendPayment  │
│ Invoice  │ │ Notification │
│ (sync)   │ │ (queued)     │
└─────────┘ └──────────────┘
```
