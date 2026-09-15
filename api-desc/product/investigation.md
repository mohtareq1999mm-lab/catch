# Product Module — Investigation — `Route::apiResource('products', ProductController::class)` + `GET products` + `GET products/{slug}`

**Date:** 2026-09-13 (updated — dual-surface)
**Investigated routes:**
- `Route::apiResource('products', ProductController::class)` — `packages/marvel/src/Rest/Routes.php:236` (Admin, 5 routes, `auth:sanctum`+`throttle:admin`)
- `Route::get('products', [ProductController::class,'index'])` — `routes/api.php:82` (`v1/general`, public `throttle:public-api`)
- `Route::get('products/{slug}', [ProductController::class,'getProductBySlug'])` — `routes/api.php:83` (public detail by slug)

**Ownership:** Admin `apiResource` is **Marvel-owned** kernel; storefront listing/detail is **Application-owned** (`app/`). The two `ProductController` classes are distinct and not interchangeable.

---

## 1. Route Registration & Expansion

### 1.1 Admin — `packages/marvel/src/Rest/Routes.php:236`

```php
Route::middleware(['auth:sanctum','throttle:admin'])->group(function () {
    Route::post('products/bulk-delete', [ProductController::class,'destroyBulk']);          // 223
    Route::delete('products/all', [ProductController::class,'destroyAll']);                // 224
    // static segments BEFORE apiResource — do not reorder (swallow guard)
    Route::get('products/import/sample', [ProductImportController::class,'downloadSample']); // 227
    Route::get('products/export', [ProductExportController::class,'export']);                // 228
    Route::post('products/export', [ProductExportController::class,'export']);               // 229
    Route::get('products/export/{id}', [ProductExportController::class,'status']);           // 230
    Route::get('products/export/{id}/download', [ProductExportController::class,'download']);// 231
    Route::post('products/import', [ProductImportController::class,'import']);               // 232
    Route::get('products/import/{id}', [ProductImportController::class,'status']);           // 233
    Route::post('products/import/{id}/cancel', [ProductImportController::class,'cancel']);   // 234
    Route::get('products/import/{id}/download-errors', [ProductImportController::class,'downloadErrors']); //235
    Route::apiResource('products', ProductController::class); // 236 — expands to 5
    Route::get('products/{product}/digital-assets', [DigitalAssetController::class,'index']); // 239
    Route::post('products/{product}/digital-assets', [DigitalAssetController::class,'store']); // 240
});
```

`apiResource` expands under `api/v1` prefix (service provider) to:

| # | Method | URI | Name | Action | Permission (constructor) |
|---|--------|-----|------|--------|--------------------------|
| 1 | `GET` | `/api/v1/products` | `products.index` | `Marvel\ProductController@index` | `view-products` |
| 2 | `POST` | `/api/v1/products` | `products.store` | `Marvel\ProductController@store` | `create-product` |
| 3 | `GET` | `/api/v1/products/{product}` | `products.show` | `Marvel\ProductController@show` | `view-products` |
| 4 | `PUT\|PATCH` | `/api/v1/products/{product}` | `products.update` | `Marvel\ProductController@update` | `update-product` |
| 5 | `DELETE` | `/api/v1/products/{product}` | `products.destroy` | `Marvel\ProductController@destroy` | `delete-product` |

Bespoke swallow risk mitigated: `products/all`, `products/export`, `products/import/sample` must precede the `apiResource` or `GET /products/{product}` would capture `{product}=export`.

### 1.2 Storefront — `routes/api.php:39-84`

```php
Route::prefix('v1/general')->group(function () {
    Route::middleware(['api','throttle:public-api'])->group(function () {
        Route::get('products', [ProductController::class,'index']);        // 82 — App\General
        Route::get('products/{slug}', [ProductController::class,'getProductBySlug']); // 83
    });
    Route::middleware(['api','auth:sanctum','throttle:authenticated'])->group(function () {
        Route::post('products/{id}/reviews', [ProductController::class,'addProductReview']);
        Route::put('products/reviews/{id}', [ProductController::class,'updateProductReview']);
    });
});
```

No auth, public cache, currency-aware. `type` and `order` validated by `ProductIndexRequest` (`Rule::in(supportedTypes)` / `asc,desc`). `{slug}` is string slug, not numeric, and channel-scoped.

Both surfaces coexist with same `products` prefix but distinct prefixes/middleware — middleware grouping makes routing deterministic (`php artisan route:list` shows 7 `general/products` + 5 `api/v1/products` plus the bespoke import/export rows).

---

## 2. Controller & Middleware

### Admin `Marvel\ProductController` — `packages/marvel/src/Http/Controllers/ProductController.php:104-111`

```php
public function __construct(ProductRepository $repo, SettingsRepository $settings) {
  $this->middleware("permission:".Permission::VIEW_PRODUCTS,   ["only"=>["index","show"]]);
  $this->middleware("permission:".Permission::CREATE_PRODUCT,  ["only"=>["store"]]);
  $this->middleware("permission:".Permission::UPDATE_PRODUCT,  ["only"=>["update"]]);
  $this->middleware("permission:".Permission::DELETE_PRODUCT,  ["only"=>["destroy","destroyAll","destroyBulk"]]);
}
```
Group provides `auth:sanctum`+`throttle:admin`; per-action authorization is controller middleware.

### Storefront `General\ProductController` — `app/Http/Controllers/Api/General/ProductController.php`

```php
public function __construct(private ProductService $productService, private ProductStrategyResolver $productStrategyResolver) {}
use ApiResponse, HasCache;
index(Request $request): JsonResponse        // delegates to ProductIndexRequest validation only for type/order, rest via ProductService
buildStrategyResponse(Request,string): array // resolver → ids → getDynamicFilters → categories
buildFallbackResponse(Request,string): array // Scout vs buildFilteredBaseQuery → paginate → facets
shouldCache(Request): bool                  // false when search present or authenticated
currencyAwareCacheKey(Request): string     // sha256(path+query+currency+channel)
getCollectionCategories(productIds): array
getProductBySlug(Request): JsonResponse    // slug → active+channel → with(relations)+withAvg rating → enrichPricing → ProductResource → cache
addProductReview(Request,int) / updateProductReview(ReviewUpdateRequest,int)
```

Storefront listing chooses path: `type && type !== 'all'` → `buildStrategyResponse`; else `buildFallbackResponse`. Both return `ProductCollectionMini` + `filters` + `categories` facets.

---

## 3. Requests & Validation

**`ProductCreateRequest` / `ProductUpdateRequest`** (`packages/marvel/src/Http/Requests/Product*Request.php`) — see `api.md` table: `name: UniqueTranslation`, `item_type: in:PHYSICAL,DIGITAL` default `PHYSICAL` with immutability after `order_items/digital_assets`, conditional `discount_type/amount/discount_status/start/end_date` and `flash_sale_id`, `variants.*.attribute_values exists`, dims normalized to string, `tags/brands/banners/sliders exists`. Update is `sometimes` with unique ignoring self.

**`ProductIndexRequest`** (`app/Http/Requests/ProductIndexRequest.php`):

```php
authorize(): true
rules(): [
  'type'  => ['sometimes','nullable', Rule::in(app(ProductStrategyResolver::class)->supportedTypes())],
  'order' => ['sometimes','nullable', Rule::in(['asc','desc'])],
]
```

Supported `type` keys from `ProductStrategyResolver::STRATEGIES` (10):

`index, best_product_sales, brands_product, new_arrivals, all_product_discounts, product_discount_today_or_low_qty, flash_sales_product, flash_sales_end_today, flash_sales_end_week, product_for_parent_category`

Other listing params (`limit, search, order_price, productsId, price_min/max, rating_min/max, height_min/max … weight_min/max, banner/promotion/flash_sale/slider/status/date_range, category/brands/tags`) are not strict-validated in `ProductIndexRequest` but filtered via `ProductService::applyProductFilters` → `ProductFilter` scope (see backend.md).

---

## 4. Service / Repository / Strategy

**`ProductService`** (`app/Services/General/ProductService.php`): `enrichProductWithPricing(Collection)`, `buildScoutSearchQuery(Request)` (Meilisearch `Product::search(term)` or null), `buildFilteredBaseQuery(Request)` (`active()+with(productRelations)+withAvg rating+applyChannelHomeFilter+applyProductFilters+applyIdsFilter(productsId)+applyRelationIdsFilters`), `applyProductFilters` (→ `scopeFilter` + dimension + rating ranges), `getLimit(Request)` clamped `1..100`, `getDynamicFilters(Builder)` (clones query → aggregates `price{min,max}, brands[], categories[], tags[], ratings[1..5]`), `applyChannelHomeFilter` + `FastShippingScope` + `HasChannelFilter`. Pricing delegated to lazy `ProductPricingService`.

**`ProductStrategyResolver`** (`app/Services/General/ProductEngine/ProductStrategyResolver.php`): maps `type` → `AllProduct|BestProduct|ProductForBrand|NewArrivals|AllProductHasDiscount|ProductDiscountEndingTodayOrLowStock|ProductHasFlashSale|ProductHasFlashSaleEndToday|ProductHasFlashSaleEndThisWeek|ProductForParentCategory`, each `getProducts(Request): LengthAwarePaginator|Collection` returning curated id sets.

**`ProductRepository`** (`packages/marvel/src/Database/Repositories/ProductRepository.php:67,116`): admin `storeProduct` inside `DB::transaction` (slug, `resolveFlashSale`, `ProductPricingService::calculate*`, `addVariants` + `AttributeProduct` pivots, MediaLibrary, `syncRelation` for categories/brands/banners/sliders/tags/flash_sales, dashboard cache clear). `updateProduct` similar but currently flagged B-002 for missing transaction.

**`ProductPricingService`** (`packages/marvel/src/Services/ProductPricingService.php`): single source (`docs/architecture/runtime-pricing-architecture.md`) — `calculateProductCurrentPrice`, `calculateDiscountedPrice`, `calculateFlashSalePrice`, etc. with `roundMoney(2)`.

---

## 5. Resources (Response Shapes)

**Admin:** `Marvel\Http\Resources\product\ProductResource` — `id, name(getTranslation vs getRawOriginal by route), slug, description, price/roundMoney, current_price, tax_enabled/tax_rate/tax(describe)+price_including_tax(applyTo via ProductTaxPresenter), price_after_discount/price_after_flash_sale, discount* fields, sku, stock/reserved/available/quantity/sold, in_stock, status, product_type/item_type, dims, has_flash_sale/has_discount/discount_valid, is_fast_shipping_available, tags/brands/banners/sliders/categories/flash_sales/reviews/images/variants/related_products, created_at`; variants mapped with `price/current_price/stock_quantity/attributes`.

**Storefront listing:** `ProductMiniResource` / `ProductCollectionMini` — `id, name(scalar per locale), slug, price/convertCatalogPrice, has_variants, item_type, current_price/convertCatalogPrice, currency(effectiveCurrency), quantity(stock_quantity), in_stock, discount_active/flash_sale_active, is_fast_shipping_available, ratings(round reviews_avg_rating), tags, image{thumbnail:getFirstMediaUrl('products'), original:getMediaImages slice(1) }`. Envelope adds `filters` + `categories` facets + `links`.

**Storefront detail:** `App\ProductResource` — like admin but currency-converted (`convertCatalogPrice` + `effectiveCurrency` on `price/current_price/discount_amount`), `formatDiscountAmount` includes fixed-rate currency conversion, `getVariants(convertCatalogPrice)`, `getCategories(level-sorted)`, `whenLoaded` relations, `mergeWhen(!routeIs general-product-show)` → `filters`.

---

## 6. Security / Performance / DB

**Auth:** storefront public; admin `auth:sanctum` always. `401` vs `403` via `permission:`.

**Validation:** `422` on `type/order` (storefront `ProductIndexRequest`), `item_type`, `variants`, `discount_*`, etc. `404` on unknown slug / soft-deleted / channel mismatch (`HasChannelFilter`).

**Cache:** storefront listings/details cached per `currencyAwareCacheKey`; search listings bypass cache. `HasCache` TTL configured site-wide.

**Scout:** `Product::search(term)->keys()->toArray()` preferred for large catalogs; fallback is LIKE on JSON `name/description` + `price/sku/variant sku`.

**DB:** `products` (`SoftDeletes`, JSON translations, 59 fillable, indexes `price, sold_quantity, name, slug, sku, is_fast_shipping_available, idx_status_deleted_price`), `product_variants`, `attribute_product` pivot, `category_product/brand_product/product_tag/banner_product/slider_product/flash_sale_products` pivots, `tags` MediaLibrary.

---

## 7. Risks & Verification

- **Swallow guard:** new `products/*` static segments must be inserted before `apiResource:236`.
- **`updateProduct` transaction:** add `DB::transaction` (B-002).
- **`destroyAll` hard delete:** throttle `throttle:admin`, prefer `super_admin` policy + confirmation header.
- **Translation/search:** JSON LIKE unindexed at scale — prefer Scout.
- Verify: `php artisan route:list | Select-String products`, `curl /api/v1/products` with/without token (401), with without `view-products` (403), `GET /api/v1/general/products?type=best_product_sales&limit=5`, `GET /api/v1/general/products/{slug}` for existing + unknown slug (404).
