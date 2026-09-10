# Bug Report — Category Import / Export

---

## BUG-CATIMP-001: Export Ignores Requested Filter Parameters

**Severity:** Medium

**Component:** `CategoryExportController@export` + `ExportCategoriesJob` + `CategoriesExport`

**Description:** The documented product requirement expects `GET /categories/export` to support `status`, `is_featured`, `parent`, and `search` filters. The current implementation accepts no parameters: `CategoryExportRequest` has empty rules, the job constructs `new CategoriesExport()` with no arguments, and `loadCategories()` always exports every category ordered by level then id.

**Code Location:**
- `packages/marvel/src/Http/Requests/CategoryExportRequest.php`
- `packages/marvel/src/Jobs/ExportCategoriesJob.php`
- `packages/marvel/src/Exports/CategoriesExport.php`

**Current Behavior:**
```php
// CategoryExportRequest rules() → []
// ExportCategoriesJob:
$export = new CategoriesExport();
// CategoriesExport::loadCategories():
Category::query()->with(['parent:id,name'])->select([...])->orderBy('level')->orderBy('id')->get();
```

**Impact:** Functional for full exports, but users cannot export subsets. Frontend filter UI would silently be ignored.

**Recommendation:** Accept validated filter query parameters, serialize them into `ExportCategoriesJob`, and apply them inside `loadCategories()`. See jira Task 1.

---

## BUG-CATIMP-002: Status Endpoint Exposes `errors` as Raw JSON Array

**Severity:** Low

**Component:** `CategoryImportController@status` / `CategoryExportController@status`

**Description:** The status payload includes `errors` as the raw stored JSON array (each item contains `sheet`, `row`, `name_en`, `name_ar`, `parent_name_en`, `error_message`). Clients must know the internal shape. A dedicated error resource would decouple the API from storage internals.

**Code Location:** `packages/marvel/src/Http/Controllers/CategoryImportController.php` — `status()`

**Impact:** Low — shape is stable but undocumented at the resource level.

---

## BUG-CATIMP-003: `download-errors` Returns 404 for Empty Errors

**Severity:** Low

**Component:** `CategoryImportController@downloadErrors`

**Description:** When an import has no recorded errors, `GET /categories/import/{id}/download-errors` returns 404 with message "No errors found". A 404 is semantically about a missing resource; a 200 with an empty workbook (or a 409) may be clearer. Matches the current `{ status, message, success }` convention, so this is intentional behavior, but worth confirming with the client team.

**Code Location:** `packages/marvel/src/Http/Controllers/CategoryImportController.php` — `downloadErrors()`

---

## BUG-CATIMP-004: Import Estimate Counts Blank Trailing Rows

**Severity:** Low

**Component:** `CategoryImportController@estimateRowCount`

**Description:** `total_rows` is estimated via `getHighestDataRow()` on each sheet. `getHighestDataRow()` returns the last row that ever contained data (including formatting), so `total_rows` can exceed the actual number of category rows and be corrected later by `ImportCategoriesJob::countRows()` / the final row counts. Progress percentages may appear slightly off early on.

**Code Location:** `packages/marvel/src/Http/Controllers/CategoryImportController.php` — `estimateRowCount()`

**Impact:** Low — cosmetic; the job reconciles `total_rows` before processing.

---

## BUG-CATIMP-005: Row-Order Dependency on Image Downloads in `prepareRow`

**Severity:** Low

**Component:** `CategoryImportService::prepareRow`

**Description:** Image downloads happen in `prepareRow`, before duplicate `name_en` detection completes in `upsertCategories`. Two rows with the same `name_en` both download images; the duplicate row's temp image is downloaded then discarded. Wasteful for large files with duplicates, but harmless functionally.

**Code Location:** `packages/marvel/src/Services/Import/CategoryImportService.php` — `prepareRow()`

**Impact:** Low — extra bandwidth for duplicate rows only.

---

## BUG-CATIMP-006: No Guard Against Downloading Another User's Export

**Severity:** Low

**Component:** `CategoryExportController@download`

**Description:** The download/status/cancel endpoints are protected by `auth:sanctum` + permission, but any user holding `import-category` / `export-category` can fetch status or download a file created by another user (the `imports` row is found purely by id). If multi-tenant isolation is required, add a `created_by` ownership check.

**Code Location:** `packages/marvel/src/Http/Controllers/CategoryExportController.php` — `download()` / `status()`

**Impact:** Low in a single-admin deployment; medium if staff roles share the panel.