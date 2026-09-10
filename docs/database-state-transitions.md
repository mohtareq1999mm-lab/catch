# Database State Transitions — Complete Architecture Documentation

> Version: 1.0 | Classification: INTERNAL | Last Updated: 2026-07-27

---

## Executive Summary

The system has seven distinct state machines across orders, transactions, invoices, refunds, cart, inventory, and products. The **order state machine** is the central orchestrator — every payment, cancellation, and refund event converges on `OrderService::changeOrderStatus()`. Transaction status (`pending → paid → failed`) mirrors payment outcome. Invoice status (`generated → pdf_generating → ready`) is a simple lifecycle. Refund status (`pending → approved/rejected`) triggers inventory restoration and financial adjustments. Cart status (`active → checked_out/expired`) gates inventory reservation. Three inventory quantity columns (`stock_quantity`, `reserved_quantity`, `sold_quantity`) track physical stock through the pipeline with atomic guards (`inventory_restored_at`) preventing double processing.

---

## 1. Order State Machine

### 1.1 States

| Status | Meaning |
|---|---|
| `pending` | Order created, awaiting payment |
| `processing` | Payment initiated, being processed |
| `completed` | Payment confirmed, order fulfilled |
| `delivered` | Customer received the order |
| `cancelled` | Order voided (terminal) |

### 1.2 Valid Transitions

```
pending    → pending, processing, completed, cancelled
processing → processing, completed, cancelled
completed  → completed, delivered
delivered  → delivered        (terminal)
cancelled  → cancelled        (terminal)
```

**Guard**: `OrderService::canTransitionOrderStatus()` checks `$allowedOrderTransitions[$from]`.

### 1.3 Transition Triggers & Side Effects

| From | To | Trigger | File | Side Effects |
|---|---|---|---|---|
| — | `pending` | `OrderCreationService::createOrder()` | `OrderCreationService.php:28` | New order created, inventory reserved |
| `pending` | `completed` | `checkoutCallback()` (online) | `OrderController.php:331` | TXN→paid, inventory finalized, coupon recorded, promotion increment, `PaymentSucceeded` event |
| `pending` | `completed` | `markCodAsPaid()` | `OrderService.php:569` | Same as callback for COD |
| `pending` | `completed` | `markCashierPaid()` | `OrderService.php:600` | Same as callback for cashier |
| `pending` | `cancelled` | `changeOrderStatus()` (admin) | `OrderService.php:497` | TXN→failed, promotion decrement, inventory restored, `OrderCancelled` event |
| `pending` | `cancelled` | `CancelUnpaidOrders` (command) | `CancelUnpaidOrders.php` | Same as above |
| `completed` | `delivered` | Admin via `changeOrderStatus()` | `OrderService.php:497` | `OrderStatusChanged` event |

---

## 2. Transaction State Machine

### 2.1 States

| Status | Meaning |
|---|---|
| `pending` | Transaction created, awaiting settlement |
| `paid` | Payment confirmed (terminal) |
| `failed` | Payment failed or cancelled (terminal) |

### 2.2 Transitions

```
pending → paid    (terminal)
pending → failed  (terminal)
```

### 2.3 Transition Triggers

| From | To | Trigger | File |
|---|---|---|---|
| — | `pending` | `PaymentCheckoutHandler` (any method) | `PaymentCheckoutHandler.php` |
| `pending` | `paid` | `changeOrderStatus(invoiceId, 'completed')` | `OrderService.php:542` |
| `pending` | `paid` | `markCodAsPaid()` | `OrderService.php:583` |
| `pending` | `paid` | `markCashierPaid()` | `OrderService.php:614` |
| `pending` | `paid` | `checkoutCallback()` direct update | `OrderController.php:314` |
| `pending` | `failed` | `changeOrderStatus(invoiceId, 'cancelled')` | `OrderService.php:548` |
| `pending` | `failed` | `checkoutErrorCallback()` | `OrderController.php:417` |

---

## 3. Payment Status (Derived)

Payment status is **computed** — not stored as a column. Defined in `Order.php:129`:

**For COD/cashier**: Reads `transactions.latest().status`
- `paid` → `'payment-success'`
- `failed` → `'payment-failed'`
- else → `'payment-pending'`
- No transaction but order `completed/delivered` → `'payment-success'`

**For online**: Derives from `orders.status`
- `completed` or `delivered` → `'payment-success'`
- `cancelled` → `'payment-failed'`
- else → `'payment-pending'`

---

## 4. Invoice State Machine

### 4.1 States

| Status | Meaning |
|---|---|
| `generated` | Invoice record created |
| `pdf_generating` | PDF generation in progress |
| `ready` | PDF generated, invoice complete (terminal) |
| `failed` | PDF generation failed (recoverable) |
| `corrected` | Correction invoice issued |
| `cancelled` | Invoice voided |

### 4.2 Transitions

```
[no invoice] → generated       (InvoiceService::generateFromOrder)
generated    → pdf_generating  (GenerateInvoicePdfJob dispatched)
pdf_generating → ready         (GenerateInvoicePdfJob::handle — success)
pdf_generating → failed        (GenerateInvoicePdfJob::handle — exception)
failed       → pdf_generating  (InvoiceController::regenerate — retry)
ready        → pdf_generating  (InvoiceController::regenerate — regenerate)
ready        → corrected       (correction invoice, not yet implemented)
ready        → cancelled       (not yet implemented)
```

### 4.3 Guard
`regenerate()` only allows `failed` or `ready` statuses.

### 4.4 Side Effects
- `ready`: sets `pdf_generated_at = now()`
- `failed`: sets `last_generation_error`, increments `generation_attempts`

---

## 5. Refund State Machine

### 5.1 States

| Status | Meaning |
|---|---|
| `pending` | Refund requested, awaiting admin action |
| `processing` | Refund being processed |
| `approved` | Refund approved, processed (terminal) |
| `rejected` | Refund denied (terminal) |

### 5.2 Transitions

```
[created] → pending      (RefundRepository::storeRefund)
pending   → approved     (RefundRepository::updateRefund)
pending   → rejected     (RefundRepository::updateRefund)
pending   → processing   (manual update)
```

### 5.3 Side Effects of `approved`

| Effect | Listener/Handler |
|---|---|
| Order status → `refunded` | `RefundRepository::updateRefund()` |
| Payment status → `refunded` | `RefundRepository::updateRefund()` |
| Gateway refund (MyFatoorah `MakeRefund`) | `RefundController::updateRefund()` |
| Shop balance debited | `RefundController` (decrement `total_earnings`, `current_balance`) |
| Customer wallet credited | `RefundController` (increment `total_points`, `available_points`) |
| Inventory restored | `RestoreInventoryOnRefund` listener |
| Reviews removed | `RatingRemoved` listener |

---

## 6. Cart State Machine

### 6.1 States

| Status | Meaning |
|---|---|
| `active` | Cart in use, inventory reserved |
| `checked_out` | Cart converted to order (terminal) |
| `expired` | Cart abandoned, inventory released (terminal) |

### 6.2 Transitions

```
active        → checked_out  (CartInventoryService::finalizeCart / finalizeItemsByShippingMethod)
active        → expired      (CartInventoryService::expireCart / expireCarts)
checked_out   → (terminal)
expired       → (terminal)
```

### 6.3 Side Effects
- `active → checked_out`: Items deleted, `finalizeStock()` (reserved → sold), cart total → 0
- `active → expired`: Items deleted, `releaseStock()` (reserved → available), cart total → 0

---

## 7. Inventory State Machine

### 7.1 Quantity Columns

| Column | Purpose |
|---|---|
| `stock_quantity` | Physical available stock |
| `reserved_quantity` | In active carts, awaiting checkout |
| `sold_quantity` | Paid and delivered |

### 7.2 Transitions

| Operation | stock | reserved | sold | Trigger |
|---|---|---|---|---|
| `reserveStock()` | — | +qty | — | Add to cart |
| `releaseStock()` | — | -qty | — | Cart expired / remove item |
| `finalizeStock()` | -qty | -qty | +qty | Payment confirmed |
| `restoreStock()` (cancel) | +qty | — | -qty | Order cancelled |
| `restoreStock()` (refund) | +qty | — | -qty | Refund approved |

**Atomic guard**: `inventory_restored_at` timestamp on orders table prevents double restoration. Checked in both `RestoreProductInventory` and `RestoreInventoryOnRefund` listeners.

**Skipped items**: Gift items, rental products, digital products are excluded from inventory adjustments.

### 7.3 Stock Visibility

```php
available = stock_quantity - reserved_quantity
in_stock  = available > 0
```

Updated after every reserve/release/finalize operation in `CartInventoryService`.

---

## 8. Complete State Flow Diagram

```
  CART: active
  (inventory: reserved)
        │
        │ checkout
        ▼
  ORDER: pending
  TXN:   pending
  INV:   (none)
        │
        ├──────────────────┬──────────────────┐
        │ ONLINE           │ COD/CASHIER      │ CANCEL
        ▼                  ▼                  ▼
  Gateway verify     Mark as paid      ORDER: cancelled
        │                  │            TXN:   failed
        ▼                  ▼            PROMO: -usage
  ORDER: completed    ORDER: completed  INV:   restored
  TXN:   paid         TXN:   paid       EVENT: OrderCancelled
  INV:   finalized    INV:   finalized
  COUPON: recorded    COUPON: recorded
  PROMO: +usage       PROMO: +usage
  EVENT: PaymentSucceeded
        │
        ▼
  INVOICE: generated → pdf_generating → ready
        │
        ▼
  ORDER: delivered (manual admin)
        │
        ─────────────────────────────────
        │                               │
  CANCELLATION                     REFUND
  (before payment)                 (after payment)
        │                               │
  ORDER: cancelled                REFUND: pending → approved
  TXN:   failed                   ORDER:  refunded
  PROMO: -usage                   INV:    restored (if not cancelled)
  INV:   restored                 WALLET: +points
  EVENT: OrderCancelled           SHOP:   -earnings
                                  EVENT: RefundApproved
```

---

## 9. All Enums Reference

| Enum | Values | File |
|---|---|---|
| `OrderStatus` | PENDING, PROCESSING, COMPLETED, CANCELLED, REFUNDED, FAILED, AT_LOCAL_FACILITY, OUT_FOR_DELIVERY, READY_FOR_PICKUP | `Marvel/Enums/OrderStatus.php` |
| `PaymentStatus` | PENDING, PROCESSING, SUCCESS, FAILED, REVERSAL, REFUNDED, CASH_ON_DELIVERY, CASH, WALLET, AWAITING_FOR_APPROVAL | `Marvel/Enums/PaymentStatus.php` |
| `InvoiceStatus` | PENDING, GENERATED, PDF_GENERATING, READY, FAILED, CORRECTED, CANCELLED | `App/Enums/InvoiceStatus.php` |
| `RefundStatus` | APPROVED, PENDING, REJECTED, PROCESSING | `Marvel/Enums/RefundStatus.php` |
| `ProductStatus` | UNDER_REVIEW, APPROVED, REJECTED, PUBLISH, UNPUBLISH, DRAFT | `Marvel/Enums/ProductStatus.php` |
| `WithdrawStatus` | APPROVED, PENDING, ON_HOLD, REJECTED, PROCESSING | `Marvel/Enums/WithdrawStatus.php` |
| `ImportStatus` | PENDING, PROCESSING, COMPLETED, COMPLETED_WITH_ERRORS, FAILED, CANCELLED | `Marvel/Enums/ImportStatus.php` |
| `FulfillmentType` | DELIVERY, PICKUP | `Marvel/Enums/FulfillmentType.php` |
| `ShippingMethod` | SCHEDULED, FAST | `Marvel/Enums/ShippingMethod.php` |
| `ProductType` | SIMPLE, VARIABLE | `Marvel/Enums/ProductType.php` |

---

## 10. Key Classes Reference

| Class | File | Role |
|---|---|---|
| `OrderService` | `app/Services/General/OrderService.php` | Central transition engine for orders, transactions |
| `OrderCreationService` | `app/Services/Checkout/OrderCreationService.php` | Initial order + order_item creation |
| `CartInventoryService` | `app/Services/General/CartInventoryService.php` | Inventory reserve/release/finalize |
| `InvoiceService` | `app/Services/Invoice/InvoiceService.php` | Invoice generation from order |
| `GenerateInvoicePdfJob` | `app/Jobs/GenerateInvoicePdfJob.php` | PDF generation, status → ready |
| `PaymentCheckoutHandler` | `app/Services/Payment/PaymentCheckoutHandler.php` | Transaction creation + gateway orchestration |
| `OrderController` | `app/Http/Controllers/Api/General/OrderController.php` | Callback processing, mark-paid endpoints |
| `InvoiceController` | `app/Http/Controllers/Api/InvoiceController.php` | Invoice regenerate endpoint |
| `RefundController` | `packages/marvel/src/Http/Controllers/RefundController.php` | Refund approval with financial adjustments |
| `RefundRepository` | `packages/marvel/src/Database/Repositories/RefundRepository.php` | Refund CRUD + status transitions |
| `OrderStatusManagerWithPaymentTrait` | `packages/marvel/src/Traits/OrderStatusManagerWithPaymentTrait.php` | Legacy order status transitions |
| `RestoreProductInventory` | `app/Listeners/RestoreProductInventory.php` | Inventory restoration on cancellation |
| `RestoreInventoryOnRefund` | `app/Listeners/RestoreInventoryOnRefund.php` | Inventory restoration on refund approval |
| `CancelUnpaidOrders` | `app/Console/Commands/CancelUnpaidOrders.php` | Auto-cancellation of stale pending orders |
