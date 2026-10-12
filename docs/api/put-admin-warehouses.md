# Endpoint Name

Warehouses – Update Warehouse (`PUT /api/v1/admin/warehouses/{id}`)

# Description

Updates one warehouse's metadata only with protected fields silently ignored, returning 200.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `PUT` (only `PUT` is registered — `PATCH` on this path → 405; code-verified) |
| Full path | `/api/v1/admin/warehouses/{id}` (explicit `{id}` placeholder with `whereNumber('id')`) |
| Path composition | `api/v1` prefix + `admin/warehouses/{id}` from the `Route::prefix('admin/warehouses')->middleware(['api', 'auth:sanctum', 'throttle:admin'])` group (named `api.admin.warehouses.update`) in `packages/marvel/src/Rest/Routes.php` |
| Controller action | `App\Http\Controllers\Api\Admin\Wms\WarehouseController@update(UpdateWarehouseRequest $request, int $id)` → `whereKey->firstOrFail` (miss → 404 fixed; validation ran FIRST, so 422 precedes 404 — code-verified order); `authorizeWarehouseScope(id, 'manage-warehouse')` with `asNotFound: FALSE` (default — scoped denial stays a VERBATIM 403 here, unlike show's masked 404; code-verified asymmetry); then `updateDetails` under a row lock with an explicit five-key whitelist (`name/address/city/country/metadata` via `array_intersect_key` — `code`/`status`/`is_default` are SILENTLY DROPPED even when valid, and empty-allowed means an empty body is a no-op 200; code-verified, test-proven with `code: WH-HACK` + `status: inactive` + `is_default: true` all ignored while `name` applied) → fresh 200. NOTE: `manage-warehouse` is the GLOBAL bypass — a holder may update ANY warehouse regardless of home (test-verified cross-home 200); a same-home viewer is denied the write (test-verified 403) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (group; no constructor middleware) |
| Permission | Required: `manage-warehouse` (route pipe + scope check — BOTH the same permission; `view-warehouse` alone fails both) |
| Required headers | `Authorization: Bearer <sanctum-token>`, `Content-Type: application/json` when a body is sent |
| Recommended headers | `Accept: application/json` |
| Supported request formats | `application/json` |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `api.admin.warehouses.update` (explicit named route) |

Order matters: 401/403-route → 422 (validation) → 404 (lookup) → 403-scope → 200.

## Request inputs

Metadata ONLY — `code` is immutable via API (stable identifier), `status`/`is_default` move through their explicit commands (source-commented; code-verified).

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller (also the scope key) |
| `id` | path | integer | Required | No | `whereNumber('id')` + `int $id` | Warehouse id manageable by the caller | None | None | `2` | Identifies the warehouse |
| `name` | body | string | Optional | No | `sometimes\|required\|string\|max:255` (PLAIN string — no bilingual object; code-verified) | Non-empty ≤ 255 chars | Unchanged | None | `"Beta Renamed"` | Display name |
| `address` | body | string | Optional | Yes | `nullable\|string` (no max) | Any string or null | Unchanged | None | `"Nile St 2"` | Street address |
| `city` | body | string | Optional | Yes | `nullable\|string\|max:100` | ≤ 100 chars | Unchanged | None | `"Giza"` | City |
| `country` | body | string | Optional | Yes | `nullable\|string\|max:100` | ≤ 100 chars | Unchanged | None | `"Egypt"` | Country |
| `metadata` | body | object | Optional | Yes | `nullable\|array` (unvalidated passthrough) | Any object or null | Unchanged | None | `{}` | Free metadata map |
| `code` / `status` / `is_default` | body | mixed | N/A | N/A | NO RULES — silently dropped by the service whitelist even when well-formed (NOT rejected; code-verified) | Ignored | Untouched | None | `"WH-HACK"` | IGNORED fields (use the explicit commands) |

## Request examples

Valid metadata-only update (test-verified call — protected fields present AND ignored):

```json
{
  "method": "PUT",
  "url": "/api/v1/admin/warehouses/2",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json"
  },
  "body": {
    "name": "Beta Renamed",
    "code": "WH-HACK",
    "status": "inactive",
    "is_default": true
  }
}
```

# Responses

## Success: warehouse updated — HTTP 200

Returned via `ApiResponse::apiResponse('Warehouse updated successfully.', 200, true, WarehouseResource::make($warehouse))` (literal message; FRESH row). Verified by `test_update_changes_metadata_only_and_ignores_protected_fields` (200 + `name` fragment + `name` applied + `code`/`status`/`is_default` provably untouched, `putJson`) and `test_update_requires_manage_permission_even_same_home` (global cross-home 200 + applied proof).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Literal (untranslated) | Always `"Warehouse updated successfully."` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | Fresh `WarehouseResource` (protected fields provably unchanged) | Same 10-key set |

```json
{
  "status": 200,
  "message": "Warehouse updated successfully.",
  "success": true,
  "data": {
    "id": 2,
    "code": "WH-B",
    "name": "Beta Renamed",
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

No error-code field; every error below has Error code `None`.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | No valid token (code-traced) | See 401 example |
| 403 | Authenticated caller lacks `manage-warehouse` (route pipe) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Same-home viewer (verified by `test_update_requires_manage_permission_even_same_home` → 403 on the viewer half) | See 403 example |
| 403 | Out-of-scope `manage-warehouse`-less caller at the SCOPE layer (verbatim, WITH period — the `asNotFound: false` path, unlike show's masked 404) | `{message: string, status: boolean}` | None | `"Forbidden warehouse operation."` (verbatim) | None | Cross-scope write without the global permission (code-traced shape) | See 403-scope example |
| 404 | No warehouse with `{id}` (post-validation lookup miss) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string) | None | Unknown numeric id with valid body (code-traced) | See 404 example |
| 404 | Non-numeric `{id}` matches NO route | `{message: string, status: boolean}` | None | `"Not Found"` (route-miss shape) | None | Non-numeric segment | See 404-constraint example |
| 405 | `PATCH` on this path (only `PUT` registered) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` | None | Wrong method (code-traced) | See 405 example |
| 422 | `UpdateWarehouseRequest` fails (empty `name`, overlong strings) | `{message: string, status: boolean, errors: object}` | None | Laravel default | Field → messages | Invalid body (code-traced) | See 422 example |
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

422 example:

```json
{
  "message": "The name field is required.",
  "status": false,
  "errors": {
    "name": ["The name field is required."]
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
