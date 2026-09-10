# Events, Listeners & Jobs — Complete Architecture Documentation

> Version: 1.0 | Classification: INTERNAL | Last Updated: 2026-07-27

---

## Executive Summary

The event system is split across two providers: **App-level** (order/payment lifecycle) and **Marvel-level** (notifications, broadcasts, shop operations). Events are primarily dispatched from services (`OrderService`), repositories, controllers, and model observers. Listeners handle notifications (email, SMS, database), inventory management, activity logging, and invoice generation. Jobs handle heavy-lifting: activity logging (47 dispatch points across 9 observers + 6 listeners), PDF generation, payment reconciliation, and product import/export. Three sub-systems (Marvel `OrderCreated` listeners) are orphaned — registered in code but not wired in any provider.

---

## 1. Event-to-Listener Mappings

### 1.1 App EventServiceProvider

| Event | Listeners | Queue |
|---|---|---|
| `App\Events\OrderCreated` | `SendNewOrderNotification` | medium |
| `App\Events\OrderCancelled` | `RestoreProductInventory`, `SendOrderCancelledNotification` | medium |
| `App\Events\OrderStatusChanged` | `SendOrderStatusChangedNotification` | medium |
| `App\Events\PaymentSucceeded` | `SendPaymentSucceededNotification`, `GenerateInvoiceListener` | medium, high |
| `App\Events\PaymentFailed` | `SendPaymentFailedNotification` | medium |
| `App\Events\AdminLoggedIn` | `SendAdminLoginNotification` | sync |
| `App\Events\ContactMessageReceived` | `SendContactMessageNotification` | sync |
| `App\Events\UserRolesUpdated` | `LogUserRolesUpdated` | queued |
| `Marvel\Events\OrderCancelled` | `RestoreProductInventory` | medium |

### 1.2 Marvel EventServiceProvider

| Event | Listeners | Queue |
|---|---|---|
| `Marvel\Events\OrderCancelled` | `SendOrderCancelledNotification` | queued |
| `Marvel\Events\OrderReceived` | `SendOrderReceivedNotification` | queued |
| `Marvel\Events\OrderDelivered` | `SendOrderDeliveredNotification` | queued |
| `Marvel\Events\OrderStatusChanged` | *(orphan — not registered via provider)* | queued |
| `Marvel\Events\PaymentSuccess` | *(orphan)* | queued |
| `Marvel\Events\PaymentFailed` | *(orphan)* | queued |
| `Marvel\Events\FlashSaleProcessed` | `FlashSaleProductProcess` | queued |
| `Marvel\Events\MessageSent` | `MessageParticipantNotification`, `SendMessageNotification` (900s delay), `StoredMessagedNotifyLogsListener` | mixed |
| `Marvel\Events\StoreNoticeEvent` | `StoreNoticeListener`, `StoredStoreNoticeNotifyLogsListener` | queued |
| `Marvel\Events\RefundRequested` | `SendRefundRequestedNotification` | queued |
| `Marvel\Events\RefundUpdate` | `SendRefundUpdateNotification` | queued |
| `Marvel\Events\Maintenance` | `MaintenanceNotification` | sync |
| `Marvel\Events\ShopMaintenance` | `ShopMaintenanceListener` | queued |
| `Marvel\Events\CommissionRateUpdateEvent` | `CommissionRateUpdateListener` | sync |
| `Marvel\Events\ProcessOwnershipTransition` | `TransferredShopOwnershipNotification` | queued |
| `Marvel\Events\OwnershipTransferStatusControl` | `OwnershipTransferStatusControlListener` | queued |
| `Marvel\Events\PaymentMethods` | `CheckAndSetDefaultCard` | queued |
| `Marvel\Events\ProductReviewApproved` | `ProductReviewApprovedListener` | queued |
| `Marvel\Events\ProductReviewRejected` | `ProductReviewRejectedListener` | queued |
| `Marvel\Events\DigitalProductUpdateEvent` | `DigitalProductNotifyLogsListener` | queued |
| `Marvel\Events\ProcessUserData` | `AppDataListener` (DISABLED) | sync |
| `App\Events\QuestionAnswered` | `SendQuestionAnsweredNotification` | queued |
| `App\Events\RefundApproved` | `RatingRemoved`, `RestoreInventoryOnRefund` | mixed |
| `App\Events\ReviewCreated` | `SendReviewNotification` | queued |

---

## 2. Order & Payment Events

### OrderCreated
**Dispatched by**: `OrderService` during checkout

**Listeners**:
- `SendNewOrderNotification` — sends `NewOrderNotification` to all active admins + dispatches `LogActivityJob` with key `order_created`

**Marvel orphan listeners** (code exists, NOT registered):
- `ManageProductInventory` — decrements product stock
- `SendOrderCreationNotification` — emails customer + admin + SMS
- `StoredOrderNotifyLogsListener` — creates NotifyLogs records

### PaymentSucceeded
**Dispatched by**: `OrderService` (callback/mark-paid), `OrderController` (webhook)

**Listeners**:
- `SendPaymentSucceededNotification` — dispatches `LogActivityJob` with key `payment_succeeded` (medium queue)
- `GenerateInvoiceListener` — calls `InvoiceService::generateFromOrder($order)` (high queue)

### PaymentFailed
**Dispatched by**: `OrderService`, `OrderController`, `CancelUnpaidOrders` command

**Listeners**:
- `SendPaymentFailedNotification` — dispatches `LogActivityJob` with key `payment_failed` (medium queue)

### OrderCancelled
**Dispatched by**: `OrderService`, `CancelUnpaidOrders` command, `OrderStatusManagerWithPaymentTrait`

**Listeners**:
- `RestoreProductInventory` — restores stock, checks `inventory_restored_at` to prevent double restoration, uses `lockForUpdate` (medium queue)
- `SendOrderCancelledNotification` — dispatches `LogActivityJob` with key `order_cancelled` (medium queue)
- `Marvel\SendOrderCancelledNotification` — emails customer/vendor/admin + SMS (queued)

### OrderStatusChanged
**Dispatched by**: `OrderService`

**Listeners**:
- `SendOrderStatusChangedNotification` — dispatches `LogActivityJob` with key `order_status_changed` (medium queue)

---

## 3. Refund Events

### RefundRequested
**Dispatched by**: Refund controller (on create)

**Listeners**:
- `SendRefundRequestedNotification` — emails admin + customer + SMS (queued)

### RefundUpdate
**Dispatched by**: Refund controller (on update)

**Listeners**:
- `SendRefundUpdateNotification` — emails customer + admin, skips child orders (queued)

### RefundApproved
**Dispatched by**: `RefundController::updateRefund()`

**Listeners**:
- `RatingRemoved` — deletes associated review (sync)
- `RestoreInventoryOnRefund` — restores stock, skip if already cancelled, uses `lockForUpdate` + `inventory_restored_at` guard (medium queue)

---

## 4. Admin & Communication Events

### AdminLoggedIn
**Dispatched by**: `UserController` (admin login), `routes/web.php` (broadcast)

**Data**: `$admin`, `$ip`, `$userAgent`
**Broadcast**: `PrivateChannel('admin.notifications')` as `admin.logged.in`
**Listeners**: `SendAdminLoginNotification` — notifies all other admins (sync)

### ContactMessageReceived
**Dispatched by**: `ContactRepository` (on create)

**Listeners**: `SendContactMessageNotification` — emails all active admins (sync)

### UserRolesUpdated
**Dispatched by**: `User` model (`syncPermissions`/`assignRole`)

**Listeners**: `LogUserRolesUpdated` — computes added/removed roles, dispatches `LogActivityJob` with key `roleUpdated` (queued)

### MessageSent
**Data**: `$message`, `$conversation`, `$type` ('shop'|'user'), `$user`
**Broadcast**: Private channel per type as `message.event`
**Listeners**:
- `MessageParticipantNotification` — creates `Participant` record (sync)
- `SendMessageNotification` — sends `MessageReminder` email with 900s delay (queued)
- `StoredMessagedNotifyLogsListener` — creates `NotifyLogs` records (queued)

### QuestionAnswered
**Listeners**: `SendQuestionAnsweredNotification` — emails customer (queued)

### ReviewCreated
**Listeners**: `SendReviewNotification` — emails shop owner (queued)
**Note**: Dispatch is **commented out** in `ReviewRepository`

---

## 5. Product & Shop Events

### FlashSaleProcessed
**Dispatched by**: `FlashSaleVendorRequestRepository`, `FlashSaleVendorRequestController`

**Listeners**: `FlashSaleProductProcess` — applies/removes flash sale pricing on products and variations (queued)

### ProductReviewApproved / ProductReviewRejected
**Listeners**: Notify vendor via `ProductApprovedNotification` / `ProductRejectedNotification` (queued)

### DigitalProductUpdateEvent
**Listeners**: `DigitalProductNotifyLogsListener` — queries `ordered_files` for purchasers, creates `NotifyLogs`, emails customers (queued)

### CommissionRateUpdateEvent
**Dispatched by**: `OrderStatusManagerWithPaymentTrait`

**Listeners**: `CommissionRateUpdateListener` — emails `AdminCommissionRateUpdate` + `VendorCommissionRateUpdate` (sync)

### StoreNoticeEvent
**Data**: `$storeNotice`, `$action`, `$user`
**Broadcast**: Private channels per user as `store.notice.event`
**Listeners**:
- `StoreNoticeListener` — notifies super admins (queued)
- `StoredStoreNoticeNotifyLogsListener` — creates NotifyLogs for vendors (queued)

### ShopMaintenance
**Listeners**: `ShopMaintenanceListener` — emails admins + shop staff (queued)

### Maintenance
**Listeners**: `MaintenanceNotification` — emails store owners (sync)

### ProcessOwnershipTransition
**Listeners**: `TransferredShopOwnershipNotification` — emails admins + previous/new owners (queued)

### OwnershipTransferStatusControl
**Listeners**: `OwnershipTransferStatusControlListener` — handles status transitions: processing (disable shop + draft products), approved (transfer), rejected (disable + draft). Emails all parties. (queued)

### PaymentMethods
**Listeners**: `CheckAndSetDefaultCard` — ensures only one default card (queued)

---

## 6. Activity Logging System

### Package: `spatie/laravel-activitylog`

### Core Job: `LogActivityJob`

```php
LogActivityJob::dispatch(
    subjectType: string,    // model class (e.g., App\Models\Product)
    subjectId: int,         // model ID
    causerId: ?int,         // acting user ID
    event: string,          // key (e.g., 'order_created', 'roleUpdated')
    logName: string,        // category (e.g., 'orders', 'users')
    description: ?string,   // localized via __('activity.*')
    properties: array,      // contextual data
);
```

**Queue**: `medium`

### Dispatch Sources (47 total)

| Source | Events Logged |
|---|---|
| `SendNewOrderNotification` | `order_created` |
| `SendOrderCancelledNotification` | `order_cancelled` |
| `SendOrderStatusChangedNotification` | `order_status_changed` |
| `SendPaymentSucceededNotification` | `payment_succeeded` |
| `SendPaymentFailedNotification` | `payment_failed` |
| `LogUserRolesUpdated` | `roleUpdated` |
| `ProductObserver` | created, updated, deleted, restored, forceDeleted |
| `CategoryObserver` | created, updated, deleted, restored |
| `BrandObserver` | created, updated, deleted, restored |
| `CouponObserver` | created, updated, deleted, restored |
| `FlashSaleObserver` | created, updated, deleted, restored, active_flash_sale |
| `PromotionObserver` | created, updated, deleted, restored |
| `PickupLocationObserver` | created, updated, deleted, restored |
| `RoleObserver` | created, updated, deleted |
| `UserObserver` | created, updated, deleted, restored, forceDeleted, login |

---

## 7. Jobs Reference

| Job | Queue | Tries | Timeout | Purpose |
|---|---|---|---|---|
| `LogActivityJob` | medium | — | — | Activity log entry (47 dispatch points) |
| `GenerateInvoicePdfJob` | low | 3 (30s/120s/300s) | — | Placeholder PDF generation, marks invoice ready |
| `PaymentReconciliationJob` | low | — | — | Batch reconcile transactions with gateway |
| `ImportProductsJob` | high | 3 (60s/120s/240s) | 1500s | Bulk product import from Excel |
| `ExportProductsJob` | default | 2 | 600s | Product export to Excel |
| `SendConversationReminder` | default | — | — | Email conversation reminder to participant |

---

## 8. Broadcast Events

| Event | Channel | Broadcast As | Condition |
|---|---|---|---|
| `Marvel\Events\OrderCreated` | Private per admin/vendor | `order.create.event` | `settings->pushNotification` |
| `App\Events\AdminLoggedIn` | `PrivateChannel('admin.notifications')` | `admin.logged.in` | Always |
| `Marvel\Events\MessageSent` | Private per type (shop/user) | `message.event` | `settings` |
| `Marvel\Events\StoreNoticeEvent` | Private per user | `store.notice.event` | `settings` |
| `Marvel\Events\TestPusherEvent` | Private per user | `test.pusher.event` | Test only |

---

## 9. Orphan Listeners (Wired but Unregistered)

These listeners exist in the codebase, import events, and contain full implementation — but are **not registered** in any `EventServiceProvider`:

| Listener | Event | Purpose |
|---|---|---|
| `ManageProductInventory` | `Marvel\Events\OrderCreated` | Decrements stock |
| `SendOrderCreationNotification` | `Marvel\Events\OrderCreated` | Emails + SMS |
| `StoredOrderNotifyLogsListener` | `Marvel\Events\OrderCreated` | NotifyLogs |
| `ProductInventoryRestore` | Any `$event->order` | Restores stock |
| `SendPaymentSuccessNotification` | `Marvel\Events\PaymentSuccess` | Emails + SMS |
| `SendPaymentFailedNotification` | `Marvel\Events\PaymentFailed` | Emails + SMS |
| `SendOrderStatusChangedNotification` | `Marvel\Events\OrderStatusChanged` | Emails + SMS |

These represent functional gaps — the App-level equivalents (`RestoreProductInventory`, `SendPaymentSucceededNotification`, etc.) are registered and active, so order/payment notifications still work. The Marvel listeners would provide additional SMS and vendor-specific notifications.

---

## 10. Key Classes Reference

### Events (App)

| Class | File |
|---|---|
| `OrderCreated` | `app/Events/OrderCreated.php` |
| `OrderCancelled` | `app/Events/OrderCancelled.php` |
| `OrderStatusChanged` | `app/Events/OrderStatusChanged.php` |
| `PaymentSucceeded` | `app/Events/PaymentSucceeded.php` |
| `PaymentFailed` | `app/Events/PaymentFailed.php` |
| `RefundApproved` | `packages/marvel/src/Events/RefundApproved.php` |
| `AdminLoggedIn` | `app/Events/AdminLoggedIn.php` |
| `ContactMessageReceived` | `app/Events/ContactMessageReceived.php` |
| `UserRolesUpdated` | `app/Events/UserRolesUpdated.php` |
| `AssignedCouponConsumed` | `app/Events/AssignedCouponConsumed.php` |

### Listeners (App)

| Class | File |
|---|---|
| `SendNewOrderNotification` | `app/Listeners/SendNewOrderNotification.php` |
| `SendOrderCancelledNotification` | `app/Listeners/SendOrderCancelledNotification.php` |
| `SendOrderStatusChangedNotification` | `app/Listeners/SendOrderStatusChangedNotification.php` |
| `SendPaymentSucceededNotification` | `app/Listeners/SendPaymentSucceededNotification.php` |
| `SendPaymentFailedNotification` | `app/Listeners/SendPaymentFailedNotification.php` |
| `GenerateInvoiceListener` | `app/Listeners/GenerateInvoiceListener.php` |
| `RestoreProductInventory` | `app/Listeners/RestoreProductInventory.php` |
| `RestoreInventoryOnRefund` | `app/Listeners/RestoreInventoryOnRefund.php` |
| `RatingRemoved` | `app/Listeners/RatingRemoved.php` |
| `SendAdminLoginNotification` | `app/Listeners/SendAdminLoginNotification.php` |
| `SendContactMessageNotification` | `app/Listeners/SendContactMessageNotification.php` |
| `LogUserRolesUpdated` | `app/Listeners/LogUserRolesUpdated.php` |
| `CommissionRateUpdateListener` | `app/Listeners/CommissionRateUpdateListener.php` |

### Jobs

| Class | File | Queue |
|---|---|---|
| `LogActivityJob` | `app/Jobs/LogActivityJob.php` | medium |
| `GenerateInvoicePdfJob` | `app/Jobs/GenerateInvoicePdfJob.php` | low |
| `PaymentReconciliationJob` | `app/Jobs/PaymentReconciliationJob.php` | low |
| `ImportProductsJob` | `packages/marvel/src/Jobs/ImportProductsJob.php` | high |
| `ExportProductsJob` | `packages/marvel/src/Jobs/ExportProductsJob.php` | default |
| `SendConversationReminder` | `packages/marvel/src/Jobs/SendConversationReminder.php` | default |

### Observers

| Class | Log Name Prefix |
|---|---|
| `ProductObserver` | products |
| `CategoryObserver` | categories |
| `BrandObserver` | brands |
| `CouponObserver` | coupons |
| `FlashSaleObserver` | flash_sales |
| `PromotionObserver` | promotions |
| `PickupLocationObserver` | pickup_locations |
| `RoleObserver` | roles |
| `UserObserver` | users |

---

## 11. Queue Architecture

| Queue | Purpose | Jobs/Listeners |
|---|---|---|
| **high** | Urgent, must-complete | `GenerateInvoiceListener`, `ImportProductsJob` |
| **medium** | Notifications & activity logging | `LogActivityJob`, all `Send*Notification` listeners |
| **low** | Batch/non-urgent | `GenerateInvoicePdfJob`, `PaymentReconciliationJob` |
| **default** | General | `ExportProductsJob`, `SendConversationReminder` |
