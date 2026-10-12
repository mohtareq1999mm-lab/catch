# Endpoint Name

Dashboard – Low Stock (`GET /api/v1/dashboard/low-stock`)

# Description

Returns cached physical products below the stock threshold, capped at fifty rows.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/dashboard/low-stock` |
| Path composition | `api/v1` prefix from `packages/marvel/src/Providers/RestAPIServiceProvider.php` + `dashboard` prefix + `low-stock` from the dashboard group in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:analytics', 'permission:view-analytics', 'lang']` group |
| Controller action | `App\Http\Controllers\Api\General\DashboardController@lowStock` → `App\Services\Dashboard\DashboardService::getLowStockProducts` (application layer — not Marvel; this endpoint uses its own `{success, message, data}` envelope, not the Marvel `apiResponse` shape) |
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

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token enforced by `auth:sanctum` | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | Must be in `['en', 'ar']` to take effect; other values ignored | `en`, `ar` | `en` | None | `en` | Selects response message locale |
| `Accept` | header | string | Recommended, not strictly required | No | Behaviorally relevant for content negotiation; JSON error shape is still returned for `/api/*` paths | `application/json` | None | None | `application/json` | Requests a JSON response |
| `limit` | query | integer | Optional | No | No validation; computed as `min((int) ($request->limit ?? 10), 50)` — cast to int, hard-capped at 50, default 10 | Any value (non-numeric casts to `0` → empty list; code-traced) | `10` | None | `5` | Maximum rows returned |

There is no `422` contract — no validation runs on this endpoint.

## Request examples

Valid request fetching low-stock products:

```json
{
  "method": "GET",
  "url": "/api/v1/dashboard/low-stock?limit=5",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: product list — HTTP 200

Returned via plain `response()->json(['success' => true, 'message' => ..., 'data' => $dataCache])` (implicit 200; note: NO `status` key — code-verified envelope difference from every Marvel `apiResponse` endpoint). Verified by `test_low_stock_returns_products_below_threshold` (`DashboardTest`: one product with quantity 3 and one with 50 → 200, `success: true`, `'Low Stock Item'` present in `data` names; the test uses `getJson`, matching this route — no test fix needed). There is exactly one success contract.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `message` | string | Always present | Translated `message.DASHBOARD.LOW_STOCK_FETCHED` (locale from `lang` header) | `en` → `"Low stock products fetched successfully."` (note trailing period), `ar` → `"تم جلب المنتجات منخفضة المخزون بنجاح"` |
| `data` | array of objects | Always present on success (possibly empty) | Physical products with `stock_quantity < 10`, serialized directly from Eloquent models with the `type` relation loaded — NO API resource and NO column select apply, so the attribute set is whatever the `Product` model serializes; exact keys **Unverified in source** (the test asserts names only, tolerating both string and translations-array forms) | Up to `limit` items |

Filter semantics (code-verified): strictly `stock_quantity < 10` (a quantity of exactly 10 is excluded — code-traced); digital products are excluded by the `physical()` scope by design (per the code comment: digital goods have no stock semantics); no `orderBy` applies, so row order is database-default. Relation between the test's `quantity` attribute and the `stock_quantity` predicate is **Unverified in source** — the test proves end-to-end inclusion of the low item, not the column mapping. Caching (code-verified, two layers): the service result is cached 300s under `"dashboard_low_stock_{$limit}"`, then the controller caches per-URL (`md5(fullUrl)`) up to 4h via `HasCache::remember` (tagged when the store supports it). `DashboardTest::setUp` flushes the cache, so tests observe fresh queries. There is no cache-bypass parameter.

Complete success example (`en` locale; items illustrative — shape unverified):

```json
{
  "success": true,
  "message": "Low stock products fetched successfully.",
  "data": [
    {
      "id": 7,
      "name": { "en": "Low Stock Item" },
      "slug": "low-stock-item",
      "stock_quantity": 3
    }
  ]
}
```

# Errors

The implementation has no machine-readable error-code field; every error below has Error code `None`. The 200/401 contracts are test-verified (`test_low_stock_returns_products_below_threshold`, `test_unauthenticated_user_cannot_access_dashboard` which loops all 15 dashboard endpoints including `low-stock`); all other rows are code-verified or code-traced as marked. Note the deliberate contract of `errorResponse`: EVERY caught failure renders 409, never 500.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing, invalid, revoked, or expired Sanctum token (`auth:sanctum` rejects before the controller runs) | `{message: string, status: boolean}` (Handler shape) | None | `"Unauthenticated"` via `AuthenticationException` branch of `App\Exceptions\Handler` | None | Any request without a valid `Authorization: Bearer` token; verified by `test_unauthenticated_user_cannot_access_dashboard` expecting 401 on this exact URI | See 401 example |
| 403 | Authenticated caller lacks the `view-analytics` permission (Spatie `permission:view-analytics` route middleware rejects before the method body runs) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` via `Handler::messageForSpatieUnauthorized` (no permission-name suffix: `display_permission_in_exception` is `false`): `en` → `"User does not have the required permissions."`, `ar` → `"لا يمتلك المستخدم الصلاحيات المطلوبة."` (no low-stock-specific 403 test exists — code-traced through the shared path) | None | Valid token + user without `view-analytics` | See 403 example |
| 405 | Any HTTP method other than `GET` on `/api/v1/dashboard/low-stock` (only `Route::get` is registered) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` via `MethodNotAllowedHttpException` branch of `Handler` | None | E.g. `POST`, `PUT`, `PATCH`, `DELETE` to the same URI (code-traced) | See 405 example |
| 409 | ANY failure inside `lowStock` (service, cache, or serialization): `catch (Throwable)` → `errorResponse`, which always responds 409 with `{success: false, message}` (no `data` key) — including non-database failures, by design | `{success: boolean, message: string}` (note: no `data` key and no `status` key, unlike both the success envelope and the rate-limiter shape) | None | `__('message.DASHBOARD.DATABASE_ERROR')` when the throwable is a `QueryException`: `en` → `"A database error occurred. Please check your request and try again."`, `ar` → `"حدث خطأ في قاعدة البيانات. يرجى التحقق من طلبك والمحاولة مرة أخرى."`; otherwise `__('message.ERROR.SOMETHING_WENT_WRONG')`: `en` → `"Something went wrong."`, `ar` → `"حدث خطأ ما"` | None | Any thrown failure (code-traced, no test) | See 409 examples |
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

405 example (wrong method, e.g. `POST /api/v1/dashboard/low-stock`):

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
