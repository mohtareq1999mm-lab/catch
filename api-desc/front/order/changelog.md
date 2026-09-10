# Changelog - Order Feature

## [Unreleased]

### Changed (implementation v4 — canonical lifecycle)
- Status endpoint completions now generate an invoice and payment notification (`PaymentSucceeded` fired exactly once per completion, all entry points unified).
- `delivered` transitions now notify the customer (DB + Pusher) via the new app-level `OrderDelivered` event.
- COD / cashier mark-paid endpoints now emit `OrderStatusChanged` activity-log entries like every other transition.
- Checkout validation: delivery address no longer required for pickup orders; branch required for pickup; governorate required for delivery. Cashier remains pickup-only; COD+pickup still rejected.

### Added (docs v3 — status lifecycle audit)
- Documented the real admin status endpoint **`PATCH /api/v1/orders/{id}/status`** (`orders.update-status`): auth chain, `update-order-status` permission, `OrderStatusUpdateRequest` validation, transition matrix, per-target side effects, events and queues.
- Added the verified status machine section: `pending → processing → completed → delivered`, `cancelled` terminal, same-status re-set allowed.
- Documented the asynchronous continuation after a successful PATCH (activity log, notifications, inventory restore on `meem-medium`) so frontend teams don't assume synchronous delivery.
- Corrected event documentation to the actually registered set (`app/Providers/EventServiceProvider.php`): `OrderStatusChanged → activity log`, cancellation triple (restore inventory / activity log / user notification), payment events → invoice generation + user notifications.
- Documented idempotency guards (transaction states, `coupon_consumed`, `promotion_consumed`, `inventory_restored_at`).

### Fixed (docs)
- Replaced every reference to the non-existent `PUT /api/v1/orders/{id}` with the real `PATCH .../status`.
- Removed the non-existent `GET /general/checkout/transaction-qr/{uuid}` route from endpoint tables.
- Corrected status value lists: only `pending|processing|completed|delivered|cancelled` are valid request/filter values; legacy `order-*` enum strings removed from examples.
- Test counts corrected after execution: OrdersProductionHardenTest = 38 tests (was listed as 25), OrderCreationFlowTest = 17 (was 18).

### Added (earlier)
- Customer order details endpoint `GET /api/v1/general/orders/{id}` (owner-scoped query; other users' orders → 404).
- Complete order lifecycle management: checkout → payment → fulfillment → completion/cancellation.
- Checkout with 3 payment methods: COD, online, pay-at-cashier.
- Price snapshot system for order items (immutable at time of order).
- Auto-generated order numbers (`ORD-{id padded}`).
- Fulfillment types: delivery and pickup. Shipping methods: scheduled and fast.
- Inventory management: reserve at checkout, deduct on payment success, restore once on cancellation.

### Fixed (earlier)
- **HIGH:** `GET /api/v1/general/orders?status={status}` now correctly filters by order status (fixed 2026-07-23).

### Known Issues
- Marvel-package SMS/email order listeners (incl. `meem-high` cancel/payment chains) are unreachable — no code dispatches their event classes; generic status changes produce activity-log entries plus app-level user notifications only.
- Events fire inside the status-change DB transaction without `afterCommit`; under race, queued workers may briefly read pre-commit state.
- Same-status PATCH re-fires `OrderStatusChanged` (duplicate activity-log rows possible).
- Dual model system (legacy Marvel columns vs modern App columns) persists elsewhere in the package.
