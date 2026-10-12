# Endpoint Name

Currency Rates – Create Exchange Rate (`POST /api/v1/currency-rates`)

# Description

Creates or same-day-upserts a manual exchange rate and returns 200 with the currency block.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `POST` |
| Full path | `/api/v1/currency-rates` |
| Path composition | `api/v1` prefix + `currency-rates` from `Route::apiResource('currency-rates', ...)` in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group, plus route-level ANY-of pipe middleware (constructor `create-exchange-rate` gate decides) |
| Controller action | `Marvel\Http\Controllers\CurrencyRateController@store` → `CurrencyRateService::store` in a row-locked transaction: locks the currency row, then UPSERTS on (`currency_id`, day) — a second post for the same currency+day UPDATES the existing row instead of duplicating (verified by `posting_a_rate_for_the_same_day_upserts_instead_of_duplicating`: count stays 3, rate becomes `0.3`); stamps `source: manual`, clears `provider`; when the date IS today, also flips the currency to `MANUAL` mode + `manual_rate` + invalidates frontend price caches (code-verified side effects); NOTE success status is **200, NOT 201** (all rate tests assert 200) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `create-exchange-rate` via controller constructor (applies only to `store`); `StoreCurrencyRateRequest::authorize` returns `true` |
| Required headers | `Authorization: Bearer <sanctum-token>`, `Content-Type: application/json` |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en\|ar` (default `en`) |
| Supported request formats | `application/json` |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `currency-rates.store` (apiResource default naming) |

Middleware runs before validation: 401/403 precede 422.

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | In `['en', 'ar']` | `en`, `ar` | `en` | None | `en` | Selects message locale |
| `currency_id` | body | integer | Required | No | `required\|integer\|exists:currencies,id` (NOT trashed-aware — a soft-deleted currency id passes validation, then the service `firstOrFail` throws `ModelNotFoundException` → 404 fixed string; code-traced edge) | Existing currency id | None | None | `2` | Parent currency |
| `exchange_rate` | body | number/string | Required | No | `required\|numeric\|gt:0` (strictly positive; 10-decimal precision preserved — verified `exchange_rate_precision_is_preserved`: `0.1234567890` round-trips as `0.123456789`) | Any value > 0 | None | None | `"0.2500000000"` | Rate vs the base currency |
| `effective_date` | body | date string | Required | No | `required\|date` (any parseable date; future dates allowed — no past-only rule, code-verified) | Any date | None | None | `"2026-01-01"` | Day the rate takes effect (upsert key together with `currency_id`) |

No path or query parameters.

## Request examples

```json
{
  "method": "POST",
  "url": "/api/v1/currency-rates",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json",
    "lang": "en"
  },
  "body": {
    "currency_id": 2,
    "exchange_rate": "0.2500000000",
    "effective_date": "2026-01-01"
  }
}
```

# Responses

## Success: rate stored — HTTP 200

Returned via `ApiResponse::apiResponse(CURRENCY_RATE_CREATED_SUCCESSFULLY, 200, true, CurrencyRateResource::make($rate->load('currency')))` (generic message key shared with updates? No — create uses `CURRENCY_RATE_CREATED_SUCCESSFULLY`, code-verified distinct). Verified by `admin_can_create_exchange_rate` (`assertStatus(200)`, exact message assertion, `data.currency.code: KWD`, `exchange_rate` float `0.25`, row count asserted) using `postJson`.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success (NOT 201 — code-verified) |
| `message` | string | Always present | Translated `MESSAGE.CURRENCY_RATE_CREATED_SUCCESSFULLY` | `en` → `"Exchange rate created successfully"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | `CurrencyRateResource` with `currency` loaded | `id`, `currency{id,code,name{},symbol{}}`, `exchange_rate` (string), `source`, `provider`, `effective_date`, `created_at`, `updated_at` |

```json
{
  "status": 200,
  "message": "Exchange rate created successfully",
  "success": true,
  "data": {
    "id": 4,
    "currency": {
      "id": 2,
      "code": "KWD",
      "name": { "en": "Kuwaiti Dinar", "ar": "دينار كويتي" },
      "symbol": { "en": "د.ك", "ar": "د.ك" }
    },
    "exchange_rate": "0.2500000000",
    "source": "manual",
    "provider": null,
    "effective_date": "2026-01-01",
    "created_at": "2026-01-01T00:00:00+00:00",
    "updated_at": "2026-01-01T00:00:00+00:00"
  }
}
```

# Errors

No error-code field; every error below has Error code `None`.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | No valid token (verified by `unauthenticated_requests_are_rejected_with_401`, `postJson` on `/currency-rates`) | See 401 example |
| 403 | Authenticated caller lacks `create-exchange-rate` | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Customer (verified by `customer_without_permission_is_forbidden`, `postJson`); view-only admin (verified: POST → 403 while GET → 200) | See 403 example |
| 404 | Soft-deleted `currency_id` passes `exists` validation (which ignores `deleted_at`) but the service `firstOrFail` throws `ModelNotFoundException` → fixed string (code-traced edge, same class as the governorate/city race edges) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string) | None | Rate for a trashed currency | See 404 example |
| 422 | `StoreCurrencyRateRequest` fails | `{message: string, status: boolean, errors: object}` | None | Laravel default | Field → messages | `exchange_rate: 0` (verified `exchange_rate_must_be_positive`); `exchange_rate: abc` (verified `exchange_rate_must_be_numeric`); `currency_id: 999999` (verified `currency_id_must_exist`); missing `effective_date` (verified `effective_date_is_required`) | See 422 example |
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

404 example (trashed-currency edge):

```json
{
  "message": "Resource Not Found",
  "status": false
}
```

422 example:

```json
{
  "message": "The exchange rate field must be greater than 0.",
  "status": false,
  "errors": {
    "exchange_rate": ["The exchange rate field must be greater than 0."]
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
