# Endpoint Name

Governorates – Toggle Fast Shipping (`PUT /api/v1/governorates/{id}/fast-shipping`)

# Description

Sets the fast-shipping flag of one governorate by id and returns 200 with the resource.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `PUT` (only `PUT` is registered — `PATCH`/`POST` on this path → 405) |
| Full path | `/api/v1/governorates/{id}/fast-shipping` |
| Path composition | `api/v1` prefix + `governorates/{id}/fast-shipping` custom `Route::put` in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group with `['auth:sanctum', 'permission:update-governorate']` (two segments after `governorates`, so no collision with `governorates/{governorate}` show/update) |
| Controller action | `Marvel\Http\Controllers\GovernorateController@toggleFastShipping` → `findById($id)`; miss → `NOT_FOUND` 404 BEFORE validation (code-verified order — unlike update, where validation runs first); then inline `$request->validate(['is_fast_shipping_enabled' => ['required', 'boolean']])` (→ 422) and repository `update` + `flushTag(governorates)` |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `update-governorate` via route middleware (constructor does NOT gate `toggleFastShipping`) |
| Required headers | `Authorization: Bearer <sanctum-token>`, `Content-Type: application/json` |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en\|ar` (default `en`) |
| Supported request formats | `application/json` |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | None (`->name()` absent — code-verified) |

Order matters: 401/403 → 404 (miss) → 422 (bad flag). A missing flag on an unknown id yields 404, not 422.

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | In `['en', 'ar']` | `en`, `ar` | `en` | None | `en` | Selects message locale |
| `id` | path | integer | Required | No | `int $id` signature; no route constraint | Existing governorate id | None | None | `1` | Identifies the governorate |
| `is_fast_shipping_enabled` | body | boolean | Required | No | `required\|boolean` (inline validate — accepts `true`/`false`/`0`/`1`/`"0"`/`"1"`) | Boolean-coercible | None | None | `true` | Target fast-shipping flag |

No query parameters.

## Request examples

```json
{
  "method": "PUT",
  "url": "/api/v1/governorates/1/fast-shipping",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json",
    "lang": "en"
  },
  "body": {
    "is_fast_shipping_enabled": true
  }
}
```

# Responses

## Success: flag updated — HTTP 200

Returned via `ApiResponse::apiResponse(GOVERNORATE_UPDATED_SUCCESSFULLY, 200, true, GovernorateResource::make($governorate))` (no relations loaded — `country: null`, `cities: []`, `shipping_price: null`). Verified by `test_authenticated_admin_can_toggle_fast_shipping` (`assertOk`, `data.is_fast_shipping_enabled: true`) using `putJson`.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.GOVERNORATE_UPDATED_SUCCESSFULLY` | `en` → `"Governorate updated successfully"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | Refreshed `GovernorateResource`, locale-string `name` | `id`, `country_id`, `name` (string), `status`, `is_fast_shipping_enabled`, `created_at` |

```json
{
  "status": 200,
  "message": "Governorate updated successfully",
  "success": true,
  "data": {
    "id": 1,
    "country_id": 1,
    "name": "Cairo",
    "status": true,
    "is_fast_shipping_enabled": true,
    "country": null,
    "cities": [],
    "shipping_price": null,
    "created_at": "2026-01-01T00:00:00+00:00"
  }
}
```

# Errors

No error-code field; every error below has Error code `None`.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | No valid token (verified by `test_guest_gets_401_for_toggle_fast_shipping` for id `9999`) | See 401 example |
| 403 | Authenticated caller lacks `update-governorate` | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token without `update-governorate` (code-traced; no toggle-specific 403 test found) | See 403 example |
| 404 | No governorate with `{id}` (checked BEFORE flag validation) | `{status: integer, message: string, success: boolean}` | None | `en` → `"Not found"` (generic) | None | Unknown id even with valid flag (verified by `test_toggle_fast_shipping_returns_404_for_nonexistent_governorate` for `9999`) | See 404 example |
| 405 | `POST`/`PATCH` on `/api/v1/governorates/{id}/fast-shipping` (only `PUT` registered) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` | None | Wrong method (code-traced) | See 405 example |
| 422 | Inline validation fails (missing or non-boolean `is_fast_shipping_enabled`) on an EXISTING id | `{message: string, status: boolean, errors: object}` | None | Laravel default | Field → messages | Empty body on a real id (code-traced; no toggle-specific 422 test found) | See 422 example |
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

405 example:

```json
{
  "message": "Method Not Allowed",
  "status": false
}
```

422 example:

```json
{
  "message": "The is fast shipping enabled field is required.",
  "status": false,
  "errors": {
    "is_fast_shipping_enabled": ["The is fast shipping enabled field is required."]
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
