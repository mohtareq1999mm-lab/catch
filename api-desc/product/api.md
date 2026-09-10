# Product Module — Full API Reference

---

## Product Type (`item_type`)

Every product carries an **`item_type`** field that describes the nature of the product and its fulfillment behavior.

| Value | Meaning | Examples | Inventory Implication | Fulfillment Implication |
|-------|---------|----------|----------------------|------------------------|
| `PHYSICAL` | Physical/tangible product (default) | Phone, clothes, laptop, accessories | Uses physical stock reservation/deduction | May require shipping or pickup |
| `DIGITAL` | Digital product delivered electronically after purchase | PDF document, license, activation code, downloadable file | Unlimited availability for MVP; no physical stock touched | Delivered electronically once payment succeeds |

**Important rules:**

- Only `PHYSICAL` and `DIGITAL` are valid. (`SERVICE` was removed from the domain.)
- `item_type` is a **PRODUCT-level attribute**. It is NOT the product category.
- Do NOT treat `DIGITAL` as `SERVICE`.
- Defaults to `PHYSICAL` when omitted (all legacy products resolve to `PHYSICAL`).
- Invalid values are rejected with `422`.
- **Immutability:** once a product has order items or digital assets attached, its `item_type` can no longer be changed — attempts return `422`.

> Note: `product_type` is a DIFFERENT field. It holds the variant structure of the product (`simple` or `variable`) and is derived server-side from whether variants are present. It must not be confused with `item_type`.

---

## Routes Overview

| Method | URI | Controller@function | Permission |
|--------|-----|---------------------|------------|
| `GET` | `/products` | `ProductController@index` | `view-products` |
| `POST` | `/products` | `ProductController@store` | `create-product` |
| `GET` | `/products/{id}` | `ProductController@show` | `view-products` |
| `PUT` | `/products/{id}` | `ProductController@update` | `update-product` |
| `DELETE` | `/products/{id}` | `ProductController@destroy` | `delete-product` |
| `POST` | `/products/bulk-delete` | `ProductController@destroyBulk` | `delete-product` |
| `DELETE` | `/products/all` | `ProductController@destroyAll` | `delete-product` |
| `POST` | `/products/import` | `ProductImportController@import` | `create-product` or `super_admin` |
| `POST` | `/products/import/{id}/cancel` | `ProductImportController@cancel` | `create-product` or `super_admin` |
| `GET` | `/products/import/{id}` | `ProductImportController@status` | `create-product` or `super_admin` |
| `GET` | `/products/import/{id}/download-errors` | `ProductImportController@downloadErrors` | `create-product` or `super_admin` |
| `GET` | `/reviews` | `ReviewController@index` | public (requires `product_id` query) |
| `POST` | `/reviews` | `ReviewController@store` | customer auth |
| `GET` | `/reviews/{id}` | `ReviewController@show` | public |
| `PUT` | `/reviews/{id}` | `ReviewController@update` | customer auth |
| `DELETE` | `/reviews/{id}` | `ReviewController@destroy` | `delete-reviews` |
| `PATCH` | `/reviews/{id}/toggle-approve` | `ReviewController@toggleApproveReview` | `approve-reviews` |

---

## GET /products

List paginated products with search, filter, sort.

**Auth:** Public (no token required for index+show)

**Permissions:** none for public; `view-products` for authenticated scope

## Query Parameters (GET /products)

| Field | Type | Default | Description |
|-------|------|---------|-------------|
| `page` | int | 1 | Page number |
| `limit` | int | 15 | Results per page |
| `search` | string | — | Search in product name, description, SKU, and variant SKUs |
| `sort` | string | `desc` | Legacy sort direction by `created_at` (`asc` or `desc`) |
| `orderBy` | string | `created_at` | Column to sort by. Supported: `created_at`, `updated_at`, `name`, `price`, `sold_quantity`, `sku`, `id` |
| `orderDir` | string | `desc` | Sort direction (`asc` or `desc`) |
| `date_range` | string | — | Date range `YYYY-MM-DD//YYYY-MM-DD` for availability filtering |
| `status` | int | — | Filter by product status (`0` or `1`) |
| `category` | string | — | Filter by category slug (e.g. `?category=electronics`) |
| `banner` | string | — | Filter by banner slug (e.g. `?banner=summer-sale`) |
| `flash_sale` | string | — | Filter by flash sale slug (e.g. `?flash_sale=flash-01`) |
| `promotion` | string | — | Filter by promotion slug (e.g. `?promotion=summer-deal`) |
| `slider` | string | — | Filter by slider slug (e.g. `?slider=hero-banner`) |
| `tags` | string | — | Filter by tag slug or ID (e.g. `?tags=summer` or `?tags=1,2`). Comma-separated. AND logic. |
### Response 200

```json
{
  "success": true,
  "message": "MESSAGE.FETCH_DATA_SUCCESSFULLY",
  "data": {
    "data": [
      {
        "id": 1,
        "name": "T-Shirt",
        "slug": "t-shirt",
        "description": "A comfortable cotton t-shirt",
        "price": 29.99,
        "current_price": 19.99,
        "price_after_discount": null,
        "price_after_flash_sale": null,
        "sku": "PRD-001",
        "product_type": "simple",
        "item_type": "PHYSICAL",
        "in_stock": true,
        "status": "publish",
        "has_discount": true,
        "has_flash_sale": false,
        "discount_type": "percentage",
        "discount_amount": 33,
        "stock_quantity": 100,
        "sold_quantity": 25,
        "image": "https://cdn.example.com/products/1/image.jpg",
        "tags": [
          { "id": 1, "name": "summer", "slug": "summer" }
        ],
        "categories": [
          { "id": 2, "name": "Clothing", "slug": "clothing" }
        ]
      }
    ],
    "current_page": 1,
    "from": 1,
    "to": 15,
    "last_page": 5,
    "per_page": 15,
    "total": 72
  }
}
```

---

## GET /products/{id}

Show single product by ID or slug.

**Auth:** Public

### Path Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `id` | integer/string | Yes | Product ID or slug |

### Response 200

```json
{
  "success": true,
  "message": "MESSAGE.FETCH_DATA_SUCCESSFULLY",
  "data": {
    "id": 1,
    "name": "T-Shirt",
    "slug": "t-shirt",
    "description": "A comfortable cotton t-shirt",
    "price": 29.99,
    "current_price": 19.99,
    "product_type": "variable",
    "item_type": "PHYSICAL",
    "sku": "PRD-001",
    "in_stock": true,
    "status": "publish",
    "has_discount": true,
    "discount_type": "percentage",
    "discount_amount": 33,
    "variants": [
      {
        "id": 10,
        "sku": "VAR-RED-S",
        "price": 29.99,
        "current_price": 19.99,
        "stock_quantity": 50,
        "in_stock": true,
        "attributes": [
          { "id": 1, "name": "Color", "value": "Red" },
          { "id": 5, "name": "Size", "value": "S" }
        ]
      }
    ],
    "categories": [
      { "id": 2, "name": "Clothing", "slug": "clothing" }
    ],
    "brands": [],
    "tags": [],
    "reviews": [],
    "related_products": [],
    "created_at": "2024-01-15T10:00:00Z"
  }
}
```

### Response 404

```json
{
  "success": false,
  "message": "MESSAGE.NOT_FOUND"
}
```

---

## POST /products

Create a new product.

**Auth:** Required (auth:sanctum, email.verified)

**Permission:** `create-product`

### Request Body

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `name` | object | **Yes** | `{ "en": "...", "ar": "..." }` |
| `description` | object | **Yes** | `{ "en": "...", "ar": "..." }` |
| `product_type` | string | **Yes** | `simple` or `variable` (variant structure; derived server-side from `variants`) |
| `item_type` | string | No | Product nature: `PHYSICAL` (default) or `DIGITAL`. See [Product Type](#product-type-item_type) |
| `price` | float | sometimes | Required if `product_type=simple` |
| `categories` | array | **Yes** | Array of category IDs |
| `images` | array | **Yes** | Array of uploaded image files |
| `in_stock` | boolean | **Yes** | `1` or `0` |
| `has_discount` | boolean | **Yes** | `true` or `false` or `1` or `0` |
| `has_flash_sale` | boolean | **Yes** | `true` or `false` or `1` or `0` |
| `type_id` | integer | No | Product type ID |
| `quantity` | integer | No | Stock quantity |
| `sku` | string | No | Auto-generated if empty |
| `status` | string | No | One of: `publish`, `draft`, `under_review`, `approved`, `rejected`, `unpublish` |
| `discount_type` | string | No | `percentage` or `fixed_rate` or `free_shipping` (required if has_discount) |
| `discount_amount` | float | No | Required if has_discount |
| `discount_status` | boolean | No | Required if has_discount |
| `start_date` | date | No | Discount start |
| `end_date` | date | No | Discount end (after_or_equal:start_date) |
| `flash_sale_id` | integer | No | Required if has_flash_sale |
| `variants` | array | No | Array of variant objects (required if product_type=variable) |
| `tags` | array | No | Array of tag IDs |
| `brands` | array | No | Array of brand IDs |
| `banners` | array | No | Array of banner IDs |
| `sliders` | array | No | Array of slider IDs |
| `pieces` | integer | No | Pieces per unit (default: 1) |
| `height/width/length/weight` | string | No | Dimensions |
| `is_fast_shipping_available` | boolean | No | Fast shipping flag |

### Variant Object

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `price` | float | **Yes** | Variant price |
| `quantity` | integer | **Yes** | Variant stock |
| `attribute_values` | array | **Yes** | Array of `attribute_value` IDs |
| `sku` | string | No | Unique SKU |
| `height/width/length/weight` | string | No | Variant dimensions |

### Response 201

```json
{
  "success": true,
  "message": "MESSAGE.CREATE_PRODUCT_SUCCESSFULLY",
  "data": {
    "id": 1,
    "name": "New Product",
    "slug": "new-product",
    "product_type": "simple",
    "item_type": "PHYSICAL",
    "price": 49.99,
    "current_price": 49.99,
    "in_stock": true,
    "status": "draft"
  }
}
```

### Validation Errors 422

```json
{
  "name.en": ["The name.en field is required."],
  "categories": ["The categories field is required."],
  "images": ["The images field is required."]
}
```

Invalid `item_type` (e.g. `"item_type": "VIRTUAL"`):

```json
{
  "item_type": ["The selected item type is invalid."]
}
```

---

## PUT /products/{id}

Update an existing product.

**Auth:** Required (auth:sanctum, email.verified)

**Permission:** `update-product`

### Path Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `id` | integer | Yes | Product ID |

### Request Body

Same fields as POST but all are optional (`sometimes`). Only include fields that need updating.

The `name.*` unique validation ignores the current product's own name.

`tags` can be included to update product-tag associations (replaces existing tags).

`item_type` can be updated (e.g. change `PHYSICAL` → `DIGITAL`). Invalid values return `422`.

### Response 200

Same shape as POST response with `MESSAGE.UPDATE_PRODUCT_SUCCESSFULLY`.

---

## DELETE /products/{id}

Soft-delete a product.

**Auth:** Required (auth:sanctum, email.verified)

**Permission:** `delete-product`

### Response 200

```json
{
  "success": true,
  "message": "MESSAGE.DELETE_PRODUCT_SUCCESSFULLY"
}
```

### Response 404

```json
{
  "success": false,
  "message": "MESSAGE.NOT_FOUND"
}
```

---

## POST /products/bulk-delete

Delete multiple products by IDs (hard delete).

**Auth:** Required (auth:sanctum)

**Permission:** `delete-product`

### Request Body

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `ids` | array | **Yes** | Array of product IDs to delete |

### Response 200

```json
{
  "success": true,
  "message": "MESSAGE.PRODUCTS_DELETED_SUCCESSFULLY"
}
```

---

## DELETE /products/all

Delete all products (hard delete).

**Auth:** Required (auth:sanctum)

**Permission:** `delete-product`

### Response 200

```json
{
  "success": true,
  "message": "MESSAGE.PRODUCTS_DELETED_SUCCESSFULLY"
}
```

---

## POST /products/import

Start a product import job from a spreadsheet file.

**Auth:** Required (auth:sanctum)

**Permission:** `create-product` or `super_admin`

### Request Body

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `file` | file | **Yes** | Excel/CSV file (.xlsx, .csv) |

### Response 202

```json
{
  "success": true,
  "message": "Import started successfully",
  "data": {
    "import_id": 1,
    "status": "pending"
  }
}
```

---

## GET /products/import/{id}

Get the status of an import job.

**Auth:** Required (auth:sanctum)

**Permission:** `create-product` or `super_admin`

### Response 200

```json
{
  "status": 200,
  "message": "Import status fetched",
  "success": true,
  "data": {
    "id": 1,
    "status": "processing",
    "total_rows": 100,
    "processed_rows": 45,
    "success_rows": 40,
    "failed_rows": 5,
    "progress": 45.0,
    "errors": null
  }
}
```

---

## POST /products/import/{id}/cancel

Cancel a pending/processing import job.

**Auth:** Required (auth:sanctum)

**Permission:** `create-product` or `super_admin`

### Response 200

```json
{
  "success": true,
  "message": "Import cancelled successfully",
  "data": {
    "import_id": 1,
    "status": "cancelled"
  }
}
```

### Response 409 (cannot cancel completed import)

```json
{
  "success": false,
  "message": "Import cannot be cancelled"
}
```

---

## GET /products/import/{id}/download-errors

Download an Excel file with rows that failed during import.

**Auth:** Required (auth:sanctum)

**Permission:** `create-product` or `super_admin`

### Response 200

Binary file download (xlsx).

### Response 404

```json
{
  "success": false,
  "message": "No errors found"
}
```

