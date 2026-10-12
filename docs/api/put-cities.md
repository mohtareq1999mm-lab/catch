# Endpoint Name

Cities – Update City (`PUT /api/v1/cities/{city}`)

# Description

Partially or fully updates one city by id and returns 200 with the resource.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `PUT` (apiResource also registers `PATCH` → same action) |
| Full path | `/api/v1/cities/{city}` |
| Path composition | `api/v1` prefix + `cities/{city}` from `Route::apiResource('cities', ...)` in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group, plus route-level ANY-of pipe middleware (constructor `update-city` gate decides) |
| Controller action | `Marvel\Http\Controllers\CityController@update` → `findById($id)` (no relations); miss → `NOT_FOUND` 404; then `CityRepository::update` (re-checks `governorate_id` via `ensureGovernorateExists` → 500 race edge) + `flushTag(cities)` |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `update-city` via controller constructor (applies only to `update`); `CityUpdateRequest::authorize` returns `true` |
| Required headers | `Authorization: Bearer <sanctum-token>`, `Content-Type: application/json` when a body is sent |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en\|ar` (default `en`) |
| Supported request formats | `application/json` |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `cities.update` (apiResource default naming) |

Middleware runs before validation: 401/403 precede 404/422. Validation precedes the miss lookup, so 422 precedes 404 when both apply.

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | In `['en', 'ar']` | `en`, `ar` | `en` | None | `en` | Selects message locale |
| `city` | path | integer | Required | No | No route constraint; `int $id` signature | Existing city id | None | None | `1` | Identifies the city |
| `governorate_id` | body | integer | Optional | No | `sometimes\|integer\|exists:governorates,id` | Existing governorate id | Unchanged | None | `1` | Reparents the city |
| `name.en` | body | string | Optional | No | `sometimes\|string\|min:2\|max:50` + `UniqueTranslationRule::for('cities')->ignore(route('city'))` | String 2–50 chars | Unchanged | None | `"Updated"` | English name |
| `name.ar` | body | string | Optional | No | Same rule set as `name.en` | String 2–50 chars | Unchanged | None | `"مدينة نصر"` | Arabic name |
| `status` | body | string/integer | Optional | Yes | `nullable\|in:1,0` — accepted but silently discarded (not fillable; code-verified trap shared with store) | `1`, `0` | Ignored | None | `1` | Ignored field |

## Request examples

```json
{
  "method": "PUT",
  "url": "/api/v1/cities/1",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json",
    "lang": "en"
  },
  "body": {
    "name": { "en": "Updated" }
  }
}
```

# Responses

## Success: city updated — HTTP 200

Returned via `ApiResponse::apiResponse(CITY_UPDATED_SUCCESSFULLY, 200, true, CityResource::make($city))` (refreshed row, locale-string `name`).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.CITY_UPDATED_SUCCESSFULLY` | `en` → `"City updated successfully"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | Refreshed `CityResource` | `id`, `governorate_id`, `name` (string), `created_at` |

```json
{
  "status": 200,
  "message": "City updated successfully",
  "success": true,
  "data": {
    "id": 1,
    "governorate_id": 1,
    "name": "Updated",
    "created_at": "2026-01-01T00:00:00+00:00"
  }
}
```

# Errors

No error-code field; every error below has Error code `None`.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | No valid token | See 401 example |
| 403 | Authenticated caller lacks `update-city` | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token without `update-city` | See 403 example |
| 404 | No city with id (post-validation lookup miss) | `{status: integer, message: string, success: boolean}` | None | `en` → `"Not found"` (generic) | None | Unknown id with valid body | See 404 example |
| 422 | `CityUpdateRequest` fails (unknown `governorate_id`, short name) | `{message: string, status: boolean, errors: object}` | None | Laravel default | Field → messages | Invalid body | See 422 example |
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

422 example:

```json
{
  "message": "The selected governorate id is invalid.",
  "status": false,
  "errors": {
    "governorate_id": ["The selected governorate id is invalid."]
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
