# Endpoint Name

Cart – Delete Item (`DELETE /api/v1/cart/delete-item/{itemId}`)

# Description

Removes one line from the caller's cart and recomputes totals with promotion revalidation.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `DELETE` |
| Full path | `/api/v1/cart/delete-item/{itemId}` |
| Path composition | `api/v1` prefix from `packages/marvel/src/Providers/RestAPIServiceProvider.php` + `cart/delete-item/{itemId}` from the cart group in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:cart', 'lang']` group |
| Controller action | `Marvel\Http\Controllers\CartController@deleteItemFromCart` (ownership + existence + inventory-release gates, all collapsing to the same 400; promotion revalidated and cart total recomputed from surviving lines on success) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | None required (no permission middleware on this controller or route — any authenticated user; ownership enforced in-method against the caller's own cart) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` (error responses are JSON for all `/api/*` paths regardless of `Accept`, per `App\Exceptions\Handler::shouldReturnJson`) |
| Optional headers | `lang: en\|ar` (default `en`; any other value is ignored and the locale is left unchanged, per `App\Http\Middleware\CheckLangMiddleware`) |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:cart`: 20 requests/minute per authenticated user id (or per IP for guests), per `App\Providers\AppServiceProvider::boot` |
| Route name | None defined for these routes |

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token enforced by `auth:sanctum` | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller (and scopes to their cart) |
| `lang` | header | string | Optional | No | Must be in `['en', 'ar']` to take effect; other values ignored | `en`, `ar` | `en` | None | `en` | Selects response message locale |
| `Accept` | header | string | Recommended, not strictly required | No | Behaviorally relevant for content negotiation; JSON error shape is still returned for `/api/*` paths | `application/json` | None | None | `application/json` | Requests a JSON response |
| `itemId` | path | integer (no route-level constraint; note the parameter is named `$ItemId` in code — case has no routing effect) | Required | No | No validation; the item is looked up within the CALLER's cart only (`$cart->items()->where('id', $ItemId)->first()`) — a foreign item id yields the same 400 as a miss (anti-leak by design; code-verified) | Id of a line in the caller's cart | None | None | `55` | Identifies the line to remove |

There are no query parameters, body fields, multipart fields, or file uploads. There is no `422` contract.

## Request examples

```json
{
  "method": "DELETE",
  "url": "/api/v1/cart/delete-item/55",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: line removed — HTTP 200

Returned via `ApiResponse::apiResponse(DELETE_CART_ITEM_SUCCESSFULLY, 200, true)` with default empty data, so the `data` key is absent. Verified by `test_delete_item_releases_inventory` and `test_delete_item_removes_item_from_cart` (both `assertStatus(200)`; the latter proves `assertDatabaseMissing('cart_items', ...)`; both use `deleteJson`, matching this route). There is exactly one success contract. Observable effects (code-verified): inventory released via `releaseItem`, promotion revalidated, and `carts.total_price` recomputed as the rounded sum of surviving lines. Under the current inventory contract the release is cart-local bookkeeping (test-verified `reserved_quantity` stays `0`).

Complete success example:

```json
{
  "status": 200,
  "message": "Cart item deleted successfully",
  "success": true
}
```

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.DELETE_CART_ITEM_SUCCESSFULLY` (locale from `lang` header) | `en` → `"Cart item deleted successfully"`, `ar` → `"تم حذف عنصر السلة بنجاح"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |

# Errors

The implementation has no machine-readable error-code field; every error below has Error code `None`. There is no validation, so there is no `422` contract and no `errors` map. The 200/401 contracts are test-verified (all use `deleteJson`); all other rows are code-verified or code-traced as marked. Note the deliberate contract: all four preconditions collapse to the SAME 400 body.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 400 | Any of: (a) caller has no cart, (b) cart `user_id` mismatch (defense-in-depth — unreachable when the cart comes from the caller's relation), (c) no such line in the caller's cart (foreign ids included — anti-leak), (d) inventory release reports failure — all return `apiResponse(DELETE_CART_ITEM_FAILED, 400, false)` | `{status: integer, message: string, success: boolean}`, no `data` key | None | Translated `ERROR.DELETE_CART_ITEM_FAILED`: `en` → `"Failed to delete cart item"`, `ar` → `"فشل حذف عنصر السلة"` | None | Any failed precondition (code-traced — no endpoint-specific 400 test exists) | See 400 example |
| 401 | Missing, invalid, revoked, or expired Sanctum token (`auth:sanctum` rejects before the controller runs) | `{message: string, status: boolean}` (Handler shape; note boolean `status`, unlike the success envelope) | None | `"Unauthenticated"` via `AuthenticationException` branch of `App\Exceptions\Handler` | None | Any request without a valid `Authorization: Bearer` token; verified by `test_delete_item_requires_auth` expecting 401 | See 401 example |
| 405 | Any HTTP method other than `DELETE` on `/api/v1/cart/delete-item/{itemId}` (only `Route::delete` is registered) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` via `MethodNotAllowedHttpException` branch of `Handler` | None | E.g. `GET`, `POST`, `PUT`, `PATCH` to the same URI (code-traced) | See 405 example |
| 429 | Exceeding `throttle:cart` (20/min per user id or IP) | `{success: boolean, message: string, data: null}` (rate-limiter shape; note `success`/`data` keys instead of the Handler `status` shape) | None | `__('message.too_many_requests')`: `en` → `"Too many requests. Please try again later."`, `ar` → `"طلبات كثيرة جدًا. يرجى المحاولة لاحقًا."` | None | Rate limit exceeded for the caller key (the cart suite disables this limiter in `setUp` — no endpoint-specific 429 test exists) | See 429 example |
| 500 | Unexpected failure other than the handled preconditions (any exception), rendered by the generic branch of `Handler` (the method has no try/catch) | `{message: string, status: boolean}` | None | The exception message when `app.debug` is true, else `"Internal Server Error"` | None | Internal failure (code-traced, no test) | See 500 example |

400 example (no cart, foreign/missing line, or failed release):

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

405 example (wrong method, e.g. `GET /api/v1/cart/delete-item/55`):

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
