# General Products Filtering + Cursor Pagination — Implementation Documentation

**Endpoint:** `GET /api/v1/general/products`  
**Controller:** `app/Http/Controllers/Api/General/ProductController.php:25`  
**FormRequest:** `app/Http/Requests/ProductIndexRequest.php:8`  
**Service:** `app/Services/General/ProductService.php:26`  
**Filter:** `app/Services/General/ProductFilter.php:10`  
**Route:** `routes/api.php:80` — `Route::get('products', [ProductController::class,'index'])` inside `prefix('v1/general')->middleware(['api','throttle:public-api'])` (public, no auth)  
**Framework:** Laravel 10.30.1 + Marvel Kernel  
**Cache:** `App\Traits\HasCache` + `shouldCache()` bypasses cache when `search` present; cache key `md5(fullUrl + '|currency:' + effectiveCode)` — `app/Http/Controllers/Api/General/ProductController.php:203`  
**Channel:** `App\Traits\HasChannelFilter` — when `config('channel.enabled')` and `ChannelContext::isHome()` adds `where is_fast_shipping_available = false`

---

## 1. Request Lifecycle

```
HTTP GET /api/v1/general/products?{query}
  ↓ Route routes/api.php:80
  ↓ ProductController@index(ProductIndexRequest $request)  app/Http/Controllers/Api/General/ProductController.php:47
  ↓ ProductIndexRequest::rules() + withValidator()  app/Http/Requests/ProductIndexRequest.php:13
  ↓ $type = query('type','index')  — default 'index' routes to strategy path
  ↓ if type non-empty → buildStrategyResponse() → ProductStrategyResolver::resolve(type) → AllProduct::getProducts → ProductService::paginate() / paginateCursor()
  ↓ else → buildFallbackResponse() → buildScoutSearchQuery() or buildFilteredBaseQuery() inline
  ↓ ProductService::buildFilteredBaseQuery()  app/Services/General/ProductService.php:56
  ↓   Product::query()->active()  packages/marvel/src/Database/Models/Product.php:549  (status=1 + stock + active categories/brands + SoftDeletes + FastShippingScope)
  ↓   with(relations) withAvg(reviews) withCount(reviews)
  ↓   applyChannelHomeFilter()
  ↓   applyProductFilters() → $query->filter($request->all()) [ProductFilter] + applyDimensionFilters + rating_min/max + rating
  ↓   applyIdsFilter(productsId)
  ↓   applyRelationIdsFilters(categoriesId/brandsId/...)
  ↓   applyProductSearch(term) when search present and not Scout
  ↓   return Builder
  ↓ Deterministic sorting (orderBy id / price+id)
  ↓ cursorPaginate(limit)->withQueryString()  or  paginate(limit)
  ↓ ProductCollectionMini::toArray()  packages/marvel/src/Http/Resources/product/ProductCollectionMini.php:14
  ↓ ProductMiniResource::collection  app/Http/Resources/Product/ProductMiniResource.php:14
  ↓ apiResponse(FETCH_DATA_SUCCESSFULLY,200) + {filters, categories} facets
```

Conceptual pipeline enforced (unless documented otherwise):

```
Base Product Query
  ↓ Visibility / Active Scope
  ↓ Business Filters (ProductFilter)
  ↓ Search Filters
  ↓ Relationship Filters (productsId/relationIds)
  ↓ Price Filters
  ↓ Dimension / Rating Filters
  ↓ Deterministic Sorting
  ↓ Cursor Pagination
  ↓ Resource Transformation
```

Pagination is always applied AFTER all filters and sorting. No code rebuilds the query after filtering (`Product::query()` is only called once per branch, clones are via `clone $query` for facets).

---

## 2. Supported Query Parameters

All parameters are sent in the URL query string. Arrays are comma-separated (`brand=nike,adidas` or `productsId=1,2,3`). Values are URL-encoded by the HTTP client.

| Parameter | Type | Required | Validated | Allowed Values | Meaning | Example | Pagination Compatible | When Omitted | Invalid Value Behavior |
|-----------|------|----------|-----------|----------------|---------|---------|----------------------|--------------|------------------------|
| `type` | string | no | yes (`Rule::in(supportedTypes)`) | `index, best_product_sales, brands_product, new_arrivals, all_product_discounts, product_discount_today_or_low_qty, flash_sales_product, flash_sales_end_today, flash_sales_end_week, product_for_parent_category` | Strategy type. Default `index` (= all active products via `ProductService::paginate`). `type=` empty falls back to `buildFallbackResponse` (DB/Scout listing). | `?type=index` | yes (strategy path delegates to same paginate logic) | defaults to `index` (AllProduct) | `422` with `errors.type` |
| `pagination` | string | no | yes (`in:offset,cursor` + withValidator) | `cursor` (opt-in), `offset` or omitted = offset | Pagination mode. Cursor requires `config cursor.enabled = true`. | `?pagination=cursor` | — | offset pagination | `422` if `cursor` disabled → `errors.pagination`; `search+pagination=cursor` → `422` |
| `limit` | int | no | no (bounded in service) | 1..100, default 15, 0/negative → 15, >100 → 100 | Page size. `getLimit()` `app/Services/General/ProductService.php:859` clamps. | `?limit=20` | yes | 15 | capped / fallback, no 422 |
| `page` | int | no | no | ≥1 | Offset page number (offset mode only). Ignored in cursor mode (use `cursor`). | `?limit=15&page=2` | offset only | 1 | Laravel paginator defaults |
| `cursor` | string | no | no (opaque, Laravel CursorPaginator) | base64-encoded `{"id":N}` or `{"price":X,"id":N}` | Cursor token from `links.next_page_url` / `prev_page_url`. Invalid token → first page. | `?pagination=cursor&cursor=eyJpZCI6...` | cursor only | first page | silently returns first page (`test_cursor_pagination_invalid_token_returns_first_page`) |
| `order` | string | no | yes (`in:asc,desc`) | `asc`, `desc`, default `desc` | Sort direction for `id` (tie-breaker). When `order_price` present, `order` is tie-breaker/ fallback. Empty → `desc`. | `?order=asc` | yes (single-column cursor uses it) | `desc` | `422` `errors.order` |
| `order_price` | string | no | no (in_array strict in service) | `asc`, `desc` | Sort by `price` before `id`. Cursor: `ORDER BY price {dir}, id {dir}` (both same direction, deterministic). Offset (fallback type-empty path): `ORDER BY price {dir}, id {order}`. Default `type=index` offset path ignores it (known, test `test_order_price_does_not_sort_by_price_in_default_index_flow`). | `?pagination=cursor&order_price=asc` | yes (cursor multi-column keyset) | no price ordering | invalid → ignored (no 422), falls back to `order` |
| `search` | string | no | no | any string (trimmed) | Full-text term. Scout `Product::search(term)->keys()` when `SCOUT_DRIVER` available → `whereIn id` + `FIELD` order; else `applyProductSearch` LIKE on `name->{locale}`, `description->{locale}`, `price`, `sku`, variant dims/sku, reviews.comment, categories.name. Bypasses cache. Cannot combine with `pagination=cursor` (→422). | `?search=phone` | offset only (422 with cursor) | no search | `422` if `search+cursor` |
| `category` / `categories` | string | no | no | slug or translated name, comma-separated; `categories` alias accepted | Filter by category slug/name (ProductFilter). Expands to descendants via `expandWithDescendants` (`ProductFilter.php:43`). Unknown → `WHERE 1=0` → empty result. | `?category=electronics` or `?categories=electronics,phones` | yes | no category filter | unknown → empty data (not 422) |
| `brand` / `brands` | string | no | no | slug or translated name, comma-separated; `brands` alias accepted (frontend docs use plural) | Filter by brand (ProductFilter `resolveIds` + `whereHas brands`). Unknown → `1=0`. | `?brand=nike` or `?brands=nike,adidas` | yes | no brand filter | unknown → empty |
| `promotion` | string | no | no | slug, comma-separated | Filter whereHas `promotions` slug | `?promotion=black-friday` | yes | none | unknown → empty |
| `flash_sale` | string | no | no | slug or title translation | Filter whereHas `flash_sales` | `?flash_sale=summer-sale` | yes | none | unknown → empty |
| `banner` | string | no | no | slug or title translation | Filter whereHas `banners`. Unknown → `1=0` (fixed; previously silently ignored) | `?banner=hero` | yes | none | unknown → empty |
| `slider` | string | no | no | slug | Filter whereHas `sliders`. Unknown → `1=0` | `?slider=home` | yes | none | unknown → empty |
| `tag` / `tags` | string | no | no | slug or numeric id, comma-separated | Filter AND logic — product must have ALL specified tags (loop `whereHas` per tagId). Unknown → `1=0`. | `?tag=summer` or `?tags=summer,winter` | yes | none | unknown → empty |
| `minPrice` / `maxPrice` and aliases | numeric | no | no | numeric; accepted keys: `minPrice`, `maxPrice`, `price_min`, `price_max`, `min_price`, `max_price` (all canonical after fix). `min_price`/`max_price` are the cursor guide documented forms. | Price range on `products.price` OR `product_variants.price` (`orWhereHas variations`). Both variants use `>=` / `<=`. | `?minPrice=10&maxPrice=100` or `?min_price=10&max_price=100` or `?price_min=10&price_max=100` | yes | no price filter | non-numeric → no filter (treated as null); `min>max` → logically `WHERE price >= min AND price <= max` → likely empty |
| `rating` | numeric | no | no | 0..5 | Average rating filter: `WHERE id IN (SELECT product_id FROM reviews WHERE approved GROUP BY product_id HAVING ROUND(AVG(rating),1) >= ?)` | `?rating=4` | yes | none | invalid numeric → may return unexpected empty |
| `rating_min` / `rating_max` | numeric | no | no | 0..5 | Filters whereHas `reviews` with `rating BETWEEN min/max` (any review in range). | `?rating_min=3&rating_max=5` | yes | none | non-numeric → ignored branch |
| `productsId` | string | no | no | comma-separated numeric ids | Filter `whereIn products.id` (`ProductService.php:810`) | `?productsId=12,34` | yes | none | non-numeric ids stripped; empty → no filter |
| `categoriesId` / `brandsId` / `tagsId` / `promotionsId` / `flashSalesId` / `bannersId` / `couponsId` / `slidersId` | string | no | no | comma-separated numeric ids | Relation id filters via `whereHas` (`ProductService.php:825`). | `?categoriesId=5&brandsId=7` | yes | none | non-numeric stripped |
| `height` / `width` / `length` / `weight` | string | no | no | exact string value (ProductFilter casts `(string)(float)`) | Exact match on `products.{dim}` OR variant dim (`whereIn` + `orWhereHas variations`). | `?height=10&weight=2.5` | yes | none | non-numeric → cast to `0` string may mismatch |
| `height_min` / `height_max` / `width_min/max` / `length_min/max` / `weight_min/max` | numeric | no | no | numeric | Range filter via `REGEXP_REPLACE` numeric extraction on `products.{dim}` plus variant exact numeric (`ProductService.php:694`). | `?height_min=5&height_max=20` | yes | none | non-numeric → cast to 0 |
| `dynamic attribute slugs` | string | no | no | values: comma-separated display values or slug; slug = attribute.slug from `attributes` table | Filter by attribute: resolves `AttributeValue` ids via `value LIKE %locale:value%` or `slug`, then `whereHas variations.attributeProducts` (`ProductFilter.php:150`). Unknown value → `1=0`. | `?color=Red` (where `color` is attribute slug) | yes | none | unknown attribute slug → ignored (not in `attributeSlugs`); unknown value → `1=0` |

Visibility: public endpoint always applies `Product::active()` → `status=1`, in-stock (`in_stock` OR `stock_quantity - reserved_quantity >0`), not soft-deleted, categories/brands active, and `FastShippingScope` + `HasChannelFilter`. No `status` query param is honored (public only exposes active); admin `GET /api/v1/products` has separate `status` filter.

---

## 3. URL Examples

Use actual route `{{APP_URL}}/api/v1/general/products`.

**No filters — default offset, first page**
```http
GET /api/v1/general/products
```

**Category filter**
```http
GET /api/v1/general/products?category=electronics
GET /api/v1/general/products?category=electronics,phones
```

**Brand filter (singular canonical, plural alias accepted)**
```http
GET /api/v1/general/products?brand=nike
GET /api/v1/general/products?brands=nike,adidas
```

**Price range — all three forms accepted**
```http
GET /api/v1/general/products?price_min=10&price_max=100
GET /api/v1/general/products?minPrice=10&maxPrice=100
GET /api/v1/general/products?min_price=10&max_price=100
```

**Combined filters**
```http
GET /api/v1/general/products?category=electronics&brand=nike&price_min=100&price_max=500
GET /api/v1/general/products?category=electronics&brand=nike&min_price=10&max_price=100&tags=summer,winter&rating_min=4
```

**Search (offset only, no cursor, bypasses cache)**
```http
GET /api/v1/general/products?search=phone
```

**Cursor — first page**
```http
GET /api/v1/general/products?pagination=cursor&limit=20
GET /api/v1/general/products?pagination=cursor&limit=20&order=asc
```

**Cursor with price ordering (multi-column keyset)**
```http
GET /api/v1/general/products?pagination=cursor&order_price=asc
GET /api/v1/general/products?pagination=cursor&order_price=desc&limit=20
```

**Cursor + filters — first page**
```http
GET /api/v1/general/products?pagination=cursor&limit=20&category=electronics
GET /api/v1/general/products?pagination=cursor&order_price=asc&min_price=10&max_price=100
GET /api/v1/general/products?pagination=cursor&order_price=asc&brand=nike&category=electronics&limit=20
```

**Cursor — next page (preserve all filters)**
```http
GET /api/v1/general/products?pagination=cursor&order_price=asc&category=electronics&limit=20&cursor=eyJpZCI6MTAwfQ==
```
Always use the exact `links.next_page_url` / `links.prev_page_url` returned by the previous response — they already contain the encoded cursor plus all query string (`withQueryString()`).

**Relation-id filters**
```http
GET /api/v1/general/products?categoriesId=5&brandsId=7&productsId=12,34
```

**Tag AND + rating + dimensions**
```http
GET /api/v1/general/products?tags=summer,winter&rating=4&height_min=5&height_max=20
```

---

## 4. How the Frontend Must Send Parameters

- Query parameters belong in the URL query string (`?key=value&key2=value2`). The endpoint is `GET`; do not send filters in request body.
- Values must be URL-encoded (`&`, `=`, spaces encoded). Comma `,` between multiple values is not encoded but accepted either way.
- Arrays are comma-separated: `?brand=nike,adidas` or `?productsId=1,2,3`. Repeated `?brand=nike&brand=adidas` is not handled; use comma form.
- When requesting the next cursor page, the frontend **must not** send only `?cursor=...`. The request must preserve the original filters, or simply use the `next_page_url` verbatim. The backend's `withQueryString()` guarantees `next_page_url` already contains all active filters plus the new cursor, so the correct flow is `fetch(next_page_url)`.
- If you construct URLs manually, preserve every filter that was used for the first page: `?category=electronics&price_min=10&price_max=100&pagination=cursor&cursor=TOKEN` — omitting a filter silently changes the result set.
- Cursors are opaque base64 tokens. Do not decode, construct, or cache them long-term.

---

## 5. Cursor Frontend Flow

```
1. User selects filters (category, brand, price range, etc.)
2. Frontend builds query string: ?category=electronics&price_min=10&price_max=500&pagination=cursor&limit=20[&order_price=asc]
3. Frontend GET /api/v1/general/products?{query}  (first page, no cursor)
4. Backend returns {data: {data: [...], links: {next_page_url, prev_page_url}}} + {filters, categories} facets
5. Frontend stores next_page_url for this query state
6. User requests more (infinite scroll / Load more / Next)
7. Frontend GET next_page_url (contains SAME filters + cursor)
8. Backend decodes cursor, applies WHERE (price,id) > cursor, returns next page
9. Frontend appends products
10. Repeat until next_page_url is null
```

**Cursor reset rules** — discard stored `next_page_url`/`prev_page_url` and start from step 3 whenever:

- Any filter value changes (category, brand, tag, price range, rating, dimension, attribute, productsId, relationIds)
- Sort changes (`order`, `order_price`)
- Search term changes
- `limit` (page size) changes
- `type` changes

Do not reuse a cursor generated for one filter/sort combination against a materially different query — Laravel's cursor encodes its ordering columns and filter context is not embedded; reuse returns incorrect or empty pages.

---

## 6. API Response Documentation

### Offset response (default, `pagination` omitted or `pagination=offset`)

`ProductCollectionMini::toArray()` when `LengthAwarePaginator` (`packages/marvel/src/Http/Resources/product/ProductCollectionMini.php:23`)

```json
{
  "success": true,
  "message": "Products retrieved successfully",
  "data": {
    "data": [
      {
        "id": 12,
        "name": "Wireless Headphones",
        "slug": "wireless-headphones-abc123",
        "price": 99.99,
        "has_variants": false,
        "item_type": "PHYSICAL",
        "current_price": 99.99,
        "currency": "USD",
        "quantity": 50,
        "in_stock": true,
        "discount_active": false,
        "flash_sale_active": false,
        "is_fast_shipping_available": false,
        "ratings": 4.5,
        "tags": [{"id":1,"name":"summer","slug":"summer"}],
        "image": {"thumbnail": "https://cdn/.../thumb.jpg", "original": ["https://cdn/.../2.jpg"]}
      }
    ],
    "links": {
      "current_page": 1,
      "from": 1,
      "to": 15,
      "last_page": 3,
      "path": "https://api.example.com/api/v1/general/products",
      "per_page": 15,
      "total": 42,
      "next_page_url": "https://api.example.com/api/v1/general/products?page=2&limit=15",
      "prev_page_url": null,
      "last_page_url": "https://api.example.com/api/v1/general/products?page=3&limit=15",
      "first_page_url": "https://api.example.com/api/v1/general/products?page=1&limit=15"
    },
    "filters": [ {"display":"Brand","key":"brand","data":["Nike","Adidas"]}, {"display":"Category","key":"category","data":["Electronics"]} ],
    "categories": [ {"id":2,"name":"Electronics","slug":"electronics","image":{"desktop":"...","mobile":"..."}} ]
  }
}
```

### Cursor response (`pagination=cursor`)

`ProductCollectionMini::toArray()` when `CursorPaginator` (`packages/marvel/src/Http/Resources/product/ProductCollectionMini.php:15`)

```json
{
  "success": true,
  "message": "Products retrieved successfully",
  "data": {
    "data": [ { "id": 7, "name": "...", "slug": "...", "price": 10.0, "current_price": 10.0, "currency": "USD", "quantity": 5, "in_stock": true, "discount_active": false, "flash_sale_active": false, "is_fast_shipping_available": false, "ratings": 0, "tags": [], "image": {"thumbnail":"...","original":[]} } ],
    "links": {
      "path": "https://api.example.com/api/v1/general/products",
      "per_page": 20,
      "next_page_url": "https://api.example.com/api/v1/general/products?pagination=cursor&limit=20&cursor=eyJpZCI6N30%3D",
      "prev_page_url": null
    },
    "filters": [ "...same shape..." ],
    "categories": [ "..." ]
  }
}
```

Cursor ownership: backend generates cursor via `CursorPaginator->nextCursor()->encode()` (`packages/marvel/src/Http/Resources/product/ProductCollectionMini.php:15`). Frontend never generates. First request without `cursor` returns first page + `next_cursor`/`next_page_url`; subsequent request sends `cursor=<next_cursor>` with same filters. `next_cursor` equals `cursor` param in `links.next_page_url` / `next_page_url` (both from same paginator, `withQueryString()` preserves filters). When no more pages, `next_cursor: null` and `next_page_url: null`; `prev_cursor` null on first page.

Example `next_page_url`:
```
/api/v1/general/products?pagination=cursor&category=care&brand=nike&min_price=10&max_price=100&limit=15&cursor=BACKEND_GENERATED_CURSOR
```
Cursor response (after `26d3a27` + `ProductCollectionMini` next_cursor exposure):
```json
{
  "success": true,
  "message": "Products retrieved successfully",
  "data": {
    "data": [{ "id": 7, "name": "...", "price": 10.0, "current_price": 10.0, "currency": "USD" }],
    "links": { "path": ".../general/products", "per_page": 20, "next_page_url": "...&cursor=eyJpZCI6...", "prev_page_url": null },
    "next_cursor": "eyJpZCI6...",  // == cursor in next_page_url, from paginator->nextCursor()->encode()
    "prev_cursor": null,
    "next_page_url": "...&cursor=eyJpZCI6...",  // top-level duplicate for spec compatibility
    "prev_page_url": null,
    "filters": [], "categories": []
  }
}
```
Contract preserved: top-level `success`, `message`, `data` wrapper unchanged. Cursor mode omits `total`, `last_page`, `current_page`, `from`, `to`, `last_page_url`, `first_page_url` by design — only `path`, `per_page`, `next_page_url`, `prev_page_url` are present, plus explicit `next_cursor`/`prev_cursor` from backend paginator.

---

## 7. Error Handling

| Condition | HTTP | Body | Notes |
|-----------|------|------|-------|
| Invalid `type` | 422 | `{"success":false,"message":"Validation failed","errors":{"type":["The selected type is invalid."]}}` | `ProductIndexRequest` `Rule::in(supportedTypes)` |
| Invalid `order` | 422 | `{"errors":{"order":[...]}}` | `Rule::in(['asc','desc'])` |
| Cursor disabled (`config cursor.enabled=false`) + `pagination=cursor` | 422 | `{"errors":{"pagination":["Cursor pagination is disabled."]}}` (`resources/lang/en/validation.php: custom.pagination.disabled`) | `ProductIndexRequest::withValidator` |
| `search` + `pagination=cursor` | 422 | `{"errors":{"pagination":["Cursor pagination does not support search queries."]}}` | `withValidator` — search must use offset |
| Invalid `order_price` | 200 (not 422) | — | Silently ignored, falls back to `order` (`in_array` strict check). Guide's "invalid returns 422" is aspirational; current contract preserves backward compatibility. |
| Unknown filter slug (brand/category/tag/banner/slider etc.) | 200 | `{"data":{"data":[]}}` | Filter resolves no ids → `WHERE 1=0` or `whereHas` with no match → empty collection (not error) |
| Invalid cursor token | 200 | first page data | Laravel CursorPaginator returns first page on decode failure (`test_cursor_pagination_invalid_token_returns_first_page`) |
| Rate limited | 429 | `Too Many Requests` | `throttle:public-api` |
| Unsupported param name (e.g. typo `categorie=`) | 200 | unfiltered data | Silently ignored (not validated); treat as absent — no error |
| `min_price > max_price` (price range) | 200 | `data: []` likely | `WHERE price >= min AND price <= max` yields empty when min>max |
| `filters returning zero rows` | 200 | `data: [], links: {next_page_url:null, prev_page_url:null}` | No error; `prev/next` null |
| Empty `search` (`search=`) | 200 | same as no search | Trimmed term `''` skips search branch |

---

## 8. Architectural Ownership

- Filtering belongs in `ProductService::buildFilteredBaseQuery` + `ProductFilter::apply` (repository/query-builder layer), not Controller/Resource/FormRequest. Controller stays thin (`receive Request → call Service → return Resource`). This was already the canonical split; the fix preserves it.
- Pricing centralization preserved — no pricing logic in filter/pagination.
- Marvel kernel (`packages/marvel/src/Database/Models/Product.php`) scope `active()` remains authoritative for visibility.
- No second filtering system introduced — alias normalization reuses the single `ProductFilter` pipeline.
- RabbitMQ not reintroduced; Scout+Meilisearch optional, fallback LIKE path retained.

---

## 9. Related Documentation

- `docs/architecture/runtime-pricing-architecture.md` — pricing ownership
- `api-desc/product/frontend.md` — frontend integration (query keys table — see note: `brands` alias now accepted but canonical remains `brand`)
- `CURSOR_PAGINATION_PRICE_ORDERING_API_GUIDE.md` / `API_CURSOR_PAGINATION_PRICE.md` — cursor price-ordering guide (now corrected: `min_price`/`max_price` accepted; prefer `price_min`/`price_max` for canonical)
- `PRODUCT_STATUS_AND_CURSOR_FINAL_CLOSURE_REPORT.md` — prior status/cursor audit
