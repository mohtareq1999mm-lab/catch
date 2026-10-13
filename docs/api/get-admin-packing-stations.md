# Endpoint Name

Packing Stations – List Stations (`GET /api/v1/admin/packing-stations`)

# Description

Returns a home-scoped packing-station page ordered by code with an optional status filter.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/admin/packing-stations` |
| Path composition | `api/v1` prefix + `admin/packing-stations/` from the `Route::prefix('admin/packing-stations')->middleware(['api', 'auth:sanctum', 'throttle:admin'])` group (named `api.admin.packing-stations.index`) in `packages/marvel/src/Rest/Routes.php`. No `lang` in group middleware (same as all WMS families) |
| Controller action | `App\Http\Controllers\Api\Admin\Wms\PackingStationController@index` (no constructor middleware — group supplies auth; NO in-controller permission check, only scope branches): `code ASC` ordering; `manage-warehouse` holders see ALL stations (optional `warehouse_id` filter); everyone else: NULL home → verbatim 403 (fail-closed); foreign filter → scope-checked with `asNotFound: TRUE` → 404 (NOT an empty page — code-verified anti-enumeration); no filter → home-only. `status` equality filter; `limit` capped at 100 with NO floor; plain paginator collection (exact pagination keys **Unverified in source** at key level) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (group) |
| Permission | Required: `view-fulfillment` via the route pipe (a permissionless homed user fails the pipe — same family as the fulfillment index 403 test) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute per authenticated user id (or per IP for guests) |
| Route name | `api.admin.packing-stations.index` (explicit named route) |

## Request inputs

`ListPackingStationsRequest` runs before the body (`authorize: true` — the gates above already ran).

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token enforced by `auth:sanctum` | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller (also the scope key) |
| `Accept` | header | string | Recommended, not strictly required | No | Content negotiation only | `application/json` | None | None | `application/json` | Requests a JSON response |
| `warehouse_id` | query | integer | Optional | Yes | `nullable\|integer\|min:1` (NO `exists` check — unknown ids yield an empty page, not 422/404; code-verified leniency) | Any integer ≥ 1 | None (global: no filter; scoped: home-only) | None | `1` | Warehouse filter (scoped callers: home-only or 404) |
| `status` | query | string | Optional | Yes | `nullable\|string\|in:active,inactive,maintenance` (THREE states — code-verified) | `active`, `inactive`, `maintenance` | None (no filter) | None | `active` | Status filter |
| `limit` | query | integer | Optional | Yes | `nullable\|integer\|min:1\|max:100` | `1`–`100` | `15` | None | `15` | Page size |

## Request examples

```json
{
  "method": "GET",
  "url": "/api/v1/admin/packing-stations?limit=15",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json"
  }
}
```

# Responses

## Success: scoped page returned — HTTP 200

Returned via `ApiResponse::apiResponse(__('wms.packing.stations'), 200, true, PackingStationResource::collection($paginator))` (translated message). Verified by `test_packing_reads_scope_to_home_warehouse` (200 + home-only `['ST-A1']` codes, foreign filter → 404 — both `getJson`).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `wms.packing.stations` | `en` → `"Packing stations fetched successfully."` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | array/object | Always present (possibly empty) | Paginated `PackingStationResource` rows | Row: `id`, `warehouse_id`, `code`, `name`, `status`, `assigned_to`, `daily_capacity` (int/null), `created_at`, `updated_at` |

```json
{
  "status": 200,
  "message": "Packing stations fetched successfully.",
  "success": true,
  "data": [
    {
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
  ]
}
```

# Errors

No error-code field; every error below has Error code `None`.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing, invalid, revoked, or expired Sanctum token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | Any request without a valid token (verified by the packing 401 matrix on index) | See 401 example |
| 403 | Authenticated caller lacks `view-fulfillment` (route pipe) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token without the permission (same pipe family as sibling 403 tests) | See 403 example |
| 403 | NULL-home holder (fail-closed listing) | `{message: string, status: boolean}` | None | `"Forbidden warehouse operation."` (verbatim WITH period) | None | Null-home `view-fulfillment` holder (code-traced; same helper as every WMS index) | See 403-scope example |
| 404 | Scoped caller filters a FOREIGN warehouse (anti-enumeration on a list filter) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string) | None | Foreign `warehouse_id` filter without global scope (verified → 404 on the foreign twin) | See 404 example |
| 422 | `ListPackingStationsRequest` fails (off-list `status`, `limit` outside 1–100, `warehouse_id: 0`) | `{message: string, status: boolean, errors: object}` | None | Laravel default | Field → messages | Invalid query (code-traced) | See 422 example |
| 429 | Exceeding `throttle:admin` (400/min per user id or IP) | `{success: boolean, message: string, data: null}` | None | `__('message.too_many_requests')` | None | Rate limit exceeded for the caller key | See 429 example |

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

403-scope example (null home — verbatim WITH period):

```json
{
  "message": "Forbidden warehouse operation.",
  "status": false
}
```

404 example (foreign filter — NOT an empty page):

```json
{
  "message": "Resource Not Found",
  "status": false
}
```

422 example:

```json
{
  "message": "The selected status is invalid.",
  "status": false,
  "errors": {
    "status": ["The selected status is invalid."]
  }
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
