# Invoice System Lifecycle Design

## Table of Contents

1. [Current State](#1-current-state)
2. [Invoice Status Machine](#2-invoice-status-machine)
3. [Trigger Points](#3-trigger-points)
4. [Generation Pipeline](#4-generation-pipeline)
5. [Invoice Number Generation](#5-invoice-number-generation)
6. [Snapshot Architecture](#6-snapshot-architecture)
7. [Snapshot Validation Pipeline](#7-snapshot-validation-pipeline)
8. [Financial Invariants](#8-financial-invariants)
9. [PDF Generation Lifecycle](#9-pdf-generation-lifecycle)
10. [Correction Flow](#10-correction-flow)
11. [Cancellation & Credit Notes](#11-cancellation--credit-notes)
12. [Refund Flow](#12-refund-flow)
13. [Event Flow & Integration Points](#13-event-flow--integration-points)
14. [Gap Analysis](#14-gap-analysis)
15. [Critical Questions Answered](#15-critical-questions-answered)
16. [Bugs & Issues Found](#16-bugs--issues-found)

---

## 1. Current State

### What Exists (Designed & Coded but NOT Wired)

| Component | Status | Location |
|-----------|--------|----------|
| `Invoice` model | Complete | `app/Models/Invoice.php` |
| `InvoiceSequence` model | Complete | `app/Models/InvoiceSequence.php` |
| `InvoiceStatus` enum | Complete | `app/Enums/InvoiceStatus.php` |
| `InvoiceSnapshotService` | Complete | `app/Services/Invoice/InvoiceSnapshotService.php` |
| `InvoiceSnapshotValidator` | Complete | `app/Services/Invoice/InvoiceSnapshotValidator.php` |
| `SnapshotIntegrityService` | Complete | `app/Services/Invoice/SnapshotIntegrityService.php` |
| `InvoiceNumberService` | Complete | `app/Services/Invoice/InvoiceNumberService.php` |
| 6 Validators | Complete | `app/Services/Invoice/Validators/*.php` |
| `SnapshotValidatorInterface` | Complete | `app/Contracts/Services/Invoice/SnapshotValidatorInterface.php` |
| `invoices` migration | Complete | `2026_07_16_000002_create_invoices_table.php` |
| `invoice_sequences` migration | Complete | `2026_07_16_000001_create_invoice_sequences_table.php` |
| PDF Blade template (app) | Complete | `resources/views/pdf/order-invoice.blade.php` |
| PDF Blade template (marvel) | Complete | `packages/marvel/stubs/resources/views/pdf/order-invoice.blade.php` |
| Email template (order-invoice) | Complete | `resources/views/emails/order/order-invoice.blade.php` |
| ADR-003 Architecture Decision | Complete | `docs/cms-endpoints/invoice-system-architecture.md` |
| InvoiceController | **MISSING** | — |
| InvoiceService (orchestrator) | **MISSING** | — |
| Listener on PaymentSucceeded | **MISSING** | — |
| Admin routes for invoices | **MISSING** | — |
| API endpoints for invoices | **MISSING** | — |
| InvoiceResource | **MISSING** | — |
| InvoiceRequest | **MISSING** | — |

### Key Finding

The invoice system is **fully designed but dormant**. All infrastructure exists, but `Invoice::create()` is never called anywhere in the codebase. No event listener, no controller, no artisan command triggers invoice generation. The system awaits integration wiring.

---

## 2. Invoice Status Machine

### Statuses (defined in `InvoiceStatus` enum)

```
PENDING      → created but not yet populated with snapshot data
GENERATED    → snapshot built and stored, PDF not yet generated
PDF_GENERATING → PDF generation in progress
READY        → snapshot built + PDF generated, final state
FAILED       → generation failed (recoverable)
CORRECTED    → this invoice was corrected by a newer correction invoice
CANCELLED    → invoice cancelled (credit note issued)
```

### State Transition Diagram

```
┌──────────┐
│  PENDING │ ◄── Invoice record created
└────┬─────┘
     │
     ▼
┌───────────┐
│ GENERATED │ ◄── Snapshot built, validated, stored
└────┬──────┘
     │
     ├──────────────────────────►┌────────┐
     │                            │ FAILED │ ◄── Snapshot validation or build fails
     │                            └───┬────┘
     │                                │
     │                                └──► retry → GENERATED
     │
     ▼
┌───────────────┐
│ PDF_GENERATING│ ◄── PDF generation started
└───────┬───────┘
        │
        ├──────────────────────────►┌────────┐
        │                            │ FAILED │ ◄── PDF generation fails
        │                            └───┬────┘
        │                                │
        │                                └──► retry → PDF_GENERATING
        │
        ▼
┌───────────┐
│   READY   │ ◄── Final terminal state
└─────┬─────┘
      │
      ├── correction issued → new invoice created, this one → CORRECTED
      │
      └── cancellation → CANCELLED (credit note)
```

### Allowed Transitions

| From | To | Trigger |
|------|----|---------|
| PENDING | GENERATED | Snapshot build succeeds |
| PENDING | FAILED | Snapshot build/validation fails |
| GENERATED | PDF_GENERATING | PDF generation started |
| GENERATED | FAILED | PDF generation fails |
| PDF_GENERATING | READY | PDF generated successfully |
| PDF_GENERATING | FAILED | PDF generation fails |
| FAILED | GENERATED | Retry snapshot build |
| FAILED | PDF_GENERATING | Retry PDF generation |
| READY | CORRECTED | Correction invoice issued |
| READY | CANCELLED | Cancellation (credit note) |
| GENERATED | CANCELLED | Cancellation (before PDF) |

### Forbidden Transitions

| From | To | Why |
|------|----|-----|
| READY | PENDING/GENERATED | Immutable once finalized |
| CANCELLED | anything | Terminal state |
| CORRECTED | anything | Terminal state |
| PENDING | READY | Must go through snapshot + PDF |

---

## 3. Trigger Points

### Primary Trigger: Payment Confirmed

When should an invoice be generated? **The moment revenue is recognized**:

**Online payment**: After callback verification (`OrderController::checkoutCallback`) — when `$transaction->status` becomes `paid` and `$order->status` becomes `completed`

**COD / Pay-at-Cashier**: After admin marks paid (`markCodAsPaid()` / `markCashierPaid()`) — same states

However, there's a legitimate business question: should the invoice be issued at `pending` (order placed) or at `completed` (payment confirmed)?

**Recommendation**: Invoice creation at `completed` only. Rationale:
- Before payment, the order may be cancelled or payment may fail
- Issuing invoice numbers for unpaid orders creates gaps and audit complexity
- The `InvoiceSnapshotService::buildFullSnapshot()` reads `$order->transactions->first()?->paid_at`, which is null until payment is confirmed

### Secondary Trigger: Admin Manual Generation

An admin should be able to manually trigger invoice generation (or regeneration) for any completed order. This covers:
- Post-failure recovery
- Re-publishing lost PDFs
- Generating retroactive invoices for orders placed before the system was active

### Tertiary Trigger: Scheduled Generation

A `GenerateInvoices` artisan command (like `CancelUnpaidOrders`) should run periodically to generate invoices for any completed order that lacks one.

### Cancellation Trigger

When an order is cancelled AFTER an invoice was generated:
- A **credit note** (negative invoice or cancellation record) should be created
- The original invoice status → `CANCELLED` or `CORRECTED`

### Integration Points (Current Code)

The system must be wired at these locations:

| Location | Event | What to Do |
|----------|-------|------------|
| `OrderService::markCodAsPaid():595` | After `PaymentSucceeded` event | Create invoice |
| `OrderService::markCashierPaid():625` | After `PaymentSucceeded` event | Create invoice |
| `OrderController::checkoutCallback():338` | After `PaymentSucceeded` event | Create invoice |
| `OrderService::changeOrderStatus():534` | When status → `completed` | Create invoice |
| `OrderService::changeOrderStatus():546` | When status → `cancelled` after invoice exists | Credit note |
| `EventServiceProvider` | `PaymentSucceeded` → `GenerateInvoice` listener | Wire the listener |

**Best approach**: Attach a listener to `App\Events\PaymentSucceeded`. This is a single integration point that covers all three payment methods (online, COD, cashier). The listener would:
1. Load the order
2. Build the snapshot
3. Validate the snapshot
4. Generate invoice number
5. Create Invoice record
6. Trigger PDF generation (async via job)

---

## 4. Generation Pipeline

The full invoice generation pipeline (to be implemented in an `InvoiceService` orchestrator):

```
Step 1: Guard — Check idempotency
  - Is there already an invoice for this order?
  - If yes, skip (or regenerate if forced)
  - Unique constraint: `uq_invoices_order_id`

Step 2: Generate Invoice Number
  - Call InvoiceNumberService::generateNext()
  - This uses DB transaction + lockForUpdate on invoice_sequences
  - Returns: number, series, sequence, year

Step 3: Build Snapshot
  - Call InvoiceSnapshotService::buildFullSnapshot($order)
  - Returns: full array with customer, items, pricing, payment, metadata

Step 4: Validate Snapshot
  - Call InvoiceSnapshotValidator::validate($snapshot)
  - All validators run:
    - StructureValidator: required keys exist
    - SnapshotVersionValidator: schema version 2, version 2.0.0
    - FinancialInvariantValidator: subtotal - promos - coupon + shipping = total
    - MoneyValidator: no more than 3 decimal places
    - CurrencyValidator: allowed currency
    - MetadataValidator: system_version, locale, generated_at

Step 5: Compute Hash
  - Call SnapshotIntegrityService::computeHash($snapshot)
  - SHA-256 of canonical JSON

Step 6: Create Invoice Record
  - INSERT INTO invoices with all financial columns + snapshot JSON + hash

Step 7: Dispatch PDF Generation Job
  - Queue job to generate PDF asynchronously
  - Job should have retry logic with backoff
  - On success: status → READY, record pdf_path, pdf_checksum, pdf_generated_at
  - On failure: status → FAILED, increment generation_attempts, record error
```

### Transaction Boundary

Steps 1-6 should execute in a SINGLE database transaction:
```
DB::transaction(function () {
    // 1. Check idempotency (lockForUpdate on orders row?)
    // 2. Generate invoice number (InvoiceNumberService handles its own inner txn)
    // 3. Build snapshot (read-only, no DB writes)
    // 4-5. Validate + hash (read-only)
    // 6. Create Invoice record
});
```

Step 7 (PDF generation) should be OUTSIDE the transaction — it's an async job.

### Concurrency Considerations

- **Idempotency**: The `uq_invoices_order_id` unique constraint prevents duplicate invoices. Wrap the creation in a try-catch for `UniqueConstraintViolationException`.
- **Race condition**: Two concurrent payments could both reach the invoice creation step. The unique constraint acts as the final guard. The first to insert wins; the second fails and can be ignored (since `PaymentSucceeded` already has idempotency via the transaction lock).
- **Numbering gap**: If invoice number generation is inside the same transaction as invoice creation, a rollback would waste a number. This is acceptable — gapless numbering is not required (and is impossible in concurrent systems without serialization).

---

## 5. Invoice Number Generation

### Implementation: `InvoiceNumberService::generateNext()`

```php
public function generateNext(string $series = 'INV'): array
{
    $year = (int) now()->year;

    return DB::transaction(function () use ($series, $year) {
        $seq = InvoiceSequence::lockForUpdate()
            ->where('series', $series)
            ->where('sequence_year', $year)
            ->first();

        if (!$seq) {
            $seq = InvoiceSequence::create([
                'series' => $series,
                'sequence_year' => $year,
                'last_sequence' => 0,
            ]);
        }

        $seq->increment('last_sequence');

        $number = sprintf('%s-%d-%06d', $series, $year, $seq->last_sequence);

        return [
            'number' => $number,
            'series' => $series,
            'sequence' => (int) $seq->last_sequence,
            'year' => $year,
        ];
    });
}
```

### Numbering Format

```
INV-2026-000001
│    │      │
│    │      └── Zero-padded sequence (6 digits → 999,999 invoices/year)
│    └── Year of generation
└── Series prefix (configurable, default 'INV')
```

### Concurrency Safety

- `lockForUpdate()` on `invoice_sequences` row ensures gapless sequences within a series+year
- Only one concurrent request can increment at a time
- Yearly reset: when the year changes, a new row is created with `last_sequence = 0`

### Series Strategy

- Default series: `INV` for standard invoices
- Correction series: `CN` (credit note) for corrections
- This could be extended per-payment-method if needed (e.g., `INV-ONLINE`, `INV-COD`)

### Potential Issue

**INV-1**: The `InvoiceNumberService::generateNext()` runs in its own `DB::transaction`. If the caller's outer transaction rolls back (e.g., invoice creation fails after number generation), the number is consumed but never used. This creates a gap. Acceptable for most jurisdictions — gapless numbering is not legally required in most markets.

---

## 6. Snapshot Architecture

### Schema Version: 2.0.0

The snapshot is a JSON blob stored in `invoices.data`. It is an immutable record of the order state at the time of invoicing.

### Snapshot Structure (from `InvoiceSnapshotService::buildFullSnapshot()`)

```php
[
    'snapshot_version' => '2.0.0',       // Semver for the snapshot schema
    'snapshot_schema' => 2,                // Integer version for programmatic checks

    'customer' => [
        'id', 'name', 'email', 'phone',
    ],

    'billing_address' => [
        'street', 'city', 'state', 'governorate', 'zip', 'country', 'coordinates',
    ],

    'shipping_address' => [                // Currently same as billing_address
        // same structure
    ],

    'fulfillment' => [
        'type',                            // delivery | pickup
        'shipping_method',                 // SCHEDULED | FAST
        'shipping_price',
        'expected_delivery_at',            // ISO8601
    ],

    'pickup_location' => [                 // null if fulfillment_type !== 'pickup'
        'id', 'name', 'address', 'phone', 'coordinates',
    ],

    'items' => [
        [
            'product_id',
            'product_variant_id',
            'product_name',
            'product_sku',
            'attributes',                  // JSON attributes from cart item
            'quantity',
            'unit_price',                   // effective unit price
            'total_price',                  // line total
            'original_price',               // same as unit_price (always)
            'discount_price',               // flash sale or product discount, nullable
            'flash_sale_price',             // nullable
            'promotion_discount_amount',    // nullable
            'is_gift',
            'promotion_id',                 // nullable
            'images',                       // EMPTY ARRAY — always []
        ],
    ],

    'pricing_breakdown' => [
        'subtotal',                         // order.price (pre-discount total)
        'promotion_discount',               // total promotion discount applied
        'coupon_discount',                  // total coupon discount applied
        'shipping_price',
        'fast_shipping_fee',
        'total',                            // grand total
        'currency',
        'exchange_rate',                    // null (always null currently)
        'coupon' => [                       // nullable
            'code', 'type', 'discount', 'max_discount_amount',
        ],
        'promotion' => [                   // nullable
            'id', 'code', 'type', 'discount',
        ],
    ],

    'payment' => [
        'method',
        'gateway',
        'transaction_id',                   // internal transaction ID
        'gateway_transaction_id',
        'paid_at',                          // ISO8601, nullable
        'gateway_invoice_id',               // null (not populated)
        'gateway_response_summary',         // null (not populated)
    ],

    'taxes' => [],                          // EMPTY — no tax system implemented

    'metadata' => [
        'system_version',                   // from config('app.version')
        'locale',
        'ip_address',                       // null (not populated)
        'user_agent',                       // null (not populated)
        'generated_at',
    ],

    'notes' => $order->notes,

    'audit' => [
        'generated_by',
        'generation_attempts',
        'correction_reason',                // null
        'cancellation_reason',              // null
    ],
]
```

### Issues with Current Snapshot

| ID | Severity | Issue |
|----|----------|-------|
| INV-2 | LOW | `items[].images` is always `[]` — empty array stored for every item. This is wasteful but harmless. |
| INV-3 | LOW | `items[].original_price` always equals `unit_price` — this field seems vestigial from a previous design where original_price might differ. |
| INV-4 | MEDIUM | `payment.paid_at` reads `$order->transactions->first()?->paid_at` — this depends on the order of transactions. If an order has multiple transactions (e.g., failed retry then success), `->first()` might return the wrong one. Should use `->where('status', 'paid')->latest()->first()`. |
| INV-5 | LOW | `pricing_breakdown.exchange_rate` is always null — this breaks if multi-currency is ever needed. |
| INV-6 | LOW | `taxes` is always `[]` — no tax system is integrated. If taxes are added later, the snapshot will need a schema upgrade. |
| INV-7 | LOW | `payment.gateway_invoice_id` and `gateway_response_summary` are always null — these fields are defined but never populated from the gateway response. |
| INV-8 | LOW | `metadata.ip_address` and `user_agent` are null — not captured during checkout. |

### Snapshot Immutability Contract

Once the snapshot is stored in `invoices.data`, it is NEVER modified. Corrections produce a NEW invoice record with `is_correction = true` and `correction_to_id` pointing to the original.

The `snapshot_hash` (SHA-256) enables integrity verification:
- On read, the system can recompute `hash(json_encode(data))` and compare with `snapshot_hash`
- If mismatched, the snapshot has been tampered with or corrupted
- This is a tamper-detection mechanism, not a tamper-prevention one

---

## 7. Snapshot Validation Pipeline

### Composite Validator Pattern

`InvoiceSnapshotValidator` accepts multiple `SnapshotValidatorInterface` implementations via constructor injection. Each validator focuses on a single concern.

### Validator Chain

| Validator | Purpose | Failure Exception |
|-----------|---------|-------------------|
| `StructureValidator` | Ensures all required keys exist at root, customer, fulfillment, pricing, payment, metadata, audit levels | `SnapshotValidationException` |
| `SnapshotVersionValidator` | Validates `snapshot_schema` is 2, `snapshot_version` is "2.0.0" | `UnsupportedSchemaException` |
| `FinancialInvariantValidator` | Verifies `subtotal - promos - coupon + shipping + fast_fee = total` within 0.01 tolerance | `FinancialInvariantException` |
| `MoneyValidator` | Ensures monetary values have ≤ 3 decimal places, are numeric | `SnapshotValidationException` |
| `CurrencyValidator` | Validates currency is in allowed list (EGP, USD, EUR, GBP, SAR, AED) | `CurrencyMismatchException` |
| `MetadataValidator` | Ensures `system_version`, `locale` (string), and `generated_at` exist | `SnapshotValidationException` |

### Validation Flow

```
Snapshot array
    │
    ▼
StructureValidator ────► Pass/Fail
    │
    ▼
SnapshotVersionValidator ──► Pass/Fail
    │
    ▼
FinancialInvariantValidator ──► Pass/Fail
    │
    ▼
MoneyValidator ──► Pass/Fail
    │
    ▼
CurrencyValidator ──► Pass/Fail
    │
    ▼
MetadataValidator ──► Pass/Fail
    │
    ▼
[All passed] → compute hash, store invoice
```

### Financial Invariant Formula

```
computedTotal = subtotal - promotion_discount - coupon_discount + shipping_price + fast_shipping_fee
assert |computedTotal - declaredTotal| <= 0.01
```

This matches the formula used in `OrderCreationService::createOrder()`:
```php
$totalPrice = round((float) $checkoutTotals->finalTotal + $shippingPrice + ($fastShippingFee ?? 0), 2);
```

Where `finalTotal = round(max(0, priceAfterPromotion - couponDiscount), 2)` (from `calculateCheckoutTotals()`).

### Tolerance

The tolerance of `0.01` accounts for floating-point rounding at each stage. This is acceptable for EGP pricing.

---

## 8. Financial Invariants

### Invariant 1: Total Formula (VALIDATED)

```
total = subtotal - promotion_discount - coupon_discount + shipping_price + fast_shipping_fee
```

Verified by `FinancialInvariantValidator`.

### Invariant 2: Item Total Consistency (NOT Validated)

For each item: `total_price = unit_price * quantity`. But the current snapshot does not validate this. The values are taken directly from `order_items`, which should already be consistent from order creation.

### Invariant 3: Coupon Discount Consistency (NOT Validated)

`coupon_discount` should equal the actual discount calculated from the coupon. The snapshot stores it as-is from the order, which was computed during checkout. No re-validation occurs.

### Invariant 4: Promotion Discount Consistency (NOT Validated)

Same as coupon — stored as-is from the order.

### Invariant 5: Sum of Item Totals vs Subtotal (NOT Validated)

The snapshot does not sum `items[].total_price` and compare with `pricing_breakdown.subtotal`. These should match (before discounts), but there's no cross-check.

### Recommendations

| ID | Severity | Recommendation |
|----|----------|----------------|
| INV-9 | LOW | Add a validator that checks `sum(items[].total_price) == subtotal` (within tolerance) |
| INV-10 | LOW | Add a validator that checks `sum(items[].promotion_discount_amount) == promotion_discount` (if all items have promotion data) |
| INV-11 | LOW | Move the financial invariant calculation to use the same rounding strategy as checkout (PHP `round(x, 2)`) — currently uses float comparison with tolerance |

---

## 9. PDF Generation Lifecycle

### PDF Templates

Two PDF templates exist:

| Template | Location | Purpose |
|----------|----------|---------|
| App template | `resources/views/pdf/order-invoice.blade.php` | Used by the app checkout flow |
| Marvel stub | `packages/marvel/stubs/resources/views/pdf/order-invoice.blade.php` | Used by the Marvel admin flow |

### PDF Generation Flow (to be implemented)

```
1. Invoice status → PDF_GENERATING
2. Generate PDF:
   a. Decode JSON snapshot from invoices.data
   b. Pass snapshot data to Blade view
   c. Render HTML
   d. Convert HTML to PDF (Barryvdh\DomPDF or similar)
   e. Store PDF file at storage path (e.g., invoices/INV-2026-000001.pdf)
3. On success:
   - Update pdf_path, pdf_checksum (SHA-256 of file), pdf_generated_at
   - Set generation_attempts to 0 (reset on success)
   - Status → READY
4. On failure:
   - Increment generation_attempts
   - Set last_generation_error
   - Status → FAILED
   - Retry via queue with exponential backoff (max 3 attempts)
```

### PDF Regeneration

Triggered by admin action:
- Updates `pdf_regenerated_at`
- Re-computes `pdf_checksum`
- Does NOT change `generated_at` (original generation time is immutable)
- Status cycle: READY → PDF_GENERATING → READY

### Retry Strategy

| Attempt | Delay | Action |
|---------|-------|--------|
| 1 | 0s (immediate) | Queue job |
| 2 | 60s | After failure |
| 3 | 300s | After failure |
| 4+ | — | Abort, mark FAILED, notify admin |

---

## 10. Correction Flow

### When to Correct

A correction is issued when:
- An error is discovered in a finalized invoice (READY status)
- The order data changed after invoicing (e.g., price adjustment, refund)

### Correction Process

```
1. Original invoice identified (status: READY)
2. New invoice created with:
   - is_correction = true
   - correction_to_id = original_invoice.id
   - correction_reason = "Why this correction exists"
3. Original invoice status → CORRECTED
4. New invoice follows the same generation pipeline:
   - Snapshot from the updated order state
   - New invoice number (CN-2026-000001 series)
   - New PDF generated
5. Both invoices are preserved for audit
```

### What Correction Does NOT Do

- Does NOT modify the original invoice's data or snapshot
- Does NOT delete or hide the original invoice
- Does NOT update order financials

### Series for Corrections

Corrections should use a separate series (e.g., `CN` for Credit Note) to distinguish them from original invoices. The `InvoiceNumberService` supports this via the `$series` parameter.

---

## 11. Cancellation & Credit Notes

### Invoice Cancellation vs Order Cancellation

These are separate concepts:

| Concept | Trigger | Effect |
|---------|---------|--------|
| Order cancelled | Admin action / timeout | Order status → cancelled |
| Invoice cancelled | Invoice already issued for a now-cancelled order | Invoice status → CANCELLED |

### Invoice Cancellation Flow

```
1. Order is cancelled (by admin or timeout)
2. Check: Does this order have an invoice?
   - If no invoice: nothing to cancel (invoice never existed)
   - If invoice exists:
     a. Create a cancellation/credit note record
     b. Original invoice status → CANCELLED
     c. Record cancellation_reason and cancelled_at
```

### Three Scenarios

| Payment Status | Invoice Status | Cancellation Action |
|----------------|----------------|---------------------|
| Never paid (pending/cancelled) | No invoice exists | No action needed |
| Paid → refunded | Invoice exists, status READY | Cancel invoice, issue credit note |
| Paid → cancelled (admin reversal) | Invoice exists, status READY | Cancel invoice, refund via gateway |

---

## 12. Refund Flow

### Current Refund Handling

The refund system currently operates entirely outside the invoice system:

1. Admin triggers refund via `MyFatoorahGateway::refund()`
2. `RestoreInventoryOnRefund` handles `RefundApproved` event
3. Inventory is restored (stock++, sold--)
4. **Order status does NOT change** (remains 'completed')
5. **Invoice is NOT touched**

### What Should Happen

When a refund is processed on an invoiced order:
1. Process the gateway refund
2. Create a **credit note** invoice (new Invoice record with negative amounts or correction marker)
3. Original invoice status → CORRECTED (if partial refund) or CANCELLED (if full refund)
4. The credit note follows the same generation pipeline but uses negative financial amounts

### Key Issues

| ID | Severity | Issue |
|----|----------|-------|
| INV-12 | MEDIUM | Refunds do not interact with invoices at all. No credit note is generated. No invoice status change. |
| INV-13 | LOW | There is no partial refund support in the invoice system. A partial refund should create a correction, not a cancellation. |

---

## 13. Event Flow & Integration Points

### Current Event Map (No Invoice Integration)

```
PaymentSucceeded (App\Events)
    ├── SendPaymentSucceededNotification (queued)
    └── [NO INVOICE GENERATION]

OrderCreated (App\Events)
    └── SendNewOrderNotification (queued)

OrderStatusChanged (App\Events)
    └── SendOrderStatusChangedNotification (queued)

OrderCancelled (App\Events)
    ├── RestoreProductInventory (queued)
    ├── SendOrderCancelledNotification (queued)
    └── [NO INVOICE CANCELLATION]
```

### Required Integration Points

The following changes are needed to wire the invoice system:

#### 1. Add Listener to `PaymentSucceeded`

```php
// In EventServiceProvider:
PaymentSucceeded::class => [
    SendPaymentSucceededNotification::class,
    GenerateInvoiceListener::class,  // NEW
],
```

The `GenerateInvoiceListener` should:
1. Load the order from the event
2. Check if an invoice already exists (idempotency)
3. Build snapshot via `InvoiceSnapshotService`
4. Validate via `InvoiceSnapshotValidator`
5. Generate invoice number via `InvoiceNumberService`
6. Create `Invoice` record
7. Dispatch PDF generation job

#### 2. Add Listener to `OrderCancelled`

```php
// For orders that were already invoiced:
OrderCancelled::class => [
    RestoreProductInventory::class,
    SendOrderCancelledNotification::class,
    CancelInvoiceListener::class,  // NEW
],
```

The `CancelInvoiceListener` should:
1. Load the order
2. Check if an invoice exists for this order
3. If yes: set status → CANCELLED, record cancellation_reason + cancelled_at
4. Generate credit note if needed

#### 3. InvoiceController (Admin CRUD)

Required endpoints:

| Method | Route | Purpose |
|--------|-------|---------|
| GET | `/api/admin/invoices` | List invoices (paginated, filterable) |
| GET | `/api/admin/invoices/{id}` | Show invoice detail with snapshot |
| POST | `/api/admin/invoices/generate/{orderId}` | Manually trigger generation |
| POST | `/api/admin/invoices/{id}/regenerate-pdf` | Regenerate PDF |
| POST | `/api/admin/invoices/{id}/correct` | Issue correction invoice |
| POST | `/api/admin/invoices/{id}/cancel` | Cancel invoice |
| GET | `/api/admin/invoices/{id}/pdf` | Download PDF |

#### 4. Admin Notification

When PDF generation fails after 3 retries, an admin should be notified (e.g., via a `InvoicePdfGenerationFailed` event + listener).

---

## 14. Gap Analysis

### Infrastructure Present But Not Wired

| Component | Exists? | Wired? | Action Required |
|-----------|---------|--------|-----------------|
| `Invoice` model | YES | NO | Create controller + service |
| `InvoiceSequence` model | YES | NO | Already auto-wired via `InvoiceNumberService` |
| `InvoiceSnapshotService` | YES | NO | Create orchestrator to call it |
| `InvoiceSnapshotValidator` | YES | NO | Create orchestrator to call it |
| `SnapshotIntegrityService` | YES | NO | Already auto-wired |
| `InvoiceNumberService` | YES | NO | Already auto-wired |
| Validators (6) | YES | NO | Already auto-wired |
| Migrations | YES | NO | Run `php artisan migrate` |
| PDF templates | YES | NO | Wire into PDF generation job |

### Missing Components

| Component | Priority | Effort |
|-----------|----------|--------|
| `InvoicesController` (admin) | HIGH | Medium |
| `InvoiceResource` (API response) | HIGH | Small |
| `GenerateInvoiceListener` | HIGH | Small |
| `CancelInvoiceListener` | MEDIUM | Small |
| `GenerateInvoicePdfJob` (queue) | HIGH | Medium |
| `InvoiceOrchestrator` / `InvoiceService` | HIGH | Medium |
| Routes for admin invoice endpoints | HIGH | Small |
| `php artisan invoices:generate` command | MEDIUM | Small |
| Invoice policy (authorization) | MEDIUM | Small |
| Email attachment of invoice PDF | LOW | Small |

### Implementation Sequence

```
Phase 1: Core Wiring (Priority: HIGH)
  1. Create InvoiceService (orchestrator)
  2. Create GenerateInvoiceListener
  3. Wire into EventServiceProvider (PaymentSucceeded)
  4. Create GenerateInvoicePdfJob
  5. Run migrations
  6. Test: payment success → invoice created + PDF generated

Phase 2: Admin UI (Priority: HIGH)
  7. Create InvoicesController
  8. Create InvoiceResource
  9. Create admin routes
  10. Create authorization policy
  11. Test: admin can view, generate, regenerate

Phase 3: Lifecycle Completeness (Priority: MEDIUM)
  12. CancelInvoiceListener (wire to OrderCancelled)
  13. Correction flow
  14. Credit note for refunds
  15. php artisan invoices:generate command

Phase 4: Polish (Priority: LOW)
  16. Email invoice PDF as attachment
  17. Additional validators (sum of items vs subtotal)
  18. Gateway invoice ID population in snapshot
```

---

## 15. Critical Questions Answered

### Q1: When is an invoice created?

Currently: **NEVER** (system is dormant).

Should be: On `PaymentSucceeded` event (i.e., when payment is confirmed).

### Q2: Can an invoice exist without payment?

No — invoices should only be generated for completed (paid) orders.

### Q3: What if payment succeeds but invoice generation fails?

The order is still marked as completed. The invoice can be regenerated later (via admin action or scheduled command). The `generation_attempts` counter tracks retries.

### Q4: Can an invoice be regenerated?

Yes — admin can trigger PDF regeneration. The original `generated_at` is preserved, but `pdf_regenerated_at` is updated.

### Q5: What happens when an invoiced order is cancelled?

The invoice should be marked as `CANCELLED` and a credit note should be issued.

### Q6: Are corrections possible?

Yes — a new correction invoice is created with `is_correction = true` and `correction_to_id` pointing to the original. Original invoice status → `CORRECTED`.

### Q7: What if two payments succeed for the same order?

The unique constraint `uq_invoices_order_id` prevents duplicate invoices. The second `PaymentSucceeded` event would attempt to create a duplicate and fail — which is correct (the transaction-level idempotency guards should have prevented this).

### Q8: Is the snapshot guaranteed to match the order?

No — the snapshot is a point-in-time capture. If order data changes after invoicing (e.g., admin modifies the order), the snapshot reflects the original state. Corrections handle this.

### Q9: What currency is used?

EGP only. The `CurrencyValidator` allows other currencies but the system only generates EGP invoices.

### Q10: Can invoices be deleted?

No — the migration uses `restrictOnDelete()` for order and user foreign keys. Invoices are immutable records. Corrections and cancellations change status but never delete data.

### Q11: How are taxes handled?

The `taxes` field exists in the snapshot but is always empty `[]`. The system has no tax calculation component.

### Q12: What happens to the invoice if the order is refunded?

Currently: nothing. The invoice remains in READY status with no interaction. Recommended: create a credit note and mark the original as CORRECTED or CANCELLED.

---

## 16. Bugs & Issues Found

| ID | Severity | Location | Description |
|----|----------|----------|-------------|
| INV-1 | INFO | `InvoiceNumberService::generateNext()` | Number is generated in its own transaction. If the caller's transaction rolls back, the number is consumed but never used (gap). Acceptable for most jurisdictions. |
| INV-2 | LOW | `InvoiceSnapshotService:55` | `items[].images` is always `[]` — empty array stored per item. Wasteful but harmless. |
| INV-3 | LOW | `InvoiceSnapshotService:49` | `items[].original_price` always equals `unit_price`. Vestigial field. |
| INV-4 | MEDIUM | `InvoiceSnapshotService:85` | `payment.paid_at` uses `$order->transactions->first()?->paid_at` which may return the wrong transaction if multiple exist. Should use `->where('status', 'paid')->latest()->first()`. |
| INV-5 | LOW | `InvoiceSnapshotService:66` | `pricing_breakdown.exchange_rate` is always null. |
| INV-6 | LOW | `InvoiceSnapshotService:92` | `taxes` is always `[]`. |
| INV-7 | LOW | `InvoiceSnapshotService:87-88` | `payment.gateway_invoice_id` and `gateway_response_summary` are always null — not populated from gateway response. |
| INV-8 | LOW | `InvoiceSnapshotService:96-97` | `metadata.ip_address` and `user_agent` are null — not captured during checkout. |
| INV-9 | LOW | No validator | No cross-check that `sum(items[].total_price) == subtotal` within tolerance. |
| INV-10 | LOW | No validator | No cross-check that `sum(items[].promotion_discount_amount) == promotion_discount`. |
| INV-11 | LOW | `FinancialInvariantValidator` | Uses float comparison with tolerance. Should use the same rounding strategy as checkout (round to 2 decimal places). |
| INV-12 | MEDIUM | `RestoreInventoryOnRefund` | Refunds do not interact with invoices at all. No credit note generated. No status change. |
| INV-13 | LOW | No code yet | No partial refund support in invoice design. |

### Critical Gap Summary

The most impactful issue is **INV-4**: The snapshot's `paid_at` reads `$order->transactions->first()` without filtering by `status = 'paid'`. If an order has multiple transactions (e.g., a failed attempt followed by a successful one), `->first()` could return the wrong (failed) transaction with a null `paid_at`. This would cause the snapshot's `paid_at` to be null even for a paid order.

**Fix**: Change `InvoiceSnapshotService:85`:
```php
// Current (buggy):
'paid_at' => $order->transactions->first()?->paid_at,

// Fix:
'paid_at' => $order->transactions
    ->where('status', 'paid')
    ->sortByDesc('paid_at')
    ->first()?->paid_at,
```
