# Flash Sale Module — Frontend Integration Guide

## Endpoints

---

### 1. GET /api/v1/general/flash-sales — List Active Flash Sales

**Purpose:** Fetch active flash sales for homepage flash sale sections, countdown timers, and promotional banners.

**Authentication:** None (public)

**Query Parameters:**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| page | int | 1 | Page number |
| limit | int | 10 | Items per page |
| start_date | string (date) | - | Filter by created_at >= |
| end_date | string (date) | - | Filter by created_at <= |
| flashSalesId | string | - | Comma-separated IDs |
| order | string | desc | Sort direction |
| slug | string | - | Get single flash sale by slug |

**Response:**
```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": [
    {
      "id": 1,
      "name": "Summer Flash Sale",
      "discription": "Big discounts on summer products",
      "slug": "summer-flash-sale",
      "start_date": "2026-07-01",
      "end_date": "2026-07-31",
      "image": {
        "desktop": "https://cdn.example.com/flash-sales/summer-desktop.jpg",
        "mobile": "https://cdn.example.com/flash-sales/summer-mobile.jpg"
      }
    }
  ]
}
```

**Note:** Response key `discription` is a known typo (BUG-FLASH-003).

---

### 2. GET /api/v1/general/flash-sales/{slug} — Get Flash Sale by Slug

**Purpose:** Fetch a single flash sale with its associated products.

**Authentication:** None (public)

**Response 200:**
```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": {
    "id": 1,
    "name": "Summer Flash Sale",
    "discription": "Big discounts on summer products",
    "slug": "summer-flash-sale",
    "start_date": "2026-07-01",
    "end_date": "2026-07-31",
    "image": {
      "desktop": "https://cdn.example.com/flash-sales/summer-desktop.jpg",
      "mobile": "https://cdn.example.com/flash-sales/summer-mobile.jpg"
    },
    "products": [
      {
        "id": 10,
        "name": "Summer Dress",
        "slug": "summer-dress",
        "price": 79.99,
        "current_price": 49.99,
        "has_variants": false,
        "quantity": 150,
        "in_stock": true,
        "discount_active": true,
        "flash_sale_active": true,
        "is_fast_shipping_available": false,
        "ratings": 4.2,
        "image": {
          "thumbnail": "https://cdn.example.com/products/dress-thumb.jpg",
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

---

### 3. GET /api/v1/general/flash-sale-products — Products by Quantity

**Purpose:** Fetch a flat list of products from multiple flash sales.

**Parameters:** `?limit=5` (products per flash sale)

---

### 4. GET /api/v1/general/flash-sale-products-ending-this-week

**Purpose:** Products in flash sales ending within the next 7 days. `?limit=10`

---

### 5. GET /api/v1/general/flash-sale-products-ending-today

**Purpose:** Products in flash sales ending today. `?limit=10`

---

## Frontend Usage

### Flash Sale Countdown Banner
Use `GET /api/v1/general/flash-sales` to show active flash sales with countdown timers.

### Flash Sale Product Grid
Use `GET /api/v1/general/flash-sales/{slug}` to display a flash sale page with its products.

### Ending Soon Section
Use the "ending this week" and "ending today" endpoints for urgency sections.

### State Handling

| State | Behavior |
|-------|----------|
| **Loading** | Skeleton banners/product cards |
| **Empty (list)** | Hide flash sale section |
| **Empty (detail)** | 404 page |
| **Error** | Hide section with console warning |
