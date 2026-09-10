# Brand Module — Frontend Integration Guide

## Endpoints

---

### 1. GET /api/v1/general/brands — List Active Brands (Public)

**Purpose:** Display brand logos/list on homepage, brand browsing page, or brand filter dropdown.

**Authentication:** None (public)

**Query Parameters:**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| limit | int | 10 | Number of brands to return |
| start_date | string (date) | - | Filter brands created after this date (Y-m-d) |
| end_date | string (date) | - | Filter brands created before this date (Y-m-d) |
| brandsId | string | - | Comma-separated brand IDs to filter by |
| order | string | desc | Sort direction (asc, desc) |
| slug | string | - | Get single brand by slug (overrides list behavior) |

**Response:**
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

---

### 2. GET /api/v1/general/brands/{slug} — Get Brand by Slug (Public)

**Purpose:** Display brand detail page with brand info and associated products.

**Authentication:** None (public)

**Response:**
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

**Notes:**
- Products are enriched with real-time pricing (discounts, flash sales applied)
- Only active products are returned (channel-filtered)
- If slug not found, returns 404 with `"Data not found"` message

---

### 3. GET /api/v1/general/brands-products — Brand Products by Quantity (Public)

**Purpose:** Fetch a flat list of products from multiple brands, limited by quantity per brand.

**Authentication:** None (public)

**Query Parameters:**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| limit | int | 10 | Products per brand |
| limit_brand | int | 10 | Number of brands to include |
| start_date | string (date) | - | Filter brands by creation date start |
| end_date | string (date) | - | Filter brands by creation date end |

**Response:**
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

**Notes:**
- Returns a flat array of products (brand groupings are internal)
- Products are enriched with real-time pricing
- Products limited per brand by `limit` param
- Brands limited by `limit_brand` param

---

## Frontend Usage

### Brand Listing Page
Use `GET /api/v1/general/brands` to show a grid of brand logos. Each card shows the brand image and name, linking to `/brands/{slug}`.

### Brand Detail Page
Use `GET /api/v1/general/brands/{slug}` to show brand info and product grid. Brand image at top, products listed below with pricing and ratings.

### Homepage Brand Strip
Use `GET /api/v1/general/brands?limit=8` to fetch a handful of brands for a horizontal scrolling brand strip.

### "Shop by Brand" Section
Use `GET /api/v1/general/brands-products?limit=4&limit_brand=6` to show a curated selection of products from top brands.

### State Handling

| State | Behavior |
|-------|----------|
| **Loading** | Skeleton cards/grid while fetching |
| **Empty (brands list)** | "No brands available" message |
| **Empty (brand detail)** | 404 page with "Brand not found" |
| **Empty (brand products)** | "No products from this brand" |
| **Error** | Error message with retry button |
| **Image missing** | Fallback placeholder for brand image |
| **Image error** | Broken image fallback |
