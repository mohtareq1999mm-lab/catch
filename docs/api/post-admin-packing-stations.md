# Endpoint Name

Packing Stations – Create Station (`POST /api/v1/admin/packing-stations`)

# Description

Creates one packing station with default-warehouse fallback and global-unique code, returning 201.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `POST` |
| Full path | `/api/v1/admin/packing-stations` |
| Path composition | `api/v1` prefix + `admin/packing-stations/` from the `Route::prefix('admin/packing-stations')->middleware(['api', 'auth:sanctum', 'throttle:admin'])` group (named `api.admin.packing-stations.store`) in `packages/marvel/src/Rest/Routes.php` |
| Controller action | `App\Http\Controllers\Api\Admin\Wms\PackingStationController@store(CreatePackingStationRequest $request)` → absent `warehouse_id` resolves the DEFAULT warehouse (missing/inactive default → 422 live `wms.warehouse.*` text — code-verified); explicit `warehouse_id` is existence-checked AFTER resolution (`firstOrFail` → 404 fixed for authorized callers); then `authorizeWarehouseScope(target, 'manage-warehouse')` (verbatim-403 path); `status` defaults `active`; direct `PackingStation::create` (NO domain service — same CRUD-only posture as locations, code-verified) → 201 |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (group; no constructor middleware) |
| Permission | Required: `manage-warehouse` (route pipe + scope check — BOTH the same permission; a `view-fulfillment`-only caller fails both) |
| Required headers | `Authorization: Bearer <sanctum-token>`, `Content-Type: application/json` |
| Recommended headers | `Accept: application/json` |
| Supported request formats | `application/json` |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `api.admin.packing-stations.store` (explicit named route) |

Order matters: 401/403-route → 422 (validation) → 422 (default resolution) → 404 (explicit warehouse lookup) → 403-scope → 201.

## Request inputs

`CreatePackingStationRequest` runs before the body (`authorize: true` — the gates above already ran). `code` is GLOBALLY unique (unscoped `unique:packing_stations,code` — same code in another warehouse is REJECTED, unlike location codes; code-verified asymmetry).

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller (also the scope key) |
| `warehouse_id` | body | integer | Optional | Yes | `nullable\|integer\|exists:warehouses,id` (trashed rows count as existing at validation, then 404 at lookup — same split as locations) | Existing warehouse id manageable by the caller | DEFAULT warehouse via service (missing/inactive default → 422) | None | `1` | Owning warehouse |
| `code` | body | string | Required | No | `required\|string\|max:50\|unique:packing_stations,code` (GLOBAL uniqueness — code-verified) | Globally-unique ≤ 50 chars | None | None | `"ST-A2"` | Station code |
| `name` | body | string | Required | No | `required\|string\|max:100` (PLAIN string, cap 100 — NOT bilingual; code-verified) | ≤ 100 chars | None | None | `"Station A2"` | Display name |
| `status` | body | string | Optional | Yes | `nullable\|string\|in:active,inactive,maintenance` (THREE states — code-verified) | `active`, `inactive`, `maintenance` | `"active"` | None | `"active"` | Lifecycle state |
| `assigned_to` | body | integer | Optional | Yes | `nullable\|integer\|exists:users,id` (any existing user — cross-warehouse membership unchecked; code-verified absence) | Existing user id | `null` | None | `7` | Assigned operator |
| `daily_capacity` | body | integer | Optional | Yes | `nullable\|integer\|min:1` | Integer ≥ 1 | `null` | None | `200` | Daily throughput cap |
| `metadata` | body | object | N/A | N/A | NO RULE — silently dropped by `validated()` even when well-formed (the rule was removed in the working tree; the column is still fillable but unreachable via this endpoint — code-verified) | Ignored | Untouched | None | `{}` | IGNORED field |

No endpoint test found for this action — contracts code-traced.

## Request examples

```json
{
  "method": "POST",
  "url": "/api/v1/admin/packing-stations",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json"
  },
  "body": {
    "warehouse_id": 1,
    "code": "ST-A2",
    "name": "Station A2"
  }
}
```

# Responses

## Success: station created — HTTP 201

Returned via `ApiResponse::apiResponse(__('wms.packing.station_created'), 201, true, PackingStationResource::make($station))` (translated message).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `201` on success |
| `message` | string | Always present | Translated `wms.packing.station_created` | `en` → `"Packing station created successfully."` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | `PackingStationResource` (9 keys, verified) | `id`, `warehouse_id`, `code`, `name`, `status`, `assigned_to`, `daily_capacity` (int/null), `created_at`, `updated_at` |

```json
{
  "status": 201,
  "message": "Packing station created successfully.",
  "success": true,
  "data": {
    "id": 2,
    "warehouse_id": 1,
    "code": "ST-A2",
    "name": "Station A2",
    "status": "active",
    "assigned_to": null,
    "daily_capacity": null,
    "created_at": "2026-01-01T00:00:00+00:00",
    "updated_at": "2026-01-01T00:00:00+00:00"
  }
}
```

# Errors

No error-code field; every error below has Error code `None`.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | No valid token (code-traced) | See 401 example |
| 403 | Authenticated caller lacks `manage-warehouse` (route pipe) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token without the permission (code-traced) | See 403 example |
| 403 | Out-of-scope `manage-warehouse` holder at the SCOPE layer (verbatim) | `{message: string, status: boolean}` | None | `"Forbidden warehouse operation."` (verbatim WITH period) | None | Foreign target warehouse (code-traced shape) | See 403-scope example |
| 404 | Explicit `warehouse_id` unknown (`firstOrFail` AFTER resolution — authorized callers only reach it) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string) | None | Unknown warehouse id (code-traced) | See 404 example |
| 422 | `CreatePackingStationRequest` fails (missing/duplicate `code`, missing/long `name`, bad `status`, unknown `assigned_to`) | `{message: string, status: boolean, errors: object}` | None | Laravel default | Field → messages | Invalid body (code-traced) | See 422-validation example |
| 422 | No usable DEFAULT warehouse (absent `warehouse_id` + missing/inactive default) | `{message: string, status: boolean}` (NO `errors` map — thrown `HttpException`) | None | Live `wms.warehouse.*` text (exact string resolution-dependent) | None | Defaultless setup (code-traced) | See 422-engine example |
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

422-engine example (live text, no map):

```json
{
  "message": "Live warehouse-resolution text (exact string setup-dependent)",
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
