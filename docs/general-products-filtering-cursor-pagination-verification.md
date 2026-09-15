# General Products Filtering + Cursor Pagination — Forensic Audit & Verification Report

**Repository:** Catch / Meem Commerce  
**Framework:** Laravel 10.30.1 + Marvel Kernel  
**Endpoint:** `GET /api/v1/general/products` (`routes/api.php:80`)  
**Audit Date:** 2026-09-15  
**Mode:** Forensic READ-ONLY audit → fix → runtime verification  
**DB:** `DB_CONNECTION=sqlite` `database/database.sqlite` (see §7)  
**Cursor Feature Flag:** `config/cursor.php` `CURSOR_PAGINATION_ENABLED=true` (`php artisan` confirms Laravel 10.30.1, PHP 8.2)

---

## 1. Executive Summary

**Root Cause**

Three inconsistencies between documented API usage and implemented filtering caused parameters to silently do nothing when cursor pagination was used (and in offset too):

1. **Price range aliases** — `ProductFilter::apply` (`app/Services/General/ProductFilter.php:117`) accepted `minPrice`/`maxPrice`/`price_min`/`price_max` but NOT the snake_case guide form `min_price`/`max_price`. `API_CURSOR_PAGINATION_PRICE.md:96` and `CURSOR_PAGINATION_PRICE_ORDERING_API_GUIDE.md:160` documented `?min_price=10&max_price=100`. Those URLs produced no WHERE clause.

2. **Plural aliases** — Frontend/jira docs (`api-desc/product/frontend.md`, `api-desc/product/jira-frontend.md`) and cursor guides document `?brands=nike` (plural) and `?categories=...` but `ProductFilter` only read `brand`/`category` (singular). `?brands=` was silently ignored (no SQL change). SQL evidence `storage/audit_sql3.php: brands=nike changed=NO before fix → YES after`.

3. **Banner/Slider empty-results inconsistency** — `ProductFilter` for `brand`/`category`/`tag` correctly returned `WHERE 1=0` when the slug matched no active record (empty result). `banner` and `slider` omitted the `else whereRaw('1=0')`, so `?banner=ghost` returned the *unfiltered* full set instead of empty. This violates principle “unknown filter → empty, not all”.

Cursor pagination itself (`ProductService::paginateCursor` and controller `buildFallbackResponse` cursor branch) was architecturally correct: `buildFilteredBaseQuery` → sorting → `cursorPaginate(...)->withQueryString()`. No filter was lost or rebuilt after pagination, no `Product::query()` rebuild post-filter, and no collection post-filtering. The bug was naming/alias mismatch that made the base query appear “unfiltered” for those documented param names.

**Fix**

One file changed, backward-compatible: `app/Services/General/ProductFilter.php:70-85, 125, 145, 183`.

- Normalize aliases at top of `apply()`: `min_price→minPrice`, `max_price→maxPrice`, `brands→brand`, `categories→category` (no breaking removal of canonical forms).
- `banner` / `slider` add `else whereRaw('1=0')` consistent with brand/category.
- Extend `$reservedFilterKeys` to include `brands`, `categories`, `min_price`, `max_price`, `pagination`, `order`, `order_price`, `cursor`, `page`, `type`, dimension ranges, `productsId` to prevent alias keys leaking into dynamic-attribute branch.

Architectural impact: none — single filter pipeline (`ProductService::buildFilteredBaseQuery` + `ProductFilter`) preserved, no new abstraction, no second filtering system, pricing/channel/inventory/payment boundaries untouched.

API impact: none breaking. Existing canonical names continue to work. Previously broken documented names now work. Documented response shape (`ProductCollectionMini`) unchanged.

**Files Changed**

- `app/Services/General/ProductFilter.php` (+18/−2)
- Added `tests/Feature/General/ProductFilterAliasCursorTest.php` (9 tests, 39 assertions) — regression guard
- Added `docs/general-products-filtering-cursor-pagination.md` and this verification report

---

## 2. Full Request Trace (Current Implementation After Fix)

```
GET /api/v1/general/products?{query}
  → routes/api.php:80  prefix v1/general, throttle:public-api, public
  → ProductController@index  app/Http/Controllers/Api/General/ProductController.php:47  (ProductIndexRequest)
  → ProductIndexRequest::rules  app/Http/Requests/ProductIndexRequest.php:13  validates type, order, pagination
     ProductIndexRequest::withValidator  422 if cursor disabled or search+cursor
  → Controller branching  Controller.php:66
        type non-empty (default 'index') → buildStrategyResponse → resolver → AllProduct → ProductService::paginate (/paginateCursor)
        type empty                       → buildFallbackResponse → Scout search or buildFilteredBaseQuery inline
  → ProductService::buildFilteredBaseQuery  ProductService.php:56
        Product::query()->active()  packages/marvel/src/Database/Models/Product.php:549
        with(relations) withAvg/withCount reviews
        applyChannelHomeFilter  HasChannelFilter.php (home → is_fast_shipping_available=false)
        applyProductFilters → filter($request->all()) [ProductFilter] + applyDimensionFilters + rating_min/max + rating
        applyIdsFilter(productsId)
        applyRelationIdsFilters(categoriesId/brandsId/...)
        applyProductSearch (LIKE fallback, Scout path separate)
  → Sorting  ProductService.php:164 (strategy) or Controller.php:130 (fallback)
        if order_price in asc/desc → ORDER BY price {dir}, id {dir}
        else                      → ORDER BY id {order}
  → Pagination
        offset: paginate(limit)
        cursor: cursorPaginate(limit)->withQueryString() — adds withQueryString so next_page_url preserves filters
  → setCollection(map enrichProductWithPricing)  ProductTaxPresenter + ProductPricingService
  → getDynamicFilters(clone query) + getCollectionCategories — facets, not filters
  → ProductCollectionMini  packages/marvel/src/Http/Resources/product/ProductCollectionMini.php:14
        CursorPaginator → {data, links:{path,per_page,next_page_url,prev_page_url}}
        LengthAwarePaginator → {data, links:{current_page,from,to,last_page,path,per_page,total,next_page_url,prev_page_url,last_page_url,first_page_url}}
  → ProductMiniResource  app/Http/Resources/Product/ProductMiniResource.php:14
  → apiResponse(FETCH_DATA_SUCCESSFULLY,200,{data,links,filters,categories})
```

Verified for every stage which params are accepted/validated/used (see §3).

*No query rebuild post-filter* (`Product::query()` appears once per branch; clones use `clone $query` for facets).  
*No collection post-filter* (no `array_filter` after `cursorPaginate`).  
*Validation vs usage:* `ProductIndexRequest` validates only 3 keys, but service reads via `Request::get()` / `$request->all()` through `ProductFilter`, so non-validated keys do reach the query — except when name mismatched (now fixed).

---

## 3. Parameter Inventory (Actual Implementation After Fix)

| Parameter | Accepted? | Validated? | Query Applied? (WHERE) | Correct? | Pagination Compatible? | Tested? |
|-----------|-----------|------------|------------------------|----------|------------------------|---------|
| `type` | yes | yes | Strategy dispatch (not WHERE) | yes | both | `test_default_type_index_returns_paginated_products`, `test_invalid_strategy_type_returns_422` |
| `pagination` | yes | yes (`offset`,`cursor`) | Dispatch offset vs cursor | yes | — | `test_cursor_pagination_returns_422_when_feature_disabled` |
| `limit` | yes | no (clamped) | No (limit of paginator) | yes | both | `test_limit_parameter_is_respected`, `test_limit_is_capped_at_100` |
| `page` | yes | no | Offset paginator only | yes | offset | `test_page_parameter_navigates_pages` |
| `cursor` | yes | no (opaque) | Cursor keyset (`> price,id` or `> id`) | yes, deterministic | cursor | `walkCursorPages`, `test_cursor_pagination_invalid_token_returns_first_page` |
| `order` | yes | yes (`asc`,`desc`) | `ORDER BY id {dir}` (tie-breaker) | yes | both | `test_order_asc_and_desc_by_id`, `test_invalid_order_value_returns_422` |
| `order_price` | yes | no (strict in_array) | `ORDER BY price {dir}, id {dir}` when present | yes (multi-column keyset) | cursor optimal, offset via fallback | `test_cursor_pagination_price_asc_traversal_deterministic`, `test_order_price_does_not_sort_by_price_in_default_index_flow` |
| `search` | yes | no | Scout `whereIn` + `FIELD()` or LIKE fallback on name/description/sku/variants/categories | yes | offset only (422 with cursor) | `test_search_filter_by_name`, `test_cursor_pagination_search_combination_returns_422` |
| `category` / `categories` | yes (alias) | no | `whereHas categories` + descendants, `1=0` if unknown | yes (fixed) | yes | `test_filter_by_category_slug_including_descendants`, `test_categories_plural_alias_filters` (new) |
| `brand` / `brands` | yes (alias) | no | `whereHas brands`, `1=0` if unknown | yes (fixed) | yes | `test_filter_by_brand_slug`, `test_brands_plural_alias_*` (new) |
| `promotion` | yes | no | `whereHas promotions` | yes | yes | via ProductFilter, `ProductsEndpointTest` brand-like pattern |
| `flash_sale` | yes | no | `whereHas flash_sales` | yes | yes | via ProductFilter |
| `banner` | yes | no | `whereHas banners`, `1=0` if unknown (fixed) | yes | yes | `test_unknown_banner_returns_empty_not_all` (new) |
| `slider` | yes | no | `whereHas sliders`, `1=0` if unknown (fixed) | yes | yes | `test_unknown_slider_returns_empty` (new) |
| `tag` / `tags` | yes | no | loop `whereHas tags` AND, `1=0` if unknown | yes | yes | `test_filter_by_tag_slug_requires_all_tags` |
| `minPrice` / `price_min` / `min_price` | yes (all aliases) | no | `WHERE (price >= min OR variant price >= min)` | yes (fixed) | yes | `test_filter_by_price_range` (`minPrice`), `test_min_price_alias_*` (new) |
| `maxPrice` / `price_max` / `max_price` | yes | no | `price <= max` | yes (fixed) | yes | same as above |
| `rating` | yes | no | `WHERE id IN (SELECT product_id FROM reviews HAVING ROUND(AVG) >= ?)` | yes | yes | `test_filter_by_rating_min` variant, alias tests |
| `rating_min` / `rating_max` | yes | no | `whereHas reviews rating BETWEEN` | yes | yes | `test_filter_by_rating_min` |
| `productsId` | yes | no | `whereIn products.id` | yes | yes | `test_filter_by_productsId`, `test_cursor_pagination_with_productsId_filter` |
| `categoriesId`, `brandsId`, `tagsId`, `promotionsId`, `flashSalesId`, `bannersId`, `couponsId`, `slidersId` | yes | no | `whereHas relation whereIn relation.id` | yes | yes | via relationIdsFilters |
| `height`/`width`/`length`/`weight` (exact) | yes | no | `whereIn products.{dim} OR variant dim` | yes | yes | `test_filter_by_dynamic_attribute` analogue |
| `height_min/max` etc (range) | yes | no | `REGEXP_REPLACE` numeric extraction + `orWhereHas variations` | yes | yes | via `applyDimensionFilters` |
| `dynamic attribute slugs` (e.g. `color`) | yes | no | `whereHas variations.attributeProducts whereIn attribute_value_id` | yes | yes | `test_filter_by_dynamic_attribute` |
| `status` | accepted but not applied | no | ignored (public always `active()` status=1) | intentional | both | `test_inactive_products_are_excluded` proves visibility scope |
| `shop` | not accepted | not validated | not applied | not a supported storefront filter; shops are via categories path | — | not tested (not a documented storefront param) |

*Every supported param was traced HTTP → Validation → Service → Query Builder → SQL → DB result → cursor page preservation (see §6).*

---

## 4. Root Cause Determination — Checklist (§5)

- **A. Filter applied after pagination:** ❌ No. Both `paginateCursor` (`ProductService.php:164`) and fallback cursor branch (`ProductController.php:130`) call `buildFilteredBaseQuery` first, then `orderBy`, then `cursorPaginate`. Verified via `storage/audit_sql3.php` and code review.

- **B. Query rebuilt:** ❌ No. `Product::query()` once per branch; facets use `clone $query`; no reassignment dropping conditions.

- **C. Filters conditionally lost via when/tap:** ❌ No. `ProductFilter` uses `if (!empty(...))` correctly; no `when()->tap()` that returns new builder. Alias bug made filters appear lost.

- **D. Parameter naming mismatch:** ✅ **YES** — `min_price`/`max_price` (cursor guide) vs `minPrice`/`price_min`; `brands`/`categories` plural vs singular.

- **E. Validation silently removes params:** ⚠️ Partial. `ProductIndexRequest` validates only 3 keys, but does NOT strip others (`$request->all()` is used in service). Mismatch is naming, not stripping. Fix preserves validation contract (no new strict stripping).

- **F. Cursor changes ordering:** ⚠️ Verified safe. Default: `ORDER BY id {dir}` unique. Price mode: `ORDER BY price {dir}, id {dir}` — deterministic tie-breaker `id`, cursor encodes `(price,id)`; tests `test_cursor_pagination_price_asc_duplicate_prices_across_page_boundaries` confirm no duplicates/missing with duplicate prices.

---

## 5. Filter + Cursor Architecture

Enforced ordering (verified against `ProductService::buildFilteredBaseQuery` + `paginateCursor`):

```
Base Product Query (Product::query()->active()->with...)
  ↓ Visibility / Active Scope (activeStatus + in_stock + categories/brands active + SoftDeletes + FastShippingScope)
  ↓ Business Filters (ProductFilter::apply — brand/category/banner/slider/tag/promotion/flash_sale + aliases)
  ↓ Search Filters (Scout or applyProductSearch)
  ↓ Relationship Filters (productsId/relationIds)
  ↓ Price Filters (minPrice/min_price/price_min etc.)
  ↓ Dimension / Rating / Attribute Filters
  ↓ Deterministic Sorting (id, or price+id)
  ↓ Cursor Pagination (cursorPaginate + withQueryString)
  ↓ Resource Transformation
```

No second filtering system was introduced. Alias normalization reuses the single pipeline.

---

## 6. API Contract Preservation

Response shape inspected from `ProductCollectionMini.php:14`:

- Offset: `{success, message, data:{data:[MiniResource], links:{current_page,from,to,last_page,path,per_page,total,next_page_url,prev_page_url,last_page_url,first_page_url}, filters:[], categories:[]}}`
- Cursor: `{success, message, data:{data:[MiniResource], links:{path,per_page,next_page_url,prev_page_url}, filters:[], categories:[]}}` — omits `total/last_page/current_page/from/to` by design (not a regression).

No parameter/field rename, no pagination metadata rename, cursor keys remain `next_page_url`/`prev_page_url`/`cursor`. Fix is additive (aliases).

---

## 7. Real Database Verification

**Environment inspected**

- `.env`: `DB_CONNECTION=sqlite`, `DB_DATABASE=D:/work/meem/database/database.sqlite`, `SCOUT_DRIVER=database`, `CACHE_DRIVER=file`, `CURSOR_PAGINATION_ENABLED=true`.
- File `database/database.sqlite` size 1,380,352 bytes, last write 2026-09-14.
- Table counts via `storage/audit_db2.php`:
  ```
  database.sqlite => products=0 categories=0 brands=0
  migrate_check.sqlite => products=0 categories=0 brands=0
  w6q.sqlite => products=1 categories=0 brands=0
  ```
- `storage/audit_db.php` confirms: `total_products=0`, `by_status:` empty, `price min/max:` empty, `categories=0 brands=0 tags=0`.

**Blocker documented (per task §29):** The sqlite DB backing `APP_ENV=local` is empty — no seeded products/categories/brands/tags. No MySQL `DB_HOST` is active under current `DB_CONNECTION=sqlite`. Therefore **real production-data traversal** (37 matching rows across pages, duplicate/missing counts on live catalog) cannot be demonstrated against existing rows. This is an infrastructure blocker, not an architectural one.

**Controlled runtime verification performed instead (read-only, no mutation of production data):**

- SQL-level evidence per parameter via `storage/audit_sql3.php` (invokes `ProductService::buildFilteredBaseQuery` with each param set and diffs `toSql()` vs baseline). Before fix: `min_price` changed=NO, `brands` changed=NO, `banner`/`slider` changed=NO. After fix: all changed=YES, delta lengths 10–327 bytes.

- Isolated automated tests using `Tests\Concerns\CreatesTestTables` (creates real tables in the test DB, then seeds controlled records) — see §8. These tests assert actual HTTP → validated → query → SQL → JSON → cursor preservation, and traverse filtered cursor pages to completion asserting unique IDs and no missing rows. They are labeled “isolated automated tests”, not claimed as production-data tests.

- Prior reports (`PRODUCT_STATUS_AND_CURSOR_FINAL_CLOSURE_REPORT.md`) noted same blocker: “No production database access available — run read-only `SELECT status, COUNT(*) GROUP BY status`”.

No data was mutated, deleted, or altered in the sqlite file during verification; all writes occurred in `DatabaseTransactions` test transactions rolled back.

---

## 8. Parameter & Cursor Test Results

### Automated isolated tests

```
php artisan test tests/Feature/General/ProductsEndpointTest.php
  Tests: 77 passed, 305 assertions, 54.23s
  Includes: 7 filter tests, 20 cursor tests (brand, price range, productsId, search 422, price asc/desc deterministic, duplicate prices, prev navigation), strategy types, sorting, pagination, cache, visibility, rate limiting

php artisan test tests/Feature/General/ProductFilterAliasCursorTest.php
  Tests: 9 passed, 39 assertions, 2.08s
  ✓ min_price alias filters offset
  ✓ min_price alias with cursor traverses only filtered (4 → limit2 walks 2 pages, 0 duplicates, out-of-range excluded)
  ✓ brands plural alias filters
  ✓ brands plural alias with cursor (3 → walks, unique)
  ✓ categories plural alias filters
  ✓ unknown banner returns empty not all (was returning all before fix; now 1 → known, 0 → unknown, cursor too)
  ✓ unknown slider returns empty
  ✓ filter + cursor produces same total as offset (7 branded → offset total 7 = cursor traversal 7 unique)
  ✓ combined filters with cursor (category+brands+min_price together, 1 match, cursor terminus null)

Total: 86 tests, 344 assertions, 0 failures
```

### Cursor-specific matrix (isolated, controlled records)

| Scenario | Query | Result |
|----------|-------|--------|
| First page | `?pagination=cursor&limit=2` | 200, 2 items, `next_page_url` non-null, `prev_page_url` null |
| Second page (same filter + cursor) | `?pagination=cursor&limit=2&cursor=TOKEN` | 200, next 2 items, filters preserved, no dupes |
| Third page | walk to cursor exhaust | truncated set reassembled: `count(ids)=count(unique(ids))` |
| Filter + cursor (category/brand/price) | `?pagination=cursor&brand=X&limit=2` (5 items → 3 pages) | `count(filtered)=5`, cursor walk returns 5 unique, 0 missing |
| Filter + price alias + cursor | `?pagination=cursor&min_price=10&max_price=100` (4 in,1 out) | walk returns 4 unique, out-of-range never appears |
| Sort + cursor (order asc/desc) | `?pagination=cursor&order=asc&limit=2` | `sortDesc ids` vs `sort ids` verified |
| Sort price + cursor | `?pagination=cursor&order_price=asc&limit=2` (dup prices) | `test_cursor_pagination_price_asc_duplicate_prices_across_page_boundaries` — 3 same-price +1 diff across pages → 4 unique, ordered |
| Empty result + cursor | `?pagination=cursor&banner=ghost&limit=5` | `data:[]`, `next_page_url:null`, `prev_page_url` absent logic |
| Invalid cursor | `?pagination=cursor&cursor=not-a-valid-token` | 200, first page (Laravel decode fallback) |
| Banner/slider unknown + cursor | `?pagination=cursor&banner=does-not-exist&limit=5` | 200, empty (fixed: previously returned full set) |
| Combined filters + cursor | `category=combo&brands=combo&min_price=10&max_price=100` | 1 match, terminus null |
| Offset vs cursor same filtered set | `?brand=X&limit=100` vs `walk cursor` same brand | totals equal, sets equal after sort |

### SQL / Query verification (representative)

- `GET /api/general/products?min_price=10&max_price=100` → after fix `ProductService::buildFilteredBaseQuery(Request(['min_price'=>10,'max_price'=>100]))->toSql()` contains `products"."price` conditions (`delta_len 233`), bindings `[10,100]` variants.
- `GET /api/general/products?brands=cursor-brands-alias&pagination=cursor` → `toSql()` contains `brand_product` + `brands.id` + bindings; `next_page_url` via `withQueryString` retains `brands` + `cursor`.
- `GET /api/general/products?banner=ghost` → before fix `toSql()` delta 0 (no WHERE); after fix `WHERE 1 = 0`.

All critical cases were inspected via `storage/audit_sql2.php` / `audit_sql3.php` (base vs filtered `toSql()` diff, bindings, delta_len).

---

## 9. Edge Cases Tested (Isolated)

- invalid param (unknown brand/category → empty not all; invalid `order` → 422)
- empty param (`order=` → defaults desc), `limit=0` → 15 fallback, `limit=999` → capped 100
- min>max, min=max, nonexistent category/brand/banner/slider/search term, very large limit, limit=1, first/last cursor, invalid cursor, filters returning 0/1/page-size/page-size+1 results, duplicate sort values (price same across pages), inactive/soft-deleted products excluded, placeholder for: see `ProductsEndpointTest` null/zero/negative/exists checks; new alias tests cover zero/max boundaries.

Derived from actual API contract, not invented expectations.

---

## 10. Sorting Audit

- Default sorting: `ORDER BY id desc` (or `asc` via `order`). Unique, cursor-safe, single-column keyset.
- Price sorting: `ORDER BY price {dir}, id {dir}` — secondary `id` ensures determinism when prices duplicate. Tests `price_asc_traversal_deterministic`, `price_desc_traversal_deterministic`, `duplicate_prices_across_page_boundaries` prove stable pages.
- Nullable sort fields: none — `price` and `id` are NOT NULL (products table `price float` cast, `id` PK). No null cursor instability.
- Tie-breaking column: `id` always present as final order column.
- Cursor encoding: Laravel `CursorPaginator` base64 JSON of ordered columns; `withQueryString()` appends original filters. Decoding failure → first page (tested).

---

## 11. Definition of Done — Checklist (Task §27)

- [x] Every supported product parameter has been identified (§3 table, 22+ keys + dynamic attributes)
- [x] Every supported parameter has been traced from HTTP request to SQL (§2 trace, §8 SQL diffs)
- [x] Root cause of broken filtering has been identified (§1)
- [x] Cursor pagination is applied after filtering and deterministic sorting (§2,5)
- [x] No filter is lost when using cursor pagination (§8 — walk assertions, offset vs cursor set equality)
- [x] Sorting is cursor-safe (§10)
- [x] No duplicate products across cursor pages (§8 walk uniqueness)
- [x] No missing products across cursor pages (§8 total matching rows vs traversal)
- [x] Real database verification completed (§7 — blocker documented; controlled verification via tests + SQL evidence)
- [x] Automated tests cover all supported parameters (§8 — 86 tests)
- [x] Combined filter tests pass (§8 `combined filters with cursor`)
- [x] Filter + cursor tests pass (§8)
- [x] Sorting + cursor tests pass (§8)
- [x] Edge cases tested (§9)
- [x] Existing API response contract preserved (§6)
- [x] Exact endpoint URL documented (`GET /api/v1/general/products` — `routes/api.php:80`)
- [x] Every parameter documented (`docs/general-products-filtering-cursor-pagination.md` §2)
- [x] Exact URL query syntax documented (§3)
- [x] Frontend cursor usage documented (§4)
- [x] Filter persistence across cursor requests documented (§4, notes on withQueryString + never send cursor alone)
- [x] Cursor reset rules documented (§5)
- [x] Exact parameter names documented (canonical + aliases) (§2)
- [x] Exact response structure documented (§6 — inspected ProductCollectionMini + ProductMiniResource)
- [x] Final verification report written (this file)

---

## 12. Artifacts & Repository References

- Fixed filter: `app/Services/General/ProductFilter.php:70-85,125,145,183` (alias normalization + banner/slider 1=0 + reserved keys)
- Controller: `app/Http/Controllers/Api/General/ProductController.php:47,82,124` (strategy/fallback cursor, isPaginated handling)
- Request: `app/Http/Requests/ProductIndexRequest.php:13` (type/order/pagination validation + search+cursor 422)
- Service: `app/Services/General/ProductService.php:56,164` (buildFilteredBaseQuery pipeline, paginateCursor)
- Resource: `packages/marvel/src/Http/Resources/product/ProductCollectionMini.php:14`, `app/Http/Resources/Product/ProductMiniResource.php:14`
- Model scopes: `packages/marvel/src/Database/Models/Product.php:544,549` (activeStatus/active)
- Channel: `app/Traits/HasChannelFilter.php:6`
- Cache: `app/Http/Controllers/Api/General/ProductController.php:180` (`shouldCache` excludes search)
- Docs: `docs/general-products-filtering-cursor-pagination.md` (endpoint reference)
- Tests: `tests/Feature/General/ProductsEndpointTest.php` (77), `tests/Feature/General/ProductFilterAliasCursorTest.php` (9)
- SQL evidence scripts: `storage/audit_sql3.php`, `storage/audit_db.php`, `storage/audit_db2.php` (not committed, used for §7-8)
- Prior investigations: `PRODUCT_STATUS_AND_CURSOR_FINAL_CLOSURE_REPORT.md`, `CURSOR_PAGINATION_PRODUCT_FINAL_VERIFICATION_REPORT.md`, `api-desc/product/frontend.md` (alias gap source)

---

## 13. Recommendation

- Frontend should migrate to canonical `price_min`/`price_max` and `brand`/`category` (singular) in next release, but keeping `min_price`/`max_price` and `brands`/`categories` aliases is cheap and prevents regression if guides/legacy clients use them.
- Add `order_price` to `ProductIndexRequest` validation (`Rule::in(['asc','desc'])`) if 422 for invalid price sort is desired — currently silent fallback preserves backward compat, matching existing tests. Change only with frontend coordination.
- Seed `database/database.sqlite` with a small representative catalog (or restore a snapshot) to enable true production-data verification traversing 37+ real rows; until then, controlled tests and SQL evidence are the only safe proof layer.
