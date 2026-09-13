# Product Module — QA Test Plan

> Covers Admin `apiResource` (`products.index|store|show|update|destroy`, bulk-delete, destroy-all, import/export) + Storefront (`GET /api/v1/general/products`, `GET /api/v1/general/products/{slug}`) + Reviews. Last verified 2026-09-13.

## Test Files

- `tests/Feature/ProductCrudTest.php` — 62 tests (admin CRUD, bulk, import, reviews, tags) — all passing
- `tests/Feature/ProductTagTest.php` — tags in create/list/show/filter
- `tests/Feature/General/ProductsEndpointTest.php` — storefront listing/detail (public, type filters, slug, currency)
- `tests/Feature/ImportExport/*` — import lifecycle + invariants
- `tests/Unit/ProductPricingServiceTest.php` — pricing math

### 1. Functionality

#### Admin `apiResource`
- ✅ Create simple product with all required fields
- ✅ Create variable product with variants
- ✅ Create product without variants (auto-detects as simple)
- ✅ List products with pagination (`limit`, `page`, `orderBy/orderDir/sort`)
- ✅ Show product by ID
- ✅ Show product by slug
- ✅ Update product name only
- ✅ Update product pricing + discount/flash_sale toggles
- ✅ Update product with new variants (old variants deleted)
- ✅ Delete product (soft delete)
- ✅ Bulk delete products (`POST /products/bulk-delete`)
- ✅ Destroy all products (`DELETE /products/all`)
- ✅ Import: sample download, export, status, cancel, download-errors
- ✅ Digital-assets per product (`/products/{product}/digital-assets`)

#### Storefront (new coverage — see gaps)
- ✅ Public `GET /general/products` returns `ProductCollectionMini` + `filters` + `categories` + `links`
- ✅ `type` strategy resolver: `index, best_product_sales, brands_product, new_arrivals, all_product_discounts, product_discount_today_or_low_qty, flash_sales_product, flash_sales_end_today, flash_sales_end_week, product_for_parent_category` each returns curated set
- ✅ `type=all` ≡ fallback listing
- ✅ Public `GET /general/products/{slug}` returns `App\ProductResource` (currency-converted, channel-scoped)
- ✅ `search` uses Scout when configured else LIKE fallback (name/description/sku/variant sku)
- ✅ Dimension filters (`height_min/max … weight_min/max`) + `rating_min/max` + `price_min/max` via `ProductService`
- ✅ `order_price` asc/desc + `order` asc/desc ordering
- ✅ `currencyAwareCacheKey` separate per currency; `shouldCache:false` when `search` present

#### Reviews
- ✅ List reviews by product (`?product_id`)
- ✅ Show review by ID
- ✅ Toggle review approval
- ✅ Delete review
- ✅ `POST /general/products/{id}/reviews` + `PUT /general/products/reviews/{id}` (auth)

### 2. Validation

#### ProductCreateRequest / ProductUpdateRequest
- ✅ Missing `name` → 422
- ✅ Missing `description` → 422
- ✅ Missing `categories` → 422
- ✅ Missing `images` → 422
- ✅ Invalid `product_type` → 422
- ✅ Invalid `status` → 422
- ✅ Missing `in_stock/has_discount/has_flash_sale` → 422
- ✅ Invalid `item_type` (e.g., `VIRTUAL`) → 422; valid only `PHYSICAL|DIGITAL`
- ✅ `item_type` immutability after order/digital-asset linkage → 422
- ✅ `has_discount=true` but missing `discount_type/discount_amount` → 422
- ✅ `has_flash_sale=true` but missing `flash_sale_id` → 422
- ✅ `variants` missing required `price/quantity/attribute_values` → 422
- ✅ `tags.*` invalid id → 422; `attribute_values.*` invalid → 422
- ✅ `name.en` exceeds 255 → 422
- ✅ `end_date` before `start_date` → 422
- ✅ `discount_type` not in `percentage,fixed_rate,free_shipping` → 422

#### ProductIndexRequest (storefront)
- ✅ `type` not in supportedTypes → 422
- ✅ `order` not in `asc,desc` → 422
- ✅ Invalid `limit` (>100 clamped to 100; <=0 defaults to 15; not 422)

### 3. Authentication & Authorization

- ✅ Guest cannot `POST/PUT/DELETE /api/v1/products` → 401 (admin `auth:sanctum`)
- ✅ Guest cannot `POST /products/bulk-delete`, `DELETE /all`, `import/*` → 401
- ✅ Guest CAN `GET /api/v1/general/products` → 200 (public `throttle:public-api`)
- ✅ Guest CAN `GET /api/v1/general/products/{slug}` → 200 or 404 (public)
- ✅ Guest cannot `POST /general/products/{id}/reviews` → 401 (`throttle:authenticated`)
- ✅ User without `view-products` → GET admin index/show → 403
- ✅ User without `create-product` → POST admin → 403
- ✅ User without `update-product` → PUT admin → 403
- ✅ User without `delete-product` → DELETE/bulk/all → 403

### 4. Edge Cases

- ✅ Show nonexistent admin product → 404
- ✅ Update nonexistent product → 404
- ✅ Delete nonexistent product → 404
- ✅ Unknown slug `GET /general/products/unknown-slug` → 404
- ✅ Soft-deleted product not visible in storefront listing/detail (channel-scoped)
- ✅ Soft-deleted admin `show` → 404 (unless `withTrashed`)
- ✅ Bulk delete with empty `ids:[]` → 422
- ✅ `destroyAll` with no rows → 200 empty
- ✅ Strategy `type` unknown → 422 (storefront); missing type → fallback listing 200

### 5. Resource Structure

- ✅ Admin list/show contain `price/current_price/price_after_discount/price_after_flash_sale/tax/price_including_tax/discount_type/item_type/has_variants` + translation `name`
- ✅ Storefront listing `ProductMiniResource` contains `price/current_price/currency/has_variants/quantity/in_stock/discount_active/ratings/image{thumbnail,original}/tags/filters/categories/links`
- ✅ Storefront detail `App\ProductResource` contains `price/current_price/currency, variants[price,attributes], images[], categories, related_products`
- ✅ Response `message` matches `MESSAGE.*` keys per locale

### 6. Soft Delete / Channel Scope

- ✅ Deleted admin product has `deleted_at` set
- ✅ Deleted product not in admin `index` nor storefront `index`/`{slug}`
- ✅ Channel mismatch (`HasChannelFilter`/`FastShippingScope`) hides product with 404 on slug detail

### 7. Tags / Ratings / Filters

- ✅ Product list includes `tags` array (admin + storefront `ProductCollectionMini`)
- ✅ Create with valid `tags:[id]` → persisted via `product_tag`
- ✅ Filter `?tags=slug` / `?tags=1,2` (AND logic, slug or numeric id) via `ProductFilter`
- ✅ Dynamic facets `filters{price,bands,categories,tags,ratings}` reflect current result set
- ✅ Dimension range filters (`height_min/max … weight_min/max`) + `rating_min/max`

### 8. Cache / Scout

- ✅ Repeated `GET /general/products?limit=15` without `search` → cache hit (`HasCache`)
- ✅ Same query with different `Currency` → different `currencyAwareCacheKey`
- ✅ `GET /general/products?search=shirt` → not cached, uses Scout or LIKE fallback

## Coverage Summary

- **Files:** `ProductCrudTest.php` (62), `ProductTagTest.php`, `General\ProductsEndpointTest.php`, `ImportExport\*`, `ProductPricingServiceTest.php`
- **Covers:** Admin CRUD + reviews + import/export + storefront dual-surface + tags + facets
- **Gaps to add:** explicitly test storefront `type` enumeration, `order_price` ordering, Scout fallback toggle, `currencyAwareCacheKey` invariance, `item_type` immutability, `tags replacive` on update, bulk-delete with >100 ids chunking

## Manual QA Checklist

- [ ] Admin `GET /api/v1/products` paginates with `limit` clamped 1..100, `orderBy/orderDir/sort` correct
- [ ] Storefront `GET /general/products?type=best_product_sales&limit=5` returns `filters.categories` + `filters.brands`
- [ ] `GET /general/products/{slug}` for existing slug returns full resource; unknown slug 404
- [ ] `GET /general/products?search=shirt` returns relevant results (via Scout) and is not cached
- [ ] Create `DIGITAL` product then attempt `PUT` to `PHYSICAL` after placing an order → 422 immutability
- [ ] Mutate product then verify `HasCache` invalidated and next storefront listing reflects change
