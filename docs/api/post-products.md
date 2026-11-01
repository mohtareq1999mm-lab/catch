# Endpoint Name

`POST /api/v1/products` — create product (`products.store`)

# Description

Admin product creation (marvel `apiResource` store, `packages/marvel/src/Rest/Routes.php:267` → `Marvel\Http\Controllers\ProductController@store`, `ProductController.php:238-307`). Multilingual translatable name/description, simple/variable pricing, discount/flash-sale conditionals, WMS placement hints (optional initial placement fail-fast), barcode uniqueness, image uploads. Transactional repository write, followed by a `FrontendResource::PRODUCTS` cache flush.

Sibling docs: `get-products.md`, `get-products-show.md`, `put-products.md`; umbrella `admin-products-crud.md`.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| URL | `/api/v1/products` |
| Route name | `products.store` |
| Enclosing middleware | `['auth:sanctum', 'throttle:admin', 'lang']` (`Routes.php:135`) |
| Content type | `multipart/form-data` when `images` (files) are sent; JSON otherwise |
| Permission | Route OR-gate + constructor STRICT `create-product` for store (`ProductController.php:110`; `Permission.php:145`) |
| Rate limit / Locale | `throttle:admin` 400/min; `lang` header for messages |
| FormRequest | `Marvel\Http\Requests\ProductCreateRequest` (`:62-130`, rules quoted verbatim below) |

## Validated body fields (COMPLETE — enum values verified)

| Field | Type | Required | Rules |
| --- | --- | --- | --- |
| `name` | per-locale object | **Yes** | `array`; `name.*` required, string, `max:255`, unique across products (`UniqueTranslationRule`) |
| `description` | per-locale object | **Yes** | `description.*` required, string, `max:10000` |
| `product_type` | string | **Yes** | `simple` \| `variable` (`Marvel\Enums\ProductType`) |
| `item_type` | string | No | `PHYSICAL` \| `DIGITAL` (`Marvel\Enums\ItemType`) |
| `price` | numeric | **effective No** — validated only when present (see note below) | verbatim rules: `['sometimes', 'numeric', 'min:0', 'required_if:product_type,' . ProductType::SIMPLE]` (`ProductCreateRequest:66`) |
| `sku` | string \| null | **optional** (see SKU note below) | verbatim rules: `['sometimes', 'nullable', 'string', 'max:255', Rule::unique('products', 'sku')]` (`ProductCreateRequest:70`) — DUPLICATE against an existing product sku → **422** on `sku` |
| `categories` | int[] | **Yes** | `categories.*` exists:`categories,id` |
| `images` | file[] | **Yes** | `images.*` file, `mimes:jpeg,png,jpg,gif`, `max:2048` KB |
| `in_stock` | `1`\|`0` | **Yes** | in:`1,0` |
| `has_discount` | `true,false,1,0` | **Yes** | if `1` → `discount_type` (`percentage`\|`fixed_rate`\|`free_shipping` — `Marvel\Enums\DiscountType`), `discount_amount` (numeric `min:1`), `discount_status` (`1,0`) required |
| `has_flash_sale` | `true,false,1,0` | **Yes** | if `1` → `flash_sale_id` required, exists:`flash_sales,id` |
| `status` | `0`\|`1` | No | — |

### Validator semantics note (vendor-source verified)

`price` carries a `sometimes` rule. Verified from the framework source (`Illuminate\Validation\Validator::passesOptionalCheck`, `Validator.php:774-784`): a field ABSENT from the request data skips ALL of its rules — including implicit requirements like `required_if`. Consequence for this endpoint: **omitting `price` entirely passes validation for ANY `product_type`** (no 422) — `required_if:product_type,simple` only re-runs on the value when the field IS present (then the effective demand is `numeric, min:0`). The repository does not re-enforce a price floor (fillable pass-through, `ProductRepository:51`), so a simple product can be created without `price` through the validator alone.

### SKU semantics (save-if-provided / generate-if-absent)

**Contract** (implemented 2026-11-01): `sku` is a writable API field — whitelist entry in `ProductRepository::WRITABLE_FIELDS`.

| Request state | Result |
| --- | --- |
| `sku` provided | Saved **exactly as provided** (string ≤ 255; uniqueness enforced among other products → **422** `sku` on collision). Model creating-hook does NOT intervene. |
| `sku` absent | The `Product::creating` hook (`packages/marvel/src/Database/Models/Product.php:131-138`) auto-generates `PRD-<max(id)+1, zero-padded>` (e.g. `PRD-042`, no padding beyond 3 digits). |
| `sku: null`? | Valid (nullable) — hit the same auto-generation branch. |

Notes:
- SKU on the 201 payload (`data.sku`) always reflects the SAVED value (provided or generated).
- Uniqueness is a VALIDATION rule, not a DB constraint (`products.sku` = varchar(255) nullable, no unique index in `2020_06_02_051901_create_marvel_tables.php`): model-level generation has no uniqueness check, and imports/inventory flows bypass the request rules.
- Inventory counting columns (`stock_quantity`, `reserved_quantity`, `sold_quantity`) remain outside the request surface (excluded by `WRITABLE_FIELDS`).
| `type_id` | int | No | exists:`types,id` |
| `quantity` / `pieces` | int | No | `min:1` |
| `height`/`length`/`width`/`weight` | string | No | — |
| `brands` | int[] | No | exists:`brands,id` |
| `tags` | int[] \| null | No | exists:`tags,id` |
| `banners` / `sliders` | int[] | No | FK existence |
| `is_fast_shipping_available` | boolean | No | — |
| `tax_enabled` | boolean | No | — |
| `tax_rate` | numeric \| null | No | `min:0`, `max:100` |
| `start_date` / `end_date` | date | No | `end_date` `after_or_equal:start_date` |
| `piece_barcode` / `carton_barcode` | string ≤64 | No | unique across BOTH barcode columns (`App\Rules\UniqueProductBarcode`, 'piece'/'carton' modes) |
| `pieces_per_carton` | int | No | `min:1` |
| `locations[]` | array | No | `locations.*.location_id` required (per row), exists; `locations.*.quantity` required, numeric `min:0`; `locations.*.warehouse_id` nullable |
| `variants[]` | array | No | `variants.*.price` required (per variant), numeric `min:0`; `variants.*.attribute_values` required, array; `variants.*.attribute_values.*` exists:`attribute_values,id`; `variants.*.quantity` `min:0`; `variants.*.weight`/`length`/`width`/`height` optional strings; `variants.*.locations[]` same location-row rules as above |
| `location_id` (+ optional `warehouse_id`) | int | No | **Initial WMS placement** — fail-fast pre-check BEFORE any write (`:243-291`), see conditions below |

### `location_id` pre-check outcomes (before persistence)

| Condition | Result |
| --- | --- |
| Warehouse not resolvable / not placeable (`StockOperationService::resolvePlaceableLocation`) | 422 (service message) |
| User not authorized for the target warehouse (`authorizeStockOperation`) | **403** — plain `AuthorizationException` → message `This action is unauthorized.` / `هذا الإجراء غير مصرح به.` |
| Product variable (`variants[]` non-empty) | 422 `Initial placement is not supported for variable products: receive variant stock separately.` |
| Product digital | 422 `Digital products hold no physical stock.` |

## Request example (COMPLETE)

```json
{
  "method": "POST",
  "url": "/api/v1/products",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  },
  "body": {
    "name": { "en": "New Widget", "ar": "عنصر جديد" },
    "description": { "en": "Widget description", "ar": "وصف العنصر" },
    "product_type": "simple",
    "item_type": "PHYSICAL",
    "price": 2.5,
    "categories": [3],
    "in_stock": 1,
    "has_discount": 1,
    "discount_type": "percentage",
    "discount_amount": 10,
    "discount_status": 1,
    "start_date": "2026-11-01",
    "end_date": "2026-11-15",
    "has_flash_sale": 0,
    "status": 1,
    "quantity": 100,
    "images": [ "<file: widget-1.jpg>", "<file: widget-2.png>" ],
    "brands": [1],
    "tags": [7, 9],
    "tax_enabled": true,
    "tax_rate": 5,
    "is_fast_shipping_available": true,
    "height": "10",
    "width": "5",
    "length": "4",
    "weight": "0.3",
    "piece_barcode": "P-WGT-001",
    "carton_barcode": "C-WGT-001",
    "pieces_per_carton": 6,
    "locations": [
      { "location_id": 3, "quantity": 60, "warehouse_id": 1 },
      { "location_id": 4, "quantity": 40, "warehouse_id": 1 }
    ]
  }
}
```

# Responses

## Success — HTTP 201

Flow: placement pre-check → `ProductRepository::storeProduct` (transactional) → eager-load placement relations (`variations.productLocations.*`, `productLocations.*` — `:251-256`) → flush `FrontendResource::PRODUCTS` tag → **201**.

Message: `en` → `"Product created successfully"` (no period), `ar` → `"تم إنشاء المنتج بنجاح."`.

**Response-key visibility on create** (verified `ProductResource` conditions + `loadMissing`): the `name`/`description` are **raw per-locale arrays** (not a single locale); `images` (direct media access), `locations`, `variants` are present; the relation blocks `tags`, `brands`, `banners`, `sliders`, `categories`, `flash_sales`, `reviews`, `related_products` are **ABSENT** (relations not loaded on write responses — they appear on `get-products-show.md`). `tax` = `{tax_enabled, tax_rate, amount}` or `null` (no tax config).

```json
{
  "status": 201,
  "message": "Product created successfully",
  "success": true,
  "data": {
    "id": 42,
    "name": { "en": "New Widget", "ar": "عنصر جديد" },
    "slug": "new-widget",
    "description": { "en": "Widget description", "ar": "وصف العنصر" },
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
    "images": [
      "http://127.0.0.1:8000/storage/product/widget-1.jpg",
      "http://127.0.0.1:8000/storage/product/widget-2.png"
    ],
    "locations": [
      {
        "id": 71,
        "location_id": 3,
        "warehouse_id": 1,
        "quantity": 60,
        "allocated_hint": 0,
        "location": { "id": 3, "code": "A-01", "barcode": "LOC-A01", "name": "Shelf A-01", "type": "shelf" },
        "warehouse": { "id": 1, "code": "WH1", "name": "Main warehouse" }
      },
      {
        "id": 72,
        "location_id": 4,
        "warehouse_id": 1,
        "quantity": 40,
        "allocated_hint": 0,
        "location": { "id": 4, "code": "A-02", "barcode": "LOC-A02", "name": "Shelf A-02", "type": "shelf" },
        "warehouse": { "id": 1, "code": "WH1", "name": "Main warehouse" }
      }
    ],
    "variants": []
  }
}
```

Simple products always return `"variants": []` (relation loaded, empty). Variable products return variant rows in the `variants[]` shape. `slug` is ALWAYS re-derived from `name` (`en` locale, else the first locale), with a uniqueness suffix — `BaseRepository::makeSlug:230-246`, invoked at `ProductRepository.php:119`; no direct `slug` input is accepted by this route (not in the validated rules). Stock: `stock_quantity` is the products-row authority value; variable products hold stock on `product_variants.stock_quantity` (variants own stock).

# Errors

All failures → `app/Exceptions/Handler.php` unified contract `{message, status:false, success:false[, errors]}`.

| HTTP status | Trigger | Message (en / ar) | Field errors | Verification |
| --- | --- | --- | --- | --- |
| 401 | No/invalid token | `Unauthenticated.` / `غير مصرح. يرجى تسجيل الدخول.` | None | `Handler.php:168-171` |
| 403 | No `create-product` (route gate or constructor) | `User does not have the required permissions.` / `لا يمتلك المستخدم الصلاحيات المطلوبة.` | None | Spatie middleware path (`Handler.php:134-141,349`); display flags OFF |
| 403 | Initial placement denied (warehouse authorization) | `This action is unauthorized.` / `هذا الإجراء غير مصرح به.` | None | plain `AuthorizationException` → `Handler.php:173-175`; `ProductController.php:273` |
| 422 | Validation failure | `The given data was invalid.` / `البيانات المُدخلة غير صحيحة.` | Keyed by field (e.g. `name.en`, `price`, `variants.0.price`, `locations.0.location_id`) | `Handler.php:178-186` |
| 422 | Placement pre-check refusals (variable / digital / placeable-resolve) | Exact service messages (listed above); no `errors` key (plain apiResponse) | None | `ProductController.php:275-291` |
| 429 | `throttle:admin` | `Too many requests. Please try again later.` / `طلبات كثيرة جدًا. يرجى المحاولة لاحقًا.` | None | Verified |
| 500 | Repository/service failure | `Something went wrong.` / `حدث خطأ ما` | None | `:304-306` (MarvelException wrap), `Handler.php:207-208` |

422 example (create with a duplicated per-locale name):

```json
{
  "message": "The given data was invalid.",
  "status": false,
  "success": false,
  "errors": {
    "name.en": ["The name.en has already been taken."]
  }
}
```

# Invariants & notes

- **Flush timing**: the PRODUCTS tag flush happens after the repository transaction returns — a failed create leaves no cached-state divergence.
- **Placement anti-race**: the pre-check is read-only (fail fast); the repository re-validates under lock inside its transaction, so a concurrent location change still fails closed (`:240-242` code comment).
- **Images**: required on CREATE (unlike update). Each file ≤ 2048 KB, `jpeg,jpg,png,gif`.
- **Money**: `price_after_*` fields relate to the discount/flash-sale settings stored with the product; `current_price` is the effective PRE-tax price.

# Verification source

`php artisan route:list --path=products` (products.store action); `Routes.php:135,267`; `Marvel\Http\Controllers\ProductController.php` (:110, :238-307); `packages/marvel/src/Http/Requests/ProductCreateRequest.php` (:62-130); `products/ProductResource.php` (:17-151); `BaseRepository::makeSlug` (:230-246) + `ProductRepository.php` (:119, :692-701); `App\Services\Tax\ProductTaxPresenter.php`; `App\Services\Inventory\StockOperationService` (resolvePlaceableLocation/authorizeStockOperation call sites); `app/Exceptions/Handler.php`; `packages/marvel/config/constants.php`; `resources/lang/en|ar/message.php`.
