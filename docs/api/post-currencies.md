# Endpoint Name

Currencies – Create Currency (`POST /api/v1/currencies`)

# Description

Creates one currency with translated names and returns 200 with the full resource.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `POST` |
| Full path | `/api/v1/currencies` |
| Path composition | `api/v1` prefix + `currencies` from `Route::apiResource('currencies', ...)` in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group, plus route-level ANY-of pipe middleware (constructor `create-currency` gate decides) |
| Controller action | `Marvel\Http\Controllers\CurrencyController@store` → `CurrencyService::storeCurrency($request->validated())`; NOTE success status is **200, NOT 201** (code-verified — the controller passes `200` and the test asserts `assertStatus(200)`) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `create-currency` via controller constructor (applies only to `store`); `StoreCurrencyRequest::authorize` returns `true` |
| Required headers | `Authorization: Bearer <sanctum-token>`, `Content-Type: application/json` |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en\|ar` (default `en`) |
| Supported request formats | `application/json` |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `currencies.store` (apiResource default naming) |

Middleware runs before validation: 401/403 precede 422.

## Request inputs

`StoreCurrencyRequest` runs before the body. `prepareForValidation` uppercases + trims `code`, so lowercase input (`usd`) is normalized BEFORE the `unique` check — `duplicate_code_is_rejected_even_with_different_case` proves `usd` collides with `USD` (code-verified normalization, not a case-insensitive column). `is_base`/`is_catalog`/`rate_mode` are NOT request fields (no base/catalog hijack via create — code-verified).

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | In `['en', 'ar']` | `en`, `ar` | `en` | None | `en` | Selects message locale |
| `code` | body | string | Required | No | `required\|string\|size:3\|regex:/^[A-Za-z]{3}$/\|unique:currencies,code` (normalized to UPPER first; `size:3` + alpha-only) | 3 ASCII letters, unique | None | None | `"EGP"` | ISO-4217 code |
| `name` | body | object | Required | No | `required\|array` | Object with locales | None | None | `{"en": "Egyptian Pound", "ar": "جنيه مصري"}` | Localized names wrapper |
| `name.*` | body | string | Required per locale | No | `required\|string\|max:255` + `UniqueTranslationRule::for('currencies', 'name')` (vendor text **Unverified in source**) | String ≤ 255 chars, unique | None | None | `"Egyptian Pound"` | One locale's name |
| `symbol` | body | object | Required | No | `required\|array` | Object with locales | None | None | `{"en": "E£", "ar": "ج.م"}` | Localized symbols wrapper |
| `symbol.*` | body | string | Required per locale | No | Same rule shape as `name.*` (unique across `symbol`) | String ≤ 255 chars, unique | None | None | `"E£"` | One locale's symbol |
| `country_name` | body | object | Required | No | `required\|array` | Object with locales | None | None | `{"en": "Egypt", "ar": "مصر"}` | Localized country-name wrapper |
| `country_name.*` | body | string | Required per locale | No | Same rule shape as `name.*` (unique across `country_name`) | String ≤ 255 chars, unique | None | None | `"Egypt"` | One locale's country name |
| `numeric_code` | body | string | Optional | Yes | `nullable\|string\|max:3` | ≤ 3 chars | `null` | None | `"818"` | ISO-4217 numeric code |
| `decimal_places` | body | integer | Optional | Yes | `nullable\|integer\|min:0\|max:4` | `0`–`4` | Model default | None | `2` | Minor-unit digits |
| `icon` | body | string | Optional | Yes | `nullable\|string\|max:255` | Any string ≤ 255 chars | `null` | None | `"eg"` | Icon key |
| `is_active` | body | boolean | Optional | Yes | `nullable\|boolean` | `true`, `false`, `0`, `1` | Model default | None | `true` | Active flag |
| `sort_order` | body | integer | Optional | Yes | `nullable\|integer` | Any integer | Model default | None | `7` | Display order |

No path or query parameters.

## Request examples

```json
{
  "method": "POST",
  "url": "/api/v1/currencies",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json",
    "lang": "en"
  },
  "body": {
    "code": "EGP",
    "name": { "en": "Egyptian Pound", "ar": "جنيه مصري" },
    "symbol": { "en": "E£", "ar": "ج.م" },
    "country_name": { "en": "Egypt", "ar": "مصر" },
    "numeric_code": "818",
    "decimal_places": 2,
    "icon": "eg",
    "is_active": true,
    "sort_order": 7
  }
}
```

# Responses

## Success: currency created — HTTP 200

Returned via `ApiResponse::apiResponse(CURRENCY_CREATED_SUCCESSFULLY, 200, true, CurrencyResource::make($currency))`. Verified by `admin_can_create_currency` (`assertStatus(200)`, exact message assertion, `data.code: EGP`, `data.is_base: false`, DB row asserted) using `postJson`.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success (NOT 201 — code-verified) |
| `message` | string | Always present | Translated `MESSAGE.CURRENCY_CREATED_SUCCESSFULLY` | `en` → `"Currency created successfully"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | Full `CurrencyResource` (translation maps + computed flags; new rows are never base/catalog) | Same 22-key shape as the list item |

```json
{
  "status": 200,
  "message": "Currency created successfully",
  "success": true,
  "data": {
    "id": 4,
    "code": "EGP",
    "name": { "en": "Egyptian Pound", "ar": "جنيه مصري" },
    "symbol": { "en": "E£", "ar": "ج.م" },
    "country_name": { "en": "Egypt", "ar": "مصر" },
    "numeric_code": "818",
    "decimal_places": 2,
    "icon": "eg",
    "is_active": true,
    "sort_order": 7,
    "is_base": false,
    "is_catalog": false,
    "effective_rate": null,
    "rate_mode": null,
    "manual_rate": null,
    "provider_rate": null,
    "provider": null,
    "last_synced_at": null,
    "provider_rate_at": null,
    "effective_rate_updated_at": null,
    "is_rate_stale": false,
    "created_at": "2026-01-01T00:00:00+00:00",
    "updated_at": "2026-01-01T00:00:00+00:00"
  }
}
```

# Errors

No error-code field; every error below has Error code `None`.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | No valid token (verified by `unauthenticated_requests_are_rejected_with_401`, `postJson` with the full payload) | See 401 example |
| 403 | Authenticated caller lacks `create-currency` | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Customer (verified by `customer_without_permission_is_forbidden`); view-only admin (verified by `admin_without_create_permission_is_forbidden` — GET passes 200, POST 403) | See 403 example |
| 422 | `StoreCurrencyRequest` fails | `{message: string, status: boolean, errors: object}` | None | Laravel default | Field → messages | 2-letter code (verified `invalid_code_is_rejected`); alphanumeric `US1` (verified `code_must_be_alphabetic`); duplicate incl. case-folded `usd` (verified `duplicate_code_is_rejected_even_with_different_case`); missing `name` (verified `missing_name_is_rejected`); `decimal_places: 9` (verified `invalid_decimal_places_is_rejected`) | See 422 example |
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
  "message": "The code field must be 3 characters.",
  "status": false,
  "errors": {
    "code": ["The code field must be 3 characters."]
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
