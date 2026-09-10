# Changelog - Order Feature

## [Unreleased]

### Changed (implementation v6 — Order-ID canonical invoice lookup)

## [Unreleased] — 2026-08-22
### Added
- **`GET /api/v1/general/orders/{orderId}/invoice`** — canonical customer invoice lookup by Order ID (`OrderController::invoiceByOrderId`, `whereNumber`). Ownership scoped in the query (foreign order = same clean 404, no existence leak). Resolves `latestInvoice` → identical `CustomerInvoiceResource` payload as the legacy UUID route. Pending orders return 404 (no invoice yet).
- Feature suite `tests/Feature/Order/OrderIdInvoiceEndpointTest.php` — now **10 tests / 49 assertions** covering: pending→404, first-leave→200, lifecycle stability (invoice never duplicated), cancelled/completed first-leave, ownership isolation, missing order, resource-contract shape, correction resolution, order-list invoice indicators.
### Removed
- **`GET /orders/invoice/{uuid}`** and `OrderController::invoice()` — superseded by the canonical endpoint; obsolete suite `OrderInvoiceEndpointTest.php` deleted (list-indicator test ported).
### Unchanged
- `transactions[].invoice_id` remains a gateway reference string — unrelated to the Order's Invoice (documented distinction).

### Changed (implementation v5 — invoice decoupled from payment)
- **Invoice is now generated exactly once when an Order performs its first valid transition away from `pending`** — regardless of target status (`processing`, `completed`, `cancelled`). Implemented inside `OrderService::changeOrderStatus()` by reusing the idempotent `InvoiceService` (existing-invoice lock); failures are reported and never block the operational transition. Same-status re-sets (`pending→pending`) do NOT create invoices.
- **Payment and Invoice are formally separated**: `pending→processing` yields `status=processing`, unchanged `payment-pending`, and ONE invoice with NO `PaymentSucceeded`. Completion still fires `PaymentSucceeded` exactly once (invoice already exists → listener no-ops). Gateway callback opt-out (`emit=false`) still produces its invoice from the transition itself.
- Queue inventory documented from `deploy/supervisor/`: workers verified — `meem-high` (2 procs, tries=5, timeout=90) and `meem-medium` (2 procs, tries=3, timeout=900, also consumes `default`) on the `database` connection. All assigned queues are consumed.

### Changed (implementation v4 — canonical lifecycle unification)
- **COD & Cashier marking now run the canonical lifecycle**: `markCodAsPaid()`/`markCashierPaid()` no longer hand-copy status columns; they lock+pay the transaction, finalize promotion/inventory, then delegate to `changeOrderStatus(null,'completed',…)` — gaining transition validation, coupon usage, `OrderStatusChanged`, and a single `PaymentSucceeded`.
- **`completed` now carries full payment-success semantics on every entry point**: the canonical transition emits `PaymentSucceeded` (invoice via meem-high + payment notifications) exactly once; the gateway callback opts out (`$emitPaymentSuccess=false`) because it fires the event itself after commit. Guarantees one completion = one invoice across PATCH / gateway / COD / cashier.
- **Completion forces `payment_status=payment-success`** and stamps `paid_at` (preserving any pre-existing value from the callback path) — encoding the business contract *completed ⇒ payment succeeded*.
- **Delivered is now a real business event**: first-time transition to `delivered` dispatches the new `App\Events\OrderDelivered`; registered listener `SendUserOrderDeliveredNotification` (meem-medium) delivers DB + Pusher notification. Legacy Marvel delivery listeners remain untouched.
- **Cancel-unpaid audit trail fixed**: `orders:cancel-unpaid` additionally emits `OrderStatusChanged` (it intentionally bypasses the canonical service because never-paid orders must not decrement promotion usage); `OrderCancelled`/`PaymentFailed` behavior unchanged.
- Checkout validation: **`address` is now required only for `delivery`**; pickup orders no longer require a delivery address (`pickup_location_id` remains required for pickup, `governorate_id` for delivery). Invoice snapshot service was already pickup-safe (`$order->address ?? []`).

### Added (docs v3 — status lifecycle audit)
- Documented the admin status-change endpoint **`PATCH /api/v1/orders/{id}/status`** (`orders.update-status`): auth (`sanctum`+`throttle:admin`), permission `update-order-status`, `OrderStatusUpdateRequest` validation, transition matrix, side effects per target status, events, queues, and full error table.
- Added the verified **order status machine**: `pending → processing → completed → delivered`, `cancelled` terminal, same-status re-set allowed; matrix sourced from `OrderService::$allowedOrderTransitions`.
- Added the fulfillment status machine (`ready_for_pickup`, `out_for_delivery`, …) and its sync rules during order-status changes.
- Documented event wiring after every transition: `OrderStatusChanged` (always) and `OrderCancelled` (first-time cancel), both queued to `meem-medium`; inventory restoration via `RestoreProductInventory`.
- Documented COD / cashier `mark-paid` endpoints and public gateway callbacks as the payment-driven status paths, including idempotency guards (`promotion_consumed`, `coupon_consumed`, transaction-state checks).
- Corrected status value documentation: API uses DB values `pending|processing|completed|delivered|cancelled`; legacy `Marvel\Enums\OrderStatus` values (`order-*`) are NOT valid request values.

### Fixed (docs)
- Replaced references to a non-existent `PUT /api/v1/orders/{id}` update route with the real `PATCH orders/{id}/status`.
- Removed `syncOrderStatusColumn()` / `OrderManagementTrait` references that do not exist in the current codebase.
- Corrected "no explicit orderBy" note: lists are ordered by the model global scope `created_at DESC`.

### Added (docs v2)
- Documented the **customer** order endpoints (`/api/v1/general/orders`, `/api/v1/general/orders/invoice/{uuid}`, `/api/v1/general/orders/{id}`) with true response shapes.
- Replaced admin response examples with source-accurate responses (standard `{ status, message, success, data }` envelope; list excludes `order_items`/`transactions`; detail includes them only on `orders.show`).
- Documented `App\Http\Resources\Order\*` (customer) vs `Marvel\Http\Resources\Order\*` (admin) resource differences.
- Documented `order_has_invoice` / `invoice_id` fields for customer order list.
- Documented customer pagination `links` including `last_page_url` / `first_page_url`.

### Admin (existing)
- Admin order list endpoint `GET /api/v1/orders` with filter parameters
- Admin order detail endpoint `GET /api/v1/orders/{id}` with conditional full detail response
- Permission-based access control (`view-orders` / `view-order` / `update-order-status`)
- Pagination with configurable limit (default 15, max 100)
- `OrderCollection` + `OrderResource` API resource classes
- 5 eager-loaded relations (user, items/products, variants, transactions, pickup location)
- Dual resolution for detail endpoint (ID or tracking number)

### Known Issues
- `CustomerInvoiceResource.download_url` points to a non-existent route; real download is `GET /api/v1/invoices/{uuid}/download` (see bug-report Issue 3).
- Events are dispatched inside `changeOrderStatus()`'s DB transaction without `afterCommit` — queued listeners may run against pre-commit state if the worker races the commit (see bug-report Issue 5).
- Marvel-package order listeners (SMS/email, `meem-high`) exist and are registered for `Marvel\Events\*` classes, but no app-layer flow dispatches those classes — they are unreachable from current flows (see bug-report Issues 6–7).
