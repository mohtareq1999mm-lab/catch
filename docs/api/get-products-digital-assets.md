# Endpoint Name

Digital Assets – List Assets (`GET /api/v1/products/{product}/digital-assets`)

# Description

Returns all digital assets of one product id ordered by sort order with no pagination.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/products/{product}/digital-assets` (explicit `{product}` placeholder with `whereNumber('product')`) |
| Path composition | `api/v1` prefix + nested path custom `Route::get` in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group with route-level `permission:view-products` (two segments after `products`, so no collision with `products/{product}` show) |
| Controller action | `Marvel\Http\Controllers\DigitalAssetController@index(Request $request, int $product)` → `DigitalAsset` rows for the product ordered `sort_order, id`, returned as a PLAIN collection (NOT paginated — no `limit`/`page` parameter is honored; code-verified). NOTE (code-verified): the product's own existence and `item_type` are NEVER checked here — an unknown or PHYSICAL product id yields 200 with `[]`, not 404/422 (unlike store, which enforces both) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `view-products` via route middleware AND constructor `only: [index, show]` (double gate, same permission — code-verified) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en\|ar` (default `en`) |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | None (`->name()` absent — code-verified; unlike its POST sibling, which is named) |

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | In `['en', 'ar']` | `en`, `ar` | `en` | None | `en` | Selects message locale |
| `product` | path | integer | Required | No | `whereNumber('product')` + `int $product`; NO existence/type check (unknown → empty 200) | Any integer (existing DIGITAL product for meaningful rows) | None | None | `5` | Identifies the product |

No query parameters are honored, no body, no `422` contract. No endpoint test found — contracts code-traced.

## Request examples

```json
{
  "method": "GET",
  "url": "/api/v1/products/5/digital-assets",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: assets returned — HTTP 200 (ALWAYS, even for unknown products)

Returned via `ApiResponse::apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, DigitalAssetResource::collection($assets))` (possibly empty — and `!empty([])` omits... NOTE: an empty COLLECTION is an object, and `empty()` on a resource collection object is FALSE (objects are never empty in PHP) — so `data: []` IS present even when empty, unlike plain-array actions; code-verified subtlety).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.FETCH_DATA_SUCCESSFULLY` | `en` → `"Data fetched successfully"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | array | Always present (possibly empty) | `DigitalAssetResource` items (NO secrets: file internals and LICENSE/ACCESS secrets never serialized — code-verified; `external_url` present ONLY for URL-type assets via `when`) | Item: `id`, `uuid`, `type` (`FILE`/`URL`/`LICENSE`/`ACCESS`), `original_name`, `display_name`, `mime`, `size` (int), `sort_order` (int), `external_url?`, `created_at`, `updated_at` |

```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": []
}
```

# Errors

No error-code field; every error below has Error code `None`. No validation and no lookups, so no `404`/`422` on this action.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | No valid token (code-traced) | See 401 example |
| 403 | Authenticated caller lacks `view-products` | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token without the permission (code-traced) | See 403 example |
| 404 | Non-numeric `{product}` matches NO route | `{message: string, status: boolean}` | None | `"Not Found"` (route-miss shape — the ONLY 404 on this action) | None | Non-numeric segment | See 404-constraint example |
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

404-constraint example:

```json
{
  "message": "Not Found",
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
