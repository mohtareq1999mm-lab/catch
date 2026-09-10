# QA - Order Feature

Verified executed suites:

- `tests/Feature/OrdersProductionHardenTest.php` — **38/38 OK**, 83 assertions
- `tests/Feature/OrderCreationFlowTest.php` — **17/17 OK**, 57 assertions

## Test Matrix

| TC ID | Description | Expected |
|-------|-------------|----------|
| TC-ORD-000 | List orders filtered by status | `?status=pending` returns only pending |
| TC-ORD-002 | Checkout with COD | pending order + pending COD transaction |
| TC-ORD-003 | Checkout with online payment | pending order; gateway delegation payload |
| TC-ORD-004 | Checkout with empty cart | 400 |
| TC-ORD-005 | Checkout with invalid payment method | 422 |
| TC-ORD-006 | Checkout with promotion | Discount applied to totals |
| TC-ORD-007 | Checkout with coupon | Discount applied / free shipping honored |
| TC-ORD-008 | Payment callback success | Order completed once (idempotent on repeat) |
| TC-ORD-009 | Payment callback failure | transaction failed + `PaymentFailed` fired |
| TC-ORD-010 | Mark COD as paid | tx→paid, order→completed |
| TC-ORD-011 | Mark COD without pending tx / already paid | 422 |
| TC-ORD-012 | Admin PATCH status valid transition | 200 + persisted |
| TC-ORD-013 | Admin PATCH forbidden transition | 422, no mutation |
| TC-ORD-014 | Admin PATCH invalid value (`refunded`, `order-pending`) | 422 validation |
| TC-ORD-015 | Admin PATCH unknown id | 404 |
| TC-ORD-016 | Admin PATCH without permission | 403 |
| TC-ORD-017 | Price snapshot immutable | Product price changes don't affect order items |
| TC-ORD-018 | Guest cannot checkout | 401 |
| TC-ORD-019 | Guest cannot view orders | 401 |
| TC-ORD-020 | Cancelled order restores inventory exactly once | stock restored; `inventory_restored_at` set |
| TC-ORD-021 | Authenticated user views own order detail | 200 + resource |
| TC-ORD-022 | User requests another user's order | 404 (no data leaked) |
| TC-ORD-023 | User requests nonexistent order | 404 |
| TC-ORD-024 | Detail ignores `user_id` query parameter | ownership from token only |

## Manual Test Checklist

- [ ] Verify customer can see only their orders
- [ ] Verify customer gets 404 (not 403/200) when opening another user's order
- [ ] Verify checkout creates order with correct totals and snapshots
- [ ] Verify COD flow: pending → admin mark-paid → completed (+tx paid)
- [ ] Verify online flow: pending → callback verified → completed (+PaymentSucceeded)
- [ ] Walk the FULL transition matrix via `PATCH /api/v1/orders/{id}/status`:
  - [ ] every legal arrow returns 200 and persists
  - [ ] every other pair returns 422 and leaves the row untouched
  - [ ] terminal states (delivered, cancelled) reject all changes
- [ ] Verify cancellation restores stock once and fires customer notification (async)
- [ ] Verify completion consumes coupon/promotion usage exactly once (repeat-safe)
- [ ] Verify queued listeners land on `meem-medium` and are consumed by workers
- [ ] Verify activity_log receives `order_status_changed` entries after transitions
