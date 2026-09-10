# Dashboard Invoices — Backend Architecture

## Overview

Dashboard Invoices is the **admin invoice management API** under prefix `invoices`. It exposes 10 endpoints for listing, viewing (by ID/UUID), streaming PDFs (view/download), verifying authenticity, and lifecycle mutations (regenerate, correct, cancel, debit-note). All routes share `auth:sanctum` and resolve to `App\Http\Controllers\Api\InvoiceController` (`app/Http/Controllers/Api/InvoiceController.php:26`). Permission enforcement is split: collection/show/mutation via constructor middleware, PDF endpoints via inline ownership check.

## Endpoints

| Method | URL (under `/api/v1/invoices`) | Auth | Permission | Purpose |
|--------|-------------------------------|------|------------|---------|
| GET | `/` | Sanctum | `view-invoices` | List (paginated, filterable, sortable) |
| GET | `/{uuid}/download` | Sanctum + throttle:30,1 | Inline: owner OR `view-invoice-download` | Stream PDF attachment |
| GET | `/{uuid}/view` | Sanctum + throttle:30,1 | Inline: owner OR `view-invoice-download` | Stream PDF inline |
| GET | `/{id}` | Sanctum | `view-invoice` | Show by numeric ID |
| POST | `/{id}/regenerate` | Sanctum | `regenerate-invoice` | Regenerate PDF (async job) |
| POST | `/{id}/correct` | Sanctum | `correct-invoice` | Create correction invoice |
| POST | `/{id}/cancel` | Sanctum | `cancel-invoice` | Cancel invoice |
| POST | `/{id}/debit-note` | Sanctum | `issue-debit-note` | Issue debit note |
| GET | `/verify/{uuid}` | Sanctum + throttle:5,1 | — | Verify / tamper-check |
| GET | `/uuid/{uuid}` | Sanctum | `view-invoice` | Show by UUID |

> **Customer complement:** `GET /api/v1/general/invoices/my-invoices`, signed `view/{uuid}`/`download/{uuid}`, `GET /api/v1/general/orders/{orderId}/invoice` live in `routes/api.php:133-172` and are documented in `api-desc/invoice/`. Signed routes use `signed` middleware (no Sanctum).

## Route Definitions

**Reviewed snippet (intended canonical location: `../../packages/marvel/src/Rest/Routes.php` inside `api/v1` group, loaded by `RestApiServiceProvider`):**

```php
// packages/marvel/src/Rest/Routes.php — inside Route::middleware('auth:sanctum')->group under api/v1
Route::prefix('invoices')->group(function () {
    Route::middleware(['auth:sanctum'])->group(function () {
        Route::get('/', [InvoiceController::class, 'index']);
        Route::get('{uuid}/download', [InvoiceController::class, 'download'])->whereUuid('uuid')->middleware('throttle:30,1');
        Route::get('{uuid}/view', [InvoiceController::class, 'view'])->whereUuid('uuid')->middleware('throttle:30,1');
        Route::get('{id}', [InvoiceController::class, 'show'])->whereNumber('id');
        Route::post('{id}/regenerate', [InvoiceController::class, 'regenerate'])->whereNumber('id');
        Route::post('{id}/correct', [InvoiceController::class, 'correct'])->whereNumber('id');
        Route::post('{id}/cancel', [InvoiceController::class, 'cancel'])->whereNumber('id');
        Route::post('{id}/debit-note', [InvoiceController::class, 'issueDebitNote'])->whereNumber('id');
        Route::get('verify/{uuid}', [InvoiceController::class, 'verify'])->middleware('throttle:5,1');
        Route::get('uuid/{uuid}', [InvoiceController::class, 'showByUuid']);
    });
});
```

**Current production divergence:**

| Aspect | Reviewed snippet | Production (`Routes.php:391-403` + `routes/api.php:133-172`) |
|--------|-----------------|-------------------------------------------------------------|
| `view` route | Present (`{uuid}/view`) | Not in admin group; inline/view lives as `InvoiceController@view` but no admin route — only signed `v1/general/invoices/view/{uuid}` |
| `verify` location | Inside `invoices` prefix (`/invoices/verify/{uuid}`) | Both: `/invoices/verify/{uuid}` (admin) + `/general/invoices/verify/{uuid}` (customer) — snippet consolidates to admin only |
| `uuid/{uuid}` constraint | None in snippet | Production adds `whereUuid` on general side; admin snippet omits — should add |
| `verify` constraint | None in snippet | Should be `whereUuid` |

**Middleware summary:**

| Route | Middleware |
|-------|------------|
| All 10 | `auth:sanctum` (group) |
| `/{uuid}/download`, `/{uuid}/view` | `throttle:30,1` + inline owner/permission in `InvoiceController::pdfFileResponse:280` |
| `/verify/{uuid}` | `throttle:5,1` (no permission) |
| `/`, `/{id}`, `/uuid/{uuid}`, `/regenerate`, `/correct`, `/cancel`, `/debit-note` | `permission:*` via constructor |

## Controller Flow

**File:** `app/Http/Controllers/Api/InvoiceController.php:26`

```php
public function __construct(
  private InvoiceService $invoiceService,
  private InvoiceTimelineService $timelineService,
  private DebitNoteService $debitNoteService,
) {
  $this->middleware('permission:'.Permission::VIEW_INVOICES, ['only'=>['index']]);
  $this->middleware('permission:'.Permission::VIEW_INVOICE, ['only'=>['show','showByUuid']]);
  $this->middleware('permission:'.Permission::REGENERATE_INVOICE, ['only'=>['regenerate']]);
  $this->middleware('permission:'.Permission::CORRECT_INVOICE, ['only'=>['correct']]);
  $this->middleware('permission:'.Permission::CANCEL_INVOICE, ['only'=>['cancel']]);
  $this->middleware('permission:'.Permission::ISSUE_DEBIT_NOTE, ['only'=>['issueDebitNote']]);
}
```

```
GET /
  → InvoiceController@index(Request) :43
    → perPage = min((int)limit, 100)
    → Invoice::query()->with(['order','user'])
      → when(search) → where(invoice_number LIKE OR order.order_number LIKE)
      → when(status/order_id/user_id/invoice_series/currency/from/to)
      → when(sort_by) → orderBy(allowed field, direction) else orderBy(created_at, desc)
      → paginate(perPage)
    → AdminInvoiceCollection($paginator) → apiResponse 200

GET /{uuid}/download
  → InvoiceController@download:264 → pdfFileResponse(uuid, 'attachment', recordDownload:true) :280
    → Invoice::with('order')->where('uuid',uuid)->firstOrFail()
    → if user_id !== auth()->id() && !can(VIEW_INVOICE_DOWNLOAD) → apiResponse 404
    → if !pdf_path → apiResponse 404 + {status, pdf_generated_at}
    → if recordDownload: update downloaded_at (first only) + timeline.recordDownloaded
    → Storage::disk('public')->response('invoices/{pdf_path}', filename, [pdf headers, Content-Disposition: attachment])

GET /{uuid}/view
  → InvoiceController@view:273 → pdfFileResponse(uuid,'inline') :280
    → same lookup + auth + pdf_path checks
    → no download bookkeeping
    → Content-Disposition: inline

GET /{id}
  → InvoiceController@show:61
    → Invoice::with(['order.orderItems','transaction','user'])->findOrFail(id)
    → AdminInvoiceResource::make(invoice) → apiResponse 200

POST /{id}/regenerate
  → InvoiceController@regenerate:121
    → findOrFail → status in ['failed','ready','generated'] else apiResponse 422
    → update status='pdf_generating', generation_attempts++, last_generation_error=null
    → timeline.recordPdfRegenerated
    → GenerateInvoicePdfJob::dispatch(invoice) → apiResponse 200 {invoice_id, status:pdf_generating}

POST /{id}/correct
  → InvoiceController@correct:151 (CorrectInvoiceRequest)
    → try InvoiceService::correctInvoice(id, overrides, reason, auth()->id())
        → DB::transaction + lockForUpdate, status in generated/ready/verified/downloaded/printed
        → generate new number, clone snapshot with data_set overrides, compute hash, create correction (is_correction, correction_to_id)
        → mark original status='corrected', corrected_at, correction_reason, timeline events
        → afterCommit dispatch InvoiceCreated + GenerateInvoicePdfJob
      catch ModelNotFoundException → rethrow → Handler 404
      catch RuntimeException → apiResponse 422

POST /{id}/cancel
  → InvoiceController@cancel:180 (inline validate reason)
    → try InvoiceService::cancelInvoice(id, reason, auth()->id())
        → lockForUpdate, status in generated/ready/failed/corrected/verified/downloaded/printed
        → update status='cancelled', cancelled_at, cancellation_reason, timeline.recordCancelled
      catch ModelNotFoundException → rethrow 404
      catch RuntimeException → 422

POST /{id}/debit-note
  → InvoiceController@issueDebitNote:220 (DebitNoteRequest amount, reason)
    → findOrFail → status in generated/ready/verified/downloaded/printed else 422
    → DebitNoteService::generate(invoice, amount, reason, auth()->id()) → DN-{YEAR}-{SEQ}
    → apiResponse 201

GET /verify/{uuid}
  → InvoiceController@verify:202
    → InvoiceService::verifyInvoice(uuid) → find + hash('sha256', snapshot_hash+secret) vs verification_hash via hash_equals
    → null → 404
    → tampered → 409 {authentic:false, tampered:true}
    → authentic: increment verify_count, last_verified_at=now(), verified_at on first, timeline.recordVerified → 200 {authentic:true, invoice:InvoiceResource, order, qr_content}

GET /uuid/{uuid}
  → InvoiceController@showByUuid:92
    → Invoice::with(['order.orderItems','transaction','user'])->where('uuid',uuid)->firstOrFail()
    → AdminInvoiceResource
```

## Services

| Service | File | Responsibility |
|---------|------|----------------|
| `InvoiceService` | `../../app/Services/Invoice/InvoiceService.php` | `generateFromOrder`, `verifyInvoice`, `correctInvoice`, `cancelInvoice` |
| `InvoiceNumberService` | `../../app/Services/Invoice/InvoiceNumberService.php` | Gapless `INV/DN/CN-{YEAR}-{SEQ}` via `invoice_sequences` + `lockForUpdate` |
| `InvoiceSnapshotService` | `../../app/Services/Invoice/InvoiceSnapshotService.php` | `buildFullSnapshot(order)` — freezes order/customer/addresses/items/pricing/payment |
| `SnapshotIntegrityService` | `../../app/Services/Invoice/SnapshotIntegrityService.php` | `computeHash(sorted JSON)` → sha256 |
| `InvoiceTimelineService` | `../../app/Services/Invoice/InvoiceTimelineService.php` | `recordGenerated/PdfRegenerated/Verified/Downloaded/Corrected/Cancelled` |
| `DebitNoteService` | `../../app/Services/Invoice/DebitNoteService.php` | `generate(invoice, amount, reason, user)` |

**Verification hash:** `verification_hash = hash('sha256', snapshot_hash . config('app.key'))` via `InvoiceService::computeVerificationHash`.

## Model

**File:** `app/Models/Invoice.php:14` — table `invoices`, no soft deletes (immutable records)

**Fillable:** uuid, order_id, transaction_id, user_id, correction_to_id, invoice_number, invoice_series, sequence_number, sequence_year, subtotal, shipping_price, coupon_discount, promotion_discount, total_discount, total, amount_paid, currency, payment_method, payment_gateway, status, data, snapshot_hash, verification_hash, pdf_*, generation_attempts, is_correction, correction_*, cancelled_*, generated_*, verified_*, downloaded_*, printed_*, archived_*, verify_count.

**Casts:** data→array, is_correction→bool, timestamps→datetime.

**Boot hooks:**

- `creating` → `uuid = Str::orderedUuid()` if empty
- `saving` → if `status` dirty, `InvoiceStatus::tryFrom(original/current)` → `canTransitionTo` else throw `RuntimeException "Invalid invoice status transition: X → Y"` (enforces state machine at persistence layer)

**Relations:** `order` (BelongsTo Order), `transaction`, `user`, `correctionTo` (self), `corrections` (HasMany self), `timeline`, `creditNotes`, `debitNotes`.

## Status State Machine

**Enum:** `app/Enums/InvoiceStatus.php:20` — `allowedTransitions()`:

```
pending → generating, cancelled
generating → generated, failed
generated → pdf_generating, ready, failed, verified, downloaded, printed, corrected, cancelled
pdf_generating → ready, failed
ready → pdf_generating, downloaded, printed, verified, failed, corrected, cancelled, archived
failed → pdf_generating, cancelled
verified → downloaded, printed, cancelled, archived
downloaded → printed, verified, cancelled, archived
printed → downloaded, verified, cancelled, archived
corrected → cancelled, archived
cancelled → archived
archived → (terminal)
```

Enforced at enum (`canTransitionTo`) + model `saving` hook. Controller `regenerate` allowlist narrows to `failed/ready/generated`.

## Resources

| Endpoint | Resource |
|----------|----------|
| `GET /` | `AdminInvoiceCollection` |
| `GET /{id}`, `GET /uuid/{uuid}`, `POST /correct`, `POST /cancel` | `AdminInvoiceResource` |
| `GET /verify/{uuid}` | `InvoiceResource` + order/qr (inside `data`) |
| `GET /{uuid}/download`, `GET /{uuid}/view` | Raw PDF binary (no resource) |
| `POST /{id}/debit-note` | Raw `DebitNote` model (201) |
| `POST /{id}/regenerate` | `{invoice_id, status}` |

**AdminInvoiceResource** (`AdminInvoiceResource.php:10`): id, uuid, order_id, invoice_number, status, subtotal/shipping/coupon/promotion/total_discount/total/amount_paid (rounded 2dp), currency, payment_method/gateway, snapshot_hash, verification_hash, pdf_generated_at, generated_at, generation_attempts, last_generation_error, is_correction, correction_reason, corrected_at, cancelled_at, cancellation_reason, verified_at, downloaded_at, printed_at, archived_at, last_verified_at, verify_count, created_at, verification_url (when uuid), qr_content (when uuid), view_url (when id), download_url (when uuid+pdf_path), snapshot (when data), timeline/debit/credit summaries when relations loaded.

## Request Validation

| Request | File | Rules |
|---------|------|-------|
| `CorrectInvoiceRequest` | `CorrectInvoiceRequest.php:14` | `reason required|max:500`, `overrides nullable array` + nested `total/amount_paid/shipping_price numeric min:0`, `customer.email email`, etc. |
| `DebitNoteRequest` | `DebitNoteRequest.php:14` | `amount required|numeric|min:0.01`, `reason required|max:500` |
| `cancel` inline | `InvoiceController.php:182` | `reason required|string|max:500` |
| `index` filters | Inline `when()` | No FormRequest — free-form query params, clamped limit, allowlisted sort |

## PDF Generation

```
PaymentSucceeded event → GenerateInvoiceListener (queue meem-high, afterCommit, 5 retries)
  → InvoiceService::generateFromOrder → DB transaction, snapshot, validate, hash, number, create
  → afterCommit: InvoiceCreated event → GenerateInvoicePdfJob (queue meem-medium, 3 tries)
      → DomPDF loadView('pdf.invoice') → storage/app/public/invoices/{INV-...}.pdf
      → update status ready, pdf_path, pdf_checksum (md5), pdf_generated_at, generation_attempts
      → on failure: status failed, last_generation_error, increment attempts, rethrow → retry
```

`regenerate` reuses same job path, resetting `pdf_generating`.

## Database Schema (see `database.md`)

Primary tables: `invoices` (uuid+invoice_number unique, order_id unique, indexes on user_id/status), `invoice_sequences` (series,year PK), `invoice_timeline`, `debit_notes`, `credit_notes`. No soft deletes.

## Known Gaps vs Production

1. Snippet omits `whereUuid` on `verify/{uuid}` and `uuid/{uuid}` — add for consistency.
2. Snippet `view` route was absent from prior admin group — ensure `permits` + `throttle` parity with `download`.
3. Route order matters if `/uuid/{uuid}` were ever ambiguous with `/{id}` — numeric vs UUID constraints already disambiguate, but keep `uuid/` prefix before `/{id}` or document.
