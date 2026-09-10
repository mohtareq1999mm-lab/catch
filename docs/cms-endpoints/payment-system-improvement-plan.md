# Payment System Improvement Plan

## Overview

This document consolidates all proposed improvements to the payment system across multiple dimensions. Each improvement references a dedicated plan document where applicable.

---

## Improvement Matrix

| # | Improvement | Priority | Effort | Risk Reduction | Plan Document |
|---|-------------|----------|--------|----------------|---------------|
| 1 | Callback Security — Amount/Order Verification | Medium | ~1 hour | Medium | This doc |
| 2 | Stock Leak Fix — RestoreProductInventory Guard | High | ~1 hour | High | `cart-reservation-improvement-plan.md` |
| 3 | Payment Verification Cron Job | High | ~2 hours | High | `cart-reservation-improvement-plan.md` |
| 4 | Checkout Totals Immutable DTO | Low | ~4 hours | Low | `checkout-totals-refactor-plan.md` |
| 5 | Refund — Gateway Integration | Medium | ~6 hours | Medium | `refund-strategy-plan.md` |
| 6 | Dashboard — Promotion Discount in Net Revenue | Low | ~30 min | Low | This doc |
| 7 | Cart Heartbeat / Soft Reservation | Medium | ~4 hours | Medium | `cart-reservation-improvement-plan.md` |
| 8 | Event-Sourced Inventory | Low | ~8 hours | High | `cart-reservation-improvement-plan.md` |

---

## Affected Files

### Improvement 1: Callback Security

| File | Change |
|------|--------|
| `app/Http/Controllers/Api/General/OrderController.php` | Add amount/comparison check in `checkoutCallback()` |
| `app/Services/Gateway/MyFatoorahGateway.php` | Consider returning invoice amount in `verifyPayment()` |

### Improvement 2: Stock Leak Fix

| File | Change |
|------|--------|
| `app/Listeners/RestoreProductInventory.php` | Guard: only restore if stock was finalized |
| `app/Services/General/OrderService.php` | Ensure `changeOrderStatus()` passes old status |

### Improvement 3: Payment Verification Cron Job

| File | Change |
|------|--------|
| `app/Console/Kernel.php` | Register scheduled command |
| `app/Console/Commands/VerifyPendingPayments.php` | New command |
| `app/Services/General/OrderService.php` | Add `verifyAndCompleteOrder()` method |

### Improvement 4: Checkout Totals DTO

See `docs/checkout-totals-refactor-plan.md` for full details.

| File | Change |
|------|--------|
| `app/DTOs/CheckoutTotals.php` | New class |
| `app/Services/General/OrderService.php` | Add `calculateCheckoutTotalsDTO()`, refactor `calcInvoicePrice()` and `addItemsInOrder()` |
| `app/Services/Checkout/OrderCreationService.php` | Accept DTO instead of array |
| `app/Http/Controllers/Api/General/OrderController.php` | Wire DTO through |
| `app/Services/General/FastShippingService.php` | Update to use DTO |

### Improvement 5: Refund Gateway Integration

See `docs/refund-strategy-plan.md` for full details.

| File | Change |
|------|--------|
| `app/Services/Gateway/MyFatoorahGateway.php` or `MyfatoraService.php` | Add `refund()` method |
| `app/Services/Payment/Contracts/PaymentGatewayContract.php` | Add `refund()` to interface |
| `app/Listeners/ProcessRefund.php` | New listener for `RefundApproved` |

### Improvement 6: Dashboard Promotion Discount

| File | Change |
|------|--------|
| `app/Services/Dashboard/DashboardService.php` | `getFinanceAnalytics()`: change `SUM(coupon_discount)` to `SUM(coupon_discount) + SUM(promotion_discount)` |

### Improvement 7: Cart Heartbeat

See `docs/cart-reservation-improvement-plan.md` for full details.

| File | Change |
|------|--------|
| `database/migrations/..._add_checkout_started_at_to_carts.php` | New migration |
| `app/Services/General/CartInventoryService.php` | Add heartbeat logic |
| `app/Console/Commands/ReleaseAbandonedCheckouts.php` | New command |

### Improvement 8: Event-Sourced Inventory

See `docs/cart-reservation-improvement-plan.md` for full details.

---

## Migration Strategy

### Phase 1 — Immediate (Week 1)
1. Fix stock leak (RestoreProductInventory guard) — **1 hour**
2. Fix callback security — **1 hour**
3. Fix dashboard promotion discount — **30 min**
4. Deploy to staging; run full test suite

### Phase 2 — Short-term (Week 2)
1. Payment verification cron job — **2 hours**
2. Cart heartbeat / soft reservation — **4 hours**
3. Deploy to staging; monitor cart reservation behavior

### Phase 3 — Medium-term (Week 3-4)
1. Refund gateway integration — **6 hours**
2. Checkout totals DTO — **4 hours**
3. Deploy to staging; monitor refund flows

### Phase 4 — Long-term (Future)
1. Event-sourced inventory — **8 hours**
2. Full audit trail for inventory operations

---

## Risks

| Risk | Likelihood | Impact | Mitigation |
|------|-----------|--------|------------|
| Stock leak fix breaks cancellation flow | Low | Medium | Create regression test first |
| Callback amount check breaks legitimate payments if MyFatoorah amounts differ | Low | High | Log warning instead of blocking initially |
| Checkout DTO refactor introduces regression in scheduled checkouts | Medium | High | Run full test suite; use feature flag |
| Refund gateway call fails after inventory restored | Medium | High | Transaction: restore inventory only after gateway confirms |

---

## Rollback Strategy

Each improvement is independently revertible:

1. **Stock leak fix:** Revert `RestoreProductInventory` changes → reverts to current (buggy) behavior. No data loss.
2. **Callback security:** Remove amount check → reverts to callback that accepts any paid status. Acceptable.
3. **Dashboard fix:** Revert the sum change → net revenue is slightly overstated. Acceptable.
4. **DTO refactor:** Revert method signatures → would break callers. Requires coordinated revert.
5. **Refund integration:** Remove listener + gateway method. Refunds table data retained.

---

## Verification

After each phase:
1. Run full test suite
2. Execute manual checkout flow (online + COD + cashier)
3. Verify callback success and failure paths
4. Verify dashboard analytics match expected values
5. Run inventory reconciliation (sum(ordered) = sum(sold) + sum(available))

---

*End of Plan*
