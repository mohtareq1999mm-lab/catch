# Endpoint Name

Locations – Delete Location (`DELETE /api/v1/admin/locations/{id}`)

# Description

Hard-deletes one guarded location with placement cleanup after three safety checks, returning 200.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `DELETE` |
| Full path | `/api/v1/admin/locations/{id}` (explicit `{id}` placeholder with `whereNumber('id')` — non-numeric segments match NO route) |
| Path composition | `api/v1` prefix + `admin/locations/{id}` custom `Route::delete(...)->name('api.admin.locations.destroy')` in `packages/marvel/src/Rest/Routes.php` inside the `['api', 'auth:sanctum', 'throttle:admin']` group with route-level `permission:manage-location` |
| Controller action | `App\Http\Controllers\Api\Admin\Wms\LocationController@destroy(int $id)` → `whereKey->firstOrFail` (miss → 404 fixed; validation does not exist, so NOTHING precedes the lookup — code-verified); `authorizeWarehouseScope(warehouse_id, 'manage-location')` with `asNotFound: FALSE` (verbatim-403 path); then THREE ordered guards (children → stock → picking tasks, each a literal 422 with NO `errors` map, evaluated in source order — code-verified); then ONE transaction deleting zero-stock placement records (`productLocations()->delete()` mass query) plus the location row itself (`Location` has NO `SoftDeletes` trait — HARD delete, code-verified from the model) → 200 with NO data key |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (group; no constructor middleware) |
| Permission | Required: `manage-location` (route pipe + scope check — BOTH the same permission) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `api.admin.locations.destroy` (explicit named route) |

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller (also the scope key) |
| `id` | path | integer | Required | No | `whereNumber('id')` + `int $id`; must be guard-clean (no children, no active stock, no pending/claimed picks) | Deletable location id manageable by the caller | None | None | `1` | Identifies the location |

No query parameters, body fields, or validation-driven `422` (all three 422s below are guard outcomes with live literals, not validation). No endpoint test found for this action (all nearby matches cover sibling actions or the unrelated pickup-locations resource) — contracts code-traced.

## Request examples

```json
{
  "method": "DELETE",
  "url": "/api/v1/admin/locations/1",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json"
  }
}
```

# Responses

## Success: location hard-deleted — HTTP 200 (NO data key)

Returned via `ApiResponse::apiResponse('Location deleted successfully.', 200, true)` (literal message). Placement records for the location are mass-deleted in the same transaction (zero-stock by guard construction — the stock check runs BEFORE the transaction; code-verified order).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Literal (untranslated) | Always `"Location deleted successfully."` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |

```json
{
  "status": 200,
  "message": "Location deleted successfully.",
  "success": true
}
```

# Errors

No error-code field; every error below has Error code `None`. No validation, so no `errors`-map 422 exists on this action (all three 422s are guard literals).

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | No valid token (code-traced) | See 401 example |
| 403 | Authenticated caller lacks `manage-location` (route pipe) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token without the permission (code-traced; same pair as its siblings) | See 403 example |
| 403 | Out-of-scope `manage-location` holder at the SCOPE layer (verbatim, WITH period) | `{message: string, status: boolean}` | None | `"Forbidden warehouse operation."` (verbatim) | None | Cross-warehouse delete (code-traced shape) | See 403-scope example |
| 404 | No location with `{id}` (lookup miss — nothing precedes it) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string) | None | Unknown numeric id (code-traced) | See 404 example |
| 404 | Non-numeric `{id}` matches NO route | `{message: string, status: boolean}` | None | `"Not Found"` (route-miss shape) | None | Non-numeric segment | See 404-constraint example |
| 422 | Location HAS child locations (guard 1 of 3, evaluated first) | `{status: integer, message: string, success: boolean}` (NO `errors` map) | None | `"Cannot delete location: it has child locations assigned. Reassign or remove them first."` (live literal WITH period) | None | Parent location (code-traced) | See 422-children example |
| 422 | Location holds active physical inventory (`quantity > 0` OR `allocated_hint > 0` on ANY placement — either trips it; code-verified OR) | `{status: integer, message: string, success: boolean}` (NO `errors` map) | None | `"Cannot delete location: location contains active inventory. Transfer or clear stock first."` (live literal WITH period) | None | Stocked location (code-traced) | See 422-stock example |
| 422 | Active picking tasks (`pending`/`claimed` ONLY — other statuses ignored; code-verified closed set) target the location's placements | `{status: integer, message: string, success: boolean}` (NO `errors` map) | None | `"Cannot delete location: there are active picking tasks associated with it."` (live literal WITH period) | None | Location with live picks (code-traced) | See 422-tasks example |
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

422-children example (live literal, no map):

```json
{
  "status": 422,
  "message": "Cannot delete location: it has child locations assigned. Reassign or remove them first.",
  "success": false
}
```

422-stock example (live literal, no map):

```json
{
  "status": 422,
  "message": "Cannot delete location: location contains active inventory. Transfer or clear stock first.",
  "success": false
}
```

422-tasks example (live literal, no map):

```json
{
  "status": 422,
  "message": "Cannot delete location: there are active picking tasks associated with it.",
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
