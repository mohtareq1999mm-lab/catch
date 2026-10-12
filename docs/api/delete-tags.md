# Endpoint Name

Tags – Delete Tag (`DELETE /api/v1/tags/{tag}`)

# Description

Hard-deletes one tag by id and returns 200 with a boolean data payload.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `DELETE` |
| Full path | `/api/v1/tags/{tag}` |
| Path composition | `api/v1` prefix + `tags/{tag}` from bare `Route::apiResource('tags', ...)` (no route middleware; the in-controller `delete-tags` gate decides) in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group |
| Controller action | `Marvel\Http\Controllers\TagController@destroy($id)` → `findOrFail($id)->delete()` (`Tag` has NO `SoftDeletes` trait — HARD delete, code-verified, unlike FAQ/flash-sale soft deletes); the miss (`ModelNotFoundException`) is NOT a `MarvelException`, so it ESCAPES the `catch (MarvelException)` (whose `throw new MarvelException(COULD_NOT_DELETE_THE_RESOURCE)` rethrow → 500 translated — dead for real misses) into `Handler` → 404 FIXED string (code-verified) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `delete-tags` via controller constructor (applies only to `destroy`) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en\|ar` (default `en`) |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `tags.destroy` (apiResource default naming) |

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | In `['en', 'ar']` | `en`, `ar` | `en` | None | `en` | Selects message locale |
| `tag` | path | mixed id | Required | No | No route constraint; passed to `findOrFail` | Existing tag id | None | None | `1` | Identifies the tag |

No query parameters, body fields, or `422` contract. Product links detach via database-level pivot behavior (**Unverified in source** — no explicit detach in code); media rows cleanup is media-library level (**Unverified in source**). No endpoint test found for the ADMIN destroy — contracts code-traced.

## Request examples

```json
{
  "method": "DELETE",
  "url": "/api/v1/tags/1",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: tag hard-deleted — HTTP 200 (WITH `data: true`)

Returned via `ApiResponse::apiResponse(TAG_DELETED_SUCCESSFULLY, 200, true, true)` — the explicit fourth arg `true` is NON-empty, so the response carries `"data": true` (code-verified wart: the ONLY delete endpoint in this batch with a data payload; compare all others' no-data 200s).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.TAG_DELETED_SUCCESSFULLY` | `en` → `"Tag deleted successfully"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | boolean | Always present on success | Literal `true` (NOT the deleted row — code-verified) | Always `true` |

```json
{
  "status": 200,
  "message": "Tag deleted successfully",
  "success": true,
  "data": true
}
```

# Errors

No error-code field; every error below has Error code `None`. No validation, so no `422`.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | No valid token (code-traced) | See 401 example |
| 403 | Authenticated caller lacks `delete-tags` | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token without `delete-tags` (code-traced) | See 403 example |
| 404 | No tag with id (miss escapes as `ModelNotFoundException` → fixed string; the 500 rethrow is dead for real misses) | `{message: string, status: boolean}` (Handler shape) | None | `"Resource Not Found"` (fixed string) | None | Unknown id | See 404 example |
| 500 | A `MarvelException` surfaces (rethrow → translated 500; nearly dead in practice) | `{message: string, status: boolean}` | None | Translated `ERROR.COULD_NOT_DELETE_THE_RESOURCE`: `en` → `"Could not delete the resource"` | None | `MarvelException`-sourced failure (code-traced) | See 500 example |
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

500 example:

```json
{
  "message": "Could not delete the resource",
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
