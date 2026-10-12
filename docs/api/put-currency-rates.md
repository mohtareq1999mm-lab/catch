# Endpoint Name

Currency Rates – Update Exchange Rate (`PUT /api/v1/currency-rates/{currency_rate}`)

# Description

Replaces the value of one exchange rate row and returns 200 with the currency block.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `PUT` (apiResource also registers `PATCH` → same action; tests use `putJson`) |
| Full path | `/api/v1/currency-rates/{currency_rate}` |
| Path composition | `api/v1` prefix + `currency-rates/{currency_rate}` from `Route::apiResource('currency-rates', ...)->whereNumber('currency_rate')` in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group, plus route-level ANY-of pipe middleware (constructor `update-exchange-rate` gate decides) |
| Controller action | `Marvel\Http\Controllers\CurrencyRateController@update(UpdateCurrencyRateRequest $request, int $id)` → plain `find($id)` (rates are hard-deleted — no trashed question); miss → `CURRENCY_RATE_NOT_FOUND` 404 (checked AFTER validation — an invalid body on an unknown id yields 422, not 404, code-verified order); then `CurrencyRateService::update` in a row-locked transaction: re-locks the parent currency, stamps `exchange_rate` + `source: manual`, clears `provider`; same-day rows additionally flip the currency to `MANUAL` + `manual_rate` + invalidate price caches (same side effects as store, code-verified) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `update-exchange-rate` via controller constructor (applies only to `update` — `delete-exchange-rate` is separately gated, verified: update-holder gets 200 on PUT but 403 on DELETE); `UpdateCurrencyRateRequest::authorize` returns `true` |
| Required headers | `Authorization: Bearer <sanctum-token>`, `Content-Type: application/json` when a body is sent |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en\|ar` (default `en`) |
| Supported request formats | `application/json` |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `currency-rates.update` (apiResource default naming) |

Middleware runs before validation: 401/403 precede 404/422.

## Request inputs

`UpdateCurrencyRateRequest` has exactly ONE rule (code-verified minimalism): `currency_id` and `effective_date` are IMMUTABLE after create — sending them is silently ignored, not rejected.

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | In `['en', 'ar']` | `en`, `ar` | `en` | None | `en` | Selects message locale |
| `currency_rate` | path | integer | Required | No | `whereNumber` + `int $id` | Existing rate id | None | None | `1` | Identifies the rate |
| `exchange_rate` | body | number/string | Required | No | `required\|numeric\|gt:0` (the ONLY body rule) | Any value > 0 | None | None | `"0.4000000000"` | Replacement rate value |

## Request examples

```json
{
  "method": "PUT",
  "url": "/api/v1/currency-rates/1",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json",
    "lang": "en"
  },
  "body": {
    "exchange_rate": "0.4000000000"
  }
}
```

# Responses

## Success: rate updated — HTTP 200

Returned via `ApiResponse::apiResponse(CURRENCY_RATE_UPDATED_SUCCESSFULLY, 200, true, CurrencyRateResource::make($rate->load('currency')))`. Verified by `admin_can_update_exchange_rate` (`assertStatus(200)`, exact message assertion, float `0.4` both in response and DB) using `putJson`, and by `admin_with_update_exchange_rate_permission_cannot_delete_rates` (PUT → 200 for the update-holder).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.CURRENCY_RATE_UPDATED_SUCCESSFULLY` | `en` → `"Exchange rate updated successfully"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | Refreshed `CurrencyRateResource` with `currency` loaded | `id`, `currency{id,code,name{},symbol{}}`, `exchange_rate` (string), `source: "manual"`, `provider: null`, `effective_date` (unchanged), timestamps |

```json
{
  "status": 200,
  "message": "Exchange rate updated successfully",
  "success": true,
  "data": {
    "id": 1,
    "currency": {
      "id": 2,
      "code": "KWD",
      "name": { "en": "Kuwaiti Dinar", "ar": "دينار كويتي" },
      "symbol": { "en": "د.ك", "ar": "د.ك" }
    },
    "exchange_rate": "0.4000000000",
    "source": "manual",
    "provider": null,
    "effective_date": "2026-01-01",
    "created_at": "2026-01-01T00:00:00+00:00",
    "updated_at": "2026-01-02T00:00:00+00:00"
  }
}
```

# Errors

No error-code field; every error below has Error code `None`.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | No valid token (same stack as the rate 401 tests) | See 401 example |
| 403 | Authenticated caller lacks `update-exchange-rate` | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | View-only holder (verified: PUT → 403 while GET → 200 in `admin_with_only_view_permission_cannot_mutate_exchange_rates`) | See 403 example |
| 404 | No rate with id (post-validation lookup miss) | `{status: integer, message: string, success: boolean}` | None | Translated `ERROR.CURRENCY_RATE_NOT_FOUND` | None | Unknown numeric id with valid body (verified by `missing_rate_returns_404` for `999999`, `putJson`) | See 404 example |
| 404 | Non-numeric id matches NO route | `{message: string, status: boolean}` | None | `"Not Found"` | None | `/currency-rates/abc` (same `whereNumber` mechanics) | See 404-constraint example |
| 422 | `UpdateCurrencyRateRequest` fails (missing/non-numeric/non-positive `exchange_rate`) | `{message: string, status: boolean, errors: object}` | None | Laravel default | Field → messages | Invalid body (shared `exchange_rate` rule family with the create 422 tests) | See 422 example |
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
  "message": "Exchange rate not found",
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
