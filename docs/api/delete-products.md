# Endpoint Name

Products – Delete Product (`DELETE /api/v1/products/{product}`)

# Description

Soft-deletes one product by id with explicit miss mapping and returns 200 with no data.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `DELETE` |
| Full path | `/api/v1/products/{product}` |
| Path composition | `api/v1` prefix + `products/{product}` from `Route::apiResource('products', ...)` in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group, plus route-level ANY-of pipe middleware (constructor `delete-product` gate decides — note: `destroyBulk` shares this gate while `destroyAll` needs `delete-all-products`) |
| Controller action | `Marvel\Http\Controllers\ProductController@destroy(Request $request, $id)` → merges path `id` → `destroyProduct` helper → `findOrFail` + `delete()` (`Product` uses `SoftDeletes` — SOFT delete, code-verified); miss (`ModelNotFoundException`) → rethrown `MarvelException(NOT_FOUND)` → escapes (no catch in `destroy` itself) → `Handler` `MarvelException` branch: contains `NOT_FOUND` → 404 TRANSLATED `'Not found'` (code-verified chain — compare update's fixed-string miss); any OTHER exception → `MarvelException(SOMETHING_WENT_WRONG)` → 500 translated |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `delete-product` via controller constructor (applies to `destroy` AND `destroyBulk`); `delete-all-products` is NOT accepted here (code-verified — bulk-all needs the stronger permission, single delete needs this one) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en\|ar` (default `en`) |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `products.destroy` (apiResource default naming) |

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | In `['en', 'ar']` | `en`, `ar` | `en` | None | `en` | Selects message locale |
| `product` | path | mixed id | Required | No | No route constraint; merged as `id` for `findOrFail` (soft-deleted ids miss — no restore path here) | Id of a non-deleted product | None | None | `1` | Identifies the product |

No query parameters, body fields, or `422` contract. Variant/media/pivot cleanup is model/database-level (**Unverified in source** — no explicit cleanup in the helper).

## Request examples

```json
{
  "method": "DELETE",
  "url": "/api/v1/products/1",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: product soft-deleted — HTTP 200

Returned via `ApiResponse::apiResponse(DELETE_PRODUCT_SUCCESSFULLY, 200, true)` with NO data key (plus `flushTag(PRODUCTS)`). Runtime-probed 2026-10-17: `{"status":200,"message":"Product deleted successfully","success":true}` — key set matches below exactly.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.DELETE_PRODUCT_SUCCESSFULLY` | `en` → `"Product deleted successfully"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success (verified: `apiResponse(..., 200, true)` with no data — `true` is hardcoded on this path) |
| `data` | — | Never present on success | Omitted by design: `ApiResponse` adds the `data` key only when `!empty($data)`, and this path passes no data | Key absent (do not expect `data: null` or `data: {}`) |

```json
{
  "status": 200,
  "message": "Product deleted successfully",
  "success": true
}
```

# Errors

No error-code field; every error below has Error code `None`. No validation, so no `422`.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | No valid token (strict `assertEquals(401)` in `test_guest_cannot_delete_product`, `deleteJson`) | See 401 example |
| 403 | Authenticated caller lacks `delete-product` | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | View-only user (verified by `test_view_only_user_cannot_delete` → 403) | See 403 example |
| 404 | No product with id (miss → rethrown `MarvelException(NOT_FOUND)` → TRANSLATED — code-verified chain) | `{status: integer, message: string, success: boolean}` | None | Translated `ERROR.NOT_FOUND`: `en` → `"Not found"` | None | Unknown id (code-traced; no destroy-miss test found) | See 404 example |
| 500 | Delete failure after lookup (`MarvelException(SOMETHING_WENT_WRONG)` → translated 500) | `{status: integer, message: string, success: boolean}` | None | Translated `ERROR.SOMETHING_WENT_WRONG` | None | Post-lookup failure (code-traced) | See 500 example |
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

404 example:

```json
{
  "status": 404,
  "message": "Not found",
  "success": false
}
```

500 example:

```json
{
  "status": 500,
  "message": "Something went wrong",
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
