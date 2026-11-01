# Endpoint Name

`GET /api/v1/products` — paginated admin product list (`products.index`)

# Description

Dashboard/admin listing of products (marvel `apiResource` index, `packages/marvel/src/Rest/Routes.php:267` → `Marvel\Http\Controllers\ProductController@index`, `ProductController.php:123-156`). Locale-aware search and ordering, status/kind/category filters, tagged result cache, paginator payload.

Part of the product-surface family — siblings: `get-products-show.md` (single), `post-products.md` (create), `put-products.md` (update), umbrella `admin-products-crud.md` (bulk/export/import/assets/WMS).

# Request

## Request metadata

| Property | Value |
| --- | --- |
| URL | `/api/v1/products` |
| Route name | `products.index` |
| Path composition | marvel provider `Route::prefix('api/v1')` (`packages/marvel/src/Providers/RestAPIServiceProvider.php:29-31`) + `apiResource('products')` (`Routes.php:267`) |
| Group middleware | `['auth:sanctum', 'throttle:admin', 'lang']` (`Routes.php:135`) |
| Controller | `Marvel\Http\Controllers\ProductController` (DI: `ProductRepository`, `SettingsRepository`) |
| Permission | **Two layers**: route OR-gate `permission:view-products\|view-product\|create-product\|update-product\|delete-product` (`:267`); constructor STRICT `view-products` for index/show (`ProductController.php:109`). A user holding only `view-product` passes the gate and is then **403**. |
| Rate limit | `throttle:admin` — 400/min keyed by `user?->id ?? ip` (`app/Providers/AppServiceProvider.php:133`) |
| Locale | `lang` header selects message locale AND the search/ordering translation field (`app()->getLocale()`) |

## Query parameters

| Field | Type | Required | Accepted values | Default | Meaning |
| --- | --- | --- | --- | --- | --- |
| `limit` | integer | No | Positive int (paginator cast) | `15` | Page size |
| `search` | string | No | Free text | — | Locale-aware LIKE on `name`/`description` JSON translations + `sku` + variant `sku` (`ProductController.php:133-173`) |
| `status` | `0`\|`1` | No | Raw equality (`= $request->status`, `:197-199`) | — | Draft/published filter |
| `item_type` | string | No | `PHYSICAL` \| `DIGITAL` (`Marvel\Enums\ItemType`) | — | Product kind filter |
| `category` | string (slug) | No | `whereHas('categories', slug = …)` | — | Category filter |
| `banner` | string (slug) | No | `whereHas('banners', …)` | — | Banner attach filter |
| `flash_sale` | string (slug) | No | `whereHas('flash_sales', …)` | — | Flash-sale filter |
| `slider` | string (slug) | No | `whereHas('sliders', …)` | — | Slider attach filter |
| `sort` | string | No | Lowercased `asc`/`desc` → applied to `created_at` (legacy param) | — | Direction-only sort |
| `orderBy` | string | No | Allowlist `created_at, updated_at, name, price, sold_quantity, sku, id` — anything else ignored (`:137,141`) | `created_at` | Sort column (`name` sorts by `name->locale`) |
| `orderDir` | string | No | Lowercased `asc`/`desc` | `desc` | Sort direction |

Behavior notes (code-verified): eager loads `variations, categories, flash_sales, barcodes` (`:131`); `paginate($limit)->withQueryString()`; results cached under the `FrontendResource::PRODUCTS` tag with key `md5(fullUrl)` (4 h default TTL via `HasCache::remember`, `:153`) — every create/update/delete in this family flushes that tag.

## Request example

```json
{
  "method": "GET",
  "url": "/api/v1/products?limit=10&status=1&orderBy=price&orderDir=asc&search=widget",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success — HTTP 200

Message: `en` → `"Data fetched successfully"`, `ar` → `"تم جلب البيانات بنجاح"` (no trailing period — lang-file verified). `data` from the custom `ProductCollection` (`packages/marvel/src/Http/Resources/product/ProductCollection.php:14-29`): `data.data[]` rows + flattened `links` pagination block.

```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": {
    "data": [
      {
        "id": 42,
        "name": "New Widget",
        "slug": "new-widget",
        "description": "Widget description",
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
        "created_at": "2026-11-01T09:15:00.000000Z",
        "categories": [ { "id": 3, "name": "Grocery", "slug": "grocery" } ],
        "flash_sales": [],
        "images": [ "http://127.0.0.1:8000/storage/product/widget-1.jpg" ],
        "locations": [],
        "variants": []
      }
    ],
    "links": {
      "current_page": 1,
      "from": 1,
      "to": 1,
      "last_page": 1,
      "path": "http://127.0.0.1:8000/api/v1/products",
      "per_page": 10,
      "total": 1,
      "next_page_url": null,
      "prev_page_url": null,
      "last_page_url": "http://127.0.0.1:8000/api/v1/products?page=1",
      "first_page_url": "http://127.0.0.1:8000/api/v1/products?page=1"
    }
  }
}
```

## Row field table — COMPLETE for `products.index` (`ProductResource.php:17-68`, relation availability resolved for this route)

| Field | Type | Notes (list-route specifics) |
| --- | --- | --- |
| `id` | int | — |
| `name` / `description` | string | **Single locale translation** on the list route (`routeIs('products.index')` → `getTranslation('name', app()->getLocale())`, `:19,21`) — NOT the raw array |
| `slug` | string | — |
| `price`, `current_price` | money string | `current_price` = pre-tax effective price (admin semantics, `:12-13`) |
| `tax_enabled` | boolean | — |
| `tax_rate` | float \| null | — |
| `tax` | object \| null | `{tax_enabled, tax_rate, amount}` from `ProductTaxPresenter::describe`; `null` when the product has NO tax configuration at all |
| `price_including_tax` | money string | Tax-inclusive value |
| `price_after_discount`, `price_after_flash_sale` | money string | — |
| `discount_type`, `discount_amount` | mixed | — |
| `discount_valid` | boolean | Merged ONLY when `has_discount` true (`:55`) |
| `start_date`, `end_date` | date \| null | — |
| `sku` | string \| null | Simple SKU |
| `stock_quantity`, `reserved_quantity`, `available_stock` | int | Simple-authority stock, reserved defaults 0 |
| `quantity` | numeric | — |
| `sold_quantity` | int | Defaults 0 |
| `in_stock` | bool-ish | — |
| `status` | int | `0`/`1` |
| `product_type` | string | `simple` \| `variable` |
| `item_type` | string | `PHYSICAL` \| `DIGITAL` |
| `piece_barcode`, `carton_barcode`, `pieces_per_carton` | string/int \| null | From the eager-loaded `barcodes` relation |
| `height`, `width`, `length`, `weight` | string \| null | — |
| `has_flash_sale`, `has_discount` | bool-ish | — |
| `is_fast_shipping_available` | boolean | — |
| `created_at` | ISO-8601 \| null | — |
| `categories` | array | **Flattened shape on this route**: `{id, name (locale), slug}` (`:72-81`) |
| `flash_sales` | array | Loaded on the list route |
| `images` | array of URL strings | Media collection `products` (`:64,94-99`) |
| `locations` | array | Placement hints `{id, location_id, warehouse_id, quantity, allocated_hint, location{…}, warehouse{…}}` (`:124-151`; lazy-loaded per row here) |
| `variants` | array | `whenLoaded('variations')` — loaded on this route; element shape `{id, price, current_price, stock_quantity, reserved_quantity, available_stock, quantity, height, width, length, weight, locations[], attributes[{attribute_name, value}]}` (`:101-122`) |
| — `tags`, `brands`, `banners`, `sliders`, `reviews`, `related_products` | — | **ABSENT on list rows** (`whenLoaded` — relations not eager-loaded by `index`) |

# Errors

All routes in this family render failures through `app/Exceptions/Handler.php` → `{message, status: false, success: false[, errors]}`.

| HTTP status | Trigger | Message (en / ar) | Verification |
| --- | --- | --- | --- |
| 401 | Missing/invalid Sanctum token | `Unauthenticated.` / `غير مصرح. يرجى تسجيل الدخول.` | `Handler.php:168-171` |
| 403 | None of the five product permissions (route gate) | `User does not have the required permissions.` / `لا يمتلك المستخدم الصلاحيات المطلوبة.` | Spatie middleware → `Handler.php:134-141,349`; display flags OFF (`packages/marvel/config/permission.php:139,147`) |
| 403 | Holds another product permission but NOT `view-products` (constructor) | Same Spatie contract | `ProductController.php:109` |
| 429 | `throttle:admin` exceeded | `Too many requests. Please try again later.` / `طلبات كثيرة جدًا. يرجى المحاولة لاحقًا.` | Limiter + lang-file verified |

# Invariants & notes

- **Caching**: identical query URLs hit the tagged cache (`FrontendResource::PRODUCTS`, `md5(fullUrl)`, 4 h). Cache-key poisoning is bounded — filters shape the URL key; an invalid `orderBy` value is silently IGNORED (not an error), falling back to `created_at desc`.
- **Locale coupling**: `search`, the `name` sort field and the `name`/`description` single-locale serialization all follow `lang`.
- **Storefront warning**: this is the ADMIN list (`status=0` rows appear for permitted users). The public catalog is `GET /api/v1/general/products` — a different surface with different visibility rules.
- **The `tax` object**: `current_price` is pre-tax; `tax.amount` is computed on it; `price_including_tax` = `current_price` + `tax.amount` (sample values are illustrative).

# Verification source

`php artisan route:list --path=products` (`products.index` → `Marvel\ProductController@index`); `Routes.php:135,267`; `RestAPIServiceProvider.php:29-31`; `Marvel\Http\Controllers\ProductController.php` (:109-184 middlewares, search, filters, cache); `products/ProductCollection.php`; `products/ProductResource.php` (:17-151); `app/Services/Tax/ProductTaxPresenter.php` (describe/applyTo); `app/Providers/AppServiceProvider.php:133`; `app/Exceptions/Handler.php`; `packages/marvel/config/permission.php`; `resources/lang/en|ar/message.php`.
