# Endpoint Name

Dashboard – Overview (`GET /api/v1/dashboard/overview`)

# Description

Returns cached store-wide totals for revenue, refunds, orders, products, and customers.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/dashboard/overview` |
| Path composition | `api/v1` prefix from `packages/marvel/src/Providers/RestAPIServiceProvider.php` + `dashboard` prefix + `overview` from the dashboard group in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:analytics', 'permission:view-analytics', 'lang']` group |
| Controller action | `App\Http\Controllers\Api\General\DashboardController@overview` → `App\Services\Dashboard\DashboardService::getOverview` (application layer — not Marvel; this endpoint uses its own `{success, message, data}` envelope, not the Marvel `apiResponse` shape) |
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

`getOverview(Request $request)` accepts the request but never reads it (code-verified: no `$request` reference in the method body) — query parameters have zero effect on the result. They only alter the outer cache key (`md5($request->fullUrl())`), so distinct query strings cache separate but identical payloads (code-traced).

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token enforced by `auth:sanctum` | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | Must be in `['en', 'ar']` to take effect; other values ignored | `en`, `ar` | `en` | None | `en` | Selects response message locale |
| `Accept` | header | string | Recommended, not strictly required | No | Behaviorally relevant for content negotiation; JSON error shape is still returned for `/api/*` paths | `application/json` | None | None | `application/json` | Requests a JSON response |

There are no effective query parameters, body fields, path parameters, multipart fields, or file uploads. There is no validation, so there is no `422` contract.

## Request examples

Valid request fetching the overview:

```json
{
  "method": "GET",
  "url": "/api/v1/dashboard/overview",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: overview payload — HTTP 200

Returned via plain `response()->json(['success' => true, 'message' => ..., 'data' => $dataCache])` (implicit 200; note: NO `status` key — code-verified envelope difference from every Marvel `apiResponse` endpoint). HTTP-verified by `test_overview_returns_expected_structure` (`DashboardTest`: two completed orders of 500 + 300 plus one pending → 200, `success: true`, structure `data: [total_revenue, todays_revenue, total_refunds, total_orders, total_products, total_customers, new_customers]`, `data.total_revenue == 800.0` proving pending orders are excluded; the test uses `getJson`, matching this route) and `finance_api_buckets_and_cache_degradation` (`FinancialCurrencyEndToEndTest`: seeded E2E admin holding the `view-analytics` permission → 200 + `success: true`). Service-level values verified by `scenario_1_usd_only_orders_report_single_currency_buckets` (`revenue_currency === 'USD'`, `revenue_by_base_currency === ['USD' => 150.0]`, `mixed_base_eras === false`, `total_revenue == 150.0`) and the multi-era scenarios (`mixed_base_eras === true` with `USD`+`SAR` buckets). There is exactly one success contract. Similarly-named but UNRELATED coverage (different endpoint/service, not this route): `AnalyticsAPITest` exercises `GET /api/v1/admin/analytics/dashboard`, and `AnalyticsServiceTest::calculates_dashboard_overview_correctly` exercises `OrderAnalyticsService::getDashboardOverview` (which counts `delivered` orders, while this service counts `completed`) — neither covers this endpoint.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `message` | string | Always present | Translated `message.DASHBOARD.OVERVIEW_FETCHED` (locale from `lang` header) | `en` → `"Dashboard overview fetched successfully."` (note trailing period), `ar` → `"تم جلب نظرة عامة على لوحة التحكم بنجاح"` |
| `data` | object | Always present on success | Cached overview aggregates from `DashboardService::getOverview` | See `data` fields below |

`data` fields (code-verified against the service return):

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `data.total_revenue` | number (float, 2dp) | Always present | Sum of completed-order totals in base currency (`COALESCE(converted_total_price, total_price)` — never raw `total_price` across currencies, per the service's own invariant comment) | E.g. `12500.75` |
| `data.todays_revenue` | number (float, 2dp) | Always present | Same sum restricted to orders created within the last day | E.g. `320.5` |
| `data.total_refunds` | number (float, 2dp) | Always present | Sum of marketplace `refunds.amount` | E.g. `410.0` |
| `data.total_orders` | integer | Always present | Order row count | E.g. `842` |
| `data.total_products` | integer | Always present | Product row count | E.g. `196` |
| `data.total_customers` | integer | Always present | Users with `type = 'user'` | E.g. `1530` |
| `data.new_customers` | integer | Always present | `type = 'user'` rows created within the last 30 days | E.g. `64` |
| `data.revenue_currency` | string | Always present | Current store base currency code (uppercased; falls back to `shop.default_currency`, default `'USD'`, when the currency service fails — code-verified) | E.g. `"USD"` |
| `data.revenue_by_base_currency` | object (map code → number) | Always present (possibly empty — degrades to `{}` when snapshot columns are missing; the endpoint itself never fails for this — code-verified try/catch) | Completed revenue bucketed by base-currency era, including `'UNKNOWN'` legacy rows | E.g. `{"USD": 12500.75}` |
| `data.mixed_base_eras` | boolean | Always present | True when more than one nonzero revenue bucket exists (i.e. the scalar mixes currency units) | `true`/`false` |
| `data.refund_by_currency` | object (map code → number) | Always present | Marketplace refunds bucketed by currency | E.g. `{"USD": 410.0}` |
| `data.gateway_refund_by_currency` | object (map code → number) | Always present | Provider-executed online refunds summed from each transaction's own `_refunds` ledger, grouped by that transaction's currency (completed orders only) | E.g. `{}` when no gateway refunds exist |
| `data.mixed_refund_currencies` | boolean | Always present | True when more than one nonzero refund bucket exists across BOTH refund systems | `true`/`false` |

Caching (code-verified, two layers): the service result is cached 300s under the fixed key `'dashboard_overview'` (request-blind), then the controller caches per-URL (`md5(fullUrl)`) up to 4h via `HasCache::remember` (tagged when the store supports it). Repeated identical calls return cached data without recomputation; there is no cache-bypass parameter.

Complete success example (`en` locale; figures illustrative, keys and message verified):

```json
{
  "success": true,
  "message": "Dashboard overview fetched successfully.",
  "data": {
    "total_revenue": 12500.75,
    "todays_revenue": 320.5,
    "total_refunds": 410.0,
    "total_orders": 842,
    "total_products": 196,
    "total_customers": 1530,
    "new_customers": 64,
    "revenue_currency": "USD",
    "revenue_by_base_currency": {
      "USD": 12500.75
    },
    "mixed_base_eras": false,
    "refund_by_currency": {
      "USD": 410.0
    },
    "gateway_refund_by_currency": {},
    "mixed_refund_currencies": false
  }
}
```

# Errors

The implementation has no machine-readable error-code field; every error below has Error code `None`. The 200/401/403 contracts are test-verified (`test_overview_returns_expected_structure`, `test_unauthenticated_user_cannot_access_dashboard`, `test_customer_without_view_analytics_permission_cannot_access_dashboard`); all other rows are code-verified or code-traced as marked. Note the deliberate contract of `errorResponse`: EVERY caught failure renders 409, never 500.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing, invalid, revoked, or expired Sanctum token (`auth:sanctum` rejects before the controller runs) | `{message: string, status: boolean}` (Handler shape) | None | `"Unauthenticated"` via `AuthenticationException` branch of `App\Exceptions\Handler` | None | Any request without a valid `Authorization: Bearer` token; verified by `test_unauthenticated_user_cannot_access_dashboard` expecting 401 on this exact URI (loops all 15 dashboard endpoints) | See 401 example |
| 403 | Authenticated caller lacks the `view-analytics` permission (Spatie `permission:view-analytics` route middleware rejects before the method body runs) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` via `Handler::messageForSpatieUnauthorized` (no permission-name suffix: `display_permission_in_exception` is `false`): `en` → `"User does not have the required permissions."`, `ar` → `"لا يمتلك المستخدم الصلاحيات المطلوبة."` (no endpoint-specific 403 test exists — code-traced through the shared path) | None | Valid token + permission-less customer; verified by `test_customer_without_view_analytics_permission_cannot_access_dashboard` asserting 403 on this exact URI | See 403 example |
| 405 | Any HTTP method other than `GET` on `/api/v1/dashboard/overview` (only `Route::get` is registered) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` via `MethodNotAllowedHttpException` branch of `Handler` | None | E.g. `POST`, `PUT`, `PATCH`, `DELETE` to the same URI (code-traced) | See 405 example |
| 409 | ANY failure inside `overview` (service, cache, or serialization): `catch (Throwable)` → `errorResponse`, which always responds 409 with `{success: false, message}` (no `data` key) — including non-database failures, by design | `{success: boolean, message: string}` (note: no `data` key and no `status` key, unlike both the success envelope and the rate-limiter shape) | None | `__('message.DASHBOARD.DATABASE_ERROR')` when the throwable is a `QueryException`: `en` → `"A database error occurred. Please check your request and try again."`, `ar` → `"حدث خطأ في قاعدة البيانات. يرجى التحقق من طلبك والمحاولة مرة أخرى."`; otherwise `__('message.ERROR.SOMETHING_WENT_WRONG')`: `en` → `"Something went wrong."`, `ar` → `"حدث خطأ ما"` | None | Any thrown failure (code-traced, no test) | See 409 examples |
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

405 example (wrong method, e.g. `POST /api/v1/dashboard/overview`):

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
