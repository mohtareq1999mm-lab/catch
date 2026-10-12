# Endpoint Name

Currencies – Set Rate Mode (`PATCH /api/v1/currencies/{id}/rate-mode`)

# Description

Switches one currency between automatic provider rates and a manual rate, persisting the outcome.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `PATCH` (only `PATCH` is registered — `PUT`/`POST` on this path → 405) |
| Full path | `/api/v1/currencies/{id}/rate-mode` (explicit `{id}` placeholder with `whereNumber('id')` — non-numeric segments match NO route → 404 fixed shape) |
| Path composition | `api/v1` prefix + `currencies/{id}/rate-mode` custom `Route::patch` in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group with single-permission `permission:update-currency` (two segments after `currencies`, so no collision with `currencies/{currency}`) |
| Controller action | `Marvel\Http\Controllers\CurrencyController@setRateMode(UpdateCurrencyRateModeRequest $request, int $id)` → `withTrashed` lookup; miss → `CURRENCY_NOT_FOUND` 404 (checked BEFORE validation — an invalid body on an unknown id yields 404, not 422, code-verified order); then `CurrencyService::setRateMode($currency, RateMode::from(mode), manual_rate?)`: `manual→auto` replays the latest provider rate into the rates table, `auto→manual` stamps the manual rate; `CurrencyRateNotFoundException`/`InvalidArgumentException`/`ExchangeRateProviderException`/`ExchangeRateValidationException` ALL collapse to 422 `EXCHANGE_RATE_NOT_FOUND` (code-verified collapsing catch) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `update-currency` via route middleware AND constructor `only: [setRateMode]` (double gate, same permission — code-verified) |
| Required headers | `Authorization: Bearer <sanctum-token>`, `Content-Type: application/json` |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en\|ar` (default `en`) |
| Supported request formats | `application/json` |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | None (`->name()` absent — code-verified) |

Order matters: 401/403 → 404 (miss) → 422 (validation or service-guard).

## Request inputs

`UpdateCurrencyRateModeRequest` runs after the miss lookup. `prepareForValidation` lowercases + trims `mode`, so `AUTO`/` Manual ` normalize before the `in` check (code-verified). `withValidator` makes `manual_rate` `required` ONLY when the normalized mode is `manual`.

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | In `['en', 'ar']` | `en`, `ar` | `en` | None | `en` | Selects message locale |
| `id` | path | integer | Required | No | `whereNumber('id')` + `int $id` | Existing currency id (trashed included in lookup) | None | None | `2` | Identifies the currency |
| `mode` | body | string | Required | No | `required\|in:auto,manual` (normalized to lowercase first — `AUTO` passes) | `auto`, `manual` | None | None | `"manual"` | Target rate mode |
| `manual_rate` | body | string/number | Conditionally required | No | `nullable\|regex:/^\d+(?:\.\d{1,10})?$/\|gt:0`, plus `required` when `mode === 'manual'` (up to 10 decimals, strictly positive, plain-decimal format only — no exponents/thousands separators, code-verified) | Positive decimal ≤ 10dp | None | Required iff `mode` is `manual`; ignored (nullable) for `auto` | `"0.3180000000"` | Manual rate to stamp |

## Request examples

```json
{
  "method": "PATCH",
  "url": "/api/v1/currencies/2/rate-mode",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json",
    "lang": "en"
  },
  "body": {
    "mode": "manual",
    "manual_rate": "0.3180000000"
  }
}
```

# Responses

## Success: rate mode switched — HTTP 200

Returned via `ApiResponse::apiResponse(CURRENCY_UPDATED_SUCCESSFULLY, 200, true, CurrencyResource::make($currency))`. Verified both directions by `CurrencyRateModeTest` (`patchJson`, matching route+method): `manual_to_auto_uses_the_latest_provider_rate` (mode `auto` → `RateMode::AUTO`, rate row `0.30746`) and `auto_to_manual_requires_and_applies_manual_rate` (mode `manual` + rate → `RateMode::MANUAL`, `manual_rate`/`exchange_rate` `0.318`).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.CURRENCY_UPDATED_SUCCESSFULLY` (generic update message, NOT a mode-specific key — code-verified) | `en` → `"Currency updated successfully"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | Refreshed full `CurrencyResource` | 22-key shape; `rate_mode` is the NEW mode, `manual_rate` the stamped rate (manual) |

```json
{
  "status": 200,
  "message": "Currency updated successfully",
  "success": true,
  "data": {
    "id": 2,
    "code": "KWD",
    "name": { "en": "Kuwaiti Dinar", "ar": "دينار كويتي" },
    "symbol": { "en": "د.ك", "ar": "د.ك" },
    "country_name": { "en": "Kuwait", "ar": "الكويت" },
    "numeric_code": "414",
    "decimal_places": 3,
    "icon": "kwd",
    "is_active": true,
    "sort_order": 0,
    "is_base": false,
    "is_catalog": false,
    "effective_rate": "0.3180000000",
    "rate_mode": "manual",
    "manual_rate": "0.3180000000",
    "provider_rate": "0.3074600000",
    "provider": "frankfurter",
    "last_synced_at": null,
    "provider_rate_at": "2026-01-01T00:00:00+00:00",
    "effective_rate_updated_at": "2026-01-02T00:00:00+00:00",
    "is_rate_stale": false,
    "created_at": "2026-01-01T00:00:00+00:00",
    "updated_at": "2026-01-02T00:00:00+00:00"
  }
}
```

# Errors

No error-code field; every error below has Error code `None`.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | No valid token (same stack as the currency 401 tests) | See 401 example |
| 403 | Authenticated caller lacks `update-currency` | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Customer (verified by `test_customer_cannot_change_rate_mode` — 403) | See 403 example |
| 404 | No currency row with `{id}` (checked BEFORE validation) | `{status: integer, message: string, success: boolean}` | None | Translated `ERROR.CURRENCY_NOT_FOUND` | None | Unknown numeric id even with a valid body (code-traced; no rate-mode 404 test found) | See 404 example |
| 404 | Non-numeric `{id}` matches NO route | `{message: string, status: boolean}` | None | `"Not Found"` | None | `/currencies/abc/rate-mode` (same `whereNumber` mechanics) | See 404-constraint example |
| 405 | `PUT`/`POST` on `/api/v1/currencies/{id}/rate-mode` (only `PATCH` registered) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` | None | Wrong method (code-traced) | See 405 example |
| 422 | `UpdateCurrencyRateModeRequest` fails (missing/unknown `mode`, `manual` without `manual_rate`, non-positive or malformed rate) | `{message: string, status: boolean, errors: object}` | None | Laravel default | Field → messages | `mode: manual` without `manual_rate` (verified by `test_manual_mode_requires_a_manual_rate` → `manual_rate` validation error) | See 422 example |
| 422 | Service-guard failure AFTER validation (no provider rate to replay for `auto`, invalid/failed provider data — all four exception types collapse here) | `{status: integer, message: string, success: boolean}` (NO `errors` map — service outcome, not validation) | None | Translated `ERROR.EXCHANGE_RATE_NOT_FOUND`: `en` → `"No exchange rate found for this currency on or before the selected date"` | None | `mode: auto` with no usable provider rate (code-traced; no test forces this path) | See 422-guard example |
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
  "message": "Currency not found",
  "success": false
}
```

404-constraint example:

```json
{
  "message": "Not Found",
  "status": false
}
```

405 example:

```json
{
  "message": "Method Not Allowed",
  "status": false
}
```

422 example (manual without rate):

```json
{
  "message": "The manual rate field is required.",
  "status": false,
  "errors": {
    "manual_rate": ["The manual rate field is required."]
  }
}
```

422-guard example (no provider rate for auto):

```json
{
  "status": 422,
  "message": "No exchange rate found for this currency on or before the selected date",
  "success": false
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
