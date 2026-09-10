# Category Import / Export — Frontend Integration Guide

## Endpoints

---

### 1. POST /api/v1/categories/import — Queue Category Import (Admin)

**Purpose:** Upload an Excel file to bulk import categories.

**Authentication:** Required (Sanctum), permission: `import-category`

**Request:** `multipart/form-data`
- `file` (required, `.xlsx` / `.xls` / `.ods`, max 20 MB)

**Response 202:**
```json
{
  "status": 202,
  "message": "Category import started successfully",
  "success": true,
  "data": { "import_id": 42, "status": "pending" }
}
```

**Frontend flow:**
1. Upload the file → receive `import_id`.
2. Poll `GET /api/v1/categories/import/{import_id}` until terminal status.
3. If `failed_rows > 0`, offer `GET /.../download-errors` to fetch the error report.

---

### 2. GET /api/v1/categories/import/sample — Download Template (Admin)

**Purpose:** Let the user download the official Excel template.

**Authentication:** Required (Sanctum), permission: `import-category`

**Response:** Binary `.xlsx` (`category-import-sample.xlsx`)

---

### 3. GET /api/v1/categories/import/{id} — Import Progress (Admin)

**Purpose:** Poll import progress.

**Authentication:** Required (Sanctum), permission: `import-category`

**Response:**
```json
{
  "status": 200,
  "message": "Category import status fetched successfully",
  "success": true,
  "data": {
    "id": 42,
    "status": "completed_with_errors",
    "total_rows": 120,
    "processed_rows": 120,
    "successful_rows": 118,
    "failed_rows": 2,
    "progress": 100.0,
    "errors": [],
    "error_count": 2,
    "created_at": "2026-08-18T10:15:00+00:00",
    "completed_at": "2026-08-18T10:16:05+00:00"
  }
}
```

**Terminal statuses:** `completed`, `completed_with_errors`, `failed`, `cancelled`. Transient: `pending`, `processing`, `cancelling`.

---

### 4. POST /api/v1/categories/import/{id}/cancel — Cancel Import (Admin)

**Authentication:** Required (Sanctum), permission: `import-category`

**Response 200:**
```json
{
  "status": 200,
  "message": "Import cancelled successfully",
  "success": true,
  "data": { "import_id": 42, "status": "cancelled" }
}
```

**Response 409** (already finished):
```json
{ "status": 409, "message": "Import cannot be cancelled in its current state", "success": false }
```

---

### 5. GET /api/v1/categories/import/{id}/download-errors — Error Report (Admin)

**Authentication:** Required (Sanctum), permission: `import-category`

**Response:** Binary `.xlsx` (`failed_category_import_rows_{id}.xlsx`) with columns `Sheet`, `Row`, `Name (EN)`, `Name (AR)`, `Parent Name (EN)`, `Error Message`.

**Response 404** (no errors): `{ "status": 404, "message": "No errors found", "success": false }`

---

### 6. GET /api/v1/categories/export — Queue Category Export (Admin)

**Purpose:** Queue a full Excel export of all categories.

**Authentication:** Required (Sanctum), permission: `export-category`

**Query Parameters:** None (exports all categories).

**Response 202:**
```json
{
  "status": 202,
  "message": "Category export started successfully",
  "success": true,
  "data": { "export_id": 58, "status": "pending" }
}
```

---

### 7. GET /api/v1/categories/export/{id} — Export Status (Admin)

**Authentication:** Required (Sanctum), permission: `export-category`

**Response:**
```json
{
  "status": 200,
  "message": "Category export status fetched successfully",
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

---

### 8. GET /api/v1/categories/export/{id}/download — Download Export (Admin)

**Authentication:** Required (Sanctum), permission: `export-category`

**Response:** Binary `.xlsx` (`categories-export-{timestamp}.xlsx`)

**Response 409** (not ready): `{ "status": 409, "message": "Export file is not ready yet", "success": false }`

---

## Frontend Usage

### Import Upload
```js
const formData = new FormData();
formData.append('file', excelFile);

const res = await fetch('/api/v1/categories/import', {
  method: 'POST',
  headers: { Authorization: `Bearer ${token}` },
  body: formData,
});
const body = await res.json();          // { import_id, status }
```

### Progress Polling
```js
const poll = async (importId) => {
  while (true) {
    const res = await fetch(`/api/v1/categories/import/${importId}`, {
      headers: { Authorization: `Bearer ${token}` },
    });
    const body = await res.json();
    const data = body.data;

    if (['completed', 'completed_with_errors', 'failed', 'cancelled'].includes(data.status)) {
      return data;                       // done
    }
    await new Promise((r) => setTimeout(r, 2000));
  }
};
```

### Loading State
- Show progress percentage from `data.progress` (0 → 100).
- Show counters: `processed_rows` / `total_rows`, `successful_rows`, `failed_rows`.

### Success State
- `completed` → "Import finished successfully".
- `completed_with_errors` → "Finished with N failed rows" + link to download error report.

### Error State
- **422:** Invalid file (missing, wrong format, > 20 MB) — field-level `errors.file`.
- **401 / 403:** Not authenticated / missing `import-category` or `export-category`.
- **404:** Import/export record not found; `download-errors` with no errors.
- **409:** Cancel on finished import; download export before it is ready.
- **500:** Worker/system failure — show generic message.

## Key Considerations

1. **Async by design** — the POST/GET start endpoints return immediately with `import_id` / `export_id`; the actual work runs on the `meem-high` queue. The frontend must poll.

2. **`successful_rows`, not `success_rows`** — the API exposes `successful_rows`; the database column is `success_rows`. Use the API field.

3. **Excel template is fixed** — the 9 columns (`name_en`, `name_ar`, `details_en`, `details_ar`, `parent_name_en`, `status`, `is_featured`, `image_desktop_url`, `image_mobile_url`) must be preserved. Do not add `id` / `slug` / `parent_id` / `parent_slug` columns.

4. **Identity is the English name** — importing the same `name_en` updates the existing category; the slug is generated by the backend.

5. **Parents by English name** — `parent_name_en` must match an existing category's English name; empty means root.

6. **Download export only after `completed`** — otherwise the API returns 409.

7. **Export can be re-imported** — the exported file uses the exact import columns, so it can be edited and uploaded again.