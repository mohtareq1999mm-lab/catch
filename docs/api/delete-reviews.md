# Endpoint Name

Reviews – Delete Review (`DELETE /api/v1/reviews/{review}`)

# Description

Soft-deletes one product review by id and returns 200 with no data payload.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `DELETE` |
| Full path | `/api/v1/reviews/{review}` |
| Path composition | `api/v1` prefix + `reviews/{review}` from `Route::apiResource('reviews', ...)->except(['store', 'update'])` in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group, plus route-level ANY-of pipe middleware AND constructor `delete-reviews` gate on `destroy` (double gate — the constructor's single permission is decisive for this action) |
| Controller action | `Marvel\Http\Controllers\ReviewController@destroy($id)` → `findOrFail($id)` then `$review->delete()` (`Review` uses `SoftDeletes` — row kept with `deleted_at`, code-verified); the `catch (MarvelException)` converts ONLY `MarvelException`s, so a real miss ESCAPES to `Handler` (same 404-or-500 shape split as show; the in-controller `throw new MarvelException(NOT_FOUND)` line is effectively dead for real misses) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `delete-reviews` via controller constructor (applies only to `destroy`); the route pipe's ANY-of set is redundant here since the constructor gate is stricter |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en\|ar` (default `en`) |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `reviews.destroy` (apiResource default naming) |

NOTE (code-verified): `store` and `update` are EXCEPTED from the apiResource, so `POST /api/v1/reviews` and `PUT/PATCH /api/v1/reviews/{review}` hit NO route (405) — only index/show/destroy/toggle-approve are reachable. The constructor's `store`/`update` gates are dead middleware over HTTP.

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | In `['en', 'ar']` | `en`, `ar` | `en` | None | `en` | Selects message locale |
| `review` | path | mixed id | Required | No | No route constraint; passed to `findOrFail` (soft-deleted ids miss → 404-or-500, no restore path here) | Id of a non-deleted review | None | None | `1` | Identifies the review |

No query parameters, body fields, or `422` contract. Media rows for the review are NOT cleaned up in code (orphan behavior is media-library level, **Unverified in source**).

## Request examples

```json
{
  "method": "DELETE",
  "url": "/api/v1/reviews/1",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: review soft-deleted — HTTP 200

Returned via `ApiResponse::apiResponse(REVIEW_DELETED_SUCCESSFULLY, 200, true)` with NO data key. Tests hedge (`assertContains([200, 500])` in `test_admin_can_delete_review`, `deleteJson` matching this route).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.REVIEW_DELETED_SUCCESSFULLY` | `en` → `"Review deleted successfully"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |

```json
{
  "status": 200,
  "message": "Review deleted successfully",
  "success": true
}
```

# Errors

No error-code field; every error below has Error code `None`. No validation, so no `422`.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | No valid token (strict `assertEquals(401)` in `test_guest_cannot_delete_review`, `deleteJson`) | See 401 example |
| 403 | Authenticated caller lacks `delete-reviews` (constructor gate rejects regardless of the route pipe) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token without `delete-reviews` (code-traced; no destroy-specific 403 test) | See 403 example |
| 404 | Miss surfaces as `ModelNotFoundException` → fixed string | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string, NOT translated) | None | Unknown id (hedged `[404, 500]` by `test_delete_nonexistent_review_returns_404` for `99999`) | See 404 example |
| 500 | Miss surfaces as a non-`ModelNotFoundException` → generic 500 (explicitly allowed by the hedge) | `{message: string, status: boolean}` | None | `"Internal Server Error"` (or the live message when debug is on) | None | Unknown id taking the other path, or any post-auth failure | See 500 example |
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
