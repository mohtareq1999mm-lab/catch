# Review Module — Frontend Integration Guide

## Endpoints

---

### 1. GET /api/v1/reviews — List Reviews (Authenticated)

**Purpose:** Display reviews for a specific product (product detail page).

**Authentication:** Required (Sanctum)

**Query Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| product_id | int | Yes | Product ID to fetch reviews for |
| limit | int | No (default 15) | Items per page |
| page | int | No (default 1) | Page number |

**Response:**
```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": [
    {
      "id": 1,
      "rating": 5,
      "comment": "Great product!",
      "images": [],
      "is_approved": true
    }
  ]
}
```

---

### 2. POST /api/v1/reviews — Create Review (Authenticated)

**Purpose:** Submit a review for a purchased product.

**Authentication:** Required (Sanctum)

**Rate Limited:** 5 requests per minute

**Request:**
```json
{
  "product_id": 10,
  "comment": "Great product, highly recommended!",
  "rating": 5
}
```

**Response (200):**
```json
{
  "status": 200,
  "message": "Review created successfully",
  "success": true,
  "data": { ... }
}
```

**Error (400)** — Already reviewed:
```json
{
  "status": 400,
  "message": "Already given review for this product",
  "success": false
}
```

---

### 3. GET /api/v1/reviews/{id} — Show Review (Authenticated)

**Purpose:** Get a single review details.

**Authentication:** Required (Sanctum)

**Response (200):** Review object

---

### 4. PUT /api/v1/reviews/{id} — Update Review (Authenticated)

**Purpose:** Edit an existing review's comment and/or rating.

**Authentication:** Required (Sanctum)

**Rate Limited:** 5 requests per minute

**Request:**
```json
{
  "comment": "Updated review comment",
  "rating": 4
}
```

**Response (200):** Updated review object

---

### 5. DELETE /api/v1/reviews/{id} — Delete Review (Admin)

**Purpose:** Soft-delete a review.

**Authentication:** Required (Sanctum)

**Permission:** `delete-reviews`

**Response (200):**
```json
{
  "status": 200,
  "message": "Review deleted successfully",
  "success": true
}
```

---

### 6. PATCH /api/v1/reviews/{id}/toggle-approve — Toggle Approval (Admin)

**Purpose:** Approve or unapprove a review (moderation).

**Authentication:** Required (Sanctum)

**Permission:** `approve-reviews`

**Response (200):** Updated review with toggled `is_approved`

---

## Frontend Usage

### Loading State
```js
const response = await fetch(`/api/v1/reviews?product_id=${productId}&limit=10`);
if (!response.ok) {
  // Handle error (e.g., 401 redirect to login)
}
const reviews = await response.json();
```

### Empty State
- **No reviews:** Empty array `[]` — show "No reviews yet. Be the first to review!" message
- **No approved reviews:** Show "Reviews are pending approval" if all are unapproved

### Error State
- **400:** Already reviewed — disable submit button, show "You have already reviewed this product"
- **401:** Unauthenticated — redirect to login or show "Login to review" CTA
- **403:** Forbidden (delete/approve without permission) — hide action buttons
- **404:** Review not found — show "Review not found"
- **422:** Validation errors — field-level error messages
- **429:** Rate limited — show "Too many requests. Please try again later."

## Key Considerations

1. **`product_id` is required** for listing — the GET endpoint will fail with 422 if `product_id` is not provided
2. **`is_approved` is conditional** — only visible to users with `approve-reviews` permission. The frontend should handle its absence gracefully
3. **Rating scale** — 1 to 5, integer only. The frontend should enforce this (e.g., star selector that sends integer values)
4. **Rate limiting** — 5 requests per minute for create and update. Disable submit buttons briefly after each submission
5. **No public endpoint** — all review endpoints require authentication. Users must be logged in to view or submit reviews
6. **Images are not currently validated** — the `images` field exists in the API but validation is commented out. Do not rely on image upload functionality
7. **Product_id is not updatable** — once a review is created, the associated product cannot be changed
