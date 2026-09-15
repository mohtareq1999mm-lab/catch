# CURSOR PAGINATION — PRODUCT ENDPOINT AUDIT & IMPLEMENTATION PLAN

**Endpoint:** `GET /v1/general/products`
**Mode:** STRICT READ-ONLY AUDIT — no implementation performed
**Date:** 2026-09-14
**Framework:** Laravel `10.30.1` (VERIFIED — `composer.json:22`)

---

## 1. EXECUTIVE SUMMARY

`GET /v1/general/products` has **two runtime flows** selected in `ProductController::index()`:

1. **Strategy flow** (`?type=…`) — resolved via `ProductStrategyResolver` (10 strategies). Exactly **one** strategy (`index` → `AllProduct`) returns a real `LengthAwarePaginator`; the other **nine** return a capped, un-paginated `Collection` (`limit()->get()`).
2. **Fallback flow** (no `type`) — dispatches to either a **Scout search** path (`buildScoutSearchQuery`, relevance `FIELD()` + offset) or a **filtered SQL** path (`buildFilteredBaseQuery`, `ORDER BY [price,] id`, offset).

**Headline conclusion:**

- **`CURSOR_SUPPORTED`** — non-search, paginated, `id`-ordered scenarios (fallback default/`order=asc|desc`, and `type=index` no-search). `id` is the primary key → unique → deterministic → trivially keyset-paginable.
- **`CURSOR_SUPPORTED_WITH_CONDITION`** — `order_price` (price sort). `products.price` is **nullable**, so `ORDER BY price, id` cursor is only valid after an explicit NULL-price policy. No other condition.
- **`OFFSET_ONLY`** — all search scenarios (Scout `FIELD()` relevance, and the SQL `LIKE` path under `type=index&search`). Relevance ordering cannot be expressed as an indexed keyset boundary.
- **`NOT_APPLICABLE`** — the 9 Collection-returning strategies. They are **single-page** results (`limit()->get()`), not paginated; there is nothing to cursor-paginate.

**Final decision (Section 27): `GO WITH CONDITIONS`.**

---

## 2. CURRENT PRODUCT ENDPOINT ARCHITECTURE

### 2.1 Full request trace (VERIFIED)

| Step | File | Class | Method | Line | Responsibility |
|------|------|-------|--------|------|----------------|
| Prefix | `routes/api.php` | — | — | `:47` | `Route::prefix('v1/general')` |
| Middleware | `routes/api.php` | — | — | `:48` | `['api', 'throttle:public-api']` |
| Route | `routes/api.php` | — | — | `:83` | `Route::get('products', [ProductController::class, 'index'])` |
| API group | `app/Http/Kernel.php` | `Kernel` | `$middlewareGroups['api']` | `:34-45` | `throttle:api`, `SubstituteBindings`, `ChannelMiddleware` (`:47`), `CheckLangMiddleware` |
| Channel | `app/Http/Middleware/ChannelMiddleware.php` | `ChannelMiddleware` | `handle()` | `:14` | Sets `ChannelContext` from `X-Channel` header (default `home`) |
| Language | `app/Http/Middleware/CheckLangMiddleware.php` | `CheckLangMiddleware` | `handle()` | `:16` | Sets locale from `lang` **header** (`en`/`ar`, default `en`) — not a query param |
| Global scope | `app/Models/Scopes/FastShippingScope.php` | `FastShippingScope` | `apply()` | `:9` | If channel = `fast_shipping` → `where is_fast_shipping_available = true` |
| Request | `app/Http/Requests/ProductIndexRequest.php` | `ProductIndexRequest` | `rules()` | `:15` | Validates only `type` (enum of strategy keys) and `order` (`asc`/`desc`) |
| Controller | `app/Http/Controllers/Api/General/ProductController.php` | `ProductController` | `index()` | `:51` | Dispatch + cache + envelope |
| Controller | same | same | `buildStrategyResponse()` | `:79` | Strategy flow |
| Controller | same | same | `buildFallbackResponse()` | `:114` | Fallback (search vs filter) flow |
| Controller | same | same | `shouldCache()` | `:145` | `!$request->has('search')` |
| Resolver | `app/Services/General/ProductEngine/ProductStrategyResolver.php` | `ProductStrategyResolver` | `resolve()` | `:35` | `type` → strategy class |
| Service | `app/Services/General/ProductService.php` | `ProductService` | `buildFilteredBaseQuery()` | `:87` | Active + relations + filters |
| Service | same | same | `buildScoutSearchQuery()` | `:110` | Scout `whereIn` + `FIELD()` |
| Service | same | same | `paginate()` | `:148` | `orderBy('id')->paginate()` (strategy `index`) |
| Filter | `app/Services/General/ProductFilter.php` | `ProductFilter` | `apply()` | — | All filter params |
| Model | `packages/marvel/src/Database/Models/Product.php` | `Product` | `scopeActive()` | `:559` | Visibility scopes |
| Model | same | same | `scopeFilter()` | `:662` | Delegates to `ProductFilter` |
| Resource | `packages/marvel/src/Http/Resources/product/ProductCollectionMini.php` | `ProductCollectionMini` | `toArray()` | — | `data` + `links` (offset) |
| Resource | `app/Http/Resources/Product/ProductMiniResource.php` | `ProductMiniResource` | `toArray()` | — | Per-product fields |
| Envelope | `packages/marvel/src/Traits/ApiResponse.php` | `ApiResponse` | `apiResponse()` | `:9` | `{status,message,success,data}` |

### 2.2 Authentication / authorization (VERIFIED)

- No auth middleware. Public storefront endpoint. `ProductIndexRequest::authorize()` returns `true` (`:11`).
- Visibility/authorization is **scope-based**, not user-based: `active()` scope + `FastShippingScope` (global) + `applyChannelHomeFilter`.

### 2.3 Parameter transformation by middleware (VERIFIED)

Global middleware `TrimStrings` + `ConvertEmptyStringsToNull` (`Kernel.php:16-17`) normalize inputs: whitespace-only `type` → trimmed → null → defaults to `index` (confirmed by `ProductsEndpointTest::test_whitespace_strategy_type_is_treated_as_empty`).

---

## 3. COMPLETE PARAMETER INVENTORY

Verified against `ProductIndexRequest`, `ProductController`, `ProductService`, `ProductFilter`, and a repository-wide grep for candidate param names (`sort`, `sort_by`, `order_by`, `sortedBy`, `status`, `is_approved`, `featured`, `shop_id`, `locale`, `language`). **A parameter is reported only if present.**

| Parameter | Source | Type | Default | Validated | Effect on query | Affects ordering? | Cursor compatible? |
|-----------|--------|------|---------|-----------|-----------------|-------------------|--------------------|
| `type` | query | string | `index` | Yes (`ProductIndexRequest:17`) | Selects strategy | strategy-dependent | N/A (see §4) |
| `order` | query | `asc`/`desc` | `desc` | Yes (`:18`) | Sort direction for `id` (and `price` tie-breaker) | YES | YES |
| `order_price` | query | `asc`/`desc` | none | **No** | Adds `ORDER BY price` before `id` (fallback only) | YES | CONDITIONAL (NULL price) |
| `search` | query | string | none | No | Switches to Scout/SQL-LIKE search; bypasses cache | YES (relevance) | **NO** |
| `limit` | query | int | `15` | No | Clamped 1–100 (`getLimit():826-834`) | No | YES |
| `page` | query | int | 1 | No (Laravel) | Offset page | No | YES (offset) |
| `cursor` | query | string | — | **Absent — not implemented** | — | — | — |
| `brand` | query | str/array | none | No | `whereHas('brands')` (name/slug → ids) | No | YES |
| `category` | query | str/array | none | No | `whereHas('categories')` + descendant expansion | No | YES |
| `promotion` | query | str/array | none | No | `whereHas('promotions')` by slug | No | YES |
| `flash_sale` | query | str/array | none | No | `whereHas('flash_sales')` by slug/title | No | YES |
| `banner` | query | str/array | none | No | `whereHas('banners')` | No | YES |
| `tag` / `tags` | query | str/array | none | No | `whereHas('tags')` AND-logic | No | YES |
| `slider` | query | str/array | none | No | `whereHas('sliders')` | No | YES |
| `minPrice` / `price_min` | query | numeric | none | No | `products.price >= x` OR variant price | No | YES (aliases) |
| `maxPrice` / `price_max` | query | numeric | none | No | `products.price <= x` OR variant price | No | YES (aliases) |
| `height`/`width`/`length`/`weight` | query | str/array | none | No | Exact dimension match (product OR variant) | No | YES |
| `height_min`/…/`weight_max` | query | numeric | none | No | `REGEXP_REPLACE` numeric range (`applyDimensionRange:664`) | No | YES |
| `rating` | query | numeric | none | No | `AVG(rating) >= x` subquery (`:626`) | No | YES |
| `rating_min`/`rating_max` | query | numeric | none | No | `whereHas reviews rating between` (`:616`) | No | YES |
| `productsId` | query | comma-list | none | No | `whereIn('id')` (`:777`) | No | YES |
| `categoriesId`/`brandsId`/`tagsId`/`promotionsId`/`flashSalesId`/`bannersId`/`couponsId`/`slidersId` | query | comma-list | none | No | `whereHas(relation)` (`:792`) | No | YES |
| dynamic attribute slugs | query | str/array | none | No | `whereHas('variations.attributeProducts')` | No | YES |
| `start_date` / `end_date` | query | date | none | No | Strategy source-set filters (flash-sale/brand strategies only) | No | N/A (strategy) |
| `X-Channel` | **header** | enum | `home` | No (middleware) | Sets channel context | No | YES |
| `lang` | **header** | `en`/`ar` | `en` | No (middleware) | Sets locale | No | YES |

### 3.1 Aliases, transforms, ignored, and ABSENT parameters (VERIFIED)

- **Aliases:** `price_min`≡`minPrice`, `price_max`≡`maxPrice`, `tag`≡`tags`.
- **Transformed:** `category` name/slug → ids + **recursive descendant expansion** (`ProductFilter::expandWithDescendants`); `brand`/`banner`/`slider`/`tag`/`promotion`/`flash_sale` → ids; dynamic attribute values → `AttributeValue` ids; dimension values → `(string)(float)`.
- **Partially effective:** `order_price` is honored **only** in the fallback flow (`ProductController:124-127`), **ignored** by `type=index` (`ProductService::paginate()` orders only by `id`, `:154`). Confirmed by `ProductsEndpointTest::test_order_price_does_not_sort_by_price_in_default_index_flow`.
- **Ignored entirely by the 9 Collection strategies:** all `*Id`, price-range, rating, dimension filters (they do not call `applyProductFilters`).
- **ABSENT (do NOT assume):** `sort`, `sortedBy`, `sort_by`, `order_by`, `status`, `is_approved`, `featured`, `shop_id`, `locale`, `language`, `category_id`, `brand_id`. Verified absent via grep — the only `sort_by`/`orderBy` usages in the app layer belong to Order/Invoice/Currency/Tag controllers, not Products.

---

## 4. COMPLETE PRODUCT STRATEGY INVENTORY

`ProductStrategyResolver::STRATEGIES` (`ProductStrategyResolver.php:18-32`).

| Strategy key | Class | Service method (`ProductService.php`) | Return | Ordering | Pagination | Cursor compatible? | Reason |
|--------------|-------|---------------------------------------|--------|----------|------------|--------------------|--------|
| `index` | `AllProduct` | `paginate()` `:148` | `LengthAwarePaginator` | `ORDER BY id {order}` | `paginate()` offset | **YES** | `id` unique; query = `buildFilteredBaseQuery` |
| `best_product_sales` | `BestProduct` | `getBestProductSales()` `:535` | `Collection` | `orderByDesc('sold_quantity')` | `limit()->get()` | **NOT_APPLICABLE** | not paginated |
| `brands_product` | `ProductForBrand` | `getBrandsProductsByQtySet()` `:417` | `Collection` | none (per-brand `limit`) | `limit()->get()` | **NOT_APPLICABLE** | not paginated |
| `new_arrivals` | `NewArrivals` | `getNewArrivals()` `:448` | `Collection` | `orderByDesc('created_at')` | `limit()->get()` | **NOT_APPLICABLE** | not paginated |
| `all_product_discounts` | `AllProductHasDiscount` | `getAllDiscountProducts()` `:389` | `Collection` | `orderByDesc('id')` | `limit()->get()` | **NOT_APPLICABLE** | not paginated; post-query `filter(isDiscountActive)` `:407` |
| `product_discount_today_or_low_qty` | `ProductDiscountEndingTodayOrLowStock` | `getDiscountEndingTodayOrLowStockProducts()` | `Collection` | none | `limit()->get()` | **NOT_APPLICABLE** | not paginated; non-deterministic order |
| `flash_sales_product` | `ProductHasFlashSale` | `getFlashSalesAndHereProductsByQtySet()` | `Collection` | none (per-flash-sale `limit`) | `limit()->get()` | **NOT_APPLICABLE** | not paginated |
| `flash_sales_end_today` | `ProductHasFlashSaleEndToday` | `getFlashSaleProductsEndingToday()` `:349` | `Collection` | `orderByDesc('id')` | `limit()->get()` | **NOT_APPLICABLE** | not paginated |
| `flash_sales_end_week` | `ProductHasFlashSaleEndThisWeek` | `getFlashSaleProductsEndingThisWeek()` `:315` | `Collection` | `orderByDesc('id')` | `limit()->get()` | **NOT_APPLICABLE** | not paginated |
| `product_for_parent_category` | `ProductForParentCategory` | `getProductForParentCategory()` `:558` | `Collection` | `orderByDesc('id')` | `limit()->get()` | **NOT_APPLICABLE** | not paginated |

Controller renders Collection strategies via `buildSimpleLinks()` (`ProductController:158`) → `per_page = total`, `last_page = 1`, `next_page_url = null` (single page).

---

## 5. COMPLETE SCENARIO MATRIX

The **fallback non-search** path base query shape (VERIFIED):

```
SELECT products.* (+ withAvg/withCount subqueries)
FROM products
WHERE  active() scope
       FastShippingScope (if channel=fast_shipping)
       applyChannelHomeFilter (is_fast_shipping_available = 0 if channel=home)
       ProductFilter + ids + relation-ids + rating + dimension filters
       [applyProductSearch LIKE  — only in type=index flow]
ORDER BY [price if order_price] , id {order}
LIMIT/OFFSET
```

| Scenario | Query path | WHERE/JOIN | ORDER BY | Deterministic? | Cursor boundary columns | Cursor possible? | Index needed? | Classification |
|----------|-----------|------------|----------|----------------|--------------------------|------------------|---------------|----------------|
| `/products` (fallback, no search) | `buildFilteredBaseQuery` | active+channel | `id {order}` | YES | `id` | YES | no (PK) | CURSOR_SUPPORTED |
| `/products?order=asc\|desc` | fallback | active+channel | `id {order}` | YES | `id` | YES | no | CURSOR_SUPPORTED |
| `/products?order_price=asc\|desc` | fallback | active+channel | `price {dir}, id {order}` | YES | `price`, `id` | CONDITIONAL | optional `(price,id)` | CURSOR_SUPPORTED_WITH_CONDITION |
| `/products?brand=X` | fallback | `whereHas brands` | `id` | YES | `id` | YES | pivot idx | CURSOR_SUPPORTED |
| `/products?category=X` | fallback | `whereHas categories` (+descendants) | `id` | YES | `id` | YES | `idx_cat_prod_category_product` | CURSOR_SUPPORTED |
| `/products?minPrice/maxPrice` | fallback | price OR variant | `id` | YES | `id` | YES | `idx_products_status_deleted_price`/`price` | CURSOR_SUPPORTED |
| `/products?productsId=…` | fallback | `whereIn id` | `id` | YES | `id` | YES | PK | CURSOR_SUPPORTED |
| `/products?categoriesId/…Id` | fallback | `whereHas relation` | `id` | YES | `id` | YES | pivot idx | CURSOR_SUPPORTED |
| `/products?rating[_min/_max]` | fallback | `whereHas reviews`/subquery | `id` | YES | `id` | YES | — | CURSOR_SUPPORTED |
| `/products?height_min…` | fallback | `whereRaw REGEXP_REPLACE` | `id` | YES | `id` | YES | — | CURSOR_SUPPORTED |
| dynamic attribute slug | fallback | `whereHas variations.attributeProducts` | `id` | YES | `id` | YES | — | CURSOR_SUPPORTED |
| **filter + `order_price`** | fallback | filter + price sort | `price, id` | YES | `price`,`id` | CONDITIONAL | optional | CURSOR_SUPPORTED_WITH_CONDITION |
| `/products?search=X` | fallback Scout | `whereIn id` + `FIELD()` | `FIELD, id` | YES but relevance | n/a | **NO** | n/a | OFFSET_ONLY |
| `?type=index` (no search) | `paginate()` | active+channel | `id {order}` | YES | `id` | YES | no | CURSOR_SUPPORTED |
| `?type=index&search=X` | `paginate()` → SQL `LIKE` | LIKE multi-field | `id {order}` | YES | `id` | **NO (policy: search)** | n/a | OFFSET_ONLY |
| `?type=best_product_sales` | `getBestProductSales` | none | `sold_quantity desc` | NO | n/a | N/A | n/a | NOT_APPLICABLE |
| other 8 strategies | Collection | various | various | varies | n/a | N/A | n/a | NOT_APPLICABLE |

**Structural note (VERIFIED):** no `join`, `groupBy`, `distinct`, or `union` is ever applied to the main product query. `withAvg`/`withCount`/`whereHas` are subqueries; **result cardinality and ordering are never affected** by eager loading/aggregation.

---

## 6. ORDERING / DETERMINISM AUDIT

| Sort | Direction | Where used | Query | Deterministic? | Tie-breaker | Cursor compatible? |
|------|-----------|------------|-------|----------------|-------------|--------------------|
| `id` | asc/desc (default desc) | fallback + `index` | `orderBy('id', $order)` (`ProductService:154`, `ProductController:128`) | YES (PK unique) | implicit | YES |
| `price`+`id` | price dir + id dir | fallback only | `orderBy('price',$orderPrice)`+`orderBy('id',$order)` (`:126-128`) | YES (id tie-break) | `id` | CONDITIONAL (NULL) |
| `FIELD(id,…)` | relevance | fallback search | `orderByRaw("FIELD(...)")` (`ProductService:137`) | YES | `id` (moot) | NO |
| `sold_quantity` | desc | best_product_sales | `orderByDesc('sold_quantity')` (`:547`) | NO | none | N/A (not paginated) |
| `created_at` | desc | new_arrivals | `orderByDesc('created_at')` (`:465`) | NO (same-second ties) | none | N/A (not paginated) |
| *(none)* | — | discounts-today, brands_product, flash_sales_product | no `orderBy` | NO | none | N/A (not paginated) |

**Absent sorts (do NOT assume):** no `name`, `slug`, `rating`, `updated_at`, `discount`, `sold_quantity` (as a listing sort), or `created_at` (as a listing sort) exists on the paginated listing endpoint. The only paginated orderings are `id` and `price,id`.

**Determinism table:**

| Ordering | Primary | Tie-breaker | Unique? | Stable? |
|----------|---------|-------------|---------|---------|
| `ORDER BY id desc/asc` | id | none needed | YES (PK) | YES |
| `ORDER BY price desc, id desc` | price | id | YES | YES (barring NULL/mutation) |
| `ORDER BY FIELD(id,…), id` | FIELD | id | YES | YES (search only) |
| `ORDER BY sold_quantity desc` | sold_quantity | none | NO | NO |
| `ORDER BY created_at desc` | created_at | none | NO | NO |

---

## 7. PRICE SORT DEEP AUDIT

### A–C. Column vs displayed value (VERIFIED)

- **DB column sorted:** `products.price` — `decimal(10,2) NULLABLE` (`2020_06_02_051901_create_marvel_tables.php:111`).
- **Returned values:** `ProductMiniResource` returns `price` = `convertCatalogPrice($this->price)` (currency-converted) and `current_price` = tax-inclusive + currency-converted effective price (flash-sale → discount → base), via `enrichProductWithPricing` (`ProductService:39-53`).
- **The SQL sort key (`products.price`) is NOT the displayed `current_price`.** Sorting is by base catalog price, not the effective/discounted/flash price.

### D. Nullability (VERIFIED)

`price` is nullable. MySQL: `ORDER BY price ASC` → NULLs first; `DESC` → NULLs last. Cursor predicates use `>`/`<`/`=`, all of which are `NULL` (false) when compared against `NULL` → **NULL-price rows are unreachable / terminate pagination** when `price` is a cursor key. **Do NOT auto-apply `COALESCE(price,0)`** — that would change business semantics (a free/NULL-price product would be sorted as 0).

### E. Mutability (VERIFIED)

`price` is `fillable` (admin-editable). A price change moves the row → duplicate/missing risk across the cursor boundary under `price` sort.

### F. Deterministic ordering achievable? (VERIFIED/INFERRED)

`ORDER BY price DESC, id DESC` is deterministic. Boundary: `WHERE price < :p OR (price = :p AND id < :id)` (Laravel generates this — `BuildsQueries.php:405-420`). Valid **only after** a NULL-price policy.

### G. Index (see §14)

Existing: standalone `price` index + composite `(status, deleted_at, price)`. A dedicated `(price, id)` composite is **optional** (not required for correctness; improves deep price-cursor seeks).

### Verdict

`ORDER BY price, id` is **CURSOR_SUPPORTED_WITH_CONDITION** (NULL-price policy required). It is **not** an architecture blocker per se, but the **displayed-vs-sorted price mismatch** is a documented semantic nuance (§17) that pre-exists and is unchanged by cursor.

---

## 8. ACTIVE / VISIBILITY AUDIT

Execution order (all **before** ORDER BY/LIMIT — VERIFIED):

1. `FastShippingScope` global (`FastShippingScope.php:9`): channel `fast_shipping` → `is_fast_shipping_available = 1`.
2. `scopeActive()` (`Product:559`):
   - `activeStatus()` (`:548`): `status = true` OR `CAST(status AS CHAR) = 'publish'`.
   - stock gate: `in_stock = true` OR `(COALESCE(stock_quantity,0) - COALESCE(reserved_quantity,0)) > 0`.
   - category gate: no categories OR all categories `active()`.
   - brand gate: no brands OR all brands `active()`.
3. `applyChannelHomeFilter` (`HasChannelFilter.php:9`): channel `home` → `is_fast_shipping_available = false`.
4. Business filters (`ProductFilter` + ids + relation-ids + rating + dimensions).
5. Search (Scout `whereIn` or SQL `LIKE`).
6. `ORDER BY`.
7. `LIMIT/OFFSET` (or future cursor boundary).

**Invariant satisfied:** cursor boundary is appended by Laravel to the same already-scoped query (`$builder->where(...)`), so it re-runs identical `active()`/channel/filter predicates. The cursor can only move **within** the filtered set; it cannot leak inactive/unauthorized/channel-excluded/out-of-stock rows. Confirmed by tests: `test_out_of_stock_products_are_excluded_by_active_scope`, `test_inactive_products_are_excluded`, `test_soft_deleted_products_are_excluded`, `test_deleted_category_returns_empty_results`.

---

## 9. CURSOR BOUNDARY DESIGN

### 9.1 `id` only (default / `order=asc|desc`) — CURSOR_SUPPORTED

- Cursor columns: `['id']` (auto-derived from `orderBy('id', $order)`).
- desc: `WHERE id < :cursor_id`; asc: `WHERE id > :cursor_id`.
- `id` never null (PK). Fully compatible.

### 9.2 `price,id` (`order_price`) — CONDITIONAL

- Cursor columns: `['price','id']`.
- asc/asc: `WHERE price > :p OR (price = :p AND id > :id)` (recursively generated; verified `BuildsQueries.php:405-420`).
- NULL price breaks both branches (see §7.D).

### 9.3 Cursor token (framework-verified — do not re-invent)

- `Cursor::encode()` = `base64url(json_encode(parameters + ['_pointsToNextItems'=>bool]))` (`Cursor.php:111`), `+`,`/`,`=` → `-`,`_`,``.
- `parameters` = ORDER BY column names (`['id']` or `['price','id']`) from `$orders->pluck('column')` (`BuildsQueries.php:450`).
- Invalid/malformed token → `Cursor::fromEncoded` returns `null` (`Cursor.php:80-92`) → paginator treats as first page (no exception, HTTP 200).
- Treat token as **opaque client token**.

---

## 10. SEARCH SEPARATION

### 10.1 Fallback Scout path (VERIFIED)

`buildScoutSearchQuery()` (`ProductService:110`): `Product::search($term)->keys()` → `whereIn('products.id', $scoutIds)` → `orderByRaw("FIELD(products.id, …)")` → controller appends `orderBy('id',$order)` (`:119`) → `paginate()`.

- `Product` uses `Searchable` trait (`Product.php:24`); `shouldBeSearchable()` gates index to active/in-stock (`:76`).
- Local `.env` `SCOUT_DRIVER=database`; `config/scout.php` default `collection`; Meilisearch host/key configured. **Production driver = NOT VERIFIED (BLOCKED).**

**Why not cursor:** `FIELD()` is relevance order. Laravel's `ensureOrderForCursorPagination` filters orders to those **with a `direction`** (`BuildsQueries.php:1009-1011`); `orderByRaw` has none → only `id` would become the cursor column, but the real `ORDER BY` is `FIELD(...) FIRST` → wrong/duplicate/missing results. **Do not attempt; do not invent Meilisearch `search_after`.**

### 10.2 `type=index` SQL-LIKE path (VERIFIED)

`paginate()` → `buildFilteredBaseQuery()` → `applyProductSearch()` (`:720`) — multi-field `LIKE` (name/description/dimensions/sku/reviews/categories). `ORDER BY id`. Technically cursor-able on `id`, but grouped under search policy (below).

### 10.3 Search + cursor policy (RECOMMENDATION)

The spec-preferred policy is **`422 Unsupported Combination`**. However, given this endpoint already returns `422` for invalid `type`/`order` (`ProductsEndpointTest::test_invalid_strategy_type_returns_422`, `test_invalid_order_value_returns_422`), and to avoid silently producing a *different* result set than the client requested, **`422` is recommended and consistent with existing validation behavior**. Backward-compat impact: none for existing clients (they never sent `pagination=cursor` before). Recommendation: validate `pagination` in `ProductIndexRequest` and return `422` when `pagination=cursor` is combined with `search`.

---

## 11. CURSOR TOKEN / API CONTRACT AUDIT

- **Laravel version:** `10.30.1` (VERIFIED).
- **Invalid cursor:** `fromEncoded` → `null` → first page (HTTP 200). No 400/422 by framework default.
- **Cursor from another ordering / changed sort / changed strategy / changed filters:** tokens carry only sort-key values; they are **not** bound to filters/sort. A stale cursor yields a valid-but-wrong window. See §12.
- **Missing cursor:** first page.
- **Exact HTTP behavior:** framework does not throw for malformed cursor; the app must decide whether to add validation (see §12 recommendation — no fingerprint; rely on client reset).

---

## 12. FILTER CHANGE + CURSOR SAFETY

Scenario `category=10&sort=price_desc` → `category=20&cursor=<old>`: cursor values are not filter-bound, so the request returns a **valid but semantically wrong** window (pages within the new filter set from an arbitrary position). No exception, no data leak (filters still applied).

**Contract (RECOMMENDATION — simplest correct):** the client MUST reset (drop) the cursor whenever any of `filter`, `sort` (`order`/`order_price`), `strategy` (`type`), `limit`, `lang` header, `X-Channel` header, or effective currency changes. No server-side fingerprint/hash — YAGNI for a public read feed; filters are still always applied so there is no security concern.

---

## 13. CONCURRENCY / MUTABILITY

Cursor guarantees **contiguous, non-duplicated, non-missing pages only under a static sort key**. It does **not** provide snapshot isolation.

| Mutation | `ORDER BY id` | `ORDER BY price,id` |
|----------|---------------|---------------------|
| Insert (higher id) | appears at head; forward-pager never sees it (correct) | may reorder |
| Delete | earlier pages fine; later shift — no dup (id) | same + price shift |
| Deactivate/activate | row enters/leaves `active()` → dup/miss across boundary | same + price |
| Price change | unaffected | row moves → dup/miss |
| Category/brand change | row joins/leaves filter set → dup/miss | same + price |
| Stock change | affects `active()` stock gate → dup/miss | same |

**Separation:** cursor correctness ≠ snapshot consistency. For a storefront browse feed this is acceptable (eventually consistent). If business requires point-in-time snapshots, that is a **separate architectural requirement**, not a cursor defect — document it, do not build locking for this endpoint (YAGNI).

---

## 14. DATABASE / INDEX AUDIT

### 14.1 Existing indexes (VERIFIED — migration, not inferred)

`products` (`2020_06_02_051901_create_marvel_tables.php:139-149`):
- PK `id`
- `price`, `sold_quantity`, `name`, `slug`, `sku`, `is_fast_shipping_available`
- `idx_products_status_deleted_price (status, deleted_at, price)`
- `height`/`width`/`length`/`weight`
- **No index on `created_at` or `updated_at`** (`timestamps()` at `:137` unindexed).

Pivots: `category_product` → `idx_cat_prod_category_product (category_id, product_id)` (`:285`); `brand_product` (separate migration); `flash_sale_products` FK (`:164`); `product_variants.product_id`, `sku`, `price`, dims indexes (`:170-175`).

### 14.2 Index requirements per cursor query

| Query | Existing | Sufficient? | Missing |
|-------|----------|-------------|---------|
| `ORDER BY id` | PK | YES | none |
| `ORDER BY price, id` | standalone `price` + PK (usable via merge) | WORKABLE | optional `(price, id)` composite for pure deep price-cursor |
| `whereHas categories` | `idx_cat_prod_category_product` | YES | none |
| `whereHas brands` | brand pivot index | YES | none |
| `active()` stock gate | `status` prefix; no index on `stock_quantity`/`reserved_quantity` | PARTIAL | optional |

### 14.3 Production DB engine

- Local: `DB_CONNECTION=sqlite`, `CACHE_DRIVER=file`, `SCOUT_DRIVER=database` (VERIFIED, `.env` read-only).
- `phpunit.mysql.xml`/`phpunit.concurrency.xml` imply MySQL test/CI target (INFERRED).
- Code uses MySQL-family syntax (`REGEXP_REPLACE`, `FIELD()`, `CAST`, JSON `->`). **Production engine/version = NOT VERIFIED (BLOCKED).**

---

## 15. EXPLAIN FINDINGS

**No EXPLAIN / EXPLAIN ANALYZE was run.** Runtime DB access is not available in this environment (local DB is SQLite; no production access; shell read-only). All index recommendations are **shape-derived, not plan-verified**. Status: **NOT VERIFIED**.

---

## 16. RELATIONSHIPS / EAGER LOADING

`productRelations()` loads `categories`, `categories.parent`, `variations`, `brands`, `media`, `flash_sales`, `tags` (active-constrained); plus `withAvg`/`withCount` on reviews (`ProductService:87-92`).

- **Cardinality:** unaffected (all `with*`/`whereHas` are separate queries/subqueries; no joins). VERIFIED.
- **Ordering:** unaffected. VERIFIED.
- **Cursor compatibility:** eager loading is compatible; no query-level incompatibility. VERIFIED.
- **Pagination vs transformation:** pagination (`paginate()`) happens **before** pricing enrichment (`setCollection(map(enrichProductWithPricing))`, `ProductService:156-158`). Cursor must preserve this ordering (paginate → then enrich).

---

## 17. PRODUCT PRICING PIPELINE

1. **Returned price:** `current_price` = `ProductPricingService::calculateProductPricing()` → `final_price = flashSale ?? discount ?? base` (`ProductPricingService.php:42`), then tax (`ProductTaxPresenter::applyTo`) then currency conversion (`ConvertsProductPrice::convertCatalogPrice`).
2. **DB sort price:** raw `products.price` (catalog base). VERIFIED.
3. **Same value?** **No** — sort uses base `price`; display uses effective `current_price`. Pre-existing semantic mismatch.
4. **Mutated after pagination?** `current_price` is computed in-memory after pagination (`enrichProductWithPricing`). The sort key `price` is not mutated.
5. **Currency affects ordering?** Currency conversion is a linear multiply (monotonic) → relative order of `price` preserved; absolute values differ. Sorting is currency-agnostic. INFERRED.
6. **Promotions/discount/flash affect ordering?** No — sort ignores them.
7. **Cursor semantic validity:** for `price` sort, cursor stays valid (stable stored column), but clients may observe "displayed prices out of order" relative to `current_price` — a pre-existing behavior, not introduced by cursor. **Flag as documentation note, not a blocker.**

---

## 18. CACHE AUDIT

- **Gate:** `shouldCache()` = `!has('search')` (`ProductController:145`). Search bypasses cache.
- **Tag:** `products` (fallback) / `products_{type}` (strategy) (`:61/:64`).
- **Key:** `md5(fullUrl() . '|currency:' . effectiveCode)` (`:152`) — includes **all query params (incl. would-be `cursor`) and `page`**.
- **TTL:** 4h (`HasCache::remember`, `now()->addHours(4)`).
- **Invalidation:** `ProductObserver::flushProductCaches()` flushes `products` + `products_{type}` + `Cache::flush()` safety net (`ProductObserver:188-215`); `CategoryObserver`/`BrandObserver`/`FlashSaleObserver` flush `products`.

**Cursor implication:** cursor token becomes part of `fullUrl()` → each cursor page is a **distinct cache entry** → cursor works with existing cache **with no key change**. Per-page cached snapshots freeze mutations per cursor until TTL/invalidation (consistent with current per-`page` offset caching). **No cache change required.**

---

## 19. FRONTEND DEPENDENCY

- **No dedicated storefront SPA found in this backend repository.** `package.json`/`webpack.mix.js` are legacy mix tooling; no product-pagination consumer identified in `resources/`. Consumers are **external clients**.
- **Frontend audit = PENDING** (external). Not verified, not modified.
- Documented frontend needs: consume `next_cursor`/`prev_cursor`, append results, reset cursor on filter/sort/strategy/limit/header/currency change, stop when `next_cursor` null, keep offset (`page`) for search.

---

## 20. EXISTING TEST AUDIT

Relevant tests (VERIFIED present):

- `tests/Feature/General/ProductsEndpointTest.php` — auth-free 200, empty data, paginated `index`, resource shape, links metadata, all 10 strategy types 2xx, invalid type 422, whitespace/empty type fallback, IDOR/unknown filter → empty, deleted/inactive category, mixed brand values, translated category name, out-of-stock/inactive/soft-deleted exclusion, `order` asc/desc/default/invalid-422/empty-default, `order_price` not honored in index flow, `limit` respected/capped.
- `tests/Feature/ProductFilterTest.php`, `ProductCacheTest.php`, `ProductProductionHardenTest.php`, `ProductCrudTest.php`, `ProductTagTest.php`, `ProductItemTypeTest.php`, `ProductCurrencyTest.php` (currency), `ProductExportTest.php`/`ProductImportTest.php`/`CompleteProductExportImportTest.php`, `Categories/CategoryProductsCountConsistencyTest.php`, `FlashSales/FlashSaleProductionHardenTest.php`.

**No existing cursor-pagination test** (grep for `cursorPaginate` returned only `->cursor()` lazy-chunk usages in MediaCleanupObserver/CancelUnpaidOrders/PaymentReconciliationJob). VERIFIED.

---

## 21. REQUIRED FUTURE TEST MATRIX (audit only — not claimed passing)

- **Pagination:** first cursor page, next/prev, empty result, last page (`next_cursor` null), single-result, exact-page-size, `limit` clamp, invalid/tampered cursor → first page.
- **Sorting:** `order=asc/desc`; `order_price=asc/desc` (with/without filters); combined `order`+`order_price`; duplicate-price tie; NULL-price (decision path).
- **Filters:** brand, category (descendants), min/maxPrice, productsId, categoriesId/brandsId, rating/rating_min/max, dimension min/max, dynamic attribute — each alone + combined + combined with `order_price`.
- **Combinations:** filter+cursor, filter+sort+cursor, strategy(`index`)+cursor, strategy+sort+cursor, price-range+price-sort+cursor.
- **Search:** search stays offset; `search`+`pagination=cursor` → policy (422 recommended); search+filters.
- **Security/visibility:** inactive/out-of-stock/soft-deleted never returned on any cursor page; channel (`X-Channel`) respected; home excludes fast-shipping.
- **Concurrency:** insert/delete/deactivate/price-change during paging (assert no crash; document drift).

---

## 22. PERFORMANCE ANALYSIS

**No runtime benchmark performed.**

Theoretical query shapes:

- Offset: `ORDER BY id DESC LIMIT 15 OFFSET 15000` → scans+skips 15k rows.
- Cursor: `WHERE id < :cursor ORDER BY id DESC LIMIT 15` → index seek on PK, near-constant cost.

Expected (unmeasured) advantages: no `COUNT(*)`, no deep-offset scan; worst case bounded by cursor-seek. Cache (4h) dominates non-search requests either way, so real-world delta is visible primarily on cache-miss and is material only for deep pages. **No fabricated numbers.**

---

## 23. FINAL COMPATIBILITY MATRIX

| Product Scenario | Query Path | Current Pagination | Ordering | Deterministic? | Cursor Possible? | Required Index? | Risk | Classification |
|------------------|-----------|--------------------|----------|----------------|------------------|-----------------|------|----------------|
| `/products` (no type, no search) | fallback | offset | `id {order}` | YES | YES | no | low | CURSOR_SUPPORTED |
| `?order=asc\|desc` | fallback | offset | `id` | YES | YES | no | low | CURSOR_SUPPORTED |
| `?order_price=asc\|desc` | fallback | offset | `price,id` | YES | CONDITIONAL | optional | NULL price | CURSOR_SUPPORTED_WITH_CONDITION |
| any filter (+ no price sort) | fallback | offset | `id` | YES | YES | pivot idx | low | CURSOR_SUPPORTED |
| filter + `order_price` | fallback | offset | `price,id` | YES | CONDITIONAL | optional | NULL price | CURSOR_SUPPORTED_WITH_CONDITION |
| `?type=index` (no search) | `paginate()` | offset | `id {order}` | YES | YES | no | low | CURSOR_SUPPORTED |
| `?type=index&search=X` | `paginate()` SQL LIKE | offset | `id` | YES | NO (policy) | n/a | policy | OFFSET_ONLY |
| `?search=X` (fallback Scout) | Scout | offset | `FIELD,id` | YES | NO | n/a | relevance | OFFSET_ONLY |
| `?search=X` + any filter | Scout | offset | `FIELD,id` | YES | NO | n/a | relevance | OFFSET_ONLY |
| `?type=best_product_sales` | strategy | none (`limit`) | `sold_quantity desc` | NO | N/A | n/a | — | NOT_APPLICABLE |
| `?type=new_arrivals` | strategy | none | `created_at desc` | NO | N/A | n/a | — | NOT_APPLICABLE |
| `?type=all_product_discounts` | strategy | none | `id desc` + post-filter | NO | N/A | n/a | — | NOT_APPLICABLE |
| other 6 strategies | strategy | none | varies | NO | N/A | n/a | — | NOT_APPLICABLE |

Search = **OFFSET / SEPARATE ARCHITECTURE**.

---

## 24. BLOCKERS

| ID | Severity | Evidence | File:Line | Impact | Why it blocks Cursor | Required decision |
|----|----------|----------|-----------|--------|----------------------|-------------------|
| B1 | P1 | `products.price` nullable; cursor `>`/`<`/`=` vs NULL → false | migration `:111`; `ProductController:126` | NULL-price rows unreachable/terminate price cursor | Blocks `order_price` cursor until resolved | NULL-price policy (exclude NULL / disallow combo / explicit ordering) |
| B2 | P1 | Sort key `price` ≠ displayed `current_price` | `ProductController:126` vs `ProductPricingService:42` | Displayed price order may appear non-monotonic vs sort | Semantic nuance, not correctness blocker for `id` cursor | Document; do not "fix" in cursor task |
| B3 | P1 | Search uses `FIELD()` relevance (Scout) / SQL `LIKE` | `ProductService:137`, `:720` | Keyset boundary inexpressible | Search cannot use SQL cursor | Search stays offset (unchanged) |
| B4 | P1 | 9 strategies return `limit()->get()` Collections (non-deterministic / no pagination) | `ProductService:389-578` | No pagination to convert | Cursor inapplicable | Out of scope; leave unchanged |
| B5 | P2 | No `(price,id)` composite index | migration `:139-145` | Deep price-cursor seeks rely on merge of `price` + PK | Optional perf; not correctness | Add composite only if measured |
| B6 | P2 | `created_at`/`updated_at` unindexed | migration `:137` | Only affects `new_arrivals` (not paginated) | N/A for paginated listing | none (out of scope) |
| B7 | P3 | `order_price` ignored by `type=index` | `ProductService:154` | Inconsistent sort contract between flows | Documentation | clarify/align contract |

---

## 25. IMPLEMENTATION PLAN (DO NOT IMPLEMENT)

### Phase 1 — Product Cursor (fallback + `type=index`, non-search)

| File | Current responsibility | Required change | Why | Risk |
|------|----------------------|-----------------|-----|------|
| `app/Http/Requests/ProductIndexRequest.php` | validates `type`,`order` | add `'pagination' => ['sometimes','nullable', Rule::in(['offset','cursor'])]` | introduce opt-in param | additive |
| `app/Services/General/ProductService.php` | `paginate()` offset | add `paginateCursor(Request)` mirroring `paginate()` with `cursorPaginate()`; optional `whereNotNull('price')` for `order_price` | isolate cursor query | additive |
| `app/Http/Controllers/Api/General/ProductController.php` `buildFallbackResponse()` `:114` | offset only | branch: `pagination=cursor` && no search → cursor path; else offset | opt-in | backward-compatible |
| `app/Http/Controllers/Api/General/ProductController.php` `buildStrategyResponse()` `:79` | `index` → `paginate()` | route `index` (no search) through cursor when requested | consistency | opt-in |
| `packages/marvel/src/Http/Resources/product/ProductCollectionMini.php` | offset links | emit `next_cursor`/`prev_cursor`, null/omit `total`/`last_page` for cursor | cursor navigation | additive (offset unchanged) |

**Not needed:** `ProductStrategyResolver`, `ProductMiniResource`, `ApiResponse`, routes, migrations (optional composite only).

### Request/API contract

- `?page=N` / default → offset (unchanged).
- `?pagination=cursor` (no `search`) → cursor.
- `?pagination=cursor&search=X` → **422** (recommended).

### Query architecture

```
filters (active/channel/ProductFilter/ids/rating/dimensions)
→ deterministic order (ORDER BY [price,] id)
→ cursor boundary (Laravel-generated WHERE)
→ LIMIT perPage+1
```

### Sorting cursor strategy

- `id {order}` → columns `[id]`.
- `price {order_price}, id {order}` → columns `[price, id]` (only after NULL policy).

### Search

Remains offset (Scout `FIELD` + SQL `LIKE`). No change.

### Response (before/after)

Before (offset): `links{current_page,from,to,last_page,path,per_page,total,next_page_url,prev_page_url,last_page_url,first_page_url}`.
After (cursor): same array **plus** `next_cursor`/`prev_cursor`; `total`/`last_page`/`last_page_url`/`first_page_url`/`current_page` omitted or null; offset mode byte-for-byte unchanged.

### Tests

Full matrix from §21.

### Database

Only optional `(price, id)` composite — evidence-backed, not required.

### Cache

No change required (cursor token part of `fullUrl()` cache key).

### Frontend

External — documented integration: append via `next_cursor`, reset cursor on filter/sort/strategy/limit/header/currency change, stop on null, search uses `page`.

---

## 26. ROLLOUT / ROLLBACK

- **Rollout:** opt-in `?pagination=cursor` behind a feature flag (e.g. `config('shop.cursor_pagination', false)` default OFF) → enable on staging → verify against MySQL EXPLAIN (currently BLOCKED) → enable on prod → frontend adopts → (later, separately) consider default.
- **Monitoring:** cursor page walks, deep-page latency, no `total` regressions for offset.
- **Rollback:** set flag OFF → `pagination=cursor` falls back to offset (or 422); zero code removal. Indexes additive (no destructive rollback).
- **Compatibility:** `?page=` offset path untouched at all phases.

---

## 27. FINAL DECISION

# GO WITH CONDITIONS

**Question:** *Can every non-search Product listing scenario safely migrate to Cursor Pagination without changing business semantics?*

**Answer:** Yes for the **paginated, non-search, `id`-ordered** scenarios (unconditional). Yes for **price-sorted** scenarios **only after** a NULL-price policy. **Not applicable** to the 9 un-paginated Collection strategies (nothing to migrate). **Search** is explicitly out of scope (offset).

**Conditions:**

1. Opt-in `?pagination=cursor`; default and `?page=` remain offset (zero breaking change).
2. Search (Scout + SQL LIKE) stays offset; `pagination=cursor` + `search` → `422` (consistent with existing validation).
3. `id` sort ships first (unconditionally safe).
4. `price` sort requires an explicit NULL-`price` policy (recommend: disallow `order_price`+`cursor` initially, or `whereNotNull('price')`; never `COALESCE(price,0)`).
5. Collection strategies remain unchanged (out of scope).
6. Cursor response omits/nulls `total`/`last_page` and adds `next_cursor`/`prev_cursor`; offset response unchanged.
7. Client resets cursor on any filter/sort/strategy/limit/header/currency change.
8. `active()`/channel/visibility scopes remain first in the pipeline; cursor cannot bypass them.

**IMPLEMENTATION_READY = YES**, subject to conditions 1–8 (with B1 NULL-price decision and production-DB EXPLAIN verification being the only pre-flight items for the `price` sort path).

---

### FINAL REPORT

```
AUDIT COMPLETE

File:
CURSOR_PAGINATION_PRODUCT_ENDPOINT_AUDIT.md

Implementation performed:
NO

Source files modified:
NO

Database modified:
NO

Migrations created:
NO

Tests modified:
NO

Final decision:
GO WITH CONDITIONS

CURSOR_SUPPORTED scenarios:
- /products (no type, no search) — ORDER BY id {asc|desc}
- /products + any filter (brand/category/price/rating/ids/dimensions/attributes) — ORDER BY id
- /products?type=index (no search) — ORDER BY id

CURSOR_SUPPORTED_WITH_CONDITION scenarios:
- /products?order_price=asc|desc (with/without filters) — ORDER BY price,id — requires NULL-price policy

OFFSET_ONLY scenarios:
- /products?search=X (Scout FIELD() relevance) and all search+filter combos
- /products?type=index&search=X (SQL LIKE)

NOT_APPLICABLE scenarios:
- All 9 Collection strategies (best_product_sales, brands_product, new_arrivals,
  all_product_discounts, product_discount_today_or_low_qty, flash_sales_product,
  flash_sales_end_today, flash_sales_end_week, product_for_parent_category) — single-page, not paginated

Main blockers:
- B1 NULL products.price (P1): blocks price cursor until NULL policy decided
- B3 Search relevance ordering (P1): search stays offset
- B4 Collection strategies (P1): cursor inapplicable
- Production DB engine + EXPLAIN: NOT VERIFIED (BLOCKED)
```

---

## 28. PRE-IMPLEMENTATION GATE

### 28.1 Product strategy scope — RESOLVED (VERIFIED)

The 9 Collection strategies are **not** a paginated browse feed. They are **home/widget "top-N" sections** re-exposed through the `type=` endpoint.

Evidence:
- `app/Services/General/HomeService.php` renders the *same* concepts (`getNewArrivals()`, `getAllDiscountProducts()`, `getFlashSaleProductsEndingThisWeek()`, `getDiscountEndingTodayOrLowStockProducts()`, `getWeeklyCategoryProducts()`) as **cached single-page home sections** with `limit(10)` and no pagination metadata (`HomeService.php` `getHomeData()` + `CACHE_KEYS`).
- `ProductController::buildSimpleLinks()` (`:158`) returns single-page metadata: `per_page = total`, `last_page = 1`, `next_page_url = null`.
- `CurrencyService::PRODUCT_STRATEGY_TYPES` (`CurrencyService.php:25-35`) references these keys **only for currency-aware cache invalidation**, not pagination.
- Docs (`api-desc/product/api.md:337`) list them as "strategy keys" with no pagination semantics; `type=all` is treated as missing → fallback (paginated).
- Several have **non-deterministic ordering** (`sold_quantity` for best_product_sales; `created_at` for new_arrivals; no `orderBy` for brands_product / product_discount_today_or_low_qty / flash_sales_product) and **post-query filtering** (`->filter(isDiscountActive)`, `ProductService:407`).

Classification:
- `index` → **CURSOR_TARGET** (paginated browse feed).
- `best_product_sales`, `brands_product`, `new_arrivals`, `all_product_discounts`, `product_discount_today_or_low_qty`, `flash_sales_product`, `flash_sales_end_today`, `flash_sales_end_week`, `product_for_parent_category` → **INTENTIONALLY_SINGLE_PAGE**.

No cursor work required (or safe) for the 9 single-page strategies; converting them would change business behavior.

### 28.2 NULL price — RESOLVED (VERIFIED)

Evidence:
- `MeemProductCreateRequest.php:33` — `'price' => ['nullable', 'numeric', 'min:0']` (app-layer create path).
- `ProductCreateRequest.php:68` — `'price' => ['sometimes','numeric','min:0','required_if:product_type,simple']` — price is **not required for `variable` products** (price lives on variants).
- `products.price` column is `decimal(10,2) NULLABLE` (migration `:111`).
- `active()` scope does **not** filter by price; the price-range filter (`ProductFilter:167-188`) explicitly handles variant prices.
- A separate `products.min_price` column is referenced in `AnalyticsController` (`products.min_price`) and `RelatedProductResource`, but the listing sort uses `products.price`, not `min_price`.

Determination:

```text
NULL_PRICE_POLICY = NULL prices are LEGITIMATE storefront products
                    (variable products with variant-level pricing).
```

Consequence: `whereNotNull('price')` **would drop variable products → changes the result set → NOT safe**. Laravel's built-in `cursorPaginate` has **no NULL-aware keyset**; `>`/`<`/`=` against `NULL` are false, so NULL-price rows are unreachable under `ORDER BY price`.

```text
PRICE_CURSOR = CONDITIONAL / BLOCKED
```

Approved options (choose one; do not auto-apply):
1. Ship `id`-cursor first; keep `order_price` on **offset** (defer price cursor). — recommended.
2. Design an explicit NULL-bucket cursor (custom predicate, e.g. `(price IS NULL AND id < :id) OR (price IS NOT NULL AND (price < :p OR (price = :p AND id < :id)))`), which is **not** provided by Laravel `cursorPaginate` and would require custom cursor work.

### 28.3 `type=index` + `order_price` — RESOLVED (VERIFIED)

`order_price` is **documented** as fallback-only:
- `README.md:99` — "`order_price` → `orderBy price` before `id` **in fallback**".
- `api-desc/product/api.md:339` — "when present, `orderBy('price', order_price`) **in fallback flow**".
- `ProductService::paginate()` (`:148-154`) orders only by `id` (used by `type=index`); `ProductController:124-127` applies `order_price` only in `buildFallbackResponse`.
- Test pins the behavior: `ProductsEndpointTest::test_order_price_does_not_sort_by_price_in_default_index_flow` asserts `order_price` is **not** honored in the default `index` flow.

Decision:

```text
KEEP_EXISTING_SEMANTICS
```

The cursor implementation MUST NOT make `type=index` honor `order_price`. Fallback keeps `order_price`; `type=index` keeps `id`-only. (Aligning them would be a separate, explicitly-approved API change.)

### 28.4 Cursor response contract — RESOLVED (PROPOSED, needs approval)

Current offset `data` envelope (VERIFIED): `ProductCollectionMini::toArray()` emits `{ "data": [...], "links": { current_page, from, to, last_page, path, per_page, total, next_page_url, prev_page_url, last_page_url, first_page_url } }`, with `filters` and `categories` added as siblings (`ProductController:132-134`).

Proposed contract (recommendation):

- **Offset mode:** unchanged, byte/shape compatible. `?page=N` clients unaffected.
- **Cursor mode:** keep `data`/`filters`/`categories` identical; `links` becomes:

```json
"links": {
  "path": "/api/v1/general/products",
  "per_page": 15,
  "next_page_url": "/api/v1/general/products?cursor=…",
  "prev_page_url": null
}
```

  - `total`, `last_page`, `current_page`, `from`, `to`, `last_page_url`, `first_page_url` are **offset-only concepts** → omitted in cursor mode (they do not exist on `CursorPaginator`).
  - Optionally add raw tokens `next_cursor`/`prev_cursor` alongside the URLs if the client wants opaque tokens instead of URLs (decide once; do not invent both unless required).
  - `filters`/`categories` are computed independently of pagination → remain present in both modes.

This contract requires a small branch in `ProductCollectionMini` (or a sibling `ProductCollectionMiniCursor` resource); `ApiResponse` and `ProductMiniResource` are unchanged.

### 28.5 Feature flag / cursor-disabled behavior — RESOLVED

Existing error convention (VERIFIED): FormRequest `failedValidation()` → `response()->json($validator->errors(), 422)`; invalid `type`/`order` already return **422** (`ProductsEndpointTest`).

```text
CURSOR_DISABLED_BEHAVIOR = 422
```

- Add `pagination` to `ProductIndexRequest` (`Rule::in(['offset','cursor'])`).
- If `pagination=cursor` but cursor mode is disabled (feature flag off), return **422** (explicit), consistent with existing validation. **Never silently fall back to offset.**
- Rollback preserves offset clients: flag off → offset unaffected; cursor clients receive explicit 422.

### 28.6 Production database — RESOLVED (engine VERIFIED, runtime BLOCKED)

- **Engine VERIFIED:** TiDB Cloud (MySQL-compatible). Evidence: `render.yaml` — `DB_CONNECTION: mysql`, `DB_PORT: 4000` (TiDB default), comment "Database (TiDB Cloud - MySQL Compatible)", `DB_INIT_COMMAND: SET SESSION tidb_txn_mode = 'pessimistic'`, `MYSQL_ATTR_SSL_CA` for TiDB SSL.
- **TiDB compatibility of proposed SQL:** MySQL-compatible — supports `FIELD()`, `REGEXP_REPLACE`, `CAST`, JSON `->`, composite indexes, DESC indexes, and OR-based keyset predicates. NULL ordering = MySQL semantics (NULL first on ASC, last on DESC) — relevant to §28.2.
- `PRODUCTION_DB_RUNTIME = NOT_AVAILABLE` (no live DB; no `EXPLAIN` performed). **No fabricated plans.**

---

### 28.7 Final pre-implementation matrix

| Area | Status | Evidence | Blocks implementation? |
|------|--------|----------|------------------------|
| ID Cursor | READY | `id` PK unique, `orderBy('id')` | NO |
| Price Cursor | CONDITIONAL | `price` nullable + NULL-policy unresolved | YES (only for price sort) |
| NULL Price | RESOLVED — legitimate (variable) | `MeemProductCreateRequest:33` `nullable`; `ProductCreateRequest:68` `required_if:simple` | YES (blocks price cursor only) |
| All Product Filters | READY | all `whereHas`/`where`/`whereIn`; applied before order | NO |
| Product Strategies | RESOLVED | `index`=target; 9×INTENTIONALLY_SINGLE_PAGE | NO |
| Search Separation | READY | Scout `FIELD`/SQL `LIKE` → offset | NO |
| API Response | PROPOSED | offset unchanged; cursor `links` defined (§28.4) | Needs approval |
| Backward Compatibility | READY | opt-in `pagination=cursor`; `?page=` unchanged | NO |
| Cursor Validation | READY | `pagination` in `ProductIndexRequest`; 422 convention | NO |
| Cache | READY | cursor token part of `fullUrl()` key; no change | NO |
| Active Scope | READY | cursor appended after all scopes; no bypass | NO |
| Database | ENGINE VERIFIED (TiDB) | `render.yaml` | NO |
| EXPLAIN | NOT_AVAILABLE | no live DB | NO (verify before price cursor) |
| Frontend Contract | PENDING | external clients; not in repo | NO (external) |

### 28.8 Final decision

```text
IMPLEMENTATION_READY_WITH_EXPLICIT_CONDITIONS
```

**What is proven:**
- `id`-cursor is safe for fallback (no-search) and `type=index` (no-search), with all filters.
- Search must stay offset (Scout `FIELD` + SQL `LIKE`).
- The 9 single-page strategies are intentionally not paginated — no cursor scope.
- TiDB (MySQL-compatible) production engine verified; NULL ordering = MySQL semantics.
- Cache, active scope, and offset backward-compatibility are all preserved by an opt-in `pagination=cursor`.

**What remains conditional (exact decisions required before/at implementation):**
1. **NULL_PRICE_POLICY** — approved as "NULL prices are legitimate"; implementation must NOT use `whereNotNull('price')`. Price cursor requires an explicit NULL-bucket design (or defer price cursor and keep `order_price` on offset).
2. **Response contract** (§28.4) must be approved (cursor `links` shape; whether raw `next_cursor`/`prev_cursor` tokens are emitted).
3. **CURSOR_DISABLED_BEHAVIOR = 422** must be approved.
4. **`type=index` semantics** confirmed as `KEEP_EXISTING_SEMANTICS` (no `order_price` on `index`).
5. **Production TiDB `EXPLAIN`** must be run before enabling `price` cursor (and to decide the optional `(price, id)` composite index).

**May implementation start?**
- Yes for the **`id`-cursor** path once conditions 2, 3, and 4 are approved (condition 1 is irrelevant to `id` sort).
- **No** for the `price`-cursor path until condition 1 (NULL-bucket design) and condition 5 (TiDB EXPLAIN) are resolved.

```text
FINAL DECISION: IMPLEMENTATION_READY_WITH_EXPLICIT_CONDITIONS
```
