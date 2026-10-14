# Endpoint Name

Packages – Create Package (`POST /api/v1/admin/packages`)

# Description

Creates one open package for a fulfillment (at most one active package per fulfillment; voided rows never block), returning 201 with package detail.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `POST` |
| Full path | `/api/v1/admin/packages` (fixed path — no placeholders) |
| Path composition | `api/v1` prefix + `admin/packages` custom `Route::post(...)->name('api.admin.packages.store')` in `packages/marvel/src/Rest/Routes.php` inside the `['api', 'auth:sanctum', 'throttle:admin']` group with route-level `permission:packing-execute` |
| Controller action | `App\Http\Controllers\Api\Admin\Wms\PackageController@store(CreatePackageRequest $request)` → validated payload → fulfillment lookup (`whereKey->firstOrFail` — miss → 404 fixed; NO `exists` rule, code-verified); `authorizeWarehouseScope(fulfillment.warehouse_id, 'packing-execute', asNotFound: FALSE)`; optional `packing_task_id` → task lookup (miss → 404 fixed) + task-warehouse scope check (`asNotFound: TRUE` → 404, code-verified early scoped read); then `PackingService::createPackage(fulfillment, taskId, weight/dimensions/notes with nulls dropped via `array_filter` — code-verified)` — `WmsConflictException` → 409 (typed, locale-proof), `RuntimeException`/`\Exception` → 422 (code-verified). Service, under lock (code-verified): fulfillment status MUST be `packing`/`picked` (else 422 `create_package_status`); an ACTIVE (non-voided) package already present → 409 `active_package` (voided rows never block — code-verified); linked task under ANOTHER fulfillment → 422 `task_fulfillment_mismatch` (service re-checks under lock — controller-commented); else `open` package + generated `package_number` (`PCK-…`) with optional weight/dimensions/notes. NOTE the status gate: a `ready_to_ship`/`shipped`/`cancelled` fulfillment refuses with 422 (code-verified — only `packing`/`picked` pass) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (group; no constructor middleware) |
| Permission | Required: `packing-execute` via the route pipe + scope check |
| Required headers | `Authorization: Bearer <sanctum-token>`, `Content-Type: application/json` |
| Recommended headers | `Accept: application/json` |
| Supported request formats | `application/json` |
| Rate limit | `throttle:admin`: 400 requests/minute per user id (or IP for guests), per `App\Providers\AppServiceProvider::boot` (code-verified) |
| Route name | `api.admin.packages.store` (explicit named route; `route:list` verified; covered by the generic WMS route sweeps) |

Order matters: 401 → 403-route → 422-validation → 404-fulfillment → 403-scope → 404-task/404-task-scope → 422-status → 409-active → 422-mismatch → 201.

## Request inputs

`CreatePackageRequest` runs before the action (`authorize: true`). NOTE (code-verified): NO `exists` rules — unknown `fulfillment_id`/`packing_task_id` do NOT 422 at validation; they 404 at the in-action lookups.

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `fulfillment_id` | body | integer | Required | No | `required\|integer\|min:1` (unknown ids 404 at lookup, code-verified) | `packing`/`picked` fulfillment id manageable by the caller | None | None | `11` | The fulfillment to package (test-authored 201) |
| `packing_task_id` | body | integer | Optional | Yes | `nullable\|integer\|min:1` (unknown ids 404 at lookup; other-fulfillment task → 422) | Task id under the SAME fulfillment or omitted | `null` | Non-null → task lookup + scope + mismatch check | `3` | Link to the packing task (optional) |
| `weight` | body | number | Optional | Yes | `nullable\|numeric\|gt:0` | `> 0` or omitted | `null` (dropped when null) | None | `2.5` | Initial weight |
| `dimensions` | body | object | Optional | Yes | `nullable\|array\|min:1`, items `numeric` | Non-empty map or omitted | `null` (dropped when null) | None | `{"length": 30}` | Initial dimensions |
| `notes` | body | string | Optional | Yes | `nullable\|string\|max:2000` | Any string or null | `null` (dropped when null) | None | `"fragile"` | Package note |

## Request examples

```json
{
  "method": "POST",
  "url": "/api/v1/admin/packages",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json"
  },
  "body": {
    "fulfillment_id": 11
  }
}
```

# Responses

## Success: package created — HTTP 201 (DETAIL resource, open state)

Returned via `ApiResponse::apiResponse(__('wms.package.created'), 201, true, PackageDetailResource…)` — translated at the call site (`en` → `"Package created successfully."`, verified in `resources/lang/en/wms.php:139`). Test-authored: 201 + id; voided predecessor frees a replacement (new id, 201).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `201` on creation |
| `message` | string | Always present | Translated `wms.package.created` | `en` → `"Package created successfully."` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | Fresh `PackageDetailResource` (`status` = `open`, empty `items`) | Full detail keys (see show doc) |

```json
{
  "status": 201,
  "message": "Package created successfully.",
  "success": true,
  "data": {
    "id": 4,
    "fulfillment_id": 11,
    "packing_task_id": null,
    "package_number": "PCK-20261014-AB12CD",
    "barcode": null,
    "status": "open",
    "weight": null,
    "dimensions": null,
    "notes": null,
    "items": [],
    "sealed_at": null,
    "handed_off_at": null
  }
}
```

# Errors

No error-code field; every error below has Error code `None`. Validation failures use the VALIDATION envelope (`errors` map); contention uses 409 and refusals use the EXCEPTION envelope `{message, status: false}` (code-verified).

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated."` (WITH period, code-verified) | None | No valid token (test-authored 401 on this URI) | See 401 example |
| 403 | Authenticated caller lacks `packing-execute` (route pipe) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token without the permission (code-traced) | See 403 example |
| 403 | Out-of-scope `packing-execute` holder at the SCOPE layer (controller verbatim discarded — code-verified) | `{message: string, status: boolean}` | None | `"This action is unauthorized."` (`message.ERROR.UNAUTHORIZED_ACTION`) | None | Cross-warehouse fulfillment (code-traced) | See 403-scope example |
| 404 | Unknown `fulfillment_id` (lookup miss — no `exists` rule, code-verified) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string) | None | Bad fulfillment id (code-traced) | See 404 example |
| 404 | Unknown `packing_task_id` OR task under another warehouse (lookup miss / `asNotFound: TRUE` scope — code-verified) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string) | None | Bad or foreign task id (code-traced) | See 404-task example |
| 409 | Fulfillment ALREADY has an active (non-voided) package — exactly one row provably retained (test-authored) | `{message: string, status: boolean}` (NO `errors` map) | None | Translated `wms.packing.active_package`: `en` → `"Fulfillment #11 already has an active package (one package per fulfillment)"` (ids illustrative; verified in `resources/lang/en/wms.php:122`) | None | Second package while one is open (test-authored 409 + count proof) | See 409 example |
| 422 | Body validation failure | `{message: string, status: boolean, errors: object}` (WITH `errors` map) | None | `"The given data was invalid."` (`message.ERROR.INVALID_DATA`) | Per-field arrays | Missing `fulfillment_id`, non-positive weight (code-traced) | See 422-validation example |
| 422 | Fulfillment in the wrong status (only `packing`/`picked` may accrue packages) | `{message: string, status: boolean}` (NO `errors` map) | None | Translated `wms.packing.create_package_status`: `en` → `"Cannot create package for fulfillment in status: pending"` (status illustrative; verified in `resources/lang/en/wms.php:120`) | None | E.g. `pending` fulfillment (code-traced) | See 422-status example |
| 422 | Linked task belongs to ANOTHER fulfillment | `{message: string, status: boolean}` (NO `errors` map) | None | Translated `wms.packing.task_fulfillment_mismatch`: `en` → `"Packing task #3 belongs to a different fulfillment than #11"` (ids illustrative; verified in `resources/lang/en/wms.php:123`) | None | Mismatched task link (code-traced) | See 422-mismatch example |
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

403-scope example (catalogue text, code-verified):

```json
{
  "message": "This action is unauthorized.",
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

404-task example:

```json
{
  "message": "Resource Not Found",
  "status": false
}
```

409 example (translated, no map — exactly one row retained):

```json
{
  "message": "Fulfillment #11 already has an active package (one package per fulfillment)",
  "status": false
}
```

422-validation example (WITH `errors` map):

```json
{
  "message": "The given data was invalid.",
  "status": false,
  "errors": {
    "fulfillment_id": ["The fulfillment id field is required."]
  }
}
```

422-status example (translated, no map):

```json
{
  "message": "Cannot create package for fulfillment in status: pending",
  "status": false
}
```

422-mismatch example (translated, no map):

```json
{
  "message": "Packing task #3 belongs to a different fulfillment than #11",
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
