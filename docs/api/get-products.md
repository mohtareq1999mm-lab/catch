# Endpoint Name

Products – List Products (`GET /api/v1/products`)

# Description

Returns a paginated, cached product collection with grouped search and safe sorting.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/products` |
| Path composition | `api/v1` prefix from `packages/marvel/src/Providers/RestAPIServiceProvider.php` + `products` from `Route::apiResource('products', ...)` in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group, plus route-level ANY-of pipe middleware (constructor `view-products` gate decides; note BOTH `view-products` and `view-product` exist in the pipe) |
| Controller action | `Marvel\Http\Controllers\ProductController@index` — NO `FormRequest`: `limit` defaults 15 unvalidated; `search` (trimmed) groups locale name/description/sku/variant-sku LIKE; `status`/`item_type` (allowlisted)/category/banner/flash_sale/slider slug filters; sorting is SAFE (unlike promotions): legacy `sort` forces `created_at` asc/desc, else allowlisted `orderBy` (`created_at/updated_at/name/price/sold_quantity/sku/id`, `name` sorts the locale JSON path) with any other direction degrading to `desc` — no 409 edge exists here; relations `variations/categories/flash_sales` eager-loaded; cached per full URL; `ProductCollection` (NOT the banner envelope — the collection resource serializes itself) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `view-products` via controller constructor (applies to `index` and `show`); the route pipe lists five permissions but the constructor gate is decisive |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en\|ar` (default `en`; selects search locale and `name`/`description` locale strings) |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute per authenticated user id (or per IP for guests), per `App\Providers\AppServiceProvider::boot` |
| Route name | `products.index` (apiResource default naming — and NOTE: `ProductResource` checks `routeIs('products.index')`, TRUE here, so `name` AND `description` are locale STRINGS on index) |

## Request inputs

No validation runs — NOTHING here 422s (unknown slugs yield empty pages; bad sort keys degrade silently).

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token enforced by `auth:sanctum` | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | Must be in `['en', 'ar']` to take effect | `en`, `ar` | `en` | None | `en` | Selects message locale, search locale, and field locales |
| `Accept` | header | string | Recommended | No | Content negotiation only | `application/json` | None | None | `application/json` | Requests a JSON response |
| `limit` | query | integer | Optional | No | Falsy → `15`; unvalidated | Any positive integer | `15` | None | `2` | Page size (verified: `limit=2` → 2 rows) |
| `search` | query | string | Optional | No | Trimmed; blank skips; grouped LIKE over locale name/description, sku, variant sku | Any string | None (no filter) | None | `shirt` | Free-text filter |
| `sort` | query | string | Optional | No | Non-blank FORCES `created_at` ordering; `asc` (case-insensitive) → asc, ANYTHING else → desc (takes precedence over `orderBy`/`orderDir` when present — code-verified) | Any string | None | None | `asc` | Legacy sort switch |
| `orderBy` | query | string | Optional | No | Allowlisted (`created_at/updated_at/name/price/sold_quantity/sku/id`); off-list → silent `created_at DESC` fallback (no 409 — code-verified difference from promotions) | Allowlisted columns | `created_at` | Ignored when `sort` is non-blank | `price` | Sort column |
| `orderDir` | query | string | Optional | No | `asc` (case-insensitive) → asc, anything else → desc | `asc`, `desc` | `desc` | Only with allowlisted `orderBy` | `asc` | Sort direction |
| `status` | query | mixed | Optional | No | Applied when the key is present and non-null (`has` + `!== null`); NO boolean parsing — raw equality match | Any status value | None (no filter) | None | `1` | Status filter |
| `item_type` | query | string | Optional | No | Applied only when `filled` AND in `['PHYSICAL', 'DIGITAL']` (code-verified `ItemType` values); anything else silently ignored | `PHYSICAL`, `DIGITAL` | None (no filter) | None | `PHYSICAL` | Item-type filter |
| `category` | query | string | Optional | No | Applied when the key is present (`has` — even empty string, which matches nothing); trimmed slug equality inside `whereHas('categories')` | Category slug | None (no filter) | None | `men` | Category-slug filter |
| `banner` | query | string | Optional | No | Applied when the key is present (`has`); trimmed slug equality inside `whereHas('banners')` | Banner slug | None (no filter) | None | `summer` | Banner-slug filter |
| `flash_sale` | query | string | Optional | No | Applied when the key is present (`has`); trimmed slug equality inside `whereHas('flash_sales')` | Flash-sale slug | None (no filter) | None | `black-friday` | Flash-sale-slug filter |
| `slider` | query | string | Optional | No | Applied when the key is present (`has`); trimmed slug equality inside `whereHas('sliders')` | Slider slug | None (no filter) | None | `home-hero` | Slider-slug filter |
| `page` | query | integer | Optional | No | Standard Laravel paginator page; unvalidated | Any positive integer | `1` | None | `2` | Page number |

## Request examples

```json
{
  "method": "GET",
  "url": "/api/v1/products?limit=2",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: page returned — HTTP 200

Returned via `ApiResponse::apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, new ProductCollection($products))`. Verified by `test_admin_can_list_products` (`assertOk`) and `test_list_products_paginates` (`limit=2` → 2 rows, `getJson`).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.FETCH_DATA_SUCCESSFULLY` | `en` → `"Data fetched successfully"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success (verified: `apiResponse(..., 200, true, ...)` — `true` is hardcoded on this path) |
| `data` | object | Always present on success | `ProductCollection` serialization (object is never `empty()`, so the `data` key is never omitted here) | Contains `data` + `links` below |
| `data.data` | array | Always present (can be empty) | Page items (`ProductResource` collection) | Empty array when no products match |
| `data.data[].id` | integer | Always present | Product id | e.g. `1` |
| `data.data[].name` | string | Always present | Locale string for active locale (this route IS `products.index`) | e.g. `"Shirt"` |
| `data.data[].slug` | string | Always present | Product slug | e.g. `"shirt"` |
| `data.data[].description` | string | Always present | Locale string for active locale (this route IS `products.index`) | e.g. `"Cotton shirt"` |
| `data.data[].price` | number | Always present (null possible) | Base price, rounded to 2 decimals | e.g. `100.0` |
| `data.data[].current_price` | number | Always present (null possible) | Pre-tax effective price, rounded | e.g. `90.0` |
| `data.data[].tax_enabled` | boolean | Always present | Tax switch | `true` / `false` |
| `data.data[].tax_rate` | number \| null | Always present (nullable) | Tax percent | e.g. `15.0` or `null` |
| `data.data[].tax` | object | Always present | Tax description from `ProductTaxPresenter::describe` | Object (rate/amount breakdown) |
| `data.data[].price_including_tax` | number | Always present (null possible) | Tax-inclusive value, rounded | e.g. `103.5` |
| `data.data[].price_after_discount` | number | Always present (null possible) | Post-discount price, rounded | e.g. `90.0` |
| `data.data[].price_after_flash_sale` | number | Always present (null possible) | Post-flash-sale price, rounded | e.g. `85.0` |
| `data.data[].discount_type` | string \| null | Always present (nullable) | Discount kind | `percentage`, `fixed_rate`, `free_shipping`, or `null` |
| `data.data[].discount_amount` | number \| null | Always present (nullable) | Discount value | e.g. `10` or `null` |
| `data.data[].start_date` | string \| null | Always present (nullable) | Discount/flash window start | ISO date or `null` |
| `data.data[].end_date` | string \| null | Always present (nullable) | Discount/flash window end | ISO date or `null` |
| `data.data[].sku` | string \| null | Always present (nullable) | Stock-keeping unit | e.g. `"SH-001"` |
| `data.data[].stock_quantity` | integer \| null | Always present (nullable) | Stock on hand | e.g. `50` |
| `data.data[].reserved_quantity` | integer | Always present | Reserved units (defaults `0`) | e.g. `2` |
| `data.data[].available_stock` | integer \| null | Always present (nullable) | Sellable units | e.g. `48` |
| `data.data[].quantity` | integer \| null | Always present (nullable) | Requested/pack quantity | e.g. `10` |
| `data.data[].sold_quantity` | integer | Always present | Units sold (defaults `0`) | e.g. `5` |
| `data.data[].in_stock` | integer | Always present | Stock flag (no model cast — raw tinyint `0`/`1`) | `1`, `0` |
| `data.data[].status` | integer | Always present | Publish flag (verified: `Product` casts `status` to `integer`) | `1`, `0` |
| `data.data[].product_type` | string | Always present | Product kind | `simple`, `variable` |
| `data.data[].item_type` | string | Always present | Fulfillment kind | `PHYSICAL`, `DIGITAL` |
| `data.data[].height` | string \| null | Always present (nullable) | Dimension (pre-cast to string) | e.g. `"10"` |
| `data.data[].width` | string \| null | Always present (nullable) | Dimension | e.g. `"20"` |
| `data.data[].length` | string \| null | Always present (nullable) | Dimension | e.g. `"30"` |
| `data.data[].weight` | string \| null | Always present (nullable) | Weight | e.g. `"1.5"` |
| `data.data[].has_flash_sale` | boolean | Always present | Flash-sale switch (verified model boolean cast) | `true` / `false` |
| `data.data[].has_discount` | boolean | Always present | Discount switch (verified model boolean cast) | `true` / `false` |
| `data.data[].is_fast_shipping_available` | boolean | Always present | Fast-shipping flag | `true` / `false` |
| `data.data[].discount_valid` | boolean | Conditionally present (only when `has_discount` is truthy) | Whether the discount window is active | `true` / `false` |
| `data.data[].tags` | array | Present when `tags` relation loaded (not loaded on index → omitted) | Tag collection | `[]` when loaded-empty |
| `data.data[].brands` | array | Present when `brands` loaded (not loaded on index → omitted) | Brand collection | `[]` when loaded-empty |
| `data.data[].banners` | array | Present when `banners` loaded (not loaded on index → omitted) | Banner collection | `[]` when loaded-empty |
| `data.data[].sliders` | array | Present when `sliders` loaded (not loaded on index → omitted) | Slider collection | `[]` when loaded-empty |
| `data.data[].created_at` | string \| null | Always present (nullable) | Creation timestamp | ISO-8601 or `null` |
| `data.data[].categories` | array | Always present on index (relation eager-loaded) | `[{id, name, slug}]` per category | Empty array when uncategorized |
| `data.data[].flash_sales` | array | Always present on index (relation eager-loaded) | Flash-sale resources | Empty array when none |
| `data.data[].reviews` | array | Present when `reviews` loaded (not loaded on index → omitted) | Review resources | `[]` when loaded-empty |
| `data.data[].images` | array of strings | Always present (can be empty) | Media URLs for `products` collection | e.g. `["https://cdn.example/i.jpg"]` |
| `data.data[].variants` | array | Always present on index (relation eager-loaded; empty for simple products) | Variant rows | See variant keys below |
| `data.data[].variants[].id` | integer | Always present per variant | Variant id | e.g. `11` |
| `data.data[].variants[].price` | number | Always present (null possible) | Variant price, rounded | e.g. `100.0` |
| `data.data[].variants[].current_price` | number | Always present (null possible) | Variant effective price, rounded | e.g. `90.0` |
| `data.data[].variants[].stock_quantity` | integer \| null | Always present (nullable) | Variant stock | e.g. `20` |
| `data.data[].variants[].reserved_quantity` | integer | Always present | Variant reserved (defaults `0`) | e.g. `1` |
| `data.data[].variants[].available_stock` | integer \| null | Always present (nullable) | Variant sellable | e.g. `19` |
| `data.data[].variants[].quantity` | integer \| null | Always present (nullable) | Variant quantity | e.g. `5` |
| `data.data[].variants[].height` | string \| null | Always present (nullable) | Variant dimension | e.g. `"5"` |
| `data.data[].variants[].width` | string \| null | Always present (nullable) | Variant dimension | e.g. `"5"` |
| `data.data[].variants[].length` | string \| null | Always present (nullable) | Variant dimension | e.g. `"5"` |
| `data.data[].variants[].weight` | string \| null | Always present (nullable) | Variant weight | e.g. `"0.5"` |
| `data.data[].variants[].attributes` | array | Always present per variant | `[{attribute_name, value}]` | Empty array when none |
| `data.links` | object | Always present | Laravel paginator links (code-verified `ProductCollection::toArray`) | All keys below |
| `data.links.current_page` | integer | Always present | Current page number | e.g. `1` |
| `data.links.from` | integer \| null | Always present (nullable) | First item index (`null` on empty page) | e.g. `1` |
| `data.links.to` | integer \| null | Always present (nullable) | Last item index (`null` on empty page) | e.g. `2` |
| `data.links.last_page` | integer | Always present | Total pages | e.g. `5` |
| `data.links.path` | string | Always present | Request URL without query | e.g. `"https://api.example/api/v1/products"` |
| `data.links.per_page` | integer | Always present | Page size (`limit`) | e.g. `2` |
| `data.links.total` | integer | Always present | Total matching rows | e.g. `10` |
| `data.links.next_page_url` | string \| null | Always present (nullable) | Next page URL | URL or `null` on last page |
| `data.links.prev_page_url` | string \| null | Always present (nullable) | Previous page URL | URL or `null` on first page |
| `data.links.last_page_url` | string | Always present | Last page URL | URL |
| `data.links.first_page_url` | string | Always present | First page URL | URL |

```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": {
    "data": [
      {
        "id": 1,
        "name": "Shirt",
        "slug": "shirt",
        "description": "Cotton shirt",
        "price": 100.0,
        "current_price": 90.0,
        "tax_enabled": false,
        "tax_rate": null,
        "tax": {},
        "price_including_tax": 90.0,
        "price_after_discount": 90.0,
        "price_after_flash_sale": null,
        "discount_type": null,
        "discount_amount": null,
        "start_date": null,
        "end_date": null,
        "sku": "SH-001",
        "stock_quantity": 50,
        "reserved_quantity": 0,
        "available_stock": 50,
        "quantity": 10,
        "sold_quantity": 5,
        "in_stock": 1,
        "status": 1,
        "product_type": "simple",
        "item_type": "PHYSICAL",
        "height": null,
        "width": null,
        "length": null,
        "weight": null,
        "has_flash_sale": 0,
        "has_discount": 0,
        "is_fast_shipping_available": false,
        "created_at": "2026-01-01T00:00:00+00:00",
        "categories": [{ "id": 2, "name": "Men", "slug": "men" }],
        "flash_sales": [],
        "images": [],
        "variants": []
      }
    ],
    "links": {
      "current_page": 1,
      "from": 1,
      "to": 1,
      "last_page": 1,
      "path": "https://api.example/api/v1/products",
      "per_page": 15,
      "total": 1,
      "next_page_url": null,
      "prev_page_url": null,
      "last_page_url": "https://api.example/api/v1/products?page=1",
      "first_page_url": "https://api.example/api/v1/products?page=1"
    }
  }
}
```

# Errors

No error-code field; every error below has Error code `None`. No validation, so no `422`.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing, invalid, revoked, or expired Sanctum token | `{message: string, status: boolean}` | None | `"Unauthenticated"` via `AuthenticationException` branch | None | Any request without a valid token (guest list hedges `[401, 403]` in `test_guest_cannot_list_products` — 401 is the code-traced outcome for a bare guest) | See 401 example |
| 403 | Authenticated caller lacks `view-products` (constructor gate rejects after the route pipe) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS`: `en` → `"User does not have the required permissions."` | None | User with no permissions (verified by `test_no_perm_user_cannot_list` → 403) | See 403 example |
| 429 | Exceeding `throttle:admin` (400/min per user id or IP) | `{success: boolean, message: string, data: null}` | None | `__('message.too_many_requests')` | None | Rate limit exceeded for the caller key | See 429 example |

401 example:

```json
{
  "message": "Unauthenticated",
  "status": false
}
```

403 example:

```json
{
  "message": "User does not have the required permissions.",
  "status": false
}
```

429 example:

```json
{
  "success": false,
  "message": "Too many requests. Please try again later.",
  "data": null
}
```
