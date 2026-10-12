# Endpoint Name

Governorates – Bulk Status (`PUT /api/v1/governorates/change-status`)

# Description

Bulk-updates the status flag of a list of governorates and returns 200 with no data.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `PUT` (note: countries sibling uses `POST` — code-verified asymmetry; only `PUT` is registered here, so `POST` on this path → 405) |
| Full path | `/api/v1/governorates/change-status` |
| Path composition | `api/v1` prefix + `governorates/change-status` custom `Route::put` in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group with `['auth:sanctum', 'permission:update-governorate']`. Registered BEFORE `apiResource('governorates', ...)`, so `change-status` is matched here and NOT captured as `governorates/{governorate}` (code-verified ordering safety) |
| Controller action | `Marvel\Http\Controllers\GovernorateController@bulkStatus` → `GovernorateRepository::bulkStatus(ids, status)` + `flushTag(governorates)`; returns `apiResponse(BULK_STATUS_UPDATED_SUCCESSFULLY, 200, true)` with NO data key (NOTE: countries sibling returns `['updated' => $count]` — code-verified difference) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `update-governorate` via route middleware (constructor does NOT gate `bulkStatus`) |
| Required headers | `Authorization: Bearer <sanctum-token>`, `Content-Type: application/json` |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en\|ar` (default `en`) |
| Supported request formats | `application/json` |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | None (`->name()` absent — code-verified) |

Middleware runs before validation: 401/403 precede 422.

## Request inputs

`BulkStatusRequest::resolveTable`: path contains `governorates` → `governorates` table, so `ids.*` validates `exists:governorates,id` (code-verified).

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | In `['en', 'ar']` | `en`, `ar` | `en` | None | `en` | Selects message locale |
| `ids` | body | array of integers | Required | No | `required\|array\|min:1` | Non-empty array | None | None | `[1, 2]` | Governorates to update |
| `ids.*` | body | integer | Required per element | No | `integer\|min:1\|distinct\|exists:governorates,id` | Existing governorate ids, no duplicates | None | None | `1` | One governorate id |
| `status` | body | integer | Required | No | `required\|in:0,1` (strict `0`/`1`) | `0`, `1` | None | None | `1` | Target status |

No path or query parameters.

## Request examples

```json
{
  "method": "PUT",
  "url": "/api/v1/governorates/change-status",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json",
    "lang": "en"
  },
  "body": {
    "ids": [1, 2],
    "status": 1
  }
}
```

# Responses

## Success: statuses updated — HTTP 200

Returned via `ApiResponse::apiResponse(BULK_STATUS_UPDATED_SUCCESSFULLY, 200, true)` with NO data key. Verified by `test_authenticated_admin_can_update_governorate_bulk_status` (`assertOk`, `success: true`, DB rows `status: 1`) using `putJson`, matching this route and method.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.BULK_STATUS_UPDATED_SUCCESSFULLY` | `en` → `"Bulk status updated successfully"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |

```json
{
  "status": 200,
  "message": "Bulk status updated successfully",
  "success": true
}
```

# Errors

No error-code field; every error below has Error code `None`.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | No valid token (verified by `test_guest_gets_401_for_bulk_status`, `putJson`) | See 401 example |
| 403 | Authenticated caller lacks `update-governorate` | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token without `update-governorate` (code-traced; no bulk-specific 403 test found) | See 403 example |
| 405 | `POST` on `/api/v1/governorates/change-status` (only `PUT` is registered) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` | None | Wrong method on this path (code-traced) | See 405 example |
| 422 | `BulkStatusRequest` fails (missing/empty `ids`, duplicate/unknown id, missing or non-`0`/`1` `status`) | `{message: string, status: boolean, errors: object}` | None | Laravel default | Field → messages | Empty body (verified by `test_governorate_bulk_status_returns_422_for_missing_ids`) | See 422 example |
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

405 example:

```json
{
  "message": "Method Not Allowed",
  "status": false
}
```

422 example:

```json
{
  "message": "The ids field is required. (and 1 more error)",
  "status": false,
  "errors": {
    "ids": ["The ids field is required."],
    "status": ["The status field is required."]
  }
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
