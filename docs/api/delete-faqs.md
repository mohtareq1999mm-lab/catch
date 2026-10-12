# Endpoint Name

FAQs – Delete FAQ (`DELETE /api/v1/faqs/{faq}`)

# Description

Soft-deletes one FAQ by id behind a double permission gate and returns 200.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `DELETE` |
| Full path | `/api/v1/faqs/{faq}` |
| Path composition | `api/v1` prefix + `faqs/{faq}` from `Route::apiResource('faqs', ...)` in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group, plus route-level ANY-of pipe middleware AND constructor `delete-faq` gate AND an INLINE `$user->hasPermissionTo(DELETE_FAQ)` re-check inside `deleteFaq` (TRIPLE gate, same permission — code-verified; the auth test even asserts the route gathers `permission:delete-faq` middleware) |
| Controller action | `Marvel\Http\Controllers\FaqsController@destroy($id, Request $request)` → merges path `id` → `deleteFaq`: inline permission re-check (failure throws `AuthorizationException(NOT_AUTHORIZED)` → NOT a `MarvelException` → escapes to `Handler`'s `AuthorizationException` branch → 403 `'This action is unauthorized'` — dead in practice since the constructor gate is identical, code-verified); then `findOrFail` + soft-delete (`Faqs` uses `SoftDeletes`, verified `assertSoftDeleted`) + `flushTag(FAQS)`; the miss (`ModelNotFoundException`) is NOT a `MarvelException`, so it ESCAPES the `catch (MarvelException)` (which rethrows as `MarvelException(NOT_FOUND)`) into `Handler` → 404...(see Errors — the rethrow line is dead for real misses; code-verified) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required: `delete-faq` (route pipe + constructor `only: [destroy]` + inline re-check — all three the same permission) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en\|ar` (default `en`) |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `faqs.destroy` (apiResource default naming — existence asserted in-test via `getByName('faqs.destroy')`) |

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | In `['en', 'ar']` | `en`, `ar` | `en` | None | `en` | Selects message locale |
| `faq` | path | mixed id | Required | No | No route constraint; merged into the request as `id` for `findOrFail` (soft-deleted ids miss — no restore path here) | Id of a non-deleted FAQ | None | None | `1` | Identifies the FAQ |

No query parameters, body fields, or `422` contract.

## Request examples

```json
{
  "method": "DELETE",
  "url": "/api/v1/faqs/1",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: FAQ soft-deleted — HTTP 200

Returned via `ApiResponse::apiResponse(FAQ_DELETED_SUCCESSFULLY, 200, true)` with NO data key. Verified by `can_delete_faq` (`assertOk`, `success: true`, `assertSoftDeleted`) and `user_with_delete_only_can_delete` (`assertOk`), both `deleteJson`.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.FAQ_DELETED_SUCCESSFULLY` | `en` → `"FAQ deleted successfully"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |

```json
{
  "status": 200,
  "message": "FAQ deleted successfully",
  "success": true
}
```

# Errors

No error-code field; every error below has Error code `None`. No validation, so no `422`.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | No valid token (verified by `unauthenticated_user_cannot_delete_faq` → 401) | See 401 example |
| 403 | Authenticated caller lacks `delete-faq` (constructor gate rejects; the inline re-check would render `'This action is unauthorized'` instead, but it is unreachable behind the identical constructor gate) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` (constructor path) | None | View-only user (verified by `user_with_view_only_cannot_delete` → 403) | See 403 example |
| 404 | No FAQ with id (miss escapes as `ModelNotFoundException`; the `throw new MarvelException(NOT_FOUND)` rethrow never fires for real misses) | `{message: string, status: boolean}` (Handler shape) | None | `"Resource Not Found"` (fixed string — same shape family as the show-miss test) | None | Unknown id (verified by `delete_returns_404_for_nonexistent_faq` for `99999` — status-only assertion, consistent with this shape) | See 404 example |
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

404 example:

```json
{
  "message": "Resource Not Found",
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
