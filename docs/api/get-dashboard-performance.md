# Endpoint Name

Dashboard – Performance (`GET /api/v1/dashboard/performance`)

# Description

Returns SLA compliance and stuck-order bottlenecks for a silently defaulted period.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/dashboard/performance` (NOT the stale `/api/v1/admin/analytics/performance` twin — same staleness note: no current registration; behavior served here) |
| Path composition | `api/v1` prefix + `dashboard/performance` from the `Route::middleware(['auth:sanctum', 'throttle:analytics', 'permission:view-analytics', 'lang'])->prefix('dashboard')` group (named `api.admin.analytics.performance`) at `packages/marvel/src/Rest/Routes.php:420` |
| Controller action | `App\Http\Controllers\Api\Admin\AnalyticsController@performance` → `authorizeAdmin` (view-analytics triple fallback) → `$period = input('period', '24h')` with NO validation (unknown values fall through `getPeriodStart`'s `default` arm to 24h silently — code-verified, unlike time-series which 422s); probes the `order_performance_metrics` view (?->get in try/catch — missing view → `hasView=false`, NOT an error); when present: SLA aggregates (`sla_status`, count, avg delivery minutes) since period start + stuck orders (non-delivered/cancelled, created > 24h ago) grouped by status with `avg_hours_stuck` HARDCODED to `0` on every row (code-verified wart — the field is always zero regardless of reality); when absent: both arrays empty, still 200 |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (group + constructor + manual check) |
| Permission | `view-analytics` via route middleware AND inline `authorizeAdmin` (double gate, same permission) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en\|ar` (default `en`) |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:analytics`: 60 requests/minute (NOT 400 — code-verified group difference) |
| Route name | `api.admin.analytics.performance` (explicit named route) |

## Request inputs

No validation runs — NOTHING here 422s (unknown `period` degrades to 24h instead).

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | In `['en', 'ar']` | `en`, `ar` | `en` | None | `en` | Selects message locale |
| `period` | query | string | Optional | No | Unvalidated; `1h/24h/7d/30d/90d` map to exact starts, ANYTHING else → 24h via the `default` arm (code-verified silent fallback) | Known windows (or anything — degrades) | `24h` | None | `"24h"` | Lookback window |

## Request examples

```json
{
  "method": "GET",
  "url": "/api/v1/dashboard/performance?period=24h",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: performance returned — HTTP 200 (ALWAYS, even without the metrics view)

Returned via `ApiResponse::apiResponse('Performance retrieved.', 200, true, ['period' => $period, 'sla_compliance' => ..., 'bottlenecks' => ...])` (literal message; the ECHOED `period` is the raw input, not the resolved default — an unknown `?period=bogus` echoes `"bogus"` while querying 24h; code-verified). Structure (`data.period/sla_compliance/bottlenecks`) asserted by `performance_endpoint_returns_sla_data` on the stale twin path — same method, shape transfers (flagged).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Literal (untranslated) | Always `"Performance retrieved."` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | Performance payload (keys verified in controller + test) | `period` (echoed input), `sla_compliance` (array), `bottlenecks` (array) |
| `data.sla_compliance[]` | object | Possibly empty (view missing → `[]`) | Per-`sla_status` aggregates from the metrics view | `sla_status`, `count`, `avg_delivery_time` (keys verified; value formats **Unverified in source**) |
| `data.bottlenecks[]` | object | Possibly empty (view missing → `[]`) | Per-status stuck-order counts; `avg_hours_stuck` is ALWAYS `0` (hardcoded post-map — code-verified wart, do not trust as a measurement) | `status`, `stuck_count`, `avg_hours_stuck: 0` |

```json
{
  "status": 200,
  "message": "Performance retrieved.",
  "success": true,
  "data": {
    "period": "24h",
    "sla_compliance": [],
    "bottlenecks": []
  }
}
```

# Errors

No error-code field; every error below has Error code `None`. No validation, so no `422` (unknown periods degrade; missing views empty out — neither errors).

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated."` (verbatim, with period) | None | No valid token (twin-path guest tests assert 401 on the analytics family) | See 401 example |
| 403 | Authenticated caller lacks `view-analytics` (route pipe → translated text; inline check → verbatim text) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` OR verbatim `"Forbidden. Missing required permission: view-analytics."` | None | Customer without the permission (twin-path test asserts 403 on this method's family) | See 403 example |
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

429 example:

```json
{
  "success": false,
  "message": "Too many requests. Please try again later.",
  "data": null
}
```
