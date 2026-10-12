# Endpoint Name

Locations – Deactivate Location (`POST /api/v1/admin/locations/{id}/deactivate`)

# Description

Sets one location inactive unconditionally with no placement checks and returns 200 fresh.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `POST` |
| Full path | `/api/v1/admin/locations/{id}/deactivate` (explicit `{id}` placeholder with `whereNumber('id')`) |
| Path composition | `api/v1` prefix + `admin/locations/{id}/deactivate` custom `Route::post(...)->name('api.admin.locations.deactivate')` in `packages/marvel/src/Rest/Routes.php` inside the `['api', 'auth:sanctum', 'throttle:admin']` group with route-level `permission:manage-location` |
| Controller action | `App\Http\Controllers\Api\Admin\Wms\LocationController@deactivate(int $id)` → `whereKey->firstOrFail` (miss → 404 fixed); `authorizeWarehouseScope(warehouse_id, 'manage-location')` (verbatim-403 path); then UNCONDITIONAL `$location->update(['status' => inactive])` — no placement/occupancy/default-block checks of any kind (locations have no default concept and deactivation ignores stock placement; code-verified absence of handling — compare the warehouse twin's default block) → fresh 200. Already-inactive rows still 200 (no-op class) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (group; no constructor middleware) |
| Permission | Required: `manage-location` (route pipe + scope check — BOTH the same permission) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `api.admin.locations.deactivate` (explicit named route) |

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller (also the scope key) |
| `id` | path | integer | Required | No | `whereNumber('id')` + `int $id` | Location id manageable by the caller | None | None | `1` | Identifies the location |

No query parameters, body fields, validation-driven `422`, or guards. Verified by `test_location_activate_deactivate_cycle` (deactivate half: 200 + `status: inactive` fragment, `postJson`).

## Request examples

```json
{
  "method": "POST",
  "url": "/api/v1/admin/locations/1/deactivate",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json"
  }
}
```

# Responses

## Success: location deactivated — HTTP 200

Returned via `ApiResponse::apiResponse('Location deactivated successfully.', 200, true, LocationResource::make($location->fresh()))` (literal message; FRESH re-read — code-verified).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success (including already-inactive no-ops) |
| `message` | string | Always present | Literal (untranslated) | Always `"Location deactivated successfully."` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | Fresh `LocationResource` (`status: inactive`) | Same 11-key set |

```json
{
  "status": 200,
  "message": "Location deactivated successfully.",
  "success": true,
  "data": {
    "id": 1,
    "warehouse_id": 1,
    "parent_id": null,
    "code": "C-1",
    "barcode": null,
    "name": "C",
    "type": null,
    "status": "inactive",
    "priority": null,
    "created_at": "2026-01-01T00:00:00+00:00",
    "updated_at": "2026-01-02T00:00:00+00:00"
  }
}
```

# Errors

No error-code field; every error below has Error code `None`. No validation and no guards, so no `422` at all on this action.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | No valid token (code-traced) | See 401 example |
| 403 | Authenticated caller lacks `manage-location` (route pipe) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token without the permission (code-traced) | See 403 example |
| 403 | Out-of-scope `manage-location` holder at the SCOPE layer (verbatim, WITH period) | `{message: string, status: boolean}` | None | `"Forbidden warehouse operation."` (verbatim) | None | Cross-warehouse deactivation (code-traced shape) | See 403-scope example |
| 404 | No location with `{id}` (lookup miss) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string) | None | Unknown numeric id (code-traced) | See 404 example |
| 404 | Non-numeric `{id}` matches NO route | `{message: string, status: boolean}` | None | `"Not Found"` (route-miss shape) | None | Non-numeric segment | See 404-constraint example |
| 405 | `PUT`/`PATCH`/`GET` on this path (only `POST` registered) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` | None | Wrong method (code-traced) | See 405 example |
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

405 example:

```json
{
  "message": "Method Not Allowed",
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
