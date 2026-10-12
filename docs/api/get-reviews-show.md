# Endpoint Name

Reviews – Show Review (`GET /api/v1/reviews/{review}`)

# Description

Returns one product review by id with media links and a permission-gated approval flag.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/reviews/{review}` |
| Path composition | `api/v1` prefix + `reviews/{review}` from `Route::apiResource('reviews', ...)->except(['store', 'update'])` in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group, plus route-level ANY-of pipe middleware (no constructor gate covers `show` — the route pipe alone decides) |
| Controller action | `Marvel\Http\Controllers\ReviewController@show($id)` (untyped — no implicit binding, manual lookup) → `ReviewRepository::findOrFail($id)`; the `catch (MarvelException)` converts ONLY `MarvelException`s, but a Prettus miss throws `RepositoryException`/`ModelNotFoundException` — which ESCAPES to `Handler` (code-verified shape split: `ModelNotFoundException` → 404 fixed string, anything else → 500; the in-controller `throw new MarvelException(NOT_FOUND)` line is effectively dead for real misses) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: ANY-OF `view-contacts\|update-contact\|delete-contact\|approve-reviews\|delete-reviews` via the route pipe (same legacy contact-permission wart as the index) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en\|ar` (default `en`) |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `reviews.show` (apiResource default naming) |

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token enforced by `auth:sanctum` | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | Must be in `['en', 'ar']` to take effect | `en`, `ar` | `en` | None | `en` | Selects response message locale |
| `Accept` | header | string | Recommended, not strictly required | No | Content negotiation only | `application/json` | None | None | `application/json` | Requests a JSON response |
| `review` | path | mixed id | Required | No | No route-level constraint; passed straight to `findOrFail` (soft-deleted ids miss — `Review` uses `SoftDeletes`, code-verified) | Id of a non-deleted review | None | None | `1` | Identifies the review |

There are no query parameters, body fields, multipart fields, or file uploads. There is no `422` contract.

## Request examples

Valid request showing a review (test-compatible call):

```json
{
  "method": "GET",
  "url": "/api/v1/reviews/1",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: review returned — HTTP 200

Returned via `ApiResponse::apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, ReviewResource::make($review))`. Tests hedge (`assertContains([200, 500])` in `test_admin_can_show_review`, `getJson`), so the 200 below is code-traced with a documented 500 edge.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.FETCH_DATA_SUCCESSFULLY` | `en` → `"Data fetched successfully"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | Marvel `ReviewResource` (NOTE: no `user_id`/`product_id`/`user` keys — the `user` line is commented out in source, code-verified) | `id`, `rating`, `comment`, `images` (array of URLs), plus conditional `is_approved` |
| `data.is_approved` | boolean | Conditional | Present ONLY when the caller has `approve-reviews` (`mergeWhen` — code-verified) | `true`/`false` |

```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": {
    "id": 1,
    "rating": 5,
    "comment": "Great product!",
    "images": [],
    "is_approved": true
  }
}
```

# Errors

No error-code field; every error below has Error code `None`. There is no validation, so there is no `422` contract.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing, invalid, revoked, or expired Sanctum token | `{message: string, status: boolean}` | None | `"Unauthenticated"` via `AuthenticationException` branch | None | Any request without a valid token (guest show hedges `[401, 403, 404]` in `test_guest_cannot_show_review` — 401 is the code-traced outcome for a bare guest) | See 401 example |
| 403 | Authenticated caller holds NONE of the five pipe permissions | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token without any pipe permission (code-traced; no show-specific 403 test) | See 403 example |
| 404 | Miss surfaces as `ModelNotFoundException` → `Handler` fixed string (ONE of the two miss shapes — the other is the 500 row; which one fires depends on the Prettus internals, **Unverified in source**) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string, NOT translated) | None | Unknown id (hedged `[404, 500]` by `test_show_nonexistent_review_returns_404` for `99999`) | See 404 example |
| 500 | Miss surfaces as a non-`ModelNotFoundException` (e.g. Prettus `RepositoryException`) → generic 500 (the suite explicitly allows this — hedged `[404, 500]` even for the miss path) | `{message: string, status: boolean}` | None | `"Internal Server Error"` (or the live message when debug is on) | None | Unknown id taking the non-`ModelNotFoundException` path, or any post-auth failure | See 500 example |
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

404 example (miss via `ModelNotFoundException`):

```json
{
  "message": "Resource Not Found",
  "status": false
}
```

500 example (miss via the other path):

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
