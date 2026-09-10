# API Documentation - Product Feature

## Product Type (`item_type`)

Products expose an **`item_type`** field describing the nature of the product.

| Value | Meaning |
|-------|---------|
| `PHYSICAL` | Physical item. Uses stock. May use delivery or pickup. |
| `DIGITAL` | Digitally delivered item (e.g. PDF). No physical shipping; available for download once payment succeeds. |

Only `PHYSICAL` and `DIGITAL` are valid. Invalid values are rejected with `422`. Once a product has orders or digital assets, its type is locked.

### Where `item_type` appears

- **List products** (`GET /api/v1/general/products`): each object in `data.data` includes `"item_type"`.
- **Product by slug** (`GET /api/v1/general/products/{slug}`): included at the top level of `data`.
- **Admin create/update** (`POST|PUT /api/v1/products`): send `item_type` to set it; omit it to default to `PHYSICAL`.

```json
{ "id": 123, "uuid": "xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx", "name": "Windows 11 Pro License", "item_type": "DIGITAL" }
```

### Creating/updating a Product (Admin)

Send one of:

```json
{ "item_type": "PHYSICAL" }
```

```json
{ "item_type": "DIGITAL" }
```

Omitting the field defaults new products to `PHYSICAL`. Unsupported values — including the retired `SERVICE` — are rejected with `422`.

### Important: `item_type` is NOT the Product Category

Category and product nature are independent concepts. Example — Category: **Gaming**:

| Product | item_type |
|---------|-----------|
| PlayStation Controller | `PHYSICAL` |
| PlayStation Gift Card | `DIGITAL` |
| Gaming Setup Service Booking | `DIGITAL` (voucher delivered electronically) |

> Note: `product_type` is a DIFFERENT existing field (`simple`/`variable`) describing variant structure. Do not confuse it with `item_type`.

### Product Type Behavior

**PHYSICAL**
- Physical item.
- May require stock.
- May use delivery or pickup.

**DIGITAL**
- Digitally delivered item.
- No physical shipping.
- May deliver a code, license, card, file, or similar digital asset.
- Entitlements are product-scoped: files uploaded later to a purchased DIGITAL product automatically become available for download to its entitled customers.

---

## Endpoints

---

### 1. List Products (Public - Strategy-based)

**GET** `/api/v1/general/products`

**Purpose:** Retrieve products based on display strategy. Supports multiple listing modes, advanced filtering, and full-text search.

#### Authentication

| Aspect | Detail |
|--------|--------|
| Required | No |

#### Query Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `type` | `string` | No | Strategy: `index`, `best_product_sales`, `brands_product`, `new_arrivals`, `all_product_discounts`, `product_discount_today_or_low_qty`, `flash_sales_product`, `flash_sales_end_today`, `flash_sales_end_week`, `product_for_parent_category` |
| `search` | `string` | No | Full-text search (name, desc, sku, categories) via Laravel Scout (Meilisearch) |
| `limit` | `integer` | No | Per page (max 100, default 30) |
| `order` | `asc\|desc` | No | Sort by ID |
| `order_price` | `asc\|desc` | No | Sort by current price |
| `category` | `string\|array` | No | Filter by category slug (recursive descendants) |
| `brand` | `string\|array` | No | Filter by brand name/slug |
| `tag` | `string\|array` | No | Filter by tag slug (AND logic). Comma-separated or array format. |
| `tags` | `string\|array` | No | Filter by tag slug or ID (AND logic). Supports both slug and numeric ID lookup. Comma-separated or array format. |
| `promotion` | `string\|array` | No | Filter by promotion slug |
| `flash_sale` | `string\|array` | No | Filter by flash sale slug/title |
| `banner` | `string\|array` | No | Filter by banner slug/title |
| `slider` | `string\|array` | No | Filter by slider slug |
| `minPrice` | `float` | No | Minimum price (product + variant) |
| `maxPrice` | `float` | No | Maximum price (product + variant) |
| `rating` | `float` | No | Minimum avg rating |
| `rating_min` | `float` | No | Minimum rating floor |
| `rating_max` | `float` | No | Maximum rating ceiling |
| `height` | `string\|array` | No | Filter by height |
| `width` | `string\|array` | No | Filter by width |
| `length` | `string\|array` | No | Filter by length |
| `weight` | `string\|array` | No | Filter by weight |
| `productsId` | `string` | No | Comma-separated product IDs |
| `categoriesId` | `string` | No | Comma-separated category IDs |
| `brandsId` | `string` | No | Comma-separated brand IDs |
| `promotionsId` | `string` | No | Comma-separated promotion IDs |
| `flashSalesId` | `string` | No | Comma-separated flash sale IDs |
| `bannersId` | `string` | No | Comma-separated banner IDs |
| `couponsId` | `string` | No | Comma-separated coupon IDs |
| `slidersId` | `string` | No | Comma-separated slider IDs |

#### Success Response (200)

```json
{
    "success": true,
    "message": "MESSAGE.FETCH_DATA_SUCCESSFULLY",
    "data": {
        "data": [
            {
                "id": 1,
                "name": "Wireless Headphones",
                "slug": "wireless-headphones",
                "price": 99.99,
                "has_variants": false,
                "item_type": "PHYSICAL",
                "current_price": 79.99,
                "quantity": 50,
                "in_stock": true,
                "discount_active": true,
                "flash_sale_active": false,
                "is_fast_shipping_available": true,
                "ratings": 4.5,
                "image": {
                    "thumbnail": "https://cdn.example.com/thumb.jpg",
                    "original": [
                        "https://cdn.example.com/img1.jpg",
                        "https://cdn.example.com/img2.jpg"
                    ]
                }
            }
        ],
        "current_page": 1,
        "from": 1,
        "to": 30,
        "last_page": 5,
        "per_page": 30,
        "total": 150,
        "filters": { "brands": [], "categories": [], "attributes": [], "ratings": {}, "dimensions": {} },
        "categories": [ { "id": 1, "name": "Subcategory", "slug": "sub-cat", "image": { "desktop": null, "mobile": null } } ]
    }
}
```

---

### 2. Get Product by Slug (Public)

**GET** `/api/v1/general/products/{slug}`

**Purpose:** Retrieve a single product with full details, reviews, related products, and dynamic filters.

#### Authentication

| Aspect | Detail |
|--------|--------|
| Required | No |

#### Query Parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| `limit` | `integer` | Number of related products to include |

#### Success Response (200)

```json
{
    "success": true,
    "message": "MESSAGE.FETCH_DATA_SUCCESSFULLY",
    "data": {
        "id": 1,
        "name": "Wireless Headphones",
        "slug": "wireless-headphones",
        "description": "Premium wireless headphones with noise cancellation.",
        "price": 99.99,
        "current_price": 79.99,
        "discount_type": "percentage",
        "discount_amount": 20,
        "start_date": "2026-07-01",
        "end_date": "2026-07-31",
        "sku": "PRD-001",
        "quantity": 50,
        "sold_quantity": 25,
        "in_stock": true,
        "product_type": "simple",
        "item_type": "PHYSICAL",
        "height": null,
        "width": null,
        "length": null,
        "weight": null,
        "has_flash_sale": false,
        "has_discount": true,
        "is_fast_shipping_available": false,
        "discount_valid": true,
        "discount_active": true,
        "flash_sale_active": false,
        "categories": [
            { "id": 1, "level": 1, "name": "Electronics", "slug": "electronics" },
            { "id": 2, "level": 0, "name": "Root Category", "slug": "root" }
        ],
        "images": {
            "thumbnail": "https://cdn.example.com/thumb.jpg",
            "original": [
                "https://cdn.example.com/img1.jpg",
                "https://cdn.example.com/img2.jpg"
            ]
        },
        "tags": [
            { "id": 1, "name": "new", "slug": "new" }
        ],
        "variants": [
            {
                "id": 10,
                "price": 109.99,
                "current_price": 89.99,
                "quantity": 20,
                "height": null,
                "width": null,
                "length": null,
                "weight": null,
                "attributes": [
                    { "attribute_name": "Color", "value": "Black" }
                ]
            }
        ],
        "reviews": [
            { "id": 1, "rating": 5, "comment": "Great product!", "user": { "name": "John" }, "images": [], "created_at": "2026-07-15T10:00:00Z" }
        ],
        "related_products": [
            { "id": 2, "name": "Earbuds", "slug": "earbuds", "price": 49.99, "has_variants": false, "item_type": "PHYSICAL", "current_price": 49.99, "quantity": 30, "in_stock": true, "discount_active": false, "flash_sale_active": false, "is_fast_shipping_available": false, "ratings": 4.0, "image": { "thumbnail": null, "original": [] } }
        ],
        "filters": { "brands": [], "categories": [], "attributes": [], "ratings": {}, "dimensions": {} }
    }
}
```

#### Error Responses

| Status | Condition |
|--------|-----------|
| 404 | Product not found |

---

### 3. List Products (Admin)

**GET** `/api/v1/products`

**Purpose:** Retrieve paginated list of all products with advanced filtering for admin management.

#### Authentication

| Aspect | Detail |
|--------|--------|
| Required | Yes |
| Guard | `sanctum` |
| Permission | `view-products` |

#### Query Parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| `page` | `integer` | Page number |
| `search` | `string` | Search by name/sku |
| `sort` | `string` | Sort field |
| `orderBy` | `string` | Order direction |
| `limit` | `integer` | Per page |
| `status` | `string` | Filter by status |
| `category` | `integer` | Filter by category ID |

#### Success Response (200)

```json
{
    "data": [
        {
            "id": 1,
            "name": "Wireless Headphones",
            "slug": "wireless-headphones",
            "price": 99.99,
            "current_price": 79.99,
            "sku": "PRD-001",
            "status": "publish",
            "product_type": "simple",
            "in_stock": true,
            "quantity": 50
        }
    ],
    "meta": {
        "current_page": 1,
        "last_page": 10,
        "per_page": 15,
        "total": 150
    }
}
```

---

### 4. Create Product (Admin)

**POST** `/api/v1/products`

**Purpose:** Create a new product with variants, images, categories, and discount/flash sale configuration.

#### Authentication

| Aspect | Detail |
|--------|--------|
| Required | Yes |
| Permission | `create-product` |

#### Request Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `name` | `array` | Yes | Translatable name (`{ en, ar }`) |
| `description` | `array` | Yes | Translatable description (max 10000) |
| `product_type` | `string` | Yes | `simple` or `variable` (variant structure) |
| `item_type` | `string` | No | Product nature: `PHYSICAL` (default) or `DIGITAL`. See [Product Type](#product-type-item_type) |
| `categories` | `array` | Yes | Array of category IDs |
| `images` | `array` | Yes | Array of image files (jpeg,png,jpg, max 2MB) |
| `in_stock` | `boolean` | Yes | Stock availability |
| `has_discount` | `boolean` | Yes | Has discount |
| `has_flash_sale` | `boolean` | Yes | Has flash sale |
| `price` | `numeric` | Sometimes | Required if product_type=simple |
| `type_id` | `integer` | No | Associated type/collection ID |
| `quantity` | `integer` | No | Stock quantity |
| `pieces` | `integer` | No | Pieces per unit (min 1) |
| `status` | `string` | No | ProductStatus enum value |
| `discount_type` | `string` | No | `percentage` or `fixed` |
| `discount_amount` | `numeric` | No | Discount value |
| `start_date` | `date` | No | Discount start |
| `end_date` | `date` | No | Discount end (after start_date) |
| `variants` | `array` | No | Array of variant objects |

#### Success Response (201)

```json
{
    "data": {
        "id": 1,
        "name": "Wireless Headphones",
        "slug": "wireless-headphones",
        "price": 99.99,
        "current_price": 79.99,
        "product_type": "simple",
        "item_type": "PHYSICAL",
        "status": "publish"
    }
}
```

---

### 5. Update Product (Admin)

**PUT** `/api/v1/products/{id}`

**Purpose:** Update an existing product (partial updates supported). Recategorization, re-pricing, and variant changes are fully synced.

#### Authentication

| Aspect | Detail |
|--------|--------|
| Required | Yes |
| Permission | `update-product` |

#### Success Response (200)

Returns updated product resource.

---

### 6. Delete Product (Admin)

**DELETE** `/api/v1/products/{id}`

**Purpose:** Soft-delete a single product.

#### Authentication

| Aspect | Detail |
|--------|--------|
| Required | Yes |
| Permission | `delete-product` |

#### Success Response (200)

```json
{
    "message": "Product deleted successfully"
}
```

---

### 7. Bulk Delete Products (Admin)

**POST** `/api/v1/products/bulk-delete`

**Purpose:** Soft-delete multiple products at once.

#### Authentication

| Aspect | Detail |
|--------|--------|
| Required | Yes |
| Permission | `delete-product` |

#### Request Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `ids` | `array` | Yes | Array of product IDs (min 1, distinct) |

---


