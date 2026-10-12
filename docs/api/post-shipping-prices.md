# Endpoint Name

Shipping Prices – Create Shipping Price (`POST /api/v1/shipping-prices`)

# Description

Creates one shipping price for a governorate without an existing price and returns 201.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `POST` |
| Full path | `/api/v1/shipping-prices` |
| Path composition | `api/v1` prefix + `shipping-prices` from `Route::apiResource('shipping-prices', ...)` in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group with route `permission:manage-shipping-prices` AND constructor `permission:manage-shipping-prices` on `store` (double gate, same permission — code-verified) |
| Controller action | `Marvel\Http\Controllers\ShippingPriceController@store` → `ShippingPriceRepository::create`: `ensureGovernorateExists` then `ensureGovernorateHasNoPrice` (one price per governorate — second create for the same governorate → `InvalidArgumentException('This governorate already has a shipping price.')` → 500 edge, see Errors) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required: `manage-shipping-prices` (route middleware + constructor `only: store, update, destroy, bulkStatus`); `ShippingPriceStoreRequest::authorize` returns `true` |
| Required headers | `Authorization: Bearer <sanctum-token>`, `Content-Type: application/json` |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en\|ar` (default `en`) |
| Supported request formats | `application/json` |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `shipping-prices.store` (apiResource default naming) |

Middleware runs before validation: 401/403 precede 422.

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | In `['en', 'ar']` | `en`, `ar` | `en` | None | `en` | Selects message locale |
| `governorate_id` | body | integer | Required | No | `required\|integer\|exists:governorates,id\|unique:shipping_prices,governorate_id` (DB-level uniqueness ALSO enforced by the repository guard — race → 500 edge) | Governorate id with no price yet | None | None | `1` | Parent governorate (one-to-one) |
| `price` | body | number | Required | No | `required\|numeric\|min:0` | Any number ≥ 0 | None | None | `25.5` | Delivery fee |
| `estimated_days` | body | integer | Optional | Yes | `nullable\|integer\|min:1` | Integer ≥ 1 | `null` | None | `3` | ETA days |
| `free_shipping_over` | body | number | Optional | Yes | `nullable\|numeric\|min:0` | Number ≥ 0 | `null` | None | `100` | Free-shipping threshold |
| `status` | body | boolean | Optional | Yes | `nullable\|boolean` | `true`, `false`, `0`, `1` | Model default | None | `true` | Active flag |

No path or query parameters. No endpoint test found — contracts code-traced.

## Request examples

```json
{
  "method": "POST",
  "url": "/api/v1/shipping-prices",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json",
    "lang": "en"
  },
  "body": {
    "governorate_id": 1,
    "price": 25.5,
    "estimated_days": 3,
    "free_shipping_over": 100,
    "status": true
  }
}
```

# Responses

## Success: price created — HTTP 201

Returned via `ApiResponse::apiResponse(CREATE_DATA_SUCCESSFULLY, 201, true, ShippingPriceResource::make($price))` (generic `MESSAGE.CREATE_DATA_SUCCESSFULLY`, NOT a shipping-specific key — code-verified).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `201` on success |
| `message` | string | Always present | Translated `MESSAGE.CREATE_DATA_SUCCESSFULLY` | `en` → `"Data created successfully"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | Created `ShippingPriceResource` (`price`/`free_shipping_over` rounded to 2dp) | `id`, `governorate_id`, `price`, `estimated_days`, `free_shipping_over`, `status`, `created_at` |

```json
{
  "status": 201,
  "message": "Data created successfully",
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

No error-code field; every error below has Error code `None`.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | No valid token | See 401 example |
| 403 | Authenticated caller lacks `manage-shipping-prices` | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token without the permission | See 403 example |
| 422 | `ShippingPriceStoreRequest` fails (missing `governorate_id`/`price`, unknown governorate, duplicate governorate, negative price, `estimated_days: 0`) | `{message: string, status: boolean, errors: object}` | None | Laravel default | Field → messages | Invalid body | See 422 example |
| 500 | `governorate_id` passes validation but the governorate is deleted before insert, OR a concurrent request creates the price first (`InvalidArgumentException('Governorate not found.' / 'This governorate already has a shipping price.')` → generic 500) | `{message: string, status: boolean}` | None | `"Internal Server Error"` (or raw message when debug is on) | None | Write-race (code-traced edge) | See 500 example |
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

422 example:

```json
{
  "message": "The price field is required. (and 1 more error)",
  "status": false,
  "errors": {
    "governorate_id": ["The selected governorate id is invalid."],
    "price": ["The price field is required."]
  }
}
```

500 example (race edge, debug off):

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
