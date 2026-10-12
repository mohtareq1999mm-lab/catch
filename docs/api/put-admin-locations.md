# Endpoint Name

Locations – Update Location (`PUT /api/v1/admin/locations/{id}`)

# Description

Updates one location's non-warehouse fields with moves silently ignored, returning 200 fresh.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `PUT` (only `PUT` is registered — `PATCH` on this path → 405; code-verified) |
| Full path | `/api/v1/admin/locations/{id}` (explicit `{id}` placeholder with `whereNumber('id')`) |
| Path composition | `api/v1` prefix + `admin/locations/{id}` from the `Route::prefix('admin/locations')->middleware(['api', 'auth:sanctum', 'throttle:admin'])` group (named `api.admin.locations.update`) in `packages/marvel/src/Rest/Routes.php` |
| Controller action | `App\Http\Controllers\Api\Admin\Wms\LocationController@update(UpdateLocationRequest $request, int $id)` → `whereKey->firstOrFail` (miss → 404 fixed; validation ran FIRST, so 422 precedes 404 — code-verified order); `authorizeWarehouseScope(warehouse_id, 'manage-location')` with `asNotFound: FALSE` (verbatim-403 path — test-verified cross-warehouse 403); then validated payload → optional cross-warehouse `parent_id` check (422 live, same literal as store) → direct `$location->update($data)` + fresh 200. `warehouse_id`/`status` have NO rules, so they never reach the model (the D-LOC-MOVE `RuntimeException` guard therefore CANNOT fire through this endpoint — validated input can never carry `warehouse_id`; code-verified dead-guard relationship) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (group; no constructor middleware) |
| Permission | Required: `manage-location` (route pipe + scope check — BOTH the same permission; a same-home `view-location`-only caller fails both) |
| Required headers | `Authorization: Bearer <sanctum-token>`, `Content-Type: application/json` when a body is sent |
| Recommended headers | `Accept: application/json` |
| Supported request formats | `application/json` |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `api.admin.locations.update` (explicit named route) |

Order matters: 401/403-route → 422 (validation) → 404 (lookup) → 403-scope → 422 (parent) → 200.

## Request inputs

`warehouse_id`/`status` are ABSENT from the rules (immovable via update — code-verified; use activate/deactivate for status).

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller (also the scope key) |
| `id` | path | integer | Required | No | `whereNumber('id')` + `int $id` | Location id manageable by the caller | None | None | `1` | Identifies the location |
| `parent_id` | body | integer | Optional | Yes | `nullable\|integer\|exists:locations,id` + same-warehouse check (cross-warehouse → 422 live) | Location id in the SAME warehouse | Unchanged | None | `4` | New parent bin |
| `name` | body | string | Optional | No | `sometimes\|required\|string\|max:255` | Non-empty ≤ 255 chars | Unchanged | None | `"M Renamed"` | Display name |
| `barcode` | body | string | Optional | Yes | `nullable\|string\|max:255` (no uniqueness — code-verified) | Any string or null | Unchanged | None | `"BC-2"` | Barcode |
| `type` | body | string | Optional | Yes | `nullable\|in:` (same eight types as create) | Eight types or null | Unchanged | None | `"storage"` | Bin type |
| `priority` | body | integer | Optional | No | `sometimes\|integer` (no min/max) | Any integer | Unchanged | None | `7` | Sort priority |
| `metadata` | body | object | Optional | Yes | `nullable\|array` (unvalidated) | Any object or null | Unchanged | None | `{}` | Free metadata map |
| `warehouse_id` / `status` | body | mixed | N/A | N/A | NO RULES — silently dropped by `validated()` (NOT rejected; code-verified, test-proven with both sent and both ignored) | Ignored | Untouched | None | `2` | IGNORED fields |

## Request examples

Valid request renaming with ignored fields (test-verified call):

```json
{
  "method": "PUT",
  "url": "/api/v1/admin/locations/1",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json"
  },
  "body": {
    "name": "M Renamed",
    "warehouse_id": 2,
    "status": "inactive"
  }
}
```

# Responses

## Success: location updated — HTTP 200 (FRESH row, ignored fields provably untouched)

Returned via `ApiResponse::apiResponse('Location updated successfully.', 200, true, LocationResource::make($location->fresh()))` (literal message; FRESH re-read — code-verified). Verified by `test_location_update_ignores_warehouse_move_and_status` (200 + `name` fragment + `warehouse_id`/`status` provably unchanged, `putJson`).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Literal (untranslated) | Always `"Location updated successfully."` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | Fresh `LocationResource` | Same 11-key set |

```json
{
  "status": 200,
  "message": "Location updated successfully.",
  "success": true,
  "data": {
    "id": 1,
    "warehouse_id": 1,
    "parent_id": null,
    "code": "M-1",
    "barcode": null,
    "name": "M Renamed",
    "type": null,
    "status": "active",
    "priority": null,
    "created_at": "2026-01-01T00:00:00+00:00",
    "updated_at": "2026-01-02T00:00:00+00:00"
  }
}
```

# Errors

No error-code field; every error below has Error code `None`.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | No valid token (code-traced) | See 401 example |
| 403 | Authenticated caller lacks `manage-location` (route pipe) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token without the permission (code-traced) | See 403 example |
| 403 | Out-of-scope `manage-location` holder at the SCOPE layer (verbatim, WITH period) | `{message: string, status: boolean}` | None | `"Forbidden warehouse operation."` (verbatim) | None | Cross-warehouse write (verified by `test_location_update_cross_warehouse_returns_403` → 403) | See 403-scope example |
| 404 | No location with `{id}` (post-validation lookup miss) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string) | None | Unknown numeric id with valid body (code-traced) | See 404 example |
| 404 | Non-numeric `{id}` matches NO route | `{message: string, status: boolean}` | None | `"Not Found"` (route-miss shape) | None | Non-numeric segment | See 404-constraint example |
| 405 | `PATCH` on this path (only `PUT` registered) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` | None | Wrong method (code-traced) | See 405 example |
| 422 | `UpdateLocationRequest` fails (empty `name`, bad `type`, unknown `parent_id`) | `{message: string, status: boolean, errors: object}` | None | Laravel default | Field → messages | Invalid body (code-traced) | See 422-validation example |
| 422 | `parent_id` in a DIFFERENT warehouse than the location (thrown `HttpException`, NOT validation) | `{message: string, status: boolean}` (NO `errors` map) | None | `"Parent location belongs to a different warehouse."` (live literal WITH period) | None | Cross-warehouse parent (code-traced; store twin test-proves the message) | See 422-parent example |
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

403-scope example (verbatim WITH period):

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

422-validation example:

```json
{
  "message": "The name field is required.",
  "status": false,
  "errors": {
    "name": ["The name field is required."]
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
