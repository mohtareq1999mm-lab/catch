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
| Controller action | `Marvel\Http\Controllers\ProductController@store` → `ProductStore` helper → `ProductRepository::storeProduct` (relations, variants, media; ends with `syncProductPlacements` — SEE the placements section below); `MarvelException` failures rewrap as `MarvelException(SOMETHING_WENT_WRONG)` and ESCAPE (no catch in `store` itself → `Handler`: no NOT_FOUND/NOT_AUTHORIZED marker → 500 translated — code-verified: store failures are 500s, and the live cause is swallowed); NEW FLOW: placement `HttpException(422)`s hit the repository's own `catch (HttpException)` → transaction ROLLBACK → RETHROW (they bypass the 500 rewrap and reach `Handler` as raw 422 `{message, status:false}`); then `flushTag(PRODUCTS)` |
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
| `quantity` | body | integer | Optional | No | `sometimes\|integer\|min:1` | Integer ≥ 1 | Model default | With `locations[]`-equivalent placement inputs: `SUM(location quantities)` must equal this value (tolerance `0.0001`) or the request fails 422 AFTER validation (`post-products.md` placements section) | `10` | Product-level stock quantity (force-updated to the location sum when placements sync) |
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
| `locations` | body | array of objects | Optional | No | `sometimes\|array` | Placement entries | Absent (no product-level placements) | Ignored (skipped) for `variable` products that already ship `variants[]` — variable stock lives on variant rows | `[{"location_id": 3, "quantity": 10}]` | NEW-FLOW product-level WMS placements |
| `locations.*.location_id` | body | integer | Required with `locations` | No | `required_with:locations\|integer\|exists:locations,id` | Existing location ids | None | None | `3` | The stock location |
| `locations.*.quantity` | body | number | Required with `locations` | No | `required_with:locations\|numeric\|min:0` | Number ≥ 0 (negatives fail validation; sum mismatch fails in the repository) | None | None | `10` | Units placed at this location |
| `locations.*.warehouse_id` | body | integer | Optional | Yes | `nullable\|integer\|exists:warehouses,id` | Existing warehouse ids | Resolved SOLELY from the location row (explicit mismatch → 422) | None | `1` | Advisory binding check against the location's own warehouse |
| `location_id` | body | integer | Optional (LEGACY single-entry path) | No | NO FormRequest rule anymore — repo only reads it when present (`syncProductPlacements` single-entry branch); unknown location ids surface as 422 `"Location #N not found."` from the repository | Existing location id | None | Only used when `locations` is absent | `3` | Legacy single-placement input (kept for compatibility; under-validated by design of the new flow — flagged open item: `warehouse_id`-only no longer 422s) |
| `variants` | body | array of objects | Optional | No | `sometimes\|array` (variable products) | Variant arrays | Absent | None | `[{"price": 100}]` | Variant rows |
| `variants.*.price` | body | number | Required with `variants` | No | `required_with:variants\|numeric\|min:0` | Number ≥ 0 | None | Required iff `variants` present | `100` | Variant price |
| `variants.*.quantity` | body | integer | Optional (NEW FLOW — no longer `required_with:variants`) | No | `sometimes\|integer\|min:0` | Integer ≥ 0 | Derived | With `variants[].locations[]`: `SUM(location quantities)` must equal this value (tolerance `0.0001`) else 422 `"The sum of location quantities (S) must equal the total variant quantity (Q)."`; absent + locations → set from the location sum (code-verified `addVariants`) | `5` | Variant quantity (force-set from location sum when placements sync) |
| `variants.*.attribute_values` | body | array of integers | Required with `variants` | No | `required_with:variants\|array` | Id arrays | None | Required iff `variants` present | `[9]` | Variant attribute links |
| `variants.*.attribute_values.*` | body | integer | Required per value | No | `integer\|exists:attribute_values,id` | Existing value ids | None | None | `9` | One attribute value |
| `variants.*.weight` | body | string | Optional | No | `sometimes\|string` (pre-cast to string) | Any string | None | None | `"0.5"` | Variant weight |
| `variants.*.length` | body | string | Optional | No | `sometimes\|string` (pre-cast to string) | Any string | None | None | `"5"` | Variant length |
| `variants.*.width` | body | string | Optional | No | `sometimes\|string` (pre-cast to string) | Any string | None | None | `"5"` | Variant width |
| `variants.*.height` | body | string | Optional | No | `sometimes\|string` (pre-cast to string) | Any string | None | None | `"5"` | Variant height |
| `variants.*.locations` | body | array of objects | Optional | No | `sometimes\|array` | Placement entries per variant | Absent | Variant-level WMS placements (variable products) | `[{"location_id": 3, "quantity": 10}]` | NEW-FLOW per-variant stock placements |
| `variants.*.locations.*.location_id` | body | integer | Required with `variants.*.locations` | No | `required_with:variants.*.locations\|integer\|exists:locations,id` | Existing location ids | None | None | `3` | The stock location |
| `variants.*.locations.*.quantity` | body | number | Required with `variants.*.locations` | No | `required_with:variants.*.locations\|numeric\|min:0` | Number ≥ 0 | None | None | `10` | Units placed at this location for this variant |
| `variants.*.locations.*.warehouse_id` | body | integer | Optional | Yes | `nullable\|integer\|exists:warehouses,id` | Existing warehouse ids | Resolved from the location row (mismatch → 422) | None | `1` | Advisory binding check |

The suite pins required-ness per family with hedged statuses (`[422, 403, 500]`): empty body, name-only, and name+description bodies each assert inclusion of 422 (`test_create_product_requires_name/description/categories`) — the 422 outcome is code-traced as the first applicable failure for a permitted admin.

## Placements synchronization (NEW FLOW — after validation, inside the create transaction)

Runs at the END of `storeProduct` via `syncProductPlacements` (product level) and, per variant, `syncVariantPlacements`. All failures are `HttpException(422)` → transaction rollback → raw 422 envelope (NO `errors` map), NOT the legacy 500 rewrap. Evidence: code-verified `ProductRepository` (working tree) + test-verified `tests/Feature/Wms/WmsMultiLocationTest.php`.

1. **Scope guard**: variable products WITH variants skip product-level placements entirely (variant rows own the stock); variant rows with `locations[]` sync per variant.
2. **Sum invariant**: `SUM(location quantities)` must equal the declared total (`quantity` for products / `variants[].quantity` for variants; tolerance `0.0001`) → else 422 `"The sum of location quantities (25) must equal the total product quantity (30)."` (variant message: `... the total variant quantity (30).`).
3. **Stock centralization**: product `stock_quantity`/`in_stock` = location sum; variant `quantity`/`stock_quantity`/`in_stock` = its location sum (absent `quantity` with locations → derived); product total = `SUM(variant stock)` force-saved (test-verified `55 = 25 + 30`).
4. **Stale-place overwrite guard**: a placement NOT in the new set is deleted ONLY when it has no open picking task (`pending|assigned|picking`) AND no allocated stock (`allocated_hint` > 0 or active `FulfillmentItem`s) → otherwise 422 `"Cannot overwrite location #N: active picking tasks exist."` (test-verified: the same message covers the allocated-without-task case).
5. **Location binding**: missing → 422 `"Location #N not found."`, inactive → 422 `"Location {code} is not active."`, explicit `warehouse_id` ≠ the location's warehouse → 422 `"Location #N (code) belongs to warehouse #M, not warehouse #W."` (warehouse is resolved SOLELY from the location row; test-verified message pattern).
6. **Placement write**: `updateOrCreate` per entry (`product_id`, `product_variant_id` nullable-or-variant, `location_id`) with `warehouse_id` from the location, `quantity`, `allocated_hint` reset to `0`.
7. **Auto-allocation**: every touched placement runs `StockOperationService::autoAllocatePendingFulfillments(placement)` — pending fulfillment items whose product/variant matches receive a location automatically (test-verified: single sufficient location assigns directly; insufficient single location triggers a SPLIT of the fulfillment item across locations of the same warehouse, then picking tasks generate for BOTH locations).

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
        "quantity": 25,
        "attribute_values": [9],
        "weight": "0.5",
        "length": "5",
        "width": "5",
        "height": "5",
        "locations": [
          { "location_id": 3, "quantity": 15 },
          { "location_id": 4, "quantity": 10 }
        ]
      }
    ],
    "locations": [
      { "location_id": 3, "quantity": 10 },
      { "location_id": 4, "quantity": 5 }
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
| `data.tax` | object \| null | Always present (nullable — runtime-probed 2026-10-17: `null` when `tax_enabled` is `false`) | `ProductTaxPresenter::describe` output | Object or `null` |
| `data.price_including_tax` | number | Always present (null possible) | Tax-inclusive value, rounded | e.g. `115.0` |
| `data.price_after_discount` | number | Always present (null possible) | Post-discount price, rounded | e.g. `90.0`; runtime-probed 2026-10-17: `null` on the freshly-created instance when no discount is configured |
| `data.price_after_flash_sale` | number | Always present (null possible) | Post-flash-sale price, rounded | e.g. `null` |
| `data.discount_type` | string \| null | Always present (nullable) | Discount kind | `percentage`, `fixed_rate`, `free_shipping`, or `null`; NOTE (runtime-probed 2026-10-17): on the freshly-created instance `discount_*` may be `null` even with `has_*` flags — the DEFAULT fill (`percentage`/`0`) appears only on reloaded rows (index/show) |
| `data.discount_amount` | number \| null | Always present (nullable) | Discount value | e.g. `10` or `null` |
| `data.start_date` | string \| null | Always present (nullable) | Window start | Date or `null` |
| `data.end_date` | string \| null | Always present (nullable) | Window end | Date or `null` |
| `data.sku` | string \| null | Always present (nullable) | SKU | e.g. `"SH-001"` |
| `data.stock_quantity` | integer \| null | Always present (nullable) | Stock on hand | e.g. `10` |
| `data.reserved_quantity` | integer | Always present | Reserved (defaults `0`) | e.g. `0` |
| `data.available_stock` | integer \| null | Always present (nullable) | Sellable units | e.g. `10` |
| `data.quantity` | integer \| null | Always present (nullable) | Quantity — NEW FLOW: force-set to the placement sum when `locations`/variant `locations` ride the request (runtime-probed: `30 = 20 + 10`); equals the raw input when no placements | e.g. `10` |
| `data.sold_quantity` | integer | Always present | Sold (defaults `0`) | e.g. `0` |
| `data.in_stock` | integer \| boolean | Always present (NOT a single cast — runtime-probed 2026-10-17: `1` on the freshly-created SIMPLE product; boolean `true` on the freshly-created VARIABLE product) | Stock flag | `1`, `0`, `true`, `false` |
| `data.status` | integer \| null | Always present (nullable — runtime-probed 2026-10-17: `null` when the create request omits `status`; the model/DB default is only reflected on RELOADED rows, e.g. index/show show `0`) | Publish flag | `1`, `0`, or `null` (fresh instance) |
| `data.product_type` | string | Always present | Product kind | `simple`, `variable` |
| `data.item_type` | string \| null | Always present (nullable — runtime-probed 2026-10-17: `null` when the create request omits `item_type`; reloaded rows show the default `PHYSICAL`) | Fulfillment kind | `PHYSICAL`, `DIGITAL`, or `null` (fresh instance) |
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
    "tax": null,
    "price_including_tax": 100.0,
    "price_after_discount": null,
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
    "status": null,
    "product_type": "simple",
    "item_type": null,
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
| 422 | Placement synchronization fails post-validation (sum mismatch, negative per-location quantity is caught by validation; missing/inactive location; explicit `warehouse_id` ≠ location's warehouse; overwrite of a placement carrying open picking tasks or allocated stock) | `{message: string, status: boolean}` — NO `errors` key (repository `HttpException` → `Handler` envelope) | None | Exact server message, e.g. `"The sum of location quantities (25) must equal the total product quantity (30)."`, `"Cannot overwrite location #3: active picking tasks exist."`, `"Location #3 (A-01) belongs to warehouse #2, not warehouse #1."`, `"Location #3 not found."`, `"Location A-01 is not active."` | None | Valid request body whose placement rules fail (test-verified `WmsMultiLocationTest`; quantity sum messages byte-pinned) | See 422-placement example |
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

422-placement example (post-validation placement refusal — note NO `errors` map and whole-transaction rollback: NO product exists after the failure):

```json
{
  "message": "The sum of location quantities (25) must equal the total product quantity (30).",
  "status": false
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
