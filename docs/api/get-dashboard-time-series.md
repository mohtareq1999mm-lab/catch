# Endpoint Name

Dashboard – Time Series (`GET /api/v1/dashboard/time-series`)

# Description

Returns an orders/revenue metric series for a period and granularity behind a double gate.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/dashboard/time-series` (NOT `/api/v1/admin/analytics/time-series` — the admin path appears in `AnalyticsAPITest`/`SecurityRemediationTest`/`route-inventory.json` but has NO registration in current source (repo-wide search finds only the export prefix at `Routes.php:484`); those tests target a stale path that would 404 today, while the behavior below is served here — HIGH confidence, verified; the kept route NAME `api.admin.analytics.time-series` is the fossil of the move) |
| Path composition | `api/v1` prefix + `dashboard/time-series` from the `Route::middleware(['auth:sanctum', 'throttle:analytics', 'permission:view-analytics', 'lang'])->prefix('dashboard')` group (named `api.admin.analytics.time-series`) at `packages/marvel/src/Rest/Routes.php:417` |
| Controller action | `App\Http\Controllers\Api\Admin\AnalyticsController@timeSeries` → `authorizeAdmin` (view-analytics triple fallback) → inline `$request->validate(...)` (→ 422) → `OrderAnalyticsService::getTimeSeries($metric, $period, $granularity)`; service points carry `{date, value}` keys (pinned at SERVICE level by `AnalyticsServiceTest::generates_time_series_data` — the HTTP assertion below is method-transferred from the stale-path test since the controller method is identical) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (group + constructor + manual check) |
| Permission | `view-analytics` via route middleware AND inline `authorizeAdmin` (double gate, same permission — code-verified) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en\|ar` (default `en`) |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:analytics`: 60 requests/minute per user (or per IP for guests) — NOT the 400/min `throttle:admin` used by every other endpoint in this batch (code-verified group difference) |
| Route name | `api.admin.analytics.time-series` (explicit named route — stale-prefix name on the live dashboard path) |

Order matters: 401/403 → 422 (validation) → 200.

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | In `['en', 'ar']` | `en`, `ar` | `en` | None | `en` | Selects message locale |
| `metric` | query | string | Required | No | `required\|in:orders,revenue,avg_order_value` | `orders`, `revenue`, `avg_order_value` | None | None | `"orders"` | The series metric |
| `period` | query | string | Optional | No | `sometimes\|in:1h,24h,7d,30d,90d` | The five windows | `7d` (NOTE: differs from the `24h` default on sibling actions — code-verified) | None | `"7d"` | Lookback window |
| `granularity` | query | string | Optional | No | `sometimes\|in:hour,day,week,month` | The four buckets | `day` | None | `"day"` | Bucket size |

## Request examples

```json
{
  "method": "GET",
  "url": "/api/v1/dashboard/time-series?metric=orders&period=7d&granularity=day",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: series returned — HTTP 200

Returned via `ApiResponse::apiResponse('Time series retrieved.', 200, true, ['metric' => ..., 'period' => ..., 'granularity' => ..., 'series' => $data])` (literal message, not a constant). Structure (`data.metric/period/granularity/series`) asserted by `admin_can_fetch_time_series_data` on the stale twin path — same controller method, so the shape transfers (flagged, not re-verified here); point keys `{date, value}` pinned at service level.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Literal (untranslated) | Always `"Time series retrieved."` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | Echo + series (keys verified in controller + tests) | `metric`, `period`, `granularity` (echoed effective values), `series` (array of `{date, value}` — service-pinned keys) |

```json
{
  "status": 200,
  "message": "Time series retrieved.",
  "success": true,
  "data": {
    "metric": "orders",
    "period": "7d",
    "granularity": "day",
    "series": [
      { "date": "2026-01-01", "value": 3 }
    ]
  }
}
```

# Errors

No error-code field; every error below has Error code `None`. NOTE the abort messages carry trailing periods (verbatim `HttpException` messages).

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated."` (verbatim, with period) | None | No valid token (twin-path tests assert 401 for guests: `dashboard_requires_authentication`, `test_analytics_requires_authentication`) | See 401 example |
| 403 | Authenticated caller lacks `view-analytics` (route pipe OR inline check — either rejects) | `{message: string, status: boolean}` | None | Route pipe → translated `ERROR.PERMISSION_MISSING_PERMISSIONS`; inline check → verbatim `"Forbidden. Missing required permission: view-analytics."` (TWO possible 403 texts on one action — code-verified) | None | Customer without the permission (twin-path tests assert 403: `test_customer_denied_on_analytics` covers this method's path family) | See 403 example |
| 422 | Inline validation fails (missing/unknown `metric`, off-list `period`/`granularity`) | `{message: string, status: boolean, errors: object}` | None | Laravel default | Field → messages | `?metric=invalid` (asserted 422 + `metric` error on the twin path by `validates_time_series_parameters` — same method, shape transfers) | See 422 example |
| 429 | Exceeding `throttle:analytics` (60/min — NOT 400) | `{success: boolean, message: string, data: null}` | None | `__('message.too_many_requests')` | None | Rate limit exceeded | See 429 example |

401 example (note the period):

```json
{
  "message": "Unauthenticated.",
  "status": false
}
```

403 example (inline-check verbatim):

```json
{
  "message": "Forbidden. Missing required permission: view-analytics.",
  "status": false
}
```

422 example:

```json
{
  "message": "The selected metric is invalid.",
  "status": false,
  "errors": {
    "metric": ["The selected metric is invalid."]
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
