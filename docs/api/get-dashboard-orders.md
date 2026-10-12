# Endpoint Name

Dashboard – Order Analytics (`GET /api/v1/dashboard/orders`)

# Description

Returns cached order timelines, completion rates, and refund rate across three granularities.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/dashboard/orders` |
| Path composition | `api/v1` prefix from `packages/marvel/src/Providers/RestAPIServiceProvider.php` + `dashboard` prefix + `orders` from the dashboard group in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:analytics', 'permission:view-analytics', 'lang']` group |
| Controller action | `App\Http\Controllers\Api\General\DashboardController@orderAnalytics` → `App\Services\Dashboard\DashboardService::getOrderAnalytics` (application layer — not Marvel; this endpoint uses its own `{success, message, data}` envelope, not the Marvel `apiResponse` shape) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `view-analytics` via route-level `permission:view-analytics` middleware (no constructor middleware and no inline check exist on this controller); the method itself performs no authorization check |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` (error responses are JSON for all `/api/*` paths regardless of `Accept`, per `App\Exceptions\Handler::shouldReturnJson`) |
| Optional headers | `lang: en\|ar` (default `en`; any other value is ignored and the locale is left unchanged, per `App\Http\Middleware\CheckLangMiddleware`) |
| Supported request formats | Query string only; no body is read |
| Rate limit | `throttle:analytics`: 60 requests/minute per authenticated user id (or per IP for guests), per `App\Providers\AppServiceProvider::boot` |
| Route name | None defined for this route |

Middleware runs before the method body: an unauthenticated or unauthorized call fails with 401/403 without reaching the service.

## Request inputs

`getOrderAnalytics(Request $request)` accepts the request but never reads it (code-verified: no `$request` reference in the method body) — query parameters have zero effect on the result. They only alter the outer cache key (`md5($request->fullUrl())`), so distinct query strings cache separate but identical payloads (code-traced).

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token enforced by `auth:sanctum` | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | Must be in `['en', 'ar']` to take effect; other values ignored | `en`, `ar` | `en` | None | `en` | Selects response message locale |
| `Accept` | header | string | Recommended, not strictly required | No | Behaviorally relevant for content negotiation; JSON error shape is still returned for `/api/*` paths | `application/json` | None | None | `application/json` | Requests a JSON response |

There are no effective query parameters, body fields, path parameters, multipart fields, or file uploads. There is no validation, so there is no `422` contract.

## Request examples

Valid request fetching order analytics:

```json
{
  "method": "GET",
  "url": "/api/v1/dashboard/orders",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: order-analytics payload — HTTP 200

Returned via plain `response()->json(['success' => true, 'message' => ..., 'data' => $dataCache])` (implicit 200; note: NO `status` key — code-verified envelope difference from every Marvel `apiResponse` endpoint). Verified by `test_order_analytics_returns_timeline_and_success_rate` (200, `success: true`, structure `data: [timeline{daily, weekly, monthly}, success_rate{completed, cancelled, refunded, total}, refund_rate]`) and `test_order_analytics_refund_rate_is_calculated` (approved refund on a completed order → `data.refund_rate > 0`; both tests use `getJson`, matching this route — no test fix needed). There is exactly one success contract.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `message` | string | Always present | Translated `message.DASHBOARD.ORDER_ANALYTICS_FETCHED` (locale from `lang` header) | `en` → `"Order analytics fetched successfully."` (note trailing period), `ar` → `"تم جلب تحليلات الطلبات بنجاح"` |
| `data` | object | Always present on success | Cached analytics from `DashboardService::getOrderAnalytics` | `timeline`, `success_rate`, `refund_rate` — see below |

`data` fields (code-verified against the service return):

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `data.timeline.daily` | array of objects | Always present (possibly empty) | Per-calendar-date orders over the last 30 days (strict `>`) | Each item: `{date: "YYYY-MM-DD", orders: int, revenue: float}`. Revenue uses base-safe sums but covers ALL statuses (no status filter — code-verified difference from the revenue endpoints) |
| `data.timeline.weekly` | array of objects | Always present (possibly empty) | Per-`YEARWEEK` orders over the last 6 months, ascending | Each item: `{week: int (e.g. `202641`), orders: int, revenue: float}` — all statuses |
| `data.timeline.monthly` | array of objects | Always present (possibly empty) | Per-`%Y-%m` orders over the last 2 years, ascending | Each item: `{month: "YYYY-MM", orders: int, revenue: float}` — all statuses |
| `data.success_rate` | object | Always present | `{completed: completed/all×100, cancelled: cancelled/all×100, refunded: approved-refund distinct orders/completed×100, total: all orders}` — all rounded 2dp, all guarded to `0` on empty denominators (code-verified) | E.g. `{"completed": 50.0, "cancelled": 50.0, "refunded": 0.0, "total": 2}` for the test's two orders |
| `data.refund_rate` | number | Always present | Duplicates `success_rate.refunded` (code-verified duplication): approved-refund distinct `order_id` count ÷ completed orders × 100 (test-verified `> 0` with one approved refund) | E.g. `100.0` |

Refunded counting nuance (code-verified): only `refunds.status = 'approved'` rows count, deduplicated by `order_id` — pending/rejected refunds are invisible here. Date functions are MySQL-canonical (`DATE()`, `YEARWEEK`, `DATE_FORMAT` — per the `dateFormat` helper comment, SQLite support was removed project-wide). Caching (code-verified, two layers): the service result is cached 300s under the fixed key `'dashboard_order_analytics'` (request-blind), then the controller caches per-URL (`md5(fullUrl)`) up to 4h via `HasCache::remember` (tagged when the store supports it). `DashboardTest::setUp` flushes the cache, so tests observe fresh computations. There is no cache-bypass parameter.

Complete success example (`en` locale; figures illustrative for the two test orders, keys/message/structure verified):

```json
{
  "success": true,
  "message": "Order analytics fetched successfully.",
  "data": {
    "timeline": {
      "daily": [
        { "date": "2026-10-10", "orders": 2, "revenue": 400.0 }
      ],
      "weekly": [
        { "week": 202641, "orders": 2, "revenue": 400.0 }
      ],
      "monthly": [
        { "month": "2026-10", "orders": 2, "revenue": 400.0 }
      ]
    },
    "success_rate": {
      "completed": 50.0,
      "cancelled": 50.0,
      "refunded": 0.0,
      "total": 2
    },
    "refund_rate": 0.0
  }
}
```

# Errors

The implementation has no machine-readable error-code field; every error below has Error code `None`. The 200/401 contracts are test-verified (both order tests, `test_unauthenticated_user_cannot_access_dashboard` which loops all 15 dashboard endpoints including `orders`); all other rows are code-verified or code-traced as marked. Note the deliberate contract of `errorResponse`: EVERY caught failure renders 409, never 500.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing, invalid, revoked, or expired Sanctum token (`auth:sanctum` rejects before the controller runs) | `{message: string, status: boolean}` (Handler shape) | None | `"Unauthenticated"` via `AuthenticationException` branch of `App\Exceptions\Handler` | None | Any request without a valid `Authorization: Bearer` token; verified by `test_unauthenticated_user_cannot_access_dashboard` expecting 401 on this exact URI | See 401 example |
| 403 | Authenticated caller lacks the `view-analytics` permission (Spatie `permission:view-analytics` route middleware rejects before the method body runs) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` via `Handler::messageForSpatieUnauthorized` (no permission-name suffix: `display_permission_in_exception` is `false`): `en` → `"User does not have the required permissions."`, `ar` → `"لا يمتلك المستخدم الصلاحيات المطلوبة."` (no orders-specific 403 test exists — code-traced through the shared path) | None | Valid token + user without `view-analytics` | See 403 example |
| 405 | Any HTTP method other than `GET` on `/api/v1/dashboard/orders` (only `Route::get` is registered) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` via `MethodNotAllowedHttpException` branch of `Handler` | None | E.g. `POST`, `PUT`, `PATCH`, `DELETE` to the same URI (code-traced) | See 405 example |
| 409 | ANY failure inside `orderAnalytics` (service, cache, or serialization): `catch (Throwable)` → `errorResponse`, which always responds 409 with `{success: false, message}` (no `data` key) — including non-database failures, by design | `{success: boolean, message: string}` (note: no `data` key and no `status` key, unlike both the success envelope and the rate-limiter shape) | None | `__('message.DASHBOARD.DATABASE_ERROR')` when the throwable is a `QueryException`: `en` → `"A database error occurred. Please check your request and try again."`, `ar` → `"حدث خطأ في قاعدة البيانات. يرجى التحقق من طلبك والمحاولة مرة أخرى."`; otherwise `__('message.ERROR.SOMETHING_WENT_WRONG')`: `en` → `"Something went wrong."`, `ar` → `"حدث خطأ ما"` | None | Any thrown failure (code-traced, no test) | See 409 examples |
| 429 | Exceeding `throttle:analytics` (60/min per user id or IP) | `{success: boolean, message: string, data: null}` (rate-limiter shape) | None | `__('message.too_many_requests')`: `en` → `"Too many requests. Please try again later."`, `ar` → `"طلبات كثيرة جدًا. يرجى المحاولة لاحقًا."` | None | Rate limit exceeded for the caller key | See 429 example |

There is no 422 contract (no validation) and no 500 contract (all failures collapse to 409 by design).

401 example (no or invalid token):

```json
{
  "message": "Unauthenticated",
  "status": false
}
```

403 example (authenticated without `view-analytics` permission; `en` locale):

```json
{
  "message": "User does not have the required permissions.",
  "status": false
}
```

405 example (wrong method, e.g. `POST /api/v1/dashboard/orders`):

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
