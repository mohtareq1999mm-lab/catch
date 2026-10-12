# Endpoint Name

Products – Show Product (`GET /api/v1/products/{product}`)

# Description

Returns one product by id with relations and related products loaded.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/products/{product}` |
| Path composition | `api/v1` prefix + `products/{product}` from `Route::apiResource('products', ...)` in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group, plus route-level ANY-of pipe middleware (constructor `view-products` gate decides). NOTE the sample/export single-segment GETs (`import/sample`, `export`) are registered BEFORE the apiResource precisely so they are not captured here (source comment verified) |
| Controller action | `Marvel\Http\Controllers\ProductController@show(Request $request, $id)` → `fetchSingleProduct`: `where('id')->firstOrFail` (id-ONLY lookup — slug NOT accepted, unlike flash-sale/tag show; code-verified asymmetry), related-products fetch (`limit` default 10) attached as `related_products` relation, then EIGHT relations loaded (`variations/categories/flash_sales/banners/sliders/brands/reviews/tags`); inner miss (`Exception` incl. `ModelNotFoundException`) → `MarvelNotFoundException(NOT_FOUND)` → outer `catch (MarvelException)` → rethrown `MarvelException(NOT_FOUND)` → escapes to `Handler` → 404... via WHICH branch? `MarvelNotFoundException`/`MarvelException` are NOT `ModelNotFoundException` (name similarity is a trap — verified distinct classes) → `Handler` `MarvelException` branch: contains `NOT_FOUND` → 404 TRANSLATED `'Not found'` (code-verified chain) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `view-products` via controller constructor (applies to `index` and `show`) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en\|ar` (default `en`) |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `products.show` (apiResource default naming — NOT `products.index`, so `name`/`description` are RAW stored values) |

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | In `['en', 'ar']` | `en`, `ar` | `en` | None | `en` | Selects message locale |
| `product` | path | mixed id | Required | No | No route constraint; `where('id', $id)` equality (soft-deleted rows miss — `Product` uses `SoftDeletes`, code-verified) | Id of a non-deleted product | None | None | `1` | Identifies the product |
| `limit` | query | integer | Optional | No | `$request->limit ?? 10`; unvalidated (related-products page size, code-verified) | Any positive integer | `10` | None | `10` | Related-products limit |

No body fields. No `422` contract.

## Request examples

Valid request showing a product (test-verified call):

```json
{
  "method": "GET",
  "url": "/api/v1/products/1",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: product returned — HTTP 200

Returned via `ApiResponse::apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, ProductResource::make($product))` with all eight relations + `related_products` loaded (all gated collections present). Verified by `test_admin_can_show_product_by_id` (`assertOk`, `data.id`, `getJson`).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.FETCH_DATA_SUCCESSFULLY` | `en` → `"Data fetched successfully"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success (verified: `apiResponse(..., 200, true, ...)` — `true` is hardcoded on this path) |
| `data` | object | Always present on success | Full `ProductResource` (raw name/description because route is NOT `products.index`; all eight relations + `related_products` loaded, so all gated collections present; `Resource` object is never `empty()`, so the `data` key is never omitted here) | Every key below |
| `data.id` | integer | Always present | Product id | e.g. `1` |
| `data.name` | string | Always present | Raw stored translations as JSON TEXT (verified: `getRawOriginal('name')` — escaped string, not an object) | e.g. `"{\"en\": \"Shirt\", \"ar\": \"قميص\"}"` |
| `data.slug` | string | Always present | Product slug | e.g. `"shirt"` |
| `data.description` | string | Always present | Raw stored translations as JSON TEXT | e.g. `"{\"en\": \"Cotton shirt\"}"` |
| `data.price` | number | Always present (null possible) | Base price, rounded | e.g. `100.0` |
| `data.current_price` | number | Always present (null possible) | Pre-tax effective price, rounded | e.g. `100.0` |
| `data.tax_enabled` | boolean | Always present | Tax switch | `true` / `false` |
| `data.tax_rate` | number \| null | Always present (nullable) | Tax percent | e.g. `15.0` or `null` |
| `data.tax` | object | Always present | `ProductTaxPresenter::describe` output | Object |
| `data.price_including_tax` | number | Always present (null possible) | Tax-inclusive value, rounded | e.g. `115.0` |
| `data.price_after_discount` | number | Always present (null possible) | Post-discount price, rounded | e.g. `90.0` |
| `data.price_after_flash_sale` | number | Always present (null possible) | Post-flash-sale price, rounded | e.g. `null` |
| `data.discount_type` | string \| null | Always present (nullable) | Discount kind | `percentage`, `fixed_rate`, `free_shipping`, or `null` |
| `data.discount_amount` | number \| null | Always present (nullable) | Discount value | e.g. `10` or `null` |
| `data.start_date` | string \| null | Always present (nullable) | Window start | Date or `null` |
| `data.end_date` | string \| null | Always present (nullable) | Window end | Date or `null` |
| `data.sku` | string \| null | Always present (nullable) | SKU | e.g. `"SH-001"` |
| `data.stock_quantity` | integer \| null | Always present (nullable) | Stock on hand | e.g. `10` |
| `data.reserved_quantity` | integer | Always present | Reserved (defaults `0`) | e.g. `0` |
| `data.available_stock` | integer \| null | Always present (nullable) | Sellable units | e.g. `10` |
| `data.quantity` | integer \| null | Always present (nullable) | Quantity | e.g. `10` |
| `data.sold_quantity` | integer | Always present | Sold (defaults `0`) | e.g. `0` |
| `data.in_stock` | integer | Always present | Stock flag (no model cast — raw tinyint `0`/`1`) | `1`, `0` |
| `data.status` | integer | Always present | Publish flag (verified: `Product` casts `status` to `integer`) | `1`, `0` |
| `data.product_type` | string | Always present | Product kind | `simple`, `variable` |
| `data.item_type` | string | Always present | Fulfillment kind | `PHYSICAL`, `DIGITAL` |
| `data.height` | string \| null | Always present (nullable) | Dimension | e.g. `"10"` or `null` |
| `data.width` | string \| null | Always present (nullable) | Dimension | e.g. `"20"` or `null` |
| `data.length` | string \| null | Always present (nullable) | Dimension | e.g. `"30"` or `null` |
| `data.weight` | string \| null | Always present (nullable) | Weight | e.g. `"1.5"` or `null` |
| `data.has_flash_sale` | boolean | Always present | Flash-sale switch (verified model boolean cast) | `true` / `false` |
| `data.has_discount` | boolean | Always present | Discount switch (verified model boolean cast) | `true` / `false` |
| `data.is_fast_shipping_available` | boolean | Always present | Fast-shipping flag | `true` / `false` |
| `data.discount_valid` | boolean | Conditionally present (only when `has_discount` truthy) | Discount window active | `true` / `false` |
| `data.tags` | array | Always present on show (relation loaded) | Tag resources | `[]` when none |
| `data.brands` | array | Always present on show (relation loaded) | Brand resources | `[]` when none |
| `data.banners` | array | Always present on show (relation loaded) | Banner resources | `[]` when none |
| `data.sliders` | array | Always present on show (relation loaded) | Slider resources | `[]` when none |
| `data.created_at` | string \| null | Always present (nullable) | Creation timestamp | ISO-8601 or `null` |
| `data.categories` | array | Always present on show (relation loaded) | `[{id, name, slug}]` | `[]` when none |
| `data.flash_sales` | array | Always present on show (relation loaded) | Flash-sale resources | `[]` when none |
| `data.reviews` | array | Always present on show (relation loaded) | Review resources | `[]` when none |
| `data.images` | array of strings | Always present (can be empty) | Media URLs | e.g. `["https://cdn.example/i.jpg"]` |
| `data.variants` | array | Always present on show (relation loaded) | Variant rows with `id/price/current_price/stock_quantity/reserved_quantity/available_stock/quantity/height/width/length/weight/attributes[{attribute_name,value}]` | `[]` for simple products |
| `data.related_products` | array | Always present on show (relation explicitly attached) | `ProductResource` collection limited by `?limit` (default 10) | `[]` when none |

```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": {
    "id": 1,
    "name": "{\"en\": \"Shirt\", \"ar\": \"قميص\"}",
    "slug": "shirt",
    "description": "{\"en\": \"Cotton shirt\"}",
    "price": 100.0,
    "current_price": 100.0,
    "tax_enabled": false,
    "tax_rate": null,
    "tax": {},
    "price_including_tax": 100.0,
    "price_after_discount": 100.0,
    "price_after_flash_sale": null,
    "discount_type": null,
    "discount_amount": null,
    "start_date": null,
    "end_date": null,
    "sku": "SH-001",
    "stock_quantity": 10,
    "reserved_quantity": 0,
    "available_stock": 10,
    "quantity": 10,
    "sold_quantity": 0,
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
    "tags": [],
    "brands": [],
    "banners": [],
    "sliders": [],
    "reviews": [],
    "images": [],
    "variants": [],
    "related_products": []
  }
}
```

# Errors

No error-code field; every error below has Error code `None`. No validation, so no `422`.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | No valid token (guest show hedges `[401, 403]` in `test_guest_cannot_show_product` — 401 is the code-traced outcome for a bare guest) | See 401 example |
| 403 | Authenticated caller lacks `view-products` | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token without `view-products` (same constructor gate as index, test-verified on the index sibling) | See 403 example |
| 404 | No product with id (double-wrapped `MarvelException(NOT_FOUND)` → `Handler` `MarvelException` branch → TRANSLATED — code-verified chain through the name-trap classes) | `{status: integer, message: string, success: boolean}` (ApiResponse shape) | None | Translated `ERROR.NOT_FOUND`: `en` → `"Not found"` | None | Unknown id (suite hedges `[404, 500]` in `test_show_nonexistent_product_returns_404` for `99999` — 404 is the code-traced outcome) | See 404 example |
| 500 | Related-products fetch failure inside `fetchSingleProduct` (any `Exception` there takes the same rethrow path... still lands 404-translated, NOT 500 — so a true 500 here needs a post-lookup failure; explicitly allowed by the hedge, code-traced as possible-but-unshaped) | `{message: string, status: boolean}` | None | `"Internal Server Error"` (or the live message when debug is on) | None | Post-lookup failure (allowed by the `[404, 500]` hedge) | See 500 example |
| 429 | Exceeding `throttle:admin` | `{success: boolean, message: string, data: null}` | None | `__('message.too_many_requests')` | None | Rate limit exceeded | See 429 example |

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

404 example:

```json
{
  "status": 404,
  "message": "Not found",
  "success": false
}
```

500 example (hedge-allowed):

```json
{
  "message": "Internal Server Error",
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
