# Endpoint Name

Flash Sale – Product Flash Sale Info (`GET /api/v1/product-flash-sale-info`)

# Description

Returns the raw flash-sale models attached to one product id with no permission check.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/product-flash-sale-info` |
| Path composition | `api/v1` prefix + `product-flash-sale-info` custom `Route::get` in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group with NO route-level permission middleware (route-inventory confirms group middleware only: `api, auth:sanctum, throttle:admin, lang` — code-verified) and NO constructor gate covers `getFlashSaleInfoByProductID` (gates list only `index/show/store/update/reorder/destroy`) |
| Controller action | `Marvel\Http\Controllers\FlashSaleController@getFlashSaleInfoByProductID` → `Product::find($request->id)` (plain `find` — NO exception, NO SoftDeletes bypass question answered in code; miss → `null` → empty array, still 200); hit → `$product->flash_sales` (BelongsToMany) returned DIRECTLY as `data` — NOT wrapped in `FlashSaleResource` (raw model attributes, exact keys **Unverified in source**); the `catch (MarvelException)` + `throw new MarvelException(SOMETHING_WENT_WRONG)` pair only converts `MarvelException`s (nearly dead — `find` throws nothing) |
| Authentication | Required. Sanctum bearer token via the GROUP `auth:sanctum` (the route itself declares no auth — the group supplies it; an unauthenticated call still 401s, code-verified via inventory) |
| Permission | None. No permission middleware on this route and no constructor gate covers this method — any authenticated user (even with zero flash-sale permissions) can call it (code-verified) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en\|ar` (default `en`) |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | None (`->name()` absent — code-verified) |

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token (group-level) | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | In `['en', 'ar']` | `en`, `ar` | `en` | None | `en` | Selects message locale |
| `id` | query | integer | Optional | No | NO validation whatsoever — absent/non-numeric/unknown → `Product::find` returns null → 200 with `data: []` (code-verified; there is deliberately no 404 or 422 on this endpoint) | Any product id | None → `[]` | None | `5` | Identifies the product (QUERY key `id`, not a path segment) |

No path parameters, body fields, or `422` contract. No endpoint test found — contracts code-traced.

## Request examples

```json
{
  "method": "GET",
  "url": "/api/v1/product-flash-sale-info?id=5",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: flash-sale info returned — HTTP 200

Returned via `ApiResponse::apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, $flash_sale_info)` where the payload is either the `flash_sales` relation collection (raw attributes — NOT resource-shaped) or `[]`. NOTE the ApiResponse `!empty($data)` guard: an EMPTY array omits the `data` key entirely (code-verified — a product with no sales returns `{status, message, success}` with NO `data` key).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.FETCH_DATA_SUCCESSFULLY` | `en` → `"Data fetched successfully"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | array | Conditional | Raw `flash_sales` relation models (pivot fields included per the relation definition; exact keys **Unverified in source** — no resource, no test) | Array of flash-sale model attribute objects; ABSENT (not `[]`) when the product has no sales or does not exist |

```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": []
}
```

Empty/no-product variant (no `data` key at all — code-verified `!empty` guard):

```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true
}
```

# Errors

No error-code field; every error below has Error code `None`. There is deliberately no 404 or 422 on this endpoint (misses are 200s — code-verified).

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token (group `auth:sanctum`) | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | No valid token (code-traced via inventory middleware) | See 401 example |
| 429 | Exceeding `throttle:admin` | `{success: boolean, message: string, data: null}` | None | `__('message.too_many_requests')` | None | Rate limit exceeded | See 429 example |

There is deliberately NO 403 row (no permission check exists — any authenticated caller succeeds).

401 example:

```json
{
  "message": "Unauthenticated",
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
