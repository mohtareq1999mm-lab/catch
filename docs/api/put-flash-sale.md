# Endpoint Name

Flash Sale – Update Flash Sale (`PUT /api/v1/flash-sale/{flash_sale}`)

# Description

Partially or fully updates one flash sale by id, repricing its products, and returns 200.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `PUT` (apiResource also registers `PATCH` → same action) |
| Full path | `/api/v1/flash-sale/{flash_sale}` |
| Path composition | `api/v1` prefix + `flash-sale/{flash_sale}` from `Route::apiResource('flash-sale', ...)` in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group, plus route-level ANY-of pipe middleware (constructor `update-flash-sale` gate decides) |
| Controller action | `Marvel\Http\Controllers\FlashSaleController@update(UpdateFlashSaleRequest $request, $id)` → merges path `id` into the request → `updateFlashSale` → `FlashSaleRepository::updateFlashSale` in a DB transaction: `findOrFail`, slug recompute, mass-update (all fields EXCEPT the two hyphenated images), conditional image replacement, `products` sync with `has_flash_sale` flag maintenance (removed products get the flag cleared — code-verified), then `updateFlashSaleProductPrices` (active sales stamp `price_after_flash_sale` per product via `ProductPricingService`; inactive sales NULL it out); ANY exception → repository rethrows `HttpException(500, SOMETHING_WENT_WRONG)` → escapes the `MarvelException`-only controller catch → `Handler` 500 RAW key (code-verified wart — including the MISS path: updating an unknown id yields 500, NOT 404) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `update-flash-sale` via controller constructor (applies to `update` and `reorder`); `UpdateFlashSaleRequest::authorize` returns `true` |
| Required headers | `Authorization: Bearer <sanctum-token>`, `Content-Type: application/json` (or `multipart/form-data` when replacing images) |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en\|ar` (default `en`) |
| Supported request formats | `application/json` for field-only updates; `multipart/form-data` when sending `image-desktop`/`image-mobile` |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `flash-sale.update` (singular apiResource naming) |

Middleware runs before validation: 401/403 precede 422/500. Validation precedes the lookup, so 422 precedes 500 when both apply.

## Request inputs

`UpdateFlashSaleRequest` runs before the body. `UniqueTranslationRule::ignore($this->route("flash_sale"))` matches the `{flash_sale}` placeholder, so keeping the sale's own title passes (code-verified). `max_discount_amount` keeps its `required_if:type,percentage` rule even on update (sending `type: percentage` without a cap fails even when a cap already exists — code-verified strictness).

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | In `['en', 'ar']` | `en`, `ar` | `en` | None | `en` | Selects message locale |
| `flash_sale` | path | integer | Required | No | No route constraint; `$id` passed to `findOrFail` | Existing flash-sale id (slugs NOT accepted here, unlike show — code-verified asymmetry) | None | None | `1` | Identifies the flash sale |
| `title` | body | object | Optional | No | `sometimes\|array` | Object with locales | Unchanged | None | `{"en": "Winter Sale"}` | Localized titles wrapper |
| `title.*` | body | string | Optional | No | `sometimes\|string\|min:3\|max:70` + unique-ignore-self | String 3–70 chars | Unchanged | None | `"Winter Sale"` | One locale's title (recomputes slug) |
| `description` | body | object | Optional | No | `sometimes\|array` | Object with locales | Unchanged | None | `{"en": "New text"}` | Localized descriptions wrapper |
| `description.*` | body | string | Optional | No | `sometimes\|string\|max:1000` | String ≤ 1000 chars | Unchanged | None | `"New text"` | One locale's description |
| `image-desktop` | body (file) | file (image) | Optional | No | `sometimes\|image\|mimes:jpeg,png,jpg,webp,gif` (absent = image kept) | jpeg/png/jpg/webp/gif | Unchanged | None | `desktop2.jpg` | Replacement desktop image |
| `image-mobile` | body (file) | file (image) | Optional | No | Same rule set as `image-desktop` | jpeg/png/jpg/webp/gif | Unchanged | None | `mobile2.jpg` | Replacement mobile image |
| `start_date` | body | date string | Optional | No | `sometimes\|date` | Any date | Unchanged | None | `"2026-01-01"` | Sale start |
| `end_date` | body | date string | Optional | No | `sometimes\|date\|after_or_equal:start_date` (compared against the REQUEST's `start_date`, not the stored one — sending only `end_date` compares against null; code-verified edge) | Date ≥ request start | Unchanged | None | `"2026-03-01"` | Sale end |
| `type` | body | string | Optional | No | `sometimes\|Rule::in(percentage, fixed_rate, final_price)` | `percentage`, `fixed_rate`, `final_price` | Unchanged | None | `"percentage"` | Discount mechanics |
| `discount` | body | number | Optional | No | `sometimes\|numeric\|min:0` | Number ≥ 0 | Unchanged | None | `40` | Discount value |
| `max_discount_amount` | body | number | Conditionally required | No | `required_if:type,percentage\|numeric\|min:1` | Number ≥ 1 | Unchanged | Required iff request `type` is `percentage` | `80` | Cap on percentage discounts |
| `status` | body | string/integer | Optional | No | `sometimes\|in:1,0` | `1`, `0` | Unchanged | None | `0` | Active flag |
| `products` | body | array | Optional | No | `sometimes\|array` (present-but-empty array DETACHES all products + clears their flags + nulls their flash prices — code-verified; absent key leaves products untouched) | Array of ids | Unchanged | None | `[3]` | Replacement product links |
| `products.*` | body | integer | Optional | No | `integer\|exists:products,id` | Existing product ids | None | None | `3` | One product id |

## Request examples

```json
{
  "method": "PUT",
  "url": "/api/v1/flash-sale/1",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json",
    "lang": "en"
  },
  "body": {
    "title": { "en": "Winter Sale" },
    "status": 0
  }
}
```

# Responses

## Success: flash sale updated — HTTP 200

Returned via `ApiResponse::apiResponse(UPDATE_FLASH_SALE_SUCCESSFULLY, 200, true, FlashSaleResource::make($flashSale))` with `products` loaded. No endpoint test found — contract code-traced.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.UPDATE_FLASH_SALE_SUCCESSFULLY` | `en` → `"Flash sale updated successfully"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | Refreshed `FlashSaleResource` (bilingual `title` object) | Same key set as the create/show responses, plus `products` (array — loaded) |

```json
{
  "status": 200,
  "message": "Flash sale updated successfully",
  "success": true,
  "data": {
    "id": 1,
    "title": { "ar": "تخفيضات الشتاء", "en": "Winter Sale" },
    "slug": "winter-sale",
    "image": { "desktop": "", "mobile": "" },
    "description": "Up to 50% off",
    "start_date": "2026-01-01",
    "end_date": "2026-02-01",
    "status": false,
    "is_valid": false,
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
| 403 | Authenticated caller lacks `update-flash-sale` | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token without `update-flash-sale` (code-traced) | See 403 example |
| 422 | `UpdateFlashSaleRequest` fails (bad title/type/dates, `percentage` without cap, unknown `products.*`) | `{message: string, status: boolean, errors: object}` | None | Laravel default | Field → messages | Invalid body (code-traced) | See 422 example |
| 500 | Unknown `{flash_sale}` (miss → repository `HttpException(500, raw-key)` → escapes to `Handler` — NOT a 404; code-verified wart) OR any post-validation failure (failed image update, DB error) | `{message: string, status: boolean}` | None | `"ERROR.SOMETHING_WENT_WRONG"` (RAW untranslated key) | None | Unknown id with valid body, or post-validation failure | See 500 example |
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
  "message": "The type field must be one of percentage, fixed_rate, final_price.",
  "status": false,
  "errors": {
    "type": ["The type field must be one of percentage, fixed_rate, final_price."]
  }
}
```

500 example (unknown id OR post-validation failure — RAW key):

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
