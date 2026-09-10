# Category Excel Import / Export Module

## Overview

The Category Excel Import/Export module provides asynchronous bulk management of hierarchical product categories through Excel files. It provides:

- **Import API** (`/api/v1/categories/import`) — Upload an Excel file, queue an import job, track progress, cancel, and download error reports
- **Export API** (`/api/v1/categories/export`) — Queue an Excel export, track status, and download the generated `.xlsx` file
- **Sample Template** (`/api/v1/categories/import/sample`) — Official import template with the exact expected columns

Imports and exports run **asynchronously** on the `meem-high` queue. The HTTP request only creates a tracking record in the `imports` table and dispatches a queued job; all Excel processing happens in the background. Progress is exposed through signal files under `storage/app/imports/` and the `imports` database table.

Categories are identified by their **English name** (`name_en`). Existing categories are updated, new categories are created with a deterministically generated slug (`Str::slug()`). Parents are resolved by **English name** (`parent_name_en`), which allows row-order-independent, multi-level hierarchy imports. Images are imported from public URLs and protected against SSRF.

## Key Files

| Layer | File |
|-------|------|
| Import Controller | `packages/marvel/src/Http/Controllers/CategoryImportController.php` |
| Export Controller | `packages/marvel/src/Http/Controllers/CategoryExportController.php` |
| Import Service | `packages/marvel/src/Services/Import/CategoryImportService.php` |
| Import Job | `packages/marvel/src/Jobs/ImportCategoriesJob.php` |
| Export Job | `packages/marvel/src/Jobs/ExportCategoriesJob.php` |
| Excel Import | `packages/marvel/src/Imports/CategoriesImport.php` |
| Excel Export | `packages/marvel/src/Exports/CategoriesExport.php` |
| Import Request | `packages/marvel/src/Http/Requests/CategoryImportRequest.php` |
| Export Request | `packages/marvel/src/Http/Requests/CategoryExportRequest.php` |
| Import Model | `packages/marvel/src/Database/Models/Import.php` |
| Import Status Enum | `packages/marvel/src/Enums/ImportStatus.php` |
| Import Table Migration | `database/migrations/2026_06_27_000001_create_imports_table.php` |
| Sample Template | `packages/marvel/resources/categories/category-import-sample.xlsx` |
| Admin Routes | `packages/marvel/src/Rest/Routes.php` (lines 142-151) |
| Permissions | `packages/marvel/src/Enums/Permission.php` |

## Dependencies

- **Maatwebsite/Excel** (`maatwebsite/excel`) — Excel read/write
- **PhpSpreadsheet** — Row counting and format detection
- **Laravel Queues** — Async processing on the `meem-high` queue
- **Laravel HTTP Client** — SSRF-safe image downloads
- **Spatie Media Library** — Category image storage (`categories-desktop`, `categories-mobile` collections on `categories` disk)
- **CategoryHierarchyService** — Parent validation, cycle detection, level calculation

## Permissions

| Permission | Required For |
|------------|-------------|
| `import-category` | POST /categories/import, GET /categories/import/sample, GET /categories/import/{id}, POST /categories/import/{id}/cancel, GET /categories/import/{id}/download-errors |
| `export-category` | GET /categories/export, GET /categories/export/{id}, GET /categories/export/{id}/download |

`super_admin` bypasses both permission checks (the middleware allows `import-category|super_admin` and `export-category|super_admin`).

## Routes

### Admin

| Method | Endpoint | Purpose |
|--------|----------|---------|
| POST | `/api/v1/categories/import` | Queue an Excel category import (returns `import_id`) |
| GET | `/api/v1/categories/import/sample` | Download the official import template |
| GET | `/api/v1/categories/import/{id}` | Fetch import progress/status |
| POST | `/api/v1/categories/import/{id}/cancel` | Cancel a pending/processing import |
| GET | `/api/v1/categories/import/{id}/download-errors` | Download failed rows as `.xlsx` |
| GET | `/api/v1/categories/export` | Queue an Excel category export (returns `export_id`) |
| GET | `/api/v1/categories/export/{id}` | Fetch export status |
| GET | `/api/v1/categories/export/{id}/download` | Download the generated `.xlsx` export |

## Excel Format

Both Import and Export use exactly the same 9 columns:

```
name_en | name_ar | details_en | details_ar | parent_name_en | status | is_featured | image_desktop_url | image_mobile_url
```

- Header row is row **1**; data starts at row **2**
- The sheet title is `categories`
- The exported Excel can be re-imported after editing

## Image Contract — Single URL Per Collection

- `image_desktop_url` = **one URL** → `categories-desktop` collection on disk `categories`
- `image_mobile_url`  = **one URL** → `categories-mobile` collection on disk `categories`
- **Maximum 2 media rows per category** (1 desktop + 1 mobile) through this import.
- There is **no gallery** contract (`images`, `gallery`, `categories-gallery` do not exist).
- Pipe-separated values like `url1|url2|url3` in a single cell are **one malformed URL** and fail validation (`INVALID_IMAGE_URL`), producing 0 media rows and a row-level error. Do not use `explode('|', ...)`.
- Image download is best-effort: URL format validation still fails the row, but download/attachment failures (unsafe URL, too large, wrong MIME, corrupt image, network error) are logged and the category still succeeds with no/partial media, preserving existing valid media.
- API returns `image.desktop = getFirstMediaUrl('categories-desktop')`, `image.mobile = getFirstMediaUrl('categories-mobile')` independently.
- Export reads each collection independently: `image_desktop_url = desktop media URL`, `image_mobile_url = mobile media URL`.

## Import Identity

- **`name_en` is the identity.** It is normalized (whitespace collapsed + trimmed, then case-insensitive via `mb_strtolower`) and matched against existing categories.
- If a category with the same normalized English name exists → the existing category is **updated**.
- If not found → a new category is created with slug `Str::slug(name_en)` (deterministic, no random suffix, `globalSlugify()` is never used).
- If the generated slug is already owned by a different category → the row fails with a "slug conflict" error.

## Async Behavior

| Queue | Jobs |
|-------|------|
| `meem-high` | `ImportCategoriesJob`, `ExportCategoriesJob` |

- `ImportCategoriesJob` — tries 3, timeout 1500s, backoff [60, 120, 240]
- `ExportCategoriesJob` — tries 2, timeout 600s