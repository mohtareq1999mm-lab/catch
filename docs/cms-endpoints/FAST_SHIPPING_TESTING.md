# Fast Shipping Feature - Manual Testing Guide

## Meem Commerce (Marvel)

---

## Prerequisites

1. Run all migrations:
```bash
php artisan migrate
```

2. Run `composer dump-autoload` to register new classes.

3. Ensure you have:
   - A test user with auth token
   - An admin user (SUPER_ADMIN role)
   - At least one active governorate
   - At least one active product in stock
   - The settings table has at least one row

---

## Test Suite

---

### Test 1: Admin - Configure Fast Shipping Settings

**Endpoint:** `PUT /api/v1/fast-shipping/settings`

**Auth:** SUPER_ADMIN (Bearer token)

**Request Body:**
```json
{
    "enabled": true,
    "duration_minutes": 120,
    "fee": 30,
    "start_hour": "08:00",
    "end_hour": "22:00"
}
```

**Expected Response (200):**
```json
{
    "status": true,
    "message": "Fast shipping settings updated successfully"
}
```

**Verify:**
- Settings are persisted in `settings.options.fast_shipping`

---

### Test 2: Admin - Get Fast Shipping Settings

**Endpoint:** `GET /api/v1/fast-shipping/settings`

**Auth:** SUPER_ADMIN (Bearer token)

**Expected Response (200):**
```json
{
    "status": true,
    "message": "Data fetched successfully",
    "data": {
        "enabled": true,
        "duration_minutes": 120,
        "fee": 30,
        "start_hour": "08:00",
        "end_hour": "22:00"
    }
}
```

**Verify:**
- Settings match what was configured in Test 1

---

### Test 3: Admin - Toggle Fast Shipping on Governorate

**Endpoint:** `PUT /api/v1/governorates/{id}/fast-shipping`

**Auth:** SUPER_ADMIN or STAFF (Bearer token)

**Request Body:**
```json
{
    "is_fast_shipping_enabled": true
}
```

**Expected Response (200):**
```json
{
    "status": true,
    "message": "Governorate updated successfully",
    "data": {
        "id": 1,
        "name": "Cairo",
        "is_fast_shipping_enabled": true,
        ...
    }
}
```

**Verify:**
- Governorate now has `is_fast_shipping_enabled: true`

**Negative Test:** Send invalid boolean:
```json
{ "is_fast_shipping_enabled": "yes" }
```
Expected: 422 validation error.

---

### Test 4: Admin - Toggle Fast Shipping on Product

**Endpoint:** `PUT /api/v1/products/{id}/fast-shipping`

**Auth:** SUPER_ADMIN or STAFF (Bearer token)

**Request Body:**
```json
{
    "is_fast_shipping_available": true
}
```

**Expected Response (200):**
```json
{
    "status": true,
    "message": "Product updated successfully",
    "data": {
        "id": 1,
        "name": "Test Product",
        "is_fast_shipping_available": true,
        ...
    }
}
```

**Verify:**
- Product now has `is_fast_shipping_available: true`

**Negative Test:** Product not found (invalid ID):
Expected: 404.

---

### Test 5: Admin - Update Product with Fast Shipping Flag (via Product CRUD)

**Endpoint:** `PUT /api/v1/products/{id}`

**Auth:** SUPER_ADMIN or STAFF (Bearer token)

**Request Body (partial):**
```json
{
    "is_fast_shipping_available": true,
    "price": 150,
    ...
}
```

**Verify:**
- The existing Product Update endpoint accepts `is_fast_shipping_available`

---

### Test 6: Admin - Verify Governorate Resource Includes Fast Shipping Flag

**Endpoint:** `GET /api/v1/governorates`

**Auth:** SUPER_ADMIN (Bearer token)

**Expected Response:**
```json
{
    "status": true,
    "data": [
        {
            "id": 1,
            "name": "Cairo",
            "is_fast_shipping_enabled": true,
            ...
        }
    ]
}
```

**Verify:**
- `is_fast_shipping_enabled` is present in the response

---

### Test 7: Customer - Get Fast Shipping Status

**Endpoint:** `GET /api/v1/general/fast-shipping/status`

**Auth:** None required (public)

**Expected Response (when within hours, enabled):**
```json
{
    "status": true,
    "message": "Data fetched successfully",
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

**Expected Response (when outside hours):**
```json
{
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

**Verify:**
- When `fast_shipping_enabled = false` in settings, `enabled` is `false` and `available` is `false`
- When current time is before `start_hour` or after `end_hour`, `available` is `false` and `available_again_at` is populated

---

### Test 8: Customer - List Fast Shipping Products

**Endpoint:** `GET /api/v1/general/fast-shipping/products`

**Auth:** None required (public)

**Expected Response:**
```json
{
    "status": true,
    "message": "Data fetched successfully",
    "data": [...]
}
```

**Verify:**
- Only products with `is_fast_shipping_available = true` appear
- Products with `is_fast_shipping_available = false` do NOT appear
- Supports `?search=term`, `?limit=N`, `?page=N` query params

---

### Test 9: Customer - Fast Checkout (Success Path)

**Prerequisites:**
- Fast shipping globally enabled (Test 1)
- Within working hours (08:00 - 22:00)
- Governorate has `is_fast_shipping_enabled = true` (Test 3)
- All cart items have `is_fast_shipping_available = true` (Test 4)
- Cart is not empty
- Products are in stock

**Endpoint:** `POST /api/v1/general/checkout/fast`

**Auth:** Customer (Bearer token)

**Request Body:**
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
    "status": true,
    "message": "Checkout successful",
    "data": {
        "url": "https://myfatoorah.invoice.url/..."
    }
}
```

**Verify in Database:**
- Order created with `shipping_method = 'FAST'`
- Order has `expected_delivery_at = now() + 120 minutes`
- Order has `fast_shipping_fee = 30`
- Order has `total_price = subtotal + promotions discount + coupon discount + fast_shipping_fee`
- Order has status `pending`

---

### Test 10: Customer - Fast Checkout (Feature Disabled)

**Prerequisites:**
- Set `fast_shipping_enabled = false` in settings

**Endpoint:** `POST /api/v1/general/checkout/fast`

**Expected Response (422):**
```json
{
    "message": "Fast shipping is not available at this time.",
    "errors": {...}
}
```

**Verify:**
- Checkout is rejected
- No order is created

---

### Test 11: Customer - Fast Checkout (Outside Working Hours)

**Prerequisites:**
- Set start_hour to a future time (e.g., if now is 14:00, set start_hour to "20:00" and end_hour to "22:00")
- OR set end_hour to a past time (e.g., end_hour = "06:00")

**Endpoint:** `POST /api/v1/general/checkout/fast`

**Expected Response (422):**
```json
{
    "message": "Fast shipping is only available between 20:00 and 22:00.",
    "errors": {...}
}
```

**Verify:**
- Checkout is rejected
- No order is created

After test, restore working hours to current time range.

---

### Test 12: Customer - Fast Checkout (Governorate Not Enabled)

**Prerequisites:**
- Set governorate's `is_fast_shipping_enabled = false`

**Endpoint:** `POST /api/v1/general/checkout/fast`

**Expected Response (422):**
```json
{
    "message": "Fast shipping is not available in your governorate.",
    "errors": {...}
}
```

**Verify:**
- Checkout is rejected
- No order is created

After test, restore governorate's fast shipping to enabled.

---

### Test 13: Customer - Fast Checkout (Mixed Cart - Contains Non-Fast Product)

**Prerequisites:**
- Cart contains at least one product with `is_fast_shipping_available = false`

**Endpoint:** `POST /api/v1/general/checkout/fast`

**Expected Response (422):**
```json
{
    "message": "One or more items in your cart are not eligible for fast shipping.",
    "errors": {...}
}
```

**Verify:**
- Checkout is rejected
- No order is created

---

### Test 14: Customer - Fast Checkout (Empty Cart)

**Prerequisites:**
- Cart has no items

**Endpoint:** `POST /api/v1/general/checkout/fast`

**Expected Response (400):**
```json
{
    "message": "Cart not found",
    "status": false
}
```

**Verify:**
- Checkout is rejected

---

### Test 15: Customer - Fast Checkout (Product Out of Stock)

**Prerequisites:**
- Set a fast-eligible product's stock to 0

**Endpoint:** `POST /api/v1/general/checkout/fast`

**Expected Response (400):**
```json
{
    "message": "Insufficient stock for ...",
    "status": false
}
```

**Verify:**
- Cart inventory reservation fails
- Checkout is rejected

---

### Test 16: Customer - List Fast Orders

**Endpoint:** `GET /api/v1/general/fast-shipping/orders`

**Auth:** Customer (Bearer token)

**Expected Response:**
```json
{
    "status": true,
    "message": "Data fetched successfully",
    "data": [...]
}
```

**Verify:**
- Only orders with `shipping_method = 'FAST'` appear
- Pagination works with `?limit=N&page=N`
- Each order includes `expected_delivery_at` and `fast_shipping_fee`

---

### Test 17: Customer - Scheduled Orders Still Work

**Endpoint:** `POST /api/v1/general/checkout`

**Auth:** Customer (Bearer token)

**Verify:**
- Regular checkout still works as before
- Order created has `shipping_method = 'SCHEDULED'` (default)
- No `fast_shipping_fee` (or 0)
- No `expected_delivery_at` (null)

---

### Test 18: Customer - Regular Order Listing Shows All Orders

**Endpoint:** `GET /api/v1/general/orders`

**Auth:** Customer (Bearer token)

**Verify:**
- Both SCHEDULED and FAST orders appear
- The existing order listing is not broken

---

### Test 19: Admin - Order Listing with Shipping Method Filter

**Endpoint:** `GET /api/v1/orders?shipping_method=FAST`

**Auth:** SUPER_ADMIN (Bearer token)

**Verify:**
- Only orders with `shipping_method = 'FAST'` are returned

**Endpoint:** `GET /api/v1/orders?shipping_method=SCHEDULED`

**Verify:**
- Only orders with `shipping_method = 'SCHEDULED'` are returned

---

### Test 20: Admin - Create Product with Fast Shipping (via Product CRUD)

**Endpoint:** `POST /api/v1/products`

**Auth:** SUPER_ADMIN or STAFF (Bearer token)

**Request Body (relevant fields):**
```json
{
    "name": "Fast Eligible Product",
    "price": 100,
    "is_fast_shipping_available": true,
    "has_discount": false,
    "has_flash_sale": false,
    "in_stock": true,
    "status": true,
    ...
}
```

**Verify:**
- Product created with `is_fast_shipping_available = true`
- Product appears in `GET /api/v1/general/fast-shipping/products`

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

**Note:** This is dependent on when the time validation runs (at checkout time, not page load time).

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
1. Set app.timezone to 'Africa/Cairo' in config/app.php
2. Configure start_hour = "08:00", end_hour = "22:00"
3. At 15:00 Cairo time, fast shipping should be available

**Verify:**
- Fast shipping status returns `available: true`
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

## Database Verification Queries

Use these SQL queries to verify data integrity:

```sql
-- Count orders by shipping method
SELECT shipping_method, COUNT(*) as count
FROM orders
GROUP BY shipping_method;

-- Check fast orders with ETA
SELECT id, shipping_method, expected_delivery_at, fast_shipping_fee, total_price, status
FROM orders
WHERE shipping_method = 'FAST';

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

---

## Files Summary

### New Files Created

| File | Purpose |
|------|---------|
| `packages/marvel/src/Enums/ShippingMethod.php` | SCHEDULED/FAST enum |
| `packages/marvel/src/Database/Repositories/FastShippingRepository.php` | Core business logic & validations |
| `app/Services/General/FastShippingService.php` | Customer-facing service layer |
| `app/Http/Controllers/Api/General/FastShippingController.php` | Customer API endpoints |
| `packages/marvel/src/Http/Controllers/FastShippingController.php` | Admin API endpoints |
| `packages/marvel/src/Http/Requests/FastCheckoutRequest.php` | Fast checkout validation |

### Migrations Created

| File | Changes |
|------|---------|
| `2026_06_08_000001_add_fast_shipping_to_products.php` | Adds `is_fast_shipping_available` to `products` |
| `2026_06_08_000002_add_fast_shipping_to_governorates.php` | Adds `is_fast_shipping_enabled` to `governorates` |
| `2026_06_08_000003_add_fast_shipping_to_orders.php` | Adds `shipping_method`, `expected_delivery_at`, `fast_shipping_fee` to `orders` |
| `2026_06_08_000004_add_shipping_method_to_cart_items.php` | Adds `shipping_method` to `cart_items` |
| `2026_06_08_000005_add_shipping_method_index_to_orders.php` | Adds index on `orders.shipping_method` |

### Modified Files

| File | Changes |
|------|---------|
| `packages/marvel/src/Enums/Permission.php` | Added `VIEW_FAST_SHIPPING`, `UPDATE_FAST_SHIPPING` |
| `packages/marvel/src/Database/Models/Product.php` | Added `is_fast_shipping_available` fillable, cast, scope |
| `packages/marvel/src/Database/Models/Governorate.php` | Added `is_fast_shipping_enabled` fillable, cast, scope |
| `packages/marvel/src/Database/Models/Order.php` | Added shipping fields to fillable, casts, scopes |
| `packages/marvel/src/Database/Models/CartItem.php` | Added `shipping_method` to fillable |
| `packages/marvel/src/Database/Repositories/OrderRepository.php` | Added `shipping_method` to searchable fields |
| `packages/marvel/src/Http/Controllers/GovernorateController.php` | Added `toggleFastShipping()` method |
| `packages/marvel/src/Http/Controllers/ProductController.php` | Added `toggleFastShipping()` method |
| `packages/marvel/src/Http/Resources/GovernorateResource.php` | Added `is_fast_shipping_enabled` |
| `packages/marvel/src/Http/Requests/GovernorateStoreRequest.php` | Added `is_fast_shipping_enabled` validation |
| `packages/marvel/src/Http/Requests/GovernorateUpdateRequest.php` | Added `is_fast_shipping_enabled` validation |
| `packages/marvel/src/Http/Requests/ProductCreateRequest.php` | Added `is_fast_shipping_available` validation |
| `packages/marvel/src/Http/Requests/ProductUpdateRequest.php` | Added `is_fast_shipping_available` validation |
| `packages/marvel/config/constants.php` | Added fast shipping error constants |
| `resources/lang/en/message.php` | Added English translations |
| `resources/lang/ar/message.php` | Added Arabic translations |
| `routes/api.php` | Added fast shipping customer routes |
| `packages/marvel/src/Rest/Routes.php` | Added fast shipping admin routes |
