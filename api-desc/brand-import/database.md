# Database — Brand Import / Export

## Overview

Brand import/export reuses the generic `imports` tracking table and the `brands` business table. No brand-import-specific migration creates a new table; the `imports` table is shared across product/category/brand imports/exports and category bulk-delete. Media for brands lives on the `brands` disk via Spatie Media Library.

## Table: `imports` (tracking)

**Migration:** `../../database/migrations/2026_06_27_000001_create_imports_table.php` (generic; also seeded in `tests/Feature/Brands/BrandImportExportTest` fallback)
**Model:** `packages/marvel/src/Database/Models/Import.php` (`Marvel\Database\Models\Import`)
**Table:** `imports`

| Column | Type | Nullable | Default | Notes |
|--------|------|----------|---------|-------|
| `id` | bigint unsigned PK | No | auto | |
| `type` | string | No | `product` | For brands: `brand` on create (controller drift) / `brand-import` (ImportType) / `brand-export` |
| `file_path` | string | Yes | — | Import: `imports/{uuid}.xlsx` on `imports` disk (`public` in job cleanup); Export: `brands-export-{ts}.xlsx` on `imports` disk |
| `file_name` | string | Yes | — | Import: original upload name; Export: generated filename |
| `images_source` | string | No | `none` | Unused for brands |
| `zip_file_path` | string | Yes | null | Unused |
| `status` | string | No | `pending` | `pending`/`processing`/`completed`/`completed_with_errors`/`failed`/`cancelled` |
| `total_rows` | int | No | 0 | Estimated via PhpSpreadsheet, reconciled by job to `success+failed` |
| `processed_rows` | int | No | 0 | `success+failed` on terminal |
| `success_rows` | int | No | 0 | DB column; API exposes as `successful_rows` |
| `failed_rows` | int | No | 0 | |
| `errors` | json | Yes | null | Array of `{sheet,row,name_en,name_ar,error_message}`; system rows use `sheet:'system',row:0` |
| `created_by` | FK → `users.id` | Yes | null | `nullOnDelete`; gated by `ImportPolicy@view` |
| `created_at` / `updated_at` | timestamps | — | — | `updated_at` → `completed_at` for terminal states |

**Casts** (`Import` model): `errors => array`, counters => `integer`.

**Scopes:** `pending()`, helpers `isCompleted()`, `isTerminal()`.

**Enum** (`Marvel\Enums\ImportType`): `BRAND_IMPORT='brand-import'`, `BRAND_EXPORT='brand-export'`.

**Enum** (`Marvel\Enums\ImportStatus`): `PENDING`, `PROCESSING`, `COMPLETED`, `COMPLETED_WITH_ERRORS`, `FAILED`, `CANCELLED`.

**ImportType mismatch note:** `BrandImportController@import` creates with `type='brand'` (literal), while `status/cancel/downloadErrors` query `ImportType::BRAND_IMPORT ('brand-import')`. `ImportBrandsJob` and `ExportBrandsJob` use the typed constants. The row is found after the job updates the type? Actually job never rewrites `type`; the initial row stays `brand` and status lookups would miss it if strict. In practice the DB stores `brand-import` for brand imports created through the corrected path; the `brand` literal is a legacy drift that tests do not hit because they create imports directly via `ImportType`. See bug report BUG-BRDIMP-001.

## Table: `brands` (business)

**File:** `../../packages/marvel/src/Database/Models/Brand.php` | **Table:** `brands`
**Traits:** `HasTranslations`, `InteractsWithMedia`, `SortableTrait`, `SoftDeletes`
**Migration:** `packages/marvel/database/migrations/2026_05_09_000001_create_brands_table.php`

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint PK | |
| `name` | json | Translatable `en`/`ar` |
| `details` | json | Translatable `en`/`ar` |
| `slug` | string | Unique, generated via `Str::slug(name_en)` in import service |
| `status` | tinyint | `1` active / `0` inactive; import parses booleans |
| `order` | int | Spatie sortable |
| `created_at` / `updated_at` | timestamps | |
| `deleted_at` | timestamp nullable | Soft delete; `rollbackCreatedData()` soft-deletes brands created in a cancelled import |

**Indexes:** `slug` unique (migration), `status` filter, `order`.

**Translatable:** `name`, `details` (en/ar).

**Sortable:** `order_column_name='order'`, `sort_when_creating=true`.

**Fillable:** `name`, `details`, `slug`, `status`, `order`.

## Table: `brand_product` (pivot, not directly touched by import/export)

| Column | Type |
|--------|------|
| `brand_id` | FK → brands |
| `product_id` | FK → products |

Unique composite index `(brand_id, product_id)`. Import does not manipulate pivot; product-brand associations are managed via product import (`BrandsSheetImport` legacy) or admin brand CRUD.

## Media Collections

**Trait:** `Marvel\Traits\MediaManager` via `Brand` model
**Disks:** `brands` (local, `../../storage/app/public/brands`, URL `/public/storage/brands`), `imports` (local, `storage/app/imports`)

| Collection | Disk | Used By |
|------------|------|---------|
| `brands-desktop` | `brands` | `BrandImportService::attachImage(..., 'brands-desktop')` |
| `brands-mobile` | `brands` | `BrandImportService::attachImage(..., 'brands-mobile')` |

`attachImage()` clears the collection before adding (replaces). Files are named `Str::uuid().ext`.

**Export image resolution:** `Brand::getFirstMediaUrl('brands-desktop')` / `brands-mobile` — empty string if no media.

## Related Tables

| Table | Relation |
|-------|----------|
| `users` | `imports.created_by` FK |
| `media` | Spatie media for brands |
| `imports` | Source of truth for async operation status |

## Soft Deletes & Rollback

`Brand` uses `SoftDeletes`. `BrandImportService::rollbackCreatedData()` iterates `createdIds` (brands created in this import run) and soft-deletes them (and clears media) when a cancel signal is honored (`ImportCancelledException`). Updated brands (existing matched by name) are **not** rolled back.

## Fillable / Mass Assignment

`Import` fillable: `type, file_path, file_name, images_source, zip_file_path, status, total_rows, processed_rows, success_rows, failed_rows, errors, created_by`.

`Brand` fillable: `name, details, slug, status, order`.

## Migration Files

| File | Purpose |
|------|---------|
| `../../database/migrations/2026_06_27_000001_create_imports_table.php` | Creates `imports` |
| `../../packages/marvel/database/migrations/2026_05_09_000001_create_brands_table.php` | Creates `brands` |
| `../../packages/marvel/database/migrations/2026_05_09_000002_create_brand_product_table.php` | Creates `brand_product` |

## Signal Files (filesystem, not DB)

| Path | Content |
|------|---------|
| `storage/app/imports/progress_{id}.json` | `{processed_rows, success_rows, failed_rows, progress, ...}` |
| `storage/app/imports/cancel_{id}.json` | `{cancelled_at: ISO8601}` |

These supplement the DB counters for live polling while the job runs.

## Sample File

`../../packages/marvel/resources/brands/brand-import-sample.xlsx` — 7-column template (`name_en`, `name_ar`, `details_en`, `details_ar`, `status`, `image_desktop_url`, `image_mobile_url`), sheet `brands`, header row 1.

## Configuration

`../../config/marvel.php`:

```php
'marvel' => [
  'import' => [
    'samples' => [
      'brand' => storage_path('packages/marvel/resources/brands/brand-import-sample.xlsx'),
    ]
  ]
]
```

`../../config/filesystems.php` disks: `imports` → `storage/app/imports`, `brands` → `storage/app/public/brands`, `public` → `storage/app/public`, `local` → `storage/app`.

## Query Patterns & Performance

- `loadExistingBrands()` preloads all brands (`select id,name,slug`) once at the start of `processRows` — O(N) in brands count, memory-bound; acceptable for < 100k brands, but would need chunking at scale.
- `Import::where(type)->findOrFail(id)` is the hot path for status/cancel/download; index on `(type, id)` would help if many import types coexist (currently PK lookup + filter).
- `progress_{id}.json` reads use `clearstatcache(true, path)` to avoid stale `file_exists` in the same request.
- Temp image files are stored in `storage/app/temp/brand_img_*.ext` and deleted in `finally`.
