# Brand Import / Export — Changelog

## [1.0.0] — 2026-08-26

### Added
- Brand Excel Import/Export feature: async bulk brand creation/update via Excel (7-column contract).
- `BrandImportController` — `import`, `downloadSample`, `status`, `cancel`, `downloadErrors` (8 endpoints total with export family).
- `BrandExportController` — `export`, `status`, `download`.
- `BrandImportRequest` — `file: required|file|mimes:xlsx,xls,ods|max:20480`.
- `BrandImportService` — SSRF-safe image download, deterministic `Str::slug(name_en)` identity, slug-conflict/ambiguous-name guards, progress ticks, rollback on cancel.
- `BrandsImport` (`WithMultipleSheets`) + `BrandsSheetImport` (sheet `brands`, `WithHeadingRow`, `SkipsEmptyRows`).
- `BrandsExport` (`FromCollection`, `WithHeadings`, `WithTitle`) — `id-asc`, 7-column mirror of import, image URLs from `brands-desktop`/`brands-mobile`.
- `ImportBrandsJob` (`meem-medium`, tries 3, timeout 1500, backoff [60,120,240]) and `ExportBrandsJob` (`meem-medium`, tries 2, timeout 600).
- `ImportType::BRAND_IMPORT='brand-import'`, `BRAND_EXPORT='brand-export'`; `Permission::IMPORT_BRAND`, `EXPORT_BRAND`.
- Sample file `../../packages/marvel/resources/brands/brand-import-sample.xlsx` + `config/marvel.php` `marvel.import.samples.brand`.
- `FileOperationEvent` constants `BRAND_IMPORT_PROGRESS`, `BRAND_EXPORT_COMPLETED/FAILED` + `BroadcastsFileOperationProgress` trait (realtime wake-ups on `private:users.{id}`).
- Signal files `storage/app/imports/progress_{id}.json` / `cancel_{id}.json`.
- Routes in `../../packages/marvel/src/Rest/Routes.php` (8 routes) ordered before `apiResource('brands')` to avoid `brands/{brand}` capture.
- Feature test `../../tests/Feature/Brands/BrandImportExportTest.php` (sample, import dispatch on `meem-medium` 202, export dispatch 202, terminal cancel 409).

### Changed
- `../../packages/marvel/src/Rest/Routes.php` — added custom brand import/export routes before `apiResource('brands')` with `whereNumber('id')` constraints.
- `../../docs/audits/IMPORT_EXPORT_E2E_AUDIT.md`, `docs/architecture/realtime-file-operations.md`, `docs/PROJECT-CHANGELOG-AND-API-IMPACT.md` — documented brand import/export as async `meem-medium` operations.

### Known Issues

1. **Import `type` drift** — `BrandImportController@import` writes `type='brand'` while status family queries `ImportType::BRAND_IMPORT='brand-import'` (see BUG-BRDIMP-001). Fix: align to `ImportType::BRAND_IMPORT` and normalize existing rows.
2. **Disk mismatch** — `BrandImportController@import` stores on `imports` disk but `estimateRowCount`/`ImportBrandsJob` read/delete via `public` (BUG-BRDIMP-002).
3. **`BrandsSheetImport` copy-paste** — sheet type-hints `ProductImportService` and groups by `product_sku`/`brand_slug` (BUG-BRDIMP-003); not the active path.
4. **`getHighestDataRow` over-count** — formatted empty rows inflate early `total_rows`; reconciled later (BUG-BRDIMP-005).
5. **No transaction** — per-row best-effort persistence; partial imports are visible (BUG-BRDIMP-006).
