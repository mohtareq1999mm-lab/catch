# Endpoint Name

Cart – Show Cart (`GET /api/v1/cart/{id}`)

# Description

Returns one of the caller's carts by id, indistinguishable from a miss when foreign.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/cart/{id}` |
| Path composition | `api/v1` prefix from `packages/marvel/src/Providers/RestAPIServiceProvider.php` + `cart/{id}` (with `whereNumber('id')`) from the cart group in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:cart', 'lang']` group |
| Controller action | `Marvel\Http\Controllers\CartController@show` (lookup scoped to the caller's `user_id` BEFORE resolving — foreign and nonexistent ids are deliberately indistinguishable, per the F-06 code comment) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | None required (no permission middleware on this controller or route — any authenticated user) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` (error responses are JSON for all `/api/*` paths regardless of `Accept`, per `App\Exceptions\Handler::shouldReturnJson`) |
| Optional headers | `lang: en\|ar` (default `en`; any other value is ignored and the locale is left unchanged, per `App\Http\Middleware\CheckLangMiddleware`) |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:cart`: 20 requests/minute per authenticated user id (or per IP for guests), per `App\Providers\AppServiceProvider::boot` |
| Route name | None defined for these routes |

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token enforced by `auth:sanctum` | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller (and scopes the lookup) |
| `lang` | header | string | Optional | No | Must be in `['en', 'ar']` to take effect; other values ignored | `en`, `ar` | `en` | None | `en` | Selects response message locale |
| `Accept` | header | string | Recommended, not strictly required | No | Behaviorally relevant for content negotiation; JSON error shape is still returned for `/api/*` paths | `application/json` | None | None | `application/json` | Requests a JSON response |
| `id` | path | integer (route-level `whereNumber` constraint) | Required | No | Must be numeric to match the route at all; then resolved by the caller-scoped `findOrFail`, which throws `ModelNotFoundException` for unknown OR foreign ids → `Handler` 404 (the method has no try/catch at all) | Id of one of the caller's own carts | None | None | `3` | Identifies the cart to return |

There are no query parameters, body fields, multipart fields, or file uploads. There is no `422` contract.

## Request examples

```json
{
  "method": "GET",
  "url": "/api/v1/cart/3",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: cart returned — HTTP 200

Returned via `ApiResponse::apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, CartResource::make($cart))` with items/product relations eager-loaded. No endpoint-specific success test exists (the cart suite covers index/store/update/delete paths; the show behavior below is code-verified). There is exactly one success contract.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.FETCH_DATA_SUCCESSFULLY` (locale from `lang` header) | `en` → `"Data fetched successfully"`, `ar` → `"تم جلب البيانات بنجاح"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | The cart serialized by `CartResource` (see the list doc for the full key set; item-level shape **Unverified in source**) | Promotion-aware totals included |

Complete success example (`en` locale; values illustrative, keys verified):

```json
{
  "status": 200,
  "message": "Data fetched successfully",
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

The implementation has no machine-readable error-code field; every error below has Error code `None`. There is no validation, so there is no `422` contract and no `errors` map. No test covers this endpoint: 401 expectations are verified against the shared `auth:sanctum` path exercised by named cart tests (cited per row); all other rows are code-verified or code-traced.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing, invalid, revoked, or expired Sanctum token (`auth:sanctum` rejects before the controller runs) | `{message: string, status: boolean}` (Handler shape; note boolean `status`, unlike the success envelope) | None | `"Unauthenticated"` via `AuthenticationException` branch of `App\Exceptions\Handler` (identical rejection test-verified on sibling cart routes) | None | Any request without a valid `Authorization: Bearer` token | See 401 example |
| 404 | No cart with this id among the CALLER's rows: the scoped `findOrFail` throws `ModelNotFoundException` → `Handler` renders its `ModelNotFoundException` branch. A foreign (another user's) id is indistinguishable from a nonexistent one (anti-oracle by design, per the F-06 comment) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string; not the translated `ERROR.NOT_FOUND`) | None | Unknown id or another user's cart id | See 404 example |
| 404 | Non-numeric `{id}`: the `whereNumber` constraint fails so NO route matches → `Handler` renders its `NotFoundHttpException` branch (code-verified distinct body from the lookup miss above) | `{message: string, status: boolean}` | None | `"Not Found"` (fixed string from the empty-exception-message fallback — code-traced) | None | E.g. `/cart/abc` | See 404 example (non-numeric) |
| 405 | `POST`, `PUT`, `PATCH`, or `DELETE` on `/api/v1/cart/{id}` (no such routes are registered; the suffixed cart-item URIs are separate actions) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` via `MethodNotAllowedHttpException` branch of `Handler` | None | Wrong method with no competing route match (code-traced) | See 405 example |
| 429 | Exceeding `throttle:cart` (20/min per user id or IP) | `{success: boolean, message: string, data: null}` (rate-limiter shape; note `success`/`data` keys instead of the Handler `status` shape) | None | `__('message.too_many_requests')`: `en` → `"Too many requests. Please try again later."`, `ar` → `"طلبات كثيرة جدًا. يرجى المحاولة لاحقًا."` | None | Rate limit exceeded for the caller key (the cart suite disables this limiter in `setUp` — no endpoint-specific 429 test exists) | See 429 example |
| 500 | Unexpected failure (any exception in the lookup/serialize), rendered by the generic branch of `Handler` (the method has no try/catch) | `{message: string, status: boolean}` | None | The exception message when `app.debug` is true, else `"Internal Server Error"` | None | Serialization failure (code-traced, no test) | See 500 example |

401 example (no or invalid token):

```json
{
  "message": "Unauthenticated",
  "status": false
}
```

404 example (unknown or foreign `{id}`):

```json
{
  "message": "Resource Not Found",
  "status": false
}
```

404 example (non-numeric `{id}`, e.g. `/cart/abc`):

```json
{
  "message": "Not Found",
  "status": false
}
```

405 example (wrong method, e.g. `POST /api/v1/cart/3`):

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
