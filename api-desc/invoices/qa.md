# Dashboard Invoices — QA Test Cases

## Test Files (existing invoice suite, same controller)

- `../../tests/Feature/Invoice/MyInvoicesEndpointTest.php`
- `../../tests/Feature/Invoice/InvoiceVerifyEndpointTest.php`
- `tests/Feature/CustomerInvoiceByUuidTest.php`
- `tests/Feature/Invoice/InvoicePdfDownloadTest.php` (if present)
- `tests/Feature/Invoice/InvoiceCorrectionTest.php`
- `tests/Feature/Invoice/InvoiceCancellationTest.php`
- `tests/Feature/Invoice/InvoiceRegenerateTest.php`
- `tests/Feature/Invoice/InvoiceDebitNoteTest.php`

> Dashboard group reuses same `InvoiceController` — existing invoice tests cover behavior; new cases below target the 10-route group specifics (`view` inline, throttle, where* constraints).

---

## API Functionality Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| F1 | List invoices | `GET /invoices` with valid permission | 200 pagination + AdminInvoiceCollection |
| F2 | List with search | `?search=INV-` matches invoice_number/order_number | 200 filtered |
| F3 | List with status | `?status=ready` | 200 filtered |
| F4 | List with sort | `?sort_by=total&sort_direction=asc` | 200 ordered |
| F5 | Show by ID | `GET /invoices/{id}` | 200 AdminInvoiceResource |
| F6 | Show by UUID | `GET /invoices/uuid/{uuid}` | 200 same shape |
| F7 | View PDF inline | `GET /invoices/{uuid}/view` owner | 200 binary `inline` |
| F8 | Download PDF | `GET /invoices/{uuid}/download` owner | 200 binary `attachment` + downloaded_at |
| F9 | Verify authentic | `GET /invoices/verify/{uuid}` valid | 200 authentic true + verify_count++ |
| F10 | Verify tampered | Tamper snapshot_hash then verify | 409 authentic false tampered true |
| F11 | Regenerate | `POST /invoices/{id}/regenerate` status ready | 200 pdf_generating + job dispatched |
| F12 | Correct | `POST /invoices/{id}/correct` with reason | 200 new correction AdminInvoiceResource |
| F13 | Cancel | `POST /invoices/{id}/cancel` with reason | 200 status cancelled |
| F14 | Debit note | `POST /invoices/{id}/debit-note` amount+reason | 201 DN number |

---

## Validation Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| V1 | List limit clamped | `?limit=500` → capped 100 | 200 per_page 100 |
| V2 | Correct without reason | `POST /correct` {} | 422 reason required |
| V3 | Correct overrides invalid | `overrides.total: -5` | 422 |
| V4 | Cancel without reason | `POST /cancel` {} | 422 |
| V5 | Debit without amount | `POST /debit-note` {reason} | 422 |
| V6 | Debit negative amount | `amount: 0` or `-1` | 422 min 0.01 |
| V7 | Correct reason >500 | long string | 422 |
| V8 | Cancel reason >500 | long string | 422 |
| V9 | Regenerate wrong status | cancelled invoice | 422 |
| V10 | Correct wrong status | failed invoice correct | 422 |
| V11 | Debit wrong status | cancelled → debit | 422 |
| V12 | View malformed UUID | `GET /{not-uuid}/view` | 404 routing |
| V13 | Show malformed ID | `GET /invoices/abc` (non-numeric) | 404 whereNumber |

---

## Authorization Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| A1 | Guest list | No token `GET /` | 401 |
| A2 | Guest download | No token `GET /{uuid}/download` | 401 |
| A3 | Guest view | No token `GET /{uuid}/view` | 401 |
| A4 | Guest show | No token `GET /{id}` | 401 |
| A5 | Guest verify | No token `GET /verify/{uuid}` | 401 (auth:sanctum group) |
| A6 | No view-invoices | Auth without permission list | 403 |
| A7 | No view-invoice | Auth without permission show/uuid | 403 |
| A8 | No regenerate-invoice | Auth without permission regenerate | 403 |
| A9 | No correct-invoice | Auth without permission correct | 403 |
| A10 | No cancel-invoice | Auth without permission cancel | 403 |
| A11 | No issue-debit-note | Auth without permission debit-note | 403 |
| A12 | Download non-owner no permission | Other user's invoice, lacks view-invoice-download | 404 privacy |
| A13 | Download non-owner with permission | Other user's invoice, has view-invoice-download | 200 |
| A14 | View non-owner same as download | Inline variant | Same as A12/A13 |
| A15 | Verify any auth works | Any auth user, no permission needed | 200 |

---

## Edge Case Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| E1 | Show unknown ID | `GET /invoices/99999` | 404 Resource Not Found |
| E2 | Show unknown UUID | `GET /uuid/550e8400-...` fake | 404 |
| E3 | Verify unknown UUID | `GET /verify/550e8400-...` fake | 404 envelope |
| E4 | Download unknown UUID | `GET /{uuid}/download` fake | 404 |
| E5 | View unknown UUID | `GET /{uuid}/view` fake | 404 |
| E6 | Download before PDF ready | `pdf_path=null` | 404 PDF not yet generated + status |
| E7 | View before PDF ready | same | 404 PDF not yet generated |
| E8 | Regenerate race | Two concurrent regenerates | Second 422 or queued (lockForUpdate) |
| E9 | Correct then correct again | Correct already-corrected invoice | 422 status guard |
| E10 | Cancel then cancel again | Cancel cancelled invoice | 422 |
| E11 | Verify increments | Call verify twice → verify_count +2, verified_at unchanged on second | OK |
| E12 | Download idempotence | Download twice → downloaded_at unchanged on second | OK |
| E13 | Pagination boundary | `?page=999` beyond last_page | 200 empty data array |
| E14 | Search empty string | `?search=` | 200 all invoices (when clause skipped on empty?) |
| E15 | Sort by invalid field | `?sort_by=invalid` | Falls back to created_at |

---

## Throttle Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| T1 | Download throttle | 31st request in 1 min `GET /{uuid}/download` | 429 |
| T2 | View throttle | 31st request `GET /{uuid}/view` | 429 |
| T3 | Verify throttle | 6th request `GET /verify/{uuid}` in 1 min | 429 |
| T4 | Throttle isolation | view throttle does not affect verify throttle (separate limiters) | OK |

---

## State Machine Tests

| # | Test | Description | Expected |
|---|------|-------------|----------|
| S1 | Regenerate from failed | `failed → pdf_generating` | 200 + job |
| S2 | Regenerate from cancelled | `cancelled → pdf_generating` | 422 |
| S3 | Cancel from verified | `verified → cancelled` | 200 |
| S4 | Cancel from archived | `archived → cancelled` | 422 (model throws) |
| S5 | Direct status tamper | `Invoice::where()->update(['status'=>'archived'])` from `generated` bypass → save hook throws | RuntimeException |

---

## Missing Coverage (recommended)

- [ ] Concurrent `correct` vs `cancel` on same invoice (row lock)
- [ ] `download` after `cancel` still streams existing PDF (should? spec says generated/verified allow debit-note but download not blocked by cancelled — check)
- [ ] `verify` with snapshot_hash manually altered in DB → 409 tampered
- [ ] `view`/`download` file missing on disk after `pdf_path` set → 404
- [ ] `invoice_number` uniqueness race via `InvoiceNumberService lockForUpdate` under parallel generation
- [ ] Timeline `record*` entries created for each mutation
- [ ] Resource includes `snapshot` only when `data` present; list does NOT include snapshot
