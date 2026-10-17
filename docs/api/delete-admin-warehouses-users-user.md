# Endpoint Name

Warehouses – Remove Assigned User (`DELETE /api/v1/admin/warehouses/{id}/users/{userId}`)

# Description

Single-target unlinking command for the warehouse-staff assignment surface: removes ONE user's assignment from ONE warehouse by deleting the `warehouse_users` pivot row. The user account itself is never deleted — only the link.

Verified behaviors (runtime: `tests/Feature/Wms/WarehouseUserAssignmentTest.php`, suite OK — includes the 5 assignments tests below):

- Removes exactly the one assignment pivot row; other users assigned to the same warehouse are untouched (runtime-verified: `u2` row still present after `u1` removal).
- Operating on a user who is NOT assigned is an **idempotent no-op**: still HTTP 200 with `count: 1` — `count` counts the REQUESTED ids, not the rows actually removed (code-verified `WarehouseService::assignUsers:254-259`).
- Warehouse miss and user miss are distinct 404s (runtime-verified).
- Guests 401; staff without `manage-warehouse` 403 (runtime-verified).

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `DELETE` |
| Full path | `/api/v1/admin/warehouses/{id}/users/{userId}` |
| Path composition | `api/v1` prefix + `admin/warehouses` prefix group + `Route::delete('{id}/users/{userId}', [WarehouseController::class, 'removeUser'])->whereNumber(['id', 'userId'])->middleware('permission:manage-warehouse')->name('api.admin.warehouses.users.remove')` (`packages/marvel/src/Rest/Routes.php:587`, group middleware `['api', 'auth:sanctum', 'throttle:admin']`) — route:list-verified 2026-10-17 (`DELETE api/v1/admin/warehouses/{id}/users/{userId} api.admin.warehouses.users.remove › Api\Admin\Wms\Warehous…`) |
| Controller action | `App\Http\Controllers\Api\Admin\Wms\WarehouseController@removeUser(int $id, int $userId)` (no FormRequest — no body is read) → `Warehouse::whereKey($id)->firstOrFail` (miss → 404) → `authorizeWarehouseScope($warehouse->id, 'manage-warehouse')` (WRITE mode — deny → 403, NOT 404) → `User::whereKey($userId)->firstOrFail` (miss → 404) → `WarehouseService::assignUsers($warehouse, [$userId], 'detach')` single transaction → success envelope `ApiResponse` with `wms.warehouse.users_removed` (code-verified `WarehouseController.php:256-275`) |
| Exception-to-error mapping | `ModelNotFoundException` → Handler 404 catalogue branch (`"Resource Not Found"`); `AuthorizationException` → Handler 403 catalogue branch (`"This action is unauthorized."`) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (group) |
| Permission (coarse) | `manage-warehouse` — route middleware. Missing → 403 `"User does not have the required permissions."` (en, suffix off — runtime-verified 403 with a `view-warehouse`-only staff) |
| Permission (object-level, warehouse scope) | `WarehouseAccess::allows($user, $warehouse->id, 'manage-warehouse')` (WRITE mode): deny unless (a) caller also holds `manage-warehouse` (global cross-warehouse grant), or (b) caller's HOME warehouse equals the target warehouse, or (c) the warehouse is in the caller's assigned warehouses. Fail-closed (nulls deny). Denial → 403 `AuthorizationException` → `"This action is unauthorized."` (code-verified chain; NOT runtime-tested on this endpoint) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en\|ar` (default `en`, from the `api` middleware GROUP — `CheckLangMiddleware`) |
| Supported request formats | No body is read — any `Content-Type`/body is IGNORED by the action (no FormRequest bound) |
| Rate limit | TWO limiters apply: route `throttle:admin` = 400/min keyed `$request->user()?->id ?? ip` with custom 429 envelope, PLUS group `throttle:api` = 60/min keyed same (both code-verified: `AppServiceProvider.php:133`, `RouteServiceProvider.php:60`) |
| Route name | `api.admin.warehouses.users.remove` |

Order matters: 401 → 403-route (`manage-warehouse` middleware) → 404-warehouse → 403-scope → 404-user → 200. Non-numeric `{id}` or `{userId}` matches NO route (`whereNumber(['id','userId'])`) → framework 404.

Bulk twin (same surface, NOT this endpoint): `DELETE /api/v1/admin/warehouses/{id}/users` (`api.admin.warehouses.users.remove-bulk`, `RemoveWarehouseUsersRequest` with `user_ids`, same `manage-warehouse` permission, bulk validation 422 — runtime-verified) — see route `Routes.php:586`.

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller (also the scope key) |
| `lang` | header | string | Optional | No | In `['en', 'ar']` (case-insensitive, trimmed) | `en`, `ar` | `en` | None | `ar` | Renders the message in that locale |
| `id` | path | integer | Required | No | `whereNumber('id')` — non-numeric segment matches no route → 404 | Existing `warehouses.id` | None | None | `7` | Warehouse whose assignment is modified |
| `userId` | path | integer | Required | No | `whereNumber('userId')` — non-numeric segment matches no route → 404 | Existing `users.id` | None | None | `42` | User to unlink from the warehouse |

No body fields, no query parameters, no validation-driven `422` on this endpoint (the bulk twin validates; the single endpoint does not).

## Request examples

```json
{
  "method": "DELETE",
  "url": "/api/v1/admin/warehouses/7/users/42",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json"
  }
}
```

# Responses

## Success — HTTP 200 (single assignment removed / already absent)

Returned via `ApiResponse::apiResponse(__('wms.warehouse.users_removed'), 200, true, $result)` — translated at the call site (`en` → `"Users removed from warehouse successfully."`, `ar` → `"تمت إزالة الموظفين من المستودع بنجاح."`, code-verified). Runtime message pinned: `"Users removed from warehouse successfully."` (test-verified).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `wms.warehouse.users_removed` | `"Users removed from warehouse successfully."` (runtime-verified) |
| `success` | boolean | Always present | Outcome flag | Always `true` |
| `data` | object | Always present | Service result contract | Keys below |
| `data.warehouse_id` | integer | Always present | Warehouse id (int-cast) | e.g. `7` |
| `data.user_ids` | array of integers | Always present | The requested id (de-duplicated, intval-cast) — always single-element here | `[42]` |
| `data.count` | integer | Always present | Count of REQUESTED ids — NOT rows removed. Always `1` here, even when the user was not assigned | Runtime-verified `1` |
| `data.mode` | string | Always present | Service operation mode | Always `"detach"` (runtime-verified) |

### Side effects

- `DB::transaction`: `Warehouse::users()->detach([$userId])` — deletes the `warehouse_users` pivot row `(warehouse_id = {id}, user_id = {userId})` when present (runtime-verified via `assertDatabaseMissing`).
- `Log::info('Warehouse users assigned', [...])` audit entry with `mode: detach` (code-verified).
- The `users` record itself, and any OTHER assignments, remain untouched (runtime-verified: sibling assignment row still present).

### Example (runtime-verified keys)

```json
{
  "status": 200,
  "message": "Users removed from warehouse successfully.",
  "success": true,
  "data": {
    "warehouse_id": 7,
    "user_ids": [42],
    "count": 1,
    "mode": "detach"
  }
}
```

# Errors

No `422` exists on this endpoint (no validation). Scope refusals are `403` (write mode — the warehouse's existence is NOT masked).

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions / tests | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated."` | None | `test_guest_cannot_remove_warehouse_users` → 401 (runtime-verified) | See 401 |
| 403 | Authenticated caller lacks `manage-warehouse` (route middleware) | `{message: string, status: boolean}` | None | `"User does not have the required permissions."` | None | `test_staff_without_manage_permission_cannot_remove_users` → 403 (runtime-verified) | See 403a |
| 403 | Has `manage-warehouse` but warehouse scope denies (foreign warehouse; fail-closed) | `{message: string, status: boolean}` | None | `"This action is unauthorized."` | None | Code-verified chain (`authorizeWarehouseScope` write mode; NOT runtime-tested on this endpoint) | See 403b |
| 404 | No warehouse with `{id}` (`Warehouse::whereKey()->firstOrFail()` → `ModelNotFoundException`) | `{message: string, status: boolean}` | None | `"Resource Not Found"` | None | `test_remove_user_fails_with_404_if_warehouse_or_user_not_found` → 404 (runtime-verified) | See 404 |
| 404 | No user with `{userId}` (`User::whereKey()->firstOrFail()` — runs AFTER the scope gate) | `{message: string, status: boolean}` | None | `"Resource Not Found"` | None | Same test, user case → 404 (runtime-verified) | See 404 |
| 404 | Non-numeric `{id}` or `{userId}` (`whereNumber` constraint — route not matched) | `{message: string, status: boolean}` | None | Framework passthrough (`"Not Found"`) | None | Code-traced; no test pin | See 404c |
| 429 | Exceeding `throttle:admin` (400/min) | `{success: boolean, message: string, data: null}` | None | `"Too many requests. Please try again later."` | None | Custom limiter response (code-verified) | See 429 |
| 429 | Exceeding group `throttle:api` (60/min) | `{message: string, ...}` | None | Framework default ("Too Many Attempts.") | None | Generic limiter — envelope NOT project-customized | — |

401 example:

```json
{
  "message": "Unauthenticated.",
  "status": false
}
```

403a example (missing `manage-warehouse`):

```json
{
  "message": "User does not have the required permissions.",
  "status": false
}
```

403b example (foreign-warehouse scope denial):

```json
{
  "message": "This action is unauthorized.",
  "status": false
}
```

404 example (both the warehouse and the user miss render identical bodies):

```json
{
  "message": "Resource Not Found",
  "status": false
}
```

404c example (non-numeric path segment — ILLUSTRATIVE, framework default):

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

# Notes

- **Idempotent no-op**: removing a user who is NOT currently assigned still returns 200 with `count: 1` — the `count` field counts REQUESTED ids (`count($uniqueIds)` in `WarehouseService::assignUsers:257`), never the number of pivot rows actually deleted (code-verified; `Eloquent detach()` silently ignores absent links). Callers cannot infer prior-assignment state from the response; check the pivot or `GET {id}/users` for truth.
- **No account deletion**: the operation detaches the assignment ONLY — the user record survives (runtime-verified DB assertions).
- **Ordering guarantee**: scope denial (`403b`) fires AFTER the warehouse lookup and BEFORE the user lookup — a foreign warehouse request returns 403 even when `userId` would 404 (code-verified chain).
- Message catalogue keys: `wms.warehouse.users_removed` (`resources/lang/en/wms.php:266`, `resources/lang/ar/wms.php:263` — code-verified); `message.ERROR.*` for the 401/404 renders (`resources/lang/en/message.php`).
- Sibling surface (same `assignUsers` service): `POST {id}/users` (attach/sync — documented separately), `DELETE {id}/users` (bulk remove, `user_ids`, 422-validated), `GET {id}/users` (index). This endpoint is the single-target variant of the bulk remove; same 200 message and `mode: "detach"`.
- Runtime evidence (2026-10-17, `catch_verify`): `tests/Feature/Wms/WarehouseUserAssignmentTest.php` — 5 remove/attach flows including `test_manager_can_remove_single_user_via_delete_endpoint` (200 + `count: 1` + `mode: "detach"` + pivot-row assertions), `test_guest_cannot_remove_warehouse_users` (401), `test_staff_without_manage_permission_cannot_remove_users` (403), `test_remove_user_fails_with_404_if_warehouse_or_user_not_found` (both 404s); route additionally `route:list`-verified the same day.
