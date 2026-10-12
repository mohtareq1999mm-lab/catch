# Endpoint Name

Dashboard – Customer Segmentation (`GET /api/v1/dashboard/customer-segmentation`)

# Description

Returns the customer segmentation snapshot with no inputs and no validation.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/dashboard/customer-segmentation` (NOT the stale `/api/v1/admin/analytics/customer-segmentation` twin — same staleness note as time-series: no current registration; behavior served here) |
| Path composition | `api/v1` prefix + `dashboard/customer-segmentation` from the `Route::middleware(['auth:sanctum', 'throttle:analytics', 'permission:view-analytics', 'lang'])->prefix('dashboard')` group (named `api.admin.analytics.segmentation` — NOTE the name says `segmentation`, not `customer-segmentation`; code-verified) at `packages/marvel/src/Rest/Routes.php:419` |
| Controller action | `App\Http\Controllers\Api\Admin\AnalyticsController@customerSegmentation` → `authorizeAdmin` (view-analytics triple fallback) → `OrderAnalyticsService::getCustomerSegmentation()` passed through UNWRAPPED; NO inputs read, NO validation (code-verified minimal action) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (group + constructor + manual check) |
| Permission | `view-analytics` via route middleware AND inline `authorizeAdmin` (double gate, same permission) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en\|ar` (default `en`) |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:analytics`: 60 requests/minute (NOT 400 — code-verified group difference) |
| Route name | `api.admin.analytics.segmentation` (explicit named route — truncated name, code-verified) |

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | In `['en', 'ar']` | `en`, `ar` | `en` | None | `en` | Selects message locale |

No path/query/body parameters of any kind. No `422` contract. No test found at any level (service or HTTP) for segmentation output — payload keys **Unverified in source** beyond passthrough.

## Request examples

```json
{
  "method": "GET",
  "url": "/api/v1/dashboard/customer-segmentation",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: segmentation returned — HTTP 200

Returned via `ApiResponse::apiResponse('Segmentation retrieved.', 200, true, $data)` (literal message; service result unwrapped).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Literal (untranslated) | Always `"Segmentation retrieved."` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | mixed | Always present on success | Service snapshot, shape **Unverified in source** (no test pins it; documented as passthrough, not a contract) | Service-defined |

```json
{
  "status": 200,
  "message": "Segmentation retrieved.",
  "success": true,
  "data": []
}
```

# Errors

No error-code field; every error below has Error code `None`. No validation, so no `422`.

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
