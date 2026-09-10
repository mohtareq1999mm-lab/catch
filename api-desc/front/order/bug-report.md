# Bug Report - Order Feature

All findings below verified directly from source during the status-lifecycle audit.

## Issue 1: Status Filter Completely Ignored on `/api/v1/general/orders` — FIXED

- **Severity:** HIGH
- **Status:** FIXED (2026-07-23)
- **Component:** `app/Services/General/OrderService.php::paginateForUser()`
- **Fix:** `->when($request->has('status'), fn($q) => $q->where('status', $request->get('status')))`

## Issue 2 (HIGH, docs-facing): Marvel SMS/email order listeners are unreachable

- **Files:** `packages/marvel/src/Listeners/SendOrderStatusChangedNotification.php`, `SendPaymentSuccessNotification.php`, `SendPaymentFailedNotification.php` (all `meem-high`/`meem-medium`)
- **Confidence:** VERIFIED
- **Root cause:** The app layer dispatches only `App\Events\*` classes. Nothing in `app/` dispatches `Marvel\Events\OrderCancelled|OrderDelivered|OrderStatusChanged|PaymentSuccess|PaymentFailed`, and `Marvel\Providers\EventServiceProvider` registers listeners for only two of them (`OrderCancelled`, `OrderDelivered`). The generic status-change SMS/email chain therefore never runs.
- **Production impact:** On a generic status change (e.g. admin PATCH to `processing`), customers receive NO SMS/email — only the queued activity-log entry, and on cancellation the app-level `SendUserOrderCancelledNotification`.
- **Fix direction:** either register + dispatch the Marvel events from `changeOrderStatus()`, or port the SMS/email chain onto `App\Events\OrderStatusChanged`.

## Issue 3 (MEDIUM): Events dispatched inside the DB transaction without afterCommit

- **File:** `app/Services/General/OrderService.php:619-623`
- **Confidence:** VERIFIED
- **Description:** `OrderStatusChanged` / `OrderCancelled` are fired inside `DB::transaction(...)`. Listeners are queued; a fast worker can execute before commit and read stale state or fail transiently.
- **Impact:** Possible stale payloads in activity logs/notifications under race conditions.
- **Note:** The codebase already uses the correct pattern elsewhere (`DB::afterCommit()` in `recordCouponUsage()`).

## Issue 4 (LOW): Same-status transitions re-fire events

- **File:** `app/Services/General/OrderService.php:494-500` (self-targets allowed) and `:619`
- **Confidence:** VERIFIED
- **Impact:** Duplicate `order_status_changed` activity-log rows when an admin re-saves the same status. No financial duplication (idempotency flags hold).

## Issue 5 (LOW): Legacy enum values conflict with API contract

- **Files:** `packages/marvel/src/Enums/OrderStatus.php` vs model constants / DB enum
- **Confidence:** VERIFIED
- **Description:** Enum exposes `order-pending`, `order-processing`, … but validation (`OrderStatusUpdateRequest`) and storage use raw `pending`, `processing`, …. Any client sending enum-style values gets 422.
- **Impact:** Frontend confusion risk; docs now pin the five DB values.

## Issue 6 (INFO): Documentation previously described non-existent routes/methods

- `PUT /api/v1/orders/{id}` — never registered; real endpoint is `PATCH orders/{id}/status`.
- `GET /general/checkout/transaction-qr/{uuid}` + `getTransactionQr()` — no such route/method exists.
- `syncOrderStatusColumn()` / `OrderManagementTrait::changeOrderStatus($order,$status,$user)` — not present in current `OrderService`; actual signature is `changeOrderStatus($invoiceId, $status, $orderId)`.
- **Status:** documentation corrected in this pass.

## Legacy notes

- Dual model system (legacy Marvel columns vs modern App columns) still exists in package internals; modern flows use the App columns exclusively.
- No base `create_orders_table` migration found (likely squashed); lifecycle columns added by `2026_07_27_081603_*` and later migrations.
