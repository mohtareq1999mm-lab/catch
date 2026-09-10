# API Reference — Banner Module (Public API)

---

### GET /api/v1/general/banners

List active banners with optional filtering.

**Authentication:** None (public)

**Rate Limit:** Subject to global `throttle:api`

**Query Parameters:**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| limit | int | 10 | Number of banners to return |
| start_date | string (date) | - | Filter by created_at >= |
| end_date | string (date) | - | Filter by created_at <= |
| bannersId | string | - | Comma-separated banner IDs |
| order | string | desc | Sort order (asc, desc) |
| slug | string | - | Get single banner by slug |

**Response 200:**
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
      "description": "Get up to 50% off on summer collection",
      "image": {
        "desktop": "https://cdn.example.com/banners/summer-desktop.jpg",
        "mobile": "https://cdn.example.com/banners/summer-mobile.jpg"
      },
      "status": true
    }
  ]
}
```

**Quick Test:**
```bash
# List banners
curl -X GET "http://example.com/api/v1/general/banners" \
  -H "Accept: application/json"

# Get single banner by slug via query param
curl -X GET "http://example.com/api/v1/general/banners?slug=summer-sale" \
  -H "Accept: application/json"
```

---

### GET /api/v1/general/banners/{slug}

Get a single banner by slug with optional associated products.

**Authentication:** None (public)

**Query Parameters:**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| with_products | string | true | Set to `false` to exclude products |

**Response 200:**
```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": {
    "id": 1,
    "title": "Summer Sale",
    "slug": "summer-sale",
    "description": "Get up to 50% off on summer collection",
    "image": {
      "desktop": "https://cdn.example.com/banners/summer-desktop.jpg",
      "mobile": "https://cdn.example.com/banners/summer-mobile.jpg"
    },
    "status": true,
    "products": [
      {
        "id": 10,
        "name": "Summer Dress",
        "slug": "summer-dress",
        "price": 79.99,
        "has_variants": false,
        "current_price": 49.99,
        "quantity": 150,
        "in_stock": true,
        "discount_active": true,
        "flash_sale_active": false,
        "is_fast_shipping_available": false,
        "ratings": 4.2,
        "image": {
          "thumbnail": "https://cdn.example.com/products/summer-dress-thumb.jpg",
          "original": []
        }
      }
    ]
  }
}
```

**Response 404:**
```json
{
  "status": 404,
  "message": "Data not found",
  "success": false
}
```

**Quick Test:**
```bash
# Get banner with products
curl -X GET "http://example.com/api/v1/general/banners/summer-sale" \
  -H "Accept: application/json"

# Get banner without products
curl -X GET "http://example.com/api/v1/general/banners/summer-sale?with_products=false" \
  -H "Accept: application/json"

# Non-existent banner
curl -X GET "http://example.com/api/v1/general/banners/nonexistent" \
  -H "Accept: application/json"
```

**Business Rules:**
- Only `status = true` banners are returned
- Soft-deleted banners are excluded
- Products are channel-filtered and enriched with real-time pricing
- Banners are ordered by `id` (not the `order` sortable column)
