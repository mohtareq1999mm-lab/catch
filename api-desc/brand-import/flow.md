# Request Flows — Brand Import / Export

## Flow 1: Queue Brand Import (Admin)

```
Client → POST /api/v1/brands/import (multipart/form-data, file=brands.xlsx)
         ↓
    [auth:sanctum] → authenticate
         ↓
    [permission:import-brand|super_admin] → check Spatie permission
         ↓
    BrandImportRequest → validation:
      file: required, file, mimes:xlsx,xls,ods, max:20480
         ↓
    Fail? → 422 { message, errors.file }
         ↓
    BrandImportController@import()
         ↓
    Store file on 'imports' disk → storage/app/imports/{uuid}.xlsx
         ↓
    estimateRowCount() → PhpSpreadsheet getHighestDataRow per sheet → total_rows
         ↓
    Import::create(type='brand', status='pending', created_by=user.id, total_rows)
         ↓
    writeSignalFile('progress', {processed:0, success:0, failed:0})
         ↓
    ImportBrandsJob::dispatch(import_id) → meem-medium
         ↓
    202 { status:202, message:BRAND_IMPORT_STARTED, success:true, data:{import_id, status:'pending'} }
```

## Flow 2: Import Job Execution (Async, meem-medium)

```
Worker → ImportBrandsJob@handle(importId)
         ↓
    Import::select([id,status,file_path,file_name])->findOrFail
         ↓
    cancelled? (DB status==='cancelled' || cancel signal exists) → Storage::disk('public')->delete(file); remove cancel signal; return
         ↓
    Already terminal (completed/completed_with_errors/failed)? → return
         ↓
    status→'processing', reset processed/success/failed=0
         ↓
    $service = new BrandImportService(importId); service->writeExplicitProgress(1.0)
         ↓
    countRows() → PhpSpreadsheet highestDataRow per sheet → total_rows (reconcile if drifted)
         ↓
    service->writeExplicitProgress(2.0)
         ↓
    new BrandsImport($service); readerType from file_name extension (XLSX/XLS/ODS)
         ↓
    Excel::import(BrandsImport, filePath, null, readerType)
         ↓
    BrandsImport sheets['brands'] → BrandImportService@processRows(rows)
```

### Inside BrandImportService::processRows

```
[1] prepareRows(rows) → loadExistingBrands() → dbByName / dbBySlug maps
        for each row (index→excel_row=index+2):
          - normalize name_en, trim name_ar, defaults status=1
          - validate: name_en required, name_ar required, duplicate name_en in file → addFailedRow & return
          - parseBooleanField(status) → '1'/'0'/null/invalid
          - URL format checks for image_desktop_url / image_mobile_url → invalid → addFailedRow
          - if errors non-empty → skip downloads
          - else downloadImage(desktop) + downloadImage(mobile) (SSRF-safe, 5MB, redirects 5, jpeg/png/gif only)
            failures → report() & set temp=null (non-fatal)
        writeExplicitProgress(10.0)
         ↓
    isCancelled()? → throw ImportCancelledException
         ↓
[2] upsertBrands(pending)
        for each valid row:
          matches = dbByName[nameEn] ?? []
          >1 → fail AMBIGUOUS_NAME
          ==1 → updateSlugIsSafe? → fail SLUG_CONFLICT : brand.update({name:{en,ar}, details:{en,ar}, status})
          ==0 → slug=Str::slug(nameEn) → empty→INVALID_SLUG; slug in dbBySlug/createdSlugs→SLUG_CONFLICT
                else Brand::create({name,details,slug,status}); index into maps; createdIds[]
        writeExplicitProgress(80.0)
         ↓
    isCancelled()? → throw ImportCancelledException
         ↓
[3] attachImages(pending)
        for each row with target!==null:
          if temp_desktop → attachImage(target, temp, 'brands-desktop') (clear + addMedia → disk 'brands')
          if temp_mobile → attachImage(target, temp, 'brands-mobile')
          successCount++ (even if attach failed — image failures are non-fatal)
          flushProgressTick() (every FLUSH_THRESHOLD=20 or time-based)
        writeExplicitProgress(99.0)
         ↓
    finally → cleanupTempFiles()
         ↓
    service->finalizeProgress() (flush counters to signal file + broadcast)
         ↓
    failedRows = service->getFailedRows(); success = service->getSuccessCount()
         ↓
    status = completed (no errors & success>0) | completed_with_errors (errors && success>0) | failed (success==0)
         ↓
    Import update {status, total=success+failed, processed=success+failed, success_rows=success, failed_rows=count(failed), errors=failedRows}
         ↓
    broadcastFileOperationTerminal(BRAND_IMPORT_PROGRESS, 'brand-import', id, status, hasErrors, {progress:100,...})
         ↓
    Storage::disk('public')->delete(file); remove progress signal
```

### Cancellation / Failure paths

```
isCancelled() signal detected during processRows
  → throw ImportCancelledException
  → catch in ImportBrandsJob → service->rollbackCreatedData() (soft-delete created brand ids, clear media)
  → Storage::disk('public')->delete(file); cleanSignals(); update imports → cancelled; broadcast cancelled

Throwable (non-cancel, non-validation)
  → if attempts() >= tries (3) → update errors with system row {sheet:system,row:0,error_message: e.message}, status failed, broadcast failed, throw
  → else → append {Attempt N: e.message} to errors and rethrow for retry
```

## Flow 3: Import Cancelled via API (Sync) while Job Pending/Processing

```
Client → POST /api/v1/brands/import/42/cancel
         ↓
    [auth:sanctum] → [permission:import-brand|super_admin] → authorize('view', import)
         ↓
    Import::where(type=BRAND_IMPORT)->findOrFail(42)
         ↓
    Terminal? (completed/completed_with_errors/failed/cancelled) → 409 IMPORT_CANNOT_CANCEL
         ↓
    writeSignalFile(cancel, {cancelled_at: ISO8601}) → storage/app/imports/cancel_42.json
         ↓
    Import::where(id)->update(status='cancelled') (eager; job will also handle rollback)
         ↓
    broadcastFileOperationTerminal(BRAND_IMPORT_PROGRESS, 'brand-import', 42, cancelled, false)
         ↓
    200 { status:200, message:IMPORT_CANCELLED_SUCCESSFULLY, data:{import_id:42, status:'cancelled'} }

Worker (concurrently) → isCancelled() becomes true → ImportCancelledException path above
```

## Flow 4: Import Status Polling

```
Client → GET /api/v1/brands/import/42
         ↓
    [auth:sanctum] → [permission:import-brand|super_admin] → authorize('view', import)
         ↓
    Import::where(type=BRAND_IMPORT)->select([id,status,total,processed,success,failed,errors,created_at,updated_at,created_by])->findOrFail(42)
         ↓
    effectiveStatus = cancel signal exists ? 'cancelling' : import.status
         ↓
    progress = 100.0 if completed/completed_with_errors
             = signal.progress if failed/cancelled
             = signal.progress if processing && !cancelling && signal exists (fallback 99.0)
             = 0.0 otherwise
         ↓
    processed/success/failed = signal values if present else DB values
         ↓
    isTerminal = in(completed,completed_with_errors,failed,cancelled)
         ↓
    200 (Cache-Control: no-cache) { status:200, message, success:true,
      data:{ id, status:effectiveStatus, total_rows, processed_rows, successful_rows, failed_rows,
             progress, errors, error_count, created_at, completed_at: terminal? updated_at : null } }
```

## Flow 5: Download Import Errors

```
Client → GET /api/v1/brands/import/42/download-errors
         ↓
    [auth:sanctum] → [permission:import-brand|super_admin] → authorize('view')
         ↓
    Import::where(type=BRAND_IMPORT)->select([id,errors,created_by])->findOrFail(42)
         ↓
    empty(errors)? → 404 { status:404, message:IMPORT_NO_ERRORS, success:false }
         ↓
    Build anonymous FromCollection+WithHeadings export from errors array
      headings: Sheet, Row, Name (EN), Name (AR), Error Message
      rows: map each error -> {sheet,row,name_en,name_ar,error_message}
         ↓
    Excel::store(filename, 'local') → storage/app/failed_brand_import_rows_42.xlsx
         ↓
    response()->download(storage_path(app/filename), filename, Content-Type xlsx)->deleteFileAfterSend(true)
```

## Flow 6: Download Sample Template

```
Client → GET /api/v1/brands/import/sample
         ↓
    [auth:sanctum] → [permission:import-brand|super_admin]
         ↓
    config('marvel.import.samples.brand') → storage_path('packages/marvel/resources/brands/brand-import-sample.xlsx')
         ↓
    is_file? → download as 'brand-import-sample.xlsx' (application/vnd.openxmlformats...) : 404 IMPORT.SAMPLE_NOT_FOUND
```

## Flow 7: Queue Brand Export

```
Client → GET /api/v1/brands/export
         ↓
    [auth:sanctum] → [permission:export-brand|super_admin]
         ↓
    BrandExportController@export()
         ↓
    Import::create(type='brand-export', file_path='', file_name='', status='pending', created_by)
         ↓
    ExportBrandsJob::dispatch(import_id) → meem-medium
         ↓
    202 { status:202, message:BRAND_EXPORT_STARTED, data:{export_id, status:'pending'} }
```

## Flow 8: Export Job Execution (Async, meem-medium)

```
Worker → ExportBrandsJob@handle(importId)
         ↓
    Import::findOrFail(importId); if already terminal → return
         ↓
    status→'processing', reset counters
         ↓
    new BrandsExport() → Brand::query()->select([id,name,details,slug,status])->orderBy(id)->get()
      → map each brand -> {name_en, name_ar, details_en, details_ar, status, image_desktop_url, image_mobile_url}
         ↓
    rowCount = collection()->count()
         ↓
    filename = 'brands-export-' + now(Y-m-d-His) + '.xlsx'
         ↓
    export->store(filename, 'imports') → storage/app/imports/filename (disk 'imports')
         ↓
    Import update {status:'completed', file_path:filename, file_name:filename,
                   total:rowCount, processed:rowCount, success:rowCount, failed:0, errors:[]}
         ↓
    broadcastFileOperationTerminal(BRAND_EXPORT_COMPLETED, 'brand-export', id, completed, false, {progress:100, total:rowCount,...})
         ↓
    On Throwable → update failed; broadcast BRAND_EXPORT_FAILED; throw (failed() also guards processing→failed)
```

## Flow 9: Export Status Polling

```
Client → GET /api/v1/brands/export/58
         ↓
    [auth:sanctum] → [permission:export-brand|super_admin] → authorize('view')
         ↓
    Import::where(type=BRAND_EXPORT)->select([...])->findOrFail(58)
         ↓
    200 (Cache-Control: no-cache) { status:200, message:BRAND_EXPORT_STATUS_FETCHED,
      data:{ id, status, total_rows, processed_rows, successful_rows, failed_rows, errors, created_at, completed_at } }
```

## Flow 10: Download Export File

```
Client → GET /api/v1/brands/export/58/download
         ↓
    [auth:sanctum] → [permission:export-brand|super_admin] → authorize('view')
         ↓
    Import::where(type=BRAND_EXPORT)->select([id,status,file_path,file_name,created_by])->findOrFail(58)
         ↓
    status !== 'completed' || !file_path || !Storage::disk('imports')->exists(file_path)
      → 409 { status:409, message:EXPORT_NOT_READY, success:false }
         ↓
    response()->download(Storage::disk('imports')->path(file_path), file_name ?: basename(file_path),
                         Content-Type: application/vnd.openxmlformats...)
```

## Flow 11: Broadcast Wake-ups (Realtime)

```
Service/Job → BroadcastsFileOperationProgress trait
  → FileOperationEvent(userId, eventName, payload)
  → private channel users.{userId} (ShouldBroadcastNow, no queue)

Events:
  brand.import.progress  (import terminal: completed/completed_with_errors/failed/cancelled, plus cancel sync)
  brand.export.completed
  brand.export.failed

Client (Echo/Pusher) → on event → poll GET /brands/import/{id} or /brands/export/{id} for truth (imports table is source of truth; event is wake-up only)
```

## Signal File Lifecycle Summary

| Signal | Created | Updated | Deleted |
|--------|---------|---------|---------|
| `progress_{id}.json` | `BrandImportController@import` (0) | `BrandImportService` (ticks + finalize) | `ImportBrandsJob@handle` on success |
| `cancel_{id}.json` | `BrandImportController@cancel` | — | `ImportBrandsJob@handle` (on cancel detection) or `cleanSignals()` |

Progress file contains: `{processed_rows, success_rows, failed_rows, progress, ...}` plus counters used to override DB values while job runs.
