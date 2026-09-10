# API Reference — Dashboard Invoices (Admin)

> **Source of truth:** `app/Http/Controllers/Api/InvoiceController.php:26`, `packages/marvel/src/Rest/Routes.php:391-403`, `routes/api.php:133-172`, `app/Enums/InvoiceStatus.php:20`, `packages/marvel/src/Enums/Permission.php:275`. The 10-route group below is the exact snippet under investigation, loaded as `/api/v1/invoices/*`.

---

## Common Base

- **Prefix:** `/api/v1/invoices` (via `Route::prefix('invoices')` inside `api/v1` group)
- **Auth:** `auth:sanctum` on every route in the group
- **Envelope:** `{ status, message, success, data?, errors? }` via `Marvel\Traits\ApiResponse::apiResponse()`
- **Constraints:** `whereNumber('id')` for `{id}` routes → malformed ID returns framework 404 before controller; `whereUuid('uuid')` for PDF/verify/uuid routes → malformed UUID returns 404

---

### GET /api/v1/invoices — List Invoices

Paginated, filterable, sortable admin list.

**Auth:** `auth:sanctum` + `permission:view-invoices` (constructor `InvoiceController.php:35`)

**Query Parameters:**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| limit | int | 15 | Items per page, clamped `min(limit, 100)` (`InvoiceController.php:43`) |
| search | string | — | `WHERE invoice_number LIKE %search% OR order.order_number LIKE %search%` |
| status | string | — | Exact match on `invoices.status` (e.g. `generated`, `ready`, `failed`) |
| order_id | int | — | Filter by `order_id` |
| user_id | int | — | Filter by `user_id` |
| invoice_series | string | — | Filter by `invoice_series` (`INV`, `CN`, `DN`) |
| currency | string | — | Filter by `currency` (`EGP`, etc.) |
| from | date | — | `whereDate(created_at >= from)` |
| to | date | — | `whereDate(created_at <= to)` |
| sort_by | enum | `created_at` | Allowed: `created_at`, `total`, `status`, `invoice_number` |
| sort_direction | string | `desc` | `asc` or `desc` |

**Response 200:** `AdminInvoiceCollection` paginator envelope.

```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": {
    "data": [
      {
        "id": 12,
        "uuid": "550e8400-e29b-41d4-a716-446655440000",
        "order_id": 101,
        "invoice_number": "INV-2026-000012",
        "status": "ready",
        "subtotal": 150.0,
        "shipping_price": 10.0,
        "coupon_discount": 0.0,
        "promotion_discount": 5.0,
        "total_discount": 5.0,
        "total": 155.0,
        "amount_paid": 155.0,
        "currency": "EGP",
        "payment_method": "online",
        "payment_gateway": "myfatoorah",
        "snapshot_hash": "9f2a...",
        "verification_hash": "c31b...",
        "pdf_generated_at": "2026-08-22T09:15:00+00:00",
        "generated_at": "2026-08-22T09:14:58+00:00",
        "generation_attempts": 1,
        "last_generation_error": null,
        "is_correction": false,
        "verify_count": 0,
        "created_at": "2026-08-22T09:14:58+00:00",
        "verification_url": "http://example.com/api/v1/general/invoices/verify/550e8400-...",
        "qr_content": { "uuid": "550e8400-...", "invoice_number": "INV-2026-000012", "verification_hash": "c31b...", "issued_at": "2026-08-22T09:14:58+00:00", "verification_url": "http://example.com/api/v1/general/invoices/verify/550e8400-..." },
        "view_url": "http://example.com/api/v1/invoices/12",
        "download_url": "http://example.com/api/v1/invoices/550e8400-.../download",
        "snapshot": { "...": "InvoiceSnapshotResource when data exists" }
      }
    ],
    "current_page": 1, "from": 1, "to": 15, "last_page": 3, "path": "http://example.com/api/v1/invoices",
    "per_page": 15, "total": 42, "next_page_url": "http://example.com/api/v1/invoices?page=2"
  }
}
```

**Errors:** `401` unauthenticated, `403` missing `view-invoices`.

**cURL:**
```bash
curl -H "Authorization: Bearer $TOKEN" "http://example.com/api/v1/invoices?status=ready&limit=20&sort_by=total&sort_direction=desc"
curl -H "Authorization: Bearer $TOKEN" "http://example.com/api/v1/invoices?search=INV-2026-0000&from=2026-08-01&to=2026-08-31"
```

---

### GET /api/v1/invoices/{uuid}/download — Download PDF (Attachment)

Stream real PDF bytes as attachment. Not a JSON URL.

**Auth:** `auth:sanctum` + `throttle:30,1` + **inline** owner OR `view-invoice-download` (`InvoiceController.php:284-289`). Fails with **404** (not 403) to avoid existence leak.

**Path Parameters:**

| Param | Type | Constraint |
|-------|------|------------|
| uuid | string | `whereUuid` — malformed → 404 at routing |

**Flow:** `Invoice::with('order')->where('uuid', uuid)->firstOrFail()` → owner/permission check → `pdf_path` required → `Storage::disk('public')->exists('invoices/{pdf_path}')` → optional `downloaded_at` + `recordDownloaded()` on first download → `Storage::disk('public')->response()` with `Content-Type: application/pdf`, `Content-Disposition: attachment; filename="..."`.

**Success:** `200` binary PDF stream, headers:
```
Content-Type: application/pdf
Content-Disposition: attachment; filename="INV-2026-000012.pdf"
```

**Errors:**

| Status | When | Body |
|--------|------|------|
| 404 | Not owner AND lacking `view-invoice-download` (or unknown uuid) | `{ status:404, message:"Not found", success:false }` |
| 404 | `pdf_path` null (not yet generated) | `{ status:404, message:"PDF not yet generated", success:false, data:{ status, pdf_generated_at } }` |
| 404 | File missing on disk | `{ status:404, message:"Not found", success:false }` |
| 401 | Guest | `{ message:"Unauthenticated", status:false }` |
| 429 | >30/min | `Too Many Attempts` |

**cURL:**
```bash
curl -H "Authorization: Bearer $TOKEN" http://example.com/api/v1/invoices/550e8400-e29b-41d4-a716-446655440000/download -o invoice.pdf
```

---

### GET /api/v1/invoices/{uuid}/view — View PDF Inline

Identical security chain to `download`, but streams `inline` and **does not** record download bookkeeping.

**Auth:** `auth:sanctum` + `throttle:30,1` + inline owner/permission

**Success:** `200` binary PDF with:
```
Content-Type: application/pdf
Content-Disposition: inline; filename="INV-2026-000012.pdf"
```

Display in `<iframe>` or new tab: `window.open('/api/v1/invoices/{uuid}/view', '_blank')` with `Authorization` header via fetch→blob.

**Errors:** same as download (404 unauth/unknown/missing, 404 not-generated).

**cURL:**
```bash
curl -H "Authorization: Bearer $TOKEN" http://example.com/api/v1/invoices/550e8400-.../view -o view.pdf
# Or fetch with blob for inline preview:
# fetch(url, {headers:{Authorization:`Bearer ${token}`}}).then(r=>r.blob()).then(URL.createObjectURL)
```

---

### GET /api/v1/invoices/{id} — Show by Numeric ID

**Auth:** `auth:sanctum` + `permission:view-invoice` (`InvoiceController.php:36`, `whereNumber`)

**Path Parameters:**

| Param | Type | Constraint |
|-------|------|------------|
| id | int | `whereNumber` |

**Controller:** `InvoiceController@show:61` → `Invoice::with(['order.orderItems','transaction','user'])->findOrFail(id)` → `AdminInvoiceResource`

**Response 200:** single `AdminInvoiceResource` object (same shape as list item but single `data` wrapper).

```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": { "id": 12, "uuid": "550e8400-...", "invoice_number": "INV-2026-000012", "status": "ready", "total": 155.0, "...": "..." }
}
```

**Errors:** `404` → `{"message":"Resource Not Found","status":false}` (Handler for ModelNotFoundException), `401`, `403`.

**cURL:**
```bash
curl -H "Authorization: Bearer $TOKEN" http://example.com/api/v1/invoices/12
```

---

### POST /api/v1/invoices/{id}/regenerate — Regenerate PDF (Async)

Re-queues PDF generation for `failed`/`ready`/`generated` invoices.

**Auth:** `auth:sanctum` + `permission:regenerate-invoice` + `whereNumber`

**Request Body:** none (empty).

**Controller:** `InvoiceController@regenerate:121` → `findOrFail` → status allowlist `['failed','ready','generated']` else `422` with `ERROR_ADDING_ITEMS_TO_ORDER` → update `status='pdf_generating'`, `generation_attempts++`, `last_generation_error=null` → `timeline.recordPdfRegenerated` → `GenerateInvoicePdfJob::dispatch`.

**Success 200:**
```json
{ "status": 200, "message": "Data fetched successfully", "success": true, "data": { "invoice_id": 12, "status": "pdf_generating" } }
```
Job then renders DomPDF → `storage/app/public/invoices/INV-...pdf` → updates `pdf_path`, `pdf_checksum`, `pdf_generated_at`, `status='ready'` or `status='failed'` on error with retry backoff.

**Errors:**

| Status | When |
|--------|------|
| 404 | Unknown `id` |
| 422 | Status not in allowlist (e.g. `cancelled`, `archived`) → `ERROR_ADDING_ITEMS_TO_ORDER` |
| 401/403 | Auth/permission |

**cURL:**
```bash
curl -X POST -H "Authorization: Bearer $TOKEN" http://example.com/api/v1/invoices/12/regenerate
```

---

### POST /api/v1/invoices/{id}/correct — Correct Invoice (Create Correction)

Creates a new correction invoice `is_correction=true, correction_to_id=original` and marks original as `corrected`.

**Auth:** `auth:sanctum` + `permission:correct-invoice`

**Request Body (`CorrectInvoiceRequest.php:14`):**

| Field | Type | Required | Rules |
|-------|------|----------|-------|
| reason | string | **required** | `required|string|max:500` |
| overrides | object | optional | `nullable|array` |
| overrides.total | number | optional | `nullable|numeric|min:0` |
| overrides.amount_paid | number | optional | `nullable|numeric|min:0` |
| overrides.shipping_price | number | optional | `nullable|numeric|min:0` |
| overrides.customer.name | string | optional | `max:255` |
| overrides.customer.email | string | optional | `email|max:255` |
| overrides.customer.phone | string | optional | `max:50` |
| overrides.billing_address | object | optional | `array` |
| overrides.shipping_address | object | optional | `array` |
| overrides.notes | string | optional | `string` |

**Controller:** `InvoiceController@correct:151` → `InvoiceService::correctInvoice(id, overrides, reason, authId)` inside `DB::transaction` + `lockForUpdate`, status must be `generated/ready/verified/downloaded/printed` else RuntimeException→422.

**Success 200:**
```json
{
  "status": 200,
  "message": "Invoice corrected successfully",
  "success": true,
  "data": { "id": 13, "uuid": "7c9e6679-...", "invoice_number": "INV-2026-000013", "status": "generated", "is_correction": true, "correction_reason": "Wrong total", "corrected_at": "2026-09-07T...", "total": 95.0 }
}
```

**Errors:**

| Status | When |
|--------|------|
| 422 | `reason` missing/empty/`>500` |
| 422 | Original status not correctable (e.g. `cancelled`, `failed`) → `RuntimeException` message |
| 404 | Unknown `id` |
| 401/403 | Auth/permission |

**cURL:**
```bash
curl -X POST -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"reason":"Wrong total charged","overrides":{"total":95.0,"amount_paid":95.0}}' \
  http://example.com/api/v1/invoices/12/correct
```

---

### POST /api/v1/invoices/{id}/cancel — Cancel Invoice

**Auth:** `auth:sanctum` + `permission:cancel-invoice`

**Request Body (inline validate `InvoiceController.php:182`):**

| Field | Type | Required | Rules |
|-------|------|----------|-------|
| reason | string | **required** | `required|string|max:500` |

**Controller:** `InvoiceController@cancel:180` → `InvoiceService::cancelInvoice(id, reason, authId)` → `lockForUpdate`, status allowlist `generated/ready/failed/corrected/verified/downloaded/printed` else 422 → sets `status='cancelled'`, `cancelled_at=now()`, `cancellation_reason` → `recordCancelled`.

**Success 200:**
```json
{ "status": 200, "message": "Invoice cancelled successfully", "success": true, "data": { "id": 12, "status": "cancelled", "cancelled_at": "2026-09-07T...", "cancellation_reason": "Order refunded" } }
```

**Errors:** `404` unknown id, `422` `reason` missing/invalid or un-cancellable status, `401/403`.

**cURL:**
```bash
curl -X POST -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"reason":"Order refunded"}' http://example.com/api/v1/invoices/12/cancel
```

---

### POST /api/v1/invoices/{id}/debit-note — Issue Debit Note

**Auth:** `auth:sanctum` + `permission:issue-debit-note`

**Request Body (`DebitNoteRequest.php:14`):**

| Field | Type | Required | Rules |
|-------|------|----------|-------|
| amount | number | **required** | `required|numeric|min:0.01` |
| reason | string | **required** | `required|string|max:500` |

**Controller:** `InvoiceController@issueDebitNote:230` → `findOrFail` → status must be `generated/ready/verified/downloaded/printed` else 422 localized `INVOICE_DEBIT_NOTE_NOT_ALLOWED` → `DebitNoteService::generate(invoice, amount, reason, authId)` → `DN-{YEAR}-{SEQ}` via `InvoiceNumberService`.

**Success 201:**
```json
{ "status": 201, "message": "Debit note issued successfully", "success": true, "data": { "id": 1, "uuid": "...", "debit_note_number": "DN-2026-000001", "amount": 25.0, "currency": "EGP", "reason": "Additional shipping" } }
```

**Errors:** `404` unknown id, `422` validation or status-not-allowed, `401/403`.

**cURL:**
```bash
curl -X POST -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"amount":25.0,"reason":"Additional shipping"}' http://example.com/api/v1/invoices/12/debit-note
```

---

### GET /api/v1/invoices/verify/{uuid} — Verify Authenticity

**Auth:** `auth:sanctum` + `throttle:5,1` — **no permission**, any authenticated user.

**Path Parameters:**

| Param | Type | Constraint |
|-------|------|------------|
| uuid | string | `whereUuid` preferred (snippet omits but production adds) |

**Controller:** `InvoiceController@verify:202` → `InvoiceService::verifyInvoice(uuid)` → `sha256(snapshot_hash + secret)` vs `verification_hash` via `hash_equals`.

**Response 200 (authentic):**
```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": {
    "authentic": true,
    "invoice": { "id":1, "uuid":"550e8400-...", "invoice_number":"INV-...", "status":"ready", "total":155.0, "currency":"EGP", "verify_count":1, "verification_url":"http://example.com/api/v1/general/invoices/verify/550e8400-..." },
    "order": { "id":101, "order_number":"ORD-001", "status":"completed", "payment_status":"paid", "fulfillment_status":"fulfilled" },
    "qr_content": "http://example.com/api/v1/general/invoices/verify/550e8400-..."
  }
}
```
Side effects: `verify_count++`, `last_verified_at=now()`, `verified_at` set on first verify, `timeline.recordVerified`.

**Response 409 (tampered):**
```json
{ "status": 409, "message": "Invoice verification failed", "success": false, "data": { "authentic": false, "tampered": true } }
```

**Response 404:** `{ status:404, message:"Not found", success:false }` — unknown uuid.

**cURL:**
```bash
curl -H "Authorization: Bearer $TOKEN" http://example.com/api/v1/invoices/verify/550e8400-e29b-41d4-a716-446655440000
```

---

### GET /api/v1/invoices/uuid/{uuid} — Show by UUID

**Auth:** `auth:sanctum` + `permission:view-invoice`

**Controller:** `InvoiceController@showByUuid:92` → `Invoice::with(['order.orderItems','transaction','user'])->where('uuid', uuid)->firstOrFail()` → `AdminInvoiceResource`.

**Success 200:** identical shape to `GET /{id}` (full admin fields).

**Errors:** `404` unknown uuid, `401/403`.

**cURL:**
```bash
curl -H "Authorization: Bearer $TOKEN" http://example.com/api/v1/invoices/uuid/550e8400-e29b-41d4-a716-446655440000
```

---

## Pagination Envelope (Admin Collection)

Admin uses `AdminInvoiceCollection` (paginator). Shape includes `current_page`, `from`, `to`, `last_page`, `path`, `per_page`, `total`, `next_page_url`, `prev_page_url`, `last_page_url`, `first_page_url`. See `api-desc/invoice/api.md` for canonical example.

## Error Envelope Summary

| Status | Condition |
|--------|-----------|
| 401 | Missing/invalid Sanctum token |
| 403 | Authenticated but missing required permission |
| 404 | Unknown `id`/`uuid`, malformed constraint, or owner-check failure (privacy) |
| 409 | `verify` tampered |
| 422 | Validation (`CorrectInvoiceRequest`, `DebitNoteRequest`, inline `reason`, regenerate allowlist, debit-note allowlist) |
| 429 | Throttle (`view`/`download` 30/min, `verify` 5/min) |
| 500 | Unexpected (state machine violation, PDF failure after retries) |
