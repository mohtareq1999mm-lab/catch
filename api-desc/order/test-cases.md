# Test Cases - Order Feature

## Current Coverage (verified by execution)

- **`tests/Feature/OrdersProductionHardenTest.php`** — 38 tests, all passing
- **`tests/Feature/OrderCreationFlowTest.php`** — 17 tests, all passing
- **`tests/Feature/OrderStatusLifecycleTest.php`** — 15 tests, 45 assertions, all passing (NEW)

### OrderStatusLifecycleTest coverage map (canonical lifecycle + invoice contract)

| Test | Asserts |
|------|---------|
| `completing_to_delivered_dispatches_order_delivered_once` | `OrderDelivered` ×1 + `OrderStatusChanged` on `completed→delivered` |
| `re_delivering_same_order_does_not_duplicate_delivered_event` | No duplicate delivery event on re-set |
| `admin_completion_via_canonical_transition_emits_payment_succeeded_once` | PATCH completion → `PaymentSucceeded` ×1, `payment-status=payment-success`, `paid_at`, `completed_at` |
| `gateway_callback_path_does_not_emit_payment_succeeded_from_transition` | `$emitPaymentSuccess=false` opt-out (callback owns the event) |
| `mark_cod_as_paid_fires_order_status_changed_and_single_payment_succeeded` | COD: canonical transition + exactly one of each event |
| `mark_cashier_as_paid_fires_order_status_changed_and_single_payment_succeeded` | Cashier parity with COD |
| `cancellation_still_dispatches_both_events` | Cancel contract unchanged, no `PaymentSucceeded` |
| `status_transitions_work_for_pickup_order_without_delivery_address` | Pickup order (null address) transitions safely |
| `pending_to_processing_creates_invoice_once_without_payment_success` | **Invoice ×1 on first leave-pending; no payment event; payment status untouched** |
| `pending_to_completed_creates_exactly_one_invoice_and_one_payment_success` | Invoice ×1 + `PaymentSucceeded` ×1 together |
| `pending_to_cancelled_creates_invoice_without_payment_success` | **Invoice even for cancellation; NO `PaymentSucceeded`** |
| `later_transitions_never_duplicate_the_invoice` | processing→completed→delivered chain keeps exactly 1 invoice |
| `same_status_reassignment_does_not_create_invoice` | `pending→pending` is not a leave — 0 invoices |
| `mark_cod_produces_exactly_one_invoice_and_one_payment_succeeded` | COD end-state: 1 invoice + 1 payment event |
| `gateway_opt_out_path_still_creates_the_invoice_from_the_transition` | With `emit=false`, transition still creates the invoice |

> Note: the status-lifecycle tests call the service directly. There is currently **no feature test hitting the HTTP route** `PATCH /api/v1/orders/{id}/status` itself (auth middleware, FormRequest 422s, and the controller's 404 branch are uncovered at HTTP level).

### OrdersProductionHardenTest coverage map

| Category | Tests |
|----------|-------|
| Authentication | `guest_cannot_checkout`, `guest_cannot_access_promotions` |
| Checkout Flow | `checkout_creates_order_with_correct_totals`, `checkout_creates_transaction_for_cod`, `checkout_creates_order_items_with_price_snapshot`, `checkout_rejects_empty_cart`, `checkout_rejects_invalid_payment_method`, `checkout_rejects_cod_with_pickup` |
| Status Lifecycle | `pending_to_completed_transition_succeeds`, `pending_to_cancelled_transition_succeeds`, `completed_to_cancelled_transition_rejected`, `cancelled_to_completed_transition_rejected`, `pending_to_delivered_transition_rejected` — all exercise `OrderService::changeOrderStatus()` directly |
| Payment Callback | `callback_missing_payment_id_returns_400` |
| Coupon Integration | valid / expired / free-shipping coupons |
| Promotion Integration | percentage promotion, usage tracking |
| Inventory | `checkout_does_not_finalize_inventory`, `inventory_not_affected_if_checkout_fails`, `cancelled_order_restores_inventory` |
| Events | `order_created_event_dispatched_on_checkout`, `order_status_changed_event_dispatched`, `order_cancelled_event_dispatched` |
| Mark Paid | `mark_cod_as_paid_succeeds`, `mark_cod_as_paid_rejects_when_no_pending_transaction`, `mark_cod_as_paid_rejects_already_paid_transaction` |
| Transactions | UUID auto-generation + uniqueness |
| Security | `customer_cannot_access_another_customers_order`, `mark_paid_requires_update_order_status_permission` (403), `mark_paid_succeeds_for_admin_with_permission`, `mark_paid_returns_404_for_nonexistent_order` |

## Coverage Gaps → Recommended Tests

### Status endpoint (HTTP level)

| # | Test | Type | Priority |
|---|------|------|----------|
| TC-ORD-H001 | PATCH with admin + permission returns 200 and persists status | Feature | High |
| TC-ORD-H002 | PATCH without permission returns 403 | Auth | High |
| TC-ORD-H003 | PATCH unauthenticated returns 401 | Auth | High |
| TC-ORD-H004 | PATCH invalid enum value (`refunded`, `order-pending`) returns 422 | Validation | High |
| TC-ORD-H005 | PATCH forbidden transition returns 422 and does not mutate order | Edge | High |
| TC-ORD-H006 | PATCH nonexistent ID returns 404 | Edge | High |
| TC-ORD-H007 | PATCH same-status re-set succeeds (documented behavior) | Edge | Medium |
| TC-ORD-H008 | PATCH to completed sets payment_status/completed_at/tx paid | Assertion | High |
| TC-ORD-H009 | PATCH cancel dispatches OrderCancelled + restores inventory once | Integration | High |

### Customer

| # | Test | Type | Priority |
|---|------|------|----------|
| TC-ORD-C001 | Customer list returns only authenticated user's orders | Feature | High |
| TC-ORD-C002 | Customer list returns 401 without token | Auth | High |
| TC-ORD-C003 | Customer list filters by each of the 5 statuses | Filter | Medium |
| TC-ORD-C007 | Customer invoice endpoint returns 200 for owner | Feature | High |
| TC-ORD-C008 | Customer invoice endpoint returns 403 for non-owner | Auth | High |
| TC-ORD-C009 | Customer invoice endpoint returns 404 for unknown uuid | Edge | High |

### Admin

| # | Test | Type | Priority |
|---|------|------|----------|
| FT-001–FT-023 | List/detail filters, permissions, pagination, structure (as previously listed) | Feature/Auth/Edge | Medium |

## Regression Suites Required When Order Code Changes

Per the project state rules (`docs/regression-matrix.md`), changing any order lifecycle code requires rerunning:

1. `OrdersProductionHardenTest`
2. `OrderCreationFlowTest`
3. `PaymentSystemTest` / `PaymentProductionHardenTest` / `PaymentCallbackStressTest` (status coupling)
4. `CheckoutPendingOrderRedesignTest`, `CheckoutApiTest`, `PaymentCheckoutTest` (creation coupling)
5. `EventSystemTest` (event wiring)
6. Coupon & promotion suites (usage consumption on completion/cancellation)
