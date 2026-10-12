# Endpoint Name

Dashboard – Cart Analytics (`GET /api/v1/dashboard/cart`)

# Description

Returns cached cart abandonment, dropoff, average value, and most-added products.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/dashboard/cart` |
| Path composition | `api/v1` prefix from `packages/marvel/src/Providers/RestAPIServiceProvider.php` + `dashboard` prefix + `cart` from the dashboard group in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:analytics', 'permission:view-analytics', 'lang']` group. Note: a separate shopper-cart group also registers `GET cart` (no `dashboard` prefix, different controller) — the two must not be conflated |
| Controller action | `App\Http\Controllers\Api\General\DashboardController@cartAnalytics` → `App\Services\Dashboard\DashboardService::getCartAnalytics` (application layer — not Marvel; this endpoint uses its own `{success, message, data}` envelope, not the Marvel `apiResponse` shape) |
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

`getCartAnalytics(Request $request)` accepts the request but never reads it (code-verified: no `$request` reference in the method body) — query parameters have zero effect on the result. They only alter the outer cache key (`md5($request->fullUrl())`), so distinct query strings cache separate but identical payloads (code-traced).

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token enforced by `auth:sanctum` | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | Must be in `['en', 'ar']` to take effect; other values ignored | `en`, `ar` | `en` | None | `en` | Selects response message locale |
| `Accept` | header | string | Recommended, not strictly required | No | Behaviorally relevant for content negotiation; JSON error shape is still returned for `/api/*` paths | `application/json` | None | None | `application/json` | Requests a JSON response |

There are no effective query parameters, body fields, path parameters, multipart fields, or file uploads. There is no validation, so there is no `422` contract.

## Request examples

Valid request fetching cart analytics:

```json
{
  "method": "GET",
  "url": "/api/v1/dashboard/cart",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: cart-analytics payload — HTTP 200

Returned via plain `response()->json(['success' => true, 'message' => ..., 'data' => $dataCache])` (implicit 200; note: NO `status` key — code-verified envelope difference from every Marvel `apiResponse` endpoint). Verified by `test_cart_analytics_returns_abandonment_rate` (one active + one checked-out cart → 200, `success: true`, structure `data: [abandonment_rate, most_added_products, average_cart_value, checkout_dropoff_rate]`) and `test_cart_analytics_shows_zero_when_no_carts` (`abandonment_rate`, `checkout_dropoff_rate`, `average_cart_value` all exactly `0` — proving the zero-division guards; both tests use `getJson`, matching this route — no test fix needed). There is exactly one success contract.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `message` | string | Always present | Translated `message.DASHBOARD.CART_ANALYTICS_FETCHED` (locale from `lang` header) | `en` → `"Cart analytics fetched successfully."` (note trailing period), `ar` → `"تم جلب تحليلات السلة بنجاح"` |
| `data` | object | Always present on success | Cached analytics from `DashboardService::getCartAnalytics` (raw `carts`/`cart_items` tables — all cart statuses in scope unless noted) | See `data` fields below |

`data` fields (code-verified against the service return):

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `data.abandonment_rate` | number (%, 2dp) | Always present | Carts with status `active` OR `expired` ÷ all carts × 100 (`0` when no carts — test-verified guard). Checked-out carts are NOT abandoned; any other status is neither (code-verified) | E.g. `50.0` for the test's one-active/one-checked-out fixture |
| `data.most_added_products` | array (max 10) | Always present (possibly empty) | Products ordered by summed `cart_items.quantity` descending (all carts regardless of status — code-verified, no status filter) | Each item: `{id, name, slug, price, total_added}` |
| `data.average_cart_value` | number (float, 2dp) | Always present | `AVG(total_price)` over `active` + `checked_out` carts only (`0`/null-safe when none — test-verified `0`; `avg()` returns null on empty set, `round(null)` → `0` — code-traced) | E.g. `150.0` for the test fixture (100 + 200 ÷ 2) |
| `data.checkout_dropoff_rate` | number (%, 2dp) | Always present | `(1 − checked_out ÷ all) × 100` (`0` when no carts — test-verified guard) | E.g. `50.0` for the test fixture |

Caching (code-verified, two layers): the service result is cached 300s under the fixed key `'dashboard_cart_analytics'` (request-blind), then the controller caches per-URL (`md5(fullUrl)`) up to 4h via `HasCache::remember` (tagged when the store supports it). `DashboardTest::setUp` flushes the cache, so tests observe fresh computations. There is no cache-bypass parameter.

Complete success example (`en` locale; figures illustrative for the test fixture, keys/message/structure verified):

```json
{
  "success": true,
  "message": "Cart analytics fetched successfully.",
  "data": {
    "abandonment_rate": 50.0,
    "most_added_products": [
      { "id": 4, "name": { "en": "Test Product X" }, "slug": "test-product-x", "price": 100.0, "total_added": 2 }
    ],
    "average_cart_value": 150.0,
    "checkout_dropoff_rate": 50.0
  }
}
```

# Errors

The implementation has no machine-readable error-code field; every error below has Error code `None`. The 200/401 contracts are test-verified (both cart tests, `test_unauthenticated_user_cannot_access_dashboard` which loops all 15 dashboard endpoints including `cart`); all other rows are code-verified or code-traced as marked. Note the deliberate contract of `errorResponse`: EVERY caught failure renders 409, never 500.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing, invalid, revoked, or expired Sanctum token (`auth:sanctum` rejects before the controller runs) | `{message: string, status: boolean}` (Handler shape) | None | `"Unauthenticated"` via `AuthenticationException` branch of `App\Exceptions\Handler` | None | Any request without a valid `Authorization: Bearer` token; verified by `test_unauthenticated_user_cannot_access_dashboard` expecting 401 on this exact URI | See 401 example |
| 403 | Authenticated caller lacks the `view-analytics` permission (Spatie `permission:view-analytics` route middleware rejects before the method body runs) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` via `Handler::messageForSpatieUnauthorized` (no permission-name suffix: `display_permission_in_exception` is `false`): `en` → `"User does not have the required permissions."`, `ar` → `"لا يمتلك المستخدم الصلاحيات المطلوبة."` (no cart-specific 403 test exists — code-traced through the shared path) | None | Valid token + user without `view-analytics` | See 403 example |
| 405 | Any HTTP method other than `GET` on `/api/v1/dashboard/cart` (only `Route::get` is registered) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` via `MethodNotAllowedHttpException` branch of `Handler` | None | E.g. `POST`, `PUT`, `PATCH`, `DELETE` to the same URI (code-traced) | See 405 example |
| 409 | ANY failure inside `cartAnalytics` (service, cache, or serialization): `catch (Throwable)` → `errorResponse`, which always responds 409 with `{success: false, message}` (no `data` key) — including non-database failures, by design | `{success: boolean, message: string}` (note: no `data` key and no `status` key, unlike both the success envelope and the rate-limiter shape) | None | `__('message.DASHBOARD.DATABASE_ERROR')` when the throwable is a `QueryException`: `en` → `"A database error occurred. Please check your request and try again."`, `ar` → `"حدث خطأ في قاعدة البيانات. يرجى التحقق من طلبك والمحاولة مرة أخرى."`; otherwise `__('message.ERROR.SOMETHING_WENT_WRONG')`: `en` → `"Something went wrong."`, `ar` → `"حدث خطأ ما"` | None | Any thrown failure (code-traced, no test) | See 409 examples |
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

405 example (wrong method, e.g. `POST /api/v1/dashboard/cart`):

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
