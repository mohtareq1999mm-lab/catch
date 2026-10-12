# Endpoint Name

Coupons – Create Coupon (`POST /api/v1/coupons`)

# Description

Creates one coupon with a server-generated code and hyphenated images, returning 201 with the resource.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `POST` |
| Full path | `/api/v1/coupons` |
| Path composition | `api/v1` prefix + `coupons` from bare `Route::apiResource('coupons', ...)` (no route middleware — route-inventory confirms group middleware only; the in-controller `create-coupon` gate decides) in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group |
| Controller action | `Marvel\Http\Controllers\CouponController@store` → `CouponRepository::storeCoupon` in a DB transaction under the CP-11 whitelist (`$dataArray`: name/slug/discount/discount_type/border_*/dates/limiter/status/max_discount/is_public — `code` and `used` NEVER pass via input; the redeemable code is always server-generated `coupon_XXXXXXX`-style and normalized, the slug sanitized/auto-suffixed; model `saving` additionally enforces business constraints fail-closed); hyphenated image uploads; ANY failure rolls back and rethrows `MarvelBadRequestException(COULD_NOT_CREATE_THE_RESOURCE)` — which is an `HttpException`, NOT a `MarvelException`, so it ESCAPES the controller's `catch (MarvelException)` into `Handler`'s `HttpException` branch → 400 with the RAW key (code-verified wart: the translated catch-block 400 is dead) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `create-coupon` via controller constructor (applies only to `store`); `CouponRequest::authorize` returns `true` |
| Required headers | `Authorization: Bearer <sanctum-token>`, `Content-Type: multipart/form-data` when sending images (`application/json` otherwise) |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en\|ar` (default `en`) |
| Supported request formats | `application/json` or `multipart/form-data` (images are OPTIONAL here, unlike flash-sale — `hasFile` guards) |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `coupons.store` (apiResource default naming) |

Middleware runs before validation: 401/403 precede 422/400.

## Request inputs

`CouponRequest` runs before the body. There is deliberately NO `code` rule (server-generated) and NO `used` rule (system-controlled) — sending them is silently ignored via `only(dataArray)`, not rejected (proven by `store_coupon_strips_system_managed_fields`, cited in-model). `type` allows THREE values (`percentage`, `fixed_rate`, `free_shipping` per `DiscountType` — note `free_shipping` exists here but NOT in `FlashSaleType`; code-verified).

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | In `['en', 'ar']` | `en`, `ar` | `en` | None | `en` | Selects message locale |
| `name` | body | object | Required | No | `required\|array` | Object with locales | None | None | `{"en": "Summer Sale", "ar": "تخفيضات الصيف"}` | Localized names |
| `name.*` | body | string | Required with `name` | No | `required_with:name` + `UniqueTranslationRule::for('coupons', 'name')` (vendor text **Unverified in source**) | Unique strings | None | None | `"Summer Sale"` | One locale's name |
| `image-desktop` | body (file) | file (image) | Required | No | `required\|image\|mimes:jpeg,png,jpg,webp,gif` (REQUIRED on create — code-verified; `hasFile` guards in the repository are belt-and-braces) | jpeg/png/jpg/webp/gif | None | None | `desktop.jpg` | Desktop image (hyphenated key) |
| `image-mobile` | body (file) | file (image) | Required | No | Same `required` rule set as `image-desktop` | jpeg/png/jpg/webp/gif | None | None | `mobile.jpg` | Mobile image (hyphenated key) |
| `border_color` | body | string | Optional | Yes | `nullable\|string\|max:50` | ≤ 50 chars | `null` | None | `"#FF0000"` | Border color |
| `borderless` | body | string/integer | Optional | No | `sometimes\|in:1,0` | `1`, `0` | Model default | None | `0` | Borderless flag |
| `discount` | body | number | Required | No | `required\|numeric\|min:0` | Number ≥ 0 | None | None | `50` | Discount value |
| `discount_type` | body | string | Required | No | `required\|Rule::in(percentage, fixed_rate, free_shipping)` | `percentage`, `fixed_rate`, `free_shipping` | None | None | `"percentage"` | Discount mechanics |
| `max_discount_amount` | body | number | Conditionally required | No | `required_if:discount_type,percentage\|numeric\|min:1` | Number ≥ 1 | None | Required iff `discount_type` is `percentage` | `100` | Cap on percentage discounts |
| `start_date` | body | date string | Required | No | `required\|date_format:Y-m-d` (STRICT format — datetimes rejected) | `Y-m-d` | None | None | `"2026-01-01"` | Validity start |
| `end_date` | body | date string | Required | No | `required\|date_format:Y-m-d\|after_or_equal:start_date` | `Y-m-d` ≥ start | None | None | `"2026-02-01"` | Validity end |
| `limiter` | body | integer | Optional | Yes | `nullable\|integer\|min:0` | Integer ≥ 0 | `null` (unlimited) | None | `100` | Global redemption cap |
| `status` | body | string/integer | Optional | No | `sometimes\|in:1,0` | `1`, `0` | Model default | None | `1` | Enabled flag |
| `is_public` | body | boolean | Optional | No | `sometimes\|boolean` (independent discoverability flag, defaults false — assignments never flip it implicitly) | `true`, `false`, `0`, `1` | `false` | None | `false` | Public-visibility flag |

No path or query parameters. No endpoint test found — contracts code-traced.

## Request examples

```json
{
  "method": "POST",
  "url": "/api/v1/coupons",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json",
    "lang": "en"
  },
  "body": {
    "name": { "en": "Summer Sale", "ar": "تخفيضات الصيف" },
    "discount": 50,
    "discount_type": "percentage",
    "max_discount_amount": 100,
    "start_date": "2026-01-01",
    "end_date": "2026-02-01",
    "limiter": 100,
    "status": 1
  }
}
```

# Responses

## Success: coupon created — HTTP 201

Returned via `ApiResponse::apiResponse(CREATED_COUPON_SUCCESSFULLY, 201, true, CouponResource::make($coupon))`. Relations NOT pre-loaded here (the resource lazy-loads `targeting`/`assignments` — works, with extra queries). Route is NOT `coupons.show`, so `name` is the locale STRING.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `201` on success (unlike flash-sale/banners/currencies stores — code-verified 201 here) |
| `message` | string | Always present | Translated `MESSAGE.CREATED_COUPON_SUCCESSFULLY` | `en` → `"Coupon created successfully"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | `CouponResource` (server-generated `code`, e.g. `COUPON_X7K2P9Q`) | Same key set as the list item |

```json
{
  "status": 201,
  "message": "Coupon created successfully",
  "success": true,
  "data": {
    "id": 1,
    "code": "COUPON_X7K2P9Q",
    "name": "Summer Sale",
    "image": { "desktop": null, "mobile": null },
    "borderColor": null,
    "borderless": false,
    "discount": "50.00",
    "discount_type": "Percentage discount",
    "max_discount_amount": 100.0,
    "start_date": "2026-01-01",
    "end_date": "2026-02-01",
    "limiter": 100,
    "used": 0,
    "status": true,
    "is_valid": true,
    "audience": {},
    "is_assigned": false,
    "audience_type": "PUBLIC",
    "targeting_mode": null,
    "targeting": null,
    "assignments": [],
    "created_at": "2026-01-01T00:00:00+00:00"
  }
}
```

# Errors

No error-code field; every error below has Error code `None`.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | No valid token (code-traced) | See 401 example |
| 403 | Authenticated caller lacks `create-coupon` | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token without `create-coupon` (code-traced) | See 403 example |
| 400 | Repository/transaction failure AFTER validation (image upload failure, business-constraint violation, DB error) — escapes as `HttpException(400, raw-key)` → RAW key (the translated catch-block 400 is dead; `MarvelBadRequestException extends HttpException`, NOT `MarvelException` — verified in source) | `{message: string, status: boolean}` | None | `"ERROR.COULD_NOT_CREATE_THE_RESOURCE"` (RAW untranslated key — `Handler` does not translate `HttpException` messages) | None | Post-validation failure | See 400 example |
| 422 | `CouponRequest` fails (missing name/images/dates/discount/type, bad `discount_type`, `end_date` < `start_date`, missing cap for percentage) | `{message: string, status: boolean, errors: object}` | None | Laravel default | Field → messages | Invalid body (code-traced) | See 422 example |
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

400 example (post-validation failure — RAW key):

```json
{
  "message": "ERROR.COULD_NOT_CREATE_THE_RESOURCE",
  "status": false
}
```

422 example:

```json
{
  "message": "The discount type field is required. (and 1 more error)",
  "status": false,
  "errors": {
    "discount_type": ["The discount type field is required."],
    "discount": ["The discount field is required."]
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
