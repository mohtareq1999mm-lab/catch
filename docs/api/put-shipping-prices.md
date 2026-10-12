# Endpoint Name

Shipping Prices – Update Shipping Price (`PUT /api/v1/shipping-prices/{shipping_price}`)

# Description

Partially or fully updates one shipping price by id and returns 200 with the resource.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `PUT` (apiResource also registers `PATCH` → same action) |
| Full path | `/api/v1/shipping-prices/{shipping_price}` |
| Path composition | `api/v1` prefix + `shipping-prices/{shipping_price}` from `Route::apiResource('shipping-prices', ...)` in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group with route `permission:manage-shipping-prices` AND constructor gate on `update` (double gate, same permission) |
| Controller action | `Marvel\Http\Controllers\ShippingPriceController@update` → `findById($id)` (no relations); miss → `NOT_FOUND` 404; then `ShippingPriceRepository::update` (re-homing to another governorate re-checks existence + one-to-one uniqueness, excluding self) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required: `manage-shipping-prices` (route + constructor); `ShippingPriceUpdateRequest::authorize` returns `true` |
| Required headers | `Authorization: Bearer <sanctum-token>`, `Content-Type: application/json` when a body is sent |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en\|ar` (default `en`) |
| Supported request formats | `application/json` |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `shipping-prices.update` (apiResource default naming) |

Middleware runs before validation: 401/403 precede 404/422. Validation precedes the miss lookup, so 422 precedes 404 when both apply. WART (code-verified): `$this->route('shipping_price')` is the SNAKE-CASE apiResource placeholder — the unique-ignore targets the correct row only because the placeholder is `{shipping_price}`, not `{id}`.

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | In `['en', 'ar']` | `en`, `ar` | `en` | None | `en` | Selects message locale |
| `shipping_price` | path | integer | Required | No | No route constraint; `int $id` signature | Existing price id | None | None | `1` | Identifies the price |
| `governorate_id` | body | integer | Optional | No | `sometimes\|integer\|exists:governorates,id` + `Rule::unique(shipping_prices, governorate_id)->ignore($shippingPriceId)` (re-homing to a governorate that already has a DIFFERENT price → 422; keeping the same governorate passes) | Governorate id with no other price | Unchanged | None | `1` | Re-homes the price |
| `price` | body | number | Optional | No | `sometimes\|numeric\|min:0` | Number ≥ 0 | Unchanged | None | `30` | Delivery fee |
| `estimated_days` | body | integer | Optional | Yes | `nullable\|integer\|min:1` | Integer ≥ 1 | Unchanged | None | `2` | ETA days |
| `free_shipping_over` | body | number | Optional | Yes | `nullable\|numeric\|min:0` | Number ≥ 0 | Unchanged | None | `150` | Free-shipping threshold |
| `status` | body | boolean | Optional | Yes | `nullable\|boolean` | `true`, `false`, `0`, `1` | Unchanged | None | `true` | Active flag |

## Request examples

```json
{
  "method": "PUT",
  "url": "/api/v1/shipping-prices/1",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json",
    "lang": "en"
  },
  "body": {
    "price": 30
  }
}
```

# Responses

## Success: price updated — HTTP 200

Returned via `ApiResponse::apiResponse(UPDATE_DATA_SUCCESSFULLY, 200, true, ShippingPriceResource::make($price))` (generic `MESSAGE.UPDATE_DATA_SUCCESSFULLY`).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.UPDATE_DATA_SUCCESSFULLY` | `en` → `"Data updated successfully"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | Refreshed `ShippingPriceResource` | `id`, `governorate_id`, `price` (2dp), `estimated_days`, `free_shipping_over`, `status`, `created_at` |

```json
{
  "status": 200,
  "message": "Data updated successfully",
  "success": true,
  "data": {
    "id": 1,
    "governorate_id": 1,
    "price": 30.0,
    "estimated_days": 3,
    "free_shipping_over": 100.0,
    "status": true,
    "created_at": "2026-01-01T00:00:00+00:00"
  }
}
```

# Errors

No error-code field; every error below has Error code `None`. No endpoint test found — contracts code-traced.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | No valid token | See 401 example |
| 403 | Authenticated caller lacks `manage-shipping-prices` | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token without the permission | See 403 example |
| 404 | No price with id (post-validation lookup miss) | `{status: integer, message: string, success: boolean}` | None | `en` → `"Not found"` (generic) | None | Unknown id with valid body | See 404 example |
| 422 | `ShippingPriceUpdateRequest` fails (unknown/re-homed-to-occupied `governorate_id`, negative `price`, `estimated_days: 0`) | `{message: string, status: boolean, errors: object}` | None | Laravel default | Field → messages | Invalid body | See 422 example |
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

422 example:

```json
{
  "message": "The governorate id has already been taken.",
  "status": false,
  "errors": {
    "governorate_id": ["The governorate id has already been taken."]
  }
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
