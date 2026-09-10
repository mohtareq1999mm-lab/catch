# COMPLIANCE AUDIT - 26 BUSINESS RULES

**Date**: 2026-08-31
**Audit Type**: Deep Repository Discovery
**Status**: Implementation Gap Analysis

---

## EXECUTIVE SUMMARY

**Compliant**: 15/26 rules (58%)
**Non-Compliant**: 11/26 rules (42%)

**Critical Issues**:
1. Pending order reuse not implemented (Rules 4-5)
2. Coupon reservation missing (Rules 9-10)
3. Promotion decrement on paid cancellation violates Rule 17
4. Paid order inventory restoration not implemented (Rule 17)

---

## DETAILED COMPLIANCE MATRIX

### ✅ RULE 1: Cart NEVER reserves inventory
**Status**: ✅ FULLY COMPLIANT
**Evidence**: `CartInventoryService.php` refactored Aug 31, 2026
**Code**: 
```php
/**
 * The cart is ONLY the user's current shopping selection:
 * CRUD, duplicate merging, quantity validation, pricing snapshots and
 * abandoned-cart activity tracking. It NEVER touches inventory counters
 */
```
**Methods**: No `reserveStock()` or `releaseStock()` exist
**Verification**: ✅ Complete

---

### ✅ RULE 2: Checkout transfers cart to order and removes cart items
**Status**: ✅ FULLY COMPLIANT
**Evidence**: `OrderService::addItemsInOrder()` line 255-259
**Code**:
```php
$this->orderReservationService->reserveForOrder($order);
// ... 
$this->cartInventoryService->clearCheckedOutSlice($cart, ShippingMethod::SCHEDULED);
```
**Flow**: Order created → Inventory reserved → Cart items deleted
**Verification**: ✅ Complete

---

### ✅ RULE 3: Order OWNS inventory reservation (24h TTL)
**Status**: ✅ FULLY COMPLIANT
**Evidence**: `OrderReservationService.php` complete implementation
**State Machine**:
- none → active: `reserveForOrder()`
- active → committed: `commit()`
- active → released: `release()`
**TTL**: Configurable via `config('payment.order_timeout_hours', 24)`
**Columns**: `inventory_state`, `inventory_reserved_at`, `reservation_expires_at`
**Migration**: `2026_08_26_100001_add_inventory_reservation_state_to_orders.php`
**Verification**: ✅ Complete

---

### ❌ RULE 4: No duplicate pending orders for same checkout context
**Status**: ❌ NON-COMPLIANT
**Evidence**: Test `test_second_checkout_after_refill_creates_independent_order()` line 248
**Current Behavior**:
```php
// First checkout creates Order A (pending)
$this->checkout();

// User refills cart
$cart->incrementItem(...);

// Second checkout creates Order B (pending) <- VIOLATION
$this->checkout();

$orders = Order::where('user_id', $userId)->where('status', 'pending')->get();
$this->assertCount(2, $orders); // Test EXPECTS duplicate orders
```
**Root Cause**: `OrderCreationService::findPendingOrderForUser()` EXISTS but NOT CALLED
**Gap**: Checkout flow always creates new order via `createOrder()`, never calls `findPendingOrderForUser()`
**Verification**: ❌ Violation confirmed

---

### ❌ RULE 5: Payment retry reuses pending order
**Status**: ❌ NON-COMPLIANT
**Evidence**: Same as Rule 4 - no pending order reuse mechanism
**Current Flow**:
```
Payment attempt 1: Cart → Order A → Payment fails
User attempts again: Cart (refilled) → Order B <- VIOLATION
```
**Required Flow**:
```
Payment attempt 1: Cart → Order A → Payment fails
User attempts again: Reuse Order A (update if cart changed)
```
**Gap**: No retry detection logic exists
**Verification**: ❌ Not implemented

---

### ✅ RULE 6: Online payment reserves at checkout
**Status**: ✅ FULLY COMPLIANT
**Evidence**: `OrderService::addItemsInOrder()` line 255
**Flow**:
```php
// 1. Create order
$order = $this->orderCreationService->createOrder(...);

// 2. Reserve inventory IMMEDIATELY
$this->orderReservationService->reserveForOrder($order);

// 3. Then initiate payment gateway
$paymentHandler->handleOnlinePayment(...);
```
**Timing**: Reservation happens BEFORE `PaymentCheckoutHandler::handleOnlinePayment()`
**Verification**: ✅ Complete

---

### ✅ RULE 7: COD reserves at checkout
**Status**: ✅ FULLY COMPLIANT
**Evidence**: Same as Rule 6 - reservation happens in checkout flow, not payment method specific
**Verification**: ✅ Complete

---

### ✅ RULE 8: Cashier payment reserves at checkout
**Status**: ✅ FULLY COMPLIANT
**Evidence**: Same as Rule 6 - reservation happens in checkout flow
**Verification**: ✅ Complete

---

### ⚠️ RULE 9: Coupon reserved at payment initiation
**Status**: ❌ NON-COMPLIANT (partially correct timing)
**Current Behavior**:
- Coupon validated at checkout (eligibility check)
- Coupon code stored in `order.coupon` column
- Coupon consumed at payment success via `recordCouponUsage()`
**Gap**: NO temporary reservation during payment window
**Risk**: Two users can reserve last-use coupon simultaneously
**Evidence**: No `CouponReservation` model or table exists
**Verification**: ❌ Missing reservation mechanism

---

### ❌ RULE 10: Single-use coupon reservation prevents double-booking
**Status**: ❌ NON-COMPLIANT
**Evidence**: No reservation system = no double-booking prevention
**Scenario**:
```
User A: Checkout with coupon "SAVE50" (1 use left) → Order A (pending)
User B: Checkout with coupon "SAVE50" (1 use left) → Order B (pending)
User A: Pays successfully → Coupon consumed (used=1)
User B: Pays successfully → Coupon consumed (used=2) <- VIOLATION
```
**Current Check**: Only validates `coupon.usage < coupon.limiter` at checkout
**Missing**: Temporary reservation with 30min TTL
**Verification**: ❌ Not implemented

---

### ✅ RULE 11: Coupon consumption increments global counter
**Status**: ✅ FULLY COMPLIANT
**Evidence**: `OrderService::recordCouponUsage()` line 750
**Code**:
```php
$coupon->increment('used');
```
**Verification**: ✅ Complete

---

### ✅ RULE 12: Assigned coupon quota enforcement
**Status**: ✅ FULLY COMPLIANT
**Evidence**: `OrderService::recordCouponUsage()` line 754-764
**Code**:
```php
$assignment = CouponAssignment::where('coupon_id', $coupon->id)
    ->where('user_id', $order->user_id)
    ->lockForUpdate()
    ->first();

$coupon->increment('used');
$assignment->increment('used');
CouponAssignmentUsage::create([...]);
```
**Verification**: ✅ Complete

---

### ✅ RULE 13: Order expires after 24h
**Status**: ✅ FULLY COMPLIANT
**Evidence**: `CancelUnpaidOrders` command
**Code**:
```php
$orders = Order::query()
    ->where('status', 'pending')
    ->where('inventory_state', Order::INVENTORY_STATE_ACTIVE)
    ->where('reservation_expires_at', '<=', now())
    ->cursor();
```
**Scheduling**: Must be configured in Laravel scheduler
**Verification**: ✅ Complete

---

### ✅ RULE 14: Expired order releases inventory, does NOT consume coupon
**Status**: ✅ FULLY COMPLIANT
**Evidence**: `CancelUnpaidOrders::handle()` line 40-50
**Code**:
```php
// Releases inventory
$this->orderReservationService->release($lockedOrder);

// Cancels order (no coupon consumption)
$lockedOrder->update(['status' => 'cancelled']);

// Events fired
event(new OrderStatusChanged($lockedOrder));
event(new OrderCancelled($lockedOrder));
event(new PaymentFailed($lockedOrder));
```
**Verification**: ✅ `recordCouponUsage()` NOT called for expired orders

---

### ✅ RULE 15: Payment failure does NOT consume promotion
**Status**: ✅ FULLY COMPLIANT
**Evidence**: `CancelUnpaidOrders` does NOT call `promotionService->decrementUsage()`
**Code**: Only `release()` called, no promotion manipulation
**Verification**: ✅ Complete

---

### ✅ RULE 16: Payment failure does NOT consume coupon
**Status**: ✅ FULLY COMPLIANT
**Evidence**: Same as Rule 14 - `recordCouponUsage()` only called on status='completed'
**Verification**: ✅ Complete

---

### ❌ RULE 17: Paid order cancellation restores inventory, does NOT decrement promotion
**Status**: ❌ NON-COMPLIANT (2 violations)

**Violation 1: Promotion Decrement on Paid Cancellation**
**Evidence**: `OrderService::changeOrderStatus()` line 627
**Code**:
```php
if ($status === 'cancelled' && $previousStatus !== 'cancelled') {
    $this->orderReservationService->release($order);
    $this->promotionService->decrementUsage($order->promotion_id); // WRONG!
}
```
**Problem**: This decrements promotion usage even for PAID orders (violates Rule 17)
**Current Logic**: 
- Paid order (payment_status='payment-success')
- Admin cancels order
- Promotion usage decremented ← VIOLATION

**Violation 2: No Inventory Restoration Service**
**Gap**: No service to restore committed inventory back to stock
**Current Behavior**: `release()` is called, which is a no-op for committed orders
**Evidence**: `OrderReservationService::release()` line 110-132
```php
public function release(Order $order): bool
{
    $claimed = Order::whereKey($order->id)
        ->where('inventory_state', Order::INVENTORY_STATE_ACTIVE) // Only works if active
        ->lockForUpdate()
        ->first();

    if (!$claimed) {
        return false; // Paid orders are 'committed', so this returns false
    }
    // ... release logic only runs for 'active' orders
}
```
**Required**: New method `restore()` to handle committed → restored transition
**Verification**: ❌ Double violation

---

### ✅ RULE 18: Digital products never reserve inventory
**Status**: ✅ FULLY COMPLIANT
**Evidence**: `OrderReservationService::aggregatePhysicalLines()` line 156-173
**Code**:
```php
return $order->orderItems()
    ->get()
    ->filter(function ($item) use ($hasItemType) {
        if ($hasItemType && $item->item_type === ItemType::DIGITAL) {
            return false; // D1 — digital lines hold no physical reservation
        }
        // ...
        return true;
    })
```
**Verification**: ✅ Complete

---

### ✅ RULE 19: Order snapshot immutable (product data)
**Status**: ✅ FULLY COMPLIANT
**Evidence**: Order never recalculated after creation
**Columns**: `order_products` stores complete snapshot (price, name, attributes, etc.)
**Verification**: ✅ Complete

---

### ✅ RULE 20: Order snapshot immutable (flash sale pricing)
**Status**: ✅ FULLY COMPLIANT
**Evidence**: Flash sale price captured at checkout, never recalculated
**Verification**: ✅ Complete

---

### ✅ RULE 21: Order snapshot immutable (promotion data)
**Status**: ✅ FULLY COMPLIANT
**Evidence**: Promotion data stored in order columns at creation
**Columns**: `promotion_id`, `promotion_code`, `promotion_type`, `promotion_discount`
**Verification**: ✅ Complete

---

### ✅ RULE 22: Inventory operations use row-level locking
**Status**: ✅ FULLY COMPLIANT
**Evidence**: `OrderReservationService::lockStockRow()` line 190-197
**Code**:
```php
private function lockStockRow(int $productId, ?int $variantId): Product|ProductVariant
{
    if ($variantId) {
        return ProductVariant::query()->whereKey($variantId)->lockForUpdate()->firstOrFail();
    }
    return Product::query()->whereKey($productId)->lockForUpdate()->firstOrFail();
}
```
**Verification**: ✅ All inventory operations use `lockForUpdate()`

---

### ✅ RULE 23: Coupon operations use row-level locking
**Status**: ✅ FULLY COMPLIANT
**Evidence**: `OrderService::recordCouponUsage()` line 754
**Code**:
```php
$assignment = CouponAssignment::where('coupon_id', $coupon->id)
    ->where('user_id', $order->user_id)
    ->lockForUpdate()
    ->first();
```
**Verification**: ✅ Complete

---

### ✅ RULE 24: Payment callbacks idempotent
**Status**: ✅ FULLY COMPLIANT
**Evidence**: `OrderController::checkoutCallback()` line 330-345
**Code**:
```php
DB::transaction(function () use ($order, $transaction, $paymentId, $verifiedInvoiceId, $result, &$processed) {
    $lockedTransaction = Transaction::where('gateway_transaction_id', $paymentId)
        ->orWhere('invoice_id', $paymentId)
        ->lockForUpdate()
        ->first();
    
    $lockedOrder = $lockedTransaction->order()->lockForUpdate()->first();
    
    if ($lockedOrder->status !== 'pending') {
        return; // Idempotency check - already processed
    }
    
    // ... process payment
});
```
**Verification**: ✅ Status check prevents double-processing

---

### ✅ RULE 25: Order status state machine enforced
**Status**: ✅ FULLY COMPLIANT
**Evidence**: `OrderService::changeOrderStatus()` enforces transitions
**Constants**: Order model defines valid statuses
**Verification**: ✅ Complete

---

### ✅ RULE 26: Inventory state machine enforced
**Status**: ✅ FULLY COMPLIANT
**Evidence**: `OrderReservationService` enforces state transitions
**States**: none → active → committed/released
**Guards**: All methods check current state before transition
**Code**:
```php
// reserveForOrder: only if state=none
if ($order->inventory_state !== Order::INVENTORY_STATE_NONE) {
    return; // no-op
}

// commit: only if state=active
$claimed = Order::whereKey($order->id)
    ->where('inventory_state', Order::INVENTORY_STATE_ACTIVE)
    ->lockForUpdate()
    ->first();

// release: only if state=active
$claimed = Order::whereKey($order->id)
    ->where('inventory_state', Order::INVENTORY_STATE_ACTIVE)
    ->lockForUpdate()
    ->first();
```
**Verification**: ✅ Complete

---

## IMPLEMENTATION REQUIREMENTS

### Priority 1: Fix Promotion Decrement Bug (Rule 17)
**File**: `app/Services/General/OrderService.php`
**Line**: 627
**Current**:
```php
if ($status === 'cancelled' && $previousStatus !== 'cancelled') {
    $this->orderReservationService->release($order);
    $this->promotionService->decrementUsage($order->promotion_id);
}
```
**Required**:
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

### Priority 2: Implement Coupon Reservation (Rules 9-10)

**Required Components**:

1. **Migration**: `create_coupon_reservations_table`
```php
Schema::create('coupon_reservations', function (Blueprint $table) {
    $table->id();
    $table->foreignId('coupon_id')->constrained()->onDelete('cascade');
    $table->foreignId('user_id')->constrained()->onDelete('cascade');
    $table->foreignId('order_id')->constrained()->onDelete('cascade');
    $table->timestamp('reserved_at');
    $table->timestamp('expires_at');
    $table->timestamps();
    
    $table->index(['coupon_id', 'expires_at']);
    $table->unique(['order_id']); // One reservation per order
});
```

2. **Model**: `app/Models/CouponReservation.php`

3. **Service**: `app/Services/Coupon/CouponReservationService.php`
- Method: `reserve(Order $order, Coupon $coupon): CouponReservation`
- Method: `consume(Order $order): void`
- Method: `release(Order $order): void`

4. **Command**: `app/Console/Commands/ExpireCouponReservations.php`
- Deletes reservations where `expires_at <= now()`
- Runs every 5 minutes

5. **Integration Points**:
- Reserve in `PaymentCheckoutHandler::handleOnlinePayment()` BEFORE gateway call
- Reserve in `PaymentCheckoutHandler::handleCodPayment()` BEFORE transaction creation
- Reserve in `PaymentCheckoutHandler::handleCashierQrPayment()` BEFORE transaction creation
- Consume in `OrderService::recordCouponUsage()`
- Release in `CancelUnpaidOrders` command

---

### Priority 3: Implement Pending Order Reuse (Rules 4-5)

**Required Changes**:

1. **Update**: `app/Services/Checkout/OrderCreationService.php`
- Add method: `updateOrder(Order $order, array $checkoutData): Order`

2. **Update**: `app/Services/General/OrderService.php::addItemsInOrder()`
**Current Flow**:
```php
public function addItemsInOrder(Cart $cart, ...) {
    // Always creates new order
    $order = $this->orderCreationService->createOrder(...);
}
```

**Required Flow**:
```php
public function addItemsInOrder(Cart $cart, ...) {
    // Check for existing pending order
    $pendingOrder = $this->orderCreationService->findPendingOrderForUser($cart->user_id);
    
    if ($pendingOrder) {
        // Retry scenario: reuse existing order
        $order = $this->orderCreationService->updateOrder($pendingOrder, $checkoutData);
    } else {
        // New checkout: create new order
        $order = $this->orderCreationService->createOrder(...);
    }
}
```

3. **Decision Logic**:
- If pending order exists: UPDATE it with new cart data
- Release old reservation
- Create new reservation with new items
- Keep same order_number, created_at
- Update totals, items, addresses

---

### Priority 4: Implement Paid Order Inventory Restoration (Rule 17)

**Required Components**:

1. **Migration**: Add column to orders table
```php
Schema::table('orders', function (Blueprint $table) {
    $table->timestamp('inventory_state_restored_at')->nullable()->after('reservation_expires_at');
});
```

2. **Service**: `app/Services/Inventory/InventoryRestoreService.php`
- Method: `restore(Order $order): bool`
- State: committed → restored (new state)
- Logic: Increment `stock_quantity`, decrement `sold_quantity`

3. **Update**: `Order` model constants
```php
public const INVENTORY_STATE_RESTORED = 'restored';
```

4. **Update**: `OrderService::changeOrderStatus()`
```php
if ($status === 'cancelled' && $previousStatus !== 'cancelled') {
    // Check if order was paid
    if ($order->payment_status === Order::PAYMENT_STATUS_SUCCESS 
        && $order->inventory_state === Order::INVENTORY_STATE_COMMITTED) {
        // Paid order: restore inventory
        $this->inventoryRestoreService->restore($order);
    } else {
        // Unpaid order: release reservation
        $this->orderReservationService->release($order);
    }
    
    // Only decrement if order was never paid
    if ($order->payment_status !== Order::PAYMENT_STATUS_SUCCESS) {
        $this->promotionService->decrementUsage($order->promotion_id);
    }
}
```

---

## TEST COVERAGE GAPS

### Missing Tests:
1. Pending order reuse on second checkout
2. Payment retry reuses same order
3. Coupon reservation prevents double-booking
4. Coupon reservation expires after 30min
5. Promotion NOT decremented on paid cancellation
6. Inventory restored on paid cancellation
7. Concurrent coupon usage (last-use scenario)
8. Multiple users attempting same last-use coupon

---

## DATABASE SCHEMA GAPS

### Missing Tables:
- `coupon_reservations`

### Missing Columns:
- `orders.inventory_state_restored_at`

### Missing States:
- `Order::INVENTORY_STATE_RESTORED`

---

## ESTIMATED IMPLEMENTATION TIME

| Priority | Task | Hours |
|----------|------|-------|
| P1 | Fix promotion decrement bug | 1-2 |
| P1 | Add inventory_state_restored_at column | 0.5 |
| P2 | Coupon reservation (full implementation) | 6-8 |
| P3 | Pending order reuse | 4-6 |
| P4 | Paid order inventory restoration | 3-4 |
| Tests | Comprehensive test coverage | 4-6 |
| **Total** | | **18-26 hours** |

---

## NEXT ACTIONS

1. ✅ Discovery complete
2. ⏳ Implement P1 fixes (promotion bug + column)
3. ⏳ Implement coupon reservation
4. ⏳ Implement pending order reuse
5. ⏳ Implement paid cancellation restoration
6. ⏳ Add comprehensive tests
7. ⏳ Run full test suite
8. ⏳ Final verification audit

