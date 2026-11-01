# Endpoint Name

`GET /api/v1/products/{product}` — single admin product (`products.show`)

# Description

Admin detail view for one product (marvel `apiResource` show, `packages/marvel/src/Rest/Routes.php:267` → `Marvel\Http\Controllers\ProductController@show`, `ProductController.php:317-355`). Resolves by **id OR slug**, eager-loads the full relation set and attaches `related_products`.

Sibling docs: `get-products.md` (list), `post-products.md` (create), `put-products.md` (update); umbrella `admin-products-crud.md`.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| URL | `/api/v1/products/{product}` |
| Route name | `products.show` |
| Path composition | `Route::prefix('api/v1')` (`RestAPIServiceProvider.php:29-31`) + `apiResource('products')` (`Routes.php:267`) |
| Group middleware | `['auth:sanctum', 'throttle:admin', 'lang']` (`Routes.php:135`) |
| Controller | `Marvel\Http\Controllers\ProductController` |
| `{product}` path parameter | **Resolves as `id` OR `slug`** — `where('id', $id)->orWhere('slug', $id)->firstOrFail()` (`:338-341`). No numeric route constraint is applied on this apiResource line. Unknown → 404. |
| Permission | Route OR-gate (5 product permissions) + constructor STRICT `view-products` for show (`ProductController.php:109`) — `view-product` alone is **403** |
| Rate limit / Locale | `throttle:admin` 400/min; `lang` header selects message locale |

## Query parameters

| Field | Type | Required | Default | Meaning |
| --- | --- | --- | --- | --- |
| `limit` | integer | No | `10` | Sizes the `related_products` relation (`:337,342`) |

- No body, no other query parameters.

## Eager loads (code-verified, `:345-351`)

Placement chains (`variations.productLocations.location`/`.warehouse`, `productLocations.*`), `categories`, `flash_sales`, `banners`, `sliders`, `brands`, `reviews`, `tags`, `barcodes` — plus `related_products` (`repository->fetchRelated($product->id, $limit)` attached as relation, `:342-343`).

## Request example

```json
{
  "method": "GET",
  "url": "/api/v1/products/42",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

```json
{
  "method": "GET",
  "url": "/api/v1/products/new-widget",
  "headers": { "Authorization": "Bearer 1|abcdef123456", "Accept": "application/json" }
}
```

# Responses

## Success — HTTP 200

Message: `en` → `"Data fetched successfully"` / `ar` → `"تم جلب البيانات بنجاح"`. `data` = one **complete** `ProductResource` row — RELEATIONS ARE LOADED, so relation blocks appear (unlike list/create/update rows: `whenLoaded` gating, `ProductResource.php:56-67`).

On show, `name`/`description` come as the **raw per-locale arrays** (`getRawOriginal`, `:19,21`).

```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": {
    "id": 42,
    "name": { "en": "New Widget", "ar": "عنصر جديد" },
    "description": { "en": "Widget description", "ar": "وصف العنصر" },
    "slug": "new-widget",
    "price": "2.50",
    "current_price": "2.25",
    "tax_enabled": true,
    "tax_rate": 5,
    "tax": { "tax_enabled": true, "tax_rate": 5, "amount": 0.11 },
    "price_including_tax": "2.36",
    "price_after_discount": "2.25",
    "price_after_flash_sale": "2.25",
    "discount_type": "percentage",
    "discount_amount": 10,
    "discount_valid": true,
    "start_date": "2026-11-01",
    "end_date": "2026-11-15",
    "sku": "WGT-001",
    "stock_quantity": 100,
    "reserved_quantity": 0,
    "available_stock": 100,
    "quantity": 100,
    "sold_quantity": 0,
    "in_stock": 1,
    "status": 1,
    "product_type": "simple",
    "item_type": "PHYSICAL",
    "piece_barcode": "P-WGT-001",
    "carton_barcode": "C-WGT-001",
    "pieces_per_carton": 6,
    "height": "10",
    "width": "5",
    "length": "4",
    "weight": "0.3",
    "has_flash_sale": 0,
    "has_discount": 1,
    "is_fast_shipping_available": true,
    "tags": [
      { "id": 7, "name": "best-seller", "slug": "best-seller", "image": null, "icon": null }
    ],
    "brands": [
      {
        "id": 1,
        "name": "Meem",
        "slug": "meem",
        "image": { "desktop": "…", "mobile": "…" },
        "details": "…",
        "status": true
      }
    ],
    "banners": [ "<BannerResource row>" ],
    "sliders": [ "<SliderResource row>" ],
    "created_at": "2026-11-01T09:15:00.000000Z",
    "categories": [ { "id": 3, "name": "Grocery", "slug": "grocery" } ],
    "flash_sales": [ "<FlashSaleResource row>" ],
    "reviews": [ "<ReviewResource row>" ],
    "images": [ "http://127.0.0.1:8000/storage/product/widget-1.jpg" ],
    "locations": [
      {
        "id": 71,
        "location_id": 3,
        "warehouse_id": 1,
        "quantity": 60,
        "allocated_hint": 0,
        "location": { "id": 3, "code": "A-01", "barcode": "LOC-A01", "name": "Shelf A-01", "type": "shelf" },
        "warehouse": { "id": 1, "code": "WH1", "name": "Main warehouse" }
      }
    ],
    "variants": [],
    "related_products": [ "<ProductResource rows — abridged single-locale rows>" ]
  }
}
```

Relation-sub-shape facts (verified from the individual resources):

| Block | Shape (verified / abridged) |
| --- | --- |
| `categories[]` | `{id, name (locale string), slug}` — flattened, NOT a CategoryResource (`:72-81`) |
| `tags[]` (`TagResource`) | `{id, name (locale string), slug, image (URL \| null), icon (URL \| null)}` — `products` sub-block omitted unless that relation is loaded |
| `brands[]` (`BrandResource`) | `{id, name (locale string), slug, image:{desktop, mobile}, details (locale string), status (bool)}` |
| `variants[]` | `{id, price, current_price, stock_quantity, reserved_quantity, available_stock, quantity, height, width, length, weight, locations[], attributes:[{attribute_name, value}]}` (`:101-122`) |
| `locations[]` | `{id, location_id, warehouse_id, quantity, allocated_hint, location{id, code, barcode, name, type}, warehouse{id, code, name}}` (`:124-151`) |
| `images[]` | plain URL strings (media collection `products`) |
| `banners[]`/`sliders[]`/`flash_sales[]`/`reviews[]`/`related_products[]` | their own resources (`packages/marvel/src/Http/Resources/{Banner,Slider,FlashSale,Review}Resource.php`, `related_products` = `ProductResource` rows) — abridged in the sample above on purpose |

# Errors

| HTTP status | Trigger | Message (en / ar) | Verification |
| --- | --- | --- | --- |
| 401 / 403 / 429 | Same shared matrix as `get-products.md` | — | Verified there |
| 404 | Unknown id/slug value | `Not found` / `غير موجود` — the ` MarvelException(NOT_FOUND)` branch maps to 404 | `Handler.php:202-203`, `ProductController.php:322-323`; exceptions from `fetchSingleProduct` (`MarvelNotFoundException`) map through the HttpException/translate path (`Handler.php:111-121,188-192`) to the same text family |

404 body has NO `data` key:

```json
{ "message": "Not found", "status": false, "success": false }
```

# Invariants & notes

- **id-vs-slug ambiguity**: when the requested value could match both columns (e.g. slug `"42"` while id 42 exists), plain `first()` row order decides — no first-match guarantee should be assumed.
- **N+1 awareness**: `fetchRelated` runs its own query; `limit` huge values follow the repository contract (`fetchRelated($id, $limit)` default 10 from `:337`).
- **`tax` object**: same semantics as the list doc (`describe` on `current_price`).
- **Storefront counterpart**: public detail endpoint is `GET /api/v1/general/products/{slug}` — different controller/resource and visibility (published-only).

# Verification source

`php artisan route:list --path=products` (products.show action); `Routes.php:135,267`; `Marvel\Http\Controllers\ProductController.php` (:109, :317-355); `products/ProductResource.php` (:17-160); `TagResource.php` (:16-35); `BrandResource.php` (:15-40); `app/Exceptions/Handler.php` (:105-121, :188-236); `resources/lang/en|ar/message.php`; `resources/lang/en|ar/wms.php` NOT used here (admin placements doc covers those).
