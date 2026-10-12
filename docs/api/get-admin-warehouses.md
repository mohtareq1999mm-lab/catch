# Endpoint Name

Warehouses – List Warehouses (`GET /api/v1/admin/warehouses`)

# Description

Returns a scope-filtered warehouse page where non-global callers see only home.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/admin/warehouses` |
| Path composition | `api/v1` prefix + `admin/warehouses/` from the `Route::prefix('admin/warehouses')->middleware(['api', 'auth:sanctum', 'throttle:admin'])` group (named `api.admin.warehouses.index`) in `packages/marvel/src/Rest/Routes.php`. No `lang` in group middleware (same as its WMS siblings) |
| Controller action | `App\Http\Controllers\Api\Admin\Wms\WarehouseController@index` (no constructor middleware — group supplies auth; NO in-controller permission check on this action, only the scope branch): `manage-warehouse` holders list ALL rows (`id ASC`); everyone else is confined to `whereKey(home)`; NULL home → `AuthorizationException('Forbidden warehouse operation.')` → 403 (fail-closed listing — CANNOT scope without a home, code-verified). `limit` capped at 100 with NO floor (`min((int) limit, 100)` — zero/negative pass through unvalidated; code-verified); plain paginator collection (NOT the banner envelope — `WarehouseResource::collection($paginator)` serializes with its own pagination affordances, exact keys **Unverified in source** at key level) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (group) |
| Permission | Required: `view-warehouse` via the route pipe (no constructor/inline check on this action — the pipe alone decides; the SCOPE branch is permission-independent beyond it) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute per authenticated user id (or per IP for guests) |
| Route name | `api.admin.warehouses.index` (explicit named route) |

## Request inputs

No `FormRequest` — NOTHING here 422s.

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token enforced by `auth:sanctum` | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller (also the scope key) |
| `Accept` | header | string | Recommended, not strictly required | No | Content negotiation only | `application/json` | None | None | `application/json` | Requests a JSON response |
| `limit` | query | integer | Optional | No | Capped `min((int) limit, 100)`; NO floor validated (code-verified) | Effective ≤ 100 | `15` | None | `15` | Page size |

## Request examples

```json
{
  "method": "GET",
  "url": "/api/v1/admin/warehouses?limit=15",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json"
  }
}
```

# Responses

## Success: scoped page returned — HTTP 200

Returned via `ApiResponse::apiResponse('Warehouses fetched successfully.', 200, true, WarehouseResource::collection($paginator))` (literal message). Verified three ways: scoped viewer sees ONLY home (`WH-A` present, `WH-B` missing — `test_index_scopes_to_home_warehouse_without_global_permission`); global manager sees both (`test_index_returns_all_for_global_permission`); all via `getJson`.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Literal (untranslated) | Always `"Warehouses fetched successfully."` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | array/object | Always present (possibly empty) | Paginated `WarehouseResource` rows (global: all; scoped: home only) | Row: `id`, `code`, `name`, `address`, `city`, `country`, `status` (`active`/`inactive`), `is_default` (bool), `created_at`, `updated_at` |

```json
{
  "status": 200,
  "message": "Warehouses fetched successfully.",
  "success": true,
  "data": [
    {
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
  ]
}
```

# Errors

No error-code field; every error below has Error code `None`. No validation, so no `422`.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing, invalid, revoked, or expired Sanctum token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | Any request without a valid token (verified by `test_unauthenticated_warehouse_routes_return_401` on GET + POST, `getJson`/`postJson`) | See 401 example |
| 403 | Authenticated caller lacks `view-warehouse` (route pipe) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Homed user with no permissions (verified by `test_user_without_permission_is_forbidden` → 403) | See 403 example |
| 403 | Homed viewer WITHOUT global scope is fine — but NULL-home holders fail CLOSED even WITH `view-warehouse` (scope branch, NOT the pipe) | `{message: string, status: boolean}` | None | `"Forbidden warehouse operation."` (verbatim `AuthorizationException` text WITH period) | None | Null-home `view-warehouse` holder (verified by `test_index_with_null_home_fails_closed` AND `test_null_home_viewer_cannot_read_or_list` → 403 on index) | See 403-scope example |
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

429 example:

```json
{
  "success": false,
  "message": "Too many requests. Please try again later.",
  "data": null
}
```
