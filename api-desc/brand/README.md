# Brand Module

## Overview

The Brand module manages product brands for the e-commerce platform. It provides two separate API surfaces:

- **Admin API** (`/api/v1/brands`) — Full CRUD + reorder, protected by permissions
- **Public API** (`/api/v1/general/brands`) — Read-only, no authentication required

Brands are fully translatable (name, details in multiple languages), support media uploads (desktop + mobile images), maintain a sortable order, and associate with products via a many-to-many relationship.

## Key Files

| Layer | File |
|-------|------|
| Admin Controller | `packages/marvel/src/Http/Controllers/BrandController.php` |
| Public Controller | `app/Http/Controllers/Api/General/BrandController.php` |
| Repository | `packages/marvel/src/Database/Repositories/BrandRepository.php` |
| Model | `packages/marvel/src/Database/Models/Brand.php` |
| Admin Resource | `packages/marvel/src/Http/Resources/BrandResource.php` |
| Public Resource | `app/Http/Resources/Brand/BrandResource.php` |
| Create Request | `packages/marvel/src/Http/Requests/BrandCreateRequest.php` |
| Update Request | `packages/marvel/src/Http/Requests/BrandUpdateRequest.php` |
| Reorder Request | `packages/marvel/src/Http/Requests/BrandsReorderRequest.php` |
| Public Service | `app/Services/General/BrandService.php` |
| Observer | `app/Observers/BrandObserver.php` |
| Admin Routes | `packages/marvel/src/Rest/Routes.php` (lines 680-681) |
| Public Routes | `routes/api.php` (lines 45-47) |
| Permissions | `packages/marvel/src/Enums/Permission.php` |
| Brands Migration | `packages/marvel/database/migrations/2026_05_09_000001_create_brands_table.php` |
| Pivot Migration | `packages/marvel/database/migrations/2026_05_09_000002_create_brand_product_table.php` |
| Seeder (brands) | `database/seeders/BrandSeeder.php` |
| Seeder (pivot) | `database/seeders/BrandProductSeeder.php` |
| Import | `packages/marvel/src/Imports/Sheets/BrandsSheetImport.php` |
| Export | `packages/marvel/src/Exports/Sheets/BrandsSheetExport.php` |
| Strategy | `app/Services/General/ProductEngine/Strategies/ProductForBrand.php` |
| Tests | `tests/Feature/BrandApiTest.php` |
| Tests | `tests/Feature/BrandProductionHardenTest.php` |

## Dependencies

- **Spatie Translatable** (`HasTranslations`) — bilingual name/details (en/ar)
- **Spatie Media Library** (`InteractsWithMedia`) — brand image management
- **Spatie Eloquent Sortable** (`SortableTrait`) — draggable reorder
- **Laravel SoftDeletes** — soft delete support
- **Prettus Repository** — repository pattern with caching

## Permissions

| Permission | Required For |
|------------|-------------|
| `view-brands` | GET /brands, GET /brands/{id} |
| `create-brand` | POST /brands |
| `update-brand` | PUT /brands/{id}, PUT /brands/reorder |
| `delete-brand` | DELETE /brands/{id} |

## Routes

### Admin

| Method | Endpoint | Purpose |
|--------|----------|---------|
| GET | `/api/v1/brands` | List brands (paginated, filterable, sortable) |
| POST | `/api/v1/brands` | Create brand (with images + product associations) |
| GET | `/api/v1/brands/{id}` | Show brand by ID or slug |
| PUT | `/api/v1/brands/{id}` | Update brand |
| DELETE | `/api/v1/brands/{id}` | Soft-delete brand |
| PUT | `/api/v1/brands/reorder` | Reorder brands |

### Public

| Method | Endpoint | Purpose |
|--------|----------|---------|
| GET | `/api/v1/general/brands` | List active brands |
| GET | `/api/v1/general/brands/{slug}` | Get brand by slug with enriched products |
| GET | `/api/v1/general/brands-products` | Get brand products by quantity set |
