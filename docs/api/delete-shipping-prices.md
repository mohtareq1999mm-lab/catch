# Endpoint Name

Shipping Prices – Delete Shipping Price (`DELETE /api/v1/shipping-prices/{shipping_price}`)

# Description

Deletes one shipping price by id and returns 200 with no data payload.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `DELETE` |
| Full path | `/api/v1/shipping-prices/{shipping_price}` |
| Path composition | `api/v1` prefix + `shipping-prices/{shipping_price}` from `Route::apiResource('shipping-prices', ...)` in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group with route `permission:manage-shipping-prices` AND constructor gate on `destroy` (double gate, same permission) |
| Controller action | `Marvel\Http\Controllers\ShippingPriceController@destroy` → `findById($id)`; miss → `NOT_FOUND` 404; then `delete($price)` (no guards — deleting a price never cascades, code-verified) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required: `manage-shipping-prices` (route + constructor) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en\|ar` (default `en`) |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `shipping-prices.destroy` (apiResource default naming) |

NOTE (code-verified): `ShippingPriceController::bulkStatus` exists but NO `shipping-prices/change-status` route is registered in `Routes.php` — it is unreachable over HTTP. `BulkStatusRequest::resolveTable` still contains a `shipping-prices` branch (dead path). There is likewise no `shippingPrice` sub-route for `GovernorateController::shippingPrice` (dead method over HTTP).

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
  "method": "DELETE",
  "url": "/api/v1/shipping-prices/1",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: price deleted — HTTP 200

Returned via `ApiResponse::apiResponse(DELETE_DATA_SUCCESSFULLY, 200, true)` with NO data key (generic `MESSAGE.DELETE_DATA_SUCCESSFULLY`).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.DELETE_DATA_SUCCESSFULLY` | `en` → `"Data deleted successfully"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |

```json
{
  "status": 200,
  "message": "Data deleted successfully",
  "success": true
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
