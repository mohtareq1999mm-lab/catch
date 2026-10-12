# Endpoint Name

Cart – Update Item (`PUT /api/v1/cart/update-item`)

# Description

Applies an increment or decrement operation to a cart line with promotion revalidation.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `PUT` |
| Full path | `/api/v1/cart/update-item` |
| Path composition | `api/v1` prefix from `packages/marvel/src/Providers/RestAPIServiceProvider.php` + `cart/update-item` from the cart group in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:cart', 'lang']` group |
| Controller action | `Marvel\Http\Controllers\CartController@update` → `CartRepository::updateCart` + `revalidatePromotion` (promotion state recomputed after every mutation — code-verified side effect) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | None required (no permission middleware on this controller or route — any authenticated user) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` (error responses are JSON for all `/api/*` paths regardless of `Accept`, per `App\Exceptions\Handler::shouldReturnJson`) |
| Optional headers | `lang: en\|ar` (default `en`; any other value is ignored and the locale is left unchanged, per `App\Http\Middleware\CheckLangMiddleware`) |
| Supported request formats | `application/json` |
| Rate limit | `throttle:cart`: 20 requests/minute per authenticated user id (or per IP for guests), per `App\Providers\AppServiceProvider::boot` |
| Route name | None defined for these routes |

Middleware runs before validation: an unauthenticated call fails with 401 without reaching the `FormRequest`.

## Request inputs

`CartUpdateRequest` validation runs before the method body (`failedValidation` rethrows → 422). Unlike store, the operation is REQUIRED here (increment/decrement deliberate-change semantics), while `shipping_method` is optional.

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token enforced by `auth:sanctum` | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller (and owns the cart) |
| `lang` | header | string | Optional | No | Must be in `['en', 'ar']` to take effect; other values ignored | `en`, `ar` | `en` | None | `en` | Selects response message locale |
| `Accept` | header | string | Recommended, not strictly required | No | Behaviorally relevant for content negotiation; JSON error shape is still returned for `/api/*` paths | `application/json` | None | None | `application/json` | Requests a JSON response |
| `Content-Type` | header | string | Required when a body is sent | No | Must match body format | `application/json` | None | None | `application/json` | Declares body encoding |
| `item` | body | object | Required | No | `required\|array\|min:1` | Non-empty object with the keys below | None | None | See example | The line operation |
| `item.product_id` | body | integer | Required | No | `required_with:item\|integer\|exists:products,id` | Id of an existing product | None | None | `7` | Product line to change |
| `item.quantity` | body | integer | Required | No | `required_with:item\|integer\|min:1\|max:<cart.max_item_quantity, default 100>` | `1`–max | None | None | `3` | Delta/quantity applied by the operation (test-verified: 2 + increment 3 = 5) |
| `item.product_variant_id` | body | integer | Optional | Yes (`nullable`) | `sometimes\|nullable\|integer\|exists:product_variants,id` | Existing variant id or null | Absent | None | `null` | Variant selection |
| `item.attributes` | body | object | Optional | N/A | `sometimes\|array` | Arbitrary attribute map | Absent | None | `{"color": "red"}` | Line attributes |
| `item.shipping_method` | body | string | Optional | No | `sometimes\|string\|in:SCHEDULED,FAST,scheduled,fast` | Any accepted spelling, or absent | Absent | None | `"SCHEDULED"` | Fulfillment lane override (test-verified uppercase accepted) |
| `item.operation` | body | string | Required | No | `required\|string\|in:increment,decrement` (lowercase only — `INCREMENT` rejected; code-verified enum values) | `increment`, `decrement` | None | None | `"increment"` | Change direction |

There are no path parameters, query parameters, multipart fields, or file uploads. Any extra body fields are ignored.

## Request examples

Valid request incrementing a line (test-verified call):

```json
{
  "method": "PUT",
  "url": "/api/v1/cart/update-item",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json",
    "lang": "en"
  },
  "body": {
    "item": {
      "product_id": 7,
      "quantity": 3,
      "operation": "increment",
      "shipping_method": "SCHEDULED"
    }
  }
}
```

# Responses

## Success: line updated — HTTP 200

Returned via `ApiResponse::apiResponse(UPDATE_CART_SUCCESSFULLY, 200, true, CartResource::make($cart))` after `updateCart` + `revalidatePromotion`. Verified by `test_update_item_changes_quantity` (`assertStatus(200)`, DB quantity 2 + increment 3 = 5; uses `putJson`, matching this route). There is exactly one success contract.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.UPDATE_CART_SUCCESSFULLY` (locale from `lang` header) | `en` → `"Cart updated successfully"`, `ar` → `"تم تحديث السلة بنجاح"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | The cart serialized by `CartResource` (see the list doc for the full key set; item-level shape **Unverified in source**) | Promotion-aware totals included |

Complete success example (`en` locale; values illustrative, keys verified):

```json
{
  "status": 200,
  "message": "Cart updated successfully",
  "success": true,
  "data": {
    "id": 3,
    "user_id": 27,
    "total_quantity": 5,
    "total_price": 500.0,
    "currency": "USD",
    "has_eligible_promotion": false
  }
}
```

# Errors

The implementation has no machine-readable error-code field; every error below has Error code `None`. The 200/401 contracts are test-verified (both use `putJson`); all other rows are code-verified or code-traced as marked. Note the two failure envelopes: validation escapes to `Handler` (422 shape), while repository failures are caught in-method (400 envelope with the live exception text).

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 400 | Repository failure after validation (e.g. unknown product, missing line): caught by the method's own `catch (\Exception $e)` which returns `apiResponse($e->getMessage(), 400, false)` — the message is the LIVE exception text (dynamic, **Unverified in source** per case) | `{status: integer, message: string, success: boolean}`, no `data` key | None | Live exception message (dynamic) | None | Post-validation domain failure (code-traced — no endpoint-specific 400 test exists) | See 400 example |
| 401 | Missing, invalid, revoked, or expired Sanctum token (`auth:sanctum` rejects before the controller runs) | `{message: string, status: boolean}` (Handler shape; note boolean `status`, unlike the success envelope) | None | `"Unauthenticated"` via `AuthenticationException` branch of `App\Exceptions\Handler` | None | Any request without a valid `Authorization: Bearer` token; verified by `test_update_item_requires_auth` expecting 401 | See 401 example |
| 405 | `GET`, `POST`, `PATCH`, or `DELETE` on `/api/v1/cart/update-item` (only `Route::put` is registered) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` via `MethodNotAllowedHttpException` branch of `Handler` | None | Wrong method with no competing route match (code-traced) | See 405 example |
| 422 | Any `CartUpdateRequest` validation failure, rendered by the `ValidationException` branch of `Handler` (message = `ValidationException::summarize`) with the full map under `errors` | `{message: string, status: boolean, errors: object}` | None | First failure message (dynamic per failure) | Map of `item` / `item.{field}` → message arrays (key verified from rule targets; exact messages **Unverified in source** — no update-specific 422 test exists) | Missing/non-array/empty `item`; unknown/non-integer `product_id`; quantity out of range; unknown variant; bad `shipping_method`; missing/unknown `operation` (note: uppercase `INCREMENT` fails — code-verified) | See 422 example |
| 429 | Exceeding `throttle:cart` (20/min per user id or IP) | `{success: boolean, message: string, data: null}` (rate-limiter shape; note `success`/`data` keys instead of the Handler `status` shape) | None | `__('message.too_many_requests')`: `en` → `"Too many requests. Please try again later."`, `ar` → `"طلبات كثيرة جدًا. يرجى المحاولة لاحقًا."` | None | Rate limit exceeded for the caller key (the cart suite disables this limiter in `setUp` — no endpoint-specific 429 test exists) | See 429 example |

There is no 500 contract on this action (all `\Exception`s are caught → 400).

400 example (repository failure; exact text dynamic):

```json
{
  "status": 400,
  "message": "Cart item not found.",
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

405 example (wrong method, e.g. `POST /api/v1/cart/update-item`):

```json
{
  "message": "Method Not Allowed",
  "status": false
}
```

422 example (missing `operation`):

```json
{
  "message": "The item.operation field is required.",
  "status": false,
  "errors": {
    "item.operation": [
      "The item.operation field is required."
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
