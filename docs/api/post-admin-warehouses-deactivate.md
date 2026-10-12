# Endpoint Name

Warehouses – Deactivate Warehouse (`POST /api/v1/admin/warehouses/{id}/deactivate`)

# Description

Sets one non-default warehouse inactive under a default block, returning 200.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `POST` |
| Full path | `/api/v1/admin/warehouses/{id}/deactivate` (explicit `{id}` placeholder with `whereNumber('id')`) |
| Path composition | `api/v1` prefix + `admin/warehouses/{id}/deactivate` custom `Route::post(...)->name('api.admin.warehouses.deactivate')` in `packages/marvel/src/Rest/Routes.php` inside the `['api', 'auth:sanctum', 'throttle:admin']` group with route-level `permission:manage-warehouse` |
| Controller action | `App\Http\Controllers\Api\Admin\Wms\WarehouseController@deactivate(int $id)` → `whereKey->firstOrFail` (miss → 404 fixed); `authorizeWarehouseScope(id, 'manage-warehouse')` (verbatim-403 path); then `WarehouseService::deactivate` in a row-locked transaction: CURRENT-DEFAULT check FIRST (`is_default` → `RuntimeException("Cannot deactivate default warehouse #.. (..): promote another active warehouse first")` → 422 live FULL text — code-verified verbatim); else `status := inactive` (already-inactive rows still 200 — no-op class, code-verified by symmetry with activate) → fresh 200. NOTE (code-verified scope): unlike the ORDER-FLOW deactivation guard, there is NO in-flight-fulfillment check here — deactivation is purely a default-block + flag write |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (group; no constructor middleware) |
| Permission | Required: `manage-warehouse` (route pipe + scope check — BOTH the same permission; a viewer fails with status provably untouched — test-verified) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `api.admin.warehouses.deactivate` (explicit named route) |

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller (also the scope key) |
| `id` | path | integer | Required | No | `whereNumber('id')` + `int $id`; must NOT be the current default (else 422) | Id of a non-default warehouse manageable by the caller | None | None | `2` | Identifies the warehouse |

No query parameters, body fields, or `422`-validation contract (the 422 below is the service outcome, not validation).

## Request examples

```json
{
  "method": "POST",
  "url": "/api/v1/admin/warehouses/2/deactivate",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json"
  }
}
```

# Responses

## Success: warehouse deactivated — HTTP 200

Returned via `ApiResponse::apiResponse('Warehouse deactivated successfully.', 200, true, WarehouseResource::make($warehouse))` (literal message; FRESH row). The deactivation itself is the terminal assertion in `test_deactivate_default_returns_422_and_deactivate_other_succeeds` (its non-default half — the suite then proves the default half 422s; both halves use `postJson`).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Literal (untranslated) | Always `"Warehouse deactivated successfully."` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | Fresh `WarehouseResource` (`status: inactive`) | Same 10-key set |

```json
{
  "status": 200,
  "message": "Warehouse deactivated successfully.",
  "success": true,
  "data": {
    "id": 2,
    "code": "WH-B",
    "name": "Beta",
    "address": null,
    "city": null,
    "country": null,
    "status": "inactive",
    "is_default": false,
    "created_at": "2026-01-01T00:00:00+00:00",
    "updated_at": "2026-01-02T00:00:00+00:00"
  }
}
```

# Errors

No error-code field; every error below has Error code `None`. No validation, so the 422s carry live text with NO `errors` map.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | No valid token (code-traced) | See 401 example |
| 403 | Authenticated caller lacks `manage-warehouse` (route pipe AND scope layer) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` (pipe) or verbatim `"Forbidden warehouse operation."` (scope) | None | Homed viewer (verified by `test_viewer_cannot_deactivate_and_status_is_untouched` → 403 + status provably `active`) | See 403 example |
| 404 | No warehouse with `{id}` (lookup miss) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string) | None | Unknown numeric id (code-traced) | See 404 example |
| 404 | Non-numeric `{id}` matches NO route | `{message: string, status: boolean}` | None | `"Not Found"` (route-miss shape) | None | Non-numeric segment | See 404-constraint example |
| 405 | `PUT`/`PATCH`/`GET` on this path (only `POST` registered) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` | None | Wrong method (code-traced) | See 405 example |
| 422 | Target IS the current default (checked under row lock BEFORE the write — no partial state; code-verified) | `{message: string, status: boolean}` (NO `errors` map — thrown `HttpException`) | None | `"Cannot deactivate default warehouse #1 (WH-A): promote another active warehouse first"` (FULL live text with id + code — code-verified verbatim shape, ids/codes illustrative) | None | Default target (verified by `test_deactivate_default_returns_422_and_deactivate_other_succeeds` → 422 on the default half) | See 422 example |
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

422 example (FULL live text, no map):

```json
{
  "status": 422,
  "message": "Cannot deactivate default warehouse #1 (WH-A): promote another active warehouse first",
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
