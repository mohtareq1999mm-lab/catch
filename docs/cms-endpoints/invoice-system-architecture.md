# ADR-003: Invoice System Architecture Decision — Version 2

## Metadata

- Decision ID: ADR-003
- Architecture Area: Invoicing
- Status: Accepted
- Decision State: Frozen
- Production Status: Approved

---

## Table of Contents

1. [Revised Transaction Boundaries](#1-revised-transaction-boundaries)
2. [Invoice Lifecycle](#2-invoice-lifecycle)
3. [Structured Columns vs JSON Snapshot](#3-structured-columns-vs-json-snapshot)
   - 3.5 [Snapshot Versioning](#35-snapshot-versioning)
   - 3.7 [Immutable Snapshot Contract](#37-immutable-snapshot-contract)
   - 3.8 [Invoice Data Integrity](#38-invoice-data-integrity)
   - 3.9 [Financial Invariants](#39-financial-invariants)
4. [Invoice Number Generation](#4-invoice-number-generation)
5. [Exactly-Once Processing](#5-exactly-once-processing)
6. [Failure Recovery](#6-failure-recovery)
7. [Invoice Aggregate Boundaries](#7-invoice-aggregate-boundaries)
   - 7.4 [Read-Only Consumer](#74-the-read-only-consumer-principle)
   - 7.5 [Invoice Data Sources](#75-invoice-data-sources)
   - 7.6 [PDF Source of Truth](#76-pdf-source-of-truth)
   - 7.7 [Snapshot Validation Pipeline](#77-snapshot-validation-pipeline)
8. [Audit Trail](#8-audit-timeline)
9. [API Design](#9-api-design)
10. [Event Flow and Lifecycle Diagram](#10-event-flow-and-lifecycle-diagram)
11. [Database Design](#11-database-design)
12. [Long-Term Scalability](#12-long-term-scalability)
13. [Architecture Compliance](#13-architecture-compliance)
14. [Files Inventory](#14-files-inventory)
15. [Observability and Operations](#15-observability-and-operations)
16. [Architecture Freeze](#16-architecture-freeze)
17. [AI Agent Guidelines](#17-ai-agent-guidelines)
18. [Legal and Compliance Guarantees](#18-legal-and-compliance-guarantees)

### Appendices

A. [Snapshot Completeness Checklist](#appendix-a-snapshot-completeness-checklist)

---

## 1. Revised Transaction Boundaries

### 1.1 Problem With V1 Design

The V1 proposal recommended writing invoice data **synchronously inside the same database transaction** that marks payment as completed. The stated rationale was atomicity: if the invoice write fails, the payment transaction rolls back.

This is **incorrect for production systems** for the following reasons:

| Concern | Impact |
|---------|--------|
| **Incorrect semantics** | An invoice is a downstream artifact of a successful payment, not part of the payment itself. If invoice persistence fails, the customer has still paid successfully. Rolling back payment because of an invoice failure means the customer pays again or loses their order. |
| **Tight coupling** | Payment completion depends on invoice system availability. If the invoices table has a schema issue, a storage failure, or a transient deadlock, the payment is also lost. |
| **Extended transaction duration** | Snapshot data assembly (loading relations, building JSON) increases transaction time, holding locks on `orders` and `transactions` rows longer than necessary. |
| **Inconsistent with existing code** | The `checkoutCallback()` flow already dispatches `PaymentSucceeded` **outside** the transaction (line 296 of `OrderController`). Only `markCodAsPaid` and `markCashierPaid` dispatch inside the transaction. Making invoice generation synchronous would require restructuring all three paths differently, increasing complexity. |

### 1.2 Revised Design: DB::afterCommit() + Queued Listener

**Principle: A successful payment must never be rolled back because of a downstream invoice generation failure.**

```
┌─────────────────────────────────────────────────────┐
│                Payment Transaction                   │
│                                                      │
│  1. Lock transaction row (lockForUpdate)             │
│  2. Update Transaction → status=paid, paid_at=now()  │
│  3. Update Order → status=completed                  │
│  4. Record coupon usage                              │
│  5. Commit transaction                               │
│                                                      │
│  After commit:                                       │
│  ┌─────────────────────────────────────────────┐     │
│  │  DB::afterCommit()                          │     │
│  │    dispatch(PaymentSucceeded) → queue        │     │
│  └─────────────────────────────────────────────┘     │
└─────────────────────────────────────────────────────┘
                           │
                           v              ┌─── Queue Worker
                    PaymentSucceeded ─────┤
                           │              └─── Queue Worker
                           v
              GenerateInvoiceListener [ShouldQueue]
                           │
                           v
              Invoice record created (own transaction)
```

### 1.3 Changes Required in Existing Code

**Three dispatch points currently exist:**

| Location | Current Behavior | Required Change |
|----------|-----------------|-----------------|
| `OrderService::markCodAsPaid()` line 487 | Dispatches `PaymentSucceeded` **inside** `DB::transaction()` | Move event dispatch inside `DB::afterCommit()` |
| `OrderService::markCashierPaid()` line 514 | Dispatches `PaymentSucceeded` **inside** `DB::transaction()` | Move event dispatch inside `DB::afterCommit()` |
| `OrderController::checkoutCallback()` line 296 | Dispatches `PaymentSucceeded` **after** `changeOrderStatus()` returns (outside transaction, in try/catch that swallows errors) | The `changeOrderStatus()` already commits before this point. Move dispatch inside `DB::afterCommit()` inside `changeOrderStatus()` |

**Implementation Plan:**

**Step 1 — Centralize dispatch in `changeOrderStatus()`:**

Modify `OrderService::changeOrderStatus()` to dispatch `PaymentSucceeded` via `DB::afterCommit()` when status changes to `completed`. Add the dispatch after the existing `OrderStatusChanged` event, inside the same transaction block but dispatched after commit:

```php
// Inside changeOrderStatus(), after event(new OrderStatusChanged($order)):
if ($status === 'completed') {
    DB::afterCommit(function () use ($order) {
        event(new PaymentSucceeded($order));
    });
}
```

**Step 2 — Remove duplicate dispatches:**

| Location | Lines | Change |
|----------|-------|--------|
| `OrderService::markCodAsPaid()` | 487 | Remove `event(new PaymentSucceeded($order))` |
| `OrderService::markCashierPaid()` | 514 | Remove `event(new PaymentSucceeded($order))` |
| `OrderController::checkoutCallback()` | 294-300 | Remove entire try/catch block that dispatches `PaymentSucceeded` |

**Step 3 — Verify all payment paths converge through `changeOrderStatus()`:**

| Path | Currently Calls `changeOrderStatus()`? | Will Dispatch `PaymentSucceeded`? |
|------|----------------------------------------|-----------------------------------|
| `checkoutCallback()` (online) | ✅ Yes, line 291 with `status=completed` | ✅ Yes — via `changeOrderStatus()` |
| `markCodAsPaid()` | ❌ No — does its own transaction | ⚠️ Must be refactored to call `changeOrderStatus()` OR manually add `DB::afterCommit()` |
| `markCashierPaid()` | ❌ No — does its own transaction | ⚠️ Must be refactored to call `changeOrderStatus()` OR manually add `DB::afterCommit()` |

**Important**: Both `markCodAsPaid()` and `markCashierPaid()` perform their own `DB::transaction()` without calling `changeOrderStatus()`. There are two options:

**Option A (Recommended — Full Centralization):** Refactor `markCodAsPaid()` and `markCashierPaid()` to call `changeOrderStatus(null, 'completed', $order->id)` with the order ID instead of duplicating the transaction logic. This ensures `PaymentSucceeded` is always dispatched from a single point.

**Option B (Minimal Change):** Add `DB::afterCommit()` wrapping around the `event(new PaymentSucceeded(...))` call in both `markCodAsPaid()` and `markCashierPaid()` without refactoring them to use `changeOrderStatus()`. This resolves the "dispatch inside transaction" bug but keeps the dispatch in three places.

**Verdict: Option A is preferred** for long-term maintainability. However, Option B is acceptable as a first iteration since it does not change the control flow of `markCodAsPaid()` and `markCashierPaid()` — it only wraps the event dispatch.

This centralization uses the existing `DB::afterCommit()` pattern already proven in `recordCouponUsage()` for `AssignedCouponConsumed` (line 576).

### 1.4 Trade-Off Analysis

| Approach | V1 (Synchronous in Transaction) | V2 (afterCommit + Queue) |
|----------|-------------------------------|--------------------------|
| Payment safety | ❌ Invoice failure rolls back payment | ✅ Payment always persists |
| Invoice timeliness | ✅ Immediate | ⚠️ Brief delay (queue latency) |
| Transaction duration | ❌ Longer (snapshot assembly) | ✅ Short (payment only) |
| Coupling | ❌ Payment depends on invoice | ✅ Fully decoupled |
| Idempotency needed | ❌ Not needed (transactional) | ✅ Required (queue retries) |
| Complexity | Lower | Slightly higher |
| Existing code alignment | ❌ Inconsistent with callback flow | ✅ Aligns with existing afterCommit pattern |

**Verdict: V2 (afterCommit + Queue) is the production-safe approach.**

---

## 2. Invoice Lifecycle

### 2.1 State Machine

```
                  ┌─────────────────────────────────────┐
                  │        PaymentSucceeded fires        │
                  └─────────────────────────────────────┘
                              │
                              v
                    ┌─────────────────┐
                    │    Pending      │── Invoice record creation started
                    └─────────────────┘
                              │
                      (snapshot collected,
                       record inserted)
                              │
                              v
                    ┌─────────────────┐
                    │   Generated     │── Snapshot written, PDF job dispatched
                    └─────────────────┘
                              │
                      (PDF job starts)
                              │
                              v
                    ┌─────────────────┐
                    │  PDF Generating  │── Job is processing
                    └─────────────────┘
                         /        \
                    Success        Failure (retry)
                       │                │
                       v                v
                ┌──────────┐    ┌────────────────┐
                │  Ready   │    │    Failed      │── Max retries exhausted
                └──────────┘    └────────────────┘
                       │                │
                  (admin corrects)  (admin regenerates)
                       │                │
                       v                v
                ┌──────────┐    ┌────────────────┐
                │Corrected │    │  PDF Pending   │── Manual retry dispatched
                └──────────┘    └────────────────┘
                       │
                  (if full refund/void)
                       │
                       v
                ┌──────────┐
                │Cancelled │── Invoice voided (not deleted)
                └──────────┘
```

### 2.2 State Definitions and Transitions

| Status | Definition | Entry Condition | Exit Condition |
|--------|-----------|-----------------|----------------|
| `pending` | Invoice record creation has started but snapshot assembly is in progress. Transient state — visible only if queue worker fails mid-processing. | Listener starts processing | Snapshot assembled and record inserted |
| `generated` | Invoice record exists in database with full snapshot JSON and structured columns. PDF generation has been dispatched to queue. | `Invoice::create()` succeeds | PDF job starts processing |
| `pdf_generating` | PDF job is actively processing. Prevents duplicate PDF jobs for the same invoice. | `GenerateInvoicePdfJob` starts | Job completes or fails |
| `ready` | PDF has been generated, stored, and checksum verified. Invoice is fully available for download. | `document_path` and `pdf_generated_at` are set after successful PDF generation | Admin creates correction (original becomes `corrected`) |
| `failed` | PDF generation exhausted all retry attempts. Invoice data exists but no PDF. Requires manual intervention. | Job reaches max attempts without success | Admin triggers regenerate |
| `corrected` | This invoice has been superseded by a correction invoice. The original invoice record remains for audit purposes. The correction invoice references it via `correction_to_id`. | Correction invoice is created referencing this invoice as original | None (terminal audit state) |
| `cancelled` | Invoice has been voided (e.g., full refund before invoice generation, or administrative void). Not deleted — preserved for audit trail. | Admin cancels the invoice | None (terminal state) |

### 2.3 State Transition Rules

1. **Forward transitions only** — Once an invoice reaches `generated`, its status never moves backward except via correction (which creates a new record).
2. **`generated` → `pdf_generating`** — The PDF job uses `lockForUpdate()` on the invoice row to prevent concurrent PDF generation.
3. **`failed` → `ready`** — Admin triggers regeneration; the job re-runs and updates status to `ready` on success.
4. **`ready` → `corrected`** — Only via admin creating a correction invoice. Original invoice's status changes to `corrected`.
5. **`cancelled` is terminal** — A cancelled invoice cannot transition to any other state (not even `corrected`). If a cancelled order was later found to be paid, a new invoice would be created (if allowed) or a correction would be created referencing the cancelled invoice.

---

## 3. Structured Columns vs JSON Snapshot

### 3.1 Rationale for Hybrid Model

V1 stored everything inside a single JSON `data` column. This is insufficient for production because:

| Limitation | Impact |
|-----------|--------|
| No indexed filtering | Admin searches for invoices by date range, status, or amount require full table scans |
| No foreign key enforcement | `order_id`, `user_id`, `transaction_id` must be real FK columns for referential integrity |
| Reporting/exporting | Monthly revenue reports, per-customer summaries, payment gateway reconciliation all benefit from structured, indexed columns |
| ORM integration | Eloquent relationships (`user()->invoices()`, `order->invoice`) require FK columns |

### 3.2 Structured Columns (Searchable, Filterable, Reportable)

These columns live on the `invoices` table as first-class database columns with proper types and indexes:

| Column | Type | Purpose | Index |
|--------|------|---------|-------|
| `id` | BIGINT UNSIGNED PK | Primary identifier | PRIMARY |
| `order_id` | BIGINT UNSIGNED FK | Links to orders table (1:1 relationship) | UNIQUE |
| `transaction_id` | BIGINT UNSIGNED FK NULLABLE | Links to the payment transaction | INDEX |
| `user_id` | BIGINT UNSIGNED FK | Customer who owns this invoice | INDEX |
| `invoice_number` | VARCHAR(50) | Human-readable unique invoice identifier | UNIQUE |
| `invoice_series` | VARCHAR(10) | Series prefix (e.g., INV, CN, DN) | — |
| `sequence_number` | BIGINT UNSIGNED | Sequential number within series+year | INDEX |
| `sequence_year` | YEAR | Year for number partitioning | INDEX |
| `subtotal` | DECIMAL(10,3) | Sum of line item prices before discounts | — |
| `shipping_price` | DECIMAL(10,3) | Shipping cost at time of purchase | — |
| `coupon_discount` | DECIMAL(10,3) | Total coupon discount applied | — |
| `promotion_discount` | DECIMAL(10,3) | Total promotion discount applied | — |
| `total_discount` | DECIMAL(10,3) | Combined discount (coupon + promotion) | — |
| `total` | DECIMAL(10,3) | Grand total paid by customer | INDEX |
| `amount_paid` | DECIMAL(10,3) | Actual amount received (may differ from total for partial payments) | — |
| `currency` | VARCHAR(3) | ISO 4217 currency code (e.g., EGP, KWD) | INDEX |
| `payment_method` | VARCHAR(30) | cod, online, pay_at_cashier, wallet | INDEX |
| `payment_gateway` | VARCHAR(50) NULLABLE | myfatoorah, etc. | INDEX |
| `status` | VARCHAR(20) | Current lifecycle status (see section 2) | INDEX |
| `generated_at` | TIMESTAMP | When the invoice record was created | INDEX |

### 3.3 JSON Snapshot (Immutable Business Data)

The `data` JSON column stores everything that is **not needed for indexed queries** but is **required for the immutable historical record**:

| Category | Fields in Snapshot | Why Not Structured |
|----------|-------------------|-------------------|
| Customer | name, email, phone | Customer data can change; snapshot must preserve historical values. Not queried at scale. `company_name` and `tax_id` are NOT available in V1 — see §3.10. |
| Addresses | billing_address (full), shipping_address (full) | Complex nested objects. Queried only when viewing a specific invoice. |
| Line items | product_name, SKU, attributes, quantity, pricing per item, images, is_gift, promotion_id | Variable number of items per invoice. MySQL JSON allows variable-length arrays naturally. |
| Pricing breakdown | Per-item breakdown, coupon details at time of use, promotion details at time of use, flash sale details | Complex nested structure. Only needed for invoice display, not for aggregation. |
| Payment details | gateway_transaction_id, gateway_response summary, paid_at, gateway_invoice_id | Mostly free-text or nested data from external systems. |
| Fulfillment | fulfillment_type, shipping_method, expected_delivery_at, pickup_location details | Variable structure depending on fulfillment_type. `tracking_number` is NOT available in V1 — see §3.10. |
| Metadata | system_version, locale, ip_address, user_agent, notes | Free-form, not suitable for structured columns. |

### 3.4 Rule for Placement

**Always start with a structured column if the field meets ANY of these criteria:**

1. Needed in `WHERE`, `ORDER BY`, or `GROUP BY` clauses
2. Needed for foreign key relationships
3. Needed for reporting/aggregation
4. Needed for unique constraints

**Always use JSON snapshot if the field meets ALL of these criteria:**

1. Read only when viewing a specific invoice
2. Complex or variable structure
3. Immutable historical record only (no live queries)

### 3.5 Snapshot Versioning

Every invoice snapshot must carry version metadata to support future schema evolution without breaking invoices generated years earlier.

#### 3.5.1 Version Fields in Snapshot JSON

```json
{
  "snapshot_version": "2.0.0",
  "snapshot_schema": 2,
  "data": { ... all business data ... },
  "metadata": {
    "system_version": "1.0.0",
    ...
  }
}
```

| Field | Location | Type | Purpose |
|-------|----------|------|---------|
| `snapshot_version` | Root of `data` JSON | SemVer string (`major.minor.patch`) | Human-readable version identifier. Used during support to determine which schema version generated a given invoice. |
| `snapshot_schema` | Root of `data` JSON | Integer | Monotonically incrementing schema version number. Used programmatically — the PDF template and snapshot service check this field to determine how to read the data. |
| `metadata.system_version` | `data.metadata` | SemVer string | The application version that generated this invoice. Useful for debugging: "which deployment generated this broken snapshot?" |

#### 3.5.2 Version Evolution Rules

| Change Type | Increment | Backward Compatible? | Example |
|-------------|-----------|---------------------|---------|
| Adding a new optional field to an existing section | Minor | ✅ Yes — old readers ignore unknown keys | Adding `customer.tax_id` (future V2 field — see §3.10) |
| Adding a new required section | Minor | ⚠️ Yes — snapshot_service writes it; PDF reader handles missing via defaults | Adding `taxes[]` to an invoice that had no taxes |
| Renaming an existing field | Major | ❌ No — breaks backward compatibility. Never rename. Deprecate old + add new. | Renaming `items` to `line_items` |
| Removing an existing field | Major | ❌ No — breaks backward compatibility. Never remove without deprecation period. | Removing `pricing_breakdown.coupon` |
| Changing data type | Major | ❌ No — old readers interpret data differently | Changing `unit_price` from number to string |
| Structural reorganization | Major | ❌ No — requires coordinated migration | Moving `items` under `order.items` |

#### 3.5.3 How Versioning Works at Runtime

```
Snapshot built (snapshot_schema = 2)
  │
  ├── Stored in invoices.data JSON
  │
  └── PDF template reads $data->snapshot_schema
        │
        ├── == 2: Render with modern layout
        └── == 1: Render with legacy layout (backward compat)
```

When `snapshot_schema` increments (e.g., to 3):
1. `InvoiceSnapshotService` starts writing version 3 for new invoices.
2. `InvoicePdfService` and PDF template handle both version 2 and version 3.
3. Old invoices (version 2) are rendered with the legacy code path.
4. After all old invoices are archived (5+ years), version 2 support can be removed.

#### 3.5.4 Example: Adding Tax Support Across Versions

```php
// Version 2 → 3 migration: adding tax breakdown
if ($data->snapshot_schema >= 3) {
    $taxAmount = $data->taxes[0]->amount ?? 0;
    $taxRate = $data->taxes[0]->rate ?? 0;
} else {
    // No tax data in old invoices — safe defaults
    $taxAmount = 0;
    $taxRate = 0;
}
```

This pattern allows the PDF template to handle both pre-tax and post-tax invoices without breaking.

### 3.6 Migration From All-JSON to Hybrid

If V1 was deployed with an all-JSON approach, the migration to hybrid is additive:

```sql
ALTER TABLE invoices
    ADD COLUMN subtotal DECIMAL(10,3) GENERATED ALWAYS AS (JSON_UNQUOTE(JSON_EXTRACT(data, '$.pricing_breakdown.subtotal'))) STORED,
    ADD COLUMN status VARCHAR(20) GENERATED ALWAYS AS (JSON_UNQUOTE(JSON_EXTRACT(data, '$.status'))) STORED,
    ADD INDEX invoices_status_index (status);
```

MySQL 8.0+ generated columns can extract values from JSON without application changes. However, the V2 design builds these columns directly.

### 3.7 Immutable Snapshot Contract

The snapshot JSON stored in `invoices.data` is governed by an immutable contract:

#### 3.7.1 The Contract

> Once a snapshot field is written to the `data` column, it must remain readable by any future version of the application for the entire lifetime of the invoice (minimum 10 years).

This contract is enforced by the following rules:

| Rule | Description | Violation Example |
|------|-------------|-------------------|
| **Append-only** | New fields may only be added. Existing fields must never be removed. | Removing `pricing_breakdown.coupon` because "we no longer use that field" |
| **No renaming** | Existing keys must never be renamed. If a better name is needed, add the new key alongside the old one. | Renaming `items` to `line_items` |
| **No type changes** | The data type of an existing field must never change. If a new type is needed, add a new field with a different name. | Changing `unit_price` from `number` to `string` |
| **No semantic changes** | The meaning of an existing field must never change. If the business meaning changes, add a new field. | Changing `total` from "amount paid by customer" to "amount before shipping" |
| **Deprecation** | Deprecated fields remain in the snapshot until all supported schema versions are retired (minimum 5 years). They are preserved but marked with `_deprecated` suffix in documentation. | Removing `promotion_discount` because a new `discounts[]` array replaced it |
| **Null is acceptable** | To deprecate a field, stop populating it (set to `null`) but keep the key in the JSON structure. | `"promotion_discount": null` instead of removing the key |

#### 3.7.2 What Happens When the Contract Is Broken

| Broken Rule | Consequence |
|-------------|-------------|
| Field removed | Old invoices cause PHP notice (`Undefined index`) when PDF template tries to render. PDF generation fails. Manual fix required. |
| Field renamed | Old invoices render with missing data. New invoices render with new key. Two code paths needed forever. |
| Type changed | Old invoices and new invoices require different handling in PDF template. Conditional logic proliferates. |

#### 3.7.3 Practical Example: Adding Multiple Discounts Support

```php
// Version 2 (current): single discount fields
$snapshot = [
    'pricing_breakdown' => [
        'coupon_discount' => 50.00,
        'promotion_discount' => 100.00,
        // _deprecated fields above — kept for backward compat
        // New unified structure:
        'discounts' => [
            ['type' => 'coupon', 'code' => 'SAVE10', 'amount' => 50.00],
            ['type' => 'promotion', 'code' => 'SUMMER20', 'amount' => 100.00],
        ],
    ],
];
```

Old fields (`coupon_discount`, `promotion_discount`) remain for backward compatibility. New field (`discounts[]`) is added. The PDF template checks `snapshot_schema` and reads from the appropriate location.

### 3.8 Invoice Data Integrity

Integrity verification protects against data corruption, tampering, and storage bit rot.

#### 3.8.1 Two Distinct Integrity Checks

| Check | Scope | What It Validates | Stored In |
|-------|-------|-------------------|-----------|
| `snapshot_hash` | Immutable financial record | SHA-256 of the canonical JSON in `data` column | `invoices.snapshot_hash` (new structured column) |
| `pdf_checksum` | Rendered document | SHA-256 of the generated PDF file | `invoices.pdf_checksum` |

**These are different and serve different purposes:**

- `snapshot_hash` validates the **financial data itself**. If a database bit flip or manual edit corrupts the JSON, the hash detects it.
- `pdf_checksum` validates the **rendered document**. If the PDF is accidentally truncated or a storage error corrupts it, the checksum detects it.

#### 3.8.2 Snapshot Hash Lifecycle

```
Generated:
  InvoiceSnapshotService builds JSON → canonical_encode() → SHA-256 → snapshot_hash
  Stored alongside the JSON in the invoices.data column (internal) AND invoices.snapshot_hash (structured column)

Verified:
  1. On read: PDF template reads data, recomputes hash, compares with stored hash
  2. Scheduled audit: invoices:audit recomputes hash for all invoices with snapshot_hash
  3. On regeneration: PDF job verifies snapshot_hash before generating PDF
  4. On archival: hash is verified before moving to cold storage

Tampered:
  snapshot_hash mismatch → Log critical alert → Automated integrity report
  Invoice is NOT served to customers until manually reviewed
```

#### 3.8.3 Canonical JSON Encoding

The hash is computed on a **canonical** representation of the JSON to ensure determinism:

```php
class SnapshotIntegrityService
{
    public function computeHash(array $data): string
    {
        // Canonical JSON encoding:
        // - Keys sorted alphabetically
        // - No whitespace
        // - Consistent number formatting (no trailing zeros)
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_SORT_KEYS);
        return hash('sha256', $json);
    }

    public function verify(array $data, string $expectedHash): bool
    {
        return hash_equals($expectedHash, $this->computeHash($data));
    }
}
```

**Why canonical JSON?**
- Standard `json_encode` output depends on PHP version and options.
- Keys must be sorted to ensure the same input produces the same hash.
- Number formatting must be consistent (e.g., `50.00` vs `50.0` vs `50`).

#### 3.8.4 What Integrity Verification Protects Against

| Threat | Detected By | Action |
|--------|-------------|--------|
| Database bit flip corrupts JSON | snapshot_hash mismatch | Alert → restore from backup or regenerate invoice |
| Manual SQL edit of data column | snapshot_hash mismatch | Alert → investigate unauthorized access |
| Storage corruption of PDF file | pdf_checksum mismatch | Regenerate PDF from snapshot |
| Man-in-the-middle PDF tampering during download | pdf_checksum mismatch (if verified on download) | Reject download, regenerate, alert |
| Accidental partial write (truncated JSON) | json_decode fails + snapshot_hash mismatch | Alert → restore from backup |
| Archival corruption | snapshot_hash mismatch (verified before archival) | Re-archive from primary data |

#### 3.8.5 Adding snapshot_hash to the Schema

```sql
ALTER TABLE invoices ADD COLUMN snapshot_hash VARCHAR(64) NULL AFTER data;
CREATE INDEX idx_invoices_snapshot_hash ON invoices (snapshot_hash);
```

The column is nullable to allow migration of existing invoices (they will get their hash computed by the first audit run).

### 3.9 Financial Invariants

Every invoice must satisfy a set of mathematical invariants before it is persisted. These invariants guarantee financial correctness.

#### 3.9.1 Core Invariant Formula

```
subtotal
- coupon_discount
- promotion_discount
+ shipping_price
+ tax_total (when implemented)
= total
```

In code:

```php
$expectedTotal = $subtotal
    - $couponDiscount
    - $promotionDiscount
    + $shippingPrice;

// Allow a 1-cent rounding tolerance for currency calculations
$tolerance = 0.01;

if (abs($expectedTotal - $total) > $tolerance) {
    throw new FinancialInvariantException(
        "Invoice total {$total} does not match computed total {$expectedTotal}. "
        . "Subtotal: {$subtotal}, Coupon: {$couponDiscount}, "
        . "Promotion: {$promotionDiscount}, Shipping: {$shippingPrice}"
    );
}
```

#### 3.9.2 Additional Invariants

| Invariant | Rule | Rationale |
|-----------|------|-----------|
| Non-negative amounts | All financial fields must be >= 0 | Negative prices or discounts indicate data corruption |
| Total ≥ subtotal | `total` >= `subtotal` (when shipping is zero and discounts are applied) | Verifies discounts don't exceed subtotal (unless store policy allows it) |
| Amount paid ≤ total | `amount_paid` <= `total` (for partial payments) | Customer cannot pay more than the invoice total (overpayments are separate, tracked via correction invoice) |
| One currency per invoice | `currency` must be identical for all monetary fields | Multi-currency invoices are not supported in V2 |
| Line item invariants | `total_price` >= `unit_price * quantity` - discount_range | Line item total must be consistent with quantity and discounts applied |
| Sequence invariants | `sequence_number` > 0 and unique per `series+year` | Invoice numbering must be monotonic |

#### 3.9.3 Invariant Validation Location

The invariants are validated in `InvoiceSnapshotValidator`, which is called by `GenerateInvoiceListener` **before** `Invoice::create()`:

```php
class GenerateInvoiceListener
{
    public function handle(PaymentSucceeded $event): void
    {
        $order = $event->order;

        // 1. Build snapshot
        $snapshot = app(InvoiceSnapshotService::class)->buildFullSnapshot($order);

        // 2. Validate invariants
        app(InvoiceSnapshotValidator::class)->validate($snapshot);

        // 3. Create invoice
        DB::transaction(function () use ($order, $snapshot) {
            // ...
        });
    }
}
```

#### 3.9.4 Invariant Violation Handling

| Severity | Condition | Action |
|----------|-----------|--------|
| **Blocking** | Core invariant formula violation (tolerance exceeded) | Exception thrown. Invoice is NOT created. Listener fails and retries. After max retries, moves to failed_jobs. Operations must investigate. |
| **Blocking** | Negative amount detected | Same as above. Indicates data corruption in the order. |
| **Warning** | Line item minor inconsistency (< tolerance) | Logged as warning. Invoice is created. Audit report flags it. |
| **Warning** | Amount paid < total | Allowed (partial payment). Logged as info for reporting purposes. |

### 3.10 V1 Snapshot Schema — Definitive Implementation Contract

This section defines the **exact field set** that must be implemented in V1. Every field listed here is guaranteed to exist in the database at the time of invoice generation. Fields marked as *Future Enhancement* are explicitly excluded from V1.

#### 3.10.1 Available Fields

All fields in Appendix A are available in V1 **except** the following three, which do not exist in the current database schema:

| Section | Field | Reason | V1 Status |
|---------|-------|--------|-----------|
| A.2 Customer | `company_name` | No column on `orders` or `users` table | ❌ Future Enhancement (requires users table migration) |
| A.2 Customer | `tax_id` | No column on `orders` or `users` table | ❌ Future Enhancement (requires users table migration) |
| A.4 Fulfillment | `tracking_number` | No column on `orders` table | ❌ Future Enhancement (requires orders table migration) |

#### 3.10.2 V1 Snapshot Assembly Contract

```php
class InvoiceSnapshotService
{
    /**
     * Build the V1 snapshot for a given order.
     *
     * @param Order $order (must have eager-loaded: orderItems, transactions, user, governorate)
     * @return array
     */
    public function buildFullSnapshot(Order $order): array
    {
        return [
            'snapshot_version' => '2.0.0',
            'snapshot_schema' => 2,

            // Customer (V1: no company_name, no tax_id)
            'customer' => [
                'id' => $order->user_id,
                'name' => $order->name,
                'email' => $order->user_email,
                'phone' => $order->user_phone,
            ],

            // Addresses
            'billing_address' => $this->resolveAddress($order),
            'shipping_address' => $this->resolveAddress($order),

            // Fulfillment (V1: no tracking_number)
            'fulfillment' => [
                'type' => $order->fulfillment_type,
                'shipping_method' => $order->shipping_method,
                'shipping_price' => (float) $order->shipping_price,
                'expected_delivery_at' => $order->expected_delivery_at?->toIso8601String(),
            ],

            // Pickup location (null if delivery)
            'pickup_location' => $order->fulfillment_type === 'pickup' ? [
                'id' => $order->pickup_location_id,
                'name' => $order->pickup_location_name,
                'address' => $order->pickup_location_address,
                'phone' => $order->pickup_location_phone,
                'coordinates' => $order->pickup_location_coordinates,
            ] : null,

            // Line items
            'items' => $order->orderItems->map(fn ($item) => [
                'product_id' => $item->product_id,
                'product_variant_id' => $item->product_variant_id,
                'product_name' => $item->product_name,
                'product_sku' => $item->product_sku,
                'attributes' => $item->attributes,
                'quantity' => (int) $item->product_quantity,
                'unit_price' => (float) $item->product_price,
                'total_price' => (float) $item->product_total_price,
                'original_price' => (float) $item->product_price,
                'discount_price' => $item->product_discount_price ? (float) $item->product_discount_price : null,
                'flash_sale_price' => $item->product_flash_sale_price ? (float) $item->product_flash_sale_price : null,
                'promotion_discount_amount' => $item->promotion_discount_amount ? (float) $item->promotion_discount_amount : null,
                'is_gift' => (bool) $item->is_gift,
                'promotion_id' => $item->promotion_id,
                'images' => [], // Populated from eager-loaded $item->product->media
            ])->toArray(),

            // Pricing breakdown
            'pricing_breakdown' => [
                'subtotal' => (float) $order->price,
                'promotion_discount' => (float) $order->promotion_discount,
                'coupon_discount' => (float) $order->coupon_discount,
                'shipping_price' => (float) $order->shipping_price,
                'total' => (float) $order->total_price,
                'currency' => $order->transactions->first()?->currency ?? 'EGP',
                'exchange_rate' => null, // V1: single currency
                'coupon' => $order->coupon ? [
                    'code' => $order->coupon,
                    'type' => $order->coupon_discount_type,
                    'discount' => (float) $order->coupon_discount,
                    'max_discount_amount' => $order->coupon_discount_max_amount ? (float) $order->coupon_discount_max_amount : null,
                ] : null,
                'promotion' => $order->promotion_id ? [
                    'id' => (int) $order->promotion_id,
                    'code' => $order->promotion_code,
                    'type' => $order->promotion_type,
                    'discount' => (float) $order->promotion_discount,
                ] : null,
            ],

            // Payment
            'payment' => [
                'method' => $order->payment_method,
                'gateway' => $order->payment_gateway,
                'transaction_id' => $order->transactions->first()?->id,
                'gateway_transaction_id' => $order->transactions->first()?->gateway_transaction_id,
                'paid_at' => $order->transactions->first()?->paid_at,
                'gateway_invoice_id' => null, // Extracted from gateway_response JSON if available
                'gateway_response_summary' => null, // Key-value summary from gateway_response
            ],

            // Taxes (reserved for future — always empty in V1)
            'taxes' => [],

            // Metadata
            'metadata' => [
                'system_version' => config('app.version', '1.0.0'),
                'locale' => app()->getLocale(),
                'ip_address' => null,
                'user_agent' => null,
                'generated_at' => now()->toIso8601String(),
            ],

            // Notes
            'notes' => $order->notes,

            // Audit
            'audit' => [
                'generated_by' => 'system',
                'generation_attempts' => 1,
                'correction_reason' => null,
                'cancellation_reason' => null,
            ],
        ];
    }
}
```

#### 3.10.3 Relationship Loading Requirements

Before calling `InvoiceSnapshotService::buildFullSnapshot()`, the `$order` must have these relations eager loaded:

```php
$order->load([
    'orderItems',                       // Line items (order_products)
    'orderItems.product',               // Product media for images
    'transactions',                     // Payment details
    'governorate',                      // Governorate name resolution (requires adding the relationship — see A.3)
]);
```

#### 3.10.4 Required Order Model Change

Add the `governorate()` relationship to `Marvel\Database\Models\Order`:

```php
public function governorate(): BelongsTo
{
    return $this->belongsTo(Governorate::class);
}
```

#### 3.10.5 V1 Exclusions — Future Enhancement Catalog

| Field | When It Can Be Added | Migration Required | Schema Version |
|-------|---------------------|-------------------|----------------|
| `customer.company_name` | V2 (next release) | `ALTER TABLE users ADD company_name VARCHAR(255) NULL` | `snapshot_schema` = 3 |
| `customer.tax_id` | V2 (next release) | `ALTER TABLE users ADD tax_id VARCHAR(50) NULL` | `snapshot_schema` = 3 |
| `fulfillment.tracking_number` | V2 (next release) | `ALTER TABLE orders ADD tracking_number VARCHAR(255) NULL` | `snapshot_schema` = 3 |

---

## 4. Invoice Number Generation

### 4.1 Format

```
{INVOICE_SERIES}-{YEAR}-{SEQUENCE}

Examples:
INV-2026-000001    -- Standard invoice
CN-2026-000001     -- Credit note
DN-2026-000001     -- Debit note
```

- **Series**: `INV` for invoices, `CN` for credit notes, `DN` for debit notes. Configurable per environment.
- **Year**: 4-digit year. Allows natural partitioning. Resets sequence per year.
- **Sequence**: Zero-padded to 6 digits (supports up to 999,999 invoices per series per year).

### 4.2 Sequence Table

```sql
CREATE TABLE invoice_sequences (
    series          VARCHAR(10) NOT NULL,
    sequence_year   YEAR NOT NULL,
    last_sequence   BIGINT UNSIGNED NOT NULL DEFAULT 0,
    created_at      TIMESTAMP NULL,
    updated_at      TIMESTAMP NULL,

    PRIMARY KEY (series, sequence_year)
);
```

### 4.3 Number Generation Algorithm

```php
class InvoiceNumberService
{
    public function generateNext(string $series = 'INV'): array
    {
        $year = now()->year;

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

            $number = sprintf(
                '%s-%d-%06d',
                $series,
                $year,
                $seq->last_sequence
            );

            return [
                'number' => $number,
                'series' => $series,
                'sequence' => $seq->last_sequence,
                'year' => $year,
            ];
        });
    }
}
```

### 4.4 Concurrency Handling

| Scenario | Mechanism |
|----------|-----------|
| Two workers generating simultaneously | `lockForUpdate()` on the sequence row creates a sequential bottleneck. The second worker waits for the first to commit. |
| Transaction rollback after sequence increment | The sequence number is consumed (incremented) even if the invoice creation fails. The number is **not reused**. This is deliberate — gaps are acceptable. |
| High-frequency generation | The `lockForUpdate()` on a single row limits throughput to ~1,000/sec (typical MySQL). For e-commerce volumes, this is sufficient. If scaling beyond this, use a dedicated sequence table per series+year, or pre-reserve number ranges. |

### 4.5 Gap Policy

**Gaps are acceptable.** The sequence table uses `lockForUpdate()` to prevent duplicates, but if an invoice transaction rolls back after incrementing the sequence, the number is not reused. This means:

- Invoice numbers may have gaps (e.g., INV-2026-000001, INV-2026-000003)
- Gaps are logged in the invoice audit trail with the reason
- Gap-free numbering is fragile and expensive in distributed systems. It is not required for legal compliance in most jurisdictions.

### 4.6 Duplicate Prevention

The `UNIQUE` constraint on `invoices.invoice_number` provides database-level duplicate prevention. The sequence table only generates numbers — if a duplicate somehow passed the sequence (impossible with `lockForUpdate()`), the unique constraint catches it at insert time.

### 4.7 Rollback Behavior

| Failure Point | Sequence Number | Invoice Record |
|---------------|----------------|----------------|
| Sequence increment succeeds, invoice insert fails | Consumed (gap) | Does not exist |
| Sequence increment succeeds, invoice insert succeeds, transaction commits | Used | Exists |
| Sequence increment succeeds, invoice insert succeeds, transaction rolls back (unrelated error) | Consumed (gap) | Does not exist |

The sequence increment and invoice insert **should be in the same database transaction** to minimize the gap window. The sequence increment is a single UPDATE with `lockForUpdate()`, so the critical section is small.

---

## 5. Exactly-Once Processing

### 5.1 Threat Model

| Threat | Scenario | Risk Level |
|--------|----------|------------|
| Duplicate event | PaymentSucceeded dispatched twice due to code bug or replay | High |
| Queue retry | Worker crashes after processing but before acknowledging the job. Job is re-delivered. | High |
| Concurrent workers | Two workers pick up the same event from the queue | Medium |
| Database retry | Laravel retries a failed database insert after a transient failure | Low |
| Race condition | Idempotency check passes for two concurrent requests before either inserts | Medium |

### 5.2 Defense Layers

#### Layer 1: Database UNIQUE Constraint

```sql
ALTER TABLE invoices ADD UNIQUE invoices_order_id_unique (order_id);
```

This is the **final guarantee**. No matter how many times the listener runs, only one invoice per order can exist. Any duplicate insert attempt throws a `UniqueConstraintViolationException`, which is caught and logged.

#### Layer 2: Application-Level Idempotency Check

```php
class GenerateInvoiceListener implements ShouldQueue
{
    public function handle(PaymentSucceeded $event): void
    {
        $order = $event->order;

        // Idempotency check
        if (Invoice::where('order_id', $order->id)->exists()) {
            Log::info('Invoice already exists for order', ['order_id' => $order->id]);
            return;
        }

        // Proceed with creation
        DB::transaction(function () use ($order) {
            // ... create invoice
        });
    }
}
```

**Note:** The application check is an optimization (avoids the exception). The database constraint is the source of truth.

#### Layer 3: Queue Job Middleware

```php
class GenerateInvoiceListener implements ShouldQueue
{
    public $middleware = [WithoutOverlapping::class];

    public function middleware(): array
    {
        return [new WithoutOverlapping($this->order->id)];
    }
}
```

The `WithoutOverlapping` middleware uses a cache lock to prevent two workers from processing the same order concurrently. If a second attempt arrives while the first is running, it is released back to the queue.

#### Layer 4: Transaction Wrapping

The invoice creation runs inside a `DB::transaction()`. If the insert fails (unique constraint), the transaction rolls back cleanly. If it succeeds, the transaction commits atomically.

### 5.3 Complete Idempotency Flow

```
PaymentSucceeded [queue job arrives]
  │
  ├── WithoutOverlapping lock acquired?
  │     ├── No → Release back to queue (retry later)
  │     └── Yes → Continue
  │
  ├── Invoice exists for order_id?
  │     ├── Yes → Log duplicate, return (idempotent)
  │     └── No → Continue
  │
  ├── DB::transaction()
  │     ├── Generate invoice number (lockForUpdate)
  │     ├── Build snapshot data
  │     ├── INSERT INTO invoices (order_id, ...)
  │     │     ├── Success → Continue
  │     │     └── Unique constraint violation → Rollback, log duplicate
  │     └── Commit
  │
  ├── Release WithoutOverlapping lock
  └── Dispatch GenerateInvoicePdfJob
```

### 5.4 Behavior Under Each Threat

| Threat | Layer 1 Catches? | Layer 2 Catches? | Layer 3 Catches? | Outcome |
|--------|-----------------|-----------------|-----------------|---------|
| Duplicate event dispatch | Yes | Yes (first check) | N/A | No duplicate invoice |
| Queue retry (worker crash) | Yes | Yes (exists() returns true) | N/A | No duplicate invoice |
| Concurrent workers | Yes | Yes (both pass check) | Yes (lock prevents both running) | No duplicate invoice |
| Database retry (transient failure on insert) | N/A | N/A | N/A | Same transaction; retry is safe |
| Race condition in app check | Yes | No (both pass exists()) | No (two separate events) | Unique constraint prevents second insert |

**The UNIQUE constraint on `order_id` is the single source of truth for idempotency. All other layers are optimizations.**

---

## 6. Failure Recovery

### 6.1 Failure Scenarios and Recovery Strategies

#### 6.1.1 Invoice Snapshot Creation Fails

**Scenario**: `GenerateInvoiceListener` fails during snapshot assembly or database insert.

**Recovery**:
- The listener implements `ShouldQueue` and uses Laravel's built-in retry mechanism.
- Configuration: `public $tries = 3` with `backoff` (exponential: 10s, 30s, 90s).
- If the order data is temporarily unavailable (transient DB issue), the retry will succeed.
- After 3 failed attempts, the job is moved to the `failed_jobs` table. Operations team can replay it via `php artisan queue:retry`.

**Prevention**:
- All data dependencies (Order, OrderProduct, Transaction) are already committed and available at the time the listener runs. No live-locks or incomplete transactions.
- The snapshot service is a pure data transformer — it reads existing committed data and does not depend on external services.

#### 6.1.2 PDF Generation Fails

**Scenario**: `GenerateInvoicePdfJob` fails due to memory limit, disk space, or blade rendering error.

**Recovery**:
- `public $tries = 3` with exponential backoff.
- On each retry, the status remains `generated` (the job uses `lockForUpdate()` to prevent concurrent generation).
- After exhaustion, status changes to `failed`.
- A scheduled command `invoices:regenerate-failed-pdfs` scans for invoices with status `failed` and re-dispatches the job.
- Admin can also manually trigger regeneration via API endpoint.

#### 6.1.3 Storage Failure (Disk Full / S3 Unavailable)

**Scenario**: PDF content cannot be written to the configured disk.

**Recovery**:
- The job catches `IOException` or S3 exceptions and marks the attempt as failed.
- Retry mechanism applies (see 6.1.2).
- Alert is sent to operations (via failed_jobs monitoring or custom alert).
- Once storage is available, the regeneration command re-dispatches all failed PDFs.

**Design detail**: The invoice data (JSON) is already safely stored in the database before PDF generation begins. PDF is a rendering concern only. No data is lost if storage fails permanently — a new PDF can always be regenerated from the stored snapshot.

#### 6.1.4 Queue Failure (Redis/Memory Down)

**Scenario**: Queue connection is unavailable. Jobs cannot be dispatched or processed.

**Recovery**:
- If the queue is down during `DB::afterCommit()`, Laravel queues the job in the `QUEUE_FAILED_DRIVER` (typically `database`).
- When the queue recovers, the job is processed normally.
- If the job was lost entirely (e.g., Redis flush), the `invoices:generate-pending` command scans for invoices without a generated status and re-dispatches.
- The `PaymentSucceeded` event carries only the `order_id`. If lost, an admin can manually trigger invoice generation for any paid order.

#### 6.1.5 Partially Completed Operation (Crash After Insert, Before Dispatch)

**Scenario**: Server crashes after `Invoice::create()` succeeds but before `GenerateInvoicePdfJob` is dispatched.

**Recovery**:
- The invoice record exists in the database with status `generated` (or `pdf_generating` if the crash occurred in the PDF job).
- The scheduled command `invoices:generate-pending` finds invoices where `pdf_generated_at IS NULL` and re-dispatches the PDF job.
- This command runs every 5 minutes via Laravel scheduler.

### 6.2 Retry Configuration Summary

| Component | Retries | Backoff Strategy | Final Action |
|-----------|---------|-----------------|--------------|
| `GenerateInvoiceListener` | 3 | 10s, 30s, 90s | Job moves to `failed_jobs` table |
| `GenerateInvoicePdfJob` | 3 | 30s, 120s, 300s | Status changes to `failed` |
| `invoices:generate-pending` | N/A (scheduled) | Runs every 5 min | Re-dispatches `GenerateInvoicePdfJob` |
| `invoices:regenerate-failed-pdfs` | N/A (manual trigger) | On-demand | Re-dispatches for `failed` status |

### 6.3 Operational Recovery Procedures

| Issue | Detection | Recovery Step |
|-------|-----------|---------------|
| Invoice listener keeps failing | `failed_jobs` table, monitoring alert | 1. Check `failed_jobs.exception` for the error. 2. Fix the root cause (e.g., migration issue). 3. Run `php artisan queue:retry --id=xxx`. 4. Verify invoice was created. |
| Invoice with no PDF | Check: `Invoice::whereNull('pdf_generated_at')->count()` | 1. Run `php artisan invoices:generate-pending`. 2. If fails, check storage and permissions. 3. Run `php artisan invoices:regenerate-failed-pdfs`. |
| PDF corrupted | Customer complaint, checksum mismatch | 1. Admin triggers PDF regeneration via API. 2. Job regenerates from stored snapshot data. 3. New checksum is computed and stored. |
| Sequence table corruption | Duplicate invoice number error | 1. Lock the sequence table. 2. Set `last_sequence` to max used sequence. 3. Unlock and verify next generation. |

---

## 7. Invoice Aggregate Boundaries

### 7.1 Domain Responsibilities

The Invoice module is responsible for exactly **one thing**:

> Transform an immutable snapshot of a completed order into an immutable invoice record and render it as a PDF document.

This means:

| Included | Excluded |
|----------|----------|
| Reading order data (already snapshotted at order creation time) | Recalculating prices |
| Assembling a self-contained JSON snapshot | Validating coupons |
| Storing structured financial fields for reporting | Applying promotions |
| Generating a unique invoice number | Resolving flash sales |
| Rendering a PDF from the stored snapshot | Querying current product prices |
| Issuing correction invoices (adjusted snapshots) | Modifying order status or payment records |
| Providing read-only access via API | Managing inventory |

### 7.2 What the Invoice Module Must Never Do

```
┌──────────────────────────────────────────────────────────────┐
│                    INVOICE MODULE                             │
│                                                              │
│  ✓ Read order data (committed, immutable at read time)       │
│  ✓ Read transaction data (committed)                         │
│  ✓ Read user data (name, email, phone at time of generation) │
│  ✓ Assemble JSON snapshot                                    │
│  ✓ Generate invoice number (sequence table)                  │
│  ✓ Store invoice record                                      │
│  ✓ Render PDF from snapshot                                  │
│  ✓ Serve read-only API responses                             │
│                                                              │
│  ✗ NEVER call ProductPricingService                          │
│  ✗ NEVER call CouponCalculator / CouponOrchestrator          │
│  ✗ NEVER call PromotionService                               │
│  ✗ NEVER call FlashSaleService / ProductFlashSaleService     │
│  ✗ NEVER execute raw SQL for pricing calculations            │
│  ✗ NEVER call Order::update() or Transaction::update()       │
│  ✗ NEVER call Product::find() or Variant::find()             │
│  ✗ NEVER dispatch Order-related events (OrderStatusChanged)  │
│  ✗ NEVER duplicate business logic found in other services    │
└──────────────────────────────────────────────────────────────┘
```

### 7.3 Why These Boundaries Matter

| Violation | Consequence |
|-----------|-------------|
| Invoice calls `ProductPricingService` | Invoice would show different prices than what the customer actually paid (live prices may have changed). Breaks frozen pricing architecture (ADR-001). |
| Invoice calls `CouponCalculator` | Coupon rules may have changed since the order was placed. Invoice would calculate a different discount than what was applied. |
| Invoice modifies Order status | Introduces side effects into the invoice flow. If invoice fails, order state is inconsistent. |
| Invoice queries live product data | Product may have been deleted, renamed, or re-priced. Invoice would reference data that did not exist at time of purchase. |

### 7.4 The "Read-Only Consumer" Principle

The Invoice module is a **read-only consumer** of already-finalized business data:

```
Order placed ──→ Order snapshots data (order_products, totals, coupon, promotion)
                      │
                      ↓ (read-only)
                 Invoice module ──→ Immutable invoice record
                      │
                      ↓ (read-only)
                 PDF rendering ──→ Static document
```

Every piece of data the invoice needs must have been captured and committed before the invoice module touches it. If data was not snapshotted at order creation time, the invoice module must request that it be added to the order snapshot — it must never reach into live tables to fetch it.

### 7.5 Invoice Data Sources

The Invoice module is a **read-only consumer**. The following table documents every allowed data source and explicitly lists what must never be accessed.

#### 7.5.1 Allowed Data Sources

| Source Table/Model | Accessed Via | Purpose | Data Type |
|-------------------|-------------|---------|-----------|
| `orders` | `$order` (passed via `PaymentSucceeded` event) | Order-level snapshots: totals, shipping, coupon, promotion, fulfillment, customer name/phone/email, pickup location | Structured columns + selected fields |
| `order_products` | `$order->orderItems` (eager loaded) | Line items: product_name, SKU, quantity, unit_price, total_price, discount, flash_sale_price, promotion_amount, attributes, is_gift | Collection → JSON array in snapshot |
| `transactions` | `$order->transactions()->latest()` | Payment details: gateway_transaction_id, amount, currency, paid_at, gateway | Single record → JSON object in snapshot |
| `users` | `$order->user` | Customer email and phone (supplementary to `order.user_email`/`order.user_phone` which are already snapshotted) | Single record → JSON object in snapshot |
| `invoice_sequences` | `InvoiceNumberService` | Sequence number generation only | Internal sequence tracking |
| `settings` | System config | Currency, locale, site title | Config values → snapshot metadata |

**Key rule**: The snapshot service reads these tables using the **already-loaded Eloquent relationships** on the `$order` object, which is passed as a parameter. It never constructs new queries against these tables directly. The `Order` model with its relations must be eager-loaded before the snapshot service is called.

#### 7.5.2 Prohibited Data Sources

The Invoice module must NEVER read from these tables:

| Table | Why It Is Prohibited |
|-------|---------------------|
| `products` | Prices may have changed. Product may have been deleted. Data was already snapshotted in `order_products.product_name`, `order_products.product_sku`. |
| `product_variants` | Variant may have been deleted or re-priced. Attributes were already snapshotted in `order_products.attributes` (JSON). |
| `flash_sales` | Flash sale may have ended. Flash sale pricing was already snapshotted in `order_products.product_flash_sale_price`. |
| `promotions` | Promotion may have been deleted or modified. Discount was already snapshotted in `orders.promotion_discount` and `order_products.promotion_discount_amount`. |
| `coupons` | Coupon may have been deleted or its quota exhausted. Discount data was already snapshotted in `orders.coupon_discount`. |
| `coupon_assignments` / `coupon_assignment_usages` | Assignment state is mutable. Not relevant to invoice — the snapshot stores the coupon code and discount amount only. |
| `product_pricing` services | Frozen per ADR-001. Pricing was already calculated at order creation time. |
| `shipping_prices` | Shipping price was already snapshotted in `orders.shipping_price`. |

#### 7.5.3 Snapshot Service Implementation Contract

```php
class InvoiceSnapshotService
{
    public function __construct(
        // Constructor injection is limited to utilities.
        // No domain services (ProductPricingService, CouponCalculator, etc.)
    ) {}

    public function buildFullSnapshot(Order $order): array
    {
        // ONLY reads from:
        // 1. $order (already loaded with relations)
        // 2. $order->orderItems (already eager loaded)
        // 3. $order->transactions (already eager loaded)
        // 4. $order->user (already eager loaded)
        //
        // NEVER calls:
        // - ProductPricingService
        // - CouponCalculator / CouponOrchestrator
        // - PromotionService
        // - FlashSale::find()
        // - Product::find()
        // - Any pricing-related service

        return [
            'snapshot_version' => '2.0.0',
            'snapshot_schema' => 2,
            'customer' => $this->buildCustomer($order),
            'billing_address' => $this->buildAddress($order),
            'shipping_address' => $this->buildAddress($order),
            'fulfillment' => $this->buildFulfillment($order),
            'items' => $this->buildItems($order),
            'pricing_breakdown' => $this->buildPricing($order),
            'payment' => $this->buildPayment($order),
            'metadata' => $this->buildMetadata($order),
        ];
    }

    // Each builder method reads from order snapshots only.
    // No live database queries. No service calls.
}
```

### 7.6 PDF Source of Truth

**Principle: The database invoice record is the single source of truth. The PDF is only a generated representation of the immutable invoice data.**

```
Source of Truth              Generated Representation
─────────────────           ─────────────────────────
invoices table               PDF file on disk
  ├── data (JSON)               ├── content
  ├── structured columns        ├── layout
  └── pdf_checksum              └── checksum (matches invoice)
       │
       └── Can regenerate ──────┘
```

#### 7.6.1 Consequences of This Principle

| Scenario | Truth | Action |
|----------|-------|--------|
| PDF is accidentally deleted | The invoice record still exists with all data | Regenerate PDF from `invoices.data` JSON |
| PDF content is corrupted | The invoice record has the correct checksum (`pdf_checksum`); mismatch is detected | Regenerate and verify new checksum |
| PDF sent to customer contains wrong data | The invoice record in the database has the correct data; the PDF was generated from old/buggy code | Fix the PDF generation code, regenerate, and re-send |
| Developer wants to add a new field to the PDF | No data needs to be re-collected | The field should already exist in the JSON snapshot. If not, add it to the snapshot (new `snapshot_schema` version) and update the PDF template. |
| Customer requests a copy of an invoice from 3 years ago | The invoice record exists in the database (or archival storage) | Regenerate the PDF from the stored snapshot data. The output matches the original. |

#### 7.6.2 PDF Generation Is a Pure Function

```
PDF = render(invoice.data JSON, PDF template version)
```

Given the same `invoice.data` and the same template version, the PDF output is always identical. This is enforced by:

1. **Checksum verification**: `pdf_checksum` is SHA-256 of the generated PDF. After regeneration, the checksum should match (for the same template version).
2. **No external data**: The PDF template reads exclusively from `$invoice->data`. It never queries the database, never calls services, and never fetches live data.
3. **Template versioning**: The PDF template is versioned alongside the application. If the template changes, the `system_version` in the snapshot metadata records which version generated the original PDF.

#### 7.6.3 What This Means for Operations

- **Backup priority**: The database (specifically `invoices` table) is the critical backup target. PDF files are secondary — they can always be regenerated.
- **Recovery time**: If the entire PDF storage is lost, recovery is: `Invoice::whereNotNull('data')->get() -> dispatch(GenerateInvoicePdfJob)`.
- **No vendor lock-in**: The PDF library (dompdf) can be replaced without data loss. Only the PDF template needs to change; the source JSON is unchanged.

### 7.7 Snapshot Validation Pipeline

Before an invoice snapshot is persisted, it must pass through a validation pipeline. This guarantees that only financially correct, structurally valid snapshots enter the database.

#### 7.7.1 Pipeline Flow

```
InvoiceSnapshotBuilder
       │
       ▼
InvoiceSnapshotValidator
       │
       ├── 1. Structure validation (required fields, types)
       ├── 2. Money validation (precision, non-negative)
       ├── 3. Currency consistency
       ├── 4. Financial invariant validation (see §3.9)
       ├── 5. Financial total consistency (subtotal - discounts + shipping = total)
       ├── 6. Version validation (snapshot_schema is known)
       ├── 7. Metadata validation (locale, generated_at)
       ├── 8. Snapshot hash computation
       │
       ▼
InvoiceRepository::create()
       │
       ├── 9. Database insert with UNIQUE constraint
       ├── 10. Hash stored alongside data
       │
       ▼
   Invoice record (persisted)
```

#### 7.7.2 Validation Rules

| # | Validator | Rule | Failure Action |
|---|-----------|------|----------------|
| 1 | **StructureValidator** | All required fields from Appendix A exist with correct types | Reject with `ValidationException` |
| 2 | **MoneyValidator** | All monetary fields have max 3 decimal places, are non-negative, and are not NaN/INF | Reject with `MoneyPrecisionException` |
| 3 | **CurrencyValidator** | All monetary fields use the same `currency` value | Reject with `CurrencyMismatchException` |
| 4 | **FinancialInvariantValidator** | `subtotal - coupon_discount - promotion_discount + shipping_price ± tolerance = total` | Reject with `FinancialInvariantException` |
| 5 | **SnapshotVersionValidator** | `snapshot_schema` matches a known, supported schema version | Reject with `UnsupportedSchemaException` |
| 6 | **MetadataValidator** | `generated_at` is present and not in the future; `locale` is valid | Log warning; allow creation with invalid locale but reject future `generated_at` |
| 7 | **IntegrityHashGenerator** | Compute `snapshot_hash` from canonical JSON | Store hash; this is not a rejection step |

#### 7.7.3 Validator Implementation Contract

```php
class InvoiceSnapshotValidator
{
    public function __construct(
        private readonly array $validators = [
            StructureValidator::class,
            MoneyValidator::class,
            CurrencyValidator::class,
            FinancialInvariantValidator::class,
            SnapshotVersionValidator::class,
            MetadataValidator::class,
        ],
    ) {}

    public function validate(array $snapshot): void
    {
        foreach ($this->validators as $validator) {
            app($validator)->validate($snapshot);
        }
    }
}
```

Each validator implements a single interface:

```php
interface SnapshotValidatorInterface
{
    /**
     * @throws SnapshotValidationException
     */
    public function validate(array $snapshot): void;
}
```

#### 7.7.4 Validation vs. Business Logic

The validation pipeline is a **guard**, not business logic. It enforces:

- **Structural correctness**: The JSON has the right shape.
- **Financial correctness**: The numbers add up.
- **Version compatibility**: The schema version is known to the current application.

It does NOT:

- Recalculate prices (that was done at order creation by `ProductPricingService`).
- Validate coupons or promotions (that was done at checkout by `CouponOrchestrator` and `PromotionService`).
- Check product existence (products were valid at order time).

---

## 8. Audit Timeline

### 8.1 Invoice Table Audit Fields

| Field | Type | Purpose | Set When |
|-------|------|---------|----------|
| `generated_at` | TIMESTAMP NOT NULL | Tracks exactly when the invoice record entered the system. Critical for audit: "was the invoice generated within acceptable time after payment?" | Invoice creation |
| `generated_by` | VARCHAR(50) NULLABLE | Identifies the trigger: `system` (automatic via PaymentSucceeded), or admin user ID (manual generation). | Invoice creation |
| `pdf_generated_at` | TIMESTAMP NULL | Documents when the PDF was successfully rendered. Used to calculate PDF generation latency and for SLA tracking. | PDF job completion |
| `pdf_regenerated_at` | TIMESTAMP NULL | Tracks the last regeneration. Important for debugging — if a PDF checksum changes, this field shows when. | PDF regeneration |
| `pdf_path` | VARCHAR(500) NULL | Storage path of the PDF file. Used to serve downloads and for backup/archival. | PDF job completion |
| `pdf_checksum` | VARCHAR(64) NULL | SHA-256 hash of the PDF content. Used for integrity verification: "has the PDF been tampered with or corrupted?" | PDF job completion |
| `generation_attempts` | TINYINT UNSIGNED NOT NULL DEFAULT 0 | Counts how many times PDF generation was attempted. Used to detect chronic failures. Incremented on each attempt. | Each PDF attempt |
| `last_generation_error` | TEXT NULL | Stores the last PDF generation error message. Used by operations to diagnose failures without reading queue logs. | PDF failure |
| `correction_reason` | VARCHAR(500) NULL | Documents why a correction invoice was issued. Required for audit compliance. | Correction creation |
| `corrected_at` | TIMESTAMP NULL | When the correction was issued. Links the original invoice to a correction event. | Correction creation |
| `original_invoice_id` | BIGINT UNSIGNED NULL | FK to the original invoice that this correction supersedes. Creates an audit chain. | Correction creation |
| `is_correction` | TINYINT(1) NOT NULL DEFAULT 0 | Quick filter: is this a correction invoice? | Correction creation |
| `cancelled_at` | TIMESTAMP NULL | When the invoice was voided/cancelled. | Cancellation |
| `cancellation_reason` | VARCHAR(500) NULL | Why the invoice was cancelled. | Cancellation |

### 8.2 Audit Chain Example

```
Invoice A (original)
  generated_at = 2026-07-15 10:30:05
  generated_by = system
  status = corrected
  corrected_at = 2026-07-20 14:00:00
  correction_reason = "Price adjustment per customer request"
    │
    └── Invoice B (correction)
          generated_at = 2026-07-20 14:00:01
          generated_by = admin@example.com
          original_invoice_id = 42 (Invoice A)
          is_correction = 1
          status = ready
```

### 8.3 Why Each Field Exists

| Field | Audit Question It Answers |
|-------|-------------------------|
| `generated_at` | "Was the invoice generated within SLA of payment?" |
| `generated_by` | "Was this automatic or did someone manually trigger it?" |
| `pdf_generated_at` | "How long did PDF generation take? Is it within acceptable range?" |
| `pdf_regenerated_at` | "When was the PDF last regenerated? Why?" |
| `pdf_checksum` | "Has the PDF been modified after generation? Is it the exact same file as before?" |
| `generation_attempts` | "Is PDF generation chronically failing for this invoice?" |
| `last_generation_error` | "What went wrong without needing to dig through logs?" |
| `correction_reason` | "What business reason justified modifying this financial record?" |
| `corrected_at` | "When did the correction occur?" |
| `original_invoice_id` | "What is the full audit trail from original to corrected?" |
| `cancelled_at` | "When was this financial record voided?" |

---

## 9. API Design

### 9.1 Customer Endpoints

All customer endpoints require `auth:sanctum`. Authorization is `InvoicePolicy` (owner-only).

#### GET /api/general/invoices

List authenticated user's invoices.

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `page` | int | 1 | Page number |
| `per_page` | int | 15 | Items per page (max 50) |
| `sort` | string | `-created_at` | Sort field with direction prefix (`+` asc, `-` desc) |
| `status` | string | null | Filter by status |
| `date_from` | date | null | Filter by generated_at >= |
| `date_to` | date | null | Filter by generated_at <= |

Response:
```json
{
  "data": [
    {
      "id": 1,
      "invoice_number": "INV-2026-000001",
      "order_number": "ORD-00000042",
      "total": 2900.00,
      "currency": "EGP",
      "status": "ready",
      "payment_method": "online",
      "has_document": true,
      "generated_at": "2026-07-15T10:30:05Z"
    }
  ],
  "meta": {
    "current_page": 1,
    "last_page": 5,
    "per_page": 15,
    "total": 65
  }
}
```

#### GET /api/general/invoices/{id}

View full invoice.

Response:
```json
{
  "data": {
    "id": 1,
    "invoice_number": "INV-2026-000001",
    "order_number": "ORD-00000042",
    "order_id": 42,
    "status": "ready",
    "subtotal": 3000.00,
    "shipping_price": 50.00,
    "coupon_discount": 50.00,
    "promotion_discount": 100.00,
    "total_discount": 150.00,
    "total": 2900.00,
    "amount_paid": 2900.00,
    "currency": "EGP",
    "payment_method": "online",
    "payment_gateway": "myfatoorah",
    "is_correction": false,
    "correction_to": null,
    "correction_reason": null,
    "has_document": true,
    "generated_at": "2026-07-15T10:30:05Z",
    "data": { "... full snapshot ..." }
  }
}
```

#### GET /api/general/invoices/{id}/download

Download invoice PDF.

- Returns: `Content-Type: application/pdf` with `Content-Disposition: attachment; filename="INV-2026-000001.pdf"`
- Status 404 if `has_document` is false
- Uses `InvoicePolicy@download` for authorization

#### GET /api/general/orders/{orderId}/invoice

Get invoice for a specific order.

- Returns full `InvoiceResource`
- 404 if no invoice exists for this order

### 9.2 Admin Endpoints

All admin endpoints require `auth:sanctum` + appropriate permission.

#### GET /api/general/admin/invoices

List all invoices with advanced filtering.

| Parameter | Type | Description |
|-----------|------|-------------|
| `page` | int | Page number |
| `per_page` | int | Items per page |
| `sort` | string | Sort field |
| `status` | string | Filter by status |
| `user_id` | int | Filter by customer |
| `payment_method` | string | Filter by payment method |
| `payment_gateway` | string | Filter by gateway |
| `currency` | string | Filter by currency |
| `date_from` | date | Filter by generated_at >= |
| `date_to` | date | Filter by generated_at <= |
| `total_min` | decimal | Filter by total >= |
| `total_max` | decimal | Filter by total <= |
| `invoice_number` | string | Search by invoice number |
| `order_number` | string | Search by order number |
| `search` | string | Full-text search across invoice_number and customer name |

Permission: `view-invoices`

#### GET /api/general/admin/invoices/{id}

View any invoice. Permission: `view-invoices`

#### GET /api/general/admin/invoices/{id}/download

Download any invoice PDF. Permission: `view-invoices`

#### GET /api/general/admin/orders/{orderId}/invoice

Get invoice by order ID. Permission: `view-invoices`

#### POST /api/general/admin/invoices/{id}/correct

Issue a correction invoice.

```json
{
  "reason": "Price adjustment per customer request",
  "adjusted_total": 2800.00,
  "adjusted_shipping": 0.00,
  "notes": "Customer was overcharged due to promo glitch"
}
```

Permission: `issue-correction-invoice`

Response: Returns the new correction `InvoiceResource`.

#### POST /api/general/admin/invoices/{id}/regenerate-pdf

Regenerate the PDF document.

Permission: `regenerate-invoice-pdf`

Response:
```json
{
  "message": "PDF regeneration queued",
  "invoice_id": 42
}
```

#### GET /api/general/admin/invoices/export

Export invoices as CSV.

| Parameter | Type | Description |
|-----------|------|-------------|
| `date_from` | date | Required |
| `date_to` | date | Required |
| `status` | string | Optional filter |
| `format` | string | `csv` (default) or `xlsx` |

Permission: `export-invoices`

### 9.3 Resource Structure

#### InvoiceResource (Full)

```php
class InvoiceResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'invoice_number' => $this->invoice_number,
            'order_number' => $this->order?->order_number,
            'order_id' => $this->order_id,
            'status' => $this->status,
            'subtotal' => (float) $this->subtotal,
            'shipping_price' => (float) $this->shipping_price,
            'coupon_discount' => (float) $this->coupon_discount,
            'promotion_discount' => (float) $this->promotion_discount,
            'total_discount' => (float) $this->total_discount,
            'total' => (float) $this->total,
            'amount_paid' => (float) $this->amount_paid,
            'currency' => $this->currency,
            'payment_method' => $this->payment_method,
            'payment_gateway' => $this->payment_gateway,
            'is_correction' => $this->is_correction,
            'correction_to' => $this->correction_to_id,
            'correction_reason' => $this->correction_reason,
            'has_document' => !is_null($this->pdf_generated_at),
            'generated_at' => $this->generated_at,
            'data' => $this->data,  // Full snapshot (immutable)
        ];
    }
}
```

#### InvoiceCollection (List)

```php
class InvoiceCollection extends ResourceCollection
{
    public function toArray($request): array
    {
        return [
            'data' => $this->collection->map(fn ($invoice) => [
                'id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'order_number' => $invoice->order?->order_number,
                'total' => (float) $invoice->total,
                'currency' => $invoice->currency,
                'status' => $invoice->status,
                'payment_method' => $invoice->payment_method,
                'has_document' => !is_null($invoice->pdf_generated_at),
                'generated_at' => $invoice->generated_at,
            ]),
        ];
    }
}
```

### 9.4 Authorization (InvoicePolicy)

```php
class InvoicePolicy
{
    const OWNER_PERMISSIONS = [
        'view', 'download',
    ];

    const ADMIN_PERMISSIONS = [
        'view', 'download', 'createCorrection',
        'regeneratePdf', 'export',
    ];

    public function view(User $user, Invoice $invoice): bool
    {
        return $user->id === $invoice->user_id
            || $user->hasPermissionTo('view-invoices');
    }

    public function download(User $user, Invoice $invoice): bool
    {
        return $user->id === $invoice->user_id
            || $user->hasPermissionTo('view-invoices');
    }

    public function createCorrection(User $user): bool
    {
        return $user->hasPermissionTo('issue-correction-invoice');
    }

    public function regeneratePdf(User $user): bool
    {
        return $user->hasPermissionTo('regenerate-invoice-pdf');
    }

    public function export(User $user): bool
    {
        return $user->hasPermissionTo('export-invoices');
    }
}
```

---

## 10. Event Flow and Lifecycle Diagram

### 10.1 Complete Event Sequence

```
┌─────────────────────────────────────────────────────────────────────┐
│                     PAYMENT COMPLETION                               │
│                                                                      │
│  ┌──────────────────────────────────────────────────────────────┐   │
│  │  Payment Flow (ONLINE / COD / CASHIER)                        │   │
│  │                                                               │   │
│  │  1. Transaction::lockForUpdate()                              │   │
│  │  2. Transaction::update(['status' => 'paid', 'paid_at'])      │   │
│  │  3. Order::update(['status' => 'completed'])                   │   │
│  │  4. recordCouponUsage()                                        │   │
│  │  5. DB::commit()                                               │   │
│  │  6. DB::afterCommit() → dispatch(PaymentSucceeded($order))     │   │
│  └──────────────────────────────────────────────────────────────┘   │
│                                                                      │
│  Event: PaymentSucceeded { $order }                                  │
│  Queue: default queue                                                │
│  ──────────────────────────────────────────────────────────────      │
└─────────────────────────────────────────────────────────────────────┘
                                    │
                                    v
┌─────────────────────────────────────────────────────────────────────┐
│                   INVOICE GENERATION                                 │
│                                                                      │
│  Listener: GenerateInvoiceListener (ShouldQueue)                     │
│  Queue: medium                                                        │
│                                                                      │
│  ┌──────────────────────────────────────────────────────────────┐   │
│  │  1. Idempotency: Invoice::where('order_id', $order.id)       │   │
│  │     exists? → Skip (return early)                             │   │
│  │                                                               │   │
│  │  2. DB::transaction():                                        │   │
│  │     a. InvoiceNumberService::generateNext('INV')              │   │
│  │     b. InvoiceSnapshotService::buildFullSnapshot($order)      │   │
│  │     c. Invoice::create([structured columns + JSON data])      │   │
│  │  3. DB::afterCommit():                                        │   │
│  │     dispatch(new InvoiceCreated($invoice))                    │   │
│  │     dispatch(new GenerateInvoicePdfJob($invoice))             │   │
│  └──────────────────────────────────────────────────────────────┘   │
│                                                                      │
│  Event: InvoiceCreated { $invoice }                                  │
│  Queue: medium (fire-and-forget for external integrations)           │
│  ──────────────────────────────────────────────────────────────      │
└─────────────────────────────────────────────────────────────────────┘
                                    │
                                    v
┌─────────────────────────────────────────────────────────────────────┐
│                    PDF GENERATION                                    │
│                                                                      │
│  Job: GenerateInvoicePdfJob (ShouldQueue)                            │
│  Queue: pdf                                                          │
│  Tries: 3                                                            │
│                                                                      │
│  ┌──────────────────────────────────────────────────────────────┐   │
│  │  1. Invoice::lockForUpdate()                                  │   │
│  │  2. Ensure status allows PDF generation (generated/failed)   │   │
│  │  3. Increment generation_attempts                             │   │
│  │  4. Set status = pdf_generating                               │   │
│  │  5. Build PDF from blade view (uses $invoice->data JSON)     │   │
│  │  6. Store to storage/invoices/{filename}.pdf                  │   │
│  │  7. Compute SHA-256 checksum                                   │   │
│  │  8. Update invoice:                                           │   │
│  │     pdf_path, pdf_checksum, pdf_generated_at = now(),         │   │
│  │     status = ready                                            │   │
│  │                                                               │   │
│  │  ── On success ──> InvoiceReady ($invoice)                    │   │
│  │  ── On failure ──> Release back to queue (up to 3 times)     │   │
│  │  ── Final failure ──> status = failed, log error             │   │
│  └──────────────────────────────────────────────────────────────┘   │
│                                                                      │
│  Event: InvoiceReady { $invoice } (for external notifications)       │
│  Queue: low (fire-and-forget)                                        │
│  ──────────────────────────────────────────────────────────────      │
└─────────────────────────────────────────────────────────────────────┘
                                    │
                                    v
┌─────────────────────────────────────────────────────────────────────┐
│                   RECOVERY FLOWS                                     │
│                                                                      │
│  ┌──────────────────────────────────────────────────────────────┐   │
│  │  Scheduled: invoices:generate-pending                          │   │
│  │  Cadence: Every 5 minutes                                      │   │
│  │  Finds: Invoice::whereNull('pdf_generated_at')                 │   │
│  │         ->where('status', '!=', 'failed')                      │   │
│  │  Action: dispatch(GenerateInvoicePdfJob($invoice))            │   │
│  └──────────────────────────────────────────────────────────────┘   │
│                                                                      │
│  ┌──────────────────────────────────────────────────────────────┐   │
│  │  Scheduled: invoices:regenerate-failed-pdfs                   │   │
│  │  Cadence: Every 30 minutes (or on-demand)                    │   │
│  │  Finds: Invoice::where('status', 'failed')                    │   │
│  │  Action: dispatch(GenerateInvoicePdfJob($invoice))            │   │
│  └──────────────────────────────────────────────────────────────┘   │
│                                                                      │
│  ┌──────────────────────────────────────────────────────────────┐   │
│  │  Admin API: POST /admin/invoices/{id}/regenerate-pdf          │   │
│  │  Action: dispatch(GenerateInvoicePdfJob($invoice))            │   │
│  └──────────────────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────────────────┘
```

### 10.2 Event Inventory

| Event | Dispatched By | Listened By | Purpose |
|-------|--------------|-------------|---------|
| `PaymentSucceeded` | `OrderService` (DB::afterCommit) | `GenerateInvoiceListener`, `SendPaymentSucceededNotification` | Triggers invoice generation |
| `InvoiceCreated` | `GenerateInvoiceListener` (DB::afterCommit) | Future: external integrations (ERP, accounting) | Notifies downstream systems that an invoice exists |
| `InvoiceReady` | `GenerateInvoicePdfJob` | Future: email notification to customer | Notifies that PDF is available for download |

---

## 11. Database Design

### 11.1 `invoices` Table

```sql
CREATE TABLE invoices (
    -- Primary / Foreign Keys
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id            BIGINT UNSIGNED NOT NULL,
    transaction_id      BIGINT UNSIGNED NULL,
    user_id             BIGINT UNSIGNED NOT NULL,
    correction_to_id    BIGINT UNSIGNED NULL,

    -- Invoice Numbering
    invoice_number      VARCHAR(50) NOT NULL,
    invoice_series      VARCHAR(10) NOT NULL DEFAULT 'INV',
    sequence_number     BIGINT UNSIGNED NOT NULL,
    sequence_year       YEAR NOT NULL,

    -- Financial Summary (structured, indexed)
    subtotal            DECIMAL(10,3) NOT NULL DEFAULT 0,
    shipping_price      DECIMAL(10,3) NOT NULL DEFAULT 0,
    coupon_discount     DECIMAL(10,3) NOT NULL DEFAULT 0,
    promotion_discount  DECIMAL(10,3) NOT NULL DEFAULT 0,
    total_discount      DECIMAL(10,3) NOT NULL DEFAULT 0,
    total               DECIMAL(10,3) NOT NULL DEFAULT 0,
    amount_paid         DECIMAL(10,3) NOT NULL DEFAULT 0,
    currency            VARCHAR(3) NOT NULL DEFAULT 'EGP',
    payment_method      VARCHAR(30) NULL,
    payment_gateway     VARCHAR(50) NULL,

    -- Lifecycle Status
    status              VARCHAR(20) NOT NULL DEFAULT 'generated',

    -- Immutable Snapshot (full business data)
    data                JSON NOT NULL,

    -- PDF Document Tracking
    pdf_generated_at    TIMESTAMP NULL,
    pdf_regenerated_at  TIMESTAMP NULL,
    pdf_path            VARCHAR(500) NULL,
    pdf_checksum        VARCHAR(64) NULL,
    generation_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    last_generation_error TEXT NULL,

    -- Corrections
    is_correction       TINYINT(1) NOT NULL DEFAULT 0,
    correction_reason   VARCHAR(500) NULL,
    corrected_at        TIMESTAMP NULL,

    -- Cancellation
    cancelled_at        TIMESTAMP NULL,
    cancellation_reason VARCHAR(500) NULL,

    -- Audit Metadata
    generated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    generated_by        VARCHAR(50) NULL DEFAULT 'system',
    created_at          TIMESTAMP NULL,
    updated_at          TIMESTAMP NULL,

    -- Foreign Keys
    CONSTRAINT fk_invoices_order_id
        FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE RESTRICT,
    CONSTRAINT fk_invoices_transaction_id
        FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE SET NULL,
    CONSTRAINT fk_invoices_user_id
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_invoices_correction_to_id
        FOREIGN KEY (correction_to_id) REFERENCES invoices(id) ON DELETE SET NULL,

    -- Unique Constraints
    CONSTRAINT uq_invoices_order_id UNIQUE (order_id),
    CONSTRAINT uq_invoices_invoice_number UNIQUE (invoice_number),

    -- Indexes
    INDEX idx_invoices_user_id (user_id),
    INDEX idx_invoices_status (status),
    INDEX idx_invoices_currency (currency),
    INDEX idx_invoices_payment_method (payment_method),
    INDEX idx_invoices_payment_gateway (payment_gateway),
    INDEX idx_invoices_generated_at (generated_at),
    INDEX idx_invoices_total (total),
    INDEX idx_invoices_sequence_year (sequence_year),
    INDEX idx_invoices_transaction_id (transaction_id),
    INDEX idx_invoices_correction_to_id (correction_to_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 11.2 `invoice_sequences` Table

```sql
CREATE TABLE invoice_sequences (
    series          VARCHAR(10) NOT NULL,
    sequence_year   YEAR NOT NULL,
    last_sequence   BIGINT UNSIGNED NOT NULL DEFAULT 0,
    created_at      TIMESTAMP NULL,
    updated_at      TIMESTAMP NULL,

    PRIMARY KEY (series, sequence_year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 11.3 Index Justification

| Index | Why It Exists |
|-------|---------------|
| `uq_invoices_order_id` | **Exactly-once guarantee.** Prevents duplicate invoices per order. Also enables fast lookup: "get invoice for order X." |
| `uq_invoices_invoice_number` | **Business rule.** Every invoice must have a unique human-readable identifier. Used in legal/financial contexts. |
| `idx_invoices_user_id` | **Customer queries.** Every `/invoices` owner filter uses `WHERE user_id = ?`. Without this index, customer invoice listing would scan the entire table. |
| `idx_invoices_status` | **Admin filtering + scheduled commands.** The `invoices:generate-pending` and `invoices:regenerate-failed-pdfs` commands filter by status. Without this index, they scan all rows. |
| `idx_invoices_generated_at` | **Admin date-range filtering + reporting.** Monthly revenue reports filter by `generated_at BETWEEN ? AND ?`. |
| `idx_invoices_total` | **Admin total-range filtering.** Queries like `WHERE total > 10000` benefit from this index. |
| `idx_invoices_payment_method` | **Admin filter + reporting.** Payment method breakdown reports aggregate on this column. |
| `idx_invoices_payment_gateway` | **Gateway reconciliation.** Used to find all invoices for a specific gateway for reconciliation. |
| `idx_invoices_currency` | **Multi-currency reporting.** If the system supports multiple currencies, this index is needed for per-currency aggregation. |
| `idx_invoices_transaction_id` | **Joins with transactions table.** Enables efficient `transaction->invoice` navigation. |
| `idx_invoices_correction_to_id` | **Correction chain traversal.** Used to find all corrections for a given original invoice. |

### 11.4 Nullable Field Justification

| Field | Nullable? | Why |
|-------|-----------|-----|
| `transaction_id` | Yes | Invoice may reference a future partial-payment scenario where multiple transactions map to one invoice. |
| `correction_to_id` | Yes | Only populated for correction invoices. Normal invoices have no correction reference. |
| `pdf_generated_at` | Yes | Invoice is created before PDF is generated. This field is NULL until PDF job completes. |
| `pdf_path` | Yes | NULL until PDF is generated. |
| `pdf_checksum` | Yes | NULL until PDF is generated. |
| `last_generation_error` | Yes | NULL unless PDF generation failed. |
| `correction_reason` | Yes | NULL unless this is a correction. |
| `corrected_at` | Yes | NULL unless this invoice has been corrected. |
| `cancelled_at` | Yes | NULL unless cancelled. |
| `cancellation_reason` | Yes | NULL unless cancelled. |
| `generated_by` | Yes | Defaults to 'system'. NULL allowed for backward compatibility if field is added later. |
| `payment_gateway` | Yes | COD payments have no gateway. |

### 11.5 JSON Data Structure (Stored in `data` Column)

The JSON structure maintains the same format as V1 (see V1 Section 6) with the following additions:

```json
{
  "customer": { ... },
  "billing_address": { ... },
  "shipping_address": { ... },
  "fulfillment": { ... },
  "pickup_location": null,
  "items": [ ... ],
  "pricing_breakdown": { ... },
  "payment": {
    "method": "online",
    "gateway": "myfatoorah",
    "transaction_id": 42,
    "gateway_transaction_id": "MF-123456789",
    "paid_at": "2026-07-15T10:30:00Z",
    "gateway_invoice_id": "INV-12345"
  },
  "taxes": [],
  "metadata": {
    "generated_at": "2026-07-15T10:30:05Z",
    "system_version": "1.0.0",
    "locale": "ar",
    "ip_address": null,
    "user_agent": null
  },
  "notes": null,
  "audit": {
    "generated_by": "system",
    "generation_attempts": 1,
    "correction_reason": null,
    "cancellation_reason": null
  }
}
```

### 11.6 Archival Strategy

| Age | Storage | Action |
|-----|---------|--------|
| 0-2 years | Primary database | Full access, all indexes, standard queries |
| 2-5 years | Primary database | Warmed up (less frequently accessed). Queries work but may be slower. |
| 5+ years | Cold storage (S3 Glacier / archival) | Monthly cron: Move invoice records to archival storage. `data` JSON + structured columns serialized to JSON file. PDF moved to Glacier. Invoices table keeps a lightweight record (id, order_id, invoice_number, generated_at, archival_path). |

**Archival process:**
```php
// Monthly: archive invoices older than 5 years
$archivable = Invoice::where('generated_at', '<', now()->subYears(5))->get();

foreach ($archivable as $invoice) {
    // 1. Export full record + PDF to archival storage
    $path = ArchivalService::archive($invoice);

    // 2. Keep minimal metadata in main DB (lightweight)
    $invoice->update([
        'data' => null,
        'pdf_path' => null,
        'archival_path' => $path,
        'archived_at' => now(),
    ]);
}
```

### 11.7 Soft Deletes

**Invoices should NOT use soft deletes.** Financial records must never be deleted. If an invoice should not have been created:

1. **Cancel it** (set `status = cancelled`, `cancelled_at`, `cancellation_reason`)
2. **Create a correction** if financial adjustments are needed

Hard deletion of financial records creates audit gaps and may violate legal/regulatory requirements.

---

## 12. Long-Term Scalability

### 12.1 Evaluation of Future Requirements

| Future Requirement | Supported in V2? | Changes Needed |
|--------------------|-----------------|----------------|
| **Multiple payment providers** | ✅ Yes | The `payment_gateway` column already stores the gateway name. Snapshot stores gateway details. No schema change needed. The invoice references the `transaction` FK which already supports any gateway via the existing `PaymentGatewayFactory` pattern. |
| **Partial payments** | ⚠️ Partial | V2 assumes one payment per order (1:1 invoice to transaction). For partial payments, the `amount_paid` column would need to represent cumulative paid amount, and multiple transaction references may be needed. Solution: create a `invoice_transactions` pivot table to support N:M relationship. |
| **Split payments** | ⚠️ Partial | Similar to partial payments. If an order is paid via COD + wallet + online, multiple transactions exist. Solution: either (a) aggregate into one invoice with `amount_paid` = total, or (b) create separate invoices per payment method. Option (a) is recommended: one invoice per order regardless of payment splits. The `payment_method` and `payment_gateway` columns would need to become JSON arrays or a separate table. |
| **Partial shipments** | ✅ Yes | Partial fulfillment does not affect the invoice. The invoice represents the financial transaction, not the fulfillment status. Fulfillment is tracked via the `orders` and `order_products` tables, which are outside the invoice module's responsibility. |
| **Partial refunds** | ✅ Yes | Handled via correction invoices. Create a correction invoice with the adjusted amounts. The `correction_reason` documents why. |
| **Multiple invoices per order** | ⚠️ Not in V2 | The `UNIQUE(order_id)` constraint enforces 1:1. To support multiple invoices per order (e.g., progressive invoicing for subscriptions), remove the unique constraint and add `debtor_invoice_number` or similar business identifier. The sequence table supports unlimited numbers per series+year. |
| **Credit notes** | ✅ Yes | Same mechanism as correction invoices. Use series `CN` for credit notes. The `invoice_series` column supports any series prefix. |
| **Debit notes** | ✅ Yes | Same as credit notes. Use series `DN`. |
| **Tax invoices** | ⚠️ Needs additions | Two changes required: (a) Add `tax_amount`, `tax_rate`, `tax_type` structured columns to the invoices table (or use generated columns from JSON). (b) Add tax breakdown to the JSON snapshot's `taxes` array. The schema already includes a `taxes` array in the JSON snapshot — adding structured columns is additive and backward compatible. |
| **International currencies** | ✅ Yes | The `currency` column and snapshot structure support any ISO 4217 currency. Exchange rate at time of payment can be stored in the snapshot's `pricing_breakdown`. No schema change needed. |
| **Multi-tenant deployments** | ✅ Yes | Add a `tenant_id` column as a FK and include it in the primary key of `invoice_sequences` (to allow per-tenant sequences). The status is tenant-scoped. The unique constraint on `invoice_number` would need to become `UNIQUE(tenant_id, invoice_number)`. All indexes should include `tenant_id` as the leading column. |

### 12.2 Architectural Resilience Assessment

| Quality | Assessment |
|---------|------------|
| **Decoupling** | Invoice module has zero runtime dependencies on pricing, coupon, promotion, or inventory services. It reads only committed data. |
| **Idempotency** | UNIQUE(order_id) + application check + queue middleware provide defense in depth. |
| **Failure isolation** | Invoice generation failure never affects payment, order, or customer experience. The customer gets their order regardless of invoice status. |
| **Scalability bottleneck** | The `invoice_sequences` table with `lockForUpdate()` is the only serialization point. At 1,000 transactions/second, this is sufficient. If scaling beyond that, pre-reserve number ranges per worker or use a distributed sequence service (e.g., Redis INCR, Snowflake). |
| **Data growth** | Structured columns are small and efficiently indexed. JSON column stores the bulk of historical data. Archival strategy after 5 years keeps the primary table manageable. |
| **Backward compatibility** | All API responses are additive. No existing endpoint is modified. The invoice endpoints are entirely new. |

### 12.3 Recommended Evolution Path

```
V2 (current)              V2.1                       V3
─────────────────  ─────────────────  ─────────────────
1:1 invoice:order  1:N invoice:order  Multi-entity
Single payment     Partial payments   International tax
Simple PDF         Tax fields          ZATCA compliance
                   Multiple currencies  Multi-tenant
                   Pivot transactions   Invoice webhook
```

Each step is additive. No V2 schema changes need to be rolled back. New columns are added with defaults, new indexes are added online (MySQL 8.0+), and new API fields are appended to the existing resources.

---

## 13. Architecture Compliance

### 13.1 Integration With Existing Patterns

| Pattern | This Design |
|---------|-------------|
| **Service layer** | `InvoiceSnapshotService`, `InvoicePdfService`, `InvoiceNumberService` follow constructor injection, single responsibility. |
| **Event-driven** | Listens to `PaymentSucceeded` via `DB::afterCommit()`. Does not introduce new events into the order lifecycle. |
| **Transaction safety** | Invoice creation owns its own transaction. Never extends the payment transaction. |
| **Queued jobs** | `GenerateInvoiceListener` (ShouldQueue), `GenerateInvoicePdfJob` (ShouldQueue) follow the existing `LogActivityJob` pattern. |
| **Resources** | `InvoiceResource` / `InvoiceCollection` follow existing resource pattern in `app/Http/Resources/`. |
| **Policies** | `InvoicePolicy` follows existing authorization pattern. |
| **Controller purity** | `InvoiceController` only receives request, calls service, returns resource. |
| **Model purity** | `Invoice` model has no business logic — only relationships, casts, and fillable. |
| **Immutable snapshot** | JSON column stores all pricing/promotion/coupon data — no live reads. |
| **Zero duplication** | Snapshot service reads existing `Order` and `OrderProduct` fields — no recalculation. |

### 13.2 What This Design Does NOT Do

- Does not duplicate `ProductPricingService` — pricing already calculated at order creation
- Does not duplicate `CouponCalculator` — coupon discount already stored in `orders.coupon_discount`
- Does not duplicate `PromotionService` — promotion discount already stored in `orders.promotion_discount`
- Does not modify any existing order/payment/coupon flow
- Does not introduce new payment states
- Does not refactor the frozen pricing architecture (ADR-001)
- Does not create parallel billing logic
- Does not make Invoice depend on live data from Products, Promotions, Coupons, or FlashSales

---

## 14. Files Inventory

### 14.1 Files to Create

| # | File | Responsibility |
|---|------|----------------|
| 1 | `database/migrations/2026_07_16_000001_create_invoice_sequences_table.php` | Sequence number generation table |
| 2 | `database/migrations/2026_07_16_000002_create_invoices_table.php` | Main invoices table |
| 3 | `app/Models/Invoice.php` | Eloquent model (relationships, casts, no business logic) |
| 4 | `app/Models/InvoiceSequence.php` | Sequence model |
| 5 | `app/Enums/InvoiceStatus.php` | Status enum: Pending, Generated, PdfGenerating, Ready, Failed, Corrected, Cancelled |
| 6 | `app/Services/Invoice/InvoiceSnapshotService.php` | Builds immutable JSON snapshot from order data |
| 7 | `app/Services/Invoice/InvoiceNumberService.php` | Generates sequential invoice numbers with lockForUpdate |
| 8 | `app/Services/Invoice/InvoicePdfService.php` | Generate and regenerate PDF documents |
| 9 | `app/Listeners/GenerateInvoiceListener.php` | ShouldQueue listener for PaymentSucceeded |
| 10 | `app/Jobs/GenerateInvoicePdfJob.php` | Queued PDF generation |
| 11 | `resources/views/pdf/invoice.blade.php` | New PDF template reading from $invoice->data |
| 12 | `app/Http/Controllers/Api/General/InvoiceController.php` | Customer + admin invoice API |
| 13 | `app/Http/Resources/Invoice/InvoiceResource.php` | Full invoice resource |
| 14 | `app/Http/Resources/Invoice/InvoiceCollection.php` | List invoice resource |
| 15 | `app/Policies/InvoicePolicy.php` | Authorization |
| 16 | `app/Console/Commands/GeneratePendingInvoicePdfs.php` | Scheduled: dispatch pending PDF generation |
| 17 | `app/Console/Commands/RegenerateFailedInvoicePdfs.php` | Scheduled/on-demand: retry failed PDFs |
| 18 | `app/Events/InvoiceCreated.php` | Event for external integrations |
| 19 | `app/Events/InvoiceReady.php` | Event for notification when PDF is ready |

### 14.2 Files to Modify

| # | File | Change | Risk |
|---|------|--------|------|
| 1 | `app/Providers/EventServiceProvider.php` | Add `PaymentSucceeded => [GenerateInvoiceListener::class]` | Low — additive change |
| 2 | `app/Services/General/OrderService.php` | Move `event(PaymentSucceeded)` inside `DB::afterCommit()` in `changeOrderStatus()` and remove from `markCodAsPaid()` / `markCashierPaid()` | Medium — changes existing event dispatch timing. Requires careful testing. |
| 3 | `app/Http/Controllers/Api/General/OrderController.php` | Remove `event(new PaymentSucceeded(...))` from `checkoutCallback()` — event is now dispatched from `changeOrderStatus()` | Low — removes duplicate dispatch |
| 4 | `packages/marvel/src/Database/Models/Order.php` | Add `governorate()` BelongsTo relationship for snapshot address resolution | Low — additive relationship |
| 5 | `routes/api.php` | Add invoice routes under `general` prefix | Low — additive |
| 6 | `app/Providers/AuthServiceProvider.php` | Register `InvoicePolicy` | Low — additive |
| 7 | `app/Console/Kernel.php` | Schedule `invoices:generate-pending` every 5 min and `invoices:regenerate-failed-pdfs` every 30 min | Low — additive |

### 14.3 Files NOT Modified

- `app/Services/Payment/PaymentCheckoutHandler.php` — No changes needed
- `app/Services/Payment/PaymentGatewayFactory.php` — No changes needed
- `app/Services/Gateway/MyFatoorahGateway.php` — No changes needed
- `packages/marvel/src/Services/Pricing/ProductPricingService.php` — Frozen architecture (ADR-001)
- `app/Services/Coupon/CouponCalculator.php` — No changes needed
- `app/Services/Coupon/CouponOrchestrator.php` — No changes needed
- `app/Services/General/PromotionService.php` — No changes needed
- `app/Http/Resources/Order/OrderResource.php` — No changes needed
- `app/DTOs/CheckoutTotals.php` — No changes needed
- Legacy invoice views in `resources/views/pdf/order-invoice.blade.php` — Kept for backward compatibility during transition

---

## 15. Observability and Operations

### 15.1 Metrics to Track

Every invoice generation is a mission-critical financial operation. The following metrics must be tracked in production.

#### 15.1.1 Invoice Generation Metrics

| Metric | Type | Source | Alert Threshold | Why |
|--------|------|--------|-----------------|-----|
| `invoice.generation.attempts` | Counter | GenerateInvoiceListener start | — | Total invoice generation attempts |
| `invoice.generation.success` | Counter | Invoice::create() success | — | Successful invoice creations |
| `invoice.generation.failed` | Counter | Listener exception | > 0 in 5 min | Invoice creation failures |
| `invoice.generation.duration` | Histogram | Listener start→end (seconds) | p99 > 5s | Snapshot assembly performance |
| `invoice.generation.duplicate_skipped` | Counter | Idempotency check hit | — | How often duplicate events are safely skipped |

#### 15.1.2 PDF Generation Metrics

| Metric | Type | Source | Alert Threshold | Why |
|--------|------|--------|-----------------|-----|
| `invoice.pdf.attempts` | Counter | GenerateInvoicePdfJob start | — | Total PDF generation attempts |
| `invoice.pdf.success` | Counter | PDF stored + invoice updated | — | Successful PDF generations |
| `invoice.pdf.failed` | Counter | Job exception | > 0 in 15 min | PDF generation failures |
| `invoice.pdf.duration` | Histogram | Job start→end (seconds) | p99 > 30s | PDF rendering performance |
| `invoice.pdf.retries` | Counter | Job released back to queue | > 3 per invoice | Jobs requiring multiple attempts |
| `invoice.pdf.invoice_id` | Gauge | Invoice without PDF older than 5 min | > 10 | Invoices stuck in "generated" state |
| `invoice.pdf.failed_count` | Gauge | Invoice with status = failed | > 5 | Invoices requiring manual intervention |

#### 15.1.3 Queue Metrics

| Metric | Type | Source | Alert Threshold | Why |
|--------|------|--------|-----------------|-----|
| `queue.payment_succeeded.latency` | Histogram | Event dispatch→listener start | p99 > 60s | Delay between payment and invoice generation |
| `queue.pdf.latency` | Histogram | Job dispatch→job start | p99 > 120s | Delay between invoice creation and PDF generation |
| `queue.size.medium` | Gauge | Queue length for `medium` queue | > 1000 | Invoice listener backlog |
| `queue.size.pdf` | Gauge | Queue length for `pdf` queue | > 500 | PDF job backlog |

### 15.2 Logging Strategy

Every invoice operation must produce structured logs for debugging and audit.

#### 15.2.1 Correlation ID

A single correlation ID must trace across the entire invoice lifecycle:

```
PaymentSucceeded (event)
  └── correlation_id: "inv_ord_42_1710527400"
        │
        ├── GenerateInvoiceListener
        │     └── Log::info('invoice.generation.started', ['correlation_id', 'order_id', 'order_number'])
        │
        ├── Invoice::create()
        │     └── Log::info('invoice.generation.succeeded', ['correlation_id', 'invoice_id', 'invoice_number'])
        │
        ├── GenerateInvoicePdfJob
        │     └── Log::info('invoice.pdf.started', ['correlation_id', 'invoice_id'])
        │
        └── PDF stored
              └── Log::info('invoice.pdf.succeeded', ['correlation_id', 'invoice_id', 'pdf_path', 'pdf_checksum'])
```

**Correlation ID generation**: `sprintf('inv_ord_%d_%d', $order->id, now()->timestamp)`

#### 15.2.2 Log Event Catalog

| Log Event | Level | Data | When |
|-----------|-------|------|------|
| `invoice.generation.started` | info | correlation_id, order_id, order_number | Listener starts processing |
| `invoice.generation.duplicate_skipped` | info | correlation_id, order_id, existing_invoice_id | Idempotency check hit |
| `invoice.generation.succeeded` | info | correlation_id, invoice_id, invoice_number, duration_ms | Invoice record created |
| `invoice.generation.failed` | error | correlation_id, order_id, exception_message, stack_trace | Listener exception |
| `invoice.generation.idempotency_violation` | warning | correlation_id, order_id, invoice_id | Unique constraint caught a duplicate that application check missed |
| `invoice.number.generated` | info | series, year, sequence, invoice_number | New sequence number assigned |
| `invoice.pdf.started` | info | correlation_id, invoice_id, attempt | PDF job starts |
| `invoice.pdf.succeeded` | info | correlation_id, invoice_id, pdf_path, pdf_checksum, duration_ms | PDF stored successfully |
| `invoice.pdf.failed` | error | correlation_id, invoice_id, attempt, exception_message | PDF job failed |
| `invoice.pdf.retry_exhausted` | error | correlation_id, invoice_id, attempts=3 | PDF job exhausted all retries |
| `invoice.pdf.regenerated` | info | invoice_id, admin_id, reason | Admin triggered regeneration |
| `invoice.correction.created` | info | original_invoice_id, correction_invoice_id, admin_id, reason | Correction invoice issued |
| `invoice.cancelled` | info | invoice_id, admin_id, reason | Invoice cancelled |

#### 15.2.3 Log Context Enrichment

Every invoice-related log entry MUST include these context fields:

```php
Log::withContext([
    'component' => 'invoice',
    'correlation_id' => $correlationId,
    'order_id' => $order->id,
    'invoice_id' => $invoice?->id,
    'environment' => config('app.env'),
]);
```

### 15.3 Alerting Strategy

| Alert | Condition | Severity | Response |
|-------|-----------|----------|----------|
| Invoice generation failing | `invoice.generation.failed` > 0 in 5 minutes | **Critical** | On-call engineer investigates. Customer payments are succeeding but invoices are not being generated. |
| Invoice generation slow | `invoice.generation.duration` p99 > 5s | Warning | Snapshot assembly is taking too long. Check for N+1 queries or missing eager loads. |
| PDF generation failing | `invoice.pdf.failed` > 0 in 15 minutes | **Critical** | On-call engineer investigates. Invoice data exists but customers cannot download PDFs. |
| PDF generation slow | `invoice.pdf.duration` p99 > 30s | Warning | PDF rendering is slow. Check memory limits, blade template complexity, or dompdf version. |
| Stuck invoices | `invoice.pdf.invoice_id` (generated > 5 min ago, no PDF) > 10 | **Critical** | Invoices exist but PDF jobs are not being processed. Check queue worker health. |
| Failed invoices accumulating | `invoice.pdf.failed_count` > 5 | Warning | Multiple invoices require manual PDF regeneration. Run the regeneration command or investigate root cause. |
| Queue backlog | `queue.size.medium` > 1000 or `queue.size.pdf` > 500 | Warning | Queue workers are falling behind. Scale up workers. |

### 15.4 Operational Dashboards

#### 15.4.1 Grafana Panel: Invoice System Health

```
Row 1: Generation Rate
  ├── Panel: Invoice Created / sec (sparkline, 1h window)
  └── Panel: Invoice Generation Success Rate % (current + 7d trend)

Row 2: Failure Overview
  ├── Panel: Failed Generations (last 1h) — bar chart
  ├── Panel: Failed PDF Generations (last 1h) — bar chart
  └── Panel: Stuck Invoices (in generated > 5min) — single stat

Row 3: Latency
  ├── Panel: Invoice Generation Duration p50/p95/p99 (histogram)
  └── Panel: PDF Generation Duration p50/p95/p99 (histogram)

Row 4: Queue Health
  ├── Panel: PaymentSucceeded Queue Latency (p99)
  ├── Panel: PDF Queue Latency (p99)
  └── Panel: Queue Depth (medium, pdf) — gauge

Row 5: Accumulated Totals
  ├── Panel: Total Invoices (lifetime)
  ├── Panel: Invoices by Status (pie: ready, failed, corrected, cancelled)
  └── Panel: Invoices Without PDF (single stat)
```

#### 15.4.2 Laravel Horizon Dashboard

Configure Laravel Horizon to monitor:

| Queue | Jobs | Alert When |
|-------|------|------------|
| `medium` | `GenerateInvoiceListener` | Wait time > 60s |
| `pdf` | `GenerateInvoicePdfJob` | Wait time > 120s |
| `failed` | Any invoice job | Count > 0 |

#### 15.4.3 Health Check Endpoint

```php
// GET /api/general/admin/invoices/health
{
  "healthy": true,
  "checks": {
    "recent_invoices": {
      "last_5_min": 12,
      "passed": true
    },
    "stuck_invoices": {
      "count": 0,
      "passed": true
    },
    "failed_pdfs": {
      "count": 0,
      "passed": true
    },
    "queue_latency": {
      "payment_succeeded_queue": "0.5s",
      "pdf_queue": "1.2s",
      "passed": true
    }
  },
  "last_check": "2026-07-15T10:35:00Z"
}
```

### 15.5 Runbook: Common Operational Procedures

| Procedure | Steps |
|-----------|-------|
| **Investigate missing invoice** | 1. Check `failed_jobs` table for `GenerateInvoiceListener` failures. 2. Check logs for `invoice.generation.failed`. 3. Verify `PaymentSucceeded` was dispatched (check queue logs or Order status). 4. If order was completed but no invoice exists, run `php artisan invoices:generate --order-id={id}` to manually trigger generation. |
| **Regenerate all PDFs for failed invoices** | `php artisan invoices:regenerate-failed-pdfs` |
| **Regenerate a single PDF manually** | Admin API: `POST /api/general/admin/invoices/{id}/regenerate-pdf` |
| **Force regenerate all PDFs (e.g., after template update)** | `php artisan invoices:regenerate-all-pdfs` (first create this command; use with caution — this regenerates all PDFs) |
| **Check invoice system health** | `php artisan invoices:health` (CLI version of health check) |
| **Manually create invoice for historical order** | `php artisan invoices:generate --order-id=42` (creates invoice from existing order data; runs snapshot service) |
| **Cancel an incorrectly generated invoice** | Admin API: `POST /api/general/admin/invoices/{id}/cancel { reason }` |
| **Audit invoice data integrity** | `php artisan invoices:audit` (checks: all completed orders have invoices, all invoices have valid JSON data, all PDF checksums match stored files) |

---

## 16. Architecture Freeze

The Invoice System Architecture V2 (this document) is officially frozen.

Architectural changes are allowed only under these conditions:

1. **Verified production bug** — A bug in production proves the current architecture produces incorrect invoice data.
2. **Failing automated test** — An existing test proves the current design is incorrect.
3. **New business requirement** — A requirement cannot be implemented while preserving the current architecture (e.g., subscription invoicing with N invoices per order, or regulatory requirement like ZATCA).

The following are NOT valid reasons for changing the architecture:

- Personal preference
- Code style preferences
- Theoretical improvements without measurable impact
- Desire to introduce unnecessary abstraction
- "It would look cleaner in a different pattern"

**Design freeze scope**: Transaction boundaries (Section 1), Invoice lifecycle (Section 2), Hybrid data model (Section 3), Immutable snapshot contract (Section 3.7), Invoice data integrity (Section 3.8), Financial invariants (Section 3.9), **V1 Snapshot Schema (Section 3.10 — field-level contract)** , Invoice number generation (Section 4), Exactly-once processing (Section 5), Aggregate boundaries (Section 7), Read-only consumer principle (Section 7.4), Invoice data sources (Section 7.5), PDF source of truth (Section 7.6), Snapshot validation pipeline (Section 7.7), Observability (Section 15), Legal and compliance guarantees (Section 18), Snapshot completeness (Appendix A).

**V1 snapshot schema is frozen at `snapshot_schema = 2`**. The following fields are explicitly excluded from V1 and may only be added in a future ADR with a schema version bump:

| Field | Required For V1? | Future Enhancement |
|-------|-----------------|-------------------|
| `customer.company_name` | ❌ Excluded | Requires `ALTER TABLE users ADD company_name` + `snapshot_schema` = 3 |
| `customer.tax_id` | ❌ Excluded | Requires `ALTER TABLE users ADD tax_id` + `snapshot_schema` = 3 |
| `fulfillment.tracking_number` | ❌ Excluded | Requires `ALTER TABLE orders ADD tracking_number` + `snapshot_schema` = 3 |

**Design areas open to improvement** (without full ADR): PDF template design, API response field additions, index tuning (add/remove indexes based on query patterns), archival strategy adjustments.

---

## 17. AI Agent Guidelines

AI agents modifying code in this project must obey the following rules when working with invoice-related code:

1. **Do not create new pricing services** — All pricing computation already happens in `ProductPricingService` (frozen per ADR-001). The invoice module reads already-computed prices from `OrderProduct` snapshots.

2. **Do not move invoice business logic into Resources** — `InvoiceResource` is a serialization layer only. It must never build snapshots, calculate totals, or determine status transitions.

3. **Do not move invoice business logic into Models** — `Invoice` model is a data container with relationships and casts only. No status machine logic, no snapshot assembly, no sequence number generation.

4. **Do not create parallel invoice flows** — All invoice generation goes through `GenerateInvoiceListener`. No direct `Invoice::create()` calls from controllers, other listeners, or services.

5. **Do not extend the payment transaction** — Invoice creation must never be inside the same database transaction as payment completion. Use `DB::afterCommit()` to dispatch `PaymentSucceeded`.

6. **Respect the frozen architecture (Section 16)** — Do not suggest architectural refactoring of the invoice system without meeting the three criteria in Section 16.

7. **When in doubt, check existing services first** — If the required data exists in `Order` or `OrderProduct` snapshots, use it. Do not query live product, promotion, or coupon tables.

8. **Never modify Order or Transaction from the invoice module** — The invoice module is a read-only consumer. If order data is needed for an invoice feature, request that the data be snapshotted at order creation time.

---

## 18. Legal and Compliance Guarantees

This section documents the financial and legal guarantees provided by the Invoice Architecture. These guarantees are architectural invariants — they are not configurable and must not be disabled.

### 18.1 Immutable Financial Records

| Guarantee | Mechanism | Legal Rationale |
|-----------|-----------|-----------------|
| Once written, invoice data never changes | `data` JSON column is written once by `InvoiceSnapshotService`. No update path exists for the `data` column. The model has no `updateData()` method. | Financial records must be tamper-evident. If a regulator requests the original invoice, the stored snapshot must match what was generated at time of payment. |
| Corrections create new records | Correction invoices are separate records referencing the original via `correction_to_id`. The original is never modified. | Corrections must be additive, not destructive. The audit trail must show both the original and the corrected version. |
| Deletion is prevented | `ON DELETE RESTRICT` on FKs. No soft deletes. No hard deletes. The only status changes are `status` transitions. | Financial records must be preserved per legal retention requirements (typically 5-10 years depending on jurisdiction). |

### 18.2 Audit Preservation

| Guarantee | Mechanism |
|-----------|-----------|
| Every invoice has a creation timestamp | `generated_at` (NOT NULL, set at creation) |
| Every invoice identifies the generator | `generated_by` (default `'system'`, or admin user ID for manual operations) |
| Every state transition is logged | Structured logs with correlation ID trace through the entire lifecycle (see §15.2) |
| Every correction is linked to its original | `correction_to_id` FK creates an auditable chain of corrections |
| Every cancellation is documented | `cancelled_at` and `cancellation_reason` must be set when cancelling |
| Every PDF generation attempt is tracked | `generation_attempts` counter, `last_generation_error` for failures |

### 18.3 Correction Instead of Modification

> **It is architecturally impossible to modify an existing invoice.**

If an invoice contains incorrect data:

1. The incorrect invoice remains in the database as-is (immutable record).
2. A new correction invoice is created referencing the original via `correction_to_id`.
3. The original invoice's status transitions to `corrected`.
4. The correction invoice contains the corrected data and the reason for correction.

This is a legal requirement in most jurisdictions. Modifying a financial record after creation is equivalent to falsification.

### 18.4 No Destructive Deletion

| Action | Allowed? | Mechanism |
|--------|----------|-----------|
| Hard delete invoice row | ❌ Never | Not implemented. FKs use `ON DELETE RESTRICT`. |
| Soft delete invoice | ❌ Never | Model does not use `SoftDeletes` trait. |
| Cancel invoice | ✅ Allowed | Status changes to `cancelled`. Record persists. |
| Archive invoice | ✅ Allowed | Data moved to cold storage after 5+ years (see §11.6). Lightweight metadata remains in main DB. |

### 18.5 Historical Reproducibility

> Given an invoice record, the exact state of the transaction at the time of payment can be reconstructed without querying any live business data.

This is guaranteed by:

1. **Self-contained snapshot**: The `data` JSON column contains every piece of business data needed to understand the invoice.
2. **No live dependencies**: The invoice is rendered exclusively from `$invoice->data`. It never queries live products, prices, discounts, or customer profiles.
3. **PDF regeneration**: The PDF can be regenerated at any time from the stored snapshot. The regenerated PDF is functionally identical to the original (same data, same layout for the same template version).
4. **Snapshot integrity**: `snapshot_hash` verifies the JSON has not been tampered with (see §3.8).

### 18.6 Sequential Invoice Numbering

| Property | Guarantee |
|----------|-----------|
| Monotonic | Sequence numbers within a `series+year` always increase |
| Unique | `UNIQUE(invoice_number)` constraint at database level |
| Traceable | Gaps are logged and acceptable (see §4.5) |
| Audit-compliant | The sequence table with `lockForUpdate()` provides gapless-ish numbering suitable for tax authority requirements in most jurisdictions |

### 18.7 Long-Term Archival Guarantees

| Age | Guarantee | Mechanism |
|-----|-----------|-----------|
| 0-2 years | Full online access, sub-second queries | Primary database with all indexes |
| 2-5 years | Online access, slower queries | Primary database (warmed data) |
| 5-10 years | Offline archival, 24-hour restoration SLA | Cold storage (S3 Glacier). Lightweight metadata in DB. Restoration via `php artisan invoices:restore {id}` |
| 10+ years | Offline archival, 7-day restoration SLA | Deep archive (S3 Glacier Deep Archive). Request-based restoration. |

### 18.8 Regulatory Compliance Checklist

| Requirement | Status | Notes |
|-------------|--------|-------|
| Invoice records immutable after creation | ✅ Enforced | No update path for `data` column |
| Corrections create new records | ✅ Enforced | Correction invoices with audit chain |
| Deletion prohibited | ✅ Enforced | `ON DELETE RESTRICT`, no soft deletes |
| Sequential numbering | ✅ Enforced | Sequence table with `lockForUpdate()` |
| Generation timestamp | ✅ Enforced | `generated_at` NOT NULL |
| Generator identification | ✅ Enforced | `generated_by` column |
| PDF available for download | ✅ Enforced | Stored with checksum verification |
| Historical reproducibility | ✅ Enforced | Self-contained JSON snapshot |
| Data retention > 5 years | ✅ Enforced | Archival strategy with cold storage |
| Audit trail for corrections | ✅ Enforced | `correction_to_id`, `correction_reason`, `corrected_at` |
| Anti-tampering integrity | ✅ Enforced | `snapshot_hash` (SHA-256) + `pdf_checksum` (SHA-256) |

---

## Appendix A: Snapshot Completeness Checklist

Every invoice snapshot stored in the `data` JSON column must contain the following sections and fields. This checklist must be verified during code review of `InvoiceSnapshotService`.

### A.1 Snapshot Root

| Field | Type | Required | Source | Notes |
|-------|------|----------|--------|-------|
| `snapshot_version` | string | ✅ | Hardcoded constant | SemVer format. Current: `2.0.0` |
| `snapshot_schema` | integer | ✅ | Hardcoded constant | Current: `2` |
| `customer` | object | ✅ | See A.2 | — |
| `billing_address` | object | ✅ | See A.3 | — |
| `shipping_address` | object | ✅ | See A.3 | — |
| `fulfillment` | object | ✅ | See A.4 | — |
| `pickup_location` | object\|null | ✅ | See A.5 | Null if fulfillment is delivery |
| `items` | array | ✅ | See A.6 | Array of line item objects |
| `pricing_breakdown` | object | ✅ | See A.7 | — |
| `payment` | object | ✅ | See A.8 | — |
| `taxes` | array | ✅ | See A.9 | Empty array if no tax |
| `metadata` | object | ✅ | See A.10 | — |
| `notes` | string\|null | ✅ | `$order->notes` | Free-text order notes |
| `audit` | object | ✅ | See A.11 | — |

### A.2 Customer Snapshot

| Field | Type | Source | Notes |
|-------|------|--------|-------|
| `id` | integer | `$order->user_id` | References user at time of order |
| `name` | string | `$order->name` | Already snapshotted in orders table |
| `email` | string | `$order->user_email` | Already snapshotted |
| `phone` | string | `$order->user_phone` | Already snapshotted |

**V1 constraint**: `company_name` and `tax_id` are NOT available (no columns exist on `orders` or `users` tables). These fields are reserved for a future V2 enhancement that requires a database migration to add the columns to the users table, followed by a minor schema version bump (`snapshot_schema` 2 → 3).

**Never**: Query `users` table directly. Read from order snapshot fields (`$order->name`, `$order->user_email`, `$order->user_phone`).

### A.3 Address Snapshot

| Field | Type | Source | Notes |
|-------|------|--------|-------|
| `street` | string | `$order->address['street']` | From `orders.address` JSON |
| `city` | string | `$order->address['city']` | — |
| `state` | string\|null | `$order->address['state']` | Optional |
| `governorate` | string | From `Governorate` model via `$order->governorate_id` | Resolve at generation time from governorate_id |
| `zip` | string\|null | `$order->address['zip']` | Optional |
| `country` | string | `$order->address['country']` | — |
| `coordinates` | object\|null | `$order->address['coordinates']` | Optional lat/lng |

**Governorate resolution**: The `$order->governorate_id` FK exists, but the `Order` model does NOT currently define a `belongsTo(Governorate::class)` relationship. To resolve the governorate name in the snapshot, one of the following approaches must be implemented:

- **Approach A (Recommended — Add relationship to Order model)**: Add `public function governorate(): BelongsTo { return $this->belongsTo(Governorate::class); }` to `Order` model. Then eager load `governorate` before snapshot assembly and read `$order->governorate->name`.
- **Approach B (Manual resolution)**: In `InvoiceSnapshotService`, manually query `Governorate::find($order->governorate_id)?->name`. This bypasses Eloquent relationships and is less maintainable.

**Verdict**: Approach A is preferred. Add the relationship to the Order model as part of the invoice system implementation. The governorate name is then stored in the snapshot at generation time.

**Never**: Query `governorates` table on every invoice. Resolve the governorate name once and store it in the snapshot.

### A.4 Fulfillment Snapshot

| Field | Type | Source | Notes |
|-------|------|--------|-------|
| `type` | string | `$order->fulfillment_type` | `delivery` or `pickup` |
| `shipping_method` | string | `$order->shipping_method` | e.g., `SCHEDULED`, `FAST` |
| `shipping_price` | decimal | `$order->shipping_price` | Already snapshotted |
| `expected_delivery_at` | string\|null | `$order->expected_delivery_at` | ISO 8601 format |

**V1 constraint**: `tracking_number` is NOT available (no column exists on `orders` table). This field is reserved for a future V2 enhancement that requires a database migration to add a `tracking_number` column to the `orders` table.

### A.5 Pickup Location Snapshot

| Field | Type | Source | Notes |
|-------|------|--------|-------|
| `id` | integer\|null | `$order->pickup_location_id` | Nullable |
| `name` | string | `$order->pickup_location_name` | Already snapshotted |
| `address` | string | `$order->pickup_location_address` | Already snapshotted |
| `phone` | string | `$order->pickup_location_phone` | Already snapshotted |
| `coordinates` | string\|null | `$order->pickup_location_coordinates` | Already snapshotted |

**Never**: Query `pickup_locations` table. All data already stored in `orders` table snapshot fields.

### A.6 Line Items Snapshot (Array)

Each element in the `items` array:

| Field | Type | Source | Notes |
|-------|------|--------|-------|
| `product_id` | integer | `$item->product_id` | Reference only — not used for pricing |
| `product_variant_id` | integer\|null | `$item->product_variant_id` | Nullable for simple products |
| `product_name` | string | `$item->product_name` | Already snapshotted in `order_products` |
| `product_sku` | string\|null | `$item->product_sku` | Already snapshotted |
| `attributes` | object\|null | `$item->attributes` | Already snapshotted JSON (e.g., `{"color": "Black"}`) |
| `quantity` | integer | `$item->product_quantity` | Already snapshotted |
| `unit_price` | decimal | `$item->product_price` | Already snapshotted — the price of one unit at time of order |
| `total_price` | decimal | `$item->product_total_price` | Already snapshotted — unit_price × quantity |
| `original_price` | decimal | `$item->product_price` | Same as unit_price; preserved for reference even if discount applied |
| `discount_price` | decimal\|null | `$item->product_discount_price` | Already snapshotted — discount per unit (if applicable) |
| `flash_sale_price` | decimal\|null | `$item->product_flash_sale_price` | Already snapshotted — flash sale per unit (if applicable) |
| `promotion_discount_amount` | decimal\|null | `$item->promotion_discount_amount` | Already snapshotted — total promotion discount for this line |
| `is_gift` | boolean | `$item->is_gift` | Already snapshotted |
| `promotion_id` | integer\|null | `$item->promotion_id` | Reference only |
| `images` | array | See note | Snapshot product image URLs from `$item->product->media` at time of generation. **Must be eager loaded** — never lazy load. |

**Never**: Recalculate `unit_price × quantity`. Use `$item->product_total_price` (already computed at order creation).

**Image handling**: Load `$item->product->media` relation once (eager loaded) and extract thumbnail URLs. Store only URLs, never binary data. Accept that images may break if CDN removes old files — this is acceptable for historical invoices.

### A.7 Pricing Breakdown Snapshot

| Field | Type | Source | Notes |
|-------|------|--------|-------|
| `subtotal` | decimal | `$order->price` | Already snapshotted |
| `promotion_discount` | decimal | `$order->promotion_discount` | Already snapshotted |
| `coupon_discount` | decimal | `$order->coupon_discount` | Already snapshotted |
| `shipping_price` | decimal | `$order->shipping_price` | Already snapshotted |
| `total` | decimal | `$order->total_price` | Already snapshotted |
| `currency` | string | Config or `$order->transaction->currency` | ISO 4217 |
| `exchange_rate` | decimal\|null | Config or transaction | 1.0 for base currency; populated only for foreign currency payments |
| `coupon` | object\|null | See below | Full coupon snapshot |
| `promotion` | object\|null | See below | Full promotion snapshot |

**Coupon sub-object:**

| Field | Type | Source |
|-------|------|--------|
| `code` | string | `$order->coupon` |
| `type` | string | `$order->coupon_discount_type` (e.g., `percentage`, `fixed`) |
| `discount` | decimal | `$order->coupon_discount` |
| `max_discount_amount` | decimal\|null | `$order->coupon_discount_max_amount` |

**Promotion sub-object:**

| Field | Type | Source |
|-------|------|--------|
| `id` | integer\|null | `$order->promotion_id` |
| `code` | string\|null | `$order->promotion_code` |
| `type` | string\|null | `$order->promotion_type` |
| `discount` | decimal | `$order->promotion_discount` |

**Never**: Call CouponCalculator, PromotionService, or query coupons/promotions tables. All data is already in order snapshot fields.

### A.8 Payment Snapshot

| Field | Type | Source | Notes |
|-------|------|--------|-------|
| `method` | string | `$order->payment_method` | e.g., `online`, `cod`, `pay_at_cashier` |
| `gateway` | string\|null | `$order->payment_gateway` | e.g., `myfatoorah`. Null for COD. |
| `transaction_id` | integer | `$transaction->id` | Internal transaction PK |
| `gateway_transaction_id` | string\|null | `$transaction->gateway_transaction_id` | External gateway reference |
| `paid_at` | string | `$transaction->paid_at` | ISO 8601 |
| `gateway_invoice_id` | string\|null | `$transaction->gateway_response['invoice_id']` | Gateway's own invoice ID (if available) |
| `gateway_response_summary` | string\|null | `$transaction->gateway_response` | Key-value summary from gateway; avoid storing full raw response |

**Never**: Query the payment gateway API. Read only from already-stored `Transaction` model.

### A.9 Tax Breakdown Snapshot

| Field | Type | Source | Notes |
|-------|------|--------|-------|
| `taxes` | array | Currently empty | Reserved for future tax implementation |
| Each tax: | | | |
| `name` | string | Tax config | e.g., `VAT`, `Sales Tax` |
| `rate` | decimal | Tax config | e.g., `14.00` for 14% VAT |
| `amount` | decimal | Computed from taxable amount × rate | — |
| `type` | string | Tax config | `inclusive` or `exclusive` |

**Current state**: The system does not yet implement tax calculation. The taxes array must be present (empty array `[]`) in the snapshot to ensure forward compatibility. When tax is added to the checkout flow, this section will be populated.

### A.10 Metadata Snapshot

| Field | Type | Source | Notes |
|-------|------|--------|-------|
| `system_version` | string | `config('app.version')` or hardcoded | Application version at generation time |
| `locale` | string | `app()->getLocale()` | Language used when generating |
| `ip_address` | string\|null | `request()->ip()` | IP of the user who triggered payment (if available in the listener context) |
| `user_agent` | string\|null | `request()->userAgent()` | User agent of the payer (if available) |
| `generated_at` | string | `now()->toIso8601String()` | Exact timestamp of snapshot creation |

### A.11 Audit Metadata (Root-Plus-Snapshot)

Stored both in structured DB columns AND repeated in JSON for self-contained snapshots:

| Field | Type | Source | Notes |
|-------|------|--------|-------|
| `generated_by` | string | `'system'` or admin ID | Who/what triggered the generation |
| `generation_attempts` | integer | Starts at `1` | Incremented on each PDF retry |
| `correction_reason` | string\|null | From correction request | Only for correction invoices |
| `cancellation_reason` | string\|null | From cancellation request | Only for cancelled invoices |

### A.12 Completeness Verification Rule

> A snapshot is complete only when every field in this checklist is present in the `data` JSON column with the correct type.

During code review of `InvoiceSnapshotService`, verify:

1. Every section (A.2 through A.11) is populated.
2. No field uses live data from Products, Variants, Coupons, Promotions, FlashSales, or Pricing services.
3. All financial amounts match the already-snapshotted values in `orders` and `order_products` — no recalculation.
4. The `snapshot_schema` is incremented when adding or restructuring fields.
5. The PDF template handles both the current and previous `snapshot_schema` versions.
