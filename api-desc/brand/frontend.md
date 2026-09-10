# Brand Module — Frontend Integration Guide

## Endpoints

---

### 1. GET /api/v1/general/brands — List Active Brands (Public)

**Purpose:** Display brand logos on the homepage or brand listing page.

**Authentication:** None (public)

**Query Parameters:**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| limit | int | 10 | Number of brands to fetch |
| brandsId | string | - | Comma-separated brand IDs to filter |
| order | string | desc | Sort order (asc, desc by id) |
| slug | string | - | Get single brand by slug |

**Response:**
```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": [
    {
      "id": 1,
      "name": "Apple",
      "slug": "apple",
      "image": {
        "desktop": "https://cdn.example.com/brands/apple-desktop.jpg",
        "mobile": "https://cdn.example.com/brands/apple-mobile.jpg"
      },
      "status": true
    }
  ]
}
```

---

### 2. GET /api/v1/general/brands/{slug} — Get Brand With Products (Public)

**Purpose:** Display brand detail page with associated products.

**Authentication:** None (public)

**Response:**
```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": {
    "id": 1,
    "name": "Apple",
    "slug": "apple",
    "image": {
      "desktop": "https://cdn.example.com/brands/apple-desktop.jpg",
      "mobile": "https://cdn.example.com/brands/apple-mobile.jpg"
    },
    "status": true,
    "products": [
      {
        "id": 5,
        "name": "iPhone 15",
        "slug": "iphone-15",
        "image": { "thumbnail": "..." },
        "price": 999.99,
        "price_after_discount": 949.99
      }
    ]
  }
}
```

**Error Response (404):**
```json
{ "status": 404, "message": "Not found", "success": false }
```

---

### 3. GET /api/v1/general/brands-products — Brand Products by Quantity (Public)

**Purpose:** Fetch a curated set of products from multiple brands (e.g., for homepage sections).

**Authentication:** None (public)

**Query Parameters:**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| limit | int | 10 | Products per brand |
| limit_brand | int | 10 | Number of brands |

**Response:**
```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": [
    {
      "id": 5,
      "name": "iPhone 15",
      "slug": "iphone-15",
      "image": { "thumbnail": "..." },
      "price": 999.99,
      "price_after_discount": 949.99,
      "rating": 4.5
    }
  ]
}
```

---

### 4. PUT /api/v1/brands/reorder — Reorder Brands (Admin)

**Purpose:** Drag-and-drop reorder of brands on the admin brand listing page.

**Authentication:** Required (Sanctum)

**Permission:** `update-brand`

**Request:**
```json
{
  "brands": [3, 1, 2]
}
```

**Response:**
```json
{
  "status": 200,
  "message": "Brands reordered successfully",
  "success": true
}
```

---

### 5. POST /api/v1/brands — Create Brand (Admin)

**Purpose:** Create a new brand with images and product associations.

**Authentication:** Required (Sanctum)

**Permission:** `create-brand`

**Request:** `multipart/form-data`

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| name[en] | string | Yes | English name |
| name[ar] | string | Yes | Arabic name |
| image-desktop | file | Yes | Desktop image (jpeg,png,jpg,gif,svg, max 2MB) |
| image-mobile | file | Yes | Mobile image (jpeg,png,jpg,gif,svg, max 2MB) |
| details[en] | string | No | English description |
| details[ar] | string | No | Arabic description |
| status | int | No | 1 or 0 |
| products[] | int[] | No | Product IDs to associate |

**Response (201):**
```json
{
  "status": 201,
  "message": "Brand created successfully",
  "success": true,
  "data": { "id": 31, "name": "Apple", ... }
}
```

---

### 6. PUT /api/v1/brands/{id} — Update Brand (Admin)

**Purpose:** Update brand details, images, and product associations.

**Authentication:** Required (Sanctum)

**Permission:** `update-brand`

**Request:** `multipart/form-data`

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| name[en] | string | No | English name |
| name[ar] | string | No | Arabic name |
| image-desktop | file | No | Replaces desktop image |
| image-mobile | file | No | Replaces mobile image |
| details[en] | string | No | English description |
| details[ar] | string | No | Arabic description |
| status | int | No | 1 or 0 |
| products[] | int[] | No | Replaces all product associations |

**Response (200):**
```json
{
  "status": 200,
  "message": "Brand updated successfully",
  "success": true,
  "data": { ... }
}
```

---

### 7. DELETE /api/v1/brands/{id} — Delete Brand (Admin)

**Purpose:** Soft-delete a brand.

**Authentication:** Required (Sanctum)

**Permission:** `delete-brand`

**Response (200):**
```json
{
  "status": 200,
  "message": "Brand deleted successfully",
  "success": true
}
```

## Frontend Usage

### Loading State
```js
const response = await fetch('/api/v1/general/brands');
if (!response.ok) {
  // Show error state
}
const brands = await response.json();
```

### Empty State
- **No brands:** Empty array `[]` — show a message like "No brands available"
- **No products for brand:** Products array is omitted if not loaded — check `data.products` existence

### Error State
- **404:** Brand not found (by slug) — redirect to brand listing
- **422:** Validation errors — field-level error messages
- **500:** Server error — show generic error message

## Key Considerations

1. **Translatable fields** — `name` and `details` are sent as nested objects:
   ```json
   { "en": "Apple", "ar": "أبل" }
   ```

2. **Image dimensions** — Desktop and mobile images use separate collections. The frontend should display the appropriate image based on viewport.

3. **Brand-Product association** — Products are synced, not appended. Sending an empty `products` array removes all associations.

4. **Admin image uploads** — Use `multipart/form-data` for create/update due to file uploads.

5. **Reordering** — The `brands` array should contain all brand IDs in the new order. The frontend should send the complete list, not just the moved item.
