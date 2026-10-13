# Endpoint Name

Packing Stations – Delete Station (`DELETE /api/v1/admin/packing-stations/{id}`)

# Description

Hard-deletes one idle packing station after an active-task check, returning 200 with no data.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `DELETE` |
| Full path | `/api/v1/admin/packing-stations/{id}` (explicit `{id}` placeholder with `whereNumber('id')`) |
| Path composition | `api/v1` prefix + `admin/packing-stations/{id}` custom `Route::delete(...)->name('api.admin.packing-stations.destroy')` in `packages/marvel/src/Rest/Routes.php` inside the `['api', 'auth:sanctum', 'throttle:admin']` group with route-level `permission:manage-warehouse` |
| Controller action | `App\Http\Controllers\Api\Admin\Wms\PackingStationController@destroy(int $id)` → `whereKey->firstOrFail` (miss → 404 fixed); `authorizeWarehouseScope(warehouse_id, 'manage-warehouse')` (verbatim-403 path); then the ACTIVE-TASK guard (`pending/assigned/started/packing` — FOUR statuses; code-verified closed set) → 422 live `'Cannot delete packing station: active packing tasks exist.'`; else hard `delete()` (`PackingStation` has NO `SoftDeletes` trait — HARD delete, code-verified from the model) → 200 with NO data key |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (group; no constructor middleware) |
| Permission | Required: `manage-warehouse` (route pipe + scope check — BOTH the same permission) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `api.admin.packing-stations.destroy` (explicit named route) |

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller (also the scope key) |
| `id` | path | integer | Required | No | `whereNumber('id')` + `int $id`; must have NO live packing tasks (else 422) | Idle station id manageable by the caller | None | None | `1` | Identifies the packing station |

No query parameters, body fields, or validation-driven `422` (the 422 below is the guard outcome, not validation). No endpoint test found for this action — contracts code-traced.

## Request examples

```json
{
  "method": "DELETE",
  "url": "/api/v1/admin/packing-stations/1",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json"
  }
}
```

# Responses

## Success: station hard-deleted — HTTP 200 (NO data key)

Returned via `ApiResponse::apiResponse(__('wms.packing.station_deleted'), 200, true)` (translated message; no payload).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `wms.packing.station_deleted` | `en` → `"Packing station deleted successfully."` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |

```json
{
  "status": 200,
  "message": "Packing station deleted successfully.",
  "success": true
}
```

# Errors

No error-code field; every error below has Error code `None`. No validation, so the 422 carries live text with NO `errors` map.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | No valid token (code-traced) | See 401 example |
| 403 | Authenticated caller lacks `manage-warehouse` (route pipe) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token without the permission (code-traced) | See 403 example |
| 403 | Out-of-scope `manage-warehouse` holder at the SCOPE layer (verbatim, WITH period) | `{message: string, status: boolean}` | None | `"Forbidden warehouse operation."` (verbatim) | None | Cross-warehouse delete (code-traced shape) | See 403-scope example |
| 404 | No station with `{id}` (lookup miss) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string) | None | Unknown numeric id (code-traced) | See 404 example |
| 404 | Non-numeric `{id}` matches NO route | `{message: string, status: boolean}` | None | `"Not Found"` (route-miss shape) | None | Non-numeric segment | See 404-constraint example |
| 422 | Station HAS live packing tasks (`pending/assigned/started/packing` — terminal/skipped/cancelled rows do NOT block; code-verified) | `{status: integer, message: string, success: boolean}` (NO `errors` map — thrown `HttpException`) | None | `"Cannot delete packing station: active packing tasks exist."` (live literal WITH period) | None | Busy station (code-traced) | See 422 example |
| 429 | Exceeding `throttle:admin` | `{success: boolean, message: string, data: null}` | None | `__('message.too_many_requests')` | None | Rate limit exceeded | See 429 example |

401 example:

```json
{
  "message": "Unauthenticated",
  "status": false
}
```

403 example (missing permission):

```json
{
  "message": "User does not have the required permissions.",
  "status": false
}
```

403-scope example (verbatim WITH period):

```json
{
  "message": "Forbidden warehouse operation.",
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

404-constraint example:

```json
{
  "message": "Not Found",
  "status": false
}
```

422 example (live literal, no map):

```json
{
  "status": 422,
  "message": "Cannot delete packing station: active packing tasks exist.",
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
