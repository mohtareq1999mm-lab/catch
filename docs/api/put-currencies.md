# Endpoint Name

Currencies – Update Currency (`PUT /api/v1/currencies/{currency}`)

# Description

Partially updates one currency by numeric id, excluding code and monetary roles, and returns 200.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `PUT` (apiResource also registers `PATCH` → same action; tests use `putJson`) |
| Full path | `/api/v1/currencies/{currency}` |
| Path composition | `api/v1` prefix + `currencies/{currency}` from `Route::apiResource('currencies', ...)->whereNumber('currency')` in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group, plus route-level ANY-of pipe middleware (constructor `update-currency` gate decides; non-numeric segments match NO route → 404 fixed shape, verified by `non_numeric_id_returns_404_not_500`) |
| Controller action | `Marvel\Http\Controllers\CurrencyController@update(UpdateCurrencyRequest $request, int $id)` → `withTrashed` lookup (trashed rows updatable — code-verified); miss → `CURRENCY_NOT_FOUND` 404; then `CurrencyService::updateCurrency` + 200 (NOT `update-currency`-specific message — the generic `CURRENCY_UPDATED_SUCCESSFULLY`) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `update-currency` via controller constructor (applies only to `update` — NOT to setBase/setCatalog, which have their own gates); `UpdateCurrencyRequest::authorize` returns `true` |
| Required headers | `Authorization: Bearer <sanctum-token>`, `Content-Type: application/json` when a body is sent |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en\|ar` (default `en`) |
| Supported request formats | `application/json` |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `currencies.update` (apiResource default naming) |

Middleware runs before validation: 401/403 precede 404/422. Validation precedes the miss lookup, so 422 precedes 404 when both apply.

## Request inputs

`UpdateCurrencyRequest` runs before the body. NOTE what is ABSENT (code-verified): there is NO `code` rule — the ISO code is IMMUTABLE after create (sending `code` is silently ignored, not rejected); NO `is_base`/`is_catalog`/`rate_mode`/`manual_rate` rules (monetary roles change only via the dedicated set-base/set-catalog/rate-mode endpoints). The full-permission test proves a full-payload update works while `code: USD` passes through unchanged.

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | In `['en', 'ar']` | `en`, `ar` | `en` | None | `en` | Selects message locale |
| `currency` | path | integer | Required | No | `whereNumber` + `int $id` | Existing currency id (trashed included) | None | None | `1` | Identifies the currency |
| `name` | body | object | Optional | No | `sometimes\|array` | Object with locales | Unchanged | None | `{"en": "United States Dollar"}` | Localized names wrapper |
| `name.*` | body | string | Optional | No | `sometimes\|required\|string\|max:255` + unique-ignore-self (per-locale `sometimes` — a present-but-empty `name: {}` passes element validation, code-verified laxity) | String ≤ 255 chars | Unchanged | None | `"United States Dollar"` | One locale's name |
| `symbol` | body | object/array | Optional | Yes | `nullable\|array` (differs from create's `required` — code-verified) | Object or null | Unchanged | None | `{"en": "US$"}` | Localized symbols wrapper |
| `symbol.*` | body | string | Optional | Yes | `nullable\|string\|max:255` + unique-ignore-self | String ≤ 255 chars or null | Unchanged | None | `"US$"` | One locale's symbol |
| `country_name` | body | object/array | Optional | Yes | `nullable\|array` (differs from create's `required` — code-verified) | Object or null | Unchanged | None | `{"en": "United States"}` | Localized country-name wrapper |
| `country_name.*` | body | string | Optional | Yes | `nullable\|string\|max:255` + unique-ignore-self | String ≤ 255 chars or null | Unchanged | None | `"United States"` | One locale's country name |
| `numeric_code` | body | string | Optional | Yes | `nullable\|string\|max:3` | ≤ 3 chars | Unchanged | None | `"840"` | Numeric code |
| `decimal_places` | body | integer | Optional | Yes | `nullable\|integer\|min:0\|max:4` | `0`–`4` | Unchanged | None | `2` | Minor-unit digits |
| `icon` | body | string | Optional | Yes | `nullable\|string\|max:255` | Any string ≤ 255 chars | Unchanged | None | `"dollar"` | Icon key |
| `is_active` | body | boolean | Optional | Yes | `nullable\|boolean` | `true`, `false`, `0`, `1` | Unchanged | None | `true` | Active flag |
| `sort_order` | body | integer | Optional | Yes | `nullable\|integer` | Any integer | Unchanged | None | `7` | Display order |

## Request examples

```json
{
  "method": "PUT",
  "url": "/api/v1/currencies/1",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json",
    "lang": "en"
  },
  "body": {
    "name": { "en": "United States Dollar", "ar": "دولار أمريكي" },
    "icon": "dollar"
  }
}
```

# Responses

## Success: currency updated — HTTP 200

Returned via `ApiResponse::apiResponse(CURRENCY_UPDATED_SUCCESSFULLY, 200, true, CurrencyResource::make($currency))`. Verified by `admin_can_update_currency` (`assertStatus(200)`, exact message assertion, `data.icon: dollar`, DB row asserted) using `putJson`.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.CURRENCY_UPDATED_SUCCESSFULLY` | `en` → `"Currency updated successfully"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | Refreshed full `CurrencyResource` (22 keys) | See list doc for the key table |

```json
{
  "status": 200,
  "message": "Currency updated successfully",
  "success": true,
  "data": {
    "id": 1,
    "code": "USD",
    "name": { "en": "United States Dollar", "ar": "دولار أمريكي" },
    "symbol": { "en": "$", "ar": "$" },
    "country_name": { "en": "United States", "ar": "الولايات المتحدة" },
    "numeric_code": "840",
    "decimal_places": 2,
    "icon": "dollar",
    "is_active": true,
    "sort_order": 0,
    "is_base": true,
    "is_catalog": true,
    "effective_rate": "1.0000000000",
    "rate_mode": "manual",
    "manual_rate": "1.0000000000",
    "provider_rate": null,
    "provider": null,
    "last_synced_at": null,
    "provider_rate_at": null,
    "effective_rate_updated_at": "2026-01-01T00:00:00+00:00",
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
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | No valid token (same stack as list 401 tests) | See 401 example |
| 403 | Authenticated caller lacks `update-currency` (view-only is NOT enough) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | View-only admin (verified by `admin_with_only_view_permission_cannot_mutate_currencies` — PUT → 403) | See 403 example |
| 404 | No currency row with id (post-validation lookup miss) | `{status: integer, message: string, success: boolean}` | None | Translated `ERROR.CURRENCY_NOT_FOUND`: `en` → `"Currency not found"` | None | Unknown numeric id with valid body (verified by `missing_currency_returns_404` for `999999`) | See 404 example |
| 404 | Non-numeric id matches NO route | `{message: string, status: boolean}` | None | `"Not Found"` (NOT the translated string) | None | `/currencies/abc` (verified by `non_numeric_id_returns_404_not_500`) | See 404-constraint example |
| 422 | `UpdateCurrencyRequest` fails (`decimal_places: 9`, bad types) | `{message: string, status: boolean, errors: object}` | None | Laravel default | Field → messages | Invalid body (shared rule family with the create 422 tests) | See 422 example |
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

404 example (unknown numeric id):

```json
{
  "status": 404,
  "message": "Currency not found",
  "success": false
}
```

404-constraint example (non-numeric id):

```json
{
  "message": "Not Found",
  "status": false
}
```

422 example:

```json
{
  "message": "The decimal places field must not be greater than 4.",
  "status": false,
  "errors": {
    "decimal_places": ["The decimal places field must not be greater than 4."]
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
