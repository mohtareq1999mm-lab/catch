# API Reference — Cart Module (Authenticated API)

Base URL: `/api/v1`
All endpoints require `auth:sanctum` + `throttle:cart` middleware.

---

### GET /api/v1/cart

List the authenticated user's shopping carts (paginated).

**Query Parameters:**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| limit | int | 15 | Items per page |
| page | int | 1 | Page number |

**Response 200:** Full paginated response with `normal_items` and `fast_items` split.

**Quick Test:**
```bash
curl -X GET "http://example.com/api/v1/cart" \
  -H "Authorization: Bearer {token}" \
  -H "Accept: application/json"
```

---

### POST /api/v1/cart

Add an item to the cart. Creates a new cart if none exists for the user.

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

**Validation Rules:**

| Field | Rules |
|-------|-------|
| item | required, array, min:1 |
| item.product_id | required, integer, exists:products,id |
| item.quantity | required, integer, min:1 |
| item.product_variant_id | sometimes, nullable, integer, exists:product_variants,id |
| item.attributes | sometimes, array |
| item.shipping_method | required, string, in:SCHEDULED,FAST |

**Response 201:**
```json
{
  "status": 201,
  "message": "Cart created successfully",
  "success": true,
  "data": { /* CartResource */ }
}
```

**Response 422 (validation):** `{ "field": ["error message"] }`

**Quick Test:**
```bash
curl -X POST "http://example.com/api/v1/cart" \
  -H "Authorization: Bearer {token}" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"item":{"product_id":10,"quantity":1,"shipping_method":"SCHEDULED"}}'
```

---

### GET /api/v1/cart/{id}

Show a specific cart by ID. Returns 403 if cart belongs to another user.

**Path Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| id | integer | Cart ID (whereNumber validation) |

**Response 200:** CartResource object.
**Response 403:** If user_id mismatch.
**Response 404:** If cart not found.

---

### POST /api/v1/cart/bulk-items

Add multiple items in a single transaction (all-or-nothing).

**Request Body:**
```json
{
  "items": [
    {
      "product_id": 10,
      "quantity": 2,
      "product_variant_id": null,
      "shipping_method": "SCHEDULED"
    }
  ]
}
```

**Response 201:** Full CartResource of the user's cart after all items added.
**Response 400:** If any item fails (transaction rolled back).

---

### PUT /api/v1/cart/update-item

Update cart item quantity. Uses `set` mode (quantity replaces existing, not additive).

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

Note: `shipping_method` is optional in update. If omitted, the existing item's shipping method is preserved.

**Response 200:** CartResource after update.

---

### DELETE /api/v1/cart/delete-item/{itemId}

Remove a single item from the cart and release its inventory.

**Path Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| itemId | integer | CartItem ID |

**Response 200:** `{ "status": 200, "message": "...", "success": true }`
**Response 400:** If auth mismatch or item not found.

---

### DELETE /api/v1/cart/delete-items

Clear the entire cart. If a coupon is applied and `confirm` is not provided, returns a warning instead.

**Query Parameters:**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| confirm | boolean | false | Confirm clearing cart with coupon |

**Response 200 (cleared):** `{ "status": 200, "message": "...", "success": true }`
**Response 200 (warning):** `{ "status": 200, "message": "Coupon warning...", "success": true }`
**Response 404:** If no cart exists.

---

## CartResource Response Fields

| Field | Type | Description |
|-------|------|-------------|
| id | integer | Cart ID |
| user_id | integer | User ID |
| coupon | object\|null | Full coupon object (CouponResource) when coupon applied |
| coupon_code | string\|null | Raw coupon code string |
| status | string | active, checked_out, expired |
| reserved_at | datetime\|null | Last inventory reservation timestamp |
| expires_at | datetime\|null | Reservation expiry (3 days) |
| total_items | integer\|null | Number of unique items in cart |
| total_quantity | integer\|null | Sum of all item quantities |
| total_price | float | Sum of all items' total_price (before coupon discount) |
| subtotal | float | Same as total_price (alias for clarity) |
| coupon_discount | float | Calculated coupon discount amount (0 if no coupon) |
| total_after_coupon | float | subtotal - coupon_discount (min 0) |
| normal_items_count | integer | Count of SCHEDULED shipping items |
| fast_items_count | integer | Count of FAST shipping items |
| normal_items | array | CartItemResource array (SCHEDULED) |
| fast_items | array | CartItemResource array (FAST) |
| has_eligible_promotion | boolean | Whether cart qualifies for a promotion |

## Business Rules

- Each user has exactly one active cart at a time (created on first add)
- Inventory is reserved immediately on add using `lockForUpdate()` pessimistic lock
- Reservation TTL: 3 days (cart expires after 3 days of inactivity)
- Variable products require `product_variant_id` — simple products reject it
- FAST shipping requires `product.is_fast_shipping_available = true`
- Price is calculated at time of add via ProductPricingService (includes flash sales, discounts)
- Promotion discounts are cleared on any cart mutation via `revalidatePromotion()`
- Gift items (from promotions) have price/total_price = 0 and is_gift = true
- Coupon applied to cart is checked before clearing — requires `?confirm=true` to proceed
- Coupon discount is calculated via `CouponCalculator::calculate()` using the coupon model and subtotal
- `coupon_discount` supports PERCENTAGE (with optional max_discount_amount cap) and FIXED_RATE types
- `total_after_coupon` is floored at 0 (never negative)
