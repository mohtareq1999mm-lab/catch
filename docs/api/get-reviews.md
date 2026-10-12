# Endpoint Name

Reviews – List Reviews (`GET /api/v1/reviews`)

# Description

Returns a paginated list of product reviews for a required product id.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/reviews` |
| Path composition | `api/v1` prefix from `packages/marvel/src/Providers/RestAPIServiceProvider.php` + `reviews` from `Route::apiResource('reviews', ...)->except(['store', 'update'])` in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group, plus route-level ANY-of pipe middleware (no constructor gate covers `index` — the route pipe alone decides) |
| Controller action | `Marvel\Http\Controllers\ReviewController@index` → inline `$request->validate(['product_id' => 'required\|integer\|exists:products,id'])` (→ 422); then `ReviewRepository::where('product_id', ...)->paginate($limit)` with `$limit = $request->limit ?: 15` (NOTE: reads `limit`, not `per_page` — code-verified wart); the repository boots Prettus `RequestCriteria`, so filter/sort query keys may also apply (**Unverified in source** — criteria internals not inspected) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: ANY-OF `view-contacts\|update-contact\|delete-contact\|approve-reviews\|delete-reviews` via the route pipe (LEGACY WART code-verified: product-review reads are gated by CONTACT permission names, not review names; no constructor middleware covers `index`/`show`) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en\|ar` (default `en`; other values ignored, per `App\Http\Middleware\CheckLangMiddleware`) |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute per authenticated user id (or per IP for guests), per `App\Providers\AppServiceProvider::boot` |
| Route name | `reviews.index` (apiResource default naming) |

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token enforced by `auth:sanctum` | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | Must be in `['en', 'ar']` to take effect | `en`, `ar` | `en` | None | `en` | Selects response message locale |
| `Accept` | header | string | Recommended | No | Content negotiation only | `application/json` | None | None | `application/json` | Requests a JSON response |
| `product_id` | query | integer | Required | No | `required\|integer\|exists:products,id` (scopeless — a soft-deleted product id passes validation, then yields an empty page, not 404; code-verified) | Id of an existing product | None | None | `10` | Restricts reviews to one product |
| `limit` | query | integer | Optional | No | Falsy (`0`, absent) → `15`; NOT `per_page` | Any positive integer | `15` | None | `15` | Page size |

No body fields, path parameters, or file uploads.

## Request examples

```json
{
  "method": "GET",
  "url": "/api/v1/reviews?product_id=10&limit=15",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: page returned — HTTP 200

Returned via `ApiResponse::apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, ReviewResource::collection($data))`. Tests hedge the status (`assertContains([200, 500])` in `test_admin_can_list_reviews`, `getJson`), so the 200 below is code-traced with a documented 500 edge rather than strictly test-pinned. Exact pagination meta keys are **Unverified in source** (no structure assertion in tests — unlike banners/site-reviews, this action does NOT flatten meta manually).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.FETCH_DATA_SUCCESSFULLY` | `en` → `"Data fetched successfully"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | array/object | Always present (possibly empty) | Items by Marvel `ReviewResource` (paginator serialization — exact meta keys **Unverified in source**) | Item: `id`, `rating`, `comment`, `images` (array of URLs), plus conditional `is_approved` (see below) |
| `data[].is_approved` | boolean | Conditional | Present ONLY when the caller has the `approve-reviews` permission (`mergeWhen(auth()->user()?->hasPermissionTo('approve-reviews'))` — code-verified conditional key; ordinary readers never see it) | `true`/`false` |

```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": [
    {
      "id": 1,
      "rating": 5,
      "comment": "Great product!",
      "images": [],
      "is_approved": true
    }
  ]
}
```

# Errors

No error-code field; every error below has Error code `None`.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing, invalid, revoked, or expired Sanctum token (`auth:sanctum` runs before the route pipe) | `{message: string, status: boolean}` | None | `"Unauthenticated"` via `AuthenticationException` branch | None | Any request without a valid token (guest list hedges `[401, 403, 422]` in `test_guest_cannot_list_reviews` — 401 is the code-traced outcome for a bare guest with a valid `product_id`; the 403/422 alternatives cover permission-less tokens and missing `product_id`) | See 401 example |
| 403 | Authenticated caller holds NONE of the five pipe permissions | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS`: `en` → `"User does not have the required permissions."` | None | Valid token without any of `view-contacts/update-contact/delete-contact/approve-reviews/delete-reviews` (code-traced; no index-specific 403 test) | See 403 example |
| 422 | Missing/invalid `product_id` (absent, non-integer, or unknown id) | `{message: string, status: boolean, errors: object}` | None | Laravel default | Field → messages | `GET /api/v1/reviews` without `product_id` (hedged `[422, 500]` by `test_list_reviews_requires_product_id` — 422 is the code-traced outcome) | See 422 example |
| 500 | Repository/pagination failure AFTER validation (the test suite explicitly allows 500 on this action — hedged `[200, 500]` even for the happy path, so a 500 here is a known-possible outcome, not just an edge) | `{message: string, status: boolean}` | None | `"Internal Server Error"` (or the live message when debug is on) | None | Post-validation failure (allowed by `test_admin_can_list_reviews`) | See 500 example |
| 429 | Exceeding `throttle:admin` (400/min per user id or IP) | `{success: boolean, message: string, data: null}` | None | `__('message.too_many_requests')` | None | Rate limit exceeded for the caller key | See 429 example |

401 example:

```json
{
  "message": "Unauthenticated",
  "status": false
}
```

403 example:

```json
{
  "message": "User does not have the required permissions.",
  "status": false
}
```

422 example:

```json
{
  "message": "The product id field is required.",
  "status": false,
  "errors": {
    "product_id": ["The product id field is required."]
  }
}
```

500 example:

```json
{
  "message": "Internal Server Error",
  "status": false
}
```

429 example:

```json
{
  "success": false,
  "message": "Too many requests. Please try again later.",
  "data": null
}
```
