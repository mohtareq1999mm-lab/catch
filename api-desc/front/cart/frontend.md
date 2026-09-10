# Cart Module — Frontend Integration Guide

---

### 1. GET /api/v1/cart — List User Carts

**Authentication:** Required (`auth:sanctum`)

**Query Parameters:**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| limit | int | 15 | Items per page |
| page | int | 1 | Page number |

**Response 200:**
```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": {
    "data": [
      {
        "id": 1,
        "user_id": 42,
        "coupon": null,
        "coupon_code": null,
        "status": "active",
        "reserved_at": "2026-07-20T09:00:00Z",
        "expires_at": "2026-07-23T09:00:00Z",
        "total_items": 3,
        "total_quantity": 5,
        "total_price": 149.97,
        "subtotal": 149.97,
        "coupon_discount": 0,
        "total_after_coupon": 149.97,
        "normal_items_count": 2,
        "fast_items_count": 1,
        "has_eligible_promotion": false,
        "normal_items": [
          {
            "id": 1,
            "product_id": 10,
            "product_variant_id": null,
            "quantity": 2,
            "price": 49.99,
            "total_price": 99.98,
            "attributes": null,
            "shipping_method": "SCHEDULED",
            "promotion_id": null,
            "discount_amount": 0,
            "is_gift": false,
            "product": {
              "id": 10,
              "name": "T-Shirt",
              "slug": "t-shirt",
              "thumbnail": "https://cdn.example.com/products/t-shirt.jpg"
            }
          }
        ],
        "fast_items": [
          {
            "id": 3,
            "product_id": 20,
            "product_variant_id": 5,
            "quantity": 1,
            "price": 89.99,
            "total_price": 89.99,
            "attributes": [
              { "attribute": "Size", "value": "L" },
              { "attribute": "Color", "value": "Red" }
            ],
            "shipping_method": "FAST",
            "promotion_id": null,
            "discount_amount": 0,
            "is_gift": false,
            "product": {
              "id": 20,
              "name": "Sneakers",
              "slug": "sneakers",
              "thumbnail": "https://cdn.example.com/products/sneakers.jpg"
            }
          }
        ]
      }
    ],
    "page": 1,
    "current_page": 1,
    "from": 1,
    "to": 1,
    "last_page": 1,
    "path": "http://example.com/api/v1/cart",
    "per_page": 15,
    "total": 1,
    "next_page_url": null,
    "prev_page_url": null,
    "last_page_url": null,
    "first_page_url": "http://example.com/api/v1/cart?page=1"
  }
}
```

**Response 200 (with coupon applied):**
```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": {
    "data": [
      {
        "id": 1,
        "user_id": 42,
        "coupon": {
          "id": 5,
          "code": "SUMMER10",
          "name": "Summer Sale 10%",
          "slug": "summer-sale-10",
          "discount_type": "percentage",
          "discount": 10,
          "max_discount_amount": 50,
          "status": true
        },
        "coupon_code": "SUMMER10",
        "status": "active",
        "total_items": 3,
        "total_quantity": 5,
        "total_price": 149.97,
        "subtotal": 149.97,
        "coupon_discount": 14.997,
        "total_after_coupon": 134.97,
        "normal_items_count": 2,
        "fast_items_count": 1,
        "has_eligible_promotion": false,
        "normal_items": [ /* ... */ ],
        "fast_items": [ /* ... */ ]
      }
    ]
  }
}
```

---

### 2. POST /api/v1/cart — Add Item to Cart

**Authentication:** Required (`auth:sanctum`)

**Request Body:**
```json
{
  "item": {
    "product_id": 10,
    "quantity": 2,
    "product_variant_id": null,
    "attributes": [],
    "shipping_method": "SCHEDULED"
  }
}
```

**Response 201:**
```json
{
  "status": 201,
  "message": "Cart created successfully",
  "success": true,
  "data": { /* CartResource object */ }
}
```

**Response 400 (error):**
```json
{
  "status": 400,
  "message": "Stock exceeded for product 'T-Shirt'",
  "success": false
}
```

**Response 422 (validation):**
```json
{
  "item.product_id": ["The selected item.product_id is invalid."],
  "item.quantity": ["The item.quantity must be at least 1."]
}
```

---

### 3. GET /api/v1/cart/{id} — Show Cart

**Authentication:** Required (`auth:sanctum`)

**Response 200:** Same structure as index single item.
**Response 403:** If cart belongs to another user.
**Response 404:** If cart not found.

---

### 4. POST /api/v1/cart/bulk-items — Bulk Add Items

**Authentication:** Required (`auth:sanctum`)

**Request Body:**
```json
{
  "items": [
    {
      "product_id": 10,
      "quantity": 2,
      "product_variant_id": null,
      "shipping_method": "SCHEDULED"
    },
    {
      "product_id": 20,
      "quantity": 1,
      "product_variant_id": 5,
      "shipping_method": "FAST"
    }
  ]
}
```

**Response 201:** Full cart after all items added (all-or-nothing via DB transaction).

---

### 5. PUT /api/v1/cart/update-item — Update Item

**Authentication:** Required (`auth:sanctum`)

**Request Body:**
```json
{
  "item": {
    "product_id": 10,
    "quantity": 3,
    "product_variant_id": null,
    "attributes": [],
    "shipping_method": "SCHEDULED"
  }
}
```

Note: Mode is `set` — quantity replaces existing value (not additive).

**Response 200:**
```json
{
  "status": 200,
  "message": "Cart updated successfully",
  "success": true,
  "data": { /* CartResource object */ }
}
```

---

### 6. DELETE /api/v1/cart/delete-item/{itemId} — Remove Item

**Authentication:** Required (`auth:sanctum`)

**Response 200:**
```json
{
  "status": 200,
  "message": "Cart item deleted successfully",
  "success": true
}
```

**Response 400:**
```json
{
  "status": 400,
  "message": "Cart item delete failed",
  "success": false
}
```

---

### 7. DELETE /api/v1/cart/delete-items — Clear Cart

**Authentication:** Required (`auth:sanctum`)

**Query Parameters:**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| confirm | boolean | false | Confirm clearing cart with coupon applied |

**Response 200 (cleared):**
```json
{
  "status": 200,
  "message": "Cart deleted successfully",
  "success": true
}
```

**Response 200 (coupon warning):**
```json
{
  "status": 200,
  "message": "You have a coupon applied to your cart. Are you sure you want to clear the cart?",
  "success": true
}
```
(No inventory released until confirmed.)

**Response 404:**
```json
{
  "status": 404,
  "message": "Cart not found",
  "success": false
}
```

---

## Frontend Usage

### State Handling

| State | Behavior |
|-------|----------|
| **List loading** | Skeleton cart items with placeholders |
| **List empty** | "Your cart is empty" with "Shop now" CTA |
| **List error** | Toast with retry |
| **Add to cart loading** | Button shows spinner, quantity input disabled |
| **Add to cart success** | Toast "Added to cart", cart badge updates |
| **Add to cart error** | Toast error with reason |
| **Update quantity** | Inline spinner on quantity selector |
| **Remove item** | Loading spinner on item, then fade-out |
| **Clear cart loading** | Confirmation modal with spinner |
| **Clear cart coupon warning** | "Your cart has a coupon. Are you sure?" confirm dialog |
| **Auth token expired** | 401 → redirect to login |

### Shipping Method Split
The `normal_items` and `fast_items` arrays separate items by shipping method. Render them in separate sections with different delivery expectations.

### Coupon Integration
When a coupon is applied, `coupon` object contains full coupon info, `coupon_code` has the raw code, and `coupon_discount` shows the calculated discount amount. Display applied coupon with:
- Coupon code and name
- Discount amount (`coupon_discount`) — shown as savings line item
- Total after coupon (`total_after_coupon`) — shown as the effective total
- Remove option (clearing cart or removing coupon via coupon endpoint)

### Coupon Discount Display
| Field | When Coupon Applied | When No Coupon |
|-------|-------------------|----------------|
| `subtotal` | Sum of item prices before discount | Same as `total_price` |
| `coupon_discount` | Calculated discount value (> 0) | 0 |
| `total_after_coupon` | `subtotal - coupon_discount` | Same as `total_price` |
| `total_price` | Always the raw sum of items (before coupon) | Always the raw sum of items |
