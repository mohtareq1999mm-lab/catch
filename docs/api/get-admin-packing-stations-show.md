# Endpoint Name

Packing Stations – Show Station (`GET /api/v1/admin/packing-stations/{id}`)

# Description

Returns one packing station by numeric id with cross-warehouse reads masked as 404.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/admin/packing-stations/{id}` (explicit `{id}` placeholder with `whereNumber('id')` — non-numeric segments match NO route) |
| Path composition | `api/v1` prefix + `admin/packing-stations/{id}` from the `Route::prefix('admin/packing-stations')->middleware(['api', 'auth:sanctum', 'throttle:admin'])` group (named `api.admin.packing-stations.show`) in `packages/marvel/src/Rest/Routes.php` |
| Controller action | `App\Http\Controllers\Api\Admin\Wms\PackingStationController@show(int $id)` → `whereKey->firstOrFail` (miss escapes → 404 FIXED string); then `authorizeWarehouseScope(warehouse_id, 'view-fulfillment', asNotFound: TRUE)` — scoped denial converts to `NotFoundHttpException('Resource Not Found')` → SAME fixed text (code-verified anti-enumeration twin of warehouse/location/fulfillment show) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (group; no constructor middleware) |
| Permission | Required: `view-fulfillment` via the route pipe (NOT a station-specific permission — reads ride on fulfillment viewing; code-verified), PLUS home-scope |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `api.admin.packing-stations.show` (explicit named route) |

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller (also the scope key) |
| `id` | path | integer | Required | No | `whereNumber('id')` + `int $id` | Station id in the caller's home warehouse (or any, for global holders) | None | None | `1` | Identifies the packing station |

No query parameters, body fields, or `422` contract. No show-specific test found — contracts code-traced.

## Request examples

```json
{
  "method": "GET",
  "url": "/api/v1/admin/packing-stations/1",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json"
  }
}
```

# Responses

## Success: station returned — HTTP 200

Returned via `ApiResponse::apiResponse(__('wms.packing.station_fetched'), 200, true, PackingStationResource::make($station))` (translated message).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `wms.packing.station_fetched` | `en` → `"Packing station fetched successfully."` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | `PackingStationResource` (9 keys, verified) | `id`, `warehouse_id`, `code`, `name`, `status`, `assigned_to`, `daily_capacity` (int/null), `created_at`, `updated_at` |

```json
{
  "status": 200,
  "message": "Packing station fetched successfully.",
  "success": true,
  "data": {
    "id": 1,
    "warehouse_id": 1,
    "code": "ST-A1",
    "name": "Station A1",
    "status": "active",
    "assigned_to": null,
    "daily_capacity": null,
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
| 403 | Authenticated caller lacks `view-fulfillment` (route pipe) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token without the permission (code-traced) | See 403 example |
| 404 | No visible station with `{id}` — genuine miss, OR cross-warehouse read masked by anti-enumeration (byte-identical: fixed string, Handler shape) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string) | None | Unknown id; foreign id (code-traced twin of the warehouse/location pattern) | See 404 example |
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
