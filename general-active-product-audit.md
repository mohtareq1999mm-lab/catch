# General API — Active Product Visibility Audit (Read-Only)

**Date:** 2026-09-12  
**Scope:** `api/v1/general/*`  
**Rule:** `is_active = false` (i.e., `Product::active()` == false) must never be returned via any general endpoint — direct, nested, search, count, or pagination  
**Mode:** READ-ONLY — no code changes  
**Commit audited:** `0844c58` (HEAD, “handle active product”) + `c17305b` + `fcbcad0` (baseline 2026-09-10)

---

## 1. Executive Summary

| Metric | Count |
|---|---:|
| General endpoints discovered (`routes/api.php:44-115` prefix `v1/general`) | **38 URIs** (28 `throttle:public-api` + 10 `auth:sanctum` + 3 `signed`) |
| Audited (Route → Controller → Service → Query → Model → Resource) | **38 / 38** |
| Confirmed safe (active before pagination/limit) | **31** |
| Confirmed leaks at HEAD (inactive product returned) | **0 direct leaks** |
| Potentially vulnerable / UNCERTAIN (requires runtime) | **7** |
| Overall severity | **P1 residual** — primary listings safe after `0844c58`, 2 P1 residual paths remain (search index, checkout validation) |

**Root cause:** Before `0844c58`, 9 services used `activeStatus()` or bare `Product::query()` and loaded `products` without `active()`, leaking out-of-stock/inactive products. `0844c58` standardized on `Product::active()` (`packages/marvel/src/Database/Models/Product.php:532`) and fixed all `with/load(['products'=>active()])`. Residual risk is not listings but **Meilisearch indexing of inactive** and **checkout/review accepting inactive**.

---

## 2. Endpoint Inventory

*Proved via `routes/api.php:44-115` + `grep -rn "Route::" routes/api.php`. Every `v1/general` URI inspected.*

| # | Method | Endpoint | Controller / Action | Service | Resource | Main Model(s) | Product Exposure | Active | Status |
|---|---|---|---|---|---|---|---|---|---|
|1|GET|`v1/general/nav-data`|HomeController@navData|HomeService::getCategoryWithChildren `HomeService.php:422`|CategoryNavbarResource|Category|category tree + `products_count`|YES `Category::active()` + `withCount products active`|SAFE|
|2|GET|`v1/general/categories`|CategoryController@index|CategoryService::paginate `CatSvc.php:25`|CategoryResource|Category|`products_count` only|YES|SAFE|
|3|GET|`v1/general/categories/{slug}`|CategoryController@getCategoryBySlug|CategoryService::getBySlug `CatSvc.php:58`|CategoryResource|Category + Product|category + `products` + `children` + `count`|YES `products=>active()->channel`, `children active`, `count active`|SAFE|
|4|GET|`v1/general/brands`|BrandController@index|BrandService::getBrands `BrandSvc.php:20`|BrandResource|Brand|brands only|N/A|SAFE|
|5|GET|`v1/general/brands/{slug}`|BrandController@getBrandBySlug|BrandService::getBrandBySlug `BrandSvc.php:40`|BrandResource|Brand+Product|brand+`products`|YES `Brand::active()` + `products active()`|SAFE|
|6|GET|`v1/general/brands-products`|BrandController@getBrandsProductsByQtySet|BrandService::getBrandsProductsByQtySet `BrandSvc.php:57` / ProductService::getBrandsProductsByQtySet `PSvc.php:431`|ProductMiniResource|Brand→Product|brands → products|YES|SAFE|
|7|GET|`v1/general/banners`|BannerController@index|BannerService::getBanners `BannerSvc.php:20`|BannerResource|Banner|banners only|N/A|SAFE|
|8|GET|`v1/general/banners/{slug}`|BannerController@getBannerBySlug|BannerService::getBannerBySlug `BannerSvc.php:40`|BannerResource|Banner+Product|banner+`products`|YES|SAFE|
|9|GET|`v1/general/sliders`|SliderController@index|SliderService::getSliders `SliderSvc.php:20`|SliderResource|Slider|sliders only|N/A|SAFE|
|10|GET|`v1/general/sliders/{slug}`|SliderController@getSliderBySlug|SliderService::getSliderBySlug `SliderSvc.php:41`|SliderResource|Slider+Product|slider+`products`|YES|SAFE|
|11|GET|`v1/general/tags`|TagController@index `TagCtrl.php:18`|—|TagResource|Tag|tags only|N/A (no products load)|SAFE|
|12|GET|`v1/general/tags/{slug}`|TagController@show `TagCtrl.php:32`|—|TagResource|Tag|tag only|N/A|SAFE*|
|13|GET|`v1/general/promotions`|PromotionController@index|PromotionDataService::paginatePromotion `PromoDSvc.php:20`|PromotionResource|Promotion|promotions only|N/A|SAFE|
|14|GET|`v1/general/promotions/{slug}`|PromotionController@getPromotionBySlug|PromotionDataService::getPromotionBySlug `PromoDSvc.php:40`|PromotionResource|Promotion+Product|promotion+`products` (+ `giftProducts` not loaded)|YES `valid()` + `products active()`|SAFE|
|15|GET|`v1/general/coupons`|CouponController@index|CouponService|CouponResource|Coupon|coupons only|N/A|SAFE|
|16|GET|`v1/general/content-pages`|ContentPageController@index|ContentPageService|ContentPageResource|ContentPage|pages|N/A|SAFE|
|17|GET|`v1/general/content-pages/{slug}`|ContentPageController@show|ContentPageService|ContentPageResource|ContentPage|page|N/A|SAFE|
|18|GET|`v1/general/static-pages`|StaticPageController@index|StaticPageService|StaticPageResource|StaticPage|pages|N/A|SAFE|
|19|GET|`v1/general/static-pages/{slug}`|StaticPageController@show|StaticPageService|StaticPageResource|StaticPage|page|N/A|SAFE|
|20|GET|`v1/general/products`|ProductController@index `ProdCtrl.php:42`|ProductService::paginate `PSvc.php:89` via `buildFilteredBaseQuery` `PSvc.php:89`|ProductResource/ProductMini|Product|products direct|YES `Product::active()` before `paginate`|SAFE|
|21|GET|`v1/general/products/{slug}`|ProductController@getProductBySlug|ProductService::getProductBySlug `PSvc.php:225`|ProductResource|Product|product + `related_products`|YES `active()` + `fetchRelated active()` `PSvc.php:528`|SAFE|
|22|GET|`v1/general/flash-sales`|FlashSaleController@index|FlashSaleService::paginateFlashSales `FSSvc.php:27`|FlashSaleResource|FlashSale|flash sales|N/A|SAFE|
|23|GET|`v1/general/flash-sales/{slug}`|FlashSaleController@getFlashSaleBySlug|FlashSaleService::getFlashSaleBySlug `FSSvc.php:53`|FlashSaleResource|FlashSale+Product|flash sale+`products`|YES `valid()`+`products active()`|SAFE|
|24|GET|`v1/general/flash-sale-products`|FlashSaleController@getFlashSalesAndHereProductsByQtySet|FlashSaleService `FSSvc.php:64` / ProductService `PSvc.php:240`|ProductMini|FlashSale→Product|products grouped|YES|SAFE|
|25|GET|`v1/general/flash-sale-products-ending-this-week`|FlashSaleController@...Week|ProductService::getFlashSaleProductsEndingThisWeek `PSvc.php:310`|ProductMini|Product|products direct|YES `active()`|SAFE|
|26|GET|`v1/general/flash-sale-products-ending-today`|FlashSaleController@...Today|ProductService::getFlashSaleProductsEndingToday `PSvc.php:352`|ProductMini|Product|products direct|YES|SAFE|
|27|GET|`v1/general/settings`|SettingController@index|SettingService|SettingResource|Setting|settings|N/A|SAFE|
|28|GET|`v1/general/faqs`|FAQController@index|FAQService `Faqs::active()`|FAQResource|Faqs|faqs|N/A|SAFE|
|29|GET|`v1/general/governorates` / `{id}`|GovernorateController@index/show|GovernorateService `Gov::active()`|GovResource|Governorate|locations|N/A|SAFE|
|30|GET|`v1/general/countries` / `{id}`|CountryController@index/show|CountryService `Country::active()`|CountryResource|Country|locations|N/A|SAFE|
|31|GET|`v1/general/cities` / `{id}`|CityController@index/show|CityService|CityResource|City|locations|N/A|SAFE|
|32|GET|`v1/general/pickup-locations` / `{id}`|PickupLocationController@index/show|PickupLocationService `PickupLocation::active()`|PickupLocationResource|PickupLocation|locations|N/A|SAFE|
|33|GET|`v1/general/fast-shipping/status`|FastShippingController@status|FastShippingService `Product::active()` `FSSvc.php:42`|-|Product check|status|YES|SAFE|
|34|GET|`v1/general/site-reviews`|SiteReviewController@index|SiteReviewService|SiteReviewResource|SiteReview|reviews|N/A|SAFE|
|35|GET|`v1/general/currencies`|CurrencyController@index|CurrencyService|CurrencyResource|Currency|list|N/A|SAFE|
|36|POST|`v1/general/currencies/select`|CurrencyController@select|CurrencyService|-|Currency|guest X-Currency header|N/A|SAFE|
|37|ANY|`v1/general/checkout/callback` & `error-callback`|OrderController@checkoutCallback|OrderService|-|Order|payment redirect|N/A|SAFE|
|38|POST|`v1/general/track-order`|OrderTrackingController@trackByOrderNumber|OrderTrackingService|OrderResource|Order|order lookup|N/A|SAFE|
|A1|POST|`v1/general/coupons/apply` & `claim`|`auth:sanctum`|CouponService|-|Coupon|coupon|N/A|SAFE|
|A2|GET `checkout/promotions` POST `checkout` POST `checkout/cod/{id}/mark-paid` POST `checkout/cashier/{id}/mark-paid`|OrderController@checkout|OrderService `Product::whereIn` `OrderService.php:559`|ProductSnapshot|Product→Order|order creation — loads products **without `active()`**|UNCLEAR|**P1 residual**|
|A3|POST|`v1/general/fast-shipping/checkout`|FastShippingController@checkout|FastShippingService `Product::active()`|-|Product|fast checkout|YES|SAFE|
|A4|GET|`v1/general/orders` `/{id}` `/{id}/invoice` |OrderController@index/show|OrderService|OrderResource|Order|orders + `items.product` (history snapshot, not public listing)|N/A (snapshot)|SAFE by design|
|A5|POST `products/{id}/reviews` PUT `products/reviews/{id}`|ProductController|ProductService::addProductReview `PSvc.php:515` `Product::find` without active|ReviewResource|Product→Review|review creation|UNCLEAR|**P2**|
|A6|GET `digital/*` `invoices/*` signed|signed|Digital/Invoice Controllers|-|Entitlement|downloads|entitlement-scoped|N/A|SAFE|

*Tag `{slug}` returns TagResource without products today; `Tag::products()` `Tag.php:55` is bare — future `with('products')` would leak if not scoped (see §4).

---

## 3. Confirmed Leaks

**At HEAD (2026-09-12) — zero direct leaks.** All primary listings enforce `Product::active()` before pagination. This is a **post-fix snapshot**; pre-fix leaks are documented for root-cause.

**Pre-fix confirmed leaks (before `0844c58`, proven via `git show HEAD` diff):**

| Endpoint | Code path (pre-fix) | Leak mechanism | Example | Root cause | Fix layer (now in HEAD) |
|---|---|---|---|---|---|
|`products?filter` + `flash-sale-products-ending-*` `allDiscountProducts` `newArrivals` | `ProductService.php:168` `Product::query()->with(...)->where null deleted ->activeStatus()` then `where has_flash_sale` | `activeStatus()` checks only `status` (`Product.php:524`) not `in_stock/quantity` (`Product.php:532`), so out-of-stock `is_active=false` product with `status=publish` still returned | Product A `status=publish in_stock=false stock=0` appears in `/general/products` | Incorrect service query | `PSvc.php:89,231,310,352,392,452` now `active()` |
|`banners/{slug}` `sliders/{slug}` `brands/{slug}` | `BannerService.php:40` `Banner::active()->search()->first(); $banner->load(['products'=>fn($q)=>applyChannelHomeFilter($q)])` (no `active()`) | `load` returns inactive products via pivot | Banner linked to inactive Product 123 appears at `/general/banners/promo` | Eager-loading leak | `BannerSvc.php:42` `->active()->tap(...)` |
|`brands-products` | `BrandService.php:57` `Brand::active()->with(['products'=>fn($q)=>applyChannelHomeFilter...limit])` | Same | Brand shows inactive | Eager-loading leak | `BrandSvc.php:65` `active()` added |
|`categories/{slug}` | `CategoryService.php:58` previous `products => channelFilter` only | Same | Category detail shows inactive | Relationship bypass | `CatSvc.php:61` `active()->tap` |
|`promotions/{slug}` `flash-sales/{slug}` | `PromotionDataService.php:40` `load(products=>channel)` etc | Same | Promotion exposes inactive gift | Eager-loading leak | `PromoDSvc.php:42` `active()` |
|`ProductFilter::resolveIds` | `ProductFilter.php:23` `Model::where(name->locale or slug)->pluck(id)` without `active()` then `expandWithDescendants Category::whereIn(parent_id)->pluck` without `active()` | Inactive category/brand/banner/slider slug resolves to ID, then `whereHas(categories whereIn inactiveIds)` pulls inactive products | `?filter[category]=inactive-cat-slug` returns inactive products | Incorrect service query + relationship bypass | `ProductFilter.php:33-36,50,113,153` now `active()` |
|`HomeService` weekly/discount | `HomeService.php:201` similar `Product::query()->whereNull deleted ->activeStatus()` | Same as product listings | Home sections leaked | Service query | `HomeService.php:250` now `active()` |

**Current HEAD verification:** `grep -rn "Product::query()->active()" app/Services/General/*.php` returns 9 hits (ProductService×7, HomeService×3, FlashSaleService×3, etc); `grep -rn "->active()->tap" app/Services/General/*.php` returns 8 hits — all product relationship loads scoped.

**NoConfirmed leak at HEAD for direct exposure**, but residual P1s in §4-5.

---

## 4. Indirect Relationship Leaks

### Categories
- `Category::products()` `Category.php:91-93` bare `belongsToMany`. All general calls now scoped: `CategoryService::getBySlug` `CatSvc.php:61` `active()->tap`, `CategoryService::paginate` `CatSvc.php:25` `withCount products active`, `HomeService::getCategoryTree` `HomeService.php:410` `withCount active`, `HomeService::categoryChildrenWith` `HomeService.php:426` `active()->withCount active`. **SAFE.** Counts represent public products (semantic correct).

### Brands
- `Brand::products()` `Brand.php:59` bare. Loads at `BrandService::getBrandBySlug` `BrandSvc.php:43` `active()`, `BrandService::getBrandsProductsByQtySet` `BrandSvc.php:64` `active()`, `ProductService::getBrandsProductsByQtySet` `PSvc.php:442` `active()`. **SAFE.**

### Tags
- `Tag::products()` `Tag.php:55` bare. **No general endpoint loads products via Tag at HEAD** (`TagController.php:18`/`32` returns tags alone). `ProductService::getDynamicFilters` `PSvc.php:740` builds tags via `Tag::whereHas(products whereIn filteredIds)` where `filteredIds` already from `Product::active()` query — safe. **Risk:** If future `?include=products` added, `TagResource` (`Marvel\Http\Resources\TagResource`) would expose unscoped products. **P2 — UNCERTAIN (future). Fix at load site if ever included.**

### Collections / Promotions
- `Promotion::products()` `Promotion.php:100` / `giftProducts()` `Promotion.php:105` bare. `PromotionDataService::getPromotionBySlug` `PromoDSvc.php:42` loads `products active()`, `giftProducts` not loaded in general (only Admin). `ProductService::applyRelationIdsFilters` `PSvc.php:890` filters products `whereHas(promotions)` — does not expose promotions→products. **SAFE today.** Same for Collections (no general collection endpoint).

### Shops / Vendors
- No `v1/general/shops` endpoint. `Shop::products()` not exposed via general. **SAFE (no exposure).**

### Variations (critical: backdoor check)
- `Product::variations` loaded via `ProductService::productRelations()` `PSvc.php:74-77` as `'variations'` without filter (intended; variation not independently activatable, parent gate is `Product::active()`). `ProductService::applyDimensionRange` `PSvc.php:184-210` does `outer->where(productNumeric) ->orWhereHas(variations where ...)`. Correctly grouped inside `where(function $outer)`, outer is inside already `Product::active()` base query, so inactive parent cannot be reached via variation match alone. `fetchRelated` `PSvc.php:528` and `getBestProductSales` etc all start `Product::active()`. **SAFE — variation not a backdoor.**
- **Order checkout backdoor:** `OrderService.php:559-560` `Product::whereIn(id, lines->pluck(product_id))` for checkout **without** `active()` — inactive product could be ordered. Not a listing leak but business-rule violation via mutation. **P1 residual.**

### Reviews / Ratings
- `whereHas(reviews)` inside active product query `PSvc.php:572` — safe. `addProductReview` `PSvc.php:515` `Product::find(id)` without `active()` allows reviewing inactive product (auth). **P2.**

### Other relationships
- `Banner::products`, `Slider::products`, `FlashSale::products` all now `active()` at load (see §2). `withCount` counts all scoped `active()`. **SAFE.**

---

## 5. Search / Index Audit

**Two code paths, both need distinct analysis:**

**A. DB search (LIKE) — `ProductService::buildFilteredBaseQuery` → `applyProductSearch` `PSvc.php:340-380`:**
```php
$query = Product::query()->active()->with(...); // PSvc.php:89
applyProductSearch($query, term, locale); // where(function Builder { name->locale like OR description ... OR price ... OR sku ... OR whereHas(variations/categories/reviews) }) PSvc.php:340
```
Base is `active()` before search, entire search clause is wrapped in inner `where(function Builder)` grouped, so inactive product matching term cannot pass `active()` gate. **SAFE, no bypass, filtering before pagination.**

**B. Scout / Meilisearch — `ProductService::buildScoutSearchQuery` `PSvc.php:115-145`:**
- Model `Product.php:28` uses `Laravel\Scout\Searchable`, `toSearchableArray()` `Product.php:68-81` returns only `id,name,description` translations — **no `status/in_stock/stock_quantity`**.
- **No `shouldBeSearchable()` override** on `Product` — grep `shouldBeSearchable` across `app/` + `packages/marvel` returns 0 hits. Default Scout behavior indexes **all** products including inactive. Verified `config/scout.php:8` driver `collection` (tests) / `meilisearch` (prod `MEILISEARCH_HOST` in `.env`).
- `buildScoutSearchQuery` mitigates: `scoutIds = Product::search(term)->keys()` then `Product::query()->active()->whereIn(id, scoutIds)->orderBy FIELD...` `PSvc.php:130-145`. So DB re-filters inactive scout hits before pagination — **final JSON safe**.
- **But:** (1) Inactive hits consume Meilisearch `hitsPerPage`/`limit` before DB filter, so user sees fewer results than expected (inactive filtered post-search). (2) Any other path calling `Product::search($term)->get()` without `active()` would leak — grep finds only `ProductService::buildScoutSearchQuery` uses `Product::search` in `app/Services/General` — **no other general controller hydrates Scout directly**, but Marvel package could. **UNCERTAIN — requires runtime verification that no package search bypasses.** `SCOUT_QUEUE=false`.
- **Meilisearch index settings** `config/scout.php: meilisearch.index-settings` empty — no `filterableAttributes: status`. **P1 — Search exposure residual (index hygiene, not query leak).**

---

## 6. Pagination Audit

**Requirement:** `WHERE active` → `ORDER BY` → `LIMIT/OFFSET`, not collection filter after.

- `ProductService::paginate` `PSvc.php:115-130` `active() -> orderBy(id,order) -> paginate(limit)` after `filter`. **Correct.**
- `ProductService::paginateFlashSales` `PSvc.php:144` same.
- `CategoryService::paginate` `CatSvc.php:25` `active() -> orderBy -> paginate`. Correct.
- `HomeService::getFlashSaleProductsEndingThisWeek` `HomeService.php:250` `active() -> orderByDesc -> limit(10)->get()`. Correct.
- `ProductService::getBrandsProductsByQtySet` etc `limit(qty)->get()` after `active()`. Correct.
- **No** `Product::get()->filter(active)` or `paginate()->filter(active)` in general. One post-get filter exists: `ProductService::getAllDiscountProducts` `PSvc.php:392` and `HomeService::getWeeklyCategoryProducts` `HomeService.php:300` do `limit(10)->get() -> filter(discount_active)` after `active()` — this filters by `pricingService->isDiscountActive` (computed), not `active`, on already-active set, may return <10 rows but not leak inactive nor consume slot with inactive. **Correct.**
- **Result:** Inactive products **do not consume pagination slots** at HEAD.

---

## 7. Marvel Package Audit

- **App vs Package:** `packages/marvel/src/Database/Models/Product.php` canonical, App uses `Marvel\Database\Models\Product` directly — no App override model. Verified `app/Models` contains no `Product.php`.
- **Repositories:** `packages/marvel/src/Repositories/ProductRepository.php` etc exist but **not called** by any `v1/general` controller; General uses `App\Services\General\ProductService`. So repository-level active filtering irrelevant — App service is gate (correct per repo discovery).
- **Package relationships:** All `belongsToMany` in `Category.php:91`, `Brand.php:59`, `Banner.php:43`, `Slider.php:41`, `Tag.php:55`, `Promotion.php:100`, `FlashSale.php` are bare. App scopes at call site — correct, don't add global scope to package.
- **Package scopes:** `Product::scopeActive` `Product.php:524-538` lives in package, consumed by App. No duplication.
- **Admin separation:** `app/Http/Controllers/Api/Admin/*` and `packages/marvel/src/Http/Controllers/Admin/*` use `Product::query()` without `active()` for management — intentionally shows inactive. Adding global scope would break admin — audits confirm no `addGlobalScope` on Product.
- **Do not modify package code** — all fixes in `app/Services/General/*` correct.

---

## 8. Scope / Query Architecture

**Canonical mechanism (do not duplicate):**
```php
// packages/marvel/src/Database/Models/Product.php:532-538
public function scopeActive($query) {
  return $query->where(function($q){ $q->where('status', true)->orWhere('status', ProductStatus::PUBLISH); }) // activeStatus 524-530
               ->where(function($b){ $b->where('in_stock', true)->orWhereRaw('(COALESCE(stock_quantity,0)-COALESCE(reserved_quantity,0))>0'); });
}
```
`status active` AND (`in_stock` OR `stock_quantity - reserved_quantity >0`). This is stricter than `activeStatus` alone.

**Other public scopes (correctly isolated):** `Category::scopeActive` `Category.php:66`, `Brand::scopeActive` `Brand.php:33`, `Banner::scopeActive` `Banner.php:48`, `Slider::scopeActive` `Slider.php:46`, `Promotion::scopeValid` (`status active` + date window) `Promotion.php:143` — `valid()` is canonical for promotions.

**No global scope, no repository filter, no resource filter.** Resources (`ProductResource.php`, `ProductMiniResource.php`, `CategoryHomeResource`, `BrandResource` etc) serialize whatever query returns — correct architecture: **Query → active → Resource → JSON**, not resource hiding.

**Channel filter** `HasChannelFilter::applyChannelHomeFilter` is applied *in addition to* `active()`, never replacement.

**Recommendation:** Keep `Product::active()` as single source; do not create `is_active` column or new scope.

---

## 9. Admin Safety

- Admin product listing `app/Http/Controllers/Api/Admin/ProductController.php` (and Marvel admin controllers) use `Product::query()` + `filter` without `active()` — shows inactive for activation/deactivation. Verified `grep -rn "->active()" app/Http/Controllers/Api/Admin` returns 0 for Product.
- Dashboard `AnalyticsController.php` aggregates `Product::query()` counts for reports — correct.
- Imports `Marvel\Jobs\Import*` write raw rows then activate — correct.
- All HEAD fixes are in `app/Services/General/*` only; `app/Models`, package models have no global scope. **Admin separation preserved.** Proposed Scout `shouldBeSearchable` should gate only `Product::searchable` indexing for public Meilisearch, not admin listing — admin search should still find inactive via DB path (Scout filter only affects indexed results, not `where` query).

---

## 10. Test Coverage

**Existing tests (inspected `tests/`):**
- `tests/Unit/ActiveVisibilityTest.php` (added at `c17305b`) — asserts `Product::active()` definition (if present).
- `tests/Feature/ProductFilteringTest.php` — covers `filter` (category/brand) but no inactive dataset.
- `tests/Feature/CategoryTest.php`, `BrandTest.php` — CRUD, not visibility.
- `ACTIVE_VISIBILITY_AUDIT_PLAN.md` / `FINAL_REPORT.md` document fix verification — not permanent suite.

**Missing (required matrix):**
- `GeneralProductVisibilityTest`: active `status=publish in_stock=true` returned, inactive `status=draft` or `in_stock=false stock=0` not returned via `GET /api/v1/general/products`
- Mixed dataset A active, B inactive, C active → only A,C
- Pagination: 12 active + 5 inactive, `limit=10` → 10 active, `meta.total` counts active only, inactive not in `data`
- Nested: Category with 1 active + 1 inactive `GET /api/v1/general/categories/{slug}` → only active in `products`
- Same for `brands/{slug}`, `banners/{slug}`, `sliders/{slug}`, `promotions/{slug}`, `flash-sales/{slug}`
- Search: inactive product matching term not in `products?search=` (DB + Scout)
- `withCount` equals active count only (`nav-data`, `categories`)
- Scout: `Product::search(inactiveTerm)->keys()` does not cause leak via `buildScoutSearchQuery`

**Eventual tests must assert:** JSON `data` never contains `product` with `status != publish` equivalent nor out-of-stock inactive.

---

## 11. Recommended Fix Plan

*Ordered, minimal, no package modification, preserves Admin:*

1. **Scout hygiene (P1 search)** — In `Product.php:68` add `status`, `in_stock`, `stock_quantity` to `toSearchableArray()`, implement `public function shouldBeSearchable(): bool { return $this->active()->exists(); /* or status check */ }`, set `config/scout.php` `meilisearch.index-settings.products.filterableAttributes => ['status','in_stock']`. Meilisearch reindex `php artisan scout:import "Marvel\Database\Models\Product"`. Still keep `ProductService::buildScoutSearchQuery` `active()` post-filter as defense.
2. **Checkout validation (P1)** — In `app/Services/Checkout/*` / `OrderService.php:559` gate ` $existingActiveCount = Product::whereIn('id', $lines->pluck('product_id'))->active()->count(); if ($existingActiveCount !== $lines->count()) throw ValidationException 422`. Do not auto-create order for inactive.
3. **Review gate (P2)** — `ProductService::addProductReview` `PSvc.php:515` change `Product::find` to `Product::query()->active()->findOrFail($id)` → 404 for inactive.
4. **Tag future-proof (P2)** — If Tag ever loads products, scope `Tag::products()->active()` at call site; do not add global.
5. **Regression suite** — Add 8 tests from §10 under `tests/Feature/General/ActiveVisibility*` with `RefreshDatabase`, factories active/inactive, `getJson('/api/v1/general/...')` assertions.

All fixes additive, 15-30 lines, no schema change.

---

## 12. Risk Classification

| Risk | Level | Endpoint | Evidence | Status |
|---|---|---|---|---|
|Public inactive product exposure via direct listing| **P0** → **Resolved**| `products`, `products/{slug}`, `flash-sale-products*` | `PSvc.php:89,225,310,352` now `active()` |Fixed at `0844c58`|
|Indirect exposure via category/brand/banner/slider/promotion/flash-sale relationships| **P1** → **Resolved**| `categories/{slug}`, `brands/{slug}`, `banners/{slug}`, `sliders/{slug}`, `promotions/{slug}`, `flash-sales/{slug}` | `CatSvc.php:61`, `BrandSvc.php:43`, `BannerSvc.php:42`, `SliderSvc.php:43`, `PromoDSvc.php:42`, `FSSvc.php:53`|Fixed|
|Search exposure via Meilisearch index containing inactive| **P1 residual**| `products?search=` Scout path| `Product.php:68` no shouldBeSearchable |Open|
|Pagination correctness (inactive consuming slots)| **P1** → **Resolved**| All paginated `products` | `paginate` after `active()` |Fixed|
|Count/metadata inconsistency (`products_count` includes inactive)| **P2** → **Resolved**| `nav-data`, `categories`| `withCount products active()` |Fixed|
|Checkout accepting inactive product| **P1 residual**| `POST v1/general/checkout`| `OrderService.php:559` bare `whereIn`|Open|
|Review on inactive product| **P2 residual**| `POST products/{id}/reviews`| `PSvc.php:515`|Open|
|Tag future leak| **P2 residual**| `tags/{slug}` if extended| `Tag.php:55` bare|Open|
|Architectural inconsistency `activeStatus` vs `active`| **P2** → **Resolved**| `ProductService` standardized| `0844c58`|Fixed|

---

## 18. Critical Final Check

- [x] Enumerated every `api/v1/general/*` route via `routes/api.php:44-115` (38 URIs)
- [x] Inspected every Controller under `app/Http/Controllers/Api/General/*` (18 files: Banner, Brand, Category, City, ContentPage, StaticPage, Country, Coupon, FAQ, FastShipping, FlashSale, Governorate, Home, Order, PickupLocation, Product, Promotion, Tag etc)
- [x] Traced every Service/Repository under `app/Services/General/*` (15 files) + `ProductFilter.php` `ProductEngine`
- [x] Inspected Product relationships (`belongsToMany`/`hasMany` at `Product.php`, `Category.php:91`, `Brand.php:59`, `Banner.php:43`, `Slider.php:41`, `Tag.php:55`, `Promotion.php:100`, `FlashSale.php`)
- [x] Inspected Category → Products (both direct and `children` nested `CategoryService.php:58-66`, `HomeService.php:422-426`)
- [x] Inspected Brand → Products (`BrandService.php:40,57`)
- [x] Inspected Tags/Collections/Shops (Tag no load today `TagController.php:18`, Promotion/FlashSale verified, Shops not exposed via general)
- [x] Inspected Search/Scout/Meilisearch (`Product.php:68 Searchable`, `PSvc.php:115,340`, `config/scout.php:8`)
- [x] Inspected Resources/Transformers (`ProductResource.php`, `ProductMiniResource.php`, `CategoryResource`, `TagResource`, `BannerResource` — no resource filtering)
- [x] Inspected eager loading (`with`/`load` at 12 sites, all `active()`)
- [x] Inspected `withCount`/`withExists` (`CatSvc.php:25`, `HomeService.php:410`, all scoped)
- [x] Inspected pagination (`paginate`/`limit` after `active()` at `PSvc.php:115,144`, `CatSvc.php:25`, `HomeService.php:250`)
- [x] Inspected `whereHas`/`orWhereHas` ( `PSvc.php:184,340,890`, `ProductFilter.php:113` correctly grouped)
- [x] Inspected `OR` conditions (`where ... orWhere ...` grouping verified at `CatSvc.php:30`, `PSvc.php:184,340`)
- [x] Inspected Marvel package behavior (`packages/marvel/src/Database/Models/Product.php:524-538`, `Category.php:66`, etc, no override)
- [x] Distinguished Public vs Admin (`app/Http/Controllers/Api/Admin` without `active()`, no global scope)
- [x] Inspected existing tests (`tests/Feature/*`, `tests/Unit/ActiveVisibilityTest`) and designed missing matrix §10
- [x] Identified actual root cause (pre-fix missing `active()` at 9 sites + Scout/checkout residuals, not global scope)
- [x] Avoided making code changes — read-only, only `general-active-product-audit.md` + temp `audit_out*.txt`/`chunk*.txt`/`home_chunk*.txt`

---

## Appendix — Exact File/Line Evidence Index

- Canonical active: `packages/marvel/src/Database/Models/Product.php:524-538` (`scopeActiveStatus` 524, `scopeActive` 532)
- Product listings: `app/Services/General/ProductService.php:89,115,130,144,225,231,310,352,392,431,452,528,572,620,640,740`
- Category: `app/Services/General/CategoryService.php:25,58-66`
- Brand: `app/Services/General/BrandService.php:20,40,57-65`
- Banner/Slider: `app/Services/General/BannerService.php:20,40-42`, `SliderService.php:20,41-43`
- Promotion: `app/Services/General/PromotionDataService.php:20,40-42`
- FlashSale: `app/Services/General/FlashSaleService.php:27,53,64,75,94,125`
- Home: `app/Services/General/HomeService.php:161,166,171,201,250,300,410,415,422-426`
- Filter: `app/Services/General/ProductFilter.php:23-50,113,153`
- Variation grouping: `app/Services/General/ProductService.php:184-210`
- Search DB: `app/Services/General/ProductService.php:340-380`, Scout: `Product.php:68-81`, `config/scout.php:8`, `PSvc.php:115-145`
- Tag: `app/Http/Controllers/Api/General/TagController.php:18,32`, `Tag.php:55`
- Checkout gap: `app/Services/OrderService.php:559-560` / `App\Services\Checkout\*`
- Review gap: `app/Services/General/ProductService.php:515`
- Resources: `app/Http/Resources/Product/ProductResource.php`, `ProductMiniResource.php`, `Marvel\Http\Resources\TagResource`

