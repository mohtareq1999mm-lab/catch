# Product Module — Frontend Integration Guide

> Two surfaces: **Admin** (`/api/v1/products` `apiResource` — `auth:sanctum`, `throttle:admin`) + **Storefront** (`/api/v1/general/products` + `/api/v1/general/products/{slug}` — public, `throttle:public-api`, cached, currency-aware). Verified 2026-09-13.

## Endpoints

| Method | Endpoint | Surface | Auth | Purpose |
|--------|----------|---------|------|---------|
| `GET` | `/api/v1/products` | Admin `products.index` | `auth:sanctum` `view-products` | Admin listing (paginated, `orderBy/orderDir`, `ProductFilter`, Scout search) |
| `GET` | `/api/v1/products/{product}` | Admin `products.show` | `auth:sanctum` `view-products` | Admin detail by id/slug (full `ProductResource` with `price_including_tax`) |
| `POST` | `/api/v1/products` | Admin `products.store` | `auth:sanctum` `create-product` | Create (`ProductCreateRequest`, multipart `images`) |
| `PUT\|PATCH` | `/api/v1/products/{product}` | Admin `products.update` | `auth:sanctum` `update-product` | Update (`sometimes`, replacive `tags`) |
| `DELETE` | `/api/v1/products/{product}` | Admin `products.destroy` | `auth:sanctum` `delete-product` | Soft-delete |
| `POST` | `/api/v1/products/bulk-delete` | Admin | `auth:sanctum` `delete-product` | Hard bulk delete `{ids:[]}` |
| `DELETE` | `/api/v1/products/all` | Admin | `auth:sanctum` `delete-product` | Hard delete all |
| `GET` | `/api/v1/general/products` | **Storefront** `General\ProductController@index` | public `throttle:public-api` | **Public listing** — `ProductCollectionMini` + `filters` + `categories` facets, strategy/Scout/DB, cached per currency |
| `GET` | `/api/v1/general/products/{slug}` | **Storefront** `getProductBySlug` | public `throttle:public-api` | **Public detail** by `slug` — `App\ProductResource` (full relations, currency-converted) |
| `POST` | `/api/v1/products/import` | Admin | `auth:sanctum` | Upload `file` (xlsx/csv) → `202 {import_id}` |
| `GET` | `/api/v1/products/import/{id}` | Admin | `auth:sanctum` | Import status poll |
| `POST` | `/api/v1/products/import/{id}/cancel` | Admin | `auth:sanctum` | Cancel pending import |
| `GET` | `/api/v1/products/import/{id}/download-errors` | Admin | `auth:sanctum` | Download error xlsx |
| `GET` | `/api/v1/products/export*` | Admin | `auth:sanctum` | Export jobs |
| `GET\|POST\|PUT\|DELETE\|PATCH` | `/api/v1/reviews...` | Both | mixed | Product reviews (see `ReviewController`) |

## Response Structure

### Admin List Response — `GET /api/v1/products`

```json
{
  "success": true,
  "message": "MESSAGE.FETCH_DATA_SUCCESSFULLY",
  "data": {
    "data": [{ "id": 1, "name": { "en": "T-Shirt", "ar": "تيشيرت" }, "slug": "t-shirt", "price": 29.99, "current_price": 19.99, "price_including_tax": 19.99, "tax": null, "item_type": "PHYSICAL", "has_variants": false, "in_stock": true, "images": ["...thumb.jpg"], "tags": [{"id":1,"name":"summer","slug":"summer"}] }],
    "current_page": 1, "per_page": 15, "total": 72, "last_page": 5, "from": 1, "to": 15
  }
}
```

### Storefront Listing Response — `GET /api/v1/general/products`

```json
{
  "success": true,
  "message": "MESSAGE.FETCH_DATA_SUCCESSFULLY",
  "data": {
    "data": [{ "id": 1, "name": "T-Shirt", "slug": "t-shirt", "price": 29.99, "current_price": 19.99, "currency": "USD", "item_type": "PHYSICAL", "quantity": 100, "in_stock": true, "discount_active": true, "flash_sale_active": false, "ratings": 4.35, "tags": [], "image": { "thumbnail": "...thumb.jpg", "original": ["...2.jpg"] } }],
    "current_page": 1, "per_page": 15, "total": 72,
    "links": { "first": "/api/v1/general/products?page=1", "last": "/api/v1/general/products?page=5", "prev": null, "next": "/api/v1/general/products?page=2" },
    "filters": { "price": { "min": 5, "max": 299 }, "brands": [{"id":3,"name":"Nike","count":12}], "categories": [{"id":2,"name":"Clothing","count":34}], "tags": [{"id":1,"name":"summer","count":18}], "ratings": { "5": 41 } },
    "categories": [{ "id": 2, "name": "Clothing", "slug": "clothing" }]
  }
}
```

Use `filters` to render sidebar facets; `categories` to render collection chips. `filters` is server-aggregated via `ProductService::getDynamicFilters` on the *current* result set — re-render on every listing response.

### Storefront Detail Response — `GET /api/v1/general/products/{slug}`

```json
{
  "success": true,
  "message": "MESSAGE.FETCH_DATA_SUCCESSFULLY",
  "data": {
    "id": 1, "name": { "en": "T-Shirt", "ar": "تيشيرت" }, "slug": "t-shirt", "description": { "en": "...", "ar": "..." },
    "price": 29.99, "current_price": 19.99, "currency": "USD",
    "discount_type": "percentage", "discount_amount": 33, "status": "publish",
    "product_type": "variable", "item_type": "PHYSICAL",
    "in_stock": true, "quantity": 100, "stock_quantity": 100,
    "images": ["...1.jpg", "...2.jpg"],
    "variants": [{ "id": 10, "price": 29.99, "current_price": 19.99, "attributes": [{"name":"Color","value":"Red"}] }],
    "categories": [{"id":2,"name":"Clothing","slug":"clothing"}],
    "tags": [{"id":1,"name":"summer","slug":"summer"}],
    "reviews": [{"id":5,"rating":5,"comment":"Great!"}],
    "related_products": [{"id":7,"name":"Jeans","slug":"jeans","price":59.99}],
    "filters": { "price": {"min":5,"max":299} }
  }
}
```

Admin detail (`/api/v1/products/{product}`) instead returns `tax/tax_enabled/tax_rate/price_including_tax/price_after_discount/price_after_flash_sale` and `name` as raw translation object via `Marvel\ProductResource`.

## States — Handling for both surfaces

### Loading
- Admin table / Storefront grid: skeleton cards while `GET` pending
- Slug detail: skeleton hero + gallery placeholders; abort previous fetch on slug change

### Empty
- `data.data: []` + `total: 0` → "No products found" + CTA. For storefront, still render `filters: { price:{min,max}, brands:[], ...}` (empty) — don't crash.

### Error
- `401` Admin only: redirect to `/login`; clear Sanctum token
- `403` Admin only: "You don't have permission" (`view-products` etc.)
- `404` slug detail or admin `show`: slug not found / soft-deleted / channel mismatch (`HasChannelFilter`) → 404 page + suggested products
- `422` validation: field-level errors. Storefront `GET` listing `422` only when `type` invalid (`ProductIndexRequest` `Rule::in(supportedTypes)`) or `order` invalid — show toast "Invalid filter"
- `500` → "Something went wrong" toast + retry button

## Query Parameters

### Storefront `GET /api/v1/general/products` (most frontend usage)

| Param | Type | Default | Notes |
|-------|------|---------|-------|
| `page` | int | 1 | |
| `limit` | int | 15 | 1..100 |
| `type` | string | — | Curated set. Valid: `index, best_product_sales, brands_product, new_arrivals, all_product_discounts, product_discount_today_or_low_qty, flash_sales_product, flash_sales_end_today, flash_sales_end_week, product_for_parent_category`. `type=all` → fallback listing. |
| `order` | string | `desc` | `asc,desc` — fallback `orderBy id` direction |
| `order_price` | string | — | `asc,desc` → price ordering before id |
| `search` | string | — | Debounce 300ms. Uses Scout Meilisearch when available else LIKE fallback. When `search` present the listing is **not cached** (`shouldCache:false`). |
| `productsId` | string | — | Comma IDs |
| `category` | string | — | Slug/ID |
| `brands` | string | — | Comma slug/ids |
| `tags` | string | — | Comma slug/ids (`?tags=summer` or `?tags=1,2`) |
| `price_min/max`, `rating_min/max` | numeric | — | Facet ranges |
| `height_min/max … weight_min/max` | numeric | — | Dimension ranges |
| `banner,promotion,flash_sale,slider,status,date_range` | string | — | Legacy `ProductFilter` keys |

### Admin `GET /api/v1/products`

| Field | Type | Default | Description |
|-------|------|---------|-------------|
| `page` | int | 1 | |
| `limit` | int | 15 | |
| `search` | string | — | Like on translatable `name/description`, `sku`, variant sku |
| `sort` | string | `desc` | Legacy `created_at` |
| `orderBy` | string | `created_at` | `created_at, updated_at, name, price, sold_quantity, sku, id` |
| `orderDir` | string | `desc` | |
| `category/banner/flash_sale/promotion/slider/tags/status/date_range` | string | — | Via `ProductFilter` |

Sync storefront listing filters to URL query string (`?type=...&search=...&category=...`) so back/forward restores faceted view; share URLs encode full filter state.

## Key Considerations

### 1. Translatable Fields
- `name` and `description` are JSON `{ en, ar }`.
- Admin `store` requires both locales; storefront `name` on `ProductMiniResource` uses `getTranslation(locale)` scalar per current `app()->getLocale()`, admin `ProductResource` returns locale-translated or raw object depending on `request()->routeIs('products.index')`.
- Frontend must send both locales on admin create; detail views choose `app.getLocale()` key.

### 2. Product Type vs Item Type
- `product_type: simple|variable` — structural (variants present). Auto-derived server-side but accepted for validation.
- `item_type: PHYSICAL|DIGITAL` — fulfillment nature. Defaults `PHYSICAL`. Rejected `422` if not in `ItemType::getValues()`; update rejected `422` after order/digital-asset linkage.

### 3. Variants
- `variable` products carry `variants[]` each with `price, quantity, sku?, attribute_values:[id...], dims?`
- Admin `update` replacive: sending new `variants` deletes old variants + pivots and recreates — warn before submit.
- Storefront omits raw `variants` on listing (`has_variants` boolean only); detail includes `variants[]` with `convertCatalogPrice` and `attributes[{name,value}]`.

### 4. Images & MediaLibrary
- Admin: `images` multipart `jpeg/png/jpg/gif max 2048` via Spatie MediaLibrary; on update send retained IDs + new files.
- Storefront listing: `image: { thumbnail:getFirstMediaUrl('products'), original:getMediaImages slice(1) }`; detail uses `images: [...]` (all media URLs). Thumbnail is `getFirstMediaUrl`, not `images[0]`.

### 5. Discount vs Flash Sale vs Tax vs Currency
- `has_discount + discount_type/amount/dates` regular; `has_flash_sale + flash_sale_id` curated. `current_price` is effective price; admin also exposes `price_after_discount`, `price_after_flash_sale`, `discount_valid(isDiscountActive)`.
- `tax: describe(product,current_price)` + `price_including_tax: applyTo(...)`
- Storefront: all money fields through `ConvertsProductPrice→effectiveCurrency()` — render `price` + `currency` code together, never assume USD.

### 6. Soft Delete & Channel Scoping
- Admin `destroy` is `SoftDeletes` (no restore endpoint).
- Storefront silently filters soft-deleted + inactive + channel-mismatched products via `active()` + `HasChannelFilter` + `FastShippingScope` — `GET /general/products/{slug}` can 404 for a valid slug when channel context excludes it.

### 7. Search & Cache
- Storefront `search` prefers Scout `Product::search(term)` via Meilisearch; falls back to `applyProductSearch` LIKE on `name/description/price/sku`. Search listings bypass cache. Non-search listings are cached per `currencyAwareCacheKey(Request)` — after import or product mutation dashboard cache is cleared; handle stale-for-TTL by invalidating on mutation.

### 8. Filters & Facets
- Storefront `filters` facet is server-computed on the current result set (`getDynamicFilters(clone query)`). Don't client-aggregate. On `type`-based listings the facet is `whereIn(resultIds)` aggregated. Use it to render counts.

### 9. Caching & Throttle
- Storefront throttled `throttle:public-api` — burst-safe but back off on `429`.
- Admin `throttle:admin` stricter — `destroyAll/bulk-delete` are throttled hard.

### 10. Tags
- Many-to-many via `product_tag`. Filter `?tags=summer` or `?tags=1,2` (AND). Admin `store/update` accepts `tags:[id]`. Both `ProductMiniResource` (listing) and `ProductResource` (detail) expose `tags: TagResource[]`.
