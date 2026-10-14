# Endpoint Name

Packages – Seal Package (`POST /api/v1/admin/packages/{id}/seal`)

# Description

Seals one open non-empty package (immutable contents, barcode assigned), returning 200 with package detail.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `POST` |
| Full path | `/api/v1/admin/packages/{id}/seal` (explicit `{id}` placeholder with `whereNumber('id')` — non-numeric segments match NO route) |
| Path composition | `api/v1` prefix + `admin/packages/{id}/seal` custom `Route::post(...)->name('api.admin.packages.seal')` in `packages/marvel/src/Rest/Routes.php` inside the `['api', 'auth:sanctum', 'throttle:admin']` group with route-level `permission:packing-execute` |
| Controller action | `App\Http\Controllers\Api\Admin\Wms\PackageController@seal(SealPackageRequest $request, int $id)` → validated payload → `whereKey->firstOrFail` (miss → 404 fixed); `authorizeWarehouseScope(package→fulfillment warehouse, 'packing-execute', asNotFound: FALSE)`; then `PackingService::sealPackage(package, weight, dimensions)` — ANY `\Exception` → 422 (code-verified). Service, under lock (code-verified): status MUST be `open` (else 422 `seal_status`); ZERO items → 422 `seal_empty`; else `sealed` + barcode (generated `PKG-…` when missing) + weight/dimensions (explicit values win, else retained) + `sealed_at` |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (group; no constructor middleware) |
| Permission | Required: `packing-execute` via the route pipe + scope check |
| Required headers | `Authorization: Bearer <sanctum-token>`, `Content-Type: application/json` |
| Recommended headers | `Accept: application/json` |
| Supported request formats | `application/json` |
| Rate limit | `throttle:admin`: 400 requests/minute per user id (or IP for guests), per `App\Providers\AppServiceProvider::boot` (code-verified) |
| Route name | `api.admin.packages.seal` (explicit named route; `route:list` verified; covered by the generic WMS route sweeps) |

Order matters: 401 → 403-route → 422-validation → 404-lookup → 403-scope → 422-state/empty → 200.

## Request inputs

`SealPackageRequest` runs before the action (`authorize: true`). All fields optional — an empty body seals with retained weight/dimensions (test-authored `[]` seal).

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `id` | path | integer | Required | No | `whereNumber('id')` + `int $id`; package must be open + non-empty for success | Open non-empty package id manageable by the caller | None | None | `4` | Identifies the package |
| `weight` | body | number | Optional | Yes | `nullable\|numeric\|gt:0` | `> 0` or omitted | `null` (retains package weight) | Explicit value wins over retained | `2.5` | Final weight override |
| `dimensions` | body | object | Optional | Yes | `nullable\|array\|min:1`, items `numeric` | Non-empty map or omitted | `null` (retains package dimensions) | Explicit value wins over retained | `{"length": 30}` | Final dimensions override |

## Request examples

```json
{
  "method": "POST",
  "url": "/api/v1/admin/packages/4/seal",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json"
  },
  "body": {}
}
```

# Responses

## Success: package sealed — HTTP 200 (DETAIL resource, barcode assigned)

Returned via `ApiResponse::apiResponse(__('wms.package.sealed'), 200, true, PackageDetailResource…)` — translated at the call site (`en` → `"Package sealed successfully."`, verified in `resources/lang/en/wms.php:141`). Test-authored: 200 + non-empty `barcode`; end-to-end seal before verify.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `wms.package.sealed` | `en` → `"Package sealed successfully."` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | Fresh `PackageDetailResource` (`status` = `sealed`, `barcode` set, `sealed_at` set) | Full detail keys (see show doc) |

```json
{
  "status": 200,
  "message": "Package sealed successfully.",
  "success": true,
  "data": {
    "id": 4,
    "status": "sealed",
    "barcode": "PKG-20261014-EF34GH56",
    "sealed_at": "2026-10-14T10:15:00+00:00"
  }
}
```

# Errors

No error-code field; every error below has Error code `None`. Validation failures use the VALIDATION envelope (`errors` map); seal refusals use the EXCEPTION envelope `{message, status: false}` (code-verified).

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated."` (WITH period, code-verified) | None | No valid token (test-authored 401 on this URI) | See 401 example |
| 403 | Authenticated caller lacks `packing-execute` (route pipe) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token without the permission (code-traced) | See 403 example |
| 403 | Out-of-scope `packing-execute` holder at the SCOPE layer (controller verbatim discarded — code-verified) | `{message: string, status: boolean}` | None | `"This action is unauthorized."` (`message.ERROR.UNAUTHORIZED_ACTION`) | None | Cross-warehouse package (code-traced) | See 403-scope example |
| 404 | No package with `{id}` (lookup miss) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string) | None | Unknown numeric id (code-traced) | See 404 example |
| 404 | Non-numeric `{id}` matches NO route | `{message: string, status: boolean}` | None | Framework passthrough (ILLUSTRATIVE `"Not Found"` — NOT live-verified) | None | Non-numeric segment | See 404-constraint example |
| 422 | Body validation failure (non-positive weight, empty dimensions map) | `{message: string, status: boolean, errors: object}` (WITH `errors` map) | None | `"The given data was invalid."` (`message.ERROR.INVALID_DATA`) | Per-field arrays | `weight: 0` (validation-message localization-tested in `en` + `ar` in `tests/Feature/ValidationMessagesTest.php`) | See 422-validation example |
| 422 | Wrong state (anything but `open`) | `{message: string, status: boolean}` (NO `errors` map) | None | Translated `wms.packing.seal_status`: `en` → `"Cannot seal package in status: sealed"` (status illustrative; verified in `resources/lang/en/wms.php:130`) | None | Reseal (code-traced) | See 422-state example |
| 422 | Empty package (zero items) | `{message: string, status: boolean}` (NO `errors` map) | None | Translated `wms.packing.seal_empty`: `en` → `"Cannot seal an empty package"` (verified in `resources/lang/en/wms.php:131`) | None | Item-less package (test-authored 422) | See 422-empty example |
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
    "weight": ["The weight must be greater than 0."]
  }
}
```

422-state example (translated, no map):

```json
{
  "message": "Cannot seal package in status: sealed",
  "status": false
}
```

422-empty example (translated, no map):

```json
{
  "message": "Cannot seal an empty package",
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
