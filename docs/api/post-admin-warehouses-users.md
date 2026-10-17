# Endpoint Name

Warehouses – Assign Users (`POST /api/v1/admin/warehouses/{id}/users`)

# Description

Bulk-assigns staff users to one warehouse (`attach` / `sync` / `detach` modes) inside the caller's warehouse scope, transactionally, without duplicating pivot rows.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `POST` |
| Full path | `/api/v1/admin/warehouses/{id}/users` (explicit `{id}` placeholder with `whereNumber('id')`) |
| Path composition | `api/v1` prefix + `admin/warehouses/{id}/users` from the `Route::prefix('admin/warehouses')->middleware(['api', 'auth:sanctum', 'throttle:admin'])` group (named `api.admin.warehouses.users.assign`) in `packages/marvel/src/Rest/Routes.php` |
| Controller action | `App\Http\Controllers\Api\Admin\Wms\WarehouseController@assignUsers(AssignWarehouseUsersRequest $request, int $id)` → `Warehouse::whereKey->firstOrFail` (miss → 404 fixed); `authorizeWarehouseScope($warehouse->id, 'manage-warehouse')` (asNotFound defaults FALSE → cross-warehouse → 403 `"This action is unauthorized."` — writes never mask as 404); then `WarehouseService::assignUsers($warehouse, user_ids, mode)`: `attach` → `syncWithoutDetaching` (idempotent, no duplicate-key errors), `sync` → `sync()`, `detach` → `detach()` — all inside one `DB::transaction` — → 200 result array (code-verified) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (group; no constructor middleware) |
| Permission | Required: `manage-warehouse` (route pipe + scope check — BOTH the same permission; a `view-warehouse`-only viewer fails both — verified by `test_staff_without_manage_permission_cannot_assign_users` → 403 with the pivot table provably untouched) |
| Required headers | `Authorization: Bearer <sanctum-token>`, `Content-Type: application/json` |
| Recommended headers | `Accept: application/json` |
| Supported request formats | `application/json` |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `api.admin.warehouses.users.assign` (explicit named route; `route:list` verified) |

Order matters: 401 → 403-route → 422 (validation) → 404 (warehouse lookup) → 403-scope → 200.

## Request inputs

`AssignWarehouseUsersRequest` runs before the body (`authorize: true` — the gates above already ran).

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `id` | path | integer | Required | No | `whereNumber('id')` + `int $id` | Warehouse id manageable by the caller | None | None | `1` | Target warehouse |
| `user_ids` | body | integer array | Required | No | `required\|array\|min:1\|max:500` + `user_ids.* → integer\|min:1\|exists:users,id` (code-verified `AssignWarehouseUsersRequest`; unknown ids 422 HERE at validation — verified) | Existing user ids (max 500) | None | None | `[4, 5]` | Users to attach/sync/detach |
| `mode` | body | string | Optional | No | `nullable\|string\|in:attach,sync,detach` (code-verified; other values 422 — verified) | `attach` / `sync` / `detach` | `attach` | None | `"attach"` | Pivot write mode |

## Request examples

Valid attach (test-verified call: `WarehouseUserAssignmentTest::test_manager_can_bulk_assign_users_to_warehouse`, `postJson`):

```json
{
  "method": "POST",
  "url": "/api/v1/admin/warehouses/1/users",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json"
  },
  "body": {
    "user_ids": [4, 5],
    "mode": "attach"
  }
}
```

# Responses

## Success: assignment applied — HTTP 200

Returned via `ApiResponse::apiResponse(__('wms.warehouse.users_assigned'), 200, true, $result)` — translated at the call site (`en` → `"Users assigned to warehouse successfully."`, verified in `resources/lang/en/wms.php` under `wms.warehouse.users_assigned`). Verified by `test_manager_can_bulk_assign_users_to_warehouse` (`200`, `data.count`, DB rows) and `test_manager_can_sync_and_detach_users` (`sync` deletes removed ids, `detach` removes the target row).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `wms.warehouse.users_assigned` | `en` → `"Users assigned to warehouse successfully."` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | Result summary (runtime-probed shape) | `warehouse_id` (int), `user_ids` (int[] — echo of the deduped request ids), `count` (int = count of unique ids sent), `mode` (string echoed) |

```json
{
  "status": 200,
  "message": "Users assigned to warehouse successfully.",
  "success": true,
  "data": {
    "warehouse_id": 1,
    "user_ids": [1],
    "count": 1,
    "mode": "attach"
  }
}
```

# Errors

No error-code field; every error below has Error code `None`.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated."` (WITH period, `message.ERROR.UNAUTHENTICATED`) | None | Guest call (verified by `test_guest_cannot_assign_or_view_warehouse_users` → 401) | See 401 example |
| 403 | Authenticated caller lacks `manage-warehouse` (route pipe) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS`: `"User does not have the required permissions."` | None | `view-warehouse`-only viewer (verified by `test_staff_without_manage_permission_cannot_assign_users` → 403) | See 403 example |
| 403 | `manage-warehouse` holder at a FOREIGN warehouse (scope layer, `asNotFound` defaults FALSE — writes never mask as 404) | `{message: string, status: boolean}` | None | `"This action is unauthorized."` (`Handler` swallows the `Forbidden warehouse operation.` throw text; sibling WMS writes verify the shape) | None | Foreign warehouse (code-traced; NOT directly test-verified on this URI) | See 403-scope example |
| 404 | No warehouse with `{id}` | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string) | None | Unknown numeric id (code-traced `firstOrFail`) | See 404 example |
| 404 | Non-numeric `{id}` matches NO route | `{message: string, status: boolean}` | None | `"Not Found"` (route-miss shape) | None | Non-numeric segment | See 404-constraint example |
| 422 | `AssignWarehouseUsersRequest` fails (empty `user_ids`, non-existent user id, > 500 ids, unknown `mode`) | `{message: string, status: boolean, errors: object}` | None | `"The given data was invalid."` (`message.ERROR.INVALID_DATA` — field texts live in `errors`) | Field → messages | Invalid body (all three cases verified by `test_validation_rejects_empty_or_invalid_users` → 422) | See 422 example |
| 429 | Exceeding `throttle:admin` | `{success: boolean, message: string, data: null}` | None | `__('message.too_many_requests')` | None | Rate limit exceeded | See 429 example |

401 example:

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

403-scope example (foreign warehouse, throw text swallowed by Handler):

```json
{
  "message": "This action is unauthorized.",
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

404-constraint example (non-numeric segment):

```json
{
  "message": "Not Found",
  "status": false
}
```

422 example:

```json
{
  "message": "The given data was invalid.",
  "status": false,
  "errors": {
    "user_ids.0": ["The selected user ids.0 is invalid."]
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
