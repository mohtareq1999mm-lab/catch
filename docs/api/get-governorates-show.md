# Endpoint Name

Governorates – Show Governorate (`GET /api/v1/governorates/{governorate}`)

# Description

Returns one governorate by id with country, cities, and shipping price eager-loaded.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/governorates/{governorate}` |
| Path composition | `api/v1` prefix + `governorates/{governorate}` from `Route::apiResource('governorates', ...)` in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group, plus route-level ANY-of pipe middleware (constructor `view-governorate` gate decides) |
| Controller action | `Marvel\Http\Controllers\GovernorateController@show` → `findById($id, ['country', 'cities', 'shippingPrice'])`; miss → `apiResponse(NOT_FOUND, 404, false)` (i.e. translated `ERROR.NOT_FOUND` → `en` `"Not found"`) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `view-governorate` via controller constructor (applies to `index` and `show`) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en\|ar` (default `en`) |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `governorates.show` (apiResource default naming — and NOTE: `GovernorateResource` checks `routeIs('governorates.show')`, TRUE here, so `name` is the `{ar,en}` OBJECT on show) |

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | In `['en', 'ar']` | `en`, `ar` | `en` | None | `en` | Selects message locale |
| `governorate` | path | integer | Required | No | No route constraint; `int $id` signature | Existing governorate id | None | None | `1` | Identifies the governorate |

No query parameters, body fields, or `422` contract.

## Request examples

```json
{
  "method": "GET",
  "url": "/api/v1/governorates/1",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: governorate returned — HTTP 200

Returned via `ApiResponse::apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, GovernorateResource::make($governorate))` with `country`, `cities`, `shippingPrice` loaded. Verified by `test_authenticated_user_can_show_governorate` (`data.id`, `data.country_id`, keys `id, country_id, name, status, is_fast_shipping_enabled, created_at`) using `getJson`.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.FETCH_DATA_SUCCESSFULLY` | `en` → `"Data fetched successfully"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | `GovernorateResource` with `name` as `{ar,en}` OBJECT (code-verified show branch) | `id`, `country_id`, `name{ar,en}`, `status`, `is_fast_shipping_enabled`, `country` (object), `cities` (array), `shipping_price` (object or null), `created_at` |

```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": {
    "id": 1,
    "country_id": 1,
    "name": { "ar": "القاهرة", "en": "Cairo" },
    "status": true,
    "is_fast_shipping_enabled": false,
    "country": null,
    "cities": [],
    "shipping_price": null,
    "created_at": "2026-01-01T00:00:00+00:00"
  }
}
```

# Errors

No error-code field; every error below has Error code `None`. No validation, so no `422`. NOTE the 404 message here is the generic `ERROR.NOT_FOUND` (`"Not found"`), unlike countries which use the specific `ERROR.COUNTRY_NOT_FOUND` — code-verified asymmetry.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | No valid token (verified by `test_guest_gets_401_for_show_governorate`) | See 401 example |
| 403 | Authenticated caller lacks `view-governorate` | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token without `view-governorate` (same constructor gate as index, test-verified on index sibling) | See 403 example |
| 404 | No governorate with id | `{status: integer, message: string, success: boolean}` (ApiResponse shape) | None | `en` → `"Not found"` (generic, NOT `"Governorate not found"` — no such key exists) | None | Unknown id (verified by `test_show_governorate_returns_404_for_nonexistent_id` for `9999`) | See 404 example |
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
