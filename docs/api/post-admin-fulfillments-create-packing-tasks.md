# Endpoint Name

Fulfillments – Bulk Create Packing Tasks (`POST /api/v1/admin/fulfillments/create-packing-tasks`)

# Description

Creates one packing task per eligible fulfillment (fault-tolerant: eligible rows progress, ineligible/missing rows report per-item reasons). Always HTTP 200 — even when every item fails (check `summary`).

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `POST` (only `POST` registered on this static path) |
| Full path | `/api/v1/admin/fulfillments/create-packing-tasks` (static segment under the `admin/fulfillments` prefix — never collides with `{id}` routes) |
| Path composition | `api/v1` prefix + `admin/fulfillments/create-packing-tasks` from the `Route::prefix('admin/fulfillments')->middleware(['api', 'auth:sanctum', 'throttle:admin'])` group (named `api.admin.fulfillments.create-packing-tasks`) in `packages/marvel/src/Rest/Routes.php` |
| Controller action | `App\Http\Controllers\Api\Admin\Wms\PackingController@bulkCreateTasks(BulkCreatePackingTasksRequest)` → `PackingService::bulkCreatePackingTasks(fulfillment_ids, packing_station_id?, actorId)` → 200 `['successful', 'failed', 'summary']`. NOTE: no `authorizeWarehouseScope` call exists on this bulk path (code-verified absence) — authorization is the route `packing-execute` permission ONLY (unlike single-task commands which scope per-warehouse). Optional station assignment applies only when BOTH `packing_station_id` and the authenticated actor are present (code-verified `stationId !== null && userId !== null` guard; `actorId` is always present when authenticated, so a supplied station always assigns here) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (group) |
| Permission | Required: `packing-execute` via route middleware (only gate — no object-level scope on this path) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json`, `Content-Type: application/json` |
| Supported request formats | JSON body (required) |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `api.admin.fulfillments.create-packing-tasks` (explicit named route; `route:list` verified) |

Order matters: 401 → 403-route → 422-validation → 200. Per-item misses/refusals live inside `data.failed` — they never become top-level 404/409/422-engine statuses (request-level 401/403/405/422/429 above still apply).

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller (also the assignee when a station is supplied) |
| `fulfillment_ids` | body | array | Required | No | `required\|array\|min:1\|max:100` + `fulfillment_ids.*: integer\|min:1` | 1–100 fulfillment ids (duplicates de-duplicated server-side) | None | None | `[11, 12]` | Fulfillments to task |
| `packing_station_id` | body | integer | Optional | Yes | `nullable\|integer\|exists:packing_stations,id` | Existing station id | `null` (unassigned tasks) | When supplied, created tasks are also assigned to this station for the caller | `2` | Target station |

Validation failure returns `422` with an `errors` map.

## Request examples

```json
{
  "method": "POST",
  "url": "/api/v1/admin/fulfillments/create-packing-tasks",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json"
  },
  "body": {
    "fulfillment_ids": [11, 12, 13],
    "packing_station_id": 2
  }
}
```

# Responses

## Success: bulk result — HTTP 200 (fault-tolerant, check `summary`)

Returned via `ApiResponse::apiResponse(__('wms.packing.created'), 200, true, $result)` (`en` → `"Packing task created successfully."`, `resources/lang/en/wms.php:95`). Status/summary verified by `test_bulk_create_packing_tasks` (3 ids → 200, `summary: {total: 3, success_count: 2, failed_count: 1}`; ineligible `pending` row stays `pending`; uses `postJson`); per-item key shapes code-traced against the service return.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` (bulk never 201, even on full success) |
| `message` | string | Always present | Translated `wms.packing.created` | `en` → `"Packing task created successfully."` |
| `success` | boolean | Always present | Outcome flag | Always `true` (per-item failures live in `data.failed`, not here) |
| `data.successful` | array | Always present | Created tasks | `[{fulfillment_id, fulfillment_number, packing_task_id, status, packing_station_id}][]` |
| `data.failed` | array | Always present | Refused/missing rows | `[{fulfillment_id, fulfillment_number?/null, reason}][]` — missing row: `"Fulfillment #id not found"` (no number); refusal: live engine text (e.g. wrong-status `create_status`, open-task `open_exists`, station mismatch) |
| `data.summary` | object | Always present | Counts over de-duplicated ids | `{total, success_count, failed_count}` |

```json
{
  "status": 200,
  "message": "Packing task created successfully.",
  "success": true,
  "data": {
    "successful": [
      {"fulfillment_id": 11, "fulfillment_number": "FF-000011", "packing_task_id": 5, "status": "open", "packing_station_id": 2}
    ],
    "failed": [
      {"fulfillment_id": 13, "fulfillment_number": "FF-000013", "reason": "Cannot create packing task from fulfillment in status: pending"}
    ],
    "summary": {"total": 3, "success_count": 2, "failed_count": 1}
  }
}
```

(Figures illustrative; keys/shape verified against the service return.)

# Errors

No error-code field; every error below has Error code `None`. Per-item misses/refusals are 200-payload — they never become top-level 404/409/422-engine statuses (request-level 422 below still applies).

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated."` (WITH period — Handler:170, code-verified) | None | No valid token (code-traced; same group as verified single-task 401s) | See 401 example |
| 403 | Authenticated caller lacks `packing-execute` (route pipe) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token without the permission (code-traced) | See 403 example |
| 405 | Method other than `POST` (only `POST` registered) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` | None | E.g. `GET` (code-traced) | See 405 example |
| 422 | Body validation fails (missing/empty/over-100 `fulfillment_ids`, non-integer entries, unknown station) | `{message: string, status: boolean, errors: object}` | None | `"The given data was invalid."` | Field → messages | `{}` or `{"fulfillment_ids": []}` (code-traced) | See 422 example |
| 429 | Exceeding `throttle:admin` | `{success: boolean, message: string, data: null}` | None | `__('message.too_many_requests')` | None | Rate limit exceeded (code-traced) | See 429 example |

401 example:

```json
{
  "message": "Unauthenticated.",
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

422 example:

```json
{
  "message": "The given data was invalid.",
  "status": false,
  "errors": {
    "fulfillment_ids": ["The fulfillment ids field is required."]
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
