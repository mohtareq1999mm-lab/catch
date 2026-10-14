# Endpoint Name

Packages – Void Package (`POST /api/v1/admin/packages/{id}/void`)

# Description

Voids one open package with a required reason (sealed custody is never silently destroyed; the row and items are retained for audit and never block a replacement), returning 200 with package detail.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `POST` |
| Full path | `/api/v1/admin/packages/{id}/void` (explicit `{id}` placeholder with `whereNumber('id')` — non-numeric segments match NO route) |
| Path composition | `api/v1` prefix + `admin/packages/{id}/void` custom `Route::post(...)->name('api.admin.packages.void')` in `packages/marvel/src/Rest/Routes.php` inside the `['api', 'auth:sanctum', 'throttle:admin']` group with route-level `permission:manage-fulfillment` |
| Controller action | `App\Http\Controllers\Api\Admin\Wms\PackageController@void(BatchCommandRequest $request, int $id)` → validated reason → `whereKey->firstOrFail` (miss → 404 fixed); `authorizeWarehouseScope(package→fulfillment warehouse, 'manage-fulfillment', asNotFound: FALSE)`; then `PackingService::voidPackage(package, reason)` — `InvalidArgumentException`/`RuntimeException` → 422 (code-verified). Service (code-verified): null/blank-after-trim reason → 422 `void_reason` (NOTE: `' '` passes validation `min:1` and fails HERE — same split as packing cancel/skip); ALREADY voided → 422 `package_voided`; non-`open` (sealed/handed_off) → 422 `void_status` (sealed packages are physical custody — left untouched and logged, never voided; sealed-on-cancel needs a future business decision, code-commented); else `voided` + trimmed reason into `notes`, items retained. Voided rows are excluded from quantity math and never block a replacement package (code-verified) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (group; no constructor middleware) |
| Permission | Required: `manage-fulfillment` via the route pipe + scope check (a packer/supervisor without it gets 403 — test-authored permission split) |
| Required headers | `Authorization: Bearer <sanctum-token>`, `Content-Type: application/json` |
| Recommended headers | `Accept: application/json` |
| Supported request formats | `application/json` |
| Rate limit | `throttle:admin`: 400 requests/minute per user id (or IP for guests), per `App\Providers\AppServiceProvider::boot` (code-verified) |
| Route name | `api.admin.packages.void` (explicit named route; `route:list` verified; covered by the generic WMS route sweeps) |

Order matters: 401 → 403-route → 422-validation → 404-lookup → 403-scope → 422-reason/voided/state → 200.

## Request inputs

`BatchCommandRequest` runs before the action (`authorize: true`).

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `id` | path | integer | Required | No | `whereNumber('id')` + `int $id`; package must be `open` for success | Open package id manageable by the caller | None | None | `4` | Identifies the package |
| `reason` | body | string | Required | No | `required\|string\|min:1\|max:2000` at validation (missing → 422 WITH map); whitespace-only passes validation then 422 WITHOUT map at the service (code-verified split) | Non-blank string | None | Trimmed into `notes` | `"wrong box"` | Why the package is voided (test-authored) |

## Request examples

```json
{
  "method": "POST",
  "url": "/api/v1/admin/packages/4/void",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json"
  },
  "body": {
    "reason": "wrong box"
  }
}
```

# Responses

## Success: package voided — HTTP 200 (DETAIL resource, row retained)

Returned via `ApiResponse::apiResponse(__('wms.package.voided'), 200, true, PackageDetailResource…)` — translated at the call site (`en` → `"Package voided successfully."`, verified in `resources/lang/en/wms.php:142`). Test-authored: open void → 200 + `voided`; replacement package afterwards → 201 with a different id.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `wms.package.voided` | `en` → `"Package voided successfully."` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | Fresh `PackageDetailResource` (`status` = `voided`, `notes` = trimmed reason, items retained) | Full detail keys (see show doc) |

```json
{
  "status": 200,
  "message": "Package voided successfully.",
  "success": true,
  "data": {
    "id": 4,
    "status": "voided",
    "notes": "wrong box"
  }
}
```

# Errors

No error-code field; every error below has Error code `None`. Validation failures use the VALIDATION envelope (`errors` map); service refusals use the EXCEPTION envelope `{message, status: false}` (code-verified). The blank-reason text lives under `wms.packing.*` (code-verified namespace placement).

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated."` (WITH period, code-verified) | None | No valid token (test-authored 401 on this URI) | See 401 example |
| 403 | Authenticated caller lacks `manage-fulfillment` (route pipe) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Packer without the permission (test-authored 403 with state provably still `open`) | See 403 example |
| 403 | Out-of-scope `manage-fulfillment` holder at the SCOPE layer (controller verbatim discarded — code-verified) | `{message: string, status: boolean}` | None | `"This action is unauthorized."` (`message.ERROR.UNAUTHORIZED_ACTION`) | None | Cross-warehouse void (code-traced) | See 403-scope example |
| 404 | No package with `{id}` (lookup miss) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string) | None | Unknown numeric id (code-traced) | See 404 example |
| 404 | Non-numeric `{id}` matches NO route | `{message: string, status: boolean}` | None | Framework passthrough (ILLUSTRATIVE `"Not Found"` — NOT live-verified) | None | Non-numeric segment | See 404-constraint example |
| 422 | Body validation failure (missing/non-string/over-long reason) | `{message: string, status: boolean, errors: object}` (WITH `errors` map) | None | `"The given data was invalid."` (`message.ERROR.INVALID_DATA`) | Per-field arrays | Missing `reason` (code-traced) | See 422-validation example |
| 422 | Blank-after-trim reason (passes `min:1`, refused by the service — code-verified split) | `{message: string, status: boolean}` (NO `errors` map) | None | Translated `wms.packing.void_reason`: `en` → `"Void reason is required"` (verified in `resources/lang/en/wms.php:132`) | None | `' '` reason (batch-cancel-tested pattern) | See 422-reason example |
| 422 | Already voided (repeat void refused — idempotency is explicitly NOT offered here, code-verified) | `{message: string, status: boolean}` (NO `errors` map) | None | Translated `wms.packing.package_voided` WITH id: `en` → `"Package #4 is already voided"` (ids illustrative; verified in `resources/lang/en/wms.php:133`) | None | Second void (code-traced) | See 422-replay example |
| 422 | Non-`open` package (sealed/handed_off custody — never silently destroyed) | `{message: string, status: boolean}` (NO `errors` map) | None | Translated `wms.packing.void_status`: `en` → `"Cannot void package in status: sealed (only open packages may be voided)"` (status illustrative; verified in `resources/lang/en/wms.php:134`) | None | Sealed package (test-authored 422) | See 422-state example |
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

404-constraint example (ILLUSTRATIVE — NOT live-verified):

```json
{
  "message": "Not Found",
  "status": false
}
```

422-validation example (WITH `errors` map):

```json
{
  "message": "The given data was invalid.",
  "status": false,
  "errors": {
    "reason": ["The reason field is required."]
  }
}
```

422-reason example (translated, no map):

```json
{
  "message": "Void reason is required",
  "status": false
}
```

422-replay example (translated WITH id, no map):

```json
{
  "message": "Package #4 is already voided",
  "status": false
}
```

422-state example (translated, no map):

```json
{
  "message": "Cannot void package in status: sealed (only open packages may be voided)",
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
