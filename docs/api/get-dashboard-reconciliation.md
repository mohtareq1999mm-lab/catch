# Endpoint Name

Dashboard – Reconciliation (`GET /api/v1/dashboard/reconciliation`)

# Description

Returns cached payment-reconciliation coverage and mismatch counts with the last run timestamp.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/dashboard/reconciliation` |
| Path composition | `api/v1` prefix from `packages/marvel/src/Providers/RestAPIServiceProvider.php` + `dashboard` prefix + `reconciliation` from the dashboard group in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:analytics', 'permission:view-analytics', 'lang']` group |
| Controller action | `App\Http\Controllers\Api\General\DashboardController@reconciliation` → `App\Services\Dashboard\DashboardService::getReconciliationSummary` (application layer — not Marvel; this endpoint uses its own `{success, message, data}` envelope, not the Marvel `apiResponse` shape) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `view-analytics` via route-level `permission:view-analytics` middleware (no constructor middleware and no inline check exist on this controller); the method itself performs no authorization check |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` (error responses are JSON for all `/api/*` paths regardless of `Accept`, per `App\Exceptions\Handler::shouldReturnJson`) |
| Optional headers | `lang: en\|ar` (default `en`; any other value is ignored and the locale is left unchanged, per `App\Http\Middleware\CheckLangMiddleware`) |
| Supported request formats | No meaningful input at all (see below) |
| Rate limit | `throttle:analytics`: 60 requests/minute per authenticated user id (or per IP for guests), per `App\Providers\AppServiceProvider::boot` |
| Route name | None defined for this route |

Middleware runs before the method body: an unauthenticated or unauthorized call fails with 401/403 without reaching the service.

## Request inputs

Neither layer reads the request: `getReconciliationSummary()` takes NO arguments, and the outer cache key is the FIXED string `md5('reconciliation')` (not the URL — code-verified difference from every sibling endpoint). Query strings, bodies, and headers (other than auth/`Accept`) have zero effect of any kind — not even on cache partitioning.

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token enforced by `auth:sanctum` | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | Must be in `['en', 'ar']` to take effect; other values ignored | `en`, `ar` | `en` | None | `ar` | Selects response message locale (test-verified: `ar` yields the Arabic message below) |
| `Accept` | header | string | Recommended, not strictly required | No | Behaviorally relevant for content negotiation; JSON error shape is still returned for `/api/*` paths | `application/json` | None | None | `application/json` | Requests a JSON response |

There are no effective inputs of any kind. There is no validation, so there is no `422` contract.

## Request examples

Valid request fetching the reconciliation summary:

```json
{
  "method": "GET",
  "url": "/api/v1/dashboard/reconciliation",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: reconciliation payload — HTTP 200

Returned via plain `response()->json(['success' => true, 'message' => ..., 'data' => $dataCache])` (implicit 200; note: NO `status` key — code-verified envelope difference from every Marvel `apiResponse` endpoint). Verified by `test_dashboard_returns_readable_arabic_reconciliation_message` (`DashboardTest`: `lang: ar` header → 200, message exactly `'تم جلب ملخص التسوية بنجاح'` with the raw key asserted ABSENT — proving end-to-end translation resolution; uses `getJson`, matching this route — no test fix needed). There is exactly one success contract.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `message` | string | Always present | Translated `message.DASHBOARD.RECONCILIATION_FETCHED` (locale from `lang` header; `ar` rendering test-verified exactly) | `en` → `"Reconciliation summary fetched successfully."` (note trailing period), `ar` → `"تم جلب ملخص التسوية بنجاح"` |
| `data` | object | Always present on success | Reconciliation aggregates from `DashboardService::getReconciliationSummary` | See `data` fields below |

`data` fields (code-verified against the service return):

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `data.total_checked` | integer | Always present | Transactions with a non-null `gateway_transaction_id` and `status != 'failed'` (i.e. provider-referenced, non-failed attempts — includes pending/succeeded alike; code-verified) | E.g. `0` on an empty ledger |
| `data.total_mismatches` | integer | Always present | All `payment_reconciliation_results` rows regardless of resolution | E.g. `0` |
| `data.pending_mismatches` | integer | Always present | Rows matching the `unresolved()` scope | E.g. `0` |
| `data.resolved_mismatches` | integer | Always present | Rows matching the `resolved()` scope | E.g. `0` |
| `data.last_run` | string (datetime) or null | Always present | `created_at` of the latest reconciliation-result row, or `null` when the table is empty (null-safe operator — code-verified; JSON `null`, key still present) | E.g. `"2026-10-10T12:00:00.000000Z"` or `null` |

Caching (code-verified): unlike every sibling, the service performs NO `Cache::remember` — only the controller's per-key (`md5('reconciliation')`, fixed) up-to-4h `HasCache::remember` applies (tagged when the store supports it). `DashboardTest::setUp` flushes the cache, so tests observe fresh queries. There is no cache-bypass parameter.

Complete success example (`en` locale; values illustrative, keys/message verified):

```json
{
  "success": true,
  "message": "Reconciliation summary fetched successfully.",
  "data": {
    "total_checked": 118,
    "total_mismatches": 3,
    "pending_mismatches": 1,
    "resolved_mismatches": 2,
    "last_run": "2026-10-10T12:00:00.000000Z"
  }
}
```

# Errors

The implementation has no machine-readable error-code field; every error below has Error code `None`. The 200/401/403 contracts are test-verified (`test_dashboard_returns_readable_arabic_reconciliation_message`, `test_unauthenticated_user_cannot_access_dashboard` which loops all 15 dashboard endpoints including `reconciliation`, `test_customer_without_view_analytics_permission_cannot_access_dashboard` which asserts 403 on this exact URI for a permission-less customer); all other rows are code-verified or code-traced as marked. Note the deliberate contract of `errorResponse`: EVERY caught failure renders 409, never 500.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing, invalid, revoked, or expired Sanctum token (`auth:sanctum` rejects before the controller runs) | `{message: string, status: boolean}` (Handler shape) | None | `"Unauthenticated"` via `AuthenticationException` branch of `App\Exceptions\Handler` | None | Any request without a valid `Authorization: Bearer` token; verified by `test_unauthenticated_user_cannot_access_dashboard` expecting 401 on this exact URI | See 401 example |
| 403 | Authenticated caller lacks the `view-analytics` permission (Spatie `permission:view-analytics` route middleware rejects before the method body runs) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` via `Handler::messageForSpatieUnauthorized` (no permission-name suffix: `display_permission_in_exception` is `false`): `en` → `"User does not have the required permissions."`, `ar` → `"لا يمتلك المستخدم الصلاحيات المطلوبة."` | None | Valid token + permission-less customer; verified by `test_customer_without_view_analytics_permission_cannot_access_dashboard` asserting 403 on this exact URI | See 403 example |
| 405 | Any HTTP method other than `GET` on `/api/v1/dashboard/reconciliation` (only `Route::get` is registered) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` via `MethodNotAllowedHttpException` branch of `Handler` | None | E.g. `POST`, `PUT`, `PATCH`, `DELETE` to the same URI (code-traced) | See 405 example |
| 409 | ANY failure inside `reconciliation` (service, cache, or serialization): `catch (Throwable)` → `errorResponse`, which always responds 409 with `{success: false, message}` (no `data` key) — including non-database failures, by design | `{success: boolean, message: string}` (note: no `data` key and no `status` key, unlike both the success envelope and the rate-limiter shape) | None | `__('message.DASHBOARD.DATABASE_ERROR')` when the throwable is a `QueryException`: `en` → `"A database error occurred. Please check your request and try again."`, `ar` → `"حدث خطأ في قاعدة البيانات. يرجى التحقق من طلبك والمحاولة مرة أخرى."`; otherwise `__('message.ERROR.SOMETHING_WENT_WRONG')`: `en` → `"Something went wrong."`, `ar` → `"حدث خطأ ما"` | None | Any thrown failure (code-traced, no test) | See 409 examples |
| 429 | Exceeding `throttle:analytics` (60/min per user id or IP) | `{success: boolean, message: string, data: null}` (rate-limiter shape) | None | `__('message.too_many_requests')`: `en` → `"Too many requests. Please try again later."`, `ar` → `"طلبات كثيرة جدًا. يرجى المحاولة لاحقًا."` | None | Rate limit exceeded for the caller key | See 429 example |

There is no 422 contract (no validation) and no 500 contract (all failures collapse to 409 by design).

401 example (no or invalid token):

```json
{
  "message": "Unauthenticated",
  "status": false
}
```

403 example (permission-less customer; default locale):

```json
{
  "message": "User does not have the required permissions.",
  "status": false
}
```

405 example (wrong method, e.g. `POST /api/v1/dashboard/reconciliation`):

```json
{
  "message": "Method Not Allowed",
  "status": false
}
```

409 example (database failure; `en` locale):

```json
{
  "success": false,
  "message": "A database error occurred. Please check your request and try again."
}
```

409 example (non-database failure; `en` locale):

```json
{
  "success": false,
  "message": "Something went wrong."
}
```

429 example (rate limit exceeded):

```json
{
  "success": false,
  "message": "Too many requests. Please try again later.",
  "data": null
}
```
