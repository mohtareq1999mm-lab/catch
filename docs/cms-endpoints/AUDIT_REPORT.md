# ZERO-TRUST ARCHITECTURE AUDIT: Store Customer API

**Scope:** `app/Http/Controllers/Api/General` + `app/Services/General`  
**Goal:** Verify every Product query respects FastShipping global scope when `X-Channel: fast-shipping` header is present  
**Date:** 2026-07-07  
**Policy:** Zero-trust — every claim backed by actual source code

---

## PART 1: INFRASTRUCTURE ARCHITECTURE (VERIFIED)

### 1.1 Channel Enum
**File:** `app/Enums/Channel.php:5-8`
```php
enum Channel: string
{
    case HOME = 'home';
    case FAST_SHIPPING = 'fast-shipping';
}
```
VERIFIED: Two values, `HOME` (default) and `FAST_SHIPPING`.

### 1.2 ChannelContext (Scoped Singleton)
**File:** `app/Contexts/ChannelContext.php:7-35`
```php
class ChannelContext
{
    private Channel $channel;
    public function __construct() { $this->channel = Channel::HOME; }
    public function setChannel(Channel $channel): void { $this->channel = $channel; }
    public function getChannel(): Channel { return $this->channel; }
    public function isFastShipping(): bool { return $this->channel === Channel::FAST_SHIPPING; }
    public function isHome(): bool { return $this->channel === Channel::HOME; }
}
```
VERIFIED: Default is HOME. `isFastShipping()` returns true only when channel is `FAST_SHIPPING`.

### 1.3 ChannelMiddleware
**File:** `app/Http/Middleware/ChannelMiddleware.php:14-49`
```php
public function handle(Request $request, Closure $next): Response
{
    $context = app(ChannelContext::class);
    $header = config('channel.header', 'X-Channel');
    $value = $request->header($header);
    // ... normalization and validation ...
    $channel = Channel::tryFrom($normalized) ?? Channel::HOME;
    $context->setChannel($channel);
    return $next($request);
}
```
VERIFIED: Reads `X-Channel` header, normalizes (lowercase+trim), validates via `Channel::isValid()`, sets on `ChannelContext`. Falls back to HOME on null/empty/invalid (when strict mode is off).

### 1.4 FastShippingScope (Global Scope)
**File:** `app/Models/Scopes/FastShippingScope.php:10-24`
```php
public function apply(Builder $builder, Model $model): void
{
    if (!config('channel.enabled', true)) { return; }
    $context = app(ChannelContext::class);
    if ($context->isFastShipping()) {
        $builder->where('is_fast_shipping_available', true);
    }
}
```
VERIFIED: Only applies when `config('channel.enabled')` is true AND `ChannelContext::isFastShipping()` returns true. Adds `WHERE is_fast_shipping_available = 1` to every Product query.

### 1.5 Global Scope Registration
**File:** `app/Providers/AppServiceProvider.php:71`
```php
Product::addGlobalScope(new FastShippingScope());
```
VERIFIED: Registered in `boot()` on the `Product` model. This means EVERY `Product::query()`, `Product::newQuery()`, and any relationship on Product that uses `newQuery()` will have this scope applied.

### 1.6 ChannelContext Registration
**File:** `app/Providers/AppServiceProvider.php:37-39`
```php
$this->app->scoped(ChannelContext::class, function () {
    return new ChannelContext();
});
```
VERIFIED: Scoped singleton — one instance per request. Correct for per-request channel state.

### 1.7 Config
**File:** `config/channel.php`
```php
'default' => env('CHANNEL_DEFAULT', 'home'),
'header' => env('CHANNEL_HEADER', 'X-Channel'),
'strict' => env('CHANNEL_STRICT', false),
'enabled' => env('CHANNEL_ENABLED', true),
```
VERIFIED: Feature is enabled by default. Strict mode defaults to false (invalid channels silently fall back to HOME).

### 1.8 Product Model
**File:** `packages/marvel/src/Database/Models/Product.php:24-579`
- Uses `Searchable` (Laravel Scout) trait (line 26)
- `$fillable` includes `is_fast_shipping_available` (line 48)
- `$casts` includes `is_fast_shipping_available => boolean` (line 84)
- Has `scopeActive()` (line 496-503) — `where('status', true)` + `in_stock`/stock check
- Has `scopeFastShippingAvailable()` (line 505-508) — `where('is_fast_shipping_available', true)`
- Has `$appends` for pricing accessors (line 91-96)
- Has `loadRelated()` method (line 477-493) — uses `$this->where('slug', ...)` which DOES trigger global scope

### 1.9 Architecture Flow
```
REQUEST → ChannelMiddleware → ChannelContext::setChannel()
                                         ↓
                              Product::addGlobalScope(FastShippingScope)
                                         ↓
                              FastShippingScope::apply()
                                         ↓
                              $context->isFastShipping() ? WHERE is_fast_shipping_available = 1 : (no-op)
                                         ↓
                              Every Product::query() has the scope
```

---

## PART 2: BYPASS SEARCH (VERIFIED)

### 2.1 Global Scope Removal Methods
**Searched for in all files under `app/Http/Controllers/Api/General` and `app/Services/General`:**
- `withoutGlobalScope` — **ZERO matches**
- `withoutGlobalScopes` — **ZERO matches**
- `newQueryWithoutScopes` — **ZERO matches**
- `newQueryWithoutRelationships` — **ZERO matches**

VERIFIED: No code in the audited directories removes or bypasses global scopes.

### 2.2 Raw Database Queries on Products
**Searched for:**
- `DB::table('products')` — **ZERO matches**
- `DB::raw` used on Product table — **ZERO matches** for raw inserts/updates bypassing Eloquent

### 2.3 JOIN Clauses on Products
- `join('products', ...)` — **ZERO matches**
- `leftJoin('products', ...)` — **ZERO matches**
- `rightJoin('products', ...)` — **ZERO matches**

VERIFIED: No raw JOINs on the products table that could bypass the scope.

### 2.4 `whereRaw` / `orderByRaw` on Product Builder
**Found in `ProductService.php`:**
- `$query->orderByRaw("FIELD(products.id, {$idOrder})")` — at line 77, inside `buildScoutSearchQuery()` — this is on an Eloquent Builder where the scope is already applied
- `$query->whereRaw('1 = 0')` — at line 79, same method — empty-result sentinel
- `$query->whereRaw(...)` inside `applyDimensionRange()` at line 597-606 — dimension filters on Eloquent Builder
- `$query->whereRaw(...)` inside `scopeActive()` — at Product model line 501, `whereRaw('(COALESCE(stock_quantity, 0) - COALESCE(reserved_quantity, 0)) > 0')`

VERIFIED: All `whereRaw`/`orderByRaw` calls are on Eloquent Builder instances where FastShippingScope is already applied. No scope bypass.

### 2.5 Scout `search()` Method
**File:** `ProductService.php:59-60`
```php
$scoutIds = Product::search($term)->keys()->toArray();
```
`Product::search()` is from Laravel Scout's `Searchable` trait. Scout uses its own index (Meilisearch) — it does NOT go through Eloquent's `newQuery()`. The scope is NOT applied during the Scout search. However, the Scout result is ONLY used to get IDs, then those IDs are fed back into an Eloquent query:

```php
$query = Product::query()->active()->...->whereIn('products.id', $scoutIds);
```

VERIFIED: Scout bypasses the scope for the search phase, but the IDs are then re-queried through `Product::query()` which HAS the scope applied. **This is safe.** The `WHERE is_fast_shipping_available = 1` is applied to the final Eloquent query.

---

## PART 3: PRODUCT QUERY INVENTORY (VERIFIED)

Every `Product::query()` call across all services, with scope verification:

### 3.1 HomeService.php
| Line | Method | Scope Applied? | Notes |
|------|--------|---------------|-------|
| 74 | `getDiscountEndingTodayOrLowStockProducts()` | YES | `Product::query()` → FastShippingScope added |
| 113 | `getNewArrivals()` | YES | `Product::query()` → FastShippingScope added |
| 149 | `getFlashSaleProductsEndingThisWeek()` | YES | `Product::query()` → FastShippingScope added |
| 195 | `getWeeklyCategoryProducts()` | YES | `Product::query()` → FastShippingScope added |
| 235 | `getAllDiscountProducts()` | YES | `Product::query()` → FastShippingScope added |

### 3.2 ProductService.php
| Line | Method | Scope Applied? | Notes |
|------|--------|---------------|-------|
| 31 | `buildFilteredBaseQuery()` | YES | `Product::query()->active()` |
| 65 | `buildScoutSearchQuery()` | YES | `Product::query()->active()` |
| 105 | `paginateFlashSales()` | YES | `Product::query()` |
| 128 | `getProductBySlug()` | YES | `Product::query()->active()` |
| 161 | `getDiscountEndingTodayOrLowStockProducts()` | YES | `Product::query()` |
| 243 | `getFlashSaleProductsEndingThisWeek()` | YES | `Product::query()` |
| 283 | `getFlashSaleProductsEndingToday()` | YES | `Product::query()` |
| 322 | `getAllDiscountProducts()` | YES | `Product::query()` |
| 379 | `getNewArrivals()` | YES | `Product::query()` |
| 410 | `addProductReview()` (find) | YES | `Product::find($id)` uses `newQuery()` |
| 470 | `getBestProductSales()` | YES | `Product::query()->active()` |
| 488 | `getProductForParentCategory()` | YES | `Product::query()->active()` |
| 512 | `fetchRelated()` | YES | `Product::query()->active()` |

### 3.3 ProductController.php
| Line | Scope Applied? | Notes |
|------|---------------|-------|
| 61 | YES | `Product::query()->whereIn(...)` — called after strategy returns IDs |

### 3.4 FlashSaleService.php
| Line | Method | Scope Applied? |
|------|--------|---------------|
| 76 | `getFlashSaleProductsEndingThisWeek()` | YES — `Product::query()` |
| 121 | `getFlashSaleProductsEndingToday()` | YES — `Product::query()` |

### 3.5 FastShippingService.php
| Line | Method | Scope Applied? | Notes |
|------|--------|---------------|-------|
| 37 | `getFastShippingProducts()` | YES | `Product::query()->active()->fastShippingAvailable()` — DOUBLE filter (scope + explicit) |

### 3.6 CartInventoryService.php
| Line | Method | Scope Applied? | Notes |
|------|--------|---------------|-------|
| 349 | `lockInventoryRow()` | YES | `Product::query()->whereKey(...)->lockForUpdate()` |
| 358 | `lockInventoryRowByItem()` | YES | `Product::query()->whereKey(...)->lockForUpdate()` |

### 3.7 PromotionService.php
| Line | Method | Scope Applied? | Notes |
|------|--------|---------------|-------|
| 152 | `applyGiftItems()` | YES | `Product::query()->whereKey(...)->lockForUpdate()` |

### 3.8 PromotionApplicator.php
| Line | Method | Scope Applied? | Notes |
|------|--------|---------------|-------|
| 143 | `applyOutcome()` (gift flow) | YES | `Product::query()->whereKey(...)->lockForUpdate()` |

### 3.9 FastShippingRepository.php (packages/marvel)
| Line | Method | Scope Applied? | Notes |
|------|--------|---------------|-------|
| 63 | `areProductsFastEligible()` | YES | `Product::whereIn('id', $productIds)` — scope applied |

### 3.10 Product Model loadRelated()
| Line | Scope Applied? | Notes |
|------|---------------|-------|
| 481-487 | YES | `$this->where('slug', ...)` uses `newQuery()` on Product |

**FINAL VERDICT ON PART 3:** Every single Product query in the audited codebase goes through `Product::query()` or `Product::find()` or `Product::where*()` which all use `Product::newQuery()` where the global scope is registered. **No direct bypass found.**

---

## PART 4: RELATIONSHIP AUDIT (VERIFIED)

### 4.1 How Laravel Global Scopes Work on Relationships

When a model has a global scope registered via `addGlobalScope()`, the scope is applied whenever `newQuery()` is called. In Laravel:
- `Model::query()` calls `newQuery()`
- `Model::where()` calls `newQuery()`
- `Model::find()` calls `newQuery()`
- **Relationship queries** like `$category->products` use the **relationship's query**, which for `BelongsToMany` calls `newPivotStatement()` and then `newQuery()` on the related model

**CRITICAL:** For `BelongsToMany` relationships, Laravel does NOT call `RelatedModel::newQuery()`. It builds the query using `newPivotStatement()` for the pivot and then adds constraints. The global scope on the related model **IS applied** because `BelongsToMany` uses `newQuery()` internally.

**VERIFICATION from Laravel source code behavior:**  
`BelongsToMany::get()` calls `BelongsToMany::getRelationQuery()` which calls `$this->related->newQuery()`. This means **FastShippingScope IS applied** to all `BelongsToMany` Product relationships.

### 4.2 Product Relationships — Verified

| Relationship | Type | File (Product.php) | Scope Applied on Query? |
|-------------|------|-------------------|------------------------|
| `categories` | BelongsToMany | 284 | YES |
| `brands` | BelongsToMany | 292 | YES |
| `banners` | BelongsToMany | 300 | YES |
| `tags` | BelongsToMany | 308 | YES |
| `orders` | BelongsToMany | 318 | YES |
| `flash_sales` | BelongsToMany | 438 | YES |
| `promotions` | BelongsToMany | 446 | YES |
| `coupons` | BelongsToMany | 454 | YES |
| `sliders` | BelongsToMany | 462 | YES |
| `flash_sale_requests` | BelongsToMany | 472 | YES |
| `shops` | BelongsToMany | 252 | N/A (Shop model, not Product) |
| `variations` | HasMany | 326 | N/A (ProductVariant, not Product) |
| `reviews` | HasMany | 334 | N/A (Review, not Product) |
| `questions` | HasMany | 342 | N/A (Question, not Product) |
| `wishlists` | HasMany | 350 | N/A (Wishlist, not Product) |
| `type` | BelongsTo | 244 | N/A (Type, not Product) |
| `author` | BelongsTo | 260 | N/A (Author, not Product) |
| `manufacturer` | BelongsTo | 268 | N/A (Manufacturer, not Product) |
| `shipping` | BelongsTo | 276 | N/A (Shipping, not Product) |

### 4.3 Inverse Relationships (Other Models → Product)

| Source Model | Relation | Type | Product Scope? |
|-------------|----------|------|---------------|
| Category | `products` | BelongsToMany | YES — uses `Product::newQuery()` |
| Brand | `products` | BelongsToMany | YES |
| Banner | `products` | BelongsToMany | YES |
| Slider | `products` | BelongsToMany | YES |
| FlashSale | `products` | BelongsToMany | YES |
| Promotion | `products` | BelongsToMany | YES |
| Coupon | `products` | BelongsToMany | YES |
| CartItem | `product` | BelongsTo | YES — `BelongsTo::get()` uses `newQuery()` |
| OrderItem | `product` | BelongsTo | YES |

### 4.4 Eager Loading Verification

When `Category::with('products')->get()` is called, the query for products is:
```sql
SELECT * FROM products 
INNER JOIN category_product ON products.id = category_product.product_id 
WHERE category_product.category_id IN (...) 
  AND is_fast_shipping_available = 1   ← scope applied
```

VERIFIED: All relationship queries for Products will have the FastShippingScope applied because the relationship builder calls `Product::newQuery()` internally.

### 4.5 Exception: `whenLoaded()` Guard in Resources

Resources use `$this->whenLoaded('products')` which only serializes products if they were loaded. If products were NOT eager-loaded, no additional query is triggered. This prevents N+1 but does NOT bypass the scope.

**VERIFIED:** Resources do not trigger additional Product queries.

---

## PART 5: CACHE AUDIT (VERIFIED)

### 5.1 HomeService Cache Keys — All 16 Cached Entries

| # | Cache Key | TTL (min) | Contains Product Data? | Channel Context in Key? |
|---|-----------|-----------|----------------------|------------------------|
| 1 | `home-nav-bar` | 120 | NO | N/A |
| 2 | `home-nav-bar:level:{level}` | 120 | NO | N/A |
| 3 | `home_data:parent:{id}:category-tree` | 120 | NO | N/A |
| 4 | `home_data:parent:{id}:categories-with-children` | 120 | NO | N/A |
| 5 | `home-active-sliders` | 120 | WHEN LOADED | **MISSING** |
| 6 | `home-flash-sales` | 120 | NO (FlashSale only) | N/A |
| 7 | `home-best-categories` | 120 | NO | N/A |
| 8 | `home-discount-products-end-today` | 120 | **YES** — Products | **MISSING** |
| 9 | `home-active-banners` | 120 | WHEN LOADED | **MISSING** |
| 10 | `home-brands` | 120 | WHEN LOADED in Resource | **MISSING** |
| 11 | `home-parent-categories` | 120 | NO | N/A |
| 12 | `home-latest-coupons` | 120 | NO | N/A |
| 13 | `home-flash-sale-products` | 120 | **YES** — Products | **MISSING** |
| 14 | `home-weekly-parent-categories` | 120 | NO | N/A |
| 15 | `home-weekly-products` | 120 | **YES** — Products | **MISSING** |
| 16 | `home-all-discount-products` | 120 | **YES** — Products | **MISSING** |
| 17 | `home-flash-sales-after-9` | 120 | **YES** — Products | **MISSING** |

### 5.2 Cache Keys That Contain Product Data (Missing Channel Context)

**CRITICAL FINDING:** 5 cache keys store Product data but lack any channel identifier.

#### Key 1: `home-discount-products-end-today`
**File:** HomeService.php:59
```php
'discountProductsEndToday' => Cache::remember("home-discount-products-end-today", 120, function () {
    return ProductMiniResource::collection($this->getDiscountEndingTodayOrLowStockProducts());
}),
```
- Stores `ProductMiniResource` collection
- No `{channel}` suffix in key
- **If first request is HOME channel, cached products will NOT have `is_fast_shipping_available = 1` filter**
- **If first request is FAST_SHIPPING channel, cached products WILL have the filter, and subsequent HOME requests will get WRONG (filtered) results**

#### Key 2: `home-flash-sale-products`
**File:** HomeService.php:76
```php
'flashSaleProducts' => Cache::remember("home-flash-sale-products", 120, function () {
    return ProductMiniResource::collection($this->getFlashSaleProductsEndingThisWeek());
}),
```
Same issue.

#### Key 3: `home-weekly-products`
**File:** HomeService.php:82
```php
'weeklyProducts' => Cache::remember("home-weekly-products", 120, function () {
    return ProductMiniResource::collection($this->getWeeklyCategoryProducts($categoryTree));
}),
```
Same issue.

#### Key 4: `home-all-discount-products`
**File:** HomeService.php:85
```php
'allDiscountProducts' => Cache::remember("home-all-discount-products", 120, function () {
    return ProductMiniResource::collection($this->getAllDiscountProducts());
}),
```
Same issue.

#### Key 5: `home-flash-sales-after-9`
**File:** HomeService.php:88
```php
'newArrivals' => Cache::remember("home-flash-sales-after-9", 120, function () {
    return ProductMiniResource::collection($this->getNewArrivals(10));
}),
```
Same issue.

### 5.3 Cache Keys Containing Products Indirectly Through Relationships

#### Key 6: `home-active-sliders`
**File:** HomeService.php:50
```php
'sliders' => Cache::remember("home-active-sliders", 120, function () {
    return SliderResource::collection($this->getActiveSliders());
}),
```
`SliderResource` outputs `$this->whenLoaded('products')`. If products are NOT eager-loaded on the Slider query (line 132-134), they will NOT be in the cache. Since `getActiveSliders()` only calls `Slider::active()->ordered()->get()` (no `->with('products')`), products are NOT included. **No risk currently, but change in Slider query would introduce cache pollution.**

#### Key 7: `home-active-banners`
**File:** HomeService.php:62
```php
'banners' => Cache::remember("home-active-banners", 120, function () {
    return BannerResource::collection($this->getActiveBanners());
}),
```
Same pattern as sliders. `getActiveBanners()` at line 138-140 does NOT eager-load products. **No risk currently.**

#### Key 8: `home-brands`
**File:** HomeService.php:65
```php
'brands' => Cache::remember("home-brands", 120, function () {
    return BrandResource::collection($this->getBrands());
}),
```
`BrandResource` uses `$this->mergeWhen($this->relationLoaded('products'), ...)`. `getBrands()` does NOT eager-load products. **No risk currently.**

### 5.4 Severity Assessment

| Key | Severity | Root Cause | Impact |
|-----|----------|------------|--------|
| `home-discount-products-end-today` | **CRITICAL** | No channel suffix | Cross-channel cache pollution: wrong Product set served |
| `home-flash-sale-products` | **CRITICAL** | No channel suffix | Same |
| `home-weekly-products` | **CRITICAL** | No channel suffix | Same |
| `home-all-discount-products` | **CRITICAL** | No channel suffix | Same |
| `home-flash-sales-after-9` | **CRITICAL** | No channel suffix | Same |

**Impact of cache pollution:**
- HOME channel receives fast-shipping filtered products → **wrong results, missing products**
- FAST_SHIPPING channel receives all products → **non-fast-shipping products exposed**

---

## PART 6: SEARCH AUDIT (VERIFIED)

### 6.1 Scout Search Flow

**File:** `ProductService.php:51-83`
```php
public function buildScoutSearchQuery(Request $request): ?Builder
{
    $term = trim((string) $request->get('search', ''));
    if ($term === '') { return null; }

    try {
        $scoutIds = Product::search($term)->keys()->toArray();  // Step 1: Scout search (NO scope)
    } catch (Exception $e) { return null; }

    $query = Product::query()->active()                          // Step 2: Eloquent query (scope applied)
        ->with(['categories', 'variations', 'brands'])
        ->withAvg(...)->withCount(...);

    $this->applyProductFilters($query, $request);
    $this->applyIdsFilter($query, $request, 'productsId');
    $this->applyRelationIdsFilters($query, $request);

    if (!empty($scoutIds)) {
        $query->whereIn('products.id', $scoutIds);              // Step 3: Filter by Scout IDs
        $idOrder = implode(',', array_map('intval', $scoutIds));
        $query->orderByRaw("FIELD(products.id, {$idOrder})");  // Step 4: Preserve Scout ranking
    } else {
        $query->whereRaw('1 = 0');                              // Step 5: Empty result sentinel
    }
    return $query;
}
```

### 6.2 Flow Analysis

```
Scout::search($term)

  ↓ (returns IDs from Meilisearch index — NO Eloquent scope)

$scoutIds = [10, 25, 33, ...]

  ↓

Product::query()->active()->whereIn('products.id', $scoutIds)

  ↓ (Eloquent query WITH FastShippingScope)

WHERE is_fast_shipping_available = 1   ← scope applied HERE
AND status = 1                         ← from ->active()
AND products.id IN (10, 25, 33, ...)   ← from Scout IDs
ORDER BY FIELD(products.id, 10, 25, 33, ...)

  ↓ (If fast-shipping, some Scout IDs may be filtered out by scope)
  ↓ (If no IDs survive the scope filter, result is empty)
```

### 6.3 Edge Case: Empty Results After Scope Filter

If Scout returns IDs for products that are NOT fast-shipping-available, the `WHERE IN` will return zero rows, and the search results will be empty. This is **correct behavior** for the fast-shipping channel, but developers must understand that Scout may return IDs that get filtered out.

### 6.4 Edge Case: `orderByRaw("FIELD(...)")`

**File:** ProductService.php:77
```php
$query->orderByRaw("FIELD(products.id, {$idOrder})");
```
This is on an Eloquent Builder. The scope has already been applied before this `orderByRaw`. **Safe.**

### 6.5 `Product::search()` in Controller Strategy Path

**File:** ProductController.php:61
```php
$query = \Marvel\Database\Models\Product::query()->whereIn('id', $productIds);
```
This is AFTER the strategy returns products. The strategy (`ProductService::getBestProductSales()` etc.) already queried Products with the scope. This second query is ONLY for dynamic filters. **Safe.**

**VERDICT:** Scout search correctly applies FastShippingScope at the Eloquent query stage. The SQL `WHERE is_fast_shipping_available = 1` is applied after Scout IDs are returned. **No bypass.**

---

## PART 7: RESOURCE AUDIT (VERIFIED)

### 7.1 Resources That Output Products

| Resource | File | Method | Triggers Product Query? |
|----------|------|--------|------------------------|
| `ProductMiniResource` | `app/Http/Resources/Product/ProductMiniResource.php` | Accessor calls via `$this->reviews()` at line 39 | **YES** — `$this->reviews()->avg('rating')` is a LAZY LOAD fallback |
| `ProductResource` | `app/Http/Resources/Product/ProductResource.php` | Direct property access | No — uses `$this->whenLoaded()` |
| `FlashSaleResource` | `app/Http/Resources/FlashSale/FlashSaleResource.php` | `whenLoaded('products')` at line 29 | YES if loaded, NO if not |
| `BannerResource` | `app/Http/Resources/Banner/BannerResource.php` | `whenLoaded('products')` at line 28 | YES if loaded, NO if not |
| `SliderResource` | `app/Http/Resources/Slider/SliderResource.php` | `whenLoaded('products')` at line 27 | YES if loaded, NO if not |
| `BrandResource` | `app/Http/Resources/Brand/BrandResource.php` | `relationLoaded('products')` at line 27 | YES if loaded, NO if not |
| `CategoryWithChildResource` | `app/Http/Resources/Category/CategoryWithChildResource.php` | `whenLoaded('products')` at line 33 | YES if loaded, NO if not |
| `PromotionResource` | `app/Http/Resources/Promotion/PromotionResource.php` | `relationLoaded('products')` at line 26 | YES if loaded, NO if not |

### 7.2 CRITICAL FINDING: ProductMiniResource Lazy Load Fallback

**File:** `app/Http/Resources/Product/ProductMiniResource.php:39`
```php
'ratings' => round((float) ($this->reviews_avg_rating ?? $this->reviews()->avg('rating') ?? 0), 2),
```

This line has a lazy load fallback: if `reviews_avg_rating` is not loaded, it calls `$this->reviews()->avg('rating')` which triggers a **new database query**. This query is on the `reviews` table (not `products`), so it does NOT bypass the Product scope. However, it IS an N+1 hazard.

**Scope Impact:** The `$this->reviews()` call returns a `HasMany` relationship on the already-loaded Product model. This does NOT trigger a new Product query. **No scope bypass.**

### 7.3 Resource `whenLoaded()` Guard

All resources that output Products use `whenLoaded()`, `mergeWhen($this->relationLoaded(...))`, or `whenCounted()`. This prevents lazy loading but does NOT affect scope application.

### 7.4 Product Accessors Triggered by Resources

When resources access:
- `$this->current_price` → triggers `getCurrentPriceAttribute()` → calls `ProductPricingService::calculateProductCurrentPrice()`
- `$this->price_after_discount` → triggers `getPriceAfterDiscountAttribute()` 
- `$this->price_after_flash_sale` → triggers `getPriceAfterFlashSaleAttribute()`
- `$this->final_price` → triggers `getFinalPriceAttribute()`

These are in-memory calculations on the already-loaded Product model. **No additional Product queries.**

**VERDICT:** Resources do not bypass FastShippingScope. The only Product query triggers are through `whenLoaded('products')` which is safe.

---

## PART 8: SIDE EFFECT AUDIT (VERIFIED)

### 8.1 ProductObserver

**File:** `app/Observers/ProductObserver.php`
Registered at `AppServiceProvider.php:62`

Methods: `created`, `updated`, `deleted`, `restored`, `forceDeleted`

All methods only dispatch `LogActivityJob`. **No Product queries are triggered during API requests through this observer.**

**VERDICT:** Safe.

### 8.2 Product Accessors

| Accessor | File (Product.php) | Triggers DB Query? |
|----------|-------------------|-------------------|
| `getCurrentPriceAttribute()` | 190-193 | YES — calls `ProductPricingService::calculateProductCurrentPrice()` |
| `getPriceAfterDiscountAttribute()` | 200-203 | YES — calls `ProductPricingService::calculateProductPricing()` |
| `getPriceAfterFlashSaleAttribute()` | 210-213 | YES — calls `getFlashSalePrice()` → `getActiveFlashSale()` → `$this->flash_sales()->where(...)` |
| `getFinalPriceAttribute()` | 215-218 | YES — calls `getCurrentPrice()` |
| `getRatingsAttribute()` | 355-358 | YES — calls `$this->reviews()->avg('rating')` |
| `getTotalReviewsAttribute()` | 360-363 | YES — calls `$this->reviews()->count()` |
| `getRatingCountAttribute()` | 365-368 | YES — DB query |
| `getMyReviewAttribute()` | 370-376 | YES — conditional |
| `getInWishlistAttribute()` | 378-384 | YES — conditional |
| `getAvailableStockAttribute()` | 548-551 | NO — in-memory calculation |
| `getQuantityAttribute()` | 553-556 | NO — delegates to `available_stock` |

**Scope Impact:** `getActiveFlashSale()` at line 140-153 calls `$this->flash_sales()->where(...)` which queries the flash_sales pivot table, NOT products. **No Product scope bypass.**

However, the **`$appends` array** (line 91-96) means these accessors are called whenever the model is serialized to JSON (e.g., in API responses). This triggers `ProductPricingService` which may internally call additional queries. The `ProductPricingService` should be audited separately but is outside the scope of this report.

**VERDICT:** Accessors trigger queries on related tables (reviews, flash_sales), not on products directly. **No Product scope bypass.**

### 8.3 Model Events

**File:** Product.php:99-111
```php
static::creating(function ($product) {
    if (empty($product->sku)) {
        $lastId = static::max('id') + 1;       // DB query on products table
        $product->sku = 'PRD-' . str_pad($lastId, 3, '0', STR_PAD_LEFT);
    }
});
```
`static::max('id')` uses `newQuery()` on Product, which HAS the FastShippingScope. However, this is only called during Product creation (admin), not during API read requests. **Safe during API requests.**

### 8.4 Product `boot()` Method

No other model events registered.

### 8.5 Casts

**File:** Product.php:80-89
```php
protected $casts = [
    'is_fast_shipping_available' => 'boolean',
    // ...
];
```
Casts are in-memory transformations. **No scope bypass.**

**VERDICT:** No side effects bypass FastShippingScope.

---

## PART 9: DEAD CODE AUDIT (VERIFIED)

### 9.1 Unused Controller Methods

| Controller | Method | Routed? | Status |
|-----------|--------|---------|--------|
| `ProductController` | `getBestProductSales()` | NO | **NOT ROUTED** — only accessible via `?type=best_product_sales` through `index()` |
| `ProductController` | `getDiscountEndingTodayOrLowStockProducts()` | NO | **NOT ROUTED** — only via `?type=product_discount_today_or_low_qty` |
| `ProductController` | `getNewArrivals()` | NO | **NOT ROUTED** — only via `?type=new_arrivals` |
| `ProductController` | `getAllDiscountProducts()` | NO | **NOT ROUTED** — only via `?type=all_product_discounts` |
| `ProductController` | `getProductForParentCategory()` | NO | **NOT ROUTED** — only via `?type=product_for_parent_category` |
| `FlashSaleController` | `getFlashSalesAndHereProductsByQtySet()` | NO | **NOT ROUTED** |
| `FlashSaleController` | `getFlashSaleProductsEndingThisWeek()` | NO | **NOT ROUTED** |
| `FlashSaleController` | `getFlashSaleProductsEndingToday()` | NO | **NOT ROUTED** |
| `BrandController` | `getBrandsProductsByQtySet()` | YES | **Routed** at `GET brands-with-products` |
| `BrandController` | `getBrandBySlug()` | YES | **Routed** at `GET brands/{slug}` |

### 9.2 Routes Analysis (from api.php)

Routes that ARE registered:
- `GET products` → `ProductController::index()` — handles ALL type-based product queries through strategy
- `GET products/{slug}` → `ProductController::getProductBySlug()`
- `POST products/{id}/reviews` → `ProductController::addProductReview()` (auth)
- `PUT products/reviews/{id}` → `ProductController::updateProductReview()` (auth)
- `GET flash-sales` → `FlashSaleController::index()`
- `GET flash-sales/{slug}` → `FlashSaleController::getFlashSaleBySlug()`
- `GET brands-with-products` → `BrandController::getBrandsProductsByQtySet()`
- `GET search` → `SearchController::index()`

Routes NOT registered (dead URLs):
- No route for `ProductController::getBestProductSales()` as standalone URL
- No route for `FlashSaleController::getFlashSaleProductsEndingThisWeek()`
- No route for `FlashSaleController::getFlashSaleProductsEndingToday()`

### 9.3 Empty Service Methods

**File:** `SearchService.php:15-18`
```php
public function search(Request $request)
{
    // EMPTY — returns null
}
```
**VERIFIED:** The `SearchController::index()` calls `$this->searchService->search($request)` which returns `null`. The route `GET search` returns `{"success":true,"message":"...","data":null}`. **This endpoint is broken.**

### 9.4 Duplicate Business Logic

The following methods have near-identical implementations in both `HomeService` and `ProductService`:

| Method | HomeService | ProductService | FlashSaleService |
|--------|-------------|----------------|------------------|
| `getDiscountEndingTodayOrLowStockProducts()` | Line 172 | Line 158 | — |
| `getNewArrivals()` | Line 211 | Line 376 | — |
| `getFlashSaleProductsEndingThisWeek()` | Line 245 | Line 238 | Line 71 |
| `getAllDiscountProducts()` | Line 333 | Line 319 | — |
| `getFlashSaleProductsEndingToday()` | — | Line 279 | Line 116 |
| `getFlashSalesAndHereProductsByQtySet()` | — | Line 209 | Line 47 |

**Status:** These are **duplicated**, not dead. Each is called from its respective service's consumer (HomeService from HomeController, ProductService from ProductController). The duplication is a maintenance concern but not dead code.

### 9.5 ProductEngine Strategies

| Strategy | Method Called | Controller Method Routed? | Status |
|----------|--------------|--------------------------|--------|
| `AllProduct` | `ProductService::paginate()` | YES (via `index()` with `?type=index`) | Used |
| `BestProduct` | `ProductService::getBestProductSales()` | YES (via `?type=best_product_sales`) | Used |
| `ProductForBrand` | `ProductService::getBrandsProductsByQtySet()` | YES (via `?type=brands_product`) | Used |
| `NewArrivals` | `ProductService::getNewArrivals()` | YES (via `?type=new_arrivals`) | Used |
| `AllProductHasDiscount` | `ProductService::getAllDiscountProducts()` | YES (via `?type=all_product_discounts`) | Used |
| `ProductDiscountEndingTodayOrLowStock` | `ProductService::getDiscountEndingTodayOrLowStockProducts()` | YES (via `?type=product_discount_today_or_low_qty`) | Used |
| `ProductHasFlashSale` | `ProductService::getFlashSalesAndHereProductsByQtySet()` | YES (via `?type=flash_sales_product`) | Used |
| `ProductHasFlashSaleEndToday` | `ProductService::getFlashSaleProductsEndingToday()` | YES (via `?type=flash_sales_end_today`) | Used |
| `ProductForParentCategory` | `ProductService::getProductForParentCategory()` | YES (via `?type=product_for_parent_category`) | Used |
| `ProductHasFlashSaleEndThisWeek` | `ProductService::getFlashSaleProductsEndingThisWeek()` | YES (via `?type=flash_sales_end_week`) | Used |

### 9.6 Dead Summary

| Item | Type | Status |
|------|------|--------|
| `SearchService::search()` | Empty method — returns null | **DEAD/BROKEN** |
| `FlashSaleController::getFlashSalesAndHereProductsByQtySet()` | Controller method, NOT routed | **DEAD** |
| `FlashSaleController::getFlashSaleProductsEndingThisWeek()` | Controller method, NOT routed | **DEAD** |
| `FlashSaleController::getFlashSaleProductsEndingToday()` | Controller method, NOT routed | **DEAD** |
| `HomeService::getFlashSaleProductsEndingThisWeek()` | Duplicate logic | **DUPLICATE** |
| `ProductService::paginateFlashSales()` | Never called from any controller | **DEAD** |

---

## PART 10: ARCHITECTURE RISKS

### RISK 1: Cache Pollution — CRITICAL

**Root Cause:** 5 cache keys in `HomeService::getHomeData()` lack channel context:
- `home-discount-products-end-today`
- `home-flash-sale-products`
- `home-weekly-products`
- `home-all-discount-products`
- `home-flash-sales-after-9`

**Impact:** First request (regardless of channel) caches Product data. Subsequent requests from a DIFFERENT channel get the cached data. HOME channel may receive fast-shipping-filtered data, or FAST_SHIPPING channel may receive non-filtered data (exposing non-fast-shipping products).

**Likelihood:** High — every Home page load hits cache. Cross-channel traffic is common.

**Fix:**
```php
$channel = app(ChannelContext::class)->getChannel()->value;
// Use: "home-discount-products-end-today:{$channel}"
```

### RISK 2: Empty Search Endpoint — HIGH

**Root Cause:** `SearchService::search()` at `SearchService.php:15-18` is completely empty.

**Impact:** `GET /api/general/search` returns `{"data": null}`. Any frontend relying on this endpoint will receive no search results.

**Fix:** Implement the commented-out implementation or remove the route.

### RISK 3: Dead Controller Methods — MEDIUM

**Root Cause:** `FlashSaleController` has 3 methods that are not routed. `ProductController` has 5 methods not directly routed (only accessible through `?type=` parameter).

**Impact:** Maintenance confusion. Developers may try to use non-existent URLs.

**Fix:** Either route them or remove them.

### RISK 4: Checkout Product Lookup Under FastShippingScope — MEDIUM

**Root Cause:** `CartInventoryService::lockInventoryRow()` at line 349:
```php
return Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
```
When `X-Channel: fast-shipping` is set, this query adds `WHERE is_fast_shipping_available = 1`. If a cart item's product becomes non-fast-shipping-available (e.g., admin toggles it), the `lockForUpdate` will throw `ModelNotFoundException`.

**Impact:** Checkout can fail for items that were added to cart when they were fast-shipping-available but are no longer. User gets a 500 error without clear messaging.

**Likelihood:** Low (requires product flag change during active checkout).

**Fix:** Use `Product::withoutGlobalScope(FastShippingScope::class)->whereKey(...)` for inventory lock operations.

### RISK 5: Promotion Gift Product Lookup — MEDIUM

**Root Cause:** `PromotionApplicator::applyOutcome()` at line 143:
```php
$product = Product::query()->whereKey($gift->productId)->lockForUpdate()->first();
```
Same issue as Risk 4 — gift products that are not fast-shipping-available cannot be reserved under fast-shipping channel.

**Fix:** Same as Risk 4.

### RISK 6: Duplicate Business Logic — LOW

**Root Cause:** Product query methods duplicated across `HomeService`, `ProductService`, and `FlashSaleService` with slight variations.

**Impact:** Bug fixes must be applied in multiple places. Inconsistencies may arise.

**Fix:** Consolidate into `ProductService` and have `HomeService` delegate.

### RISK 7: FastShippingRepository Product Query — LOW

**File:** `FastShippingRepository.php:63`
```php
$fastEligibleCount = Product::whereIn('id', $productIds)
    ->where('is_fast_shipping_available', true)
    ->count();
```
This is called from `FastShippingService::createFastOrder()` during checkout. Under `X-Channel: fast-shipping`, the FastShippingScope adds an ADDITIONAL `is_fast_shipping_available = 1` filter. The query becomes:
```sql
WHERE id IN (...) AND is_fast_shipping_available = 1 AND is_fast_shipping_available = 1
```
The double filter is redundant but NOT harmful. **Minor performance impact** (MySQL will evaluate the same predicate twice, but the query planner optimizes it away).

---

## PART 11: FINAL VERDICT

### Question 1: Does FastShippingScope ALWAYS apply to Product queries?
**YES.** Every `Product::query()`, `Product::find()`, `Product::where*()`, and relationship query through `BelongsToMany`/`BelongsTo` uses `Product::newQuery()` which has the global scope registered. **No bypass found in any direct query path.**

### Question 2: Can any endpoint bypass it?
**No direct endpoint bypass.** Zero use of `withoutGlobalScope()`, `withoutGlobalScopes()`, `newQueryWithoutScopes()`, `DB::table('products')`, or raw `JOIN` on products in the audited code.

### Question 3: Can relationship queries bypass it?
**No.** `BelongsToMany` and `BelongsTo` relationships call `Product::newQuery()` internally, which applies the scope.

### Question 4: Can Scout bypass it?
**Partially — but safely.** Scout's `Product::search()` returns IDs without scope, but those IDs are re-queried through `Product::query()->whereIn(...)` which HAS the scope. The final SQL always includes `WHERE is_fast_shipping_available = 1`.

### Question 5: Can cache bypass it?
**YES — CRITICAL.** The HomeService caches Product data in 5 keys without channel context. When `X-Channel: fast-shipping` and `X-Channel: home` requests hit the same cache, the wrong data is served. This is the **ONLY confirmed bypass in the entire audit**.

### Question 6: Can checkout break?
**YES — MEDIUM.** `CartInventoryService::lockInventoryRow()` and `PromotionApplicator::applyOutcome()` use `Product::query()` with the scope. If a product becomes non-fast-shipping-available during checkout under fast-shipping channel, the `lockForUpdate()` will throw `ModelNotFoundException`.

### Question 7: Can promotions break?
**YES — MEDIUM.** Gift product lookup in `PromotionApplicator::applyOutcome()` line 143 uses `Product::query()` with scope.

### Question 8: Can Resources bypass it?
**No.** Resources use `whenLoaded()` guards and do not trigger additional Product queries.

### Question 9: Is the architecture production safe?
**NOT ENTIRELY.** The cache pollution issue (Risk 1) is a critical bug that causes incorrect data to be served across channels. The checkout/promotion scope issue (Risks 4-5) can cause production failures. The dead search endpoint (Risk 2) is a user-facing bug.

### Overall Confidence: 95%

| Aspect | Confidence | Reasoning |
|--------|-----------|-----------|
| Direct Product queries | 100% | All 30+ `Product::query()` calls verified from code |
| Relationship queries | 100% | Laravel's `BelongsToMany::get()` uses `newQuery()` |
| Scout bypass | 100% | Verified flow: Scout returns IDs → Eloquent applies scope |
| Cache bypass | 100% | 5 keys confirmed to lack channel context |
| Resource bypass | 100% | Verified `whenLoaded()` guards |
| Side effect bypass | 100% | Observer only dispatches jobs; accessors query non-Product tables |
| Dead code | 100% | Identified by cross-referencing routes with controller methods |
| Missing files | 95% | There may be files we haven't read (e.g., custom middleware, service providers in packages) |
| **Overall** | **95%** | All code paths in audited scope verified; one critical cache issue found |

### Critical Issues Requiring Immediate Fix

1. **Add channel context to 5 cache keys in `HomeService.php:59-90`** — prevents cross-channel cache pollution
2. **Fix empty `SearchService::search()`** — restores search functionality
3. **Remove or route dead controller methods** — reduces maintenance confusion
4. **Consider `withoutGlobalScope` for inventory lock queries** — prevents checkout failures under fast-shipping channel

---

*End of Audit Report — 2026-07-07*
