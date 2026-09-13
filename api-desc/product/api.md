# Product Module — Full API Reference

> Last verified: 2026-09-13 — covers both **Admin `apiResource`** (`packages/marvel/src/Rest/Routes.php:236`) and **Storefront** (`routes/api.php` `v1/general/products`).

---

## Product Type (`item_type`)

Every product carries an **`item_type`** field that describes the nature of the product and its fulfillment behavior.

| Value | Meaning | Examples | Inventory Implication | Fulfillment Implication |
|-------|---------|----------|----------------------|------------------------|
| `PHYSICAL` | Physical/tangible product (default) | Phone, clothes, laptop, accessories | Uses physical stock reservation/deduction | May require shipping or pickup |
| `DIGITAL` | Digital product delivered electronically after purchase | PDF document, license, activation code, downloadable file | Unlimited availability for MVP; no physical stock touched | Delivered electronically once payment succeeds |

**Important rules:**

- Only `PHYSICAL` and `DIGITAL` are valid. (`SERVICE` was removed from the domain.)
- `item_type` is a **PRODUCT-level attribute**. It is NOT the product category.
- Do NOT treat `DIGITAL` as `SERVICE`.
- Defaults to `PHYSICAL` when omitted (all legacy products resolve to `PHYSICAL`).
- Invalid values are rejected with `422`.
- **Immutability:** once a product has order items or digital assets attached, its `item_type` can no longer be changed — attempts return `422`.

> Note: `product_type` is a DIFFERENT field. It holds the variant structure of the product (`simple` or `variable`) and is derived server-side from whether variants are present. It must not be confused with `item_type`.

---

## Routes Overview

### A) Admin — `Route::apiResource('products', ProductController::class)` — `packages/marvel/src/Rest/Routes.php:236`

Grouped under `Route::middleware(['auth:sanctum','throttle:admin'])` → prefix `api/v1`. All 5 routes inherit `auth:sanctum`. Permissions enforced in `Marvel\Http\Controllers\ProductController::__construct` (`packages/marvel/src/Http/Controllers/ProductController.php:108-111`).

| Method | URI | Name | Controller@function | Permission | Middleware |
|--------|-----|------|---------------------|------------|------------|
| `GET` | `/api/v1/products` | `products.index` | `Marvel\ProductController@index` | `view-products` | `auth:sanctum`,`throttle:admin` |
| `POST` | `/api/v1/products` | `products.store` | `Marvel\ProductController@store` | `create-product` | `auth:sanctum`,`throttle:admin` |
| `GET` | `/api/v1/products/{product}` | `products.show` | `Marvel\ProductController@show` | `view-products` | `auth:sanctum`,`throttle:admin` |
| `PUT\|PATCH` | `/api/v1/products/{product}` | `products.update` | `Marvel\ProductController@update` | `update-product` | `auth:sanctum`,`throttle:admin` |
| `DELETE` | `/api/v1/products/{product}` | `products.destroy` | `Marvel\ProductController@destroy` | `delete-product` | `auth:sanctum`,`throttle:admin` |

Bespoke routes sharing `/products/*` — declared **before** the `apiResource` (lines 223-235) so they are not swallowed by `{product}`:

| Method | URI | Name | Controller | Permission |
|--------|-----|------|------------|------------|
| `POST` | `/api/v1/products/bulk-delete` | — | `ProductController@destroyBulk` | `delete-product` |
| `DELETE` | `/api/v1/products/all` | — | `ProductController@destroyAll` | `delete-product` |
| `GET` | `/api/v1/products/import/sample` | `admin.products.import.sample` | `ProductImportController@downloadSample` | `create-product`/`super_admin` |
| `GET/POST` | `/api/v1/products/export` | `admin.products.export` / `admin.products.export.post` | `ProductExportController@export` | `export-product` |
| `GET` | `/api/v1/products/export/{id}` | `admin.products.export.status` | `ProductExportController@status` | `export-product` |
| `GET` | `/api/v1/products/export/{id}/download` | `admin.products.export.download` | `ProductExportController@download` | `export-product` |
| `POST` | `/api/v1/products/import` | `admin.products.import` | `ProductImportController@import` | `create-product`/`super_admin` |
| `GET` | `/api/v1/products/import/{id}` | `admin.products.import.status` | `ProductImportController@status` | `create-product`/`super_admin` |
| `POST` | `/api/v1/products/import/{id}/cancel` | `admin.products.import.cancel` | `ProductImportController@cancel` | `create-product`/`super_admin` |
| `GET` | `/api/v1/products/import/{id}/download-errors` | `admin.products.import.download-errors` | `ProductImportController@downloadErrors` | `create-product`/`super_admin` |
| `GET/POST` | `/api/v1/products/{product}/digital-assets` | `admin.products.digital-assets.*` | `DigitalAssetController@index|store` | `view-products` |

### B) Storefront — `App\Http\Controllers\Api\General\ProductController` — `routes/api.php:80-84`

Grouped under `Route::prefix('v1/general')->middleware(['api','throttle:public-api'])` — **public, no auth**, cached, currency-aware, channel-scoped.

| Method | URI | Name | Controller@function | Auth | Throttle |
|--------|-----|------|---------------------|------|----------|
| `GET` | `/api/v1/general/products` | `general.products.index` | `General\ProductController@index` | public | `throttle:public-api` |
| `GET` | `/api/v1/general/products/{slug}` | `general.products.show` | `General\ProductController@getProductBySlug` | public | `throttle:public-api` |

> Also: `POST /api/v1/general/products/{id}/reviews` + `PUT /api/v1/general/products/reviews/{id}` for product reviews (requires `auth:sanctum`, throttle:authenticated).

---

## A) Admin — `GET /api/v1/products` — `products.index`

Paginated admin listing. Requires `auth:sanctum` + `permission:view-products`.

**Headers:** `Authorization: Bearer <sanctum-token>` , `Accept: application/json`

### Query Parameters

| Param | Type | Default | Validation | Description |
|-------|------|---------|------------|-------------|
| `limit` | int | 15 | 1-100 | Page size. |
| `page` | int | 1 | — | Page number. |
| `search` | string | — | — | LIKE on translatable `name`/`description`, `sku`, variant SKUs (fallback to Scout when configured via `ProductService::buildScoutSearchQuery`). |
| `sort` | string | `desc` | `asc,desc` | Legacy direction for `created_at`. |
| `orderBy` | string | `created_at` | `created_at,updated_at,name,price,sold_quantity,sku,id` | Column. |
| `orderDir` | string | `desc` | `asc,desc` | Direction. |
| `category` | string | — | slug | Category slug filter via `ProductFilter`. |
| `banner` | string | — | slug | Banner slug filter. |
| `promotion` | string | — | slug | Promotion slug filter. |
| `flash_sale` | string | — | slug | Flash-sale slug filter. |
| `slider` | string | — | slug | Slider slug filter. |
| `tags` / `tag` | string | — | slug or numeric id, comma-separated | AND logic via `ProductFilter`. |
| `status` | int | — | `0,1` | Publish status filter. |
| `date_range` | string | — | `YYYY-MM-DD//YYYY-MM-DD` | Availability filter. |

### Response 200 — `Marvel\Http\Resources\product\ProductResource` + `ProductCollection`

```json
{
  "success": true,
  "message": "MESSAGE.FETCH_DATA_SUCCESSFULLY",
  "data": {
    "data": [
      {
        "id": 1,
        "name": { "en": "T-Shirt", "ar": "تيشيرت" },
        "slug": "t-shirt",
        "description": { "en": "A comfortable cotton t-shirt", "ar": "تيشيرت قطني" },
        "price": 29.99,
        "current_price": 19.99,
        "tax_enabled": false,
        "tax_rate": null,
        "tax": null,
        "price_including_tax": 19.99,
        "price_after_discount": 19.99,
        "price_after_flash_sale": null,
        "discount_type": "percentage",
        "discount_amount": 33,
        "start_date": "2026-09-01",
        "end_date": "2026-09-30",
        "sku": "PRD-001",
        "stock_quantity": 100,
        "reserved_quantity": 2,
        "available_stock": 98,
        "quantity": 100,
        "sold_quantity": 25,
        "in_stock": true,
        "status": "publish",
        "product_type": "simple",
        "item_type": "PHYSICAL",
        "height": null, "width": null, "length": null, "weight": null,
        "has_flash_sale": false,
        "has_discount": true,
        "discount_valid": true,
        "is_fast_shipping_available": false,
        "created_at": "2026-01-15T10:00:00.000000Z",
        "categories": [{ "id": 2, "name": "Clothing", "slug": "clothing" }],
        "flash_sales": [],
        "tags": [{ "id": 1, "name": "summer", "slug": "summer" }],
        "brands": [],
        "banners": [],
        "sliders": [],
        "reviews": [],
        "images": ["https://cdn.example.com/storage/products/1/thumb.jpg"],
        "variants": [
          { "id": 10, "price": 29.99, "current_price": 19.99, "stock_quantity": 50, "quantity": 50, "attributes": [{ "id": 1, "name": "Color", "value": "Red" }] }
        ]
      }
    ],
    "current_page": 1, "from": 1, "to": 15, "last_page": 5, "per_page": 15, "total": 72
  }
}
```

Error cases: `401` (no token), `403` (`view-products` missing), `422` (invalid `orderBy`).

---

## A) Admin — `GET /api/v1/products/{product}` — `products.show`

Fetch single product by numeric ID **or slug** via `ProductRepository::fetchSingleProduct`. Same auth/permission as index.

**Path:** `{product}` — integer ID or string slug.

### Response 200

```json
{
  "success": true,
  "message": "MESSAGE.FETCH_DATA_SUCCESSFULLY",
  "data": {
    "id": 1,
    "name": { "en": "T-Shirt", "ar": "تيشيرت" },
    "slug": "t-shirt",
    "description": { "en": "...", "ar": "..." },
    "price": 29.99,
    "current_price": 19.99,
    "tax_enabled": false,
    "tax_rate": null,
    "tax": null,
    "price_including_tax": 19.99,
    "price_after_discount": 19.99,
    "price_after_flash_sale": null,
    "sku": "PRD-001",
    "in_stock": true,
    "status": "publish",
    "product_type": "variable",
    "item_type": "PHYSICAL",
    "has_discount": true,
    "has_flash_sale": false,
    "is_fast_shipping_available": false,
    "images": ["https://cdn.example.com/storage/products/1/thumb.jpg"],
    "variants": [{ "id": 10, "price": 29.99, "current_price": 19.99, "stock_quantity": 50, "attributes": [{ "id": 1, "name": "Color", "value": "Red" }] }],
    "categories": [{ "id": 2, "name": "Clothing", "slug": "clothing" }],
    "brands": [], "tags": [], "banners": [], "sliders": [], "flash_sales": [], "reviews": [], "related_products": []
  }
}
```

### Response 404

```json
{ "success": false, "message": "MESSAGE.NOT_FOUND" }
```

---

## A) Admin — `POST /api/v1/products` — `products.store`

**Auth:** `auth:sanctum` + `permission:create-product` (`ProductController::__construct:109`). Throttle `throttle:admin`.
**Request:** `multipart/form-data` (images) or `application/json` (when images are URLs/media-ids in some clients). Validated by `Marvel\Http\Requests\ProductCreateRequest`.

### Request Body — `ProductCreateRequest` rules

| Field | Type | Required | Rules / Notes |
|-------|------|----------|---------------|
| `name` | object | **Yes** | `{ en:string 3-255, ar:string 3-255 }` — `UniqueTranslation` on `name.en`/`ar`. |
| `description` | object | **Yes** | `{ en:string, ar:string }` — min 5. |
| `product_type` | string | **Yes** | `in:simple,variable` — also auto-derived from presence of `variants`. |
| `item_type` | string | No | `in:PHYSICAL,DIGITAL` default `PHYSICAL` (`App\Enums\ItemType` / `Marvel\Enums\ItemType`). |
| `price` | numeric | sometimes | `required_if:product_type,simple` `min:0`. Currency base (catalog). |
| `categories` | array | **Yes** | `exists:categories,id` each. |
| `images` | array | **Yes** | `array` of files (`mimes:jpeg,png,jpg,gif|max:2048` via Spatie MediaLibrary). |
| `in_stock` | bool | **Yes** | `boolean` (accepts `0/1,true/false`). |
| `has_discount` | bool | **Yes** | `boolean`. |
| `has_flash_sale` | bool | **Yes** | `boolean`. |
| `type_id` | int | No | `exists:types,id`. |
| `quantity` | int | No | Stock qty (`integer|min:0`). |
| `sku` | string | No | Auto-generated `PRD-{id+1:03d}` if blank, `unique:products,sku`. |
| `status` | string | No | `in:publish,draft,under_review,approved,rejected,unpublish`. |
| `discount_type` | string | No | `required_if:has_discount,true` `in:percentage,fixed_rate,free_shipping` |
| `discount_amount` | numeric | No | `required_if:has_discount,true` |
| `discount_status` | bool | No | `required_if:has_discount,true` |
| `start_date` | date | No | `date` |
| `end_date` | date | No | `date|after_or_equal:start_date` |
| `flash_sale_id` | int | No | `required_if:has_flash_sale,true` `exists:flash_sales,id` |
| `variants` | array | No | Required when `product_type=variable`. Each: `price:required|numeric`, `quantity:required|integer`, `attribute_values:required_with:variants|array`, `attribute_values.*:exists:attribute_values,id`, `sku:unique:product_variants,sku`, dims optional. |
| `tags` | array | No | `nullable|array` `exists:tags,id` |
| `brands` | array | No | `exists:brands,id` |
| `banners` | array | No | `exists:banners,id` |
| `sliders` | array | No | `exists:sliders,id` |
| `pieces` | int | No | `integer|min:1` default 1 |
| `height/width/length/weight` | string | No | Cast to string via `prepareForValidation`. |
| `is_fast_shipping_available` | bool | No | `boolean` — scoped by `FastShippingScope` when channel enabled. |

### Response 201

```json
{
  "success": true,
  "message": "MESSAGE.CREATE_PRODUCT_SUCCESSFULLY",
  "data": {
    "id": 12,
    "name": { "en": "New Product", "ar": "منتج جديد" },
    "slug": "new-product",
    "product_type": "simple",
    "item_type": "PHYSICAL",
    "price": 49.99,
    "current_price": 49.99,
    "price_including_tax": 49.99,
    "in_stock": true,
    "status": "draft",
    "images": ["https://cdn.example.com/storage/products/12/thumb.jpg"]
  }
}
```

### Error 422 — validation

```json
{
  "name.en": ["The name.en field is required."],
  "categories": ["The categories field is required."],
  "images": ["The images field is required."]
}
```
```json
{ "item_type": ["The selected item type is invalid."] }
```
`401` unauthenticated, `403` missing `create-product`.

---

## A) Admin — `PUT|PATCH /api/v1/products/{product}` — `products.update`

**Auth:** `auth:sanctum` + `permission:update-product`.

Rules identical to `ProductCreateRequest` but all `sometimes`. `name.*` unique rule ignores current product (`UniqueTranslation` with `ignore:$id`). `tags` replacive sync. Currency/discount recalculated with fallback to persisted values. `item_type` change rejected with `422` if product already has `order_items` or `digital_assets`.

### Response 200

```json
{ "success": true, "message": "MESSAGE.UPDATE_PRODUCT_SUCCESSFULLY", "data": { "id": 12, "slug": "new-product", "item_type": "DIGITAL", "price": 49.99 } }
```

---

## A) Admin — `DELETE /api/v1/products/{product}` — `products.destroy`

Soft-delete via `SoftDeletes`. Requires `permission:delete-product`.

### Response 200

```json
{ "success": true, "message": "MESSAGE.DELETE_PRODUCT_SUCCESSFULLY" }
```

`404` if not found. Subsequent `GET` returns `404` unless `withTrashed` (not exposed).

---

## A) Admin — `POST /api/v1/products/bulk-delete` + `DELETE /api/v1/products/all`

| Endpoint | Body | Response 200 | Notes |
|----------|------|--------------|-------|
| `POST /products/bulk-delete` | `{ "ids": [1,2,3] }` — `ids:required|array|exists:products,id` | `{ "success": true, "message": "MESSAGE.PRODUCTS_DELETED_SUCCESSFULLY" }` | Hard delete `whereIn`. `403` if `delete-product` missing. `422` if `ids` empty/invalid. |
| `DELETE /products/all` | — | same | Hard delete all rows. Throttled `throttle:admin`, should be `super_admin` in policy. No soft-delete. |

---

## B) Storefront — `GET /api/v1/general/products` — public listing

**Controller:** `App\Http\Controllers\Api\General\ProductController@index` (`app/Http/Controllers/Api/General/ProductController.php:40-110`)
**Service:** `App\Services\General\ProductService` + `App\Services\General\ProductEngine\ProductStrategyResolver`
**Auth:** public — `middleware: api, throttle:public-api` (`routes/api.php:39`). No `auth:sanctum`.
**Cache:** `HasCache` + `currencyAwareCacheKey(Request)` — cached per-currency + per-query-string. `shouldCache()` returns `false` when `search` present (and when user-specific headers); otherwise cache HIT avoids DB/Scout.
**Channel:** `HasChannelFilter` + `FastShippingScope` auto-applied.
**Pricing:** `ConvertsProductPrice` + `ProductTaxPresenter` — prices converted to effective catalog currency (`effectiveCurrency()`).

### Query Parameters

| Param | Type | Default | Description |
|-------|------|---------|-------------|
| `limit` | int | 15 | 1-100 (`ProductService::getLimit`). |
| `page` | int | 1 | Laravel paginator page. |
| `type` | string | `null` (→ fallback) | Strategy key. `App\Http\Requests\ProductIndexRequest` validates `in:supportedTypes`. Supported: `index`, `best_product_sales`, `brands_product`, `new_arrivals`, `all_product_discounts`, `product_discount_today_or_low_qty`, `flash_sales_product`, `flash_sales_end_today`, `flash_sales_end_week`, `product_for_parent_category` (`ProductStrategyResolver::STRATEGIES`). `type=all` treated same as missing → fallback. |
| `order` | string | `desc` | `asc,desc` — orders fallback query by `id`. Validated by `ProductIndexRequest`. |
| `order_price` | string | — | `asc,desc` — when present, `orderBy('price', order_price)` in fallback flow. |
| `search` | string | — | Scouts `Product::search(term)` via Meilisearch when `buildScoutSearchQuery` non-null; otherwise `applyProductSearch` does `LIKE` on translatable `name`/`description` (`applyTranslatableLike`) + `orWhere price/sku/variant.sku`. |
| `productsId` | string | — | Comma-separated IDs filter (`applyIdsFilter`). |
| `category` | string | — | Category slug/ID via `ProductFilter` (`filter($request->all())`). |
| `brands` / `brand` | string | — | Brand IDs/slugs via `applyRelationIdsFilters`. |
| `tags` | string | — | Tag slug or ID, comma-separated via `ProductFilter`. |
| `price_min` / `price_max` | numeric | — | Via `applyProductFilters` → `ProductFilter`. |
| `rating_min` / `rating_max` | numeric | — | `reviews_avg_rating` range (`applyProductFilters`). |
| `height_min/max`, `width_min/max`, `length_min/max`, `weight_min/max` | numeric | — | Dimension range filters (`applyDimensionFilters`). |
| Other `ProductFilter` keys | string | — | `banner`, `promotion`, `flash_sale`, `slider`, `status`, `date_range` proxied to `ProductFilter`. |

Fallback flow (`buildFallbackResponse`): `buildScoutSearchQuery` → Scout `orderBy('id',order)->paginate(getLimit)` + `getDynamicFilters(clone scoutQuery)` ; else `buildFilteredBaseQuery` (active + channel + `applyProductFilters` + ids/relations) → `orderBy(price?) + orderBy(id,order) -> paginate`.

Strategy flow (`buildStrategyResponse`): `ProductStrategyResolver::resolve(type)->getProducts(request)` → pluck ids → `getDynamicFilters(whereIn ids)` → `getCollectionCategories(productIds)`.

### Response 200 — `Marvel\Http\Resources\product\ProductCollectionMini` / `App\Http\Resources\Product\ProductMiniResource`

```json
{
  "success": true,
  "message": "MESSAGE.FETCH_DATA_SUCCESSFULLY",
  "data": {
    "data": [
      {
        "id": 1,
        "name": "T-Shirt",
        "slug": "t-shirt",
        "price": 29.99,
        "current_price": 19.99,
        "currency": "USD",
        "has_variants": false,
        "item_type": "PHYSICAL",
        "quantity": 100,
        "in_stock": true,
        "discount_active": true,
        "flash_sale_active": false,
        "is_fast_shipping_available": false,
        "ratings": 4.35,
        "tags": [{ "id": 1, "name": "summer", "slug": "summer" }],
        "image": { "thumbnail": "https://cdn.example.com/storage/products/1/thumb.jpg", "original": ["https://cdn.example.com/storage/products/1/2.jpg"] }
      }
    ],
    "current_page": 1, "from": 1, "to": 15, "last_page": 5, "per_page": 15, "total": 72,
    "links": { "first": "/api/v1/general/products?page=1", "last": "/api/v1/general/products?page=5", "prev": null, "next": "/api/v1/general/products?page=2" },
    "filters": {
      "price": { "min": 5, "max": 299 },
      "brands": [{ "id": 3, "name": "Nike", "slug": "nike", "count": 12 }],
      "categories": [{ "id": 2, "name": "Clothing", "slug": "clothing", "count": 34 }],
      "tags": [{ "id": 1, "name": "summer", "slug": "summer", "count": 18 }],
      "ratings": { "1": 2, "2": 4, "3": 11, "4": 28, "5": 41 }
    },
    "categories": [{ "id": 2, "name": "Clothing", "slug": "clothing" }]
  }
}
```

Cache header not exposed but internal `HasCache` stores per `currencyAwareCacheKey`.

---

## B) Storefront — `GET /api/v1/general/products/{slug}` — public detail

**Route:** `Route::get('products/{slug}', [ProductController::class, 'getProductBySlug'])` (`routes/api.php:83`) — `throttle:public-api`, no auth. `{slug}` is string slug (not numeric ID).
**Controller:** `General\ProductController@getProductBySlug(Request $request)` — resolves by `slug`, applies `HasChannelFilter` + `active()` scope, eager-loads `variations`, `categories`, `brands`, `tags`, `banners`, `sliders`, `flash_sales`, `reviews`, `related_products`, media.
**Resource:** `App\Http\Resources\Product\ProductResource` (`app/Http/Resources/Product/ProductResource.php`) — currency-converted via `ConvertsProductPrice`, `HasProductFilters`.
**Cache:** `HasCache` per slug+currency. `shouldCache` same guard.

### Path

| Param | Type | Required | Description |
|-------|------|----------|-------------|
| `slug` | string | Yes | Product slug (unique, `customSlugify`). |

### Response 200 — `App\Http\Resources\Product\ProductResource`

```json
{
  "success": true,
  "message": "MESSAGE.FETCH_DATA_SUCCESSFULLY",
  "data": {
    "id": 1,
    "name": { "en": "T-Shirt", "ar": "تيشيرت" },
    "slug": "t-shirt",
    "description": { "en": "A comfortable cotton t-shirt", "ar": "..." },
    "price": 29.99,
    "current_price": 19.99,
    "currency": "USD",
    "discount_type": "percentage",
    "discount_amount": 33.0,
    "start_date": "2026-09-01",
    "end_date": "2026-09-30",
    "sku": "PRD-001",
    "stock_quantity": 100,
    "reserved_quantity": 2,
    "available_stock": 98,
    "quantity": 100,
    "sold_quantity": 25,
    "in_stock": true,
    "status": "publish",
    "product_type": "variable",
    "item_type": "PHYSICAL",
    "height": null, "width": null, "length": null, "weight": null,
    "has_flash_sale": false,
    "has_discount": true,
    "discount_valid": true,
    "is_fast_shipping_available": false,
    "tax_enabled": false, "tax_rate": null,
    "categories": [{ "id": 2, "name": "Clothing", "slug": "clothing" }],
    "flash_sales": [],
    "brands": [], "banners": [], "sliders": [],
    "tags": [{ "id": 1, "name": "summer", "slug": "summer" }],
    "images": ["https://cdn.example.com/storage/products/1/thumb.jpg", "https://cdn.example.com/storage/products/1/2.jpg"],
    "variants": [
      { "id": 10, "price": 29.99, "current_price": 19.99, "quantity": 50, "height": null, "width": null, "length": null, "weight": null, "attributes": [{ "id": 1, "name": "Color", "value": "Red" }] }
    ],
    "reviews": [{ "id": 5, "rating": 5, "comment": "Great!", "user": { "id": 9, "name": "Ahmed" } }],
    "related_products": [{ "id": 7, "name": "Jeans", "slug": "jeans", "price": 59.99, "image": { "thumbnail": "..." } }],
    "filters": { "price": { "min": 5, "max": 299 }, "brands": [], "categories": [] }
  }
}
```

`filters` is merged only when NOT on `general-product-show` route guard (i.e., hidden on detail in some contexts — current `ProductResource` merges `filters` via `getProductFilters` when `!request()->routeIs('general-product-show')`; in practice detail still includes `filters` via controller-level payload, not resource).

### Response 404

```json
{ "success": false, "message": "MESSAGE.NOT_FOUND" }
```

Caused by unknown slug, soft-deleted product, or channel mismatch (`HasChannelFilter` / `FastShippingScope`).

---

## Import / Export (supplemental — not part of the 3 requested endpoints but share `/products/*` prefix)

| Endpoint | Method | Auth | Permission | Request | Response |
|----------|--------|------|------------|---------|----------|
| `POST /products/import` | multipart `file: xlsx,csv` | `auth:sanctum` | `create-product`/`super_admin` | `ProductImportRequest` | `202 { success:true, message:"Import started", data:{ import_id, status:"pending"} }` |
| `GET /products/import/{id}` | — | `auth:sanctum` | `create-product`/`super_admin` | `id:numeric` | `200 { status,total_rows,processed_rows,success_rows,failed_rows,progress }` — reads signal file |
| `POST /products/import/{id}/cancel` | — | `auth:sanctum` | `create-product`/`super_admin` | `id:numeric` | `200 cancelled` or `409 Import cannot be cancelled` |
| `GET /products/import/{id}/download-errors` | — | `auth:sanctum` | `create-product`/`super_admin` | `id:numeric` | `200 xlsx` or `404 No errors` |
| `GET /products/export` + `POST /products/export` + `GET /products/export/{id}` + `GET /products/export/{id}/download` | varies | `auth:sanctum` | `export-product` | varies | see `ProductExportController` |

---

## Error Contract (all endpoints)

```json
{ "success": false, "message": "MESSAGE.<KEY>", "errors": { "field": ["..."] } }
```

Common `message` keys: `MESSAGE.FETCH_DATA_SUCCESSFULLY`, `CREATE_PRODUCT_SUCCESSFULLY`, `UPDATE_PRODUCT_SUCCESSFULLY`, `DELETE_PRODUCT_SUCCESSFULLY`, `PRODUCTS_DELETED_SUCCESSFULLY`, `NOT_FOUND`.

HTTP codes: `200` success/paginated, `201` created, `202` accepted (import), `401` unauthenticated, `403` forbidden, `404` not found, `409` conflict (cancel completed import), `422` validation.
