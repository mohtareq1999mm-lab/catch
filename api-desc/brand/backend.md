# Brand Module — Backend Architecture

## Overview

The Brand module manages product brands on the platform. Brands are fully translatable (name, details), support media uploads (desktop + mobile images), maintain a sortable order, and can be associated with products via a many-to-many relationship. The module provides separate public (read-only) and admin (full CRUD + reorder) APIs.

## Endpoints

### Admin API (`/api/v1/brands`)

| Method | URL | Auth | Permission | Purpose |
|--------|-----|------|------------|---------|
| GET | `/api/v1/brands` | `auth:sanctum` | `view-brands` | List brands (paginated, filterable, sortable) |
| POST | `/api/v1/brands` | `auth:sanctum` | `create-brand` | Create a new brand |
| GET | `/api/v1/brands/{id}` | `auth:sanctum` | `view-brands` | Show brand by ID or slug |
| PUT | `/api/v1/brands/{id}` | `auth:sanctum` | `update-brand` | Update brand |
| DELETE | `/api/v1/brands/{id}` | `auth:sanctum` | `delete-brand` | Soft-delete brand |
| PUT | `/api/v1/brands/reorder` | `auth:sanctum` | `update-brand` | Reorder brands |

**Note:** `PUT /api/v1/brands/reorder` is defined BEFORE `apiResource('brands', ...)` so the literal `reorder` route matches before `{brand}` parameter binding.

### Public API (`/api/v1/general/brands`)

| Method | URL | Auth | Purpose |
|--------|-----|------|---------|
| GET | `/api/v1/general/brands` | Public | List active brands (filterable by date/IDs) |
| GET | `/api/v1/general/brands/{slug}` | Public | Get brand by slug with enriched products |
| GET | `/api/v1/general/brands-products` | Public | Get brand products by quantity set |

## Route Definitions

### Admin Routes
**File:** `packages/marvel/src/Rest/Routes.php`

```
Line 680: Route::put('brands/reorder', [BrandController::class, 'reorder']);     // Authenticated routes (protected by constructor middleware)
Line 681: Route::apiResource('brands', BrandController::class);                    // Full CRUD (index, store, show, update, destroy)
```

Authentication middleware and permission middleware are applied in the controller constructor, not at the route level.

### Public Routes
**File:** `routes/api.php`

```
Line 45: Route::get('brands', [BrandController::class, 'index']);                              // Prefix: /api/v1/general
Line 46: Route::get('brands/{slug}', [BrandController::class, 'getBrandBySlug']);              // Prefix: /api/v1/general
Line 47: Route::get('brands-products', [BrandController::class, 'getBrandsProductsByQtySet']); // Prefix: /api/v1/general
```

These are nested within a `Route::prefix('general')` group.

## Middleware

### Admin Controller (`Marvel\Http\Controllers\BrandController`)

| Method | Middleware |
|--------|-----------|
| `index` | `permission:view-brands` (via constructor) |
| `show` | `permission:view-brands` (via constructor) |
| `store` | `permission:create-brand` (via constructor) |
| `update` | `permission:update-brand` (via constructor) |
| `destroy` | `permission:delete-brand` (via constructor) |
| `reorder` | `permission:update-brand` (via constructor) |

Auth (`auth:sanctum`) is applied at the route group level in `Routes.php`.

### Public Controller (`App\Http\Controllers\Api\General\BrandController`)

No middleware — fully public access.

## Controller Flow

### Admin Controller (`Marvel\Http\Controllers\BrandController`)
**File:** `packages/marvel/src/Http/Controllers/BrandController.php`

```
GET /brands
  → BrandController@index
    → $this->repository (BrandRepository)
      → Apply filters: active(), inactive(), search('name', ...), orderBy(...)
    → $repository->ordered()->paginate($limit)
    → BrandResource::collection($brands)
    → $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, [...pagination data...])

POST /brands
  → BrandController@store(BrandCreateRequest)
    → $this->repository->saveBrand($request)
      → DB::transaction
        → Create brand with slug
        → Sync products if provided
        → Upload desktop image (collection: brands-desktop, disk: brands)
        → Upload mobile image (collection: brands-mobile, disk: brands)
        → Commit
      → On failure: Rollback, log error, throw HttpException(500)
    → $brand->load('products')
    → BrandResource::make($brand)
    → $this->apiResponse(BRAND_CREATED_SUCCESSFULLY, 201, true, ...)

GET /brands/{params}
  → BrandController@show($params)
    → If numeric: find by ID, else: find by slug
    → $this->repository->with('products')->where('id'|'slug', $params)->firstOrFail()
    → BrandResource::make($brand)
    → $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, ...)
    → On failure: MarvelException(NOT_FOUND)

PUT /brands/{id}
  → BrandController@update(BrandUpdateRequest, $id)
    → $request->merge(['id' => $id])
    → $this->brandUpdate($request) [private]
      → $this->repository->findOrFail($request->id)
      → $this->repository->updateBrand($request, $brand)
        → DB::transaction
          → Update brand data (regenerate slug if name changed)
          → Sync products if provided
          → Update desktop image if provided
          → Update mobile image if provided
          → Commit
        → On failure: Rollback, log error, throw HttpException(500)
    → $brand->load('products')
    → BrandResource::make($brand)
    → $this->apiResponse(BRAND_UPDATED_SUCCESSFULLY, 200, true, ...)

DELETE /brands/{id}
  → BrandController@destroy($id)
    → $this->repository->findOrFail($id)->delete()   [soft delete]
    → $this->apiResponse(BRAND_DELETED_SUCCESSFULLY, 200, true)
    → On failure: MarvelException(NOT_FOUND)

PUT /brands/reorder
  → BrandController@reorder(BrandsReorderRequest)
    → $this->repository->reorder($request->brands)
      → $this->setNewOrder($brands)   [Spatie SortableTrait]
    → $this->apiResponse(BRANDS_REORDERED_SUCCESSFULLY, 200, true)
```

### Public Controller (`App\Http\Controllers\Api\General\BrandController`)
**File:** `app/Http/Controllers/Api/General/BrandController.php`

```
GET /general/brands
  → BrandController@index(Request)
    → If slug query param: delegate to getBrandBySlug($slug)
    → BrandService::getBrands($request)
      → Query: Brand::active()
        → Filter by start_date / end_date / brandsId (comma-separated or array)
        → Order by id (default desc), limit (default 10)
      → Return collection
    → BrandResource::collection($brands)
    → $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, ...)

GET /general/brands/{slug}
  → BrandController@getBrandBySlug($slug)
    → BrandService::getBrandBySlug($slug)
      → Brand::active()->search('slug', $slug, locale)->first()
      → Load products with channel filter, media, review averages
      → Enrich with pricing via ProductService::enrichCollectionWithPricing()
    → BrandResource::make($brand)
    → $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, ...)
    → If not found: $this->apiResponse(NOT_FOUND, 404, false)

GET /general/brands-products
  → BrandController@getBrandsProductsByQtySet(Request)
    → BrandService::getBrandsProductsByQtySet($request)
      → Brand::active()
        → Filter by start_date / end_date
        → Load products with channel filter, media, review averages (limit: ?limit=10)
        → Limit brands (default: ?limit_brand=10)
      → Flatten products from all brands
      → Enrich with pricing
    → ProductMiniResource::collection($products)
    → $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, ...)
```

## Repository

**File:** `packages/marvel/src/Database/Repositories/BrandRepository.php`
**Extends:** `BaseRepository` (which extends `Prettus\Repository\Eloquent\BaseRepository`)

| Method | Description |
|--------|-------------|
| `model()` | Returns `Brand::class` |
| `boot()` | Pushes `RequestCriteria` for search/filter |
| `saveBrand($request)` | Transactional create with slug, product sync, image upload |
| `updateBrand($request, $brand)` | Transactional update with slug regeneration, product sync, image update |
| `reorder(array $brands)` | Delegates to `SortableTrait::setNewOrder()` |

### `saveBrand()` Flow
```
1. DB::beginTransaction()
2. Extract $data from request (name, slug, details, status)
3. Generate slug via $this->makeSlug($request)
4. $this->create($data)
5. If products provided: $brand->products()->sync($products)
6. If image-desktop: uploadSingleImage($request, 'image-desktop', $brand, 'brands-desktop', 'brands')
7. If image-mobile: uploadSingleImage($request, 'image-mobile', $brand, 'brands-mobile', 'brands')
8. DB::commit()
9. Return $brand

On error:
  - HttpException(422): Image upload failed
  - HttpException(500): Generic failure (rollback + log)
```

### `updateBrand()` Flow
```
1. DB::beginTransaction()
2. Extract $data from request
3. If name provided: regenerate slug via makeSlug() (with update ID for unique check)
4. $brand->update($data)
5. If products provided: $brand->products()->sync($products)  [replaces all]
6. If image-desktop: updateSingleImage()  [clears collection, uploads new]
7. If image-mobile: updateSingleImage()   [clears collection, uploads new]
8. DB::commit()
9. Return $this->findOrFail($brand->id)  [fresh instance]

On error:
  - HttpException(422): Image upload failed
  - HttpException(500): Generic failure (rollback + log)
```

### `reorder()` Flow
```
1. Receive validated array of brand IDs in desired order
2. $this->setNewOrder($brands)  — Spatie Eloquent Sortable
   → Updates the 'order' column for each brand based on array position
   → On error: HttpException(500) with error message
```

### Base Repository (`BaseRepository`)
**File:** `packages/marvel/src/Database/Repositories/BaseRepository.php`

| Method | Description |
|--------|-------------|
| `makeSlug($request, $key, $update)` | Generates unique slug from request name/title/slug |
| `findBySlugOrId($value, $language)` | Find by `id` (numeric) or `slug` (string) |
| `findOneByField($field, $value)` | Find single record by field |
| `findOneByFieldOrFail($field, $value)` | Find single or throw MarvelException |
| `hasPermission($user, $shop_id)` | Check user permissions for shop context |

Uses `CacheableRepository` trait (Prettus) for automatic query caching.

## Model

**File:** `packages/marvel/src/Database/Models/Brand.php`
**Table:** `brands`
**Traits:** `HasTranslations`, `InteractsWithMedia`, `SortableTrait`, `SoftDeletes`
**Implements:** `HasMedia`, `Sortable`

| Property | Details |
|----------|---------|
| Translatable | `name`, `details` |
| Sortable | `order_column_name => 'order'`, `sort_when_creating => true` |
| Fillable | `name`, `details`, `slug`, `status`, `order` |
| Media Collections | `brands-desktop`, `brands-mobile` |

### Scopes

| Scope | Description |
|-------|-------------|
| `scopeActive($q)` | `where('status', 1)` |
| `scopeInactive($q)` | `where('status', 0)` |
| `scopeSearch($q, $field, $term, $locale)` | Searches translatable fields with `like` on both `{field}->{locale}` and raw `{field}` |

### Model Events (booted)

| Event | Behavior |
|-------|----------|
| `saving` | If `name` is dirty but `slug` is not, auto-generate slug from English name via `Str::slug()` |

### Relationships

| Relation | Type | Pivot | Foreign |
|----------|------|-------|---------|
| `products()` | BelongsToMany | `brand_product` (`brand_id`, `product_id`) | `product_id` |

Pivot table `brand_product` has a unique composite index on `(brand_id, product_id)` to prevent duplicates.

## Resources

### Admin Resource (`Marvel\Http\Resources\BrandResource`)
**File:** `packages/marvel/src/Http/Resources/BrandResource.php`

```json
{
  "id": "integer",
  "name": "translated string",
  "slug": "string",
  "image": {
    "desktop": "media url | null",
    "mobile": "media url | null"
  },
  "details": "translated string",
  "status": "boolean",
  "products": "[...]" // only when loaded, mapped to: id, name, slug, status, image.thumbnail
}
```

### Public Resource (`App\Http\Resources\Brand\BrandResource`)
**File:** `app/Http/Resources/Brand/BrandResource.php`

```json
{
  "id": "integer",
  "name": "translated string",
  "slug": "string",
  "image": {
    "desktop": "media url | null",
    "mobile": "media url | null"
  },
  "status": "boolean",
  "products": "[...]" // only when loaded, uses ProductMiniResource
}
```

**Key difference:** Admin resource includes `details` field; public resource does not.

## Request Validation

### BrandCreateRequest (`Marvel\Http\Requests\BrandCreateRequest`)

**File:** `packages/marvel/src/Http/Requests/BrandCreateRequest.php`

| Field | Rules |
|-------|-------|
| `name` | `required`, `array` |
| `name.*` | `required`, `string`, `UniqueTranslationRule::for('brands', 'name')` |
| `image-desktop` | `required`, `file`, `mimes:jpeg,png,jpg,gif,svg`, `max:2048` |
| `image-mobile` | `required`, `file`, `mimes:jpeg,png,jpg,gif,svg`, `max:2048` |
| `details` | `sometimes`, `array` |
| `details.*` | `required_with:details`, `string`, `min:3`, `max:2500` |
| `status` | `sometimes`, `in:1,0` |
| `products` | `sometimes`, `array` |
| `products.*` | `integer`, `exists:products,id` |

### BrandUpdateRequest (`Marvel\Http\Requests\BrandUpdateRequest`)

**File:** `packages/marvel/src/Http/Requests/BrandUpdateRequest.php`

| Field | Rules |
|-------|-------|
| `name` | `sometimes`, `array` |
| `name.*` | `sometimes`, `string`, `UniqueTranslationRule::for('brands')->ignore($id)` |
| `image-desktop` | `sometimes`, `file`, `mimes:jpeg,png,jpg,gif,svg`, `max:2048` |
| `image-mobile` | `sometimes`, `file`, `mimes:jpeg,png,jpg,gif,svg`, `max:2048` |
| `details` | `sometimes`, `array` |
| `details.*` | `required_with:details`, `string`, `min:3`, `max:2500` |
| `status` | `sometimes`, `in:1,0` |
| `products` | `sometimes`, `array` |
| `products.*` | `integer`, `exists:products,id` |

**Note:** Update request ignores the brand's own name on uniqueness check via `->ignore($id)` where `$id = $this->route('brand')`.

### BrandsReorderRequest (`Marvel\Http\Requests\BrandsReorderRequest`)

**File:** `packages/marvel/src/Http/Requests/BrandsReorderRequest.php`

| Field | Rules |
|-------|-------|
| `brands` | `required`, `array` |
| `brands.*` | `required`, `integer`, `exists:brands,id` |

## Observer

**File:** `app/Observers/BrandObserver.php`
**Registered in:** `AppServiceProvider` or `EventServiceProvider`

| Event | Behavior |
|-------|----------|
| `created` | Logs activity: `brand_created` |
| `updated` | If status changed: logs `brand_activated` or `brand_deactivated`. If other fields changed: logs `brand_updated` with old/new values. Skips logging if only `updated_at` is dirty. |
| `deleted` | Logs activity: `brand_deleted` |

All observer logging dispatches `LogActivityJob` (queued).

## Media Handling

**Trait:** `Marvel\Traits\MediaManager`

**Disk:** `brands` (local, `storage/app/public/brands`, URL: `/public/storage/brands`)

**Collections:**

| Collection | Type | Upload Method |
|------------|------|---------------|
| `brands-desktop` | Single image | `uploadSingleImage()` on create, `updateSingleImage()` on update |
| `brands-mobile` | Single image | `uploadSingleImage()` on create, `updateSingleImage()` on update |

`updateSingleImage()` clears the entire collection before uploading the new file.

## Database Schema

### Table: `brands`
**Migration:** `packages/marvel/database/migrations/2026_05_09_000001_create_brands_table.php`

| Column | Type | Constraints |
|--------|------|-------------|
| `id` | bigint unsigned | PK, auto-increment |
| `name` | text | NOT NULL (translatable JSON) |
| `slug` | string(255) | NOT NULL |
| `details` | text | NULLABLE (translatable JSON) |
| `status` | boolean | DEFAULT true |
| `order` | integer | DEFAULT 0 (sortable) |
| `created_at` | timestamp | NULLABLE |
| `updated_at` | timestamp | NULLABLE |
| `deleted_at` | timestamp | NULLABLE (soft deletes) |

### Table: `brand_product` (pivot)
**Migration:** `packages/marvel/database/migrations/2026_05_09_000002_create_brand_product_table.php`

| Column | Type | Constraints |
|--------|------|-------------|
| `id` | bigint unsigned | PK, auto-increment |
| `brand_id` | bigint unsigned | FK → brands.id ON DELETE CASCADE |
| `product_id` | bigint unsigned | FK → products.id ON DELETE CASCADE |

**Indexes:**
- `UNIQUE (brand_id, product_id)` — prevents duplicate associations
- `INDEX (brand_id, product_id)` — composite index for query performance

## Soft Deletes & Pivot Behavior

- Brands use `SoftDeletes` — calling `delete()` sets `deleted_at` instead of removing the row.
- Pivot records in `brand_product` are **preserved** on soft delete (no `cascade` on soft delete).
- Pivot records are **hard-deleted** on force delete (FK `ON DELETE CASCADE`).
- Restoring a soft-deleted brand restores access to its pivot relationships.

## Import / Export

### Import: BrandsSheetImport
**File:** `packages/marvel/src/Imports/Sheets/BrandsSheetImport.php`
- Sheet title: `brands`
- Groups rows by `product_sku`
- Calls `ProductImportService::syncBrands($sku, $slugs)` to associate brands via slugs

### Export: BrandsSheetExport
**File:** `packages/marvel/src/Exports/Sheets/BrandsSheetExport.php`
- Sheet title: `brands`
- Columns: `product_sku`, `brand_slug`
- Supports filtering by `brand_id`
- Iterates all products with their associated brands

## Product Engine Strategy

**File:** `app/Services/General/ProductEngine/Strategies/ProductForBrand.php`

Part of the Product Engine strategy pattern. Delegates to `ProductService::getBrandsProductsByQtySet()` for fetching products filtered by brand with quantity limits.

## Permissions

**Enum:** `Marvel\Enums\Permission`

| Constant | Value |
|----------|-------|
| `VIEW_BRANDS` | `view-brands` |
| `VIEW_BRAND` | `view-brand` |
| `CREATE_BRAND` | `create-brand` |
| `UPDATE_BRAND` | `update-brand` |
| `DELETE_BRAND` | `delete-brand` |

## Constants

**File:** `packages/marvel/config/constants.php`

```php
define('BRAND_CREATED_SUCCESSFULLY',   APP_NOTICE_DOMAIN . 'MESSAGE.BRAND_CREATED_SUCCESSFULLY');
define('BRAND_UPDATED_SUCCESSFULLY',   APP_NOTICE_DOMAIN . 'MESSAGE.BRAND_UPDATED_SUCCESSFULLY');
define('BRAND_DELETED_SUCCESSFULLY',   APP_NOTICE_DOMAIN . 'MESSAGE.BRAND_DELETED_SUCCESSFULLY');
define('BRANDS_REORDERED_SUCCESSFULLY', APP_NOTICE_DOMAIN . 'MESSAGE.BRANDS_REORDERED_SUCCESSFULLY');
```

## Seeders

### BrandSeeder
**File:** `database/seeders/BrandSeeder.php`
- Seeds 30 brands (Apple, Samsung, Sony, LG, Nike, Adidas, etc.) with bilingual names (en/ar) and bilingual details.
- Each brand gets random status (0 or 1).
- Images from `public/images/brand/` are assigned to `brands-desktop` and `brands-mobile` collections (cyclic assignment).
- Idempotent: creates if not exists, updates if exists.

### BrandProductSeeder
**File:** `database/seeders/BrandProductSeeder.php`
- Maps brands to products based on SKU prefix patterns (e.g., Apple → ELC, Nike → CLO/SPT).
- Unmapped brands get 3–8 random products assigned.
- Uses `syncWithoutDetaching()` to avoid duplicate pivot entries.

## Dependencies

| File | Role |
|------|------|
| `packages/marvel/src/Rest/Routes.php` | Admin route definitions |
| `routes/api.php` | Public route definitions |
| `packages/marvel/src/Http/Controllers/BrandController.php` | Admin controller |
| `app/Http/Controllers/Api/General/BrandController.php` | Public controller |
| `packages/marvel/src/Http/Requests/BrandCreateRequest.php` | Create validation |
| `packages/marvel/src/Http/Requests/BrandUpdateRequest.php` | Update validation |
| `packages/marvel/src/Http/Requests/BrandsReorderRequest.php` | Reorder validation |
| `packages/marvel/src/Http/Resources/BrandResource.php` | Admin API resource |
| `app/Http/Resources/Brand/BrandResource.php` | Public API resource |
| `app/Http/Resources/Product/ProductMiniResource.php` | Product resource (public, for brand products) |
| `packages/marvel/src/Database/Models/Brand.php` | Model |
| `packages/marvel/src/Database/Repositories/BrandRepository.php` | Repository |
| `packages/marvel/src/Database/Repositories/BaseRepository.php` | Base repository (slug generation, caching) |
| `app/Services/General/BrandService.php` | Public brand service |
| `app/Services/General/ProductService.php` | Product enrichment (pricing) |
| `app/Services/General/ProductEngine/Strategies/ProductForBrand.php` | Product engine strategy |
| `app/Observers/BrandObserver.php` | Activity logging observer |
| `app/Jobs/LogActivityJob.php` | Queued activity logging |
| `packages/marvel/src/Enums/Permission.php` | Permissions enum |
| `packages/marvel/config/constants.php` | Response message constants |
| `packages/marvel/src/Traits/MediaManager.php` | Image upload trait |
| `app/Traits/HasChannelFilter.php` | Channel filtering trait |
| `packages/marvel/database/migrations/2026_05_09_000001_create_brands_table.php` | Brands table migration |
| `packages/marvel/database/migrations/2026_05_09_000002_create_brand_product_table.php` | Pivot table migration |
| `database/seeders/BrandSeeder.php` | Brand seeder |
| `database/seeders/BrandProductSeeder.php` | Brand-product pivot seeder |
| `packages/marvel/src/Imports/Sheets/BrandsSheetImport.php` | Excel import |
| `packages/marvel/src/Exports/Sheets/BrandsSheetExport.php` | Excel export |
| `config/filesystems.php` | Disk configuration (`brands` disk) |
| `tests/Feature/BrandApiTest.php` | API feature tests |
| `tests/Feature/BrandProductionHardenTest.php` | Production harden tests |

## Translation Keys Used

| Key | Context |
|-----|---------|
| `MESSAGE.BRAND_CREATED_SUCCESSFULLY` | POST response message |
| `MESSAGE.BRAND_UPDATED_SUCCESSFULLY` | PUT response message |
| `MESSAGE.BRAND_DELETED_SUCCESSFULLY` | DELETE response message |
| `MESSAGE.BRANDS_REORDERED_SUCCESSFULLY` | PUT /reorder response message |
| `MESSAGE.FETCH_DATA_SUCCESSFULLY` | GET response message |
| `ERROR.NOT_FOUND` | 404 error response |
| `ERROR.COULD_NOT_CREATE_THE_RESOURCE` | 500 create failure |
| `ERROR.COULD_NOT_UPDATE_THE_RESOURCE` | 500 update failure |
| `activity.brand_created` | Observer: create log |
| `activity.brand_updated` | Observer: update log |
| `activity.brand_deleted` | Observer: delete log |
| `activity.brand_restored` | Observer: restore log |
| `activity.brand_activated` | Observer: status change to active |
| `activity.brand_deactivated` | Observer: status change to inactive |
