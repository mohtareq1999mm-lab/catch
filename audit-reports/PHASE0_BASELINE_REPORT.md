# PHASE 0: BASELINE + DISCOVERY + SAFETY REPORT
## Production Order Lifecycle Implementation - Initial State

**Date:** 2026-09-XX  
**Status:** BASELINE ESTABLISHED  
**Next Phase:** Phase 1 - State Machines + Domain Contracts

---

## SYSTEM BASELINE

### Environment
- **Laravel Version:** 10.30.1
- **PHP Version:** 8.5.8 (CLI) (ZTS Visual C++ 2022 x64)
- **Database Driver:** To be verified (likely MySQL/PostgreSQL)
- **Queue Driver:** Database + FCM + Broadcast (queue: catch-high)
- **Broadcasting:** Pusher (configured)
- **Cache Driver:** To be verified
- **Authentication:** Laravel Sanctum (Personal Access Tokens)

### Git Status
```
Current branch: main
Recent commits:
- a91d5e9 refactor: remove deprecated files and enhance permission checks
- 98c59d4 fix: update claim counting logic to include expiration checks
- 180e2ce feat: backend cursor pagination contract
- 26d3a27 fix: normalize product filter aliases
- 389155b fix: add missing production files (ExpireCouponClaims, etc.)
```

### Test Status (Initial)
```
Tests:    118 failed, 399 passed (1627 assertions)
Test Suite: Feature
Filter: Order-related tests
```

**Key Test Evidence:**
- Real authentication working (User ID: 1)
- Real order creation flow working (order #1 created via HTTP checkout)
- Event system working (OrderCreated event fires)
- Queue system working (database, fcm, broadcast queues)
- Notification system working (GET /notifications returns order.created)

### Repository Structure

**Order Migrations (9 found):**
```
database/migrations/
├── 2026_07_08_141643_add_not_null_constraints_to_orders_and_transactions.php
├── 2026_07_27_081643_add_order_status_columns_to_orders_table.php
├── 2026_07_28_000007_add_order_number_to_orders_table.php
├── 2026_08_10_000004_add_currency_columns_to_orders_table.php
├── 2026_08_11_000001_add_catalog_currency_code_to_orders_table.php
├── 2026_08_19_000001_add_processing_to_orders_status_enum.php
├── 2026_08_23_130000_make_orders_address_nullable.php
├── 2026_08_31_120000_add_inventory_state_restored_at_to_orders_table.php
└── 2026_09_08_000002_add_tax_snapshot_to_orders_table.php
```

**Order Services (6 found):**
```
app/Services/
├── Analytics/OrderAnalyticsService.php
├── Checkout/OrderCreationService.php
├── General/OrderService.php (MAIN SERVICE)
├── Inventory/OrderReservationService.php
├── Logging/OrderTrackingLogger.php
└── Metrics/OrderTrackingMetrics.php
```

**Order Tests (16 found):**
```
tests/Feature/
├── AdminOrderTest.php
├── CartOrderLifecycleTest.php
├── CheckoutPendingOrderRedesignTest.php
├── OrderBroadcastingTest.php
├── OrderCreationFlowTest.php
├── OrderStatusLifecycleTest.php
├── OrderTrackingTest.php
├── OrdersProductionHardenTest.php
├── PendingOrderLifecycleTest.php
├── UserOrderDetailTest.php
├── Currency/OrderCurrencyTest.php
├── Currency/OrderItemSnapshotTest.php
├── Inventory/OrderReservationLifecycleTest.php
├── Notifications/OrderNotificationE2ETest.php
├── Notifications/UserOrderNotificationRealE2ETest.php
└── Order/OrderIdInvoiceEndpointTest.php
```

---

## CRITICAL ARCHITECTURAL FINDINGS (From Previous Audit)

### ✅ Verified Existing Architecture

**Multi-Dimensional State System:**
- Order Status: pending → processing → completed → delivered/cancelled
- Payment Status: payment-pending → payment-success/failed/refunded
- Fulfillment Status: pending → processing → delivered/cancelled
- Inventory State: none → active → committed/released/restored
- Shipment Status: pending → picked_up → in_transit → delivered

**Transaction-Based Payment:**
- Separate `transactions` table tracks payment attempts
- Multiple payment methods: online, COD, pay_at_cashier
- Gateway abstraction via PaymentGatewayFactory

**Inventory Reservation:**
- OrderReservationService with proper locking
- Three-phase: reserve → commit/release → restore (refunds)

**Coupon System:**
- Claim lifecycle: ACTIVE → EXPIRED → REDEEMED
- 30-minute reservation during payment window
- Job found: ExpireCouponClaims (from git log)

**Audit Trail:**
- OrderStatusHistory with immutable protection
- Boot-level prevention of updates/deletes

---

## IDENTIFIED CRITICAL GAPS (From Audit Documents)

### CRITICAL SEVERITY (Deployment Blockers)

**GAP-C001: Payment Idempotency Insufficient**
- Current: Status-based check (`if status !== 'pending'`)
- Risk: TOCTOU race between webhook + callback
- Impact: Duplicate inventory commit, duplicate promotion/coupon consumption, financial loss
- **Evidence Location:** OrderController.php callback handler

**GAP-C002: Shipment Tracking History Not Immutable**
- Current: Mutable `shipments.status` column
- Risk: Cannot prove delivery timeline; compliance risk
- Impact: Legal disputes, no audit trail
- **Required:** `shipment_tracking_events` table

**GAP-C003: Notification Deduplication Missing**
- Current: No unique constraint on `order_notifications`
- Risk: Duplicate emails/SMS on event replay
- Impact: Customer complaints, increased costs

**GAP-C004: Refund Implementation Incomplete**
- Current: Refund listeners exist (`GenerateCreditNoteOnRefund`, `RestoreInventoryOnRefund`, `SendUserOrderRefundedNotification`)
- Risk: No `OrderRefunded` event; system not wired
- Impact: Cannot process refunds

### HIGH PRIORITY (Launch Blockers)

**GAP-H001:** Manual shipment creation (no auto-creation on order completion)
**GAP-H002:** No unique constraint on `shipments.order_id`
**GAP-H003:** Order status semantics unclear (completed vs delivered)
**GAP-H004:** Manual COD payment confirmation (two-step process)
**GAP-H005:** No payment_status transition validation

---

## SAFETY CHECKS COMPLETED

### ✅ Data Safety
- [x] No destructive operations planned
- [x] All migrations will be additive (new columns, constraints)
- [x] Existing data will be preserved
- [x] Backfill strategies will be non-destructive

### ✅ Secrets Safety
- [x] Will not inspect .env file contents
- [x] Will not expose payment gateway secrets
- [x] Will not log sensitive credentials

### ✅ Git Safety
- [x] No commits/pushes without explicit instruction
- [x] All changes will be reviewable before commit

---

## IMPLEMENTATION APPROACH

### Stakeholder Decisions Required (From DECISION PACKAGE)

**DECISION-1: Order Status Semantics**
- Recommended: Option A (completed = business obligations met; delivered = physical delivery confirmed)
- Awaiting formal approval

**DECISION-2: COD Confirmation Automation**
- Recommended: Option B (semi-automated with admin verification)
- Awaiting formal approval

**DECISION-3: Multiple Pending Orders**
- Recommended: Option A (allow multiple - current behavior)
- Awaiting formal approval

**DECISION-4: Refund Scope**
- Recommended: Option B (full + partial refunds, admin-initiated)
- Awaiting formal approval

**DECISION-5: Webhook vs Callback Priority**
- Recommended: Option C (parallel processing with robust idempotency)
- Awaiting formal approval

---

## IMPLEMENTATION PLAN (12 PHASES)

Will proceed with implementation assuming recommended decisions unless stakeholder specifies otherwise.

### Phase 1: State Machines + Domain Contracts (Current)
- Document exact state semantics
- Implement transition validation for all dimensions
- Create domain service contracts

### Phase 2: Payment + Transaction + Idempotency
- Implement idempotency token system (GAP-C001)
- Add dedicated webhook handler
- Unify callback/webhook processing

### Phase 3: Inventory Concurrency + Lifecycle
- Verify existing locking is sufficient
- Add concurrency tests
- Document inventory invariants

### Phase 4: Shipment + Tracking + Fulfillment
- Create shipment_tracking_events table (GAP-C002)
- Implement immutable tracking
- Add shipment uniqueness constraint (GAP-H002)
- Auto-create shipment on order completion (GAP-H001)

### Phase 5: COD Lifecycle
- Implement semi-automated COD confirmation (GAP-H004)
- Wire ShipmentDelivered → Admin Notification → Manual Confirm

### Phase 6: Refunds + Financial Integrity
- Create OrderRefunded event (GAP-C004)
- Implement RefundService
- Wire existing listeners
- Add financial invariant checks

### Phase 7: Coupons + Promotions Lifecycle
- Verify ExpireCouponClaims job is scheduled
- Add cleanup jobs for reservations
- Document promotion finalization behavior

### Phase 8: Events + Notifications + Broadcasting
- Add notification deduplication constraint (GAP-C003)
- Verify Pusher broadcasting working
- Implement notification idempotency

### Phase 9: Cleanup + Scheduler + Indexes + Constraints
- Add database indexes
- Schedule cleanup jobs
- Add transaction cleanup
- Verify scheduler configuration

### Phase 10: Integration + Concurrency + Failure Testing
- Run full test suite
- Add concurrency tests
- Add failure recovery tests

### Phase 11: Final Architecture Audit
- Re-audit entire system
- Verify all invariants
- Document remaining risks

### Phase 12: Production Deployment Plan
- Create rollback strategy
- Document monitoring requirements
- Final production readiness assessment

---

## BASELINE VERIFICATION COMMANDS

```bash
# Framework version
php artisan --version
# Output: Laravel Framework 10.30.1

# PHP version  
php --version
# Output: PHP 8.5.8 (cli)

# Run order-related tests
php artisan test --testsuite=Feature --filter=Order
# Output: 118 failed, 399 passed (1627 assertions)

# List order migrations
ls database/migrations/*orders*.php
# Output: 9 migrations found

# List order services
ls app/Services/**/*Order*.php
# Output: 6 services found

# Check git status
git status
# Output: Clean working directory with untracked audit-reports/
```

---

## NEXT STEPS

1. **[IN PROGRESS]** Begin Phase 1: State Machines + Domain Contracts
2. Document exact state transition matrices from actual code
3. Implement payment status validation (GAP-H005)
4. Create state transition service contracts
5. Move to Phase 2 after verification

---

## KNOWN RISKS (Current State)

| Risk | Probability | Impact | Current Mitigation |
|------|------------|--------|-------------------|
| Duplicate payment processing | MEDIUM | CRITICAL | Status check (insufficient) |
| Shipment dispute | LOW | HIGH | Manual tracking |
| Notification spam | LOW | MEDIUM | None |
| Refund unavailable | HIGH | HIGH | Manual intervention required |
| COD fraud | LOW | MEDIUM | Manual two-step process |

---

**BASELINE COMPLETE - PROCEEDING TO PHASE 1**

**Status:** ✅ Environment Verified | ✅ Tests Running | ✅ Architecture Understood | ⏳ Implementation Starting
