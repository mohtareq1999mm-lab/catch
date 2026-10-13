# Endpoint Name

Picking Tasks – List Tasks (`GET /api/v1/admin/picking-tasks`)

# Description

Returns a home-scoped picking-task page with batch, fulfillment, and ownership filters.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/admin/picking-tasks` |
| Path composition | `api/v1` prefix + `admin/picking-tasks/` from the `Route::prefix('admin/picking-tasks')->middleware(['api', 'auth:sanctum', 'throttle:admin'])` group (named `api.admin.picking-tasks.index`) in `packages/marvel/src/Rest/Routes.php`. No `lang` in group middleware (same as all WMS families) |
| Controller action | `App\Http\Controllers\Api\Admin\Wms\PickingController@index(ListPickingTasksRequest $request)` (NO constructor middleware — group supplies auth; NO in-controller permission check, only scope branches): `id DESC` ordering; `applyWarehouseScope` — `manage-warehouse` holders see ALL tasks (optional `warehouse_id` filter through the item→fulfillment chain); everyone else: NULL home → verbatim 403, else home-confined (no foreign-filter 404 here — the filter is simply overridden by home confinement, unlike fulfillments; code-verified difference); then `batch_id` (scoped lookup → 404 on foreign/missing) and `fulfillment_id` (same) narrow further; `status` equality; `mine` (truthy → `claimed_by` = caller); `limit` validated `1`–`100`; plain paginator collection serialized as a FLAT array under `data` (no paginator envelope inside this contract — test-pinned comment) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (group) |
| Permission | Required: `picking-execute` via the route pipe (a `view-fulfillment`-only caller fails task reads while passing batch reads — the §R permission split is test-locked: `test_task_reads_require_picking_execute_while_batch_reads_ride_on_view`) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute per authenticated user id (or per IP for guests) |
| Route name | `api.admin.picking-tasks.index` (explicit named route) |

## Request inputs

`ListPickingTasksRequest` runs before the body (`authorize: true` — the gates above already ran).

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token enforced by `auth:sanctum` | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller (also the scope key + `mine` identity) |
| `Accept` | header | string | Recommended, not strictly required | No | Content negotiation only | `application/json` | None | None | `application/json` | Requests a JSON response |
| `warehouse_id` | query | integer | Optional | Yes | `nullable\|integer\|exists:warehouses,id` (global: optional filter; scoped: silently overridden by home confinement — code-verified) | Existing warehouse id | None (global: no filter; scoped: home) | None | `1` | Warehouse filter |
| `batch_id` | query | integer | Optional | Yes | `nullable\|integer\|exists:fulfillment_batches,id` + SCOPE-CHECKED lookup (foreign/missing → 404, code-verified) | Existing batch id visible to the caller | None (no filter) | None | `3` | Batch filter |
| `fulfillment_id` | query | integer | Optional | Yes | `nullable\|integer\|exists:fulfillments,id` + SCOPE-CHECKED lookup (foreign/missing → 404) | Existing fulfillment id visible to the caller | None (no filter) | None | `1` | Fulfillment filter |
| `status` | query | string | Optional | Yes | `nullable\|in:pending,assigned,picking,picked,skipped,cancelled` (SIX states — code-verified closed set) | The six states | None (no filter) | None | `assigned` | Status filter (test-verified combined with `fulfillment_id`) |
| `mine` | query | boolean-ish | Optional | Yes | `nullable\|boolean` (truthy → caller-claimed only; test-verified exact-id proof) | Truthy values | None (no filter) | None | `1` | Self-claimed filter |
| `limit` | query | integer | Optional | Yes | `nullable\|integer\|min:1\|max:100` | `1`–`100` | `15` | None | `15` | Page size |

## Request examples

```json
{
  "method": "GET",
  "url": "/api/v1/admin/picking-tasks?fulfillment_id=1&status=assigned",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json"
  }
}
```

# Responses

## Success: scoped page returned — HTTP 200 (FLAT array payload)

Returned via `ApiResponse::apiResponse('Picking tasks fetched successfully.', 200, true, PickingTaskResource::collection($paginator))` (literal message; FLAT array — the test comment pins "no paginator envelope inside this contract"). Verified by `test_task_index_scopes_to_home_and_supports_filters` (200 + `success: true`, NO Warehouse-B leakage with existence control, `mine` exact-id proof, combined-filter fragment proof — all `getJson`).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Literal (untranslated) | Always `"Picking tasks fetched successfully."` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | array | Always present (possibly empty) | FLAT `PickingTaskResource` rows (NOT an envelope — code-verified + test-pinned) | Row: `id`, `batch_id`, `fulfillment_item_id`, `order_id`, `product_location_id`, `quantity_to_pick`, `quantity_picked`, `remaining` (computed float), `status`, `sequence` (int/null), `claimed_by`, `claimed_at`, `claim_expires_at`, `picked_at`, `created_at`, `updated_at` |

```json
{
  "status": 200,
  "message": "Picking tasks fetched successfully.",
  "success": true,
  "data": [
    {
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
      "created_at": "2026-01-01T00:00:00+00:00",
      "updated_at": "2026-01-01T00:00:00+00:00"
    }
  ]
}
```

# Errors

No error-code field; every error below has Error code `None`.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing, invalid, revoked, or expired Sanctum token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | Any request without a valid token (verified by `test_unauthenticated_reads_return_401` on index + show) | See 401 example |
| 403 | Authenticated caller lacks `picking-execute` (route pipe — `view-fulfillment` alone fails task reads while passing batch reads) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Viewer without the permission (verified by the §R split test → 403 on index + show) | See 403 example |
| 403 | NULL-home holder (fail-closed listing) | `{message: string, status: boolean}` | None | `"Forbidden warehouse operation."` (verbatim WITH period) | None | Null-home caller (code-traced; same helper as every WMS index) | See 403-scope example |
| 404 | Scoped `batch_id`/`fulfillment_id` filter targets a FOREIGN or missing row (scope-checked lookups, NOT empty pages) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string) | None | Foreign filter id (code-traced; same `asNotFound` path as show) | See 404 example |
| 422 | `ListPickingTasksRequest` fails (unknown ids, off-list `status`, `limit` outside 1–100) | `{message: string, status: boolean, errors: object}` | None | Laravel default | Field → messages | Invalid query (code-traced) | See 422 example |
| 429 | Exceeding `throttle:admin` (400/min per user id or IP) | `{success: boolean, message: string, data: null}` | None | `__('message.too_many_requests')` | None | Rate limit exceeded for the caller key | See 429 example |

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

403-scope example (null home — verbatim WITH period):

```json
{
  "message": "Forbidden warehouse operation.",
  "status": false
}
```

404 example (foreign filter — NOT an empty page):

```json
{
  "message": "Resource Not Found",
  "status": false
}
```

422 example:

```json
{
  "message": "The selected status is invalid.",
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
