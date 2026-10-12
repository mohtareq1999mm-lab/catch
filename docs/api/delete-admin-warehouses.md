# Endpoint Name

Warehouses – Delete Warehouse (`DELETE /api/v1/admin/warehouses/{id}`)

# Description

Archives one non-default warehouse by soft delete with an archived message and no data.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `DELETE` |
| Full path | `/api/v1/admin/warehouses/{id}` (explicit `{id}` placeholder with `whereNumber('id')`) |
| Path composition | `api/v1` prefix + `admin/warehouses/{id}` custom `Route::delete(...)->name('api.admin.warehouses.destroy')` in `packages/marvel/src/Rest/Routes.php` inside the `['api', 'auth:sanctum', 'throttle:admin']` group with route-level `permission:manage-warehouse` |
| Controller action | `App\Http\Controllers\Api\Admin\Wms\WarehouseController@destroy(int $id)` → `whereKey->firstOrFail` (miss → 404 fixed; trashed rows miss for in-scope actors — genuine 404 with zero side effects, test-verified); `authorizeWarehouseScope(id, 'manage-warehouse')` (verbatim-403 path); then `WarehouseService::remove` → model `delete()` → the `deleting` hook re-reads AUTHORITATIVE `is_default` from the DB (never the possibly-stale instance — code-verified comment) and throws `RuntimeException("Cannot delete default warehouse #.. (..): promote another active warehouse first")` on defaults → 422 live FULL text (code-verified verbatim shape); non-defaults soft-delete (row + history survive via snapshots — code-verified comment). Success message is `'Warehouse archived successfully.'` — NOT "deleted" (code-verified wording wart) with NO data key |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (group; no constructor middleware) |
| Permission | Required: `manage-warehouse` (route pipe + scope check — BOTH the same permission; a viewer fails with the row provably surviving — test-verified) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `api.admin.warehouses.destroy` (explicit named route) |

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller (also the scope key) |
| `id` | path | integer | Required | No | `whereNumber('id')` + `int $id`; must NOT be the current default (else 422); trashed rows miss | Id of a non-default warehouse manageable by the caller | None | None | `2` | Identifies the warehouse |

No query parameters, body fields, or `422`-validation contract (the 422 below is the model-guard outcome). There is NO restore path on this endpoint family (restoration mechanics **Unverified in source**).

## Request examples

```json
{
  "method": "DELETE",
  "url": "/api/v1/admin/warehouses/2",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json"
  }
}
```

# Responses

## Success: warehouse archived — HTTP 200 (NO data key)

Returned via `ApiResponse::apiResponse('Warehouse archived successfully.', 200, true)` (literal message — "archived", NOT "deleted"; code-verified wording wart shared with NO other delete endpoint).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Literal (untranslated — "archived" wording) | Always `"Warehouse archived successfully."` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |

```json
{
  "status": 200,
  "message": "Warehouse archived successfully.",
  "success": true
}
```

# Errors

No error-code field; every error below has Error code `None`. No validation, so the 422 carries live text with NO `errors` map.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | No valid token (code-traced) | See 401 example |
| 403 | Authenticated caller lacks `manage-warehouse` (route pipe AND scope layer) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` (pipe) or verbatim `"Forbidden warehouse operation."` (scope) | None | Homed viewer (verified by `test_viewer_cannot_delete_and_row_survives` → 403 + row provably present with `deleted_at: null`) | See 403 example |
| 404 | No warehouse with `{id}` (lookup miss, INCLUDING trashed rows for in-scope actors — genuine 404, zero side effects) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string) | None | Unknown/trashed numeric id (trashed twin verified by `test_soft_deleted_warehouse_is_invisible_and_unusable` → genuine 404 with "no leak, no action" comment) | See 404 example |
| 404 | Non-numeric `{id}` matches NO route | `{message: string, status: boolean}` | None | `"Not Found"` (route-miss shape) | None | Non-numeric segment | See 404-constraint example |
| 422 | Target IS the current default (authoritative DB re-read inside the model hook — stale instances cannot bypass; code-verified) | `{message: string, status: boolean}` (NO `errors` map — thrown `HttpException`) | None | `"Cannot delete default warehouse #1 (WH-A): promote another active warehouse first"` (FULL live text with id + code — code-verified verbatim shape, ids/codes illustrative) | None | Default target (code-traced; the deactivate twin proves the same guard live) | See 422 example |
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

422 example (FULL live text, no map):

```json
{
  "status": 422,
  "message": "Cannot delete default warehouse #1 (WH-A): promote another active warehouse first",
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
