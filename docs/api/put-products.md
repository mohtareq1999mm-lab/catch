# Endpoint Name

`PUT|PATCH /api/v1/products/{product}` — update product (`products.update`)

# Description

Admin product update (marvel `apiResource` update, `packages/marvel/src/Rest/Routes.php:267` → `Marvel\Http\Controllers\ProductController@update`, `ProductController.php:364-397`). Same field universe as create but **every rule is `sometimes`** — omitting a group leaves its state untouched; `variants.*.id` identifies existing variant rows. Transactional repository update, placements reload, `FrontendResource::PRODUCTS` flush.

Sibling docs: `get-products.md`, `get-products-show.md`, `post-products.md`; umbrella `admin-products-crud.md`.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| URL | `/api/v1/products/{product}` (numeric id; delete/carrier of the row) |
| Route name | `products.update` |
| Method | `PUT` or `PATCH` (both registered by `apiResource`) |
| Enclosing middleware | `['auth:sanctum', 'throttle:admin', 'lang']` (`Routes.php:135`) |
| Permission | Route OR-gate + constructor STRICT `update-product` (`ProductController.php:111`; `Permission.php:146`) |
| Rate limit / Locale | `throttle:admin` 400/min; `lang` header for messages |
| FormRequest | `Marvel\Http\Requests\ProductUpdateRequest` (`:61-129`) |

## Validated body fields (COMPLETE — differences from create verified)

Rules identical to `post-products.md` except record-verified deltas:

| Field | Create (POST) | Update (PUT/PATCH) |
| --- | --- | --- |
| `name` | required, per-locale unique | **sometimes**; uniqueness ignores the current product (`UniqueTranslationRule …->ignore($this->route('product'))`, `:62`) |
| `description` | required | **sometimes** (`:63-64`) |
| `product_type` | required | **sometimes** (`:65`) |
| `price` | `required_if` simple (dictated by rule text, but effectively optional — see the `post-products.md` validator note) | **identical rule to create**: `['sometimes','numeric','min:0','required_if:product_type,' . SIMPLE]` (`:67`) — omission patches nothing, no validation error |
| `sku` | optional (saved as provided / generated when absent | see `post-products.md` SKU note) | **sometimes**, `nullable`, `string`, `max:255`, `Rule::unique('products','sku')->ignore($this->route('product'))` (`:69`) — provided value PATCHES the row; omission keeps the existing sku; collision with ANOTHER product's sku → **422** |
| `shop_id` | — (not in create rules) | **sometimes**, exists:`shops,id` (`:68`) |
| `variants.*.sale_price` | — (not in create rules) | **sometimes**, `numeric`, `min:0` (`:119`) |
| `categories` / `images` / `in_stock` / `has_discount` / `has_flash_sale` | required (images always) | ALL **sometimes**; `images.*` still `mimes:jpeg,png,jpg,gif max:2048` |

Unchanged rule families (all `sometimes` on update — see `ProductUpdateRequest:61-129`): `item_type`, `status`, `type_id`, `quantity`, `pieces`, dimensions, `is_fast_shipping_available`, `flash_sale_id` + the discount triplet, date pair, `brands`/`tags`/`banners`/`sliders`, `tax_enabled`/`tax_rate`, barcode trio (`UniqueProductBarcode` excluding the current product via `route('product')`), `locations[]`, `variants[]` — **incl. `variants.*.id` (`sometimes`, exists:`product_variants,id`, `:117`) which selects the variant row to update**.

Same validator nuance as create applies here: **any omitted `sometimes` field skips validation entirely** (framework `passesOptionalCheck`) — omission IS the patch semantics of this endpoint, not an error.

## Semantics you must know (code-verified)

- **`name` is whole-array replacement**: when `name` is sent, it is validated and applied as a WHOLE; locales not included in the sent array are lost. Send every locale you want to keep.
- **`slug` regeneration** (updated 2026-11-01): when the request sends `name`, the slug is ALWAYS re-derived from it (`en` locale, else the first locale) with a collision suffix — `BaseRepository::makeSlug:230-246`, invoked at `ProductRepository.php`. A patch WITHOUT `name` **and** WITHOUT `slug` now KEEPS the current slug (regeneration is gated on source presence at `ProductRepository`); previously this shape silently reset the slug to an `auto-generated-string`-derived value. `slug` remains a NON-input field.
- **`sku` patching**: `sku` is now an accepted patch field (same save-if-provided / keep-if-absent contract as create; NOT regenerated on update — the `PRD-###` generation hook only runs on `creating`). Uniqueness excludes the current product via `->ignore($this->route('product'))`.
- **`images`**: optional; absent image groups leave media untouched (differs from create where images are required).
- **Stock**: `stock_quantity` is the products-row authority value; variable products own stock on `product_variants.stock_quantity` (variants own stock — `ProductPlacementsResource` docblock).

## Request example (COMPLETE)

```json
{
  "method": "PUT",
  "url": "/api/v1/products/42",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  },
  "body": {
    "price": 2.75,
    "status": 1,
    "name": { "en": "New Widget Pro", "ar": "عنصر جديد برو" },
    "has_discount": 1,
    "discount_type": "fixed_rate",
    "discount_amount": 0.5,
    "discount_status": 1,
    "tax_enabled": true,
    "tax_rate": 5,
    "images": [ "<file: widget-new.jpg>" ],
    "piece_barcode": "P-WGT-001-B",
    "variants": [
      {
        "id": 88,
        "price": 2.9,
        "quantity": 40,
        "weight": "0.3",
        "attribute_values": [11, 12],
        "locations": [ { "location_id": 3, "quantity": 25, "warehouse_id": 1 } ]
      }
    ],
    "locations": [
      { "location_id": 3, "quantity": 55, "warehouse_id": 1 },
      { "location_id": 4, "quantity": 40, "warehouse_id": 1 }
    ]
  }
}
```

# Responses

## Success — HTTP 200

Flow: `ProductRepository::updateProduct` → reload placements (`loadMissing`, `:369-374`) → flush `FrontendResource::PRODUCTS` → **200**.

Message: `en` → `"Product updated successfully"` (no period), `ar` → `"تم تحديث المنتج بنجاح."`.

Response-key visibility: identical to `post-products.md` (`name`/`description` raw arrays, `images`/`locations`/`variants` present, `tags`/`brands`/`banners`/`sliders`/`categories`/`flash_sales`/`reviews`/`related_products` ABSENT — relations not loaded on write responses).

```json
{
  "status": 200,
  "message": "Product updated successfully",
  "success": true,
  "data": {
    "id": 42,
    "name": { "en": "New Widget Pro", "ar": "عنصر جديد برو" },
    "slug": "new-widget-pro",
    "description": { "en": "Widget description", "ar": "وصف العنصر" },
    "price": "2.75",
    "current_price": "2.25",
    "tax_enabled": true,
    "tax_rate": 5,
    "tax": { "tax_enabled": true, "tax_rate": 5, "amount": 0.11 },
    "price_including_tax": "2.36",
    "price_after_discount": "2.25",
    "price_after_flash_sale": "2.25",
    "discount_type": "fixed_rate",
    "discount_amount": 0.5,
    "discount_valid": true,
    "start_date": null,
    "end_date": null,
    "sku": "WGT-001",
    "stock_quantity": 95,
    "reserved_quantity": 0,
    "available_stock": 95,
    "quantity": 95,
    "sold_quantity": 0,
    "in_stock": 1,
    "status": 1,
    "product_type": "variable",
    "item_type": "PHYSICAL",
    "piece_barcode": "P-WGT-001-B",
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
    "images": [ "http://127.0.0.1:8000/storage/product/widget-new.jpg" ],
    "locations": [
      {
        "id": 71,
        "location_id": 3,
        "warehouse_id": 1,
        "quantity": 55,
        "allocated_hint": 0,
        "location": { "id": 3, "code": "A-01", "barcode": "LOC-A01", "name": "Shelf A-01", "type": "shelf" },
        "warehouse": { "id": 1, "code": "WH1", "name": "Main warehouse" }
      }
    ],
    "variants": [
      {
        "id": 88,
        "price": "2.90",
        "current_price": "2.65",
        "stock_quantity": 40,
        "reserved_quantity": 0,
        "available_stock": 40,
        "quantity": 40,
        "height": "10",
        "width": "5",
        "length": "4",
        "weight": "0.3",
        "locations": [
          {
            "id": 73,
            "location_id": 3,
            "warehouse_id": 1,
            "quantity": 25,
            "allocated_hint": 0,
            "location": { "id": 3, "code": "A-01", "barcode": "LOC-A01", "name": "Shelf A-01", "type": "shelf" },
            "warehouse": { "id": 1, "code": "WH1", "name": "Main warehouse" }
          }
        ],
        "attributes": [ { "attribute_name": "Color", "value": "Red" } ]
      }
    ]
  }
}
```

Sample values are internally-consistent illustrations (not runtime captures): `fixed_rate` 0.50 on `price` 2.75 → `current_price` 2.25; tax at 5% on 2.25 → 0.11 → `price_including_tax` 2.36.

# Errors

All failures → `app/Exceptions/Handler.php` unified contract `{message, status:false, success:false[, errors]}`.

| HTTP status | Trigger | Message (en / ar) | Verification |
| --- | --- | --- | --- |
| 401 | No/invalid token | `Unauthenticated.` / `غير مصرح. يرجى تسجيل الدخول.` | `Handler.php:168-171` |
| 403 | No `update-product` (route or constructor gate) | `User does not have the required permissions.` / `لا يمتلك المستخدم الصلاحيات المطلوبة.` | Spatie path (`Handler.php:134-141,349`) |
| 403 | Repository authorization denial (`ProductRepository::updateProduct` → AuthorizationException rethrow) | `This action is unauthorized.` / `هذا الإجراء غير مصرح به.` | `ProductController.php:394-396` → `Handler.php:173-175` |
| 404 | Unknown `{product}` id inside the repository flow (model lookup misses) | `Resource Not Found` / `المورد غير موجود.` (ModelNotFoundException branch) or `Not found` (`MarvelException(NOT_FOUND)` branch) | `Handler.php:107-109` / `:202-203` |
| 422 | Validation failure | `The given data was invalid.` / `البيانات المُدخلة غير صحيحة.` + `errors` keyed by field | `Handler.php:178-186` |
| 429 | `throttle:admin` | `Too many requests. Please try again later.` / `طلبات كثيرة جدًا. يرجى المحاولة لاحقًا.` | Verified |
| 500 | Repository failure wrapped as `MarvelException(COULD_NOT_UPDATE_THE_RESOURCE)` | `Could not update the resource` / `تعذر تحديث المورد` | `ProductController.php:377-378`; `Handler.php:207-208` |

# Invariants & notes

- **Whole-array `name`**: partial locale arrays REPLACE the map — the update contract is not a per-field patch for translatable columns.
- **Variant updates**: identify rows by `variants.*.id`; a variant sent WITHOUT `id` is treated per the repository contract (do not assume create-vs-update without verifying the repository flow for your case).
- **Cache**: the PRODUCTS tag flush happens after the repository transaction — failed updates leave no cached-state divergence.
- **404 duality**: the update failure path distinguishes `Resource Not Found` (ModelNotFound branch) from `Not found` (MarvelException branch) depending on where the miss occurs; both are 404.

# Verification source

`php artisan route:list --path=products` (products.update action); `Routes.php:135,267`; `Marvel\Http\Controllers\ProductController.php` (:111, :364-397); `packages/marvel/src/Http/Requests/ProductUpdateRequest.php` (:61-129); `products/ProductResource.php` (:17-151); `BaseRepository::makeSlug` (:230-246) + `ProductRepository.php` (:186, :692-701); `App\Services\Tax\ProductTaxPresenter.php`; `app/Exceptions/Handler.php`; `packages/marvel/config/constants.php`; `resources/lang/en|ar/message.php`.
