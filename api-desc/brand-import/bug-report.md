# Bug Report — Brand Import / Export

---

## BUG-BRDIMP-001: Import `type` Drift — `brand` vs `brand-import`

**Severity:** High

**Component:** `BrandImportController@import` vs `BrandImportController@status|cancel|downloadErrors` + `ImportBrandsJob` + `ImportType`

**Description:** `BrandImportController@import` creates the tracking row with `type='brand'` (literal), but `status`/`cancel`/`downloadErrors` query `Import::where('type', ImportType::BRAND_IMPORT)` where `BRAND_IMPORT='brand-import'`. The row created by the controller is not found by the status endpoint unless the job or another writer has rewritten the `type`. The existing feature test sidesteps this by not asserting status after import (it only checks dispatch). Export uses `brand-export` consistently.

**Code Location:**
- `../../packages/marvel/src/Http/Controllers/BrandImportController.php` — `import()` line 78: `'type' => 'brand'`
- `../../packages/marvel/src/Http/Controllers/BrandImportController.php` — `status()` line 138, `cancel()` 201, `downloadErrors()` 239: `where('type', ImportType::BRAND_IMPORT)`
- `../../packages/marvel/src/Enums/ImportType.php` — `const BRAND_IMPORT = 'brand-import'`

**Current Behavior:**
```php
Import::create(['type' => 'brand', ...]);   // import()
Import::where('type', 'brand-import')->findOrFail($id); // status()/cancel()/downloadErrors()
```

**Impact:** A freshly queued brand import would return 404 on `GET /brands/import/{id}` until something mutates `type`. In production the job writes no `type` mutation, so the row remains `brand` and is unreachable via the status family.

**Recommendation:** Align `import()` to `ImportType::BRAND_IMPORT`:
```php
'type' => ImportType::BRAND_IMPORT,
```
and add a migration to normalize existing `brand` rows to `brand-import`. Tests should assert `GET /status` succeeds after `POST /import`.

---

## BUG-BRDIMP-002: Disk Mismatch — `imports` vs `public`

**Severity:** Medium

**Component:** `BrandImportController@import` + `estimateRowCount` + `ImportBrandsJob@handle` + `BrandExportController@download` + `BrandsExport@store`

**Description:** `BrandImportController@import` stores the upload on the `imports` disk (`$file->store('imports','imports')`), but `estimateRowCount` reads via `Storage::disk('public')->path($filePath)` and `ImportBrandsJob` deletes via `Storage::disk('public')->delete($filePath)` and `Storage::disk('public')->path()`. `BrandExportController@download` checks `Storage::disk('imports')->exists(file_path)` while `ExportBrandsJob` stores via `store(filename,'imports')`. If `imports` and `public` point to different roots (`../../storage/app/imports` vs `storage/app/public`), the job will not find the uploaded file and export downloads will mis-resolve.

**Code Location:**
- `BrandImportController.php` — `@import` store('imports','imports'), `estimateRowCount` disk('public'), `ImportBrandsJob.php` disk('public')
- `BrandExportController.php` — `download()` disk('imports'), `ExportBrandsJob.php` `store(...,'imports')`

**Impact:** `estimateRowCount` returns 0 and `ImportBrandsJob` silently processes an absent file (or throws) when disks diverge.

**Recommendation:** Normalize to `Storage::disk('imports')` for all brand import file I/O and `public` only where explicitly intended. Category import uses `public` consistently; brand should pick one and document it.

---

## BUG-BRDIMP-003: `BrandsSheetImport` Copy-Paste Drift — Wrong Service and Contract

**Severity:** Medium

**Component:** `../../packages/marvel/src/Imports/Sheets/BrandsSheetImport.php`

**Description:** The sheet class type-hints `ProductImportService` and implements a product-brand sync (`groupBy product_sku`, `pluck brand_slug`, `syncBrands(sku, slugs)`) — not brand import. The active import path is `BrandImportService` via `BrandsImport`. The sheet is dead code that would call the wrong service if wired.

**Code Location:** `../../packages/marvel/src/Imports/Sheets/BrandsSheetImport.php` lines 6–30

```php
use Marvel\Services\Import\ProductImportService;
// ...
protected ProductImportService $service;
```

**Impact:** Confusing for maintainers; if someone refactors `BrandsImport` to actually delegate per-row to the sheet, brands would be silently synced as product relations.

**Recommendation:** Fix to `BrandImportService` with brand-row contract (`WithHeadingRow` expecting `name_en` etc.), or remove the sheet and let `BrandsImport` delegate directly to `BrandImportService::processRows` (current effective path). Add a test that `BrandsSheetImport` class exists and is not referenced by the active job.

---

## BUG-BRDIMP-004: Route Ordering Fragility

**Severity:** Low

**Component:** `../../packages/marvel/src/Rest/Routes.php`

**Description:** Custom `brands/import/*` and `brands/export*` routes must precede `apiResource('brands')`; otherwise `GET /brands/export` is captured by `brands/{brand}` → `BrandController@show` and returns 404. The current file has a correct comment and ordering, but the guarantee is implicit.

**Code Location:** `Routes.php` lines 139–148

**Current Behavior:** Correct, but no test guards the ordering.

**Recommendation:** Keep the existing comment (`// Custom import/export routes MUST precede apiResource('brands')`) and add a regression test that `GET /brands/export` hits `BrandExportController@export` not `BrandController@show` (route matching test).

---

## BUG-BRDIMP-005: `estimateRowCount` Over-Counts Formatted Empty Rows

**Severity:** Low

**Component:** `BrandImportController@estimateRowCount` + `ImportBrandsJob@countRows`

**Description:** `getHighestDataRow()` returns the last row that ever held data or formatting, so `total_rows` can exceed actual brand rows. The job reconciles via `countRows()` and final `success+failed`, but early progress percentages are slightly off and `pending` polling shows inflated `total_rows`.

**Recommendation:** Accept as cosmetic; the job's reconciliation and final `total_rows = success+failed` are authoritative. Optionally subtract header row or use `ToCollection` row count instead of `getHighestDataRow`.

---

## BUG-BRDIMP-006: No Transaction on Brand Creation

**Severity:** Low

**Component:** `BrandImportService@upsertBrands` → `Brand::create` / `Brand::update`

**Description:** Brands are created one-by-one without a surrounding `DB::transaction`. A mid-file failure leaves a partial import (some brands persisted, some not). `ImportBrandsJob` does eventually mark `completed_with_errors`/`completed`, but there is no atomic rollback for non-cancellation failures. Cancellation does soft-delete `createdIds` via `rollbackCreatedData()`.

**Impact:** Partial imports are visible; not a correctness bug (row-level errors are expected), but callers may assume all-or-nothing.

**Recommendation:** Document as intended — brand import is **best-effort per row**, not atomic. If atomicity is required, wrap `upsertBrands` in a transaction and revise the `successCount`/`failedRows` contract.

---

## BUG-BRDIMP-007: Status Endpoint Exposes `errors` as Raw JSON with No Pagination

**Severity:** Low

**Component:** `BrandImportController@status` + `ExportBrandsJob`

**Description:** `errors` is the raw `imports.errors` JSON array (`sheet,row,name_en,name_ar,error_message`). For large files with many failures, the payload can be large and the client must know the internal shape. A dedicated error resource or paginated download would decouple the API from storage.

**Impact:** Large error arrays inflate the status response; `download-errors` already provides a paginated workbook alternative.

**Recommendation:** Keep status `errors` for convenience but document the shape and advise clients to use `GET /download-errors` for bulk errors. Consider truncating `errors` in status when `error_count` is high.

---

## BUG-BRDIMP-008: Sample Path Config Drift (`marvel.import.samples.brand` vs `marvel.import.samples`)

**Severity:** Low

**Component:** `../../config/marvel.php` + `BrandImportController@downloadSample`

**Description:** `../../config/marvel.php` nests the brand sample under `marvel.import.samples.brand` → `storage_path('packages/marvel/resources/brands/brand-import-sample.xlsx')`, while the controller reads `config('marvel.import.samples.brand')`. Some code paths or tests reference `config('marvel.import.samples')` (category) or `config('marvel.import.brand')` — a flat key vs nested key mismatch would yield `null`.

**Current Behavior:** Works because the current controller uses the nested key that matches the current config.

**Recommendation:** Standardize all import samples to `marvel.import.samples.{product,category,brand}` and add a test that `downloadSample` resolves to a real file.
