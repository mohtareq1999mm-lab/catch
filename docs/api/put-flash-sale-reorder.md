# Endpoint Name

Flash Sale – Reorder Flash Sales (`PUT /api/v1/flash-sale/reorder`)

# Description

Replaces the display order of flash sales from an ordered id list and returns 200 with no data.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `PUT` (only `PUT` is registered — `POST`/`PATCH` on this path → 405; note the sibling `banner/reorder` is POST-only — code-verified method asymmetry across resources) |
| Full path | `/api/v1/flash-sale/reorder` (singular — code-verified) |
| Path composition | `api/v1` prefix + `flash-sale/reorder` custom `Route::put` in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group with single-permission `permission:update-flash-sale`. Registered BEFORE `apiResource('flash-sale', ...)`, so `reorder` is matched here and NOT captured as `flash-sale/{flash_sale}` (code-verified ordering safety — same pattern as `faqs/reorder` and `governorates/change-status`) |
| Controller action | `Marvel\Http\Controllers\FlashSaleController@reorder` → inline `$request->validate(['flash_sales' => 'required\|array', 'flash_sales.*' => 'required\|exists:flash_sales,id'])` (→ 422); then `FlashSaleRepository::reorder` → `setNewOrder` (SortableTrait `order` column); repository failures throw `HttpException(500, live-message)` which the controller catches EXPLICITLY (`catch (HttpException)`) → translated 500 (NOTE: unlike store/update, the reorder catch DOES match — translated 500 here, code-verified difference) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `update-flash-sale` via route middleware AND constructor `only: [update, reorder]` (double gate, same permission) |
| Required headers | `Authorization: Bearer <sanctum-token>`, `Content-Type: application/json` |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en\|ar` (default `en`) |
| Supported request formats | `application/json` |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | None (`->name()` absent — code-verified) |

Order matters: 401/403 → 422 (validation) → 200/500. Duplicate ids are NOT rejected (no `distinct` rule — code-verified laxity shared with banners/faqs reorder).

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | In `['en', 'ar']` | `en`, `ar` | `en` | None | `en` | Selects message locale |
| `flash_sales` | body | array of integers | Required | No | `required\|array` (empty array passes validation, then `setNewOrder([])` is a no-op success — code-verified edge) | Array of flash-sale ids in desired display order | None | None | `[2, 1]` | The new order (first element = position 1) |
| `flash_sales.*` | body | integer | Required per element | No | `required\|exists:flash_sales,id` (scopeless; element-level `required` — stricter than the store `products.*`, code-verified) | Existing flash-sale ids | None | None | `2` | One flash-sale id in sequence |

No path or query parameters. No endpoint test found — contracts code-traced.

## Request examples

```json
{
  "method": "PUT",
  "url": "/api/v1/flash-sale/reorder",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json",
    "lang": "en"
  },
  "body": {
    "flash_sales": [2, 1]
  }
}
```

# Responses

## Success: flash sales reordered — HTTP 200

Returned via `ApiResponse::apiResponse(FLASH_SALE_REORDERED_SUCCESSFULLY, 200, true)` with NO data key. (No flash-sale cache flush in code — unlike store/update/destroy, reorder does NOT call `flushTag`; the cached index may serve stale order until expiry, code-verified asymmetry.)

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.FLASH_SALE_REORDERED_SUCCESSFULLY` | `en` → `"Flash sales reordered successfully"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |

```json
{
  "status": 200,
  "message": "Flash sales reordered successfully",
  "success": true
}
```

# Errors

No error-code field; every error below has Error code `None`.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | No valid token (code-traced) | See 401 example |
| 403 | Authenticated caller lacks `update-flash-sale` | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token without `update-flash-sale` (code-traced) | See 403 example |
| 405 | `POST`/`PATCH` on `/api/v1/flash-sale/reorder` (only `PUT` registered) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` | None | Wrong method (code-traced) | See 405 example |
| 422 | Inline validation fails (missing `flash_sales` key, non-array, unknown id) | `{message: string, status: boolean, errors: object}` | None | Laravel default | Field → messages | Absent key or `[9999]` (code-traced; same rule shape as the FAQ reorder tests) | See 422 example |
| 500 | `setNewOrder` throws AFTER validation → caught `HttpException` → translated key (message swallowed, code-verified) | `{status: integer, message: string, success: boolean}` | None | Translated `ERROR.SOMETHING_WENT_WRONG`: `en` → `"Something went wrong"` | None | Post-validation failure | See 500 example |
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
  "message": "The flash sales field is required.",
  "status": false,
  "errors": {
    "flash_sales": ["The flash sales field is required."]
  }
}
```

500 example (post-validation failure — translated):

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
