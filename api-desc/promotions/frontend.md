# Promotion Module — Frontend Integration Guide

## Endpoints

---

### 1. GET /api/v1/general/promotions — List Valid Promotions (Public)

**Purpose:** Display promotion banners on the homepage or promotion listing page.

**Authentication:** None (public)

**Query Parameters:**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| limit | int | 10 | Number of promotions to fetch |
| promotionsId | string | - | Comma-separated promotion IDs to filter |
| order | string | desc | Sort order (asc, desc by id) |
| slug | string | - | Get single promotion by slug |

**Response:**
```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": [
    {
      "id": 1,
      "name": "Summer Special 20% Off",
      "slug": "summer-special-20-off",
      "status": true,
      "image": {
        "desktop": "https://cdn.example.com/promotions/desktop.jpg",
        "mobile": "https://cdn.example.com/promotions/mobile.jpg"
      }
    }
  ]
}
```

---

### 2. GET /api/v1/general/promotions/{slug} — Get Promotion With Products (Public)

**Purpose:** Display promotion detail page with associated products.

**Authentication:** None (public)

**Response:**
```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": {
    "id": 1,
    "name": "Summer Special 20% Off",
    "slug": "summer-special-20-off",
    "status": true,
    "image": {
      "desktop": "https://cdn.example.com/promotions/desktop.jpg",
      "mobile": "https://cdn.example.com/promotions/mobile.jpg"
    },
    "products": [
      {
        "id": 5,
        "name": "Product Name",
        "slug": "product-name",
        "image": { "thumbnail": "..." },
        "price": 100,
        "price_after_discount": 80
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

### 3. GET /api/v1/checkout/promotions — Eligible Promotions (Authenticated)

**Purpose:** Fetch promotions eligible for the current cart (for checkout page).

**Authentication:** Required (Sanctum)

**Response:**
```json
{
  "eligible_promotions": [
    {
      "id": 1,
      "type": "percentage",
      "title": "Summer Special 20% Off",
      "code": "ALLXK8FJ2M",
      "discount": 20.00,
      "gift_items": []
    }
  ]
}
```

---

### 4. POST /api/v1/promotions — Create Promotion (Admin)

**Purpose:** Create a new promotion with images, products, and gift associations.

**Authentication:** Required (Sanctum)
**Permission:** `create-promotion`

**Request:** `multipart/form-data`

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| name[en] | string | Yes | English name |
| name[ar] | string | Yes | Arabic name |
| image-desktop | file | Yes | Desktop image (jpeg,png,jpg,webp) |
| image-mobile | file | Yes | Mobile image (jpeg,png,jpg,webp) |
| type | string | Yes | `price` or `quantity` |
| type_amount | string | Yes | `fixed_rate`, `percentage`, or `gift` |
| apply_to | string | Yes | `all_products` or `specific_products` |
| product_ids[] | int[] | Conditional | Required when apply_to=specific_products |
| gift_products | array | Conditional | Required when type_amount=gift |
| discount | float | Conditional | Required when type=price and not gift |
| max_discount_amount | float | Conditional | Required when type_amount=percentage |
| required_quantity_type | int | Conditional | Required when type=quantity |
| minimum_order_amount | float | Conditional | Required when type=price |
| limiter | int | No | Max usage limit |
| start_at | date | No | Start date |
| end_at | date | No | End date (after_or_equal:start_at) |
| status | int | No | 1 or 0 |

**Response (201):**
```json
{
  "status": 201,
  "message": "Promotion created successfully",
  "success": true,
  "data": { "id": 21, "name": "Summer Sale", ... }
}
```

---

### 5. PUT /api/v1/promotions/{id} — Update Promotion (Admin)

**Purpose:** Update promotion details, images, and associations.

**Authentication:** Required (Sanctum)
**Permission:** `update-promotion`

**Request:** `multipart/form-data`

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| name[en] | string | No | English name |
| name[ar] | string | No | Arabic name |
| image-desktop | file | No | Replaces desktop image |
| image-mobile | file | No | Replaces mobile image |
| type | string | No | `price` or `quantity` |
| type_amount | string | No | `fixed_rate`, `percentage`, or `gift` |
| apply_to | string | No | `all_products` or `specific_products` |
| product_ids[] | int[] | No | Replaces all product associations |
| gift_products | array | No | Replaces all gift associations |
| discount | float | No | Discount value |
| max_discount_amount | float | No | Max cap |
| required_quantity_type | int | No | Min quantity |
| minimum_order_amount | float | No | Min order amount |
| limiter | int | No | Max usage |
| start_at | date | No | Start date |
| end_at | date | No | End date |
| status | int | No | 1 or 0 |

**Response (200):**
```json
{
  "status": 200,
  "message": "Promotion updated successfully",
  "success": true,
  "data": { ... }
}
```

---

### 6. DELETE /api/v1/promotions/{id} — Delete Promotion (Admin)

**Purpose:** Delete a promotion.

**Authentication:** Required (Sanctum)
**Permission:** `delete-promotion`

**Response (200):**
```json
{
  "status": 200,
  "message": "Promotion deleted successfully",
  "success": true
}
```

## Frontend Usage

### Loading State
```js
const response = await fetch('/api/v1/general/promotions');
if (!response.ok) {
  // Show error state
}
const promotions = await response.json();
```

### Empty State
- **No promotions:** Empty array `[]` — show a message like "No promotions available"
- **No products for promotion:** Products array is omitted if not loaded — check `data.products` existence

### Error State
- **404:** Promotion not found (by slug) — redirect to promotion listing
- **422:** Validation errors — field-level error messages
- **500:** Server error — show generic error message

## Key Considerations

1. **Translatable fields** — `name` is sent/received as nested object:
   ```json
   { "en": "Summer Sale", "ar": "تخفيضات الصيف" }
   ```

2. **Image dimensions** — Desktop and mobile images use separate collections. The frontend should display the appropriate image based on viewport.

3. **Promotion-Product association** — Products are synced, not appended. Sending `product_ids: []` removes all associations.

4. **Admin image uploads** — Use `multipart/form-data` for create/update due to file uploads.

5. **Gift promotion flow** — Gift promotions require `type=quantity` and `type_amount=gift`. The `gift_products` array specifies which products are given as gifts, with optional variant and quantity.

6. **Eligibility** — Only `valid()` promotions are shown in public API (active, within dates, usage < limiter). The checkout endpoint further filters by cart content eligibility.

7. **Coupon vs Promotion** — Promotions are applied before coupons. Only one promotion per order (no stacking).
