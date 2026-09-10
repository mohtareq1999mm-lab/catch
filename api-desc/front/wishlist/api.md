# API Reference — Wishlist Module (Authenticated API)

Base URL: `/api/v1`
All endpoints except `in_wishlist` require `auth:sanctum`.

---

### GET /api/v1/wishlists

List the authenticated user's wishlist products. Scoped to `user_id` — never returns another user's wishlist.

**Query Parameters:**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| limit | int | 15 | Max products returned |
| page | int | 1 | Page number |

**Response 200:** Standard envelope with a flat product array under `data` (WishlistResource fields).

**Quick Test:**
```bash
curl -X GET "http://example.com/api/v1/wishlists?limit=10&page=1" \
  -H "Authorization: Bearer {token}" \
  -H "Accept: application/json"
```

---

### POST /api/v1/wishlists

Add a product to the current user's wishlist.

**Request Body:**
```json
{
  "product_id": 10,
  "product_variant_id": null
}
```

**Validation Rules:**

| Field | Rules |
|-------|-------|
| product_id | required, exists:products,id |
| product_variant_id | requiredIf(product is variable), integer, exists:product_variants,id |

**Response 200 (added):**
```json
{
  "status": 200,
  "message": "Added to wishlist successfully",
  "success": true,
  "data": {
    "user_id": 42,
    "product_id": 10,
    "product_variant_id": null,
    "id": 1
  }
}
```

**Response 400 (duplicate):**
```json
{
  "status": 400,
  "message": "This product is already added to the wishlist",
  "success": false
}
```

**Response 422 (validation):** `{ "product_id": ["The product id field is required."] }`

**Quick Test:**
```bash
curl -X POST "http://example.com/api/v1/wishlists" \
  -H "Authorization: Bearer {token}" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"product_id":10}'
```

---

### PATCH /api/v1/wishlists/toggle

Add the product if not present, or remove it if already present. Same validation as store.

**Response 200 (added):**
```json
{
  "status": 200,
  "message": "Added to wishlist successfully",
  "success": true
}
```

**Response 200 (removed):**
```json
{
  "status": 200,
  "message": "Removed from wishlist successfully",
  "success": true
}
```

**Response 422:** Same as store (variable product without `product_variant_id`).

---

### DELETE /api/v1/wishlists/{product_id}

Remove a product from the current user's wishlist.

**Path Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| product_id | integer | Product ID (not wishlist row ID) |

**Query Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| product_variant_id | integer, optional | Removes only the matching variant wishlist row |

**Response 200:**
```json
{
  "status": 200,
  "message": "Removed from wishlist successfully",
  "success": true,
  "data": true
}
```

**Response 404:** Product not found, or no matching wishlist row for this user.
**Response 403:** Unauthenticated user (defense-in-depth; auth:sanctum normally returns 401 first).

**Quick Test:**
```bash
curl -X DELETE "http://example.com/api/v1/wishlists/10?product_variant_id=5" \
  -H "Authorization: Bearer {token}" \
  -H "Accept: application/json"
```

---

### GET /api/v1/wishlists/in_wishlist/{product_id}

Check whether the product is in the current user's wishlist. Public endpoint — returns `false` for guests or non-members.

**Response 200:**
```json
{
  "data": true
}
```

---
