# Category Import / Export — Jira Tasks

---

## Task 1: Add Filter Support to Category Export

**Priority:** High
**Component:** Export
**Effort:** Medium
**Files:**
- `packages/marvel/src/Http/Controllers/CategoryExportController.php`
- `packages/marvel/src/Http/Requests/CategoryExportRequest.php`
- `packages/marvel/src/Jobs/ExportCategoriesJob.php`
- `packages/marvel/src/Exports/CategoriesExport.php`

**Description:** The export currently exports **all** categories (`CategoriesExport` loads every category ordered by level then id; `CategoryExportRequest` has no rules; the job passes no filters). The documented product requirement expects `status`, `is_featured`, `parent`, and `search` filters. Filter parameters must be accepted on `GET /categories/export`, serialized into the job, and applied in `CategoriesExport::loadCategories()`.

**Acceptance Criteria:**
- [ ] `CategoryExportRequest` validates optional `status`, `is_featured`, `parent`, `search` query parameters
- [ ] `ExportCategoriesJob` carries the filter values (queued payload)
- [ ] `CategoriesExport` applies the filters in `loadCategories()`
- [ ] Filters are optional; omitting them exports all categories

---

## Task 2: Extract Signal-File Helpers Into a Shared Trait

**Priority:** Medium
**Component:** Import / Export Controllers
**Effort:** Small
**Files:**
- `packages/marvel/src/Http/Controllers/CategoryImportController.php`
- `packages/marvel/src/Http/Controllers/CategoryExportController.php`
- `packages/marvel/src/Http/Controllers/ProductImportController.php`

**Description:** `readSignalFile`, `signalFileExists`, and `writeSignalFile` are duplicated across the import/export controllers. Extract into a shared trait (e.g., `Marvel\Traits\ImportSignalFile`) to keep the code DRY.

**Acceptance Criteria:**
- [ ] Shared trait created
- [ ] CategoryImportController / CategoryExportController use it
- [ ] Behavior unchanged (tests still pass)

---

## Task 3: Persist Filter Inputs on the Export `imports` Row

**Priority:** Medium
**Component:** Export
**Effort:** Small
**Files:**
- `packages/marvel/src/Http/Controllers/CategoryExportController.php`

**Description:** The export `imports` row stores no record of which filters produced the file. Store the filter snapshot (e.g., in `errors` JSON or a new column) so the status/download response can echo what was exported.

**Acceptance Criteria:**
- [ ] Export row records the applied filters
- [ ] Status response exposes the stored filters
- [ ] Existing status endpoint response remains backward compatible

---

## Task 4: Return `file_name` / URL in Export Status

**Priority:** Low
**Component:** Export Status
**Effort:** Small
**Files:**
- `packages/marvel/src/Http/Controllers/CategoryExportController.php`

**Description:** The export status endpoint returns no file information. Exposing the generated `file_name` (when completed) lets the frontend show the download filename without issuing a HEAD request.

**Acceptance Criteria:**
- [ ] `file_name` included in the status payload when completed
- [ ] Null / omitted when not ready

---

## Task 5: Add Live HTTP Image Import Tests

**Priority:** Medium
**Component:** Import / Tests
**Effort:** Medium
**Files:**
- `tests/Feature/Categories/CategoryImportTest.php`

**Description:** The image import path (SSRF block, MIME rejection, 5 MB limit, redirect handling) is untested with a live endpoint. Add tests using an HTTP server / faked HTTP responses covering unsafe IPs, SVG rejection, oversized downloads, and successful JPEG/PNG/GIF downloads.

**Acceptance Criteria:**
- [ ] Test: unsafe URL (private IP) → row error
- [ ] Test: SVG payload → row error (unsupported type)
- [ ] Test: > 5 MB payload → row error (too large)
- [ ] Test: valid JPEG/PNG/GIF → media attached