# Endpoint Name

Flash Sale – Show Flash Sale (`GET /api/v1/flash-sale/{flash_sale}`)

# Description

Returns one flash sale by id or slug with products and bilingual title.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/flash-sale/{flash_sale}` |
| Path composition | `api/v1` prefix + `flash-sale/{flash_sale}` from `Route::apiResource('flash-sale', ...)` in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group, plus route-level ANY-of pipe middleware (constructor `view-flash-sale` gate decides) |
| Controller action | `Marvel\Http\Controllers\FlashSaleController@show(Request $request, $params)` (untyped — numeric segment → `where('id')` lookup, else `where('slug')` lookup, both with `products` eager-loaded — same dispatch pattern as attributes show, code-verified); miss → explicit `throw new MarvelException(NOT_FOUND)` → catch → `apiResponse(NOT_FOUND, 404, false)` (translated `ERROR.NOT_FOUND` — this path is REAL here, unlike the Prettus-based controllers where misses escape; code-verified) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `view-flash-sale` via controller constructor (applies to `index` and `show`) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en\|ar` (default `en`) |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `flash-sale.show` (singular apiResource naming — and NOTE: the resource's locale-string branch checks `routeIs('flash-sale.index')`, FALSE here, so `title` is the `{ar,en}` OBJECT on show) |

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | In `['en', 'ar']` | `en`, `ar` | `en` | None | `en` | Selects message locale (`title` is bilingual here regardless) |
| `flash_sale` | path | integer id OR slug string | Required | No | No route-level constraint; `is_numeric` dispatch (soft-deleted rows miss either way — `FlashSale` uses `SoftDeletes`, code-verified) | Id or slug of a non-deleted flash sale | None | None | `1` or `summer-sale` | Identifies the flash sale |

No query parameters, body fields, or `422` contract. No endpoint test found — contracts code-traced.

## Request examples

```json
{
  "method": "GET",
  "url": "/api/v1/flash-sale/1",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: flash sale returned — HTTP 200

Returned via `ApiResponse::apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, FlashSaleResource::make($flash_sale))` with `products` loaded (key present).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.FETCH_DATA_SUCCESSFULLY` | `en` → `"Data fetched successfully"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | `FlashSaleResource` with bilingual `title` object | `id`, `title{ar,en}`, `slug`, `image{desktop, mobile}`, `description` (locale string), `start_date`, `end_date`, `status`, `is_valid`, `type` (localized label), `discount`, `max_discount_amount`, `created_at` (`Y-m-d`), `products` (array — loaded) |

```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": {
    "id": 1,
    "title": { "ar": "تخفيضات الصيف", "en": "Summer Sale" },
    "slug": "summer-sale",
    "image": { "desktop": "", "mobile": "" },
    "description": "Up to 50% off",
    "start_date": "2026-01-01",
    "end_date": "2026-02-01",
    "status": true,
    "is_valid": true,
    "type": "Percentage discount",
    "discount": "50.00",
    "max_discount_amount": 100.0,
    "created_at": "2026-01-01",
    "products": []
  }
}
```

# Errors

No error-code field; every error below has Error code `None`. No validation, so no `422`.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | No valid token (code-traced) | See 401 example |
| 403 | Authenticated caller lacks `view-flash-sale` | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token without `view-flash-sale` (code-traced) | See 403 example |
| 404 | No flash sale matches `{flash_sale}` by id or slug (explicit in-controller `MarvelException(NOT_FOUND)` → translated path — code-verified, unlike FAQ/Prettus misses) | `{status: integer, message: string, success: boolean}` (ApiResponse shape) | None | Translated `ERROR.NOT_FOUND`: `en` → `"Not found"` | None | Unknown id or slug | See 404 example |
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

429 example:

```json
{
  "success": false,
  "message": "Too many requests. Please try again later.",
  "data": null
}
```
