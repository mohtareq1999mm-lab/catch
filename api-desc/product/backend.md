# Product Module — Backend Architecture

## Routes

**File:** `packages/marvel/src/Rest/Routes.php`

### Public (index+show only)
```
GET    /products              → ProductController@index
GET    /products/{product}    → ProductController@show
```

### Authenticated (auth:sanctum, email.verified)
```
POST   /products              → ProductController@store       (create-product)
PUT    /products/{product}    → ProductController@update      (update-product)
DELETE /products/{product}    → ProductController@destroy     (delete-product)
POST   /products/bulk-delete  → ProductController@destroyBulk (delete-product)
DELETE /products/all          → ProductController@destroyAll  (delete-product)
PUT    /products/{id}/fast-shipping → toggleFastShipping
```

### Additional public
```
GET    /popular-products       → popularProducts
GET    /best-selling-products  → bestSellingProducts
GET    /products/calculate-rental-price → calculateRentalPrice
```

## Controller Flow

### index()
```php
public function index(Request $request): JsonResponse
```
1. Parse `limit`, `search`, `orderBy`, `orderDir`, `category`, `date_range`, `status` from request
2. Build query with `with('variations', 'categories', 'flash_sales')`
3. Apply search on translatable `name`/`description`, `sku`, and variant SKU
4. Apply `ProductFilter` service for category/banner/promotion/flash_sale/slider/tag/tags/date_range/status filters
5. Paginate, wrap in `ProductCollection`, return JSON

### store()
```php
public function store(ProductCreateRequest $request): JsonResponse
```
1. Validate via `ProductCreateRequest`
2. Call `ProductStore($request)` → `$this->repository->storeProduct($request)`
3. Return `201` + `ProductResource` + `CREATE_PRODUCT_SUCCESSFULLY`

### show()
```php
public function show(Request $request, $id): JsonResponse
```
1. Call `fetchSingleProduct($request, $id)`
2. Find by ID or slug, load `variations`, `categories`, `flash_sales`, `banners`, `sliders`, `brands`, `reviews`, `tags`, `related_products`
3. Return `200` + `ProductResource` + `FETCH_DATA_SUCCESSFULLY`

### update()
```php
public function update(ProductUpdateRequest $request, $id): JsonResponse
```
1. Validate via `ProductUpdateRequest`
2. Call `updateProduct($request)` → `$this->repository->updateProduct($request, $id)`
3. Return `200` + `ProductResource` + `UPDATE_PRODUCT_SUCCESSFULLY`

### destroy()
```php
public function destroy(Request $request, $id): JsonResponse
```
1. Soft-delete the product
2. Return `200` + `DELETE_PRODUCT_SUCCESSFULLY`

### destroyBulk()
```php
public function destroyBulk(Request $request): JsonResponse
```
1. Validate `ids` array required
2. Hard-delete all products matching IDs
3. Return `200` + `PRODUCTS_DELETED_SUCCESSFULLY`

### destroyAll()
```php
public function destroyAll(): JsonResponse
```
1. Hard-delete ALL products
2. Return `200` + `PRODUCTS_DELETED_SUCCESSFULLY`

## Review Controller

**File:** `ReviewController.php`

All review routes are auth:sanctum (except index+show which are public).

### index($request)
- Requires `product_id` query param → validates `exists:products,id`
- Paginates reviews by product

### store($request)
- Validates via `ReviewCreateRequest`: `product_id`, `comment`, `rating`
- Calls `repository->storeReview($request)`
- Returns `REVIEW_CREATED_SUCCESSFULLY`

### toggleApproveReview($id)
- Requires `approve-reviews` permission
- Calls `repository->toggleApprove($id)` (flips `approved` boolean)
- Returns `REVIEW_UPDATED_SUCCESSFULLY`

## Product Import Controller

**File:** `ProductImportController.php`

All routes require `auth:sanctum` + `create-product|super_admin`.

### import($request)
- Validates via `ProductImportRequest` (CSV/Excel file upload)
- Stores file, creates `Import` record with status `pending`
- Dispatches `ImportProductsJob`
- Returns 202 with `import_id`

### status($id)
- Returns import progress: `status`, `total_rows`, `processed_rows`, `success_rows`, `failed_rows`, `progress`
- Reads signal file for real-time progress

### cancel($id)
- Writes cancel signal file
- Updates import status → `cancelled`
- Returns 409 if import already completed/failed

### downloadErrors($id)
- If import has errors: generates XLSX download
- If no errors: returns 404

## Repository

### storeProduct($request)
1. `DB::beginTransaction()`
2. Auto-detect `simple`/`variable` product type from variants presence
3. Generate slug via `customSlugify()`
4. Resolve active flash sale via `resolveFlashSale()`
5. Calculate pricing (discount, flash sale) via `ProductPricingService`
6. Create product record
7. If variable: call `addVariants()` — creates `ProductVariant` + `AttributeProduct` pivot
8. Upload images via Spatie Media Library
9. Sync relations: categories, brands, banners, sliders, tags, flash_sales
10. `DB::commit()`, clear dashboard cache, return product with `variations`

### updateProduct($request, $id)
1. Find existing product
2. If new variants provided: delete old variants, then `addVariants()`
3. Recalculate pricing with existing values as fallback
4. Update images (delete removed, upload new)
5. Sync relations (categories, brands, banners, sliders, **tags**, flash_sales)
6. Return updated product

## Key Models

### Product
- **Table:** `products`, **SoftDeletes**, **HasTranslations** (name, description)
- **59 fillable fields**, **25+ relations**
- **Appends:** `current_price`, `price_after_discount`, `price_after_flash_sale`, `final_price`
- **Casts:** booleans (discount_status, has_discount, has_flash_sale, in_stock), integers (stock/reserved/sold quantity), float (price)
- **Global Scope:** `FastShippingScope` (auto-filters by `is_fast_shipping_available` when channel enabled)

### ProductVariant
- **Table:** `product_variants`
- BelongsTo `Product`, HasMany `AttributeProduct`
- Fields: sku, price, sale_price, quantity, stock/reserved/sold_quantity, dimensions, product_id

## Permissions

Defined in `Marvel\Enums\Permission`:
- `view-products`, `create-product`, `update-product`, `delete-product`
- `view-low-stock-products`, `view-draft-products`
- `view-tags`, `create-tags`, `update-tags`, `delete-tags` (Tag CRUD)

## Pricing Service

`ProductPricingService` handles:
- `calculateProductCurrentPrice()` — base price or variant min price
- `calculateDiscountedPrice()` — percentage or fixed_rate math
- `calculateFlashSalePrice()` — flash sale discount on top
- `calculateCouponPrice()` — coupon discount application
- `calculateVariantSalePrice()` — variant-specific pricing
- All pricing methods use `roundMoney()` to 2 decimals
