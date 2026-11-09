# Products API Endpoints Reference

## 1. Admin — List Products (Paginated)

- **Method & URL:** `GET /api/v1/products`
- **Headers:** `Authorization: Bearer {token}`, `Accept-Language: en|ar`
- **Permission:** `view-products`

### Query Parameters

| Parameter | Type | Default | Description |
| :--- | :--- | :--- | :--- |
| `page` | `integer` | `1` | Page number |
| `limit` | `integer` | `15` | Items per page |
| `search` | `string` | `null` | Search in `name`, `description`, `sku`, or `variations.sku` |
| `orderBy` | `string` | `created_at` | `created_at`, `updated_at`, `name`, `price`, `sold_quantity`, `sku`, `id` |
| `orderDir` | `string` | `desc` | `asc` or `desc` |
| `sort` | `string` | `null` | Legacy sort on `created_at` (`asc` or `desc`) |
| `status` | `integer` | `null` | `1` (active) or `0` (inactive) |
| `item_type` | `string` | `null` | `PHYSICAL` or `DIGITAL` |
| `category` | `string` | `null` | Filter by category `slug` |
| `banner` | `string` | `null` | Filter by banner `slug` |
| `flash_sale` | `string` | `null` | Filter by flash sale `slug` |
| `slider` | `string` | `null` | Filter by slider `slug` |

### Response (`200 OK`)

```json
{
  "success": true,
  "message": "Products retrieved successfully",
  "data": {
    "data": [
      {
        "id": 101,
        "name": "Wireless Headphones",
        "slug": "wireless-headphones",
        "description": "High-resolution wireless headphones",
        "price": 250,
        "tax": {
          "tax_enabled": true,
          "tax_rate": 15,
          "effective_tax_rate": 15
        },
        "price_including_tax": 287.5,
        "sku": "PRD-101",
        "sold_quantity": 12,
        "quantity": 50,
        "stock_quantity": 50,
        "reserved_quantity": 2,
        "available_stock": 48,
        "in_stock": 1,
        "status": 1,
        "product_type": "simple",
        "item_type": "PHYSICAL",
        "piece_barcode": "6281000001011",
        "carton_barcode": "6281000001012",
        "pieces_per_carton": 10,
        "height": "20",
        "length": "18",
        "width": "8",
        "weight": "0.35",
        "pieces": 1,
        "has_flash_sale": false,
        "has_discount": true,
        "start_date": "2026-11-01",
        "end_date": "2026-11-30",
        "discount_type": "percentage",
        "discount_amount": 10,
        "discount_status": 1,
        "current_price": 225,
        "ratings": 4.5,
        "total_reviews": 8,
        "my_review": null,
        "in_wishlist": false,
        "is_fast_shipping_available": true,
        "categories": [
          {
            "id": 3,
            "name": "Electronics",
            "slug": "electronics"
          }
        ],
        "brands": [
          {
            "id": 2,
            "name": "Sony",
            "slug": "sony"
          }
        ],
        "tags": [],
        "banners": [],
        "sliders": [],
        "flash_sales": null,
        "images": [
          {
            "id": 501,
            "original": "http://localhost/storage/products/501/headphones.jpg",
            "thumbnail": "http://localhost/storage/products/501/conversions/headphones-thumb.jpg"
          }
        ],
        "locations": [
          {
            "id": 1,
            "code": "WH1-A-01",
            "type": "pick",
            "is_active": true,
            "warehouse_id": 1,
            "quantity": 50,
            "reserved_quantity": 2
          }
        ],
        "variants": []
      }
    ],
    "links": {
      "current_page": 1,
      "from": 1,
      "to": 1,
      "last_page": 1,
      "path": "http://localhost/api/v1/products",
      "per_page": 15,
      "total": 1,
      "next_page_url": null,
      "prev_page_url": null,
      "last_page_url": "http://localhost/api/v1/products?page=1",
      "first_page_url": "http://localhost/api/v1/products?page=1"
    }
  }
}
```

---

## 2. Admin — Create Product

- **Method & URL:** `POST /api/v1/products`
- **Headers:** `Authorization: Bearer {token}`, `Content-Type: multipart/form-data`, `Accept: application/json`
- **Permission:** `create-product`

### Request Body (`multipart/form-data`)

| Field | Type | Required | Rules / Notes |
| :--- | :--- | :--- | :--- |
| `name[en]` | `string` | Yes | Max `255`, unique in `products.name` |
| `name[ar]` | `string` | Yes | Max `255`, unique in `products.name` |
| `description[en]` | `string` | Yes | Max `2000` |
| `description[ar]` | `string` | Yes | Max `2000` |
| `product_type` | `string` | Yes | `simple` or `variable` |
| `item_type` | `string` | No | `PHYSICAL` (default) or `DIGITAL` |
| `price` | `numeric` | Conditional | Required if `product_type = simple`, min `0` |
| `sku` | `string` | No | Unique in `products.sku` (auto-generated as `PRD-###` if omitted) |
| `quantity` | `integer` | No | Min `1` |
| `pieces` | `integer` | No | Min `1` |
| `in_stock` | `integer` | Yes | `1` or `0` |
| `status` | `integer` | No | `1` (active) or `0` (inactive) |
| `categories[]` | `array<int>` | Yes | Existing category IDs (`exists:categories,id`) |
| `brands[]` | `array<int>` | No | Existing brand IDs (`exists:brands,id`) |
| `tags[]` | `array<int>` | No | Existing tag IDs (`exists:tags,id`) |
| `banners[]` | `array<int>` | No | Existing banner IDs (`exists:banners,id`) |
| `sliders[]` | `array<int>` | No | Existing slider IDs (`exists:sliders,id`) |
| `cross_sell_ids[]` | `array<int>` | No | Existing product IDs (`exists:products,id`, distinct) |
| `images[]` | `array<file>` | Yes | Image files (`jpeg,png,jpg,gif,webp`), max `2048 KB` each |
| `has_discount` | `boolean/int` | Yes | `1`, `0`, `true`, `false` |
| `discount_type` | `string` | Conditional | Required if `has_discount = 1`: `fixed` or `percentage` |
| `discount_amount` | `numeric` | Conditional | Required if `has_discount = 1`, min `1` (if `percentage`, must be `< 100`) |
| `discount_status` | `integer` | Conditional | Required if `has_discount = 1`: `1` or `0` |
| `start_date` | `date` | No | Required if `end_date` is provided; `>= today` |
| `end_date` | `date` | No | Required if `start_date` is provided; `> start_date` |
| `has_flash_sale` | `boolean/int` | Yes | `1`, `0`, `true`, `false` |
| `flash_sale_id` | `integer` | Conditional | Required if `has_flash_sale = 1` (`exists:flash_sales,id`) |
| `tax_enabled` | `boolean` | No | `true` or `false` |
| `tax_rate` | `numeric` | No | `0` to `100` |
| `height` / `length` / `width` / `weight` | `numeric` | No | Min `0` |
| `is_fast_shipping_available` | `boolean` | No | `1` or `0` |
| `piece_barcode` | `string` | No | Max `64`, unique across products & variations |
| `carton_barcode` | `string` | No | Max `64`, unique across products & variations |
| `pieces_per_carton` | `integer` | No | Min `1` |
| `locations[i][location_id]` | `integer` | No | Required when `locations` is sent (`exists:locations,id`) |
| `locations[i][quantity]` | `integer` | No | Required with `locations`, min `0` |
| `locations[i][warehouse_id]` | `integer` | No | Optional (`exists:warehouses,id`) |
| `variants[i][price]` | `numeric` | Conditional | Required when `variants` is sent |
| `variants[i][quantity]` | `integer` | Conditional | Required when `variants` is sent, min `0` |
| `variants[i][attribute_values][]` | `array<int>` | Conditional | Existing `attribute_values.id` |
| `variants[i][weight/length/width/height]` | `numeric` | No | Optional variant dimensions |
| `variants[i][locations]` | `array` | No | Same structure as product `locations` |

#### Example Request Payload (Simple Product)

```json
{
  "name": {
    "en": "Wireless Headphones",
    "ar": "سماعات لاسلكية"
  },
  "description": {
    "en": "High-resolution wireless headphones",
    "ar": "سماعات لاسلكية عالية الدقة"
  },
  "product_type": "simple",
  "item_type": "PHYSICAL",
  "price": 250,
  "quantity": 50,
  "pieces": 1,
  "in_stock": 1,
  "status": 1,
  "categories": [3],
  "brands": [2],
  "has_discount": 1,
  "discount_type": "percentage",
  "discount_amount": 10,
  "discount_status": 1,
  "start_date": "2026-11-10",
  "end_date": "2026-11-30",
  "has_flash_sale": 0,
  "tax_enabled": true,
  "tax_rate": 15,
  "piece_barcode": "6281000001011",
  "carton_barcode": "6281000001012",
  "pieces_per_carton": 10,
  "cross_sell_ids": [102, 103],
  "locations": [
    {
      "location_id": 1,
      "warehouse_id": 1,
      "quantity": 50
    }
  ],
  "images": ["(binary file)"]
}
```

#### Example Request Payload (Variable Product)

```json
{
  "name": {
    "en": "Cotton T-Shirt",
    "ar": "تيشيرت قطن"
  },
  "description": {
    "en": "100% Cotton T-Shirt",
    "ar": "تيشيرت قطن ١٠٠٪"
  },
  "product_type": "variable",
  "item_type": "PHYSICAL",
  "in_stock": 1,
  "status": 1,
  "categories": [5],
  "has_discount": 0,
  "has_flash_sale": 0,
  "variants": [
    {
      "price": 120,
      "quantity": 25,
      "weight": 0.2,
      "attribute_values": [11, 21],
      "locations": [
        {
          "location_id": 1,
          "warehouse_id": 1,
          "quantity": 25
        }
      ]
    }
  ],
  "images": ["(binary file)"]
}
```

### Response (`201 Created`)

Returns the full Admin `ProductResource` (same structure as **Section 3** below) with `"message": "Product created successfully"`.

---

## 3. Admin — Show Single Product

- **Method & URL:** `GET /api/v1/products/{id}` *(accepts numeric `id` or `slug`)*
- **Headers:** `Authorization: Bearer {token}`
- **Permission:** `view-products`

### Query Parameters

| Parameter | Type | Default | Description |
| :--- | :--- | :--- | :--- |
| `limit` | `integer` | `10` | Max number of `related_products` returned |

### Response (`200 OK`)

```json
{
  "success": true,
  "message": "Product retrieved successfully",
  "data": {
    "id": 101,
    "name": {
      "en": "Wireless Headphones",
      "ar": "سماعات لاسلكية"
    },
    "slug": "wireless-headphones",
    "description": {
      "en": "High-resolution wireless headphones",
      "ar": "سماعات لاسلكية عالية الدقة"
    },
    "price": 250,
    "tax": {
      "tax_enabled": true,
      "tax_rate": 15,
      "effective_tax_rate": 15
    },
    "price_including_tax": 287.5,
    "sku": "PRD-101",
    "sold_quantity": 12,
    "quantity": 50,
    "stock_quantity": 50,
    "reserved_quantity": 2,
    "available_stock": 48,
    "in_stock": 1,
    "status": 1,
    "product_type": "simple",
    "item_type": "PHYSICAL",
    "piece_barcode": "6281000001011",
    "carton_barcode": "6281000001012",
    "pieces_per_carton": 10,
    "height": "20",
    "length": "18",
    "width": "8",
    "weight": "0.35",
    "pieces": 1,
    "has_flash_sale": false,
    "has_discount": true,
    "start_date": "2026-11-10",
    "end_date": "2026-11-30",
    "discount_type": "percentage",
    "discount_amount": 10,
    "discount_status": 1,
    "current_price": 225,
    "ratings": 4.5,
    "total_reviews": 8,
    "my_review": null,
    "in_wishlist": false,
    "is_fast_shipping_available": true,
    "categories": [
      {
        "id": 3,
        "name": "Electronics",
        "slug": "electronics"
      }
    ],
    "brands": [
      {
        "id": 2,
        "name": "Sony",
        "slug": "sony"
      }
    ],
    "tags": [],
    "banners": [],
    "sliders": [],
    "flash_sales": null,
    "reviews": [],
    "images": [
      {
        "id": 501,
        "original": "http://localhost/storage/products/501/headphones.jpg",
        "thumbnail": "http://localhost/storage/products/501/conversions/headphones-thumb.jpg"
      }
    ],
    "locations": [
      {
        "id": 1,
        "code": "WH1-A-01",
        "type": "pick",
        "is_active": true,
        "warehouse_id": 1,
        "quantity": 50,
        "reserved_quantity": 2
      }
    ],
    "variants": [],
    "related_products": [],
    "cross_sell_ids": [102, 103],
    "saved_cross_sell_count": 2,
    "eligible_cross_sell_count": 2,
    "storefront_cross_sell_limit": 4,
    "cross_sell_products": []
  }
}
```

---

## 4. Admin — Update Product

- **Method & URL:**
  - `PUT /api/v1/products/{id}`

- **Headers:** `Authorization: Bearer {token}`, `Content-Type: multipart/form-data` (or `application/json`)
- **Permission:** `update-product`

### Request Body

All fields from **Create Product** are supported with `sometimes` validation (send only the fields you want to update).
For variable products, include `variants[i][id]` (`exists:variations,id`) to update an existing variant, or omit `id` to create a new variant.

```json
{
  "price": 230,
  "status": 1,
  "in_stock": 1,
  "has_discount": 0,
  "cross_sell_ids": [102]
}
```

### Response (`200 OK`)

Returns the updated Admin `ProductResource` with `"message": "Product updated successfully"`.

---

## 5. Admin — Delete Product(s)

### 5.1 Delete Single Product

- **Method & URL:** `DELETE /api/v1/products/{id}`
- **Permission:** `delete-product`

#### Response (`200 OK`)

```json
{
  "success": true,
  "message": "Product deleted successfully",
  "data": []
}
```

### 5.2 Bulk Delete Products

- **Method & URL:** `POST /api/v1/products/bulk-delete`
- **Permission:** `delete-all-products|delete-product`

#### Request Body (`application/json`)

```json
{
  "ids": [101, 102],
  "reason": "Discontinued seasonal items"
}
```

#### Response (`200 OK`)

```json
{
  "success": true,
  "message": "Products deleted successfully",
  "data": {
    "deleted_ids": [101, 102],
    "batch_uuid": "9d7b41c0-82a1-4c2b-9f11-04d81234abcd"
  }
}
```

---

## 6. Public Storefront — List Products (`GET /api/v1/general/products`)

- **Method & URL:** `GET /api/v1/general/products`
- **Headers:** `Accept-Language: en|ar`, `X-Currency: SAR|USD|...` (optional), `Authorization: Bearer {token}` (optional, resolves `in_wishlist` & `in_cart`)
- **Authentication:** Public

### Query Parameters

| Parameter | Type | Default | Description |
| :--- | :--- | :--- | :--- |
| `type` | `string` | `index` | Strategy type: `index`, `best_product_sales`, `new_arrivals`, `all_product_discounts`, `flash_sales_product`, `brands_product`, `product_for_parent_category` |
| `page` | `integer` | `1` | Page number (when `pagination=offset`) |
| `limit` | `integer` | `15` | Items per page (max `100`) |
| `pagination` | `string` | `offset` | `offset` or `cursor` |
| `search` | `string` | `null` | Full-text / keyword search |
| `order` | `string` | `null` | `asc` or `desc` (by `created_at`) |
| `order_price` | `string` | `null` | `asc` or `desc` (by effective price) |
| `category` | `string` | `null` | Category `slug` (also returns child `categories` in response) |
| `brand` | `string` | `null` | Brand `slug` |
| `tag` / `tags` | `string` | `null` | Tag `slug` or comma-separated IDs/slugs |
| `min_price` | `numeric` | `null` | Minimum price filter |
| `max_price` | `numeric` | `null` | Maximum price filter |
| `rating` | `numeric` | `null` | Exact/minimum rating filter |
| `rating_min` / `rating_max` | `numeric` | `null` | Rating range filter |
| `productsId` | `string/array` | `null` | Filter by specific product IDs |
| `categoriesId` | `string/array` | `null` | Filter by category IDs |
| `brandsId` | `string/array` | `null` | Filter by brand IDs |
| `flashSalesId` | `string/array` | `null` | Filter by flash sale IDs |
| `bannersId` | `string/array` | `null` | Filter by banner IDs |
| `slidersId` | `string/array` | `null` | Filter by slider IDs |
| `promotionsId` | `string/array` | `null` | Filter by promotion IDs |
| `couponsId` | `string/array` | `null` | Filter by coupon IDs |
| `attributes` | `array/string` | `null` | Attribute value filter |

### Response (`200 OK`)

```json
{
  "success": true,
  "message": "Products retrieved successfully",
  "data": {
    "products": {
      "data": [
        {
          "id": 101,
          "name": "Wireless Headphones",
          "slug": "wireless-headphones",
          "description": "High-resolution wireless headphones",
          "price": 287.5,
          "current_price": 258.75,
          "currency": "SAR",
          "discount_type": "percentage",
          "discount_amount": 10,
          "in_stock": true,
          "sold_quantity": 12,
          "has_flash_sale": false,
          "has_discount": true,
          "discount_active": true,
          "flash_sale_active": false,
          "ratings": 4.5,
          "in_wishlist": false,
          "in_cart": false,
          "is_fast_shipping_available": true,
          "product_type": "simple",
          "item_type": "PHYSICAL",
          "images": {
            "id": 501,
            "original": "http://localhost/storage/products/501/headphones.jpg",
            "thumbnail": "http://localhost/storage/products/501/conversions/headphones-thumb.jpg"
          }
        }
      ],
      "links": {
        "current_page": 1,
        "from": 1,
        "to": 1,
        "last_page": 1,
        "path": "http://localhost/api/v1/general/products",
        "per_page": 15,
        "total": 1,
        "next_page_url": null,
        "prev_page_url": null,
        "last_page_url": "http://localhost/api/v1/general/products?page=1",
        "first_page_url": "http://localhost/api/v1/general/products?page=1"
      }
    },
    "filters": {
      "brands": [],
      "categories": [],
      "tags": [],
      "attributes": [],
      "price": {
        "min": 258.75,
        "max": 287.5
      }
    },
    "categories": []
  }
}
```

---

## 7. Public Storefront — Get Product By Slug (`GET /api/v1/general/products/{slug}`)

- **Method & URL:** `GET /api/v1/general/products/{slug}`
- **Headers:** `Accept-Language: en|ar`, `X-Currency: SAR|USD|...` (optional), `Authorization: Bearer {token}` (optional)
- **Authentication:** Public

### Query Parameters

| Parameter | Type | Default | Description |
| :--- | :--- | :--- | :--- |
| `limit` | `integer` | `10` | Number of `related_products` to return (`1`–`100`) |

### Response (`200 OK`)

```json
{
  "success": true,
  "message": "Product retrieved successfully",
  "data": {
    "id": 101,
    "name": "Wireless Headphones",
    "slug": "wireless-headphones",
    "description": "High-resolution wireless headphones",
    "price": 287.5,
    "current_price": 258.75,
    "currency": "SAR",
    "discount_type": "percentage",
    "discount_amount": 10,
    "sku": "PRD-101",
    "sold_quantity": 12,
    "quantity": 48,
    "in_stock": true,
    "status": 1,
    "product_type": "simple",
    "item_type": "PHYSICAL",
    "piece_barcode": "6281000001011",
    "carton_barcode": "6281000001012",
    "pieces_per_carton": 10,
    "height": "20",
    "length": "18",
    "width": "8",
    "weight": "0.35",
    "pieces": 1,
    "has_flash_sale": false,
    "has_discount": true,
    "discount_active": true,
    "flash_sale_active": false,
    "start_date": "2026-11-10",
    "end_date": "2026-11-30",
    "ratings": 4.5,
    "ratings_count": {
      "1": 0,
      "2": 0,
      "3": 1,
      "4": 2,
      "5": 5
    },
    "total_reviews": 8,
    "my_review": null,
    "in_wishlist": false,
    "in_cart": false,
    "is_fast_shipping_available": true,
    "brands": [
      {
        "id": 2,
        "name": "Sony",
        "slug": "sony"
      }
    ],
    "categories": [
      {
        "id": 3,
        "name": "Electronics",
        "slug": "electronics"
      }
    ],
    "images": [
      {
        "thumbnail": "http://localhost/storage/products/501/conversions/headphones-thumb.jpg",
        "original": "http://localhost/storage/products/501/headphones.jpg"
      }
    ],
    "tags": [],
    "locations": [
      {
        "id": 1,
        "code": "WH1-A-01",
        "type": "pick",
        "is_active": true,
        "warehouse_id": 1,
        "quantity": 50,
        "reserved_quantity": 2
      }
    ],
    "variants": [],
    "reviews": [],
    "related_products": [],
    "eligible_cross_sell_count": 2,
    "cross_sell_products": [
      {
        "id": 102,
        "name": "Headphone Stand",
        "slug": "headphone-stand",
        "price": 75,
        "current_price": 75,
        "currency": "SAR",
        "in_stock": true,
        "product_type": "simple",
        "item_type": "PHYSICAL"
      }
    ],
    "filters": []
  }
}
```

### Error Response (`404 Not Found` — Inactive or Missing Slug)

```json
{
  "success": false,
  "message": "Not Found!",
  "data": []
}
```
