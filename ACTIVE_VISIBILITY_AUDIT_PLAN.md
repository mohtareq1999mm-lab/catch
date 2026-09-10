# ACTIVE VISIBILITY AUDIT PLAN — Public vs Admin

> **Date:** 2026-09-10
> **Context:** `general/*` = public-facing (must return active only), Admin/Dashboard = management (active+inactive)
> **Issue:** Inactive products appearing in `general/*` responses, potential same for categories/brands/tags/variations via relationships

---

## 1. Findings

- **Direct product listing** (`general/products` via `ProductService::buildFilteredBaseQuery`) correctly uses `Product::active()` (status + in_stock). **No leak** on base query.
- **Scout search** (`general/products?search=`) uses `Product::search($term)->keys()` then `Product::active()->whereIn(id, scoutIds)` — filters inactive after indexing, so inactive indexed docs are excluded. Correct but relies on DB fallback; stale index could temporarily expose inactive until DB filter.
- **Relationship leaks (CONFIRMED):**
  - `CategoryService::getBySlug` loads `products` via `applyChannelHomeFilter` only, **no `->active()`** — inactive products in an active category will be returned via `general/categories/{slug}`.
  - `BrandService::getBrandBySlug` and `getBrandsProductsByQtySet` load `products` with only `applyChannelHomeFilter` + `withAvg` — **no active filter**.
  - `ProductService::productRelations()` includes `categories.parent`, `variations`, `brands`, `tags`, `flash_sales valid` — but does not filter inactive `categories`/`brands`/`tags`/`variations`. An active product with inactive brand/category will still expose that inactive brand/category via `with()`.
  - `HomeService` methods (`getDiscountEndingTodayOrLowStockProducts`, `getNewArrivals`, `getFlashSaleProductsEndingThisWeek`, etc.) use `->activeStatus()` (status only) not `->active()` (status+in_stock). Some use `activeStatus` plus `where('has_discount',true)` etc., but not `in_stock` check — may expose out-of-stock inactive? Also their `products` eager loads not filtering inactive variations.
- **Admin parity:** Admin services (e.g., `ProductRepository`, dashboard controllers) do **not** use `active()` — correct for management. Must preserve.
- **Global scope:** No `ActiveScope` global scope registered on `Product`, `Category`, `Brand` — visibility is via **local scope `scopeActive()`** applied explicitly in public services. Correct architecture (avoids breaking admin), but **inconsistently applied** to relationship queries.
- **Search/cache:** Scout indexing includes inactive products in Meilisearch (`toSearchableArray` does not filter), but DB fallback filters. Cache keys for `general/*` (`home-*`, `products_*`) are channel+currency aware but not `public vs admin` — admin cache not used for public (admin endpoints not cached via same keys), so no leak, but `CategoryService::paginate` cache `FrontendResource::CATEGORIES` + `md5(fullUrl)` could be poisoned if admin user hits same URL (unlikely, but verify middleware).

---

## 2. Root Cause

Local `scopeActive()` exists but is **not uniformly applied** to:
1. Relationship eager loads (`with(['products' => fn($q) => ...])`) in `CategoryService`, `BrandService`, `HomeService` helpers.
2. Nested relationships (`product.categories`, `product.brands`, `product.variations`, `category.children` already active, but `category.products` not).
3. No enforcement at relationship definition level (e.g., `Category::products()` has no `->where('status',1)`), so every `with('products')` must remember to add `->active()`.

Admin correctly bypasses, but public incorrectly inherits the same unfiltered relationship path.

---

## 3. Affected Models

| Model | Column | Scope | Type | Current Active Enforcement |
|-------|--------|-------|------|----------------------------|
| `Product` | `status` (bool or `ProductStatus::PUBLISH`) + `in_stock`/`stock_quantity-reserved_quantity` | `scopeActive()` (via `activeStatus()` + stock check) | Local | Public base query YES, relationships NO |
| `Category` | `status` (bool) | `scopeActive()` | Local | Public index/slug YES, `children` YES, `products` NO |
| `Brand` | `status` | `scopeActive()` | Local | Public `getBrands`/`getBrandBySlug` base YES, `products` NO |
| `Tag` | none (no status) | none | — | No public visibility rule — but `Tag::products` should still filter inactive products |
| `Variation` | none (in `variation_options`) | none | — | Variation is dependent on product; no independent active, but should inherit product active? |
| `Shop` (Store) | `status`? `is_active`? | `scopeActive()` | Local | Not directly in `general/*` but via `products.shop`? |
| `Banner`/`Slider`/`FlashSale` | `status`/`is_active` + `valid()` scope | `scopeActive()`/`valid()` | Local | `HomeService` uses `active()`/`valid()` correctly |
| `Coupon` | `status`/`valid()` | `valid()` | Local | `HomeService::getLatestValidCoupons` uses `valid()` — correct |
| `Attribute`/`AttributeValue` | `status`? | — | Local | Not directly public via `general/*` (product relations) |

---

## 4. Affected Endpoints (`general/*`)

| Route | Controller | Method | Repository/Service | Primary Model | Relationships | Active Enforcement | Bypass | Resource |
|-------|------------|--------|------------------|--------------|---------------|------------------|--------|----------|
| `GET general/products` | `ProductController@index` | `index` | `ProductService::buildFilteredBaseQuery` + `ProductStrategyResolver` | `Product` | `categories.parent`, `variations`, `brands`, `tags`, `flash_sales valid`, `reviews` | **YES** (`active()`) on base query, but `categories.parent` not filtered for inactive categories | `with()` categories may include inactive | `ProductMiniResource` |
| `GET general/products/{slug}` | `ProductController@getProductBySlug` | `getProductBySlug` | `ProductService` | `Product` | same as above + `categories`, `brands` | **YES** on product (`active()`), but related `categories` not filtered | `with()` | `ProductResource` |
| `GET general/categories` | `CategoryController@index` | `paginate` | `CategoryService::paginate` | `Category` | `products` count, `media` | **YES** (`active()`) on categories | `withCount('products')` counts inactive too (no filter) | `CategoryHomeResource` |
| `GET general/categories/{slug}` | `CategoryController@getCategoryBySlug` | `getBySlug` | `CategoryService::getBySlug` | `Category` | `products` (via `applyChannelHomeFilter` only), `children` (active), `products` count | **PARTIAL** — category active YES, `products` NO, `children` YES | `with(['products' => fn])` leaks inactive products | `CategoryWithChildResource` |
| `GET general/brands` | `BrandController@index` | `index` | `BrandService::getBrands` | `Brand` | `products` | **YES** on brands, `products` NO | `with(['products' => fn])` | `BrandResource` |
| `GET general/brands/{slug}` | `BrandController@getBrandBySlug` | `getBrandBySlug` | `BrandService` | `Brand` | `products` | **PARTIAL** brand active YES, products NO | `with` | `BrandResource` |
| `GET general/brands-products` | `BrandController@getBrandsProductsByQtySet` | `getBrandsProductsByQtySet` | `BrandService` | `Brand` | `products` | **YES** on brands, products NO | `with` | `ProductMiniResource` |
| `GET general/tags` / `tags/{slug}` | `TagController` | `index`/`show` | `Tag` query | `Tag` | `products` | Tag has no status, but products should be active | `with('products')` not filtered | `TagResource` |
| `GET general/banners` / `sliders` / `flash-sales` / `promotions` / `coupons` / `settings` / `faqs` etc. | Various | `index` | Home/Brand services | `Banner`/`Slider` etc. | — | **YES** (`active()`/`valid()`) | — | Various |
| `GET general/home` / `nav-data` | `HomeController` | `navData`/`getHomeData` | `HomeService` | `Category`/`Product`/`Brand`/`Banner` etc. | Multiple (categories, products, flash_sale_products) | **PARTIAL** — categories via `active()`, products via `activeStatus()` (not `active()`), products relations not filtered | `with()` | `CategoryNavbarResource`, `ProductMiniResource` |
| `Search` (`general/products?search=`) | `ProductController` via `ProductService::buildScoutSearchQuery` | `buildScoutSearchQuery` | Scout `Product::search` + `Product::active()->whereIn` | `Product` | Same as products | **YES** after filter, but index contains inactive | `ProductCollectionMini` |
| `general/orders`, `general/checkout`, `general/digital` etc. | Authenticated `general/*` | Various | `OrderService` | `Order` | `products` via order Products | Orders are user-scoped, product visibility within order history should show historical (even inactive) — **do not filter** | — | `OrderResource` |

Admin/Dashboard routes under `v1/admin/*` and `v1/*` admin prefixes use separate services/repositories that **do not** call `active()` — verified via `ProductRepository` (no active), `CategoryRepository` etc. Must preserve.

---

## 5. Affected Relationships (public)

- `Category::products(): BelongsToMany Product` — **not filtered** — leak via `general/categories/{slug}` and `home` categories
- `Brand::products(): BelongsToMany` — **not filtered**
- `Tag::products(): BelongsToMany` — **not filtered** (tag has no status, but products must be active)
- `Product::categories(): BelongsToMany Category` — **not filtered** — active product with inactive category will expose inactive category via `ProductResource`
- `Product::brands(): BelongsToMany Brand` — **not filtered**
- `Product::variations(): HasMany Variation` — variation has no active, but product's variations should maybe be filtered? Currently not filtered for inactive parent? Product variations are always returned with product, even if parent product inactive already filtered, so variation visibility tied to product active — okay, but if variation had status, not filtered.
- `Product::tags(): BelongsToMany Tag` — tags have no status, okay
- `Product::media`, `flash_sales` — `flash_sales` uses `valid()` filter, good
- `Category::children(): HasMany Category` — **correctly** `active()` in `CategoryService::getBySlug`, but `paginate`'s `products_count` not filtered
- `Category::parent(): BelongsTo` — not filtered, may expose inactive parent

---

## 6. Current Scope Behavior

- **No global scope** for active — correct (avoids breaking admin).
- **Local scopes:** `Product::scopeActive()` (status + stock), `Product::scopeActiveStatus()` (status only), `Category::scopeActive()` (status), `Brand::scopeActive()`, `Shop::scopeActive()`, etc.
- **Applied:** Public base queries (`ProductService::buildFilteredBaseQuery`, `CategoryService::paginate`, `BrandService::getBrands`, `HomeService::getBrands`, etc.) correctly call `active()`.
- **Not applied:** Relationship eager loads (`with(['products' => fn($q) => applyChannelHomeFilter($q)])`) **do not** call `active()` — bypass.
- **Bypass mechanisms:** Any `with()`, `whereHas()`, `withCount()` without `->active()` will leak; `withoutGlobalScopes()` not used (no global to bypass); `has()`/`whereRelation` also not filtered.

---

## 7. Public Visibility Rules

- `Product`: `status` true or `PUBLISH` **and** (`in_stock` true OR `stock_quantity - reserved_quantity >0`) — via `scopeActive()`. For home flash sale products, `activeStatus()` used (status only) — should be `active()` for consistency.
- `Category`: `status = 1` — via `scopeActive()`
- `Brand`: `status = 1` — via `scopeActive()`
- `Tag`: no status — always visible, but its `products` must be active
- `Variation`: no independent status — visibility tied to parent product active
- `Banner`/`Slider`: `active()` + `ordered()` — status + ordering
- `FlashSale`/`Promotion`/`Coupon`: `valid()` (status + date ranges)
- **Relationships:** When returning a parent, only include related models that themselves satisfy public visibility (e.g., `category.products` must be `active()`, `product.categories` must be `active()` if category is public-facing; if business rule is to show product even with inactive category, document but currently assume filter).

---

## 8. Admin Visibility Rules

- Admin/Dashboard queries **must not** filter `active` — they use direct `Model::query()` or repository without `active()` scope.
- Verified: `ProductRepository`, `CategoryRepository`, `BrandRepository`, `TagRepository`, `AdminProductController`, `AdminCategoryController`, `AdminBrandController` — no `active()` call.
- Admin must see inactive via `GET /admin/products?status=inactive` or similar, edit, reactivate.
- Do not add global scope.

---

## 9. Search Implications

- **Indexing:** `Product::toSearchableArray()` indexes all products regardless of status — inactive are indexed.
- **Public search:** `ProductService::buildScoutSearchQuery` does `Product::search($term)->keys()` → `Product::active()->whereIn(id, scoutIds)` — filters inactive **after** search, so public search will not return inactive. Correct but depends on DB fallback; if Scout is configured to directly return documents (e.g., `Product::search($term)->get()` without DB filter), it would leak. Currently uses DB filter, so safe.
- **Stale index:** If product becomes inactive, Scout index not immediately cleared (async `queue` false currently sync, but if `SCOUT_QUEUE` enabled, async). Could temporarily search inactive until DB filter excludes — acceptable because DB filter excludes.
- **DB fallback:** `buildFilteredBaseQuery` with `search` param uses `applyProductSearch` (LIKE on name/details) + `active()` — also safe.

---

## 10. Cache Implications

- **Public cache:** `HomeService` caches `home-*` keys with channel+currency, 120s TTL, via `Cache::remember` — only public data, uses `active()`/`activeStatus()` correctly for base, but relationship leak propagates to cache (inactive products cached as part of category). Must fix source query.
- **Product listing cache:** `ProductController::index` caches via `FrontendResource::PRODUCTS` + `md5(fullUrl)` + `shouldCache(!$request->has('search'))` — search bypasses cache, good. Cache key includes channel+currency but not admin/public distinction — admin does not use same cache keys (admin routes not cached via `ProductController`), so no leak.
- **Category cache:** `CategoryService::paginate` cached via `FrontendResource::CATEGORIES` + `md5(fullUrl)` — same, admin not using same key.
- **Risk:** If admin hits same URL as public (e.g., `general/products` with same query), cache could be poisoned with inactive records if public query is incorrectly including inactive — fixing source eliminates risk.

---

## 11. Exact Files to Modify

| File | Function/Method | Change |
|------|-----------------|--------|
| `app/Services/General/CategoryService.php:39` | `paginate()` | `withCount('products')` → `withCount(['products' => fn($q) => $q->active()])` |
| `app/Services/General/CategoryService.php:51` | `getBySlug()` | `'products' => fn($q) => applyChannelHomeFilter($q)` → `fn($q) => $q->active()->...` + also `withCount` already active, ensure `products` active |
| `app/Services/General/BrandService.php:39` | `getBrandBySlug()` | `load(['products' => fn($q) => ...])` add `->active()` |
| `app/Services/General/BrandService.php:58` | `getBrandsProductsByQtySet()` | `with(['products' => fn($q) => ...])` add `->active()` |
| `app/Services/General/ProductService.php:72` | `productRelations()` | `categories.parent` → constrain to `active()` via `with(['categories' => fn($q) => $q->active(), 'categories.parent' => ...])` or add `whereHas`? Minimal: add `categories` active filter in `with` and `withCount` |
| `app/Services/General/ProductService.php:79` | `buildFilteredBaseQuery()` | Already `active()` on base, but `productRelations` categories need active filter — modify `productRelations()` to filter `categories` |
| `app/Services/General/HomeService.php:201`, `236`, `268` etc. | `getDiscountEndingTodayOrLowStockProducts()`, `getNewArrivals()`, `getFlashSaleProductsEndingThisWeek()`, etc. | Change `activeStatus()` → `active()` for consistency (include stock check) |
| `app/Services/General/HomeService.php:67` | `getCategoryTree()` / `getCategories()` / `getCategoryWithChildren()` | Ensure `active()` on categories and products within those helpers (inspect each helper) |
| `packages/marvel/src/Database/Models/Category.php:94` | `products()` | Optionally add `->where('status',1)` at relationship definition? **Do not** — would break admin. Keep local scope, apply in public services. |
| `packages/marvel/src/Database/Models/Brand.php:??` | `products()` | Same — keep local, apply in public services |
| `app/Http/Resources/Category/CategoryWithChildResource.php` | `toArray()` | Verify it does not bypass — no change needed if source query fixed |
| `app/Http/Resources/Product/ProductResource.php` | `toArray()` | Verify `categories`, `brands` not exposing inactive — no resource filtering, rely on query |

*Do not modify admin services/repositories: `ProductRepository`, `CategoryRepository`, `Admin*Controller`.*

---

## 12. Exact Functions/Methods to Modify

- `CategoryService::paginate()`: add active filter to `withCount`
- `CategoryService::getBySlug()`: add `active()` to `products` eager load
- `BrandService::getBrandBySlug()`: add `active()` to `products` load
- `BrandService::getBrandsProductsByQtySet()`: add `active()` to `products` with
- `ProductService::productRelations()`: change to `['categories' => fn($q) => $q->active(), 'categories.parent' => fn($q) => $q->active(), 'brands' => fn($q) => $q->active(), 'variations', 'media', 'flash_sales' => fn($q) => $q->valid(), 'tags']` — but `variations` maybe not needed
- `HomeService::getDiscountEndingTodayOrLowStockProducts()`, `getNewArrivals()`, `getFlashSaleProductsEndingThisWeek()`, `getWeeklyCategoryProducts()`, `getAllDiscountProducts()`: change `activeStatus()` → `active()`

---

## 13. Tests to Add/Update

- **Products:** `active product -> general/products -> returned`, `inactive product -> general/products -> NOT returned`, `active -> admin -> returned`, `inactive -> admin -> returned` (already have `tests/Feature/Products/ProductVisibilityTest.php`? verify and add if missing)
- **Categories:** `active category -> general/categories -> returned`, `inactive -> general/categories -> NOT returned`, admin returns both
- **Brands:** same as categories
- **Relationships:** `active category + active product` (category show includes product), `active category + inactive product` (product excluded), `inactive category + active product` (category not returned, product via direct listing still returned), `active product + inactive brand` (brand not included in product resource if brand inactive), `active product + inactive tag` (tag has no status, but test inactive product not in tag products)
- **Search:** `search term matching inactive product` -> not returned via Scout or DB fallback
- **Cache:** verify `general/products` cache does not return inactive after product deactivated (clear and re-fetch)
- **Existing suites:** Update `CategoryQueueAssignmentTest`, `ProductRepositoryTest`, etc. that may assert inactive in public

---

## 14. Risks

- **Over-filtering:** Adding `active()` to relationship could hide products that should be visible via order history (authenticated `general/orders` should show historical inactive products) — do not filter order's products (order history is user-scoped, not public catalog).
- **Admin breakage:** If global scope added, admin loses inactive visibility — do not use global scope.
- **Cache poisoning:** Fixing source eliminates, but existing cache may still serve stale inactive — need cache clear on deploy.
- **Performance:** Adding `where('status',1)` to eager loads is indexed (assuming index on status) — negligible.
- **Business rule mismatch:** If inactive category should still allow active product to be sold (product active but category inactive), filtering `product.categories` to active may remove category from product response — verify business: currently assume category must be active to be shown, but product itself remains visible via direct listing.

---

## 15. Backward-Compatibility Considerations

- **API contract:** Response envelope (`success`, `message`, `data`, `meta`) preserved; only content of `data` filtered — inactive records removed from public, which is **expected** breaking change for clients relying on seeing inactive (none should, public should only see active).
- **Pagination:** Total count will decrease (inactive excluded) — clients must handle variable total.
- **Search:** Same — total hits decrease.
- **Admin:** No contract change — admin still sees inactive.

---

*Plan internally validated: public `general/*` must be active-only where entity has status, admin must see all, no global scope, fix at query/service level for base and relationships, search and cache consistent.*

