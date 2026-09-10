# Invoice Lifecycle — Zero-Trust Production Audit

**Date**: 2026-07-27  
**Scope**: When invoice is created per payment method, business rules, behavior per order state  
**Trust Level**: ZERO — every claim verified against source code

---

## Table of Contents

1. [Current Wiring Status](#1-current-wiring-status)
2. [Invoice Creation Per Payment Method](#2-invoice-creation-per-payment-method)
3. [Invoice Behavior Per Order Status](#3-invoice-behavior-per-order-status)
4. [Invoice and Order Status Matrix](#4-invoice-and-order-status-matrix)
5. [Business Rules](#5-business-rules)
6. [Verified Bugs](#6-verified-bugs)
7. [Design Recommendations](#7-design-recommendations)

---

## 1. Current Wiring Status

The previous audit (`docs/invoice-system.md`) stated the system was "fully designed but dormant." **This is partially outdated.** The system is now partially wired:

| Component | Status |
|---|---|
| `InvoiceService::generateFromOrder()` | **COMPLETE** — creates Invoice record, dispatches PDF job |
| `GenerateInvoiceListener` (queued, `high` queue) | **COMPLETE** — listens to `App\Events\PaymentSucceeded` |
| `GenerateInvoicePdfJob` (queued, `low` queue) | **COMPLETE** — placeholder (logs + sets status to `ready`) |
| InvoiceController, API routes, Admin routes | **MISSING** |
| Email sending after PDF generation | **MISSING** |
| View/download endpoint | **MISSING** |
| Credit note flow | **DESIGNED** but not wired |
| Correction flow | **DESIGNED** but not wired |

### 1.1 Trigger Path

```
PaymentSucceeded event
  └── GenerateInvoiceListener (QUEUED, high queue)
        └── InvoiceService::generateFromOrder()
              ├── buildFullSnapshot()
              ├── validate snapshot
              ├── compute hash
              ├── generate invoice number (locked sequence)
              ├── Invoice::create()
              └── dispatch GenerateInvoicePdfJob (QUEUED, low queue)
```

### 1.2 What's Missing

- `InvoiceController` — no API to view/download invoices
- No email notification after PDF is generated
- No admin interface to view/manage invoices
- No cancellation/credit note flow
- No correction flow

---

## 2. Invoice Creation Per Payment Method

### 2.1 Online Payment (MyFatoorah)

**Flow**:
1. `OrderController::checkoutCallback()` — payment gateway callback
2. Inside DB transaction: finalize inventory → update transaction to `paid` → change order to `completed`
3. After transaction commits: dispatch `PaymentSucceeded`
4. `GenerateInvoiceListener` receives event → calls `InvoiceService::generateFromOrder()`

**Timing**: After payment is confirmed and order is completed, in a queued listener.

**Invariant**: Invoice is generated AFTER inventory is finalized, AFTER transaction is marked paid, AFTER order is completed.

**BUG-INV-LIFE-001**: The `GenerateInvoiceListener` is on the `high` queue. `GenerateInvoicePdfJob` is on the `low` queue. If the listener crashes AFTER creating the Invoice record but BEFORE dispatching the PDF job, we get an orphaned Invoice with `status = 'generated'` but no PDF generation will ever be attempted.

### 2.2 Cash on Delivery (COD)

**Flow**:
1. `OrderService::markCodAsPaid()` — admin marks COD as paid
2. Inside DB transaction: update transaction → update order → record coupon → finalize promotion → finalize inventory
3. Dispatch `PaymentSucceeded`
4. `GenerateInvoiceListener` receives event

**Timing**: When admin explicitly marks COD as paid.

**BUG-INV-LIFE-002**: `markCodAsPaid()` dispatches `PaymentSucceeded` and then `GenerateInvoiceListener` fires. But `PaymentSucceeded` is also dispatched by the online callback. The listener handles both — same flow, same code. This is correct.

**BUT**: For COD orders, the invoice should arguably be generated at ORDER TIME (when the order is placed), not when payment is collected. If the invoice is issued when payment is collected (days after delivery), the customer received the goods without an invoice.

### 2.3 Cashier QR Payment

**Flow**: Identical to COD:
1. `OrderService::markCashierPaid()` — marks cashier payment as paid
2. Same transaction flow: update transaction → update order → record coupon → finalize promotion → finalize inventory
3. Dispatch `PaymentSucceeded`
4. `GenerateInvoiceListener` receives event

**Timing**: When admin/cashier marks cashier payment as paid.

**Same issue as COD**: Invoice generated at payment collection, not at order time.

### 2.4 Full Wallet Payment

**Flow**: Via `OrderRepository::storeOrder()` (Marvel admin path):
1. Creates order, deducts stock, records coupon
2. Never dispatches `App\Events\PaymentSucceeded`
3. Dispatch `Marvel\Events\OrderProcessed` (which has NO listeners)

**BUG-INV-LIFE-003**: Wallet payments bypass the invoice system entirely. `OrderRepository::storeOrder()` dispatches `Marvel\Events\OrderProcessed`, not `App\Events\PaymentSucceeded`. The `GenerateInvoiceListener` never fires for wallet payments.

---

## 3. Invoice Behavior Per Order Status

### 3.1 Order: Pending (Not Yet Paid)

**Invoice**: Should NOT exist. No payment has been made.

**Current behavior**: Invoice is NOT created (only triggered by `PaymentSucceeded`). ✓

**BUG**: If an admin manually creates an invoice for a pending order (not currently possible since no admin interface exists, but should be prevented at the design level).

### 3.2 Order: Completed + Paid

**Invoice**: SHOULD be created. This is the primary flow.

**Current behavior**: `GenerateInvoiceListener` fires → `InvoiceService::generateFromOrder()` creates Invoice. ✓

### 3.3 Order: Cancelled (Before Payment)

**Invoice**: Should NOT exist. Order was cancelled before payment.

**Current behavior**: Invoice is NOT created (no `PaymentSucceeded` event). ✓

### 3.4 Order: Cancelled (After Payment)

**Invoice**: Should exist (payment was made). After cancellation, a **credit note** or **cancellation** should be added to the invoice.

**Current behavior**: Invoice was created when payment succeeded. After cancellation, the invoice remains in `generated` status. No cancellation recorded on the invoice.

**BUG-INV-LIFE-004**: No listener on `OrderCancelled` updates the invoice status to `cancelled` or generates a credit note.

### 3.5 Order: Refunded

**Invoice**: Invoice exists (payment was made). After refund, the invoice should reflect this.

**Current behavior**: Invoice remains unchanged after refund.

**BUG-INV-LIFE-005**: No listener on `RefundApproved` creates a credit note or marks the invoice as cancelled.

### 3.6 Order: Failed (Payment Failed)

**Invoice**: Should NOT exist. No successful payment.

**Current behavior**: Invoice is NOT created (no `PaymentSucceeded`). ✓

### 3.7 Order: Processing

**Invoice**: Should be created if payment was made (COD orders are in "processing" status when placed, payment comes later).

**Current behavior**: If COD is marked as paid → order is set to `completed` → invoice created. If order is in `processing` status (before payment for COD), no invoice exists.

**BUG-INV-LIFE-006**: For COD orders, there's a period where the order is placed and accepted (processing) but not yet paid. During this period, there's no invoice. If the customer needs an invoice during this period, they can't get one.

---

## 4. Invoice and Order Status Matrix

### 4.1 Allowed Combinations

| Order Status | Payment Status | Invoice Status | Valid? | Notes |
|---|---|---|---|---|
| pending | pending | — | ✓ | No invoice yet |
| pending | failed | — | ✓ | Payment failed, no invoice |
| pending | paid | — | ✗ | Can't be paid without completing order |
| processing | pending | — | ✓ | COD — waiting for payment |
| processing | paid | — | ✓ | Admin marked COD as paid but delivery in progress |
| completed | paid | generated → generating → ready | ✓ | Primary success path |
| completed | paid | cancelled | ✓ | Invoice was cancelled (credit note issued) |
| cancelled | pending | — | ✓ | Cancelled before payment |
| cancelled | paid | generated | ✓ | Cancelled after payment — invoice should be cancelled too |
| cancelled | paid | cancelled | ✓ | Invoice properly cancelled with credit note |
| refunded | refunded | generated | ✓ | Invoice exists but should show refunded amount |
| refunded | refunded | cancelled | ✓ | Credit note issued for the refund |

### 4.2 Missing Invoice Status Transitions

| Trigger | Current Behavior | Expected Behavior |
|---|---|---|
| Order cancelled (after payment) | Invoice unchanged | Invoice status → `cancelled`, `cancelled_at` set |
| Refund approved | Invoice unchanged | Invoice status → `cancelled`, or create credit note |
| Order re-completed after cancel | Invoice unchanged | Invoice should be regenerated or un-cancelled |

---

## 5. Business Rules

### 5.1 One Invoice Per Order

```php
$existing = Invoice::where('order_id', $order->id)->first();
if ($existing) {
    return $existing;  // Return existing invoice, don't duplicate
}
```

**Rule**: Exactly one invoice per order, ever. If invoice exists, return it.

**Implication**: If `PaymentSucceeded` fires twice (possible — race condition), `generateFromOrder()` is idempotent. Second call returns the existing invoice. ✓

### 5.2 Invoice Numbers Are Monotonic and Gap-Free

```php
$seq->increment('last_sequence');
$number = sprintf('%s-%d-%06d', $series, $year, $seq->last_sequence);
```

**Rule**: Invoice numbers follow `INV-2026-000001`, `INV-2026-000002`, etc. No gaps in sequence (locked by `lockForUpdate`).

**BUG-INV-LIFE-007**: If `InvoiceService::generateFromOrder()` throws after incrementing the sequence but before creating the Invoice record, the sequence is consumed but no invoice is created. This creates a gap in the sequence.

**Fix**: Move sequence generation to the very end of the transaction, after the Invoice is created:
```php
DB::transaction(function () use ($order) {
    $snapshot = $this->snapshotService->buildFullSnapshot($order);
    $this->snapshotValidator->validate($snapshot);
    $hash = $this->integrityService->computeHash($snapshot);

    $invoice = Invoice::create([...]);  // No number yet

    $numberData = $this->numberService->generateNext();  // Lock + increment
    $invoice->update([
        'invoice_number' => $numberData['number'],
        'invoice_series' => $numberData['series'],
        'sequence_number' => $numberData['sequence'],
        'sequence_year' => $numberData['year'],
    ]);

    dispatch(new GenerateInvoicePdfJob($invoice));
    return $invoice;
});
```

### 5.3 Invoice Requires Paid Transaction

```php
$paidTransaction = $order->transactions()
    ->where('status', 'paid')
    ->latest()
    ->first();
// ...
'transaction_id' => $paidTransaction?->id,
```

**BUG-INV-LIFE-008**: If `generateFromOrder()` is called and no paid transaction exists (e.g., order completed via admin bypass, or wallet payment route that doesn't create a paid transaction), `$paidTransaction` is null. The `transaction_id` is null, `amount_paid` uses `$total` (not from transaction), and `currency` defaults to `'EGP'`.

**Impact**: Invoice references a null transaction. If `paidTransaction?->currency` is null, currency hard-codes to `'EGP'`.

### 5.4 Snapshot Hash for Integrity

```php
$hash = $this->integrityService->computeHash($snapshot);
Invoice::create([
    'data' => $snapshot,
    'snapshot_hash' => $hash,
]);
```

The hash is a SHA-256 of the JSON-encoded snapshot. This allows verification that the snapshot hasn't been tampered with. ✓

**But**: The hash is stored alongside the data in the same row. If someone modifies the `data` column, they can also modify the `snapshot_hash` to match. **The hash should be in an external audit log or blockchain, not in the same row.** But for practical purposes, it prevents accidental corruption.

---

## 6. Verified Bugs

| ID | Bug | Severity | File |
|---|---|---|---|
| **BUG-INV-LIFE-001** | Orphaned Invoice if listener crashes after create but before PDF job dispatch | MEDIUM | `InvoiceService.php:73` |
| **BUG-INV-LIFE-002** | COD invoice generated at payment time, not order time | LOW | `OrderService.php:596` |
| **BUG-INV-LIFE-003** | Wallet payments bypass invoice system entirely | HIGH | `OrderRepository.php` dispatches `OrderProcessed`, not `PaymentSucceeded` |
| **BUG-INV-LIFE-004** | No invoice status change when order is cancelled after payment | HIGH | No `OrderCancelled` listener for invoice |
| **BUG-INV-LIFE-005** | No invoice status change when order is refunded | HIGH | No `RefundApproved` listener for invoice |
| **BUG-INV-LIFE-006** | No invoice available during COD processing period (before payment) | LOW | Design limitation |
| **BUG-INV-LIFE-007** | Sequence gap if Invoice creation fails after sequence increment | MEDIUM | `InvoiceNumberService.php:28` |
| **BUG-INV-LIFE-008** | Null transaction_id if no paid transaction exists when invoice generated | MEDIUM | `InvoiceService.php:35-38` |
| **BUG-INV-LIFE-009** | `GenerateInvoicePdfJob` is a placeholder — logs instead of generating PDF | MEDIUM | `GenerateInvoicePdfJob.php:29` |
| **BUG-INV-LIFE-010** | Invoice data is read from Order model fields which may be stale by the time the queued listener processes | LOW | Queue delay |

### Severity Summary

- **HIGH**: 3 (BUG-INV-LIFE-003, BUG-INV-LIFE-004, BUG-INV-LIFE-005)
- **MEDIUM**: 4 (BUG-INV-LIFE-001, BUG-INV-LIFE-007, BUG-INV-LIFE-008, BUG-INV-LIFE-009)
- **LOW**: 3 (BUG-INV-LIFE-002, BUG-INV-LIFE-006, BUG-INV-LIFE-010)

---

## 7. Design Recommendations

### 7.1 High: Add Invoice Listeners for Cancellation and Refund

**OrderCancelled → CancelInvoice**
```php
// New listener
class CancelInvoiceListener implements ShouldQueue {
    public function handle(OrderCancelled $event): void {
        $invoice = Invoice::where('order_id', $event->order->id)->first();
        if ($invoice && $invoice->status !== 'cancelled') {
            $invoice->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancellation_reason' => 'Order cancelled',
            ]);
        }
    }
}
```

**RefundApproved → CreditNoteInvoice** (or cancel with refund reason)

### 7.2 High: Wire Invoice for Wallet Payments

Add `App\Events\PaymentSucceeded` dispatch to the wallet payment path in `OrderRepository`.

### 7.3 Medium: Fix Sequence Gap Bug

Move `generateNext()` to after `Invoice::create()` in the transaction:

```php
$invoice = Invoice::create([...]);  // Without number
$numberData = $this->numberService->generateNext();  // Lock + increment
$invoice->update(['invoice_number' => ..., ...]);
```

### 7.4 Medium: Add Invoice Controller and Routes

Minimum endpoints:
- `GET /invoices/{id}` — View invoice JSON
- `GET /invoices/{id}/pdf` — Download PDF
- `GET /orders/{id}/invoice` — Get invoice for an order
- `GET /invoices/{id}/verify` — Verify snapshot hash

### 7.5 Low: Generate Invoice at COD Order Time

For COD orders, generate a **proforma invoice** at order time (before payment). When payment is collected, update the invoice status or regenerate with payment details. This gives the customer an invoice immediately.
