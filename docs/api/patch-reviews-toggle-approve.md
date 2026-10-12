# Endpoint Name

Reviews – Toggle Approve Review (`PATCH /api/v1/reviews/{id}/toggle-approve`)

# Description

Flips the approval flag of one product review and fires the matching approval event.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `PATCH` (only `PATCH` is registered — `PUT`/`POST` on this path → 405; the apiResource has no `update` route, so no collision regardless, and the custom route is registered BEFORE it) |
| Full path | `/api/v1/reviews/{id}/toggle-approve` (explicit `{id}` placeholder with NO `whereNumber` constraint, unlike the site-review routes — code-verified) |
| Path composition | `api/v1` prefix + `reviews/{id}/toggle-approve` custom `Route::patch` in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group with single-permission `permission:approve-reviews` |
| Controller action | `Marvel\Http\Controllers\ReviewController@toggleApproveReview($id)` → `ReviewRepository::toggleApprove`: `findOrFail`, flips `approved`, saves, fires `ReviewApproved` on false→true and `ReviewRejected` on true→false (queued listeners per established event patterns — side effects, code-verified); ANY exception → `HttpException(400, SOMETHING_WENT_WRONG)`, which is NOT a `MarvelException` so it ESCAPES the controller catch-all into `Handler`'s `HttpException` branch → 400 with the RAW key (same wart class as the banner/governorate toggle edges) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `approve-reviews` via route middleware AND constructor `only: [toggleApproveReview]` (double gate, same permission — code-verified) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en\|ar` (default `en`) |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | None (`->name()` absent — code-verified) |

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | In `['en', 'ar']` | `en`, `ar` | `en` | None | `en` | Selects message locale |
| `id` | path | mixed id | Required | No | No constraint; passed to `findOrFail` (soft-deleted ids miss) | Id of a non-deleted review | None | None | `1` | Identifies the review to flip |

No query parameters, body fields, or `422` contract — the toggle is unconditional (no target value is sent; it always flips).

## Request examples

```json
{
  "method": "PATCH",
  "url": "/api/v1/reviews/1/toggle-approve",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: approval flipped — HTTP 200

Returned via `ApiResponse::apiResponse(REVIEW_UPDATED_SUCCESSFULLY, 200, true, ReviewResource::make($review))`. Tests hedge (`assertContains([200, 500])` in `test_admin_can_toggle_review_approval`, `patchJson` matching this route and method). The caller holds `approve-reviews` (it passed the gate), so `is_approved` is always present here.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.REVIEW_UPDATED_SUCCESSFULLY` | `en` → `"Review updated successfully"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | Toggled `ReviewResource` (the NEW flag value) | `id`, `rating`, `comment`, `images`, `is_approved` (bool — always present here) |

```json
{
  "status": 200,
  "message": "Review updated successfully",
  "success": true,
  "data": {
    "id": 1,
    "rating": 5,
    "comment": "Great product!",
    "images": [],
    "is_approved": false
  }
}
```

# Errors

No error-code field; every error below has Error code `None`. No validation, so no `422`.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | No valid token (strict `assertEquals(401)` in `test_guest_cannot_toggle_approve_review`, `patchJson`) | See 401 example |
| 403 | Authenticated caller lacks `approve-reviews` | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token without `approve-reviews` (code-traced double gate; no toggle-specific 403 test) | See 403 example |
| 400 | Unknown/soft-deleted `{id}` surfacing through the repository `HttpException(400, SOMETHING_WENT_WRONG)` → `Handler` renders the RAW key untranslated (code-verified wart) | `{message: string, status: boolean}` | None | `"ERROR.SOMETHING_WENT_WRONG"` (RAW key — `Handler` does not translate `HttpException` messages) | None | Unknown id taking the `HttpException` path (allowed by `test_toggle_approve_nonexistent_review_returns_error` hedging `[400, 404, 500]` for `99999`) | See 400 example |
| 404 | Unknown `{id}` surfacing as `ModelNotFoundException` instead (depends on Prettus internals, **Unverified in source**) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string) | None | Unknown id taking the `ModelNotFoundException` path (same hedge) | See 404 example |
| 405 | `PUT`/`POST` on `/api/v1/reviews/{id}/toggle-approve` (only `PATCH` registered) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` | None | Wrong method (code-traced) | See 405 example |
| 500 | Post-auth failure outside the above paths (explicitly allowed — both toggle tests hedge 500 even for the happy path) | `{message: string, status: boolean}` | None | `"Internal Server Error"` (or the live message when debug is on) | None | Any post-auth failure | See 500 example |
| 429 | Exceeding `throttle:admin` | `{success: boolean, message: string, data: null}` | None | `__('message.too_many_requests')` | None | Rate limit exceeded | See 429 example |

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

400 example (miss via repository `HttpException`):

```json
{
  "message": "ERROR.SOMETHING_WENT_WRONG",
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

405 example:

```json
{
  "message": "Method Not Allowed",
  "status": false
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
