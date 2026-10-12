# Endpoint Name

Banners – Delete Banner (`DELETE /api/v1/banners/{banner}`)

# Description

Soft-deletes one banner by id and returns 200 with no data payload.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `DELETE` |
| Full path | `/api/v1/banners/{banner}` |
| Path composition | `api/v1` prefix + `banners/{banner}` from `Route::apiResource('banners', ...)` in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group, plus route-level ANY-of pipe middleware (constructor `delete-banners` gate decides) |
| Controller action | `Marvel\Http\Controllers\BannerController@destroy(string $id)` → `findOrFail($id)` (miss on unknown AND soft-deleted ids); then `$banner->delete()` (SoftDeletes → row kept with `deleted_at`, excluded from index — verified by `test_soft_deleted_banner_not_listed_in_index`); ANY `\Exception` → `apiResponse(NOT_FOUND, 404, false, null)` (the explicit `null` data arg is a no-op since empty data is omitted — code-verified) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `delete-banners` via controller constructor (applies only to `destroy`) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en\|ar` (default `en`) |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `banners.destroy` (apiResource default naming) |

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | In `['en', 'ar']` | `en`, `ar` | `en` | None | `en` | Selects message locale |
| `banner` | path | string id | Required | No | No route constraint; `string $id` → `findOrFail` | Id of a non-deleted banner | None | None | `1` | Identifies the banner |

No query parameters, body fields, or `422` contract. Media rows and `banner_product` pivot rows are NOT cleaned up in code (orphan behavior is database/media-library level, **Unverified in source**).

## Request examples

```json
{
  "method": "DELETE",
  "url": "/api/v1/banners/1",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: banner soft-deleted — HTTP 200

Returned via `ApiResponse::apiResponse(BANNER_DELETED_SUCCESSFULLY, 200, true)` with NO data key. Verified by `test_authenticated_admin_can_soft_delete_banner` (`assertOk`, `success: true`, `assertSoftDeleted('banners', ...)`) using `deleteJson`.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.BANNER_DELETED_SUCCESSFULLY` | `en` → `"Banner deleted successfully"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |

```json
{
  "status": 200,
  "message": "Banner deleted successfully",
  "success": true
}
```

# Errors

No error-code field; every error below has Error code `None`. No validation, so no `422`. NOTE: deleting an already-soft-deleted id yields 404 (the `findOrFail` misses it) — there is no restore path on this endpoint.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | No valid token (verified by `test_guest_gets_401_for_delete_banner`) | See 401 example |
| 403 | Authenticated caller lacks `delete-banners` | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token + only `view-banners` (verified by `test_user_without_delete_permission_gets_forbidden_for_destroy`) | See 403 example |
| 404 | Unknown, already-soft-deleted, or non-numeric `{banner}` (any lookup/delete failure collapses to 404 via the catch-all) | `{status: integer, message: string, success: boolean}` | None | `en` → `"Not found"` (generic) | None | Unknown id (verified by `test_delete_banner_returns_404_for_nonexistent_id` for `9999`) | See 404 example |
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
  "status": 404,
  "message": "Not found",
  "success": false
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
