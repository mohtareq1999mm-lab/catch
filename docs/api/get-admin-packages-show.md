# Endpoint Name

Packages – Show Package (`GET /api/v1/admin/packages/{id}`)

# Description

Returns one package with its items inside the caller's warehouse scope (scope resolves via package → fulfillment; cross-warehouse reads 404), returning 200.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/admin/packages/{id}` (explicit `{id}` placeholder with `whereNumber('id')` — non-numeric segments match NO route) |
| Path composition | `api/v1` prefix + `admin/packages/{id}` custom `Route::get(...)->name('api.admin.packages.show')` in `packages/marvel/src/Rest/Routes.php` inside the `['api', 'auth:sanctum', 'throttle:admin']` group with route-level `permission:view-fulfillment` |
| Controller action | `App\Http\Controllers\Api\Admin\Wms\PackageController@show(int $id)` → `whereKey->firstOrFail` (miss → 404 fixed; nothing precedes the lookup — code-verified); `authorizeWarehouseScope(package→fulfillment warehouse, 'view-fulfillment', asNotFound: TRUE)` (cross-warehouse → 404 anti-enumeration, NEVER 403 — code-verified); `loadMissing('items')` → 200 `PackageDetailResource`. Pure read (code-verified) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (group; no constructor middleware) |
| Permission | Required: `view-fulfillment` (route pipe + scope check — BOTH the same permission) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute per user id (or IP for guests), per `App\Providers\AppServiceProvider::boot` (code-verified) |
| Route name | `api.admin.packages.show` (explicit named route; `route:list` verified; covered by the generic WMS route sweeps) |

Order matters: 401 → 403-route → 404-lookup → 404-scope → 200. No 403-scope exists on this read (anti-enumeration); no validation, no 422.

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller (also the scope key) |
| `id` | path | integer | Required | No | `whereNumber('id')` + `int $id` | Package id manageable by the caller | None | None | `4` | Identifies the package |

No query parameters, body fields, or validation-driven `422`.

## Request examples

```json
{
  "method": "GET",
  "url": "/api/v1/admin/packages/4",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json"
  }
}
```

# Responses

## Success: package detail — HTTP 200 (items included)

Returned via `ApiResponse::apiResponse(__('wms.package.fetched_one'), 200, true, PackageDetailResource::make(...))` — translated at the call site (`en` → `"Package fetched successfully."`, verified in `resources/lang/en/wms.php:138`). `items` is always present here (controller always loads it — code-verified `whenLoaded` satisfied).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `wms.package.fetched_one` | `en` → `"Package fetched successfully."` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | Full detail (list keys + below) | All `PackageResource` keys except `item_count` (detail carries `items` instead — code-verified), plus: |
| `data.dimensions` | array/null | Always present | Pack dimensions | — |
| `data.notes` | string/null | Always present | Operator notes | — |
| `data.items` | array | Always present here | Packed lines | `{id, fulfillment_item_id, order_item_id, quantity (float)}[]` |
| `data.handed_off_at` | string/null | Always present | Handoff time | ISO-8601 |

```json
{
  "status": 200,
  "message": "Package fetched successfully.",
  "success": true,
  "data": {
    "id": 4,
    "fulfillment_id": 11,
    "packing_task_id": null,
    "package_number": "PCK-20261014-AB12CD",
    "barcode": "PKG-20261014-EF34GH56",
    "status": "sealed",
    "weight": 2.5,
    "dimensions": {"length": 30, "width": 20, "height": 10},
    "notes": null,
    "items": [
      {"id": 9, "fulfillment_item_id": 3, "order_item_id": 5, "quantity": 2}
    ],
    "sealed_at": "2026-10-14T10:15:00+00:00",
    "handed_off_at": null,
    "created_at": "2026-10-14T09:00:00+00:00",
    "updated_at": "2026-10-14T10:15:00+00:00"
  }
}
```

# Errors

No error-code field; every error below has Error code `None`. No validation, so no `errors`-map 422 exists on this action.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated."` (WITH period, `message.ERROR.UNAUTHENTICATED` — Handler:170, code-verified) | None | No valid token (code-traced) | See 401 example |
| 403 | Authenticated caller lacks `view-fulfillment` (route pipe) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token without the permission (code-traced) | See 403 example |
| 404 | No package with `{id}` (lookup miss — nothing precedes it) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string) | None | Unknown numeric id (code-traced) | See 404 example |
| 404 | Cross-warehouse package (scope anti-enumeration — reads NEVER 403 at the scope layer, code-verified) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string) | None | Foreign package id (pattern test-authored on sibling reads) | See 404-scope example |
| 404 | Non-numeric `{id}` matches NO route | `{message: string, status: boolean}` | None | Framework passthrough (ILLUSTRATIVE `"Not Found"` — NOT live-verified) | None | Non-numeric segment | See 404-constraint example |
| 429 | Exceeding `throttle:admin` | `{success: boolean, message: string, data: null}` | None | `__('message.too_many_requests')` | None | Rate limit exceeded | See 429 example |

401 example (`message.ERROR.UNAUTHENTICATED`, WITH period):

```json
{
  "message": "Unauthenticated.",
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

404 example:

```json
{
  "message": "Resource Not Found",
  "status": false
}
```

404-scope example (indistinguishable from a miss — by design):

```json
{
  "message": "Resource Not Found",
  "status": false
}
```

404-constraint example (ILLUSTRATIVE — NOT live-verified):

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
