# Endpoint Name

Cart – List (`GET /api/v1/cart`)

# Description

Returns the caller's carts paginated with promotion-aware totals and sectioned items.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/cart` |
| Path composition | `api/v1` prefix from `packages/marvel/src/Providers/RestAPIServiceProvider.php` + `cart` from the cart group in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:cart', 'lang']` group |
| Controller action | `Marvel\Http\Controllers\CartController@index` (user-scoped ONLY when authenticated — guests are already rejected by `auth:sanctum`, so the `if ($user)` guard is defense-in-depth; without it an (unreachable) guest would list every cart) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | None required (no permission middleware on this controller or route — any authenticated user) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` (error responses are JSON for all `/api/*` paths regardless of `Accept`, per `App\Exceptions\Handler::shouldReturnJson`) |
| Optional headers | `lang: en\|ar` (default `en`; any other value is ignored and the locale is left unchanged, per `App\Http\Middleware\CheckLangMiddleware`) |
| Supported request formats | Query string only; no body is read |
| Rate limit | `throttle:cart`: 20 requests/minute per authenticated user id (or per IP for guests), per `App\Providers\AppServiceProvider::boot` |
| Route name | None defined for these routes |

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token enforced by `auth:sanctum` | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller (and scopes the listing) |
| `lang` | header | string | Optional | No | Must be in `['en', 'ar']` to take effect; other values ignored | `en`, `ar` | `en` | None | `en` | Selects response message locale |
| `Accept` | header | string | Recommended, not strictly required | No | Behaviorally relevant for content negotiation; JSON error shape is still returned for `/api/*` paths | `application/json` | None | None | `application/json` | Requests a JSON response |
| `limit` | query | integer | Optional | No | No validation; passed to `paginate($limit)` | Any positive integer | `15` | None | `20` | Page size (code-traced; no max cap is enforced) |
| `page` | query | integer | Optional | No | Native paginator parameter (plus `withQueryString` preserved links) | Any positive integer | `1` | None | `2` | Page number |

There is no `422` contract — no validation runs on this endpoint.

## Request examples

```json
{
  "method": "GET",
  "url": "/api/v1/cart?limit=20",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: page of carts — HTTP 200

Returned via `ApiResponse::apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, [...])` with the roles-list-style manual pagination payload (same key set incl. `""`-fallback URL fields). Verified by `test_cart_index_returns_empty_when_no_cart` (200, `success: true` with no cart) and `test_cart_index_requires_auth` (401 guest); both use `getJson`, matching this route. In practice one row per user. There is exactly one success contract.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.FETCH_DATA_SUCCESSFULLY` (locale from `lang` header) | `en` → `"Data fetched successfully"`, `ar` → `"تم جلب البيانات بنجاح"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | Manually built page payload | See `data` fields below |

`data` fields (code-verified against the `apiResponse` payload):

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `data.data[]` | array of objects | Always present (possibly empty) | Caller carts serialized by `CartResource` | `id`, `user_id`, `coupon` (object or `null` — resolved via canonical code lookup), `coupon_code`, `status`, `reserved_at`, `expires_at`, `total_items`, `total_quantity`, `total_price`/`subtotal` (effective-currency converted), `coupon_discount`, `total_after_coupon`, `currency`, `normal_items_count`, `fast_items_count`, `normal_items[]`, `fast_items[]` (split by `shipping_method`), `has_eligible_promotion` (bool). Item-level shape (`CartItemResource`) **Unverified in source** — no test asserts item keys |
| `data.page` / `data.current_page` / `data.from` / `data.to` / `data.last_page` / `data.path` / `data.per_page` / `data.total` / `data.next_page_url` / `data.prev_page_url` / `data.last_page_url` / `data.first_page_url` | mixed | Always present | Manual pagination (same contract as the users/roles lists; `?? ""`/`?? 0` fallbacks) | See those docs for fallback semantics |

Complete success example (`en` locale; rows illustrative, keys verified):

```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": {
    "data": [],
    "page": 1,
    "current_page": 1,
    "from": 0,
    "to": 0,
    "last_page": 1,
    "path": "http://localhost/api/v1/cart",
    "per_page": 15,
    "total": 0,
    "next_page_url": "",
    "prev_page_url": "",
    "last_page_url": "http://localhost/api/v1/cart?page=1",
    "first_page_url": "http://localhost/api/v1/cart?page=1"
  }
}
```

# Errors

The implementation has no machine-readable error-code field; every error below has Error code `None`. There is no validation, so there is no `422` contract and no `errors` map. The 200/401 contracts are test-verified (both use `getJson`); all other rows are code-verified or code-traced as marked.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing, invalid, revoked, or expired Sanctum token (`auth:sanctum` rejects before the controller runs) | `{message: string, status: boolean}` (Handler shape; note boolean `status`, unlike the success envelope) | None | `"Unauthenticated"` via `AuthenticationException` branch of `App\Exceptions\Handler` | None | Any request without a valid `Authorization: Bearer` token; verified by `test_cart_index_requires_auth` expecting 401 | See 401 example |
| 405 | `PUT`, `PATCH`, or `DELETE` on the bare `/api/v1/cart` path (no such routes are registered; note `POST /cart` is the separate store endpoint, not a 405) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` via `MethodNotAllowedHttpException` branch of `Handler` | None | Wrong method with no competing route match (code-traced) | See 405 example |
| 429 | Exceeding `throttle:cart` (20/min per user id or IP) | `{success: boolean, message: string, data: null}` (rate-limiter shape; note `success`/`data` keys instead of the Handler `status` shape) | None | `__('message.too_many_requests')`: `en` → `"Too many requests. Please try again later."`, `ar` → `"طلبات كثيرة جدًا. يرجى المحاولة لاحقًا."` | None | Rate limit exceeded for the caller key (the cart suite disables this limiter in `setUp` — no endpoint-specific 429 test exists) | See 429 example |
| 500 | Unexpected failure (any exception), rendered by the generic branch of `Handler` (the method has no try/catch) | `{message: string, status: boolean}` | None | The exception message when `app.debug` is true, else `"Internal Server Error"` | None | Query failure (code-traced, no test) | See 500 example |

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
