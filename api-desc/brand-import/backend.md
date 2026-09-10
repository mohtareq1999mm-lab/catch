# Brand Import / Export — Backend Architecture

## Overview

The Brand Excel Import/Export module is an async bulk-operation surface built atop the shared `imports` table. Every request creates a tracking row and dispatches a queued job on `meem-medium`; progress is mirrored in JSON signal files (`storage/app/imports/progress_{id}.json`, `cancel_{id}.json`) and in broadcasts (`FileOperationEvent`). Import identity is the normalized English name; slugging is deterministic via `Str::slug`. Images are fetched SSRF-safe into Spatie media collections.

Unlike Category import, Brand import has **no hierarchy** (no `parent_name_en`, no `is_featured`, no level/cycle logic), and export is a flat `id-asc` dump. Brand jobs run on `meem-medium` (category runs on `meem-high`).

## Endpoints

### Admin API (`/api/v1/brands`)

| Method | URL | Auth | Permission | Purpose |
|--------|-----|------|------------|---------|
| POST | `/api/v1/brands/import` | `auth:sanctum` | `import-brand` | Queue brand Excel import |
| GET | `/api/v1/brands/import/sample` | `auth:sanctum` | `import-brand` | Download import template |
| GET | `/api/v1/brands/import/{id}` | `auth:sanctum` | `import-brand` | Fetch import status/progress |
| POST | `/api/v1/brands/import/{id}/cancel` | `auth:sanctum` | `import-brand` | Cancel pending/processing import |
| GET | `/api/v1/brands/import/{id}/download-errors` | `auth:sanctum` | `import-brand` | Download failed rows as xlsx |
| GET | `/api/v1/brands/export` | `auth:sanctum` | `export-brand` | Queue brand Excel export |
| GET | `/api/v1/brands/export/{id}` | `auth:sanctum` | `export-brand` | Fetch export status |
| GET | `/api/v1/brands/export/{id}/download` | `auth:sanctum` | `export-brand` | Download export xlsx |

`super_admin` is accepted for all endpoints (`permission:import-brand|super_admin`, `permission:export-brand|super_admin`).

## Route Definitions

**File:** `../../packages/marvel/src/Rest/Routes.php` (admin group, inside `auth:sanctum` + `throttle:admin`)

```
Line 139: Route::post('brands/import',           [BrandImportController::class, 'import'])->name('admin.brands.import');
Line 140: Route::get('brands/import/sample',     [BrandImportController::class, 'downloadSample'])->name('admin.brands.import.sample');
Line 141: Route::get('brands/import/{id}',       [BrandImportController::class, 'status'])->whereNumber('id')->name('admin.brands.import.status');
Line 142: Route::post('brands/import/{id}/cancel',[BrandImportController::class, 'cancel'])->whereNumber('id')->name('admin.brands.import.cancel');
Line 143: Route::get('brands/import/{id}/download-errors', [BrandImportController::class, 'downloadErrors'])->whereNumber('id')->name('admin.brands.import.download-errors');
Line 144: Route::get('brands/export',            [BrandExportController::class, 'export'])->name('admin.brands.export');
Line 145: Route::get('brands/export/{id}',       [BrandExportController::class, 'status'])->whereNumber('id')->name('admin.brands.export.status');
Line 146: Route::get('brands/export/{id}/download', [BrandExportController::class, 'download'])->whereNumber('id')->name('admin.brands.export.download');
Line 147: Route::put('brands/reorder',           [BrandController::class, 'reorder']);
Line 148: Route::apiResource('brands',           BrandController::class);
```

> **Order matters:** import/export static routes are **before** `apiResource('brands')` so `GET /brands/export` and `GET /brands/import/sample` are not captured by `brands/{brand}`.

## Middleware

### BrandImportController (`Marvel\Http\Controllers\BrandImportController`)

| Method | Middleware |
|--------|-----------|
| `import` | `auth:sanctum` + `permission:import-brand|super_admin` |
| `downloadSample` | `auth:sanctum` + `permission:import-brand|super_admin` |
| `status` | `auth:sanctum` + `permission:import-brand|super_admin` + `ImportPolicy@view` |
| `cancel` | `auth:sanctum` + `permission:import-brand|super_admin` + `ImportPolicy@view` |
| `downloadErrors` | `auth:sanctum` + `permission:import-brand|super_admin` + `ImportPolicy@view` |

### BrandExportController (`Marvel\Http\Controllers\BrandExportController`)

| Method | Middleware |
|--------|-----------|
| `export` | `auth:sanctum` + `permission:export-brand|super_admin` |
| `status` | `auth:sanctum` + `permission:export-brand|super_admin` + `ImportPolicy@view` |
| `download` | `auth:sanctum` + `permission:export-brand|super_admin` + `ImportPolicy@view` |

Middleware is set in each controller's `__construct`. `auth:sanctum` is also on the route group. `throttle:admin` applies at the group level.

## Controller Flow

### BrandImportController
**File:** `../../packages/marvel/src/Http/Controllers/BrandImportController.php` (305 lines)
**Traits:** `ApiResponse`, `BroadcastsFileOperationProgress`
**Helpers:** `readSignalFile(id,type)`, `signalFileExists(id,type)`, `writeSignalFile(id,type,data)`, `estimateRowCount(filePath)`

```
POST /brands/import
  → BrandImportController@import(BrandImportRequest)
    → $file = request->file('file'); $filePath = $file->store('imports','imports')
    → estimateRowCount($filePath) → total_rows (PhpSpreadsheet getHighestDataRow per sheet)
    → Import::create(type='brand', file_path, file_name, status='pending', created_by)
        // Note: ImportType::BRAND_IMPORT is 'brand-import'; controller writes 'brand' (drift — see bug report)
    → writeSignalFile(progress, {processed:0, success:0, failed:0})
    → ImportBrandsJob::dispatch(import_id) [meem-medium]
    → 202 { import_id, status }

GET /brands/import/sample
  → BrandImportController@downloadSample()
    → $samplePath = config('marvel.import.samples.brand') → storage_path('packages/marvel/resources/brands/brand-import-sample.xlsx')
    → is_file ? download 'brand-import-sample.xlsx' (application/vnd.openxmlformats...) : 404 IMPORT.SAMPLE_NOT_FOUND

GET /brands/import/{id}
  → BrandImportController@status(id)
    → Import::where(type=ImportType::BRAND_IMPORT)->select([...])->findOrFail(id)
    → authorize('view', $import) [ImportPolicy — owner/role check]
    → cancelPending = signalFileExists(id,'cancel')
    → progressData = readSignalFile(id,'progress')
    → effectiveStatus = cancelPending ? 'cancelling' : import.status
    → progress = 100.0 (completed/completed_with_errors) | progressData.progress (processing with signal, failed/cancelled) | 0.0
    → processed/success/failed prefer signal over DB while running
    → 200 { id, status:effectiveStatus, total_rows, processed_rows, successful_rows, failed_rows, progress, errors, error_count, created_at, completed_at }
    → headers: Cache-Control no-cache

POST /brands/import/{id}/cancel
  → BrandImportController@cancel(id)
    → Import::where(type=BRAND_IMPORT)->select([id,status,created_by])->findOrFail(id); authorize('view')
    → if terminal (completed/completed_with_errors/failed/cancelled) → 409 IMPORT_CANNOT_CANCEL
    → writeSignalFile(cancel, {cancelled_at: ISO8601})
    → Import::where(id)->update(status='cancelled') (try/catch QueryException)
    → broadcastFileOperationTerminal(BRAND_IMPORT_PROGRESS, 'brand-import', id, 'cancelled', false)
    → 200 { import_id, status:'cancelled' }

GET /brands/import/{id}/download-errors
  → BrandImportController@downloadErrors(id)
    → Import::where(type=BRAND_IMPORT)->select([id,errors,created_by])->findOrFail(id); authorize('view')
    → if empty(errors) → 404 IMPORT_NO_ERRORS
    → build anonymous FromCollection+WithHeadings export from errors (Sheet, Row, Name (EN), Name (AR), Error Message)
    → Excel::store(filename, 'local') → response()->download(storage_path(app/filename), filename)->deleteFileAfterSend(true)
```

### BrandExportController
**File:** `../../packages/marvel/src/Http/Controllers/BrandExportController.php` (106 lines)
**Traits:** `ApiResponse`

```
GET /brands/export
  → BrandExportController@export(Request)
    → Import::create(type='brand-export', file_path='', file_name='', status='pending', created_by)
    → ExportBrandsJob::dispatch(import_id) [meem-medium]
    → 202 { export_id, status }

GET /brands/export/{id}
  → BrandExportController@status(id)
    → Import::where(type=BRAND_EXPORT)->select([...])->findOrFail(id); authorize('view')
    → 200 { id, status, total_rows, processed_rows, successful_rows, failed_rows, errors, created_at, completed_at }
    → Cache-Control no-cache

GET /brands/export/{id}/download
  → BrandExportController@download(id)
    → Import::where(type=BRAND_EXPORT)->select([id,status,file_path,file_name,created_by])->findOrFail(id); authorize('view')
    → if status !== 'completed' || !file_path || !Storage::disk('imports')->exists(file_path) → 409 EXPORT_NOT_READY
    → response()->download(Storage::disk('imports')->path(file_path), file_name ?: basename(file_path), Content-Type xlsx)
```

## Jobs

### ImportBrandsJob
**File:** `../../packages/marvel/src/Jobs/ImportBrandsJob.php` (271 lines)
**Traits:** `BroadcastsFileOperationProgress`

| Property | Value |
|----------|-------|
| Queue | `meem-medium` |
| Tries | 3 |
| Timeout | 1500 |
| Backoff | [60, 120, 240] |
| Dispatch | `BrandImportController@import` |

```
handle():
  1. Import::select([id,status,file_path,file_name])->findOrFail(importId)
  2. If status === 'cancelled' || cancel signal exists → Storage::disk('public')->delete(file_path); remove cancel signal; return
  3. If already terminal (completed/completed_with_errors/failed) → return
  4. update status→'processing', reset processed/success/failed to 0
  5. $service = new BrandImportService(importId); service->writeExplicitProgress(1.0)
  6. countRows() → PhpSpreadsheet highestDataRow per sheet; reconcile total_rows if drifted
  7. service->writeExplicitProgress(2.0)
  8. new BrandsImport($service); pick readerType from file_name extension (XLSX/XLS/ODS)
  9. Excel::import(BrandsImport, filePath, null, readerType) → BrandsImport sheets → service->processRows
  10. service->writeExplicitProgress(99.0); service->finalizeProgress()
  11. failedRows = service->getFailedRows(); success = service->getSuccessCount()
  12. status = completed (no errors) | completed_with_errors (errors && success>0) | failed (success==0)
  13. import->update({status, total=success+failed, processed=success+failed, success, failed, errors:failedRows})
  14. broadcastFileOperationTerminal(BRAND_IMPORT_PROGRESS, 'brand-import', id, status, hasErrors, {progress:100, total, processed, success, failed})
  15. Storage::disk('public')->delete(file_path); remove progress signal
  catch ImportCancelledException:
    → service->rollbackCreatedData() (soft-delete created brands; clear media)
    → Storage::disk('public')->delete(file_path); cleanSignals()
    → update imports row → cancelled + counters
    → broadcast BRAND_IMPORT_PROGRESS cancelled
  catch Throwable (non-cancel):
    → if attempts() >= tries → update errors with system row + status failed + broadcast failed + throw
    → else → append attempted error to errors and rethrow (retryable)

countRows(): same PhpSpreadsheet logic as controller estimate
failed(exception): if status === 'processing' → update failed + broadcast

Signal helpers: removeSignalFile(type), cancelSignalFileExists(), cleanSignals()
```

### ExportBrandsJob
**File:** `../../packages/marvel/src/Jobs/ExportBrandsJob.php` (120 lines)

| Property | Value |
|----------|-------|
| Queue | `meem-medium` |
| Tries | 2 |
| Timeout | 600 |

```
handle():
  1. Import::findOrFail(importId); if already terminal → return
  2. update status→'processing', reset counters
  3. $export = new BrandsExport(); rowCount = export->collection()->count()
  4. filename = 'brands-export-' + now(Y-m-d-His) + '.xlsx'; export->store(filename, 'imports')
  5. update imports row → completed, file_path=filename, file_name=filename, total/processed/success=rowCount, failed=0, errors=[]
  6. broadcastFileOperationTerminal(BRAND_EXPORT_COMPLETED, 'brand-export', id, completed, false, {progress:100, total:rowCount,...})
  catch Throwable:
    update failed; broadcast BRAND_EXPORT_FAILED; throw
failed(exception): if processing → update failed + broadcast
```

## Import Service

**File:** `../../packages/marvel/src/Services/Import/BrandImportService.php` (814 lines)
**Traits:** `BroadcastsFileOperationProgress`
**Constants:** `FLUSH_THRESHOLD=20`, `MAX_IMAGE_SIZE=5MB`, `MAX_REDIRECTS=5`, `IMAGE_TIMEOUT=30`, `ALLOWED_MIMES=[jpeg,png,gif]`

| Method | Responsibility |
|--------|----------------|
| `processRows(Collection)` | Orchestrates `prepareRows → upsertBrands → attachImages` with explicit progress ticks (10,80,99) |
| `prepareRows / prepareRow` | Per-row validation, boolean parsing, URL format check, temp image download |
| `upsertBrands` | Match normalized `name_en` → update or create with `Str::slug`; slug/ambiguous checks |
| `attachImages / attachImage` | Add temp images to `brands-desktop` / `brands-mobile` on `brands` disk |
| `downloadImage` | SSRF-safe fetch (redirect manual, size/mime/svg guards) |
| `assertSafeUrl / isBlockedIp / resolveHost` | SSRF protection — blocks 10/8, 172.16/12, 192.168/16, cgNAT 100.64/10, loopback, link-local, multicast, etc. |
| `loadExistingBrands` | Preloads DB brands into `dbByName` / `dbBySlug` maps |
| `updateSlugIsSafe` | Checks new slug doesn't collide with another brand |
| `rollbackCreatedData` | Soft-deletes brands in `createdIds` on cancellation |
| `finalizeProgress / writeExplicitProgress / flushProgressTick` | Signal file + broadcast helpers from trait |
| `isCancelled` | Checks `cancel_{id}.json` signal |
| `cleanupTempFiles` | Deletes temp image files in `finally` |

### prepareRow validation

| Check | Error key | Message |
|-------|-----------|---------|
| `name_en` empty | `NAME_EN_REQUIRED` | English name required |
| `name_ar` empty | `NAME_AR_REQUIRED` | Arabic name required |
| duplicate `name_en` in file (`seenNames`) | `DUPLICATE_ROW` | Duplicate brand in file |
| `status` invalid (not 1/0/true/false/yes/no/on/off/empty) | `INVALID_STATUS` | Invalid status |
| `image_desktop_url` / `image_mobile_url` invalid URL | `INVALID_IMAGE_URL` | Invalid URL |

On any `errors` non-empty → `addFailedRow(data, firstError)` and return (no image download).

Image downloads are attempted only for valid rows; failures are **non-fatal** (logged via `report()` and skipped — row still succeeds).

### upsertBrands logic

- `dbByName` holds `normalizedName → [Brand...]`
- `count(matches) > 1` → `AMBIGUOUS_NAME`
- `count == 1` → check `updateSlugIsSafe(brand, nameEn)`; on conflict → `SLUG_CONFLICT`; else `brand->update({name:{en,ar}, details:{en,ar}, status})`
- `count == 0` → `slug = Str::slug(nameEn)`; empty → `INVALID_SLUG`; slug in `dbBySlug` or `createdSlugs` → `SLUG_CONFLICT`; else `Brand::create(...)` and index into `dbByName/$dbBySlug/$createdSlugs/$createdIds`

### Signal / Progress

| File | Written By | Read By |
|------|-----------|---------|
| `storage/app/imports/progress_{id}.json` | Controller + Service (`writeExplicitProgress`, `flushProgressTick`) | Status endpoint |
| `storage/app/imports/cancel_{id}.json` | Cancel endpoint | Job + Service (`isCancelled`) |

Progress ticks: `1.0` (job start), `2.0` (row count), `10.0` (after prepare), `80.0` (after upsert), `99.0` (after attach), `100.0` (terminal broadcast).

## Excel Import / Export

### BrandsImport
**File:** `../../packages/marvel/src/Imports/BrandsImport.php`

```php
class BrandsImport implements WithMultipleSheets {
  __construct(BrandImportService $service)
  sheets(): array { return ['brands' => new BrandsSheetImport($service)]; }
}
```

### BrandsSheetImport
**File:** `../../packages/marvel/src/Imports/Sheets/BrandsSheetImport.php`

> **Known issue:** Current file type-hints `ProductImportService` and groups by `product_sku`/`brand_slug` (copy-paste from product import). The canonical import path uses `BrandImportService` directly via `ImportBrandsJob` → `BrandsImport` → sheet. The sheet class is not the active path for brand import; `BrandImportService::processRows` is invoked directly from the job's `Excel::import` via `BrandsImport`. See bug report.

Intended contract: `ToCollection` + `WithTitle('brands')` + `WithHeadingRow` + `SkipsEmptyRows`.

### BrandsExport
**File:** `../../packages/marvel/src/Exports/BrandsExport.php`
**Implements:** `FromCollection`, `WithHeadings`, `WithTitle`

```php
title(): 'brands'
headings(): ['name_en','name_ar','details_en','details_ar','status','image_desktop_url','image_mobile_url']
collection(): Brand::query()->select([id,name,details,slug,status])->orderBy(id)->get()->map([
  name_en => translation(name,en), name_ar => ..., details_*, status => (string)(int)status,
  image_desktop_url => getFirstMediaUrl('brands-desktop'), image_mobile_url => ...
])
store(filename, disk): Excel::store(this, filename, disk)  // disk='imports'
```

Translation helper mirrors category export — `getTranslation` with raw fallback.

## Import Model & Table

**File:** `../../packages/marvel/src/Database/Models/Import.php` | **Table:** `imports`

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint PK | |
| `type` | string | `brand` (on create) / `brand-import` (ImportType) / `brand-export` |
| `file_path` | string | Import: `imports/{uuid}.xlsx` on `imports` disk; Export: `brands-export-*.xlsx` |
| `file_name` | string | Import: original upload name; Export: generated name |
| `images_source` | string | default `none` (unused) |
| `zip_file_path` | string nullable | unused |
| `status` | string | `pending`, `processing`, `completed`, `completed_with_errors`, `failed`, `cancelled` |
| `total_rows` | int | Estimated then reconciled |
| `processed_rows` | int | Success + failed |
| `success_rows` | int | DB name; API exposes `successful_rows` |
| `failed_rows` | int | |
| `errors` | json nullable | Array of `{sheet,row,name_en,name_ar,error_message}` |
| `created_by` | FK → users.id | Owner for `ImportPolicy@view` |
| `created_at` / `updated_at` | timestamps | `updated_at` → `completed_at` for terminal |

### ImportStatus Enum (`Marvel\Enums\ImportStatus`)

`PENDING`, `PROCESSING`, `COMPLETED`, `COMPLETED_WITH_ERRORS`, `FAILED`, `CANCELLED`.

## Request Validation

### BrandImportRequest
**File:** `../../packages/marvel/src/Http/Requests/BrandImportRequest.php`

| Field | Rules | Message |
|-------|-------|---------|
| `file` | `required`, `file`, `mimes:xlsx,xls,ods`, `max:20480` | `FILE_REQUIRED`, `FILE_MIMES`, `FILE_MAX` |

`authorize()` returns `true` — authorization is via controller middleware + policy.

### BrandExportRequest
No dedicated FormRequest — `BrandExportController@export` takes generic `Illuminate\Http\Request` (no validation, no filters).

## Broadcast & Realtime

**File:** `../../app/Events/FileOperationEvent.php` + `app/Traits/BroadcastsFileOperationProgress.php`

| Constant | Channel event | Emitter |
|----------|--------------|---------|
| `BRAND_IMPORT_PROGRESS` | `brand.import.progress` | `BrandImportService`, `ImportBrandsJob`, `BrandImportController@cancel` |
| `BRAND_EXPORT_COMPLETED` | `brand.export.completed` | `ExportBrandsJob` |
| `BRAND_EXPORT_FAILED` | `brand.export.failed` | `ExportBrandsJob` |

Broadcast is on `private:users.{userId}` (`ShouldBroadcastNow`). Payload is whitelisted (kind, id, status, progress, counters, has_errors) — never paths or raw errors. Client must poll status after receiving the event.

## Queue Configuration

| Job | Queue | Tries | Timeout | Backoff |
|-----|-------|-------|---------|---------|
| `ImportBrandsJob` | `meem-medium` | 3 | 1500 | [60,120,240] |
| `ExportBrandsJob` | `meem-medium` | 2 | 600 | — |

## Permissions & Policies

- `Permission::IMPORT_BRAND = 'import-brand'`, `EXPORT_BRAND = 'export-brand'`, `SUPER_ADMIN = 'super_admin'`
- `ImportPolicy@view` gates `status`/`cancel`/`downloadErrors`/`export status`/`export download` — checks `created_by` ownership (admin can view own imports; super_admin may view all depending on policy implementation).
- Seeded in tests via `SpatiePermission::firstOrCreate`.

## Translation Keys Used

| Key | Context |
|-----|---------|
| `MESSAGE.BRAND_IMPORT_STARTED` | POST /import 202 |
| `MESSAGE.BRAND_IMPORT_STATUS_FETCHED` | GET /import/{id} 200 |
| `MESSAGE.BRAND_EXPORT_STARTED` | GET /export 202 |
| `MESSAGE.BRAND_EXPORT_STATUS_FETCHED` | GET /export/{id} 200 |
| `MESSAGE.IMPORT_CANNOT_CANCEL` | cancel 409 |
| `MESSAGE.IMPORT_CANCELLED_SUCCESSFULLY` | cancel 200 |
| `MESSAGE.IMPORT_NO_ERRORS` | download-errors 404 |
| `MESSAGE.EXPORT_NOT_READY` | export download 409 |
| `MESSAGE.IMPORT.SAMPLE_NOT_FOUND` | sample 404 |
| `IMPORT.VALIDATION.FILE_REQUIRED/MIMES/MAX` | file validation |
| `IMPORT.BRAND.NAME_EN_REQUIRED` | row error |
| `IMPORT.BRAND.NAME_AR_REQUIRED` | |
| `IMPORT.BRAND.DUPLICATE_ROW` | |
| `IMPORT.BRAND.INVALID_STATUS` | |
| `IMPORT.BRAND.INVALID_IMAGE_URL` | |
| `IMPORT.BRAND.AMBIGUOUS_NAME` | |
| `IMPORT.BRAND.SLUG_CONFLICT` | |
| `IMPORT.BRAND.INVALID_SLUG` | |
| `IMPORT.BRAND.UNSAFE_IMAGE_URL` | image download |
| `IMPORT.BRAND.IMAGE_DOWNLOAD_FAILED` | |
| `IMPORT.BRAND.IMAGE_TOO_LARGE` | |
| `IMPORT.BRAND.UNSUPPORTED_IMAGE_TYPE` | |
| `IMPORT.BRAND.INVALID_IMAGE_FILE` | |
| `IMPORT.BRAND.TOO_MANY_REDIRECTS` | |

## Dependencies

| File | Role |
|------|------|
| `../../packages/marvel/src/Rest/Routes.php` | Route definitions (order before apiResource) |
| `../../packages/marvel/src/Http/Controllers/BrandImportController.php` | Import + signal + estimate |
| `../../packages/marvel/src/Http/Controllers/BrandExportController.php` | Export + download |
| `../../packages/marvel/src/Http/Requests/BrandImportRequest.php` | Validation |
| `../../packages/marvel/src/Services/Import/BrandImportService.php` | Pipeline + SSRF |
| `../../packages/marvel/src/Imports/BrandsImport.php` | Sheet map |
| `../../packages/marvel/src/Imports/Sheets/BrandsSheetImport.php` | Sheet (legacy copy) |
| `../../packages/marvel/src/Exports/BrandsExport.php` | Export collection |
| `../../packages/marvel/src/Jobs/ImportBrandsJob.php` | Async import |
| `../../packages/marvel/src/Jobs/ExportBrandsJob.php` | Async export |
| `../../packages/marvel/src/Database/Models/Brand.php` | Spatie translatable + media + sortable + soft deletes |
| `../../packages/marvel/src/Database/Models/Import.php` | Tracking table |
| `../../config/marvel.php` | Sample path |
| `../../app/Events/FileOperationEvent.php` | Realtime events |
| `../../app/Traits/BroadcastsFileOperationProgress.php` | Broadcast helpers |

## Brand Model Context

**File:** `../../packages/marvel/src/Database/Models/Brand.php` — `brands` table, `HasTranslations(name,details)`, `InteractsWithMedia`, `SortableTrait` (`order`), `SoftDeletes`. Media collections `brands-desktop` / `brands-mobile` on disk `brands`. Import service creates brands directly; it does not go through `BrandRepository` or `BrandController` CRUD.

## Configuration

**File:** `../../config/marvel.php`

```php
'marvel' => [
  'import' => [
    'samples' => [
      'brand' => storage_path('packages/marvel/resources/brands/brand-import-sample.xlsx'),
    ]
  ]
]
```

**Disks:** `imports` (local, `../../storage/app/imports`), `brands` (local, `storage/app/public/brands`), `public`, `local`. Controller stores uploads on `imports`; job and export check `public`/`imports` — see inconsistency note in bug report.
