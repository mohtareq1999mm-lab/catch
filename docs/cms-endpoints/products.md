# Products API

## Overview

The Products module manages the full product catalog. Supports simple and variable product types with translatable names/descriptions, discount and flash sale pricing, variant management with attribute values, category association, image uploads, and inventory tracking.

---

## Database Schema

### `products` Table

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| `id` | bigint | PK, AUTO_INCREMENT | Unique identifier |
| `name` | json | NOT NULL, UNIQUE | Translatable name |
| `slug` | varchar(255) | NOT NULL, UNIQUE | Auto-generated URL slug |
| `description` | json | NOT NULL | Translatable description |
| `price` | decimal(15,4) | NULLABLE | Base price (required for simple products) |
| `price_after_discount` | decimal(15,4) | NULLABLE | Computed by `ProductPricingService` |
| `price_after_flash_sale` | decimal(15,4) | NULLABLE | Computed by `ProductPricingService` |
| `sku` | varchar(255) | NULLABLE, UNIQUE | Stock keeping unit |
| `quantity` | int | DEFAULT 0 | Current stock quantity |
| `stock_quantity` | int | NULLABLE | Total stock (syncs with quantity) |
| `reserved_quantity` | int | DEFAULT 0 | Reserved stock |
| `available_stock` | int | NULLABLE | Computed: stock_quantity - reserved_quantity |
| `sold_quantity` | int | DEFAULT 0 | Units sold |
| `in_stock` | tinyint(1) | NOT NULL | Stock availability flag |
| `product_type` | varchar(255) | NOT NULL | `simple` or `variable` |
| `has_discount` | tinyint(1) | NOT NULL | Discount enabled |
| `discount_type` | varchar(255) | NULLABLE | `percentage` or `fixed_rate` |
| `discount_amount` | decimal(15,4) | NULLABLE | Discount value |
| `discount_status` | tinyint(1) | NULLABLE | Discount active/inactive |
| `start_date` | datetime | NULLABLE | Discount start |
| `end_date` | datetime | NULLABLE | Discount end |
| `has_flash_sale` | tinyint(1) | NOT NULL | Flash sale enabled |
| `is_fast_shipping_available` | tinyint(1) | DEFAULT 0 | Fast shipping eligibility |
| `status` | tinyint(1) | DEFAULT 1 | Active/inactive |
| `pieces` | int | NULLABLE | Units per piece |
| `height` | varchar(255) | NULLABLE | Product dimensions |
| `width` | varchar(255) | NULLABLE | Product dimensions |
| `length` | varchar(255) | NULLABLE | Product dimensions |
| `weight` | varchar(255) | NULLABLE | Product weight |
| `type_id` | int | NULLABLE | FK → types.id |
| `language` | varchar(10) | DEFAULT 'en' | Language code |
| `created_at` | timestamp | NULLABLE | Creation time |
| `updated_at` | timestamp | NULLABLE | Last update |
| `deleted_at` | timestamp | NULLABLE | Soft delete |

### `product_variants` Table

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| `id` | bigint | PK, AUTO_INCREMENT | Unique identifier |
| `product_id` | bigint | FK → products.id, CASCADE | Parent product |
| `price` | decimal(15,4) | NOT NULL | Variant price |
| `sale_price` | decimal(15,4) | NULLABLE | Computed sale price |
| `current_price` | decimal(15,4) | NULLABLE | Computed current price |
| `quantity` | int | DEFAULT 0 | Stock quantity |
| `stock_quantity` | int | NULLABLE | Total stock |
| `reserved_quantity` | int | DEFAULT 0 | Reserved stock |
| `available_stock` | int | NULLABLE | Computed available stock |
| `height` | varchar(255) | NULLABLE | Variant dimensions |
| `width` | varchar(255) | NULLABLE | |
| `length` | varchar(255) | NULLABLE | |
| `weight` | varchar(255) | NULLABLE | |

### `attribute_product` Pivot Table

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| `id` | bigint | PK, AUTO_INCREMENT | Unique identifier |
| `product_variant_id` | bigint | FK → product_variants.id, CASCADE | Variant reference |
| `attribute_value_id` | bigint | FK → attribute_values.id, CASCADE | Attribute value reference |

### `category_product` Pivot Table

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| `id` | bigint | PK, AUTO_INCREMENT | |
| `product_id` | bigint | FK → products.id, CASCADE | |
| `category_id` | bigint | FK → categories.id, CASCADE | |

### `flash_sale_products` Pivot Table

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| `id` | bigint | PK, AUTO_INCREMENT | |
| `product_id` | bigint | FK → products.id, CASCADE | |
| `flash_sale_id` | bigint | FK → flash_sales.id, CASCADE | |

### `banner_product` Pivot Table

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| `id` | bigint | PK, AUTO_INCREMENT | |
| `banner_id` | bigint | FK → banners.id, CASCADE | |
| `product_id` | bigint | FK → products.id, CASCADE | |
| `created_at` | timestamp | NULLABLE | |

### `slider_product` Pivot Table

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| `id` | bigint | PK, AUTO_INCREMENT | |
| `slider_id` | bigint | FK → sliders.id, CASCADE | |
| `product_id` | bigint | FK → products.id, CASCADE | |
| `created_at` | timestamp | NULLABLE | |

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

### ProductResource

| Field | Type | Description |
|-------|------|-------------|
| `id` | int | Product ID |
| `name` | string | Translated name (current locale) |
| `slug` | string | URL slug |
| `description` | object | Translatable description `{en, ar}` |
| `price` | float | Base price |
| `current_price` | float | Computed current price (after all discounts) |
| `price_after_discount` | float | Price after regular discount |
| `price_after_flash_sale` | float | Price after flash sale |
| `discount_type` | string | `percentage` or `fixed_rate` |
| `discount_amount` | float | Discount value |
| `start_date` | string | Discount start date |
| `end_date` | string | Discount end date |
| `sku` | string | Stock keeping unit |
| `stock_quantity` | int | Total stock |
| `reserved_quantity` | int | Reserved stock (default 0) |
| `available_stock` | int | Computed available stock |
| `quantity` | int | Current quantity |
| `sold_quantity` | int | Units sold (default 0) |
| `in_stock` | int | Stock flag (1/0) |
| `status` | bool | Active/inactive |
| `product_type` | string | `simple` or `variable` |
| `height` | string | Dimension |
| `width` | string | Dimension |
| `length` | string | Dimension |
| `weight` | string | Weight |
| `has_flash_sale` | int | Flash sale flag |
| `has_discount` | int | Discount flag |
| `is_fast_shipping_available` | bool | Fast shipping eligibility |
| `discount_valid` | bool | (merged when has_discount) Whether discount is within date range |
| `banner_id` | int | Associated banner ID |
| `created_at` | ISO 8601 | Creation timestamp |
| `categories` | array | `[{id, name, slug}]` (when loaded) |
| `tags` | array | `TagResource` collection `[{id, name, slug, image, icon}]` (when loaded) |
| `brands` | array | Brand objects (when loaded via `brands`) |
| `banners` | array | Banner objects (when loaded via `banners`) |
| `sliders` | array | Slider objects (when loaded via `sliders`) |
| `flash_sales` | array | `FlashSaleResource` collection (when loaded) |
| `images` | array | Media URLs from `products` collection |
| `variants` | array | Variant details (when loaded via `variations`) |
| `related_products` | array | `ProductResource` collection (when loaded) |

**Variant object (within `variants`):**

| Field | Type | Description |
|-------|------|-------------|
| `id` | int | Variant ID |
| `price` | float | Variant price |
| `current_price` | float | Computed current price |
| `stock_quantity` | int | Total stock |
| `reserved_quantity` | int | Reserved (default 0) |
| `available_stock` | int | Available |
| `quantity` | int | Quantity |
| `height` | string | Dimension |
| `width` | string | |
| `length` | string | |
| `weight` | string | |
| `attributes` | array | `[{attribute_name, value}]` |

---

## Endpoints

### GET /products — List Products

**Purpose:** List all products with pagination, filtering, and sorting. Excludes unavailable products when a date range is provided.

**Method:** `GET`

**URL:** `/products`

**Authentication:** Required

**Permissions:** `view-products`

**Query Parameters:**

| Field | Type | Default | Description |
|-------|------|---------|-------------|
| `page` | int | 1 | Page number |
| `limit` | int | 15 | Results per page |
| `search` | string | — | Search in product name, description, SKU, and variant SKUs |
| `with` | string | — | Relations to eager load (semicolon separated, e.g. `variations;categories;flash_sales`) |
| `sort` | string | `desc` | Legacy sort direction by `created_at` (`asc` or `desc`) |
| `orderBy` | string | `created_at` | Column to sort by. Supported: `created_at`, `updated_at`, `name`, `price`, `sold_quantity`, `sku`, `id` |
| `orderDir` | string | `desc` | Sort direction (`asc` or `desc`) |
| `date_range` | string | — | Date range `YYYY-MM-DD//YYYY-MM-DD` for availability filtering |
| `flash_sale_builder` | mixed | — | Flash sale product processing |
| `status` | int | — | Filter by product status (`0` or `1`) |
| `category` | string | — | Filter by category slug (e.g. `?category=electronics`) |
| `banner` | string | — | Filter by banner slug (e.g. `?banner=summer-sale`) |
| `flash_sale` | string | — | Filter by flash sale slug (e.g. `?flash_sale=flash-01`) |
| `slider` | string | — | Filter by slider slug (e.g. `?slider=hero-banner`) |
| `tag` | string\|array | — | Filter by tag slug (e.g. `?tag=gaming`). AND logic — product must have ALL specified tags. Comma-separated or array format. |
| `tags` | string\|array | — | Filter by tag slug or ID (e.g. `?tags=gaming` or `?tags[]=1&tags[]=2`). Supports both slug and numeric ID lookup. AND logic — product must have ALL specified tags. |

**URL Examples:**
```
GET /api/v1/products?category=electronics&flash_sale=summer-sale&orderBy=price&orderDir=asc&limit=20
GET /api/v1/products?search=iphone&banner=homepage-banner&orderBy=name&orderDir=asc
GET /api/v1/products?slider=featured&status=1&sort=desc&page=2
GET /api/v1/products?category=clothing&orderBy=sold_quantity&orderDir=desc&limit=10
```

**Business Logic:**
1. If `date_range` is set, calculates unavailable products via `getUnavailableProducts()` and excludes them
2. If `with` contains `variation_options.digital_files` or `digital_files`, throws `AuthorizationException`
3. If `flash_sale_builder` is set, processes flash sale product filtering
4. Eager loads `variations`, `categories`, `shops`, `flash_sales`
5. Paginates with given limit
6. Returns `ProductCollection` with full pagination links

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
                "name": "Hoppister Tops",
                "slug": "hoppister-tops",
                "description": {
                    "en": "Product description here",
                    "ar": "وصف المنتج هنا"
                },
                "price": 350.00,
                "current_price": 300.00,
                "price_after_discount": 300.00,
                "price_after_flash_sale": 280.00,
                "discount_type": "percentage",
                "discount_amount": 14.29,
                "in_stock": 1,
                "status": true,
                "product_type": "variable",
                "categories": [
                    { "id": 1, "name": "Clothing", "slug": "clothing" }
                ],
                "images": [
                    "https://cdn.example.com/storage/products/1/image.jpg"
                ],
                "variants": [
                    {
                        "id": 1,
                        "price": 350.00,
                        "current_price": 300.00,
                        "quantity": 50,
                        "attributes": [
                            { "attribute_name": "Size", "value": "M" },
                            { "attribute_name": "Color", "value": "Red" }
                        ]
                    }
                ]
            }
        ],
        "links": {
            "current_page": 1,
            "from": 1,
            "to": 15,
            "last_page": 5,
            "path": "https://api.example.com/products",
            "per_page": 15,
            "total": 72,
            "next_page_url": "https://api.example.com/products?page=2",
            "prev_page_url": null,
            "last_page_url": "https://api.example.com/products?page=5",
            "first_page_url": "https://api.example.com/products?page=1"
        }
    }
}
```

**Error Responses:**
| Status | Condition |
|--------|-----------|
| 401 | Unauthenticated |
| 403 | Missing `view-products` permission |

---

### POST /products — Create Product

**Purpose:** Create a new product with support for images, variants, categories, flash sales, and discount pricing.

**Method:** `POST`

**URL:** `/products`

**Authentication:** Required

**Permissions:** `create-product`

**Request Body (multipart/form-data for image support):**

| Field | Type | Required | Validation |
|-------|------|----------|------------|
| `name` | object | **Yes** | Translatable array |
| `name.*` | string | **Yes** | `required`, `string`, `max:255`, unique translation |
| `description` | object | **Yes** | Translatable array |
| `description.*` | string | **Yes** | `required`, `string`, `max:10000` |
| `product_type` | string | **Yes** | `in:simple,variable` |
| `categories` | array | **Yes** | Array of integer IDs |
| `categories.*` | int | **Yes** | `exists:categories,id` |
| `brands` | array | No | `sometimes` |
| `brands.*` | int | No | `exists:brands,id` |
| `banners` | array | No | `sometimes` |
| `banners.*` | int | No | `exists:banners,id` |
| `sliders` | array | No | `sometimes` |
| `sliders.*` | int | No | `exists:sliders,id` |
| `price` | numeric | Conditional | `sometimes`, `min:0`, `required_if:product_type=simple` |
| `quantity` | int | No | `sometimes`, `min:1` |
| `in_stock` | bool/int | **Yes** | `in:1,0` |
| `has_discount` | bool/int | **Yes** | `in:true,false,1,0` |
| `has_flash_sale` | bool/int | **Yes** | `in:true,false,1,0` |
| `flash_sale_id` | int | **If** has_flash_sale=1 | `exists:flash_sales,id` |
| `discount_type` | string | **If** has_discount=1 | `in:percentage,fixed_rate` |
| `discount_amount` | numeric | **If** has_discount=1 | `min:1` |
| `discount_status` | bool/int | **If** has_discount=1 | `in:1,0` |
| `start_date` | date | No | `sometimes` |
| `end_date` | date | No | `sometimes`, `after_or_equal:start_date` |
| `pieces` | int | No | `sometimes`, `min:1` |
| `status` | bool/int | No | `sometimes`, `in:1,0` |
| `height` | string | No | `nullable` |
| `width` | string | No | `nullable` |
| `length` | string | No | `nullable` |
| `weight` | string | No | `nullable` |
| `is_fast_shipping_available` | bool | No | `nullable`, `boolean` |
| `images` | array | No | Files (jpeg,png,jpg, max:2048) — multipart upload |
| `variants` | array | Conditional | `sometimes` — if non-empty, forces product_type to `variable` |
| `variants.*.price` | numeric | **If** variants set | `required_with:variants`, `min:0` |
| `variants.*.quantity` | int | **If** variants set | `required_with:variants`, `min:0` |
| `variants.*.attribute_values` | array | **If** variants set | `required_with:variants` |
| `variants.*.attribute_values.*` | int | **If** variants set | `exists:attribute_values,id` |
| `variants.*.weight` | string | No | `sometimes` |
| `variants.*.length` | string | No | `sometimes` |
| `variants.*.width` | string | No | `sometimes` |
| `variants.*.height` | string | No | `sometimes` |

**Example Request (Simple Product):**
```json
{
    "name": {
        "en": "Classic T-Shirt",
        "ar": "تي شيرت كلاسيكي"
    },
    "description": {
        "en": "A comfortable cotton t-shirt",
        "ar": "تي شيرت قطني مريح"
    },
    "price": 29.99,
    "product_type": "simple",
    "categories": [1, 3],
    "brands": [1, 2],
    "banners": [1],
    "sliders": [2],
    "quantity": 100,
    "in_stock": true,
    "has_discount": true,
    "discount_type": "percentage",
    "discount_amount": 10,
    "discount_status": 1,
    "has_flash_sale": true,
    "flash_sale_id": 1,
    "start_date": "2025-01-01",
    "end_date": "2025-01-31"
}
```

**Example Request (Variable Product with Variants):**
```json
{
    "name": {
        "en": "Running Shoes",
        "ar": "حذاء جري"
    },
    "description": {
        "en": "Lightweight running shoes",
        "ar": "حذاء جري خفيف الوزن"
    },
    "product_type": "variable",
    "categories": [2, 5],
    "brands": [3],
    "banners": [1],
    "sliders": [],
    "quantity": 150,
    "in_stock": true,
    "has_discount": false,
    "has_flash_sale": false,
    "status": true,
    "variants": [
        {
            "price": 89.99,
            "quantity": 50,
            "sku": "SHOE-RED-42",
            "height": "10",
            "width": "8",
            "length": "12",
            "weight": "0.5",
            "attribute_values": [1, 5]
        },
        {
            "price": 89.99,
            "quantity": 30,
            "sku": "SHOE-BLU-42",
            "height": "10",
            "width": "8",
            "length": "12",
            "weight": "0.5",
            "attribute_values": [2, 5]
        },
        {
            "price": 94.99,
            "quantity": 20,
            "sku": "SHOE-RED-44",
            "height": "11",
            "width": "9",
            "length": "13",
            "weight": "0.6",
            "attribute_values": [1, 6]
        }
    ]
}
```

**Business Logic:**
1. Auto-detects `product_type`: set to `variable` if `variants` array is non-empty, otherwise `simple`
2. Generates `slug` from the English translation of `name`
3. Resolves flash sale via `resolveFlashSale()`
4. Calculates pricing via `ProductPricingService`:
   - `price_after_discount` — applies discount type/amount
   - `price_after_flash_sale` — applies flash sale pricing
5. Creates the product
6. If variants provided, creates `ProductVariant` records with `attribute_values` associations
7. Uploads images via Spatie Media Library to `products` collection
8. Syncs categories via `categories()` relationship
9. Syncs brands via `brands()` relationship (`brand_product` pivot)
10. Syncs banners via `banners()` relationship (`banner_product` pivot)
11. Syncs sliders via `sliders()` relationship (`slider_product` pivot)
12. Syncs flash sale via `flash_sales()` relationship
13. All operations wrapped in `DB::transaction()` — rollback on failure
14. Returns created product with loaded `variations`, `categories`, `brands`, `banners`, `sliders`, `flash_sales`, `shops`

**Success Response (201):**
```json
{
    "status": 201,
    "message": "Product created successfully",
    "success": true,
    "data": {
        "id": 1,
        "name": "Classic T-Shirt",
        "slug": "classic-t-shirt",
        "price": 29.99,
        "price_after_discount": 26.99,
        "price_after_flash_sale": null,
        "product_type": "simple",
        "has_discount": 1,
        "has_flash_sale": 0,
        "in_stock": 1,
        "status": true,
        "categories": [
            { "id": 1, "name": "Clothing", "slug": "clothing" }
        ],
        "variants": []
    }
}
```

**Error Responses:**
| Status | Condition |
|--------|-----------|
| 401 | Unauthenticated |
| 403 | Missing `create-product` permission |
| 422 | Validation failure |
| 500 | Something went wrong (DB transaction rollback) |

---

### GET /products/{id} — Show Product

**Purpose:** Fetch a single product with related products (same categories, excluding self).

**Method:** `GET`

**URL:** `/products/{id}`

**Authentication:** Required

**Permissions:** `view-products`

**Business Logic:**
1. Finds product by ID via `repository->where('id', $id)->firstOrFail()`
2. Fetches related products (same categories, excluding current product, limited to `limit` param, default 10)
3. Sets `related_products` relation on the product
4. Eager loads `variations`, `categories`, `shops`, `flash_sales`, `banners`, `sliders`, `brands`, `reviews`

**Success Response (200):**
```json
{
    "status": 200,
    "message": "Data fetched successfully",
    "success": true,
    "data": {
        "id": 1,
        "name": "Classic T-Shirt",
        "slug": "classic-t-shirt",
        "price": 29.99,
        "current_price": 26.99,
        "categories": [
            { "id": 1, "name": "Clothing", "slug": "clothing" }
        ],
        "brands": [
            { "id": 1, "name": "Nike", "slug": "nike" }
        ],
        "banners": [
            { "id": 1, "title": "Summer Sale", "slug": "summer-sale" }
        ],
        "sliders": [
            { "id": 1, "title": "Hero Banner", "slug": "hero-banner" }
        ],
        "reviews": [
            { "id": 1, "rating": 5, "comment": "Great product!", "user": { "id": 1, "name": "John Doe" } }
        ],
        "variants": [],
        "related_products": [
            { "id": 2, "name": "V-Neck T-Shirt", "slug": "v-neck-t-shirt", "price": 24.99, "current_price": 24.99 }
        ]
    }
}
```

**Error Responses:**
| Status | Condition |
|--------|-----------|
| 401 | Unauthenticated |
| 403 | Missing `view-products` permission |
| 404 | Product not found |

---

### PUT /products/{id} — Update Product

**Purpose:** Update an existing product's fields, images, variants, categories, and pricing.

**Method:** `PUT`

**URL:** `/products/{id}`

**Authentication:** Required

**Permissions:** `update-product`

**Request Body (multipart/form-data for image support):**

All fields are `sometimes` (optional) — only send changed fields.

| Field | Type | Required | Validation | Notes vs Create |
|-------|------|----------|------------|----------------|
| `name` | object | No | `sometimes` | unique translation ignores self |
| `name.*` | string | No | `string`, `max:255`, unique translation (ignores current product) | |
| `description` | object | No | `sometimes` | |
| `description.*` | string | No | `string`, `max:10000` | |
| `product_type` | string | No | `in:simple,variable` | |
| `price` | numeric | No | `sometimes`, `min:0`, `required_if:product_type=simple` | |
| `shop_id` | int | No | `sometimes`, `exists:shops,id` | **New — only in update** |
| `categories` | array | No | `sometimes` | |
| `categories.*` | int | No | `exists:categories,id` | |
| `brands` | array | No | `sometimes` | |
| `brands.*` | int | No | `exists:brands,id` | |
| `banners` | array | No | `sometimes` | |
| `banners.*` | int | No | `exists:banners,id` | |
| `sliders` | array | No | `sometimes` | |
| `sliders.*` | int | No | `exists:sliders,id` | |
| `quantity` | int | No | `sometimes`, `min:1` | |
| `images` | array | No | `sometimes`; each: image, mimes:jpeg,png,jpg,gif, max:2048 | **New — only in update** |
| `images.*` | file | No | `image`, `mimes:jpeg,png,jpg,gif`, `max:2048` | |
| `status` | string | No | `in:publish,unpublish,under_review,approved,rejected,draft` | Uses `ProductStatus` enum (different from create) |
| `in_stock` | bool/int | No | `sometimes`, `in:true,false,1,0` | |
| `has_discount` | bool/int | No | `sometimes`, `in:true,false,1,0` | |
| `has_flash_sale` | bool/int | No | `sometimes`, `in:true,false,1,0` | |
| `is_fast_shipping_available` | bool | No | `nullable`, `boolean` | |
| `flash_sale_id` | int | **If** has_flash_sale=1 | `exists:flash_sales,id` | |
| `discount_type` | string | **If** has_discount=1 | `in:percentage,fixed_rate` | |
| `discount_amount` | numeric | **If** has_discount=1 | `min:1` | |
| `discount_status` | bool/int | **If** has_discount=1 | `in:true,false,1,0` | Supports true/false unlike create |
| `start_date` | date | No | `sometimes` | |
| `end_date` | date | No | `sometimes`, `after_or_equal:start_date` | |
| `pieces` | int | No | `sometimes`, `min:1` | |
| `height` | string | No | `nullable` | |
| `width` | string | No | `nullable` | |
| `length` | string | No | `nullable` | |
| `weight` | string | No | `nullable` | |
| `variants` | array | No | `sometimes` | |
| `variants.*.id` | int | No | `sometimes`, `exists:product_variants,id` | **New — variant identifier** |
| `variants.*.price` | numeric | No | `sometimes`, `min:0` | |
| `variants.*.sale_price` | numeric | No | `sometimes`, `min:0` | **New — only in update** |
| `variants.*.quantity` | int | No | `sometimes`, `min:0` | |
| `variants.*.attribute_values` | array | No | `sometimes` | |
| `variants.*.attribute_values.*` | int | No | `exists:attribute_values,id` | |
| `variants.*.weight` | string | No | `sometimes` | |
| `variants.*.length` | string | No | `sometimes` | |
| `variants.*.width` | string | No | `sometimes` | |
| `variants.*.height` | string | No | `sometimes` | |

**Example Request (Simple Product — partial update):**
```json
{
    "name": {
        "en": "Classic T-Shirt (Updated)"
    },
    "price": 34.99,
    "categories": [1, 3, 5],
    "brands": [1, 2, 4],
    "banners": [2],
    "sliders": [1, 3],
    "has_discount": true,
    "discount_type": "fixed_rate",
    "discount_amount": 5,
    "discount_status": 1,
    "in_stock": true,
    "status": 1
}
```

**Example Request (Variable Product — replace all variants):**
```json
{
    "name": {
        "en": "Running Shoes (2025 Edition)"
    },
    "product_type": "variable",
    "categories": [2, 5],
    "brands": [3],
    "banners": [1],
    "sliders": [],
    "in_stock": true,
    "has_discount": false,
    "has_flash_sale": false,
    "status": 1,
    "variants": [
        {
            "price": 99.99,
            "quantity": 40,
            "sku": "SHOE-RED-42-2025",
            "attribute_values": [1, 5]
        },
        {
            "price": 99.99,
            "quantity": 25,
            "sku": "SHOE-BLU-42-2025",
            "attribute_values": [2, 5]
        },
        {
            "price": 104.99,
            "quantity": 15,
            "sku": "SHOE-RED-44-2025",
            "attribute_values": [1, 6]
        }
    ]
}
```

**Business Logic:**
1. Same pricing recalculation as Create — reads existing product values for unchanged fields
2. Generates slug from name if name changed (appends suffix if slug collision)
3. Resolves flash sale (reads existing `has_flash_sale` from product if not in request)
4. **Warning:** ALL existing variants are **deleted and recreated** — `ProductVariant::where('product_id', ...)->delete()` — `variants.*.id` is not used
5. Updates images via `updateImages()` which replaces existing media
6. Syncs categories via `categories()` relationship
7. Syncs brands via `brands()` relationship (`brand_product` pivot)
8. Syncs banners via `banners()` relationship (`banner_product` pivot)
9. Syncs sliders via `sliders()` relationship (`slider_product` pivot)
10. Syncs flash sale via `flash_sales()` relationship
11. All operations wrapped in `DB::transaction()`

**Success Response (200):**
```json
{
    "status": 200,
    "message": "Product updated successfully",
    "success": true,
    "data": {
        "id": 1,
        "name": "Classic T-Shirt (Updated)",
        "slug": "classic-t-shirt-updated",
        "price": 34.99,
        "price_after_discount": 29.99,
        "product_type": "simple",
        "in_stock": 1,
        "status": true,
        "categories": [
            { "id": 1, "name": "Clothing", "slug": "clothing" }
        ],
        "brands": [
            { "id": 1, "name": "Nike", "slug": "nike" }
        ],
        "banners": [
            { "id": 1, "title": "Summer Sale", "slug": "summer-sale" }
        ],
        "sliders": [
            { "id": 1, "title": "Hero Banner", "slug": "hero-banner" }
        ],
        "variants": []
    }
}
```

**Error Responses:**
| Status | Condition |
|--------|-----------|
| 401 | Unauthenticated |
| 403 | Missing `update-product` permission |
| 404 | Product not found |
| 422 | Validation failure |
| 500 | Could not update the resource |

---

### DELETE /products/{id} — Delete Product

**Purpose:** Hard-delete a single product with all its relations (variants, media, reviews, questions, wishlists, pivots). Irreversible.

**Method:** `DELETE`

**URL:** `/products/{id}`

**Authentication:** Required

**Permissions:** `delete-product`

**Business Logic:**
1. Finds product by ID via `repository->findOrFail($request->id)`
2. Calls `forceDeleteProduct()` which performs full cleanup:
   - `clearMediaCollection('products')` — removes Spatie Media Library files + records
   - Delete variants → each variant's `attributeProducts()` → variant delete
   - Delete `reviews()`, `questions()`, `wishlists()`, `availabilities()`
   - Delete `digital_file()` morph-one relation
   - Detach all 16 BelongsToMany pivots (categories, brands, banners, tags, orders, shops, flash_sales, promotions, coupons, sliders, dropoff_locations, pickup_locations, deposits, persons, features, flash_sale_requests)
   - `forceDelete()` — bypasses SoftDeletes, removes row permanently

**Success Response (200):**
```json
{
    "status": 200,
    "message": "Product deleted successfully",
    "success": true
}
```

**Error Responses:**
| Status | Condition |
|--------|-----------|
| 401 | Unauthenticated |
| 403 | Missing `delete-product` permission |
| 404 | Product not found |

---

### DELETE /products/all — Delete All Products

**Purpose:** Hard-delete every product in the database (including soft-deleted ones). Cleans up media, variants, reviews, questions, wishlists, and all pivot relations. Irreversible.

**Method:** `DELETE`

**URL:** `/products/all`

**Authentication:** Required

**Permissions:** `delete-product`

**Business Logic:**
1. Counts all products (including soft-deleted) via `Product::withTrashed()->count()`
2. Iterates in chunks of 100 via `withTrashed()->chunk(100, ...)`
3. For each product, `forceDeleteProduct()` performs:
   - `clearMediaCollection('products')` — removes Spatie Media Library files + records
   - Delete variants → each variant's `attributeProducts()` → variant delete
   - Delete `reviews()`, `questions()`, `wishlists()`, `availabilities()`
   - Delete `digital_file()` morph-one relation
   - Detach all 16 BelongsToMany pivots (categories, brands, banners, tags, orders, shops, flash_sales, promotions, coupons, sliders, dropoff_locations, pickup_locations, deposits, persons, features, flash_sale_requests)
   - `forceDelete()` — bypasses SoftDeletes, removes row permanently
4. Returns deleted count

**Success Response (200):**
```json
{
    "status": 200,
    "message": "Products deleted successfully",
    "success": true,
    "data": {
        "deleted_count": 47
    }
}
```

**Error Responses:**
| Status | Condition |
|--------|-----------|
| 401 | Unauthenticated |
| 403 | Missing `delete-product` permission |

---

### POST /products/bulk-delete — Bulk Delete Products

**Purpose:** Hard-delete specific products by their IDs with full cleanup. Irreversible.

**Method:** `POST`

**URL:** `/products/bulk-delete`

**Authentication:** Required

**Permissions:** `delete-product`

**Validation Rules (via `BulkDeleteProductsRequest`):**

| Field | Type | Required | Validation |
|-------|------|----------|------------|
| `ids` | array | **Yes** | `required`, `array`, `min:1` |
| `ids.*` | int | **Yes** | `integer`, `min:1`, `distinct`, `exists:products,id` |

**Example Request:**
```json
{
    "ids": [1, 5, 12, 27]
}
```

**Business Logic:**
1. Validates request via `BulkDeleteProductsRequest`
2. Queries with `Product::withTrashed()->whereIn('id', $ids)->chunk(100, ...)`
3. Per-product cleanup identical to `destroyAll`:
   - Clear media, delete variants + attribute products
   - Delete reviews, questions, wishlists, availabilities, digital files
   - Detach all pivot relations
   - `forceDelete()`
4. Returns array of deleted IDs

**Success Response (200):**
```json
{
    "status": 200,
    "message": "Products deleted successfully",
    "success": true,
    "data": {
        "deleted_ids": [1, 5, 12, 27]
    }
}
```

**Error Responses:**
| Status | Condition |
|--------|-----------|
| 401 | Unauthenticated |
| 403 | Missing `delete-product` permission |
| 422 | Validation failure (missing/invalid IDs) |

---

### PUT /products/{id}/fast-shipping — Toggle Fast Shipping

**Purpose:** Toggle the `is_fast_shipping_available` flag on a product.

**Method:** `PUT`

**URL:** `/products/{id}/fast-shipping`

**Authentication:** Required

**Permissions:** `update-product`

**Request Body (JSON):**

| Field | Type | Required | Validation |
|-------|------|----------|------------|
| `is_fast_shipping_available` | bool | **Yes** | `required`, `boolean` |

**Business Logic:**
1. Finds product by ID
2. Validates request body inline
3. Updates `is_fast_shipping_available`
4. Returns product with loaded relations

**Success Response (200):**
```json
{
    "status": 200,
    "message": "Product updated successfully",
    "success": true,
    "data": { ... ProductResource ... }
}
```

**Error Responses:**
| Status | Condition |
|--------|-----------|
| 401 | Unauthenticated |
| 403 | Missing `update-product` permission |
| 404 | Product not found |
| 422 | Validation failure |

---

### GET /best-selling-products

**Purpose:** Retrieve products sorted by total sold quantity from completed orders.

**Method:** `GET`

**URL:** `/best-selling-products`

**Authentication:** None

**Permissions:** None

**Query Parameters:**

| Field | Type | Default | Description |
|-------|------|---------|-------------|
| `limit` | int | 10 | Max products to return |
| `language` | string | `en` | Language filter |
| `type_slug` | string | — | Filter by type slug |
| `range` | int | — | Days to look back for sales |
| `shop_id` | int | — | Filter by shop |

**Business Logic:**
1. Left joins `order_product` and `orders` tables
2. Sums `order_quantity` grouped by product
3. Filters completed orders (`order_status = 'order-completed'`)
4. Orders by `total_sales` descending

---

### GET /popular-products

**Purpose:** Retrieve products sorted by order count (popularity).

**Method:** `GET`

**URL:** `/popular-products`

**Authentication:** None

**Permissions:** None

**Query Parameters:**

| Field | Type | Default | Description |
|-------|------|---------|-------------|
| `limit` | int | 10 | Max products to return |
| `language` | string | `en` | Language filter |
| `shop_id` | int | — | Filter by shop |
| `type_id` | int | — | Filter by type ID |
| `type_slug` | string | — | Filter by type slug (alternative to type_id) |
| `range` | int | — | Days to look back |

---

### GET /draft-products

**Purpose:** List paginated draft products scoped to the authenticated user's shops.

**Method:** `GET`

**URL:** `/draft-products`

**Authentication:** Required

**Permissions:** Varies by role (SUPER_ADMIN, STORE_OWNER, STAFF)

**Query Parameters:**

| Field | Type | Default | Description |
|-------|------|---------|-------------|
| `limit` | int | 15 | Items per page |
| `shop_id` | int | — | Filter by specific shop |
| `language` | string | `en` | Language filter |

---

### GET /product-stock

**Purpose:** List products with low stock (quantity < 10) scoped to the authenticated user's shops.

**Method:** `GET`

**URL:** `/product-stock`

**Authentication:** Required

**Permissions:** Varies by role

**Query Parameters:**

| Field | Type | Default | Description |
|-------|------|---------|-------------|
| `limit` | int | 15 | Items per page |
| `shop_id` | int | — | Filter by specific shop |
| `language` | string | `en` | Language filter |

---

### GET /my-wishlists

**Purpose:** List the authenticated user's wishlist products.

**Method:** `GET`

**URL:** `/my-wishlists`

**Authentication:** Required

**Permissions:** None (user-scoped)

**Query Parameters:**

| Field | Type | Default | Description |
|-------|------|---------|-------------|
| `limit` | int | 10 | Items per page |

---

### GET /products/calculate-rental-price

**Purpose:** Calculate rental price for a rental product based on duration, quantity, and add-ons. Also checks availability.

**Method:** `GET`

**URL:** `/products/calculate-rental-price`

**Authentication:** None

**Permissions:** None

**Query Parameters:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `product_id` | int | **Yes** | Product ID |
| `variation_id` | int | No | Variation ID |
| `from` | date | **Yes** | Start date |
| `to` | date | **Yes** | End date |
| `quantity` | int | No | Quantity (default 1) |
| `persons` | int | No | Number of persons |
| `dropoff_location_id` | int | No | Dropoff location |
| `pickup_location_id` | int | No | Pickup location |
| `deposits` | array | No | Deposit resource IDs |
| `features` | array | No | Feature resource IDs |

---

## Route Definitions

```php
// Authenticated routes — index + show only
Route::apiResource('products', ProductController::class, ['only' => ['index', 'show']]);

// Super Admin only — store, update, destroy
Route::apiResource('products', ProductController::class, ['only' => ['store', 'update', 'destroy']])
     ->middleware(['role:super_admin', 'auth:sanctum', 'email.verified']);

// Additional endpoints
Route::get('best-selling-products', [ProductController::class, 'bestSellingProducts']);
Route::get('popular-products', [ProductController::class, 'popularProducts']);
Route::get('draft-products', [ProductController::class, 'draftedProducts']);
Route::get('product-stock', [ProductController::class, 'productStock']);
Route::get('my-wishlists', [ProductController::class, 'myWishlists']);
Route::get('products/calculate-rental-price', [ProductController::class, 'calculateRentalPrice']);
Route::put('products/{id}/fast-shipping', [ProductController::class, 'toggleFastShipping']);

// Bulk delete routes (registered before apiResource to avoid route conflict)
Route::delete('products/all', [ProductController::class, 'destroyAll']);
Route::post('products/bulk-delete', [ProductController::class, 'destroyBulk']);
```

Source: `packages/marvel/src/Rest/Routes.php`

---

## Permissions Map

| Permission Enum | String | Applied To |
|----------------|--------|------------|
| `VIEW_PRODUCTS` | `view-products` | `index`, `show` |
| `CREATE_PRODUCT` | `create-product` | `store` |
| `UPDATE_PRODUCT` | `update-product` | `update`, `toggleFastShipping` |
| `DELETE_PRODUCT` | `delete-product` | `destroy`, `destroyAll`, `destroyBulk` |

---

## Enums

| Enum | Values |
|------|--------|
| `ProductType` | `simple`, `variable` |
| `ProductStatus` | `under_review`, `approved`, `rejected`, `publish`, `unpublish`, `draft` |
| `DiscountType` | `percentage`, `fixed_rate` |

---

## Model Relationships

| Relation | Type | Model |
|----------|------|-------|
| `type()` | BelongsTo | Type |
| `shops()` | BelongsToMany | Shop (pivot: product_shop) |
| `author()` | BelongsTo | Author |
| `manufacturer()` | BelongsTo | Manufacturer |
| `shipping()` | BelongsTo | Shipping |
| `categories()` | BelongsToMany | Category (pivot: category_product) |
| `brands()` | BelongsToMany | Brand (pivot: brand_product) |
| `tags()` | BelongsToMany | Tag (pivot: product_tag) |
| `orders()` | BelongsToMany | Order (pivot: order_product) |
| `variations()` | HasMany | ProductVariant |
| `reviews()` | HasMany | Review |
| `questions()` | HasMany | Question |
| `wishlists()` | HasMany | Wishlist |
| `flash_sales()` | BelongsToMany | FlashSale (pivot: flash_sale_products) |
| `promotions()` | BelongsToMany | Promotion (pivot: promotion_product) |
| `coupons()` | BelongsToMany | Coupon (pivot: coupon_product) |
| `sliders()` | BelongsToMany | Slider (pivot: slider_product) |
| `digital_file()` | MorphOne | DigitalFile |
| `availabilities()` | MorphMany | Availability |
| `dropoff_locations()` | BelongsToMany | Resource (pivot: dropoff_location_product) |
| `pickup_locations()` | BelongsToMany | Resource (pivot: pickup_location_product) |
| `deposits()` | BelongsToMany | Resource (pivot: deposit_product) |
| `persons()` | BelongsToMany | Resource (pivot: person_product) |
| `features()` | BelongsToMany | Resource (pivot: feature_product) |

---

## Model Appends (computed attributes)

| Attribute | Description |
|-----------|-------------|
| `current_price` | Final computed price via `ProductPricingService` |
| `price_after_discount` | Price after regular discount |
| `price_after_flash_sale` | Price after flash sale |
| `final_price` | Alias for current_price |

---

## Dependencies

| Component | Path |
|-----------|------|
| Controller | `packages/marvel/src/Http/Controllers/ProductController.php` |
| Create Request | `packages/marvel/src/Http/Requests/ProductCreateRequest.php` |
| Update Request | `packages/marvel/src/Http/Requests/ProductUpdateRequest.php` |
| ProductResource | `packages/marvel/src/Http/Resources/product/ProductResource.php` |
| ProductCollection | `packages/marvel/src/Http/Resources/product/ProductCollection.php` |
| GetSingleProductResource | `packages/marvel/src/Http/Resources/product/GetSingleProductResource.php` |
| RelatedProductResource | `packages/marvel/src/Http/Resources/product/RelatedProductResource.php` |
| ProductCollectionMini | `packages/marvel/src/Http/Resources/product/ProductCollectionMini.php` |
| ProductVariantResource | `packages/marvel/src/Http/Resources/product/ProductVariantResource.php` |
| Repository | `packages/marvel/src/Database/Repositories/ProductRepository.php` |
| Model | `packages/marvel/src/Database/Models/Product.php` |
| ProductType Enum | `packages/marvel/src/Enums/ProductType.php` |
| ProductStatus Enum | `packages/marvel/src/Enums/ProductStatus.php` |
| Bulk Delete Request | `packages/marvel/src/Http/Requests/BulkDeleteProductsRequest.php` |
| DiscountType Enum | `packages/marvel/src/Enums/DiscountType.php` |
| PricingService | `packages/marvel/Services/Pricing/ProductPricingService.php` |
| Permission Enum | `packages/marvel/src/Enums/Permission.php` |

---

## Notes

- `product_type` is auto-set during creation: `variable` if variants array is provided, otherwise `simple` — overrides user input
- On update, **all existing variants are deleted and recreated** even if `variants.*.id` is provided (the ID field exists in the request but is ignored by the repository)
- Pricing is recalculated on every create/update via `ProductPricingService`
- The `status` field differs between **create** (`1` or `0`) and **update** (ProductStatus enum values: `publish`, `unpublish`, `under_review`, `approved`, `rejected`, `draft`)
- The `sku` is auto-generated if empty (format: `PRD-{uuid}`)
- Images are managed via Spatie Media Library in the `products` collection
- Categories are synced via `sync()` — send the full desired list
- Brands are synced via `sync()` on the `brand_product` pivot table — send the full desired list of brand IDs
- Banners are synced via `sync()` on the `banner_product` pivot table — send the full desired list of banner IDs
- Sliders are synced via `sync()` on the `slider_product` pivot table — send the full desired list of slider IDs
- Flash sale is synced via `sync()` — single flash sale per product
- `slug` is auto-generated from the English translation of `name`
- The `ApiResponse` trait handles response formatting for all product endpoints
- All create/update operations are wrapped in `DB::transaction()` with rollback on failure

