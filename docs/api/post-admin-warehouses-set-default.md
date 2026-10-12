# Endpoint Name

Warehouses – Set Default (`POST /api/v1/admin/warehouses/{id}/set-default`)

# Description

Moves the singleton default flag to an active warehouse transactionally, returning 200.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `POST` |
| Full path | `/api/v1/admin/warehouses/{id}/set-default` (explicit `{id}` placeholder with `whereNumber('id')`) |
| Path composition | `api/v1` prefix + `admin/warehouses/{id}/set-default` custom `Route::post(...)->name('api.admin.warehouses.set-default')` in `packages/marvel/src/Rest/Routes.php` inside the `['api', 'auth:sanctum', 'throttle:admin']` group with route-level `permission:manage-warehouse` |
| Controller action | `App\Http\Controllers\Api\Admin\Wms\WarehouseController@setDefault(int $id)` → `whereKey->firstOrFail` (miss → 404 fixed; trashed rows miss for in-scope actors — test-verified genuine 404 on the trashed twin); `authorizeWarehouseScope(id, 'manage-warehouse')` (verbatim-403 path); then `WarehouseService::setDefault` in a row-locked transaction: target re-read under lock + `assertActive` (INACTIVE → 422 live, test-verified) + locked demote-others/promote-target (never auto-selects a replacement; never two defaults — code-verified) → fresh 200. `RuntimeException` → 422 live message (thrown `HttpException` via `unprocessable`) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (group; no constructor middleware) |
| Permission | Required: `manage-warehouse` (route pipe + scope check — BOTH the same permission; a viewer fails BOTH layers with flags provably untouched — test-verified) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `api.admin.warehouses.set-default` (explicit named route) |

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller (also the scope key) |
| `id` | path | integer | Required | No | `whereNumber('id')` + `int $id`; must be ACTIVE (else 422) | Id of an active warehouse manageable by the caller | None | None | `2` | Identifies the new default |

No query parameters, body fields, or `422`-validation contract (the 422s below are service outcomes, not validation).

## Request examples

```json
{
  "method": "POST",
  "url": "/api/v1/admin/warehouses/2/set-default",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json"
  }
}
```

# Responses

## Success: default moved — HTTP 200

Returned via `ApiResponse::apiResponse('Default warehouse updated successfully.', 200, true, WarehouseResource::make($warehouse))` (literal message; FRESH row). Verified by `test_set_default_moves_flag` (200 + B-true/A-false proofs, `postJson`).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Literal (untranslated) | Always `"Default warehouse updated successfully."` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | Fresh `WarehouseResource` of the NEW default (`is_default: true`) | Same 10-key set |

```json
{
  "status": 200,
  "message": "Default warehouse updated successfully.",
  "success": true,
  "data": {
    "id": 2,
    "code": "WH-B",
    "name": "Beta",
    "address": null,
    "city": null,
    "country": null,
    "status": "active",
    "is_default": true,
    "created_at": "2026-01-01T00:00:00+00:00",
    "updated_at": "2026-01-02T00:00:00+00:00"
  }
}
```

# Errors

No error-code field; every error below has Error code `None`. No validation, so no `errors`-map 422 (service 422s carry live text instead).

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | No valid token (code-traced) | See 401 example |
| 403 | Authenticated caller lacks `manage-warehouse` (route pipe AND scope layer — same permission, flags provably untouched) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` (pipe) or verbatim `"Forbidden warehouse operation."` (scope) | None | Homed viewer (verified by `test_viewer_cannot_change_default_and_flags_are_untouched` → 403 + both flags untouched) | See 403 example |
| 404 | No warehouse with `{id}` (lookup miss, INCLUDING trashed rows for in-scope actors — genuine 404) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string) | None | Unknown/trashed numeric id (trashed twin verified by `test_soft_deleted_warehouse_is_invisible_and_unusable` → 404 on set-default) | See 404 example |
| 404 | Non-numeric `{id}` matches NO route | `{message: string, status: boolean}` | None | `"Not Found"` (route-miss shape) | None | Non-numeric segment | See 404-constraint example |
| 405 | `PUT`/`PATCH`/`GET` on this path (only `POST` registered) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` | None | Wrong method (code-traced) | See 405 example |
| 422 | Target INACTIVE (`assertActive` under lock) | `{message: string, status: boolean}` (NO `errors` map — thrown `HttpException`) | None | Live text (`"Warehouse #2 (WH-B) is not active: ..."` — prefix verified, tail **Unverified in source** beyond the call) | None | Inactive target (verified by `test_set_default_on_inactive_returns_422` → 422) | See 422 example |
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

405 example:

```json
{
  "message": "Method Not Allowed",
  "status": false
}
```

422 example (live text, no map):

```json
{
  "message": "Warehouse #2 (WH-B) is not active: cannot create new fulfillment",
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
