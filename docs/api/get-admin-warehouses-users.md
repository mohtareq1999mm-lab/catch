# Endpoint Name

Warehouses – List Assigned Users (`GET /api/v1/admin/warehouses/{id}/users`)

# Description

Returns the staff users assigned to one warehouse (pivot list) inside the caller's warehouse scope (cross-warehouse reads 404).

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/admin/warehouses/{id}/users` (explicit `{id}` placeholder with `whereNumber('id')` — non-numeric segments match NO route) |
| Path composition | `api/v1` prefix + `admin/warehouses/{id}/users` from the `Route::prefix('admin/warehouses')->middleware(['api', 'auth:sanctum', 'throttle:admin'])` group (named `api.admin.warehouses.users.index`) in `packages/marvel/src/Rest/Routes.php` |
| Controller action | `App\Http\Controllers\Api\Admin\Wms\WarehouseController@users(int $id)` → `Warehouse::whereKey->firstOrFail` (miss → 404 fixed); `authorizeWarehouseScope($warehouse->id, 'view-warehouse', asNotFound: TRUE)` (cross-warehouse → 404 anti-enumeration — code-verified); `$warehouse->users()->select('users.id','users.name','users.email','users.type')->get()` (BelongsToMany over `warehouse_users` with `withPivot('is_primary')->withTimestamps()`) → 200 user array. Pure read (code-verified) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (group; no constructor middleware) |
| Permission | Required: `view-warehouse` (route pipe + scope check — BOTH the same permission, `asNotFound` masked at the scope layer) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `api.admin.warehouses.users.index` (explicit named route; `route:list` verified) |

Order matters: 401 → 403-route → 404-lookup → 404-scope → 200. No query parameters, no body, no validation, no `422`.

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller (also the scope key) |
| `id` | path | integer | Required | No | `whereNumber('id')` + `int $id` | Warehouse id visible to the caller | None | None | `1` | Identifies the warehouse |

## Request examples

(test-verified call: `WarehouseUserAssignmentTest::test_manager_can_bulk_assign_users_to_warehouse`, `getJson`)

```json
{
  "method": "GET",
  "url": "/api/v1/admin/warehouses/1/users",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json"
  }
}
```

# Responses

## Success: assigned users — HTTP 200

Returned via `ApiResponse::apiResponse(__('wms.warehouse.users_fetched'), 200, true, $users)` — translated at the call site (`en` → `"Warehouse users fetched successfully."`, verified in `resources/lang/en/wms.php` under `wms.warehouse.users_fetched`). Verified by `WarehouseUserAssignmentTest::test_manager_can_bulk_assign_users_to_warehouse` — `200` + item count + shape-pinned keys (assertions added with this flow).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `wms.warehouse.users_fetched` | `en` → `"Warehouse users fetched successfully."` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | array (each item an object) | Always present on success | Assigned staff users (runtime-probed shape) | Each item: `id`, `name`, `email`, `type`, `email_verified` (model `$appends`), `pivot` object `{warehouse_id, user_id, is_primary, created_at, updated_at}` |

```json
{
  "status": 200,
  "message": "Warehouse users fetched successfully.",
  "success": true,
  "data": [
    {
      "id": 1,
      "name": "Bonnie Tromp",
      "email": "howe.colt@example.net",
      "type": "staff",
      "email_verified": false,
      "pivot": {
        "warehouse_id": 1,
        "user_id": 1,
        "is_primary": 1,
        "created_at": "2026-10-17T10:30:38.000000Z",
        "updated_at": "2026-10-17T10:30:38.000000Z"
      }
    }
  ]
}
```

# Errors

No error-code field; every error below has Error code `None`. No validation, so no `422`.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated."` (WITH period, `message.ERROR.UNAUTHENTICATED`) | None | No valid token (verified by `test_guest_cannot_assign_or_view_warehouse_users` on this URI → 401) | See 401 example |
| 403 | Authenticated caller lacks `view-warehouse` (route pipe, Spatie) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS`: `"User does not have the required permissions."` | None | Valid token without the permission (pattern-verified on sibling WMS reads) | See 403 example |
| 404 | No warehouse with `{id}` (lookup miss — validation-free surface) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string) | None | Unknown numeric id (code-traced through `firstOrFail`) | See 404 example |
| 404 | Cross-warehouse warehouse (scope anti-enumeration — reads NEVER 403 at the scope layer) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string) | None | Foreign warehouse id for a `view-warehouse`-only caller (code-traced via `asNotFound: TRUE`; NOT directly test-verified) | See 404-scope example |
| 404 | Non-numeric `{id}` matches NO route | `{message: string, status: boolean}` | None | `"Not Found"` (route-miss shape) | None | Non-numeric segment | See 404-constraint example |
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

404 example (miss and masked scope — identical):

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
