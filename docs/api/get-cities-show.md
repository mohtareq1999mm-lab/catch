# Endpoint Name

Cities – Show City (`GET /api/v1/cities/{city}`)

# Description

Returns one city by id with bilingual names and no status field.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/cities/{city}` |
| Path composition | `api/v1` prefix + `cities/{city}` from `Route::apiResource('cities', ...)` in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group, plus route-level ANY-of pipe middleware (constructor `view-city` gate decides) |
| Controller action | `Marvel\Http\Controllers\CityController@show` → `findById($id, ['governorate'])`; miss → `apiResponse(NOT_FOUND, 404, false)` (generic `ERROR.NOT_FOUND`); the loaded `governorate` is never serialized by `CityResource` (code-verified dead eager load) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `view-city` via controller constructor (applies to `index` and `show`) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en\|ar` (default `en`) |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `cities.show` (apiResource default naming — and NOTE: `CityResource` checks `routeIs('cities.show')`, TRUE here, so `name` is the `{ar,en}` OBJECT on show) |

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | In `['en', 'ar']` | `en`, `ar` | `en` | None | `en` | Selects message locale |
| `city` | path | integer | Required | No | No route constraint; `int $id` signature | Existing city id | None | None | `1` | Identifies the city |

No query parameters, body fields, or `422` contract.

## Request examples

```json
{
  "method": "GET",
  "url": "/api/v1/cities/1",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: city returned — HTTP 200

Returned via `ApiResponse::apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, CityResource::make($city))` with `name` as `{ar,en}` OBJECT (code-verified show branch).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.FETCH_DATA_SUCCESSFULLY` | `en` → `"Data fetched successfully"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | `CityResource` — `id`, `governorate_id`, `name{ar,en}`, `created_at` ONLY (no `status`, no governorate object) | Fixed key set |

```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": {
    "id": 1,
    "governorate_id": 1,
    "name": { "ar": "مدينة نصر", "en": "Nasr City" },
    "created_at": "2026-01-01T00:00:00+00:00"
  }
}
```

# Errors

No error-code field; every error below has Error code `None`. No validation, so no `422`.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | No valid token (same stack as sibling city guest-401 tests) | See 401 example |
| 403 | Authenticated caller lacks `view-city` | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token without `view-city` | See 403 example |
| 404 | No city with id | `{status: integer, message: string, success: boolean}` | None | `en` → `"Not found"` (generic) | None | Unknown id (code-traced; same `NOT_FOUND` pattern as governorate show 404 test) | See 404 example |
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
