# Endpoint Name

Dashboard – Finance Analytics (`GET /api/v1/dashboard/finance`)

# Description

Returns cached gross/net revenue, refunds, discounts, shipping, and per-currency money splits.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/dashboard/finance` |
| Path composition | `api/v1` prefix from `packages/marvel/src/Providers/RestAPIServiceProvider.php` + `dashboard` prefix + `finance` from the dashboard group in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:analytics', 'permission:view-analytics', 'lang']` group |
| Controller action | `App\Http\Controllers\Api\General\DashboardController@financeAnalytics` → `App\Services\Dashboard\DashboardService::getFinanceAnalytics` (application layer — not Marvel; this endpoint uses its own `{success, message, data}` envelope, not the Marvel `apiResponse` shape) |
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

`getFinanceAnalytics(Request $request)` accepts the request but never reads it (code-verified: no `$request` reference in the method body) — query parameters have zero effect on the result. They only alter the outer cache key (`md5($request->fullUrl())`), so distinct query strings cache separate but identical payloads (code-traced).

| Field | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token enforced by `auth:sanctum` | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | Must be in `['en', 'ar']` to take effect; other values ignored | `en`, `ar` | `en` | None | `en` | Selects response message locale |
| `Accept` | header | string | Recommended, not strictly required | No | Behaviorally relevant for content negotiation; JSON error shape is still returned for `/api/*` paths | `application/json` | None | None | `application/json` | Requests a JSON response |

There are no effective query parameters, body fields, path parameters, multipart fields, or file uploads. There is no validation, so there is no `422` contract.

## Request examples

Valid request fetching finance analytics:

```json
{
  "method": "GET",
  "url": "/api/v1/dashboard/finance",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: finance-analytics payload — HTTP 200

Returned via plain `response()->json(['success' => true, 'message' => ..., 'data' => $dataCache])` (implicit 200; note: NO `status` key — code-verified envelope difference from every Marvel `apiResponse` endpoint). Verified by `test_finance_analytics_returns_revenue_breakdown` (one completed order of 1000 + shipping 50 + fast fee 10 + discount 30 + approved refund → 200, `success: true`, structure `data: [gross_revenue, net_revenue, refund_amount, total_discount, shipping_revenue]`, `gross_revenue == 1000.00`, `shipping_revenue == 60.00`) and `test_finance_analytics_returns_zero_when_no_data` (empty DB → `gross_revenue`, `net_revenue`, `shipping_revenue` all exactly `0`; both tests use `getJson`, matching this route — no test fix needed). There is exactly one success contract.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `message` | string | Always present | Translated `message.DASHBOARD.FINANCE_ANALYTICS_FETCHED` (locale from `lang` header) | `en` → `"Finance analytics fetched successfully."` (note trailing period), `ar` → `"تم جلب تحليلات المالية بنجاح"` |
| `data` | object | Always present on success | Cached analytics from `DashboardService::getFinanceAnalytics` | See `data` fields below |

`data` fields (code-verified against the service return; statuses/scopes differ per key — read carefully):

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `data.gross_revenue` | number (float, 2dp) | Always present | Completed-order base-safe sums (`COALESCE(converted_total_price, total_price)`); test-verified `1000.00` / `0` when empty | E.g. `1000.0` |
| `data.net_revenue` | number (float, 2dp) | Always present | `max(gross − approved-refund scalar, 0)` — clamped at zero; UNRESOLVED mixed-unit policy: base-denominated gross minus order-currency refunds with no conversion when units differ (per the code's own NET REVENUE POLICY comment — authoritative splits are the per-currency maps below) | E.g. `950.0` |
| `data.refund_amount` | number (float, 2dp) | Always present | `SUM(refunds.amount)` over APPROVED refunds (no order-scope join — includes refunds on any order scope; kept for backward compatibility per the code comment; gateway/online refunds NOT included — they live in per-transaction ledgers) | E.g. `50.0` |
| `data.total_discount` | number (float, 2dp) | Always present | `SUM(coupon_discount) + SUM(promotion_discount)` over ALL order statuses (legacy scope, preserved — does NOT reconcile with the completed-only buckets below, per the S2 scope comment) | E.g. `30.0` (test fixture discount) |
| `data.shipping_revenue` | number (float, 2dp) | Always present | Completed orders: `(shipping_price + fast_shipping_fee)` each converted with its own STORED `currency_rate` (never today's rate); test-verified `60.00` / `0` when empty | E.g. `60.0` |
| `data.gross_by_currency` | object (map code → number) | Always present (possibly empty) | Completed gross using RAW `total_price` by order currency (`NULL` → legacy `'BASE'` label) — the unconverted split feeding `net_revenue` | E.g. `{"USD": 1000.0}` |
| `data.discount_by_currency` | object (map code → number) | Always present (possibly empty) | Completed-only coupon+promotion discounts by order currency (`NULL` → `'UNKNOWN'`); reconciles with `gross_by_currency`, NOT with `total_discount` | E.g. `{"USD": 30.0}` |
| `data.mixed_discount_currencies` | boolean | Always present | True when more than one nonzero discount bucket exists | `true`/`false` |
| `data.shipping_by_base_currency` | object (map code → number) | Always present (possibly empty) | Completed shipping+fees bucketed by base era with stored-rate conversion (`NULL` → `'UNKNOWN'`) | E.g. `{"USD": 60.0}` |
| `data.net_revenue_by_currency` | object (map code → number) | Always present (possibly empty) | Per-currency net computed WITHIN one unit: completed gross − completed-scope marketplace refunds − gateway-ledger refunds, per key, sorted by key (the §9 business rule — no two units ever added/subtracted; `UNKNOWN` buckets never authoritative) | E.g. `{"USD": 950.0}` |
| `data.mixed_net_currencies` | boolean | Always present | True when more than one nonzero net bucket exists | `true`/`false` |
| `data.revenue_currency` | string | Always present | Current store base currency code (uppercased; config fallback on service failure) | E.g. `"USD"` |
| `data.revenue_by_base_currency` | object (map code → number) | Always present (possibly empty) | Completed revenue bucketed by base era; NULL-base legacy → `'UNKNOWN'` | E.g. `{"USD": 1000.0}` |
| `data.mixed_base_eras` | boolean | Always present | True when more than one nonzero base bucket exists | `true`/`false` |
| `data.refund_by_currency` | object (map code → number) | Always present (possibly empty) | APPROVED marketplace refunds bucketed by the refunded order's currency (completed, non-trashed orders only here — orphan/pending/trashed refunds stay visible ONLY in this map and are never netted, per the §9 comment) | E.g. `{"USD": 50.0}` |
| `data.gateway_refund_by_currency` | object (map code → number) | Always present (possibly empty) | Provider-executed online refunds from transaction `_refunds` ledgers by transaction currency (completed orders only) | E.g. `{}` |
| `data.mixed_refund_currencies` | boolean | Always present | True when EITHER refund system spans currencies | `true`/`false` |

Caching (code-verified, two layers): the service result is cached 300s under the fixed key `'dashboard_finance_analytics'` (request-blind), then the controller caches per-URL (`md5(fullUrl)`) up to 4h via `HasCache::remember` (tagged when the store supports it). `DashboardTest::setUp` flushes the cache, so tests observe fresh computations. There is no cache-bypass parameter. (The sibling E2E suite also exercises this route's buckets and the file-driver cache degradation path — `finance_api_buckets_and_cache_degradation` — supporting evidence, not cited as contract.)

Complete success example (`en` locale; figures illustrative for the single-order fixture, keys/message/structure verified):

```json
{
  "success": true,
  "message": "Finance analytics fetched successfully.",
  "data": {
    "gross_revenue": 1000.0,
    "net_revenue": 950.0,
    "refund_amount": 50.0,
    "total_discount": 30.0,
    "shipping_revenue": 60.0,
    "gross_by_currency": { "USD": 1000.0 },
    "discount_by_currency": { "USD": 30.0 },
    "mixed_discount_currencies": false,
    "shipping_by_base_currency": { "USD": 60.0 },
    "net_revenue_by_currency": { "USD": 950.0 },
    "mixed_net_currencies": false,
    "revenue_currency": "USD",
    "revenue_by_base_currency": { "USD": 1000.0 },
    "mixed_base_eras": false,
    "refund_by_currency": { "USD": 50.0 },
    "gateway_refund_by_currency": {},
    "mixed_refund_currencies": false
  }
}
```

# Errors

The implementation has no machine-readable error-code field; every error below has Error code `None`. The 200/401/403 contracts are test-verified (both finance tests, `test_unauthenticated_user_cannot_access_dashboard`, `test_customer_without_view_analytics_permission_cannot_access_dashboard`); all other rows are code-verified or code-traced as marked. Note the deliberate contract of `errorResponse`: EVERY caught failure renders 409, never 500.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing, invalid, revoked, or expired Sanctum token (`auth:sanctum` rejects before the controller runs) | `{message: string, status: boolean}` (Handler shape) | None | `"Unauthenticated"` via `AuthenticationException` branch of `App\Exceptions\Handler` | None | Any request without a valid `Authorization: Bearer` token; verified by `test_unauthenticated_user_cannot_access_dashboard` expecting 401 on this exact URI | See 401 example |
| 403 | Authenticated caller lacks the `view-analytics` permission (Spatie `permission:view-analytics` route middleware rejects before the method body runs) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` via `Handler::messageForSpatieUnauthorized` (no permission-name suffix: `display_permission_in_exception` is `false`): `en` → `"User does not have the required permissions."`, `ar` → `"لا يمتلك المستخدم الصلاحيات المطلوبة."` (no finance-specific 403 test exists — code-traced through the shared path) | None | Valid token + permission-less customer; verified by `test_customer_without_view_analytics_permission_cannot_access_dashboard` asserting 403 on this exact URI | See 403 example |
| 405 | Any HTTP method other than `GET` on `/api/v1/dashboard/finance` (only `Route::get` is registered) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` via `MethodNotAllowedHttpException` branch of `Handler` | None | E.g. `POST`, `PUT`, `PATCH`, `DELETE` to the same URI (code-traced) | See 405 example |
| 409 | ANY failure inside `financeAnalytics` (service, cache, or serialization): `catch (Throwable)` → `errorResponse`, which always responds 409 with `{success: false, message}` (no `data` key) — including non-database failures, by design | `{success: boolean, message: string}` (note: no `data` key and no `status` key, unlike both the success envelope and the rate-limiter shape) | None | `__('message.DASHBOARD.DATABASE_ERROR')` when the throwable is a `QueryException`: `en` → `"A database error occurred. Please check your request and try again."`, `ar` → `"حدث خطأ في قاعدة البيانات. يرجى التحقق من طلبك والمحاولة مرة أخرى."`; otherwise `__('message.ERROR.SOMETHING_WENT_WRONG')`: `en` → `"Something went wrong."`, `ar` → `"حدث خطأ ما"` | None | Any thrown failure (code-traced, no test) | See 409 examples |
| 429 | Exceeding `throttle:analytics` (60/min per user id or IP; limiter behavior verified by `test_all_endpoints_return_429_when_rate_limited` — 60 hits then 429 on the sibling overview URI, same limiter) | `{success: boolean, message: string, data: null}` (rate-limiter shape) | None | `__('message.too_many_requests')`: `en` → `"Too many requests. Please try again later."`, `ar` → `"طلبات كثيرة جدًا. يرجى المحاولة لاحقًا."` | None | Rate limit exceeded for the caller key | See 429 example |

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

405 example (wrong method, e.g. `POST /api/v1/dashboard/finance`):

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
