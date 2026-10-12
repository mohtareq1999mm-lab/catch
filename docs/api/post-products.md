# Endpoint Name

Products – Create Product (`POST /api/v1/products`)

# Description

Creates one simple or variable product with relations and images, returning 201 with the resource.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `POST` |
| Full path | `/api/v1/products` |
| Path composition | `api/v1` prefix + `products` from `Route::apiResource('products', ...)` in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group, plus route-level ANY-of pipe middleware (constructor `create-product` gate decides) |
| Controller action | `Marvel\Http\Controllers\ProductController@store` → `ProductStore` helper → `ProductRepository::storeProduct` (relations, variants, media); `MarvelException` failures rewrap as `MarvelException(SOMETHING_WENT_WRONG)` and ESCAPE (no catch in `store` itself → `Handler`: no NOT_FOUND/NOT_AUTHORIZED marker → 500 translated — code-verified: store failures are 500s, and the live cause is swallowed); then `flushTag(PRODUCTS)` |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `create-product` via controller constructor (applies only to `store`); `ProductCreateRequest::authorize` returns `true` |
| Required headers | `Authorization: Bearer <sanctum-token>`, `Content-Type: multipart/form-data` (images are required files) |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en\|ar` (default `en`) |
| Supported request formats | `multipart/form-data` (test-compatible; images are `file` rules) |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `products.store` (apiResource default naming) |

Middleware runs before validation: 401/403 precede 422/500. Dimensions pre-cast to string (both levels) before validation.

## Request inputs

`ProductCreateRequest` runs before the body. Discount flags use `in:true,false,1,0` (string forms accepted — code-verified laxity vs strict `in:1,0` elsewhere). `price` is required ONLY for simple products.

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | In `['en', 'ar']` | `en`, `ar` | `en` | None | `en` | Selects message locale |
| `name` | body | object | Required | No | `required\|array` | Object with locales | None | None | `{"en": "Shirt"}` | Localized names |
| `name.*` | body | string | Required | No | `required\|string\|max:255` + `UniqueTranslationRule::for('products')` (vendor text **Unverified in source**) | String ≤ 255 chars, unique | None | None | `"Shirt"` | One locale's name |
| `description` | body | object | Required | No | `required\|array` | Object with locales | None | None | `{"en": "Cotton shirt"}` | Localized descriptions |
| `description.*` | body | string | Required | No | `required\|string\|max:10000` (NO uniqueness — code-verified) | String ≤ 10000 chars | None | None | `"Cotton shirt"` | One locale's description |
| `product_type` | body | string | Required | No | `required\|Rule::in(['simple', 'variable'])` (code-verified `ProductType` values) | `simple`, `variable` | None | None | `"simple"` | Product kind |
| `item_type` | body | string | Optional | No | `sometimes\|string\|Rule::in(['PHYSICAL', 'DIGITAL'])` (code-verified `ItemType` values) | `PHYSICAL`, `DIGITAL` | Model default | None | `"PHYSICAL"` | Fulfillment kind |
| `price` | body | number | Conditionally required | No | `sometimes\|numeric\|min:0` + `required_if:product_type,simple` | Number ≥ 0 | None | Required iff `product_type` is `simple` | `100` | Base price |
| `type_id` | body | integer | Optional | No | `sometimes\|integer\|exists:types,id` | Existing type id | None | None | `1` | Product type reference |
| `categories` | body | array of integers | Required | No | `required\|array` | Non-empty id arrays | None | None | `[2]` | Category links |
| `categories.*` | body | integer | Required per element | No | `integer\|exists:categories,id` | Existing category ids | None | None | `2` | One category id |
| `images` | body | array of files | Required | No | `required\|array` | Non-empty file arrays | None | None | `["shirt.jpg"]` | Product images |
| `images.*` | body (file) | file | Required per element | No | `required\|file\|mimes:jpeg,png,jpg,gif\|max:2048` | jpeg/png/jpg/gif ≤ 2 MB | None | None | `shirt.jpg` | One image |
| `quantity` | body | integer | Optional | No | `sometimes\|integer\|min:1` | Integer ≥ 1 | Model default | None | `10` | Stock quantity |
| `pieces` | body | integer | Optional | No | `sometimes\|integer\|min:1` | Integer ≥ 1 | Model default | None | `1` | Pieces count |
| `in_stock` | body | string/integer | Required | No | `required\|in:1,0` (STRICT — `true` fails; code-verified) | `1`, `0` | None | None | `1` | Stock flag |
| `has_discount` | body | string | Required | No | `required\|in:true,false,1,0` (string forms accepted) | `true`, `false`, `1`, `0` | None | None | `0` | Discount switch |
| `has_flash_sale` | body | string | Required | No | Same lax set as `has_discount` | `true`, `false`, `1`, `0` | None | None | `0` | Flash-sale switch |
| `flash_sale_id` | body | integer | Conditionally required | No | `required_if:has_flash_sale,1\|exists:flash_sales,id` | Existing flash-sale id | None | Required iff `has_flash_sale` is `1` | `4` | Linked flash sale |
| `discount_type` | body | string | Conditionally required | No | `required_if:has_discount,1\|Rule::in(['percentage', 'fixed_rate', 'free_shipping'])` (code-verified `DiscountType` values) | `percentage`, `fixed_rate`, `free_shipping` | None | Required iff `has_discount` is `1` | `"percentage"` | Discount kind |
| `discount_amount` | body | number | Conditionally required | No | `required_if:has_discount,1\|numeric\|min:1` | Number ≥ 1 | None | Required iff `has_discount` is `1` | `10` | Discount value |
| `discount_status` | body | string/integer | Conditionally required | No | `required_if:has_discount,1\|in:1,0` (STRICT on create — update relaxes to lax) | `1`, `0` | None | Required iff `has_discount` is `1` | `1` | Discount status |
| `status` | body | string/integer | Optional | No | `sometimes\|in:0,1` | `0`, `1` | Model default | None | `1` | Publish flag |
| `height` | body | string | Optional | Yes | `nullable\|string` (pre-cast to string when present and non-null) | Any string | `null` | None | `"10"` | Height dimension |
| `length` | body | string | Optional | Yes | `nullable\|string` (pre-cast to string) | Any string | `null` | None | `"30"` | Length dimension |
| `width` | body | string | Optional | Yes | `nullable\|string` (pre-cast to string) | Any string | `null` | None | `"20"` | Width dimension |
| `weight` | body | string | Optional | Yes | `nullable\|string` (pre-cast to string) | Any string | `null` | None | `"1.5"` | Weight |
| `is_fast_shipping_available` | body | boolean | Optional | Yes | `nullable\|boolean` | Booleans | Model default | None | `false` | Fast-shipping switch |
| `start_date` | body | string (date) | Optional | No | `sometimes\|date` | Valid date | None | None | `"2026-01-01"` | Discount/flash window start |
| `end_date` | body | string (date) | Optional | No | `sometimes\|date\|after_or_equal:start_date` | Valid date ≥ `start_date` | None | None | `"2026-02-01"` | Discount/flash window end |
| `brands` | body | array of integers | Optional | No | `sometimes\|array` | Id arrays | Absent | None | `[1]` | Brand links |
| `brands.*` | body | integer | Required per element | No | `integer\|exists:brands,id` | Existing brand ids | None | None | `1` | One brand id |
| `tags` | body | array of integers | Optional | Yes | `nullable\|array` | Id arrays or explicit `null` | Absent | None | `[3]` | Tag links |
| `tags.*` | body | integer | Required per element | No | `integer\|exists:tags,id` | Existing tag ids | None | None | `3` | One tag id |
| `banners` | body | array of integers | Optional | No | `sometimes\|array` | Id arrays | Absent | None | `[1]` | Banner links |
| `banners.*` | body | integer | Required per element | No | `integer\|exists:banners,id` | Existing banner ids | None | None | `1` | One banner id |
| `sliders` | body | array of integers | Optional | No | `sometimes\|array` | Id arrays | Absent | None | `[1]` | Slider links |
| `sliders.*` | body | integer | Required per element | No | `integer\|exists:sliders,id` | Existing slider ids | None | None | `1` | One slider id |
| `tax_enabled` | body | boolean | Optional | No | `sometimes\|boolean` | Booleans | Model default | None | `false` | Tax switch |
| `tax_rate` | body | number | Optional | Yes | `nullable\|numeric\|min:0\|max:100` | `0`–`100` | `null` | None | `15` | Tax percent |
| `variants` | body | array of objects | Optional | No | `sometimes\|array` (variable products) | Variant arrays | Absent | None | `[{"price": 100}]` | Variant rows |
| `variants.*.price` | body | number | Required with `variants` | No | `required_with:variants\|numeric\|min:0` | Number ≥ 0 | None | Required iff `variants` present | `100` | Variant price |
| `variants.*.quantity` | body | integer | Required with `variants` | No | `required_with:variants\|integer\|min:0` | Integer ≥ 0 | None | Required iff `variants` present | `5` | Variant quantity |
| `variants.*.attribute_values` | body | array of integers | Required with `variants` | No | `required_with:variants\|array` | Id arrays | None | Required iff `variants` present | `[9]` | Variant attribute links |
| `variants.*.attribute_values.*` | body | integer | Required per value | No | `integer\|exists:attribute_values,id` | Existing value ids | None | None | `9` | One attribute value |
| `variants.*.weight` | body | string | Optional | No | `sometimes\|string` (pre-cast to string) | Any string | None | None | `"0.5"` | Variant weight |
| `variants.*.length` | body | string | Optional | No | `sometimes\|string` (pre-cast to string) | Any string | None | None | `"5"` | Variant length |
| `variants.*.width` | body | string | Optional | No | `sometimes\|string` (pre-cast to string) | Any string | None | None | `"5"` | Variant width |
| `variants.*.height` | body | string | Optional | No | `sometimes\|string` (pre-cast to string) | Any string | None | None | `"5"` | Variant height |

The suite pins required-ness per family with hedged statuses (`[422, 403, 500]`): empty body, name-only, and name+description bodies each assert inclusion of 422 (`test_create_product_requires_name/description/categories`) — the 422 outcome is code-traced as the first applicable failure for a permitted admin.

## Request examples

Minimal valid request (required fields only, simple product):

```json
{
  "method": "POST",
  "url": "/api/v1/products",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "multipart/form-data",
    "lang": "en"
  },
  "body": {
    "name": { "en": "Shirt" },
    "description": { "en": "Cotton shirt" },
    "product_type": "simple",
    "price": 100,
    "categories": [2],
    "images": ["<file shirt.jpg>"],
    "in_stock": 1,
    "has_discount": 0,
    "has_flash_sale": 0
  }
}
```

Full request with every supported field (variable product with discount, flash sale, tax, relations, and variants — send as `multipart/form-data`; files shown as `<file ...>` markers):

```json
{
  "method": "POST",
  "url": "/api/v1/products",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "multipart/form-data",
    "lang": "en"
  },
  "body": {
    "name": { "en": "Shirt", "ar": "قميص" },
    "description": { "en": "Cotton shirt", "ar": "قميص قطني" },
    "product_type": "variable",
    "item_type": "PHYSICAL",
    "price": 100,
    "type_id": 1,
    "categories": [2],
    "quantity": 10,
    "pieces": 1,
    "status": 1,
    "height": "10",
    "length": "30",
    "width": "20",
    "weight": "1.5",
    "images": ["<file shirt.jpg>", "<file shirt-back.jpg>"],
    "in_stock": 1,
    "has_discount": 1,
    "has_flash_sale": 1,
    "is_fast_shipping_available": false,
    "flash_sale_id": 4,
    "discount_type": "percentage",
    "discount_amount": 10,
    "discount_status": 1,
    "start_date": "2026-01-01",
    "end_date": "2026-02-01",
    "brands": [1],
    "tags": [3],
    "banners": [1],
    "sliders": [1],
    "tax_enabled": true,
    "tax_rate": 15,
    "variants": [
      {
        "price": 100,
        "quantity": 5,
        "attribute_values": [9],
        "weight": "0.5",
        "length": "5",
        "width": "5",
        "height": "5"
      }
    ]
  }
}
```

# Responses

## Success: product created — HTTP 201

Returned via `ApiResponse::apiResponse(CREATE_PRODUCT_SUCCESSFULLY, 201, true, ProductResource::make($product))`. Route is NOT `products.index`, so `name`/`description` are RAW stored values.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `201` on success |
| `message` | string | Always present | Translated `MESSAGE.CREATE_PRODUCT_SUCCESSFULLY` | `en` → `"Product created successfully"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success (verified: `apiResponse(..., 201, true, ...)` — `true` is hardcoded on this path) |
| `data` | object | Always present on success | `ProductResource` (raw name/description because route is NOT `products.index`; `Resource` object is never `empty()`, so the `data` key is never omitted here) | Every key below |
| `data.id` | integer | Always present | Product id | e.g. `1` |
| `data.name` | string | Always present | Raw stored translations as JSON TEXT (verified: `getRawOriginal('name')` returns the DB JSON string, not an object — response carries an escaped string) | e.g. `"{\"en\": \"Shirt\", \"ar\": \"قميص\"}"` |
| `data.slug` | string | Always present | Product slug | e.g. `"shirt"` |
| `data.description` | string | Always present | Raw stored translations as JSON TEXT (same `getRawOriginal` mechanism as `name`) | e.g. `"{\"en\": \"Cotton shirt\"}"` |
| `data.price` | number | Always present (null possible) | Base price, rounded 2 decimals | e.g. `100.0` |
| `data.current_price` | number | Always present (null possible) | Pre-tax effective price, rounded | e.g. `100.0` |
| `data.tax_enabled` | boolean | Always present | Tax switch | `true` / `false` |
| `data.tax_rate` | number \| null | Always present (nullable) | Tax percent | e.g. `15.0` or `null` |
| `data.tax` | object | Always present | `ProductTaxPresenter::describe` output | Object |
| `data.price_including_tax` | number | Always present (null possible) | Tax-inclusive value, rounded | e.g. `115.0` |
| `data.price_after_discount` | number | Always present (null possible) | Post-discount price, rounded | e.g. `90.0` |
| `data.price_after_flash_sale` | number | Always present (null possible) | Post-flash-sale price, rounded | e.g. `null` |
| `data.discount_type` | string \| null | Always present (nullable) | Discount kind | `percentage`, `fixed_rate`, `free_shipping`, or `null` |
| `data.discount_amount` | number \| null | Always present (nullable) | Discount value | e.g. `10` or `null` |
| `data.start_date` | string \| null | Always present (nullable) | Window start | Date or `null` |
| `data.end_date` | string \| null | Always present (nullable) | Window end | Date or `null` |
| `data.sku` | string \| null | Always present (nullable) | SKU | e.g. `"SH-001"` |
| `data.stock_quantity` | integer \| null | Always present (nullable) | Stock on hand | e.g. `10` |
| `data.reserved_quantity` | integer | Always present | Reserved (defaults `0`) | e.g. `0` |
| `data.available_stock` | integer \| null | Always present (nullable) | Sellable units | e.g. `10` |
| `data.quantity` | integer \| null | Always present (nullable) | Quantity | e.g. `10` |
| `data.sold_quantity` | integer | Always present | Sold (defaults `0`) | e.g. `0` |
| `data.in_stock` | integer | Always present | Stock flag (no model cast — raw tinyint `0`/`1`) | `1`, `0` |
| `data.status` | integer | Always present | Publish flag (verified: `Product` casts `status` to `integer`; request only accepts `0`/`1`) | `1`, `0` |
| `data.product_type` | string | Always present | Product kind | `simple`, `variable` |
| `data.item_type` | string | Always present | Fulfillment kind | `PHYSICAL`, `DIGITAL` |
| `data.height` | string \| null | Always present (nullable) | Dimension (no cast — raw value) | e.g. `"10"` or `null` |
| `data.width` | string \| null | Always present (nullable) | Dimension (no cast — raw value) | e.g. `"20"` or `null` |
| `data.length` | string \| null | Always present (nullable) | Dimension (no cast — raw value) | e.g. `"30"` or `null` |
| `data.weight` | string \| null | Always present (nullable) | Weight (no cast — raw value) | e.g. `"1.5"` or `null` |
| `data.has_flash_sale` | boolean | Always present | Flash-sale switch (verified: `Product` casts to `boolean`) | `true` / `false` |
| `data.has_discount` | boolean | Always present | Discount switch (verified: `Product` casts to `boolean`) | `true` / `false` |
| `data.is_fast_shipping_available` | boolean | Always present | Fast-shipping flag | `true` / `false` |
| `data.discount_valid` | boolean | Conditionally present (only when `has_discount` truthy) | Discount window active | `true` / `false` |
| `data.tags` | array | Present when `tags` loaded | Tag resources | `[]` when loaded-empty |
| `data.brands` | array | Present when `brands` loaded | Brand resources | `[]` when loaded-empty |
| `data.banners` | array | Present when `banners` loaded | Banner resources | `[]` when loaded-empty |
| `data.sliders` | array | Present when `sliders` loaded | Slider resources | `[]` when loaded-empty |
| `data.created_at` | string \| null | Always present (nullable) | Creation timestamp | ISO-8601 or `null` |
| `data.categories` | array | Present when `categories` loaded | `[{id, name, slug}]` | `[]` when loaded-empty |
| `data.flash_sales` | array | Present when `flash_sales` loaded | Flash-sale resources | `[]` when loaded-empty |
| `data.reviews` | array | Present when `reviews` loaded | Review resources | `[]` when loaded-empty |
| `data.images` | array of strings | Always present (can be empty) | Media URLs | e.g. `["https://cdn.example/i.jpg"]` |
| `data.variants` | array | Present when `variations` loaded | Variant rows with `id/price/current_price/stock_quantity/reserved_quantity/available_stock/quantity/height/width/length/weight/attributes[{attribute_name,value}]` | `[]` for simple products |

```json
{
  "status": 201,
  "message": "Product created successfully",
  "success": true,
  "data": {
    "id": 1,
    "name": "{\"en\": \"Shirt\", \"ar\": \"قميص\"}",
    "slug": "shirt",
    "description": "{\"en\": \"Cotton shirt\"}",
    "price": 100.0,
    "current_price": 100.0,
    "tax_enabled": false,
    "tax_rate": null,
    "tax": {},
    "price_including_tax": 100.0,
    "price_after_discount": 100.0,
    "price_after_flash_sale": null,
    "discount_type": null,
    "discount_amount": null,
    "start_date": null,
    "end_date": null,
    "sku": "SH-001",
    "stock_quantity": 10,
    "reserved_quantity": 0,
    "available_stock": 10,
    "quantity": 10,
    "sold_quantity": 0,
    "in_stock": 1,
    "status": 1,
    "product_type": "simple",
    "item_type": "PHYSICAL",
    "height": null,
    "width": null,
    "length": null,
    "weight": null,
    "has_flash_sale": false,
    "has_discount": false,
    "is_fast_shipping_available": false,
    "created_at": "2026-01-01T00:00:00+00:00",
    "categories": [{ "id": 2, "name": "Men", "slug": "men" }],
    "flash_sales": [],
    "images": [],
    "variants": []
  }
}
```

# Errors

No error-code field; every error below has Error code `None`.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | No valid token (strict `assertEquals(401)` in `test_guest_cannot_create_product`, `postJson`) | See 401 example |
| 403 | Authenticated caller lacks `create-product` | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | View-only user (verified by `test_view_only_user_cannot_create` → 403) | See 403 example |
| 422 | `ProductCreateRequest` fails (missing name/description/categories/images/product_type/in_stock/flags, conditional discount/flash fields, bad relations) | `{message: string, status: boolean, errors: object}` | None | Laravel default | Field → messages | Invalid body (code-traced first-failure for permitted admins; suite hedges `[422, 403, 500]` across the requires-* tests) | See 422 example |
| 500 | Repository failure AFTER validation (`MarvelException` rewrapped → escapes to generic 500; live cause swallowed) | `{message: string, status: boolean}` | None | Translated `ERROR.SOMETHING_WENT_WRONG`: `en` → `"Something went wrong"` | None | Post-validation failure (code-traced; explicitly allowed by the requires-* hedges) | See 500 example |
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

422 example (empty body):

```json
{
  "message": "The name field is required. (and 5 more errors)",
  "status": false,
  "errors": {
    "name": ["The name field is required."],
    "description": ["The description field is required."]
  }
}
```

500 example (post-validation failure):

```json
{
  "message": "Something went wrong",
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
