# Order Status Matrix — Zero-Trust Production Audit

**Date**: 2026-07-27  
**Scope**: Every possible order journey by payment method, status matrices, customer vs admin visibility  
**Trust Level**: ZERO — every claim verified against source code

---

## Table of Contents

1. [Status Systems](#1-status-systems)
2. [Payment Method Journeys](#2-payment-method-journeys)
3. [Full Status Matrix](#3-full-status-matrix)
4. [Inventory Behavior Per Status](#4-inventory-behavior-per-status)
5. [Coupon/Promotion Behavior Per Status](#5-couponpromotion-behavior-per-status)
6. [Invoice Behavior Per Status](#6-invoice-behavior-per-status)
7. [Customer vs Admin Visibility](#7-customer-vs-admin-visibility)
8. [Verified Bugs](#8-verified-bugs)

---

## 1. Status Systems

### 1.1 Dual Status Columns

The codebase has TWO different status columns on the `orders` table:

| Column | System | Values |
|---|---|---|
| `status` | Custom Checkout | `pending`, `processing`, `completed`, `delivered`, `cancelled` |
| `order_status` | Marvel Admin/GraphQL | `order-pending`, `order-processing`, `order-completed`, `order-cancelled`, `order-refunded`, `order-failed`, `order-at-local-facility`, `order-out-for-delivery`, `order-ready-for-pickup` |

**BUG-OSM-001**: The Order model `$fillable` includes `status` but does NOT include `order_status`. The Marvel OrderRepository writes to `$request['order_status']` which is silently excluded from mass-assignment. The admin `order_status` changes may not persist.

**BUG-OSM-002**: The custom checkout only manages the `status` column. The Marvel admin only manages `order_status`. These two columns are NOT synchronized. An order can be `completed` in one system and `order-pending` in the other.

### 1.2 Payment Status (Computed Attribute)

```php
// Order::getPaymentStatusAttribute()
public function getPaymentStatusAttribute()
```

The `payment_status` is a COMPUTED attribute, not a DB column. It's derived from the transaction status. This means:
- You CANNOT query the database by payment status
- Payment status is recalculated every time the attribute is accessed
- Different order payment flows may produce different computed values

### 1.3 No Fulfillment Status Column

There is NO fulfillment status column on the orders table. Fulfillment status is inferred from:
- `order_status` (Marvel admin path uses `at-local-facility`, `out-for-delivery`, `ready-for-pickup`)
- `shipping_method` (SCHEDULED vs FAST vs PICKUP)

---

## 2. Payment Method Journeys

### 2.1 Online Payment (MyFatoorah)

```
Step  | Order Status | Payment Status | Action
──────┼──────────────┼────────────────┼──────────────────────────────────────
1     | pending      | pending        | User creates order + transaction
2     | pending      | pending        | User redirected to MyFatoorah
3     | pending      | pending        | Payment processing at gateway
4     | completed    | paid           | Callback received → finalize all
```

**Inventory**: Reserved at cart, finalized at callback  
**Coupon**: Recorded at callback (`changeOrderStatus('completed')`)  
**Promotion**: Incremented at callback (`finalizePromotionUsageAfterPayment`)  
**Invoice**: Generated after `PaymentSucceeded` event

### 2.2 Cash on Delivery (COD)

```
Step  | Order Status | Payment Status | Action
──────┼──────────────┼────────────────┼──────────────────────────────────────
1     | pending      | pending        | User creates order (no online payment)
2     | pending      | pending        | Admin processes order
3     | completed    | paid           | Admin clicks "Mark COD as Paid"
```

**Inventory**: Reserved at cart, finalized at "Mark COD as Paid"  
**Coupon**: Recorded at "Mark COD as Paid"  
**Promotion**: Incremented at "Mark COD as Paid"  
**Invoice**: Generated after `PaymentSucceeded` event (triggered by mark COD)

### 2.3 Cashier QR

```
Step  | Order Status | Payment Status | Action
──────┼──────────────┼────────────────┼──────────────────────────────────────
1     | pending      | pending        | User generates QR
2     | pending      | pending        | Cashier scans QR, confirms payment
3     | completed    | paid           | System processes payment
```

Same as COD — payment is confirmed later by an operator.

### 2.4 Wallet Payment (Marvel Admin Path)

```
Step  | Order Status (order_status) | Payment Status | Action
──────┼──────────────────────────────┼────────────────┼──────────────────────
1     | order-pending                | wallet         | Admin creates order with wallet
2     | order-completed              | payment-success| Order completed immediately
```

**BUG-OSM-003**: Wallet payments bypass the inventory finalization, coupon recording, and invoice generation in the custom checkout path. Only `OrderRepository::deductStock()` runs (which has its own bugs — see BUG-INV-005/006).

### 2.5 Failed/Cancelled Flows

**Online Payment Failure**:
```
Step  | Order Status | Payment Status | Action
──────┼──────────────┼────────────────┼──────────────────────────────────────
1     | pending      | pending        | Order created
2     | cancelled    | failed         | Payment fails → Order cancelled
```

**CancelUnpaidOrders**:
```
Step  | Order Status | Payment Status | Action
──────┼──────────────┼────────────────┼──────────────────────────────────────
1     | pending      | pending        | Timeout elapsed
2     | cancelled    | failed         | System cancels + marks transaction failed
```

**Post-Payment Cancel**:
```
Step  | Order Status | Payment Status | Action
──────┼──────────────┼────────────────┼──────────────────────────────────────
1     | completed    | paid           | Order was completed + paid
2     | cancelled    | paid           | Admin cancels after payment
```

**BUG-OSM-004**: `changeOrderStatus()` sets `transaction.status = 'failed'` when cancelling, even if the transaction was previously `paid`. Cancelling a paid order marks the transaction as failed, losing the payment audit trail.

### 2.6 Refund Flow

```
Step  | Order Status (order_status) | Payment Status | Action
──────┼──────────────────────────────┼────────────────┼──────────────────────
1     | order-completed              | payment-success| Order completed
2     | order-refunded               | payment-refunded| Admin approves refund
```

**BUG-OSM-005**: The refund flow uses the Marvel admin path (`order_status`, `order-refunded`). It does NOT touch the custom checkout `status` column. The custom checkout still sees `completed`, not `refunded`.

---

## 3. Full Status Matrix

### 3.1 By Payment Method

| # | Payment Method | Order Status | Payment Status | Invoice | Valid? |
|---|---|---|---|---|---|
| 1 | Online | pending | pending | — | ✓ |
| 2 | Online | completed | paid | ready | ✓ |
| 3 | Online | cancelled | failed | — | ✓ (before payment) |
| 4 | Online | cancelled | paid | cancelled | ✓ (after payment) |
| 5 | COD | pending | pending | — | ✓ |
| 6 | COD | completed | paid | ready | ✓ (after mark paid) |
| 7 | COD | cancelled | pending | — | ✓ (before mark paid) |
| 8 | COD | cancelled | paid | cancelled | ✗ (COD can't be paid then cancelled by admin — possible via bug) |
| 9 | Cashier | pending | pending | — | ✓ |
| 10 | Cashier | completed | paid | ready | ✓ |
| 11 | Wallet | order-completed | success | — | ✗ (no invoice generated) |
| 12 | Any | refunded | refunded | cancelled | ✓ |

### 3.2 By Fulfillment Method

| Fulfillment | Allowed Order Statuses |
|---|---|
| Delivery (SCHEDULED) | pending → processing → completed → delivered |
| Delivery (FAST) | pending → completed → delivered |
| Pickup | pending → processing → ready_for_pickup → completed |
| Pickup (admin) | order-pending → order-processing → order-ready-for-pickup → order-completed |

---

## 4. Inventory Behavior Per Status

| Order Status | Reserved Qty | Stock Qty | Sold Qty |
|---|---|---|---|
| pending | ✓ reserved | unchanged | unchanged |
| processing | ✓ reserved | unchanged | unchanged |
| completed | — released | ✓ decremented | ✓ incremented |
| delivered | — released | ✓ decremented | ✓ incremented |
| cancelled (before completion) | — released | unchanged | unchanged |
| cancelled (after completion) | — released | ✓ incremented (restored) | ✓ decremented (restored) |
| refunded | — released | ✓ incremented (restored) | ✓ decremented (restored) |

**Key**: Inventory is only decremented when order reaches `completed` (via `finalizeStock`). Inventory is restored on cancel/refund via queued listeners.

---

## 5. Coupon/Promotion Behavior Per Status

| Order Status | Coupon Usage | Promotion Usage |
|---|---|---|
| pending | NOT recorded | Incremented (BUG!) |
| completed | Recorded | Incremented |
| cancelled | NOT returned | Decremented (in `changeOrderStatus`) |
| refunded | NOT returned | NOT decremented |
| failed | NOT recorded | NOT decremented (if payment failed before promotion finalization) |

**BUG-OSM-006**: Promotion usage is incremented during checkout (`applySelectedPromotion`), not at payment confirmation. The decrement in `changeOrderStatus('cancelled')` can bring it back — but only if the promotion_id exists on the order AND the cancellation goes through the custom `changeOrderStatus` path. Admin cancellations via `OrderStatusManagerWithPaymentTrait` do NOT call `decrementUsage()`.

---

## 6. Invoice Behavior Per Status

| Order Status | Invoice Exists? | Invoice Status |
|---|---|---|
| pending | No | — |
| completed | Yes | generated → generating → ready |
| delivered | Yes | ready (unchanged) |
| cancelled (before payment) | No | — |
| cancelled (after payment) | Yes | generated (should be cancelled) |
| refunded | Yes | generated (should be cancelled) |
| failed | No | — |

---

## 7. Customer vs Admin Visibility

| Order Status | Customer Sees | Admin Sees |
|---|---|---|
| pending | "Pending" | "Pending" |
| processing | "Processing" | "Processing" |
| completed | "Completed" | "Completed" |
| delivered | "Delivered" | "Delivered" |
| cancelled | "Cancelled" | "Cancelled" |
| refunded | "Refunded" | "Refunded" |
| at_local_facility | "At Local Facility" | "At Local Facility" |
| out_for_delivery | "Out for Delivery" | "Out for Delivery" |
| ready_for_pickup | "Ready for Pickup" | "Ready for Pickup" |

**Note**: The custom checkout only uses: pending, processing, completed, delivered, cancelled. The additional Marvel statuses (at_local_facility, out_for_delivery, ready_for_pickup) are only visible/settable via the admin GraphQL path.

---

## 8. Verified Bugs

| ID | Bug | Severity | Source |
|---|---|---|---|
| **BUG-OSM-001** | `order_status` NOT in Order `$fillable` — admin status changes silently fail | CRITICAL | `Order.php fillable` vs `OrderRepository.php` |
| **BUG-OSM-002** | Dual status columns (`status` vs `order_status`) not synchronized | CRITICAL | Architecture |
| **BUG-OSM-003** | Wallet payments bypass invoice, inventory finalization, coupon recording | HIGH | `OrderRepository.php` |
| **BUG-OSM-004** | `changeOrderStatus('cancelled')` sets transaction to `failed` even if previously `paid` | HIGH | `OrderService.php:548-553` |
| **BUG-OSM-005** | Refund flow uses `order_status` not `status` — custom checkout sees stale `completed` | HIGH | `RefundRepository.php` |
| **BUG-OSM-006** | Promotion decrement only in custom `changeOrderStatus`, not in admin cancel path | MEDIUM | `OrderStatusManagerWithPaymentTrait.php` |

### Severity Summary

- **CRITICAL**: 2 (BUG-OSM-001, BUG-OSM-002)
- **HIGH**: 3 (BUG-OSM-003, BUG-OSM-004, BUG-OSM-005)
- **MEDIUM**: 1 (BUG-OSM-006)
