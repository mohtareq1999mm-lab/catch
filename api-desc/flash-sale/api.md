# API Reference — Flash Sale

---

## Admin Endpoints

---

### GET /api/v1/flash-sale

Paginated list of flash sales with filtering and sorting.

**Authentication**: `auth:sanctum`, permission: `view-flash-sale`

**Query Parameters**:

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| page | int | 1 | Page number |
| per_page | int | 10 | Items per page (alias: limit) |
| search | string | - | Search by title (LIKE, supports translatable fields) |
| active | bool | - | Filter by valid (status=true + within date range) |
| inactive | bool | - | Filter by invalid (status=false OR outside date range) |
| order | string | - | Sort column (id, title, slug, type, discount, status, start_date, end_date, created_at, updated_at) |
| sortedBy | string | asc | Sort direction (asc, desc) |

**Response 200**:
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
          "desktop": "https://example.com/storage/flashSales/desktop.jpg",
          "mobile": "https://example.com/storage/flashSales/mobile.jpg"
        },
        "description": "Amazing summer discounts",
        "start_date": "2026-07-01",
        "end_date": "2026-07-31",
        "status": true,
        "is_valid": true,
        "type": "Percentage discount",
        "discount": 25,
        "max_discount_amount": 100,
        "created_at": "2026-07-01"
      }
    ],
    "page": 1,
    "current_page": 1,
    "from": 1,
    "to": 10,
    "last_page": 3,
    "path": "http://example.com/api/v1/flash-sale",
    "per_page": 10,
    "total": 25,
    "next_page_url": "http://example.com/api/v1/flash-sale?page=2",
    "prev_page_url": "",
    "last_page_url": "http://example.com/api/v1/flash-sale?page=3",
    "first_page_url": "http://example.com/api/v1/flash-sale?page=1"
  }
}
```

**Quick Test**:
```bash
curl -X GET "http://example.com/api/v1/flash-sale?page=1&per_page=10" \
  -H "Authorization: Bearer {token}" \
  -H "Accept: application/json"

curl -X GET "http://example.com/api/v1/flash-sale?active=true&order=end_date&sortedBy=asc" \
  -H "Authorization: Bearer {token}" \
  -H "Accept: application/json"
```

**Business Rules**:
- Results are ordered by the `order` column by default
- Active and inactive filters are mutually exclusive
- Search applies to translatable `title` field (searches both locales)
- `is_valid` field in response indicates whether the sale is currently active (status + date range)

---

### POST /api/v1/flash-sale

Create a new flash sale.

**Authentication**: `auth:sanctum`, permission: `create-flash-sale`

**Request Body** (multipart/form-data):

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| title | object | required | Translatable title (e.g., `{"en": "Summer Sale", "ar": "تخفيضات الصيف"}`) |
| description | object | required | Translatable description (max 1000 per locale) |
| image-desktop | file | required | Desktop image (jpeg,png,jpg,webp) |
| image-mobile | file | required | Mobile image (jpeg,png,jpg,webp) |
| start_date | date | required | Sale start date |
| end_date | date | required | Sale end date |
| type | string | required | `percentage`, `fixed_rate`, or `final_price` |
| discount | numeric | required | Discount value (min:0) |
| max_discount_amount | numeric | required_if:type=percentage | Maximum discount cap for percentage type |
| status | int | required | 1 or 0 |
| products | array | sometimes | Array of product IDs to associate |

**Validation Rules**:
| Field | Rules |
|-------|-------|
| title.* | required, string, min:3, max:70, unique_translation:flash_sales,title |
| image-desktop | required, image, mimes:jpeg,png,jpg,webp |
| image-mobile | required, image, mimes:jpeg,png,jpg,webp |
| start_date | required, date |
| end_date | required, date |
| type | required, in:percentage,fixed_rate,final_price |
| discount | required, numeric, min:0 |
| max_discount_amount | required_if:type,percentage, numeric, min:1 |

**Response 200**:
```json
{
  "status": 200,
  "message": "Flash sale created successfully",
  "success": true,
  "data": {
    "id": 11,
    "title": "Summer Sale",
    "slug": "summer-sale",
    "image": { "desktop": "...", "mobile": "..." },
    "description": "Amazing summer discounts",
    "start_date": "2026-07-01",
    "end_date": "2026-07-31",
    "status": true,
    "is_valid": true,
    "type": "Percentage discount",
    "discount": 25,
    "max_discount_amount": 100,
    "created_at": "2026-07-19",
    "products": []
  }
}
```

**Quick Test**:
```bash
curl -X POST "http://example.com/api/v1/flash-sale" \
  -H "Authorization: Bearer {token}" \
  -H "Accept: application/json" \
  -F 'title[en]="Summer Sale"' \
  -F 'title[ar]="تخفيضات الصيف"' \
  -F 'description[en]="Amazing discounts"' \
  -F 'start_date=2026-07-01' \
  -F 'end_date=2026-07-31' \
  -F 'type=percentage' \
  -F 'discount=25' \
  -F 'max_discount_amount=100' \
  -F 'status=1' \
  -F 'image-desktop=@desktop.jpg' \
  -F 'image-mobile=@mobile.jpg'
```

---

### GET /api/v1/flash-sale/{id}

Get a single flash sale by ID or slug.

**Authentication**: `auth:sanctum`, permission: `view-flash-sale`

**Path Parameters**:

| Parameter | Type | Description |
|-----------|------|-------------|
| id | int/string | Flash sale ID (numeric) or slug (string) |

**Response 200**:
```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": {
    "id": 1,
    "title": "Summer Sale",
    "slug": "summer-sale",
    "image": { "desktop": "...", "mobile": "..." },
    "description": "Amazing summer discounts",
    "start_date": "2026-07-01",
    "end_date": "2026-07-31",
    "status": true,
    "is_valid": true,
    "type": "Percentage discount",
    "discount": 25,
    "max_discount_amount": 100,
    "created_at": "2026-07-01",
    "products": [
      { "id": 5, "name": "Product A", "slug": "product-a", ... }
    ]
  }
}
```

**Quick Test**:
```bash
curl -X GET "http://example.com/api/v1/flash-sale/1" \
  -H "Authorization: Bearer {token}" \
  -H "Accept: application/json"

curl -X GET "http://example.com/api/v1/flash-sale/summer-sale" \
  -H "Authorization: Bearer {token}" \
  -H "Accept: application/json"
```

---

### PUT /api/v1/flash-sale/{id}

Update an existing flash sale.

**Authentication**: `auth:sanctum`, permission: `update-flash-sale`

**Request Body** (multipart/form-data):

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| title | object | sometimes | Translatable title |
| description | object | sometimes | Translatable description |
| image-desktop | file | sometimes | New desktop image |
| image-mobile | file | sometimes | New mobile image |
| start_date | date | sometimes | Updated start date |
| end_date | date | sometimes | Updated end date |
| type | string | sometimes | `percentage`, `fixed_rate`, `final_price` |
| discount | numeric | sometimes | Discount value |
| max_discount_amount | numeric | required_if:type=percentage | Max discount cap |
| status | int | sometimes | 1 or 0 |
| products | array | sometimes | Array of product IDs (replaces all) |

**Response 200**:
```json
{
  "status": 200,
  "message": "Flash sale updated successfully",
  "success": true,
  "data": { "...": "..." }
}
```

**Quick Test**:
```bash
curl -X PUT "http://example.com/api/v1/flash-sale/1" \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"title": {"en": "Updated Sale"}, "status": 0}'
```

**Business Rules**:
- Slug is auto-regenerated from English title if title is changed
- Products array replaces ALL current associations (sync, not attach)
- Existing images are replaced (old collection is cleared)
- `price_after_flash_sale` is recalculated for all associated products
- Observer logs activity changes

---

### DELETE /api/v1/flash-sale/{id}

Soft-delete a flash sale.

**Authentication**: `auth:sanctum`, permission: `delete-flash-sale`

**Response 200**:
```json
{
  "status": 200,
  "message": "Flash sale deleted successfully",
  "success": true
}
```

**Quick Test**:
```bash
curl -X DELETE "http://example.com/api/v1/flash-sale/1" \
  -H "Authorization: Bearer {token}" \
  -H "Accept: application/json"
```

---

### PUT /api/v1/flash-sale/reorder

Reorder flash sales.

**Authentication**: `auth:sanctum`, permission: `update-flash-sale`

**Request Body**:
```json
{
  "flash_sales": [3, 1, 2]
}
```

**Note:** Field name is `flash_sales` (not `flash_sales.*` in validation).

**Response 200**:
```json
{
  "status": 200,
  "message": "Flash sales reordered successfully",
  "success": true
}
```

**Quick Test**:
```bash
curl -X PUT "http://example.com/api/v1/flash-sale/reorder" \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"flash_sales": [3, 1, 2]}'
```

---

### GET /api/v1/product-flash-sale-info

Get flash sale information by product ID.

**Authentication**: `auth:sanctum`

**Query Parameters**:

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| id | int | required | Product ID |

**Response 200**:
```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": [
    {
      "id": 1,
      "title": "Summer Sale",
      "slug": "summer-sale",
      "...": "..."
    }
  ]
}
```

**Quick Test**:
```bash
curl -X GET "http://example.com/api/v1/product-flash-sale-info?id=5" \
  -H "Authorization: Bearer {token}" \
  -H "Accept: application/json"
```

---

### GET /api/v1/products-by-flash-sale

Get products by flash sale slug.

**Authentication**: `auth:sanctum`

**Query Parameters**:

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| slug | string | required | Flash sale slug |
| per_page | int | 10 | Items per page |
| page | int | 1 | Page number |
| order | string | - | Sort column (id, title, slug, price, sale_price, quantity, created_at, updated_at) |
| sortedBy | string | asc | Sort direction |

---

## Public Endpoints

---

### GET /api/v1/general/flash-sales

List active flash sales.

**Authentication**: None (public)

**Query Parameters**:

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| limit | int | 10 | Items per page |
| page | int | 1 | Page number |
| slug | string | - | If provided, delegates to getFlashSaleBySlug |

**Quick Test**:
```bash
curl -X GET "http://example.com/api/v1/general/flash-sales?limit=10" -H "Accept: application/json"
```

---

### GET /api/v1/general/flash-sales/{slug}

Get flash sale by slug with associated products.

**Authentication**: None (public)

**Quick Test**:
```bash
curl -X GET "http://example.com/api/v1/general/flash-sales/summer-sale" -H "Accept: application/json"
```

---

### GET /api/v1/general/flash-sale-products

Get flash sale products by quantity set.

**Authentication**: None (public)

**Quick Test**:
```bash
curl -X GET "http://example.com/api/v1/general/flash-sale-products?limit=10" -H "Accept: application/json"
```

---

### GET /api/v1/general/flash-sale-products-ending-this-week

Products in flash sales ending within 7 days.

**Authentication**: None (public)

---

### GET /api/v1/general/flash-sale-products-ending-today

Products in flash sales ending today.

**Authentication**: None (public)
