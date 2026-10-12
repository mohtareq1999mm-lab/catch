# Endpoint Name

Products – Bulk Delete (`POST /api/v1/products/bulk-delete`)

# Description

Soft-deletes a validated id list in chunks with an audit batch and returns requested ids.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `POST` |
| Full path | `/api/v1/products/bulk-delete` |
| Path composition | `api/v1` prefix + `products/bulk-delete` custom `Route::post` in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group with route-level `permission:delete-all-products\|delete-product` (ANY-of extremes — either permission passes the pipe; the constructor `delete-product` gate on `destroyBulk` is then decisive in practice, so `delete-product` alone suffices end-to-end — code-verified) |
| Controller action | `Marvel\Http\Controllers\ProductController@destroyBulk(BulkDeleteProductsRequest $request)` → validated `ids` → `whereIn->chunk(100)` per-row `deleteProduct` (SOFT deletes; unknown ids inside a validated list are IMPOSSIBLE — `exists` rejects them — so partial success cannot occur at this layer); `ActivityAuditService::recordBatch('products', 'bulk_delete', ..., affected_count = count($ids))` (REQUESTED count, not rows touched — code-verified); `flushTag(PRODUCTS)`; returns `deleted_ids` (echoed request) + fresh `batch_uuid`. `MarvelException` → rethrown with its own message (escapes to `Handler`: `MarvelException` branch — 404/403/500 by marker, else 500) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Effectively `delete-product` (route ANY-of pipe + constructor `only: [destroy, destroyBulk]` gate — code-verified) |
| Required headers | `Authorization: Bearer <sanctum-token>`, `Content-Type: application/json` |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en\|ar` (default `en`) |
| Supported request formats | `application/json` |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | None (`->name()` absent — code-verified) |

Middleware runs before validation: 401/403 precede 422.

## Request inputs

`BulkDeleteProductsRequest` runs before the body (`exists:products,id` is scopeless — trashed ids PASS validation, then `whereIn` skips them silently while `affected_count` still counts them; code-verified wart).

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | In `['en', 'ar']` | `en`, `ar` | `en` | None | `en` | Selects message locale |
| `ids` | body | array of integers | Required | No | `required\|array\|min:1` | Non-empty array | None | None | `[1, 2]` | Products to soft-delete |
| `ids.*` | body | integer | Required per element | No | `integer\|min:1\|distinct\|exists:products,id` (duplicates rejected; unknown ids 422 — NOT skipped) | Existing product ids, no duplicates | None | None | `1` | One product id |
| `reason` | body | string | Optional | No | No validation rule (free text, recorded on the audit batch; code-verified passthrough) | Any string | Absent | None | `"season clearout"` | Audit reason |

No endpoint test found — contracts code-traced.

## Request examples

```json
{
  "method": "POST",
  "url": "/api/v1/products/bulk-delete",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json",
    "lang": "en"
  },
  "body": {
    "ids": [1, 2]
  }
}
```

# Responses

## Success: ids deleted — HTTP 200

Returned via `ApiResponse::apiResponse(PRODUCTS_DELETED_SUCCESSFULLY, 200, true, ['deleted_ids' => $ids, 'batch_uuid' => $batchUuid])` (plural message shared with destroy-all — code-verified).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.PRODUCTS_DELETED_SUCCESSFULLY` | `en` → `"Products deleted successfully"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | Result payload (keys verified) | `deleted_ids` (ECHO of the request — not a touched-rows list), `batch_uuid` (fresh UUID v4 string for audit correlation) |

```json
{
  "status": 200,
  "message": "Products deleted successfully",
  "success": true,
  "data": {
    "deleted_ids": [1, 2],
    "batch_uuid": "123e4567-e89b-12d3-a456-426614174000"
  }
}
```

# Errors

No error-code field; every error below has Error code `None`.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | No valid token (code-traced) | See 401 example |
| 403 | Authenticated caller lacks `delete-product` (and `delete-all-products`) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token without either permission (code-traced) | See 403 example |
| 422 | `BulkDeleteProductsRequest` fails (missing/empty `ids`, duplicates, unknown ids) | `{message: string, status: boolean, errors: object}` | None | Laravel default | Field → messages | Invalid body (code-traced; same rule shape as the country/governorate bulk 422 tests) | See 422 example |
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

422 example:

```json
{
  "message": "The ids field is required.",
  "status": false,
  "errors": {
    "ids": ["The ids field is required."]
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
