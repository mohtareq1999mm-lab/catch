# Banner Module — Frontend Integration Guide

## Endpoints

---

### 1. GET /api/v1/general/banners — List Active Banners (Public)

**Purpose:** Fetch promotional banners for the homepage hero section, carousel, or marketing strips.

**Authentication:** None (public)

**Query Parameters:**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| limit | int | 10 | Number of banners to return |
| start_date | string (date) | - | Filter by created_at >= (Y-m-d) |
| end_date | string (date) | - | Filter by created_at <= (Y-m-d) |
| bannersId | string | - | Comma-separated banner IDs |
| order | string | desc | Sort direction (asc, desc) |
| slug | string | - | Get single banner by slug (overrides list) |

**Response:**
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

---

### 2. GET /api/v1/general/banners/{slug} — Get Banner by Slug (Public)

**Purpose:** Fetch a single banner with optional associated products for a banner detail or promotional landing page.

**Authentication:** None (public)

**Query Parameters:**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| with_products | string | "true" | Set to literal `false` to exclude products |

**Response (with products):**
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
          "original": ["https://cdn.example.com/products/summer-dress-1.jpg"]
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

---

## Frontend Usage

### Homepage Hero Carousel
Use `GET /api/v1/general/banners` to fetch banners for the main hero slider. Map each banner to a slide:
- `image.desktop` for desktop view
- `image.mobile` for mobile view
- `title` as heading text
- `description` as subtitle
- Each slide links to the banner slug or associated products

### Promotional Section
Use `GET /api/v1/general/banners?slug=promo-name` (or the explicit slug route) to fetch a specific promotional banner for a marketing section.

### Banner Detail Page
Use `GET /api/v1/general/banners/{slug}?with_products=false` to show just banner info without loading products (faster).

### State Handling

| State | Behavior |
|-------|----------|
| **Loading** | Skeleton hero slider/banner cards |
| **Empty (list)** | Hide hero section, show default content |
| **Empty (detail)** | 404 page |
| **Error** | Hide section with console warning |
| **Image missing** | Fallback placeholder |
| **Image error** | Broken image fallback |
