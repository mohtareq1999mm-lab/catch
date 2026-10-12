# Endpoint Name

Countries – Governorates of Country (`GET /api/v1/countries/{id}/governorates`)

# Description

Returns one country by id with its governorates eager-loaded in a country envelope.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/countries/{id}/governorates` |
| Path composition | `api/v1` prefix + `countries/{id}/governorates` custom `Route::get` in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group with route middleware `auth:sanctum` ONLY (no permission pipe — code-verified; does not collide with `countries/{country}` show because segment counts differ: 2 vs 1) |
| Controller action | `Marvel\Http\Controllers\CountryController@governorates` → `findById($country, ['governorates'])`; miss → `COUNTRY_NOT_FOUND` 404; hit → `apiResponse(GOVERNORATES_FETCHED_SUCCESSFULLY, 200, true, CountryResource::make($countryModel))` |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (group + route) |
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
| `id` | path | integer | Required | No | `int $country` signature; no route constraint | Existing country id | None | None | `1` | Identifies the country |

No query parameters, body fields, or `422` contract. No endpoint test found — all contracts below are code-traced, NOT test-verified.

## Request examples

```json
{
  "method": "GET",
  "url": "/api/v1/countries/1/governorates",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: country with governorates — HTTP 200

Returned via `ApiResponse::apiResponse(GOVERNORATES_FETCHED_SUCCESSFULLY, 200, true, CountryResource::make(...))`. NOTE the envelope is a COUNTRY object (not a governorate array) — code-verified. `CountryResource` on this unnamed route: `routeIs('countries.show')` is FALSE, so `name` is the locale string.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.GOVERNORATES_FETCHED_SUCCESSFULLY` | `en` → `"Governorates fetched successfully"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | Country with `governorates` array (each `GovernorateResource` with locale-string name) | `id`, `name` (string), `phone_code`, `status`, `governorates[]`, `created_at` |

```json
{
  "status": 200,
  "message": "Governorates fetched successfully",
  "success": true,
  "data": {
    "id": 1,
    "name": "Egypt",
    "phone_code": "+20",
    "status": true,
    "governorates": [],
    "created_at": "2026-01-01T00:00:00+00:00"
  }
}
```

# Errors

No error-code field; every error below has Error code `None`. No validation or permission check, so no `403`/`422`.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | No valid token (code-traced; no guest test found for this sub-route) | See 401 example |
| 404 | No country with `{id}` | `{status: integer, message: string, success: boolean}` | None | `en` → `"Country not found"` | None | Unknown id (code-traced) | See 404 example |
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
  "message": "Country not found",
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
