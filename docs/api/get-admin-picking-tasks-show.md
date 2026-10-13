# Endpoint Name

Picking Tasks – Show Task (`GET /api/v1/admin/picking-tasks/{id}`)

# Description

Returns one picking task by numeric id with audit detail and masked foreign reads.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/admin/picking-tasks/{id}` (explicit `{id}` placeholder with `whereNumber('id')` — non-numeric segments match NO route) |
| Path composition | `api/v1` prefix + `admin/picking-tasks/{id}` from the `Route::prefix('admin/picking-tasks')->middleware(['api', 'auth:sanctum', 'throttle:admin'])` group (named `api.admin.picking-tasks.show`) in `packages/marvel/src/Rest/Routes.php` |
| Controller action | `App\Http\Controllers\Api\Admin\Wms\PickingController@show(int $id)` → `whereKey->firstOrFail` (miss escapes → 404 fixed); then scope resolved through the AUTHORITATIVE chain task → item → fulfillment (`taskWarehouseId` — null at ANY hop fails closed via `denyUnless`, source-commented) with `asNotFound: TRUE` (foreign reads mask as 404 — test-verified); then `fulfillmentItem.fulfillment` + `productLocation` loaded for the DETAIL resource (staff-facing `scan_log` included precisely because every read here is authenticated + scoped — source-commented) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (group; no constructor middleware) |
| Permission | Required: `picking-execute` via the route pipe (a `view-fulfillment`-only caller fails task reads — test-verified 403 on show) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `api.admin.picking-tasks.show` (explicit named route) |

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `id` | path | integer | Required | No | `whereNumber('id')` + `int $id` | Task id visible to the caller | None | None | `1` | Identifies the picking task |

No query parameters, body fields, or `422` contract.

## Request examples

```json
{
  "method": "GET",
  "url": "/api/v1/admin/picking-tasks/1",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json"
  }
}
```

# Responses

## Success: task detail returned — HTTP 200 (contract-pinned keys AND absence)

Returned via `ApiResponse::apiResponse('Picking task fetched successfully.', 200, true, PickingTaskDetailResource::make($task))` (literal message — singular `task`, unlike the plural index message). Verified by `test_task_show_cross_warehouse_returns_404_with_detail_contract` (200 + FIVE pinned keys + `allowed_actions` absence + `fulfillment.warehouse_id` proof, `getJson`).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Literal (untranslated) | Always `"Picking task fetched successfully."` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | `PickingTaskDetailResource` = base row + 5 detail keys (test-pinned) | Base 16 keys (see index doc) PLUS `fulfillment{id, fulfillment_number, warehouse_id, status}`, `location{id, location_id, warehouse_id}`/null, `op_seq` (int, 0 default), `scan_log` (array — staff audit of actor ids + scans), `notes`. ABSENT by design: `allowed_actions` (P9-9 owns that contract — test-pinned absence) |

```json
{
  "status": 200,
  "message": "Picking task fetched successfully.",
  "success": true,
  "data": {
    "id": 1,
    "batch_id": null,
    "fulfillment_item_id": 11,
    "order_id": 42,
    "product_location_id": 31,
    "quantity_to_pick": 2,
    "quantity_picked": 0,
    "remaining": 2.0,
    "status": "assigned",
    "sequence": 1,
    "claimed_by": 7,
    "claimed_at": "2026-01-01T00:00:00+00:00",
    "claim_expires_at": null,
    "picked_at": null,
    "fulfillment": { "id": 1, "fulfillment_number": "FF-000001", "warehouse_id": 1, "status": "picking" },
    "location": { "id": 31, "location_id": 5, "warehouse_id": 1 },
    "op_seq": 1,
    "scan_log": [],
    "notes": null,
    "created_at": "2026-01-01T00:00:00+00:00",
    "updated_at": "2026-01-01T00:00:00+00:00"
  }
}
```

# Errors

No error-code field; every error below has Error code `None`. No validation, so no `422`. NOTE the byte-identical 404s (anti-enumeration by design).

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | Any request without a valid token (verified by the 401 matrix on show) | See 401 example |
| 403 | Authenticated caller lacks `picking-execute` (route pipe) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Viewer without the permission (verified by the §R split test → 403 on show) | See 403 example |
| 404 | No visible task with `{id}` — genuine miss, OR cross-warehouse read masked by anti-enumeration (byte-identical: fixed string, Handler shape) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string) | None | Unknown id; foreign id (verified by the cross-warehouse twin → 404) | See 404 example |
| 404 | Non-numeric `{id}` matches NO route | `{message: string, status: boolean}` | None | `"Not Found"` (route-miss shape — different text, code-verified) | None | Non-numeric segment | See 404-constraint example |
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

404 example (miss OR masked read — identical):

```json
{
  "message": "Resource Not Found",
  "status": false
}
```

404-constraint example (non-numeric segment):

```json
{
  "message": "Not Found",
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
