# Endpoint Name

Cart – Add Item (`POST /api/v1/cart`)

# Description

Adds one line item to the caller's cart, creating the cart with promotion revalidation.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `POST` |
| Full path | `/api/v1/cart` |
| Path composition | `api/v1` prefix from `packages/marvel/src/Providers/RestAPIServiceProvider.php` + `cart` from the cart group in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:cart', 'lang']` group |
| Controller action | `Marvel\Http\Controllers\CartController@store` → `CartRepository::storeCart` + `revalidatePromotion` (promotion state recomputed after every mutation — code-verified side effect) |
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

`CartCreateRequest` validation runs before the method body (`failedValidation` rethrows → 422). Quantities never touch inventory counters at cart time (test-verified contract: `reserved_quantity` stays `0`; stock is checked at checkout, and per-line maximum `cart.max_item_quantity`, default 100, is enforced here).

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token enforced by `auth:sanctum` | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller (and owns the cart) |
| `lang` | header | string | Optional | No | Must be in `['en', 'ar']` to take effect; other values ignored | `en`, `ar` | `en` | None | `en` | Selects response message locale |
| `Accept` | header | string | Recommended, not strictly required | No | Behaviorally relevant for content negotiation; JSON error shape is still returned for `/api/*` paths | `application/json` | None | None | `application/json` | Requests a JSON response |
| `Content-Type` | header | string | Required when a body is sent | No | Must match body format | `application/json` | None | None | `application/json` | Declares body encoding |
| `item` | body | object | Required | No | `required\|array\|min:1` | Non-empty object with the keys below | None | None | See example | The single line item (note singular `item` — unlike bulk's `items`) |
| `item.product_id` | body | integer | Required | No | `required\|integer\|exists:products,id` (existence only — active/deleted status is a repository concern, surfacing as 400 below, test-verified for id `99999`) | Id of an existing product | None | None | `7` | Product to add |
| `item.quantity` | body | integer | Required | No | `required\|integer\|min:1\|max:<cart.max_item_quantity, default 100>` | `1`–max (verified trigger: `999` → 422) | None | None | `2` | Units to add (accumulates with existing line quantity — test-verified 1+3=4) |
| `item.product_variant_id` | body | integer | Optional | Yes (`nullable`) | `sometimes\|nullable\|integer\|exists:product_variants,id` | Existing variant id or null | Absent | None | `null` | Variant selection |
| `item.attributes` | body | object | Optional | N/A | `sometimes\|array` | Arbitrary attribute map | Absent | None | `{"color": "red"}` | Line attributes |
| `item.shipping_method` | body | string | Required | No | `required\|string\|in:SCHEDULED,FAST,scheduled,fast` (both cases accepted at the boundary; the repository normalizes/stores UPPERCASE — test-verified `SCHEDULED` in DB for lowercase input) | `scheduled`/`SCHEDULED`/`fast`/`FAST` | None | None | `"scheduled"` | Fulfillment lane (drives the normal/fast resource split) |

There are no path parameters, query parameters, multipart fields, or file uploads. Any extra body fields are ignored.

## Request examples

Valid request adding two units (test-verified call):

```json
{
  "method": "POST",
  "url": "/api/v1/cart",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json",
    "lang": "en"
  },
  "body": {
    "item": {
      "product_id": 7,
      "quantity": 2,
      "shipping_method": "scheduled"
    }
  }
}
```

# Responses

## Success: item added — HTTP 201

Returned via `ApiResponse::apiResponse(CREATE_CART_SUCCESSFULLY, 201, true, CartResource::make($cart))` after `storeCart` + `revalidatePromotion`. Verified by `test_add_item_creates_cart` (`assertStatus(201)`, `success: true`, structure `data: [id, user_id, normal_items, total_price]`, `assertDatabaseHas` on carts + cart_items; uses `postJson`, matching this route). There is exactly one success contract.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `201` on success |
| `message` | string | Always present | Translated `MESSAGE.CREATE_CART_SUCCESSFULLY` (locale from `lang` header) | `en` → `"Cart created successfully"`, `ar` → `"تم إنشاء السلة بنجاح"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | The cart serialized by `CartResource` (see the list doc for the full key set; item-level shape **Unverified in source**) | Promotion-aware totals included |

Complete success example (`en` locale; values illustrative, keys verified):

```json
{
  "status": 201,
  "message": "Cart created successfully",
  "success": true,
  "data": {
    "id": 3,
    "user_id": 27,
    "coupon": null,
    "coupon_code": null,
    "status": "active",
    "total_items": 1,
    "total_quantity": 2,
    "total_price": 200.0,
    "currency": "USD",
    "normal_items_count": 1,
    "fast_items_count": 0,
    "has_eligible_promotion": false
  }
}
```

# Errors

The implementation has no machine-readable error-code field; every error below has Error code `None`. The 201/401/422 contracts are test-verified (all use `postJson`); all other rows are code-verified or code-traced as marked. Note the two failure envelopes: validation escapes to `Handler` (422 shape), while repository failures are caught in-method (400 envelope with the live exception text).

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 400 | Repository failure after validation (e.g. unknown/inactive product — `storeCart` throws): caught by the method's own `catch (\Exception $e)` which returns `apiResponse($e->getMessage(), 400, false)` — the message is the LIVE exception text (dynamic, **Unverified in source** per case) | `{status: integer, message: string, success: boolean}`, no `data` key | None | Live exception message (dynamic) | None | Post-validation domain failure; verified shape trigger: `test_add_item_rejects_nonexistent_product` accepts `[400, 422]` for id `99999` | See 400 example |
| 401 | Missing, invalid, revoked, or expired Sanctum token (`auth:sanctum` rejects before the controller runs) | `{message: string, status: boolean}` (Handler shape; note boolean `status`, unlike the success envelope) | None | `"Unauthenticated"` via `AuthenticationException` branch of `App\Exceptions\Handler` | None | Any request without a valid `Authorization: Bearer` token; verified by `test_add_item_requires_auth` and `test_guest_cannot_access_cart`, both expecting 401 | See 401 example |
| 405 | `PUT`, `PATCH`, or `DELETE` on the bare `/api/v1/cart` path (no such routes are registered; note `GET /cart` is the separate list endpoint, not a 405) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` via `MethodNotAllowedHttpException` branch of `Handler` | None | Wrong method with no competing route match (code-traced) | See 405 example |
| 422 | Any `CartCreateRequest` validation failure, rendered by the `ValidationException` branch of `Handler` (message = `ValidationException::summarize`) with the full map under `errors` | `{message: string, status: boolean, errors: object}` | None | First failure message (dynamic per failure) | Map of `item` / `item.{field}` → message arrays (key verified from rule targets; exact messages **Unverified in source**) | Missing/non-array/empty `item`; unknown/non-integer `product_id`; quantity `< 1` or above the configured max (verified trigger: `999` → 422 with inventory untouched); unknown variant; bad `shipping_method` | See 422 example |
| 429 | Exceeding `throttle:cart` (20/min per user id or IP) | `{success: boolean, message: string, data: null}` (rate-limiter shape; note `success`/`data` keys instead of the Handler `status` shape) | None | `__('message.too_many_requests')`: `en` → `"Too many requests. Please try again later."`, `ar` → `"طلبات كثيرة جدًا. يرجى المحاولة لاحقًا."` | None | Rate limit exceeded for the caller key (the cart suite disables this limiter in `setUp` — no endpoint-specific 429 test exists) | See 429 example |

There is no 500 contract on this action (all `\Exception`s are caught → 400).

400 example (repository failure; exact text dynamic):

```json
{
  "status": 400,
  "message": "Product not found.",
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

405 example (wrong method, e.g. `PUT /api/v1/cart`):

```json
{
  "message": "Method Not Allowed",
  "status": false
}
```

422 example (excessive quantity):

```json
{
  "message": "The item.quantity may not be greater than 100.",
  "status": false,
  "errors": {
    "item.quantity": [
      "The item.quantity may not be greater than 100."
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
