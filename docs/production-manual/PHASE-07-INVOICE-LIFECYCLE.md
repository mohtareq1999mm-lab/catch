# PHASE 7: INVOICE LIFECYCLE

> Production Operations Manual — Invoice Lifecycle Management
> Last Updated: 2026-07-28

---

## TABLE OF CONTENTS

1. [Architecture Overview](#architecture-overview)
2. [Invoice Status Enum — Complete Transition Matrix](#invoice-status-enum--complete-transition-matrix)
3. [InvoiceService::generateFromOrder()](#invoiceservicegeneratefromorder)
4. [InvoiceSnapshotService::buildFullSnapshot()](#invoicesnapshotservicebuildfullsnapshot)
5. [Snapshot Validator Pipeline](#snapshot-validator-pipeline)
6. [SnapshotIntegrityService::computeHash()](#snapshotintegrityservicecomputehash)
7. [InvoiceNumberService::generateNext()](#invoicenumberservicegeneratenext)
8. [InvoiceTimelineService](#invoicetimelineservice)
9. [Invoice Correction Flow](#invoice-correction-flow)
10. [Invoice Cancellation Flow](#invoice-cancellation-flow)
11. [Credit Note Service](#credit-note-service)
12. [Debit Note Service](#debit-note-service)
13. [GenerateInvoicePdfJob](#generateinvoicepdfjob)
14. [Regeneration Flow](#regeneration-flow)
15. [Verification Flow (HMAC)](#verification-flow-hmac)
16. [Download Flow](#download-flow)
17. [Event/Listener Wiring](#eventlistener-wiring)
18. [Database Schema](#database-schema)
19. [Edge Cases & Failure Modes](#edge-cases--failure-modes)

---

## Architecture Overview

```
PaymentSucceeded
  └─▶ GenerateInvoiceListener (queue:high, 5 tries)
        └─▶ InvoiceService::generateFromOrder()
              ├─▶ InvoiceSnapshotService::buildFullSnapshot()
              ├─▶ InvoiceSnapshotValidator (6 validators)
              ├─▶ SnapshotIntegrityService::computeHash()
              ├─▶ InvoiceNumberService::generateNext() [lockForUpdate]
              ├─▶ Invoice::create() [status=generated]
              ├─▶ InvoiceTimelineService::recordGenerated()
              └─▶ DB::afterCommit
                    ├─▶ InvoiceCreated::dispatch()
                    │     └─▶ LogInvoiceCreated (sync)
                    └─▶ GenerateInvoicePdfJob::dispatch() (queue:low, 3 tries)
                          └─▶ status → ready OR failed
```

### Layer Separation

```
Controller ──▶ Service ──▶ Repository ──▶ Model
  │                │
  │                ├─▶ InvoiceSnapshotService
  │                ├─▶ InvoiceSnapshotValidator
  │                │     ├─ StructureValidator
  │                │     ├─ FinancialInvariantValidator
  │                │     ├─ CurrencyValidator
  │                │     ├─ MoneyValidator
  │                │     ├─ MetadataValidator
  │                │     └─ SnapshotVersionValidator
  │                ├─▶ SnapshotIntegrityService
  │                ├─▶ InvoiceNumberService
  │                └─▶ InvoiceTimelineService
  │
  ├─▶ InvoiceController (permission-gated)
  ├─▶ InvoiceResource
  └─▶ Form Requests (CorrectInvoiceRequest, DebitNoteRequest)
```

---

## Invoice Status Enum — Complete Transition Matrix

Source: `app/Enums/InvoiceStatus.php`

### 12 States

| # | Case | Value | Description |
|---|------|-------|-------------|
| 1 | `PENDING` | `pending` | Awaiting generation; initial state after payment |
| 2 | `GENERATING` | `generating` | Snapshot build & validation in progress |
| 3 | `GENERATED` | `generated` | Snapshot captured, hash stored, waiting for PDF |
| 4 | `PDF_GENERATING` | `pdf_generating` | PDF generation job dispatched and running |
| 5 | `READY` | `ready` | PDF generated successfully, ready for download |
| 6 | `FAILED` | `failed` | PDF generation failed (recoverable, retryable) |
| 7 | `VERIFIED` | `verified` | HMAC verification performed successfully |
| 8 | `DOWNLOADED` | `downloaded` | PDF downloaded at least once |
| 9 | `PRINTED` | `printed` | PDF printed at least once |
| 10 | `CORRECTED` | `corrected` | Superseded by a correction invoice |
| 11 | `CANCELLED` | `cancelled` | Voided; no longer valid |
| 12 | `ARCHIVED` | `archived` | Terminal state; no further transitions allowed |

### Full Transition Matrix

```
┌──────────────────┐
│     PENDING      │
└──────┬──────┬────┘
       │      │
       ▼      ▼
  GENERATING  CANCELLED
       │
       │
       ▼
  ┌──────────┐     ┌────────┐
  │ GENERATED│────▶│ FAILED │
  └────┬─────┘     └────────┘
       │
       ├──────────────────────────────────────────────────────┐
       │  │  │  │  │  │  │  │  │  │  │  │  │  │  │  │  │     │
       ▼  ▼  ▼  ▼  ▼  ▼  ▼  ▼  ▼  ▼  ▼  ▼  ▼  ▼  ▼  ▼  ▼     ▼
  PDF_GENERATING  VERIFIED  DOWNLOADED  PRINTED  CORRECTED  CANCELLED
       │
       │
       ▼
  ┌─────────┐     ┌────────┐
  │  READY  │────▶│ FAILED │
  └────┬────┘     └────────┘
       │
       ├──────┬──────┬──────┬──────┬──────┬──────┐
       ▼      ▼      ▼      ▼      ▼      ▼      ▼
  DOWNLOADED PRINTED VERIFIED FAILED CORRECTED CANCELLED ARCHIVED
       │
       ├──────┬──────┐
       ▼      ▼      ▼
   PRINTED VERIFIED ARCHIVED
       │
       ├──────┬──────┐
       ▼      ▼      ▼
  DOWNLOADED VERIFIED ARCHIVED
       │
       ├──────┬──────┐
       ▼      ▼      ▼
  VERIFIED DOWNLOADED ARCHIVED

  CORRECTED ──▶ CANCELLED ──▶ ARCHIVED
                       │
                       ▼
                   ARCHIVED

  FAILED ──▶ PDF_GENERATING ──▶ READY / FAILED
  FAILED ──▶ CANCELLED
```

### Allowed Transitions Per State (from source code)

| From State | Allowed To |
|---|---|
| `pending` | `generating`, `cancelled` |
| `generating` | `generated`, `failed` |
| `generated` | `pdf_generating`, `verified`, `downloaded`, `printed`, `corrected`, `cancelled` |
| `pdf_generating` | `ready`, `failed` |
| `ready` | `downloaded`, `printed`, `verified`, `failed`, `corrected`, `cancelled`, `archived` |
| `failed` | `pdf_generating`, `cancelled` |
| `verified` | `downloaded`, `printed`, `archived` |
| `downloaded` | `printed`, `verified`, `archived` |
| `printed` | `downloaded`, `verified`, `archived` |
| `corrected` | `cancelled`, `archived` |
| `cancelled` | `archived` |
| `archived` | *(none — terminal)* |

### Transition Validation

The `Invoice` model enforces transitions via a `saving` hook:

```
Invoice::saving(function (self $invoice) {
    if ($invoice->exists && $invoice->isDirty('status')) {
        $from = InvoiceStatus::tryFrom($originalStatus);
        $to   = InvoiceStatus::tryFrom($newStatus);
        if ($from && $to && !$from->canTransitionTo($to)) {
            throw new RuntimeException("Invalid transition");
        }
    }
});
```

This means **any code** that sets `$invoice->status = '...'` and calls `save()` will be validated. Bulk updates using `Invoice::where(...)->update(['status' => ...])` bypass this hook and must be avoided.

---

## InvoiceService::generateFromOrder()

Source: `app/Services/Invoice/InvoiceService.php:22`

### Signature

```php
public function generateFromOrder(Order $order): ?Invoice
```

### Transactional Flow

```
DB::transaction
  ├─ 1. SELECT ... FROM invoices WHERE order_id = ? FOR UPDATE
  │     └─ If exists → return existing (idempotent guard)
  │
  ├─ 2. $snapshot = InvoiceSnapshotService::buildFullSnapshot($order)
  │
  ├─ 3. InvoiceSnapshotValidator::validate($snapshot)
  │     └─ Runs all 6 validators in order
  │
  ├─ 4. $hash = SnapshotIntegrityService::computeHash($snapshot)
  │
  ├─ 5. InvoiceNumberService::generateNext()
  │     └─ Returns { number, series, sequence, year }
  │
  ├─ 6. Find latest paid transaction
  │
  ├─ 7. Invoice::create([...])
  │     └─ status = 'generated'
  │     └─ data = $snapshot
  │     └─ snapshot_hash = $hash
  │     └─ verification_hash = hash('sha256', $snapshotHash . app_key)
  │
  ├─ 8. InvoiceTimelineService::recordGenerated($invoice)
  │
  └─ DB::afterCommit
        ├─ InvoiceCreated::dispatch($invoice)
        │     └─ LogInvoiceCreated (sync listener)
        │
        └─ GenerateInvoicePdfJob::dispatch($invoice)
              └─ on queue 'low', 3 tries
```

### Idempotency

```php
$existing = Invoice::where('order_id', $order->id)
    ->lockForUpdate()
    ->first();
if ($existing) {
    return $existing;
}
```

- Uses `lockForUpdate()` (exclusive row-level lock on `order_id` unique scope)
- Prevents duplicate invoice generation under concurrent requests
- Returns existing invoice without side effects

### Financial Calculations

| Field | Formula |
|---|---|
| `subtotal` | `(float) $order->price` |
| `total_discount` | `promotion_discount + coupon_discount` |
| `total` | `(float) $order->total_price` |
| `amount_paid` | `total` (same as total) |
| `currency` | `$paidTransaction->currency ?? 'EGP'` |

---

## InvoiceSnapshotService::buildFullSnapshot()

Source: `app/Services/Invoice/InvoiceSnapshotService.php:9`

### Purpose

Captures a point-in-time snapshot of the order at invoice generation time. This snapshot becomes immutable and is stored as JSON in the `data` column.

### Snapshot Structure

```
snapshot_version: "2.1.0"
snapshot_schema:  3
├── order
│     id, order_number, status, payment_status, fulfillment_status
├── customer
│     id, name, email, phone
├── billing_address
│     street, city, state, governorate, zip, country, coordinates
├── shipping_address
│     (same structure as billing_address)
├── fulfillment
│     type, shipping_method, shipping_price, fast_shipping_fee, expected_delivery_at
├── pickup_location (nullable)
│     id, name, address, phone, coordinates
├── items[]
│     product_id, product_variant_id, product_name, product_sku, attributes,
│     quantity, unit_price, original_price, effective_unit_price, discount_price,
│     flash_sale_price, promotion_discount_amount, total_price, is_gift,
│     promotion_id, images[]
├── pricing_breakdown
│     subtotal, promotion_discount, coupon_discount, shipping_price,
│     fast_shipping_fee, total, currency, exchange_rate,
│     coupon { code, type, discount, max_discount_amount },
│     promotion { id, code, type, discount }
├── payment
│     method, gateway, transaction_id, gateway_transaction_id,
│     paid_at, gateway_invoice_id, gateway_response_summary
├── taxes[]
│     (currently empty — no tax engine implemented)
├── metadata
│     system_version, locale, ip_address, user_agent, generated_at
├── notes
│     (free text from order)
└── audit
      generated_by, generation_attempts, correction_reason, cancellation_reason
```

### Key Design Decisions

- `effective_unit_price` uses a priority chain: `discount_price > flash_sale_price > product_price`
- `exchange_rate` is always `null` (multi-currency not implemented)
- `taxes[]` is always empty (no tax logic in platform)
- `images[]` per item is always empty (not populated)
- `pickup_location` is only populated when `fulfillment_type === 'pickup'`
- `billing_address` and `shipping_address` both use the same `resolveAddress()` method — they are identical

---

## Snapshot Validator Pipeline

Source: `app/Services/Invoice/InvoiceSnapshotValidator.php`

### Composition

```php
class InvoiceSnapshotValidator
{
    private array $validators;  // SnapshotValidatorInterface[]
    
    public function validate(array $snapshot): void
    {
        foreach ($this->validators as $validator) {
            $validator->validate($snapshot);
        }
    }
}
```

All validators implement `App\Contracts\Services\Invoice\SnapshotValidatorInterface`:

```php
interface SnapshotValidatorInterface
{
    public function validate(array $snapshot): void;
}
```

### 1. StructureValidator

Source: `app/Services/Invoice/Validators/StructureValidator.php`

Validates presence of required keys at each nesting level:

| Context | Required Keys |
|---|---|
| root | `snapshot_version`, `snapshot_schema`, `customer`, `billing_address`, `shipping_address`, `fulfillment`, `items`, `pricing_breakdown`, `payment`, `taxes`, `metadata`, `audit` |
| customer | `id`, `name`, `email`, `phone` |
| fulfillment | `type`, `shipping_method`, `shipping_price` |
| pricing_breakdown | `subtotal`, `promotion_discount`, `coupon_discount`, `shipping_price`, `total`, `currency` |
| payment | `method`, `transaction_id`, `paid_at` |
| metadata | `system_version`, `locale`, `generated_at` |
| audit | `generated_by`, `generation_attempts` |

Additionally validates that `items` and `taxes` are arrays.

Throws `SnapshotValidationException` on failure.

### 2. FinancialInvariantValidator

Source: `app/Services/Invoice/Validators/FinancialInvariantValidator.php`

Enforces the financial invariant:

```
computedTotal = subtotal - promotion_discount - coupon_discount + shipping_price + fast_shipping_fee
|computedTotal - declaredTotal| ≤ 0.01 (tolerance)
```

Throws `FinancialInvariantException` on violation.

### 3. CurrencyValidator

Source: `app/Services/Invoice/Validators/CurrencyValidator.php`

Validates currency is one of: `EGP`, `USD`, `EUR`, `GBP`, `SAR`, `AED`.

Throws `CurrencyMismatchException` if missing or unsupported.

### 4. MoneyValidator

Source: `app/Services/Invoice/Validators/MoneyValidator.php`

Validates monetary fields:
- Must be numeric (`is_numeric`, `is_float`, or `is_int`)
- Maximum 3 decimal places

Fields checked:
- `pricing_breakdown.subtotal`, `.promotion_discount`, `.coupon_discount`, `.shipping_price`, `.total`
- Each item's `unit_price` and `total_price`

Throws `SnapshotValidationException` on failure.

### 5. MetadataValidator

Source: `app/Services/Invoice/Validators/MetadataValidator.php`

Validates:
- `metadata.system_version` is set
- `metadata.locale` is set and is a string
- `metadata.generated_at` is set

Throws `SnapshotValidationException` on failure.

### 6. SnapshotVersionValidator

Source: `app/Services/Invoice/Validators/SnapshotVersionValidator.php`

Validates:
- `snapshot_schema` is an integer and in `[2, 3]`
- `snapshot_version` (if present) is in `['2.0.0', '2.1.0']`

Throws `UnsupportedSchemaException` on failure.

---

## SnapshotIntegrityService::computeHash()

Source: `app/Services/Invoice/SnapshotIntegrityService.php:7`

### Algorithm

```php
public function computeHash(array $data): string
{
    $sorted = $this->sortRecursive($data);  // ksort at every nesting level
    $json = json_encode($sorted, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return hash('sha256', $json);
}
```

### Determinism Guarantee

1. `sortRecursive()` applies `ksort()` at every array depth — guaranteed key ordering
2. `JSON_UNESCAPED_UNICODE` — prevents `\uXXXX` escaping, ensures Arabic/non-ASCII stability
3. `JSON_UNESCAPED_SLASHES` — prevents `\/` escaping, maintains URL stability
4. `hash('sha256', ...)` — SHA-256, 64 hex characters

### Usage

- Stored as `snapshot_hash` on the invoice record
- Used as input to `verification_hash` computation
- Used by `verify()` method to check if snapshot was tampered with

### verify()

```php
public function verify(array $data, string $expectedHash): bool
{
    return hash_equals($expectedHash, $this->computeHash($data));
}
```

Uses `hash_equals()` — timing-attack-safe comparison.

---

## InvoiceNumberService::generateNext()

Source: `app/Services/Invoice/InvoiceNumberService.php:10`

### Gapless Sequence

```php
public function generateNext(string $series = 'INV'): array
{
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
            'number' => $number,   // e.g. "INV-2026-000042"
            'series' => $series,   // e.g. "INV"
            'sequence' => (int) $seq->last_sequence,
            'year' => $year,
        ];
    });
}
```

### Key Properties

- **Gapless**: `lockForUpdate()` on `invoice_sequences` table prevents race conditions. Each transaction waits for the previous to complete.
- **Yearly reset**: Sequence resets per year; different series (INV, CN, DN) have independent counters.
- **Series support**: `INV` (invoice), `CN` (credit note), `DN` (debit note).

### Format

```
{series}-{year}-{sequence:06d}
```

Example: `INV-2026-000042`, `CN-2026-000007`, `DN-2026-000003`

### Database Table: `invoice_sequences`

| Column | Type | Description |
|---|---|---|
| `series` | string (PK) | Series prefix (INV, CN, DN) |
| `sequence_year` | int | Year scope |
| `last_sequence` | int | Last used sequence number |

**Note**: `series` is the primary key with `$incrementing = false`.

---

## InvoiceTimelineService

Source: `app/Services/Invoice/InvoiceTimelineService.php`

### Write-Once Append Log

Every state change, verification, download, print, correction, cancellation, and archival is recorded as an immutable timeline entry.

### Timeline Events

| Method | Event String | old_status | new_status | Metadata |
|---|---|---|---|---|
| `recordGenerated()` | `generated` | null | `generated` | invoice_number, total, currency |
| `recordVerified()` | `verified` | current | `verified` | — |
| `recordDownloaded()` | `downloaded` | current | `downloaded` | — |
| `recordPrinted()` | `printed` | current | `printed` | — |
| `recordPdfRegenerated()` | `pdf_regenerated` | null | null | — |
| `recordCorrected()` | `corrected` | current | `corrected` | reason |
| `recordCancelled()` | `cancelled` | current | `cancelled` | reason |
| `recordArchived()` | `archived` | current | `archived` | — |

### Record Structure

```php
InvoiceTimeline::create([
    'invoice_id' => $invoice->id,
    'event' => $event,
    'old_status' => $oldStatus,
    'new_status' => $newStatus,
    'actor_type' => $request?->user()?->getMorphClass(),  // polymorphic
    'actor_id' => $request?->user()?->id,
    'metadata' => $metadata,
    'ip_address' => $request?->ip(),
]);
```

### Database Table: `invoice_timeline`

| Column | Type | Description |
|---|---|---|
| `id` | bigint (PK) | Auto-increment |
| `invoice_id` | bigint (FK) | References `invoices.id` |
| `event` | string | Event name |
| `old_status` | string|null | Previous status |
| `new_status` | string|null | New status |
| `actor_type` | string|null | Polymorphic model type |
| `actor_id` | bigint|null | Polymorphic model ID |
| `metadata` | json|null | Arbitrary event metadata |
| `ip_address` | string|null | Request IP |
| `created_at` | timestamp | Standard Laravel timestamp |

### Key Design

- **Immutable**: No update/delete operations — this is a write-only log
- **Chronological**: `created_at` ascending order gives full audit trail
- **Actor tracking**: Captures who performed the action via polymorphic relation
- **IP capture**: Captures request IP for audit compliance

---

## Invoice Correction Flow

Source: `InvoiceService::correctInvoice()` (line 114)

### Purpose

Creates a corrected invoice to supersede an existing invoice. The original is marked as `corrected` and a new invoice is generated with `is_correction = true` and `correction_to_id` pointing to the original.

### Allowed Source Statuses

Only invoices in the following statuses can be corrected:
- `generated`
- `ready`
- `verified`
- `downloaded`
- `printed`

### Flow

```
correctInvoice(originalId, overrides, reason, adminId)
│
DB::transaction
  ├─ Original: SELECT ... FOR UPDATE
  │
  ├─ Validate original status is in allowed set
  │   └─ Throws RuntimeException if not
  │
  ├─ Generate new invoice number (InvoiceNumberService::generateNext())
  │
  ├─ Clone original snapshot
  │   ├─ Set snapshot.audit.correction_reason = reason
  │   ├─ Set snapshot.audit.generated_by = 'admin:{id}'
  │   └─ Apply overrides using data_set()
  │
  ├─ Compute new snapshot hash
  │
  ├─ Create correction invoice:
  │   ├─ status = 'generated'
  │   ├─ is_correction = true
  │   ├─ correction_to_id = original.id
  │   └─ correction_reason = reason
  │
  ├─ Update original:
  │   ├─ status = 'corrected'
  │   ├─ corrected_at = now()
  │   └─ correction_reason = reason
  │
  ├─ Timeline: recordCorrected(original, reason)
  └─ Timeline: recordGenerated(correction)
```

### Correction Links

```
Original Invoice (status: corrected)
  └─ correction_to_id = null
  └─ corrections() → [Correction Invoice]
  
Correction Invoice (status: generated)
  └─ correction_to_id = original.id
  └─ is_correction = true
```

The `Invoice` model defines:
- `correctionTo()`: BelongsTo — the original this corrects
- `corrections()`: HasMany — all corrections of this invoice

### Override Mechanism

The `$overrides` array uses Laravel's `data_set()` helper, allowing dot-notation paths:

```php
data_set($snapshot, $key, $value);
```

Examples:
- `['total' => 150.00]` — changes top-level total
- `['pricing_breakdown.subtotal' => 200.00]` — changes nested pricing
- `['items.0.unit_price' => 25.00]` — changes specific item price

---

## Invoice Cancellation Flow

Source: `InvoiceService::cancelInvoice()` (line 178)

### Allowed Source Statuses

Only invoices in these statuses can be cancelled:
- `generated`
- `ready`
- `failed`
- `corrected`

This is a hard-coded array, not derived from the enum:

```php
$allowed = ['generated', 'ready', 'failed', 'corrected'];
```

Note the discrepancy with the InvoiceStatus enum which also allows `pending → cancelled`. The `cancelInvoice()` service method does NOT allow cancellation from `pending`. This means:
- `pending → cancelled` is only possible via direct status change (if at all)
- The service method is the primary cancellation path, effectively narrowing allowed cancellations

### Flow

```
cancelInvoice(id, reason, adminId)
│
DB::transaction
  ├─ Invoice: SELECT ... FOR UPDATE
  ├─ Validate status is in allowed set
  ├─ Update: status = 'cancelled', cancelled_at = now(), cancellation_reason = reason
  └─ Timeline: recordCancelled(invoice, reason)
```

---

## Credit Note Service

Source: `app/Services/Invoice/CreditNoteService.php`

### generateForRefund()

```php
public function generateForRefund(
    Invoice $invoice,
    float $amount,
    string $reason,
    ?int $refundTransactionId = null,
    ?int $createdBy = null,
): CreditNote
```

- Series: `CN`
- Type: `refund`
- Uses `InvoiceNumberService::generateNext('CN')` for gapless CN sequence
- Copies `line_items` from invoice snapshot

### generateForCancellation()

```php
public function generateForCancellation(
    Invoice $invoice,
    float $amount,
    string $reason,
    ?int $createdBy = null,
): CreditNote
```

- Series: `CN`
- Type: `cancellation`
- Same structure as refund credit note with different type and notes text

### Database Table: `credit_notes`

| Column | Type | Description |
|---|---|---|
| `id` | bigint (PK) | Auto-increment |
| `invoice_id` | bigint (FK) | References `invoices.id` |
| `credit_note_number` | string | e.g. `CN-2026-000007` |
| `credit_note_series` | string | Always `CN` |
| `sequence_number` | int | Sequence counter |
| `sequence_year` | int | Year of issuance |
| `type` | string | `refund` or `cancellation` |
| `reason` | text | Explanation |
| `amount` | decimal | Credit amount |
| `currency` | string | Currency code |
| `refund_transaction_id` | bigint|null | Related refund transaction |
| `created_by` | bigint|null | Admin user ID |
| `line_items` | json | Snapshot of invoice items at time of issuance |
| `notes` | text | Additional notes |
| `issued_at` | timestamp | Issuance timestamp |

---

## Debit Note Service

Source: `app/Services/Invoice/DebitNoteService.php`

### generate()

```php
public function generate(
    Invoice $invoice,
    float $amount,
    string $reason,
    ?int $createdBy = null,
): DebitNote
```

- Series: `DN`
- Type: `correction`
- Uses `InvoiceNumberService::generateNext('DN')` for gapless DN sequence
- Uses in `InvoiceController::issueDebitNote()`

### Allowed Invoice Statuses for Debit Note Issuance

From `InvoiceController::issueDebitNote()`:

```php
$allowed = ['generated', 'ready', 'verified', 'downloaded', 'printed'];
```

---

## GenerateInvoicePdfJob

Source: `app/Jobs/GenerateInvoicePdfJob.php`

### CURRENT STATE: PLACEHOLDER

The job does NOT actually generate a PDF. It is a stub that:

```
handle():
  ├─ Log: "PDF generation placeholder for invoice {number}"
  ├─ Update: status = 'ready', pdf_generated_at = now()
  └─ On exception:
        ├─ Update: status = 'failed', last_generation_error, generation_attempts++
        └─ throw $e
```

### Job Configuration

| Property | Value |
|---|---|
| Queue | `low` |
| Tries | 3 |
| Backoff | `[30, 120, 300]` (seconds) |
| Timeout | 120 seconds |

### failed() Handler

```php
public function failed(\Throwable $e): void
{
    Log::error('PDF generation failed for invoice ' . $this->invoice->invoice_number, [
        'invoice_id' => $this->invoice->id,
        'attempts' => $this->attempts(),
        'error' => $e->getMessage(),
    ]);
}
```

### PRODUCTION RECOMMENDATION

Replace placeholder with actual PDF generation using a library like `barryvdh/laravel-dompdf` or `mpdf/mpdf`. The job should:
1. Generate PDF from invoice snapshot data
2. Store PDF to `storage/invoices/{uuid}.pdf`
3. Set `pdf_path` to the relative path
4. Compute `pdf_checksum` (SHA-256 of file contents)
5. Set status to `ready`

---

## Regeneration Flow

Source: `InvoiceController::regenerate()` (line 196)

### Allowed Source Statuses

Only invoices in these statuses can be regenerated:
- `failed`
- `ready`
- `generated`

### Flow

```
regenerate(id)
  ├─ Find invoice
  ├─ Validate status in ['failed', 'ready', 'generated']
  ├─ Update:
  │     status = 'pdf_generating'
  │     generation_attempts++
  │     last_generation_error = null
  ├─ Timeline: recordPdfRegenerated(invoice)
  └─ Dispatch GenerateInvoicePdfJob
```

---

## Verification Flow (HMAC)

Source: `InvoiceService::verifyInvoice()` (line 94), `InvoiceService::computeVerificationHash()` (line 200)

### HMAC Algorithm

```php
private function computeVerificationHash(string $snapshotHash): string
{
    $secret = config('app.key', 'default-secret');
    return hash('sha256', $snapshotHash . $secret);
}
```

- **Input**: Snapshot hash (SHA-256 of sorted snapshot JSON) concatenated with `app.key`
- **Output**: SHA-256 hex string (64 characters)
- **Stored as**: `verification_hash` on the invoice record

### Verification

```php
public function verifyInvoice(string $uuid): ?array
{
    $invoice = Invoice::where('uuid', $uuid)->with(['order', 'user'])->first();
    if (!$invoice) return null;

    $expectedHash = $this->computeVerificationHash($invoice->snapshot_hash);
    $authentic = hash_equals($expectedHash, $invoice->verification_hash ?? '');

    return [
        'authentic' => $authentic,
        'invoice' => $authentic ? $invoice : null,
        'tampered' => !$authentic,
    ];
}
```

### Security Properties

- Uses `hash_equals()` — timing-attack-safe comparison
- `app.key` is the secret — if the key is compromised, verification can be forged
- Snapshot hash ensures data integrity — any modification to `data` changes the hash
- Verification hash is computed at generation time and stored; it is NOT recomputed from data

### What Verification Proves

1. The invoice data has not been modified since generation (snapshot hash matches)
2. The verification hash was generated by the system (requires knowledge of `app.key`)
3. The invoice is authentic and originated from this platform

### verify_count Increment

Each successful verification increments `verify_count` and updates `last_verified_at`. First verification also sets `verified_at`.

---

## Download Flow

Source: `InvoiceController::download()` (line 163)

### Checks

1. **Authorization**: User must own the invoice OR have `Permission::VIEW_INVOICE`
2. **PDF exists**: `$invoice->pdf_path` must be non-null
3. **Status check**: Not explicitly checked, but if `pdf_path` is null returns "PDF not yet generated"

### Flow

```
download(uuid)
  ├─ Authorization check (owner or permission)
  ├─ If !pdf_path → return 404 "PDF not yet generated"
  ├─ Set downloaded_at (first time only)
  ├─ Timeline: recordDownloaded(invoice)
  └─ Return: { url, invoice_number }
```

### Download URL

```php
'url' => url('storage/invoices/' . $invoice->pdf_path)
```

This is a public URL to the storage symlink (`public/storage/invoices/...`).

---

## Event/Listener Wiring

Source: `app/Providers/EventServiceProvider.php`

### Event Map

| Event | Listeners | Sync/Async | Queue | Tries |
|---|---|---|---|---|
| `PaymentSucceeded` (Marvel) | `GenerateInvoiceListener` | Async (ShouldQueue) | `high` | 5 |
| `InvoiceCreated` (App) | `LogInvoiceCreated` | Sync | — | — |

### PaymentSucceeded → GenerateInvoiceListener

```php
class GenerateInvoiceListener implements ShouldQueue
{
    public $afterCommit = true;
    public $queue = 'high';
    public $tries = 5;
    public $backoff = [10, 30, 60, 120, 300];

    public function handle(PaymentSucceeded $event): void
    {
        $order = $event->order;
        $this->invoiceService->generateFromOrder($order);
    }
}
```

- Dispatched after DB commit (`$afterCommit = true`)
- 5 retries with exponential backoff: 10s, 30s, 60s, 120s, 300s
- On failure: logs error, reports via `report()`, re-throws for queue retry

### InvoiceCreated → LogInvoiceCreated

```php
class LogInvoiceCreated
{
    public function handle(InvoiceCreated $event): void
    {
        Log::info('Invoice created', [
            'invoice_id' => $event->invoice->id,
            'invoice_number' => $event->invoice->invoice_number,
            'order_id' => $event->invoice->order_id,
            'total' => $event->invoice->total,
        ]);
    }
}
```

- Synchronous listener — runs in same process as dispatch
- Merely logs; no side effects

### DB::afterCommit Chain

Inside `generateFromOrder()`, after the transaction commits:

```php
DB::afterCommit(function () use ($invoice) {
    InvoiceCreated::dispatch($invoice);        // sync listener logs it
    GenerateInvoicePdfJob::dispatch($invoice);  // on queue 'low'
});
```

Both dispatch and job dispatch happen after the DB transaction commits, so if the transaction rolls back, neither fires.

---

## Database Schema

### `invoices` Table

| Column | Type | Description |
|---|---|---|
| `id` | bigint (PK) | Auto-increment |
| `uuid` | uuid | Public identifier (route key) |
| `order_id` | bigint (FK) | References `orders.id` |
| `transaction_id` | bigint (FK, nullable) | References `transactions.id` |
| `user_id` | bigint (FK) | References `users.id` |
| `correction_to_id` | bigint (FK, nullable) | References `invoices.id` |
| `invoice_number` | string | e.g. `INV-2026-000042` |
| `invoice_series` | string | `INV` |
| `sequence_number` | int | Per-year sequence |
| `sequence_year` | int | Year |
| `subtotal` | decimal | Order subtotal |
| `shipping_price` | decimal | Shipping cost |
| `coupon_discount` | decimal | Coupon discount amount |
| `promotion_discount` | decimal | Promotion discount amount |
| `total_discount` | decimal | Sum of all discounts |
| `total` | decimal | Final total |
| `amount_paid` | decimal | Amount paid (usually = total) |
| `currency` | string | e.g. `EGP` |
| `payment_method` | string | e.g. `credit_card` |
| `payment_gateway` | string | e.g. `myfatoorah` |
| `status` | string | InvoiceStatus value |
| `data` | json | Full snapshot |
| `snapshot_hash` | string | SHA-256 of sorted snapshot JSON |
| `verification_hash` | string | HMAC: sha256(snapshot_hash . app_key) |
| `pdf_path` | string (nullable) | Relative path to generated PDF |
| `pdf_checksum` | string (nullable) | SHA-256 of PDF file |
| `pdf_generated_at` | timestamp (nullable) | When PDF was first generated |
| `pdf_regenerated_at` | timestamp (nullable) | When PDF was regenerated |
| `generation_attempts` | int (default: 0) | Number of generation attempts |
| `last_generation_error` | text (nullable) | Last error message |
| `is_correction` | bool (default: false) | Whether this is a correction invoice |
| `correction_reason` | text (nullable) | Why correction was issued |
| `corrected_at` | timestamp (nullable) | When corrected |
| `cancelled_at` | timestamp (nullable) | When cancelled |
| `cancellation_reason` | text (nullable) | Why cancelled |
| `generated_at` | timestamp | Generation timestamp |
| `generated_by` | string | `system` or `admin:{id}` |
| `verified_at` | timestamp (nullable) | First verification |
| `last_verified_at` | timestamp (nullable) | Most recent verification |
| `verify_count` | int (default: 0) | Number of verifications |
| `downloaded_at` | timestamp (nullable) | First download |
| `printed_at` | timestamp (nullable) | First print |
| `archived_at` | timestamp (nullable) | When archived |

### `invoice_sequences` Table

| Column | Type | Description |
|---|---|---|
| `series` | string (PK) | Series prefix (INV, CN, DN) |
| `sequence_year` | int | Year scope |
| `last_sequence` | int | Last used sequence number |

### `invoice_timeline` Table

| Column | Type | Description |
|---|---|---|
| `id` | bigint (PK) | Auto-increment |
| `invoice_id` | bigint (FK) | References `invoices.id` |
| `event` | string | Event name |
| `old_status` | string (nullable) | Previous status |
| `new_status` | string (nullable) | New status |
| `actor_type` | string (nullable) | Polymorphic model type |
| `actor_id` | bigint (nullable) | Polymorphic model ID |
| `metadata` | json (nullable) | Event metadata |
| `ip_address` | string (nullable) | Request IP |
| `created_at` | timestamp | Created |

### `credit_notes` Table

| Column | Type | Description |
|---|---|---|
| `id` | bigint (PK) | Auto-increment |
| `invoice_id` | bigint (FK) | References `invoices.id` |
| `credit_note_number` | string | e.g. `CN-2026-000007` |
| `credit_note_series` | string | `CN` |
| `sequence_number` | int | Per-year sequence |
| `sequence_year` | int | Year |
| `type` | string | `refund` or `cancellation` |
| `reason` | text | Explanation |
| `amount` | decimal | Credit amount |
| `currency` | string | Currency code |
| `refund_transaction_id` | bigint (nullable) | Related refund transaction |
| `created_by` | bigint (nullable) | Admin user ID |
| `line_items` | json | Invoice items snapshot |
| `notes` | text | Additional notes |
| `issued_at` | timestamp | Issuance timestamp |

### `debit_notes` Table

| Column | Type | Description |
|---|---|---|
| `id` | bigint (PK) | Auto-increment |
| `invoice_id` | bigint (FK) | References `invoices.id` |
| `debit_note_number` | string | e.g. `DN-2026-000003` |
| `debit_note_series` | string | `DN` |
| `sequence_number` | int | Per-year sequence |
| `sequence_year` | int | Year |
| `type` | string | `correction` |
| `reason` | text | Explanation |
| `amount` | decimal | Debit amount |
| `currency` | string | Currency code |
| `created_by` | bigint (nullable) | Admin user ID |
| `line_items` | json | Invoice items snapshot |
| `notes` | text | Additional notes |
| `issued_at` | timestamp | Issuance timestamp |

---

## Edge Cases & Failure Modes

### 1. Duplicate Invoice Generation (Idempotency)

**Problem**: Concurrent requests for the same order could create duplicate invoices.

**Mitigation**: `lockForUpdate()` on the `order_id` check inside a transaction. Only the first request proceeds to create; subsequent requests see the existing record and return it.

**Failure**: If the lock is not applied (e.g., raw `Invoice::first()` without lock), duplicates can occur.

### 2. PDF Generation Failure

**Problem**: PDF generation is a placeholder. No real PDF is created.

**Impact**: `ready` status is set without an actual PDF. The `pdf_path` remains null indefinitely.

**Verification**: Run `php artisan tinker` and check:
```php
Invoice::whereNotNull('pdf_path')->count();
Invoice::where('status', 'ready')->whereNull('pdf_path')->count();
```

### 3. Status Transition Bypass

**Problem**: Bulk `update()` bypasses the `saving` hook:
```php
Invoice::where('id', $id)->update(['status' => 'archived']); // bypasses validation!
```

**Mitigation**: Never use bulk updates for status changes. Always use Eloquent `save()`.

### 4. Sequence Gap (Rollback)

**Problem**: If `generateNext()` increments the sequence but the invoice creation fails, the sequence number is consumed (gap).

**Why**: The sequence increment and invoice creation happen in the same transaction. If the transaction rolls back, the sequence increment also rolls back. **However**, `increment()` on a fresh model instance does NOT participate in the outer transaction in all cases — it issues `UPDATE invoice_sequences SET last_sequence = last_sequence + 1` which is auto-committed in MySQL with MyISAM. With InnoDB, it participates in the transaction.

**Recommendation**: Verify that `invoice_sequences` uses InnoDB. If it uses MyISAM, gaps WILL occur.

### 5. Verification Hash Mismatch After Key Rotation

**Problem**: If `app.key` changes, all stored `verification_hash` values become invalid.

**Impact**: All existing invoices will fail verification.

**Mitigation**: Never rotate `app.key` without a migration to recompute verification hashes, OR keep the old key in a separate config for legacy verification.

### 6. Correction Race Condition

**Problem**: Two admins correct the same invoice simultaneously.

**Mitigation**: `lockForUpdate()` on the original invoice prevents concurrent corrections. The second correction will wait for the first to complete, then see status `corrected` and fail validation.

### 7. Cancellation Race Condition

**Problem**: Admin cancels invoice while PDF is being generated.

**Mitigation**: The cancellation `lockForUpdate()` will wait for the PDF generation transaction to finish. After cancellation, the PDF job's update will fail the transition check. However, there is a TOCTOU window between the job reading the invoice and updating it.

### 8. Timeline Table Bloat

**Problem**: Active invoices with many verifications/downloads accumulate timeline entries.

**Mitigation**: The `InvoiceResource` only loads the latest 10 timeline entries. For long-lived invoices, consider archiving old entries or adding a retention policy.

### 9. Missing InvoiceSeries Index

**Potential Issue**: The `invoice_sequences` table uses `series` as primary key (non-incrementing string). This is fine for the current small dataset but may need a composite index on `(series, sequence_year)` for performance.

### 10. Correction without Following

**Potential Issue**: When an invoice is corrected, the new invoice is set to `generated` status. No `GenerateInvoicePdfJob` is dispatched for the correction. The correction PDF must be generated manually or via a separate trigger.

---

## Key Files Reference

| File | Purpose |
|---|---|
| `app/Enums/InvoiceStatus.php` | 12-state enum with transition matrix |
| `app/Models/Invoice.php` | Eloquent model with transition validation hook |
| `app/Models/InvoiceTimeline.php` | Timeline entry model |
| `app/Models/InvoiceSequence.php` | Gapless sequence model |
| `app/Models/CreditNote.php` | Credit note model |
| `app/Models/DebitNote.php` | Debit note model |
| `app/Services/Invoice/InvoiceService.php` | Core invoice orchestration |
| `app/Services/Invoice/InvoiceSnapshotService.php` | Snapshot builder |
| `app/Services/Invoice/InvoiceSnapshotValidator.php` | Validator pipeline |
| `app/Services/Invoice/Validators/*.php` | 6 validators |
| `app/Services/Invoice/SnapshotIntegrityService.php` | Hash computation |
| `app/Services/Invoice/InvoiceNumberService.php` | Gapless number generation |
| `app/Services/Invoice/InvoiceTimelineService.php` | Audit log service |
| `app/Services/Invoice/CreditNoteService.php` | Credit note issuance |
| `app/Services/Invoice/DebitNoteService.php` | Debit note issuance |
| `app/Jobs/GenerateInvoicePdfJob.php` | PDF generation (PLACEHOLDER) |
| `app/Events/InvoiceCreated.php` | Event fired after invoice creation |
| `app/Listeners/LogInvoiceCreated.php` | Sync logger listener |
| `app/Listeners/GenerateInvoiceListener.php` | Queue listener on PaymentSucceeded |
| `app/Http/Controllers/Api/InvoiceController.php` | REST controller |
| `app/Http/Resources/Invoice/InvoiceResource.php` | API resource transformer |
| `routes/api.php` | Route definitions |
