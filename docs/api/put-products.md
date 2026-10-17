# Endpoint Name

Products – Update Product (`PUT /api/v1/products/{product}`)

# Description

Partially updates one product by id with type-guard rails and returns 200.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `PUT` (apiResource also registers `PATCH` → same action; tests use `putJson`) |
| Full path | `/api/v1/products/{product}` |
| Path composition | `api/v1` prefix + `products/{product}` from `Route::apiResource('products', ...)` in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group, plus route-level ANY-of pipe middleware (constructor `update-product` gate decides) |
| Controller action | `Marvel\Http\Controllers\ProductController@update(ProductUpdateRequest $request, $id)` → merges path `id` → `updateProduct` helper → `ProductRepository::updateProduct`; variant handling is NOW TWO-PATH (NEW FLOW, code-verified working tree): (a) incoming `variants[]` rows CARRY `id`s → partial sync: existing rows locked (`lockForUpdate`), ONLY those NOT in the incoming set are deleted — delete set runs the protected-reference check (452-guard below) BEFORE the delete, incoming-id rows are UPDATED in place (`sale_price` recalculated via `ProductPricingService`, `attribute_values` synced by delete+recreate) and rows WITHOUT ids become new rows — this partial path NEVER deletes a referenced incoming variant, so per-row 422s hit only the DELETE set; then product stock resync (`stock_quantity = SUM(variant stock)`, `in_stock` follows, forceFill — test-verified `50 = 20 + 30`); (b) NO incoming ids → full-replace path: `assertVariantsReplaceable` FIRST (row-locks current variant rows; refuses with `HttpException(422)` if any current row is referenced by `fulfillment_items` / `order_products` / `product_locations` (any row, live or zero-qty history) or carries `reserved_quantity > 0` — test-verified `tests/Feature/Wms/VariantLifecycleGuardTest.php`), guarded delete with the FK-1451 race backstop converting MySQL `1451` to the SAME 422 instead of HTTP 500, then `addVariants`; BOTH paths end with `syncProductPlacements` (product level) and `syncVariantPlacements` per variant (SEE the placements flow in `post-products.md` — same six rules: sum invariant, overwrite guard on open picking tasks/allocated stock, missing/inactive location, strict warehouse binding, `allocated_hint` reset, then `autoAllocatePendingFulfillments` per touched placement); any placement/variant-refusal `HttpException(422)` → transaction ROLLBACK → RETHROW (raw 422 envelope via `Handler` `translateHttpMessage` pass-through, NO `errors` map); helper-level `MarvelException` converts to `AuthorizationException(NOT_AUTHORIZED)` (NOT rethrown as update-failure — code-verified twist: repository `MarvelException`s surface as 403 `'This action is unauthorized'`, not 400); uncaught non-`MarvelException`s (incl. miss-shaped `ModelNotFoundException`) escape to `Handler` (404-fixed or 500 by class); then `flushTag(PRODUCTS)` |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `update-product` via controller constructor (applies only to `update`); `ProductUpdateRequest::authorize` returns `true` |
| Required headers | `Authorization: Bearer <sanctum-token>`, `Content-Type: application/json` (or `multipart/form-data` when replacing images) |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en\|ar` (default `en`) |
| Supported request formats | `application/json` for field-only updates; `multipart/form-data` when sending `images` |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `products.update` (apiResource default naming) |

Middleware runs before validation: 401/403 precede 403-twist/404/422/500. `UniqueTranslationRule::ignore($this->route('product'))` matches the `{product}` placeholder (code-verified).

## Request inputs

Update relaxations vs create (all code-verified): `name`/`description`/`product_type` → `sometimes`; `images` → optional (`sometimes|image` per element — NOTE `image` not `file`, and element-level `sometimes`: an empty object element passes); `in_stock`/`has_discount`/`has_flash_sale` → `sometimes` (lax string forms kept); `discount_status` relaxes to lax `in:true,false,1,0` (create is STRICT `in:1,0`); `tags` relaxes to `sometimes`; variants gain `id` (which-row) + `sale_price`, all `sometimes`; `shop_id` appears (update-only).

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | In `['en', 'ar']` | `en`, `ar` | `en` | None | `en` | Selects message locale |
| `product` | path | mixed id | Required | No | No route constraint; merged as `id` | Existing product id | None | None | `1` | Identifies the product |
| `name` | body | object | Optional | No | `sometimes\|array` | Object with locales | Unchanged | None | `{"en": "Hacked"}` | Localized names wrapper |
| `name.*` | body | string | Optional | No | `sometimes\|string\|max:255` + unique-ignore-self | String ≤ 255 chars | Unchanged | None | `"Hacked"` | One locale's name |
| `description` | body | object | Optional | No | `sometimes\|array` | Object with locales | Unchanged | None | `{"en": "New desc"}` | Localized descriptions wrapper |
| `product_type` | body | string | Optional | No | `sometimes\|Rule::in(['simple', 'variable'])` — WARNING: switching simple↔variable post-create has order/type-immutability guards elsewhere (`ITEM_TYPE_IMMUTABLE_*` family exists for `item_type`; `product_type` switch behavior **Unverified in source** — proceed carefully) | `simple`, `variable` | Unchanged | None | `"simple"` | Product kind |
| `item_type` | body | string | Optional | No | `sometimes\|string\|Rule::in(['PHYSICAL', 'DIGITAL'])` — switching PHYSICAL↔DIGITAL on products with orders/assets is blocked by `ERROR.ITEM_TYPE_IMMUTABLE_ORDERED/ASSETS` guards (code-verified existence of the guard family; trigger conditions summarized) | `PHYSICAL`, `DIGITAL` | Unchanged | None | `"PHYSICAL"` | Fulfillment kind |
| `description.*` | body | string | Optional | No | `sometimes\|string\|max:10000` | String ≤ 10000 chars | Unchanged | None | `"New desc"` | One locale's description |
| `price` | body | number | Optional | No | `sometimes\|numeric\|min:0` + `required_if:product_type,simple` | Number ≥ 0 | Unchanged | Required iff request `product_type` is `simple` | `120` | Base price |
| `shop_id` | body | integer | Optional | No | `sometimes\|exists:shops,id` (UPDATE-ONLY field — absent on create) | Existing shop id | Unchanged | None | `1` | Shop reference |
| `categories` | body | array of integers | Optional | No | `sometimes\|array` (absent key leaves links untouched) | Id arrays | Unchanged | None | `[2]` | Category replacement |
| `categories.*` | body | integer | Required per element | No | `integer\|exists:categories,id` | Existing category ids | None | None | `2` | One category id |
| `pieces` | body | integer | Optional | No | `sometimes\|integer\|min:1` | Integer ≥ 1 | Unchanged | None | `1` | Pieces count |
| `status` | body | string/integer | Optional | No | `sometimes\|in:0,1` | `0`, `1` | Unchanged | None | `1` | Publish flag |
| `height` | body | string | Optional | Yes | `nullable\|string` (pre-cast to string) | Any string | Unchanged | None | `"10"` | Height dimension |
| `length` | body | string | Optional | Yes | `nullable\|string` (pre-cast to string) | Any string | Unchanged | None | `"30"` | Length dimension |
| `width` | body | string | Optional | Yes | `nullable\|string` (pre-cast to string) | Any string | Unchanged | None | `"20"` | Width dimension |
| `weight` | body | string | Optional | Yes | `nullable\|string` (pre-cast to string) | Any string | Unchanged | None | `"1.5"` | Weight |
| `images` | body | array of files | Optional | No | `sometimes\|array` (absent = images kept) | File arrays | Unchanged | None | `["n.jpg"]` | Replacement images |
| `images.*` | body (file) | file | Optional | No | `sometimes\|image\|mimes:jpeg,png,jpg,gif\|max:2048` (NOTE `image` not `file`, and element-level `sometimes`: an empty object element passes) | jpeg/png/jpg/gif ≤ 2 MB | Unchanged | None | `n.jpg` | One replacement image |
| `in_stock` | body | string | Optional | No | `sometimes\|in:true,false,1,0` (lax kept) | `true`, `false`, `1`, `0` | Unchanged | None | `1` | Stock switch |
| `has_discount` | body | string | Optional | No | `sometimes\|in:true,false,1,0` (lax kept) | `true`, `false`, `1`, `0` | Unchanged | None | `1` | Discount switch |
| `has_flash_sale` | body | string | Optional | No | `sometimes\|in:true,false,1,0` (lax kept) | `true`, `false`, `1`, `0` | Unchanged | None | `0` | Flash-sale switch |
| `is_fast_shipping_available` | body | boolean | Optional | Yes | `nullable\|boolean` | Booleans | Unchanged | None | `false` | Fast-shipping switch |
| `flash_sale_id` | body | integer | Conditionally required | No | `required_if:has_flash_sale,1\|exists:flash_sales,id` | Existing flash-sale id | Unchanged | Required iff `has_flash_sale` is `1` | `4` | Linked flash sale |
| `discount_type` | body | string | Conditionally required | No | `required_if:has_discount,1\|Rule::in(['percentage', 'fixed_rate', 'free_shipping'])` | `percentage`, `fixed_rate`, `free_shipping` | Unchanged | Required iff `has_discount` is `1` | `"percentage"` | Discount kind |
| `discount_amount` | body | number | Conditionally required | No | `required_if:has_discount,1\|numeric\|min:1` | Number ≥ 1 | Unchanged | Required iff `has_discount` is `1` | `10` | Discount value |
| `discount_status` | body | string | Conditionally required | No | `required_if:has_discount,1\|in:true,false,1,0` (lax on update; create is STRICT `in:1,0`) | `true`, `false`, `1`, `0` | Unchanged | Required iff `has_discount` is `1` | `1` | Discount status |
| `start_date` | body | string (date) | Optional | No | `sometimes\|date` | Valid date | Unchanged | None | `"2026-01-01"` | Window start |
| `end_date` | body | string (date) | Optional | No | `sometimes\|date\|after_or_equal:start_date` | Valid date ≥ `start_date` | Unchanged | None | `"2026-02-01"` | Window end |
| `brands` | body | array of integers | Optional | No | `sometimes\|array` | Id arrays | Unchanged | None | `[1]` | Brand replacement |
| `brands.*` | body | integer | Required per element | No | `integer\|exists:brands,id` | Existing brand ids | None | None | `1` | One brand id |
| `tags` | body | array of integers | Optional | No | `sometimes\|array` (update relaxes create's `nullable` to `sometimes`) | Id arrays | Unchanged | None | `[3]` | Tag replacement |
| `tags.*` | body | integer | Required per element | No | `integer\|exists:tags,id` | Existing tag ids | None | None | `3` | One tag id |
| `banners` | body | array of integers | Optional | No | `sometimes\|array` | Id arrays | Unchanged | None | `[1]` | Banner replacement |
| `banners.*` | body | integer | Required per element | No | `integer\|exists:banners,id` | Existing banner ids | None | None | `1` | One banner id |
| `sliders` | body | array of integers | Optional | No | `sometimes\|array` | Id arrays | Unchanged | None | `[1]` | Slider replacement |
| `sliders.*` | body | integer | Required per element | No | `integer\|exists:sliders,id` | Existing slider ids | None | None | `1` | One slider id |
| `tax_enabled` | body | boolean | Optional | No | `sometimes\|boolean` | Booleans | Unchanged | None | `false` | Tax switch |
| `tax_rate` | body | number | Optional | Yes | `nullable\|numeric\|min:0\|max:100` | `0`–`100` | Unchanged | None | `15` | Tax percent |
| `locations` | body | array of objects | Optional | No | `sometimes\|array` | Placement entries | Absent (product placements kept) | Ignored for `variable` products with variants (variant rows own the stock) | `[{"location_id": 3, "quantity": 20}]` | NEW-FLOW product-level WMS placement replacement |
| `locations.*.location_id` | body | integer | Required with `locations` | No | `required_with:locations\|integer\|exists:locations,id` | Existing location ids | None | None | `3` | The stock location |
| `locations.*.quantity` | body | number | Required with `locations` | No | `required_with:locations\|numeric\|min:0` | Number ≥ 0 | None | Sum must equal `quantity` (tolerance `0.0001`) else 422 | `20` | Units placed at this location |
| `locations.*.warehouse_id` | body | integer | Optional | Yes | `nullable\|integer\|exists:warehouses,id` | Existing warehouse ids | Resolved SOLELY from the location row (mismatch → 422) | None | `1` | Advisory binding check |
| `quantity` | body | integer | Optional | No | `sometimes\|integer\|min:1` | Integer ≥ 1 | Unchanged | With `locations[]`: sum of location quantities must equal this value (tolerance `0.0001`) — else 422 AFTER validation (test-verified message) | `20` | Product-level stock (force-updated to the location sum when placements sync) |
| `variants` | body | array of objects | Optional | No | `sometimes\|array` | Variant arrays | Unchanged | None | `[{"id": 11}]` | Variant rows |
| `variants.*.id` | body | integer | Optional | No | `sometimes\|exists:product_variants,id` (UPDATE-ONLY row selector — present = partial sync path for that row; ABSENT ids in the list = full-replace completion semantics: rows NOT referenced by any incoming row are DELETED, subject to the protected-reference refusal) | Existing variant ids | Absent | Decides WHICH update path runs (see Controller action) | `11` | Targets a variant row |
| `variants.*.price` | body | number | Optional | No | `sometimes\|numeric\|min:0` | Number ≥ 0 | Unchanged | None | `120` | Variant price |
| `variants.*.sale_price` | body | number | Optional | No | `sometimes\|numeric\|min:0` (UPDATE-ONLY field) | Number ≥ 0 | Unchanged | None | `90` | Variant sale price |
| `variants.*.quantity` | body | integer | Optional | No | `sometimes\|integer\|min:0` | Integer ≥ 0 | Unchanged | With `variants[].locations[]`: sum of that variant's location quantities must equal this value (tolerance `0.0001`) else 422 `"The sum of location quantities (S) must equal the total variant quantity (Q)."` (test-verified); absent with locations → derived from the sum | `5` | Variant quantity (force-set to its location sum when placements sync) |
| `variants.*.weight` | body | string | Optional | No | `sometimes\|string` (pre-cast to string) | Any string | Unchanged | None | `"0.5"` | Variant weight |
| `variants.*.length` | body | string | Optional | No | `sometimes\|string` (pre-cast to string) | Any string | Unchanged | None | `"5"` | Variant length |
| `variants.*.width` | body | string | Optional | No | `sometimes\|string` (pre-cast to string) | Any string | Unchanged | None | `"5"` | Variant width |
| `variants.*.height` | body | string | Optional | No | `sometimes\|string` (pre-cast to string) | Any string | Unchanged | None | `"5"` | Variant height |
| `variants.*.attribute_values` | body | array of integers | Optional | No | `sometimes\|array` | Id arrays | Unchanged | None | `[9]` | Variant attribute links |
| `variants.*.attribute_values.*` | body | integer | Required per value | No | `integer\|exists:attribute_values,id` | Existing value ids | None | None | `9` | One attribute value |
| `variants.*.locations` | body | array of objects | Optional | No | `sometimes\|array` | Placement entries per variant | Absent (variant placements kept) | Applied during the per-row sync on the partial path, or after full-replace `addVariants` | `[{"location_id": 3, "quantity": 20}]` | NEW-FLOW per-variant stock placements |
| `variants.*.locations.*.location_id` | body | integer | Required with `variants.*.locations` | No | `required_with:variants.*.locations\|integer\|exists:locations,id` | Existing location ids | None | None | `3` | The stock location for this variant |
| `variants.*.locations.*.quantity` | body | number | Required with `variants.*.locations` | No | `required_with:variants.*.locations\|numeric\|min:0` | Number ≥ 0 | None | Sum must equal `variants.*.quantity` (tolerance `0.0001`) else 422 | `20` | Units placed at this location for this variant |
| `variants.*.locations.*.warehouse_id` | body | integer | Optional | Yes | `nullable\|integer\|exists:warehouses,id` | Existing warehouse ids | Resolved from the location row (mismatch → 422) | None | `1` | Advisory binding check |

## Request examples

```json
{
  "method": "PUT",
  "url": "/api/v1/products/1",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json",
    "lang": "en"
  },
  "body": {
    "name": { "en": "Hacked" }
  }
}
```

Partial variant-sync + placements example (NEW FLOW — rows with `id` update in place, unlisted rows are DELETED subject to the protected-reference refusal; variant gets multi-location stock; product stock resyncs to the variant sum):

```json
{
  "method": "PUT",
  "url": "/api/v1/products/1",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json"
  },
  "body": {
    "variants": [
      {
        "id": 11,
        "price": 110,
        "quantity": 20,
        "locations": [
          { "location_id": 3, "quantity": 20 }
        ]
      },
      {
        "id": 12,
        "price": 150,
        "quantity": 30,
        "locations": [
          { "location_id": 4, "quantity": 30 }
        ]
      }
    ],
    "in_stock": 1
  }
}
```

# Responses

## Success: product updated — HTTP 200

Returned via `ApiResponse::apiResponse(UPDATE_PRODUCT_SUCCESSFULLY, 200, true, ProductResource::make($product))` (raw name/description).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.UPDATE_PRODUCT_SUCCESSFULLY` | `en` → `"Product updated successfully"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success (verified: `apiResponse(..., 200, true, ...)` — `true` is hardcoded on this path) |
| `data` | object | Always present on success | Refreshed `ProductResource` (raw name/description; `Resource` object is never `empty()`, so the `data` key is never omitted here) | Every key below |
| `data.id` | integer | Always present | Product id | e.g. `1` |
| `data.name` | string | Always present | Raw stored translations as JSON TEXT (verified `getRawOriginal`) | e.g. `"{\"en\": \"Hacked\"}"` |
| `data.slug` | string | Always present | Product slug | e.g. `"shirt"`; CAVEAT (runtime-probed 2026-10-17): an update that regenerates the slug can return the placeholder `"auto-generated-string"` |
| `data.description` | string | Always present | Raw stored translations as JSON TEXT | e.g. `"{\"en\": \"New desc\"}"` |
| `data.price` | number | Always present (null possible) | Base price, rounded | e.g. `100.0` |
| `data.current_price` | number | Always present (null possible) | Pre-tax effective price, rounded | e.g. `100.0` |
| `data.tax_enabled` | boolean | Always present | Tax switch | `true` / `false` |
| `data.tax_rate` | number \| null | Always present (nullable) | Tax percent | e.g. `15.0` or `null` |
| `data.tax` | object \| null | Always present (nullable — runtime-probed 2026-10-17: `null` when `tax_enabled` is `false`) | `ProductTaxPresenter::describe` output | Object or `null` |
| `data.price_including_tax` | number | Always present (null possible) | Tax-inclusive value, rounded | e.g. `115.0` |
| `data.price_after_discount` | number | Always present (null possible) | Post-discount price, rounded | e.g. `90.0`; runtime-probed 2026-10-17: `null` when no discount is configured — including rows carrying the default fill (`discount_type:"percentage"`, `discount_amount:0`) |
| `data.price_after_flash_sale` | number | Always present (null possible) | Post-flash-sale price, rounded | e.g. `null` |
| `data.discount_type` | string \| null | Always present (nullable) | Discount kind | `percentage`, `fixed_rate`, `free_shipping`, or `null`; NOTE (runtime-probed 2026-10-17): the update response serializes the RELOADED product, so the DEFAULT fill (`percentage` + `discount_amount: 0`) appears even when `has_discount=false` |
| `data.discount_amount` | number \| null | Always present (nullable) | Discount value | e.g. `10`; default fill `0` on reloaded rows |
| `data.start_date` | string \| null | Always present (nullable) | Window start | Date or `null` |
| `data.end_date` | string \| null | Always present (nullable) | Window end | Date or `null` |
| `data.sku` | string \| null | Always present (nullable) | SKU | e.g. `"SH-001"` |
| `data.stock_quantity` | integer \| null | Always present (nullable) | Stock on hand — NEW FLOW: force-set to the placement sum when the update carries `locations`/variant `locations` (runtime-probed: `12 = 12`) | e.g. `10` |
| `data.reserved_quantity` | integer | Always present | Reserved (defaults `0`) | e.g. `0` |
| `data.available_stock` | integer \| null | Always present (nullable) | Sellable units — mirrors `stock_quantity` after placement sync (runtime-probed) | e.g. `10` |
| `data.quantity` | integer \| null | Always present (nullable) | Quantity — NEW FLOW: force-set to the placement sum when placements ride the update (runtime-probed `12`); equals stored value otherwise | e.g. `10` |
| `data.sold_quantity` | integer | Always present | Sold (defaults `0`) | e.g. `0` |
| `data.in_stock` | integer \| boolean | Always present (NOT a single cast — runtime-probed 2026-10-17: `true` (boolean) on the updated variable product; `1`/`0` integer on reloaded rows) | Stock flag | `1`, `0`, `true`, `false` |
| `data.status` | integer | Always present | Publish flag (verified integer cast) | `1`, `0` |
| `data.product_type` | string | Always present | Product kind | `simple`, `variable` |
| `data.item_type` | string | Always present | Fulfillment kind | `PHYSICAL`, `DIGITAL` |
| `data.height` | string \| null | Always present (nullable) | Dimension | e.g. `"10"` or `null` |
| `data.width` | string \| null | Always present (nullable) | Dimension | e.g. `"20"` or `null` |
| `data.length` | string \| null | Always present (nullable) | Dimension | e.g. `"30"` or `null` |
| `data.weight` | string \| null | Always present (nullable) | Weight | e.g. `"1.5"` or `null` |
| `data.has_flash_sale` | boolean | Always present | Flash-sale switch (verified model boolean cast) | `true` / `false` |
| `data.has_discount` | boolean | Always present | Discount switch (verified model boolean cast) | `true` / `false` |
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
| `data.variants` | array | Present when `variations` loaded | Variant rows with `id/price/current_price/stock_quantity/reserved_quantity/available_stock/quantity/height/width/length/weight/attributes[{attribute_name,value}]` | `[]` for simple products; variant `price` reflects the update in the same response (runtime-probed `55`); `attributes[].attribute_name` can be an EMPTY STRING when the attribute row has no localized name for the active locale (runtime-probed) |

```json
{
  "status": 200,
  "message": "Product updated successfully",
  "success": true,
  "data": {
    "id": 1,
    "name": "{\"en\": \"Hacked\"}",
    "slug": "shirt",
    "description": "{\"en\": \"New desc\"}",
    "price": 100.0,
    "current_price": 100.0,
    "tax_enabled": false,
    "tax_rate": null,
    "tax": null,
    "price_including_tax": 100.0,
    "price_after_discount": null,
    "price_after_flash_sale": null,
    "discount_type": "percentage",
    "discount_amount": 0,
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

No error-code field; every error below has Error code `None`. NOTE the 403-twist (unique in this batch).

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | No valid token (strict `assertEquals(401)` in `test_guest_cannot_update_product`, `putJson`) | See 401 example |
| 403 | Authenticated caller lacks `update-product` (constructor gate) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | View-only user (verified by `test_view_only_user_cannot_update` → 403) | See 403 example |
| 403 | Repository raised `MarvelException` (helper converts to `AuthorizationException(NOT_AUTHORIZED)` → `Handler` `AuthorizationException` branch — a BUSINESS failure disguised as authz; code-verified twist, compare coupons' 400 for the same situation) | `{message: string, status: boolean}` | None | `"This action is unauthorized"` (NO trailing period — `Handler` text) | None | Repository-level `MarvelException` (e.g. guarded transition) | See 403-twist example |
| 404 | Miss surfaces as `ModelNotFoundException` (escapes both catches → fixed string) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string) | None | Unknown id (code-traced; no update-miss test found) | See 404 example |
| 422 | `ProductUpdateRequest` fails | `{message: string, status: boolean, errors: object}` | None | Laravel default | Field → messages | Invalid body (code-traced) | See 422 example |
| 422 | Variant-replacement refusal — SCOPE BY PATH (NEW FLOW): full-replace (NO incoming `variants[].id`) → `assertVariantsReplaceable` checks ALL current rows; partial (ids present) → the SAME protected-reference check runs ONLY on the DELETE set (rows absent from the incoming list). Protected classes: `fulfillment_items`, `order_products` (RESTRICT — no silent unattribution), `product_locations` (any live or zero-qty history row), or `reserved_quantity > 0`; full-replace path also carries the FK-`1451` race backstop (code-verified `ProductRepository` + `Handler` `HttpException` branch with `translateHttpMessage` pass-through; test-verified `VariantLifecycleGuardTest`: 422, falsy `success`/`status`, string `message`, NO `SQLSTATE`/`1451`/FK-name leakage, target data untouched, repeat attempt identically refused) | `{message: string, status: boolean}` — NO `errors` key (only the `InvalidDataException` validation branch adds `errors`) | None | `"Cannot replace variants: existing variants are referenced by orders, fulfillments, or placements."` (plain constant by repository convention — deliberately NOT translated and NOT field-scoped) | None | Variant payload + protected reference | See 422-guard example |
| 422 | Placement synchronization fails post-validation (sum mismatch; duplicate locations in placements; target location occupied by another product/variant; overwrite guard on open picking tasks/allocated stock; missing/inactive location; strict warehouse binding; runs for product level via `syncProductPlacements` and per variant via `syncVariantPlacements`) → transaction ROLLBACK → rethrow (test-verified `WmsMultiLocationTest`, `ProductStoreUpdateOccupancyTest`) | `{message: string, status: boolean}` — NO `errors` key | None | e.g. `"The sum of location quantities (25) must equal the total variant quantity (30)."`, `"Duplicate locations are not allowed in placements."`, `"Location #3 is occupied by product #1 and cannot receive product #2."`, `"Cannot overwrite location #3: active picking tasks exist."`, `"Location #3 (A-01) belongs to warehouse #2, not warehouse #1."`, `"Location #3 not found."`, `"Location A-01 is not active."` | None | Valid body whose placement rules fail | See 422-placement example |
| 500 | Any other post-validation failure | `{message: string, status: boolean}` | None | `"Internal Server Error"` (or live text in debug) | None | Post-validation failure (code-traced) | See 500 example |
| 429 | Exceeding `throttle:admin` | `{success: boolean, message: string, data: null}` | None | `__('message.too_many_requests')` | None | Rate limit exceeded | See 429 example |

401 example:

```json
{
  "message": "Unauthenticated",
  "status": false
}
```

403 example (missing permission):

```json
{
  "message": "User does not have the required permissions.",
  "status": false
}
```

403-twist example (repository `MarvelException` — note NO period):

```json
{
  "message": "This action is unauthorized",
  "status": false
}
```

404 example:

```json
{
  "message": "Resource Not Found",
  "status": false
}
```

422 example:

```json
{
  "message": "The selected type is invalid.",
  "status": false,
  "errors": {
    "type": ["The selected type is invalid."]
  }
}
```

422-guard example (variant replacement refused — note NO `errors` map and no SQL internals):

```json
{
  "message": "Cannot replace variants: existing variants are referenced by orders, fulfillments, or placements.",
  "status": false
}
```

422-placement example (post-validation placement refusal — whole-update transaction rolls back; nothing is rewritten):

```json
{
  "message": "Cannot overwrite location #3: active picking tasks exist.",
  "status": false
}
```

500 example:

```json
{
  "message": "Internal Server Error",
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
