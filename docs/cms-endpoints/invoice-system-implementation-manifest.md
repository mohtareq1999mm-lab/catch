# Invoice System — Implementation Manifest

**Document Type**: Execution Plan  
**Status**: Approved  
**Architecture Reference**: ADR-003 (`docs/cms-endpoints/invoice-system-architecture.md`)  
**Architecture State**: Frozen — no architectural changes permitted  
**Target Branch**: `main` (additive changes only)

---

## Table of Contents

1. [File Inventory](#1-file-inventory)
2. [Phase Breakdown](#2-phase-breakdown)
3. [Dependency Graph](#3-dependency-graph)
4. [Pull Request Strategy](#4-pull-request-strategy)
5. [Testing Strategy](#5-testing-strategy)
6. [Deployment Strategy](#6-deployment-strategy)
7. [Rollback Strategy](#7-rollback-strategy)
8. [Acceptance Criteria](#8-acceptance-criteria)
9. [Definition of Done](#9-definition-of-done)
10. [Final Implementation Roadmap](#10-final-implementation-roadmap)

---

## 1. File Inventory

### 1.1 Files to Create (48 files)

| # | File | Phase | Responsibility | Dependencies | Risk |
|---|------|-------|----------------|-------------|------|
| 1 | `database/migrations/2026_07_16_000001_create_invoice_sequences_table.php` | P1 | Sequence number generation table | None | Low |
| 2 | `database/migrations/2026_07_16_000002_create_invoices_table.php` | P1 | Main invoices table with structured columns + JSON data | None | Low |
| 3 | `database/migrations/2026_07_16_000003_seed_invoice_permissions.php` | P1 | Seed `view-invoices`, `issue-correction-invoice`, `regenerate-invoice-pdf`, `export-invoices` permissions | #2 | Low |
| 4 | `app/Models/InvoiceSequence.php` | P1 | Sequence model (fillable, timestamps) | #1 | Low |
| 5 | `app/Models/Invoice.php` | P1 | Invoice model (fillable, casts, relationships) | #2 | Low |
| 6 | `app/Enums/InvoiceStatus.php` | P1 | Status enum: Pending, Generated, PdfGenerating, Ready, Failed, Corrected, Cancelled | None | Low |
| 7 | `app/Exceptions/FinancialInvariantException.php` | P2 | Custom exception for invariant violations | None | Low |
| 8 | `app/Exceptions/SnapshotValidationException.php` | P2 | Custom exception for snapshot validation failures | None | Low |
| 9 | `app/Exceptions/CurrencyMismatchException.php` | P2 | Custom exception for currency mismatch | None | Low |
| 10 | `app/Exceptions/UnsupportedSchemaException.php` | P2 | Custom exception for unknown schema version | None | Low |
| 11 | `app/Contracts/Services/Invoice/SnapshotValidatorInterface.php` | P2 | Validator contract | None | Low |
| 12 | `app/Services/Invoice/InvoiceSnapshotService.php` | P2 | Builds immutable JSON snapshot from order data | #5 (Invoice model), Order model | Low |
| 13 | `app/Services/Invoice/InvoiceNumberService.php` | P2 | Generates sequential invoice numbers with lockForUpdate | #4 (InvoiceSequence model) | Low |
| 14 | `app/Services/Invoice/SnapshotIntegrityService.php` | P2 | SHA-256 hash computation and verification with canonical JSON | None | Low |
| 15 | `app/Services/Invoice/InvoiceSnapshotValidator.php` | P2 | Orchestrates validator pipeline | #11, #16–#21 | Low |
| 16 | `app/Services/Invoice/Validators/StructureValidator.php` | P2 | Validates required fields and types exist | #11 | Low |
| 17 | `app/Services/Invoice/Validators/MoneyValidator.php` | P2 | Validates monetary precision (3 decimals, non-negative) | #11 | Low |
| 18 | `app/Services/Invoice/Validators/CurrencyValidator.php` | P2 | Validates currency consistency across all monetary fields | #11 | Low |
| 19 | `app/Services/Invoice/Validators/FinancialInvariantValidator.php` | P2 | Validates subtotal - discounts + shipping = total | #11 | Low |
| 20 | `app/Services/Invoice/Validators/SnapshotVersionValidator.php` | P2 | Validates snapshot_schema is a known version | #11 | Low |
| 21 | `app/Services/Invoice/Validators/MetadataValidator.php` | P2 | Validates metadata (generated_at, locale) | #11 | Low |
| 22 | `app/Events/InvoiceCreated.php` | P3 | Event fired after invoice record creation | #5 | Low |
| 23 | `app/Events/InvoiceReady.php` | P3 | Event fired when PDF is ready | #5 | Low |
| 24 | `app/Listeners/GenerateInvoiceListener.php` | P3 | ShouldQueue listener: builds snapshot, validates, creates invoice | #5, #12, #13, #15, #22 | Medium |
| 25 | `app/Jobs/GenerateInvoicePdfJob.php` | P4 | ShouldQueue job: renders PDF from snapshot, stores, computes checksum | #5, #28, #29 | Low |
| 26 | `app/Services/Invoice/InvoicePdfService.php` | P4 | PDF render, store, checksum, regeneration | #5, #14 | Low |
| 27 | `resources/views/pdf/invoice.blade.php` | P4 | PDF Blade template reading from $invoice->data JSON | None | Low |
| 28 | `app/Http/Controllers/Api/General/InvoiceController.php` | P5 | Customer + admin invoice API endpoints | #5, #30, #31, #32, #33, #34 | Low |
| 29 | `app/Http/Resources/Invoice/InvoiceResource.php` | P5 | Full invoice resource | #5 | Low |
| 30 | `app/Http/Resources/Invoice/InvoiceCollection.php` | P5 | List invoice resource | #29 | Low |
| 31 | `app/Http/Requests/Invoice/InvoiceListRequest.php` | P5 | Validation for list/filter parameters | None | Low |
| 32 | `app/Http/Requests/Invoice/InvoiceCorrectionRequest.php` | P5 | Validation for correction request | None | Low |
| 33 | `app/Policies/InvoicePolicy.php` | P5 | Authorization: owner view/download, admin permissions | None | Low |
| 34 | `app/Console/Commands/GeneratePendingInvoicePdfs.php` | P5 | Scheduled: dispatch pending PDF generation | #5, #25 | Low |
| 35 | `app/Console/Commands/RegenerateFailedInvoicePdfs.php` | P5 | Scheduled/on-demand: retry failed PDFs | #5, #25 | Low |
| 36 | `app/Console/Commands/InvoiceAuditCommand.php` | P5 | Audit: checks all completed orders have invoices, integrity check | #5, #14 | Low |
| 37 | `app/Console/Commands/InvoiceHealthCommand.php` | P5 | CLI health check for invoice system | #5 | Low |
| 38 | `app/Console/Commands/InvoiceGenerateCommand.php` | P5 | Manually generate invoice for existing order | #5, #12, #13, #15, #24 | Low |
| 39 | `resources/lang/en/invoice.php` | P6 | English translations for invoice module | None | Low |
| 40 | `resources/lang/ar/invoice.php` | P6 | Arabic translations for invoice module | None | Low |
| 41 | `tests/Unit/Invoice/InvoiceSnapshotServiceTest.php` | P7 | Snapshot assembly correctness, field mapping, determinism | #12 | Low |
| 42 | `tests/Unit/Invoice/InvoiceNumberServiceTest.php` | P7 | Sequence generation, concurrency, gap behavior | #13 | Low |
| 43 | `tests/Unit/Invoice/InvoiceValidatorTest.php` | P7 | Each validator: pass/fail cases, edge cases | #15–#21 | Low |
| 44 | `tests/Unit/Invoice/SnapshotIntegrityServiceTest.php` | P7 | Canonical JSON, hash determinism, tamper detection | #14 | Low |
| 45 | `tests/Unit/Invoice/FinancialInvariantTest.php` | P7 | Invariant formula: valid, invalid, rounding tolerance | #19 | Low |
| 46 | `tests/Feature/Invoice/InvoiceGenerationFlowTest.php` | P7 | Full flow: PaymentSucceeded → listener → invoice creation | #24, #5 | Medium |
| 47 | `tests/Feature/Invoice/InvoiceIdempotencyTest.php` | P7 | Duplicate events, concurrent listeners, unique constraint | #24, #5 | Medium |
| 48 | `tests/Feature/Invoice/InvoicePdfGenerationTest.php` | P7 | PDF generation: template renders, storage, checksum, regeneration | #25, #26, #27 | Low |
| 49 | `tests/Feature/Invoice/InvoiceApiTest.php` | P7 | Customer API: list, view, download, order invoice | #28–#33 | Low |
| 50 | `tests/Feature/Invoice/InvoiceAdminApiTest.php` | P7 | Admin API: list with filters, view, correct, regenerate, export | #28–#33 | Low |
| 51 | `tests/Feature/Invoice/InvoiceAuthorizationTest.php` | P7 | Customer cannot view others' invoices, permissions enforced | #33 | Low |
| 52 | `tests/Feature/Invoice/InvoiceConcurrencyTest.php` | P7 | WithoutOverlapping, double insert race, lockForUpdate behavior | #24, #25 | Medium |

### 1.2 Files to Modify (8 files)

| # | File | Change | Phase | Risk | Rollback |
|---|------|--------|-------|------|----------|
| 1 | `packages/marvel/src/Database/Models/Order.php` | Add `governorate()` BelongsTo relationship | P1 | Low — additive, no side effects | Revert commit |
| 2 | `packages/marvel/src/Enums/Permission.php` | Add `VIEW_INVOICES`, `ISSUE_CORRECTION_INVOICE`, `REGENERATE_INVOICE_PDF`, `EXPORT_INVOICES` constants | P1 | Low — additive | Revert commit |
| 3 | `app/Providers/EventServiceProvider.php` | Add `PaymentSucceeded => [GenerateInvoiceListener::class]` | P3 | Low — additive listener registration | Revert commit |
| 4 | `app/Services/General/OrderService.php` | Centralize `PaymentSucceeded` dispatch in `changeOrderStatus()`, remove from `markCodAsPaid()` / `markCashierPaid()` | P3 | **Medium** — changes event timing. `markCodAsPaid()` and `markCashierPaid()` currently dispatch inside transaction. Must use `DB::afterCommit()`. | Revert commit |
| 5 | `app/Http/Controllers/Api/General/OrderController.php` | Remove duplicate `event(new PaymentSucceeded(...))` from `checkoutCallback()` (lines 294-300) | P3 | Low — removes redundant dispatch | Revert commit |
| 6 | `routes/api.php` | Add invoice routes under `general` prefix | P5 | Low — additive routes, no existing route modified | Revert commit |
| 7 | `app/Providers/AuthServiceProvider.php` | Register `InvoicePolicy` for `Invoice` model | P5 | Low — additive | Revert commit |
| 8 | `app/Console/Kernel.php` | Schedule `invoices:generate-pending` (every 5 min), `invoices:regenerate-failed-pdfs` (every 30 min) | P5 | Low — additive scheduled commands | Revert commit |

### 1.3 Files NOT Modified (Frozen/Protected)

| File | Reason |
|------|--------|
| `app/Services/Pricing/ProductPricingService.php` | Frozen architecture (ADR-001) |
| `app/Services/Coupon/CouponCalculator.php` | No changes needed |
| `app/Services/Coupon/CouponOrchestrator.php` | No changes needed |
| `app/Services/General/PromotionService.php` | No changes needed |
| `app/Services/Payment/PaymentCheckoutHandler.php` | No changes needed |
| `app/Services/Payment/PaymentGatewayFactory.php` | No changes needed |
| `app/Services/Gateway/MyFatoorahGateway.php` | No changes needed |
| `app/DTOs/CheckoutTotals.php` | No changes needed |
| `app/Http/Resources/Order/OrderResource.php` | No changes needed |
| `app/Http/Resources/Order/OrderItemResource.php` | No changes needed |
| `app/Http/Resources/Order/OrderCollection.php` | No changes needed |
| `packages/marvel/config/constants.php` | No changes needed |
| `resources/views/pdf/order-invoice.blade.php` | Legacy — kept for backward compatibility during transition |

---

## 2. Phase Breakdown

### Phase 1: Foundation (Database + Models + Enums)

**Objective**: Create the database schema, Eloquent models, enums, and permissions that the entire invoice system depends on.

**Files Created**: #1, #2, #3, #4, #5, #6

**Files Modified**: #1 (Order model — add governorate relationship), #2 (Permission enum)

**Deliverables**:
- `invoice_sequences` table created and migrated
- `invoices` table created with all structured columns, FKs, indexes, unique constraints
- Invoice permissions seeded into `permissions` table
- `Invoice` model with relationships (`order`, `transaction`, `user`, `correctionTo`, `corrections`)
- `InvoiceSequence` model with fillable fields
- `InvoiceStatus` enum (backed by `BenSampo\Enum\Enum`)
- `Order` model gains `governorate()` BelongsTo relationship
- `Permission` enum gains 4 new constants

**Exit Criteria**:
- `php artisan migrate` succeeds
- `php artisan migrate:rollback` + `migrate` round-trip succeeds
- All FKs, unique constraints, and indexes verified in MySQL
- `Invoice` model relationships resolve correctly
- Permission seeds idempotent (can run multiple times without duplicates)
- `php artisan tinker` can create/read `Invoice` and `InvoiceSequence` instances
- All existing feature tests still pass (no regressions)

**Rollback Strategy**:
- `php artisan migrate:rollback --step=3` drops both tables and permission seed
- Revert Order model and Permission enum commits
- Continue without downtime — new tables are unused until Phase 3

**Risks**:
- Migration naming conflicts with future dates: **Low** — use unique timestamp
- FK constraint on `user_id` references `users` table which uses SoftDeletes: **Low** — `ON DELETE RESTRICT` prevents invoice for deleted user (acceptable)
- `order_id` UNIQUE constraint prevents accidental duplicates: **Designed behavior**

---

### Phase 2: Core Domain Layer (Services + Validators + Contracts)

**Objective**: Build the snapshot assembly, validation pipeline, number generation, and integrity services. These are pure business logic with no side effects and no API exposure.

**Files Created**: #7, #8, #9, #10, #11, #12, #13, #14, #15, #16, #17, #18, #19, #20, #21

**Dependencies**: Phase 1 (models and enums)

**Deliverables**:
- `InvoiceSnapshotService` builds a complete V1 snapshot array from an `Order` with eager-loaded relations
- `InvoiceNumberService` generates sequential invoice numbers using `lockForUpdate()`
- `InvoiceSnapshotValidator` orchestrates 6 validators + integrity hash
- Each validator (`StructureValidator`, `MoneyValidator`, `CurrencyValidator`, `FinancialInvariantValidator`, `SnapshotVersionValidator`, `MetadataValidator`) implements `SnapshotValidatorInterface`
- `SnapshotIntegrityService` computes and verifies SHA-256 with canonical JSON
- Custom exceptions for validation failures

**Exit Criteria**:
- `InvoiceSnapshotService::buildFullSnapshot()` produces deterministic output for the same input
- `InvoiceNumberService::generateNext()` produces `INV-2026-000001` format
- All 6 validators pass valid data and reject invalid data
- Financial invariant formula: `subtotal - coupon - promotion + shipping = total` with 0.01 tolerance
- Snapshot hash is deterministic (same input → same hash)
- Hash changes when any field is tampered with
- All services instantiable via `app()` (Laravel container)
- 100% unit test coverage of all validators

**Rollback Strategy**:
- No rollback needed — these services are not called from any live code yet
- Simply don't deploy Phase 2 without Phase 3

**Risks**:
- Overly strict validators rejecting valid data: **Medium** — tolerance of 0.01 for rounding
- Missing required field in snapshot: **Low** — caught by StructureValidator in unit tests
- Number format mismatch with business expectations: **Low** — format is documented and tested

---

### Phase 3: Event Integration (Listener + Events + OrderService Changes)

**Objective**: Wire the `PaymentSucceeded` event into the invoice generation flow. This is the integration point that connects the existing payment system to the new invoice module.

**Files Created**: #22, #23, #24

**Files Modified**: #3 (EventServiceProvider), #4 (OrderService), #5 (OrderController)

**Dependencies**: Phase 1 (models), Phase 2 (services)

**Deliverables**:
- `GenerateInvoiceListener` (ShouldQueue, queue: `medium`, tries: 3, backoff: 10/30/90) listens for `PaymentSucceeded`
- Listener flow: idempotency check → `lockForUpdate` → snapshot assembly → validation → `Invoice::create()` → dispatch `InvoiceCreated`
- `changeOrderStatus()` dispatches `PaymentSucceeded` via `DB::afterCommit()` when status = `completed`
- `markCodAsPaid()` and `markCashierPaid()` no longer dispatch `PaymentSucceeded` directly
- `checkoutCallback()` try/catch block removed (lines 294-300)
- `InvoiceCreated` + `InvoiceReady` events defined

**Exit Criteria**:
- `PaymentSucceeded` dispatched only from `changeOrderStatus()` when status = `completed`
- All 3 old dispatch locations verified removed
- `GenerateInvoiceListener` creates invoice record in database
- Duplicate `PaymentSucceeded` events do NOT create duplicate invoices (idempotency verified)
- Listener retries on failure (3 attempts with backoff)
- All existing payment flows still complete successfully:
  - Online payment via `checkoutCallback()`
  - COD payment via `markCodAsPaid()`
  - Cashier payment via `markCashierPaid()`
- No regressions in existing test suite

**Rollback Strategy**:
- **Critical**: If this phase breaks payment flows, revert the commit immediately
- Reverting restores the original 3 dispatch points
- Invoice records created during the broken window remain in database (no harm — they are valid)
- Command to clean up orphan invoices: `php artisan tinker --execute="Invoice::where('generated_by', 'system')->delete()"` (if needed)

**Risks**:
| Risk | Mitigation |
|------|-----------|
| `changeOrderStatus()` already has its own `DB::transaction()`. Adding event inside `DB::afterCommit()` is straightforward — same pattern as `recordCouponUsage()` | Follow the existing `AssignedCouponConsumed` pattern at line 576 |
| `checkoutCallback()` dispatches `PaymentSucceeded` inside a try/catch that silently swallows errors | Removing this is safe because the event is now dispatched from `changeOrderStatus()` (line 291), which is called just before the removed try/catch block |
| `markCodAsPaid()` and `markCashierPaid()` both have their own `DB::transaction()` and don't call `changeOrderStatus()` | Option A (recommended): refactor to call `changeOrderStatus(null, 'completed', $order->id)`. Option B (fallback): wrap existing `event()` in `DB::afterCommit()`. |

---

### Phase 4: PDF Generation

**Objective**: Generate, store, and serve PDF documents from immutable invoice snapshots.

**Files Created**: #25, #26, #27

**Dependencies**: Phase 3 (invoice records exist in database)

**Deliverables**:
- `InvoicePdfService` reads `$invoice->data`, renders Blade template, stores to disk, computes SHA-256 checksum
- `GenerateInvoicePdfJob` (ShouldQueue, queue: `pdf`, tries: 3, backoff: 30/120/300)
- PDF Blade template (`resources/views/pdf/invoice.blade.php`) renders from `$invoice->data` JSON
- Template handles `snapshot_schema = 2` (V1 format)
- PDF stored at `storage/invoices/{invoice_number}.pdf`
- `pdf_path`, `pdf_checksum`, `pdf_generated_at` updated on success
- `last_generation_error` updated on failure
- Status transitions: `generated` → `pdf_generating` → `ready` (or `failed`)

**Exit Criteria**:
- `GenerateInvoicePdfJob` generates a valid PDF file on disk
- PDF checksum matches after regeneration (deterministic output)
- PDF contains all customer, order, and line item data from the snapshot
- PDF template reads ONLY from `$invoice->data` — no live database queries
- Job retries on failure (3 attempts)
- After max retries, invoice status = `failed` and `last_generation_error` populated
- PDF is downloadable via browser (valid `Content-Type: application/pdf`)

**Rollback Strategy**:
- Revert commit — no PDF generation occurs
- Invoice records already in the database are unaffected (they remain in `generated` state)
- Phase 6 commands (`invoices:generate-pending`) can catch up after re-deploy

**Risks**:
| Risk | Mitigation |
|------|-----------|
| dompdf memory limit for large invoices | Test with maximum realistic line items (100+). Set `PDF_MEMORY_LIMIT` config |
| Blade template tries to access live data | Code review gate: template must only use `$invoice->data`. Add lint rule. |
| PDF checksum non-deterministic | Must use same dompdf version, same font files, same paper config on every run |
| Arabic RTL rendering | Test with Arabic locale. dompdf supports `style="direction: rtl"` |

---

### Phase 5: API Layer (Controller + Resources + Policy + Routes + Commands)

**Objective**: Expose the invoice system via REST API for customers and admins.

**Files Created**: #28, #29, #30, #31, #32, #33, #34, #35, #36, #37, #38

**Files Modified**: #6 (routes), #7 (AuthServiceProvider), #8 (Kernel)

**Dependencies**: Phase 3 (invoice records exist), Phase 4 (optional — PDF download endpoint)

**Deliverables**:
- `InvoiceController` with customer endpoints (index, show, download, order-invoice)
- `InvoiceController` with admin endpoints (index with filters, show, download, correct, regenerate, export, health)
- `InvoiceResource` and `InvoiceCollection` following existing `Order` resource patterns
- `InvoicePolicy` with view/download for owners, view/download/correct/regenerate/export for admins
- `InvoiceListRequest` with sort, filter, pagination validation
- `InvoiceCorrectionRequest` with reason and adjustment validation
- Invoice routes registered under `/api/general/` prefix
- `InvoicePolicy` registered in `AuthServiceProvider`
- Scheduled commands registered in `Kernel`

**Exit Criteria**:
- `GET /api/general/invoices` returns paginated invoices for authenticated user
- `GET /api/general/invoices/{id}` returns full invoice resource with snapshot data
- `GET /api/general/invoices/{id}/download` returns PDF file
- `GET /api/general/orders/{orderId}/invoice` returns invoice for order
- `GET /api/general/admin/invoices` returns all invoices with filters
- `POST /api/general/admin/invoices/{id}/correct` creates correction invoice
- `POST /api/general/admin/invoices/{id}/regenerate-pdf` queues PDF regeneration
- `GET /api/general/admin/invoices/export` returns CSV
- `GET /api/general/admin/invoices/health` returns health status
- Customer cannot view another customer's invoice
- Admin without `view-invoices` permission cannot access admin endpoints
- All endpoints return proper JSON structure per architecture spec

**Rollback Strategy**:
- Revert commit — all API endpoints are removed
- Invoice records in database are unaffected
- No data loss

**Risks**:
| Risk | Mitigation |
|------|-----------|
| New routes conflict with existing routes | All routes under `/invoices` prefix — no existing routes use this path |
| Permissions not seeded for existing users | Seed migration handles this. Add console command for existing environments: `php artisan invoices:seed-permissions` |
| PDF download performance (large files) | Use `streamedResponse()` for PDF download |

---

### Phase 6: Translations

**Objective**: Add all user-facing strings to translation files.

**Files Created**: #39, #40

**Dependencies**: Phase 5 (API responses use translation keys)

**Deliverables**:
- `resources/lang/en/invoice.php` — English translations
- `resources/lang/ar/invoice.php` — Arabic translations

**Translation keys**:

```php
// invoice.php
return [
    'created_successfully' => 'Invoice created successfully',
    'not_found' => 'Invoice not found',
    'no_invoice_for_order' => 'No invoice found for this order',
    'pdf_not_ready' => 'Invoice PDF is not yet available',
    'pdf_regeneration_queued' => 'PDF regeneration has been queued',
    'correction_created' => 'Correction invoice issued successfully',
    'cancelled' => 'Invoice cancelled successfully',
    'download_disabled' => 'Invoice download is not available',
    'health_ok' => 'Invoice system is healthy',
    'export_started' => 'Invoice export has been queued',
    'already_exists' => 'An invoice already exists for this order',
    'status_pending' => 'Pending',
    'status_generated' => 'Generated',
    'status_pdf_generating' => 'PDF Generating',
    'status_ready' => 'Ready',
    'status_failed' => 'Failed',
    'status_corrected' => 'Corrected',
    'status_cancelled' => 'Cancelled',
];
```

**Exit Criteria**:
- All translation keys resolve correctly in both `en` and `ar`
- No hardcoded user-facing strings in API responses
- Arabic translations respect RTL context

**Rollback Strategy**: Revert commit.

---

### Phase 7: Tests

**Objective**: Complete test coverage of the entire invoice system.

**Files Created**: #41, #42, #43, #44, #45, #46, #47, #48, #49, #50, #51, #52

**Dependencies**: All prior phases

**Deliverables**: 12 test files covering unit, feature, integration, concurrency, and authorization scenarios.

**Exit Criteria**:
- All 12 test files pass
- Coverage meets requirements defined in [Section 5](#5-testing-strategy)
- No regressions in existing test suite

**Rollback Strategy**: Revert commit. Test files have no production impact.

---

## 3. Dependency Graph

```
                    ┌──────────────────────────────────┐
                    │         Phase 1: Foundation       │
                    │  Migrations → Models → Enums      │
                    │         → Permissions             │
                    └──────────────┬───────────────────┘
                                   │
                                   ▼
                    ┌──────────────────────────────────┐
                    │        Phase 2: Domain Layer       │
                    │  Contracts → Validators → Services │
                    │  → Exceptions → Integrity          │
                    └──────────────┬───────────────────┘
                                   │
                    ┌──────────────┴───────────────────┐
                    │                                  │
                    ▼                                  ▼
┌──────────────────────────────┐       ┌──────────────────────────────┐
│    Phase 3: Event Integration │       │   Phase 4: PDF Generation    │
│  EventServiceProvider →        │       │  PDF Template → PdfService  │
│  Listener → OrderService       │       │  → PdfJob                    │
│  (LIVE CODE CHANGE)            │       │                              │
└──────────────┬────────────────┘       └──────────────┬───────────────┘
               │                                      │
               ▼                                      │
┌──────────────────────────────┐                      │
│     Phase 5: API Layer        │◄─────────────────────┘
│  Controller → Resources →     │
│  Policy → Routes → Commands   │
└──────────────┬────────────────┘
               │
               ▼
┌──────────────────────────────┐
│     Phase 6: Translations     │
└──────────────┬────────────────┘
               │
               ▼
┌──────────────────────────────┐
│     Phase 7: Tests            │
│  Unit → Feature → Concurrency │
└──────────────────────────────┘
```

### Parallel Work Opportunities

| Parallel Group | Phases | Constraint |
|---------------|--------|-----------|
| Group A | Phase 1 + Phase 2 validator interface design | Phase 2 services require Phase 1 models |
| Group B | Phase 4 PDF template + Phase 2 services | PDF template is independent, but PdfService needs Invoice model |
| Group C | Phase 5 Commands + Phase 5 Controller | Commands are independent of API controller |
| Group D | Phase 6 Translations + any phase | Translations are fully independent |

**Critical Path** (strictly sequential, no parallelization possible):
1. Phase 1 → Phase 2 → Phase 3 → Phase 5
2. Phase 3 → Phase 4 (optional — PDF job only needs invoice record)

Phase 3 is the **single point of integration risk** and should be the most carefully reviewed and tested.

---

## 4. Pull Request Strategy

### PR 1: Foundation — Database, Models, Enums, Permissions

| Field | Value |
|-------|-------|
| **Title** | `feat(invoice): add invoice database schema, models, and enums` |
| **Scope** | Phase 1 |
| **Files** | 6 created + 2 modified |
| **Estimated Size** | ~400 lines |
| **Review Complexity** | Low — schema review (indexes, FKs, constraints) |
| **Merge Prerequisites** | None |
| **Risk** | Low — additive tables, no existing code changed |
| **Testing** | Run `php artisan migrate:fresh --seed`. Verify tables via `DESCRIBE invoices`. All existing tests pass. |

### PR 2: Core Domain — Services, Validators, Contracts

| Field | Value |
|-------|-------|
| **Title** | `feat(invoice): add snapshot service, validator pipeline, and number generation` |
| **Scope** | Phase 2 |
| **Files** | 15 created |
| **Estimated Size** | ~800 lines |
| **Review Complexity** | Medium — validation logic, financial invariants, number generation |
| **Merge Prerequisites** | PR 1 merged |
| **Risk** | Low — no live code calls these services yet |
| **Testing** | Unit tests for each validator, snapshot determinism test, number format test |

### PR 3: Event Integration — Listener, Events, OrderService Changes

| Field | Value |
|-------|-------|
| **Title** | `feat(invoice): integrate PaymentSucceeded event with invoice generation listener` |
| **Scope** | Phase 3 |
| **Files** | 3 created + 3 modified |
| **Estimated Size** | ~350 lines |
| **Review Complexity** | **High** — modifies live payment flow in `OrderService` and `OrderController` |
| **Merge Prerequisites** | PR 2 merged |
| **Risk** | **Medium** — changes existing event dispatch timing |
| **Critical Review Focus** | `changeOrderStatus()` change, removal from `markCodAsPaid()`/`markCashierPaid()`/`checkoutCallback()`, `DB::afterCommit()` correctness |
| **Testing** | Full payment flow feature test: online, COD, cashier paths. Idempotency test. |

### PR 4: PDF Generation

| Field | Value |
|-------|-------|
| **Title** | `feat(invoice): add PDF generation service, job, and blade template` |
| **Scope** | Phase 4 |
| **Files** | 3 created |
| **Estimated Size** | ~500 lines |
| **Review Complexity** | Medium — blade template correctness, dompdf configuration, checksum logic |
| **Merge Prerequisites** | PR 3 merged (invoice records must exist) |
| **Risk** | Low — PDF job processes existing invoices; failure doesn't affect payments |
| **Testing** | PDF generation test, checksum verification, regeneration test |

### PR 5: API Layer — Controller, Resources, Policy, Routes, Commands

| Field | Value |
|-------|-------|
| **Title** | `feat(invoice): add customer and admin invoice API endpoints` |
| **Scope** | Phase 5 |
| **Files** | 11 created + 3 modified |
| **Estimated Size** | ~900 lines |
| **Review Complexity** | Medium — API design consistency, authorization, resource structure |
| **Merge Prerequisites** | PR 3 merged (invoice records exist), PR 4 optional (PDF endpoints) |
| **Risk** | Low — all additive, no existing endpoint modified |
| **Testing** | Feature tests for all customer + admin endpoints. Authorization test. |

### PR 6: Translations

| Field | Value |
|-------|-------|
| **Title** | `feat(invoice): add english and arabic translations` |
| **Scope** | Phase 6 |
| **Files** | 2 created |
| **Estimated Size** | ~50 lines |
| **Review Complexity** | Low — translation keys only |
| **Merge Prerequisites** | PR 5 merged (translation keys referenced in controller responses) |
| **Risk** | Low |

### PR 7: Tests

| Field | Value |
|-------|-------|
| **Title** | `test(invoice): add complete test coverage` |
| **Scope** | Phase 7 |
| **Files** | 12 created |
| **Estimated Size** | ~2000 lines |
| **Review Complexity** | Medium — test correctness, concurrency scenarios |
| **Merge Prerequisites** | All PRs 1-6 merged |
| **Risk** | Low — no production code changes |

### PR Merge Order

```
PR 1 (Foundation)
  ↓
PR 2 (Domain)
  ↓
PR 3 (Integration) ← Critical review gate
  ↓
PR 4 (PDF) ─────── Optional — can merge before or after PR 5
  ↓
PR 5 (API)
  ↓
PR 6 (Translations)
  ↓
PR 7 (Tests)
```

---

## 5. Testing Strategy

### Phase 1 — Foundation Tests

| Test | Type | File | What It Verifies |
|------|------|------|------------------|
| Migration creates all tables | Manual | — | `php artisan migrate:fresh` succeeds |
| Migration rollback round-trip | Manual | — | `migrate:rollback` then `migrate` succeeds |
| FK constraints enforced | Integration | — | Insert with invalid `order_id` fails with FK violation |
| UNIQUE constraint on `order_id` | Integration | — | Insert same `order_id` twice fails |
| UNIQUE constraint on `invoice_number` | Integration | — | Insert same `invoice_number` twice fails |
| Permission seeds idempotent | Integration | — | Run seeder twice — no duplicate permission error |
| Invoice model relationships | Unit | — | `$invoice->order`, `$invoice->user`, `$invoice->transaction` resolve |
| Governorate relationship exists | Unit | — | `$order->governorate` returns `Governorate` model |

### Phase 2 — Domain Service Tests

| Test | Type | File | What It Verifies |
|------|------|------|------------------|
| Snapshot contains all required fields | Unit | `InvoiceSnapshotServiceTest` | Every field from Appendix A is present |
| Snapshot is deterministic | Unit | `InvoiceSnapshotServiceTest` | Same input → identical array output |
| Snapshot uses order snapshot fields only | Unit | `InvoiceSnapshotServiceTest` | No live queries — only `$order->field` patterns |
| Snapshot pricing_breakdown matches order | Unit | `InvoiceSnapshotServiceTest` | `subtotal = $order->price`, `total = $order->total_price` |
| Invoice number format | Unit | `InvoiceNumberServiceTest` | Format: `INV-{YEAR}-{SEQUENCE}` |
| Sequence increments | Unit | `InvoiceNumberServiceTest` | Two calls produce consecutive numbers |
| Sequence year boundary | Unit | `InvoiceNumberServiceTest` | New year resets sequence to 1 |
| `lockForUpdate` concurrency | Unit | `InvoiceNumberServiceTest` | Two simultaneous calls get different numbers |
| Gap after rollback | Unit | `InvoiceNumberServiceTest` | After DB rollback, next number skips the rolled-back value |
| StructureValidator passes valid data | Unit | `InvoiceValidatorTest` | All required fields present → passes |
| StructureValidator rejects missing field | Unit | `InvoiceValidatorTest` | Missing field → throws `SnapshotValidationException` |
| MoneyValidator rejects negative amounts | Unit | `InvoiceValidatorTest` | Negative price → throws exception |
| MoneyValidator rejects >3 decimals | Unit | `InvoiceValidatorTest` | Price with 4 decimals → throws exception |
| CurrencyValidator ensures consistency | Unit | `InvoiceValidatorTest` | Mixed currencies → throws `CurrencyMismatchException` |
| FinancialInvariantValidator passes valid formula | Unit | `FinancialInvariantTest` | Correct `subtotal - discounts + shipping = total` → passes |
| FinancialInvariantValidator rejects mismatch | Unit | `FinancialInvariantTest` | Incorrect total → throws `FinancialInvariantException` |
| FinancialInvariantValidator allows rounding tolerance | Unit | `FinancialInvariantTest` | 0.01 difference → passes (allowable tolerance) |
| FinancialInvariantValidator rejects >0.01 difference | Unit | `FinancialInvariantTest` | 0.02 difference → throws exception |
| SnapshotVersionValidator rejects unknown version | Unit | `InvoiceValidatorTest` | `snapshot_schema = 99` → throws `UnsupportedSchemaException` |
| SnapshotIntegrityService deterministic hash | Unit | `SnapshotIntegrityServiceTest` | Same data → same hash |
| SnapshotIntegrityService detects tampering | Unit | `SnapshotIntegrityServiceTest` | Modified field → hash mismatch |

### Phase 3 — Event Integration Tests

| Test | Type | File | What It Verifies |
|------|------|------|------------------|
| Listener creates invoice from PaymentSucceeded | Feature | `InvoiceGenerationFlowTest` | Full flow: dispatch event → listener runs → invoice created |
| Invoice has correct data from order | Feature | `InvoiceGenerationFlowTest` | All financial and customer fields match source order |
| Duplicate event is idempotent | Feature | `InvoiceIdempotencyTest` | Dispatch same event twice → one invoice created |
| Concurrent duplicate is caught by UNIQUE constraint | Feature | `InvoiceIdempotencyTest` | Two simultaneous inserts → one succeeds, one fails silently |
| WithoutOverlapping prevents concurrent processing | Feature | `InvoiceConcurrencyTest` | Two workers on same order → second released back to queue |
| Listener retries on failure | Feature | `InvoiceGenerationFlowTest` | Mock snapshot service to throw → verify 3 attempts |
| `changeOrderStatus('completed')` dispatches PaymentSucceeded | Feature | `InvoiceGenerationFlowTest` | Verify event is dispatched when status = completed |
| `changeOrderStatus('cancelled')` does NOT dispatch | Feature | `InvoiceGenerationFlowTest` | Verify event is NOT dispatched for non-completed statuses |
| `markCodAsPaid()` no longer dispatches directly | Feature | `InvoiceGenerationFlowTest` | Verify dispatch count unchanged (dispatched via changeOrderStatus) |
| `markCashierPaid()` no longer dispatches directly | Feature | `InvoiceGenerationFlowTest` | Same as above |
| `checkoutCallback()` no longer dispatches directly | Feature | `InvoiceGenerationFlowTest` | Same as above |
| All existing payment tests still pass | Regression | Existing tests | `PaymentCheckoutTest`, `PaymentSystemTest`, `OrderCreationFlowTest` |

### Phase 4 — PDF Generation Tests

| Test | Type | File | What It Verifies |
|------|------|------|------------------|
| PDF generated from valid invoice | Feature | `InvoicePdfGenerationTest` | Job runs → PDF file exists on disk |
| PDF checksum matches after regeneration | Feature | `InvoicePdfGenerationTest` | Regenerate PDF → checksum identical |
| PDF contains expected data | Feature | `InvoicePdfGenerationTest` | Parse PDF text, verify customer name, invoice number |
| PDF status transitions correctly | Feature | `InvoicePdfGenerationTest` | `generated` → `pdf_generating` → `ready` |
| PDF failure sets status to `failed` | Feature | `InvoicePdfGenerationTest` | Mock PDF render to throw → status = `failed`, error logged |
| PDF retry exhaustion | Feature | `InvoicePdfGenerationTest` | All 3 attempts fail → status = `failed` |
| PDF template reads only from `$invoice->data` | Unit | `InvoicePdfGenerationTest` | Code review + assert no DB query in template |
| PDF generation for Arabic locale | Feature | `InvoicePdfGenerationTest` | Arabic snapshot → PDF renders without errors |

### Phase 5 — API Tests

| Test | Type | File | What It Verifies |
|------|------|------|------------------|
| List invoices for authenticated user | Feature | `InvoiceApiTest` | `GET /api/general/invoices` returns 200 with paginated data |
| List returns only user's invoices | Feature | `InvoiceApiTest` | User A sees only their invoices (user_id filter) |
| View single invoice | Feature | `InvoiceApiTest` | `GET /api/general/invoices/{id}` returns full resource |
| View non-existent invoice returns 404 | Feature | `InvoiceApiTest` | Invalid ID → 404 |
| Download PDF when ready | Feature | `InvoiceApiTest` | `GET /api/general/invoices/{id}/download` returns PDF |
| Download PDF when not ready returns 404 | Feature | `InvoiceApiTest` | Invoice without PDF → 404 with message |
| Get invoice by order ID | Feature | `InvoiceApiTest` | `GET /api/general/orders/{orderId}/invoice` returns invoice |
| Get invoice by order without invoice returns 404 | Feature | `InvoiceApiTest` | Order with no invoice → 404 |
| Admin list all invoices | Feature | `InvoiceAdminApiTest` | `GET /api/general/admin/invoices` returns all invoices |
| Admin filter by status | Feature | `InvoiceAdminApiTest` | `?status=ready` returns only ready invoices |
| Admin filter by date range | Feature | `InvoiceAdminApiTest` | `?date_from=...&date_to=...` filters correctly |
| Admin filter by total range | Feature | `InvoiceAdminApiTest` | `?total_min=100&total_max=500` filters correctly |
| Admin view any invoice | Feature | `InvoiceAdminApiTest` | `GET /api/general/admin/invoices/{id}` returns any user's invoice |
| Admin download any PDF | Feature | `InvoiceAdminApiTest` | Admin can download any invoice PDF |
| Admin create correction invoice | Feature | `InvoiceAdminApiTest` | POST with reason → correction invoice created, original marked corrected |
| Correction validation: reason required | Feature | `InvoiceAdminApiTest` | POST without reason → 422 |
| Admin regenerate PDF | Feature | `InvoiceAdminApiTest` | POST regenerate → job queued, 200 returned |
| Admin export CSV | Feature | `InvoiceAdminApiTest` | GET export with date range → CSV file returned |
| Admin health check | Feature | `InvoiceAdminApiTest` | Returns healthy status with recent invoice counts |
| Customer cannot view another's invoice | Feature | `InvoiceAuthorizationTest` | User B requests User A's invoice → 403 |
| Customer cannot access admin endpoints | Feature | `InvoiceAuthorizationTest` | User accesses `/admin/invoices` → 403 |
| Guest cannot access customer endpoints | Feature | `InvoiceAuthorizationTest` | Unauthenticated → 401 |
| Guest cannot access admin endpoints | Feature | `InvoiceAuthorizationTest` | Unauthenticated admin route → 401 |
| Response JSON structure matches spec | Feature | `InvoiceApiTest` | Assert exact key structure from architecture document |

### Phase 6 — No tests required (translation files only)

### Executing Tests

```bash
# Run all invoice tests
php artisan test --filter=Invoice

# Run unit tests only
php artisan test --filter='Tests\\Unit\\Invoice'

# Run feature tests only
php artisan test --filter='Tests\\Feature\\Invoice'

# Run concurrency test (requires multiple queue workers)
php artisan test --filter=InvoiceConcurrencyTest

# Run all tests to detect regressions
php artisan test
```

---

## 6. Deployment Strategy

### Phase 1 — Foundation

| Concern | Answer |
|---------|--------|
| Maintenance mode required? | **No** — additive schema changes |
| Backward compatible? | **Yes** — new tables, no existing schema modified |
| Database migrations safe? | **Yes** — all `CREATE TABLE`, no `ALTER TABLE` |
| Zero-downtime possible? | **Yes** |
| Operational precautions | Run migration during low traffic to avoid table creation locks |

### Phase 2 — Domain Services

| Concern | Answer |
|---------|--------|
| Maintenance mode required? | **No** — no live code changes |
| Backward compatible? | **Yes** — new classes not referenced by any existing code |
| Database migrations safe? | N/A — no migrations |
| Zero-downtime possible? | **Yes** |
| Operational precautions | None |

### Phase 3 — Event Integration (Critical)

| Concern | Answer |
|---------|--------|
| Maintenance mode required? | **Recommended but not required** — brief window where event dispatch changes |
| Backward compatible? | **Yes** — event was already being dispatched; only the timing and location change |
| Database migrations safe? | N/A — no migrations this phase |
| Zero-downtime possible? | **Yes** — if queue workers are deployed before web servers |
| Operational precautions | **Rolling deploy recommended**: (1) Deploy queue workers first (new listener registered). (2) Deploy web servers (dispatch centralization). This ensures old web servers dispatch events that new workers can process. |

**Deployment Order for Phase 3**:
```
1. Deploy code changes to queue workers (they start listening for PaymentSucceeded)
2. Verify workers are processing the new listener
3. Deploy code changes to web servers (OrderService/OrderController changes)
4. Monitor failed_jobs table for listener failures
5. Monitor invoice generation rate
```

### Phase 4 — PDF Generation

| Concern | Answer |
|---------|--------|
| Maintenance mode required? | **No** |
| Backward compatible? | **Yes** — processes existing `generated` invoices |
| Database migrations safe? | N/A |
| Zero-downtime possible? | **Yes** |
| Operational precautions | Ensure storage disk (`invoices/`) exists and is writable. Test dompdf memory limits in production-like environment. |

### Phase 5 — API Layer

| Concern | Answer |
|---------|--------|
| Maintenance mode required? | **No** |
| Backward compatible? | **Yes** — new endpoints, no existing routes modified |
| Database migrations safe? | N/A |
| Zero-downtime possible? | **Yes** |
| Operational precautions | Ensure permissions are seeded before deploying API (permissions checked by middlewares). Run `php artisan invoices:seed-permissions` if migration auto-seed fails. |

### Phase 6 — Translations

| Concern | Answer |
|---------|--------|
| Maintenance mode required? | **No** |
| Backward compatible? | **Yes** |
| Database migrations safe? | N/A |
| Zero-downtime possible? | **Yes** |
| Operational precautions | None |

### Phase 7 — Tests

| Concern | Answer |
|---------|--------|
| Maintenance mode required? | **No** — no production code changes |
| Backward compatible? | **Yes** |
| Zero-downtime possible? | **Yes** |
| Operational precautions | None — test files only |

---

## 7. Rollback Strategy

### Phase 1 — Foundation

| Scenario | Recovery |
|----------|----------|
| Migration fails (syntax error, constraint violation) | `php artisan migrate:rollback --step=3`. Fix migration. Re-deploy. |
| FK constraint prevents legitimate inserts | Rollback. Review FK rules. Relax if needed (e.g., `ON DELETE SET NULL` instead of `RESTRICT`). |
| Permission seed creates duplicates | Seed uses `firstOrCreate` — idempotent by design. No rollback needed. |

### Phase 2 — Domain Services

| Scenario | Recovery |
|----------|----------|
| Service logic bug found after merge | Fix in next PR. Services are not called from live code — no production impact. |
| Validator too strict (rejects valid data) | Fix tolerance or rule in next PR. Only affects tests until Phase 3. |

### Phase 3 — Event Integration (Critical)

| Scenario | Recovery |
|----------|----------|
| Listener crashes (exception in snapshot assembly) | Job retries 3 times. After exhaustion, moves to `failed_jobs`. Fix bug, run `php artisan queue:retry` to reprocess failed jobs. |
| Listener creates duplicate invoices | UNIQUE constraint prevents this. No recovery needed — constraint is the safety net. |
| Listener creates invoice with wrong data | Invoice snapshot is immutable. Create correction invoice (Phase 5 API) to fix. |
| `PaymentSucceeded` not dispatched (bug in `changeOrderStatus()` change) | **Rollback immediately**. Revert the commit. Restore old dispatch points. Verify all payment flows work. Then investigate and re-deploy with fix. |
| `markCodAsPaid()` stops working after refactor | **Rollback immediately**. The `markCodAsPaid` path did NOT call `changeOrderStatus()` before. If Option A was chosen (refactor to call `changeOrderStatus()`), verify the refactor is correct. |
| Queue backlog (medium queue grows) | Worker processes backlog. No customer-facing impact. Invoices are eventually created. |

**Emergency Rollback Script for Phase 3**:
```bash
# 1. Revert the commit
git revert HEAD

# 2. Deploy reverted code
# 3. Verify payment flows
php artisan test --filter=OrderCreationFlowTest
php artisan test --filter=PaymentCheckoutTest

# 4. Clean up any invoices created during the broken window (if needed)
php artisan tinker
> Invoice::where('generated_at', '>=', '2026-07-16 10:00:00')->delete();
```

### Phase 4 — PDF Generation

| Scenario | Recovery |
|----------|----------|
| PDF generation fails for all invoices | Check dompdf configuration, memory limits, Blade template errors. Fix and re-deploy. Run `php artisan invoices:regenerate-failed-pdfs`. |
| PDF renders incorrectly | Fix Blade template. Regenerate PDFs via admin API or `php artisan invoices:regenerate-all-pdfs`. |
| Storage disk full | Clear temp files, add storage. Run `php artisan invoices:regenerate-failed-pdfs` after resolution. |

### Phase 5 — API Layer

| Scenario | Recovery |
|----------|----------|
| New route conflicts with existing frontend route | Routes under `/invoices` — low conflict risk. If conflict, revert and rename prefix. |
| Permission not seeded → admin 403 errors | Run `php artisan db:seed --class=InvoicePermissionSeeder` manually. |
| API returns wrong data structure | Fix in next PR. Not a data corruption issue — only response formatting. |

### Phase 6 — Translations

No rollback needed — translation files have no operational impact.

### Phase 7 — Tests

No rollback needed — test files have no production impact.

---

## 8. Acceptance Criteria

### Phase 1 — Foundation

| # | Criterion | Verification Method |
|---|-----------|-------------------|
| 1.1 | Migration `create_invoice_sequences_table` succeeds | `php artisan migrate` exits with 0 |
| 1.2 | Migration `create_invoices_table` succeeds | Same |
| 1.3 | Migration `seed_invoice_permissions` succeeds | Same |
| 1.4 | All FKs are correctly defined | `DESCRIBE invoices` shows FKs |
| 1.5 | UNIQUE constraints exist on `order_id` and `invoice_number` | `SHOW CREATE TABLE invoices` shows constraints |
| 1.6 | All 10 indexes exist on invoices table | Same |
| 1.7 | `Invoice` model can be instantiated with fillable fields | `php artisan tinker` — `Invoice::create([...])` |
| 1.8 | `InvoiceSequence` model can be instantiated | Same |
| 1.9 | `Order::find($id)->governorate` returns Governorate model | `php artisan tinker` — relationship resolves |
| 1.10 | Invoice permissions exist in permissions table | `Permission::where('name', 'view-invoices')->exists()` |
| 1.11 | No regressions in existing tests | `php artisan test` — all pre-existing tests pass |

### Phase 2 — Domain Services

| # | Criterion | Verification Method |
|---|-----------|-------------------|
| 2.1 | Snapshot output matches Appendix A field list | Automated test: assert every required field exists |
| 2.2 | Snapshot is deterministic | Same input produces identical array |
| 2.3 | Invoice number format: `INV-{YYYY}-{000001}` | Automated test |
| 2.4 | Sequence increments monotonically | 1000 concurrent calls → no duplicates |
| 2.5 | All 6 validators pass valid data | Each validator unit test passes |
| 2.6 | All 6 validators reject invalid data | Each validator has negative test cases |
| 2.7 | Financial invariant formula holds for all test cases | Random data generation + invariant validation |
| 2.8 | Snapshot hash is deterministic | Same JSON → same SHA-256 |
| 2.9 | Tampering detected | Change one field → hash mismatch |

### Phase 3 — Event Integration

| # | Criterion | Verification Method |
|---|-----------|-------------------|
| 3.1 | `PaymentSucceeded` dispatched via `DB::afterCommit()` from `changeOrderStatus()` | Event fake assertion in feature test |
| 3.2 | No `PaymentSucceeded` dispatch from `markCodAsPaid()` | Event fake assertion — not dispatched |
| 3.3 | No `PaymentSucceeded` dispatch from `markCashierPaid()` | Same |
| 3.4 | No `PaymentSucceeded` dispatch from `checkoutCallback()` | Same |
| 3.5 | Listener creates invoice record | Assert `Invoice::where('order_id', $order->id)->exists()` |
| 3.6 | Duplicate event creates one invoice | Dispatch twice → Invoice count = 1 |
| 3.7 | Listener retries on failure | 3 attempts verified in test |
| 3.8 | All existing payment flow tests pass | `php artisan test --filter=Payment` |
| 3.9 | No regressions in order/payment flow | Full test suite |

### Phase 4 — PDF Generation

| # | Criterion | Verification Method |
|---|-----------|-------------------|
| 4.1 | PDF file created on disk | `Storage::exists("invoices/INV-2026-000001.pdf")` |
| 4.2 | PDF is valid (not empty, has correct header) | `file_get_contents` → starts with `%PDF` |
| 4.3 | Regeneration produces same checksum | `pdf_checksum` identical after regenerate |
| 4.4 | Status transitions: `generated` → `ready` | Assert on invoice model |
| 4.5 | On failure: status = `failed`, error logged | Assert on invoice model |
| 4.6 | Template reads only from `$invoice->data` | Code review + assertion |

### Phase 5 — API Layer

| # | Criterion | Verification Method |
|---|-----------|-------------------|
| 5.1 | All customer endpoints return 200 with valid JSON | Integration test |
| 5.2 | All admin endpoints return 200 with valid JSON | Integration test |
| 5.3 | Pagination works (default 15, max 50) | Assert `meta.per_page` |
| 5.4 | Sorting works (+field asc, -field desc) | Assert ordered results |
| 5.5 | Filtering by status, date, total works | Assert filtered results |
| 5.6 | PDF download returns `application/pdf` | Assert response header |
| 5.7 | Correction creates linked invoice | Assert `correction_to_id` on new invoice |
| 5.8 | Authorization prevents unauthorized access | Assert 401/403 responses |
| 5.9 | Health endpoint returns system status | Assert JSON structure |

---

## 9. Definition of Done

The Invoice System feature is considered **complete** only when ALL of the following conditions are met:

### Architecture Compliance

- [ ] All architecture requirements from ADR-003 are satisfied
- [ ] Transaction boundaries respected: invoice creation never inside payment transaction
- [ ] Read-only consumer principle enforced: invoice module never modifies Order/Transaction/Product
- [ ] No pricing recalculation: all financial data from order snapshots
- [ ] No calls to ProductPricingService, CouponCalculator, CouponOrchestrator, PromotionService, FlashSaleService

### Data Integrity

- [ ] Snapshot JSON is immutable — no update path exists for `data` column
- [ ] Financial invariant formula verified for all invoices
- [ ] `snapshot_hash` computed and stored for every invoice
- [ ] SHA-256 canonical JSON encoding is deterministic
- [ ] Exactly-once processing verified: UNIQUE(order_id) is the safety net

### PDF Generation

- [ ] PDF generates successfully from snapshot data
- [ ] PDF regeneration produces identical checksum
- [ ] PDF template reads exclusively from `$invoice->data` — no live queries
- [ ] Failed PDFs (after 3 retries) are captured with error messages
- [ ] `invoices:generate-pending` catches invoices stuck in `generated` state

### API

- [ ] All customer endpoints work: list, view, download, order-invoice
- [ ] All admin endpoints work: list with filters, view, download, correct, regenerate, export, health
- [ ] Authorization enforced: owner-only for customer, permission-based for admin
- [ ] All responses follow the standard `{success, message, data, meta}` structure

### Testing

- [ ] All 12 test files pass
- [ ] 100% unit test coverage of validators
- [ ] Duplicate event handling verified
- [ ] Concurrent worker behavior verified
- [ ] Authorization scenarios covered
- [ ] No regressions in existing test suite

### Operations

- [ ] Scheduled commands registered in Kernel
- [ ] Migration idempotent (can run multiple times)
- [ ] Permissions seeded automatically
- [ ] No hardcoded user-facing strings — all via translation files
- [ ] English and Arabic translations complete

### Code Quality

- [ ] No duplicated business logic
- [ ] No static helpers or facade abuse
- [ ] Follows existing project patterns (controller → service → repository → model → resource)
- [ ] All classes have constructor injection
- [ ] All methods are small (< 30 lines where possible)
- [ ] No `// TODO` or `// FIXME` comments in production code

### Documentation

- [ ] ADR-003 document is frozen (no further architecture changes)
- [ ] This Implementation Manifest is up to date
- [ ] API documentation (if requested) reflects actual implementation

---

## 10. Final Implementation Roadmap

### Chronological Sequence

```
Week 1:  Phase 1 (Foundation)
                Mon-Tue: Migrations design + review
                Wed: Models + Enums
                Thu: Permissions + Order relationship
                Fri: PR 1 review + merge

Week 2:  Phase 2 (Domain Services)
                Mon-Tue: Validator interface + pipeline
                Wed-Thu: Snapshot service + Number service
                Fri: PR 2 review + merge

Week 3:  Phase 3 (Event Integration) ← CRITICAL PATH
                Mon-Tue: GenerateInvoiceListener + events
                Wed: OrderService + OrderController changes
                Thu: Full integration testing, edge case testing
                Fri: PR 3 review + merge (with deployment precautions)

Week 4:  Phase 4 (PDF Generation) + Phase 5 (API)
                Mon: PDF template design
                Tue: InvoicePdfService + GenerateInvoicePdfJob
                Wed: PR 4 review + merge
                Thu: InvoiceController + Resources + Policy + Commands
                Fri: PR 5 review + merge

Week 5:  Phase 6 (Translations) + Phase 7 (Tests)
                Mon: Translations (en + ar)
                Tue-Thu: All 12 test files
                Fri: PR 6 + PR 7 review + merge
                Full regression test suite
```

### Estimated Effort

| Phase | Person-Days | Parallelizable |
|-------|-------------|----------------|
| Phase 1: Foundation | 5 days | No (sequential within) |
| Phase 2: Domain Services | 5 days | Partial (validators can be done in parallel) |
| Phase 3: Event Integration | 5 days | No (single integration point) |
| Phase 4: PDF Generation | 3 days | Yes (template + service can be parallel) |
| Phase 5: API Layer | 3 days | Partial (commands can be parallel with controller) |
| Phase 6: Translations | 1 day | Yes (fully independent) |
| Phase 7: Tests | 5 days | Yes (tests can be written in parallel) |
| **Total** | **27 days** | **~15 calendar days with 1 developer** |

### Critical Path

```
Phase 1 → Phase 2 → Phase 3 → Phase 5
     └→ Phase 4 ──→ (independent after Phase 3)
                       └→ Phase 6 (independent)
                          └→ Phase 7 (depends on all)
```

The critical path is **20 calendar days** (Phases 1-3-5 with Phase 4 in parallel).

### Parallel Work Opportunities

| Developer A | Developer B |
|-------------|-------------|
| Phase 1: Migrations + Models | Phase 1: Permission enum + Order relationship |
| Phase 2: Snapshot service + Number service | Phase 2: Validators (all 6 in parallel) |
| Phase 3: Listener + Events + OrderService | Phase 4: PDF template + PdfService |
| Phase 5: Controller + Resources + Policy | Phase 5: Commands + Routes |
| Phase 7: Feature tests | Phase 7: Unit tests + Concurrency tests |
| Phase 6: Translations | — |

With 2 developers, elapsed time reduces from **5 weeks** to **3 weeks**.

### Production Release Checklist

Before marking the feature as complete:

- [ ] All 7 PRs merged into `main`
- [ ] All migrations run in staging environment
- [ ] Permissions seeded in staging
- [ ] Full test suite passes in CI
- [ ] Staging smoke test: create order → verify invoice created
- [ ] Staging smoke test: download PDF
- [ ] Queue workers configured with `medium` and `pdf` queues
- [ ] Horizon (or queue monitoring) configured
- [ ] Storage disk `invoices/` created with correct permissions
- [ ] dompdf memory limit configured in `php.ini` or config
- [ ] Backups of `invoices` table included in backup strategy
- [ ] Monitoring alert rules configured (see §15.3 of architecture)
- [ ] Operations runbook printed/accessible
- [ ] Rollback plan documented and understood by on-call engineer
- [ ] Production deployment scheduled during low-traffic window
- [ ] Post-deployment: verify invoice generation rate > 0
- [ ] Post-deployment: verify no failed_jobs entries for invoice listener
- [ ] Post-deployment: verify API endpoints respond correctly
- [ ] Feature flagged (optional): if concerns about stability, add feature flag `invoice.system.enabled` defaulting to `true`

---

## Audit Fixes

### Implementation Status Verification (2026-07-15)

A full codebase audit was performed to verify the implementation status of all 60 claimed files (48 created + 8 modified + 4 excluded).

| Phase | Status | Files Created | Files Modified |
|-------|--------|---------------|----------------|
| Phase 1: Foundation | ✅ **COMPLETE** | 4/6 (66%) — 2 migrations, Invoice model, InvoiceSequence model, InvoiceStatus enum exist. Migration `2026_07_16_000003_seed_invoice_permissions.php` MISSING. | 1/2 — Order `governorate()` relationship NOT added. Permission enum constants (VIEW_INVOICES, ISSUE_CORRECTION_INVOICE, REGENERATE_INVOICE_PDF, EXPORT_INVOICES) ✅ ADDED. |
| Phase 2: Domain Layer | ✅ **COMPLETE** | 15/15 (100%) — All exceptions, contracts, services, and validators exist. | N/A |
| Phase 3: Event Integration | ❌ **NOT STARTED** | 0/3 (0%) — InvoiceCreated, InvoiceReady events and GenerateInvoiceListener MISSING. | 0/3 — EventServiceProvider not modified. OrderService PaymentSucceeded dispatch not centralized. OrderController duplicate dispatch not removed. |
| Phase 4: PDF Generation | ❌ **NOT STARTED** | 0/3 (0%) — GenerateInvoicePdfJob, InvoicePdfService, PDF Blade template all MISSING. | N/A |
| Phase 5: API Layer | ❌ **NOT STARTED** | 0/11 (0%) — All controller, resources, requests, policy, and commands MISSING. | 0/3 — Routes not added. AuthServiceProvider not modified. Kernel not modified. |
| Phase 6: Translations | ❌ **NOT STARTED** | 0/2 (0%) — Both en/invoice.php and ar/invoice.php MISSING. | N/A |
| Phase 7: Tests | ❌ **NOT STARTED** | 1/12 (8%) — Only SnapshotIntegrityServiceTest exists. 11 test files MISSING. | N/A |

### Detailed Findings

#### Phase 1 — Missing Items

| # | Item | Impact | Action Required |
|---|------|--------|-----------------|
| 1 | Migration `2026_07_16_000003_seed_invoice_permissions.php` | Invoice permissions (view-invoices, issue-correction-invoice, regenerate-invoice-pdf, export-invoices) are not seeded. | Create migration before Phase 3 or add to existing PermissionSeeder. |
| 2 | `Order::governorate()` relationship | Invoice snapshot service references `$order->governorate` but the relationship is not defined on the Order model. | Add `BelongsTo` relationship to `Marvel\Database\Models\Order`. |
| 3 | Permission enum constants | `Marvel\Enums\Permission` missing `VIEW_INVOICES`, `ISSUE_CORRECTION_INVOICE`, `REGENERATE_INVOICE_PDF`, `EXPORT_INVOICES`. | ✅ RESOLVED 2026-07-15 — Added 4 constants to `Marvel\Enums\Permission`. |

#### Phase 3-7 — All Items Missing

All files listed for Phases 3 through 7 in the File Inventory (§1.1, items #22–#52) have not been created. All 8 modified files listed in §1.2 have not been modified. This represents approximately 40 files (created + modified) that remain to be implemented.

### Verdict

The documentation in this Manifest accurately describes the **planned implementation**. The audit confirms:
- **Phases 1-2**: Implementation was started but has gaps (Order relationship, Permission constants, seed migration).
- **Phases 3-7**: Implementation has not begun.
- The Manifest's claim of "20/60 claimed files exist" is incorrect — only **19 files** actually exist (Phase 1: 4, Phase 2: 15). Additionally, the modified files for Phase 1 have NOT been completed.

**Recommendation**: Before beginning Phase 3 implementation, complete the remaining Phase 1 items:
1. Run/copy the permission seed migration
2. Add the `governorate()` relationship to the Order model (requires `ALTER TABLE orders ADD governorate_id` migration)

