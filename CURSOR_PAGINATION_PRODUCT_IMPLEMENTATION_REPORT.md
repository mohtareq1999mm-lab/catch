# CURSOR PAGINATION — PRODUCT ENDPOINT IMPLEMENTATION REPORT (PHASE 1)

**Endpoint:** `GET /v1/general/products`
**Framework:** Laravel `10.30.1`
**Production DB:** TiDB Cloud (MySQL-compatible)
**Status:** Phase 1 implemented — opt-in ID-ordered cursor pagination for non-search Product browse/feed flows.

---

## 1. Files Changed

| File | Change |
|------|--------|
| `config/cursor.php` | NEW — feature flag `cursor.enabled` (`env('CURSOR_PAGINATION_ENABLED', false)`) |
| `app/Http/Requests/ProductIndexRequest.php` | Added `pagination` validation (`offset`/`cursor`) + `withValidator()` rejecting unsupported cursor combos |
| `app/Services/General/ProductService.php` | Added `paginateCursor()`; `paginate()` branches to it for `pagination=cursor` |
| `app/Http/Controllers/Api/General/ProductController.php` | `buildFallbackResponse()` cursor branch; `buildStrategyResponse()` now recognizes `CursorPaginator` |
| `packages/marvel/src/Http/Resources/product/ProductCollectionMini.php` | `toArray()` branches for `CursorPaginator` (cursor `links` shape) |
| `resources/lang/en/validation.php` | Added `custom.pagination.*` messages |
| `resources/lang/ar/validation.php` | Added `custom.pagination.*` messages (Arabic) |
| `tests/Feature/General/ProductsEndpointTest.php` | Added 12 cursor pagination feature tests + helpers |

No changes to: `ProductStrategyResolver`, `ProductMiniResource`, `ApiResponse`, `ProductFilter`, routes, migrations, or indexes.

---

## 2. Architecture Changes

```
Request (?pagination=cursor)
  -> ProductIndexRequest
       rules(): pagination in {offset, cursor}
       withValidator(): reject if flag disabled / +search / +order_price  -> 422
  -> ProductController::index()
       type defaults to 'index' -> buildStrategyResponse()
       explicit empty type -> buildFallbackResponse()
  -> ProductService::paginate() [type=index]
       if cursor -> paginateCursor()
  -> ProductService::paginateCursor()
       buildFilteredBaseQuery() (active/visibility/filters, unchanged)
       -> orderBy('id', $order)
       -> cursorPaginate($limit)->withQueryString()
       -> enrich pricing (unchanged)
  -> ProductCollectionMini (cursor branch)
       links: { path, per_page, next_page_url, prev_page_url }
```

Keyset semantics (delegated to Laravel native `cursorPaginate`):
- `order=desc` (default): `WHERE id < :cursor_id ORDER BY id DESC LIMIT n`
- `order=asc`: `WHERE id > :cursor_id ORDER BY id ASC LIMIT n`

Cursor tokens are opaque (Laravel `Cursor`); no custom encoding, no internal structure exposed.

---

## 3. Cursor-Supported Scenarios

- `GET /v1/general/products?pagination=cursor` (default browse feed, `ORDER BY id DESC`)
- `?pagination=cursor&order=asc` / `?pagination=cursor&order=desc`
- `?pagination=cursor` + any existing filter: `brand`, `category`, `promotion`, `flash_sale`, `banner`, `tag(s)`, `slider`, `minPrice`/`maxPrice`/`price_min`/`price_max`, dimensions, `*_min/_max`, `rating`/`rating_min`/`rating_max`, `productsId`, `*Id` relation filters, dynamic attribute filters
- `?type=index&pagination=cursor`
- `?type=&pagination=cursor` (explicit fallback flow)

---

## 4. Explicitly Deferred Scenarios

| Scenario | Behavior | Reason |
|----------|----------|--------|
| `?search=...` (any flow) | offset only (unchanged) | Scout `FIELD()` relevance / SQL `LIKE` ordering not keyset-expressible |
| `?search=...&pagination=cursor` | 422 | unsupported combination |
| `?order_price=asc|desc` (offset) | offset only (unchanged) | `products.price` is NULLABLE (legitimate variable products) |
| `?order_price=...&pagination=cursor` | 422 | unsupported combination; no `whereNotNull('price')`, no `COALESCE(price,0)` |
| 9 single-page strategies | unchanged (single-page `limit()->get()`) | intentionally top-N home/widget feeds, not paginated |

The 9 single-page strategies: `best_product_sales`, `brands_product`, `new_arrivals`, `all_product_discounts`, `product_discount_today_or_low_qty`, `flash_sales_product`, `flash_sales_end_today`, `flash_sales_end_week`, `product_for_parent_category`.

---

## 5. API Contract

### Offset mode (unchanged — byte/shape compatible)

```json
{
  "status": 200, "message": "...", "success": true,
  "data": {
    "data": [ { "...product..." } ],
    "links": {
      "current_page": 1, "from": 1, "to": 15, "last_page": 5,
      "path": "/api/v1/general/products", "per_page": 15, "total": 72,
      "next_page_url": "...?page=2", "prev_page_url": null,
      "last_page_url": "...?page=5", "first_page_url": "...?page=1"
    },
    "filters": [ ], "categories": [ ]
  }
}
```

### Cursor mode (new)

```json
{
  "status": 200, "message": "...", "success": true,
  "data": {
    "data": [ { "...product..." } ],
    "links": {
      "path": "/api/v1/general/products",
      "per_page": 15,
      "next_page_url": "/api/v1/general/products?pagination=cursor&limit=15&cursor=...",
      "prev_page_url": null
    },
    "filters": [ ], "categories": [ ]
  }
}
```

- `total`, `last_page`, `current_page`, `from`, `to`, `last_page_url`, `first_page_url` are omitted in cursor mode (offset-only concepts).
- Only URL-based navigation is exposed (`next_page_url`/`prev_page_url`), not raw `next_cursor`/`prev_cursor` tokens (avoids inventing both).
- Product item shape (`ProductMiniResource`) unchanged. API envelope (`ApiResponse`) unchanged.

---

## 6. Tests Executed

```
php artisan test --filter=test_cursor_pagination tests/Feature/General/ProductsEndpointTest.php
php artisan test tests/Feature/General/ProductsEndpointTest.php
php artisan test tests/Feature/General tests/Feature/ProductFilterTest.php tests/Feature/ProductCacheTest.php
```

## 7. Test Results

| Run | Result |
|-----|--------|
| Cursor tests (filter) | 12 passed, 0 failed |
| `ProductsEndpointTest` (full file) | 68 passed, 0 failed (250 assertions) |
| `Feature/General` + `ProductFilterTest` + `ProductCacheTest` | 181 passed, 1 failed |

The single failure is `CategoriesEndpointTest > public response should expose pagination metadata` — pre-existing and unrelated to this change (it asserts a `pagination` key on the categories endpoint; this change touches no category code). Not fixed, per scope rule ("do not modify unrelated failing tests").

---

## 8. Backward Compatibility

- `?page=2` and the default (no `pagination` param) continue to use offset pagination; offset `links` shape is unchanged (verified by `test_response_exposes_links_pagination_metadata`, `test_page_parameter_navigates_pages`).
- `pagination` omitted -> offset. `Rule::in(['offset','cursor'])` means an invalid value is a 422, never a silent mode change.
- All 10 strategies return their existing response shape when `pagination` is omitted (verified by `test_all_documented_strategy_types_respond_ok`).
- No NULL-price filtering, no `COALESCE(price,0)`, no speculative DB index, no search-architecture change.

---

## 9. Cache Behavior

- Cache key is `md5(fullUrl() . '|currency:' . effectiveCode)`; the `cursor` token is part of the URL, so each cursor page is a distinct cache entry — no key change required and no collision with offset entries (offset uses `?page=`, cursor uses `?cursor=`).
- `shouldCache()` still bypasses cache when `search` is present (search stays offset, uncached).
- No cache TTL/invalidation changes.

---

## 10. Known Limitations

- No snapshot isolation — cursor guarantees contiguous, non-duplicated, non-missing pages only under a static sort key; concurrent inserts/deletes/price changes can shift boundaries (feed semantics, not a correctness/security bug).
- Client must reset the cursor when any filter / `order` / `order_price` / `type` / `limit` / `lang` header / `X-Channel` header / currency changes (no server-side fingerprint in Phase 1).
- Price cursor deferred — `order_price` stays offset because `products.price` is NULLABLE (NULL represents legitimate variable-product pricing); Phase 2 would need a custom NULL-aware keyset predicate.
- Feature flag default OFF — `pagination=cursor` returns 422 until `CURSOR_PAGINATION_ENABLED=true`.

---

## 11. Production Verification

- Source verification: DONE (code read, diff reviewed).
- Test verification: DONE (68 product tests + cursor tests pass locally).
- Production runtime verification: NOT PERFORMED — no production DB/EXPLAIN access; `CURSOR_PAGINATION_ENABLED` remains `false` by default until production verification is complete.
