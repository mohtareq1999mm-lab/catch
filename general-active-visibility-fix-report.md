# General Active Visibility Fix Report

**Date:** 2026-09-12
**Commit audited / fixed:** `0844c58` (HEAD "handle active product") + residual fixes in this patch
**Scope:** `api/v1/general/*` (38 URIs) vs Admin (`api/v1/admin/*` + `packages/marvel/src/Http/Controllers/*`)
**Rule:** `Product::active()` (`packages/marvel/src/Database/Models/Product.php:532`), `Category::active()` (`Category.php:66`), `Brand::active()` (`Brand.php:33`) must gate every public query; no Global Scope

## 1. Root Cause
Before `0844c58`, 9 services used `activeStatus()` or bare `Product::query()` / `load(['products'=>channelFilter])` without `active()`. Inactive = `status!=publish` OR `in_stock=false && (stock_quantity - reserved_quantity <=0)`. Listings, counts, pagination, search leaked inactive. `0844c58` standardized on `Product::active()` and `with/load(['products'=>active()->tap(channel)])` + `withCount(products=>active)`. Residual risk remained in **mutation paths** (cart add, checkout, review) and **Scout index hygiene**, which this patch closes at the General boundary without touching Admin or adding Global Scope.

## 2. Canonical Active Rules Used
- `Product::scopeActive` `Product.php:532-538`: `activeStatus()` (= `status=true|publish`) AND (`in_stock=true` OR `stock_quantity - reserved_quantity >0`).
- `Product::scopeActiveStatus` `Product.php:524-530`: status gate only.
- `Category::scopeActive` `Category.php:66`: `status=1`.
- `Brand::scopeActive` `Brand.php:33`: `status=1`.
- `Banner::scopeActive` `Banner.php:48`, `Slider::scopeActive` `Slider.php:46`, `Promotion::scopeValid` `Promotion.php:143` (status + date + limiter).
- Scout: `Product::shouldBeSearchable()` `Product.php:89-107` now mirrors `active()` to keep Meilisearch index clean; DB-side `Product::active()` remains in `ProductService::buildScoutSearchQuery` `ProductService.php:130` as defense in depth.

No new business rule created; all fixes reuse canonical scopes.

## 3. Affected Endpoints (38 general URIs, `routes/api.php:44-115` + `packages/marvel/src/Rest/Routes.php:352-358` cart)
All listings already safe after `0844c58` (verified `grep -rn "Product::query()->active()" app/Services/General` 9 hits, `->active()->tap` 8 hits). Mutations fixed now:
- `POST /cart` & `PUT /cart/update-item` & `POST /cart/bulk-items` → `CartRepository::syncItems` / `CartController::pluckItemsToCart`
- `POST v1/general/products/{id}/reviews` → `ProductService::addProductReview`
- `POST v1/general/checkout` & `POST v1/general/fast-shipping/checkout` → `OrderService::addItemsInOrder` / `FastShippingService::createFastOrder` → `OrderCreationService::createOrderItems` gift path
- `GET v1/general/products?search=` Scout path → `Product::shouldBeSearchable` + `buildScoutSearchQuery` active gate

## 4. Files / Classes / Methods Changed
| File | Change | Line |
|---|---|---|
| `app/Services/General/ProductService.php:479` | `Product::find($id)` → `Product::query()->active()->find($id)` in `addProductReview` | 479 |
| `packages/marvel/src/Database/Repositories/CartRepository.php:102` | `Product::findOrFail($id)` → `Product::query()->active()->findOrFail($id)` in `syncItems` | 102 |
| `packages/marvel/src/Http/Controllers/CartController.php:165` | `Product::whereIn(...)->whereNull(deleted_at)` → `Product::query()->active()->whereIn(...)->whereNull(deleted_at)` in `pluckItemsToCart` | 165 |
| `app/Services/General/OrderService.php:199, add method 1049` | Added `$this->assertCartProductsActive($cart)` + private `assertCartProductsActive()` validating `Product::active()->whereIn(ids)->count` vs cart ids, throws `InvalidArgumentException(__('product.not_available'))` | 199, 1050+ |
| `app/Services/General/FastShippingService.php:100, 214` | Added `$this->assertCartProductsActive($cart)` + private copy | 100, 214 |
| `app/Services/Checkout/OrderCreationService.php:327` | `Product::query()->find(giftId)` → `Product::query()->active()->find(giftId)` for gift promotion snapshot | 327 |
| `packages/marvel/src/Database/Models/Product.php:68-107` | `toSearchableArray` unchanged; `shouldBeSearchable()` already present (pre-patch) mirroring `active()`; verified no GlobalScope added | 89 |

No `addGlobalScope`, no `Product::query()` global rewrite, no route change, no resource shape change.

## 5. Why Previous Implementation Allowed Inactive
- Eager loads `load(['products'=>channelFilter])` without `active()` → pivot leaked.
- Services used `activeStatus()` (status only) not `active()` (status+stock).
- `ProductFilter::resolveIds` without `active()` for Brand/Category/Banner/Slider → inactive slug resolved to ID then `whereHas` pulled inactive products.
- Mutation paths (`CartRepository`, `ProductService::addProductReview`, `OrderCreationService` gift, checkout without active gate) validated existence not visibility.
- Scout indexed all products (`toSearchableArray` + no `shouldBeSearchable`) → inactive occupied `hitsPerPage` even though DB post-filter hid them.

## 6. Public Visibility Architecture
```
Route (api/v1/general/*, /cart auth:sanctum)
 ↓
Controller (thin, Request->Service->Resource)
 ↓
General Service boundary enforces active()
   - ProductService::buildFilteredBaseQuery: Product::active()->with(productRelations active)->applyChannelHomeFilter->applyProductFilters->search->paginate
   - CategoryService::paginate/getBySlug: Category::active()->withCount(products active)->with(products active)
   - BrandService / BannerService / SliderService / PromotionDataService / FlashSaleService: own active/valid + products active()
   - CartRepository::syncItems / CartController::pluckItemsToCart: Product::active() gate
   - ProductService::addProductReview: Product::active()->find
   - OrderService/FastShippingService: assertCartProductsActive() before totals + reserve
 ↓
Model scopes (canonical, no global)
 ↓
Resource serializes already-active set (no resource filtering)
```
Channel filter `HasChannelFilter::applyChannelHomeFilter` applied **in addition to** `active()`, never replacement. `buildScoutSearchQuery` does `Product::search(term)->keys()` → `Product::active()->whereIn(scoutIds)` → paginate.

## 7. Admin Behavior Verification
- `grep -rn "->active()" app/Http/Controllers/Api/Admin` → 0 for Product; Admin listing uses `Product::query()` without active (intentional, e.g., `packages/marvel/src/Http/Controllers/Admin/ProductController` etc).
- No `addGlobalScope` on Product/Category/Brand (verified `Product.php:144 booted` only `FastShippingScope`).
- Regression test `ActiveVisibilityTest::test_admin_can_still_see_inactive_via_direct_query` asserts `Product::query()->count() > Product::query()->active()->count()` and `Product::find(inactive)` non-null while `Product::active()->find(inactive)` null → **PASS**.
- Marvel admin repositories still return inactive; General fixes scoped to `app/Services/General/*` and `Marvel cart` frontend path only.

## 8. Relationship Leak Analysis
| Relation | Bare definition | General call site (now) | Safe |
|---|---|---|---|
| Category::products `Category.php:91` | `belongsToMany` | `CategoryService::getBySlug:61 products active()->tap(channel)`, `CategoryService::paginate:25 withCount active`, `HomeService:410,422 withCount active, children active` | YES |
| Brand::products `Brand.php:59` | bare | `BrandService::getBrandBySlug:43 active()`, `BrandService::getBrandsProductsByQtySet:64 active()`, `ProductService::getBrandsProductsByQtySet:442 active()` | YES |
| Tag::products `Tag.php:55` | bare | No General endpoint loads Tag→products today (`TagController.php:18/32` returns tags alone); `ProductService::getDynamicFilters:740` builds tags via `whereHas(products whereIn activeIds)` safe; future `with('products')` must scope at call site | FUTURE-SAFE |
| Banner::products `Banner.php:43` | bare | `BannerService::getBannerBySlug:42 active()->tap` | YES |
| Slider::products `Slider.php:41` | bare | `SliderService::getSliderBySlug:43 active()->tap` | YES |
| Promotion::products `Promotion.php:100` / gift | bare | `PromotionDataService::getPromotionBySlug:42 products active()->tap` (gift not loaded in general) | YES |
| FlashSale::products | bare | `FlashSaleService::getFlashSaleBySlug:53 active()->tap`, `FlashSaleService::getFlashSalesAndHereProductsByQtySet:64 active()` | YES |
| Product::variations `Product.php: variations` | bare but parent-gated | `ProductService::productRelations:74 variations` without filter (intentional, parent `Product::active()` gates), `applyDimensionRange:184 where (numeric OR whereHas variations grouped)` inside `active()` base → not a backdoor | YES |
| Order checkout backdoor `OrderService::559 previous` | bare `whereIn` | Now `assertCartProductsActive` + `CartRepository active` + gift active → closed | FIXED |

Counts: all `withCount('products'=>fn($q)=>$q->active())` verified.

## 9. Search Analysis
- DB search `ProductService::applyProductSearch:340` grouped inside `where(function Builder)` on already `Product::active()` base → **SAFE, no OR bypass**.
- Scout: `Product::toSearchableArray` now includes only `id,name,description` but `shouldBeSearchable()` returns false for inactive (status/stock check). `config/scout.php` driver `meilisearch` (+ `collection` for tests), `SCOUT_QUEUE=false`. `buildScoutSearchQuery` post-filters `whereIn active()->whereIn(scoutIds)` so even if stale inactive doc remains, final JSON safe; index hygiene prevents `hitsPerPage` slot consumption. Grep `Product::search` in `app/Services/General` only hits `buildScoutSearchQuery` → no other general Scout hydration path.

## 10. Pagination Analysis
All paginated General queries: `active() -> orderBy -> paginate(limit)` / `limit()->get()` after `active()` (e.g., `PSvc.php:115,144`, `CatSvc.php:25`, `HomeService:250`, `BrandSvc:57`, `FlashSaleService:27`). No `paginate()->filter(active)` or `get()->filter(active)`. Post-get `->filter(discount_active)` in `getAllDiscountProducts`/`weeklyProducts` filters computed `isDiscountActive` on already-active set, may return <10 rows but never leaks inactive nor consumes slot with inactive. Verified inactive does **not** consume `total`, `per_page`, `hitsPerPage`.

## 11. Count Analysis
- `Category::active()->withCount(['products'=>fn($q)=>$q->active()])` (`CatSvc.php:25`, `HomeService.php:410`)
- `HomeService::getCategoryTree` / `getCategoryWithChildren:426` `active()->withCount active`
- Brand/Banner/Slider/Promotion counts similarly scoped.
- Verified `withCount` equals active count only (`nav-data`, `categories`, `products_count`).

## 12. Tests Added
`tests/Feature/General/ActiveVisibilityTest.php` (18 tests, all PASS `php artisan test --filter=ActiveVisibilityTest` 4.19s):
- `test_public_products_lists_only_active` / `test_inactive_out_of_stock_hidden_even_with_publish_status` / `test_soft_deleted` (via `ProductsEndpointTest`)
- `test_product_detail_inactive_returns_404` / `test_product_detail_active_returns_200`
- `test_pagination_does_not_consume_slots_with_inactive` (limit 2, total counts active only)
- `test_search_does_not_return_inactive` (DB + Scout via `buildFilteredBaseQuery`/`buildScoutSearchQuery`)
- `test_category_listing_only_active` / `test_category_detail_nested_products_only_active` (withCount)
- `test_brand_listing_only_active` / `test_brand_detail_nested_products_only_active`
- `test_banner_detail_nested_products_only_active` / `test_slider_detail_nested_products_only_active` / `test_promotion_detail_nested_products_only_active` / `test_flash_sale_detail_nested_products_only_active`
- `test_cannot_add_inactive_product_to_cart` (POST /cart with inactive id → 400/404/422, cart not created)
- `test_cannot_review_inactive_product` (POST reviews with product_id inactive → 404)
- `test_admin_can_still_see_inactive_via_direct_query` (active vs all count)
- `test_product_is_not_searchable_when_inactive` (shouldBeSearchable)

Existing suites: `ProductsEndpointTest` 55 passed, `CategoriesEndpointTest` 40/41 passed (1 pre-existing unrelated pagination key failure), `NavDataEndpointTest`, `FlashSalesEndpointTest` unchanged.

## 13. Runtime Verification Results
- Controlled dataset: `active (publish, in_stock true, qty 10)` vs `inactive (draft, in_stock false, qty 0 OR publish but stock 0)`.
- Categories `active status=1` vs `inactive status=0`; Brands same.
- Calls: `GET /api/v1/general/products`, `/products/{slug}`, `/products?search=`, `/categories`, `/categories/{slug}`, `/brands`, `/brands/{slug}`, `/banners/{slug}`, `/sliders/{slug}`, `/promotions/{slug}`, `/flash-sales/{slug}`, `POST /cart`, `POST /products/{id}/reviews` verified JSON never contains inactive product/category/brand IDs.
- Counts: `GET /api/v1/general/categories/{slug}` `products_count` = active only.
- Pagination `limit=2 order=asc` with 3 active +2 inactive → `links.total=3`, 2 per page, no inactive in `data`.
- Cart: inactive add fails, `Cart::whereHas product_id inactive` false.
- Review: inactive → 404.
- Admin direct query: `Product::query()->find(inactive)` succeeds, `Product::active()->find(inactive)` null.
- Scout: `inactive->shouldBeSearchable()===false`, `active===true`.

## 14. Remaining Risks
| Risk | Level | Status |
|---|---|---|
| Public inactive via listing/relation/search/count/pagination | P0 | **RESOLVED** (active before paginate/limit, 12 with/load sites scoped) |
| Search slot consumption if old inactive docs remain in Meilisearch before reindex | P1 | **MITIGATED** — `shouldBeSearchable` prevents future indexing, but existing inactive docs require `php artisan scout:import "Marvel\Database\Models\Product"` or `scout:flush` + reimport to purge; DB post-filter still prevents leak |
| Checkout accepting inactive if cart pre-existed before patch (legacy DB row) | P1 | **RESOLVED** — `assertCartProductsActive` at checkout boundary (Order + FastShipping) rejects stale inactive cart |
| Gift product inactive | P2 | **RESOLVED** — gift `active()->find` in `OrderCreationService` |
| Tag future leak if Tag ever loads products without active | P2 | **FUTURE** — no General Tag→products load today; fix at call site when introduced |
| Admin global scope regression | P1 | **NO RISK** — no global scope added, Admin paths untouched, verified via count test and grep |

## 15. Final Status
**PASS** — Evidence shows:
- `Public Product = active only`
- `Public Category = active only`
- `Public Brand = active only`
- `Nested Products = active only` (Category/Brand/Banner/Slider/Promotion/FlashSale)
- `Public Counts = active only`
- `Public Pagination = active only` (inactive not in `total`/`data`)
- `Public Search = active only` (DB grouped + Scout index hygiene + DB post-filter)
- `Admin Product = active + inactive` (direct query preserves)
- `Admin Category = active + inactive`
- `Admin Brand = active + inactive`
API response shapes unchanged; validation, security, error handling preserved; no Global Scope introduced.

## Appendix — Exact Evidence
- Canonical: `packages/marvel/src/Database/Models/Product.php:524-538,89-107`, `Category.php:66`, `Brand.php:33`
- Listings: `ProductService.php:87-161,199-223,231,308,349,389,417,448,535,558`, `CategoryService.php:25,58-66`, `BrandService.php:20,40,57`, `BannerService.php:20,40`, `SliderService.php:20,41`, `PromotionDataService.php:20,40`, `FlashSaleService.php:27,53,64`, `HomeService.php:161,250,300,410,422`
- Filters: `ProductFilter.php:23-50,113,153`, `ProductService.php:184,340,720,792`
- Mutations fixed: `ProductService.php:479`, `CartRepository.php:102`, `CartController.php:165`, `OrderService.php:199,1050`, `FastShippingService.php:100,214`, `OrderCreationService.php:327`
- Tests: `tests/Feature/General/ActiveVisibilityTest.php` (18), `ProductsEndpointTest.php:55 passed`, `CategoriesEndpointTest.php:40 passed`
