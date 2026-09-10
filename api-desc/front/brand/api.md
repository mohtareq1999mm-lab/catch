# API Reference — Brand Module (Public API)

---

### GET /api/v1/general/brands

List active brands with optional filtering.

**Authentication:** None (public)

**Rate Limit:** Subject to global `throttle:api` middleware

**Query Parameters:**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| limit | int | 10 | Number of brands to return |
| start_date | string (date) | - | Filter by created_at >= (Y-m-d) |
| end_date | string (date) | - | Filter by created_at <= (Y-m-d) |
| brandsId | string | - | Comma-separated brand IDs |
| order | string | desc | Sort order (asc, desc) |
| slug | string | - | Get single brand by slug (overrides list) |

**Response 200:**
```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": [
    {
      "id": 1,
      "name": "Nike",
      "slug": "nike",
      "image": {
        "desktop": "https://cdn.example.com/brands/nike-desktop.jpg",
        "mobile": "https://cdn.example.com/brands/nike-mobile.jpg"
      },
      "status": true
    }
  ]
}
```

**Quick Test:**
```bash
# List brands
curl -X GET "http://example.com/api/v1/general/brands" \
  -H "Accept: application/json"

# List with filters
curl -X GET "http://example.com/api/v1/general/brands?limit=5&order=asc" \
  -H "Accept: application/json"

# Get single brand by slug via query param
curl -X GET "http://example.com/api/v1/general/brands?slug=nike" \
  -H "Accept: application/json"
```

---

### GET /api/v1/general/brands/{slug}

Get a single brand by slug with its associated products.

**Authentication:** None (public)

**Response 200:**
```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": {
    "id": 1,
    "name": "Nike",
    "slug": "nike",
    "image": {
      "desktop": "https://cdn.example.com/brands/nike-desktop.jpg",
      "mobile": "https://cdn.example.com/brands/nike-mobile.jpg"
    },
    "status": true,
    "products": [
      {
        "id": 10,
        "name": "Air Max 270",
        "slug": "air-max-270",
        "price": 150.00,
        "price_after_discount": 129.99,
        "rating": 4.5,
        "image": {
          "thumbnail": "https://cdn.example.com/products/air-max-270.jpg"
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
# Get brand by slug
curl -X GET "http://example.com/api/v1/general/brands/nike" \
  -H "Accept: application/json"

# Non-existent brand
curl -X GET "http://example.com/api/v1/general/brands/nonexistent" \
  -H "Accept: application/json"
```

**Business Rules:**
- Brand must have `status = 1` (active) to be returned
- Products are channel-filtered (home or fast-shipping)
- Products are enriched with real-time pricing (discounts, flash sales)
- Only approved reviews contribute to the average rating

---

### GET /api/v1/general/brands-products

Fetch a flat list of products from multiple brands, limited per brand.

**Authentication:** None (public)

**Query Parameters:**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| limit | int | 10 | Products per brand |
| limit_brand | int | 10 | Number of brands to include |
| start_date | string (date) | - | Filter brands by created_at >= |
| end_date | string (date) | - | Filter brands by created_at <= |

**Response 200:**
```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": [
    {
      "id": 10,
      "name": "Air Max 270",
      "slug": "air-max-270",
      "price": 150.00,
      "price_after_discount": 129.99,
      "rating": 4.5,
      "image": {
        "thumbnail": "https://cdn.example.com/products/air-max-270.jpg"
      }
    }
  ]
}
```

**Quick Test:**
```bash
# Get brand products
curl -X GET "http://example.com/api/v1/general/brands-products?limit=4&limit_brand=6" \
  -H "Accept: application/json"
```

**Business Rules:**
- Returns a flat array of products (brand grouping is internal to the query)
- Products are enriched with real-time pricing
- Products are sorted by brand ID descending within the query
