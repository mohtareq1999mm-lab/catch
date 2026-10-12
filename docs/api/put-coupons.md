# Endpoint Name

Coupons – Update Coupon (`PUT /api/v1/coupons/{coupon}`)

# Description

Partially updates one coupon by id under the repository whitelist and returns 200.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `PUT` (apiResource also registers `PATCH` → same action) |
| Full path | `/api/v1/coupons/{coupon}` |
| Path composition | `api/v1` prefix + `coupons/{coupon}` from bare `Route::apiResource('coupons', ...)` (no route middleware; the in-controller `update-coupon` gate decides) in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group |
| Controller action | `Marvel\Http\Controllers\CouponController@update(UpdateCouponRequest $request, $id)` → `CouponRepository::updateCoupon` in a DB transaction under the same CP-11 whitelist (only `dataArray` fields persist — `code`/`used` changes silently ignored, not rejected); miss (`find` → null) throws `MarvelBadRequestException(COULD_NOT_UPDATE_THE_RESOURCE)` — an `HttpException`, NOT a `MarvelException`, so it ESCAPES the controller's `catch (MarvelException)` into `Handler` → 400 with the RAW key (code-verified wart: updating an unknown id yields 400, NOT 404, and the translated catch-block 400 is dead) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `update-coupon` via controller constructor (applies only to `update`); `UpdateCouponRequest::authorize` returns `true` |
| Required headers | `Authorization: Bearer <sanctum-token>`, `Content-Type: application/json` (or `multipart/form-data` when replacing images) |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en\|ar` (default `en`) |
| Supported request formats | `application/json` for field-only updates; `multipart/form-data` when sending `image-desktop`/`image-mobile` |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `coupons.update` (apiResource default naming) |

Middleware runs before validation: 401/403 precede 400/422. Validation precedes the lookup, so 422 precedes 400 when both apply. `UniqueTranslationRule::ignore($this->route('coupon'))` matches the `{coupon}` placeholder, so keeping the coupon's own name passes (code-verified).

## Request inputs

`code` and `used` are NOT rules (immutable post-create — silently ignored). `max_discount_amount` keeps `required_if:discount_type,percentage` even on update (same strictness as create).

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | In `['en', 'ar']` | `en`, `ar` | `en` | None | `en` | Selects message locale |
| `coupon` | path | mixed id | Required | No | No route constraint; passed to repository `find` (numeric id expected — code lookup NOT supported here, unlike show; code-verified asymmetry) | Existing coupon id | None | None | `1` | Identifies the coupon |
| `name` | body | object | Optional | No | `sometimes\|array` | Object with locales | Unchanged | None | `{"en": "Winter Sale"}` | Localized names wrapper |
| `name.*` | body | string | Optional | No | `required_with:name` + unique-ignore-self (vendor text **Unverified in source**) | Unique strings | Unchanged | None | `"Winter Sale"` | One locale's name |
| `image-desktop` | body (file) | file (image) | Optional | No | `sometimes\|image\|mimes:jpeg,png,jpg,webp,gif` (absent = image kept) | jpeg/png/jpg/webp/gif | Unchanged | None | `desktop2.jpg` | Replacement desktop image |
| `image-mobile` | body (file) | file (image) | Optional | No | Same rule set as `image-desktop` | jpeg/png/jpg/webp/gif | Unchanged | None | `mobile2.jpg` | Replacement mobile image |
| `border_color` | body | string | Optional | Yes | `nullable\|string\|max:50` | ≤ 50 chars | Unchanged | None | `"#00FF00"` | Border color |
| `borderless` | body | string/integer | Optional | No | `sometimes\|in:1,0` | `1`, `0` | Unchanged | None | `1` | Borderless flag |
| `discount` | body | number | Optional | No | `sometimes\|numeric\|min:0` | Number ≥ 0 | Unchanged | None | `40` | Discount value |
| `discount_type` | body | string | Optional | No | `sometimes\|Rule::in(percentage, fixed_rate, free_shipping)` | `percentage`, `fixed_rate`, `free_shipping` | Unchanged | None | `"percentage"` | Discount mechanics |
| `max_discount_amount` | body | number | Conditionally required | No | `required_if:discount_type,percentage\|numeric\|min:1` | Number ≥ 1 | Unchanged | Required iff request `discount_type` is `percentage` | `80` | Cap on percentage discounts |
| `start_date` | body | date string | Optional | No | `sometimes\|date_format:Y-m-d` | `Y-m-d` | Unchanged | None | `"2026-01-01"` | Validity start |
| `end_date` | body | date string | Optional | No | `sometimes\|date_format:Y-m-d\|after_or_equal:start_date` (compared against the REQUEST's `start_date`, not the stored one — code-verified edge shared with flash-sale) | `Y-m-d` | Unchanged | None | `"2026-03-01"` | Validity end |
| `limiter` | body | integer | Optional | Yes | `nullable\|integer\|min:0` | Integer ≥ 0 | Unchanged | None | `50` | Global redemption cap |
| `status` | body | string/integer | Optional | No | `sometimes\|in:1,0` | `1`, `0` | Unchanged | None | `0` | Enabled flag |
| `is_public` | body | boolean | Optional | No | `sometimes\|boolean` | `true`, `false`, `0`, `1` | Unchanged | None | `false` | Public-visibility flag |

No endpoint test found — contracts code-traced.

## Request examples

```json
{
  "method": "PUT",
  "url": "/api/v1/coupons/1",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json",
    "lang": "en"
  },
  "body": {
    "discount": 40,
    "status": 0
  }
}
```

# Responses

## Success: coupon updated — HTTP 200

Returned via `ApiResponse::apiResponse(UPDATED_COUPON_SUCCESSFULLY, 200, true, CouponResource::make($coupon))` (relations not pre-loaded — lazy-loaded by the resource).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.UPDATED_COUPON_SUCCESSFULLY` | `en` → `"Coupon updated successfully"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | Refreshed `CouponResource`, locale-string `name` | Same key set as the list item |

```json
{
  "status": 200,
  "message": "Coupon updated successfully",
  "success": true,
  "data": {
    "id": 1,
    "code": "COUPON_X7K2P9Q",
    "name": "Summer Sale",
    "image": { "desktop": null, "mobile": null },
    "borderColor": null,
    "borderless": false,
    "discount": "40.00",
    "discount_type": "Percentage discount",
    "max_discount_amount": 100.0,
    "start_date": "2026-01-01",
    "end_date": "2026-02-01",
    "limiter": 100,
    "used": 3,
    "status": false,
    "is_valid": false,
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
| 403 | Authenticated caller lacks `update-coupon` | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token without `update-coupon` (code-traced) | See 403 example |
| 400 | Unknown `{coupon}` (miss → `MarvelBadRequestException` → escapes to `Handler` — NOT a 404; code-verified wart) OR any post-validation failure (failed image update, DB error) | `{message: string, status: boolean}` | None | `"ERROR.COULD_NOT_UPDATE_THE_RESOURCE"` (RAW untranslated key) | None | Unknown id with valid body, or post-validation failure | See 400 example |
| 422 | `UpdateCouponRequest` fails (bad `discount_type`, `end_date` < `start_date`, `percentage` without cap) | `{message: string, status: boolean, errors: object}` | None | Laravel default | Field → messages | Invalid body (code-traced) | See 422 example |
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

400 example (unknown id OR post-validation failure — RAW key):

```json
{
  "message": "ERROR.COULD_NOT_UPDATE_THE_RESOURCE",
  "status": false
}
```

422 example:

```json
{
  "message": "The discount type field must be one of percentage, fixed_rate, free_shipping.",
  "status": false,
  "errors": {
    "discount_type": ["The discount type field must be one of percentage, fixed_rate, free_shipping."]
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
