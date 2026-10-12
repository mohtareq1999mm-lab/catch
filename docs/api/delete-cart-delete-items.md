# Endpoint Name

Cart – Clear Cart (`DELETE /api/v1/cart/delete-items`)

# Description

Releases and clears the caller's cart, with a coupon confirmation gate.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `DELETE` |
| Full path | `/api/v1/cart/delete-items` |
| Path composition | `api/v1` prefix from `packages/marvel/src/Providers/RestAPIServiceProvider.php` + `cart/delete-items` from the cart group in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:cart', 'lang']` group |
| Controller action | `Marvel\Http\Controllers\CartController@destroy` (no-cart → 404 envelope; couponed cart without `confirm` → 200 WARNING envelope WITHOUT deleting; otherwise inventory release + 200 deleted confirmation) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | None required (no permission middleware on this controller or route — any authenticated user; the cart is the caller's own relation) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` (error responses are JSON for all `/api/*` paths regardless of `Accept`, per `App\Exceptions\Handler::shouldReturnJson`) |
| Optional headers | `lang: en\|ar` (default `en`; any other value is ignored and the locale is left unchanged, per `App\Http\Middleware\CheckLangMiddleware`) |
| Supported request formats | Query string only for the one input (`confirm`); no body is read |
| Rate limit | `throttle:cart`: 20 requests/minute per authenticated user id (or per IP for guests), per `App\Providers\AppServiceProvider::boot` |
| Route name | None defined for these routes |

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token enforced by `auth:sanctum` | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller (and selects their cart) |
| `lang` | header | string | Optional | No | Must be in `['en', 'ar']` to take effect; other values ignored | `en`, `ar` | `en` | None | `en` | Selects response message locale |
| `Accept` | header | string | Recommended, not strictly required | No | Behaviorally relevant for content negotiation; JSON error shape is still returned for `/api/*` paths | `application/json` | None | None | `application/json` | Requests a JSON response |
| `confirm` | query | boolean | Conditionally required | No | Evaluated via `$request->boolean('confirm')`, and ONLY consulted when the cart carries a coupon (`$cart->coupon` truthy) | Truthy values (`1`, `true`, `"1"`, `"true"`) | Absent (no confirmation) | Required (truthy) to proceed when a coupon is applied; ignored for coupon-less carts | `true` | Destructive-action confirmation for couponed carts |

There is no validation, so there is no `422` contract.

## Request examples

Valid request clearing a coupon-less cart (test-verified call):

```json
{
  "method": "DELETE",
  "url": "/api/v1/cart/delete-items?confirm=true",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: cart cleared — HTTP 200

Returned via `ApiResponse::apiResponse(DELETE_CART_SUCCESSFULLY, 200, true)` with default empty data, so the `data` key is absent. Verified by `test_clear_cart_releases_all_inventory` (`deleteJson` with `confirm: true` → 200; reserved counters stay `0`; uses `deleteJson`, matching this route). There are two 200 contracts on this endpoint (cleared vs. coupon-warning) — see below.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.DELETE_CART_SUCCESSFULLY` (locale from `lang` header) | `en` → `"Cart deleted successfully"`, `ar` → `"تم حذف السلة بنجاح"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |

Complete success example:

```json
{
  "status": 200,
  "message": "Cart deleted successfully",
  "success": true
}
```

## Coupon warning — HTTP 200 (NOT a deletion)

When the cart carries a coupon and `confirm` is NOT truthy, the method returns `apiResponse(COUPON_DELETE_CART_WARNING, 200, true)` WITHOUT touching inventory or rows — the cart survives intact and the client must re-call with confirmation (code-verified; message asserted end-to-end by `delete_cart_with_coupon_returns_warning` in `CouponSystemTest`).

```json
{
  "status": 200,
  "message": "This cart has a coupon applied. Please confirm to proceed with deletion.",
  "success": true
}
```

(`ar` → `"تحتوي هذه السلة على قسيمة مطبقة. يرجى التأكيد للمتابعة في الحذف."` — file-verified.)

# Errors

The implementation has no machine-readable error-code field; every error below has Error code `None`. There is no validation, so there is no `422` contract and no `errors` map. The 200/401 contracts are test-verified (all use `deleteJson`); all other rows are code-verified or code-traced as marked. Note the three 200/400/404 outcomes share the method with no try/catch (unexpected exceptions bubble to 500).

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 400 | Cart `user_id` mismatch (defense-in-depth — unreachable when the cart comes from the caller's own relation): `apiResponse(DELETE_CART_ITEM_FAILED, 400, false)` | `{status: integer, message: string, success: boolean}`, no `data` key | None | Translated `ERROR.DELETE_CART_ITEM_FAILED`: `en` → `"Failed to delete cart item"`, `ar` → `"فشل حذف عنصر السلة"` | None | Ownership mismatch only (code-traced — effectively unreachable; no test) | See 400 example |
| 401 | Missing, invalid, revoked, or expired Sanctum token (`auth:sanctum` rejects before the controller runs) | `{message: string, status: boolean}` (Handler shape; note boolean `status`, unlike the success envelope) | None | `"Unauthenticated"` via `AuthenticationException` branch of `App\Exceptions\Handler` | None | Any request without a valid `Authorization: Bearer` token; verified by `test_clear_cart_requires_auth` expecting 401 | See 401 example |
| 404 | Caller has no cart (`auth()->user()?->cart` null): `apiResponse(CART_NOT_FOUND, 404, false)` envelope (code-verified — translated notice inside the controller envelope, NOT the Handler fixed string) | `{status: integer, message: string, success: boolean}`, no `data` key | None | Translated `ERROR.CART_NOT_FOUND`: `en` → `"Cart not found"`, `ar` → `"السلة غير موجودة"` (file-verified values; end-to-end rendering code-traced — no endpoint-specific 404 test exists) | None | No cart row for the caller | See 404 example |
| 405 | Any HTTP method other than `DELETE` on `/api/v1/cart/delete-items` (only `Route::delete` is registered) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` via `MethodNotAllowedHttpException` branch of `Handler` | None | E.g. `GET`, `POST`, `PUT`, `PATCH` to the same URI (code-traced) | See 405 example |
| 429 | Exceeding `throttle:cart` (20/min per user id or IP) | `{success: boolean, message: string, data: null}` (rate-limiter shape; note `success`/`data` keys instead of the Handler `status` shape) | None | `__('message.too_many_requests')`: `en` → `"Too many requests. Please try again later."`, `ar` → `"طلبات كثيرة جدًا. يرجى المحاولة لاحقًا."` | None | Rate limit exceeded for the caller key (the cart suite disables this limiter in `setUp` — no endpoint-specific 429 test exists) | See 429 example |
| 500 | Unexpected failure (any exception in the release), rendered by the generic branch of `Handler` (the method has no try/catch) | `{message: string, status: boolean}` | None | The exception message when `app.debug` is true, else `"Internal Server Error"` | None | Internal failure (code-traced, no test) | See 500 example |

400 example (ownership mismatch — effectively unreachable):

```json
{
  "status": 400,
  "message": "Failed to delete cart item",
  "success": false
}
```

401 example (no or invalid token):

```json
{
  "message": "Unauthenticated",
  "status": false
}
```

404 example (caller has no cart; `en` locale):

```json
{
  "status": 404,
  "message": "Cart not found",
  "success": false
}
```

405 example (wrong method, e.g. `GET /api/v1/cart/delete-items`):

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
