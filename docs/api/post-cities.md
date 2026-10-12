# Endpoint Name

Cities – Create City (`POST /api/v1/cities`)

# Description

Creates one city with bilingual name under a governorate and returns 201.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `POST` |
| Full path | `/api/v1/cities` |
| Path composition | `api/v1` prefix + `cities` from `Route::apiResource('cities', ...)` in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group, plus route-level ANY-of pipe middleware (constructor `create-city` gate decides) |
| Controller action | `Marvel\Http\Controllers\CityController@store` → `CityRepository::create` (`ensureGovernorateExists` throws `InvalidArgumentException('Governorate not found.')` on a race → 500 edge) + `flushTag(cities)` |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `create-city` via controller constructor (applies only to `store`); `CityStoreRequest::authorize` returns `true` |
| Required headers | `Authorization: Bearer <sanctum-token>`, `Content-Type: application/json` |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en\|ar` (default `en`) |
| Supported request formats | `application/json` |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `cities.store` (apiResource default naming) |

Middleware runs before validation: 401/403 precede 422.

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | In `['en', 'ar']` | `en`, `ar` | `en` | None | `en` | Selects message locale |
| `governorate_id` | body | integer | Required | No | `required\|integer\|exists:governorates,id` | Existing governorate id | None | None | `1` | Parent governorate |
| `name` | body | object | Required (wrapper) | No | No explicit `name: array` rule (unlike governorates) — only the locale children are validated; passing a non-object `name` yields child-rule failures, code-verified | Object with `en`+`ar` | None | None | `{"en": "Nasr City", "ar": "مدينة نصر"}` | Localized names |
| `name.en` | body | string | Required | No | `required\|string\|min:2\|max:50` + `UniqueTranslationRule::for('cities')` (vendor text **Unverified in source**) | String 2–50 chars, unique | None | None | `"Nasr City"` | English name |
| `name.ar` | body | string | Required | No | Same rule set as `name.en` | String 2–50 chars, unique | None | None | `"مدينة نصر"` | Arabic name |
| `status` | body | string/integer | Optional | Yes | `nullable\|in:1,0` — ACCEPTED by validation but NOT fillable on `City` (`$fillable` is only `governorate_id`+`name`), so it is silently discarded (code-verified trap — sending it changes nothing) | `1`, `0` | Ignored | None | `1` | Ignored field |

No path or query parameters.

## Request examples

```json
{
  "method": "POST",
  "url": "/api/v1/cities",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json",
    "lang": "en"
  },
  "body": {
    "name": { "en": "Nasr City", "ar": "مدينة نصر" },
    "governorate_id": 1
  }
}
```

# Responses

## Success: city created — HTTP 201

Returned via `ApiResponse::apiResponse(CITY_CREATED_SUCCESSFULLY, 201, true, CityResource::make($city))`. `CityResource` has only `id`, `governorate_id`, locale-string `name`, `created_at`.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `201` on success |
| `message` | string | Always present | Translated `MESSAGE.CITY_CREATED_SUCCESSFULLY` | `en` → `"City created successfully"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | Created `CityResource` | `id`, `governorate_id`, `name` (string), `created_at` |

```json
{
  "status": 201,
  "message": "City created successfully",
  "success": true,
  "data": {
    "id": 1,
    "governorate_id": 1,
    "name": "Nasr City",
    "created_at": "2026-01-01T00:00:00+00:00"
  }
}
```

# Errors

No error-code field; every error below has Error code `None`.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | No valid token (same stack as sibling city guest-401 tests) | See 401 example |
| 403 | Authenticated caller lacks `create-city` | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token without `create-city` (same constructor-gate family) | See 403 example |
| 422 | `CityStoreRequest` fails (missing `governorate_id`/`name`, unknown governorate, short name) | `{message: string, status: boolean, errors: object}` | None | Laravel default | Field → messages | Invalid body (code-traced; shared rule family with governorate create 422 tests) | See 422 example |
| 500 | `governorate_id` passes validation but the row vanishes before insert (`InvalidArgumentException('Governorate not found.')` → generic 500) | `{message: string, status: boolean}` | None | `"Internal Server Error"` (or raw message when debug is on) | None | Delete-race (code-traced edge) | See 500 example |
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
  "message": "The governorate id field is required. (and 1 more error)",
  "status": false,
  "errors": {
    "governorate_id": ["The governorate id field is required."],
    "name.en": ["The name.en field is required."]
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
