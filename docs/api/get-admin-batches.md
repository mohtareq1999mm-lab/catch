# Endpoint Name

Batches – List Batches (`GET /api/v1/admin/batches`)

# Description

Lists fulfillment batches in reverse-id order inside the caller's warehouse scope with status/assignee/mine filters, returning 200 with a paginated flat array.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/admin/batches` (fixed path — no placeholders) |
| Path composition | `api/v1` prefix + `admin/batches` custom `Route::get(...)->name('api.admin.batches.index')` in `packages/marvel/src/Rest/Routes.php` inside the `['api', 'auth:sanctum', 'throttle:admin']` group with route-level `permission:view-fulfillment` |
| Controller action | `App\Http\Controllers\Api\Admin\Wms\BatchController@index(ListBatchesRequest $request)` → validated filters → scope branch: `manage-warehouse` holders see everything (optional `?warehouse_id` honored as a plain filter with NO scope authorization on it — code-verified); scoped callers are confined to home (`warehouseIdFor`, null home → 403 catalogue) with `?warehouse_id` probed (`≠ home` → 404 anti-enumeration via `asNotFound: TRUE`, code-verified); `status`/`assigned_to`/`mine` narrow; `limit` caps at 100 (default 15); `orderByDesc('id')` paginated → 200 `BatchResource` collection. Pure read (code-verified) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (group; no constructor middleware) |
| Permission | Required: `view-fulfillment` (route pipe + scope check — BOTH the same permission) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute per user id (or IP for guests), per `App\Providers\AppServiceProvider::boot` (code-verified) |
| Route name | `api.admin.batches.index` (explicit named route; `route:list` verified; covered by the generic WMS route sweeps) |

Order matters: 401 → 403-route → 422-validation → 403-homeless / 404-scope-filter → 200.

## Request inputs

`ListBatchesRequest` runs before the action (`authorize: true`).

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller (also the scope key) |
| `warehouse_id` | query | integer | Optional | Yes | `nullable\|integer\|exists:warehouses,id` (unknown ids 422 HERE at validation) | Warehouse id or omitted | `null` | Globals: plain filter; scoped: probed (`≠ home` → 404) | `4` | Narrows listing to one warehouse |
| `status` | query | string | Optional | Yes | `nullable\|in:pending,assigned,picking,completed,cancelled` | One status or omitted | `null` | None | `picking` | Narrows listing to one status |
| `assigned_to` | query | integer | Optional | Yes | `nullable\|integer\|exists:users,id` | User id or omitted | `null` | None | `7` | Narrows listing to one assignee |
| `mine` | query | boolean | Optional | Yes | `nullable\|boolean` | Truthy or omitted | `null`/falsy | Truthy → `assigned_to = actorId` | `1` | Only batches assigned to the caller |
| `limit` | query | integer | Optional | Yes | `nullable\|integer\|min:1\|max:100` | `1`–`100` | `15` | None | `50` | Page size |

## Request examples

```json
{
  "method": "GET",
  "url": "/api/v1/admin/batches?status=picking",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json"
  }
}
```

# Responses

## Success: batches listed — HTTP 200 (flat paginated array)

Returned via `ApiResponse::apiResponse(__('wms.batch.fetched_many'), 200, true, BatchResource::collection($paginated))`. Collection serializes as a FLAT array under `data` (same contract shape as the task indexes — code-verified). Test-authored on the batch show path: no `allowed_actions` key; `tasks` + `progress` present on detail.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `wms.batch.fetched_many` | `en` → `"Batches fetched successfully."` (verified in `resources/lang/en/wms.php:19`) |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | array | Always present on success | Batch rows, newest first | Flat `BatchResource[]` (possibly `[]`) |
| `data[].id` | integer | Per row | Batch id | — |
| `data[].batch_number` | string | Per row | Human number | `BATCH-…` (generated) |
| `data[].warehouse_id` | integer | Per row | Owning warehouse | — |
| `data[].status` | string | Per row | Batch status | `pending`/`assigned`/`picking`/`completed`/`cancelled` |
| `data[].type` | string | Per row | Batch type | HTTP accepts `wave` only (code-verified rule) |
| `data[].assigned_to` | integer/null | Per row | Assignee user id | — |
| `data[].total_items` | integer | Per row | Task count | — |
| `data[].picked_items` | integer | Per row | Picked-task count | Recounted, never trusted from the client |
| `data[].progress` | number | Per row | Completion percentage | `progressPercentage()` |
| `data[].started_at` | string/null | Per row | Start time | ISO-8601 |
| `data[].completed_at` | string/null | Per row | Completion time | Stable timestamp, set once |
| `data[].cancelled_at` | string/null | Per row | Cancel time | ISO-8601 |
| `data[].created_at` | string/null | Per row | Creation time | ISO-8601 |
| `data[].updated_at` | string/null | Per row | Update time | ISO-8601 |

```json
{
  "status": 200,
  "message": "Batches fetched successfully.",
  "success": true,
  "data": [
    {
      "id": 2,
      "batch_number": "BATCH-20261014-ABC123",
      "warehouse_id": 1,
      "status": "picking",
      "type": "wave",
      "assigned_to": 7,
      "total_items": 2,
      "picked_items": 1,
      "progress": 50,
      "started_at": "2026-10-14T10:00:00+00:00",
      "completed_at": null,
      "cancelled_at": null
    }
  ]
}
```

# Errors

No error-code field; every error below has Error code `None`. Validation failures use the VALIDATION envelope (`errors` map); all other errors use the EXCEPTION envelope `{message, status: false}` (code-verified via `Handler`).

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated."` (WITH period, `message.ERROR.UNAUTHENTICATED` — Handler:170, code-verified) | None | No valid token (test-authored 401 on batch reads) | See 401 example |
| 403 | Authenticated caller lacks `view-fulfillment` (route pipe) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token without the permission (code-traced) | See 403 example |
| 403 | Scoped caller with NO home warehouse (fail-closed; controller verbatim discarded — code-verified) | `{message: string, status: boolean}` | None | `"This action is unauthorized."` (`message.ERROR.UNAUTHORIZED_ACTION`) | None | Homeless staffer (code-traced) | See 403-scope example |
| 404 | Scoped caller probing a foreign `?warehouse_id` (anti-enumeration, `asNotFound: TRUE` — code-verified) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (`message.ERROR.RESOURCE_NOT_FOUND`) | None | Cross-warehouse filter probe (pattern test-authored on sibling reads) | See 404-scope example |
| 422 | Filter validation failure (`exists`/`in`/`min`/`max` rules) | `{message: string, status: boolean, errors: object}` (WITH `errors` map) | None | `"The given data was invalid."` (`message.ERROR.INVALID_DATA`) | Per-field arrays | E.g. unknown `warehouse_id`, bad `status`, `limit > 100` (code-traced) | See 422-validation example |
| 429 | Exceeding `throttle:admin` | `{success: boolean, message: string, data: null}` | None | `__('message.too_many_requests')` | None | Rate limit exceeded | See 429 example |

401 example (`message.ERROR.UNAUTHENTICATED`, WITH period):

```json
{
  "message": "Unauthenticated.",
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

403-scope example (catalogue text — the controller verbatim is discarded by the Handler, code-verified):

```json
{
  "message": "This action is unauthorized.",
  "status": false
}
```

404-scope example (fixed string, anti-enumeration):

```json
{
  "message": "Resource Not Found",
  "status": false
}
```

422-validation example (WITH `errors` map):

```json
{
  "message": "The given data was invalid.",
  "status": false,
  "errors": {
    "status": ["The selected status is invalid."]
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
