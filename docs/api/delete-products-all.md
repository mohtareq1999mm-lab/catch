# Endpoint Name

Products – Delete All Products (`DELETE /api/v1/products/all`)

# Description

Soft-deletes every product behind an explicit confirmation flag and returns the pre-count.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `DELETE` |
| Full path | `/api/v1/products/all` |
| Path composition | `api/v1` prefix + `products/all` custom `Route::delete` in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group with route-level `permission:delete-all-products` (single — the strongest product permission; `delete-product` alone FAILS here, code-verified split from single/bulk delete) |
| Controller action | `Marvel\Http\Controllers\ProductController@destroyAll(Request $request)` — NO `FormRequest` (inline `$request->boolean('confirm')` gate FIRST: falsy/missing → 422 translated `MESSAGE.DESTROY_ALL_CONFIRMATION_REQUIRED` — code-verified, NOT a validation 422: no `errors` map); then `Product::count()` (PRE-delete count, includes ALL rows visible to the default scope), `chunk(100)` per-row soft-deletes, `ActivityAuditService::recordBatch('products', 'destroy_all', ..., affected_count = pre-count)`; returns `deleted_count` (pre-count, NOT rows touched — rows added mid-run inflate neither; code-verified) + fresh `batch_uuid`. Failures throw BARE `MarvelException(SOMETHING_WENT_WRONG)` (escapes to 500 translated — no catch in this method) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required: `delete-all-products` via route middleware AND constructor `only: [destroyAll]` (double gate, same permission — code-verified) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en\|ar` (default `en`) |
| Supported request formats | `application/json` when sending a body (DELETE with a JSON body carrying `confirm`) |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | None (`->name()` absent — code-verified) |

Order matters: 401/403 → 422 (confirmation) → 200/500. There is NO undo — soft-deleted rows need restore flows outside this endpoint.

## Request inputs

No `FormRequest` — the ONLY gate is the inline boolean check (`$request->boolean()` accepts `true/1/"1"/"true"/"yes"/"on"`-ish truthy spellings per Laravel semantics; everything else 422s).

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | In `['en', 'ar']` | `en`, `ar` | `en` | None | `en` | Selects message locale |
| `confirm` | body | boolean-ish | Required (business) | No | Truthy per `$request->boolean()` (missing/false/0 → 422; code-verified) | Truthy values | None → 422 | None | `true` | Destructive-action confirmation |
| `reason` | body | string | Optional | No | No validation rule (free text, recorded on the audit batch) | Any string | Absent | None | `"catalog reset"` | Audit reason |

No endpoint test found — contracts code-traced. THIS IS A DESTRUCTIVE OPERATION (all-products wipe behind one flag) — call it deliberately.

## Request examples

```json
{
  "method": "DELETE",
  "url": "/api/v1/products/all",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json",
    "lang": "en"
  },
  "body": {
    "confirm": true
  }
}
```

# Responses

## Success: catalog wiped — HTTP 200

Returned via `ApiResponse::apiResponse(PRODUCTS_DELETED_SUCCESSFULLY, 200, true, ['deleted_count' => $count, 'batch_uuid' => $batchUuid])` (same plural message as bulk-delete).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.PRODUCTS_DELETED_SUCCESSFULLY` | `en` → `"Products deleted successfully"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | Result payload (keys verified) | `deleted_count` (PRE-delete `Product::count()` — not rows touched), `batch_uuid` (fresh UUID v4) |

```json
{
  "status": 200,
  "message": "Products deleted successfully",
  "success": true,
  "data": {
    "deleted_count": 42,
    "batch_uuid": "123e4567-e89b-12d3-a456-426614174000"
  }
}
```

# Errors

No error-code field; every error below has Error code `None`. There is deliberately no 404 (nothing is looked up).

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | No valid token (code-traced) | See 401 example |
| 403 | Authenticated caller lacks `delete-all-products` (`delete-product` alone is NOT enough) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token without `delete-all-products` (code-traced split) | See 403 example |
| 422 | Missing/falsy `confirm` (inline boolean gate — NO `errors` map, unlike validation 422s; code-verified shape) | `{status: integer, message: string, success: boolean}` (NO `errors` key) | None | Translated `MESSAGE.DESTROY_ALL_CONFIRMATION_REQUIRED`: `en` → `"Confirmation is required to delete all products."` | None | Body without truthy `confirm` | See 422 example |
| 500 | Wipe failure after confirmation (bare `MarvelException` → translated 500; partial wipes possible — chunked deletes are NOT transactional across chunks, code-verified) | `{status: integer, message: string, success: boolean}` | None | Translated `ERROR.SOMETHING_WENT_WRONG` | None | Mid-wipe failure | See 500 example |
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

422 example (NO `errors` map — inline gate):

```json
{
  "status": 422,
  "message": "Confirmation is required to delete all products.",
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
