# Endpoint Name

Locations – Create Location (`POST /api/v1/admin/locations`)

# Description

Creates one location with per-warehouse unique code and same-warehouse parenting, returning 201.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `POST` |
| Full path | `/api/v1/admin/locations` |
| Path composition | `api/v1` prefix + `admin/locations/` from the `Route::prefix('admin/locations')->middleware(['api', 'auth:sanctum', 'throttle:admin'])` group (named `api.admin.locations.store`) in `packages/marvel/src/Rest/Routes.php` |
| Controller action | `App\Http\Controllers\Api\Admin\Wms\LocationController@store(CreateLocationRequest $request)` → validated payload → `authorizeWarehouseScope(warehouse_id, 'manage-location')` with `asNotFound: FALSE` (verbatim-403 path — foreign writes 403 with ZERO side effects, test-proven); then `Warehouse::firstOrFail` (unknown warehouse for an AUTHORIZED caller → 404 fixed — runs AFTER scope, so scoped callers on foreign ids 403 first; code-verified order); optional `parent_id` cross-warehouse check → 422 live `'Parent location belongs to a different warehouse.'` (thrown `HttpException` via `unprocessable`); then direct `Location::create` (NO domain service — CRUD-only by design, source-commented) → 201. Inactive warehouses ACCEPT locations (test-proven allowed — placement scaffolding is not fulfillment-gated) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (group; no constructor middleware) |
| Permission | Required: `manage-location` (route pipe + scope check — BOTH the same permission; `view-location` alone fails both; `manage-warehouse` alone fails the pipe — test-proven split) |
| Required headers | `Authorization: Bearer <sanctum-token>`, `Content-Type: application/json` |
| Recommended headers | `Accept: application/json` |
| Supported request formats | `application/json` |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `api.admin.locations.store` (explicit named route) |

Order matters: 401/403-route → 422 (validation) → 403-scope → 404 (warehouse lookup) → 422 (parent) → 201. Caller-supplied actor ids are IGNORED (server-derived identity only — test-verified on this family).

## Request inputs

`CreateLocationRequest` runs before the body (`authorize: true` — the gates above already ran). `code` uniqueness is PER-WAREHOUSE (scoped rule — same code in another warehouse is a 201, test-proven).

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller (also the scope key) |
| `warehouse_id` | body | integer | Required | No | `required\|integer\|exists:warehouses,id` (existence incl. trashed? plain `exists` — trashed rows COUNT as existing at validation, then `firstOrFail` misses → 404 fixed; code-verified split) | Existing warehouse id manageable by the caller | None | None | `1` | Owning warehouse (immutable afterwards — D-LOC-MOVE) |
| `parent_id` | body | integer | Optional | Yes | `nullable\|integer\|exists:locations,id` + same-warehouse check (cross-warehouse → 422 live; code-verified) | Location id in the SAME warehouse | `null` (top-level) | None | `4` | Parent bin |
| `code` | body | string | Required | No | `required\|string\|max:50\|unique-per-warehouse` (scoped `Rule::unique` on `warehouse_id` — same code elsewhere is FINE) | Unique-within-warehouse ≤ 50 chars | None | None | `"A-01"` | Bin code |
| `name` | body | string | Required | No | `required\|string\|max:255` (PLAIN string — NOT bilingual; code-verified) | ≤ 255 chars | None | None | `"Bin"` | Display name |
| `barcode` | body | string | Optional | Yes | `nullable\|string\|max:255` (NO uniqueness rule — duplicates accepted; code-verified) | Any string or null | `null` | None | `"BC-1"` | Barcode |
| `type` | body | string | Optional | Yes | `nullable\|in:receiving,storage,picking,packing,staging,quarantine,damaged,returns` (5 placeable + 3 non-sellable; anything else 422s — test-verified with `teleport`) | The eight types or null | `null` | None | `"picking"` | Bin type |
| `status` | body | string | Optional | No | `sometimes\|in:active,inactive` | `active`, `inactive` | Model default | None | `"active"` | Lifecycle state |
| `priority` | body | integer | Optional | No | `sometimes\|integer` (NO min/max — negatives accepted; code-verified) | Any integer | Model default | None | `5` | Sort priority |
| `metadata` | body | object | Optional | Yes | `nullable\|array` (unvalidated passthrough) | Any object or null | `null` | None | `{}` | Free metadata map |

## Request examples

Valid request creating a location (test-verified call):

```json
{
  "method": "POST",
  "url": "/api/v1/admin/locations",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json"
  },
  "body": {
    "warehouse_id": 1,
    "code": "A-01",
    "name": "Bin",
    "type": "picking",
    "priority": 5
  }
}
```

# Responses

## Success: location created — HTTP 201

Returned via `ApiResponse::apiResponse('Location created successfully.', 201, true, LocationResource::make($location))` (literal message). Verified by `test_location_store_and_duplicate_code_rules` (201 + `code` fragment, `postJson`) including the cross-warehouse twin (same code, other warehouse → second 201).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `201` on success |
| `message` | string | Always present | Literal (untranslated) | Always `"Location created successfully."` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | `LocationResource` (11 keys, verified) | `id`, `warehouse_id`, `parent_id`, `code`, `barcode`, `name`, `type`, `status`, `priority` (int/null), `created_at`, `updated_at` |

```json
{
  "status": 201,
  "message": "Location created successfully.",
  "success": true,
  "data": {
    "id": 1,
    "warehouse_id": 1,
    "parent_id": null,
    "code": "A-01",
    "barcode": null,
    "name": "Bin",
    "type": "picking",
    "status": "active",
    "priority": 5,
    "created_at": "2026-01-01T00:00:00+00:00",
    "updated_at": "2026-01-01T00:00:00+00:00"
  }
}
```

# Errors

No error-code field; every error below has Error code `None`.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | Empty body by a guest (verified by `test_unauthenticated_warehouse_routes_return_401` → 401 on POST locations) | See 401 example |
| 403 | Authenticated caller lacks `manage-location` (route pipe — `view-location` and `manage-warehouse` alone both fail here) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Homed viewer (verified by `test_viewer_cannot_write` → 403); `manage-warehouse`-only holder (verified by the permission-split test → 403 on POST) | See 403 example |
| 403 | In-scope permission held but FOREIGN `warehouse_id` (scope layer, verbatim — ZERO side effects) | `{message: string, status: boolean}` | None | `"Forbidden warehouse operation."` (verbatim WITH period) | None | Scoped manager writing abroad (verified by `test_location_store_into_foreign_warehouse_returns_403` → 403 + `assertDatabaseMissing`); permissionless null-home writer (verified by `test_null_home_location_writer_is_denied_with_zero_side_effects` → 403 + missing proof) | See 403-scope example |
| 404 | Authorized caller, unknown `warehouse_id` (`firstOrFail` AFTER scope — scoped callers never reach it on foreign ids) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string) | None | Unknown warehouse id (code-traced) | See 404 example |
| 422 | `CreateLocationRequest` fails (missing/bad fields, duplicate code SAME warehouse, unknown `type`) | `{message: string, status: boolean, errors: object}` | None | Laravel default | Field → messages | Invalid body (duplicate verified → 422; `teleport` type verified → 422) | See 422-validation example |
| 422 | `parent_id` in a DIFFERENT warehouse (thrown `HttpException`, NOT validation) | `{message: string, status: boolean}` (NO `errors` map) | None | `"Parent location belongs to a different warehouse."` (live literal WITH period) | None | Cross-warehouse parent (verified → 422) | See 422-parent example |
| 429 | Exceeding `throttle:admin` | `{success: boolean, message: string, data: null}` | None | `__('message.too_many_requests')` | None | Rate limit exceeded | See 429 example |

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

403-scope example (foreign warehouse — verbatim, zero side effects):

```json
{
  "message": "Forbidden warehouse operation.",
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

422-validation example:

```json
{
  "message": "The code field is required.",
  "status": false,
  "errors": {
    "code": ["The code field is required."]
  }
}
```

422-parent example (live literal, no map):

```json
{
  "message": "Parent location belongs to a different warehouse.",
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
