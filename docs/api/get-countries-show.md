# Endpoint Name

Countries – Show Country (`GET /api/v1/countries/{country}`)

# Description

Returns one country by id with governorates eager-loaded and bilingual names.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/countries/{country}` |
| Path composition | `api/v1` prefix + `countries/{country}` from `Route::apiResource('countries', ...)` in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group, plus route-level ANY-of pipe middleware (constructor `view-country` gate decides) |
| Controller action | `Marvel\Http\Controllers\CountryController@show` → `CountryRepository::findById($id, ['governorates'])`; miss → `apiResponse(COUNTRY_NOT_FOUND, 404, false)` (code-verified, translated — NOT the fixed `Resource Not Found` string) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `view-country` via controller constructor (applies to `index` and `show`) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en\|ar` (default `en`) |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `countries.show` (apiResource default naming — and NOTE: `CountryResource` checks `routeIs('countries.show')`, which is TRUE here, so `name` is the `{ar,en}` OBJECT on show) |

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | In `['en', 'ar']` to take effect | `en`, `ar` | `en` | None | `en` | Selects response message locale |
| `Accept` | header | string | Recommended | No | Content negotiation only | `application/json` | None | None | `application/json` | Requests a JSON response |
| `country` | path | integer | Required | No | No route-level constraint (`whereNumber` absent); controller signature `int $id`; non-numeric segment → route still matches, implicit int coercion/startup 500 edge **Unverified in source** | Id of an existing country | None | None | `1` | Identifies the country |

No query parameters or body fields. No `422` contract.

## Request examples

```json
{
  "method": "GET",
  "url": "/api/v1/countries/1",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: country returned — HTTP 200

Returned via `ApiResponse::apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, CountryResource::make($country))` with `governorates` loaded. Verified by `test_authenticated_user_can_show_country` (`assertOk`, `data.id`, `data.phone_code`) and `test_country_resource_structure_on_show` (keys `id, name, phone_code, status, created_at`), both `getJson`.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.FETCH_DATA_SUCCESSFULLY` | `en` → `"Data fetched successfully"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | `CountryResource` with `name` as `{ar,en}` OBJECT (code-verified show branch) | `id`, `name{ar,en}`, `phone_code`, `status` (bool), `governorates` (array of `GovernorateResource` with locale-string names), `created_at` |

```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": {
    "id": 1,
    "name": { "ar": "مصر", "en": "Egypt" },
    "phone_code": "+20",
    "status": true,
    "governorates": [],
    "created_at": "2026-01-01T00:00:00+00:00"
  }
}
```

# Errors

No error-code field; every error below has Error code `None`. No validation, so no `422`.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | No valid token (verified by `test_guest_gets_401_for_show_country`) | See 401 example |
| 403 | Authenticated caller lacks `view-country` | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token without `view-country` (same constructor gate as index, test-verified on index sibling) | See 403 example |
| 404 | No country with `{country}` (`findById` returns null → `COUNTRY_NOT_FOUND`, i.e. translated `ERROR.COUNTRY_NOT_FOUND`) | `{status: integer, message: string, success: boolean}` (ApiResponse shape — note integer `status`, unlike the Handler shape) | None | `en` → `"Country not found"` | None | Unknown id (verified by `test_show_country_returns_404_for_nonexistent_id` for `9999`) | See 404 example |
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
