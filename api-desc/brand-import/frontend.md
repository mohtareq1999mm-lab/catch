# Brand Import / Export — Frontend Integration Guide

## Endpoints

---

### 1. POST /api/v1/brands/import — Queue Brand Import (Admin)

**Purpose:** Upload an Excel file to bulk create/update brands.

**Authentication:** Required (Sanctum), permission: `import-brand` (or `super_admin`)

**Request:** `multipart/form-data`
- `file` (required, `.xlsx` / `.xls` / `.ods`, max 20 MB)

**Response 202:**
```json
{
  "status": 202,
  "message": "Brand import started successfully",
  "success": true,
  "data": { "import_id": 42, "status": "pending" }
}
```

**Frontend flow:**
1. Upload via `FormData` → receive `import_id`.
2. Poll `GET /brands/import/{import_id}` until terminal status.
3. If `failed_rows > 0`, offer `GET /.../download-errors` to fetch the error workbook.
4. On `completed`/`completed_with_errors`, refresh brand listing.

```js
const formData = new FormData();
formData.append('file', file);
const res = await fetch('/api/v1/brands/import', {
  method: 'POST',
  headers: { Authorization: `Bearer ${token}` },
  body: formData,
});
const { data } = await res.json(); // { import_id, status }
```

---

### 2. GET /api/v1/brands/import/sample — Download Template (Admin)

**Purpose:** Let the user download the official 7-column Excel template.

**Authentication:** Required (Sanctum), permission: `import-brand`

**Response:** Binary `.xlsx` (`brand-import-sample.xlsx`, `application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`)

**Columns:** `name_en | name_ar | details_en | details_ar | status | image_desktop_url | image_mobile_url`

```bash
curl -X GET "http://example.com/api/v1/brands/import/sample" \
  -H "Authorization: Bearer <token>" \
  -o brand-import-sample.xlsx
```

**Tip:** Keep header row and sheet name `brands` intact; don't add `id`/`slug` columns.

---

### 3. GET /api/v1/brands/import/{id} — Import Progress (Admin)

**Purpose:** Poll import progress while the job runs on `meem-medium`.

**Authentication:** Required (Sanctum), permission: `import-brand`

**Response 200:**
```json
{
  "status": 200,
  "message": "Brand import status fetched successfully",
  "success": true,
  "data": {
    "id": 42,
    "status": "completed_with_errors",
    "total_rows": 120,
    "processed_rows": 120,
    "successful_rows": 118,
    "failed_rows": 2,
    "progress": 100.0,
    "errors": [ { "sheet":"brands", "row":5, "name_en":"Acme", "name_ar":"أكمي", "error_message":"..." } ],
    "error_count": 2,
    "created_at": "2026-08-18T10:15:00+00:00",
    "completed_at": "2026-08-18T10:16:05+00:00"
  }
}
```

**Terminal:** `completed`, `completed_with_errors`, `failed`, `cancelled`. Transient: `pending`, `processing`, `cancelling`.

**Polling interval:** 1.5–2 s recommended. Stop when terminal.

```js
const poll = async (importId) => {
  while (true) {
    const res = await fetch(`/api/v1/brands/import/${importId}`, {
      headers: { Authorization: `Bearer ${token}` },
    });
    const body = await res.json();
    const data = body.data;
    if (['completed','completed_with_errors','failed','cancelled'].includes(data.status)) return data;
    if (data.status === 'cancelling') { /* show "Cancelling…" */ }
    await new Promise(r => setTimeout(r, 2000));
  }
};
```

---

### 4. POST /api/v1/brands/import/{id}/cancel — Cancel Import (Admin)

**Purpose:** Cancel a `pending` or `processing` import.

**Authentication:** Required (Sanctum), permission: `import-brand`

**Response 200:**
```json
{ "status": 200, "message": "Import cancelled successfully", "success": true,
  "data": { "import_id": 42, "status": "cancelled" } }
```

**409** (already finished): `{ "status": 409, "message": "Import cannot be cancelled in its current state", "success": false }`

```js
await fetch(`/api/v1/brands/import/${id}/cancel`, {
  method: 'POST',
  headers: { Authorization: `Bearer ${token}` },
});
```

**Note:** Cancel is soft-rollback — brands created in this import are soft-deleted; updated brands are not reverted.

---

### 5. GET /api/v1/brands/import/{id}/download-errors — Error Report (Admin)

**Purpose:** Download failed rows as a correction workbook.

**Authentication:** Required (Sanctum), permission: `import-brand`

**Response 200:** Binary `.xlsx` (`failed_brand_import_rows_{id}.xlsx`) — columns `Sheet`, `Row`, `Name (EN)`, `Name (AR)`, `Error Message`.

**Response 404** (no errors): `{ "status": 404, "message": "No errors found", "success": false }`

```js
const res = await fetch(`/api/v1/brands/import/${id}/download-errors`, {
  headers: { Authorization: `Bearer ${token}` },
});
if (res.ok) { const blob = await res.blob(); /* trigger download */ }
```

---

### 6. GET /api/v1/brands/export — Queue Brand Export (Admin)

**Purpose:** Queue a full Excel export of all brands.

**Authentication:** Required (Sanctum), permission: `export-brand` (or `super_admin`)

**Query params:** None (exports all brands, ordered `id` asc).

**Response 202:**
```json
{ "status": 202, "message": "Brand export started successfully", "success": true,
  "data": { "export_id": 58, "status": "pending" } }
```

```js
const res = await fetch('/api/v1/brands/export', {
  headers: { Authorization: `Bearer ${token}` },
});
const { data } = await res.json(); // { export_id, status }
```

---

### 7. GET /api/v1/brands/export/{id} — Export Status (Admin)

**Purpose:** Poll export until file is ready.

**Authentication:** Required (Sanctum), permission: `export-brand`

**Response 200:**
```json
{
  "status": 200,
  "message": "Brand export status fetched successfully",
  "success": true,
  "data": {
    "id": 58,
    "status": "completed",
    "total_rows": 120,
    "processed_rows": 120,
    "successful_rows": 120,
    "failed_rows": 0,
    "errors": [],
    "created_at": "2026-08-18T10:20:00+00:00",
    "completed_at": "2026-08-18T10:20:04+00:00"
  }
}
```

**Statuses:** `pending`, `processing`, `completed`, `failed`.

---

### 8. GET /api/v1/brands/export/{id}/download — Download Export (Admin)

**Purpose:** Download the generated workbook (only when `completed`).

**Authentication:** Required (Sanctum), permission: `export-brand`

**Response 200:** Binary `.xlsx` (`brands-export-{Y-m-d-His}.xlsx`)

**Response 409** (not ready): `{ "status": 409, "message": "Export file is not ready yet", "success": false }`

```js
const res = await fetch(`/api/v1/brands/export/${id}/download`, {
  headers: { Authorization: `Bearer ${token}` },
});
if (res.status === 409) { /* show "Export still processing" */ }
else { const blob = await res.blob(); /* download */ }
```

---

## Frontend Usage

### Import Upload with Progress Bar

```js
const importBrands = async (file) => {
  const formData = new FormData();
  formData.append('file', file);
  const start = await fetch('/api/v1/brands/import', {
    method: 'POST',
    headers: { Authorization: `Bearer ${token}` },
    body: formData,
  }).then(r => r.json());
  const importId = start.data.import_id;

  const timer = setInterval(async () => {
    const res = await fetch(`/api/v1/brands/import/${importId}`, {
      headers: { Authorization: `Bearer ${token}` },
    });
    const body = await res.json();
    const d = body.data;
    updateProgress(d.progress, d.processed_rows, d.total_rows);
    updateCounters(d.successful_rows, d.failed_rows);
    if (['completed','completed_with_errors','failed','cancelled'].includes(d.status)) {
      clearInterval(timer);
      if (d.failed_rows > 0) showDownloadErrorsLink(importId);
      if (d.status === 'completed' || d.status === 'completed_with_errors') refreshBrandsList();
    }
  }, 2000);
};
```

### Realtime Wake-up (optional, preferred over tight polling)

```js
import Echo from 'laravel-echo';
const echo = new Echo({ broadcaster: 'pusher', auth: { headers: { Authorization: `Bearer ${token}` } } });
echo.private(`users.${userId}`)
  .listen('.brand.import.progress', () => {
    // server signals terminal/cancel; reconcile via status endpoint
    fetchStatus(importId).then(render);
  })
  .listen('.brand.export.completed', () => fetchExportStatus(exportId).then(render))
  .listen('.brand.export.failed', () => showError('Export failed'));
```

> The broadcast is a wake-up only; always fetch `GET /brands/import/{id}` or `/brands/export/{id}` for truth (payload omits paths and raw errors).

### Export Download

```js
const exportBrands = async () => {
  const start = await fetch('/api/v1/brands/export', {
    headers: { Authorization: `Bearer ${token}` },
  }).then(r => r.json());
  const exportId = start.data.export_id;
  // poll until completed
  let status;
  do {
    await new Promise(r => setTimeout(r, 2000));
    const s = await fetch(`/api/v1/brands/export/${exportId}`, {
      headers: { Authorization: `Bearer ${token}` },
    }).then(r => r.json());
    status = s.data.status;
    if (status === 'failed') throw new Error('Export failed');
  } while (status !== 'completed');
  // download
  const fileRes = await fetch(`/api/v1/brands/export/${exportId}/download`, {
    headers: { Authorization: `Bearer ${token}` },
  });
  const blob = await fileRes.blob();
  downloadBlob(blob, `brands-export-${exportId}.xlsx`);
};
```

### Loading / Empty / Error States

| State | Handling |
|-------|----------|
| **Upload loading** | Disable upload button, show spinner + "Queuing import…" |
| **Polling** | Progress bar (0→100), counters `processed/total`, `successful`, `failed` |
| **Completed** | Toast "Brand import finished" + refresh listing |
| **Completed with errors** | Toast "Finished with N errors" + "Download error report" CTA |
| **Failed** | Alert with system error row (check `errors[0].error_message`) |
| **Cancelled / Cancelling** | Banner "Cancelling…" then "Import cancelled" |
| **Error report empty** | Hide download button when `error_count===0` (endpoint returns 404) |
| **Export pending/processing** | Spinner + "Preparing export…" |
| **Export 409 on download** | Button disabled with tooltip "Export still processing" |
| **422 validation** | Show `errors.file` under file input |
| **401/403** | Redirect to login or show "Missing import-brand / export-brand permission" |
| **404** | "Import/Export not found" |
| **Network error** | Retry with backoff; show "Network error" toast |

## Key Considerations

1. **Async by design** — POST/GET start endpoints return 202 with an ID; work runs on `meem-medium`. Frontend must poll (or listen for `brand.import.progress`/`brand.export.completed`).

2. **Field name is `successful_rows`** — API exposes `successful_rows`; DB column is `success_rows`. Don't read `success_rows` from the status payload.

3. **Template is fixed** — 7 columns in order; sheet must be `brands`; don't add `id`/`slug` columns. The sheet import uses `WithHeadingRow`.

4. **Identity is English name** — same `name_en` updates the existing brand; slug is backend-generated. No hierarchy fields (`parent_name_en`, `is_featured`) exist.

5. **Images are optional and non-blocking** — invalid URLs produce row errors, but download failures (SSRF, size, mime, network) are tolerated and the brand still succeeds.

6. **Export can be re-imported** — the exported file uses identical headings and can be edited and uploaded again.

7. **Download export only after `completed`** — otherwise 409 `EXPORT_NOT_READY`.

8. **Cancellation rollback** — only newly created brands are soft-deleted; updates to existing brands are not reverted.

9. **Owner policy** — `GET /brands/import/{id}` is gated by `ImportPolicy@view`; a user can only poll/cancel/download errors for imports they created (unless super_admin).

10. **Throttling** — admin group is `throttle:admin`; avoid rapid polling (<1 s) to stay within limits; 2 s interval is safe.

11. **File ownership** — import file is user-supplied; export filename is server-generated (`brands-export-Y-m-d-His.xlsx`), not user-controlled.
