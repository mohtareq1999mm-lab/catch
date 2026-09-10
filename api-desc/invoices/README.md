# Dashboard — Invoices Module (Admin)

## Overview

Dashboard Invoices is the admin/staff invoice management surface exposed under `GET /api/v1/invoices` prefix (loaded via `../../packages/marvel/src/Rest/Routes.php` under `api/v1`).

The module is **admin-owned but invoice-owned in persistence**: all routes resolve to a single controller `App\Http\Controllers\Api\InvoiceController` (`app/Http/Controllers/Api/InvoiceController.php:26`). The same controller also serves customer routes under `../../routes/api.php` (`v1/general/invoices`). Dashboard routes are the **staff** view of that same domain.

This investigation documents the **exact 10-endpoint group** provided for review:

```php
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

Loaded as `api/v1/invoices/*` in production.

## Key Files

| Layer | File |
|-------|------|
| Controller | `../../app/Http/Controllers/Api/InvoiceController.php` |
| Customer Routes | `../../routes/api.php` (lines 133-157, 149-158) + signed PDF routes 162-172 |
| Admin Routes | `../../packages/marvel/src/Rest/Routes.php` (lines 391-403) — canonical; snippet is the reviewed variant |
| Service | `../../app/Services/Invoice/InvoiceService.php` |
| Number Service | `../../app/Services/Invoice/InvoiceNumberService.php` |
| Snapshot Service | `../../app/Services/Invoice/InvoiceSnapshotService.php` |
| Integrity Service | `../../app/Services/Invoice/SnapshotIntegrityService.php` |
| Timeline Service | `../../app/Services/Invoice/InvoiceTimelineService.php` |
| Debit Note Service | `../../app/Services/Invoice/DebitNoteService.php` |
| Model | `../../app/Models/Invoice.php` |
| Status Enum | `../../app/Enums/InvoiceStatus.php` |
| Timeline Model | `../../app/Models/InvoiceTimeline.php` |
| DebitNote Model | `../../app/Models/DebitNote.php` |
| Admin Resource | `../../app/Http/Resources/Invoice/AdminInvoiceResource.php` |
| Admin Collection | `../../app/Http/Resources/Invoice/AdminInvoiceCollection.php` |
| Customer Collection | `../../app/Http/Resources/Invoice/CustomerInvoiceCollection.php` |
| Correct Request | `../../app/Http/Requests/Invoice/CorrectInvoiceRequest.php` |
| DebitNote Request | `../../app/Http/Requests/Invoice/DebitNoteRequest.php` |
| PDF Job | `../../app/Jobs/GenerateInvoicePdfJob.php` |
| Observer / Event | `../../app/Events/InvoiceCreated.php`, `app/Listeners/GenerateInvoiceListener.php` |
| Permissions | `../../packages/marvel/src/Enums/Permission.php` (VIEW_INVOICES, VIEW_INVOICE, VIEW_INVOICE_DOWNLOAD, REGENERATE_INVOICE, CORRECT_INVOICE, CANCEL_INVOICE, ISSUE_DEBIT_NOTE) |
| Migrations | `database/migrations/2026_07_16_*_create_invoices*.php`, `2026_07_28_*` |

## Dependencies

- **BenSampo Enum** — `Permission`, `InvoiceStatus`
- **Spatie Media / DomPDF** — `GenerateInvoicePdfJob` renders `pdf.invoice` via DomPDF (A4, Arial)
- **Storage `public` disk** — `storage/app/public/invoices/{invoice_number}.pdf`
- **Queue** — `meem-high` (GenerateInvoiceListener), `meem-medium` (GenerateInvoicePdfJob)
- **Laravel Sanctum** — `auth:sanctum` on all 10 routes

## Permissions

| Permission | Required For | Enforcement |
|------------|-------------|-------------|
| `view-invoices` | `GET /` → `index` | Constructor middleware `permission:view-invoices` |
| `view-invoice` | `GET /{id}`, `GET /uuid/{uuid}` → `show`, `showByUuid` | Constructor middleware `permission:view-invoice` |
| `view-invoice-download` | `GET /{uuid}/download`, `GET /{uuid}/view` | **Inline** in `pdfFileResponse()` — owner (`invoice.user_id === auth()->id()`) OR permission; else 404 privacy |
| `regenerate-invoice` | `POST /{id}/regenerate` | Constructor middleware |
| `correct-invoice` | `POST /{id}/correct` | Constructor middleware |
| `cancel-invoice` | `POST /{id}/cancel` | Constructor middleware |
| `issue-debit-note` | `POST /{id}/debit-note` | Constructor middleware |
| *(none)* | `GET /verify/{uuid}` | `auth:sanctum` + `throttle:5,1` only — no permission, any authenticated user may verify |

## Routes (Reviewed Group)

| Method | Endpoint (under `/api/v1/invoices`) | Auth | Middleware | Purpose |
|--------|--------------------------------------|------|------------|---------|
| GET | `/` | Sanctum | `permission:view-invoices` | List invoices (paginated, filterable, sortable) |
| GET | `/{uuid}/download` | Sanctum | `throttle:30,1` + inline owner/permission | Stream PDF as attachment |
| GET | `/{uuid}/view` | Sanctum | `throttle:30,1` + inline owner/permission | Stream PDF inline (browser view) |
| GET | `/{id}` | Sanctum | `whereNumber` + `permission:view-invoice` | Show by numeric ID |
| POST | `/{id}/regenerate` | Sanctum | `whereNumber` + `permission:regenerate-invoice` | Regenerate PDF (async) |
| POST | `/{id}/correct` | Sanctum | `whereNumber` + `permission:correct-invoice` | Create correction invoice |
| POST | `/{id}/cancel` | Sanctum | `whereNumber` + `permission:cancel-invoice` | Cancel invoice |
| POST | `/{id}/debit-note` | Sanctum | `whereNumber` + `permission:issue-debit-note` | Issue debit note |
| GET | `/verify/{uuid}` | Sanctum | `throttle:5,1` (no permission) | Verify authenticity / tamper check |
| GET | `/uuid/{uuid}` | Sanctum | `permission:view-invoice` | Show by UUID (admin) |

> **Note:** Customer-facing routes `GET /api/v1/general/invoices/my-invoices`, signed `view/{uuid}`/`download/{uuid}`, and `GET /api/v1/general/orders/{orderId}/invoice` are documented in `api-desc/invoice/` — this dashboard doc covers only the 10-route admin group.
