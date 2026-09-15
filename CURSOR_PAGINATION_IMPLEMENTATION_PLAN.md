# CURSOR PAGINATION IMPLEMENTATION PLAN — Meem E-Commerce

**Repository:** `D:\work\meem`  
**Mode:** READ-ONLY / PLAN (Build Mode now — file generated)  
**Date:** 2026-09-14  
**Status:** `ARCHITECTURE_CLOSED: NO — pending FE repo audit` / `IMPLEMENTATION_READY: YES for Products+Orders SQL cursor (opt-in)` / `RUNTIME_CERTIFIED: PENDING (EXPLAIN + prod SCOUT_DRIVER)`

---

## 1. Executive Summary

**Current:** 100% Offset `LengthAwarePaginator` via `Prettus BaseRepository::paginate($limit)` + `orderBy('id', $order)` (single column, no tie-breaker) across all listings; `COUNT(*)` overhead; search via `SCOUT_DRIVER=collection` (`LIKE` fallback, not Meilisearch) paginated offset.

**Decision:** Introduce **Dual Pagination Architecture** on **same endpoints** — default **Offset**, opt-in **Cursor** via `?pagination=cursor`. Scope: `GET /v1/general/products` (non-search SQL) + `GET /v1/general/orders` (customer). Search (`?search=`), admin tables, `categories|brands|reviews`, import/export, home aggregates **remain offset/unchanged**.

**Why:** Storefront feed + order history are append-only, large, sequential, benefit from keyset `WHERE id < cursor ORDER BY id DESC LIMIT n` (index seek, no `COUNT`). Admin `Page 1..N` needs `total/last_page` → offset. Search relevance ranking cannot be keyset → offset.

**Guarantees:** `VERIFIED FACT` = evidence file:line; `ARCHITECTURAL RECOMMENDATION` = deterministic tie-breaker + composite indexes; `BLOCKED` = frontend repo + prod `MEILISEARCH_HOST` + migration `EXPLAIN`.

---

## 2. Current Architecture

**Route:** `routes/api.php:47` (products) / `routes/api.php:108` (orders) + `packages/marvel/src/Rest/Routes.php` admin resources.

**Controller:** `app/Http/Controllers/Api/General/ProductController.php:49` `index(ProductIndexRequest)` + `app/Http/Controllers/Api/General/OrderController.php` (thin) delegates to service.

**Strategy:** `ProductStrategyResolver.php:32` `resolve($type)` → `AllProduct::getProducts()` → `ProductService.php:86` `paginate()`; other strategies `BestProduct`, `NewArrivals`, etc. all delegate to `ProductService`.

**Service/Query:** `ProductService.php:55` `buildFilteredBaseQuery()` → `Product::query()->active()->with(relations)->applyChannelHomeFilter()->applyProductFilters()->applyIdsFilter()->whereRaw('1=0' if Scout miss)` → `orderBy('id', $order)` (+ optional `orderBy('price',$orderPrice)` before) → `paginate(limit)`. `OrderService.php:64` `paginateForUser()` → `Order::query()->forUser(userId)->when(status)->with(relations)->paginate(limit)->withQueryString()`.

**Model:** `Product.php:23` (`Searchable`, `SoftDeletes`, `scopeActive` L532, `shouldBeSearchable` L538), `Brand.php:1` (`Sortable`, `scopeActive`), `Order` model (not read but `forUser`, `HasChannelFilter`).

**Resource:** `BrandController.php:62` `BrandResource::collection($paginator)->response()->getData(true)` → `HasCache::remember` → `apiResponse()`; `ProductController.php:94` `new ProductCollectionMini($data)->toArray(request)` + `buildSimpleLinks`; `ApiResponse` trait `Marvel\Traits\ApiResponse` wraps `success/message/data`.

**Cache:** `HasCache::remember(tag, key, ttl)` + `HomeService::clearCache()` + `api_cache_version` middleware `CacheApiResponse.php` (verified `routes/api.php` `throttle:public-api`).

---

## 3. Pagination Inventory (VERIFIED FACT)

| file:line | call | type |
|---|---|---|
| `packages/marvel/src/Database/Repositories/BaseRepository.php:1` | `extends BaseRepository` → `paginate($limit)` | Offset `LengthAwarePaginator` (Prettus) |
| `packages/marvel/src/Http/Controllers/BrandController.php:60` | `ordered()->paginate($limit)` | Offset |
| `app/Http/Controllers/Api/General/ProductController.php:84` | `instanceof LengthAwarePaginator` check | Offset |
| `app/Services/General/ProductService.php:93,97` | `orderBy('id')->paginate(limit)` (fallback + scout) | Offset |
| `app/Services/General/OrderService.php:72` | `paginate(limit)` | Offset |
| `app/Services/General/FastShippingService.php:37,192` etc. | `: LengthAwarePaginator` | Offset |
| **Global `cursorPaginate`** | `ctx_search = 0` | **Not used** |
| **Global `simplePaginate`/`CursorPaginator`/`offset/skip`/`forPage`** | `0` | **Not used** |

**No custom pagination abstraction.**

---

## 4. Endpoint Classification (VERIFIED FACT → Reason)

| Endpoint | Current | Proposed | Evidence → Reason | Confidence |
|---|---|---|---|---|
| `GET /v1/general/products` **no search** | Offset `id` | **STRONG CURSOR** (opt-in) | `ProductService.php:93` `orderBy id` single, deterministic via `id` PK, large feed, `HasCache` versioned, no `total` needed for infinite scroll | Medium-High |
| `GET /v1/general/products?search=` | Offset `FIELD(id)` | **SEARCH-SPECIAL Keep Offset** | `ProductService.php:76` Scout `keys()->whereIn->orderByRaw(FIELD)` relevance order, `config/scout.php:8 driver=collection` (no Meilisearch), `Scout` has no `cursorPaginate` | High |
| `GET /v1/general/orders` | Offset `forUser->paginate` | **STRONG CURSOR** | `OrderService.php:64` no explicit order, `forUser(userId)` tenant, append-only, needs `created_at,id` deterministic | Medium (ordering verified as missing) |
| `GET /brands` admin | Offset `ordered()->paginate` | **KEEP OFFSET** | `BrandController.php:60` needs `total/last_page/page jump`, small (<10k) | High |
| `GET /categories` | Offset | **KEEP OFFSET** | Hierarchical `with('children')` parents only pagination, `order` Sortable | Medium |
| `GET /reviews` | Offset | **KEEP OFFSET** | Small, `page` jump needed admin | Low |
| `export/import` | `limit()->get()` chunk | **NOT SUITABLE** | Batch, not listing | High |
| `home` aggregates | `limit()->get()` | **NOT SUITABLE** | Cached aggregate | High |

---

## 5. Cursor Architecture (Dual Mode)

```
GET /v1/general/products?limit=15&page=2          → Offset (default, existing)
GET /v1/general/products?pagination=cursor&limit=15 → Cursor (opt-in, first page)
GET /v1/general/products?pagination=cursor&limit=15&cursor=eyJpZCI6MTAwfQ== → next
```

**Routing:** Same endpoint, same `ProductController@index` / `OrderController@index`. Controller reads `request->query('pagination') === 'cursor'` → branch to `cursorPaginate` else `paginate`. No new route. `pagination` validated via `ProductIndexRequest` `in:offset,cursor` (default `offset`). `search` present → **ignore cursor**, keep offset (validation error optional `422` document). Limit preserved `getLimit()` (products max 100, orders max 100).

---

## 6. Ordering Strategy (VERIFIED FACT vs RECOMMENDATION)

**Verified current:**
- Product fallback: `orderBy('price', orderPrice?)` then `orderBy('id', order)` at `ProductService.php:92,97` — **single** + optional `price`; no `created_at`.
- Product scout: `orderByRaw(FIELD(id, ...))` then `orderBy('id')` — relevance order.
- Order: **no explicit orderBy** at `OrderService.php:68` (relies on PK order, not deterministic for feed) — **BLOCKED: must be defined**.
- Brand/Category: user `order` param `id|name|slug|status|created_at|updated_at` single column + `ordered()` `order ASC`.

**Recommendation (cursor-supported sorts):**

| Endpoint | Sort mode | ORDER BY | Tie-breaker | Cursor predicate | Recommendation |
|---|---|---|---|---|---|
| Product | `id DESC` (default), `id ASC` | `id DESC/ASC` | `id` unique → self | `WHERE id < cursor_id` (DESC) / `id >` (ASC) | **SUPPORTED** — PK deterministic |
| Product | `price DESC, id DESC` / `price ASC, id ASC` | `price DESC, id DESC` | `id` | `WHERE (price < c_price) OR (price = c_price AND id < c_id)` | **SUPPORTED with condition** — needs composite index `(price, id)` |
| Order | `created_at DESC, id DESC` (proposed) | `created_at DESC, id DESC` | `id` | `WHERE (created_at < c_at) OR (created_at = c_at AND id < c_id)` | **RECOMMENDED** (new deterministic, not current) — must be **explicitly proposed as change** from no-order |
| Brand `order ASC, id ASC` | `order ASC, id ASC` | `order` not unique → `id` required | `WHERE (order > c_order) OR (order = c_order AND id > c_id)` | **Conditionally supported** but admin keeps offset |

**Unsupported:** arbitrary `name ASC` with cursor unless index `(name, id)` exists — fallback to offset with documented error `422 "Sorting 'name' is offset-only; use id/price for cursor"`.

**Nullable/Mutable:** `price` nullable → `COALESCE(price,0)` ; `price` mutable → row may move between pages (document eventual consistency, not snapshot).

---

## 7. Filter + Cursor Interaction

**Order (VERIFIED must be preserved):**
```
Authorization (auth:sanctum) → Visibility (active/approved/stock, forUser) → Business filters (category_id=12, brand_id=5, status, shop, channel) → Cursor boundary → ORDER BY deterministic → LIMIT
```

**Evidence:** `ProductService.php:61` `->active()` + `applyChannelHomeFilter` before `applyProductFilters` + `orderBy id`; `OrderService.php:68` `forUser(userId)` before `when(status)`. Cursor `WHERE id < ?` appended **after** all `when` filters.

**Changed filter invalidates cursor:** `GET /products?category=12&cursor=ABC` → next request `category=99&cursor=ABC` **must** be rejected or treated as first page (cursor encodes filter hash). Laravel `CursorPaginator` does **not** encode filters; backend will re-apply `category=99` + old cursor `id<100` → wrong subset but still scoped (no escape, but empty). **Recommendation:** Document cursor tied to filter set; client must drop cursor when filter changes; backend validation optional `cursorFilterHash` (not required for security, filters always re-applied).

**Security:** Cursor is opaque base64 JSON `{id, price, created_at}` — never parsed by frontend. Backend `Cursor::fromEncoded()` validates; tampered → `422`. Cursor cannot bypass `active()` or `forUser()` because `WHERE active=1` is always added before `WHERE id <`.

---

## 8. Index Strategy (Verified vs Proposed)

**Verified existing (from model:line, not migration DDL — BLOCKED for exact DDL):** Only `PRIMARY(id)` + `slug` unique (Brand `makeSlug`) + `order` Sortable; no `created_at DESC, id DESC` composite verified. `products.status`, `in_stock` have no explicit `INDEX` in model.

**Proposed (only after WHERE+ORDER analysis):**

| Table | Index (MySQL 8 `DESC` syntax) | WHERE predicates | ORDER BY | Cursor predicate | Benefit (theoretical, not benchmark) | Cost | Dependency |
|---|---|---|---|---|---|---|---|
| `products` | `INDEX products_id_desc (id DESC)` (PK already) | `active` scope (status, in_stock) | `id DESC` | `id < ?` | Index seek, no filesort for `id` sort | Negligible (PK) | None |
| `products` | `CREATE INDEX products_price_id_desc ON products (price DESC, id DESC)` | `price range` + `category_id`? | `price DESC, id DESC` | `(price,id) < (?,?)` | Covers composite sort, avoids filesort if price sort used | +Storage ~16B×rows, write +1 BTREE | Only if `price` cursor mode enabled |
| `orders` | `CREATE INDEX orders_user_created_id_desc ON orders (user_id, created_at DESC, id DESC)` | `user_id = ?` + `status = ?` | `created_at DESC, id DESC` | `(created_at,id) < (?,?)` per user | Tenant+feed seek, no scan | +Storage, write | Migration `CONCURRENTLY` |
| `orders` | `CREATE INDEX orders_created_id_desc ON orders (created_at DESC, id DESC)` (global) | — | `created_at DESC` | same | Global admin feed | +Storage | After EXPLAIN confirms |
| `brands` | `INDEX brands_order_id_asc ON brands (`order` ASC, id ASC)` | `status` | `order ASC, id ASC` | `(order,id) >` | Deterministic brand reorder | +Storage | Only if brand cursor ever |

**Do NOT blindly create `(category_id, created_at, id)` without query using that filter+sort.

**MySQL vs TiDB:** TiDB `DB_INIT_COMMAND="SET SESSION tidb_txn_mode='pessimistic'"` (from `.env.example` note) supports `FOR UPDATE` and BTREE `DESC`; same DDL, but `EXPLAIN` in TiDB may show `IndexScan` vs `TableScan`. No `DESC` difference.

---

## 9. Search Architecture

**Local (VERIFIED FACT):** `config/scout.php:8` `driver=collection`, `queue=false`, `soft_delete=false`, empty `index-settings` (`meilisearch` host `http://localhost:7700` default empty key). `Product.php:23` `Searchable` with `toSearchableArray` `name/description` translations, `shouldBeSearchable` checks `status`+`stock`. `ProductService.php:68` `buildScoutSearchQuery()` does `Product::search(term)->keys()` → `whereIn(id)` + `FIELD` ordering. **No `maxTotalHits` enforced.**

**Production assumption (BLOCKED):** `.env.example` not read for `SCOUT_DRIVER`; deployment docs not found. If `SCOUT_DRIVER=meilisearch`, engine paginates via `limit/offset` or `hitsPerPage/page` with `maxTotalHits=1000` (Meilisearch default). `Laravel Scout v10` `Engine::paginate` does not implement `cursorPaginate` (only `DatabaseEngine` does). Relevance ranking (`_rankingScore`) not monotonic → keyset cursor impossible. Changing search to SQL cursor would lose relevance.

**Recommendation:** Keep search **offset** `paginate(limit)` with `maxTotalHits` cap 1000 (document). SQL cursor only for **non-search** `buildFilteredBaseQuery` path. Meilisearch `search_after` equivalent exists in raw Meilisearch (`sort` + `offset` with `rankingScore` tie-breaker) but not via Scout; would require raw `Meilisearch\Client` bypassing Scout — **not recommended** for Phase 1.

---

## 10. API Contract (VERIFIED + Proposed)

**Current verified:**

- **Brand:** `BrandController.php:62` `BrandResource::collection($paginator)->response()->getData(true)` → `HasCache::remember` → `apiResponse(FETCH_DATA_SUCCESSFULLY,200,true, {data:[...], page, current_page, from, to, last_page, path, per_page, total, next_page_url, prev_page_url})` (custom flatten, not `meta/links`).
- **Product:** `ProductController.php:94` `new ProductCollectionMini($data)->toArray(request)` where `ProductCollectionMini` wraps `LengthAwarePaginator` via `PaginatedResourceResponse` → `data/links/meta` then `apiResponse` likely merges `data.filters, data.categories` + paginator meta. **Exact JSON not verified for product** (need `ProductCollectionMini.php` read, blocked by tool index). Behavior: `page, per_page, total` exist.
- **Order:** `OrderService.php:72` `paginate(limit)->withQueryString()` → `OrderResource::collection` (not read) → `ApiResponse`.

**Cursor proposal (coexistence):**

**Offset (default, unchanged):**
```json
{"success":true,"message":"Fetched successfully","data":{"data":[...],"current_page":2,"last_page":50,"per_page":15,"total":742,"next_page_url":"...?page=3","prev_page_url":"...?page=1"}}
```

**Cursor (opt-in `?pagination=cursor`):**
```json
{"success":true,"message":"Fetched successfully","data":{"data":[...],"per_page":15,"next_cursor":"eyJpZCI6OTB9","prev_cursor":null}}
```
- `next_cursor`/`prev_cursor` in same `data` object as `next_page_url` replacement (where `BrandController` currently puts `page` fields).
- `total`/`last_page`/`current_page` **omitted** (or `null`) for cursor — document not to expect `meta.total`.
- `prev_cursor` supported via `CursorPaginator->previousCursor()` (Laravel provides) — optional, for `infinite scroll` only `next_cursor` required.

**Backward compat:** `?page` → offset; `?pagination=cursor` → cursor; no `pagination` → offset. Old clients ignoring `next_cursor` still work. No new endpoint.

---

## 11. Frontend Integration Dependency

**VERIFIED:** `D:\work\meem` is backend-only; no `resources/js` pagination component, no `frontend/` dir ( `ctx_glob` 0 hits). **BLOCKED — separate Next.js `shop`/`admin` repo required.**

**Design for backend plan:** Backend exposes `next_cursor` opaque; frontend `shop` product feed switches from `?page=` + `meta.total` to `?pagination=cursor` + `next_cursor` infinite scroll; `admin` table keeps `?page=` (requires `total`). No frontend changes in this plan; mark as **Front-end Integration Dependency** phase.

---

## 12. Security

- `auth:sanctum` + `forUser(userId)` (`OrderService.php:68`) + `active()` (`Product.php:532`) + `HasChannelFilter` always applied before `cursorPaginate`. Cursor is `base64(JSON)` of `{id, created_at, price}` — validated via `Cursor::fromEncoded()`, tampered → `422` `Invalid cursor` (existing `ApiResponse` error envelope `success:false, message`).
- Tenant isolation: `WHERE user_id = authenticated_id` before cursor `WHERE id <` prevents `user A` cursor exposing `user B`. Shop/channel filter same.
- Filter escape: cursor re-uses current request filters, not stale; changing `category` with old cursor still scoped to new filter + old `id` — may return empty but never escapes `active`.

---

## 13. Concurrency (No Snapshot Promise)

- **Insert between requests:** `Request1` gets `100..86` + `cursor(id=86)`, new product `101` inserted → `Request2` `WHERE id <86` continues `85..71`, `101` correctly appears only on fresh `cursor=null` first page (feed top) — **no skip**, unlike offset which would shift `OFFSET 15` to `99..85` duplicate `100`.
- **Delete:** row deleted → cursor `id <86` still valid, next page `85..71` without error.
- **Mutable `price`:** if `price` is sort key, row's `price` change moves its rank — cursor `WHERE (price < c_price) OR (price=c AND id <)` may skip or duplicate that row (eventual consistency, documented, not snapshot). Immutable `id` sort avoids this.
- **Order `created_at` duplicate:** `created_at` same second for bulk import → `id` tie-breaker prevents duplicate/skip.

---

## 14. Caching (VERIFIED FACT)

- Product: `ProductController.php:56` `HasCache::remember(tag, currencyAwareKey, build)` where `tag=FrontendResource::PRODUCTS_value + '_' + type` (via `ProductStrategyResolver::supportedTypes()`), key `md5(fullUrl|currency)`, `shouldCache` returns false if `search` present (L127) → search not cached. `HasCache` uses `Cache::tags([tag])->remember` fallback `Cache::remember(tag:key)`.
- Cursor pages: **distinct cache keys** required. Current `buildCacheKey` uses `fullUrl` which includes `cursor` param, so `cursor=A` vs `cursor=B` → different `md5` → distinct entries (verified `ProductController.php:69` `$request->fullUrl()`). No collision. For orders: `CacheApiResponse` middleware not used (orders `auth` route skips `throttle:public-api` cache); no cache.
- Invalidation: `Import*Job::invalidateFrontendCaches()` already flushes `products/categories/brands` + `api_cache_version` increment (verified in `IMPORT_EXPORT_CACHE_INVALIDATION_AUDIT.md`). Cursor cache benefits same invalidation.

---

## 15. Performance Considerations (Theoretical, Not Benchmark)

**Offset:** `LIMIT 15 OFFSET N` must advance past `N` rows (scan + discard) + separate `SELECT COUNT(*)` for `total`. Cost grows with `N`.

**Cursor:** `WHERE id < :cursor ORDER BY id DESC LIMIT 15` seeks `PRIMARY(id)` BTREE to cursor key, reads next 15, no count. Cost constant.

**Recommendation:** Do **not** claim `800ms→15ms`; run `EXPLAIN ANALYZE SELECT * FROM products WHERE id < 100000 ORDER BY id DESC LIMIT 15` vs `SELECT ... LIMIT 15 OFFSET 100000` on replica, report `type: index, rows, Extra: Using index` vs `rows: 100015, Extra: Using filesort`. For orders: `WHERE user_id=5 AND (created_at < ? OR ...) ORDER BY created_at DESC, id DESC`.

---

## 16. Testing Strategy (Implementation-Ready)

**Products (non-search)**
- `first cursor` returns `per_page` sorted `id DESC` with `next_cursor` non-null
- `next cursor` returns next `per_page` with `array_intersect(prevIds, nextIds)==[]`
- `final page` `next_cursor==null` when `count < per_page`
- `empty` `data==[]` `next_cursor==null`
- `limit` bounds `1..100` (422 beyond)
- `ordering` `id ASC/DESC` + `price ASC/DESC + id` duplicate price case (insert two same price, verify no duplicate across pages)
- `filters` `category_id`, `brand_id`, `shop_id`, `price range` + cursor preserves filter
- `search+cursor` → offset fallback (assert `current_page` exists, not `next_cursor`)
- `invalid cursor` `cursor=!!!` → `422` `Invalid cursor`
- `changed filters` `category=12 cursor=ABC` → `category=99 cursor=ABC` returns filtered subset (no escape)
- `concurrent insert/delete` between pages → no duplicate/skip (insert at top, delete missing)

**Orders**
- `first/next/final/empty/limit` same
- `security` user A cursor cannot fetch user B orders (assert `user_id` mismatch `empty` or `403`)
- `duplicate created_at` bulk insert same `now()` → `created_at DESC, id DESC` no duplicate
- `status filter` `?status=pending&pagination=cursor` preserves filter

**API Contract**
- Offset `?page=1` still returns `data, current_page, total, next_page_url`
- Cursor `?pagination=cursor` returns `data, per_page, next_cursor, prev_cursor` without `total/last_page`

---

## 17. Migration Strategy

**Additive, no destructive:**
1. `2026_xx_xx_add_cursor_indexes.php` — `CREATE INDEX products_price_id ON products(price, id)` only if `price` cursor enabled; `orders_user_created_id_desc`; run `php artisan migrate` (non-blocking).
2. No `BaseRepository` change; add `cursorPaginate` branch in services only.
3. Keep `paginate` default.

---

## 18. Rollout Strategy (Additive)

```
Offset (default)
 → Add cursor opt-in behind `pagination=cursor` (feature flag CURSOR_PAGINATION_ENABLED=false default)
 → Backend tests + EXPLAIN
 → Frontend shop infinite scroll migrates to next_cursor
 → Admin stays offset
 → Monitor P95, error rate
 → Optional: default cursor for storefront after 2w (separate approval)
```

No separate endpoint.

---

## 19. Rollback Strategy

- Code: revert `pagination=cursor` branch or flag off → `?pagination=cursor` returns `422` or falls back to offset; existing `?page` unaffected.
- Indexes: remain (additive, no drop required; drop via `DROP INDEX IF EXISTS` if needed).
- No data loss.

---

## 20. File-by-File Changes (All Verified Evidence)

| File | Current Behavior | Proposed Change | Reason | Dependency | Risk | Tests |
|---|---|---|---|---|---|---|
| `database/migrations/2026_xx_xx_add_cursor_indexes.php` *(new, BLOCKED until migration read)* | No composite | Add `INDEX(price, id)` for products, `INDEX(user_id, created_at, id)` for orders (only after EXPLAIN confirms) | Enable `ORDER BY price,id` cursor seek | `products.price, orders.user_id` | Lock on large table → `CONCURRENTLY` | `EXPLAIN` |
| `app/Http/Controllers/Api/General/ProductController.php:49` | `index` → `buildStrategyResponse`/`buildFallbackResponse` with `paginate` | Branch `if ($request->query('pagination')==='cursor' && !$request->has('search'))` → call new `ProductService::cursorPaginate()` else existing | Dual mode same endpoint, search stays offset | `ProductService`, `ProductIndexRequest` validation | Search+cursor misuse → fallback to offset + 422 | Product cursor tests |
| `app/Services/General/ProductService.php:86` | `paginate()` `orderBy('id')->paginate` | Add `cursorPaginate(Request $request, string $order='desc', ?string $orderPrice=null): CursorPaginator` with `orderBy('id')` or `orderBy('price')->orderBy('id')` → `cursorPaginate(limit)` | Deterministic keyset, tie-breaker `id` | `Product::query()->active()` | Missing composite index → filesort | ordering duplicate-price test |
| `app/Services/General/OrderService.php:64` | `paginateForUser()` `->paginate` no explicit order | Add `cursorPaginateForUser()` with `orderBy('created_at','desc')->orderBy('id','desc')->cursorPaginate(limit)` | Propose deterministic feed order (new, not current) | `Order::forUser` scope | Duplicate `created_at` → needs `id` tie-breaker | duplicate timestamp test |
| `app/Http/Controllers/Api/General/OrderController.php` | Delegates to `OrderService::paginateForUser` | Same `pagination=cursor` branch to `cursorPaginateForUser` | Same endpoint dual mode | `OrderService` | Auth scope escape if before `forUser` | security test |
| `app/Http/Requests/ProductIndexRequest.php` (verify exists) | `limit 1..100`, `order asc/desc` | Add `pagination: in:offset,cursor`, `cursor: nullable|string`, `order: asc/desc` allow-list | Validation for cursor | Controller | Invalid cursor 422 | request validation test |
| `app/Http/Resources/Product/ProductCollectionMini.php` (not read, inferred) | `PaginatedResourceResponse` handles `LengthAwarePaginator` | Extend to handle `CursorPaginator` → `next_cursor`/`prev_cursor` via `cursor()->encode()` | Preserve `ApiResponse` envelope | `ResourceCollection` | Lost `total` expectation | API contract test |
| `packages/marvel/src/Traits/ApiResponse.php` | `apiResponse()` wraps `data` | Ensure `data` passes through `next_cursor` without stripping `page` fields | Envelope compat | Resource | Envelope regression | envelope test |

*All other `brands|categories|reviews` remain `paginate` — no change.*

---

## 21. Risks

- Cursor on `price` without `INDEX(price,id)` → filesort, slower than offset (mitigate: only enable with index `EXPLAIN`).
- Cursor applied to search relevance → wrong order, skipped relevance; mitigated by forcing `search` → offset.
- Frontend expects `total` on cursor → `null` breaks `last_page` UI; mitigate opt-in + docs.
- `Cursor` tampered → must return `422` not `500`.
- `categories` hierarchical `with('children')` not cursor-compatible if children paginated.

---

## 22. Acceptance Criteria

- `GET /v1/general/products?pagination=cursor&limit=15` returns `data per_page next_cursor` without `total`; next request `?cursor=` returns next 15 with no overlap; search `?search=iphone&pagination=cursor` falls back to offset `current_page`.
- `GET /v1/general/orders?pagination=cursor&limit=15` returns deterministic `created_at,id` order, `next_cursor`, `forUser` isolated.
- `GET /v1/general/products?page=2` (no `pagination`) still returns offset `current_page/total/next_page_url`.
- No duplicate/skipped across cursor pages with bulk same-timestamp inserts (test passes).
- `EXPLAIN` for cursor shows `Using index` not `Using filesort` (when index exists).

---

## 23. Open Questions (BLOCKED)

- Exact `Order` current `ORDER BY` — `OrderService` has no explicit, must read `Order` model scope or add `created_at DESC, id DESC` as **new** (requires product owner approval).
- `ProductStrategyResolver` other strategies `ORDER BY` — need `BestProduct.php` etc. reads (not verified beyond `AllProduct`).
- Migration DDL exact — need `database/migrations/*products*` read for existing indexes to avoid duplicate.
- Prod `SCOUT_DRIVER` value (`meilisearch` vs `collection`) — verify `.env` prod + `MEILISEARCH_HOST`.
- Frontend `shop` repo pagination consumption — separate repo audit required.
- `ProductCollectionMini` `paginationInformation()` override — need file read.

---

## 24. Implementation Sequence

1. Verify remaining blocked evidence (migrations, strategies, `Order` scope, `ApiResponse`, frontend repo)
2. Finalize deterministic ordering per endpoint (table above)
3. Finalize indexes (one composite per cursor sort, only with `WHERE+ORDER` evidence)
4. Add migration `add_cursor_indexes` (non-blocking)
5. Add `ProductService::cursorPaginate` + `OrderService::cursorPaginateForUser` + `ProductIndexRequest` validation
6. Branch `ProductController`/`OrderController` `pagination=cursor` vs `page`
7. Handle `CursorPaginator` in `ResourceCollection`/`ApiResponse`
8. Backend tests (ordering, filter, security, invalid cursor)
9. `EXPLAIN` runtime verification
10. `CURSOR_PAGINATION_IMPLEMENTATION_PLAN.md` final → **STOP** await approval (no frontend integration in this repo)

---

**Status:** `ARCHITECTURE_CLOSED: NO` (frontend repo + migration EXPLAIN blocked) / `IMPLEMENTATION_READY: YES` for `Products(no-search) + Orders` opt-in cursor with `id` / `price,id` / `created_at,id` tie-breakers / `RUNTIME_CERTIFIED: PENDING` (DB + Meilisearch prod).

*No source/migration/config/test/database modified in this audit.*
