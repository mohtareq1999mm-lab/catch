# Endpoint Name

Dashboard – Top Customers (`GET /api/v1/dashboard/top-customers`)

# Description

Returns the top customers by lifetime value with a silently clamped limit and no validation.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/dashboard/top-customers` (NOT the stale `/api/v1/admin/analytics/top-customers` twin — same staleness note as time-series: no current registration, tests target the dead path; behavior served here) |
| Path composition | `api/v1` prefix + `dashboard/top-customers` from the `Route::middleware(['auth:sanctum', 'throttle:analytics', 'permission:view-analytics', 'lang'])->prefix('dashboard')` group (named `api.admin.analytics.top-customers`) at `packages/marvel/src/Rest/Routes.php:418` |
| Controller action | `App\Http\Controllers\Api\Admin\AnalyticsController@topCustomers` → `authorizeAdmin` (view-analytics triple fallback) → `$limit = min(max((int) $request->input('limit', 10), 1), 100)` then `OrderAnalyticsService::getTopCustomers($limit)`; NO validation — garbage clamps silently (`abc` → `(int) 0` → `1`; `-5` → `1`; `9999` → `100`; code-verified); ranking by lifetime value DESC at service level (`identifies_top_customers`: first row is the highest spender with `user_id` + `lifetime_value` keys pinned) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (group + constructor + manual check) |
| Permission | `view-analytics` via route middleware AND inline `authorizeAdmin` (double gate, same permission) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en\|ar` (default `en`) |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:analytics`: 60 requests/minute (NOT 400 — code-verified group difference) |
| Route name | `api.admin.analytics.top-customers` (explicit named route — stale-prefix name on the live path) |

## Request inputs

No validation runs — NOTHING here 422s (the clamp replaces rejection).

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | In `['en', 'ar']` | `en`, `ar` | `en` | None | `en` | Selects message locale |
| `limit` | query | integer-ish | Optional | No | Clamped `min(max((int) input, 1), 100)` — never rejected (code-verified) | Effective `1`–`100` | `10` | None | `10` | Max rows |

## Request examples

```json
{
  "method": "GET",
  "url": "/api/v1/dashboard/top-customers?limit=10",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: ranking returned — HTTP 200

Returned via `ApiResponse::apiResponse('Top customers retrieved.', 200, true, $data)` (literal message; the service array passes through UNWRAPPED — `data` IS the list, not an object; code-verified). Row keys `user_id` + `lifetime_value` pinned at service level; any further row keys are **Unverified in source**.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Literal (untranslated) | Always `"Top customers retrieved."` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | array | Always present (possibly empty) | Ranked customers, highest lifetime value first (service-verified ordering) | Row: `user_id`, `lifetime_value` (pinned) + unverified companions |

```json
{
  "status": 200,
  "message": "Top customers retrieved.",
  "success": true,
  "data": [
    { "user_id": 7, "lifetime_value": 1000 }
  ]
}
```

# Errors

No error-code field; every error below has Error code `None`. No validation, so no `422` (bad `limit` clamps instead).

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated."` (verbatim, with period) | None | No valid token (twin-path guest tests assert 401 on the analytics family) | See 401 example |
| 403 | Authenticated caller lacks `view-analytics` (route pipe → translated text; inline check → verbatim text — two possible 403 texts, code-verified) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` OR verbatim `"Forbidden. Missing required permission: view-analytics."` | None | Customer without the permission (twin-path test asserts 403 on this method's family) | See 403 example |
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
