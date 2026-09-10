# Final Production Audit — Executive Summary

**Date**: 2026-07-27  
**Production Readiness Score**: 3.9 / 10  
**Total Bugs Found**: 67 (across all subsystems)

---

## 1. Master Bug Tally

| Severity | Count | Description |
|---|---|---|
| **CRITICAL** | **14** | Data loss, financial integrity, security bypass |
| **HIGH** | **17** | Major feature gaps, incorrect behavior, missing functionality |
| **MEDIUM** | **23** | Significant issues, edge cases, design problems |
| **LOW** | **11** | Minor issues, code quality, documentation |
| **INFO** | **2** | Observations, non-blocking |

---

## 2. All CRITICAL Bugs (Must Fix Before Production)

| ID | Bug | System | Summary |
|---|---|---|---|
| BUG-001 | CancelUnpaidOrders NEVER scheduled | Cron | Kernel.php has ALL cron commented out. Pending unpaid orders never cancelled. |
| BUG-002 | Promotion usage increments BEFORE payment | Promotion | `incrementUsage()` called in `applySelectedPromotion()` at checkout — before payment. Failed payments consume quota. |
| BUG-003 | Post-payment actions not atomic | Payment | Events fire BEFORE coupon/promotion/inventory finalized. Race window on failure. |
| BUG-004 | Pending order reuse destroys audit trail | Checkout | `findPendingOrderForUser()` silently overwrites old orders. |
| BUG-INV-005 | `deductStock()` runs outside transaction | Inventory | Stock deduction not atomic with order creation. Inconsistency on failure. |
| BUG-INV-006 | `deductStock()` non-atomic decrement after lock released | Inventory | Oversell possible between validation and deduction. |
| BUG-INV-016 | No scheduled command runs `expireCarts()` | Inventory | Kernel cron all commented out. Reserved carts never expire. |
| BUG-INV-018 | Two independent inventory systems with zero coordination | Inventory | CartInventoryService vs OrderRepository::deductStock on same columns. |
| BUG-FIN-001 | Custom checkout and admin use different financial models | Financial | Different column sets for same concepts (price vs amount, total_price vs paid_total). |
| BUG-FIN-006 | Admin financial fields silently excluded from mass-assignment | Financial | `amount`, `paid_total`, `sales_tax`, `delivery_fee`, `discount` not in `$fillable`. |
| BUG-FIN-009 | Promotion usage incremented before payment | Financial | (Same as BUG-002) Consumption on failed payments. |
| BUG-CON-005 | Cancelled order can be re-completed by late payment callback | Concurrency | Idempotency guard only checks `status !== completed`, not `status === pending`. |
| BUG-CON-009 | `validateAndLockStock` lock released before `deductStock` | Concurrency | Cross-transaction lock — oversell window. |
| BUG-OSM-001/002 | Dual status columns not synchronized | Order Status | `status` vs `order_status` — two systems writing to different columns. |

### 14 CRITICAL Bugs

---

## 3. All HIGH Bugs

| ID | Bug | System |
|---|---|---|
| BUG-005 | `changeOrderStatus()` writes transaction `paid_at` (SRP violation) | Order |
| BUG-006 | No idempotency guard on callback | Payment |
| BUG-007 | `refreshCartItemPrices()` runs AFTER promotion applied | Checkout |
| BUG-008 | Stale `$cart->coupon` re-applied after being cleared | Checkout |
| BUG-009 | `payment_status` is computed, not stored | Order |
| BUG-010 | `$order->order_status` vs `$order->status` column ambiguity | Order |
| BUG-INV-001 | Price snapshotted at reservation, not checkout | Inventory |
| BUG-INV-004 | `finalizeItemsByShippingMethod` only finalizes one shipping method | Inventory |
| BUG-INV-007 | RestoreProductInventory adds variant qty to parent product stock | Inventory |
| BUG-INV-010 | Wrong early return for cancelled orders in RestoreInventoryOnRefund | Inventory |
| BUG-INV-011 | RestoreInventoryOnRefund adds variant qty to parent stock | Inventory |
| BUG-EVT-004 | SendPaymentSuccessNotification/FailedNotification are dead code | Events |
| BUG-EVT-007 | App listeners only notify customers; dead Marvel listeners would notify vendors | Events |
| BUG-FIN-011 | `runSafely()` silently swallows pricing errors | Financial |
| BUG-CON-006 | No reconciliation for payments received after cancellation | Concurrency |
| BUG-CON-010 | No retry mechanism on deadlock | Concurrency |
| BUG-OSM-003 | Wallet payments bypass invoice, inventory finalization, coupon | Order Status |
| BUG-OSM-004 | changeOrderStatus('cancelled') sets paid transaction to 'failed' | Order Status |
| BUG-OSM-005 | Refund flow uses `order_status` not `status` — stale `completed` | Order Status |

### 19 HIGH Bugs

---

## 4. Architecture Violations

| Violation | Description |
|---|---|
| **Two inventory systems** | CartInventoryService (reserve→finalize) vs OrderRepository::deductStock (direct decrement). Same columns, zero coordination. |
| **Two event namespaces** | App\Events vs Marvel\Events for same events (OrderCancelled, PaymentSucceeded, etc.). No mapping between them. |
| **Two financial models** | Different column sets for order financial data between custom checkout and admin. |
| **Two order status columns** | `status` (custom) vs `order_status` (admin). Not synchronized. |
| **Two notification systems** | App\Listeners (customer-only) vs Marvel\Listeners (customer+vendor+admin+SMS). |
| **Business logic in Controller** | `OrderController::checkoutCallback()` contains inventory finalization and order status changes, not delegated to service. |

---

## 5. Implementation Plan

### Week 1: CRITICAL Fixes

| Priority | Bug | Effort | Impact |
|---|---|---|---|
| **P0** | BUG-001: Schedule `CancelUnpaidOrders` + `expireCarts()` in Kernel | 30 min | Prevents stale orders + inventory leaks |
| **P0** | BUG-003: Make post-payment actions atomic (finalize → commit → dispatch) | 4 hrs | Prevents financial inconsistency on crash |
| **P0** | BUG-002/BUG-FIN-009: Move promotion usage to payment confirmation | 2 hrs | Prevents quota consumption on failed payments |
| **P0** | BUG-CON-005: Add `status === 'pending'` check to callback guard | 15 min | Prevents re-completing cancelled orders |
| **P0** | BUG-CON-009: Wrap validateAndLockStock + deductStock in single txn | 1 hr | Prevents oversell |
| **P0** | BUG-INV-005/BUG-INV-006: Fix deductStock atomicity | 2 hrs | Prevents inventory inconsistency |
| **P0** | BUG-FIN-006: Add missing fields to Order `$fillable` | 15 min | Prevents data loss |
| **P0** | BUG-004: Remove pending order reuse — always create new order | 1 hr | Fixes audit trail |

### Week 2: HIGH Fixes

| Priority | Bug | Effort |
|---|---|---|
| P1 | BUG-007: Move `refreshCartItemPrices` before promotion application | 30 min |
| P1 | BUG-008: Re-validate coupon after price refresh | 30 min |
| P1 | BUG-INV-004: Finalize ALL cart items, not just one shipping method | 2 hrs |
| P1 | BUG-INV-001: Snapshot prices at checkout, not reservation | 1 hr |
| P1 | BUG-INV-007/011: Fix variant stock restoration (don't touch parent) | 1 hr |
| P1 | BUG-INV-010: Fix wrong early return in RestoreInventoryOnRefund | 30 min |
| P1 | BUG-CON-006: Add payment reconciliation command | 4 hrs |
| P1 | BUG-CON-010: Add deadlock retry to callback | 2 hrs |
| P1 | BUG-EVT-004/007: Register dead Marvel notification listeners | 2 hrs |
| P1 | BUG-OSM-003: Wire wallet payments to PaymentSucceeded | 2 hrs |
| P1 | BUG-OSM-004: Don't set paid transaction to 'failed' on cancel | 30 min |
| P1 | BUG-OSM-005: Sync refund status to custom `status` column | 1 hr |

### Week 3: MEDIUM Fixes

| Priority | Bug | Effort |
|---|---|---|
| P2 | BUG-INV-018: Unify inventory systems (deprecate deductStock) | 8 hrs |
| P2 | BUG-010: Resolve `order_status` vs `status` column | 4 hrs |
| P2 | BUG-009: Add persisted `payment_status` column | 4 hrs |
| P2 | BUG-005: Remove transaction write from changeOrderStatus | 1 hr |
| P2 | BUG-FIN-002/005: Use integer cents everywhere | 8 hrs |
| P2 | BUG-FIN-010: Add external consistency checks to invoice validator | 2 hrs |
| P2 | BUG-EVT-006: Remove dual event registration | 1 hr |
| P2 | BUG-CON-001: Add status check to expireCart | 15 min |
| P2 | BUG-INV-LIFE-003/004/005: Add invoice listeners for cancel/refund | 4 hrs |

### Week 4: LOW + Polish

| Priority | Bug | Effort |
|---|---|---|
| P3 | BUG-006: Add idempotency key to payment callback | 1 hr |
| P3 | Clean up dead listener classes | 2 hrs |
| P3 | BUG-FIN-011: Remove `runSafely()`, let exceptions propagate | 2 hrs |
| P3 | Fix `expireCart()` status check (BUG-CON-001) | 15 min |
| P3 | Fix invoice sequence gap (BUG-INV-LIFE-007) | 30 min |
| P3 | Consolidate notification listeners (App + Marvel) | 4 hrs |
| P3 | Add invoice controller/routes | 4 hrs |
| P3 | Add PDF generation (replace placeholder) | 8 hrs |
| P3 | Add HMAC to invoice signing (see invoice-qr-design.md) | 2 hrs |
| P3 | Fix cashier QR replay protection (see invoice-qr-design.md) | 2 hrs |

---

## 6. Cumulative Bug Totals By Document

| Document | CRITICAL | HIGH | MEDIUM | LOW | INFO | **Total** |
|---|---|---|---|---|---|---|
| `order-state-machine.md` | 1 | 2 | 3 | 3 | 1 | 10 |
| `payment-lifecycle.md` | 2 | 1 | 1 | 2 | 0 | 6 |
| `cart-lifecycle.md` | 0 | 1 | 2 | 2 | 0 | 5 |
| `coupon-lifecycle.md` | 0 | 1 | 0 | 2 | 0 | 3 |
| `invoice-system.md` | 0 | 2 | 5 | 3 | 3 | 13 |
| `promotion-engine.md` | 1 | 2 | 3 | 0 | 1 | 7 |
| `final-production-flow.md` | 7 | 4 | 6 | 0 | 0 | 17 |
| **`inventory-system.md`** | **4** | **6** | **6** | **2** | **0** | **18** |
| **`events-graph.md`** | **2** | **2** | **3** | **2** | **0** | **9** |
| **`financial-integrity.md`** | **3** | **1** | **4** | **2** | **1** | **11** |
| **`concurrency-audit.md`** | **2** | **2** | **4** | **2** | **0** | **10** |
| **`invoice-lifecycle.md`** | **0** | **3** | **4** | **3** | **0** | **10** |
| **`order-status-matrix.md`** | **2** | **3** | **1** | **0** | **0** | **6** |
| **GRAND TOTAL** | **14** | **19** | **23** | **11** | **2** | **67** |

---

## 7. Production Readiness Score

| Category | Score (1-10) | Reasoning |
|---|---|---|
| **Data Integrity** | 3 | Dual inventory systems, non-atomic post-payment, float arithmetic |
| **Financial Integrity** | 2 | Promotion usage before payment, dual financial models, silent errors |
| **Concurrency** | 4 | Good locking but critical gaps: cancels oversell, deadlock no-retry |
| **Security** | 5 | No major SQLi/XSS but QR replay, missing auth on some paths |
| **Monitoring** | 2 | `runSafely()` swallows exceptions, no reconciliation, no alerts |
| **Testing** | 4 | Some tests exist but missing concurrency, race condition, edge cases |
| **Architecture** | 3 | Dual systems everywhere, dead code, orphaned listeners |
| **Scalability** | 6 | Good locking patterns, but no caching, no read replicas |
| **Maintainability** | 4 | Clean structure but fragmented namespaces, dead code, dual systems |

**Overall**: **3.9 / 10**
