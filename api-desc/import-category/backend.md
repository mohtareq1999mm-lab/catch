# Category Import / Export — Backend Architecture

## Overview

The Category Excel Import/Export module lets administrators bulk import and export hierarchical categories through Excel files. Both flows are fully asynchronous: the HTTP request creates a tracking row in the `imports` table and dispatches a queued job on the `meem-high` queue. Progress is shared through JSON signal files under `storage/app/imports/` (e.g., `progress_{id}.json`, `cancel_{id}.json`) plus the `imports` table itself.

Import identity is the normalized English name; the slug is always derived from it via `Str::slug()` (deterministic, never `globalSlugify()`, never random). Parents are resolved by English name, giving row-order-independent multi-level imports with cycle detection via `CategoryHierarchyService`. Images are downloaded from public URLs with SSRF protection into Spatie media collections.

## Endpoints

### Admin API (`/api/v1/categories`)

| Method | URL | Auth | Permission | Purpose |
|--------|-----|------|------------|---------|
| POST | `/api/v1/categories/import` | `auth:sanctum` | `import-category` | Queue an Excel category import |
| GET | `/api/v1/categories/import/sample` | `auth:sanctum` | `import-category` | Download official import template |
| GET | `/api/v1/categories/import/{id}` | `auth:sanctum` | `import-category` | Fetch import progress/status |
| POST | `/api/v1/categories/import/{id}/cancel` | `auth:sanctum` | `import-category` | Cancel pending/processing import |
| GET | `/api/v1/categories/import/{id}/download-errors` | `auth:sanctum` | `import-category` | Download failed rows as xlsx |
| GET | `/api/v1/categories/export` | `auth:sanctum` | `export-category` | Queue an Excel category export |
| GET | `/api/v1/categories/export/{id}` | `auth:sanctum` | `export-category` | Fetch export status |
| GET | `/api/v1/categories/export/{id}/download` | `auth:sanctum` | `export-category` | Download generated xlsx |

`super_admin` is accepted by the permission middleware for every endpoint above (`permission:import-category|super_admin` and `permission:export-category|super_admin`).

## Route Definitions

**File:** `packages/marvel/src/Rest/Routes.php` (admin group, lines 142-151)

```
Line 142: Route::post('categories/import', [CategoryImportController::class, 'import'])->name('admin.categories.import');
Line 143: Route::get('categories/import/sample', [CategoryImportController::class, 'downloadSample'])->name('admin.categories.import.sample');
Line 144: Route::get('categories/import/{id}', [CategoryImportController::class, 'status'])->name('admin.categories.import.status');
Line 145: Route::post('categories/import/{id}/cancel', [CategoryImportController::class, 'cancel'])->name('admin.categories.import.cancel');
Line 146: Route::get('categories/import/{id}/download-errors', [CategoryImportController::class, 'downloadErrors'])->name('admin.categories.import.download-errors');
Line 147: Route::get('categories/export', [CategoryExportController::class, 'export'])->name('admin.categories.export');
Line 148: Route::get('categories/export/{id}', [CategoryExportController::class, 'status'])->name('admin.categories.export.status');
Line 149: Route::get('categories/export/{id}/download', [CategoryExportController::class, 'download'])->name('admin.categories.export.download');
```

**Note:** The routes are declared inside the `auth:sanctum` + `throttle:admin` middleware group and **before** `Route::apiResource('categories', ...)`. Static segments (`import`, `export`, `import/sample`) are matched before the `{category}` parameter binding, avoiding collisions.

## Middleware

### CategoryImportController (`Marvel\Http\Controllers\CategoryImportController`)

| Method | Middleware |
|--------|-----------|
| `import` | `auth:sanctum` + `permission:import-category\|super_admin` |
| `status` | `auth:sanctum` + `permission:import-category\|super_admin` |
| `cancel` | `auth:sanctum` + `permission:import-category\|super_admin` |
| `downloadErrors` | `auth:sanctum` + `permission:import-category\|super_admin` |
| `downloadSample` | `auth:sanctum` + `permission:import-category\|super_admin` |

Middleware is applied via the controller constructor. `auth:sanctum` is also applied at the route group level.

### CategoryExportController (`Marvel\Http\Controllers\CategoryExportController`)

| Method | Middleware |
|--------|-----------|
| `export` | `auth:sanctum` + `permission:export-category\|super_admin` |
| `status` | `auth:sanctum` + `permission:export-category\|super_admin` |
| `download` | `auth:sanctum` + `permission:export-category\|super_admin` |

## Controller Flow

### CategoryImportController
**File:** `packages/marvel/src/Http/Controllers/CategoryImportController.php`

```
POST /categories/import
  → CategoryImportController@import(CategoryImportRequest)
    → $file = request->file('file')
    → store on 'public' disk under 'imports/'
    → estimateRowCount() → total_rows (PhpSpreadsheet highest data row)
    → Import::create(type='category', status='pending', created_by=user)
    → writeSignalFile('progress', processed=0, success=0, failed=0)
    → ImportCategoriesJob::dispatch(import_id)   [meem-high]
    → 202 { import_id, status }

GET /categories/import/{id}
  → CategoryImportController@status($id)
    → Import::select([...])->findOrFail($id)
    → effectiveStatus = 'cancelling' if cancel signal exists, else import.status
    → progress = 100.0 (completed/completed_with_errors) | signal progress (processing/failed/cancelled) | 0.0
    → 200 { id, status, total_rows, processed_rows, successful_rows, failed_rows,
            progress, errors, error_count, created_at, completed_at }
    → Cache-Control: no-cache

POST /categories/import/{id}/cancel
  → CategoryImportController@cancel($id)
    → If status terminal (completed/completed_with_errors/failed/cancelled) → 409
    → writeSignalFile('cancel') + Import status update → 'cancelled'
    → 200 { import_id, status: 'cancelled' }

GET /categories/import/{id}/download-errors
  → CategoryImportController@downloadErrors($id)
    → If errors empty → 404 'No errors found'
    → Build xlsx from errors (headings: Sheet, Row, Name (EN), Name (AR), Parent Name (EN), Error Message)
    → Stream download `failed_category_import_rows_{id}.xlsx`, delete after send

GET /categories/import/sample
  → CategoryImportController@downloadSample()
    → Binary download of packages/marvel/resources/categories/category-import-sample.xlsx
```

### CategoryExportController
**File:** `packages/marvel/src/Http/Controllers/CategoryExportController.php`

```
GET /categories/export
  → CategoryExportController@export(CategoryExportRequest)
    → Import::create(type='category-export', file_path='', file_name='', status='pending')
    → ExportCategoriesJob::dispatch(import_id)   [meem-high]
    → 202 { export_id, status }

GET /categories/export/{id}
  → CategoryExportController@status($id)
    → 200 { id, status, total_rows, processed_rows, successful_rows, failed_rows,
            errors, created_at, completed_at }
    → Cache-Control: no-cache

GET /categories/export/{id}/download
  → CategoryExportController@download($id)
    → If status !== 'completed' OR file missing on public disk → 409 'Export file is not ready yet'
    → Stream xlsx download
```

## Jobs

### ImportCategoriesJob
**File:** `packages/marvel/src/Jobs/ImportCategoriesJob.php`

| Property | Value |
|----------|-------|
| Queue | `meem-high` |
| Tries | 3 |
| Timeout | 1500 |
| Backoff | [60, 120, 240] |

```
handle():
  1. If cancelled (signal) → delete uploaded file, remove cancel signal, return
  2. If already terminal → return
  3. status → 'processing', reset counters
  4. countRows() → total_rows (reconcile if changed)
  5. Build CategoryImportService(importId)
  6. Excel::import(CategoriesImport, file, XLSX/XLS/ODS by extension)
     → service->processRows() → prepareRows → upsertCategories → assignParents → attachImages
  7. finalizeProgress(); compute failedRows + successCount
  8. status = completed | completed_with_errors (success>0 & errors) | failed (no success & no errors)
  9. Update imports row; delete uploaded file; remove progress signal
  On ImportCancelledException:
    → service->rollbackCreatedData() (soft-delete created categories)
    → status → 'cancelled'
  On Throwable:
    → status → 'failed' with system error row; rethrow
failed(exception):
  → If status was 'processing' → status → 'failed'
```

### ExportCategoriesJob
**File:** `packages/marvel/src/Jobs/ExportCategoriesJob.php`

| Property | Value |
|----------|-------|
| Queue | `meem-high` |
| Tries | 2 |
| Timeout | 600 |

```
handle():
  1. If already terminal → return
  2. status → 'processing', reset counters
  3. new CategoriesExport() → loads ALL categories (level asc, id asc)
  4. store as categories-export-{Y-m-d-His}.xlsx on 'public' disk
  5. Update imports row → status 'completed', file_path/file_name, total/processed/success rows, errors []
  On Throwable:
    → status → 'failed'; rethrow
failed(exception):
  → If status was 'processing' → status → 'failed'
```

## Import Service

**File:** `packages/marvel/src/Services/Import/CategoryImportService.php`

| Method | Responsibility |
|--------|----------------|
| `processRows(Collection)` | Orchestrates prepare → upsert → assignParents → attachImages |
| `prepareRows` / `prepareRow` | Per-row validation, boolean parsing, image URL format check, image download to temp |
| `upsertCategories` | Match by normalized `name_en` → update, else create with `Str::slug()`; slug-conflict detection |
| `assignParents` | Resolve `parent_name_en` → `parent_id`; missing/ambiguous parent, self-parent, cycle detection; save |
| `attachImages` / `attachImage` | Clear + add media to `categories-desktop` / `categories-mobile` on `categories` disk |
| `downloadImage` | SSRF-safe download (redirects, size, MIME, image verification) |
| `assertSafeUrl` / `isBlockedIp` | SSRF protection |
| `rollbackCreatedData` | Soft-delete categories created during a cancelled import |
| `finalizeProgress` | Flush counters to the `imports` row |

### Signal Files

| File | Written By | Read By |
|------|-----------|---------|
| `storage/app/imports/progress_{id}.json` | Import controller + service | Status endpoint |
| `storage/app/imports/cancel_{id}.json` | Cancel endpoint | Job + service |

### Row Errors

Failed rows are recorded as:

```json
{
  "sheet": "categories",
  "row": 5,
  "name_en": "Smartwatches",
  "name_ar": "ساعات ذكية",
  "parent_name_en": "Electronics",
  "error_message": "The parent category 'Electronics' was not found."
}
```

Validation in `prepareRow` covers: `name_en` required, `name_ar` required, duplicate `name_en` within the file, invalid `status` / `is_featured` values, invalid image URL format, image download failures (unsafe URL, too large, unsupported type, invalid file, too many redirects). Hierarchy errors in `assignParents` cover: missing parent, ambiguous parent, self-parent, circular hierarchy, invalid parent.

## Excel Import

**File:** `packages/marvel/src/Imports/CategoriesImport.php`

- Sheet title: `categories`
- `WithHeadingRow` (header row 1), `WithStartRow` (data from row 2)
- `ToCollection` — rows are batched to the service; `SkipsEmptyRows`

## Excel Export

**File:** `packages/marvel/src/Exports/CategoriesExport.php`

- `FromCollection`, `WithHeadings`, `WithMapping`
- Loads **all** categories: `with('parent')`, ordered by `level` asc then `id` asc
- Resolves `parent_name_en` from the parent's English name; image URLs from `categories-desktop` / `categories-mobile` media collections
- Headings are the exact 9 import columns

## Import Model & Table

**File:** `packages/marvel/src/Database/Models/Import.php`
**Table:** `imports`
**Migration:** `database/migrations/2026_06_27_000001_create_imports_table.php`

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint | PK |
| `type` | string | `category`, `category-export` |
| `file_path` | string | Import: upload path on `public`; export: generated file path |
| `file_name` | string | Original / generated file name |
| `images_source` | string | default `none` |
| `zip_file_path` | string nullable | unused |
| `status` | string | `pending`, `processing`, `completed`, `completed_with_errors`, `failed`, `cancelled` |
| `total_rows` | int | Row count |
| `processed_rows` | int | Processed count |
| `success_rows` | int | Success count (API exposes as `successful_rows`) |
| `failed_rows` | int | Failed count |
| `errors` | json nullable | Row error array |
| `created_by` | FK → users.id | Requester |
| `created_at` / `updated_at` | timestamp | `updated_at` becomes `completed_at` in status responses |

### ImportStatus Enum
**File:** `packages/marvel/src/Enums/ImportStatus.php`

| Constant | Value |
|----------|-------|
| `PENDING` | `pending` |
| `PROCESSING` | `processing` |
| `COMPLETED` | `completed` |
| `COMPLETED_WITH_ERRORS` | `completed_with_errors` |
| `FAILED` | `failed` |
| `CANCELLED` | `cancelled` |

## Request Validation

### CategoryImportRequest (`Marvel\Http\Requests\CategoryImportRequest`)
**File:** `packages/marvel/src/Http/Requests/CategoryImportRequest.php`

| Field | Rules |
|-------|-------|
| `file` | `required`, `file`, `mimes:xlsx,xls,ods`, `max:20480` (20 MB) |

Custom messages:
- `file.required` → `IMPORT.VALIDATION.FILE_REQUIRED`
- `file.mimes` → `IMPORT.VALIDATION.FILE_MIMES`
- `file.max` → `IMPORT.VALIDATION.FILE_MAX`

### CategoryExportRequest (`Marvel\Http\Requests\CategoryExportRequest`)
**File:** `packages/marvel/src/Http/Requests/CategoryExportRequest.php`

No validation rules — the export always exports all categories.

## Queue Configuration

| Job | Queue | Tries | Timeout | Backoff |
|-----|-------|-------|---------|---------|
| `ImportCategoriesJob` | `meem-high` | 3 | 1500 | [60, 120, 240] |
| `ExportCategoriesJob` | `meem-high` | 2 | 600 | — |

## Permissions

**Enum:** `Marvel\Enums\Permission`

| Constant | Value |
|----------|-------|
| `IMPORT_CATEGORY` | `import-category` |
| `EXPORT_CATEGORY` | `export-category` |
| `SUPER_ADMIN` | `super_admin` |

## Translation Keys Used

| Key | Context |
|-----|---------|
| `MESSAGE.CATEGORY_IMPORT_STARTED` | POST /import 202 response |
| `MESSAGE.CATEGORY_IMPORT_STATUS_FETCHED` | GET /import/{id} response |
| `MESSAGE.IMPORT_CANNOT_CANCEL` | Cancel on terminal state (409) |
| `MESSAGE.IMPORT_CANCELLED_SUCCESSFULLY` | Cancel success (200) |
| `MESSAGE.IMPORT_NO_ERRORS` | download-errors with no errors (404) |
| `MESSAGE.CATEGORY_EXPORT_STARTED` | GET /export 202 response |
| `MESSAGE.CATEGORY_EXPORT_STATUS_FETCHED` | GET /export/{id} response |
| `MESSAGE.EXPORT_NOT_READY` | Download when not ready (409) |
| `IMPORT.VALIDATION.FILE_REQUIRED` | file required |
| `IMPORT.VALIDATION.FILE_MIMES` | invalid format |
| `IMPORT.VALIDATION.FILE_MAX` | file too large |
| `IMPORT.CATEGORY.*` | Row-level errors (name required, duplicate, status/featured, image, parent, slug) |

## Dependencies

| File | Role |
|------|------|
| `packages/marvel/src/Rest/Routes.php` | Admin route definitions |
| `packages/marvel/src/Http/Controllers/CategoryImportController.php` | Import endpoints |
| `packages/marvel/src/Http/Controllers/CategoryExportController.php` | Export endpoints |
| `packages/marvel/src/Http/Requests/CategoryImportRequest.php` | Import validation |
| `packages/marvel/src/Http/Requests/CategoryExportRequest.php` | Export request |
| `packages/marvel/src/Services/Import/CategoryImportService.php` | Import pipeline + SSRF-safe downloads |
| `packages/marvel/src/Imports/CategoriesImport.php` | Excel import |
| `packages/marvel/src/Exports/CategoriesExport.php` | Excel export |
| `packages/marvel/src/Jobs/ImportCategoriesJob.php` | Async import worker |
| `packages/marvel/src/Jobs/ExportCategoriesJob.php` | Async export worker |
| `packages/marvel/src/Database/Models/Import.php` | Tracking model |
| `packages/marvel/src/Enums/ImportStatus.php` | Status enum |
| `packages/marvel/src/Enums/Permission.php` | Permissions |
| `app/Services/General/CategoryHierarchyService.php` | Parent/cycle validation |
| `packages/marvel/src/Database/Models/Category.php` | Category model |
| `database/migrations/2026_06_27_000001_create_imports_table.php` | Imports table |
| `packages/marvel/resources/categories/category-import-sample.xlsx` | Sample template |
| `resources/lang/en/message.php` / `ar/message.php` | Translations |
| `tests/Feature/Categories/CategoryImportTest.php` | Import tests |
| `tests/Feature/Categories/CategoryExportTest.php` | Export tests |