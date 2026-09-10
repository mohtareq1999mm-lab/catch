# Category Import / Export — Changelog

## [1.0.0] — 2026-08-18

### Added
- Comprehensive API investigation documentation (`api-desc/import-category/`)
- Category Excel **Import** API (async):
  - `POST /api/v1/categories/import` — queue import, returns `import_id`
  - `GET /api/v1/categories/import/{id}` — status/progress polling
  - `POST /api/v1/categories/import/{id}/cancel` — cancel with rollback of created categories
  - `GET /api/v1/categories/import/{id}/download-errors` — failed rows as `.xlsx`
  - `GET /api/v1/categories/import/sample` — official template download
- Category Excel **Export** API (async):
  - `GET /api/v1/categories/export` — queue export, returns `export_id`
  - `GET /api/v1/categories/export/{id}` — status polling
  - `GET /api/v1/categories/export/{id}/download` — `.xlsx` download
- `ImportCategoriesJob` / `ExportCategoriesJob` on the **`meem-high`** queue
- `CategoryImportService` with:
  - Identity by normalized English name (update existing / create new)
  - Deterministic slug via `Str::slug()` (never `globalSlugify()`, never random)
  - Row-order-independent, multi-level parent resolution by English name with cycle detection
  - SSRF-safe image downloads (private/loopback/link-local/reserved/metadata IP blocks, per-redirect validation, 5 MB limit, JPEG/PNG/GIF only, SVG rejected)
- Signal-file progress + cancellation mechanism (`storage/app/imports/progress_{id}.json`, `cancel_{id}.json`)
- Permissions: `import-category`, `export-category` (`super_admin` bypass)
- Sample template: `packages/marvel/resources/categories/category-import-sample.xlsx`
- Translations (en/ar) for all import/export messages and row-level errors
- Tests: `CategoryImportTest` (21), `CategoryExportTest` (9)

### Known
- `GET /categories/export` does **not** support filters — it always exports all categories (see `BUG-CATIMP-001`)
- Export status response has no filter snapshot (see `BUG-CATIMP-002` / jira Task 3)
- `download-errors` uses 404 for the "no errors" case (intentional, `BUG-CATIMP-003`)
- `total_rows` is estimated via `getHighestDataRow()` and may be corrected by the job (see `BUG-CATIMP-004`)