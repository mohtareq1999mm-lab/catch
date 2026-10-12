# Endpoint Name

Governorates – Cities of Governorate (`GET /api/v1/governorates/{id}/cities`)

# Description

Returns one governorate by id with its cities eager-loaded in a governorate envelope.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/governorates/{id}/cities` |
| Path composition | `api/v1` prefix + `governorates/{id}/cities` custom `Route::get` in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group with route middleware `auth:sanctum` ONLY (no permission pipe — code-verified; two segments after `governorates`, so no collision with show) |
| Controller action | `Marvel\Http\Controllers\GovernorateController@cities` → `findById($governorate, ['cities'])`; miss → `NOT_FOUND` 404; hit → `apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, GovernorateResource::make($governorateModel))` |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | None. No permission middleware on this route and the constructor gates only `index/show/store/update/destroy` — any authenticated user can call it (code-verified) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en\|ar` (default `en`) |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | None (`->name()` absent — code-verified) |

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | In `['en', 'ar']` | `en`, `ar` | `en` | None | `en` | Selects message locale |
| `id` | path | integer | Required | No | `int $governorate` signature; no route constraint | Existing governorate id | None | None | `1` | Identifies the governorate |

No query parameters, body fields, or `422` contract. No endpoint test found — contracts code-traced, NOT test-verified.

## Request examples

```json
{
  "method": "GET",
  "url": "/api/v1/governorates/1/cities",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: governorate with cities — HTTP 200

Returned via `ApiResponse::apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, GovernorateResource::make(...))`. NOTE the envelope is a GOVERNORATE object (not a city array) — code-verified. Unnamed route → `routeIs('governorates.show')` is FALSE, so `name` is the locale string.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.FETCH_DATA_SUCCESSFULLY` | `en` → `"Data fetched successfully"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | Governorate with `cities` array (each `CityResource`: `id`, `governorate_id`, locale-string `name`, `created_at` — note NO `status` key on cities) | `id`, `country_id`, `name` (string), `status`, `is_fast_shipping_enabled`, `cities[]`, `created_at` |

```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": {
    "id": 1,
    "country_id": 1,
    "name": "Cairo",
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

No error-code field; every error below has Error code `None`. No validation or permission check, so no `403`/`422`.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | No valid token (code-traced; no guest test for this sub-route) | See 401 example |
| 404 | No governorate with `{id}` | `{status: integer, message: string, success: boolean}` | None | `en` → `"Not found"` (generic) | None | Unknown id (code-traced) | See 404 example |
| 429 | Exceeding `throttle:admin` | `{success: boolean, message: string, data: null}` | None | `__('message.too_many_requests')` | None | Rate limit exceeded | See 429 example |

401 example:

```json
{
  "message": "Unauthenticated",
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
