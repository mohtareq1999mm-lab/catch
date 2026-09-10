# ARCHITECTURE ANALYSIS: NEW BUSINESS RULES IMPLEMENTATION

**Analysis Date**: 2026-08-31  
**Repository**: D:\work\meem  
**Laravel Version**: 10.30.1  
**Analysis Type**: READ-ONLY Architecture Planning

---

## EXECUTIVE SUMMARY

This document provides a comprehensive analysis of the current codebase architecture against 26 new business rules governing the cart → checkout → order → payment → inventory → coupon lifecycle. The analysis identifies gaps, proposes solutions, and outlines a detailed implementation plan.

**Key Findings**:
- Current system has partial Order-owned inventory (introduced Aug 26, 2026)
- Cart still maintains legacy 3-day reservation (conflicts with Rule 1)
- Coupon consumption happens at order creation, not payment success (conflicts with Rule 9)
- No support for payment retry on same order (conflicts with Rule 5)
- Order snapshot is immutable (compliant with Rule 19)
- 24-hour order expiration reaper exists (supports Rules 13-14)

---

## TABLE OF CONTENTS

1. [Current Flow Analysis](#1-current-flow-analysis)
2. [Current Problems vs Required Behavior](#2-current-problems-vs-required-behavior)
3. [Target Flow Design](#3-target-flow-design)
4. [State Machines](#4-state-machines)
5. [Database Changes Required](#5-database-changes-required)
6. [File-by-File Implementation Plan](#6-file-by-file-implementation-plan)
7. [Service Responsibility Matrix](#7-service-responsibility-matrix)
8. [Race Condition Prevention](#8-race-condition-prevention)
9. [Comprehensive Test Plan](#9-comprehensive-test-plan)
10. [Implementation Phases](#10-implementation-phases)

---

## 1. CURRENT FLOW ANALYSIS

### 1.1 Current Cart Flow

```
User adds item to cart
  ↓
CartInventoryService::addToCart()
  ↓
Cart row: status='active', expires_at = now + 3 days
CartItem row: product_id, quantity, price
  ↓
[PROBLEM] CartInventoryService::reserveStock()
  ↓
Product.reserved_quantity += quantity (WRONG per Rule 1)
  ↓
Cart persists with reservation
```

**Source**: 
- `app/Services/General/CartInventoryService.php:47-120`
- `packages/marvel/src/Database/Models/Cart.php`

**Problem**: Cart reserves inventory, violating Rule 1.

---

### 1.2 Current Checkout Flow

```
POST /api/v1/checkout
  ↓
OrderController::checkout() (line 87)
  ↓
OrderService::addItemsInOrder() (line 169)
  ↓
DB::transaction {
  Lock cart (lockForUpdate)
  ↓
  Validate coupon if present
    - Lock coupon
    - CouponOrchestrator::validate()
    - If invalid: cart.coupon = null
  ↓
  Calculate totals (OrderService::calculateCheckoutTotals)
    - Subtotal from cart items
    - Apply coupon discount
    - Apply promotion discount
    - Add shipping
  ↓
  OrderCreationService::createOrder()
    - Creates Order row with status='pending'
    - Snapshots: prices, coupon, promotion, currency
  ↓
  OrderCreationService::createOrderItems()
    - Creates OrderProduct rows from CartItems
  ↓
  OrderReservationService::reserveForOrder()
    - Locks order + stock rows
    - Increments Product.reserved_quantity
    - Sets order.inventory_state='active'
    - Sets order.reservation_expires_at = now + 24h
  ↓
  CartInventoryService::clearCheckedOutSlice()
    - Deletes CartItems for SCHEDULED shipping
    - Cart row survives (reusable container)
    - [PROBLEM] Cart reservation NOT released here
}
  ↓
OrderCreationService::finalizeOrder()
  - Emits OrderCreated event (after commit)
  ↓
Return order to controller
```

**Sources**:
- `app/Http/Controllers/Api/General/OrderController.php:87-141`
- `app/Services/General/OrderService.php:169-277`
- `app/Services/Checkout/OrderCreationService.php:31-100`
- `app/Services/Inventory/OrderReservationService.php:37-76`

**Problems**:
- Cart reservation persists after checkout (violates Rule 2)
- No support for pending order reuse (violates Rule 5)

---

### 1.3 Current Payment Flows

#### 1.3.1 Online Payment (MyFatoorah)

```
PaymentCheckoutHandler::handleOnlinePayment()
  ↓
Create Transaction row:
  - order_id, user_id
  - payment_method='myfatoorah'
  - status='pending'
  - gateway_transaction_id (from gateway)
  ↓
Return {url: gateway_payment_url}
  ↓
User pays at gateway
  ↓
Gateway callback → OrderController::checkoutCallback() (line 169)
  ↓
Gateway verification
  ↓
DB::transaction {
  Lock transaction
  Lock order
  ↓
  If order.status != 'pending': return (idempotent)
  ↓
  transaction.status = 'paid', paid_at = now
  order.payment_status = 'payment-success'
  order.paid_at = now
  ↓
  OrderReservationService::commit(order)
    - Deducts stock_quantity
    - Decrements reserved_quantity
    - Increments sold_quantity
    - order.inventory_state = 'committed'
  ↓
  OrderService::finalizePromotionUsageAfterPayment()
    - Increments promotion.used
    - order.promotion_consumed = true
  ↓
  OrderService::changeOrderStatus(null, 'completed', order.id, emitPaymentSuccess=false)
    - order.status = 'completed'
    - order.completed_at = now
    - InvoiceService::generateFromOrder() (idempotent)
    - Emits OrderStatusChanged
}
  ↓
event(PaymentSucceeded) // After commit
```

**Sources**:
- `app/Services/Payment/PaymentCheckoutHandler.php:23-81`
- `app/Http/Controllers/Api/General/OrderController.php:169-399`
- `app/Services/Inventory/OrderReservationService.php:78-108`

**Problems**:
- Coupon NOT consumed here (violates Rule 9)
- No payment retry support (violates Rule 5)

#### 1.3.2 COD Payment

```
PaymentCheckoutHandler::handleCodPayment()
  ↓
Create Transaction row:
  - payment_method='cod'
  - status='pending'
  ↓
Return {order_id}
  ↓
[Later] POST /api/v1/orders/{id}/mark-cod-paid
  ↓
OrderService::markCodAsPaid()
  ↓
DB::transaction {
  Lock COD transaction
  ↓
  transaction.status = 'paid', paid_at = now
  ↓
  OrderService::finalizePromotionUsageAfterPayment()
  OrderReservationService::commit(order)
  OrderService::changeOrderStatus(null, 'completed', order.id)
}
```

**Sources**:
- `app/Services/Payment/PaymentCheckoutHandler.php:83-101`
- `app/Services/General/OrderService.php:651-680`

**Problems**: Same as online payment.

#### 1.3.3 Pay at Cashier

```
PaymentCheckoutHandler::handleCashierQrPayment()
  ↓
Create Transaction row:
  - payment_method='pay_at_cashier'
  - status='pending'
  ↓
Return {order_id}
  ↓
[Later] POST /api/v1/orders/{id}/mark-cashier-paid
  ↓
OrderService::markCashierPaid()
  ↓
DB::transaction {
  Lock cashier transaction
  ↓
  transaction.status = 'paid', paid_at = now
  ↓
  OrderService::finalizePromotionUsageAfterPayment()
  OrderReservationService::commit(order)
  OrderService::changeOrderStatus(null, 'completed', order.id)
}
```

**Sources**:
- `app/Services/Payment/PaymentCheckoutHandler.php:103-122`
- `app/Services/General/OrderService.php:682-710`

**Problems**: Same as online payment.

---

### 1.4 Current Order Expiration

```
Scheduled Command: orders:cancel-unpaid (runs every minute)
  ↓
CancelUnpaidOrders::handle()
  ↓
Query:
  - status='pending'
  - inventory_state='active'
  - reservation_expires_at <= now
  - payment_status='payment-pending' OR NULL
  ↓
For each expired order:
  DB::transaction {
    Lock order
    Re-check conditions (race protection)
    ↓
    [Defensive] gatewayReportsPaid()
      - Verifies payment not successful at gateway
      - Prevents cancel if gateway shows paid but callback not received
    ↓
    OrderReservationService::release(order)
      - Decrements reserved_quantity
      - order.inventory_state = 'released'
    ↓
    order.status = 'cancelled'
    order.payment_status = 'payment-failed'
    order.fulfillment_status = 'cancelled'
    order.cancelled_at = now
    ↓
    order.transactions (status='pending') → 'failed'
    ↓
    event(OrderStatusChanged)
    event(OrderCancelled)
    event(PaymentFailed)
  }
```

**Source**: `app/Console/Commands/CancelUnpaidOrders.php:40-127`

**Strengths**:
- Handles Order-owned inventory correctly
- Race-safe with lock + re-check
- Defensive gateway verification
- Does NOT touch carts

**Problems**:
- Promotion usage NOT decremented (correct for unpaid orders)
- Coupon usage NOT tracked yet (Rule 9 not implemented)

---

### 1.5 Current Coupon Lifecycle

```
Apply coupon to cart:
  cart.coupon = 'CODE'
  ↓
Checkout:
  Lock coupon
  CouponOrchestrator::validate(coupon, user, cart.items)
    ↓
    CouponAssignmentValidator::validate()
      - If coupon has assignments:
        - Check user has assignment
        - Check assignment.used < assignment.max_uses
        - Check assignment not expired
      - If no assignments: anyone can use (public coupon)
    ↓
    CouponValidator::validate()
      - Check coupon.status = true
      - Check start_date <= today <= end_date
      - Check coupon.used < coupon.limiter (if limiter set)
      - Check minimum order amount
      - Check product restrictions
  ↓
  If valid:
    Order snapshot includes coupon code
    order.coupon = 'CODE'
    order.coupon_discount = X
    order.coupon_discount_type = 'percentage'
  ↓
  If invalid:
    cart.coupon = null
  ↓
[PROBLEM] No coupon consumption tracking
[PROBLEM] No increment of coupon.used or assignment.used
```

**Sources**:
- `app/Services/Coupon/CouponOrchestrator.php:11-71`
- `app/Services/Coupon/CouponValidator.php`
- `app/Services/Coupon/CouponAssignmentValidator.php`

**Database Tables**:
- `coupons`: Global coupon definition
  - Columns: code, discount_type, discount, max_discount_amount, limiter, used, status, start_date, end_date
- `coupon_assignments`: Per-user grants
  - Columns: coupon_id, user_id, max_uses, used, assigned_at, expires_at
  - Unique: (coupon_id, user_id)
- `coupon_usages`: Usage history (NOT currently written)
  - Columns: coupon_id, user_id, order_id, used_at
- `coupon_assignment_usages`: Assignment usage history (NOT currently written)
  - Columns: coupon_assignment_id, order_id, used_at

**Problems**:
- Coupon snapshot happens at checkout, not payment (violates Rule 9)
- No increment of `coupon.used`
- No increment of `coupon_assignment.used`
- No creation of `coupon_usages` rows
- No creation of `coupon_assignment_usages` rows
- No reservation mechanism (violates Rule 10)

---

### 1.6 Current Promotion Lifecycle

```
User selects promotion during checkout
  ↓
Promotion validation in calculateCheckoutTotals()
  - Check promotion is active
  - Check user hasn't exceeded usage limit
  - Check cart qualifies (minimum amount, etc.)
  ↓
If valid:
  Calculate promotion discount
  Snapshot in order:
    - order.promotion_id
    - order.promotion_code
    - order.promotion_type
    - order.promotion_discount
    - order.promotion_consumed = false
  ↓
Payment success:
  OrderService::finalizePromotionUsageAfterPayment()
    - If order.promotion_consumed = false:
      - Increment promotion.used
      - order.promotion_consumed = true
```

**Source**: `app/Services/General/OrderService.php:279-293`

**Strengths**:
- Promotion consumption happens AFTER payment (correct)
- Idempotent via `promotion_consumed` flag

**Problems**:
- No per-user usage tracking table
- Cancellation doesn't decrement usage

---

## 2. CURRENT PROBLEMS VS REQUIRED BEHAVIOR

| # | Rule | Current Behavior | Problem | Impact |
|---|------|------------------|---------|--------|
| 1 | Cart must NEVER reserve inventory | Cart reserves via `CartInventoryService::reserveStock()` | Cart increments `reserved_quantity` | Double reservation: cart + order |
| 2 | Order creation transfers cart items to order, cart items deleted | Cart items deleted, but cart reservation persists | `reserved_quantity` not released | Stock leakage |
| 3 | Order OWNS inventory reservation (24h TTL) | ✓ Implemented (Aug 26) | None | Compliant |
| 4 | No duplicate orders for same cart | No pending order reuse | New order created every checkout | Multiple pending orders possible |
| 5 | Payment retry reuses same order | No retry support | Must create new cart + new order | Poor UX, stock churn |
| 6 | Online payment: no reservation until payment gateway URL generated | ✓ Reservation happens after order creation | None | Compliant |
| 7 | COD/Cashier: reservation at checkout | ✓ Reservation happens after order creation | None | Compliant |
| 8 | Payment success commits reservation | ✓ Implemented | None | Compliant |
| 9 | Coupon reserved at payment initiation, consumed on payment success | Coupon snapshot at checkout, no consumption tracking | No `coupon.used++`, no `coupon_usages` row | Unlimited coupon reuse |
| 10 | Coupon single-use reservation (no double-booking) | No reservation mechanism | Two users can reserve same last-use coupon | Race condition |
| 11 | Coupon consumption increments global + assignment counters | Not implemented | No tracking | Unlimited usage |
| 12 | Assigned coupon: check assignment quota | ✓ Validated at checkout | None | Compliant (validation only) |
| 13 | Order expiration releases reservation | ✓ Implemented | None | Compliant |
| 14 | Order expiration does NOT consume coupon | ✓ No consumption on expiration | None | Compliant |
| 15 | Order expiration does NOT consume promotion | ✓ Promotion consumed only after payment | None | Compliant |
| 16 | Payment failure does NOT consume coupon/promotion | ✓ No consumption | None | Compliant |
| 17 | Cancelled paid order: release inventory, DO NOT decrement coupon/promotion | Cancel logic doesn't decrement | Admin cancellation | Needs verification |
| 18 | Digital products NEVER reserve inventory | ✓ Excluded in `OrderReservationService` | None | Compliant |
| 19 | Order snapshot immutable | ✓ Order never recalculated | None | Compliant |
| 20 | Flash sale snapshot at order creation | ✓ Snapshotted in `createOrderItems()` | None | Compliant |
| 21 | Promotion snapshot at order creation | ✓ Snapshotted in `createOrder()` | None | Compliant |
| 22 | Concurrency: row-level locks | ✓ Extensive use of `lockForUpdate()` | None | Compliant |
| 23 | Idempotency: payment callback | ✓ Check `order.status != 'pending'` | None | Compliant |
| 24 | Transaction boundaries: inventory + payment atomic | ✓ `DB::transaction` wraps critical sections | None | Compliant |
| 25 | Inventory state machine: none → active → committed/released | ✓ Implemented | None | Compliant |
| 26 | Order status machine: pending → completed/cancelled/expired | ✓ Enforced in `changeOrderStatus()` | None | Compliant |

---

## 3. TARGET FLOW DESIGN

### 3.1 New Cart Flow (No Reservation)

```
User adds item to cart
  ↓
CartService::addToCart() [RENAMED from CartInventoryService]
  ↓
Cart row: status='active', expires_at = NULL (no expiration)
CartItem row: product_id, quantity, price
  ↓
[NO RESERVATION] Stock check only:
  if (product.stock_quantity < quantity) reject
  ↓
Cart persists WITHOUT reservation
```

**Changes**:
- Remove `CartInventoryService::reserveStock()`
- Remove `CartInventoryService::releaseStock()`
- Remove `Cart.expires_at` usage (keep column for backward compat)
- Remove `Cart.reserved_at` usage
- Stock availability checked READ-ONLY at add-to-cart

---

### 3.2 New Checkout Flow (Pending Order Reuse)

```
POST /api/v1/checkout
  ↓
OrderController::checkout()
  ↓
OrderService::checkout() [NEW METHOD]
  ↓
DB::transaction {
  Lock cart
  ↓
  Find pending order for user:
    OrderCreationService::findPendingOrderForUser(user_id)
      → Returns order if exists with status='pending'
  ↓
  IF pending order exists:
    [PAYMENT RETRY PATH]
    ↓
    Validate cart items haven't changed significantly:
      - Same products?
      - Same quantities?
      - Pricing within acceptable variance?
    ↓
    IF changed:
      Release old order inventory
      Update order snapshot (new prices, new items)
      Reserve new inventory
    ELSE:
      Reuse existing order as-is
    ↓
    Return existing order
  ELSE:
    [NEW ORDER PATH]
    ↓
    Calculate totals (NO coupon consumption yet)
    ↓
    Create Order (status='pending', payment_status='payment-pending')
    ↓
    Create OrderItems
    ↓
    Reserve inventory for order (24h TTL)
    ↓
    Delete cart items
    ↓
    Return new order
}
  ↓
Proceed to payment
```

**Changes**:
- Add `OrderCreationService::findPendingOrderForUser()`
- Add `OrderCreationService::updateOrder()` for retry scenario
- Add `OrderService::checkout()` orchestrator
- Move coupon reservation to payment initiation

---

### 3.3 New Payment Initiation (Coupon Reservation)

```
Payment method selected
  ↓
IF online:
  PaymentCheckoutHandler::handleOnlinePayment()
    ↓
    [NEW] CouponReservationService::reserve(order)
      ↓
      DB::transaction {
        Lock coupon
        Lock user's coupon_assignment if exists
        ↓
        Validate availability:
          - coupon.used < coupon.limiter
          - assignment.used < assignment.max_uses
        ↓
        Create temporary reservation:
          coupon_reservations table
            - order_id (UNIQUE)
            - coupon_code
            - user_id
            - reserved_at
            - expires_at = now + 30min
        ↓
        Return success
      }
    ↓
    Create gateway invoice
    ↓
    Create Transaction (status='pending')
    ↓
    Return payment URL

IF COD/Cashier:
  [NO coupon reservation]
  Create Transaction (status='pending')
  Return order_id
```

**Changes**:
- Add `coupon_reservations` table
- Add `CouponReservationService` class
- Add `CouponReservationService::reserve()` method
- Add scheduled command to expire coupon reservations

---

### 3.4 New Payment Success (Coupon Consumption)

```
Payment callback / Mark as paid
  ↓
DB::transaction {
  Lock transaction
  Lock order
  ↓
  Idempotency check: if order.status != 'pending': return
  ↓
  transaction.status = 'paid', paid_at = now
  order.payment_status = 'payment-success', paid_at = now
  ↓
  OrderReservationService::commit(order)
    → Inventory committed
  ↓
  [NEW] CouponConsumptionService::consume(order)
    ↓
    Lock coupon
    Lock assignment if exists
    ↓
    Increment counters:
      coupon.used++
      assignment.used++ (if assigned)
    ↓
    Create usage records:
      coupon_usages(coupon_id, user_id, order_id, used_at)
      coupon_assignment_usages(assignment_id, order_id, used_at)
    ↓
    Mark consumed:
      order.coupon_consumed = true
    ↓
    Delete reservation:
      DELETE FROM coupon_reservations WHERE order_id = ?
  ↓
  PromotionConsumptionService::consume(order)
    → Promotion consumed (existing logic)
  ↓
  OrderService::changeOrderStatus(null, 'completed', order.id)
    → Status transitions, invoice generated
}
  ↓
event(PaymentSucceeded)
```

**Changes**:
- Add `CouponConsumptionService` class
- Add `CouponConsumptionService::consume()` method
- Insert into `coupon_usages` and `coupon_assignment_usages`
- Delete from `coupon_reservations`

---

### 3.5 New Payment Failure (Coupon Release)

```
Payment fails / User abandons
  ↓
IF online payment explicit failure:
  Gateway callback with failure status
    ↓
    [NEW] CouponReservationService::release(order)
      ↓
      DELETE FROM coupon_reservations WHERE order_id = ?
    ↓
    event(PaymentFailed)
  ↓
  Order remains pending, reservation intact
  User can retry payment

IF 24-hour expiration:
  CancelUnpaidOrders command
    ↓
    [NEW] CouponReservationService::release(order)
    ↓
    OrderReservationService::release(order)
    ↓
    order.status = 'cancelled'
    ↓
    [NO promotion decrement]
    [NO coupon decrement]
```

**Changes**:
- Add `CouponReservationService::release()` method
- Call on payment failure
- Call on order cancellation

---

### 3.6 New Admin Cancellation

```
Admin cancels paid order
  ↓
DB::transaction {
  Lock order
  ↓
  Check: order.status = 'completed' AND order.payment_status = 'payment-success'
  ↓
  [NEW] InventoryRestoreService::restoreCancelled(order)
    ↓
    IF order.inventory_state = 'committed':
      Reverse commit:
        stock_quantity += quantity
        sold_quantity -= quantity
      ↓
      order.inventory_state = 'restored'
  ↓
  order.status = 'cancelled'
  order.fulfillment_status = 'cancelled'
  order.cancelled_at = now
  ↓
  [DO NOT decrement coupon.used]
  [DO NOT decrement promotion.used]
  [DO NOT decrement assignment.used]
  ↓
  event(OrderCancelled)
}
```

**Changes**:
- Add `InventoryRestoreService::restoreCancelled()` method
- Reverse physical inventory only
- Leave financial counters (coupon/promotion) unchanged

---

## 4. STATE MACHINES

### 4.1 Order Status State Machine

```
STATES:
  - pending (initial)
  - processing (optional intermediate)
  - completed (terminal success)
  - cancelled (terminal failure)
  - delivered (terminal success, post-fulfillment)

TRANSITIONS:
  pending → processing (admin action)
  pending → completed (payment success)
  pending → cancelled (expiration, payment failure, user/admin cancel)
  processing → completed (admin action)
  processing → cancelled (admin action)
  completed → delivered (fulfillment complete)
  completed → cancelled (admin cancellation, refund)

INVARIANTS:
  - Only 'pending' orders can receive payment
  - 'completed' → 'cancelled' requires special handling (inventory restore)
  - 'cancelled' is terminal (no transitions out)
  - 'delivered' is terminal (no transitions out)
```

**Implementation**: `app/Services/General/OrderService.php::changeOrderStatus()`

---

### 4.2 Payment Status State Machine

```
STATES:
  - payment-pending (initial)
  - payment-success (terminal success)
  - payment-failed (terminal failure)
  - payment-refunded (terminal, post-success)

TRANSITIONS:
  payment-pending → payment-success (payment callback, mark paid)
  payment-pending → payment-failed (explicit failure, expiration, cancellation)
  payment-success → payment-refunded (refund processed)

INVARIANTS:
  - Only 'payment-pending' can transition to 'payment-success'
  - 'payment-success' enables inventory commit
  - 'payment-refunded' requires inventory restore
```

**Column**: `orders.payment_status`

---

### 4.3 Inventory State Machine

```
STATES:
  - none (initial, no reservation)
  - active (reserved, expires in 24h)
  - committed (deducted from stock)
  - released (reservation freed, order cancelled/expired)
  - restored (reversed after cancellation of completed order)

TRANSITIONS:
  none → active (order creation)
  active → committed (payment success)
  active → released (payment failure, expiration, cancellation before payment)
  committed → restored (admin cancellation of paid order)

INVARIANTS:
  - 'none' → 'active' increments reserved_quantity
  - 'active' → 'committed' deducts stock_quantity, decrements reserved_quantity
  - 'active' → 'released' decrements reserved_quantity
  - 'committed' → 'restored' increments stock_quantity, decrements sold_quantity
  - Digital products: always 'none' (never reserve/commit)
```

**Column**: `orders.inventory_state`  
**Implementation**: `app/Services/Inventory/OrderReservationService.php`

---

### 4.4 Coupon Lifecycle State Machine

```
STATES (implicit, tracked via tables):
  - available (coupon.used < limiter, assignment.used < max_uses)
  - reserved (row in coupon_reservations, 30min TTL)
  - consumed (row in coupon_usages, counters incremented)
  - expired (reservation TTL elapsed)
  - exhausted (coupon.used >= limiter)

TRANSITIONS:
  available → reserved (payment initiation)
  reserved → consumed (payment success)
  reserved → expired (30min TTL, explicit release)
  reserved → available (explicit release = delete reservation)
  consumed → [terminal]

INVARIANTS:
  - Only one reservation per order (UNIQUE order_id in coupon_reservations)
  - Reservation prevents double-booking (atomic check + insert)
  - Consumption increments global + assignment counters
  - Expired reservations auto-deleted by scheduled command
```

**Tables**: `coupon_reservations` (NEW), `coupon_usages`, `coupon_assignment_usages`

---

### 4.5 Promotion Lifecycle State Machine

```
STATES (implicit, tracked via order.promotion_consumed):
  - selected (order.promotion_id set, promotion_consumed=false)
  - consumed (promotion_consumed=true, promotion.used incremented)

TRANSITIONS:
  selected → consumed (payment success)

INVARIANTS:
  - Promotion snapshot immutable after order creation
  - Consumption happens AFTER payment, BEFORE status change to 'completed'
  - Idempotent via promotion_consumed flag
  - No decrement on cancellation (historical record)
```

**Column**: `orders.promotion_consumed`  
**Implementation**: `app/Services/General/OrderService.php::finalizePromotionUsageAfterPayment()`

---

## 5. DATABASE CHANGES REQUIRED

### 5.1 New Table: `coupon_reservations`

```sql
CREATE TABLE coupon_reservations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id BIGINT UNSIGNED NOT NULL UNIQUE,
    coupon_code VARCHAR(255) NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    reserved_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    
    INDEX idx_expires_at (expires_at),
    INDEX idx_coupon_code (coupon_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Purpose**: Temporary coupon hold during payment window (30min TTL).

**Unique Constraint**: `order_id` ensures one reservation per order (idempotent).

**Expiration Index**: Supports efficient cleanup by scheduled command.

---

### 5.2 Modify Table: `orders`

**Add Column**:
```sql
ALTER TABLE orders 
ADD COLUMN inventory_state_restored_at TIMESTAMP NULL AFTER inventory_state;
```

**Purpose**: Track when cancelled paid order had inventory restored.

---

### 5.3 Modify Table: `carts`

**NO SCHEMA CHANGES** (backward compat).

**Behavioral Changes**:
- `expires_at` no longer set (remains NULL)
- `reserved_at` no longer set (remains NULL)
- `status` still 'active'/'expired', but expiration disabled

---

### 5.4 Existing Tables (No Changes)

These tables already exist and support the new flow:

- `coupon_usages`: Ready to track consumption
  - Columns: coupon_id, user_id, order_id, used_at
  
- `coupon_assignment_usages`: Ready to track assignment consumption
  - Columns: coupon_assignment_id, order_id, used_at

- `orders`: Already has required columns
  - `coupon_consumed` (boolean, default false)
  - `promotion_consumed` (boolean, default false)
  - `inventory_state` (enum: none, active, committed, released)
  - `inventory_reserved_at`, `reservation_expires_at` (timestamps)

---

## 6. FILE-BY-FILE IMPLEMENTATION PLAN

### Phase 1: Cart De-Reservation (Rule 1-2)

#### 6.1 `app/Services/General/CartInventoryService.php`

**Changes**:
- **Method: `addToCart()`** (line ~47)
  - Remove call to `reserveStock()`
  - Add stock availability check (read-only):
    ```php
    if ($product->stock_quantity < $quantity) {
        throw new InsufficientStockException();
    }
    ```
  - Remove `cart.expires_at` assignment
  - Remove `cart.reserved_at` assignment

- **Method: `updateCartItem()`** (line ~120)
  - Remove `releaseStock()` call
  - Remove `reserveStock()` call
  - Add stock availability check

- **Method: `removeFromCart()`** (line ~150)
  - Remove `releaseStock()` call

- **Method: `clearCart()`** (line ~180)
  - Remove loop calling `releaseStock()`

- **Method: `reserveStock()`** (line ~200)
  - **DELETE METHOD** (no longer used)

- **Method: `releaseStock()`** (line ~220)
  - **DELETE METHOD** (no longer used)

- **Method: `expireCarts()`** (line ~235)
  - **DISABLE METHOD** (add early return)
  - Or remove from schedule

**Testing**:
- Add to cart → verify `products.reserved_quantity` unchanged
- Update quantity → verify no stock mutations
- Remove item → verify no stock mutations

---

#### 6.2 `app/Console/Commands/ExpireCarts.php`

**Changes**:
- **Method: `handle()`**
  - Add early return: `return self::SUCCESS;`
  - OR: Remove from `app/Console/Kernel.php` schedule

**Rationale**: Cart expiration no longer needed (carts don't reserve).

---

### Phase 2: Pending Order Reuse (Rule 4-5)

#### 6.3 `app/Services/Checkout/OrderCreationService.php`

**Changes**:
- **Method: `findPendingOrderForUser()`** (line 22)
  - Already exists ✓
  - Verify logic correct

- **NEW Method: `shouldReuseOrder()`**
  ```php
  public function shouldReuseOrder(Order $pendingOrder, Cart $cart): bool
  {
      // Compare cart items vs order items
      // Return true if same products/quantities, false if changed
  }
  ```

- **Modify Method: `updateOrder()`** (line 102)
  - Already exists ✓
  - Verify recalculates totals
  - Verify updates OrderItems

**Testing**:
- Checkout once → get order A
- Abandon payment
- Checkout again → get same order A (reused)
- Change cart
- Checkout again → get new order B

---

#### 6.4 `app/Services/General/OrderService.php`

**Changes**:
- **NEW Method: `checkout()`**
  ```php
  public function checkout(Request $request): Order
  {
      return DB::transaction(function () use ($request) {
          $cart = $this->getCartUser(); // Lock
          $pendingOrder = $this->orderCreationService->findPendingOrderForUser($request->user()->id);
          
          if ($pendingOrder && $this->orderCreationService->shouldReuseOrder($pendingOrder, $cart)) {
              // Reuse existing order
              return $pendingOrder;
          }
          
          if ($pendingOrder) {
              // Cart changed: update order
              $this->orderReservationService->release($pendingOrder);
              $checkoutTotals = $this->calculateCheckoutTotals(...);
              $order = $this->orderCreationService->updateOrder($pendingOrder, $orderData, $cart, $checkoutTotals, ...);
              $this->orderReservationService->reserveForOrder($order);
              return $order;
          }
          
          // No pending order: create new (existing addItemsInOrder logic)
          return $this->addItemsInOrder($request);
      });
  }
  ```

- **Modify Method: `addItemsInOrder()`** (line 169)
  - Extract to `checkout()` method above
  - Keep as internal helper for new order path

**Testing**:
- Verify payment retry uses same order
- Verify cart changes trigger order update
- Verify new cart creates new order

---

#### 6.5 `app/Http/Controllers/Api/General/OrderController.php`

**Changes**:
- **Method: `checkout()`** (line 87)
  - Replace `$order = $this->orderService->addItemsInOrder($request);`
  - With: `$order = $this->orderService->checkout($request);`

**No other changes needed**.

---

### Phase 3: Coupon Reservation (Rule 9-12)

#### 6.6 `app/Services/Coupon/CouponReservationService.php` [NEW FILE]

```php
<?php

namespace App\Services\Coupon;

use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Order;
use App\Models\CouponReservation;
use Marvel\Database\Models\Coupon;
use Marvel\Database\Models\CouponAssignment;

class CouponReservationService
{
    /**
     * Reserve coupon for order (30min TTL).
     * Idempotent: existing reservation for this order is returned.
     */
    public function reserve(Order $order): bool
    {
        if (!$order->coupon) {
            return true; // No coupon to reserve
        }
        
        return DB::transaction(function () use ($order) {
            // Idempotent check
            $existing = CouponReservation::where('order_id', $order->id)->lockForUpdate()->first();
            if ($existing) {
                return true; // Already reserved
            }
            
            // Lock coupon + assignment
            $coupon = Coupon::where('code', $order->coupon)->lockForUpdate()->first();
            if (!$coupon) {
                throw new \RuntimeException('Coupon not found');
            }
            
            $assignment = null;
            if ($coupon->assignments()->exists()) {
                $assignment = CouponAssignment::where('coupon_id', $coupon->id)
                    ->where('user_id', $order->user_id)
                    ->lockForUpdate()
                    ->first();
            }
            
            // Validate availability (including pending reservations)
            $pendingReservations = CouponReservation::where('coupon_code', $order->coupon)
                ->where('expires_at', '>', now())
                ->count();
            
            $availableGlobal = $coupon->limiter ? ($coupon->limiter - $coupon->used - $pendingReservations) : PHP_INT_MAX;
            $availableAssignment = $assignment ? ($assignment->max_uses - $assignment->used) : PHP_INT_MAX;
            
            if ($availableGlobal <= 0 || $availableAssignment <= 0) {
                throw new \RuntimeException('Coupon not available');
            }
            
            // Create reservation
            CouponReservation::create([
                'order_id' => $order->id,
                'coupon_code' => $order->coupon,
                'user_id' => $order->user_id,
                'reserved_at' => now(),
                'expires_at' => now()->addMinutes(30),
            ]);
            
            return true;
        });
    }
    
    /**
     * Release coupon reservation (payment failure, order cancellation).
     */
    public function release(Order $order): void
    {
        CouponReservation::where('order_id', $order->id)->delete();
    }
}
```

**Testing**:
- Reserve last-use coupon → succeeds
- Concurrent reserve same coupon → second fails
- Expiration → reservation deleted

---

#### 6.7 `app/Services/Coupon/CouponConsumptionService.php` [NEW FILE]

```php
<?php

namespace App\Services\Coupon;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Coupon;
use Marvel\Database\Models\CouponAssignment;
use Marvel\Database\Models\CouponUsage;
use Marvel\Database\Models\CouponAssignmentUsage;
use App\Models\CouponReservation;

class CouponConsumptionService
{
    /**
     * Consume coupon after payment success.
     * Idempotent: order.coupon_consumed flag prevents double consumption.
     */
    public function consume(Order $order): void
    {
        if (!$order->coupon) {
            return; // No coupon to consume
        }
        
        if ($order->coupon_consumed) {
            return; // Already consumed (idempotent)
        }
        
        DB::transaction(function () use ($order) {
            // Lock coupon + assignment
            $coupon = Coupon::where('code', $order->coupon)->lockForUpdate()->first();
            if (!$coupon) {
                \Log::warning('Coupon not found during consumption', ['order_id' => $order->id, 'coupon' => $order->coupon]);
                return;
            }
            
            // Increment global usage
            $coupon->increment('used');
            
            // Create global usage record
            CouponUsage::create([
                'coupon_id' => $coupon->id,
                'user_id' => $order->user_id,
                'order_id' => $order->id,
                'used_at' => now(),
            ]);
            
            // Handle assignment if exists
            $assignment = CouponAssignment::where('coupon_id', $coupon->id)
                ->where('user_id', $order->user_id)
                ->lockForUpdate()
                ->first();
            
            if ($assignment) {
                // Increment assignment usage
                $assignment->increment('used');
                
                // Create assignment usage record
                CouponAssignmentUsage::create([
                    'coupon_assignment_id' => $assignment->id,
                    'order_id' => $order->id,
                    'used_at' => now(),
                ]);
                
                // Emit assignment consumed event
                event(new \App\Events\AssignedCouponConsumed($order, $coupon, $assignment));
            }
            
            // Mark consumed on order
            if (Schema::hasColumn('orders', 'coupon_consumed')) {
                $order->update(['coupon_consumed' => true]);
            }
            
            // Delete reservation
            CouponReservation::where('order_id', $order->id)->delete();
        });
    }
}
```

**Testing**:
- Payment success → verify `coupon.used++`, `assignment.used++`
- Verify `coupon_usages` row created
- Verify `coupon_assignment_usages` row created (if assigned)
- Verify `coupon_consumed = true`
- Verify reservation deleted

---

#### 6.8 `app/Models/CouponReservation.php` [NEW FILE]

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CouponReservation extends Model
{
    protected $table = 'coupon_reservations';
    
    protected $fillable = [
        'order_id',
        'coupon_code',
        'user_id',
        'reserved_at',
        'expires_at',
    ];
    
    protected $casts = [
        'reserved_at' => 'datetime',
        'expires_at' => 'datetime',
    ];
    
    public function order(): BelongsTo
    {
        return $this->belongsTo(\Marvel\Database\Models\Order::class);
    }
    
    public function user(): BelongsTo
    {
        return $this->belongsTo(\Marvel\Database\Models\User::class);
    }
}
```

---

#### 6.9 `app/Console/Commands/ExpireCouponReservations.php` [NEW FILE]

```php
<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\CouponReservation;

class ExpireCouponReservations extends Command
{
    protected $signature = 'coupons:expire-reservations';
    protected $description = 'Delete expired coupon reservations (30min TTL)';
    
    public function handle(): int
    {
        $deleted = CouponReservation::where('expires_at', '<=', now())->delete();
        
        $this->info("Deleted {$deleted} expired coupon reservation(s).");
        
        return self::SUCCESS;
    }
}
```

**Schedule**: Add to `app/Console/Kernel.php`:
```php
$schedule->command('coupons:expire-reservations')->everyFiveMinutes();
```

---

#### 6.10 `app/Services/Payment/PaymentCheckoutHandler.php`

**Changes**:
- **Method: `handleOnlinePayment()`** (line 23)
  - After gateway invoice created, before returning:
    ```php
    // Reserve coupon (30min TTL)
    app(\App\Services\Coupon\CouponReservationService::class)->reserve($order);
    ```

- **Method: `handleCodPayment()`** (line 83)
  - NO coupon reservation (COD pays on delivery)

- **Method: `handleCashierQrPayment()`** (line 103)
  - NO coupon reservation (pays at cashier)

**Testing**:
- Online payment initiation → coupon reserved
- COD/Cashier → no reservation

---

#### 6.11 `app/Http/Controllers/Api/General/OrderController.php`

**Changes**:
- **Method: `checkoutCallback()`** (line 329, inside transaction)
  - After `$this->orderReservationService->commit($lockedOrder);`
  - Add:
    ```php
    // Consume coupon after payment success
    app(\App\Services\Coupon\CouponConsumptionService::class)->consume($lockedOrder);
    ```

- **Method: `checkoutCallback()`** (line 204, payment failure path)
  - After `event(new PaymentFailed($order));`
  - Add:
    ```php
    // Release coupon reservation on explicit failure
    try {
        app(\App\Services\Coupon\CouponReservationService::class)->release($order);
    } catch (\Throwable $e) {
        report($e);
    }
    ```

**Testing**:
- Payment success → coupon consumed
- Payment failure → reservation released

---

#### 6.12 `app/Services/General/OrderService.php`

**Changes**:
- **Method: `markCodAsPaid()`** (line 651)
  - After `$this->orderReservationService->commit($order);`
  - Add:
    ```php
    // Consume coupon after COD payment
    app(\App\Services\Coupon\CouponConsumptionService::class)->consume($order);
    ```

- **Method: `markCashierPaid()`** (line 682)
  - After `$this->orderReservationService->commit($order);`
  - Add:
    ```php
    // Consume coupon after cashier payment
    app(\App\Services\Coupon\CouponConsumptionService::class)->consume($order);
    ```

**Testing**:
- COD marked paid → coupon consumed
- Cashier marked paid → coupon consumed

---

### Phase 4: Order Expiration Updates (Rule 14)

#### 6.13 `app/Console/Commands/CancelUnpaidOrders.php`

**Changes**:
- **Method: `handle()`** (line 60, inside transaction)
  - After `$this->orderReservationService->release($lockedOrder);`
  - Add:
    ```php
    // Release coupon reservation on expiration
    try {
        app(\App\Services\Coupon\CouponReservationService::class)->release($lockedOrder);
    } catch (\Throwable $e) {
        report($e);
    }
    ```

**Testing**:
- Order expires → inventory released, coupon reservation released
- Verify `coupon.used` NOT decremented
- Verify `promotion.used` NOT decremented

---

### Phase 5: Admin Cancellation (Rule 17)

#### 6.14 `app/Services/Inventory/InventoryRestoreService.php` [NEW FILE]

```php
<?php

namespace App\Services\Inventory;

use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Product;

class InventoryRestoreService
{
    /**
     * Restore inventory for cancelled paid order.
     * Reverses the commit operation.
     */
    public function restoreCancelled(Order $order): void
    {
        if ($order->inventory_state !== Order::INVENTORY_STATE_COMMITTED) {
            return; // Nothing to restore
        }
        
        DB::transaction(function () use ($order) {
            $lockedOrder = Order::whereKey($order->id)->lockForUpdate()->first();
            
            foreach ($lockedOrder->orderItems as $item) {
                // Skip digital products (rule D1)
                if (($item->item_type ?? 'physical') === 'digital') {
                    continue;
                }
                
                $product = Product::whereKey($item->product_id)->lockForUpdate()->first();
                if (!$product) {
                    continue;
                }
                
                $quantity = max(1, (int) $item->quantity);
                
                // Reverse commit: restore stock, decrement sold
                $product->increment('stock_quantity', $quantity);
                $product->decrement('sold_quantity', $quantity);
            }
            
            // Mark as restored
            $lockedOrder->update([
                'inventory_state' => 'restored',
                'inventory_state_restored_at' => now(),
            ]);
        });
    }
}
```

**Testing**:
- Cancel paid order → stock restored, sold decremented
- Verify `inventory_state = 'restored'`
- Digital products skipped

---

#### 6.15 `app/Services/General/OrderService.php`

**Changes**:
- **Method: `changeOrderStatus()`** (line 509)
  - When transitioning FROM 'completed' TO 'cancelled':
    ```php
    if ($previousStatus === 'completed' && $status === 'cancelled') {
        // Restore inventory for cancelled paid order
        app(\App\Services\Inventory\InventoryRestoreService::class)->restoreCancelled($order);
        
        // DO NOT decrement coupon.used
        // DO NOT decrement promotion.used
        // DO NOT decrement assignment.used
    }
    ```

**Testing**:
- Admin cancels paid order → inventory restored
- Verify coupon/promotion counters unchanged

---

## 7. SERVICE RESPONSIBILITY MATRIX

| Service | Responsibility | Key Methods | Depends On |
|---------|---------------|-------------|------------|
| **CartService** (renamed from CartInventoryService) | Cart CRUD, stock availability checks (READ-ONLY) | addToCart(), updateCartItem(), removeFromCart(), clearCart() | Product model |
| **OrderService** | Order orchestration, status transitions, payment finalization | checkout(), changeOrderStatus(), markCodAsPaid(), markCashierPaid() | OrderCreationService, OrderReservationService, CouponConsumptionService, PromotionService |
| **OrderCreationService** | Order/OrderItem creation, snapshot logic, pending order reuse | createOrder(), createOrderItems(), findPendingOrderForUser(), updateOrder(), shouldReuseOrder() | CurrencyService, PromotionService |
| **OrderReservationService** | Inventory reservation lifecycle (Order-owned) | reserveForOrder(), commit(), release() | Product model, Order model |
| **InventoryRestoreService** | Inventory restoration for cancelled paid orders | restoreCancelled() | Product model, Order model |
| **CouponReservationService** | Coupon reservation (30min TTL), release on failure | reserve(), release() | CouponReservation model, Coupon model, CouponAssignment model |
| **CouponConsumptionService** | Coupon consumption on payment success, usage tracking | consume() | Coupon model, CouponUsage model, CouponAssignment model, CouponAssignmentUsage model |
| **CouponOrchestrator** | Coupon validation at checkout | validate(), validateByCode() | CouponValidator, CouponAssignmentValidator |
| **PaymentCheckoutHandler** | Payment gateway integration, transaction creation | handleOnlinePayment(), handleCodPayment(), handleCashierQrPayment() | PaymentGatewayFactory, CouponReservationService |
| **PromotionService** | Promotion application, usage tracking | applySelectedPromotion(), incrementUsage() | Promotion model |

---

## 8. RACE CONDITION PREVENTION

### 8.1 Concurrent Checkout (Same Cart)

**Scenario**: User clicks checkout twice rapidly.

**Solution**:
```php
DB::transaction(function () {
    $cart = Cart::where('user_id', $userId)->lockForUpdate()->first();
    
    if ($cart->items->isEmpty()) {
        throw new CartEmptyException(); // Second request sees empty cart
    }
    
    // Create order, delete cart items
});
```

**Outcome**: First request succeeds, second fails with CartEmptyException.

---

### 8.2 Coupon Double-Booking (Last Use)

**Scenario**: Two users try to use last-use coupon simultaneously.

**Solution**:
```php
DB::transaction(function () {
    $coupon = Coupon::where('code', $code)->lockForUpdate()->first();
    $pendingReservations = CouponReservation::where('coupon_code', $code)
        ->where('expires_at', '>', now())
        ->count();
    
    $available = $coupon->limiter - $coupon->used - $pendingReservations;
    
    if ($available <= 0) {
        throw new CouponUnavailableException();
    }
    
    CouponReservation::create([...]); // UNIQUE constraint on order_id
});
```

**Outcome**: First request reserves, second fails validation or INSERT.

---

### 8.3 Payment Callback vs Expiration Reaper

**Scenario**: Order expires at same moment payment succeeds.

**Solution**:
```php
// Payment callback:
DB::transaction(function () {
    $lockedOrder = Order::whereKey($orderId)->lockForUpdate()->first();
    
    if ($lockedOrder->status !== 'pending') {
        return; // Already processed (by reaper or duplicate callback)
    }
    
    // Process payment
});

// Expiration reaper:
DB::transaction(function () {
    $lockedOrder = Order::whereKey($orderId)->lockForUpdate()->first();
    
    if ($lockedOrder->status !== 'pending' 
        || $lockedOrder->inventory_state !== 'active') {
        return; // Payment succeeded, skip cancellation
    }
    
    if ($lockedOrder->reservation_expires_at->isFuture()) {
        return; // Not yet expired
    }
    
    // Defensive gateway check
    if ($this->gatewayReportsPaid($lockedOrder)) {
        return; // Payment succeeded at gateway, callback pending
    }
    
    // Cancel order
});
```

**Outcome**: Lock ensures serialization, status check prevents conflict.

---

### 8.4 Concurrent Payment Callbacks (Duplicate)

**Scenario**: Gateway sends duplicate success callback.

**Solution**:
```php
DB::transaction(function () {
    $lockedOrder = Order::whereKey($orderId)->lockForUpdate()->first();
    
    if ($lockedOrder->status !== 'pending') {
        return; // Already processed (idempotent)
    }
    
    // Process once
});
```

**Outcome**: First callback processes, second returns early.

---

### 8.5 Admin Cancellation vs Auto-Fulfillment

**Scenario**: Admin cancels order while auto-fulfillment job processes it.

**Solution**:
```php
// Fulfillment job:
DB::transaction(function () {
    $lockedOrder = Order::whereKey($orderId)->lockForUpdate()->first();
    
    if ($lockedOrder->status !== 'completed') {
        return; // Cancelled or not yet paid
    }
    
    // Fulfill
});
```

**Outcome**: Lock ensures serialization, status check prevents conflict.

---

## 9. COMPREHENSIVE TEST PLAN

### 9.1 Unit Tests

#### 9.1.1 Cart Service Tests

```php
// tests/Unit/Services/CartServiceTest.php

test('add_to_cart_does_not_reserve_inventory')
test('add_to_cart_rejects_insufficient_stock')
test('update_cart_item_does_not_mutate_reserved_quantity')
test('remove_from_cart_does_not_release_inventory')
test('clear_cart_does_not_release_inventory')
```

#### 9.1.2 Coupon Reservation Tests

```php
// tests/Unit/Services/CouponReservationServiceTest.php

test('reserve_creates_reservation_with_30min_ttl')
test('reserve_is_idempotent')
test('reserve_fails_if_coupon_exhausted')
test('reserve_fails_if_assignment_exhausted')
test('reserve_prevents_double_booking_last_use')
test('release_deletes_reservation')
```

#### 9.1.3 Coupon Consumption Tests

```php
// tests/Unit/Services/CouponConsumptionServiceTest.php

test('consume_increments_global_used_counter')
test('consume_increments_assignment_used_counter')
test('consume_creates_usage_record')
test('consume_creates_assignment_usage_record')
test('consume_deletes_reservation')
test('consume_sets_coupon_consumed_flag')
test('consume_is_idempotent')
```

#### 9.1.4 Order Reuse Tests

```php
// tests/Unit/Services/OrderCreationServiceTest.php

test('find_pending_order_returns_existing')
test('find_pending_order_returns_null_if_none')
test('should_reuse_order_true_if_cart_unchanged')
test('should_reuse_order_false_if_cart_changed')
test('update_order_recalculates_totals')
test('update_order_replaces_order_items')
```

---

### 9.2 Integration Tests

#### 9.2.1 Checkout Flow Tests

```php
// tests/Feature/CheckoutFlowTest.php

test('checkout_creates_order_without_cart_reservation')
test('checkout_reuses_pending_order_if_cart_unchanged')
test('checkout_updates_pending_order_if_cart_changed')
test('checkout_creates_new_order_if_no_pending')
test('checkout_deletes_cart_items')
test('checkout_does_not_delete_cart_row')
```

#### 9.2.2 Payment Flow Tests

```php
// tests/Feature/PaymentFlowTest.php

test('online_payment_reserves_coupon')
test('online_payment_success_consumes_coupon')
test('online_payment_success_commits_inventory')
test('online_payment_success_consumes_promotion')
test('online_payment_failure_releases_coupon_reservation')
test('cod_payment_does_not_reserve_coupon')
test('cod_mark_paid_consumes_coupon')
test('cashier_payment_does_not_reserve_coupon')
test('cashier_mark_paid_consumes_coupon')
test('payment_callback_idempotent_on_duplicate')
```

#### 9.2.3 Coupon Lifecycle Tests

```php
// tests/Feature/CouponLifecycleTest.php

test('coupon_applied_to_cart')
test('coupon_validated_at_checkout')
test('coupon_reserved_at_payment_initiation')
test('coupon_consumed_on_payment_success')
test('coupon_released_on_payment_failure')
test('coupon_released_on_order_expiration')
test('coupon_not_decremented_on_cancellation_before_payment')
test('coupon_not_decremented_on_cancellation_after_payment')
test('assigned_coupon_increments_assignment_used')
test('public_coupon_increments_global_used')
```

#### 9.2.4 Order Expiration Tests

```php
// tests/Feature/OrderExpirationTest.php

test('order_expires_after_24_hours')
test('expiration_releases_inventory')
test('expiration_releases_coupon_reservation')
test('expiration_does_not_decrement_coupon_used')
test('expiration_does_not_decrement_promotion_used')
test('expiration_fails_if_gateway_reports_paid')
test('expired_order_cannot_be_paid')
```

#### 9.2.5 Admin Cancellation Tests

```php
// tests/Feature/AdminCancellationTest.php

test('cancel_paid_order_restores_inventory')
test('cancel_paid_order_does_not_decrement_coupon_used')
test('cancel_paid_order_does_not_decrement_promotion_used')
test('cancel_unpaid_order_releases_inventory')
test('cancel_unpaid_order_releases_coupon_reservation')
test('cancelled_order_cannot_be_paid')
```

---

### 9.3 Concurrency Tests

```php
// tests/Feature/ConcurrencyTest.php

test('concurrent_checkout_same_cart_one_succeeds')
test('concurrent_coupon_reservation_last_use_one_succeeds')
test('payment_callback_vs_expiration_reaper_no_conflict')
test('duplicate_payment_callbacks_idempotent')
test('admin_cancel_vs_fulfillment_no_conflict')
```

**Implementation**: Use parallel processes or database snapshots.

---

### 9.4 End-to-End Tests

```php
// tests/Feature/E2E/FullCheckoutJourneyTest.php

test('e2e_online_payment_success')
test('e2e_online_payment_failure_then_retry')
test('e2e_cod_payment')
test('e2e_cashier_payment')
test('e2e_order_expiration')
test('e2e_admin_cancellation_paid_order')
test('e2e_coupon_lifecycle_assigned')
test('e2e_coupon_lifecycle_public')
test('e2e_promotion_lifecycle')
```

---

## 10. IMPLEMENTATION PHASES

### Phase 1: Cart De-Reservation (2-3 days)

**Goal**: Remove inventory reservation from Cart.

**Files**:
- `app/Services/General/CartInventoryService.php`
- `app/Console/Commands/ExpireCarts.php`
- `app/Console/Kernel.php`

**Tests**:
- Unit: CartServiceTest
- Integration: CartFlowTest

**Verification**:
- Add to cart → `reserved_quantity` unchanged
- Remove from cart → `reserved_quantity` unchanged
- Cart expiration disabled

**Deploy**: Can deploy independently (backward compatible).

---

### Phase 2: Database Migration (1 day)

**Goal**: Add `coupon_reservations` table.

**Files**:
- `database/migrations/XXXX_create_coupon_reservations_table.php`
- `database/migrations/XXXX_add_inventory_state_restored_at_to_orders.php`

**Tests**:
- Migration runs without errors
- Rollback works

**Deploy**: Run migration in production (no downtime).

---

### Phase 3: Pending Order Reuse (3-4 days)

**Goal**: Implement payment retry logic.

**Files**:
- `app/Services/Checkout/OrderCreationService.php`
- `app/Services/General/OrderService.php`
- `app/Http/Controllers/Api/General/OrderController.php`

**Tests**:
- Unit: OrderCreationServiceTest
- Integration: CheckoutFlowTest
- E2E: FullCheckoutJourneyTest (retry scenario)

**Verification**:
- Checkout twice → same order ID
- Change cart → new order ID
- Payment retry → same order ID

**Deploy**: Can deploy independently (backward compatible).

---

### Phase 4: Coupon Reservation (5-6 days)

**Goal**: Implement coupon reservation and consumption.

**Files**:
- `app/Services/Coupon/CouponReservationService.php`
- `app/Services/Coupon/CouponConsumptionService.php`
- `app/Models/CouponReservation.php`
- `app/Console/Commands/ExpireCouponReservations.php`
- `app/Services/Payment/PaymentCheckoutHandler.php`
- `app/Http/Controllers/Api/General/OrderController.php`
- `app/Services/General/OrderService.php`
- `app/Console/Kernel.php`

**Tests**:
- Unit: CouponReservationServiceTest, CouponConsumptionServiceTest
- Integration: PaymentFlowTest, CouponLifecycleTest
- Concurrency: ConcurrencyTest (last-use coupon)
- E2E: FullCheckoutJourneyTest (coupon scenarios)

**Verification**:
- Online payment → coupon reserved
- Payment success → `coupon.used++`, `coupon_usages` row created
- Payment failure → reservation deleted
- Concurrent last-use → second fails

**Deploy**: Critical, requires careful testing. Consider feature flag.

---

### Phase 5: Order Expiration Updates (1 day)

**Goal**: Add coupon release to expiration command.

**Files**:
- `app/Console/Commands/CancelUnpaidOrders.php`

**Tests**:
- Integration: OrderExpirationTest

**Verification**:
- Expired order → inventory released, coupon reservation released
- Verify counters unchanged

**Deploy**: Can deploy with Phase 4.

---

### Phase 6: Admin Cancellation (2-3 days)

**Goal**: Implement inventory restoration for cancelled paid orders.

**Files**:
- `app/Services/Inventory/InventoryRestoreService.php`
- `app/Services/General/OrderService.php`

**Tests**:
- Unit: InventoryRestoreServiceTest
- Integration: AdminCancellationTest
- Concurrency: admin cancel vs fulfillment

**Verification**:
- Cancel paid order → stock restored, sold decremented
- Verify coupon/promotion counters unchanged

**Deploy**: Can deploy independently.

---

### Phase 7: End-to-End Testing & Monitoring (2-3 days)

**Goal**: Comprehensive testing and observability.

**Tasks**:
- Run full E2E test suite
- Load testing (concurrent checkouts)
- Add monitoring/alerts:
  - Coupon reservation expiration rate
  - Order expiration rate
  - Payment callback failures
- Add dashboards:
  - Pending orders by age
  - Active coupon reservations
  - Inventory reservation by state

**Deploy**: Monitoring only.

---

### Total Estimated Time: 16-23 days

---

## APPENDIX A: KEY BUSINESS RULES REFERENCE

1. Cart must NEVER reserve inventory
2. Order creation deletes cart items
3. Order OWNS inventory reservation (24h TTL)
4. No duplicate orders for same cart
5. Payment retry reuses same order
6. Online payment: reserve after gateway URL
7. COD/Cashier: reserve at checkout
8. Payment success commits reservation
9. Coupon reserved at payment, consumed on success
10. Coupon single-use reservation
11. Coupon consumption increments counters
12. Assigned coupon: check assignment quota
13. Order expiration releases reservation
14. Order expiration does NOT consume coupon
15. Order expiration does NOT consume promotion
16. Payment failure does NOT consume coupon/promotion
17. Cancelled paid order: restore inventory, keep counters
18. Digital products NEVER reserve inventory
19. Order snapshot immutable
20. Flash sale snapshot at order creation
21. Promotion snapshot at order creation
22. Concurrency: row-level locks
23. Idempotency: payment callbacks
24. Transaction boundaries: atomic operations
25. Inventory state machine enforced
26. Order status machine enforced

---

## APPENDIX B: CURRENT STATE COMPLIANCE MATRIX

| Rule | Compliant? | Notes |
|------|-----------|-------|
| 1 | ❌ | Cart reserves via CartInventoryService |
| 2 | ⚠️ | Items deleted, reservation persists |
| 3 | ✅ | Implemented Aug 26 |
| 4 | ❌ | No pending order reuse |
| 5 | ❌ | No retry support |
| 6 | ✅ | Reserve after order creation |
| 7 | ✅ | Reserve after order creation |
| 8 | ✅ | Commit on payment success |
| 9 | ❌ | No reservation, no consumption tracking |
| 10 | ❌ | No reservation mechanism |
| 11 | ❌ | No counter increments |
| 12 | ✅ | Validation at checkout |
| 13 | ✅ | Expiration releases inventory |
| 14 | ✅ | No consumption on expiration |
| 15 | ✅ | Promotion consumed after payment |
| 16 | ✅ | No consumption on failure |
| 17 | ⚠️ | Needs verification |
| 18 | ✅ | Digital excluded |
| 19 | ✅ | Snapshot immutable |
| 20 | ✅ | Flash sale snapshotted |
| 21 | ✅ | Promotion snapshotted |
| 22 | ✅ | Extensive locking |
| 23 | ✅ | Idempotent callbacks |
| 24 | ✅ | Transaction boundaries |
| 25 | ✅ | State machine enforced |
| 26 | ✅ | Status machine enforced |

**Compliance Score**: 15/26 fully compliant, 2/26 partial, 9/26 non-compliant

---

## END OF DOCUMENT

**Document Version**: 1.0  
**Last Updated**: 2026-08-31  
**Status**: READ-ONLY ANALYSIS COMPLETE
