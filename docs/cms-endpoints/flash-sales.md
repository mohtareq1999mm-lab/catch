# Flash Sales API

## Overview

The Flash Sales module manages time-limited product discounts with translatable titles/descriptions, type-based pricing (percentage/fixed rate/final price), and product associations.

---

## Database Schema

### `flash_sales` Table

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| `id` | bigint | PK, AUTO_INCREMENT | Unique identifier |
| `title` | json | NOT NULL | Translatable title |
| `slug` | varchar(255) | NOT NULL | URL slug |
| `description` | json | NULLABLE | Translatable description |
| `start_date` | date | NULLABLE | Sale start date |
| `end_date` | date | NULLABLE | Sale end date |
| `type` | varchar(50) | NOT NULL | `percentage`, `fixed_rate`, or `final_price` |
| `discount` | decimal | NOT NULL | Discount value |
| `max_discount_amount` | decimal | NULLABLE | Max discount cap (for percentage type) |
| `status` | tinyint(1) | DEFAULT false | Active/inactive |
| `order` | int(11) | DEFAULT 0 | Sort order for reordering |
| `created_at` | timestamp | NULLABLE | Creation time |
| `updated_at` | timestamp | NULLABLE | Last update |
| `deleted_at` | timestamp | NULLABLE | Soft delete |

### `flash_sale_products` Pivot Table

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| `flash_sale_id` | bigint | FK → flash_sales.id, CASCADE | Flash sale reference |
| `product_id` | bigint | FK → products.id, CASCADE | Product reference |

---

## Response Envelope

All endpoints return:

```json
{
    "status": 200,
    "message": "Translated message string",
    "success": true,
    "data": {}
}
```

---

## Resource Structure

### FlashSaleResource

| Field | Type | Description |
|-------|------|-------------|
| `id` | int | Flash sale ID |
| `title` | string | Title. On `flash-sale.index`: translated via `getTranslation()`. Other routes: raw original JSON. |
| `slug` | string | URL slug |
| `image.desktop` | string\|null | Desktop image URL via `getFirstMediaUrl('flash-sales-desktop')` (null-safe) |
| `image.mobile` | string\|null | Mobile image URL via `getFirstMediaUrl('flash-sales-mobile')` (null-safe) |
| `description` | string | Translated description via `getTranslation()` |
| `start_date` | string | Sale start date (Y-m-d) |
| `end_date` | string | Sale end date (Y-m-d) |
| `status` | bool | Active status (cast to bool) |
| `is_valid` | bool | Whether sale is currently valid via `isValid()` |
| `type` | string | Translated type label via `typeByLang()` |
| `discount` | float | Discount value |
| `max_discount_amount` | float\|null | Max discount cap |
| `created_at` | string | Creation date (Y-m-d format) |
| `products` | array | When `products` relation loaded (`mergeWhen(relationLoaded('products'))`). `ProductResource` collection. |

**Product object within `products`:**
| Field | Type | Description |
|-------|------|-------------|
| `id` | int | Product ID |
| `name` | string | Product name |
| `slug` | string | Product slug |
| `status` | bool | Product active status |
| `image.thumbnail` | string | Product thumbnail URL |

**Example:**
```json
{
    "id": 1,
    "title": "Summer Sale",
    "slug": "summer-sale",
    "image": {
        "desktop": "http://localhost:8000/public/storage/flash-sales/1/desktop.jpg",
        "mobile": "http://localhost:8000/public/storage/flash-sales/1/mobile.jpg"
    },
    "description": "Up to 50% off on summer collection",
    "start_date": "2026-06-01",
    "end_date": "2026-06-30",
    "status": true,
    "is_valid": true,
    "type": "Percentage discount",
    "discount": 50.00,
    "max_discount_amount": 200.00,
    "created_at": "2026-05-15"
}
```

---

## Endpoints

### GET /flash-sale — List Flash Sales

**Purpose:** List all flash sales with optional filtering.

**Method:** `GET`

**URL:** `/flash-sale`

**Authentication:** No (public route)

**Query Parameters:**

| Field | Type | Default | Description |
|-------|------|---------|-------------|
| `page` | int | 1 | Page number |
| `per_page` | int | 10 | Results per page (alias: `limit`) |
| `limit` | int | 10 | Results per page (alias: `per_page`) |
| `active` | bool | — | Filter currently active sales |
| `inactive` | bool | — | Filter expired/inactive sales |
| `search` | string | — | Search by title (translatable) |
| `order` | string | — | Field to sort by. Allowed: `id`, `title`, `slug`, `type`, `discount`, `status`, `start_date`, `end_date`, `created_at`, `updated_at` |
| `sortedBy` | string | `asc` | Sort direction (`asc` or `desc`). Only applies when `order` is set. |

**Example Usage:**
```
GET /flash-sale?page=2&per_page=20          # Page 2, 20 per page
GET /flash-sale?order=start_date&sortedBy=desc  # Newest first
GET /flash-sale?order=discount&sortedBy=desc    # Highest discount first
GET /flash-sale?active=true                 # Only active sales
GET /flash-sale?search=summer               # Search by title
```

**Business Logic:**
1. Applies optional filters (active, inactive, search)
2. If `order` is a valid field, applies `orderBy($order, $sortedBy)`
3. Paginates with given limit (supports `per_page` and `limit` aliases)
4. Returns paginated `FlashSaleResource` collection

**Success Response (200):**
```json
{
    "status": 200,
    "message": "Data fetched successfully",
    "success": true,
    "data": {
        "data": [
            {
                "id": 1,
                "title": "Summer Sale",
                "slug": "summer-sale",
                "image": {
                    "desktop": "http://localhost:8000/public/storage/flash-sales/1/desktop.jpg",
                    "mobile": "http://localhost:8000/public/storage/flash-sales/1/mobile.jpg"
                },
                "description": "Up to 50% off on summer collection",
                "start_date": "2026-06-01",
                "end_date": "2026-06-30",
                "status": true,
                "is_valid": true,
                "type": "Percentage discount",
                "discount": 50.00,
                "max_discount_amount": 200.00,
                "created_at": "2026-05-15"
            }
        ],
        "page": 1,
        "current_page": 1,
        "from": 1,
        "to": 10,
        "last_page": 1,
        "path": "https://api.example.com/flash-sale",
        "per_page": 10,
        "total": 1,
        "next_page_url": null,
        "prev_page_url": null,
        "last_page_url": "https://api.example.com/flash-sale?page=1",
        "first_page_url": "https://api.example.com/flash-sale?page=1"
    }
}
```

*All fields are returned on all routes — no conditional hiding.*

**Error Responses:**
| Status | Condition |
|--------|-----------|
| 500 | Server error |

---

### POST /flash-sale — Create Flash Sale

**Purpose:** Create a new flash sale with image and optional product associations.

**Method:** `POST`

**URL:** `/flash-sale`

**Authentication:** Required

**Permissions:** `create-flash-sale`

**Request Body (multipart/form-data):**

| Field | Type | Required | Validation |
|-------|------|----------|------------|
| `title` | object | **Yes** | Translatable array |
| `title.*` | string | **Yes** | `string`, `min:3`, `max:70`, unique translation |
| `description` | object | **Yes** | Translatable array |
| `description.*` | string | **Yes** | `string`, `max:1000` |
| `image-desktop` | file | **Yes** | `image`, `mimes:jpeg,png,jpg,webp` |
| `image-mobile` | file | **Yes** | `image`, `mimes:jpeg,png,jpg,webp` |
| `start_date` | date | **Yes** | Valid date |
| `end_date` | date | **Yes** | Valid date |
| `type` | string | **Yes** | `Rule::in(FlashSaleType::getValues())` — `percentage`, `fixed_rate`, `final_price` |
| `discount` | numeric | **Yes** | `min:0` |
| `max_discount_amount` | numeric | If type=percentage | `min:1` |
| `status` | int | **Yes** | `in:1,0` |
| `products` | array | No | Array of product IDs |
| `products.*` | int | No | `exists:products,id` |

**Example Request:**
```json
{
    "title": {
        "en": "Summer Sale",
        "ar": "تخفيضات الصيف"
    },
    "description": {
        "en": "Up to 50% off on summer collection",
        "ar": "خصم يصل إلى 50% على مجموعة الصيف"
    },
    "start_date": "2026-06-01",
    "end_date": "2026-06-30",
    "type": "percentage",
    "discount": 50,
    "max_discount_amount": 200,
    "status": 1,
    "products": [2, 11, 14]
}
```

**Business Logic:**
1. Validates via `CreateFlashSaleRequest`
2. Generates slug from title
3. Creates flash sale record
4. Uploads desktop/mobile images to `flash-sales-desktop` / `flash-sales-mobile` collections
5. Syncs product associations if provided via `products()->sync()`
6. Calls `setProductInFlashSale()` to toggle `in_flash_sale` on synced products
7. Returns created flash sale with loaded products

**Success Response (201):**
```json
{
    "status": 200,
    "message": "Flash sale created successfully",
    "success": true,
    "data": {
        "id": 1,
        "title": "Summer Sale",
        "slug": "summer-sale",
        "image": {
            "desktop": "http://localhost:8000/public/storage/flash-sales/1/desktop.jpg",
            "mobile": "http://localhost:8000/public/storage/flash-sales/1/mobile.jpg"
        },
        "description": "Up to 50% off on summer collection",
        "start_date": "2026-06-01",
        "end_date": "2026-06-30",
        "status": true,
        "is_valid": true,
        "type": "Percentage discount",
        "discount": 50.00,
        "max_discount_amount": 200.00,
        "created_at": "2026-05-15",
        "products": [
            {
                "id": 2,
                "name": "Romaine Lettuce",
                "slug": "romaine-lettuce-380Hw",
                "status": 1,
                "image": {
                    "thumbnail": "http://localhost:8000/public/storage/products/403/thumbnail.jpg"
                }
            }
        ]
    }
}
```

**Error Responses:**
| Status | Condition |
|--------|-----------|
| 401 | Unauthenticated |
| 403 | Missing `create-flash-sale` permission |
| 422 | Validation failure |
| 500 | Server error |

---

### GET /flash-sale/{id} — Show Flash Sale

**Purpose:** Fetch a single flash sale by ID with all details and associated products.

**Method:** `GET`

**URL:** `/flash-sale/{id}`

**Authentication:** No (public route)

**Business Logic:**
1. Finds flash sale by ID with `products` relation loaded
2. Returns complete flash sale resource

**Success Response (200):**
```json
{
    "status": 200,
    "message": "Data fetched successfully",
    "success": true,
    "data": {
        "id": 1,
        "title": "Summer Sale",
        "slug": "summer-sale",
        "image": {
            "desktop": "http://localhost:8000/public/storage/flash-sales/1/desktop.jpg",
            "mobile": "http://localhost:8000/public/storage/flash-sales/1/mobile.jpg"
        },
        "description": "Up to 50% off on summer collection",
        "start_date": "2026-06-01",
        "end_date": "2026-06-30",
        "status": true,
        "is_valid": true,
        "type": "Percentage discount",
        "discount": 50.00,
        "max_discount_amount": 200.00,
        "created_at": "2026-05-15",
        "products": [
            {
                "id": 2,
                "name": "Romaine Lettuce",
                "slug": "romaine-lettuce-380Hw",
                "status": 1,
                "image": {
                    "thumbnail": "http://localhost:8000/public/storage/products/403/thumbnail.jpg"
                }
            }
        ]
    }
}
```

**Error Responses:**
| Status | Condition |
|--------|-----------|
| 404 | Flash sale not found |

---

### PUT /flash-sale/{id} — Update Flash Sale

**Purpose:** Update an existing flash sale's fields, image, and product associations.

**Method:** `PUT`

**URL:** `/flash-sale/{id}`

**Authentication:** Required

**Permissions:** `update-flash-sale`

**Request Body (multipart/form-data):**

| Field | Type | Required | Validation |
|-------|------|----------|------------|
| `title` | object | No | Translatable array |
| `title.*` | string | No | `string`, `min:3`, `max:70`, unique translation (ignores self) |
| `description` | object | No | Translatable array |
| `description.*` | string | No | `string`, `max:1000` |
| `image-desktop` | file | No | `image`, `mimes:jpeg,png,jpg,webp` |
| `image-mobile` | file | No | `image`, `mimes:jpeg,png,jpg,webp` |
| `start_date` | date | No | Valid date |
| `end_date` | date | No | Valid date |
| `type` | string | No | `Rule::in(FlashSaleType::getValues())` — `percentage`, `fixed_rate`, `final_price` |
| `discount` | numeric | No | `min:0` |
| `max_discount_amount` | numeric | If type=percentage | `min:1` |
| `status` | int | No | `in:1,0` |
| `products` | array | No | Array of product IDs |
| `products.*` | int | No | `exists:products,id` |

**Example Request:**
```json
{
    "title": {
        "en": "Winter Sale",
        "ar": "تخفيضات الشتاء"
    },
    "status": 0,
    "products": [5, 21, 35]
}
```

**Business Logic:**
1. Validates via `UpdateFlashSaleRequest`
2. Updates flash sale fields
3. Updates images if provided
4. Syncs product associations via `products()->sync()`
5. Calls `unsetProductFromFlashSale()` on previously synced products no longer in the list
6. Calls `setProductInFlashSale()` on newly synced products
7. Recalculates product prices for affected products via `updateFlashSaleProductPrices()`
8. Returns updated flash sale with loaded products

**Success Response (200):**
```json
{
    "status": 200,
    "message": "Flash sale updated successfully",
    "success": true,
    "data": {
        "id": 1,
        "title": "Winter Sale",
        "slug": "summer-sale",
        "image": {
            "desktop": "http://localhost:8000/public/storage/flash-sales/1/desktop.jpg",
            "mobile": "http://localhost:8000/public/storage/flash-sales/1/mobile.jpg"
        },
        "description": "Up to 50% off on summer collection",
        "start_date": "2026-06-01",
        "end_date": "2026-06-30",
        "status": true,
        "is_valid": true,
        "type": "Percentage discount",
        "discount": 50.00,
        "max_discount_amount": 200.00,
        "created_at": "2026-05-15",
        "products": [
            {
                "id": 5,
                "name": "Running Shoes",
                "slug": "running-shoes",
                "status": 1,
                "image": {
                    "thumbnail": "http://localhost:8000/public/storage/products/403/thumbnail.jpg"
                }
            }
        ]
    }
}
```

**Error Responses:**
| Status | Condition |
|--------|-----------|
| 401 | Unauthenticated |
| 403 | Missing `update-flash-sale` permission |
| 404 | Flash sale not found |
| 422 | Validation failure |
| 500 | Server error |

---

### DELETE /flash-sale/{id} — Delete Flash Sale

**Purpose:** Delete a flash sale (soft delete).

**Method:** `DELETE`

**URL:** `/flash-sale/{id}`

**Authentication:** Required

**Permissions:** `delete-flash-sale`

**Business Logic:**
1. Finds flash sale by ID
2. Soft deletes record

**Success Response (200):**
```json
{
    "status": 200,
    "message": "Flash sale deleted successfully",
    "success": true
}
```

**Error Responses:**
| Status | Condition |
|--------|-----------|
| 401 | Unauthenticated |
| 403 | Missing `delete-flash-sale` permission |
| 404 | Flash sale not found |

---

### GET /products-by-flash-sale — Products by Flash Sale

**Purpose:** Fetch paginated products belonging to a flash sale by slug.

**Method:** `GET`

**URL:** `/products-by-flash-sale`

**Authentication:** Required (super_admin, sanctum, email verified)

**Query Parameters:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `slug` | string | **Yes** | Flash sale slug |
| `per_page` | int | No | Results per page (default: 10, alias: `limit`) |
| `limit` | int | No | Results per page (default: 10, alias: `per_page`) |
| `order` | string | No | Field to sort by. Allowed: `id`, `title`, `slug`, `price`, `sale_price`, `quantity`, `created_at`, `updated_at` |
| `sortedBy` | string | No | Sort direction (`asc` or `desc`, default: `asc`). Only applies when `order` is set. |

**Business Logic:**
1. Finds flash sale by slug via repository
2. Uses BelongsToMany `products()` relationship (no raw joins)
3. Applies `orderBy($order, $sortedBy)` if valid sort field provided
4. Returns paginated product list

**Success Response (200):**
```json
{
    "data": [...],
    "current_page": 1,
    "per_page": 10,
    "total": 5,
    "last_page": 1
}
```

**Example Usage:**
```
GET /products-by-flash-sale?slug=summer-sale&order=price&sortedBy=asc
GET /products-by-flash-sale?slug=summer-sale&per_page=25
```

---

### GET /product-flash-sale-info — Product Flash Sale Info

**Purpose:** Get flash sale information for a specific product.

**Method:** `GET`

**URL:** `/product-flash-sale-info`

**Authentication:** Required

**Permissions:** N/A (admin route)

**Query Parameters:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `id` | int | **Yes** | Product ID |

**Business Logic:**
1. Finds product by ID
2. Returns all flash sales associated with the product

**Success Response (200):**
```json
[
    {
        "id": 1,
        "title": "Summer Sale",
        "slug": "summer-sale",
        ...
    }
]
```

---

### PUT /flash-sale/reorder — Reorder Flash Sales

**Purpose:** Reorder flash sales by providing an ordered array of IDs.

**Method:** `PUT`

**URL:** `/flash-sale/reorder`

**Authentication:** Required

**Permissions:** `update-flash-sale`

**Request Body (JSON):**

| Field | Type | Required | Validation |
|-------|------|----------|------------|
| `flash_sales` | array | **Yes** | Array of flash sale IDs |
| `flash_sales.*` | int | **Yes** | `exists:flash_sales,id` |

**Example Request:**
```json
{
    "flash_sales": [3, 1, 2, 5, 4]
}
```

**Business Logic:**
1. Validates that all IDs exist in `flash_sales` table
2. Calls `SortableTrait::setNewOrder()` to update the `order` column
3. Returns success message

**Success Response (200):**
```json
{
    "status": 200,
    "message": "Flash sales reordered successfully",
    "success": true
}
```

**Error Responses:**
| Status | Condition |
|--------|-----------|
| 401 | Unauthenticated |
| 403 | Missing `update-flash-sale` permission |
| 422 | Validation failure |
| 500 | Server error |

---

## Route Definitions

```php
// Public routes (no auth)
Route::apiResource('flash-sale', FlashSaleController::class, [
    'only' => ['index', 'show'],
]);

// Admin routes (auth: super_admin + sanctum + email verified)
Route::apiResource('flash-sale', FlashSaleController::class, [
    'only' => ['store', 'update', 'destroy'],
]);
Route::get('products-by-flash-sale', [FlashSaleController::class, 'getProductsByFlashSale']);
Route::get('product-flash-sale-info', [FlashSaleController::class, 'getFlashSaleInfoByProductID']);
Route::post('flash-sale/reorder', [FlashSaleController::class, 'reorder']);
```

Source: `packages/marvel/src/Rest/Routes.php`

---

## Permissions Map

| Permission Enum | String | Applied To |
|----------------|--------|------------|
| `VIEW_FlASH_SALE` | `view-flash-sale` | `index`, `show` |
| `CREATE_FlASH_SALE` | `create-flash-sale` | `store` |
| `UPDATE_FlASH_SALE` | `update-flash-sale` | `update`, `reorder` |
| `DELETE_FlASH_SALE` | `delete-flash-sale` | `destroy` |

---

## Media Library Collections

| Collection Name | Usage | Uploaded Via |
|----------------|-------|-------------|
| `flash-sales-desktop` | Desktop image | `storeFlashSale` / `updateFlashSale` |
| `flash-sales-mobile` | Mobile image | `storeFlashSale` / `updateFlashSale` |

---

## Model Features

- **Translatable:** `title` and `description` fields (Spatie `HasTranslations`)
- **MediaLibrary:** Images managed via Spatie MediaLibrary
- **Sortable:** Implements `Spatie\EloquentSortable\Sortable` with `SortableTrait` (order column: `order`, sorts when creating)
- **Slug:** Auto-generated from title on `saving` event
- **SoftDeletes:** Records are soft-deleted
- **Relations:**
  - `BelongsToMany` with `Product` via `flash_sale_products` pivot
  - `HasMany` with `FlashSaleRequests`
- **Enum types:** `PERCENTAGE`, `FIXED_RATE`, `FINAL_PRICE` (from `FlashSaleType` enum, `FREE_SHIPPING` removed)
- **Scopes:**
  - `valid()` — active sales with dates in range
  - `invalid()` — inactive or expired sales
  - `search($field, $term, $locale)` — translatable search
- **Methods:**
  - `isValid(): bool` — checks status + date range
  - `typeByLang(): string` — returns translated type label (percentage → "Percentage discount", fixed_rate → "Fixed discount", final_price → "Final price")
  - `calcPrice($price): float` — applies flash sale discount to a price

---

## Dependencies

| Class | Type | File |
|-------|------|------|
| `FlashSaleController` | Controller | `packages/marvel/src/Http/Controllers/FlashSaleController.php` |
| `FlashSaleRepository` | Repository | `packages/marvel/src/Database/Repositories/FlashSaleRepository.php` |
| `FlashSale` | Model | `packages/marvel/src/Database/Models/FlashSale.php` |
| `FlashSaleResource` | Resource | `packages/marvel/src/Http/Resources/FlashSaleResource.php` |
| `CreateFlashSaleRequest` | Form Request | `packages/marvel/src/Http/Requests/CreateFlashSaleRequest.php` |
| `UpdateFlashSaleRequest` | Form Request | `packages/marvel/src/Http/Requests/UpdateFlashSaleRequest.php` |
| `FlashSaleType` | Enum | `packages/marvel/src/Enums/FlashSaleType.php` |
| `Permission` | Enum | `packages/marvel/src/Enums/Permission.php` |

---

## Notes

- All resource fields are returned on every route — no conditional hiding (removed in the response refactor).
- The `title` field behavior differs by route: on `flash-sale.index` it returns the translated value, on other routes it returns the raw JSON value.
- Images use null-safe operator: `$this?->getFirstMediaUrl(...)`.
- Images are uploaded via `multipart/form-data` (not JSON).
- The `slug` is auto-generated from the title.
- `products` sync replaces all existing pivot associations — send full desired list.
- `updateFlashSaleProductPrices()` is called after update to recalculate product-level flash sale prices.
- `setProductInFlashSale()` / `unsetProductFromFlashSale()` toggle the `in_flash_sale` boolean on products when syncing.
- The `sale_status` property does not exist on the FlashSale model — always use `status` instead. Fixed in `FlashSaleRepository.php:188`.
- The reorder feature uses Spatie's `SortableTrait::setNewOrder()` which bulk-updates the `order` column for all provided IDs.
