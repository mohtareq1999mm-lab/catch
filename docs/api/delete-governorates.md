# Endpoint Name

Governorates – Delete Governorate (`DELETE /api/v1/governorates/{governorate}`)

# Description

Deletes one governorate by id when it has no cities, returning 200 with no data.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `DELETE` |
| Full path | `/api/v1/governorates/{governorate}` |
| Path composition | `api/v1` prefix + `governorates/{governorate}` from `Route::apiResource('governorates', ...)` in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group, plus route-level ANY-of pipe middleware (constructor `delete-governorate` gate decides) |
| Controller action | `Marvel\Http\Controllers\GovernorateController@destroy` → `findById($id)`; miss → `NOT_FOUND` 404; then `GovernorateRepository::delete` which throws `InvalidArgumentException('Cannot delete a governorate that has cities.')` when `cities()->exists()` (→ 500 edge, see Errors); else deletes + `flushTag(governorates)` |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `delete-governorate` via controller constructor (applies only to `destroy`) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en\|ar` (default `en`) |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `governorates.destroy` (apiResource default naming) |

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | In `['en', 'ar']` | `en`, `ar` | `en` | None | `en` | Selects message locale |
| `governorate` | path | integer | Required | No | No route constraint; `int $id` signature | Existing governorate id | None | None | `1` | Identifies the governorate |

No query parameters, body fields, or `422` contract.

## Request examples

```json
{
  "method": "DELETE",
  "url": "/api/v1/governorates/1",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: governorate deleted — HTTP 200

Returned via `ApiResponse::apiResponse(GOVERNORATE_DELETED_SUCCESSFULLY, 200, true)` with NO data key. Verified by `test_authenticated_admin_can_delete_governorate` (`assertOk`, `success: true`) using `deleteJson`.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.GOVERNORATE_DELETED_SUCCESSFULLY` | `en` → `"Governorate deleted successfully"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |

```json
{
  "status": 200,
  "message": "Governorate deleted successfully",
  "success": true
}
```

# Errors

No error-code field; every error below has Error code `None`. No validation, so no `422`.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | No valid token (verified by `test_guest_gets_401_for_delete_governorate`) | See 401 example |
| 403 | Authenticated caller lacks `delete-governorate` | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token + only `view-governorate` (verified by `test_user_without_delete_permission_gets_forbidden_for_destroy`) | See 403 example |
| 404 | No governorate with id | `{status: integer, message: string, success: boolean}` | None | `en` → `"Not found"` (generic) | None | Unknown id (verified by `test_delete_governorate_returns_404_for_nonexistent_id` for `9999`) | See 404 example |
| 500 | Governorate still has cities (`InvalidArgumentException('Cannot delete a governorate that has cities.')` matches NO Handler branch → generic 500; message leaks only when `config('app.debug')` is on) | `{message: string, status: boolean}` | None | `"Internal Server Error"` (or the raw message when debug is on) | None | Deleting a governorate with at least one city (code-verified guard, NOT test-verified here) | See 500 example |
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

500 example (has-cities guard, debug off):

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
