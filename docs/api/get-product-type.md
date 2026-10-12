# Endpoint Name

Catalog – Product Type Labels (`GET /api/v1/product-type`)

# Description

Returns the nine storefront product-type filter labels resolved in the request locale.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/product-type` |
| Path composition | `api/v1` prefix from `packages/marvel/src/Providers/RestAPIServiceProvider.php` + `product-type` closure route in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group (no controller — inline closure; no permission middleware, so any authenticated user) |
| Controller action | None (route closure mapping nine fixed keys through `__("message.PRODUCT_TYPE." . strtoupper($key))`) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | None required (no permission middleware on this route — any authenticated user) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` (error responses are JSON for all `/api/*` paths regardless of `Accept`, per `App\Exceptions\Handler::shouldReturnJson`) |
| Optional headers | `lang: en\|ar` (default `en`; any other value is ignored and the locale is left unchanged, per `App\Http\Middleware\CheckLangMiddleware`) |
| Supported request formats | No inputs of any kind; no body is read |
| Rate limit | `throttle:admin`: 400 requests/minute per authenticated user id (or per IP for guests), per `App\Providers\AppServiceProvider::boot` |
| Route name | None defined for this route |

## Request inputs

No inputs of any kind are honored. There is no validation, so there is no `422` contract.

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token enforced by `auth:sanctum` | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | Must be in `['en', 'ar']` to take effect; other values ignored | `en`, `ar` | `en` | None | `en` | Selects the label locale (the ONLY effective input — each value resolves through `message.PRODUCT_TYPE.*` translations) |
| `Accept` | header | string | Recommended, not strictly required | No | Behaviorally relevant for content negotiation; JSON error shape is still returned for `/api/*` paths | `application/json` | None | None | `application/json` | Requests a JSON response |

## Request examples

```json
{
  "method": "GET",
  "url": "/api/v1/product-type",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: label map — HTTP 200

Returned as a bare JSON object (implicit 200; note: NO `status`/`success`/`message` envelope at all — code-verified, this closure returns the map directly). No test covers this route (all expectations below are code-verified or code-traced). There is exactly one success contract.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `<nine fixed keys>` | string values | Always present | Locale-resolved labels for `best_product_sales`, `brands_product`, `new_arrivals`, `all_product_discounts`, `product_discount_today_or_low_qty`, `flash_sales_product`, `flash_sales_end_today`, `product_for_parent_category`, `flash_sales_end_week` | Each value = `message.PRODUCT_TYPE.{UPPERCASED_KEY}` translation in the request locale; exact strings **Unverified in source** (translation files not inspected for these keys). Missing translation keys would echo the key path itself (standard `__()` fallback — code-traced framework behavior) |

Complete success example (structure verified; label strings illustrative):

```json
{
  "best_product_sales": "Best Product Sales",
  "brands_product": "Brands Product",
  "new_arrivals": "New Arrivals",
  "all_product_discounts": "All Product Discounts",
  "product_discount_today_or_low_qty": "Product Discount Today Or Low Qty",
  "flash_sales_product": "Flash Sales Product",
  "flash_sales_end_today": "Flash Sales End Today",
  "product_for_parent_category": "Product For Parent Category",
  "flash_sales_end_week": "Flash Sales End Week"
}
```

# Errors

The implementation has no machine-readable error-code field; every error below has Error code `None`. No test covers this route: 401 expectations are verified against the shared `auth:sanctum` path exercised by named tests on sibling routes (cited per row); all other rows are code-verified or code-traced.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing, invalid, revoked, or expired Sanctum token (`auth:sanctum` rejects before the closure runs) | `{message: string, status: boolean}` (Handler shape) | None | `"Unauthenticated"` via `AuthenticationException` branch of `App\Exceptions\Handler` (identical rejection test-verified on sibling routes) | None | Any request without a valid `Authorization: Bearer` token | See 401 example |
| 405 | Any HTTP method other than `GET` on `/api/v1/product-type` (only `Route::get` is registered) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` via `MethodNotAllowedHttpException` branch of `Handler` | None | E.g. `POST`, `PUT`, `PATCH`, `DELETE` to the same URI (code-traced) | See 405 example |
| 429 | Exceeding `throttle:admin` (400/min per user id or IP) | `{success: boolean, message: string, data: null}` (rate-limiter shape; note `success`/`data` keys instead of the Handler `status` shape) | None | `__('message.too_many_requests')`: `en` → `"Too many requests. Please try again later."`, `ar` → `"طلبات كثيرة جدًا. يرجى المحاولة لاحقًا."` | None | Rate limit exceeded for the caller key | See 429 example |
| 500 | Unexpected failure (any exception in translation resolution), rendered by the generic branch of `Handler` | `{message: string, status: boolean}` | None | The exception message when `app.debug` is true, else `"Internal Server Error"` | None | Internal failure (code-traced, no test) | See 500 example |

There is no 403 contract (no permission gate — any authenticated caller succeeds) and no 422 contract (no validation).

401 example (no or invalid token):

```json
{
  "message": "Unauthenticated",
  "status": false
}
```

405 example (wrong method, e.g. `POST /api/v1/product-type`):

```json
{
  "message": "Method Not Allowed",
  "status": false
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

500 example (`app.debug` off):

```json
{
  "message": "Internal Server Error",
  "status": false
}
```
