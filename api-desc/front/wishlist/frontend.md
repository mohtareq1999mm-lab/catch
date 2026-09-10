# Wishlist Module — Frontend Integration Guide

---

### 1. GET /api/v1/wishlists — List Current User's Wishlist

**Authentication:** Required (`auth:sanctum`)

**Query Parameters:**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| limit | int | 15 | Products per page |
| page | int | 1 | Page number |

**Response 200:**
```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": [
    {
      "id": 10,
      "slug": "t-shirt",
      "name": "T-Shirt",
      "price": 49.99,
      "sale_price": 39.99,
      "min_price": 49.99,
      "max_price": 59.99,
      "product_type": "simple",
      "thumbnail": "https://cdn.example.com/products/t-shirt.jpg",
      "in_wishlist": true,
      "variations": [
        {
          "id": 3,
          "title": "Red / L",
          "price": 54.99,
          "sale_price": 44.99,
          "quantity": 12,
          "is_disable": false,
          "attributes": [
            { "id": 1, "slug": "color", "name": "Color", "value": "Red" },
            { "id": 2, "slug": "size", "name": "Size", "value": "L" }
          ]
        }
      ]
    }
  ]
}
```

> Note: `data` is a **flat array** (no pagination meta). Paginate via `limit`/`page` but do not rely on a meta block for this endpoint.

---

### 2. POST /api/v1/wishlists — Add Product to Wishlist

**Authentication:** Required (`auth:sanctum`)

**Request Body:**
```json
{
  "product_id": 10,
  "product_variant_id": null
}
```
`product_variant_id` is **required when the product has variations** (e.g. `3`), and must reference an existing variant.

**Response 200 (added):**
```json
{
  "status": 200,
  "message": "Added to wishlist successfully",
  "success": true,
  "data": true
}
```

**Response 400 (duplicate):**
```json
{
  "status": 400,
  "message": "Already added to wishlist for this product",
  "success": false
}
```

**Response 422 (validation):**
```json
{
  "message": "The given data was invalid.",
  "status": 422,
  "errors": {
    "product_id": ["The selected product id is invalid."]
  }
}
```

---

### 3. POST /api/v1/wishlists/toggle — Toggle Product in Wishlist

**Authentication:** Required (`auth:sanctum`)

**Request Body:** Same as add (`product_id`, optional `product_variant_id`).

**Response 200 (added):**
```json
{
  "status": 200,
  "message": "Added to wishlist successfully",
  "success": true,
  "data": true
}
```

**Response 200 (removed):**
```json
{
  "status": 200,
  "message": "Removed from wishlist successfully",
  "success": true,
  "data": false
}
```

> `data: true` = item now in wishlist; `data: false` = item removed. No duplicate-400 on toggle.

---

### 4. DELETE /api/v1/wishlists/{product_id} — Remove Product from Wishlist

**Authentication:** Required (`auth:sanctum`)

**Query Parameters:**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| product_variant_id | int | null | Variant to remove (required for variant items) |

**Response 200 (removed):**
```json
{
  "status": 200,
  "message": "Removed from wishlist successfully",
  "success": true,
  "data": false
}
```

**Response 404 (not found):**
```json
{
  "status": 404,
  "message": "Product not found",
  "success": false
}
```

---

### 5. GET /api/v1/wishlists/in_wishlist/{product_id} — Guest-Safe In-Wishlist Check

**Authentication:** None (public)

**Response 200 (authenticated, in wishlist):**
```json
{ "data": true }
```

**Response 200 (guest or not in wishlist):**
```json
{ "data": false }
```

> Product-level check — does not consider `product_variant_id`.

---

### 6. GET /api/v1/my-wishlists — Paginated Wishlist Products

**Authentication:** Required (`auth:sanctum`)

**Query Parameters:**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| limit | int | 15 | Products per page |
| page | int | 1 | Page number |

**Response 200:**
```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": [
    {
      "id": 10,
      "slug": "t-shirt",
      "name": "T-Shirt",
      "price": 49.99,
      "sale_price": 39.99,
      "product_type": "simple",
      "thumbnail": "https://cdn.example.com/products/t-shirt.jpg",
      "is_disable": false,
      "in_wishlist": true,
      "max_price": 59.99,
      "min_price": 49.99,
      "product_review_rating": 4.5,
      "variations": [ /* ... */ ]
    }
  ],
  "meta": {
    "current_page": 1,
    "from": 1,
    "last_page": 2,
    "per_page": 10,
    "to": 10,
    "total": 14
  },
  "links": {
    "first": "http://example.com/api/v1/my-wishlists?page=1",
    "last": "http://example.com/api/v1/my-wishlists?page=2",
    "next": "http://example.com/api/v1/my-wishlists?page=2",
    "prev": null
  }
}
```

> Unlike `GET /wishlists`, this endpoint returns the **standard paginated** shape (`data` / `meta` / `links`) and uses the same ProductResource serialization as other product endpoints.

---

## Frontend Usage

### State Handling

| State | Behavior |
|-------|----------|
| **Wishlist list loading** | Skeleton product cards |
| **Wishlist list empty** | "No saved items yet" with "Browse products" CTA |
| **Wishlist list error** | Toast with retry |
| **Toggle/Add loading** | Heart icon spinner, button disabled |
| **Toggle success** | Heart fills/empties + toast "Added"/"Removed" |
| **Add duplicate error (400)** | Keep heart filled, toast "Already in wishlist" |
| **Variant selection** | When product has variations, require choosing a variant before add/toggle |
| **Auth token expired** | 401 → redirect to login |

### Wishlist Icon Behaviour
- Product cards/listing: use `in_wishlist` from the product payload to render initial heart state, then call `toggle` on tap.
- Product detail page: use `GET /wishlists/in_wishlist/{product_id}` for guest-safe initial state, then `POST /wishlists` (or `toggle`) when authenticated.

### Guest Handling
- Guests can render the heart (state from `in_wishlist` = `false`), but tapping it should trigger login redirect — `toggle`/`store` return 401 for unauthenticated calls.

### Variant Semantics
- A product with variations is treated per-variant in the wishlist: adding variant A and variant B creates two entries. Removal must send the matching `product_variant_id` or the variant entry will not be removed.
- `in_wishlist` is product-level only; if finer granularity is needed, derive it from the wishlist list response.
