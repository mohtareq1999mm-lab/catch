# Flash Sale Module — Frontend Integration Guide

## Endpoints

---

### 1. GET /api/v1/general/flash-sales — List Active Flash Sales (Public)

**Purpose:** Display flash sale banners/countdowns on the homepage or sale listing page.

**Authentication:** None (public)

**Query Parameters:**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| limit | int | 10 | Number of flash sales to fetch |
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
      "name": "Summer Sale",
      "slug": "summer-sale",
      "start_date": "2026-07-01",
      "end_date": "2026-07-31",
      "image": {
        "desktop": "https://cdn.example.com/flashSales/desktop.jpg",
        "mobile": "https://cdn.example.com/flashSales/mobile.jpg"
      }
    }
  ]
}
```

---

### 2. GET /api/v1/general/flash-sales/{slug} — Get Flash Sale With Products (Public)

**Purpose:** Display flash sale detail page with associated products showing discounted prices.

**Authentication:** None (public)

**Response:**
```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": {
    "id": 1,
    "name": "Summer Sale",
    "slug": "summer-sale",
    "start_date": "2026-07-01",
    "end_date": "2026-07-31",
    "image": { "desktop": "...", "mobile": "..." },
    "products": [
      {
        "id": 5,
        "name": "Product A",
        "slug": "product-a",
        "image": { "thumbnail": "..." },
        "price": 100,
        "price_after_discount": 75
      }
    ]
  }
}
```

---

### 3. GET /api/v1/general/flash-sale-products — Flash Sale Products by Quantity (Public)

**Purpose:** Fetch a curated set of products from flash sales (e.g., for homepage sections).

**Authentication:** None (public)

---

### 4. GET /api/v1/general/flash-sale-products-ending-this-week (Public)

**Purpose:** Show urgency — products in flash sales ending within 7 days.

---

### 5. GET /api/v1/general/flash-sale-products-ending-today (Public)

**Purpose:** Show urgency — products in flash sales ending today.

---

### 6. Admin CRUD Endpoints

| Method | Endpoint | Permission | Description |
|--------|----------|------------|-------------|
| GET | `/api/v1/flash-sale` | `view-flash-sale` | List (paginated, filterable) |
| POST | `/api/v1/flash-sale` | `create-flash-sale` | Create (multipart) |
| GET | `/api/v1/flash-sale/{id}` | `view-flash-sale` | Show by ID or slug |
| PUT | `/api/v1/flash-sale/{id}` | `update-flash-sale` | Update (multipart) |
| DELETE | `/api/v1/flash-sale/{id}` | `delete-flash-sale` | Soft-delete |
| PUT | `/api/v1/flash-sale/reorder` | `update-flash-sale` | Reorder |
| GET | `/api/v1/product-flash-sale-info?id=` | `view-flash-sale` | Flash sale info by product ID |

---

## Frontend Usage

### Loading State
```js
const response = await fetch('/api/v1/general/flash-sales?limit=10');
if (!response.ok) {
  // Show error state
}
const flashSales = await response.json();
```

### Empty State
- **No flash sales:** Empty array `[]` — hide the flash sale section
- **No products in flash sale:** Products array omitted — show "No products in this sale"

### Error State
- **404:** Flash sale not found — redirect to flash sale listing
- **422:** Validation errors — field-level error messages
- **500:** Server error — show generic error message

## Key Considerations

1. **Translatable fields** — `title` and `description` are sent as nested objects:
   ```json
   { "en": "Summer Sale", "ar": "تخفيضات الصيف" }
   ```

2. **Date range** — `start_date` and `end_date` determine if a flash sale is active. Display countdown timers to `end_date` for urgency.

3. **Image dimensions** — Desktop and mobile images use separate collections. Display the appropriate image based on viewport.

4. **Product pricing** — Products under flash sale have `price_after_flash_sale` calculated automatically. Display this as the sale price alongside the original price.

5. **Flash sale types:**
   - `percentage`: Show "25% OFF" badge, up to max discount amount
   - `fixed_rate`: Show "$10 OFF" badge
   - `final_price`: Show "Now $50" badge

6. **`is_valid` field** — Admin responses include `is_valid` boolean. Frontend can use this to visually distinguish active vs expired sales.

7. **Admin image uploads** — Use `multipart/form-data` for create/update due to image uploads (jpeg, png, jpg, webp).

8. **Reordering** — The `flash_sales` array should contain all flash sale IDs in the new order. Send the complete list.
