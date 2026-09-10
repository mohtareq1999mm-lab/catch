# IMPLEMENTATION CONTEXT - BUSINESS RULES

**Date**: 2026-08-31
**Session**: Deep Discovery Complete

---

## DISCOVERY SUMMARY

### ✅ ALREADY IMPLEMENTED (15 of 26 rules)

1. **Cart De-Reservation** (Rules 1-2)
   - `CartInventoryService` refactored Aug 31, 2026
   - Cart NEVER reserves inventory
   - Comments confirm: "NEVER touches inventory counters"
   - No `reserveStock()` or `releaseStock()` methods
   
2. **Order-Owned Inventory** (Rules 3, 6-8, 13, 18)
   - `OrderReservationService` exists
   - Methods: `reserveForOrder()`, `commit()`, `release()`
   - State machine: none → active → committed/released
   - Migration: `2026_08_26_100001_add_inventory_reservation_state_to_orders.php`
   - Digital products excluded via `item_type='digital'` check
   
3. **Order Expiration** (Rules 13-14)
   - `CancelUnpaidOrders` command exists
   - 24-hour TTL with defensive gateway check
   - Releases inventory, does NOT consume coupon/promotion
   - Already race-safe with `lockForUpdate()`
   
4. **Coupon Consumption** (Rules 9, 11-12, 14, 16)
   - `OrderService::recordCouponUsage()` exists
   - Called in `changeOrderStatus()` when status becomes 'completed'
   - Increments `coupon.used` and `assignment.used`
   - Creates `CouponUsage` and `CouponAssignmentUsage` records
   - Sets `order.coupon_consumed = true`
   - Idempotent via `coupon_consumed` flag
   
5. **Promotion Consumption** (Rules 15, 21)
   - `OrderService::finalizePromotionUsageAfterPayment()` exists
   - Called in payment success flows (online, COD, cashier)
   - Increments `promotion.used`
   - Sets `order.promotion_consumed = true`
   - Idempotent
   
6. **Payment Success Flow** (Rules 8, 23-24)
   - `OrderController::checkoutCallback()` handles online payment
   - `OrderService::markCodAsPaid()` handles COD
   - `OrderService::markCashierPaid()` handles cashier
   - All use `DB::transaction` with `lockForUpdate()`
   - Idempotent via status check
   
7. **Order Snapshot Immutable** (Rules 19-21)
   - Order never recalculated after creation
   - Flash sale pricing snapshotted
   - Promotion data snapshotted
   
8. **State Machines** (Rules 25-26)
   - Order status enforced in `changeOrderStatus()`
   - Inventory state enforced in `OrderReservationService`
   - Payment status tracked in `orders.payment_status`

### ❌ NOT IMPLEMENTED (11 of 26 rules)

1. **Pending Order Reuse** (Rules 4-5)
   - `findPendingOrderForUser()` EXISTS but NOT USED
   - Current flow: every checkout creates a NEW order
   - Test confirms: `test_second_checkout_after_refill_creates_independent_order`
   - **NEEDS**: Integration into checkout flow
   
2. **Coupon Reservation** (Rules 9-10)
   - No `CouponReservation` model/table
   - No reservation service
   - No scheduled expiration command
   - **NEEDS**: Full implementation
   
3. **Paid Order Cancellation** (Rule 17)
   - Inventory restoration NOT implemented
   - `changeOrderStatus()` has promotion decrement (line 627) which violates Rule 17
   - **NEEDS**: `InventoryRestoreService` + fix promotion decrement logic

---

## DATABASE SCHEMA STATUS

### ✅ EXISTS
- `orders.coupon_consumed` (boolean)
- `orders.promotion_consumed` (boolean)
- `orders.inventory_state` (enum)
- `orders.inventory_reserved_at` (timestamp)
- `orders.reservation_expires_at` (timestamp)
- `orders.payment_status` (string)
- `orders.fulfillment_status` (string)
- `orders.paid_at` (timestamp)
- `orders.completed_at` (timestamp)
- `orders.cancelled_at` (timestamp)
- `coupon_usages` table
- `coupon_assignment_usages` table

### ❌ MISSING
- `coupon_reservations` table (30min TTL)
- `orders.inventory_state_restored_at` (for paid cancellation tracking)

---

## KEY ARCHITECTURAL DECISIONS

### Decision 1: Pending Order Reuse Strategy

**Problem**: Cart is deleted after checkout, so how do we detect "retry" vs "new checkout"?

**Current Flow**:
```
Checkout #1:
  Cart + items → Order + OrderItems
  CartItems deleted
  Cart row survives (empty)

User adds items again:
  Cart row reused, new CartItems

Checkout #2:
  Currently creates ANOTHER order
```

**Required Flow** (Rule 5):
```
Checkout #1:
  Cart + items → Order A (pending)
  CartItems deleted

Payment fails/abandoned:
  Order A remains pending

Checkout #2 (retry):
  Must reuse Order A
  Must NOT create Order B
```

**Solution**: 
- Use `findPendingOrderForUser()` at the START of checkout
- If pending order exists AND cart is empty: it's a retry (reuse)
- If pending order exists AND cart has items: it's a cart change (update order OR create new)
- If no pending order: create new

**Trade-off**: This means a user can have AT MOST one pending order at a time.

### Decision 2: Coupon Reservation Timing

**Analysis**: The analysis suggests:
```
Online payment:
  Checkout → Order created → Payment initiation → Coupon reserved → Gateway
```

But this creates a problem:
- If coupon reservation fails AFTER gateway invoice created, payment URL is invalid

**Better Flow**:
```
Online payment:
  Checkout → Order created → Coupon reserved → Payment initiation → Gateway
  
  If coupon reservation fails:
    Rollback entire transaction (no order, no payment attempt)
```

**Implementation**: Reserve coupon in `PaymentCheckoutHandler::handleOnlinePayment()` BEFORE gateway call.

### Decision 3: Promotion Decrement on Cancellation

**Current Code** (OrderService.php:627):
```php
if ($status === 'cancelled' && $previousStatus !== 'cancelled') {
    $this->orderReservationService->release($order);
    $this->promotionService->decrementUsage($order->promotion_id);  // WRONG!
}
```

**Problem**: This decrements promotion usage even for PAID orders (Rule 17 violation).

**Fix**: Only decrement if order was NEVER paid:
```php
if ($status === 'cancelled' && $previousStatus !== 'cancelled') {
    $this->orderReservationService->release($order);
    
    // Only decrement if order was never paid (pre-payment cancellation)
    if ($order->payment_status !== Order::PAYMENT_STATUS_SUCCESS) {
        $this->promotionService->decrementUsage($order->promotion_id);
    }
}
```

---

## IMPLEMENTATION PHASES

### Phase 1: Fix Existing Issues (2-3 hours)
1. Fix promotion decrement logic (Rule 17)
2. Add `inventory_state_restored_at` column
3. Verify all tests pass

### Phase 2: Coupon Reservation (4-6 hours)
1. Create `coupon_reservations` migration
2. Create `CouponReservation` model
3. Create `CouponReservationService`
4. Integrate into payment flows
5. Create `ExpireCouponReservations` command
6. Add tests

### Phase 3: Pending Order Reuse (3-4 hours)
1. Integrate `findPendingOrderForUser()` into checkout
2. Handle retry scenario
3. Handle cart-changed scenario
4. Update tests
5. Verify no duplicate orders

### Phase 4: Paid Order Cancellation (2-3 hours)
1. Create `InventoryRestoreService`
2. Integrate into `changeOrderStatus()`
3. Add tests

### Phase 5: End-to-End Testing (2-3 hours)
1. Run all existing tests
2. Add missing integration tests
3. Verify all 26 rules

**Total Estimated Time**: 13-19 hours

---

## CRITICAL CONSTRAINTS

1. **NO API CHANGES**: Existing endpoints must work unchanged
2. **NO SCHEMA DESTRUCTION**: Only add columns, never remove
3. **BACKWARD COMPATIBLE**: Old orders/transactions must remain valid
4. **TEST-FIRST**: Never break existing tests
5. **PRODUCTION READY**: Every change must be transaction-safe

---

## TEST COVERAGE STATUS

### ✅ EXISTS
- `CheckoutPendingOrderRedesignTest.php` (comprehensive)
- `AssignedCouponSystemTest.php`
- `CouponSystemTest.php`
- `OrderReservationLifecycleTest.php`
- `CartApiTest.php`

### ❌ MISSING
- Pending order reuse tests
- Coupon reservation concurrency tests
- Paid order cancellation tests
- Payment retry tests

---

## NEXT STEPS

1. **START HERE**: Fix promotion decrement bug (Phase 1)
2. Implement coupon reservation (Phase 2)
3. Implement pending order reuse (Phase 3)
4. Implement paid cancellation (Phase 4)
5. Comprehensive testing (Phase 5)

