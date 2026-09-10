# Admin Orders API

## Overview

Order management for the admin panel: read-only list/detail plus the canonical status-change endpoint. All endpoints are within the Marvel package.

---

## GET /orders

List all orders with filtering and pagination.

**URI:** `/api/v1/orders`

**Controller:** `packages/marvel/src/Http/Controllers/Order/OrderController.php`

**Action:** `index()`

**Route Middleware:** `auth:sanctum`, `email.verified`

**Controller Middleware:** `permission:view-orders`

**Permission Required:** `view-orders` (defined in `Marvel\Enums\Permission::VIEW_ORDERS`)

**Eager Loaded Relations:**
- `user`
- `orderItems.product`
- `orderItems.productVariant.attributeProducts.attributeValue`
- `transactions`
- `pickupLocation`

**Query Parameters:**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `status` | string | — | Filter by order status |
| `user_id` | integer | — | Filter by customer ID |
| `user_email` | string | — | Filter by customer email (LIKE) |
| `promotion_id` | integer | — | Filter by promotion ID |
| `promotion_name` | string | — | Filter by promotion name (LIKE, subquery on `promotions.code`) |
| `product_id` | integer | — | Filter by product ID in order items (`whereHas`) |
| `product_name` | string | — | Filter by product name in order items (LIKE) |
| `flash_sale_name` | string | — | Filter by flash sale title on order items (LIKE) |
| `shipping_method` | string | — | Filter by shipping method (SCHEDULED, FAST) |
| `created_from` | date | — | Orders created on or after this date |
| `created_to` | date | — | Orders created on or before this date |
| `search` | string | — | Search across name, user_email, user_phone (LIKE, OR) |
| `limit` | integer | 15 | Results per page (max 100) |
| `page` | integer | 1 | Page number |

**Success Response (200):**

```json
{
    "status": 200,
    "message": "Data fetched successfully",
    "success": true,
    "data": {
        "data": [
            {
                "id": 1,
                "order_number": "ORD-00000001",
                "status": "pending",
                "payment_status": "pending",
                "shipping_method": "SCHEDULED",
                "expected_delivery_at": "2026-07-20T12:00:00.000000Z",
                "customer": {
                    "id": 5,
                    "name": "John Doe",
                    "email": "john@example.com",
                    "phone": "+201234567890"
                },
                "created_at": "2026-07-18T10:00:00.000000Z",
                "updated_at": "2026-07-18T10:00:00.000000Z",
                "fast_shipping_fee": 0,
                "pickup_location": null
            }
        ],
        "links": {
            "current_page": 1,
            "from": 1,
            "to": 15,
            "last_page": 5,
            "path": "https://example.com/api/v1/orders",
            "per_page": 15,
            "total": 75,
            "next_page_url": "https://example.com/api/v1/orders?page=2",
            "prev_page_url": null,
            "last_page_url": "https://example.com/api/v1/orders?page=5",
            "first_page_url": "https://example.com/api/v1/orders?page=1"
        }
    }
}
```

**Index Response Fields (per order):**

| Field | Type | Source | Description |
|-------|------|--------|-------------|
| `id` | integer | `orders.id` | Primary key |
| `order_number` | string | Computed `getOrderNumberAttribute()` | `ORD-` padded to 8 digits |
| `status` | string | `orders.status` | Order status |
| `payment_status` | string | Computed `getPaymentStatusAttribute()` | Derived from transactions / status |
| `shipping_method` | string | `orders.shipping_method` | SCHEDULED or FAST |
| `expected_delivery_at` | string (ISO8601) | `orders.expected_delivery_at` | Nullable |
| `customer` | object | `orders.user` relation | `{ id, name, email, phone }` |
| `fast_shipping_fee` | float | `orders.fast_shipping_fee` | 0 if not fast shipping |
| `pickup_location` | object\|null | Computed `resolvePickupLocation()` | Only if `fulfillment_type === 'pickup'` |
| `created_at` | string (ISO8601) | `orders.created_at` | Timestamp |
| `updated_at` | string (ISO8601) | `orders.updated_at` | Timestamp |

Extra fields (`customer_name`, `customer_phone`, `customer_email`, `address`, `notes`, `price`, `shipping_price`, `total_price`, `coupon`, `coupon_discount`, `promotion`, `order_items`, `transactions`) are **not** included in the index response due to `mergeWhen(routeIs('orders.show'))` in `OrderResource`.

---

## Error Response (any 4xx/5xx)

```json
{
    "status": 404,
    "message": "ModelNotFoundException",
    "success": false,
    "data": []
}
```

Error responses use the `ApiResponse` trait: `{ status: int, message: string, success: false, data: [] }`.

---

## GET /orders/{id}

Get a single order with full details.

**URI:** `/api/v1/orders/{id}`

**Controller:** `packages/marvel/src/Http/Controllers/Order/OrderController.php`

**Action:** `show()`

**Route Middleware:** `auth:sanctum`, `email.verified`

**Controller Middleware:** `permission:view-order`

**Permission Required:** `view-order` (defined in `Marvel\Enums\Permission::VIEW_ORDER`)

**Eager Loaded Relations:** Same as index (user, orderItems.product, orderItems.productVariant.attributeProducts.attributeValue, transactions, pickupLocation)

**Show Response Fields (in addition to index fields):**

| Field | Type | Source | Description |
|-------|------|--------|-------------|
| `customer_name` | string | `orders.name` | Snapshot customer name at time of order |
| `customer_phone` | string | `orders.user_phone` | Snapshot customer phone at time of order |
| `customer_email` | string | `orders.user_email` | Snapshot customer email at time of order |
| `address` | string (JSON) | `orders.address` | Snapshot shipping address |
| `notes` | string | `orders.notes` | Order notes |
| `price` | float | `orders.price` | Subtotal before discounts/shipping |
| `shipping_price` | float | `orders.shipping_price` | Shipping cost |
| `total_price` | float | `orders.total_price` | Final total (after discounts + shipping) |
| `coupon` | string | `orders.coupon` | Coupon code used |
| `coupon_discount` | float | `orders.coupon_discount` | Coupon discount amount |
| `promotion` | object\|null | Computed from `promotion_id`, `promotion_code`, `promotion_type`, `promotion_discount` | `{ id, code, type, discount }` or null |
| `order_items` | array | `OrderItemResource` collection | Array of order product items |
| `transactions` | array | `OrderTransactionResource` collection | Array of payment transactions |

**OrderItemResource fields:**

| Field | Type | Description |
|-------|------|-------------|
| `id` | integer | Order product ID |
| `product_id` | integer | Related product ID |
| `product_variant_id` | integer\|null | Related variant ID |
| `product_name` | string | Snapshot product name |
| `product_sku` | string | Snapshot product SKU |
| `quantity` | integer | Quantity ordered |
| `unit_price` | float | Original unit price per item |
| `total_price` | float | Total price for quantity |
| `discount_price` | float\|null | Discount amount per item |
| `flash_sale_price` | float\|null | Flash sale discount amount per item |
| `promotion_discount_amount` | float | Promotion discount applied |
| `is_gift` | boolean | Whether the item is a gift |
| `promotion_id` | integer\|null | Related promotion ID |
| `attributes` | string\|null | Selected variant attributes (JSON) |
| `product` | object\|null | `{ id, name, slug, image }` (when loaded) |
| `variant` | object\|null | `{ id, sku, price, in_stock, attributes }` (when applicable and loaded) |

**OrderTransactionResource fields:**

| Field | Type | Description |
|-------|------|-------------|
| `id` | integer | Transaction ID |
| `uuid` | string | Unique UUID |
| `invoice_id` | string\|null | External invoice ID |
| `payment_method` | string\|null | Payment method used |
| `status` | string | Transaction status (pending, paid, failed) |
| `amount` | float\|null | Transaction amount |
| `created_at` | string (ISO8601) | Transaction date |

---

## PATCH /orders/{id}/status

Canonical status transition. Route: `packages/marvel/src/Rest/Routes.php:167` (name `orders.update-status`, `whereNumber('id')`).

**Middleware:** group `auth:sanctum` + `throttle:admin` (Routes.php:115); controller constructor adds `permission:update-order-status`.

### Request

```json
{ "status": "processing" }
```

Validation (`Marvel\Http\Requests\OrderStatusUpdateRequest`): `status` required|string, one of `pending|processing|completed|delivered|cancelled`.

### Transition Matrix (`App\Services\General\OrderService::$allowedOrderTransitions`)

```text
pending    -> pending, processing, completed, cancelled
processing -> processing, completed, cancelled
completed  -> completed, delivered
delivered  -> delivered   (terminal)
cancelled  -> cancelled   (terminal)
```

Forbidden transition or unknown order -> handled by the controller as **422** (translated `checkout.invalid_order_status_transition`) / **404**.

### Side Effects Summary (one DB transaction via `OrderService::changeOrderStatus`)

- **Invoice x1** on the first valid transition away from `pending` (any target; same-status excluded) - idempotent via `InvoiceService`.
- `completed`: `payment_status=payment-success`, `paid_at`+`completed_at`, transaction paid, coupon usage consumed, promotion finalized, `PaymentSucceeded` fired exactly once (invoice listener no-ops), fulfillment advanced.
- `cancelled` (first time): `cancelled_at`, transaction failed, promotion usage decremented, inventory restored once for paid orders.
- `processing`/`delivered`: fulfillment status synced per matrix.
- Events always: `OrderStatusChanged`; conditional: `OrderCancelled`, `OrderDelivered`, `PaymentSucceeded`.

Full details: `api-desc/order/api.md` section 5.

---

## Business Logic

### Order Number
Computed attribute `getOrderNumberAttribute()`: `ORD-` + zero-padded ID (8 digits).

### Payment Status
Computed attribute `getPaymentStatusAttribute()`:
- For COD / pay_at_cashier: derived from the latest transaction status
- For other methods: derived from order status (`completed`/`delivered` → SUCCESS, `cancelled` → FAILED, default → PENDING)

### Resource Switching
`OrderResource` uses `request()->routeIs('orders.show')` (line 25 of `OrderResource.php`) to conditionally include detailed fields. Index response includes only basic fields; show response includes everything.

### Pagination
`getLimit()` caps at 100 and defaults to 15. Invalid values (≤0) reset to default 15.

### Pickup Location Resolution
`resolvePickupLocation()` tries two strategies:
1. If `pickupLocation` relation is loaded and exists: return related model fields
2. Else if `pickup_location_name` is set: return snapshot fields from order columns
3. Otherwise: return null

---

## Dependencies

| Component | Source File | Line |
|-----------|-------------|------|
| Controller | `packages/marvel/src/Http/Controllers/Order/OrderController.php` | 14 |
| Route definition | `packages/marvel/src/Rest/Routes.php` | 165-167 |
| Status FormRequest | `packages/marvel/src/Http/Requests/OrderStatusUpdateRequest.php` | 9 |
| OrderService (transitions/invoice/events) | `app/Services/General/OrderService.php` | 526+ |
| InvoiceService (idempotent generation) | `app/Services/Invoice/InvoiceService.php` | 22 |
| Permission enum | `packages/marvel/src/Enums/Permission.php` | VIEW_ORDERS / VIEW_ORDER / UPDATE_ORDER_STATUS |
| Order model | `packages/marvel/src/Database/Models/Order.php` | 14 |
| OrderProduct model | `packages/marvel/src/Database/Models/OrderProduct.php` | - |
| Transaction model | `packages/marvel/src/Database/Models/Transaction.php` | - |
| PickupLocation model | `packages/marvel/src/Database/Models/PickupLocation.php` | - |
| Order resource | `packages/marvel/src/Http/Resources/Order/OrderResource.php` | 8 |
| Order collection | `packages/marvel/src/Http/Resources/Order/OrderCollection.php` | 8 |
| Order item resource | `packages/marvel/src/Http/Resources/Order/OrderItemResource.php` | 8 |
| Order transaction resource | `packages/marvel/src/Http/Resources/Order/OrderTransactionResource.php` | 8 |
| ApiResponse trait | `packages/marvel/src/Traits/ApiResponse.php` | 7 |
| Auth middleware | `auth:sanctum` (Laravel Sanctum) | - |
| Throttle | `throttle:admin` (Route group, Routes.php:115) | - |
| Lifecycle tests | `tests/Feature/OrderStatusLifecycleTest.php` (15), `OrdersProductionHardenTest.php` (38) | - |
