# Endpoint Name

Countries – Delete Country (`DELETE /api/v1/countries/{country}`)

# Description

Deletes one country by id and returns 200 with no data payload.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `DELETE` |
| Full path | `/api/v1/countries/{country}` |
| Path composition | `api/v1` prefix + `countries/{country}` from `Route::apiResource('countries', ...)` in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group, plus route-level ANY-of pipe middleware (constructor `delete-country` gate decides) |
| Controller action | `Marvel\Http\Controllers\CountryController@destroy` → `findById($id)`; miss → `COUNTRY_NOT_FOUND` 404; then `delete($country)` + `flushTag(countries)` (no child guard in code — FK behavior is database-level, **Unverified in source**) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `delete-country` via controller constructor (applies only to `destroy`) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en\|ar` (default `en`) |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `countries.destroy` (apiResource default naming) |

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | In `['en', 'ar']` | `en`, `ar` | `en` | None | `en` | Selects message locale |
| `country` | path | integer | Required | No | No route constraint; `int $id` signature | Existing country id | None | None | `1` | Identifies the country |

No query parameters, body fields, or `422` contract.

## Request examples

```json
{
  "method": "DELETE",
  "url": "/api/v1/countries/1",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: country deleted — HTTP 200

Returned via `ApiResponse::apiResponse(COUNTRY_DELETED_SUCCESSFULLY, 200, true)` with NO data key (`empty($data)` omits it). Verified by `test_authenticated_admin_can_delete_country` (`assertOk`, `success: true`) using `deleteJson`.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.COUNTRY_DELETED_SUCCESSFULLY` | `en` → `"Country deleted successfully"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |

```json
{
  "status": 200,
  "message": "Country deleted successfully",
  "success": true
}
```

# Errors

No error-code field; every error below has Error code `None`. No validation, so no `422`.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | No valid token (verified by `test_guest_gets_401_for_delete_country`) | See 401 example |
| 403 | Authenticated caller lacks `delete-country` | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token + only `view-country` (verified by `test_user_without_delete_permission_gets_forbidden_for_destroy`) | See 403 example |
| 404 | No country with `{country}` | `{status: integer, message: string, success: boolean}` | None | `en` → `"Country not found"` | None | Unknown id (verified by `test_delete_country_returns_404_for_nonexistent_id` for `9999`) | See 404 example |
| 409 | Database FK violation when governorates still reference the country (code-traced edge — no child guard in `CountryRepository::delete`; exact behavior depends on migration `ON DELETE` rule, **Unverified in source**) | `{message: string, status: boolean}` | None | `"Database error occurred. Please check your request and try again."` via `QueryException` branch | None | Deleting a country that still has governorates when the FK is restrictive | See 409 example |
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

409 example (conditional FK edge):

```json
{
  "message": "Database error occurred. Please check your request and try again.",
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
