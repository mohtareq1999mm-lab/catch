# Endpoint Name

Dashboard – Sales Analytics (`GET /api/v1/dashboard/sales`)

# Description

Returns cached sales comparisons, average order value, and revenue splits by payment and fulfillment.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/dashboard/sales` |
| Path composition | `api/v1` prefix from `packages/marvel/src/Providers/RestAPIServiceProvider.php` + `dashboard` prefix + `sales` from the dashboard group in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:analytics', 'permission:view-analytics', 'lang']` group |
| Controller action | `App\Http\Controllers\Api\General\DashboardController@salesAnalytics` → `App\Services\Dashboard\DashboardService::getSalesAnalytics` (application layer — not Marvel; this endpoint uses its own `{success, message, data}` envelope, not the Marvel `apiResponse` shape) |
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

`getSalesAnalytics(Request $request)` accepts the request but never reads it (code-verified: no `$request` reference in the method body) — query parameters have zero effect on the result. They only alter the outer cache key (`md5($request->fullUrl())`), so distinct query strings cache separate but identical payloads (code-traced).

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token enforced by `auth:sanctum` | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | Must be in `['en', 'ar']` to take effect; other values ignored | `en`, `ar` | `en` | None | `en` | Selects response message locale |
| `Accept` | header | string | Recommended, not strictly required | No | Behaviorally relevant for content negotiation; JSON error shape is still returned for `/api/*` paths | `application/json` | None | None | `application/json` | Requests a JSON response |

There are no effective query parameters, body fields, path parameters, multipart fields, or file uploads. There is no validation, so there is no `422` contract.

## Request examples

Valid request fetching sales analytics:

```json
{
  "method": "GET",
  "url": "/api/v1/dashboard/sales",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: sales-analytics payload — HTTP 200

Returned via plain `response()->json(['success' => true, 'message' => ..., 'data' => $dataCache])` (implicit 200; note: NO `status` key — code-verified envelope difference from every Marvel `apiResponse` endpoint). Verified by `test_sales_analytics_returns_daily_comparison` (200, `success: true`, structure `data: [daily_revenue{today, yesterday, last_7_days, last_30_days}, revenue_comparison, average_order_value, revenue_by_payment_method]`) and `test_sales_analytics_shows_revenue_by_payment_method` (stripe + paypal methods present; both tests use `getJson`, matching this route — no test fix needed). There is exactly one success contract.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `message` | string | Always present | Translated `message.DASHBOARD.SALES_ANALYTICS_FETCHED` (locale from `lang` header) | `en` → `"Sales analytics fetched successfully."` (note trailing period), `ar` → `"تم جلب تحليلات المبيعات بنجاح"` |
| `data` | object | Always present on success | Cached analytics from `DashboardService::getSalesAnalytics` (all revenue figures completed-orders only, base-currency-safe sums) | See `data` fields below |

`data` fields (code-verified against the service return):

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `data.daily_revenue` | object | Always present | Period sums: `today` (calendar date), `yesterday`, `last_7_days` / `last_30_days` (strict `created_at > now − N days`) | `{today, yesterday, last_7_days, last_30_days}` floats rounded 2dp |
| `data.revenue_comparison` | object | Always present | Three comparisons, each `{period values…, change}` where `change` = `percentageChange(previous, current)`: `100` when previous is 0 and current > 0, `0` when both are 0, else `round((current − previous) / previous × 100, 2)` (negative when declining — code-verified) | `today_vs_yesterday{today, yesterday, change}`, `this_month_vs_last_month{this_month, last_month, change}`, `this_year_vs_last_year{this_year, last_year, change}` |
| `data.average_order_value` | number | Always present | All-time completed revenue ÷ completed order count, rounded 2dp (`0` when no completed orders — code-verified guard) | E.g. `150.0` |
| `data.revenue_by_payment_method` | array of objects | Always present (possibly empty) | Completed revenue grouped by transaction `payment_method` (`[{method, total}]`, totals rounded 2dp; test-verified stripe/paypal presence) | E.g. `[{"method": "stripe", "total": 300.0}]` |
| `data.revenue_by_fulfillment_type` | array of objects | Always present (possibly empty) | Completed revenue grouped by order `fulfillment_type` (`[{fulfillment_type, total}]`; NULL/empty types reported as `'delivery'` — code-verified fallback) | E.g. `[{"fulfillment_type": "delivery", "total": 800.0}]` |
| `data.revenue_currency` | string | Always present | Current store base currency code (uppercased; config fallback on service failure — code-verified) | E.g. `"USD"` |
| `data.revenue_by_base_currency` | object (map code → number) | Always present (possibly empty) | All-time completed revenue bucketed by base era; NULL-base legacy rows → `'UNKNOWN'` | E.g. `{"USD": 800.0}` |
| `data.mixed_base_eras` | boolean | Always present | True when more than one nonzero base bucket exists | `true`/`false` |

Caching (code-verified, two layers): the service result is cached 300s under the fixed key `'dashboard_sales_analytics'` (request-blind), then the controller caches per-URL (`md5(fullUrl)`) up to 4h via `HasCache::remember` (tagged when the store supports it). `DashboardTest::setUp` flushes the cache, so tests observe fresh computations. There is no cache-bypass parameter.

Complete success example (`en` locale; figures illustrative, keys/message/structure verified):

```json
{
  "success": true,
  "message": "Sales analytics fetched successfully.",
  "data": {
    "daily_revenue": {
      "today": 300.0,
      "yesterday": 0.0,
      "last_7_days": 300.0,
      "last_30_days": 300.0
    },
    "revenue_comparison": {
      "today_vs_yesterday": { "today": 300.0, "yesterday": 0.0, "change": 100.0 },
      "this_month_vs_last_month": { "this_month": 300.0, "last_month": 0.0, "change": 100.0 },
      "this_year_vs_last_year": { "this_year": 300.0, "last_year": 0.0, "change": 100.0 }
    },
    "average_order_value": 150.0,
    "revenue_by_payment_method": [
      { "method": "stripe", "total": 300.0 }
    ],
    "revenue_by_fulfillment_type": [
      { "fulfillment_type": "delivery", "total": 300.0 }
    ],
    "revenue_currency": "USD",
    "revenue_by_base_currency": { "USD": 300.0 },
    "mixed_base_eras": false
  }
}
```

# Errors

The implementation has no machine-readable error-code field; every error below has Error code `None`. The 200/401 contracts are test-verified (both sales tests, `test_unauthenticated_user_cannot_access_dashboard` which loops all 15 dashboard endpoints including `sales`); all other rows are code-verified or code-traced as marked. Note the deliberate contract of `errorResponse`: EVERY caught failure renders 409, never 500.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing, invalid, revoked, or expired Sanctum token (`auth:sanctum` rejects before the controller runs) | `{message: string, status: boolean}` (Handler shape) | None | `"Unauthenticated"` via `AuthenticationException` branch of `App\Exceptions\Handler` | None | Any request without a valid `Authorization: Bearer` token; verified by `test_unauthenticated_user_cannot_access_dashboard` expecting 401 on this exact URI | See 401 example |
| 403 | Authenticated caller lacks the `view-analytics` permission (Spatie `permission:view-analytics` route middleware rejects before the method body runs) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` via `Handler::messageForSpatieUnauthorized` (no permission-name suffix: `display_permission_in_exception` is `false`): `en` → `"User does not have the required permissions."`, `ar` → `"لا يمتلك المستخدم الصلاحيات المطلوبة."` (no sales-specific 403 test exists — code-traced through the shared path) | None | Valid token + user without `view-analytics` | See 403 example |
| 405 | Any HTTP method other than `GET` on `/api/v1/dashboard/sales` (only `Route::get` is registered) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` via `MethodNotAllowedHttpException` branch of `Handler` | None | E.g. `POST`, `PUT`, `PATCH`, `DELETE` to the same URI (code-traced) | See 405 example |
| 409 | ANY failure inside `salesAnalytics` (service, cache, or serialization): `catch (Throwable)` → `errorResponse`, which always responds 409 with `{success: false, message}` (no `data` key) — including non-database failures, by design | `{success: boolean, message: string}` (note: no `data` key and no `status` key, unlike both the success envelope and the rate-limiter shape) | None | `__('message.DASHBOARD.DATABASE_ERROR')` when the throwable is a `QueryException`: `en` → `"A database error occurred. Please check your request and try again."`, `ar` → `"حدث خطأ في قاعدة البيانات. يرجى التحقق من طلبك والمحاولة مرة أخرى."`; otherwise `__('message.ERROR.SOMETHING_WENT_WRONG')`: `en` → `"Something went wrong."`, `ar` → `"حدث خطأ ما"` | None | Any thrown failure (code-traced, no test) | See 409 examples |
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

405 example (wrong method, e.g. `POST /api/v1/dashboard/sales`):

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
