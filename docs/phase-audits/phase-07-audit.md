# Phase 07 — Invoice Lifecycle

## 1. Executive Verdict

**PASS WITH RESIDUAL RISKS.** The invoice subsystem is a well-structured document pipeline: locked idempotent generation, 6-validator snapshot pipeline, integrity hashing + HMAC verification, yearly gapless numbering under lock, model-level transition enforcement, permission-gated admin operations, owner-scoped/signed customer access, real mPDF rendering with Arabic shaping, and credit-note closure on refunds. Four historically recorded invoice gaps are fixed in current code (INV-4 paid-transaction filter, INV-12 refund credit note, GAP-1 debit-note permission, GAP-5 PDF placeholder). Residual risks: invoices are now generated on cancellation paths with `amount_paid = total` stamped on never-paid orders (accuracy question carried from Phase 01 F-03), and the 12 invoice suites were not executed here.

## 2. Phase Objective

Per `PHASE-07-INVOICE-LIFECYCLE.md` (source: `HEAD:docs/production-manual/PHASE-07-INVOICE-LIFECYCLE.md`, 1240 lines): specify the invoice state machine (12 states), generation pipeline, snapshot/validators/hash/numbering/timeline services, correction/cancel/credit/debit flows, PDF job, regeneration, HMAC verification, download, event wiring, schema, and edge cases.

## 3. Scope

**In scope:** invoice generation triggers, snapshot + validation + integrity + numbering, status machine + enforcement, correction/cancel/debit/credit flows, PDF rendering, customer verification/download, admin endpoints + permissions, timeline, refund interplay.

**Out of scope:** QR design specifics (Phase 08), payment completion (Phase 06), refund policy (Phase 10), shipment/order transitions (Phases 05/09).

## 4. What Was Supposed to Be Implemented

The manual claims: queued-only generation via `GenerateInvoiceListener` (snapshot → 6 validators → hash → locked numbering → create `generated` → timeline → afterCommit `InvoiceCreated` + PDF job → `ready`/`failed`); 12-state machine with model `saving`-hook enforcement; correction (new invoice, original → `corrected`); cancellation; credit/debit notes; PDF job (3 tries); regeneration; HMAC verification; download; event wiring table; schema; edge cases.

## 5. What Actually Exists

The manual's pipeline is accurate and present, with four evolutions:

1. **Generation has three entry points, not one**: synchronous generation inside `changeOrderStatus` on first-leave-pending (any target incl. `cancelled`), the queued `GenerateInvoiceListener` on `PaymentSucceeded` (now usually a no-op via the existing-invoice lock), and admin regeneration. All converge on the idempotent `InvoiceService::generateFromOrder` (`InvoiceService.php:22-90`).
2. **PDF is real**: mPDF engine with Arabic shaping/RTL, disk persistence, checksum posture (GAP-5 resolved); job tries 3, backoff [30,120,300], timeout 120 (`GenerateInvoicePdfJob.php:17-20+`).
3. **Debit-note permission exists**: `permission:ISSUE_DEBIT_NOTE` on `issueDebitNote` (GAP-1 resolved, `InvoiceController.php:40`).
4. **Status matrix evolved**: `GENERATED → READY` shortcut and `READY → PDF_GENERATING` retry loop added to `allowedTransitions()` (`InvoiceStatus.php`) beyond the manual's table; everything else matches.
5. **Refund closure**: `GenerateCreditNoteOnRefund` (queued high) finds the latest active invoice, generates the credit note, marks `corrected`, records timeline (INV-12 resolved).
6. **Paid-transaction accuracy**: both generation (`InvoiceService.php:41-44`) and snapshot (`InvoiceSnapshotService.php:11,106`) filter `status='paid'` (INV-4 resolved).

## 6. Architecture

```
TRIGGERS: changeOrderStatus first-leave-pending (sync) → GenerateInvoiceListener/PaymentSucceeded (queued backup) → admin regenerate
generateFromOrder (ONE DB::transaction):
  existing lock-guard → snapshot → 6 validators → hash → locked yearly number
  → paid-txn link → create(generated, verification HMAC) → timeline → afterCommit(InvoiceCreated → LogInvoiceCreated; PDF job)
PDF job (low queue): mPDF render → store → ready (+checksum/path) | fail → failed (retryable → pdf_generating)
LIFECYCLE OPS (permission-gated): correct (new invoice + original corrected) / cancel / debit note / regenerate
CREDIT: RefundApproved → GenerateCreditNoteOnRefund → credit note + corrected + timeline
CUSTOMER: myInvoices (owner-scoped) / verify UUID (HMAC) / signed view+download URLs (path-traversal-guarded streaming)
ENFORCEMENT: InvoiceStatus::allowedTransitions + model saving hook (bulk updates bypass — documented in-manual warning, still true)
```

## 7. Complete Execution Flow

1. **Generate**: any trigger → locked existing-check → `buildFullSnapshot` (order + items + taxes + currency + paid_at from paid txn) → `InvoiceSnapshotValidator` (structure, financial-invariant, currency, money, metadata, version) → `computeHash` → `generateNext` (yearly series, locked) → create with HMAC `hash(sha256, snapshotHash + app_key)` → `recordGenerated` → afterCommit fan-out.
2. **Render**: PDF job renders from snapshot data, stores under `invoices/`, marks `ready` (or `failed` → retry → `pdf_generating`).
3. **Serve**: admin show/index (permissions `VIEW_INVOICES/VIEW_INVOICE`); customer `myInvoices` (own rows), `verify` (HMAC compare via `hash_equals`), signed view/download (signature-gated, traversal-guarded, download recorded + timeline).
4. **Correct**: `correctInvoice` locks original, allows only correctable statuses, creates superseding invoice, marks original `corrected` with reason/admin/timeline.
5. **Cancel**: `cancel` with `CANCEL_INVOICE` permission, terminal-ward transition.
6. **Debit**: `issueDebitNote` with `ISSUE_DEBIT_NOTE` permission + `DebitNoteRequest` validation.
7. **Refund**: credit-note listener path (above); invoice → `corrected` (refund-approved semantics, not void).

## 8. Business Rules

| # | Rule | Implementation | Bypass? |
|---|---|---|---|
| R1 | Exactly one invoice per order (idempotent under lock) | existing-invoice `lockForUpdate` guard | No |
| R2 | No invoice without a valid snapshot (6 validators) | `snapshotValidator->validate` throws inside creation txn | No |
| R3 | Numbers are yearly-series gapless under lock | `InvoiceNumberService::generateNext` | Rollback gaps possible (standard sequence caveat) |
| R4 | Status transitions enforced at model layer | `allowedTransitions` + `saving` hook | Bulk `update()` bypasses (documented warning) |
| R5 | Verification is HMAC over hash + app key (constant-time compare) | `computeVerificationHash` + `hash_equals` | No (key rotation would invalidate old HMACs — ops note) |
| R6 | Paid linkage uses the latest `paid` transaction; currency falls back txn → order → base → EGP | Generation `:41-50` | No |
| R7 | Refund-approved invoices become `corrected` with a credit note, never silently void | Credit-note listener | No |
| R8 | Customer PDF access is signature-gated; listings are owner-scoped | Signed URLs + `myInvoices` user scope | No |

## 9. Source of Truth / Authorities

- **Existence**: `InvoiceService::generateFromOrder` (sole creator; regeneration funnels through it).
- **Validity**: snapshot validators (sole gate); integrity hash (tamper evidence).
- **Numbering**: `InvoiceNumberService` + `invoice_sequences` (sole allocator).
- **Lifecycle**: `InvoiceStatus` machine + admin services (correct/cancel/debit) + credit-note service.
- **History**: `invoice_timeline` + `LogInvoiceCreated` (append-only posture).
- **Rendering**: `GenerateInvoicePdfJob` (sole writer of `pdf_path`/ready state).

## 10. Database Impact

`invoices` (order link, txn link, user, number/series/seq/year, money columns, `amount_paid`, currency snapshot, payment method/gateway, status machine, snapshot JSON + hash + HMAC, pdf path/checksum, uuid `2026_07_27_082000`, lifecycle columns `2026_07_28_000005`, timestamps); `invoice_sequences` (yearly counters); `invoice_timeline` (event rows); `credit_notes` + `debit_notes` (`2026_07_28_000002/3`). Migrations additive; no destructive invoice changes.

## 11. API Surface

| Method | URI (admin) | Permission | Handler | Notes |
|---|---|---|---|---|
| GET | `/v1/admin/invoices` | `VIEW_INVOICES` | `index` | search/sort/paginate |
| GET | `/v1/admin/invoices/{id}` | `VIEW_INVOICE` | `show` | |
| POST | `/v1/admin/invoices/{id}/regenerate` | `REGENERATE_INVOICE` | `regenerate` | |
| POST | `/v1/admin/invoices/{id}/correct` | `CORRECT_INVOICE` | `correct` | `CorrectInvoiceRequest` |
| POST | `/v1/admin/invoices/{id}/cancel` | `CANCEL_INVOICE` | `cancel` | |
| POST | `/v1/admin/invoices/{id}/debit-note` | `ISSUE_DEBIT_NOTE` | `issueDebitNote` | `DebitNoteRequest` |
| GET | customer `my-invoices`, `invoice/{uuid}`, `verify/{uuid}`, signed view/download | owner/signature | `InvoiceController` customer actions | traversal-guarded streaming |

## 12. Authentication & Authorization

Admin invoice operations are individually permission-gated (six distinct permissions — GAP-1 closed). Customer invoice access is owner-scoped (`user_id`) or capability-URL-based (signed URLs with expiry). Verification endpoint is public-by-design (anti-counterfeit UX) and leaks nothing beyond authenticity + invoice on success. No invoice mutation without both auth and the specific grant.

## 13. Validation

Snapshot validators (structure/financial-invariant/currency/money/metadata/version) run inside the creation transaction — any failure aborts creation with no row. `CorrectInvoiceRequest`/`DebitNoteRequest` gate admin mutations (amounts, reasons). Status transitions validated at model layer. Correction allowed only from correctable statuses (`generated/ready/failed/corrected/verified/downloaded/printed` — `InvoiceService.php:119`).

## 14. Transactions

Generation is a single transaction (lock → validate → number → create → timeline) with afterCommit fan-out; correction/cancel/debit are transactional; PDF rendering is outside any order/payment transaction (queue job, retryable); timeline writes ride the same unit as their state change; listener failures never roll back order/payment state (report/throw-to-retry only in the job).

## 15. Concurrency

| Case | Protection | Classification |
|---|---|---|
| Double generation (callback + listener + cancel path) | existing-invoice `lockForUpdate` | STRONGLY REASONED |
| Number collision | sequence row `lockForUpdate` per series-year | STRONGLY REASONED |
| Concurrent correct/cancel | original `lockForUpdate` + status preconditions | STRONGLY REASONED |
| PDF double-render | status-gated job (failed → pdf_generating → ready/failed) | STRONGLY REASONED |
| True parallel proof | 12 invoice suites | UNPROVEN — NOT EXECUTED |

## 16. Async / Queues / Events

`PaymentSucceeded → GenerateInvoiceListener` (high, 5 tries, afterCommit) — now usually idempotent backup; `InvoiceCreated → LogInvoiceCreated` (sync); `GenerateInvoicePdfJob` (low, 3 tries) after commit; `RefundApproved → GenerateCreditNoteOnRefund` (high, afterCommit); timeline recorders sync within units. Retry semantics: generation retries via listener redelivery (idempotent); PDF retries via job backoff; correction/cancel are sync admin actions.

## 17. Error Handling

Validator failure → no invoice row + reported error (caller decides: order flow continues, listener retries). PDF failure → `failed` status + logged error + rethrow (job retry); admin regenerable. Missing invoice for refund → warning log + graceful skip (no throw). Verification miss → 404; tampered → `tampered:true`, invoice withheld. Download of missing file → 404 (never 500).

## 18. Security

- HMAC verification with `hash_equals` (timing-safe); verification hash binds snapshot + app key.
- Signed customer URLs (expiry + signature); path-traversal guard on `pdf_path` (regex + `..` rejection) before disk access.
- Six granular admin permissions; owner-scoped listings; public verify endpoint is authenticity-only.
- No snapshot/PDF path reaches mass assignment unguarded; invoice creation fields are server-computed.
- App-key rotation invalidates stored HMACs (ops consideration — no rekey affordance found).

## 19. Performance

Generation is one transaction with a handful of indexed reads + one sequence lock (short); snapshot build is in-memory over order relations (eager-loaded by callers); PDF rendering is off-request (queue, 120s timeout); customer streaming uses disk responses (no memory buffering); timeline writes are single-row inserts; `myInvoices` paginated (cap 100).

## 20. Tests & Verification

12 suites: `AdminInvoiceAuthTest`, `AdminInvoiceIndexTest`, `AdminInvoiceShowTest`, `AdminInvoiceEndToEndTest`, `AdminInvoiceRegenerateTest`, `AdminInvoiceCorrectTest`, `AdminInvoiceCancelTest`, `AdminInvoiceDebitNoteEndpointTest`, `MyInvoicesEndpointTest`, `InvoiceDownloadPermissionTest`, `InvoicePdfViewDownloadTest`, `InvoiceVerifyEndpointTest`. Coverage spans auth matrix, lifecycle ops, customer access, and verification. **None executed** (environment).

## 21. Edge Cases

Covered: generation on unpaid/cancelled orders (creates row — F-02); multiple paid transactions (latest paid linked); no paid transaction (null link, order currency fallback); snapshot validation failure (no row); sequence-year rollover (new row); PDF job failure (failed + retry + regen); refund with no active invoice (warn + skip); tampered snapshot (verify reports, withholds); deleted order (FK posture per schema); re-download (idempotent `downloaded_at` first-stamp + timeline).

## 22. Potential Bugs

### Finding F-01
- Severity: MEDIUM
- Type: DOCUMENTATION
- Status: PROVEN
#### Finding
The manual describes queued-only generation and predates: synchronous generation on first-leave-pending (including cancel paths), the real mPDF renderer, signed customer URLs, the `GENERATED→READY`/`READY→PDF_GENERATING` transitions, refund credit-note closure, and paid-transaction filtering.
#### Evidence
`OrderService.php:1127-1135` (sync trigger); `GenerateInvoicePdfJob.php` (mPDF); `InvoiceController.php:107-155` (signed flows); `InvoiceStatus.php` (matrix deltas); `GenerateCreditNoteOnRefund.php`; manual §§Architecture/Status/PDF/Verification.
#### Why it matters
Invoice-operations runbooks based on the manual miss the primary (sync) generation path and the cancel-path invoice behavior.
#### Current behavior
Correct implementation; stale manual.
#### Recommended future action
Regenerate Phase 7 around the three-trigger model (on explicit docs request).
> NOTE: This audit is READ-ONLY. No fix was applied.

### Finding F-02
- Severity: LOW
- Type: DATA INTEGRITY
- Status: STRONGLY REASONED
#### Finding
Invoices generated on never-paid cancel paths stamp `amount_paid = order total` (`InvoiceService.php:61`) and link no paid transaction — a document that reads as paid-in-full for an order where no money moved. Combined with Phase 01 F-03 (invoice-on-cancel), the invoice table contains paid-looking rows for cancelled orders.
#### Evidence
`InvoiceService.php:41-61` (`$paidTransaction` null-tolerant; `amount_paid = $total` unconditional).
#### Why it matters
Accounting exports, customer invoice lists, and tax computations consuming `amount_paid` will overstate collections unless they join payment state.
#### Current behavior
Consistent row shape; misleading value on unpaid invoices.
#### Recommended future action
Stamp `amount_paid` from the paid transaction (0/null when none) or gate generation to paid/completing transitions per the confirmed policy; pin with a test.
> NOTE: This audit is READ-ONLY. No fix was applied.

### Finding F-03
- Severity: INFO
- Type: DOCUMENTATION
- Status: PROVEN
#### Finding
Four recorded invoice gaps are fixed: INV-4 (paid-transaction filter in both generation and snapshot), INV-12 (refund credit-note listener), GAP-1 (debit-note permission), GAP-5 (real PDF rendering).
#### Evidence
`InvoiceService.php:41-44`; `InvoiceSnapshotService.php:11`; `GenerateCreditNoteOnRefund.php`; `InvoiceController.php:40`; `GenerateInvoicePdfJob.php` (mPDF).
#### Why it matters
Record as fixed; closes Phase-16 gap items.
#### Current behavior
Correct.
#### Recommended future action
None (record only).
> NOTE: This audit is READ-ONLY. No fix was applied.

### Finding F-04
- Severity: MEDIUM
- Type: TEST GAP
- Status: PROVEN (gap) / UNPROVEN (runtime)
#### Finding
Twelve invoice suites exist and none were executed here; validator-pipeline, numbering-concurrency, transition-enforcement, and signed-URL behaviors are statically verified only.
#### Evidence
Test listing verified; execution impossible (MySQL-only).
#### Why it matters
Invoice documents are legal/financial artifacts; the validator + numbering paths need runtime proof most.
#### Current behavior
Well-constructed; unproven.
#### Recommended future action
Execute the Invoice suites against real MySQL in CI; add a numbering-race test if absent.
> NOTE: This audit is READ-ONLY. No fix was applied.

## 23. Potential Failure Scenarios

- **Snapshot invalid at generation**: no row; sync path reports (order flow continues); queued path retries 5× then dead-letters to failed-jobs (observable via `AdminQueueJobFailedNotification`).
- **Sequence row contention**: short lock; yearly series bounds hot rows; acquiring across year boundary creates the new row lazily.
- **PDF storage outage**: job fails → `failed` → operator regenerates; order/payment state unaffected.
- **App-key rotation**: stored HMACs fail verification (all invoices read tampered) — rotation runbook must re-stamp or version the hash input.
- **Refund without invoice**: warn + skip (no crash); manual follow-up.
- **Bulk status updates**: bypass model hooks — must use services (documented in-manual warning; enforce by code review).

## 24. Documentation Drift

Manual accurate for: pipeline stages, validator names/purposes, hash/HMAC design, numbering design, timeline role, correction/cancel/credit/debit mechanics, event names, schema shapes, hook-bypass warning. Drifted: trigger model (queued-only → three triggers), PDF implementation (placeholder-era → mPDF), customer access (→ signed URLs), status matrix deltas, refund closure (gap → implemented), paid-link filtering (fixed), permission list (debit added).

## 25. Dependencies

- **Depends on**: Phase 01/05 (generation triggers), Phase 06 (paid transactions), Phase 10 (refund events), order/tax/currency snapshots.
- **Consumed by**: Phase 08 (QR/verification presentation), Phase 10 (credit notes), Phase 12 (customer invoice access), Phase 13 (admin invoice ops), Phase 15 (timeline invoice stage).
- **Shared tables**: `invoices`, `invoice_sequences`, `invoice_timeline`, `credit_notes`, `debit_notes`, `orders`, `transactions`.
- **Shared services**: `InvoiceService`, `InvoiceSnapshotService`, validators, `SnapshotIntegrityService`, `InvoiceNumberService`, `InvoiceTimelineService`, `CreditNoteService`, `DebitNoteService`.

## 26. Out of Scope

QR code artwork/payload design (Phase 08), tax computation rules (referenced only), PDF template aesthetics, e-invoicing/tax-authority submission (no such integration found), invoice analytics.

## 27. Residual Risks

1. Unpaid-invoice `amount_paid` accuracy (F-02) + cancel-path invoice policy (Phase 01 F-03).
2. Runtime proof absent (F-04).
3. App-key rotation invalidates HMACs (documented, no affordance).
4. Bulk-update hook bypass relies on convention.
5. Manual predates the three-trigger model (F-01).

## 28. Evidence / Source Files

Manual: `HEAD:docs/production-manual/PHASE-07-INVOICE-LIFECYCLE.md` (1240 lines, temp extract; §§ read: TOC, architecture, status matrix, generation, validators list, correction/cancel/credit/debit/PDF/regeneration/verification/download/events/schema/edge cases). Code: `app/Enums/InvoiceStatus.php` (full); `app/Models/Invoice.php` (uuid + saving hook); `app/Services/Invoice/{InvoiceService (:22-115 verified), InvoiceSnapshotService (:11,106 paid filter), InvoiceSnapshotValidator, Validators/* (6), SnapshotIntegrityService, InvoiceNumberService (full), InvoiceTimelineService, CreditNoteService, DebitNoteService, SnapshotValidatorInterface}`; `app/Jobs/GenerateInvoicePdfJob.php` (mPDF engine); `app/Listeners/{GenerateInvoiceListener, LogInvoiceCreated, GenerateCreditNoteOnRefund (full)}`; `app/Http/Controllers/Api/InvoiceController.php` (permissions `:35-40`, admin ops, customer signed flows `:107-280`); `CorrectInvoiceRequest`, `DebitNoteRequest`; invoice resources; `OrderService.php:1127-1135` (sync trigger). Tests (12 files, listed, not executed). Migrations: `2026_07_16_000001/2`, `2026_07_27_082000`, `2026_07_28_000001-6`.

## 29. Final Assessment

**Verdict: PASS WITH RESIDUAL RISKS.**

The invoice lifecycle is complete and careful — idempotent generation, validated snapshots, enforced state machine, granular authorization, verified customer access, real rendering, and refund closure — with four recorded gaps demonstrably fixed. It cannot reach PASS because unpaid-path invoices carry paid-looking amounts, runtime proof is absent, and the manual predates the current trigger model. No blocking defect found.
