# API Reference — Brand Import / Export

---

## Admin Endpoints

> Base prefix: `/api/v1` (defined in `../../packages/marvel/src/Rest/Routes.php` admin group).
> All endpoints require `auth:sanctum` + `throttle:admin` (route group) plus the controller permission.

---

### POST /api/v1/brands/import

Upload an Excel file and queue an asynchronous brand import.

The HTTP request **only queues the import**. The actual Excel processing happens asynchronously via `ImportBrandsJob` on the `meem-medium` queue.

**Authentication**: `auth:sanctum`, permission: `import-brand` (or `super_admin`)

**Request** (multipart/form-data):

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| file | file | required | Excel file (xlsx, xls, ods), max 20 MB |

**Validation Rules** (`BrandImportRequest`):

| Field | Rules |
|-------|-------|
| file | `required`, `file`, `mimes:xlsx,xls,ods`, `max:20480` (KB = 20 MB) |

Custom messages: `IMPORT.VALIDATION.FILE_REQUIRED`, `FILE_MIMES`, `FILE_MAX`.

**Response 202** (queued):
```json
{
  "status": 202,
  "message": "Brand import started successfully",
  "success": true,
  "data": {
    "import_id": 42,
    "status": "pending"
  }
}
```

**Response 422** (validation):
```json
{
  "message": "The given data was invalid.",
  "errors": {
    "file": ["The file field is required."]
  }
}
```

**Response 401** (unauthenticated):
```json
{ "status": 401, "message": "Unauthenticated.", "success": false }
```

**Response 403** (forbidden — lacks `import-brand`):
```json
{ "status": 403, "message": "This action is unauthorized.", "success": false }
```

**Quick Test**:
```bash
curl -X POST "http://example.com/api/v1/brands/import" \
  -H "Authorization: Bearer <token>" \
  -H "Accept: application/json" \
  -F "file=@brands.xlsx"
```

**Business Rules**:
- File is stored on the `imports` disk under `imports/` (controller: `$file->store('imports','imports')`)
- `total_rows` is estimated via PhpSpreadsheet `getHighestDataRow()` across all sheets (may over-count; reconciled by job)
- An `imports` row is created with `type = brand` (DB stores `brand`; `ImportType::BRAND_IMPORT` is `brand-import`), `status = pending`, `created_by = user.id`
- A `progress_{id}.json` signal file is seeded with `{processed:0, success:0, failed:0}`
- `ImportBrandsJob` is dispatched on `meem-medium` (tries 3, timeout 1500s, backoff 60/120/240)
- Track progress with `GET /brands/import/{id}` using the returned `import_id`

---

### GET /api/v1/brands/import/sample

Download the official Brand Excel import template.

**Authentication**: `auth:sanctum`, permission: `import-brand` (or `super_admin`)

**Response 200**: Binary `.xlsx` file (`brand-import-sample.xlsx`, content-type `application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`)

**Columns** (header row 1, data from row 2):
```
name_en | name_ar | details_en | details_ar | status | image_desktop_url | image_mobile_url
```

**Error 404** (sample missing on disk):
```json
{ "status": 404, "message": "Sample file not found", "success": false }
```

**Quick Test**:
```bash
curl -X GET "http://example.com/api/v1/brands/import/sample" \
  -H "Authorization: Bearer <token>" \
  -o brand-import-sample.xlsx
```

**Business Rules**:
- Path resolved from `config('marvel.import.samples.brand')` → `storage_path('packages/marvel/resources/brands/brand-import-sample.xlsx')`
- The template must preserve the exact 7 columns — importer expects `WithHeadingRow` matching these keys
- Sheet title must be `brands` (`BrandsImport::sheets()`)

---

### GET /api/v1/brands/import/{id}

Fetch the status and progress of a queued brand import.

**Authentication**: `auth:sanctum`, permission: `import-brand` (or `super_admin`), plus `ImportPolicy@view` (owner check via `created_by`)

**Path Parameters**:

| Parameter | Type | Description |
|-----------|------|-------------|
| id | int | Import ID (from `POST /brands/import`) — `whereNumber` |

**Response 200** (processing):
```json
{
  "status": 200,
  "message": "Brand import status fetched successfully",
  "success": true,
  "data": {
    "id": 42,
    "status": "processing",
    "total_rows": 120,
    "processed_rows": 40,
    "successful_rows": 35,
    "failed_rows": 5,
    "progress": 33.33,
    "errors": [],
    "error_count": 0,
    "created_at": "2026-08-18T10:15:00+00:00",
    "completed_at": null
  }
}
```

**Response 200** (terminal with errors):
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
    "errors": [
      {
        "sheet": "brands",
        "row": 5,
        "name_en": "Acme",
        "name_ar": "أكمي",
        "error_message": "Brand name already exists with conflicting slug."
      }
    ],
    "error_count": 2,
    "created_at": "2026-08-18T10:15:00+00:00",
    "completed_at": "2026-08-18T10:16:05+00:00"
  }
}
```

**Response 404**: `{ "status": 404, "message": "Not found", "success": false }`

**Quick Test**:
```bash
curl -X GET "http://example.com/api/v1/brands/import/42" \
  -H "Authorization: Bearer <token>" \
  -H "Accept: application/json"
```

**Supported Statuses**:

| Status | Meaning |
|--------|---------|
| `pending` | Queued, not yet picked up |
| `processing` | Job is actively processing rows |
| `completed` | All rows succeeded |
| `completed_with_errors` | Finished, some rows failed (`errors` populated) |
| `failed` | System failure (no rows succeeded) |
| `cancelled` | Cancelled by user |
| `cancelling` | Transient — cancel signal present, DB still `processing`/`pending` |

**Business Rules**:
- Query scopes to `Import::where('type', ImportType::BRAND_IMPORT)` — note mismatch: controller writes `type='brand'` on create, but status queries `brand-import`. The effective DB value is `brand-import` (ImportType constant) after job updates; initial pending row uses `brand` (see bug report).
- `successful_rows` is the API alias for DB `success_rows`
- `error_count` = `count(errors)`
- `progress` = `100.0` for `completed`/`completed_with_errors`; else `progress` from `progress_{id}.json` signal (fallback `0.0` or `99.0` when processing with signal)
- `completed_at` = `updated_at` only for terminal states
- Response is never cached (`Cache-Control: no-cache, no-store, must-revalidate`)
- Progress counters prefer signal file values over DB while job is running

---

### POST /api/v1/brands/import/{id}/cancel

Cancel a pending or processing brand import.

**Authentication**: `auth:sanctum`, permission: `import-brand` (or `super_admin`), plus `ImportPolicy@view`

**Path Parameters**:

| Parameter | Type | Description |
|-----------|------|-------------|
| id | int | Import ID |

**Response 200**:
```json
{
  "status": 200,
  "message": "Import cancelled successfully",
  "success": true,
  "data": { "import_id": 42, "status": "cancelled" }
}
```

**Response 409** (already terminal):
```json
{ "status": 409, "message": "Import cannot be cancelled in its current state", "success": false }
```

**Response 404**: not found / wrong type.

**Quick Test**:
```bash
curl -X POST "http://example.com/api/v1/brands/import/42/cancel" \
  -H "Authorization: Bearer <token>" \
  -H "Accept: application/json"
```

**Business Rules**:
- Allowed while `pending` or `processing`; terminal (`completed`, `completed_with_errors`, `failed`, `cancelled`) returns **409**
- Writes `cancel_{id}.json` with `{cancelled_at: ISO8601}`; job detects signal, throws `ImportCancelledException`, triggers `rollbackCreatedData()` (soft-deletes brands created in this import), marks `cancelled`
- Controller also eagerly updates DB `status='cancelled'` and broadcasts `FileOperationEvent::BRAND_IMPORT_PROGRESS` with `cancelled`

---

### GET /api/v1/brands/import/{id}/download-errors

Download the failed rows of a brand import as an Excel file.

**Authentication**: `auth:sanctum`, permission: `import-brand` (or `super_admin`), plus `ImportPolicy@view`

**Path Parameters**:

| Parameter | Type | Description |
|-----------|------|-------------|
| id | int | Import ID |

**Response 200**: Binary `.xlsx` (`failed_brand_import_rows_{id}.xlsx`, content-type `application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`)

**File columns** (headings):

| Column | Description |
|--------|-------------|
| Sheet | Sheet name (`brands`) |
| Row | Excel row number (data starts at 2) |
| Name (EN) | English name from failed row |
| Name (AR) | Arabic name from failed row |
| Error Message | Failure reason |

**Response 404** (import has no errors):
```json
{ "status": 404, "message": "No errors found", "success": false }
```

**Quick Test**:
```bash
curl -X GET "http://example.com/api/v1/brands/import/42/download-errors" \
  -H "Authorization: Bearer <token>" \
  -o failed_brand_import_rows_42.xlsx
```

**Business Rules**:
- Available whenever `imports.errors` is non-empty (even while `processing`)
- Returns 404 `IMPORT_NO_ERRORS` when `errors` empty
- File is built on the fly via anonymous `FromCollection+WithHeadings` export, stored on `local` disk, streamed with `deleteFileAfterSend(true)`

---

### GET /api/v1/brands/export

Queue an asynchronous Excel export of all brands.

The request **only queues the export**. File generation runs via `ExportBrandsJob` on `meem-medium`.

**Authentication**: `auth:sanctum`, permission: `export-brand` (or `super_admin`)

**Query Parameters**: None. Exports **all** brands ordered by `id` asc. No filters.

**Response 202**:
```json
{
  "status": 202,
  "message": "Brand export started successfully",
  "success": true,
  "data": { "export_id": 58, "status": "pending" }
}
```

**Quick Test**:
```bash
curl -X GET "http://example.com/api/v1/brands/export" \
  -H "Authorization: Bearer <token>" \
  -H "Accept: application/json"
```

**Business Rules**:
- Creates `imports` row with `type='brand-export'`, `status='pending'`, `file_path=''`, `file_name=''`, `total_rows=0`
- Dispatches `ExportBrandsJob` on `meem-medium` (tries 2, timeout 600s)
- File is written to the `imports` disk (`../../storage/app/imports` by convention) as `brands-export-{Y-m-d-His}.xlsx`
- Poll `GET /brands/export/{id}` with `export_id`

---

### GET /api/v1/brands/export/{id}

Fetch the status of a queued brand export.

**Authentication**: `auth:sanctum`, permission: `export-brand` (or `super_admin`), plus `ImportPolicy@view`

**Path Parameters**:

| Parameter | Type | Description |
|-----------|------|-------------|
| id | int | Export ID from `GET /brands/export` |

**Response 200**:
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

**Response 404**: not found / wrong type.

**Quick Test**:
```bash
curl -X GET "http://example.com/api/v1/brands/export/58" \
  -H "Authorization: Bearer <token>" \
  -H "Accept: application/json"
```

**Supported Statuses**: `pending`, `processing`, `completed`, `failed` (brand export never uses `completed_with_errors`/`cancelled`/`cancelling`).

**Business Rules**:
- `successful_rows` maps from `success_rows`
- `completed_at` present only for terminal (`completed`, `completed_with_errors`, `failed`, `cancelled`) — export uses `completed`/`failed`
- `Cache-Control: no-cache`

---

### GET /api/v1/brands/export/{id}/download

Download the generated brand export file.

**Authentication**: `auth:sanctum`, permission: `export-brand` (or `super_admin`), plus `ImportPolicy@view`

**Path Parameters**:

| Parameter | Type | Description |
|-----------|------|-------------|
| id | int | Export ID |

**Response 200**: Binary `.xlsx` (`brands-export-{timestamp}.xlsx`, content-type `application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`)

**Response 409** (not ready):
```json
{ "status": 409, "message": "Export file is not ready yet", "success": false }
```

**Response 404**: not found / wrong type.

**Quick Test**:
```bash
curl -X GET "http://example.com/api/v1/brands/export/58/download" \
  -H "Authorization: Bearer <token>" \
  -o brands-export.xlsx
```

**Business Rules**:
- Only when `status='completed'` **and** file exists on the `imports` disk (`Storage::disk('imports')->exists(file_path)`)
- Returns **409** (`EXPORT_NOT_READY`) while `pending`/`processing`/`failed` or file missing
- The exported Excel uses the exact 7 import columns and can be re-imported

---

## Excel Format Reference

| Column | Required | Description |
|--------|----------|-------------|
| `name_en` | Yes | English name — identity |
| `name_ar` | Yes | Arabic name |
| `details_en` | No | English details |
| `details_ar` | No | Arabic details |
| `status` | No | `1`/`0` (true/false/yes/no/on/off; empty → 1) |
| `image_desktop_url` | No | `http`/`https` URL |
| `image_mobile_url` | No | `http`/`https` URL |

## Import Identity Behavior

1. Normalize `name_en`; empty → row error `NAME_EN_REQUIRED`.
2. `name_ar` empty → row error `NAME_AR_REQUIRED`.
3. Duplicate `name_en` within file → row error `DUPLICATE_ROW`.
4. `status` invalid → row error `INVALID_STATUS`.
5. Image URL format invalid → row error `INVALID_IMAGE_URL`.
6. Image download failures (SSRF, size, mime, redirects) are **non-fatal** — logged via `report()` and skipped, brand still counts as success.
7. DB `name_en` match: 1 hit → update (slug safety check); 0 hits → create with `Str::slug(name_en)`; >1 hits → `AMBIGUOUS_NAME`.
8. Slug conflict (existing or newly created in same import) → `SLUG_CONFLICT`.
9. Empty slug → `INVALID_SLUG`.

## Image Import

| Property | Value |
|----------|-------|
| Protocols | `http`, `https` only |
| Allowed mimes | `image/jpeg`, `image/png`, `image/gif` (SVG blocked) |
| Max size | 5 MB |
| Redirects | max 5 |
| Timeout | 30 s |
| SSRF | DNS resolves host, blocks private/loopback/cgNAT/multicast (`isBlockedIp`) |
| Attachment | `clearMediaCollection` then `addMedia(temp)->toMediaCollection(collection, 'brands')` |

## Common Error Responses

| Status | When |
|--------|------|
| 401 | Missing/invalid Sanctum token |
| 403 | Lacks `import-brand` / `export-brand` (and not `super_admin`), or `ImportPolicy` owner check fails |
| 404 | Import/export not found for id+type, or sample file missing |
| 409 | Cancel on terminal import; export download before `completed` |
| 422 | `file` validation failure |

## Relation to Brand CRUD

These endpoints are **independent** of `GET/POST /brands`, `PUT /brands/{id}`, `DELETE /brands/{id}`, `PUT /brands/reorder`. Import creates/updates brands directly via `Brand::create` / `Brand::update`; export reads all brands. Reorder and CRUD permissions (`view-brands`, `create-brand`, etc.) do NOT grant import/export access.
