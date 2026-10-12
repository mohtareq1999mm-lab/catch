# Endpoint Name

Warehouses – Activate Warehouse (`POST /api/v1/admin/warehouses/{id}/activate`)

# Description

Sets one warehouse active idempotently with no guards and returns 200.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `POST` |
| Full path | `/api/v1/admin/warehouses/{id}/activate` (explicit `{id}` placeholder with `whereNumber('id')`) |
| Path composition | `api/v1` prefix + `admin/warehouses/{id}/activate` custom `Route::post(...)->name('api.admin.warehouses.activate')` in `packages/marvel/src/Rest/Routes.php` inside the `['api', 'auth:sanctum', 'throttle:admin']` group with route-level `permission:manage-warehouse` |
| Controller action | `App\Http\Controllers\Api\Admin\Wms\WarehouseController@activate(int $id)` → `whereKey->firstOrFail` (miss → 404 fixed); `authorizeWarehouseScope(id, 'manage-warehouse')` (verbatim-403 path); then `WarehouseService::activate` in a row-locked transaction: UNCONDITIONAL `status := active` — NO try/catch in the controller (any service/DB failure escapes as a raw 500 — code-verified absence of handling, unlike set-default/deactivate/destroy which map `RuntimeException` to 422). Idempotent by construction (activating an active row still 200s — same no-op class as the flow-deactivation twin, code-verified) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (group; no constructor middleware) |
| Permission | Required: `manage-warehouse` (route pipe + scope check — BOTH the same permission) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `api.admin.warehouses.activate` (explicit named route) |

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller (also the scope key) |
| `id` | path | integer | Required | No | `whereNumber('id')` + `int $id` | Warehouse id manageable by the caller | None | None | `2` | Identifies the warehouse |

No query parameters, body fields, validation-driven `422`, or guards of any kind. No endpoint test found for activate specifically (deactivate/set-default twins pin the neighboring contracts) — contracts code-traced.

## Request examples

```json
{
  "method": "POST",
  "url": "/api/v1/admin/warehouses/2/activate",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json"
  }
}
```

# Responses

## Success: warehouse activated — HTTP 200

Returned via `ApiResponse::apiResponse('Warehouse activated successfully.', 200, true, WarehouseResource::make($warehouse))` (literal message; FRESH row; symmetric counterpart message to deactivate — code-verified).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success (including already-active no-ops) |
| `message` | string | Always present | Literal (untranslated) | Always `"Warehouse activated successfully."` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | Fresh `WarehouseResource` (`status: active`) | Same 10-key set |

```json
{
  "status": 200,
  "message": "Warehouse activated successfully.",
  "success": true,
  "data": {
    "id": 2,
    "code": "WH-B",
    "name": "Beta",
    "address": null,
    "city": null,
    "country": null,
    "status": "active",
    "is_default": false,
    "created_at": "2026-01-01T00:00:00+00:00",
    "updated_at": "2026-01-02T00:00:00+00:00"
  }
}
```

# Errors

No error-code field; every error below has Error code `None`. No validation and NO service-guard mapping — the ONLY post-auth failures are 404/500 (code-verified absence of 422 on this action).

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | No valid token (code-traced) | See 401 example |
| 403 | Authenticated caller lacks `manage-warehouse` (route pipe AND scope layer) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` (pipe) or verbatim `"Forbidden warehouse operation."` (scope) | None | Valid token without the permission (code-traced; same pair as its twins) | See 403 example |
| 404 | No warehouse with `{id}` (lookup miss) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string) | None | Unknown numeric id (code-traced) | See 404 example |
| 404 | Non-numeric `{id}` matches NO route | `{message: string, status: boolean}` | None | `"Not Found"` (route-miss shape) | None | Non-numeric segment | See 404-constraint example |
| 405 | `PUT`/`PATCH`/`GET` on this path (only `POST` registered) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` | None | Wrong method (code-traced) | See 405 example |
| 500 | Row-lock/DB failure inside `activate` (NO catch — raw escape, unlike its guarded siblings; code-verified) | `{message: string, status: boolean}` | None | `"Internal Server Error"` (or live text in debug) | None | Persistence failure (code-traced) | See 500 example |
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

500 example (uncaught escape):

```json
{
  "message": "Internal Server Error",
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
