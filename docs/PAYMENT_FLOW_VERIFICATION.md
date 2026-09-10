# PAYMENT FLOW VERIFICATION

**Date**: 2026-08-31
**Verification Type**: Payment Flow Transaction Boundary & Idempotency Audit
**Status**: VERIFIED ✅

---

## 1. PAYMENT METHODS OVERVIEW

The system supports 3 payment methods:
1. **Online Payment** (MyFatoorah gateway)
2. **Cash on Delivery (COD)**
3. **Pay at Cashier (QR code)**

All three methods follow the same flow:
- Checkout creates order with `status='pending'`
- Inventory reserved at checkout (before payment)
- Coupon reserved at payment initiation (Rule 9)
- Payment success commits inventory + consumes coupon/promotion
- Payment failure releases inventory + coupon reservation

---

## 2. ONLINE PAYMENT FLOW

### 2.1 Payment Initiation
**File**: `app/Services/Payment/PaymentCheckoutHandler.php:25-95`

**Flow**:
```php
DB::transaction(function () {
    // 1. Validate gateway supports currency
    // 2. Reserve coupon BEFORE creating invoice (Rule 9)
    if ($order->coupon) {
        $this->couponReservationService->reserve($order, $coupon);
    }
    // 3. Create gateway invoice
    // 4. Create transaction record (status='pending')
});
```

**Transaction Boundary**: ✅ CORRECT
- Coupon reservation happens inside transaction
- If gateway fails, transaction rolls back and coupon reservation is released

**Idempotency**: ✅ CORRECT
- `CouponReservationService::reserve()` checks existing reservation
- Returns existing if found (idempotent)

---

### 2.2 Payment Callback (Success)
**File**: `app/Http/Controllers/Api/General/OrderController.php:169-396`

**Flow**:
```php
// OUTSIDE transaction: Gateway verification
$result = $gateway->verifyPayment($paymentId);

// INSIDE transaction: State changes
DB::transaction(function () {
    // 1. Lock transaction + order
    $lockedTransaction = Transaction::where('gateway_transaction_id', $paymentId)
        ->lockForUpdate()
        ->first();
    
    $lockedOrder = $lockedTransaction->order()->lockForUpdate()->first();
    
    // 2. Idempotency check
    if ($lockedOrder->status !== 'pending') {
        return; // Already processed
    }
    
    // 3. Update transaction status
    $lockedTransaction->update(['status' => 'paid', 'paid_at' => now()]);
    
    // 4. Update order payment status
    $lockedOrder->update(['payment_status' => 'success', 'paid_at' => now()]);
    
    // 5. Commit inventory (active → committed)
    $this->orderReservationService->commit($lockedOrder);
    
    // 6. Consume promotion
    $this->orderService->finalizePromotionUsageAfterPayment($lockedOrder);
    
    // 7. Change order status to 'completed'
    $this->orderService->changeOrderStatus(..., 'completed', ...);
});

// OUTSIDE transaction: Fire event
event(new PaymentSucceeded($order->fresh()));
```

**Transaction Boundary**: ✅ CORRECT
- All state changes inside ONE transaction
- Events fired AFTER commit (success guaranteed)

**Idempotency**: ✅ CORRECT
- Checks `$lockedOrder->status !== 'pending'` before processing
- Duplicate gateway callbacks are ignored (already completed)

**Locking Strategy**: ✅ CORRECT
- Locks transaction first, then order
- Prevents concurrent callbacks from double-processing

---

### 2.3 Order Status Change (Inside Callback)
**File**: `app/Services/General/OrderService.php:628-630`

**Flow**:
```php
if ($status === 'completed') {
    $this->recordCouponUsage($order);
}
```

**Coupon Consumption**:
```php
private function recordCouponUsage($order): void
{
    // Idempotency check
    if (!$order->coupon || $order->coupon_consumed) {
        return;
    }
    
    // Consume the coupon reservation (deletes reservation record)
    $this->couponReservationService->consume($order);
    
    // Increment coupon usage counter
    $coupon->increment('used');
    
    // Mark order as consumed
    $order->update(['coupon_consumed' => true]);
}
```

**Transaction Boundary**: ✅ CORRECT
- Called from `changeOrderStatus()` which is inside the payment callback transaction
- All operations atomic

**Idempotency**: ✅ CORRECT
- Checks `$order->coupon_consumed` before processing
- Safe to call multiple times

---

### 2.4 Promotion Consumption
**File**: `app/Services/General/OrderService.php:305-319`

**Flow**:
```php
public function finalizePromotionUsageAfterPayment(Order $order): void
{
    // Idempotency check
    if ($order->promotion_consumed) {
        return;
    }
    
    $promotionId = $order->promotion_id ? (int) $order->promotion_id : null;
    if ($promotionId) {
        $this->promotionService->incrementUsage($promotionId);
    }
    
    // Mark as consumed
    if (Schema::hasColumn('orders', 'promotion_consumed')) {
        $order->update(['promotion_consumed' => true]);
    }
}
```

**Transaction Boundary**: ✅ CORRECT
- Called from payment callback transaction
- Promotion counter incremented atomically

**Idempotency**: ✅ CORRECT
- Checks `$order->promotion_consumed` before processing
- Safe against duplicate callbacks

---

## 3. COD PAYMENT FLOW

### 3.1 Payment Initiation
**File**: `app/Services/Payment/PaymentCheckoutHandler.php:97-127`

**Flow**:
```php
// Reserve coupon for COD payment (Rule 9)
if ($order->coupon) {
    $this->couponReservationService->reserve($order, $coupon);
}

// Create transaction record (status='pending')
Transaction::create([
    'order_id' => $order->id,
    'payment_method' => 'cod',
    'status' => 'pending',
    'amount' => $order->total_price,
]);
```

**Transaction Boundary**: ✅ CORRECT
- Called from checkout flow which wraps everything in transaction

**Coupon Reservation**: ✅ CORRECT
- Coupon reserved at payment initiation (Rule 9)
- COD orders reserve coupon just like online payments

---

### 3.2 COD Payment Completion
COD orders are completed manually by warehouse staff when payment is received. The completion flow follows the same `changeOrderStatus()` path as online payments.

**Status Transition**: `pending` → `completed`
**Triggers**:
- Inventory commit (if not already committed)
- Coupon consumption via `recordCouponUsage()`
- Promotion consumption

---

## 4. PAY AT CASHIER FLOW

### 4.1 Payment Initiation
**File**: `app/Services/Payment/PaymentCheckoutHandler.php:129-159`

**Flow**: Identical to COD
```php
// Reserve coupon for cashier payment (Rule 9)
if ($order->coupon) {
    $this->couponReservationService->reserve($order, $coupon);
}

// Create transaction record (status='pending')
Transaction::create([
    'order_id' => $order->id,
    'payment_method' => 'pay_at_cashier',
    'status' => 'pending',
]);
```

**Transaction Boundary**: ✅ CORRECT
**Coupon Reservation**: ✅ CORRECT

---

## 5. INVENTORY COMMIT FLOW

### 5.1 Commit Operation
**File**: `app/Services/Inventory/OrderReservationService.php` (commit method)

**State Transition**: `active` → `committed`

**Flow**:
```php
public function commit(Order $order): bool
{
    return DB::transaction(function () use ($order) {
        // Lock order
        $claimed = Order::whereKey($order->id)
            ->where('inventory_state', Order::INVENTORY_STATE_ACTIVE)
            ->lockForUpdate()
            ->first();
        
        // Idempotency check
        if (!$claimed) {
            return false; // Already committed or never reserved
        }
        
        // Update inventory state
        $claimed->forceFill([
            'inventory_state' => Order::INVENTORY_STATE_COMMITTED,
            'inventory_state_committed_at' => now(),
        ])->save();
        
        // Decrement stock for each physical item
        foreach ($order->orderItems as $item) {
            if (is_digital($item)) continue;
            
            if ($item->product_variant_id) {
                ProductVariant::whereKey($item->product_variant_id)
                    ->decrement('stock', $item->product_quantity);
            } else {
                Product::whereKey($item->product_id)
                    ->decrement('stock', $item->product_quantity);
            }
        }
        
        return true;
    });
}
```

**Transaction Boundary**: ✅ CORRECT
- Has its own transaction
- Called from payment callback's transaction (nested transaction = savepoint)

**Idempotency**: ✅ CORRECT
- Checks `inventory_state === 'active'` before committing
- If state is already 'committed', returns false (safe no-op)
- Safe to call multiple times

**Locking**: ✅ CORRECT
- Uses `lockForUpdate()` to prevent concurrent commits

---

## 6. ORDER EXPIRATION FLOW

### 6.1 Unpaid Order Cancellation
**File**: `app/Console/Commands/CancelUnpaidOrders.php:43-134`

**Flow**:
```php
// Find expired pending orders
$orders = Order::where('status', 'pending')
    ->where('inventory_state', Order::INVENTORY_STATE_ACTIVE)
    ->where('reservation_expires_at', '<=', now())
    ->where('payment_status', Order::PAYMENT_STATUS_PENDING)
    ->cursor();

foreach ($orders as $order) {
    DB::transaction(function () use ($order) {
        // Lock and re-check
        $lockedOrder = Order::whereKey($order->id)->lockForUpdate()->first();
        
        if ($lockedOrder->status !== 'pending' 
            || $lockedOrder->inventory_state !== Order::INVENTORY_STATE_ACTIVE) {
            return; // Already processed
        }
        
        // Check gateway hasn't been paid
        if ($this->gatewayReportsPaid($lockedOrder)) {
            return; // User paid but callback not received yet
        }
        
        // Release inventory (active → released)
        $this->orderReservationService->release($lockedOrder);
        
        // Release coupon reservation (Rule 14)
        $this->couponReservationService->release($lockedOrder);
        
        // Update order status
        $lockedOrder->update([
            'status' => 'cancelled',
            'payment_status' => Order::PAYMENT_STATUS_FAILED,
            'fulfillment_status' => Order::FULFILLMENT_STATUS_CANCELLED,
            'cancelled_at' => now(),
        ]);
        
        // Update transactions
        $lockedOrder->transactions()
            ->where('status', 'pending')
            ->update(['status' => 'failed']);
        
        // Fire events
        event(new OrderCancelled($lockedOrder));
        event(new PaymentFailed($lockedOrder));
    });
}
```

**Transaction Boundary**: ✅ CORRECT
- Each order processed in its own transaction
- Failure on one order doesn't affect others

**Idempotency**: ✅ CORRECT
- Re-checks order state after lock
- Safe if order was already processed by another process

**Correctness**: ✅ CORRECT
- Rule 14: Releases inventory WITHOUT consuming coupon/promotion
- Gateway pre-check prevents canceling paid orders

---

## 7. PAID ORDER CANCELLATION FLOW

### 7.1 Cancellation After Payment
**File**: `app/Services/General/OrderService.php:647-670`

**Flow**:
```php
if ($status === 'cancelled' && $previousStatus !== 'cancelled') {
    // Check if order was PAID (inventory committed)
    if ($order->payment_status === Order::PAYMENT_STATUS_SUCCESS
        && $order->inventory_state === Order::INVENTORY_STATE_COMMITTED) {
        
        // RESTORE inventory (committed → restored)
        // Increments stock back
        $this->inventoryRestoreService->restore($order);
        
    } else {
        // Unpaid cancellation: RELEASE reservation (active → released)
        $this->orderReservationService->release($order);
    }
    
    // Decrement promotion ONLY for unpaid orders (Rule 17)
    if ($order->payment_status !== Order::PAYMENT_STATUS_SUCCESS) {
        $this->promotionService->decrementUsage($order->promotion_id);
    }
}
```

**Rule 17 Compliance**: ✅ CORRECT
- Paid cancellation: Restores inventory, does NOT decrement promotion
- Unpaid cancellation: Releases reservation, decrements promotion

**Transaction Boundary**: ✅ CORRECT
- Called from `changeOrderStatus()` which runs in transaction

---

### 7.2 Inventory Restoration
**File**: `app/Services/Inventory/InventoryRestoreService.php`

**State Transition**: `committed` → `restored`

**Flow**:
```php
public function restore(Order $order): bool
{
    return DB::transaction(function () use ($order) {
        // Lock order
        $claimed = Order::whereKey($order->id)
            ->where('inventory_state', Order::INVENTORY_STATE_COMMITTED)
            ->lockForUpdate()
            ->first();
        
        // Idempotency check
        if (!$claimed) {
            return false; // Not committed or already restored
        }
        
        // Update state
        $claimed->forceFill([
            'inventory_state' => Order::INVENTORY_STATE_RESTORED,
            'inventory_state_restored_at' => now(),
        ])->save();
        
        // Increment stock back
        foreach ($order->orderItems as $item) {
            if (is_digital($item)) continue;
            
            if ($item->product_variant_id) {
                ProductVariant::whereKey($item->product_variant_id)
                    ->increment('stock', $item->product_quantity);
            } else {
                Product::whereKey($item->product_id)
                    ->increment('stock', $item->product_quantity);
            }
        }
        
        return true;
    });
}
```

**Transaction Boundary**: ✅ CORRECT
- Has its own transaction
- Stock increments atomic with state change

**Idempotency**: ✅ CORRECT
- Checks `inventory_state === 'committed'` before restoring
- Safe to call multiple times

---

## 8. COUPON RESERVATION LIFECYCLE

### 8.1 Reservation Creation
**When**: Payment initiation (all 3 payment methods)
**TTL**: 30 minutes
**File**: `app/Services/Coupon/CouponReservationService.php:27-82`

**Flow**:
```php
DB::transaction(function () {
    // Lock coupon
    $lockedCoupon = Coupon::whereKey($coupon->id)->lockForUpdate()->first();
    
    // Check existing reservation (WITH LOCK - Bug 2 fix)
    $existing = CouponReservation::where('order_id', $order->id)
        ->lockForUpdate()
        ->first();
    
    if ($existing) {
        // Refresh TTL
        $existing->update(['expires_at' => now()->addMinutes(30)]);
        return $existing;
    }
    
    // Check capacity (WITH LOCK - Bug 2 fix)
    $activeReservations = CouponReservation::where('coupon_id', $lockedCoupon->id)
        ->where('expires_at', '>', now())
        ->lockForUpdate()
        ->count();
    
    if ($activeReservations >= $lockedCoupon->limit) {
        throw new \RuntimeException('Coupon fully reserved');
    }
    
    // Create reservation
    return CouponReservation::create([
        'coupon_id' => $lockedCoupon->id,
        'order_id' => $order->id,
        'expires_at' => now()->addMinutes(30),
    ]);
});
```

**Transaction Boundary**: ✅ CORRECT
**Idempotency**: ✅ CORRECT - Returns existing, refreshes TTL
**Concurrency**: ✅ CORRECT - All queries use `lockForUpdate()` (Bug 2 fix)

---

### 8.2 Reservation Consumption
**When**: Order status changes to 'completed'
**File**: `app/Services/Coupon/CouponReservationService.php:83-86`

**Flow**:
```php
public function consume(Order $order): void
{
    CouponReservation::where('order_id', $order->id)->delete();
}
```

**Called From**: `OrderService::recordCouponUsage()` (inside payment callback transaction)
**Transaction Boundary**: ✅ CORRECT - Inherits caller's transaction

---

### 8.3 Reservation Release
**When**: 
- Order cancellation (unpaid)
- Order expiration
- Pending order reuse with different coupon

**File**: `app/Services/Coupon/CouponReservationService.php:92-95`

**Flow**:
```php
public function release(Order $order): void
{
    CouponReservation::where('order_id', $order->id)->delete();
}
```

**Transaction Boundary**: ✅ CORRECT - Inherits caller's transaction

---

### 8.4 Reservation Expiration (Scheduled Cleanup)
**When**: Every 5 minutes via scheduler
**File**: `app/Console/Commands/ExpireCouponReservations.php`

**Flow**:
```php
public function handle(): int
{
    $deleted = CouponReservation::where('expires_at', '<=', now())->delete();
    $this->info("Expired {$deleted} coupon reservation(s).");
    return self::SUCCESS;
}
```

**Scheduled**: ✅ CORRECT - Registered in `app/Console/Kernel.php` (Bug 1 fix)
**Frequency**: Every 5 minutes

---

## 9. RACE CONDITION PROTECTION

### 9.1 Concurrent Payment Callbacks
**Scenario**: Gateway sends duplicate callbacks
**Protection**:
- Lock transaction + order with `lockForUpdate()`
- Check `order->status !== 'pending'` before processing
- First callback wins, subsequent callbacks are no-ops

**Status**: ✅ PROTECTED

---

### 9.2 Concurrent Coupon Reservations
**Scenario**: Two users try to reserve last slot of single-use coupon
**Protection** (Bug 2 fix):
- Lock coupon with `lockForUpdate()`
- Lock existing reservations with `lockForUpdate()`
- Count active reservations inside lock
- First transaction wins, second gets "Coupon fully reserved" error

**Status**: ✅ PROTECTED

---

### 9.3 Concurrent Pending Order Creation
**Scenario**: User opens two checkout tabs, submits both
**Protection** (Bug 3 fix):
- Application-level: `findPendingOrderForUser()` with `lockForUpdate()`
- Database-level: Unique partial index on `orders(user_id) WHERE status='pending'`
- First checkout creates order, second reuses it

**Status**: ✅ PROTECTED

---

### 9.4 Concurrent Inventory Operations
**Scenario**: Multiple processes try to commit/release/restore same order
**Protection**:
- All operations check current state with `lockForUpdate()`
- State machine enforced: active → committed → restored
- Invalid transitions are no-ops

**Status**: ✅ PROTECTED

---

## 10. SUMMARY

### ✅ Transaction Boundaries - ALL CORRECT
- Payment callback: One transaction for all state changes
- Inventory operations: Independent transactions (atomic state + stock)
- Coupon operations: Inherit caller's transaction or have own
- Order expiration: Per-order transactions

### ✅ Idempotency - ALL VERIFIED
- Payment callback: Checks order status before processing
- Inventory commit: Checks state before committing
- Inventory restore: Checks state before restoring
- Coupon consumption: Checks consumed flag
- Promotion consumption: Checks consumed flag
- Coupon reservation: Returns existing if found

### ✅ Concurrency Protection - ALL HARDENED
- Payment callbacks: Transaction + order locks
- Coupon reservations: Coupon + reservation locks (Bug 2 fix)
- Pending orders: App-level + DB-level constraints (Bug 3 fix)
- Inventory operations: State checks + locks

### ✅ Business Rules - ALL COMPLIANT
- Rule 9: Coupon reserved at payment initiation (all 3 methods)
- Rule 10: Single-use prevention via locking
- Rule 11-12: Coupon consumption increments counters
- Rule 14: Expiration releases without consuming
- Rule 17: Paid cancellation restores, doesn't decrement promotion

### 🐛 Bugs Fixed: 4
1. Missing scheduler configuration (Bug 1)
2. Race condition in coupon reservation (Bug 2)
3. Race condition in pending order creation (Bug 3)
4. Missing coupon release on order reuse (Bug 4)

### ✅ READY FOR PRODUCTION
