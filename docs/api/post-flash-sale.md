# Endpoint Name

Flash Sale – Create Flash Sale (`POST /api/v1/flash-sale`)

# Description

Creates one flash sale with hyphenated image fields and optional products, returning 200 with the resource.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `POST` |
| Full path | `/api/v1/flash-sale` (singular — code-verified) |
| Path composition | `api/v1` prefix + `flash-sale` from `Route::apiResource('flash-sale', ...)` in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group, plus route-level ANY-of pipe middleware (constructor `create-flash-sale` gate decides) |
| Controller action | `Marvel\Http\Controllers\FlashSaleController@store` → `FlashSaleRepository::storeFlashSale` in a DB transaction: slug via `makeSlug`, row create from `dataArray` (title/slug/description/dates/type/status/max_discount/discount), hyphenated image uploads (`image-desktop`/`image-mobile` → `flash-sales-desktop`/`flash-sales-mobile` collections), `products` sync + `has_flash_sale` flags; ANY failure rolls back and rethrows as `HttpException(500, SOMETHING_WENT_WRONG)` — which is NOT a `MarvelException`, so it ESCAPES the controller catch-all into `Handler`'s `HttpException` branch → 500 with the RAW key (code-verified wart: the translated 500 in the catch block is dead for repository failures) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `create-flash-sale` via controller constructor (applies only to `store`); `CreateFlashSaleRequest::authorize` returns `true` |
| Required headers | `Authorization: Bearer <sanctum-token>`, `Content-Type: multipart/form-data` (two image files are required) |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en\|ar` (default `en`) |
| Supported request formats | `multipart/form-data` (image rules require real files; plain JSON without files fails 422) |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `flash-sale.store` (singular apiResource naming) |

Middleware runs before validation: 401/403 precede 422. NOTE success status is **200, NOT 201** (code-verified — the controller passes `200`; no flash-sale test pins it, unlike FAQs which use 201).

## Request inputs

`CreateFlashSaleRequest` runs before the body. NOTE the HYPHENATED image keys (`image-desktop`, not `image_desktop` as in banners — code-verified asymmetry). `type` allows THREE values (`percentage`, `fixed_rate`, `final_price` per `FlashSaleType::getValues()`). `status` is REQUIRED here (unlike most resources where it is `sometimes`).

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | In `['en', 'ar']` | `en`, `ar` | `en` | None | `en` | Selects message locale |
| `title` | body | object | Required | No | `required\|array` | Object with locales | None | None | `{"en": "Summer Sale", "ar": "تخفيضات الصيف"}` | Localized titles |
| `title.*` | body | string | Required per locale | No | `required\|string\|min:3\|max:70` + `UniqueTranslationRule::for('flash_sales', 'title')` (vendor text **Unverified in source**) | String 3–70 chars, unique | None | None | `"Summer Sale"` | One locale's title (slug derives from it via `makeSlug`) |
| `description` | body | object | Required | No | `required\|array` | Object with locales | None | None | `{"en": "Up to 50% off"}` | Localized descriptions |
| `description.*` | body | string | Required per locale | No | `required\|string\|max:1000` (NO min, NO uniqueness — code-verified laxity vs title) | String ≤ 1000 chars | None | None | `"Up to 50% off"` | One locale's description |
| `image-desktop` | body (file) | file (image) | Required | No | `required\|image\|mimes:jpeg,png,jpg,webp,gif\|max:` (no explicit max — rule has no size cap; code-verified vs banners' 2048) | jpeg/png/jpg/webp/gif | None | None | `desktop.jpg` | Desktop image (hyphenated key!) |
| `image-mobile` | body (file) | file (image) | Required | No | Same rule set as `image-desktop` | jpeg/png/jpg/webp/gif | None | None | `mobile.jpg` | Mobile image (hyphenated key!) |
| `start_date` | body | date string | Required | No | `required\|date` | Any date | None | None | `"2026-01-01"` | Sale start |
| `end_date` | body | date string | Required | No | `required\|date\|after_or_equal:start_date` | Date ≥ start | None | None | `"2026-02-01"` | Sale end |
| `type` | body | string | Required | No | `required\|Rule::in(percentage, fixed_rate, final_price)` | `percentage`, `fixed_rate`, `final_price` | None | None | `"percentage"` | Discount mechanics |
| `discount` | body | number | Required | No | `required\|numeric\|min:0` | Number ≥ 0 | None | None | `50` | Discount value |
| `max_discount_amount` | body | number | Conditionally required | No | `required_if:type,percentage\|numeric\|min:1` (required ONLY for percentage; absent otherwise — no prohibition check, code-verified) | Number ≥ 1 | None | Required iff `type` is `percentage` | `100` | Cap on percentage discounts |
| `status` | body | string/integer | Required | No | `required\|in:1,0` (REQUIRED — not `sometimes`; code-verified strictness) | `1`, `0` | None | None | `1` | Active flag |
| `products` | body | array | Optional | No | `sometimes\|array` | Array of ids | Absent (no links) | None | `[3]` | Products to attach (sync + `has_flash_sale` flags) |
| `products.*` | body | integer | Required per element | No | `integer\|exists:products,id` (NOTE: no `required` on the element itself, unlike the reorder rule — code-verified) | Existing product ids | None | None | `3` | One product id |

No path or query parameters. No endpoint test found — contracts code-traced.

## Request examples

Multipart request creating a flash sale (files shown as placeholders):

```json
{
  "method": "POST",
  "url": "/api/v1/flash-sale",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "multipart/form-data",
    "lang": "en"
  },
  "body": {
    "title": { "en": "Summer Sale", "ar": "تخفيضات الصيف" },
    "description": { "en": "Up to 50% off" },
    "image-desktop": "<file desktop.jpg>",
    "image-mobile": "<file mobile.jpg>",
    "start_date": "2026-01-01",
    "end_date": "2026-02-01",
    "type": "percentage",
    "discount": 50,
    "max_discount_amount": 100,
    "status": 1
  }
}
```

# Responses

## Success: flash sale created — HTTP 200

Returned via `ApiResponse::apiResponse(CREATE_FLASH_SALE_SUCCESSFULLY, 200, true, FlashSaleResource::make($flashSale))` with `products` loaded. Route is NOT `flash-sale.index`, so `title` is the `{ar,en}` OBJECT.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success (NOT 201 — code-verified) |
| `message` | string | Always present | Translated `MESSAGE.CREATE_FLASH_SALE_SUCCESSFULLY` | `en` → `"Flash sale created successfully"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | `FlashSaleResource` (NOT index → bilingual `title` object; `description` always locale string) | `id`, `title{ar,en}`, `slug`, `image{desktop, mobile}`, `description` (string), `start_date`, `end_date`, `status`, `is_valid`, `type` (localized label), `discount`, `max_discount_amount`, `created_at` (`Y-m-d`), `products` (array — loaded) |

```json
{
  "status": 200,
  "message": "Flash sale created successfully",
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

No error-code field; every error below has Error code `None`.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | No valid token (code-traced) | See 401 example |
| 403 | Authenticated caller lacks `create-flash-sale` | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token without `create-flash-sale` (code-traced) | See 403 example |
| 422 | `CreateFlashSaleRequest` fails (missing title/images/dates/type/discount/status, bad `type`, `end_date` < `start_date`, missing cap for percentage, unknown `products.*`) | `{message: string, status: boolean, errors: object}` | None | Laravel default | Field → messages | Invalid body (code-traced) | See 422 example |
| 500 | Repository/transaction failure AFTER validation (image upload failure, DB error) — escapes as `HttpException(500, raw-key)` → RAW key (the translated catch-block 500 is dead for this path; code-verified) | `{message: string, status: boolean}` | None | `"ERROR.SOMETHING_WENT_WRONG"` (RAW untranslated key — `Handler` does not translate `HttpException` messages) | None | Post-validation failure | See 500 example |
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
  "message": "The image-desktop field is required. (and 1 more error)",
  "status": false,
  "errors": {
    "image-desktop": ["The image-desktop field is required."],
    "image-mobile": ["The image-mobile field is required."]
  }
}
```

500 example (post-validation failure — RAW key):

```json
{
  "message": "ERROR.SOMETHING_WENT_WRONG",
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
