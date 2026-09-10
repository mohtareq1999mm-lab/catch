# Brand Import / Export Module — Dashboard

## Overview

The Brand Excel Import/Export module lets administrators bulk import and export brands through Excel files. Both flows are fully asynchronous: the HTTP request creates a tracking row in the `imports` table and dispatches a queued job on the `meem-medium` queue. Progress is shared through JSON signal files under `../../storage/app/imports` (`progress_{id}.json`, `cancel_{id}.json`) plus the `imports` table itself.

Import identity is the normalized English name (`name_en`). A matching `name_en` updates the existing brand in place; a new `name_en` creates a brand with a deterministic `Str::slug(name_en)`. Image URLs are downloaded with SSRF protection into Spatie media collections (`brands-desktop`, `brands-mobile`).

The module is Marvel-owned but exposed through the admin `auth:sanctum` + `throttle:admin` route group. All 8 endpoints are admin-only.

## Key Files

| Layer | File |
|-------|------|
| Import Controller | `../../packages/marvel/src/Http/Controllers/BrandImportController.php` |
| Export Controller | `../../packages/marvel/src/Http/Controllers/BrandExportController.php` |
| Import Request | `../../packages/marvel/src/Http/Requests/BrandImportRequest.php` |
| Import Service | `../../packages/marvel/src/Services/Import/BrandImportService.php` |
| Excel Import | `../../packages/marvel/src/Imports/BrandsImport.php` |
| Sheet Import | `../../packages/marvel/src/Imports/Sheets/BrandsSheetImport.php` |
| Excel Export | `../../packages/marvel/src/Exports/BrandsExport.php` |
| Import Job | `../../packages/marvel/src/Jobs/ImportBrandsJob.php` |
| Export Job | `../../packages/marvel/src/Jobs/ExportBrandsJob.php` |
| Import Model | `../../packages/marvel/src/Database/Models/Import.php` |
| Brand Model | `../../packages/marvel/src/Database/Models/Brand.php` |
| Routes | `../../packages/marvel/src/Rest/Routes.php` (lines 139-146) |
| Permissions | `../../packages/marvel/src/Enums/Permission.php` (`IMPORT_BRAND`, `EXPORT_BRAND`) |
| ImportType Enum | `../../packages/marvel/src/Enums/ImportType.php` (`brand-import`, `brand-export`) |
| Sample File | `../../packages/marvel/resources/brands/brand-import-sample.xlsx` |
| Config | `../../config/marvel.php` (`marvel.import.samples.brand`) |
| Events | `../../app/Events/FileOperationEvent.php` (`BRAND_IMPORT_PROGRESS`, `BRAND_EXPORT_*`) |
| Broadcast Trait | `../../app/Traits/BroadcastsFileOperationProgress.php` |
| Tests | `../../tests/Feature/Brands/BrandImportExportTest.php` |

## Dependencies

- **Maatwebsite Excel** (`WithMultipleSheets`, `WithHeadingRow`, `SkipsEmptyRows`, `FromCollection`) — Excel I/O
- **PhpSpreadsheet** — row counting (`getHighestDataRow`)
- **Spatie Translatable** (`HasTranslations`) — bilingual `name`/`details` on `brands`
- **Spatie Media Library** (`InteractsWithMedia`) — `brands-desktop` / `brands-mobile` collections, disk `brands`
- **Laravel Queue** — async jobs on `meem-medium`
- **Spatie Permission** — `import-brand` / `export-brand` / `super_admin`
- **HTTP Client** (`Illuminate\Support\Facades\Http`) — SSRF-safe image downloads

## Permissions

| Permission | Value | Required For |
|------------|-------|--------------|
| `IMPORT_BRAND` | `import-brand` | `POST /brands/import`, `GET /brands/import/*` (sample, status, cancel, download-errors) |
| `EXPORT_BRAND` | `export-brand` | `GET /brands/export*` |
| `SUPER_ADMIN` | `super_admin` | Bypasses both checks (OR) |

Controller constructor: `$this->middleware('permission:' . Permission::IMPORT_BRAND . '|' . Permission::SUPER_ADMIN)` (import) and `EXPORT_BRAND` (export). Route group adds `auth:sanctum` + `throttle:admin`.

## Routes

### Admin (`/api/v1/brands`) — `../../packages/marvel/src/Rest/Routes.php`

| Method | URL | Name | Auth | Purpose |
|--------|-----|------|------|---------|
| POST | `/api/v1/brands/import` | `admin.brands.import` | sanctum + `import-brand` | Queue Excel import |
| GET | `/api/v1/brands/import/sample` | `admin.brands.import.sample` | sanctum + `import-brand` | Download template |
| GET | `/api/v1/brands/import/{id}` | `admin.brands.import.status` | sanctum + `import-brand` | Poll import progress |
| POST | `/api/v1/brands/import/{id}/cancel` | `admin.brands.import.cancel` | sanctum + `import-brand` | Cancel import |
| GET | `/api/v1/brands/import/{id}/download-errors` | `admin.brands.import.download-errors` | sanctum + `import-brand` | Download error report |
| GET | `/api/v1/brands/export` | `admin.brands.export` | sanctum + `export-brand` | Queue Excel export |
| GET | `/api/v1/brands/export/{id}` | `admin.brands.export.status` | sanctum + `export-brand` | Poll export status |
| GET | `/api/v1/brands/export/{id}/download` | `admin.brands.export.download` | sanctum + `export-brand` | Download export file |

> All `brands/import/*` and `brands/export*` routes are declared **before** `Route::apiResource('brands', ...)` so static segments are not captured by `brands/{brand}`.

## Excel Format

### Import template columns (heading row 1, data from row 2)

| Column | Required | Description |
|--------|----------|-------------|
| `name_en` | Yes | English name — **import identity** (normalized: trim + collapse whitespace) |
| `name_ar` | Yes | Arabic name |
| `details_en` | No | English details |
| `details_ar` | No | Arabic details |
| `status` | No | `1`/`0` (also `true/false/yes/no/on/off`; empty → `1`) |
| `image_desktop_url` | No | Public URL of the desktop image |
| `image_mobile_url` | No | Public URL of the mobile image |

> The file MUST NOT contain `id` or `slug`. Slug is always derived by the backend via `Str::slug(name_en)`.

Sheet title expected by `BrandsImport`: `brands`.

### Export columns

Identical 7 columns in the same order: `name_en`, `name_ar`, `details_en`, `details_ar`, `status`, `image_desktop_url`, `image_mobile_url`. Export is ordered by `brands.id` asc. Image URLs are resolved from `brands-desktop` / `brands-mobile` media collections. The exported file can be edited and re-imported.

## Import Identity

1. Normalize `name_en` (`trim` + `preg_replace('/\s+/', ' ', ...)`).
2. Look up existing brands by normalized name (via `loadExistingBrands()`).
3. **0 matches** → `Str::slug(name_en)` → if slug unused → `Brand::create(...)` (is_new).
4. **1 match** → `updateSlugIsSafe()` check → `brand->update(...)` (slug preserved if still equivalent, else conflict).
5. **>1 match (duplicate English name in DB)** → row fails with `AMBIGUOUS_NAME`.
6. Slug conflict (`dbBySlug` or `createdSlugs` already holds the slug) → row fails with `SLUG_CONFLICT`.
7. Empty slug (`Str::slug` returns `''`) → row fails with `INVALID_SLUG`.

## Async Behavior

- **Queue:** `meem-medium` (both jobs). `ImportBrandsJob` tries 3 / timeout 1500s / backoff [60,120,240]; `ExportBrandsJob` tries 2 / timeout 600s.
- **Signal files:** `storage/app/imports/progress_{id}.json` (written by controller + service, read by status), `cancel_{id}.json` (written by cancel endpoint, read by job/service).
- **Broadcasts:** `FileOperationEvent` on `private:users.{userId}` — `brand.import.progress` (import terminal + cancel), `brand.export.completed` / `brand.export.failed`. Events are wake-ups only; `imports` table is source of truth.
- **Cleanup:** Import job deletes uploaded file after terminal state (disk `public` vs `imports` — see bug report); temp image downloads are removed in `finally` block.
