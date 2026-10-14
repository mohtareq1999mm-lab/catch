# Endpoint Name

Packages – List Packages (`GET /api/v1/admin/packages`)

# Description

Lists one fulfillment's packages in reverse-id order with item counts inside the caller's warehouse scope, returning 200 with a paginated flat array. `fulfillment_id` is required — packages are never listed unbounded.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/admin/packages` (fixed path — no placeholders) |
| Path composition | `api/v1` prefix + `admin/packages` custom `Route::get(...)->name('api.admin.packages.index')` in `packages/marvel/src/Rest/Routes.php` inside the `['api', 'auth:sanctum', 'throttle:admin']` group with route-level `permission:view-fulfillment` |
| Controller action | `App\Http\Controllers\Api\Admin\Wms\PackageController@index(ListPackagesRequest $request)` → REQUIRED `fulfillment_id` → `whereKey->firstOrFail` (miss → 404 fixed; nothing precedes it — code-verified); `authorizeWarehouseScope(fulfillment.warehouse_id, 'view-fulfillment', asNotFound: TRUE)` (foreign fulfillment → 404 anti-enumeration, test-authored); packages of that fulfillment `orderByDesc('id')` with item counts; optional `status` narrow; `limit` caps at 100 (default 15) → 200 `PackageResource` collection. Pure read (code-verified) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (group; no constructor middleware) |
| Permission | Required: `view-fulfillment` (route pipe + scope check — BOTH the same permission) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute per user id (or IP for guests), per `App\Providers\AppServiceProvider::boot` (code-verified) |
| Route name | `api.admin.packages.index` (explicit named route; `route:list` verified; covered by the generic WMS route sweeps) |

Order matters: 401 → 403-route → 422-validation (missing `fulfillment_id`) → 404-fulfillment (miss OR scope denial — indistinguishable) → 200.

## Request inputs

`ListPackagesRequest` runs before the action (`authorize: true`). NOTE (code-verified): NO `exists` rule — unknown `fulfillment_id` does NOT 422 at validation; it 404s at the in-action lookup.

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller (also the scope key) |
| `fulfillment_id` | query | integer | Required | No | `required\|integer\|min:1` (missing → 422 WITH map; unknown → 404 at lookup) | Fulfillment id manageable by the caller | None | None | `11` | The fulfillment whose packages are listed (test-authored required + 200) |
| `status` | query | string | Optional | Yes | `nullable\|string\|in:open,sealed,handed_off,voided` | One status or omitted | `null` | None | `open` | Narrows listing to one status |
| `limit` | query | integer | Optional | Yes | `nullable\|integer\|min:1\|max:100` | `1`–`100` | `15` | None | `50` | Page size |

## Request examples

```json
{
  "method": "GET",
  "url": "/api/v1/admin/packages?fulfillment_id=11",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json"
  }
}
```

# Responses

## Success: packages listed — HTTP 200 (flat paginated array, item counts included)

Returned via `ApiResponse::apiResponse(__('wms.package.fetched_many'), 200, true, PackageResource::collection($paginated))`. Collection serializes as a FLAT array under `data` (same contract shape as the task indexes — code-verified). `item_count` is present because the query always `withCount('items')` (`whenCounted` guard satisfied — code-verified).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `wms.package.fetched_many` | `en` → `"Packages fetched successfully."` (verified in `resources/lang/en/wms.php:137`) |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | array | Always present on success | Package rows, newest first | Flat `PackageResource[]` (possibly `[]`) |
| `data[].id` | integer | Per row | Package id | — |
| `data[].fulfillment_id` | integer | Per row | Owning fulfillment | Echo of the filter |
| `data[].packing_task_id` | integer/null | Per row | Linked packing task | `null` when created standalone |
| `data[].package_number` | string | Per row | Human number | `PCK-…` (generated) |
| `data[].barcode` | string/null | Per row | Scan identity | `PKG-…` once sealed (generated when missing) |
| `data[].status` | string | Per row | Package status | `open`/`sealed`/`handed_off`/`voided` |
| `data[].weight` | number/null | Per row | Package weight | Float or `null` |
| `data[].item_count` | integer | Per row | Packed line count | Always present here (`withCount` — code-verified) |
| `data[].sealed_at` | string/null | Per row | Seal time | ISO-8601 |
| `data[].created_at` | string/null | Per row | Creation time | ISO-8601 |
| `data[].updated_at` | string/null | Per row | Update time | ISO-8601 |

```json
{
  "status": 200,
  "message": "Packages fetched successfully.",
  "success": true,
  "data": [
    {
      "id": 4,
      "fulfillment_id": 11,
      "packing_task_id": null,
      "package_number": "PCK-20261014-AB12CD",
      "barcode": null,
      "status": "open",
      "weight": null,
      "item_count": 1,
      "sealed_at": null,
      "created_at": "2026-10-14T09:00:00+00:00",
      "updated_at": "2026-10-14T09:00:00+00:00"
    }
  ]
}
```

# Errors

No error-code field; every error below has Error code `None`. Validation failures use the VALIDATION envelope (`errors` map); all other errors use the EXCEPTION envelope `{message, status: false}` (code-verified via `Handler`).

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated."` (WITH period, `message.ERROR.UNAUTHENTICATED` — Handler:170, code-verified) | None | No valid token (test-authored 401 on this URI) | See 401 example |
| 403 | Authenticated caller lacks `view-fulfillment` (route pipe) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token without the permission (code-traced) | See 403 example |
| 404 | Unknown `fulfillment_id` (lookup miss — no `exists` rule, code-verified) OR foreign fulfillment (scope anti-enumeration — indistinguishable by design) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string) | None | Bad id; foreign fulfillment (test-authored 404 on the foreign probe) | See 404 example |
| 422 | Missing/invalid query (`fulfillment_id` absent, bad `status`, `limit > 100`) | `{message: string, status: boolean, errors: object}` (WITH `errors` map) | None | `"The given data was invalid."` (`message.ERROR.INVALID_DATA`) | Per-field arrays | Omitted `fulfillment_id` (test-authored 422) | See 422-validation example |
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

404 example:

```json
{
  "message": "Resource Not Found",
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

429 example:

```json
{
  "success": false,
  "message": "Too many requests. Please try again later.",
  "data": null
}
```
