# Category Module — Backend Architecture

## Overview

The Category module manages hierarchical product categories with parent-child relationships, level tracking, and cycle detection. Categories support translatable names/details, media uploads (desktop + mobile images), featured toggling, and product associations via a many-to-many pivot. The module provides separate public (read-only) and admin (full CRUD + feature toggle) APIs.

## Endpoints

### Admin API (`/api/v1/categories`)

| Method | URL | Auth | Permission | Purpose |
|--------|-----|------|------------|---------|
| GET | `/api/v1/categories` | `auth:sanctum` | `view-categories` | List categories (paginated, filterable, sortable, parent/featured filters) |
| POST | `/api/v1/categories` | `auth:sanctum` | `create-category` | Create a new category |
| GET | `/api/v1/categories/{id}` | `auth:sanctum` | `view-categories` | Show category by ID with parent, children, products |
| PUT | `/api/v1/categories/{id}` | `auth:sanctum` | `update-category` | Update category |
| DELETE | `/api/v1/categories/{id}` | `auth:sanctum` | `delete-category` | Soft-delete category (restricted if children exist) |
| PUT | `/api/v1/categories/feature` | `auth:sanctum` | `update-category` | Toggle category featured status |
| GET | `/api/v1/featured-categories` | Public | None | Fetch top N categories by product count |

### Public API (`/api/v1/general/categories`)

| Method | URL | Auth | Purpose |
|--------|-----|------|---------|
| GET | `/api/v1/general/categories` | Public | List active categories (paginated, filterable, searchable) |
| GET | `/api/v1/general/categories/{slug}` | Public | Get category by slug with children and enriched products |

### Analytics / Dashboard

| Method | URL | Auth | Purpose |
|--------|-----|------|---------|
| GET | `/api/v1/category-wise-product` | Auth | Category-wise product count (analytics) |
| GET | `/api/v1/category-wise-product-sale` | Auth | Category-wise product sales (analytics) |
| GET | `/api/v1/category-stats` | Auth | Category statistics (dashboard) |
| GET | `/api/v1/dashboard/categories` | Auth | Category analytics (dashboard) |

## Route Definitions

### Admin Routes
**File:** `packages/marvel/src/Rest/Routes.php`

```
Line 229: Route::apiResource('categories', CategoryController::class, ['only' => ['index', 'show']]);  // Public (no auth)
Line 232: Route::get('featured-categories', [CategoryController::class, 'fetchFeaturedCategories']);    // Public
Line 233: Route::get('component-data/categories', [ComponentDataController::class, 'categories']);       // Public
Line 678: Route::put('categories/feature', [CategoryController::class, 'addOrRemoveCategoryFromFeature']); // Authenticated
Line 679: Route::apiResource('categories', CategoryController::class);                                     // Authenticated (full CRUD)
Line 573: Route::get('category-wise-product', [AnalyticsController::class, 'categoryWiseProduct']);      // Analytics
Line 574: Route::get('category-wise-product-sale', [AnalyticsController::class, 'categoryWiseProductSale']); // Analytics
Line 819: Route::get('category-stats', [DashboardController::class, 'categoryStats']);                   // Dashboard
Line 825: Route::get('categories', [DashboardController::class, 'categoryAnalytics']);                   // Dashboard
```

**Note:** Lines 229 and 679 register the same resource with different middleware. The public group (line 229, `only: index, show`) has no auth. The authenticated group (line 679) has auth + permissions. The `PUT categories/feature` route is defined BEFORE `apiResource` to avoid `{category}` parameter capturing "feature".

### Public Routes
**File:** `routes/api.php`

```
Route::prefix('general')->group(function () {
    Route::get('categories', [CategoryController::class, 'index']);
    Route::get('categories/{slug}', [CategoryController::class, 'getCategoryBySlug']);
});
```

## Middleware

### Admin Controller (`Marvel\Http\Controllers\CategoryController`)

| Method | Middleware |
|--------|-----------|
| `index` | `permission:view-categories` (via constructor) |
| `show` | `permission:view-categories` (via constructor) |
| `store` | `permission:create-category` (via constructor) |
| `update` | `permission:update-category` (via constructor) |
| `destroy` | `permission:delete-category` (via constructor) |
| `addOrRemoveCategoryFromFeature` | `permission:update-category` (via constructor) |
| `fetchFeaturedCategories` | None (public) |

Auth (`auth:sanctum`) is applied at the route group level for authenticated routes.

### Public Controller (`App\Http\Controllers\Api\General\CategoryController`)

No middleware — fully public access.

## Controller Flow

### Admin Controller (`Marvel\Http\Controllers\CategoryController`)
**File:** `packages/marvel/src/Http/Controllers/CategoryController.php`

```
GET /categories
  → CategoryController@index(Request)
    → Apply filters: parent (top-level only), exceptSelf, active/inactive, search (name), feature-category
    → withCount('products')
    → orderBy (id, name, slug, products_count, created_at, updated_at, level)
    → If parent=true: whereNull('parent_id')
    → If feature-category: where('is_featured', true)
    → paginate($limit)
    → CategoryResource::collection($categories)
    → $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, [...pagination data...])

POST /categories
  → CategoryController@store(CategoryCreateRequest)
    → CategoryRepository::saveCategory($request)
      → DB::transaction
        → Generate slug via makeSlug($request)
        → Create category (name, slug, details, parent_id, status)
        → If products[]: sync($products)
        → If image-desktop: uploadSingleImage('categories-desktop', 'categories')
        → If image-mobile: uploadSingleImage('categories-mobile', 'categories')
        → Commit
      → On failure: Rollback, HttpException(500)
    → $category->load('products')
    → CategoryResource::make($category)
    → $this->apiResponse(CATEGORY_CREATED_SUCCESSFULLY, 200, true, ...)

GET /categories/{id}
  → CategoryController@show(Request, $id)
    → $this->repository->with(['parent', 'products'])->withCount('products')->where('id', $id)->firstOrFail()
    → CategoryHierarchyService::loadDirectChildren($category, true)
    → CategoryResource::make($category)
    → $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, ...)
    → On failure: MarvelException(NOT_FOUND)

PUT /categories/{id}
  → CategoryController@update(CategoryUpdateRequest, $id)
    → $request->merge(['id' => $id])
    → categoryUpdate($request) [private]
      → $this->repository->findOrFail($request->id)
      → $this->repository->updateCategory($request, $category)
        → DB::transaction
          → Update data (slug regenerated if name changed)
          → If image-desktop: updateSingleImage()
          → If image-mobile: updateSingleImage()
          → If products[]: sync($products)
          → Commit
        → On failure: Rollback, HttpException(500)
    → $category->load('products')
    → CategoryResource::make($category)
    → $this->apiResponse(CATEGORY_UPDATED_SUCCESSFULLY, 200, true, ...)

DELETE /categories/{id}
  → CategoryController@destroy($id)
    → $this->repository->findOrFail($id)->delete() [soft delete]
    → $this->apiResponse(CATEGORY_DELETED_SUCCESSFULLY, 200, true)
    → On ModelNotFoundException: MarvelException(NOT_FOUND)
    → On QueryException: MarvelException(CANNOT_DELETE_CATEGORY_WITH_ASSOCIATED_RESOURCES)

PUT /categories/feature
  → CategoryController@addOrRemoveCategoryFromFeature(Request)
    → Inline validation: id required|integer|exists:categories,id
    → Category::find($id)
    → $category->is_featured = !$category->is_featured
    → $category->save()
    → $this->apiResponse(CATEGORY_FEATURE_TOGGLED_SUCCESSFULLY, 200, true)

GET /featured-categories
  → CategoryController@fetchFeaturedCategories(Request)
    → $this->repository->with(['products'])->withCount('products')
      → orderByDesc('products_count')->limit($limit)
    → CategoryResource::collection($categories)
    → $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, ...)
```

### Public Controller (`App\Http\Controllers\Api\General\CategoryController`)
**File:** `app/Http/Controllers/Api/General/CategoryController.php`

```
GET /general/categories
  → CategoryController@index(Request)
    → If slug query param: delegate to getCategoryBySlug()
    → CategoryService::paginate($request)
      → Category::active()->withCount('products')
      → Filter by categoriesId (comma-separated or array)
      → Search by name/details (translatable LIKE)
      → If parent=true: whereNull('parent_id')
      → If pest_category: orderBy('products_count', $order)
      → Else: orderBy('id', $order)
      → paginate($limit)
    → CategoryHomeResource::collection($categories)
    → $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, ...)

GET /general/categories/{slug}
  → CategoryController@getCategoryBySlug($slug)
    → CategoryService::getBySlug($slug)
      → Category::active()->with(['products' => channel filter, 'children' => active withCount])
      → withCount('products')
      → where('slug', $slug)->firstOrFail()
      → ProductService::enrichCollectionWithPricing($category->products)
    → CategoryWithChildResource::make($category)
    → $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, ...)
    → If not found: $this->apiResponse(NOT_FOUND, 404, false)
```

## Repository

**File:** `packages/marvel/src/Database/Repositories/CategoryRepository.php`
**Extends:** `BaseRepository`

| Method | Description |
|--------|-------------|
| `model()` | Returns `Category::class` |
| `boot()` | Pushes `RequestCriteria` for search/filter |
| `saveCategory($request)` | Transactional create with slug, product sync, image upload |
| `updateCategory($request, $category)` | Transactional update with slug regeneration, image update, product sync |

**Field searchable:** `name => 'like'`
**Data array:** `name, slug, details, parent_id, is_featured, status`

### `saveCategory()` Flow
```
1. DB::beginTransaction()
2. Generate slug via makeSlug($request)
3. $data = $request->only(['name', 'slug', 'details', 'parent_id', 'is_featured', 'status'])
4. $this->create($data)  → CategoryHierarchyService::syncHierarchy() via model saving event
   → Calculates level from parent_id
   → Validates no self-parent or cycle
5. If products[]: $category->products()->sync($products)
6. If image-desktop: uploadSingleImage('categories-desktop', 'categories')
7. If image-mobile: uploadSingleImage('categories-mobile', 'categories')
8. DB::commit()
9. Return $category

On error:
  - HttpException(422): Image upload failed
  - HttpException(500): Generic failure (rollback)
```

### `updateCategory()` Flow
```
1. DB::beginTransaction()
2. $data = $request->only(['name', 'slug', 'details', 'parent_id', 'is_featured', 'status'])
3. If name provided: regenerate slug via makeSlug() with update ID
4. $category->update($data)  → CategoryHierarchyService::syncHierarchy() via model saving event
   → If parent_id changes: updateDescendantLevels() via model saved event
5. If image-desktop: updateSingleImage() [clears + uploads]
6. If image-mobile: updateSingleImage() [clears + uploads]
7. If products[]: $category->products()->sync($products)
8. DB::commit()
9. Return $this->findOrFail($category->id)

On error:
  - HttpException(422): Image upload failed
  - HttpException(500): Generic failure (rollback)
```

## CategoryHierarchyService

**File:** `app/Services/General/CategoryHierarchyService.php`

| Method | Description |
|--------|-------------|
| `calculateLevel(?int $parentId)` | Returns level = parent.level + 1, or 1 if no parent |
| `syncHierarchy(Category $category)` | Called on model `saving`. Validates hierarchy and sets level |
| `ensureHierarchyIsValid($category, $parentId)` | Validates: not self-parent, no cycle |
| `createsCycle(int $categoryId, int $parentId)` | Traverses ancestry to detect circular references |
| `updateDescendantLevels(Category $category)` | Recursively updates levels of all descendants when parent changes |
| `loadRecursiveChildren(Collection $categories, bool $activeOnly)` | Eager-loads full multi-level child tree |
| `loadDirectChildren(Category $category, bool $activeOnly)` | Loads one level of children |
| `loadRecursiveTree(Category $category, bool $activeOnly)` | Loads full recursive tree from a root |

## Model

**File:** `packages/marvel/src/Database/Models/Category.php`
**Table:** `categories`
**Traits:** `HasTranslations`, `InteractsWithMedia`, `SoftDeletes`
**Implements:** `HasMedia`

| Property | Details |
|----------|---------|
| Translatable | `name`, `details` |
| Fillable | `name`, `details`, `slug`, `is_featured`, `parent_id`, `level`, `status` |
| Casts | `parent_id => integer`, `level => integer`, `status => boolean`, `is_featured => boolean` |

### Scopes

| Scope | Description |
|-------|-------------|
| `scopeActive($q)` | `where('status', 1)` |
| `scopeInactive($q)` | `where('status', 0)` |
| `scopeSearch($q, $field, $term, $locale)` | Searches translatable fields with `like` on both `{field}->{locale}` and raw `{field}` |

### Model Events (booted)

| Event | Behavior |
|-------|----------|
| `saving` (1) | `CategoryHierarchyService::syncHierarchy()` — calculates level, validates hierarchy |
| `saving` (2) | If `name` is dirty but `slug` is not, auto-generate slug from English name via `Str::slug()` |
| `saved` | If `parent_id` changed, calls `CategoryHierarchyService::updateDescendantLevels()` recursively |
| `retrieved` | If slug starts with `{` (JSON-encoded), decode and extract English slug |

### Relationships

| Relation | Type | Foreign Key | Notes |
|----------|------|-------------|-------|
| `products()` | BelongsToMany | `category_product` | Pivot: `category_product` |
| `children()` | HasMany | `parent_id` | Self-referencing |
| `parent()` | BelongsTo | `parent_id` | Self-referencing |

## Resources

### Admin Resource (`Marvel\Http\Resources\CategoryResource`)
**File:** `packages/marvel/src/Http/Resources/CategoryResource.php`

```json
{
  "id": "integer",
  "name": "translated string",
  "slug": "string",
  "parent_id": "integer|null",
  "level": "integer",
  "image": {
    "desktop": "media url | null",
    "mobile": "media url | null"
  },
  "is_featured": "boolean",
  "products_count": "integer",
  "status": "boolean",
  "details": "translated string",       // omitted in index
  "children": "[...]",                   // when loaded and not empty
  "products": "[...]"                    // when loaded (id, name, slug, status, image.thumbnail)
}
```

### ChildrenCategoryResource (`Marvel\Http\Resources\ChildrenCategoryResource`)
```json
{
  "id": "integer",
  "name": "translated string",
  "slug": "string",
  "products_count": "integer",
  "image": { "desktop": "...", "mobile": "..." }
}
```

### Public Resources

**CategoryHomeResource** (`app/Http/Resources/Category/CategoryHomeResource.php`):
```json
{
  "id": "integer",
  "name": "translated string",
  "slug": "string",
  "image": { "desktop": "...", "mobile": "..." },
  "products_count": "integer",
  "details": "translated string"      // when exists
}
```

**CategoryWithChildResource** (`app/Http/Resources/Category/CategoryWithChildResource.php`):
```json
{
  "id": "integer",
  "name": "translated string",
  "slug": "string",
  "image": { "desktop": "...", "mobile": "..." },
  "products_count": "integer",
  "details": "translated string",        // when exists
  "children": "[CategoryHomeResource]",  // when loaded and not empty
  "products": "[ProductMiniResource]"    // when loaded and not empty
}
```

## Request Validation

### CategoryCreateRequest (`Marvel\Http\Requests\CategoryCreateRequest`)

**File:** `packages/marvel/src/Http/Requests/CategoryCreateRequest.php`

| Field | Rules |
|-------|-------|
| `name` | `required`, `array` |
| `name.*` | `required`, `string`, `UniqueTranslationRule::for('categories', 'name')` |
| `image-desktop` | `required`, `file`, `mimes:jpeg,png,jpg,gif,svg`, `max:2048` |
| `image-mobile` | `required`, `file`, `mimes:jpeg,png,jpg,gif,svg`, `max:2048` |
| `parent_id` | `nullable`, `integer`, `exists:categories,id` |
| `details` | `sometimes`, `string`, `min:3`, `max:2500` |
| `products` | `sometimes`, `array` |
| `products.*` | `exists:products,id` |

**Note:** `details` is a plain string (not translatable array) in the create request, unlike brands where it's an array.

### CategoryUpdateRequest (`Marvel\Http\Requests\CategoryUpdateRequest`)

**File:** `packages/marvel/src/Http/Requests/CategoryUpdateRequest.php`

| Field | Rules |
|-------|-------|
| `name` | `sometimes`, `array` |
| `name.*` | `sometimes`, `string`, `UniqueTranslationRule::for('categories')->ignore($id)` |
| `image-desktop` | `sometimes`, `file`, `mimes:jpeg,png,jpg,gif,svg`, `max:2048` |
| `image-mobile` | `sometimes`, `file`, `mimes:jpeg,png,jpg,gif,svg`, `max:2048` |
| `parent_id` | `nullable`, `integer`, `exists:categories,id`, **custom: prevents circular reference** |
| `details` | `sometimes`, `string`, `min:3`, `max:2500` |
| `products` | `sometimes`, `array` |
| `products.*` | `exists:products,id` |
| `status` | `sometimes`, `in:0,1` |

**Key difference from brands:** `parent_id` has a custom validation rule that checks for circular references via `CategoryHierarchyService::createsCycle()`.

## Observer

**File:** `app/Observers/CategoryObserver.php`
**Registered in:** `AppServiceProvider` or `EventServiceProvider`

| Event | Behavior |
|-------|----------|
| `created` | Logs activity: `category_created` |
| `updated` | If status changed: logs `category_activated` or `category_deactivated`. If other fields changed: logs `category_updated` with old/new values. Skips logging if only `updated_at` is dirty. |
| `deleted` | Logs activity: `category_deleted` |

All observer logging dispatches `LogActivityJob` (queued).

## Media Handling

**Trait:** `Marvel\Traits\MediaManager`

**Disk:** `categories` (local, `storage/app/public/categories`)

**Collections:**

| Collection | Type | Upload Method |
|------------|------|---------------|
| `categories-desktop` | Single image | `uploadSingleImage()` on create, `updateSingleImage()` on update |
| `categories-mobile` | Single image | `uploadSingleImage()` on create, `updateSingleImage()` on update |

`updateSingleImage()` clears the entire collection before uploading the new file.

## Hierarchy & Level System

Categories maintain a self-referencing hierarchy with automatic level calculation:

```
Root Category (level=1, parent_id=null)
  ├── Child Category (level=2, parent_id=root.id)
  │     └── Grandchild Category (level=3, parent_id=child.id)
  └── Another Child (level=2, parent_id=root.id)
```

**Rules:**
- A category cannot be its own parent
- A category cannot be assigned to one of its descendants (cycle prevention)
- When `parent_id` changes, all descendant levels are updated recursively
- Level is calculated as `parent.level + 1`, root categories have `level = 1`
- `parent_id` FK uses `RESTRICT ON DELETE` — cannot delete a category that has children
- Child categories are soft-deleted independently (no cascade)

## Database Schema

### Table: `categories`
**Migration:** `packages/marvel/database/migrations/2020_06_02_051901_create_marvel_tables.php`

| Column | Type | Constraints |
|--------|------|-------------|
| `id` | bigint unsigned | PK, auto-increment |
| `name` | string | NOT NULL, UNIQUE |
| `slug` | string | NOT NULL |
| `details` | text | NULLABLE |
| `parent_id` | bigint unsigned | NULLABLE, FK → categories.id RESTRICT ON DELETE |
| `status` | boolean | DEFAULT true |
| `is_featured` | boolean | DEFAULT false |
| `level` | unsigned smallint | DEFAULT 1, indexed |
| `created_at` | timestamp | NULLABLE |
| `updated_at` | timestamp | NULLABLE |
| `deleted_at` | timestamp | NULLABLE (soft deletes) |

**Indexes:** `name` (index), `level` (index)

### Table: `category_product` (pivot)

| Column | Type | Constraints |
|--------|------|-------------|
| `id` | bigint unsigned | PK, auto-increment |
| `category_id` | bigint unsigned | FK → categories.id ON DELETE CASCADE |
| `product_id` | bigint unsigned | FK → products.id ON DELETE CASCADE |

**Indexes:**
- `UNIQUE (category_id, product_id)` — prevents duplicates
- `INDEX (product_id, category_id)` — reverse lookup performance
- `INDEX (category_id, product_id)` — forward lookup performance

### Foreign Key Behavior

| FK | ON DELETE | ON UPDATE |
|----|-----------|-----------|
| `parent_id` → `categories.id` | **RESTRICT** | — |
| `category_product.category_id` → `categories.id` | **CASCADE** | — |
| `category_product.product_id` → `products.id` | **CASCADE** | — |

**Key difference from brands:** Parent FK uses RESTRICT (cannot delete a category that has children). Brand pivot uses CASCADE.

## Soft Deletes & Cascade Behavior

- Categories use `SoftDeletes` — calling `delete()` sets `deleted_at`.
- If a category has children, delete throws `QueryException` → caught as `CANNOT_DELETE_CATEGORY_WITH_ASSOCIATED_RESOURCES`.
- Pivot records in `category_product` are **hard-deleted** on force delete (FK ON DELETE CASCADE).
- Pivot records are **preserved** on soft delete.
- Media files are preserved on soft delete, removed on force delete.

## Import / Export

### Import: CategoriesSheetImport
**File:** `packages/marvel/src/Imports/Sheets/CategoriesSheetImport.php`
- Sheet title: `categories`
- Groups rows by `product_sku`
- Calls `ProductImportService::syncCategories($sku, $slugs)` to associate categories via slugs

### Export: CategoriesSheetExport
**File:** `packages/marvel/src/Exports/Sheets/CategoriesSheetExport.php`
- Sheet title: `categories`
- Columns: `product_sku`, `category_slug`
- Supports filtering by `category_id`
- Iterates all products with their associated categories

## Product Engine Strategy

**File:** `app/Services/General/ProductEngine/Strategies/ProductForCategory.php`

Part of the Product Engine strategy pattern. Delegates to `ProductService::getCategoriesProductsByQtySet()` for fetching products filtered by category with quantity limits.

## Permissions

**Enum:** `Marvel\Enums\Permission`

| Constant | Value |
|----------|-------|
| `VIEW_CATEGORIES` | `view-categories` |
| `VIEW_CATEGORY` | `view-category` |
| `CREATE_CATEGORY` | `create-category` |
| `UPDATE_CATEGORY` | `update-category` |
| `DELETE_CATEGORY` | `delete-category` |

## Constants

**File:** `packages/marvel/config/constants.php`

```php
define('CATEGORY_CREATED_SUCCESSFULLY', APP_NOTICE_DOMAIN . 'MESSAGE.CATEGORY_CREATED_SUCCESSFULLY');
define('CATEGORY_UPDATED_SUCCESSFULLY', APP_NOTICE_DOMAIN . 'MESSAGE.CATEGORY_UPDATED_SUCCESSFULLY');
define('CATEGORY_DELETED_SUCCESSFULLY', APP_NOTICE_DOMAIN . 'MESSAGE.CATEGORY_DELETED_SUCCESSFULLY');
define('CATEGORY_FEATURE_TOGGLED_SUCCESSFULLY', APP_NOTICE_DOMAIN . 'MESSAGE.CATEGORY_FEATURE_TOGGLED_SUCCESSFULLY');
define('CANNOT_DELETE_CATEGORY_WITH_ASSOCIATED_RESOURCES', APP_NOTICE_DOMAIN . 'ERROR.CANNOT_DELETE_CATEGORY_WITH_ASSOCIATED_RESOURCES');
```

## Seeders

### CategorySeeder
**File:** `database/seeders/CategorySeeder.php`
- Seeds 3-level deep cosmetics categories (Face, Eyes, Lips, Skincare, Brushes & Tools, Fragrance)
- Bilingual names/details (en/ar)
- Recursive seeding logic for hierarchy
- Media image assignment for each category

### CategoryProductSeeder
**File:** `database/seeders/CategoryProductSeeder.php`
- Maps categories to products based on SKU prefix patterns
- Seeds many-to-many relationships

## Dependencies

| File | Role |
|------|------|
| `packages/marvel/src/Rest/Routes.php` | Admin route definitions |
| `routes/api.php` | Public route definitions |
| `packages/marvel/src/Http/Controllers/CategoryController.php` | Admin controller |
| `app/Http/Controllers/Api/General/CategoryController.php` | Public controller |
| `packages/marvel/src/Http/Requests/CategoryCreateRequest.php` | Create validation |
| `packages/marvel/src/Http/Requests/CategoryUpdateRequest.php` | Update validation |
| `packages/marvel/src/Http/Resources/CategoryResource.php` | Admin API resource |
| `packages/marvel/src/Http/Resources/ChildrenCategoryResource.php` | Children resource |
| `packages/marvel/src/Http/Resources/CategoryCollection.php` | Collection resource |
| `app/Http/Resources/Category/CategoryHomeResource.php` | Public listing resource |
| `app/Http/Resources/Category/CategoryWithChildResource.php` | Public detail resource |
| `app/Http/Resources/Category/CategoryWithChildNameResource.php` | Navbar resource |
| `app/Http/Resources/Category/CategoryNavbarResource.php` | Navbar resource |
| `app/Http/Resources/Product/ProductMiniResource.php` | Product mini resource |
| `packages/marvel/src/Database/Models/Category.php` | Model |
| `packages/marvel/src/Database/Repositories/CategoryRepository.php` | Repository |
| `packages/marvel/src/Database/Repositories/BaseRepository.php` | Base repository |
| `app/Services/General/CategoryService.php` | Public category service |
| `app/Services/General/CategoryHierarchyService.php` | Hierarchy + cycle detection |
| `app/Services/General/ProductService.php` | Product enrichment |
| `app/Services/General/ProductEngine/Strategies/ProductForCategory.php` | Product engine strategy |
| `app/Observers/CategoryObserver.php` | Activity logging |
| `packages/marvel/src/Enums/Permission.php` | Permissions enum |
| `packages/marvel/config/constants.php` | Response message constants |
| `packages/marvel/src/Traits/MediaManager.php` | Image upload trait |
| `app/Traits/HasChannelFilter.php` | Channel filtering trait |
| `packages/marvel/database/migrations/2020_06_02_051901_create_marvel_tables.php` | Categories + pivot migration |
| `packages/marvel/database/migrations/2026_05_18_000001_add_level_to_categories_table.php` | Level column migration |
| `packages/marvel/database/migrations/2026_07_18_000002_add_unique_constraint_to_category_product.php` | Pivot unique + dedup |
| `database/seeders/CategorySeeder.php` | Category seeder |
| `database/seeders/CategoryProductSeeder.php` | Category-product pivot seeder |
| `packages/marvel/src/Imports/Sheets/CategoriesSheetImport.php` | Excel import |
| `packages/marvel/src/Exports/Sheets/CategoriesSheetExport.php` | Excel export |
| `tests/Feature/Categories/CategoryCrudTest.php` | CRUD tests |
| `tests/Feature/Categories/CategoryValidationTest.php` | Validation tests |
| `tests/Feature/Categories/CategoryAuthorizationTest.php` | Authorization tests |
| `tests/Feature/Categories/CategoryAuthenticationTest.php` | Auth tests |
| `tests/Feature/Categories/CategorySoftDeleteTest.php` | Soft delete tests |
| `tests/Feature/Categories/CategoryTranslationTest.php` | Translation tests |
| `tests/Feature/Categories/CategoryRelationshipTest.php` | Relationship tests |
| `tests/Feature/Categories/CategoryResourceTest.php` | Resource structure tests |
| `tests/Feature/Categories/CategoryPivotUniqueTest.php` | Pivot unique tests |
| `tests/Feature/Categories/CategoryFeaturedTest.php` | Featured toggle tests |
| `tests/Feature/Categories/CategoryMediaTest.php` | Media tests |
| `tests/Feature/Categories/CategoryMediaLifecycleTest.php` | Media lifecycle tests |
| `tests/Feature/Categories/CategoryRegressionTest.php` | Regression tests |

## Translation Keys Used

| Key | Context |
|-----|---------|
| `MESSAGE.CATEGORY_CREATED_SUCCESSFULLY` | POST response |
| `MESSAGE.CATEGORY_UPDATED_SUCCESSFULLY` | PUT response |
| `MESSAGE.CATEGORY_DELETED_SUCCESSFULLY` | DELETE response |
| `MESSAGE.CATEGORY_FEATURE_TOGGLED_SUCCESSFULLY` | PUT /feature response |
| `MESSAGE.FETCH_DATA_SUCCESSFULLY` | GET response |
| `ERROR.NOT_FOUND` | 404 error |
| `ERROR.CANNOT_DELETE_CATEGORY_WITH_ASSOCIATED_RESOURCES` | Delete with children error |
| `activity.category_created` | Observer: create |
| `activity.category_updated` | Observer: update |
| `activity.category_deleted` | Observer: delete |
| `activity.category_activated` | Observer: status change to active |
| `activity.category_deactivated` | Observer: status change to inactive |
