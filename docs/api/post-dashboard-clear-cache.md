# Endpoint Name

Dashboard – Clear Analytics Cache (`POST /api/v1/dashboard/clear-cache`)

# Description

Clears the analytics service cache unconditionally and returns 200 with no data.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `POST` (only `POST` is registered — `GET` on this path → 405; code-verified) |
| Full path | `/api/v1/dashboard/clear-cache` (NOT the stale `/api/v1/admin/analytics/clear-cache` twin — same staleness note: no current registration; the twin-path `clear_cache_endpoint_works` test asserts 200 + `success: true` on the dead path, and the identical method serves this path) |
| Path composition | `api/v1` prefix + `dashboard/clear-cache` from the `Route::middleware(['auth:sanctum', 'throttle:analytics', 'permission:view-analytics', 'lang'])->prefix('dashboard')` group (named `api.admin.analytics.clear-cache`) at `packages/marvel/src/Rest/Routes.php:421` — inside the SAME group line as its siblings (the group middleware is shared; code-verified) |
| Controller action | `App\Http\Controllers\Api\Admin\AnalyticsController@clearCache` → `authorizeAdmin` (view-analytics triple fallback) → `OrderAnalyticsService::clearCache()` (UNCONDITIONAL — no confirmation, no scope, no dry-run; code-verified) → 200 with NO data key. NOTE the throttle question is settled by position: the shared group line carries `throttle:analytics` (60/min), so this POST is under the analytics bucket, not the admin bucket (code-verified from the group declaration) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (group + constructor + manual check) |
| Permission | `view-analytics` via route middleware AND inline `authorizeAdmin` (double gate, same permission — note: cache-clearing requires only the READ permission, no separate write permission exists; code-verified) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en\|ar` (default `en`) |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:analytics`: 60 requests/minute (NOT 400 — group-verified; operational note: the 60/min bucket is the only throttle on a cache-destructive action) |
| Route name | `api.admin.analytics.clear-cache` (explicit named route — stale-prefix name on the live path) |

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | In `['en', 'ar']` | `en`, `ar` | `en` | None | `en` | Selects message locale |

No path/query/body parameters of any kind. No `422` contract (nothing is validated).

## Request examples

```json
{
  "method": "POST",
  "url": "/api/v1/dashboard/clear-cache",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: cache cleared — HTTP 200 (NO data key)

Returned via `ApiResponse::apiResponse('Analytics cache cleared', 200, true)` with NO data argument. NOTE the message has NO trailing period (`'Analytics cache cleared'` — code-verified verbatim, unlike the other four analytics literals which all end with `.`). Twin-path test asserts 200 + `success: true` via `postJson` (same method — outcome transfers, flagged for the path difference).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Literal (untranslated — NO trailing period) | Always `"Analytics cache cleared"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |

```json
{
  "status": 200,
  "message": "Analytics cache cleared",
  "success": true
}
```

# Errors

No error-code field; every error below has Error code `None`. No validation, so no `422`.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated."` (verbatim, with period) | None | No valid token (twin-path guest tests assert 401 on the analytics family) | See 401 example |
| 403 | Authenticated caller lacks `view-analytics` (route pipe → translated text; inline check → verbatim text) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` OR verbatim `"Forbidden. Missing required permission: view-analytics."` | None | Customer without the permission (twin-path test asserts 403 on this method's family, including `postJson` clear-cache) | See 403 example |
| 405 | `GET` on `/api/v1/dashboard/clear-cache` (only `POST` registered) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` | None | Wrong method (code-traced) | See 405 example |
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

405 example:

```json
{
  "message": "Method Not Allowed",
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
