# Endpoint Name

Countries – Update Country (`PUT /api/v1/countries/{country}`)

# Description

Partially or fully updates one country by id and returns 200 with the locale-string resource.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `PUT` (apiResource also registers `PATCH` → same action; tests use `putJson`) |
| Full path | `/api/v1/countries/{country}` |
| Path composition | `api/v1` prefix + `countries/{country}` from `Route::apiResource('countries', ...)` in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group, plus route-level ANY-of pipe middleware (constructor `update-country` gate decides) |
| Controller action | `Marvel\Http\Controllers\CountryController@update` → `findById($id)` (no relations); miss → `COUNTRY_NOT_FOUND` 404; then `update($country, $request->validated())` + `flushTag(countries)` |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `update-country` via controller constructor (applies only to `update`); `CountryUpdateRequest::authorize` returns `true` |
| Required headers | `Authorization: Bearer <sanctum-token>`, `Content-Type: application/json` when a body is sent |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en\|ar` (default `en`) |
| Supported request formats | `application/json` |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `countries.update` (apiResource default naming) |

Middleware runs before validation: 401/403 precede any 404/422.

## Request inputs

`CountryUpdateRequest` runs before the body: every field is `sometimes`, so an empty body passes validation and performs a no-op update (code-verified). Miss lookup happens AFTER validation, so a 422 precedes a 404 when both apply.

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | In `['en', 'ar']` | `en`, `ar` | `en` | None | `en` | Selects message locale |
| `country` | path | integer | Required | No | No route constraint; `int $id` signature | Existing country id | None | None | `1` | Identifies the country |
| `name.en` | body | string | Optional | No | `sometimes\|string\|min:2\|max:50` + `UniqueTranslationRule::for('countries')->ignore(route('country'))` (self-name passes; vendor text **Unverified in source**) | String 2–50 chars | Unchanged | None | `"Updated"` | English name |
| `name.ar` | body | string | Optional | No | Same rule set as `name.en` | String 2–50 chars | Unchanged | None | `"مصر"` | Arabic name |
| `phone_code` | body | string | Optional | Yes | `nullable\|string\|max:5` | Up to 5 chars | Unchanged | None | `"+2"` | Dial prefix |
| `status` | body | string/integer | Optional | Yes | `nullable\|in:1,0` (strict `1`/`0`) | `1`, `0` | Unchanged | None | `0` | Active flag |

No query parameters.

## Request examples

```json
{
  "method": "PUT",
  "url": "/api/v1/countries/1",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json",
    "lang": "en"
  },
  "body": {
    "phone_code": "+2",
    "status": 0
  }
}
```

# Responses

## Success: country updated — HTTP 200

Returned via `ApiResponse::apiResponse(COUNTRY_UPDATED_SUCCESSFULLY, 200, true, CountryResource::make($country))` (refreshed model, no relations — `governorates: []`). Verified by `test_authenticated_admin_can_update_country` (`assertOk`, `data.phone_code: +2`, `data.status: false`) using `putJson`.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.COUNTRY_UPDATED_SUCCESSFULLY` | `en` → `"Country updated successfully"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | Refreshed `CountryResource`, locale-string `name` | `id`, `name` (string), `phone_code`, `status` (bool), `created_at` |

```json
{
  "status": 200,
  "message": "Country updated successfully",
  "success": true,
  "data": {
    "id": 1,
    "name": "Egypt",
    "phone_code": "+2",
    "status": false,
    "governorates": [],
    "created_at": "2026-01-01T00:00:00+00:00"
  }
}
```

# Errors

No error-code field; every error below has Error code `None`.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | No valid token (verified by `test_guest_gets_401_for_update_country`) | See 401 example |
| 403 | Authenticated caller lacks `update-country` | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token + only `view-country` (verified by `test_user_without_update_permission_gets_forbidden_for_update`) | See 403 example |
| 404 | No country with `{country}` (post-validation lookup miss) | `{status: integer, message: string, success: boolean}` | None | `en` → `"Country not found"` | None | Unknown id with valid body (verified by `test_update_country_returns_404_for_nonexistent_id` for `9999`) | See 404 example |
| 422 | `CountryUpdateRequest` fails (`name.en` too short, `phone_code` > 5, bad `status`) | `{message: string, status: boolean, errors: object}` | None | Laravel default | Field → messages | Invalid body on an existing or missing id (validation precedes lookup) | See 422 example |
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

422 example:

```json
{
  "message": "The name.en field must be at least 2 characters.",
  "status": false,
  "errors": {
    "name.en": ["The name.en field must be at least 2 characters."]
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
