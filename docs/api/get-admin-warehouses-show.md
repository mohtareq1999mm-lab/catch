# Endpoint Name

Warehouses – Show Warehouse (`GET /api/v1/admin/warehouses/{id}`)

# Description

Returns one warehouse by numeric id with cross-warehouse reads masked as 404.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/admin/warehouses/{id}` (explicit `{id}` placeholder with `whereNumber('id')` — non-numeric segments match NO route) |
| Path composition | `api/v1` prefix + `admin/warehouses/{id}` from the `Route::prefix('admin/warehouses')->middleware(['api', 'auth:sanctum', 'throttle:admin'])` group (named `api.admin.warehouses.show`) in `packages/marvel/src/Rest/Routes.php` |
| Controller action | `App\Http\Controllers\Api\Admin\Wms\WarehouseController@show(int $id)` → `whereKey->firstOrFail` (miss escapes → 404 FIXED string); then `authorizeWarehouseScope(id, 'view-warehouse', asNotFound: TRUE)` — scoped denial converts to `NotFoundHttpException('Resource Not Found')` → `Handler` renders the SAME fixed text (code-verified anti-enumeration: a cross-warehouse read and a genuine miss are byte-identical on the wire; the test comment calls this "no existence leak"). ORDER (code-verified, with a scope-before-existence proof): the SCOPE check runs AFTER the lookup, but a trashed row misses at lookup for in-scope actors (genuine 404) while out-of-scope actors on the SAME trashed row 403-or-404 earlier — the test proves neither case oracles existence |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (group; no constructor middleware) |
| Permission | Required: `view-warehouse` via the route pipe, PLUS home-scope (`manage-warehouse` bypasses scope — global holders read any warehouse; code-verified via the manager tests) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `api.admin.warehouses.show` (explicit named route) |

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller (also the scope key) |
| `id` | path | integer | Required | No | `whereNumber('id')` + `int $id` | Warehouse id visible to the caller | None | None | `1` | Identifies the warehouse |

No query parameters, body fields, or `422` contract.

## Request examples

Valid request reading home (test-verified call):

```json
{
  "method": "GET",
  "url": "/api/v1/admin/warehouses/1",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json"
  }
}
```

# Responses

## Success: warehouse returned — HTTP 200

Returned via `ApiResponse::apiResponse('Warehouse fetched successfully.', 200, true, WarehouseResource::make($warehouse))` (literal message — singular `Warehouse`, unlike the plural index message). Verified by `test_show_same_warehouse_returns_200` (200 + `code` fragment, `getJson`).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Literal (untranslated) | Always `"Warehouse fetched successfully."` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | `WarehouseResource` (10 keys, verified) | Same key set as create rows |

```json
{
  "status": 200,
  "message": "Warehouse fetched successfully.",
  "success": true,
  "data": {
    "id": 1,
    "code": "WH-A",
    "name": "Alpha",
    "address": null,
    "city": "Cairo",
    "country": "Egypt",
    "status": "active",
    "is_default": true,
    "created_at": "2026-01-01T00:00:00+00:00",
    "updated_at": "2026-01-01T00:00:00+00:00"
  }
}
```

# Errors

No error-code field; every error below has Error code `None`. No validation, so no `422`. NOTE the two byte-identical 404s (anti-enumeration by design).

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | No valid token (code-traced; the index twin pins 401 on this stack) | See 401 example |
| 403 | Authenticated caller lacks `view-warehouse` (route pipe) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token without the permission (same pipe family as the index 403 test) | See 403 example |
| 404 | No visible warehouse with `{id}` — genuine miss, OR cross-warehouse read masked by anti-enumeration, OR null-home read (ALL byte-identical: fixed string, Handler shape) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string) | None | Unknown id (verified by `test_show_missing_returns_404` for `999999`); foreign id (verified by `test_show_cross_warehouse_returns_404_anti_enumeration`); null-home id (verified by `test_null_home_viewer_cannot_read_or_list` → show 404 with the "no existence leak" comment); trashed id for in-scope actors (verified by `test_soft_deleted_warehouse_is_invisible_and_unusable` → genuine 404) | See 404 example |
| 404 | Non-numeric `{id}` matches NO route | `{message: string, status: boolean}` | None | `"Not Found"` (route-miss shape — different text, code-verified) | None | Non-numeric segment | See 404-constraint example |
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

404 example (miss OR masked read — identical):

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
