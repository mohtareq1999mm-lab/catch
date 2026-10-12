# Endpoint Name

Governorates – Update Governorate (`PUT /api/v1/governorates/{governorate}`)

# Description

Partially or fully updates one governorate by id, including its nested shipping price, and returns 200.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `PUT` (apiResource also registers `PATCH` → same action; tests use `putJson`) |
| Full path | `/api/v1/governorates/{governorate}` |
| Path composition | `api/v1` prefix + `governorates/{governorate}` from `Route::apiResource('governorates', ...)` in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group, plus route-level ANY-of pipe middleware (constructor `update-governorate` gate decides) |
| Controller action | `Marvel\Http\Controllers\GovernorateController@update` → `findById($id)` (no relations); miss → `NOT_FOUND` 404; then repository `update` in a DB transaction: updates row, then upserts nested `shipping_price` (updates existing row or creates one with `governorate_id`); `flushTag(governorates)` |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `update-governorate` via controller constructor (applies only to `update`); `GovernorateUpdateRequest::authorize` returns `true` |
| Required headers | `Authorization: Bearer <sanctum-token>`, `Content-Type: application/json` when a body is sent |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en\|ar` (default `en`) |
| Supported request formats | `application/json` |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `governorates.update` (apiResource default naming) |

Middleware runs before validation: 401/403 precede 404/422. Validation precedes the miss lookup, so 422 precedes 404 when both apply.

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | In `['en', 'ar']` | `en`, `ar` | `en` | None | `en` | Selects message locale |
| `governorate` | path | integer | Required | No | No route constraint; `int $id` signature | Existing governorate id | None | None | `1` | Identifies the governorate |
| `country_id` | body | integer | Optional | No | `sometimes\|integer\|exists:countries,id` | Existing country id | Unchanged | None | `1` | Reparents the governorate |
| `name` | body | object | Optional | No | `sometimes\|array` | Object with locales | Unchanged | None | `{"en": "Giza"}` | Localized names wrapper |
| `name.en` | body | string | Optional | No | `sometimes\|string\|min:2\|max:50` + `UniqueTranslationRule::for('governorates')->ignore(route('governorate'))` | String 2–50 chars | Unchanged | None | `"Giza"` | English name |
| `name.ar` | body | string | Optional | No | Same rule set as `name.en` | String 2–50 chars | Unchanged | None | `"الجيزة"` | Arabic name |
| `status` | body | boolean | Optional | Yes | `nullable\|boolean` (NOTE: store uses strict `in:1,0` — update accepts real booleans; code-verified asymmetry) | `true`, `false`, `0`, `1` | Unchanged | None | `false` | Active flag |
| `is_fast_shipping_enabled` | body | boolean | Optional | Yes | `nullable\|boolean` | `true`, `false`, `0`, `1` | Unchanged | None | `false` | Fast-shipping flag |
| `shipping_price` | body | object | Optional | No | `sometimes\|array` (empty object = no-op, code-verified `! empty()` guard) | Object with fields below | Unchanged | None | `{"price": 30}` | Nested price upsert |
| `shipping_price.price` | body | number | Optional | No | `sometimes\|numeric` | Any number | Unchanged | Only inside `shipping_price` | `30` | Delivery fee |
| `shipping_price.estimated_days` | body | integer | Optional | No | `sometimes\|integer` | Any integer | Unchanged | Only inside `shipping_price` | `2` | ETA days |
| `shipping_price.free_shipping_over` | body | number | Optional | No | `sometimes\|numeric` | Any number | Unchanged | Only inside `shipping_price` | `150` | Free-shipping threshold |
| `shipping_price.status` | body | boolean | Optional | No | `sometimes\|boolean` | `true`, `false`, `0`, `1` | Unchanged | Only inside `shipping_price` | `true` | Price active flag |

## Request examples

```json
{
  "method": "PUT",
  "url": "/api/v1/governorates/1",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json",
    "lang": "en"
  },
  "body": {
    "status": 0,
    "is_fast_shipping_enabled": false
  }
}
```

# Responses

## Success: governorate updated — HTTP 200

Returned via `ApiResponse::apiResponse(GOVERNORATE_UPDATED_SUCCESSFULLY, 200, true, GovernorateResource::make($governorate))` (refreshed row; nested price NOT eager-loaded on the response — `shipping_price: null` unless the relation happens to be loaded, code-verified). Verified by `test_authenticated_admin_can_update_governorate` (`data.status: false`, `data.is_fast_shipping_enabled: false`) using `putJson`.

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
    "status": false,
    "is_fast_shipping_enabled": false,
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
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | No valid token (verified by `test_guest_gets_401_for_update_governorate`) | See 401 example |
| 403 | Authenticated caller lacks `update-governorate` | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token + only `view-governorate` (verified by `test_user_without_update_permission_gets_forbidden_for_update`) | See 403 example |
| 404 | No governorate with id (post-validation lookup miss) | `{status: integer, message: string, success: boolean}` | None | `en` → `"Not found"` (generic) | None | Unknown id with valid body (verified by `test_update_governorate_returns_404_for_nonexistent_id` for `9999`) | See 404 example |
| 422 | `GovernorateUpdateRequest` fails (unknown `country_id`, bad types) | `{message: string, status: boolean, errors: object}` | None | Laravel default | Field → messages | Invalid body (code-traced; create-side 422 tests verify the shared rule family) | See 422 example |
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
  "message": "The selected country id is invalid.",
  "status": false,
  "errors": {
    "country_id": ["The selected country id is invalid."]
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
