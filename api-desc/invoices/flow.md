# Request Flows — Dashboard Invoices (Admin)

> All 10 routes under `GET /api/v1/invoices` → `App\Http\Controllers\Api\InvoiceController` (`InvoiceController.php:26`). Prefix loaded as `api/v1/invoices` (snippet). Middleware `auth:sanctum` on group; PDF/verify add throttle.

---

## Flow 1: List Invoices

```
Client → GET /api/v1/invoices?status=ready&limit=20&sort_by=total&search=INV-
         ↓
    [auth:sanctum] → [permission:view-invoices] (constructor InvoiceController:35)
         ↓
    InvoiceController@index(Request) :43
      → perPage = min((int)limit, 100)
      → Invoice::query()->with(['order','user'])
          → when(search) → where invoice_number LIKE OR order.order_number LIKE
          → when(status/order_id/user_id/invoice_series/currency/from/to)
          → when(sort_by in [created_at,total,status,invoice_number]) → orderBy(field, direction) else orderBy(created_at, desc)
          → paginate(perPage)
         ↓
    AdminInvoiceCollection($paginator)
         ↓
    Return { status:200, message, success:true, data:{ data[], current_page, total, links } }
```

Error: `401` guest, `403` missing permission.

---

## Flow 2: Download PDF (Attachment)

```
Client → GET /api/v1/invoices/550e8400-.../download
         ↓
    [auth:sanctum] → [throttle:30,1]
         ↓
    InvoiceController@download(uuid) :264 → pdfFileResponse(uuid,'attachment', recordDownload:true) :280
      → Invoice::with('order')->where('uuid',uuid)->firstOrFail() → 404 if unknown
      → Inline auth: invoice.user_id === auth()->id() OR auth()->user()->can('view-invoice-download')
          → Fail → 404 { Not found } (privacy — no existence leak)
      → if !pdf_path → 404 { PDF not yet generated, data:{status, pdf_generated_at} }
      → if !Storage::disk('public')->exists('invoices/{pdf_path}') → 404
      → if recordDownload: update downloaded_at on first download + timeline.recordDownloaded
         ↓
    Storage::disk('public')->response('invoices/{pdf_path}', filename, [
      Content-Type: application/pdf,
      Content-Disposition: attachment; filename="INV-...pdf"
    ])  → 200 binary PDF
```

Throttle `429` after 30/min. View variant below differs only in disposition + no bookkeeping.

---

## Flow 3: View PDF Inline

```
Client → GET /api/v1/invoices/550e8400-.../view
         ↓
    [auth:sanctum] → [throttle:30,1]
         ↓
    InvoiceController@view(uuid) :273 → pdfFileResponse(uuid,'inline') :280
      → same lookup + inline owner/permission check + pdf_path check as download
      → NO downloaded_at update, NO timeline recordDownloaded
         ↓
    Storage::disk('public')->response('invoices/{pdf_path}', filename, [
      Content-Type: application/pdf,
      Content-Disposition: inline; filename="INV-...pdf"
    ]) → 200 binary PDF (renders in browser/iframe)
```

Frontend: `fetch(url, {headers:{Authorization}}).then(r=>r.blob()).then(URL.createObjectURL)` or direct navigation with token via query? (token must be header — cannot be query).

---

## Flow 4: Show by Numeric ID

```
Client → GET /api/v1/invoices/12
         ↓
    [auth:sanctum] → [permission:view-invoice] → whereNumber('id') guard
         ↓
    InvoiceController@show(id) :61
      → Invoice::with(['order.orderItems','transaction','user'])->findOrFail(id)
          → 404 { Resource Not Found } if unknown
         ↓
    AdminInvoiceResource::make(invoice) → includes verification_url/qr_content/view_url/download_url/snapshot/timeline summaries
         ↓
    Return { status:200, message, success:true, data: resource }
```

Same shape as Flow 5.

---

## Flow 5: Show by UUID

```
Client → GET /api/v1/invoices/uuid/550e8400-...
         ↓
    [auth:sanctum] → [permission:view-invoice] → (add whereUuid in production)
         ↓
    InvoiceController@showByUuid(uuid) :92
      → Invoice::with(['order.orderItems','transaction','user'])->where('uuid',uuid)->firstOrFail()
         ↓
    AdminInvoiceResource::make(invoice)
         ↓
    Return 200 same as Flow 4; 404 if unknown uuid
```

Note: customer side also has `GET /api/v1/general/orders/{orderId}/invoice` (owner-scoped) — separate doc.

---

## Flow 6: Verify Authenticity

```
Client → GET /api/v1/invoices/verify/550e8400-...
         ↓
    [auth:sanctum] → [throttle:5,1]  (NO permission middleware)
         ↓
    InvoiceController@verify(uuid) :202
      → InvoiceService::verifyInvoice(uuid)
          → Invoice::where('uuid',uuid)->with(['order','user'])->first() → null → 404
          → expected = hash('sha256', snapshot_hash . config('app.key'))
          → hash_equals(expected, stored verification_hash)? 
              → mismatch → { tampered:true } → controller → 409 { authentic:false, tampered:true }
              → match → increment verify_count, last_verified_at=now(), verified_at on first, timeline.recordVerified
         ↓
    Return 200 { authentic:true, invoice:InvoiceResource, order:{id,order_number,status,payment_status,fulfillment_status}, qr_content:url }
    or 409 / 404 as above
```

---

## Flow 7: Regenerate PDF (Async)

```
Client → POST /api/v1/invoices/12/regenerate  (empty body)
         ↓
    [auth:sanctum] → [permission:regenerate-invoice] → whereNumber
         ↓
    InvoiceController@regenerate(id) :121
      → Invoice::findOrFail(id) → 404 if unknown
      → if status NOT in ['failed','ready','generated'] → 422 { ERROR_ADDING_ITEMS_TO_ORDER }
      → update status='pdf_generating', generation_attempts++, last_generation_error=null
      → timeline.recordPdfRegenerated
      → GenerateInvoicePdfJob::dispatch(invoice)
         ↓
    Return 200 { invoice_id, status:'pdf_generating' }
         ↓
    [async] GenerateInvoicePdfJob (meem-medium, 3 tries, backoff [30,120,300])
      → DomPDF loadView('pdf.invoice', invoice, A4 portrait) → storage/app/public/invoices/INV-...pdf
      → on success: update pdf_path, pdf_checksum (md5), pdf_generated_at, status='ready', generation_attempts
      → on failure: status='failed', last_generation_error, increment attempts, rethrow → retry
```

State machine: `ready` → `pdf_generating` is legal (INV-002 fix).

---

## Flow 8: Correct Invoice (Correction)

```
Client → POST /api/v1/invoices/12/correct  Body: { reason, overrides?:{total, amount_paid, shipping_price, customer, billing_address, shipping_address, notes} }
         ↓
    [auth:sanctum] → [permission:correct-invoice] → CorrectInvoiceRequest validation
         ↓
    InvoiceController@correct(request, id) :151
      → InvoiceService::correctInvoice(id, overrides, reason, authId)
          → DB::transaction + lockForUpdate findOrFail
          → status in [generated,ready,verified,downloaded,printed] else RuntimeException → 422
          → InvoiceNumberService::generateNext() → INV-2026-000013
          → clone snapshot, apply overrides via data_set(), compute new hash
          → create correction: is_correction=true, correction_to_id=original, status='generated'
          → update original: status='corrected', corrected_at, correction_reason
          → timeline.recordCorrected + recordGenerated
          → afterCommit: InvoiceCreated dispatch + GenerateInvoicePdfJob for correction
         ↓
    Return 200 { message:"Invoice corrected successfully", data: AdminInvoiceResource(correction) }
    Errors: 404 unknown id, 422 validation or un-correctable status
```

---

## Flow 9: Cancel Invoice

```
Client → POST /api/v1/invoices/12/cancel  Body: { reason }
         ↓
    [auth:sanctum] → [permission:cancel-invoice]
      → inline validate reason required|string|max:500
         ↓
    InvoiceController@cancel(request, id) :180
      → InvoiceService::cancelInvoice(id, reason, authId)
          → DB::transaction + lockForUpdate findOrFail
          → status in [generated,ready,failed,corrected,verified,downloaded,printed] else 422
          → update status='cancelled', cancelled_at=now(), cancellation_reason
          → timeline.recordCancelled
         ↓
    Return 200 { message:"Invoice cancelled successfully", data: AdminInvoiceResource(fresh) }
    Errors: 404, 422 (reason or status)
```

---

## Flow 10: Issue Debit Note

```
Client → POST /api/v1/invoices/12/debit-note  Body: { amount, reason }
         ↓
    [auth:sanctum] → [permission:issue-debit-note] → DebitNoteRequest (amount required|min:0.01, reason required|max:500)
         ↓
    InvoiceController@issueDebitNote(request, id) :220
      → Invoice::findOrFail(id) → 404 if unknown
      → if status NOT in [generated,ready,verified,downloaded,printed] → 422 localized INVOICE_DEBIT_NOTE_NOT_ALLOWED
      → DebitNoteService::generate(invoice, amount, reason, authId)
          → DB::transaction
          → InvoiceNumberService::generateNext('DN') → DN-2026-000001
          → DebitNote::create({invoice_id, debit_note_number, amount, reason, ...})
         ↓
    Return 201 { message:"Debit note issued successfully", data: debitNote }
    Errors: 404, 422 validation/status, 401/403
```

---

## Invoice Status State Machine (enforced at `Invoice.php:saving` + `InvoiceStatus.php:20`)

```
                    ┌──────────┐
                    │ PENDING  │
                    └────┬─────┘
                         │
                    ┌────v──────┐
                    │ GENERATING│
                    └────┬──────┘
                         │
                    ┌────v──────┐
                    │ GENERATED │
                    └──┬────┬──┘
                       │    └──────────┐
              ┌────────v──┐     ┌──────v──────────┐
              │PDF_GENERAT.│     │ FAILED          │
              └────┬──────┘     └──┬──────────────┘
                   │               │ (cancel→archived)
         ┌─────────v───────────────v──────────┐
         │           READY                    │
         └─┬────────┬────────┬──────┬─────────┘
           │        │        │      │
     ┌─────v──┐ ┌──v──────┐ ┌v──────┐ ┌v─────────┐
     │VERIFIED│ │DOWNLOADED│ │PRINTED│ │CORRECTED │
     └──┬─────┘ └──┬──────┘ └──┬────┘ └──┬───────┘
        │          │           │         │
        └──────────┴─────┬─────┴─────────┘
                         │
                   ┌─────v──────┐
                   │ CANCELLED  │
                   └─────┬──────┘
                         │
                   ┌─────v──────┐
                   │ ARCHIVED   │ (terminal)
                   └────────────┘
  FAILED ──pdf_generating──► READY/FAILED  (retry loop)
  CORRECTED/VERIFIED/DOWNLOADED/PRINTED/GENERATED/READY/FAILED ──► CANCELLED ──► ARCHIVED
```

---

## Automatic Generation (out of scope for this group but referenced)

```
PaymentSucceeded event → GenerateInvoiceListener (queue meem-high)
  → InvoiceService::generateFromOrder(order) [idempotent: order_id lock]
  → afterCommit: InvoiceCreated → GenerateInvoicePdfJob → status ready
```

See `api-desc/invoice/flow.md` Flow 6 for full detail.
