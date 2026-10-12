# Endpoint Name

Shipping Prices – Show Shipping Price (`GET /api/v1/shipping-prices/{shipping_price}`)

# Description

Returns one shipping price by id with rounded monetary fields.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/shipping-prices/{shipping_price}` |
| Path composition | `api/v1` prefix + `shipping-prices/{shipping_price}` from `Route::apiResource('shipping-prices', ...)` in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group with route `permission:manage-shipping-prices` (reads gated — asymmetry vs `view-*` resources) |
| Controller action | `Marvel\Http\Controllers\ShippingPriceController@show` → `findById($id, ['governorate'])`; miss → `apiResponse(NOT_FOUND, 404, false)` (generic); the loaded `governorate` is never serialized (code-verified dead eager load) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required: `manage-shipping-prices` via route middleware (no constructor gate on `show` — route middleware alone decides) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en\|ar` (default `en`) |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `shipping-prices.show` (apiResource default naming; note the placeholder is the SNAKE-CASE `{shipping_price}`, unlike `{id}` on the governorate sub-routes) |

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | In `['en', 'ar']` | `en`, `ar` | `en` | None | `en` | Selects message locale |
| `shipping_price` | path | integer | Required | No | No route constraint; `int $id` signature | Existing price id | None | None | `1` | Identifies the price |

No query parameters, body fields, or `422` contract. No endpoint test found — contracts code-traced.

## Request examples

```json
{
  "method": "GET",
  "url": "/api/v1/shipping-prices/1",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: price returned — HTTP 200

Returned via `ApiResponse::apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, ShippingPriceResource::make($price))`.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.FETCH_DATA_SUCCESSFULLY` | `en` → `"Data fetched successfully"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | `ShippingPriceResource` | `id`, `governorate_id`, `price` (2dp), `estimated_days`, `free_shipping_over` (2dp or null), `status`, `created_at` |

```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": {
    "id": 1,
    "governorate_id": 1,
    "price": 25.5,
    "estimated_days": 3,
    "free_shipping_over": 100.0,
    "status": true,
    "created_at": "2026-01-01T00:00:00+00:00"
  }
}
```

# Errors

No error-code field; every error below has Error code `None`. No validation, so no `422`.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | No valid token | See 401 example |
| 403 | Authenticated caller lacks `manage-shipping-prices` | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token without the permission | See 403 example |
| 404 | No price with id | `{status: integer, message: string, success: boolean}` | None | `en` → `"Not found"` (generic) | None | Unknown id | See 404 example |
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

429 example:

```json
{
  "success": false,
  "message": "Too many requests. Please try again later.",
  "data": null
}
```
