# Endpoint Name

Cart – Bulk Add (`POST /api/v1/cart/bulk-items`)

# Description

Adds many line items at once, reporting skipped and failed ids without failing the batch.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `POST` |
| Full path | `/api/v1/cart/bulk-items` |
| Path composition | `api/v1` prefix from `packages/marvel/src/Providers/RestAPIServiceProvider.php` + `cart/bulk-items` from the cart group in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:cart', 'lang']` group |
| Controller action | `Marvel\Http\Controllers\CartController@pluckItemsToCart` (NO `FormRequest` — inline `$request->validate()`; per-item `storeCart` in individual try/catch; inactive/deleted products filtered out up front; shipping methods uppercased server-side) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | None required (no permission middleware on this controller or route — any authenticated user) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` (error responses are JSON for all `/api/*` paths regardless of `Accept`, per `App\Exceptions\Handler::shouldReturnJson`) |
| Optional headers | `lang: en\|ar` (default `en`; any other value is ignored and the locale is left unchanged, per `App\Http\Middleware\CheckLangMiddleware`) |
| Supported request formats | `application/json` (plus a raw-JSON-body fallback: when `items` is absent as a parsed field but the raw content decodes to `{"items": ...}`, it is merged in — code-verified quirk) |
| Rate limit | `throttle:cart`: 20 requests/minute per authenticated user id (or per IP for guests), per `App\Providers\AppServiceProvider::boot` |
| Route name | None defined for these routes |

Middleware runs before validation: an unauthenticated call fails with 401 without reaching validation.

## Request inputs

Inline validation runs before any mutation: an invalid envelope yields 422 with nothing written. Per-item domain failures AFTER that never fail the batch — they land in `failed_items` with 201 overall.

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token enforced by `auth:sanctum` | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller (and owns the cart) |
| `lang` | header | string | Optional | No | Must be in `['en', 'ar']` to take effect; other values ignored | `en`, `ar` | `en` | None | `en` | Selects response message locale |
| `Accept` | header | string | Recommended, not strictly required | No | Behaviorally relevant for content negotiation; JSON error shape is still returned for `/api/*` paths | `application/json` | None | None | `application/json` | Requests a JSON response |
| `Content-Type` | header | string | Required when a body is sent | No | Must match body format | `application/json` | None | None | `application/json` | Declares body encoding |
| `items` | body | array of objects | Required | No | `required\|array` | Non-empty array of line objects (empty array passes `required\|array`? — `required` FAILS empty arrays per framework semantics, so `[]` → 422; code-traced) | None | None | See example | The batch (note plural `items` — unlike single-add's `item`) |
| `items.*.product_id` | body (each element) | integer | Required | No | `required\|integer` (NO `exists` rule — unknown ids are filtered later into `skipped_product_ids`, not 422; code-verified difference from single-add) | Any integer | None | None | `7` | Product to add |
| `items.*.quantity` | body (each element) | integer | Required | No | `required\|integer\|min:1\|max:<cart.max_item_quantity, default 100>` | `1`–max | None | None | `2` | Units to add |
| `items.*.product_variant_id` | body (each element) | integer | Optional | Yes (`nullable`) | `nullable\|integer` (NO `exists` rule — unlike single-add; an unknown variant surfaces as a per-item 201 `failed_items` entry, code-traced) | Any integer or null | Absent | None | `null` | Variant selection |
| `items.*.shipping_method` | body (each element) | string | Optional | Yes (`nullable`) | `nullable\|string\|in:scheduled,fast,SCHEDULED,FAST` | Any accepted spelling or null | Upper-cased server-side (`strtoupper(... ?? 'scheduled')` — absent becomes `'SCHEDULED'`; code-verified) | None | `"fast"` | Fulfillment lane |

There are no path parameters, query parameters, multipart fields, or file uploads. Any extra body fields are ignored.

Pipeline (code-verified order): (1) envelope validation; (2) shipping uppercasing; (3) product filter — only `active()` + non-deleted ids proceed, the rest land in `skipped_product_ids` (values echoed VERBATIM, order preserved — test-verified `[99999, 88888]`); (4) per-item `storeCart` each in its own try/catch → failures land in `failed_items[]` as `{product_id, product_variant_id, reason}` with the LIVE exception text; (5) the caller's cart (if any row got created) serialized, else `cart: null` — with 201 EITHER WAY (even zero valid items — test-verified `data.cart: null`, no cart row).

## Request examples

Valid mixed request (test-verified call):

```json
{
  "method": "POST",
  "url": "/api/v1/cart/bulk-items",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json",
    "lang": "en"
  },
  "body": {
    "items": [
      { "product_id": 7, "quantity": 2, "shipping_method": "scheduled" },
      { "product_id": 99999, "quantity": 5 },
      { "product_id": 88888, "quantity": 3, "shipping_method": "fast" }
    ]
  }
}
```

# Responses

## Success: batch processed — HTTP 201

Returned via `ApiResponse::apiResponse(CREATE_CART_SUCCESSFULLY, 201, true, ['cart' => ..., 'skipped_product_ids' => ..., 'failed_items' => ...])` — ALWAYS 201 once validation passes, regardless of per-item outcomes. Verified by `test_bulk_add_items` (2 valid → 201 + 2 cart rows), `test_bulk_add_skips_nonexistent_products` (all-invalid → 201, `success: true`, `skipped_product_ids: [99999, 88888]`, `failed_items: []`, `cart: null`, NO cart row), and `test_bulk_add_mixed_valid_and_nonexistent_skips_invalid` (mixed → 201 + 1 row with the valid product/qty; all use `postJson`, matching this route). There is exactly one success contract.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `201` on success |
| `message` | string | Always present | Translated `MESSAGE.CREATE_CART_SUCCESSFULLY` (locale from `lang` header) | `en` → `"Cart created successfully"`, `ar` → `"تم إنشاء السلة بنجاح"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success (even with failures inside) |
| `data` | object | Always present on success | `{cart, skipped_product_ids, failed_items}` | See below |
| `data.cart` | object or null | Always present | The cart serialized by `CartResource`, or `null` when no item was stored (test-verified null case) | Same key set as single-add (item-level shape **Unverified in source**) |
| `data.skipped_product_ids` | array of integers | Always present (possibly empty) | Requested ids failing the active/existing filter, verbatim and in order | E.g. `[99999, 88888]` (test-verified) |
| `data.failed_items` | array of objects | Always present (possibly empty) | Per-item `storeCart` failures: `{product_id, product_variant_id, reason}` with the LIVE exception text | E.g. `[]` (test-verified empty case; non-empty contents code-traced) |

Complete success example (`en` locale; values illustrative, keys verified):

```json
{
  "status": 201,
  "message": "Cart created successfully",
  "success": true,
  "data": {
    "cart": {
      "id": 3,
      "user_id": 27,
      "total_quantity": 2,
      "total_price": 200.0,
      "currency": "USD"
    },
    "skipped_product_ids": [99999, 88888],
    "failed_items": []
  }
}
```

# Errors

The implementation has no machine-readable error-code field; every error below has Error code `None`. The 201/401 contracts are test-verified (all use `postJson`); all other rows are code-verified or code-traced as marked. Note the two failure shapes: envelope validation escapes to `Handler` (422 shape), while per-item failures NEVER surface as HTTP errors (they nest inside the 201 body).

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing, invalid, revoked, or expired Sanctum token (`auth:sanctum` rejects before the controller runs) | `{message: string, status: boolean}` (Handler shape; note boolean `status`, unlike the success envelope) | None | `"Unauthenticated"` via `AuthenticationException` branch of `App\Exceptions\Handler` (no bulk-specific 401 test exists; identical rejection test-verified on sibling cart routes) | None | Any request without a valid `Authorization: Bearer` token | See 401 example |
| 405 | `GET`, `PUT`, `PATCH`, or `DELETE` on `/api/v1/cart/bulk-items` (only `Route::post` is registered) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` via `MethodNotAllowedHttpException` branch of `Handler` | None | Wrong method with no competing route match (code-traced) | See 405 example |
| 422 | Envelope validation failure (missing/non-array `items`, missing/non-integer/out-of-range `quantity`, non-integer variant, bad `shipping_method`), rendered by the `ValidationException` branch of `Handler` (message = `ValidationException::summarize`) with the full map under `errors` | `{message: string, status: boolean, errors: object}` | None | First failure message (dynamic per failure) | Map of `items` / `items.{index}.{field}` → message arrays (key verified from rule targets; exact messages **Unverified in source** — no bulk-specific 422 test exists) | Malformed envelope only — unknown product ids do NOT 422 here (they skip; code-verified difference from single-add) | See 422 example |
| 429 | Exceeding `throttle:cart` (20/min per user id or IP) | `{success: boolean, message: string, data: null}` (rate-limiter shape; note `success`/`data` keys instead of the Handler `status` shape) | None | `__('message.too_many_requests')`: `en` → `"Too many requests. Please try again later."`, `ar` → `"طلبات كثيرة جدًا. يرجى المحاولة لاحقًا."` | None | Rate limit exceeded for the caller key (the cart suite disables this limiter in `setUp` — no endpoint-specific 429 test exists) | See 429 example |
| 500 | Unexpected failure OUTSIDE per-item handling (any exception in envelope processing/serialization), rendered by the generic branch of `Handler` (per-item `storeCart` failures are caught in-method and never reach here) | `{message: string, status: boolean}` | None | The exception message when `app.debug` is true, else `"Internal Server Error"` | None | Infrastructure failure (code-traced, no test) | See 500 example |

401 example (no or invalid token):

```json
{
  "message": "Unauthenticated",
  "status": false
}
```

405 example (wrong method, e.g. `GET /api/v1/cart/bulk-items`):

```json
{
  "message": "Method Not Allowed",
  "status": false
}
```

422 example (missing `items`):

```json
{
  "message": "The items field is required.",
  "status": false,
  "errors": {
    "items": [
      "The items field is required."
    ]
  }
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
