# Endpoint Name

Promotions – Update Promotion (`PUT /api/v1/promotions/{promotion}`)

# Description

Partially updates one promotion by id with mirrored discount fields and returns 200.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `PUT` (apiResource also registers `PATCH` → same action; tests use `putJson`) |
| Full path | `/api/v1/promotions/{promotion}` |
| Path composition | `api/v1` prefix + `promotions/{promotion}` from bare `Route::apiResource('promotions', ...)` (no route middleware; the in-controller `update-promotion` gate decides) in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group |
| Controller action | `Marvel\Http\Controllers\PromotionController@update(UpdatePromotionRequest $request, $id)` → `PromotionRepository::updatePromotion`: `find` miss → `MarvelBadRequestException(COULD_NOT_UPDATE_THE_RESOURCE)` rethrown VERBATIM (the `catch (MarvelBadRequestException) { throw $e; }` passthrough — code-verified) → escapes the `MarvelException`-only controller catch (`MarvelBadRequestException extends HttpException`) into `Handler` → 400 with the RAW key (code-verified wart: updating an unknown id yields 400, NOT 404); other failures → same RAW 400; slug recomputed, discount⇄value mirrored, products/gifts re-synced (absent keys leave links untouched; present-empty `product_ids` detaches — same sync semantics as flash-sale) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `update-promotion` via controller constructor (applies only to `update`); `UpdatePromotionRequest::authorize` returns `true` |
| Required headers | `Authorization: Bearer <sanctum-token>`, `Content-Type: application/json` (or `multipart/form-data` when replacing images) |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en\|ar` (default `en`) |
| Supported request formats | `application/json` for field-only updates; `multipart/form-data` when sending images |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `promotions.update` (apiResource default naming) |

Middleware runs before validation: 401/403 precede 400/422. Validation precedes the lookup, so 422 precedes 400 when both apply. `UniqueTranslationRule::ignore($this->route('promotion'))` matches the `{promotion}` placeholder (code-verified).

## Request inputs

Update relaxations vs create (all code-verified): images → `sometimes`; `product_ids` loses its `requiredIf` (only the `all_products` prohibition stays); a NEW `gift_product_ids` id-array alternative appears; `max_discount_amount`/`required_quantity_type` drop their `requiredIf` (plain `sometimes`); `apply_to`/`limiter`/dates become `nullable`.

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | In `['en', 'ar']` | `en`, `ar` | `en` | None | `en` | Selects message locale |
| `promotion` | path | mixed id | Required | No | No route constraint; passed to repository `find` | Existing promotion id | None | None | `1` | Identifies the promotion |
| `name` | body | object | Optional | No | `sometimes\|array` | Object with locales | Unchanged | None | `{"en": "Updated Name"}` | Localized names wrapper |
| `name.*` | body | string | Optional | No | `required_with:name` + unique-ignore-self (vendor text **Unverified in source**) | Unique strings | Unchanged | None | `"Updated Name"` | One locale's name (recomputes slug) |
| `image-desktop` | body (file) | file (image) | Optional | No | `sometimes\|image\|mimes:jpeg,png,jpg,webp,gif` (absent = image kept) | jpeg/png/jpg/webp/gif | Unchanged | None | `desktop2.jpg` | Replacement desktop image |
| `image-mobile` | body (file) | file (image) | Optional | No | Same rule set as `image-desktop` | jpeg/png/jpg/webp/gif | Unchanged | None | `mobile2.jpg` | Replacement mobile image |
| `type` | body | string | Optional | No | `sometimes\|Rule::in(price, quantity)` | `price`, `quantity` | Unchanged | None | `"price"` | Promotion mechanics |
| `type_amount` | body | string | Optional | No | `sometimes\|Rule::in(fixed_rate, percentage, gift)` | `fixed_rate`, `percentage`, `gift` | Unchanged | None | `"fixed_rate"` | Amount mechanics |
| `apply_to` | body | string | Optional | Yes | `nullable\|Rule::in(all_products, specific_products)` | The two scopes or null | Unchanged | None | `"all_products"` | Product scope |
| `product_ids` | body | array of integers | Optional | No | `sometimes\|array` + prohibited when request `apply_to` is `all_products` (the create-time `requiredIf` is GONE — code-verified relaxation) | Product id arrays | Unchanged | None | `[3]` | Replacement scoped products |
| `product_ids.*` | body | integer | Optional | No | `exists:products,id` | Existing product ids | None | None | `3` | One product id |
| `gift_product_ids` | body | array of integers | Optional | No | `sometimes\|array\|min:1` (UPDATE-ONLY alternative to `gift_products` — absent on create; synced with quantity 1, no variant; code-verified) | Non-empty id arrays | Unchanged | None | `[5]` | Replacement gift ids (simple form) |
| `gift_products` | body | array of objects | Optional | No | `sometimes\|array\|min:1` (no `required_if` on update — code-verified relaxation) | Gift line arrays | Unchanged | None | `[{"product_id": 3}]` | Replacement gift lines (variant form) |
| `discount` / `value` | body | number | Optional | No | `sometimes\|numeric\|min:0` (+ create-style `requiredIf` exemption only when `type` is `quantity` WITH gifts in the SAME request — `value` is NOT a validated field but IS persisted via mirroring; code-verified) | Numbers ≥ 0 | Unchanged (mirrored when one side sent) | See rule | `25` | Discount value |
| `max_discount_amount` | body | number | Optional | No | `sometimes\|numeric\|min:1` (no `required_if` on update) | Number ≥ 1 | Unchanged | None | `80` | Cap on percentage discounts |
| `required_quantity_type` | body | integer | Optional | No | `sometimes\|integer\|min:1` (no `requiredIf` on update) | Integer ≥ 1 | Unchanged | None | `2` | Quantity threshold |
| `minimum_order_amount` | body | number | Optional | No | `sometimes\|numeric\|min:0` + required when request `type` is present and NOT `quantity` | Number ≥ 0 | Unchanged | See rule | `0` | Order floor |
| `limiter` | body | integer | Optional | Yes | `nullable\|integer\|min:1` | Integer ≥ 1 or null | Unchanged | None | `100` | Global redemption cap |
| `start_at` / `end_at` | body | date | Optional | Yes | `nullable\|date` (+ `after_or_equal:start_at` on `end_at`, compared against the REQUEST's start — code-verified edge shared with coupons/flash-sale) | Dates or null | Unchanged | None | `"2026-03-01"` | Validity window |
| `status` | body | string/integer | Optional | No | `sometimes\|in:1,0` | `1`, `0` | Unchanged | None | `0` | Enabled flag |

## Request examples

Field-only update (test-verified call — note `name` as an object and the mirrored `value`/`discount` pair):

```json
{
  "method": "PUT",
  "url": "/api/v1/promotions/1",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json",
    "lang": "en"
  },
  "body": {
    "name": { "en": "Updated Name" },
    "value": 25,
    "discount": 25
  }
}
```

# Responses

## Success: promotion updated — HTTP 200

Returned via `ApiResponse::apiResponse(UPDATED_PROMOTION_SUCCESSFULLY, 200, true, PromotionResource::make($promotion))` (relations not pre-loaded). Verified by `test_admin_can_update_promotion` (`assertOk`, DB `value: 25`, `putJson`).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.UPDATED_PROMOTION_SUCCESSFULLY` | `en` → `"Promotion updated successfully"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | Refreshed `PromotionResource`, RAW `name` | Same key set as create |

```json
{
  "status": 200,
  "message": "Promotion updated successfully",
  "success": true,
  "data": {
    "id": 1,
    "name": "{\"en\": \"Updated Name\"}",
    "slug": "updated-name",
    "type": "Price",
    "discount_type": "fixed_rate",
    "value": 25,
    "discount": 25,
    "code": "A-ABC123",
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
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | No valid token (code-traced; no promotion guest-update test found) | See 401 example |
| 403 | Authenticated caller lacks `update-promotion` | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token without `update-promotion` (code-traced) | See 403 example |
| 400 | Unknown `{promotion}` (miss → verbatim rethrow → escapes to `Handler` — NOT a 404; code-verified wart) OR any post-validation failure (image/variant/migration/DB — RAW live text or RAW key by cause) | `{message: string, status: boolean}` | None | RAW `'ERROR.COULD_NOT_UPDATE_THE_RESOURCE'` for miss/generic path; RAW live text (`'Image upload failed'`, etc.) for specific causes | None | Unknown id with valid body, or post-validation failure | See 400 example |
| 422 | `UpdatePromotionRequest` fails (bad `type`/`type_amount`, `product_ids` with `all_products`) | `{message: string, status: boolean, errors: object}` | None | Laravel default | Field → messages | Invalid `type` + `type_amount` (verified by `test_update_validation_fails_with_invalid_type` → 422) | See 422 example |
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

400 example (unknown id OR post-validation failure — RAW):

```json
{
  "message": "ERROR.COULD_NOT_UPDATE_THE_RESOURCE",
  "status": false
}
```

422 example:

```json
{
  "message": "The selected type is invalid. (and 1 more error)",
  "status": false,
  "errors": {
    "type": ["The selected type is invalid."],
    "type_amount": ["The selected type amount is invalid."]
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
