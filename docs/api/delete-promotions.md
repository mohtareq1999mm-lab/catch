# Endpoint Name

Promotions – Delete Promotion (`DELETE /api/v1/promotions/{promotion}`)

# Description

Hard-deletes one promotion by id with all failures mapped to 400 and returns 200.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `DELETE` |
| Full path | `/api/v1/promotions/{promotion}` |
| Path composition | `api/v1` prefix + `promotions/{promotion}` from bare `Route::apiResource('promotions', ...)` (no route middleware; the in-controller `delete-promotion` gate decides) in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group |
| Controller action | `Marvel\Http\Controllers\PromotionController@destroy($id)` → `findOrFail($id)->delete()` (`Promotion` has NO `SoftDeletes` trait — HARD delete, verified by `assertDatabaseMissing`); the `catch (Throwable)` converts EVERYTHING — including the miss — into `apiResponse(COULD_NOT_DELETE_THE_RESOURCE, 400, false)` (translated; code-verified wart: deleting an unknown id yields 400, NOT 404 — the widest miss-mapping in this batch) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `delete-promotion` via controller constructor (applies only to `destroy`) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en\|ar` (default `en`) |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `promotions.destroy` (apiResource default naming) |

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | In `['en', 'ar']` | `en`, `ar` | `en` | None | `en` | Selects message locale |
| `promotion` | path | mixed id | Required | No | No route constraint; passed to `findOrFail` | Existing promotion id | None | None | `1` | Identifies the promotion |

No query parameters, body fields, or `422` contract. Product links detach via FK/pivot behavior at the database level (**Unverified in source** — no explicit detach in code).

## Request examples

```json
{
  "method": "DELETE",
  "url": "/api/v1/promotions/1",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: promotion hard-deleted — HTTP 200

Returned via `ApiResponse::apiResponse(DELETED_PROMOTION_SUCCESSFULLY, 200, true)` with NO data key (plus `flushTag(PROMOTIONS)`). Verified by `test_admin_can_delete_promotion` (`assertOk`, `assertDatabaseMissing`, `deleteJson`).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.DELETED_PROMOTION_SUCCESSFULLY` | `en` → `"Promotion deleted successfully"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |

```json
{
  "status": 200,
  "message": "Promotion deleted successfully",
  "success": true
}
```

# Errors

No error-code field; every error below has Error code `None`. No validation and no 404 — this action's signature wart is the 400-miss.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | No valid token (code-traced) | See 401 example |
| 403 | Authenticated caller lacks `delete-promotion` | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token without `delete-promotion` (code-traced) | See 403 example |
| 400 | Unknown `{promotion}` (miss → total catch → translated 400 — NOT 404; code-verified wart shared with NO other delete endpoint) OR any delete failure | `{status: integer, message: string, success: boolean}` | None | Translated `ERROR.COULD_NOT_DELETE_THE_RESOURCE`: `en` → `"Could not delete the resource"` | None | Unknown id or delete failure (code-traced; no delete-miss test found) | See 400 example |
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

400 example (unknown id OR delete failure — translated):

```json
{
  "status": 400,
  "message": "Could not delete the resource",
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
