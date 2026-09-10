# Order System Documentation

> Comprehensive guide for backend developers working on the Order system.
> Last updated: 2026-07-01

---

## Table of Contents

1. [Database Structure](#database-structure)
2. [Order Flow](#order-flow)
3. [Relationships](#relationships)
4. [Business Rules](#business-rules)
5. [Existing APIs](#existing-apis)
6. [Class Map](#class-map)

---

## Database Structure

### orders table

The `orders` table is the core of the order system. It stores every customer order as a single row.

| Column | Type | Purpose |
|---|---|---|
| `id` | bigIncrements | Primary key |
| `user_id` | unsignedBigInteger (FK -> users) | The customer who placed this order |
| `name` | string | Customer name at time of order (snapshotted) |
| `user_phone` | string | Customer phone at time of order (snapshotted) |
| `user_email` | string | Customer email at time of order (snapshotted) |
| `address` | json | Shipping/billing address snapshot (full address object) |
| `notes` | text, nullable | Customer notes for the order |
| `shipping_price` | decimal, nullable | Shipping fee charged |
| `total_price` | decimal | Final total paid by customer (after all discounts) |
| `price` | decimal | Subtotal before shipping (sum of product prices × quantities) |
| `coupon` | string, nullable | Coupon code used (snapshotted) |
| `coupon_discount` | decimal, nullable | Discount amount from coupon |
| `coupon_discount_type` | string, nullable | Coupon discount type (percentage/fixed) |
| `coupon_discount_max_amount` | decimal, nullable | Maximum discount cap for the coupon |
| `promotion_id` | integer, nullable | ID of the promotion applied |
| `promotion_code` | string, nullable | Promotion code (snapshotted) |
| `promotion_type` | string, nullable | Promotion type (snapshotted) |
| `promotion_discount` | decimal, nullable | Discount amount from promotion |
| `status` | string | Order status (e.g. pending, completed, cancelled) |
| `shipping_method` | string, nullable | Shipping method (e.g. SCHEDULED, FAST) |
| `expected_delivery_at` | datetime, nullable | Expected delivery date |
| `fast_shipping_fee` | decimal, nullable | Additional fee for fast shipping |
| `deleted_at` | timestamp, nullable | Soft delete timestamp |
| `created_at` | timestamp | Record creation time |
| `updated_at` | timestamp | Record last update time |

**Key business decisions:**
- Customer data (`name`, `phone`, `email`, `address`) is **snapshotted** — it does not update if the user changes their profile later.
- `price` = subtotal (products before discounts). `total_price` = final amount charged.
- `coupon_*` and `promotion_*` fields are snapshots so historical orders remain accurate.
- The table uses `SoftDeletes` — orders are never hard-deleted.

### order_products table

Stores individual line items within an order. This is a **snapshot** of the product at the time of purchase.

| Column | Type | Purpose |
|---|---|---|
| `id` | bigIncrements | Primary key |
| `order_id` | unsignedBigInteger (FK -> orders) | Parent order |
| `product_id` | unsignedBigInteger (FK -> products) | Reference to product (may point to deleted product) |
| `product_variant_id` | unsignedBigInteger, nullable (FK -> product_variants) | Reference to variant if applicable |
| `product_name` | string | Product name at time of order (snapshotted) |
| `product_sku` | string, nullable | Product SKU at time of order (snapshotted) |
| `attributes` | json, nullable | Selected variant attributes (e.g. color, size) |
| `product_quantity` | integer | Quantity ordered |
| `product_price` | decimal | Unit price at time of order |
| `product_total_price` | decimal | Line total (price × quantity) |
| `product_discount_price` | decimal, nullable | Discount amount on this item |
| `product_flash_sale_price` | decimal, nullable | Flash sale price if applicable |
| `promotion_discount_amount` | decimal, nullable | Discount amount from promotion |
| `is_gift` | boolean | Whether this item was added as a gift from a promotion |
| `promotion_id` | integer, nullable | Reference to the promotion that added this item |
| `created_at` | timestamp | Record creation time |
| `updated_at` | timestamp | Record last update time |

**Why product data is duplicated here:** Products can be deleted or changed after an order is placed. Duplicating the name, SKU, and prices ensures the order history remains accurate forever. This is a standard e-commerce pattern.

### transactions table

Tracks payment transactions linked to orders.

| Column | Type | Purpose |
|---|---|---|
| `id` | bigIncrements | Primary key |
| `order_id` | unsignedBigInteger (FK -> orders) | The order this transaction belongs to |
| `invoice_id` | string | External payment gateway invoice/transaction ID |
| `payment_method` | string | Payment method used (e.g. myfatoorah, stripe) |
| `user_id` | unsignedBigInteger (FK -> users) | The user who made the payment |
| `created_at` | timestamp | Record creation time |
| `updated_at` | timestamp | Record last update time |

---

## Order Flow

The order creation process involves multiple steps across several classes.

```
Client Request
     │
     ▼
┌─────────────────────────────────────────────────┐
│ 1. Validation (OrderCreateRequest)              │
│    File: packages/marvel/src/Http/Requests/     │
│          OrderCreateRequest.php                 │
│    Validates: name, user_phone, user_email,     │
│               address, notes, promotion_id      │
└─────────────────────────────────────────────────┘
     │
     ▼
┌─────────────────────────────────────────────────┐
│ 2. Cart Retrieval                               │
│    File: app/Services/General/OrderService.php  │
│    Method: getCartUser()                        │
│    - Fetches active cart for authenticated user │
│    - Ensures cart exists and has items          │
└─────────────────────────────────────────────────┘
     │
     ▼
┌─────────────────────────────────────────────────┐
│ 3. Cart Reservation Check                       │
│    File: app/Services/General/                  │
│          CartInventoryService.php               │
│    - Ensures cart items are reserved            │
│    - Validates stock availability               │
└─────────────────────────────────────────────────┘
     │
     ▼
┌─────────────────────────────────────────────────┐
│ 4. Price Calculation                            │
│    Files: OrderService + PromotionService +     │
│           ProductPricingService                 │
│    - Calculates subtotal from cart items        │
│    - Applies promotion discounts                │
│    - Applies coupon discounts                   │
│    - Computes final total                       │
└─────────────────────────────────────────────────┘
     │
     ▼
┌─────────────────────────────────────────────────┐
│ 5. External Payment Invoice Creation            │
│    File: app/Services/General/                  │
│          MyfatoraService.php                    │
│    - Creates invoice on external payment gateway│
│    - Returns payment URL for redirect           │
└─────────────────────────────────────────────────┘
     │
     ▼
┌─────────────────────────────────────────────────┐
│ 6. Order Creation (Database)                    │
│    File: app/Services/General/OrderService.php  │
│    Method: saveOrderInDatabase()                │
│    - Inserts row into `orders` table            │
│    - Stores all price snapshots                 │
│    - Stores customer info snapshot              │
└─────────────────────────────────────────────────┘
     │
     ▼
┌─────────────────────────────────────────────────┐
│ 7. Order Items Creation                         │
│    File: app/Services/General/OrderService.php  │
│    Method: createOrderItems()                   │
│    - Iterates cart items                        │
│    - Inserts rows into `order_products` table   │
│    - Snapshots product name, SKU, prices        │
│    - Stores variant attributes                  │
└─────────────────────────────────────────────────┘
     │
     ▼
┌─────────────────────────────────────────────────┐
│ 8. Transaction Creation                         │
│    File: app/Services/General/OrderService.php  │
│    Method: createTransaction()                  │
│    - Inserts row into `transactions` table      │
│    - Stores invoice_id from payment gateway     │
└─────────────────────────────────────────────────┘
     │
     ▼
┌─────────────────────────────────────────────────┐
│ 9. Payment Callback Handling                    │
│    File: app/Http/Controllers/Api/General/      │
│          OrderController.php                    │
│    Methods: checkoutCallback(),                 │
│             checkoutErrorCallback()             │
│    - Validates payment via webhook/callback     │
│    - Updates order status (completed/cancelled) │
│    - Releases or finalizes cart reservation     │
└─────────────────────────────────────────────────┘
```

### Alternative Flow: Admin Order Creation

For admin/store-owner order creation, the flow goes through `Marvel\Http\Controllers\OrderController::store()` → `Marvel\Database\Repositories\OrderRepository::storeOrder()`:

```
Request (OrderCreateRequest)
     │
     ▼
OrderRepository::storeOrder()
     │
     ├── Generate tracking number
     ├── Set order/payment status based on gateway
     ├── Calculate subtotal
     ├── Validate and lock stock (pessimistic locking)
     ├── Apply coupon (validate usage limits)
     ├── Apply wallet points if applicable
     ├── Order::create() + processProducts() + attach()
     ├── Create child orders (per-shop sub-orders)
     ├── Deduct stock
     ├── Record coupon usage
     ├── Create payment intent (for online gateways)
     └── Fire events (OrderCreated, OrderProcessed, OrderReceived)
```

---

## Relationships

### Order → User (BelongsTo)

```php
// Order model
public function user(): BelongsTo
{
    return $this->belongsTo(User::class);
}
```

**Why:** Every order belongs to a customer. This allows querying "all orders for this user" and accessing user details from an order.

### Order → OrderProduct (HasMany)

```php
// Order model
public function orderItems(): HasMany
{
    return $this->hasMany(OrderProduct::class);
}
```

**Why:** One order can have multiple products. Each product line is a separate `order_products` row.

### Order → Transaction (HasMany)

```php
// Order model
public function transactions(): HasMany
{
    return $this->hasMany(Transaction::class);
}
```

**Why:** An order can have multiple payment attempts/transactions (e.g. retry after failure).

### OrderProduct → Product (BelongsTo)

```php
// OrderProduct model
public function product(): BelongsTo
{
    return $this->belongsTo(Product::class);
}
```

**Why:** Links the line item back to the original product for additional data (images, categories, etc.). Handles soft-deleted products gracefully.

### OrderProduct → ProductVariant (BelongsTo)

```php
// OrderProduct model
public function productVariant(): BelongsTo
{
    return $this->belongsTo(ProductVariant::class);
}
```

**Why:** If the product had variations (size, color), this links to the specific variant chosen.

---

## Business Rules

### Why Product Data is Duplicated in order_products

Products are mutable. Names change. Prices change. Products get deleted. By storing a snapshot of `product_name`, `product_sku`, `product_price`, etc. in each `order_products` row, the historical record of what the customer actually bought and paid is preserved forever.

### How Deleted Products Are Handled

- `order_products.product_id` still references the original product (even if soft-deleted).
- The `order_products` table stores its own copies of name/SKU/price, so even if the product is hard-deleted, the order data remains intact.
- The `Product` model uses `SoftDeletes`, so the FK relationship still resolves (returns null for trashed products, but the snapshot data is used as fallback).

### How Deleted Users Are Handled

- `orders.user_id` is a FK to the `users` table.
- The `User` model uses `SoftDeletes`, so the relationship resolver in `Order::user()` will return null for deleted users.
- Customer name/phone/email are snapshotted in the `orders` table itself, so user data is never lost even if the user is deleted.

### How Prices Are Protected After Order Creation

- All prices are calculated and stored at the moment of order creation.
- The `orders` table stores `price` (subtotal), `total_price` (final), `coupon_discount`, `promotion_discount`, etc.
- The `order_products` table stores `product_price` (unit price), `product_total_price` (line total), `product_discount_price`, `product_flash_sale_price`, `promotion_discount_amount`.
- No subsequent changes to products, coupons, or promotions will affect existing orders.

---

## Existing APIs

### GET /general/orders (Customer Order List)

**Controller:** `App\Http\Controllers\Api\General\OrderController@index`

**Authentication:** Required (`auth:sanctum`)

**Query Parameters:**
| Parameter | Type | Default | Description |
|---|---|---|---|
| `limit` | integer | 15 | Items per page (max 100) |

**Response (200):**
```json
{
    "status": 200,
    "message": "Data fetched successfully",
    "success": true,
    "data": [
        {
            "id": 1,
            "order_number": "ORD-00000001",
            "status": "pending",
            "subtotal": 150.00,
            "discount": 10.00,
            "total": 140.00,
            "promotion": {
                "id": 2,
                "type": "percentage",
                "code": "SUMMER20"
            },
            "created_at": "2026-06-30T12:00:00.000000Z",
            "order_items": [
                {
                    "id": 1,
                    "quantity": 2,
                    "unit_price": 50.00,
                    "total_price": 100.00,
                    "promotion_discount_amount": 5.00,
                    "is_gift": false,
                    "promotion_id": null,
                    "product": {
                        "id": 1,
                        "name": "Product Name",
                        "sku": "PRD-001",
                        "image": "..."
                    },
                    "variant": {
                        "id": 5,
                        "price": 50.00,
                        "current_price": 45.00,
                        "in_stock": true,
                        "attributes": [
                            {"value_id": 10, "value": "Red"}
                        ]
                    }
                }
            ]
        }
    ],
    "links": {
        "current_page": 1,
        "from": 1,
        "to": 15,
        "last_page": 5,
        "path": "...",
        "per_page": 15,
        "total": 75,
        "next_page_url": "...",
        "prev_page_url": null,
        "last_page_url": "...",
        "first_page_url": "..."
    }
}
```

### GET /general/checkout/promotions (Eligible Promotions)

**Controller:** `App\Http\Controllers\Api\General\OrderController@eligiblePromotions`

**Authentication:** Required

Returns all promotions applicable to the current user's cart.

### POST /general/checkout (Create Order)

**Controller:** `App\Http\Controllers\Api\General\OrderController@checkout`

**Authentication:** Required

**Request Body:**
```json
{
    "name": "John Doe",
    "user_phone": "+201234567890",
    "user_email": "john@example.com",
    "address": {
        "street": "123 Main St",
        "city": "Cairo",
        "country": "EG"
    },
    "notes": "Leave at door",
    "selected_promotion_id": 2,
    "selected_gift_product_id": null
}
```

**Flow:** Validates → Checks cart → Calculates prices → Creates external payment invoice → Creates order → Creates order items → Creates transaction → Returns payment URL.

### GET /api/v1/orders (Admin Order List)

**Controller:** `Marvel\Http\Controllers\OrderController@index`

**Authentication:** Required (Super Admin via `auth:sanctum`)

**Middleware:** `role:super_admin`, `auth:sanctum`, `email.verified`

**Description:** Retrieve a paginated list of orders. Customers see their own orders. Store Owners/Staff see orders for their shop. Super Admins see all.

**Query Parameters:**
| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `limit` | integer | 10 | Items per page |
| `page` | integer | 1 | Page number |
| `shop_id` | integer | - | Filter by Shop ID (for Store Owners/Staff) |
| `tracking_number` | string | - | Search by tracking number |

**Authorization rules:**
- Super Admin: sees all orders (`parent_id IS NULL`)
- Store Owner: sees orders for their shop(s) (`shop_id = X`, `parent_id IS NOT NULL`)
- Staff: sees orders for their assigned shop (`shop_id = X`, `parent_id IS NOT NULL`)
- Customer: sees own orders (`customer_id = user.id`, `parent_id IS NULL`)

---

### GET /api/v1/orders/{id} (Admin Order Detail)

**Controller:** `Marvel\Http\Controllers\OrderController@show`

**Authentication:** Required (Super Admin via `auth:sanctum`)

**Middleware:** `role:super_admin`, `auth:sanctum`, `email.verified`

**Description:** Retrieve a single order by ID or tracking number. Access restricted to the Customer who owns it, or Store Owner/Staff of the shop.

**URL Parameters:**
| Parameter | Type | Description |
|-----------|------|-------------|
| `id` | string | Order ID or Tracking Number |

**Eager loaded relations:** `products`, `shop`, `children.shop`, `wallet_point`

**Note:** The `show` method sets `tracking_number` from the route parameter and delegates to `fetchSingleOrder()` which queries by `id` OR `tracking_number`. For online payment gateways, a `payment_intent` is attached.

---

### POST /api/v1/orders (Admin Order Creation)

**Controller:** `Marvel\Http\Controllers\OrderController@store`

**Authentication:** Required (Super Admin / Store Owner)

Uses `OrderRepository::storeOrder()` which handles stock locking, coupon validation, wallet points, child orders, and payment intents.

---

## Class Map

| Layer | File | Responsibility |
|---|---|---|
| **Controller (Client)** | `app/Http/Controllers/Api/General/OrderController.php` | Client-facing checkout flow |
| **Controller (Admin)** | `packages/marvel/src/Http/Controllers/OrderController.php` | Admin order management |
| **Service** | `app/Services/General/OrderService.php` | Order creation, payment, cart management |
| **Service** | `app/Services/General/PromotionService.php` | Promotion/discount calculations |
| **Service** | `app/Services/General/CartInventoryService.php` | Cart reservation and stock checks |
| **Service** | `app/Services/General/MyfatoraService.php` | External payment gateway integration |
| **Service** | `packages/marvel/src/Services/Pricing/ProductPricingService.php` | Pricing calculations |
| **Repository** | `packages/marvel/src/Database/Repositories/OrderRepository.php` | Admin order CRUD with stock/coupon/wallet logic |
| **Repository** | `packages/marvel/src/Database/Repositories/CheckoutRepository.php` | Checkout verification (tax, shipping, stock) |
| **Request** | `packages/marvel/src/Http/Requests/OrderCreateRequest.php` | Checkout input validation |
| **Request** | `packages/marvel/src/Http/Requests/OrderUpdateRequest.php` | Order status update validation |
| **Model** | `packages/marvel/src/Database/Models/Order.php` | Order entity with relationships |
| **Model** | `packages/marvel/src/Database/Models/OrderProduct.php` | Order line items |
| **Model** | `packages/marvel/src/Database/Models/Transaction.php` | Payment transactions |
| **Resource** | `app/Http/Resources/Order/OrderResource.php` | Order JSON transformation |
| **Resource** | `app/Http/Resources/Order/OrderCollection.php` | Paginated order collection |
| **Resource** | `app/Http/Resources/Order/OrderItemResource.php` | Order item JSON transformation |
| **Resource** | `app/Http/Resources/Order/OrderProductVariantResource.php` | Variant JSON for order items |
| **Enum** | `packages/marvel/src/Enums/OrderStatus.php` | Order status constants |
| **Enum** | `packages/marvel/src/Enums/PaymentStatus.php` | Payment status constants |
| **Event** | `packages/marvel/src/Events/OrderCreated.php` | Fired when order is created |
| **Event** | `packages/marvel/src/Events/OrderProcessed.php` | Fired when order processing starts |
| **Event** | `packages/marvel/src/Events/OrderReceived.php` | Fired when order is received by shop |
| **Event** | `packages/marvel/src/Events/OrderStatusChanged.php` | Fired on status change |
| **Event** | `packages/marvel/src/Events/OrderDelivered.php` | Fired on delivery |
| **Event** | `packages/marvel/src/Events/OrderCancelled.php` | Fired on cancellation |
