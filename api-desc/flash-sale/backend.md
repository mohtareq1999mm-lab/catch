# Flash Sale Module — Backend Architecture

## Overview

The Flash Sale module manages time-limited discount campaigns. Flash sales are translatable (title, description), support desktop + mobile images, maintain a sortable order, and associate with products via a many-to-many pivot. Three discount types are supported: percentage, fixed rate, and final price. The module also handles vendor requests for store participation and automatic product pricing updates via events.

## Endpoints

### Admin API (`/api/v1/flash-sale`)

| Method | URL | Auth | Permission | Purpose |
|--------|-----|------|------------|---------|
| GET | `/api/v1/flash-sale` | `auth:sanctum` | `view-flash-sale` | List flash sales (paginated, filterable, sortable) |
| POST | `/api/v1/flash-sale` | `auth:sanctum` | `create-flash-sale` | Create a new flash sale |
| GET | `/api/v1/flash-sale/{id}` | `auth:sanctum` | `view-flash-sale` | Show flash sale by ID or slug |
| PUT | `/api/v1/flash-sale/{id}` | `auth:sanctum` | `update-flash-sale` | Update flash sale |
| DELETE | `/api/v1/flash-sale/{id}` | `auth:sanctum` | `delete-flash-sale` | Soft-delete flash sale |
| PUT | `/api/v1/flash-sale/reorder` | `auth:sanctum` | `update-flash-sale` | Reorder flash sales |
| GET | `/api/v1/product-flash-sale-info` | `auth:sanctum` | `view-flash-sale` | Get flash sale info by product ID |
| GET | `/api/v1/products-by-flash-sale` | `auth:sanctum` | `view-flash-sale` | Get products by flash sale slug |

### Vendor Request API (`/api/v1/vendor-requests-for-flash-sale`)

| Method | URL | Auth | Permission | Purpose |
|--------|-----|------|------------|---------|
| GET | `/api/v1/vendor-requests-for-flash-sale` | `auth:sanctum` | — | List vendor requests |
| POST | `/api/v1/vendor-requests-for-flash-sale` | `auth:sanctum` | — | Create vendor request |
| GET | `/api/v1/vendor-requests-for-flash-sale/{id}` | `auth:sanctum` | — | Show vendor request |
| PUT | `/api/v1/vendor-requests-for-flash-sale/{id}` | `auth:sanctum` | — | Update vendor request |
| DELETE | `/api/v1/vendor-requests-for-flash-sale/{id}` | `auth:sanctum` | — | Delete vendor request |
| POST | `/api/v1/approve-flash-sale-requested-products` | `auth:sanctum` | — | Approve vendor request |
| POST | `/api/v1/disapprove-flash-sale-requested-products` | `auth:sanctum` | — | Disapprove vendor request |

### Public API (`/api/v1/general/flash-sales`)

| Method | URL | Auth | Purpose |
|--------|-----|------|---------|
| GET | `/api/v1/general/flash-sales` | Public | List active flash sales |
| GET | `/api/v1/general/flash-sales/{slug}` | Public | Get flash sale by slug with products |
| GET | `/api/v1/general/flash-sale-products` | Public | Get flash sale products by quantity set |
| GET | `/api/v1/general/flash-sale-products-ending-this-week` | Public | Products ending within 7 days |
| GET | `/api/v1/general/flash-sale-products-ending-today` | Public | Products ending today |

## Route Definitions

### Admin Routes
**File:** `packages/marvel/src/Rest/Routes.php`

```
Line 411: Route::apiResource('flash-sale', FlashSaleController::class, ['only' => ['index', 'show']]);  // public
Line 623: Route::get('products-by-flash-sale', ...);                                                      // authenticated
Line 632: Route::apiResource('vendor-requests-for-flash-sale', ..., ['only' => ['index', 'show', 'store', 'destroy']]);
Line 673: Route::put('flash-sale/reorder', [FlashSaleController::class, 'reorder']);                       // super admin
Line 675: Route::apiResource('flash-sale', FlashSaleController::class, ['only' => ['store', 'update', 'destroy']]);
Line 810: Route::get('product-flash-sale-info', ...);
Line 812: Route::post('approve-flash-sale-requested-products', ...);
Line 813: Route::post('disapprove-flash-sale-requested-products', ...);
Line 814: Route::apiResource('vendor-requests-for-flash-sale', ..., ['only' => ['update']]);
```

### Public Routes
**File:** `routes/api.php`

```
Line 56: Route::get('flash-sales', [FlashSaleController::class, 'index']);
Line 57: Route::get('flash-sales/{slug}', [FlashSaleController::class, 'getFlashSaleBySlug']);
Line 58: Route::get('flash-sale-products', [FlashSaleController::class, 'getFlashSalesAndHereProductsByQtySet']);
Line 59: Route::get('flash-sale-products-ending-this-week', ...);
Line 60: Route::get('flash-sale-products-ending-today', ...);
```

## Middleware

### Admin Controller (`Marvel\Http\Controllers\FlashSaleController`)

| Method | Middleware |
|--------|-----------|
| `index` | `permission:view-flash-sale` (via constructor) |
| `show` | `permission:view-flash-sale` (via constructor) |
| `store` | `permission:create-flash-sale` (via constructor) |
| `update` | `permission:update-flash-sale` (via constructor) |
| `destroy` | `permission:delete-flash-sale` (via constructor) |
| `reorder` | `permission:update-flash-sale` (via constructor) |
| `getFlashSaleInfoByProductID` | Inherits group auth |
| `getProductsByFlashSale` | Inherits group auth |

Auth (`auth:sanctum`) is applied at the route group level in `Routes.php`.

### Public Controller (`App\Http\Controllers\Api\General\FlashSaleController`)

No middleware — fully public access.

## Controller Flow

**File:** `packages/marvel/src/Http/Controllers/FlashSaleController.php`

```
GET /flash-sale
  → FlashSaleController@index(Request)
    → fetchFlashSales($request)
      → Filters: active(), invalid(), search('title', ...), orderBy(...)
    → $query->paginate($limit)->withQueryString()
    → FlashSaleResource::collection($flashSales)
    → $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, [...pagination data...])

POST /flash-sale
  → FlashSaleController@store(CreateFlashSaleRequest)
    → $this->repository->storeFlashSale($request)
      → DB::transaction
        → Generate slug via makeSlug($request)
        → Create flash sale
        → Upload desktop image (collection: flash-sales-desktop, disk: flashSales)
        → Upload mobile image (collection: flash-sales-mobile, disk: flashSales)
        → Sync products if provided + setProductInFlashSale()
        → Commit
      → On failure: Rollback, log error, throw HttpException(500)
    → $flashSale->load('products')
    → FlashSaleResource::make($flashSale)
    → $this->apiResponse(CREATE_FLASH_SALE_SUCCESSFULLY, 200, true, ...)

GET /flash-sale/{params}
  → FlashSaleController@show($params)
    → If numeric: find by ID, else: find by slug
    → $this->repository->with('products')->where(...)->first()
    → If not found: throw MarvelException(NOT_FOUND)
    → FlashSaleResource::make($flashSale)
    → $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, ...)

PUT /flash-sale/{id}
  → FlashSaleController@update(UpdateFlashSaleRequest, $id)
    → $request->merge(['id' => $id])
    → $this->updateFlashSale($request) [public]
      → $this->repository->updateFlashSale($request, $id)
        → DB::transaction
          → findOrFail($id)
          → Regenerate slug via makeSlug()
          → Update flash sale data
          → Update desktop/mobile images if provided
          → Sync products: unset old, set new
          → updateFlashSaleProductPrices()
          → Commit
        → On failure: Rollback, throw HttpException(500)
    → $flashSale->load('products')
    → FlashSaleResource::make($flashSale)
    → $this->apiResponse(UPDATE_FLASH_SALE_SUCCESSFULLY, 200, true, ...)

DELETE /flash-sale/{id}
  → FlashSaleController@destroy($id, Request)
    → $request->merge(['id' => $id])
    → $this->deleteFlashSale($request) [public]
      → $this->repository->findOrFail($id)
      → $flashSale->delete()  [soft delete]
    → $this->apiResponse(DELETE_FLASH_SALE_SUCCESSFULLY, 200, true)

PUT /flash-sale/reorder
  → FlashSaleController@reorder(Request)
    → $request->validate(['flash_sales' => 'required|array', 'flash_sales.*' => 'required|exists:flash_sales,id'])
    → $this->repository->reorder($request->flash_sales)
      → $this->setNewOrder($flashSales)  [Spatie SortableTrait]
    → $this->apiResponse(FLASH_SALE_REORDERED_SUCCESSFULLY, 200, true)

GET /product-flash-sale-info
  → FlashSaleController@getFlashSaleInfoByProductID(Request)
    → Product::find($request->id)
    → Return $product->flash_sales (BelongsToMany)
    → $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, ...)

GET /products-by-flash-sale
  → FlashSaleController@getProductsByFlashSale(Request)
    → fetchProductsByFlashSale($request)
      → $this->repository->where('slug', $request->slug)->firstOrFail()
      → $flashSale->products()->orderBy(...)
    → paginate($limit)
```

## Repository

**File:** `packages/marvel/src/Database/Repositories/FlashSaleRepository.php`
**Extends:** `BaseRepository` (extends `Prettus\Repository\Eloquent\BaseRepository`)

| Method | Description |
|--------|-------------|
| `model()` | Returns `FlashSale::class` |
| `modelQuery()` | Returns `FlashSale::query()` |
| `boot()` | Pushes `RequestCriteria` for search/filter |
| `storeFlashSale($request)` | Transactional create with slug, images, product sync, pricing |
| `updateFlashSale($request, $id)` | Transactional update with slug, images, product sync, pricing |
| `setProductInFlashSale($product_ids)` | Sets `has_flash_sale = true` on products |
| `unsetProductFromFlashSale($old, $new)` | Sets `has_flash_sale = false` on removed products |
| `reorder(array $flashSales)` | Delegates to `SortableTrait::setNewOrder()` |
| `updateFlashSaleProductPrices($flashSale)` | Calculates and updates `price_after_flash_sale` on products |

### `storeFlashSale()` Flow
```
1. DB::beginTransaction()
2. Generate slug via $this->makeSlug($request)
3. Extract $data from request
4. $this->create($data)
5. Upload image-desktop to 'flash-sales-desktop'
6. Upload image-mobile to 'flash-sales-mobile'
7. If products[]: $flash_sale->products()->sync($products)
8. setProductInFlashSale($products)
9. DB::commit()
10. Return $flash_sale

On error:
  - HttpException(422): Image upload failed
  - HttpException(500): Generic failure (rollback + log)
```

### `updateFlashSale()` Flow
```
1. DB::beginTransaction()
2. $flash_sale = $this->findOrFail($id)
3. Regenerate slug via makeSlug()
4. $flash_sale->update($data)
5. Update image-desktop if provided (clear + upload)
6. Update image-mobile if provided (clear + upload)
7. If products[]:
   - Get old product IDs
   - Sync new products
   - unsetProductFromFlashSale(old, new)
   - setProductInFlashSale(new)
8. updateFlashSaleProductPrices($flashSale)
9. DB::commit()
10. Return $flash_sale

On error:
  - HttpException(500): Generic failure (rollback + log)
```

### `updateFlashSaleProductPrices()` Flow
```
1. Load flash sale with products
2. Check if sale is active (status + date range)
3. If active:
   - For each product, calculate price_after_flash_sale
   - Use flashSale->calcPrice() which delegates to ProductPricingService
4. If inactive:
   - Set price_after_flash_sale = null for all products
```

## Model

**File:** `packages/marvel/src/Database/Models/FlashSale.php`
**Table:** `flash_sales`
**Traits:** `HasTranslations`, `SoftDeletes`, `InteractsWithMedia`, `SortableTrait`

| Property | Details |
|----------|---------|
| Translatable | `title`, `description` |
| Sortable | `order_column_name => 'order'`, `sort_when_creating => true` |
| Fillable | `title`, `slug`, `description`, `start_date`, `end_date`, `status`, `type`, `discount`, `max_discount_amount`, `order` |
| Casts | `status => boolean`, `start_date => date`, `end_date => date` |
| Media Collections | `flash-sales-desktop`, `flash-sales-mobile` |

### Scopes

| Scope | Description |
|-------|-------------|
| `scopeValid($q)` | `status = true AND start_date <= today AND end_date >= today` |
| `scopeInvalid($q)` | `status = false OR start_date > today OR end_date < today` |
| `scopeSearch($q, $field, $term, $locale)` | Searches translatable fields with `like` |

### Relationships

| Relation | Type | Pivot | Foreign |
|----------|------|-------|---------|
| `products()` | BelongsToMany | `flash_sale_products` (`flash_sale_id`, `product_id`) | `product_id` |
| `flashSaleRequests()` | HasMany | — | `flash_sale_id` on `flash_sale_requests` |

### Key Methods

| Method | Description |
|--------|-------------|
| `isValid()` | Returns true if status=1 and within date range |
| `calcPrice($price)` | Delegates to `ProductPricingService::calculateFlashSalePrice()` |
| `typeByLang()` | Returns translated discount type label based on locale |

## Resources

### Admin Resource (`Marvel\Http\Resources\FlashSaleResource`)

```json
{
  "id": "integer",
  "title": "translated string (raw on list, translated on detail)",
  "slug": "string",
  "image": {
    "desktop": "media url | null",
    "mobile": "media url | null"
  },
  "description": "translated string",
  "start_date": "date",
  "end_date": "date",
  "status": "boolean",
  "is_valid": "boolean",
  "type": "translated label (e.g. 'Percentage discount')",
  "discount": "decimal",
  "max_discount_amount": "decimal | null",
  "created_at": "date",
  "products": "[...]" // only when loaded, uses ProductResource
}
```

### Public Resource (`App\Http\Resources\FlashSale\FlashSaleResource`)

```json
{
  "id": "integer",
  "name": "translated title",
  "description": "translated description",
  "slug": "string",
  "start_date": "date",
  "end_date": "date",
  "image": {
    "desktop": "media url | null",
    "mobile": "media url | null"
  },
  "products": "[...]" // only when loaded, uses ProductMiniResource
}
```

## Request Validation

### CreateFlashSaleRequest

| Field | Rules |
|-------|-------|
| `title` | `required`, `array` |
| `title.*` | `required`, `string`, `min:3`, `max:70`, `unique_translation:flash_sales,title` |
| `description` | `required`, `array` |
| `description.*` | `required`, `string`, `max:1000` |
| `image-desktop` | `required`, `image`, `mimes:jpeg,png,jpg,webp` |
| `image-mobile` | `required`, `image`, `mimes:jpeg,png,jpg,webp` |
| `start_date` | `required`, `date` |
| `end_date` | `required`, `date` |
| `type` | `required`, `in:percentage,fixed_rate,final_price` |
| `discount` | `required`, `numeric`, `min:0` |
| `max_discount_amount` | `required_if:type,percentage`, `numeric`, `min:1` |
| `status` | `required`, `in:1,0` |
| `products` | `sometimes`, `array` |
| `products.*` | `integer`, `exists:products,id` |

### UpdateFlashSaleRequest

| Field | Rules |
|-------|-------|
| `title` | `sometimes`, `array` |
| `title.*` | `sometimes`, `string`, `min:3`, `max:70`, `unique_translation:flash_sales,title->ignore($id)` |
| `description` | `sometimes`, `array` |
| `description.*` | `sometimes`, `string`, `max:1000` |
| `image-desktop` | `sometimes`, `image`, `mimes:jpeg,png,jpg,webp` |
| `image-mobile` | `sometimes`, `image`, `mimes:jpeg,png,jpg,webp` |
| `start_date` | `sometimes`, `date` |
| `end_date` | `sometimes`, `date` |
| `type` | `sometimes`, `in:percentage,fixed_rate,final_price` |
| `discount` | `sometimes`, `numeric`, `min:0` |
| `max_discount_amount` | `required_if:type,percentage`, `numeric`, `min:1` |
| `status` | `sometimes`, `in:1,0` |
| `products` | `sometimes`, `array` |
| `products.*` | `integer`, `exists:products,id` |

### Flash Sale Vendor Requests

| Field | Rules |
|-------|-------|
| `title` | `required`, `string` |
| `note` | `sometimes`, `string` |
| `flash_sale_id` | `required` |
| `requested_product_ids` | Vendor request product attachments |

## Observer

**File:** `app/Observers/FlashSaleObserver.php`

| Event | Behavior |
|-------|----------|
| `created` | Logs activity: `flash_sale_created` |
| `updated` | If status changed: logs `flash_sale_activated` or `flash_sale_deactivated`. Otherwise logs `flash_sale_updated` |
| `deleted` | Logs activity: `flash_sale_deleted` |
| `restored` | Logs activity: `flash_sale_restored` |
| `forceDeleted` | Logs activity: `flash_sale_force_deleted` |

All observer logging dispatches `LogActivityJob` (queued).

## Events & Listeners

**Event:** `Marvel\Events\FlashSaleProcessed` (implements `ShouldQueue`)

**Listener:** `Marvel\Listeners\FlashSaleProductProcess` (implements `ShouldQueue`)

| Action | Behavior |
|--------|----------|
| `append_attached_products` | Updates product pricing for newly attached products, sets `has_flash_sale=true` |
| `remove_attached_products` | Clears product pricing, sets `has_flash_sale=false` |
| `delete_vendor_request` | Clears product pricing for detached products |

## Media Handling

**Trait:** `Marvel\Traits\MediaManager`

**Disk:** `flashSales` (local, `storage/app/public/flashSales`)

**Collections:**

| Collection | Type | Upload Method |
|------------|------|---------------|
| `flash-sales-desktop` | Single image | `uploadSingleImage()` on create, `updateSingleImage()` on update |
| `flash-sales-mobile` | Single image | `uploadSingleImage()` on create, `updateSingleImage()` on update |

## Database Schema

### Table: `flash_sales`

| Column | Type | Constraints |
|--------|------|-------------|
| `id` | bigint unsigned | PK, auto-increment |
| `title` | text | NOT NULL (translatable JSON) |
| `slug` | string(255) | NOT NULL |
| `description` | text | NULLABLE (translatable JSON) |
| `start_date` | date | DEFAULT today |
| `end_date` | date | NOT NULL |
| `status` | boolean | DEFAULT true |
| `type` | enum | `percentage`, `fixed_rate`, `final_price` |
| `discount` | decimal(10,2) | NULLABLE |
| `max_discount_amount` | decimal(10,2) | NULLABLE |
| `order` | integer | DEFAULT 0 |
| `deleted_at` | timestamp | NULLABLE |
| `created_at` | timestamp | NULLABLE |
| `updated_at` | timestamp | NULLABLE |

### Table: `flash_sale_products` (pivot)

| Column | Type | Constraints |
|--------|------|-------------|
| `flash_sale_id` | bigint unsigned | FK → flash_sales.id ON DELETE CASCADE |
| `product_id` | bigint unsigned | FK → products.id ON DELETE CASCADE |

**Indexes:** UNIQUE `(flash_sale_id, product_id)`

### Table: `flash_sale_requests`

| Column | Type | Constraints |
|--------|------|-------------|
| `id` | bigint unsigned | PK, auto-increment |
| `flash_sale_id` | bigint unsigned | FK → flash_sales.id |
| `title` | string | |
| `note` | text | |
| `request_status` | boolean | |
| `deleted_at` | timestamp | NULLABLE |

## Soft Deletes & Pivot Behavior

- Flash sales use `SoftDeletes` — `delete()` sets `deleted_at`
- Pivot records in `flash_sale_products` are **hard-deleted** on flash sale delete (FK `ON DELETE CASCADE`)
- `FlashSaleRequests` and `FlashSaleShop` also use `SoftDeletes`
- No restore or force-delete endpoints exposed via admin API

## Flash Sale Types

**Enum:** `Marvel\Enums\FlashSaleType`

| Constant | Value | Description |
|----------|-------|-------------|
| `PERCENTAGE` | `percentage` | Discount by percentage (requires `max_discount_amount`) |
| `FIXED_RATE` | `fixed_rate` | Fixed amount discount |
| `FINAL_PRICE` | `final_price` | Set product to a specific final price |

## Pricing Logic

The `ProductPricingService` handles flash sale price calculations:

- **percentage**: `price - (price * discount / 100)`, capped at `max_discount_amount`
- **fixed_rate**: `price - discount`
- **final_price**: `discount` (used as the final price directly)

## Constants

```php
define('CREATE_FLASH_SALE_SUCCESSFULLY',   APP_NOTICE_DOMAIN . 'MESSAGE.CREATE_FLASH_SALE_SUCCESSFULLY');
define('UPDATE_FLASH_SALE_SUCCESSFULLY',   APP_NOTICE_DOMAIN . 'MESSAGE.UPDATE_FLASH_SALE_SUCCESSFULLY');
define('DELETE_FLASH_SALE_SUCCESSFULLY',   APP_NOTICE_DOMAIN . 'MESSAGE.DELETE_FLASH_SALE_SUCCESSFULLY');
define('FLASH_SALE_REORDERED_SUCCESSFULLY', APP_NOTICE_DOMAIN . 'MESSAGE.FLASH_SALE_REORDERED_SUCCESSFULLY');
```

## Translation Keys

| Key | Context | Exists? |
|-----|---------|---------|
| `MESSAGE.CREATE_FLASH_SALE_SUCCESSFULLY` | POST response | en: ❌, ar: ✅ |
| `MESSAGE.UPDATE_FLASH_SALE_SUCCESSFULLY` | PUT response | en: ❌, ar: ✅ |
| `MESSAGE.DELETE_FLASH_SALE_SUCCESSFULLY` | DELETE response | en: ❌, ar: ✅ |
| `MESSAGE.FLASH_SALE_REORDERED_SUCCESSFULLY` | PUT /reorder response | en: ❌, ar: ✅ |
| `activity.flash_sale_created` | Observer activity log | en: ✅, ar: ✅ |
| `activity.flash_sale_updated` | Observer activity log | en: ✅, ar: ✅ |
| `activity.flash_sale_deleted` | Observer activity log | en: ✅, ar: ✅ |

## Dependencies

| File | Role |
|------|------|
| `packages/marvel/src/Rest/Routes.php` | Admin route definitions |
| `routes/api.php` | Public route definitions |
| `packages/marvel/src/Http/Controllers/FlashSaleController.php` | Admin controller |
| `app/Http/Controllers/Api/General/FlashSaleController.php` | Public controller |
| `packages/marvel/src/Http/Requests/CreateFlashSaleRequest.php` | Create validation |
| `packages/marvel/src/Http/Requests/UpdateFlashSaleRequest.php` | Update validation |
| `packages/marvel/src/Http/Resources/FlashSaleResource.php` | Admin API resource |
| `app/Http/Resources/FlashSale/FlashSaleResource.php` | Public API resource |
| `packages/marvel/src/Database/Models/FlashSale.php` | Model |
| `packages/marvel/src/Database/Models/FlashSaleRequests.php` | Vendor requests model |
| `packages/marvel/src/Database/Repositories/FlashSaleRepository.php` | Repository |
| `packages/marvel/src/Database/Repositories/FlashSaleVendorRequestRepository.php` | Vendor request repository |
| `packages/marvel/src/Database/Repositories/BaseRepository.php` | Base repository |
| `app/Services/General/FlashSaleService.php` | Public flash sale service |
| `app/Services/General/ProductService.php` | Product enrichment |
| `app/Services/Pricing/ProductPricingService.php` | Pricing calculations |
| `app/Observers/FlashSaleObserver.php` | Activity logging observer |
| `packages/marvel/src/Events/FlashSaleProcessed.php` | Event (queued) |
| `packages/marvel/src/Listeners/FlashSaleProductProcess.php` | Listener (queued) |
| `packages/marvel/src/Enums/Permission.php` | Permissions enum |
| `packages/marvel/src/Enums/FlashSaleType.php` | Flash sale type enum |
| `packages/marvel/config/constants.php` | Response message constants |
| `packages/marvel/src/Traits/MediaManager.php` | Image upload trait |
| `app/Traits/HasChannelFilter.php` | Channel filtering trait |
| `packages/marvel/database/migrations/2023_08_14_173253_create_flash_sales_table.php` | Migration |
| `database/seeders/FlashSaleSeeder.php` | Seeder |
| `packages/marvel/src/Imports/Sheets/FlashSalesSheetImport.php` | Excel import |
| `packages/marvel/src/Exports/Sheets/FlashSalesSheetExport.php` | Excel export |
| `tests/Feature/FlashSales/FlashSaleApiTest.php` | Feature tests |
| `tests/Feature/FlashSales/FlashSaleReorderTest.php` | Reorder tests |
| `tests/Feature/FlashSales/FlashSaleProductionHardenTest.php` | Production harden tests |
