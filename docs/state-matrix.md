# State Matrices: Complete Reference

> **Source code verified** — Every transition backed by `OrderService`, `InvoiceStatus`, `Shipment`, `Transaction`, `CartInventoryService`

---

## 1. Order Status Matrix

**Source**: `OrderService::$allowedOrderTransitions` (line 474-480), `OrderService@canTransitionOrderStatus`

### Transitions

| From \ To | pending | processing | completed | delivered | cancelled |
|-----------|---------|------------|-----------|-----------|-----------|
| **pending** | ✅ | ✅ | ✅ | ❌ | ✅ |
| **processing** | ❌ | ✅ | ✅ | ❌ | ✅ |
| **completed** | ❌ | ❌ | ✅ | ✅ | ❌ |
| **delivered** | ❌ | ❌ | ❌ | ✅ (terminal) | ❌ |
| **cancelled** | ❌ | ❌ | ❌ | ❌ | ✅ (terminal) |

### Transition Details

| Transition | Trigger | Actor | Event | Notification |
|-----------|---------|-------|-------|-------------|
| → pending | Checkout completes | Customer | `OrderCreated` | Admin notified |
| → processing | Admin updates status | Admin | `OrderStatusChanged` | Activity log |
| → completed | Payment callback / mark-paid | System/Admin | `PaymentSucceeded` | Activity log + invoice generated |
| → completed | Admin updates directly | Admin | `OrderStatusChanged` | Activity log |
| → delivered | Admin marks delivered | Admin | `OrderStatusChanged` | Activity log |
| → cancelled | Admin cancels / cron job | Admin/System | `OrderCancelled` | Activity log + inventory restored |

### Side Effects

| Transition | Coupon Usage | Promotion Usage | Inventory | Transaction |
|-----------|-------------|-----------------|-----------|-------------|
| → pending | — | — | Reserved | Pending |
| → completed | Recorded (if coupon) | Incremented (if promo) | Finalized (deducted) | → paid |
| → cancelled | NOT reversed | Decremented | Restored | → failed |
| → processing | — | — | — | — |
| → delivered | — | — | — | — |

---

## 2. Payment Status Matrix

**Source**: `Order` model constants `PAYMENT_STATUS_*`, `OrderService@changeOrderStatus`

### Transitions

| From \ To | PENDING | SUCCESS | FAILED | CANCELLED |
|-----------|---------|---------|--------|-----------|
| **PENDING** | — | ✅ (mark-paid, callback) | ❌ | ❌ |
| **SUCCESS** | ❌ | — | ❌ | ❌ |
| **FAILED** | ❌ | ❌ | — | ❌ |
| **CANCELLED** | ❌ | ❌ | ❌ | — |

**Note**: `payment_status` is only set to SUCCESS. It is NEVER set to FAILED or CANCELLED programmatically (column may be null). The `status` column on the order tracks failure via 'pending' → timeout → 'cancelled' by cron job.

### Per Payment Method

| Method | Initial Payment Status | Final Status | Trigger |
|--------|----------------------|--------------|---------|
| Online (MyFatoorah) | null | SUCCESS | Callback verification |
| COD | null | SUCCESS | Mark-paid by admin |
| Pay at Cashier | null | SUCCESS | Mark-paid by admin |

---

## 3. Fulfillment Status Matrix

**Source**: `OrderService::$allowedFulfillmentTransitions` (lines 482-489), `Order` model constants `FULFILLMENT_STATUS_*`

### Transitions

| From \ To | PENDING | PROCESSING | READY_FOR_PICKUP | OUT_FOR_DELIVERY | DELIVERED | CANCELLED |
|-----------|---------|------------|------------------|------------------|-----------|-----------|
| **PENDING** | ✅ | ✅ | ❌ | ❌ | ❌ | ✅ |
| **PROCESSING** | ❌ | ✅ | ✅ | ✅ | ❌ | ✅ |
| **READY_FOR_PICKUP** | ❌ | ❌ | ✅ | ❌ | ✅ | ✅ |
| **OUT_FOR_DELIVERY** | ❌ | ❌ | ❌ | ✅ | ✅ | ✅ |
| **DELIVERED** | ❌ | ❌ | ❌ | ❌ | ✅ (terminal) | ❌ |
| **CANCELLED** | ❌ | ❌ | ❌ | ❌ | ❌ | ✅ (terminal) |

### Auto-Sync from Order Status

| Order Status Change | Fulfillment Change (auto) |
|-------------------|--------------------------|
| pending → processing | PENDING → PROCESSING |
| pending → completed | PENDING → PROCESSING (auto-advance) |
| → completed | (if was PENDING → PROCESSING) |
| → cancelled | → CANCELLED |
| → delivered | → DELIVERED |

---

## 4. Invoice Status Matrix

**Source**: `InvoiceStatus` enum (`app/Enums/InvoiceStatus.php:20-36`), enforced in `Invoice@boot/saving`

### Transitions

| From \ To | PENDING | GENERATING | GENERATED | PDF_GEN | READY | FAILED | VERIFIED | DOWNLOADED | PRINTED | CORRECTED | CANCELLED | ARCHIVED |
|-----------|---------|------------|-----------|---------|-------|--------|----------|------------|---------|-----------|-----------|----------|
| **PENDING** | — | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ✅ | ❌ |
| **GENERATING** | ❌ | — | ✅ | ❌ | ❌ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| **GENERATED** | ❌ | ❌ | — | ✅ | ❌ | ❌ | ✅ | ✅ | ✅ | ✅ | ✅ | ❌ |
| **PDF_GENERATING** | ❌ | ❌ | ❌ | — | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| **READY** | ❌ | ❌ | ❌ | ❌ | — | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| **FAILED** | ❌ | ❌ | ❌ | ✅ | ❌ | — | ❌ | ❌ | ❌ | ❌ | ✅ | ❌ |
| **VERIFIED** | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | — | ✅ | ✅ | ❌ | ❌ | ✅ |
| **DOWNLOADED** | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ✅ | — | ✅ | ❌ | ❌ | ✅ |
| **PRINTED** | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ✅ | ✅ | — | ❌ | ❌ | ✅ |
| **CORRECTED** | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | — | ✅ | ✅ |
| **CANCELLED** | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | — | ✅ |
| **ARCHIVED** | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | — (terminal) |

### Transition Triggers

| Transition | Trigger | Actor | Side Effects |
|-----------|---------|-------|-------------|
| PENDING → GENERATING | Invoice creation started | System | — |
| GENERATING → GENERATED | `InvoiceService@generateFromOrder` | System | `InvoiceTimeline::recordGenerated()` |
| GENERATED → PDF_GENERATING | PDF job dispatched | System | `InvoiceTimeline::recordPdfRegenerated()` |
| PDF_GENERATING → READY | PDF generated successfully | System | `pdf_generated_at` set, `pdf_path` set |
| PDF_GENERATING → FAILED | PDF generation failed (retries exhausted) | System | `last_generation_error` set |
| FAILED → PDF_GENERATING | Regeneration triggered | Admin | `generation_attempts++`, error cleared |
| → VERIFIED | QR/URL verification | Anyone | `verify_count++`, `last_verified_at` |
| → DOWNLOADED | PDF download | Customer/Admin | `downloaded_at` set (first) |
| → PRINTED | Print event | Customer/Admin | `printed_at` set (first) |
| → CORRECTED | Correction created | Admin | Creates new invoice with `correction_to_id` |
| → CANCELLED | Order cancelled | Admin/System | `cancelled_at`, `cancellation_reason` |
| → ARCHIVED | Archival process | Admin/System | `archived_at` |

---

## 5. Transaction Status Matrix

**Source**: `Transaction` model, `OrderController@checkoutCallback`, `OrderService@markCodAsPaid`

| From \ To | pending | paid | failed |
|-----------|---------|------|--------|
| **pending** | — | ✅ | ✅ |
| **paid** | ❌ | — | ❌ |
| **failed** | ❌ | ❌ | — |

### Per Payment Method

| Method | Initial | Success | Failure |
|--------|---------|---------|---------|
| Online | pending | paid (callback) | failed (callback/error callback) |
| COD | pending | paid (mark-paid) | — |
| Cashier | pending | paid (mark-paid) | — |

---

## 6. Coupon Usage Matrix

**Source**: `OrderService@recordCouponUsage`

| Coupon Type | Initial State | After Usage | Reversible? |
|-------------|--------------|-------------|-------------|
| Public | `coupon.used = N` | `coupon.used = N+1` | **NEVER** (policy: no reversal) |
| Assigned | `assignment.used = N` | `assignment.used = N+1` | **NEVER** (policy: no reversal) |
| Assigned + audit | — | `assignment_usage` record created | **NEVER** |

**Concurrency**: `assignment` row locked with `lockForUpdate()` before incrementing.

**Reversal Policy** (documented in code comment, `OrderService:712-731`):
> "Coupon quota is consumed when payment succeeds. It is NEVER automatically returned on cancellation or refund. This prevents abuse where a user could re-use the same quota by repeatedly cancelling and re-ordering."

---

## 7. Promotion Usage Matrix

**Source**: `OrderService@finalizePromotionUsageAfterPayment`, `PromotionService@incrementUsage`

| Action | Effect | Reversible? |
|--------|--------|-------------|
| Payment success | `promotion.usage++` | ✅ Decremented on cancellation |
| Order cancelled | `promotion.usage--` | Only if promotion_id exists |

---

## 8. Cart Status Matrix

| From \ To | active | checked_out | expired |
|-----------|--------|-------------|---------|
| **active** | — | ❌ (conceptual) | ✅ (cron) |
| **checked_out** | ❌ | — | ❌ |
| **expired** | ❌ | ❌ | — |

**Actual implementation**: Cart status is `active` or (conceptually) released. There is no formal `checked_out` status transition in the code. Carts are expired via `CartInventoryService@expireCart` which calls `$cart->delete()` (soft-delete not used — cart items are deleted).

---

## 9. Inventory Status Matrix

**Source**: `CartInventoryService` (reserve/release/finalize), `RestoreProductInventory`, `RestoreInventoryOnRefund`

| State | reserved_quantity | stock_quantity | sold_quantity | Guard Column |
|-------|-------------------|----------------|---------------|--------------|
| Available | 0 | N | M | — |
| In cart | +qty | N | M | — |
| In cart (gift) | +qty (gift) | N | M | — |
| Ordered (unpaid) | +qty | N | M | — |
| Ordered (paid) | 0 (released) | N-qty (deducted) | M+qty | — |
| Cancelled (unpaid) | 0 | N | M | — |
| Cancelled (paid) | 0 | N+qty (restored) | M-qty | `inventory_restored_at` guard |
| Refunded | 0 | N+qty (restored) | M-qty | `inventory_restored_at` guard |

**Guard against double restoration**: `Order.inventory_restored_at` — must be null to restore. After first restore, set to `now()`. Subsequent restore attempts skip.

---

## 10. Refund Status Matrix

**Source**: `RefundStatus` enum (Marvel)

| From \ To | pending | processing | approved | rejected |
|-----------|---------|------------|----------|----------|
| **pending** | — | ✅ | ✅ | ✅ |
| **processing** | ❌ | — | ✅ | ✅ |
| **approved** | ❌ | ❌ | — (terminal) | ❌ |
| **rejected** | ❌ | ❌ | ❌ | — (terminal) |

**Side effects on approved**:
- `RestoreInventoryOnRefund` listener fires (dispatches to `medium` queue)
- Restores `stock_quantity` (+qty), decrements `sold_quantity` (-qty)
- Guard: `inventory_restored_at` prevents double restoration
- Skips gift items
- Skips cancelled orders
