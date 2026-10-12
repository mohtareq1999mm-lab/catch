# Endpoint Name

Dashboard – Customer Analytics (`GET /api/v1/dashboard/customers`)

# Description

Returns cached customer growth, top-customer rankings, lifetime values, and activity windows.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/dashboard/customers` |
| Path composition | `api/v1` prefix from `packages/marvel/src/Providers/RestAPIServiceProvider.php` + `dashboard` prefix + `customers` from the dashboard group in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:analytics', 'permission:view-analytics', 'lang']` group |
| Controller action | `App\Http\Controllers\Api\General\DashboardController@customerAnalytics` → `App\Services\Dashboard\DashboardService::getCustomerAnalytics` (application layer — not Marvel; this endpoint uses its own `{success, message, data}` envelope, not the Marvel `apiResponse` shape) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `view-analytics` via route-level `permission:view-analytics` middleware (no constructor middleware and no inline check exist on this controller); the method itself performs no authorization check |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` (error responses are JSON for all `/api/*` paths regardless of `Accept`, per `App\Exceptions\Handler::shouldReturnJson`) |
| Optional headers | `lang: en\|ar` (default `en`; any other value is ignored and the locale is left unchanged, per `App\Http\Middleware\CheckLangMiddleware`) |
| Supported request formats | Query string only; no body is read |
| Rate limit | `throttle:analytics`: 60 requests/minute per authenticated user id (or per IP for guests), per `App\Providers\AppServiceProvider::boot` |
| Route name | None defined for this route |

Middleware runs before the method body: an unauthenticated or unauthorized call fails with 401/403 without reaching the service. "Customer" throughout means `users.type = 'user'` (code-verified scope on every query).

## Request inputs

`getCustomerAnalytics(Request $request)` accepts the request but never reads it (code-verified: no `$request` reference in the method body) — query parameters have zero effect on the result. They only alter the outer cache key (`md5($request->fullUrl())`), so distinct query strings cache separate but identical payloads (code-traced).

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token enforced by `auth:sanctum` | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | Must be in `['en', 'ar']` to take effect; other values ignored | `en`, `ar` | `en` | None | `en` | Selects response message locale |
| `Accept` | header | string | Recommended, not strictly required | No | Behaviorally relevant for content negotiation; JSON error shape is still returned for `/api/*` paths | `application/json` | None | None | `application/json` | Requests a JSON response |

There are no effective query parameters, body fields, path parameters, multipart fields, or file uploads. There is no validation, so there is no `422` contract.

## Request examples

Valid request fetching customer analytics:

```json
{
  "method": "GET",
  "url": "/api/v1/dashboard/customers",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: customer-analytics payload — HTTP 200

Returned via plain `response()->json(['success' => true, 'message' => ..., 'data' => $dataCache])` (implicit 200; note: NO `status` key — code-verified envelope difference from every Marvel `apiResponse` endpoint). Verified by `test_customer_analytics_returns_growth_and_segments` (`DashboardTest`: one customer with one completed order → 200, `success: true`, structure `data: [new_vs_returning, monthly_growth, top_customers{by_orders, by_revenue}, customer_lifetime_value, active_customers{last_7_days, last_30_days, last_90_days}]`; the test uses `getJson`, matching this route — no test fix needed). There is exactly one success contract.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `message` | string | Always present | Translated `message.DASHBOARD.CUSTOMER_ANALYTICS_FETCHED` (locale from `lang` header) | `en` → `"Customer analytics fetched successfully."` (note trailing period), `ar` → `"تم جلب تحليلات العملاء بنجاح"` |
| `data` | object | Always present on success | Cached analytics from `DashboardService::getCustomerAnalytics` | See `data` fields below |

`data` fields (code-verified against the service return):

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `data.new_vs_returning` | object | Always present | `{new_customers: customers created in the last 30 days (strict `>`), returning_customers: distinct customers with any order in the last 30 days (any status — code-verified, no status filter)}` | E.g. `{"new_customers": 64, "returning_customers": 41}` |
| `data.monthly_growth` | array of objects | Always present (possibly empty) | Customer signups per `%Y-%m` month over the last 12 months, ascending | Each item: `{month: "YYYY-MM", count: integer}` |
| `data.top_customers.by_orders` | array (max 10) | Always present (possibly empty) | Top customers by completed-order count, descending | Each item: `{id, name, email, orders}` |
| `data.top_customers.by_revenue` | array (max 10) | Always present (possibly empty) | Top customers by completed-order revenue, descending | Each item: `{id, name, email, revenue}` (rounded 2dp) |
| `data.customer_lifetime_value` | array (max 10) | Always present (possibly empty) | Same query/shape as `by_revenue` with the value keyed `lifetime_value` instead of `revenue` (code-verified duplication) | Each item: `{id, name, email, lifetime_value}` (rounded 2dp) |
| `data.active_customers` | object | Always present | Distinct customers with any order (any status — code-verified, no status filter) in the last 7 / 30 / 90 days (strict `>`) | `{last_7_days, last_30_days, last_90_days}` integers |

Currency nuance (code-verified difference from the sales/revenue endpoints): `by_revenue` and `lifetime_value` sum RAW `orders.total_price` — no base-currency conversion is applied here, so multi-currency stores mix units in these figures. Dead code (code-verified): `$customersBefore` (customers created ≥30 days ago) is computed but never returned, and there is NO `total_customers` key in this payload (unlike the overview endpoint). Caching (code-verified, two layers): the service result is cached 300s under the fixed key `'dashboard_customer_analytics'` (request-blind), then the controller caches per-URL (`md5(fullUrl)`) up to 4h via `HasCache::remember` (tagged when the store supports it). `DashboardTest::setUp` flushes the cache, so tests observe fresh computations. There is no cache-bypass parameter.

Complete success example (`en` locale; figures illustrative, keys/message/structure verified):

```json
{
  "success": true,
  "message": "Customer analytics fetched successfully.",
  "data": {
    "new_vs_returning": {
      "new_customers": 64,
      "returning_customers": 41
    },
    "monthly_growth": [
      { "month": "2026-09", "count": 58 },
      { "month": "2026-10", "count": 64 }
    ],
    "top_customers": {
      "by_orders": [
        { "id": 27, "name": "Jane Doe", "email": "jane@example.com", "orders": 9 }
      ],
      "by_revenue": [
        { "id": 27, "name": "Jane Doe", "email": "jane@example.com", "revenue": 1840.5 }
      ]
    },
    "customer_lifetime_value": [
      { "id": 27, "name": "Jane Doe", "email": "jane@example.com", "lifetime_value": 1840.5 }
    ],
    "active_customers": {
      "last_7_days": 22,
      "last_30_days": 41,
      "last_90_days": 97
    }
  }
}
```

# Errors

The implementation has no machine-readable error-code field; every error below has Error code `None`. The 200/401 contracts are test-verified (`test_customer_analytics_returns_growth_and_segments`, `test_unauthenticated_user_cannot_access_dashboard` which loops all 15 dashboard endpoints including `customers`); all other rows are code-verified or code-traced as marked. Note the deliberate contract of `errorResponse`: EVERY caught failure renders 409, never 500.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing, invalid, revoked, or expired Sanctum token (`auth:sanctum` rejects before the controller runs) | `{message: string, status: boolean}` (Handler shape) | None | `"Unauthenticated"` via `AuthenticationException` branch of `App\Exceptions\Handler` | None | Any request without a valid `Authorization: Bearer` token; verified by `test_unauthenticated_user_cannot_access_dashboard` expecting 401 on this exact URI | See 401 example |
| 403 | Authenticated caller lacks the `view-analytics` permission (Spatie `permission:view-analytics` route middleware rejects before the method body runs) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` via `Handler::messageForSpatieUnauthorized` (no permission-name suffix: `display_permission_in_exception` is `false`): `en` → `"User does not have the required permissions."`, `ar` → `"لا يمتلك المستخدم الصلاحيات المطلوبة."` (no customers-specific 403 test exists — code-traced through the shared path) | None | Valid token + user without `view-analytics` | See 403 example |
| 405 | Any HTTP method other than `GET` on `/api/v1/dashboard/customers` (only `Route::get` is registered) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` via `MethodNotAllowedHttpException` branch of `Handler` | None | E.g. `POST`, `PUT`, `PATCH`, `DELETE` to the same URI (code-traced) | See 405 example |
| 409 | ANY failure inside `customerAnalytics` (service, cache, or serialization): `catch (Throwable)` → `errorResponse`, which always responds 409 with `{success: false, message}` (no `data` key) — including non-database failures, by design | `{success: boolean, message: string}` (note: no `data` key and no `status` key, unlike both the success envelope and the rate-limiter shape) | None | `__('message.DASHBOARD.DATABASE_ERROR')` when the throwable is a `QueryException`: `en` → `"A database error occurred. Please check your request and try again."`, `ar` → `"حدث خطأ في قاعدة البيانات. يرجى التحقق من طلبك والمحاولة مرة أخرى."`; otherwise `__('message.ERROR.SOMETHING_WENT_WRONG')`: `en` → `"Something went wrong."`, `ar` → `"حدث خطأ ما"` | None | Any thrown failure (code-traced, no test) | See 409 examples |
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

405 example (wrong method, e.g. `POST /api/v1/dashboard/customers`):

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
