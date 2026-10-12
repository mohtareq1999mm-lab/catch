# Endpoint Name

Promotions – Create Promotion (`POST /api/v1/promotions`)

# Description

Creates one promotion with conditional discount mechanics and linked products, returning 201 with the resource.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `POST` |
| Full path | `/api/v1/promotions` |
| Path composition | `api/v1` prefix + `promotions` from bare `Route::apiResource('promotions', ...)` (no route middleware; the in-controller `create-promotion` gate decides) in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group |
| Controller action | `Marvel\Http\Controllers\PromotionController@store` → `PromotionRepository::storePromotion`: whitelisted `dataArray` + slug, `discount`⇄`value` mirroring (each fills the other when absent — code-verified normalization), product/gift sync (gift variants verified against their product, missing `product_variant_id` migration column → explicit 400; code-verified), hyphenated image uploads; ANY failure rolls back and rethrows `MarvelBadRequestException(COULD_NOT_CREATE_THE_RESOURCE)` — an `HttpException`, NOT a `MarvelException`, so it ESCAPES the controller's `catch (MarvelException)` into `Handler` → 400 with the RAW key (code-verified wart: the translated catch-block 400 is dead) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `create-promotion` via controller constructor (applies only to `store`); `PromotionRequest::authorize` returns `true` |
| Required headers | `Authorization: Bearer <sanctum-token>`, `Content-Type: multipart/form-data` when sending images (`application/json` otherwise) |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en\|ar` (default `en`) |
| Supported request formats | `application/json` or `multipart/form-data` (images optional — `hasFile` guards) |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `promotions.store` (apiResource default naming) |

Middleware runs before validation: 401/403 precede 400/422.

## Request inputs

`PromotionRequest` runs before the body. `type` has TWO values (`price`, `quantity`); `type_amount` has THREE (`fixed_rate`, `percentage`, `gift`). The conditional web below is all code-verified from the request class.

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | In `['en', 'ar']` | `en`, `ar` | `en` | None | `en` | Selects message locale |
| `name` | body | object | Required | No | `required\|array` | Object with locales | None | None | `{"en": "Promo A"}` | Localized names |
| `name.*` | body | string | Required with `name` | No | `required_with:name` + `UniqueTranslationRule::for('promotions', 'name')` (vendor text **Unverified in source**) | Unique strings | None | None | `"Promo A"` | One locale's name (slug derives from it) |
| `image-desktop` | body (file) | file (image) | Required | No | `required\|image\|mimes:jpeg,png,jpg,webp,gif` (REQUIRED on create; no size cap — code-verified) | jpeg/png/jpg/webp/gif | None | None | `desktop.jpg` | Desktop image (hyphenated key) |
| `image-mobile` | body (file) | file (image) | Required | No | Same `required` rule set | jpeg/png/jpg/webp/gif | None | None | `mobile.jpg` | Mobile image (hyphenated key) |
| `type` | body | string | Required | No | `required\|Rule::in(price, quantity)` | `price`, `quantity` | None | None | `"price"` | Promotion mechanics |
| `type_amount` | body | string | Required | No | `required\|Rule::in(fixed_rate, percentage, gift)` | `fixed_rate`, `percentage`, `gift` | None | None | `"fixed_rate"` | Amount mechanics |
| `apply_to` | body | string | Required | No | `required\|Rule::in(all_products, specific_products)` | `all_products`, `specific_products` | None | None | `"all_products"` | Product scope |
| `product_ids` | body | array of integers | Conditionally required/prohibited | No | REQUIRED when `apply_to` is `specific_products`; PROHIBITED when `all_products` (sending ids with `all_products` 422s — code-verified); `array` | Product id arrays | None | See rule | `[3]` | Scoped products |
| `product_ids.*` | body | integer | Required per element | No | `exists:products,id` | Existing product ids | None | None | `3` | One product id |
| `discount` | body | number | Conditionally required | No | `numeric\|min:0` + required UNLESS (`type` is `quantity` AND gifts present — the only exemption; code-verified) | Number ≥ 0 | None (mirrored into `value` when absent and vice versa) | See rule | `10` | Discount value |
| `max_discount_amount` | body | number | Conditionally required | No | `required_if:type_amount,percentage\|numeric\|min:1` | Number ≥ 1 | None | Required iff `type_amount` is `percentage` | `100` | Cap on percentage discounts |
| `required_quantity_type` | body | integer | Conditionally required | No | `integer\|min:1` + required when `type` is `quantity` | Integer ≥ 1 | None | Required iff `type` is `quantity` | `2` | Quantity threshold |
| `minimum_order_amount` | body | number | Conditionally required | No | `numeric\|min:0` + required when `type` is NOT `quantity` | Number ≥ 0 | None | Required iff `type` ≠ `quantity` | `0` | Order floor |
| `gift_products` | body | array of objects | Conditionally required | No | `required_if:type_amount,gift\|array\|min:1` | Non-empty array | None | Required iff `type_amount` is `gift` | `[{"product_id": 3}]` | Gift lines |
| `gift_products.*.product_id` | body | integer | Required with `gift_products` | No | `required_with:gift_products\|exists:products,id` | Existing product ids | None | None | `3` | Gifted product |
| `gift_products.*.product_variant_id` | body | integer | Optional | Yes | `nullable\|exists:product_variants,id` (+ runtime belonging check vs `product_id` → 400 RAW on mismatch) | Variant of the same product | `null` (quantity-1, no variant) | None | `9` | Gifted variant |
| `gift_products.*.quantity` | body | integer | Optional | No | `sometimes\|integer\|min:1` (clamped to ≥ 1 at sync — `max(1, ...)`; code-verified) | Integer ≥ 1 | `1` | None | `1` | Gift quantity |
| `limiter` | body | integer | Optional | No | `sometimes\|integer\|min:1` | Integer ≥ 1 | None (unlimited) | None | `100` | Global redemption cap |
| `start_at` | body | date | Optional | No | `sometimes\|date` | Any date | None | None | `"2026-01-01"` | Validity start |
| `end_at` | body | date | Optional | No | `sometimes\|date\|after_or_equal:start_at` | Date ≥ start | None | None | `"2026-02-01"` | Validity end |
| `status` | body | string/integer | Optional | No | `sometimes\|in:1,0` | `1`, `0` | Model default | None | `1` | Enabled flag |

No path or query parameters. (The request's `code` is request-side IGNORED — `dataArray` has no `code`; the model/test path sets codes directly. Sending `code` changes nothing — code-verified.)

## Request examples

```json
{
  "method": "POST",
  "url": "/api/v1/promotions",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json",
    "lang": "en"
  },
  "body": {
    "name": { "en": "Promo A" },
    "type": "price",
    "type_amount": "fixed_rate",
    "apply_to": "all_products",
    "discount": 10,
    "minimum_order_amount": 0
  }
}
```

# Responses

## Success: promotion created — HTTP 201

Returned via `ApiResponse::apiResponse(CREATED_PROMOTION_SUCCESSFULLY, 201, true, PromotionResource::make($promotion))` (relations NOT pre-loaded — `products`/`gift_products` omitted unless the sync loaded them; code-verified). Route is NOT `promotions.index`, so `name` is the RAW stored value (`getRawOriginal` — all locales as stored, NOT the `{ar,en}` object pattern; code-verified distinction).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `201` on success (unlike flash-sale store's 200 — code-verified) |
| `message` | string | Always present | Translated `MESSAGE.CREATED_PROMOTION_SUCCESSFULLY` | `en` → `"Promotion created successfully"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | `PromotionResource` | `id`, `name` (raw), `slug`, `type` (localized label), `discount_type` (raw `type_amount`), `value`, `discount`, `code`, `minimum_order_amount`, `required_quantity`, `apply_to`, `image{desktop, mobile}`, `start_at`/`end_at` (ISO/null), `status`, `is_valid`, `created_at` |

```json
{
  "status": 201,
  "message": "Promotion created successfully",
  "success": true,
  "data": {
    "id": 1,
    "name": "{\"en\": \"Promo A\"}",
    "slug": "promo-a",
    "type": "Price",
    "discount_type": "fixed_rate",
    "value": 10,
    "discount": 10,
    "code": null,
    "minimum_order_amount": 0.0,
    "required_quantity": null,
    "apply_to": "all_products",
    "image": { "desktop": null, "mobile": null },
    "start_at": null,
    "end_at": null,
    "status": true,
    "is_valid": true,
    "created_at": "2026-01-01T00:00:00+00:00"
  }
}
```

# Errors

No error-code field; every error below has Error code `None`.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | No valid token (verified by `test_unauthenticated_user_cannot_create_promotion` → 401, `postJson`) | See 401 example |
| 403 | Authenticated caller lacks `create-promotion` | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token without `create-promotion` (code-traced) | See 403 example |
| 400 | Repository failure AFTER validation — image upload failure (`'Image upload failed'`), gift-variant mismatch (`'Gift variant does not belong to the selected product.'`), missing variant migration (`'Gift variants require a migration.'`), or any DB error (all escape as `HttpException` → RAW messages; the translated catch-block 400 is dead) | `{message: string, status: boolean}` | None | RAW live message (`'Image upload failed'`, etc.) or RAW `'ERROR.COULD_NOT_CREATE_THE_RESOURCE'` for the generic path | None | Post-validation failure (code-traced; exact text varies by cause) | See 400 example |
| 422 | `PromotionRequest` fails (missing name/images/type/apply_to, `product_ids` with `all_products`, missing conditional fields, unknown product ids) | `{message: string, status: boolean, errors: object}` | None | Laravel default | Field → messages | Empty body (verified by `test_create_validation_fails_without_required_fields` → 422) | See 422 example |
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

400 example (post-validation failure — RAW key/text):

```json
{
  "message": "ERROR.COULD_NOT_CREATE_THE_RESOURCE",
  "status": false
}
```

422 example (empty body):

```json
{
  "message": "The name field is required. (and 5 more errors)",
  "status": false,
  "errors": {
    "name": ["The name field is required."],
    "image-desktop": ["The image-desktop field is required."]
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
