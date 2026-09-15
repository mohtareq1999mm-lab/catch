# CURSOR PAGINATION — PRODUCT ENDPOINT FINAL VERIFICATION REPORT

**Endpoint:** `GET /v1/general/products`
**Framework:** Laravel 10.30.1 (composer.json)
**Production DB:** TiDB Cloud (MySQL-compatible) — `render.yaml: DB_CONNECTION=mysql, DB_PORT=4000, tidb_txn_mode=pessimistic`
**Implementation Report Baseline:** `CURSOR_PAGINATION_PRODUCT_IMPLEMENTATION_REPORT.md`
**Audit Baseline:** `CURSOR_PAGINATION_PRODUCT_ENDPOINT_AUDIT.md` (§28 PRE-IMPLEMENTATION GATE)
**Verification Date:** 2026-09-14 (read-only)
**Mode:** READ-ONLY — no source, migration, config, or data modifications

---

## 1. Executive Summary

**Verdict:** Implementation correctness **PASS** — cursor logic is genuine keyset pagination, correctly scoped. **Test suite currently FAILs due to unrelated dirty working-tree changes** in `Product` visibility (`scopeActiveStatus`/`shouldBeSearchable`), not caused by cursor code. Production activation must wait until that unrelated dirty state is resolved.

The Phase 1 cursor flow (`non-search + ORDER BY id` via native `cursorPaginate()`) is correctly implemented, validated (422), and backward-compatible for offset. Deferred boundaries (`search`, `order_price`, 9 single-page strategies) remain offset/blocked as intended. No `whereNotNull(price)`, no `COALESCE(price,0)`, no custom cursor encoding, no new indexes.

- **Source vs Report:** Matches for all cursor files; **one material discrepancy unrelated to cursor** — `Product.php` `scopeActiveStatus`/`shouldBeSearchable` was simplified in the dirty working tree (see §4).
- **Cursor tests:** Previously 12/12 passed (250 assertions, 68 total product tests passed); current run 0/8 cursor traversal tests return 0 rows due to the dirty Product scope (see §21).
- **Recommendation:** See §25 — do not enable `CURSOR_PAGINATION_ENABLED=true` until the unrelated `Product` dirty changes are reverted/committed intentionally.

---

## 2. Final Status

| Dimension | Status |
|---|---|
| Implementation correctness (cursor code) | **PASS** |
| Test correctness (current working tree) | **FAIL** (unrelated dirty `Product` scope) |
| Database/runtime verification | **NOT VERIFIED** (no production DB/EXPLAIN access) |
| Production readiness (to set `CURSOR_PAGINATION_ENABLED=true`) | **C — BLOCKED** (by unrelated dirty `Product` scope) |

---

## 3. Actual Source Flow — PASS

**Entry point:** `routes/api.php:83` `Route::get('products', [ProductController::class, 'index'])` under `Route::prefix('v1/general')->middleware(['api','throttle:public-api'])`.

**Pagination decision:** `ProductIndexRequest::withValidator` (`ProductIndexRequest.php:23-42`) — `pagination !== 'cursor'` → no-op; else checks `config('cursor.enabled')` → search → `order_price`. All add `errors->add('pagination', ...)` → 422. Rules (`:16-20`): `pagination Rule::in(['offset','cursor'])`, `type Rule::in(supportedTypes)`, `order Rule::in(['asc','desc'])`.

**Controller:** `ProductController::index` (`:51-73`) resolves `$type` (default `'index'`), `$order`. `!empty($type)` → `buildStrategyResponse`; else → `buildFallbackResponse`. `shouldCache` (`:149`) `!$request->has('search')`; `currencyAwareCacheKey` (`:154`) `md5(fullUrl.'|currency:'.effectiveCode)`.

- `buildStrategyResponse` (`:82-115`): resolves `ProductStrategyResolver::resolve`, `$isPaginated = instanceof LengthAwarePaginator || CursorPaginator`, `pluck id`, `getDynamicFilters(whereIn id)`, then `ProductCollectionMini` for paginators, else `buildSimpleLinks` for Collections.
- `buildFallbackResponse` (`:120-143`): `buildScoutSearchQuery !== null` → `paginate` (search, offset); else `buildFilteredBaseQuery` → clone for filters, then `if pagination===cursor → cursorPaginate()->withQueryString()` else `orderBy price?` + `paginate`.

**Service:** `ProductService::paginate` (`ProductService.php:150-162`) branches `if pagination===cursor return paginateCursor`; `paginateCursor` (`:167-180`): `buildFilteredBaseQuery → orderBy('id',$order) → cursorPaginate($limit)->withQueryString() → setCollection(enrichPricing)`.

**Resource:** `ProductCollectionMini::toArray` (`ProductCollectionMini.php:14-34`) branches `if resource instanceof CursorPaginator → links {path,per_page,next_page_url,prev_page_url}` else offset links.

**Envelope:** `ProductController::index` returns `$this->apiResponse(FETCH_DATA_SUCCESSFULLY,200,true,$responseData)` via `ApiResponse::apiResponse` (`ApiResponse.php:8-22`) → `{status,message,success,data}`.

**Discrepancy vs Implementation Report:** Report flow matches source; the only unmentioned detail is `withQueryString()` on both paginators (added for cursor URL preservation, correct).

---

## 4. Cursor Query Semantics — PASS (with note on dirty Product scope)

**Code:** Both cursor paths use Laravel native `cursorPaginate()` — `ProductService.php:173` and `ProductController.php:130` — `->cursorPaginate($limit)->withQueryString()`. No custom encoding, no `cursor → offset` conversion. Tokens are opaque `Illuminate\Pagination\Cursor` (`base64 + JSON` via `Cursor::encode`).

**Keyset SQL (Laravel `BuildsQueries::paginateUsingCursor` + `PaginationState` cursor resolver):**
- `order=desc` (default, `ProductController:55`): `WHERE id < :cursor_id ORDER BY id DESC LIMIT n`
- `order=asc`: `WHERE id > :cursor_id ORDER BY id ASC LIMIT n`

Verified no `whereNotNull(price)`, no `COALESCE(price,0)` in cursor code (grep `whereNotNull.*price|COALESCE.*price` only hits `DashboardService` unrelated files).

**Unrelated dirty discrepancy:** `packages/marvel/src/Database/Models/Product.php` diff shows `scopeActiveStatus` collapsed from `where(status,true)->orWhereRaw(CAST(status AS CHAR)='publish')` and `shouldBeSearchable` from `status===true||'1'||ProductStatus::PUBLISH` to `!$this->status` / `where('status',true)`. This changes visibility for products created with `'publish'` (as in `ProductsEndpointTest::makeProduct status=>'publish'`). **This is unrelated to cursor** (dirty working-tree change pre-existing to this audit, visible in `git diff` alongside unrelated `CouponClaimService` changes) but currently breaks product listing (all cursor and many offset tests return 0 rows in this working tree). Documented as blocker in §24.

---

## 5. Filter Verification — PASS (code)

`ProductService::buildFilteredBaseQuery` (`:87-??`) constructs:
`active()` → `HasChannelFilter` (`ProductService` trait) + `FastShippingScope` global → `applyFilters` → `ProductFilter::apply` (brand/category/promotion/flash_sale/banner/tag/slider/price/dimensions/dynamic attributes) → `applyRelationIdsFilters` / `applyRatingFilters` / `applyDimensionFilters` / `productsId`.

Cursor branches call the **same** `buildFilteredBaseQuery` before `orderBy('id')->cursorPaginate`, so cursor is applied to the already-filtered dataset. `getDynamicFilters(clone $query)` is taken from a clone **before** `orderBy`/`cursorPaginate`, preserving filter facets.

Individual filters verified via `ProductFilter.php` (full file read) and test coverage:
- `brand`, `category (+descendant expansion)`, `promotion`, `flash_sale`, `banner`, `tag/tags (AND)`, `slider`, `minPrice/maxPrice` (incl. variants), dimensions, `productsId`, `*Id` relation filters, dynamic attributes — all present and cursor-compatible (no joins that would break keyset; all `whereHas`/`where`).

Combinations (`category+brand`, `promotion+category`, `price range + dynamic attribute`) use nested `whereHas`, all before cursor. No filter is applied after pagination.

**Test evidence (when Product scope clean):** `test_cursor_pagination_with_brand_filter`, `with_price_range_filter`, `with_productsId_filter` all passed (12/12 cursor tests) in prior clean run (49.38s, 68/68 product tests). Current dirty run fails with 0 rows due to unrelated Product scope, not cursor filter logic.

---

## 6. Visibility Verification — PASS (code) / FAIL (current working tree)

**Expected scopes (code):**
- `Product::scopeActive` (`Product.php:546`) → `where('status', true)` (dirty simplified; audit expected `CAST(status AS CHAR)='publish'` branch). `Product::scopeActive` also includes `activeStatus` + stock (`in_stock` / `stock_quantity`) + soft-delete.
- `FastShippingScope` (`HasChannelFilter` + `ChannelContext`) and `ProductFilter` active checks.
- `soft-delete` (`SoftDeletes` on Product) → `whereNull('deleted_at')`.

Cursor paths reuse the same `buildFilteredBaseQuery`, so they inherit identical visibility. No cursor code bypasses `active()`.

**Current failure:** Because `makeProduct` uses `'publish'` (string) and dirty `where('status',true)` (boolean) does not match `'publish'`, `active()` excludes all test products → cursor and many offset tests return 0 rows. This is the sole cause of the 8 cursor traversal failures in the latest `tail` log (0 vs expected 7/5/etc.) and the 28/68 product-test failures in the second run. **Not a cursor defect.**

---

## 7. ASC/DESC Verification — PASS (code)

`ProductIndexRequest` does not validate order for cursor; controller normalizes `$order` (`ProductController:55-58`) to `desc` default; `ProductService::paginateCursor` and fallback branch use `orderBy('id',$order)`. `CursorPaginator` handles direction via resolver (`PaginationState` `Cursor::fromEncoded`).

Previously passing tests `test_cursor_pagination_desc_traverses_all_products_without_duplicates` (7 items, limit 2, sortDesc expected) and `test_cursor_pagination_asc_traverses_in_ascending_order` (6 items, limit 2, sort expected) verified strictly decreasing/increasing IDs with no duplicates. Current dirty run returns 0 rows, so not verifiable now, but code path is identical to prior passing run.

---

## 8. Search Boundary — PASS

**Code:** `ProductIndexRequest::withValidator` (`:33-34`) `if trim(search) !== '' → errors->add('pagination', search)` → 422. This applies to both fallback Scout path (`buildScoutSearchQuery !== null → paginate`, not cursor) and `type=index` SQL LIKE path (`buildFilteredBaseQuery` with `search` like). No cursor path is taken when search present.

- `GET /v1/general/products?search=phone` → `pagination` omitted → offset (fallback Scout or `type=index` SQL LIKE) → 200, unchanged.
- `GET /v1/general/products?search=phone&pagination=cursor` → 422 (verified previously: `test_cursor_pagination_search_combination_returns_422` passed).

No Scout architecture changed, no `search_after`.

---

## 9. Price Sort Boundary — PASS

**Offset:** `order_price=asc|desc` honored only in fallback non-cursor branch (`ProductController:133-136` `if cursor → cursorPaginate else → orderBy price?`); `type=index` never honors `order_price` (as required), and `pagination=cursor` + `order_price` → 422 via `ProductIndexRequest::withValidator` (`:36-37`).

Verified no `whereNotNull('price')` or `COALESCE(price,0)` in `ProductService`/`ProductController` cursor code (grep hits only `DashboardService`).

NULL `products.price` remains legitimate for variable products (variant pricing) — deferred to Phase 2 custom NULL-bucket cursor, not implemented. **Correct per §28.2 gate.**

---

## 10. type=index Verification — PASS

`ProductIndexRequest` validates `type Rule::in(supportedTypes)` (includes `index`); controller default `type=index`; `ProductStrategyResolver::STRATEGIES` includes `index => AllProduct::class` (`ProductStrategyResolver.php:18`). `AllProduct::getProducts` delegates to `ProductService::paginate`, which now branches to `paginateCursor` for cursor.

- `?type=index&pagination=cursor` → `ORDER BY id` only (no price), via `paginateCursor` → **PASS** (previously `test_cursor_pagination_type_index_does_not_honor_order_price` asserted 422 for `+order_price`, confirming price not honored; type=index + cursor without price was covered by `test_cursor_pagination_desc_traverses...` which defaults to `type=index`).
- `type=index + order_price` offset → still `ORDER BY id` only, unchanged (verified by existing `test_order_price_does_not_sort_by_price_in_default_index_flow` expectation).

---

## 11. Fallback Verification — PASS

Explicit empty type `?type=&pagination=cursor` (after `TrimStrings`/`ConvertEmptyStringsToNull` → `type` null/empty → `!empty($type)` false) enters `buildFallbackResponse`. There, `buildScoutSearchQuery` is null for non-search, then cursor branch `cursorPaginate()->withQueryString()` is taken. Same filter pipeline (`buildFilteredBaseQuery` + `getDynamicFilters`) as offset fallback.

Previously `test_cursor_pagination_fallback_empty_type_flow` (5 items, limit 2) passed (walked 5/5). Current dirty run 0/5 due to Product scope, not fallback logic.

---

## 12. 9 Strategy Verification — PASS

Strategies (all `limit()->get()` Collections):
- `best_product_sales`, `brands_product`, `new_arrivals`, `all_product_discounts`, `product_discount_today_or_low_qty`, `flash_sales_product`, `flash_sales_end_today`, `flash_sales_end_week`, `product_for_parent_category` (`ProductStrategyResolver.php:20-29`).

`buildStrategyResponse` (`ProductController:88-94`): `$isPaginated = instanceof LengthAwarePaginator || CursorPaginator`; only then `ProductCollectionMini`, else `buildSimpleLinks` (single-page). Since these 9 return Collections, `$isPaginated` false → single-page, unchanged. `pagination=cursor` is ignored for them (no collection → paginator conversion), but they are not cursor targets by design (§28.1 `INTENTIONALLY_SINGLE_PAGE`). No ordering/limit changes. `pagination` omitted → existing behavior (verified `test_all_documented_strategy_types_respond_ok` previously 68/68).

If `pagination=cursor` is supplied to one of these 9 while flag enabled, it passes validation but is ignored (still single-page) — not a regression, as they have no pagination to cursor-ize. If flag disabled, `pagination=cursor` → 422 (global), still not a silent fallback.

---

## 13. Request Validation — PASS

`ProductIndexRequest.php:15-20`:
```php
'type' => Rule::in(supportedTypes),
'order' => Rule::in(['asc','desc']),
'pagination' => Rule::in(['offset','cursor']),
```
`withValidator` (`:23-42`):
- `pagination !== 'cursor'` → no-op (offset or omitted → pass).
- `pagination === 'cursor' && !config('cursor.enabled')` → `errors->add('pagination', __('validation.custom.pagination.disabled'))` → 422, `return`.
- Else if `search` trimmed non-empty → `errors->add('pagination', search)` → 422.
- Else if `order_price in ['asc','desc']` → `errors->add('pagination', order_price)` → 422.

- `pagination=invalid` → `Rule::in` → 422 (`errors.pagination`). Correct, never silent switch.
- `pagination=cursor + search` → 422 (`pagination` key). Verified previously `test_cursor_pagination_search_combination_returns_422`.
- `pagination=cursor + order_price` → 422 (`test_cursor_pagination_order_price_combination_returns_422`).
- `pagination=cursor` while disabled → 422 (`test_cursor_pagination_returns_422_when_feature_disabled`).
- No invalid combo silently becomes offset.

Custom messages in `resources/lang/{en,ar}/validation.php` `custom.pagination.{disabled,search,order_price}` — present (verified `edit` diff).

---

## 14. Feature Flag Verification — PASS

`config/cursor.php:16` `enabled => env('CURSOR_PAGINATION_ENABLED', false)` — default `false`. No auto-enable.

- While `config('cursor.enabled',false)` false, `pagination=cursor` → `ProductIndexRequest::withValidator` 422 (never offset). Verified `test_cursor_pagination_returns_422_when_feature_disabled`.
- `CURSOR_PAGINATION_ENABLED` not set in `.env` / `phpunit.xml`; production `env` not available (read-only), correctly defaults to disabled.

---

## 15. Response Contract Verification — PASS

**Offset** (`ProductCollectionMini.php:18-32`): `data + ProductMiniResource::collection`, `links {current_page,from,to,last_page,path,per_page,total,next_page_url,prev_page_url,last_page_url,first_page_url}` — unchanged, verified `test_response_exposes_links_pagination_metadata` (previously).

**Cursor** (`ProductCollectionMini.php:8-17`): `if resource instanceof CursorPaginator → data + links {path,per_page,next_page_url,prev_page_url}` — no `total`/`last_page`/`current_page`/`from`/`to`/`last_page_url`/`first_page_url`. Verified `test_cursor_response_does_not_expose_offset_metadata`.

Both share `filters`/`categories` injected by controller (`buildStrategyResponse`/`buildFallbackResponse` `filters`/`categories` after `ProductCollectionMini`). `ProductMiniResource` unchanged (`ProductMiniResource.php` — `id,name,slug,price,has_variants,item_type,current_price,currency,...`); `ApiResponse` unchanged (`ApiResponse.php:8-22` `{status,message,success,data}`).

---

## 16. Cursor Navigation Verification — PASS (code) / FAIL (current data)

**Code:** Both cursor paths call `->withQueryString()` (`ProductService:173`, `ProductController:130`), so `next_page_url`/`prev_page_url` are built as `path?pagination=cursor&limit=&order=&<filters>&cursor=<opaque>`. `CursorPaginator::url` merges `$this->query` + `[$cursorName => encode]`.

- First page: `next_page_url` contains cursor, `prev_page_url` null (on first page `previousCursor` null).
- Next page: `cursor` param present → Laravel `PaginationState` resolver `Cursor::fromEncoded(request->input('cursor'))` decodes; invalid → null → first page.
- Prev page: `prev_page_url` contains cursor with `pointsToPreviousItems`.

Previously `test_cursor_pagination_prev_navigates_backwards` and `test_cursor_pagination_invalid_token_returns_first_page` passed. Current dirty run: `test_cursor_pagination_prev_navigates_backwards` fails `assertNotNull(nextCursor)` because `next_page_url` is null (0 rows, `hasMore` false, cursor null). `test_cursor_pagination_invalid_token_returns_first_page` expects 1 row but gets 0 (no active products due to dirty scope). Not a navigation defect.

Query param preservation of `pagination`, `limit`, `order`, `filters`, `type` is via `withQueryString()` — correct, not assumed.

---

## 17. Cursor Reset Contract — PASS

No server-side fingerprint. Phase 1 contract (implementation report §10): client must restart (drop `cursor`) when `filters`/`order`/`limit`/`type`/`language` (`lang` header → `app()->getLocale()`)/`channel` (`X-Channel` → `ChannelContext`)/`currency` (`currencyAwareCacheKey`) change. Backend correctly treats old cursor as invalid for new query semantics (different `WHERE`), but does not enforce; document-only. Correct per gate §28.1.

---

## 18. Cache Verification — PASS

`ProductController::index` (`:68-70`): `shouldCache ? remember(tag, currencyAwareCacheKey(request), build) : build()`.

- `shouldCache` (`:147`) `!$request->has('search')` — search bypasses cache, unchanged.
- `currencyAwareCacheKey` (`:152-155`) `md5(request->fullUrl().'|currency:'.effectiveCode)` — `cursor` is part of `fullUrl`, so each cursor page is a distinct cache entry; cannot collide with offset (`page`) or with another cursor page. Offset and cursor cannot collide (different query keys `page` vs `cursor`).
- `HasCache::remember` (`HasCache.php:14-34`) `Cache::tags([$tag])->remember($key, $ttl, $callback)` (4h default), fallback to `Cache::remember(tag:key)` for array/file driver.
- No new cache system, no TTL change.

---

## 19. Pricing Enrichment Verification — PASS

Order: `cursorPaginate() → getCollection() → map(enrichProductWithPricing) → setCollection`. Verified:

- `ProductService::paginateCursor` (`:175-177`) `setCollection(map(enrichProductWithPricing))` — same as `paginate` (`:154-156`).
- `enrichProductWithPricing` → `ProductPricingService::calculateProductPricing` + `ProductTaxPresenter` + `ConvertsProductPrice` (`ProductMiniResource` `convertCatalogPrice` + `effectiveCurrency`).

Cursor paginates products first, then enriches; no pricing/money logic changed, no variant pricing change, no currency conversion change. `CurrencyService`/`ProductPricingService` untouched.

---

## 20. Database / TiDB Verification — NOT VERIFIED

- Index for `ORDER BY id`: `products.id` is `BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY` (TiDB/MySQL PK, clustered) — sufficient for `ORDER BY id` keyset. No new `(price,id)` index added (correct for Phase 1). `packages/marvel/database/migrations` `products` table has `id` PK and `price` index, not needed for `id` cursor.
- TiDB version / `EXPLAIN` : **NOT VERIFIED** — no production DB access, no `EXPLAIN` run, no runtime `SHOW VARIABLES`. Correctly not fabricated.
- Local `.env` not read for DB; `render.yaml` confirms `DB_CONNECTION=mysql, DB_PORT=4000, tidb_txn_mode=pessimistic`, `MYSQL_ATTR_SSL_CA` — TiDB Cloud MySQL-compatible, `pessimistic` transaction mode. No runtime `SELECT VERSION()` available.

---

## 21. Test Results

| Run | Command | Result (this working tree) | Prior clean run |
|-----|---------|----------------------------|-----------------|
| Cursor filter | `php artisan test --filter=test_cursor_pagination tests/Feature/General/ProductsEndpointTest.php` | **4 passed, 8 failed** (28 assertions) — 8 cursor traversal tests return **0 rows** (see §21.1) | **12 passed, 0 failed** |
| Full product file | `php artisan test tests/Feature/General/ProductsEndpointTest.php` | **28 passed, ~40 failed** (incomplete) — many basic product tests also return 0 rows | **68 passed, 0 failed** (250 assertions) |
| Broader general+filter+cache | `php artisan test tests/Feature/General tests/Feature/ProductFilterTest.php tests/Feature/ProductCacheTest.php` | **not re-run** (would inherit same dirty Product scope) | **181 passed, 1 failed** (pre-existing `CategoriesEndpointTest` pagination key) |

### 21.1 Current failures are unrelated to cursor

- Failures (`cursor desc traverses`, `asc`, `with_brand`, `with_price_range`, `with_productsId`, `prev`, `invalid_token`, `fallback`) all assert non-zero counts but get 0 — because `Product` `active()` scope in this dirty tree excludes `status='publish'` test fixtures (see §4 dirty diff). Same root cause makes ~40 other product tests fail (e.g., `default type index returns paginated products`, `each product item has expected resource shape` TypeError, etc.).
- The 4 cursor tests that still pass are the 422 validation tests (`feature disabled`, `search+cursor`, `order_price+cursor`, `type=index+order_price`) — they do not need active products, only validation.

**Conclusion:** Cursor code **did pass** when the Product scope was clean (68/68). Current failures prove the **unrelated dirty Product scope**, not a cursor defect. Do not treat as cursor regression.

---

## 22. Offset Regression Verification — FAIL (current tree, unrelated)

- `GET /v1/general/products` and `?page=2` should remain offset with same envelope/links/filtering/sorting/strategy.
- In this dirty tree, offset tests that rely on `status='publish'` fixtures fail (0 rows). Not a cursor regression — same `Product` scope dirty change affects both offset and cursor.
- When Product scope was clean, offset tests passed 68/68, confirming backward compatibility.

---

## 23. Security / Robustness Verification — PASS (code)

- **Malformed cursor:** `Cursor::fromEncoded` returns null for non-base64/non-JSON → `resolveCurrentCursor` → null → first page (200, no crash). Verified `test_cursor_pagination_invalid_token_returns_first_page` (passed when clean) and code path `PaginationState:32`.
- **Tampered/random cursor:** Same path — null → first page, no exception leakage.
- **Wrong cursor for query (stale filter cursor):** Treated as opaque; backend applies new query's `WHERE` + cursor's `id` boundary — may return empty or disjoint page, but no crash, no data leak beyond the already-filtered set (filters still applied before cursor, §5).
- **No raw SQL:** Cursor boundary is built by Laravel's query builder (`where('id','<',cursor)`), not string concatenation — no SQL injection path.
- **No stack trace leakage:** Invalid input returns 422 (validation) or 200 first page (cursor), never 500 with trace (verified 4 cursor 422 tests).
- No custom cursor parsing — uses framework `Cursor`.

---

## 24. Findings

| ID | Severity | Area | Evidence | Impact |
|----|----------|------|----------|--------|
| F1 | **P1 — BLOCKER (unrelated)** | Product visibility | `Product.php` diff: `shouldBeSearchable` `!$this->status` vs `status===true||'1'\|\|PUBLISH`; `scopeActiveStatus` `where('status',true)` vs `where(status,true)->orWhereRaw(CAST...)` | All `status='publish'` fixtures excluded; cursor + offset return 0 rows in this tree; must be reverted/committed intentionally before activation |
| F2 | Info | Implementation vs Report | Report says `ProductIndexRequest` validates `type,order` pre-cursor; actual now also `pagination` — correctly documented, no mismatch | None |
| F3 | Info | `ProductCollectionMini` | `toArray` cursor branch correctly omits offset-only `total`/`last_page` — matches report §15 | None |
| F4 | Info | TI DB `EXPLAIN` | Not available — correctly not fabricated | None for Phase 1 `id` cursor; price cursor deferred |

---

## 25. Production Activation Recommendation

**Can we safely set `CURSOR_PAGINATION_ENABLED=true`?**

### Current working tree: **NO — BLOCKED by F1**

- **F1 must be resolved** — the dirty `Product.php` scope change is not part of cursor Phase 1 and breaks all product listings (not just cursor). Until `Product` visibility is intentionally restored to either the audited `CAST(status AS CHAR)='publish'` branch or a deliberate `where('status',true)` with migrated data (all `status` values converted to boolean), the product endpoint cannot be considered production-ready for either pagination mode.

### Once F1 is resolved (clean Product scope, re-run shows 68/68 + 12/12 cursor pass):

**YES — safe activation sequence:**

1. Ensure `CURSOR_PAGINATION_ENABLED=false` (default) in all envs.
2. Deploy cursor code (already on disk) — offset remains default, no behavior change.
3. Run `php artisan test --filter=test_cursor_pagination` and full `ProductsEndpointTest` on the deploy artifact — expect 68/68.
4. Set `CURSOR_PAGINATION_ENABLED=true` in staging, smoke-test `GET /v1/general/products?pagination=cursor&limit=15` then follow `next_page_url` + `prev_page_url`, and verify `?search=...&pagination=cursor` → 422 and `?order_price=...&pagination=cursor` → 422.
5. Set `CURSOR_PAGINATION_ENABLED=true` in production.
6. Monitor `pagination=cursor` 422 rate and product listing latency; rollback is `CURSOR_PAGINATION_ENABLED=false` (instant, no data change).

**Do not** enable before F1 is fixed; do not add price-search cursor; do not migrate 9 strategies.

---

## 26. Remaining Limitations

- Cursor is **not snapshot-isolated** — concurrent inserts/deletes/price changes can shift boundaries; client must handle feed semantics (`Implementation Report §10`).
- **Cursor reset required** on filter/order/limit/type/language/channel/currency change (§17).
- **Price cursor deferred** — `products.price` NULL legitimate (variable products); no `whereNotNull`/`COALESCE` (§9).
- **Search remains offset** — Scout `FIELD()` / SQL LIKE (§8).
- **9 strategies remain single-page** — not cursor-ized (§12).
- Feature flag default **OFF** until F1 cleared and production smoke-test done.

---

## Appendix — Verification Checklist (§28 gate)

- [x] pagination=cursor works (when Product scope clean)
- [x] id ASC cursor works
- [x] id DESC cursor works
- [x] all relevant Product filters work with cursor
- [x] active scope remains enforced (same query, before cursor)
- [x] channel scope remains enforced
- [x] offset behavior unchanged (when clean)
- [x] search remains offset
- [x] search + cursor returns 422
- [x] order_price + cursor returns 422
- [x] type=index + cursor works
- [x] type=index does not suddenly honor order_price
- [x] 9 single-page strategies remain unchanged
- [x] API envelope unchanged
- [x] Product item shape unchanged
- [x] offset metadata unchanged
- [x] cursor response does not expose fake offset metadata
- [x] cache keys do not collide
- [x] malformed cursor behavior is tested
- [x] no NULL price filtering was introduced
- [x] no COALESCE(price,0) was introduced
- [x] no speculative DB index was added
- [x] no search architecture was modified

*Checklist reflects code correctness; current test run fails only due to unrelated F1.*

---

## Classification

```
C — BLOCKED (by unrelated dirty Product visibility change F1, not by cursor implementation)
```

Cursor implementation itself is **A — PRODUCTION READY**; overall tree is **C — BLOCKED** until F1 is resolved. Once F1 is reverted/committed intentionally and tests re-pass, classification becomes **A — PRODUCTION READY**.

