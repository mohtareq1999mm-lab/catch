# Test Cases - Order Feature

## Current Coverage (verified by execution)

- **`tests/Feature/OrdersProductionHardenTest.php`** — **38 tests, all passing**
- **`tests/Feature/OrderCreationFlowTest.php`** — **17 tests, all passing**
- **`tests/Feature/OrderStatusLifecycleTest.php`** — **15 tests, 45 assertions, all passing** (canonical lifecycle + invoice contract: delivered event once, completion payment-success semantics, gateway opt-out, COD/cashier single-event, invoice-on-first-leave incl. cancellation and no-duplicate chain, same-status exclusion, pickup null-safety)

### OrdersProductionHardenTest coverage map (38)

| Category | Tests |
|----------|-------|
| Authentication | `guest_cannot_checkout`, `guest_cannot_access_promotions` |
| Checkout Flow | `checkout_creates_order_with_correct_totals`, `checkout_creates_transaction_for_cod`, `checkout_creates_order_items_with_price_snapshot`, `checkout_rejects_empty_cart`, `checkout_rejects_invalid_payment_method`, `checkout_rejects_cod_with_pickup` |
| Status Lifecycle | `pending_to_completed_transition_succeeds`, `pending_to_cancelled_transition_succeeds`, `completed_to_cancelled_transition_rejected`, `cancelled_to_completed_transition_rejected`, `pending_to_delivered_transition_rejected` — via `OrderService::changeOrderStatus()` directly |
| Payment Callback | `callback_missing_payment_id_returns_400` |
| Coupon Integration | valid / expired / free-shipping coupons |
| Promotion Integration | percentage promotion, usage tracking |
| Inventory | `checkout_does_not_finalize_inventory`, `inventory_not_affected_if_checkout_fails`, `cancelled_order_restores_inventory` |
| Events | `order_created_event_dispatched_on_checkout`, `order_status_changed_event_dispatched`, `order_cancelled_event_dispatched` |
| Mark Paid | success / no-pending-transaction / already-paid |
| Transactions | UUID auto-generation + uniqueness; duplicate checkout behavior |
| Security | cross-customer isolation, `mark_paid_requires_update_order_status_permission` (403), admin mark-paid success, 404 for missing order |

### OrderCreationFlowTest coverage map (17)

Flash-sale pricing (4), discount pricing (4), variant flash-sale/discount pricing (5), edge cases: no-variant fallback, variant-without-price fallback, effective unit price (3). One test per documented snapshot rule.

## Coverage Gaps → Recommended Additional Tests

### HTTP-level status endpoint (currently uncovered at route level)

| # | Test | Type | Priority |
|---|------|------|----------|
| FT-ORD-S01 | PATCH `/orders/{id}/status` with admin + permission → 200 + persisted status | Feature | High |
| FT-ORD-S02 | PATCH without permission → 403 | Auth | High |
| FT-ORD-S03 | PATCH unauthenticated → 401 | Auth | High |
| FT-ORD-S04 | PATCH invalid enum (`refunded`, `order-pending`) → 422 validation | Validation | High |
| FT-ORD-S05 | PATCH forbidden transition → 422 and order unchanged | Edge | High |
| FT-ORD-S06 | PATCH unknown id → 404 | Edge | High |
| FT-ORD-S07 | PATCH same-status re-set succeeds (documented) | Edge | Medium |
| FT-ORD-S08 | PATCH completed syncs payment fields + tx paid | Assertion | High |
| FT-ORD-S09 | PATCH cancelled restores inventory once + fires OrderCancelled | Integration | High |
| FT-ORD-S10 | Queue assertion: listeners bound to `meem-medium` | Structure | Medium |

### Other gaps

| # | Test | Type | Priority |
|---|------|------|----------|
| FT-001 | Cashier mark-paid flow mirrors COD suite | Feature | High |
| FT-002 | Callback amount/currency mismatch blocks completion | Edge | High |
| FT-003 | Callback idempotency: second success call is a no-op | Edge | High |
| FT-004 | Invoice download with valid/expired token | Auth | Medium |
