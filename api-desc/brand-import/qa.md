# Brand Import / Export — QA Test Cases

## Test Files

| File | Lines | Focus |
|------|-------|-------|
| `../../tests/Feature/Brands/BrandImportExportTest.php` | 163 | Core brand import/export behavioral regression — async dispatch, queue, permissions, sample |

Additional live battery referenced in audits: `../../docs/audits/IMPORT_EXPORT_E2E_AUDIT.md` § Brand Import (18 IE-BRD-* checks) — sample structure, permission matrix, async lifecycle.

## QA Coverage from BrandImportExportTest

### Sample Download

| # | Test | Type | Assertion |
|---|------|------|-----------|
| 1 | `sample_downloads_valid_xlsx_with_contract_headers` | Feature | `GET /brands/import/sample` → 200, `Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`, contract headers (`name_en` etc.) present |

### Import Dispatch

| # | Test | Type | Assertion |
|---|------|------|-----------|
| 2 | `import_dispatches_job_on_meem_high_queue_and_returns_202` (actual queue `meem-medium`) | Feature | `POST /brands/import` with `File::createWithContent` from real sample → 202, `success:true`, `Queue::assertPushed(ImportBrandsJob)`, `assertPushedOn('meem-medium')` |

### Export Dispatch

| # | Test | Type | Assertion |
|---|------|------|-----------|
| 3 | `export_dispatches_job_and_returns_202_with_export_id` (inferred from junit) | Feature | `GET /brands/export` → 202, `export_id` + `status:pending`, `Queue::assertPushed(ExportBrandsJob)`, `on('meem-medium')` |

### Permission / Queue Variants

| # | Test | Type | Assertion |
|---|------|------|-----------|
| 4 | `import_requires_permission` (via permission matrix checks) | Authorization | Token without `import-brand` → 403 |
| 5 | `export_requires_permission` | Authorization | Token without `export-brand` → 403 |
| 6 | `cancel_on_terminal_import_returns_409` | Feature | Cancel on `completed`/`failed`/`cancelled` → 409 |

## Manual QA Checklist (recommended, not yet automated)

### Functional

- [ ] Upload `.xlsx` with 1 valid brand → import completes → brand appears in `GET /brands` with correct `name.en/ar`, `details.en/ar`, `status`, `slug=Str::slug(name_en)`
- [ ] Upload same `name_en` with changed `details/status` → existing brand is **updated** (id stable, slug preserved if name unchanged)
- [ ] Upload with two rows sharing `name_en` → second row fails with `DUPLICATE_ROW`, first succeeds; `errors` array contains row 3 error, `failed_rows=1`
- [ ] Upload with `name_en` mapping to existing slug of another brand → `SLUG_CONFLICT`
- [ ] Upload with empty `name_en` / `name_ar` → `NAME_EN_REQUIRED` / `NAME_AR_REQUIRED`
- [ ] `status` values `yes/no/true/false/on/off/1/0/empty` → normalized to `1`/`0`; invalid value → `INVALID_STATUS`
- [ ] `image_desktop_url` / `image_mobile_url` invalid URL format → `INVALID_IMAGE_URL` (row fails)
- [ ] `image_*_url` unreachable / SSRF / oversize / bad mime → brand still succeeds (image skipped, reported), `success_rows` increments
- [ ] Download errors → `failed_brand_import_rows_{id}.xlsx` with 5 columns `Sheet, Row, Name (EN), Name (AR), Error Message`
- [ ] Download errors when `errors` empty → 404 `IMPORT_NO_ERRORS`
- [ ] Sample download → `brand-import-sample.xlsx` with 7 headers in order, sheet `brands`
- [ ] `GET /brands/import/{id}` polling → `progress` 0→100, `successful_rows` vs `success_rows` naming, `completed_at` null until terminal
- [ ] Cancel `pending` → 200 `cancelled`; subsequent poll shows `cancelling` transient then `cancelled`; created brands are soft-deleted
- [ ] Cancel terminal → 409 `IMPORT_CANNOT_CANCEL`
- [ ] Export `GET /brands/export` → 202 + export row; `GET /export/{id}` polls to `completed`; `GET /download` → `.xlsx` with same 7 headings; download before `completed` → 409 `EXPORT_NOT_READY`
- [ ] Export content round-trip → exported file edited and re-imported succeeds
- [ ] Over-large file (>20 MB) → 422 `FILE_MAX`; wrong mime (`.txt`) → 422 `FILE_MIMES`; missing file → 422 `FILE_REQUIRED`

### Security

- [ ] Unauthenticated request → 401
- [ ] User with only `CREATE_BRAND` but not `import-brand` → 403 on import endpoints
- [ ] `ImportPolicy@view` enforcement: user B cannot fetch/cancel/download errors for user A's import id → 403
- [ ] SSRF payloads (`http://127.0.0.1`, `http://169.254.169.254`, `file:///etc/passwd`) → blocked, brand still succeeds without image, logged
- [ ] Redirect loop (>5) → `TOO_MANY_REDIRECTS` (non-fatal)
- [ ] Oversized image (>5 MB) / unsupported mime (svg/webp) → `IMAGE_TOO_LARGE` / `UNSUPPORTED_IMAGE_TYPE` (non-fatal)

### Edge Cases

- [ ] Empty workbook (header only) → `total_rows≈1`, `completed` with `success=0` → `failed` status (job maps `success==0` to `failed`)
- [ ] `getHighestDataRow` over-count (formatted empty rows) → early `total_rows` inflated, reconciled by job `countRows()`
- [ ] Duplicate slug via different `name_en` that slugifies identically (e.g., `Acme Inc` vs `Acme-Inc`) → second row `SLUG_CONFLICT`
- [ ] `Str::slug('***')` → empty slug → `INVALID_SLUG`
- [ ] Concurrent import and export — no DB lock contention; both on `meem-medium` (queue isolation, not DB)
- [ ] Job retry (Throwable before terminal) → first attempt appends `Attempt N:` error, rethrows, second attempt may succeed

## Missing Coverage (recommended tests — see `test-cases.md` and `jira.md`)

- [ ] **Validation:** `file.mimes` with `.ods`, `.xls`; `file.max` boundary (20480 KB exact)
- [ ] **Authorization:** `ImportPolicy` owner vs `super_admin` override matrix on every sub-endpoint
- [ ] **Status field rename:** Assert API returns `successful_rows` not `success_rows`
- [ ] **Signal file precedence:** While `processing`, `progress/success/failed` come from signal, not DB
- [ ] **Image non-fatal guarantee:** Row with bad image still increments `successCount`
- [ ] **Rollback:** Cancelled import soft-deletes only `createdIds`, leaves updated brands intact
- [ ] **Export disk:** Assert exported file lives on `imports` disk and is downloadable; missing file → 409
- [ ] **BrandsSheetImport legacy:** Verify active path is `BrandImportService` (not `ProductImportService` grouping)
