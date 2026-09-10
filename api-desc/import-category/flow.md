# Request Flows — Category Import / Export

## Flow 1: Queue Category Import (Admin)

```
Client → POST /api/v1/categories/import (multipart/form-data, file=categories.xlsx)
         ↓
    [auth:sanctum] middleware → authenticate token
         ↓
    [permission:import-category|super_admin] middleware → check Spatie permission
         ↓
    CategoryImportRequest → validation:
      - file: required, file, mimes:xlsx,xls,ods, max:20480
         ↓
    Fail? → 422 with field errors under { message, errors }
         ↓
    CategoryImportController@import()
         ↓
    Store file on 'public' disk → storage/app/public/imports/{uuid}.xlsx
         ↓
    estimateRowCount() → PhpSpreadsheet highest data row → total_rows
         ↓
    Import::create(type='category', status='pending', created_by=user.id)
         ↓
    writeSignalFile('progress') → { processed_rows:0, success_rows:0, failed_rows:0 }
         ↓
    ImportCategoriesJob::dispatch(import_id) → queued on 'meem-high'
         ↓
    Return 202: { status:202, message, success:true,
                  data: { import_id, status:'pending' } }
```

## Flow 2: Import Job Execution (Async, meem-high)

```
Worker → ImportCategoriesJob@handle(import_id)
         ↓
    Import::findOrFail
         ↓
    Cancelled? (cancel signal) → delete uploaded file, remove signal, return
         ↓
    Already terminal? → return
         ↓
    status → 'processing', reset processed/success/failed rows
         ↓
    countRows() → total_rows (reconcile)
         ↓
    $service = new CategoryImportService(import_id)
    $service->writeExplicitProgress(1.0)
         ↓
    Excel::import(CategoriesImport, file, XLSX|XLS|ODS by extension)
         ↓
    CategoriesImport@collection → $service->processRows($rows)
         ↓
    [1] prepareRows → load existing categories (by name_en + slug)
        per row: validate name_en/name_ar/duplicate/status/is_featured/image URLs
        download images to temp (SSRF-safe)      [errors → failedRows]
        writeExplicitProgress(10.0)
         ↓
    [2] upsertCategories → for each valid row:
        match name_en in existing → UPDATE category (name/details/status/is_featured)
        else slug = Str::slug(name_en) → if slug free → CREATE category
        slug conflict / ambiguous name / empty slug → failedRows
        writeExplicitProgress(60.0)
         ↓
    [3] assignParents → for each row:
        resolve parent_name_en → parent_id
        missing/ambiguous parent, self-parent, cycle → failedRows
        save parent_id → hierarchy service recalculates level
        writeExplicitProgress(80.0)
         ↓
    [4] attachImages → add temp images to categories-desktop/categories-mobile
        writeExplicitProgress(99.0)
         ↓
    finally → cleanupTempFiles (delete temp downloads)
         ↓
    $service->finalizeProgress() → flush counters to imports row
         ↓
    status = completed | completed_with_errors | failed
         ↓
    Update imports row { status, total_rows, processed_rows, success_rows,
                         failed_rows, errors }
         ↓
    Delete uploaded file from 'public' disk, remove progress signal
```

## Flow 3: Import Cancelled Mid-Processing (Async)

```
Worker → ImportCategoriesJob@handle(import_id)
         ↓
    ... processing ...
         ↓
    $service->processRows → isCancelled() → cancel signal exists
         ↓
    throw ImportCancelledException
         ↓
    catch → $service->rollbackCreatedData()
             → soft-delete categories created during this import
             → delete uploaded file, clean signals (cancel + progress)
             → update imports row → status 'cancelled'
```

## Flow 4: Import Status Polling

```
Client → GET /api/v1/categories/import/42
         ↓
    [auth:sanctum] → [permission:import-category|super_admin]
         ↓
    CategoryImportController@status(42)
         ↓
    Import::select([id, status, total_rows, processed_rows, success_rows,
                    failed_rows, errors, created_at, updated_at])->findOrFail(42)
         ↓
    effectiveStatus = cancel signal exists ? 'cancelling' : import.status
         ↓
    progress = 100.0 if completed/completed_with_errors
             = signal progress if processing/failed/cancelled
             = 0.0 otherwise
         ↓
    Return 200 (Cache-Control: no-cache):
      { status:200, message, success:true, data: {
          id, status, total_rows, processed_rows, successful_rows, failed_rows,
          progress, errors, error_count, created_at,
          completed_at: (terminal ? updated_at : null) } }
```

## Flow 5: Cancel Import

```
Client → POST /api/v1/categories/import/42/cancel
         ↓
    [auth:sanctum] → [permission:import-category|super_admin]
         ↓
    CategoryImportController@cancel(42)
         ↓
    Import::findOrFail(42)
         ↓
    Already terminal (completed/completed_with_errors/failed/cancelled)?
      ├─ Yes → 409 { status:409, message:'Import cannot be cancelled...', success:false }
      └─ No  → writeSignalFile('cancel')  → storage/app/imports/cancel_42.json
               ↓
            Import status → 'cancelled' (DB)
               ↓
            Return 200: { status:200, message, success:true,
                          data: { import_id:42, status:'cancelled' } }
```

## Flow 6: Download Import Errors

```
Client → GET /api/v1/categories/import/42/download-errors
         ↓
    [auth:sanctum] → [permission:import-category|super_admin]
         ↓
    CategoryImportController@downloadErrors(42)
         ↓
    Import::findOrFail(42)
         ↓
    errors empty?
      ├─ Yes → 404 { status:404, message:'No errors found', success:false }
      └─ No  → build xlsx (headings: Sheet, Row, Name (EN), Name (AR),
                            Parent Name (EN), Error Message)
               ↓
            Stream download 'failed_category_import_rows_42.xlsx'
            → delete file after send
```

## Flow 7: Download Sample Template

```
Client → GET /api/v1/categories/import/sample
         ↓
    [auth:sanctum] → [permission:import-category|super_admin]
         ↓
    CategoryImportController@downloadSample()
         ↓
    Binary download of
      packages/marvel/resources/categories/category-import-sample.xlsx
```

## Flow 8: Queue Category Export

```
Client → GET /api/v1/categories/export
         ↓
    [auth:sanctum] → [permission:export-category|super_admin]
         ↓
    CategoryExportRequest (no validation rules)
         ↓
    CategoryExportController@export()
         ↓
    Import::create(type='category-export', file_path='', file_name='',
                   status='pending', created_by=user.id)
         ↓
    ExportCategoriesJob::dispatch(import_id) → queued on 'meem-high'
         ↓
    Return 202: { status:202, message, success:true,
                  data: { export_id, status:'pending' } }
```

## Flow 9: Export Job Execution (Async, meem-high)

```
Worker → ExportCategoriesJob@handle(import_id)
         ↓
    Import::findOrFail
         ↓
    Already terminal? → return
         ↓
    status → 'processing', reset counters
         ↓
    new CategoriesExport() → load ALL categories
        → with('parent'), orderBy level asc, id asc
        → resolve parent_name_en + image URLs
         ↓
    rowCount = export->collection()->count()
         ↓
    store 'categories-export-{Y-m-d-His}.xlsx' on 'public' disk
         ↓
    Update imports row → status 'completed', file_path, file_name,
                         total_rows, processed_rows, success_rows, failed_rows:0, errors:[]
         ↓
    On Throwable → status 'failed'; rethrow
```

## Flow 10: Export Status Polling

```
Client → GET /api/v1/categories/export/58
         ↓
    [auth:sanctum] → [permission:export-category|super_admin]
         ↓
    CategoryExportController@status(58)
         ↓
    Return 200 (Cache-Control: no-cache):
      { status:200, message, success:true, data: {
          id, status, total_rows, processed_rows, successful_rows, failed_rows,
          errors, created_at,
          completed_at: (terminal ? updated_at : null) } }
```

## Flow 11: Download Export File

```
Client → GET /api/v1/categories/export/58/download
         ↓
    [auth:sanctum] → [permission:export-category|super_admin]
         ↓
    CategoryExportController@download(58)
         ↓
    Import::findOrFail(58)
         ↓
    status !== 'completed' OR file missing on 'public' disk?
      ├─ Yes → 409 { status:409, message:'Export file is not ready yet', success:false }
      └─ No  → Stream xlsx download (categories-export-*.xlsx)
```