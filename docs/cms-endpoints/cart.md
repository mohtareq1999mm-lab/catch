# Cart API

## Overview

The Cart module manages authenticated user shopping carts. It supports adding items, updating quantities, bulk adding, removing individual items, and clearing the entire cart. Cart items reserve inventory for a TTL of 3 days (`CartInventoryService::CART_TTL_DAYS`).

---

## Database Schema

### `carts` Table

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| `id` | bigint | PK, AUTO_INCREMENT | Unique identifier |
| `user_id` | bigint | FK → users.id | Cart owner |
| `coupon` | varchar(255) | NULLABLE | Applied coupon code |
| `total_price` | decimal(15,4) | NULLABLE | Computed sum of item total prices |
| `status` | varchar(50) | DEFAULT 'active' | `active`, `checked_out`, `expired` |
| `reserved_at` | timestamp | NULLABLE | Last reservation timestamp |
| `expires_at` | timestamp | NULLABLE | Reservation expiry (now + 3 days) |
| `created_at` | timestamp | NULLABLE | |
| `updated_at` | timestamp | NULLABLE | |

### `cart_items` Table

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| `id` | bigint | PK, AUTO_INCREMENT | Unique identifier |
| `cart_id` | bigint | FK → carts.id, CASCADE | Parent cart |
| `product_id` | bigint | FK → products.id | Product reference |
| `product_variant_id` | bigint | FK → product_variants.id, NULLABLE | Variant reference |
| `quantity` | int | NOT NULL | Desired quantity |
| `reserved_quantity` | int | DEFAULT 0 | Quantity reserved in inventory |
| `price` | decimal(15,4) | NULLABLE | Unit price at time of add |
| `total_price` | decimal(15,4) | NULLABLE | price × quantity |
| `attributes` | json | NULLABLE | Selected variant attributes |
| `is_gift` | tinyint(1) | DEFAULT 0 | Gift item flag |
| `promotion_id` | bigint | FK → promotions.id, NULLABLE | Associated promotion |
| `shipping_method` | varchar(255) | NULLABLE | Selected shipping method |
| `created_at` | timestamp | NULLABLE | |
| `updated_at` | timestamp | NULLABLE | |

---

## Authentication

All endpoints require `auth:sanctum` middleware.

| Guard | Sanctum |
|-------|---------|
| Permissions | Authenticated user only |
| Rate Limit | `throttle:cart` — 20 req/min per user (RouteServiceProvider::configureRateLimiting()) |

---

## 1. GET /cart — List Cart

### Purpose
Retrieve the authenticated user's cart with all items, products, and variant details.

### Query Parameters

| Field | Type | Required | Default | Description |
|-------|------|----------|---------|-------------|
| `limit` | integer | No | 15 | Items per page |
| `page` | integer | No | 1 | Page number |

### Success Response (200)

```json
{
    "message": "Data fetched successfully",
    "status": 200,
    "success": true,
    "data": {
        "id": 1,
        "user_id": 5,
        "status": "active",
        "reserved_at": "2026-06-27T12:00:00.000000Z",
        "expires_at": "2026-06-30T12:00:00.000000Z",
        "total_items": 2,
        "total_quantity": 5,
        "total_price": 249.98,
        "items": [
            {
                "id": 1,
                "product_id": 10,
                "product_variant_id": null,
                "quantity": 3,
                "price": 49.99,
                "total_price": 149.97,
                "attributes": null,
                "product": {
                    "id": 10,
                    "name": "Wireless Headphones",
                    "slug": "wireless-headphones",
                    "thumbnail": "https://example.com/storage/products/headphones.jpg"
                }
            },
            {
                "id": 2,
                "product_id": 15,
                "product_variant_id": 3,
                "quantity": 2,
                "price": 50.00,
                "total_price": 100.00,
                "attributes": [
                    {
                        "attribute": "Color",
                        "value": "Black"
                    },
                    {
                        "attribute": "Size",
                        "value": "Large"
                    }
                ],
                "product": {
                    "id": 15,
                    "name": "T-Shirt",
                    "slug": "t-shirt",
                    "thumbnail": "https://example.com/storage/products/tshirt.jpg"
                }
            }
        ]
    },
    "page": 1,
    "current_page": 1,
    "from": 1,
    "to": 2,
    "last_page": 1,
    "path": "http://localhost/api/cart",
    "per_page": 15,
    "total": 2,
    "next_page_url": "",
    "prev_page_url": "",
    "last_page_url": "http://localhost/api/cart?page=1",
    "first_page_url": "http://localhost/api/cart?page=1"
}
```

### Error Responses

| Status | Description |
|--------|-------------|
| 401 | Unauthenticated |
| 429 | Too many requests (rate limit) |

---

## 2. POST /cart — Add Item to Cart

### Purpose
Add a single item (simple or variant) to the cart. If the item already exists, the quantity is **added** to the existing quantity (mode: `add`). Inventory is reserved.

### Request Body

```json
{
    "item": {
        "product_id": 10,
        "quantity": 2,
        "product_variant_id": null,
        "attributes": []
    }
}
```

### Validation Rules

| Field | Rules |
|-------|-------|
| `item` | required, array, min:1 |
| `item.product_id` | required, integer, exists:products,id |
| `item.quantity` | required, integer, min:1 |
| `item.product_variant_id` | sometimes, nullable, integer, exists:product_variants,id |
| `item.attributes` | sometimes, array |

### Business Logic

1. Finds or creates an active cart for the authenticated user.
2. Locks the cart row (`lockForUpdate`) to prevent race conditions.
3. If `product_variant_id` is provided, validates the variant belongs to the product.
4. If the product type is `variable` and no variant is provided, returns `INVALID_ITEM_DATA`.
5. Calls `CartInventoryService::reserveItem()` which:
   - Locks the inventory row (product or variant).
   - Checks available stock (`stock_quantity - reserved_quantity`).
   - Updates reserved quantity in inventory.
   - Creates or updates the cart item.
   - Sets `expires_at` to now + 3 days.
6. Recalculates cart `total_price` as sum of all item `total_price`.

### Success Response (201)

```json
{
    "message": "Data fetched successfully",
    "status": 201,
    "success": true,
    "data": {
        "id": 1,
        "user_id": 5,
        "status": "active",
        "reserved_at": "2026-06-27T12:00:00.000000Z",
        "expires_at": "2026-06-30T12:00:00.000000Z",
        "total_items": 1,
        "total_quantity": 2,
        "total_price": 99.98,
        "items": [
            {
                "id": 1,
                "product_id": 10,
                "product_variant_id": null,
                "quantity": 2,
                "price": 49.99,
                "total_price": 99.98,
                "attributes": null,
                "product": {
                    "id": 10,
                    "name": "Wireless Headphones",
                    "slug": "wireless-headphones",
                    "thumbnail": "https://example.com/storage/products/headphones.jpg"
                }
            }
        ]
    }
}
```

### Error Responses

| Status | Description |
|--------|-------------|
| 400 | Invalid item data / Quantity exceeds available stock |
| 401 | Unauthorized |
| 422 | Validation failed |
| 429 | Too many requests |

### Validation Error Response (422)

```json
{
    "item.product_id": ["The item.product_id field is required."],
    "item.quantity": ["The item.quantity must be at least 1."]
}
```

---

## 3. POST /cart/bulk-items — Bulk Add Items

### Purpose
Add multiple items to the cart in a single request. Each item is processed individually using the same `storeCart` logic.

### Request Body

```json
{
    "items": [
        {
            "product_id": 10,
            "quantity": 2,
            "product_variant_id": null
        },
        {
            "product_id": 15,
            "quantity": 1,
            "product_variant_id": 3
        }
    ]
}
```

### Validation Rules

| Field | Rules |
|-------|-------|
| `items` | required, array |
| `items.*.product_id` | required, exists:products,id |
| `items.*.quantity` | required, integer, min:1 |
| `items.*.product_variant_id` | nullable, exists:product_variants,id |

### Business Logic

1. Clones the request for each item.
2. Calls `CartRepository::storeCart()` for each item sequentially (same as POST /cart).
3. Returns the user's cart with all items loaded after processing.

### Success Response (201)

```json
{
    "message": "Cart created successfully",
    "status": 201,
    "success": true,
    "data": {
        "id": 1,
        "user_id": 5,
        "status": "active",
        "reserved_at": "2026-06-27T12:00:00.000000Z",
        "expires_at": "2026-06-30T12:00:00.000000Z",
        "total_items": 2,
        "total_quantity": 3,
        "total_price": 149.97,
        "items": [
            {
                "id": 1,
                "product_id": 10,
                "product_variant_id": null,
                "quantity": 2,
                "price": 49.99,
                "total_price": 99.98,
                "attributes": null,
                "product": { "id": 10, "name": "Wireless Headphones", "slug": "wireless-headphones", "thumbnail": "..." }
            },
            {
                "id": 2,
                "product_id": 15,
                "product_variant_id": 3,
                "quantity": 1,
                "price": 50.00,
                "total_price": 50.00,
                "attributes": [
                    { "attribute": "Color", "value": "Black" },
                    { "attribute": "Size", "value": "Large" }
                ],
                "product": { "id": 15, "name": "T-Shirt", "slug": "t-shirt", "thumbnail": "..." }
            }
        ]
    }
}
```

### Error Responses

| Status | Description |
|--------|-------------|
| 400 | Invalid item data / Quantity exceeds available stock |
| 401 | Unauthorized |
| 422 | Validation failed |
| 429 | Too many requests |

---

## 4. PUT /cart/update-item — Update Cart Item

### Purpose
Update a cart item's quantity to an **absolute value** (mode: `set`). If the quantity is 0 or less, the request is rejected.

### Request Body

```json
{
    "item": {
        "product_id": 10,
        "quantity": 5,
        "product_variant_id": null,
        "attributes": []
    }
}
```

### Validation Rules

| Field | Rules |
|-------|-------|
| `item` | required, array, min:1 |
| `item.product_id` | required_with:item, integer, exists:products,id |
| `item.quantity` | required_with:item, integer, min:1 |
| `item.product_variant_id` | sometimes, nullable, integer, exists:product_variants,id |
| `item.attributes` | sometimes, array |

### Business Logic

1. Same as store but with mode `set` instead of `add`.
2. The quantity becomes the absolute value (not incremental).
3. If the new quantity is less than the old quantity, excess reserved stock is released.
4. If the new quantity exceeds available stock, an error is thrown.

### Success Response (200)

```json
{
    "message": "Cart updated successfully",
    "status": 200,
    "success": true,
    "data": {
        "id": 1,
        "user_id": 5,
        "status": "active",
        "reserved_at": "2026-06-27T12:05:00.000000Z",
        "expires_at": "2026-06-30T12:05:00.000000Z",
        "total_items": 1,
        "total_quantity": 5,
        "total_price": 249.95,
        "items": [
            {
                "id": 1,
                "product_id": 10,
                "product_variant_id": null,
                "quantity": 5,
                "price": 49.99,
                "total_price": 249.95,
                "attributes": null,
                "product": {
                    "id": 10,
                    "name": "Wireless Headphones",
                    "slug": "wireless-headphones",
                    "thumbnail": "..."
                }
            }
        ]
    }
}
```

### Error Responses

| Status | Description |
|--------|-------------|
| 400 | Invalid item data / Quantity exceeds available stock |
| 401 | Unauthorized |
| 422 | Validation failed |
| 429 | Too many requests |

---

## 5. DELETE /cart/delete-item/{itemId} — Delete Single Cart Item

### Purpose
Remove a single item from the cart and release its reserved inventory.

### Path Parameters

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `itemId` | integer | Yes | Cart item ID |

### Business Logic

1. Finds the authenticated user's cart.
2. Verifies cart ownership.
3. Finds the cart item by `itemId` within the cart.
4. Calls `CartInventoryService::releaseItem(item, delete: true)` which:
   - Locks the cart item row.
   - Releases reserved stock back to inventory.
   - Deletes the cart item.
5. Recalculates cart `total_price`.

### Success Response (200)

```json
{
    "message": "Cart item deleted successfully",
    "status": 200,
    "success": true,
    "data": []
}
```

### Error Responses

| Status | Description |
|--------|-------------|
| 400 | Cart not found / Cart item not found / Delete failed |
| 401 | Unauthorized |
| 404 | Cart item not found |
| 429 | Too many requests |

---

## 6. DELETE /cart/delete-items — Clear Cart

### Purpose
Remove all items from the authenticated user's cart and release all reserved inventory.

### Business Logic

1. Finds the authenticated user's cart.
2. Verifies cart ownership.
3. Calls `CartInventoryService::releaseCart(cart, deleteItems: true)` which:
   - Iterates all cart items.
   - For each item: releases reserved stock, deletes the item.
   - Resets cart `total_price` to 0, clears reservation timestamps.

### Success Response (200)

```json
{
    "message": "Cart deleted successfully",
    "status": 200,
    "success": true,
    "data": []
}
```

### Error Responses

| Status | Description |
|--------|-------------|
| 400 | Cart item delete failed |
| 401 | Unauthorized |
| 429 | Too many requests |

---

## Resource Structure

### CartResource

| Field | Type | Description |
|-------|------|-------------|
| `id` | integer | Cart ID |
| `user_id` | integer | Owner user ID |
| `status` | string | `active`, `checked_out`, `expired` |
| `reserved_at` | string (datetime) | Last reservation time |
| `expires_at` | string (datetime) | Reservation expiry |
| `total_items` | integer | Count of distinct items |
| `total_quantity` | integer | Sum of all item quantities |
| `total_price` | float | Sum of all item total prices |
| `items` | array | Array of CartItemResource |

### CartItemResource

| Field | Type | Description |
|-------|------|-------------|
| `id` | integer | Item ID |
| `product_id` | integer | Product ID |
| `product_variant_id` | integer|null | Variant ID |
| `quantity` | integer | Quantity |
| `price` | float | Unit price |
| `total_price` | float | Unit price × quantity |
| `attributes` | array|null | Selected variant attributes |
| `product.id` | integer | Product ID |
| `product.name` | string | Product name |
| `product.slug` | string | Product slug |
| `product.thumbnail` | string | Product thumbnail URL |

---

## Dependencies

| Layer | Files |
|-------|-------|
| Controller | `packages/marvel/src/Http/Controllers/CartController.php` |
| Requests | `CartCreateRequest.php`, `CartUpdateRequest.php` |
| Resources | `CartResource.php`, `CartItemResource.php` |
| Repository | `CartRepository.php` |
| Services | `App\Services\General\CartInventoryService.php` |
| Models | `Cart.php`, `CartItem.php` |
| Routes | `packages/marvel/src/Rest/Routes.php:797-805` |

---

## Translation Keys

| Key | EN Value | AR Value |
|-----|----------|----------|
| `MESSAGE.FETCH_DATA_SUCCESSFULLY` | Data fetched successfully | تم جلب البيانات بنجاح |
| `MESSAGE.CREATE_CART_SUCCESSFULLY` | Cart created successfully | تم إنشاء السلة بنجاح |
| `MESSAGE.UPDATE_CART_SUCCESSFULLY` | Cart updated successfully | تم تحديث السلة بنجاح |
| `MESSAGE.DELETE_CART_SUCCESSFULLY` | Cart deleted successfully | تم حذف السلة بنجاح |
| `MESSAGE.DELETE_CART_ITEM_SUCCESSFULLY` | Cart item deleted successfully | تم حذف عنصر السلة بنجاح |
| `ERROR.DELETE_CART_ITEM_FAILED` | Cart item delete failed | فشل حذف عنصر السلة |
| `ERROR.INVALID_ITEM_DATA` | Invalid item data | بيانات العنصر غير صالحة |
| `ERROR.NOT_AUTHORIZED` | Not authorized | غير مخول |
