# Endpoint Name

Locations – Show Location (`GET /api/v1/admin/locations/{id}`)

# Description

Returns one location by numeric id with cross-warehouse reads masked as 404.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/admin/locations/{id}` (explicit `{id}` placeholder with `whereNumber('id')` — non-numeric segments match NO route) |
| Path composition | `api/v1` prefix + `admin/locations/{id}` from the `Route::prefix('admin/locations')->middleware(['api', 'auth:sanctum', 'throttle:admin'])` group (named `api.admin.locations.show`) in `packages/marvel/src/Rest/Routes.php` |
| Controller action | `App\Http\Controllers\Api\Admin\Wms\LocationController@show(int $id)` → `whereKey->firstOrFail` (miss escapes → 404 FIXED string); then `authorizeWarehouseScope(warehouse_id, 'view-location', asNotFound: TRUE)` — scoped denial converts to `NotFoundHttpException('Resource Not Found')` → `Handler` renders the SAME fixed text (code-verified anti-enumeration twin of the warehouse show: genuine miss and foreign read are byte-identical) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (group; no constructor middleware) |
| Permission | Required: `view-location` via the route pipe, PLUS home-scope (`manage-warehouse` does NOT bypass here — the scope helper only auto-passes `manage-warehouse` for THAT permission string; a `manage-warehouse`-only holder fails the pipe first in any case) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `api.admin.locations.show` (explicit named route) |

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller (also the scope key) |
| `id` | path | integer | Required | No | `whereNumber('id')` + `int $id` | Location id in the caller's home warehouse (or any, for global holders — but no global `view-location` exists; scope always applies) | None | None | `1` | Identifies the location |

No query parameters, body fields, or `422` contract.

## Request examples

```json
{
  "method": "GET",
  "url": "/api/v1/admin/locations/1",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json"
  }
}
```

# Responses

## Success: location returned — HTTP 200

Returned via `ApiResponse::apiResponse('Location fetched successfully.', 200, true, LocationResource::make($location))` (literal message — singular `Location`, unlike the plural index message).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Literal (untranslated) | Always `"Location fetched successfully."` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | `LocationResource` (11 keys, verified) | Same key set as create rows |

```json
{
  "status": 200,
  "message": "Location fetched successfully.",
  "success": true,
  "data": {
    "id": 1,
    "warehouse_id": 1,
    "parent_id": null,
    "code": "A-01",
    "barcode": null,
    "name": "Bin A",
    "type": null,
    "status": "active",
    "priority": null,
    "created_at": "2026-01-01T00:00:00+00:00",
    "updated_at": "2026-01-01T00:00:00+00:00"
  }
}
```

# Errors

No error-code field; every error below has Error code `None`. No validation, so no `422`. NOTE the byte-identical 404s (anti-enumeration by design).

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | No valid token (code-traced) | See 401 example |
| 403 | Authenticated caller lacks `view-location` (route pipe) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token without the permission (same pipe family as the index 403 test) | See 403 example |
| 404 | No visible location with `{id}` — genuine miss, OR cross-warehouse read masked by anti-enumeration (byte-identical: fixed string, Handler shape) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string) | None | Unknown id; foreign id (verified by `test_location_show_cross_warehouse_returns_404` → 404) | See 404 example |
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
