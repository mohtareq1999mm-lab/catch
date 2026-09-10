# Test Coverage — Brand Import / Export

---

## Test Files

| File | Lines | Focus |
|------|-------|-------|
| `../../tests/Feature/Brands/BrandImportExportTest.php` | 163 | Core brand import/export behavioral regression |
| Live battery (audit) | — | 18 IE-BRD-* checks referenced in `../../docs/audits/IMPORT_EXPORT_E2E_AUDIT.md` |

---

## BrandImportExportTest.php Coverage

### Sample

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 1 | `sample_downloads_valid_xlsx_with_contract_headers` | Feature | `GET /brands/import/sample` → 200, correct content-type, contract headers present |

### Import Dispatch

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 2 | `import_dispatches_job_on_meem_high_queue_and_returns_202` | Feature | `POST /brands/import` with real sample file → 202, `success:true`, `Queue::assertPushed(ImportBrandsJob)`, `assertPushedOn('meem-medium')` |

### Export Dispatch

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 3 | `export_dispatches_job_and_returns_202_with_export_id` | Feature | `GET /brands/export` → 202, `export_id` + `status:pending`, `Queue::assertPushed(ExportBrandsJob)` on `meem-medium` |

### Authorization / Terminal Guards

| # | Test Name | Type | Description |
|---|-----------|------|-------------|
| 4 | `import_requires_permission` (permission matrix) | Authorization | Token lacking `import-brand` → 403 |
| 5 | `export_requires_permission` | Authorization | Token lacking `export-brand` → 403 |
| 6 | `cancel_on_terminal_import_returns_409` | Feature | Cancel on `completed`/`failed`/`cancelled` → 409 |

Note: the file uses `DatabaseTransactions` + `CreatesTestTables` and seeds permissions `import-brand`/`export-brand`/`super_admin` directly, ensuring the suite runs without full seeder.

---

## Live Battery (IE-BRD-* — 18 checks)

Referenced in `../../docs/audits/IMPORT_EXPORT_E2E_AUDIT.md` §§22–31 and `docs/audits/import-export-master-todo.md`. Covers sample XLSX structure (7-column contract), permission matrix, async lifecycle (202 → processing → completed), error workbook shape, image handling (valid/invalid/SSRF), identity/slug/duplicate handling, cancellation, and export round-trip. These checks are the intended integration suite; the feature test above is the minimal CI gate.

---

## BrandImportService Coverage (manual — not in feature test)

| # | Scenario | Expected |
|---|----------|----------|
| 7 | Valid row creates brand | `Brand::create` with `slug=Str::slug(name_en)` |
| 8 | Existing `name_en` updates brand | `brand.update` with new translations/status, slug preserved |
| 9 | Duplicate `name_en` in file | Second row `DUPLICATE_ROW` error, `failedRows++` |
| 10 | `status` boolean parsing (yes/no/true/false/on/off/1/0) | Normalized correctly, invalid → `INVALID_STATUS` |
| 11 | Image SSRF (private IP, loopback) | Blocked, brand still succeeds, no media attached |
| 12 | Image too large / bad mime / SVG | Blocked, brand still succeeds |
| 13 | Slug conflict (existing or intra-file) | `SLUG_CONFLICT` |
| 14 | Ambiguous DB name (>1 brand with same `name_en`) | `AMBIGUOUS_NAME` |
| 15 | Cancel signal mid-processing | `ImportCancelledException` → rollback |

---

## Coverage Summary

| Category | Count |
|----------|-------|
| Feature (success path) | 3 |
| Authorization / Guard | 3 |
| Manual service scenarios (not in CI) | ~9 |
| Live battery (audit) | 18 |
| **Total** | ~33 |

---

## Missing Tests (Recommended)

- [ ] **Validation:** `POST /brands/import` with no file → 422 `FILE_REQUIRED`; `.pdf` → 422 `FILE_MIMES`; file exactly 20480 KB vs 20481 KB
- [ ] **.xls / .ods path:** Upload `.xls` and `.ods` samples → same 202 + correct `readerType`
- [ ] **ImportPolicy:** User B cannot `GET /brands/import/{id}` for user A's import → 403 (and `super_admin` can)
- [ ] **Status shape:** Assert `successful_rows` (not `success_rows`), `error_count`, `progress` 0/100, `completed_at` null vs ISO8601
- [ ] **Signal precedence:** While `processing`, polling reflects signal `processed_rows` overriding DB
- [ ] **Image non-fatal:** Row with bad image URL still counts as `successCount++` and media is absent
- [ ] **Download errors:** `GET /download-errors` when errors present → `.xlsx` with 5 headings; when empty → 404 `IMPORT_NO_ERRORS`
- [ ] **Cancellation rollback:** Create 2 brands then cancel → 2 soft-deleted rows exist with `deleted_at`
- [ ] **Export:** `GET /brands/export` creates row on `meem-medium`; `GET /export/{id}/download` before `completed` → 409; after `completed` → 200 with identical headings
- [ ] **Sample not found:** Remove sample file then `GET /brands/import/sample` → 404 `IMPORT.SAMPLE_NOT_FOUND`
- [ ] **Job retry:** Simulate `Throwable` on first attempt (attempts < tries) → errors appended `Attempt N:` and job is retried; on final attempt → `failed` + broadcast
- [ ] **BrandsSheetImport drift:** Assert `BrandsSheetImport` is not the active path (or fix to `BrandImportService`)
- [ ] **Disk mismatch:** Verify controller `store('imports','imports')` and job `Storage::disk('public')->delete` are reconciled (currently different disks)
