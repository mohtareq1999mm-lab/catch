# Endpoint Name

Packing Stations – Update Station (`PUT /api/v1/admin/packing-stations/{id}`)

# Description

Updates one station's non-warehouse fields with warehouse moves impossible, returning 200 fresh.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `PUT` (only `PUT` is registered — `PATCH` on this path → 405; code-verified) |
| Full path | `/api/v1/admin/packing-stations/{id}` (explicit `{id}` placeholder with `whereNumber('id')`) |
| Path composition | `api/v1` prefix + `admin/packing-stations/{id}` from the `Route::prefix('admin/packing-stations')->middleware(['api', 'auth:sanctum', 'throttle:admin'])` group (named `api.admin.packing-stations.update`) in `packages/marvel/src/Rest/Routes.php` |
| Controller action | `App\Http\Controllers\Api\Admin\Wms\PackingStationController@update(UpdatePackingStationRequest $request, int $id)` → `whereKey->firstOrFail` (miss → 404 fixed; validation ran FIRST — code-verified order); `authorizeWarehouseScope(warehouse_id, 'manage-warehouse')` with `asNotFound: FALSE` (verbatim-403 path); then direct `$station->update($request->validated())` + fresh 200. `warehouse_id` has NO rule, so it never reaches the model (moves impossible through this endpoint — same dead-guard relationship as locations; code-verified) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (group; no constructor middleware) |
| Permission | Required: `manage-warehouse` (route pipe + scope check — BOTH the same permission) |
| Required headers | `Authorization: Bearer <sanctum-token>`, `Content-Type: application/json` when a body is sent |
| Recommended headers | `Accept: application/json` |
| Supported request formats | `application/json` |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `api.admin.packing-stations.update` (explicit named route) |

Order matters: 401/403-route → 422 (validation) → 404 (lookup) → 403-scope → 200.

## Request inputs

`warehouse_id` is ABSENT from the rules (immutable via API — sending it is silently dropped by `validated()`, not rejected; code-verified).

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller (also the scope key) |
| `id` | path | integer | Required | No | `whereNumber('id')` + `int $id` | Station id manageable by the caller | None | None | `1` | Identifies the packing station |
| `code` | body | string | Optional | No | `sometimes\|required\|string\|max:50\|unique-ignore-self` (self-code passes — code-verified `Rule::unique()->ignore(route id)`) | Globally-unique ≤ 50 chars | Unchanged | None | `"ST-A1b"` | Station code |
| `name` | body | string | Optional | No | `sometimes\|required\|string\|max:100` | Non-empty ≤ 100 chars | Unchanged | None | `"Station A1b"` | Display name |
| `status` | body | string | Optional | No | `sometimes\|required\|string\|in:active,inactive,maintenance` (THREE states; no guards here — deactivating under live tasks is allowed at this layer, code-verified absence) | `active`, `inactive`, `maintenance` | Unchanged | None | `"maintenance"` | Lifecycle state |
| `assigned_to` | body | integer | Optional | Yes | `nullable\|integer\|exists:users,id` | Existing user id or null | Unchanged | None | `7` | Assigned operator |
| `daily_capacity` | body | integer | Optional | Yes | `nullable\|integer\|min:1` | Integer ≥ 1 or null | Unchanged | None | `200` | Daily throughput cap |
| `metadata` | body | object | Optional | Yes | `nullable\|array` (unvalidated) | Any object or null | Unchanged | None | `{}` | Free metadata map |
| `warehouse_id` | body | mixed | N/A | N/A | NO RULE — silently dropped (NOT rejected; code-verified) | Ignored | Untouched | None | `2` | IGNORED field |

No endpoint test found for this action — contracts code-traced.

## Request examples

```json
{
  "method": "PUT",
  "url": "/api/v1/admin/packing-stations/1",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json"
  },
  "body": {
    "name": "Station A1b"
  }
}
```

# Responses

## Success: station updated — HTTP 200

Returned via `ApiResponse::apiResponse(__('wms.packing.station_updated'), 200, true, PackingStationResource::make($station->fresh()))` (translated message; FRESH re-read — code-verified).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `wms.packing.station_updated` | `en` → `"Packing station updated successfully."` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | Fresh `PackingStationResource` | Same 9-key set |

```json
{
  "status": 200,
  "message": "Packing station updated successfully.",
  "success": true,
  "data": {
    "id": 1,
    "warehouse_id": 1,
    "code": "ST-A1",
    "name": "Station A1b",
    "status": "active",
    "assigned_to": null,
    "daily_capacity": null,
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
| 403 | Authenticated caller lacks `manage-warehouse` (route pipe) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token without the permission (code-traced) | See 403 example |
| 403 | Out-of-scope `manage-warehouse` holder at the SCOPE layer (verbatim, WITH period) | `{message: string, status: boolean}` | None | `"Forbidden warehouse operation."` (verbatim) | None | Cross-warehouse update (code-traced shape) | See 403-scope example |
| 404 | No station with `{id}` (post-validation lookup miss) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string) | None | Unknown numeric id with valid body (code-traced) | See 404 example |
| 404 | Non-numeric `{id}` matches NO route | `{message: string, status: boolean}` | None | `"Not Found"` (route-miss shape) | None | Non-numeric segment | See 404-constraint example |
| 405 | `PATCH` on this path (only `PUT` registered) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` | None | Wrong method (code-traced) | See 405 example |
| 422 | `UpdatePackingStationRequest` fails (duplicate `code`, overlong strings, unknown `assigned_to`) | `{message: string, status: boolean, errors: object}` | None | Laravel default | Field → messages | Invalid body (code-traced) | See 422 example |
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
  "message": "The code has already been taken.",
  "status": false,
  "errors": {
    "code": ["The code has already been taken."]
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
