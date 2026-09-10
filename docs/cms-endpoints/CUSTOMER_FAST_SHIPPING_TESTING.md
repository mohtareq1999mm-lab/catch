# Fast Shipping Feature - Customer Testing Guide

## Meem Commerce (Marvel)

---

## Prerequisites

1. Run all migrations:
```bash
php artisan migrate
```

2. Run `composer dump-autoload` to register new classes.

3. Ensure you have:
   - A test user with auth token (for auth-required endpoints)
   - An admin user (SUPER_ADMIN role) to configure settings
   - At least one active governorate
   - At least one active product in stock
   - The settings table has at least one row
   - Fast shipping globally enabled (via admin `PUT /api/v1/fast-shipping/settings`)
   - At least one governorate with `is_fast_shipping_enabled = true`

---

## Test Suite

---

### Test 8: Customer - List Fast Shipping Products

**Endpoint:** `GET /api/v1/general/fast-shipping/products`

**Auth:** None required (public)

**Query Parameters:**

| Parameter | Type | Required | Default | Max | Description |
|-----------|------|----------|---------|-----|-------------|
| `search` | string | No | — | — | Filter by product name or description |
| `limit` | integer | No | 15 | 100 | Items per page |
| `page` | integer | No | 1 | — | Page number |

**Expected Response (200):**
```json
{
    "status": 200,
    "message": "Data fetched successfully",
    "success": true,
    "data": {
        "current_page": 1,
        "data": [
            {
                "id": 1,
                "name": "Fast Eligible Product",
                "slug": "fast-eligible-product",
                "price": 150.00,
                "current_price": 150.00,
                "stock_quantity": 100,
                "available_stock": 100,
                "quantity": 100,
                "in_stock": true,
                "status": true,
                "is_fast_shipping_available": true,
                "categories": [
                    {
                        "id": 1,
                        "name": "Category Name",
                        "slug": "category-name"
                    }
                ],
                "variations": [],
                "reviews_avg_rating": 4.5,
                "reviews_count": 10,
                "images": [],
                "created_at": "2026-06-22T10:00:00+00:00"
            }
        ],
        "total": 1,
        "last_page": 1
    }
}
```

**Business Rules:**
- Only products with `is_fast_shipping_available = true` are returned
- Only active products (status = true, in_stock = true) are returned
- Products are ordered by newest first (descending ID)
- Search matches against `name` and `description` columns

**Verify:**
- Products with `is_fast_shipping_available = false` do NOT appear
- Search by product name returns matching products
- Search by non-existent term returns empty `data` array
- Pagination works with `?limit=N&page=N`

**Negative Test:** Invalid `limit` (e.g., `?limit=-1` or `?limit=999999`):
- Expected: limits clamped to valid range (min 1, max 100)

---

### Test 9: Customer - Fast Checkout (Success Path)

**Prerequisites:**
- Fast shipping globally enabled (via admin settings)
- Within working hours (default 08:00 - 22:00)
- Governorate has `is_fast_shipping_enabled = true`
- **All** cart items have `is_fast_shipping_available = true`
- Cart is not empty
- Products are in stock
- User has a valid active cart (added items to cart)

**Endpoint:** `POST /api/v1/general/checkout/fast`

**Auth:** Customer (Bearer token) — middleware: `auth:sanctum`

**Request Body:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `name` | string | Yes | Customer full name (max 255) |
| `user_phone` | string | Yes | Customer phone number (max 255) |
| `user_email` | email | Yes | Customer email (max 255) |
| `address` | object | Yes | Shipping address (any structure) |
| `notes` | string | No | Delivery notes |
| `governorate_id` | integer | Yes | Must exist in `governorates` table |
| `selected_promotion_id` | integer | No | Must exist in `promotions` table |
| `selected_gift_product_id` | integer | No | Must exist in `products` table |

**Example Request Body:**
```json
{
    "name": "Ahmed Ali",
    "user_phone": "01012345678",
    "user_email": "ahmed@example.com",
    "address": {
        "street": "10 Main St",
        "city": "Cairo",
        "details": "Apt 5"
    },
    "notes": "Ring the bell",
    "governorate_id": 1
}
```

**Expected Response (200):**
```json
{
    "status": 200,
    "message": "Checkout successful",
    "success": true,
    "data": {
        "url": "https://myfatoorah.invoice.url/..."
    }
}
```

**Verify in Database:**
- Order created with `shipping_method = 'FAST'`
- Order has `expected_delivery_at = now() + duration_minutes` (e.g., +120 min)
- Order has `fast_shipping_fee = configured fee` (e.g., 30.00)
- Order has `total_price = subtotal + promotion_discount + coupon_discount + fast_shipping_fee`
- Order has status `pending`
- Cart is marked as processed (no longer active)
- Transaction record created with MyFatoorah `invoice_id`

---

### Test 10: Customer - Fast Checkout (Feature Disabled)

**Prerequisites:**
- Set `fast_shipping_enabled = false` in settings (via admin `PUT /api/v1/fast-shipping/settings`)

**Endpoint:** `POST /api/v1/general/checkout/fast`

**Expected Response (422):**
```json
{
    "status": 422,
    "message": "Fast shipping is not available at this time.",
    "success": false
}
```

**Verify:**
- Checkout is rejected
- No order is created
- Cart remains intact

**After test**, restore `fast_shipping_enabled = true`.

---

### Test 11: Customer - Fast Checkout (Outside Working Hours)

**Prerequisites:**
- Shift working hours outside current time, e.g.:
  - If now is 14:00, set `start_hour = "20:00"`, `end_hour = "22:00"`
  - OR set `end_hour = "06:00"` (past)

**Endpoint:** `POST /api/v1/general/checkout/fast`

**Expected Response (422):**
```json
{
    "status": 422,
    "message": "Fast shipping is only available between 20:00 and 22:00.",
    "success": false
}
```

**Verify:**
- Checkout is rejected
- No order is created

**After test**, restore working hours to current time range (default: `"08:00"` – `"22:00"`).

---

### Test 12: Customer - Fast Checkout (Governorate Not Enabled)

**Prerequisites:**
- Set target governorate's `is_fast_shipping_enabled = false` (via admin `PUT /api/v1/governorates/{id}/fast-shipping`)

**Endpoint:** `POST /api/v1/general/checkout/fast`

**Expected Response (422):**
```json
{
    "status": 422,
    "message": "Fast shipping is not available in your governorate.",
    "success": false
}
```

**Verify:**
- Checkout is rejected
- No order is created

**After test**, restore governorate's `is_fast_shipping_enabled = true`.

---

### Test 13: Customer - Fast Checkout (Mixed Cart - Contains Non-Fast Product)

**Prerequisites:**
- Cart contains at least one product with `is_fast_shipping_available = false`

**Endpoint:** `POST /api/v1/general/checkout/fast`

**Expected Response (422):**
```json
{
    "status": 422,
    "message": "One or more items in your cart are not eligible for fast shipping.",
    "success": false
}
```

**Verify:**
- Checkout is rejected
- No order is created
- Cart items are **not** cleared after rejection

---

### Test 14: Customer - Fast Checkout (Empty Cart)

**Prerequisites:**
- User has no active cart, or cart has zero items

**Endpoint:** `POST /api/v1/general/checkout/fast`

**Expected Response (400):**
```json
{
    "status": 400,
    "message": "Cart not found",
    "success": false
}
```

**Verify:**
- Checkout is rejected

---

### Test 15: Customer - Fast Checkout (Product Out of Stock)

**Prerequisites:**
- Set a fast-eligible product's quantity to 0 (e.g., via admin product update)

**Endpoint:** `POST /api/v1/general/checkout/fast`

**Expected Response (400):**
```json
{
    "status": 400,
    "message": "Insufficient stock for ...",
    "success": false
}
```

**Verify:**
- Cart inventory reservation fails
- Checkout is rejected
- No order is created

**After test**, restore product stock to original quantity.

---

### Test 16: Customer - List Fast Orders

**Endpoint:** `GET /api/v1/general/fast-shipping/orders`

**Auth:** Customer (Bearer token) — middleware: `auth:sanctum`

**Query Parameters:**

| Parameter | Type | Required | Default | Max | Description |
|-----------|------|----------|---------|-----|-------------|
| `limit` | integer | No | 15 | 100 | Items per page |
| `page` | integer | No | 1 | — | Page number |

**Expected Response:**
```json
{
    "status": 200,
    "message": "Data fetched successfully",
    "success": true,
    "data": {
        "current_page": 1,
        "data": [
            {
                "id": 1,
                "name": "Ahmed Ali",
                "user_phone": "01012345678",
                "user_email": "ahmed@example.com",
                "address": {
                    "street": "10 Main St",
                    "city": "Cairo",
                    "details": "Apt 5"
                },
                "notes": "Ring the bell",
                "shipping_method": "FAST",
                "expected_delivery_at": "2026-06-22T12:00:00+00:00",
                "fast_shipping_fee": 30.00,
                "price": 150.00,
                "total_price": 180.00,
                "status": "pending",
                "order_items": [
                    {
                        "id": 1,
                        "product_id": 1,
                        "product_name": "Fast Eligible Product",
                        "product_quantity": 2,
                        "product_price": 75.00,
                        "product_total_price": 150.00,
                        "product": {
                            "id": 1,
                            "media": []
                        },
                        "product_variant": null
                    }
                ]
            }
        ],
        "total": 1,
        "last_page": 1
    }
}
```

**Verify:**
- Only orders with `shipping_method = 'FAST'` appear
- Only the authenticated user's orders appear
- Each order includes `expected_delivery_at` and `fast_shipping_fee`
- Order items include product media and variant attributes
- Pagination works with `?limit=N&page=N`
- Orders where `shipping_method = 'SCHEDULED'` do NOT appear here

---

### Test 17: Customer - Get Fast Shipping Status (Public)

**Endpoint:** `GET /api/v1/general/fast-shipping/status`

**Auth:** None required (public)

**Expected Response (when within working hours, enabled):**
```json
{
    "status": 200,
    "message": "Data fetched successfully",
    "success": true,
    "data": {
        "enabled": true,
        "available": true,
        "duration_minutes": 120,
        "fee": 30,
        "opens_at": "08:00",
        "closes_at": "22:00",
        "available_again_at": null
    }
}
```

**Expected Response (when outside working hours):**
```json
{
    "status": 200,
    "message": "Data fetched successfully",
    "success": true,
    "data": {
        "enabled": true,
        "available": false,
        "duration_minutes": 120,
        "fee": 30,
        "opens_at": "08:00",
        "closes_at": "22:00",
        "available_again_at": "08:00"
    }
}
```

**Expected Response (when globally disabled):**
```json
{
    "status": 200,
    "message": "Data fetched successfully",
    "success": true,
    "data": {
        "enabled": false,
        "available": false,
        "duration_minutes": 120,
        "fee": 30,
        "opens_at": "08:00",
        "closes_at": "22:00",
        "available_again_at": null
    }
}
```

**Verify:**
- When `fast_shipping_enabled = false` in settings → `enabled` is `false`, `available` is `false`, `available_again_at` is `null`
- When current time is before `start_hour` → `enabled` is `true`, `available` is `false`, `available_again_at` = `start_hour`
- When current time is after `end_hour` → `enabled` is `true`, `available` is `false`, `available_again_at` = next day's `start_hour`
- When current time is between `start_hour` and `end_hour` → `available` is `true`, `available_again_at` is `null`

---

## Edge Cases

---

### Edge Case 1: Fast Shipping Disabled After Item in Cart

**Scenario:**
1. Add a fast-eligible product to cart
2. Admin disables fast shipping globally
3. Try to checkout fast

**Expected:** Checkout rejected with "Fast shipping is not available at this time."

**Verify:**
- Existing orders remain unaffected

---

### Edge Case 2: Governorate Disabled After Cart Creation

**Scenario:**
1. Add a fast-eligible product to cart
2. Admin disables fast shipping for the governorate
3. Try to checkout fast

**Expected:** Checkout rejected with "Fast shipping is not available in your governorate."

---

### Edge Case 3: Duration Changed After Order Creation

**Scenario:**
1. Create fast order (duration = 120 min, ETA = now + 120 min)
2. Admin changes duration to 90 min
3. Create another fast order

**Verify:**
- Existing order has original ETA (120 min from creation)
- New order has ETA = 90 min from creation

---

### Edge Case 4: Fee Changed After Order Creation

**Scenario:**
1. Create fast order (fee = 30 EGP)
2. Admin changes fee to 50 EGP
3. Create another fast order

**Verify:**
- Existing order has `fast_shipping_fee = 30`
- New order has `fast_shipping_fee = 50`

---

### Edge Case 5: Checkout After Closing Hour

**Scenario:**
1. Customer opens fast shipping page at 21:50 (closes at 22:00)
2. Customer takes time, clicks checkout at 22:05

**Expected:** Checkout rejected with time validation error.

**Note:** Validation runs at checkout submission time, not page load time.

---

### Edge Case 6: Product Removed from Fast Shipping

**Scenario:**
1. Product A is fast-eligible
2. Customer adds Product A to cart
3. Admin sets `is_fast_shipping_available = false` for Product A
4. Customer tries fast checkout

**Expected:** Checkout rejected with "One or more items in your cart are not eligible for fast shipping."

**Verify:**
- Existing orders for Product A remain with their original ETA

---

### Edge Case 7: Stock Sold Out

**Scenario:**
1. Product A has quantity = 1 and is fast-eligible
2. Customer 1 adds Product A to cart
3. Customer 2 adds Product A to cart
4. Customer 1 completes fast checkout (stock reserved)
5. Customer 2 tries fast checkout

**Expected:** Customer 2 receives stock validation error.

---

### Edge Case 8: Mixed Cart Validation

**Scenario:**
1. Add Product A (fast-eligible) to cart
2. Add Product B (not fast-eligible) to cart
3. Try fast checkout

**Expected:** Checkout rejected with "One or more items in your cart are not eligible for fast shipping."

**Verify:**
- Cart is not cleared after rejection

---

### Edge Case 9: Timezone Handling

**Scenario:**
1. Set `app.timezone` to `'Africa/Cairo'` in `config/app.php`
2. Configure `start_hour = "08:00"`, `end_hour = "22:00"`
3. At 15:00 Cairo time, fast shipping should be available

**Verify:**
- `GET /api/v1/general/fast-shipping/status` returns `available: true`
- Checkout works

---

### Edge Case 10: Global Disable

**Scenario:**
1. Admin sets `fast_shipping_enabled = false`
2. Existing fast orders should remain unchanged
3. New fast checkout requests should be rejected

**Verify:**
- Existing orders in DB still have `shipping_method = 'FAST'`
- New checkout returns error

---

## Validation Rules (from FastCheckoutRequest)

| Field | Rules |
|-------|-------|
| `name` | required, string, max:255 |
| `user_phone` | required, string, max:255 |
| `user_email` | required, email, max:255 |
| `address` | required, array |
| `notes` | nullable, string |
| `governorate_id` | required, integer, exists:governorates,id |
| `selected_promotion_id` | nullable, integer, exists:promotions,id |
| `selected_gift_product_id` | nullable, integer, exists:products,id |

---

## Database Verification Queries

Use these SQL queries to verify data integrity:

```sql
-- Count customer's fast orders
SELECT id, shipping_method, expected_delivery_at, fast_shipping_fee, total_price, status
FROM orders
WHERE user_id = {user_id} AND shipping_method = 'FAST';

-- Check products eligible for fast shipping
SELECT id, name, is_fast_shipping_available
FROM products
WHERE is_fast_shipping_available = true;

-- Check governorates with fast shipping enabled
SELECT id, name, is_fast_shipping_enabled
FROM governorates
WHERE is_fast_shipping_enabled = true;

-- Check fast shipping settings
SELECT options->>'$.fast_shipping' as fast_shipping_settings
FROM settings
LIMIT 1;
```

---

## Customer Endpoints Summary

| # | Method | URL | Auth | Purpose |
|---|--------|-----|------|---------|
| 8 | `GET` | `/api/v1/general/fast-shipping/products` | Public | List fast-shipping-eligible products |
| 9 | `POST` | `/api/v1/general/checkout/fast` | Sanctum | Create a fast-shipping order |
| 16 | `GET` | `/api/v1/general/fast-shipping/orders` | Sanctum | List current user's fast orders |
| 17 | `GET` | `/api/v1/general/fast-shipping/status` | Public | Check fast shipping availability |

---

## Rollback Plan

If rollback is needed:

```bash
# Rollback all fast shipping migrations
php artisan migrate:rollback --path=packages/marvel/database/migrations/2026_06_08_000005_add_shipping_method_index_to_orders.php
php artisan migrate:rollback --path=packages/marvel/database/migrations/2026_06_08_000004_add_shipping_method_to_cart_items.php
php artisan migrate:rollback --path=packages/marvel/database/migrations/2026_06_08_000003_add_fast_shipping_to_orders.php
php artisan migrate:rollback --path=packages/marvel/database/migrations/2026_06_08_000002_add_fast_shipping_to_governorates.php
php artisan migrate:rollback --path=packages/marvel/database/migrations/2026_06_08_000001_add_fast_shipping_to_products.php
```
