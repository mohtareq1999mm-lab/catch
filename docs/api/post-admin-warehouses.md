# Endpoint Name

Warehouses – Create Warehouse (`POST /api/v1/admin/warehouses`)

# Description

Creates one warehouse with global-unique code and optional default promotion, returning 201.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `POST` |
| Full path | `/api/v1/admin/warehouses` |
| Path composition | `api/v1` prefix + `admin/warehouses/` from the `Route::prefix('admin/warehouses')->middleware(['api', 'auth:sanctum', 'throttle:admin'])` group (named `api.admin.warehouses.store`) in `packages/marvel/src/Rest/Routes.php` |
| Controller action | `App\Http\Controllers\Api\Admin\Wms\WarehouseController@store(CreateWarehouseRequest $request)` → `authorizeWarehouseScope(null, 'manage-warehouse')` — with a NULL target the `allows()` global bypass decides: `manage-warehouse` holders pass REGARDLESS of home (null-home global managers CAN create — by design, test-verified, source-commented "Documented, not a finding"); anyone else 403s. Then `WarehouseService::create` in a transaction: blank code → 422 live; DUPLICATE code (checked `withTrashed` — trashed codes still collide, code-verified) → 422 live; bad status → 422 live; row created (`name` falls back to `code`, `is_default: false` initially); `is_default` truthy → active-assert + locked default move (no double defaults — verified by the promote-and-unset test); `RuntimeException` → 422 live message (thrown `HttpException` via `unprocessable`, code-verified) → 201 |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (group; no constructor middleware) |
| Permission | Required: `manage-warehouse` (route pipe + scope bypass — BOTH the same permission; a `view-warehouse`-only caller fails BOTH layers) |
| Required headers | `Authorization: Bearer <sanctum-token>`, `Content-Type: application/json` |
| Recommended headers | `Accept: application/json` |
| Supported request formats | `application/json` |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `api.admin.warehouses.store` (explicit named route) |

Order matters: 401/403-route → 403-scope → 422 (validation) → 201/422-service. Caller-supplied actor ids are IGNORED anywhere (server-derived identity only — test-verified `test_caller_controlled_actor_ids_are_ignored`).

## Request inputs

`CreateWarehouseRequest` runs before the body (`authorize: true` — the gates above already ran).

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `code` | body | string | Required | No | `required\|string\|max:50\|unique:warehouses,code` (+ service blank/trashed-inclusive duplicate guards — code-verified second layer) | Unique ≤ 50 chars | None | None | `"WH-C"` | Stable identifier (globally unique, trashed rows count) |
| `name` | body | string | Required | No | `required\|string\|max:255` (PLAIN string — NOT bilingual, unlike order-flow names; code-verified) | ≤ 255 chars | Falls back to `code` in the SERVICE (validation still requires it — code-verified dead-ish fallback) | None | `"Gamma"` | Display name |
| `address` | body | string | Optional | Yes | `nullable\|string` (no max — code-verified) | Any string or null | `null` | None | `"Nile St 1"` | Street address |
| `city` | body | string | Optional | Yes | `nullable\|string\|max:100` | ≤ 100 chars | `null` | None | `"Cairo"` | City |
| `country` | body | string | Optional | Yes | `nullable\|string\|max:100` | ≤ 100 chars | `null` | None | `"Egypt"` | Country |
| `status` | body | string | Optional | No | `sometimes\|in:active,inactive` (service re-checks — code-verified) | `active`, `inactive` | `"active"` (service default) | None | `"active"` | Lifecycle state |
| `is_default` | body | boolean | Optional | No | `sometimes\|boolean` (truthy → active-assert + locked move; on an INACTIVE row → 422, test-verified) | `true`, `false` | `false` | None | `false` | Default flag |
| `metadata` | body | object | Optional | Yes | `nullable\|array` (keys/values unvalidated — code-verified passthrough) | Any object or null | `null` | None | `{}` | Free metadata map |

## Request examples

Valid request creating a warehouse (test-verified call):

```json
{
  "method": "POST",
  "url": "/api/v1/admin/warehouses",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json"
  },
  "body": {
    "code": "WH-C",
    "name": "Gamma",
    "city": "Cairo"
  }
}
```

# Responses

## Success: warehouse created — HTTP 201

Returned via `ApiResponse::apiResponse('Warehouse created successfully.', 201, true, WarehouseResource::make($warehouse))` (literal message; FRESH row). Verified by `test_store_creates_warehouse` (`assertStatus(201)`, `code` fragment, DB `status: active`, `postJson`) and `test_null_home_global_manager_may_create_warehouse_by_design` (201 + DB row for the null-home global case).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `201` on success |
| `message` | string | Always present | Literal (untranslated) | Always `"Warehouse created successfully."` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | `WarehouseResource` (10 keys, verified) | `id`, `code`, `name`, `address`, `city`, `country`, `status`, `is_default` (bool), `created_at`, `updated_at` |

```json
{
  "status": 201,
  "message": "Warehouse created successfully.",
  "success": true,
  "data": {
    "id": 3,
    "code": "WH-C",
    "name": "Gamma",
    "address": null,
    "city": "Cairo",
    "country": null,
    "status": "active",
    "is_default": false,
    "created_at": "2026-01-01T00:00:00+00:00",
    "updated_at": "2026-01-01T00:00:00+00:00"
  }
}
```

# Errors

No error-code field; every error below has Error code `None`. All service 422s render `{status, message, success}` with NO `errors` map (thrown `HttpException`, not validation — code-verified).

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | Empty body by a guest (verified by `test_unauthenticated_warehouse_routes_return_401` → 401 on POST) | See 401 example |
| 403 | Authenticated caller lacks `manage-warehouse` (route pipe AND scope bypass — both the same permission) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` (pipe) or verbatim `"Forbidden warehouse operation."` (scope layer — same text family as the index scope row) | None | Homed viewer (verified by `test_viewer_cannot_write` → 403 with valid payload) | See 403 example |
| 422 | `CreateWarehouseRequest` fails (missing/long/duplicate `code`, missing/long `name`, bad `status`) | `{message: string, status: boolean, errors: object}` | None | Laravel default | Field → messages | Invalid body (code-traced) | See 422-validation example |
| 422 | Blank code post-trim (passes `required`, fails the service) | `{status: integer, message: string, success: boolean}` (NO `errors` map) | None | `"Warehouse code is required"` (live service text) | None | Whitespace-only code (code-traced) | See 422-service example |
| 422 | Duplicate code (checked `withTrashed` — a TRASHED row's code still collides; code-verified) | `{status: integer, message: string, success: boolean}` (NO `errors` map) | None | `"Warehouse code WH-A already exists"` (live text with code) | None | Taken code (verified by `test_store_duplicate_code_returns_422` → 422) | See 422-duplicate example |
| 422 | `is_default: true` on an INACTIVE row (active-asserted before the move) | `{status: integer, message: string, success: boolean}` (NO `errors` map) | None | Live `assertActive` text (`"Warehouse #.. (..) is not active: ..."` — prefix verified, tail **Unverified in source** beyond the call) | None | Default-on-inactive (verified by `test_store_with_default_on_inactive_returns_422` → 422) | See 422-inactive example |
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

422-service example (live text, no map):

```json
{
  "status": 422,
  "message": "Warehouse code is required",
  "success": false
}
```

422-duplicate example (live text WITH code, no map):

```json
{
  "status": 422,
  "message": "Warehouse code WH-A already exists",
  "success": false
}
```

422-inactive example (live text, no map):

```json
{
  "status": 422,
  "message": "Warehouse #5 (WH-E) is not active: cannot create new fulfillment",
  "success": false
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
