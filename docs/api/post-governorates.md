# Endpoint Name

Governorates – Create Governorate (`POST /api/v1/governorates`)

# Description

Creates one governorate with bilingual name and optional nested shipping price, returning 201.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `POST` |
| Full path | `/api/v1/governorates` |
| Path composition | `api/v1` prefix + `governorates` from `Route::apiResource('governorates', ...)` in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group, plus route-level ANY-of pipe middleware (constructor `create-governorate` gate decides) |
| Controller action | `Marvel\Http\Controllers\GovernorateController@store` → `GovernorateRepository::create` in a DB transaction: strips `shipping_price`, creates governorate, then creates `ShippingPrice` with `governorate_id` when a non-empty array was supplied; `ensureCountryExists` throws `InvalidArgumentException('Country not found.')` on a raced/missing country (→ 500 edge, see Errors) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `create-governorate` via controller constructor (applies only to `store`); `GovernorateStoreRequest::authorize` returns `true` |
| Required headers | `Authorization: Bearer <sanctum-token>`, `Content-Type: application/json` |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en\|ar` (default `en`) |
| Supported request formats | `application/json` |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `governorates.store` (apiResource default naming) |

Middleware runs before validation: 401/403 precede 422.

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | In `['en', 'ar']` | `en`, `ar` | `en` | None | `en` | Selects message locale |
| `country_id` | body | integer | Required | No | `required\|integer\|exists:countries,id` (scopeless string syntax — trashed ids pass then hit the 500 `ensureCountryExists` edge only in a race; normally 422) | Existing country id | None | None | `1` | Parent country |
| `name` | body | object | Required | No | `required\|array` | Object with `en`+`ar` | None | None | `{"en": "Cairo", "ar": "القاهرة"}` | Localized names |
| `name.en` | body | string | Required | No | `required\|string\|min:2\|max:50` + `UniqueTranslationRule::for('governorates')` (vendor text **Unverified in source** — suites stub it to pass) | String 2–50 chars, unique | None | None | `"Cairo"` | English name |
| `name.ar` | body | string | Required | No | Same rule set as `name.en` | String 2–50 chars, unique | None | None | `"القاهرة"` | Arabic name |
| `status` | body | string/integer | Optional | Yes | `nullable\|in:1,0` (strict `1`/`0`) | `1`, `0` | Model default | None | `1` | Active flag |
| `is_fast_shipping_enabled` | body | boolean | Optional | Yes | `nullable\|boolean` | `true`, `false`, `0`, `1` | Model default | None | `false` | Fast-shipping flag |
| `shipping_price` | body | object | Optional | No | `sometimes\|array` (absent = no price row; empty object = also no row, code-verified `! empty()` guard) | Object with fields below | Absent | None | `{"price": 25.5}` | Nested price to create atomically |
| `shipping_price.price` | body | number | Optional | No | `sometimes\|numeric` (no min — negative passes validation, code-verified laxity) | Any number | None | Only inside `shipping_price` | `25.5` | Delivery fee |
| `shipping_price.estimated_days` | body | integer | Optional | No | `sometimes\|integer` (no min) | Any integer | None | Only inside `shipping_price` | `3` | ETA days |
| `shipping_price.free_shipping_over` | body | number | Optional | No | `sometimes\|numeric` | Any number | None | Only inside `shipping_price` | `100` | Free-shipping threshold |
| `shipping_price.status` | body | boolean | Optional | No | `sometimes\|boolean` | `true`, `false`, `0`, `1` | None | Only inside `shipping_price` | `true` | Price active flag |

No path or query parameters.

## Request examples

```json
{
  "method": "POST",
  "url": "/api/v1/governorates",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json",
    "lang": "en"
  },
  "body": {
    "name": { "en": "Cairo", "ar": "القاهرة" },
    "country_id": 1
  }
}
```

# Responses

## Success: governorate created — HTTP 201

Returned via `ApiResponse::apiResponse(GOVERNORATE_CREATED_SUCCESSFULLY, 201, true, GovernorateResource::make($governorate))` (refreshed row; nested price is created but NOT eager-loaded on the response — `shipping_price: null` even when created, code-verified). Verified by `test_authenticated_admin_can_create_governorate` (`assertCreated`, DB row asserted) using `postJson`.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `201` on success |
| `message` | string | Always present | Translated `MESSAGE.GOVERNORATE_CREATED_SUCCESSFULLY` | `en` → `"Governorate created successfully"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | `GovernorateResource`, locale-string `name` | `id`, `country_id`, `name` (string), `status`, `is_fast_shipping_enabled`, `created_at` |

```json
{
  "status": 201,
  "message": "Governorate created successfully",
  "success": true,
  "data": {
    "id": 1,
    "country_id": 1,
    "name": "Cairo",
    "status": true,
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
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | No valid token (verified by `test_guest_gets_401_for_create_governorate`) | See 401 example |
| 403 | Authenticated caller lacks `create-governorate` | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token + only `view-governorate` (verified by `test_user_without_create_permission_gets_forbidden_for_store`) | See 403 example |
| 422 | `GovernorateStoreRequest` fails (missing `country_id`/`name`, unknown `country_id`, bad types) | `{message: string, status: boolean, errors: object}` | None | Laravel default | Field → messages | Missing `country_id` (verified by `test_create_governorate_returns_422_for_missing_country_id`); unknown `country_id: 9999` (verified by `test_create_governorate_returns_422_for_nonexistent_country`) | See 422 example |
| 500 | `country_id` passes validation but the row vanishes before insert (`ensureCountryExists` throws `InvalidArgumentException('Country not found.')`, which matches NO Handler branch → generic 500; `config('app.debug')` controls whether the message leaks) | `{message: string, status: boolean}` | None | `"Internal Server Error"` (or `'Country not found.'` when debug is on) | None | Delete-race between validation and insert (code-traced edge) | See 500 example |
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
  "message": "The country id field is required.",
  "status": false,
  "errors": {
    "country_id": ["The country id field is required."]
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
