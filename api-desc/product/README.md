# Product Module — API Documentation

> Dual-surface: Admin `Route::apiResource('products', ProductController::class)` (`packages/marvel/src/Rest/Routes.php:236`) + Storefront `GET /products` / `GET /products/{slug}` (`routes/api.php:80-84`). Verified 2026-09-13.

## Overview

The Product module manages the entire product catalog including simple and variable products, `item_type` (`PHYSICAL|DIGITAL`), multi-currency pricing with discounts/flash sales/tax, inventory reservations, Spatie MediaLibrary images, categories/brands/banners/sliders/tags/ratings, variants with `AttributeProduct` pivots, and curated listing strategies via `ProductEngine`. Distributed over two surfaces: **Admin Marvel kernel** (CRUD, import/export, bulk ops) and **Storefront `General\ProductController`** (public, cached, channel-scoped, Scout-capable listing + slug detail).

## Key Files

| File | Purpose |
|------|---------|
| `packages/marvel/src/Http/Controllers/ProductController.php` | Admin 5 `apiResource` endpoints + `destroyBulk/destroyAll` (permission via constructor 108-111) |
| `packages/marvel/src/Database/Repositories/ProductRepository.php` | `storeProduct`/`updateProduct` (transaction, `ProductPricingService`, `syncRelation`, `addVariants`, `customSlugify`) |
| `packages/marvel/src/Http/Requests/ProductCreateRequest.php` | Create validation (`UniqueTranslation` on `name`, `in:PHYSICAL,DIGITAL` on `item_type`, conditional `discount_*`/`flash_sale_id`) |
| `packages/marvel/src/Http/Requests/ProductUpdateRequest.php` | Update validation (`sometimes`, unique ignores current id, `item_type` immutability after order/digital-asset linkage) |
| `packages/marvel/src/Http/Resources/product/ProductResource.php` | Admin response (40+ fields, `tax/price_including_tax` via `ProductTaxPresenter`, `whenLoaded` relations) |
| `Marvel\Http\Resources\product\ProductCollection` | Admin paginated wrapper |
| `app/Http/Controllers/Api/General/ProductController.php` | Storefront `index` + `getProductBySlug` (public, `HasCache`, `currencyAwareCacheKey`, strategy vs fallback) |
| `app/Services/General/ProductService.php` | Storefront service: `buildScoutSearchQuery`, `buildFilteredBaseQuery`, `applyProductFilters` (dimension/rating), `getDynamicFilters` facets, `getLimit 1..100` |
| `app/Services/General/ProductEngine/ProductStrategyResolver.php` | Resolves `type=>Strategy` (`index, best_product_sales, brands_product, new_arrivals, all_product_discounts, product_discount_today_or_low_qty, flash_sales_product, flash_sales_end_today, flash_sales_end_week, product_for_parent_category`) |
| `app/Http/Resources/Product/ProductMiniResource.php` | Storefront listing card (`ConvertsProductPrice`, `HasProductFilters`, `image[thumbnail,original]`) |
| `app/Http/Resources/Product/ProductResource.php` | Storefront detail (`convertCatalogPrice` + `effectiveCurrency`, `getVariants`, `filters` merge) |
| `app/Http/Requests/ProductIndexRequest.php` | Validates `type in supportedTypes` + `order in asc,desc` |
| `packages/marvel/src/Database/Models/Product.php` | Model — 59 fillable, `SoftDeletes`, `HasTranslations(name,description)`, global `FastShippingScope`, casts booleans/integers/floats |
| `packages/marvel/src/Database/Models/ProductVariant.php` | Variant model (`sku UNIQUE`, price/sale_price, dims, belongsTo product) |
| `app/Services/Tax/ProductTaxPresenter.php` | `describe` + `applyTo` for `tax` + `price_including_tax` |
| `app/Services/General/ProductFilter.php` | `scopeFilter` for category/banner/promotion/flash_sale/slider/tag/tags/status/date_range/price |
| `Marvel\Http\Controllers\ProductImportController.php` + `ProductExportController.php` | Import/export jobs (`ImportProductsJob` queue high/medium, signal files) |
| `Marvel\Database\Models\Tag` + `TagController/TagResource` | Tag CRUD, `product_tag` pivot, MediaLibrary on `tags` |

## Permissions

| Permission | Methods | Surface |
|------------|---------|---------|
| `view-products` | `products.index`, `products.show` | Admin `apiResource` |
| `create-product` | `products.store`, `import:*` | Admin |
| `update-product` | `products.update` | Admin |
| `delete-product` | `products.destroy`, `destroyBulk`, `destroyAll` | Admin |
| `import-product` / `export-product` | import/export | Admin |
| `view-low-stock-products`, `view-draft-products` | dashboard scopes | Admin |
| *(none)* | `GET v1/general/products`, `GET v1/general/products/{slug}` | Storefront — public, `throttle:public-api` only |
| `delete-reviews`, `approve-reviews` | review `destroy`, `toggle-approve` | Reviews |

`view-products` etc. are enforced via `Marvel\ProductController::__construct` `permission:` middleware, not route file. Missing permission → `403`.

## Routes

### Admin — `Route::apiResource('products', ProductController::class)` — `packages/marvel/src/Rest/Routes.php:236`

Grouped `middleware: auth:sanctum, throttle:admin` → `api/v1`. See `api.md` for full bespoke `/products/*` table (bulk-delete, all, import/export, digital-assets declared **before** the `apiResource` so static segments are not captured by `{product}`).

| Method | URI | Name | Controller@function | Auth | Purpose |
|--------|-----|------|---------------------|------|---------|
| `GET` | `/api/v1/products` | `products.index` | `Marvel\ProductController@index` | `auth:sanctum` | Admin paginated listing (filters, search, Scout) |
| `POST` | `/api/v1/products` | `products.store` | `Marvel\ProductController@store` | `auth:sanctum` | Create (multipart, Spatie Media, pricing) |
| `GET` | `/api/v1/products/{product}` | `products.show` | `Marvel\ProductController@show` | `auth:sanctum` | Show by id or slug |
| `PUT\|PATCH` | `/api/v1/products/{product}` | `products.update` | `Marvel\ProductController@update` | `auth:sanctum` | Update (sometimes, tags replacive) |
| `DELETE` | `/api/v1/products/{product}` | `products.destroy` | `Marvel\ProductController@destroy` | `auth:sanctum` | Soft-delete |

Also: `POST /products/bulk-delete` (`{ids:[]}` hard delete), `DELETE /products/all` (hard delete all), `.../import/*`, `.../export/*`, `.../digital-assets`.

### Storefront — `routes/api.php` `v1/general`

| Method | URI | Controller@function | Auth | Purpose |
|--------|-----|---------------------|------|---------|
| `GET` | `/api/v1/general/products` | `General\ProductController@index` | public `throttle:public-api` | Public listing — strategy or Scout/DB fallback, cached per currency, `filters` + `categories` facets, `ProductCollectionMini` |
| `GET` | `/api/v1/general/products/{slug}` | `General\ProductController@getProductBySlug` | public `throttle:public-api` | Public detail by slug — `ProductResource` (full relations, currency-converted, channel-scoped) |

Reviews (storefront auth): `POST /api/v1/general/products/{id}/reviews`, `PUT /api/v1/general/products/reviews/{id}` (`throttle:authenticated`).

### GET /products — Query Parameters (Admin `products.index`)

| Field | Type | Default | Description |
|-------|------|---------|-------------|
| `page` | int | 1 | Page number |
| `limit` | int | 15 | Results per page (clamped 1..100) |
| `search` | string | — | Translatable `name`/`description`, `sku`, variant SKUs (Scout when enabled) |
| `sort` | string | `desc` | Legacy `created_at` direction |
| `orderBy` | string | `created_at` | `created_at, updated_at, name, price, sold_quantity, sku, id` |
| `orderDir` | string | `desc` | `asc,desc` |
| `date_range` | string | — | `YYYY-MM-DD//YYYY-MM-DD` |
| `status` | int | — | `0,1` |
| `category` | string | — | Category slug |
| `banner` | string | — | Banner slug |
| `flash_sale` | string | — | Flash-sale slug |
| `promotion` | string | — | Promotion slug |
| `slider` | string | — | Slider slug |
| `tags` | string | — | Comma-separated slug or id, AND logic |

### GET /api/v1/general/products — Query Parameters (Storefront)

| Field | Type | Default | Description |
|-------|------|---------|-------------|
| `limit` | int | 15 | 1..100 |
| `page` | int | 1 | Page |
| `type` | string | — | `ProductIndexRequest` validated: `index, best_product_sales, brands_product, new_arrivals, all_product_discounts, product_discount_today_or_low_qty, flash_sales_product, flash_sales_end_today, flash_sales_end_week, product_for_parent_category`. `type=all` → fallback. |
| `order` | string | `desc` | `asc,desc` (fallback `orderBy id` direction) |
| `order_price` | string | — | `asc,desc` → `orderBy price` before `id` in fallback |
| `search` | string | — | Scout Meilisearch or LIKE fallback on `name/description` + `price/sku` when Scout unavailable |
| `productsId` | string | — | Comma IDs |
| `category / brands / tags` | string | — | Slug/ID lists via `applyRelationIdsFilters` + `ProductFilter` |
| `price_min/max`, `rating_min/max` | numeric | — | Facet ranges |
| `height_min/max`, `width_min/max`, `length_min/max`, `weight_min/max` | numeric | — | Dimension ranges |
| `banner, promotion, flash_sale, slider, status, date_range` | string | — | Proxied to `ProductFilter` |

## Response Shape Reference

**Admin `products.index/show/store/update`:** `Marvel\Http\Resources\product\ProductResource` — see `api.md` for full 40+ field example (`name: {en,ar}, slug, description, price/current_price/price_after_discount/price_after_flash_sale/price_including_tax/tax/tax_enabled/tax_rate, sku, stock_quantity/reserved_quantity/available_stock/quantity/sold_quantity, in_stock, status:publish/draft/..., product_type:simple/variable, item_type:PHYSICAL/DIGITAL, dims, has_flash_sale/has_discount/discount_valid, is_fast_shipping_available, categories/flash_sales/tags/brands/banners/sliders/reviews/images/variants/related_products, created_at`).

**Storefront listing:** `ProductMiniResource` — `id, name(scalar per locale), slug, price, has_variants, item_type, current_price, currency, quantity, in_stock, discount_active/flash_sale_active, is_fast_shipping_available, ratings, tags, image{thumbnail,original[]}` plus envelope `filters{price,bands,categories,tags,ratings}, categories[], links{first,last,prev,next}, meta`.

**Storefront detail:** `App\Http\Resources\Product\ProductResource` — like admin but `convertCatalogPrice` + `effectiveCurrency` on `price/current_price/discount_amount`, `image` vs `images`, `filters` merge, `related_products: ProductMiniResource[]`.

## Additional Endpoints (not the two under review)

| Method | URI | Purpose |
|--------|-----|---------|
| `POST /products/bulk-delete` | Hard delete `whereIn ids` |
| `DELETE /products/all` | Hard delete all (throttled) |
| `GET /products/import/sample` + `GET|POST /products/export*` | Excel/CSV import/export with signal files + jobs |
| `GET /products/{product}/digital-assets` | Digital asset listing per product |
