# Endpoint Name

Locations – List Locations (`GET /api/v1/admin/locations`)

# Description

Returns a scope-filtered location page where a foreign filter 404s instead of emptying.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/admin/locations` |
| Path composition | `api/v1` prefix + `admin/locations/` from the `Route::prefix('admin/locations')->middleware(['api', 'auth:sanctum', 'throttle:admin'])` group (named `api.admin.locations.index`) in `packages/marvel/src/Rest/Routes.php`. No `lang` in group middleware (same as its WMS siblings) |
| Controller action | `App\Http\Controllers\Api\Admin\Wms\LocationController@index` (no constructor middleware beyond `WarehouseAccess` injection — group supplies auth; NO in-controller permission check, only scope branches): `manage-warehouse` holders list ALL rows (optional `warehouse_id` filter, `id ASC`); everyone else: NULL home → `AuthorizationException` → verbatim 403 (fail-closed listing); explicit filter → scope-checked with `asNotFound: TRUE` (FOREIGN filter → 404 `'Resource Not Found'`, NOT an empty page — code-verified anti-enumeration on a LIST filter); no filter → home-only. `limit` capped at 100 with NO floor (`min((int) limit, 100)` — code-verified); plain paginator collection (exact pagination keys **Unverified in source** at key level) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (group) |
| Permission | Required: `view-location` via the route pipe (a `manage-warehouse`-ONLY holder fails the pipe → 403 — the permission split is test-proven: `test_manage_warehouse_does_not_grant_location_or_shipment_access` asserts 403 on GET for `manage-warehouse` without `view-location`) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute per authenticated user id (or per IP for guests) |
| Route name | `api.admin.locations.index` (explicit named route) |

## Request inputs

No `FormRequest` — NOTHING here 422s (a garbage `warehouse_id`/`limit` degrades per the rules below, never rejects).

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token enforced by `auth:sanctum` | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller (also the scope key) |
| `Accept` | header | string | Recommended, not strictly required | No | Content negotiation only | `application/json` | None | None | `application/json` | Requests a JSON response |
| `warehouse_id` | query | integer-ish | Optional | No | `(int) get('warehouse_id', 0) ?: null` — `0`/absent/`"abc"` all mean NO filter (code-verified coercion); global holders filter freely; scoped holders face the 404-or-apply rule above | Any integer | None (global: no filter; scoped: home-only) | None | `3` | Warehouse filter |
| `limit` | query | integer | Optional | No | Capped `min((int) limit, 100)`; NO floor validated (code-verified) | Effective ≤ 100 | `15` | None | `15` | Page size |

## Request examples

```json
{
  "method": "GET",
  "url": "/api/v1/admin/locations?limit=15",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json"
  }
}
```

# Responses

## Success: scoped page returned — HTTP 200

Returned via `ApiResponse::apiResponse('Locations fetched successfully.', 200, true, LocationResource::collection($paginator))` (literal message). Verified by `test_location_index_scopes_to_home_warehouse` (200 + `A-01` present + `B-01` missing, `getJson`).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Literal (untranslated) | Always `"Locations fetched successfully."` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | array/object | Always present (possibly empty) | Paginated `LocationResource` rows (global: all/filtered; scoped: home-only) | Row: `id`, `warehouse_id`, `parent_id`, `code`, `barcode`, `name`, `type`, `status`, `priority` (int/null), `created_at`, `updated_at` |

```json
{
  "status": 200,
  "message": "Locations fetched successfully.",
  "success": true,
  "data": [
    {
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
  ]
}
```

# Errors

No error-code field; every error below has Error code `None`. No validation, so no `422`.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing, invalid, revoked, or expired Sanctum token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | Any request without a valid token (verified by `test_unauthenticated_warehouse_routes_return_401` → 401 on GET locations) | See 401 example |
| 403 | Authenticated caller lacks `view-location` (route pipe — `manage-warehouse` alone is NOT enough) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Homed user with no permissions (verified by `test_user_without_permission_is_forbidden` → 403); `manage-warehouse`-only holder (verified by the permission-split test → 403) | See 403 example |
| 403 | NULL-home holder (cannot scope a listing — fail-closed) | `{message: string, status: boolean}` | None | `"Forbidden warehouse operation."` (verbatim WITH period) | None | Null-home `view-location` holder (same fail-closed family as the warehouse index twin) | See 403-scope example |
| 404 | Scoped caller filters a FOREIGN warehouse (anti-enumeration on a list filter — NOT an empty page) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string via `NotFoundHttpException`) | None | Foreign `warehouse_id` filter without global scope (code-traced; same `asNotFound` path as show) | See 404 example |
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

429 example:

```json
{
  "success": false,
  "message": "Too many requests. Please try again later.",
  "data": null
}
```
