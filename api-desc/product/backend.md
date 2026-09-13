# Product Module — Backend Architecture

> Dual-surface: **Admin `apiResource`** (`Marvel\ProductController` at `packages/marvel/src/Rest/Routes.php:236`) + **Storefront** (`General\ProductController` at `routes/api.php:80-84`). Verified 2026-09-13.

## Routes

### Admin — `packages/marvel/src/Rest/Routes.php`

```php
Route::middleware(['auth:sanctum','throttle:admin'])->group(function () {
    Route::post('products/bulk-delete', [ProductController::class,'destroyBulk']);
    Route::delete('products/all', [ProductController::class,'destroyAll']);
    // static segments BEFORE apiResource — do not reorder
    Route::get('products/import/sample', [ProductImportController::class,'downloadSample']);
    Route::get('products/export', [ProductExportController::class,'export']);
    Route::post('products/export', [ProductExportController::class,'export']);
    Route::get('products/export/{id}', [ProductExportController::class,'status']);
    Route::get('products/export/{id}/download', [ProductExportController::class,'download']);
    Route::post('products/import', [ProductImportController::class,'import']);
    Route::get('products/import/{id}', [ProductImportController::class,'status']);
    Route::post('products/import/{id}/cancel', [ProductImportController::class,'cancel']);
    Route::get('products/import/{id}/download-errors', [ProductImportController::class,'downloadErrors']);
    Route::apiResource('products', ProductController::class); // 236 — 5 routes

    Route::get('products/{product}/digital-assets', [DigitalAssetController::class,'index']);
    Route::post('products/{product}/digital-assets', [DigitalAssetController::class,'store']);
});
```

`apiResource` expands to `products.index|store|show|update|destroy` (`GET|POST|GET|PUT/PATCH|DELETE`). Names `products.*`. `{product}` accepts numeric id or slug via `fetchSingleProduct`. All inherit `auth:sanctum` + `throttle:admin`; per-action `permission:` is in controller constructor, not route file.

### Storefront — `routes/api.php`

```php
Route::prefix('v1/general')->middleware(['api','throttle:public-api'])->group(function () {
    Route::get('products', [ProductController::class,'index']);        // App\Http\Controllers\Api\General\ProductController
    Route::get('products/{slug}', [ProductController::class,'getProductBySlug']);
    // reviews under throttle:authenticated
    Route::middleware(['api','auth:sanctum','throttle:authenticated'])->group(...);
});
```

No auth, public cache, currency-aware. `throttle:public-api` only. `{slug}` is string slug.

## Controller Flow

### Admin `Marvel\ProductController` — `packages/marvel/src/Http/Controllers/ProductController.php`

Constructor (`__construct(ProductRepository, SettingsRepository):108-111`):

```php
$this->middleware("permission:" . Permission::VIEW_PRODUCTS, ["only"=>["index","show"]]);
$this->middleware("permission:" . Permission::CREATE_PRODUCT, ["only"=>["store"]]);
$this->middleware("permission:" . Permission::UPDATE_PRODUCT, ["only"=>["update"]]);
$this->middleware("permission:" . Permission::DELETE_PRODUCT, ["only"=>["destroy","destroyAll","destroyBulk"]]);
```

Thin controllers — mutations go to `ProductRepository`.

#### index(Request): JsonResponse
1. Parse `limit, search, orderBy, orderDir, sort, category, date_range, status` + delegated `ProductFilter` for `banner, promotion, flash_sale, slider, tags/tag`
2. `with('variations','categories','flash_sales')` + `HasChannelFilter` + `FastShippingScope` (global)
3. Scout search `Product::search(term)->keys()` when Meilisearch enabled; else `where` like on `name/description` (translatable JSON) / `sku` / variant sku
4. `paginate(limit)->withQueryString()` → `ProductCollection` (admin: `Marvel\Http\Resources\product\ProductResource` collection) → `apiResponse(FETCH_DATA_SUCCESSFULLY,200)`

#### store(ProductCreateRequest): JsonResponse
1. Validate via `ProductCreateRequest` (see api.md — 25+ rules, `UniqueTranslation` on `name`, `in:PHYSICAL,DIGITAL` on `item_type`)
2. `repository->storeProduct(request)` inside `DB::transaction` → `201` + `ProductResource::make(product)` + `CREATE_PRODUCT_SUCCESSFULLY`
3. Pricing via `ProductPricingService` + `ProductTaxPresenter` inside repository

#### show(Request, $id): JsonResponse
1. `fetchSingleProduct(request,id)` — `findOrFail` by `id` then fallback to `slug`, load `variations,categories,flash_sales,banners,sliders,brands,reviews,tags,related_products`, channel filter applied
2. `ProductResource::make(product)` + `FETCH_DATA_SUCCESSFULLY` or `MarvelException(NOT_FOUND)` → 404

#### update(ProductUpdateRequest, $id): JsonResponse
1. `ProductUpdateRequest` (`sometimes` rules, unique ignores current id, `item_type` immutability guard)
2. `repository->updateProduct(request,id)` (transaction, variant wipe+recreate, pricing recalc with fallback, media diff, `syncRelation`) → `ProductResource` + `UPDATE_PRODUCT_SUCCESSFULLY`

#### destroy(Request, $id): JsonResponse
Soft-delete (`SoftDeletes`) → `DELETE_PRODUCT_SUCCESSFULLY`.

#### destroyBulk(Request): JsonResponse
`BulkDeleteProductsRequest` (`ids:required|array|exists:products,id`) → `whereIn->delete()` hard delete → `PRODUCTS_DELETED_SUCCESSFULLY`.

#### destroyAll(): JsonResponse
Hard delete all rows → `PRODUCTS_DELETED_SUCCESSFULLY`. Throttled, intended `super_admin`.

### Storefront `General\ProductController` — `app/Http/Controllers/Api/General/ProductController.php`

Constructor: `__construct(ProductService $productService, ProductStrategyResolver $productStrategyResolver)` (DI via service container).

Trait: `ApiResponse, HasCache`.

#### index(Request): JsonResponse — `ProductIndexRequest` validated `type` + `order`
```php
public function index(Request $request): JsonResponse // 40
{
    $type  = $request->query('type');      // null → fallback
    $order = $request->query('order','desc');
    $cacheKey = $this->currencyAwareCacheKey($request);
    if ($this->shouldCache($request) && Cache::has($cacheKey)) return cached;
    $responseData = ($type && $type !== 'all')
        ? $this->buildStrategyResponse($request,$type)   // strategy path
        : $this->buildFallbackResponse($request,$order); // Scout/DB fallback
    if ($this->shouldCache($request)) Cache::put($cacheKey,$responseData, ttl);
    return $this->apiResponse(FETCH_DATA_SUCCESSFULLY,200,true,$responseData);
}
```
- `buildStrategyResponse(type)`: `ProductStrategyResolver::resolve(type)->getProducts(request)` returns `LengthAwarePaginator|Collection` of ids → `whereIn ids` → `ProductService::getDynamicFilters(whereIn query)` + `getCollectionCategories(productIds)` → returns `['data'=>ProductCollectionMini, 'filters'=>..., 'categories'=>..., 'links'=>..., 'meta'=>...]`
- `buildFallbackResponse(order)`: if `ProductService::buildScoutSearchQuery(request)` non-null → `scoutQuery->orderBy('id',order)->paginate(getLimit)` + `getDynamicFilters(clone scoutQuery)`; else `buildFilteredBaseQuery` (active + `applyChannelHomeFilter` + `applyProductFilters` + `applyIdsFilter(productsId)` + `applyRelationIdsFilters(brand/category/tag)`) → optional `orderBy('price',order_price)` → `orderBy('id',order)->paginate(getLimit)` → same enrichment via `ProductCollectionMini`, `getDynamicFilters`, `getCollectionCategories`

Helpers:
- `shouldCache(Request): bool` — false when `search` present, auth token present, or `no-cache` headers; true otherwise
- `currencyAwareCacheKey(Request): string` — `hash('sha256', path + query + CurrencyContext::current()->code + channel)` — ensures USD vs EGP listings are distinct cache entries
- `buildSimpleLinks(Request, total): array` — `first/last/prev/next` pagination URLs preserving query string
- `getCollectionCategories(productIds): array` — aggregates category facets for the current result set

#### getProductBySlug(Request): JsonResponse
1. `$slug = $request->route('slug')` → `Product::where('slug',$slug)->active()->tap(applyChannelHomeFilter)->with(relations)->withAvg('reviews','rating')->firstOrFail()`
2. `ProductService::enrichProductWithPricing(product)` → `ProductTaxPresenter` + `ConvertsProductPrice` (currency conversion)
3. `App\Http\Resources\Product\ProductResource::make(product)` + `filters: getDynamicFilters(whereIn[id])` merged (only when not on `general-product-show` guard) → `apiResponse(FETCH_DATA_SUCCESSFULLY,200)` with `HasCache` per slug+currency
4. `404 NOT_FOUND` on missing slug, soft-deleted, or channel mismatch

#### addProductReview / updateProductReview
Proxy to `ProductService::storeProductReview / updateProductReview` → `ReviewResource`. Guards `auth:sanctum` + ownership on update.

## Requests

### ProductCreateRequest — `packages/marvel/src/Http/Requests/ProductCreateRequest.php`
- `authorize(): true` (permission via controller)
- `prepareForValidation()` normalizes dimensions to string
- `rules()`: see api.md table (~30 fields, `UniqueTranslation` on `name.en/ar`, `in:PHYSICAL,DIGITAL`, `required_if:has_discount`, `required_if:has_flash_sale`, `variants.*.attribute_values exists`)

### ProductUpdateRequest — `packages/marvel/src/Http/Requests/ProductUpdateRequest.php`
Same as create but `sometimes`; `name.*` unique ignores ` $this->route('product')`; `item_type immutability` checked in repository not request.

### ProductIndexRequest — `app/Http/Requests/ProductIndexRequest.php`
```php
authorize(): true
rules(): [
  'type'  => ['sometimes','nullable', Rule::in(app(ProductStrategyResolver::class)->supportedTypes())],
  'order' => ['sometimes','nullable', Rule::in(['asc','desc'])],
]
```
`type` keys: `index, best_product_sales, brands_product, new_arrivals, all_product_discounts, product_discount_today_or_low_qty, flash_sales_product, flash_sales_end_today, flash_sales_end_week, product_for_parent_category`. `type=all` treated as null fallback. Storefront listing trusts additional filter params via `ProductService` without strict request validation (relies on `ProductFilter` scope).

## Services & Strategies

### ProductService — `app/Services/General/ProductService.php`
- `enrichProductWithPricing(Product): Product` / `enrichCollectionWithPricing(Collection): Collection` — delegates to `ProductPricingService` + `ProductTaxPresenter`
- `productRelations(): array` — `['variations.attributeProducts','categories','brands','tags','flash_sales','reviews','media']`
- `buildScoutSearchQuery(Request): ?Builder` — when `search` present and Scout configured → `Product::search(term)->query(...)`
- `buildFilteredBaseQuery(Request): Builder` — `Product::query()->active()->with(productRelations)->withAvg('reviews','rating')->tap(applyChannelHomeFilter)->tap(applyProductFilters)->tap(applyIdsFilter)->tap(applyRelationIdsFilters)`
- `applyProductFilters(Builder, Request): void` — `query->filter($request->all())` (`ProductFilter` trait scope), `applyDimensionFilters` for `height/width/length/weight` min/max, `rating_min/max` on `reviews_avg_rating`
- `applyDimensionFilters / applyDimensionRange / applyProductSearch / applyTranslatableLike` — LIKE on JSON `name->{locale}` + description + `price/sku/variant sku` when numeric
- `applyIdsFilter(Builder, Request, productsId)` / `applyRelationIdsFilters` — comma-separated ID/slug lists for `brand, category, tag, banner, slider, flash_sale`
- `getLimit(Request): int` — `(int) get('limit',15)` clamped `1..100`
- `getDynamicFilters(Builder): array` — aggregates current filtered query into facets: `price{min,max}, brands[ id,name,slug,count ], categories[ id,name,slug,count ], tags[ ... ], ratings[1..5=>count]` via subqueries clone
- `pricingService(): ProductPricingService` lazy
- `HasChannelFilter` + `FastShippingScope` mixed in

### ProductStrategyResolver — `app/Services/General/ProductEngine/ProductStrategyResolver.php`
```php
const STRATEGIES = [
  'index'                                   => AllProduct::class,
  'best_product_sales'                      => BestProduct::class,
  'brands_product'                          => ProductForBrand::class,
  'new_arrivals'                            => NewArrivals::class,
  'all_product_discounts'                   => AllProductHasDiscount::class,
  'product_discount_today_or_low_qty'       => ProductDiscountEndingTodayOrLowStock::class,
  'flash_sales_product'                     => ProductHasFlashSale::class,
  'flash_sales_end_today'                   => ProductHasFlashSaleEndToday::class,
  'flash_sales_end_week'                    => ProductHasFlashSaleEndThisWeek::class,
  'product_for_parent_category'             => ProductForParentCategory::class,
];
resolve($type): ProductTypeStrategy  // throws if unknown (validated upstream)
supportedTypes(): array => array_keys(STRATEGIES)
```
Each `*Strategy::getProducts(Request): LengthAwarePaginator|Collection` encapsulates the query for that curated set (e.g., `AllProductHasDiscount` → `where has_discount && isDiscountActive`).

### ProductPricingService — `packages/marvel/src/Services/ProductPricingService.php` (centralized per `docs/architecture/runtime-pricing-architecture.md`)
Never duplicate pricing in model/resource/controller. Methods: `calculateProductCurrentPrice`, `calculateDiscountedPrice(percentage|fixed_rate)`, `calculateFlashSalePrice`, `calculateCouponPrice`, `calculateVariantSalePrice`, `roundMoney(2)`.

## Resources

### Admin — `Marvel\Http\Resources\product\ProductResource` (`packages/marvel/src/Http/Resources/product/ProductResource.php`)
Field set (abridged): `id, name(getRawOriginal|getTranslation), slug, description, price/roundMoney, current_price, tax_enabled, tax_rate, tax(ProductTaxPresenter::describe), price_including_tax(ProductTaxPresenter::applyTo), price_after_discount, price_after_flash_sale, discount_type/amount, start/end_date, sku, stock_quantity/reserved_quantity/available_stock/quantity/sold_quantity, in_stock, status, product_type, item_type, height/width/length/weight, has_flash_sale/has_discount/discount_valid(isDiscountActive), is_fast_shipping_available, tags/brands/banners/sliders/categories/flash_sales/reviews/images/variants/related_products, created_at`. `whenLoaded` guards prevent N+1. `variants` maps `variations->map(price, current_price, stock_quantity, attributes:getAttributeName)`.

### Storefront listing — `Marvel\Http\Resources\product\ProductCollectionMini` / `App\Http\Resources\Product\ProductMiniResource` (`app/Http/Resources/Product/ProductMiniResource.php`)
Mini shape for `GET v1/general/products`: `id, name(getTranslation), slug, price(convertCatalogPrice), has_variants, item_type, current_price(convertCatalogPrice), currency(effectiveCurrency), quantity(stock_quantity int), in_stock, discount_active, flash_sale_active, is_fast_shipping_available, ratings(round reviews_avg_rating 2), tags(TagResource::collection), image[ thumbnail:getFirstMediaUrl('products'), original:getMediaImages slice(1) ]`. Uses `HasProductFilters, ConvertsProductPrice`.

### Storefront detail — `App\Http\Resources\Product\ProductResource` (`app/Http/Resources/Product/ProductResource.php`)
Full shape: `id, name, slug, description(getTranslation), price/current_price(convertCatalogPrice)+currency, discount_type/amount(formatDiscountAmount currency-aware for fixed_rate), start/end_date, sku, stock_quantity/reserved_quantity/available_stock/quantity/sold_quantity, in_stock, status, product_type/item_type, dims, has_flash_sale/has_discount/discount_valid, is_fast_shipping_available, tax_enabled/tax_rate, categories(getCategories level-sorted unique), flash_sales, brands/banners/sliders via Resource collections, tags, images(getmedia slice), variants(getVariants with convertCatalogPrice + attributes), reviews(ReviewResource::collection whenLoaded), related_products(ProductMiniResource::collection whenLoaded), mergeWhen(!routeIs general-product-show) → filters(getProductFilters)`. Similar private helpers `getCategories, getShops, getmediaImages, getVariants, getAttributeName`.

## Repository

### ProductRepository — `packages/marvel/src/Database/Repositories/ProductRepository.php`
- `storeProduct(Request)`: `DB::beginTransaction` → detect `simple/variable` from `variants` → `customSlugify` → `resolveFlashSale` → `ProductPricingService::calculate*` → `Product::create` → `addVariants` (create `ProductVariant` + `AttributeProduct` pivot) → Spatie MediaLibrary upload → `syncRelation(categories,brands,banners,sliders,tags,flash_sales)` → `DB::commit` + dashboard cache clear
- `updateProduct(Request,id)`: find → if variants present delete old then `addVariants` → recalc pricing with fallback to persisted values → image diff (delete removed, upload new) → `syncRelation` (tags replacive) → commit (note: `DB::transaction` should wrap; bug B-002 notes it currently lacks transaction)
- `customSlugify, resolveFlashSale, calculateDiscountedPrice, calculateFlashSalePrice, calculateResourcePrice, syncRelation, addVariants, hasPermission, isDiscountActive`

## Models

### Product — `packages/marvel/src/Database/Models/Product.php`
Table `products`, `SoftDeletes`, `HasTranslations(name,description)`, ~59 fillable, 25+ relations, appends `current_price, price_after_discount, price_after_flash_sale, final_price`, casts `discount_status/has_discount/has_flash_sale/in_stock boolean`, `stock/reserved/sold quantity integer`, `price float`, global scope `FastShippingScope` (filters `is_fast_shipping_available` per `ChannelContext`).

### ProductVariant — `packages/marvel/src/Database/Models/ProductVariant.php`
Table `product_variants`, `BelongsTo product`, `HasMany AttributeProduct`, fields `sku, price, sale_price, quantity, stock/reserved/sold_quantity, dims, product_id`.

### ProductFilter — `app/Services/General/ProductFilter.php`
Scope `filter(array)` used by both admin `index` and `ProductService::applyProductFilters` for `category,banner,promotion,flash_sale,slider,tags,price,status,date_range` plus numeric `price_min/max`.

## Permissions — `Marvel\Enums\Permission`

Admin `apiResource` uses `view-products, create-product, update-product, delete-product` (plus `view-low-stock-products, view-draft-products` for filtered dashboards). Product import/export use `import-product, export-product` (super_admin fallback). Storefront `v1/general/products` requires none.

## Pricing / Tax / Currency / Channel

- `ProductPricingService` is single source of truth — see `docs/architecture/runtime-pricing-architecture.md`.
- `ProductTaxPresenter` (`app/Services/Tax/ProductTaxPresenter.php`) → `describe(product, current_price)` + `applyTo(product, current_price)` for `tax` + `price_including_tax` in admin and storefront resources.
- `ConvertsProductPrice` trait (`app/Http/Resources/Product/ConvertsProductPrice.php`) converts `price/current_price/discount_amount` to `effectiveCurrency()` rate for storefront.
- `HasChannelFilter` + `ChannelContext` / `ChannelMiddleware` enforce channel scoping on both surfaces; never bypass.
- `FastShippingScope` global scope on `Product` model.

## Review Controller (supplemental)

`Marvel\Http\Controllers\ReviewController` + `App\Services\General\ProductService::storeProductReview/updateProductReview` — `GET/POST/PUT/DELETE /reviews` plus `PATCH toggle-approve` (`permission:approve-reviews`), `product_id` required on index.

## Import/Export Controllers

`ProductImportController` (`import, status, cancel, downloadErrors`) + `ProductExportController` (`export, status, download`) — all behind `auth:sanctum` + `create-product|super_admin` / `export-product`, signal-file + `ImportProductsJob` queue (`high`/`medium` per config).
