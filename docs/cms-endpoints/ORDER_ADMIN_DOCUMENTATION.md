# Admin Order Management Feature

## Purpose

The Admin Order Management feature allows super administrators to view all orders in the system with complete details, including customer information, pricing breakdown, order products with variants, and transaction records.

This endpoint is designed for backend administration panels and dashboards.

---

## Endpoint

| Method | URL | Description |
|--------|-----|-------------|
| GET | `/api/v1/admin/orders` | List all orders (paginated) |
| GET | `/api/v1/admin/orders/{id}` | Get a single order with full details |

---

## Authentication

| Guard | Type |
|-------|------|
| `sanctum` | Token-based (Bearer token) |
| `email.verified` | Email must be verified |

## Request

### Query Parameters (Index)

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `limit` | integer | 15 | Items per page (max 100) |
| `page` | integer | 1 | Page number |

### URL Parameters (Show)

| Parameter | Type | Description |
|-----------|------|-------------|
| `id` | integer | Order ID |

---

## Relations Loaded (Eager Loading)

The following relations are eager loaded to prevent N+1 queries:

```
Order
├── user
├── orderItems
│   ├── product
│   └── productVariant
│       └── attributeProducts
│           └── attributeValue
└── transactions
```

---

## Response Format

All responses follow the standard API format:

```json
{
    "status": 200,
    "message": "Data fetched successfully",
    "success": true,
    "data": {}
}
```

Error responses:

```json
{
    "status": 403,
    "message": "Not authorized",
    "success": false
}
```

---

## Response Structure

### Order (index returns a collection, show returns a single order)

| Field | Type | Description |
|-------|------|-------------|
| `id` | integer | Order ID |
| `order_number` | string | Formatted order number (`ORD-00000001`) |
| `tracking_number` | string | External tracking number |
| `status` | string | Order status (pending, processing, completed, etc.) |
| `payment_status` | string | Computed payment status from order status |
| `shipping_method` | string | Shipping method (SCHEDULED, FAST) |
| `expected_delivery_at` | string (ISO8601) | Expected delivery date |
| `customer` | object | User data from `user` relation (`id`, `name`, `email`, `phone`) |
| `customer_name` | string | Snapshot customer name |
| `customer_phone` | string | Snapshot customer phone |
| `customer_email` | string | Snapshot customer email |
| `address` | object | Snapshot shipping address |
| `notes` | string | Order notes |
| `price` | float | Subtotal before discounts/shipping |
| `shipping_price` | float | Shipping cost |
| `total_price` | float | Final total (after discounts + shipping) |
| `coupon` | string | Coupon code used |
| `coupon_discount` | float | Coupon discount amount |
| `promotion` | object | Promotion data (`id`, `code`, `type`, `discount`) |
| `order_items` | array | Array of order product items |
| `transactions` | array | Array of payment transactions |
| `created_at` | string (ISO8601) | Order creation date |
| `updated_at` | string (ISO8601) | Last update date |

### Order Item

| Field | Type | Description |
|-------|------|-------------|
| `id` | integer | Order product ID |
| `product_id` | integer | Related product ID |
| `product_variant_id` | integer | Related variant ID (nullable) |
| `product_name` | string | Snapshot product name |
| `product_sku` | string | Snapshot product SKU |
| `quantity` | integer | Quantity ordered |
| `unit_price` | float | Original unit price |
| `total_price` | float | Total price for quantity |
| `discount_price` | float | Discount amount |
| `flash_sale_price` | float | Flash sale discount amount |
| `promotion_discount_amount` | float | Promotion discount applied |
| `is_gift` | boolean | Whether the item is a gift |
| `promotion_id` | integer | Related promotion ID |
| `attributes` | array | Selected variant attributes (JSON) |
| `product` | object | Related product (`id`, `name`, `slug`, `image`) |
| `variant` | object | Related variant (`id`, `sku`, `price`, `in_stock`, `attributes`) |

### Transaction

| Field | Type | Description |
|-------|------|-------------|
| `id` | integer | Transaction ID |
| `invoice_id` | string | External invoice ID (e.g. from MyFatoorah) |
| `payment_method` | string | Payment method used |
| `created_at` | string (ISO8601) | Transaction date |

---

## Data Flow

```
Admin Request (GET /api/v1/admin/orders)
         │
         ▼
    ┌─────────────┐
    │  Middleware  │
    │ auth:sanctum │
    │ email.verified│
    └──────┬──────┘
           │
           ▼
    ┌──────────────┐
    │ Authorization│
    │ Check for    │
    │ SUPER_ADMIN  │
    │ permission   │
    └──────┬──────┘
           │
      ┌────┴────┐
      │         │
    403 │  200   │
      │         │
      ▼         ▼
   Denied    ┌──────────────────┐
             │  Order::query()  │
             │  ->with(relations)│
             │  ->paginate()    │
             └────────┬─────────┘
                      │
                      ▼
             ┌────────────────────┐
             │  OrderCollection   │
             │  (wraps each order │
             │   in OrderResource) │
             └────────┬──────────┘
                      │
                      ▼
             ┌────────────────────┐
             │  ApiResponse trait │
             │  wraps with:       │
             │  status, message,  │
             │  success, data     │
             └────────┬──────────┘
                      │
                      ▼
             JSON Response sent
```

---

## Dependencies

| Component | File |
|-----------|------|
| Controller | `packages/marvel/src/Http/Controllers/Admin/OrderController.php` |
| Base Controller | `packages/marvel/src/Http/Controllers/CoreController.php` |
| ApiResponse Trait | `packages/marvel/src/Traits/ApiResponse.php` |
| Order Model | `packages/marvel/src/Database/Models/Order.php` |
| OrderProduct Model | `packages/marvel/src/Database/Models/OrderProduct.php` |
| Transaction Model | `packages/marvel/src/Database/Models/Transaction.php` |
| OrderResource | `packages/marvel/src/Http/Resources/Order/OrderResource.php` |
| OrderCollection | `packages/marvel/src/Http/Resources/Order/OrderCollection.php` |
| OrderItemResource | `packages/marvel/src/Http/Resources/Order/OrderItemResource.php` |
| OrderTransactionResource | `packages/marvel/src/Http/Resources/Order/OrderTransactionResource.php` |
| Permission Enum | `packages/marvel/src/Enums/Permission.php` |
| Constants | `packages/marvel/config/constants.php` |
| Route Definition | `packages/marvel/src/Rest/Routes.php` |

---

## Database Tables Impacted

| Table | Usage |
|-------|-------|
| `orders` | Main order data |
| `order_products` | Order line items (snapshot of product data at time of order) |
| `transactions` | Payment transaction records |
| `products` | Related via `order_products.product_id` |
| `product_variants` | Related via `order_products.product_variant_id` |
| `users` | Related via `orders.user_id` |

---

## Performance Considerations

- All relations are **eager loaded** to prevent N+1 queries (6 relations total)
- **Paginated** results with configurable limit (default 15, max 100)
- **Read-only** queries — no writes, no locks
- The `orders` table has a global scope ordering by `created_at DESC`
- No nested loop processing — all data comes from Eloquent eager loading
