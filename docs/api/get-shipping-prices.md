# Endpoint Name

Shipping Prices – List Shipping Prices (`GET /api/v1/shipping-prices`)

# Description

Returns a paginated list of shipping prices with optional status/governorate filters.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/shipping-prices` |
| Path composition | `api/v1` prefix + `shipping-prices` from `Route::apiResource('shipping-prices', ...)` in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group, plus route-level `permission:manage-shipping-prices` (single permission, NOT a pipe — code-verified; route-inventory confirms it applies to index too, unlike the country/city/governorate pipes) |
| Controller action | `Marvel\Http\Controllers\ShippingPriceController@index` → `ShippingPriceRepository::paginate(per_page, status, governorate_id)` with `governorate.country` eager-loaded (never serialized — code-verified dead eager load), ordered `id DESC`. NO constructor gate on `index`/`show` — the route middleware alone decides |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | `manage-shipping-prices` via route middleware on EVERY action including `index`/`show` (code-verified asymmetry vs countries/cities/governorates, where reads need only `view-*`) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en\|ar` (default `en`) |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `shipping-prices.index` (apiResource default naming) |

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | In `['en', 'ar']` | `en`, `ar` | `en` | None | `en` | Selects message locale |
| `per_page` | query | integer | Optional | No | Cast `(int)`, default `15` | Any positive integer | `15` | None | `15` | Page size |
| `status` | query | boolean-ish | Optional | No | Only when key present; cast `(bool)` | `0`, `1`, `true`, `false` | None (no filter) | None | `1` | Filters by active flag |
| `governorate_id` | query | integer | Optional | No | Applied only when truthy; NO `exists` check — unknown id yields empty page | Any integer | None (no filter) | None | `1` | Filters by governorate |

No search parameter (unlike countries/governorates/cities — code-verified absence). No body fields.

## Request examples

```json
{
  "method": "GET",
  "url": "/api/v1/shipping-prices?per_page=15&status=1&governorate_id=1",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: page returned — HTTP 200

Returned via `ApiResponse::apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, ShippingPriceResource::collection($prices))`. No endpoint test found — contract code-traced.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.FETCH_DATA_SUCCESSFULLY` | `en` → `"Data fetched successfully"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | array | Always present (possibly empty) | Items by `ShippingPriceResource` (NO governorate object despite the eager load) | Item: `id`, `governorate_id`, `price` (number, 2dp), `estimated_days` (int or null), `free_shipping_over` (number or null, 2dp), `status` (bool), `created_at` |

```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": [
    {
      "id": 1,
      "governorate_id": 1,
      "price": 25.5,
      "estimated_days": 3,
      "free_shipping_over": 100.0,
      "status": true,
      "created_at": "2026-01-01T00:00:00+00:00"
    }
  ]
}
```

# Errors

No error-code field; every error below has Error code `None`. No validation, so no `422`. No endpoint test found — all rows code-traced.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token (`auth:sanctum` group runs before the permission pipe) | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | No valid token | See 401 example |
| 403 | Authenticated caller lacks `manage-shipping-prices` (route pipe rejects — reads included, unlike `view-*` resources) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token without `manage-shipping-prices` | See 403 example |
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

429 example:

```json
{
  "success": false,
  "message": "Too many requests. Please try again later.",
  "data": null
}
```
