# Endpoint Name

Countries – Create Country (`POST /api/v1/countries`)

# Description

Creates one country with bilingual name and returns 201 with the locale-string resource.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `POST` |
| Full path | `/api/v1/countries` |
| Path composition | `api/v1` prefix + `countries` from `Route::apiResource('countries', ...)` in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group, plus route-level ANY-of pipe middleware (constructor `create-country` gate decides) |
| Controller action | `Marvel\Http\Controllers\CountryController@store` → `CountryRepository::create($request->validated())`, then `flushTag(countries)` |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `create-country` via controller constructor (applies only to `store`); `CountryStoreRequest::authorize` returns `true` |
| Required headers | `Authorization: Bearer <sanctum-token>`, `Content-Type: application/json` (body is read) |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en\|ar` (default `en`) |
| Supported request formats | `application/json` |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `countries.store` (apiResource default naming) |

Middleware runs before validation: an unauthenticated or unauthorized call fails with 401/403 without reaching the `FormRequest`.

## Request inputs

`CountryStoreRequest` validation runs before the method body (`failedValidation` rethrows → 422). Persisted fields are `name`, `phone_code`, `status`; any other body fields are ignored (mass-assignment guard verified by `test_country_mass_assignment_protection` — `id: 99999` ignored, 201 still returned).

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | In `['en', 'ar']` to take effect | `en`, `ar` | `en` | None | `en` | Selects response message locale |
| `name` | body | object | Required | No | Must be present as array; each locale rule below applies | Object with `en`+`ar` | None | None | `{"en": "Egypt", "ar": "مصر"}` | Localized names |
| `name.en` | body | string | Required | No | `required\|string\|min:2\|max:50` + `UniqueTranslationRule::for('countries')` (unique across countries; vendor message text **Unverified in source** — suites stub this rule to always pass) | String 2–50 chars, unique | None | None | `"Egypt"` | English name |
| `name.ar` | body | string | Required | No | Same rule set as `name.en` (BOTH locales mandatory on create) | String 2–50 chars, unique | None | None | `"مصر"` | Arabic name |
| `phone_code` | body | string | Optional | Yes | `nullable\|string\|max:5` | Up to 5 chars | `null` | None | `"+20"` | Dial prefix |
| `status` | body | string/integer | Optional | Yes | `nullable\|in:1,0` (note: strict `1`/`0`, NOT boolean `true`/`false`) | `1`, `0` | Model default | None | `1` | Active flag |

No path or query parameters.

## Request examples

```json
{
  "method": "POST",
  "url": "/api/v1/countries",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json",
    "lang": "en"
  },
  "body": {
    "name": { "en": "Egypt", "ar": "مصر" },
    "phone_code": "+20"
  }
}
```

# Responses

## Success: country created — HTTP 201

Returned via `ApiResponse::apiResponse(COUNTRY_CREATED_SUCCESSFULLY, 201, true, CountryResource::make($country))`. Verified by `test_authenticated_admin_can_create_country` (`assertCreated`, `data.phone_code: +20`, DB row asserted) using `postJson`, matching this route.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `201` on success |
| `message` | string | Always present | Translated `MESSAGE.COUNTRY_CREATED_SUCCESSFULLY` | `en` → `"Country created successfully"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | `CountryResource` (route NOT `countries.show`, so `name` is locale string) | `id`, `name` (string), `phone_code`, `status` (bool), `created_at` |

```json
{
  "status": 201,
  "message": "Country created successfully",
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

No error-code field; every error below has Error code `None`.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token (`auth:sanctum` before validation) | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | No valid token (verified by `test_guest_gets_401_for_create_country`) | See 401 example |
| 403 | Authenticated caller lacks `create-country` | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token + only `view-country` (verified by `test_user_without_create_permission_gets_forbidden_for_store`) | See 403 example |
| 422 | `CountryStoreRequest` fails (missing `name`, short string, `phone_code` > 5, bad `status`) | `{message: string, status: boolean, errors: object}` | None | `"The ... field ..."` (Laravel default) | Map of field → messages | Empty body (verified by `test_create_country_returns_422_for_missing_name`) | See 422 example |
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

422 example (empty body):

```json
{
  "message": "The name.en field is required. (and 1 more error)",
  "status": false,
  "errors": {
    "name.en": ["The name.en field is required."],
    "name.ar": ["The name.ar field is required."]
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
