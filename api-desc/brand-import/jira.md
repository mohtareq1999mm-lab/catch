# Brand Import / Export — Jira Tasks

---

## Task 1: Fix Brand Import `type` Drift (`brand` → `brand-import`)

**Priority:** High
**Component:** `BrandImportController@import` + `ImportType` + migration
**Effort:** Small
**Files:**
- `../../packages/marvel/src/Http/Controllers/BrandImportController.php`
- `../../packages/marvel/src/Enums/ImportType.php`
- `database/migrations/xxxx_normalize_brand_import_type.php` (new)

**Description:** The controller writes `type='brand'` but status/cancel/downloadErrors query `type='brand-import'`. New imports are unreachable via status until the type is normalized.

**Acceptance Criteria:**
- [ ] `BrandImportController@import` uses `ImportType::BRAND_IMPORT`
- [ ] Migration converts existing `imports.type='brand'` rows to `brand-import`
- [ ] `POST /brands/import` → `GET /brands/import/{id}` returns 200 (not 404) in an integration test
- [ ] `../../docs/audits/IMPORT_EXPORT_E2E_AUDIT.md` discrepancy closed

---

## Task 2: Normalize Disk Usage — `imports` vs `public`

**Priority:** Medium
**Component:** `BrandImportController` + `ImportBrandsJob` + `ExportBrandsJob` + disks
**Effort:** Small
**Files:**
- `../../packages/marvel/src/Http/Controllers/BrandImportController.php` (`estimateRowCount`)
- `../../packages/marvel/src/Jobs/ImportBrandsJob.php`
- `../../packages/marvel/src/Http/Controllers/BrandExportController.php`
- `../../packages/marvel/src/Exports/BrandsExport.php`

**Description:** Import stores on `imports` but reads/deletes via `public`; export stores/loads via `imports`. The mismatch fails when disks have different roots.

**Acceptance Criteria:**
- [ ] All brand-import file I/O uses `Storage::disk('imports')` (or all use `public` — pick one and document)
- [ ] `estimateRowCount` and job `countRows` read from the same disk uploads are written to
- [ ] Export `store` and `download` use the same disk
- [ ] Regression test: upload → status → downloaded export file resolves correctly

---

## Task 3: Fix `BrandsSheetImport` — Correct Service and Contract

**Priority:** Medium
**Component:** `BrandsImport` / `BrandsSheetImport`
**Effort:** Small
**Files:**
- `../../packages/marvel/src/Imports/Sheets/BrandsSheetImport.php`
- `../../packages/marvel/src/Imports/BrandsImport.php`
- `../../packages/marvel/src/Jobs/ImportBrandsJob.php` (if wiring changes)

**Description:** The sheet currently imports `ProductImportService` and syncs `product_sku` → `brand_slug` (product import copy-paste). The active path bypasses it, but the dead code is misleading.

**Acceptance Criteria:**
- [ ] `BrandsSheetImport` type-hints `BrandImportService` (or is removed and `BrandsImport` documents that `BrandImportService::processRows` is the active entry)
- [ ] Sheet implements `ToCollection` + `WithTitle('brands')` + `WithHeadingRow` + `SkipsEmptyRows` with brand headings
- [ ] No reference to `product_sku` / `brand_slug`
- [ ] Test covers the corrected path (or asserts the sheet is unused by the active job)

---

## Task 4: Add Defensive Route-Ordering Test

**Priority:** Low
**Component:** Routes
**Effort:** Trivial
**Files:**
- `../../packages/marvel/src/Rest/Routes.php`
- `../../tests/Feature/Brands/BrandImportExportTest.php` (or new `BrandRoutesTest`)

**Description:** `brands/import/*` and `brands/export*` must precede `apiResource('brands')`.

**Acceptance Criteria:**
- [ ] Existing comment `// Custom import/export routes MUST precede apiResource('brands')` is present
- [ ] Test asserts `GET /brands/export` resolves to `BrandExportController@export` (not `BrandController@show`)
- [ ] Test asserts `GET /brands/import/sample` resolves to `BrandImportController@downloadSample`

---

## Task 5: Guard `download-errors` Headings and `status` Shape

**Priority:** Low
**Component:** `BrandImportController@downloadErrors` + `status`
**Effort:** Small
**Files:**
- `../../packages/marvel/src/Http/Controllers/BrandImportController.php`
- `../../tests/Feature/Brands/BrandImportExportTest.php`

**Description:** Ensure the error workbook always has the 5-column contract and the status payload uses `successful_rows` (not `success_rows`).

**Acceptance Criteria:**
- [ ] `downloadErrors` headings are `['Sheet','Row','Name (EN)','Name (AR)','Error Message']`
- [ ] `GET /brands/import/{id}` returns `successful_rows` (DB `success_rows` aliased)
- [ ] Tests assert both shapes

---

## Task 6: Extract Signal-File Helpers into Shared Trait

**Priority:** Low
**Component:** Import controllers + jobs + service
**Effort:** Small
**Files:**
- `../../packages/marvel/src/Http/Controllers/BrandImportController.php` (helpers)
- `../../packages/marvel/src/Http/Controllers/CategoryImportController.php` (duplicate helpers)
- `../../packages/marvel/src/Http/Controllers/ProductImportController.php`
- `app/Traits/ManagesImportSignals.php` (new) — or reuse `BroadcastsFileOperationProgress` signal helpers

**Description:** `readSignalFile` / `signalFileExists` / `writeSignalFile` / `removeSignalFile` / `cancelSignalFileExists` are duplicated across three import controllers and jobs.

**Acceptance Criteria:**
- [ ] Shared trait or service encapsulates signal file I/O and `clearstatcache`
- [ ] Controllers and jobs consume the trait; duplication removed
- [ ] No behavioral change (signal paths `storage/app/imports/{type}_{id}.json` preserved)

---

## Task 7: Add Live Image-Import Tests (SSRF / Size / Mime)

**Priority:** Medium
**Component:** `BrandImportService` + HTTP fake
**Effort:** Medium
**Files:**
- `../../packages/marvel/src/Services/Import/BrandImportService.php`
- `../../tests/Feature/Brands/BrandImportExportTest.php` (or `tests/Feature/ImportExport/BrandImageImportTest.php`)

**Description:** Image downloads are SSRF-guarded and non-fatal (brand still succeeds). These semantics need explicit coverage beyond the audit battery.

**Acceptance Criteria:**
- [ ] `Http::fake` for `200 image/jpeg` within 5 MB → brand succeeds with media attached
- [ ] `Http::fake` for private IP / redirect loop / oversize / SVG → brand still succeeds, no media, `report()` called, no `failedRows` increment for image
- [ ] Invalid URL format (`image_desktop_url` not a URL) → row fails with `INVALID_IMAGE_URL` (fatal)
- [ ] `isBlockedIp` covers `127.0.0.1`, `10/8`, `192.168/16`, `100.64/10` (cgNAT), `169.254/16`, `224/4` (multicast)
